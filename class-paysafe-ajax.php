<?php
/**
 * Paysafe AJAX Handler Class
 * File: includes/class-paysafe-ajax.php
 * Handles AJAX requests for payment processing
  * Last updated: 2025-11-25
 */

if (!defined('ABSPATH')) {
	exit;
}

class Paysafe_Ajax {

	/**
	 * Rate limiting tracking
	 */
	private $rate_limit_prefix = 'paysafe_ajax_rate_';
	private $max_attempts = 5;
	private $time_window = 60; // seconds

	public function __construct() {
		// AJAX actions for logged in users
		add_action('wp_ajax_paysafe_process_payment', array($this, 'process_payment'));
		add_action('wp_ajax_paysafe_get_iframe_token', array($this, 'get_iframe_token'));
		add_action('wp_ajax_paysafe_create_single_use_token', array($this, 'create_single_use_token'));
		add_action('wp_ajax_paysafe_validate_credentials', array($this, 'validate_credentials'));

		// AJAX actions for non-logged in users - WITH RATE LIMITING
		add_action('wp_ajax_nopriv_paysafe_process_payment', array($this, 'process_payment'));
		add_action('wp_ajax_nopriv_paysafe_get_iframe_token', array($this, 'get_iframe_token'));
		add_action('wp_ajax_nopriv_paysafe_create_single_use_token', array($this, 'create_single_use_token'));

		// Apple Pay validation
		add_action('wp_ajax_paysafe_validate_apple_pay_merchant', array($this, 'validate_apple_pay_merchant'));
		add_action('wp_ajax_nopriv_paysafe_validate_apple_pay_merchant', array($this, 'validate_apple_pay_merchant'));
	}

	/**
	 * Try to get the gateway instance for custom error messages
	 * @return WC_Gateway_Paysafe|null
	 */
	private function get_gateway_instance() {
		if (class_exists('WC_Payment_Gateways')) {
			$gateways = WC_Payment_Gateways::instance();
			return $gateways->payment_gateways()['paysafe'] ?? null;
		}
		return null;
	}

	/**
	 * Set security headers for AJAX responses
	 */
	private function set_security_headers() {
		// Prevent content type sniffing
		header('X-Content-Type-Options: nosniff');

		// Prevent clickjacking
		header('X-Frame-Options: SAMEORIGIN');

		// Enable XSS protection
		header('X-XSS-Protection: 1; mode=block');

		// Content Security Policy for AJAX responses
		header("Content-Security-Policy: default-src 'self'");

		// Referrer Policy
		header('Referrer-Policy: strict-origin-when-cross-origin');

		// Prevent caching of sensitive data
		header('Cache-Control: no-store, no-cache, must-revalidate, private');
		header('Pragma: no-cache');
		header('Expires: 0');
	}

	/**
	 * Check rate limiting for non-logged-in users
	 * 
	 * @return bool True if within rate limit, false if exceeded
	 */
	private function check_rate_limit() {
		// Skip rate limiting for logged-in users with purchase capability
		if (is_user_logged_in() && current_user_can('purchase')) {
			return true;
		}

		// Get client IP
		$client_ip = $this->get_client_ip();
		$rate_key = $this->rate_limit_prefix . md5($client_ip);

		// Get current attempts
		$attempts = get_transient($rate_key);

		if ($attempts === false) {
			// First attempt
			set_transient($rate_key, 1, $this->time_window);
			return true;
		}

		if ($attempts >= $this->max_attempts) {
			// Rate limit exceeded
			return false;
		}

		// Increment attempts
		set_transient($rate_key, $attempts + 1, $this->time_window);
		return true;
	}

	/**
	 * Get client IP address with enhanced security
	 * 
	 * @return string
	 */
	private function get_client_ip() {
		// Only trust proxy headers if we're behind a known proxy
		$trust_proxy = defined('PAYSAFE_TRUST_PROXY') ? PAYSAFE_TRUST_PROXY : false;

		// If behind Cloudflare, prioritize their header
		if ($trust_proxy && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			$ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
		} 
		// If behind a trusted proxy, check X-Forwarded-For
		elseif ($trust_proxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// Get the first IP from the comma-separated list
			$ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			$ip = trim($ips[0]);
		} 
		// Otherwise, use REMOTE_ADDR which is most reliable
		else {
			$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		}

		// Validate the IP address
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
			// If invalid, fall back to REMOTE_ADDR
			$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		}

		// Additional validation - ensure it's a valid IP
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			$ip = '0.0.0.0';
		}

		return $ip;
	}

	/**
	 * Rotate nonce after critical operations
	 * This prevents replay attacks
	 * 
	 * @param string $action The action that was performed
	 * @return string New nonce
	 */
	private function rotate_nonce($action) {
		// Generate a unique key for this user/session
		$user_id = get_current_user_id();
		$session_key = 'paysafe_nonce_' . $action . '_' . $user_id;

		// Invalidate the old nonce by storing it in a blacklist (transient)
		$old_nonce = isset($_POST['paysafe_nonce']) ? $_POST['paysafe_nonce'] : '';
		if ($old_nonce) {
			set_transient('paysafe_used_nonce_' . md5($old_nonce), true, HOUR_IN_SECONDS);
		}

		// Generate and return new nonce
		return wp_create_nonce($action);
	}

	/**
	 * Check if a nonce has been used (blacklisted)
	 * 
	 * @param string $nonce The nonce to check
	 * @return bool True if nonce is blacklisted
	 */
	private function is_nonce_blacklisted($nonce) {
		return get_transient('paysafe_used_nonce_' . md5($nonce)) !== false;
	}

	/**
	 * Verify nonce and check permissions
	 * 
	 * @param string $nonce_action The nonce action to verify
	 * @param string $capability Required capability (optional)
	 * @return bool|WP_Error True if valid, WP_Error if not
	 */
	private function verify_request_security($nonce_action = 'paysafe_payment_nonce', $capability = null) {
		// Check rate limiting first for non-logged-in users
		if (!is_user_logged_in() && !$this->check_rate_limit()) {
			return new WP_Error('rate_limit_exceeded', 
				__('Too many requests. Please wait a moment and try again.', 'paysafe-payment'));
		}

		// Verify nonce - check multiple possible field names
		$nonce = isset($_POST['paysafe_nonce']) ? $_POST['paysafe_nonce'] : 
				 (isset($_POST['nonce']) ? $_POST['nonce'] : 
				 (isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : ''));

		if (empty($nonce)) {
			return new WP_Error('missing_nonce', 
				__('Security token is missing.', 'paysafe-payment'));
		}

		// Check if nonce is blacklisted (already used)
		if ($this->is_nonce_blacklisted($nonce)) {
			return new WP_Error('nonce_already_used', 
				__('This form has already been submitted. Please refresh the page.', 'paysafe-payment'));
		}

		if (!wp_verify_nonce($nonce, $nonce_action)) {
			return new WP_Error('invalid_nonce', 
				__('Security check failed. Please refresh the page and try again.', 'paysafe-payment'));
		}

		// Check capability if specified
		if ($capability && !current_user_can($capability)) {
			return new WP_Error('insufficient_permissions', 
				__('You do not have permission to perform this action.', 'paysafe-payment'));
		}

		// Additional security: Check referer for sensitive operations
		if ($capability === 'manage_options') {
			$referer = wp_get_referer();
			if (!$referer || strpos($referer, admin_url()) !== 0) {
				return new WP_Error('invalid_referer', 
					__('Invalid request source.', 'paysafe-payment'));
			}
		}

		return true;
	}

	/**
	 * Create single-use token via server-side API
	 */
	public function create_single_use_token() {
		// Verify nonce
		// Verify nonce via central security helper (accepts paysafe_nonce/nonce/_wpnonce)
		$security_check = $this->verify_request_security('paysafe_payment_nonce');
		if (is_wp_error($security_check)) {
			wp_send_json_error(array('message' => $security_check->get_error_message()));
			return;
		}

		// Get card data
		$card_data = array(
			'number' => sanitize_text_field($_POST['card_number'] ?? ''),
			'exp_month' => sanitize_text_field($_POST['exp_month'] ?? ''),
			'exp_year' => sanitize_text_field($_POST['exp_year'] ?? ''),
			'cvv' => sanitize_text_field($_POST['cvv'] ?? ''),
			'name' => sanitize_text_field($_POST['holder_name'] ?? '')
		);

		// Validate required fields
		if (empty($card_data['number']) || empty($card_data['exp_month']) || 
			empty($card_data['exp_year']) || empty($card_data['cvv'])) {
			wp_send_json_error(array('message' => 'Missing required card information'));
			return;
		}

		// Get settings
		$settings = get_option('woocommerce_paysafe_settings', array());

		// Create API instance
		$api_settings = array(
			'api_username' => $settings['api_key_user'] ?? '',
			'api_password' => $settings['api_key_password'] ?? '',
			// match Paysafe_API constructor keys
			'single_use_token_username' => $settings['single_use_token_user'] ?? '',
			'single_use_token_password' => $settings['single_use_token_password'] ?? '',
			'merchant_id' => $settings['merchant_id'] ?? '',
			'account_id_cad' => $settings['cards_account_id_cad'] ?? '',
			'account_id_usd' => $settings['cards_account_id_usd'] ?? '',
			'environment' => $settings['environment'] ?? 'sandbox',
			'debug' => $settings['enable_debug'] ?? 'no'
		);

		try {
			$api = new Paysafe_API($api_settings, $this->get_gateway_instance());
			$token = $api->create_single_use_token($card_data);
			// Return the shape expected by the JS (payment_token), plus safe last4 and brand.
			$digits = preg_replace('/\D+/', '', (string) $card_data['number']);
			$last4  = $digits !== '' ? substr($digits, -4) : '';
			// Lightweight brand detection from PAN (no logging of PAN)
			$brand = 'unknown';
			if (preg_match('/^4\d{12,18}$/', $digits)) {
				$brand = 'visa';
			} elseif (preg_match('/^(5[1-5]\d{14}|2(2[2-9]\d{2}|[3-6]\d{3}|7[01]\d{2}|720)\d{10})$/', $digits)) {
				$brand = 'mastercard';
			} elseif (preg_match('/^3[47]\d{13}$/', $digits)) {
				$brand = 'amex';
			} elseif (preg_match('/^6(?:011|5\d{2})\d{12}$/', $digits)) {
				$brand = 'discover';
			} elseif (preg_match('/^(?:2131|1800)\d{11}|35\d{14}$/', $digits)) {
				$brand = 'jcb';
			} elseif (preg_match('/^3(?:0[0-5]|[68]\d)\d{11}$/', $digits)) {
				$brand = 'diners';
			}
			wp_send_json_success(array('payment_token' => $token, 'last4' => $last4, 'brand' => $brand));
		} catch (Exception $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}

		/**
	 * Process a standalone (non-WC checkout) payment via AJAX
	 * - Accepts either a client single-use token (preferred) or raw card data (server tokenizes)
	 * - Uses API auths endpoint with singleUseToken first, then falls back to paymentToken if needed
	 * - Optionally saves the card for logged-in users
	 */
	public function process_payment() {
		// Enhanced security verification + basic rate limit
		$security_check = $this->verify_request_security('paysafe_payment_nonce');
		if (is_wp_error($security_check)) {
			wp_send_json_error(array(
				'message' => $security_check->get_error_message(),
				'code'    => $security_check->get_error_code(),
			));
			return;
		}

		$this->set_security_headers();

		// Required fields
		$amount   = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
		$currency = sanitize_text_field($_POST['currency'] ?? get_woocommerce_currency());

		if ($amount <= 0) {
			wp_send_json_error(array('message' => __('Invalid amount.', 'paysafe-payment')));
			return;
		}

		// Gather payer/billing (best-effort; mock order consumes these)
		$payment_data = array(
			'amount'      => $amount,
			'currency'    => $currency,
			'first_name'  => sanitize_text_field($_POST['first_name']  ?? ''),
			'last_name'   => sanitize_text_field($_POST['last_name']   ?? ''),
			'email'       => sanitize_email($_POST['email']            ?? ''),
			'phone'       => sanitize_text_field($_POST['phone']       ?? ''),
			'address'     => sanitize_text_field($_POST['address']     ?? ''),
			'city'        => sanitize_text_field($_POST['city']        ?? ''),
			'province'    => sanitize_text_field($_POST['province']    ?? ''),
			'country'     => sanitize_text_field($_POST['country']     ?? ''),
			'postal_code' => sanitize_text_field($_POST['postal_code'] ?? ''),
		);

		// May arrive from hosted fields or server tokenization
		$single_use_or_payment_token = sanitize_text_field($_POST['paysafe_payment_token'] ?? '');

		// If no token provided, try to create one server-side from raw fields
		if (empty($single_use_or_payment_token)) {
			$card_number = preg_replace('/\s+/', '', sanitize_text_field($_POST['card_number'] ?? ''));
			$exp_month   = sanitize_text_field($_POST['card_expiry_month'] ?? ($_POST['exp_month'] ?? ''));
			$exp_year    = sanitize_text_field($_POST['card_expiry_year']  ?? ($_POST['exp_year']  ?? ''));
			$cvv         = sanitize_text_field($_POST['cvv'] ?? '');
			$holder_name = sanitize_text_field($_POST['cardholder_name'] ?? ($_POST['holder_name'] ?? ''));

			if (!$card_number || !$exp_month || !$exp_year || !$cvv) {
				wp_send_json_error(array('message' => __('Missing required card information.', 'paysafe-payment')));
				return;
			}

			// Build API settings
			$settings = get_option('woocommerce_paysafe_settings', array());
			$api_settings = array(
				'api_username'           => $settings['api_key_user']            ?? '',
				'api_password'           => $settings['api_key_password']        ?? '',
				'single_token_username'  => $settings['single_use_token_user']   ?? '',
				'single_token_password'  => $settings['single_use_token_password'] ?? '',
				'merchant_id'            => $settings['merchant_id']             ?? '',
				'account_id_cad'         => $settings['cards_account_id_cad']    ?? '',
				'account_id_usd'         => $settings['cards_account_id_usd']    ?? '',
				'environment'            => $settings['environment']             ?? 'sandbox',
				'debug'                  => $settings['enable_debug']            ?? 'no',
			);

			try {
				$api   = new Paysafe_API($api_settings);
				$token = $api->create_single_use_token(array(
					'number'    => $card_number,
					'exp_month' => $exp_month,
					'exp_year'  => $exp_year,
					'cvv'       => $cvv,
					'name'      => $holder_name,
				));
				$single_use_or_payment_token = $token;
			} catch (Exception $e) {
				wp_send_json_error(array('message' => $e->getMessage()));
				return;
			}
		}

		// Prepare API + mock order
		$settings = get_option('woocommerce_paysafe_settings', array());
		$api_settings = array(
			'api_username'           => $settings['api_key_user']            ?? '',
			'api_password'           => $settings['api_key_password']        ?? '',
			'single_token_username'  => $settings['single_use_token_user']   ?? '',
			'single_token_password'  => $settings['single_use_token_password'] ?? '',
			'merchant_id'            => $settings['merchant_id']             ?? '',
			'account_id_cad'         => $settings['cards_account_id_cad']    ?? '',
			'account_id_usd'         => $settings['cards_account_id_usd']    ?? '',
			'environment'            => $settings['environment']             ?? 'sandbox',
			'debug'                  => $settings['enable_debug']            ?? 'no',
		);

		$api   = new Paysafe_API($api_settings);
		$order = $this->create_mock_order($payment_data);

		// Pick account id by currency (mirror Paysafe_API::get_account_id logic)
		$account_id = ($currency === 'USD' && !empty($api_settings['account_id_usd']))
			? $api_settings['account_id_usd']
			: ($api_settings['account_id_cad'] ?? '');

		if (empty($account_id)) {
			wp_send_json_error(array('message' => sprintf(__('No account ID configured for %s.', 'paysafe-payment'), $currency)));
			return;
		}

		// Build auth request with singleUseToken first
		$request = array(
			'merchantRefNum' => 'order_' . $order->get_id() . '_' . time(),
			'amount'         => intval($order->get_total() * 100),
			'settleWithAuth' => true,
			'billingDetails' => array(
				'street'  => $order->get_billing_address_1(),
				'city'    => $order->get_billing_city(),
				'state'   => $order->get_billing_state(),
				'country' => $order->get_billing_country(),
				'zip'     => str_replace(' ', '', $order->get_billing_postcode()),
			),
			'card' => array(
				'singleUseToken' => $single_use_or_payment_token,
			),
		);

		$endpoint = '/cardpayments/v1/accounts/' . $account_id . '/auths';

		try {
			// Try with singleUseToken
			$response = $api->make_request($endpoint, 'POST', $request);
		} catch (Exception $e_single) {
			// Fallback: try treating the provided token as a permanent paymentToken
			$request['card'] = array('paymentToken' => $single_use_or_payment_token);
			try {
				$response = $api->make_request($endpoint, 'POST', $request);
			} catch (Exception $e_perm) {
				wp_send_json_error(array('message' => $e_perm->getMessage()));
				return;
			}
		}

		if (!isset($response['status']) || $response['status'] !== 'COMPLETED') {
			// Normalize Paysafe error into gateway custom error buckets (AVS/CVV/etc.).
			$error_code  = isset($response['error']['code']) ? (string) $response['error']['code'] : '';
			$raw_message = isset($response['error']['message']) ? $response['error']['message'] : __('Payment failed', 'paysafe-payment');
			$msg         = $raw_message;

			// Try to map to the gateway's custom error message settings when possible.
			if (class_exists('WC_Payment_Gateways')) {
				$gateways = WC_Payment_Gateways::instance();
				$gateway  = isset($gateways->payment_gateways()['paysafe']) ? $gateways->payment_gateways()['paysafe'] : null;

				if ($gateway && method_exists($gateway, 'get_custom_error_message')) {
					if ($error_code !== '') {
						$custom_msg = $gateway->get_custom_error_message($error_code, '');
						if (!empty($custom_msg)) {
							$msg = $custom_msg;
						}
					}

					// If we still have the raw Paysafe AVS text, normalize it to AVS_FAILED.
					if ($msg === $raw_message && stripos($raw_message, 'failed the avs check') !== false) {
						$avs_msg = $gateway->get_custom_error_message('AVS_FAILED', '');
						if (!empty($avs_msg)) {
							$msg = $avs_msg;
						} else {
							// Fallback: use generic message instead of raw Paysafe text
							$msg = 'Address Verification Failed';
						}
					}
				}
			}

			// Allow safe inline markup in custom error messages.
			$msg = wp_kses($msg, array(
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'br'     => array(),
			));

			wp_send_json_error(array('message' => $msg));
			return;
		}

		// Optionally save card when checkbox present and user logged in
		$save_card = !empty($_POST['save_card']) && (int) $_POST['save_card'] === 1;
		if ($save_card && is_user_logged_in()) {
			// Use the original token the client/server produced (single-use)
			$payment_data['payment_token'] = $single_use_or_payment_token;
			$this->save_card_for_user($payment_data, $response, $api_settings);
		}

		wp_send_json_success(array(
			'message'        => __('Payment processed successfully!', 'paysafe-payment'),
			'transaction_id' => $response['id'] ?? '',
			'auth_code'      => $response['authCode'] ?? '',
			// Optional redirect support for frontend logic
			'redirect'       => '',
		));
	}

	/**
	 * Get iFrame access token
	 */
	public function get_iframe_token() {
		// Enhanced security verification
		$security_check = $this->verify_request_security('paysafe_payment_nonce');
		if (is_wp_error($security_check)) {
			wp_send_json_error(array(
				'message' => $security_check->get_error_message(),
				'code' => $security_check->get_error_code()
			));
			return;
		}

		// Set security headers
		$this->set_security_headers();

		// Get settings
		$settings = get_option('woocommerce_paysafe_settings', array());

		// For Paysafe Checkout, we need to return the setup configuration
		$iframe_config = array(
			'environment' => ($settings['environment'] ?? 'sandbox') === 'live' ? 'LIVE' : 'TEST',
			'apiKey' => $settings['single_use_token_user'] ?? '',
			'apiSecret' => $settings['single_use_token_password'] ?? '',
			'accountId' => $settings['cards_account_id_cad'] ?? '',
			'currency' => get_woocommerce_currency()
		);

		wp_send_json_success($iframe_config);
	}

	/**
	 * Validate credentials via AJAX (admin only)
	 */
	public function validate_credentials() {
		// Enhanced security verification - admin only
		$security_check = $this->verify_request_security('paysafe_admin_nonce', 'manage_options');
		if (is_wp_error($security_check)) {
			wp_send_json_error(array(
				'message' => $security_check->get_error_message(),
				'code' => $security_check->get_error_code()
			));
			return;
		}

		// Set security headers
		$this->set_security_headers();

		// Get credentials from POST
		$api_username = sanitize_text_field($_POST['api_username'] ?? '');
		$api_password = sanitize_text_field($_POST['api_password'] ?? '');
		$environment = sanitize_text_field($_POST['environment'] ?? 'sandbox');

		if (empty($api_username) || empty($api_password)) {
			wp_send_json_error(array(
				'message' => __('Missing credentials', 'paysafe-payment'),
				'code' => 'missing_credentials'
			));
			return;
		}

		// Create settings array for API
		$api_settings = array(
			'api_username' => $api_username,
			'api_password' => $api_password,
			'environment' => $environment,
			'debug' => 'no'
		);

		// Test connection
			$api = new Paysafe_API($api_settings, $this->get_gateway_instance());
		$result = $api->test_connection();

		if ($result['success']) {
			wp_send_json_success(array('message' => $result['message']));
		} else {
			wp_send_json_error(array('message' => $result['message']));
		}
	}

	/**
	 * Validate Apple Pay merchant
	 */
	public function validate_apple_pay_merchant() {
		// Enhanced security verification
		$security_check = $this->verify_request_security('paysafe_payment_nonce');
		if (is_wp_error($security_check)) {
			wp_send_json_error(array(
				'message' => $security_check->get_error_message(),
				'code' => $security_check->get_error_code()
			));
			return;
		}

		// Set security headers
		$this->set_security_headers();

		// Get Apple Pay session data
		$validation_url = sanitize_text_field($_POST['validationURL'] ?? '');
		$domain_name = sanitize_text_field($_POST['domainName'] ?? '');

		if (empty($validation_url)) {
			wp_send_json_error(array(
				'message' => __('Missing validation URL', 'paysafe-payment'),
				'code' => 'missing_validation_url'
			));
			return;
		}

		// Validate domain name matches current site
		$site_domain = parse_url(home_url(), PHP_URL_HOST);
		if ($domain_name !== $site_domain) {
			wp_send_json_error(array(
				'message' => __('Domain mismatch', 'paysafe-payment'),
				'code' => 'domain_mismatch'
			));
			return;
		}

		// Get settings
		$settings = get_option('woocommerce_paysafe_settings', array());
		$merchant_id = $settings['apple_pay_merchant_id'] ?? '';

		if (empty($merchant_id)) {
			wp_send_json_error(array(
				'message' => __('Apple Pay merchant ID not configured', 'paysafe-payment'),
				'code' => 'merchant_id_not_configured'
			));
			return;
		}

		// This would normally validate with Apple Pay
		// For now, return a mock session
		$session = array(
			'epochTimestamp' => time() * 1000,
			'expiresAt' => (time() + 300) * 1000,
			'merchantSessionIdentifier' => uniqid('merchant_session_'),
			'nonce' => wp_create_nonce('apple_pay_session'),
			'merchantIdentifier' => $merchant_id,
			'domainName' => $domain_name,
			'displayName' => get_bloginfo('name'),
			'signature' => base64_encode(hash('sha256', $validation_url . $merchant_id, true))
		);

		wp_send_json_success($session);
	}

		/**
	 * Save card for future use
	 * - Ensures a Customer Vault profile exists
	 * - Converts the single-use token to a permanent card/paymentToken
	 * - Creates a WC token (WC_Payment_Token_Paysafe) so it shows under My Account
	 */
	private function save_card_for_user($payment_data, $transaction_result, $api_settings) {
		$user_id = get_current_user_id();
		if (!$user_id) {
			return;
		}

			$api = new Paysafe_API($api_settings, $this->get_gateway_instance());

		// Use the unified meta key used elsewhere in the plugin
		$profile_meta_key = '_paysafe_customer_profile_id';
		$customer_profile_id = get_user_meta($user_id, $profile_meta_key, true);

		// Create Customer Vault profile if needed
		if (empty($customer_profile_id)) {
			try {
				// Accept both shapes; Paysafe_API::create_customer_profile() normalizes
				$profile = $api->create_customer_profile(array(
					'merchantCustomerId' => 'wp_user_' . $user_id,
					'firstName'          => $payment_data['first_name'] ?? '',
					'lastName'           => $payment_data['last_name']  ?? '',
					'email'              => $payment_data['email']      ?? '',
					'phone'              => $payment_data['phone']      ?? '',
				));

				if (!empty($profile['success']) && !empty($profile['profile_id'])) {
					$customer_profile_id = $profile['profile_id'];
					update_user_meta($user_id, $profile_meta_key, $customer_profile_id);
				} else {
					// If profile creation failed silently, stop saving the card (payment already succeeded)
					return;
				}
			} catch (Exception $e) {
				error_log('Paysafe: Failed to create customer profile - ' . $e->getMessage());
				return;
			}
		}

		// We expect a client/server single-use token here
		$single_use_token = $payment_data['payment_token'] ?? '';
		if (empty($customer_profile_id) || empty($single_use_token)) {
			return;
		}

		// Convert to a permanent card (paymentToken) in the vault
		try {
			$result = $api->create_permanent_card_from_token($customer_profile_id, $single_use_token);
			if (empty($result['success'])) {
				return;
			}

			$card_id       = $result['card_id']       ?? '';
			$payment_token = $result['payment_token'] ?? '';
			$card_type     = strtolower($result['card_type'] ?? '');
			$last4         = $result['last_digits']   ?? '';
			$expiry        = $result['card_expiry']   ?? array();

			$exp_month = isset($expiry['month']) ? intval($expiry['month']) : 0;
			$exp_year  = isset($expiry['year'])  ? intval($expiry['year'])  : 0;

			// Create a WooCommerce token so the card appears in My Account
			if ($payment_token && class_exists('WC_Payment_Token_Paysafe')) {
				$token = new WC_Payment_Token_Paysafe();
				$token->set_user_id($user_id);
				$token->set_gateway_id('paysafe'); // your gateway id
				$token->set_token($payment_token); // WC's native token field
				if ($card_type) $token->set_card_type($card_type);
				if ($last4)     $token->set_last4($last4);
				if ($exp_month) $token->set_expiry_month($exp_month);
				if ($exp_year)  $token->set_expiry_year($exp_year);
				// Paysafe-specific data
				$token->set_paysafe_profile_id($customer_profile_id);
				if ($card_id) $token->set_paysafe_card_id($card_id);
				$token->set_paysafe_payment_token($payment_token);

				// Persist
				$token->save();
			}
		} catch (Exception $e) {
			// Do not fail the payment if saving fails
			error_log('Paysafe: Failed to save card to profile - ' . $e->getMessage());
		}
	}

	/**
	 * Create a mock order object for standalone payments
	 */
	private function create_mock_order($payment_data) {
		// Create a simple object that mimics WC_Order methods needed by the API
		return new class($payment_data) {
			private $data;

			public function __construct($data) {
				$this->data = $data;
			}

			public function get_id() {
				return 'standalone_' . time();
			}

			public function get_total() {
				return floatval($this->data['amount']);
			}

			public function get_currency() {
				return $this->data['currency'] ?? 'CAD';
			}

			public function get_billing_first_name() {
				return $this->data['first_name'] ?? '';
			}

			public function get_billing_last_name() {
				return $this->data['last_name'] ?? '';
			}

			public function get_billing_email() {
				return $this->data['email'] ?? '';
			}

			public function get_billing_phone() {
				return $this->data['phone'] ?? '';
			}

			public function get_billing_address_1() {
				return $this->data['address'] ?? '';
			}

			public function get_billing_address_2() {
				return '';
			}

			public function get_billing_city() {
				return $this->data['city'] ?? '';
			}

			public function get_billing_state() {
				return $this->data['province'] ?? '';
			}

			public function get_billing_country() {
				return $this->data['country'] ?? '';
			}

			public function get_billing_postcode() {
				return $this->data['postal_code'] ?? '';
			}

			public function get_customer_id() {
				return get_current_user_id();
			}
		};
	}
}

// Initialize the AJAX handler
new Paysafe_Ajax();
<?php
/**
 * WooCommerce Paysafe Payment Gateway
 * File: /includes/class-wc-gateway-paysafe.php
 * @class WC_Gateway_Paysafe
 * @extends WC_Payment_Gateway
 * @version 1.0.4
 * Last updated: 2025-12-10
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Paysafe Payment Gateway
 */
class WC_Gateway_Paysafe extends WC_Payment_Gateway {

	/**
	 * Class properties - declare all properties to avoid PHP deprecation warnings
	 */
	protected $integration_type;
	protected $environment;
	protected $sandbox;
	protected $hide_for_checkout;
	protected $api_key_user;
	protected $api_key_password;
	protected $single_use_token_user;
	protected $single_use_token_password;
	protected $merchant_id;
	protected $cards_account_id_cad;
	protected $cards_account_id_usd;
	protected $direct_debit_account_id_cad;
	protected $direct_debit_account_id_usd;
	protected $enable_3ds_v1;
	protected $enable_3ds_v2;
	protected $three_ds_challenge_indicator;
	protected $three_ds_exemption_indicator;
	protected $payment_page_language;
	protected $authorization_type;
	protected $accepted_cards;
	protected $enable_saved_cards;
	protected $vault_prefix;
	protected $require_cvv_with_token;
	protected $default_payment_method;
	protected $enable_debug;
	protected $enable_apple_pay;
	protected $apple_pay_merchant_id;
	protected $apple_pay_merchant_name;
	protected $apple_pay_domain_verification;
	protected $enable_google_pay;
	protected $google_pay_merchant_id;
	protected $google_pay_merchant_name;
	protected $google_pay_environment;
	protected $pci_compliance_mode;
	protected $interac_apple_pay_mode;
	protected $interac_google_pay_mode;
	protected $log = null;
	protected $tokenization_handler = null;
	protected $rate_limit_enabled;
	protected $rate_limit_requests;
	protected $rate_limit_window;
	protected $rate_limit_message;
	
	/**
	 * Store card validation errors to add AFTER billing validation
	 * WooCommerce calls validate_fields() before billing errors are added to notices
	 * We defer card errors and add them via woocommerce_after_checkout_validation hook
	 * @var array
	 */
	protected $deferred_card_errors = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id = 'paysafe';
		$this->has_fields = true;
		$this->method_title = __('Paysafe', 'paysafe-payment');
		$this->method_description = __('Accept credit card payments through Paysafe/Merrco', 'paysafe-payment');

		// Admin-only icon (Payments list + classic settings).
		// Set the property only for admin screens or REST (used by WooCommerce's React Payments screen).
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
			$this->icon = PAYSAFE_PLUGIN_URL . 'assets/images/paysafe-logo.png';
		}

		// Ensure order stores the payment method ID, not the title
		$this->order_button_text = __('Place order', 'woocommerce');

		$this->supports = array(
			'products',
			'refunds',
			'tokenization',
			'add_payment_method',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
		);

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();
		$this->interac_apple_pay_mode  = $this->get_option('interac_apple_pay_mode', 'auto');
		$this->interac_google_pay_mode = $this->get_option('interac_google_pay_mode', 'disabled');

		// Define user set variables from settings
		$this->title = $this->get_option('title');
		$this->description = $this->get_option('description');
		$this->enabled = $this->get_option('enabled');
		$this->integration_type = $this->get_option('integration_type', 'checkout_api');
		$this->environment = $this->get_option('environment', 'sandbox');
		$this->sandbox = ($this->environment === 'sandbox');
		$this->hide_for_checkout = 'yes' === $this->get_option('hide_for_checkout');
		$this->api_key_user = $this->get_option('api_key_user');
		$this->api_key_password = $this->get_option('api_key_password');
		$this->single_use_token_user = $this->get_option('single_use_token_user');
		$this->single_use_token_password = $this->get_option('single_use_token_password');
		$this->merchant_id = $this->get_option('merchant_id');

		// Account IDs - stored as separate options for each currency
		$this->cards_account_id_cad = $this->get_option('cards_account_id_cad');
		$this->cards_account_id_usd = $this->get_option('cards_account_id_usd');
		$this->direct_debit_account_id_cad = $this->get_option('direct_debit_account_id_cad');
		$this->direct_debit_account_id_usd = $this->get_option('direct_debit_account_id_usd');

		$this->enable_3ds_v1 = 'yes' === $this->get_option('enable_3ds_v1');
		$this->enable_3ds_v2 = 'yes' === $this->get_option('enable_3ds_v2');
		$this->three_ds_challenge_indicator = $this->get_option('3ds_challenge_indicator', 'no_preference');
		$this->three_ds_exemption_indicator = $this->get_option('3ds_exemption_indicator', 'none');
		$this->payment_page_language = $this->get_option('payment_page_language', 'en');
		$this->authorization_type = $this->get_option('authorization_type', 'sale');

		// Get accepted cards - ensure it's an array
		$accepted_cards = $this->get_option('accepted_cards');
		if (empty($accepted_cards) || !is_array($accepted_cards)) {
			$accepted_cards = array('visa', 'mastercard', 'amex', 'discover');
		}
		$this->accepted_cards = $accepted_cards;

		$this->enable_saved_cards = 'yes' === $this->get_option('enable_saved_cards');
		$this->vault_prefix = $this->get_option('vault_prefix', 'PHZ-' . substr(md5(get_site_url()), 0, 4) . '-');
		$this->require_cvv_with_token = 'yes' === $this->get_option('require_cvv_with_token');
		$this->default_payment_method = $this->get_option('default_payment_method', 'cards');
		$this->enable_debug = 'yes' === $this->get_option('enable_debug');

		// Digital wallet settings
		$this->enable_apple_pay = 'yes' === $this->get_option('enable_apple_pay');
		$this->apple_pay_merchant_id = $this->get_option('apple_pay_merchant_id');
		$this->apple_pay_merchant_name = $this->get_option('apple_pay_merchant_name', get_bloginfo('name'));
		$this->apple_pay_domain_verification = $this->get_option('apple_pay_domain_verification');
		$this->enable_google_pay = 'yes' === $this->get_option('enable_google_pay');
		$this->google_pay_merchant_id = $this->get_option('google_pay_merchant_id');
		$this->google_pay_merchant_name = $this->get_option('google_pay_merchant_name', get_bloginfo('name'));
		$this->google_pay_environment = $this->get_option('google_pay_environment', 'TEST');
		$this->pci_compliance_mode = $this->get_option('pci_compliance_mode', 'saq_a_with_fallback');

		// Rate limiting settings
		$this->rate_limit_enabled = 'yes' === $this->get_option('rate_limit_enabled', 'yes');
		$this->rate_limit_requests = intval($this->get_option('rate_limit_requests', 30));
		$this->rate_limit_window = intval($this->get_option('rate_limit_window', 60));
		$this->rate_limit_message = $this->get_option('rate_limit_message', __('Rate limit exceeded. Please wait %d seconds before trying again.', 'paysafe-payment'));

// ALWAYS load token class (even if saved cards disabled) so WooCommerce can display existing tokens
		if (!class_exists('WC_Payment_Token_Paysafe')) {
			require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-payment-token.php';
		}

		// Initialize tokenization handler if saved cards are enabled
		if ($this->enable_saved_cards) {
			// Include tokenization class if not already loaded
			if (!class_exists('Paysafe_Tokenization')) {
				require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-tokenization.php';
			}

			// Initialize tokenization handler
			$this->tokenization_handler = new Paysafe_Tokenization($this);
		}

		// Ensure core API class is available across all flows
		if ( ! class_exists( 'Paysafe_API' ) ) {
			require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-api.php';
		}

		// Actions
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

		// Payment scripts
		add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

		// Custom admin scripts for enhanced settings
		add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));

		// Apple Pay domain verification
		add_action('init', array($this, 'maybe_handle_apple_pay_verification'));

		// Thank you page customizations
		add_filter('woocommerce_thankyou_order_received_text', array($this, 'customize_thankyou_message'), 10, 2);
		add_filter('woocommerce_get_order_item_totals', array($this, 'customize_payment_method_display'), 10, 3);

		// Fix payment method display on order received page - HIGHER PRIORITY
		add_filter('woocommerce_order_get_payment_method_title', array($this, 'get_order_payment_method_title'), 20, 2);

		// If the shopper created an account during checkout, attach the saved card after the user exists.
		add_action( 'woocommerce_thankyou', array( $this, 'maybe_attach_pending_token_to_user' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_attach_pending_token_to_user' ), 20, 1 );

		// Ensure token class is loaded early for WooCommerce token display
		add_action('woocommerce_payment_token_class', array($this, 'ensure_token_class_loaded'), 10, 2);
		

		// CRITICAL: Defer card validation errors until after billing validation
		// WooCommerce adds billing errors to WP_Error object first, then to notices AFTER validate_checkout()
		// Our validate_fields() runs BEFORE notices are added, so we can't check wc_notice_count()
		// Instead, store pending card errors and add them via this hook only if no billing errors exist
		add_action('woocommerce_after_checkout_validation', array($this, 'add_deferred_card_errors'), 10, 2);
	}

/**
	 * Ensure custom token class is loaded when WooCommerce needs it
	 * 
	 * @param string $class_name
	 * @param string $token_type
	 * @return string
	 */
	public function ensure_token_class_loaded($class_name, $token_type) {
		if ($token_type === 'CC' && !class_exists('WC_Payment_Token_Paysafe')) {
			require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-payment-token.php';
		}
		return $class_name;
	}

	/**
	 * Get centralized API settings
	 */
	public function get_api_settings() {
		return array(
			'api_username' => $this->api_key_user,
			'api_password' => $this->api_key_password,
			'single_use_token_username' => $this->single_use_token_user,
			'single_use_token_password' => $this->single_use_token_password,
			'merchant_id' => $this->merchant_id,
			'account_id_cad' => $this->cards_account_id_cad,
			'account_id_usd' => $this->cards_account_id_usd,
			'environment' => $this->environment,
			'debug' => $this->enable_debug ? 'yes' : 'no',
			'3ds_enabled' => $this->enable_3ds_v2 ? 'yes' : 'no',
			'3ds_challenge' => $this->three_ds_challenge_indicator,
			'3ds_exemption' => $this->three_ds_exemption_indicator,
			'rate_limit_enabled' => $this->rate_limit_enabled,
			'rate_limit_requests' => $this->rate_limit_requests,
			'rate_limit_window' => $this->rate_limit_window,
			'rate_limit_message' => $this->rate_limit_message
		);
	}

/**
	 * Get custom error message for error code
	 * 
	 * @param string $error_code Paysafe error code
	 * @param string $default_message Default message if no custom message set
	 * @return string Custom or default error message
	 */
	public function get_custom_error_message($error_code, $default_message = '') {
		// Map error codes to setting keys
		$error_map = array(
			// AVS errors
			'3007' => 'error_message_avs',
			'AVS_FAILED' => 'error_message_avs',
			'AVS_NO_MATCH' => 'error_message_avs',
			'AVS_NOT_PROCESSED' => 'error_message_avs',
			'ADDRESS_VERIFICATION_FAILED' => 'error_message_avs',
			
			// CVV errors
			'3023' => 'error_message_cvv',
			'5015' => 'error_message_cvv',
			'CVV_FAILED' => 'error_message_cvv',
			'CVV_NO_MATCH' => 'error_message_cvv',
			'INVALID_CVV' => 'error_message_cvv',
			
			// Insufficient funds
			'3022' => 'error_message_insufficient_funds',
			'3051' => 'error_message_insufficient_funds',
			'3052' => 'error_message_insufficient_funds',
			'INSUFFICIENT_FUNDS' => 'error_message_insufficient_funds',
			'NSF' => 'error_message_insufficient_funds',
			
			// Risk Management - Specific Detail Codes (take priority over general 4002)
			'4844' => 'error_message_risk_max_attempts',
			'4845' => 'error_message_risk_suspicious',
			'4846' => 'error_message_risk_geographic',
			'4847' => 'error_message_risk_velocity',
			'4848' => 'error_message_risk_device',
			'4849' => 'error_message_risk_ip',
			'4850' => 'error_message_risk_email',
			'4851' => 'error_message_risk_phone',
			
			// Risk Management - General (4002) - fallback if no specific detail code
			'4002' => 'error_message_risk_decline',
			'RISK_DECLINE' => 'error_message_risk_decline',
			
			// Card declined (including Risk Management)
			'3001' => 'error_message_declined',
			'3002' => 'error_message_declined',
			'3004' => 'error_message_declined',
			'3005' => 'error_message_declined',
			'3009' => 'error_message_declined',
			'5001' => 'error_message_declined',
			'DECLINED' => 'error_message_declined',
			'DO_NOT_HONOR' => 'error_message_declined',
			
			// Expired card
			'3012' => 'error_message_expired',
			'EXPIRED_CARD' => 'error_message_expired',
			'CARD_EXPIRED' => 'error_message_expired',
			
			// Invalid card
			'3011' => 'error_message_invalid_card',
			'5002' => 'error_message_invalid_card',
			'5003' => 'error_message_invalid_card',
			'INVALID_CARD' => 'error_message_invalid_card',
			'INVALID_CARD_NUMBER' => 'error_message_invalid_card',
		);
		
		// Cast error code to string and normalize for consistent array lookup (defensive)
		$error_code = strtoupper(trim((string) $error_code));
		
		$setting_key = isset($error_map[$error_code]) ? $error_map[$error_code] : '';
		
		if ($setting_key) {
			$custom_message = $this->get_option($setting_key);
			if (!empty($custom_message)) {
				// Allow HTML in custom messages but sanitize
				return wp_kses_post($custom_message);
			}
		}
		
		// Return default message if no custom message found
		return $default_message;
	}

	/**
	 * Initialize Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'integration_settings' => array(
				'title' => __('Integration Settings', 'paysafe-payment'),
				'type' => 'title',
				'description' => __('Choose the API integration you want to connect and Save Settings.', 'paysafe-payment'),
			),
			'integration_type' => array(
				'title' => __('Integration Type', 'paysafe-payment'),
				'type' => 'select',
				'default' => 'checkout_api',
				'options' => array(
					'checkout_api' => __('Checkout API', 'paysafe-payment'),
				),
				'desc_tip' => true,
				'description' => __('Choose the API integration you want to connect and Save Settings.', 'paysafe-payment'),
			),
			'enable_disable' => array(
				'title' => __('Enable/Disable', 'paysafe-payment'),
				'type' => 'title',
			),
			'enabled' => array(
				'title' => __('Enable Paysafe', 'paysafe-payment'),
				'type' => 'checkbox',
				'label' => __('Enable Paysafe Payment', 'paysafe-payment'),
				'default' => 'yes'
			),
			'environment' => array(
				'title' => __('Environment', 'paysafe-payment'),
				'type' => 'select',
				'default' => 'sandbox',
				'options' => array(
					'sandbox' => __('Sandbox Mode', 'paysafe-payment'),
					'live' => __('Live Mode', 'paysafe-payment'),
				),
				'description' => __('Select Live Mode to process real payments or Sandbox Mode for testing.', 'paysafe-payment'),
				'desc_tip' => true,
			),
			'hide_checkout' => array(
				'title' => __('Hide for Checkout Payments', 'paysafe-payment'),
				'type' => 'title',
			),
			'hide_for_checkout' => array(
				'title' => __('Displayed', 'paysafe-payment'),
				'type' => 'checkbox',
				'label' => __('Hide from checkout', 'paysafe-payment'),
				'default' => 'no',
				'description' => __('This option will remove the payment method from the checkout page and any new payment screens but will keep the method active in case of subscription recurring payments.', 'paysafe-payment'),
			),
			'general_settings' => array(
				'title' => __('General Settings', 'paysafe-payment'),
				'type' => 'title',
			),
			'title' => array(
				'title' => __('Method Title', 'paysafe-payment'),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'paysafe-payment'),
				'default' => __('Credit / Debit Card', 'paysafe-payment'),
				'desc_tip' => true,
			),
			'description' => array(
				'title' => __('Description', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('This controls the description which the user sees during checkout.', 'paysafe-payment'),
				'default' => __('Click on the "Place Order" Button below to Pay Securely Using Your Credit / Debit Card.', 'paysafe-payment'),
			),
			'credentials_settings' => array(
				'title' => __('Credentials Settings', 'paysafe-payment'),
				'type' => 'title',
				'description' => __('Enter your Paysafe API credentials below.', 'paysafe-payment'),
			),
			'api_key_user' => array(
				'title' => __('API Key: User Name', 'paysafe-payment') . ' <span class="required">*</span>',
				'type' => 'text',
				'description' => __('The User Name from the section API Keys in the Paysafe account settings.', 'paysafe-payment'),
				'default' => '',
				'desc_tip' => true,
			),
			'api_key_password' => array(
				'title' => __('API Key: Password', 'paysafe-payment') . ' <span class="required">*</span>',
				'type' => 'password',
				'description' => __('The corresponding password to the API User Name.', 'paysafe-payment'),
				'default' => '',
				'desc_tip' => true,
			),
			'single_use_token_user' => array(
				'title' => __('Single-Use Token: User Name', 'paysafe-payment'),
				'type' => 'text',
				'description' => __('The User Name from the section Single-Use Token in the Paysafe account settings.', 'paysafe-payment'),
				'default' => '',
				'desc_tip' => true,
			),
			'single_use_token_password' => array(
				'title' => __('Single-Use Token: Password', 'paysafe-payment'),
				'type' => 'password',
				'description' => __('The corresponding password to the Single-Use Token User Name.', 'paysafe-payment'),
				'default' => '',
				'desc_tip' => true,
			),
			'merchant_id' => array(
				'title' => __('Merchant ID', 'paysafe-payment'),
				'type' => 'text',
				'description' => __('Your Paysafe Merchant ID.', 'paysafe-payment'),
				'default' => '',
				'desc_tip' => true,
			),
			'merchant_accounts' => array(
				'title' => __('Merchant Accounts Settings', 'paysafe-payment'),
				'type' => 'title',
				'description' => __('Enter account IDs for each currency. Leave blank if not accepting that currency.', 'paysafe-payment'),
			),
			'cards_account_id_cad' => array(
				'title' => __('Cards Account ID (CAD)', 'paysafe-payment'),
				'type' => 'text',
				'description' => __('Account ID for Canadian Dollar card payments.', 'paysafe-payment'),
				'desc_tip' => true,
				'placeholder' => __('Enter CAD account ID', 'paysafe-payment'),
			),
			'cards_account_id_usd' => array(
				'title' => __('Cards Account ID (USD)', 'paysafe-payment'),
				'type' => 'text',
				'description' => __('Account ID for US Dollar card payments.', 'paysafe-payment'),
				'desc_tip' => true,
				'placeholder' => __('Enter USD account ID', 'paysafe-payment'),
			),
			'direct_debit_account_id_cad' => array(
				'title' => __('Direct Debit Account ID (CAD)', 'paysafe-payment'),
				'type' => 'text',
				'description' => __('Account ID for Canadian Dollar direct debit payments.', 'paysafe-payment'),
				'desc_tip' => true,
				'placeholder' => __('Enter CAD account ID', 'paysafe-payment'),
			),
			'direct_debit_account_id_usd' => array(
				'title' => __('Direct Debit Account ID (USD)', 'paysafe-payment'),
				'type' => 'text',
				'description' => __('Account ID for US Dollar direct debit payments.', 'paysafe-payment'),
				'desc_tip' => true,
				'placeholder' => __('Enter USD account ID', 'paysafe-payment'),
			),
			'3ds_settings' => array(
				'title' => __('3DS Settings', 'paysafe-payment'),
				'type' => 'title',
				'description' => __('Configure 3D Secure authentication for enhanced security.', 'paysafe-payment'),
			),
			'enable_3ds_v1' => array(
				'title' => __('3DS(v1)', 'paysafe-payment'),
				'type' => 'checkbox',
				'label' => __('Enable 3DS Authentication for card payments', 'paysafe-payment'),
				'default' => 'no',
				'description' => __('Enable 3D Secure version 1 for card payments. This adds an extra layer of security.', 'paysafe-payment'),
			),
			'enable_3ds_v2' => array(
				'title' => __('3DS(v2)', 'paysafe-payment'),
				'type' => 'checkbox',
				'label' => __('Enable 3DS2 Authentication for card payments', 'paysafe-payment'),
				'default' => 'no',
				'description' => __('Enable 3D Secure version 2 for card payments. This provides enhanced authentication with better user experience.', 'paysafe-payment'),
			),
			'3ds_challenge_indicator' => array(
				'title' => __('3DS2 Challenge Preference', 'paysafe-payment'),
				'type' => 'select',
				'default' => 'no_preference',
				'options' => array(
					'no_preference' => __('No preference', 'paysafe-payment'),
					'no_challenge' => __('No challenge requested', 'paysafe-payment'),
					'challenge_preferred' => __('Challenge preferred', 'paysafe-payment'),
					'challenge_mandated' => __('Challenge mandated', 'paysafe-payment'),
				),
				'description' => __('Set your preference for 3DS2 challenge flow. No preference lets the issuer decide.', 'paysafe-payment'),
				'desc_tip' => true,
			),
			'3ds_exemption_indicator' => array(
				'title' => __('3DS2 Exemption Request', 'paysafe-payment'),
				'type' => 'select',
				'default' => 'none',
				'options' => array(
					'none' => __('No exemption', 'paysafe-payment'),
					'tra' => __('Transaction Risk Analysis', 'paysafe-payment'),
					'low_value' => __('Low value (under 30 EUR)', 'paysafe-payment'),
					'secure_corporate' => __('Secure corporate payment', 'paysafe-payment'),
					'trusted_beneficiary' => __('Trusted beneficiary', 'paysafe-payment'),
				),
				'description' => __('Request exemption from Strong Customer Authentication if applicable.', 'paysafe-payment'),
				'desc_tip' => true,
			),
			'transaction_settings' => array(
				'title' => __('Transaction Settings', 'paysafe-payment'),
				'type' => 'title',
			),
			'payment_page_language' => array(
				'title' => __('Payment Page Language', 'paysafe-payment'),
				'type' => 'select',
				'default' => 'en',
				'options' => array(
					'en' => __('US English', 'paysafe-payment'),
				),
				'description' => __('Choose the language you want your Paysafe payment pages to be in.', 'paysafe-payment'),
				'desc_tip' => true,
			),
			'authorization_type' => array(
				'title' => __('Authorization Type', 'paysafe-payment'),
				'type' => 'select',
				'default' => 'sale',
				'options' => array(
					'sale' => __('Sale', 'paysafe-payment'),
					'authorization' => __('Authorization Only', 'paysafe-payment'),
				),
				'description' => __('"Sale" will capture the fund right after the transaction. "Authorization Only" will only perform an authorization and let you capture the funds at a later date.', 'paysafe-payment'),
				'desc_tip' => true,
			),
			'pci_compliance_mode' => array(
			'title' => __('PCI Compliance Mode', 'paysafe-payment'),
			'type' => 'select',
			'default' => 'saq_a_with_fallback',
			'options' => array(
				'saq_a_only' => __('SAQ-A Only (Hosted Fields)', 'paysafe-payment'),
				'saq_aep_only' => __('SAQ-A-EP Only (Direct Tokenization)', 'paysafe-payment'),
				'saq_a_with_fallback' => __('SAQ-A with SAQ-A-EP Fallback', 'paysafe-payment'),
			),
			'description' => __(
				'<strong>SAQ-A Only:</strong> Strictest PCI compliance. Uses Paysafe hosted fields (iframes) exclusively. Card data never touches your server. Autofill disabled. If hosted fields fail to load, payment will be blocked.<br><br>' .
				'<strong>SAQ-A-EP Only:</strong> Moderate PCI compliance. Card data is tokenized on your server before transmission to Paysafe. Autofill works. Requires SSL and additional security measures.<br><br>' .
				'<strong>SAQ-A with Fallback (Recommended):</strong> Attempts SAQ-A first for best security. Falls back to SAQ-A-EP if hosted fields fail. Balances security with reliability.', 
				'paysafe-payment'
			),
			'desc_tip' => false,
		),
			'accepted_cards' => array(
				'title' => __('Accepted Cards', 'paysafe-payment'),
				'type' => 'multiselect',
				'class' => 'wc-enhanced-select',
				'css' => 'width: 450px;',
				'default' => array('visa', 'mastercard', 'amex', 'discover'),
				'options' => array(
					'visa' => __('Visa', 'paysafe-payment'),
					'mastercard' => __('MasterCard', 'paysafe-payment'),
					'amex' => __('American Express', 'paysafe-payment'),
					'discover' => __('Discover', 'paysafe-payment'),
					'jcb' => __('JCB', 'paysafe-payment'),
					'diners' => __('Diners Club', 'paysafe-payment'),
					'interac' => __('Interac (Canada)', 'paysafe-payment'),
				),
				'description' => __(
					'Choose the cards you accept and want to display. Note: for Interac to appear in Apple Pay / Google Pay, Interac must be selected here and the store country must be Canada.',
					'paysafe-payment'
				),
				'desc_tip' => true,
				'custom_attributes' => array(
					'data-placeholder' => __('Select card types', 'paysafe-payment')
				),
			),
			'digital_wallet_settings' => array(
				'title' => __('Digital Wallet Settings', 'paysafe-payment'),
				'type' => 'title',
				'description' => __('Configure Apple Pay and Google Pay settings for your store.', 'paysafe-payment'),
			),
			'enable_apple_pay' => array(
				'title' => __('Apple Pay', 'paysafe-payment'),
				'type' => 'checkbox',
				'label' => __('Enable Apple Pay', 'paysafe-payment'),
				'default' => 'yes',
				'description' => __('Apple Pay will only be shown on supported devices and browsers (Safari on iOS/macOS).', 'paysafe-payment'),
			),
			'apple_pay_merchant_id' => array(
				'title' => __('Apple Pay Merchant ID', 'paysafe-payment'),
				'type' => 'text',
				'description' => __('Your Apple Pay Merchant Identifier (e.g., merchant.com.yourcompany).', 'paysafe-payment'),
				'desc_tip' => true,
				'placeholder' => 'merchant.com.yourcompany',
			),
			'apple_pay_merchant_name' => array(
				'title' => __('Apple Pay Merchant Name', 'paysafe-payment'),
				'type' => 'text',
				'description' => __('The merchant name to display during Apple Pay checkout.', 'paysafe-payment'),
				'desc_tip' => true,
				'default' => get_bloginfo('name'),
			),
			'apple_pay_domain_verification' => array(
				'title' => __('Apple Pay Domain Verification', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Apple Pay domain association file content. This needs to be served at /.well-known/apple-developer-merchantid-domain-association', 'paysafe-payment'),
				'desc_tip' => true,
				'css' => 'height: 100px;',
				'placeholder' => '7B227073696E67223A2257...',
			),
			'enable_google_pay' => array(
				'title' => __('Google Pay', 'paysafe-payment'),
				'type' => 'checkbox',
				'label' => __('Enable Google Pay', 'paysafe-payment'),
				'default' => 'yes',
				'description' => __('Google Pay will be shown on supported browsers (Chrome, Edge, etc.).', 'paysafe-payment'),
			),
			'google_pay_merchant_id' => array(
				'title' => __('Google Pay Merchant ID', 'paysafe-payment'),
				'type' => 'text',
				'description' => __('Your Google Pay Merchant ID. Required for production. Get it from Google Pay Business Console.', 'paysafe-payment'),
				'desc_tip' => true,
				'placeholder' => '12345678901234567890',
			),
			'google_pay_merchant_name' => array(
				'title' => __('Google Pay Merchant Name', 'paysafe-payment'),
				'type' => 'text',
				'description' => __('The merchant name to display during Google Pay checkout.', 'paysafe-payment'),
				'desc_tip' => true,
				'default' => get_bloginfo('name'),
			),
			'google_pay_environment' => array(
				'title' => __('Google Pay Environment', 'paysafe-payment'),
				'type' => 'select',
				'description' => __('Select TEST for development/testing. PRODUCTION requires a valid merchant ID.', 'paysafe-payment'),
				'desc_tip' => true,
				'default' => 'TEST',
				'options' => array(
					'TEST' => __('Test', 'paysafe-payment'),
					'PRODUCTION' => __('Production', 'paysafe-payment'),
				),
			),
			// Interac on Apple Pay
			'interac_apple_pay_mode' => array(
				'title'       => __('Interac on Apple Pay', 'paysafe-payment'),
				'type'        => 'select',
				'description' => __('Control whether Interac appears in Apple Pay.', 'paysafe-payment'),
				'default'     => 'auto', // Auto = only if accepted & CA
				'options'     => array(
					'auto'     => __('Auto (if accepted & CA)', 'paysafe-payment'),
					'enabled'  => __('Enabled', 'paysafe-payment'),
					'disabled' => __('Disabled', 'paysafe-payment'),
				),
			),

			// Interac on Google Pay
			'interac_google_pay_mode' => array(
				'title'       => __('Interac on Google Pay', 'paysafe-payment'),
				'type'        => 'select',
				'description' => __('Control whether Interac appears in Google Pay.', 'paysafe-payment'),
				'default'     => 'disabled', // safer default today
				'options'     => array(
					'auto'     => __('Auto (if accepted & CA)', 'paysafe-payment'),
					'enabled'  => __('Enabled', 'paysafe-payment'),
					'disabled' => __('Disabled', 'paysafe-payment'),
				),
			),
			'vault_settings' => array(
				'title' => __('Vault Settings', 'paysafe-payment'),
				'type' => 'title',
			),
			'enable_saved_cards' => array(
				'title' => __('Saved Cards', 'paysafe-payment'),
				'type' => 'checkbox',
				'label' => __('Enable Payment via Saved Cards', 'paysafe-payment'),
				'default' => 'yes',
			),
			'vault_prefix' => array(
				'title' => __('Vault Profile Prefix', 'paysafe-payment'),
				'type' => 'text',
				'default' => 'PHZ-' . substr(md5(get_site_url()), 0, 4) . '-',
				'description' => __('Enter unique customer prefix for their Vault profile ID. Make sure this is unique prefix for your store. (Example: WC-)', 'paysafe-payment'),
				'desc_tip' => true,
			),
			'cvv_settings' => array(
				'title' => __('CVV Card Field', 'paysafe-payment'),
				'type' => 'title',
			),
			'require_cvv_with_token' => array(
				'title' => __('Require CVV with Card Token Payments', 'paysafe-payment'),
				'type' => 'checkbox',
				'label' => __('Require CVV with Card Token Payments', 'paysafe-payment'),
				'default' => 'yes',
			),
			'default_payment_method_settings' => array(
				'title' => __('Default Payment Method', 'paysafe-payment'),
				'type' => 'title',
			),
			'default_payment_method' => array(
				'title' => __('Default Payment Method', 'paysafe-payment'),
				'type' => 'select',
				'default' => 'cards',
				'options' => array(
					'cards' => __('Cards', 'paysafe-payment'),
				),
				'description' => __('The payment method selected when Paysafe Checkout is opened.', 'paysafe-payment'),
				'desc_tip' => true,
			),
			'rate_limiting_settings' => array(
				'title' => __('API Rate Limiting', 'paysafe-payment'),
				'type' => 'title',
				'description' => __('Configure rate limiting to prevent hitting API limits.', 'paysafe-payment'),
			),
			'rate_limit_enabled' => array(
				'title' => __('Enable Rate Limiting', 'paysafe-payment'),
				'type' => 'checkbox',
				'label' => __('Enable API rate limiting', 'paysafe-payment'),
				'default' => 'yes',
				'description' => __('Recommended: Keep enabled to prevent hitting API limits', 'paysafe-payment'),
			),
			'rate_limit_requests' => array(
				'title' => __('Requests per Window', 'paysafe-payment'),
				'type' => 'number',
				'default' => '30',
				'description' => __('Maximum API requests within time window (1-100)', 'paysafe-payment'),
				'desc_tip' => true,
				'custom_attributes' => array(
					'min' => 1,
					'max' => 100,
				),
			),
			'rate_limit_window' => array(
				'title' => __('Time Window (seconds)', 'paysafe-payment'),
				'type' => 'number',
				'default' => '60',
				'description' => __('Rolling window duration in seconds (10-300)', 'paysafe-payment'),
				'desc_tip' => true,
				'custom_attributes' => array(
					'min' => 10,
					'max' => 300,
				),
			),
			'rate_limit_message' => array(
				'title' => __('Rate Limit Message', 'paysafe-payment'),
				'type' => 'text',
				'default' => __('Rate limit exceeded. Please wait %d seconds before trying again.', 'paysafe-payment'),
				'description' => __('Message shown when rate limit is exceeded. Use %d for wait time.', 'paysafe-payment'),
				'desc_tip' => true,
			),
			'error_messages_settings' => array(
				'title' => __('Custom Error Messages', 'paysafe-payment'),
				'type' => 'title',
				'description' => __('Customize error messages to help customers fix payment issues. Use HTML for formatting. Leave blank to use default messages.', 'paysafe-payment'),
			),
			'error_message_avs' => array(
				'title' => __('AVS Mismatch Error', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when billing address doesn\'t match card. Error codes: 3007, AVS_FAILED, AVS_NO_MATCH, AVS_NOT_PROCESSED, ADDRESS_VERIFICATION_FAILED', 'paysafe-payment'),
				'default' => __('<strong>Address Verification Failed.</strong> The billing address you entered doesn\'t match your card\'s registered address. Please check your address and try again, or contact your bank.', 'paysafe-payment'),
				'desc_tip' => false,
				'css' => 'min-height: 80px; width: 100%;',
			),
			'error_message_cvv' => array(
				'title' => __('CVV/Security Code Error', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when CVV/CVC is incorrect. Error codes: 3023, 5015, CVV_FAILED, CVV_NO_MATCH, INVALID_CVV', 'paysafe-payment'),
				'default' => __('<strong>Security Code Invalid.</strong> The 3 or 4-digit security code on your card is incorrect. Check the back of your card (or front for Amex) and try again.', 'paysafe-payment'),
				'desc_tip' => false,
				'css' => 'min-height: 80px; width: 100%;',
			),
			'error_message_insufficient_funds' => array(
				'title' => __('Insufficient Funds Error', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when card has insufficient funds. Error codes: 3022, 3051, 3052, INSUFFICIENT_FUNDS, NSF', 'paysafe-payment'),
				'default' => __('<strong>Insufficient Funds.</strong> Your card doesn\'t have enough funds to complete this purchase. Please use a different payment method or contact your bank.', 'paysafe-payment'),
				'desc_tip' => false,
				'css' => 'min-height: 80px; width: 100%;',
			),

				// ============================================================
				// Risk Management Error Messages
				// ============================================================
				
			'risk_management_heading' => array(
				'title' => __('Risk Management / Fraud Prevention', 'paysafe-payment'),
				'type' => 'title',
				'description' => __('Messages for transactions blocked by Paysafe\'s fraud prevention system. Specific codes (4844, 4845, etc.) take priority over the general Risk Decline message.', 'paysafe-payment'),
			),
			
			'error_message_risk_max_attempts' => array(
				'title' => __('Max Attempts Reached (4844)', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when customer has exceeded the maximum number of failed payment attempts within 24 hours. Error code: 4844', 'paysafe-payment'),
				'default' => '<strong>Too Many Failed Attempts:</strong> You have exceeded the maximum number of payment attempts allowed within 24 hours. Please wait 24 hours before trying again, or contact us for assistance.',
				'desc_tip' => true,
				'class' => 'paysafe-custom-error-message',
				'custom_attributes' => array(
					'rows' => 3
				)
			),
			
			'error_message_risk_suspicious' => array(
				'title' => __('Suspicious Activity (4845)', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when transaction is blocked due to suspicious activity patterns detected by fraud prevention. Error code: 4845', 'paysafe-payment'),
				'default' => '<strong>Transaction Flagged:</strong> This transaction has been flagged for suspicious activity. For your security, please contact us to complete your purchase.',
				'desc_tip' => true,
				'class' => 'paysafe-custom-error-message',
				'custom_attributes' => array(
					'rows' => 3
				)
			),
			
			'error_message_risk_geographic' => array(
				'title' => __('Geographic Mismatch (4846)', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when billing location doesn\'t match expected patterns or card issuer location. Error code: 4846', 'paysafe-payment'),
				'default' => '<strong>Location Verification Failed:</strong> The billing location doesn\'t match our records. Please verify your billing information or contact us for assistance.',
				'desc_tip' => true,
				'class' => 'paysafe-custom-error-message',
				'custom_attributes' => array(
					'rows' => 3
				)
			),
			
			'error_message_risk_velocity' => array(
				'title' => __('Velocity Check Failed (4847)', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when too many transactions attempted in a short time period (spending velocity exceeded). Error code: 4847', 'paysafe-payment'),
				'default' => '<strong>Transaction Limit Exceeded:</strong> You have exceeded the transaction limit for this time period. Please wait a few hours and try again, or contact us for assistance.',
				'desc_tip' => true,
				'class' => 'paysafe-custom-error-message',
				'custom_attributes' => array(
					'rows' => 3
				)
			),
			
			'error_message_risk_device' => array(
				'title' => __('Device/Browser Issue (4848)', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when device fingerprinting or browser security checks fail. Error code: 4848', 'paysafe-payment'),
				'default' => '<strong>Device Verification Failed:</strong> We couldn\'t verify your device or browser. Please try using a different browser, disable ad blockers, or contact us for assistance.',
				'desc_tip' => true,
				'class' => 'paysafe-custom-error-message',
				'custom_attributes' => array(
					'rows' => 3
				)
			),
			
			'error_message_risk_ip' => array(
				'title' => __('IP Address Flagged (4849)', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when the customer\'s IP address has been flagged by fraud prevention systems. Error code: 4849', 'paysafe-payment'),
				'default' => '<strong>IP Address Blocked:</strong> Your IP address has been flagged for security reasons. Please contact us to complete your purchase, or try again from a different network.',
				'desc_tip' => true,
				'class' => 'paysafe-custom-error-message',
				'custom_attributes' => array(
					'rows' => 3
				)
			),
			
			'error_message_risk_email' => array(
				'title' => __('Email Address Flagged (4850)', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when the email address provided has been flagged by fraud prevention systems. Error code: 4850', 'paysafe-payment'),
				'default' => '<strong>Email Address Blocked:</strong> The email address provided has been flagged for security reasons. Please contact us to complete your purchase, or try with a different email address.',
				'desc_tip' => true,
				'class' => 'paysafe-custom-error-message',
				'custom_attributes' => array(
					'rows' => 3
				)
			),
			
			'error_message_risk_phone' => array(
				'title' => __('Phone Number Flagged (4851)', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when the phone number provided has been flagged by fraud prevention systems. Error code: 4851', 'paysafe-payment'),
				'default' => '<strong>Phone Number Blocked:</strong> The phone number provided has been flagged for security reasons. Please contact us to complete your purchase, or try with a different phone number.',
				'desc_tip' => true,
				'class' => 'paysafe-custom-error-message',
				'custom_attributes' => array(
					'rows' => 3
				)
			),
			
			'error_message_risk_decline' => array(
				'title' => __('General Risk Decline (4002)', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Fallback message for other Risk Management declines that don\'t match specific codes above. Error codes: 4002, RISK_DECLINE', 'paysafe-payment'),
				'default' => '<strong>Payment Blocked by Security:</strong> This transaction was flagged by our fraud prevention system. Please contact us for assistance or try a different payment method.',
				'desc_tip' => true,
				'class' => 'paysafe-custom-error-message',
				'custom_attributes' => array(
					'rows' => 3
				)
			),
			
			// ============================================================
			// General Decline Messages
			// ============================================================
			
			'general_decline_heading' => array(
				'title' => __('General Declines', 'paysafe-payment'),
				'type' => 'title',
				'description' => __('Messages for standard card declines not related to Risk Management.', 'paysafe-payment'),
			),
				
			'error_message_declined' => array(
				'title' => __('Card Declined Error', 'paysafe-payment'),
				'type' => 'textarea',
					'description' => __('Message shown when card is declined by issuing bank (not AVS/CVV/insufficient funds/risk). Error codes: 3001, 3002, 3004, 3005, 3009, 5001, DECLINED, DO_NOT_HONOR', 'paysafe-payment'),
				'default' => __('<strong>Payment Declined.</strong> Your payment was declined. This could be due to insufficient funds, daily spending limits, suspected fraud protection, or bank restrictions. Please try a different payment method or contact your bank for assistance.', 'paysafe-payment'),
				'desc_tip' => false,
				'css' => 'min-height: 80px; width: 100%;',
			),
			'error_message_expired' => array(
				'title' => __('Expired Card Error', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when card is expired. Error codes: 3012, EXPIRED_CARD, CARD_EXPIRED', 'paysafe-payment'),
				'default' => __('<strong>Card Expired.</strong> The card you\'re trying to use has expired. Please use a different card or contact your bank for a replacement.', 'paysafe-payment'),
				'desc_tip' => false,
				'css' => 'min-height: 80px; width: 100%;',
			),
			'error_message_invalid_card' => array(
				'title' => __('Invalid Card Number Error', 'paysafe-payment'),
				'type' => 'textarea',
				'description' => __('Shown when card number is invalid. Error codes: 3011, 5002, 5003, INVALID_CARD, INVALID_CARD_NUMBER', 'paysafe-payment'),
				'default' => __('<strong>Invalid Card Number.</strong> The card number you entered is invalid. Please check the number and try again.', 'paysafe-payment'),
				'desc_tip' => false,
				'css' => 'min-height: 80px; width: 100%;',
			),
			'test_debug_settings' => array(
				'title' => __('Test and Debug Settings', 'paysafe-payment'),
				'type' => 'title',
			),
			'enable_debug' => array(
				'title' => __('Enable Debug Mode', 'paysafe-payment'),
				'type' => 'checkbox',
				'label' => __('Enable Debug mode', 'paysafe-payment'),
				'default' => 'no',
				'description' => sprintf(__('Debug logs the plugin processes for easier troubleshooting. Logged issues <a href="%s">@woocommerce > Status > Logs</a>', 'paysafe-payment'), admin_url('admin.php?page=wc-status&tab=logs')),
			),
		);
	}

	/**
	 * Admin scripts
	 */
	public function admin_scripts($hook) {
	   if ( 'woocommerce_page_wc-settings' !== $hook ) {
			   return;
	   }

	   // Sanitize queried section before comparing.
	   $section = isset( $_GET['section'] )
			   ? sanitize_key( wp_unslash( $_GET['section'] ) )
			   : '';

	   if ( 'paysafe' === $section ) {
			   $ver = defined( 'PAYSAFE_VERSION' ) ? PAYSAFE_VERSION : null;

			   // Admin CSS for our settings section.
			   wp_enqueue_style(
					   'paysafe-admin',
					   PAYSAFE_PLUGIN_URL . 'assets/css/admin-style.css',
					   array(),
					   $ver
			   );

			   /**
				* WooCommerce 10.3+ renamed some core admin scripts:
				*   - jquery-tiptip   → wc-jquery-tiptip
				*   - select2         → wc-select2
				*
				* We enqueue the NEW handles here so:
				*   1. Our settings JS can safely call tipTip / wc-enhanced-select.
				*   2. We avoid deprecation notices in debug.log.
				*
				* These handles are registered by WooCommerce on admin pages,
				* so they are safe to enqueue here.
				*/
			   wp_enqueue_script( 'wc-jquery-tiptip' );
			   wp_enqueue_script( 'wc-select2' );

			   // Our admin JS (depends on jQuery + WooCommerce admin helpers above).
			   wp_enqueue_script(
					   'paysafe-admin',
					   PAYSAFE_PLUGIN_URL . 'assets/js/admin-settings.js',
					   array( 'jquery', 'wc-jquery-tiptip', 'wc-select2' ),
					   $ver,
					   true
			   );

			   // Localize the script with translations and data.
			   wp_localize_script(
					   'paysafe-admin',
					   'paysafe_admin',
					   array(
							   'ajax_url' => admin_url( 'admin-ajax.php' ),
							   'nonce'    => wp_create_nonce( 'paysafe_admin_nonce' ),
							   'i18n'     => array(
									   'enter_account_id'        => __( 'Please enter an Account ID', 'paysafe-payment' ),
									   'currency_exists'         => __( 'An account ID for this currency already exists. Please remove it first.', 'paysafe-payment' ),
									   'cad_label'               => __( 'Canadian dollar', 'paysafe-payment' ),
									   'usd_label'               => __( 'United States (US) dollar', 'paysafe-payment' ),
									   'remove'                  => __( 'Remove', 'paysafe-payment' ),
									   'confirm_remove'          => __( 'Are you sure you want to remove this account ID?', 'paysafe-payment' ),
									   'validate_credentials'    => __( 'Validate Credentials', 'paysafe-payment' ),
									   'validating'              => __( 'Validating...', 'paysafe-payment' ),
									   'enter_both_credentials'  => __( 'Please enter both API Key User Name and Password', 'paysafe-payment' ),
									   'valid'                   => __( 'Valid', 'paysafe-payment' ),
									   'invalid'                 => __( 'Invalid credentials', 'paysafe-payment' ),
									   'error_validating'        => __( 'Error validating credentials', 'paysafe-payment' ),
									   'live_mode_warning'       => __( 'You are about to enable LIVE mode. This will process real payments. Are you sure?', 'paysafe-payment' ),
									   'debug_mode_warning'      => __( 'Debug mode will log all API requests and responses including sensitive data. This should only be enabled for troubleshooting and disabled immediately after. Continue?', 'paysafe-payment' ),
									   'copied'                  => __( 'Copied!', 'paysafe-payment' ),
									   'copy_failed'             => __( 'Copy failed. Please copy manually.', 'paysafe-payment' ),
							   ),
					   )
			   );
	   }
	}

/**
 * Customize the thank you message
 */
public function customize_thankyou_message( $message, $order ) {
	// Only override the thank-you hero box for Paysafe orders.
	if ( $order && $order->get_payment_method() === $this->id ) {

		$message = '<div style="
			background: #E2E2F0;
			padding: 1.5em 2em;
			border-radius: 0.5em;
			border: 2px solid #1E4270;
			box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1), 0 2px 4px rgba(0, 0, 0, 0.06);
			margin: 1em 0 2em 0;
			text-align: center;
		">
			<p style="
				font-size: 1.5em;
				font-weight: bold;
				color: #1E4270;
				margin: 0;
				line-height: 1.3;
			">' . __(
				'Awesome! We\'ve got your order and we\'re on it! 💯 You\'ll get a shipping notification as soon as your package is on its way.',
				'paysafe-payment'
			) . '</p>
		</div>';
	}

	return $message;
}

	/**
	 * Customize payment method display on thank you page and emails
	 */
	public function customize_payment_method_display($total_rows, $order, $tax_display) {
		// Only modify for Paysafe payments
		if ($order->get_payment_method() === $this->id) {
			// Get the stored card type
			$card_type = $order->get_meta('_paysafe_card_type');
			$card_suffix = $order->get_meta('_paysafe_card_suffix');

			if (!$card_type) {
				// Try to get from transaction meta if not found
				$card_type = $order->get_meta('_transaction_card_type');
			}

			// Build the payment method display
			$payment_method_display = $this->title;

			if ($card_type) {
				// Get the card display name
				$card_display_map = array(
					'visa' => 'Visa',
					'mastercard' => 'Mastercard',
					'amex' => 'American Express',
					'discover' => 'Discover',
					'jcb' => 'JCB',
					'diners' => 'Diners Club',
					'interac' => 'Interac',
				);

				$card_name = isset($card_display_map[$card_type]) ? $card_display_map[$card_type] : ucfirst($card_type);

				// Create the display with ONLY the used card icon inline with text
				$icon_url = PAYSAFE_PLUGIN_URL . 'assets/images/card-' . $card_type . '.svg';

				// Wrap entire payment method display to shift it left
				$payment_method_display = sprintf(
					'<div style="margin-left: 0em;">%s<img src="%s" alt="%s" style="height: 2.25em; width: auto; display: inline-block; vertical-align: middle; margin-left: 0.75em; position: relative;" onerror="this.style.display=\'none\'" />',
					esc_html($this->title),
					esc_url($icon_url),
					esc_attr($card_name)
				);

				// Add card details on the same shifted alignment
				if ($card_suffix) {
					$payment_method_display .= sprintf(
						'<br><small>%s ending in %s</small>',
						esc_html($card_name),
						esc_html($card_suffix)
					);
				}

				$payment_method_display .= '</div>';
			}

			// Update the payment method row
			if (isset($total_rows['payment_method'])) {
				$total_rows['payment_method']['value'] = $payment_method_display;
			}
		}

		return $total_rows;
	}

	/**
	 * Get order payment method title
	 */
	public function get_order_payment_method_title($title, $order) {
		if ($order->get_payment_method() === $this->id) {
			$title = $this->title;

			// Add card icon on order-received page
			if (is_wc_endpoint_url('order-received')) {
				$card_type = $order->get_meta('_paysafe_card_type');
				if (!$card_type) {
					$card_type = $order->get_meta('_transaction_card_type');
				}

				if ($card_type) {
					$icon_url = PAYSAFE_PLUGIN_URL . 'assets/images/card-' . $card_type . '.svg';
					if (file_exists(PAYSAFE_PLUGIN_PATH . 'assets/images/card-' . $card_type . '.svg')) {
						 // Human-friendly alt text for the icon
						$card_display_map = array(
							'visa' => 'Visa',
							'mastercard' => 'Mastercard',
							'amex' => 'American Express',
							'discover' => 'Discover',
							'jcb' => 'JCB',
							'diners' => 'Diners Club',
							'interac' => 'Interac',
						);
						$alt = isset($card_display_map[$card_type]) ? $card_display_map[$card_type] : ucfirst($card_type);
						// Use relative/absolute positioning to prevent icon from affecting text baseline
						$title = '<span style="position: relative; padding-right: 3.5em;">' . 
								 esc_html($this->title) . 
								 '<img src="' . esc_url($icon_url) . '" alt="' . esc_attr($alt) . '" style="height: 2.25em; width: auto; position: absolute; right: 0; top: -55%; transform: translateY(-50%);" />' .
								 '</span>';
					}
				}
			}
		}
		return $title;
	}
   
	/**
	 * Check if gateway is available
	 */
	public function is_available() {
		if ('yes' !== $this->enabled) {
			return false;
		}

		if ('yes' === $this->hide_for_checkout && (is_checkout() || is_checkout_pay_page())) {
			// Only hide if not processing a subscription renewal
			if (!class_exists('WC_Subscriptions_Cart') || !WC_Subscriptions_Cart::cart_contains_subscription()) {
				return false;
			}
		}

		// Keep the gateway "available" only on the saved-methods listing so Woo can render tokens.
		// Do NOT bypass checks on Add payment method; that page must respect enable/credentials/currency.
		if ( is_account_page() && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'payment-methods' ) ) {
			return true;
		}

		if ( !$this->api_key_user || !$this->api_key_password ) {
			return false;
		}

		// Currency/account gating: only show when usable
		// Default to the store currency…
		$currency = get_woocommerce_currency();
		// …but if we're on the "Pay for Order" page, use that order's currency.
		if ( is_checkout_pay_page() ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			if ( $order_id ) {
				$pay_order = wc_get_order( $order_id );
				if ( $pay_order ) {
					$currency = $pay_order->get_currency();
				}
			}
		}
		if ( $currency === 'USD' && empty( $this->cards_account_id_usd ) ) {
			return false;
		}
		if ( $currency === 'CAD' && empty( $this->cards_account_id_cad ) ) {
			return false;
		}
		if ( $currency !== 'USD' && $currency !== 'CAD' ) {
			return false;
		}

		return true;
	}

	/**
	 * Payment form on checkout page
	 */
	public function payment_fields() {
		if ($this->description) {
			echo '<p>' . wp_kses_post($this->description) . '</p>';
		}

		// Check if saved cards are enabled and user is logged in
		if ($this->enable_saved_cards && is_user_logged_in()) {
			// Make sure the token class is loaded
			if (!class_exists('WC_Payment_Token_Paysafe')) {
				require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-payment-token.php';
			}

			// Get saved tokens for this gateway
			$saved_tokens = WC_Payment_Tokens::get_customer_tokens(get_current_user_id(), $this->id);

			// Filter out expired cards
			$valid_tokens = array();
			$current_year  = (int) date('Y');
			$current_month = (int) date('n');

			foreach ($saved_tokens as $token) {
				if (method_exists($token, 'get_expiry_year') && method_exists($token, 'get_expiry_month')) {
					$expiry_year  = (int) $token->get_expiry_year();
					$expiry_month = (int) $token->get_expiry_month();

					// Check if card is not expired
					if ($expiry_year > $current_year || 
						($expiry_year == $current_year && $expiry_month >= $current_month)) {
						$valid_tokens[] = $token;
					}
				}
			}

			// Only show saved cards section if there are valid saved cards
			if (!empty($valid_tokens)) {
				echo '<style>
					.paysafe-card-selection-wrapper {
						margin: 1em 0;
					}

					.paysafe-card-option {
						margin: 0.75em 0;
						position: relative;
					}

					.paysafe-card-option > label {
						display: flex;
						align-items: center;
						cursor: pointer;
						font-size: 1.25em !important;
						font-weight: bold !important;
						color: #1D942F !important;
						margin: 0;
						padding: 0;
					}

					.paysafe-card-option input[type="radio"] {
						width: 0.85em !important;
						height: 0.85em !important;
						margin-right: 0.5em;
						margin-top: 0;
						margin-bottom: 0;
						cursor: pointer;
					}

					.paysafe-saved-cards-container {
						background: #f9f9f9;
						border: 1px solid #e0e0e0;
						border-radius: 8px;
						padding: 1em;
						margin: 0.75em 0 0.75em 2em;
						transition: all 0.3s ease;
					}

					.paysafe-saved-card-item {
						background: white;
						border: 1px solid #ddd;
						border-radius: 6px;
						padding: 0.75em 1em;
						margin-bottom: 0;
						transition: all 0.2s ease;
						display: flex;
						align-items: center;
						justify-content: space-between;
					}

					.paysafe-saved-card-item:hover {
						border-color: #1D942F;
						box-shadow: 0 2px 4px rgba(29, 148, 47, 0.1);
					}

					.paysafe-saved-card-item label {
						display: flex;
						align-items: center;
						cursor: pointer;
						margin: 0;
						flex: 1;
					}

					.paysafe-saved-card-item input[type="radio"] {
						margin-right: 0.75em;
						cursor: pointer;
						flex-shrink: 0;
					}

					.paysafe-card-details {
						display: flex;
						align-items: center;
						flex: 1;
						gap: 0.75em;
						white-space: nowrap;
					}

					.paysafe-card-details img {
						height: 1.5em;
						width: auto;
						flex-shrink: 0;
					}

					.paysafe-card-text {
						color: #333;
						font-size: 0.95em;
						white-space: nowrap;
					}

					.paysafe-card-last4 {
						font-weight: 600;
						color: #000;
					}

					.paysafe-card-expiry {
						color: #666;
						font-size: 0.9em;
						margin-left: 0.25em;
					}

					.paysafe-payment-form-hidden {
						display: none !important;
					}

					.paysafe-payment-section {
						transition: opacity 0.3s ease, height 0.3s ease;
					}

					.paysafe-saved-card-cvv {
						margin-left: auto;
						padding-left: 0.5em;
						position: relative;
						display: flex;
						align-items: center;
						gap: 0.25em;
						flex-shrink: 0;
					}

					.paysafe-saved-card-cvv label {
						display: none;
					}

					.paysafe-saved-card-cvv input {
						width: 8em;
						padding: 0.4em 0.5em;
						border: 2px solid #4A90E2;
						border-radius: 4px;
						font-size: 1em;
						transition: border-color 0.2s ease, box-shadow 0.2s ease;
						box-shadow: 0 0 0 0px #1E4270;
					}
					
					.paysafe-saved-card-cvv input:hover {
						border-color: #1E4270;
					}
					
					.paysafe-saved-card-cvv input:focus {
						outline: none;
						border-color: #1E4270;
						box-shadow: 0 0 0 1px #1E4270;
					}

					.paysafe-saved-card-cvv input::placeholder {
						font-size: 0.85em;
						color: #999;
					}

					.paysafe-cvv-help-icon {
						display: inline-flex;
						align-items: center;
						justify-content: center;
						width: 1.2em;
						height: 1.2em;
						background: #666;
						color: white;
						border-radius: 50%;
						font-size: 0.85em;
						font-weight: bold;
						cursor: help;
						position: relative;
						flex-shrink: 0;
					}

					.paysafe-cvv-tooltip {
						display: none;
						position: absolute;
						bottom: calc(100% + 10px);
						right: -10px;
						background: #333;
						color: white;
						padding: 0.75em 1em;
						border-radius: 6px;
						font-size: 1em;
						line-height: 1.4;
						width: 15.625em;
						z-index: 1000;
						box-shadow: 0 2px 8px rgba(0,0,0,0.2);
					}

					.paysafe-cvv-tooltip::after {
						content: "";
						position: absolute;
						top: 100%;
						right: 0.9375em;
						border: 0.375em solid transparent;
						border-top-color: #333;
					}

					.paysafe-cvv-help-icon:hover .paysafe-cvv-tooltip {
						display: block;
					}

					.paysafe-new-card-option-wrapper {
						display: block !important;
					}
				</style>';

				echo '<div class="paysafe-card-selection-wrapper">';

				// Use a saved card option - only show if user has saved cards
				echo '<div class="paysafe-card-option">';
				echo '<label>';
				echo '<input type="radio" name="paysafe-card-selection" value="saved" id="paysafe-use-saved-card" checked="checked" />';
				echo '<span>' . __('Use a saved card', 'paysafe-payment') . '</span>';
				echo '</label>';
				echo '</div>';

				// Use a new card option - always show, positioned right after saved card option
				echo '<div class="paysafe-card-option paysafe-new-card-option-wrapper">';
				echo '<label>';
				echo '<input type="radio" name="paysafe-card-selection" value="new" id="paysafe-use-new-card" />';
				echo '<span>' . __('Use a new card', 'paysafe-payment') . '</span>';
				echo '</label>';
				echo '</div>';

				// Saved cards list container
				echo '<div class="paysafe-saved-cards-container" id="paysafe-saved-cards-list">';

				$first_token = true;
				foreach ($valid_tokens as $token) {
					if (method_exists($token, 'get_card_type')) {
						$card_type = $token->get_card_type();
						$last4 = $token->get_last4();
						$expiry_year  = (int) $token->get_expiry_year();
						$expiry_month = (int) $token->get_expiry_month();

						echo '<div class="paysafe-saved-card-item">';
						echo '<label>';
						echo '<input type="radio" name="wc-paysafe-payment-token" value="' . esc_attr($token->get_id()) . '"';
						if ($first_token || $token->is_default()) {
							echo ' checked="checked"';
							$first_token = false;
						}
						echo ' />';

						echo '<div class="paysafe-card-details">';

						// Add card type icon
						$icon_url = PAYSAFE_PLUGIN_URL . 'assets/images/card-' . $card_type . '.svg';
						if (file_exists(PAYSAFE_PLUGIN_PATH . 'assets/images/card-' . $card_type . '.svg')) {
						$alt = wc_get_credit_card_type_label( $card_type );
						echo '<img src="' . esc_url($icon_url) . '" alt="' . esc_attr($alt) . '" />';
						}

						echo '<span class="paysafe-card-text">';
						echo esc_html(wc_get_credit_card_type_label($card_type));
						echo ' ending in <span class="paysafe-card-last4">' . esc_html($last4) . '</span>';
						$yy = (int) ($expiry_year % 100);
						echo '<span class="paysafe-card-expiry">(expires ' . esc_html(sprintf('%02d/%02d', $expiry_month, $yy)) . ')</span>';
						echo '</span>'; 
						echo '</div>';
						echo '</label>';

						// Add CVV field inline with card details if required
						if ($this->require_cvv_with_token) {
							$cvv_input_id = 'saved_card_cvv_' . $token->get_id();
							echo '<div class="paysafe-saved-card-cvv">';
							echo '<label for="' . esc_attr( $cvv_input_id ) . '" class="screen-reader-text">' . __('Security code', 'paysafe-payment') . '</label>';
							echo '<input type="text" ';
							echo 'id="' . esc_attr( $cvv_input_id ) . '" ';
							echo 'class="paysafe-saved-card-cvv-input" ';
							echo 'data-saved-card="true" ';
							echo 'placeholder="' . __('Security code', 'paysafe-payment') . '" ';
							echo 'autocomplete="cc-csc" ';
							echo 'inputmode="numeric" ';
							echo 'pattern="[0-9]*" ';
							echo 'maxlength="4" ';
							echo 'aria-label="' . __('Security code', 'paysafe-payment') . '" ';
							echo 'aria-required="true" />';
							echo '<span class="paysafe-cvv-help-icon">?';
							echo '<span class="paysafe-cvv-tooltip">' . __('3-digit security code usually found on the back of your card. American Express cards have a 4-digit code located on the front. Also known as a CVV or CVC code.', 'paysafe-payment') . '</span>';
							echo '</span>';
							echo '</div>';
						}

						echo '</div>';
					}
				}

				echo '</div>'; // End saved cards container

				echo '</div>'; // End card selection wrapper

				// Add JavaScript for toggling
				echo '<script type="text/javascript">
				jQuery(document).ready(function($) {
					function syncSavedCardCVVName() {
						// Enable & name the CVV for the selected token, disable others
						$("input[name=\"wc-paysafe-payment-token\"]").each(function () {
							var $radio = $(this);
							var isSelected = $radio.is(":checked");
							var $cvv = $radio.closest(".paysafe-saved-card-item").find(".paysafe-saved-card-cvv-input");
							if (isSelected) {
								$cvv.prop("disabled", false).attr("name", "paysafe_saved_card_cvv");
							} else {
								$cvv.prop("disabled", true).removeAttr("name");
							}
						});
					}

					function togglePaysafeCardForm() {
						var useNewCard = $("#paysafe-use-new-card").is(":checked");
						var useSavedCard = $("#paysafe-use-saved-card");
						var savedCardsOption = useSavedCard.closest(".paysafe-card-option");
						var savedCardsList = $("#paysafe-saved-cards-list");
						var paymentForm = $(".paysafe-woocommerce-form");
						var saveCardCheckbox = $(".paysafe-save-card-wc");

						if (useNewCard) {
							// Hide only the saved cards list, not the option
							savedCardsList.slideUp(250);
							// Show new card form
							paymentForm.hide().removeClass("paysafe-payment-form-hidden").fadeIn(300);
							saveCardCheckbox.fadeIn(300);
						} else {
							// Show saved cards list
							savedCardsList.hide().slideDown(250);
							// Hide new card form
							paymentForm.fadeOut(300, function() {
								$(this).addClass("paysafe-payment-form-hidden");
							});
							saveCardCheckbox.fadeOut(300);

							// Keep CVV naming in sync with the selected token
							syncSavedCardCVVName();
							if (!$("input[name=\"wc-paysafe-payment-token\"]:checked").length) {
								$("input[name=\"wc-paysafe-payment-token\"]").first().prop("checked", true);
							}
						}
					}

					// Initially hide the new card form since saved card is selected by default
					$(".paysafe-woocommerce-form").addClass("paysafe-payment-form-hidden");
					$(".paysafe-save-card-wc").hide();

					$("input[name=\"paysafe-card-selection\"]").on("change", togglePaysafeCardForm);
					$(document).on("change", "input[name=\"wc-paysafe-payment-token\"]", syncSavedCardCVVName);

					// Handle CVV input - only allow numbers
					$(document).on("input", ".paysafe-saved-card-cvv-input", function() {
						this.value = this.value.replace(/\s+/g, "").replace(/[^0-9]/g, "");
					});

					// Initial sync when the page loads
					syncSavedCardCVVName();
				});
				</script>';

			} else {
				// No saved cards, just add a hidden field and set new card as default
				echo '<input type="hidden" name="wc-paysafe-payment-token" value="new" />';
				echo '<input type="hidden" name="paysafe-card-selection" value="new" />';
			}
		} else {
			// Saved cards not enabled or user not logged in
			echo '<input type="hidden" name="wc-paysafe-payment-token" value="new" />';
		}
		if ( ! class_exists( 'Paysafe_Frontend' ) ) {
		require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-frontend.php';
	}

		// Render the payment form ONCE
		$frontend = new Paysafe_Frontend();

		 // Use order currency/amount on Pay page; store currency/amount otherwise.
		 $form_currency = get_woocommerce_currency();
		 $form_amount   = '';
		 if ( is_checkout_pay_page() ) {
			 $order_id = absint( get_query_var( 'order-pay' ) );
			 if ( $order_id ) {
			 $pay_order = wc_get_order( $order_id );
			 if ( $pay_order ) {
				 $form_currency = $pay_order->get_currency();
				 $form_amount   = $pay_order->get_total();
			 }
		 }
	 }
		 $atts = array(
		 'amount'      => $form_amount,
		 'currency'    => $form_currency,
			'show_amount' => false,
			'button_text' => __('Pay Now', 'paysafe-payment'),
			'form_class' => 'paysafe-woocommerce-form',
		);

		echo $frontend->render_payment_form($atts);

		// Add save card checkbox for new cards ONCE
		if ($this->enable_saved_cards && is_user_logged_in()) {
			// Determine if we should show or hide the checkbox initially
			$style_attr = '';
			if (!empty($valid_tokens)) {
				$style_attr = ' style="margin-top: 1em; display: none;"';
			} else {
				$style_attr = ' style="margin-top: 1em;"';
			}

			echo '<div class="paysafe-save-card-wc"' . $style_attr . '>';

			$id = 'wc-' . $this->id . '-new-payment-method';
			echo '<label for="' . esc_attr($id) . '" style="display: inline-block; margin: 0;">';
			echo '<input id="' . esc_attr($id) . '" name="wc-' . esc_attr($this->id) . '-new-payment-method" type="checkbox" value="true" style="margin-right: 0.5em;" checked />';
			echo esc_html__('Save this card in a secure vault for future purchases', 'paysafe-payment');
			echo '</label>';

			echo '<input type="hidden" id="paysafe_save_card" name="paysafe_save_card" value="1" />';
			echo '</div>';

		   ?>
		   <script type="text/javascript">
		   jQuery(document).ready(function($) {
			   // Sync checkboxes
			   $('#<?php echo esc_js($id); ?>').on('change', function() {
				   $('#paysafe_save_card').val($(this).is(':checked') ? '1' : '0');
			   });
		   });
		   </script>
		   <?php
	   }
	}

/**
 * Payment Scripts
 */
public function payment_scripts() {
	if ( ( ! is_checkout() && ! is_checkout_pay_page() && ! is_add_payment_method_page() ) || ! $this->is_available() ) {
		return;
	}

	// Determine merchant/store country
	$base = wc_get_base_location();
	$merchant_country = ! empty( $base['country'] ) ? strtoupper( $base['country'] ) : 'US';

	// Enqueue Paysafe.js - CRITICAL: Remove defer to ensure it loads before our script
	$ps_js_url = ( $this->environment === 'live' )
		? 'https://hosted.paysafe.com/js/v1/latest/paysafe.min.js'
		: 'https://hosted.test.paysafe.com/js/v1/latest/paysafe.min.js';

	if ( ! wp_script_is( 'paysafe-js', 'enqueued' ) ) {
		if ( ! wp_script_is( 'paysafe-js', 'registered' ) ) {
			wp_register_script( 'paysafe-js', $ps_js_url, array(), null, true );
		}
		wp_enqueue_script( 'paysafe-js' );
		// REMOVED: wp_script_add_data defer - causes race condition
	}

// Enqueue Decision Engine FIRST - Single source of truth for all payment flow decisions
// MUST load before payment-guard.js and payment-form.js
if ( ! wp_script_is( 'paysafe-decision-engine', 'registered' ) ) {
	wp_register_script(
		'paysafe-decision-engine',
		PAYSAFE_PLUGIN_URL . 'assets/js/paysafe-decision-engine.js',
		array( 'jquery' ), // Only depends on jQuery
		defined( 'PAYSAFE_VERSION' ) ? PAYSAFE_VERSION : null,
		true
	);
}
if ( ! wp_script_is( 'paysafe-decision-engine', 'enqueued' ) ) {
	wp_enqueue_script( 'paysafe-decision-engine' );
}

	// Enqueue core UI utilities (shared across all PCI modes) - MUST load before other scripts
	if ( ! wp_script_is( 'paysafe-core-ui', 'registered' ) ) {
		wp_register_script(
			'paysafe-core-ui',
			PAYSAFE_PLUGIN_URL . 'assets/js/paysafe-core-ui.js',
			array( 'jquery' ),
			defined( 'PAYSAFE_VERSION' ) ? PAYSAFE_VERSION : null,
			true
		);
	}
	if ( ! wp_script_is( 'paysafe-core-ui', 'enqueued' ) ) {
		wp_enqueue_script( 'paysafe-core-ui' );
	}
	// Ensure our main checkout JS exists
	if ( ! wp_script_is( 'paysafe-payment-form', 'registered' ) && ! wp_script_is( 'paysafe-payment-form', 'enqueued' ) ) {
		wp_register_script(
			'paysafe-payment-form',
			PAYSAFE_PLUGIN_URL . 'assets/js/payment-form.js',
			array( 'jquery', 'paysafe-js', 'paysafe-core-ui', 'paysafe-decision-engine' ), // Added decision engine
			defined( 'PAYSAFE_VERSION' ) ? PAYSAFE_VERSION : null,
			true
		);
	}
	if ( ! wp_script_is( 'paysafe-payment-form', 'enqueued' ) ) {
		wp_enqueue_script( 'paysafe-payment-form' );
	}

	// Submit-guard: keeps Place Order enabled; blocks submit only when fields aren’t ready/valid
	if ( ! wp_script_is( 'paysafe-payment-guard', 'registered' ) && ! wp_script_is( 'paysafe-payment-guard', 'enqueued' ) ) {
		wp_register_script(
			'paysafe-payment-guard',
			PAYSAFE_PLUGIN_URL . 'assets/js/payment-guard.js',
			array( 'jquery', 'wc-checkout', 'paysafe-core-ui', 'paysafe-payment-form', 'paysafe-decision-engine' ), // Added decision engine
			defined( 'PAYSAFE_VERSION' ) ? PAYSAFE_VERSION : null,
			true
		);
	}
	if ( ! wp_script_is( 'paysafe-payment-guard', 'enqueued' ) ) {
		wp_enqueue_script( 'paysafe-payment-guard' );
	}

	// Error interceptor: Routes WooCommerce errors to custom dark gradient error box
	if ( ! wp_script_is( 'paysafe-error-interceptor', 'registered' ) ) {
		wp_register_script(
			'paysafe-error-interceptor',
			PAYSAFE_PLUGIN_URL . 'assets/js/paysafe-error-interceptor.js',
			array( 'jquery', 'paysafe-core-ui', 'paysafe-payment-form' ),
			defined( 'PAYSAFE_VERSION' ) ? PAYSAFE_VERSION : null,
			true
		);
	}
	if ( ! wp_script_is( 'paysafe-error-interceptor', 'enqueued' ) ) {
		wp_enqueue_script( 'paysafe-error-interceptor' );
	}

		// SAQ-A-EP form validator (adds red rings + top error box; no conflict with hosted fields)
		if ( ! wp_script_is( 'paysafe-ep-validate', 'registered' ) ) {
			wp_register_script(
				'paysafe-ep-validate',
				PAYSAFE_PLUGIN_URL . 'assets/js/paysafe-ep-validate.js',
				array( 'jquery', 'wc-checkout', 'paysafe-core-ui', 'paysafe-payment-form' ),
				defined( 'PAYSAFE_VERSION' ) ? PAYSAFE_VERSION : null,
				true
			);
		}
		if ( ! wp_script_is( 'paysafe-ep-validate', 'enqueued' ) ) {
			wp_enqueue_script( 'paysafe-ep-validate' );
		}
		wp_localize_script( 'paysafe-ep-validate', 'paysafe_ep', array(
			'mode' => $this->pci_compliance_mode,
			'selectors' => array(
				'section'   => '.paysafe-woocommerce-form',
				'savedRadio'=> '#paysafe-use-saved-card',
				'newRadio'  => '#paysafe-use-new-card',
				'tokenField'=> 'input[name=\"paysafe_payment_token\"]',
				'number'    => '#card_number',
				'expiry'    => '#card_expiry',
				'cvv'       => '#card_cvv',
				'numberWrap'=> '#cardNumber_container',
				'expiryWrap'=> '#cardExpiry_container',
				'cvvWrap'   => '#cardCvv_container',
			),
			'i18n' => array(
				'header'         => __( 'Please fix the card fields below:', 'paysafe-payment' ),
				'invalid_number' => __( 'Please enter a valid card number', 'paysafe-payment' ),
				'invalid_expiry' => __( 'Please enter a valid expiration date (MM / YY)', 'paysafe-payment' ),
				'invalid_cvv'    => __( 'Please enter a valid security code', 'paysafe-payment' ),
			),
		) );

		// NOTE: Billing field validation is handled SERVER-SIDE by WooCommerce.
		// WooCommerce validates all required billing/shipping fields via AJAX BEFORE
		// calling our gateway's validate_fields() method. This is the correct WooCommerce
		// architecture - see woocommerce_after_checkout_validation hook.
		// Card validation (paysafe-ep-validate.js, payment-guard.js) handles card fields only.

	// Determine the working currency
	$currency = get_woocommerce_currency();
	if ( is_checkout_pay_page() ) {
		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( $order_id ) {
			$pay_order = wc_get_order( $order_id );
			if ( $pay_order ) {
				$currency = $pay_order->get_currency();
			}
		}
	}

	// Pass tokenization credentials
	wp_localize_script('paysafe-payment-form', 'paysafe_params', array(
		'ajax_action_tokenize' => 'paysafe_create_single_use_token',
		'ajax_url' => admin_url('admin-ajax.php'),
		'plugin_url' => PAYSAFE_PLUGIN_URL,
		'environment' => $this->environment,
		'merchant_id' => $this->merchant_id,
		'account_id' => $currency === 'USD' ? $this->cards_account_id_usd : $this->cards_account_id_cad,
		'accepted_cards' => $this->accepted_cards,
		'single_use_token_username' => $this->single_use_token_user,
		'single_use_token_password' => $this->single_use_token_password,
		'processing_text' => __('Processing...', 'paysafe-payment'),
		'error_text' => __('An error occurred. Please try again.', 'paysafe-payment'),
		'currency' => $currency,
		'pci_compliance_mode' => $this->pci_compliance_mode,
		'nonce' => wp_create_nonce('paysafe_payment_nonce'),
		'debug' => $this->enable_debug ? 1 : 0,
		'merchant_country' => $merchant_country,
		'apple_pay_enabled' => $this->enable_apple_pay ? 'yes' : 'no',
		'interac_apple_pay_mode' => $this->interac_apple_pay_mode,
		'card_number_placeholder' => __('Card number', 'paysafe-payment'),
		'card_expiry_placeholder' => __('Expiry date (MM / YY)', 'paysafe-payment'),
		'card_cvv_placeholder' => __('Security code', 'paysafe-payment'),
	));

	// Pass digital wallet settings
	wp_localize_script('paysafe-payment-form', 'paysafe_wallet_params', array(
		'enable_apple_pay' => $this->enable_apple_pay,
		'apple_pay_merchant_id' => $this->apple_pay_merchant_id,
		'apple_pay_merchant_name' => $this->apple_pay_merchant_name,
		'enable_google_pay' => $this->enable_google_pay,
		'google_pay_merchant_id' => $this->google_pay_merchant_id,
		'google_pay_merchant_name' => $this->google_pay_merchant_name,
		'google_pay_environment' => $this->google_pay_environment,
		'interac_google_pay_mode' => $this->interac_google_pay_mode,
	));

	wp_add_inline_script('paysafe-payment-form', '(function() {
		if (typeof jQuery === "undefined") { return; }
		jQuery(function($) {
			var initTimeout = null;
			var isInitializing = false;

			function safeInit() {
				if (isInitializing) return;

				if ($("input[name=\"payment_method\"]:checked").val() === "paysafe") {
					if (typeof window.initializePaysafeTokenization === "function") {
						isInitializing = true;
						try {
							window.initializePaysafeTokenization();
						} finally {
							isInitializing = false;
						}
					}
				}
			}

			// Debounced initialization
			function scheduleInit() {
				clearTimeout(initTimeout);
				initTimeout = setTimeout(safeInit, 300);
			}

			// Listen to WooCommerce events - use namespace to prevent duplicate handlers
			// Add Add-Payment-Method flow: wc-credit-card-form-init + init_add_payment_method
			$(document.body)
				.off(".paysafe_init")
				.on("init_checkout.paysafe_init updated_checkout.paysafe_init payment_method_selected.paysafe_init wc-credit-card-form-init.paysafe_init init_add_payment_method.paysafe_init", scheduleInit);

			// Initial check on page load
			scheduleInit();
		});
	})();
	');
}

	/**
	 * Support WooCommerce "Add payment method" flow by consuming a freshly created token
	 * and saving it to the customer's vault. Requires support flag 'add_payment_method'.
	 *
	 * @return array|bool
	 */
	public function add_payment_method() {
		if ( ! is_user_logged_in() ) {
			wc_add_notice( __( 'You must be logged in to add a payment method.', 'paysafe-payment' ), 'error' );
			return false;
		}

		$payment_token = isset( $_POST['paysafe_payment_token'] ) ? wc_clean( wp_unslash( $_POST['paysafe_payment_token'] ) ) : '';
		$card_type     = isset( $_POST['paysafe_card_type'] )      ? wc_clean( wp_unslash( $_POST['paysafe_card_type'] ) )      : '';
		$card_last4    = isset( $_POST['paysafe_card_last4'] )     ? wc_clean( wp_unslash( $_POST['paysafe_card_last4'] ) )     : '';

		if ( empty( $payment_token ) ) {
			wc_add_notice( __( 'Card tokenization failed. Please try again.', 'paysafe-payment' ), 'error' );
			return false;
		}

		$dummy_order = wc_create_order(); // lightweight container for billing details if needed later
		$ok = $this->save_card_from_token( $dummy_order, $payment_token, $card_type, $card_last4 );

		// Clean up temp order if created
		if ( $dummy_order && is_a( $dummy_order, 'WC_Order' ) ) {
			$dummy_order->delete( true );
		}

		if ( ! $ok ) {
			wc_add_notice( __( 'Unable to save card. Please try again.', 'paysafe-payment' ), 'error' );
			return false;
		}

		wc_add_notice( __( 'Card added successfully.', 'paysafe-payment' ), 'success' );
		return array(
			'result'   => 'success',
			'redirect' => wc_get_account_endpoint_url( 'payment-methods' ),
		);
	}

/**
* Process the payment - with tokenization support
*/
public function process_payment($order_id) {
   $order = wc_get_order($order_id);

   // Log the start of payment processing
   if ($this->enable_debug) {
	   $this->log('Starting payment processing for order ' . $order_id);
   }

   // Check the card selection first
   $card_selection = isset($_POST['paysafe-card-selection']) ? wc_clean( wp_unslash( $_POST['paysafe-card-selection'] ) ) : 'new';

   // If using saved card selection, get the token
   if ($card_selection === 'saved') {
	   $token_id = isset($_POST['wc-paysafe-payment-token']) ? wc_clean( wp_unslash( $_POST['wc-paysafe-payment-token'] ) ) : '';

	   if (!empty($token_id) && is_numeric($token_id)) {
		   // Process payment with saved card
		   return $this->process_payment_with_saved_card($order, $token_id);
		}
   }

   // CHECK FOR TOKENIZED PAYMENT FIRST (PCI Compliant path)
   $payment_token = isset($_POST['paysafe_payment_token']) ? wc_clean( wp_unslash( $_POST['paysafe_payment_token'] ) ) : '';

   if (!empty($payment_token)) {
	   // Process with tokenized payment (PCI compliant)
	   if ($this->enable_debug) {
		   $this->log('Processing with Paysafe token (PCI compliant)');
	   }
	   return $this->process_tokenized_payment($order, $payment_token);
   }

   // If we get here, no payment method was provided (no token reached the server).
   // Always surface a visible error so checkout doesn't fail silently.
   if ( $this->pci_compliance_mode === 'saq_a_only' ) {
	   wc_add_notice( __( 'Secure card fields failed to load. Please refresh the page and try again.', 'paysafe-payment' ), 'error' );
   } else {
	   wc_add_notice( __( 'Card details were not captured. Please try again and ensure the card fields are visible.', 'paysafe-payment' ), 'error' );
   }

	if ($this->enable_debug) {
		$this->log('No payment token or card data provided', 'error');
	}

	return false;
}

   /**
	* Process payment with saved card
	*/
   protected function process_payment_with_saved_card($order, $token_id) {
	   // Fixed: Check if tokenization handler exists before using it
	   if (!$this->tokenization_handler) {
		   if ($this->enable_debug) {
			   $this->log('Tokenization handler not initialized', 'error');
		   }
		   wc_add_notice(__('Saved cards are not available. Please use a new card.', 'paysafe-payment'), 'error');
		   return false;
	   }

	   if ($this->enable_debug) {
		   $this->log('Processing payment with saved card for order ' . $order->get_id());
	   }

	   // Get the token
	   $token = WC_Payment_Tokens::get($token_id);

	   if (!$token || $token->get_user_id() !== get_current_user_id()) {
		   wc_add_notice(__('Invalid payment token.', 'paysafe-payment'), 'error');
		   return false;
	   }

	   // Extra safety: ensure this token belongs to this gateway
	   if ( method_exists( $token, 'get_gateway_id' ) && $token->get_gateway_id() !== $this->id ) {
		   wc_add_notice(__('Selected card does not belong to this payment method.', 'paysafe-payment'), 'error');
		   return false;
	   }

	   // Get CVV if required
	   $cvv = '';
	   if ($this->require_cvv_with_token) {
		   $cvv = isset($_POST['paysafe_saved_card_cvv']) ? wc_clean( wp_unslash( $_POST['paysafe_saved_card_cvv'] ) ) : '';
		   $cvv = trim( (string) $cvv );
		   if ($cvv === '') {
			   wc_add_notice(__('Please enter your card security code (CVV).', 'paysafe-payment'), 'error');
			   return false;
		   }

		// Defensive recheck (validate_fields() already enforces this)
		   if ( ! preg_match( '/^[0-9]{3,4}$/' , $cvv ) ) {
			   wc_add_notice(__('Invalid CVV format. Please enter 3 or 4 digits.', 'paysafe-payment'), 'error');
			   return false;
		   }
	   }

	   try {
		   // Get payment token from the saved token
		   $payment_token = '';
		   if (method_exists($token, 'get_paysafe_payment_token')) {
			   $payment_token = $token->get_paysafe_payment_token();
		   } else {
			   $payment_token = $token->get_token();
		   }

		   // Prepare payment data
		   $payment_data = array(
			   'payment_token' => $payment_token,
			   'cvv' => $cvv,
			   'card_type' => method_exists($token, 'get_card_type') ? $token->get_card_type() : '',
			   'last4' => method_exists($token, 'get_last4') ? $token->get_last4() : '',
			   // Ensure saved-card flow honors gateway setting (Sale vs Authorization Only)
			   'settleWithAuth' => ( $this->authorization_type === 'sale' ),
		   );

		   // Process payment through API using centralized settings
		   $api = new Paysafe_API($this->get_api_settings(), $this);
		   $result = $api->process_payment_with_token($order, $payment_data);

		   if (isset($result['result']) && $result['result'] === 'success') {
			   // Payment successful
			   $order->payment_complete($result['transaction_id']);

			   // Add order note
			   $card_type = method_exists($token, 'get_card_type') ? $token->get_card_type() : 'card';
			   $last4 = method_exists($token, 'get_last4') ? $token->get_last4() : '****';

			   $card_display_map = array(
				   'visa' => 'Visa',
				   'mastercard' => 'Mastercard',
				   'amex' => 'American Express',
				   'discover' => 'Discover',
				   'jcb' => 'JCB',
				   'diners' => 'Diners Club',
				   'interac' => 'Interac',
			   );

			   $card_name = isset($card_display_map[$card_type]) ? $card_display_map[$card_type] : ucfirst($card_type);

			   $order->add_order_note(sprintf(
				   __('Paysafe payment completed with saved card. Transaction ID: %s. Card: %s ending in %s', 'paysafe-payment'),
				   $result['transaction_id'],
				   $card_name,
				   $last4
			   ));

			   // Store transaction data - WITHOUT set_payment_method_title
			   $order->update_meta_data('_paysafe_transaction_id', $result['transaction_id']);
			   $order->update_meta_data('_paysafe_card_type', $card_type);
			   $order->update_meta_data('_paysafe_card_suffix', $last4);
			   if (isset($result['auth_code'])) {
				   $order->update_meta_data('_paysafe_auth_code', $result['auth_code']);
			   }
			   $order->save();

			   // Return success
			   return array(
				   'result' => 'success',
				   'redirect' => $this->get_return_url($order)
			   );
		   } else {
			   // Payment failed
			   $error_message = isset($result['message']) ? $result['message'] : __('Payment failed. Please try again.', 'paysafe-payment');
			   wc_add_notice($error_message, 'error');

			   if ($this->enable_debug) {
				   $this->log('Payment with saved card failed: ' . $error_message, 'error');
			   }

			   return false;
		   }
	   } catch (Exception $e) {
	   $error_message = $e->getMessage();
	   
	   // Parse error code format: "ERROR_CODE|Default message"
	   if (strpos($error_message, '|') !== false) {
		   list($error_code, $default_msg) = explode('|', $error_message, 2);
		   $error_code = trim($error_code);
		   $default_msg = trim($default_msg);
		   
		   // Try to get custom error message
		   $custom_msg = $this->get_custom_error_message($error_code, '');
		   if (!empty($custom_msg)) {
			   $error_message = $custom_msg;
		   } else {
			   $error_message = $default_msg;
		   }
		   
		   // Allow safe HTML tags in error messages
		   $error_message = wp_kses($error_message, array(
			   'strong' => array(),
			   'b'      => array(),
			   'em'     => array(),
			   'i'      => array(),
			   'br'     => array(),
		   ));
	   }
	   
	   wc_add_notice($error_message, 'error');

		   if ($this->enable_debug) {
		   $this->log('Payment with saved card error: ' . $error_message, 'error');
		   }

		   return false;
	   }
   }

/**
* Process tokenized payment
*/
protected function process_tokenized_payment($order, $payment_token) {
   if ($this->enable_debug) {
	   $this->log('Processing tokenized payment for order ' . $order->get_id());
   }

   try {
	   // Get additional data
	   $card_type  = isset($_POST['paysafe_card_type'])  ? wc_clean( wp_unslash( $_POST['paysafe_card_type'] ) )   : '';
	   $card_last4 = isset($_POST['paysafe_card_last4']) ? wc_clean( wp_unslash( $_POST['paysafe_card_last4'] ) ) : '';

	   /**
		* IMPORTANT:
		* If the buyer chose "save card", we must convert the single-use token into a
		* permanent vault token *before* charging. Re-using the same single-use token
		* after an auth will fail and nothing gets saved.
		 * 
		 * EXCEPTION: If the card already exists (duplicate), save_card_from_token returns NULL
		 * and we continue with the original single-use token for the payment.
		*/
	   $token_for_charge = $payment_token; // default: use the one we already have
	   if ( $this->should_save_card() ) {
		   $maybe_perm = $this->save_card_from_token( $order, $payment_token, $card_type, $card_last4 );
			// save_card_from_token returns the permanent payment token string on success,
			// or NULL if the card already exists (duplicate - no need to save again)
		   if ( is_string( $maybe_perm ) && $maybe_perm !== '' ) {
			   $token_for_charge = $maybe_perm;
			} elseif ( $maybe_perm === null ) {
				// Duplicate detected - use original single-use token, don't save again
				if ( $this->enable_debug ) {
					$this->log('Card already exists in vault; proceeding to charge with original single-use token.', 'info');
				}
				// $token_for_charge stays as $payment_token (the single-use token)
		   } else {
			   if ( $this->enable_debug ) {
				   $this->log('Requested to save card, but vault write failed; proceeding to charge with provided token.', 'warning');
			   }
		   }
	   }

	   // Get currency-specific account ID
	   $currency = $order->get_currency();
	   $account_id = ($currency === 'USD') ? $this->cards_account_id_usd : $this->cards_account_id_cad;

	   if (empty($account_id)) {
		   throw new Exception(sprintf(__('Payment processing not available for %s currency.', 'paysafe-payment'), $currency));
	   }

	   // Prepare API request with token
	   $api = new Paysafe_API($this->get_api_settings(), $this);

	   $payment_data = array(
		   'merchantRefNum' => 'order_' . $order->get_id() . '_' . time(),
		   'amount' => (int) round( (float) $order->get_total() * 100 ),
		   'settleWithAuth' => ($this->authorization_type === 'sale'),
		   'card' => array(
			   // Cards API expects `paymentToken` for tokenized charges.
			   'paymentToken' => $token_for_charge,
		   ),
		   'billingDetails' => array(
			   'street' => $order->get_billing_address_1(),
			   'city' => $order->get_billing_city(),
			   'state' => $this->format_state_code( $order->get_billing_state(), $order->get_billing_country() ),
			   'country' => $order->get_billing_country(),
			   'zip' => str_replace(' ', '', $order->get_billing_postcode())
		   )
	   );

	   // Add 3D Secure if enabled
	   if ($this->enable_3ds_v2) {
		   $payment_data['authentication'] = array(
			   'threeDSecure' => array(
				   'deviceChannel' => 'BROWSER',
				   'challengeIndicator' => $this->three_ds_challenge_indicator,
				   'exemptionIndicator' => $this->three_ds_exemption_indicator,
				   'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
				   'acceptHeader' => isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '',
				   'customerIp' => WC_Geolocation::get_ip_address()
			   )
		   );
	   }

// Process the payment
		$endpoint = '/cardpayments/v1/accounts/' . $account_id . '/auths';
		$response = $api->make_request($endpoint, 'POST', $payment_data);

// CRITICAL: Check for AVS/CVV errors FIRST (HTTP 402 with error code + auth ID)
	if (isset($response['error']['code'])) {
		$error_code = $response['error']['code'];
		$auth_id = isset($response['id']) ? $response['id'] : null;
		
		// Errors that create auth requiring void (3007=AVS, 3009=Declined, 3022=NSF, 5014=CVV)
if (in_array($error_code, array('3007', '3009', '3022', '5014'))) {
	if ($this->enable_debug) {
		$this->log('Error ' . $error_code . ' created auth requiring void - Auth ID: ' . $auth_id . ' - Voiding authorization', 'warning');
	}
	
	if ($auth_id) {
		try {
			$void_endpoint = '/cardpayments/v1/accounts/' . $account_id . '/auths/' . $auth_id . '/voidauths';
			$void_data = array('merchantRefNum' => 'void_' . $error_code . '_' . $order->get_id() . '_' . time());
			$api->make_request($void_endpoint, 'POST', $void_data);
			
			if ($this->enable_debug) {
				$this->log('Successfully voided auth ' . $auth_id . ' due to error ' . $error_code, 'info');
			}
			
			$order->add_order_note(sprintf(__('Authorization %s voided due to error %s. Hold will be released by bank.', 'paysafe-payment'), $auth_id, $error_code));
		} catch (Exception $void_error) {
			// Error 3500 means auth was declined and can't be voided
			// The hold will auto-release in 3-5 days per Paysafe
			$void_error_msg = $void_error->getMessage();
			$is_already_declined = (strpos($void_error_msg, '3500') !== false || strpos($void_error_msg, 'confirmation number') !== false);
			
			if ($is_already_declined) {
				if ($this->enable_debug) {
					$this->log('Auth ' . $auth_id . ' is declined (error ' . $error_code . '), cannot be voided. Hold will auto-release in 3-5 days.', 'info');
				}
				$order->add_order_note(sprintf(__('Authorization %s declined (error %s). Hold will auto-release by bank in 3-5 business days.', 'paysafe-payment'), $auth_id, $error_code));
			} else {
				// Unexpected void failure
				if ($this->enable_debug) {
					$this->log('CRITICAL: Failed to void auth ' . $auth_id . ': ' . $void_error_msg, 'error');
				}
				$order->add_order_note(sprintf(__('CRITICAL: Auth %s created but declined (error %s). Void failed: %s. MANUAL VOID REQUIRED.', 'paysafe-payment'), $auth_id, $error_code, $void_error_msg));
			}
			$order->save();
		}
	}
	
	// Show appropriate error message based on error type
	if ($error_code === '3007') {
		// AVS failure
		$error_msg = $this->get_custom_error_message($error_code, 
			__('Address verification failed. The billing address doesn\'t match your card. A temporary hold was placed but will be released within 3-5 business days.', 'paysafe-payment'));
	} elseif ($error_code === '3022') {
		// Insufficient funds
		$error_msg = $this->get_custom_error_message($error_code, 
			__('The card has insufficient funds. A temporary hold was placed but will be released within 3-5 business days.', 'paysafe-payment'));
	} elseif ($error_code === '3009') {
		// Generic issuer decline
		$error_msg = $this->get_custom_error_message($error_code, 
			__('Your card was declined by the issuing bank. Please check your card details or contact your bank. A temporary hold was placed but will be released within 3-5 business days.', 'paysafe-payment'));
	} else {
		// CVV or other
		$error_msg = $this->get_custom_error_message($error_code, 
			__('Transaction declined. A temporary hold was placed but will be released within 3-5 business days.', 'paysafe-payment'));
	}
	
	// Allow safe HTML tags in error messages for frontend display
	$error_msg = wp_kses($error_msg, array('strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'br' => array()));
	throw new Exception($error_msg);
}
		
		// CVV failure (3023, 5015)
		if (in_array($error_code, array('3023', '5015'))) {
			if ($this->enable_debug) {
				$this->log('CVV error ' . $error_code . ' - Auth ID: ' . $auth_id . ' - Voiding authorization', 'warning');
			}
			
			if ($auth_id) {
				try {
					$void_endpoint = '/cardpayments/v1/accounts/' . $account_id . '/auths/' . $auth_id . '/voidauths';
					$void_data = array('merchantRefNum' => 'void_cvv_' . $order->get_id() . '_' . time());
					$api->make_request($void_endpoint, 'POST', $void_data);
					
					if ($this->enable_debug) {
						$this->log('Successfully voided auth ' . $auth_id . ' due to CVV failure', 'info');
					}
					
					$order->add_order_note(sprintf(__('Authorization %s voided due to CVV failure (code: %s). Hold will be released by bank.', 'paysafe-payment'), $auth_id, $error_code));
				} catch (Exception $void_error) {
					if ($this->enable_debug) {
						$this->log('CRITICAL: Failed to void auth ' . $auth_id . ': ' . $void_error->getMessage(), 'error');
					}
					$order->add_order_note(sprintf(__('CRITICAL: Auth %s created but CVV failed (code: %s). Void failed: %s. MANUAL VOID REQUIRED.', 'paysafe-payment'), $auth_id, $error_code, $void_error->getMessage()));
					$order->save();
				}
			}
			
			$cvv_error = $this->get_custom_error_message($error_code,
			__('CVV verification failed. The security code is incorrect. A temporary hold was placed but will be released within 3-5 business days.', 'paysafe-payment'));
		// Allow safe HTML tags in error messages for frontend display
		$cvv_error = wp_kses($cvv_error, array('strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'br' => array()));
		throw new Exception($cvv_error);
		}
		
		// Risk Management decline (4002) - Check if AVS-related
		if ($error_code === '4002' && $auth_id) {
			$is_avs_related = false;
			$risk_message = '';
			
			// Check additionalDetails for AVS indicators
			if (isset($response['error']['additionalDetails']) && is_array($response['error']['additionalDetails'])) {
				foreach ($response['error']['additionalDetails'] as $detail) {
					if (isset($detail['message'])) {
						$msg = strtolower($detail['message']);
						// Look for AVS-related keywords in risk response
						if (strpos($msg, 'address') !== false || 
							strpos($msg, 'name') !== false || 
							strpos($msg, 'first name') !== false ||
							strpos($msg, 'last name') !== false ||
							strpos($msg, 'billing') !== false ||
							strpos($msg, 'avs') !== false) {
							$is_avs_related = true;
							$risk_message = $detail['message'];
							break;
						}
					}
				}
			}
			
			if ($is_avs_related) {
				if ($this->enable_debug) {
					$this->log('Risk Management decline (4002) is AVS-related: ' . $risk_message . ' - Auth ID: ' . $auth_id . ' - Voiding authorization', 'warning');
				}
				
				// Void the authorization to release the hold
				try {
					$void_endpoint = '/cardpayments/v1/accounts/' . $account_id . '/auths/' . $auth_id . '/voidauths';
					$void_data = array('merchantRefNum' => 'void_risk_avs_' . $order->get_id() . '_' . time());
					$api->make_request($void_endpoint, 'POST', $void_data);
					
					if ($this->enable_debug) {
						$this->log('Successfully voided auth ' . $auth_id . ' due to Risk Management AVS failure', 'info');
					}
					
					$order->add_order_note(sprintf(__('Authorization %s voided due to Risk Management AVS failure (code: 4002, reason: %s). Hold will be released by bank.', 'paysafe-payment'), $auth_id, $risk_message));
				} catch (Exception $void_error) {
					if ($this->enable_debug) {
						$this->log('CRITICAL: Failed to void auth ' . $auth_id . ': ' . $void_error->getMessage(), 'error');
					}
					$order->add_order_note(sprintf(__('CRITICAL: Auth %s created but Risk Management declined due to AVS (code: 4002). Void failed: %s. MANUAL VOID REQUIRED.', 'paysafe-payment'), $auth_id, $void_error->getMessage()));
					$order->save();
				}
				
			// Show custom AVS error message
			$avs_error = $this->get_custom_error_message('AVS_FAILED', 
				__('Address verification failed. The billing address doesn\'t match your card. A temporary hold was placed but will be released within 3-5 business days.', 'paysafe-payment'));
			// Allow safe HTML tags in error messages for frontend display
			$avs_error = wp_kses($avs_error, array('strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'br' => array()));
			throw new Exception($avs_error);
			}
		}
	}

	if (isset($response['status']) && $response['status'] === 'COMPLETED') {
		   // Capture auth ID immediately - needed if we must void due to AVS/CVV failure
		   $auth_id = isset($response['id']) ? $response['id'] : null;
		   
// Check AVS response even on completed auths
			if (isset($response['avsResponse'])) {
				$avs = strtoupper($response['avsResponse']);
				$avs_fail_codes = array('N', 'A', 'Z', 'W', 'C', 'I', 'P');
				if (in_array($avs, $avs_fail_codes)) {
					if ($this->enable_debug) {
						$this->log('AVS Check Failed: ' . $avs . ' - Transaction was APPROVED but voiding due to address mismatch', 'warning');
					}
					
					if ($auth_id) {
						try {
							$void_endpoint = '/cardpayments/v1/accounts/' . $account_id . '/auths/' . $auth_id . '/voidauths';
							$void_data = array(
								'merchantRefNum' => 'void_avs_' . $order->get_id() . '_' . time()
							);
							$api->make_request($void_endpoint, 'POST', $void_data);
							
							if ($this->enable_debug) {
								$this->log('Successfully voided authorization ' . $auth_id . ' due to AVS failure', 'info');
							}
							
							$order->add_order_note(
								sprintf(
									__('Payment authorization %s was voided due to address verification failure (AVS: %s). The hold on the customer\'s card will be released by their bank.', 'paysafe-payment'),
									$auth_id,
									$avs
								)
							);
							
						} catch (Exception $void_error) {
							if ($this->enable_debug) {
								$this->log('CRITICAL: Failed to void authorization ' . $auth_id . ' after AVS failure: ' . $void_error->getMessage(), 'error');
							}
							$order->add_order_note(
								sprintf(
									__('CRITICAL: Payment was approved (Auth ID: %s) but AVS failed. Attempted to void but void failed: %s. Manual action required.', 'paysafe-payment'),
									$auth_id,
									$void_error->getMessage()
								)
							);
							$order->save();
							
						$avs_error = $this->get_custom_error_message('AVS_FAILED', 
							__('Your payment was approved but address verification failed. Our system attempted to void the authorization but encountered an error. Please contact us immediately.', 'paysafe-payment'));
						// Allow safe HTML tags in error messages for frontend display
						$avs_error = wp_kses($avs_error, array('strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'br' => array()));
						throw new Exception($avs_error);
						}
					}
					
				$avs_error = $this->get_custom_error_message('AVS_FAILED', 
					__('Address verification failed. The billing address doesn\'t match your card\'s registered address. A temporary hold was placed on your card but will be automatically released by your bank within 1-3 business days.', 'paysafe-payment'));
				// Allow safe HTML tags in error messages for frontend display
				$avs_error = wp_kses($avs_error, array('strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'br' => array()));
				throw new Exception($avs_error);
				}
			}

// Check CVV response even on completed auths
			if (isset($response['cvvVerification'])) {
				$cvv = strtoupper($response['cvvVerification']);
				$cvv_fail_codes = array('N', 'P', 'S', 'U');
				if (in_array($cvv, $cvv_fail_codes)) {
					if ($this->enable_debug) {
						$this->log('CVV Check Failed: ' . $cvv . ' - Transaction was APPROVED but voiding due to CVV mismatch', 'warning');
					}

					if ($auth_id) {
						try {
							$void_endpoint = '/cardpayments/v1/accounts/' . $account_id . '/auths/' . $auth_id . '/voidauths';
							$void_data = array(
								'merchantRefNum' => 'void_cvv_' . $order->get_id() . '_' . time()
							);
							$api->make_request($void_endpoint, 'POST', $void_data);
							
							if ($this->enable_debug) {
								$this->log('Successfully voided authorization ' . $auth_id . ' due to CVV failure', 'info');
							}
							
							$order->add_order_note(
								sprintf(
									__('Payment authorization %s was voided due to CVV verification failure (CVV: %s). The hold on the customer\'s card will be released by their bank.', 'paysafe-payment'),
									$auth_id,
									$cvv
								)
							);
							
						} catch (Exception $void_error) {
							if ($this->enable_debug) {
								$this->log('CRITICAL: Failed to void authorization ' . $auth_id . ' after CVV failure: ' . $void_error->getMessage(), 'error');
							}
							$order->add_order_note(
								sprintf(
									__('CRITICAL: Payment was approved (Auth ID: %s) but CVV failed. Attempted to void but void failed: %s. Manual action required.', 'paysafe-payment'),
									$auth_id,
									$void_error->getMessage()
								)
							);
							$order->save();
							
						$cvv_error = $this->get_custom_error_message('CVV_FAILED',
							__('Your payment was approved but CVV verification failed. Our system attempted to void the authorization but encountered an error. Please contact us immediately.', 'paysafe-payment'));
						// Allow safe HTML tags in error messages for frontend display
						$cvv_error = wp_kses($cvv_error, array('strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'br' => array()));
						throw new Exception($cvv_error);
						}
					}

					$cvv_error = $this->get_custom_error_message('CVV_FAILED',
					__('Security code verification failed. The CVV/CVC on your card is incorrect. A temporary hold was placed on your card but will be automatically released by your bank within 1-3 business days.', 'paysafe-payment'));
				// Allow safe HTML tags in error messages for frontend display
				$cvv_error = wp_kses($cvv_error, array('strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'br' => array()));
				throw new Exception($cvv_error);
				}
			}

		   // Payment successful and passed all verifications
		   $order->payment_complete($response['id']);

		   // Add order note
		   $card_display = $this->get_card_display_name($card_type);
		   $order->add_order_note(sprintf(
			   __('Paysafe payment completed (tokenized). Transaction ID: %s. Card: %s ending in %s', 'paysafe-payment'),
			   $response['id'],
			   $card_display,
			   $card_last4
		   ));

		   // Store transaction meta
		   $order->update_meta_data('_paysafe_transaction_id', $response['id']);
		   $order->update_meta_data('_paysafe_card_type', $card_type);
		   $order->update_meta_data('_paysafe_card_suffix', $card_last4);
		   if (isset($response['authCode'])) {
			   $order->update_meta_data('_paysafe_auth_code', $response['authCode']);
		   }
		   $order->save();

		   return array(
			   'result' => 'success',
			   'redirect' => $this->get_return_url($order)
		   );
	   }

	   // Normalize Paysafe error into gateway custom error buckets (AVS/CVV/etc.).
	   $error_code  = isset($response['error']['code']) ? (string) $response['error']['code'] : '';
	   $raw_message = isset($response['error']['message']) ? $response['error']['message'] : __('Payment failed', 'paysafe-payment');
	   $msg         = $raw_message;

	   if ($error_code !== '') {
		   $custom_msg = $this->get_custom_error_message($error_code, '');
		   if (!empty($custom_msg)) {
			   $msg = $custom_msg;
		   }
	   }

		// If we still have the raw Paysafe AVS text, normalize it to AVS_FAILED.
		if ($msg === $raw_message && stripos($raw_message, 'failed the avs check') !== false) {
			$avs_msg = $this->get_custom_error_message('AVS_FAILED', '');
			if (!empty($avs_msg)) {
				$msg = $avs_msg;
			} else {
				// Fallback: use generic message instead of raw Paysafe text
				$msg = 'Address Verification Failed';
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

	   throw new Exception($msg);

} catch (Exception $e) {
	$error_message = $e->getMessage();
	
	// Parse error code format: "ERROR_CODE|Default message"
	if (strpos($error_message, '|') !== false) {
		list($error_code, $default_msg) = explode('|', $error_message, 2);
		$error_code = trim($error_code);
		$default_msg = trim($default_msg);
		
		// Try to get custom error message from admin settings
		$custom_msg = $this->get_custom_error_message($error_code, '');
		if (!empty($custom_msg)) {
			$error_message = $custom_msg;  // Use admin's custom message
		} else {
			$error_message = $default_msg;  // Fall back to default
		}
		
		// Allow safe HTML tags in custom messages
		$error_message = wp_kses($error_message, array(
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
		));
	}
	
	wc_add_notice($error_message, 'error');
	
	if ($this->enable_debug) {
		$this->log('Payment error: ' . $error_message, 'error');
	}
	
	return false;
}
}

/**
* Save card from payment token
*
* @return string|false Permanent Paysafe payment token on success, false on failure.
*/
protected function save_card_from_token($order, $payment_token, $card_type, $last4) {
   // Respect gateway setting.
   if ( ! $this->enable_saved_cards ) {
	   if ( $this->enable_debug ) {
		   $this->log( 'save_card_from_token: Saved cards not enabled', 'info' );
	   }
	   return false;
   }

   // We'll try to persist immediately if we have a WP user; otherwise we'll
   // still convert to a permanent Paysafe token and defer attaching it to the user.
   $order_user_id = ( $order && is_a( $order, 'WC_Order' ) ) ? (int) $order->get_user_id() : 0;

   if ( $this->enable_debug ) {
	   $this->log( sprintf( 'save_card_from_token: Starting. Order ID: %s, User ID: %s, Token: %s', 
		   $order ? $order->get_id() : 'none',
		   $order_user_id,
		   substr( $payment_token, 0, 10 ) . '...'
	   ), 'info' );
   }

   try {
	   // Prefer the order's customer to avoid session/user mismatches.
	   $user_id = $order_user_id ? $order_user_id : get_current_user_id();

	   // Get or create customer profile
	   $profile_id = '';
	   if ( $user_id ) {
		   $profile_id = get_user_meta( $user_id, '_paysafe_customer_profile_id', true );
		   if ( $this->enable_debug ) {
			   $this->log( sprintf( 'save_card_from_token: Found profile_id: %s for user %d', 
				   $profile_id ? $profile_id : 'none',
				   $user_id
			   ), 'info' );
		   }
	   }

	   if (!$profile_id) {
		// Create new customer profile
		// Fallbacks ensure "Add payment method" (no order) still works reliably.
		$user  = $user_id ? get_userdata( $user_id ) : null;
		$first = ( $order && method_exists( $order, 'get_billing_first_name' ) && $order->get_billing_first_name() )
				   ? $order->get_billing_first_name()
				   : get_user_meta( $user_id, 'billing_first_name', true );
		$last  = ( $order && method_exists( $order, 'get_billing_last_name' ) && $order->get_billing_last_name() )
				   ? $order->get_billing_last_name()
				   : get_user_meta( $user_id, 'billing_last_name', true );
		$email = ( $order && method_exists( $order, 'get_billing_email' ) && $order->get_billing_email() )
				   ? $order->get_billing_email()
				   : ( $user ? $user->user_email : '' );
		$phone = ( $order && method_exists( $order, 'get_billing_phone' ) && $order->get_billing_phone() )
				   ? $order->get_billing_phone()
				   : get_user_meta( $user_id, 'billing_phone', true );

		// Build a stable profile id even when the WP user doesn't exist yet.
		$profile_stub  = $user_id ? (string) $user_id : ( $order && $order->get_id() ? 'guest-' . $order->get_id() : 'guest-' . wp_generate_password(6,false,false) );
		$customer_data = array(
			'customer_id' => $this->vault_prefix . $profile_stub,
			'first_name'  => $first ?: 'Customer',
			'last_name'   => $last ?: '',
			'email'       => $email,
			'phone'       => $phone,
		);

		   $api = new Paysafe_API($this->get_api_settings(), $this);
		   $profile_result = $api->create_customer_profile($customer_data);

		   if ($profile_result['success'] && isset($profile_result['profile_id'])) {
			   $profile_id = $profile_result['profile_id'];
			   update_user_meta($user_id, '_paysafe_customer_profile_id', $profile_id);
			   if ( $this->enable_debug ) {
				   $this->log( sprintf( 'save_card_from_token: Created profile_id: %s', $profile_id ), 'info' );
			   }
		   } else {
			   if ( $this->enable_debug ) {
				   $this->log( 'save_card_from_token: Failed to create customer profile', 'error' );
			   }
			   throw new Exception('Failed to create customer profile');
		   }
	   }

if ($profile_id) {
			if ( $this->enable_debug ) {
				$this->log( sprintf( 'save_card_from_token: Checking for duplicate card for profile: %s', $profile_id ), 'info' );
			}
			
			// PROACTIVE DUPLICATE CHECK
			$check_exp_month = 0;
			$check_exp_year = 0;
			
			if (isset($_POST['expiry_month']) && isset($_POST['expiry_year'])) {
				$check_exp_month = (int) wc_clean( wp_unslash( $_POST['expiry_month'] ) );
				$check_exp_year = (int) wc_clean( wp_unslash( $_POST['expiry_year'] ) );
				if ($check_exp_year < 100) {
					$check_exp_year = ($check_exp_year >= 70) ? (1900 + $check_exp_year) : (2000 + $check_exp_year);
				}
			}
			
			if ((!$check_exp_month || !$check_exp_year) && isset($_POST['card_expiry'])) {
				$raw_exp = wp_unslash( $_POST['card_expiry'] );
				if (is_string($raw_exp)) {
					$s = preg_replace('/\s+/', '', $raw_exp);
					if (preg_match('#^(\d{1,2})/(\d{2})$#', $s, $m)) {
						$check_exp_month = (int)$m[1];
						$check_exp_year = (int)$m[2];
						$check_exp_year = ($check_exp_year >= 70) ? (1900 + $check_exp_year) : (2000 + $check_exp_year);
					}
				}
			}
			
			if ($last4 && $check_exp_month && $check_exp_year) {
				$api = new Paysafe_API($this->get_api_settings(), $this);
				$duplicate_check = $api->check_duplicate_card($profile_id, $last4, $check_exp_month, $check_exp_year);
				
				if ($duplicate_check !== false) {
					if ($this->enable_debug) {
						$this->log('save_card_from_token: DUPLICATE DETECTED - Card already exists in vault', 'info');
						$this->log('save_card_from_token: Existing card_id: ' . $duplicate_check['card_id'], 'info');
					}
					
					if (function_exists('wc_add_notice')) {
						wc_add_notice(__('This card is already saved to your account.', 'paysafe-payment'), 'notice');
					}
					
					if ($user_id) {
						$existing_tokens = WC_Payment_Tokens::get_customer_tokens($user_id, $this->id);
						
						foreach ($existing_tokens as $existing_token) {
							if ($existing_token instanceof WC_Payment_Token_Paysafe) {
								if ($existing_token->get_paysafe_card_id() === $duplicate_check['card_id']) {
									if ($this->enable_debug) {
										$this->log('save_card_from_token: Found matching WC token, returning NULL to use original single-use token for payment', 'info');
									}
									return null;
								}
							}
						}
						
						// EDGE CASE: Card exists in vault but no WC token
						if ($this->enable_debug) {
							$this->log('save_card_from_token: Duplicate in vault but no WC token - creating WC token link', 'info');
						}
						
						if (!class_exists('WC_Payment_Token_Paysafe')) {
							require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-payment-token.php';
						}
						
						$token = new WC_Payment_Token_Paysafe();
						$token->set_token($duplicate_check['payment_token']);
						$token->set_gateway_id($this->id);
						$token->set_user_id($user_id);
						$token->set_card_type($duplicate_check['card_type'] ?: ($card_type ?: 'card'));
						$token->set_last4($duplicate_check['last_digits']);
						
						if (isset($duplicate_check['card_expiry']['month']) && isset($duplicate_check['card_expiry']['year'])) {
							$token->set_expiry_month((int)$duplicate_check['card_expiry']['month']);
							$token->set_expiry_year((int)$duplicate_check['card_expiry']['year']);
						}
						
						$token->set_paysafe_payment_token($duplicate_check['payment_token']);
						$token->set_paysafe_profile_id($profile_id);
						$token->set_paysafe_card_id($duplicate_check['card_id']);
						
						$existing_for_gateway = WC_Payment_Tokens::get_customer_tokens($user_id, $this->id);
						if (empty($existing_for_gateway)) {
							$token->set_default(true);
						}
						
						$token_id = $token->save();
						
						if ($token_id && $this->enable_debug) {
							$this->log('save_card_from_token: Created WC token ID ' . $token_id . ' for existing vault card', 'info');
						}
					}
					
					return null;
				}
			}
			
			// NO DUPLICATE: Create new card
			if ( $this->enable_debug ) {
				$this->log( sprintf( 'save_card_from_token: No duplicate found, creating new card for profile: %s', $profile_id ), 'info' );
			}
			
			$api = new Paysafe_API($this->get_api_settings(), $this);
			$card_result = $api->create_permanent_card_from_token($profile_id, $payment_token);

			// Check if API caught a duplicate via 7503 error
			if ($card_result['success'] && !empty($card_result['duplicate'])) {
				if ($this->enable_debug) {
					$this->log('save_card_from_token: API returned duplicate=true (7503 error), not creating WC token', 'info');
				}
				return null;
			}

			if ($card_result['success'] && empty($card_result['duplicate'])) {

				// If we already have a WP user, store as a core CC token so Woo renders it on
				// My Account → Payment methods (custom types are ignored there).
				if ( ! class_exists( 'WC_Payment_Token_CC' ) ) {
					require_once WC()->plugin_path() . '/includes/payment-tokens/class-wc-payment-token-cc.php';
				}

			   $perm_token   = $card_result['payment_token'];
			   $final_type   = $card_type ?: ( $card_result['card_type']   ?? 'card' );
			   $final_last4  = $last4     ?: ( $card_result['last_digits'] ?? ''   );
			   $exp_month    = isset( $card_result['card_expiry']['month'] ) ? (int) $card_result['card_expiry']['month'] : 0;
			   $exp_year     = isset( $card_result['card_expiry']['year'] )  ? (int) $card_result['card_expiry']['year']  : 0;

			   if ( $this->enable_debug ) {
				   $this->log( sprintf( 'save_card_from_token: Card details - Type: %s, Last4: %s, Expiry: %02d/%d', 
					   $final_type,
					   $final_last4,
					   $exp_month,
					   $exp_year
				   ), 'info' );
			   }

			   /**
				* Robust expiry fallback for SAQ-A-EP:
				* If the vault response didn’t include expiry, derive it from POSTed fields.
				* Accepts: card_expiry ("MM/YY" or "MM / YY"), expiry_month, expiry_year.
				*/
			   if ( ! $exp_month || ! $exp_year ) {
				   // 1) Explicit month/year fields
				   $pm = isset($_POST['expiry_month']) ? (int) wc_clean( wp_unslash( $_POST['expiry_month'] ) ) : 0;
				   $py = isset($_POST['expiry_year'])  ? (int) wc_clean( wp_unslash( $_POST['expiry_year'] ) )  : 0;
				   if ( $pm >= 1 && $pm <= 12 && $py ) {
					   if ( $py < 100 ) { $py += ( $py >= 70 ) ? 1900 : 2000; }
					   $exp_month = $pm;
					   $exp_year  = $py;
				   }
			   }
			   if ( ! $exp_month || ! $exp_year ) {
				   // 2) Combined "MM/YY"
				   $raw_exp = isset($_POST['card_expiry']) ? wp_unslash( $_POST['card_expiry'] ) : '';
				   if ( is_string( $raw_exp ) ) {
					   $s = preg_replace( '/\s+/', '', $raw_exp );
					   if ( preg_match( '#^(\d{1,2})/(\d{2})$#', $s, $m ) ) {
						   $mm = (int) $m[1];
						   $yy = (int) $m[2];
						   if ( $mm >= 1 && $mm <= 12 ) {
							   $yy = ( $yy >= 70 ) ? (1900 + $yy) : (2000 + $yy);
							   $exp_month = $mm;
							   $exp_year  = $yy;
						   }
					   }
				   }
			   }

			   if ( $user_id ) {
				   // Ensure the Paysafe token class is loaded
				   if ( ! class_exists( 'WC_Payment_Token_Paysafe' ) ) {
				   	require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-payment-token.php';
				   }
				   
				   $token = new WC_Payment_Token_Paysafe();
				   $token->set_token( $perm_token );
				   $token->set_gateway_id( $this->id );
				   $token->set_user_id( $user_id );
				   $token->set_card_type( $final_type );
				   $token->set_last4( $final_last4 );
				   if ( $exp_month && $exp_year ) {
					   $token->set_expiry_month( $exp_month );
					   $token->set_expiry_year( $exp_year );
				   }
				   // Set Paysafe-specific properties using the proper setter methods
				   $token->set_paysafe_payment_token( $perm_token );
				   $token->set_paysafe_profile_id( $profile_id );
				   $token->set_paysafe_card_id( $card_result['card_id'] ?? '' );
				   
				   if ( $this->enable_debug ) {
					   $this->log( sprintf( 'save_card_from_token: About to save token. User: %d, Gateway: %s, Profile: %s', 
						   $user_id,
						   $this->id,
						   $profile_id
					   ), 'info' );
					   // Check validation before save
					   $is_valid = $token->validate();
					   $this->log( sprintf( 'save_card_from_token: Token validation: %s', $is_valid ? 'PASS' : 'FAIL' ), $is_valid ? 'info' : 'error' );
					   if ( ! $is_valid ) {
						   $this->log( sprintf( 'save_card_from_token: Validation failed. Payment token set: %s, Profile ID set: %s', 
							   $token->get_paysafe_payment_token() ? 'YES' : 'NO',
							   $token->get_paysafe_profile_id() ? 'YES' : 'NO'
						   ), 'error' );
					   }
				   }
				   // If this is the user's first token for this gateway, mark it default
				   $existing_for_gateway = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->id );
				   if ( empty( $existing_for_gateway ) && method_exists( $token, 'set_default' ) ) {
					   $token->set_default( true );
				   }
				   $token_id = $token->save();
				   if ( $this->enable_debug ) {
					   $this->log( sprintf( 'save_card_from_token: Token save result: %s (ID: %s)', 
						   $token_id ? 'SUCCESS' : 'FAILED',
						   $token_id ? $token_id : 'none'
					   ), $token_id ? 'info' : 'error' );
				   }
				   if ( ! $token_id ) {
					   throw new Exception( 'Failed to store payment token' );
				   }
				   // Persist the default flag at the user level if set
				   if ( method_exists( 'WC_Payment_Tokens', 'set_users_default' ) && method_exists( $token, 'get_is_default' ) && $token->get_is_default() ) {
					   WC_Payment_Tokens::set_users_default( $user_id, $token_id );
				   }
				   // Only note on real orders (skip the temporary one used by add_payment_method)
				   if ( $order && is_a( $order, 'WC_Order' ) && $order->get_id() ) {
					   $order->add_order_note( sprintf( __( 'Card saved to customer vault. Token ID: %s', 'paysafe-payment' ), $token_id ) );
				   }
			   } else {
				   // No WP user yet (guest or "create account" during checkout). Defer attaching.
				   if ( $order && is_a( $order, 'WC_Order' ) ) {
					   $order->update_meta_data( '_paysafe_pending_vault_token', $perm_token );
					   $order->update_meta_data( '_paysafe_pending_card_type', $final_type );
					   $order->update_meta_data( '_paysafe_pending_card_last4', $final_last4 );
					   if ( $exp_month && $exp_year ) {
						   $order->update_meta_data( '_paysafe_pending_card_expiry_month', $exp_month );
						   $order->update_meta_data( '_paysafe_pending_card_expiry_year',  $exp_year );
					   }
					   $order->update_meta_data( '_paysafe_pending_card_id', $card_result['card_id'] ?? '' );
					   $order->save();
				   }
			   }

			   return $perm_token;
		   }
	   }

   } catch (Exception $e) {
	   if ($this->enable_debug) {
		   $this->log('Failed to save card: ' . $e->getMessage(), 'error');
	   }
   }

   return false;
}

/**
* Get card display name
*/
protected function get_card_display_name($card_type) {
   $card_display_map = array(
	   'visa' => 'Visa',
	   'mastercard' => 'Mastercard',
	   'amex' => 'American Express',
	   'discover' => 'Discover',
	   'jcb' => 'JCB',
	   'diners' => 'Diners Club',
	   'interac' => 'Interac',
   );

   return isset($card_display_map[$card_type]) ? $card_display_map[$card_type] : 'Card';
}

/**
* Check if card should be saved
*/
protected function should_save_card() {
   // Allow saving if enabled and either the user is logged in
   // or they are creating an account during checkout.
   if ( ! $this->enable_saved_cards ) {
	   return false;
   }

   $creating_account = false;
   if ( isset( $_POST['createaccount'] ) ) {
	   $raw = strtolower( (string) wc_clean( wp_unslash( $_POST['createaccount'] ) ) );
	   $creating_account = ( $raw === '1' || $raw === 'true' || $raw === 'yes' || $raw === 'on' );
   }

   if ( ! is_user_logged_in() && ! $creating_account ) {
	   return false;
   }

   // 1) Classic checkout & theme aliases (booleans in POST)
   $candidates = array(
	   // Canonical WC gateway name (dynamic for safety)
	   'wc-' . $this->id . '-new-payment-method',
	   // Historic/explicit ids we output in payment_fields()
	   'wc-paysafe-new-payment-method',
	   'paysafe_save_card',
	   // Assorted aliases seen in themes/blocks shims
	   'save_card',
	   'save_payment_method',
	   'wc_save_payment_method',
   );
   foreach ( $candidates as $key ) {
	   if ( isset( $_POST[ $key ] ) ) {
		   $val = strtolower( (string) wc_clean( wp_unslash( $_POST[ $key ] ) ) );
		   if ( $val === '1' || $val === 'true' || $val === 'yes' || $val === 'on' ) {
			   return true;
		   }
	   }
   }

   // 2) WooCommerce Blocks / Store API payloads (JSON object in POST)
   if ( isset( $_POST['payment_method_data'] ) ) {
	   $raw = wp_unslash( $_POST['payment_method_data'] );
	   $decoded = is_string( $raw ) && strlen( $raw ) && $raw[0] === '{'
		   ? json_decode( $raw, true )
		   : ( is_array( $raw ) ? $raw : array() );
	   if ( is_array( $decoded ) ) {
		   $keys = array( 'savePaymentMethod', 'save_payment_method', 'should_save_payment_method' );
		   foreach ( $keys as $k ) {
			   // Flat (top-level) key
			   if ( isset( $decoded[ $k ] ) && wc_string_to_bool( $decoded[ $k ] ) ) {
				   return true;
			   }
			   // Nested under gateway id
			   if ( isset( $decoded[ $this->id ] ) && is_array( $decoded[ $this->id ] ) && isset( $decoded[ $this->id ][ $k ] ) && wc_string_to_bool( $decoded[ $this->id ][ $k ] ) ) {
				   return true;
			   }
		   }
	   }
   }

   // Creating an account at checkout can imply permission to save.
   if ( $creating_account ) {
	   return true;
   }
   return false;
}

/**
 * Normalize state/province to 2-letter codes for CA/US (best effort).
 * Prevents processor rejects when full names leak through.
 */
protected function format_state_code( $state, $country ) {
	if ( empty( $state ) ) {
		return '';
	}
	$country = strtoupper( (string) $country );
	// Canada
	if ( $country === 'CA' ) {
		$map = array(
			'AB'=>'AB','BC'=>'BC','MB'=>'MB','NB'=>'NB','NL'=>'NL','NT'=>'NT','NS'=>'NS','NU'=>'NU','ON'=>'ON','PE'=>'PE','QC'=>'QC','SK'=>'SK','YT'=>'YT',
			'alberta'=>'AB','british columbia'=>'BC','manitoba'=>'MB','new brunswick'=>'NB','newfoundland'=>'NL','newfoundland and labrador'=>'NL',
			'northwest territories'=>'NT','nova scotia'=>'NS','nunavut'=>'NU','ontario'=>'ON','prince edward island'=>'PE','quebec'=>'QC','québec'=>'QC',
			'saskatchewan'=>'SK','yukon'=>'YT','yukon territory'=>'YT',
		);
		if ( strlen( $state ) === 2 ) {
			$upper = strtoupper( $state );
			if ( isset( $map[ $upper ] ) ) {
				return $upper;
			}
		}
		$lower = strtolower( $state );
		if ( isset( $map[ $lower ] ) ) {
			return $map[ $lower ];
		}
		return strtoupper( substr( $state, 0, 2 ) );
	}
	// United States
	if ( $country === 'US' ) {
		$abbr = array(
			'AL'=>'AL','AK'=>'AK','AZ'=>'AZ','AR'=>'AR','CA'=>'CA','CO'=>'CO','CT'=>'CT','DE'=>'DE','FL'=>'FL','GA'=>'GA','HI'=>'HI','ID'=>'ID','IL'=>'IL',
			'IN'=>'IN','IA'=>'IA','KS'=>'KS','KY'=>'KY','LA'=>'LA','ME'=>'ME','MD'=>'MD','MA'=>'MA','MI'=>'MI','MN'=>'MN','MS'=>'MS','MO'=>'MO','MT'=>'MT',
			'NE'=>'NE','NV'=>'NV','NH'=>'NH','NJ'=>'NJ','NM'=>'NM','NY'=>'NY','NC'=>'NC','ND'=>'ND','OH'=>'OH','OK'=>'OK','OR'=>'OR','PA'=>'PA','RI'=>'RI',
			'SC'=>'SC','SD'=>'SD','TN'=>'TN','TX'=>'TX','UT'=>'UT','VT'=>'VT','VA'=>'VA','WA'=>'WA','WV'=>'WV','WI'=>'WI','WY'=>'WY',
			'DC'=>'DC','PR'=>'PR','GU'=>'GU','VI'=>'VI','AS'=>'AS','MP'=>'MP',
		);
		if ( strlen( $state ) === 2 ) {
			$upper = strtoupper( $state );
			if ( isset( $abbr[ $upper ] ) ) {
				return $upper;
			}
		}
		$names = array(
			'alabama'=>'AL','alaska'=>'AK','arizona'=>'AZ','arkansas'=>'AR','california'=>'CA','colorado'=>'CO','connecticut'=>'CT','delaware'=>'DE','florida'=>'FL',
			'georgia'=>'GA','hawaii'=>'HI','idaho'=>'ID','illinois'=>'IL','indiana'=>'IN','iowa'=>'IA','kansas'=>'KS','kentucky'=>'KY','louisiana'=>'LA','maine'=>'ME',
			'maryland'=>'MD','massachusetts'=>'MA','michigan'=>'MI','minnesota'=>'MN','mississippi'=>'MS','missouri'=>'MO','montana'=>'MT','nebraska'=>'NE',
			'nevada'=>'NV','new hampshire'=>'NH','new jersey'=>'NJ','new mexico'=>'NM','new york'=>'NY','north carolina'=>'NC','north dakota'=>'ND','ohio'=>'OH',
			'oklahoma'=>'OK','oregon'=>'OR','pennsylvania'=>'PA','rhode island'=>'RI','south carolina'=>'SC','south dakota'=>'SD','tennessee'=>'TN','texas'=>'TX',
			'utah'=>'UT','vermont'=>'VT','virginia'=>'VA','washington'=>'WA','west virginia'=>'WV','wisconsin'=>'WI','wyoming'=>'WY',
			'district of columbia'=>'DC','washington dc'=>'DC','d.c.'=>'DC','dc'=>'DC','puerto rico'=>'PR','guam'=>'GU','u.s. virgin islands'=>'VI',
			'us virgin islands'=>'VI','american samoa'=>'AS','northern mariana islands'=>'MP',
		);
		$lookup = strtolower( trim( $state ) );
		if ( isset( $names[ $lookup ] ) ) {
			return $names[ $lookup ];
		}
		return strtoupper( substr( $state, 0, 2 ) );
	}
	// Other countries: preserve two-letter uppercase if already a code; otherwise pass through.
	return ( strlen( $state ) === 2 ) ? strtoupper( $state ) : $state;
}

/**
 * Simple Luhn for server fallback.
 */
protected function luhn_valid( $num ) {
	$num = preg_replace( '/\D+/', '', (string) $num );
	$len = strlen( $num );
	if ( $len < 12 || $len > 19 ) return false;
	$sum = 0; $alt = false;
	for ( $i = $len - 1; $i >= 0; $i-- ) {
		$n = intval( $num[$i] );
		if ( $alt ) { $n *= 2; if ( $n > 9 ) $n -= 9; }
		$sum += $n; $alt = ! $alt;
	}
	return ( $sum % 10 ) === 0;
}

/**
 * Accepts "MM/YY" or "MM / YY", not in the past.
 */
protected function expiry_valid( $raw ) {
	$s = preg_replace( '/\s+/', '', (string) $raw );
	if ( ! preg_match( '#^(\d{1,2})/(\d{2})$#', $s, $m ) ) return false;
	$mm = (int) $m[1]; $yy = (int) $m[2];
	if ( $mm < 1 || $mm > 12 ) return false;
	$yy += ( $yy >= 70 ) ? 1900 : 2000;
	$now = current_time( 'timestamp' );
	$y = (int) gmdate( 'Y', $now );
	$m = (int) gmdate( 'n', $now );
	return ( $yy > $y ) || ( $yy === $y && $mm >= $m );
}

/**
 * If we converted a single-use token to a permanent token during checkout
 * but the WP user didn't exist yet (guest / "create account"), attach the
 * pending token to the user once available.
 *
 * @param int $order_id
 */
public function maybe_attach_pending_token_to_user( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
		return;
	}
	if ( $order->get_payment_method() !== $this->id ) {
		return;
	}
	if ( ! $this->enable_saved_cards ) {
		return;
	}

	$perm_token = $order->get_meta( '_paysafe_pending_vault_token' );
	if ( empty( $perm_token ) ) {
		return; // nothing to do
	}

	$user_id = (int) $order->get_user_id();
	if ( ! $user_id ) {
		return; // still no user; keep meta for a later hook
	}

	// Create the WC token now.
	if ( ! class_exists( 'WC_Payment_Token_Paysafe' ) ) {
		require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-payment-token.php';
	}
	
	// Get profile_id for this user
	$profile_id = get_user_meta( $user_id, '_paysafe_customer_profile_id', true );
	// Get card_id from pending meta (if available)
	$card_id = (string) $order->get_meta( '_paysafe_pending_card_id' );

	$card_type  = (string) $order->get_meta( '_paysafe_pending_card_type' );
	$last4      = (string) $order->get_meta( '_paysafe_pending_card_last4' );
	$exp_month  = (string) $order->get_meta( '_paysafe_pending_card_expiry_month' );
	$exp_year   = (string) $order->get_meta( '_paysafe_pending_card_expiry_year' );

	$token = new WC_Payment_Token_Paysafe();
	$token->set_token( $perm_token );
	$token->set_gateway_id( $this->id );
	$token->set_user_id( $user_id );
	if ( $card_type ) { $token->set_card_type( $card_type ); }
	if ( $last4 )     { $token->set_last4( $last4 ); }
	if ( $exp_month && $exp_year ) {
		$token->set_expiry_month( $exp_month );
		$token->set_expiry_year(  $exp_year );
	}

	// Set Paysafe-specific properties using the proper setter methods
	$token->set_paysafe_payment_token( $perm_token );
	if ( $profile_id ) {
		$token->set_paysafe_profile_id( $profile_id );
	}
	$token->set_paysafe_card_id( $card_id );

	$existing_for_gateway = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->id );
	if ( empty( $existing_for_gateway ) && method_exists( $token, 'set_default' ) ) {
		$token->set_default( true );
	}
	$token_id = $token->save();
	if ( $token_id ) {
		$order->add_order_note( __( 'Card saved to customer vault (deferred after checkout).', 'paysafe-payment' ) );
		// Clean up
		$order->delete_meta_data( '_paysafe_pending_vault_token' );
		$order->delete_meta_data( '_paysafe_pending_card_type' );
		$order->delete_meta_data( '_paysafe_pending_card_last4' );
		$order->delete_meta_data( '_paysafe_pending_card_expiry_month' );
		$order->delete_meta_data( '_paysafe_pending_card_expiry_year' );
		$order->delete_meta_data( '_paysafe_pending_card_id' );
		$order->save();
	}
}

   /**
	* Process refund
	*/
   public function process_refund($order_id, $amount = null, $reason = '') {
	   $order = wc_get_order($order_id);

	   if (!$order) {
		   return new WP_Error('invalid_order', __('Invalid order ID', 'paysafe-payment'));
	   }

	   // Get transaction ID
	   $transaction_id = $order->get_meta('_paysafe_transaction_id');

	   if (!$transaction_id) {
		   return new WP_Error('no_transaction', __('No transaction ID found for this order', 'paysafe-payment'));
	   }

		// Basic amount validation up-front
		if ( is_null( $amount ) || ! is_numeric( $amount ) || (float) $amount <= 0 ) {
			return new WP_Error( 'invalid_refund_amount', __( 'Refund amount must be greater than zero.', 'paysafe-payment' ) );
		}

	   if ($this->enable_debug) {
		   $this->log('Processing refund for order ' . $order_id . '. Amount: ' . $amount . '. Transaction ID: ' . $transaction_id);
	   }

	   try {
		   // Process refund through API using centralized settings
		   $api = new Paysafe_API($this->get_api_settings(), $this);

		   $refund_data = array(
			   'transaction_id' => $transaction_id,
			   'amount' => $amount,
			   'reason' => $reason,
			   'currency' => $order->get_currency()
		   );

		   $result = $api->process_refund($order, $refund_data);

		   if ($result['success']) {
			   // Add order note
			   $order->add_order_note(sprintf(
				   __('Paysafe refund processed successfully. Refund ID: %s. Amount: %s %s', 'paysafe-payment'),
				   $result['refund_id'],
				   $amount,
				   $order->get_currency()
			   ));

			   if ($this->enable_debug) {
				   $this->log('Refund processed successfully. Refund ID: ' . $result['refund_id']);
			   }

			   return true;
		   } else {
			   $error_message = isset($result['message']) ? $result['message'] : __('Refund failed', 'paysafe-payment');
			   
			   if ($this->enable_debug) {
				   $this->log('Refund failed: ' . $error_message, 'error');
			   }

			   return new WP_Error('refund_failed', $error_message);
		   }
	   } catch (Exception $e) {
		   if ($this->enable_debug) {
			   $this->log('Refund error: ' . $e->getMessage(), 'error');
		   }

		   return new WP_Error('refund_error', $e->getMessage());
	   }
   }

   /**
	* Get logger instance
	*/
   private function get_logger() {
	   if ($this->log === null && $this->enable_debug) {
		   $this->log = wc_get_logger();
	   }
	   return $this->log;
   }

   /**
	* Sanitize sensitive data before logging
	* 
	* @param mixed $data The data to sanitize
	* @return mixed The sanitized data
	*/
   private function sanitize_log_data($data) {
	// If it's a string, sanitize it directly
	if (is_string($data)) {
		// Remove card numbers (13-19 digits)
		$data = preg_replace('/\b\d{13,19}\b/', '***CARD_NUMBER_REDACTED***', $data);

		// Remove CVV (3-4 digits after certain keywords)
		$data = preg_replace('/\b(cvv|cvc|cvd|cve|cvn|cid|csc)["\']?\s*[:=]\s*["\']?\d{3,4}\b/i', '$1=***', $data);

		// Remove tokens
		$data = preg_replace('/\b(token|payment_token|paymentToken|single_use_token|singleUseToken)["\']?\s*[:=]\s*["\']?[\w\-]{20,}/i', '$1=***TOKEN_REDACTED***', $data);

		// Remove passwords (common names)
	   $data = preg_replace('/\b(password|pwd|pass|api_key_password|api_password)["\']?\s*[:=]\s*["\']?[^"\s,}]+/i', '$1=***PASSWORD_REDACTED***', $data);
	   // Generic catch-all: any key that ends with "password" or "username" (e.g. singleUseTokenPassword, single_use_token_username)
	   $data = preg_replace('/\b([a-z0-9._-]*password|[a-z0-9._-]*username)\b["\']?\s*[:=]\s*["\']?[^"\s,}]+/i', '$1=***REDACTED***', $data);

		// Remove auth codes
		$data = preg_replace('/\b(auth_code|authCode|authorization_code)["\']?\s*[:=]\s*["\']?[\w\-]+/i', '$1=***AUTH_REDACTED***', $data);

		// Remove API keys
		$data = preg_replace('/\b(api_key|apiKey|api_username|api_password)["\']?\s*[:=]\s*["\']?[^"\s,}]+/i', '$1=***API_KEY_REDACTED***', $data);

		// Mask email addresses (keep first letter and domain)
		$data = preg_replace_callback(
			'/\b([a-zA-Z])[a-zA-Z0-9._%+-]+@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})\b/',
			function($matches) {
				return $matches[1] . '***@' . $matches[2];
			},
			$data
		);

		// Mask phone numbers
		$data = preg_replace('/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', '***-***-****', $data);

		// Remove profile IDs and customer IDs
		$data = preg_replace('/\b(profile_id|customer_profile_id|customer_id|profileId|customerId)["\']?\s*[:=]\s*["\']?[\w\-]+/i', '$1=***ID_REDACTED***', $data);

		// Remove transaction IDs that might contain sensitive patterns
		$data = preg_replace('/\b(transaction_id|transactionId|trans_id|payment_id)["\']?\s*[:=]\s*["\']?[\w\-]{15,}/i', '$1=***TRANS_ID_REDACTED***', $data);

		return $data;
	}

	// If it's an array or object, recursively sanitize
	if (is_array($data) || is_object($data)) {
		$sanitized = array();
		foreach ($data as $key => $value) {
			// Check if the key itself indicates sensitive data
			$key_lower = is_string($key) ? strtolower($key) : '';
			if (in_array($key_lower, array(
				'card_number', 'cardnumber', 'cardnum', 'card_no',
				'cvv', 'cvc', 'cvd', 'cve', 'cvn', 'cid', 'csc',
				'password', 'pwd', 'pass', 'api_key_password', 'api_password',
				'token', 'payment_token', 'paymenttoken', 'single_use_token',
				'single_use_token_password','singleusetokenpassword',
				'single_use_token_username','singleusetokenusername',
				'auth_code', 'authcode', 'authorization_code',
				'api_key', 'apikey', 'api_username',
				'ssn', 'social_security', 'sin', 'social_insurance'
			), true)) {
				$sanitized[$key] = '***REDACTED***';
			} else {
				$sanitized[$key] = $this->sanitize_log_data($value);
			}
		}
		return is_object($data) ? (object)$sanitized : $sanitized;
	}

	return $data;
   }

   /**
	* Add log entry with sensitive data sanitization
	* 
	* @param string $message The message to log
	* @param string $level The log level (debug, info, warning, error)
	*/
   protected function log($message, $level = 'debug') {
	if ($this->enable_debug) {
		// Sanitize the message before logging
		$sanitized_message = $this->sanitize_log_data($message);

		$logger = $this->get_logger();
		if ($logger) {
			$context = array('source' => 'paysafe-payment');

			// Add timestamp for better debugging
			$timestamp = current_time('Y-m-d H:i:s');
			$sanitized_message = "[{$timestamp}] {$sanitized_message}";

			switch ($level) {
				case 'error':
					$logger->error($sanitized_message, $context);
					break;
				case 'warning':
					$logger->warning($sanitized_message, $context);
					break;
				case 'info':
					$logger->info($sanitized_message, $context);
					break;
				default:
					$logger->debug($sanitized_message, $context);
					break;
			}
		}
	}
   }

   /**
	* Handle Apple Pay domain verification
	*/
   public function maybe_handle_apple_pay_verification() {
		if ( isset($_SERVER['REQUEST_URI']) &&
			 false !== strpos($_SERVER['REQUEST_URI'], '/.well-known/apple-developer-merchantid-domain-association') ) {
			if ( $this->apple_pay_domain_verification ) {
				nocache_headers();
				status_header(200);
				header('Content-Type: text/plain; charset=utf-8');
				echo $this->apple_pay_domain_verification;
				exit;
			}
		}
	}

/**
 * Validate fields
 *
 * CRITICAL ARCHITECTURE NOTE:
 * WooCommerce's checkout validation sequence is:
 * 1. validate_posted_data() - adds billing/shipping errors to WP_Error object
 * 2. validate_fields() (THIS METHOD) - called while billing errors are still in WP_Error, NOT in notices
 * 3. AFTER validate_checkout() returns, errors are copied from WP_Error to wc_add_notice()
 *
 * This means wc_notice_count('error') is ALWAYS 0 when validate_fields() runs!
 *
 * SOLUTION: Store card errors in $this->deferred_card_errors and add them via
 * woocommerce_after_checkout_validation hook only if no billing errors exist.
 */
public function validate_fields() {
	// Reset deferred errors for this validation attempt
	$this->deferred_card_errors = array();

	// Check if using saved card - first check the card selection
	$card_selection = isset($_POST['paysafe-card-selection']) ? wc_clean( wp_unslash( $_POST['paysafe-card-selection'] ) ) : 'new';

	// If using saved card, check the token ID
	if ($card_selection === 'saved') {
		$token_id = isset($_POST['wc-paysafe-payment-token']) ? wc_clean( wp_unslash( $_POST['wc-paysafe-payment-token'] ) ) : '';

		if (!empty($token_id) && is_numeric($token_id)) {
			// Validate CVV for saved card if required
			if ($this->require_cvv_with_token) {
				$cvv = isset($_POST['paysafe_saved_card_cvv']) ? trim( wc_clean( wp_unslash( $_POST['paysafe_saved_card_cvv'] ) ) ) : '';
				if ($cvv === '') {
					// DEFER: Don't add notice now, store for later
					$this->deferred_card_errors[] = __('Please enter your card security code (CVV).', 'paysafe-payment');
					return false;
				}
				if (!preg_match('/^[0-9]{3,4}$/', $cvv)) {
					$this->deferred_card_errors[] = __('Invalid CVV format. Please enter 3 or 4 digits.', 'paysafe-payment');
					return false;
				}
			}
			return true;
		} else {
			$this->deferred_card_errors[] = __('Please select a saved card or choose "Use a new card".', 'paysafe-payment');
			return false;
		}
	}

	// CHECK FOR TOKENIZED PAYMENT FIRST (PCI Compliant path)
	$payment_token = isset($_POST['paysafe_payment_token']) ? wc_clean( wp_unslash( $_POST['paysafe_payment_token'] ) ) : '';

	if (!empty($payment_token)) {
		// Tokenized payment - validation complete
		return true;
	}

	// SAQ-A-EP ONLY: do lightweight server validation so users get actionable errors if JS fails
	if ( $this->pci_compliance_mode === 'saq_aep_only' ) {
		$number = isset($_POST['card_number']) ? preg_replace('/\D+/', '', wp_unslash($_POST['card_number'])) : '';
		$expiry = isset($_POST['card_expiry']) ? wp_unslash($_POST['card_expiry']) : '';
		$cvv    = isset($_POST['card_cvv'])    ? preg_replace('/\D+/', '', wp_unslash($_POST['card_cvv']))    : '';

		$errors = array();

		// Luhn check
		if ( ! $this->luhn_valid( $number ) ) {
			$errors[] = __( 'Please enter a valid card number', 'paysafe-payment' );
		}

		// Expiry check: allow MM/YY or MM / YY
		if ( ! $this->expiry_valid( $expiry ) ) {
			$errors[] = __( 'Please enter a valid expiration date (MM / YY)', 'paysafe-payment' );
		}

		// CVV
		if ( ! preg_match( '/^[0-9]{3,4}$/', $cvv ) ) {
			$errors[] = __( 'Please enter a valid security code', 'paysafe-payment' );
		}

		if ( ! empty( $errors ) ) {
			// DEFER all card errors
			$this->deferred_card_errors = array_merge($this->deferred_card_errors, $errors);
			return false;
		}
		// Fields look OK, but without a token we still cannot proceed server-side.
		// Front-end will create a token via AJAX; if JS failed, show a generic catch-all:
		$this->deferred_card_errors[] = __( 'Unable to process card. Please try again.', 'paysafe-payment' );
		return false;
	}

	// Enforce PCI compliance modes
	if ($this->pci_compliance_mode === 'saq_a_only') {
		// SAQ-A Only Mode:
		// Blocks ALL payment functionality if hosted fields fail
		// No autofill; no fallback to direct tokenization
		$this->deferred_card_errors[] = __('Secure card fields failed to load. Payment is blocked by SAQ-A Only mode.', 'paysafe-payment');
		return false;
	}

	// SAQ-A-EP Only Mode:
	// Skips hosted fields entirely; direct tokenization only (handled by JS / server as configured)
	// SAQ-A with Fallback (Default):
	// Tries hosted fields first; falls back to direct tokenization if needed
	if ($card_selection === 'new' || empty($card_selection)) {
		// Let JavaScript handle tokenization/fallback and resubmit with paysafe_payment_token
		return true;
	}

	return true;
}

/**
 * Add deferred card validation errors AFTER billing validation
 *
 * This hook runs after WooCommerce's validate_checkout() adds billing errors to WP_Error.
 * We check if billing errors exist - if so, we DON'T add card errors (billing first).
 * If no billing errors, we add our deferred card errors now.
 *
 * @param array $data Posted checkout data
 * @param WP_Error $errors Validation errors object
 */
public function add_deferred_card_errors($data, $errors) {
	// Only process if this is our gateway
	if (!isset($data['payment_method']) || $data['payment_method'] !== $this->id) {
		return;
	}

	// Check if billing/shipping errors already exist in WP_Error
	// If so, don't add card errors - let user fix billing first
	if ($errors->has_errors()) {
		// Billing errors exist - clear our deferred errors, don't show them
		$this->deferred_card_errors = array();
		return;
	}

	// No billing errors - add our deferred card errors
	foreach ($this->deferred_card_errors as $error_msg) {
		$errors->add('payment', $error_msg);
	}

	// Clear deferred errors after adding
	$this->deferred_card_errors = array();
}

   /**
	* Admin options
	*/
   public function admin_options() {
	   ?>
	   <h2><?php echo esc_html($this->get_method_title()); ?></h2>
	   <?php if (!empty($this->get_method_description())) : ?>
		   <p><?php echo wp_kses_post($this->get_method_description()); ?></p>
	   <?php endif; ?>

	   <?php if ($this->environment === 'sandbox') : ?>
		   <div class="notice notice-info inline">
			   <p><strong><?php _e('Test Mode Enabled', 'paysafe-payment'); ?></strong> - <?php _e('Transactions will be processed in sandbox mode. Switch to Live Mode when ready for production.', 'paysafe-payment'); ?></p>
		   </div>
	   <?php else : ?>
		   <div class="notice notice-warning inline">
			   <p><strong><?php _e('Live Mode Enabled', 'paysafe-payment'); ?></strong> - <?php _e('Transactions will be processed with real money.', 'paysafe-payment'); ?></p>
		   </div>
	   <?php endif; ?>

	   <table class="form-table">
		   <?php $this->generate_settings_html(); ?>
	   </table>
	   <?php
   }

   /**
	* Get payment method title with card icons
	*/
   public function get_title() {
	   $title = $this->title;

	   // Only add icons on frontend checkout (not order received page)
	   if (!is_admin() && !is_wc_endpoint_url('order-received') && !is_wc_endpoint_url('view-order') && !did_action('woocommerce_email_header') && (is_checkout() || is_checkout_pay_page())) {
		   $icons_html = '';

		   // Map of card types
		   $card_display_map = array(
			   'visa' => 'Visa',
			   'mastercard' => 'Mastercard',
			   'amex' => 'American Express',
			   'discover' => 'Discover',
			   'jcb' => 'JCB',
			   'diners' => 'Diners Club',
			   'interac' => 'Interac',
		   );

		   // Show all accepted card icons
		   foreach ($this->accepted_cards as $card) {
			   if (isset($card_display_map[$card])) {
				   $svg_path = PAYSAFE_PLUGIN_URL . 'assets/images/card-' . $card . '.svg';
				   $icons_html .= '<img src="' . esc_url($svg_path) . '" alt="" aria-hidden="true" role="presentation" style="height: 2.0em; width: auto; margin-left: 6px; vertical-align: middle;" onerror="this.style.display=\'none\'" />';
			   }
		   }

		   // Add the icons to the title
		   if (!empty($icons_html)) {
			   $title = $title . ' ' . $icons_html;
		   }
	   }

	   return $title;
   }

	/**
	 * Admin/REST-only icon. Parent returns HTML <img> built from $this->icon.
	 * We keep checkout clean by returning empty on the frontend.
	 */
	public function get_icon() {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
			return parent::get_icon();
		}
		return '';
	}

	/**
	 * Static launcher so the AJAX route works without a prebuilt instance.
	 */
	public static function ajax_create_single_use_token_static() {
		// Instantiate a fresh gateway safely for this request.
		$gateway = new self();
		$gateway->ajax_create_single_use_token();
	}

	/**
	 * AJAX: Create a Single-Use Token (server-side) without exposing credentials.
	 * Expects POST:
	 *  - nonce (matches paysafe_payment_nonce)
	 *  - card_number, expiry_month, expiry_year, cvv
	 * Returns: { success:true, payment_token:"...", last4:"1234", brand:"visa|mastercard|amex|discover|jcb|diners|interac|card" }
	 */
	public function ajax_create_single_use_token() {
		// CSRF
		if ( ! isset( $_POST['nonce'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Bad request.', 'paysafe-payment' ) ), 400 );
		}
		check_ajax_referer( 'paysafe_payment_nonce', 'nonce' );

		// PCI mode guard: do not allow direct tokenization in SAQ-A-only.
		if ( $this->pci_compliance_mode === 'saq_a_only' ) {
			wp_send_json_error( array(
				'message' => __( 'Secure hosted fields required; direct tokenization is disabled by PCI mode.', 'paysafe-payment' ),
			), 403 );
		}

		// Basic gateway availability checks.
		if ( 'yes' !== $this->enabled ) {
			wp_send_json_error( array( 'message' => __( 'Payments are disabled.', 'paysafe-payment' ) ), 403 );
		}
		if ( empty( $this->single_use_token_user ) || empty( $this->single_use_token_password ) ) {
			wp_send_json_error( array( 'message' => __( 'Tokenization is unavailable.', 'paysafe-payment' ) ), 500 );
		}

		// Collect + sanitize inputs (do NOT log these).
		$num = isset( $_POST['card_number'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_POST['card_number'] ) ) : '';
		$mm  = isset( $_POST['expiry_month'] ) ? (int) wc_clean( wp_unslash( $_POST['expiry_month'] ) ) : 0;
		$yy  = isset( $_POST['expiry_year'] )  ? (int) wc_clean( wp_unslash( $_POST['expiry_year'] ) )  : 0;
		$cvv = isset( $_POST['cvv'] )          ? preg_replace( '/\D+/', '', wp_unslash( $_POST['cvv'] ) ) : '';

		// Normalize 2-digit years to 20xx.
		if ( $yy > 0 && $yy < 100 ) {
			$yy += ( $yy >= 70 ) ? 1900 : 2000; // Pragmatic pivot.
		}

		// Validate (lightweight; processor will also validate).
		if ( strlen( $num ) < 12 || strlen( $num ) > 19 || $mm < 1 || $mm > 12 || $yy < 2000 || $yy > 2100 || strlen( $cvv ) < 3 || strlen( $cvv ) > 4 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid card details.', 'paysafe-payment' ) ), 422 );
		}

		// Build request to Paysafe Single-Use Token endpoint.
		$host     = ( $this->environment === 'live' ) ? 'https://api.paysafe.com' : 'https://api.test.paysafe.com';
		$endpoint = $host . '/customervault/v1/singleusetokens';
		$auth     = base64_encode( $this->single_use_token_user . ':' . $this->single_use_token_password );

		$body = array(
			'merchantRefNum' => 'sut_' . time() . '_' . wp_generate_password( 6, false, false ),
			'card' => array(
				'cardNum' => $num,
				'cvv'     => $cvv,
				'cardExpiry' => array(
					'month' => (int) $mm,
					'year'  => (int) $yy,
				),
			),
		);

		$args = array(
			'method'      => 'POST',
			'headers'     => array(
				'Authorization' => 'Basic ' . $auth,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'        => wp_json_encode( $body ),
			'timeout'     => 30,
			'data_format' => 'body',
		);

		$resp = wp_remote_post( $endpoint, $args );
		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( array( 'message' => __( 'Tokenization failed. Please try again.', 'paysafe-payment' ) ), 502 );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code >= 200 && $code < 300 && ! empty( $json['paymentToken'] ) ) {
			$last4 = substr( $num, -4 );
			$brand = $this->detect_card_brand_for_display( $num );
			// Return multiple aliases to match differing frontend expectations:
			// - payment_token (snake_case)
			// - paymentToken  (camelCase, common in SDK samples)
			// - token         (very old shims)
			wp_send_json_success( array(
				'payment_token' => $json['paymentToken'],
				'paymentToken'  => $json['paymentToken'],
				'token'         => $json['paymentToken'],
				'last4'         => $last4,
				'brand'         => $brand,
			) );
		}

		// Graceful error propagation with normalization into gateway custom error buckets.
		$raw_message = ! empty( $json['error']['message'] ) ? $json['error']['message'] : __( 'Tokenization failed.', 'paysafe-payment' );
		$error_code  = ! empty( $json['error']['code'] ) ? (string) $json['error']['code'] : '';
		$msg         = $raw_message;

		if ( $error_code !== '' ) {
			$custom_msg = $this->get_custom_error_message( $error_code, '' );
			if ( ! empty( $custom_msg ) ) {
				$msg = $custom_msg;
			}
		}

		// If we still have the raw Paysafe AVS text, normalize it to AVS_FAILED.
		if ( $msg === $raw_message && stripos( $raw_message, 'failed the avs check' ) !== false ) {
			$avs_msg = $this->get_custom_error_message( 'AVS_FAILED', '' );
			if ( ! empty( $avs_msg ) ) {
				$msg = $avs_msg;
			} else {
				// Fallback: use generic message instead of raw Paysafe text
				$msg = 'Address Verification Failed';
			}
		}

		// Allow safe inline markup in custom error messages.
		$msg = wp_kses( $msg, array(
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
		) );

		// Include both "message" and "error" for frontend compatibility.
		wp_send_json_error( array( 'message' => $msg, 'error' => $msg ), $code ?: 500 );
	}

	/**
	 * Lightweight brand detection for display only. No layout/UI impact.
	 */
	private function detect_card_brand_for_display( $num ) {
		$n = preg_replace( '/\D+/', '', (string) $num );
		if ( preg_match( '/^4\d{12,18}$/', $n ) ) return 'visa';
		if ( preg_match( '/^(5[1-5]\d{14}|2(2[2-9]\d|[3-6]\d{2}|7[01]\d|720)\d{12})$/', $n ) ) return 'mastercard';
		if ( preg_match( '/^3[47]\d{13}$/', $n ) ) return 'amex';
		if ( preg_match( '/^(6011\d{12}|65\d{14}|64[4-9]\d{13}|622(12[6-9]|1[3-9]\d|[2-8]\d{2}|9([01]\d|2[0-5]))\d{10})$/', $n ) ) return 'discover';
		if ( preg_match( '/^35(2[89]|[3-8]\d)\d{12}$/', $n ) ) return 'jcb';
		if ( preg_match( '/^(3(0[0-5]\d{11}|[68]\d{12}))$/', $n ) ) return 'diners';
		return 'card';
	}
}

	// --- AJAX: server-side Single-Use Token creation (public & logged-out) ---
	add_action(
		'wp_ajax_paysafe_create_single_use_token',
		array( 'WC_Gateway_Paysafe', 'ajax_create_single_use_token_static' )
	);
	add_action(
		'wp_ajax_nopriv_paysafe_create_single_use_token',
		array( 'WC_Gateway_Paysafe', 'ajax_create_single_use_token_static' )
	);

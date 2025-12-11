<?php
/**
 * Paysafe API Integration Class
 * File: includes/class-paysafe.api.php
 * Handles all API communications with Paysafe Customer Vault properly
 * WITH FULL RATE LIMITING IMPLEMENTATION
 * @package WooCommerce_Paysafe_Gateway
 * @version 1.0.4
 * Last updated: 2025-11-25
 */

if (!defined('ABSPATH')) {
	exit;
}

class Paysafe_API {
	
	private $api_username;
	private $api_password;
	private $single_use_token_username;
	private $single_use_token_password;
	private $account_id_cad;
	private $account_id_usd;
	private $sandbox_mode;
	private $debug_mode;
	private $logger;
	private $is_merrco_account = false;
	private $gateway = null; // Direct reference to WC_Gateway_Paysafe instance

	// Rate limiting properties
	private $rate_limit_enabled;
	private $rate_limit_requests;
	private $rate_limit_window;
	private $rate_limit_message;

	/**
	 * Constructor
	 * @param array $settings Configuration settings
	 * @param WC_Gateway_Paysafe|null $gateway Optional gateway instance for direct access to custom error messages
 	 */
	public function __construct($settings, $gateway = null) {
		// Store gateway reference if provided (enables reliable custom error message retrieval)
		$this->gateway = $gateway;
		
		$this->api_username = trim($settings['api_username'] ?? '');
		$this->api_password = trim($settings['api_password'] ?? '');
		$this->single_use_token_username = trim($settings['single_use_token_username'] ?? '');
		$this->single_use_token_password = trim($settings['single_use_token_password'] ?? '');
		$this->account_id_cad = trim($settings['account_id_cad'] ?? '');
		$this->account_id_usd = trim($settings['account_id_usd'] ?? '');

		// Handle environment setting
		$environment = $settings['environment'] ?? 'sandbox';
		$this->sandbox_mode = ($environment === 'sandbox');
		
		$this->debug_mode = ($settings['debug'] ?? 'no') === 'yes';

		// Initialize rate limiting settings
		// Normalize potential 'yes'/'no' or boolean-like values to a real boolean.
		// Prevents accidental enabling when option is the non-empty string 'no'.
		$rle = $settings['rate_limit_enabled'] ?? true;
		if (is_bool($rle)) {
			$this->rate_limit_enabled = $rle;
		} else {
			// Accept common truthy strings/ints used in WP options.
			$this->rate_limit_enabled = in_array($rle, [true, 1, '1', 'yes', 'true'], true);
		}

		// Guardrails: ensure sensible minimums to avoid divide-by-zero or "infinite" windows.
		$this->rate_limit_requests = max(1, (int) ($settings['rate_limit_requests'] ?? 30));
		$this->rate_limit_window   = max(1, (int) ($settings['rate_limit_window']   ?? 60));
		$this->rate_limit_message = isset($settings['rate_limit_message']) ? 
			$settings['rate_limit_message'] : 
			__('Rate limit exceeded. Please wait %d seconds before trying again.', 'paysafe-payment');

		// Check if this is a Merrco account (pmle- prefix)
		$this->is_merrco_account = (strpos($this->api_username, 'pmle-') === 0);
		
		if ($this->debug_mode) {
			$this->logger = wc_get_logger();
			if ($this->is_merrco_account) {
				$this->log('Merrco/Netbanx account detected');
				$this->log('Environment mode: ' . ($this->sandbox_mode ? 'SANDBOX' : 'LIVE'));
			}
			if ($this->rate_limit_enabled) {
				$this->log('Rate limiting enabled: ' . $this->rate_limit_requests . ' requests per ' . $this->rate_limit_window . ' seconds');
			}
		}
	}

	/**
	 * Get the API endpoint based on environment
	 */
	private function get_api_endpoint() {
		if ($this->is_merrco_account) {
			if (!$this->sandbox_mode) {
				$this->log('Merrco: Using LIVE environment - api.netbanx.com');
				return 'https://api.netbanx.com';
			} else {
				$this->log('Merrco: Using TEST environment - api.test.netbanx.com');
				return 'https://api.test.netbanx.com';
			}
		}
		
		if ($this->sandbox_mode) {
			return 'https://api.test.paysafe.com';
		}
		return 'https://api.paysafe.com';
	}

	/**
	 * Get authorization headers for API requests
	 */
	private function get_auth_headers() {
		$credentials = base64_encode($this->api_username . ':' . $this->api_password);
		return array(
			'Authorization' => 'Basic ' . $credentials,
			'Content-Type' => 'application/json',
			'Accept' => 'application/json'
		);
	}

	/**
	 * Get authorization headers for single-use token requests
	 */
	private function get_token_auth_headers() {
		$credentials = base64_encode($this->single_use_token_username . ':' . $this->single_use_token_password);
		return array(
			'Authorization' => 'Basic ' . $credentials,
			'Content-Type' => 'application/json',
			'Accept' => 'application/json'
		);
	}

	/**
	 * Log API activity if debug mode is enabled
	 */
	private function log($message, $level = 'info') {
		if ($this->debug_mode && $this->logger) {
			// Sanitize sensitive data before logging
			$sanitized_message = $this->sanitize_log_data($message);
			$this->logger->log($level, $sanitized_message, array('source' => 'paysafe-gateway'));
		}
	}

	/**
	 * Check rate limiting
	 * 
	 * @return bool True if within rate limit, false if exceeded
	 */
	private function check_rate_limit() {
		if (!$this->rate_limit_enabled) {
			return true;
		}
		
		$key = 'paysafe_rate_limit_' . md5($this->api_username);
		$requests = get_transient($key);
		
		if ($requests === false) {
			return true;
		}
		
		if ($requests >= $this->rate_limit_requests) {
			$this->log('Rate limit exceeded: ' . $requests . '/' . $this->rate_limit_requests . ' requests', 'warning');
			return false;
		}
		
		return true;
	}

	/**
	 * Track API request for rate limiting
	 */
	private function track_request() {
		if (!$this->rate_limit_enabled) {
			return;
		}
		
		$key = 'paysafe_rate_limit_' . md5($this->api_username);
		$requests = get_transient($key);
		
		if ($requests === false) {
			// first hit in this window
			set_transient($key, 1, $this->rate_limit_window);
		} else {
			// preserve remaining TTL of the original window
			$ttl = $this->get_rate_limit_wait_time();
			$ttl = (is_int($ttl) && $ttl > 0) ? $ttl : $this->rate_limit_window;
			set_transient($key, $requests + 1, $ttl);
		}
		
		if ($this->debug_mode) {
			$current_count = $requests === false ? 1 : $requests + 1;
			$this->log('Rate limit tracking: ' . $current_count . '/' . $this->rate_limit_requests . ' requests');
		}
	}

	/**
	 * Get rate limit wait time
	 * 
	 * @return int Seconds to wait before retry
	 */
	private function get_rate_limit_wait_time() {
		$key = 'paysafe_rate_limit_' . md5($this->api_username);
		// Single-site transients
		$expiration = get_option('_transient_timeout_' . $key);
		// Multisite transients
		if (!$expiration && function_exists('is_multisite') && is_multisite()) {
			$expiration = get_site_option('_site_transient_timeout_' . $key);
		}
		// Note: with an external object cache, the timeout may not be readable.
		// In that case we gracefully fall back to the configured window.

		if ($expiration) {
			return max(0, $expiration - time());
		}

		// Best-effort fallback; preserves existing behavior
		// and avoids extending the window unintentionally.
		return $this->rate_limit_window;
	}

	/**
	 * Make API request
	 */
	public function make_request($endpoint, $method = 'POST', $body = null, $use_token_auth = false) {
		// Rate limit check
		if (!$this->check_rate_limit()) {
			$wait_time = $this->get_rate_limit_wait_time();
			$error_message = sprintf($this->rate_limit_message, $wait_time);
			$this->log('Rate limit hit - ' . $error_message, 'warning');
			throw new Exception($error_message);
		}

		// Track the request IMMEDIATELY after rate limit check passes
		// This ensures we count ALL attempts, not just successful ones
		$this->track_request();
		
		$url = $this->get_api_endpoint() . $endpoint;
		
		$this->log('API Request to: ' . $url);
		$this->log('Request Method: ' . $method);
		
		$args = array(
			'method' => $method,
			'headers' => $use_token_auth ? $this->get_token_auth_headers() : $this->get_auth_headers(),
			'timeout' => 30,
			'sslverify' => true,
			'body' => $body ? (function_exists('wp_json_encode') ? wp_json_encode($body) : json_encode($body)) : null
		);
		
		if ($body) {
			$safe_body = $this->sanitize_for_log($body);
			$this->log('Request Body: ' . (function_exists('wp_json_encode') ? wp_json_encode($safe_body) : json_encode($safe_body)));
		}
		
		$response = wp_remote_request($url, $args);

		// Handle transport errors first to avoid logging bogus codes/bodies.        
		if (is_wp_error($response)) {
			$error_message = $response->get_error_message();
			$this->log('WP Error: ' . $error_message, 'error');
			throw new Exception('Connection error: ' . $error_message);
		}
		
		 $response_code = wp_remote_retrieve_response_code($response);
		 $response_body = wp_remote_retrieve_body($response);

		 $this->log('Response Code: ' . $response_code);
		 $this->log('Response Body: ' . $this->sanitize_log_data($response_body)); // may contain sensitive data

		// Successful responses with no body (e.g., DELETE 204) should not error.
		if ($response_code >= 200 && $response_code < 300 && ($response_body === '' || $response_body === null)) {
			// Normalize to empty array for callers that expect an array from make_request()
			return array();
		}
		
		$parsed_response = json_decode($response_body, true);
		
		if ($response_code >= 400) {
			// CRITICAL: AVS/CVV errors include auth ID that needs voiding
			// Check BEFORE throwing so we can return the auth ID to gateway
			if (is_array($parsed_response) && isset($parsed_response['error']['code']) && isset($parsed_response['id'])) {
				$error_code = $parsed_response['error']['code'];
				// Error codes that create auth requiring void (not all are AVS/CVV)
				// 3007=AVS, 3009=Issuer Decline, 3022=NSF, 3023=CVV, 5014/5015=CVV
				if (in_array($error_code, array('3007', '3009', '3022', '3023', '5014', '5015'))) {
					$this->log('Error ' . $error_code . ' created auth with ID ' . $parsed_response['id'] . ' - returning full response for voiding', 'warning');
					return $parsed_response; // Return full response including auth ID
				}
			}
			
			$error_message = $this->extract_error_message($parsed_response, $response_code);
			$this->log('API Error: ' . $error_message, 'error');
			throw new Exception($error_message);
		}

		// Some gateways legitimately return the JSON literal `null` on 2xx.
		// Treat that as an empty payload instead of returning PHP null.
		if ($parsed_response === null
			&& is_string($response_body)
			&& strtolower(trim($response_body)) === 'null') {
			return array();
		}

		// JSON decode error check
		if ($parsed_response === null && json_last_error() !== JSON_ERROR_NONE) {
			$this->log('JSON decode error: ' . json_last_error_msg(), 'error');
			throw new Exception('Invalid response format from API');
		}
		
		return $parsed_response;
	}

	/**
	 * Extract error message from API response with priority checking
	 * Priority order: AVS/CVV verification codes > Error codes > Message text > Field errors
	 * 
	 * @param array $response The parsed API response
	 * @param int $code HTTP response code
	 * @return string Error message for display to customer
	 */
	private function extract_error_message($response, $code) {
		// Guard against non-array responses to avoid notices on error paths.
		if (!is_array($response)) {
			$response = array();
		}

		// ===== DEBUG LOGGING =====
		if ($this->debug_mode) {
			$this->log('=== RAW ERROR RESPONSE DEBUG ===', 'error');
			$this->log('HTTP Code: ' . $code, 'error');
			$this->log('Full Response: ' . json_encode($response), 'error');
			if (isset($response['avsResponse'])) {
				$this->log('AVS Response: ' . $response['avsResponse'], 'error');
			}
			if (isset($response['cvvVerification'])) {
				$this->log('CVV Verification: ' . $response['cvvVerification'], 'error');
			}
			if (isset($response['error']['code'])) {
				$this->log('Error Code: ' . $response['error']['code'], 'error');
			}
			if (isset($response['error']['message'])) {
				$this->log('Error Message: ' . $response['error']['message'], 'error');
			}
			$this->log('=== END DEBUG ===', 'error');
		}
		// ===== END DEBUG =====
		
		$error_code = '';
		$error_message = '';
		$raw_message = '';

		// ==================================================================================
		// PRIORITY CHECK #0: Check additionalDetails for specific risk codes (HIGHEST PRIORITY)
		// These are more specific than the main error code (e.g., 4844 is more specific than 4002)
		// ==================================================================================
		if (isset($response['error']['additionalDetails']) && is_array($response['error']['additionalDetails'])) {
			foreach ($response['error']['additionalDetails'] as $detail) {
				if (isset($detail['type']) && $detail['type'] === 'RISK_RESPONSE' && isset($detail['code'])) {
					$risk_code = (string) $detail['code'];
					$this->log('Risk Management Detail Code: ' . $risk_code, 'error');
					
					// Try to get custom message for this specific risk code
					$custom_message = $this->get_custom_error_message_from_gateway($risk_code, '');
					if (!empty($custom_message)) {
						$this->log('Using custom risk detail message for code: ' . $risk_code, 'info');
						return $custom_message;
					}
				}
			}
		}

		// ==================================================================================
		// PRIORITY CHECK #1: Look for AVS/CVV verification codes in main response FIRST
		// These take precedence over all other error indicators
		// ==================================================================================
		if (isset($response['avsResponse'])) {
			$avs = strtoupper((string) $response['avsResponse']);
			// AVS codes that indicate address mismatch:
			// N = Neither address nor ZIP match
			// A = Address matches, ZIP does not
			// Z = ZIP matches, address does not
			// W = 9-digit ZIP matches, address does not
			// C = Neither address nor ZIP match (Canada)
			// I = Address information not verified (international)
			// P = AVS not applicable (non-US)
			$avs_fail_codes = array('N', 'A', 'Z', 'W', 'C', 'I', 'P');
			if (in_array($avs, $avs_fail_codes, true)) {
				$this->log('AVS Verification Failed: ' . $avs . ' - Prioritizing AVS error', 'error');
				$error_code = 'AVS_FAILED';
					$generic_message = 'Address Verification Failed';

			// CRITICAL: Pass empty string as default to avoid returning raw Paysafe messages
			$custom_message = $this->get_custom_error_message_from_gateway($error_code, '');
			if (!empty($custom_message)) {
				$this->log('Using custom AVS message', 'info');
				return $custom_message;
		}
			// Use generic message as final fallback (never return raw Paysafe text)
			return $generic_message;
			}
		}
		
		if (isset($response['cvvVerification'])) {
			$cvv = strtoupper((string) $response['cvvVerification']);
			// CVV codes that indicate CVV mismatch:
			// N = CVV does not match
			// P = Not processed
			// S = CVV should be present but is not
			// U = CVV check unavailable
			$cvv_fail_codes = array('N', 'P', 'S', 'U');
			if (in_array($cvv, $cvv_fail_codes, true)) {
				$this->log('CVV Verification Failed: ' . $cvv . ' - Prioritizing CVV error', 'error');
				$error_code = 'CVV_FAILED';
				$generic_message = 'Security Code Invalid';

				// CRITICAL: Pass empty string as default to avoid returning raw Paysafe messages
				$custom_message = $this->get_custom_error_message_from_gateway($error_code, '');
				if (!empty($custom_message)) {
					$this->log('Using custom CVV message', 'info');
					return $custom_message;
				}
				// Use generic message as final fallback (never return raw Paysafe text)
				return $generic_message;
			}
		}
		
		// ==================================================================================
		// PRIORITY CHECK #2: Process error object (if AVS/CVV didn't trigger)
		// ==================================================================================
		if (isset($response['error']['message'])) {
			$raw_message = $response['error']['message'];
			$error_message = $raw_message;
			
			// Get error code if present - CAST TO STRING for consistent array lookup
			if (isset($response['error']['code'])) {
				$error_code = (string) $response['error']['code'];
			}
			
			// Check for specific error indicators in message text
			if (empty($error_code)) {
				$msg_lower = strtolower($raw_message);
				
				// Detect AVS errors in message text
				if (strpos($msg_lower, 'address') !== false || strpos($msg_lower, 'avs') !== false) {
					$error_code = 'AVS_FAILED';
				}
				// Detect CVV errors in message text
				elseif (strpos($msg_lower, 'cvv') !== false || strpos($msg_lower, 'security code') !== false || strpos($msg_lower, 'cvc') !== false) {
					$error_code = 'CVV_FAILED';
				}
				// Detect insufficient funds
				elseif (strpos($msg_lower, 'insufficient') !== false || strpos($msg_lower, 'nsf') !== false) {
					$error_code = 'INSUFFICIENT_FUNDS';
				}
				// Detect expired card
				elseif (strpos($msg_lower, 'expired') !== false) {
					$error_code = 'EXPIRED_CARD';
				}
				// Detect invalid card
				elseif (strpos($msg_lower, 'invalid card') !== false || strpos($msg_lower, 'card number') !== false) {
					$error_code = 'INVALID_CARD';
				}
				// Generic decline
				elseif (strpos($msg_lower, 'decline') !== false || strpos($msg_lower, 'risk') !== false) {
					$error_code = 'DECLINED';
				}
			}
			
			// Add field errors if present
			if (isset($response['error']['fieldErrors']) && is_array($response['error']['fieldErrors'])) {
				$field_errors = array();
				foreach ($response['error']['fieldErrors'] as $field_error) {
					if (isset($field_error['field']) && isset($field_error['error'])) {
						$field_errors[] = $field_error['field'] . ': ' . $field_error['error'];
						
						// Try to detect error type from field errors (only if no error code yet)
						if (empty($error_code)) {
							$field_lower = strtolower($field_error['error']);
							if (strpos($field_lower, 'cvv') !== false) {
								$error_code = 'CVV_FAILED';
							} elseif (strpos($field_lower, 'address') !== false) {
								$error_code = 'AVS_FAILED';
							}
						}
					}
				}
				if (!empty($field_errors)) {
					$error_message .= ' - ' . implode(', ', $field_errors);
				}
			}
		} else {
			// Use HTTP status code defaults if no error message
			switch ($code) {
				case 401:
					$error_message = 'Invalid credentials - please check your API username and password';
					break;
				case 403:
					$error_message = 'Access forbidden - please verify your account ID and API permissions';
					break;
				case 404:
					$error_message = 'API endpoint not found - please contact support';
					break;
				case 402:
					$error_message = 'Payment declined';
					$error_code = 'DECLINED';
					break;
				case 429:
					$error_message = 'Too many requests - API rate limit exceeded';
					break;
				case 500:
				case 502:
				case 503:
				case 504:
					$error_message = 'Paysafe server error - please try again later';
					break;
				default:
					$error_message = 'API Error (HTTP ' . $code . ')';
					break;
			}
		}
		
		// Try to get custom error message from gateway settings
		$custom_message = '';
		if (!empty($error_code)) {
			// CRITICAL: Pass empty string as default to avoid returning raw Paysafe messages
			$custom_message = $this->get_custom_error_message_from_gateway($error_code, '');
		}
		
		// If we have a custom message, use it
		if (!empty($custom_message)) {
			// Log the technical error for admin
			if ($this->debug_mode) {
				$this->log('Error Code: ' . $error_code . ' | Original Message: ' . $raw_message, 'error');
			}
			return $custom_message;
		}
		
		// Special-case Paysafe's default AVS error text so it always goes through the AVS bucket
		if (stripos($error_message, 'failed the avs check') !== false) {
			// CRITICAL: Pass empty string to avoid returning raw Paysafe text
			$avs_message = $this->get_custom_error_message_from_gateway('AVS_FAILED', '');
			if (!empty($avs_message)) {
				if ($this->debug_mode) {
					$this->log('Normalized Paysafe AVS error message into AVS_FAILED bucket. Original: ' . $error_message, 'error');
				}
				return $avs_message;
			}
			// If no custom AVS message, use generic message instead of raw Paysafe text
			return 'Address Verification Failed';
		}
		
		// For error codes with no custom message, use generic messages
		// NEVER return raw Paysafe error messages to customers
		if (!empty($error_code)) {
			if ($this->debug_mode) {
				$this->log('No custom message for error code: ' . $error_code . ' | Original: ' . $error_message, 'error');
			}
		
			// Return generic user-friendly message based on error code
			if (in_array($error_code, array('AVS_FAILED', '3007', '3009'))) {
				return 'Address Verification Failed';
			} elseif (in_array($error_code, array('CVV_FAILED', '3023', '5015'))) {
				return 'Security Code Invalid';
			} elseif (in_array($error_code, array('INSUFFICIENT_FUNDS', '3022', '3051', '3052'))) {
				return 'Insufficient Funds';
			} elseif (in_array($error_code, array('EXPIRED_CARD', '3012'))) {
				return 'Card Expired';
			} elseif (in_array($error_code, array('INVALID_CARD', '3011', '5002', '5003'))) {
				return 'Invalid Card';
			} elseif (strpos($error_code, '484') === 0 || $error_code === '4002') {
				// Risk management errors (4844-4851, 4002)
				return 'Transaction Declined - Risk Assessment';
			} elseif (in_array($error_code, array('DECLINED', '3001', '3002', '3004', '3005', '5001'))) {
				return 'Transaction Declined';
			}
		}

		// Last resort fallback - check if it looks like a Paysafe internal message
		// If so, replace with generic message
		if (stripos($error_message, 'failed the') !== false 
			|| stripos($error_message, 'request has') !== false
			|| stripos($error_message, 'reserved on') !== false
			|| stripos($error_message, 'will be released') !== false) {
			if ($this->debug_mode) {
				$this->log('Blocking Paysafe internal message: ' . $error_message, 'warning');
			}
			return 'Payment processing error. Please try again or contact support.';
		}

		// Only return the error message if it doesn't look like Paysafe internal text
		return $error_message;
	}

	/**
	 * Get custom error message from gateway settings
	 * 
	 * @param string $error_code
	 * @param string $default_message
	 * @return string
	 */
	private function get_custom_error_message_from_gateway($error_code, $default_message) {
		// PRIORITY 1: Use direct gateway reference if available (most reliable)
		if ($this->gateway && method_exists($this->gateway, 'get_custom_error_message')) {
			return $this->gateway->get_custom_error_message($error_code, $default_message);
		}
		
		// PRIORITY 2: Fallback to WC_Payment_Gateways lookup (may fail in some contexts)
		if (class_exists('WC_Payment_Gateways')) {
			$gateways = WC_Payment_Gateways::instance();
			$gateway = $gateways->payment_gateways()['paysafe'] ?? null;
			
			if ($gateway && method_exists($gateway, 'get_custom_error_message')) {
				return $gateway->get_custom_error_message($error_code, $default_message);
			}
		}

		// PRIORITY 3: Return default message if gateway unavailable
		return $default_message;
	}

	/**
	 * Sanitize sensitive data for logging
	 */
	private function sanitize_for_log($data) {
		if (!is_array($data)) {
			return $data;
		}
		
		$safe_data = $data;
		
		$sensitive_fields = [
			'cardNum', 'cvv', 'cvc', 'cvd', 'card', 'password', 'pin', 
			'card_number', 'card_cvv', 'paymentToken', 'singleUseToken',
			'api_username', 'api_password', 'api_key', 'apiKey',
			'authCode', 'auth_code', 'merchantCustomerId', 'profileId',
			'holderName', 'email', 'phone', 'street', 'zip'
		];
		
		foreach ($sensitive_fields as $field) {
			if (isset($safe_data[$field])) {
				$safe_data[$field] = '***REDACTED***';
			}
		}

		// Recursively sanitize nested arrays
		foreach ($safe_data as $key => $value) {
			if (is_array($value)) {
				$safe_data[$key] = $this->sanitize_for_log($value);
			}
		}
		
		return $safe_data;
	}

	/**
	 * Sanitize sensitive data from log messages
	 * This is different from sanitize_for_log which handles structured data
	 * This method handles string messages and sanitizes inline sensitive data
	 * 
	 * @param mixed $data The data to sanitize
	 * @return mixed The sanitized data
	 */
	private function sanitize_log_data($data) {
		// If it's already using sanitize_for_log for arrays/objects, use that
		if (is_array($data) || is_object($data)) {
			$san = $this->sanitize_for_log((array) $data);
			return function_exists('wp_json_encode') ? wp_json_encode($san) : json_encode($san);
		}

		// If it's a string, sanitize inline sensitive data
		if (is_string($data)) {
			// Remove card numbers (13-19 digits)
			$data = preg_replace('/\b\d{13,19}\b/', '***CARD_NUMBER***', $data);

			// Remove CVV (3-4 digits after cvv/cvc/cvd keywords)
			$data = preg_replace('/\b(cvv|cvc|cvd|cve|cvn|cid|csc)["\']?\s*[:=]\s*["\']?\d{3,4}\b/i', '$1=***', $data);

			// Remove tokens (long alphanumeric strings after token keywords)
			$data = preg_replace('/\b(token|payment_token|paymentToken|single_use_token|singleUseToken)["\']?\s*[:=]\s*["\']?[\w\-]{20,}/i', '$1=***TOKEN***', $data);

			// Remove passwords
			$data = preg_replace('/\b(password|pwd|pass|api_password)["\']?\s*[:=]\s*["\']?[^"\s,}]+/i', '$1=***PASSWORD***', $data);

			// Remove API keys and usernames (but preserve pmle- prefix for debugging)
			$data = preg_replace_callback(
				'/\b(api_key|apiKey|api_username|username)["\']?\s*[:=]\s*["\']?([^"\s,}]+)/i',
				function($matches) {
					if (strpos($matches[2], 'pmle-') === 0) {
						return $matches[1] . '=pmle-***';
					}
					return $matches[1] . '=***API_KEY***';
				},
				$data
			);

			// Remove auth codes
			$data = preg_replace('/\b(authCode|auth_code|authorization_code)["\']?\s*[:=]\s*["\']?[\w\-]+/i', '$1=***AUTH***', $data);

			// Remove Authorization headers with Bearer tokens or Basic auth
			$data = preg_replace('/Authorization["\']?\s*[:=]\s*["\']?(Bearer|Basic)\s+[^"\s,}]+/i', 'Authorization=***REDACTED***', $data);

			// Mask email addresses (keep first letter and domain)
			$data = preg_replace_callback(
				'/\b([A-Za-z0-9])[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/',
				function($matches) {
					return $matches[1] . '***@' . $matches[2];
				},
				$data
			);

			// Mask phone numbers
			$data = preg_replace('/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', '***-***-****', $data);

			// Remove profile IDs and customer IDs
			$data = preg_replace('/\b(profile_id|customer_profile_id|profileId|customerId|merchantCustomerId)["\']?\s*[:=]\s*["\']?[\w\-]+/i', '$1=***ID***', $data);

			// Remove transaction IDs and reference numbers.
			// Keep generic "id" visible unless it looks like a UUID (typical for Paysafe txns).
			$data = preg_replace('/\b(transaction_id|transactionId|refNum|merchantRefNum)["\']?\s*[:=]\s*["\']?[\w\-]{10,}/i', '$1=***TRANS_ID***', $data);
			$data = preg_replace('/\b(id)["\']?\s*[:=]\s*["\']?(?:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})\b/', '$1=***TRANS_ID***', $data);

			// Remove account IDs (exactly 10 digits)
			$data = preg_replace('/\b(account_id|accountId|account)["\']?\s*[:=]\s*["\']?\d{10}\b/i', '$1=***ACCOUNT_ID***', $data);

			// Remove merchant IDs
			$data = preg_replace('/\b(merchant_id|merchantId)["\']?\s*[:=]\s*["\']?[\w\-]+/i', '$1=***MERCHANT_ID***', $data);

			// Remove IP addresses
			$data = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '***IP_ADDRESS***', $data);
		}
		
		return $data;
	}

	/**
	 * Get account ID based on currency
	 * FIXED: Added validation
	 */
	private function get_account_id($currency = 'CAD') {
		$currency = strtoupper((string) $currency);
		if ($currency === 'USD') {
			if (!empty($this->account_id_usd)) {
				return $this->account_id_usd;
			}
			$this->log('No account ID configured for currency: USD', 'error');
			return '';
		}
		if ($currency === 'CAD') {
			if (!empty($this->account_id_cad)) {
				return $this->account_id_cad;
			}
			$this->log('No account ID configured for currency: CAD', 'error');
			return '';
		}
		// Unknown/unsupported currency — do not fall back to CAD.
		$this->log('Unsupported currency for account resolution: ' . $currency, 'error');
		return '';
	}

		/**
	 * Create customer profile for tokenization
	 * Accept both input shapes:
	 *  - Direct Vault shape (merchantCustomerId, firstName, lastName, email, phone)
	 *  - Snake_case shape (customer_id, first_name, last_name, email, phone)
	 */
	public function create_customer_profile($customer_data) {
		// Normalize inputs coming from different callers
		// Prefer already-normalized vault fields if present
		$merchantCustomerId = '';
		$firstName = '';
		$lastName  = '';
		$email     = '';
		$phone     = '';

		if (!empty($customer_data['merchantCustomerId'])) {
			// Caller already provided vault-ready keys (as your tokenization class currently does)
			$merchantCustomerId = (string) $customer_data['merchantCustomerId'];
			$firstName = (string) ($customer_data['firstName'] ?? ($customer_data['first_name'] ?? ''));
			$lastName  = (string) ($customer_data['lastName']  ?? ($customer_data['last_name']  ?? ''));
			$email     = (string) ($customer_data['email']     ?? '');
			$phone     = (string) ($customer_data['phone']     ?? '');
		} else {
			// Fallback to snake_case inputs
			if (empty($customer_data['customer_id'])) {
				throw new Exception('Customer ID is required to create a profile');
			}
			$merchantCustomerId = (string) $customer_data['customer_id'];
			$firstName = (string) ($customer_data['first_name'] ?? ($customer_data['firstName'] ?? ''));
			$lastName  = (string) ($customer_data['last_name']  ?? ($customer_data['lastName']  ?? ''));
			$email     = (string) ($customer_data['email'] ?? '');
			$phone     = (string) ($customer_data['phone'] ?? '');
		}

		$request = array(
			'merchantCustomerId' => $merchantCustomerId,
			'locale'             => 'en_US',
			'firstName'          => $firstName,
			'lastName'           => $lastName,
			'email'              => $email,
		);

		if ($phone !== '') {
			// Clean phone number (digits only)
			$request['phone'] = preg_replace('/[^0-9]/', '', $phone);
		}

		$endpoint = '/customervault/v1/profiles';

		try {
			$response = $this->make_request($endpoint, 'POST', $request);

			if (isset($response['id'])) {
				$this->log('Customer profile created successfully: ' . $response['id']);
				return array(
					'success'    => true,
					'profile_id' => $response['id'],
				);
			}

			throw new Exception('Failed to create customer profile - no ID returned');

		} catch (Exception $e) {
			$this->log('Customer profile creation failed. Error: ' . $e->getMessage(), 'error');
			throw $e;
		}
	}

	/**
	 * Get customer profile
	 * Added null check
	 */
	public function get_customer_profile($profile_id) {
		if (empty($profile_id)) {
			throw new Exception('Profile ID is required');
		}
		
		$endpoint = '/customervault/v1/profiles/' . $profile_id;
		
		try {
			$response = $this->make_request($endpoint, 'GET');
			return $response;
		} catch (Exception $e) {
			$this->log('Failed to get customer profile: ' . $e->getMessage(), 'error');
			throw $e;
		}
	}

/**
	 * Create permanent card from single-use token
	 * Converts a single-use token to a permanent card in the customer vault
	 * HANDLES DUPLICATE CARD ERROR (7503) by retrieving and using existing card
	 */
	public function create_permanent_card_from_token($profile_id, $single_use_token) {
		if (empty($profile_id)) {
			throw new Exception('Profile ID is required');
		}
		
		if (empty($single_use_token)) {
			throw new Exception('Single-use token is required');
		}
		
		$endpoint = '/customervault/v1/profiles/' . $profile_id . '/cards';
		
		$request = array(
			'singleUseToken' => $single_use_token
		);
		
		try {
			$response = $this->make_request($endpoint, 'POST', $request);
			
			if (isset($response['id']) && isset($response['paymentToken'])) {
				$this->log('Permanent card created from token successfully');
				
				return array(
					'success' => true,
					'card_id' => $response['id'],
					'payment_token' => $response['paymentToken'],
					'card_expiry' => isset($response['cardExpiry']) ? $response['cardExpiry'] : null,
					'card_type' => isset($response['cardType']) ? $response['cardType'] : '',
					'last_digits' => isset($response['lastDigits']) ? $response['lastDigits'] : '',
					'duplicate' => false
				);
			}
			
			throw new Exception('Failed to create permanent card from token');
			
		} catch (Exception $e) {
			$error_message = $e->getMessage();
			
			// Check if this is a duplicate card error (7503)
			if (strpos($error_message, '[7503]') !== false || strpos($error_message, 'already in use') !== false) {
				$this->log('Card already exists in vault (error 7503), attempting to retrieve existing card', 'info');
				
				// Extract the existing card ID from the error message
				// Format: "Card number already in use - a30398b7-91fb-4c70-b70a-1c2467dfd72d"
				if (preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $error_message, $matches)) {
					$existing_card_id = $matches[1];
					$this->log('Found existing card ID: ' . $existing_card_id);
					
					try {
						// Fetch the existing card details
						$card_endpoint = '/customervault/v1/profiles/' . $profile_id . '/cards/' . $existing_card_id;
						$existing_card = $this->make_request($card_endpoint, 'GET');
						
						if (isset($existing_card['id']) && isset($existing_card['paymentToken'])) {
							$this->log('Successfully retrieved existing card details');
							
							return array(
								'success' => true,
								'card_id' => $existing_card['id'],
								'payment_token' => $existing_card['paymentToken'],
								'card_expiry' => isset($existing_card['cardExpiry']) ? $existing_card['cardExpiry'] : null,
								'card_type' => isset($existing_card['cardType']) ? $existing_card['cardType'] : '',
								'last_digits' => isset($existing_card['lastDigits']) ? $existing_card['lastDigits'] : '',
								'duplicate' => true
							);
						}
					} catch (Exception $fetch_error) {
						$this->log('Failed to fetch existing card: ' . $fetch_error->getMessage(), 'error');
					}
				}
			}
			
			$this->log('Failed to create permanent card: ' . $error_message, 'error');
			return array(
				'success' => false,
				'message' => $error_message
			);
		}
	}

/**
	 * Get all cards from customer profile
	 */
	public function get_customer_cards($profile_id) {
		if (empty($profile_id)) {
			$this->log('get_customer_cards: Profile ID is required', 'error');
			return array();
		}
		
		$endpoint = '/customervault/v1/profiles/' . $profile_id . '/cards';
		
		try {
			$response = $this->make_request($endpoint, 'GET');
			if (is_array($response) && !isset($response['error'])) {
				$this->log('get_customer_cards: Retrieved ' . count($response) . ' cards for profile ' . $profile_id);
				return $response;
			}
			$this->log('get_customer_cards: No cards found or invalid response', 'info');
			return array();
		} catch (Exception $e) {
			$this->log('get_customer_cards: Failed to retrieve cards: ' . $e->getMessage(), 'error');
			return array();
		}
	}

	/**
	 * Check if card already exists in profile (proactive duplicate check)
	 */
	public function check_duplicate_card($profile_id, $last4, $exp_month, $exp_year) {
		if (empty($profile_id) || empty($last4)) {
			return false;
		}
		
		$existing_cards = $this->get_customer_cards($profile_id);
		if (empty($existing_cards)) {
			return false;
		}
		
		$exp_year_normalized = (int)$exp_year;
		if ($exp_year_normalized < 100) {
			$exp_year_normalized = ($exp_year_normalized >= 70) ? (1900 + $exp_year_normalized) : (2000 + $exp_year_normalized);
		}
		
		foreach ($existing_cards as $card) {
			if (isset($card['lastDigits']) && $card['lastDigits'] === $last4) {
				if (isset($card['cardExpiry']['month']) && isset($card['cardExpiry']['year'])) {
					$card_exp_month = (int)$card['cardExpiry']['month'];
					$card_exp_year = (int)$card['cardExpiry']['year'];
					
					if ($card_exp_month === (int)$exp_month && $card_exp_year === $exp_year_normalized) {
						$this->log('check_duplicate_card: Found duplicate card - ID: ' . $card['id']);
						return array(
							'card_id' => $card['id'],
							'payment_token' => $card['paymentToken'],
							'card_type' => $card['cardType'] ?? '',
							'last_digits' => $card['lastDigits'],
							'card_expiry' => $card['cardExpiry']
						);
					}
				}
			}
		}
		
		$this->log('check_duplicate_card: No duplicate found');
		return false;
	}

	/**
	 * Delete card from customer profile
	 */
	public function delete_card_from_profile($profile_id, $card_id) {
		if (empty($profile_id) || empty($card_id)) {
			throw new Exception('Profile ID and Card ID are required');
		}
		
		$endpoint = '/customervault/v1/profiles/' . $profile_id . '/cards/' . $card_id;
		
		try {
			$response = $this->make_request($endpoint, 'DELETE');
			$this->log('Card deleted from profile successfully');
			return true;
		} catch (Exception $e) {
			$this->log('Failed to delete card from profile: ' . $e->getMessage(), 'error');
			throw $e;
		}
	}

	/**
	 * Process payment with tokenized card
	 * FIXED: Use the permanent payment token for processing
	 */
	public function process_tokenized_payment($payment_data, $account_id) {
		if (empty($account_id)) {
			throw new Exception('Account ID is required for payment processing');
		}

		// Validate expected shape of $payment_data to fail fast with clear errors.
		if (!is_array($payment_data)) {
			throw new Exception('Payment data must be an array.');
		}
		// Allow either int or digit-string; normalize to int for the API call.
		if (
			!isset($payment_data['amount'])
			|| ( !is_int($payment_data['amount']) && !ctype_digit((string) $payment_data['amount']) )
			|| (int) $payment_data['amount'] <= 0
		) {
			throw new Exception('Payment data is missing a valid amount in cents.');
		}
		if (empty($payment_data['merchantRefNum'])) {
			throw new Exception('Payment data is missing merchantRefNum.');
		}
		if (!isset($payment_data['card']) || !is_array($payment_data['card']) || empty($payment_data['card']['paymentToken'])) {
			throw new Exception('Payment data is missing card.paymentToken.');
		}
		$payment_data['amount'] = (int) $payment_data['amount'];
		
		$endpoint = '/cardpayments/v1/accounts/' . $account_id . '/auths';
		
		try {
			$response = $this->make_request($endpoint, 'POST', $payment_data);
			
			if (isset($response['status']) && $response['status'] === 'COMPLETED') {
				return $response;
			}
			
			throw new Exception($response['error']['message'] ?? 'Payment failed');
			
		} catch (Exception $e) {
			$this->log('Tokenized payment failed: ' . $e->getMessage(), 'error');
			throw $e;
		}
	}

	/**
	 * Process payment with saved token
	 * 
	 * @param WC_Order $order
	 * @param array $payment_data containing payment_token, cvv, card_type, last4
	 * @return array
	 */
	public function process_payment_with_token($order, $payment_data) {
		$order_id = $order->get_id();
		$amount = $order->get_total();
		$currency = $order->get_currency();
		$account_id = $this->get_account_id($currency);
		
		if (empty($account_id)) {
			throw new Exception('No account ID configured for ' . $currency);
		}
		
		if (empty($payment_data['payment_token'])) {
			throw new Exception('Payment token is required');
		}

		// Build the request for tokenized payment
		$request = array(
			'merchantRefNum' => 'order_' . $order_id . '_' . time(),
			'amount' 		 => (int) round( (float) $amount * 100 ),
			// Honor gateway-provided flag; if not provided, safely fall back to "sale" (capture immediately).
			// (WC_Gateway_Paysafe should pass settleWithAuth for saved-card flow.)
			'settleWithAuth' => array_key_exists('settleWithAuth', $payment_data)
				? (bool) $payment_data['settleWithAuth'] : true,
			'card' 			 => array(
				'paymentToken' => $payment_data['payment_token']
			)
		);

		// Add billing details
		$country = $order->get_billing_country();
		$state = $order->get_billing_state();
		
		$request['billingDetails'] = array(
			'street' => $order->get_billing_address_1(),
			'city' => $order->get_billing_city(),
			'state' => $this->format_state_code($state, $country),
			'country' => $country,
			'zip' => str_replace(' ', '', $order->get_billing_postcode())
		);
		
		// Add CVV if provided (for saved cards that require CVV)
		if (!empty($payment_data['cvv'])) {
			$cvv = preg_replace('/\D+/', '', (string) $payment_data['cvv']);
			if ($cvv !== '' && strlen($cvv) >= 3 && strlen($cvv) <= 4) {
				$request['card']['cvv'] = $cvv;
			}
		}

		// Log the payment attempt
		$this->log('Processing tokenized payment for order ' . $order_id);

		// Make the API request
		$endpoint = '/cardpayments/v1/accounts/' . $account_id . '/auths';
		
		try {
			$response = $this->make_request($endpoint, 'POST', $request);
			
			if (isset($response['status']) && $response['status'] === 'COMPLETED') {
				if (isset($response['avsResponse'])) {
					$avs = strtoupper($response['avsResponse']);
					// AVS codes that indicate address mismatch
					$avs_fail_codes = array('N', 'A', 'Z', 'W', 'C', 'I', 'P');
					if (in_array($avs, $avs_fail_codes)) {
						$this->log('AVS Check Failed: ' . $avs . ' - Declining saved card transaction', 'error');
						throw new Exception('AVS_FAILED|Address Verification Failed. The billing address doesn\'t match your card\'s registered address.');
					}
				}
				
				// Check CVV response even on completed auths
				if (isset($response['cvvVerification'])) {
					$cvv = strtoupper($response['cvvVerification']);
					// CVV codes that indicate CVV mismatch
					$cvv_fail_codes = array('N', 'P', 'S', 'U');
					if (in_array($cvv, $cvv_fail_codes)) {
						$this->log('CVV Check Failed: ' . $cvv . ' - Declining saved card transaction', 'error');
						throw new Exception('CVV_FAILED|Security Code Invalid. The CVV/CVC on your card is incorrect.');
					}
				}
				$this->log('Tokenized payment successful for order ' . $order_id);
				return array(
					'result' => 'success',
					// Keep returning the auth id for BC; many sites store this now.
					'transaction_id' => $response['id'],
					// New: expose settlement id when available so refunds can target it directly.
					'settlement_id'  => $this->extract_settlement_id_from_response($response) ?: null,
					'auth_code' => $response['authCode'] ?? '',
					'card_type' => $payment_data['card_type'] ?? '',
					'card_suffix' => $payment_data['last4'] ?? '',
					'raw_response' => $response
				);
			}

			// Payment not completed - process through extract_error_message to handle custom messages
			$http_code = 402; // Payment Required (declined/failed)
			$error_message = $this->extract_error_message($response, $http_code);
			throw new Exception($error_message);
			
		} catch (Exception $e) {
			$this->log('Payment with token failed for order ' . $order_id . '. Error: ' . $e->getMessage(), 'error');
			throw $e;
		}
	}

	/**
	 * Create single-use token for card
	 */
	public function create_single_use_token($card_data) {
		// Guard: tokenization requires dedicated credentials.
		if (empty($this->single_use_token_username) || empty($this->single_use_token_password)) {
			throw new Exception(
				'Single-use token API credentials are not configured. ' .
				'Please set both Single-Use Token Username and Password.'
			);
		}

		if (empty($card_data['number'])) {
			throw new Exception('Card number is required');
		}
		
		$request = array(
			'card' => array(
				'cardNum' => preg_replace('/\D+/', '', (string) $card_data['number']),
				'cardExpiry' => array(
					'month' => intval($card_data['exp_month']),
					'year' => intval($card_data['exp_year'])
				),
				'cvv' => preg_replace('/\D+/', '', (string) $card_data['cvv'])
			)
		);
		// Normalize 2-digit years (MM/YY input) to 4-digit YYYY expected by Paysafe
		if ($request['card']['cardExpiry']['year'] < 100) {
			$request['card']['cardExpiry']['year'] += 2000; // e.g., 25 -> 2025
		}
		// Basic client-side validation for clearer errors
		if (!preg_match('/^\d{3,4}$/', $request['card']['cvv'])) {
			throw new Exception('Invalid CVV');
		}
		$month = $request['card']['cardExpiry']['month'];
		$year  = $request['card']['cardExpiry']['year'];
		if ($month < 1 || $month > 12) {
			throw new Exception('Invalid expiry month');
		}
		$currentYear = intval(date('Y'));
		if ($year < $currentYear || $year > ($currentYear + 25)) {
			throw new Exception('Invalid expiry year');
		}

		// Validate card number length
		if (strlen($request['card']['cardNum']) < 13 || strlen($request['card']['cardNum']) > 19) {
			throw new Exception('Invalid card number length');
		}

		// Add holder name if provided
		if (!empty($card_data['name'])) {
			$request['card']['holderName'] = $card_data['name'];
		}
		
		$endpoint = '/customervault/v1/singleusetokens';
		
		try {
			$response = $this->make_request($endpoint, 'POST', $request, true);
			
			if (isset($response['paymentToken'])) {
				$this->log('Single-use token created successfully');
				return $response['paymentToken'];
			}
			
			throw new Exception('Failed to create payment token');
			
		} catch (Exception $e) {
			$this->log('Token creation failed. Error: ' . $e->getMessage(), 'error');
			throw $e;
		}
	}

	/**
	 * Convert WooCommerce state codes to 2-letter codes for Canadian provinces
	 */
	private function format_state_code($state, $country) {
		if (empty($state)) {
			return '';
		}
		
		$country = strtoupper((string) $country);

		if ($country === 'CA') {
			$province_map = array(
				'AB' => 'AB', 'BC' => 'BC', 'MB' => 'MB', 'NB' => 'NB',
				'NL' => 'NL', 'NT' => 'NT', 'NS' => 'NS', 'NU' => 'NU',
				'ON' => 'ON', 'PE' => 'PE', 'QC' => 'QC', 'SK' => 'SK',
				'YT' => 'YT',
				// Full names
				'alberta' => 'AB',
				'british columbia' => 'BC',
				'manitoba' => 'MB',
				'new brunswick' => 'NB',
				'newfoundland' => 'NL',
				'newfoundland and labrador' => 'NL',
				'northwest territories' => 'NT',
				'nova scotia' => 'NS',
				'nunavut' => 'NU',
				'ontario' => 'ON',
				'prince edward island' => 'PE',
				'quebec' => 'QC',
				'québec' => 'QC',
				'saskatchewan' => 'SK',
				'yukon' => 'YT',
				'yukon territory' => 'YT'
			);

			// Check if already 2-letter code
			if (strlen($state) === 2) {
				$upper = strtoupper($state);
				if (isset($province_map[$upper])) {
					return $upper;
				}
			}

			// Check full name
			$state_lower = strtolower($state);
			if (isset($province_map[$state_lower])) {
				return $province_map[$state_lower];
			}

			// Default to uppercase first 2 letters
			return strtoupper(substr($state, 0, 2));
		} elseif ($country === 'US') {
			// Normalize US states/territories to USPS 2-letter abbreviations.
			$state_map = array(
				// States
				'AL'=>'AL','AK'=>'AK','AZ'=>'AZ','AR'=>'AR','CA'=>'CA','CO'=>'CO','CT'=>'CT','DE'=>'DE','FL'=>'FL','GA'=>'GA',
				'HI'=>'HI','ID'=>'ID','IL'=>'IL','IN'=>'IN','IA'=>'IA','KS'=>'KS','KY'=>'KY','LA'=>'LA','ME'=>'ME','MD'=>'MD',
				'MA'=>'MA','MI'=>'MI','MN'=>'MN','MS'=>'MS','MO'=>'MO','MT'=>'MT','NE'=>'NE','NV'=>'NV','NH'=>'NH','NJ'=>'NJ',
				'NM'=>'NM','NY'=>'NY','NC'=>'NC','ND'=>'ND','OH'=>'OH','OK'=>'OK','OR'=>'OR','PA'=>'PA','RI'=>'RI','SC'=>'SC',
				'SD'=>'SD','TN'=>'TN','TX'=>'TX','UT'=>'UT','VT'=>'VT','VA'=>'VA','WA'=>'WA','WV'=>'WV','WI'=>'WI','WY'=>'WY',
				// Territories & DC
				'DC'=>'DC','PR'=>'PR','GU'=>'GU','VI'=>'VI','AS'=>'AS','MP'=>'MP'
			);
			if (strlen($state) === 2) {
				$upper = strtoupper($state);
				if (isset($state_map[$upper])) {
					return $upper;
				}
			}
			// Case-insensitive full-name mapping
			$name_map = array(
				'alabama'=>'AL','alaska'=>'AK','arizona'=>'AZ','arkansas'=>'AR','california'=>'CA','colorado'=>'CO','connecticut'=>'CT',
				'delaware'=>'DE','florida'=>'FL','georgia'=>'GA','hawaii'=>'HI','idaho'=>'ID','illinois'=>'IL','indiana'=>'IN','iowa'=>'IA',
				'kansas'=>'KS','kentucky'=>'KY','louisiana'=>'LA','maine'=>'ME','maryland'=>'MD','massachusetts'=>'MA','michigan'=>'MI',
				'minnesota'=>'MN','mississippi'=>'MS','missouri'=>'MO','montana'=>'MT','nebraska'=>'NE','nevada'=>'NV','new hampshire'=>'NH',
				'new jersey'=>'NJ','new mexico'=>'NM','new york'=>'NY','north carolina'=>'NC','north dakota'=>'ND','ohio'=>'OH','oklahoma'=>'OK',
				'oregon'=>'OR','pennsylvania'=>'PA','rhode island'=>'RI','south carolina'=>'SC','south dakota'=>'SD','tennessee'=>'TN',
				'texas'=>'TX','utah'=>'UT','vermont'=>'VT','virginia'=>'VA','washington'=>'WA','west virginia'=>'WV','wisconsin'=>'WI','wyoming'=>'WY',
				'district of columbia'=>'DC','washington dc'=>'DC','d.c.'=>'DC','dc'=>'DC',
				'puerto rico'=>'PR','guam'=>'GU','u.s. virgin islands'=>'VI','us virgin islands'=>'VI','american samoa'=>'AS',
				'northern mariana islands'=>'MP'
			);
			$lookup = strtolower(trim($state));
			if (isset($name_map[$lookup])) {
				return $name_map[$lookup];
			}
			// Fallback: best-effort to a 2-letter code to avoid upstream rejects.
			return strtoupper(substr($state, 0, 2));
		}

		// For other countries, pass through (normalize 2-letter if present)
		return strlen($state) === 2 ? strtoupper($state) : $state;
	}

	/**
	 * Process refund
	 */
	public function process_refund($order, $refund_data) {
		$order_id = $order->get_id();
		$amount = $refund_data['amount'] ?? 0;
		// May be a settlement id OR an auth id depending on upstream storage
		$transaction_id = $refund_data['transaction_id'] ?? '';
		$explicit_settlement_id = $refund_data['settlement_id'] ?? '';
		$currency = $order->get_currency();
		$account_id = $this->get_account_id($currency);
		
		if (empty($account_id)) {
			throw new Exception('No account ID configured for ' . $currency);
		}
		
		if ($transaction_id === '' && $explicit_settlement_id === '') {
			throw new Exception('A settlement_id or original transaction_id is required for refund');
		}
		
		if (!is_numeric($amount) || (float) $amount <= 0) {
			throw new Exception('Invalid refund amount');
		}
		
		$request = array(
			'merchantRefNum' => 'refund_' . $order_id . '_' . time(),
			'amount' => (int) round( (float) $amount * 100 )
		);
		
		// Add reason if provided
		if (!empty($refund_data['reason'])) {
			$request['reason'] = substr($refund_data['reason'], 0, 255); // Limit to 255 chars
		}
		
		// Prefer explicit settlement id when present, otherwise assume the provided id
		// is a settlement id first (happy path). If that fails (404/not found), try to
		// resolve a settlement from an auth id and retry.
		$target_settlement_id = $explicit_settlement_id !== '' ? $explicit_settlement_id : $transaction_id;
		$endpoint = '/cardpayments/v1/accounts/' . $account_id . '/settlements/' . $target_settlement_id . '/refunds';
		
		try {
			$response = $this->make_request($endpoint, 'POST', $request);
			
			if (isset($response['status']) && in_array($response['status'], ['COMPLETED', 'PENDING'])) {
				return array(
					'success' => true,
					'refund_id' => $response['id'],
					'status' => $response['status'],
					'raw_response' => $response
				);
			}
			
			throw new Exception($response['error']['message'] ?? 'Refund failed');
			
		} catch (Exception $e) {
			// Legacy compatibility: if auth ID was stored as "transaction_id", resolve settlement and retry once.
			$msg = $e->getMessage();
			$can_retry = ($explicit_settlement_id === '' && $transaction_id !== '');
			$looks_like_missed_settlement = (stripos($msg, '404') !== false) || (stripos($msg, 'not found') !== false);
			if ($can_retry && $looks_like_missed_settlement) {
				$resolved = $this->resolve_settlement_id_from_auth($account_id, $transaction_id);
				if ($resolved !== '') {
					try {
						$endpoint_retry = '/cardpayments/v1/accounts/' . $account_id . '/settlements/' . $resolved . '/refunds';
						$response = $this->make_request($endpoint_retry, 'POST', $request);
						if (isset($response['status']) && in_array($response['status'], ['COMPLETED', 'PENDING'])) {
							return array(
								'success' => true,
								'refund_id' => $response['id'],
								'status' => $response['status'],
								'raw_response' => $response
							);
						}
					} catch (Exception $e2) {
						// fall through to original error
					}
				}
			}
			$this->log('Refund failed for order ' . $order_id . '. Error: ' . $e->getMessage(), 'error');
			throw $e;
		}
	}

	/**
	 * Extract a settlement id from common Paysafe response shapes.
	 * Safe no-op if not present.
	 */
	private function extract_settlement_id_from_response($response) {
		if (!is_array($response)) {
			return '';
		}
		if (!empty($response['settlementId'])) {
			return (string) $response['settlementId'];
		}
		if (!empty($response['links']) && is_array($response['links'])) {
			foreach ($response['links'] as $link) {
				if (is_array($link) && !empty($link['href'])) {
					$href = (string) $link['href'];
					if (strpos($href, '/settlements/') !== false) {
						// Grab the segment after '/settlements/' up to ? or end.
						$after = explode('/settlements/', $href, 2)[1] ?? '';
						$after = preg_split('/[?#]/', $after, 2)[0];
						$id = trim($after, "/ \t\n\r\0\x0B");
						if ($id !== '') {
							return $id;
						}
					}
				}
			}
		}
		return '';
	}

	/**
	 * Given an auth id, try to discover its created settlement id.
	 * Returns '' if it cannot be determined (no exception thrown).
	 */
	private function resolve_settlement_id_from_auth($account_id, $auth_id) {
		try {
			$auth = $this->make_request('/cardpayments/v1/accounts/' . $account_id . '/auths/' . $auth_id, 'GET');
			return $this->extract_settlement_id_from_response($auth);
		} catch (Exception $e) {
			// Don’t mask original refund error paths; just fail soft here.
			$this->log('Could not resolve settlement id from auth ' . $auth_id . ': ' . $e->getMessage(), 'warning');
			return '';
		}
	}

	/**
	 * Verify webhook signature
	 * 
	 * @param string $payload Raw request body
	 * @param string $signature Signature from header
	 * @return bool
	 */
	public function verify_webhook_signature($payload, $signature) {
		// Get webhook secret from settings
		$webhook_secret = $this->get_webhook_secret();
		
		if (empty($webhook_secret)) {
			$this->log('Webhook secret not configured', 'error');
			return false;
		}

		// Calculate expected signature
		$expected_signature = base64_encode(hash_hmac('sha256', $payload, $webhook_secret, true));

		// Compare signatures (timing-safe comparison)
		if (!hash_equals((string) $expected_signature, (string) trim((string) $signature))) {
			$this->log('Webhook signature verification failed', 'error');
			return false;
		}
		
		return true;
	}

	/**
	 * Get webhook secret
	 * 
	 * @return string
	 */
	private function get_webhook_secret() {
		// This should be stored securely, ideally in wp-config.php
		if (defined('PAYSAFE_WEBHOOK_SECRET')) {
			return PAYSAFE_WEBHOOK_SECRET;
		}

		// Fallback to database option
		$settings = get_option('woocommerce_paysafe_settings', array());
		return $settings['webhook_secret'] ?? '';
	}

	/**
	 * Test API connection
	 */
	public function test_connection() {
		try {
			$endpoint = '/cardpayments/monitor';
			$response = $this->make_request($endpoint, 'GET', null, false);
			
			if (isset($response['status'])) {
				$message = 'Connection successful. API Status: ' . $response['status'];
				if ($this->is_merrco_account) {
					$endpoint_url = $this->get_api_endpoint();
					$message .= ' (Using ' . parse_url($endpoint_url, PHP_URL_HOST) . ')';
				}
				return array(
					'success' => true,
					'message' => $message
				);
			}
			
			return array(
				'success' => false,
				'message' => 'Unexpected response from API'
			);
			
		} catch (Exception $e) {
			return array(
				'success' => false,
				'message' => $e->getMessage()
			);
		}
	}

	/**
	 * Test Auth Endpoint (Minimal test transaction)
	 */
	public function test_auth_endpoint() {
		// Never run a synthetic test transaction against LIVE credentials.
		if (!$this->sandbox_mode) {
			return array(
				'success' => false,
				'message' => 'Refusing to run test transaction on LIVE environment. Switch to Sandbox to test.'
			);
		}
		$account_id = $this->account_id_cad;
		
		if (empty($account_id)) {
			return array(
				'success' => false,
				'message' => 'Missing Cards Account ID (CAD)'
			);
		}
		
		$auth_url = '/cardpayments/v1/accounts/' . $account_id . '/auths';

		// Use future expiry date for test
		$test_data = array(
			'merchantRefNum' => 'test_' . time(),
			'amount' => 100,
			'settleWithAuth' => false,
			'card' => array(
				'cardNum' => '4111111111111111',
				'cardExpiry' => array(
					'month' => 12,
					'year' => date('Y') + 2 // Always 2 years in future
				),
				'cvv' => '123'
			)
		);
		
		try {
			$response = $this->make_request($auth_url, 'POST', $test_data);
			
			if (isset($response['status'])) {
				return array(
					'success' => true,
					'message' => 'Auth endpoint works! Test transaction successful.'
				);
			}
			
			return array(
				'success' => false,
				'message' => 'Unexpected response from auth endpoint'
			);
			
		} catch (Exception $e) {
			$message = $e->getMessage();

			// These errors actually mean the endpoint is working
			if (strpos($message, '[402]') !== false || 
				strpos($message, 'declined') !== false ||
				strpos($message, 'DECLINED') !== false) {
				return array(
					'success' => true,
					'message' => 'Auth endpoint accessible! (Payment validation works correctly)'
				);
			} else if (strpos($message, '[401]') !== false || strpos($message, '[403]') !== false) {
				return array(
					'success' => false,
					'message' => 'Authentication error: ' . $message
				);
			}
			
			return array(
				'success' => false,
				'message' => 'Error: ' . $message
			);
		}
	}
}

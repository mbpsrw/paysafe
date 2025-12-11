<?php
/**
 * Paysafe Tokenization Handler
 * File: /includes/class-paysafe-tokenization.php
 * Manages saved cards and tokenization for the Paysafe gateway
 * @package WooCommerce_Paysafe_Gateway
 * @version 1.0.4
 * Last updated: 2025-11-25
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Paysafe_Tokenization class
 */
class Paysafe_Tokenization {
	
	/**
	 * Gateway instance
	 * @var WC_Gateway_Paysafe
	 */
	private $gateway;
	
	/**
	 * Constructor
	 * @param WC_Gateway_Paysafe $gateway
	 */
	public function __construct($gateway) {
		$this->gateway = $gateway;
		
		// Add hooks for My Account
		// add_filter('woocommerce_payment_methods_list_item', array($this, 'get_account_saved_payment_methods_list_item'), 10, 2);
		add_action('woocommerce_payment_token_deleted', array($this, 'handle_token_deleted'), 10, 2);
		add_filter('woocommerce_payment_token_get_data', array($this, 'get_token_payment_data'), 10, 2);
		
		// Fix display of saved cards
		add_filter('woocommerce_get_credit_card_type_label', array($this, 'get_card_type_label'), 10, 1);
		add_filter('woocommerce_payment_token_get_display_name', array($this, 'get_token_display_name'), 10, 2);
		
		// Add action to handle adding payment method from My Account
		add_action('wp_ajax_wc_paysafe_add_payment_method', array($this, 'ajax_add_payment_method'));
		add_action('wp_ajax_nopriv_wc_paysafe_add_payment_method', array($this, 'ajax_add_payment_method'));
		
		// Add user profile fields in admin
		add_action('show_user_profile', array($this, 'add_customer_profile_fields'));
		add_action('edit_user_profile', array($this, 'add_customer_profile_fields'));
		add_action('personal_options_update', array($this, 'save_customer_profile_fields'));
		add_action('edit_user_profile_update', array($this, 'save_customer_profile_fields'));
		
		// Handle adding payment method from My Account
		add_action('woocommerce_add_payment_method_' . $gateway->id . '_success', array($this, 'handle_add_payment_method'), 10, 2);
		
		// AJAX handler for admin token deletion
		add_action('wp_ajax_wc_paysafe_delete_token', array($this, 'ajax_delete_token'));
	}
	
	/**
	 * Get saved payment methods list item for My Account
	 * FIXED: Properly display card information
	 * 
	 * @param array $item
	 * @param WC_Payment_Token $payment_token
	 * @return array
	 */
public function get_account_saved_payment_methods_list_item($item, $payment_token) {
		// CRITICAL FIX: Ensure $item is always an array with proper structure
		// WooCommerce sometimes passes a string instead of array which breaks payment-methods.php
		if (!is_array($item)) {
			$item = array(
				'method' => '',  // STRING not array!
				'expires' => '',
				'actions' => array()
			);
		}
		
		if ($payment_token->get_gateway_id() !== $this->gateway->id) {
			return $item;
		}
		
		// Check if it's our custom token type
		if (!($payment_token instanceof WC_Payment_Token_Paysafe)) {
			// For standard CC tokens saved by our gateway
			if ($payment_token instanceof WC_Payment_Token_CC) {
				$card_type = $payment_token->get_card_type();
				$last4 = $payment_token->get_last4();
				$expiry_month = $payment_token->get_expiry_month();
				$expiry_year = $payment_token->get_expiry_year();
				
				// Build the display
				$display = sprintf(
					'%s ending in %s',
					wc_get_credit_card_type_label($card_type),
					$last4
				);
				$item['method'] = $display;
				$item['expires'] = sprintf('%02d/%s', $expiry_month, substr($expiry_year, -2));
				
				// Add actions
				$item['actions'] = $this->get_token_actions($payment_token);
			}
			return $item;
		}
		
		// For our custom Paysafe tokens
		$card_type = $payment_token->get_card_type();
		$last4 = $payment_token->get_last4();
		$expiry_month = $payment_token->get_expiry_month();
		$expiry_year = $payment_token->get_expiry_year();
		
		// Get card type label
		$card_type_label = $this->get_proper_card_type_label($card_type);
		
		// Build card icon if available
		$icon_url = PAYSAFE_PLUGIN_URL . 'assets/images/card-' . $card_type . '.svg';
		$icon_html = '';
		
		if (file_exists(PAYSAFE_PLUGIN_PATH . 'assets/images/card-' . $card_type . '.svg')) {
			$icon_html = '<img src="' . esc_url($icon_url) . '" alt="' . esc_attr($card_type_label) . '" style="height: 1.5em; width: auto; vertical-align: middle; margin-right: 0.5em;" />';
		}
		
		// Set the method display with icon
		$item['method'] = trim(
			$icon_html . ' ' . esc_html($card_type_label) .
			($last4 ? ' ' . sprintf(__('ending in %s','paysafe-payment'), esc_html($last4)) : '')
		);
		
		// Set expiry
		$item['expires'] = sprintf('%02d/%s', $expiry_month, substr($expiry_year, -2));
		
		// Add actions
		$item['actions'] = $this->get_token_actions($payment_token);
		
		return $item;
	}
	
	/**
	 * Get proper card type label
	 */
	private function get_proper_card_type_label($card_type) {
		$labels = array(
			'visa' => __('Visa', 'paysafe-payment'),
			'mastercard' => __('Mastercard', 'paysafe-payment'),
			'amex' => __('American Express', 'paysafe-payment'),
			'discover' => __('Discover', 'paysafe-payment'),
			'jcb' => __('JCB', 'paysafe-payment'),
			'diners' => __('Diners Club', 'paysafe-payment'),
			'card' => __('Credit Card', 'paysafe-payment')
		);
		
		return isset($labels[$card_type]) ? $labels[$card_type] : $labels['card'];
	}
	
	/**
	 * Get token actions for display
	 */
	private function get_token_actions($token) {
		$actions = array();
		
		if (!$token->is_default()) {
			$actions['default'] = array(
				'url' => wp_nonce_url(
					add_query_arg(
						array(
							'set-default-payment-method' => $token->get_id()
						),
						wc_get_endpoint_url('payment-methods')
					),
					'set-default-payment-method-' . $token->get_id()
				),
				'name' => __('Make default', 'paysafe-payment')
			);
		}
		
		$actions['delete'] = array(
			'url' => wp_nonce_url(
				add_query_arg(
					array(
						'delete-payment-method' => $token->get_id()
					),
					wc_get_endpoint_url('payment-methods')
				),
				'delete-payment-method-' . $token->get_id()
			),
			'name' => __('Delete', 'paysafe-payment')
		);
		
		return $actions;
	}
	
	/**
	 * Get card type label filter
	 */
	public function get_card_type_label($label) {
		// This ensures proper labeling
		return $label;
	}
	
	/**
	 * Get token display name
	 */
	public function get_token_display_name($display, $token) {
		if ($token->get_gateway_id() !== $this->gateway->id) {
			return $display;
		}
		
		if ($token instanceof WC_Payment_Token_Paysafe) {
			$card_type = $token->get_card_type();
			$last4 = $token->get_last4();
			$expiry_month = $token->get_expiry_month();
			$expiry_year = $token->get_expiry_year();
			
			$card_type_label = $this->get_proper_card_type_label($card_type);
			
			return sprintf(
				'%s ending in %s (expires %02d/%s)',
				$card_type_label,
				$last4,
				$expiry_month,
				substr($expiry_year, -2)
			);
		}
		
		return $display;
	}
	
	/**
	 * Handle add payment method from My Account
	 */
	public function handle_add_payment_method($token_id, $args) {
		// This is called when a payment method is successfully added from My Account
		if ($this->gateway->get_option('enable_debug') === 'yes') {
			$this->log('Payment method added from My Account. Token ID: ' . $token_id);
		}
	}
	
	/**
	 * Create or get customer profile in Paysafe Vault
	 * 
	 * @param int $user_id
	 * @param array $customer_data
	 * @return string|false Profile ID or false on failure
	 */
	public function create_or_get_customer_profile($user_id, $customer_data = array()) {
		// Check if profile already exists
		$profile_id = get_user_meta($user_id, '_paysafe_customer_profile_id', true);
		
		if ($profile_id) {
			// Verify profile still exists in Paysafe
			if ($this->verify_profile_exists($profile_id)) {
				return $profile_id;
			}
			// Profile doesn't exist anymore, remove meta and create new
			delete_user_meta($user_id, '_paysafe_customer_profile_id');
		}
		
		// Get user data if not provided
		if (empty($customer_data)) {
			$user = get_userdata($user_id);
			$customer_data = array(
				'email' => $user->user_email,
				'firstName' => get_user_meta($user_id, 'first_name', true),
				'lastName' => get_user_meta($user_id, 'last_name', true),
			);
		}
		
		// Create API instance
		$api = $this->get_api_instance();
		
		try {
			// Create customer profile in Paysafe
			$request_data = array(
				'merchantCustomerId' => $this->gateway->get_option('vault_prefix') . $user_id,
				'locale' => 'en_US',
				'firstName' => $customer_data['firstName'],
				'lastName' => $customer_data['lastName'],
				'email' => $customer_data['email']
			);
			
			// Add phone if available
			if (!empty($customer_data['phone'])) {
				$request_data['phone'] = $customer_data['phone'];
			}
			
			$response = $api->create_customer_profile($request_data);
			
			if (isset($response['profile_id'])) {
				// Save profile ID to user meta
				update_user_meta($user_id, '_paysafe_customer_profile_id', $response['profile_id']);
				
				// Log success
				if ($this->gateway->get_option('enable_debug') === 'yes') {
					$this->log('Created customer profile for user ' . $user_id . ': ' . $response['profile_id']);
				}
				
				return $response['profile_id'];
			}
			
		} catch (Exception $e) {
			$this->log('Error creating customer profile: ' . $e->getMessage(), 'error');
			return false;
		}
		
		return false;
	}
	
	/**
	 * Process payment with saved token
	 * 
	 * @param WC_Order $order
	 * @param WC_Payment_Token_Paysafe $token
	 * @return array
	 */
	public function process_payment_with_token($order, $token) {
		$api = $this->get_api_instance();
		
		// Get payment token from the saved card
		$payment_token = $token->get_paysafe_payment_token();
		$profile_id = $token->get_paysafe_profile_id();
		
		if (!$payment_token || !$profile_id) {
			throw new Exception(__('Invalid saved card data', 'paysafe-payment'));
		}
		
		try {
			// Build payment request using the permanent payment token
			$request = array(
				'merchantRefNum' => 'order_' . $order->get_id() . '_' . time(),
				'amount' => intval($order->get_total() * 100), // Convert to cents
				'settleWithAuth' => ($this->gateway->get_option('authorization_type') === 'sale'),
				'card' => array(
					'paymentToken' => $payment_token
				),
				'profile' => array(
					'id' => $profile_id
				)
			);
			
			// Add CVV if required and provided (align input name with checkout)
			$cvv_field = isset($_POST['paysafe_saved_card_cvv'])
				? wc_clean( wp_unslash( $_POST['paysafe_saved_card_cvv'] ) )
				: ( isset($_POST['paysafe_cvv']) ? wc_clean( wp_unslash( $_POST['paysafe_cvv'] ) ) : '' );

			if ( $this->gateway->get_option('require_cvv_with_token') === 'yes' && ! empty( $cvv_field ) ) {
				$request['card']['cvv'] = sanitize_text_field( $cvv_field );
			}
			
			// Get currency-specific account ID
			$currency = $order->get_currency();
			$account_id = $this->get_account_id_for_currency($currency);
			
			// Process the payment
			$response = $api->process_tokenized_payment($request, $account_id);
			
			if (isset($response['status']) && $response['status'] === 'COMPLETED') {
				// Payment successful
				$order->payment_complete($response['id']);
				
				// Add order note
				$order->add_order_note(sprintf(
					__('Paysafe payment completed using saved card ending in %s. Transaction ID: %s', 'paysafe-payment'),
					$token->get_last4(),
					$response['id']
				));
				
				// Store transaction data
				$order->update_meta_data('_paysafe_transaction_id', $response['id']);
				$order->update_meta_data('_paysafe_card_type', $token->get_card_type());
				$order->update_meta_data('_paysafe_card_suffix', $token->get_last4());
				if (isset($response['authCode'])) {
					$order->update_meta_data('_paysafe_auth_code', $response['authCode']);
				}
				$order->save();
				
				return array(
					'result' => 'success',
					'redirect' => $this->gateway->get_return_url($order)
				);
			} else {
				// Normalize Paysafe error into gateway custom error buckets (AVS/CVV/etc.).
				$error_code  = isset($response['error']['code']) ? (string) $response['error']['code'] : '';
				$raw_message = isset($response['error']['message']) ? $response['error']['message'] : __('Payment failed', 'paysafe-payment');
				$msg         = $raw_message;

				if ($this->gateway && method_exists($this->gateway, 'get_custom_error_message')) {
					if ($error_code !== '') {
						$custom_msg = $this->gateway->get_custom_error_message($error_code, '');
						if (!empty($custom_msg)) {
							$msg = $custom_msg;
						}
					}

				// If we still have the raw Paysafe AVS text, normalize it to AVS_FAILED.
				if ($msg === $raw_message && stripos($raw_message, 'failed the avs check') !== false) {
					$avs_msg = $this->gateway->get_custom_error_message('AVS_FAILED', '');
					if (!empty($avs_msg)) {
						$msg = $avs_msg;
					} else {
						// Fallback: use generic message instead of raw Paysafe text
						$msg = 'Address Verification Failed';
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

				throw new Exception($msg);
			}
			
		} catch (Exception $e) {
			$this->log('Tokenized payment failed: ' . $e->getMessage(), 'error');
			throw $e;
		}
	}
	
	/**
	 * Delete token from vault when deleted in WooCommerce
	 * 
	 * @param string $token_id
	 * @param WC_Payment_Token $token
	 */
	public function handle_token_deleted($token_id, $token) {
		if ($token->get_gateway_id() !== $this->gateway->id) {
			return;
		}
		
		if (!($token instanceof WC_Payment_Token_Paysafe)) {
			return;
		}
		
		$profile_id = $token->get_paysafe_profile_id();
		$card_id = $token->get_paysafe_card_id();
		
		if (!$profile_id || !$card_id) {
			return;
		}
		
		$api = $this->get_api_instance();
		
		try {
			// Delete card from Paysafe vault
			$api->delete_card_from_profile($profile_id, $card_id);
			
			if ($this->gateway->get_option('enable_debug') === 'yes') {
				$this->log('Deleted card ' . $card_id . ' from profile ' . $profile_id);
			}
		} catch (Exception $e) {
			// Don't throw exception here as the token is already being deleted in WC
			// Just log the error
			$this->log('Error deleting card from vault: ' . $e->getMessage(), 'error');
		}
	}
	
	/**
	 * Verify if profile exists in Paysafe
	 * 
	 * @param string $profile_id
	 * @return bool
	 */
	private function verify_profile_exists($profile_id) {
		$api = $this->get_api_instance();
		
		try {
			$profile = $api->get_customer_profile($profile_id);
			return isset($profile['id']) && $profile['id'] === $profile_id;
		} catch (Exception $e) {
			return false;
		}
	}
	
	/**
	 * Add customer profile fields to user profile in admin
	 * 
	 * @param WP_User $user
	 */
	public function add_customer_profile_fields($user) {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}
		
		$profile_id = get_user_meta($user->ID, '_paysafe_customer_profile_id', true);
		$tokens = WC_Payment_Tokens::get_customer_tokens($user->ID, $this->gateway->id);
		?>
		<h2><?php _e('Paysafe Payment Information', 'paysafe-payment'); ?></h2>
		<table class="form-table">
			<tr>
				<th><label><?php _e('Paysafe Profile ID', 'paysafe-payment'); ?></label></th>
				<td>
					<?php if ($profile_id): ?>
						<code><?php echo esc_html($profile_id); ?></code>
						<p class="description"><?php _e('Customer profile ID in Paysafe Vault', 'paysafe-payment'); ?></p>
					<?php else: ?>
						<em><?php _e('No profile created yet', 'paysafe-payment'); ?></em>
					<?php endif; ?>
				</td>
			</tr>
			<?php if (!empty($tokens)): ?>
			<tr>
				<th><label><?php _e('Saved Cards', 'paysafe-payment'); ?></label></th>
				<td>
					<table class="widefat" style="max-width: 600px;">
						<thead>
							<tr>
								<th><?php _e('Card', 'paysafe-payment'); ?></th>
								<th><?php _e('Last 4', 'paysafe-payment'); ?></th>
								<th><?php _e('Expires', 'paysafe-payment'); ?></th>
								<th><?php _e('Default', 'paysafe-payment'); ?></th>
								<th><?php _e('Actions', 'paysafe-payment'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($tokens as $token): 
								$card_type = 'card';
								$last4 = '****';
								$expiry = 'N/A';
								
								if ($token instanceof WC_Payment_Token_Paysafe) {
									$card_type = $token->get_card_type();
									$last4 = $token->get_last4();
									$expiry = sprintf('%02d/%s', $token->get_expiry_month(), substr($token->get_expiry_year(), -2));
								} elseif ($token instanceof WC_Payment_Token_CC) {
									$card_type = $token->get_card_type();
									$last4 = $token->get_last4();
									$expiry = sprintf('%02d/%s', $token->get_expiry_month(), substr($token->get_expiry_year(), -2));
								}
							?>
							<tr>
								<td><?php echo esc_html($this->get_proper_card_type_label($card_type)); ?></td>
								<td><?php echo esc_html($last4); ?></td>
								<td><?php echo esc_html($expiry); ?></td>
								<td><?php echo $token->is_default() ? '✓' : ''; ?></td>
								<td>
									<button type="button" class="button button-small paysafe-delete-token" data-token-id="<?php echo esc_attr($token->get_id()); ?>">
										<?php _e('Delete', 'paysafe-payment'); ?>
									</button>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<script>
					jQuery(document).ready(function($) {
						$('.paysafe-delete-token').on('click', function() {
							if (!confirm('<?php _e('Are you sure you want to delete this card?', 'paysafe-payment'); ?>')) {
								return;
							}
							
							var tokenId = $(this).data('token-id');
							var row = $(this).closest('tr');
							
							$.post(ajaxurl, {
								action: 'wc_paysafe_delete_token',
								token_id: tokenId,
								user_id: <?php echo $user->ID; ?>,
								nonce: '<?php echo wp_create_nonce('paysafe_delete_token'); ?>'
							}, function(response) {
								if (response.success) {
									row.fadeOut();
								} else {
									alert(response.data || 'Error deleting card');
								}
							});
						});
					});
					</script>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}
	
	/**
	 * Save customer profile fields (placeholder for future use)
	 * 
	 * @param int $user_id
	 */
	public function save_customer_profile_fields($user_id) {
		// Currently no editable fields, but kept for future extensions
	}
	
	/**
	 * AJAX handler for deleting token from admin
	 */
	public function ajax_delete_token() {
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'paysafe_delete_token')) {
			wp_send_json_error('Invalid nonce');
		}
		
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error('Unauthorized');
		}
		
		$token_id = intval($_POST['token_id']);
		$user_id = intval($_POST['user_id']);
		
		$token = WC_Payment_Tokens::get( $token_id );

		if ( ! $token || $token->get_user_id() != $user_id || $token->get_gateway_id() !== $this->gateway->id ) {
			wp_send_json_error( 'Invalid token' );
		}
		
		if (WC_Payment_Tokens::delete($token_id)) {
			wp_send_json_success();
		} else {
			wp_send_json_error('Could not delete token');
		}
	}
	
	/**
	 * Get API instance
	 * 
	 * @return Paysafe_API
	 */
	private function get_api_instance() {
		// Check if gateway has the helper method for getting API settings
		if (method_exists($this->gateway, 'get_api_settings')) {
			return new Paysafe_API($this->gateway->get_api_settings(), $this->gateway);
		}
		
		// Fallback to manual settings array
		$settings = array(
			'api_username' => $this->gateway->get_option('api_key_user'),
			'api_password' => $this->gateway->get_option('api_key_password'),
			'single_token_username' => $this->gateway->get_option('single_use_token_user'),
			'single_token_password' => $this->gateway->get_option('single_use_token_password'),
			'merchant_id' => $this->gateway->get_option('merchant_id'),
			'account_id_cad' => $this->gateway->get_option('cards_account_id_cad'),
			'account_id_usd' => $this->gateway->get_option('cards_account_id_usd'),
			'environment' => $this->gateway->get_option('environment'),
			'debug' => $this->gateway->get_option('enable_debug'),
		);
		
		return new Paysafe_API($settings, $this->gateway);
	}
	
	/**
	 * Get account ID for currency
	 * 
	 * @param string $currency
	 * @return string
	 */
	private function get_account_id_for_currency($currency) {
		if ($currency === 'USD') {
			return $this->gateway->get_option('cards_account_id_usd');
		}
		return $this->gateway->get_option('cards_account_id_cad');
	}
	
	/**
	 * Detect card type from number
	 * 
	 * @param string $card_number
	 * @return string
	 */
	private function detect_card_type($card_number) {
		$card_number = str_replace(' ', '', $card_number);
		
		$patterns = array(
			'visa' => '/^4/',
			'mastercard' => '/^5[1-5]/',
			'amex' => '/^3[47]/',
			'discover' => '/^6(?:011|5)/',
			'jcb' => '/^35/',
			'diners' => '/^3(?:0[0-5]|[68])/'
		);
		
		foreach ($patterns as $type => $pattern) {
			if (preg_match($pattern, $card_number)) {
				return $type;
			}
		}
		
		return 'card';
	}
	
	/**
	 * Log messages
	 * 
	 * @param string $message
	 * @param string $level
	 */
	private function log($message, $level = 'info') {
		if ($this->gateway->get_option('enable_debug') === 'yes') {
			$logger = wc_get_logger();
			$logger->log($level, $message, array('source' => 'paysafe-tokenization'));
		}
	}
	
	/**
	 * AJAX handler for adding payment method
	 */
	public function ajax_add_payment_method() {
		// This is a placeholder - the actual add payment method is handled by the gateway class
		wp_send_json_error('Not implemented');
	}
	
	/**
	 * Get token payment data
	 * 
	 * @param array $data
	 * @param WC_Payment_Token $token
	 * @return array
	 */
	public function get_token_payment_data($data, $token) {
		if ($token instanceof WC_Payment_Token_Paysafe) {
			$data['paysafe_profile_id'] = $token->get_paysafe_profile_id();
			$data['paysafe_card_id'] = $token->get_paysafe_card_id();
			$data['paysafe_payment_token'] = $token->get_paysafe_payment_token();
		}
		return $data;
	}
}
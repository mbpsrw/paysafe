<?php
/**
 * Paysafe Gateway — Frontend (Hosted Fields & Wallets)
 * File: includes/class-paysafe-frontend.php
 * Purpose: Render hosted fields form, wallet buttons, and initialize client-side tokenization
 * Scope: Checkout, Pay for Order, and My Account add-payment; compatible with Woo Blocks
 * Features: Hosted-fields container; single-use token flow; Apple/Google Pay hooks; basic a11y
 * Notes: No deprecated WC handles; UI is rendered via gateway->payment_fields() and this class
 * Last updated: 2025-11-15
 */

/**
 * Paysafe Frontend Class
 * Handles the embedded payment form on the frontend
 */

if (!defined('ABSPATH')) {
	exit;
}

class Paysafe_Frontend {
	
	public function __construct() {
		// Add shortcode for payment form
		add_shortcode('paysafe_payment_form', array($this, 'render_payment_form'));
		
		// Enqueue frontend scripts
		add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
	}
	
	public function enqueue_frontend_scripts() {
		// Debug log (guard Woo helpers to avoid fatals)
		$is_checkout_flag = function_exists('is_checkout') ? is_checkout() : false;
 		if (defined('WP_DEBUG') && WP_DEBUG) {
 			error_log('Paysafe: enqueue_frontend_scripts called');
 			error_log('Paysafe: is_checkout = ' . ($is_checkout_flag ? 'true' : 'false'));
 			error_log('Paysafe: is_admin = ' . (is_admin() ? 'true' : 'false'));
 		}
		
		// Check if we're on a page that needs the payment form
		// Guard against WooCommerce installs that don't expose is_checkout_pay_page()
		$on_pay_page = function_exists('is_checkout_pay_page')
			? is_checkout_pay_page()
			: ( function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay') );

		if (!is_admin() && ($is_checkout_flag || $on_pay_page)) {
			error_log('Paysafe: Condition met, enqueueing scripts');
			
			// Get settings - updated to use paysafe option name
			$settings = get_option('woocommerce_paysafe_settings', array());
			$environment_setting = isset($settings['environment']) ? $settings['environment'] : 'sandbox';
			$live_mode = ($environment_setting === 'live');
			$environment = $live_mode ? 'LIVE' : 'TEST';
			
			// Get accepted cards for JavaScript
 			$accepted_cards = isset($settings['accepted_cards']) ? $settings['accepted_cards'] : array('visa', 'mastercard', 'amex', 'discover', 'jcb', 'interac');
			if (!is_array($accepted_cards)) {
 				$accepted_cards = array('visa', 'mastercard', 'amex', 'discover', 'jcb', 'interac');
			}
			
			// Paysafe.js from the correct host, guarded against double-enqueue
			$paysafe_js_url = $live_mode
				? 'https://hosted.paysafe.com/js/v1/latest/paysafe.min.js'
				: 'https://hosted.test.paysafe.com/js/v1/latest/paysafe.min.js';

			if ( ! wp_script_is( 'paysafe-js', 'enqueued' ) && ! wp_script_is( 'paysafe-js', 'registered' ) ) {
				wp_enqueue_script( 'paysafe-js', $paysafe_js_url, array(), null, true );
			}
			
			// Enqueue custom payment form script
			wp_enqueue_script(
				'paysafe-payment-form',
				PAYSAFE_PLUGIN_URL . 'assets/js/payment-form.js',
				array('jquery', 'paysafe-js'),
				PAYSAFE_VERSION,
				true
			);
			
			// Get merchant ID from settings
			$merchant_id = isset($settings['merchant_id']) ? $settings['merchant_id'] : '';
			
			// Get single-use token credentials
			$single_use_token_user = isset($settings['single_use_token_user']) ? $settings['single_use_token_user'] : '';
			$single_use_token_password = isset($settings['single_use_token_password']) ? $settings['single_use_token_password'] : '';
			
			// Get merchant name for digital wallets
			$merchant_name = get_bloginfo('name');
			
			// Get account IDs
			$account_id_cad = isset($settings['cards_account_id_cad']) ? $settings['cards_account_id_cad'] : '';
			$account_id_usd = isset($settings['cards_account_id_usd']) ? $settings['cards_account_id_usd'] : '';
			
			 // Determine current currency account ID (guard Woo helper)
			$currency = function_exists('get_woocommerce_currency')
				? get_woocommerce_currency()
				: get_option('woocommerce_currency', 'CAD');
			$current_account_id = ($currency === 'USD' && !empty($account_id_usd)) ? $account_id_usd : $account_id_cad;
			

			// Enqueue CSS
			wp_enqueue_style(
				'paysafe-payment-form',
				PAYSAFE_PLUGIN_URL . 'assets/css/payment-form.css',
				array(),
				PAYSAFE_VERSION
			);

			// Enqueue saved card CVV styling
			wp_enqueue_style(
				'paysafe-saved-card-cvv-styling',
				PAYSAFE_PLUGIN_URL . 'assets/css/saved-card-cvv-styling.css',
				array('paysafe-payment-form'),
				PAYSAFE_VERSION
			);
		}
	}

	public function render_payment_form($atts = array()) {
		// Parse attributes
		$atts = shortcode_atts(array(
			'amount' => '',
			'currency' => 'CAD',
			'show_amount' => true,
			'button_text' => __('Pay Now', 'paysafe-payment'),
			'form_class' => 'paysafe-payment-form',
		), $atts);
		
		// Get settings directly - updated to use paysafe option name
		$settings = get_option('woocommerce_paysafe_settings', array());
 		$accepted_cards = isset($settings['accepted_cards']) ? $settings['accepted_cards'] : array('visa', 'mastercard', 'amex', 'discover', 'jcb', 'interac');
		if (!is_array($accepted_cards)) {
		$accepted_cards = array('visa', 'mastercard', 'amex', 'discover', 'jcb', 'interac');
		}
		
		$enable_saved_cards = isset($settings['enable_saved_cards']) && $settings['enable_saved_cards'] === 'yes';
		$enable_apple_pay = isset($settings['enable_apple_pay']) && $settings['enable_apple_pay'] === 'yes';
		$enable_google_pay = isset($settings['enable_google_pay']) && $settings['enable_google_pay'] === 'yes';
		$enable_debug = isset($settings['enable_debug']) && $settings['enable_debug'] === 'yes';
		$pci_mode = isset($settings['pci_compliance_mode']) ? $settings['pci_compliance_mode'] : 'saq_a_with_fallback';
		$field_wrapper_class = ($pci_mode === 'saq_aep_only') ? 'paysafe-field' : 'paysafe-iframe-field';
		
		// Avoid fatal if WooCommerce is not loaded (e.g., shortcode on non-Woo page).
		$is_checkout_page = function_exists('is_checkout') ? is_checkout() : false;

		// Merchant country for Interac gating (wallets-only, Canada merchants)
		$base = function_exists('wc_get_base_location') ? wc_get_base_location() : array('country' => 'US');
		$merchant_country = ! empty( $base['country'] ) ? strtoupper( $base['country'] ) : 'US';
		$show_interac_logo = in_array('interac', $accepted_cards, true) && ($enable_apple_pay || $enable_google_pay) && $merchant_country === 'CA';
		ob_start();
		?>
		<div class="paysafe-payment-wrapper">
			<div id="paysafe-payment-form" class="<?php echo esc_attr($atts['form_class']); ?>">
				<!-- Customer Information (Hidden - pulled from checkout) -->
				<div class="paysafe-form-section paysafe-billing-section" aria-hidden="true">
					<input type="hidden" id="first_name" name="first_name" />
					<input type="hidden" id="last_name" name="last_name" />
					<input type="hidden" id="email" name="email" />
					<input type="hidden" id="phone" name="phone" />
					<input type="hidden" id="address" name="address" />
					<input type="hidden" id="city" name="city" />
					<input type="hidden" id="province" name="province" />
					<input type="hidden" id="postal_code" name="postal_code" />
					<input type="hidden" id="country" name="country" value="CA" />
				</div>
				
				<!-- Payment Information - Shopify Style -->
				<div class="paysafe-payment-section" data-psf-mode="<?php echo esc_attr($pci_mode); ?>">
<style id="paysafe-inline-fields">
/* Single source of truth for the visual shell (all PCI modes) */
.paysafe-payment-section #cardNumber_container,
.paysafe-payment-section #cardExpiry_container,
.paysafe-payment-section #cardCvv_container { width:100%; box-sizing:border-box; }

/* EP mode: inputs render directly, so they must be borderless — the shell draws the border */
.paysafe-payment-section #cardNumber_container .psf-input,
.paysafe-payment-section #cardExpiry_container .psf-input,
.paysafe-payment-section #cardCvv_container .psf-input {
  border:0 !important; outline:0 !important; background:transparent !important; box-shadow:none !important; padding:0; width:100%;
}

/* Containment and two-column row for Expiry + CVV; identical across modes */
.paysafe-payment-section .paysafe-iframe-field-wrapper,
.paysafe-payment-section .paysafe-cvv-wrapper { min-width:0; }
.paysafe-payment-section .paysafe-form-row-cards { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media (max-width:540px){ .paysafe-payment-section .paysafe-form-row-cards { grid-template-columns:1fr; } }

/* Keep the CVV help icon inside the shell */
.paysafe-payment-section #cardCvv_container { position:relative; }
.paysafe-payment-section #cardCvv_container .paysafe-help-icon { position:absolute; right:10px; top:50%; transform:translateY(-50%); pointer-events:auto; }
</style>
					<!-- Card header -->
					<div class="paysafe-card-header">
						<div class="paysafe-card-icon" aria-hidden="true"></div>
						<span class="paysafe-card-title"><?php _e('Credit / Debit Card', 'paysafe-payment'); ?></span>
						<div class="paysafe-card-logos" role="img" aria-label="<?php esc_attr_e('Accepted cards & Interac (wallet)', 'paysafe-payment'); ?>">
							<?php
							// Map of card types to display
							$card_display_map = array(
								'visa'        => 'Visa',
								'mastercard'  => 'Mastercard',
								'amex'        => 'American Express',
								'discover'    => 'Discover',
								'jcb'         => 'JCB',
								'diners'      => 'Diners Club',
								'interac'     => 'Interac'
							);
							
							// Display all accepted cards
							foreach ($accepted_cards as $card) {
								if (!isset($card_display_map[$card])) { continue; }
								// Interac is wallet-only: show its logo only if wallets are enabled and merchant is CA
								if ($card === 'interac' && ! $show_interac_logo) { continue; }
								$card_name = $card_display_map[$card];
								$svg_path  = PAYSAFE_PLUGIN_URL . 'assets/images/card-' . $card . '.svg';
								echo '<img src="' . esc_url($svg_path) . '" alt="' . esc_attr($card_name) . '" class="paysafe-card-logo" data-card-type="' . esc_attr($card) . '" onerror="this.style.display=\'none\'" />';
							}
							?>
						</div>
					</div>
					
					<!-- Card input containers -->
					<div class="paysafe-iframe-containers">
						<!-- Card Number -->
						<div class="paysafe-iframe-field-wrapper paysafe-card-number-wrapper">
							<label for="card_number" class="screen-reader-text"><?php _e('Card number', 'paysafe-payment'); ?></label>
							<div id="cardNumber_container" class="psf-shell <?php echo esc_attr($field_wrapper_class); ?> <?php echo ($pci_mode === 'saq_aep_only') ? 'psf-ep' : 'psf-hosted'; ?>">
								<input type="text" 
									   id="card_number" 
									   name="card_number" 
									   class="psf-input" placeholder="<?php esc_attr_e('Card number', 'paysafe-payment'); ?>"
									   autocomplete="cc-number"
									   inputmode="numeric"
									   pattern="[0-9\s]*"
									   maxlength="19"
									   aria-label="<?php esc_attr_e('Card number', 'paysafe-payment'); ?>"
									   aria-required="true" />
								<svg class="paysafe-lock-icon" id="card-type-indicator" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
									<path d="M12 2C9.24 2 7 4.24 7 7v5c-1.1 0-2 .9-2 2v7c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2v-7c0-1.1-.9-2-2-2V7c0-2.76-2.24-5-5-5zm0 2c1.66 0 3 1.34 3 3v5H9V7c0-1.66 1.34-3 3-3zm0 10c1.1 0 2 .9 2 2s-.9 2-2 2-2-.9-2-2 .9-2 2-2z" fill="currentColor"/>
								</svg>
								<div class="paysafe-card-type-icon" id="card-type-icon" style="display: none;"></div>
							</div>
						</div>
						
						<!-- Expiry and CVV Row -->
						<div class="paysafe-form-row-cards">
							<div class="paysafe-iframe-field-wrapper">
								<label for="card_expiry" class="screen-reader-text"><?php _e('Expiry date', 'paysafe-payment'); ?></label>
								<div id="cardExpiry_container" class="psf-shell <?php echo esc_attr($field_wrapper_class); ?> <?php echo ($pci_mode === 'saq_aep_only') ? 'psf-ep' : 'psf-hosted'; ?>">
									<input type="text" 
										   id="card_expiry" 
										   name="card_expiry" 
										   class="psf-input" placeholder="<?php esc_attr_e('Expiry date (MM / YY)', 'paysafe-payment'); ?>"
										   autocomplete="cc-exp"
										   inputmode="numeric"
										   pattern="[0-9\s\/]*"
										   maxlength="7"
										   aria-label="<?php esc_attr_e('Expiry date', 'paysafe-payment'); ?>"
										   aria-required="true" />
								</div>
							</div>
							<div class="paysafe-iframe-field-wrapper paysafe-cvv-wrapper">
								<label for="card_cvv" class="screen-reader-text"><?php _e('Security code', 'paysafe-payment'); ?></label>
								<div id="cardCvv_container" class="psf-shell <?php echo esc_attr($field_wrapper_class); ?> <?php echo ($pci_mode === 'saq_aep_only') ? 'psf-ep' : 'psf-hosted'; ?>">
									<input type="text" 
										   id="card_cvv" 
										   name="card_cvv" 
										   class="psf-input" placeholder="<?php esc_attr_e('Security code', 'paysafe-payment'); ?>"
										   autocomplete="cc-csc"
										   inputmode="numeric"
										   pattern="[0-9]*"
										   maxlength="4"
										   aria-label="<?php esc_attr_e('Security code', 'paysafe-payment'); ?>"
										   aria-required="true"
										   aria-describedby="cvv-help" />
									<span class="paysafe-help-icon" 
										  role="button"
										  tabindex="0"
										  aria-label="<?php esc_attr_e('Security code help', 'paysafe-payment'); ?>">?</span>
								</div>
								<span id="cvv-help" class="screen-reader-text">
									<?php _e('3-digit security code usually found on the back of your card. American Express cards have a 4-digit code located on the front. Also known as CVV code.', 'paysafe-payment'); ?>
								</span>
							</div>
						</div>
						
						<!-- Name on card -->
						<div class="paysafe-iframe-field-wrapper">
							<label for="cardholder_name" class="screen-reader-text"><?php _e('Name on card', 'paysafe-payment'); ?></label>
							<input type="text" 
								   id="cardholder_name" 
								   name="cardholder_name" 
								   class="paysafe-name-field" 
								   placeholder="<?php esc_attr_e('Name on card', 'paysafe-payment'); ?>"
								   autocomplete="cc-name"
								   aria-label="<?php esc_attr_e('Name on card', 'paysafe-payment'); ?>"
								   aria-required="true" />
						</div>
					</div>
					
					<?php if ($enable_saved_cards && is_user_logged_in()) : ?>
						<div class="paysafe-save-card">
							<label for="save_card">
								<input type="checkbox" id="save_card" name="save_card" value="1" checked />
								<?php _e('Save this card for future payments', 'paysafe-payment'); ?>
							</label>
						</div>
					<?php endif; ?>
				</div>
				
				<!-- Digital Wallet Options -->
				<?php if ($enable_apple_pay || $enable_google_pay) : ?>
				<div class="paysafe-digital-wallets">
					<div class="paysafe-divider">
						<span><?php _e('Or pay with', 'paysafe-payment'); ?></span>
					</div>
					<div class="paysafe-wallet-buttons">
						<?php if ($enable_apple_pay) : ?>
							<button type="button" 
									id="apple-pay-button" 
									class="paysafe-apple-pay-button" 
									style="display: none;"
									aria-label="<?php esc_attr_e('Pay with Apple Pay', 'paysafe-payment'); ?>">
								<img src="<?php echo esc_url(PAYSAFE_PLUGIN_URL . 'assets/images/apple-pay.svg'); ?>" 
									 alt="Apple Pay" 
									 height="20" />
							</button>
						<?php endif; ?>
						
						<?php if ($enable_google_pay) : ?>
							<button type="button" 
									id="google-pay-button" 
									class="paysafe-google-pay-button"
									aria-label="<?php esc_attr_e('Pay with Google Pay', 'paysafe-payment'); ?>">
								<img src="<?php echo esc_url(PAYSAFE_PLUGIN_URL . 'assets/images/google-pay.svg'); ?>" 
									 alt="Google Pay" 
									 height="20" />
							</button>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>
				
				<!-- Hidden fields -->
				<input type="hidden" name="amount" value="<?php echo esc_attr($atts['amount']); ?>" />
				<input type="hidden" name="currency" value="<?php echo esc_attr($atts['currency']); ?>" />
				<input type="hidden" name="action" value="paysafe_process_payment" />
				<?php wp_nonce_field('paysafe_payment_nonce', 'paysafe_nonce'); ?>
				
				<!-- Error messages -->
				<div id="paysafe-errors" class="paysafe-errors" style="display: none;" role="alert" aria-live="polite"></div>
				
				<!-- Submit button (hidden - form submission handled by WooCommerce) -->
				<?php if (!$is_checkout_page) : ?>
				<div class="paysafe-form-submit">
					<button type="submit" id="payNow" class="paysafe-submit-button">
						<?php echo esc_html($atts['button_text']); ?>
					</button>
				</div>
				<?php endif; ?>
 				
 				<!-- Token result (for testing) -->
 				<?php if ($enable_debug) : ?>
 					<div id="cardtoken-result" class="paysafe-debug-result" style="display: none;"></div>
 				<?php endif; ?>
 				</div>
 			</div>
 			<?php
		
		return ob_get_clean();
	}
}
/* global ajaxurl, jQuery, paysafe_admin */
/**
 * Paysafe Admin Settings JavaScript for WooCommerce
 * File: /assets/js/admin-settings.js
 * Handles the dynamic functionality of the Paysafe payment gateway settings page
 * within WooCommerce. It manages account ID fields, credential validation, and UI interactions.
 * Version: 1.0.0 - With PCI Compliant Tokenization
 * Last updated: 2025-11-02
 */

(function($) {
	'use strict';

	// Wait for DOM ready
	$(document).ready(function() {
		// Only run on Paysafe settings page
		if (!$('body').hasClass('woocommerce_page_wc-settings') || 
			$('#woocommerce_paysafe_enabled').length === 0) {
			return;
		}

		/**
		 * Ensure localization object exists with sane defaults.
		 * (Prevents runtime errors if PHP fails to localize some keys.)
		 */
		window.paysafe_admin = window.paysafe_admin || {};
		paysafe_admin.i18n = Object.assign({
			validate_credentials: 'Validate Credentials',
			validating: 'Validating...',
			enter_both_credentials: 'Please enter both API Key User Name and Password',
			valid: 'Valid',
			invalid: 'Invalid credentials',
			error_validating: 'Error validating credentials',
			live_mode_warning: 'You are about to enable LIVE mode. This will process real payments. Are you sure?',
			debug_mode_warning: 'Debug mode will log sensitive data. Only enable for troubleshooting and disable immediately after.',
			google_pay_production_warning: 'Production mode requires a valid Google Pay Merchant ID. Please obtain one from Google Pay Business Console.',
			apple_pay_help: 'To enable Apple Pay: 1) Register your domain with Apple, 2) Download the verification file, 3) Paste the file contents here.',
			learn_more: 'Learn more'
		}, paysafe_admin.i18n || {});

		/**
		 * Integration Type Handler
		 */
		function toggleIntegrationFields() {
			const integrationType = $('#woocommerce_paysafe_integration_type').val();
			
			// Hide/show relevant fields based on integration type
			if (integrationType === 'checkout_api') {
				$('.paysafe-api-fields').show();
				$('.paysafe-iframe-fields').hide();
			} else {
				$('.paysafe-api-fields').hide();
				$('.paysafe-iframe-fields').show();
			}
		}

		// Bind only if the control exists (defensive)
		if ($('#woocommerce_paysafe_integration_type').length) {
			$('#woocommerce_paysafe_integration_type').on('change', toggleIntegrationFields);
			toggleIntegrationFields();
		}

		/**
		 * Credential Validation
		 */

		// Add validate button after password field
		const $passwordField = $('#woocommerce_paysafe_api_key_password');
		if ($passwordField.length && !$passwordField.siblings('.validate-credentials').length) {
			const validateBtn = `
				<button type="button" class="button validate-credentials" style="margin-left: 10px;">
					${paysafe_admin.i18n.validate_credentials}
				</button>
				<span class="validation-status" style="margin-left: 10px;" aria-live="polite" role="status"></span>
			`;
			$passwordField.after(validateBtn);
		}

		// Handle credential validation
		$(document).on('click', '.validate-credentials', function(e) {
			e.preventDefault();

			const $button = $(this);
			const $status = $button.siblings('.validation-status');
			// Improve a11y and ensure announcements happen for screen readers
			if ($status.length) {
				$status.attr({
					'role': 'status',
					'aria-live': 'polite'
				});
			}

			const originalText = $button.text();

			// Get credentials
			const apiKeyUser = $('#woocommerce_paysafe_api_key_user').val();
			const apiKeyPassword = $('#woocommerce_paysafe_api_key_password').val();
			const sandbox = ($('#woocommerce_paysafe_environment').val() === 'sandbox');

			// Validate inputs
			if (!apiKeyUser || !apiKeyPassword) {
				alert(paysafe_admin.i18n.enter_both_credentials);
				return;
			}

			// Update UI
			$button.text(paysafe_admin.i18n.validating).prop('disabled', true);
			$status.html('');

			// Make AJAX request
			$.ajax({
				url: (window.paysafe_admin && paysafe_admin.ajax_url)
					? paysafe_admin.ajax_url
					: (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'),
				type: 'POST',
				data: {
					action: 'paysafe_validate_credentials',
					api_username: apiKeyUser,
					api_password: apiKeyPassword,
					sandbox: sandbox ? 'yes' : 'no',
					nonce: paysafe_admin.nonce || $('#_wpnonce').val()
				},
				success: function(response) {
					$button.text(originalText).prop('disabled', false);

					if (response.success) {
						$status.empty().append(
							$('<span/>', { text: '✓ ' + (paysafe_admin.i18n.valid || 'Valid') })
								.css('color', 'green')
						);

						// Auto-fill merchant ID if returned
						if (response.data && response.data.merchant_id) {
							$('#woocommerce_paysafe_merchant_id').val(response.data.merchant_id);
						}
					} else {
						const errRaw = (
							(response && response.data && (response.data.message || response.data.error)) ||
							(paysafe_admin && paysafe_admin.i18n && paysafe_admin.i18n.invalid) ||
							'Invalid credentials'
						);
						// Sanitize by injecting as text, not HTML
						$status.empty().append(
							$('<span/>', {
								text: '✗ ' + errRaw
							})
								.css('color', 'red')
						);
					}
				},
				error: function() {
					$button.text(originalText).prop('disabled', false);
					$status.empty().append(
						$('<span/>', { text: '✗ ' + (paysafe_admin.i18n.error_validating || 'Error validating credentials') })
							.css('color', 'red')
					);
				}
			});
		});

		/**
		 * Mode Warnings
		 */

		// Environment toggle warning (select: sandbox|live)
		$('#woocommerce_paysafe_environment').on('change', function() {
			const isLive = $(this).val() === 'live';
			if (isLive) {
				const message = (paysafe_admin.i18n && paysafe_admin.i18n.live_mode_warning) ||
					'You are about to enable LIVE mode. This will process real payments. Are you sure?';
				if (!confirm(message)) {
					// Revert back to sandbox silently
					$(this).val('sandbox');
				}
			}
		});

		// Debug mode warning
		$('#woocommerce_paysafe_enable_debug').on('change', function() {
			if ($(this).is(':checked')) {
				const message = paysafe_admin.i18n.debug_mode_warning || 
					'Debug mode will log sensitive data. Only enable for troubleshooting and disable immediately after.';

				if (!confirm(message)) {
					$(this).prop('checked', false);
				}
			}
		});

		/**
		 * Vault Prefix Generator
		 */
		const $vaultPrefixField = $('#woocommerce_paysafe_vault_prefix');
		if ($vaultPrefixField.length && !$vaultPrefixField.val()) {
			// Generate unique prefix based on site URL
			const siteUrl = String(window.location.hostname || 'site').replace(/[^a-zA-Z0-9]/g, '');
			const prefix = 'PHZ-' + siteUrl.substring(0, 4).toUpperCase() + '-';
			$vaultPrefixField.val(prefix);
		}

		/**
		 * Digital Wallet Settings
		 */

		// Show/hide Apple Pay fields based on enable checkbox
		$('#woocommerce_paysafe_enable_apple_pay').on('change', function() {
			const $relatedFields = $(
				'#woocommerce_paysafe_apple_pay_merchant_id, ' +
				'#woocommerce_paysafe_apple_pay_merchant_name, ' +
				'#woocommerce_paysafe_apple_pay_domain_verification'
			).closest('tr');

			if ($(this).is(':checked')) {
				$relatedFields.show();
			} else {
				$relatedFields.hide();
			}
		}).trigger('change');

		// Show/hide Google Pay fields based on enable checkbox
		$('#woocommerce_paysafe_enable_google_pay').on('change', function() {
			const $relatedFields = $(
				'#woocommerce_paysafe_google_pay_merchant_id, ' +
				'#woocommerce_paysafe_google_pay_merchant_name, ' +
				'#woocommerce_paysafe_google_pay_environment'
			).closest('tr');

			if ($(this).is(':checked')) {
				$relatedFields.show();
			} else {
				$relatedFields.hide();
			}
		}).trigger('change');

		// Google Pay environment warning
		$('#woocommerce_paysafe_google_pay_environment').on('change', function() {
			if ($(this).val() === 'PRODUCTION') {
				const merchantId = $('#woocommerce_paysafe_google_pay_merchant_id').val();
				if (!merchantId) {
					alert(paysafe_admin.i18n.google_pay_production_warning || 
						'Production mode requires a valid Google Pay Merchant ID. Please obtain one from Google Pay Business Console.');
				}
			}
		});

		// Apple Pay domain verification help
		const $applePayVerification = $('#woocommerce_paysafe_apple_pay_domain_verification');
		if ($applePayVerification.length && !$applePayVerification.next('.apple-pay-help').length) {
			const helpText = `
				<p class="apple-pay-help" style="margin-top: 5px;">
					<small>
						${paysafe_admin.i18n.apple_pay_help || 
						'To enable Apple Pay: 1) Register your domain with Apple, 2) Download the verification file, 3) Paste the file contents here.'}
						<a href="https://developer.apple.com/documentation/apple_pay_on_the_web/configuring_your_environment" target="_blank" rel="noopener noreferrer">
							${paysafe_admin.i18n.learn_more || 'Learn more'}
						</a>
					</small>
				</p>
			`;
			$applePayVerification.after(helpText);
		}

		/**
		 * Field Dependencies
		 */

		// Show/hide 3DS v2 options when v2 is enabled
		$('#woocommerce_paysafe_enable_3ds_v2').on('change', function() {
			const $v2Options = $(
				'#woocommerce_paysafe_3ds_challenge_indicator, ' +
				'#woocommerce_paysafe_3ds_exemption_indicator'
			).closest('tr');

			if ($(this).is(':checked')) {
				$v2Options.show();
			} else {
				$v2Options.hide();
			}
		}).trigger('change');

		// Show/hide CVV field option based on saved cards
		$('#woocommerce_paysafe_enable_saved_cards').on('change', function() {
			const $cvvRow = $('#woocommerce_paysafe_require_cvv_with_token').closest('tr');

			if ($(this).is(':checked')) {
				$cvvRow.show();
			} else {
				$cvvRow.hide();
			}
		}).trigger('change');

		/**
		 * Add custom CSS for admin logo
		 */
		if (!$('#paysafe-admin-logo-style').length) {
			$('head').append(`
				<style id="paysafe-admin-logo-style">
					@keyframes pulse {
						0% { opacity: 1; }
						50% { opacity: 0.7; }
						100% { opacity: 1; }
					}

					/* Style for payment methods table */
					.wc_gateways .wc_gateway_paysafe img {
						max-height: 25px;
						max-width: 100px;
						vertical-align: middle;
						margin-right: 10px;
					}

					/* Ensure logo doesn't appear on frontend */
					body:not(.wp-admin) .payment_method_paysafe img[src*="paysafe-logo"] {
						display: none !important;
					}
				</style>
			`);
		}

		/**
		 * Initialize tooltips if available
		 */
		if ($.fn.tipTip) {
			$('.wc-help-tip').tipTip({
				'attribute': 'data-tip',
				'fadeIn': 50,
				'fadeOut': 50,
				'delay': 200
			});
		}

	});

})(jQuery);

// Localization object (to be provided by PHP)
if (typeof window.paysafe_admin === 'undefined') {
	window.paysafe_admin = {
		i18n: {
			enter_account_id: 'Please enter an Account ID',
			google_pay_production_warning: 'Production mode requires a valid Google Pay Merchant ID. Please obtain one from Google Pay Business Console.',
			apple_pay_help: 'To enable Apple Pay: 1) Register your domain with Apple, 2) Download the verification file, 3) Paste the file contents here.',
			learn_more: 'Learn more',
			currency_exists: 'An account ID for this currency already exists. Please remove it first.',
			cad_label: 'Canadian dollar',
			usd_label: 'United States (US) dollar',
			remove: 'Remove',
			confirm_remove: 'Are you sure you want to remove this account ID?',
			validate_credentials: 'Validate Credentials',
			validating: 'Validating...',
			enter_both_credentials: 'Please enter both API Key User Name and Password',
			valid: 'Valid',
			invalid: 'Invalid credentials',
			error_validating: 'Error validating credentials',
			live_mode_warning: 'You are about to enable LIVE mode. This will process real payments. Are you sure?',
			debug_mode_warning: 'Debug mode will log sensitive data. Only enable for troubleshooting.'
		}
	};
} else if (typeof window.paysafe_admin.i18n === 'undefined') {
	window.paysafe_admin.i18n = {
		enter_account_id: 'Please enter an Account ID',
		google_pay_production_warning: 'Production mode requires a valid Google Pay Merchant ID. Please obtain one from Google Pay Business Console.',
		apple_pay_help: 'To enable Apple Pay: 1) Register your domain with Apple, 2) Download the verification file, 3) Paste the file contents here.',
		learn_more: 'Learn more',
		currency_exists: 'An account ID for this currency already exists. Please remove it first.',
		cad_label: 'Canadian dollar',
		usd_label: 'United States (US) dollar',
		remove: 'Remove',
		confirm_remove: 'Are you sure you want to remove this account ID?',
		validate_credentials: 'Validate Credentials',
		validating: 'Validating...',
		enter_both_credentials: 'Please enter both API Key User Name and Password',
		valid: 'Valid',
		invalid: 'Invalid credentials',
		error_validating: 'Error validating credentials',
		live_mode_warning: 'You are about to enable LIVE mode. This will process real payments. Are you sure?',
		debug_mode_warning: 'Debug mode will log sensitive data. Only enable for troubleshooting.'
	};
}
/**
 * Paysafe Error Interceptor - Single source of truth for WooCommerce checkout errors.
 * File: /assets/js/paysafe-error-interceptor.js
 * Listens to Woo's `checkout_error` event.
 * Collects `.woocommerce-error`, `.woocommerce-message`, `.woocommerce-info`
 * from the checkout/pay/add-payment-method forms.
 * Hides the native banners and shows a single dark gradient box instead.
 * Version: 1.0.3
 * Last updated: 2025-12-03
 */

/* global jQuery */
(function (window, $) {
	'use strict';

	// Block WooCommerce's scroll_to_notices by overriding $.fn.animate
	// WooCommerce's woocommerce.min.js defines $.scroll_to_notices which uses
	// $('html, body').animate({ scrollTop: ... }) to scroll to notices.
	// We intercept and block scrolls that target the top of the form.
	var originalAnimate = $.fn.animate;
	var paysafeScrollInProgress = false;
	
	$.fn.animate = function(props) {
		if (props && props.scrollTop !== undefined) {
			// If this is OUR scroll (flagged), allow it
			if (paysafeScrollInProgress) {
				return originalAnimate.apply(this, arguments);
			}
			// Block all other scrollTop animations (WooCommerce's scroll_to_notices)
			return this;
		}
		return originalAnimate.apply(this, arguments);
	};
	
	// Expose a way to allow our scroll
	window.paysafeAllowScroll = function(fn) {
		paysafeScrollInProgress = true;
		fn();
		// Reset after animation completes
		setTimeout(function() {
			paysafeScrollInProgress = false;
		}, 500);
	};

	// ═══════════════════════════════════════════════════════════════════════
	// Block focus() calls on WooCommerce error elements
	// WooCommerce calls .focus() on .woocommerce-error which causes browser
	// to scroll that element into view - this is the actual cause of the jump
	// ═══════════════════════════════════════════════════════════════════════
	var originalFocus = HTMLElement.prototype.focus;
	HTMLElement.prototype.focus = function(options) {
		// Block focus on WooCommerce error elements
		if (this.classList && (
			this.classList.contains('woocommerce-error') ||
			this.classList.contains('woocommerce-NoticeGroup') ||
			this.classList.contains('woocommerce-NoticeGroup-checkout')
		)) {
			// Don't focus - this prevents browser from scrolling to element
			return;
		}
		return originalFocus.call(this, options);
	};

	// ═══════════════════════════════════════════════════════════════════════
	// Disable WooCommerce's automatic scroll-to-top on checkout errors.
	// 
	// WooCommerce checkout.js flow:
	// 1. AJAX returns with validation errors
	// 2. Calls wc_checkout_form.submit_error()
	// 3. submit_error() calls wc_checkout_form.scroll_to_notices()
	// 4. scroll_to_notices() calls $.scroll_to_notices(element)
	// 5. $.scroll_to_notices() uses scrollIntoView() - NOT stoppable with .stop()
	//
	// Solution: Use MutationObserver to catch the .woocommerce-NoticeGroup
	// element the instant it's added and remove it BEFORE scrollIntoView runs.
	// Our checkout_error handler will then display our custom error box.
	// ═══════════════════════════════════════════════════════════════════════
	
	// MutationObserver: catch .woocommerce-NoticeGroup the instant it's added
	// and hide it BEFORE scrollIntoView() can target it
	$(function() {
		var $checkoutForm = $('form.checkout');
		if (!$checkoutForm.length) {
			return;
		}
		
		var observer = new MutationObserver(function(mutations) {
			mutations.forEach(function(mutation) {
				if (mutation.addedNodes && mutation.addedNodes.length) {
					for (var i = 0; i < mutation.addedNodes.length; i++) {
						var node = mutation.addedNodes[i];
						if (node.nodeType === 1) {
							var $node = $(node);
							if ($node.hasClass('woocommerce-NoticeGroup') || 
								$node.hasClass('woocommerce-NoticeGroup-checkout')) {
								// Hide immediately so scrollIntoView has no target
								$node.css('display', 'none');
							}
						}
					}
				}
			});
		});
		
		observer.observe($checkoutForm[0], { childList: true, subtree: false });
	});

	var stylesInjected = false;

	function ensureStyles() {
		if (stylesInjected) {
			return;
		}
		stylesInjected = true;

		var css = ''
			+ '#paysafe-error-box {'
			+ '  display: none;'
			+ '  position: fixed;'
			+ '  top: 50%;'
			+ '  left: 50%;'
			+ '  transform: translate(-50%, -50%);'
			+ '  width: calc(100% - 2rem);'
			+ '  max-width: 900px;'
			+ '  padding: 1.25em 1.5em;'
			+ '  background: linear-gradient(135deg, #171b26, #222b3b);'
			+ '  color: #ff4d4f;'
			+ '  border-radius: 8px;'
			+ '  border: 1px solid #ff4d4f;'
			+ '  box-shadow: 0 8px 18px rgba(0, 0, 0, 0.35);'
			+ '  font-size: 0.95rem;'
			+ '  line-height: 1.5;'
			+ '  z-index: 9999;'
			+ '  max-height: 80vh;'
			+ '  overflow-y: auto;'
			+ '}'
			+ '#paysafe-error-box .paysafe-error-inner {'
			+ '  display: flex;'
			+ '  flex-direction: column;'
			+ '  gap: 0.5em;'
			+ '}'
			+ '#paysafe-error-box .paysafe-error-header {'
			+ '  display: flex;'
			+ '  align-items: center;'
			+ '  justify-content: space-between;'
			+ '  gap: 1em;'
			+ '}'
			+ '#paysafe-error-box .paysafe-error-title {'
			+ '  font-weight: 700;'
			+ '  font-size: 1.05rem;'
			+ '}'
			+ '#paysafe-error-box .paysafe-error-close {'
			+ '  background: transparent;'
			+ '  border: 0;'
			+ '  color: #ff4d4f;'
			+ '  font-size: 1.8rem;'
			+ '  cursor: pointer;'
			+ '  padding: 0;'
			+ '  line-height: 1;'
			+ '}'
			+ '#paysafe-error-box .paysafe-error-list {'
			+ '  margin: 0.25em 0 0;'
			+ '  padding-left: 1.25em;'
			+ '}'
			+ '#paysafe-error-box .paysafe-error-list li {'
			+ '  margin: 0.15em 0;'
			+ '}';

		var $style = $('<style type="text/css" id="paysafe-error-box-style"></style>').text(css);
		$('head').append($style);
	}

	function ensureBox() {
		ensureStyles();

		var $box = $('#paysafe-error-box');
		if ($box.length) {
			return $box;
		}
		$box = $('<div id="paysafe-error-box" role="alert" aria-live="assertive"></div>');

		// Try to place it just above the main checkout/pay/add-payment-method form.
		// NOTE: resolve the anchor step-by-step instead of using `||` with jQuery
		// objects, because an empty jQuery collection is still truthy. This ensures
		// we actually prefer the active form wrapper when available.
		var $anchor = $('form.checkout').first().closest('.woocommerce');
		if (!$anchor.length) {
			$anchor = $('form#order_review').first().closest('.woocommerce');
		}
		if (!$anchor.length) {
			$anchor = $('form#add_payment_method').first().closest('.woocommerce');
		}

		if ($anchor && $anchor.length) {
			$anchor.first().prepend($box);
		} else {
			// Fallback: before the first Woo container we see.
			var $wc = $('.woocommerce').first();
			if ($wc.length) {
				$wc.prepend($box);
			} else {
				$('body').prepend($box);
			}
		}

		// Close button handler.
		$box.on('click', '.paysafe-error-close', function () {
			$box.slideUp(180);
		});

		return $box;
	}

	/**
	 * Strip <a> tags from HTML but preserve their inner content.
	 * This prevents WooCommerce's field anchor links from rendering as blue links.
	 */
	function stripLinksFromMessage(html) {
		if (!html) {
			return '';
		}
		var $temp = $('<div></div>').html(html);
		$temp.find('a').each(function() {
			$(this).replaceWith($(this).html());
		});
		return $.trim($temp.html());
	}

	/**
	 * Extract field IDs from WooCommerce error message anchor hrefs.
	 * WooCommerce errors contain <a href="#billing_postcode">...</a> links.
	 * Returns array of field IDs like ['billing_postcode', 'billing_email']
	 */
	function extractFieldIdsFromErrors($container) {
		var fieldIds = [];
		if (!$container || !$container.length) {
			return fieldIds;
		}
		
		$container.find('.woocommerce-error a[href^="#"]').each(function() {
			var href = $(this).attr('href');
			if (href && href.charAt(0) === '#') {
				var fieldId = href.substring(1); // Remove the #
				if (fieldId && fieldIds.indexOf(fieldId) === -1) {
					fieldIds.push(fieldId);
				}
			}
		});
		
		return fieldIds;
	}

	function collectMessagesFromForm($form) {
		var messages = [];

		if (!$form || !$form.length) {
			return messages;
		}

		// Error lists (most common).
		$form.find('.woocommerce-error').each(function () {
			var $err = $(this);
			var $lis = $err.find('li');

			if ($lis.length) {
				$lis.each(function () {
					var text = stripLinksFromMessage($(this).html());
					if (text) {
						messages.push(text);
					}
				});
			} else {
				var text = stripLinksFromMessage($err.html());
				if (text) {
					messages.push(text);
				}
			}
		});

		// Messages & info.
		$form.find('.woocommerce-message, .woocommerce-info').each(function () {
			var text = stripLinksFromMessage($(this).html());
			if (text) {
				messages.push(text);
			}
		});

		return messages;
	}

	function hideNativeWooNotices($context) {
		var $ctx = $context && $context.length ? $context : $(document);
		$ctx.find('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message, .woocommerce-info')
			.not('#paysafe-error-box, #paysafe-error-box *')
			// Remove native Woo banners entirely so they can't keep
			// re-triggering our overlay with stale messages.
			.remove();
	}

	function renderErrorBox(messages) {
		if (!messages || !messages.length) {
			return;
		}

		var $box = ensureBox();

		var listHtml = '<ul class="paysafe-error-list">' +
			messages.map(function (m) {
				return '<li>' + m + '</li>';
			}).join('') +
			'</ul>';

		var innerHtml = ''
			+ '<div class="paysafe-error-inner">'
			+ '  <div class="paysafe-error-header">'
			+ '    <div class="paysafe-error-title">'
			+ '      ' + 'Please fix the details below:' // text only, no i18n overhead here
			+ '    </div>'
			+ '    <button type="button" class="paysafe-error-close" aria-label="Dismiss error messages">&times;</button>'
			+ '  </div>'
			+ listHtml
			+ '</div>';

		$box.html(innerHtml).stop(true, true).slideDown(180);
	}

	/**
	 * Apply red rings to invalid fields and scroll to first one.
	 * @param {Array} fieldIds - Array of field IDs extracted from error messages
	 */
	function highlightInvalidFieldsAndScroll(fieldIds) {
		if (!fieldIds || !fieldIds.length) {
			return;
		}
		
		var $firstField = null;
		
		// Apply red ring to each invalid field by ID
		fieldIds.forEach(function(fieldId) {
			var $field = $('#' + fieldId);
			if ($field.length) {
				$field.addClass('paysafe-input-error');
				if (!$firstField) {
					$firstField = $field;
				}
			}
		});

		// Scroll to center the first invalid field
		if ($firstField && $firstField.length) {
			setTimeout(function() {
				window.paysafeAllowScroll(function() {
				try {
					var offsetTop = $firstField.offset().top || 0;
					var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 600;
					var targetScroll = offsetTop - (viewportHeight / 2);
					
					if (targetScroll < 0) {
						targetScroll = 0;
					}
					
					$('html, body').animate(
						{ scrollTop: targetScroll },
						300
					);
				} catch (e) {
					// Non-fatal - silent fail
				}
				});

				// Focus the field after scroll completes
				setTimeout(function() {
					try {
						$firstField.trigger('focus');
					} catch (e) {
						// Ignore
					}
				}, 50);
			}, 100);
		}
	}

	function handleCheckoutErrorEvent(errorHtml) {
		// Prefer pulling directly from the active form so we see everything Woo just rendered.
		var $form =
			$('form.checkout').first() ||
			$('form#order_review').first() ||
			$('form#add_payment_method').first();

		// CRITICAL: Extract field IDs from error anchors BEFORE we strip/hide them
		var fieldIds = extractFieldIdsFromErrors($form);
		
		// Also try to extract from the raw errorHtml passed by WooCommerce
		if (errorHtml) {
			var $tmp = $('<div></div>').html(errorHtml);
			var additionalIds = extractFieldIdsFromErrors($tmp);
			additionalIds.forEach(function(id) {
				if (fieldIds.indexOf(id) === -1) {
					fieldIds.push(id);
				}
			});
		}

		var messages = collectMessagesFromForm($form);

		// Fallback: parse the raw HTML snippet passed by Woo (if any).
		if ((!messages || !messages.length) && errorHtml) {
			var $tmp = $('<div></div>').html(errorHtml);
			$tmp.find('.woocommerce-error li').each(function () {
				var text = stripLinksFromMessage($(this).html());
				if (text) {
					messages.push(text);
				}
			});
			if (!messages.length) {
				var text = $.trim($tmp.text());
				if (text) {
					messages.push(text);
				}
			}
		}

		if (!messages.length) {
			return;
		}

		// Hide the native bars and show our dark box instead.
		hideNativeWooNotices($form);
		// Detect card-related errors by keywords
		var cardErrorKeywords = ['cvv', 'security code', 'card number', 'expiry', 'expiration', 'cardholder'];
		var isCardError = messages.some(function(msg) {
			var lowerMsg = (msg || '').toLowerCase();
			return cardErrorKeywords.some(function(keyword) {
				return lowerMsg.indexOf(keyword) >= 0;
			});
		});
		
		// Route card errors to PaysafeCore.showError for inline display near card fields
		if (isCardError && typeof window.PaysafeCore !== 'undefined' && window.PaysafeCore.showError) {
			var fieldSelector = null;
			var msgLower = messages.join(' ').toLowerCase();
			
			if (msgLower.indexOf('cvv') >= 0 || msgLower.indexOf('security code') >= 0) {
			// Find CVV field for the SELECTED saved card (not just first visible)
			var $selectedCard = $('input[name="wc-paysafe-payment-token"]:checked').closest('li, .paysafe-saved-card-item, .woocommerce-SavedPaymentMethods-token');
			var $savedCvv = $selectedCard.find('.paysafe-saved-card-cvv-input');
			
			// Fallback to any visible CVV if not found in selected card
			if (!$savedCvv.length) {
				$savedCvv = $('.paysafe-saved-card-cvv-input:visible').first();
			}
			
				if ($savedCvv.length) {
				// Apply red ring directly to the specific field
				if (typeof window.PaysafeCore !== 'undefined' && window.PaysafeCore.applyFieldState) {
					window.PaysafeCore.applyFieldState($savedCvv, 'invalid');
				}
				// Use ID if available, otherwise use token-specific selector
				if ($savedCvv.attr('id')) {
					fieldSelector = '#' + $savedCvv.attr('id');
				} else {
					// Build selector using the checked token's value
					var tokenVal = $('input[name="wc-paysafe-payment-token"]:checked').val();
					if (tokenVal) {
						fieldSelector = 'input[name="wc-paysafe-payment-token"][value="' + tokenVal + '"]';
					} else {
						fieldSelector = '.paysafe-saved-card-cvv-input';
					}
				}
				} else {
					fieldSelector = '#cardCvv_container, #card_cvv';
				}
			} else if (msgLower.indexOf('card number') >= 0) {
				fieldSelector = '#cardNumber_container, #card_number';
			} else if (msgLower.indexOf('expir') >= 0) {
				fieldSelector = '#cardExpiry_container, #card_expiry';
			}
			
			window.PaysafeCore.showError(messages, {
				fieldSelector: fieldSelector,
				sticky: true
			});
			return;
		}
		
		// Non-card errors: show in fixed modal and highlight billing fields
		renderErrorBox(messages);
		highlightInvalidFieldsAndScroll(fieldIds);
	}

	function scanAndNormalizeOnUpdate() {
		// Whenever checkout updates, grab any stray Woo messages
		// and reroute them into the dark box.
		var $form =
			$('form.checkout').first() ||
			$('form#order_review').first() ||
			$('form#add_payment_method').first();

		if (!$form || !$form.length) {
			return;
		}

		// Extract field IDs BEFORE we strip links
		var fieldIds = extractFieldIdsFromErrors($form);

		var messages = collectMessagesFromForm($form);
		if (!messages.length) {
			return;
		}

		hideNativeWooNotices($form);
		// Detect card-related errors by keywords
		var cardErrorKeywords = ['cvv', 'security code', 'card number', 'expiry', 'expiration', 'cardholder'];
		var isCardError = messages.some(function(msg) {
			var lowerMsg = (msg || '').toLowerCase();
			return cardErrorKeywords.some(function(keyword) {
				return lowerMsg.indexOf(keyword) >= 0;
			});
		});
		
		// Route card errors to PaysafeCore.showError for inline display near card fields
		if (isCardError && typeof window.PaysafeCore !== 'undefined' && window.PaysafeCore.showError) {
			var fieldSelector = null;
			var msgLower = messages.join(' ').toLowerCase();
			
			if (msgLower.indexOf('cvv') >= 0 || msgLower.indexOf('security code') >= 0) {
			// Find CVV field for the SELECTED saved card (not just first visible)
			var $selectedCard = $('input[name="wc-paysafe-payment-token"]:checked').closest('li, .paysafe-saved-card-item, .woocommerce-SavedPaymentMethods-token');
			var $savedCvv = $selectedCard.find('.paysafe-saved-card-cvv-input');
			
			// Fallback to any visible CVV if not found in selected card
			if (!$savedCvv.length) {
				$savedCvv = $('.paysafe-saved-card-cvv-input:visible').first();
			}
			
				if ($savedCvv.length) {
				// Apply red ring directly to the specific field
				if (typeof window.PaysafeCore !== 'undefined' && window.PaysafeCore.applyFieldState) {
					window.PaysafeCore.applyFieldState($savedCvv, 'invalid');
				}
				// Use ID if available, otherwise use token-specific selector
				if ($savedCvv.attr('id')) {
					fieldSelector = '#' + $savedCvv.attr('id');
				} else {
					// Build selector using the checked token's value for positioning
					var tokenVal = $('input[name="wc-paysafe-payment-token"]:checked').val();
					if (tokenVal) {
						fieldSelector = 'input[name="wc-paysafe-payment-token"][value="' + tokenVal + '"]';
					} else {
						fieldSelector = '.paysafe-saved-card-cvv-input';
					}
				}
				} else {
					fieldSelector = '#cardCvv_container, #card_cvv';
				}
			} else if (msgLower.indexOf('card number') >= 0) {
				fieldSelector = '#cardNumber_container, #card_number';
			} else if (msgLower.indexOf('expir') >= 0) {
				fieldSelector = '#cardExpiry_container, #card_expiry';
			}
			
			window.PaysafeCore.showError(messages, {
				fieldSelector: fieldSelector,
				sticky: true
			});
			return;
		}
		
		// Non-card errors: show in fixed modal and highlight billing fields
		renderErrorBox(messages);
		highlightInvalidFieldsAndScroll(fieldIds);
	}

	function init() {
		ensureStyles();

		// Core hook: this fires whenever Woo thinks there was a checkout error.
		$(document.body)
			.off('checkout_error.paysafeErrorBox')
			.on('checkout_error.paysafeErrorBox', function (e, errorHtml) {
				handleCheckoutErrorEvent(errorHtml);
			});

		// Also scan on checkout updates for any messages that appear
		// outside the normal error flow (e.g. shipping method issues).
		$(document.body)
			.off('updated_checkout.paysafeErrorBox updated_wc_div.paysafeErrorBox')
			.on('updated_checkout.paysafeErrorBox updated_wc_div.paysafeErrorBox', function () {
				scanAndNormalizeOnUpdate();
			});

		// Initial scan in case there are messages on first paint.
		$(function () {
			scanAndNormalizeOnUpdate();
		});
	}

	// As soon as the shopper starts fixing an invalid checkout field,
	// clear Woo's invalid markers (red ring) for that field/row and
	// hide our overlay so they don't see stale errors.
	$(document.body).on(
		'input change',
		'form.checkout input, form.checkout select, form.checkout textarea, ' +
		'form#order_review input, form#order_review select, form#order_review textarea, ' +
		'form#add_payment_method input, form#add_payment_method select, form#add_payment_method textarea',
		function () {
			var $field = $(this);

			// Defer so any Woo/theme validators run first, then we clean up.
			window.setTimeout(function () {
				var $row = $field.closest('.form-row, p, .validate-required');
				var $ancestors = $field.parents('.woocommerce-invalid, .woocommerce-invalid-required-field, .woocommerce-input-error');

				// Only bother if the customer is actually typing/selecting something.
				// If the value is still empty, keep Woo's required warning.
				var value = $field.is('select') ? $field.val() : $.trim($field.val());
				if (!value || (Array.isArray && Array.isArray(value) && !value.length)) {
					return;
				}

				var hadInvalid = false;

				if ($row.length) {
					if (
						$row.hasClass('woocommerce-invalid') ||
						$row.hasClass('woocommerce-invalid-required-field') ||
						$row.hasClass('woocommerce-input-error')
					) {
						hadInvalid = true;
					}

					$row.removeClass('woocommerce-invalid woocommerce-invalid-required-field woocommerce-input-error');
				}

				if ($ancestors.length) {
					hadInvalid = true;
					$ancestors.removeClass('woocommerce-invalid woocommerce-invalid-required-field woocommerce-input-error');
				}

				if (
					$field.hasClass('woocommerce-invalid') ||
					$field.hasClass('woocommerce-invalid-required-field') ||
					$field.hasClass('woocommerce-input-error')
				) {
					hadInvalid = true;
				}

				$field
					.removeClass('woocommerce-invalid woocommerce-invalid-required-field woocommerce-input-error')
					.removeClass('paysafe-input-error')
					.attr('aria-invalid', 'false')
					// Force-reset visual error styles that may be coming from
					// theme/Woo CSS (border, outline, box-shadow).
					.css({
						'border-color': '',
						'box-shadow': '',
						'outline': ''
					});

				// If this field wasn't previously marked invalid, do nothing else.
				if (!hadInvalid) {
					return;
				}

				// Mark as validated so themes using Woo's green/neutral
				// state don't keep it red.
				if ($row.length) {
					$row.addClass('woocommerce-validated');
				}
				if ($ancestors.length) {
					$ancestors.addClass('woocommerce-validated');
				}
				$field.addClass('woocommerce-validated');

				// Hide the overlay if it was showing an error about this field.
				var $box = $('#paysafe-error-box');
				if ($box.length) {
					$box.hide().attr('aria-hidden', 'true');
				}
			}, 0);
		}
	);

	$(init);

})(window, jQuery);
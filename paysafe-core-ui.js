/**
 * Paysafe Core UI - Unified Components
 * File: /assets/js/paysafe-core-ui.js
 * Purpose: Single source of truth for UI components shared across all PCI compliance modes
 * Modes: SAQ-A Only (hosted fields), SAQ-A-EP Only (direct), SAQ-A with Fallback (hybrid)
 * Version: 1.0.0
 * Last updated: 2025-11-19
 */

(function($) {
  'use strict';

  // Namespace for core UI utilities
  window.PaysafeCore = window.PaysafeCore || {};

  /* ═══════════════════════════════════════════════════════════════════════
	 FIELD STATE MANAGER
	 Provides unified visual feedback across all PCI modes
	 States: neutral, hover, focus, valid, invalid
	 ═══════════════════════════════════════════════════════════════════════ */

  const FIELD_STATES = Object.freeze({
	NEUTRAL: 'neutral',
	HOVER: 'hover',
	FOCUS: 'focus',
	VALID: 'valid',
	INVALID: 'invalid'
  });

  const STATE_CLASSES = Object.freeze({
	FOCUS: 'psf-focus',
	VALID: 'psf-valid',
	INVALID: 'psf-invalid',
	ERROR: 'error',
	CONNECTED: 'psf-connected',
	// Universal error class for all field types (billing, card, etc.)
	UNIVERSAL_ERROR: 'paysafe-input-error'
  });

  /**
   * Apply state to a field (wrapper or input)
   * @param {string|jQuery} selector - Field selector or jQuery object
   * @param {string} state - One of: neutral, hover, focus, valid, invalid
   * @param {object} options - Additional options
   */
  window.PaysafeCore.applyFieldState = function(selector, state, options) {
	options = options || {};
	const $field = (selector instanceof $) ? selector : $(selector);
	if (!$field.length) return;

	// Clear all state classes first (unless explicitly preserving)
	if (!options.preserve) {
	  $field.removeClass([
		STATE_CLASSES.FOCUS,
		STATE_CLASSES.VALID,
		STATE_CLASSES.INVALID,
		STATE_CLASSES.ERROR,
		STATE_CLASSES.UNIVERSAL_ERROR
	  ].join(' '));
	  
	  // Also clear from nested elements that might have state classes
	  $field.find('.paysafe-iframe-field, input, select, textarea').removeClass([
		STATE_CLASSES.INVALID,
		STATE_CLASSES.ERROR,
		STATE_CLASSES.UNIVERSAL_ERROR
	  ].join(' '));
	}

	// Apply new state
	switch (state) {
	  case FIELD_STATES.FOCUS:
		$field.addClass(STATE_CLASSES.FOCUS);
		break;
	  
	  case FIELD_STATES.VALID:
		$field.addClass(STATE_CLASSES.VALID);
		$field.removeClass(STATE_CLASSES.INVALID + ' ' + STATE_CLASSES.ERROR + ' ' + STATE_CLASSES.UNIVERSAL_ERROR);
		break;
	  
	  case FIELD_STATES.INVALID:
		// Apply ALL error classes for maximum compatibility
		$field.addClass(STATE_CLASSES.INVALID + ' ' + STATE_CLASSES.ERROR + ' ' + STATE_CLASSES.UNIVERSAL_ERROR);
		$field.removeClass(STATE_CLASSES.VALID);
		// Also apply to nested iframe field if present
		$field.find('.paysafe-iframe-field').addClass(STATE_CLASSES.INVALID + ' ' + STATE_CLASSES.ERROR);
		// Apply universal error class to actual input/select/textarea for direct styling
		$field.find('input, select, textarea').addClass(STATE_CLASSES.UNIVERSAL_ERROR);
		// Set aria-invalid on actual input
		$field.find('input, select, textarea').attr('aria-invalid', 'true');
		break;
	  
	  case FIELD_STATES.NEUTRAL:
	  default:
		// Already cleared above unless preserve is true
		$field.find('input, select, textarea').removeAttr('aria-invalid');
		break;
	}
  };

  /**
   * Clear all states from a field
   */
  window.PaysafeCore.clearFieldState = function(selector) {
	window.PaysafeCore.applyFieldState(selector, FIELD_STATES.NEUTRAL);
  };

  /**
   * Attach input event listeners to clear error state when user starts typing.
   * Universal method that works for any field type (billing, card, etc.)
   * @param {string} fieldSelector - CSS selector for fields to monitor (e.g., '.woocommerce-billing-fields input')
   * @param {string} namespace - Optional event namespace (default: 'paysafeClearError')
   */
  window.PaysafeCore.attachClearOnInput = function(fieldSelector, namespace) {
	namespace = namespace || 'paysafeClearError';
	const eventNames = 'input.' + namespace + ' change.' + namespace;
	
	$(fieldSelector)
	  .off(eventNames)
	  .on(eventNames, function() {
		const $this = $(this);
		
		// Check if field itself has error class
		if ($this.hasClass(STATE_CLASSES.UNIVERSAL_ERROR) || 
			$this.hasClass(STATE_CLASSES.INVALID) || 
			$this.hasClass(STATE_CLASSES.ERROR)) {
		  window.PaysafeCore.clearFieldState($this);
		}
		
		// Check if parent wrapper has error class (for card containers, etc.)
		const $wrapper = $this.closest('[class*="container"], .form-row, .paysafe-iframe-field-wrapper');
		if ($wrapper.length && 
			($wrapper.hasClass(STATE_CLASSES.UNIVERSAL_ERROR) || 
			 $wrapper.hasClass(STATE_CLASSES.INVALID) || 
			 $wrapper.hasClass(STATE_CLASSES.ERROR))) {
		  window.PaysafeCore.clearFieldState($wrapper);
		}
	  });
  };

  /**
   * Bulk apply states to multiple fields
   * @param {object} fieldStateMap - { selector: state, ... }
   */
  window.PaysafeCore.applyFieldStates = function(fieldStateMap) {
	Object.keys(fieldStateMap).forEach(function(selector) {
	  window.PaysafeCore.applyFieldState(selector, fieldStateMap[selector]);
	});
  };

  /* ═══════════════════════════════════════════════════════════════════════
	 ERROR BOX MANAGER (Enhanced PSFUI)
	 Unified dark gradient error box for all modes and all error types
	 ═══════════════════════════════════════════════════════════════════════ */

  const ERROR_BOX_ID = 'paysafe-unified-error';

  /**
   * Show error message(s) in unified dark gradient box
   * @param {string|array} messages - Single message or array of messages
   * @param {object} options - Additional options (title, sticky, fieldSelector, etc.)
   *   - fieldSelector: CSS selector for the field that triggered the error
   *   - title: Custom title for error box
   *   - sticky: Prevent auto-dismiss
   */
  window.PaysafeCore.showError = function(messages, options) {
	options = options || {};
	
	// Normalize messages to array
	let messageArray = [];
	if (typeof messages === 'string') {
	  messageArray = [messages];
	} else if (Array.isArray(messages)) {
	  messageArray = messages.filter(function(m) { return m && String(m).trim(); });
	} else if (messages && typeof messages === 'object' && messages.messages) {
	  // Support legacy format: { messages: [...] }
	  messageArray = Array.isArray(messages.messages) ? messages.messages : [String(messages.messages)];
	} else {
	  messageArray = [String(messages || 'An error occurred.')];
	}

	if (!messageArray.length) return;

	// Remove any existing error box
	$('#' + ERROR_BOX_ID).remove();

	// Create unified error box with dark gradient styling
	const $errorBox = $('<div id="' + ERROR_BOX_ID + '"></div>');
	
	// Apply inline styles using attr() to support !important
	$errorBox.attr('style', 
	  'background: linear-gradient(135deg, #161a1f, #20262d 45%, #1a1f26) !important;' +
	  'color: #ff5555 !important;' +
	  'font-size: 0.9375rem !important;' +
	  'font-weight: 500 !important;' +
	  'padding: 0.625rem 0.875rem !important;' +
	  'margin: 0 0 1rem 0 !important;' +
	  'border-radius: 0.5rem !important;' +
	  'box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.3) !important;' +
	  'display: block !important;' +
	  'border: 1px solid rgba(255, 85, 85, 0.2) !important;' +
	  'position: relative !important;' +
	  'width: 100% !important;' +
	  'max-width: 100% !important;' +
	  'box-sizing: border-box !important;' +
	  'visibility: visible !important;' +
	  'opacity: 1 !important;' +
	  'word-wrap: break-word !important;' +
	  'overflow-wrap: break-word !important;'
	);

	// Mark as sticky if requested (prevents auto-dismiss by other scripts)
	if (options.sticky) {
	  $errorBox.attr('data-sticky', '1');
	}

	// Accessibility attributes
	$errorBox.attr({
	  'role': 'alert',
	  'aria-live': 'assertive',
	  'tabindex': '-1'
	});

	// Build content wrapper for flex layout
	const $wrapper = $('<div></div>').css({
	  'display': 'flex',
	  'align-items': 'flex-start',
	  'gap': '0.5rem'
	});

	// Content container (left side)
	const $content = $('<div></div>').css({
	  'flex': '1',
	  'min-width': '0'
	});

	// Title (if provided) or default for multiple messages
	const titleText = options.title || (messageArray.length > 1 ? 'Please fix the following:' : '');
	if (titleText) {
	  const $title = $('<div></div>').text(titleText).css({
		'font-weight': '600',
		'margin-bottom': '0.25rem',
		'line-height': '1.4'
	  });
	  $content.append($title);
	}

	// Messages content
	if (messageArray.length === 1) {
	  // Single message - render HTML to support formatting like <strong>
	  const $message = $('<div></div>').html(messageArray[0]).css({
		'line-height': '1.4',
		'word-wrap': 'break-word',
		'overflow-wrap': 'break-word'
	  });
	  $content.append($message);
	} else {
	  // Multiple messages - bullet list
	  const $list = $('<ul></ul>').css({
		'margin': '0',
		'padding-left': '1.25rem',
		'line-height': '1.4'
	  });
	  messageArray.forEach(function(msg) {
		$list.append($('<li></li>').html(msg).css({
		  'word-wrap': 'break-word',
		  'overflow-wrap': 'break-word'
		}));
	  });
	  $content.append($list);
	}

	$wrapper.append($content);

	// Close button (right side)
	const $closeBtn = $('<button type="button" aria-label="Dismiss"></button>')
	  .text('×')
	  .css({
		'padding': '0 0.25rem',
		'font-size': '1.25rem',
		'line-height': '1',
		'border': 'none',
		'border-radius': '0.25rem',
		'background': 'transparent',
		'color': '#e6edf3',
		'cursor': 'pointer',
		'transition': 'all 0.2s ease',
		'flex-shrink': '0',
		'margin-top': '-0.125rem'
	  });

	// Close button hover/focus effects
	$closeBtn.on('mouseenter focus', function() {
	  $(this).css({
		'background': 'rgba(230, 237, 243, 0.15)',
		'outline': 'none'
	  });
	}).on('mouseleave blur', function() {
	  $(this).css({
		'background': 'transparent'
	  });
	});

	$wrapper.append($closeBtn);
	$errorBox.append($wrapper);

	// Insert error box into DOM
	let inserted = false;
	let insertMethod = 'unknown';
	
	// Detect context: is this a card error or billing error?
	const isCardError = options.fieldSelector && (
	  options.fieldSelector.includes('card') || 
	  options.fieldSelector.includes('cvv') || 
	  options.fieldSelector.includes('expiry') ||
	  options.fieldSelector.includes('cardholder')
	);

	// CARD ERRORS: Anchor before saved cards list (after radio buttons)
	if (isCardError) {
	  const $savedCardsContainer = $('.paysafe-saved-cards-container').filter(':visible').first();
	  if ($savedCardsContainer.length) {
		$savedCardsContainer.before($errorBox);
		inserted = true;
		insertMethod = 'card-error-before-saved-cards';
	  } else {
		// Fallback: try payment section
		const $paymentSection = $('.paysafe-payment-section, .paysafe-woocommerce-form').filter(':visible').first();
		if ($paymentSection.length) {
		  $paymentSection.before($errorBox);
		  inserted = true;
		  insertMethod = 'card-error-above-payment-section';
		}
	  }
	}

	// BILLING ERRORS or fallback: Use field-specific positioning
	// METHOD 1: If fieldSelector provided, insert directly above that specific field
	if (!inserted && options.fieldSelector) {
	  const $targetField = $(options.fieldSelector).filter(':visible').first();
	  if ($targetField.length) {
		// Find the IMMEDIATE wrapper/container of this field
		// Start with the field's direct parent, then look for common containers
		let $insertBefore = null;
		
		// Try immediate parent first (for inputs inside wrappers)
		const $immediateParent = $targetField.parent();
		if ($immediateParent.length && $immediateParent.is(':visible')) {
		  $insertBefore = $immediateParent;
		  insertMethod = 'before-field-parent';
		}
		
		// If parent is too generic (div without class), look for better container
		if ($insertBefore && $insertBefore.is('div:not([class]):not([id])')) {
		  const $betterContainer = $targetField.closest(
			'.psf-shell, .paysafe-iframe-field-wrapper, ' +
			'.paysafe-cvv-wrapper, .paysafe-expiry-wrapper, ' +
			'.paysafe-field-row, .woocommerce-SavedPaymentMethods-tokenInput, ' +
			'li.wc-saved-payment-method-token'
		  );
		  if ($betterContainer.length) {
			$insertBefore = $betterContainer;
			insertMethod = 'before-field-container';
		  }
		}
		
		if ($insertBefore && $insertBefore.length) {
		  $insertBefore.before($errorBox);
		  inserted = true;
		}
	  }
	}

	// METHOD 2: Near visible card fields (CVV, card number, etc)
	if (!inserted) {
	  const $anchor = $('#cardNumber_container, #card_number, #paysafe-card-number-field, #cardCvv_container').filter(':visible').first();
	  if ($anchor.length) {
		const $parent = $anchor.closest('.paysafe-payment-section, .paysafe-woocommerce-form').filter(':visible');
		if ($parent.length) {
		  $parent.before($errorBox);
		  inserted = true;
		  insertMethod = 'near-card-fields';
		}
	  }
	}

	// METHOD 3: Visible payment section
	if (!inserted) {
	  const $section = $('.paysafe-payment-section, .paysafe-woocommerce-form').filter(':visible').first();
	  if ($section.length) {
		$section.before($errorBox);
		inserted = true;
		insertMethod = 'payment-section';
	  }
	}

	// METHOD 4: Visible payment wrapper
	if (!inserted) {
	  const $wrapper = $('.paysafe-payment-wrapper').filter(':visible').first();
	  if ($wrapper.length) {
		$wrapper.before($errorBox);
		inserted = true;
		insertMethod = 'payment-wrapper';
	  }
	}

	// METHOD 5: Payment methods container
	if (!inserted) {
	  const $payment = $('#payment').first();
	  if ($payment.length) {
		$payment.prepend($errorBox);
		inserted = true;
		insertMethod = 'payment-container';
	  }
	}

	// METHOD 6: Checkout payment area
	if (!inserted) {
	  const $checkoutPayment = $('.woocommerce-checkout-payment').first();
	  if ($checkoutPayment.length) {
		$checkoutPayment.prepend($errorBox);
		inserted = true;
		insertMethod = 'checkout-payment';
	  }
	}

	// METHOD 7: Checkout form
	if (!inserted) {
	  const $checkoutForm = $('form.checkout, form.woocommerce-checkout').first();
	  if ($checkoutForm.length) {
		$checkoutForm.prepend($errorBox);
		inserted = true;
		insertMethod = 'checkout-form';
	  }
	}

	// GUARANTEED FALLBACK
	if (!inserted) {
	  const $main = $('#main, #content, .site-content, main, .main').first();
	  if ($main.length) {
		$main.prepend($errorBox);
		insertMethod = 'main-content';
	  } else {
		$('body').prepend($errorBox);
		insertMethod = 'body';
	  }
	  inserted = true;
	}

	// ALWAYS log insertion (not just in debug mode) so we can troubleshoot
	console.log('Paysafe error box inserted via:', insertMethod, 
	  '| Visible:', $errorBox.is(':visible'),
	  '| Offset top:', $errorBox.offset() ? $errorBox.offset().top : 'N/A');
	if (options.fieldSelector) {
	  console.log('  ↳ Field selector:', options.fieldSelector);
	  const $field = $(options.fieldSelector).filter(':visible').first();
	  if ($field.length) {
		console.log('  ↳ Field found at offset:', $field.offset().top);
		console.log('  ↳ Error box is', Math.abs($errorBox.offset().top - $field.offset().top), 'px',
		  $errorBox.offset().top < $field.offset().top ? 'ABOVE ✓' : 'BELOW ✗', 'the field');
	  }
	}

	// Bind dismiss handlers
	const dismissError = function() {
	  window.PaysafeCore.clearError();
	};

	$closeBtn.on('click', dismissError);

	// Global handlers with namespace
	$(document).off('keydown.paysafeCoreError');
	
	$(document).on('keydown.paysafeCoreError', function(e) {
	  if (e.key === 'Escape' || e.keyCode === 27) {
		dismissError();
	  }
	});

	// Focus for screen readers
	setTimeout(function() {
	  try {
		$errorBox[0].focus();
	  } catch(e) {}
	}, 10);

	// FIX ISSUE 3: Apply INVALID state to the field that triggered the error (red ring)
	if (options.fieldSelector) {
	  try {
		const $field = $(options.fieldSelector).filter(':visible').first();
		if ($field.length) {
		  window.PaysafeCore.applyFieldState($field, FIELD_STATES.INVALID);
		  console.log('  ↳ Applied INVALID state (red ring) to field:', options.fieldSelector);
		}
	  } catch(e) {
		console.log('  ↳ Failed to apply field state:', e);
	  }
	}

	// Scroll into view (smooth, centered) - CORRECT centering formula
	setTimeout(function() {
	  try {
		const rect = $errorBox[0].getBoundingClientRect();
		// CORRECT: Center the element in viewport
		const targetTop = window.pageYOffset + rect.top - (window.innerHeight / 2) + (rect.height / 2);
		window.scrollTo({
		  top: Math.max(targetTop, 0),
		  behavior: 'smooth'
		});
		console.log('  ↳ Scrolled to center error box');
	  } catch(e) {
		console.log('  ↳ Scroll failed:', e);
	  }
	}, 100);
  };

  /**
   * Clear error box
   */
  window.PaysafeCore.clearError = function() {
	$('#' + ERROR_BOX_ID).remove();
	$(document).off('keydown.paysafeCoreError');
  };

  /* ═══════════════════════════════════════════════════════════════════════
	 FIELD SANITIZATION UTILITIES
	 Shared validation and formatting logic for all modes
	 ═══════════════════════════════════════════════════════════════════════ */

  /**
   * Remove all non-digit characters
   */
  window.PaysafeCore.sanitizeDigits = function(value) {
	return String(value || '').replace(/\D+/g, '');
  };

  /**
   * Sanitize card number (remove spaces)
   */
  window.PaysafeCore.sanitizeCardNumber = function(value) {
	return String(value || '').replace(/\s+/g, '');
  };

  /**
   * Format card number with spaces (4-4-4-4 or 4-6-5 for AmEx)
   */
  window.PaysafeCore.formatCardNumber = function(value) {
	const cleaned = window.PaysafeCore.sanitizeCardNumber(value);
	const isAmex = /^3[47]/.test(cleaned);
	
	if (isAmex) {
	  // AmEx: 4-6-5
	  const parts = [
		cleaned.substring(0, 4),
		cleaned.substring(4, 10),
		cleaned.substring(10, 15)
	  ].filter(Boolean);
	  return parts.join(' ');
	} else {
	  // Others: 4-4-4-4-...
	  const groups = cleaned.match(/.{1,4}/g);
	  return groups ? groups.join(' ') : cleaned;
	}
  };

  /**
   * Sanitize and format expiry to MM / YY
   */
  window.PaysafeCore.formatExpiry = function(value) {
	let digits = window.PaysafeCore.sanitizeDigits(value);
	
	let mm = '';
	let yy = '';
	
	// Auto-leading-zero: if first digit > 1, treat as 0X
	if (digits.length === 1) {
	  const d0 = digits.charAt(0);
	  if (parseInt(d0, 10) > 1) {
		mm = '0' + d0;
		digits = mm; // Update digits for proper parsing below
	  } else {
		mm = d0;
	  }
	} else if (digits.length >= 2) {
	  mm = digits.substring(0, 2);
	  yy = digits.substring(2, 4);
	}
	
	// Clamp month: 00→01, >12→12
	if (mm.length === 2) {
	  let m = parseInt(mm, 10);
	  if (isNaN(m) || m <= 0) m = 1;
	  if (m > 12) m = 12;
	  mm = (m < 10 ? '0' : '') + m;
	}
	
	// Build formatted output
	let formatted = mm;
	if (digits.length > 2 && yy) {
	  formatted = mm + ' / ' + yy;
	} else if (digits.length >= 2) {
	  formatted = mm + ' / ';
	}
	
	return formatted;
  };

  /**
   * Parse expiry into {month, year} or null if invalid
   */
  window.PaysafeCore.parseExpiry = function(value) {
	const s = String(value || '').trim()
	  .replace(/\s+/g, '')
	  .replace(/[^0-9/]/g, '')
	  .replace(/^(\d{2})(\d{2})$/, '$1/$2'); // Allow MMYY
	
	const match = s.match(/^(\d{1,2})\s*\/\s*(\d{2})$/);
	if (!match) return null;
	
	const mm = parseInt(match[1], 10);
	const yy = parseInt(match[2], 10) + 2000; // Assume 2000s
	
	if (mm < 1 || mm > 12) return null;
	
	// Check not in the past
	const now = new Date();
	const currentYear = now.getFullYear();
	const currentMonth = now.getMonth() + 1;
	
	if (yy < currentYear || (yy === currentYear && mm < currentMonth)) {
	  return null;
	}
	
	return { month: mm, year: yy };
  };

  /**
   * Luhn algorithm validation for card numbers
   */
  window.PaysafeCore.luhnCheck = function(cardNumber) {
	const cleaned = window.PaysafeCore.sanitizeDigits(cardNumber);
	
	if (cleaned.length < 12 || cleaned.length > 19) {
	  return false;
	}
	
	let sum = 0;
	let shouldDouble = false;
	
	// Process digits from right to left
	for (let i = cleaned.length - 1; i >= 0; i--) {
	  let digit = parseInt(cleaned.charAt(i), 10);
	  
	  if (shouldDouble) {
		digit *= 2;
		if (digit > 9) {
		  digit -= 9;
		}
	  }
	  
	  sum += digit;
	  shouldDouble = !shouldDouble;
	}
	
	return (sum % 10) === 0;
  };

  /**
   * Validate CVV (3 or 4 digits)
   */
  window.PaysafeCore.validateCVV = function(cvv) {
	const cleaned = window.PaysafeCore.sanitizeDigits(cvv);
	return cleaned.length === 3 || cleaned.length === 4;
  };

  /* ═══════════════════════════════════════════════════════════════════════
	 EXPOSE CONSTANTS
	 ═══════════════════════════════════════════════════════════════════════ */

  window.PaysafeCore.FIELD_STATES = FIELD_STATES;
  window.PaysafeCore.STATE_CLASSES = STATE_CLASSES;

  /* ═══════════════════════════════════════════════════════════════════════
	 BACKWARDS COMPATIBILITY LAYER
	 Expose legacy function names that existing code might be using
	 ═══════════════════════════════════════════════════════════════════════ */

  // Expose as PSFUI for backwards compatibility
  window.PSFUI = window.PSFUI || {};
  window.PSFUI.error = function(messages, options) {
	window.PaysafeCore.showError(messages, options);
  };
  window.PSFUI.clear = function() {
	window.PaysafeCore.clearError();
  };

  // Legacy inline error functions (for payment-guard.js compatibility)
  window.__ps_inlineError = function(msg) {
	window.PaysafeCore.showError(msg, { sticky: true });
  };
  window.__ps_clearInlineError = function() {
	window.PaysafeCore.clearError();
  };

  /* ═══════════════════════════════════════════════════════════════════════
	 INITIALIZATION
	 ═══════════════════════════════════════════════════════════════════════ */

  // Log successful initialization (only if debug mode is enabled)
  if (window.paysafe_params && window.paysafe_params.debug) {
	console.log('✅ PaysafeCore initialized - Unified UI components loaded');
  }

})(jQuery);

/**
 * Paysafe Payment Form JavaScript
 * File: /assets/js/paysafe-ep-validate.js
 * Handles direct card input validation for SAQ-A-EP mode
 * Compatible with Paysafe API (formerly Payfirma/Merrco)
 * 
 * NOTE: This script validates CARD FIELDS ONLY (card number, expiry, CVV).
 * Billing/shipping field validation is handled SERVER-SIDE by WooCommerce.
 * WooCommerce validates all required fields via AJAX before our gateway's
 * validate_fields() method is called. This is the correct WooCommerce architecture.
 * 
 * Version: 2.0.0 - Card validation only, billing handled by WooCommerce server-side
 * Last updated: 2025-12-10
 */
/* global jQuery, paysafe_ep, paysafe_params, PaysafeCore */

(function($){
  'use strict';

  // Guard
  if (typeof $ === 'undefined') return;

  // Defaults if wp_localize_script wasn't available for some reason
  var cfg = window.paysafe_ep || {};
  cfg.mode = cfg.mode || (window.paysafe_params ? window.paysafe_params.pci_compliance_mode : 'saq_a_with_fallback');
  cfg.selectors = cfg.selectors || {
	section: '.paysafe-woocommerce-form',
	savedRadio: '#paysafe-use-saved-card',
	newRadio:   '#paysafe-use-new-card',
	tokenField: 'input[name="paysafe_payment_token"]',
	number:     '#card_number',
	expiry:     '#card_expiry',
	cvv:        '#card_cvv',
	numberWrap: '#cardNumber_container',
	expiryWrap: '#cardExpiry_container',
	cvvWrap:    '#cardCvv_container'
  };
  cfg.i18n = cfg.i18n || {
	header: 'Please fix the card fields below:',
	invalid_number: 'Please enter a valid card number',
	invalid_expiry: 'Please enter a valid expiration date (MM / YY)',
	invalid_cvv:    'Please enter a valid security code',
  };

  function isEPOnlyMode() {
	return cfg.mode === 'saq_aep_only';
  }

  // ---- Helpers ----------------------------------------------------

  function epFieldsPresent() {
	return $(cfg.selectors.number).length &&
		   $(cfg.selectors.expiry).length &&
		   $(cfg.selectors.cvv).length;
  }

  function usingSavedCard() {
	var $saved = $(cfg.selectors.savedRadio);
	return $saved.length && $saved.is(':checked');
  }

  function hasPaymentToken() {
	return !!$(cfg.selectors.tokenField).val();
  }

  /* ═══════════════════════════════════════════════════════════════════════
	 FIELD STATE MANAGEMENT - Now using PaysafeCore
	 ═══════════════════════════════════════════════════════════════════════ */

  function clearRings() {
	// Clear all field states using unified core
	if (typeof PaysafeCore !== 'undefined' && PaysafeCore.clearFieldState) {
	  PaysafeCore.clearFieldState(cfg.selectors.numberWrap);
	  PaysafeCore.clearFieldState(cfg.selectors.expiryWrap);
	  PaysafeCore.clearFieldState(cfg.selectors.cvvWrap);
	}
  }

  function ringField(kind, invalid) {
	console.log('🔵 ringField called:', kind, 'invalid:', invalid);
	
	// Apply invalid state using unified core
	if (typeof PaysafeCore === 'undefined' || !PaysafeCore.applyFieldState) {
	  console.log('❌ PaysafeCore not available for ringField');
	  return; // Fallback: do nothing if core not available
	}
	
	var wrapSel = cfg.selectors[kind + 'Wrap'] || '';
	console.log('🔵 Wrapper selector:', wrapSel);
	
	if (!wrapSel) {
	  console.log('❌ No wrapper selector found');
	  return;
	}
	
	if (invalid) {
	  console.log('🔴 Applying INVALID state to:', wrapSel);
	  PaysafeCore.applyFieldState(wrapSel, 'invalid');
	  console.log('🔴 State applied, checking element:', $(wrapSel).attr('class'));
	} else {
	  console.log('✅ Clearing state from:', wrapSel);
	  PaysafeCore.clearFieldState(wrapSel);
	}
  }

  /* ═══════════════════════════════════════════════════════════════════════
	 ERROR HANDLING - Now using PaysafeCore unified error box
	 ═══════════════════════════════════════════════════════════════════════ */

  function showErrors(messages) {
	console.log('🔴 EP Validate: Showing errors:', messages);
	if (typeof PaysafeCore !== 'undefined' && PaysafeCore.showError) {
	  // Use unified error box with title
	  PaysafeCore.showError(messages, {
		title: cfg.i18n.header
	  });
	} else {
	  // Fallback: log to console if core not loaded
	  console.error('Validation errors:', messages);
	}
  }

  function clearErrors() {
	if (typeof PaysafeCore !== 'undefined' && PaysafeCore.clearError) {
	  PaysafeCore.clearError();
	}
  }

  /* ═══════════════════════════════════════════════════════════════════════
	 LIVE FIELD STATE UPDATES - Focus and Valid states
	 ═══════════════════════════════════════════════════════════════════════ */

  function attachLiveStateHandlers() {
	// Already attached? Don't duplicate
	if ($(document).data('paysafe-ep-live-attached')) {
	  console.log('⏭️ EP Validate: Live handlers already attached');
	  return;
	}
	console.log('✅ EP Validate: Attaching live state handlers');
	$(document).data('paysafe-ep-live-attached', true);

	// FOCUS STATE: Show focus state when field is clicked
	$(document)
	  .off('focus.paysafeEPState', cfg.selectors.number + ',' + cfg.selectors.expiry + ',' + cfg.selectors.cvv)
	  .on('focus.paysafeEPState', cfg.selectors.number + ',' + cfg.selectors.expiry + ',' + cfg.selectors.cvv, function() {
		if (typeof PaysafeCore === 'undefined') return;
		
		var fieldId = '#' + this.id;
		if (fieldId === cfg.selectors.number) {
		  PaysafeCore.applyFieldState(cfg.selectors.numberWrap, 'focus', {preserve: true});
		} else if (fieldId === cfg.selectors.expiry) {
		  PaysafeCore.applyFieldState(cfg.selectors.expiryWrap, 'focus', {preserve: true});
		} else if (fieldId === cfg.selectors.cvv) {
		  PaysafeCore.applyFieldState(cfg.selectors.cvvWrap, 'focus', {preserve: true});
		}
	  });

	// BLUR STATE: Remove focus state when field loses focus
	$(document)
	  .off('blur.paysafeEPState', cfg.selectors.number + ',' + cfg.selectors.expiry + ',' + cfg.selectors.cvv)
	  .on('blur.paysafeEPState', cfg.selectors.number + ',' + cfg.selectors.expiry + ',' + cfg.selectors.cvv, function() {
		if (typeof PaysafeCore === 'undefined') return;
		
		var fieldId = '#' + this.id;
		var $field = $(this);
		var value = $field.val();
		
		// Check if field is valid and apply appropriate state
		if (fieldId === cfg.selectors.number) {
		  var cleaned = PaysafeCore.sanitizeCardNumber(value);
		  if (cleaned && PaysafeCore.luhnCheck(cleaned)) {
			PaysafeCore.applyFieldState(cfg.selectors.numberWrap, 'valid');
		  } else if (!cleaned) {
			PaysafeCore.clearFieldState(cfg.selectors.numberWrap);
		  }
		} else if (fieldId === cfg.selectors.expiry) {
		  if (value && PaysafeCore.parseExpiry(value)) {
			PaysafeCore.applyFieldState(cfg.selectors.expiryWrap, 'valid');
		  } else if (!value) {
			PaysafeCore.clearFieldState(cfg.selectors.expiryWrap);
		  }
		} else if (fieldId === cfg.selectors.cvv) {
		  if (value && PaysafeCore.validateCVV(value)) {
			PaysafeCore.applyFieldState(cfg.selectors.cvvWrap, 'valid');
		  } else if (!value) {
			PaysafeCore.clearFieldState(cfg.selectors.cvvWrap);
		  }
		}
	  });

	// VALID STATE: Show valid state as user types (real-time feedback)
	$(document)
	  .off('input.paysafeEPState', cfg.selectors.number + ',' + cfg.selectors.expiry + ',' + cfg.selectors.cvv)
	  .on('input.paysafeEPState', cfg.selectors.number + ',' + cfg.selectors.expiry + ',' + cfg.selectors.cvv, function() {
		if (typeof PaysafeCore === 'undefined') return;
		
		var fieldId = '#' + this.id;
		var value = $(this).val();
		
		// Real-time validation feedback
		if (fieldId === cfg.selectors.number) {
		  // Clear invalid state immediately when user starts typing
		  var $wrap = $(cfg.selectors.numberWrap);
		  if ($wrap.hasClass('psf-invalid') || $wrap.hasClass('error')) {
			PaysafeCore.clearFieldState(cfg.selectors.numberWrap);
		  }
		  
		  var cleaned = PaysafeCore.sanitizeCardNumber(value);
		  if (cleaned.length >= 13 && PaysafeCore.luhnCheck(cleaned)) {
			PaysafeCore.applyFieldState(cfg.selectors.numberWrap, 'valid');
		  } else {
			// Clear valid state but don't mark as invalid while typing
			$wrap.removeClass('psf-valid');
		  }
		} else if (fieldId === cfg.selectors.expiry) {
		  // Clear invalid state immediately when user starts typing
		  var $wrap = $(cfg.selectors.expiryWrap);
		  if ($wrap.hasClass('psf-invalid') || $wrap.hasClass('error')) {
			PaysafeCore.clearFieldState(cfg.selectors.expiryWrap);
		  }
		  
		  if (PaysafeCore.parseExpiry(value)) {
			PaysafeCore.applyFieldState(cfg.selectors.expiryWrap, 'valid');
		  } else {
			$wrap.removeClass('psf-valid');
		  }
		} else if (fieldId === cfg.selectors.cvv) {
		  // Clear invalid state immediately when user starts typing
		  var $wrap = $(cfg.selectors.cvvWrap);
		  if ($wrap.hasClass('psf-invalid') || $wrap.hasClass('error')) {
			PaysafeCore.clearFieldState(cfg.selectors.cvvWrap);
		  }
		  
		  if (PaysafeCore.validateCVV(value)) {
			PaysafeCore.applyFieldState(cfg.selectors.cvvWrap, 'valid');
		  } else {
			var $wrap = $(cfg.selectors.cvvWrap);
			$wrap.removeClass('psf-valid');
		  }
		}
	  });
	}

  function attachSavedCvvHandlers() {
	if (typeof PaysafeCore === 'undefined' || !PaysafeCore.attachClearOnInput) {
	  return;
	}
	PaysafeCore.attachClearOnInput('.paysafe-saved-card-cvv-input', 'paysafeSavedCvv');
	
	// Clear all saved CVV error states when switching between saved cards
	$(document).off('change.paysafeSavedCardSwitch', 'input[name="wc-paysafe-payment-token"]')
	  .on('change.paysafeSavedCardSwitch', 'input[name="wc-paysafe-payment-token"]', function() {
		$('.paysafe-saved-card-cvv-input').each(function() {
		  PaysafeCore.clearFieldState($(this));
		  $(this).val(''); // Clear the CVV value when switching cards
		});
		if (typeof PaysafeCore.clearError !== 'undefined') {
		  PaysafeCore.clearError();
		}
	  });
  }

  function attachExpiryFormatter() {
	// Formats MM / YY as user types or pastes; clamps month; caps at 4 digits
	 // Also used by hydrateInitialFormatting() for first render/autofill
	$(document)
	  .off('input.paysafeEPExpiry', cfg.selectors.expiry)
	  .on('input.paysafeEPExpiry', cfg.selectors.expiry, function() {
		if (typeof PaysafeCore === 'undefined' || !PaysafeCore.formatExpiry) return;
		var raw = $(this).val() || '';
		var digits = String(raw).replace(/\D+/g, '').slice(0, 4);
		var formatted = PaysafeCore.formatExpiry(digits);
		var el = this;
		var prev = $(this).val();
		if (formatted !== prev) {
		  $(this).val(formatted);
		  try { el.setSelectionRange(formatted.length, formatted.length); } catch(_e) {}
		}
	  })
	  // Some autofillers fire paste/change but not input
	  .off('paste.paysafeEPExpiry', cfg.selectors.expiry)
	  .on('paste.paysafeEPExpiry', cfg.selectors.expiry, function() {
		var el = this;
		setTimeout(function(){
		  if (typeof PaysafeCore === 'undefined' || !PaysafeCore.formatExpiry) return;
		  var d = String($(el).val() || '').replace(/\D+/g, '').slice(0,4);
		  $(el).val(PaysafeCore.formatExpiry(d));
		}, 0);
	  })
	  .off('change.paysafeEPExpiry', cfg.selectors.expiry)
	  .on('change.paysafeEPExpiry', cfg.selectors.expiry, function() {
		if (typeof PaysafeCore === 'undefined' || !PaysafeCore.formatExpiry) return;
		var d = String($(this).val() || '').replace(/\D+/g, '').slice(0,4);
		$(this).val(PaysafeCore.formatExpiry(d));
	  });
	}

	 // ─────────────────────────────────────────────────────────────────────
	 // EP-only card number: digits-only, groups of 4 with spaces, Luhn check,
	 // and belt-and-suspenders PAN stripping before any real submit.
	 function attachCardNumberFormatter() {
	   // Run when EP-only OR when EP inputs are present in fallback mode
	   if (!(isEPOnlyMode() || epFieldsPresent())) return;

	  // EP-only: strengthen native input characteristics
	  var $in = $(cfg.selectors.number);
	  if ($in.length) {
		$in.attr({ inputmode: 'numeric', autocomplete: 'cc-number', maxlength: 19 }); // 16 digits + 3 spaces
		// Normalize on change as some autofillers only trigger 'change'
		$(document)
		  .off('change.paysafeEPCard', cfg.selectors.number)
		  .on('change.paysafeEPCard', cfg.selectors.number, function(){
			var after = this.value.replace(/\D+/g,'').slice(0,16).replace(/(\d{4})(?=\d)/g,'$1 ').trim();
			if (this.value !== after) this.value = after;
		  });
	  }

	  // Block non-digits on keydown, but allow navigation/erase
	  $(document)
		.off('keydown.paysafeEPCard', cfg.selectors.number)
		.on('keydown.paysafeEPCard', cfg.selectors.number, function(e){
		  var k = e.key;
		  // Allow editing/navigation and common shortcuts
		  if (e.ctrlKey || e.metaKey) return;                    // copy/paste/select-all
		  if (k === 'Backspace' || k === 'Delete' ||
 			  k === 'ArrowLeft' || k === 'ArrowRight' ||
 			  k === 'Home' || k === 'End' || k === 'Tab') return;
 		  // Block any printable non-digit
 		  if (k && k.length === 1 && !/[0-9]/.test(k)) e.preventDefault();
		});

	   function digitsOnly(v){ return (v||'').replace(/\D+/g, ''); }
	   function formatFour(v){
		 v = digitsOnly(v).slice(0, 16);                 // cap to 16 digits
		 return v.replace(/(\d{4})(?=\d)/g, '$1 ').trim();// 4/8/12 spaces
	   }
	   function luhnOk(num){
		 var s = digitsOnly(num); if (s.length !== 16) return false;
		 var sum = 0, alt = false;
		 for (var i = s.length - 1; i >= 0; i--) {
		   var n = s.charCodeAt(i) - 48;
		   if (alt) { n *= 2; if (n > 9) n -= 9; }
		   sum += n; alt = !alt;
		 }
		 return (sum % 10) === 0;
	   }
	   function setValidity(digits){
		 var $wrap = $(cfg.selectors.numberWrap);
		 if (!$wrap.length) return;
		 $wrap.removeClass('psf-valid psf-invalid');
		 if (digits.length === 16) {
		   $wrap.addClass(luhnOk(digits) ? 'psf-valid' : 'psf-invalid');
		 }
	   }

	   // Delegate so we survive checkout fragment reloads
	   $(document)
		 .off('input.paysafeEPCard', '#card_number')
		 .on('input.paysafeEPCard', '#card_number', function(){
		   var before = this.value;
		   var digits = digitsOnly(before).slice(0,16);
		   var after  = formatFour(before);
		   if (before !== after) {
			 this.value = after;
			 try { this.setSelectionRange(after.length, after.length); } catch(_e){}
		   }
		   setValidity(digits);
		 });

	   $(document)
		 .off('paste.paysafeEPCard', '#card_number')
		 .on('paste.paysafeEPCard', '#card_number', function(){
		   var el = this;
		   setTimeout(function(){
			 el.value = formatFour(el.value);
			 setValidity(digitsOnly(el.value));
		   }, 0);
		 });

	   // Never let raw PAN post in EP flows
	   $(document)
		 .off('submit.paysafeStripPan')
		 .on('submit.paysafeStripPan', 'form.checkout, form#order_review, form[name="checkout"]', function(){
		   var el = document.getElementById('card_number');
		   if (el) { el.value = ''; el.removeAttribute('name'); }
		 });
	 }

   // ─────────────────────────────────────────────────────────────────────
   // One-time hydration for autofill/initial render so UI looks correct immediately
   function hydrateInitialFormatting() {
	 if (!isEPOnlyMode()) return;

	 // Card number: format existing value into 4/8/12 spaced groups
	 var $num = $(cfg.selectors.number);
	 if ($num.length) {
	   var nv = String($num.val() || '');
	   if (nv) {
		 nv = nv.replace(/\D+/g, '').slice(0,16).replace(/(\d{4})(?=\d)/g, '$1 ').trim();
		 $num.val(nv);
	   }
	 }

	 // Expiry: normalize any prefilled value through the same formatter
	 var $exp = $(cfg.selectors.expiry);
	 if ($exp.length) {
	   var ev = String($exp.val() || '');
	   if (ev && typeof PaysafeCore !== 'undefined' && PaysafeCore.formatExpiry) {
		 var d = ev.replace(/\D+/g, '').slice(0,4);
		 $exp.val(PaysafeCore.formatExpiry(d));
	   }
	 }
   }

   // ─────────────────────────────────────────────────────────────────────
   // Normalize containers for SAQ-A-EP so DOM/classnames reflect non-hosted fields
   function normalizeEPContainers() {
	 if (!isEPOnlyMode()) return;
	 try {
	   var wraps = [cfg.selectors.numberWrap, cfg.selectors.expiryWrap, cfg.selectors.cvvWrap];
	   wraps.forEach(function(sel){
		 var $el = $(sel);
		 if ($el.length) {
		   $el.removeClass('paysafe-iframe-field').addClass('paysafe-field');
		 }
	   });
	 } catch(_e) {
	   // Never block checkout on a class toggle failure
	 }
   }

  function attachLiveClean() {
	// Remove red ring as user types (clears invalid state)
	$(document)
	  .off('input.paysafeEPClean blur.paysafeEPClean', cfg.selectors.number + ',' + cfg.selectors.expiry + ',' + cfg.selectors.cvv)
	  .on('input.paysafeEPClean blur.paysafeEPClean', cfg.selectors.number + ',' + cfg.selectors.expiry + ',' + cfg.selectors.cvv, function(){
		var key = '#' + this.id;
		if (key === cfg.selectors.number) ringField('number', false);
		if (key === cfg.selectors.expiry) ringField('expiry', false);
		if (key === cfg.selectors.cvv)    ringField('cvv',    false);
	  });
  }

  /* ═══════════════════════════════════════════════════════════════════════
	 VALIDATION - Now using PaysafeCore utilities
	 ═══════════════════════════════════════════════════════════════════════ */

  function validateEP() {
	console.log('🔵 EP Validate: validateEP() called');

	// NOTE: We do NOT check billing fields here. WooCommerce validates billing
	// server-side via AJAX. If billing fails, WooCommerce returns errors and
	// our card validation never runs. This is the correct WooCommerce architecture.
	// Validate if EP mode is active OR the EP inputs are present on the page
	var epActive = isEPOnlyMode() || epFieldsPresent();
	console.log('🔵 EP Active:', epActive, '(EP Only Mode:', isEPOnlyMode(), ', Fields Present:', epFieldsPresent() + ')');
	
	if (!epActive) { 
	  console.log('⏭️ EP Validate: Not active, allowing submission');
	  clearErrors(); 
	  return true; 
	}
	
	if (usingSavedCard()) {
	  console.log('🔵 EP Validate: Using saved card, validating CVV...');
	  
	  var $savedCvv = $('.paysafe-saved-card-cvv-input:visible:enabled');
	  if ($savedCvv.length) {
		// Clear only the active CVV field's error state
		PaysafeCore.clearFieldState($savedCvv);
		clearErrors();
		
		var savedCvvValue = $savedCvv.val() || '';
		var cleanedCvv = PaysafeCore.sanitizeDigits(savedCvvValue);
		
		if (!PaysafeCore.validateCVV(cleanedCvv)) {
		  console.log('❌ Saved card CVV invalid');
		  
		  PaysafeCore.applyFieldState($savedCvv, 'invalid');

		// Build specific selector for only this CVV field (use ID if available, otherwise class + :visible:enabled)
		var specificSelector = $savedCvv.attr('id') ? ('#' + $savedCvv.attr('id')) : '.paysafe-saved-card-cvv-input:visible:enabled';
		  
		  if (PaysafeCore.showError) {
			PaysafeCore.showError(cfg.i18n.invalid_cvv || 'Please enter a valid security code', {
			  title: 'Please check your security code',
			fieldSelector: specificSelector
			});
		  } else {
			showErrors([cfg.i18n.invalid_cvv || 'Please enter a valid security code']);
		  }
		  
		  return false;
		}
		console.log('✅ Saved card CVV valid');
	  }
	  
	  return true;
	}
	
	if (hasPaymentToken()) {
	  console.log('⏭️ EP Validate: Token exists, allowing submission');
	  return true; // token already created by payment-form.js
	}

	// Check if PaysafeCore is available
	if (typeof PaysafeCore === 'undefined') {
	  console.error('❌ PaysafeCore not loaded - validation may not work correctly');
	  return true; // Allow submission rather than blocking
	}

	var errors = [];

	// Use PaysafeCore utilities for sanitization and validation
	var numberRaw = $(cfg.selectors.number).val();
	var expiryRaw = $(cfg.selectors.expiry).val();
	var cvvRaw    = $(cfg.selectors.cvv).val();
	var number = PaysafeCore.sanitizeCardNumber(numberRaw);
	var expiry = PaysafeCore.parseExpiry(expiryRaw);
	var cvv    = PaysafeCore.sanitizeDigits(cvvRaw);

	clearRings();

	// Card number validation
	if (!PaysafeCore.luhnCheck(number)) {
	  console.log('❌ Card number invalid');
	  errors.push(cfg.i18n.invalid_number);
	  ringField('number', true);
	} else {
	  console.log('✅ Card number valid');
	}

	// Expiry validation
	if (!expiry) {
	  console.log('❌ Expiry invalid');
	  errors.push(cfg.i18n.invalid_expiry);
	  ringField('expiry', true);
	} else {
	  console.log('✅ Expiry valid');
	}

	// CVV validation
	if (!PaysafeCore.validateCVV(cvv)) {
	  console.log('❌ CVV invalid');
	  errors.push(cfg.i18n.invalid_cvv);
	  ringField('cvv', true);
	} else {
	  console.log('✅ CVV valid');
	}

	if (errors.length) {
	  console.log('❌ EP Validate: Validation FAILED -', errors.length, 'errors');
	  showErrors(errors);
	  attachLiveClean();
	  return false;
	}
	
	console.log('✅ EP Validate: Validation PASSED');
	clearErrors();
	return true;
  }

  /* ═══════════════════════════════════════════════════════════════════════
	 INITIALIZATION & EVENT BINDING
	 ═══════════════════════════════════════════════════════════════════════ */

  // CRITICAL: Intercept button click for direct tokenization (SAQ-A-EP mode)
  // Uses Decision Engine to determine if this handler should run
  function interceptPlaceOrder() {
	console.log('🔵 EP Validate: Setting up button click interceptor');
	
	// Check if Decision Engine is available
	if (typeof window.PaysafeDecisionEngine === 'undefined') {
	  console.warn('⚠️ Decision Engine not loaded, using legacy EP detection');
	  // Legacy fallback: Only intercept if hosted fields don't exist
	  if (window.paysafeFieldsInstance && !window.paysafeHostedFieldsFailed) {
		console.log('⏭️ EP Validate: Hosted fields exist (legacy check), not intercepting');
		return;
	  }
	} else {
	  // Use Decision Engine to determine if we should intercept
	  const decision = window.PaysafeDecisionEngine.getPaymentFlow();
	  
	  // Only intercept for direct_tokenize flow (SAQ-A-EP mode or fallback)
	  // For hosted_tokenize, let payment-form.js handle it
	  if (decision.flow !== 'direct_tokenize') {
		console.log('⏭️ EP Validate: Flow is', decision.flow, '- not intercepting button clicks');
		console.log('   (payment-form.js will handle tokenization if needed)');
		return; // Don't attach handler
	  }
	  
	  console.log('✅ EP Validate: Flow is direct_tokenize - attaching EP button interceptor');
	}
	
	// Remove any existing handler first
	$(document).off('click.paysafeEPButton', '#place_order');
	
	// Bind to button click with capturing phase
	$(document).on('click.paysafeEPButton', '#place_order', function(e) {
	  console.log('🔵 EP Validate: Place Order button clicked (intercepted for direct tokenization)');
	  
	  // Use Decision Engine to determine what to do
	  if (typeof window.PaysafeDecisionEngine === 'undefined') {
		console.warn('⚠️ Decision Engine not available in click handler, using legacy logic');
		// Fall back to legacy logic below
	  } else {
		const decision = window.PaysafeDecisionEngine.getPaymentFlow();
		console.log('🔵 EP Validate: Decision Engine result:', decision.flow, 'ready:', decision.ready);
		
		// Not our gateway or not Paysafe
		if (decision.flow === 'not_paysafe') {
		  return true;
		}
		
		// Saved card - let server handle
		if (decision.flow === 'saved_card') {
		  console.log('✅ EP Validate: Saved card flow, allowing submission');
		  return true;
		}
		
		// Already has token
		if (decision.flow === 'already_tokenized') {
		  console.log('✅ EP Validate: Already tokenized, allowing submission');
		  return true;
		}
		
		// Hosted fields should tokenize (shouldn't reach here if interceptPlaceOrder logic is correct)
		if (decision.flow === 'hosted_tokenize') {
		  console.log('⏭️ EP Validate: Hosted tokenize flow detected, deferring to payment-form.js');
		  return true;
		}
		
		// Direct tokenization - this is what we handle!
		if (decision.flow === 'direct_tokenize') {
		  if (!decision.ready) {
			console.log('❌ EP Validate: Not ready for tokenization');
			if (decision.errors.length > 0) {
			  showErrors(decision.errors);
			}
			if (e && e.preventDefault) e.preventDefault();
			return false;
		  }
		  
		  console.log('✅ EP Validate: Ready for direct tokenization, proceeding...');
		  
		  // Check if already tokenizing
		  if ($('#place_order').data('paysafe-ep-tokenizing')) {
			return false;
		  }
		  $('#place_order').data('paysafe-ep-tokenizing', true);
		  
		  // Show processing state
		  var $psWrapper = jQuery('.paysafe-payment-wrapper');
		  if ($psWrapper.length) { $psWrapper.addClass('processing'); }
		  var $psOverlay = jQuery('#paysafe-processing-overlay');
		  if ($psOverlay.length) { $psOverlay.addClass('is-visible'); }
		  
		  // Prevent default form submission
		  if (e && e.preventDefault) { 
			e.preventDefault(); 
			if (e.stopPropagation) e.stopPropagation(); 
			if (e.stopImmediatePropagation) e.stopImmediatePropagation(); 
		  }
		  
		  // Get field values
		  var number = $(cfg.selectors.number).val().replace(/\s+/g,'');
		  var cvv    = $(cfg.selectors.cvv).val().replace(/\s+/g,'');
		  var exp = (window.PaysafeCore && PaysafeCore.parseExpiry)
			? PaysafeCore.parseExpiry($(cfg.selectors.expiry).val())
			: (function(){
				var raw = ($(cfg.selectors.expiry).val() || '');
				var mm  = (raw.split('/')[0] || '').replace(/\D/g,'');
				var yy  = (raw.split('/')[1] || '').replace(/\D/g,'');
				if (yy && yy.length === 2) { yy = '20' + yy; }
				return { month: mm, year: yy };
			  })();
		  var mm = String(exp && exp.month ? exp.month : '').replace(/\D/g,'');
		  var yy = String(exp && exp.year  ? exp.year  : '').replace(/\D/g,'');
		  if (yy && yy.length === 2) { yy = '20' + yy; }
		  
		  // Create token via AJAX
		  try {
			$.ajax({
			  url: (window.paysafe_params && paysafe_params.ajax_url) || (window.ajaxurl || ''),
			  type: 'POST',
			  dataType: 'json',
			  data: {
				action: (window.paysafe_params && paysafe_params.ajax_action_tokenize) || 'paysafe_create_single_use_token',
				nonce: (window.paysafe_params && paysafe_params.nonce) || '',
				card_number: number,
				exp_month: mm,
				exp_year:  yy,
				cvv: cvv
			  }
			}).done(function(resp){
			  if (resp && resp.success && resp.data && resp.data.payment_token) {
				var $form = $('form.checkout, form#order_review, form#add_payment_method').first();
				$form.find('input[name="paysafe_payment_token"]').remove();
				$('<input type="hidden" name="paysafe_payment_token">').val(resp.data.payment_token).appendTo($form);
				if (resp.data.last4) $('<input type="hidden" name="paysafe_card_last4">').val(resp.data.last4).appendTo($form);
				if (resp.data.brand) $('<input type="hidden" name="paysafe_card_type">').val(String(resp.data.brand).toLowerCase()).appendTo($form);
				$form.trigger('submit');
			  } else {
				var msg = (resp && resp.data && (resp.data.message || resp.data.error)) || 'Tokenization failed. Please check your card details and try again.';
				showErrors([msg]);
			  }
			}).fail(function(){
			  showErrors(['Unable to tokenize card at this time. Please try again.']);
			}).always(function(){
			  $('#place_order').data('paysafe-ep-tokenizing', false);
			  jQuery('.paysafe-payment-wrapper').removeClass('processing');
			  jQuery('#paysafe-processing-overlay').removeClass('is-visible');
			});
		  } catch (_err) {
			$('#place_order').data('paysafe-ep-tokenizing', false);
			jQuery('.paysafe-payment-wrapper').removeClass('processing');
			jQuery('#paysafe-processing-overlay').removeClass('is-visible');
			showErrors(['Unexpected error while preparing card data. Please try again.']);
			return false;
		  }
		  return false;
		}
		
		// Unknown flow
		console.warn('⚠️ Unknown payment flow:', decision.flow);
		return true;
	  }
	  
	  // LEGACY FALLBACK (if Decision Engine not available)
	  console.log('🔵 Using legacy EP validation logic');
	  var selectedGateway = $('input[name="payment_method"]:checked').val();
	  if (selectedGateway !== 'paysafe') {
		return true;
	  }
	  
	  // Legacy check: defer to hosted fields if they exist
	  if (window.paysafeFieldsInstance && !window.paysafeHostedFieldsFailed) {
		return true;
	  }
	  
	  // Allow through - let server validate
	  return true;
	});
  }

  // WooCommerce triggers: return true to allow submit, false to block
  // NOTE: This fires BEFORE WooCommerce's AJAX submission. WooCommerce will
  // validate billing fields server-side. We only validate card fields here.
  $(document.body).on('checkout_place_order_paysafe', function(e){
	console.log('🔵 EP Validate: checkout_place_order_paysafe event triggered');

  // Always allow form submission - server handles validation sequence:
  // 1. WooCommerce validates billing/shipping fields
  // 2. If billing passes, our validate_fields() PHP validates card fields
  // 3. Card errors only show after billing is valid
  console.log('✅ EP Validate: Allowing form submission for server-side validation');
  return true;
  });

  // Hard fallback if the above doesn't fire on a given theme/site:
  $(document).on('submit.paysafeEP', 'form.checkout, form#order_review, form#add_payment_method', function(e){
	console.log('🔵 EP Validate: Direct form submit event triggered');

	// Only run once per submit attempt
	if ($(this).data('paysafe-ep-validating')) {
	  console.log('⏭️ EP Validate: Already validating, skipping');
	  return;
	}

	// Card validation only - billing is validated server-side by WooCommerce
	var selectedGateway = $('input[name="payment_method"]:checked').val();
	if (selectedGateway !== 'paysafe') {
	  return true; // Not our gateway
	 }

	// For EP mode with new cards, we need to create a token before submitting
	// But we don't validate here - server will validate everything

	// ✅ If validation passed and no token yet for new card, create it now then resubmit
	if ((isEPOnlyMode() || epFieldsPresent()) && $('input[name="payment_method"]:checked').val() === 'paysafe') {
	  // CRITICAL FIX: Default to "new card" if no saved cards section exists
	  var $newRadio = $(cfg.selectors.newRadio);
	  var $savedRadio = $(cfg.selectors.savedRadio);
	  var usingNew = true; // Default to new card
	  
	  // Only check radios if saved cards section exists
	  if ($newRadio.length && $savedRadio.length) {
		usingNew = $newRadio.is(':checked');
	  } else if ($('input[name="wc-paysafe-payment-token"]').length) {
		// Fallback: check WooCommerce token field
		var tokenVal = $('input[name="wc-paysafe-payment-token"]').val();
		usingNew = !tokenVal || tokenVal === 'new';
	  }
	  
	  var hasToken = $('input[name="paysafe_payment_token"]').filter(function(){ return $(this).val(); }).length > 0;
	  if (usingNew && !hasToken) {
		e.preventDefault();
		e.stopPropagation();
		e.stopImmediatePropagation();
	var number = $(cfg.selectors.number).val().replace(/\s+/g,'');
	var cvv    = $(cfg.selectors.cvv).val().replace(/\s+/g,'');
	// Use core parser so we always submit a 4-digit year per Paysafe spec
	var exp = (window.PaysafeCore && PaysafeCore.parseExpiry)
	  ? PaysafeCore.parseExpiry($(cfg.selectors.expiry).val())
	  : (function(){
		  var raw = ($(cfg.selectors.expiry).val() || '');
		  var mm  = (raw.split('/')[0] || '').replace(/\D/g,'');
		  var yy  = (raw.split('/')[1] || '').replace(/\D/g,'');
		  // Fallback: coerce 2-digit -> 20YY
		  if (yy && yy.length === 2) { yy = '20' + yy; }
		  return { month: mm, year: yy };
		})();
		$.ajax({
		  url: (window.paysafe_params && paysafe_params.ajax_url) || (window.ajaxurl || ''),
		  type: 'POST',
		  dataType: 'json',
	  data: {
		action: (window.paysafe_params && paysafe_params.ajax_action_tokenize) || 'paysafe_create_single_use_token',
		nonce: (window.paysafe_params && paysafe_params.nonce) || '',
		card_number: number,
		exp_month: exp.month,   // ← server expects exp_month
		exp_year:  exp.year,    // ← server expects exp_year (YYYY)
		cvv: cvv
	  }
		}).done(function(resp){
		if (resp && resp.success && resp.data && (resp.data.payment_token || resp.data.token)) {
			var $form = $('form.checkout, form#order_review, form#add_payment_method').first();
			$form.find('input[name="paysafe_payment_token"]').remove();
		$('<input type="hidden" name="paysafe_payment_token">').val(resp.data.payment_token || resp.data.token).appendTo($form);
		if (resp.data.last4)  $('<input type="hidden" name="paysafe_card_last4">').val(resp.data.last4).appendTo($form);
		if (resp.data.brand)  $('<input type="hidden" name="paysafe_card_type">').val(String(resp.data.brand).toLowerCase()).appendTo($form);
			$form.trigger('submit');
		  } else {
			var msg = (resp && resp.data && (resp.data.message || resp.data.error)) || 'Tokenization failed. Please check your card details and try again.';
			showErrors([msg]);
		  }
		}).fail(function(){
		  showErrors(['Unable to tokenize card at this time. Please try again.']);
		});
		return false;
	  }
	}
  });

  // Attach live state handlers when document is ready or on checkout updates
  $(document).ready(function() {
	console.log('🔵 EP Validate: Document ready');
	console.log('🔵 EP Only Mode:', isEPOnlyMode());
	console.log('🔵 EP Fields Present:', epFieldsPresent());
	console.log('🔵 Fields found:', {
	  number: $(cfg.selectors.number).length,
	  expiry: $(cfg.selectors.expiry).length,
	  cvv: $(cfg.selectors.cvv).length
	});
	
	if (isEPOnlyMode() || epFieldsPresent()) {
	  console.log('✅ EP Validate: Conditions met, attaching handlers');
	  attachLiveStateHandlers();
	  attachSavedCvvHandlers();
	  attachExpiryFormatter();
	  attachCardNumberFormatter();
	  hydrateInitialFormatting(); // ensure autofill/initial values are shaped
	  normalizeEPContainers();
	  interceptPlaceOrder(); // CRITICAL: Intercept button clicks
	} else {
	  console.log('⏭️ EP Validate: Conditions not met, skipping handler attachment');
	  attachSavedCvvHandlers();
	}
 
 	// On BFCache restore (Safari/Firefox), re-format autofilled values
 	$(window).off('pageshow.paysafeEP').on('pageshow.paysafeEP', function(){
 	  if (isEPOnlyMode() || epFieldsPresent()) {
 		hydrateInitialFormatting();
 	  }
 	});
  });

  // Re-attach on WooCommerce checkout updates
  $(document.body).on('updated_checkout', function() {
	console.log('🔵 EP Validate: Checkout updated event');
	if (isEPOnlyMode() || epFieldsPresent()) {
	  console.log('✅ EP Validate: Re-attaching handlers after checkout update');
	  attachLiveStateHandlers();
	  attachSavedCvvHandlers();
	  attachExpiryFormatter();
	  attachCardNumberFormatter();
	  hydrateInitialFormatting(); // re-shape after fragment swaps/autofill
	  normalizeEPContainers();
	  interceptPlaceOrder(); // CRITICAL: Re-intercept after AJAX updates
	} else {
	  attachSavedCvvHandlers();
	}
  });

  // Expose a small public API for higher-level checkout flow/orchestrator
  // without changing any existing behavior. This lets other modules call
  // EP validation and UX helpers in a controlled way, while all of the
  // existing field formatting, icons, spacing and tooltips remain owned
  // by this file.
  window.PaysafeCardValidator = window.PaysafeCardValidator || {
	validateEP: validateEP,
	clearErrors: clearErrors,
	clearRings: clearRings,
	attachLiveUX: function() {
	  attachLiveStateHandlers();
	  attachSavedCvvHandlers();
	  attachExpiryFormatter();
	  attachCardNumberFormatter();
	  hydrateInitialFormatting();
	  normalizeEPContainers();
	  attachLiveClean();
	}
  };

  console.log('✅ EP Validate: Script loaded and initialized');
  console.log('🔵 EP Validate: Configured selectors:', cfg.selectors);
  console.log('🔵 EP Validate: Mode:', cfg.mode);

})(jQuery);

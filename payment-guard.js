/* Payment submit guard: keep Place Order enabled, block when not ready/valid.
  * File: /assets/js/payment-guard.js
  * No processor names in user-facing messages
  * Uses CSS classes already present on the hosted field containers:
  * .psf-connected / .psf-valid / .psf-invalid / .error
  * Compatible with Paysafe JS Hosted Fields (iframe)
  * 
  * NOTE: This script validates HOSTED CARD FIELDS only (iframes).
  * Billing/shipping validation is handled SERVER-SIDE by WooCommerce.
  * 
  * Version: 2.0.0 - Card validation only, billing handled by WooCommerce server-side
  * Last updated: 2025-12-10
 */
 
(function (jQuery) {
  'use strict';
  /* global jQuery, MutationObserver */

  const CONTAINERS = Object.freeze(['#cardNumber_container', '#cardExpiry_container', '#cardCvv_container']);

  // Safe DOM helper (avoid shadowing jQuery's $)
  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }

  // Read PCI mode from localized params (defaults to fallback mode)
  function pciMode() {
	const m =
	  (window.paysafe_params && window.paysafe_params.pci_compliance_mode) ||
	  'saq_a_with_fallback';
	// Normalize to lower-case to avoid strict-compare mismatches
	return String(m).toLowerCase();
  }

  // Re-armable observer instance for Woo checkout DOM replacements
  let _psfGuardObserver = null; // re-armable MutationObserver instance

  // Helper to safely stop and clear the current observer
  function isPaysafeSelected() {
	const m = qs('input[name="payment_method"]:checked');
	return !!(m && m.value === 'paysafe');
  }
  function usingSavedCard() {
	// Prefer explicit selection when radios are present
	const selection = qs('input[name="paysafe-card-selection"]:checked');
	if (selection) {
	  if (selection.value === 'saved') {
		const tokenRadio = qs('input[name="wc-paysafe-payment-token"]:checked');
		return !!(tokenRadio && tokenRadio.value && tokenRadio.value !== 'new');
	  }
	  return false;
	}
	// Fallback for themes/templates that don't render the selection radios
	const savedToggle = qs('#paysafe-use-saved-card');
	const tokenRadio  = qs('input[name="wc-paysafe-payment-token"]:checked');
	return !!(
	  (savedToggle && savedToggle.checked) ||
	  (tokenRadio && tokenRadio.value && tokenRadio.value !== 'new')
	);
  }

function state() {
	const s = { connected: true, valid: true, details: {} };
	for (const sel of CONTAINERS) {
	  const el = qs(sel);
	  const connected = !!(el && el.classList.contains('psf-connected'));
	  const valid = !!(el && el.classList.contains('psf-valid') && !el.classList.contains('psf-invalid') && !el.classList.contains('error'));
	  s.details[sel] = { connected, valid };
	  s.connected = s.connected && connected;
	  s.valid = s.valid && valid;
	}
	return s;
  }

  // Use payment-form.js's error handling (single source of truth)
  function showError(msg) {
	if (window.__ps_inlineError) {
	  window.__ps_inlineError(msg);
	} else {
	  // Fallback if payment-form.js hasn't loaded yet (shouldn't happen)
	  console.error('Paysafe validation error:', msg);
	}
  }

  function hideError() {
	if (window.__ps_clearInlineError) {
	  window.__ps_clearInlineError();
	}
  }

function guardSubmit() {
	// Use Decision Engine as single source of truth
	if (typeof window.PaysafeDecisionEngine === 'undefined') {
	  // Fallback: Decision engine not loaded yet (shouldn't happen)
	  console.warn('Paysafe Decision Engine not loaded, using legacy logic');
	  if (!isPaysafeSelected()) return true;
	  const mode = pciMode();
	  if (mode === 'saq_aep_only') return true;
	  if (usingSavedCard()) return true;
	  if (!window.paysafeFieldsInstance) return true;
	  const s = state();
	  if (!s.connected) {
		showError('Card fields are still loading. Please wait a moment and try again.');
		return false;
	  }
	  if (!s.valid) {
		showError('Please complete all card fields (number, expiry, security code).');
		return false;
	  }
	  hideError();
	  return true;
	}
	
	// Get decision from engine
	const decision = window.PaysafeDecisionEngine.getPaymentFlow();
	
	// Debug logging (if enabled)
	if (window.paysafe_params && window.paysafe_params.debug) {
	  console.log('[Payment Guard] Decision:', decision);
	}
	
	// If ready, allow submit
	if (decision.ready) {
	  hideError();
	  return true;
	}
	
	// Not ready - show appropriate error
	if (decision.errors.length > 0) {
	  showError(decision.errors.join('\n'));
	} else {
	  showError('Please complete all required fields');
	}
	
	// Try to scroll first invalid field into view
	try {
	  const firstInvalid = CONTAINERS
		.map(function (sel) { return qs(sel); })
		.find(function (el) { return el && (!el.classList.contains('psf-valid') || el.classList.contains('psf-invalid') || el.classList.contains('error')); });
	  if (firstInvalid && typeof firstInvalid.scrollIntoView === 'function') { 
		firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' }); 
	  }
	} catch (_e) {}
	
	return false;
  }

  // Centralized observer teardown to avoid duplication and leaks
  function disconnectObserver() {
	if (_psfGuardObserver) {
	  try {
		_psfGuardObserver.disconnect();
	  } catch (_e) {
		/* noop */
	  }
	  _psfGuardObserver = null;
	}
  }

  // WooCommerce gateway-specific guard (namespaced; prevents duplicate handlers)
  jQuery(document.body)
	.off('checkout_place_order_paysafe.paysafeguard')
	.on('checkout_place_order_paysafe.paysafeguard', function () {
	  try { return guardSubmit(); } catch (_e) { return true; }
	});

  // Defensive hook: if a theme/plugin bypasses the event, guard the raw form submit
  jQuery(document)
	.off('submit.paysafeguard', 'form.checkout')
	.on('submit.paysafeguard', 'form.checkout', function (e) {
	  let ok = true;
	  try { ok = guardSubmit(); } catch (_e) { ok = true; }
	  if (isPaysafeSelected() && !ok) {
		e.preventDefault();
		e.stopImmediatePropagation();
		return false;
	  }
	});

  // Add Payment Method: per-gateway event (if emitted by theme/core) and raw form guard
  jQuery(document.body)
	.off('add_payment_method_paysafe.paysafeguard')
	.on('add_payment_method_paysafe.paysafeguard', function () {
	  try { return guardSubmit(); } catch (_e) { return true; }
	});

  jQuery(document)
	.off('submit.paysafeguard', 'form#add_payment_method')
	.on('submit.paysafeguard', 'form#add_payment_method', function (e) {
	  let ok = true;
	  try { ok = guardSubmit(); } catch (_e) { ok = true; }
	  if (isPaysafeSelected() && !ok) {
		e.preventDefault();
		e.stopImmediatePropagation();
		return false;
	  }
	});

  // Pay for Order page (order-pay endpoint) uses #order_review form
  jQuery(document)
	.off('submit.paysafeguard', 'form#order_review')
	.on('submit.paysafeguard', 'form#order_review', function (e) {
	  let ok = true;
	  try { ok = guardSubmit(); } catch (_e) { ok = true; }
	  if (isPaysafeSelected() && !ok) {
		e.preventDefault();
		e.stopImmediatePropagation();
		return false;
	  }
	});

  // Live tidy-up observer (re-armable across Woo refreshes)
  function setupObservers() {
	if (typeof MutationObserver === 'undefined') return;
	disconnectObserver();
	const targets = CONTAINERS.map(sel => qs(sel)).filter(Boolean);
	if (!targets.length) return;
	_psfGuardObserver = new MutationObserver(function () {
	  const s = state();
	  if (s.connected && s.valid) hideError();
	});
	targets.forEach(function (t) {
	  _psfGuardObserver.observe(t, { attributes: true, attributeFilter: ['class'] });
	});
  }

  // Initial observer
  setupObservers();

  // Re-arm after Woo refreshes the checkout UI, add-payment-method UI, or payment method changes
  jQuery(document.body)
	.off('updated_checkout.paysafeguard init_checkout.paysafeguard payment_method_selected.paysafeguard wc-credit-card-form-init.paysafeguard init_add_payment_method.paysafeguard updated_wc_div.paysafeguard')
	.on('updated_checkout.paysafeguard init_checkout.paysafeguard payment_method_selected.paysafeguard wc-credit-card-form-init.paysafeguard init_add_payment_method.paysafeguard updated_wc_div.paysafeguard', function () {
	  if (isPaysafeSelected()) {
		setupObservers();
	  } else {
		hideError();
		disconnectObserver();
	  }
	});

  // Auto-hide error when user switches to a saved card
  jQuery(document)
	.off('change.paysafeguard-saved', '#paysafe-use-saved-card, input[name="wc-paysafe-payment-token"]')
	.on('change.paysafeguard-saved', '#paysafe-use-saved-card, input[name="wc-paysafe-payment-token"]', function () {
	  try { if (usingSavedCard()) { hideError(); } } catch (_e) {}
	});

})(jQuery);

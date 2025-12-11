/**
 * Paysafe Payment Form JavaScript
 * File: /assets/js/payment-form.js
 * Handles direct card input and payment processing
 * Compatible with Paysafe API (formerly Payfirma/Merrco)
 * Version: 1.0.4 - With PCI Compliant Tokenization
  * Last updated: 2025-12-10
 */

window.paysafe_params = window.paysafe_params || {};

(function($) {
	'use strict';

	/* ──────────────────────────────────────────────────────────────────────────
	   Paysafe front-end debug gating (tiny, non-invasive)
	   - Source of truth: paysafe_params.debug (set via wp-admin option you control)
	   - Effect: Only console.log is gated; console.error/warn/etc remain unchanged
	   - Scope: This file only (local shadow of `console`)
	   - Safety: If anything goes wrong, we silently no-op and never break runtime
	   ────────────────────────────────────────────────────────────────────────── */
	/* eslint-disable no-shadow */
	var __PSAFE_CONSOLE__ = (typeof window !== 'undefined' && window.console) ? window.console : {};
	var __PS_DEBUG__ = !!(window.paysafe_params && paysafe_params.debug);
	var __NOOP__ = function() {};
	var __DLOG__ = (__PS_DEBUG__ && typeof __PSAFE_CONSOLE__.log === 'function')
		? __PSAFE_CONSOLE__.log.bind(__PSAFE_CONSOLE__)
		: __NOOP__;
	// Shadow global console within this IIFE so only this file is affected.
	var console = Object.assign({}, __PSAFE_CONSOLE__, { log: __DLOG__ });
	// Ensure other console methods are always callable; if a browser has no console,
	// keep behavior a safe no-op rather than throwing TypeErrors on console.error/warn/etc.
	['error','warn','info','debug','trace','table','group','groupCollapsed','groupEnd'].forEach(function(k){
		if (typeof console[k] !== 'function') { console[k] = __NOOP__; }
	});
	// Optional helper if you want to log explicitly elsewhere: window.__paysafe_dlog(...)
	if (typeof window !== 'undefined') { window.__paysafe_dlog = __DLOG__; }
	/* eslint-enable no-shadow */

	/* ──────────────────────────────────────────────────────────────────────────
	   Minimal UI helper shims (avoid ReferenceError if not provided elsewhere)
	   They integrate with this file's inline error when possible and otherwise
	   degrade to very light feedback.
	   ────────────────────────────────────────────────────────────────────────── */
	function showLoading(){
		try { jQuery('.paysafe-payment-wrapper,#paysafe-payment-form').addClass('processing'); } catch(_e){}
	}
	function hideLoading(){
		try { jQuery('.paysafe-payment-wrapper,#paysafe-payment-form').removeClass('processing'); } catch(_e){}
	}
	function showError(msg, fieldSelector){
		try {
			// Use PaysafeCore.showError for unified dark gradient error box + red rings
			if (typeof window.PaysafeCore !== 'undefined' && window.PaysafeCore.showError) {
				// Convert string with \n to array of messages
				var messages = String(msg||'').split('\n').filter(function(m) { return m.trim(); });
				// Default to card number field if no specific field provided
				var selector = fieldSelector || '#cardNumber_container, #card_number, #paysafe-card-number-field';
				window.PaysafeCore.showError(messages, { 
					fieldSelector: selector,
					sticky: true 
				});
			} else if (typeof window.__ps_inlineError === 'function') {
				// Inline error uses text(); send \n and let CSS render newlines
				window.__ps_inlineError(String(msg||''));
			} else {
				// Fallback renders HTML; convert \n to <br>
				var $wrap = jQuery('.paysafe-payment-wrapper:first');
				var htmlMsg = String(msg||'').replace(/\n/g, '<br>');
				if ($wrap.length){ $wrap.prepend('<div class="woocommerce-error" role="alert">'+ htmlMsg +'</div>'); }
				else { alert(String(msg||'')); }
			}
		} catch(_e){}
	}
	function showSuccess(msg){
		try {
			// Keep it subtle; Woo will redirect/refresh on success anyway
			if (window.__paysafe_dlog) window.__paysafe_dlog('Success:', String(msg||''));
		} catch(_e){}
	}

	// Single source of truth for card-brand detection
	function getCardBrandRegexMap() {
		return {
			visa: /^4/,
			/* Cover 51–55 and 2221–2720 leading ranges */
			mastercard: /^(?:5[1-5]|222[1-9]|22[3-9]\d|2[3-6]\d{2}|27[01]\d|2720)/,
			amex: /^3[47]/,
			discover: /^(?:6011|65|64[4-9]|622(?:12[6-9]|1[3-9]\d|[2-8]\d{2}|9(?:[01]\d|2[0-5])))/,
			jcb: /^(?:35|2131|1800)/,
			diners: /^(?:30[0-5]|36|38|39)/
		};
	}

	let isInitialized = false;
	let isTokenizing = false;

	// Safe no-op for wallet initialization (prevents ReferenceError if not implemented elsewhere)
	function initializeDigitalWallets() { /* intentionally empty */ }

	// Minimal transport-time text sanitizer for names (defensive; server MUST still use prepared statements)
	function _safeText(s){
		try {
			return String(s || '')
				.replace(/[^\p{L}\p{N}\s\-\.'’]/gu, '') // keep letters/numbers/space/-/./'/’
				.replace(/\s+/g, ' ')
				.trim();
		} catch(_e){
			// Fallback for older browsers without Unicode regex
			return String(s || '')
				.replace(/[^A-Za-z0-9 \-.'’]/g, '')
				.replace(/\s+/g, ' ')
				.trim();
		}
	}

	// Parse currency amount from inputs/DOM, handling commas and symbols
	function readAmount() {
		var raw = ($('input[name="amount"]').val() ||
				   $('#order_review .order-total .amount').text() || '').trim();
		if (!raw) return '0';
		var s = raw.replace(/[^\d.,-]/g, '');
		if (s.indexOf('.') >= 0 && s.indexOf(',') >= 0 && s.lastIndexOf(',') > s.lastIndexOf('.')) {
			s = s.replace(/\./g, '');
			s = s.replace(',', '.');
		} else if (s.indexOf(',') >= 0 && s.indexOf('.') < 0) {
			s = s.replace(',', '.');
		}
		s = s.replace(/,/g, '');
		var m = s.match(/-?\d+(?:\.\d+)?/);
		return m ? m[0] : '0';
	}

	function readCurrency() {
		return ($('input[name="currency"]').val() || paysafe_params.currency || 'CAD').toUpperCase();
	}

	function _fmt2(v) {
		var n = parseFloat(v);
		return isFinite(n) ? n.toFixed(2) : '0.00';
	}

	function _fmt2pos(v) {
		var n = parseFloat(v);
		n = isFinite(n) ? n : 0;
		if (n < 0) n = 0;
		return n.toFixed(2);
	}

	function paysafeAssetUrl(filename) {
		let base = (window.paysafe_params && paysafe_params.plugin_url) ? String(paysafe_params.plugin_url) : '';
		base = base.replace(/[?#].*$/, '');
		if (base && base.charAt(base.length - 1) !== '/') {
			base += '/';
		}
		return (base ? base : '') + 'assets/images/' + filename;
	}

	window.initializePaysafeTokenization = function() {
		console.log('🔵 initializePaysafeTokenization called');

		// Double-init guard (throttle rapid repeat calls from updated_checkout, etc.)
		if (window.__paysafeInitAt && (Date.now() - window.__paysafeInitAt) < 500) { 
			console.log('❌ Blocked by throttle (called too quickly)');
			return; 
		}
		window.__paysafeInitAt = Date.now();
		console.log('✓ Throttle check passed');

		const pciMode = (window.paysafe_params && paysafe_params.pci_compliance_mode) || 'saq_a_with_fallback';
		window.paysafePCIMode = pciMode;
		console.log('✓ PCI Mode set to:', pciMode);

		// Only proceed if Paysafe.js is present
		console.log('Checking paysafe object:', typeof paysafe);
		console.log('Checking paysafe.fields:', (typeof paysafe === 'undefined') ? 'undefined' : typeof paysafe.fields);

		if (typeof paysafe !== 'undefined' && paysafe.fields) {
			console.log('✓ Paysafe.js detected');

			if ((pciMode === 'saq_a_only' || pciMode === 'saq_a_with_fallback') && $('input[name="payment_method"]:checked').val() === 'paysafe') {
				console.log('🟢 Calling initializeSecureFields()...');
				initializeSecureFields();
			} else {
				console.log('❌ PCI mode doesn\'t require hosted fields:', pciMode);
			}
		} else {
			console.log('❌ Paysafe.js NOT available yet');
		}
	};

	function initializeSecureFields() {
		console.log('🟡 initializeSecureFields() started');

		// Prefer the mode chosen during initializePaysafeTokenization; fall back to params.
		const pciMode = (window.paysafePCIMode || (paysafe_params && paysafe_params.pci_compliance_mode) || 'saq_a_with_fallback');
		console.log('PCI mode:', pciMode);

		// SAQ-A only: hide legacy inputs immediately so there is zero native fallback.
		// (Idempotent: createShadowFields() safely no-ops if already applied.)
		if (pciMode === 'saq_a_only') {
			try { createShadowFields(); } catch(_e) {}
		}

		// If WooCommerce replaced the DOM, previously mounted iframes may be gone. Allow re-init.
		if (window.paysafeFieldsInstance && !$('#cardNumber_container iframe').length) {
			console.log('Clearing stale instance');
			window.paysafeFieldsInstance = null;
		}

		// Check if already initialized successfully
		if (window.paysafeFieldsInstance && !window.paysafeHostedFieldsFailed) {
			if (paysafe_params && paysafe_params.debug) { console.log('Paysafe: Already initialized successfully, skipping'); }
			return;
		}

		console.log('Checking for containers...');

		// Wait for hosted fields container to exist
		if (!$('#cardNumber_container').length) {
			// In SAQ-A only we must *not* fall back; keep waiting until containers appear.
			if ($('#card_number').length && pciMode !== 'saq_a_only') { return; }
			if (paysafe_params && paysafe_params.debug) { console.log('Paysafe: Card fields not ready, waiting...'); }
			setTimeout(initializeSecureFields, 100);
			return;
		}

		console.log('✓ Container found');

		/* Ensure the real Paysafe mount nodes get our visual class
		   so CSS parity (padding-left & focus ring) applies immediately. */
		jQuery('#cardNumber_container,#cardExpiry_container,#cardCvv_container')
		  .addClass('paysafe-iframe-field');

		// Default to "not connected" visually until setup succeeds
		__ps_markConnection(false);
		// SAQ-A only: show inline spinner + inline connectivity message and hard-disable submit
		if (pciMode === 'saq_a_only') { try { __ps_showConnecting('Secure payment fields are connecting…'); } catch(_e) {} }

		// Check if Paysafe.js is loaded
		if (
		   typeof paysafe === 'undefined' ||
		   typeof paysafe.fields === 'undefined' ||
		   !paysafe_params.single_use_token_username ||
		   !paysafe_params.single_use_token_password
		 ) {
 
				console.log('❌ Missing requirements:', {
				paysafe: typeof paysafe,
				fields: (typeof paysafe !== 'undefined' ? typeof paysafe.fields : 'undefined'),
				username: !!paysafe_params.single_use_token_username,
				password: !!paysafe_params.single_use_token_password
			});
 
			if (pciMode === 'saq_a_only') {
				console.error('Paysafe: Hosted fields required but not available. Payment disabled.');
		  window.paysafeHostedFieldsFailed = true;
		  __ps_markConnection(false);
		  // Surface a clear inline message and hard-disable submit
		  try { __ps_showConnecting('Secure fields failed to load. Retry or use another method.'); } catch(_e){}
		  try { __ps_scheduleRetryUI(6000); } catch(_e){}
		  try { __ps_setSubmitEnabled(false); } catch(_e){}
		  return;
		}
			if (paysafe_params && paysafe_params.debug) { console.log('Paysafe: Using fallback mode'); }
			return;
		}

		if (pciMode === 'saq_aep_only') {
			if (paysafe_params && paysafe_params.debug) { console.log('Paysafe: Skipping hosted fields (SAQ-A-EP only mode)'); }
			return;
		}

		// Create shadow fields
		console.log('Creating shadow fields...');
		createShadowFields();

		// Initialize Paysafe hosted fields (UTF-8 safe btoa with graceful fallback)
		console.log('Encoding API credentials...');
		var _apiPlain = String(paysafe_params.single_use_token_username) + ':' + String(paysafe_params.single_use_token_password);
		var apiKey;
		try {
			apiKey = btoa(_apiPlain);
			console.log('✓ Credentials encoded successfully (length:', apiKey.length, ')');
		} catch (e1) {
			console.log('btoa failed, trying TextEncoder...');
			try {
				var bytes = new TextEncoder().encode(_apiPlain);
				var bin = Array.from(bytes).map(function(b){ return String.fromCharCode(b); }).join('');
				apiKey = btoa(bin);
				console.log('✓ Credentials encoded with TextEncoder');
			} catch (e2) {
				console.log('TextEncoder failed, trying legacy method...');
				try {
					// Legacy fallback
					apiKey = btoa(unescape(encodeURIComponent(_apiPlain)));
					console.log('✓ Credentials encoded with legacy method');
				} catch (e3) {
					console.error('Paysafe: API key encoding failed'); // never log secrets
					apiKey = null;
				}
			}
		}
		if (!apiKey) {
			console.error('❌ All encoding methods failed');
			if (pciMode === 'saq_a_only') {
				console.error('Paysafe: Hosted fields required but API key encoding failed. Payment disabled.');
				window.paysafeHostedFieldsFailed = true;
				// Surface a clear inline message and hard-disable submit
				try { __ps_showConnecting('Secure fields failed to load. Retry or use another method.'); } catch(_e){}
				try { __ps_scheduleRetryUI(10000); } catch(_e){}
				try { __ps_setSubmitEnabled(false); } catch(_e){}
				return;
			}
			if (paysafe_params && paysafe_params.debug) {
				console.log('Paysafe: Using fallback mode due to API key encoding issue');
			}
			restoreShadowFields();
			return;
		}

	// Promise-based setup per Paysafe docs; mount into existing containers so UI/tooltip stay identical
		console.log('🟢 Calling paysafe.fields.setup()...');
		console.log('Environment:', paysafe_params.environment === 'live' ? 'LIVE' : 'TEST');
		console.log('⏱️ Starting 10-second timeout timer...');

		// Create timeout wrapper to catch hanging promises
		var timeoutId;
		var setupStartTime = Date.now();

		// Compute hosted-field styles from your live input, using REM units.
		var _psHostedStyle = (function computeHostedStyle(){
			function pxToRem(px) {
				var root = parseFloat(getComputedStyle(document.documentElement).fontSize || '16');
				var n = parseFloat(px || '0');
				if (!isFinite(n) || !isFinite(root) || root <= 0) return '1rem';
				return (n / root).toFixed(4) + 'rem';
			}
			var nameEl = document.getElementById('cardholder_name');
			var probe  = nameEl || document.querySelector('#paysafe-payment-form input, .woocommerce-checkout input');
			var cs     = probe ? window.getComputedStyle(probe) : null;

			// Line-height from Name field → rem
			var lh = (cs && cs.lineHeight && cs.lineHeight !== 'normal') ? cs.lineHeight : (cs ? cs.fontSize : '1rem');
			var lhRem = lh.endsWith('px') ? pxToRem(lh) : lh; // if already unit (e.g. rem), keep it

			// Left inset (use NAME FIELD input’s padding-left for perfect parity) → rem
			var padLeft = cs ? cs.paddingLeft : '1rem';
			var indentRem = padLeft.endsWith('px') ? pxToRem(padLeft) : padLeft;

			// Expose values to CSS so the iframe/container can match in rem
			try {
				var wrap = document.querySelector('.paysafe-payment-wrapper');
				if (wrap) {
				  wrap.style.setProperty('--psf-line-height', lhRem);
				  /* Ensure left inset variable is always present; CSS falls back to 0.5rem */
				  wrap.style.setProperty('--psf-left-inset', (indentRem && String(indentRem)) || '0.5rem');
				}
			} catch(_e) {}

			// --- measure right-side inline icons to avoid overlap inside hosted inputs ---
			function measureIconPadPx(selectors, extraPx){
				var px = 0, els = (Array.isArray(selectors)?selectors:[selectors]);
				for (var i=0;i<els.length;i++){
					var el = document.querySelector(els[i]);
					if (!el) continue;
					var r = el.getBoundingClientRect ? el.getBoundingClientRect() : null;
					var w = r ? (r.width || 0) : 0;
					var cs = window.getComputedStyle ? getComputedStyle(el) : null;
					var ml = cs ? parseFloat(cs.marginLeft)||0 : 0;
					var mr = cs ? parseFloat(cs.marginRight)||0 : 0;
					px = Math.max(px, w + ml + mr);
				}
				return (px > 0 ? px + (extraPx||8) : 0); // add a small breathing gap
			}
			var padRightCardRem = (function(){ var px = measureIconPadPx(['#card-type-icon','.paysafe-lock-icon'], 8); return px ? pxToRem(px) : '2rem'; })();
			var padRightExpRem  = '0.75rem'; // typically no inline icon next to expiry; small safety inset
			var padRightCvvRem  = (function(){ var px = measureIconPadPx(['#cvv-help-icon','.paysafe-help-icon','.woocommerce-help-tip'], 6); return px ? pxToRem(px) : '1.5rem'; })();

			return {
	input: { "font-family": (cs ? cs.fontFamily : "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif"),
			 "font-size": (cs && /px$/.test(cs.fontSize))
				 ? (function(px){ var root=parseFloat(getComputedStyle(document.documentElement).fontSize||'16'); var n=parseFloat(px); return (n/root).toFixed(4)+'rem'; })(cs.fontSize)
				 : (cs ? cs.fontSize : "1rem"),
			 "line-height": lhRem,
			 "font-weight": "normal",
			 "color": (cs ? cs.color : "#333333") },
	"::placeholder": {
		"color": "#999999",
		"opacity": "1",
		"font-weight": "normal",
		"font-family": (cs ? cs.fontFamily : "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif"),
		"font-size": (cs && /px$/.test(cs.fontSize))
			? (function(px){ var root=parseFloat(getComputedStyle(document.documentElement).fontSize||'16'); var n=parseFloat(px); return (n/root).toFixed(4)+'rem'; })(cs.fontSize)
			: (cs ? cs.fontSize : "1rem")
	},
	"#card-number": { "line-height": lhRem, "padding-left": (indentRem || "0.5rem"), "padding-right": padRightCardRem,
					  "background-color": (cs && cs.backgroundColor ? cs.backgroundColor : "transparent") },
	"#expiry-date": { "line-height": lhRem, "padding-left": (indentRem || "0.5rem"), "padding-right": padRightExpRem,
					  "background-color": (cs && cs.backgroundColor ? cs.backgroundColor : "transparent") },
	"#cvv":         { "line-height": lhRem, "padding-left": (indentRem || "0.5rem"), "padding-right": padRightCvvRem,
					  "background-color": (cs && cs.backgroundColor ? cs.backgroundColor : "transparent") },
	".valid": {},
	".invalid": {},
	":focus": { "transition": "color 0.2s ease" }
};
})();

		// ---- ensure hosted-field placeholders match EXACTLY the configured plugin placeholders ----
		// Prefer server-provided strings so all PCI modes render identically; fall back to any existing DOM placeholders.
		var _phNumber = (paysafe_params.card_number_placeholder || jQuery('#card_number').attr('placeholder') || '').trim() || 'Card number';
		var _phExpiry = (paysafe_params.card_expiry_placeholder || jQuery('#card_expiry').attr('placeholder') || '').trim() || 'Expiry date (MM / YY)';
		var _phCvv    = (paysafe_params.card_cvv_placeholder    || jQuery('#card_cvv').attr('placeholder')    || '').trim() || 'CVV';

		// Write the same placeholders back to non-hosted inputs so all modes present the same UX text.
		try {
			if (jQuery('#card_number').length) jQuery('#card_number').attr('placeholder', _phNumber);
			if (jQuery('#card_expiry').length) jQuery('#card_expiry').attr('placeholder', _phExpiry);
			if (jQuery('#card_cvv').length)    jQuery('#card_cvv').attr('placeholder', _phCvv);
		} catch(_e) {}

		// ---- compute field height from Name on card and expose to CSS as --psf-field-height (rem) ----
		(function(){
		  function pxToRem(px){
			var root = parseFloat(getComputedStyle(document.documentElement).fontSize || '16');
			var n = parseFloat((px||'').toString().replace('px',''));
			if (!isFinite(n) || !isFinite(root) || root <= 0) return '2.875rem';
			return (n/root).toFixed(4) + 'rem';
		  }
		  try {
			var nameEl = document.getElementById('cardholder_name');
			var wrap = document.querySelector('.paysafe-payment-wrapper');
			if (nameEl && wrap) {
			  var h = getComputedStyle(nameEl).height;
			  if (h && /px$/.test(h)) { wrap.style.setProperty('--psf-field-height', pxToRem(h)); }
			}
		  } catch(_e) { /* no-op */ }
		})();

		/* Helper: compute exact right padding from the live brand/lock icon width
		   and expose as CSS vars so text never collides with the icon. */
		function __ps_applyRightInsets() {
			try {
				var root = parseFloat(getComputedStyle(document.documentElement).fontSize || '16') || 16;
				var cnIcon = document.querySelector('#card-type-icon img, .paysafe-card-type-icon img, #card-type-icon, .paysafe-card-type-icon, .paysafe-lock-icon');
				var cnPadRem = (cnIcon ? (cnIcon.getBoundingClientRect().width / root) + 0.5 : 3.125) + 'rem';
				var cvvIcon = document.querySelector('.paysafe-cvv-wrapper .paysafe-help-icon');
				var cvvPadRem = (cvvIcon ? (cvvIcon.getBoundingClientRect().width / root) + 0.5 : 2.5) + 'rem';
				var wrap = document.querySelector('.paysafe-payment-wrapper');
				if (wrap) {
					wrap.style.setProperty('--psf-cardnumber-right', cnPadRem);
					wrap.style.setProperty('--psf-cvv-right', cvvPadRem);
				}
			} catch(_e) {}
		}

		__ps_applyRightInsets(); // initial measure, updates again on brand changes

	   /* Swap inline icon as soon as a brand is recognized (no validity gate). */
	   function __ps_updateHostedInlineIcon() {
			try {
				var cardTypeIcon = jQuery('#card-type-icon, .paysafe-card-type-icon');
				var lockIcon     = jQuery('.paysafe-lock-icon');
				var detected     = (window.paysafeDetectedBrand || '').toLowerCase();
				var accepted     = (paysafe_params.accepted_cards || []).map(function(s){ return (s||'').toLowerCase(); });
				var isAccepted   = (!accepted.length || accepted.indexOf(detected) !== -1);

				// If no brand yet, keep the lock.
				if (!detected) {
					cardTypeIcon.hide().empty();
					lockIcon.show();
					__ps_applyRightInsets();
					return;
				}

				// If brand not accepted, show inline “Not accepted”
				if (!isAccepted) {
					lockIcon.hide();
					cardTypeIcon
					  .html('<span style="color:#dc2626;font-size:0.75rem;">Not accepted</span>')
					  .show();
					__ps_applyRightInsets();
					return;
				}

				// Valid + accepted → show brand icon (slightly bigger)
				lockIcon.hide();
				var iconUrl = paysafeAssetUrl('card-' + detected + '.svg');
				jQuery('#paysafe-card-error').remove(); // clear any stale "Not accepted" message
				cardTypeIcon
				  .html('<img src="'+iconUrl+'" alt="'+detected+'" style="height:var(--psf-card-size);width:auto;" />')
				  .show();
					__ps_applyRightInsets();
			} catch(_e) {}
		}

		// Helper to paint wordless connection status rings
		function __ps_markConnection(connected) {
			try {
				var $c = jQuery('#cardNumber_container,#cardExpiry_container,#cardCvv_container');
				if (!$c.length) return;
				if (connected) { $c.addClass('psf-connected').removeClass('psf-disconnected'); }
				else { $c.addClass('psf-disconnected').removeClass('psf-connected'); }
			} catch(_e) {}
		}

		// Lightweight "connecting" UX for SAQ-A only (spinner on section only; no overlay)
		function __ps_showConnecting(msg){
			try {
				// only when Paysafe is actually selected
				if ($('input[name="payment_method"]:checked').val() !== 'paysafe') { return; }
				var $wrap = jQuery('.paysafe-payment-wrapper');
				$wrap.addClass('processing'); /* uses same hook as normal loading */
				jQuery('#paysafe-payment-form').addClass('processing'); /* uses CSS spinner on wrapper only */
				__ps_setSubmitEnabled(false);
			} catch(_e){}
		}
		function __ps_hideConnecting(){
			try {
				var $wrap = jQuery('.paysafe-payment-wrapper');
				$wrap.removeClass('processing');
				hideLoading();
				__ps_clearInlineError();
			} catch(_e){}
		}
		function __ps_inlineError(msg){
			try {
				var $err = jQuery('#paysafe-connectivity-error');
				if (!$err.length) {
					// HTML (class=..."style..."); use style attribute so the div reliably renders
				$err = jQuery('<div id="paysafe-connectivity-error" style="' +
				'background: linear-gradient(135deg, #161a1f, #20262d 45%, #1a1f26);' +
				'color: #ff5555;' +
				'font-size: 1rem;' +
				'font-weight: 600;' +
				'padding: 0.5rem 0.75rem;' +
				'margin: 1rem 0;' +
				'border-radius: 0.5rem;' +
				'box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.3);' +
				'display: flex;' +
				'align-items: center;' +
				'justify-content: space-between;' +
				'white-space: pre-line;' +
				'border: 1px solid rgba(255, 85, 85, 0.2);' +
				'"></div>');
				/* mark as sticky so guard won’t auto-hide timeout errors */
				$err.attr('data-sticky','1');
					// Prefer to insert above first hosted container
					var $anchor = jQuery('#cardNumber_container');
					if ($anchor.length) { $anchor.before($err); }
					else { jQuery('.paysafe-payment-section').prepend($err); }
					// Fallback: if still not attached (no section yet), append inside wrapper so it's visible
					if (!$err.closest('body').length) { jQuery('.paysafe-payment-wrapper').append($err); }
				}
			/* Ensure error is visible (override any hidden state from payment-guard.js) */
			$err.css('display', 'block');
			$err.removeAttr('aria-hidden');
			
			/* Replace raw text with message + close button (dismiss only; no reflow elsewhere) */
			$err.empty();
			var $txt = jQuery('<span class="psf-err-txt"></span>').text((msg||'').toString());
			var $btn = jQuery(
			'<button type="button" class="psf-err-close" aria-label="Dismiss" title="Dismiss"' +
			' style="margin-left:0.75rem;padding:0.375rem 0.625rem;font-size:1rem;line-height:1;' +
			' border:1px solid rgba(230, 237, 243, 0.3);border-radius:0.25rem;' +
			' background:rgba(230, 237, 243, 0.1);color:#e6edf3;cursor:pointer;' +
			' transition: all 0.2s ease;">×</button>'
			);
			/* Tiny keyboard-focus hint without touching external CSS */
			$btn.on('focus', function(){ 
				this.style.boxShadow='0 0 0 0.125rem rgba(230, 237, 243, 0.4)'; 
				this.style.outline='none';
				this.style.background='rgba(230, 237, 243, 0.2)';
			});
			$btn.on('blur',  function(){ 
				this.style.boxShadow=''; 
				this.style.background='rgba(230, 237, 243, 0.1)';
			});
			$btn.on('mouseenter', function(){
				this.style.background='rgba(230, 237, 243, 0.2)';
			});
			$btn.on('mouseleave', function(){
				this.style.background='rgba(230, 237, 243, 0.1)';
			});
			/* Click-to-dismiss */
			$btn.on('click', function(){
			  try { __ps_clearInlineError(); } catch(_e){}
			  try { __ps_unbindErrorDismissHandlers(); } catch(_e){}
			});
			$err.append($txt).append($btn);
			/* Bind global handlers for Escape + outside-click */
				try { __ps_bindErrorDismissHandlers(); } catch(_e){}
			} catch(_e){}
		}
		function __ps_clearInlineError(){
			try { jQuery('#paysafe-connectivity-error').remove(); } catch(_e){}
			try { __ps_unbindErrorDismissHandlers(); } catch(_e){}
		}

		/* Expose error handlers globally for payment-guard.js */
		window.__ps_inlineError = __ps_inlineError;
		window.__ps_clearInlineError = __ps_clearInlineError;

		/* Close-on-Escape and click-outside (scoped; removed when error is cleared) */
		function __ps_bindErrorDismissHandlers(){
			try{
				jQuery(document).off('.psfErr');
				// Outside click — ignore clicks within the error OR within retry/cancel actions
				jQuery(document).on('mousedown.psfErr', function(e){
					var $t = jQuery(e.target);
					if (!$t.closest('#paysafe-connectivity-error, #paysafe-connectivity-actions').length) {
						__ps_clearInlineError();
					}
				});
				// Escape key
				jQuery(document).on('keydown.psfErr', function(e){
					if (e && (e.key === 'Escape' || e.key === 'Esc' || e.which === 27)) { __ps_clearInlineError(); }
				});
			}catch(_e){}
		}
		function __ps_unbindErrorDismissHandlers(){
			try { jQuery(document).off('.psfErr'); } catch(_e){}
		}
		function __ps_stopSpinnerOnly(){
			try {
				jQuery('.paysafe-payment-wrapper').removeClass('processing');
				jQuery('#paysafe-payment-form').removeClass('processing');
				// Remove overlay spinner and keep checkout disabled during failure state
				hideLoading();
				jQuery('#place_order').prop('disabled', true);
				jQuery('.paysafe-submit-button').prop('disabled', true);
			} catch(_e){}
		}

		function __ps_showRetryActions(){
			try {
				var $wrap = jQuery('.paysafe-payment-wrapper');
				var $actions = jQuery('#paysafe-connectivity-actions');
				if (!$actions.length){
					$actions = jQuery('<div id="paysafe-connectivity-actions"></div>');
					// Always place actions ABOVE the form for consistency
					var $form = jQuery('#paysafe-payment-form');
					if ($form.length) { $form.before($actions); }
					else { $wrap.prepend($actions); }
				}
				$actions.empty();

				var $retry = jQuery('<button type="button" id="ps-retry-connect" class="button">Try Connecting Secure Form Again</button>');
				var $cancel = jQuery('<button type="button" id="ps-cancel-connect" class="button">Cancel</button>');
				$actions.append($retry).append(' ').append($cancel);

				$retry.on('click', function(){
					try { jQuery('#paysafe-connectivity-actions').remove(); } catch(_e){}
					__ps_showConnecting('Reconnecting secure payment fields…');
					__ps_scheduleRetryUI(10000);
					try { window.paysafeHostedFieldsFailed = false; } catch(_e){}
					try { initializeSecureFields(); } catch(_e){}
				});

				$cancel.on('click', function(){
					__ps_stopSpinnerOnly();
					try { __ps_setSubmitEnabled(false); } catch(_e){}
					try { jQuery('#paysafe-connectivity-actions').remove(); } catch(_e){}
				});
			} catch(_e){}
		}

		function __ps_scheduleRetryUI(delayMs){
			try {
				if (window.__ps_retryTimer) { clearTimeout(window.__ps_retryTimer); }
				window.__ps_retryTimer = setTimeout(function(){
					__ps_stopSpinnerOnly(); // Stop spinner after 10s
					// show error only after timeout
					__ps_inlineError('Secure fields failed to load. Retry or use another method.');
					__ps_showRetryActions();
				}, Math.max(0, parseInt(delayMs, 10) || 0));
			} catch(_e){}
		}

		var setupPromise = paysafe.fields.setup(apiKey, {
		  environment: paysafe_params.environment === 'live' ? 'LIVE' : 'TEST',
		  currencyCode: readCurrency(),
		  fields: {
			cardNumber: {
			  selector: "#cardNumber_container",
			  separator: " ",
			  placeholder: _phNumber,
			  accessibilityLabel: _phNumber,
 			  /* Autofill hint: harmless if SDK ignores unknown keys */
 			  autocomplete: "cc-number"
			},
			expiryDate: {
			  selector: "#cardExpiry_container",
			  placeholder: _phExpiry,
			  accessibilityLabel: _phExpiry,
 			  autocomplete: "cc-exp"
			},
			cvv: {
			  selector: "#cardCvv_container",
			  placeholder: _phCvv,
			  accessibilityLabel: _phCvv,
 			  autocomplete: "cc-csc"
			}
		  },
	  /* Hosted inputs mirror your live input metrics (no hard-coded guesses) */
	  style: _psHostedStyle
		});

		console.log('⏱️ Setup promise created, typeof:', typeof setupPromise);
		console.log('⏱️ Has .then?', !!(setupPromise && typeof setupPromise.then === 'function'));
		console.log('⏱️ Has .catch?', !!(setupPromise && typeof setupPromise.catch === 'function'));

		// Add 6-second timeout for hanging promises
		var timeoutPromise = new Promise(function(resolve, reject) {
			timeoutId = setTimeout(function() {
				console.error('⏱️ TIMEOUT TRIGGERED at', Date.now() - setupStartTime, 'ms');
				var err = new Error('Paysafe fields setup timed out after 6 seconds. This usually indicates invalid API credentials or network issues.');
				err.code = 'TIMEOUT';
				reject(err);
			}, 6000);
		});

		// UI fail-safe: if setup hangs, surface a visible retry/error after ~6s no matter what
		try {
			if (window.__ps_connectUiTimer) { clearTimeout(window.__ps_connectUiTimer); }
			window.__ps_connectUiTimer = setTimeout(function(){
				try { __ps_stopSpinnerOnly(); } catch(_e){}
				try { __ps_inlineError('Secure card fields failed to load. Please try again or use a different payment method.'); } catch(_e){}
				try { __ps_showRetryActions(); } catch(_e){}
			}, 6100);
		} catch(_e){}

console.log('⏱️ Racing setup vs timeout...');

		// Race between setup and timeout
		Promise.race([setupPromise, timeoutPromise])
		.then(function(instance){
		  clearTimeout(timeoutId);
		  try { if (window.__ps_connectUiTimer) { clearTimeout(window.__ps_connectUiTimer); } } catch(_e){}
		  var elapsed = Date.now() - setupStartTime;
		  console.log('✅ paysafe.fields.setup() succeeded after', elapsed, 'ms');
  
		  window.paysafeFieldsInstance = instance;
		  window.paysafeHostedFieldsFailed = false;

		  // Success: show blue connectivity ring
		  __ps_markConnection(true);
		  if (pciMode === 'saq_a_only') { __ps_hideConnecting(); }

		  setupShadowFieldSync(instance); // keeps validation hooks
		  // Immediately wire submit gating tied to official field validity
		  try { wirePaysafeSubmitGating(instance); } catch(_e) {}

 		  // Bridge browser autofill (proxy inputs) → hosted iframes (best-effort, safe no-ops)
 		  try { wireAutofillBridge(instance); } catch(_e) {}
 
		// Keep header logos + inline brand icon in sync with hosted input
	   try {
		  var brandMap = {
			"american express": "amex",
			"mastercard": "mastercard",
			"visa": "visa",
			"discover": "discover",
			"diners club": "diners",
			"jcb": "jcb"
		  };

		  var cardTypeIcon = jQuery('#card-type-icon');
		  var lockIcon     = jQuery('.paysafe-lock-icon');
		  // Accept both id or class for the brand icon container
		  if (!cardTypeIcon.length) { cardTypeIcon = jQuery('.paysafe-card-type-icon'); }
		  var acceptedCards = (paysafe_params.accepted_cards || []).map(function(s){ return (s||'').toLowerCase(); });

		  // Hosted fields: flip icon as soon as brand is recognized.
		  instance.on("CardBrandRecognition", function(inst, evt){
			var brandName = (evt && evt.data && evt.data.cardBrand || '').toLowerCase();
			var detected  = brandMap[brandName] || '';
			window.paysafeDetectedBrand = detected;

			// Update header logos
			jQuery('.paysafe-card-logos img').removeClass('active').addClass('inactive');
			if (detected) {
			  jQuery('.paysafe-card-logos img').each(function(){
				var t = (jQuery(this).data('card-type') || '').toLowerCase();
				if (t === detected) jQuery(this).addClass('active').removeClass('inactive');
			  });
			}

			// Swap immediately (no validity gate)
			__ps_updateHostedInlineIcon();
		  });

		  // Also update icon on field-level value changes (defensive: some implementations fire per-field events more often).
		  if (instance.fields && instance.fields.cardNumber && typeof instance.fields.cardNumber.on === 'function') {
			instance.fields.cardNumber.on('FieldValueChange', function(inst2, evt2){
			  try {
				var bn  = (evt2 && evt2.data && evt2.data.cardBrand || '').toLowerCase();
				var det = brandMap[bn] || '';
				window.paysafeDetectedBrand = det;
				__ps_updateHostedInlineIcon();
			  } catch(_e) {}
			});
		  }

		  // Also catch "unsupported" signal from Paysafe
		  instance.on("UnsupportedCardBrand", function(inst, evt){
			  window.paysafeDetectedBrand = '';
			// Do not render inline "Not accepted" text; keep only the field-level error message
			(cardTypeIcon.length ? cardTypeIcon : jQuery('#card-type-icon, .paysafe-card-type-icon')).hide().empty();
			lockIcon.show();
			if (!jQuery('#paysafe-card-error').length) {
			  jQuery('#cardNumber_container').after('<div id="paysafe-card-error" class="paysafe-field-error-message" style="color:#dc2626;font-size:0.75rem;margin-top:0.25rem;">This card type is not accepted</div>');
			}
			jQuery('.paysafe-card-logos img').removeClass('active').addClass('inactive');

			// Recompute right padding after hiding the inline icon text
			__ps_applyRightInsets();
		  });
		} catch(_e) { /* no-op */ }

		  // Render the hosted iframes immediately after setup (required)
		  return instance.show(); // returns available methods
		}).then(function(paymentMethods){
		  if (paysafe_params && paysafe_params.debug) {
			console.log('Paysafe fields shown');
		  }

		  console.log('✅ Hosted fields fully initialized');

		  // Mirror hosted-field state (focus/valid/invalid/empty) onto your outer containers
		  // so visual behavior matches non-hosted fields.
		  try {
			if (paymentMethods && paymentMethods.card && !paymentMethods.card.error) {
			  wirePaysafeHostedFieldMirrors(window.paysafeFieldsInstance);
			}
		  } catch(_e) { /* no-op */ }

		  // Recompute padding-right after the brand icon renders/changes
		  __ps_applyRightInsets();
		  // Ensure submit buttons start in the correct state on first paint
		  try { __ps_updateSubmitState(); } catch(_e) {}
		  // Sync icon state after first paint
		  try { __ps_updateHostedInlineIcon(); } catch(_e) {}
		}).catch(function(err){
			clearTimeout(timeoutId);
			try { if (window.__ps_connectUiTimer) { clearTimeout(window.__ps_connectUiTimer); } } catch(_e){}
		  var elapsed = Date.now() - setupStartTime;
		  console.error('❌ Promise.race caught error after', elapsed, 'ms');
		  console.error('❌ Paysafe setup/show error:', err);
		  __ps_markConnection(false);

		  // Log ALL available error properties
		  console.error('Error details:', {
			  message: err.message || 'No message',
			  code: err.code || 'No code',
			  displayMessage: err.displayMessage || 'No displayMessage',
			  detailedMessage: err.detailedMessage || 'No detailedMessage',
			  stack: err.stack || 'No stack',
			  fullError: err
		  });

		  window.paysafeHostedFieldsFailed = true;

		  if (pciMode === 'saq_a_only') {
			console.error('SAQ-A only mode - disabling checkout');
			jQuery('#place_order').prop('disabled', true).addClass('disabled');
			// Stop spinner and show error message + retry actions immediately
			try { __ps_stopSpinnerOnly(); } catch(_e){}
			try { __ps_inlineError('Secure card fields failed to load. Please try again or use a different payment method.'); } catch(_e){}
			try { __ps_showRetryActions(); } catch(_e){}
			showError('Payment unavailable. Secure fields failed to load.');
			} else {
			console.log('Fallback mode - restoring legacy fields');
		// Fallback mode: re-show original inputs
			restoreShadowFields();
				// Surface a non-blocking inline message + retry in fallback mode (so users still see the failure)
				try { __ps_inlineError('Secure fields failed to load. Retry or use standard fields below.'); } catch(_e){}
				try { __ps_showRetryActions(); } catch(_e){}
		// Ensure the checkout button is usable in fallback mode
		jQuery('#place_order').prop('disabled', false).removeClass('disabled');
		  }
		});
	}

	function createShadowFields() {
	  // Hide legacy inputs and disable HTML5 required so native validation won't block submit
	  var $f = jQuery('#card_number,#card_expiry,#card_cvv');
	  if ($f.length) {
		$f.each(function(){
		  var $el = jQuery(this);
		  $el.data('ps-required', $el.prop('required'));
		  $el.prop('required', false);

 		  // Make proxies friendly to browser autofill but NEVER submit them
 		  if (!$el.data('psf-origname')) { $el.data('psf-origname', $el.attr('name') || ''); }
 		  $el.attr('name', ''); // strip name so raw PAN/CVV/EXP are never posted

		  // Apply strong autofill hints per field
		  if ($el.is('#card_number')) {
			$el.attr({ autocomplete:'cc-number', inputmode:'numeric', 'aria-hidden':'true' });
		  } else if ($el.is('#card_expiry')) {
			$el.attr({ autocomplete:'cc-exp', inputmode:'numeric', 'aria-hidden':'true' });
		  } else if ($el.is('#card_cvv')) {
			$el.attr({ autocomplete:'cc-csc', inputmode:'numeric', 'aria-hidden':'true' });
		  }

		  // Keep in layout for autofillers; styling handled via CSS class
		  $el.addClass('psf-autofill-proxy');
		});
	  }
	}

	function restoreShadowFields() {
	  var $f = jQuery('#card_number,#card_expiry,#card_cvv');
	  $f.each(function(){
		var $el = jQuery(this);
		// Restore name if we had one
		var on = $el.data('psf-origname'); if (typeof on === 'string') { $el.attr('name', on); }
		$el.removeClass('psf-autofill-proxy').attr('aria-hidden', null);
		var wasReq = $el.data('ps-required');
		if (typeof wasReq !== 'undefined') {
		  $el.prop('required', !!wasReq);
		}
	  });
	  $f.show();
	}

	function setupShadowFieldSync(instance) {
		// Store reference to instance for validation
		window.paysafeFieldsInstance = instance;
	}

	/* Bridge browser autofill (on proxy inputs) → hosted iframes.
	   - Some SDKs disallow programmatic PAN set; we try safe methods and swallow if unsupported.
	   - Even when programmatic set is blocked, keeping proxies in-DOM improves password manager UX.
	*/
	function wireAutofillBridge(instance){
		try {
			if (!instance) return;

			// Debounce helper
			function debounce(fn, ms){
				var t; return function(){ var ctx=this,args=arguments; clearTimeout(t); t=setTimeout(function(){ fn.apply(ctx,args); }, ms||0); };
			}

			var $num = jQuery('#card_number');
			var $exp = jQuery('#card_expiry');
			var $cvv = jQuery('#card_cvv');

			function digitsOnly(s){ return String(s||'').replace(/\D+/g,''); }
			function normalizeExpiryMMYY(s){
				var d = digitsOnly(s).slice(0,4);
				if (!d) return {mm:'',yy:''};
				if (d.length === 1 && parseInt(d,10) >= 2) d = '0'+d; // 3 -> 03
				var mm = d.substr(0,2);
				var yy = d.substr(2,2);
				// clamp month
				var m = parseInt(mm,10); if (!isFinite(m) || m<=0) m=1; if (m>12) m=12;
				mm = (m<10?('0'+m):String(m));
				return {mm:mm, yy:yy};
			}

			// Safe best-effort setters (no-ops if unsupported)
			function trySetCardNumber(v){
				try {
					if (instance && instance.setCardDetails) { instance.setCardDetails({ cardNumber: v }); return; }
				} catch(_e){}
				try {
					var f = instance && instance.fields && instance.fields('cardNumber');
					if (f && typeof f.setValue === 'function') { f.setValue(v); return; }
				} catch(_e){}
			}
			function trySetExpiry(mm, yy){
				try {
					if (instance && instance.setCardDetails) { instance.setCardDetails({ expiryMonth:mm, expiryYear:yy }); return; }
				} catch(_e){}
				try {
					var f = instance && instance.fields && instance.fields('expiryDate');
					if (f && typeof f.setValue === 'function') { f.setValue(mm + '/' + yy); return; }
				} catch(_e){}
			}
			function trySetCvv(v){
				try {
					if (instance && instance.setCardDetails) { instance.setCardDetails({ cvv:v }); return; }
				} catch(_e){}
				try {
					var f = instance && instance.fields && instance.fields('cvv');
					if (f && typeof f.setValue === 'function') { f.setValue(v); return; }
				} catch(_e){}
			}

			var pushNum = debounce(function(){ var v = digitsOnly($num.val()); if (v) trySetCardNumber(v); }, 60);
			var pushExp = debounce(function(){ var o = normalizeExpiryMMYY($exp.val()); if (o.mm && o.yy) trySetExpiry(o.mm, o.yy); }, 60);
			var pushCvv = debounce(function(){ var v = digitsOnly($cvv.val()); if (v) trySetCvv(v); }, 60);

			// Listen for autofill and manual edits on proxies
			jQuery(document)
				.off('input.psfAF change.psfAF', '#card_number,#card_expiry,#card_cvv')
				.on('input.psfAF change.psfAF', '#card_number',  pushNum)
				.on('input.psfAF change.psfAF', '#card_expiry', pushExp)
				.on('input.psfAF change.psfAF', '#card_cvv',    pushCvv);

			// On BFCache restore, push once more (Safari/Firefox)
			jQuery(window).off('pageshow.psfAF').on('pageshow.psfAF', function(){
				pushNum(); pushExp(); pushCvv();
			});

			// Initial push shortly after show() to catch immediate autofill
			setTimeout(function(){ pushNum(); pushExp(); pushCvv(); }, 200);
		} catch(_e) { /* silent no-op */ }
	}

	/**
	 * Mirror Paysafe hosted-field state to wrapper elements (no layout/DOM changes).
	 * Adds/removes lightweight classes on the field container element only:
	 *   psf-focus, psf-valid, psf-invalid, psf-empty, psf-has-value
	 */
	function wirePaysafeHostedFieldMirrors(instance) {
		try {
			if (!instance || !instance.fields || typeof instance.fields !== 'function') return;

			// Track state per field for precise error messages on submit
			window.__ps_fieldState = {
				cardNumber: { isEmpty: true, isValid: false },
				expiryDate: { isEmpty: true, isValid: false },
				cvv:        { isEmpty: true, isValid: false }
			};

			function __ps_mapContainerIdToKey(id){
				if (!id) return null;
				if (id.indexOf('cardNumber') !== -1) return 'cardNumber';
				if (id.indexOf('cardExpiry') !== -1)  return 'expiryDate';
				if (id.indexOf('cardCvv') !== -1)     return 'cvv';
				return null;
			}
			function __ps_setFieldError(sel, msg){
				try{
					var $c = jQuery(sel);
					if (!$c.length) return;
					$c.addClass('psf-invalid error');
					var $m = $c.siblings('.paysafe-field-error-message[data-for="'+sel+'"]');
					if (!$m.length){
						$m = jQuery('<div class="paysafe-field-error-message" data-for="'+sel+'" style="color:#dc2626;font-size:0.75rem;margin-top:0.25rem;"></div>');
						$c.after($m);
					}
					$m.text(msg||'');
				}catch(_e){}
			}
			function __ps_clearFieldError(sel){
				try{
					var $c = jQuery(sel);
					if ($c.length){ $c.removeClass('psf-invalid error'); }
					jQuery('.paysafe-field-error-message[data-for="'+sel+'"]').remove();
				}catch(_e){}
			}
			// Expose for validator
			window.__ps_setFieldError  = __ps_setFieldError;
			window.__ps_clearFieldError = __ps_clearFieldError;

			instance
				  .fields("cardNumber expiryDate cvv")
				  .on("Focus Blur Valid Invalid FieldValueChange", function (inst, event) {
				  // Per Paysafe docs, the iframe container is provided as event.context,
				  // and also as event.target.containerElement.
				  var el = (event && (event.context || (event.target && event.target.containerElement))) || null;
				  if (!el) return;

				  var t = String(event.type || '').toLowerCase();
				  var key = __ps_mapContainerIdToKey(el.id||'');

				  // focus / blur
				  if (t === 'focus') { el.classList.add('psf-focus'); }
				  if (t === 'blur')  { el.classList.remove('psf-focus'); }

				  // validity
				  if (t === 'valid') {
					  el.classList.add('psf-valid');
					  el.classList.remove('psf-invalid');
					  el.classList.remove('error'); // keep red outline only when invalid
					  // field-level message disappears as soon as it becomes valid
					  try {
						  if (el && el.id) { jQuery('.paysafe-field-error-message[data-for="#'+el.id+'"]').remove(); }
					  } catch(_e){}
					  if (key) { window.__ps_fieldState[key].isValid = true; }
					  // Clear any field-level message as soon as it becomes valid
					  if (key) { __ps_clearFieldError('#'+el.id); }
					  // If card number just became valid, swap icon
					  try { if (key === 'cardNumber') __ps_updateHostedInlineIcon(); } catch(_e){}
				  }
				  if (t === 'invalid') {
					  el.classList.add('psf-invalid');
					  el.classList.remove('psf-valid');
					  el.classList.add('error');    // your theme styles red outline on .error
					  // show immediate field-specific message on invalidation
					  try {
						  var id = el && el.id ? ('#' + el.id) : '';
						  var msg = 'Please correct this field';
						  if (/cardNumber/i.test(id)) msg = 'Enter a valid card number';
						  else if (/cardExpiry/i.test(id)) msg = 'Enter a valid expiration date (MM / YY)';
						  else if (/cardCvv/i.test(id)) msg = 'Enter a valid security code';
						  if (id) {
							  var $c = jQuery(id);
							  $c.addClass('psf-invalid error');
							  var $m = $c.siblings('.paysafe-field-error-message[data-for="'+id+'"]');
							  if (!$m.length) {
								  $m = jQuery('<div class="paysafe-field-error-message" data-for="'+id+'" style="color:#dc2626;font-size:0.75rem;margin-top:0.25rem;"></div>');
								  $c.after($m);
							  }
							  $m.text(msg);
						  }
					  } catch(_e){}
					  if (key) { window.__ps_fieldState[key].isValid = false; }
					  // If card number turned invalid, revert to lock
					  try { if (key === 'cardNumber') __ps_updateHostedInlineIcon(); } catch(_e){}
				  }

				  // empty vs has value
				  if (event && event.data && typeof event.data.isEmpty !== 'undefined') {
					  if (event.data.isEmpty) { el.classList.add('psf-empty'); el.classList.remove('psf-has-value'); }
					  else { el.classList.remove('psf-empty'); el.classList.add('psf-has-value'); }
					  // if user cleared the field, also clear any stale error message
					  try {
						  if (event.data.isEmpty && el && el.id) {
							  jQuery('.paysafe-field-error-message[data-for="#'+el.id+'"]').remove();
						  }
					  } catch(_e){}
					  if (key) { window.__ps_fieldState[key].isEmpty = !!event.data.isEmpty; }
					  // If number cleared, clear brand and ensure we show the lock again
					  try {
						  if (key === 'cardNumber' && event.data && event.data.isEmpty) {
							  window.paysafeDetectedBrand = '';
							  __ps_updateHostedInlineIcon();
						  }
					  } catch(_e){}
				  }

				  // echo SDK validity on any event that carries it (fires while typing, not only on blur)
				  if (event && event.data && typeof event.data.isValid !== 'undefined' && key) {
					  try {
						  window.__ps_fieldState[key].isValid = !!event.data.isValid;
						  if (key === 'cardNumber') { __ps_updateHostedInlineIcon(); }
					  } catch(_e){}
				  }
				  // Any change should reevaluate button enabled/disabled state
				  try { __ps_updateSubmitState(); } catch(_e) {}
			  });
		} catch (_e) { /* never let visuals impact checkout */ }
	}

  // ---- Submit gating for hosted fields (authoritative, no guessing) ----
  // Keep WooCommerce #place_order clickable; payment-guard.js will block submit
  // when fields are invalid. Only disable our own custom button here.
  function __ps_buttonsSel() { return $('.paysafe-submit-button'); }
  function __ps_setSubmitEnabled(enabled){
	__ps_buttonsSel().prop('disabled', !enabled).toggleClass('disabled', !enabled);
  }
  function __ps_allFieldsValid(){
	try {
	  return !!(window.paysafeFieldsInstance &&
				typeof window.paysafeFieldsInstance.areAllFieldsValid === 'function' &&
				window.paysafeFieldsInstance.areAllFieldsValid());
	} catch(_e){ return false; }
  }
  function __ps_allFieldsEmpty(){
	try {
	  return !!(window.paysafeFieldsInstance &&
				typeof window.paysafeFieldsInstance.areAllFieldsEmpty === 'function' &&
				window.paysafeFieldsInstance.areAllFieldsEmpty());
	} catch(_e){ return false; }
  }
  function __ps_updateSubmitState(){
	// Name must be present and card fields must be valid before enabling submit
	var nameOk = (jQuery('#cardholder_name').val() || '').trim().length >= 2;
	var hfValid = __ps_allFieldsValid();
	__ps_setSubmitEnabled(!!(nameOk && hfValid));
  }
  function wirePaysafeSubmitGating(instance){
	// On init, disable until inputs are valid
	__ps_updateSubmitState();
	// Defensive: also watch name changes
	jQuery('#cardholder_name')
	  .off('input.psfName change.psfName blur.psfName')
	  .on('input.psfName change.psfName blur.psfName', function(){ __ps_updateSubmitState(); });
	// If everything is empty, keep disabled; SDK updates will call __ps_updateSubmitState via mirror
	if (__ps_allFieldsEmpty()) { __ps_setSubmitEnabled(false); }
  }

	function handleTokenizationSuccess(token) {
		// Add token to form (dedupe any previous)
		$('form.woocommerce-checkout, form.checkout').find('input[name="paysafe_payment_token"]').remove();
		$('form.woocommerce-checkout, form.checkout').append(
			'<input type="hidden" name="paysafe_payment_token" value="' + token + '" />'
		);

		// Re-submit form
		$('form.woocommerce-checkout, form.checkout').submit();
	}

	function tokenizeCard(cardData, callback) {
		// Always use server-side tokenization to avoid exposing credentials in the browser
		if (isTokenizing) {
			callback(null, 'Already processing');
			return;
		}
		isTokenizing = true;

		// Basic validation so we don't send junk to the server
		var number = (cardData && cardData.cardNum || '').replace(/\D/g, ''); // digits only
		var exp = (cardData && cardData.cardExpiry) || {};
		var cvv = (cardData && cardData.cvv) || '';
		if (!number || !exp.month || !exp.year || !cvv) {
			isTokenizing = false;
			callback(null, 'Missing required card info');
			return;
		}

		if (!paysafe_params || !paysafe_params.ajax_url) {
			isTokenizing = false;
			return callback(null, 'Payment configuration is incomplete (AJAX URL missing).');
		}
		$.ajax({
			url: paysafe_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			timeout: 30000,
			data: {
				action: 'paysafe_create_single_use_token',
				nonce: paysafe_params.nonce,
				card_number: number,
				exp_month: exp.month,
				exp_year: exp.year,
				cvv: cvv,
				holder_name: _safeText((cardData.holderName || '').trim())
			}
		}).done(function(resp){
			isTokenizing = false;
			if (resp && resp.success && resp.data && resp.data.token) {
				callback(resp.data.token, null);
			} else {
				callback(null, (resp && resp.data && resp.data.message) || 'Tokenization failed');
			}
		}).fail(function(){
			isTokenizing = false;
			callback(null, 'Network error during tokenization');
		});
	}

	function initializePaysafeForm() {
		if (isInitialized) {
			return;
		}

		if (paysafe_params && paysafe_params.debug) { console.log('Paysafe: Initializing payment form'); }

		// Set up form submission
		setupFormSubmission();

		// Add input formatting
		setupFieldFormatting();

		// Auto-populate name from billing fields
		autoPopulateNameField();

		// Initialize digital wallets
		initializeDigitalWallets();

		// Ensure SAQ-A-EP flow creates a server-side single-use token before Woo submits
		bindWooCheckoutInterceptors();

		isInitialized = true;
	}

	// --- WooCommerce SAQ-A-EP interceptor: create single-use token server-side before submit ---
	function bindWooCheckoutInterceptors(){
		var $body = jQuery(document.body);
		
		// Primary: Listen to WooCommerce AJAX checkout event
		$body.off('checkout_place_order_paysafe.psEP')
			 .on('checkout_place_order_paysafe.psEP', function(e){ 
				 console.log('🟢 checkout_place_order_paysafe EVENT FIRED');
				 return interceptWooEP(e); 
			 });
		
		// CRITICAL BACKUP: Direct form submit handler (for non-AJAX checkouts)
		jQuery('form.checkout, form.woocommerce-checkout')
		  .off('submit.psEPDirect')
		  .on('submit.psEPDirect', function(e){
			  console.log('🟢 DIRECT FORM SUBMIT - Checking if Paysafe...');
			  if (jQuery('input[name="payment_method"]:checked').val() !== 'paysafe') {
				  console.log('   Not Paysafe, allowing submit');
				  return true;
			  }
			  console.log('   Paysafe detected - calling interceptWooEP');
			  return interceptWooEP(e);
		  });
		
		jQuery('form#order_review, form#add_payment_method')
		  .off('submit.psEP')
		  .on('submit.psEP', function(e){
			  if (jQuery('input[name="payment_method"]:checked').val() !== 'paysafe') return true;
			  return interceptWooEP(e);
		  });
		// Rebind after fragments update
		$body.off('updated_checkout.psEP')
			 .on('updated_checkout.psEP', function(){ bindWooCheckoutInterceptors(); });
	}

function interceptWooEP(e){
		console.log('🟢 interceptWooEP() CALLED');
		
		// Use Decision Engine as single source of truth
		if (typeof window.PaysafeDecisionEngine === 'undefined') {
			// Fallback: Decision engine not loaded yet (shouldn't happen)
			console.warn('Paysafe Decision Engine not loaded in interceptWooEP, using legacy logic');
			// Keep all existing logic as fallback (lines 1292-1396 unchanged)
			try {
				if (window.paysafePCIMode !== 'saq_aep_only' && window.paysafeFieldsInstance) {
					if (window.__ps_hosted_done) return true;
					if (jQuery('input[name="paysafe_payment_token"]').length) return true;
					var _name = (jQuery('#cardholder_name').val()||'').trim();
					if (_name.length < 2){
						showError('Please enter the cardholder name', '#cardholder_name');
						return false;
					}
					if (!__ps_allFieldsValid()){
						showError('Please correct the highlighted card fields.', '#cardNumber_container');
						return false;
					}
					var _brand = (window.paysafeDetectedBrand || '').toLowerCase();
					var _accepted = (paysafe_params.accepted_cards || []).map(function(s){ return (s||'').toLowerCase(); });
					if (_brand && _accepted.length && _accepted.indexOf(_brand) === -1) {
						showError('This card type is not accepted', '#cardNumber_container');
						return false;
					}
					if (e && e.preventDefault) e.preventDefault();
					if (e && e.stopImmediatePropagation) e.stopImmediatePropagation();
					showLoading();
					window.paysafeFieldsInstance.tokenize({ cardHolderName: _name })
						.then(function(res){
							var token = res.token || res.payment_token;
							if (!token) {
								throw new Error('Token not found in response');
							}
							jQuery('form.checkout, form.woocommerce-checkout, form#order_review, form#add_payment_method')
								.append('<input type="hidden" name="paysafe_payment_token" value="'+ token +'">');
							window.__ps_hosted_done = true;
							hideLoading();
							jQuery('form.checkout, form.woocommerce-checkout, form#order_review, form#add_payment_method')
								.first().trigger('submit');
						})
						.catch(function(err){
							hideLoading();
							console.error('Paysafe tokenize() error:', err);
							showError((err && err.displayMessage) || 'Payment tokenization failed');
						});
					return false;
				}
			} catch(_e){}
			// Rest of legacy EP logic...
			try { if (window.paysafePCIMode !== 'saq_aep_only') return true; } catch(_e){}
			if (typeof window.paysafe_ep !== 'undefined' && window.paysafe_ep.mode === 'saq_aep_only') {
				console.log('✅ Payment Form: paysafe-ep-validate.js is handling SAQ-A-EP, skipping legacy handler');
				return true;
			}
			if (window.__ps_ep_done) return true;
			if (jQuery('input[name="paysafe_payment_token"]').length) return true;
			var number = (jQuery('#card_number').val()||'').replace(/\D/g,'');
			var expiry = jQuery('#card_expiry').val()||'';
			var m  = expiry.match(/^\s*(\d{2})\s*\/\s*(\d{2})\s*$/);
			var mm = m ? m[1] : '';
			var yy = m ? m[2] : '';
			var cvv = (jQuery('#card_cvv').val()||'').replace(/\D/g,'');
			var holder = (jQuery('#cardholder_name').val()||'').trim();
			var holderSafe = _safeText(holder);
			if (!number || !mm || !yy || !cvv) {
				try { showError('Please fix the card fields below:\nMissing required card information', '#card_number'); } catch(_e){}
				if (e && e.preventDefault) e.preventDefault();
				return false;
			}
			if (e && e.preventDefault) e.preventDefault();
			if (e && e.stopImmediatePropagation) e.stopImmediatePropagation();
			showLoading();
			window.__ps_ep_done = false;
			jQuery.ajax({
				url: paysafe_params.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'paysafe_create_single_use_token',
					nonce: paysafe_params.nonce,
					card_number: number,
					expiry_month: mm,
					expiry_year: yy,
					cvv: cvv,
					holder_name: holderSafe
				}
			}).done(function(resp){
				if (resp && resp.success && resp.data && (resp.data.token || resp.data.payment_token)){
					jQuery('form.checkout, form.woocommerce-checkout, form#order_review, form#add_payment_method')
					  .append('<input type="hidden" name="paysafe_payment_token" value="'+ (resp.data.token || resp.data.payment_token) +'">');
					window.__ps_ep_done = true;
					hideLoading();
					jQuery('form.checkout, form.woocommerce-checkout, form#order_review, form#add_payment_method')
					  .first().trigger('submit');
				} else {
					hideLoading();
					showError((resp && resp.data && resp.data.message) || 'Tokenization failed');
				}
			}).fail(function(){
				hideLoading();
				showError('Network error during tokenization');
			});
			return false;
		}
		
		// DECISION ENGINE LOGIC - Single source of truth
		const decision = window.PaysafeDecisionEngine.getPaymentFlow();
		
		// ALWAYS log decision (not just in debug mode)
		console.log('🔵 [interceptWooEP] Decision Engine result:', {
			flow: decision.flow,
			ready: decision.ready,
			errors: decision.errors,
			skipTokenization: decision.skipTokenization
		});
		
		// Skip tokenization if not needed
		if (decision.skipTokenization) {
			return true;
		}
		
		// Already tokenized - allow submit
		if (decision.flow === 'already_tokenized') {
			return true;
		}
		
		// Saved card - let server handle it (THIS IS THE BUG FIX!)
		if (decision.flow === 'saved_card') {
			return true;
		}
		
		// Not ready - block submit
		if (!decision.ready) {
			if (e && e.preventDefault) e.preventDefault();
			if (e && e.stopImmediatePropagation) e.stopImmediatePropagation();
			showError(decision.errors.join('\n') || 'Please complete all card fields');
			return false;
		}
		
		// HOSTED TOKENIZATION (SAQ-A or SAQ-A with Fallback using hosted fields)
		if (decision.flow === 'hosted_tokenize') {
			// Prevent duplicate tokenization
			if (window.__ps_hosted_done) return true;
			if (jQuery('input[name="paysafe_payment_token"]').length) return true;
			
			if (e && e.preventDefault) e.preventDefault();
			if (e && e.stopImmediatePropagation) e.stopImmediatePropagation();
			showLoading();
			
			const name = (jQuery('#cardholder_name').val() || '').trim();
			
			// Validate and sanitize cardholder name
			// Paysafe requires: letters, spaces, hyphens, apostrophes only
			// Max 40 characters
			var sanitizedName = name
				.replace(/[^a-zA-Z\s\-']/g, '') // Remove invalid characters
				.substring(0, 40) // Max 40 chars
				.trim();
			
			if (!sanitizedName || sanitizedName.length < 2) {
				hideLoading();
				showError('Please enter a valid cardholder name (letters only, 2-40 characters)');
				return false;
			}
			
			console.log('🔵 Tokenizing with cardHolderName:', sanitizedName);
			
			window.paysafeFieldsInstance.tokenize({ 
				cardHolderName: sanitizedName
			})
				.then(function(res){
					var token = res.token || res.payment_token;
					if (!token) {
						throw new Error('Token not found in response');
					}
					jQuery('form.checkout, form.woocommerce-checkout, form#order_review, form#add_payment_method')
						.append('<input type="hidden" name="paysafe_payment_token" value="'+ token +'">');
					window.__ps_hosted_done = true;
					hideLoading();
					// Resume Woo flow
					jQuery('form.checkout, form.woocommerce-checkout, form#order_review, form#add_payment_method')
						.first().trigger('submit');
				})
				.catch(function(err){
					hideLoading();
					console.error('Paysafe tokenize() error:', err);
					showError((err && err.displayMessage) || 'Payment tokenization failed');
				});
			return false;
		}
		
		// DIRECT TOKENIZATION (SAQ-A-EP or fallback)
		if (decision.flow === 'direct_tokenize') {
			// CRITICAL: If paysafe-ep-validate.js is active, let it handle SAQ-A-EP entirely
			if (typeof window.paysafe_ep !== 'undefined' && window.paysafe_ep.mode === 'saq_aep_only') {
				console.log('✅ Payment Form: paysafe-ep-validate.js is handling SAQ-A-EP, skipping legacy handler');
				return true;
			}
			
			// Prevent duplicate tokenization
			if (window.__ps_ep_done) return true;
			if (jQuery('input[name="paysafe_payment_token"]').length) return true;
			
			if (e && e.preventDefault) e.preventDefault();
			if (e && e.stopImmediatePropagation) e.stopImmediatePropagation();
			showLoading();
			window.__ps_ep_done = false;
			
			// Get field values
			var number = (jQuery('#card_number').val()||'').replace(/\D/g,'');
			var expiry = jQuery('#card_expiry').val()||'';
			var m  = expiry.match(/^\s*(\d{2})\s*\/\s*(\d{2})\s*$/);
			var mm = m ? m[1] : '';
			var yy = m ? m[2] : '';
			var cvv = (jQuery('#card_cvv').val()||'').replace(/\D/g,'');
			var holder = (jQuery('#cardholder_name').val()||'').trim();
			var holderSafe = _safeText(holder);
			
			// Create token via AJAX
			jQuery.ajax({
				url: paysafe_params.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'paysafe_create_single_use_token',
					nonce: paysafe_params.nonce,
					card_number: number,
					expiry_month: mm,
					expiry_year: yy,
					cvv: cvv,
					holder_name: holderSafe
				}
			}).done(function(resp){
				if (resp && resp.success && resp.data && (resp.data.token || resp.data.payment_token)){
					jQuery('form.checkout, form.woocommerce-checkout, form#order_review, form#add_payment_method')
					  .append('<input type="hidden" name="paysafe_payment_token" value="'+ (resp.data.token || resp.data.payment_token) +'">');
					window.__ps_ep_done = true;
					hideLoading();
					jQuery('form.checkout, form.woocommerce-checkout, form#order_review, form#add_payment_method')
					  .first().trigger('submit');
				} else {
					hideLoading();
					showError((resp && resp.data && resp.data.message) || 'Tokenization failed');
				}
			}).fail(function(){
				hideLoading();
				showError('Network error during tokenization');
			});
			return false;
		}
		
		// Unknown state - allow submit (shouldn't happen)
		return true;
	}

	function setupFieldFormatting() {
		if (paysafe_params && paysafe_params.debug) { console.log('Paysafe: Setting up field formatting'); }

		// If we’re using hosted fields, there may be no #card_number. That’s OK.
		if (!$('#card_number').length) {
			if (!window.paysafeFieldsInstance && !$('#cardNumber_container').length) {
				if (paysafe_params && paysafe_params.debug) { console.warn('Paysafe: No card input found.'); }
			}
			return;
		}

		// Format card number with spaces (AmEx: 4-6-5; others: 4-4-4-…)
		$(document)
			.off('input.paysafe', '#card_number').on('input.paysafe', '#card_number', function() {
			let value = $(this).val().replace(/\s/g, '');
			var isAmex = /^3[47]/.test(value);
			var formattedValue;
			if (isAmex) {
				var p1 = value.substring(0, 4);
				var p2 = value.substring(4, 10);
				var p3 = value.substring(10, 15);
				formattedValue = [p1, p2, p3].filter(Boolean).join(' ');
			} else {
				var groups = value.match(/.{1,4}/g);
				formattedValue = groups ? groups.join(' ') : value;
			}

			// Preserve cursor position
			let cursorPos = this.selectionStart;
			let oldLen = $(this).val().length;

			$(this).val(formattedValue);

			// Adjust cursor position after formatting
			let newLen = formattedValue.length;
			if (cursorPos === oldLen) {
				cursorPos = newLen;
			} else if (oldLen < newLen) {
				// Added a space
				cursorPos++;
			}
			try { this.setSelectionRange(cursorPos, cursorPos); } catch (_e) {}

			// Detect card type (delegated handler survives Woo fragment refresh)
			detectCardType(value);
		});

		// Format & sanitize expiry strictly as "MM / YY"
		// - Auto-leading-zero if first digit is 2-9
		// - Clamp month to 01..12
		// - Auto insert " / " after month
		// - Always cap to 4 digits total (MMYY)
		$(document)
			.off('input.paysafe', '#card_expiry').on('input.paysafe', '#card_expiry', function() {
			const inputEl = this;

			// 1. Get raw digits only, max 4
			let digits = $(this).val().replace(/\D/g, '').slice(0, 4);

			// 2. Smart month normalization
			// Case: user has typed 1 digit so far
			if (digits.length === 1) {
				const d0 = digits.charAt(0);

				if (parseInt(d0, 10) >= 2) {
					// First digit can't start a valid month, so treat "3" as "03"
					digits = '0' + d0;
				}
				// (If d0 is 0 or 1, we leave it for now because they might finish "09" or "12")
			}

			// After this point, if digits length is still 1, we don't format yet.
			// We'll only start injecting " / " after we have at least 2 digits.

			let mm = '';
			let yy = '';

			if (digits.length >= 2) {
				mm = digits.substring(0, 2);

				// Clamp month to 01..12
				let mmNum = parseInt(mm, 10);

				if (isNaN(mmNum) || mmNum <= 0) {
					mmNum = 1;
				} else if (mmNum > 12) {
					mmNum = 12;
				}

				// Rebuild mm with leading zero
				if (mmNum < 10) {
					mm = '0' + mmNum;
				} else {
					mm = String(mmNum);
				}

				// yy is whatever remains (positions 2 and 3)
				if (digits.length > 2) {
					yy = digits.substring(2, 4);
				}
			}

			// 3. Build the formatted string exactly how we want to show it
			// Cases:
			// - 0-1 digit typed (like "0" or "1"): just show that digit, no slash yet
			// - 2 digits typed (month complete, no year yet): "MM / "
			// - 3-4 digits typed: "MM / YY"
			let formatted;
			if (digits.length <= 1) {
				// still collecting first digit of month
				formatted = digits;
			} else if (digits.length === 2) {
				// full month, show slash scaffold
				formatted = mm + ' / ';
			} else {
				// have at least 3 digits total => we have MM and some YY
				formatted = mm + ' / ' + yy;
			}

			// 4. Set the new value
			const prevLen = $(this).val().length;
			$(this).val(formatted);

			// 5. Caret behavior:
			// If we changed formatting length (like auto-added "0" or " / "),
			// safest move is to move cursor to end so user can just keep typing.
			try {
				// Heuristic: if we auto-expanded (formatted longer than raw digits),
				// place caret at end.
				if (formatted.length > prevLen) {
					const endPos = formatted.length;
					inputEl.setSelectionRange(endPos, endPos);
				} else {
					// Try to keep caret where it was
					// (This isn't perfect across all browsers, but it's safe.)
					const endPos = formatted.length;
					inputEl.setSelectionRange(endPos, endPos);
				}
			} catch(_e) {
				// ignore caret errors on older browsers
			}
		});

		// Only allow digits for CVV and enforce max length per detected brand
		$(document)
			.off('input.paysafe', '#card_cvv').on('input.paysafe', '#card_cvv', function() {
			let v = $(this).val().replace(/\D/g, '');
			let maxLen = parseInt($(this).attr('maxlength') || '3', 10);
			if (!isFinite(maxLen) || maxLen < 3) maxLen = 3;
			if (v.length > maxLen) v = v.substring(0, maxLen);
			$(this).val(v);
		});
	}

	function detectCardType(cardNumber) {
		const patterns = getCardBrandRegexMap();

		let detectedType = null;
		for (let [type, pattern] of Object.entries(patterns)) {
			if (pattern.test(cardNumber)) { detectedType = type; break; }
		}

		const acceptedCards = (paysafe_params.accepted_cards || []).map(function(s){ return (s||'').toLowerCase(); });

		// Prefer the same selector used by hosted-fields code
		 const lockIcon = $('.paysafe-lock-icon').length ? $('.paysafe-lock-icon') : $('#card-type-indicator');
		 const cardTypeIcon = $('#card-type-icon, .paysafe-card-type-icon');
		 const $cn = $('#card_number');
		 const cardNumberField = $cn.closest('.paysafe-iframe-field').length ? $cn.closest('.paysafe-iframe-field') : $cn;
		 const isLuhnValid = validateCardNumber(String(cardNumber||'')); /* still used for error states, not for icon gate */

		// Show brand as soon as it is identifiable (no arbitrary length or Luhn gate)
		if (detectedType) {
			if (acceptedCards.length && !acceptedCards.includes(detectedType)) {
				// Do not render inline "Not accepted" text; keep only the field-level error message
				cardTypeIcon.hide().empty();
				lockIcon.show();
				cardNumberField.addClass('error');

				// No brand highlighted when not accepted
				$('.paysafe-card-logos img').removeClass('active').addClass('inactive');

				if (!$('#paysafe-card-error').length) {
					cardNumberField.after('<div id="paysafe-card-error" class="paysafe-field-error-message" style="color:#dc2626;font-size:0.75rem;margin-top:0.25rem;">This card type is not accepted</div>');
				}
				__ps_applyRightInsets();
			} else {
				lockIcon.hide();
				const iconUrl = paysafeAssetUrl('card-' + detectedType + '.svg');
				cardTypeIcon.html('<img src="' + iconUrl + '" alt="' + detectedType + '" style="height:var(--psf-card-size);width:auto;" />').show();

				cardNumberField.removeClass('error');
				$('#paysafe-card-error').remove();

				// Highlight logos using data-card-type (theme-agnostic)
				$('.paysafe-card-logos img').each(function() {
					const t = ( $(this).data('card-type') || '' ).toLowerCase();
					if (t === detectedType) {
						$(this).removeClass('inactive').addClass('active');
					} else {
						$(this).removeClass('active').addClass('inactive');
					}
				});

				// CVV length/placeholder
				if (detectedType === 'amex') {
					$('#card_cvv').attr('maxlength', '4')
								  .attr('placeholder', paysafe_params.card_cvv_placeholder || 'Security code (4 digits)');
				} else {
					$('#card_cvv').attr('maxlength', '3')
								  .attr('placeholder', paysafe_params.card_cvv_placeholder || 'Security code');
				}
			}
		} else {
			lockIcon.show();
			cardTypeIcon.hide().empty();  /* keep lock visible until brand is known or >=4 digits */
			cardNumberField.removeClass('error');
			$('#paysafe-card-error').remove();
			$('.paysafe-card-logos img').removeClass('inactive active');
			$('#card_cvv').attr('maxlength', '3')
						  .attr('placeholder', paysafe_params.card_cvv_placeholder || 'Security code');
		}

		return detectedType;
	}

	function autoPopulateNameField() {
		if (paysafe_params && paysafe_params.debug) { console.log('Paysafe: Setting up name auto-population'); }

		// Track if user has manually edited the name field
		let userHasManuallyEdited = false;

		// Watch for changes in billing name fields
		const firstNameSelectors = [
			'#billing_first_name',
			'input[name="billing_first_name"]',
			'input[name="billing[first_name]"]'
		];

		const lastNameSelectors = [
			'#billing_last_name',
			'input[name="billing_last_name"]',
			'input[name="billing[last_name]"]'
		];

		function updateNameField() {
			// STOP auto-population if user has manually edited
			if (userHasManuallyEdited) {
				if (paysafe_params && paysafe_params.debug) { 
					console.log('Paysafe: Skipping auto-population (user has manually edited)'); 
				}
				return;
			}

			let firstName = '';
			let lastName = '';

			// Find first name
			for (let selector of firstNameSelectors) {
				const field = $(selector);
				if (field.length && field.val()) {
					firstName = field.val();
					break;
				}
			}

			// Find last name
			for (let selector of lastNameSelectors) {
				const field = $(selector);
				if (field.length && field.val()) {
					lastName = field.val();
					break;
				}
			}

			// Update name on card field
			const fullName = (firstName + ' ' + lastName).trim();
			if (fullName && $('#cardholder_name').length) {
				$('#cardholder_name').val(fullName);
				if (paysafe_params && paysafe_params.debug) { console.log('Paysafe: Auto-populated cardholder name to:', fullName); }
			}
		}

		// Detect manual edits to Name on Card field
		$(document)
		  .off('input.paysafeNameManual keydown.paysafeNameManual', '#cardholder_name')
		  .on('input.paysafeNameManual keydown.paysafeNameManual', '#cardholder_name', function(e) {
			  // Set flag on ANY manual input (typing, backspace, paste, etc.)
			  if (!userHasManuallyEdited) {
				  userHasManuallyEdited = true;
				  if (paysafe_params && paysafe_params.debug) { 
					  console.log('Paysafe: User manually edited Name on Card - auto-population disabled'); 
				  }
			  }
		  });

		// Set up listeners - use .off() to prevent duplicate handlers
		firstNameSelectors.forEach(function(selector) {
			$(document)
			  .off('change.paysafe blur.paysafe input.paysafe', selector)
			  .on('change.paysafe blur.paysafe input.paysafe', selector, updateNameField);
		});

		lastNameSelectors.forEach(function(selector) {
			$(document)
			  .off('change.paysafe blur.paysafe input.paysafe', selector)
			  .on('change.paysafe blur.paysafe input.paysafe', selector, updateNameField);
		});

		// Initial population - ONLY on page load (single call with delay)
		setTimeout(updateNameField, 300);

		// Also update when checkout updates (but only if not manually edited)
		$(document.body)
		  .off('updated_checkout.paysafeName')
		  .on('updated_checkout.paysafeName', function() {
			  setTimeout(updateNameField, 100);
		  });
	}
	function setupFormSubmission() {
	   var $wrap = $('#paysafe-payment-form');
	   // Clear any previous handlers
	   $wrap.off('.paysafe');
	   $('.paysafe-submit-button').off('.paysafe');

	   if ($wrap.is('form')) {
		   // Non-Woo pages may still render this as a real form
		   $wrap.on('submit.paysafe', function(e){
			   e.preventDefault();
			   processPayment();
		   });
	   } else {
		   // Woo checkout renders a div wrapper; if a standalone pay button is present, wire it
		   $('.paysafe-submit-button').on('click.paysafe', function(e){
			   e.preventDefault();
			   processPayment();
		   });
	   }
   }
	function processPayment() {
		if (window.paysafePCIMode === 'saq_a_only' && window.paysafeHostedFieldsFailed) {
			showError('Payment cannot be processed. Secure payment fields are required but not available.');
			return;
		}
		// Validate fields first (hosted fields path skips card validation by design)
		if (!validatePaymentFields()) {
			return;
		}

	// If hosted fields are active (and NOT EP-only) and we don't yet have a token, tokenize first
	if (window.paysafeFieldsInstance && window.paysafePCIMode !== 'saq_aep_only' && !$('input[name="paysafe_payment_token"]').length) {
			showLoading();
			// Block unsupported brands before tokenize
		   var _brand = (window.paysafeDetectedBrand || '').toLowerCase();
		   var _accepted = (paysafe_params.accepted_cards || []).map(function(s){ return (s||'').toLowerCase(); });
		   if (_brand && _accepted.length && _accepted.indexOf(_brand) === -1) {
			   hideLoading();
			   showError('This card type is not accepted');
			   return;
		   }
		window.paysafeFieldsInstance.tokenize({
			cardHolderName: ($('#cardholder_name').val() || '').trim()
		}).then(function (res) {
			// Attach the token for the server (cover both custom form and Woo checkout)
			jQuery('form.woocommerce-checkout, form.checkout, form#paysafe-payment-form')
			  .find('input[name="paysafe_payment_token"]').remove().end()
			  .append('<input type="hidden" name="paysafe_payment_token" value="' + res.token + '" />');
			// Proceed with normal AJAX submit
			const formData = getFormData();
			submitPayment(formData);
		}).catch(function (err) {
			hideLoading();
			console.error('Paysafe tokenize() error:', err);
			showError((err && err.displayMessage) || 'Payment tokenization failed');
		});
		return;
	}

	// EP path (non-hosted) or already tokenized
	showLoading();
	const formData = getFormData();
	submitPayment(formData);
}

	function validatePaymentFields() {
		let isValid = true;
		const errors = [];

		// Clear previous field errors
		$('.paysafe-iframe-field').removeClass('error');
		$('.paysafe-name-field').removeClass('error');
		$('#cardholder_name').removeClass('error');

	// Hosted fields path: rely on SDK validity (no guessing)
	if (window.paysafeFieldsInstance) {
	  const name = $('#cardholder_name').val() || '';
	  if (!name.trim() || name.trim().length < 2) {
		showError('Please enter the cardholder name', '#cardholder_name');
		$('#cardholder_name').addClass('error');
		__ps_setSubmitEnabled(false);
		return false;
	  }
	  if (!__ps_allFieldsValid()) {
		// Field-specific feedback: mark and message whichever is empty/invalid
		try {
		  var s = (window.__ps_fieldState||{});
		  // Card number
		  if (s.cardNumber && (s.cardNumber.isEmpty || !s.cardNumber.isValid)) {
			window.__ps_setFieldError('#cardNumber_container',
			  s.cardNumber.isEmpty ? 'Please enter your card number' : 'Enter a valid card number');
		  } else { window.__ps_clearFieldError('#cardNumber_container'); }
		  // Expiry
		  if (s.expiryDate && (s.expiryDate.isEmpty || !s.expiryDate.isValid)) {
			window.__ps_setFieldError('#cardExpiry_container',
			  s.expiryDate.isEmpty ? 'Please enter the expiration date (MM / YY)' : 'Enter a valid expiration date (MM / YY)');
		  } else { window.__ps_clearFieldError('#cardExpiry_container'); }
		  // CVV
		  if (s.cvv && (s.cvv.isEmpty || !s.cvv.isValid)) {
			window.__ps_setFieldError('#cardCvv_container',
			  s.cvv.isEmpty ? 'Please enter your security code' : 'Enter a valid security code');
		  } else { window.__ps_clearFieldError('#cardCvv_container'); }
		} catch(_e){}
		showError('Please correct the highlighted card fields.', '#cardNumber_container');
		__ps_setSubmitEnabled(false);
		return false;
	  }
	  return true;
	}

		// Regular fields validation
		const cardNumber = $('#card_number').val() ? $('#card_number').val().replace(/\D/g, '') : ''; // digits only
		const $cn = $('#card_number');
		const cnField = $cn.closest('.paysafe-iframe-field').length ? $cn.closest('.paysafe-iframe-field') : $cn;
		if (!cardNumber || cardNumber.length < 13) {
			errors.push('Please enter a valid card number');
			cnField.addClass('error');
			isValid = false;
		} else {
			// Luhn algorithm validation
			if (!validateCardNumber(cardNumber)) {
				errors.push('Invalid card number');
				cnField.addClass('error');
				isValid = false;
			}

			// Check if card type is accepted (use same map as detectCardType)
			const patterns = getCardBrandRegexMap();

			let detectedType = null;
			for (let [type, pattern] of Object.entries(patterns)) {
				if (pattern.test(cardNumber)) {
					detectedType = type;
					break;
				}
			}

			const acceptedCards = (paysafe_params.accepted_cards || []).map(function(s){ return (s||'').toLowerCase(); });
			if (detectedType && acceptedCards.length && !acceptedCards.includes(detectedType)) {
				errors.push('This card type is not accepted');
				cnField.addClass('error');
				isValid = false;
			}
		}

		// Expiry validation (strict MM / YY)
		const expiry = $('#card_expiry').val() || '';
		const expiryMatch = expiry.match(/^\s*(\d{2})\s*\/\s*(\d{2})\s*$/);
		if (!expiryMatch) {
			errors.push('Please enter a valid expiration date (MM / YY)');
			(function(){
				var $exp = $('#card_expiry');
				var $t = $exp.closest('.paysafe-iframe-field');
				if (!$t.length) { $t = $exp; }
				$t.addClass('error');
			})();
			isValid = false;
		} else {
			const month = parseInt(expiryMatch[1], 10);
			const year = parseInt('20' + expiryMatch[2], 10);
			const now = new Date();
			const currentYear = now.getFullYear();
			const currentMonth = now.getMonth() + 1;
			const maxYear = currentYear + 15; // clamp future year (e.g., disallow 12/32 once beyond policy)

			if (month < 1 || month > 12) {
				errors.push('Invalid expiration month');
				(function(){
					var $exp = $('#card_expiry');
					var $t = $exp.closest('.paysafe-iframe-field');
					if (!$t.length) { $t = $exp; }
					$t.addClass('error');
				})();
				isValid = false;
			} else if (year < currentYear || (year === currentYear && month < currentMonth)) {
				errors.push('Card has expired');
				(function(){
					var $exp2 = $('#card_expiry');
					var $t2 = $exp2.closest('.paysafe-iframe-field');
					if (!$t2.length) { $t2 = $exp2; }
					$t2.addClass('error');
				})();
				isValid = false;
			} else if (year > maxYear) {
				errors.push('Expiration year is too far in the future');
				(function(){
					var $exp3 = $('#card_expiry');
					var $t3 = $exp3.closest('.paysafe-iframe-field');
					if (!$t3.length) { $t3 = $exp3; }
					$t3.addClass('error');
				})();
				isValid = false;
			}
		}

		// CVV validation
		const cvv = $('#card_cvv').val() || '';
		const isAmex = /^3[47]/.test(cardNumber);

if (!/^\d+$/.test(cvv) || !cvv || cvv.length < 3 || (!isAmex && cvv.length > 3) || (isAmex && cvv.length !== 4)) {
	// Determine the appropriate error message
	var cvvErrorMsg = cvv.length === 0 
		? 'Please enter your card security code (CVV).' 
		: 'Please enter a valid security code';
	errors.push(cvvErrorMsg);
	
	// Mark CVV field as invalid using PaysafeCore
	if (typeof window.PaysafeCore !== 'undefined' && window.PaysafeCore.applyFieldState) {
		window.PaysafeCore.applyFieldState('#cardCvv_container', 'invalid');
	} else {
		// Fallback to manual error class
		(function(){
			var $cv = $('#card_cvv');
			var $t2 = $cv.closest('.paysafe-iframe-field');
			if (!$t2.length) { $t2 = $cv; }
			$t2.addClass('error');
		})();
	}
	isValid = false;
}

		// Name validation
		const name = $('#cardholder_name').val();
		if (!name || name.trim().length < 2) {
			errors.push('Please enter the cardholder name');
			$('#cardholder_name').addClass('error');
			isValid = false;
		}

if (!isValid) {
	// Use PaysafeCore.showError with field selector if available
	if (typeof window.PaysafeCore !== 'undefined' && window.PaysafeCore.showError) {
		// Find first field with error for positioning
		var firstErrorField = null;
		if (errors.length > 0 && errors[0].includes('security code')) {
			firstErrorField = '#cardCvv_container';
		}
		window.PaysafeCore.showError(errors, { 
			fieldSelector: firstErrorField,
			sticky: true 
		});
	} else {
		showError(errors.join('\n'));
	}
}

		return isValid;
	}

	function validateCardNumber(number) {
		// Luhn algorithm
		let sum = 0;
		let isEven = false;

		for (let i = number.length - 1; i >= 0; i--) {
			let digit = parseInt(number.charAt(i), 10);

			if (isEven) {
				digit *= 2;
				if (digit > 9) {
					digit -= 9;
				}
			}

			sum += digit;
			isEven = !isEven;
		}

		return (sum % 10) === 0;
	}

	function getFormData() {
		const expiry = $('#card_expiry').length ? ($('#card_expiry').val() || '') : '';
		const expiryMatch = expiry.match(/^\s*(\d{2})\s*\/\s*(\d{2})\s*$/);

		// Initialize the data object FIRST
		const data = { action: 'paysafe_process_payment' };
		if (typeof paysafe_params.nonce !== 'undefined') {
			data.nonce = paysafe_params.nonce;
		}
		// Resolve holder name once and include under both keys some back-ends expect
		var __holder = (($('#cardholder_name').val() || '') + '').trim();
		data.cardholder_name = __holder;
		data.holder_name     = __holder;

		// Get billing data from checkout form if available
		if ($('#billing_first_name').length) {
			data.first_name = $('#billing_first_name').val();
			data.last_name = $('#billing_last_name').val();
			data.email = $('#billing_email').val();
			data.phone = $('#billing_phone').val();
			data.address = $('#billing_address_1').val();
			data.city = $('#billing_city').val();
			data.province = $('#billing_state').val();
			data.country = $('#billing_country').val();
			data.postal_code = $('#billing_postcode').val();
		} else {
			// Parse name from cardholder field
			const cardholderName = $('#cardholder_name').val() || '';
			const nameParts = cardholderName.split(' ');
			data.first_name = nameParts[0] || '';
			data.last_name = nameParts.slice(1).join(' ') || '';
		}

			// Never attempt to read values out of hosted iframes.
			// SAQ-A-EP only: ALWAYS read raw fields (this is the primary EP path).
			if (window.paysafePCIMode === 'saq_aep_only') {
			// Fallback to regular (non-hosted) inputs
			const cardNumber = $('#card_number').val() ? $('#card_number').val().replace(/\D/g, '') : ''; // digits only
			data.card_number = cardNumber;
			data.card_expiry_month = expiryMatch ? expiryMatch[1] : '';
			// EP path: keep two-digit year to match strict MM/YY
			data.card_expiry_year = expiryMatch ? expiryMatch[2] : '';
			// Also send the raw string so gateway validate_fields() passes in SAQ-A-EP
			data.card_expiry = expiry;
			data.cvv = $('#card_cvv').length ? String($('#card_cvv').val() || '').replace(/\D/g, '') : '';
			// Provide alternate keys for any server-side tokenization helpers.
			data.exp_month = data.card_expiry_month;
			data.exp_year  = data.card_expiry_year; // 2-digit
		}
		data.save_card = $('#save_card').is(':checked') ? 1 : 0;

		// Sanitize name fields defensively before transport (server still validates)
		if (typeof data.holder_name === 'string')       { data.holder_name = _safeText(data.holder_name); }
		if (typeof data.cardholder_name === 'string')   { data.cardholder_name = _safeText(data.cardholder_name); }

		// Include token if previously created/attached
		var tokEl = $('input[name="paysafe_payment_token"]');
		if (tokEl.length && tokEl.val()) {
			data.paysafe_payment_token = tokEl.val();
		}

		return data;
	}

	function submitPayment(data) {
		if (!paysafe_params || !paysafe_params.ajax_url) {
			showError('Payment configuration is incomplete (AJAX URL missing). Please contact support.');
			return;
		}
		$.ajax({
			url: paysafe_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			timeout: 30000,
			data: data,
			success: function(response) {
				hideLoading();

				if (response.success) {
					// Payment successful
					if (window.paysafePaymentSuccess) {
						window.paysafePaymentSuccess(response.data);
					} else {
						showSuccess('Payment processed successfully!');

						// If in WooCommerce checkout, trigger order completion
						if ($('form.woocommerce-checkout').length) {
							// Reload page or redirect to thank you page
							if (response.data.redirect) {
								window.location.href = response.data.redirect;
							} else {
								location.reload();
							}
						}
					}
				} else {
					showError(
					  (response && response.data && response.data.message) ||
					  paysafe_params.error_text ||
					  'We couldn’t process the payment. Please try again.'
					);
				}
			},
			error: function() {
				hideLoading();
				showError(paysafe_params.error_text || 'We couldn’t process the payment. Please try again.');
			}
		});
	}

	function integrateWithWooCommerce() {
		if (paysafe_params && paysafe_params.debug) { console.log('Paysafe: Integrating with WooCommerce'); }

		// Handle WooCommerce checkout
		if ($('form.woocommerce-checkout').length > 0 || $('form.checkout').length > 0) {
			// Initialize form when payment method is selected
			$(document.body)
			  .off('payment_method_selected.paysafeInit')
			  .on('payment_method_selected.paysafeInit', function() {
				if ($('input[name="payment_method"]:checked').val() === 'paysafe') {
					if (paysafe_params && paysafe_params.debug) { console.log('Paysafe: Payment method selected'); }
					isInitialized = false; // Allow reinitialization
					setTimeout(function() {
						initializePaysafeForm();
						initializePaysafeTokenization();
					}, 100);
				}
			  });

			// Re-run on checkout fragment updates (shipping, totals, etc.)
			$(document.body)
			  .off('updated_checkout.paysafeInit2')
			  .on('updated_checkout.paysafeInit2', function() {
				  if ($('input[name="payment_method"]:checked').val() === 'paysafe') {
					  initializePaysafeForm();
					  initializePaysafeTokenization();
				  }
			  });
		  }
	  }

	// Boot on DOM ready
	$(function() {
		integrateWithWooCommerce();
		// If Paysafe is already selected, initialize immediately.
		if ($('input[name="payment_method"]:checked').val() === 'paysafe') {
			initializePaysafeForm();
			initializePaysafeTokenization();
		}

		// If the browser autofilled immediately on DOM ready (Chrome), push to hosted fields after a tick
		try {
			setTimeout(function(){
				if (window.paysafeFieldsInstance) {
					try { wireAutofillBridge(window.paysafeFieldsInstance); } catch(_e){}
				}
			}, 150);
		} catch(_e){}
	});

})(jQuery);

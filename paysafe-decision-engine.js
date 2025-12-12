/**
 * Paysafe Decision Engine - Single Source of Truth
 * File: /assets/js/paysafe-decision-engine.js
 * 
 * CRITICAL: This is the ONLY place that decides payment flow logic.
 * All other code (payment-guard.js, payment-form.js, etc.) must consult this engine.
 * 
 * Version: 1.0.0
 * Created: 2025-12-12
 */

(function($) {
	'use strict';
	
	/**
	 * Paysafe Decision Engine
	 * Single source of truth for all payment flow decisions
	 */
	window.PaysafeDecisionEngine = {
		
		/**
		 * MASTER DECISION FUNCTION
		 * This is the ONLY function that decides which payment flow to use
		 * 
		 * @returns {Object} decision object with:
		 *   - flow: 'saved_card' | 'hosted_tokenize' | 'direct_tokenize' | 'blocked' | 'not_paysafe' | 'already_tokenized'
		 *   - ready: boolean - is the payment ready to submit?
		 *   - errors: array of user-friendly error messages
		 *   - skipValidation: boolean - should validation be skipped?
		 *   - skipTokenization: boolean - should tokenization be skipped?
		 *   - debug: object with detailed state information (for debugging)
		 */
		getPaymentFlow: function() {
			const decision = {
				flow: null,
				ready: false,
				errors: [],
				skipValidation: false,
				skipTokenization: false,
				debug: {}
			};
			
			// Step 1: Is Paysafe even selected?
			const isPaysafe = this._isPaysafeSelected();
			decision.debug.isPaysafe = isPaysafe;
			
			if (!isPaysafe) {
				decision.flow = 'not_paysafe';
				decision.ready = true;
				decision.skipValidation = true;
				decision.skipTokenization = true;
				return decision;
			}
			
			// Step 2: What's the PCI mode?
			const pciMode = this._getPCIMode();
			decision.debug.pciMode = pciMode;
			
			// Step 3: Saved card or new card?
			const cardSelection = this._getCardSelection();
			decision.debug.cardSelection = cardSelection;
			
			// Step 4: Do we have a token already?
			const hasToken = this._hasPaymentToken();
			decision.debug.hasToken = hasToken;
			
			// Step 5: Are hosted fields available and in what state?
			const hostedFieldsState = this._getHostedFieldsState();
			decision.debug.hostedFields = hostedFieldsState;
			
			// Step 6: Get cardholder name state
			const nameState = this._getCardholderNameState();
			decision.debug.nameState = nameState;
			
			// ========================================================================
			// DECISION TREE - Single Source of Truth
			// ========================================================================
			
			// SAVED CARD PATH
			// User selected an existing saved card - no tokenization needed
			if (cardSelection.type === 'saved' && cardSelection.tokenId) {
				decision.flow = 'saved_card';
				decision.skipTokenization = true; // Server will use stored token
				
				// Check if CVV is required for saved cards
				const cvvRequired = this._isCVVRequiredForSavedCard();
				decision.debug.cvvRequired = cvvRequired;
				
				if (cvvRequired) {
					const cvvValid = this._validateSavedCardCVV(cardSelection.tokenId);
					decision.debug.savedCardCVV = cvvValid;
					
					if (!cvvValid.present) {
						decision.ready = false;
						decision.errors.push('Please enter your card security code (CVV)');
					} else if (!cvvValid.valid) {
						decision.ready = false;
						decision.errors.push('Invalid CVV format. Please enter 3 or 4 digits');
					} else {
						decision.ready = true;
					}
				} else {
					decision.ready = true; // No CVV needed, ready to submit
				}
				
				decision.skipValidation = false; // Still need to validate CVV if required
				return decision;
			}
			
			// ALREADY TOKENIZED
			// A payment token already exists in the form
			if (hasToken) {
				decision.flow = 'already_tokenized';
				decision.ready = true;
				decision.skipValidation = true;
				decision.skipTokenization = true;
				return decision;
			}
			
			// NEW CARD - SAQ-A ONLY MODE
			// Strict mode: hosted fields are REQUIRED, no fallback allowed
			if (pciMode === 'saq_a_only') {
				if (!hostedFieldsState.available || hostedFieldsState.failed) {
					decision.flow = 'blocked';
					decision.ready = false;
					decision.errors.push('Secure card fields are required but not available. Please refresh and try again.');
					return decision;
				}
				
				if (!hostedFieldsState.connected) {
					decision.flow = 'blocked';
					decision.ready = false;
					decision.errors.push('Card fields are still loading. Please wait a moment and try again.');
					return decision;
				}
				
				decision.flow = 'hosted_tokenize';
				decision.skipValidation = false;
				decision.skipTokenization = false;
				
				// Check all hosted field requirements
				if (!nameState.valid) {
					decision.ready = false;
					decision.errors.push('Please enter the cardholder name');
				} else if (!hostedFieldsState.valid) {
					decision.ready = false;
					decision.errors.push('Please complete all card fields (number, expiry, security code)');
				} else if (hostedFieldsState.unsupportedBrand) {
					decision.ready = false;
					decision.errors.push('This card type is not accepted');
				} else {
					decision.ready = true;
				}
				
				return decision;
			}

	// NEW CARD - SAQ-A WITH FALLBACK MODE (Default)
	// Try hosted fields first, fall back to direct tokenization if needed
	if (pciMode === 'saq_a_with_fallback') {

		// 1) STILL CONNECTING (0–8s window)
		// Hosted fields are in progress but not yet connected and not failed.
		// In this case we *block* submission with a gentle message instead of
		// immediately dropping to legacy card inputs.
		if (hostedFieldsState.connecting && !hostedFieldsState.failed) {
			decision.flow = 'hosted_tokenize';
			decision.skipValidation = false;
			decision.skipTokenization = false;
			decision.ready = false;
			decision.errors.push(
				'Secure card fields are still connecting.\nPlease wait a moment and try again.'
			);
			return decision;
		}

		// 2) HOSTED FIELDS READY (preferred path)
		if (hostedFieldsState.available && hostedFieldsState.connected && !hostedFieldsState.failed) {
			decision.flow = 'hosted_tokenize';
			decision.skipValidation = false;
			decision.skipTokenization = false;

			// Check all hosted field requirements
			if (!nameState.valid) {
				decision.ready = false;
				decision.errors.push('Please enter the cardholder name');
			} else if (!hostedFieldsState.valid) {
				decision.ready = false;
				decision.errors.push('Please complete all card fields (number, expiry, security code)');
			} else if (hostedFieldsState.unsupportedBrand) {
				decision.ready = false;
				decision.errors.push('This card type is not accepted');
			} else {
				decision.ready = true;
			}
		} else {
			// 3) FALLBACK: hosted fields have failed (timeout/error) => direct tokenization
			// No "hosted fields failed; using standard fields below" message here.
			// We fall back silently and let normal card validation messages show.
			decision.flow = 'direct_tokenize';
			decision.skipValidation = false;
			decision.skipTokenization = false;

			const directValid = this._validateDirectFields();
			decision.debug.directFields = directValid;
			decision.ready = directValid.valid;
			if (!directValid.valid) {
				decision.errors = directValid.errors;
			}
		}
		return decision;
	}

			// NEW CARD - SAQ-A-EP ONLY MODE
			// Direct tokenization only (no hosted fields)
			if (pciMode === 'saq_aep_only') {
				decision.flow = 'direct_tokenize';
				decision.skipValidation = false;
				decision.skipTokenization = false;
				
				const directValid = this._validateDirectFields();
				decision.debug.directFields = directValid;
				decision.ready = directValid.valid;
				
				if (!directValid.valid) {
					decision.errors = directValid.errors;
				}
				
				return decision;
			}
			
			// Shouldn't reach here - unknown state
			decision.flow = 'unknown';
			decision.ready = false;
			decision.errors.push('Unknown payment state. Please refresh and try again.');
			return decision;
		},
		
		// ========================================================================
		// PRIVATE HELPER FUNCTIONS
		// ========================================================================
		
		/**
		 * Check if Paysafe gateway is selected
		 */
		_isPaysafeSelected: function() {
			const radio = document.querySelector('input[name="payment_method"]:checked');
			return !!(radio && radio.value === 'paysafe');
		},
		
		/**
		 * Get the current PCI compliance mode
		 */
		_getPCIMode: function() {
			// Try multiple sources (set by payment-form.js on init)
			return (window.paysafePCIMode || 
				   (window.paysafe_params && window.paysafe_params.pci_compliance_mode) || 
				   'saq_a_with_fallback');
		},
		
		/**
		 * Determine if user selected saved card or new card
		 * @returns {Object} { type: 'saved'|'new', tokenId: string|null }
		 */
		_getCardSelection: function() {
			// Priority 1: Check explicit card selection radio buttons
			const selection = document.querySelector('input[name="paysafe-card-selection"]:checked');
			if (selection) {
				if (selection.value === 'saved') {
					// User explicitly selected "Use a saved card"
					const tokenRadio = document.querySelector('input[name="wc-paysafe-payment-token"]:checked');
					return {
						type: 'saved',
						tokenId: tokenRadio && tokenRadio.value !== 'new' ? tokenRadio.value : null
					};
				}
				// User explicitly selected "Use a new card"
				return { type: 'new', tokenId: null };
			}
			
			// Priority 2: Check legacy saved card toggle (some themes may not have selection radios)
			const savedToggle = document.querySelector('#paysafe-use-saved-card');
			const tokenRadio = document.querySelector('input[name="wc-paysafe-payment-token"]:checked');
			
			if (savedToggle && savedToggle.checked && tokenRadio && tokenRadio.value && tokenRadio.value !== 'new') {
				return { type: 'saved', tokenId: tokenRadio.value };
			}
			
			// Priority 3: If token radio is selected without explicit toggle
			if (tokenRadio && tokenRadio.value && tokenRadio.value !== 'new') {
				return { type: 'saved', tokenId: tokenRadio.value };
			}
			
			// Default: new card
			return { type: 'new', tokenId: null };
		},
		
		/**
		 * Check if a payment token already exists in the form
		 */
		_hasPaymentToken: function() {
			const tokenField = document.querySelector('input[name="paysafe_payment_token"]');
			return !!(tokenField && tokenField.value);
		},
		
		/**
		 * Get hosted fields state (availability, connection, validity)
		 */
	_getHostedFieldsState: function() {
		const state = {
			available: false,
			connected: false,
			valid: false,
			failed: false,
			unsupportedBrand: false,
			// New: explicit "connecting" flag for the 0–8s window where
			// hosted fields are still spinning up but have not timed out.
			connecting: false
		};

		// Check if hosted fields instance exists
		state.available = !!window.paysafeFieldsInstance;
		state.failed = !!window.paysafeHostedFieldsFailed;

		// "Connecting" = we have a hosted fields container on the page,
		// have not flagged failure yet, and no instance is available.
		// In that window the Decision Engine will block submit with a
		// gentle "still connecting" message instead of falling back.
		if (!state.available && !state.failed && document.querySelector('#cardNumber_container')) {
			state.connecting = true;
			return state;
		}

		if (!state.available) {
			return state;
		}

			// Check if hosted fields are connected (iframes loaded)
			const containers = ['#cardNumber_container', '#cardExpiry_container', '#cardCvv_container'];
			state.connected = containers.every(function(sel) {
				const el = document.querySelector(sel);
				return el && el.classList.contains('psf-connected');
			});
			
			if (!state.connected) {
				return state;
			}
			
		// Check if hosted fields are valid
			if (typeof window.__ps_allFieldsValid === 'function') {
				// Use the centralized validation function from payment-form.js
				state.valid = window.__ps_allFieldsValid();
			} else {
				// Fallback: check CSS classes
				state.valid = containers.every(function(sel) {
					const el = document.querySelector(sel);
					return el && 
						   el.classList.contains('psf-valid') && 
						   !el.classList.contains('psf-invalid') && 
						   !el.classList.contains('error');
				});
			}
			
			// Check if card brand is unsupported
			const detectedBrand = (window.paysafeDetectedBrand || '').toLowerCase();
			if (detectedBrand) {
				const acceptedCards = (window.paysafe_params && window.paysafe_params.accepted_cards) || [];
				const accepted = acceptedCards.map(function(c) { return (c || '').toLowerCase(); });
				state.unsupportedBrand = accepted.length > 0 && accepted.indexOf(detectedBrand) === -1;
			}
			
			return state;
		},
		
		/**
		 * Get cardholder name state
		 */
		_getCardholderNameState: function() {
			const nameField = document.getElementById('cardholder_name');
			const name = nameField ? (nameField.value || '').trim() : '';
			
			return {
				present: !!nameField,
				value: name,
				valid: name.length >= 2
			};
		},
		
		/**
		 * Check if CVV is required for saved cards
		 */
		_isCVVRequiredForSavedCard: function() {
			// This is set by the gateway settings
			// We can check if CVV fields are present in the saved card UI
			const cvvInputs = document.querySelectorAll('.paysafe-saved-card-cvv-input');
			return cvvInputs.length > 0;
		},
		
		/**
		 * Validate saved card CVV
		 */
		_validateSavedCardCVV: function(tokenId) {
			const result = { present: false, valid: false, value: '' };
			
			// Find the CVV input for this specific token
			const tokenRadio = document.querySelector('input[name="wc-paysafe-payment-token"][value="' + tokenId + '"]');
			if (!tokenRadio) {
				return result;
			}
			
			// Find the CVV input in the same card item
			const cardItem = tokenRadio.closest('.paysafe-saved-card-item');
			if (!cardItem) {
				return result;
			}
			
			const cvvInput = cardItem.querySelector('.paysafe-saved-card-cvv-input');
			if (!cvvInput) {
				return result;
			}
			
			result.present = true;
			result.value = (cvvInput.value || '').trim();
			
			// Validate CVV format: 3 or 4 digits
			result.valid = /^[0-9]{3,4}$/.test(result.value);
			
			return result;
		},
		
		/**
		 * Validate direct input fields (non-hosted)
		 */
		_validateDirectFields: function() {
			const result = { valid: false, errors: [] };
			
			// Get field values
			const numberEl = document.getElementById('card_number');
			const expiryEl = document.getElementById('card_expiry');
			const cvvEl = document.getElementById('card_cvv');
			
			const number = numberEl ? (numberEl.value || '').replace(/\D/g, '') : '';
			const expiry = expiryEl ? (expiryEl.value || '').trim() : '';
			const cvv = cvvEl ? (cvvEl.value || '').replace(/\D/g, '') : '';
			
			// Validate card number (Luhn algorithm)
			if (!number || number.length < 13) {
				result.errors.push('Please enter a valid card number');
			} else if (!this._luhnCheck(number)) {
				result.errors.push('Invalid card number');
			}
			
			// Validate expiry (MM / YY format)
			if (!expiry) {
				result.errors.push('Please enter the expiration date');
			} else if (!/^\d{2}\s*\/\s*\d{2}$/.test(expiry)) {
				result.errors.push('Please enter expiration date as MM / YY');
			} else {
				// Check if expired
				const match = expiry.match(/^(\d{2})\s*\/\s*(\d{2})$/);
				if (match) {
					const month = parseInt(match[1], 10);
					const year = 2000 + parseInt(match[2], 10);
					const now = new Date();
					const currentYear = now.getFullYear();
					const currentMonth = now.getMonth() + 1;
					
					if (month < 1 || month > 12) {
						result.errors.push('Invalid expiration month');
					} else if (year < currentYear || (year === currentYear && month < currentMonth)) {
						result.errors.push('Card has expired');
					}
				}
			}
			
			// Validate CVV
			if (!cvv) {
				result.errors.push('Please enter the security code');
			} else if (!/^[0-9]{3,4}$/.test(cvv)) {
				result.errors.push('Security code must be 3 or 4 digits');
			}
			
			result.valid = result.errors.length === 0;
			return result;
		},
		
		/**
		 * Luhn algorithm for card number validation
		 */
		_luhnCheck: function(cardNumber) {
			if (!cardNumber || typeof cardNumber !== 'string') {
				return false;
			}
			
			const digits = cardNumber.replace(/\D/g, '');
			if (digits.length < 13 || digits.length > 19) {
				return false;
			}
			
			let sum = 0;
			let isEven = false;
			
			// Loop through digits from right to left
			for (let i = digits.length - 1; i >= 0; i--) {
				let digit = parseInt(digits.charAt(i), 10);
				
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
		},
		
		// ========================================================================
		// PUBLIC API METHODS
		// ========================================================================
		
		/**
		 * Should we block form submission?
		 * @returns {boolean}
		 */
		shouldBlockSubmit: function() {
			const decision = this.getPaymentFlow();
			return !decision.ready;
		},
		
		/**
		 * Get user-friendly error messages
		 * @returns {Array<string>}
		 */
		getErrorMessages: function() {
			const decision = this.getPaymentFlow();
			return decision.errors;
		},
		
		/**
		 * Should we run tokenization before submit?
		 * @returns {boolean}
		 */
		needsTokenization: function() {
			const decision = this.getPaymentFlow();
			return !decision.skipTokenization && 
				   decision.flow !== 'already_tokenized' &&
				   decision.flow !== 'saved_card' &&
				   decision.flow !== 'not_paysafe';
		},
		
		/**
		 * Get the current payment flow type
		 * @returns {string} 'saved_card' | 'hosted_tokenize' | 'direct_tokenize' | 'blocked' | 'not_paysafe' | 'already_tokenized'
		 */
		getFlowType: function() {
			const decision = this.getPaymentFlow();
			return decision.flow;
		},
		
		/**
		 * Debug helper: log current decision
		 */
		logDecision: function() {
			const decision = this.getPaymentFlow();
			console.log('=== Paysafe Decision Engine ===');
			console.log('Flow:', decision.flow);
			console.log('Ready:', decision.ready);
			console.log('Errors:', decision.errors);
			console.log('Skip Validation:', decision.skipValidation);
			console.log('Skip Tokenization:', decision.skipTokenization);
			console.log('Debug Info:', decision.debug);
			console.log('===============================');
		}
	};
	
})(jQuery);
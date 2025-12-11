<?php
/**
 * Paysafe Payment Token Class
 * Extends WooCommerce's payment token system for saved cards
 * 
 * @package WooCommerce_Paysafe_Gateway
 * @since 1.0.3
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * WC_Payment_Token_Paysafe class
 * 
 * Handles Paysafe payment tokens for saved cards using WooCommerce's native tokenization
 */

if (!class_exists('WC_Payment_Token_CC')) {
	error_log('Paysafe Token Error: WC_Payment_Token_CC class not found');
	return;
}

class WC_Payment_Token_Paysafe extends WC_Payment_Token_CC {
	
	/**
	 * Token Type String
	 * Note: Parent class handles the type property internally
	 * @var string
	 */
	const TOKEN_TYPE = 'CC';
	
	/**
	 * Constructor
	 * Initialize the token with proper type
	 * 
	 * @param mixed $token Token ID or token object
	 */
	public function __construct($token = '') {
		// Set extra data for Paysafe-specific fields
		$this->extra_data = array_merge(
			$this->extra_data,
			array(
				'paysafe_profile_id' => '',
				'paysafe_card_id' => '',
				'paysafe_payment_token' => '',
			)
		);
		
		parent::__construct($token);
	}
	
	/**
	 * Get token type (must match parent "CC" so WooCommerce treats it as a standard card token)
	 * @param string $deprecated
	 * @return string
	 */
	public function get_type( $deprecated = '' ) {
		// CRITICAL: Must return "CC" not "Paysafe_CC" to work with WooCommerce core templates
		return 'CC';
	}
	
	/**
	 * Hook prefix must match core CC tokens so built-in filters keep working.
	 * @return string
	 */
	public function get_hook_prefix() {
		return 'woocommerce_payment_token_cc_get_';
	}
	
	/**
	 * Validate the token before saving
	 * @return boolean
	 */
	public function validate() {
		if (false === parent::validate()) {
			return false;
		}
		
		if (!$this->get_paysafe_payment_token()) {
			return false;
		}
		
		if (!$this->get_paysafe_profile_id()) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Get Paysafe profile ID
	 * @param string $context
	 * @return string
	 */
	public function get_paysafe_profile_id($context = 'view') {
		return $this->get_prop('paysafe_profile_id', $context);
	}
	
	/**
	 * Set Paysafe profile ID
	 * @param string $profile_id
	 */
	public function set_paysafe_profile_id($profile_id) {
		$this->set_prop('paysafe_profile_id', $profile_id);
	}
	
	/**
	 * Get Paysafe card ID
	 * @param string $context
	 * @return string
	 */
	public function get_paysafe_card_id($context = 'view') {
		return $this->get_prop('paysafe_card_id', $context);
	}
	
	/**
	 * Set Paysafe card ID
	 * @param string $card_id
	 */
	public function set_paysafe_card_id($card_id) {
		$this->set_prop('paysafe_card_id', $card_id);
	}
	
	/**
	 * Get Paysafe payment token
	 * @param string $context
	 * @return string
	 */
	public function get_paysafe_payment_token($context = 'view') {
		return $this->get_prop('paysafe_payment_token', $context);
	}
	
	/**
	 * Set Paysafe payment token
	 * @param string $payment_token
	 */
	public function set_paysafe_payment_token($payment_token) {
		$this->set_prop('paysafe_payment_token', $payment_token);
	}
	
	/**
	 * Get display name for payment method list
	 * @param string $deprecated Deprecated parameter
	 * @return string
	 */
	public function get_display_name($deprecated = '') {
		$display = sprintf(
			/* translators: 1: credit card type 2: last 4 digits 3: expiry month 4: expiry year */
			__('%1$s ending in %2$s (expires %3$s/%4$s)', 'paysafe-payment'),
			wc_get_credit_card_type_label($this->get_card_type()),
			$this->get_last4(),
			$this->get_expiry_month(),
			substr($this->get_expiry_year(), -2)
		);
		
		return $display;
	}
	
	/**
	 * Returns the card type with proper icon
	 * @return string HTML for card icon
	 */
	public function get_card_type_icon() {
		$card_type = $this->get_card_type();
		$icon_url = PAYSAFE_PLUGIN_URL . 'assets/images/card-' . $card_type . '.svg';
		
		if (file_exists(PAYSAFE_PLUGIN_PATH . 'assets/images/card-' . $card_type . '.svg')) {
			return '<img src="' . esc_url($icon_url) . '" alt="' . esc_attr($card_type) . '" style="height: 1.5em; width: auto; vertical-align: middle; margin-right: 0.5em;" />';
		}
		
		return '';
	}
	
	/**
	 * Get the data for this token
	 * @return array
	 */
	public function get_data() {
		$data = parent::get_data();
		
		// Add Paysafe-specific data
		$data['paysafe_profile_id'] = $this->get_paysafe_profile_id();
		$data['paysafe_card_id'] = $this->get_paysafe_card_id();
		$data['paysafe_payment_token'] = $this->get_paysafe_payment_token();
		
		return $data;
	}
	
	/**
	 * Initialize token data from database
	 * Override to handle Paysafe-specific meta data
	 * 
	 * @param object $data Token data from database
	 */
	public function read_data($data) {
		parent::read_data($data);
		
		// Load Paysafe-specific meta data
		if ($this->get_id()) {
			$this->read_meta_data();
			$meta_data = $this->get_meta_data();
			
			foreach ($meta_data as $meta) {
				switch ($meta->key) {
					case 'paysafe_profile_id':
						$this->set_paysafe_profile_id($meta->value);
						break;
					case 'paysafe_card_id':
						$this->set_paysafe_card_id($meta->value);
						break;
					case 'paysafe_payment_token':
						$this->set_paysafe_payment_token($meta->value);
						break;
				}
			}
		}
	}
	
	/**
	 * Save token data to database
	 * Override to save Paysafe-specific meta data
	 * 
	 * @return int|bool Token ID on success, false on failure
	 */
	public function save() {
		// Validate before saving
		if (!$this->validate()) {
			return false;
		}
		
		// Save using parent method
		$result = parent::save();
		
		if ($result && $this->get_id()) {
			// Save Paysafe-specific meta data
			$this->update_meta_data('paysafe_profile_id', $this->get_paysafe_profile_id());
			$this->update_meta_data('paysafe_card_id', $this->get_paysafe_card_id());
			$this->update_meta_data('paysafe_payment_token', $this->get_paysafe_payment_token());
			$this->save_meta_data();
		}
		
		return $result;
	}
	
	/**
	 * Get formatted card details for display
	 * @return array
	 */
	public function get_card_details() {
		$card_display_map = array(
			'visa' => __('Visa', 'paysafe-payment'),
			'mastercard' => __('Mastercard', 'paysafe-payment'),
			'amex' => __('American Express', 'paysafe-payment'),
			'discover' => __('Discover', 'paysafe-payment'),
			'jcb' => __('JCB', 'paysafe-payment'),
			'diners' => __('Diners Club', 'paysafe-payment'),
			'card' => __('Credit Card', 'paysafe-payment')
		);
		
		$card_type = $this->get_card_type();
		$card_label = isset($card_display_map[$card_type]) ? $card_display_map[$card_type] : $card_display_map['card'];
		
		return array(
			'type' => $card_type,
			'label' => $card_label,
			'last4' => $this->get_last4(),
			'expiry' => sprintf('%02d/%s', $this->get_expiry_month(), substr($this->get_expiry_year(), -2)),
			'expiry_month' => $this->get_expiry_month(),
			'expiry_year' => $this->get_expiry_year(),
			'icon' => $this->get_card_type_icon()
		);
	}
	
	/**
	 * Check if token is expired
	 * @return boolean
	 */
	public function is_expired() {
		$expiry_year = intval($this->get_expiry_year());
		$expiry_month = intval($this->get_expiry_month());
		
		if (!$expiry_year || !$expiry_month) {
			return false; // Can't determine, assume not expired
		}
		
		$now = new DateTime();
		$expiry = DateTime::createFromFormat('Y-m', $expiry_year . '-' . $expiry_month);
		
		if (!$expiry) {
			return false; // Invalid date format, assume not expired
		}
		
		// Set to end of expiry month
		$expiry->modify('last day of this month');
		$expiry->setTime(23, 59, 59);
		
		return $expiry < $now;
	}
	
	/**
	 * Check if this token can be used for payment
	 * @return boolean
	 */
	public function is_valid_for_payment() {
		// Check if token has all required data
		if (!$this->get_paysafe_payment_token() || !$this->get_paysafe_profile_id()) {
			return false;
		}
		
		// Check if not expired
		if ($this->is_expired()) {
			return false;
		}
		
		// Check if basic card data is present
		if (!$this->get_last4() || !$this->get_card_type()) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Get masked card number for display
	 * @return string
	 */
	public function get_masked_number() {
		$last4 = $this->get_last4();
		if (!$last4) {
			return '';
		}
		
		// Return masked format: **** **** **** 1234
		return '**** **** **** ' . $last4;
	}
	
	/**
	 * Get card network logo URL
	 * @return string
	 */
	public function get_card_logo_url() {
		$card_type = $this->get_card_type();
		if (!$card_type) {
			return '';
		}
		
		$logo_url = PAYSAFE_PLUGIN_URL . 'assets/images/card-' . $card_type . '.svg';
		
		if (file_exists(PAYSAFE_PLUGIN_PATH . 'assets/images/card-' . $card_type . '.svg')) {
			return $logo_url;
		}
		
		return '';
	}
	
	/**
	 * Format for JSON response
	 * @return array
	 */
	public function to_json() {
		return array(
			'id' => $this->get_id(),
			'gateway_id' => $this->get_gateway_id(),
			'type' => $this->get_card_type(),
			'last4' => $this->get_last4(),
			'expiry_month' => $this->get_expiry_month(),
			'expiry_year' => $this->get_expiry_year(),
			'display_name' => $this->get_display_name(),
			'is_default' => $this->is_default(),
			'is_expired' => $this->is_expired(),
			'is_valid' => $this->is_valid_for_payment(),
			'masked_number' => $this->get_masked_number(),
			'card_details' => $this->get_card_details(),
			'paysafe_profile_id' => $this->get_paysafe_profile_id(),
			'paysafe_card_id' => $this->get_paysafe_card_id()
		);
	}
}
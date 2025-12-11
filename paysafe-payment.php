<?php
/**
 * Plugin Name: Paysafe Payment Gateway
 * Plugin URI: https://next2technology.com
 * Description: Accept credit card payments through Paysafe (formerly Merrco/Payfirma) gateway with embedded payment forms
 * Version: 1.0.4
 * Author: Next2Technology
 * Author URI: https://next2technology.com
 * License: GPL v2 or later
 * Text Domain: paysafe-payment
 * Domain Path: /languages
 * Requires at least: 6.8.2
 * Requires PHP: 8.3
 * WC requires at least: 10.3.5
 * WC tested up to: 10.4.0
 * 
 * @package WooCommerce_Paysafe_Gateway
 * Last updated: 2025-11-13
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Load token class early to prevent WooCommerce template errors
 * This must happen before WooCommerce tries to display payment methods
 */
add_action('plugins_loaded', 'paysafe_load_token_class_early', 5);

function paysafe_load_token_class_early() {
	if (class_exists('WC_Payment_Token_CC') && !class_exists('WC_Payment_Token_Paysafe')) {
		require_once plugin_dir_path(__FILE__) . 'includes/class-paysafe-payment-token.php';
	}
}

// Define plugin constants
define('PAYSAFE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PAYSAFE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PAYSAFE_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('PAYSAFE_PLUGIN_FILE', __FILE__);

// Get version with automatic cache busting
function paysafe_get_version() {
	static $version = null;
	if ($version === null) {
		// Try to read from plugin header first
		if (!function_exists('get_plugin_data')) {
			require_once(ABSPATH . 'wp-admin/includes/plugin.php');
		}
		$plugin_data = get_plugin_data(PAYSAFE_PLUGIN_FILE, false, false);

		if (!empty($plugin_data['Version'])) {
			$version = $plugin_data['Version'];
		} else {
			// Fallback to file modification time for cache busting
			$version = filemtime(PAYSAFE_PLUGIN_FILE);
		}
	}
	return $version;
}
define('PAYSAFE_VERSION', paysafe_get_version());

/**
 * Check if WooCommerce is active
 */
function paysafe_is_woocommerce_active() {
	return class_exists('WooCommerce');
}

/**
 * Initialize the plugin
 */
class Paysafe_Payment_Plugin {

	/**
	 * Instance of this class
	 */
	private static $instance = null;

	/**
	 * Get instance
	 */
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		// Check dependencies
		add_action('admin_init', array($this, 'check_dependencies'));

		// Load plugin
		add_action('plugins_loaded', array($this, 'init'), 0);

		// Register activation/deactivation hooks
		register_activation_hook(PAYSAFE_PLUGIN_FILE, array($this, 'activate'));
		register_deactivation_hook(PAYSAFE_PLUGIN_FILE, array($this, 'deactivate'));

		// Add settings link
		add_filter('plugin_action_links_' . PAYSAFE_PLUGIN_BASENAME, array($this, 'add_settings_link'));

		// Declare HPOS compatibility
		add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
	}

	/**
	 * Check plugin dependencies
	 */
	public function check_dependencies() {
		if (!paysafe_is_woocommerce_active()) {
			add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
		}
	}

	/**
	 * WooCommerce missing notice
	 */
	public function woocommerce_missing_notice() {
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Paysafe Payment Gateway requires WooCommerce to be installed and activated.', 'paysafe-payment' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		// Load text domain
		$this->load_textdomain();

		// Check if WooCommerce is active
		if (!paysafe_is_woocommerce_active()) {
			return;
		}

		// Load required files
		$this->load_dependencies();

		// Initialize components
		$this->init_components();

		// Add gateway to WooCommerce
		add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
	}

	/**
	 * Load plugin text domain
	 */
	private function load_textdomain() {
		load_plugin_textdomain(
			'paysafe-payment',
			false,
			dirname(PAYSAFE_PLUGIN_BASENAME) . '/languages'
		);
	}

	/**
	 * Load required files
	 */
	private function load_dependencies() {
		// Core classes
		require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-api.php';
		require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-frontend.php';
		require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-ajax.php';

		// Admin class (only in admin)
		if (is_admin()) {
			require_once PAYSAFE_PLUGIN_PATH . 'includes/class-paysafe-admin.php';
		}

		// Gateway class (only if WooCommerce is active)
		if (class_exists('WC_Payment_Gateway')) {
			require_once PAYSAFE_PLUGIN_PATH . 'includes/class-wc-gateway-paysafe.php';
		}
	}

	/**
	 * Initialize plugin components
	 */
	private function init_components() {
		// Initialize AJAX handlers (always needed)
		// Note: This is already auto-initialized in class-paysafe-ajax.php

		// Initialize admin (only in admin)
		// Note: This is already auto-initialized in class-paysafe-admin.php

		// Initialize frontend (only on frontend)
		if (!is_admin()) {
			new Paysafe_Frontend();
		}
	}

	/**
	 * Add gateway to WooCommerce
	 */
	public function add_gateway($gateways) {
		if (class_exists('WC_Gateway_Paysafe')) {
			$gateways[] = 'WC_Gateway_Paysafe';
		}
		return $gateways;
	}

	/**
	 * Plugin activation
	 */
	public function activate() {
		global $wpdb;

		// Check PHP version
		if (version_compare(PHP_VERSION, '8.3', '<')) {
			deactivate_plugins( PAYSAFE_PLUGIN_BASENAME );
			wp_die( esc_html__( 'This plugin requires PHP 8.3 or higher.', 'paysafe-payment' ) );
		}

		// Create database tables
		$this->create_tables();

		// Store version
		update_option('paysafe_version', PAYSAFE_VERSION);

		// Create default options
		$this->create_default_options();

		// Clear permalinks
		flush_rewrite_rules();

		// Schedule cron events
		$this->schedule_cron_events();

		// Log activation
		$this->log_activation();
	}

	/**
	 * Create database tables
	 */
	private function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Transactions table
		$table_name = $wpdb->prefix . 'paysafe_transactions';

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			transaction_id varchar(100) NOT NULL,
			order_id bigint(20) UNSIGNED DEFAULT NULL,
			amount decimal(10,2) NOT NULL,
			currency varchar(3) DEFAULT 'CAD',
			status varchar(20) NOT NULL,
			transaction_type varchar(20) DEFAULT 'sale',
			customer_id bigint(20) UNSIGNED DEFAULT NULL,
			customer_email varchar(100),
			customer_name varchar(100),
			card_type varchar(20),
			card_suffix varchar(4),
			auth_code varchar(20),
			response_code varchar(10),
			response_message text,
			metadata longtext,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY transaction_id (transaction_id),
			KEY order_id (order_id),
			KEY customer_id (customer_id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		// Customer profiles table (for tokenization)
		$profiles_table = $wpdb->prefix . 'paysafe_customer_profiles';

		$sql .= "CREATE TABLE IF NOT EXISTS $profiles_table (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL,
			profile_id varchar(100) NOT NULL,
			customer_email varchar(100),
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_id (user_id),
			UNIQUE KEY profile_id (profile_id),
			KEY created_at (created_at)
		) $charset_collate;";

		// Saved cards table
		$cards_table = $wpdb->prefix . 'paysafe_saved_cards';

		$sql .= "CREATE TABLE IF NOT EXISTS $cards_table (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL,
			profile_id varchar(100) NOT NULL,
			card_id varchar(100) NOT NULL,
			payment_token varchar(100) NOT NULL,
			card_type varchar(20),
			card_suffix varchar(4),
			expiry_month varchar(2),
			expiry_year varchar(4),
			is_default tinyint(1) DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY card_id (card_id),
			KEY user_id (user_id),
			KEY profile_id (profile_id),
			KEY payment_token (payment_token)
		) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

	/**
	 * Create default options
	 */
	private function create_default_options() {
		// Check if options already exist
		$existing = get_option('woocommerce_paysafe_settings');

		if (!$existing) {
			// Set default options
			$defaults = array(
				'enabled' => 'no',
				'title' => __('Credit / Debit Card', 'paysafe-payment'),
				'description' => __('Pay securely using your credit or debit card.', 'paysafe-payment'),
				'sandbox' => 'yes',
				'enable_debug' => 'no',
				'authorization_type' => 'sale',
				'accepted_cards' => array('visa', 'mastercard', 'amex', 'discover'),
				'enable_saved_cards' => 'yes',
				'vault_prefix' => 'PSF-' . substr(md5(get_site_url()), 0, 4) . '-',
			);

			add_option('woocommerce_paysafe_settings', $defaults);
		}
	}

	/**
	 * Schedule cron events
	 */
	private function schedule_cron_events() {
		// Schedule daily cleanup of old logs
		if (!wp_next_scheduled('paysafe_daily_cleanup')) {
			wp_schedule_event(time(), 'daily', 'paysafe_daily_cleanup');
		}
	}

	/**
	 * Log activation
	 */
	private function log_activation() {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('Paysafe Payment Gateway activated - Version ' . PAYSAFE_VERSION);
		}
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate() {
		// Clear scheduled events
		wp_clear_scheduled_hook('paysafe_daily_cleanup');

		// Clear permalinks
		flush_rewrite_rules();

		// Log deactivation
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('Paysafe Payment Gateway deactivated');
		}
	}

	/**
	 * Add settings link to plugins page
	 */
	public function add_settings_link($links) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paysafe' ) ),
			esc_html__( 'Settings', 'paysafe-payment' )
		);
		
		$docs_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://docs.paysafe.com' ),
			esc_html__( 'Docs', 'paysafe-payment' )
		);

		array_unshift($links, $settings_link, $docs_link);

		return $links;
	}

	/**
	 * Declare HPOS compatibility
	 */
	public function declare_hpos_compatibility() {
		if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				PAYSAFE_PLUGIN_FILE,
				true
			);
		}
	}
}

// Initialize the plugin
Paysafe_Payment_Plugin::get_instance();

/**
 * Daily cleanup cron job
 */
add_action('paysafe_daily_cleanup', 'paysafe_daily_cleanup_job');
function paysafe_daily_cleanup_job() {
	// Clean up old logs (older than 30 days)
	// Guard against WooCommerce not being loaded to avoid fatal on undefined constant.
	if ( ! defined( 'WC_LOG_DIR' ) ) {
		return;
	}
	$log_dir = trailingslashit( WC_LOG_DIR );
	$files   = glob( $log_dir . 'paysafe-gateway-*.log' );

	if ($files) {
		$thirty_days_ago = strtotime('-30 days');

		foreach ($files as $file) {
			if (filemtime($file) < $thirty_days_ago) {
				@unlink($file);
			}
		}
	}

	// Clean up orphaned transactions (older than 90 days with no order)
	global $wpdb;
	$table_name = $wpdb->prefix . 'paysafe_transactions';

	// FIXED: Direct table name concatenation with backticks
	$wpdb->query($wpdb->prepare(
		"DELETE FROM `{$table_name}` 
		 WHERE order_id IS NULL 
		 AND created_at < %s",
		date('Y-m-d H:i:s', strtotime('-90 days'))
	));
}

/**
 * Plugin uninstall hook
 * This is called when the plugin is deleted
 */
register_uninstall_hook(PAYSAFE_PLUGIN_FILE, 'paysafe_uninstall');
function paysafe_uninstall() {
	// Capability guard (runs only when user deletes via Plugins screen)
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	// Check for user confirmation via option
	$confirmed = get_option('paysafe_uninstall_confirmed', false);
	if (!$confirmed) {
		return;
	}

	// Get uninstall option (allow users to keep data)
	$keep_data = get_option('paysafe_keep_data_on_uninstall', false);

	if (!$keep_data) {
		global $wpdb;

		// Remove database tables
		$tables = array(
			$wpdb->prefix . 'paysafe_transactions',
			$wpdb->prefix . 'paysafe_customer_profiles',
			$wpdb->prefix . 'paysafe_saved_cards'
		);

		foreach ($tables as $table) {
			// Fixed SQL injection vulnerability
			$wpdb->query("DROP TABLE IF EXISTS `{$table}`");
		}

		// Remove options
		delete_option('woocommerce_paysafe_settings');
		delete_option('paysafe_version');
		delete_option('paysafe_standalone_settings');
		delete_option('paysafe_keep_data_on_uninstall');
		delete_option('paysafe_uninstall_confirmed');

		// Remove user meta
		// Use an escaped underscore so only meta keys starting with 'paysafe_' match.
		// In MySQL LIKE, '_' is a single-character wildcard; escaping ensures literal underscore.
		$wpdb->query(
			$wpdb->prepare(
				// Add explicit ESCAPE clause for reliability across SQL modes.
				"DELETE FROM `{$wpdb->usermeta}` 
				 WHERE meta_key LIKE %s ESCAPE '\\\\'",
				'paysafe\_%'
			)
		);

		// Clear scheduled events
		wp_clear_scheduled_hook('paysafe_daily_cleanup');
	}
}
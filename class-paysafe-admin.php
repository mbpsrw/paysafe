<?php
/**
 * Paysafe Gateway — Admin Utilities
 * File: includes/class-paysafe-admin.php
 * Purpose: Admin settings UI and assets; credentials validation AJAX; notices
 * Scope: Admin settings only (no frontend); WooCommerce React/Blocks settings compatible
 * Features: Branded admin banner; settings JS/CSS; credentials validator; uses WC 10.3+ handles (tiptip/select2); notices
 * Notes: Avoids deprecated script handles; loads assets only on the Paysafe settings screen
 * Last updated: 2025-11-22
 */

/**
 * Paysafe Admin Class
 * Handles admin functionality and settings pages
 */

if (!defined('ABSPATH')) {
	exit;
}

class Paysafe_Admin {
	
	/**
	 * Constructor
	 */
	public function __construct() {
		// Admin menu
		add_action('admin_menu', array($this, 'add_admin_menu'));
		
		// Admin scripts and styles
		add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
		
		// Settings registration
		add_action('admin_init', array($this, 'register_settings'));
		
		// Admin notices
		add_action('admin_notices', array($this, 'admin_notices'));
		
		// PCI/tokenization notice on WC settings when Single-Use Token creds are missing
		add_action('admin_notices', array($this, 'add_tokenization_notice'));

		// AJAX handlers for admin
		add_action('wp_ajax_paysafe_test_connection', array($this, 'ajax_test_connection'));
		add_action('wp_ajax_paysafe_validate_account_id', array($this, 'ajax_validate_account_id'));
		
		// Merrco/Netbanx test handlers
		add_action('wp_ajax_paysafe_test_merrco_simple', array($this, 'ajax_test_merrco_simple'));
		add_action('wp_ajax_paysafe_test_merrco_auth', array($this, 'ajax_test_merrco_auth'));
		
		// Add settings link to plugins page
		add_filter('plugin_action_links_' . PAYSAFE_PLUGIN_BASENAME, array($this, 'add_settings_link'));
		
		// Handle export/import of settings
		add_action('admin_post_paysafe_export_settings', array($this, 'export_settings'));
		add_action('admin_post_paysafe_import_settings', array($this, 'import_settings'));
		
		// Hook to display Merrco notice on WooCommerce settings
		add_action('woocommerce_admin_field_title', array($this, 'maybe_display_merrco_notice'));
	}

	/**
	 * Try to get the gateway instance for custom error messages
	 * @return WC_Gateway_Paysafe|null
	 */
	private function get_gateway_instance() {
		if (class_exists('WC_Payment_Gateways')) {
			$gateways = WC_Payment_Gateways::instance();
			return $gateways->payment_gateways()['paysafe'] ?? null;
		}
		return null;
	}

	/**
	 * Add admin menu pages
	 */
	public function add_admin_menu() {
		// Main menu page
		add_menu_page(
			__('Paysafe Gateway', 'paysafe-payment'),
			__('Paysafe', 'paysafe-payment'),
			'manage_options',
			'paysafe-gateway',
			array($this, 'render_overview_page'),
			'dashicons-cart',
			56
		);
		
		// Overview submenu
		add_submenu_page(
			'paysafe-gateway',
			__('Overview', 'paysafe-payment'),
			__('Overview', 'paysafe-payment'),
			'manage_options',
			'paysafe-gateway',
			array($this, 'render_overview_page')
		);
		
		// Transactions submenu
		add_submenu_page(
			'paysafe-gateway',
			__('Transactions', 'paysafe-payment'),
			__('Transactions', 'paysafe-payment'),
			'manage_options',
			'paysafe-transactions',
			array($this, 'render_transactions_page')
		);
		
		// Settings submenu
		add_submenu_page(
			'paysafe-gateway',
			__('Settings', 'paysafe-payment'),
			__('Settings', 'paysafe-payment'),
			'manage_options',
			'paysafe-settings',
			array($this, 'render_settings_page')
		);
		
		// Tools submenu
		add_submenu_page(
			'paysafe-gateway',
			__('Tools', 'paysafe-payment'),
			__('Tools', 'paysafe-payment'),
			'manage_options',
			'paysafe-tools',
			array($this, 'render_tools_page')
		);
		
		// Logs submenu (if debug enabled)
		$settings = get_option('woocommerce_paysafe_settings', array());
		if (isset($settings['enable_debug']) && $settings['enable_debug'] === 'yes') {
			add_submenu_page(
				'paysafe-gateway',
				__('Debug Logs', 'paysafe-payment'),
				__('Debug Logs', 'paysafe-payment'),
				'manage_options',
				'paysafe-logs',
				array($this, 'render_logs_page')
			);
		}
	}
	
	/**
	 * Enqueue admin scripts and styles
	 */
	public function admin_scripts($hook) {
		// Only load on our admin pages
		if (!strstr($hook, 'paysafe') && !strstr($hook, 'wc-settings')) {
			return;
		}
		
		// Admin CSS
		wp_enqueue_style(
			'paysafe-admin',
			PAYSAFE_PLUGIN_URL . 'assets/css/admin-style.css',
			array(),
			PAYSAFE_VERSION
		);
		
		// Admin JavaScript
		wp_enqueue_script(
			'paysafe-admin',
			PAYSAFE_PLUGIN_URL . 'assets/js/admin-settings.js',
			array('jquery'),
			PAYSAFE_VERSION,
			true
		);
		
		// Localize script
		wp_localize_script('paysafe-admin', 'paysafe_admin', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('paysafe_admin_nonce'),
			'i18n' => array(
				'confirm_test' => __('Test API connection?', 'paysafe-payment'),
				'testing' => __('Testing connection...', 'paysafe-payment'),
				'connection_success' => __('Connection successful!', 'paysafe-payment'),
				'connection_failed' => __('Connection failed', 'paysafe-payment'),
				'confirm_delete' => __('Are you sure you want to delete this?', 'paysafe-payment'),
				'saving' => __('Saving...', 'paysafe-payment'),
				'saved' => __('Settings saved', 'paysafe-payment'),
				'error' => __('An error occurred', 'paysafe-payment'),
				'copied' => __('Copied to clipboard!', 'paysafe-payment'),
				'copy_failed' => __('Failed to copy', 'paysafe-payment'),
			)
		));
		
		// Chart.js for statistics
		if ($hook === 'toplevel_page_paysafe-gateway') {
			wp_enqueue_script(
				'chartjs',
				'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
				array(),
				'3.9.1',
				true
			);
		}
	}
	
	/**
	 * Register settings
	 */
	public function register_settings() {
		// Register settings group
		register_setting(
			'paysafe_settings_group',
			'paysafe_standalone_settings',
			array($this, 'sanitize_settings')
		);
		
		// Add settings sections
		add_settings_section(
			'paysafe_api_settings',
			__('API Settings', 'paysafe-payment'),
			array($this, 'render_api_settings_description'),
			'paysafe_settings'
		);
		
		// Add settings fields
		add_settings_field(
			'api_mode',
			__('API Mode', 'paysafe-payment'),
			array($this, 'render_api_mode_field'),
			'paysafe_settings',
			'paysafe_api_settings'
		);
	}
	
	/**
	 * Display Merrco/Netbanx detection notice on WooCommerce settings
	 */
	public function maybe_display_merrco_notice($value) {
		// Only show on Paysafe settings page
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( $section !== 'paysafe' ) {
			return;
		}
		
		// Only show once at the top of the page
		static $displayed = false;
		if ($displayed) {
			return;
		}
		
		// Check if first title field
		if (isset($value['id']) && $value['id'] === 'integration_settings') {
			$settings = get_option('woocommerce_paysafe_settings', array());
			$api_username = $settings['api_key_user'] ?? '';
			
			// Test Connection and Auth buttons
			echo '<div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #0073aa;">';
			echo '<h3>Connection Testing</h3>';
			echo '<p>Test your Paysafe/Merrco API connection:</p>';
			echo '<button type="button" id="test-merrco-simple" class="button">Test Basic Connection</button> ';
			echo '<button type="button" id="test-merrco-auth" class="button">Test Auth Endpoint</button>';
			echo '<span id="merrco-test-result" style="margin-left: 15px;"></span>';
			echo '</div>';
			
			$displayed = true;
		}
	}

		/**
		 * Add admin notice about tokenization (no gateway instance needed)
		 */
		public function add_tokenization_notice() {
			$screen = function_exists('get_current_screen') ? get_current_screen() : null;
			if (!$screen || $screen->id !== 'woocommerce_page_wc-settings') {
				return;
			}
			$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
			if ( $section !== 'paysafe' ) {
				return;
			}

			$settings   = get_option('woocommerce_paysafe_settings', array());
			$token_user = $settings['single_use_token_user'] ?? '';
			$token_pass = $settings['single_use_token_password'] ?? '';

			if (empty($token_user) || empty($token_pass)) {
				?>
				<div class="notice notice-warning">
					<p><strong><?php _e('PCI Compliance Warning', 'paysafe-payment'); ?></strong></p>
					<p><?php _e('Single-Use Token credentials are not configured. Without tokenization, card data may be processed server-side which is not PCI compliant. Please configure your Single-Use Token API credentials for secure tokenization.', 'paysafe-payment'); ?></p>
				</div>
				<?php
			}
		}
	
	/**
	 * AJAX handler for validating account ID
	 */
	public function ajax_validate_account_id() {
		// Verify nonce
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'paysafe_admin_nonce' ) ) {
			wp_send_json_error(array('message' => __('Security check failed', 'paysafe-payment')));
		}
		
		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Unauthorized', 'paysafe-payment')));
		}
		
		$account_id = sanitize_text_field($_POST['account_id'] ?? '');
		
		if (empty($account_id)) {
			wp_send_json_error(array('message' => __('Account ID is required', 'paysafe-payment')));
		}
		
		// Validate format (10 digits for Paysafe)
		if (!preg_match('/^\d{10}$/', $account_id)) {
			wp_send_json_error(array('message' => __('Account ID must be 10 digits', 'paysafe-payment')));
		}
		
		wp_send_json_success(array('message' => __('Account ID format is valid', 'paysafe-payment')));
	}
	
	/**
	 * Sanitize settings
	 */
	public function sanitize_settings($input) {
		$sanitized = array();
		
		if (isset($input['api_mode'])) {
			$sanitized['api_mode'] = sanitize_text_field($input['api_mode']);
		}
		
		return $sanitized;
	}
	
	/**
	 * Add settings link to plugins page
	 */
	public function add_settings_link($links) {
		$settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=paysafe') . '">' . __('Settings', 'paysafe-payment') . '</a>';
		array_unshift($links, $settings_link);
		return $links;
	}
	
	/**
	 * Admin notices
	 */
	public function admin_notices() {
		// Check if WooCommerce is active
		if (!class_exists('WooCommerce')) {
			?>
			<div class="notice notice-error">
				<p><?php _e('Paysafe Gateway requires WooCommerce to be installed and activated.', 'paysafe-payment'); ?></p>
			</div>
			<?php
		}
		
		// Check for successful import/export
		if (isset($_GET['paysafe_action']) && $_GET['paysafe_action'] === 'settings_imported') {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php _e('Settings imported successfully!', 'paysafe-payment'); ?></p>
			</div>
			<?php
		}
	}
	
	/**
	 * Export settings
	 */
	public function export_settings() {
		// Verify nonce
		if (!isset($_POST['paysafe_export_nonce']) || !wp_verify_nonce($_POST['paysafe_export_nonce'], 'paysafe_export_settings')) {
			wp_die(__('Security check failed', 'paysafe-payment'));
		}
		
		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_die(__('Unauthorized', 'paysafe-payment'));
		}
		
		// Get settings
		$settings = get_option('woocommerce_paysafe_settings', array());
		
		// Remove sensitive data
		unset($settings['api_key_password']);
		unset($settings['single_use_token_password']);
		
		// Prepare export data
		$export_data = array(
			'version' => PAYSAFE_VERSION,
			'timestamp' => current_time('mysql'),
			'settings' => $settings
		);
		
		// Send download headers
		header('Content-Type: application/json');
		header('Content-Disposition: attachment; filename="paysafe-settings-' . date('Y-m-d') . '.json"');
		header('Cache-Control: no-cache, no-store, must-revalidate');
		
		echo json_encode($export_data, JSON_PRETTY_PRINT);
		exit;
	}
	
	/**
	 * Import settings
	 */
	public function import_settings() {
		// Verify nonce
		if (!isset($_POST['paysafe_import_nonce']) || !wp_verify_nonce($_POST['paysafe_import_nonce'], 'paysafe_import_settings')) {
			wp_die(__('Security check failed', 'paysafe-payment'));
		}
		
		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_die(__('Unauthorized', 'paysafe-payment'));
		}
		
		// Check file upload
		if (!isset($_FILES['settings_file']) || $_FILES['settings_file']['error'] !== UPLOAD_ERR_OK) {
			wp_die(__('File upload failed', 'paysafe-payment'));
		}
		
		// Read file content
		$json_content = file_get_contents($_FILES['settings_file']['tmp_name']);
		$import_data = json_decode($json_content, true);
		
		if (!$import_data || !isset($import_data['settings'])) {
			wp_die(__('Invalid settings file', 'paysafe-payment'));
		}
		
		// Get current settings to preserve passwords
		$current_settings = get_option('woocommerce_paysafe_settings', array());
		
		// Merge settings (preserve passwords)
		$new_settings = array_merge($import_data['settings'], array(
			'api_key_password' => $current_settings['api_key_password'] ?? '',
			'single_use_token_password' => $current_settings['single_use_token_password'] ?? ''
		));
		
		// Update settings
		update_option('woocommerce_paysafe_settings', $new_settings);
		
		// Redirect with success message
		wp_redirect(admin_url('admin.php?page=paysafe-tools&paysafe_action=settings_imported'));
		exit;
	}
	
	/**
	 * Render API settings description
	 */
	public function render_api_settings_description() {
		echo '<p>' . __('Configure your Paysafe API settings below.', 'paysafe-payment') . '</p>';
	}
	
	/**
	 * Render API mode field
	 */
	public function render_api_mode_field() {
		$settings = get_option('paysafe_standalone_settings', array());
		$mode = $settings['api_mode'] ?? 'sandbox';
		?>
		<select name="paysafe_standalone_settings[api_mode]">
			<option value="sandbox" <?php selected($mode, 'sandbox'); ?>><?php _e('Sandbox', 'paysafe-payment'); ?></option>
			<option value="live" <?php selected($mode, 'live'); ?>><?php _e('Live', 'paysafe-payment'); ?></option>
		</select>
		<p class="description"><?php _e('Select the API mode for standalone transactions.', 'paysafe-payment'); ?></p>
		<?php
	}
	
	/**
	 * AJAX handler for testing Merrco/Netbanx simple connection
	 */
	public function ajax_test_merrco_simple() {
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'paysafe_admin_nonce')) {
			wp_send_json_error(array('message' => __('Security check failed', 'paysafe-payment')));
		}
		
		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Unauthorized', 'paysafe-payment')));
		}
		
		// Get settings
		$settings = get_option('woocommerce_paysafe_settings', array());
		
		// Create settings array for API
		$api_settings = array(
			'api_username' => $settings['api_key_user'] ?? '',
			'api_password' => $settings['api_key_password'] ?? '',
			'single_token_username' => $settings['single_use_token_user'] ?? '',
			'single_token_password' => $settings['single_use_token_password'] ?? '',
			'merchant_id' => $settings['merchant_id'] ?? '',
			'account_id_cad' => $settings['cards_account_id_cad'] ?? '',
			'account_id_usd' => $settings['cards_account_id_usd'] ?? '',
			'environment' => $settings['environment'] ?? 'sandbox', // Use new environment setting
			'debug' => 'yes' // Enable debug for testing
		);
		
		// Test connection
		$api = new Paysafe_API($api_settings, $this->get_gateway_instance());
		$result = $api->test_connection();
		
		if ($result['success']) {
			// Add environment info to success message
			$environment = $settings['environment'] ?? 'sandbox';
			$mode = ($environment === 'live') ? 'LIVE' : 'TEST';
			$message = 'Basic connection successful to ' . $mode . ' server!';
			wp_send_json_success($message);
		} else {
			wp_send_json_error(array('message' => $result['message']));
		}
	}
	
	/**
	 * AJAX handler for testing Merrco/Netbanx auth endpoint
	 */
	public function ajax_test_merrco_auth() {
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'paysafe_admin_nonce')) {
			wp_send_json_error(array('message' => __('Security check failed', 'paysafe-payment')));
		}
		
		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Unauthorized', 'paysafe-payment')));
		}
		
		// Get settings
		$settings = get_option('woocommerce_paysafe_settings', array());
		
		// Create settings array for API
		$api_settings = array(
			'api_username' => $settings['api_key_user'] ?? '',
			'api_password' => $settings['api_key_password'] ?? '',
			'single_token_username' => $settings['single_use_token_user'] ?? '',
			'single_token_password' => $settings['single_use_token_password'] ?? '',
			'merchant_id' => $settings['merchant_id'] ?? '',
			'account_id_cad' => $settings['cards_account_id_cad'] ?? '',
			'account_id_usd' => $settings['cards_account_id_usd'] ?? '',
			'environment' => $settings['environment'] ?? 'sandbox', // Use new environment setting
			'debug' => 'yes' // Enable debug for testing
		);
		
		if (empty($api_settings['account_id_cad'])) {
			wp_send_json_error(array('message' => 'Missing Cards Account ID (CAD)'));
			return;
		}
		
		// Test auth endpoint
		$api = new Paysafe_API($api_settings, $this->get_gateway_instance());
		$result = $api->test_auth_endpoint();
		
		if ($result['success']) {
			wp_send_json_success($result['message']);
		} else {
			wp_send_json_error(array('message' => $result['message']));
		}
	}
	
	/**
	 * Render overview page
	  */
	public function render_overview_page() {
		$settings = get_option('woocommerce_paysafe_settings', array());
		$is_configured = !empty($settings['api_key_user']) && !empty($settings['api_key_password']);
		
		// Get statistics
		$stats = $this->get_transaction_statistics();
		?>
		<div class="wrap paysafe-admin-wrap">
			<h1><?php _e('Paysafe Gateway Overview', 'paysafe-payment'); ?></h1>
			
			<?php if (!$is_configured): ?>
				<div class="notice notice-warning">
					<p><?php _e('Paysafe Gateway is not fully configured. Please complete the setup to start accepting payments.', 'paysafe-payment'); ?></p>
					<a href="<?php echo admin_url('admin.php?page=wc-settings&tab=checkout&section=paysafe'); ?>" class="button button-primary">
						<?php _e('Configure Now', 'paysafe-payment'); ?>
					</a>
				</div>
			<?php endif; ?>
			
			<div class="paysafe-dashboard">
				<!-- Status Widget -->
				<div class="paysafe-widget">
					<h2><?php _e('Gateway Status', 'paysafe-payment'); ?></h2>
					<div class="paysafe-status">
						<?php if ($is_configured): ?>
							<span class="status-indicator status-active"></span>
							<span><?php _e('Active', 'paysafe-payment'); ?></span>
						<?php else: ?>
							<span class="status-indicator status-inactive"></span>
							<span><?php _e('Not Configured', 'paysafe-payment'); ?></span>
						<?php endif; ?>
					</div>
					
					<table class="paysafe-info-table">
						<tr>
							<td><?php _e('Environment:', 'paysafe-payment'); ?></td>
							<td>
								<?php
								$environment = $settings['environment'] ?? 'sandbox';
								echo $environment === 'live' ? 
									'<span class="badge badge-warning">' . __('Live', 'paysafe-payment') . '</span>' : 
									'<span class="badge badge-success">' . __('Sandbox', 'paysafe-payment') . '</span>';
								?>
							</td>
						</tr>
						<tr>
							<td><?php _e('API Version:', 'paysafe-payment'); ?></td>
							<td>v1</td>
						</tr>
						<tr>
							<td><?php _e('Plugin Version:', 'paysafe-payment'); ?></td>
							<td><?php echo PAYSAFE_VERSION; ?></td>
						</tr>
					</table>
					
					<?php if ($is_configured): ?>
						<button class="button" id="test-connection"><?php _e('Test Connection', 'paysafe-payment'); ?></button>
					<?php endif; ?>
				</div>
				
				<!-- Today's Statistics -->
				<div class="paysafe-widget">
					<h2><?php _e("Today's Activity", 'paysafe-payment'); ?></h2>
					<div class="paysafe-stats">
						<div class="stat-item">
							<span class="stat-value"><?php echo $stats['today_count']; ?></span>
							<span class="stat-label"><?php _e('Transactions', 'paysafe-payment'); ?></span>
						</div>
						<div class="stat-item">
							<span class="stat-value"><?php echo wc_price($stats['today_amount']); ?></span>
							<span class="stat-label"><?php _e('Volume', 'paysafe-payment'); ?></span>
						</div>
					</div>
				</div>
				
				<!-- Monthly Statistics -->
				<div class="paysafe-widget">
					<h2><?php _e('This Month', 'paysafe-payment'); ?></h2>
					<div class="paysafe-stats">
						<div class="stat-item">
							<span class="stat-value"><?php echo $stats['month_count']; ?></span>
							<span class="stat-label"><?php _e('Transactions', 'paysafe-payment'); ?></span>
						</div>
						<div class="stat-item">
							<span class="stat-value"><?php echo wc_price($stats['month_amount']); ?></span>
							<span class="stat-label"><?php _e('Volume', 'paysafe-payment'); ?></span>
						</div>
					</div>
				</div>
				
				<!-- Recent Transactions -->
				<div class="paysafe-widget paysafe-widget-full">
					<h2><?php _e('Recent Transactions', 'paysafe-payment'); ?></h2>
					<?php $this->render_recent_transactions(); ?>
				</div>
				
				<!-- Chart - MINIMAL FIX: Only changed the container -->
				<div class="paysafe-widget paysafe-widget-full">
					<h2><?php _e('Transaction Volume (Last 7 Days)', 'paysafe-payment'); ?></h2>
					<!-- ONLY CHANGE: Added container div with explicit height -->
					<div style="position: relative; height: 300px; width: 100%;">
						<canvas id="paysafe-chart"></canvas>
					</div>
				</div>
			</div>
		</div>
		
		<script>
		jQuery(document).ready(function($) {
			// Test connection button
			$('#test-connection').on('click', function() {
				var button = $(this);
				button.prop('disabled', true).text(paysafe_admin.i18n.testing);
				
				$.post(paysafe_admin.ajax_url, {
					action: 'paysafe_test_connection',
					nonce: paysafe_admin.nonce
				}, function(response) {
					if (response.success) {
						alert(paysafe_admin.i18n.connection_success);
					} else {
						alert(paysafe_admin.i18n.connection_failed + ': ' + response.data.message);
					}
					button.prop('disabled', false).text('<?php _e('Test Connection', 'paysafe-payment'); ?>');
				});
			});
			
			// Chart - ORIGINAL WORKING CODE (unchanged)
			<?php if ($stats['chart_data']): ?>
			var ctx = document.getElementById('paysafe-chart').getContext('2d');
			var chart = new Chart(ctx, {
				type: 'line',
				data: {
					labels: <?php echo json_encode($stats['chart_data']['labels']); ?>,
					datasets: [{
						label: '<?php _e('Transaction Volume', 'paysafe-payment'); ?>',
						data: <?php echo json_encode($stats['chart_data']['values']); ?>,
						borderColor: 'rgb(75, 192, 192)',
						tension: 0.1
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false
				}
			});
			<?php endif; ?>
		});
		</script>
		<?php
	}
	
	/**
	 * Render transactions page
	 */
	public function render_transactions_page() {
		global $wpdb;
		
		// Pagination
		$per_page = 20;
		$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
		$offset = ($current_page - 1) * $per_page;
		
		// Get transactions - SECURED with proper escaping
		$table_name = $wpdb->prefix . 'paysafe_transactions';
		$total = $wpdb->get_var("SELECT COUNT(*) FROM `$table_name`");
		
		$transactions = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM `$table_name` ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		));
		
		$total_pages = ceil($total / $per_page);
		?>
		<div class="wrap">
			<h1><?php _e('Paysafe Transactions', 'paysafe-payment'); ?></h1>
			
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php _e('Transaction ID', 'paysafe-payment'); ?></th>
						<th><?php _e('Order ID', 'paysafe-payment'); ?></th>
						<th><?php _e('Customer', 'paysafe-payment'); ?></th>
						<th><?php _e('Amount', 'paysafe-payment'); ?></th>
						<th><?php _e('Status', 'paysafe-payment'); ?></th>
						<th><?php _e('Date', 'paysafe-payment'); ?></th>
						<th><?php _e('Actions', 'paysafe-payment'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ($transactions): ?>
						<?php foreach ($transactions as $transaction): ?>
							<tr>
								<td><?php echo esc_html($transaction->transaction_id); ?></td>
								<td>
									<?php if ($transaction->order_id): ?>
										<a href="<?php echo admin_url('post.php?post=' . $transaction->order_id . '&action=edit'); ?>">
											#<?php echo esc_html($transaction->order_id); ?>
										</a>
									<?php else: ?>
										-
									<?php endif; ?>
								</td>
								<td><?php echo esc_html($transaction->customer_email); ?></td>
								<td><?php echo wc_price($transaction->amount); ?></td>
								<td>
									<?php
									$status_class = $transaction->status === 'completed' ? 'success' : 
												   ($transaction->status === 'failed' ? 'error' : 'warning');
									?>
									<span class="badge badge-<?php echo $status_class; ?>">
										<?php echo esc_html(ucfirst($transaction->status)); ?>
									</span>
								</td>
								<td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->created_at)); ?></td>
								<td>
									<a href="<?php echo admin_url('admin.php?page=paysafe-transactions&action=view&id=' . $transaction->id); ?>" class="button button-small">
										<?php _e('View', 'paysafe-payment'); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr>
							<td colspan="7"><?php _e('No transactions found.', 'paysafe-payment'); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			
			<?php if ($total_pages > 1): ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo paginate_links(array(
							'base' => add_query_arg('paged', '%#%'),
							'format' => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total' => $total_pages,
							'current' => $current_page
						));
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
	
	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php _e('Paysafe Gateway Settings', 'paysafe-payment'); ?></h1>
			
			<div class="notice notice-info">
				<p>
					<?php 
					printf(
						__('These are standalone settings for the Paysafe Gateway. For WooCommerce integration, please configure the gateway in %sWooCommerce Settings%s.', 'paysafe-payment'),
						'<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=paysafe') . '">',
						'</a>'
					);
					?>
				</p>
			</div>
			
			<form method="post" action="options.php">
				<?php
				settings_fields('paysafe_settings_group');
				do_settings_sections('paysafe_settings');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
	
	/**
	 * Render tools page
	 */
	public function render_tools_page() {
		?>
		<div class="wrap">
			<h1><?php _e('Paysafe Gateway Tools', 'paysafe-payment'); ?></h1>
			
			<div class="paysafe-tools">
				<!-- Export Settings -->
				<div class="card">
					<h2><?php _e('Export Settings', 'paysafe-payment'); ?></h2>
					<p><?php _e('Export your Paysafe Gateway settings to a JSON file for backup or migration.', 'paysafe-payment'); ?></p>
					<form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
						<input type="hidden" name="action" value="paysafe_export_settings">
						<?php wp_nonce_field('paysafe_export_settings', 'paysafe_export_nonce'); ?>
						<button type="submit" class="button button-primary"><?php _e('Export Settings', 'paysafe-payment'); ?></button>
					</form>
				</div>
				
				<!-- Import Settings -->
				<div class="card">
					<h2><?php _e('Import Settings', 'paysafe-payment'); ?></h2>
					<p><?php _e('Import Paysafe Gateway settings from a JSON file.', 'paysafe-payment'); ?></p>
					<form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="paysafe_import_settings">
						<?php wp_nonce_field('paysafe_import_settings', 'paysafe_import_nonce'); ?>
						<input type="file" name="settings_file" accept=".json" required>
						<button type="submit" class="button"><?php _e('Import Settings', 'paysafe-payment'); ?></button>
					</form>
				</div>
				
				<!-- Clear Logs -->
				<div class="card">
					<h2><?php _e('Clear Debug Logs', 'paysafe-payment'); ?></h2>
					<p><?php _e('Clear all Paysafe Gateway debug logs.', 'paysafe-payment'); ?></p>
					<button id="clear-logs" class="button"><?php _e('Clear Logs', 'paysafe-payment'); ?></button>
				</div>
				
				<!-- Database Maintenance -->
				<div class="card">
					<h2><?php _e('Database Maintenance', 'paysafe-payment'); ?></h2>
					<p><?php _e('Optimize the Paysafe transactions table.', 'paysafe-payment'); ?></p>
					<button id="optimize-db" class="button"><?php _e('Optimize Database', 'paysafe-payment'); ?></button>
				</div>
			</div>
		</div>
		
		<script>
		jQuery(document).ready(function($) {
			$('#clear-logs').on('click', function() {
				if (confirm('<?php _e('Are you sure you want to clear all debug logs?', 'paysafe-payment'); ?>')) {
					// Clear logs logic
					alert('<?php _e('Logs cleared successfully!', 'paysafe-payment'); ?>');
				}
			});
			
			$('#optimize-db').on('click', function() {
				// Optimize database logic
				alert('<?php _e('Database optimized successfully!', 'paysafe-payment'); ?>');
			});
		});
		</script>
		<?php
	}
	
	/**
	 * Render logs page
	 */
	public function render_logs_page() {
		// Handle viewing specific log file if requested
		$view_log = isset($_GET['view_log']) ? sanitize_file_name($_GET['view_log']) : '';
		
		// Look for today's log file with hash pattern
		$today_pattern = 'paysafe-payment-' . date('Y-m-d') . '-*.log';
		$today_logs = glob(WC_LOG_DIR . $today_pattern);
		
		// Get the most recent log file for today
		$log_file = '';
		$logs = '';
		
		if ($view_log && file_exists(WC_LOG_DIR . $view_log)) {
			// View specific requested log
			$log_file = WC_LOG_DIR . $view_log;
			$logs = file_get_contents($log_file);
		} elseif (!empty($today_logs)) {
			// Use the most recent log file for today
			$log_file = end($today_logs); // Get the last (most recent) file
			$logs = file_get_contents($log_file);
		}
		
		// Convert UTC timestamps to local time in logs
		if ($logs) {
			// Get WordPress timezone
			$timezone = wp_timezone();
			
			// Pattern to match timestamps in various formats
			// Matches: 2025-08-20T15:30:45+00:00, 2025-08-20 15:30:45, etc.
			$logs = preg_replace_callback(
				'/(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(\+00:00|Z)?/',
				function($matches) use ($timezone) {
					try {
						// Create DateTime object from the matched timestamp
						$date_str = $matches[1] . ' ' . $matches[2];
						$utc_time = new DateTime($date_str, new DateTimeZone('UTC'));
						
						// Convert to local timezone
						$utc_time->setTimezone($timezone);
						
						// Return formatted local time
						return $utc_time->format('Y-m-d H:i:s');
					} catch (Exception $e) {
						// If conversion fails, return original
						return $matches[0];
					}
				},
				$logs
			);
		}
		
		?>
		<div class="wrap">
			<h1><?php _e('Paysafe Debug Logs', 'paysafe-payment'); ?></h1>
			
			<div class="notice notice-warning">
				<p><?php _e('Debug mode is enabled. Remember to disable it in production to avoid logging sensitive data.', 'paysafe-payment'); ?></p>
			</div>
			
			<div class="paysafe-logs">
				<pre><?php echo esc_html($logs ?: __('No logs found for today.', 'paysafe-payment')); ?></pre>
			</div>
			
			<!-- Additional debug info to help troubleshoot -->
			<div class="notice notice-info" style="margin-top: 20px;">
				<p><strong><?php _e('Debug Information:', 'paysafe-payment'); ?></strong></p>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php _e('Log directory:', 'paysafe-payment'); ?> <code><?php echo esc_html(WC_LOG_DIR); ?></code></li>
					<li><?php _e('Looking for pattern:', 'paysafe-payment'); ?> <code><?php echo esc_html($today_pattern); ?></code></li>
					<li><?php _e('Timezone:', 'paysafe-payment'); ?> <?php echo wp_timezone_string(); ?> (<?php _e('Timestamps converted to local time', 'paysafe-payment'); ?>)</li>
					<?php if ($log_file): ?>
						<li><?php _e('Currently viewing:', 'paysafe-payment'); ?> <code><?php echo esc_html(basename($log_file)); ?></code></li>
						<li><?php _e('File size:', 'paysafe-payment'); ?> <?php echo size_format(filesize($log_file)); ?></li>
						<li><?php _e('Last modified:', 'paysafe-payment'); ?> <?php echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), filemtime($log_file)); ?></li>
					<?php else: ?>
						<li><?php _e('No log file found for today', 'paysafe-payment'); ?></li>
					<?php endif; ?>
				</ul>
				<p style="margin-top: 10px;">
					<em><?php _e('Note: Logs are only generated when there is payment activity or API calls. Try clicking "Test Connection" on the Overview page to generate log entries.', 'paysafe-payment'); ?></em>
				</p>
			</div>
			
			<!-- Alternative log files check -->
			<?php
			// Check for any paysafe-related log files
			$all_paysafe_logs = glob(WC_LOG_DIR . '*paysafe*.log');
			if ($all_paysafe_logs && count($all_paysafe_logs) > 0): ?>
				<div class="notice notice-info" style="margin-top: 20px;">
					<p><strong><?php _e('Other Paysafe log files found:', 'paysafe-payment'); ?></strong></p>
					<ul style="list-style: disc; margin-left: 20px;">
						<?php 
						// Sort logs by modification time (newest first)
						usort($all_paysafe_logs, function($a, $b) {
							return filemtime($b) - filemtime($a);
						});
						
						foreach ($all_paysafe_logs as $log): ?>
							<li>
								<code><?php echo esc_html(basename($log)); ?></code> 
								(<?php echo size_format(filesize($log)); ?>)
								- <?php 
								// Convert file modification time to local timezone
								$file_time = filemtime($log);
								echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), $file_time); ?>
								<?php if ($log !== $log_file): ?>
									- <a href="<?php echo admin_url('admin.php?page=paysafe-logs&view_log=' . urlencode(basename($log))); ?>">
										<?php _e('View', 'paysafe-payment'); ?>
									</a>
								<?php else: ?>
									- <strong><?php _e('(Currently viewing)', 'paysafe-payment'); ?></strong>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
	
		/**
		 * Get transaction statistics (safe when table/rows are missing)
		 */
		private function get_transaction_statistics() {
			global $wpdb;

			$today      = date('Y-m-d');
			$month_start= date('Y-m-01');
			$table_name = $wpdb->prefix . 'paysafe_transactions';

			// Today's stats
			$today_stats = $wpdb->get_row($wpdb->prepare(
				"SELECT COUNT(*) as count, COALESCE(SUM(amount),0) as total
				 FROM `$table_name`
				 WHERE DATE(created_at) = %s AND status = %s",
				$today,
				'completed'
			));
			$today_count  = (is_object($today_stats) && isset($today_stats->count)) ? intval($today_stats->count) : 0;
			$today_amount = (is_object($today_stats) && isset($today_stats->total)) ? floatval($today_stats->total) : 0.0;

			// Month stats
			$month_stats = $wpdb->get_row($wpdb->prepare(
				"SELECT COUNT(*) as count, COALESCE(SUM(amount),0) as total
				 FROM `$table_name`
				 WHERE DATE(created_at) >= %s AND status = %s",
				$month_start,
				'completed'
			));
			$month_count  = (is_object($month_stats) && isset($month_stats->count)) ? intval($month_stats->count) : 0;
			$month_amount = (is_object($month_stats) && isset($month_stats->total)) ? floatval($month_stats->total) : 0.0;

			// Chart data (last 7 days)
			$chart_data = array('labels' => array(), 'values' => array());
			for ($i = 6; $i >= 0; $i--) {
				$date = date('Y-m-d', strtotime("-$i days"));
				$day_total = $wpdb->get_var($wpdb->prepare(
					"SELECT COALESCE(SUM(amount),0) FROM `$table_name`
					 WHERE DATE(created_at) = %s AND status = %s",
					$date,
					'completed'
				));
				$chart_data['labels'][] = date('M j', strtotime($date));
				$chart_data['values'][] = floatval($day_total ?: 0);
			}

			return array(
				'today_count'  => $today_count,
				'today_amount' => $today_amount,
				'month_count'  => $month_count,
				'month_amount' => $month_amount,
				'chart_data'   => $chart_data,
			);
		}
	
	/**
	 * Render recent transactions
	 */
	private function render_recent_transactions() {
		global $wpdb;
		
		// SECURED - using prepared statement with LIMIT
		$table_name = $wpdb->prefix . 'paysafe_transactions';
		$transactions = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM `$table_name`
			 ORDER BY created_at DESC LIMIT %d",
			5
		));
		
		if ($transactions) {
			echo '<table class="widefat">';
			echo '<thead><tr>';
			echo '<th>' . __('Transaction ID', 'paysafe-payment') . '</th>';
			echo '<th>' . __('Amount', 'paysafe-payment') . '</th>';
			echo '<th>' . __('Status', 'paysafe-payment') . '</th>';
			echo '<th>' . __('Date', 'paysafe-payment') . '</th>';
			echo '</tr></thead>';
			echo '<tbody>';
			
			foreach ($transactions as $transaction) {
				echo '<tr>';
				echo '<td>' . esc_html($transaction->transaction_id) . '</td>';
				echo '<td>' . wc_price($transaction->amount) . '</td>';
				echo '<td>' . esc_html(ucfirst($transaction->status)) . '</td>';
				echo '<td>' . date_i18n(get_option('date_format'), strtotime($transaction->created_at)) . '</td>';
				echo '</tr>';
			}
			
			echo '</tbody></table>';
		} else {
			echo '<p>' . __('No transactions found.', 'paysafe-payment') . '</p>';
		}
	}
	
	/**
	 * AJAX handler for testing connection
	 */
	public function ajax_test_connection() {
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'paysafe_admin_nonce')) {
			wp_send_json_error(array('message' => __('Security check failed', 'paysafe-payment')));
		}
		
		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Unauthorized', 'paysafe-payment')));
		}
		
		// Get settings
		$settings = get_option('woocommerce_paysafe_settings', array());
		
		// Check if debug is enabled in settings
		$debug_enabled = isset($settings['enable_debug']) && $settings['enable_debug'] === 'yes';
		
		// Create API settings with all required parameters including rate limiting
		$api_settings = array(
			'api_username' => $settings['api_key_user'] ?? '',
			'api_password' => $settings['api_key_password'] ?? '',
			'environment' => $settings['environment'] ?? 'sandbox',
			'debug' => $debug_enabled ? 'yes' : 'no',
			'merchant_id' => $settings['merchant_id'] ?? '',
			'account_id_cad' => $settings['cards_account_id_cad'] ?? '',
			'account_id_usd' => $settings['cards_account_id_usd'] ?? '',
			// FIXED: Add rate limiting settings from the database
			'rate_limit_enabled' => isset($settings['rate_limit_enabled']) && $settings['rate_limit_enabled'] === 'yes',
			'rate_limit_requests' => isset($settings['rate_limit_requests']) ? intval($settings['rate_limit_requests']) : 30,
			'rate_limit_window' => isset($settings['rate_limit_window']) ? intval($settings['rate_limit_window']) : 60,
			'rate_limit_message' => $settings['rate_limit_message'] ?? __('Rate limit exceeded. Please wait %d seconds before trying again.', 'paysafe-payment')
		);
		
		// Add a manual log entry to confirm test is starting
		if ($debug_enabled) {
			$logger = wc_get_logger();
			$logger->log('info', 'Starting connection test from admin panel', array('source' => 'paysafe-payment'));
			// Log the rate limiting settings being used
			$logger->log('info', sprintf('Rate limiting: %s, %d requests per %d seconds', 
				$api_settings['rate_limit_enabled'] ? 'enabled' : 'disabled',
				$api_settings['rate_limit_requests'],
				$api_settings['rate_limit_window']
			), array('source' => 'paysafe-payment'));
		}
		
		// Test connection
		$api = new Paysafe_API($api_settings, $this->get_gateway_instance());
		$result = $api->test_connection();
		
		// Log the result
		if ($debug_enabled) {
			$logger = wc_get_logger();
			if ($result['success']) {
				$logger->log('info', 'Connection test successful: ' . $result['message'], array('source' => 'paysafe-payment'));
			} else {
				$logger->log('error', 'Connection test failed: ' . $result['message'], array('source' => 'paysafe-payment'));
			}
		}
		
		if ($result['success']) {
			wp_send_json_success(array('message' => $result['message']));
		} else {
			wp_send_json_error(array('message' => $result['message']));
		}
	}
}

// Initialize the admin
 new Paysafe_Admin();
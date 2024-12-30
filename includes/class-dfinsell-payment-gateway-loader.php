<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';

/**
 * Class DFINSELL_PAYMENT_GATEWAY_Loader
 * Handles the loading and initialization of the DFin Sell Payment Gateway plugin.
 */
class DFINSELL_PAYMENT_GATEWAY_Loader
{
	private static $instance = null;
	private $admin_notices;
	
	private $sip_protocol;
    private $sip_host;
	
	/**
	 * Get the singleton instance of this class.
	 * @return DFINSELL_PAYMENT_GATEWAY_Loader
	 */
	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Constructor. Sets up actions and hooks.
	 */
	private function __construct()
	{

		$this->sip_protocol = SIP_PROTOCOL;
        $this->sip_host = SIP_HOST;

		$this->admin_notices = new DFINSELL_PAYMENT_GATEWAY_Admin_Notices();

		add_action('admin_init', [$this, 'dfinsell_handle_environment_check']);
		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
		add_action('plugins_loaded', [$this, 'dfinsell_init']);

		// Register the AJAX action callback for checking payment status
		add_action('wp_ajax_check_payment_status', array($this, 'dfinsell_handle_check_payment_status_request'));
		add_action('wp_ajax_nopriv_check_payment_status', array($this, 'dfinsell_handle_check_payment_status_request'));

		add_action('wp_ajax_popup_closed_event', array($this, 'handle_popup_close'));
		add_action('wp_ajax_nopriv_popup_closed_event', array($this, 'handle_popup_close'));

		register_activation_hook(DFINSELL_PAYMENT_GATEWAY_FILE, 'dfinsell_activation_check');
	}
	

	/**
	 * Initializes the plugin.
	 * This method is hooked into 'plugins_loaded' action.
	 */
	public function dfinsell_init()
	{
		// Check if the environment is compatible
		$environment_warning = dfinsell_check_system_requirements();
		if ($environment_warning) {
			return;
		}

		// Initialize gateways
		$this->dfinsell_init_gateways();

		// Initialize REST API
		$rest_api = DFINSELL_PAYMENT_GATEWAY_REST_API::get_instance();
		$rest_api->dfinsell_register_routes();

		// Add plugin action links
		add_filter('plugin_action_links_' . plugin_basename(DFINSELL_PAYMENT_GATEWAY_FILE), [$this, 'dfinsell_plugin_action_links']);

		// Add plugin row meta
		add_filter('plugin_row_meta', [$this, 'dfinsell_plugin_row_meta'], 10, 2);
	}

	/**
	 * Initialize gateways.
	 */
	private function dfinsell_init_gateways()
	{
		if (!class_exists('WC_Payment_Gateway')) {
			return;
		}

		include_once DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-dfinsell-payment-gateway.php';

		add_filter('woocommerce_payment_gateways', function ($methods) {
			$methods[] = 'DFINSELL_PAYMENT_GATEWAY';
			return $methods;
		});
	}


	private function get_api_url($endpoint)
	{
		$base_url = $this->sip_host;
		return $this->sip_protocol . $base_url . $endpoint;
	}

	/**
	 * Add action links to the plugin page.
	 * @param array $links
	 * @return array
	 */
	public function dfinsell_plugin_action_links($links)
	{
		$plugin_links = [
			'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=dfinsell')) . '">' . esc_html__('Settings', 'dfinsell-payment-gateway') . '</a>',
		];

		return array_merge($plugin_links, $links);
	}

	/**
	 * Add row meta to the plugin page.
	 * @param array $links
	 * @param string $file
	 * @return array
	 */
	public function dfinsell_plugin_row_meta($links, $file)
	{
		if (plugin_basename(DFINSELL_PAYMENT_GATEWAY_FILE) === $file) {
			$row_meta = [
				'docs'    => '<a href="' . esc_url(apply_filters('dfinsell_docs_url', 'https://www.dfin.ai/api/docs/wordpress-plugin')) . '" target="_blank">' . esc_html__('Documentation', 'dfinsell-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('dfinsell_support_url', 'https://www.dfin.ai/reach-out')) . '" target="_blank">' . esc_html__('Support', 'dfinsell-payment-gateway') . '</a>',
			];

			$links = array_merge($links, $row_meta);
		}

		return $links;
	}

	/**
	 * Check the environment and display notices if necessary.
	 */
	public function dfinsell_handle_environment_check()
	{
		$environment_warning = dfinsell_check_system_requirements();
		if ($environment_warning) {
			// Sanitize the environment warning before displaying it
			$this->admin_notices->dfinsell_add_notice('error', 'error', sanitize_text_field($environment_warning));
		}
	}

	/**
	 * Handle the AJAX request for checking payment status.
	 * @param $request
	 */
	public function dfinsell_handle_check_payment_status_request($request)
	{
		// Verify nonce for security (recommended)
		// Sanitize and unslash the 'security' value
		$security = isset($_POST['security']) ? sanitize_text_field(wp_unslash($_POST['security'])) : '';

		// Check the nonce for security
		if (empty($security) || !wp_verify_nonce($security, 'dfinsell_payment')) {
		    wp_send_json_error(['message' => 'Nonce verification failed.']);
		    wp_die();
		}

		// Sanitize and validate the order ID from $_POST
		$order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : null;
		if (!$order_id) {
			wp_send_json_error(array('error' => esc_html__('Invalid order ID', 'dfinsell-payment-gateway')));
		}

		// Call the function to check payment status with the validated order ID
		return $this->dfinsell_check_payment_status($order_id);
	}

	/**
	 * Check the payment status for an order.
	 * @param int $order_id
	 * @return WP_REST_Response
	 */
	public function dfinsell_check_payment_status($order_id)
	{
		// Get the order details
		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_REST_Response(['error' => esc_html__('Order not found', 'dfinsell-payment-gateway')], 404);
		}

		$payment_return_url = esc_url($order->get_checkout_order_received_url());
		// Check the payment status
		if ($order) {
			if ($order->is_paid()) {
				wp_send_json_success(['status' => 'success', 'redirect_url' => $payment_return_url]);
			} elseif ($order->has_status('failed')) {
				wp_send_json_success(['status' => 'failed', 'redirect_url' => $payment_return_url]);
			}
		}

		// Default to pending status
		wp_send_json_success(['status' => 'pending']);
	}

	
	public function handle_popup_close() {

		// Sanitize and unslash the 'security' value
		$security = isset($_POST['security']) ? sanitize_text_field(wp_unslash($_POST['security'])) : '';

		// Check the nonce for security
		if (empty($security) || !wp_verify_nonce($security, 'dfinsell_payment')) {
		    wp_send_json_error(['message' => 'Nonce verification failed.']);
		    wp_die();
		}
	
		// Get the order ID from the request
		$order_id = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : null;
	
		// Validate order ID
		if (!$order_id) {
			wp_send_json_error(['message' => 'Order ID is missing.']);
			wp_die();
		}
	
		// Call third-party API to update status
		$transactionStatusApiUrl = $this->get_api_url('/api/update-txn-status'); // Replace with the actual API URL
		// Prepare data to send in API request
		$response = wp_remote_post($transactionStatusApiUrl, [
			'method'    => 'POST',
			'body'      => wp_json_encode([
				'order_id' => $order_id,
				'status'   => 'updated', // Replace with the status you want to send
			]),
			'headers'   => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $security, // Add your API key or authentication header
			],
			'timeout'   => 15,
		]);
	
		// Check if the request was successful
		if (is_wp_error($response)) {
			wp_send_json_error(['message' => 'Failed to update third-party status.']);
			wp_die();
		}
	
		// Parse the response from the third-party API
		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		// Handle the response from the third-party API (example: check if status is "success")
		if (isset($response_data['status']) && $response_data['status'] === true) {
			// Fetch the order in WordPress (WooCommerce order)
			$order = wc_get_order($order_id); // Assuming you're using WooCommerce. If not, use appropriate WP functions.
	
			if ($order) {
				// Update the order status in WooCommerce
				$order->update_status($response_data['transaction_status'], 'Status updated from third-party API.');
	
				// Respond with success
				wp_send_json_success(['message' => 'Popup closed and status updated successfully in WordPress.', 'order_id' => $order_id]);
			} else {
				wp_send_json_error(['message' => 'Order not found in WordPress.']);
			}
		} else {
			// Respond with an error if the third-party update failed
			wp_send_json_error(['message' => 'Failed to update status in third-party API.']);
		}
	
		wp_die();
	}
	
	
}

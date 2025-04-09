<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

class DFINSELL_PAYMENT_GATEWAY_REST_API
{
	private $logger;
	private static $instance = null;

	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct()
	{
		// Initialize the logger
		$this->logger = wc_get_logger();
	}

	public function dfinsell_register_routes()
	{
		// Log incoming request with sanitized parameters
		add_action('rest_api_init', function () {
			register_rest_route('dfinsell/v1', '/data', array(
				'methods' => 'POST',
				'callback' => array($this, 'dfinsell_handle_api_request'),
				'permission_callback' => '__return_true',
			));
		});
	}

	private function dfinsell_verify_api_key($api_key) {
		// Sanitize the API key parameter early
		$api_key = sanitize_text_field($api_key);

		// Get DFinSell settings
		$dfin_sell_settings = get_option('woocommerce_dfinsell_payment_gateway_accounts');
		$dfin_settings = get_option('woocommerce_dfinsell_settings');

		if (!$dfin_sell_settings || empty($dfin_sell_settings)) {
			return false; // No accounts available
		}

		$accounts = $dfin_sell_settings;

		$sandbox = isset($dfin_settings['sandbox']) && $dfin_settings['sandbox'] === 'yes';

		foreach ($accounts as $account) {
			$public_key = $sandbox ? sanitize_text_field($account['sandbox_public_key']) : sanitize_text_field($account['live_public_key']);
			
			// Use a secure hash comparison
			if (!empty($public_key) && hash_equals($public_key, $api_key)) {
				return true;
			}
		}

		return false;
	}
	public function dfinsell_handle_api_request(WP_REST_Request $request)
	{
		$parameters = $request->get_json_params();

		// Sanitize incoming data
		$api_key = isset($parameters['nonce']) ? sanitize_text_field($parameters['nonce']) : '';
		$order_id = isset($parameters['order_id']) ? intval($parameters['order_id']) : 0;
		$api_order_status = isset($parameters['order_status']) ? sanitize_text_field($parameters['order_status']) : '';

		// Log incoming request with sanitized parameters
		$this->logger->info('DFin Sell API Request Received: ' . wp_json_encode($parameters, true), array('source' => 'dfinsell-payment-gateway'));

		// Verify API key
		if (!$this->dfinsell_verify_api_key(base64_decode($api_key))) {
			$this->logger->error('Unauthorized access attempt.', array('source' => 'dfinsell-payment-gateway'));
			return new WP_REST_Response(['error' => 'Unauthorized'], 401);
		}

		if ($order_id <= 0) {
			$this->logger->error('Invalid order ID.', array('source' => 'dfinsell-payment-gateway'));
			return new WP_REST_Response(['error' => 'Invalid data'], 400);
		}

		$order = wc_get_order($order_id);
		if (!$order) {
			$this->logger->error('Order not found: ' . $order_id, array('source' => 'dfinsell-payment-gateway'));
			return new WP_REST_Response(['error' => 'Order not found'], 404);
		}

		$pay_id = isset($parameters['pay_id']) ? sanitize_text_field($parameters['pay_id']) : '';
		//Get uuid from WP
		$uu_pay_id = $order->get_meta('_dfinsell_pay_id');

		if ($uu_pay_id != $pay_id) {
			$this->logger->error('Pay ID mismatch: ' . $pay_id, array('source' => 'dfinsell-payment-gateway'));
			return new WP_REST_Response(['error' => 'Pay ID mismatch'], 400);
		}

		if ($api_order_status == 'completed' && in_array($order->get_status(), ['pending', 'failed'])) {
			// Get the configured order status from the payment gateway settings
			$gateway_id = 'dfinsell';
			$payment_gateways = WC()->payment_gateways->payment_gateways();
			if (isset($payment_gateways[$gateway_id])) {
				$gateway = $payment_gateways[$gateway_id];
				$order_status = sanitize_text_field($gateway->get_option('order_status', 'processing'));
			} else {
				$this->logger->error('Payment gateway not found.', array('source' => 'dfinsell-payment-gateway'));
				return new WP_REST_Response(['error' => 'Payment gateway not found'], 500);
			}

			// Validate the order status against allowed statuses
			$allowed_statuses = wc_get_order_statuses();
			if (!array_key_exists('wc-' . esc_html($order_status), $allowed_statuses)) {
				$this->logger->error('Invalid order status: ' . esc_html($order_status), array('source' => 'dfinsell-payment-gateway'));
				return new WP_REST_Response(['error' => 'Invalid order status'], 400);
			}
		} else {
			$order_status = $order->get_status();
		}

		$updated = $order->update_status($order_status, __('Order status updated via API', 'dfinsell-payment-gateway'));

		if (WC()->cart) {
			// Remove cart
			WC()->cart->empty_cart();
		}

		if ($updated) {
			$payment_return_url = esc_url($order->get_checkout_order_received_url());
			$this->logger->info('Order status updated successfully: ' . esc_html($order_id), array('source' => 'dfinsell-payment-gateway'));
			return new WP_REST_Response(['success' => true, 'message' => 'Order status updated successfully', 'payment_return_url' => $payment_return_url], 200);
		} else {
			$this->logger->error('Failed to update order status: ' . esc_html($order_id), array('source' => 'dfinsell-payment-gateway'));
			return new WP_REST_Response(['error' => 'Failed to update order status'], 500);
		}
	}


	
}

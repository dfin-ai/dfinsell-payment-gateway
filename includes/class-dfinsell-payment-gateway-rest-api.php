<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once plugin_dir_path(__FILE__) . 'class-dfinsell-payment-state-engine.php';

class DFINSELL_PAYMENT_GATEWAY_REST_API
{
	private $logger;
	private static $instance = null;
	private $gateway_id;

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
		$this->gateway_id = defined('DFINSELL_PLUGIN_ID') ? DFINSELL_PLUGIN_ID : 'dfinsell';

		add_action('rest_api_init', function () {
			// Remove WordPress's default CORS headers
			remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');

			// Add custom CORS headers
			add_filter('rest_pre_serve_request', function ($value) {

				header('Access-Control-Allow-Origin: ' . (defined('DFINSELL_BASE_URL') ? DFINSELL_BASE_URL : '*'));
				header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
				header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, User-Agent, Accept');
				header('Access-Control-Allow-Credentials: true');

				// Safely get the request method
				$request_method = filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
				$request_method = $request_method ? strtoupper($request_method) : '';

				// Handle preflight request
				if ($request_method === 'OPTIONS') {
					status_header(200);
					exit;
				}

				return $value;
			}, 15);
		});
	}

	public function dfinsell_register_routes()
	{
		add_action('rest_api_init', function () {
			register_rest_route('dfinsell/v1', '/data', array(
				'methods'  => ['GET', 'POST'],
				'callback' => array($this, 'dfinsell_handle_api_request'),
				'permission_callback' => '__return_true',
			));
		});
	}

	private function dfinsell_verify_api_key($api_key)
	{
		$api_key = sanitize_text_field(trim($api_key));

		$accounts_data = get_option('woocommerce_dfinsell_payment_gateway_accounts');
		$general_settings = get_option('woocommerce_dfinsell_settings');

		if (empty($accounts_data)) {
			if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
				DFINSELL_Payment_Gateway_Logger::warning('No account data found', ['source' => 'dfinsell-payment-gateway']);
			}
			return false;
		}

		if (is_string($accounts_data)) {
			$accounts_data = maybe_unserialize($accounts_data);
		}

		// Wrap single account into array for consistency
		if (isset($accounts_data['live_public_key']) || isset($accounts_data['sandbox_public_key'])) {
			$accounts_data = [ $accounts_data ];
		}

		$sandbox = isset($general_settings['sandbox']) && $general_settings['sandbox'] === 'yes';

		foreach ($accounts_data as $account_id => $account) {
			if (!is_array($account)) {
				continue;
			}

			$public_key = $sandbox
				? sanitize_text_field($account['sandbox_public_key'] ?? '')
				: sanitize_text_field($account['live_public_key'] ?? '');

			if (!empty($public_key) && hash_equals($public_key, $api_key)) {
				if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
					DFINSELL_Payment_Gateway_Logger::info('Keys matched successfully', [
						'source'     => 'dfinsell-payment-gateway',
						'account_id' => $account_id,
					]);
				}
				return true;
			}
		}

		return false;
	}

	/**
	 * Handles incoming DFin Sell API requests to update order status.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response The response object.
	 */
	public function dfinsell_handle_api_request(WP_REST_Request $request)
	{
		$method      = $request->get_method();
		$params      = $request->get_params();
		$log_context = ['source' => 'dfinsell-payment-gateway'];

		$data = isset($params['api_data']) ? $params['api_data'] : $params;

		$order_id         = intval($data['order_id'] ?? 0);
		$api_order_status = sanitize_text_field($data['order_status'] ?? '');
		$pay_id           = sanitize_text_field($data['pay_id'] ?? '');
		$api_key_raw      = $data['nonce'] ?? '';

		if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
			DFINSELL_Payment_Gateway_Logger::info(
				"DFin Sell API HIT | Order #{$order_id} | Status: {$api_order_status} | Pay ID: {$pay_id}",
				$log_context
			);
		}

		// -------------------------
		// 1. VALIDATION
		// -------------------------
		if ($order_id <= 0) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Invalid ID'
			], 400);
		}

		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Order not found'
			], 404);
		}

		// -------------------------
		// 2. SECURITY CHECK (POST ONLY)
		// -------------------------
		if ($method === 'POST') {
			$decoded_nonce = base64_decode($api_key_raw);

			if (
				empty($api_key_raw) ||
				(!$this->dfinsell_verify_api_key($decoded_nonce) && !$this->dfinsell_verify_api_key($api_key_raw))
			) {
				return new WP_REST_Response([
					'success'    => false,
					'error_code' => 'INVALID_API_KEY'
				], 401);
			}
		}

		// -------------------------
		// 2b. PAYMENT LINK RECORD
		// -------------------------
		/*
		 * Checkout creates no payment link. The voucher flow creates the real
		 * one when the customer opens the button in their email, and this report
		 * - the return to the store, or the webhook behind it - is where the
		 * store is first told which payment link the order has.
		 */
		if (
			!empty($pay_id) &&
			$order->get_payment_method() === 'dfinsell' &&
			function_exists('dfinsell_store_order_payment_link')
		) {
			dfinsell_store_order_payment_link($order, [
				'uuid'           => $pay_id,
				'payment_link'   => $data['payment_link'] ?? '',
				'customer_email' => $data['customer_email'] ?? '',
				'amount'         => $data['amount'] ?? '',
			]);
		}

		// If it's only a store_payment_link action notification without an order status update:
		if (($data['action'] ?? '') === 'store_payment_link' && empty($api_order_status)) {
			return new WP_REST_Response([
				'success' => true,
				'message' => 'Payment link stored successfully'
			], 200);
		}

		// -------------------------
		// 3. EVENT TYPE
		// -------------------------
		$event_type = ($method === 'POST')
			? 'webhook_update'
			: 'redirect';

		$event_source = ($method === 'POST')
			? 'Webhook'
			: 'Redirect';

		// -------------------------
		// 4. ENGINE CALL
		// -------------------------
		if (!empty($api_order_status)) {
			$result = DFINSELL_Payment_State_Engine::handle_event(
				$order_id,
				$event_type,
				[
					'status'        => $api_order_status,
					'payment_token' => $pay_id,
					'source'        => $event_source
				]
			);

			if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
				DFINSELL_Payment_Gateway_Logger::info(
					"DFin Sell ENGINE RESULT | Order #{$order_id} | " . json_encode($result),
					$log_context
				);
			}
		}

		// -------------------------
		// 5. REFRESH ORDER
		// -------------------------
		$order = wc_get_order($order_id);

		$state = DFINSELL_Payment_State_Engine::resolve_final_state(
			$order,
			$api_order_status
		);

		$wc_status = $order->get_status();

		// -------------------------
		// 6. SUCCESS OVERRIDE
		// -------------------------
		if ($wc_status === 'processing' || $wc_status === 'completed') {
			$state = 'success';
		}

		$is_success = ($state === 'success');

		// -------------------------
		// 7. MESSAGE
		// -------------------------
		$message = match ($state) {
			'success' =>
				'Payment confirmed successfully.',
			'failed' =>
				'Payment failed. Please try again.',
			'cancelled' =>
				'Payment was cancelled.',
			'processing' =>
				'Payment is being processed.',
			'pending' =>
				'Payment is pending.',
			default =>
				'Payment status is being verified.'
		};

		// -------------------------
		// 8. REDIRECT (ENGINE ONLY)
		// -------------------------
		$redirect = null;

		if ($state === 'success') {
			$redirect = $order->get_checkout_order_received_url();
		} elseif (in_array($state, ['failed', 'cancelled'], true)) {
			$redirect = wc_get_checkout_url();
		}

		if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
			DFINSELL_Payment_Gateway_Logger::info(
				"DFin Sell FINAL RESPONSE | Order #{$order_id} | State: {$state} | WC: {$wc_status} | Redirect: {$redirect}",
				$log_context
			);
		}

		// -------------------------
		// 9. RESPONSE
		// -------------------------
		return $this->dfinsell_finalize_response(
			$method,
			$order,
			$is_success,
			$message,
			$wc_status,
			$redirect
		);
	}

	/**
	 * HELPER: Handles API responses and Safari-safe redirects
	 */
	private function dfinsell_finalize_response($method, $order, $success, $message, $target_status = '', $redirect_url = '') 
	{
		// If it's a server-to-server Webhook, return JSON
		if ($method === 'POST') {
			return new WP_REST_Response([
				'success' => $success,
				'message' => $message,
				'order_status' => $order ? $order->get_status() : $target_status,
				'payment_return_url' => $redirect_url ?: ($order ? $order->get_checkout_order_received_url() : '')
			], 200);
		}

		// --- SAFARI/SESSION FIX ---
		if (isset(WC()->session) && !empty($order)) {
			WC()->session->set('order_awaiting_payment', $order->get_id());
		}

		if (in_array($target_status, ['failed', 'cancelled'])) {
			wc_add_notice('Payment was not completed. Please try again.', 'error');
			wp_safe_redirect(wc_get_checkout_url());
		} else {
			wp_safe_redirect($redirect_url ?: $order->get_checkout_order_received_url());
		}
		exit;
	}
}

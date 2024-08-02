<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

class WC_Gateway_DFinSell_REST_API
{
  public function register_routes()
  {
    add_action('rest_api_init', function () {
      register_rest_route('dfinsell/v1', '/data', [
        'methods' => 'POST',
        'callback' => [$this, 'handle_dfinsell_api_request'],
        'permission_callback' => '__return_true',
      ]);
    });
  }

  private function verify_api_key($api_key)
  {
    // Get DFinSell public key from WooCommerce settings
    $dfin_sell_settings = get_option('woocommerce_dfinsell_settings');
    $public_key = isset($dfin_sell_settings['public_key']) ? $dfin_sell_settings['public_key'] : '';

    // Verify the API key
    if (!empty($public_key) && $public_key === $api_key) {
      return true;
    }
    return false;
  }

  public function handle_dfinsell_api_request($request)
  {
    $parameters = $request->get_json_params();
    $api_key = $parameters['nonce'] ?? '';

    // Verify API key
    if (!$this->verify_api_key(base64_decode($api_key))) {
      return new WP_REST_Response(['error' => 'Unauthorized'], 401);
    }

    $order_id = isset($parameters['order_id']) ? intval($parameters['order_id']) : 0;
    $api_order_status = isset($parameters['order_status']) ? sanitize_text_field($parameters['order_status']) : '';

    if ($order_id <= 0) {
      return new WP_REST_Response(['error' => 'Invalid data'], 400);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
      return new WP_REST_Response(['error' => 'Order not found'], 404);
    }

    if ($api_order_status == 'completed') { // check this
      // Get the configured order status from the payment gateway settings
      $gateway_id = 'dfinsell'; // Replace with your gateway ID
      $payment_gateways = WC()->payment_gateways->payment_gateways();
      if (isset($payment_gateways[$gateway_id])) {
        $gateway = $payment_gateways[$gateway_id];
        $order_status = $gateway->get_option('order_status', 'processing'); // Default to 'processing' if not set
      } else {
        return new WP_REST_Response(['error' => 'Payment gateway not found'], 500);
      }

      // Validate the order status against allowed statuses
      $allowed_statuses = wc_get_order_statuses();
      if (!array_key_exists('wc-' . $order_status, $allowed_statuses)) {
        return new WP_REST_Response(['error' => 'Invalid order status'], 400);
      }
    } else {
      $order_status = $api_order_status;
    }

    $updated = $order->update_status($order_status, __('Order status updated via API', 'woocommerce'));

    if (WC()->cart) {
      // Remove cart
      WC()->cart->empty_cart();
    }

    if ($updated) {
      $payment_return_url = $order->get_checkout_order_received_url();
      return new WP_REST_Response(['success' => true, 'message' => 'Order status updated successfully', 'payment_return_url' => $payment_return_url], 200);
    } else {
      return new WP_REST_Response(['error' => 'Failed to update order status'], 500);
    }
  }
}

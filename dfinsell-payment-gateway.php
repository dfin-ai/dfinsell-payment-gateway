<?php

/**
 * Plugin Name: DFin Sell Payment Gateway
 * Description: This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.
 * Author: DFin Sell
 * Author URI: https://www.dfin.ai/
 * Text Domain: dfinsell-payment-gateway
 * Plugin URI: https://github.com/dfin-ai/dfinsell-payment-gateway
 * Version: 1.1.4 (Beta)
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright (c) 2024 DFin
 */

if (!defined('ABSPATH')) {
	exit;
}

define('DFINSELL_PAYMENT_GATEWAY_MIN_PHP_VER', '8.0');
define('DFINSELL_PAYMENT_GATEWAY_MIN_WC_VER', '6.5.4');
define('DFINSELL_PAYMENT_GATEWAY_FILE', __FILE__);
define('DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Include utility functions
require_once DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/dfinsell-payment-gateway-utils.php';

// Migrations functions
include_once plugin_dir_path(__FILE__) . 'migration.php';

// Autoload classes
spl_autoload_register(function ($class) {
	if (strpos($class, 'DFINSELL_PAYMENT_GATEWAY_') === 0) {
		$class_file = DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-' . str_replace('_', '-', strtolower($class)) . '.php';
		if (file_exists($class_file)) {
			require_once $class_file;
		}
	}
});

DFINSELL_PAYMENT_GATEWAY_Loader::get_instance();

add_action('woocommerce_cancel_unpaid_order', 'cancel_unpaid_order_action');

function cancel_unpaid_order_action($order_id) {
	global $wpdb;
    if (!$order_id) {
        error_log('Error: Order ID is missing.');
        return;
    }

	error_log('WooCommerce hook triggered with Order ID: ' . print_r($order_id, true));
	$order = wc_get_order($order_id);
	if (!$order_id || !is_numeric($order_id)) {
        $order_id = $wpdb->get_var("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'shop_order_placehold'
            ORDER BY ID DESC
            LIMIT 1
        ");
        error_log('Auto-fetched latest unpaid order ID: ' . $order_id);
    }
	$order = wc_get_order($order_id);
	if (!$order) {
		error_log('Error: No unpaid orders found.');
        return;
    }
	else
	{
		//$pending_time = $order->get_meta('_pending_order_time');
		$pending_time = get_post_meta($order_id, '_pending_order_time', true);
   		$pending_time = is_numeric($pending_time) ? (int) $pending_time : 0; // Ensure it's an integer
		// Check if the order is still unpaid and the timeout has passed
		if ($order->has_status('pending') && (time() - $pending_time) >= (30 * 60)) {
			$order->update_status('cancelled', 'Order automatically cancelled due to unpaid timeout.');
			//$order->reduce_order_stock(); // Release the stock
			wc_reduce_stock_levels($order_id);
		}
		// ========================== start code for payment link ==========================
		//  Get latest payment URL for this order from custom table
		$table_name = $wpdb->prefix . 'order_payment_link';

		$latest_uuid = $wpdb->get_row($wpdb->prepare(
			"SELECT uuid FROM $table_name WHERE order_id = %d ORDER BY id DESC ",
			$order_id
		));

		if (!$latest_uuid) {
			error_log("DFinSell: No uuid found for order ID $order_id.");
			return;
		}
		$uuid = $latest_uuid->uuid;

		error_log("DFinSell: Found latest uuid for order $order_id: $uuid");

		// Call cancel API before inserting new
		$apiPath = '/api/cancel-order-link';

		// Concatenate the base URL and path
		$url = SIP_PROTOCOL . SIP_HOST . $apiPath;

		// Remove any double slashes in the URL except for the 'http://' or 'https://'
		$cleanUrl = esc_url(preg_replace('#(?<!:)//+#', '/', $url));
		$response = wp_remote_post($cleanUrl, array(
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => json_encode(array(
				'order_id'       => $order_id,
				'order_uuid'  => $uuid,
				'status'         => 'canceled'
			)),
			'headers'   => array(
				'Content-Type'  => 'application/json',
			),
			'sslverify' => true, // Ensure SSL verification
		));


		//Log API response for debugging
		if (is_wp_error($response)) {
			error_log(' Cancel API Error: ' . $response->get_error_message());
		} else {
			error_log(' Cancel API Response: ' . print_r(wp_remote_retrieve_body($response), true));;
		}
		// ==============/ end code for payment login / =============================

	}
}

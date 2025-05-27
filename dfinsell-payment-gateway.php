<?php

/**
 * Plugin Name: DFin Sell Payment Gateway
 * Description: This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.
 * Author: DFin Sell
 * Author URI: https://www.dfin.ai/
 * Text Domain: dfinsell-payment-gateway
 * Plugin URI: https://github.com/dfin-ai/dfinsell-payment-gateway
 * Version: 1.1.5-Beta
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

// Add actions for order cancellation
add_action('woocommerce_cancel_unpaid_order', 'cancel_unpaid_order_action'); // auto
add_action('woocommerce_order_status_cancelled', 'cancel_unpaid_order_action', 20, 2); // manually by admin

function cancel_unpaid_order_action($order_id)
{
	global $wpdb;
	if (!$order_id) {
		error_log('Error: Order ID is missing.');
		return;
	}

	$current_hook = current_filter(); // <-- Detect which hook triggered this
	error_log("Triggering hook : " . $current_hook); // Log it

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
	} else {
		//$pending_time = $order->get_meta('_pending_order_time');
		$pending_time = get_post_meta($order_id, '_pending_order_time', true);
		$pending_time = is_numeric($pending_time) ? (int) $pending_time : 0; // Ensure it's an integer

		// ========================== start code for expiring payment link ==========================
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
		$encoded_uuid_from_db = $latest_uuid->uuid;
		error_log("DFinSell: Found latest encoded uuid for order $order_id: $encoded_uuid_from_db");

		if ($current_hook == 'woocommerce_cancel_unpaid_order') {
			$status = 'expired';
		} else {
			$status = 'canceled';
		}

		// get data form laravel for chehck link status 
		$apiPath = '/api/check-payment-status';

		// Concatenate the base URL and path
		$url = SIP_PROTOCOL . SIP_HOST . $apiPath;

		// Remove any double slashes in the URL except for the 'http://' or 'https://'
		$cleanUrl = esc_url(preg_replace('#(?<!:)//+#', '/', $url));
		$response = wp_remote_post($cleanUrl, array(
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => json_encode(array(
				'uuid'       => $encoded_uuid_from_db
			)),
			'headers'   => array(
				'Content-Type'  => 'application/json',
			),
			'sslverify' => true, // Ensure SSL verification
		));

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true); // Convert JSON to associative array


		//Log API response for debugging
		if (is_wp_error($response)) {
			error_log('check-payment-status - API Error: ' . $response->get_error_message());
		} else {
			error_log('check-payment-status - API Response: ' . print_r(wp_remote_retrieve_body($response), true));;
		}
		// ==============/ end code for payment link expirty / =============================

		// get data form api for chehck link status 

		if ($data['status'] === 'pending') {

			error_log("DFinSell: Sending cancel API with status '$status' for order $order_id");

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
					'order_uuid'  => $encoded_uuid_from_db,
					'status'         => $status
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
			// ==============/ end code for payment link expirty / =============================
		}


		// Check if the order is still unpaid and the timeout has passed
		if ($order->has_status('pending') && (time() - $pending_time) >= (30 * 60)) {
			$order->update_status('cancelled', 'Order automatically cancelled due to unpaid timeout.');
			//$order->reduce_order_stock(); // Release the stock
			wc_reduce_stock_levels($order_id);
		}
	}
}

// Add action to handle order cancellation
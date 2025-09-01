<?php
/**
 * Plugin Name: DFin Sell Payment Gateway
 * Description: This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.
 * Author: DFin Sell
 * Author URI: https://www.dfin.ai/
 * Text Domain: dfinsell-payment-gateway
 * Plugin URI: https://github.com/dfin-ai/dfinsell-payment-gateway
 * Version: 1.1.5
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright (c) 2024 DFin
 */

if (!defined('ABSPATH')) {
	exit;
}

$config = require __DIR__ . '/config.php';

// Define as global (only once, avoid redeclaring)
$GLOBALS['dfinsell_config'] = $config;

/**
 * ==========================================================
 * 🔑 Plugin Constants
 * ==========================================================
 */
// Core plugin info
define('DFINSELL_PLUGIN_ID', $config['id']);
define('DFINSELL_PLUGIN_NAME', $config['name']);
define('DFINSELL_PLUGIN_VERSION', $config['version']);

// URLs & Paths
define('DFINSELL_PLUGIN_HOST', $config['host']);
define('DFINSELL_PROTOCOL', $config['protocol']);
define('DFINSELL_BASE_URL', DFINSELL_PROTOCOL . DFINSELL_PLUGIN_HOST);

define('DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR', $config['paths']['dir']);
define('DFINSELL_PAYMENT_GATEWAY_FILE', $config['paths']['file']);
define('DFINSELL_ASSETS_URL', $config['paths']['assets']);

// Requirements
define('DFINSELL_PAYMENT_GATEWAY_MIN_PHP_VER', $config['requirements']['php']);
define('DFINSELL_PAYMENT_GATEWAY_MIN_WC_VER', $config['requirements']['wc']);

/**
 * ==========================================================
 * 🔧 Includes
 * ==========================================================
 */
require_once DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/dfinsell-payment-gateway-utils.php';
include_once DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR . 'migration.php';

// Autoload classes
spl_autoload_register(function ($class) {
    if (strpos($class, 'DFINSELL_PAYMENT_GATEWAY') === 0) {
        $class_file = DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-' . str_replace('_', '-', strtolower($class)) . '.php';
        if (file_exists($class_file)) {
            require_once $class_file;
        }
    }
});

// Immediately after including the loader class
add_filter(
    'plugin_action_links_' . plugin_basename(__FILE__),
    ['DFINSELL_PAYMENT_GATEWAY_Loader', 'dfinsell_plugin_action_links']
);

DFINSELL_PAYMENT_GATEWAY_Loader::get_instance();

/**
 * ==========================================================
 * 🛑 Cancel Unpaid Orders
 * ==========================================================
 */
add_action('woocommerce_cancel_unpaid_order', 'dfinsell_cancel_unpaid_order_action');
add_action('woocommerce_order_status_cancelled', 'dfinsell_cancel_unpaid_order_action');

/**
 * Cancels an unpaid order after a specified timeout.
 *
 * @param int $order_id The ID of the order to cancel.
 */
function dfinsell_cancel_unpaid_order_action($order_id)
{
	global $wpdb;

	if (empty($order_id) || !is_numeric($order_id) || $order_id <= 0) {
		return;
	}

	$order = wc_get_order($order_id);

	// Fallback: try to fetch latest placeholder if order is invalid
	if (!$order) {
		$args = [
			'post_type'      => 'shop_order_placehold',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
		];

		$placeholder_orders = get_posts($args);

		if (!empty($placeholder_orders)) {
			$order_id = $placeholder_orders[0];
			$order    = wc_get_order($order_id);

			wc_get_logger()->info('Fallback to latest unpaid placeholder order.', [
				'source'  => 'dfinsell-payment-gateway',
				'context' => ['order_id' => $order_id],
			]);
		} else {
			wc_get_logger()->error('No unpaid placeholder orders found.', [
				'source' => 'dfinsell-payment-gateway',
			]);
			return;
		}
	}

	if (!$order) {
		wc_get_logger()->error('Order not found.', [
			'source'  => 'dfinsell-payment-gateway',
			'context' => ['order_id' => $order_id],
		]);
		return;
	}

	$pending_time = get_post_meta($order_id, '_pending_order_time', true);
	$pending_time = is_numeric($pending_time) ? (int) $pending_time : 0;

	if ($order->has_status('pending')) {
		if ((time() - $pending_time) < (30 * 60)) {
			wc_get_logger()->info('Order still within pending timeout. Skipping cancel.', [
				'source'  => 'dfinsell-payment-gateway',
				'context' => ['order_id' => $order_id],
			]);
			return;
		}

		$order->update_status('cancelled', 'Order automatically cancelled due to unpaid timeout.');
		wc_reduce_stock_levels($order_id);
		wp_cache_delete('dfinsell_payment_link_uuid_' . $order_id, 'dfinsell_payment_gateway');
		wp_cache_delete('dfinsell_payment_row_' . $order_id, 'dfinsell_payment_gateway'); // Clear row cache

		wc_get_logger()->info('Order auto-cancelled due to unpaid timeout.', [
			'source'  => 'dfinsell-payment-gateway',
			'context' => ['order_id' => $order_id],
		]);
	}

	// ====== Cancel Payment Link API ======
	$table_name   = $wpdb->prefix . 'order_payment_link';
	$cache_key    = 'dfinsell_payment_row_' . $order_id;
	$cache_group  = 'dfinsell_payment_gateway';

	$payment_row = wp_cache_get($cache_key, $cache_group);

	if (false === $payment_row) {
	    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safe, built from $wpdb->prefix
	    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- direct query is safe and properly prepared
	    $payment_row = $wpdb->get_row(
	        $wpdb->prepare(
	            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is not user input
	            "SELECT * FROM `{$table_name}` WHERE `order_id` = %d LIMIT 1",
	            $order_id
	        ),
	        ARRAY_A
	    );

	    if ($payment_row) {
	        wp_cache_set($cache_key, $payment_row, $cache_group, 5 * MINUTE_IN_SECONDS);
	    }
	}

	$uuid           = sanitize_text_field($payment_row['uuid'] ?? '');
	$payment_link   = esc_url_raw($payment_row['payment_link'] ?? '');
	$customer_email = sanitize_email($payment_row['customer_email'] ?? '');
	$amount         = number_format(floatval($payment_row['amount'] ?? 0), 8, '.', '');

	if (empty($uuid)) {
		wc_get_logger()->error('Missing or invalid UUID in payment link table.', [
			'source'  => 'dfinsell-payment-gateway',
			'context' => ['order_id' => $order_id, 'uuid' => $uuid],
		]);
		return;
	}

	$apiPath  = '/api/cancel-order-link';
	$url      = DFINSELL_BASE_URL . $apiPath;
	$cleanUrl = esc_url(preg_replace('#(?<!:)//+#', '/', $url));

	$request_payload = [
		'order_id'   => $order_id,
		'order_uuid' => $uuid,
		'status'     => 'canceled',
	];

	$response = wp_remote_post($cleanUrl, [
		'method'    => 'POST',
		'timeout'   => 30,
		'body'      => json_encode($request_payload),
		'headers'   => ['Content-Type' => 'application/json'],
		'sslverify' => true,
	]);

	if (is_wp_error($response)) {
		wc_get_logger()->error("Cancel API call failed. Order ID: {$order_id}", [
			'source'  => 'dfinsell-payment-gateway',
			'context' => [
				'order_id' => $order_id,
				'uuid'     => $uuid,
				'error'    => $response->get_error_message(),
			],
		]);
	} else {
		$response_body    = wp_remote_retrieve_body($response);
		$decoded_response = json_decode($response_body, true);

		wc_get_logger()->info("Cancel API response received for Order ID: {$order_id}.", [
			'source'  => 'dfinsell-payment-gateway',
			'context' => [
				'order_id'       => $order_id,
				'uuid'           => $uuid,
				'payment_link'   => $payment_link,
				'customer_email' => $customer_email,
				'amount'         => number_format((float) $amount, 2, '.', ''),
				'response'       => $decoded_response,
			],
		]);
	}
}


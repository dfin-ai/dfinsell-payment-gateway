<?php
/**
 * Plugin Name: DFin Sell Payment Gateway
 * Description: This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.
 * Author: DFin Sell
 * Author URI: https://www.dfin.ai/
 * Text Domain: dfinsell-payment-gateway
 * Plugin URI: https://github.com/dfin-ai/dfinsell-payment-gateway
 * Version: 1.1.6
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright (c) 2024 DFin
 */

if (!defined('ABSPATH')) {
	exit;
}

$dfinsell_config = require __DIR__ . '/config.php';

// Define as global (only once, avoid redeclaring)
$GLOBALS['dfinsell_config'] = $dfinsell_config;

/**
 * ==========================================================
 * 🔑 Plugin Constants
 * ==========================================================
 */
// Core plugin info
define('DFINSELL_PLUGIN_ID', $dfinsell_config['id']);
define('DFINSELL_PLUGIN_NAME', $dfinsell_config['name']);
define('DFINSELL_PLUGIN_VERSION', $dfinsell_config['version']);

// URLs & Paths
define('DFINSELL_PLUGIN_HOST', $dfinsell_config['host']);
define('DFINSELL_PROTOCOL', $dfinsell_config['protocol']);
define('DFINSELL_BASE_URL', DFINSELL_PROTOCOL . DFINSELL_PLUGIN_HOST);

define('DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR', $dfinsell_config['paths']['dir']);
define('DFINSELL_PAYMENT_GATEWAY_FILE', $dfinsell_config['paths']['file']);
define('DFINSELL_ASSETS_URL', $dfinsell_config['paths']['assets']);

// Requirements
define('DFINSELL_PAYMENT_GATEWAY_MIN_PHP_VER', $dfinsell_config['requirements']['php']);

/**
 * ==========================================================
 * 🔧 Includes
 * ==========================================================
 */
require_once DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/dfinsell-payment-gateway-utils.php';
include_once DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR . 'migration.php';

// Migrations functions
include_once plugin_dir_path(__FILE__) . 'migration.php';

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
add_action('woocommerce_order_status_failed', 'dfinsell_cancel_unpaid_order_action');
add_action('woocommerce_order_status_changed', 'dfinsell_cancel_unpaid_order_action', 10, 4);

add_filter('woocommerce_get_checkout_order_received_url', function ($url, $order) {

	if (!$order || !is_a($order, 'WC_Order')) {
		return $url;
	}

	// Only police DFinSell orders
	if ($order->get_payment_method() !== 'dfinsell') {
		return $url;
	}

	$wc_status    = $order->get_status();
	$engine_state = $order->get_meta('_dfinsell_state');
	$success_meta = $order->get_meta('_dfinsell_payment_success');
	$voucher_sent = $order->get_meta('_dfinsell_voucher_emailed_at');

	$is_valid = in_array($wc_status, ['processing', 'completed', 'pending'], true) || $success_meta === 'yes' || $engine_state === 'success' || !empty($voucher_sent);

	if (!$is_valid) {
		return wc_get_checkout_url();
	}

	return $url;

}, 10, 2);

/**
 * Cancels an unpaid order after a specified timeout.
 *
 * For voucher orders, the payment link may not exist yet because it is
 * created only after the customer clicks the purchase button in the email.
 *
 * @param int $order_id The ID of the order to cancel.
 */
function dfinsell_cancel_unpaid_order_action($order_id, $from_status = null, $to_status = null, $order = null)
{
	global $wpdb;

	if (empty($order_id) || !is_numeric($order_id) || $order_id <= 0) {
		return;
	}

	if ($from_status instanceof WC_Order && !$order) {
		$order       = $from_status;
		$from_status = null;
	}

	$order = $order ?: wc_get_order($order_id);

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

	// Only process orders associated with DFinSell
	$is_dfinsell = ($order->get_payment_method() === 'dfinsell')
		|| !empty($order->get_meta('_dfinsell_pay_id'))
		|| !empty($order->get_meta('_dfinsell_voucher_id'))
		|| !empty($order->get_meta('_dfinsell_voucher_reference'));

	if (!$is_dfinsell) {
		return;
	}

	$current_status = $to_status ?: $order->get_status();
	$current_status = strtolower(trim((string) $current_status));
	$current_status = preg_replace('/^wc-/', '', $current_status);

	// Automatic unpaid timeout handling for pending orders triggered by cron
	$is_auto_timeout_cron = (current_filter() === 'woocommerce_cancel_unpaid_order') || (empty($to_status) && $current_status === 'pending');
	if ($is_auto_timeout_cron && $current_status === 'pending') {
		$pending_time = get_post_meta($order_id, '_pending_order_time', true);
		$pending_time = is_numeric($pending_time) ? (int) $pending_time : 0;

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
		wp_cache_delete('dfinsell_payment_row_' . $order_id, 'dfinsell_payment_gateway');

		wc_get_logger()->info('Order auto-cancelled due to unpaid timeout.', [
			'source'  => 'dfinsell-payment-gateway',
			'context' => ['order_id' => $order_id],
		]);
		$current_status = 'cancelled';
	}

	$allowed_statuses = [
		'failed',
		'cancelled',
		'canceled',
		'on-hold',
		'hold',
		'pending',
		'processing',
		'completed',
		'refunded',
		'draft',
		'checkout-draft',
	];

	if (!in_array($current_status, $allowed_statuses, true)) {
		return;
	}

	switch ($current_status) {
		case 'failed':
			$target_api_status = 'failed';
			break;
		case 'cancelled':
		case 'canceled':
			$target_api_status = 'canceled';
			break;
		case 'on-hold':
		case 'hold':
			$target_api_status = 'on-hold';
			break;
		case 'pending':
			$target_api_status = 'pending';
			break;
		case 'processing':
			$target_api_status = 'processing';
			break;
		case 'completed':
			$target_api_status = 'completed';
			break;
		case 'refunded':
			$target_api_status = 'refunded';
			break;
		case 'draft':
		case 'checkout-draft':
			$target_api_status = 'draft';
			break;
		default:
			$target_api_status = $current_status;
			break;
	}

	// ====== Sync Status to DFIN-Sell API ======
	$payment_row = null;
	if (dfinsell_order_payment_link_table_exists()) {
		$table_name   = dfinsell_get_order_payment_link_table();
		$cache_key    = 'dfinsell_payment_row_' . intval($order_id);
		$cache_group  = 'dfinsell_payment_gateway';

		$payment_row = wp_cache_get($cache_key, $cache_group);

		if (false === $payment_row) {
			$safe_table_name = esc_sql($table_name);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$payment_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM `{$safe_table_name}` WHERE `order_id` = %d LIMIT 1",
					intval($order_id)
				),
				ARRAY_A
			);

			if ($payment_row) {
				wp_cache_set($cache_key, $payment_row, $cache_group, 5 * MINUTE_IN_SECONDS);
			}
		}
	}

	$uuid           = sanitize_text_field($payment_row['uuid'] ?? '');
	$payment_link   = esc_url_raw($payment_row['payment_link'] ?? '');
	$customer_email = sanitize_email($payment_row['customer_email'] ?? ($order ? $order->get_billing_email() : ''));
	$amount         = number_format(
		(float) ($payment_row['amount'] ?? ($order ? $order->get_total() : 0)),
		8,
		'.',
		''
	);

	if (empty($uuid)) {
		$uuid = sanitize_text_field($order->get_meta('_dfinsell_pay_id'));
	}
	if (empty($uuid)) {
		$uuid = sanitize_text_field($order->get_meta('_dfinsell_voucher_id'));
	}
	if (empty($uuid)) {
		$uuid = sanitize_text_field($order->get_meta('_dfinsell_voucher_reference'));
	}
	if (empty($uuid)) {
		$uuid = (string) $order_id;
	}

	$sync_signature = $uuid . ':' . $target_api_status;
	if ($order->get_meta('_dfinsell_cancelled_link_uuid') === $sync_signature) {
		wc_get_logger()->info(
			"DFinSell payment link already synced with status {$target_api_status}. Skipping duplicate API call.",
			[
				'source'  => 'dfinsell-payment-gateway',
				'context' => [
					'order_id' => $order_id,
					'uuid'     => $uuid,
					'status'   => $target_api_status,
				],
			]
		);

		return;
	}

	$apiPath  = '/api/cancel-order-link';
	$url      = DFINSELL_BASE_URL . $apiPath;
	$cleanUrl = esc_url(preg_replace('#(?<!:)//+#', '/', $url));

	$request_payload = [
		'order_id'   => $order_id,
		'order_uuid' => $uuid,
		'status'     => $target_api_status,
		'trigger'    => $is_auto_timeout_cron ? 'auto_timeout' : 'manual_change',
	];

	$response = wp_remote_post($cleanUrl, [
		'method'    => 'POST',
		'timeout'   => 30,
		'body'      => json_encode($request_payload),
		'headers'   => ['Content-Type' => 'application/json'],
		'sslverify' => true,
	]);

	if (is_wp_error($response)) {
		wc_get_logger()->error("Cancel/fail API call failed. Order ID: {$order_id}", [
			'source'  => 'dfinsell-payment-gateway',
			'context' => [
				'order_id' => $order_id,
				'uuid'     => $uuid,
				'status'   => $target_api_status,
				'error'    => $response->get_error_message(),
			],
		]);
		return;
	}

	$response_body    = wp_remote_retrieve_body($response);
	$decoded_response = json_decode($response_body, true);

	if (!empty($decoded_response['status'])) {
		$order->update_meta_data('_dfinsell_cancelled_link_uuid', $sync_signature);
		$order->save();
	}

	wc_get_logger()->info("Cancel/fail API response received for Order ID: {$order_id}.", [
		'source'  => 'dfinsell-payment-gateway',
		'context' => [
			'order_id'       => $order_id,
			'uuid'           => $uuid,
			'status'         => $target_api_status,
			'payment_link'   => $payment_link,
			'customer_email' => $customer_email,
			'amount'         => number_format((float) $amount, 2, '.', ''),
			'response'       => $decoded_response,
		],
	]);
}


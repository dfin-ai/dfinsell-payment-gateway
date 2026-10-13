<?php
/**
 * Plugin Name: DFin Sell Payment Gateway
 * Description: Purchase and pay quickly with a voucher
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
require_once DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-dfinsell-payment-logger.php';
require_once DFINSELL_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-dfinsell-payment-state-engine.php';

// Migrations functions
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
 * 🛑 Thank You Page Protection
 * ==========================================================
 */
add_filter('woocommerce_get_checkout_order_received_url', function($url, $order) {

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
	
	if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
		DFINSELL_Payment_Gateway_Logger::info(	
			sprintf(
				"[Order #%d] ThankYou Filter | WC=%s | Engine=%s | Success=%s",
				$order->get_id(),
				$wc_status,
				$engine_state,
				$success_meta ?: 'EMPTY'
			)
		);
	}

    $is_valid = in_array($wc_status, ['processing', 'completed'], true) || $success_meta === 'yes' || $engine_state === 'success';

	if (!$is_valid) {
		if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
			DFINSELL_Payment_Gateway_Logger::info(
				sprintf(
					'[Order #%d] ThankYou Filter BLOCKED -> %s',
					$order->get_id(),
					wc_get_checkout_url()
				)
			);
		}
		return wc_get_checkout_url();
	}

   	if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
		DFINSELL_Payment_Gateway_Logger::info(
			sprintf(
				'[Order #%d] ThankYou Filter ALLOWED -> %s',
				$order->get_id(),
				$url
			)
		);
	}

	return $url;

}, 10, 2);

/**
 * ==========================================================
 * 🛑 Cancel Unpaid Orders
 * ==========================================================
 */
add_action('woocommerce_cancel_unpaid_order', 'dfinsell_cancel_unpaid_order_action');
add_action('woocommerce_order_status_cancelled', 'dfinsell_cancel_unpaid_order_action');
add_action('woocommerce_order_status_changed', 'dfinsell_cancel_unpaid_order_action', 10, 4);

/**
 * Cancels the DFinSell payment link when a DFinSell order is cancelled.
 *
 * For voucher orders, the payment link may not exist yet because it is
 * created only after the customer clicks the purchase button in the email.
 *
 * @param int $order_id The ID of the order.
 */
function dfinsell_cancel_unpaid_order_action($order_id)
{
	global $wpdb;

	if (empty($order_id) || !is_numeric($order_id) || $order_id <= 0) {
		return;
	}

	$order = wc_get_order($order_id);

	if (!$order) {
		return;
	}

	/*
	 * Only process DFinSell orders.
	 */
	if ($order->get_payment_method() !== 'dfinsell') {
		return;
	}

	/*
	 * Only process orders that are actually cancelled.
	 */
	if (!$order->has_status('cancelled')) {
		return;
	}

	if (!function_exists('dfinsell_order_payment_link_table_exists') || !dfinsell_order_payment_link_table_exists()) {
		if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
			DFINSELL_Payment_Gateway_Logger::info(
				'No payment link table present. Nothing to cancel for this DFinSell order.',
				[
					'source'  => 'dfinsell-payment-gateway',
					'context' => [
						'order_id' => $order_id,
					],
				]
			);
		}
		return;
	}

	$table_name  = dfinsell_get_order_payment_link_table();
	$cache_key   = 'dfinsell_payment_row_' . intval($order_id);
	$cache_group = 'dfinsell_payment_gateway';

	$payment_row = wp_cache_get($cache_key, $cache_group);

	if (false === $payment_row) {
		$safe_table_name = esc_sql($table_name);

		$sql = "SELECT * FROM {$safe_table_name} WHERE order_id = %d LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$payment_row = $wpdb->get_row(
			$wpdb->prepare($sql, intval($order_id)),
			ARRAY_A
		);

		if ($payment_row) {
			wp_cache_set(
				$cache_key,
				$payment_row,
				$cache_group,
				5 * MINUTE_IN_SECONDS
			);
		}
	}

	/*
	 * Voucher flow can legitimately have no payment-link row yet.
	 * The payment link is created only when the customer clicks the
	 * purchase button in the voucher email.
	 */
	if (empty($payment_row)) {
		if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
			DFINSELL_Payment_Gateway_Logger::info(
				'DFinSell order cancelled before payment link was created. No payment link to cancel.',
				[
					'source'  => 'dfinsell-payment-gateway',
					'context' => [
						'order_id' => $order_id,
					],
				]
			);
		}
		return;
	}

	$uuid           = sanitize_text_field($payment_row['uuid'] ?? '');
	$payment_link   = esc_url_raw($payment_row['payment_link'] ?? '');
	$customer_email = sanitize_email($payment_row['customer_email'] ?? '');
	$amount         = number_format(
		(float) ($payment_row['amount'] ?? 0),
		8,
		'.',
		''
	);

	if (empty($uuid)) {
		if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
			DFINSELL_Payment_Gateway_Logger::warning(
				'DFinSell payment link exists but UUID is missing. Skipping cancellation API call.',
				[
					'source'  => 'dfinsell-payment-gateway',
					'context' => [
						'order_id' => $order_id,
						'uuid'     => $uuid,
					],
				]
			);
		}
		return;
	}

	/*
	 * Prevent duplicate cancellation API calls for the same UUID.
	 */
	if ($order->get_meta('_dfinsell_cancelled_link_uuid') === $uuid) {
		if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
			DFINSELL_Payment_Gateway_Logger::info(
				'DFinSell payment link already cancelled. Skipping duplicate cancellation API call.',
				[
					'source'  => 'dfinsell-payment-gateway',
					'context' => [
						'order_id' => $order_id,
						'uuid'     => $uuid,
					],
				]
			);
		}
		return;
	}

	$apiPath  = '/api/cancel-order-link';
	$url      = DFINSELL_BASE_URL . $apiPath;
	$cleanUrl = esc_url(
		preg_replace('#(?<!:)//+#', '/', $url)
	);

	$request_payload = [
		'order_id'   => $order_id,
		'order_uuid' => $uuid,
		'status'     => 'canceled',
	];

	$response = wp_remote_post(
		$cleanUrl,
		[
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => wp_json_encode($request_payload),
			'headers'   => [
				'Content-Type' => 'application/json',
			],
			'sslverify' => true,
		]
	);

	if (is_wp_error($response)) {
		if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
			DFINSELL_Payment_Gateway_Logger::error(
				"Cancel API call failed. Order ID: {$order_id}",
				[
					'source'  => 'dfinsell-payment-gateway',
					'context' => [
						'order_id' => $order_id,
						'uuid'     => $uuid,
						'error'    => $response->get_error_message(),
					],
				]
			);
		}
		return;
	}

	$response_body    = wp_remote_retrieve_body($response);
	$decoded_response = json_decode($response_body, true);

	if (!empty($decoded_response['status'])) {
		$order->update_meta_data('_dfinsell_cancelled_link_uuid', $uuid);
		$order->save();
	}

	if (class_exists('DFINSELL_Payment_Gateway_Logger')) {
		DFINSELL_Payment_Gateway_Logger::info(
			"Cancel API response received for Order ID: {$order_id}.",
			[
				'source'  => 'dfinsell-payment-gateway',
				'context' => [
					'order_id'       => $order_id,
					'uuid'           => $uuid,
					'payment_link'   => $payment_link,
					'customer_email' => $customer_email,
					'amount'         => number_format(
						(float) $amount,
						2,
						'.',
						''
					),
					'response'       => $decoded_response,
				],
			]
		);
	}
}

<?php

/**
 * Check the environment for compatibility issues.
 *
 * @return string|false
 */
function dfinsell_check_system_requirements()
{
	if (version_compare(phpversion(), DFINSELL_PAYMENT_GATEWAY_MIN_PHP_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required PHP version, %2$s is the current PHP version
			__('The DFin Sell Payment Gateway plugin requires PHP version %1$s or greater. You are running %2$s.', 'dfinsell-payment-gateway'),
			DFINSELL_PAYMENT_GATEWAY_MIN_PHP_VER,
			phpversion()
		);
	}

	// Check if WooCommerce is available.
	$wc_plugin_version = defined('WC_VERSION') ? WC_VERSION : null;

	if (!$wc_plugin_version) {
		return __(
			'The DFin Sell Payment Gateway plugin requires WooCommerce to be installed and active.',
			'dfinsell-payment-gateway'
		);
	}

	return false;
}

/**
 * Activation check for the plugin.
 */
function dfinsell_activation_check()
{
	$environment_warning = dfinsell_check_system_requirements();
	if ($environment_warning) {
		deactivate_plugins(plugin_basename(DFINSELL_PAYMENT_GATEWAY_FILE));
		wp_die(esc_html($environment_warning)); // Escape the output before calling wp_die
	}
}

if (!function_exists('dfinsell_get_order_payment_link_table')) {

	/**
	 * Name of the payment-link table.
	 *
	 * @return string
	 */
	function dfinsell_get_order_payment_link_table()
	{
		global $wpdb;

		return $wpdb->prefix . 'order_payment_link';
	}
}

if (!function_exists('dfinsell_order_payment_link_table_exists')) {

	/**
	 * Whether the payment-link table is present.
	 *
	 * @param bool $create Create the table when it is missing.
	 * @return bool
	 */
	function dfinsell_order_payment_link_table_exists($create = false)
	{
		global $wpdb;

		$table_name  = dfinsell_get_order_payment_link_table();
		$cache_key   = 'dfinsell_table_exists_' . md5($table_name);
		$cache_group = 'dfinsell_payment_gateway';

		if (wp_cache_get($cache_key, $cache_group) === $table_name) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$table_exists = $wpdb->get_var(
			$wpdb->prepare('SHOW TABLES LIKE %s', $table_name)
		);

		if ($table_exists === $table_name) {
			wp_cache_set($cache_key, $table_name, $cache_group, HOUR_IN_SECONDS);

			return true;
		}

		if (!$create) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$create_sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			order_id BIGINT UNSIGNED NOT NULL,
			uuid VARCHAR(100) NOT NULL,
			payment_link TEXT NOT NULL,
			customer_email VARCHAR(191),
			amount DECIMAL(18,2),
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP
		) $charset_collate;";

		dbDelta($create_sql);

		wp_cache_set($cache_key, $table_name, $cache_group, HOUR_IN_SECONDS);

		wc_get_logger()->info(
			"Created missing `{$table_name}` table.",
			[
				'source'  => 'dfinsell-payment-gateway',
				'context' => ['table' => $table_name],
			]
		);

		return true;
	}
}

if (!function_exists('dfinsell_store_order_payment_link')) {

	/**
	 * Record the payment link the application created for a DFinSell order.
	 *
	 * @param WC_Order $order Order the payment belongs to.
	 * @param array    $data  Payment fields: uuid, payment_link, customer_email, amount.
	 * @return bool Whether the order now has a payment-link row.
	 */
	function dfinsell_store_order_payment_link($order, $data = [])
	{
		global $wpdb;

		if (!$order instanceof WC_Order) {
			return false;
		}

		$order_id = (int) $order->get_id();
		$uuid     = sanitize_text_field(is_scalar($data['uuid'] ?? null) ? (string) $data['uuid'] : '');

		if ($order_id <= 0 || empty($uuid)) {
			return false;
		}

		if (!dfinsell_order_payment_link_table_exists(true)) {
			wc_get_logger()->error(
				'Payment link table missing and could not be created. Payment link not stored.',
				[
					'source'  => 'dfinsell-payment-gateway',
					'context' => ['order_id' => $order_id],
				]
			);

			return false;
		}

		$table_name      = dfinsell_get_order_payment_link_table();
		$safe_table_name = esc_sql($table_name);

		$payment_link = esc_url_raw(
			is_scalar($data['payment_link'] ?? null) ? (string) $data['payment_link'] : ''
		);

		$customer_email = sanitize_email(
			is_scalar($data['customer_email'] ?? null) ? (string) $data['customer_email'] : ''
		);

		if (empty($customer_email)) {
			$customer_email = sanitize_email($order->get_billing_email());
		}

		$amount = isset($data['amount']) && is_numeric($data['amount'])
			? (float) $data['amount']
			: (float) $order->get_total();

		$amount = number_format($amount, 2, '.', '');

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, uuid, payment_link FROM {$safe_table_name} WHERE order_id = %d LIMIT 1",
				$order_id
			),
			ARRAY_A
		);

		$row = [
			'uuid'           => $uuid,
			'payment_link'   => $payment_link,
			'customer_email' => $customer_email,
			'amount'         => $amount,
			'created_at'     => current_time('mysql', 1),
		];

		if (!empty($existing)) {
			if (
				$existing['uuid'] === $uuid &&
				(empty($payment_link) || $existing['payment_link'] === $payment_link)
			) {
				return true;
			}

			if (empty($payment_link)) {
				unset($row['payment_link']);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$written = $wpdb->update(
				$table_name,
				$row,
				['order_id' => $order_id],
				array_fill(0, count($row), '%s'),
				['%d']
			);

		} else {
			$row['order_id'] = $order_id;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$written = $wpdb->insert(
				$table_name,
				$row,
				['%s', '%s', '%s', '%s', '%s', '%d']
			);
		}

		if (false === $written) {
			wc_get_logger()->error(
				'Failed to store DFinSell order payment link.',
				[
					'source'  => 'dfinsell-payment-gateway',
					'context' => [
						'order_id' => $order_id,
						'uuid'     => $uuid,
						'error'    => $wpdb->last_error,
					],
				]
			);

			return false;
		}

		wp_cache_delete('dfinsell_payment_row_' . $order_id, 'dfinsell_payment_gateway');

		wc_get_logger()->info(
			'Stored DFinSell order payment link.',
			[
				'source'  => 'dfinsell-payment-gateway',
				'context' => [
					'order_id'     => $order_id,
					'uuid'         => $uuid,
					'payment_link' => $payment_link,
					'amount'       => $amount,
				],
			]
		);

		return true;
	}
}
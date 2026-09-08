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

	/*
	 * 'woocommerce_db_version' is deliberately NOT consulted here.
	 *
	 * WooCommerce keeps that option at the last release which actually shipped
	 * a database migration - WC_Install::update_db_version() stores
	 * array_key_last( self::$db_updates ) - rather than the running version. On
	 * WooCommerce 11.1.0 the option therefore reads '11.1.0-1', and any release
	 * without migrations leaves it further behind still. Comparing it against
	 * WC_VERSION reported a mismatch on entirely healthy sites.
	 *
	 * That was not cosmetic: a returned message makes dfinsell_activation_check()
	 * call wp_die(), and makes dfinsell_init() return before registering the
	 * gateway - so DFin Sell silently disappeared from the checkout.
	 *
	 * A genuinely pending database update is WooCommerce's own business and it
	 * raises its own notice for it. WC_VERSION is the authoritative signal for
	 * whether this plugin can run.
	 */

	// Check if WooCommerce is available.
	$wc_plugin_version = defined('WC_VERSION') ? WC_VERSION : null;

	if (!$wc_plugin_version) {
		return __(
			'The DFin Sell Payment Gateway plugin requires WooCommerce to be installed and active.',
			'dfinsell-payment-gateway'
		);
	}

	/*
	 * No WooCommerce version floor is enforced.
	 *
	 * The plugin only uses long-standing WooCommerce APIs - wc_get_order(),
	 * wc_add_notice(), wc_get_logger() and the CRUD meta methods, all present
	 * since WooCommerce 3.0 - and everything newer is feature-detected rather
	 * than version-gated: dfinsell_init_gateways() checks for
	 * WC_Payment_Gateway and dfinsell_init_blocks() checks for
	 * AbstractPaymentMethodType before loading either integration. An older
	 * WooCommerce therefore degrades quietly instead of failing.
	 *
	 * Version gates in this file have twice disabled the gateway on healthy
	 * sites, so the only precondition kept is the one that is actually
	 * required: WooCommerce has to be there.
	 */

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
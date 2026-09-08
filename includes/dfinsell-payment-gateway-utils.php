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
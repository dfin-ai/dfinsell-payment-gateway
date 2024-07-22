<?php

/**
 * Check the environment for compatibility issues.
 *
 * @return string|false
 */
function wc_gateway_dfin_sell_check_environment()
{
  if (version_compare(phpversion(), WC_DFIN_SELL_MIN_PHP_VER, '<')) {
    return sprintf(
      // translators: %1$s is the minimum required PHP version, %2$s is the current PHP version
      __('The DFin Sell Payment Gateway plugin requires PHP version %1$s or greater. You are running %2$s.', 'dfin-sell-payment-gateway'),
      WC_DFIN_SELL_MIN_PHP_VER,
      phpversion()
    );
  }

  $wc_version = get_option('woocommerce_db_version');

  if (!$wc_version || version_compare($wc_version, WC_DFIN_SELL_MIN_WC_VER, '<')) {
    return sprintf(
      // translators: %1$s is the minimum required WooCommerce version, %2$s is the current WooCommerce version (or "undefined" if not available)
      __('The DFin Sell Payment Gateway plugin requires WooCommerce version %1$s or greater. You are running %2$s.', 'dfin-sell-payment-gateway'),
      WC_DFIN_SELL_MIN_WC_VER,
      $wc_version ? $wc_version : __('undefined', 'dfin-sell-payment-gateway')
    );
  }

  return false;
}

/**
 * Activation check for the plugin.
 */
function wc_gateway_dfin_sell_activation_check()
{
  $environment_warning = wc_gateway_dfin_sell_check_environment();
  if ($environment_warning) {
    deactivate_plugins(plugin_basename(WC_DFIN_SELL_FILE));
    wp_die(esc_html($environment_warning)); // Escape the output before calling wp_die
  }
}

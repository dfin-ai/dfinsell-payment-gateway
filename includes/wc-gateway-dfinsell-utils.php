<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

/**
 * Check the environment for compatibility issues.
 *
 * @return string|false
 */
function wc_gateway_dfin_sell_check_environment()
{
  if (version_compare(phpversion(), WC_DFIN_SELL_MIN_PHP_VER, '<')) {
    return sprintf(
      __('The DFin Sell Payment Gateway plugin requires PHP version %1$s or greater. You are running %2$s.', 'dfin-sell-payment-gateway'),
      WC_DFIN_SELL_MIN_PHP_VER,
      phpversion()
    );
  }

  if (version_compare(WC_VERSION, WC_DFIN_SELL_MIN_WC_VER, '<')) {
    return sprintf(
      __('The DFin Sell Payment Gateway plugin requires WooCommerce version %1$s or greater. You are running %2$s.', 'dfin-sell-payment-gateway'),
      WC_DFIN_SELL_MIN_WC_VER,
      WC_VERSION
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
    wp_die($environment_warning);
  }
}

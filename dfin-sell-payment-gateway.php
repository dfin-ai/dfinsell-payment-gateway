<?php

/**
 * Plugin Name: DFin Sell Payment Gateway
 * Description: This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.
 * Author: DFin Sell
 * Author URI: https://www.dfin.ai/
 * Text Domain: dfin-sell-payment-gateway
 * Plugin URI: https://github.com/dfin-ai/dfin-sell-payment-gateway
 * Version: 1.0.3
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright (c) 2024 DFin
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WC_DFIN_SELL_MIN_PHP_VER', '8.0');
define('WC_DFIN_SELL_MIN_WC_VER', '6.5.4');
define('WC_DFIN_SELL_FILE', __FILE__);
define('WC_DFIN_SELL_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Include utility functions
require_once WC_DFIN_SELL_PLUGIN_DIR . 'includes/wc-gateway-dfinsell-utils.php';

// Autoload classes
spl_autoload_register(function ($class) {
    if (strpos($class, 'WC_Gateway_DFinSell_') === 0) {
        $class_file = WC_DFIN_SELL_PLUGIN_DIR . 'includes/class-' . str_replace('_', '-', strtolower($class)) . '.php';
        if (file_exists($class_file)) {
            require_once $class_file;
        }
    }
});

WC_Gateway_DFinSell_Loader::get_instance();

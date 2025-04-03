<?php

/**
 * Plugin Name: DFin Sell Payment Gateway
 * Description: This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.
 * Author: DFin Sell
 * Author URI: https://www.dfin.ai/
 * Text Domain: dfinsell-payment-gateway
 * Plugin URI: https://github.com/dfin-ai/dfinsell-payment-gateway
 * Version: 1.1.1 (Beta)
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

<?php
// config.php
if (!defined('ABSPATH')) {
    exit;
}

return [
    'id'   => 'dfinsell',
    'name' => 'DFin Sell Payment Gateway',
    'version' => '1.1.6',
	'title' => 'Voucher Pay',
    'description' => 'Purchase and pay quickly with a voucher',
    'icon' => '',

    'protocol' => 'https://',
    'host'     => 'sell.dfin.ai',

    'requirements' => [
        'php' => '8.0',
    ],

    'paths' => [
		'file'   => __FILE__,                       // Full path to this file
        'dir'    => plugin_dir_path(__FILE__),     // Absolute dir path
        'url'    => plugin_dir_url(__FILE__),      // Base URL
        'assets' => plugin_dir_url(__FILE__) . 'assets/', // Assets URL
    ],
];

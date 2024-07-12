<?php

/**
 * Plugin Name: DFin Sell Payment Gateway
 * Description: This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.
 * Author: DFin Sell
 * Author URI: https://sell-dev.dfin.ai/
 * Text Domain: dfin-sell-payment-gateway
 * Plugin URI: https://github.com/Cooraez12/dfin-sell-wp-plugin
 * Version: 1.0.0
 *
 * Copyright (c) 2024 DFin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Required minimums
 */
define('WC_DFIN_SELL_MIN_PHP_VER', '8.0');
define('WC_DFIN_SELL_MIN_WC_VER', '6.5.4');
define('WC_DFIN_SELL_FILE', __FILE__);

/**
 * Plugin loader class
 */
class WC_Gateway_DFinSell_Loader
{

    /**
     * Singleton instance
     *
     * @var WC_Gateway_DFinSell_Loader|null
     */
    private static $instance = null;

    /**
     * Notices array
     *
     * @var array
     */
    public $notices = [];

    /**
     * Get the singleton instance of this class
     *
     * @return WC_Gateway_DFinSell_Loader
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        add_action('admin_init', [$this, 'check_environment']);
        add_action('admin_notices', [$this, 'admin_notices'], 15);
        add_action('plugins_loaded', [$this, 'init']);
        register_activation_hook(__FILE__, [__CLASS__, 'activation_check']);
    }

    /**
     * Initialize the plugin
     */
    public function init()
    {

        define('DFPG_PLUGIN_FILE', __FILE__);
        define('DFPG_PLUGIN_BASENAME', plugin_basename(DFPG_PLUGIN_FILE));

        // Don't hook anything else in the plugin if we're in an incompatible environment
        if ($this->get_environment_warning()) {
            return;
        }

        // Init the gateway itself
        $this->init_gateways();

        // Add plugin action links and row meta
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);

        // Add REST API endpoint for custom order updates
        add_action('rest_api_init', function () {
            register_rest_route('custom-api/v1', '/data', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_custom_api_request'],
                'permission_callback' => '__return_true', // Adjust as per authentication needs
            ]);
        });
    }

    /**
     * Initialize the payment gateways
     */
    public function init_gateways()
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        include_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-dfin-sell.php';

        // Add the gateway to WooCommerce
        add_filter('woocommerce_payment_gateways', function ($gateways) {
            $gateways[] = 'WC_Gateway_DfinSell';
            return $gateways;
        });
    }

    /**
     * Handle custom API request for order updates
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_custom_api_request($request)
    {
        // Check nonce or authentication if needed for security
        // $nonce = $request->get_header('X-WP-Nonce');

        // // Verify the nonce
        // if (!wp_verify_nonce($nonce, 'dfinsell_process_payment_nonce')) {
        //     return new WP_REST_Response(['error' => 'Invalid nonce'], 403);
        // }

        // Get order ID and status from the AJAX request
        $parameters = $request->get_json_params();
        $order_id = isset($parameters['order_id']) ? intval($parameters['order_id']) : 0;
        $order_status = isset($parameters['order_status']) ? sanitize_text_field($parameters['order_status']) : '';

        // Validate order ID and status
        if ($order_id <= 0 || empty($order_status)) {
            return new WP_REST_Response(['error' => 'Invalid data'], 400);
        }

        // Update the order status
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_REST_Response(['error' => 'Order not found'], 404);
        }
        $updated = $order->update_status($order_status, __('Order status updated via API', 'woocommerce'));

        // Return response based on update success
        if ($updated) {
            $payment_return_url = $order->get_checkout_order_received_url();
            return new WP_REST_Response(['success' => true, 'message' => 'Order status updated successfully', 'payment_return_url' => $payment_return_url], 200);
        } else {
            return new WP_REST_Response(['error' => 'Failed to update order status'], 500);
        }
    }

    /**
     * Check the environment for compatibility issues
     */
    public function check_environment()
    {
        $environment_warning = $this->get_environment_warning();
        if ($environment_warning && is_plugin_active(plugin_basename(__FILE__))) {
            deactivate_plugins(plugin_basename(__FILE__));
            $this->add_admin_notice('bad_environment', 'error', $environment_warning);
            if (isset($_GET['activate'])) {
                unset($_GET['activate']);
            }
        }
    }

    /**
     * Activation check for plugin
     */
    public static function activation_check()
    {
        $environment_warning = self::get_environment_warning(true);
        if ($environment_warning) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die($environment_warning);
        }
    }

    /**
     * Get environment warning message if any
     *
     * @param bool $during_activation
     * @return string|false
     */
    public static function get_environment_warning($during_activation = false)
    {
        if (version_compare(phpversion(), WC_DFIN_SELL_MIN_PHP_VER, '<')) {
            $message = $during_activation ?
                __('The plugin could not be activated. The minimum PHP version required is %1$s. You are running %2$s.', 'woocommerce-gateway-dfin-sell') :
                __('The DFin Sell Payment plugin has been deactivated. The minimum PHP version required is %1$s. You are running %2$s.', 'woocommerce-gateway-dfin-sell');
            return sprintf($message, WC_DFIN_SELL_MIN_PHP_VER, phpversion());
        }

        if (version_compare(WC_VERSION, WC_DFIN_SELL_MIN_WC_VER, '<')) {
            $message = $during_activation ?
                __('The plugin could not be activated. The minimum WooCommerce version required is %1$s. You are running %2$s.', 'woocommerce-gateway-dfin-sell') :
                __('The DFin Sell Payment Gateway plugin has been deactivated. The minimum WooCommerce version required is %1$s. You are running %2$s.', 'woocommerce-gateway-dfin-sell');
            return sprintf($message, WC_DFIN_SELL_MIN_WC_VER, WC_VERSION);
        }

        return false;
    }

    /**
     * Add plugin action links
     *
     * @param array $links
     * @return array
     */
    public function plugin_action_links($links)
    {
        $plugin_links = [
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=dfinsell') . '">' .
                __('Settings', 'woocommerce-gateway-dfin-sell') .
                '</a>',
            '<a href="https://www.sell-dev.dfin.ai/api/docs/wordpress-plugin">' .
                __('Docs', 'woocommerce-gateway-dfin-sell') .
                '</a>',
            '<a href="https://www.sell-dev.dfin.ai/reach-out">' .
                __('Support', 'woocommerce-gateway-dfin-sell') .
                '</a>',
        ];

        return array_merge($plugin_links, $links);
    }

    /**
     * Add row meta on the plugin screen
     *
     * @param array $links
     * @param string $file
     * @return array
     */
    public function plugin_row_meta($links, $file)
    {
        if (plugin_basename(__FILE__) !== $file) {
            return $links;
        }

        $row_meta = [
            'docs' => '<a href="https://www.dfin.ai/api/docs/wordpress-plugin">' . __('Docs', 'woocommerce-gateway-dfin-sell') . '</a>',
            'support' => '<a href="https://www.dfin.ai/reach-out">' . __('Support', 'woocommerce-gateway-dfin-sell') . '</a>',
        ];

        return array_merge($links, $row_meta);
    }

    /**
     * Display admin notices
     */
    public function admin_notices()
    {
        foreach ((array)$this->notices as $notice_key => $notice) {
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr(sanitize_html_class($notice['class'])), wp_kses($notice['message'], ['a' => ['href' => []]]));
        }
    }
}

// Initialize the plugin
WC_Gateway_DFinSell_Loader::get_instance();

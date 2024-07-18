<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

/**
 * Class WC_Gateway_DFinSell_Loader
 * Handles the loading and initialization of the DFin Sell Payment Gateway plugin.
 */
class WC_Gateway_DFinSell_Loader
{
  private static $instance = null;
  private $admin_notices;

  /**
   * Get the singleton instance of this class.
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
   * Constructor. Sets up actions and hooks.
   */
  private function __construct()
  {
    $this->admin_notices = new WC_Gateway_DFinSell_Admin_Notices();

    add_action('admin_init', [$this, 'check_environment']);
    add_action('admin_notices', [$this->admin_notices, 'display_notices']);
    add_action('plugins_loaded', [$this, 'init']);

    // Register the AJAX action callback for checking payment status
    add_action('wp_ajax_check_payment_status', array($this, 'handle_check_payment_status_request'));
    add_action('wp_ajax_nopriv_check_payment_status', array($this, 'handle_check_payment_status_request'));

    register_activation_hook(WC_DFIN_SELL_FILE, 'wc_gateway_dfin_sell_activation_check');
  }

  /**
   * Initializes the plugin.
   * This method is hooked into 'plugins_loaded' action.
   */
  public function init()
  {
    // Check if the environment is compatible
    $environment_warning = wc_gateway_dfin_sell_check_environment();
    if ($environment_warning) {
      return;
    }

    // Initialize gateways
    $this->init_gateways();

    // Initialize REST API
    $rest_api = new WC_Gateway_DFinSell_REST_API();
    $rest_api->register_routes();

    // Add plugin action links
    add_filter('plugin_action_links_' . plugin_basename(WC_DFIN_SELL_FILE), [$this, 'plugin_action_links']);

    // Add plugin row meta
    add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);
  }

  /**
   * Initialize gateways.
   */
  private function init_gateways()
  {
    if (!class_exists('WC_Payment_Gateway')) {
      return;
    }

    include_once WC_DFIN_SELL_PLUGIN_DIR . 'includes/class-wc-gateway-dfinsell.php';

    add_filter('woocommerce_payment_gateways', function ($methods) {
      $methods[] = 'WC_Gateway_DFinSell';
      return $methods;
    });
  }

  /**
   * Add action links to the plugin page.
   * @param array $links
   * @return array
   */
  public function plugin_action_links($links)
  {
    $plugin_links = [
      '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=dfinsell') . '">' . __('Settings', 'dfin-sell-payment-gateway') . '</a>',
    ];

    return array_merge($plugin_links, $links);
  }

  /**
   * Add row meta to the plugin page.
   * @param array $links
   * @param string $file
   * @return array
   */
  public function plugin_row_meta($links, $file)
  {
    if (plugin_basename(WC_DFIN_SELL_FILE) === $file) {
      $row_meta = [
        'docs'    => '<a href="' . esc_url(apply_filters('wc_gateway_dfin_sell_docs_url', 'https://docs.dfin.ai')) . '" target="_blank">' . __('Documentation', 'dfin-sell-payment-gateway') . '</a>',
        'support' => '<a href="' . esc_url(apply_filters('wc_gateway_dfin_sell_support_url', 'https://support.dfin.ai')) . '" target="_blank">' . __('Support', 'dfin-sell-payment-gateway') . '</a>',
      ];

      $links = array_merge($links, $row_meta);
    }

    return $links;
  }

  /**
   * Check the environment and display notices if necessary.
   */
  public function check_environment()
  {
    $environment_warning = wc_gateway_dfin_sell_check_environment();
    if ($environment_warning) {
      $this->admin_notices->add_notice('error', 'error', $environment_warning);
    }
  }

  public function handle_check_payment_status_request($request)
  {
    // Verify nonce for security (recommended)
    check_ajax_referer('dfinsell_nonce', 'security');

    // Get the order ID from $_POST
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : null;
    if (!$order_id) {
      wp_send_json_error(array('error' => 'Invalid order ID'));
    }

    return $this->check_payment_status($order_id);
  }

  public function check_payment_status($order_id)
  {
    // Get the order details
    $order = wc_get_order($order_id);


    if (!$order) {
      return new WP_REST_Response(['error' => 'Order not found'], 404);
    }

    $payment_return_url = $order->get_checkout_order_received_url();
    // Check the payment status
    if ($order) {
      if ($order->is_paid()) {
        wp_send_json_success(['status' => 'success', 'redirect_url' => $payment_return_url]);
      } elseif ($order->has_status('failed')) {
        wp_send_json_success(['status' => 'failed', 'redirect_url' => $payment_return_url]);
      }
    }

    // Default to pending status
    wp_send_json_success(['status' => 'pending']);
  }
}

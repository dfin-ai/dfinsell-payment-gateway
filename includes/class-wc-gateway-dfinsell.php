<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Main WooCommerce DFin Sell Payment Gateway class.
 */
class WC_Gateway_DFinSell extends WC_Payment_Gateway_CC
{
    const ID = 'dfinsell';
    const MODE = 'live';

    // Define constants for SIP URLs
    const SIP_HOST_SANDBOX = 'sell-dev.dfin.ai'; // Sandbox SIP host
    const SIP_HOST_LIVE = 'sell.dfin.ai'; // Live SIP host 

    private $sip_protocol; // Protocol (http:// or https://)
    private $sip_host;     // Host without protocol

    // Declare properties here
    public $public_key;
    public $secret_key;

    /**
     * Constructor.
     */
    public function __construct()
    {
        // Check if WooCommerce is active
        if (!class_exists('WC_Payment_Gateway_CC')) {
            add_action('admin_notices', array($this, 'woocommerce_not_active_notice'));
            return;
        }

        // Determine SIP protocol based on site protocol
        $this->sip_protocol = is_ssl() ? 'https://' : 'http://';

        // Define user set variables
        $this->id = self::ID;
        $this->icon = ''; // Define an icon URL if needed.
        $this->method_title = __('DFin Sell Payment Gateway', 'dfin-sell-payment-gateway');
        $this->method_description = __('This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.', 'dfin-sell-payment-gateway');

        // Set SIP host based on mode
        $this->set_sip_host(self::MODE);

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define properties
        $this->title = $this->get_option('title');
        $this->description                = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->public_key = $this->get_option('public_key');
        $this->secret_key = $this->get_option('secret_key');

        // Define hooks and actions.
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        // Enqueue styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles_and_scripts'));
    }

    /**
     * Set the SIP host based on the mode.
     */
    private function set_sip_host($mode)
    {
        if ($mode === 'live') {
            $this->sip_host = self::SIP_HOST_LIVE; // Replace with your live SIP host
        } else {
            $this->sip_host = self::SIP_HOST_SANDBOX; // Replace with your sandbox SIP host
        }
    }

    /**
     * Initialize gateway settings form fields.
     */
    public function init_form_fields()
    {
        $this->form_fields = $this->get_form_fields();
    }

    /**
     * Get form fields.
     */
    public function get_form_fields()
    {
        $form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'dfin-sell-payment-gateway'),
                'label' => __('Enable DFin Sell Payment Gateway', 'dfin-sell-payment-gateway'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'dfin-sell-payment-gateway'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'dfin-sell-payment-gateway'),
                'default' => __('Credit/Debit Card', 'dfin-sell-payment-gateway'),
                'desc_tip' => __('Enter the title of the payment gateway as it will appear to customers during checkout.', 'dfin-sell-payment-gateway'),
            ),
            'description' => array(
                'title' => __('Description', 'dfin-sell-payment-gateway'),
                'type' => 'text',
                'description' => __('Provide a brief description of the DFin Sell Payment Gateway option.', 'dfin-sell-payment-gateway'),
                'default' => 'Description of the DFin Sell Payment Gateway Option.',
                'desc_tip' => __('Enter a brief description that explains the DFin Sell Payment Gateway option.', 'dfin-sell-payment-gateway'),
            ),
            'instructions' => array(
                'title' => __('Instructions', 'dfin-sell-payment-gateway'),
                'type' => 'title',
                'description' => sprintf(
                    __('To configure this gateway, %sGet your API keys from your merchant account: Developer Settings > API Keys.%s', 'dfin-sell-payment-gateway'),
                    '<strong><a href="' . esc_url($this->sip_host . '/developers') . '" target="_blank">' . __('click here to access your developer account', 'dfin-sell-payment-gateway') . '</a></strong><br>',
                    ''
                ),
                'desc_tip' => true,
            ),
            'public_key' => array(
                'title' => __('Public Key', 'dfin-sell-payment-gateway'),
                'type' => 'text',
                'default' => '',
                'desc_tip' => __('Enter your Public Key obtained from your merchant account.', 'dfin-sell-payment-gateway'),
            ),
            'secret_key' => array(
                'title' => __('Secret Key', 'dfin-sell-payment-gateway'),
                'type' => 'text',
                'default' => '',
                'desc_tip' => __('Enter your Secret Key obtained from your merchant account.', 'dfin-sell-payment-gateway'),
            ),
        );

        return apply_filters('woocommerce_gateway_settings_fields_' . $this->id, $form_fields, $this);
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        // Check for API keys
        $error_message = $this->check_api_keys();
        if ($error_message) {
            wc_add_notice(__('Payment error: ', 'woocommerce') . $error_message, 'error');
            return array('result' => 'fail');
        }

        // Prepare data for the API request
        $data = $this->prepare_payment_data($order);

        $apiPath = '/api/request-payment';

        // Concatenate the base URL and path
        $url = $this->sip_protocol . $this->sip_host . $apiPath;

        // Remove any double slashes in the URL except for the 'http://' or 'https://'
        $cleanUrl = preg_replace('#(?<!:)//+#', '/', $url);

        // Send the data to the API
        $response = wp_remote_post($cleanUrl, array(
            'method'    => 'POST',
            'timeout'   => 30,
            'body'      => $data,
            'headers'   => array(
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Bearer ' . sanitize_text_field($this->public_key),
            ),
            'sslverify' => true, // Ensure SSL verification
        ));

        if (is_wp_error($response)) {
            // Handle WP error
            $error_message = $response->get_error_message();
            wc_add_notice(__('Payment error: Unable to process payment.', 'woocommerce') . ' ' . $error_message, 'error');
            return array('result' => 'fail');
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (
            isset($response_data['status']) && $response_data['status'] === 'success' &&
            isset($response_data['data']['payment_link']) && !empty($response_data['data']['payment_link'])
        ) {
            // Mark order as pending payment
            $order->update_status('pending', __('Awaiting payment', 'woocommerce'));

            // Return a success result without redirecting
            return array(
                'payment_link' => $response_data['data']['payment_link'],
                'result'   => 'success',
            );
        } else {
            // Handle API error response
            $error_message = isset($response_data['message']) ? sanitize_text_field($response_data['message']) : __('Unable to retrieve payment link.', 'woocommerce');
            wc_add_notice(__('Payment error: ', 'woocommerce') . $error_message, 'error');
            return array('result' => 'fail');
        }
    }

    private function check_api_keys()
    {
        if (!$this->secret_key && !$this->public_key) {
            return __('API Secret and Public Key are required.', 'woocommerce');
        } elseif (!$this->secret_key) {
            return __('API Secret is required.', 'woocommerce');
        } elseif (!$this->public_key) {
            return __('Public Key is required.', 'woocommerce');
        }
        return '';
    }

    private function get_return_url_base()
    {
        return home_url('/wp-json/dfinsell/v1/data', $this->sip_protocol);
    }

    private function prepare_payment_data($order)
    {

        // Sanitize and get the billing email or phone
        $request_for = sanitize_email($order->get_billing_email() ?: $order->get_billing_phone());
        // Get order details and sanitize
        $first_name = sanitize_text_field($order->get_billing_first_name());
        $last_name = sanitize_text_field($order->get_billing_last_name());
        $amount = $order->get_total();

        $redirect_url = esc_url_raw(
            add_query_arg(
                array(
                    'order_id' => $order->get_id(), // Include order ID or any other identifier
                    'key' => $order->get_order_key(),
                    'nonce' => wp_create_nonce('dfin_sell_payment_nonce'), // Create a nonce for verification
                    'mode' => 'wp',
                ),
                $this->get_return_url_base() // Use the updated base URL method
            )
        );

        $ip_address = sanitize_text_field($this->get_client_ip());

        // Prepare meta data
        $meta_data = json_encode(array(
            'source' => 'woocommerce',
            'order_id' => $order->get_id()
        ));

        return array(
            'api_secret' => sanitize_text_field($this->secret_key),
            'first_name' => $first_name,
            'last_name' => $last_name,
            'request_for' => $request_for,
            'amount' => $amount,
            'redirect_url' => $redirect_url,
            'redirect_time' => 3,
            'ip_address' => $ip_address,
            'source' => 'wordpress',
            'meta_data' => $meta_data,
            'remarks' => 'Order #' . $order->get_order_number()
        );
    }

    // Helper function to get client IP address
    private function get_client_ip()
    {
        $ip_address = '';
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip_address = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip_address = $_SERVER['REMOTE_ADDR'];
        }
        return $ip_address;
    }

    /**
     * Receipt page.
     *
     * @param int $order_id
     */
    public function receipt_page($order_id)
    {
        return $this->get_embedded_receipt_page($order_id);
    }

    /**
     * WooCommerce not active notice.
     */
    public function woocommerce_not_active_notice()
    {
        echo '<div class="error">
    <p>' . __('DFin Sell Payment Gateway requires WooCommerce to be installed and active.', 'dfin-sell-payment-gateway') . '</p>
</div>';
    }

    /**
     * Payment form on checkout page.
     */
    public function payment_fields()
    {
        $description = $this->get_description();

        if ($description) {
            echo wpautop(wptexturize(trim($description)));
        }
    }

    /**
     * Enqueue stylesheets for the plugin.
     */
    public function enqueue_styles_and_scripts()
    {
        // Enqueue stylesheets
        wp_enqueue_style('dfin-sell-payment-loader-styles', plugins_url('../assets/css/loader.css', __FILE__));

        // Enqueue dfinsell.js script
        wp_enqueue_script('dfinsell-js', plugins_url('../assets/js/dfinsell.js', __FILE__), array('jquery'), '1.0', true);

        // Localize script with parameters that need to be passed to dfinsell.js
        wp_localize_script('dfinsell-js', 'dfinsell_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'checkout_url' => wc_get_checkout_url(),
            'dfin_loader' => plugins_url('../assets/images/loader.gif', __FILE__),
            'dfinsell_nonce' => wp_create_nonce('dfinsell_nonce'), // Create a nonce for verification
        ));
    }
}

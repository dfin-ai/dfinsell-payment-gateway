<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('DFPG_MODULE_VERSION', '1.0.0');
define('SIP_HOST', 'https://sell.dfin.ai');

class WC_Gateway_DfinSell extends WC_Payment_Gateway_CC
{
    const ID = 'dfinsell';

    /**
     * @var string
     */
    protected $public_key;

    /**
     * @var string
     */
    protected $secret_key;

    /**
     * Constructor.
     */
    public function __construct()
    {

        // Add CORS handling
        add_action('init', array($this, 'dfinsell_handle_cors'));

        $this->id                   = self::ID;
        $this->method_title         = __(
            'DFin Sell Payment Gateway',
            'woocommerce-gateway-dfin-sell'
        );
        $this->method_description   = __(
            'This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.'
        );
        $this->new_method_label     = __(
            'Use a new card',
            'woocommerce-gateway-dfin-sell'
        );
        $this->has_fields           = true;

        // Load the form fields
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get setting values
        $this->title                      = $this->get_option('title');
        $this->description                = $this->get_option('description');
        $this->enabled                    = $this->get_option('enabled');
        $this->public_key                 = $this->get_option('public_key');
        $this->secret_key                =  $this->get_option('secret_key');

        $this->init_dfinsell_sdk();

        // Hooks
        add_action(
            sprintf("woocommerce_update_options_payment_gateways_%s", $this->id),
            array($this, 'process_admin_options')
        );
        add_action(
            sprintf("woocommerce_receipt_%s", $this->id),
            array($this, 'receipt_page')
        );
        add_action(
            sprintf("woocommerce_api_wc_gateway_%s", $this->id),
            array($this, 'return_handler')
        );

        // Add JS for handling the redirect in a new tab
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Init DFinsell SDK.
     */
    protected function init_dfinsell_sdk()
    {
        // Include lib
        require_once('DFinSell.php');

        DFinSell::$publicKey  = $this->public_key;
        DFinSell::$privateKey = $this->secret_key;

        try {
            // try to extract version from main plugin file
            $plugin_path    = dirname(__FILE__, 2) . '/dfin-sell-payment-gateway.php';
            $plugin_data    = get_file_data($plugin_path, array('Version' => 'Version'));
            $plugin_version = $plugin_data['Version'] ?: 'Unknown';
        } catch (Exception $e) {
            $plugin_version = 'UnknownError';
        }

        DFinSell::$userAgent = 'DFinSellWooCommercePlugin/' . WC()->version . '/' . $plugin_version;
    }

    /**
     * Admin Panel Options.
     * - Options for bits like 'title' and availability on a country-by-country basis.
     */
    public function admin_options()
    {
?>
        <h3><?php echo $this->method_title ?></h3>

        <?php $this->checks(); ?>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
    <?php
    }

    /**
     * Check if SSL is enabled and notify the user.
     */
    public function checks()
    {
        if ('no' === $this->enabled) {
            return;
        }

        // PHP Version
        if (version_compare(phpversion(), '8.0', '<')) {
            $this->store_error(
                sprintf(
                    __('Gateway Error: DFin Sell commerce requires PHP 8.0 and above. You are using version %s.', 'woocommerce-gateway-dfin-sell'),
                    phpversion()
                )
            );
        } elseif (!$this->title) {
            $this->store_error(__('Gateway Error: Please enter Title', 'woocommerce-gateway-dfin-sell'));
        } elseif (!$this->description) {
            $this->store_error(__('Gateway Error: Please enter Description', 'woocommerce-gateway-dfin-sell'));
        } elseif (!$this->public_key || !$this->secret_key) {
            $this->store_error(__('Gateway Error: Please enter your public and secret keys', 'woocommerce-gateway-dfin-sell'));
        }

        // Check for session errors and display
        $this->display_stored_errors();
    }

    /**
     * Store error message in session.
     *
     * @param string $error_message
     */
    private function store_error($error_message)
    {
        if (!session_id()) {
            session_start();
        }
        $_SESSION['dfinsell_gateway_errors'][] = $error_message;
    }

    /**
     * Display stored errors and clear them.
     */
    private function display_stored_errors()
    {
        if (!session_id()) {
            session_start();
        }

        if (!empty($_SESSION['dfinsell_gateway_errors'])) {
            foreach ($_SESSION['dfinsell_gateway_errors'] as $error_message) {
                echo '<div class="error"><p>' . $error_message . '</p></div>';
            }
            unset($_SESSION['dfinsell_gateway_errors']);
        }
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields()
    {

        $developer_settings_description = __('Get your API keys from your merchant account: Developer Settings > API Keys.', 'woocommerce-gateway-dfin-sell');

        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'woocommerce-gateway-dfin-sell'),
                'label'       => __('Enable DFin Sell Payment Gateway', 'woocommerce-gateway-dfin-sell'),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'title' => array(
                'title'       => __('Title', 'woocommerce-gateway-dfin-sell'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-gateway-dfin-sell'),
                'default'     => __('Credit/Debit Card', 'woocommerce-gateway-dfin-sell'),
                'desc_tip'    => __('Enter the title of the payment gateway as it will appear to customers during checkout.', 'woocommerce-gateway-dfin-sell'),
            ),
            'description' => array(
                'title'       => __('Description', 'woocommerce-gateway-dfin-sell'),
                'type'        => 'text',
                'description' => __('Provide a brief description of the DFin Sell Payment Gateway option.', 'woocommerce-gateway-dfin-sell'),
                'default'     => 'Description of the DFin Sell Payment Gateway Option.',
                'desc_tip'    => __('Enter a brief description that explains the DFin Sell Payment Gateway option.', 'woocommerce-gateway-dfin-sell'),
            ),
            'instructions' => array(
                'title'       => __('Instructions', 'woocommerce-gateway-dfin-sell'),
                'type'        => 'title',
                'description' => sprintf(
                    __('To configure this gateway, %sGet your API keys from your merchant account: Developer Settings > API Keys.%s', 'woocommerce-gateway-dfin-sell'),
                    '<strong><a href="' . esc_url(SIP_HOST . '/developers') . '" target="_blank">' . __('click here to access your developer account', 'woocommerce-gateway-dfin-sell') . '</a></strong><br>',
                    ''
                ),
                'desc_tip'    => true,
            ),
            'public_key' => array(
                'title'       => __('Public Key', 'woocommerce-gateway-dfin-sell'),
                'type'        => 'text',
                'default'     => '',
                'desc_tip'    => __('Enter your Public Key obtained from your merchant account.', 'woocommerce-gateway-dfin-sell'),
            ),
            'secret_key' => array(
                'title'       => __('Secret Key', 'woocommerce-gateway-dfin-sell'),
                'type'        => 'text',
                'default'     => '',
                'desc_tip'    => __('Enter your Secret Key obtained from your merchant account.', 'woocommerce-gateway-dfin-sell'),
            ),
        );
    }

    /**
     * Returns the POSTed data, to be used to save the settings.
     * @return array
     */
    public function get_post_data()
    {
        foreach ($this->form_fields as $form_field_key => $form_field_value) {
            if ($form_field_value['type'] == "select_card_types") {
                $form_field_key_select_card_types           = $this->plugin_id . $this->id . "_" . $form_field_key;
                $select_card_types_values                   = array();
                $_POST[$form_field_key_select_card_types] = $select_card_types_values;
            }
        }

        if (!empty($this->data) && is_array($this->data)) {
            return $this->data;
        }

        return $_POST;
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

        echo '<div style="border: 1px solid #a7a8ac; border-radius:10px; padding: 10px 0;">
            <img src="' . plugins_url('../assets/images/logo.png', __FILE__) . '" width="120" height="70" />
            <h2 style="font-size: 20px; font-weight: 500; color: #58585c;padding: 8px 16px;margin: 0;">DFin Sell Pay selected.</h2>
            <div style="display: flex; align-items: center; padding: 20px 0px 0; border-top: 1px solid #a7a8ac; margin: 14px 16px 10px">
                <img src="' . plugins_url('../assets/images/icon.svg', __FILE__) . '" width="50" height="50" style="margin-right: 8px;"/>
                <p style="margin: 0;max-width:300px;color: #707070;font-size: 17px; line-height: 22px;">After submission, you will be redirected to securely complete next setps.</p>
            </div>
        </div>';
    }

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

        // Send the data to the API
        $response = wp_remote_post(SIP_HOST . '/api/request-payment', array(
            'method'    => 'POST',
            'timeout'   => 30,
            'body'      => $data,
            'headers'   => array(
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Bearer ' . sanitize_text_field($this->public_key),
            ),
        ));

        if (is_wp_error($response)) {
            wc_add_notice(__('Payment error: Unable to process payment.', 'woocommerce'), 'error');
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

            // Remove cart
            WC()->cart->empty_cart();

            // Return a success result without redirecting
            return array(
                'payment_link' => $response_data['data']['payment_link'],
                'result'   => 'success',
            );
        } else {
            $error_message = isset($response_data['messages']) ? wp_kses_post($response_data['messages']) : __('Unable to retrieve payment link.', 'woocommerce');
            wc_add_notice($error_message, 'error');
            return array('result' => 'fail');
        }
    }

    /**
     * Add custom notices on the checkout page.
     */
    function dfinsell_custom_checkout_notices()
    {
        if (isset($_POST['dfinsell_order_id'])) {
            $order_id = absint($_POST['dfinsell_order_id']);
            $order    = wc_get_order($order_id);

            if ($order) {
                // Example of checking order status and displaying a notice
                if ('failed' === $order->get_status()) {
                    wc_add_notice('Your payment has failed. Please try again.', 'error');
                } elseif ('pending' === $order->get_status()) {
                    wc_add_notice('Your payment is pending. Please check again later.', 'notice');
                }
            }
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

    private function prepare_payment_data($order)
    {

        // Sanitize and get the billing email or phone
        $request_for = sanitize_email($order->get_billing_email() ?: $order->get_billing_phone());

        // Get order details and sanitize
        $first_name = sanitize_text_field($order->get_billing_first_name());
        $last_name = sanitize_text_field($order->get_billing_last_name());
        $amount = intval(round($this->get_total($order) / 100));
        $redirect_url = esc_url_raw($this->get_return_url($order));
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
            'meta_data' => $meta_data,
            'remarks' => 'Order #' . $order->get_order_number()
        );
    }

    public function get_return_url($order = null)
    {

        $nonce = wp_create_nonce('dfinsell_process_payment_nonce');

        return add_query_arg(array(
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
            'nonce'    => $nonce, // Include nonce in the query args
            'mode' => 'wp',
        ), $this->get_return_url_base());
    }

    private function get_return_url_base()
    {
        return home_url('/wp-json/custom-api/v1/data', 'https'); //Need to https
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
     * Hosted payment args.
     *
     * @param WC_Order $order
     *
     * @return array
     */
    protected function get_hosted_payments_args($order)
    {
        $args = apply_filters('woocommerce_dfinsell_hosted_args', array(
            'sc-key'        => $this->public_key,
            'customer-name' => sprintf("%s %s", $order->get_billing_first_name(), $order->get_billing_last_name()),
            'amount'        => $this->get_total($order),
            'currency'      => strtoupper(get_woocommerce_currency()),
            'reference'     => $order->get_id(),
            'description'   => sprintf(__('Order #%s', 'woocommerce-gateway-dfin-sell'), $order->get_order_number()),
            'receipt'       => 'false',
            'redirect-url'  => WC()->api_request_url('WC_Gateway_DfinSell'),
            'operation'     => 'create.token',
        ), $order->get_id());

        return $args;
    }

    protected function attempt_transliteration($field)
    {
        $encode = mb_detect_encoding($field);
        if ($encode !== 'ASCII') {
            if (function_exists('transliterator_transliterate')) {
                $field = transliterator_transliterate('Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove', $field);
            } else {
                // fall back to iconv if intl module not available
                $field = remove_accents($field);
                $field = iconv($encode, 'ASCII//TRANSLIT//IGNORE', $field);
                $field = str_ireplace('?', '', $field);
                $field = trim($field);
            }
        }

        return $field;
    }

    /**
     * @param string|int|float $totalAmount
     *
     * @return int
     */
    protected function get_total_amount($totalAmount)
    {
        $priceDecimals   = wc_get_price_decimals();
        $priceMultiplier = pow(10, $priceDecimals);

        return (int) round((float) $totalAmount * $priceMultiplier);
    }

    /**
     * @param WC_Order $order
     *
     * @return int
     */
    protected function get_total($order)
    {
        return $this->get_total_amount($order->get_total());
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
     * Receipt Page for Embedded Integration Mode.
     *
     * @param int $order_id
     */
    protected function get_embedded_receipt_page(int $order_id)
    {
        $order = wc_get_order($order_id);
        echo '<p>' .
            __(
                'Thank you for your order, please click the button below to pay with credit card using DFin Sell Payment Gateway.',
                'woocommerce-gateway-dfin-sell'
            ) .
            '</p>';

        $args = $this->get_hosted_payments_args($order);

        $iframe_args = array();
        foreach ($args as $key => $value) {
            $value = $this->attempt_transliteration($value);
            if (!$value) {
                continue;
            }
            $iframe_args[] = sprintf(
                "data-%s=\"%s\"",
                esc_attr($key),
                esc_attr($value)
            );
        }

        // TEMPLATE VARS
        $redirect_url = WC()->api_request_url('WC_Gateway_DfinSell');
        $public_key   = $this->public_key;
        // TEMPLATE VARS

        require plugin_basename('embedded-template.php');
    }

    /**
     * Return handler for Hosted Payments.
     */
    public function return_handler()
    {
        @ob_clean();
        header('HTTP/1.1 200 OK');

        wp_redirect($this->get_return_url($order));
        exit();
    }

    public function enqueue_scripts()
    {
        if (is_checkout()) {
            global $wp;

            wp_enqueue_script(
                'dfinsell-script',
                plugins_url('../assets/js/dfinsell.js', __FILE__),
                array('jquery'),
                null,
                true
            );

            $dfinsell_params = array(
                'ajax_url'       => admin_url('admin-ajax.php'),
                'dfinsell_nonce' => wp_create_nonce('dfinsell_nonce'),
                'dfin_loader' => plugins_url('../assets/images/loader_gif.gif', __FILE__)
            );

            wp_localize_script('dfinsell-script', 'dfinsell_params', $dfinsell_params);

            wp_enqueue_style('dfinsell-loader-style', plugins_url('../assets/css/loader.css', __FILE__), array(), null);
        }
    }

    public function dfinsell_loader_html()
    {
        echo '<div class="dfinsell-loader"><img src="' . plugins_url('../assets/images/loader_gif.gif', __FILE__) . '" alt="Loading..."></div>';
    }

    public function dfinsell_handle_cors()
    {
        if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === SIP_HOST) {
            header('Access-Control-Allow-Origin: ' . SIP_HOST);
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            header('Access-Control-Allow-Credentials: true');

            // Handle OPTIONS method for preflight requests
            if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                header('HTTP/1.1 200 OK');
                exit();
            }
        }
    }


    /**
     * Thankyou Page
     *
     * @package Rethink Payment gateway for WooCommerce
     * @since 1.0.5
     **/
    function thankyou_page($order)
    {
        if (!empty($this->instructions)) {
            echo wpautop(wptexturize($this->instructions));
        }
    }
}

add_action('woocommerce_checkout_form', 'dfinsell_inject_order_id_field');


function dfinsell_inject_order_id_field()
{
    if (!is_checkout()) {
        return;
    }

    $order = wc_get_order();
    if (!$order) {
        return;
    }

    $order_id = $order->get_id();
    ?>
    <input type="hidden" name="dfinsell_order_id" value="<?php echo esc_attr($order_id); ?>">
<?php
}

function dfinsell_payment_return_shortcode()
{
    if (isset($_GET['order_id']) && isset($_GET['key'])) {
        $order_id = intval($_GET['order_id']);
        $order_key = sanitize_text_field($_GET['key']);

        // Get the order
        $order = wc_get_order($order_id);
        if ($order && $order->get_order_key() === $order_key) {
            // Get the current order status
            $order_status = $order->get_status();
            if ($order_status === 'processing') {
                return '<h2>Payment Status</h2><p>Your payment was processed successfully.</p>';
            } elseif ($order_status === 'failed') {
                return '<h2>Payment Status</h2><p>Your payment has failed. Please try again.</p>';
            } else {
                return '<h2>Payment Status</h2><p>Your payment is pending. Please check again later.</p>';
            }
        } else {
            return '<h2>Payment Status</h2><p>Invalid order ID or key.</p>';
        }
    } else {
        return '<h2>Payment Status</h2><p>Missing order ID or key.</p>';
    }
}

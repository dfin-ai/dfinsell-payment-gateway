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

	private $admin_notices;

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

		// Instantiate the notices class
		$this->admin_notices = new WC_Gateway_DFinSell_Admin_Notices();

		// Determine SIP protocol based on site protocol
		$this->sip_protocol = (is_ssl() ? 'https://' : 'http://'); // Use HTTPS if SSL is enabled, otherwise HTTP

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

		// Enqueue styles and scripts
		add_action('wp_enqueue_scripts', array($this, 'enqueue_styles_and_scripts'));
	}

	public function process_admin_options()
	{
		parent::process_admin_options();

		// Retrieve the options from the settings
		$title = $this->get_option('title');
		$description = $this->get_option('description');
		$public_key = $this->get_option('public_key');
		$secret_key = $this->get_option('secret_key');

		// Initialize error tracking
		$errors = array();

		// Check for Title
		if (empty($title)) {
			$errors[] = __('Title is required. Please enter a title in the settings.', 'dfin-sell-payment-gateway');
		}

		// Check for Description
		if (empty($description)) {
			$errors[] = __('Description is required. Please enter a description in the settings.', 'dfin-sell-payment-gateway');
		}

		// Check for Public Key
		if (empty($public_key)) {
			$errors[] = __('Public Key is required. Please enter your Public Key in the settings.', 'dfin-sell-payment-gateway');
		}

		// Check for Secret Key
		if (empty($secret_key)) {
			$errors[] = __('Secret Key is required. Please enter your Secret Key in the settings.', 'dfin-sell-payment-gateway');
		}

		// Check API Keys only if there are no other errors
		if (empty($errors)) {
			$api_key_error = $this->check_api_keys();
			if ($api_key_error) {
				$errors[] = $api_key_error;
			}
		}

		// Display all errors
		if (!empty($errors)) {
			foreach ($errors as $error) {
				$this->admin_notices->add_notice('settings_error', 'error', $error);
			}
			add_action('admin_notices', array($this->admin_notices, 'display_notices'));
		}
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
				// Translators comment added here
				/* translators: 1: Link to developer account */
				'description' => sprintf(
					/* translators: %1$s is a link to the developer account. %2$s is used for any additional formatting if necessary. */
					__('To configure this gateway, %1$sGet your API keys from your merchant account: Developer Settings > API Keys.%2$s', 'dfin-sell-payment-gateway'),
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
			'order_status' => array(
				'title' => __('Order Status', 'dfin-sell-payment-gateway'),
				'type' => 'select',
				'description' => __('Select the order status to be set after successful payment.', 'dfin-sell-payment-gateway'),
				'default' => '', // Default is empty, which is our placeholder
				'desc_tip' => true,
				'id' => 'order_status_select', // Add an ID for targeting
				'options' => array(
					// '' => __('Select order status', 'dfin-sell-payment-gateway'), // Placeholder option
					'processing' => __('Processing', 'dfin-sell-payment-gateway'),
					'completed' => __('Completed', 'dfin-sell-payment-gateway'),
				),
			),
			'show_consent_checkbox' => array(
				'title' => __('Show Consent Checkbox', 'dfin-sell-payment-gateway'),
				'label' => __('Enable consent checkbox on checkout page', 'dfin-sell-payment-gateway'),
				'type' => 'checkbox',
				'description' => __('Check this box to show the consent checkbox on the checkout page. Uncheck to hide it.', 'dfin-sell-payment-gateway'),
				'default' => 'yes',
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
	public function process_payment($order_id)
	{
		$order = wc_get_order($order_id);

		// Prepare data for the API request
		$data = $this->prepare_payment_data($order);

		$apiPath = '/api/request-payment';

		// Concatenate the base URL and path
		$url = $this->sip_protocol . $this->sip_host . $apiPath;

		// Remove any double slashes in the URL except for the 'http://' or 'https://'
		$cleanUrl = preg_replace('#(?<!:)//+#', '/', $url);

		$order->update_meta_data('_order_origin', 'dfin_sell_payment_gateway');
		$order->save();

		// Log the request data (optional)
		wc_get_logger()->info('DFin Sell Payment Request: ' . print_r($data, true), array('source' => 'dfin_sell_payment_gateway'));

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

		// Log the essential response data
		if (is_wp_error($response)) {
			// Log the error message
			wc_get_logger()->error('DFin Sell Payment Request Error: ' . $response->get_error_message(), array('source' => 'dfin_sell_payment_gateway'));
			wc_add_notice(__('Payment error: Unable to process payment.', 'woocommerce') . ' ' . $response->get_error_message(), 'error');
			return array('result' => 'fail');
		} else {
			$response_code = wp_remote_retrieve_response_code($response);
			$response_body = wp_remote_retrieve_body($response);

			// Log the response code and body
			wc_get_logger()->info(
				sprintf('DFin Sell Payment Response: Code: %d, Body: %s', $response_code, $response_body),
				array('source' => 'dfin_sell_payment_gateway')
			);
		}
		$response_data = json_decode($response_body, true);

		if (
			isset($response_data['status']) && $response_data['status'] === 'success' &&
			isset($response_data['data']['payment_link']) && !empty($response_data['data']['payment_link'])
		) {
			// Update the order status
			$order->update_status('pending', __('Payment pending.', 'woocommerce'));

			// Check if the note already exists
			$existing_notes = $order->get_customer_order_notes();
			$new_note = __('Payment initiated via DFin Sell Payment Gateway. Awaiting customer action.', 'dfin-sell-payment-gateway');
			$note_exists = false;

			foreach ($existing_notes as $note) {
				if (strip_tags($note->comment_content) === $new_note) {
					$note_exists = true;
					break;
				}
			}

			// Add the note if it doesn't exist
			if (!$note_exists) {
				$order->add_order_note($new_note);
			}

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
		// This method should only be called if no other errors exist
		if (empty($this->public_key) && empty($this->secret_key)) {
			return __('Both Public Key and Secret Key are required. Please enter them in the settings.', 'dfin-sell-payment-gateway');
		} elseif (empty($this->public_key)) {
			return __('Public Key is required. Please enter your Public Key in the settings.', 'dfin-sell-payment-gateway');
		} elseif (empty($this->secret_key)) {
			return __('Secret Key is required. Please enter your Secret Key in the settings.', 'dfin-sell-payment-gateway');
		}
		return '';
	}


	private function get_return_url_base()
	{
		return rest_url('/dfinsell/v1/data');
	}

	private function prepare_payment_data($order)
	{
		// Sanitize and get the billing email or phone
		$request_for = sanitize_email($order->get_billing_email() ?: $order->get_billing_phone());
		// Get order details and sanitize
		$first_name = sanitize_text_field($order->get_billing_first_name());
		$last_name = sanitize_text_field($order->get_billing_last_name());
		$amount = $order->get_total();

		// Get billing address details
		$billing_address_1 = sanitize_text_field($order->get_billing_address_1());
		$billing_address_2 = sanitize_text_field($order->get_billing_address_2());
		$billing_city = sanitize_text_field($order->get_billing_city());
		$billing_postcode = sanitize_text_field($order->get_billing_postcode());
		$billing_country = sanitize_text_field($order->get_billing_country());
		$billing_state = sanitize_text_field($order->get_billing_state());

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
		$meta_data = wp_json_encode(array(
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
			'remarks' => 'Order #' . $order->get_order_number(),
			// Add billing address details to the request
			'billing_address_1' => $billing_address_1,
			'billing_address_2' => $billing_address_2,
			'billing_city' => $billing_city,
			'billing_postcode' => $billing_postcode,
			'billing_country' => $billing_country,
			'billing_state' => $billing_state,
		);
	}

	// Helper function to get client IP address
	private function get_client_ip()
	{
		$ip_address = '';

		if (isset($_SERVER['HTTP_CLIENT_IP'])) {
			$ip_address = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// Extract the first IP address from the list
			$forwarded_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			$ip_address = trim($forwarded_ips[0]);
		} elseif (isset($_SERVER['REMOTE_ADDR'])) {
			$ip_address = $_SERVER['REMOTE_ADDR'];
		}

		// Validate and sanitize the IP address
		if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
			$ip_address = 'Invalid IP';
		}

		return $ip_address;
	}

	/**
	 * WooCommerce not active notice.
	 */
	public function woocommerce_not_active_notice()
	{
		echo '<div class="error">
        <p>' . esc_html__('DFin Sell Payment Gateway requires WooCommerce to be installed and active.', 'dfin-sell-payment-gateway') . '</p>
    </div>';
	}

	/**
	 * Payment form on checkout page.
	 */
	public function payment_fields()
	{
		$description = $this->get_description();

		if ($description) {
			// Apply formatting
			$formatted_description = wpautop(wptexturize(trim($description)));
			// Output directly with escaping
			echo wp_kses_post($formatted_description);
		}

		// Check if the consent checkbox should be displayed
		if ('yes' === $this->get_option('show_consent_checkbox')) {
			// Add user consent checkbox with escaping
			echo '<p class="form-row form-row-wide">
                <label for="dfinsell_consent">
                    <input type="checkbox" id="dfinsell_consent" name="dfinsell_consent" /> ' . esc_html__('I consent to the collection of my data to process this payment', 'dfin-sell-payment-gateway') . '
                </label>
            </p>';
		}

		// Add nonce field for security
		wp_nonce_field('dfinsell_payment', 'dfinsell_nonce');
	}

	/**
	 * Validate the payment form.
	 */
	public function validate_fields()
	{
		if ($this->get_option('show_consent_checkbox') === 'yes') {
			// Verify nonce
			if (!isset($_POST['dfinsell_nonce']) || !wp_verify_nonce($_POST['dfinsell_nonce'], 'dfinsell_payment')) {
				wc_add_notice(__('Nonce verification failed. Please try again.', 'dfin-sell-payment-gateway'), 'error');
				return false;
			}

			// Check if the consent checkbox setting is enabled

			if (!isset($_POST['dfinsell_consent']) || empty($_POST['dfinsell_consent'])) {
				wc_add_notice(__('Please consent to the collection of your data to proceed with the payment.', 'dfin-sell-payment-gateway'), 'error');
				return false;
			}
		}

		return true;
	}


	/**
	 * Enqueue stylesheets for the plugin.
	 */
	public function enqueue_styles_and_scripts()
	{
		// Enqueue stylesheets
		wp_enqueue_style(
			'dfin-sell-payment-loader-styles',
			plugins_url('../assets/css/loader.css', __FILE__),
			array(), // Dependencies (if any)
			'1.0', // Version number
			'all' // Media
		);

		// Enqueue dfinsell.js script
		wp_enqueue_script(
			'dfinsell-js',
			plugins_url('../assets/js/dfinsell.js', __FILE__),
			array('jquery'), // Dependencies
			'1.0', // Version number
			true // Load in footer
		);

		// Localize script with parameters that need to be passed to dfinsell.js
		wp_localize_script('dfinsell-js', 'dfinsell_params', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'checkout_url' => wc_get_checkout_url(),
			'dfin_loader' => plugins_url('../assets/images/loader.gif', __FILE__),
			'dfinsell_nonce' => wp_create_nonce('dfinsell_nonce'), // Create a nonce for verification
		));
	}
}

<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}
// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';

/**
 * Main WooCommerce DFin Sell Payment Gateway class.
 */
class DFINSELL_PAYMENT_GATEWAY extends WC_Payment_Gateway_CC
{
	const ID = 'dfinsell';

	private $sip_protocol;
    private $sip_host;

	protected $sandbox;

	private $public_key;
	private $secret_key;
	private $sandbox_secret_key;
	private $sandbox_public_key;

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
		$this->admin_notices = new DFINSELL_PAYMENT_GATEWAY_Admin_Notices();

		// Determine SIP protocol based on site protocol
        $this->sip_protocol = SIP_PROTOCOL;
		$this->sip_host = SIP_HOST;

		// Define user set variables
		$this->id = self::ID;
		$this->icon = ''; // Define an icon URL if needed.
		$this->method_title = __('DFin Sell Payment Gateway', 'dfinsell-payment-gateway');
		$this->method_description = __('This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.', 'dfinsell-payment-gateway');

		// Load the settings
		$this->dfinsell_init_form_fields();
		$this->init_settings();

		// Define properties
		$this->title = sanitize_text_field($this->get_option('title'));
		$this->description = !empty($this->get_option('description')) ? sanitize_textarea_field($this->get_option('description')) : ($this->get_option('show_consent_checkbox') === 'yes' ? 1 : 0);
		$this->enabled = sanitize_text_field($this->get_option('enabled'));
		$this->sandbox = 'yes' === sanitize_text_field($this->get_option('sandbox')); // Use boolean
		$this->public_key                 = $this->sandbox === 'no' ? sanitize_text_field($this->get_option('public_key')) : sanitize_text_field($this->get_option('sandbox_public_key'));
		$this->secret_key                = $this->sandbox === 'no' ? sanitize_text_field($this->get_option('secret_key')) : sanitize_text_field($this->get_option('sandbox_secret_key'));

		// Define hooks and actions.
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'dfinsell_process_admin_options'));

		// Enqueue styles and scripts
		add_action('wp_enqueue_scripts', array($this, 'dfinsell_enqueue_styles_and_scripts'));

		add_action('admin_enqueue_scripts', array($this, 'dfinsell_admin_scripts'));

		// Add action to display test order tag in order details
		add_action('woocommerce_admin_order_data_after_order_details', array($this, 'dfinsell_display_test_order_tag'));

		// Hook into WooCommerce to add a custom label to order rows
		add_filter('woocommerce_admin_order_preview_line_items', array($this, 'dfinsell_add_custom_label_to_order_row'), 10, 2);

		add_filter('woocommerce_available_payment_gateways',  array($this, 'hide_custom_payment_gateway_conditionally'));
	
	  // Add custom field rendering hook
	  add_action('woocommerce_admin_field_dfinsell_key_pairs', array($this, 'render_key_pairs_field'));
	
	}

	private function get_api_url($endpoint)
	{
		return $this->sip_protocol . $this->sip_host . $endpoint;
	}

	public function dfinsell_process_admin_options()
	{
		parent::process_admin_options();

		// Retrieve the options from the settings
		$title = sanitize_text_field($this->get_option('title'));
		// Check if sandbox mode is enabled and sanitize the option
		$enabled = sanitize_text_field($this->get_option('enabled'));
		$is_sandbox = sanitize_text_field($this->get_option('sandbox')) === 'yes';

		//$accounts = $this->get_option('accounts', []);

	
		$existing_settings = get_option('woocommerce_dfinsell_settings', []);
		$existing_accounts = isset($existing_settings['accounts']) ? $existing_settings['accounts'] : [];
	
		// Extract the new accounts data from POST
		$new_accounts = isset($_POST['accounts']) ? $_POST['accounts'] : [];
	
		// Merge existing accounts with new accounts
		if(is_array($existing_accounts)){
		$all_accounts = array_merge($existing_accounts, $new_accounts);
		}else{
			$all_accounts =	$new_accounts ;
		}
		// Validate all accounts
		$validation_errors = $this->validate_accounts($all_accounts);
		// Initialize error tracking
		$errors = array();

		// Check for Title
		if (empty($title)) {
			$errors[] = __('Title is required. Please enter a title in the settings.', 'dfinsell-payment-gateway');
			}
	// If validation fails, display the error and stop saving


	 // If there are validation errors, display them
	 if (!empty($validation_result['errors'])) {
	
        foreach ($validation_result['errors'] as $error) {
			$errors[] = $error;
        }
     
    }

	
		// Check API Keys only if there are no other errors
		if (empty($errors)) {
			$api_key_error = $this->dfinsell_check_api_keys();
			
			if ($api_key_error) {
				$errors[] = $api_key_error;
			}
		}

		
		// Display all errors
		if (!empty($errors)) {
			foreach ($errors as $error) {
				$this->admin_notices->dfinsell_add_notice('settings_error', 'error', $error);
			}
		
			add_action('admin_notices', array($this->admin_notices, 'display_notices'));
		}else{
			
			// Save accounts
			$accounts = isset($_POST['accounts']) ? $this->sanitize_accounts($_POST['accounts']) : [];
			$settings = get_option('woocommerce_dfinsell_settings', array());
			$settings['accounts'] = $accounts;
			update_option('woocommerce_dfinsell_settings', $settings);
		}
	}
	

/*
	public function process_admin_options() {
		echo "Dddd";exit;
		// Retrieve all POST data
		$post_data = $this->get_post_data();
	print_r($post_data );exit;
		// Extract the accounts data from POST
		$accounts = isset($post_data['accounts']) ? $post_data['accounts'] : [];
	
		// Validate all accounts
		$validation_result = $this->validate_accounts($accounts);
	
		// If validation fails, display the error and stop saving
		if ($validation_result !== true) {
			$this->add_error($validation_result);
			$this->display_errors();
			return;
		}
	
		// Sanitize the accounts
		$sanitized_accounts = $this->sanitize_accounts($accounts);
	
		// Prepare the settings array
		$settings = [
			'enabled' => isset($post_data['enabled']) ? sanitize_text_field($post_data['enabled']) : 'no',
			'title' => isset($post_data['title']) ? sanitize_text_field($post_data['title']) : '',
			'description' => isset($post_data['description']) ? sanitize_textarea_field($post_data['description']) : '',
			'instructions' => isset($post_data['instructions']) ? sanitize_textarea_field($post_data['instructions']) : '',
			'accounts' => $sanitized_accounts,
			'order_status' => isset($post_data['order_status']) ? sanitize_text_field($post_data['order_status']) : 'processing',
			'show_consent_checkbox' => isset($post_data['show_consent_checkbox']) ? sanitize_text_field($post_data['show_consent_checkbox']) : 'no',
		];

	
	
		// Save the settings under the option key `woocommerce_dfinsell_settings`
		update_option('woocommerce_dfinsell_settings', $settings);
	
		// Call the parent method to handle other WooCommerce settings
		parent::process_admin_options();
		error_log('POST Data: ' . print_r($_POST, true));
error_log('Accounts Data: ' . print_r($accounts, true));
error_log('Sanitized Accounts: ' . print_r($sanitized_accounts, true));
error_log('Settings: ' . print_r($settings, true));
	}
	*/

	/**
	 * Initialize gateway settings form fields.
	 */
	public function dfinsell_init_form_fields()
	{
		$this->form_fields = $this->dfinsell_get_form_fields();
	}

	/**
	 * Get form fields.
	 */
	public function dfinsell_get_form_fields()
	{
		$form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'dfinsell-payment-gateway'),
				'label' => __('Enable DFin Sell Payment Gateway', 'dfinsell-payment-gateway'),
				'type' => 'checkbox',
				'description' => '',
				'default' => 'no',
			),
			'title' => array(
				'title' => __('Title', 'dfinsell-payment-gateway'),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'dfinsell-payment-gateway'),
				'default' => __('Credit/Debit Card', 'dfinsell-payment-gateway'),
				'desc_tip' => __('Enter the title of the payment gateway as it will appear to customers during checkout.', 'dfinsell-payment-gateway'),
			),
			'description' => array(
				'title' => __('Description', 'dfinsell-payment-gateway'),
				'type' => 'text',
				'description' => __('Provide a brief description of the DFin Sell Payment Gateway option.', 'dfinsell-payment-gateway'),
				'default' => 'Description of the DFin Sell Payment Gateway Option.',
				'desc_tip' => __('Enter a brief description that explains the DFin Sell Payment Gateway option.', 'dfinsell-payment-gateway'),
			),
			'instructions' => array(
				'title' => __('Instructions', 'dfinsell-payment-gateway'),
				'type' => 'title',
				// Translators comment added here
				/* translators: 1: Link to developer account */
				'description' => sprintf(
					/* translators: %1$s is a link to the developer account. %2$s is used for any additional formatting if necessary. */
					__('To configure this gateway, %1$sGet your API keys from your merchant account: Developer Settings > API Keys.%2$s', 'dfinsell-payment-gateway'),
					'<strong><a class="dfinsell-instructions-url" href="' . esc_url($this->sip_host . '/developers') . '" target="_blank">' . __('click here to access your developer account', 'dfinsell-payment-gateway') . '</a></strong><br>',
					''
				),
				'desc_tip' => true,
			),
			/*'sandbox' => array(
				'title'       => __('Sandbox', 'dfinsell-payment-gateway'),
				'label'       => __('Enable Sandbox Mode', 'dfinsell-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).', 'dfinsell-payment-gateway'),
				'default'     => 'no',
			),
			'sandbox_public_key'  => array(
				'title'       => __('Sandbox Public Key', 'dfinsell-payment-gateway'),
				'type'        => 'text',
				'description' => __('Get your API keys from your merchant account: Account Settings > API Keys.', 'dfinsell-payment-gateway'),
				'default'     => '',
				'desc_tip'    => true,
				'class'       => 'dfinsell-sandbox-keys', // Add class for JS handling
			),
			'sandbox_secret_key' => array(
				'title'       => __('Sandbox Private Key', 'dfinsell-payment-gateway'),
				'type'        => 'text',
				'description' => __('Get your API keys from your merchant account: Account Settings > API Keys.', 'dfinsell-payment-gateway'),
				'default'     => '',
				'desc_tip'    => true,
				'class'       => 'dfinsell-sandbox-keys', // Add class for JS handling
			),
			'public_key' => array(
				'title' => __('Public Key', 'dfinsell-payment-gateway'),
				'type' => 'text',
				'default' => '',
				'desc_tip' => __('Enter your Public Key obtained from your merchant account.', 'dfinsell-payment-gateway'),
				'class'       => 'dfinsell-production-keys', // Add class for JS handling
			),
			'secret_key' => array(
				'title' => __('Secret Key', 'dfinsell-payment-gateway'),
				'type' => 'text',
				'default' => '',
				'desc_tip' => __('Enter your Secret Key obtained from your merchant account.', 'dfinsell-payment-gateway'),
				'class'       => 'dfinsell-production-keys', // Add class for JS handling
			),
			*/
			'accounts' => [
				'title' => __('Accounts', 'dfinsell-payment-gateway'),
				'type' => 'dfinsell_accounts', // Custom field type
				'description' => __('Add multiple accounts with sandbox and live API keys.', 'dfinsell-payment-gateway'),
				'default' => [],
				//'html' => $this->get_key_pair_html('sandbox'),
			],
		
			'order_status' => array(
				'title' => __('Order Status', 'dfinsell-payment-gateway'),
				'type' => 'select',
				'description' => __('Select the order status to be set after successful payment.', 'dfinsell-payment-gateway'),
				'default' => '', // Default is empty, which is our placeholder
				'desc_tip' => true,
				'id' => 'order_status_select', // Add an ID for targeting
				'options' => array(
					// '' => __('Select order status', 'dfinsell-payment-gateway'), // Placeholder option
					'processing' => __('Processing', 'dfinsell-payment-gateway'),
					'completed' => __('Completed', 'dfinsell-payment-gateway'),
				),
			),
			'show_consent_checkbox' => array(
				'title' => __('Show Consent Checkbox', 'dfinsell-payment-gateway'),
				'label' => __('Enable consent checkbox on checkout page', 'dfinsell-payment-gateway'),
				'type' => 'checkbox',
				'description' => __('Check this box to show the consent checkbox on the checkout page. Uncheck to hide it.', 'dfinsell-payment-gateway'),
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
		global $woocommerce;

		 // Prevent duplicate payment requests
		//  $payment_processing_key = "payment_processing_{$order_id}";
		//  if (get_transient($payment_processing_key)) {
		// 	 // Payment is already being processed, return immediately
		// 	 wc_add_notice(__('Payment is already being processed. Please wait.', 'dfinsell-payment-gateway'), 'error');
		// 	 return array('result' => 'fail');
		//  }
	 
		//  // Set the transient to lock the payment process for 5 minutes
		//  set_transient($payment_processing_key, 'processing', 5 * MINUTE_IN_SECONDS);

		// Ensure the 'REMOTE_ADDR' is set and then unslash it to remove any slashes
		if (isset($_SERVER['REMOTE_ADDR'])) {
			$ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])); // Unsheath the value
		} else {
			$ip_address = ''; // Fallback if REMOTE_ADDR is not set
		}

		// Validate the IP address format (IPv4 or IPv6)
		if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
			$ip_address = 'invalid'; // Or handle it however you see fit
		}

		// Rate-limiting configuration
		$window_size = 30; // 30 seconds
		$max_requests = 5;  // Max 5 requests in the last 30 seconds

		// Get the current timestamp
		$timestamp = time();

		// Retrieve stored timestamps of previous requests
		$timestamp_key = "rate_limit_{$ip_address}_timestamps";
		$request_timestamps = get_transient($timestamp_key);

		if (!$request_timestamps) {
			$request_timestamps = [];
		}

		// Remove timestamps older than the last $window_size seconds
		$request_timestamps = array_filter($request_timestamps, function ($ts) use ($timestamp, $window_size) {
			return ($timestamp - $ts <= $window_size);
		});

		// Count the requests within the window
		$request_count = count($request_timestamps);

		// If request count exceeds limit, block the user
		if ($request_count >= $max_requests) {
			wc_add_notice(__('You are sending too many requests. Please try again later.', 'dfinsell-payment-gateway'), 'error');
			return array('result' => 'fail');
		}

		// Add the current timestamp to the request list
		$request_timestamps[] = $timestamp;
		set_transient($timestamp_key, $request_timestamps, $window_size); // Store for $window_size seconds

		// Log suspicious activity
		if ($request_count >= $max_requests - 1) {
			wc_get_logger()->info('Suspicious activity detected from IP: ' . $ip_address, array('source' => 'dfinsell-payment-gateway'));
		}

		// Validate and sanitize order ID
		$order = wc_get_order($order_id);
		if (!$order) {
			wc_add_notice(__('Invalid order. Please try again.', 'dfinsell-payment-gateway'), 'error');
			return;
		}

		// Check if sandbox mode is enabled
		if ($this->sandbox) {
			  // Get existing order notes
			  $args = [
				'post_id' => $order->get_id(),
				'approve' => 'approve',
				'type'    => 'order_note',
			];
			$notes = get_comments($args);
		
			// Check if the note already exists
			$note_exists = false;
			foreach ($notes as $note) {
				if ($note->comment_content === __('This is a test order in sandbox mode.', 'dfinsell-payment-gateway')) {
					$note_exists = true;
					break;
				}
			}
		
			// Add the meta field and note only if it doesn't already exist
			if (!$note_exists) {
				$order->update_meta_data('_is_test_order', true);
				$order->add_order_note(__('This is a test order in sandbox mode.', 'dfinsell-payment-gateway'));
			}
		}

		   // Get the current account
		   $account = $this->get_next_account();
		 $public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
		 $secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
			// Prepare data for the API request
		$data = $this->dfinsell_prepare_payment_data($order,$public_key,$secret_key );
		$transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');

		// Send the data to the API
		$transaction_limit_response = wp_remote_post($transactionLimitApiUrl, array(
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => $data,
			'headers'   => array(
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
			),
			'sslverify' => true, // Ensure SSL verification
		));

		$transaction_limit_response_body = wp_remote_retrieve_body($transaction_limit_response);

		$transaction_limit_response_data = json_decode($transaction_limit_response_body, true);

		if (isset($transaction_limit_response_data['error'])) {
			// Display error message to the user
			wc_add_notice(
				__('Payment error: ', 'dfinsell-payment-gateway') . "Dfin Sell payment method is currently unavailable. Please contact support for assistance.",
				'error'
			);

			return array('result' => 'fail');
		}

		$apiPath = '/api/request-payment';

		// Concatenate the base URL and path
		$url = $this->sip_protocol . $this->sip_host . $apiPath;

		// Remove any double slashes in the URL except for the 'http://' or 'https://'
		$cleanUrl = esc_url(preg_replace('#(?<!:)//+#', '/', $url));

		$order->update_meta_data('_order_origin', 'dfin_sell_payment_gateway');
		$order->save();

		wc_get_logger()->info('DFin Sell Payment Request: ' . wp_json_encode($data), array('source' => 'dfin_sell_payment_gateway'));

		// Send the data to the API
		$response = wp_remote_post($cleanUrl, array(
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => $data,
			'headers'   => array(
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
			),
			'sslverify' => true, // Ensure SSL verification
		));

		// Log the essential response data
		if (is_wp_error($response)) {
			// Log the error message
			wc_get_logger()->error('DFin Sell Payment Request Error: ' . $response->get_error_message(), array('source' => 'dfin_sell_payment_gateway'));
			wc_add_notice(__('Payment error: Unable to process payment.', 'dfinsell-payment-gateway') . ' ' . $response->get_error_message(), 'error');
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
			$order->update_status('pending', __('Payment pending.', 'dfinsell-payment-gateway'));

			// Check if the note already exists
			$existing_notes = $order->get_customer_order_notes();
			$new_note = __('Payment initiated via DFin Sell Payment Gateway. Awaiting customer action.', 'dfinsell-payment-gateway');

			// Check if the note already exists
			$note_exists = false;
			foreach ($existing_notes as $note) {
				if (trim(wp_strip_all_tags($note->comment_content)) === trim($new_note)) {
					$note_exists = true;
					break;
				}
			}

			// Add the note if it doesn't exist
			if (!$note_exists) {
				// Add the order note as private so it doesn't show to customers
				$order->add_order_note(
					$new_note,        // The content of the note
					false,            // Private note, will not be shown to customers
					true              // Mark as private
				);
			}

			// Return a success result without redirecting
			return array(
				'payment_link' => esc_url($response_data['data']['payment_link']),
				'result'   => 'success',
			);
		} else {
			// Handle API error response
			if (isset($response_data['status']) && $response_data['status'] === 'error') {
				// Initialize an error message
				$error_message = isset($response_data['message']) ? sanitize_text_field($response_data['message']) : __('Unable to retrieve payment link.', 'dfinsell-payment-gateway');

				// Check if there are validation errors and handle them
				if (isset($response_data['errors']) && is_array($response_data['errors'])) {
					// Loop through the errors and format them into a user-friendly message
					foreach ($response_data['errors'] as $field => $field_errors) {
						foreach ($field_errors as $error) {
							// Append only the error message without the field name
							$error_message .= ' : ' . sanitize_text_field($error);
						}
					}
				}

				// Add the error message to WooCommerce notices
				wc_add_notice(__('Payment error: ', 'dfinsell-payment-gateway') . $error_message, 'error');

				return array('result' => 'fail');
			} else {
				// Add the error message to WooCommerce notices
				wc_add_notice(__('Payment error: ', 'dfinsell-payment-gateway') . $response_data['error'], 'error');
				return array('result' => 'fail');
			}
		}

		 // After payment process is complete (success or fail), remove the lock
		 //delete_transient($payment_processing_key);
	}

	// Display the "Test Order" tag in admin order details
	public function dfinsell_display_test_order_tag($order)
	{
		if (get_post_meta($order->get_id(), '_is_test_order', true)) {
			echo '<p><strong>' . esc_html__('Test Order', 'dfinsell-payment-gateway') . '</strong></p>';
		}
	}


	private function dfinsell_check_api_keys() {
		// Retrieve the accounts from the settings
		$accounts = $this->get_option('accounts', []);
	
		// Check if sandbox mode is enabled
		$is_sandbox = $this->get_option('sandbox') === 'yes';
	
		// Initialize an array to store validation errors
		$errors = [];
	
		// Loop through each account and validate its keys
		foreach ($accounts as $index => $account) {
			// Determine which keys to check based on sandbox mode
			$public_key = $is_sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
			$secret_key = $is_sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
	
			// Validate the keys for the current account
			if (isset($public_key)) {
				$errors[] = sprintf(__('Secret Key is required for Account %d. Please enter your Secret Key.', 'dfinsell-payment-gateway'), $index + 1);
			}
			if (isset($secret_key)) {
				$errors[] = sprintf(__('Secret Key is required for Account %d. Please enter your Secret Key.', 'dfinsell-payment-gateway'), $index + 1);
			}
		}
	
		// If there are validation errors, return them as a single string
		if (!empty($errors)) {
			return implode('<br>', $errors);
		}
	
		// If no errors, return an empty string
		return '';
	}
	
	/*private function dfinsell_check_api_keys()
	{
		// Check if sandbox mode is enabled
		$is_sandbox = $this->get_option('sandbox') === 'yes';

		$secret_key = $is_sandbox ? sanitize_text_field($this->get_option('sandbox_secret_key')) : sanitize_text_field($this->get_option('secret_key'));
		$public_key = $is_sandbox ? sanitize_text_field($this->get_option('sandbox_public_key')) : sanitize_text_field($this->get_option('public_key'));

		// This method should only be called if no other errors exist
		if (empty($public_key) && empty($secret_key)) {
			return __('Both Public Key and Secret Key are required. Please enter them in the settings.', 'dfinsell-payment-gateway');
		} elseif (empty($public_key)) {
			return __('Public Key is required. Please enter your Public Key in the settings.', 'dfinsell-payment-gateway');
		} elseif (empty($secret_key)) {
			return __('Secret Key is required. Please enter your Secret Key in the settings.', 'dfinsell-payment-gateway');
		}
		return '';
	}

	*/


	private function dfinsell_get_return_url_base()
	{
		return rest_url('/dfinsell/v1/data');
	}

	private function dfinsell_prepare_payment_data($order, $api_public_key, $api_secret)
	{
		$order_id = $order->get_id(); // Validate order ID
		// Check if sandbox mode is enabled
		$is_sandbox = $this->get_option('sandbox') === 'yes';
		
		// Sanitize and get the billing email or phone
		$request_for = sanitize_email($order->get_billing_email() ?: $order->get_billing_phone());
		// Get order details and sanitize
		$first_name = sanitize_text_field($order->get_billing_first_name());
		$last_name = sanitize_text_field($order->get_billing_last_name());
		$amount = number_format($order->get_total(), 2, '.', '');

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
					'order_id' => $order_id, // Include order ID or any other identifier
					'key' => $order->get_order_key(),
					'nonce' => wp_create_nonce('dfin_sell_payment_nonce'), // Create a nonce for verification
					'mode' => 'wp',
				),
				$this->dfinsell_get_return_url_base() // Use the updated base URL method
			)
		);

		$ip_address = sanitize_text_field($this->dfinsell_get_client_ip());

		if (empty($order_id)) {
			wc_get_logger()->error('Order ID is missing or invalid.', array('source' => 'dfinsell-payment-gateway'));
			return array('result' => 'fail');
		}

		// Create the meta data array
		$meta_data_array = array(
			'order_id' => $order_id,
			'amount' => $amount,
			'source' => 'woocommerce',
		);
	
		// Log errors but continue processing
		foreach ($meta_data_array as $key => $value) {
			$meta_data_array[$key] = sanitize_text_field($value); // Sanitize each field
			if (is_object($value) || is_resource($value)) {
				wc_get_logger()->error(
					'Invalid value for key ' . $key . ': ' . wp_json_encode($value),
					array('source' => 'dfinsell-payment-gateway')
				);
			}
		}
	

		return array(
			'api_secret'       => $api_secret, // Use sandbox or live secret key
			'api_public_key'   => $api_public_key, // Add the public key for API calls
			'first_name' => $first_name,
			'last_name' => $last_name,
			'request_for' => $request_for,
			'amount' => $amount,
			'redirect_url' => $redirect_url,
			'redirect_time' => 3,
			'ip_address' => $ip_address,
			'source' => 'wordpress',
			'meta_data' => $meta_data_array,
			'remarks' => 'Order ' . $order->get_order_number(),
			// Add billing address details to the request
			'billing_address_1' => $billing_address_1,
			'billing_address_2' => $billing_address_2,
			'billing_city' => $billing_city,
			'billing_postcode' => $billing_postcode,
			'billing_country' => $billing_country,
			'billing_state' => $billing_state,
			'is_sandbox' => $is_sandbox,
		);
	

	}

	// Helper function to get client IP address
	private function dfinsell_get_client_ip()
	{
		$ip = '';

		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			// Sanitize the client's IP directly on $_SERVER access
			$ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// Sanitize and handle multiple proxies
			$ip_list = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
			$ip = trim($ip_list[0]); // Take the first IP in the list and trim any whitespace
		} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
			// Sanitize the remote address directly
			$ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
		}

		// Validate the IP after retrieving it
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
	}


	/**
	 * Add a custom label next to the order status in the order list.
	 *
	 * @param array $line_items The order line items array.
	 * @param WC_Order $order The WooCommerce order object.
	 * @return array Modified line items array.
	 */
	public function dfinsell_add_custom_label_to_order_row($line_items, $order)
	{
		// Get the custom meta field value (e.g. '_order_origin')
		$order_origin = $order->get_meta('_order_origin');

		// Check if the meta exists and has value
		if (!empty($order_origin)) {
			// Add the label text to the first item in the order preview
			$line_items[0]['name'] .= ' <span style="background-color: #ffeb3b; color: #000; padding: 3px 5px; border-radius: 3px; font-size: 12px;">' . esc_html($order_origin) . '</span>';
		}

		return $line_items;
	}

	/**
	 * WooCommerce not active notice.
	 */
	public function dfinsell_woocommerce_not_active_notice()
	{
		echo '<div class="error">
        <p>' . esc_html__('DFin Sell Payment Gateway requires WooCommerce to be installed and active.', 'dfinsell-payment-gateway') . '</p>
    </div>';
	}

	/**
	 * Payment form on checkout page.
	 */
	public function payment_fields()
	{
		$description = $this->get_option('description');

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
                    <input type="checkbox" id="dfinsell_consent" name="dfinsell_consent" /> ' . esc_html__('I consent to the collection of my data to process this payment', 'dfinsell-payment-gateway') . '
                </label>
            </p>';

			// Add nonce field for security
			wp_nonce_field('dfinsell_payment', 'dfinsell_nonce');
		}
	}

	/**
	 * Validate the payment form.
	 */
	public function validate_fields()
	{
		// Check if the consent checkbox setting is enabled
		if ($this->get_option('show_consent_checkbox') === 'yes') {

			// Sanitize and validate the nonce field
			$nonce = isset($_POST['dfinsell_nonce']) ? sanitize_text_field(wp_unslash($_POST['dfinsell_nonce'])) : '';
			if (empty($nonce) || !wp_verify_nonce($nonce, 'dfinsell_payment')) {
				wc_add_notice(__('Nonce verification failed. Please try again.', 'dfinsell-payment-gateway'), 'error');
				return false;
			}

			// Sanitize the consent checkbox input
			$consent = isset($_POST['dfinsell_consent']) ? sanitize_text_field(wp_unslash($_POST['dfinsell_consent'])) : '';

			// Validate the consent checkbox was checked
			if ($consent !== 'on') {
				wc_add_notice(__('You must consent to the collection of your data to process this payment.', 'dfinsell-payment-gateway'), 'error');
				return false;
			}
		}

		return true;
	}


	/**
	 * Enqueue stylesheets for the plugin.
	 */
	public function dfinsell_enqueue_styles_and_scripts()
	{
		if (is_checkout()) {
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
				'dfinsell_nonce' => wp_create_nonce('dfinsell_payment'), // Create a nonce for verification
				'payment_method' => $this->id,
			));
		}
	}

	function dfinsell_admin_scripts($hook)
	{
		if ('woocommerce_page_wc-settings' !== $hook) {
			return; // Only load on WooCommerce settings page
		}
		error_log('Enqueuing admin scripts and styles'); // Debugging
		// Register and enqueue your script
		wp_enqueue_script('dfinsell-admin-script', plugins_url('../assets/js/dfinsell-admin.js', __FILE__), array('jquery'), filemtime(plugin_dir_path(__FILE__) . '../assets/js/dfinsell-admin.js'), true);

		// Localize the script to pass parameters
		wp_localize_script('dfinsell-admin-script', 'params', array(
			'PAYMENT_CODE' => $this->id
		));
	}
	public function hide_custom_payment_gateway_conditionally($available_gateways) {
		$gateway_id = self::ID;
	
		if (is_checkout() && WC()->cart) {
			$amount = number_format(WC()->cart->get_total('edit'), 2, '.', '');
	
			// Get the current account
			$account = $this->get_next_account();
			
			$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
	
			$transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');
	
			$data = [
				'is_sandbox' => $this->sandbox,
				'amount'     => $amount,
				'api_public_key' => $public_key,
			];
	
			// Use cached API response
			$cache_key = 'dfinsell_daily_limit_' . md5($public_key . $amount);
			$transaction_limit_response_data = $this->get_cached_api_response($transactionLimitApiUrl, $data, $cache_key);
	
			if (isset($transaction_limit_response_data['error'])) {
				dfinsell_log('error', 'API returned an error: ' . $transaction_limit_response_data['error']);
				unset($available_gateways[$gateway_id]);
			}
		}
	
		return $available_gateways;
	}




public function render_key_pairs_field($data)
{
	error_log('Rendering key pairs field for mode: ' . $field['id']); // Debugging
    // Ensure the 'id' key exists in the $data array
    if (!isset($data['id'])) {
        error_log('Missing "id" key in $data array'); // Debugging
        return;
    }

    $mode = str_replace('_keys', '', $data['id']); // Extract mode (sandbox or live)
    $key_pairs = $this->get_option($data['id'], []); // Retrieve saved key pairs
	echo $this->get_key_pair_html($mode);
 
}

private function get_key_pair_html($mode)
{
	error_log('get_key_pair_html'); // Debugging
	
	ob_start();
    ?>
<div class="dfinsell-accounts-container">
    <div class="dfinsell-account-template">
        <h4><?php echo esc_html(__('Account 1', 'dfinsell-payment-gateway')); ?></h4>
        <input type="text" 
               name="accounts[0][title]" 
               placeholder="<?php echo esc_attr(__('Account Title', 'dfinsell-payment-gateway')); ?>">

        <h5><?php echo esc_html(__('Sandbox Keys', 'dfinsell-payment-gateway')); ?></h5>
        <input type="text" 
               name="accounts[0][sandbox_public_key]" 
               placeholder="<?php echo esc_attr(__('Sandbox Public Key', 'dfinsell-payment-gateway')); ?>">
        <input type="text" 
               name="accounts[0][sandbox_secret_key]" 
               placeholder="<?php echo esc_attr(__('Sandbox Secret Key', 'dfinsell-payment-gateway')); ?>">

        <h5><?php echo esc_html(__('Live Keys', 'dfinsell-payment-gateway')); ?></h5>
        <input type="text" 
               name="accounts[0][live_public_key]" 
               placeholder="<?php echo esc_attr(__('Live Public Key', 'dfinsell-payment-gateway')); ?>">
        <input type="text" 
               name="accounts[0][live_secret_key]" 
               placeholder="<?php echo esc_attr(__('Live Secret Key', 'dfinsell-payment-gateway')); ?>">

        <button class="button dfinsell-remove-account">Remove</button>
    </div>
    <button class="button dfinsell-add-account">Add Account</button>
</div>
    <?php
    return ob_get_clean();
}

public function generate_dfinsell_accounts_html($key, $data) {
    $field_key = $this->get_field_key($key);
    $defaults = array(
        'title' => '',
        'type' => 'text',
        'id' => $key,
    );
    $data = wp_parse_args($data, $defaults);

    ob_start();
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
        </th>
        <td class="forminp">
            <?php echo $this->render_accounts_field($data); ?>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

public function render_accounts_field($data) {
    $accounts = $this->get_option('accounts', []);
    if (empty($accounts)) {
        $accounts = [
            [
                'title' => '',
                'sandbox_public_key' => '',
                'sandbox_secret_key' => '',
                'live_public_key' => '',
                'live_secret_key' => '',
            ],
        ];
    }

    ob_start();
    ?>
    <div class="dfinsell-accounts-container">
        <?php foreach ($accounts as $index => $account) : ?>
            <div class="dfinsell-account">
                <h4><?php echo esc_html(sprintf(__('Account %d', 'dfinsell-payment-gateway'), $index + 1)); ?></h4>
                <input type="text" 
                       name="accounts[<?php echo esc_attr($index); ?>][title]" 
                       placeholder="<?php echo esc_attr(__('Account Title', 'dfinsell-payment-gateway')); ?>" 
                       value="<?php echo esc_attr($account['title']); ?>">

                <h5><?php echo esc_html(__('Sandbox Keys', 'dfinsell-payment-gateway')); ?></h5>
                <input type="text" 
                       name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]" 
                       placeholder="<?php echo esc_attr(__('Sandbox Public Key', 'dfinsell-payment-gateway')); ?>" 
                       value="<?php echo esc_attr($account['sandbox_public_key']); ?>">
                <input type="text" 
                       name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]" 
                       placeholder="<?php echo esc_attr(__('Sandbox Secret Key', 'dfinsell-payment-gateway')); ?>" 
                       value="<?php echo esc_attr($account['sandbox_secret_key']); ?>">

                <h5><?php echo esc_html(__('Live Keys', 'dfinsell-payment-gateway')); ?></h5>
                <input type="text" 
                       name="accounts[<?php echo esc_attr($index); ?>][live_public_key]" 
                       placeholder="<?php echo esc_attr(__('Live Public Key', 'dfinsell-payment-gateway')); ?>" 
                       value="<?php echo esc_attr($account['live_public_key']); ?>">
                <input type="text" 
                       name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]" 
                       placeholder="<?php echo esc_attr(__('Live Secret Key', 'dfinsell-payment-gateway')); ?>" 
                       value="<?php echo esc_attr($account['live_secret_key']); ?>">

                <button class="button dfinsell-remove-account">Remove</button>
            </div>
        <?php endforeach; ?>
        <button class="button dfinsell-add-account">Add Account</button>
    </div>
    <?php
    return ob_get_clean();
}


/**
 * Validate an individual account.
 *
 * @param array $account The account data to validate.
 * @param int $index The index of the account (for error messages).
 * @return bool|string True if valid, error message if invalid.
 */
protected function validate_account($account, $index) {
    $is_empty = empty($account['title']) && empty($account['sandbox_public_key']) && empty($account['sandbox_secret_key']) && empty($account['live_public_key']) && empty($account['live_secret_key']);
    $is_filled = !empty($account['title']) && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']) && !empty($account['live_public_key']) && !empty($account['live_secret_key']);

    if (!$is_empty && !$is_filled) {
        return sprintf(__('Account %d is invalid. Please fill all fields or leave the account empty.', 'dfinsell-payment-gateway'), $index + 1);
    }

    return true;
}

/**
 * Validate all accounts.
 *
 * @param array $accounts The list of accounts to validate.
 * @return bool|string True if valid, error message if invalid.
 */
protected function validate_accounts($accounts) {
    $valid_accounts = [];
    $errors = [];

    foreach ($accounts as $index => $account) {
        // Check if the account is completely empty
        $is_empty = empty($account['title']) && empty($account['sandbox_public_key']) && empty($account['sandbox_secret_key']) && empty($account['live_public_key']) && empty($account['live_secret_key']);

        // Check if the account is completely filled
        $is_filled = !empty($account['title']) && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']) && !empty($account['live_public_key']) && !empty($account['live_secret_key']);

        // If the account is neither empty nor fully filled, it's invalid
        if (!$is_empty && !$is_filled) {
            $errors[] = sprintf(__('Account %d is invalid. Please fill all fields or leave the account empty.', 'dfinsell-payment-gateway'), $index + 1);
        } elseif ($is_filled) {
            // If the account is fully filled, add it to the valid accounts array
            $valid_accounts[] = $account;
        }
    }

    // If there are validation errors, return them
    if (!empty($errors)) {
        return ['errors' => $errors, 'valid_accounts' => $valid_accounts];
    }

    // If no errors, return the valid accounts
    return ['valid_accounts' => $valid_accounts];
}

/**
 * Sanitize account data.
 *
 * @param array $accounts The list of accounts to sanitize.
 * @return array The sanitized accounts.
 */
protected function sanitize_accounts($accounts) {
    $sanitized_accounts = [];

    foreach ($accounts as $account) {
        if (!empty($account['title']) && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']) && !empty($account['live_public_key']) && !empty($account['live_secret_key'])) {
            $sanitized_accounts[] = [
                'title' => sanitize_text_field($account['title']),
                'sandbox_public_key' => sanitize_text_field($account['sandbox_public_key']),
                'sandbox_secret_key' => sanitize_text_field($account['sandbox_secret_key']),
                'live_public_key' => sanitize_text_field($account['live_public_key']),
                'live_secret_key' => sanitize_text_field($account['live_secret_key']),
            ];
        }
    }

    return $sanitized_accounts;
}


private function get_next_account() {
    $accounts = $this->get_option('accounts', array());
    $current_account_index = get_transient('dfinsell_current_account_index');

    if ($current_account_index === false || $current_account_index >= count($accounts) - 1) {
        $current_account_index = 0; // Reset to the first account
    } else {
        $current_account_index++; // Move to the next account
    }

	
    // Save the current account index
    set_transient('dfinsell_current_account_index', $current_account_index, DAY_IN_SECONDS);

    return $accounts[$current_account_index];
}

private function get_cached_api_response($url, $data, $cache_key) {
    // Check if the response is already cached
    $cached_response = get_transient($cache_key);

    if ($cached_response !== false) {
        return $cached_response;
    }

    // Make the API call
    $response = wp_remote_post($url, array(
        'method'    => 'POST',
        'timeout'   => 30,
        'body'      => $data,
        'headers'   => array(
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Authorization' => 'Bearer ' . $data['api_public_key'],
        ),
        'sslverify' => true,
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $response_body = wp_remote_retrieve_body($response);
    $response_data = json_decode($response_body, true);

    // Cache the response for 5 minutes
    set_transient($cache_key, $response_data, 5 * MINUTE_IN_SECONDS);

    return $response_data;
}


}

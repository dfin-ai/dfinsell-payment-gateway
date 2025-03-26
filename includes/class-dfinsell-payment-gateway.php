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
	private $accounts = [];
	private $current_account_index = 0;
	private $used_accounts = [];

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

		$this->accounts = $this->get_option('accounts', array());
        $this->current_account_index = 0;
		
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
		add_action('woocommerce_init', [$this, 'reset_account_statuses_if_needed']);
		//add_action('admin_enqueue_scripts', 'dfinsell_enqueue_admin_styles');
	
	}

	private function get_api_url($endpoint)
	{
		return $this->sip_protocol . $this->sip_host . $endpoint;
	}

	public function dfinsell_process_admin_options() {
		if (!isset($_POST['dfinsell_secure_nonce']) || 
			!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dfinsell_secure_nonce'])), 'dfinsell_secure_action')) {
			wp_die(esc_html__('Security check failed. Please refresh the page and try again.', 'dfinsell-payment-gateway'));
		}
	
		parent::process_admin_options();
	
		// Retrieve existing settings
		$existing_settings = get_option('woocommerce_dfinsell_settings', []);
		$existing_accounts = isset($existing_settings['accounts']) ? $existing_settings['accounts'] : [];

		$new_accounts = [];
	
		if (!empty($_POST['accounts']) && is_array($_POST['accounts'])) {
			$raw_accounts = wp_unslash($_POST['accounts']);
			$validated_accounts = $this->validate_accounts($raw_accounts);
	
			if (!empty($validated_accounts['errors'])) {
				foreach ($validated_accounts['errors'] as $error) {
					$this->admin_notices->dfinsell_add_notice('settings_error', 'error', $error);
				}
				add_action('admin_notices', [$this->admin_notices, 'display_notices']);
			} else {
				$new_accounts = $this->sanitize_accounts($validated_accounts['valid_accounts']);
			}
		}
	
		// Merge existing and new accounts
		$all_accounts = is_array($existing_accounts) ? array_merge($existing_accounts, $new_accounts) : $new_accounts;

		// Validate all accounts
		$validation_result = $this->validate_accounts($all_accounts);
		if (!empty($validation_result['errors'])) {
			foreach ($validation_result['errors'] as $error) {
				$this->admin_notices->dfinsell_add_notice('settings_error', 'error', $error);
			}
			add_action('admin_notices', [$this->admin_notices, 'display_notices']);
			return;
		}
	
		// Call update_account_statuses to handle saving and setting the first account active if needed
		$this->update_account_statuses($all_accounts);
	}
	


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
			'sandbox' => array(
				'title'       => __('Sandbox', 'dfinsell-payment-gateway'),
				'label'       => __('Enable Sandbox Mode', 'dfinsell-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).', 'dfinsell-payment-gateway'),
				'default'     => 'no',
			),
			/*'sandbox_public_key'  => array(
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

	// Ensure the 'REMOTE_ADDR' is set and then unslash it to remove any slashes
	if (isset($_SERVER['REMOTE_ADDR'])) {
		$ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])); // Unsheath the value
	} else {
		$ip_address = ''; // Fallback if REMOTE_ADDR is not set
	}
    if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
        $ip_address = 'invalid';
    }

    // Rate limiting
    $window_size = 30; // 30 seconds
    $max_requests = 5;
    $timestamp = time();
    $timestamp_key = "rate_limit_{$ip_address}_timestamps";
    $request_timestamps = get_transient($timestamp_key) ?: [];
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
	// Get the currently active account
    $account = $this->get_current_active_account();
    if (!$account) {
        wc_add_notice(__('No payment accounts found.', 'dfinsell-payment-gateway'), 'error');
        return ['result' => 'fail'];
    }
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


    $public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
    $secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];

    $data = $this->dfinsell_prepare_payment_data($order, $public_key, $secret_key);
    $transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');

    $transaction_limit_response = wp_remote_post($transactionLimitApiUrl, [
        'method'    => 'POST',
        'timeout'   => 30,
        'body'      => $data,
        'headers'   => [
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
        ],
        'sslverify' => true,
    ]);

    $transaction_limit_response_body = wp_remote_retrieve_body($transaction_limit_response);
    $transaction_limit_response_data = json_decode($transaction_limit_response_body, true);

    if (isset($transaction_limit_response_data['error'])) {
        wc_get_logger()->error('Account limit reached: ' . $transaction_limit_response_data['error'], ['source' => 'dfin_sell_payment_gateway']);

        if ($this->switch_account()) {
            return $this->process_payment($order_id);
        } else {
            wc_add_notice(__('All accounts reached the limit.', 'dfinsell-payment-gateway'), 'error');
            return ['result' => 'fail'];
        }
    }

    $apiPath = '/api/request-payment';
    $url = esc_url(preg_replace('#(?<!:)//+#', '/', $this->sip_protocol . $this->sip_host . $apiPath));

    $order->update_meta_data('_order_origin', 'dfin_sell_payment_gateway');
    $order->save();
	wc_get_logger()->info('DFin Sell Payment Request: ' . wp_json_encode($data), array('source' => 'dfin_sell_payment_gateway'));

    $response = wp_remote_post($url, [
        'method'    => 'POST',
        'timeout'   => 30,
        'body'      => $data,
        'headers'   => [
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
        ],
        'sslverify' => true,
    ]);

    if (is_wp_error($response)) {
        wc_get_logger()->error('DFin Sell Payment Request Error: ' . $response->get_error_message(), array('source' => 'dfin_sell_payment_gateway'));
		wc_add_notice(__('Payment error: Unable to process.', 'dfinsell-payment-gateway'), 'error');
        return ['result' => 'fail'];
    }
	$response_code = wp_remote_retrieve_response_code($response);
	$response_body = wp_remote_retrieve_body($response);
   			// Log the response code and body
			   wc_get_logger()->info(
				sprintf('DFin Sell Payment Response: Code: %d, Body: %s', $response_code, $response_body),
				array('source' => 'dfin_sell_payment_gateway')
			);
	$response_data = json_decode($response_body, true);
	

    if (
        isset($response_data['status']) && $response_data['status'] === 'success' &&
        isset($response_data['data']['payment_link']) && !empty($response_data['data']['payment_link'])
    ) {
		$order->update_status('pending', __('Payment pending.', 'dfinsell-payment-gateway'));

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

        return [
            'payment_link' => esc_url($response_data['data']['payment_link']),
            'result'   => 'success',
        ];
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
		$is_sandbox =  $this->get_option('sandbox') === 'yes';

	
		// Initialize an array to store validation errors
		$errors = [];
	
		// Loop through each account and validate its keys
		foreach ($accounts as $index => $account) {
			// Determine which keys to check based on sandbox mode
			$public_key = $is_sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
			$secret_key = $is_sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
	
			// Validate the keys for the current account
			
			if (isset($public_key)) {
				/* Translators: %d is the Account Number Pubic key*/
				$errors[] = sprintf(__('Public  Key is required for Account %d. Please enter your Public ret Key.', 'dfinsell-payment-gateway'), $index + 1);
			}
		
			if (isset($secret_key)) {
					/* Translators: %d is the Account Number for secret key.*/
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
		$is_sandbox =  $this->get_option('sandbox') === 'yes';

		
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
	
		// Register and enqueue your script
		wp_enqueue_script('dfinsell-admin-script', plugins_url('../assets/js/dfinsell-admin.js', __FILE__), array('jquery'), filemtime(plugin_dir_path(__FILE__) . '../assets/js/dfinsell-admin.js'), true);

		wp_enqueue_style(
			'dfinsell-admin-style',
			plugins_url('assets/css/admin-style.css', __DIR__), // This ensures correct path
			[],
			'1.0.0'
		);
		
		// Localize the script to pass parameters
		wp_localize_script('dfinsell-admin-script', 'params', array(
			'PAYMENT_CODE' => $this->id
		));
	}
	public function hide_custom_payment_gateway_conditionally($available_gateways) {
		$gateway_id = self::ID;
	
		if (is_checkout() && WC()->cart) {
			$amount = number_format(WC()->cart->get_total('edit'), 2, '.', '');
	
			if (!method_exists($this, 'get_all_accounts')) {
				wc_get_logger()->error('Method get_all_accounts() is missing!', ['source' => 'dfin_sell_payment_gateway']);
				return $available_gateways;
			}
	
			$accounts = $this->get_all_accounts();
			if (empty($accounts)) {
				wc_get_logger()->warning('No accounts available.', ['source' => 'dfin_sell_payment_gateway']);
				return $available_gateways;
			}
	
			$all_accounts_limited = true;
	
			foreach ($accounts as $account) {
				if ($account['status'] === 'true') {
					$active_account = $account;
				}
				$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
				$transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');
	
				$data = [
					'is_sandbox' => $this->sandbox,
					'amount' => $amount,
					'api_public_key' => $public_key,
				];
	
				$cache_key = 'dfinsell_daily_limit_' . md5($public_key . $amount);
				$transaction_limit_response_data = $this->get_cached_api_response($transactionLimitApiUrl, $data, $cache_key);
	
				if (!isset($transaction_limit_response_data['error'])) {
					$all_accounts_limited = false;
					break;
				}
			}
	
			if ($all_accounts_limited) {
				// Prevent multiple logs per request by storing in WooCommerce session
				if (!WC()->session->get('dfin_gateway_hidden_logged')) {
					wc_get_logger()->warning('All accounts have exceeded transaction limits. Hiding gateway.', ['source' => 'dfin_sell_payment_gateway']);
					WC()->session->set('dfin_gateway_hidden_logged', true);
				}
				unset($available_gateways[$gateway_id]);
			} else {
				WC()->session->set('dfin_gateway_hidden_logged', false); // Reset if at least one account is available
			}
		}
	
		return $available_gateways;
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
			<?php echo wp_kses($this->render_accounts_field($data), $this->allowed_html_tags()); ?>
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
			<div class="dfinsell-account <?php echo isset($account['status']) && $account['status'] == 'true' ? 'active-account' : 'inactive-account'; ?>">
				<?php /* Translators: %d is the checking the status.*/?>
                <h4><?php echo esc_html(sprintf(__('Account %d', 'dfinsell-payment-gateway'), $index + 1)); ?> <?php 
    			  $is_active = !empty($account['status']) && $account['status'] == 'true';
					?>
				<?php if ($is_active) : ?>
					<span class="active-indicator"> Active</span>
				<?php endif; ?></h4>
				<input type="hidden" name="accounts[<?php echo $index; ?>][status]" value="<?php echo esc_attr($account['status'] ?? 'false'); ?>">

                <input type="text"  class="account-title"
                       name="accounts[<?php echo esc_attr($index); ?>][title]" 
                       placeholder="<?php echo esc_attr(__('Account Title', 'dfinsell-payment-gateway')); ?>" 
                       value="<?php echo esc_attr($account['title']); ?>">

                <h5><?php echo esc_html(__('Sandbox Keys', 'dfinsell-payment-gateway')); ?></h5>
				<div class="add-blog">
                <input type="text" class="sandbox-public-key"
                       name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]" 
                       placeholder="<?php echo esc_attr(__('Public Key', 'dfinsell-payment-gateway')); ?>" 
                       value="<?php echo esc_attr($account['sandbox_public_key']); ?>">
                <input type="text"  class="sandbox-secret-key"
                       name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]" 
                       placeholder="<?php echo esc_attr(__('Private Key', 'dfinsell-payment-gateway')); ?>" 
                       value="<?php echo esc_attr($account['sandbox_secret_key']); ?>">
				</div>
                <h5><?php echo esc_html(__('Live Keys', 'dfinsell-payment-gateway')); ?></h5>
				<div class="add-blog">
                <input type="text"  class="live-public-key"
                       name="accounts[<?php echo esc_attr($index); ?>][live_public_key]" 
                       placeholder="<?php echo esc_attr(__('Public Key', 'dfinsell-payment-gateway')); ?>" 
                       value="<?php echo esc_attr($account['live_public_key']); ?>">
                <input type="text"  class="live-secret-key"
                       name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]" 
                       placeholder="<?php echo esc_attr(__('Private Key', 'dfinsell-payment-gateway')); ?>" 
                       value="<?php echo esc_attr($account['live_secret_key']); ?>">
					   <button class="button dfinsell-remove-account"><span>-</span></button>
				</div>
            </div>


			
        <?php endforeach; ?>
       
    </div>
	<div class="add-account-btn">
		<button class="button dfinsell-add-account"><span>+</span> Add Account</button>
	</div>
	<?php 
    // Add nonce inside the form HTML
    wp_nonce_field('dfinsell_secure_action', 'dfinsell_secure_nonce');
    ?>
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
			/* Translators: %d is the keys are valid or leave empty.*/
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
				/* Translators: %d is the keys are valid or leave empty.*/
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
    $has_active_account = false;
    $first_valid_account_index = null;

    // First loop: Identify if an active account exists and find the first valid account
    foreach ($accounts as $index => $account) {
        if (isset($account['status']) && $account['status'] === 'true') {
            $has_active_account = true; // An active account already exists
        }
        if ($first_valid_account_index === null && 
            !empty($account['title']) && 
            !empty($account['sandbox_public_key']) && 
            !empty($account['sandbox_secret_key']) && 
            !empty($account['live_public_key']) && 
            !empty($account['live_secret_key'])) {
            $first_valid_account_index = $index; // Store the first valid account
        }
    }

    // Second loop: Process each account while keeping status intact
    foreach ($accounts as $index => $account) {
        if (!empty($account['title']) && 
            !empty($account['sandbox_public_key']) && 
            !empty($account['sandbox_secret_key']) && 
            !empty($account['live_public_key']) && 
            !empty($account['live_secret_key'])) {

            // Preserve existing status, or activate first valid account if no active account exists
            if (isset($account['status']) && ($account['status'] === 'true' || $account['status'] === 'false')) {
                $active = $account['status']; // Keep status as is
            } else {
                $active = (!$has_active_account && $index === $first_valid_account_index) ? 'true' : 'false';
                if ($active === 'true') {
                    $has_active_account = true; // Mark that we have set an active account
                }
            }

            $sanitized_accounts[] = [
                'title' => sanitize_text_field($account['title']),
                'sandbox_public_key' => sanitize_text_field($account['sandbox_public_key']),
                'sandbox_secret_key' => sanitize_text_field($account['sandbox_secret_key']),
                'live_public_key' => sanitize_text_field($account['live_public_key']),
                'live_secret_key' => sanitize_text_field($account['live_secret_key']),
                'status' => $active, // Ensure only one account is activated if needed
            ];
        }
    }

    return $sanitized_accounts;
}



public function switch_account()
{
    $old_account = $this->get_current_active_account();
    if (!$old_account) {
        wc_get_logger()->error(
            'No active payment account found to switch from.',
            ['source' => 'dfin_sell_payment_gateway']
        );
        return false;
    }

    // Store used account title to prevent reuse
    $this->used_accounts[] = $old_account['title'];

    // Get the next available account (not yet used)
    $new_account = $this->get_next_account();

    if (!$new_account) {
        wc_get_logger()->error(
            'All payment accounts have reached their limit. No more accounts available.',
            ['source' => 'dfin_sell_payment_gateway']
        );
        wc_add_notice(
            __('Payment temporarily unavailable. Please try again later.', 'dfinsell-payment-gateway'),
            'error'
        );
        return false;
    }

    // Log why the switch is happening
    wc_get_logger()->info(
        sprintf(
            'Switching from account [%s] to [%s] due to reaching transaction limit.',
            $old_account['title'],
            $new_account['title']
        ),
        ['source' => 'dfin_sell_payment_gateway']
    );

    // Load existing WooCommerce settings
    $settings = get_option('woocommerce_dfinsell_settings', []);

    // Update account statuses
    foreach ($settings['accounts'] as &$account) {
        if ($account['title'] === $new_account['title']) {
            $account['status'] = "true"; // Activate this account
        } else {
            $account['status'] = "false"; // Deactivate other accounts
        }
    }

    // Save updated settings
    update_option('woocommerce_dfinsell_settings', $settings);

    // Log the successful switch
    wc_get_logger()->info(
        sprintf('Successfully switched to new account: [%s]', $new_account['title']),
        ['source' => 'dfin_sell_payment_gateway']
    );

    // Send an email notification when an account is switched
    $this->send_account_switch_email($old_account, $new_account);

    return true;
}


private function get_next_account()
{
    $available_accounts = array_filter($this->accounts, function ($account) {
        return !in_array($account['title'], $this->used_accounts);
    });

    if (empty($available_accounts)) {
        return false; // No accounts left to switch to
    }

    return reset($available_accounts); // Pick the first available account
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

    // Cache the response for 2 minutes
    set_transient($cache_key, $response_data, 2 * MINUTE_IN_SECONDS);

    return $response_data;
}

private function get_all_accounts() {
    return $this->accounts; // Assuming accounts are stored in this variable
}


public function reset_account_statuses() {
    $settings = get_option('woocommerce_dfinsell_settings', []);

    if (!isset($settings['accounts']) || empty($settings['accounts'])) {
        wc_get_logger()->warning('No accounts found in settings for reset.', ['source' => 'dfin_sell_payment_gateway']);
        return;
    }

    // Activate first account, deactivate others
    foreach ($settings['accounts'] as $index => &$account) {
        $account['status'] = ($index === 0) ? "true" : "false";
    }

    update_option('woocommerce_dfinsell_settings', $settings);
    wc_get_logger()->info('Daily reset: First account activated, others deactivated.', ['source' => 'dfin_sell_payment_gateway']);
}

public function reset_account_statuses_if_needed() {
    $last_reset = get_option('dfinsell_last_reset', '');
    $current_date = gmdate('Y-m-d'); // Use UTC date

    if ($last_reset !== $current_date) {
        $this->reset_account_statuses(); // Reset accounts
        update_option('dfinsell_last_reset', $current_date); // Update last reset date
    }
}


function dfinsell_enqueue_admin_styles($hook) {
    // Load only on WooCommerce settings pages
    if (strpos($hook, 'woocommerce') === false) {
        return;
    }

    wp_enqueue_style('dfinsell-admin-style', plugin_dir_url(__FILE__) . 'assets/css/admin-style.css', [], '1.0.0');
}

/**
 * Send an email notification via DfinSell API
	*/
	private function send_account_switch_email($oldAccount, $newAccount) {
		$dfinSellApiUrl = $this->get_api_url('/api/switch-account-email'); // Dfin Sell API Endpoint
	
		// Use the credentials of the old (current) account to authenticate
		$api_key = $this->sandbox ? $oldAccount['sandbox_public_key'] : $oldAccount['live_public_key'];
		$api_secret = $this->sandbox ? $oldAccount['sandbox_secret_key'] : $oldAccount['live_secret_key'];
	
		// Prepare data for API request
		$emailData = [
			'old_account' => [
				'title'      => $oldAccount['title'],
				'secret_key' => $api_secret,
				'status'     => $oldAccount['status'],
			],
			'new_account' => [
				'title'      => $newAccount['title'],
				'status'     => $newAccount['status'],
			],
			'message' => "Payment processing account has been switched. Please review the details."
		];
		$emailData['is_sandbox'] = $this->sandbox;
	
		// API request headers using old account credentials
		$headers = [
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . sanitize_text_field($api_key),
		];
	
		// Log API request details
		wc_get_logger()->info('Request Data: ' . json_encode($emailData), ['source' => 'dfin_sell_payment_gateway']);
	
		// Send data to DFinSell API
		$response = wp_remote_post($dfinSellApiUrl, [
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => json_encode($emailData),
			'headers'   => $headers,
			'sslverify' => true,
		]);
	
		// Handle API response
		if (is_wp_error($response)) {
			wc_get_logger()->error('Failed to send switch email: ' . $response->get_error_message(), ['source' => 'dfin_sell_payment_gateway']);
			return false;
		}
	
		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);
	
		// Check if authentication failed
		if ($response_code == 401 || $response_code == 403 || (!empty($response_data['error']) && strpos($response_data['error'], 'invalid credentials') !== false)) {
			wc_get_logger()->error('Email Sending Failed : Authentication failed: Invalid API key or secret for old account', ['source' => 'dfin_sell_payment_gateway']);
			return false; // Stop further execution
		}
	
		// Check if the API response has errors
		if (!empty($response_data['error'])) {
			wc_get_logger()->error('DFinSell API Error: ' . json_encode($response_data), ['source' => 'dfin_sell_payment_gateway']);
			return false;
		}
	
		wc_get_logger()->info('Switch email successfully sent', ['source' => 'dfin_sell_payment_gateway']);
		return true;
	}

private function get_current_active_account()
{
    $settings = get_option('woocommerce_dfinsell_settings', []);
    if (!isset($settings['accounts']) || empty($settings['accounts'])) {
        return false;
    }

    foreach ($settings['accounts'] as $account) {
        if (isset($account['status']) && $account['status'] === "true") {
            return $account;
        }
    }

    return reset($settings['accounts']); // Default to the first account if none are marked as active
}

private function allowed_html_tags() {
    return [
        'div'    => ['class' => []],
        'span'   => ['class' => []],
        'h4'     => [],
        'h5'     => [],
        'input'  => [
            'type'        => [],
            'name'        => [],
            'value'       => [],
            'class'       => [],
            'placeholder' => [],
        ],
        'button' => [
            'class' => [],
        ],
        'label'  => ['for' => []],
        'tr'     => ['valign' => []],
        'th'     => ['scope' => [], 'class' => []],
        'td'     => ['class' => []],
    ];
}

public function update_account_statuses($accounts) {
    if (empty($accounts)) {
        return;
    }

    // Ensure the first account is active if no active account exists
    $has_active = false;

    foreach ($accounts as &$account) {
        if ($account['status'] === 'true') {
            $has_active = true;
            break;
        }
    }

    if (!$has_active && !empty($accounts)) {
        $accounts[0]['status'] = 'true'; // Set the first account as active
    }

    // Save updated accounts to settings
    $settings = get_option('woocommerce_dfinsell_settings', []);
    $settings['accounts'] = $accounts;
    update_option('woocommerce_dfinsell_settings', $settings);

   
}


}

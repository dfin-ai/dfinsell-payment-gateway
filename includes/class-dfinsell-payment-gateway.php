<?php
if (!defined('ABSPATH')) {
    exit(); // Exit if accessed directly.
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
            add_action('admin_notices', [$this, 'woocommerce_not_active_notice']);
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
        $this->public_key = $this->sandbox === 'no' ? sanitize_text_field($this->get_option('public_key')) : sanitize_text_field($this->get_option('sandbox_public_key'));
        $this->secret_key = $this->sandbox === 'no' ? sanitize_text_field($this->get_option('secret_key')) : sanitize_text_field($this->get_option('sandbox_secret_key'));
        $this->current_account_index = 0;

        // Define hooks and actions.
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'dfinsell_process_admin_options']);

        // Enqueue styles and scripts
        add_action('wp_enqueue_scripts', [$this, 'dfinsell_enqueue_styles_and_scripts']);

        add_action('admin_enqueue_scripts', [$this, 'dfinsell_admin_scripts']);

        // Add action to display test order tag in order details
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'dfinsell_display_test_order_tag']);

        // Hook into WooCommerce to add a custom label to order rows
        add_filter('woocommerce_admin_order_preview_line_items', [$this, 'dfinsell_add_custom_label_to_order_row'], 10, 2);

        add_filter('woocommerce_available_payment_gateways', [$this, 'hide_custom_payment_gateway_conditionally']);

        //add_action('admin_enqueue_scripts', 'dfinsell_enqueue_admin_styles');
    }

    private function get_api_url($endpoint)
    {
        return $this->sip_protocol . $this->sip_host . $endpoint;
    }

    public function dfinsell_process_admin_options()
    {
        parent::process_admin_options();

        $errors = [];
        $valid_accounts = [];

        if (!isset($_POST['dfinsell_accounts_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dfinsell_accounts_nonce'])), 'dfinsell_accounts_nonce_action')) {
            wp_die(esc_html__('Security check failed!', 'dfinsell-payment-gateway'));
        }

        //  CHECK IF ACCOUNTS EXIST
        if (!isset($_POST['accounts']) || !is_array($_POST['accounts']) || empty($_POST['accounts'])) {
            $errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'dfinsell-payment-gateway');
        } else {
            $normalized_index = 0;
            $unique_live_keys = [];
            $unique_sandbox_keys = [];

            $raw_accounts = isset($_POST['accounts']) ? wp_unslash($_POST['accounts']) : [];

            if (!is_array($raw_accounts)) {
                $raw_accounts = [];
            }

            $accounts = array_map(function ($account) {
                if (is_array($account)) {
                    return array_map('sanitize_text_field', $account);
                }
                return sanitize_text_field($account);
            }, $raw_accounts);

            $has_active_account = false;

            foreach ($accounts as $index => $account) {
                // Sanitize input
                $account_title = sanitize_text_field($account['title'] ?? '');
                $priority = isset($account['priority']) ? intval($account['priority']) : 1;
                $live_public_key = sanitize_text_field($account['live_public_key'] ?? '');
                $live_secret_key = sanitize_text_field($account['live_secret_key'] ?? '');
                $sandbox_public_key = sanitize_text_field($account['sandbox_public_key'] ?? '');
                $sandbox_secret_key = sanitize_text_field($account['sandbox_secret_key'] ?? '');
                $has_sandbox = isset($account['has_sandbox']); // Checkbox handling

                //  Ignore empty accounts
                if (empty($account_title) && empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
                    continue;
                }

                //  Validate required fields
                if (empty($account_title) || empty($live_public_key) || empty($live_secret_key)) {
                    // Translators: %s is the account title.
                    $errors[] = sprintf(__('Account "%s": Title, Live Public Key, and Live Secret Key are required.', 'dfinsell-payment-gateway'), $account_title);
                    continue;
                }

                //  Ensure live keys are unique
                $live_combined = $live_public_key . '|' . $live_secret_key;
                if (in_array($live_combined, $unique_live_keys)) {
                    // Translators: %s is the account title.
                    $errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be unique.', 'dfinsell-payment-gateway'), $account_title);
                    continue;
                }
                $unique_live_keys[] = $live_combined;

                //  Ensure live keys are different
                if ($live_public_key === $live_secret_key) {
                    // Translators: %s is the account title.
                    $errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be different.', 'dfinsell-payment-gateway'), $account_title);
                }

                //  Sandbox Validation
                if ($has_sandbox) {
                    if (!empty($sandbox_public_key) && !empty($sandbox_secret_key)) {
                        // Sandbox keys must be unique
                        $sandbox_combined = $sandbox_public_key . '|' . $sandbox_secret_key;
                        if (in_array($sandbox_combined, $unique_sandbox_keys)) {
                            // Translators: %s is the account title.
                            $errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be unique.', 'dfinsell-payment-gateway'), $account_title);
                            continue;
                        }
                        $unique_sandbox_keys[] = $sandbox_combined;

                        // Sandbox keys must be different
                        if ($sandbox_public_key === $sandbox_secret_key) {
                            // Translators: %s is the account title.
                            $errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be different.', 'dfinsell-payment-gateway'), $account_title);
                        }
                    }
                }

                // Store valid account
                $valid_accounts[$normalized_index] = [
                    'title' => $account_title,
                    'priority' => $priority,
                    'live_public_key' => $live_public_key,
                    'live_secret_key' => $live_secret_key,
                    'sandbox_public_key' => $sandbox_public_key,
                    'sandbox_secret_key' => $sandbox_secret_key,
                    'has_sandbox' => $has_sandbox ? 'on' : 'off',
                ];
                $normalized_index++;
            }
        }

        //  Ensure at least one valid account exists
        if (empty($valid_accounts) && empty($errors)) {
            $errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'dfinsell-payment-gateway');
        }

        //  Stop saving if there are any errors
        if (empty($errors)) {
            update_option('woocommerce_dfinsell_payment_gateway_accounts', $valid_accounts);
            $this->admin_notices->dfinsell_add_notice('settings_success', 'notice notice-success', __('Settings saved successfully.', 'dfinsell-payment-gateway'));
        } else {
            foreach ($errors as $error) {
                $this->admin_notices->dfinsell_add_notice('settings_error', 'notice notice-error', $error);
            }
        }

        add_action('admin_notices', [$this->admin_notices, 'display_notices']);
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
        $form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'dfinsell-payment-gateway'),
                'label' => __('Enable DFin Sell Payment Gateway', 'dfinsell-payment-gateway'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'dfinsell-payment-gateway'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'dfinsell-payment-gateway'),
                'default' => __('Credit/Debit Card', 'dfinsell-payment-gateway'),
                'desc_tip' => __('Enter the title of the payment gateway as it will appear to customers during checkout.', 'dfinsell-payment-gateway'),
            ],
            'description' => [
                'title' => __('Description', 'dfinsell-payment-gateway'),
                'type' => 'text',
                'description' => __('Provide a brief description of the DFin Sell Payment Gateway option.', 'dfinsell-payment-gateway'),
                'default' => 'Description of the DFin Sell Payment Gateway Option.',
                'desc_tip' => __('Enter a brief description that explains the DFin Sell Payment Gateway option.', 'dfinsell-payment-gateway'),
            ],
            'instructions' => [
                'title' => __('Instructions', 'dfinsell-payment-gateway'),
                'type' => 'title',
                // Translators comment added here
                /* translators: 1: Link to developer account */
                'description' => sprintf(
                    /* translators: %1$s is a link to the developer account. %2$s is used for any additional formatting if necessary. */
                    __('To configure this gateway, %1$sGet your API keys from your merchant account: Developer Settings > API Keys.%2$s', 'dfinsell-payment-gateway'),
                    '<strong><a class="dfinsell-instructions-url" href="' .
                        esc_url($this->sip_host . '/developers') .
                        '" target="_blank">' .
                        __('click here to access your developer account', 'dfinsell-payment-gateway') .
                        '</a></strong><br>',
                    ''
                ),
                'desc_tip' => true,
            ],
            'sandbox' => [
                'title' => __('Sandbox', 'dfinsell-payment-gateway'),
                'label' => __('Enable Sandbox Mode', 'dfinsell-payment-gateway'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).', 'dfinsell-payment-gateway'),
                'default' => 'no',
            ],
            'accounts' => [
                'title' => __('Payment Accounts', 'dfinsell-payment-gateway'),
                'type' => 'accounts_repeater', // Custom field type for dynamic accounts
                'description' => __('Add multiple payment accounts dynamically.', 'dfinsell-payment-gateway'),
            ],
            'order_status' => [
                'title' => __('Order Status', 'dfinsell-payment-gateway'),
                'type' => 'select',
                'description' => __('Select the order status to be set after successful payment.', 'dfinsell-payment-gateway'),
                'default' => '', // Default is empty, which is our placeholder
                'desc_tip' => true,
                'id' => 'order_status_select', // Add an ID for targeting
                'options' => [
                    // '' => __('Select order status', 'dfinsell-payment-gateway'), // Placeholder option
                    'processing' => __('Processing', 'dfinsell-payment-gateway'),
                    'completed' => __('Completed', 'dfinsell-payment-gateway'),
                ],
            ],
            'show_consent_checkbox' => [
                'title' => __('Show Consent Checkbox', 'dfinsell-payment-gateway'),
                'label' => __('Enable consent checkbox on checkout page', 'dfinsell-payment-gateway'),
                'type' => 'checkbox',
                'description' => __('Check this box to show the consent checkbox on the checkout page. Uncheck to hide it.', 'dfinsell-payment-gateway'),
                'default' => 'yes',
            ],
        ];

        return apply_filters('woocommerce_gateway_settings_fields_' . $this->id, $form_fields, $this);
    }

    public function generate_accounts_repeater_html($key, $data)
    {
        $option_value = get_option('woocommerce_dfinsell_payment_gateway_accounts', []);
        $option_value = maybe_unserialize($option_value);
        $active_account = get_option('dfinsell_active_account', 0); // Store active account ID

        ob_start();
        ?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title']); ?></label>
			</th>
			<td class="forminp">
				<div class="dfinsell-accounts-container">
					<?php if (empty($option_value)): ?>
						<div class="empty-account"><?php esc_html_e('No accounts available. Please add one to continue.', 'dfinsell-payment-gateway'); ?></div>
					<?php else: ?>
						<?php foreach (array_values($option_value) as $index => $account): ?>
							<div class="dfinsell-account" data-index="<?php echo esc_attr($index); ?>">
								<div class="title-blog">
									<h4>
										<span class="account-name-display">
											<?php echo !empty($account['title']) ? esc_html($account['title']) : esc_html__('Untitled Account', 'dfinsell-payment-gateway'); ?>
										</span>
										&nbsp;<i class="fa fa-caret-down account-toggle-btn" aria-hidden="true"></i>
									</h4>
									<div class="action-button">
										<button type="button" class="delete-account-btn">
											<i class="fa fa-trash" aria-hidden="true"></i>
										</button>
									</div>
								</div>
	
								<div class="account-info">
									<div class="add-blog title-priority">
										<div class="account-input account-name">
											<label><?php esc_html_e('Account Name', 'dfinsell-payment-gateway'); ?></label>
											<input type="text" class="account-title" 
												name="accounts[<?php echo esc_attr($index); ?>][title]" 
												placeholder="<?php esc_attr_e('Account Title', 'dfinsell-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['title'] ?? ''); ?>">
										</div>
										<div class="account-input priority-name">
											<label><?php esc_html_e('Priority', 'dfinsell-payment-gateway'); ?></label>
											<input type="number" class="account-priority"
												name="accounts[<?php echo esc_attr($index); ?>][priority]"
												placeholder="<?php esc_attr_e('Priority', 'dfinsell-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['priority'] ?? '1'); ?>" min="1">
										</div>
									</div>
	
									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Live Keys', 'dfinsell-payment-gateway'); ?></label>
											<input type="text" class="live-public-key"
												name="accounts[<?php echo esc_attr($index); ?>][live_public_key]"
												placeholder="<?php esc_attr_e('Public Key', 'dfinsell-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['live_public_key'] ?? ''); ?>">
										</div>
										<div class="account-input">
											<input type="text" class="live-secret-key"
												name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]"
												placeholder="<?php esc_attr_e('Secret Key', 'dfinsell-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['live_secret_key'] ?? ''); ?>">
										</div>
									</div>
	
									<div class="account-checkbox">
										<input type="checkbox" class="sandbox-checkbox"
											name="accounts[<?php echo esc_attr($index); ?>][has_sandbox]"
											<?php checked(!empty($account['sandbox_public_key'])); ?>>
										<?php esc_html_e('Do you have the sandbox keys?', 'dfinsell-payment-gateway'); ?>
									</div>
	
									<div class="sandbox-key" style="<?php echo empty($account['sandbox_public_key']) ? 'display: none;' : ''; ?>">
										<div class="add-blog">
											<div class="account-input">
												<label><?php esc_html_e('Sandbox Keys', 'dfinsell-payment-gateway'); ?></label>
												<input type="text" class="sandbox-public-key"
													name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]"
													placeholder="<?php esc_attr_e('Public Key', 'dfinsell-payment-gateway'); ?>"
													value="<?php echo esc_attr($account['sandbox_public_key'] ?? ''); ?>">
											</div>
											<div class="account-input">
												<input type="text" class="sandbox-secret-key"
													name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]"
													placeholder="<?php esc_attr_e('Secret Key', 'dfinsell-payment-gateway'); ?>"
													value="<?php echo esc_attr($account['sandbox_secret_key'] ?? ''); ?>">
											</div>
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php wp_nonce_field('dfinsell_accounts_nonce_action', 'dfinsell_accounts_nonce'); ?>
					<div class="add-account-btn">
						<button type="button" class="button dfinsell-add-account">
							<span>+</span> <?php esc_html_e('Add Account', 'dfinsell-payment-gateway'); ?>
						</button>
					</div>
				</div>
			</td>
		</tr>
		<?php return ob_get_clean();
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment($order_id, $used_accounts = [])
    {
        global $woocommerce;

        // Retrieve client IP
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
            $ip_address = 'invalid';
        }

        // **Rate Limiting**
        $window_size = 30; // 30 seconds
        $max_requests = 100;
        $timestamp_key = "rate_limit_{$ip_address}_timestamps";
        $request_timestamps = get_transient($timestamp_key) ?: [];

        // Remove old timestamps
        $timestamp = time();
        $request_timestamps = array_filter($request_timestamps, fn($ts) => $timestamp - $ts <= $window_size);

        if (count($request_timestamps) >= $max_requests) {
            wc_add_notice(__('Too many requests. Please try again later.', 'dfinsell-payment-gateway'), 'error');
            return ['result' => 'fail'];
        }

        // Add the current timestamp
        $request_timestamps[] = $timestamp;
        set_transient($timestamp_key, $request_timestamps, $window_size);

        // **Retrieve Order**
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Invalid order.', 'dfinsell-payment-gateway'), 'error');
            return ['result' => 'fail'];
        }

        // **Sandbox Mode Handling**
        if ($this->sandbox) {
            $test_note = __('This is a test order in sandbox mode.', 'dfinsell-payment-gateway');
            $existing_notes = get_comments(['post_id' => $order->get_id(), 'type' => 'order_note', 'approve' => 'approve']);

            if (!array_filter($existing_notes, fn($note) => trim($note->comment_content) === trim($test_note))) {
                $order->update_meta_data('_is_test_order', true);
                $order->add_order_note($test_note);
            }
        }
        $last_failed_account = null; // Track the last account that reached the limit
        $previous_account = null;
        // **Start Payment Process**
        while (true) {
            $account = $this->get_next_available_account($used_accounts);
            if (!$account) {
                // **Ensure email is sent to the last failed account**
                if ($last_failed_account) {
                    wc_get_logger()->info("Sending email to last failed account: '{$last_failed_account['title']}'", ['source' => 'dfinsell-payment-gateway']);
                    $this->send_account_switch_email($last_failed_account, $account);
                }
                wc_add_notice(__('No available payment accounts.', 'dfinsell-payment-gateway'), 'error');
                return ['result' => 'fail'];
            }
            $lock_key = $account['lock_key'] ?? null;

            //wc_get_logger()->info("Using account '{$account['title']}' for payment.", ['source' => 'dfinsell-payment-gateway']);
            // Add order note mentioning account name
            $order->add_order_note(__('Processing payment using account: ', 'dfinsell-payment-gateway') . $account['title']);

            // **Prepare API Data**
            $public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
            $secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
            $data = $this->dfinsell_prepare_payment_data($order, $public_key, $secret_key);

            // **Check Transaction Limit**
            $transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');
            $transaction_limit_response = wp_remote_post($transactionLimitApiUrl, [
                'method' => 'POST',
                'timeout' => 30,
                'body' => $data,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
                ],
                'sslverify' => true,
            ]);

            $transaction_limit_data = json_decode(wp_remote_retrieve_body($transaction_limit_response), true);

            // **Handle Account Limit Error**
            if (isset($transaction_limit_data['error'])) {
                $error_message = sanitize_text_field($transaction_limit_data['error']);
                wc_get_logger()->error("Account '{$account['title']}' limit reached: $error_message", ['source' => 'dfinsell-payment-gateway']);
                if (!empty($lock_key)) {
                    $this->release_lock($lock_key);
                }
    
                $last_failed_account = $account;
                // Switch to next available account
                $used_accounts[] = $account['title'];
                $new_account = $this->get_next_available_account($used_accounts);

                // **Send Email Notification **
                if ($new_account) {
                    wc_get_logger()->info("Switching from '{$account['title']}' to '{$new_account['title']}' due to limit.", ['source' => 'dfinsell-payment-gateway']);

                    // Send email only to the previously failed account
                    if ($previous_account) {
                        //$this->send_account_switch_email($previous_account, $account);
                    }

                    $previous_account = $account;
                    continue; // Retry with the new account
                } else {
                    // **No available accounts left, send email to the last failed account**
                    if ($last_failed_account) {
                        $this->send_account_switch_email($last_failed_account, $account);
                    }
                    wc_add_notice(__('All accounts have reached their transaction limit.', 'dfinsell-payment-gateway'), 'error');
                    return ['result' => 'fail'];
                }
            }

            // **Proceed with Payment**
            $apiPath = '/api/request-payment';
            $url = esc_url($this->sip_protocol . $this->sip_host . $apiPath);
			
			$order->update_meta_data('_order_origin', 'dfinsell_payment_gateway');
			$order->save();
            wc_get_logger()->info('DFin Sell Payment Request: ' . wp_json_encode($data), ['source' => 'dfinsell-payment-gateway']);

            $response = wp_remote_post($url, [
                'method' => 'POST',
                'timeout' => 30,
                'body' => $data,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
                ],
                'sslverify' => true,
            ]);

            // **Handle Response**
            if (is_wp_error($response)) {
                wc_get_logger()->error('DFin Sell Payment Request Error: ' . $response->get_error_message(), ['source' => 'dfinsell-payment-gateway']);
                if (!empty($lock_key)) {
                    $this->release_lock($lock_key);
                }    
				wc_add_notice(__('Payment error: Unable to process.', 'dfinsell-payment-gateway'), 'error');
                return ['result' => 'fail'];
            }

            $response_data = json_decode(wp_remote_retrieve_body($response), true);
            wc_get_logger()->info('DFin Sell Payment Response: ' . json_encode($response_data), ['source' => 'dfinsell-payment-gateway']);

            if (!empty($response_data['status']) && $response_data['status'] === 'success' && !empty($response_data['data']['payment_link'])) {
                if ($last_failed_account) {
                    //wc_get_logger()->info("Sending email before returning success to: '{$last_failed_account['title']}'", ['source' => 'dfinsell-payment-gateway']);
                    $this->send_account_switch_email($last_failed_account, $account);
                }
                //$last_successful_account = $account;
                // Save pay_id to order meta
                $pay_id = $response_data['data']['pay_id'] ?? '';
                if (!empty($pay_id)) {
                    $order->update_meta_data('_dfinsell_pay_id', $pay_id);
                }

                // **Update Order Status**
                $order->update_status('pending', __('Payment pending.', 'dfinsell-payment-gateway'));
                

                // **Add Order Note (If Not Exists)**
                // translators: %s represents the account title.
                $new_note = sprintf(
                    /* translators: %s represents the account title. */
                    esc_html__('Payment initiated via DFin Sell Payment Gateway. Awaiting customer action. Gateway using account: %s', 'dfinsell-payment-gateway'),
                    esc_html($account['title'])
                );
                $existing_notes = $order->get_customer_order_notes();

                if (!array_filter($existing_notes, fn($note) => trim(wp_strip_all_tags($note->comment_content)) === trim($new_note))) {
                    $order->add_order_note($new_note, false, true);
                }
              

                if (!empty($lock_key)) {
                    $this->release_lock($lock_key);
                }
                return [
                    'payment_link' => esc_url($response_data['data']['payment_link']),
                    'result' => 'success',
                ];
            }

            // **Handle Payment Failure**
            $error_message = isset($response_data['message']) ? sanitize_text_field($response_data['message']) : __('Payment failed.', 'dfinsell-payment-gateway');
            wc_get_logger()->error("Payment failed on account '{$account['title']}': $error_message", ['source' => 'dfinsell-payment-gateway']);
            // **Add Order Note for Failed Payment**
            $order->add_order_note(
                sprintf(
                    /* translators: 1: Account title, 2: Error message. */
                    esc_html__('Payment failed using account: %1$s. Error: %2$s', 'dfinsell-payment-gateway'),
                    esc_html($account['title']),
                    esc_html($error_message)
                )
            );

            // Add WooCommerce error notice
            wc_add_notice(__('Payment error: ', 'dfinsell-payment-gateway') . $error_message, 'error');
            if (!empty($lock_key)) {
                $this->release_lock($lock_key);
            }
            return ['result' => 'fail'];
        }
    }

    // Display the "Test Order" tag in admin order details
    public function dfinsell_display_test_order_tag($order)
    {
        if (get_post_meta($order->get_id(), '_is_test_order', true)) {
            echo '<p><strong>' . esc_html__('Test Order', 'dfinsell-payment-gateway') . '</strong></p>';
        }
    }

    private function dfinsell_check_api_keys()
    {
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
                [
                    'order_id' => $order_id, // Include order ID or any other identifier
                    'key' => $order->get_order_key(),
                    'nonce' => wp_create_nonce('dfinsell_payment_nonce'), // Create a nonce for verification
                    'mode' => 'wp',
                ],
                $this->dfinsell_get_return_url_base() // Use the updated base URL method
            )
        );

        $ip_address = sanitize_text_field($this->dfinsell_get_client_ip());

        if (empty($order_id)) {
            wc_get_logger()->error('Order ID is missing or invalid.', ['source' => 'dfinsell-payment-gateway']);
            return ['result' => 'fail'];
        }

        // Create the meta data array
        $meta_data_array = [
            'order_id' => $order_id,
            'amount' => $amount,
            'source' => 'woocommerce',
        ];

        // Log errors but continue processing
        foreach ($meta_data_array as $key => $value) {
            $meta_data_array[$key] = sanitize_text_field($value); // Sanitize each field
            if (is_object($value) || is_resource($value)) {
                wc_get_logger()->error('Invalid value for key ' . $key . ': ' . wp_json_encode($value), ['source' => 'dfinsell-payment-gateway']);
            }
        }

        return [
            'api_secret' => $api_secret, // Use sandbox or live secret key
            'api_public_key' => $api_public_key, // Add the public key for API calls
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
        ];
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
        <p>' .
            esc_html__('DFin Sell Payment Gateway requires WooCommerce to be installed and active.', 'dfinsell-payment-gateway') .
            '</p>
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
                    <input type="checkbox" id="dfinsell_consent" name="dfinsell_consent" /> ' .
                esc_html__('I consent to the collection of my data to process this payment', 'dfinsell-payment-gateway') .
                '
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
        // Check for SQL injection attempts
        if (!$this->check_for_sql_injection()) {
            return false;
        }
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
                [], // Dependencies (if any)
                '1.0', // Version number
                'all' // Media
            );

            // Enqueue dfinsell.js script
            wp_enqueue_script(
                'dfinsell-js',
                plugins_url('../assets/js/dfinsell.js', __FILE__),
                ['jquery'], // Dependencies
                '1.0', // Version number
                true // Load in footer
            );

            // Localize script with parameters that need to be passed to dfinsell.js
            wp_localize_script('dfinsell-js', 'dfinsell_params', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'checkout_url' => wc_get_checkout_url(),
                'dfin_loader' => plugins_url('../assets/images/loader.gif', __FILE__),
                'dfinsell_nonce' => wp_create_nonce('dfinsell_payment'), // Create a nonce for verification
                'payment_method' => $this->id,
            ]);
        }
    }

    function dfinsell_admin_scripts($hook)
    {
        if ('woocommerce_page_wc-settings' !== $hook) {
            return; // Only load on WooCommerce settings page
        }

        // Enqueue Admin CSS
        wp_enqueue_style('dfinsell-font-awesome', plugins_url('../assets/css/font-awesome.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/font-awesome.css'), 'all');

        // Enqueue Admin CSS
        wp_enqueue_style('dfinsell-admin-css', plugins_url('../assets/css/admin.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/admin.css'), 'all');

        // Register and enqueue your script
        wp_enqueue_script('dfinsell-admin-script', plugins_url('../assets/js/dfinsell-admin.js', __FILE__), ['jquery'], filemtime(plugin_dir_path(__FILE__) . '../assets/js/dfinsell-admin.js'), true);

        wp_enqueue_style(
            'dfinsell-admin-style',
            plugins_url('assets/css/admin-style.css', __DIR__), // This ensures correct path
            [],
            '1.0.0'
        );

        // Localize the script to pass parameters
        wp_localize_script('dfinsell-admin-script', 'params', [
            'PAYMENT_CODE' => $this->id,
        ]);
    }

    public function hide_custom_payment_gateway_conditionally($available_gateways)
    {
        $gateway_id = self::ID;

        if (is_checkout()) {
            //  Force refresh WooCommerce session cache
            WC()->session->set('dfin_gateway_hidden_logged', false);
            WC()->session->set('dfin_gateway_status', '');

            // Retrieve cached gateway status (to prevent redundant API calls)
            $gateway_status = WC()->session->get('dfin_gateway_status');

            if ($gateway_status === 'hidden') {
                unset($available_gateways[$gateway_id]);
                return $available_gateways;
            } elseif ($gateway_status === 'visible') {
                return $available_gateways;
            }

            // Get cart total amount
            $amount = number_format(WC()->cart->get_total('edit'), 2, '.', '');

            if (!method_exists($this, 'get_all_accounts')) {
                wc_get_logger()->error('Method get_all_accounts() is missing!', ['source' => 'dfinsell-payment-gateway']);
                return $available_gateways;
            }

            $accounts = $this->get_all_accounts();

            if (empty($accounts)) {
                //wc_get_logger()->warning('No accounts available. Hiding payment gateway.', ['source' => 'dfinsell-payment-gateway']);

                unset($available_gateways[$gateway_id]); //  Unset gateway properly
                WC()->session->set('dfin_gateway_status', 'hidden');

                return $available_gateways; //  Return updated list
            }

            // Sort accounts by priority (higher priority first)
            usort($accounts, function ($a, $b) {
                return $a['priority'] <=> $b['priority'];
            });

            $all_high_priority_accounts_limited = true;

            //  Clear Transient Cache Before Checking API Limits
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dfinsell_daily_limit_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_dfinsell_daily_limit_%'");

            foreach ($accounts as $account) {
                $public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
                $transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');

                $data = [
                    'is_sandbox' => $this->sandbox,
                    'amount' => $amount,
                    'api_public_key' => $public_key,
                ];

                // Cache key to avoid redundant API requests
                $cache_key = 'dfinsell_daily_limit_' . md5($public_key . $amount);
                $transaction_limit_response_data = $this->get_cached_api_response($transactionLimitApiUrl, $data, $cache_key);
                
                if (!isset($transaction_limit_response_data['error'])) {
                    // At least one high-priority account is available
                    $all_high_priority_accounts_limited = false;
                    break; // Stop checking after the first valid account
                }
            }

            if ($all_high_priority_accounts_limited) {
                if (!WC()->session->get('dfin_gateway_hidden_logged')) {
                    wc_get_logger()->warning('All high-priority accounts have reached transaction limits. Hiding gateway.', ['source' => 'dfinsell-payment-gateway']);
                    WC()->session->set('dfin_gateway_hidden_logged', true);
                }

                unset($available_gateways[$gateway_id]);
                WC()->session->set('dfin_gateway_status', 'hidden');
            } else {
                WC()->session->set('dfin_gateway_status', 'visible');
            }
        }

        return $available_gateways;
    }

    /**
     * Validate an individual account.
     *
     * @param array $account The account data to validate.
     * @param int $index The index of the account (for error messages).
     * @return bool|string True if valid, error message if invalid.
     */
    protected function validate_account($account, $index)
    {
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
    protected function validate_accounts($accounts)
    {
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

    private function get_cached_api_response($url, $data, $cache_key)
    {
        // Check if the response is already cached
        $cached_response = get_transient($cache_key);

        if ($cached_response !== false) {
            return $cached_response;
        }

        // Make the API call
        $response = wp_remote_post($url, [
            'method' => 'POST',
            'timeout' => 30,
            'body' => $data,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Bearer ' . $data['api_public_key'],
            ],
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        // Cache the response for 2 minutes
        set_transient($cache_key, $response_data, 2 * MINUTE_IN_SECONDS);

        return $response_data;
    }

    private function get_all_accounts()
	{
	    $accounts = get_option('woocommerce_dfinsell_payment_gateway_accounts', []);

	    // Try to unserialize if it's a string
	    if (is_string($accounts)) {
	        $unserialized = maybe_unserialize($accounts);
	        $accounts = is_array($unserialized) ? $unserialized : [];
	    }

	    $valid_accounts = [];

		if(!empty($accounts)){
		    foreach ($accounts as $account) {
		        if ($this->sandbox) {
		            if (!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) {
		                $valid_accounts[] = $account;
		            }
		        } else {
		            $valid_accounts[] = $account;
		        }
		    }
		}

	    $this->accounts = $valid_accounts;
	    return $this->accounts;
	}


    function dfinsell_enqueue_admin_styles($hook)
    {
        // Load only on WooCommerce settings pages
        if (strpos($hook, 'woocommerce') === false) {
            return;
        }

        wp_enqueue_style('dfinsell-admin-style', plugin_dir_url(__FILE__) . 'assets/css/admin-style.css', [], '1.0.0');
    }

    /**
     * Send an email notification via DfinSell API
     */
    private function send_account_switch_email($oldAccount, $newAccount)
    {
        $dfinSellApiUrl = $this->get_api_url('/api/switch-account-email'); // Dfin Sell API Endpoint

        // Use the credentials of the old (current) account to authenticate
        $api_key = $this->sandbox ? $oldAccount['sandbox_public_key'] : $oldAccount['live_public_key'];
        $api_secret = $this->sandbox ? $oldAccount['sandbox_secret_key'] : $oldAccount['live_secret_key'];

        // Prepare data for API request
        $emailData = [
            'old_account' => [
                'title' => $oldAccount['title'],
                'secret_key' => $api_secret,
            ],
            'new_account' => [
                'title' => $newAccount['title'],
            ],
            'message' => "Payment processing account has been switched. Please review the details.",
        ];
        $emailData['is_sandbox'] = $this->sandbox;

        // API request headers using old account credentials
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . sanitize_text_field($api_key),
        ];

        // Log API request details
        //wc_get_logger()->info('Request Data: ' . json_encode($emailData), ['source' => 'dfinsell-payment-gateway']);

        // Send data to DFinSell API
        $response = wp_remote_post($dfinSellApiUrl, [
            'method' => 'POST',
            'timeout' => 30,
            'body' => json_encode($emailData),
            'headers' => $headers,
            'sslverify' => true,
        ]);

        // Handle API response
        if (is_wp_error($response)) {
            wc_get_logger()->error('Failed to send switch email: ' . $response->get_error_message(), ['source' => 'dfinsell-payment-gateway']);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        // Check if authentication failed
        if ($response_code == 401 || $response_code == 403 || (!empty($response_data['error']) && strpos($response_data['error'], 'invalid credentials') !== false)) {
            wc_get_logger()->error('Email Sending Failed : Authentication failed: Invalid API key or secret for old account', ['source' => 'dfinsell-payment-gateway']);
            return false; // Stop further execution
        }

        // Check if the API response has errors
        if (!empty($response_data['error'])) {
            wc_get_logger()->error('DFinSell API Error: ' . json_encode($response_data), ['source' => 'dfinsell-payment-gateway']);
            return false;
        }

       	wc_get_logger()->info("Switch email successfully sent to: '{$oldAccount['title']}'", ['source' => 'dfinsell-payment-gateway']);
        return true;
    }

    /**
     * Get the next available payment account, handling concurrency.
     */
    private function get_next_available_account($used_accounts = [])
    {
        global $wpdb;

        // Fetch all accounts ordered by priority
        $settings = get_option('woocommerce_dfinsell_payment_gateway_accounts', []);
		
        if (is_string($settings)) {
			$settings = maybe_unserialize($settings);
		}
	
		if (!is_array($settings)) {
			return false;
		}

        // Filter out used accounts
        $available_accounts = array_filter($settings, function ($account) use ($used_accounts) {
            return !in_array($account['title'], $used_accounts);
        });

        if (empty($available_accounts)) {
            return false;
        }

        // Sort by priority (lower value = higher priority)
        usort($available_accounts, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        // Concurrency Handling: Lock the selected account
        foreach ($available_accounts as $account) {
            $lock_key = "dfinsell_lock_{$account['title']}";

            // Try to acquire lock
            if ($this->acquire_lock($lock_key)) {
                $account['lock_key'] = $lock_key; 
                // wc_get_logger()->info("Selected account '{$account['title']}' for processing.", ['source' => 'dfinsell-payment-gateway']);
                return $account;
            }
        }

        return false;
    }

    /**
     * Acquire a lock to prevent concurrent access to the same account.
     */

    private function acquire_lock($lock_key)
    {
        global $wpdb;
        $lock_timeout = 10; // Lock expires after 10 seconds

        // Try to insert a lock row in the database
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, %s, 'no')
                 ON DUPLICATE KEY UPDATE option_value = %s",
                 $lock_key,
                 time() + $lock_timeout,
                 time() + $lock_timeout
             )
         );
     
         if ($inserted === false) {
             wc_get_logger()->error(
                 "DB Error: " . $wpdb->last_error, 
                 ['source' => 'dfinsell-payment-gateway']
             );
             return false; // Lock acquisition failed
         }
     
         wc_get_logger()->info("Lock acquired for '{$lock_key}'", ['source' => 'dfinsell-payment-gateway']);
     
         return true;
     }
     

    /**
     * Release a lock after payment processing is complete.
     */
    private function release_lock($lock_key)
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $lock_key));
        
            wc_get_logger()->info("Released lock for '{$lock_key}'", ['source' => 'dfinsell-payment-gateway']);
    }

    function check_for_sql_injection()
    {
        $sql_injection_patterns = ['/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|ALTER)\b(?![^{}]*})/i', '/(\-\-|\#|\/\*|\*\/)/i', '/(\b(AND|OR)\b\s*\d+\s*[=<>])/i'];

        $errors = []; // Store multiple errors

        // Get checkout fields dynamically
        $checkout_fields = WC()
            ->checkout()
            ->get_checkout_fields();

        foreach ($_POST as $key => $value) {
            if (is_string($value)) {
                foreach ($sql_injection_patterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        // Get the field label dynamically
                        $field_label = isset($checkout_fields['billing'][$key]['label'])
                            ? $checkout_fields['billing'][$key]['label']
                            : (isset($checkout_fields['shipping'][$key]['label'])
                                ? $checkout_fields['shipping'][$key]['label']
                                : (isset($checkout_fields['account'][$key]['label'])
                                    ? $checkout_fields['account'][$key]['label']
                                    : (isset($checkout_fields['order'][$key]['label'])
                                        ? $checkout_fields['order'][$key]['label']
                                        : ucfirst(str_replace('_', ' ', $key)))));

                        // Log error for debugging
                        wc_get_logger()->info("Potential SQL Injection Attempt - Field: $field_label, Value: $value, IP: " . $_SERVER['REMOTE_ADDR'], ['source' => 'dfinsell-payment-gateway']);
                        // Add error to array instead of stopping execution
                        $errors[] = __("Please remove special characters and enter a valid '$field_label'", 'dfinsell-payment-gateway');

                        break; // Stop checking other patterns for this field
                    }
                }
            }
        }

        // Display all collected errors at once
        if (!empty($errors)) {
            foreach ($errors as $error) {
                wc_add_notice($error, 'error');
            }
            return false;
        }

        return true;
    }
}

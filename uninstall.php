<?php
// Exit if accessed directly
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete all plugin settings
delete_option('woocommerce_dfinsell_enabled');
delete_option('woocommerce_dfinsell_title');
delete_option('woocommerce_dfinsell_description');
delete_option('woocommerce_dfinsell_sandbox');
delete_option('woocommerce_dfinsell_order_status');
delete_option('woocommerce_dfinsell_show_consent_checkbox');

// Delete old single API key settings (from previous versions)
delete_option('woocommerce_dfinsell_public_key');
delete_option('woocommerce_dfinsell_secret_key');
delete_option('woocommerce_dfinsell_sandbox_public_key');
delete_option('woocommerce_dfinsell_sandbox_secret_key');

// If you're storing multiple accounts in an array or serialized format, make sure to clean it up
delete_option('woocommerce_dfinsell_payment_gateway_accounts');

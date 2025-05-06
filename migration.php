<?php

function dfinsell_migrate_old_settings() {
    
    $settings = get_option('woocommerce_dfinsell_settings');
    $settings = maybe_unserialize($settings);

    if (!is_array($settings) || empty($settings)) {
        return; // Nothing to update
    }

    // Add live/sandbox status based on available keys
    $has_live_keys = !empty($settings['public_key']) && !empty($settings['secret_key']);
    $has_sandbox_keys = !empty($settings['sandbox_public_key']) && !empty($settings['sandbox_secret_key']);

    $settings['live_status'] = $has_live_keys ? 'active' : 'inactive';
    $settings['sandbox_status'] = ($settings['sandbox'] === 'yes' && $has_sandbox_keys) ? 'active' : 'inactive';

    update_option('woocommerce_dfinsell_settings', $settings);
   // dfinsell_trigger_sync();
}


function dfinsell_trigger_sync() {
    if (class_exists('DFINSELL_PAYMENT_GATEWAY_Loader')) {
        $loader = DFINSELL_PAYMENT_GATEWAY_Loader::get_instance();
        if (method_exists($loader, 'handle_cron_event')) {
            wc_get_logger()->info('DFin Sell sync account for migrations', ['source' => 'dfinsell-payment-gateway']);
            $loader->handle_cron_event();
        }
    }
}


// Hook migration to plugin activation
register_activation_hook(DFINSELL_PAYMENT_GATEWAY_FILE, 'dfinsell_migrate_old_settings');

<?php

function dfinsell_migrate_old_settings() {
    $old_settings = get_option('woocommerce_dfinsell_settings');

    if (!$old_settings || !is_array($old_settings)) {
        return; // No old settings found, skip migration.
    }

    // Extract keys from old settings
    $sandbox_enabled = isset($old_settings['sandbox']) && $old_settings['sandbox'] === 'yes';
    $live_public_key = $old_settings['public_key'] ?? '';
    $live_secret_key = $old_settings['secret_key'] ?? '';
    $sandbox_public_key = $old_settings['sandbox_public_key'] ?? '';
    $sandbox_secret_key = $old_settings['sandbox_secret_key'] ?? '';

    // ✅ If no keys exist, do not migrate
    if (empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
        return; // Skip migration if all keys are missing
    }

    // Create an array for the single account
    $new_accounts = [
        [
            'title' => 'Default Account',
            'priority' => 1, // Default priority for a single account
            'live_public_key' => $live_public_key,
            'live_secret_key' => $live_secret_key,
            'sandbox_public_key' => $sandbox_public_key,
            'sandbox_secret_key' => $sandbox_secret_key,
            'has_sandbox' => $sandbox_enabled ? 'on' : 'off'
        ]
    ];

    // Save the migrated data
    update_option('woocommerce_dfinsell_payment_gateway_accounts', serialize($new_accounts));
}

// Hook migration to plugin activation
register_activation_hook(DFINSELL_PAYMENT_GATEWAY_FILE, 'dfinsell_migrate_old_settings');

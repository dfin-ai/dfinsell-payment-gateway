<?php

function dfinsell_migrate_old_settings()
{
	// First, check if beta option exists
	$beta_accounts = get_option('woocommerce_dfinsell_payment_gateway_accounts');

	if ($beta_accounts) {
		$beta_accounts = maybe_unserialize($beta_accounts);

		if (is_array($beta_accounts) && !empty($beta_accounts)) {
			// Enhance each account to ensure all required keys exist
			$enhanced_accounts = array_map(function ($account) {
				$account['live_status'] = $account['live_status'] ?? 'active';
				$account['sandbox_status'] = $account['sandbox_status'] ?? 'active';
				$account['has_sandbox'] = (!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) ? 'on' : 'off';
				$account['priority'] = $account['priority'] ?? 1;
				$account['title'] = $account['title'] ?? 'Default Account';
				return $account;
			}, $beta_accounts);

			// Save updated accounts back
			update_option('woocommerce_dfinsell_payment_gateway_accounts', serialize($enhanced_accounts));
			dfinsell_trigger_sync();
			return; // Migration complete for beta
		}
	}

	// Fallback to legacy `woocommerce_dfinsell_settings`
	$old_settings = get_option('woocommerce_dfinsell_settings');
	$old_settings = maybe_unserialize($old_settings);
	if (!$old_settings || !is_array($old_settings)) {
		return; // Nothing to migrate
	}

	// Extract old settings
	$live_public_key = $old_settings['public_key'] ?? '';
	$live_secret_key = $old_settings['secret_key'] ?? '';
	$sandbox_public_key = $old_settings['sandbox_public_key'] ?? '';
	$sandbox_secret_key = $old_settings['sandbox_secret_key'] ?? '';
	$sandbox_enabled = isset($old_settings['sandbox']) && $old_settings['sandbox'] === 'yes';

	$has_sandbox = (!empty($sandbox_public_key) && !empty($sandbox_secret_key)) ? 'on' : 'off';
	$live_status = 'active';
	$sandbox_status = $sandbox_enabled ? 'active' : 'inactive';

	if (empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
		return; // No keys to migrate
	}

	$new_accounts = [
		[
			'title' => 'Default Account',
			'priority' => 1,
			'live_public_key' => $live_public_key,
			'live_secret_key' => $live_secret_key,
			'sandbox_public_key' => $sandbox_public_key,
			'sandbox_secret_key' => $sandbox_secret_key,
			'has_sandbox' => $has_sandbox,
			'live_status' => $live_status,
			'sandbox_status' => $sandbox_status,
		]
	];

	update_option('woocommerce_dfinsell_payment_gateway_accounts', serialize($new_accounts));
	dfinsell_trigger_sync();
}

function dfinsell_trigger_sync()
{
	if (get_transient('dfinsell_sync_lock')) {
		return; // Already triggered recently
	}
	set_transient('dfinsell_sync_lock', true, 5 * MINUTE_IN_SECONDS);

	if (class_exists('DFINSELL_PAYMENT_GATEWAY_Loader')) {
		$loader = DFINSELL_PAYMENT_GATEWAY_Loader::get_instance();
		if (method_exists($loader, 'handle_cron_event')) {
			wc_get_logger()->info('Sync account for migration started.', [
				'source' => 'dfinsell-payment-gateway',
				'context' => ['sync_id' => uniqid('migrate_', true)]
			]);
			$loader->handle_cron_event();
		}
	}
}

function dfinsell_on_plugin_activate() {
	// Migrate settings
	if (function_exists('dfinsell_migrate_old_settings')) {
		dfinsell_migrate_old_settings();
	}

	// Activate cron
	if (class_exists('DFINSELL_PAYMENT_GATEWAY_Loader')) {
		DFINSELL_PAYMENT_GATEWAY_Loader::get_instance()->activate_cron_job();
	}
}

function dfinsell_on_plugin_deactivate() {
	// Deactivate cron
	if (class_exists('DFINSELL_PAYMENT_GATEWAY_Loader')) {
		DFINSELL_PAYMENT_GATEWAY_Loader::get_instance()->deactivate_cron_job();
	}
}

register_activation_hook(DFINSELL_PAYMENT_GATEWAY_FILE, 'dfinsell_on_plugin_activate');
register_deactivation_hook(DFINSELL_PAYMENT_GATEWAY_FILE, 'dfinsell_on_plugin_deactivate');
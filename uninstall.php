<?php

declare(strict_types=1);

/**
 * Uninstall script for Cashback Plugin
 *
 * This file is executed when the plugin is uninstalled via WordPress admin.
 * It cleans up all plugin data including tables, options, and scheduled events.
 *
 * @package Cashback_Plugin
 */

// Exit if accessed directly or not uninstalling
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Security check: only admins can uninstall
if (!current_user_can('activate_plugins')) {
    exit;
}

/**
 * Clean up plugin data
 */
function cashback_plugin_uninstall(): void
{
    global $wpdb;

    // Remove scheduled cron events
    $cron_hooks = [
        'cashback_support_auto_delete_cron',
        'cashback_health_check_cron',
        'cashback_fraud_detection_cron',
        'cashback_fraud_cleanup_cron',
    ];
    foreach ($cron_hooks as $hook) {
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
    }

    // Validate table prefix for security
    $prefix = $wpdb->prefix;
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
        error_log('Cashback Plugin Uninstall: Invalid table prefix detected');
        return;
    }

    // List of tables to drop
    $tables = [
        "{$prefix}cashback_fraud_signals",
        "{$prefix}cashback_fraud_alerts",
        "{$prefix}cashback_user_fingerprints",
        "{$prefix}cashback_payout_requests",
        "{$prefix}cashback_transactions",
        "{$prefix}cashback_unregistered_transactions",
        "{$prefix}cashback_user_balance",
        "{$prefix}cashback_webhooks",
        "{$prefix}cashback_user_profile",
        "{$prefix}cashback_payout_methods",
        "{$prefix}cashback_affiliate_network_params",
        "{$prefix}cashback_affiliate_networks",
        "{$prefix}cashback_banks",
        "{$prefix}cashback_support_attachments",
        "{$prefix}cashback_support_tickets",
        "{$prefix}cashback_support_messages",
        "{$prefix}cashback_audit_log",
        "{$prefix}cashback_click_log",
    ];

    // Drop triggers
    $triggers = [
        "{$prefix}calculate_cashback_before_insert",
        "{$prefix}calculate_cashback_before_insert_unregistered",
        "{$prefix}calculate_cashback_before_update",
        "{$prefix}calculate_cashback_before_update_unregistered",
        "{$prefix}cashback_tr_prevent_delete_final_status",
        "{$prefix}cashback_tr_prevent_update_final_status",
        "{$prefix}tr_prevent_delete_paid_payout",
        "{$prefix}tr_prevent_update_paid_payout",
        "{$prefix}tr_prevent_delete_failed_payout",
        "{$prefix}tr_prevent_update_failed_payout",
        "{$prefix}tr_banned_user_update_banned_at",
        "{$prefix}tr_freeze_balance_on_ban",
        "{$prefix}tr_clear_ban_on_unban",
        "{$prefix}tr_unfreeze_balance_on_unban",
    ];

    // Drop events
    $events = [
        "{$prefix}cashback_ev_confirmed_cashback",
        "{$prefix}cashback_ev_cleanup_cashback_webhooks_old",
        "{$prefix}cashback_ev_cleanup_click_log",
        "{$prefix}cashback_ev_mark_inactive_profiles",
    ];

    // Drop triggers
    foreach ($triggers as $trigger) {
        $wpdb->query($wpdb->prepare("DROP TRIGGER IF EXISTS `%i`", $trigger));
    }

    // Drop events
    foreach ($events as $event) {
        $wpdb->query($wpdb->prepare("DROP EVENT IF EXISTS `%i`", $event));
    }

    // Удаление файлов вложений поддержки
    $upload_dir = wp_upload_dir();
    $support_dir = $upload_dir['basedir'] . '/cashback-support';
    if (is_dir($support_dir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($support_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($support_dir);
    }

    // Drop tables (in reverse order to respect foreign keys)
    foreach (array_reverse($tables) as $table) {
        $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS `%i`", $table));
    }

    // Delete plugin options
    $options = [
        'cashback_support_module_enabled',
        'cashback_support_attachments_enabled',
        'cashback_support_max_file_size',
        'cashback_support_max_files_per_message',
        'cashback_support_allowed_extensions',
        'cashback_plugin_version',
        'cashback_plugin_db_version',
        'cashback_encryption_migrated',
        // Antifraud settings
        'cashback_fraud_enabled',
        'cashback_fraud_max_users_per_ip',
        'cashback_fraud_max_users_per_fingerprint',
        'cashback_fraud_max_withdrawals_per_day',
        'cashback_fraud_max_withdrawals_per_week',
        'cashback_fraud_cancellation_rate_threshold',
        'cashback_fraud_cancellation_min_transactions',
        'cashback_fraud_amount_anomaly_multiplier',
        'cashback_fraud_new_account_cooling_days',
        'cashback_fraud_auto_hold_amount',
        'cashback_fraud_max_accounts_per_details_hash',
        'cashback_fraud_fingerprint_retention_days',
        'cashback_fraud_auto_flag_threshold',
        'cashback_fraud_email_notification_enabled',
        'cashback_fraud_last_run',
    ];

    foreach ($options as $option) {
        delete_option($option);
    }

    // Delete affiliate network and product params post_meta from all products
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_affiliate_network_id'");
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_affiliate_product_params'");
    // Clean up old affiliate param meta (if any remain from pre-2.0)
    $wpdb->query(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_affiliate_param_%_key' OR meta_key LIKE '_affiliate_param_%_value'"
    );

    // Delete transients
    delete_transient('cashback_support_flush_rules');

    // Delete rate limiting transients (cb_clk_* pattern)
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cb_clk_%' OR option_name LIKE '_transient_timeout_cb_clk_%'"
    );

    // Delete encryption key file
    $key_file = WP_CONTENT_DIR . '/.cashback-encryption-key.php';
    if (file_exists($key_file)) {
        unlink($key_file);
    }

    // Clear any cached data
    wp_cache_flush();

    // Log uninstall completion
    error_log('Cashback Plugin: Successfully uninstalled and cleaned up all data');
}

// Execute uninstall
cashback_plugin_uninstall();

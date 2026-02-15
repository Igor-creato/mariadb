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
    $timestamp = wp_next_scheduled('cashback_support_auto_delete_cron');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'cashback_support_auto_delete_cron');
    }

    // Validate table prefix for security
    $prefix = $wpdb->prefix;
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
        error_log('Cashback Plugin Uninstall: Invalid table prefix detected');
        return;
    }

    // List of tables to drop
    $tables = [
        "{$prefix}cashback_payout_requests",
        "{$prefix}cashback_transactions",
        "{$prefix}cashback_unregistered_transactions",
        "{$prefix}cashback_user_balance",
        "{$prefix}cashback_webhooks",
        "{$prefix}cashback_user_profile",
        "{$prefix}cashback_payout_methods",
        "{$prefix}cashback_banks",
        "{$prefix}cashback_support_tickets",
        "{$prefix}cashback_support_messages",
        "{$prefix}cashback_audit_log",
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
    ];

    // Drop events
    $events = [
        "{$prefix}cashback_ev_confirmed_cashback",
        "{$prefix}cashback_ev_cleanup_cashback_webhooks_old",
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

    // Drop tables (in reverse order to respect foreign keys)
    foreach (array_reverse($tables) as $table) {
        $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS `%i`", $table));
    }

    // Delete plugin options
    $options = [
        'cashback_support_module_enabled',
        'cashback_plugin_version',
        'cashback_plugin_db_version',
        'cashback_encryption_migrated',
    ];

    foreach ($options as $option) {
        delete_option($option);
    }

    // Delete transients
    delete_transient('cashback_support_flush_rules');

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

<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс для работы с базой данных модуля поддержки
 */
class Cashback_Support_DB
{
    /**
     * Создание таблиц модуля поддержки
     */
    public static function create_tables(): void
    {
        global $wpdb;

        $charset_collate = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $tickets_table = $wpdb->prefix . 'cashback_support_tickets';
        $messages_table = $wpdb->prefix . 'cashback_support_messages';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_tickets = "CREATE TABLE IF NOT EXISTS `{$tickets_table}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL,
            `subject` varchar(255) NOT NULL,
            `priority` enum('urgent','normal','not_urgent') NOT NULL DEFAULT 'not_urgent',
            `status` enum('open','answered','closed') NOT NULL DEFAULT 'open',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `closed_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_status` (`status`),
            KEY `idx_priority` (`priority`),
            KEY `idx_status_updated` (`status`, `updated_at`)
        ) {$charset_collate};";

        $sql_messages = "CREATE TABLE IF NOT EXISTS `{$messages_table}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `ticket_id` bigint(20) unsigned NOT NULL,
            `user_id` bigint(20) unsigned NOT NULL,
            `message` text NOT NULL,
            `is_admin` tinyint(1) NOT NULL DEFAULT 0,
            `is_read` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ticket_id` (`ticket_id`),
            KEY `idx_is_read_admin` (`is_admin`, `is_read`)
        ) {$charset_collate};";

        dbDelta($sql_tickets);
        dbDelta($sql_messages);

        // Добавляем внешние ключи после создания таблиц
        $fk_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_NAME = 'fk_support_ticket_user'
             AND TABLE_SCHEMA = DATABASE()"
        );
        if (!$fk_exists) {
            $wpdb->query("ALTER TABLE `{$tickets_table}`
                ADD CONSTRAINT `fk_support_ticket_user`
                FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->users}` (`ID`) ON DELETE CASCADE");
        }

        $fk_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_NAME = 'fk_support_message_ticket'
             AND TABLE_SCHEMA = DATABASE()"
        );
        if (!$fk_exists) {
            $wpdb->query("ALTER TABLE `{$messages_table}`
                ADD CONSTRAINT `fk_support_message_ticket`
                FOREIGN KEY (`ticket_id`) REFERENCES `{$tickets_table}` (`id`) ON DELETE CASCADE");
            $wpdb->query("ALTER TABLE `{$messages_table}`
                ADD CONSTRAINT `fk_support_message_user`
                FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->users}` (`ID`) ON DELETE CASCADE");
        }
    }

    /**
     * Проверить, включен ли модуль поддержки
     */
    public static function is_module_enabled(): bool
    {
        return (bool) get_option('cashback_support_module_enabled', 0);
    }

    /**
     * Включить/выключить модуль поддержки
     */
    public static function set_module_enabled(bool $enabled): void
    {
        update_option('cashback_support_module_enabled', $enabled ? 1 : 0);
    }

    /**
     * Получить количество тикетов с непрочитанными сообщениями от пользователей
     */
    public static function get_unread_tickets_count(): int
    {
        global $wpdb;

        $tickets_table = $wpdb->prefix . 'cashback_support_tickets';
        $messages_table = $wpdb->prefix . 'cashback_support_messages';

        $count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT t.id)
             FROM `{$tickets_table}` t
             INNER JOIN `{$messages_table}` m ON t.id = m.ticket_id
             WHERE m.is_admin = 0
             AND m.is_read = 0"
        );

        return (int) $count;
    }

    /**
     * Получить количество тикетов с непрочитанными ответами от админа для конкретного пользователя
     */
    public static function get_unread_admin_replies_count(int $user_id): int
    {
        global $wpdb;

        $tickets_table = $wpdb->prefix . 'cashback_support_tickets';
        $messages_table = $wpdb->prefix . 'cashback_support_messages';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT t.id)
             FROM `{$tickets_table}` t
             INNER JOIN `{$messages_table}` m ON t.id = m.ticket_id
             WHERE t.user_id = %d
             AND m.is_admin = 1
             AND m.is_read = 0",
            $user_id
        ));

        return (int) $count;
    }

    /**
     * Форматировать номер тикета для отображения
     */
    public static function format_ticket_number(int $ticket_id): string
    {
        return '№' . str_pad((string) $ticket_id, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Удалить закрытые тикеты старше 1 месяца.
     * Сообщения удаляются каскадно через FK.
     */
    public static function delete_old_closed_tickets(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_support_tickets';

        $deleted = $wpdb->query(
            "DELETE FROM `{$table}` WHERE status = 'closed' AND closed_at IS NOT NULL AND closed_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)"
        );

        return max(0, (int) $deleted);
    }
}

// Регистрация WP Cron хука для автоудаления
add_action('cashback_support_auto_delete_cron', ['Cashback_Support_DB', 'delete_old_closed_tickets']);

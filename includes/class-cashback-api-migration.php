<?php
/**
 * Миграция БД для API-валидации
 *
 * Создаёт таблицы: cashback_validation_checkpoints, cashback_sync_log.
 * Добавляет колонки API-конфигурации в cashback_affiliate_networks.
 * Добавляет колонку click_id в таблицы транзакций.
 *
 * Безопасна для повторного запуска (идемпотентна).
 *
 * @package CashbackPlugin
 * @since   5.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_API_Migration
{
    /**
     * Запустить все миграции
     *
     * @return void
     */
    public static function run(): void
    {
        self::create_validation_checkpoints_table();
        self::migrate_checkpoint_columns();
        self::create_sync_log_table();
        self::add_api_columns_to_networks();
        self::add_click_id_to_transactions();
        self::insert_default_api_config();

        error_log('Cashback API Migration: All migrations completed');
    }

    /**
     * Таблица чекпоинтов валидации
     *
     * Хранит последнюю дату и результат проверки для каждого пользователя × сети.
     * При повторной валидации запрашиваются данные только с last_validated_date.
     */
    private static function create_validation_checkpoints_table(): void
    {
        global $wpdb;
        $charset_collate = "DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $table = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_validation_checkpoints` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL,
            `network_slug` varchar(100) NOT NULL COMMENT 'Slug CPA-сети (admitad, epn)',
            `last_validated_date` date NOT NULL COMMENT 'До какой даты данные проверены',
            `api_sum_approved` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма approved по API',
            `api_sum_pending` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма pending по API',
            `api_sum_declined` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма declined по API',
            `api_actions_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Кол-во действий в API',
            `local_sum_approved` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма approved локально',
            `local_sum_pending` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма pending локально',
            `local_sum_declined` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма declined локально',
            `local_transactions_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Кол-во транзакций локально',
            `validation_status` enum('match','mismatch','pending','error') NOT NULL DEFAULT 'pending',
            `discrepancy_amount` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Разница между API и локальными данными',
            `matched_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Кол-во совпавших транзакций',
            `mismatch_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Кол-во расхождений',
            `missing_local_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Есть в API, нет локально',
            `missing_api_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Есть локально, нет в API',
            `validated_at` datetime DEFAULT NULL COMMENT 'Когда проводилась валидация',
            `validated_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Кто инициировал валидацию (admin user_id)',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_user_network` (`user_id`, `network_slug`),
            KEY `idx_validation_status` (`validation_status`),
            KEY `idx_validated_at` (`validated_at`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Чекпоинты инкрементальной валидации кэшбэка';";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($table);
    }

    /**
     * Миграция колонок таблицы чекпоинтов
     *
     * Переименовывает admitad_* → api_*, заменяет local_commission_sum
     * на детализированные колонки, добавляет счётчики.
     */
    private static function migrate_checkpoint_columns(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_validation_checkpoints';

        // Переименование admitad_* → api_*
        $renames = [
            'admitad_sum_approved'  => ['api_sum_approved', "decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма approved по API'"],
            'admitad_sum_pending'   => ['api_sum_pending', "decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма pending по API'"],
            'admitad_sum_declined'  => ['api_sum_declined', "decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма declined по API'"],
            'admitad_actions_count' => ['api_actions_count', "int(11) NOT NULL DEFAULT 0 COMMENT 'Кол-во действий в API'"],
        ];

        foreach ($renames as $old_col => [$new_col, $definition]) {
            if (self::column_exists($table, $old_col) && !self::column_exists($table, $new_col)) {
                $wpdb->query("ALTER TABLE `{$table}` CHANGE `{$old_col}` `{$new_col}` {$definition}");
                if ($wpdb->last_error) {
                    error_log("Cashback API Migration Error: Failed to rename {$old_col} → {$new_col}: " . $wpdb->last_error);
                }
            }
        }

        // Замена local_commission_sum → local_sum_approved + добавление pending/declined
        if (self::column_exists($table, 'local_commission_sum') && !self::column_exists($table, 'local_sum_approved')) {
            $wpdb->query("ALTER TABLE `{$table}` CHANGE `local_commission_sum` `local_sum_approved` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма approved локально'");
            if ($wpdb->last_error) {
                error_log("Cashback API Migration Error: Failed to rename local_commission_sum: " . $wpdb->last_error);
            }
        }

        // Новые колонки
        $new_columns = [
            'local_sum_pending'  => "ALTER TABLE `{$table}` ADD COLUMN `local_sum_pending` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма pending локально' AFTER `local_sum_approved`",
            'local_sum_declined' => "ALTER TABLE `{$table}` ADD COLUMN `local_sum_declined` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма declined локально' AFTER `local_sum_pending`",
            'matched_count'      => "ALTER TABLE `{$table}` ADD COLUMN `matched_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Кол-во совпавших транзакций' AFTER `discrepancy_amount`",
            'mismatch_count'     => "ALTER TABLE `{$table}` ADD COLUMN `mismatch_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Кол-во расхождений' AFTER `matched_count`",
            'missing_local_count' => "ALTER TABLE `{$table}` ADD COLUMN `missing_local_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Есть в API, нет локально' AFTER `mismatch_count`",
            'missing_api_count'  => "ALTER TABLE `{$table}` ADD COLUMN `missing_api_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Есть локально, нет в API' AFTER `missing_local_count`",
        ];

        foreach ($new_columns as $col_name => $alter_sql) {
            if (!self::column_exists($table, $col_name)) {
                $wpdb->query($alter_sql);
                if ($wpdb->last_error) {
                    error_log("Cashback API Migration Error: Failed to add {$col_name}: " . $wpdb->last_error);
                }
            }
        }
    }

    /**
     * Таблица лога синхронизации
     *
     * Записывает каждое изменение статуса транзакции через API-синхронизацию.
     * Audit trail для споров с пользователями.
     */
    private static function create_sync_log_table(): void
    {
        global $wpdb;
        $charset_collate = "DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $table = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_sync_log` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `network_slug` varchar(100) NOT NULL COMMENT 'Slug CPA-сети',
            `transaction_id` bigint(20) unsigned NOT NULL COMMENT 'ID локальной транзакции',
            `action_id` varchar(255) DEFAULT NULL COMMENT 'ID действия в CPA-сети',
            `old_status` varchar(50) NOT NULL COMMENT 'Статус до синхронизации',
            `new_status` varchar(50) NOT NULL COMMENT 'Статус после синхронизации',
            `api_payment` decimal(18,2) DEFAULT NULL COMMENT 'Сумма комиссии по API',
            `sync_type` enum('cron','manual','webhook') NOT NULL DEFAULT 'cron' COMMENT 'Источник синхронизации',
            `synced_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_transaction_id` (`transaction_id`),
            KEY `idx_network_synced` (`network_slug`, `synced_at`),
            KEY `idx_synced_at` (`synced_at`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Лог синхронизации статусов транзакций через API';";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($table);
    }

    /**
     * Добавить колонки API-конфигурации в таблицу партнёрских сетей
     *
     * Позволяет хранить endpoint, credentials, маппинг полей и статусов
     * для каждой сети прямо в существующей таблице.
     */
    private static function add_api_columns_to_networks(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_affiliate_networks';

        $columns = [
            'api_base_url' => "ALTER TABLE `{$table}` ADD COLUMN `api_base_url` varchar(500) DEFAULT NULL
                COMMENT 'Base URL API сети (например https://api.admitad.com)' AFTER `notes`",

            'api_credentials' => "ALTER TABLE `{$table}` ADD COLUMN `api_credentials` BLOB DEFAULT NULL
                COMMENT 'AES-256-CBC зашифрованные credentials (JSON)' AFTER `api_base_url`",

            'api_user_field' => "ALTER TABLE `{$table}` ADD COLUMN `api_user_field` varchar(100) DEFAULT NULL
                COMMENT 'Имя поля в API, содержащего user_id (subid для Admitad)' AFTER `api_credentials`",

            'api_click_field' => "ALTER TABLE `{$table}` ADD COLUMN `api_click_field` varchar(100) DEFAULT NULL
                COMMENT 'Имя поля в API, содержащего click_id (subid1 для Admitad)' AFTER `api_user_field`",

            'api_status_map' => "ALTER TABLE `{$table}` ADD COLUMN `api_status_map` text DEFAULT NULL
                COMMENT 'JSON маппинг статусов сети → локальные' AFTER `api_click_field`",

            'api_actions_endpoint' => "ALTER TABLE `{$table}` ADD COLUMN `api_actions_endpoint` varchar(500) DEFAULT NULL
                COMMENT 'Endpoint для получения действий (/statistics/actions/)' AFTER `api_status_map`",

            'api_token_endpoint' => "ALTER TABLE `{$table}` ADD COLUMN `api_token_endpoint` varchar(500) DEFAULT NULL
                COMMENT 'Endpoint для получения токена (/token/)' AFTER `api_actions_endpoint`",

            'api_website_id' => "ALTER TABLE `{$table}` ADD COLUMN `api_website_id` varchar(100) DEFAULT NULL
                COMMENT 'ID площадки в CPA-сети (для фильтрации)' AFTER `api_token_endpoint`",
        ];

        foreach ($columns as $col_name => $alter_sql) {
            if (!self::column_exists($table, $col_name)) {
                $wpdb->query($alter_sql);
                if ($wpdb->last_error) {
                    error_log("Cashback API Migration Error: Failed to add column {$col_name}: " . $wpdb->last_error);
                } else {
                    error_log("Cashback API Migration: Added column {$col_name} to {$table}");
                }
            }
        }
    }

    /**
     * Добавить колонку click_id в таблицы транзакций
     *
     * click_id — сквозной идентификатор: клик → webhook → транзакция.
     * Используется для привязки неавторизованных пользователей и точной сверки.
     */
    private static function add_click_id_to_transactions(): void
    {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'cashback_transactions',
            $wpdb->prefix . 'cashback_unregistered_transactions',
        ];

        foreach ($tables as $table) {
            if (!self::column_exists($table, 'click_id')) {
                $wpdb->query(
                    "ALTER TABLE `{$table}` ADD COLUMN `click_id` char(32) DEFAULT NULL
                     COMMENT 'UUID клика из cashback_click_log, передаётся как SubID1/click_id в CPA'
                     AFTER `uniq_id`"
                );

                if ($wpdb->last_error) {
                    error_log("Cashback API Migration Error: Failed to add click_id to {$table}: " . $wpdb->last_error);
                } else {
                    // Индекс для привязки неавторизованных пользователей
                    $idx_name = 'idx_click_id';
                    $idx_exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
                        $table,
                        $idx_name
                    ));

                    if (!$idx_exists) {
                        $wpdb->query("ALTER TABLE `{$table}` ADD KEY `{$idx_name}` (`click_id`)");
                    }

                    error_log("Cashback API Migration: Added click_id column to {$table}");
                }
            }
        }
    }

    /**
     * Заполнить дефолтную API-конфигурацию для Admitad и EPN
     *
     * Только если колонки api_base_url пустые (не перезаписывает ручную настройку).
     */
    private static function insert_default_api_config(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_affiliate_networks';

        // Admitad
        $admitad_url = $wpdb->get_var($wpdb->prepare(
            "SELECT api_base_url FROM {$table} WHERE slug = %s",
            'admitad'
        ));

        if ($admitad_url === null || $admitad_url === '') {
            $wpdb->update(
                $table,
                [
                    'api_base_url'         => 'https://api.admitad.com',
                    'api_token_endpoint'   => '/token/',
                    'api_actions_endpoint' => '/statistics/actions/',
                    'api_user_field'       => 'subid',
                    'api_click_field'      => 'subid1',
                    'api_status_map'       => wp_json_encode([
                        'pending'  => 'waiting',
                        'approved' => 'completed',
                        'declined' => 'declined',
                        'rejected' => 'declined',
                        'open'     => 'waiting',
                        'hold'     => 'waiting',
                    ]),
                ],
                ['slug' => 'admitad']
            );
        }

        // EPN
        $epn_url = $wpdb->get_var($wpdb->prepare(
            "SELECT api_base_url FROM {$table} WHERE slug = %s",
            'epn'
        ));

        if ($epn_url === null || $epn_url === '') {
            $wpdb->update(
                $table,
                [
                    'api_base_url'         => 'https://api.epn.bz',
                    'api_token_endpoint'   => '/token',
                    'api_actions_endpoint' => '/creative/actions',
                    'api_user_field'       => 'sub',
                    'api_click_field'      => 'click_id',
                    'api_status_map'       => wp_json_encode([
                        'pending'  => 'waiting',
                        'approved' => 'completed',
                        'rejected' => 'declined',
                        'canceled' => 'declined',
                        'hold'     => 'waiting',
                    ]),
                ],
                ['slug' => 'epn']
            );
        }
    }

    /**
     * Проверяет существование колонки в таблице
     */
    private static function column_exists(string $table, string $column): bool
    {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table,
            $column
        ));
        return (int) $result > 0;
    }
}

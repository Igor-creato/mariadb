<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Защита от прямого доступа
}

/**
 * Основной класс плагина для управления базой данных кэшбэка
 */
class Mariadb_Plugin
{
    /**
     * Экземпляр класса (singleton)
     */
    private static $instance = null;

    /**
     * Получить экземпляр класса
     *
     * @return self
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Конструктор
     */
    private function __construct()
    {
        // Инициализация плагина
        add_action('user_register', array($this, 'add_user_to_cashback_tables'));
    }

    /**
     * Валидация и санитизация префикса таблицы
     * Защита от потенциальных SQL-инъекций через префикс
     *
     * @param string $prefix Префикс таблицы
     * @return string Безопасный префикс
     * @throws Exception Если префикс содержит недопустимые символы
     */
    private function validate_table_prefix(string $prefix): string
    {
        // Префикс может содержать только буквы, цифры и подчеркивания
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new Exception('Invalid table prefix detected: ' . esc_html($prefix));
        }
        return $prefix;
    }

    /**
     * Активация плагина
     *
     * @return void
     */
    public static function activate(): void
    {
        $instance = self::get_instance();

        // Подавляем вывод при создании таблиц и триггеров
        ob_start();

        try {
            $instance->create_tables();
            $instance->create_triggers();
            $instance->create_events();
            $instance->initialize_existing_users();

            ob_end_clean();
        } catch (Exception $e) {
            ob_end_clean();
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error('Mariadb Plugin Activation Error: ' . $e->getMessage());
            }
            error_log('Mariadb Plugin Activation Error: ' . $e->getMessage());
            wp_die('Ошибка активации плагина Mariadb: ' . esc_html($e->getMessage()));
        }
    }

    /**
     * Создание таблиц
     */
    private function create_tables()
    {
        global $wpdb;

        $charset_collate = "DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        // Таблица cashback_payout_requests с защитой от дублирования
        $table1 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_payout_requests` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL,
            `total_amount` decimal(18,2) NOT NULL,
            `payout_method` varchar(50) DEFAULT NULL COMMENT 'Slug способа выплаты из cashback_payout_methods',
            `payout_account` varchar(255) NOT NULL COMMENT 'Реквизиты получателя (номер телефона, карты и т.п.)',
            `provider` varchar(100) DEFAULT NULL COMMENT 'Идентификатор провайдера выплат (банк/сервис)',
            `provider_payout_id` varchar(255) DEFAULT NULL COMMENT 'ID операции у провайдера',
            `idempotency_key` char(64) NOT NULL COMMENT 'Ключ идемпотентности для предотвращения дублирования выплат',
            `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'Количество попыток отправки выплаты',
            `fail_reason` text DEFAULT NULL COMMENT 'Код/описание ошибки последней попытки',
            `status` enum('waiting','processing','paid','failed','declined','needs_retry') NOT NULL DEFAULT 'waiting',
            `refunded_at` datetime DEFAULT NULL COMMENT 'Время возврата средств после failed-статуса',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_idempotency` (`idempotency_key`) COMMENT 'Гарантирует уникальность заявки на выплату',
            KEY `idx_user_status` (`user_id`,`status`),
            KEY `idx_status_updated` (`status`,`updated_at`),
            KEY `idx_provider_payout_id` (`provider_payout_id`),
            KEY `idx_refunded` (`refunded_at`),
            KEY `idx_payout_method_slug` (`payout_method`),
            KEY `idx_user_created` (`user_id`,`created_at` DESC),
            CONSTRAINT `fk_payout_user` FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE,
            CHECK (total_amount > 0)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Заявки на выплаты с защитой от дублирования';";

        // Таблица cashback_transactions
        $table2 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_transactions` (
           `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
           `user_id` bigint(20) unsigned NOT NULL,
           `order_number` varchar(255) NOT NULL,
           `offer_name` varchar(255) DEFAULT NULL,
           `order_status` enum('waiting','completed','declined','balance') NOT NULL DEFAULT 'waiting',
           `partner` varchar(255) DEFAULT NULL,
           `sum_order` decimal(10,2) DEFAULT NULL,
           `comission` decimal(10,2) DEFAULT NULL,
           `uniq_id` varchar(255) DEFAULT NULL,
           `cashback` decimal(10,2) DEFAULT NULL,
           `applied_cashback_rate` decimal(5,2) NOT NULL DEFAULT 60.00 COMMENT 'Процент кэшбэка на момент создания транзакции',
           `processed_at` datetime DEFAULT NULL  COMMENT 'Когда транзакция была учтена в балансе',
           `processed_batch_id` char(36) DEFAULT NULL COMMENT 'UUID батча начисления',
           `idempotency_key` varchar(64) DEFAULT NULL COMMENT 'Ключ идемпотентности для предотвращения дублирования транзакций',
           `created_at` timestamp NULL DEFAULT current_timestamp(),
           `updated_at` timestamp NULL DEFAULT current_timestamp()
           ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_uniq_partner` (`uniq_id`,`partner`),
            UNIQUE KEY `idx_idempotency_key` (`idempotency_key`),
            KEY `user_id` (`user_id`),
            KEY `idx_user_created` (`user_id`,`created_at` DESC),
            KEY `idx_order_status_updated_cashback` (`order_status`,`updated_at`,`cashback`),
            KEY `idx_processed` (`processed_at`),
            KEY `idx_processed_batch_id` (`processed_batch_id`),
            CONSTRAINT `fk_transactions_user`
            FOREIGN KEY (`user_id`)
            REFERENCES `{$wpdb->prefix}users` (`ID`)
            ON DELETE CASCADE,
            CONSTRAINT `chk_applied_cashback_rate_range`
            CHECK (`applied_cashback_rate` BETWEEN 0.00 AND 100.00),
            CONSTRAINT `chk_cashback_positive`
            CHECK (`cashback` >= 0)
        ) ENGINE=InnoDB {$charset_collate};";



        // Таблица cashback_unregistered_transactions
        $table3 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_unregistered_transactions` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` varchar(255) NOT NULL,
            `order_number` varchar(255) NOT NULL,
            `offer_name` varchar(255) DEFAULT NULL,
            `order_status`  enum('waiting','completed','declined','balance') NOT NULL DEFAULT 'waiting',
            `partner` varchar(255) DEFAULT NULL,
            `sum_order` decimal(10,2) DEFAULT NULL,
            `comission` decimal(10,2) DEFAULT NULL,
            `uniq_id` varchar(255) DEFAULT NULL,
            `cashback` decimal(10,2) DEFAULT NULL,
            `user_agent` text DEFAULT NULL,
            `click_time` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_uniq_partner` (`uniq_id`,`partner`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Вэбхуки принятые от неавторизованных пользователей';";

        // Таблица cashback_user_balance
        $table4 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_user_balance` (
            `user_id` bigint(20) unsigned NOT NULL,
            `available_balance` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Доступный баланс пользователя',
            `pending_balance`   decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'В ожидании выплаты',
            `paid_balance`      decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Выплачен',
            `frozen_balance`    decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Заблокирован',
            `version` int unsigned NOT NULL DEFAULT 0 COMMENT 'Версия строки для защиты от гонок',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`),
            CONSTRAINT `fk_balance_user`
            FOREIGN KEY (`user_id`)
            REFERENCES `{$wpdb->prefix}users` (`ID`)
            ON DELETE CASCADE,
            CHECK (available_balance >= 0),
            CHECK (pending_balance >= 0),
            CHECK (paid_balance >= 0),
            CHECK (frozen_balance >= 0)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Балансы пользователей кэшбэк-сервиса';";

        // Таблица cashback_webhooks
        $table5 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_webhooks` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `received_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
            `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
            `payload_norm` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS (json_normalize(`payload`)) VIRTUAL CHECK (json_valid(`payload_norm`)),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_payload_norm` (`payload_norm`) USING HASH,
            KEY `idx_received_at` (`received_at`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Сырые уникальные webhooks';";

        // Таблица cashback_user_profile
        $table6 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_user_profile` (
            `user_id` bigint(20) unsigned NOT NULL,
            `payout_method_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID способа выплаты, привязанного к wp_cashback_payout_methods.id',
            `payout_account` varchar(255) DEFAULT NULL COMMENT 'Телефон, номер карты или кошелёк',
            `payout_full_name` varchar(255) DEFAULT NULL COMMENT 'ФИО для выплат',
            `bank_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID банка, привязанного к wp_cashback_banks.id',
            `cashback_rate` decimal(5,2) NOT NULL DEFAULT 60.00 COMMENT 'Процент кэшбэка (60 = 60%)' CHECK (`cashback_rate` BETWEEN 0.00 AND 100.00),
            `is_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = реквизиты подтверждены',
            `payout_details_updated_at` datetime DEFAULT NULL COMMENT 'Дата и время обновления реквизитов',
            `min_payout_amount` decimal(18,2) DEFAULT 100.00 COMMENT 'Минимальная сумма выплаты',
            `opt_out` tinyint(1) NOT NULL DEFAULT 0,
            `status` enum('active','noactive','banned','deleted') NOT NULL DEFAULT 'active' COMMENT 'Статус профиля',
            `banned_at` datetime DEFAULT NULL COMMENT 'Дата и время блокировки',
            `ban_reason` varchar(25) DEFAULT NULL COMMENT 'Причина блокировки',
            `last_active_at` datetime DEFAULT NULL COMMENT 'Дата и времени последней активности',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`user_id`),
            KEY `idx_active_check` (`status`,`last_active_at`,`created_at`),
            KEY `idx_payout_method` (`payout_method_id`),
            KEY `idx_bank_id` (`bank_id`),
            CONSTRAINT `fk_profile_wp_user` FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE,
            CONSTRAINT `fk_payout_method` FOREIGN KEY (`payout_method_id`) REFERENCES `{$wpdb->prefix}cashback_payout_methods` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_bank_id` FOREIGN KEY (`bank_id`) REFERENCES `{$wpdb->prefix}cashback_banks` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB {$charset_collate};";

        // Таблица способов выплат
        $table7 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_payout_methods` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `slug` varchar(50) NOT NULL COMMENT 'Уникальный идентификатор (например: sbp, mir, yoomoney)',
            `name` varchar(100) NOT NULL COMMENT 'Отображаемое название (например: СБП, МИР, ЮMoney)',
            `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = способ доступен для выбора',
            `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Порядок сортировки в интерфейсе',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_slug` (`slug`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Способы выплат пользователей';";

        // Таблица банков
        $table8 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_banks` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `bank_code` varchar(50) NOT NULL COMMENT 'Уникальный код банка (например: sber, tinkoff, vtbc)',
            `name` varchar(100) NOT NULL COMMENT 'Полное название банка',
            `short_name` varchar(50) DEFAULT NULL COMMENT 'Краткое название банка',
            `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = банк доступен для выбора',
            `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Порядок сортировки в интерфейсе',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_bank_code` (`bank_code`),
            KEY `idx_active_sort_name` (`is_active`,`sort_order`,`name`) COMMENT 'Оптимизация выборки активных банков с сортировкой',
            KEY `idx_name_active` (`name`,`is_active`) COMMENT 'Оптимизация поиска банков по названию'
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Список банков для выплат';";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($table1);
        dbDelta($table2);
        dbDelta($table3);
        dbDelta($table4);
        dbDelta($table5);
        dbDelta($table7); // Создаем payout_methods ПЕРЕД user_profile
        dbDelta($table8); // Создаем banks ПЕРЕД user_profile
        dbDelta($table6); // Создаем user_profile после payout_methods и banks

        // Инициализация начальных данных в справочные таблицы
        $this->insert_default_payout_methods();
        $this->insert_default_banks();

        // Миграция: FK на payout_method (для существующих установок)
        $this->migrate_payout_method_fk();

        // Добавление индексов производительности (для существующих установок)
        $this->add_performance_indexes();

        // Таблица аудит-лога
        $this->create_audit_log_table();

        // Добавление колонок шифрования (для существующих установок)
        $this->add_encryption_columns();

        // Миграция существующих данных в зашифрованный формат
        $this->migrate_encrypt_existing_data();

        error_log('Mariadb Plugin: Tables created successfully');
    }

    /**
     * Инициализация начальных способов выплат
     *
     * @return void
     */
    private function insert_default_payout_methods(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_payout_methods';

        // Проверяем, есть ли уже записи
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            error_log('Mariadb Plugin: Payout methods already exist, skipping initialization');
            return;
        }

        // Начальные способы выплат
        $defaults = [
            ['slug' => 'sbp', 'name' => 'СБП Система быстрых платежей', 'is_active' => 1, 'sort_order' => 1],
        ];

        foreach ($defaults as $method) {
            $wpdb->insert($table, $method, ['%s', '%s', '%d', '%d']);
            if ($wpdb->last_error) {
                error_log('Mariadb Plugin Error: Failed to insert payout method: ' . $wpdb->last_error);
            }
        }

        error_log('Mariadb Plugin: Initialized ' . count($defaults) . ' default payout methods');
    }

    /**
     * Инициализация начальных банков
     *
     * @return void
     */
    private function insert_default_banks(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_banks';

        // Проверяем, есть ли уже записи
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            error_log('Mariadb Plugin: Banks already exist, skipping initialization');
            return;
        }

        // Начальные банки
        $defaults = [
            ['bank_code' => 'sber', 'name' => 'Сбербанк', 'short_name' => 'Сбербанк', 'is_active' => 1, 'sort_order' => 1],
        ];

        foreach ($defaults as $bank) {
            $wpdb->insert($table, $bank, ['%s', '%s', '%s', '%d', '%d']);
            if ($wpdb->last_error) {
                error_log('Mariadb Plugin Error: Failed to insert bank: ' . $wpdb->last_error);
            }
        }

        error_log('Mariadb Plugin: Initialized ' . count($defaults) . ' default banks');
    }

    /**
     * Миграция: добавление FK на payout_method в cashback_payout_requests
     *
     * Для существующих установок:
     * 1. Изменяет тип колонки на varchar(50) DEFAULT NULL
     * 2. Конвертирует пустые строки и невалидные значения в NULL
     * 3. Добавляет FK constraint на cashback_payout_methods.slug
     *
     * @return void
     */
    private function migrate_payout_method_fk(): void
    {
        global $wpdb;

        $table_requests = $wpdb->prefix . 'cashback_payout_requests';
        $table_methods = $wpdb->prefix . 'cashback_payout_methods';

        // Проверяем, существует ли FK
        $fk_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_NAME = 'fk_payout_request_method'
             AND TABLE_SCHEMA = DATABASE()"
        );

        if ($fk_exists) {
            return; // FK уже создан
        }

        // Проверяем, существует ли таблица payout_requests (может быть пустая установка)
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table_requests
            )
        );

        if (!$table_exists) {
            return;
        }

        // Шаг 1: Изменяем тип колонки (для существующих таблиц с varchar(255) NOT NULL)
        $wpdb->query(
            "ALTER TABLE `{$table_requests}`
             MODIFY `payout_method` varchar(50) DEFAULT NULL COMMENT 'Slug способа выплаты из cashback_payout_methods'"
        );

        if ($wpdb->last_error) {
            error_log('Mariadb Plugin Error: Failed to alter payout_method column: ' . $wpdb->last_error);
            return;
        }

        // Шаг 2: Конвертируем пустые строки в NULL
        $wpdb->query("UPDATE `{$table_requests}` SET payout_method = NULL WHERE payout_method = ''");

        // Шаг 3: Проверяем и исправляем невалидные значения (не совпадающие со slug)
        $invalid = $wpdb->get_results(
            "SELECT DISTINCT r.payout_method
             FROM `{$table_requests}` r
             LEFT JOIN `{$table_methods}` m ON r.payout_method = m.slug
             WHERE r.payout_method IS NOT NULL AND m.slug IS NULL"
        );

        if (!empty($invalid)) {
            $slugs = array_column($invalid, 'payout_method');
            error_log('Mariadb Plugin Warning: Invalid payout_method values found: ' . implode(', ', $slugs) . '. Setting to NULL.');

            $wpdb->query(
                "UPDATE `{$table_requests}` r
                 LEFT JOIN `{$table_methods}` m ON r.payout_method = m.slug
                 SET r.payout_method = NULL
                 WHERE r.payout_method IS NOT NULL AND m.slug IS NULL"
            );
        }

        // Шаг 4: Добавляем индекс для FK (если dbDelta не создал)
        $idx_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_payout_method_slug'",
                $table_requests
            )
        );

        if (!$idx_exists) {
            $wpdb->query("ALTER TABLE `{$table_requests}` ADD KEY `idx_payout_method_slug` (`payout_method`)");
        }

        // Шаг 5: Создаём FK constraint
        $result = $wpdb->query(
            "ALTER TABLE `{$table_requests}`
             ADD CONSTRAINT `fk_payout_request_method`
             FOREIGN KEY (`payout_method`)
             REFERENCES `{$table_methods}` (`slug`)
             ON DELETE RESTRICT
             ON UPDATE CASCADE"
        );

        if ($result === false) {
            error_log('Mariadb Plugin Error: Failed to add FK fk_payout_request_method: ' . $wpdb->last_error);
        } else {
            error_log('Mariadb Plugin: Added FK constraint fk_payout_request_method');
        }
    }

    /**
     * Добавление индексов производительности для существующих установок
     *
     * dbDelta не всегда создаёт индексы при обновлении схемы,
     * поэтому добавляем вручную если отсутствуют.
     *
     * @return void
     */
    private function add_performance_indexes(): void
    {
        global $wpdb;

        $indexes = [
            // Пагинация истории транзакций: WHERE user_id = %d ORDER BY created_at DESC
            [
                'table' => $wpdb->prefix . 'cashback_transactions',
                'index' => 'idx_user_created',
                'sql' => "CREATE INDEX `idx_user_created` ON `{$wpdb->prefix}cashback_transactions` (`user_id`, `created_at` DESC)"
            ],
            // Пагинация истории выплат: WHERE user_id = %d ORDER BY created_at DESC
            [
                'table' => $wpdb->prefix . 'cashback_payout_requests',
                'index' => 'idx_user_created',
                'sql' => "CREATE INDEX `idx_user_created` ON `{$wpdb->prefix}cashback_payout_requests` (`user_id`, `created_at` DESC)"
            ],
        ];

        foreach ($indexes as $idx) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
                $idx['table'],
                $idx['index']
            ));

            if (!$exists) {
                $wpdb->query($idx['sql']);
                if ($wpdb->last_error) {
                    error_log("Mariadb Plugin Error: Failed to create index {$idx['index']}: " . $wpdb->last_error);
                } else {
                    error_log("Mariadb Plugin: Created index {$idx['index']} on {$idx['table']}");
                }
            }
        }
    }

    /**
     * Создание таблицы аудит-лога
     */
    private function create_audit_log_table(): void
    {
        global $wpdb;
        $charset_collate = "DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $table_audit = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_audit_log` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `action` varchar(100) NOT NULL COMMENT 'Тип действия',
            `actor_id` bigint(20) unsigned NOT NULL COMMENT 'ID пользователя-инициатора',
            `entity_type` varchar(50) DEFAULT NULL COMMENT 'Тип сущности (payout_request, user_profile)',
            `entity_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID сущности',
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text DEFAULT NULL,
            `details` longtext DEFAULT NULL COMMENT 'Доп. данные в JSON',
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_action_actor` (`action`, `actor_id`),
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Аудит-лог действий с чувствительными данными';";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($table_audit);
    }

    /**
     * Добавление колонок шифрования к существующим таблицам
     *
     * dbDelta не всегда добавляет новые колонки, поэтому используем ALTER TABLE.
     */
    private function add_encryption_columns(): void
    {
        global $wpdb;

        $profile_table = $wpdb->prefix . 'cashback_user_profile';
        $requests_table = $wpdb->prefix . 'cashback_payout_requests';

        // === cashback_user_profile: encrypted_details, masked_details, details_hash ===
        $columns_profile = [
            'encrypted_details' => "ALTER TABLE `{$profile_table}` ADD COLUMN `encrypted_details` BLOB DEFAULT NULL COMMENT 'AES-256-CBC зашифрованные реквизиты (JSON)' AFTER `payout_full_name`",
            'masked_details' => "ALTER TABLE `{$profile_table}` ADD COLUMN `masked_details` TEXT DEFAULT NULL COMMENT 'Маскированные реквизиты для отображения (JSON)' AFTER `encrypted_details`",
            'details_hash' => "ALTER TABLE `{$profile_table}` ADD COLUMN `details_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 хеш реквизитов для антифрода' AFTER `masked_details`",
        ];

        foreach ($columns_profile as $col_name => $alter_sql) {
            if (!$this->column_exists($profile_table, $col_name)) {
                $wpdb->query($alter_sql);
                if ($wpdb->last_error) {
                    error_log("Mariadb Plugin Error: Failed to add column {$col_name} to {$profile_table}: " . $wpdb->last_error);
                }
            }
        }

        // Индекс на details_hash для антифрода
        $idx_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_details_hash'",
            $profile_table
        ));
        if (!$idx_exists && $this->column_exists($profile_table, 'details_hash')) {
            $wpdb->query("ALTER TABLE `{$profile_table}` ADD KEY `idx_details_hash` (`details_hash`)");
        }

        // === cashback_payout_requests: encrypted_details, masked_details ===
        $columns_requests = [
            'encrypted_details' => "ALTER TABLE `{$requests_table}` ADD COLUMN `encrypted_details` BLOB DEFAULT NULL COMMENT 'AES-256-CBC зашифрованные реквизиты (снапшот)' AFTER `payout_account`",
            'masked_details' => "ALTER TABLE `{$requests_table}` ADD COLUMN `masked_details` TEXT DEFAULT NULL COMMENT 'Маскированные реквизиты для отображения (JSON)' AFTER `encrypted_details`",
        ];

        foreach ($columns_requests as $col_name => $alter_sql) {
            if (!$this->column_exists($requests_table, $col_name)) {
                $wpdb->query($alter_sql);
                if ($wpdb->last_error) {
                    error_log("Mariadb Plugin Error: Failed to add column {$col_name} to {$requests_table}: " . $wpdb->last_error);
                }
            }
        }

        error_log('Mariadb Plugin: Encryption columns check complete');
    }

    /**
     * Проверяет существование колонки в таблице
     */
    private function column_exists(string $table, string $column): bool
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

    /**
     * Миграция существующих данных: шифрует plaintext реквизиты
     *
     * Работает батчами по 100 записей. Безопасна для повторного запуска.
     */
    private function migrate_encrypt_existing_data(): void
    {
        // Проверяем, настроен ли ключ шифрования
        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            error_log('Mariadb Plugin: Skipping encryption migration — CB_ENCRYPTION_KEY not configured');
            return;
        }

        global $wpdb;
        $profile_table = $wpdb->prefix . 'cashback_user_profile';
        $requests_table = $wpdb->prefix . 'cashback_payout_requests';
        $batch_size = 100;

        // === Миграция профилей пользователей ===
        $offset = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, payout_account, payout_full_name, bank_id
                 FROM {$profile_table}
                 WHERE encrypted_details IS NULL AND payout_account IS NOT NULL AND payout_account != ''
                 LIMIT %d OFFSET %d",
                $batch_size,
                $offset
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                try {
                    $bank_name = '';
                    if (!empty($row['bank_id'])) {
                        $banks_table = $wpdb->prefix . 'cashback_banks';
                        $bank_name = $wpdb->get_var($wpdb->prepare(
                            "SELECT name FROM {$banks_table} WHERE id = %d",
                            $row['bank_id']
                        )) ?: '';
                    }

                    $encrypted = Cashback_Encryption::encrypt_details([
                        'account' => $row['payout_account'],
                        'full_name' => $row['payout_full_name'] ?? '',
                        'bank' => $bank_name,
                    ]);

                    $wpdb->update(
                        $profile_table,
                        [
                            'encrypted_details' => $encrypted['encrypted_details'],
                            'masked_details' => $encrypted['masked_details'],
                            'details_hash' => $encrypted['details_hash'],
                            'payout_account' => '',
                            'payout_full_name' => '',
                        ],
                        ['user_id' => $row['user_id']],
                        ['%s', '%s', '%s', '%s', '%s'],
                        ['%d']
                    );
                } catch (\Exception $e) {
                    error_log("Mariadb Plugin Error: Failed to encrypt profile for user {$row['user_id']}: " . $e->getMessage());
                }
            }

            $offset += $batch_size;
        } while (count($rows) === $batch_size);

        // === Миграция заявок на выплату ===
        // Временно снимаем триггеры, блокирующие UPDATE на заявках с финальным статусом
        $safe_prefix = $this->validate_table_prefix($wpdb->prefix);
        $payout_triggers = [
            "{$safe_prefix}tr_prevent_update_paid_payout",
            "{$safe_prefix}tr_prevent_update_failed_payout",
        ];
        foreach ($payout_triggers as $trigger) {
            $wpdb->query("DROP TRIGGER IF EXISTS `{$trigger}`");
        }

        $offset = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, payout_account, provider
                 FROM {$requests_table}
                 WHERE encrypted_details IS NULL AND payout_account IS NOT NULL AND payout_account != ''
                 LIMIT %d OFFSET %d",
                $batch_size,
                $offset
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                try {
                    $bank_name = '';
                    if (!empty($row['provider'])) {
                        $banks_table = $wpdb->prefix . 'cashback_banks';
                        $bank_name = $wpdb->get_var($wpdb->prepare(
                            "SELECT name FROM {$banks_table} WHERE bank_code = %s",
                            $row['provider']
                        )) ?: '';
                    }

                    $encrypted = Cashback_Encryption::encrypt_details([
                        'account' => $row['payout_account'],
                        'full_name' => '',
                        'bank' => $bank_name,
                    ]);

                    $wpdb->update(
                        $requests_table,
                        [
                            'encrypted_details' => $encrypted['encrypted_details'],
                            'masked_details' => $encrypted['masked_details'],
                            'payout_account' => '',
                        ],
                        ['id' => $row['id']],
                        ['%s', '%s', '%s'],
                        ['%d']
                    );
                } catch (\Exception $e) {
                    error_log("Mariadb Plugin Error: Failed to encrypt payout request {$row['id']}: " . $e->getMessage());
                }
            }

            $offset += $batch_size;
        } while (count($rows) === $batch_size);
        // Триггеры будут воссозданы в create_triggers() сразу после этого метода

        error_log('Mariadb Plugin: Encryption migration pass completed');
    }

    /**
     * Создание триггеров
     */
    private function create_triggers()
    {
        global $wpdb;

        // Валидация префикса таблицы для безопасности
        $safe_prefix = $this->validate_table_prefix($wpdb->prefix);

        // Удаляем существующие триггеры перед созданием новых
        $drop_triggers = [
            "DROP TRIGGER IF EXISTS `{$safe_prefix}calculate_cashback_before_insert`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}calculate_cashback_before_insert_unregistered`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}calculate_cashback_before_update`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}calculate_cashback_before_update_unregistered`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}cashback_tr_prevent_delete_final_status`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}cashback_tr_prevent_update_final_status`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}tr_prevent_delete_paid_payout`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}tr_prevent_update_paid_payout`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}tr_prevent_delete_failed_payout`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}tr_prevent_update_failed_payout`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}tr_banned_user_update_banned_at`;",
        ];

        foreach ($drop_triggers as $drop_trigger) {
            $result = $wpdb->query($drop_trigger);
            if ($result === false) {
                error_log('Mariadb Plugin Warning: Failed to drop trigger. Error: ' . $wpdb->last_error);
            }
        }

        $triggers = [
            "CREATE TRIGGER `{$safe_prefix}calculate_cashback_before_insert`
            BEFORE INSERT ON `{$safe_prefix}cashback_transactions`
            FOR EACH ROW
            -- 'Автоматически рассчитывает кэшбэк при вставке на основе индивидуального cashback_rate пользователя'
            BEGIN
                DECLARE v_rate DECIMAL(5,2) DEFAULT 60.00;

                SELECT cashback_rate INTO v_rate
                FROM `{$safe_prefix}cashback_user_profile`
                WHERE user_id = NEW.user_id
                LIMIT 1;

                SET NEW.applied_cashback_rate = IFNULL(v_rate, 60.00);

                IF NEW.comission IS NOT NULL THEN
                    SET NEW.cashback = ROUND(NEW.comission * IFNULL(v_rate, 60.00) / 100, 2);
                ELSE
                    SET NEW.cashback = 0.00;
                END IF;
            END;",

            "CREATE TRIGGER `{$safe_prefix}calculate_cashback_before_insert_unregistered`
            BEFORE INSERT ON `{$safe_prefix}cashback_unregistered_transactions`
            FOR EACH ROW
            --  'Рассчитывает кэшбэк для незарегистрированных пользователей по фиксированной ставке 60%'
            BEGIN
                SET NEW.cashback = ROUND(NEW.comission * 0.6, 2);
            END;",

            "CREATE TRIGGER `{$safe_prefix}calculate_cashback_before_update`
            BEFORE UPDATE ON `{$safe_prefix}cashback_transactions`
            FOR EACH ROW
            --  'Пересчитывает кэшбэк только при изменении comission, используя сохранённую applied_cashback_rate'
            BEGIN
                IF OLD.comission != NEW.comission THEN
                    SET NEW.cashback = ROUND(NEW.comission * NEW.applied_cashback_rate / 100, 2);
                END IF;
            END;",

            "CREATE TRIGGER `{$safe_prefix}calculate_cashback_before_update_unregistered`
            BEFORE UPDATE ON `{$safe_prefix}cashback_unregistered_transactions`
            FOR EACH ROW
            --  'Пересчитывает кэшбэк для незарегистрированных пользователей при изменении comission'
            BEGIN
                IF OLD.comission != NEW.comission THEN
                    SET NEW.cashback = ROUND(NEW.comission * 0.6, 2);
                END IF;
            END;",

            "CREATE TRIGGER `{$safe_prefix}cashback_tr_prevent_delete_final_status`
            BEFORE DELETE ON `{$safe_prefix}cashback_transactions`
            FOR EACH ROW
            --  'Запрещает удаление транзакций со статусом ''balance'' (финальный статус)'
            BEGIN
                IF OLD.order_status = 'balance' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Удаление запрещено: запись с финальным статусом не может быть удалена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$safe_prefix}cashback_tr_prevent_update_final_status`
            BEFORE UPDATE ON `{$safe_prefix}cashback_transactions`
            FOR EACH ROW
            --  'Запрещает изменение транзакций со статусом ''balance'' (финальный статус)'
            BEGIN
                IF OLD.order_status = 'balance' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: запись с финальным статусом не может быть изменена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$safe_prefix}tr_prevent_delete_paid_payout`
            BEFORE DELETE ON `{$safe_prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Запрещает удаление заявок на выплату со статусом ''paid'' выплачена'
            BEGIN
                IF OLD.status = 'paid' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Удаление запрещено: выплаченная заявка не может быть удалена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$safe_prefix}tr_prevent_update_paid_payout`
            BEFORE UPDATE ON `{$safe_prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Запрещает изменение заявок на выплату со статусом ''paid'' выплачена'
            BEGIN
                IF OLD.status = 'paid' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: выплаченная заявка не может быть изменена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$safe_prefix}tr_prevent_delete_failed_payout`
            BEFORE DELETE ON `{$safe_prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Запрещает удаление заявок на выплату со статусом ''failed'' (возвращено в баланс)'
            BEGIN
                IF OLD.status = 'failed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Удаление запрещено: заявка со статусом failed не может быть удалена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$safe_prefix}tr_prevent_update_failed_payout`
            BEFORE UPDATE ON `{$safe_prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Запрещает изменение заявок на выплату со статусом ''failed'' (возвращено в баланс)'
            BEGIN
                IF OLD.status = 'failed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: заявка со статусом failed не может быть изменена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$safe_prefix}tr_banned_user_update_banned_at`
            BEFORE UPDATE ON `{$safe_prefix}cashback_user_profile`
            FOR EACH ROW
            --  'Обновляет поле banned_at текущей датой и временем при изменении статуса на ''banned'''
            BEGIN
                IF OLD.status != 'banned' AND NEW.status = 'banned' THEN
                    SET NEW.banned_at = NOW();
                END IF;
            END;",
        ];

        $failed_triggers = [];
        foreach ($triggers as $trigger) {
            $result = $wpdb->query($trigger);
            if ($result === false) {
                $failed_triggers[] = $wpdb->last_error;
                error_log('Mariadb Plugin Error: Failed to create trigger. Error: ' . $wpdb->last_error);
            }
        }

        if (!empty($failed_triggers)) {
            throw new Exception('Failed to create one or more triggers: ' . implode('; ', $failed_triggers));
        }

        error_log('Mariadb Plugin: All triggers created successfully');
    }

    /**
     * Создание событий
     */
    private function create_events()
    {
        global $wpdb;

        // Валидация префикса таблицы для безопасности
        $safe_prefix = $this->validate_table_prefix($wpdb->prefix);

        $events = [
            // Событие ежедневно проверяет одобренный кэшбэк если старше n дней переводит в доступный баланс
            // ПОЛНАЯ ЗАЩИТА ОТ ДУБЛИРОВАНИЯ: идемпотентность через processed_at и атомарные операции
            "CREATE EVENT IF NOT EXISTS `{$safe_prefix}cashback_ev_confirmed_cashback`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
ON COMPLETION PRESERVE
ENABLE
DO
BEGIN
    DECLARE v_batch_id CHAR(36);
    DECLARE v_affected_rows INT DEFAULT 0;
    DECLARE v_event_lock INT DEFAULT 0;

    -- Выход при любой ошибке SQL с rollback
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        DO RELEASE_LOCK('cashback_event_lock');
    END;

    -- Блокировка события на уровне СУБД
    SET v_event_lock = GET_LOCK('cashback_event_lock', 0);

    IF v_event_lock = 1 THEN
        -- Генерируем UUID батча
        SET v_batch_id = UUID();

        START TRANSACTION;

        -- Временная таблица текущего батча
        DROP TEMPORARY TABLE IF EXISTS tmp_cashback_batch;

        CREATE TEMPORARY TABLE tmp_cashback_batch (
            transaction_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            cashback DECIMAL(10,2) NOT NULL,
            INDEX idx_user (user_id)
        );

        -- Захватываем транзакции с блокировкой
        INSERT INTO tmp_cashback_batch (transaction_id, user_id, cashback)
        SELECT id, user_id, cashback
        FROM `{$safe_prefix}cashback_transactions`
        WHERE
            order_status = 'completed'
            AND processed_at IS NULL
            AND cashback IS NOT NULL
            AND cashback > 0
            AND updated_at <= DATE_SUB(NOW(), INTERVAL 1 DAY)
        FOR UPDATE;

        SET v_affected_rows = ROW_COUNT();

        IF v_affected_rows > 0 THEN
            -- ШАГ 1: КРИТИЧНО - Сначала маркируем транзакции через processed_at
            -- Это источник истины для идемпотентности
            -- Если после этого шага упадет БД, при повторном запуске эти транзакции НЕ попадут в tmp_cashback_batch
            UPDATE `{$safe_prefix}cashback_transactions` ct
            INNER JOIN tmp_cashback_batch tcb ON ct.id = tcb.transaction_id
            SET
                ct.processed_at = NOW(),
                ct.processed_batch_id = v_batch_id
            WHERE ct.processed_at IS NULL;

            -- ШАГ 2: Начисляем баланс ТОЛЬКО для транзакций с processed_batch_id = v_batch_id
            -- Используем processed_batch_id как источник данных (уже гарантированно уникальные)
            INSERT INTO `{$safe_prefix}cashback_user_balance`
                (user_id, available_balance, version)
            SELECT
                user_id,
                SUM(cashback),
                0
            FROM `{$safe_prefix}cashback_transactions`
            WHERE processed_batch_id = v_batch_id
              AND cashback > 0
            GROUP BY user_id
            ON DUPLICATE KEY UPDATE
                available_balance = available_balance + VALUES(available_balance),
                version = version + 1;

            -- ШАГ 3: Финализируем статус (делаем транзакции неизменяемыми через триггер)
            -- Только если processed_batch_id соответствует текущему батчу
            UPDATE `{$safe_prefix}cashback_transactions`
            SET order_status = 'balance'
            WHERE
                processed_batch_id = v_batch_id
                AND order_status = 'completed';
        END IF;

        DROP TEMPORARY TABLE IF EXISTS tmp_cashback_batch;

        COMMIT;

        -- Освобождаем блокировку
        DO RELEASE_LOCK('cashback_event_lock');
    END IF;
END;",

            // Событие ежедневно проверяет и удаляет старые вебхуки если старше 6 месяцев
            "CREATE EVENT IF NOT EXISTS `{$safe_prefix}cashback_ev_cleanup_cashback_webhooks_old`
            ON SCHEDULE EVERY 1 DAY
            STARTS CURRENT_TIMESTAMP
            ON COMPLETION NOT PRESERVE
            ENABLE
            DO DELETE FROM `{$safe_prefix}cashback_webhooks`
            WHERE received_at < NOW() - INTERVAL 6 MONTH",

            // Событие ежедневно проверяет и помечает неактивные профили если неактивны больше 6 месяцев
            "CREATE EVENT IF NOT EXISTS `{$safe_prefix}cashback_ev_mark_inactive_profiles`
            ON SCHEDULE EVERY 1 DAY
            STARTS CURRENT_TIMESTAMP
            ON COMPLETION PRESERVE
            ENABLE
            DO
            BEGIN
                UPDATE `{$safe_prefix}cashback_user_profile`
                SET status = 'noactive'
                WHERE
                    status = 'active'
                    AND (
                        (last_active_at IS NOT NULL AND last_active_at < DATE_SUB(NOW(), INTERVAL 6 MONTH))
                        OR
                        (last_active_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH))
                    );
            END;"
        ];

        $failed_events = [];
        foreach ($events as $event) {
            $result = $wpdb->query($event);
            if ($result === false) {
                $error = $wpdb->last_error;
                // События могут не поддерживаться на хостинге, логируем но не критично
                error_log('Mariadb Plugin Warning: Failed to create event. This may be normal if your hosting does not support MySQL events. Error: ' . $error);
                $failed_events[] = $error;
            }
        }

        // События опциональны, не прерываем активацию
        if (!empty($failed_events)) {
            error_log('Mariadb Plugin: Some events failed to create (non-critical): ' . implode('; ', $failed_events));
        } else {
            error_log('Mariadb Plugin: All events created successfully');
        }
    }

    /**
     * Инициализация существующих пользователей
     */
    private function initialize_existing_users()
    {
        global $wpdb;

        $users = get_users(array('fields' => 'ID'));

        if (empty($users)) {
            error_log('Mariadb Plugin: No existing users to initialize');
            return;
        }

        foreach ($users as $user_id) {
            $result = $this->add_user_to_cashback_tables((int) $user_id);
            if (!$result) {
                throw new Exception("Failed to initialize user {$user_id}. Error: " . $wpdb->last_error);
            }
        }

        error_log('Mariadb Plugin: Successfully initialized ' . count($users) . ' existing users');
    }

    /**
     * Добавление пользователя в профиль
     *
     * @param int $user_id ID пользователя.
     *
     * @return bool True при успехе, false при ошибке.
     */
    public function add_user_to_profile(int $user_id): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_user_profile';

        $wpdb->query('START TRANSACTION');

        try {
            // INSERT IGNORE атомарно игнорирует дубли по PRIMARY KEY (user_id)
            // Защита от race condition
            $result = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table_name} (user_id, status, created_at) VALUES (%d, 'active', NOW())",
                $user_id
            ));

            // $result = 0 если запись уже существовала (игнорирована)
            // $result > 0 если запись была успешно создана
            $created = ($result > 0);

            if ($created) {
                error_log('Mariadb Plugin: Created new profile for user ID: ' . $user_id);
            }

            // Создаём баланс (независимо от того, был ли создан профиль)
            // Проверка существования баланса внутри метода add_user_to_balance
            $balance_result = $this->add_user_to_balance($user_id, $created);

            if (!$balance_result) {
                throw new Exception('Failed to create user balance');
            }

            $wpdb->query('COMMIT');
            return true;
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('Mariadb Plugin Error: Transaction failed for user ' . $user_id . '. Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Добавление пользователя в баланс
     *
     * @param int  $user_id     ID пользователя.
     * @param bool $is_new_user Флаг нового пользователя (для логирования).
     *
     * @return bool True при успехе, false при ошибке.
     */
    public function add_user_to_balance(int $user_id, bool $is_new_user = true): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_user_balance';

        // INSERT IGNORE атомарно игнорирует дубли по PRIMARY KEY
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table_name}
            (user_id, available_balance, pending_balance, paid_balance, frozen_balance, version, updated_at)
            VALUES (%d, 0.00, 0.00, 0.00, 0.00, 0, NOW())",
            $user_id
        ));

        if ($result === false) {
            error_log('Mariadb Plugin Error: Failed to insert balance for user ' . $user_id . ': ' . $wpdb->last_error);
            return false;
        }

        if ($result > 0 && $is_new_user) {
            error_log('Mariadb Plugin: Created new balance for user ID: ' . $user_id);
        }

        return true;
    }

    /**
     * Добавление пользователя в таблицы кэшбэка при регистрации
     *
     * @param int $user_id ID пользователя.
     *
     * @return bool True при успехе, false при ошибке.
     */
    public function add_user_to_cashback_tables(int $user_id): bool
    {
        global $wpdb;

        // Проверяем, существует ли профиль пользователя
        $table_profile = $wpdb->prefix . 'cashback_user_profile';
        $table_balance = $wpdb->prefix . 'cashback_user_balance';

        $profile_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_profile} WHERE user_id = %d",
            $user_id
        ));

        $balance_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_balance} WHERE user_id = %d",
            $user_id
        ));

        $is_new_user = !$profile_exists && !$balance_exists;

        if ($is_new_user) {
            error_log('Mariadb Plugin: Initializing new user ID: ' . $user_id);
        }

        // Сначала добавляем в профиль, который в свою очередь добавит в баланс
        $result = $this->add_user_to_profile($user_id);

        if ($result && $is_new_user) {
            error_log('Mariadb Plugin: Successfully created cashback profile and balance for user ID: ' . $user_id);
        } elseif ($result && !$is_new_user) {
            error_log('Mariadb Plugin: User ID ' . $user_id . ' already initialized (skipped)');
        } else {
            error_log('Mariadb Plugin Error: Failed to initialize user ID: ' . $user_id);
        }

        return $result;
    }
}

// Инициализация Mariadb_Plugin происходит через CashbackPlugin::initialize_components()
// в файле cashback-plugin.php

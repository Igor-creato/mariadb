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
            $instance->ensure_users_table_innodb();
            $instance->create_tables();
            $instance->migrate_add_reference_id();
            $instance->migrate_add_bank_required();
            $instance->create_triggers();
            $instance->migrate_backfill_webhook_payload_hash();
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
     * Конвертация wp_users в InnoDB если используется MyISAM.
     * Необходимо для создания FK constraints к wp_users.
     */
    private function ensure_users_table_innodb(): void
    {
        global $wpdb;

        $engine = $wpdb->get_var($wpdb->prepare(
            "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $wpdb->users
        ));

        if ($engine && strtolower($engine) !== 'innodb') {
            $wpdb->query("ALTER TABLE `{$wpdb->users}` ENGINE=InnoDB");
            error_log("Mariadb Plugin: Converted {$wpdb->users} from {$engine} to InnoDB");
        }
    }

    /**
     * Создание таблиц
     */
    private function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // ---------------------------------------------------------------
        // Фаза 1: Создание таблиц без FOREIGN KEY / CHECK / GENERATED
        // WordPress dbDelta() и некоторые конфигурации MySQL/MariaDB
        // не поддерживают эти конструкции внутри CREATE TABLE.
        // Ограничения добавляются отдельно в Фазе 2.
        // ---------------------------------------------------------------

        // Таблица способов выплат (справочник, без зависимостей)
        $table_payout_methods = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_payout_methods` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `slug` varchar(50) NOT NULL COMMENT 'Уникальный идентификатор (например: sbp, mir, yoomoney)',
            `name` varchar(100) NOT NULL COMMENT 'Отображаемое название (например: СБП, МИР, ЮMoney)',
            `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = способ доступен для выбора',
            `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Порядок сортировки в интерфейсе',
            `bank_required` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = для этого способа нужно выбрать банк',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_slug` (`slug`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Способы выплат пользователей';";

        // Таблица банков (справочник, без зависимостей)
        $table_banks = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_banks` (
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

        // Таблица партнерских сетей (справочник, без зависимостей)
        $table_affiliate_networks = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_affiliate_networks` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL COMMENT 'Название партнера',
            `slug` varchar(100) NOT NULL COMMENT 'Уникальный идентификатор',
            `notes` text DEFAULT NULL COMMENT 'Примечание',
            `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Порядок сортировки',
            `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = активен',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_slug` (`slug`),
            KEY `idx_active_sort` (`is_active`,`sort_order`,`name`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Партнерские сети';";

        // Таблица параметров партнерских сетей (FK добавляется в Фазе 2)
        $table_affiliate_network_params = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_affiliate_network_params` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `network_id` bigint(20) unsigned NOT NULL COMMENT 'ID партнерской сети',
            `param_name` varchar(100) NOT NULL COMMENT 'Название параметра',
            `param_type` varchar(100) DEFAULT NULL COMMENT 'Значение параметра',
            `default_value` varchar(255) DEFAULT NULL COMMENT 'Значение по умолчанию',
            PRIMARY KEY (`id`),
            KEY `idx_network_id` (`network_id`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Параметры партнерских сетей';";

        // Таблица cashback_payout_requests (FK и CHECK добавляются в Фазе 2)
        $table_payout_requests = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_payout_requests` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `reference_id` varchar(11) NOT NULL DEFAULT '' COMMENT 'Публичный ID заявки формата WD-XXXXXXXX',
            `user_id` bigint(20) unsigned NOT NULL,
            `total_amount` decimal(18,2) NOT NULL,
            `payout_method` varchar(50) DEFAULT NULL COMMENT 'Slug способа выплаты из cashback_payout_methods',
            `payout_account` varchar(255) NOT NULL COMMENT 'Реквизиты получателя (номер телефона, карты и т.п.)',
            `encrypted_details` BLOB DEFAULT NULL COMMENT 'AES-256-CBC зашифрованные реквизиты (снапшот)',
            `masked_details` TEXT DEFAULT NULL COMMENT 'Маскированные реквизиты для отображения (JSON)',
            `provider` varchar(100) DEFAULT NULL COMMENT 'Идентификатор провайдера выплат (банк/сервис)',
            `provider_payout_id` varchar(255) DEFAULT NULL COMMENT 'ID операции у провайдера',
            `idempotency_key` char(36) NOT NULL COMMENT 'UUID v4 идемпотентный ключ от клиента',
            `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'Количество попыток отправки выплаты',
            `fail_reason` text DEFAULT NULL COMMENT 'Код/описание ошибки последней попытки',
            `status` enum('waiting','processing','paid','failed','declined','needs_retry') NOT NULL DEFAULT 'waiting',
            `refunded_at` datetime DEFAULT NULL COMMENT 'Время возврата средств после failed-статуса',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_reference_id` (`reference_id`),
            UNIQUE KEY `uk_idempotency` (`idempotency_key`),
            KEY `idx_user_status` (`user_id`,`status`),
            KEY `idx_status_updated` (`status`,`updated_at`),
            KEY `idx_provider_payout_id` (`provider_payout_id`),
            KEY `idx_refunded` (`refunded_at`),
            KEY `idx_payout_method_slug` (`payout_method`),
            KEY `idx_user_created` (`user_id`,`created_at` DESC)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Заявки на выплаты с защитой от дублирования';";

        // Таблица cashback_transactions (FK и CHECK добавляются в Фазе 2)
        $table_transactions = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_transactions` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL COMMENT 'id пользователя на сайте',
            `order_number` varchar(255) NOT NULL COMMENT 'Номер заказа у партнгера',
            `offer_id` int unsigned DEFAULT NULL COMMENT 'ID партнёрской программы в CPA-сети (advcampaign_id). Стабилен, в отличие от offer_name',
            `offer_name` varchar(255) DEFAULT NULL COMMENT 'Название конкретного партнера например Алиэкспресс',
            `order_status` enum('waiting','completed','declined','hold','balance') NOT NULL DEFAULT 'waiting' COMMENT 'Статусы конверсии',
            `partner` varchar(255) DEFAULT NULL COMMENT 'Название CPA',
            `sum_order` decimal(10,2) DEFAULT NULL COMMENT 'Сумма заказа или покупки',
            `comission` decimal(10,2) DEFAULT NULL COMMENT 'Комиссия выплачиваемая за покупку',
            `currency` char(3) NOT NULL DEFAULT 'RUB' COMMENT 'Валюта комиссии (ISO 4217). Без неё невозможно корректно сравнивать суммы',
            `uniq_id` varchar(255) DEFAULT NULL COMMENT 'Уникальный id конверсии в внутри конкретной CPA, между несколькими могут совпадать',
            `cashback` decimal(10,2) DEFAULT NULL COMMENT 'Размер выплачиваемого кэшбэка',
            `applied_cashback_rate` decimal(5,2) NOT NULL DEFAULT 60.00 COMMENT 'Процент кэшбэка на момент создания транзакции',
            `api_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = Транзакция сверена с API. Основной триггер начисления в баланс',
            `action_date` datetime DEFAULT NULL COMMENT 'Реальное время покупки. НЕ путать с created_at (время получения хука)',
            `click_time` datetime DEFAULT NULL COMMENT 'Время клика. Для антифрода: action_date - click_time = 0 бот',
            `click_id` char(32) DEFAULT NULL COMMENT 'UUID клика, связь с cashback_click_log.click_id',
            `website_id` int unsigned DEFAULT NULL COMMENT 'ID площадки в CPA-сети',
            `action_type` varchar(10) DEFAULT NULL COMMENT 'sale/lead. Для корректного расчёта при нескольких тарифах',
            `processed_at` datetime DEFAULT NULL COMMENT 'Когда транзакция была учтена в балансе',
            `processed_batch_id` char(36) DEFAULT NULL COMMENT 'UUID батча начисления',
            `idempotency_key` varchar(64) DEFAULT NULL COMMENT 'Ключ идемпотентности для предотвращения дублирования транзакций',
            `spam_click` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = транзакция из подозрительного клика, кэшбэк только после ручной проверки',
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_uniq_partner` (`uniq_id`,`partner`),
            UNIQUE KEY `idx_idempotency_key` (`idempotency_key`),
            KEY `user_id` (`user_id`),
            KEY `idx_user_created` (`user_id`,`created_at` DESC),
            KEY `idx_processed` (`processed_at`),
            KEY `idx_processed_batch_id` (`processed_batch_id`),
            KEY `idx_click_id` (`click_id`),
            KEY `idx_offer_id` (`offer_id`),
            KEY `idx_balance_candidates` (`order_status`,`api_verified`,`processed_at`,`spam_click`,`cashback`)
        ) ENGINE=InnoDB {$charset_collate};";

        // Таблица cashback_unregistered_transactions
        $table_unregistered = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_unregistered_transactions` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` varchar(255) NOT NULL,
            `order_number` varchar(255) NOT NULL,
            `offer_id` int unsigned DEFAULT NULL COMMENT 'ID партнёрской программы в CPA-сети (advcampaign_id). Стабилен, в отличие от offer_name',
            `offer_name` varchar(255) DEFAULT NULL,
            `order_status` enum('waiting','completed','declined','hold','balance') NOT NULL DEFAULT 'waiting',
            `partner` varchar(255) DEFAULT NULL,
            `sum_order` decimal(10,2) DEFAULT NULL,
            `comission` decimal(10,2) DEFAULT NULL,
            `currency` char(3) NOT NULL DEFAULT 'RUB' COMMENT 'Валюта комиссии (ISO 4217). Без неё невозможно корректно сравнивать суммы',
            `uniq_id` varchar(255) DEFAULT NULL,
            `cashback` decimal(10,2) DEFAULT NULL,
            `applied_cashback_rate` decimal(5,2) NOT NULL DEFAULT 60.00 COMMENT 'Процент кэшбэка на момент создания транзакции',
            `api_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = Транзакция сверена с API. Основной триггер начисления в баланс',
            `action_date` datetime DEFAULT NULL COMMENT 'Реальное время покупки. НЕ путать с created_at (время получения хука)',
            `click_time` datetime DEFAULT NULL COMMENT 'Время клика. Для антифрода: action_date - click_time = 0 бот',
            `click_id` char(32) DEFAULT NULL COMMENT 'UUID клика, связь с cashback_click_log.click_id',
            `website_id` int unsigned DEFAULT NULL COMMENT 'ID площадки в CPA-сети',
            `action_type` varchar(10) DEFAULT NULL COMMENT 'sale/lead. Для корректного расчёта при нескольких тарифах',
            `processed_at` datetime DEFAULT NULL COMMENT 'Когда транзакция была учтена в балансе',
            `processed_batch_id` char(36) DEFAULT NULL COMMENT 'UUID батча начисления',
            `idempotency_key` varchar(64) DEFAULT NULL COMMENT 'Ключ идемпотентности для предотвращения дублирования транзакций',
            `spam_click` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = транзакция из подозрительного клика, кэшбэк только после ручной проверки',
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_uniq_partner` (`uniq_id`,`partner`),
            UNIQUE KEY `idx_idempotency_key` (`idempotency_key`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Вэбхуки принятые от неавторизованных пользователей';";

        // Таблица cashback_user_balance (FK и CHECK добавляются в Фазе 2)
        $table_balance = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_user_balance` (
            `user_id` bigint(20) unsigned NOT NULL,
            `available_balance` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Доступный баланс пользователя',
            `pending_balance`   decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'В ожидании выплаты',
            `paid_balance`      decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Выплачен',
            `frozen_balance`    decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Заблокирован',
            `version` int unsigned NOT NULL DEFAULT 0 COMMENT 'Версия строки для защиты от гонок',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Балансы пользователей кэшбэк-сервиса';";

        // Таблица cashback_webhooks (GENERATED и CHECK убраны для совместимости)
        $table_webhooks = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_webhooks` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `received_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
            `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            `network_slug` varchar(64) DEFAULT NULL,
            `payload_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 хеш payload для дедупликации',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_payload_hash` (`payload_hash`),
            KEY `idx_received_at` (`received_at`),
            KEY `idx_network_slug` (`network_slug`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Сырые уникальные webhooks';";

        // Таблица cashback_user_profile (FK добавляются в Фазе 2)
        $table_profile = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_user_profile` (
            `user_id` bigint(20) unsigned NOT NULL,
            `payout_method_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID способа выплаты, привязанного к wp_cashback_payout_methods.id',
            `payout_account` varchar(255) DEFAULT NULL COMMENT 'Телефон, номер карты или кошелёк',
            `payout_full_name` varchar(255) DEFAULT NULL COMMENT 'ФИО для выплат',
            `encrypted_details` BLOB DEFAULT NULL COMMENT 'AES-256-CBC зашифрованные реквизиты (JSON)',
            `masked_details` TEXT DEFAULT NULL COMMENT 'Маскированные реквизиты для отображения (JSON)',
            `details_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 хеш реквизитов для антифрода',
            `bank_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID банка, привязанного к wp_cashback_banks.id',
            `cashback_rate` decimal(5,2) NOT NULL DEFAULT 60.00 COMMENT 'Процент кэшбэка (60 = 60%)',
            `is_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = реквизиты подтверждены',
            `payout_details_updated_at` datetime DEFAULT NULL COMMENT 'Дата и время обновления реквизитов',
            `min_payout_amount` decimal(18,2) DEFAULT 100.00 COMMENT 'Минимальная сумма выплаты',
            `opt_out` tinyint(1) NOT NULL DEFAULT 0,
            `status` enum('active','noactive','banned','deleted') NOT NULL DEFAULT 'active' COMMENT 'Статус профиля',
            `banned_at` datetime DEFAULT NULL COMMENT 'Дата и время блокировки',
            `ban_reason` text DEFAULT NULL COMMENT 'Причина блокировки',
            `last_active_at` datetime DEFAULT NULL COMMENT 'Дата и времени последней активности',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`user_id`),
            KEY `idx_active_check` (`status`,`last_active_at`,`created_at`),
            KEY `idx_payout_method` (`payout_method_id`),
            KEY `idx_bank_id` (`bank_id`),
            KEY `idx_details_hash` (`details_hash`)
        ) ENGINE=InnoDB {$charset_collate};";

        // Таблица логирования кликов по партнерским ссылкам
        $table_click_log = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_click_log` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `click_id` char(32) NOT NULL COMMENT 'UUID клика без дефисов, передаётся в CPA как subID, ключ для диспута',
            `user_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'WP user ID (0 для гостей)',
            `session_id` varchar(128) DEFAULT NULL COMMENT 'Идентификатор сессии для незалогиненных',
            `product_id` bigint(20) unsigned NOT NULL COMMENT 'ID товара WooCommerce',
            `cpa_network` varchar(100) DEFAULT NULL COMMENT 'Название CPA-сети',
            `offer_id` varchar(255) DEFAULT NULL COMMENT 'ID оффера в сети',
            `affiliate_url` text NOT NULL COMMENT 'Полный URL с подставленными параметрами',
            `ip_address` varchar(45) NOT NULL COMMENT 'IPv4/IPv6 адрес',
            `user_agent` text DEFAULT NULL COMMENT 'User-Agent браузера',
            `referer` text DEFAULT NULL COMMENT 'Внутренний referer (страница клика)',
            `utm_source` varchar(255) DEFAULT NULL COMMENT 'UTM source',
            `utm_medium` varchar(255) DEFAULT NULL COMMENT 'UTM medium',
            `utm_campaign` varchar(255) DEFAULT NULL COMMENT 'UTM campaign',
            `country` varchar(2) DEFAULT NULL COMMENT 'Код страны GeoIP (ISO 3166-1 alpha-2)',
            `spam_click` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = подозрительный клик (rate limit), кэшбэк только после ручной проверки',
            `created_at` datetime(6) NOT NULL COMMENT 'Время клика (UTC)',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_click_id` (`click_id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_product_id` (`product_id`),
            KEY `idx_cpa_network` (`cpa_network`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_ip_address` (`ip_address`),
            KEY `idx_session_id` (`session_id`),
            KEY `idx_spam_by_ip` (`created_at`,`spam_click`,`ip_address`),
            KEY `idx_spam_by_product` (`created_at`,`spam_click`,`product_id`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Лог кликов по партнерским ссылкам';";

        // Порядок создания: сначала справочники, потом зависимые таблицы
        $tables = [
            'cashback_payout_methods'          => $table_payout_methods,
            'cashback_banks'                   => $table_banks,
            'cashback_affiliate_networks'      => $table_affiliate_networks,
            'cashback_affiliate_network_params' => $table_affiliate_network_params,
            'cashback_payout_requests'         => $table_payout_requests,
            'cashback_transactions'            => $table_transactions,
            'cashback_unregistered_transactions' => $table_unregistered,
            'cashback_user_balance'            => $table_balance,
            'cashback_webhooks'                => $table_webhooks,
            'cashback_user_profile'            => $table_profile,
            'cashback_click_log'               => $table_click_log,
        ];

        $failed_tables = [];

        foreach ($tables as $table_name => $sql) {
            $full_table_name = $wpdb->prefix . $table_name;

            // Используем $wpdb->query() напрямую вместо dbDelta()
            // dbDelta() парсит CREATE TABLE IF NOT EXISTS некорректно
            // (regex захватывает "IF" как имя таблицы) и не поддерживает
            // CONSTRAINT, CHECK, FOREIGN KEY, GENERATED конструкции
            $result = $wpdb->query($sql);

            if ($result === false) {
                $failed_tables[] = $table_name . ': ' . $wpdb->last_error;
                error_log("[Cashback] Failed to create table {$full_table_name}: " . $wpdb->last_error);
            } else {
                // Проверяем что таблица действительно существует
                $exists = $wpdb->get_var(
                    $wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name)
                );
                if (!$exists) {
                    $failed_tables[] = $table_name . ': table not created (no error reported)';
                    error_log("[Cashback] Table {$full_table_name} was not created despite no error being reported");
                }
            }
        }

        if (!empty($failed_tables)) {
            throw new Exception(
                'Failed to create tables: ' . implode('; ', $failed_tables)
            );
        }

        // Инициализация начальных данных в справочные таблицы
        $this->insert_default_payout_methods();
        $this->insert_default_banks();

        // Таблица аудит-лога
        $this->create_audit_log_table();

        // Фаза 2: Добавление FOREIGN KEY и CHECK ограничений (не фатально)
        $this->add_table_constraints();

        error_log('[Cashback] All tables created successfully');
    }

    /**
     * Добавление FOREIGN KEY и CHECK ограничений к таблицам.
     * Вынесено из CREATE TABLE для совместимости:
     * - WordPress dbDelta() не поддерживает CONSTRAINT/FK/CHECK
     * - FK между InnoDB и MyISAM невозможен (wp_users может быть MyISAM)
     * - CHECK не поддерживается в MySQL < 8.0.16
     * Ошибки не фатальны — таблицы работают и без ограничений на уровне БД.
     */
    private function add_table_constraints(): void
    {
        global $wpdb;

        $constraints = [
            // cashback_payout_requests
            "ALTER TABLE `{$wpdb->prefix}cashback_payout_requests`
                ADD CONSTRAINT `fk_payout_user` FOREIGN KEY (`user_id`)
                REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE RESTRICT",

            // cashback_transactions
            "ALTER TABLE `{$wpdb->prefix}cashback_transactions`
                ADD CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`)
                REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE RESTRICT",

            // cashback_user_balance
            "ALTER TABLE `{$wpdb->prefix}cashback_user_balance`
                ADD CONSTRAINT `fk_balance_user` FOREIGN KEY (`user_id`)
                REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE RESTRICT",

            // cashback_user_profile
            "ALTER TABLE `{$wpdb->prefix}cashback_user_profile`
                ADD CONSTRAINT `fk_profile_wp_user` FOREIGN KEY (`user_id`)
                REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE",
            "ALTER TABLE `{$wpdb->prefix}cashback_user_profile`
                ADD CONSTRAINT `fk_payout_method` FOREIGN KEY (`payout_method_id`)
                REFERENCES `{$wpdb->prefix}cashback_payout_methods` (`id`) ON DELETE SET NULL",
            "ALTER TABLE `{$wpdb->prefix}cashback_user_profile`
                ADD CONSTRAINT `fk_bank_id` FOREIGN KEY (`bank_id`)
                REFERENCES `{$wpdb->prefix}cashback_banks` (`id`) ON DELETE SET NULL",

            // cashback_affiliate_network_params
            "ALTER TABLE `{$wpdb->prefix}cashback_affiliate_network_params`
                ADD CONSTRAINT `fk_network_params` FOREIGN KEY (`network_id`)
                REFERENCES `{$wpdb->prefix}cashback_affiliate_networks` (`id`) ON DELETE CASCADE",

            // CHECK constraints
            "ALTER TABLE `{$wpdb->prefix}cashback_transactions`
                ADD CONSTRAINT `chk_applied_cashback_rate_range`
                CHECK (`applied_cashback_rate` BETWEEN 0.00 AND 100.00)",
            "ALTER TABLE `{$wpdb->prefix}cashback_transactions`
                ADD CONSTRAINT `chk_cashback_positive`
                CHECK (`cashback` >= 0)",
            "ALTER TABLE `{$wpdb->prefix}cashback_transactions`
                ADD CONSTRAINT `chk_currency_format`
                CHECK (`currency` REGEXP '^[A-Z]{3}$')",

            "ALTER TABLE `{$wpdb->prefix}cashback_user_balance`
                ADD CONSTRAINT `chk_available_balance` CHECK (`available_balance` >= 0)",
            "ALTER TABLE `{$wpdb->prefix}cashback_user_balance`
                ADD CONSTRAINT `chk_pending_balance` CHECK (`pending_balance` >= 0)",
            "ALTER TABLE `{$wpdb->prefix}cashback_user_balance`
                ADD CONSTRAINT `chk_paid_balance` CHECK (`paid_balance` >= 0)",
            "ALTER TABLE `{$wpdb->prefix}cashback_user_balance`
                ADD CONSTRAINT `chk_frozen_balance` CHECK (`frozen_balance` >= 0)",

            "ALTER TABLE `{$wpdb->prefix}cashback_user_profile`
                ADD CONSTRAINT `chk_cashback_rate_range`
                CHECK (`cashback_rate` BETWEEN 0.00 AND 100.00)",
        ];

        $suppress = $wpdb->suppress_errors(true);

        foreach ($constraints as $sql) {
            $wpdb->query($sql);
            // Ошибки типа "Duplicate key name" (constraint already exists) — ожидаемы
            if ($wpdb->last_error && strpos($wpdb->last_error, 'Duplicate') === false) {
                error_log('[Cashback] Constraint warning (non-fatal): ' . $wpdb->last_error);
            }
        }

        $wpdb->suppress_errors($suppress);
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
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE %d = %d", 1, 1));
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
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE %d = %d", 1, 1));
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
     * Создание таблицы аудит-лога
     */
    private function create_audit_log_table(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_audit_log` (
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

        $result = $wpdb->query($sql);
        if ($result === false) {
            error_log('[Cashback] Failed to create audit_log table: ' . $wpdb->last_error);
        }
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
            "DROP TRIGGER IF EXISTS `{$safe_prefix}cashback_tr_validate_status_transition`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}cashback_tr_validate_status_transition_unregistered`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}tr_prevent_delete_paid_payout`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}tr_prevent_update_paid_payout`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}tr_prevent_delete_failed_payout`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}tr_prevent_update_failed_payout`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}tr_banned_user_update_banned_at`;",
            "DROP TRIGGER IF EXISTS `{$safe_prefix}tr_webhook_payload_hash`;",
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
                IF NOT (OLD.comission <=> NEW.comission) THEN
                    SET NEW.cashback = ROUND(NEW.comission * NEW.applied_cashback_rate / 100, 2);
                END IF;
            END;",

            "CREATE TRIGGER `{$safe_prefix}calculate_cashback_before_update_unregistered`
            BEFORE UPDATE ON `{$safe_prefix}cashback_unregistered_transactions`
            FOR EACH ROW
            --  'Пересчитывает кэшбэк для незарегистрированных пользователей при изменении comission'
            BEGIN
                IF NOT (OLD.comission <=> NEW.comission) THEN
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

            "CREATE TRIGGER `{$safe_prefix}cashback_tr_validate_status_transition`
            BEFORE UPDATE ON `{$safe_prefix}cashback_transactions`
            FOR EACH ROW
            BEGIN
                -- 1. balance — полная блокировка любых изменений (финальный статус)
                IF OLD.order_status = 'balance' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: запись с финальным статусом не может быть изменена.';
                END IF;

                -- 2. Возврат в waiting запрещён из любого состояния
                IF NEW.order_status = 'waiting' AND OLD.order_status != 'waiting' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Понижение статуса до waiting запрещено.';
                END IF;

                -- 3. В balance — только из completed
                IF NEW.order_status = 'balance' AND OLD.order_status != 'completed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Перевод в balance возможен только из completed.';
                END IF;

                -- 4. В hold — только из completed (рекламодатель вернул подтверждённое на удержание)
                IF NEW.order_status = 'hold' AND OLD.order_status != 'completed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Перевод в hold возможен только из completed.';
                END IF;

                -- 5. Из declined — только в completed (апелляция через Потерянные заказы)
                IF OLD.order_status = 'declined' 
                    AND NEW.order_status != 'completed' 
                    AND NEW.order_status != 'declined' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Из declined возможен переход только в completed.';
                END IF;
            END;",

            "CREATE TRIGGER `{$safe_prefix}cashback_tr_validate_status_transition_unregistered`
            BEFORE UPDATE ON `{$safe_prefix}cashback_unregistered_transactions`
            FOR EACH ROW
            BEGIN
                IF OLD.order_status = 'balance' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: запись с финальным статусом не может быть изменена.';
                END IF;

                IF NEW.order_status = 'waiting' AND OLD.order_status != 'waiting' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Понижение статуса до waiting запрещено.';
                END IF;

                IF NEW.order_status = 'balance' AND OLD.order_status != 'completed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Перевод в balance возможен только из completed.';
                END IF;

                IF NEW.order_status = 'hold' AND OLD.order_status != 'completed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Перевод в hold возможен только из completed.';
                END IF;

                IF OLD.order_status = 'declined' 
                    AND NEW.order_status != 'completed' 
                    AND NEW.order_status != 'declined' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Из declined возможен переход только в completed.';
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

            "CREATE TRIGGER IF NOT EXISTS `{$safe_prefix}tr_freeze_balance_on_ban`
            AFTER UPDATE ON `{$safe_prefix}cashback_user_profile`
            FOR EACH ROW
            --  'Замораживает доступный и pending баланс при бане пользователя'
            BEGIN
                IF OLD.status != 'banned' AND NEW.status = 'banned' THEN
                    UPDATE `{$safe_prefix}cashback_user_balance`
                    SET
                        frozen_balance = frozen_balance + available_balance + pending_balance,
                        available_balance = 0,
                        pending_balance = 0,
                        version = version + 1
                    WHERE user_id = NEW.user_id;
                END IF;
            END;",

            "CREATE TRIGGER IF NOT EXISTS `{$safe_prefix}tr_clear_ban_on_unban`
            BEFORE UPDATE ON `{$safe_prefix}cashback_user_profile`
            FOR EACH ROW
            --  'Очищает поля banned_at и ban_reason при разбане пользователя'
            BEGIN
                IF OLD.status = 'banned' AND NEW.status != 'banned' THEN
                    SET NEW.banned_at = NULL;
                    SET NEW.ban_reason = NULL;
                END IF;
            END;",

            "CREATE TRIGGER IF NOT EXISTS `{$safe_prefix}tr_unfreeze_balance_on_unban`
            AFTER UPDATE ON `{$safe_prefix}cashback_user_profile`
            FOR EACH ROW
            --  'Размораживает баланс при разбане пользователя'
            BEGIN
                IF OLD.status = 'banned' AND NEW.status != 'banned' THEN
                    UPDATE `{$safe_prefix}cashback_user_balance`
                    SET
                        available_balance = available_balance + frozen_balance,
                        frozen_balance = 0,
                        version = version + 1
                    WHERE user_id = NEW.user_id;
                END IF;
            END;",

            // Автоматический расчёт payload_hash при INSERT в cashback_webhooks
            // Заменяет GENERATED ALWAYS AS (SHA2(payload, 256)) STORED, убранный для совместимости
            "CREATE TRIGGER IF NOT EXISTS `{$safe_prefix}tr_webhook_payload_hash`
            BEFORE INSERT ON `{$safe_prefix}cashback_webhooks`
            FOR EACH ROW
            BEGIN
                IF NEW.payload_hash IS NULL THEN
                    SET NEW.payload_hash = SHA2(NEW.payload, 256);
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
            update_option('cashback_triggers_active', false);
            error_log('Mariadb Plugin Warning: Failed to create triggers (PHP fallbacks will be used): ' . implode('; ', $failed_triggers));
        } else {
            update_option('cashback_triggers_active', true);
            error_log('Mariadb Plugin: All triggers created successfully');
        }
    }

    /**
     * Создание событий
     */
    private function create_events()
    {
        global $wpdb;

        // Валидация префикса таблицы для безопасности
        $safe_prefix = $this->validate_table_prefix($wpdb->prefix);

        // Дропаем существующие события перед пересозданием (аналогично триггерам)
        $drops = [
            "DROP EVENT IF EXISTS `{$safe_prefix}cashback_ev_confirmed_cashback`",
            "DROP EVENT IF EXISTS `{$safe_prefix}cashback_ev_cleanup_cashback_webhooks_old`",
            "DROP EVENT IF EXISTS `{$safe_prefix}cashback_ev_cleanup_click_log`",
            "DROP EVENT IF EXISTS `{$safe_prefix}cashback_ev_mark_inactive_profiles`",
        ];

        foreach ($drops as $drop) {
            $wpdb->query($drop);
        }

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

        -- ШАГ 1: Маркируем транзакции (источник истины для идемпотентности)
        -- UPDATE берёт X-lock на строки, временная таблица не нужна
        -- spam_click=1 пропускаем — только ручная проверка
        UPDATE `{$safe_prefix}cashback_transactions`
        SET
            processed_at = NOW(),
            processed_batch_id = v_batch_id
        WHERE
            order_status = 'completed'
            AND api_verified = 1
            AND processed_at IS NULL
            AND cashback IS NOT NULL
            AND cashback > 0
            AND spam_click = 0
            AND updated_at <= DATE_SUB(NOW(), INTERVAL 7 DAY);

        SET v_affected_rows = ROW_COUNT();

        IF v_affected_rows > 0 THEN
            -- ШАГ 2: Начисляем баланс ТОЛЬКО для транзакций с processed_batch_id = v_batch_id
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
            WHERE received_at < NOW() - INTERVAL 6 MONTH
            LIMIT 5000",

            // Событие ежедневно удаляет записи кликов старше 90 дней
            "CREATE EVENT IF NOT EXISTS `{$safe_prefix}cashback_ev_cleanup_click_log`
            ON SCHEDULE EVERY 1 DAY
            STARTS CURRENT_TIMESTAMP
            ON COMPLETION NOT PRESERVE
            ENABLE
            DO DELETE FROM `{$safe_prefix}cashback_click_log`
            WHERE created_at < NOW() - INTERVAL 90 DAY
            LIMIT 5000",

            // Событие ежедневно проверяет и помечает неактивные профили если неактивны больше 6 месяцев
            "CREATE EVENT IF NOT EXISTS `{$safe_prefix}cashback_ev_mark_inactive_profiles`
            ON SCHEDULE EVERY 1 DAY
            STARTS CURRENT_TIMESTAMP
            ON COMPLETION PRESERVE
            ENABLE
            DO
            BEGIN
                IF GET_LOCK('cashback_inactive_profiles_lock', 0) = 1 THEN
                    UPDATE `{$safe_prefix}cashback_user_profile`
                    SET status = 'noactive'
                    WHERE
                        status = 'active'
                        AND (
                            (last_active_at IS NOT NULL AND last_active_at < DATE_SUB(NOW(), INTERVAL 6 MONTH))
                            OR
                            (last_active_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH))
                        )
                    LIMIT 1000;
                    DO RELEASE_LOCK('cashback_inactive_profiles_lock');
                END IF;
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
            update_option('cashback_events_active', false);
            error_log('Mariadb Plugin: Some events failed to create (non-critical): ' . implode('; ', $failed_events));
        } else {
            update_option('cashback_events_active', true);
            error_log('Mariadb Plugin: All events created successfully');
        }
    }

    /**
     * Инициализация существующих пользователей
     */
    private function initialize_existing_users()
    {
        global $wpdb;

        $batch_size = 500;
        $offset = 0;
        $total_initialized = 0;

        do {
            $user_ids = get_users(array(
                'fields' => 'ID',
                'number' => $batch_size,
                'offset' => $offset,
            ));

            if (empty($user_ids)) {
                break;
            }

            foreach ($user_ids as $user_id) {
                $result = $this->add_user_to_cashback_tables((int) $user_id);
                if (!$result) {
                    error_log("[Cashback] Failed to initialize user {$user_id}: " . $wpdb->last_error);
                    throw new Exception("Failed to initialize user {$user_id}.");
                }
                $total_initialized++;
            }

            $offset += $batch_size;
        } while (count($user_ids) === $batch_size);

        if ($total_initialized === 0) {
            error_log('Mariadb Plugin: No existing users to initialize');
        } else {
            error_log('Mariadb Plugin: Successfully initialized ' . $total_initialized . ' existing users');
        }
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

    /**
     * Генерация уникального читаемого идентификатора заявки на выплату
     * Формат: WD-XXXXXXXX, где X — символ из безопасного алфавита (без 0/O, 1/I/L)
     *
     * @return string Reference ID в формате WD-XXXXXXXX
     */
    public static function generate_reference_id(): string
    {
        // 30 символов: цифры 2-9, буквы A-Z без O, I, L
        $charset = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $charset_len = 30;
        $id_length = 8;

        $random_bytes = random_bytes($id_length);
        $result = 'WD-';

        for ($i = 0; $i < $id_length; $i++) {
            $result .= $charset[ord($random_bytes[$i]) % $charset_len];
        }

        return $result;
    }

    /**
     * Миграция: добавление колонки reference_id в cashback_payout_requests
     * Бэкфилл существующих записей уникальными идентификаторами
     *
     * @return void
     */
    private function migrate_add_reference_id(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_payout_requests';

        // Шаг 1: Проверяем наличие колонки
        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'reference_id'",
            DB_NAME,
            $table
        ));

        if (!$column_exists) {
            // Шаг 2: Добавляем колонку
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `reference_id` varchar(11) NOT NULL DEFAULT '' COMMENT 'Публичный ID заявки формата WD-XXXXXXXX' AFTER `id`");

            if ($wpdb->last_error) {
                error_log('[Cashback] Failed to add reference_id column: ' . $wpdb->last_error);
                return;
            }
        }

        // Шаг 3: Бэкфилл записей с пустым reference_id
        $batch_size = 100;
        $max_retries = 5;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id FROM `{$table}` WHERE reference_id = '' LIMIT %d",
                    $batch_size
                )
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $updated = false;

                for ($attempt = 0; $attempt < $max_retries; $attempt++) {
                    $ref_id = self::generate_reference_id();
                    $result = $wpdb->update(
                        $table,
                        array('reference_id' => $ref_id),
                        array('id' => $row->id),
                        array('%s'),
                        array('%d')
                    );

                    if ($result !== false) {
                        $updated = true;
                        break;
                    }

                    // Если ошибка не связана с дубликатом — прекращаем
                    if (strpos($wpdb->last_error, 'Duplicate') === false) {
                        error_log('[Cashback] Failed to update reference_id for payout #' . $row->id . ': ' . $wpdb->last_error);
                        break;
                    }
                }

                if (!$updated) {
                    error_log('[Cashback] Could not generate unique reference_id for payout #' . $row->id . ' after ' . $max_retries . ' attempts');
                }
            }
        } while (!empty($rows));

        // Шаг 4: Добавляем UNIQUE индекс если отсутствует
        $index_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uk_reference_id'",
            DB_NAME,
            $table
        ));

        if (!$index_exists) {
            $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `uk_reference_id` (`reference_id`)");

            if ($wpdb->last_error) {
                error_log('[Cashback] Failed to add uk_reference_id index: ' . $wpdb->last_error);
            }
        }
    }

    /**
     * Миграция: добавление колонки bank_required в cashback_payout_methods
     * DEFAULT 1 — все существующие способы продолжат требовать банк
     *
     * @return void
     */
    private function migrate_add_bank_required(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_payout_methods';

        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'bank_required'",
            DB_NAME,
            $table
        ));

        if (!$column_exists) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN `bank_required` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = для этого способа нужно выбрать банк' AFTER `sort_order`"
            );

            if ($wpdb->last_error) {
                error_log('[Cashback] Failed to add bank_required column: ' . $wpdb->last_error);
            }
        }
    }

    /**
     * Бэкфилл payload_hash для существующих записей в cashback_webhooks.
     *
     * После удаления GENERATED ALWAYS AS (SHA2(payload, 256)) STORED
     * старые записи остались с payload_hash = NULL. Обновляем батчами.
     */
    private function migrate_backfill_webhook_payload_hash(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_webhooks';

        // Проверяем есть ли записи с NULL хешем
        $null_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE payload_hash IS NULL"
        );

        if ($null_count === 0) {
            return;
        }

        // Батчевое обновление по 5000 записей
        $max_iterations = 100;
        $iteration = 0;

        do {
            $affected = $wpdb->query(
                "UPDATE `{$table}` SET payload_hash = SHA2(payload, 256) WHERE payload_hash IS NULL LIMIT 5000"
            );

            $iteration++;
        } while ($affected > 0 && $iteration < $max_iterations);

        // Удаляем дубликаты, оставляя самую раннюю запись
        $wpdb->query(
            "DELETE w1 FROM `{$table}` w1
             INNER JOIN `{$table}` w2
             ON w1.payload_hash = w2.payload_hash
             AND w1.id > w2.id"
        );

        error_log(sprintf('[Cashback] Webhook payload_hash backfill complete. Updated %d records.', $null_count));
    }
}

// Инициализация Mariadb_Plugin происходит через CashbackPlugin::initialize_components()
// в файле cashback-plugin.php

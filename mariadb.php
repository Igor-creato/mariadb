<?php

if (!defined('ABSPATH')) {
    exit; // Защита от прямого доступа
}

/**
 * Основной класс плагина
 */
class Mariadb_Plugin
{
    /**
     * Экземпляр класса (singleton)
     */
    private static $instance = null;

    /**
     * Получить экземпляр класса
     */
    public static function get_instance()
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
        add_action('user_register', array($this, 'add_user_to_profile'));
        add_action('user_register', array($this, 'add_user_to_balance'));
    }

    /**
     * Активация плагина
     */
    public static function activate()
    {
        $instance = self::get_instance();

        // Подавляем вывод при создании таблиц и триггеров
        ob_start();

        $instance->create_tables();
        $instance->create_triggers();
        $instance->create_events();
        $instance->initialize_existing_users();

        // Очищаем буфер вывода (если что-то было выведено)
        ob_end_clean();

        // Flush rewrite rules for new endpoints
        flush_rewrite_rules();
    }

    /**
     * Создание таблиц
     */
    private function create_tables()
    {
        global $wpdb;

        $charset_collate = "DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        // Таблица cashback_payout_requests
        $table1 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_payout_requests` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL,
            `total_amount` decimal(18,2) NOT NULL,
            `status` enum('В обработке','Выплачен') NOT NULL DEFAULT 'В обработке',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_user_status` (`user_id`,`status`),
            CONSTRAINT `fk_payout_user` FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_collate};";

        // Таблица cashback_transactions
        $table2 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_transactions` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL,
            `order_number` varchar(255) NOT NULL,
            `offer_name` varchar(255) DEFAULT NULL,
            `order_status` varchar(255) NOT NULL DEFAULT 'В ожидании',
            `partner` varchar(255) DEFAULT NULL,
            `sum_order` decimal(10,2) DEFAULT NULL,
            `commission` decimal(10,2) DEFAULT NULL,
            `uniq_id` varchar(255) DEFAULT NULL,
            `cashback` decimal(10,2) DEFAULT NULL,
            `applied_cashback_rate` decimal(5,2) DEFAULT 60.00 COMMENT 'Процент кэшбэка на момент создания транзакции',
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_uniq_partner` (`uniq_id`,`partner`),
            KEY `user_id` (`user_id`),
            KEY `idx_order_status_updated_cashback` (`order_status`,`updated_at`,`cashback`),
            CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE,
            CONSTRAINT `chk_applied_cashback_rate_range` CHECK (`applied_cashback_rate` BETWEEN 0.00 AND 100.00)
        ) ENGINE=InnoDB {$charset_collate};";

        // Таблица cashback_unregistered_transactions
        $table3 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_unregistered_transactions` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` varchar(255) NOT NULL,
            `order_number` varchar(255) NOT NULL,
            `offer_name` varchar(255) DEFAULT NULL,
            `order_status` varchar(255) NOT NULL DEFAULT 'В ожидании',
            `partner` varchar(255) DEFAULT NULL,
            `sum_order` decimal(10,2) DEFAULT NULL,
            `commission` decimal(10,2) DEFAULT NULL,
            `uniq_id` varchar(255) DEFAULT NULL,
            `cashback` decimal(10,2) DEFAULT NULL,
            `user_agent` text DEFAULT NULL,
            `click_time` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_uniq_partner` (`uniq_id`,`partner`)
        ) ENGINE=InnoDB {$charset_collate};";

        // Таблица cashback_user_balance
        $table4 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_user_balance` (
            `user_id` bigint(20) unsigned NOT NULL,
            `available_balance` decimal(18,2) NOT NULL DEFAULT 0.0,
            `pending_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
            `paid_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (`user_id`),
            CONSTRAINT `fk_balance_user` FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_collate};";

        // Таблица cashback_webhooks
        $table5 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_webhooks` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `received_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
            `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
            `payload_norm` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS (json_normalize(`payload`)) VIRTUAL CHECK (json_valid(`payload_norm`)),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_payload_norm` (`payload_norm`) USING HASH,
            KEY `idx_received_at` (`received_at`)
        ) ENGINE=InnoDB {$charset_collate};";

        // Таблица cashback_user_profile
        $table6 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_user_profile` (
            `user_id` bigint(20) unsigned NOT NULL,
            `payout_method` ENUM('sbp','mir','yoomoney') DEFAULT NULL COMMENT 'СБП, Карта, ЮMoney и т.д.',
            `payout_account` varchar(255) DEFAULT NULL COMMENT 'Телефон, номер карты или кошелёк',
            `payout_full_name` varchar(255) DEFAULT NULL COMMENT 'ФИО для выплат',
            `cashback_rate` decimal(5,2) NOT NULL DEFAULT 60.00 COMMENT 'Процент кэшбэка (60 = 60%)' CHECK (cashback_rate BETWEEN 0.00 AND 100.00),
            `is_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = реквизиты подтверждены',
            `payout_details_updated_at` datetime DEFAULT NULL COMMENT 'Дата и время обновления реквизитов',
            `min_payout_amount` decimal(18,2) DEFAULT 100.00 COMMENT 'Минимальная сумма выплаты',
            `opt_out` tinyint(1) NOT NULL DEFAULT 0,
            `status` ENUM('active','noactive','banned','deleted') NOT NULL DEFAULT 'active' COMMENT 'Статус профиля',
            `banned_at` DATETIME DEFAULT NULL COMMENT 'Дата и время бана',
            `ban_reason` VARCHAR(255) DEFAULT NULL COMMENT 'Причина блокировки',
            `last_active_at` DATETIME DEFAULT NULL COMMENT 'Дата и время последней активности',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`user_id`),
            KEY `idx_active_check` (`status`, `last_active_at`, `created_at`),
            CONSTRAINT `fk_profile_wp_user` FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($table1);
        dbDelta($table2);
        dbDelta($table3);
        dbDelta($table4);
        dbDelta($table5);
        dbDelta($table6);
    }

    /**
     * Создание триггеров
     */
    private function create_triggers()
    {
        global $wpdb;

        // Удаляем существующие триггеры перед созданием новых
        $drop_triggers = [
            "DROP TRIGGER IF EXISTS `{$wpdb->prefix}calculate_cashback_before_insert`;",
            "DROP TRIGGER IF EXISTS `{$wpdb->prefix}calculate_cashback_before_insert_unregistered`;",
            "DROP TRIGGER IF EXISTS `{$wpdb->prefix}calculate_cashback_before_update`;",
            "DROP TRIGGER IF EXISTS `{$wpdb->prefix}calculate_cashback_before_update_unregistered`;",
            "DROP TRIGGER IF EXISTS `{$wpdb->prefix}cashback_tr_prevent_delete_final_status`;",
            "DROP TRIGGER IF EXISTS `{$wpdb->prefix}cashback_tr_prevent_update_final_status`;",
            "DROP TRIGGER IF EXISTS `{$wpdb->prefix}tr_prevent_delete_paid_payout`;",
            "DROP TRIGGER IF EXISTS `{$wpdb->prefix}tr_prevent_update_paid_payout`;",
        ];

        foreach ($drop_triggers as $drop_trigger) {
            @$wpdb->query($drop_trigger); // Подавляем уведомления об ошибках
        }

        $triggers = [
            "CREATE TRIGGER `{$wpdb->prefix}calculate_cashback_before_insert`
            BEFORE INSERT ON `{$wpdb->prefix}cashback_transactions`
            FOR EACH ROW
            BEGIN
                DECLARE v_rate DECIMAL(5,2) DEFAULT 60.00;
                
                SELECT cashback_rate INTO v_rate
                FROM `{$wpdb->prefix}cashback_user_profile`
                WHERE user_id = NEW.user_id
                LIMIT 1;
                
                SET NEW.applied_cashback_rate = IFNULL(v_rate, 60.00);
                
                IF NEW.commission IS NOT NULL THEN
                    SET NEW.cashback = ROUND(NEW.commission * IFNULL(v_rate, 60.00) / 100, 2);
                ELSE
                    SET NEW.cashback = 0.00;
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}calculate_cashback_before_insert_unregistered`
            BEFORE INSERT ON `{$wpdb->prefix}cashback_unregistered_transactions`
            FOR EACH ROW
            BEGIN
                SET NEW.cashback = FLOOR(NEW.commission * 0.6);
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}calculate_cashback_before_update`
            BEFORE UPDATE ON `{$wpdb->prefix}cashback_transactions`
            FOR EACH ROW
            BEGIN
                IF OLD.commission != NEW.commission THEN
                    SET NEW.cashback = ROUND(NEW.commission * NEW.applied_cashback_rate / 100, 2);
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}calculate_cashback_before_update_unregistered`
            BEFORE UPDATE ON `{$wpdb->prefix}cashback_unregistered_transactions`
            FOR EACH ROW
            BEGIN
                IF OLD.commission != NEW.commission THEN
                    SET NEW.cashback = FLOOR(NEW.commission * 0.6);
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}cashback_tr_prevent_delete_final_status`
            BEFORE DELETE ON `{$wpdb->prefix}cashback_transactions`
            FOR EACH ROW
            BEGIN
                IF OLD.order_status = 'Зачислено на баланс' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Удаление запрещено: запись с финальным статусом не может быть удалена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}cashback_tr_prevent_update_final_status`
            BEFORE UPDATE ON `{$wpdb->prefix}cashback_transactions`
            FOR EACH ROW
            BEGIN
                IF OLD.order_status = 'Зачислено на баланс' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: статус \"Зачислено на баланс\" является финальным.';
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}tr_prevent_delete_paid_payout`
            BEFORE DELETE ON `{$wpdb->prefix}cashback_payout_requests`
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'Выплачен' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Удаление запрещено: выплаченная заявка не может быть удалена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}tr_prevent_update_paid_payout`
            BEFORE UPDATE ON `{$wpdb->prefix}cashback_payout_requests`
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'Выплачен' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: выплаченная заявка не может быть изменена.';
                END IF;
            END;",
        ];

        foreach ($triggers as $trigger) {
            @$wpdb->query($trigger); // Подавляем уведомления об ошибках
        }
    }

    /**
     * Создание событий
     */
    private function create_events()
    {
        global $wpdb;

        $events = [
            "CREATE EVENT IF NOT EXISTS `{$wpdb->prefix}cashback_ev_account_confirmed_cashback`
            ON SCHEDULE EVERY 1 DAY STARTS NOW()
            ON COMPLETION NOT PRESERVE
            ENABLE
            DO BEGIN
                DROP TEMPORARY TABLE IF EXISTS tmp_new_balances;
                CREATE TEMPORARY TABLE tmp_new_balances (
                    user_id BIGINT UNSIGNED,
                    add_amount DECIMAL(18,2)
                ) AS
                SELECT
                    user_id,
                    SUM(cashback) AS add_amount
                FROM `{$wpdb->prefix}cashback_transactions`
                WHERE
                    order_status = 'Подтвержден'
                    AND updated_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)
                    AND cashback IS NOT NULL
                GROUP BY user_id;

                UPDATE `{$wpdb->prefix}cashback_user_balance` sub
                JOIN tmp_new_balances tnb ON sub.user_id = tnb.user_id
                SET sub.available_balance = sub.available_balance + tnb.add_amount;

                INSERT INTO `{$wpdb->prefix}cashback_user_balance` (user_id, available_balance)
                SELECT user_id, add_amount
                FROM tmp_new_balances
                ON DUPLICATE KEY UPDATE
                available_balance = available_balance;

                UPDATE `{$wpdb->prefix}cashback_transactions`
                SET
                    order_status = 'Зачислено на баланс',
                    updated_at = CURRENT_TIMESTAMP
                WHERE
                    order_status = 'Подтвержден'
                    AND updated_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)
                    AND cashback IS NOT NULL;

                DROP TEMPORARY TABLE IF EXISTS tmp_new_balances;
            END;",

            "CREATE EVENT IF NOT EXISTS `{$wpdb->prefix}cashback_ev_cleanup_cashback_webhooks_old`
            ON SCHEDULE EVERY 1 DAY STARTS NOW()
            ON COMPLETION NOT PRESERVE
            ENABLE
            DO DELETE FROM `{$wpdb->prefix}cashback_webhooks`
            WHERE received_at < NOW() - INTERVAL 6 MONTH;",

            "CREATE EVENT IF NOT EXISTS `{$wpdb->prefix}cashback_ev_mark_inactive_profiles`
            ON SCHEDULE EVERY 1 DAY
            STARTS CURRENT_TIMESTAMP
            ON COMPLETION PRESERVE
            ENABLE
            DO
            BEGIN
                UPDATE `{$wpdb->prefix}cashback_user_profile`
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

        foreach ($events as $event) {
            @$wpdb->query($event); // Подавляем уведомления об ошибках
        }
    }

    /**
     * Инициализация существующих пользователей
     */
    private function initialize_existing_users()
    {
        global $wpdb;

        $users = get_users(array('fields' => 'ID'));
        foreach ($users as $user_id) {
            $this->add_user_to_profile($user_id);
        }
    }

    /**
     * Добавление пользователя в профиль
     */
    public function add_user_to_profile($user_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_profile';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));

        if (!$exists) {
            $wpdb->insert(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'status' => 'active'
                ),
                array('%d', '%s')
            );
        }

        $this->add_user_to_balance($user_id);
    }

    /**
     * Добавление пользователя в баланс
     */
    public function add_user_to_balance($user_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_balance';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));

        if (!$exists) {
            $wpdb->insert(
                $table_name,
                array(
                    'user_id' => $user_id,
                ),
                array('%d')
            );
        }
    }
}

// Инициализация плагина
function mariadb_plugin_init()
{
    return Mariadb_Plugin::get_instance();
}

// Инициализация плагина при полной загрузке WordPress
add_action('plugins_loaded', 'mariadb_plugin_init');

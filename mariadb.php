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
        add_action('user_register', array($this, 'add_user_to_cashback_tables'));
    }

    /**
     * Активация плагина
     */
    public static function activate()
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
            error_log('Mariadb Plugin Activation Error: ' . $e->getMessage());
            wp_die('Ошибка активации плагина Mariadb: ' . esc_html($e->getMessage()));
        }

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
            `status` enum('waiting','payd','declined') NOT NULL DEFAULT 'waiting',
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
            `order_status` enum('waiting','completed','declined','balance') NOT NULL DEFAULT 'waiting',
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
            `order_status`  enum('waiting','completed','declined','balance') NOT NULL DEFAULT 'waiting',
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
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Вэбхуки принятые от неавторизованных пользователей';";

        // Таблица cashback_user_balance
        $table4 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_user_balance` (
            `user_id` bigint(20) unsigned NOT NULL,
            `available_balance` decimal(18,2) NOT NULL DEFAULT 0.0 COMMENT 'Доступный баланс пользователя',
            `pending_balance` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'В ожидании выплаты',
            `paid_balance` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Выплачен',
            `frozen_balance`    decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Заблокирован',
            PRIMARY KEY (`user_id`),
            CONSTRAINT `fk_balance_user` FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_collate}  COMMENT='Балансы пользователей кэшбэк-сервиса';";

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
            `payout_method` ENUM('sbp','mir','yoomoney') DEFAULT NULL COMMENT 'СБП, Карта, ЮMoney и т.д.',
            `payout_account` varchar(255) DEFAULT NULL COMMENT 'Телефон, номер карты или кошелёк',
            `payout_full_name` varchar(255) DEFAULT NULL COMMENT 'ФИО для выплат',
            `cashback_rate` decimal(5,2) NOT NULL DEFAULT 60.00 COMMENT 'Процент кэшбэка (60 = 60%)' CHECK (cashback_rate BETWEEN 0.00 AND 100.00),
            `is_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = реквизиты подтверждены',
            `payout_details_updated_at` datetime DEFAULT NULL COMMENT 'Дата и время обновления реквизитов',
            `min_payout_amount` decimal(18,2) DEFAULT 100.00 COMMENT 'Минимальная сумма выплаты',
            `opt_out` tinyint(1) NOT NULL DEFAULT 0,
            `status` ENUM('active','noactive','banned','deleted') NOT NULL DEFAULT 'active' COMMENT 'Статус профиля',
            `banned_at` DATETIME DEFAULT NULL COMMENT 'Дата и время блокировки',
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

        error_log('Mariadb Plugin: Tables created successfully');
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
            $result = $wpdb->query($drop_trigger);
            if ($result === false) {
                error_log('Mariadb Plugin Warning: Failed to drop trigger. Error: ' . $wpdb->last_error);
            }
        }

        $triggers = [
            "CREATE TRIGGER `{$wpdb->prefix}calculate_cashback_before_insert`
            BEFORE INSERT ON `{$wpdb->prefix}cashback_transactions`
            FOR EACH ROW
            -- 'Автоматически рассчитывает кэшбэк при вставке на основе индивидуального cashback_rate пользователя'
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
            --  'Рассчитывает кэшбэк для незарегистрированных пользователей по фиксированной ставке 60%'
            BEGIN
                SET NEW.cashback = FLOOR(NEW.commission * 0.6);
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}calculate_cashback_before_update`
            BEFORE UPDATE ON `{$wpdb->prefix}cashback_transactions`
            FOR EACH ROW
            --  'Пересчитывает кэшбэк только при изменении commission, используя сохранённую applied_cashback_rate'
            BEGIN
                IF OLD.commission != NEW.commission THEN
                    SET NEW.cashback = ROUND(NEW.commission * NEW.applied_cashback_rate / 100, 2);
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}calculate_cashback_before_update_unregistered`
            BEFORE UPDATE ON `{$wpdb->prefix}cashback_unregistered_transactions`
            FOR EACH ROW
            --  'Пересчитывает кэшбэк для незарегистрированных пользователей при изменении commission'
            BEGIN
                IF OLD.commission != NEW.commission THEN
                    SET NEW.cashback = FLOOR(NEW.commission * 0.6);
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}cashback_tr_prevent_delete_final_status`
            BEFORE DELETE ON `{$wpdb->prefix}cashback_transactions`
            FOR EACH ROW
            --  'Запрещает удаление транзакций со статусом ''balance'' (финальный статус)'
            BEGIN
                IF OLD.order_status = 'balance' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Удаление запрещено: запись с финальным статусом не может быть удалена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}cashback_tr_prevent_update_final_status`
            BEFORE UPDATE ON `{$wpdb->prefix}cashback_transactions`
            FOR EACH ROW
            --  'Запрещает изменение транзакций со статусом ''balance'' (финальный статус)'
            BEGIN
                IF OLD.order_status = 'balance' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: запись с финальным статусом не может быть изменена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}tr_prevent_delete_paid_payout`
            BEFORE DELETE ON `{$wpdb->prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Запрещает удаление заявок на выплату со статусом ''payd'' выплачена'
            BEGIN
                IF OLD.status = 'payd' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Удаление запрещено: выплаченная заявка не может быть удалена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}tr_prevent_update_paid_payout`
            BEFORE UPDATE ON `{$wpdb->prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Запрещает изменение заявок на выплату со статусом ''payd'' выплачена'
            BEGIN
                IF OLD.status = 'payd' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: выплаченная заявка не может быть изменена.';
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

        $events = [
            // Событие ежедневно проверяет одобренный кэшбэк если старше 14 дней переводит в доступный баланс
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
                    order_status = 'completed'
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
                    order_status = 'balance',
                    updated_at = CURRENT_TIMESTAMP
                WHERE
                    order_status = 'completed'
                    AND updated_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)
                    AND cashback IS NOT NULL;

                DROP TEMPORARY TABLE IF EXISTS tmp_new_balances;
            END;",

            // Событие ежедневно проверяет и удаляет старые вебхуки если старше 6 месяцев
            "CREATE EVENT IF NOT EXISTS `{$wpdb->prefix}cashback_ev_cleanup_cashback_webhooks_old`
            ON SCHEDULE EVERY 1 DAY STARTS NOW()
            ON COMPLETION NOT PRESERVE
            ENABLE
            DO DELETE FROM `{$wpdb->prefix}cashback_webhooks`
            WHERE received_at < NOW() - INTERVAL 6 MONTH;",

            // Событие ежедневно проверяет и помечает неактивные профили если неактивны больше 6 месяцев
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
            $result = $this->add_user_to_cashback_tables($user_id);
            if (!$result) {
                throw new Exception("Failed to initialize user {$user_id}. Error: " . $wpdb->last_error);
            }
        }

        error_log('Mariadb Plugin: Successfully initialized ' . count($users) . ' existing users');
    }

    /**
     * Добавление пользователя в профиль
     */
    public function add_user_to_profile($user_id)
    {
        global $wpdb;

        error_log('Mariadb Plugin: Adding user profile for user ID: ' . $user_id);

        $table_name = $wpdb->prefix . 'cashback_user_profile';

        // Проверяем, существует ли уже запись
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));

        error_log('Mariadb Plugin: Profile exists check result: ' . $exists . ' for user ID: ' . $user_id);

        if (!$exists) {
            $result = $wpdb->insert(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'status' => 'active'
                ),
                array('%d', '%s')
            );

            if ($result === false) {
                error_log('Mariadb Plugin Error: Failed to insert user profile for user ' . $user_id . '. Error: ' . $wpdb->last_error);
                return false;
            } else {
                error_log('Mariadb Plugin: Successfully inserted user profile for user ID: ' . $user_id);
            }
        } else {
            error_log('Mariadb Plugin: User profile already exists for user ID: ' . $user_id);
        }

        return $this->add_user_to_balance($user_id);
    }

    /**
     * Добавление пользователя в баланс
     */
    public function add_user_to_balance($user_id)
    {
        global $wpdb;

        error_log('Mariadb Plugin: Adding user balance for user ID: ' . $user_id);

        $table_name = $wpdb->prefix . 'cashback_user_balance';

        // Проверяем, существует ли уже запись
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));

        error_log('Mariadb Plugin: Balance exists check result: ' . $exists . ' for user ID: ' . $user_id);

        if (!$exists) {
            $result = $wpdb->insert(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'available_balance' => 0.0,
                    'pending_balance' => 0.0,
                    'paid_balance' => 0.0
                ),
                array('%d', '%f', '%f', '%f')
            );

            if ($result === false) {
                error_log('Mariadb Plugin Error: Failed to insert user balance for user ' . $user_id . '. Error: ' . $wpdb->last_error);
                return false;
            } else {
                error_log('Mariadb Plugin: Successfully inserted user balance for user ID: ' . $user_id);
            }
        } else {
            error_log('Mariadb Plugin: User balance already exists for user ID: ' . $user_id);
        }

        return true;
    }

    /**
     * Добавление пользователя в таблицы кэшбэка при регистрации
     */
    public function add_user_to_cashback_tables($user_id)
    {
        error_log('Mariadb Plugin: Processing user registration for user ID: ' . $user_id);

        // Сначала добавляем в профиль, который в свою очередь добавит в баланс
        $result = $this->add_user_to_profile($user_id);

        if ($result) {
            error_log('Mariadb Plugin: Successfully added user ' . $user_id . ' to cashback tables');
        } else {
            error_log('Mariadb Plugin: Failed to add user ' . $user_id . ' to cashback tables');
        }

        return $result;
    }
}

// Инициализация плагина
function mariadb_plugin_init()
{
    $instance = Mariadb_Plugin::get_instance();
    return $instance;
}

// Инициализация плагина при полной загрузке WordPress
add_action('plugins_loaded', 'mariadb_plugin_init');

// Также добавим инициализацию при инициализации WordPress, чтобы убедиться, что хуки зарегистрированы
add_action('init', 'mariadb_plugin_init');

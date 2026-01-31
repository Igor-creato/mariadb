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
            wc_get_logger()->error('Mariadb Plugin Activation Error: ' . $e->getMessage());
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

        // Таблица cashback_payout_requests с защитой от дублирования
        $table1 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_payout_requests` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL,
            `total_amount` decimal(18,2) NOT NULL,
            `payout_method` varchar(255) NOT NULL COMMENT 'Способ выплаты (например: СБП, карта, юmoney)',
            `payout_account` varchar(255) NOT NULL COMMENT 'Реквизиты получателя (номер телефона, карты и т.п.)',
            `provider` varchar(100) DEFAULT NULL COMMENT 'Идентификатор провайдера выплат (банк/сервис)',
            `provider_payout_id` varchar(255) DEFAULT NULL COMMENT 'ID операции у провайдера',
            `idempotency_key` char(64) NOT NULL COMMENT 'Ключ идемпотентности для предотвращения дублирования выплат',
            `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'Количество попыток отправки выплаты',
            `fail_reason` text DEFAULT NULL COMMENT 'Код/описание ошибки последней попытки',
            `status` enum('waiting','processing','paid','failed','declined','needs_retry') NOT NULL DEFAULT 'waiting',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_idempotency` (`idempotency_key`) COMMENT 'Гарантирует уникальность заявки на выплату',
            KEY `idx_user_status` (`user_id`,`status`),
            KEY `idx_status_updated` (`status`,`updated_at`),
            KEY `idx_provider_payout_id` (`provider_payout_id`),
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
           `commission` decimal(10,2) DEFAULT NULL,
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
            KEY `idx_order_status_updated_cashback` (`order_status`,`updated_at`,`cashback`),
            KEY `idx_processed` (`processed_at`),
            KEY `idx_processed_batch_id` (`processed_batch_id`),
            CONSTRAINT `fk_transactions_user`
            FOREIGN KEY (`user_id`)
            REFERENCES `{$wpdb->prefix}users` (`ID`)
            ON DELETE CASCADE,
            CONSTRAINT `chk_applied_cashback_rate_range`
            CHECK (`applied_cashback_rate` BETWEEN 0.00 AND 100.00)
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
            `cashback_rate` decimal(5,2) NOT NULL DEFAULT 60.00 COMMENT 'Процент кэшбэка (60 = 60%)' CHECK (`cashback_rate` BETWEEN 0.00 AND 100.00),
            `is_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = реквизиты подтверждены',
            `payout_details_updated_at` datetime DEFAULT NULL COMMENT 'Дата и время обновления реквизитов',
            `min_payout_amount` decimal(18,2) DEFAULT 100.00 COMMENT 'Минимальная сумма выплаты',
            `opt_out` tinyint(1) NOT NULL DEFAULT 0,
            `status` enum('active','noactive','banned','deleted') NOT NULL DEFAULT 'active' COMMENT 'Статус профиля',
            `banned_at` datetime DEFAULT NULL COMMENT 'Дата и время блокировки',
            `ban_reason` varchar(25) DEFAULT NULL COMMENT 'Причина блокировки',
            `last_active_at` datetime DEFAULT NULL COMMENT 'Дата и время последней активности',
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`user_id`),
            KEY `idx_active_check` (`status`,`last_active_at`,`created_at`),
            KEY `idx_payout_method` (`payout_method_id`),
            CONSTRAINT `fk_profile_wp_user` FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE,
            CONSTRAINT `fk_payout_method` FOREIGN KEY (`payout_method_id`) REFERENCES `{$wpdb->prefix}cashback_payout_methods` (`id`) ON DELETE SET NULL
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

        // Таблица тикетов
        $tickets_table = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_tickets` (
          `ticket_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'ID пользователя WordPress',
          `subject` varchar(255) NOT NULL COMMENT 'Тема тикета',
          `status` enum('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
          `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          `last_activity` datetime NOT NULL DEFAULT current_timestamp(),
          
          PRIMARY KEY (`ticket_id`),
          KEY `user_id` (`user_id`),
          KEY `status` (`status`),
          KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Таблица тикетов поддержки';";

        // Таблица сообщений тикетов
        $messages_table = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_ticket_messages` (
          `message_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `ticket_id` bigint(20) UNSIGNED NOT NULL,
          `user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'ID отправителя (пользователь или админ)',
          `message` text NOT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `is_admin` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=админ, 0=пользователь',
          
          PRIMARY KEY (`message_id`),
          KEY `ticket_id` (`ticket_id`),
          KEY `user_id` (`user_id`),
          KEY `created_at` (`created_at`),
          CONSTRAINT `fk_ticket_messages_ticket_{$wpdb->prefix}` 
            FOREIGN KEY (`ticket_id`) 
            REFERENCES `{$wpdb->prefix}cashback_tickets` (`ticket_id`) 
            ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Сообщения в тикетах поддержки';";

        // Таблица вложений к сообщениям тикетов
        $attachments_table = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_ticket_attachments` (
          `attachment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `message_id` bigint(20) UNSIGNED NOT NULL,
          `file_name` varchar(255) NOT NULL,
          `file_path` varchar(255) NOT NULL COMMENT 'Путь к файлу в uploads',
          `file_size` int(11) NOT NULL COMMENT 'Размер в байтах',
          `file_type` varchar(100) NOT NULL COMMENT 'MIME тип',
          `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
          
          PRIMARY KEY (`attachment_id`),
          KEY `message_id` (`message_id`),
          CONSTRAINT `fk_ticket_attachments_message_{$wpdb->prefix}` 
            FOREIGN KEY (`message_id`) 
            REFERENCES `{$wpdb->prefix}cashback_ticket_messages` (`message_id`) 
            ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Вложения к сообщениям тикетов';";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($table1);
        dbDelta($table2);
        dbDelta($table3);
        dbDelta($table4);
        dbDelta($table5);
        dbDelta($table7); // Создаем payout_methods ПЕРЕД user_profile
        dbDelta($table6); // Создаем user_profile после payout_methods
        dbDelta($tickets_table);   // Создаем таблицу тикетов
        dbDelta($messages_table);  // Создаем таблицу сообщений
        dbDelta($attachments_table); // Создаем таблицу вложений

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
            "DROP TRIGGER IF EXISTS `{$wpdb->prefix}tr_banned_user_update_banned_at`;",
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
                SET NEW.cashback = ROUND(NEW.commission * 0.6, 2);
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
                    SET NEW.cashback = ROUND(NEW.commission * 0.6, 2);
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
            --  'Запрещает удаление заявок на выплату со статусом ''paid'' выплачена'
            BEGIN
                IF OLD.status = 'paid' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Удаление запрещено: выплаченная заявка не может быть удалена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}tr_prevent_update_paid_payout`
            BEFORE UPDATE ON `{$wpdb->prefix}cashback_payout_requests`
            FOR EACH ROW
            --  'Запрещает изменение заявок на выплату со статусом ''paid'' выплачена'
            BEGIN
                IF OLD.status = 'paid' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Изменение запрещено: выплаченная заявка не может быть изменена.';
                END IF;
            END;",

            "CREATE TRIGGER `{$wpdb->prefix}tr_banned_user_update_banned_at`
            BEFORE UPDATE ON `{$wpdb->prefix}cashback_user_profile`
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

        $events = [
            // Событие ежедневно проверяет одобренный кэшбэк если старше 14 дней переводит в доступный баланс
            // ПОЛНАЯ ЗАЩИТА ОТ ДУБЛИРОВАНИЯ: идемпотентность через processed_at и атомарные операции
            "CREATE EVENT IF NOT EXISTS `{$wpdb->prefix}cashback_ev_confirmed_cashback`
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
        FROM `{$wpdb->prefix}cashback_transactions`
        WHERE
            order_status = 'completed'
            AND processed_at IS NULL
            AND cashback IS NOT NULL
            AND cashback > 0
            AND updated_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)
        FOR UPDATE;

        SET v_affected_rows = ROW_COUNT();

        IF v_affected_rows > 0 THEN
            -- ШАГ 1: КРИТИЧНО - Сначала маркируем транзакции через processed_at
            -- Это источник истины для идемпотентности
            -- Если после этого шага упадет БД, при повторном запуске эти транзакции НЕ попадут в tmp_cashback_batch
            UPDATE `{$wpdb->prefix}cashback_transactions` ct
            INNER JOIN tmp_cashback_batch tcb ON ct.id = tcb.transaction_id
            SET
                ct.processed_at = NOW(),
                ct.processed_batch_id = v_batch_id
            WHERE ct.processed_at IS NULL;

            -- ШАГ 2: Начисляем баланс ТОЛЬКО для транзакций с processed_batch_id = v_batch_id
            -- Используем processed_batch_id как источник данных (уже гарантированно уникальные)
            INSERT INTO `{$wpdb->prefix}cashback_user_balance`
                (user_id, available_balance, version)
            SELECT
                user_id,
                SUM(cashback),
                0
            FROM `{$wpdb->prefix}cashback_transactions`
            WHERE processed_batch_id = v_batch_id
            GROUP BY user_id
            ON DUPLICATE KEY UPDATE
                available_balance = available_balance + VALUES(available_balance),
                version = version + 1;

            -- ШАГ 3: Финализируем статус (делаем транзакции неизменяемыми через триггер)
            -- Только если processed_batch_id соответствует текущему батчу
            UPDATE `{$wpdb->prefix}cashback_transactions`
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
            "CREATE EVENT IF NOT EXISTS `{$wpdb->prefix}cashback_ev_cleanup_cashback_webhooks_old`
            ON SCHEDULE EVERY 1 DAY
            STARTS CURRENT_TIMESTAMP
            ON COMPLETION NOT PRESERVE
            ENABLE
            DO DELETE FROM `{$wpdb->prefix}cashback_webhooks`
            WHERE received_at < NOW() - INTERVAL 6 MONTH",

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

        error_log('Mariadb Plugin: Adding user profile for user ID: ' . $user_id);

        $table_name = $wpdb->prefix . 'cashback_user_profile';

        // Проверяем, существует ли уже запись
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));

        error_log('Mariadb Plugin: Profile exists check result: ' . $exists . ' for user ID: ' . $user_id);

        if (!$exists) {
            // Начинаем транзакцию для атомарного создания профиля и баланса
            $wpdb->query('START TRANSACTION');

            try {
                $result = $wpdb->insert(
                    $table_name,
                    array(
                        'user_id' => $user_id,
                        'status' => 'active'
                    ),
                    array('%d', '%s')
                );

                if ($result === false) {
                    throw new Exception('Failed to insert user profile: ' . $wpdb->last_error);
                }

                error_log('Mariadb Plugin: Successfully inserted user profile for user ID: ' . $user_id);

                // Добавляем запись в таблицу баланса
                $balance_result = $this->add_user_to_balance($user_id);

                if (!$balance_result) {
                    throw new Exception('Failed to create user balance');
                }

                // Если всё успешно, фиксируем транзакцию
                $wpdb->query('COMMIT');
                return true;
            } catch (Exception $e) {
                // В случае ошибки откатываем транзакцию
                $wpdb->query('ROLLBACK');
                error_log('Mariadb Plugin Error: Transaction failed for user ' . $user_id . '. Error: ' . $e->getMessage());
                return false;
            }
        } else {
            error_log('Mariadb Plugin: User profile already exists for user ID: ' . $user_id);
            return $this->add_user_to_balance($user_id);
        }
    }

    /**
     * Добавление пользователя в баланс
     */
    public function add_user_to_balance(int $user_id): bool
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
    public function add_user_to_cashback_tables(int $user_id): bool
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

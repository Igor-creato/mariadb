-- ============================================================================
-- РУЧНАЯ УСТАНОВКА СОБЫТИЯ НАЧИСЛЕНИЯ КЭШБЭКА
-- ============================================================================
-- Используйте этот скрипт, если событие не создалось автоматически при активации плагина
-- 
-- ВАЖНО: Замените 'wp_' на ваш префикс таблиц WordPress перед выполнением!
-- ============================================================================

-- Удаляем существующее событие (если есть)
DROP EVENT IF EXISTS `wp_cashback_ev_confirmed_cashback`;

-- Создаем событие с полной защитой от дублирования
DELIMITER $$

CREATE EVENT `wp_cashback_ev_confirmed_cashback`
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

    -- 🔒 Блокировка события на уровне СУБД (защита от параллельного запуска)
    SET v_event_lock = GET_LOCK('cashback_event_lock', 0);
    
    IF v_event_lock = 1 THEN
        -- Генерируем UUID батча (идемпотентный ключ)
        SET v_batch_id = UUID();

        START TRANSACTION;

        -- 1. Создаем временную таблицу текущего батча
        DROP TEMPORARY TABLE IF EXISTS tmp_cashback_batch;

        CREATE TEMPORARY TABLE tmp_cashback_batch (
            transaction_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            cashback DECIMAL(10,2) NOT NULL,
            INDEX idx_user (user_id)
        );

        -- 2. 🔒 Захватываем транзакции с блокировкой строк (FOR UPDATE)
        -- processed_at IS NULL гарантирует однократную обработку
        INSERT INTO tmp_cashback_batch (transaction_id, user_id, cashback)
        SELECT id, user_id, cashback
        FROM `wp_cashback_transactions`
        WHERE
            order_status = 'completed'
            AND processed_at IS NULL
            AND cashback IS NOT NULL
            AND cashback > 0
            AND updated_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)
        FOR UPDATE;

        SET v_affected_rows = ROW_COUNT();

        -- Обрабатываем только если есть транзакции
        IF v_affected_rows > 0 THEN
            -- ШАГ 1: КРИТИЧНО - Сначала маркируем транзакции через processed_at
            -- Это источник истины для идемпотентности
            -- Если после этого шага упадет БД, при повторном запуске эти транзакции НЕ попадут в tmp_cashback_batch
            UPDATE `wp_cashback_transactions` ct
            INNER JOIN tmp_cashback_batch tcb ON ct.id = tcb.transaction_id
            SET
                ct.processed_at = NOW(),
                ct.processed_batch_id = v_batch_id
            WHERE ct.processed_at IS NULL;

            -- ШАГ 2: Начисляем баланс ТОЛЬКО для транзакций с processed_batch_id = v_batch_id
            -- Используем processed_batch_id как источник данных (уже гарантированно уникальные)
            INSERT INTO `wp_cashback_user_balance`
                (user_id, available_balance, version)
            SELECT
                user_id,
                SUM(cashback),
                0
            FROM `wp_cashback_transactions`
            WHERE processed_batch_id = v_batch_id
            GROUP BY user_id
            ON DUPLICATE KEY UPDATE
                available_balance = available_balance + VALUES(available_balance),
                version = version + 1;

            -- ШАГ 3: Финализируем статус (делаем транзакции неизменяемыми через триггер)
            -- Только если processed_batch_id соответствует текущему батчу
            UPDATE `wp_cashback_transactions`
            SET order_status = 'balance'
            WHERE
                processed_batch_id = v_batch_id
                AND order_status = 'completed';
        END IF;

        DROP TEMPORARY TABLE IF EXISTS tmp_cashback_batch;

        COMMIT;

        -- Освобождаем блокировку события
        DO RELEASE_LOCK('cashback_event_lock');
    END IF;
END$$

DELIMITER ;

-- ============================================================================
-- ПРОВЕРКА УСТАНОВКИ
-- ============================================================================

-- Проверяем, что событие создано и активно
SELECT 
    EVENT_NAME as 'Название',
    EVENT_DEFINITION as 'Определение',
    INTERVAL_VALUE as 'Интервал',
    INTERVAL_FIELD as 'Единица',
    STATUS as 'Статус'
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE()
  AND EVENT_NAME = 'wp_cashback_ev_confirmed_cashback';

-- Проверяем глобальную настройку event_scheduler
SHOW VARIABLES LIKE 'event_scheduler';

-- Если event_scheduler = OFF, включите его командой:
-- SET GLOBAL event_scheduler = ON;
-- 
-- ВАЖНО: После перезапуска MySQL/MariaDB нужно снова включить или добавить в my.cnf:
-- [mysqld]
-- event_scheduler = ON

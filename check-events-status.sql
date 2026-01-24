-- ============================================================================
-- ДИАГНОСТИКА СОБЫТИЙ MARIADB
-- ============================================================================
-- Используйте этот скрипт для проверки состояния событий и планировщика
-- ============================================================================

-- 1. Проверяем глобальную настройку event_scheduler
SELECT 
    '1. Event Scheduler Status' as 'Проверка',
    @@global.event_scheduler as 'Значение',
    CASE 
        WHEN @@global.event_scheduler = 'ON' THEN '✅ Включен' 
        WHEN @@global.event_scheduler = 'OFF' THEN '❌ Выключен - включите командой: SET GLOBAL event_scheduler = ON;'
        ELSE '⚠️  Disabled' 
    END as 'Статус';

-- 2. Список всех событий в текущей базе данных
SELECT 
    '2. Список событий' as 'Проверка',
    EVENT_NAME as 'Название',
    STATUS as 'Статус',
    EVENT_TYPE as 'Тип',
    EXECUTE_AT as 'Выполнить в',
    INTERVAL_VALUE as 'Интервал',
    INTERVAL_FIELD as 'Единица',
    STARTS as 'Начало',
    ENDS as 'Конец',
    LAST_EXECUTED as 'Последний запуск'
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE()
ORDER BY EVENT_NAME;

-- 3. Проверяем наше основное событие начисления кэшбэка
SELECT 
    '3. Событие cashback_ev_confirmed_cashback' as 'Проверка',
    CASE 
        WHEN COUNT(*) > 0 THEN '✅ Событие существует'
        ELSE '❌ Событие не найдено - выполните install-event-manual.sql'
    END as 'Статус',
    MAX(STATUS) as 'Статус события',
    MAX(LAST_EXECUTED) as 'Последний запуск'
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE()
  AND EVENT_NAME LIKE '%cashback_ev_confirmed_cashback%';

-- 4. Проверяем права текущего пользователя на создание событий
SELECT 
    '4. Права на создание событий' as 'Проверка',
    CASE 
        WHEN COUNT(*) > 0 THEN '✅ Есть права EVENT'
        ELSE '❌ Нет прав EVENT - обратитесь к администратору БД'
    END as 'Статус'
FROM information_schema.USER_PRIVILEGES
WHERE PRIVILEGE_TYPE = 'EVENT'
  AND GRANTEE LIKE CONCAT('%', USER(), '%');

-- 5. Проверяем наличие необходимых таблиц
SELECT 
    '5. Проверка таблиц' as 'Проверка',
    TABLE_NAME as 'Таблица',
    CASE 
        WHEN TABLE_NAME IS NOT NULL THEN '✅ Существует'
        ELSE '❌ Не найдена'
    END as 'Статус'
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      CONCAT(DATABASE(), '_cashback_transactions'),
      CONCAT(DATABASE(), '_cashback_user_balance'),
      CONCAT(DATABASE(), '_cashback_payout_requests')
  )
ORDER BY TABLE_NAME;

-- 6. Проверяем транзакции, готовые к обработке
SELECT 
    '6. Транзакции к обработке' as 'Проверка',
    COUNT(*) as 'Количество',
    COALESCE(SUM(cashback), 0) as 'Общая сумма кэшбэка',
    CASE 
        WHEN COUNT(*) > 0 THEN '⚠️  Есть необработанные транзакции старше 14 дней'
        ELSE '✅ Нет транзакций к обработке'
    END as 'Статус'
FROM wp_cashback_transactions
WHERE
    order_status = 'completed'
    AND processed_at IS NULL
    AND cashback IS NOT NULL
    AND cashback > 0
    AND updated_at <= DATE_SUB(NOW(), INTERVAL 14 DAY);

-- 7. Проверяем блокировки (если событие зависло)
SELECT 
    '7. Активные блокировки' as 'Проверка',
    IS_USED_LOCK('cashback_event_lock') as 'Thread ID',
    CASE 
        WHEN IS_USED_LOCK('cashback_event_lock') IS NULL THEN '✅ Блокировки нет'
        ELSE '⚠️  Событие выполняется или зависло'
    END as 'Статус';

-- ============================================================================
-- КОМАНДЫ ДЛЯ ИСПРАВЛЕНИЯ ПРОБЛЕМ
-- ============================================================================

/*
-- Если event_scheduler выключен:
SET GLOBAL event_scheduler = ON;

-- Если событие не создано, выполните:
SOURCE install-event-manual.sql;

-- Или скопируйте содержимое файла install-event-manual.sql и выполните

-- Если событие зависло (активная блокировка):
SELECT RELEASE_LOCK('cashback_event_lock');

-- Для принудительного запуска события ВРУЧНУЮ (опционально):
CALL wp_cashback_ev_confirmed_cashback();

-- Для отключения события:
ALTER EVENT wp_cashback_ev_confirmed_cashback DISABLE;

-- Для включения события:
ALTER EVENT wp_cashback_ev_confirmed_cashback ENABLE;

-- Для удаления события:
DROP EVENT IF EXISTS wp_cashback_ev_confirmed_cashback;
*/

-- ============================================================================
-- ПРОВЕРКА ЦЕЛОСТНОСТИ ДАННЫХ
-- ============================================================================

-- 8. Сверка балансов с транзакциями
SELECT 
    '8. Проверка целостности балансов' as 'Проверка',
    COUNT(*) as 'Пользователей с расхождением'
FROM (
    SELECT 
        b.user_id,
        b.available_balance + b.pending_balance + b.paid_balance AS balance_total,
        COALESCE(SUM(CASE WHEN t.order_status = 'balance' THEN t.cashback ELSE 0 END), 0) AS transactions_total
    FROM wp_cashback_user_balance b
    LEFT JOIN wp_cashback_transactions t ON b.user_id = t.user_id
    GROUP BY b.user_id
    HAVING ABS(balance_total - transactions_total) > 0.01
) AS inconsistent_balances;

-- Детали расхождений (если есть)
SELECT 
    b.user_id,
    b.available_balance,
    b.pending_balance,
    b.paid_balance,
    b.available_balance + b.pending_balance + b.paid_balance AS balance_total,
    COALESCE(SUM(CASE WHEN t.order_status = 'balance' THEN t.cashback ELSE 0 END), 0) AS transactions_balance,
    ABS((b.available_balance + b.pending_balance + b.paid_balance) - 
        COALESCE(SUM(CASE WHEN t.order_status = 'balance' THEN t.cashback ELSE 0 END), 0)) AS difference
FROM wp_cashback_user_balance b
LEFT JOIN wp_cashback_transactions t ON b.user_id = t.user_id
GROUP BY b.user_id
HAVING ABS(balance_total - transactions_balance) > 0.01
LIMIT 10;

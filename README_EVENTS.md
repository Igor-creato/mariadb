# Инструкция по Установке и Проверке Событий MariaDB

## 📋 Быстрый Старт

После активации плагина проверьте, что события созданы:

1. Откройте phpMyAdmin или любой SQL-клиент
2. Выполните скрипт [`check-events-status.sql`](check-events-status.sql)
3. Проверьте результаты

---

## ⚠️ Если Событие НЕ Создалось

### Причина 1: Event Scheduler выключен

**Проверка:**

```sql
SHOW VARIABLES LIKE 'event_scheduler';
```

**Решение:**

```sql
SET GLOBAL event_scheduler = ON;
```

**Постоянная настройка** (добавьте в `my.ini` или `my.cnf`):

```ini
[mysqld]
event_scheduler = ON
```

### Причина 2: Нет прав на создание событий

**Проверка:**

```sql
SELECT * FROM information_schema.USER_PRIVILEGES
WHERE PRIVILEGE_TYPE = 'EVENT'
  AND GRANTEE LIKE CONCAT('%', USER(), '%');
```

**Решение** (от имени root):

```sql
GRANT EVENT ON database_name.* TO 'your_user'@'localhost';
FLUSH PRIVILEGES;
```

### Причина 3: Синтаксическая ошибка

**Решение:** Используйте ручную установку:

1. Откройте файл [`install-event-manual.sql`](install-event-manual.sql)
2. Замените все `wp_` на ваш префикс таблиц
3. Выполните скрипт в phpMyAdmin

---

## 🔧 Ручная Установка События

### Шаг 1: Определите префикс таблиц

```sql
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME LIKE '%cashback_transactions%';
```

Результат покажет вам префикс, например: `wp_cashback_transactions` → префикс `wp_`

### Шаг 2: Откройте файл install-event-manual.sql

Замените все `wp_` на ваш префикс в файле [`install-event-manual.sql`](install-event-manual.sql)

### Шаг 3: Выполните скрипт

Скопируйте весь содержимое файла и выполните в SQL-клиенте.

### Шаг 4: Проверьте результат

```sql
SELECT EVENT_NAME, STATUS, LAST_EXECUTED
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE()
  AND EVENT_NAME = 'wp_cashback_ev_confirmed_cashback';
```

Ожидаемый результат:

- `EVENT_NAME`: wp_cashback_ev_confirmed_cashback
- `STATUS`: ENABLED
- `LAST_EXECUTED`: NULL (если еще не запускалось)

---

## 🧪 Тестирование События

### 1. Создайте тестовую транзакцию

```sql
-- Вставьте тестовую транзакцию (замените 1 на реальный user_id)
INSERT INTO wp_cashback_transactions
    (user_id, order_number, offer_name, order_status, partner, commission, cashback, updated_at)
VALUES
    (1, 'TEST-001', 'Test Offer', 'completed', 'test_partner', 100.00, 60.00, DATE_SUB(NOW(), INTERVAL 15 DAY));
```

### 2. Принудительно запустите событие

```sql
-- Вручную вызываем логику события
CALL manual_process_cashback();
```

Или создайте временное событие для немедленного запуска:

```sql
CREATE EVENT temp_test_event
ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 5 SECOND
ON COMPLETION NOT PRESERVE
DO
BEGIN
    -- Скопируйте сюда код из тела события cashback_ev_confirmed_cashback
END;
```

### 3. Проверьте результаты

```sql
-- Проверяем, что транзакция обработана
SELECT
    id,
    order_status,
    processed_at,
    processed_batch_id
FROM wp_cashback_transactions
WHERE order_number = 'TEST-001';

-- Ожидаемый результат:
-- order_status = 'balance'
-- processed_at = NOT NULL
-- processed_batch_id = (UUID)

-- Проверяем, что баланс начислен
SELECT available_balance, version
FROM wp_cashback_user_balance
WHERE user_id = 1;

-- Ожидаемый результат:
-- available_balance увеличился на 60.00
-- version увеличилась на 1
```

---

## 🛡️ Проверка Защиты от Дублирования

### Тест 1: Повторный запуск события

```sql
-- Запустите событие дважды подряд
-- Событие должно обработать транзакции только один раз

-- 1. Создайте тестовую транзакцию
INSERT INTO wp_cashback_transactions
    (user_id, order_number, offer_name, order_status, partner, commission, updated_at)
VALUES
    (1, 'TEST-DUP-001', 'Test', 'completed', 'test', 100.00, DATE_SUB(NOW(), INTERVAL 15 DAY));

-- 2. Запомните текущий баланс
SELECT @balance_before := available_balance FROM wp_cashback_user_balance WHERE user_id = 1;

-- 3. Запустите событие первый раз (вручную или подождите)

-- 4. Проверьте, что баланс увеличился ровно на cashback
SELECT
    @balance_before as 'До',
    available_balance as 'После',
    available_balance - @balance_before as 'Разница'
FROM wp_cashback_user_balance
WHERE user_id = 1;

-- 5. Попробуйте запустить событие еще раз

-- 6. Проверьте, что баланс НЕ изменился
SELECT
    available_balance,
    version
FROM wp_cashback_user_balance
WHERE user_id = 1;

-- ✅ УСПЕХ если баланс не изменился при повторном запуске
```

### Тест 2: Проверка блокировки события

```sql
-- В одном окне SQL-клиента:
SELECT GET_LOCK('cashback_event_lock', 60); -- Удерживаем блокировку 60 секунд

-- В другом окне попытайтесь запустить событие
-- Оно должно пропуститься, т.к. блокировка занята

-- Освободите блокировку:
SELECT RELEASE_LOCK('cashback_event_lock');
```

---

## 📊 Мониторинг

### Ежедневная проверка

```sql
-- Сколько транзакций ожидают обработки
SELECT COUNT(*) as 'К обработке'
FROM wp_cashback_transactions
WHERE order_status = 'completed'
  AND processed_at IS NULL
  AND cashback > 0
  AND updated_at <= DATE_SUB(NOW(), INTERVAL 14 DAY);

-- Когда событие последний раз выполнялось
SELECT LAST_EXECUTED
FROM information_schema.EVENTS
WHERE EVENT_NAME = 'wp_cashback_ev_confirmed_cashback';
```

### Проверка целостности

Используйте скрипт [`check-events-status.sql`](check-events-status.sql) для комплексной проверки:

- Статус планировщика
- Наличие событий
- Права пользователя
- Целостность балансов
- Активные блокировки

---

## ❌ Устранение Проблем

### Проблема: Событие зависло

**Признаки:**

- Блокировка `cashback_event_lock` активна длительное время
- Транзакции не обрабатываются

**Решение:**

```sql
-- Освободите блокировку
SELECT RELEASE_LOCK('cashback_event_lock');

-- Проверьте активные процессы
SHOW PROCESSLIST;

-- Прервите зависший процесс (замените X на реальный ID)
KILL X;
```

### Проблема: Расхождение балансов

**Признаки:**

- Сумма `available_balance + pending_balance + paid_balance` не совпадает с суммой `cashback` в транзакциях со статусом `balance`

**Решение:**

```sql
-- Выполните пересчет балансов (ОСТОРОЖНО!)
START TRANSACTION;

-- Пересчитываем баланс для конкретного пользователя
UPDATE wp_cashback_user_balance b
SET b.available_balance = (
    SELECT COALESCE(SUM(t.cashback), 0)
    FROM wp_cashback_transactions t
    WHERE t.user_id = b.user_id
      AND t.order_status = 'balance'
) - b.pending_balance - b.paid_balance,
b.version = b.version + 1
WHERE b.user_id = 1; -- Укажите ID пользователя

-- Проверьте результат перед COMMIT
SELECT * FROM wp_cashback_user_balance WHERE user_id = 1;

-- Если всё верно:
COMMIT;
-- Иначе:
-- ROLLBACK;
```

---

## 🔐 Безопасность

### Что гарантируется:

✅ **Идемпотентность** - повторный запуск события не создаст дубли
✅ **Блокировки** - событие не может выполняться параллельно
✅ **Транзакции** - все или ничего (ACID)
✅ **Аудит** - все изменения логируются через `processed_at` и `processed_batch_id`
✅ **Неизменяемость** - транзакции со статусом `balance` нельзя изменить (триггер защиты)

### Логика защиты:

1. **GET_LOCK('cashback_event_lock', 0)** - только одно событие может выполняться
2. **processed_at IS NULL** - транзакция обрабатывается только если еще не обработана
3. **FOR UPDATE** - блокирует строки транзакций на время обработки
4. **version + 1** - оптимистичная блокировка баланса
5. **EXIT HANDLER** - автоматический rollback при ошибке

---

## 📞 Поддержка

Если события не устанавливаются:

1. Проверьте логи ошибок MySQL/MariaDB
2. Выполните [`check-events-status.sql`](check-events-status.sql)
3. Выполните ручную установку через [`install-event-manual.sql`](install-event-manual.sql)
4. Проверьте права пользователя БД
5. Убедитесь что `event_scheduler = ON`

Полная документация по финансовой безопасности: [`FINANCIAL_SECURITY.md`](FINANCIAL_SECURITY.md)

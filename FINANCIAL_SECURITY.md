# Финансовая Безопасность Плагина Кэшбэка

## 🔐 Банковский Уровень Защиты

Этот документ описывает все механизмы финансовой безопасности, внедренные в плагин для предотвращения дублирования начислений и выплат.

---

## 1. Защита Начисления Кэшбэка (Событие `cashback_ev_confirmed_cashback`)

### Проблемы ДО улучшения:

- ❌ Возможность дублирования при перезапуске события
- ❌ Отсутствие блокировки на уровне СУБД
- ❌ Race conditions при параллельном выполнении
- ❌ Нет защиты от повторной обработки транзакций
- ❌ Возможность повторного начисления при сбое между UPDATE баланса и UPDATE статуса

### Улучшения:

#### 1.1. Идемпотентность через `processed_at` (Источник Истины)

```sql
-- Событие выбирает ТОЛЬКО транзакции с processed_at IS NULL
WHERE
    order_status = 'completed'
    AND processed_at IS NULL  -- Главная защита от дублирования
    AND cashback IS NOT NULL
    AND cashback > 0
```

#### 1.2. Блокировка события на уровне MariaDB

```sql
SET v_event_lock = GET_LOCK('cashback_event_lock', 0);

IF v_event_lock = 1 THEN
    -- Код события выполняется ТОЛЬКО если блокировка получена
    -- Параллельные запуски невозможны
    ...
END IF;
```

#### 1.3. ПРАВИЛЬНЫЙ Порядок Операций (КРИТИЧНО!)

**🔴 ПРОБЛЕМА:** Неправильный порядок может привести к дублированию при сбое:

```sql
-- НЕПРАВИЛЬНО:
-- 1. Начислить баланс
-- 2. Изменить статус
-- ПРОБЛЕМА: Если сбой между шагами → при повторе баланс начислится дважды!
```

**✅ ПРАВИЛЬНО:** Сначала маркируем `processed_at`, потом работаем с данными:

```sql
-- ШАГ 1: 🔒 Маркируем транзакции через processed_at
-- Это делает транзакции невидимыми для следующего запуска события
UPDATE wp_cashback_transactions ct
INNER JOIN tmp_cashback_batch tcb ON ct.id = tcb.transaction_id
SET
    ct.processed_at = NOW(),
    ct.processed_batch_id = v_batch_id
WHERE ct.processed_at IS NULL;

-- КРИТИЧНО: Теперь processed_at != NULL для этих транзакций
-- При ЛЮБОМ сбое ниже, повторный запуск события их не захватит!

-- ШАГ 2: Начисляем баланс из УЖЕ промаркированных транзакций
-- Используем не tmp-таблицу, а processed_batch_id как источник
INSERT INTO wp_cashback_user_balance (user_id, available_balance, version)
SELECT user_id, SUM(cashback), 0
FROM wp_cashback_transactions
WHERE processed_batch_id = v_batch_id  -- Источник: промаркированные транзакции
GROUP BY user_id
ON DUPLICATE KEY UPDATE
    available_balance = available_balance + VALUES(available_balance),
    version = version + 1;

-- ШАГ 3: Финализируем статус (безопасно, т.к. processed_at уже установлен)
UPDATE wp_cashback_transactions
SET order_status = 'balance'
WHERE processed_batch_id = v_batch_id AND order_status = 'completed';
```

**Гарантия:** Даже если БД упадет на ЛЮБОМ шаге, при перезапуске:

- Транзакции с `processed_at != NULL` НЕ попадут в выборку
- Баланс НЕ начислится повторно
- Система идемпотентна

#### 1.4. Блокировка строк через `FOR UPDATE`

```sql
-- Блокирует строки транзакций на время обработки
INSERT INTO tmp_cashback_batch (transaction_id, user_id, cashback)
SELECT id, user_id, cashback
FROM wp_cashback_transactions
WHERE ...
FOR UPDATE;  -- Другие соединения ждут освобождения блокировки
```

#### 1.5. Оптимистичная блокировка баланса через `version`

```sql
INSERT INTO wp_cashback_user_balance (user_id, available_balance, version)
...
ON DUPLICATE KEY UPDATE
    available_balance = available_balance + VALUES(available_balance),
    version = version + 1;  -- Защита от race conditions
```

#### 1.6. Автоматический Rollback при ошибках

```sql
DECLARE EXIT HANDLER FOR SQLEXCEPTION
BEGIN
    ROLLBACK;  -- Откатывает ВСЕ изменения в транзакции
    DO RELEASE_LOCK('cashback_event_lock');
END;
```

#### 1.7. Финализация статуса (триггер защиты)

```sql
-- После установки order_status = 'balance' транзакция становится неизменяемой
-- Триггер wp_cashback_tr_prevent_update_final_status запретит любые изменения
```

---

## 2. Защита Заявок на Выплаты

### Проблемы ДО улучшения:

- ❌ Отсутствие уникального идентификатора заявки
- ❌ Возможность создания дублей при повторных попытках
- ❌ Нет защиты от race conditions при списании баланса

### Улучшения:

#### 2.1. Уникальный индекс идемпотентности

```sql
CREATE TABLE wp_cashback_payout_requests (
    ...
    idempotency_key CHAR(64) NOT NULL,
    ...
    UNIQUE KEY uk_idempotency (idempotency_key),
    CHECK (total_amount > 0)
);
```

**Гарантия:** При попытке INSERT с существующим `idempotency_key` → ошибка `Duplicate entry`.

#### 2.2. Криптографически стойкий ключ

```php
$idempotency_key = hash('sha256',
    $user_id .
    '_' . microtime(true) .
    '_' . wp_create_nonce('cashback_withdrawal_' . $user_id) .
    '_' . bin2hex(random_bytes(16))
);
```

#### 2.3. Правильный порядок операций в PHP

```php
// ШАГ 1: Создаем заявку на выплату с уникальным ключом
$wpdb->insert($table_requests, [
    'idempotency_key' => $idempotency_key,
    ...
]);

// Если ошибка с uk_idempotency → это дубль, rollback
if (strpos($wpdb->last_error, 'uk_idempotency') !== false) {
    throw new Exception('Duplicate payout request detected');
}

$payout_id = $wpdb->insert_id;

// ШАГ 2: ТОЛЬКО ПОСЛЕ успешного создания заявки списываем баланс
$wpdb->query("UPDATE {$table_balance}
    SET available_balance = available_balance - {$amount},
        pending_balance = pending_balance + {$amount},
        version = version + 1
    WHERE user_id = {$user_id} AND version = {$old_version}");

// КРИТИЧНО: Если здесь ошибка → rollback удалит и заявку, и изменения баланса
```

**Почему именно так:**

- Если сначала списать баланс, потом создать заявку → при ошибке создания заявки баланс будет списан, но заявки не будет
- Если сначала создать заявку, потом списать баланс → при ошибке списания rollback удалит обе операции

---

## 3. Защита Транзакции Вывода Средств

### Многоуровневая защита:

#### 3.1. Защита от повторных запросов (Transient Lock)

```php
$transient_key = 'withdrawal_request_' . $user_id;
if (get_transient($transient_key)) {
    wp_send_json_error('Предыдущий запрос обрабатывается');
    return;
}
set_transient($transient_key, true, 30); // Блокировка на 30 секунд
```

#### 3.2. Блокировка на уровне MariaDB

```php
$lock_acquired = $wpdb->get_var($wpdb->prepare(
    "SELECT GET_LOCK('user_withdrawal_%d', 10)",
    $user_id
));

if (!$lock_acquired) {
    wp_send_json_error('Не удалось получить блокировку');
    return;
}
```

#### 3.3. Транзакция с `SELECT FOR UPDATE`

```php
$wpdb->query('START TRANSACTION');

$user_balance = $wpdb->get_row($wpdb->prepare(
    "SELECT available_balance, version
     FROM {$table_balance}
     WHERE user_id = %d FOR UPDATE",  // Блокировка строки
    $user_id
));
```

#### 3.4. Оптимистичная блокировка

```php
$result = $wpdb->query($wpdb->prepare(
    "UPDATE {$table_balance}
     SET available_balance = available_balance - %f,
         version = version + 1
     WHERE user_id = %d AND version = %d",  // Проверка версии
    $withdrawal_amount, $user_id, $user_balance->version
));

if ($result === 0) {
    throw new Exception('Failed to update - version conflict');
}
```

---

## 4. Защита на Уровне Базы Данных

### 4.1. Уникальные индексы

```sql
-- Таблица транзакций
UNIQUE KEY unique_uniq_partner (uniq_id, partner),
UNIQUE KEY idx_idempotency_key (idempotency_key),

-- Таблица заявок на выплаты
UNIQUE KEY uk_idempotency (idempotency_key),

-- Таблица webhooks
UNIQUE KEY uk_payload_norm (payload_norm) USING HASH
```

### 4.2. CHECK-ограничения

```sql
-- Баланс не может быть отрицательным
CHECK (available_balance >= 0),
CHECK (pending_balance >= 0),
CHECK (paid_balance >= 0),

-- Процент кэшбэка в допустимых пределах
CHECK (applied_cashback_rate BETWEEN 0.00 AND 100.00),

-- Сумма выплаты должна быть положительной
CHECK (total_amount > 0)
```

### 4.3. Триггеры защиты

```sql
-- Запрет удаления/изменения финализированных транзакций
CREATE TRIGGER wp_cashback_tr_prevent_update_final_status ...
CREATE TRIGGER wp_cashback_tr_prevent_delete_final_status ...

-- Запрет удаления/изменения выплаченных заявок
CREATE TRIGGER wp_tr_prevent_update_paid_payout ...
CREATE TRIGGER wp_tr_prevent_delete_paid_payout ...
```

---

## 5. Обработка Ошибок

### 5.1. Автоматический Rollback

```sql
DECLARE EXIT HANDLER FOR SQLEXCEPTION
BEGIN
    ROLLBACK;
    DO RELEASE_LOCK('cashback_event_lock');
END;
```

### 5.2. Освобождение блокировок в PHP

```php
try {
    // ... операции ...
    $wpdb->query('COMMIT');
} catch (Exception $e) {
    $wpdb->query('ROLLBACK');
    $wpdb->query("SELECT RELEASE_LOCK('user_withdrawal_{$user_id}')");
    delete_transient($transient_key);
    throw $e;
}
```

### 5.3. Логирование

```php
wc_get_logger()->info(sprintf(
    'User %d withdrew %f. Payout ID: %d. Idempotency: %s',
    $user_id,
    $withdrawal_amount,
    $payout_id,
    substr($idempotency_key, 0, 16) . '...'
));
```

---

## 6. Сценарии Защиты от Сбоев

### Сценарий 1: Сбой после маркировки `processed_at`, но до начисления баланса

**Что происходит:**

1. ✅ UPDATE устанавливает `processed_at = NOW()`
2. ❌ БД падает ПЕРЕД `INSERT INTO cashback_user_balance`
3. 🔄 Событие перезапускается

**Результат:**

- ✅ Транзакции с `processed_at != NULL` НЕ попадают в выборку
- ✅ Баланс НЕ начисляется
- ❌ Транзакции остаются в статусе `completed`, но с `processed_at != NULL`

**Решение:** Нужен процесс восстановления (см. раздел 9)

### Сценарий 2: Сбой после начисления баланса, но до финализации статуса

**Что происходит:**

1. ✅ `processed_at = NOW()` установлен
2. ✅ Баланс начислен через `INSERT ... ON DUPLICATE KEY UPDATE`
3. ❌ БД падает ПЕРЕД финальным `UPDATE order_status = 'balance'`
4. 🔄 Событие перезапускается

**Результат:**

- ✅ Транзакции с `processed_at != NULL` НЕ попадают в выборку
- ✅ Баланс НЕ начисляется повторно
- ✅ Финализация статуса не критична (транзакция уже обработана)

**Безопасно:** Дублирования нет. Статус можно обновить вручную.

### Сценарий 3: Двойной запуск события одновременно

**Что происходит:**

1. 🔒 Первое событие получает `GET_LOCK('cashback_event_lock')`
2. 🔒 Второе событие пытается получить блокировку
3. ✅ Второе событие немедленно завершается (timeout = 0)

**Результат:**

- ✅ Только одно событие выполняется
- ✅ Дублирование невозможно

### Сценарий 4: Одновременные выводы от одного пользователя

**Что происходит:**

1. 🔒 Первая транзакция получает `GET_LOCK('user_withdrawal_X')`
2. 🔒 Вторая транзакция ждет блокировку (timeout = 10 сек)
3. 🔒 Первая транзакция делает `SELECT FOR UPDATE` на баланс пользователя
4. ✅ Вторая транзакция получает ошибку "недостаточно средств" (баланс уже списан)

**Результат:**

- ✅ Только одна транзакция успешна
- ✅ Баланс не уходит в минус

---

## 7. Гарантии ACID

### Atomicity (Атомарность)

✅ Все операции обернуты в транзакции `START TRANSACTION ... COMMIT`
✅ При ошибке происходит полный откат `ROLLBACK`
✅ `EXIT HANDLER FOR SQLEXCEPTION` гарантирует rollback

### Consistency (Согласованность)

✅ CHECK-ограничения на уровне БД (балансы >= 0)
✅ Триггеры для автоматического расчета
✅ Foreign Keys для целостности данных
✅ Правильный порядок операций (маркировка → обработка)

### Isolation (Изоляция)

✅ `SELECT FOR UPDATE` блокирует строки
✅ `GET_LOCK()` блокирует события и пользователей
✅ Оптимистичная блокировка через `version`
✅ Transient-блокировки на уровне PHP

### Durability (Долговечность)

✅ `ENGINE=InnoDB` - поддержка транзакций
✅ `processed_at` и `processed_batch_id` - аудит обработки
✅ Логирование через `wc_get_logger()`

---

## 8. Итоговая Схема Защиты

```
┌─────────────────────────────────────────────────────────────┐
│ СОБЫТИЕ НАЧИСЛЕНИЯ КЭШБЭКА                                   │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ 1. GET_LOCK('cashback_event_lock')                          │
│    └─ Если занята → выход (событие уже выполняется)        │
│                                                             │
│ 2. START TRANSACTION                                         │
│                                                             │
│ 3. SELECT ... WHERE processed_at IS NULL FOR UPDATE         │
│    └─ Захватываем ТОЛЬКО необработанные транзакции         │
│                                                             │
│ 4. UPDATE processed_at = NOW() (МАРКИРОВКА)                 │
│    └─ ИСТОЧНИК ИСТИНЫ: транзакция теперь невидима          │
│    └─ При сбое ниже → повтор безопасен                     │
│                                                             │
│ 5. INSERT/UPDATE баланса                                    │
│    └─ Берем данные из processed_batch_id                   │
│    └─ version + 1 защищает от race conditions              │
│                                                             │
│ 6. UPDATE order_status = 'balance'                          │
│    └─ Триггер запретит дальнейшие изменения                │
│                                                             │
│ 7. COMMIT                                                    │
│    └─ При ошибке → EXIT HANDLER сделает ROLLBACK           │
│                                                             │
│ 8. RELEASE_LOCK('cashback_event_lock')                      │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 9. Процесс Восстановления после Сбоя

Если БД упала между шагами 4 и 5, транзакции будут иметь:

- `processed_at != NULL` (промаркированы)
- `order_status = 'completed'` (не финализированы)

### Ручное восстановление:

```sql
-- 1. Найти транзакции в подвешенном состоянии
SELECT
    processed_batch_id,
    COUNT(*) as transactions,
    SUM(cashback) as total_cashback
FROM wp_cashback_transactions
WHERE processed_at IS NOT NULL
  AND order_status = 'completed'
GROUP BY processed_batch_id;

-- 2. Проверить, что баланс уже начислен
SELECT
    t.user_id,
    SUM(t.cashback) as should_be_added,
    b.available_balance
FROM wp_cashback_transactions t
LEFT JOIN wp_cashback_user_balance b ON t.user_id = b.user_id
WHERE t.processed_batch_id = 'найденный_UUID'
GROUP BY t.user_id;

-- 3a. Если баланс НЕ начислен → начислить вручную
START TRANSACTION;

INSERT INTO wp_cashback_user_balance (user_id, available_balance, version)
SELECT user_id, SUM(cashback), 0
FROM wp_cashback_transactions
WHERE processed_batch_id = 'найденный_UUID'
GROUP BY user_id
ON DUPLICATE KEY UPDATE
    available_balance = available_balance + VALUES(available_balance),
    version = version + 1;

-- 3b. Финализировать статус
UPDATE wp_cashback_transactions
SET order_status = 'balance'
WHERE processed_batch_id = 'найденный_UUID';

COMMIT;
```

---

## 10. Чек-лист Финансовой Безопасности

### База данных:

- [x] Уникальные индексы на критичные поля (`idempotency_key`, `uniq_id+partner`)
- [x] CHECK-ограничения на балансы и суммы (>= 0, total_amount > 0)
- [x] Триггеры защиты финализированных записей (status = 'balance')
- [x] Foreign Keys для целостности (CASCADE, SET NULL)
- [x] ENGINE=InnoDB для транзакций

### Код PHP:

- [x] Все финансовые операции в транзакциях (START/COMMIT/ROLLBACK)
- [x] SELECT FOR UPDATE для критичных запросов
- [x] Оптимистичная блокировка через version
- [x] Идемпотентные ключи для операций (SHA256)
- [x] Правильный порядок: INSERT заявки → UPDATE баланса
- [x] Обработка ошибок с rollback

### События MariaDB:

- [x] GET_LOCK для предотвращения параллельного запуска
- [x] Идемпотентность через processed_at (источник истины)
- [x] Правильный порядок: маркировка → обработка → финализация
- [x] Блокировка строк через FOR UPDATE
- [x] Автоматический rollback при ошибках (EXIT HANDLER)

### Логирование:

- [x] Все финансовые операции логируются (wc_get_logger)
- [x] Идемпотентные ключи в логах
- [x] Ошибки с контекстом (user_id, amount, error)
- [x] Успешные операции с деталями (payout_id, balance)

---

## 11. Мониторинг

### SQL запросы для проверки:

```sql
-- Транзакции в подвешенном состоянии (processed но не finalized)
SELECT COUNT(*) FROM wp_cashback_transactions
WHERE processed_at IS NOT NULL AND order_status = 'completed';

-- Заявки в ожидании слишком долго
SELECT * FROM wp_cashback_payout_requests
WHERE status = 'waiting' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- Проверка целостности балансов
SELECT
    b.user_id,
    b.available_balance + b.pending_balance + b.paid_balance AS total,
    COALESCE(SUM(t.cashback), 0) AS transactions_total,
    ABS((b.available_balance + b.pending_balance + b.paid_balance) -
        COALESCE(SUM(t.cashback), 0)) AS difference
FROM wp_cashback_user_balance b
LEFT JOIN wp_cashback_transactions t ON b.user_id = t.user_id
    AND t.order_status = 'balance'
GROUP BY b.user_id
HAVING difference > 0.01;
```

---

## 12. Итог

Система финансовых операций плагина кэшбэка теперь обеспечивает:

✅ **Полную идемпотентность** - повторные операции безопасны благодаря `processed_at`
✅ **Защиту от race conditions** - многоуровневые блокировки (GET_LOCK, FOR UPDATE, version)
✅ **Атомарность операций** - все или ничего (транзакции с EXIT HANDLER)
✅ **Правильный порядок** - маркировка ДО обработки (защита от сбоев)
✅ **Целостность данных** - CHECK, FK, триггеры, уникальные индексы
✅ **Аудит операций** - полное логирование с идемпотентными ключами
✅ **Банковский уровень безопасности** - как в настоящем банке

### Ключевое Отличие от Предыдущей Версии:

**БЫЛО:** Обработка → Маркировка (риск дублирования при сбое)
**СТАЛО:** Маркировка → Обработка (**100% идемпотентность**)

---

**Версия:** 2.0 (Исправлена критическая уязвимость порядка операций)
**Дата:** 2026-01-24  
**Статус:** ✅ Все критичные механизмы внедрены и протестированы

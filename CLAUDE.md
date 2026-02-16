# WordPress Cashback Plugin (MariaDB) - Документация

## Обзор плагина

**Название:** Cashback Plugin
**Назначение:** Система кешбэка для WooCommerce с партнерскими ссылками, выплатами и административным управлением

### Технические требования

- **PHP:** >= 7.4
- **WordPress:** >= 6.2
- **WooCommerce:** >= 5.0
- **База данных:** MySQL/MariaDB с поддержкой триггеров и событий

### Основные возможности

- ✅ Начисление кешбэка с гибкими ставками
- ✅ Партнерские URL с динамическими параметрами
- ✅ Защищенная система выплат с идемпотентностью
- ✅ AES-256-CBC шифрование реквизитов
- ✅ Административная панель управления
- ✅ Система поддержки с тикетами
- ✅ Мониторинг целостности данных
- ✅ Аудит-лог критических операций

### Совместимость WooCommerce

- **HPOS (High-Performance Order Storage):** полная поддержка
- **Блочная касса (Cart/Checkout Blocks):** декларирована совместимость
- Использует `FeaturesUtil::declare_compatibility()`

---

## Архитектура плагина

### Точка входа

**Файл:** [cashback-plugin.php](cashback-plugin.php)
**Класс:** `CashbackPlugin` (Singleton)

Главный класс-оркестратор, управляющий жизненным циклом плагина:

- Активация/деактивация
- Загрузка зависимостей
- Инициализация компонентов
- Генерация ключа шифрования

### Схема компонентов

```
CashbackPlugin (загрузчик)
│
├── mariadb.php (Mariadb_Plugin)
│   └── Управление БД: таблицы, триггеры, события
│
├── cashback-withdrawal.php (CashbackWithdrawal)
│   └── Вывод кешбэка с защитой от race conditions
│
├── cashback-history.php (CashbackHistory)
│   └── История транзакций пользователя
│
├── history-payout.php (HistoryPayout)
│   └── История выплат пользователя
│
├── wc-affiliate-url-params.php (WC_Affiliate_URL_Params)
│   └── Партнерские параметры URL
│
├── includes/
│   ├── class-cashback-encryption.php (Cashback_Encryption)
│   │   └── AES-256-CBC шифрование
│   └── class-cashback-user-status.php (Cashback_User_Status)
│       └── Проверка статуса и бана пользователя
│
├── admin/
│   ├── payout-methods.php (Cashback_Payout_Methods_Admin)
│   │   └── Способы выплат + главное меню
│   ├── payouts.php (Cashback_Payouts_Admin)
│   │   └── Управление заявками на выплату
│   ├── users-management.php (Cashback_Users_Management_Admin)
│   │   └── Управление профилями и банами
│   ├── bank-management.php (Cashback_Bank_Management_Admin)
│   │   └── Справочник банков
│   ├── health-check.php (Cashback_Health_Check)
│   │   └── Мониторинг целостности
│   └── traits/AdminPaginationTrait.php
│       └── Общая логика пагинации
│
└── support/
    ├── support-db.php (Cashback_Support_DB)
    │   └── Таблицы тикетов и сообщений
    ├── admin-support.php
    │   └── Административная часть поддержки
    └── user-support.php
        └── Пользовательский кабинет поддержки
```

### Жизненный цикл плагина

**Активация:**

```php
register_activation_hook() → CashbackPlugin::activate()
  1. Генерация ключа шифрования (32 байта → hex)
  2. Сохранение в wp-content/.cashback-encryption-key.php
  3. Mariadb_Plugin::activate() - создание таблиц, триггеров, событий
  4. Инициализация существующих пользователей
  5. Создание таблиц поддержки
  6. Планирование WP Cron задач
```

**Инициализация:**

```php
plugins_loaded → CashbackPlugin::init()
  1. load_dependencies() - подключение всех PHP файлов
  2. initialize_components() - создание singleton экземпляров
  3. declare_woocommerce_compatibility() - HPOS и блоки
```

**Деактивация:**

```php
register_deactivation_hook() → CashbackPlugin::deactivate()
  1. Снятие WP Cron задач
  2. Очистка кеша
```

**Удаление:**

```php
uninstall.php → cashback_plugin_uninstall()
  1. Снятие cron-событий
  2. DROP триггеров (14 шт.)
  3. DROP событий (3 шт.)
  4. DROP таблиц (11 шт.)
  5. Удаление опций и transients
  6. Удаление файла ключа шифрования
```

---

## Структура файлов

### Корневые файлы

| Файл                                                       | Назначение              | Ключевой класс            |
| ---------------------------------------------------------- | ----------------------- | ------------------------- |
| [cashback-plugin.php](cashback-plugin.php)                 | Точка входа, загрузчик  | `CashbackPlugin`          |
| [mariadb.php](mariadb.php)                                 | Управление схемой БД    | `Mariadb_Plugin`          |
| [cashback-withdrawal.php](cashback-withdrawal.php)         | Страница вывода кешбэка | `CashbackWithdrawal`      |
| [cashback-history.php](cashback-history.php)               | История транзакций      | `CashbackHistory`         |
| [history-payout.php](history-payout.php)                   | История выплат          | `HistoryPayout`           |
| [wc-affiliate-url-params.php](wc-affiliate-url-params.php) | Партнерские URL         | `WC_Affiliate_URL_Params` |
| [uninstall.php](uninstall.php)                             | Деинсталляция           | Функция                   |

### Папка admin/

**Административные интерфейсы** (требуют `manage_options`)

| Файл                                                                     | Класс                             | Страница                           |
| ------------------------------------------------------------------------ | --------------------------------- | ---------------------------------- |
| [payout-methods.php](admin/payout-methods.php)                           | `Cashback_Payout_Methods_Admin`   | Способы выплаты (СБП, МИР и др.)   |
| [payouts.php](admin/payouts.php)                                         | `Cashback_Payouts_Admin`          | Управление заявками на выплату     |
| [users-management.php](admin/users-management.php)                       | `Cashback_Users_Management_Admin` | Управление пользователями и банами |
| [bank-management.php](admin/bank-management.php)                         | `Cashback_Bank_Management_Admin`  | Справочник банков                  |
| [health-check.php](admin/health-check.php)                               | `Cashback_Health_Check`           | Мониторинг (WP Cron задача)        |
| [traits/AdminPaginationTrait.php](admin/traits/AdminPaginationTrait.php) | `AdminPaginationTrait`            | Общая логика пагинации             |

### Папка includes/

**Вспомогательные классы**

| Файл                                                                      | Класс                  | Назначение                                  |
| ------------------------------------------------------------------------- | ---------------------- | ------------------------------------------- |
| [class-cashback-encryption.php](includes/class-cashback-encryption.php)   | `Cashback_Encryption`  | Шифрование AES-256-CBC, маскирование, аудит |
| [class-cashback-user-status.php](includes/class-cashback-user-status.php) | `Cashback_User_Status` | Проверка бана и статуса пользователя        |

### Папка support/

**Модуль поддержки** (тикет-система)

| Файл                                           | Класс                 | Назначение                  |
| ---------------------------------------------- | --------------------- | --------------------------- |
| [support-db.php](support/support-db.php)       | `Cashback_Support_DB` | Таблицы тикетов и сообщений |
| [admin-support.php](support/admin-support.php) | -                     | Административный интерфейс  |
| [user-support.php](support/user-support.php)   | -                     | Пользовательский кабинет    |

### Папка development/

**Инструменты разработки**

```
development/
├── composer.json           - Зависимости (PHPStan, PHPCS)
├── phpstan.neon           - Конфигурация PHPStan
├── .phpcs.xml.dist        - Стандарты кодирования
├── config/
│   └── phpstan-bootstrap.php - Загрузчик WordPress констант
├── docs/
│   ├── README.md          - Общая документация
│   ├── CHANGELOG.md       - История изменений
│   ├── SECURITY.md        - Политика безопасности
│   └── CONTRIBUTING.md    - Руководство для контрибьюторов
└── vendor/                - Composer зависимости
    ├── phpstan/phpstan
    ├── szepeviktor/phpstan-wordpress
    ├── php-stubs/wordpress-stubs
    └── php-stubs/woocommerce-stubs
```

---

## База данных

### Таблицы

#### 1. `cashback_transactions` — Транзакции кешбэка

**Назначение:** Хранение всех покупок через партнерские ссылки

| Поле                    | Тип           | Описание                                      |
| ----------------------- | ------------- | --------------------------------------------- |
| `id`                    | BIGINT PK     | Первичный ключ                                |
| `user_id`               | BIGINT FK     | → wp_users.ID (CASCADE)                       |
| `uniq_id`               | VARCHAR(255)  | ID транзакции от партнера                     |
| `partner_name`          | VARCHAR(255)  | Название партнера                             |
| `offer_name`            | VARCHAR(255)  | Название магазина                             |
| `comission`             | DECIMAL(18,2) | Комиссия от партнера                          |
| `cashback`              | DECIMAL(18,2) | Рассчитанный кешбэк                           |
| `applied_cashback_rate` | DECIMAL(5,2)  | Примененная ставка (%)                        |
| `order_status`          | ENUM          | `waiting`, `completed`, `declined`, `balance` |
| `processed_at`          | DATETIME      | Дата обработки (начисления в баланс)          |
| `processed_batch_id`    | CHAR(36)      | UUID батча для отладки                        |
| `idempotency_key`       | VARCHAR(64)   | Защита от дубликатов                          |

**Индексы:**

- UNIQUE: `(uniq_id, partner_name)`
- UNIQUE: `idempotency_key`
- KEY: `user_id`
- KEY: `order_status`

**CHECK Constraints:**

- `applied_cashback_rate BETWEEN 0.00 AND 100.00`
- `cashback >= 0`

---

#### 2. `cashback_user_balance` — Балансы пользователей

**Назначение:** Хранение балансов в разрезе статусов

| Поле                | Тип           | Описание                    |
| ------------------- | ------------- | --------------------------- |
| `user_id`           | BIGINT PK FK  | → wp_users.ID (CASCADE)     |
| `available_balance` | DECIMAL(18,2) | Доступно для вывода         |
| `pending_balance`   | DECIMAL(18,2) | В обработке (заявка подана) |
| `paid_balance`      | DECIMAL(18,2) | Выплачено                   |
| `frozen_balance`    | DECIMAL(18,2) | Заморожено (бан)            |
| `version`           | INT UNSIGNED  | Оптимистичная блокировка    |

**CHECK Constraints:**

- Все 4 баланса >= 0

**Связи:**

- FK `user_id` → `wp_users.ID` ON DELETE CASCADE

---

#### 3. `cashback_payout_requests` — Заявки на выплату

**Назначение:** Управление запросами на вывод средств

| Поле                | Тип           | Описание                   |
| ------------------- | ------------- | -------------------------- |
| `id`                | BIGINT PK     | Первичный ключ             |
| `user_id`           | BIGINT FK     | → wp_users.ID (CASCADE)    |
| `total_amount`      | DECIMAL(18,2) | Сумма к выплате            |
| `payout_account`    | VARCHAR(255)  | Реквизиты (deprecated)     |
| `payout_full_name`  | VARCHAR(255)  | ФИО (deprecated)           |
| `status`            | ENUM          | См. конечный автомат ниже  |
| `encrypted_details` | TEXT          | AES-256-CBC реквизиты      |
| `masked_details`    | TEXT          | JSON маскированных данных  |
| `details_hash`      | CHAR(64)      | SHA-256 для антифрода      |
| `idempotency_key`   | VARCHAR(64)   | Защита от дубликатов       |
| `refunded_at`       | DATETIME      | Дата возврата (при failed) |

**Статусы (ENUM):**

- `waiting` — Ожидает обработки
- `processing` — В работе
- `paid` — Выплачен (финальный)
- `failed` — Возврат в баланс (финальный)
- `declined` — Отклонен/заморожен (финальный)
- `needs_retry` — Требует повтора

**Индексы:**

- UNIQUE: `idempotency_key`
- KEY: `(user_id, status)`

**CHECK Constraints:**

- `total_amount > 0`

---

#### 4. `cashback_user_profile` — Профили пользователей

**Назначение:** Настройки кешбэка и реквизиты пользователя

| Поле                | Тип            | Описание                                  |
| ------------------- | -------------- | ----------------------------------------- |
| `user_id`           | BIGINT PK FK   | → wp_users.ID (CASCADE)                   |
| `cashback_rate`     | DECIMAL(5,2)   | Индивидуальная ставка (0-100%)            |
| `min_payout_amount` | DECIMAL(18,2)  | Минимальная сумма вывода                  |
| `payout_method`     | VARCHAR(50) FK | → cashback_payout_methods.slug            |
| `bank_code`         | VARCHAR(9) FK  | → cashback_banks.bank_code                |
| `status`            | ENUM           | `active`, `noactive`, `banned`, `deleted` |
| `banned_at`         | DATETIME       | Дата бана                                 |
| `ban_reason`        | TEXT           | Причина бана                              |

**Связи:**

- FK `payout_method` → `cashback_payout_methods.slug` ON DELETE SET NULL
- FK `bank_code` → `cashback_banks.bank_code` ON DELETE SET NULL

**CHECK Constraints:**

- `cashback_rate BETWEEN 0.00 AND 100.00`

---

#### 5. `cashback_payout_methods` — Способы выплат

**Справочник:** СБП, МИР, Сбербанк и т.д.

| Поле          | Тип                | Описание       |
| ------------- | ------------------ | -------------- |
| `id`          | BIGINT PK          | Первичный ключ |
| `name`        | VARCHAR(255)       | Название       |
| `slug`        | VARCHAR(50) UNIQUE | Идентификатор  |
| `description` | TEXT               | Описание       |
| `is_active`   | TINYINT(1)         | Активность     |

---

#### 6. `cashback_banks` — Справочник банков

| Поле         | Тип               | Описание         |
| ------------ | ----------------- | ---------------- |
| `id`         | BIGINT PK         | Первичный ключ   |
| `bank_code`  | VARCHAR(9) UNIQUE | БИК банка        |
| `name`       | VARCHAR(255)      | Полное название  |
| `short_name` | VARCHAR(100)      | Краткое название |
| `is_active`  | TINYINT(1)        | Активность       |

---

#### 7. `cashback_webhooks` — Сырые вебхуки

**Назначение:** Логирование всех входящих webhook'ов

| Поле           | Тип       | Описание                        |
| -------------- | --------- | ------------------------------- |
| `id`           | BIGINT PK | Первичный ключ                  |
| `payload`      | LONGTEXT  | JSON тело запроса               |
| `payload_norm` | CHAR(64)  | SHA-256(payload) - дедупликация |
| `received_at`  | DATETIME  | Дата получения                  |

**Индексы:**

- UNIQUE: `payload_norm`

---

#### 8. `cashback_audit_log` — Аудит-лог

**Назначение:** Логирование критических операций

| Поле          | Тип          | Описание          |
| ------------- | ------------ | ----------------- |
| `id`          | BIGINT PK    | Первичный ключ    |
| `action`      | VARCHAR(50)  | Тип действия      |
| `entity_type` | VARCHAR(50)  | Тип сущности      |
| `entity_id`   | VARCHAR(255) | ID сущности       |
| `actor_id`    | BIGINT       | ID администратора |
| `ip_address`  | VARCHAR(45)  | IP адрес          |
| `user_agent`  | TEXT         | User-Agent        |
| `created_at`  | DATETIME     | Дата создания     |

**Типы действий:**

- `payout_details_decrypted` — Расшифровка реквизитов
- `payout_declined_on_ban` — Заявка отклонена при бане
- `user_banned` — Пользователь забанен
- `user_unbanned` — Пользователь разбанен

**Индексы:**

- KEY: `(action, actor_id)`
- KEY: `entity_type`

---

#### 9. `cashback_unregistered_transactions` — Незарегистрированные транзакции

**Назначение:** Вебхуки от пользователей, которых нет в системе

Структура аналогична `cashback_transactions`, но без FK на `wp_users`.

---

#### 10. `cashback_support_tickets` — Тикеты поддержки

| Поле         | Тип          | Описание                         |
| ------------ | ------------ | -------------------------------- |
| `id`         | BIGINT PK    | Первичный ключ                   |
| `user_id`    | BIGINT FK    | → wp_users.ID (CASCADE)          |
| `subject`    | VARCHAR(255) | Тема                             |
| `priority`   | ENUM         | `urgent`, `normal`, `not_urgent` |
| `status`     | ENUM         | `open`, `answered`, `closed`     |
| `created_at` | DATETIME     | Дата создания                    |
| `updated_at` | DATETIME     | Последнее обновление             |
| `closed_at`  | DATETIME     | Дата закрытия                    |

---

#### 11. `cashback_support_messages` — Сообщения в тикетах

| Поле         | Тип        | Описание                                |
| ------------ | ---------- | --------------------------------------- |
| `id`         | BIGINT PK  | Первичный ключ                          |
| `ticket_id`  | BIGINT FK  | → cashback_support_tickets.id (CASCADE) |
| `user_id`    | BIGINT FK  | → wp_users.ID (CASCADE)                 |
| `message`    | TEXT       | Текст сообщения                         |
| `is_admin`   | TINYINT(1) | От админа?                              |
| `is_read`    | TINYINT(1) | Прочитано?                              |
| `created_at` | DATETIME   | Дата создания                           |

---

### Триггеры

**Всего триггеров:** 14

#### Группа 1: Автоматический расчет кешбэка

| Триггер                                         | Событие       | Таблица                            | Логика                                                                                            |
| ----------------------------------------------- | ------------- | ---------------------------------- | ------------------------------------------------------------------------------------------------- |
| `calculate_cashback_before_insert`              | BEFORE INSERT | cashback_transactions              | Читает `cashback_rate` из `user_profile`, вычисляет `cashback = ROUND(comission * rate / 100, 2)` |
| `calculate_cashback_before_update`              | BEFORE UPDATE | cashback_transactions              | Пересчет при изменении `comission`                                                                |
| `calculate_cashback_before_insert_unregistered` | BEFORE INSERT | cashback_unregistered_transactions | Фиксированная ставка 60%                                                                          |
| `calculate_cashback_before_update_unregistered` | BEFORE UPDATE | cashback_unregistered_transactions | Пересчет при изменении `comission`                                                                |

#### Группа 2: Защита от удаления/изменения финальных записей

| Триггер                                   | Событие       | Таблица                  | Логика                                         |
| ----------------------------------------- | ------------- | ------------------------ | ---------------------------------------------- |
| `cashback_tr_prevent_delete_final_status` | BEFORE DELETE | cashback_transactions    | SIGNAL: запрет удаления со статусом `balance`  |
| `cashback_tr_prevent_update_final_status` | BEFORE UPDATE | cashback_transactions    | SIGNAL: запрет изменения со статусом `balance` |
| `tr_prevent_delete_paid_payout`           | BEFORE DELETE | cashback_payout_requests | SIGNAL: запрет удаления `paid`                 |
| `tr_prevent_update_paid_payout`           | BEFORE UPDATE | cashback_payout_requests | SIGNAL: запрет изменения `paid`                |
| `tr_prevent_delete_failed_payout`         | BEFORE DELETE | cashback_payout_requests | SIGNAL: запрет удаления `failed`               |
| `tr_prevent_update_failed_payout`         | BEFORE UPDATE | cashback_payout_requests | SIGNAL: запрет изменения `failed`              |

#### Группа 3: Управление баном пользователя

| Триггер                           | Событие       | Таблица               | Логика                                                                    |
| --------------------------------- | ------------- | --------------------- | ------------------------------------------------------------------------- |
| `tr_banned_user_update_banned_at` | BEFORE UPDATE | cashback_user_profile | При смене статуса на `banned`: устанавливает `banned_at = NOW()`          |
| `tr_freeze_balance_on_ban`        | AFTER UPDATE  | cashback_user_profile | При бане: `frozen += available + pending`, `available = 0`, `pending = 0` |
| `tr_clear_ban_on_unban`           | BEFORE UPDATE | cashback_user_profile | При разбане: сбрасывает `banned_at = NULL`, `ban_reason = NULL`           |
| `tr_unfreeze_balance_on_unban`    | AFTER UPDATE  | cashback_user_profile | При разбане: `available += frozen`, `frozen = 0`                          |

---

### MySQL события (Cron на уровне БД)

**Всего событий:** 3 (выполняются ежедневно)

| Событие                                     | Расписание        | Назначение                                                                                                                                                                                                                  |
| ------------------------------------------- | ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `cashback_ev_confirmed_cashback`            | Ежедневно в 03:00 | Начисляет кешбэк в баланс: берет транзакции со статусом `completed` возрастом >= 1 день, переводит в `balance`, начисляет `available_balance`. Защита: `GET_LOCK('cashback_event_lock', 0)` + `processed_batch_id = UUID()` |
| `cashback_ev_cleanup_cashback_webhooks_old` | Ежедневно в 04:00 | Удаляет вебхуки старше 6 месяцев                                                                                                                                                                                            |
| `cashback_ev_mark_inactive_profiles`        | Ежедневно в 05:00 | Переводит профили в статус `noactive` если `last_active_at < NOW() - INTERVAL 6 MONTH`                                                                                                                                      |

---

## Основные компоненты

### CashbackPlugin — Загрузчик

**Файл:** [cashback-plugin.php](cashback-plugin.php)
**Паттерн:** Singleton

#### Ключевые методы

```php
public static function activate()
```

- Генерирует ключ шифрования `CB_ENCRYPTION_KEY` (32 байта → 64 hex символа)
- Сохраняет в `wp-content/.cashback-encryption-key.php`
- Вызывает `Mariadb_Plugin::activate()` для создания БД
- Создает таблицы поддержки
- Планирует WP Cron задачи

```php
private function load_dependencies()
```

- Подключает все PHP файлы через `require_once`
- Порядок: вспомогательные классы → основные компоненты → админка → поддержка

```php
private function initialize_components()
```

- Создает singleton экземпляры всех классов
- Порядок важен: сначала зависимости, потом зависящие компоненты

#### Хуки

- `plugins_loaded` → `init()` — загрузка компонентов
- `init` → `load_textdomain()` — переводы
- `before_woocommerce_init` → `declare_woocommerce_compatibility()` — HPOS

---

### Mariadb_Plugin — Управление БД

**Файл:** [mariadb.php](mariadb.php)
**Паттерн:** Singleton

#### Ключевые методы

```php
public static function activate()
```

Последовательность:

1. `validate_table_prefix()` — regex проверка префикса
2. `create_tables()` — 9 таблиц через `dbDelta()`
3. `create_audit_log_table()` — таблица аудита
4. `create_triggers()` — 14 триггеров
5. `create_events()` — 3 MySQL события
6. `initialize_existing_users()` — инициализация текущих пользователей
7. `migrate_payout_method_fk()` — добавление FK если отсутствует
8. `add_performance_indexes()` — индексы для пагинации
9. `add_encryption_columns()` — колонки для шифрования
10. `migrate_encrypt_existing_data()` — шифрование старых данных

```php
public function add_user_to_cashback_tables(int $user_id)
```

Вызывается хуком `user_register`:

- `add_user_to_profile()` — `INSERT IGNORE` в `cashback_user_profile` (status=active, rate=60%)
- `add_user_to_balance()` — `INSERT IGNORE` в `cashback_user_balance` (все балансы = 0)

#### Миграции

```php
private function migrate_encrypt_existing_data()
```

- Обрабатывает по 100 записей за раз
- Шифрует `payout_account`, `payout_full_name`, `bank_code`
- Генерирует `masked_details` JSON
- Вычисляет `details_hash` SHA-256

---

### CashbackWithdrawal — Вывод кешбэка

**Файл:** [cashback-withdrawal.php](cashback-withdrawal.php)
**Паттерн:** Singleton

#### Endpoint

- **URL:** `/my-account/cashback-withdrawal/`
- **Позиция в меню:** После "Заказы"

#### AJAX обработчики

| Action                                | Nonce                       | Назначение                 |
| ------------------------------------- | --------------------------- | -------------------------- |
| `wp_ajax_process_cashback_withdrawal` | `cashback_withdrawal_nonce` | Создание заявки на выплату |
| `wp_ajax_get_user_balance`            | `cashback_withdrawal_nonce` | Получение текущего баланса |
| `wp_ajax_save_payout_settings`        | `cashback_withdrawal_nonce` | Сохранение реквизитов      |
| `wp_ajax_search_banks`                | `cashback_withdrawal_nonce` | Автокомплит банков         |

#### Защита от race conditions

```php
public function process_cashback_withdrawal()
```

**Механизмы:**

1. **MySQL Named Lock:**

```php
$lock_name = 'user_withdrawal_' . $user_id;
$wpdb->query($wpdb->prepare("SELECT GET_LOCK(%s, 10)", $lock_name));
```

2. **Транзакция с SELECT FOR UPDATE:**

```php
$wpdb->query('START TRANSACTION');
$balance = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$balance_table} WHERE user_id = %d FOR UPDATE",
    $user_id
));
```

3. **Идемпотентность:**

```php
$idempotency_key = hash('sha256',
    $user_id . '_' .
    microtime(true) . '_' .
    wp_create_nonce('cashback_withdrawal_' . $user_id) . '_' .
    bin2hex(random_bytes(16))
);
// UNIQUE KEY на idempotency_key предотвращает дубликаты
```

4. **Оптимистичная блокировка:**

```php
$updated = $wpdb->query($wpdb->prepare(
    "UPDATE {$balance_table}
     SET available_balance = available_balance - %f,
         pending_balance = pending_balance + %f,
         version = version + 1
     WHERE user_id = %d AND version = %d",
    $amount, $amount, $user_id, $current_version
));
if ($updated === 0) {
    throw new Exception('Version conflict');
}
```

5. **Освобождение блокировки:**

```php
$wpdb->query($wpdb->prepare("DO RELEASE_LOCK(%s)", $lock_name));
```

#### Шифрование реквизитов

```php
$encrypted_data = Cashback_Encryption::encrypt_details([
    'account' => $payout_account,
    'full_name' => $payout_full_name,
    'bank' => $bank_code
]);
// Возвращает:
// - encrypted_details (base64)
// - masked_details (JSON: {account: "+7903***4567", full_name: "И**** П****"})
// - details_hash (SHA-256)
```

---

### CashbackHistory — История транзакций

**Файл:** [cashback-history.php](cashback-history.php)

#### Endpoint

- **URL:** `/my-account/cashback-history/`
- **Константы:**
  - `PER_PAGE = 10`
  - `MAX_ALLOWED_PAGES = 1000` (защита от DoS)

#### AJAX пагинация

```php
add_action('wp_ajax_load_page_transactions', [$this, 'handle_load_page_transactions']);
```

**Безопасность:**

- `check_ajax_referer('load_page_transactions_nonce', 'nonce', false)`
- Данные только своего пользователя: `WHERE user_id = %d`

#### Статусы транзакций

| Статус      | Отображение        | Описание                                  |
| ----------- | ------------------ | ----------------------------------------- |
| `waiting`   | В ожидании         | Покупка зафиксирована, ждет подтверждения |
| `completed` | Подтвержден        | Подтверждено партнером, ждет начисления   |
| `declined`  | Отклонен           | Отклонено партнером                       |
| `balance`   | Зачислен на баланс | Начислено в `available_balance`           |

---

### HistoryPayout — История выплат

**Файл:** [history-payout.php](history-payout.php)

#### Endpoint

- **URL:** `/my-account/history-payout/`

#### Особенности

- Забаненным пользователям показывает историю в режиме read-only
- Использует `masked_details` для отображения реквизитов
- Кеширует названия способов выплат в свойстве класса

#### Статусы выплат

| Статус        | Отображение       | Описание                            |
| ------------- | ----------------- | ----------------------------------- |
| `waiting`     | Ожидает обработки | Заявка создана, ждет администратора |
| `processing`  | В обработке       | Администратор обрабатывает          |
| `paid`        | Выплачен          | Средства выплачены (финальный)      |
| `failed`      | Возврат в баланс  | Ошибка выплаты, средства возвращены |
| `declined`    | Заморожена        | Отклонено, средства заморожены      |
| `needs_retry` | Требует повтора   | Временная ошибка                    |

---

### WC_Affiliate_URL_Params — Партнерские параметры

**Файл:** [wc-affiliate-url-params.php](wc-affiliate-url-params.php)

#### Назначение

Добавляет до 3 кастомных параметров к URL внешних WooCommerce товаров.

#### Особенности

**Динамическая подстановка:**

```php
if ($value === 'user') {
    $user_id = get_current_user_id();
    $value = $user_id > 0 ? $user_id : 'USER_PLACEHOLDER_' . $param_num;
}
```

**Кеширование:**

```php
$cache_key = 'affiliate_params_' . $product_id;
wp_cache_set($cache_key, $params, 'wc_affiliate_url_params', 3600);
```

#### Хранение

- **Meta ключи:** `_affiliate_param_1_key`, `_affiliate_param_1_value`, ...
- **Проверка типа:** Поля показываются только для External/Affiliate продуктов

#### Безопасность

```php
// При сохранении:
if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(...)) return;
if (!current_user_can('edit_product', $post_id)) return;

// При выводе:
$url = add_query_arg($params, esc_url($product_url));
```

---

### Cashback_Encryption — Шифрование

**Файл:** [includes/class-cashback-encryption.php](includes/class-cashback-encryption.php)
**Паттерн:** Статический класс

#### Алгоритм

**AES-256-CBC:**

- Ключ: `CB_ENCRYPTION_KEY` (64 hex = 32 байта)
- IV: Уникальный 16-байтный вектор для каждой записи
- Формат хранения: `base64(IV || ciphertext)`

#### Ключевые методы

```php
public static function encrypt(string $plaintext): string
```

- Генерирует случайный IV: `openssl_random_pseudo_bytes(16)`
- Шифрует: `openssl_encrypt($plaintext, 'aes-256-cbc', $key, 0, $iv)`
- Возвращает: `base64_encode($iv . $ciphertext)`

```php
public static function decrypt(string $encrypted): string
```

- Декодирует: `base64_decode($encrypted)`
- Извлекает IV: первые 16 байт
- Расшифровывает: `openssl_decrypt($ciphertext, 'aes-256-cbc', $key, 0, $iv)`

```php
public static function encrypt_details(array $details): array
```

Возвращает:

```php
[
    'encrypted_details' => base64(...),
    'masked_details' => '{"account":"+7903***4567","full_name":"И**** П****","bank":"044525225"}',
    'details_hash' => sha256(json_encode($details, JSON_UNESCAPED_UNICODE))
]
```

#### Маскирование

```php
public static function mask_account(string $account): string
```

- Телефон: `+79031234567` → `+7903***4567`
- Карта: `1234567812345678` → `**** **** **** 5678`
- Общий случай: первые 4 + `***` + последние 4

```php
public static function mask_name(string $name): string
```

- `Иванов Петр Сидорович` → `И**** П**** С****`
- `John Doe` → `J**** D****`

#### Аудит-лог

```php
public static function write_audit_log(
    string $action,
    string $entity_type,
    string $entity_id,
    ?int $actor_id = null
): bool
```

Записывает в `cashback_audit_log`:

- `ip_address`: через `get_client_ip()` (поддержка прокси)
- `user_agent`: `$_SERVER['HTTP_USER_AGENT']`
- `created_at`: `current_time('mysql')`

---

### Cashback_User_Status — Статус пользователя

**Файл:** [includes/class-cashback-user-status.php](includes/class-cashback-user-status.php)
**Паттерн:** Статический класс

#### Методы

```php
public static function is_user_banned(int $user_id): bool
```

- Запрос к `cashback_user_profile`
- Проверяет: `status = 'banned'`

```php
public static function get_ban_info(int $user_id): ?array
```

Возвращает:

```php
[
    'banned_at' => '2024-01-15 10:30:00',
    'ban_reason' => 'Нарушение правил'
]
```

```php
public static function get_banned_message(?array $ban_info): string
```

Генерирует пользовательское сообщение:

```
Ваш аккаунт заблокирован.
Дата блокировки: 15.01.2024 10:30
Причина: Нарушение правил
```

---

## Административные компоненты

### Cashback_Payout_Methods_Admin — Способы выплат

**Файл:** [admin/payout-methods.php](admin/payout-methods.php)

#### Меню

**Создает главное меню:**

```php
add_menu_page(
    'Кэшбэк',
    'Кэшбэк',
    'manage_options',
    'cashback-overview',
    null,
    'dashicons-money-alt',
    30
);
```

**Подменю:**

```php
add_submenu_page(
    'cashback-overview',
    'Способы выплаты',
    'Способы выплаты',
    'manage_options',
    'cashback-payout-methods',
    [$this, 'render_page']
);
```

#### AJAX операции

- `wp_ajax_update_payout_method` — inline редактирование
- `wp_ajax_add_payout_method` — добавление нового метода

#### Поля

- `name` — название (например, "СБП")
- `slug` — идентификатор (например, "sbp")
- `description` — описание для пользователей
- `is_active` — активность (1/0)

---

### Cashback_Payouts_Admin — Управление выплатами

**Файл:** [admin/payouts.php](admin/payouts.php)
**Trait:** `AdminPaginationTrait`

#### Меню

```php
add_submenu_page(
    'cashback-overview',
    'Выплаты',
    'Выплаты',
    'manage_options',
    'cashback-payouts',
    [$this, 'render_page']
);
```

#### AJAX операции

| Action                           | Назначение                                     |
| -------------------------------- | ---------------------------------------------- |
| `wp_ajax_update_payout_request`  | Изменение статуса выплаты + обновление баланса |
| `wp_ajax_get_payout_request`     | Получение данных заявки для модального окна    |
| `wp_ajax_decrypt_payout_details` | Расшифровка реквизитов (только `processing`)   |

#### Конечный автомат статусов

```
Возможные переходы:

waiting ──┬──> processing
          ├──> paid
          ├──> failed
          ├──> declined
          └──> needs_retry

processing ──┬──> paid
             ├──> failed
             ├──> declined
             └──> needs_retry

needs_retry ──┬──> processing
              ├──> paid
              ├──> failed
              └──> declined

paid ───> (финальный, изменения запрещены триггером)
failed ───> (финальный, изменения запрещены триггером)
declined ───> (финальный)
```

#### Логика обновления баланса

```php
private function update_user_balance_on_payout(
    int $user_id,
    float $amount,
    string $new_status,
    string $old_status
): bool
```

**При смене статуса на `paid`:**

```sql
UPDATE cashback_user_balance
SET pending_balance = pending_balance - amount,
    paid_balance = paid_balance + amount,
    version = version + 1
WHERE user_id = ? AND version = ?
```

**При смене на `failed`:**

```sql
UPDATE cashback_user_balance
SET pending_balance = pending_balance - amount,
    available_balance = available_balance + amount,
    version = version + 1
WHERE user_id = ? AND version = ?

UPDATE cashback_payout_requests
SET refunded_at = NOW()
WHERE id = ?
```

**При смене на `declined`:**

```sql
UPDATE cashback_user_balance
SET pending_balance = pending_balance - amount,
    frozen_balance = frozen_balance + amount,
    version = version + 1
WHERE user_id = ? AND version = ?
```

#### Расшифровка реквизитов

```php
public function handle_decrypt_payout_details()
```

**Требования:**

- Статус заявки: `processing`
- Права: `manage_options` ИЛИ `manage_woocommerce`
- Аудит: Записывает `payout_details_decrypted` в `cashback_audit_log`

**Возвращает:**

```json
{
  "success": true,
  "data": {
    "account": "+79031234567",
    "full_name": "Иванов Петр",
    "bank": "044525225"
  }
}
```

#### Фильтры

- По статусу: dropdown
- По дате: `date_from` и `date_to` (валидация через regex)
- По пользователю: search по `user_login`, `user_email`

---

### Cashback_Users_Management_Admin — Управление пользователями

**Файл:** [admin/users-management.php](admin/users-management.php)
**Trait:** `AdminPaginationTrait`

#### Меню

```php
add_submenu_page(
    'cashback-overview',
    'Пользователи',
    'Пользователи',
    'manage_options',
    'cashback-users',
    [$this, 'render_page']
);
```

#### AJAX операции

- `wp_ajax_update_user_profile` — обновление профиля
- `wp_ajax_get_user_profile` — получение данных профиля

#### Поля профиля

| Поле                | Тип           | Валидация                                 |
| ------------------- | ------------- | ----------------------------------------- |
| `cashback_rate`     | DECIMAL(5,2)  | 0-100, проверка через `bccomp()`          |
| `min_payout_amount` | DECIMAL(18,2) | >= 0                                      |
| `status`            | ENUM          | `active`, `noactive`, `banned`, `deleted` |
| `ban_reason`        | TEXT          | Обязательно при бане                      |

#### Логика бана пользователя

```php
private function handle_user_ban(
    int $user_id,
    string $ban_reason,
    wpdb $wpdb
): bool
```

**Последовательность:**

1. **Транзакция начинается**

2. **Блокировка активных заявок:**

```sql
SELECT id, total_amount
FROM cashback_payout_requests
WHERE user_id = ? AND status IN ('waiting', 'processing', 'needs_retry')
FOR UPDATE
```

3. **Отклонение заявок:**

```sql
UPDATE cashback_payout_requests
SET status = 'declined',
    fail_reason = '(Аккаунт забанен)'
WHERE id IN (...)
```

4. **Заморозка баланса (триггер `tr_freeze_balance_on_ban`):**

```sql
-- Автоматически:
UPDATE cashback_user_balance
SET frozen_balance = frozen_balance + available_balance + pending_balance,
    available_balance = 0,
    pending_balance = 0
WHERE user_id = ?
```

5. **Аудит-лог:**

```php
Cashback_Encryption::write_audit_log('payout_declined_on_ban', 'payout_request', $request_id);
Cashback_Encryption::write_audit_log('user_banned', 'user_profile', $user_id);
```

6. **Email уведомление:**

```php
wp_mail(
    $user_email,
    'Ваш аккаунт заблокирован',
    "Причина: {$ban_reason}\nДата: {$banned_at}"
);
```

7. **Коммит транзакции**

#### Логика разбана

```php
private function handle_user_unban(int $user_id, wpdb $wpdb): bool
```

**Последовательность:**

1. **Обновление причин отклонения:**

```sql
UPDATE cashback_payout_requests
SET fail_reason = REPLACE(fail_reason, '(Аккаунт забанен)', '(Аккаунт был забанен)')
WHERE user_id = ?
  AND status = 'declined'
  AND (fail_reason LIKE '%(Аккаунт забанен)%' OR fail_reason LIKE '%Account banned%')
```

2. **Размораживание баланса (триггер `tr_unfreeze_balance_on_unban`):**

```sql
-- Автоматически:
UPDATE cashback_user_balance
SET available_balance = available_balance + frozen_balance,
    frozen_balance = 0
WHERE user_id = ?
```

3. **Аудит-лог:**

```php
Cashback_Encryption::write_audit_log('user_unbanned', 'user_profile', $user_id);
```

---

### Cashback_Bank_Management_Admin — Управление банками

**Файл:** [admin/bank-management.php](admin/bank-management.php)

#### Меню

```php
add_submenu_page(
    'cashback-overview',
    'Банки',
    'Банки',
    'manage_options',
    'cashback-banks',
    [$this, 'render_page']
);
```

#### AJAX операции

- `wp_ajax_update_bank` — обновление банка
- `wp_ajax_add_bank` — добавление с проверкой уникальности

#### Проверка уникальности при добавлении

```php
$existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$banks_table}
     WHERE bank_code = %s OR name = %s OR short_name = %s",
    $bank_code, $name, $short_name
));
if ($existing) {
    wp_send_json_error(['message' => 'Банк с таким БИК или названием уже существует']);
}
```

#### Фильтры

- Поиск: `LIKE` по `name`, `short_name`, `bank_code`
- По активности: `is_active = 1/0`

---

### Cashback_Health_Check — Мониторинг

**Файл:** [admin/health-check.php](admin/health-check.php)
**Паттерн:** Статический класс

#### WP Cron

```php
add_action('cashback_health_check_cron', [self::class, 'run_health_checks']);
```

**Расписание:** Ежедневно (планируется в `CashbackPlugin::activate()`)

#### Проверки

| Проверка                          | Серьезность | Что проверяет                                                                                     |
| --------------------------------- | ----------- | ------------------------------------------------------------------------------------------------- |
| `check_negative_balances()`       | CRITICAL    | Отрицательные значения в `available_balance`, `pending_balance`, `paid_balance`, `frozen_balance` |
| `check_stale_payouts()`           | WARNING     | Заявки в статусе `waiting` старше 30 дней                                                         |
| `check_balance_payout_mismatch()` | WARNING     | `pending_balance > 0` без соответствующих активных заявок                                         |
| `check_orphaned_users()`          | WARNING     | Пользователи WordPress без профиля или баланса                                                    |

#### Отчет

**Email администратору:**

- Тема: `[CRITICAL]` или `[WARNING]` + название сайта
- Получатель: `get_option('admin_email')`
- Формат: Plain text с детальной информацией по каждой проблеме

**Дублирование в error_log:**

```php
error_log('[Cashback Health Check] ' . $level . ': ' . $message);
```

---

## Рабочие процессы

### 1. Регистрация пользователя

```
Пользователь заполняет форму регистрации WordPress
    ↓
WordPress создает запись в wp_users
    ↓
Срабатывает хук: do_action('user_register', $user_id)
    ↓
Mariadb_Plugin::add_user_to_cashback_tables($user_id)
    ↓
INSERT IGNORE INTO cashback_user_profile:
    - user_id = $user_id
    - cashback_rate = 60.00
    - status = 'active'
    - min_payout_amount = 500.00
    ↓
INSERT IGNORE INTO cashback_user_balance:
    - user_id = $user_id
    - available_balance = 0
    - pending_balance = 0
    - paid_balance = 0
    - frozen_balance = 0
    - version = 0
    ↓
Готово: пользователь может получать кешбэк
```

---

### 2. Обработка партнерской ссылки

```
Администратор создает внешний WooCommerce товар
    ↓
В метабоксе Product Data заполняет партнерские параметры:
    - Параметр 1: subid1 = user
    - Параметр 2: source = woocommerce
    ↓
Сохраняется в post meta:
    - _affiliate_param_1_key = 'subid1'
    - _affiliate_param_1_value = 'user'
    - _affiliate_param_2_key = 'source'
    - _affiliate_param_2_value = 'woocommerce'
    ↓
Пользователь (ID=42) заходит на страницу товара
    ↓
WC_Affiliate_URL_Params::modify_external_url($url, $product)
    ↓
Чтение параметров из post meta (с кешированием)
    ↓
Замена динамических значений:
    - 'user' → get_current_user_id() → 42
    ↓
Формирование URL:
    https://partner.com/offer?subid1=42&source=woocommerce
    ↓
Пользователь кликает → переход на сайт партнера
    ↓
Партнер фиксирует клик с subid1=42
```

---

### 3. Начисление кешбэка

```
Пользователь совершает покупку на сайте партнера
    ↓
Партнер отправляет webhook на сайт
    ↓
Webhook обработчик (не включен в текущую документацию):
    1. Записывает в cashback_webhooks (дедупликация по payload_norm)
    2. Парсит данные вебхука
    3. Создает запись в cashback_transactions
    ↓
INSERT INTO cashback_transactions:
    - user_id = 42
    - uniq_id = 'ABC123' (от партнера)
    - partner_name = 'Admitad'
    - offer_name = 'Магазин Электроники'
    - comission = 1500.00
    - order_status = 'waiting'
    - idempotency_key = hash(...)
    ↓
Триггер calculate_cashback_before_insert:
    1. Читает cashback_rate из cashback_user_profile (60%)
    2. Вычисляет: cashback = ROUND(1500 * 60 / 100, 2) = 900.00
    3. Записывает applied_cashback_rate = 60.00
    ↓
Запись сохранена:
    - cashback = 900.00
    - order_status = 'waiting'
    ↓
Партнер подтверждает покупку (через webhook или API)
    ↓
UPDATE cashback_transactions SET order_status = 'completed'
    ↓
Ежедневно в 03:00 запускается MySQL Event:
    cashback_ev_confirmed_cashback
    ↓
Event:
    1. GET_LOCK('cashback_event_lock', 0) — защита от параллельного запуска
    2. Выбирает транзакции:
       - order_status = 'completed'
       - created_at < NOW() - INTERVAL 1 DAY
       - processed_at IS NULL
    3. UPDATE cashback_transactions:
       - processed_at = NOW()
       - processed_batch_id = UUID()
    4. Начисляет в баланс:
       INSERT INTO cashback_user_balance (user_id, available_balance)
       VALUES (42, 900.00)
       ON DUPLICATE KEY UPDATE
       available_balance = available_balance + 900.00
    5. UPDATE cashback_transactions SET order_status = 'balance'
    6. RELEASE_LOCK
    ↓
Триггер cashback_tr_prevent_update_final_status:
    - Блокирует дальнейшие изменения записей со статусом 'balance'
    ↓
Готово: кешбэк начислен на баланс пользователя
```

---

### 4. Запрос выплаты

```
Пользователь (ID=42) переходит в /my-account/cashback-withdrawal/
    ↓
CashbackWithdrawal::render_withdrawal_page()
    - Проверка: Cashback_User_Status::is_user_banned(42) → false
    - Показывает форму с текущим балансом
    ↓
Пользователь заполняет:
    - Сумма: 1500.00
    - Способ выплаты: sbp (СБП)
    - Счет: +79031234567
    - ФИО: Иванов Петр
    - Банк: 044525225 (Сбербанк)
    ↓
Нажимает "Отправить заявку"
    ↓
AJAX: wp_ajax_process_cashback_withdrawal
    ↓
CashbackWithdrawal::process_cashback_withdrawal()
    ↓
1. Проверка безопасности:
    - wp_verify_nonce('cashback_withdrawal_nonce')
    - is_user_logged_in()
    - Cashback_User_Status::is_user_banned(42) → false
    ↓
2. Валидация суммы:
    - bccomp(1500, 0, 2) > 0 → OK
    - Проверка min_payout_amount
    ↓
3. Получение блокировки:
    SELECT GET_LOCK('user_withdrawal_42', 10) → 1
    ↓
4. Начало транзакции:
    START TRANSACTION
    ↓
5. Блокировка строки баланса:
    SELECT * FROM cashback_user_balance
    WHERE user_id = 42
    FOR UPDATE
    ↓
    Результат:
    - available_balance = 2500.00
    - pending_balance = 0
    - version = 5
    ↓
6. Проверка достаточности средств:
    bccomp(2500, 1500, 2) >= 0 → OK
    ↓
7. Шифрование реквизитов:
    Cashback_Encryption::encrypt_details([
        'account' => '+79031234567',
        'full_name' => 'Иванов Петр',
        'bank' => '044525225'
    ])
    ↓
    Возвращает:
    - encrypted_details: "base64(...)"
    - masked_details: '{"account":"+7903***4567","full_name":"И**** П****","bank":"044525225"}'
    - details_hash: "sha256(...)"
    ↓
8. Генерация ключа идемпотентности:
    idempotency_key = hash('sha256',
        '42_1734523234.5678_' .
        wp_create_nonce('cashback_withdrawal_42') . '_' .
        bin2hex(random_bytes(16))
    )
    ↓
9. Создание заявки:
    INSERT INTO cashback_payout_requests:
        - user_id = 42
        - total_amount = 1500.00
        - encrypted_details = "base64(...)"
        - masked_details = '{"account":"+7903***4567",...}'
        - details_hash = "sha256(...)"
        - status = 'waiting'
        - idempotency_key = "..."
        - created_at = NOW()
    ↓
    UNIQUE KEY на idempotency_key предотвращает дубликаты
    ↓
10. Обновление баланса (оптимистичная блокировка):
    UPDATE cashback_user_balance
    SET available_balance = available_balance - 1500,
        pending_balance = pending_balance + 1500,
        version = version + 1
    WHERE user_id = 42 AND version = 5
    ↓
    Проверка: affected_rows = 1 → OK
    ↓
11. Коммит транзакции:
    COMMIT
    ↓
12. Освобождение блокировки:
    DO RELEASE_LOCK('user_withdrawal_42')
    ↓
13. Ответ пользователю:
    wp_send_json_success([
        'message' => 'Заявка успешно создана',
        'new_balance' => 1000.00
    ])
    ↓
Готово: Заявка в статусе 'waiting', деньги переведены в pending_balance
```

---

### 5. Обработка выплаты администратором

```
Администратор заходит в WordPress Admin → Кэшбэк → Выплаты
    ↓
Cashback_Payouts_Admin::render_page()
    - Показывает таблицу заявок с фильтрами
    ↓
Администратор находит заявку пользователя ID=42 (status='waiting')
    ↓
Кликает "Взять в работу"
    ↓
AJAX: wp_ajax_update_payout_request
    ↓
Cashback_Payouts_Admin::handle_update_payout_request()
    ↓
1. Проверка прав:
    - current_user_can('manage_options') → true
    ↓
2. Получение данных:
    - payout_id = 123
    - new_status = 'processing'
    ↓
3. Начало транзакции:
    START TRANSACTION
    ↓
4. Блокировка заявки:
    SELECT * FROM cashback_payout_requests
    WHERE id = 123
    FOR UPDATE
    ↓
    Результат:
    - user_id = 42
    - total_amount = 1500.00
    - status = 'waiting'
    ↓
5. Проверка допустимости перехода:
    allowed_transitions = [
        'waiting' => ['processing', 'paid', 'failed', 'declined', 'needs_retry']
    ]
    ↓
    'waiting' → 'processing' → OK
    ↓
6. Обновление статуса:
    UPDATE cashback_payout_requests
    SET status = 'processing',
        updated_at = NOW()
    WHERE id = 123
    ↓
7. Коммит:
    COMMIT
    ↓
8. Администратор видит расшифрованные реквизиты:
    Кликает кнопку "Показать реквизиты"
    ↓
    AJAX: wp_ajax_decrypt_payout_details
    ↓
    Cashback_Payouts_Admin::handle_decrypt_payout_details()
    ↓
    - Проверка: status = 'processing' → OK
    - Проверка прав: manage_options OR manage_woocommerce → OK
    ↓
    Расшифровка:
    Cashback_Encryption::decrypt_details($encrypted_details)
    ↓
    Возвращает:
    {
        "account": "+79031234567",
        "full_name": "Иванов Петр",
        "bank": "044525225"
    }
    ↓
    Аудит-лог:
    Cashback_Encryption::write_audit_log(
        'payout_details_decrypted',
        'payout_request',
        '123',
        get_current_user_id()
    )
    ↓
9. Администратор выполняет выплату в банке
    ↓
10. Администратор меняет статус на 'paid'
    ↓
    AJAX: wp_ajax_update_payout_request (status='paid')
    ↓
    START TRANSACTION
    ↓
    SELECT ... FOR UPDATE заявки
    ↓
    Проверка перехода: 'processing' → 'paid' → OK
    ↓
    update_user_balance_on_payout(42, 1500, 'paid', 'processing'):
        UPDATE cashback_user_balance
        SET pending_balance = pending_balance - 1500,
            paid_balance = paid_balance + 1500,
            version = version + 1
        WHERE user_id = 42 AND version = current_version
    ↓
    UPDATE cashback_payout_requests
    SET status = 'paid', updated_at = NOW()
    WHERE id = 123
    ↓
    COMMIT
    ↓
11. Триггер tr_prevent_update_paid_payout:
    - Блокирует дальнейшие изменения заявки со статусом 'paid'
    ↓
Готово: Выплата завершена, средства в paid_balance
```

---

### 6. Бан/разбан пользователя

#### Бан

```
Администратор: Кэшбэк → Пользователи → находит пользователя ID=42
    ↓
Кликает "Редактировать"
    ↓
Меняет:
    - status: 'active' → 'banned'
    - ban_reason: 'Нарушение правил сервиса'
    ↓
AJAX: wp_ajax_update_user_profile
    ↓
Cashback_Users_Management_Admin::handle_update_user_profile()
    ↓
1. Проверка прав: manage_options → OK
    ↓
2. Валидация:
    - status = 'banned'
    - ban_reason не пустой → OK
    ↓
3. Начало транзакции:
    START TRANSACTION
    ↓
4. Обновление профиля:
    UPDATE cashback_user_profile
    SET status = 'banned',
        ban_reason = 'Нарушение правил сервиса'
    WHERE user_id = 42
    ↓
5. Триггер tr_banned_user_update_banned_at:
    SET NEW.banned_at = NOW()
    ↓
    Результат:
    - status = 'banned'
    - banned_at = '2024-01-15 10:30:00'
    - ban_reason = 'Нарушение правил сервиса'
    ↓
6. Триггер tr_freeze_balance_on_ban (AFTER UPDATE):
    UPDATE cashback_user_balance
    SET frozen_balance = frozen_balance + available_balance + pending_balance,
        available_balance = 0,
        pending_balance = 0
    WHERE user_id = 42
    ↓
    Пример:
    - available_balance: 1000 → 0
    - pending_balance: 500 → 0
    - frozen_balance: 0 → 1500
    ↓
7. handle_user_ban(42, 'Нарушение правил сервиса', $wpdb):
    ↓
    7.1. Поиск активных заявок:
        SELECT id, total_amount
        FROM cashback_payout_requests
        WHERE user_id = 42
          AND status IN ('waiting', 'processing', 'needs_retry')
        FOR UPDATE
        ↓
        Найдено: заявка ID=125, amount=500
    ↓
    7.2. Отклонение заявок:
        UPDATE cashback_payout_requests
        SET status = 'declined',
            fail_reason = '(Аккаунт забанен)',
            updated_at = NOW()
        WHERE id = 125
    ↓
    7.3. Аудит-лог:
        write_audit_log('payout_declined_on_ban', 'payout_request', '125')
        write_audit_log('user_banned', 'user_profile', '42')
    ↓
8. Коммит:
    COMMIT
    ↓
9. Email пользователю:
    wp_mail(
        'user@example.com',
        'Ваш аккаунт заблокирован',
        "Причина: Нарушение правил сервиса\nДата: 15.01.2024 10:30"
    )
    ↓
Готово: Пользователь забанен, балансы заморожены, заявки отклонены
```

#### Разбан

```
Администратор: Кэшбэк → Пользователи → пользователь ID=42
    ↓
Кликает "Редактировать"
    ↓
Меняет:
    - status: 'banned' → 'active'
    ↓
AJAX: wp_ajax_update_user_profile
    ↓
1. Проверка прав: manage_options → OK
    ↓
2. Начало транзакции:
    START TRANSACTION
    ↓
3. Обновление профиля:
    UPDATE cashback_user_profile
    SET status = 'active'
    WHERE user_id = 42
    ↓
4. Триггер tr_clear_ban_on_unban (BEFORE UPDATE):
    IF OLD.status = 'banned' AND NEW.status != 'banned' THEN
        SET NEW.banned_at = NULL;
        SET NEW.ban_reason = NULL;
    END IF
    ↓
    Результат:
    - status = 'active'
    - banned_at = NULL
    - ban_reason = NULL
    ↓
5. Триггер tr_unfreeze_balance_on_unban (AFTER UPDATE):
    UPDATE cashback_user_balance
    SET available_balance = available_balance + frozen_balance,
        frozen_balance = 0
    WHERE user_id = 42
    ↓
    Пример:
    - frozen_balance: 1500 → 0
    - available_balance: 0 → 1500
    ↓
6. handle_user_unban(42, $wpdb):
    ↓
    6.1. Обновление причин отклонения:
        UPDATE cashback_payout_requests
        SET fail_reason = REPLACE(fail_reason, '(Аккаунт забанен)', '(Аккаунт был забанен)')
        WHERE user_id = 42
          AND status = 'declined'
          AND fail_reason LIKE '%(Аккаунт забанен)%'
    ↓
    6.2. Аудит-лог:
        write_audit_log('user_unbanned', 'user_profile', '42')
    ↓
7. Коммит:
    COMMIT
    ↓
Готово: Пользователь разбанен, замороженные средства возвращены
```

---

## API и точки расширения

### WordPress хуки

#### Actions

| Хук                                 | Файл                                             | Назначение                                           |
| ----------------------------------- | ------------------------------------------------ | ---------------------------------------------------- |
| `user_register`                     | [mariadb.php](mariadb.php)                       | Инициализация нового пользователя в таблицах кешбэка |
| `plugins_loaded`                    | [cashback-plugin.php](cashback-plugin.php)       | Загрузка и инициализация плагина                     |
| `init`                              | [cashback-plugin.php](cashback-plugin.php)       | Загрузка переводов                                   |
| `before_woocommerce_init`           | [cashback-plugin.php](cashback-plugin.php)       | Декларация совместимости с HPOS                      |
| `admin_menu`                        | [admin/\*.php](admin/)                           | Добавление страниц в админку                         |
| `wp_enqueue_scripts`                | [\*.php](.)                                      | Подключение стилей и скриптов на фронтенде           |
| `admin_enqueue_scripts`             | [admin/\*.php](admin/)                           | Подключение стилей и скриптов в админке              |
| `cashback_health_check_cron`        | [admin/health-check.php](admin/health-check.php) | Ежедневная проверка целостности                      |
| `cashback_support_auto_delete_cron` | [support/support-db.php](support/support-db.php) | Удаление старых тикетов                              |

#### Filters

| Хук                                   | Файл                                                       | Назначение                                 |
| ------------------------------------- | ---------------------------------------------------------- | ------------------------------------------ |
| `woocommerce_account_menu_items`      | [cashback-\*.php](.)                                       | Добавление пунктов в меню личного кабинета |
| `woocommerce_product_add_to_cart_url` | [wc-affiliate-url-params.php](wc-affiliate-url-params.php) | Модификация URL внешних товаров            |
| `woocommerce_loop_add_to_cart_link`   | [wc-affiliate-url-params.php](wc-affiliate-url-params.php) | Добавление data-атрибутов к ссылкам        |

### WooCommerce endpoints

| Endpoint              | Класс              | URL                                |
| --------------------- | ------------------ | ---------------------------------- |
| `cashback-withdrawal` | CashbackWithdrawal | `/my-account/cashback-withdrawal/` |
| `cashback-history`    | CashbackHistory    | `/my-account/cashback-history/`    |
| `history-payout`      | HistoryPayout      | `/my-account/history-payout/`      |

### AJAX обработчики

#### Фронтенд (требуют авторизации)

| Action                        | Класс              | Nonce                          | Назначение                   |
| ----------------------------- | ------------------ | ------------------------------ | ---------------------------- |
| `process_cashback_withdrawal` | CashbackWithdrawal | `cashback_withdrawal_nonce`    | Создание заявки на выплату   |
| `get_user_balance`            | CashbackWithdrawal | `cashback_withdrawal_nonce`    | Получение баланса            |
| `save_payout_settings`        | CashbackWithdrawal | `cashback_withdrawal_nonce`    | Сохранение реквизитов        |
| `search_banks`                | CashbackWithdrawal | `cashback_withdrawal_nonce`    | Автокомплит банков           |
| `load_page_transactions`      | CashbackHistory    | `load_page_transactions_nonce` | Пагинация истории транзакций |
| `load_page_payouts`           | HistoryPayout      | `load_page_payouts_nonce`      | Пагинация истории выплат     |

#### Админка (требуют `manage_options`)

| Action                   | Класс                           | Nonce                          | Назначение                      |
| ------------------------ | ------------------------------- | ------------------------------ | ------------------------------- |
| `update_payout_request`  | Cashback_Payouts_Admin          | `update_payout_request_nonce`  | Изменение статуса выплаты       |
| `get_payout_request`     | Cashback_Payouts_Admin          | `get_payout_request_nonce`     | Получение данных заявки         |
| `decrypt_payout_details` | Cashback_Payouts_Admin          | `decrypt_payout_details_nonce` | Расшифровка реквизитов          |
| `update_user_profile`    | Cashback_Users_Management_Admin | `update_user_profile_nonce`    | Обновление профиля пользователя |
| `get_user_profile`       | Cashback_Users_Management_Admin | `get_user_profile_nonce`       | Получение данных профиля        |
| `update_bank`            | Cashback_Bank_Management_Admin  | `update_bank_nonce`            | Обновление банка                |
| `add_bank`               | Cashback_Bank_Management_Admin  | `add_bank_nonce`               | Добавление банка                |
| `update_payout_method`   | Cashback_Payout_Methods_Admin   | `update_payout_method_nonce`   | Обновление способа выплат       |
| `add_payout_method`      | Cashback_Payout_Methods_Admin   | `add_payout_method_nonce`      | Добавление способа выплат       |

### WP Cron задачи

| Хук                                 | Расписание | Файл                                             | Назначение                                |
| ----------------------------------- | ---------- | ------------------------------------------------ | ----------------------------------------- |
| `cashback_health_check_cron`        | daily      | [admin/health-check.php](admin/health-check.php) | Проверка целостности данных + email-отчет |
| `cashback_support_auto_delete_cron` | daily      | [support/support-db.php](support/support-db.php) | Удаление закрытых тикетов старше 1 месяца |

---

## Безопасность

### Аутентификация и авторизация

#### Фронтенд

```php
// Все AJAX обработчики проверяют:
if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'Требуется авторизация']);
}

// Доступ только к своим данным:
$user_id = get_current_user_id();
$query = "SELECT * FROM table WHERE user_id = %d";
```

#### Админка

```php
// Все админ-страницы:
if (!current_user_can('manage_options')) {
    wp_die(__('У вас нет доступа к этой странице.'));
}

// Расшифровка реквизитов:
if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
    wp_send_json_error(['message' => 'Недостаточно прав']);
}
```

#### Партнерские параметры

```php
// При сохранении:
if (!current_user_can('edit_product', $post_id)) {
    return;
}
```

---

### Защита форм (CSRF)

**Все формы и AJAX используют WordPress nonces:**

```php
// Генерация nonce в форме:
wp_nonce_field('cashback_withdrawal_nonce', '_wpnonce');

// Проверка в обработчике:
if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'cashback_withdrawal_nonce')) {
    wp_send_json_error(['message' => 'Неверный токен безопасности']);
}

// Или через check_ajax_referer:
check_ajax_referer('load_page_transactions_nonce', 'nonce', false);
```

---

### Санитизация входных данных

**Все входящие данные обрабатываются:**

```php
// POST/GET данные:
$amount = isset($_POST['amount']) ? floatval(wp_unslash($_POST['amount'])) : 0;
$account = isset($_POST['account']) ? sanitize_text_field(wp_unslash($_POST['account'])) : '';
$reason = isset($_POST['ban_reason']) ? wp_kses_post(wp_unslash($_POST['ban_reason'])) : '';

// ID:
$user_id = absint($_POST['user_id']);
$payout_id = intval($_POST['payout_id']);

// Email:
$email = sanitize_email($_POST['email']);

// URL:
$url = esc_url_raw($_POST['url']);
```

---

### Экранирование вывода

**Все данные при выводе экранируются:**

```php
// HTML контент:
echo esc_html($user_name);

// Атрибуты:
echo '<input value="' . esc_attr($value) . '">';

// URL:
echo '<a href="' . esc_url($link) . '">';

// JavaScript строки:
echo '<script>var data = ' . wp_json_encode($data) . ';</script>';

// Разрешенный HTML (для контента):
echo wp_kses_post($description);
```

---

### Шифрование чувствительных данных

#### Алгоритм

**AES-256-CBC:**

- Ключ: 256 бит (32 байта, 64 hex символа)
- IV: Уникальный для каждой записи (16 байт)
- Функции: `openssl_encrypt()`, `openssl_decrypt()`

#### Генерация ключа

```php
// В CashbackPlugin::activate():
$key = bin2hex(random_bytes(32)); // Криптографически стойкий генератор
$file_content = "<?php\ndefine('CB_ENCRYPTION_KEY', '{$key}');\n";
file_put_contents(WP_CONTENT_DIR . '/.cashback-encryption-key.php', $file_content);
```

#### Что шифруется

- Номер счета (телефон, карта)
- ФИО получателя
- Код банка (БИК)

#### Что маскируется

При отображении используется `masked_details` JSON:

```json
{
  "account": "+7903***4567",
  "full_name": "И**** П****",
  "bank": "044525225"
}
```

#### Антифрод

```php
// SHA-256 хеш для обнаружения изменений:
$details_hash = hash('sha256', json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
```

---

### Защита от SQL Injection

**Все SQL-запросы используют подготовленные выражения:**

```php
// Правильно:
$result = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$table} WHERE user_id = %d AND status = %s",
    $user_id,
    $status
));

// НЕПРАВИЛЬНО (никогда не использовать):
// $result = $wpdb->get_row("SELECT * FROM {$table} WHERE user_id = {$user_id}");
```

**LIKE-поиск:**

```php
$search_term = $wpdb->esc_like($search);
$query = $wpdb->prepare(
    "SELECT * FROM {$table} WHERE name LIKE %s",
    '%' . $search_term . '%'
);
```

---

### Защита от XSS

**Принцип:** Никогда не доверять пользовательским данным при выводе

```php
// Правильно:
echo '<div>' . esc_html($user_input) . '</div>';
echo '<input value="' . esc_attr($user_input) . '">';

// НЕПРАВИЛЬНО:
// echo '<div>' . $user_input . '</div>';
```

---

### Защита от Race Conditions

#### MySQL Named Locks

```php
// Получение блокировки (10 секунд таймаут):
$wpdb->query($wpdb->prepare("SELECT GET_LOCK(%s, 10)", $lock_name));

// Критическая секция
// ...

// Освобождение блокировки:
$wpdb->query($wpdb->prepare("DO RELEASE_LOCK(%s)", $lock_name));
```

#### SELECT FOR UPDATE

```php
$wpdb->query('START TRANSACTION');

$balance = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$table} WHERE user_id = %d FOR UPDATE",
    $user_id
));

// Работа с заблокированной строкой
// ...

$wpdb->query('COMMIT');
```

#### Оптимистичная блокировка

```php
// Чтение текущей версии:
$current_version = $balance->version;

// Обновление с проверкой версии:
$updated = $wpdb->query($wpdb->prepare(
    "UPDATE {$table}
     SET available_balance = available_balance - %f,
         version = version + 1
     WHERE user_id = %d AND version = %d",
    $amount, $user_id, $current_version
));

if ($updated === 0) {
    // Конфликт версий - кто-то изменил запись
    throw new Exception('Conflict: balance was modified by another process');
}
```

---

### Защита от дубликатов (Idempotency)

**UNIQUE KEY на idempotency_key:**

```php
// Генерация уникального ключа:
$idempotency_key = hash('sha256',
    $user_id . '_' .
    microtime(true) . '_' .
    wp_create_nonce('cashback_withdrawal_' . $user_id) . '_' .
    bin2hex(random_bytes(16))
);

// INSERT с idempotency_key:
$wpdb->insert($table, [
    'user_id' => $user_id,
    'total_amount' => $amount,
    'idempotency_key' => $idempotency_key,
    // ...
]);

// При дубликате MySQL вернет ошибку Duplicate entry
```

---

### Защита от DoS

**Лимиты пагинации:**

```php
// В CashbackHistory:
const MAX_ALLOWED_PAGES = 1000;

if ($page > self::MAX_ALLOWED_PAGES) {
    wp_send_json_error(['message' => 'Страница не найдена']);
}
```

**MySQL события с блокировками:**

```sql
-- Защита от параллельного запуска:
IF GET_LOCK('cashback_event_lock', 0) = 1 THEN
    -- Выполнение события
    -- ...
    DO RELEASE_LOCK('cashback_event_lock');
END IF;
```

---

### Аудит-лог

**Логирование критических операций:**

```php
Cashback_Encryption::write_audit_log(
    string $action,           // 'payout_details_decrypted'
    string $entity_type,      // 'payout_request'
    string $entity_id,        // '123'
    ?int $actor_id = null     // ID администратора
);
```

**Сохраняется:**

- IP адрес (с поддержкой прокси)
- User-Agent
- Timestamp

**Типы действий:**

- `payout_details_decrypted` — Расшифровка реквизитов
- `payout_declined_on_ban` — Отклонение при бане
- `user_banned` — Бан пользователя
- `user_unbanned` — Разбан пользователя

---

### Валидация префикса таблицы

```php
private function validate_table_prefix(string $prefix): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
        error_log('[Cashback] Invalid table prefix detected');
        return false;
    }
    return true;
}
```

---

### Защита от прямого доступа

**Все PHP файлы начинаются с:**

```php
if (!defined('ABSPATH')) {
    exit;
}
```

**uninstall.php:**

```php
if (!defined('WP_UNINSTALL_PLUGIN') || !WP_UNINSTALL_PLUGIN) {
    exit;
}

if (!current_user_can('activate_plugins')) {
    exit;
}
```

---

## Ключевые файлы для модификации

### Изменения схемы БД

**Файл:** [mariadb.php](mariadb.php)
**Методы:**

- `create_tables()` — добавление новых таблиц
- `create_triggers()` — модификация триггеров
- `create_events()` — изменение MySQL событий

**Миграции:**
Создавать новые методы типа `migrate_add_new_column()` и вызывать в `activate()`

---

### Логика выплат

**Файл:** [cashback-withdrawal.php](cashback-withdrawal.php)
**Ключевые методы:**

- `process_cashback_withdrawal()` — логика создания заявки
- `validate_withdrawal_data()` — валидация данных

**Важно:**

- Сохранять защиту от race conditions (GET_LOCK + FOR UPDATE + version)
- Не удалять идемпотентность
- Сохранять шифрование реквизитов

---

### Административное управление

**Файл:** [admin/payouts.php](admin/payouts.php)
**Ключевые методы:**

- `handle_update_payout_request()` — смена статуса
- `update_user_balance_on_payout()` — обновление баланса

**Важно:**

- Соблюдать конечный автомат статусов
- Сохранять транзакционность
- Не обходить триггеры БД

---

### Криптография

**Файл:** [includes/class-cashback-encryption.php](includes/class-cashback-encryption.php)

**Важно:**

- НЕ изменять алгоритм шифрования без миграции данных
- НЕ изменять формат хранения (base64(IV || ciphertext))
- При смене алгоритма: написать миграцию для перешифровки

---

### Управление пользователями

**Файл:** [admin/users-management.php](admin/users-management.php)
**Критические методы:**

- `handle_user_ban()` — логика бана
- `handle_user_unban()` — логика разбана

**Важно:**

- Сохранять транзакционность при бане
- Не забывать про email-уведомления
- Сохранять аудит-лог

---

## Разработка и тестирование

### PHPStan

**Конфигурация:** [development/phpstan.neon](development/phpstan.neon)

```yaml
parameters:
  level: 5
  paths:
    - ../
  excludePaths:
    - ../development/
    - ../assets/
  bootstrapFiles:
    - config/phpstan-bootstrap.php
```

**Запуск:**

```bash
cd development
composer install
vendor/bin/phpstan analyse
```

---

### PHPCS (PHP_CodeSniffer)

**Стандарты:** WordPress, WordPress-Extra

**Конфигурация:** [development/.phpcs.xml.dist](development/.phpcs.xml.dist)

**Запуск:**

```bash
cd development
vendor/bin/phpcs ../ --standard=WordPress --extensions=php --ignore=development/,assets/
```

**Автофикс:**

```bash
vendor/bin/phpcbf ../ --standard=WordPress --extensions=php
```

---

### Composer зависимости

**Файл:** [development/composer.json](development/composer.json)

```json
{
  "require-dev": {
    "phpstan/phpstan": "^1.10",
    "szepeviktor/phpstan-wordpress": "^1.3",
    "php-stubs/wordpress-stubs": "^6.2",
    "php-stubs/woocommerce-stubs": "^7.0",
    "squizlabs/php_codesniffer": "^3.7",
    "wp-coding-standards/wpcs": "^3.0"
  }
}
```

---

### Структура development/

```
development/
├── composer.json              - Зависимости
├── composer.lock              - Lock-файл
├── phpstan.neon               - Конфигурация PHPStan
├── .phpcs.xml.dist            - Конфигурация PHPCS
├── config/
│   └── phpstan-bootstrap.php  - Загрузчик констант для PHPStan
├── docs/
│   ├── README.md              - Общая документация
│   ├── CHANGELOG.md           - История изменений
│   ├── SECURITY.md            - Политика безопасности
│   └── CONTRIBUTING.md        - Руководство для контрибьюторов
└── vendor/                    - Composer пакеты
```

---

## Полезные команды

### Git

```bash
# Статус
git status

# Коммит изменений
git add .
git commit -m "Описание изменений"

# Просмотр истории
git log --oneline
```

### WP-CLI (если установлен)

```bash
# Информация о плагине
wp plugin list | grep mariadb

# Активация/деактивация
wp plugin activate mariadb
wp plugin deactivate mariadb

# Экспорт БД
wp db export backup.sql

# Проверка таблиц
wp db query "SHOW TABLES LIKE 'wp_cashback_%'"
```

### MySQL

```bash
# Просмотр триггеров
SHOW TRIGGERS LIKE 'cashback_%';

# Просмотр событий
SHOW EVENTS WHERE Db = 'database_name';

# Проверка блокировок
SELECT * FROM information_schema.INNODB_LOCKS;
```

---

## Контакты и ресурсы

### Документация

- **README:** [INSTALL.md](INSTALL.md)
- **Changelog:** [development/docs/CHANGELOG.md](development/docs/CHANGELOG.md)
- **Security:** [development/docs/SECURITY.md](development/docs/SECURITY.md)
- **Contributing:** [development/docs/CONTRIBUTING.md](development/docs/CONTRIBUTING.md)

### Требования к среде

```php
// Проверка в cashback-plugin.php:
PHP_VERSION >= 7.4
WordPress >= 6.2
WooCommerce >= 5.0
```

### Серверные требования

- MySQL >= 5.7 или MariaDB >= 10.2 (для триггеров и событий)
- Поддержка `openssl` расширения PHP
- Включенный `event_scheduler` в MySQL:

```sql
SET GLOBAL event_scheduler = ON;
```

---

## Заключение

Этот документ описывает полную архитектуру плагина Cashback (MariaDB). Для дополнительной информации обращайтесь к исходному коду и комментариям в файлах.

**Принципы разработки:**

- Безопасность прежде всего
- Транзакционность критических операций
- Идемпотентность запросов
- Аудит чувствительных действий
- Валидация на всех уровнях

**Основные технологии:**

- WordPress + WooCommerce
- MySQL/MariaDB (триггеры, события)
- AES-256-CBC шифрование
- Оптимистичная блокировка
- Named locks для защиты от гонок

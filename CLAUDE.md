# WordPress Cashback Plugin (MariaDB) - Документация

## Обзор плагина

**Название:** Cashback Plugin
**Назначение:** Система кешбэка для WooCommerce с партнерскими ссылками, выплатами, антифрод-защитой, API-интеграцией с CPA-сетями и административным управлением

### Технические требования

- **PHP:** >= 7.4
- **WordPress:** >= 6.2
- **WooCommerce:** >= 5.0
- **База данных:** MySQL/MariaDB с поддержкой триггеров и событий

### Основные возможности

- ✅ Начисление кешбэка с гибкими ставками (индивидуальные и на уровне товара)
- ✅ Партнерские URL с динамическими параметрами и сетевой архитектурой
- ✅ Защищенная система выплат с идемпотентностью и reference ID
- ✅ AES-256-GCM шифрование реквизитов (с обратной совместимостью AES-256-CBC)
- ✅ Антифрод-модуль с browser fingerprinting и 7 автоматическими детекторами
- ✅ API-клиент для CPA-сетей (Admitad, EPN) с OAuth2 и фоновой синхронизацией
- ✅ Rate limiting и защита от click fraud (двухуровневый: per-product + global)
- ✅ Серверное логирование кликов по партнёрским ссылкам (UUID click_id)
- ✅ Статистика и аналитика для администратора (KPI дашборд)
- ✅ Управление транзакциями в админке (2 вкладки: зарегистрированные / анонимные)
- ✅ Управление партнёрскими сетями и их параметрами (БД вместо post_meta)
- ✅ Административная панель управления
- ✅ Система поддержки с тикетами и вложениями файлов
- ✅ Мониторинг целостности данных
- ✅ Аудит-лог критических операций с защитой от IP spoofing
- ✅ Шорткод `[cashback_balance]` для вывода баланса в любом месте сайта

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
- Загрузка зависимостей (29 PHP файлов)
- Инициализация компонентов (10 экземпляров)
- Генерация ключа шифрования

### Схема компонентов

```
CashbackPlugin (загрузчик)
│
├── mariadb.php (Mariadb_Plugin)
│   └── Управление БД: таблицы, триггеры, события, reference_id
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
│   └── Партнёрские URL: сетевая архитектура, rate limiting, click tracking, cashback display
│
├── includes/
│   ├── class-cashback-encryption.php (Cashback_Encryption)
│   │   └── AES-256-GCM шифрование (v2) + legacy CBC (v1), аудит, IP spoofing защита
│   ├── class-cashback-user-status.php (Cashback_User_Status)
│   │   └── Проверка статуса и бана пользователя
│   ├── class-cashback-api-client.php (Cashback_API_Client)
│   │   └── API-клиент CPA-сетей (Admitad, EPN), OAuth2, шифрованные credentials
│   ├── class-cashback-api-migration.php (Cashback_API_Migration)
│   │   └── Миграции для таблиц validation_checkpoints и sync_log
│   ├── class-cashback-api-cron.php (Cashback_API_Cron)
│   │   └── Фоновая синхронизация статусов каждые 2 часа
│   └── class-cashback-shortcodes.php (Cashback_Shortcodes)
│       └── Шорткод [cashback_balance] для вывода баланса пользователя
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
│   ├── click-log.php (Cashback_Click_Log_Admin)
│   │   └── Лог кликов по партнёрским ссылкам
│   ├── transactions.php (Cashback_Transactions_Admin)
│   │   └── Управление транзакциями (2 вкладки)
│   ├── statistics.php (Cashback_Statistics_Admin)
│   │   └── Дашборд статистики и KPI
│   ├── class-cashback-admin-api-validation.php (Cashback_Admin_API_Validation)
│   │   └── API валидация, синхронизация, credentials (3 вкладки)
│   └── traits/AdminPaginationTrait.php
│       └── Общая логика пагинации
│
├── partner/
│   └── partner-management.php (Cashback_Partner_Management_Admin)
│       └── Управление партнёрскими сетями и параметрами
│
├── antifraud/
│   ├── class-fraud-db.php (Cashback_Fraud_DB)
│   │   └── 3 таблицы: alerts, signals, fingerprints
│   ├── class-fraud-settings.php (Cashback_Fraud_Settings)
│   │   └── 14 настроек антифрода с валидацией
│   ├── class-fraud-collector.php (Cashback_Fraud_Collector)
│   │   └── Сбор fingerprint, IP, событий
│   ├── class-fraud-detector.php (Cashback_Fraud_Detector)
│   │   └── 7 проверок, risk scoring, email-уведомления
│   └── class-fraud-admin.php (Cashback_Fraud_Admin)
│       └── 3 вкладки: уведомления, подозрительные, настройки
│
└── support/
    ├── support-db.php (Cashback_Support_DB)
    │   └── Таблицы тикетов, сообщений, вложений + директория загрузок
    ├── admin-support.php
    │   └── Административная часть поддержки
    └── user-support.php (Cashback_User_Support)
        └── Пользовательский кабинет поддержки с rate limiting
```

### Жизненный цикл плагина

**Активация:**

```php
register_activation_hook() → CashbackPlugin::activate()
  1. Генерация ключа шифрования (32 байта → hex)
  2. Сохранение в wp-content/.cashback-encryption-key.php
  3. Mariadb_Plugin::activate() — 11 таблиц, аудит-лог, constraints, триггеры, события
  4. Mariadb_Plugin::migrate_add_reference_id() — бэкфилл reference_id
  5. Cashback_API_Migration::run() — таблицы validation_checkpoints, sync_log
  6. Cashback_Support_DB::create_tables() + ensure_upload_dir() — поддержка + вложения
  7. Cashback_Fraud_DB::create_tables() — 3 таблицы антифрода
  8. Планирование 5 WP Cron задач
  9. Cashback_API_Cron::init() — регистрация 2-часового интервала
  10. flush_rewrite_rules()
```

**Инициализация:**

```php
plugins_loaded → CashbackPlugin::init()
  1. load_dependencies() — 29 PHP файлов через require_once
  2. initialize_components() — 10 singleton/экземпляров
  3. Проверка ключа шифрования → admin_notices при отсутствии
  4. declare_woocommerce_compatibility() — HPOS и блоки
```

**Деактивация:**

```php
register_deactivation_hook() → CashbackPlugin::deactivate()
  Снятие 5 WP Cron задач:
    - cashback_support_auto_delete_cron
    - cashback_health_check_cron
    - cashback_fraud_detection_cron
    - cashback_fraud_cleanup_cron
    - cashback_api_sync_statuses
```

**Удаление:**

```php
uninstall.php → cashback_plugin_uninstall()
  1. Снятие 4 cron-событий
  2. DROP 16 триггеров
  3. DROP 4 MySQL событий
  4. Удаление файлов вложений поддержки (рекурсивно)
  5. DROP 18 таблиц (в обратном порядке для FK)
  6. Удаление 20+ опций (включая антифрод настройки)
  7. Удаление post_meta партнёрских сетей
  8. Удаление transients (rate limiting: cb_pp_*, cb_gl_*, cb_ip_*)
  9. Удаление файла ключа шифрования
  10. wp_cache_flush()
```

---

## Структура файлов

### Корневые файлы

| Файл | Назначение | Ключевой класс |
|------|-----------|----------------|
| [cashback-plugin.php](cashback-plugin.php) | Точка входа, загрузчик | `CashbackPlugin` |
| [mariadb.php](mariadb.php) | Управление схемой БД | `Mariadb_Plugin` |
| [cashback-withdrawal.php](cashback-withdrawal.php) | Страница вывода кешбэка | `CashbackWithdrawal` |
| [cashback-history.php](cashback-history.php) | История транзакций | `CashbackHistory` |
| [history-payout.php](history-payout.php) | История выплат | `HistoryPayout` |
| [wc-affiliate-url-params.php](wc-affiliate-url-params.php) | Партнерские URL, rate limiting, click tracking | `WC_Affiliate_URL_Params` |
| [uninstall.php](uninstall.php) | Деинсталляция | Функция |

### Папка admin/

**Административные интерфейсы** (требуют `manage_options`)

| Файл | Класс | Страница |
|------|-------|----------|
| [payout-methods.php](admin/payout-methods.php) | `Cashback_Payout_Methods_Admin` | Способы выплаты (СБП, МИР и др.) |
| [payouts.php](admin/payouts.php) | `Cashback_Payouts_Admin` | Управление заявками на выплату |
| [users-management.php](admin/users-management.php) | `Cashback_Users_Management_Admin` | Управление пользователями и банами |
| [bank-management.php](admin/bank-management.php) | `Cashback_Bank_Management_Admin` | Справочник банков |
| [health-check.php](admin/health-check.php) | `Cashback_Health_Check` | Мониторинг (WP Cron задача) |
| [click-log.php](admin/click-log.php) | `Cashback_Click_Log_Admin` | Лог кликов по партнёрским ссылкам |
| [transactions.php](admin/transactions.php) | `Cashback_Transactions_Admin` | Управление транзакциями (2 вкладки) |
| [statistics.php](admin/statistics.php) | `Cashback_Statistics_Admin` | Дашборд статистики и KPI |
| [class-cashback-admin-api-validation.php](admin/class-cashback-admin-api-validation.php) | `Cashback_Admin_API_Validation` | API валидация и синхронизация (3 вкладки) |
| [traits/AdminPaginationTrait.php](admin/traits/AdminPaginationTrait.php) | `AdminPaginationTrait` | Общая логика пагинации |

### Папка includes/

**Вспомогательные классы**

| Файл | Класс | Назначение |
|------|-------|-----------|
| [class-cashback-encryption.php](includes/class-cashback-encryption.php) | `Cashback_Encryption` | AES-256-GCM шифрование, маскирование, аудит, IP защита |
| [class-cashback-user-status.php](includes/class-cashback-user-status.php) | `Cashback_User_Status` | Проверка бана и статуса пользователя |
| [class-cashback-api-client.php](includes/class-cashback-api-client.php) | `Cashback_API_Client` | API-клиент CPA-сетей (Admitad, EPN), OAuth2 |
| [class-cashback-api-migration.php](includes/class-cashback-api-migration.php) | `Cashback_API_Migration` | Миграции БД для API валидации |
| [class-cashback-api-cron.php](includes/class-cashback-api-cron.php) | `Cashback_API_Cron` | Фоновая синхронизация (каждые 2 часа) |
| [class-cashback-shortcodes.php](includes/class-cashback-shortcodes.php) | `Cashback_Shortcodes` | Шорткод `[cashback_balance]` |

### Папка partner/

**Управление партнёрскими сетями**

| Файл | Класс | Назначение |
|------|-------|-----------|
| [partner-management.php](partner/partner-management.php) | `Cashback_Partner_Management_Admin` | CRUD партнёрских сетей и их параметров |

### Папка antifraud/

**Антифрод-модуль**

| Файл | Класс | Назначение |
|------|-------|-----------|
| [class-fraud-db.php](antifraud/class-fraud-db.php) | `Cashback_Fraud_DB` | 3 таблицы + cleanup старых данных |
| [class-fraud-settings.php](antifraud/class-fraud-settings.php) | `Cashback_Fraud_Settings` | 14 настроек с валидацией типов |
| [class-fraud-collector.php](antifraud/class-fraud-collector.php) | `Cashback_Fraud_Collector` | Сбор fingerprint, IP, User-Agent |
| [class-fraud-detector.php](antifraud/class-fraud-detector.php) | `Cashback_Fraud_Detector` | 7 проверок, risk scoring, email |
| [class-fraud-admin.php](antifraud/class-fraud-admin.php) | `Cashback_Fraud_Admin` | Админ-панель: 3 вкладки, badge |

### Папка support/

**Модуль поддержки** (тикет-система с вложениями)

| Файл | Класс | Назначение |
|------|-------|-----------|
| [support-db.php](support/support-db.php) | `Cashback_Support_DB` | Таблицы тикетов, сообщений, вложений + директория |
| [admin-support.php](support/admin-support.php) | - | Административный интерфейс |
| [user-support.php](support/user-support.php) | `Cashback_User_Support` | Пользовательский кабинет с rate limiting |

### Папка assets/

**Стили и скрипты**

```
assets/
├── css/
│   ├── cashback-history.css
│   ├── cashback-withdrawal.css
│   ├── history-payout.css
│   ├── admin-payouts.css
│   ├── admin-users.css
│   ├── admin-payout-methods.css
│   ├── admin-banks.css
│   ├── admin-health-check.css
│   ├── admin-click-log.css
│   ├── admin-transactions.css
│   ├── admin-statistics.css
│   ├── admin-partner-management.css
│   ├── admin-api-validation.css
│   ├── admin-fraud.css
│   ├── admin-support.css
│   └── user-support.css
├── js/
│   ├── cashback-history.js
│   ├── cashback-withdrawal.js
│   ├── history-payout.js
│   ├── admin-payouts.js
│   ├── admin-users.js
│   ├── admin-payout-methods.js
│   ├── admin-banks.js
│   ├── admin-click-log.js
│   ├── admin-transactions.js
│   ├── admin-statistics.js
│   ├── admin-partner-management.js
│   ├── admin-api-validation.js
│   ├── admin-fraud.js
│   ├── admin-support.js
│   ├── user-support.js
│   └── fraud-fingerprint.js
└── images/
```

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
```

---

## База данных

### Обзор

**Всего таблиц:** 20 (11 основных + 1 аудит-лог + 3 антифрод + 3 поддержка + 2 API валидация)
**Всего триггеров:** 16
**Всего MySQL событий:** 4

### Таблицы

#### 1. `cashback_transactions` — Транзакции кешбэка

**Назначение:** Хранение всех покупок через партнерские ссылки

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `user_id` | BIGINT FK | → wp_users.ID (RESTRICT) |
| `order_number` | VARCHAR(255) | Номер заказа у партнёра |
| `offer_id` | INT UNSIGNED | ID партнёрской программы в CPA-сети (advcampaign_id) |
| `offer_name` | VARCHAR(255) | Название партнера (магазина) |
| `order_status` | ENUM | `waiting`, `completed`, `declined`, `hold`, `balance` |
| `partner` | VARCHAR(255) | Название CPA-сети |
| `sum_order` | DECIMAL(10,2) | Сумма заказа |
| `comission` | DECIMAL(10,2) | Комиссия от партнера |
| `currency` | CHAR(3) | Валюта комиссии (ISO 4217, default `RUB`) |
| `uniq_id` | VARCHAR(255) | ID конверсии в CPA |
| `cashback` | DECIMAL(10,2) | Рассчитанный кешбэк |
| `applied_cashback_rate` | DECIMAL(5,2) | Примененная ставка (%, default 60.00) |
| `api_verified` | TINYINT(1) | 1 = транзакция сверена с API (триггер начисления) |
| `action_date` | DATETIME | Реальное время покупки |
| `click_time` | DATETIME | Время клика (антифрод: action_date − click_time = 0 → бот) |
| `click_id` | CHAR(32) | UUID клика, связь с cashback_click_log |
| `website_id` | INT UNSIGNED | ID площадки в CPA-сети |
| `action_type` | VARCHAR(10) | sale/lead |
| `processed_at` | DATETIME | Когда начислено в баланс |
| `processed_batch_id` | CHAR(36) | UUID батча начисления |
| `idempotency_key` | VARCHAR(64) | Защита от дубликатов |
| `spam_click` | TINYINT(1) | 1 = подозрительный клик, кешбэк после ручной проверки |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**Индексы:**

- UNIQUE: `(uniq_id, partner)`
- UNIQUE: `idempotency_key`
- KEY: `user_id`, `idx_user_created`, `idx_processed`, `idx_processed_batch_id`
- KEY: `idx_click_id`, `idx_offer_id`
- KEY: `idx_balance_candidates` — составной: `(order_status, api_verified, processed_at, spam_click, cashback)`

**CHECK Constraints:**

- `applied_cashback_rate BETWEEN 0.00 AND 100.00`
- `cashback >= 0`
- `currency REGEXP '^[A-Z]{3}$'`

---

#### 2. `cashback_user_balance` — Балансы пользователей

| Поле | Тип | Описание |
|------|-----|----------|
| `user_id` | BIGINT PK FK | → wp_users.ID (RESTRICT) |
| `available_balance` | DECIMAL(18,2) | Доступно для вывода |
| `pending_balance` | DECIMAL(18,2) | В обработке (заявка подана) |
| `paid_balance` | DECIMAL(18,2) | Выплачено |
| `frozen_balance` | DECIMAL(18,2) | Заморожено (бан) |
| `version` | INT UNSIGNED | Оптимистичная блокировка |
| `updated_at` | DATETIME | Дата обновления |

**CHECK Constraints:** Все 4 баланса >= 0

---

#### 3. `cashback_payout_requests` — Заявки на выплату

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `reference_id` | VARCHAR(11) UNIQUE | Публичный ID формата WD-XXXXXXXX |
| `user_id` | BIGINT FK | → wp_users.ID (RESTRICT) |
| `total_amount` | DECIMAL(18,2) | Сумма к выплате |
| `payout_method` | VARCHAR(50) | Slug способа выплаты |
| `payout_account` | VARCHAR(255) | Реквизиты (legacy) |
| `encrypted_details` | BLOB | AES-256-GCM зашифрованные реквизиты |
| `masked_details` | TEXT | JSON маскированных данных |
| `provider` | VARCHAR(100) | Идентификатор провайдера выплат |
| `provider_payout_id` | VARCHAR(255) | ID операции у провайдера |
| `idempotency_key` | CHAR(36) | UUID v4 идемпотентный ключ |
| `attempts` | INT | Количество попыток |
| `fail_reason` | TEXT | Причина ошибки |
| `status` | ENUM | `waiting`, `processing`, `paid`, `failed`, `declined`, `needs_retry` |
| `refunded_at` | DATETIME | Дата возврата (при failed) |

**Индексы:**

- UNIQUE: `reference_id`, `idempotency_key`
- KEY: `idx_user_status`, `idx_status_updated`, `idx_provider_payout_id`
- KEY: `idx_payout_method_slug`, `idx_user_created`

---

#### 4. `cashback_user_profile` — Профили пользователей

| Поле | Тип | Описание |
|------|-----|----------|
| `user_id` | BIGINT PK FK | → wp_users.ID (CASCADE) |
| `payout_method_id` | BIGINT FK | → cashback_payout_methods.id (SET NULL) |
| `payout_account` | VARCHAR(255) | Телефон/карта/кошелёк (legacy) |
| `payout_full_name` | VARCHAR(255) | ФИО для выплат (legacy) |
| `encrypted_details` | BLOB | AES-256-GCM зашифрованные реквизиты |
| `masked_details` | TEXT | Маскированные реквизиты (JSON) |
| `details_hash` | CHAR(64) | SHA-256 хеш для антифрода |
| `partner_token` | CHAR(32) | Криптографический токен для партнёрских ссылок (вместо user_id) |
| `bank_id` | BIGINT FK | → cashback_banks.id (SET NULL) |
| `cashback_rate` | DECIMAL(5,2) | Индивидуальная ставка (0-100%, default 60) |
| `is_verified` | TINYINT(1) | 1 = реквизиты подтверждены |
| `payout_details_updated_at` | DATETIME | Дата обновления реквизитов |
| `min_payout_amount` | DECIMAL(18,2) | Минимальная сумма вывода (default 100) |
| `opt_out` | TINYINT(1) | Отказ от участия |
| `status` | ENUM | `active`, `noactive`, `banned`, `deleted` |
| `banned_at` | DATETIME | Дата бана |
| `ban_reason` | TEXT | Причина бана |
| `last_active_at` | DATETIME | Последняя активность |

**Индексы:**

- KEY: `idx_active_check` — `(status, last_active_at, created_at)`
- KEY: `idx_payout_method`, `idx_bank_id`, `idx_details_hash`

---

#### 5. `cashback_payout_methods` — Способы выплат

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `slug` | VARCHAR(50) UNIQUE | Идентификатор (sbp, mir и др.) |
| `name` | VARCHAR(100) | Название |
| `is_active` | TINYINT(1) | Активность |
| `sort_order` | INT | Порядок сортировки |

---

#### 6. `cashback_banks` — Справочник банков

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `bank_code` | VARCHAR(50) UNIQUE | Код банка (sber, tinkoff и др.) |
| `name` | VARCHAR(100) | Полное название |
| `short_name` | VARCHAR(50) | Краткое название |
| `is_active` | TINYINT(1) | Активность |
| `sort_order` | INT | Порядок сортировки |

**Индексы:** `idx_active_sort_name`, `idx_name_active`

---

#### 7. `cashback_affiliate_networks` — Партнёрские сети

**Назначение:** Справочник CPA-сетей с API-конфигурацией

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `name` | VARCHAR(255) | Название |
| `slug` | VARCHAR(100) UNIQUE | Идентификатор |
| `notes` | TEXT | Примечание |
| `sort_order` | INT | Порядок сортировки |
| `is_active` | TINYINT(1) | Активность |
| `api_base_url` | VARCHAR(255) | Базовый URL API (добавлено миграцией) |
| `api_credentials` | BLOB | Зашифрованные credentials (добавлено миграцией) |
| `api_user_field` | VARCHAR(100) | Поле для user ID в API |
| `api_click_field` | VARCHAR(100) | Поле для click ID в API |
| `api_status_map` | JSON | Маппинг статусов API → локальные |
| `api_actions_endpoint` | VARCHAR(255) | Эндпоинт получения действий |
| `api_token_endpoint` | VARCHAR(255) | Эндпоинт OAuth2 токена |
| `api_website_id` | VARCHAR(100) | ID площадки в CPA-сети |

---

#### 8. `cashback_affiliate_network_params` — Параметры сетей

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `network_id` | BIGINT FK | → cashback_affiliate_networks.id (CASCADE) |
| `param_name` | VARCHAR(100) | Название параметра (subid, click_id) |
| `param_type` | VARCHAR(100) | Тип значения |
| `default_value` | VARCHAR(255) | Значение по умолчанию |

---

#### 9. `cashback_click_log` — Лог кликов

**Назначение:** Серверное логирование всех кликов по партнёрским ссылкам

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `click_id` | CHAR(32) UNIQUE | UUID клика без дефисов, передаётся как subID |
| `user_id` | BIGINT | WP user ID (0 для гостей) |
| `session_id` | VARCHAR(128) | Идентификатор сессии |
| `product_id` | BIGINT | ID товара WooCommerce |
| `cpa_network` | VARCHAR(100) | Название CPA-сети |
| `offer_id` | VARCHAR(255) | ID оффера |
| `affiliate_url` | TEXT | Полный URL с параметрами |
| `ip_address` | VARCHAR(45) | IPv4/IPv6 адрес |
| `user_agent` | TEXT | User-Agent |
| `referer` | TEXT | Внутренний referer |
| `utm_source` | VARCHAR(255) | UTM source |
| `utm_medium` | VARCHAR(255) | UTM medium |
| `utm_campaign` | VARCHAR(255) | UTM campaign |
| `country` | VARCHAR(2) | Код страны (ISO 3166-1 alpha-2) |
| `spam_click` | TINYINT(1) | 1 = подозрительный клик |
| `created_at` | DATETIME(6) | Время клика (UTC) |

**Индексы:**

- UNIQUE: `click_id`
- KEY: `idx_user_id`, `idx_product_id`, `idx_cpa_network`, `idx_created_at`
- KEY: `idx_ip_address`, `idx_session_id`
- KEY: `idx_spam_by_ip`, `idx_spam_by_product` — составные для анализа

---

#### 10. `cashback_webhooks` — Сырые вебхуки

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `received_at` | DATETIME(6) | Дата получения |
| `payload` | LONGTEXT | JSON тело запроса |
| `network_slug` | VARCHAR(64) | Идентификатор сети |
| `payload_hash` | CHAR(64) | SHA-256(payload) — дедупликация |

**Индексы:** UNIQUE `payload_hash`, KEY `idx_received_at`, `idx_network_slug`

---

#### 11. `cashback_unregistered_transactions` — Незарегистрированные

**Назначение:** Вебхуки от пользователей, которых нет в системе

Структура аналогична `cashback_transactions`, но `user_id` — VARCHAR(255), без FK на `wp_users`.

---

#### 12. `cashback_audit_log` — Аудит-лог

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `action` | VARCHAR(100) | Тип действия |
| `actor_id` | BIGINT | ID пользователя-инициатора |
| `entity_type` | VARCHAR(50) | Тип сущности |
| `entity_id` | BIGINT | ID сущности |
| `ip_address` | VARCHAR(45) | IP адрес (с защитой от спуфинга) |
| `user_agent` | TEXT | User-Agent |
| `details` | LONGTEXT | Доп. данные в JSON |
| `created_at` | DATETIME | Дата создания |

**Индексы:** `idx_action_actor`, `idx_entity`, `idx_created`

---

#### 13. `cashback_support_tickets` — Тикеты поддержки

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `user_id` | BIGINT FK | → wp_users.ID (CASCADE) |
| `subject` | VARCHAR(255) | Тема |
| `priority` | ENUM | `urgent`, `normal`, `not_urgent` |
| `status` | ENUM | `open`, `answered`, `closed` |
| `created_at` | DATETIME | Дата создания |
| `updated_at` | DATETIME | Последнее обновление |
| `closed_at` | DATETIME | Дата закрытия |

---

#### 14. `cashback_support_messages` — Сообщения в тикетах

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `ticket_id` | BIGINT FK | → cashback_support_tickets.id (CASCADE) |
| `user_id` | BIGINT FK | → wp_users.ID (CASCADE) |
| `message` | TEXT | Текст сообщения |
| `is_admin` | TINYINT(1) | От админа? |
| `is_read` | TINYINT(1) | Прочитано? |
| `created_at` | DATETIME | Дата создания |

---

#### 15. `cashback_support_attachments` — Вложения

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `message_id` | BIGINT FK | → cashback_support_messages.id (CASCADE) |
| `ticket_id` | BIGINT FK | → cashback_support_tickets.id (CASCADE) |
| `user_id` | BIGINT FK | → wp_users.ID |
| `file_name` | VARCHAR(255) | Оригинальное имя файла |
| `stored_name` | VARCHAR(255) | Хеш-имя на диске (без расширения) |
| `file_size` | BIGINT | Размер в байтах |
| `mime_type` | VARCHAR(100) | MIME-тип (проверка через finfo) |
| `created_at` | DATETIME | Дата создания |

**Безопасность вложений:**

- Файлы хранятся без расширений (защита от прямого исполнения)
- MIME-тип определяется через `finfo_file()` (не из расширения)
- Директория: `/wp-content/cashback-support/` с `.htaccess` (deny from all)
- Допустимые типы: PDF, DOC/DOCX, XLS/XLSX, JPG/PNG, GIF (настраивается)
- Максимальный размер: 5 MB (настраивается)

---

#### 16. `cashback_fraud_alerts` — Антифрод: алерты

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `user_id` | BIGINT FK | → wp_users.ID (CASCADE) |
| `alert_type` | VARCHAR(50) | Тип алерта (см. ниже) |
| `severity` | ENUM | `low`, `medium`, `high`, `critical` |
| `risk_score` | DECIMAL(5,2) | Оценка риска 0-100 |
| `status` | ENUM | `open`, `reviewing`, `confirmed`, `dismissed` |
| `summary` | TEXT | Краткое описание |
| `details` | LONGTEXT | JSON с evidence |
| `reviewed_by` | BIGINT FK | ID админа-ревьюера |
| `reviewed_at` | DATETIME | Дата ревью |
| `review_note` | TEXT | Заметка ревьюера |

**Типы алертов:** `multi_account_ip`, `multi_account_fp`, `shared_details`, `cancellation_rate`, `velocity`, `amount_anomaly`, `new_account_risk`

---

#### 17. `cashback_fraud_signals` — Антифрод: сигналы

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `alert_id` | BIGINT FK | → cashback_fraud_alerts.id (CASCADE) |
| `signal_type` | VARCHAR(50) | Тип сигнала |
| `weight` | DECIMAL(5,2) | Вес (вклад в risk_score) |
| `evidence` | LONGTEXT | JSON с доказательствами |

**Типы сигналов:** `ip_match`, `fingerprint_match`, `shared_details`, `cancellation_rate`, `velocity`, `amount_spike`, `new_account_withdrawal`

---

#### 18. `cashback_user_fingerprints` — Антифрод: отпечатки

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `user_id` | BIGINT FK | → wp_users.ID (CASCADE) |
| `ip_address` | VARCHAR(45) | IP адрес |
| `fingerprint_hash` | CHAR(64) | SHA-256 browser fingerprint |
| `user_agent_hash` | CHAR(64) | SHA-256 User-Agent |
| `event_type` | ENUM | `login`, `page_view`, `withdrawal`, `registration` |
| `created_at` | DATETIME | Время записи |

**Индексы:** `idx_user_id`, `idx_ip_address`, `idx_fingerprint`, `idx_ip_user`, `idx_fingerprint_user`, `idx_created`

---

#### 19. `cashback_validation_checkpoints` — API: контрольные точки

**Назначение:** Результаты сверки локальных данных с API CPA-сетей

| Поле | Тип | Описание |
|------|-----|----------|
| `user_id` | BIGINT | ID пользователя |
| `network_slug` | VARCHAR(100) | Идентификатор сети |
| `last_validated_date` | DATE | Дата последней валидации |
| `api_sum_approved` | DECIMAL | Сумма одобренных в API |
| `api_sum_pending` | DECIMAL | Сумма pending в API |
| `api_sum_declined` | DECIMAL | Сумма отклонённых в API |
| `local_sum_approved` | DECIMAL | Сумма одобренных локально |
| `local_sum_pending` | DECIMAL | Сумма pending локально |
| `local_sum_declined` | DECIMAL | Сумма отклонённых локально |
| `validation_status` | ENUM | `match`, `mismatch`, `pending`, `error` |
| `discrepancy_amount` | DECIMAL | Размер расхождения |
| `matched_count` | INT | Количество совпадений |
| `mismatch_count` | INT | Количество расхождений |
| `missing_local_count` | INT | Отсутствуют локально |
| `missing_api_count` | INT | Отсутствуют в API |

---

#### 20. `cashback_sync_log` — API: лог синхронизации

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | BIGINT PK | Первичный ключ |
| `network_slug` | VARCHAR(100) | Идентификатор сети |
| `transaction_id` | BIGINT | ID локальной транзакции |
| `action_id` | VARCHAR(255) | ID действия в API |
| `old_status` | VARCHAR(50) | Старый статус |
| `new_status` | VARCHAR(50) | Новый статус |
| `api_payment` | DECIMAL | Сумма по API |
| `sync_type` | ENUM | `cron`, `manual`, `webhook`, `auto_decline` |
| `synced_at` | DATETIME | Время синхронизации |

---

### Триггеры

**Всего триггеров:** 16

#### Группа 1: Автоматический расчет кешбэка (4 триггера)

| Триггер | Событие | Таблица | Логика |
|---------|---------|---------|--------|
| `calculate_cashback_before_insert` | BEFORE INSERT | cashback_transactions | Читает `cashback_rate` из `user_profile`, `cashback = ROUND(comission * rate / 100, 2)` |
| `calculate_cashback_before_update` | BEFORE UPDATE | cashback_transactions | Пересчет при изменении `comission` (использует `applied_cashback_rate`) |
| `calculate_cashback_before_insert_unregistered` | BEFORE INSERT | cashback_unregistered_transactions | Фиксированная ставка 60% |
| `calculate_cashback_before_update_unregistered` | BEFORE UPDATE | cashback_unregistered_transactions | Пересчет при изменении `comission` |

#### Группа 2: Валидация переходов статусов (4 триггера)

| Триггер | Событие | Таблица | Логика |
|---------|---------|---------|--------|
| `cashback_tr_prevent_delete_final_status` | BEFORE DELETE | cashback_transactions | SIGNAL: запрет удаления со статусом `balance` |
| `cashback_tr_validate_status_transition` | BEFORE UPDATE | cashback_transactions | **Конечный автомат**: balance — полная блокировка; нельзя вернуть в waiting; в balance — только из completed; в hold — только из completed; из declined — только в completed |
| `cashback_tr_validate_status_transition_unregistered` | BEFORE UPDATE | cashback_unregistered_transactions | Аналогичные правила |

**Конечный автомат статусов транзакций:**

```
waiting ──→ completed ──→ balance (финальный)
   │            │
   │            ├──→ hold (заморожено рекламодателем)
   │            │
   ↓            ↓
declined ──→ completed (апелляция)
```

#### Группа 3: Защита финальных заявок на выплату (4 триггера)

| Триггер | Событие | Таблица | Логика |
|---------|---------|---------|--------|
| `tr_prevent_delete_paid_payout` | BEFORE DELETE | cashback_payout_requests | SIGNAL: запрет удаления `paid` |
| `tr_prevent_update_paid_payout` | BEFORE UPDATE | cashback_payout_requests | SIGNAL: запрет изменения `paid` |
| `tr_prevent_delete_failed_payout` | BEFORE DELETE | cashback_payout_requests | SIGNAL: запрет удаления `failed` |
| `tr_prevent_update_failed_payout` | BEFORE UPDATE | cashback_payout_requests | SIGNAL: запрет изменения `failed` |

#### Группа 4: Управление баном пользователя (4 триггера)

| Триггер | Событие | Таблица | Логика |
|---------|---------|---------|--------|
| `tr_banned_user_update_banned_at` | BEFORE UPDATE | cashback_user_profile | При смене на `banned`: `banned_at = NOW()` |
| `tr_freeze_balance_on_ban` | AFTER UPDATE | cashback_user_profile | При бане: `frozen += available + pending`, `available = 0`, `pending = 0` |
| `tr_clear_ban_on_unban` | BEFORE UPDATE | cashback_user_profile | При разбане: `banned_at = NULL`, `ban_reason = NULL` |
| `tr_unfreeze_balance_on_unban` | AFTER UPDATE | cashback_user_profile | При разбане: `available += frozen`, `frozen = 0` |

---

### MySQL события (Cron на уровне БД)

**Всего событий:** 4

| Событие | Расписание | Назначение |
|---------|-----------|-----------|
| `cashback_ev_confirmed_cashback` | Ежедневно | Начисление кешбэка: `completed` + `api_verified=1` + `spam_click=0` + возраст >= 7 дней → `balance`. Защита: `GET_LOCK`, `processed_batch_id = UUID()`, временная таблица |
| `cashback_ev_cleanup_cashback_webhooks_old` | Ежедневно | Удаление вебхуков старше 6 месяцев (LIMIT 5000) |
| `cashback_ev_cleanup_click_log` | Ежедневно | Удаление кликов старше 90 дней (LIMIT 5000) |
| `cashback_ev_mark_inactive_profiles` | Ежедневно | `active` → `noactive` если `last_active_at` > 6 месяцев (LIMIT 1000, с GET_LOCK) |

---

## Основные компоненты

### CashbackPlugin — Загрузчик

**Файл:** [cashback-plugin.php](cashback-plugin.php)

#### load_dependencies() — 29 файлов

```
includes/class-cashback-encryption.php
includes/class-cashback-user-status.php
mariadb.php, cashback-history.php, cashback-withdrawal.php
history-payout.php, wc-affiliate-url-params.php
admin/traits/AdminPaginationTrait.php
admin/payout-methods.php, admin/users-management.php
admin/payouts.php, admin/bank-management.php
admin/health-check.php, admin/click-log.php
admin/transactions.php, admin/statistics.php
partner/partner-management.php
support/support-db.php, support/admin-support.php, support/user-support.php
antifraud/class-fraud-db.php, class-fraud-settings.php
antifraud/class-fraud-collector.php, class-fraud-detector.php, class-fraud-admin.php
includes/class-cashback-api-client.php, class-cashback-api-migration.php
includes/class-cashback-api-cron.php
includes/class-cashback-shortcodes.php
admin/class-cashback-admin-api-validation.php
```

#### initialize_components() — 11 экземпляров

```php
Mariadb_Plugin::get_instance()
CashbackHistory::get_instance()
CashbackWithdrawal::get_instance()
HistoryPayout::get_instance()
new WC_Affiliate_URL_Params()
Cashback_User_Support::get_instance()
Cashback_Fraud_Collector::get_instance()
new Cashback_Fraud_Admin()              // только is_admin()
Cashback_Admin_API_Validation::get_instance()  // только is_admin()
Cashback_API_Cron::init()
Cashback_Shortcodes::get_instance()
```

---

### Mariadb_Plugin — Управление БД

**Файл:** [mariadb.php](mariadb.php)
**Паттерн:** Singleton

#### activate() — Последовательность

1. `create_tables()` — 11 таблиц + `create_audit_log_table()` + `add_table_constraints()`
2. `migrate_add_reference_id()` — добавление колонки + бэкфилл
3. `create_triggers()` — 16 триггеров
4. `create_events()` — 4 MySQL события
5. `initialize_existing_users()` — профили и балансы

#### generate_reference_id()

```php
public static function generate_reference_id(): string
```

- Формат: `WD-XXXXXXXX` (8 символов из безопасного алфавита)
- Алфавит: `23456789ABCDEFGHJKMNPQRSTUVWXYZ` (30 символов, без 0/O, 1/I/L)
- Генерация через `random_bytes()` — криптографически стойкая
- Коллизии обрабатываются через UNIQUE KEY + retry loop

---

### CashbackWithdrawal — Вывод кешбэка

**Файл:** [cashback-withdrawal.php](cashback-withdrawal.php)

#### Endpoint

- **URL:** `/my-account/cashback-withdrawal/`

#### AJAX обработчики

| Action | Nonce | Назначение |
|--------|-------|-----------|
| `process_cashback_withdrawal` | `cashback_withdrawal_nonce` | Создание заявки на выплату |
| `get_user_balance` | `cashback_withdrawal_nonce` | Получение текущего баланса |
| `save_payout_settings` | `cashback_withdrawal_nonce` | Сохранение реквизитов |
| `search_banks` | `cashback_withdrawal_nonce` | Автокомплит банков |

#### Защита от race conditions

**5 уровней защиты:**

1. **MySQL Named Lock:** `GET_LOCK('user_withdrawal_' . $user_id, 10)`
2. **Транзакция с SELECT FOR UPDATE:** Блокировка строки баланса
3. **Идемпотентность:** `hash('sha256', ...)` + UNIQUE KEY
4. **Оптимистичная блокировка:** `WHERE version = ?` → `version + 1`
5. **Reference ID:** `Mariadb_Plugin::generate_reference_id()` с retry

---

### CashbackHistory — История транзакций

**Файл:** [cashback-history.php](cashback-history.php)

#### Статусы транзакций

| Статус | Отображение | Описание |
|--------|------------|----------|
| `waiting` | В ожидании | Покупка зафиксирована |
| `completed` | Подтвержден | Подтверждено партнером |
| `hold` | На проверке | Удержание рекламодателем |
| `declined` | Отклонен | Отклонено партнером |
| `balance` | Зачислен на баланс | Начислено в `available_balance` |

---

### WC_Affiliate_URL_Params — Партнерские параметры

**Файл:** [wc-affiliate-url-params.php](wc-affiliate-url-params.php)

#### Сетевая архитектура

Вместо хранения параметров в post_meta каждого товара, используется привязка товара к партнёрской сети:

- Каждый внешний товар привязан к `cashback_affiliate_networks` через `_affiliate_network_id` (post_meta)
- Сеть определяет шаблон параметров через `cashback_affiliate_network_params`
- Автоматическая подстановка: `user` → partner_token (криптографически стойкий, 128 бит), `uuid` → click_id

#### Rate Limiting (двухуровневый)

```
PER-PRODUCT (IP + product_id):
  SPAM порог: > 3 клика за 60 сек
  BLOCK порог: >= 10 кликов

GLOBAL (IP, любой товар):
  SPAM порог: > 10 кликов за 60 сек
  BLOCK порог: >= 60 кликов (CGNAT-safe)
```

**Прогрессия:** normal → spam (логируется, `spam_click=1`) → blocked (HTTP 429)

#### Click Tracking

- Серверный редирект через `handle_click_redirect()`
- Запись в `cashback_click_log`: user_id, product_id, click_id (UUID), IP, timestamp
- `click_id` связывает клик → транзакцию для диспутов с CPA

#### Cashback Display

- Отображение размера кешбэка на карточке и странице товара
- Рассчитывается на основе индивидуальной ставки пользователя + сети/товара
- Для гостей: предупреждение "Зарегистрируйтесь для получения кешбэка"

---

### Cashback_Encryption — Шифрование

**Файл:** [includes/class-cashback-encryption.php](includes/class-cashback-encryption.php)

#### Алгоритм

**v2: AES-256-GCM (authenticated encryption):**

- Ключ: `CB_ENCRYPTION_KEY` (64 hex = 32 байта)
- IV: 12 байт (уникальный для каждой записи)
- Auth Tag: 16 байт
- Формат: `"v2:" . base64(iv[12] . tag[16] . ciphertext)`

**v1: AES-256-CBC (legacy, только чтение):**

- IV: 16 байт
- Формат: `"v1:" . base64(iv[16] . ciphertext)` или `base64(iv[16] . ciphertext)` (без префикса)

#### Ключевые методы

```php
public static function encrypt(string $plaintext): string     // → "v2:base64(...)"
public static function decrypt(string $encrypted): string     // auto-detect v1/v2
public static function is_configured(): bool                  // CB_ENCRYPTION_KEY валиден?
public static function is_legacy_encrypted(string $encrypted): bool

public static function encrypt_details(array $details): array
// Возвращает: encrypted_details, masked_details (JSON), details_hash (SHA-256)

public static function decrypt_details(string $encrypted): array
// Возвращает: ['account' => ..., 'full_name' => ..., 'bank' => ...]

public static function mask_account(string $account): string
// "4276 1234 5678 4523" → "**** **** **** 4523"
// "+79031234567" → "+7903***4567"

public static function mask_name(string $name): string
// "Иванов Петр Сидорович" → "И**** П**** С****"

public static function hash_details(array $details): string
// SHA-256 от канонического JSON (lowercase, без пробелов, ksort)
```

#### Аудит-лог

```php
public static function write_audit_log(
    string $action,
    int $actor_id,
    ?string $entity_type = null,
    ?int $entity_id = null,
    ?array $extra_details = null
): void
```

#### Защита от IP Spoofing

```php
public static function get_client_ip(): string
```

- Прокси-заголовки (X-Forwarded-For, CF-Connecting-IP) читаются **только** если `REMOTE_ADDR` ∈ `CASHBACK_TRUSTED_PROXIES`
- Из прокси-заголовков принимаются **только публичные IP** (не приватные/зарезервированные)
- Цепочка X-Forwarded-For: берётся первый IP

---

## Антифрод-модуль

### Cashback_Fraud_DB — Таблицы

**Файл:** [antifraud/class-fraud-db.php](antifraud/class-fraud-db.php)

Создаёт 3 таблицы: `cashback_fraud_alerts`, `cashback_fraud_signals`, `cashback_user_fingerprints`.

**Очистка:** `cleanup_old_data()` — удаляет fingerprints старше N дней, dismissed алерты старше 90 дней.

### Cashback_Fraud_Settings — Настройки

**Файл:** [antifraud/class-fraud-settings.php](antifraud/class-fraud-settings.php)

14 настроек в `wp_options` с префиксом `cashback_fraud_`:

| Настройка | Тип | Default | Описание |
|-----------|-----|---------|----------|
| `fraud_enabled` | bool | true | Включить антифрод |
| `max_users_per_ip` | int | 3 | Макс. пользователей с одного IP |
| `max_users_per_fingerprint` | int | 2 | Макс. пользователей с одного fingerprint |
| `max_withdrawals_per_day` | int | 3 | Макс. выводов в день |
| `max_withdrawals_per_week` | int | 7 | Макс. выводов в неделю |
| `cancellation_rate_threshold` | float | 50.0 | Порог отмен (%) |
| `cancellation_min_transactions` | int | 5 | Мин. транзакций для проверки отмен |
| `amount_anomaly_multiplier` | float | 5.0 | Множитель аномалии суммы |
| `new_account_cooling_days` | int | 7 | Дни до первого вывода |
| `auto_hold_amount` | float | 5000.00 | Авто-холд при сумме выше |
| `max_accounts_per_details_hash` | int | 1 | Макс. аккаунтов на одни реквизиты |
| `fingerprint_retention_days` | int | 180 | Хранение fingerprints (дни) |
| `auto_flag_threshold` | float | 70.0 | Порог авто-флага |
| `email_notification_enabled` | bool | true | Email админу при алерте |

### Cashback_Fraud_Collector — Сбор данных

**Файл:** [antifraud/class-fraud-collector.php](antifraud/class-fraud-collector.php)

**Собирает:**

- IP адрес (через `get_client_ip()`)
- Browser fingerprint hash (SHA-256, от FingerprintJS на клиенте)
- User-Agent hash (SHA-256)
- Тип события: `login`, `page_view`, `withdrawal`, `registration`

**AJAX:** `cashback_fraud_fingerprint` — приём fingerprint от клиента (rate limit: 1 раз в 10 минут на пользователя)

**Хуки:** `wp_login` (login event), `user_register` (registration event)

### Cashback_Fraud_Detector — Детектор

**Файл:** [antifraud/class-fraud-detector.php](antifraud/class-fraud-detector.php)

#### 7 автоматических проверок

| Проверка | Макс. score | Описание |
|----------|------------|----------|
| `check_shared_ip()` | 15.0 | Несколько пользователей с одного IP |
| `check_shared_fingerprint()` | 30.0 | Несколько пользователей с одним fingerprint |
| `check_shared_payment_details()` | 40.0 | Одни реквизиты на разных аккаунтах (по `details_hash`) |
| `check_cancellation_rate()` | 0-25.0 | Высокий % отклонённых транзакций |
| `check_withdrawal_velocity()` | 20.0 | Превышение лимитов частоты вывода |
| `check_amount_anomalies()` | 20.0 | Аномальный скачок суммы кешбэка |
| `check_new_account_withdrawals()` | 15.0 | Вывод с нового аккаунта (cooling period) |

#### Severity mapping

- 0-25: `low`
- 26-50: `medium`
- 51-75: `high`
- 76-100: `critical`

#### Дедупликация алертов

```php
private static function alert_exists(int $user_id, string $alert_type): bool
```

- `open`/`reviewing` — всегда считаются активными
- `confirmed`/`dismissed` — в пределах 30 дней

#### Cron

```php
add_action('cashback_fraud_detection_cron', function(): void {
    Cashback_Fraud_Detector::run_all_checks();
});
```

Расписание: `hourly` (каждый час)

### Cashback_Fraud_Admin — Админ-панель

**Файл:** [antifraud/class-fraud-admin.php](antifraud/class-fraud-admin.php)

**Страница:** `cashback-antifraud` (подменю Кэшбэк)

**Вкладки:**

1. **Уведомления** — Dashboard алертов
2. **Подозрительные** — Ревью и управление
3. **Настройки** — Конфигурация порогов

**Badge:** Количество open алертов в меню (кешируется на 1 час)

**AJAX handlers:**

| Action | Назначение |
|--------|-----------|
| `fraud_review_alert` | Смена статуса алерта |
| `fraud_get_alert_details` | Полные данные + сигналы |
| `fraud_save_settings` | Сохранение настроек |
| `fraud_run_scan_now` | Немедленный запуск сканирования |
| `fraud_ban_user` | Бан из модального окна алерта |

---

## API-интеграция с CPA-сетями

### Cashback_API_Client — API-клиент

**Файл:** [includes/class-cashback-api-client.php](includes/class-cashback-api-client.php)
**Паттерн:** Singleton

**Поддерживаемые сети:** Admitad (api.admitad.com), EPN (api.epn.bz)

#### Ключевые методы

```php
public function save_credentials(int $network_id, array $credentials): bool
// AES-256 шифрование перед сохранением

public function background_sync(): array
// Синхронизация статусов со всех сетей

public function validate_user(int $user_id, string $network_slug): array
// Сверка транзакций пользователя с API

public function get_oauth_token(int $network_id): ?string
// OAuth2 токен с кешированием
```

#### OAuth2 Flow

1. `POST {api_token_endpoint}` с `client_id`, `client_secret`
2. Кеширование токена (request scope)
3. `Authorization: Bearer {token}` для запросов
4. Автоматический refresh при истечении

#### Reconciliation

- **Match:** `API.subid1` == `DB.click_id` (UUID из cashback_click_log)
- **Compare:** status, payment/comission, cart/sum_order
- **Filter:** `API.subid2` == `DB.user_id`

### Cashback_API_Migration — Миграции

**Файл:** [includes/class-cashback-api-migration.php](includes/class-cashback-api-migration.php)

Создаёт таблицы `cashback_validation_checkpoints` и `cashback_sync_log`, добавляет API-колонки в `cashback_affiliate_networks`.

### Cashback_API_Cron — Фоновая синхронизация

**Файл:** [includes/class-cashback-api-cron.php](includes/class-cashback-api-cron.php)

**Cron hook:** `cashback_api_sync_statuses`
**Интервал:** `cashback_every_2_hours` (7200 секунд)

**Что делает:**

1. Вызывает `Cashback_API_Client::background_sync()`
2. Обновляет локальные статусы транзакций
3. Логирует результаты в `cashback_sync_log`
4. Сохраняет результат в `wp_option` для отображения в админке

**Методы:** `run_sync()`, `manual_sync()`, `deactivate()`

### Cashback_Admin_API_Validation — Админ-страница

**Файл:** [admin/class-cashback-admin-api-validation.php](admin/class-cashback-admin-api-validation.php)

**Страница:** `cashback-api-validation` (подменю Кэшбэк)

**Вкладки:**

1. **Credentials** — API конфигурация сетей
2. **Validation Results** — Статус контрольных точек
3. **Sync Log** — История синхронизации

**AJAX handlers:**

| Action | Назначение |
|--------|-----------|
| `cashback_validate_user` | Валидация пользователя через API |
| `cashback_save_api_credentials` | Обновление credentials |
| `cashback_manual_sync` | Запуск синхронизации вручную |
| `cashback_get_sync_log` | Получение лога с пагинацией |
| `cashback_get_validation_status` | Статус checkpoint для user × network |
| `cashback_edit_transaction` | Inline-редактирование транзакции |
| `cashback_add_transaction` | Добавление транзакции из API |
| `cashback_overwrite_transaction` | Замена локальных данных на API |

---

## Административные компоненты

### Cashback_Statistics_Admin — Статистика

**Файл:** [admin/statistics.php](admin/statistics.php)
**Паттерн:** Singleton

**KPI дашборд:**

1. **Комиссии:** Общая сумма комиссий
2. **Кешбэк:** Общая сумма кешбэка
3. **Профит:** Комиссия − Кешбэк
4. **Транзакции:** Разбивка по статусам (completed, waiting + balance, declined)
5. **Выплаты:** По статусам (paid, processing, waiting, failed, declined)
6. **Балансы:** Available, pending, paid, frozen
7. **Фильтры:** date_from, date_to (YYYY-MM-DD)

---

### Cashback_Transactions_Admin — Транзакции

**Файл:** [admin/transactions.php](admin/transactions.php)

**Вкладки:**

1. **Зарегистрированные** — с привязкой к wp_users
2. **Незарегистрированные** — анонимные покупки

**Возможности:**

- Пагинация (20 записей)
- Фильтры: статус, поиск
- Inline-редактирование

---

### Cashback_Click_Log_Admin — Лог кликов

**Файл:** [admin/click-log.php](admin/click-log.php)
**Trait:** `AdminPaginationTrait`

**Отображает:** user_id, product_id, click_id (UUID), IP, timestamp, CPA-сеть, spam_click
**Фильтры:** дата, пользователь, товар

---

### Cashback_Partner_Management_Admin — Управление партнёрами

**Файл:** [partner/partner-management.php](partner/partner-management.php)

**AJAX handlers:**

| Action | Nonce | Назначение |
|--------|-------|-----------|
| `update_partner` | `update_partner_nonce` | Редактирование сети |
| `add_partner` | `add_partner_nonce` | Добавление сети |
| `save_network_params` | `save_network_params_nonce` | Bulk-сохранение параметров |
| `get_network_params` | `get_network_params_nonce` | Получение параметров сети |
| `delete_network_param` | `delete_network_param_nonce` | Удаление параметра |
| `update_network_param` | `update_network_param_nonce` | Редактирование параметра |

---

### Cashback_Payouts_Admin — Выплаты

**Файл:** [admin/payouts.php](admin/payouts.php)

#### Конечный автомат статусов

```
waiting ──┬──→ processing
          ├──→ paid
          ├──→ failed
          ├──→ declined
          └──→ needs_retry

processing ──┬──→ paid
             ├──→ failed
             ├──→ declined
             └──→ needs_retry

needs_retry ──┬──→ processing
              ├──→ paid
              ├──→ failed
              └──→ declined

paid ───→ (финальный, защищён триггером)
failed ───→ (финальный, защищён триггером)
declined ───→ (финальный)
```

#### Логика обновления баланса

- **paid:** `pending -= amount`, `paid += amount`
- **failed:** `pending -= amount`, `available += amount`, `refunded_at = NOW()`
- **declined:** `pending -= amount`, `frozen += amount`

#### Расшифровка реквизитов

Доступна только при `status = 'processing'`, записывается в аудит-лог.

---

### Cashback_Users_Management_Admin — Пользователи

**Файл:** [admin/users-management.php](admin/users-management.php)

#### Логика бана

1. START TRANSACTION
2. Поиск и блокировка активных заявок (FOR UPDATE)
3. Отклонение заявок → `declined` с причиной "(Аккаунт забанен)"
4. Триггер `tr_freeze_balance_on_ban` замораживает балансы
5. Аудит-лог
6. COMMIT
7. Email-уведомление пользователю

#### Логика разбана

1. Обновление причин: "(Аккаунт забанен)" → "(Аккаунт был забанен)"
2. Триггер `tr_unfreeze_balance_on_unban` размораживает
3. Аудит-лог

---

## Модуль поддержки

### Cashback_User_Support

**Файл:** [support/user-support.php](support/user-support.php)
**Endpoint:** `/my-account/cashback-support/`

**Rate Limiting:**

- Создание тикета: 1 раз в 5 минут
- Ответ: 5 в минуту
- Загрузка файла: 3 в минуту
- Через transients: `cb_support_rate_{user_id}_{action}`

**AJAX:**

- `support_create_ticket`
- `support_user_reply`
- `support_user_close_ticket`
- `support_load_ticket`
- `support_download_file`

---

## API и точки расширения

### WordPress хуки

#### Actions

| Хук | Файл | Назначение |
|-----|------|-----------|
| `user_register` | mariadb.php | Инициализация нового пользователя |
| `plugins_loaded` | cashback-plugin.php | Загрузка и инициализация |
| `init` | cashback-plugin.php | Загрузка переводов |
| `before_woocommerce_init` | cashback-plugin.php | HPOS совместимость |
| `admin_menu` | admin/*.php | Добавление страниц в админку |
| `wp_login` | class-fraud-collector.php | Запись fingerprint при логине |
| `cashback_health_check_cron` | health-check.php | Ежедневная проверка целостности |
| `cashback_support_auto_delete_cron` | support-db.php | Удаление старых тикетов |
| `cashback_fraud_detection_cron` | class-fraud-detector.php | Ежечасная антифрод-проверка |
| `cashback_fraud_cleanup_cron` | class-fraud-db.php | Ежедневная очистка fingerprints |
| `cashback_api_sync_statuses` | class-cashback-api-cron.php | Синхронизация с CPA каждые 2 часа |

#### Filters

| Хук | Файл | Назначение |
|-----|------|-----------|
| `woocommerce_account_menu_items` | cashback-*.php, user-support.php | Пункты в меню личного кабинета |
| `woocommerce_product_add_to_cart_url` | wc-affiliate-url-params.php | Модификация URL внешних товаров |
| `woocommerce_loop_add_to_cart_link` | wc-affiliate-url-params.php | Добавление data-атрибутов к ссылкам |
| `cron_schedules` | class-cashback-api-cron.php | Регистрация 2-часового интервала |

#### Shortcodes

| Шорткод | Файл | Назначение |
|---------|------|-----------|
| `cashback_balance` | class-cashback-shortcodes.php | Баланс авторизованного пользователя |

### WooCommerce endpoints

| Endpoint | Класс | URL |
|----------|-------|-----|
| `cashback-withdrawal` | CashbackWithdrawal | `/my-account/cashback-withdrawal/` |
| `cashback-history` | CashbackHistory | `/my-account/cashback-history/` |
| `history-payout` | HistoryPayout | `/my-account/history-payout/` |
| `cashback-support` | Cashback_User_Support | `/my-account/cashback-support/` |

### AJAX обработчики

#### Фронтенд (требуют авторизации)

| Action | Класс | Nonce | Назначение |
|--------|-------|-------|-----------|
| `process_cashback_withdrawal` | CashbackWithdrawal | `cashback_withdrawal_nonce` | Создание заявки |
| `get_user_balance` | CashbackWithdrawal | `cashback_withdrawal_nonce` | Получение баланса |
| `save_payout_settings` | CashbackWithdrawal | `cashback_withdrawal_nonce` | Сохранение реквизитов |
| `search_banks` | CashbackWithdrawal | `cashback_withdrawal_nonce` | Автокомплит банков |
| `load_page_transactions` | CashbackHistory | `load_page_transactions_nonce` | Пагинация транзакций |
| `load_page_payouts` | HistoryPayout | `load_page_payouts_nonce` | Пагинация выплат |
| `cashback_fraud_fingerprint` | Cashback_Fraud_Collector | nonce | Приём fingerprint |
| `support_create_ticket` | Cashback_User_Support | nonce | Создание тикета |
| `support_user_reply` | Cashback_User_Support | nonce | Ответ в тикете |
| `support_user_close_ticket` | Cashback_User_Support | nonce | Закрытие тикета |
| `support_load_ticket` | Cashback_User_Support | nonce | Загрузка тикета |
| `support_download_file` | Cashback_User_Support | nonce | Скачивание файла |

#### Админка (требуют `manage_options`)

| Action | Класс | Назначение |
|--------|-------|-----------|
| `update_payout_request` | Cashback_Payouts_Admin | Изменение статуса выплаты |
| `get_payout_request` | Cashback_Payouts_Admin | Получение данных заявки |
| `decrypt_payout_details` | Cashback_Payouts_Admin | Расшифровка реквизитов |
| `update_user_profile` | Cashback_Users_Management_Admin | Обновление профиля |
| `get_user_profile` | Cashback_Users_Management_Admin | Получение данных профиля |
| `update_bank` | Cashback_Bank_Management_Admin | Обновление банка |
| `add_bank` | Cashback_Bank_Management_Admin | Добавление банка |
| `update_payout_method` | Cashback_Payout_Methods_Admin | Обновление способа выплат |
| `add_payout_method` | Cashback_Payout_Methods_Admin | Добавление способа выплат |
| `update_partner` | Cashback_Partner_Management_Admin | Редактирование сети |
| `add_partner` | Cashback_Partner_Management_Admin | Добавление сети |
| `save_network_params` | Cashback_Partner_Management_Admin | Параметры сети |
| `get_network_params` | Cashback_Partner_Management_Admin | Получение параметров |
| `delete_network_param` | Cashback_Partner_Management_Admin | Удаление параметра |
| `update_network_param` | Cashback_Partner_Management_Admin | Редактирование параметра |
| `fraud_review_alert` | Cashback_Fraud_Admin | Ревью алерта |
| `fraud_get_alert_details` | Cashback_Fraud_Admin | Данные алерта |
| `fraud_save_settings` | Cashback_Fraud_Admin | Настройки антифрода |
| `fraud_run_scan_now` | Cashback_Fraud_Admin | Запуск сканирования |
| `fraud_ban_user` | Cashback_Fraud_Admin | Бан из алерта |
| `cashback_validate_user` | Cashback_Admin_API_Validation | Валидация через API |
| `cashback_save_api_credentials` | Cashback_Admin_API_Validation | Сохранение credentials |
| `cashback_manual_sync` | Cashback_Admin_API_Validation | Ручная синхронизация |
| `cashback_get_sync_log` | Cashback_Admin_API_Validation | Лог синхронизации |
| `cashback_get_validation_status` | Cashback_Admin_API_Validation | Статус валидации |
| `cashback_edit_transaction` | Cashback_Admin_API_Validation | Редактирование транзакции |
| `cashback_add_transaction` | Cashback_Admin_API_Validation | Добавление транзакции |
| `cashback_overwrite_transaction` | Cashback_Admin_API_Validation | Перезапись транзакции |

### WP Cron задачи

| Хук | Расписание | Файл | Назначение |
|-----|-----------|------|-----------|
| `cashback_health_check_cron` | daily | health-check.php | Проверка целостности + email |
| `cashback_support_auto_delete_cron` | daily | support-db.php | Удаление закрытых тикетов > 1 месяц |
| `cashback_fraud_detection_cron` | hourly | class-fraud-detector.php | 7 антифрод-проверок |
| `cashback_fraud_cleanup_cron` | daily | class-fraud-db.php | Очистка старых fingerprints |
| `cashback_api_sync_statuses` | every 2h | class-cashback-api-cron.php | Синхронизация с CPA-сетями |

---

## Шорткод баланса

### Cashback_Shortcodes

**Файл:** [includes/class-cashback-shortcodes.php](includes/class-cashback-shortcodes.php)
**Паттерн:** Singleton

Регистрирует шорткод `[cashback_balance]` для вывода кешбэк-баланса авторизованного пользователя в любом месте сайта (посты, страницы, виджеты, шаблоны темы).

#### Атрибуты шорткода

| Атрибут | Значения | По умолчанию | Описание |
|---------|---------|-------------|----------|
| `type` | `available`, `pending`, `paid`, `all` | `available` | Тип отображаемого баланса |
| `format` | `widget`, `number` | `widget` | `widget` — с подписью и `₽`, `number` — только цифра |
| `guest` | `hide`, `login_link`, `text` | `hide` | Что показывать незалогиненным пользователям |
| `decimals` | целое число | `2` | Количество знаков после запятой |

#### Примеры использования

```
[cashback_balance]
```
Доступный баланс: `Баланс: 1 234,56 ₽`

```
[cashback_balance type="all"]
```
Блок со всеми тремя строками: доступный / в обработке / выплачено

```
[cashback_balance type="pending"]
[cashback_balance type="paid"]
```
Отдельный тип баланса

```
[cashback_balance format="number"]
```
Только число для встройки в текст: `1 234,56`

```
[cashback_balance guest="login_link"]
[cashback_balance guest="text"]
```
Для незалогиненных: ссылка на страницу входа / текст «Доступно после авторизации»

```
[cashback_balance type="available" decimals="0"]
```
Без копеек

#### Использование в PHP

```php
echo do_shortcode('[cashback_balance type="all"]');
```

#### CSS-классы

| Класс | Элемент |
|-------|---------|
| `.cashback-balance` | Обёртка inline-варианта (один тип) |
| `.cashback-balance--available/pending/paid` | Модификатор типа |
| `.cashback-balance__label` | Подпись |
| `.cashback-balance__amount` | Сумма |
| `.cashback-balance-widget` | Обёртка `type="all"` |
| `.cashback-balance-widget__row` | Строка виджета |
| `.cashback-balance-widget__row--available/pending/paid` | Модификатор строки |
| `.cashback-balance-widget__label` | Подпись строки |
| `.cashback-balance-widget__amount` | Сумма строки |
| `.cashback-balance__login-link` | Ссылка входа для гостей |
| `.cashback-balance__guest` | Текст для гостей |

---

## Безопасность

### Шифрование

#### AES-256-GCM (v2) — текущий стандарт

- **Authenticated encryption:** встроенная проверка целостности (auth tag)
- Ключ: 256 бит, IV: 12 байт, Tag: 16 байт
- Формат: `"v2:" . base64(iv . tag . ciphertext)`

#### AES-256-CBC (v1) — обратная совместимость

- Только для чтения старых данных
- Новые записи всегда шифруются GCM

#### Что шифруется

- Номер счёта/карты/телефона
- ФИО получателя
- Код банка
- API credentials CPA-сетей

### Защита от Click Fraud

**Двухуровневый rate limiting:**

1. **Per-product:** IP + product_id — 3 клика/60с (spam), 10 (block)
2. **Global:** IP — 10 кликов/60с (spam), 60 (block, CGNAT-safe)

**Дополнительно:**

- Bot-detection по User-Agent (отдельный слой)
- `spam_click=1` → кешбэк только после ручной проверки
- Логирование всех кликов в `cashback_click_log`
- `click_id` для связи клик → транзакция

### Антифрод-детекция

- 7 автоматических проверок (ежечасно)
- Browser fingerprinting (FingerprintJS)
- Risk scoring (0-100) с severity levels
- Дедупликация алертов (30 дней)
- Email-уведомления администратору
- Возможность бана из модального окна алерта

### Защита файлов вложений

- Файлы хранятся **без расширений** (защита от прямого исполнения)
- MIME-тип определяется через `finfo_file()` (не из расширения)
- Директория защищена `.htaccess` (deny from all)
- Белый список расширений (настраивается)
- Лимит размера файла (настраивается)

### Защита от IP Spoofing

```php
Cashback_Encryption::get_client_ip()
```

- Прокси-заголовки читаются **только** если `REMOTE_ADDR` ∈ `CASHBACK_TRUSTED_PROXIES`
- Из заголовков принимаются **только публичные IP** (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`)
- Приоритет: CF-Connecting-IP → X-Forwarded-For → X-Real-IP

### Аудит-лог

**Типы действий:**

- `payout_details_decrypted` — Расшифровка реквизитов
- `payout_declined_on_ban` — Отклонение при бане
- `user_banned` — Бан пользователя
- `user_unbanned` — Разбан пользователя

**Сохраняется:** IP (с защитой от спуфинга), User-Agent, timestamp, entity, details (JSON)

### Защита от Race Conditions

1. **MySQL Named Locks:** `GET_LOCK('user_withdrawal_' . $user_id, 10)`
2. **SELECT FOR UPDATE:** Блокировка строк в транзакции
3. **Оптимистичная блокировка:** `WHERE version = ?` → `version + 1`
4. **Идемпотентность:** UNIQUE KEY на `idempotency_key`
5. **MySQL Event Lock:** `GET_LOCK('cashback_event_lock', 0)` + `processed_batch_id`

### Защита от SQL Injection

Все SQL через `$wpdb->prepare()` с `%d`, `%s`, `%f`, `%i`.

### Защита от XSS

`esc_html()`, `esc_attr()`, `esc_url()`, `wp_json_encode()`, `wp_kses_post()`.

### CSRF Protection

Все формы и AJAX через WordPress nonces: `wp_nonce_field()`, `wp_verify_nonce()`, `check_ajax_referer()`.

---

## Ключевые файлы для модификации

### Изменения схемы БД

**Файл:** [mariadb.php](mariadb.php)

- `create_tables()` — добавление новых таблиц
- `create_triggers()` — модификация триггеров (конечный автомат статусов)
- `create_events()` — изменение MySQL событий
- Миграции: создавать `migrate_*()` и вызывать в `activate()`

### Логика выплат

**Файл:** [cashback-withdrawal.php](cashback-withdrawal.php)

**Важно:** Сохранять 5 уровней защиты от race conditions, шифрование реквизитов, reference_id.

### Криптография

**Файл:** [includes/class-cashback-encryption.php](includes/class-cashback-encryption.php)

**Важно:**

- НЕ изменять формат v2 (GCM) без миграции
- НЕ удалять поддержку v1 (CBC) пока есть старые данные
- `write_audit_log()` сигнатура: `(action, actor_id, ?entity_type, ?entity_id, ?extra_details)`

### Антифрод

**Файл:** [antifraud/class-fraud-detector.php](antifraud/class-fraud-detector.php)

**Важно:** Дедупликация алертов через `alert_exists()`, severity mapping.

### Партнёрские ссылки

**Файл:** [wc-affiliate-url-params.php](wc-affiliate-url-params.php)

**Важно:** Rate limiting (2 уровня), click tracking с UUID, spam_click flag.

---

## Разработка и тестирование

### PHPStan

```bash
cd development
composer install
vendor/bin/phpstan analyse
```

**Конфигурация:** [development/phpstan.neon](development/phpstan.neon) (level 5)

### PHPCS

```bash
cd development
vendor/bin/phpcs ../ --standard=WordPress --extensions=php --ignore=development/,assets/
```

### MySQL

```sql
-- Триггеры
SHOW TRIGGERS LIKE 'cashback_%';

-- События
SHOW EVENTS WHERE Db = 'database_name';

-- Event scheduler (обязательно ON)
SET GLOBAL event_scheduler = ON;
```

---

## Контакты и ресурсы

### Документация

- **README:** [INSTALL.md](INSTALL.md)
- **Changelog:** [development/docs/CHANGELOG.md](development/docs/CHANGELOG.md)
- **Security:** [development/docs/SECURITY.md](development/docs/SECURITY.md)

### Серверные требования

- MySQL >= 5.7 или MariaDB >= 10.2
- PHP расширение `openssl`
- `event_scheduler = ON` в MySQL
- Запись в `wp-content/` (для ключа шифрования)
- Запись в `wp-content/cashback-support/` (для вложений)

---

## Принципы разработки

- Безопасность прежде всего
- Транзакционность критических операций
- Идемпотентность запросов
- Аудит чувствительных действий
- Валидация на всех уровнях
- Authenticated encryption (GCM)
- Rate limiting на всех публичных эндпоинтах

# Cashback Plugin — Документация функционала

## Общее описание

Cashback Plugin — это комплексная система кэшбэк-сервиса, состоящая из двух основных компонентов:

1. **WordPress/WooCommerce плагин** — пользовательский интерфейс, административная панель, управление пользователями, выплатами, партнёрской программой и т.д.
2. **Webhook Receiver (отдельный микросервис на Python/FastAPI)** — сервис приёма постбэков от CPA-сетей, находящийся в папке `server-stack/webhook-receiver/`. Это **не часть WordPress плагина**, а самостоятельный сервис.

---

## Часть 1: WordPress/WooCommerce Плагин

### 1. Ядро системы (Core)

#### cashback-plugin.php

- **Главный файл плагина** — точка входа, инициализация всех компонентов
- Генерация UUID v7 (RFC 9562) — time-ordered идентификаторы для транзакций, кликов и т.д.
- Проверка системных требований (PHP 7.4+, WordPress 6.2+, WooCommerce 5.0+)
- Проверка наличия расширения BCMath (обязательно для точных вычислений с балансами)
- Автоматическая генерация ключа шифрования при активации
- Управление WordPress Cron задачами
- Регистрация всех custom endpoints для WooCommerce My Account
- Объявление совместимости с HPOS (Custom Order Tables) и Cart/Checkout Blocks

#### mariadb.php

- **Управление базой данных** — создание всех таблиц, триггеров, событий MySQL
- Создание таблиц:
  - `cashback_transactions` — транзакции зарегистрированных пользователей
  - `cashback_unregistered_transactions` — транзакции незарегистрированных пользователей
  - `cashback_user_balance` — балансы пользователей (available, pending, paid, frozen)
  - `cashback_user_profile` — профили пользователей (ставки, способы выплат, статусы)
  - `cashback_payout_requests` — заявки на вывод средств
  - `cashback_payout_methods` — справочник способов выплат (СБП, МИР, ЮMoney и т.д.)
  - `cashback_banks` — справочник банков
  - `cashback_affiliate_networks` — партнёрские CPA-сети (Admitad, EPN и др.)
  - `cashback_affiliate_network_params` — параметры CPA-сетей
  - `cashback_click_log` — логирование кликов по партнёрским ссылкам
  - `cashback_webhooks` — сырые webhook'и от CPA-сетей (дедупликация по SHA-256)
  - `cashback_sync_log` — лог синхронизации с API
  - `cashback_validation_checkpoints` — чекпоинты валидации API
  - `cashback_audit_log` — аудит-лог изменений
  - `cashback_rate_history` — история изменений ставок кэшбэка
- Создание MySQL триггеров:
  - Автоматический расчёт кэшбэка при вставке/обновлении транзакций
  - Валидация статусных переходов (нельзя изменить финальный статус)
  - Защита от удаления транзакций с финальным статусом
  - Автоматическая заморозка/разморозка баланса при бане/разбане
- Создание MySQL Events для фоновых задач (очистка старых данных)
- Автоматические миграции при обновлении плагина

#### wc-affiliate-url-params.php

- **Управление партнёрскими URL для внешних товаров WooCommerce**
- Добавление полей в админку товара:
  - Выбор CPA-сети для товара
  - Offer ID (ID кампании в CPA-сети)
  - Индивидуальные параметры товара (до 5 параметров)
  - Настройки отображения кэшбэка на карточке товара
  - Домен магазина для браузерного расширения
  - Режим всплывающего окна браузерного расширения
- Автоматическое заполнение домена из URL товара
- Автоматическая деактивация товара при отключении кампании в CPA-сети
- **Rate limiting кликов** (двухуровневый: per-product и global, CGNAT-safe)
- Модификация URL товаров на фронтенде с подстановкой партнёрских параметров
- Отображение размера кэшбэка на карточках товаров
- Серверный redirect endpoint для логирования кликов

#### cashback-history.php

- **История покупок пользователя** (WooCommerce My Account endpoint)
- Отображение транзакций с AJAX-пагинацией (10 записей на страницу)
- Фильтры: по дате, магазину, статусу, поиску
- Статусы: В ожидании, Подтвержден, На проверке, Отклонен, Зачислен на баланс
- Защита от DoS (макс. 1000 страниц)
- Rate limiting AJAX запросов (30 запросов/мин)

#### cashback-withdrawal.php

- **Вывод кэшбэка** (WooCommerce My Account endpoint)
- Отображение трёх карточек баланса: Доступный, В обработке, Заработано
- Форма вывода кэшбэка с валидацией минимальной суммы
- Вкладки: «Вывод кэшбэка» и «Настройки вывода»
- Настройки платёжных реквизитов:
  - Выбор способа выплаты (СБП, МИР, ЮMoney и т.д.)
  - Ввод номера счёта/телефона
  - Выбор банка (для способов, требующих банк)
- **AES-256 шифрование реквизитов** (encrypted_details)
- Маскирование реквизитов для отображения
- Проверка активности платёжной системы и банка
- CAPTCHA для «серых» IP (бот-защита)
- Idempotency key (UUIDv7) для защиты от дублирования заявок
- Формат номера заявки: `WD-XXXXXXXX`

#### history-payout.php

- **История выплат пользователя** (WooCommerce My Account endpoint)
- Отображение заявок на вывод с AJAX-пагинацией
- Фильтры: по дате, номеру заявки, статусу
- Статусы: В ожидании, В обработке, Выплачен, Возврат в баланс, Выплата заморожена
- Отображение: номер заявки, дата, сумма, способ вывода, счёт, банк, статус

#### uninstall.php

- **Полная очистка данных при удалении плагина**
- Удаление всех таблиц, триггеров, событий MySQL
- Удаление WordPress options, transients
- Удаление файлов вложений поддержки
- Удаление ключа шифрования

---

### 2. Административная панель (Admin)

#### payouts.php — Управление выплатами

- Список всех заявок на вывод с фильтрацией и пагинацией
- Фильтры: по статусу, дате, номеру заявки
- Детальная страница заявки (action=view)
- Редактирование статуса, provider_payout_id, attempts, fail_reason
- Расшифровка зашифрованных реквизитов (только для статуса «processing»)
- Проверка баланса пользователя перед выплатой
- Предупреждения о деактивированных платёжных системах/банках
- AJAX обработчики: обновление, получение, расшифровка, верификация

#### transactions.php — Управление транзакциями

- Две вкладки: Зарегистрированные / Незарегистрированные
- Фильтры: по статусу, партнёрской сети, поиску (reference_id, click_id, order_number)
- Inline-редактирование транзакций (статус, сумма заказа, комиссия)
- **Атомарное обновление с FOR UPDATE** (защита от race conditions)
- Перенос транзакций из «незарегистрированных» в «зарегистрированные» по email пользователя
- Автоматическая проверка дублей при переносе
- Финальные статусы (balance) — только для чтения
- Аудит-лог ручных изменений

#### users-management.php — Управление пользователями

- Список всех пользователей с профилями кэшбэка
- Фильтры: по статусу (active, noactive, banned, deleted), поиск по email/имени
- Inline-редактирование: ставка кэшбэка, мин. сумма выплаты, статус, причина бана
- **Массовое изменение ставки кэшбэка** (preview + apply)
- Бан/разбан пользователей с:
  - Автоматической заморозкой/разморозкой баланса (через триггеры)
  - Установкой banned_at
  - Обязательным указанием причины бана
  - Withdrawal lock для сериализации с параллельным выводом
  - Транзакционная целостность (START TRANSACTION / COMMIT / ROLLBACK)

#### statistics.php — Статистика и KPI

- Карточки KPI: Общая комиссия, Общий кэшбэк, Прибыль сервиса
- Таблица транзакций по статусам (количество, комиссия, кэшбэк)
- Таблица выплат по статусам (количество, сумма)
- Агрегированные балансы пользователей (доступные, в ожидании, выплачено, заморожено)
- Фильтр по дате (по умолчанию — текущий месяц)
- Кэширование запросов (transient)

#### bank-management.php — Управление банками

- CRUD операций для справочника банков
- Активация/деактивация банков
- Сортировка банков

#### payout-methods.php — Управление способами выплат

- CRUD операций для справочника способов выплат
- Флаг «требует выбора банка» для каждого способа
- Активация/деактивация способов

#### click-log.php — Лог кликов

- Просмотр лога кликов по партнёрским ссылкам
- Фильтрация и поиск

#### rate-history.php — История изменений ставок

- Логирование всех изменений ставок кэшбэка
- Типы изменений: manual (ручное), system (системное)

#### transactions.php — Управление транзакциями

- Просмотр, фильтрация и редактирование транзакций
- Перенос транзакций незарегистрированных пользователей

#### users-management.php — Управление пользователями

- Просмотр и редактирование профилей пользователей
- Массовое изменение ставок кэшбэка
- Бан/разбан пользователей

#### class-cashback-admin-api-validation.php — API Валидация

- Админ-страница для настройки API синхронизации с CPA-сетями
- Настройка расписания фоновой синхронизации
- Ручной запуск синхронизации
- Просмотр результатов последней синхронизации

#### health-check.php — Мониторинг целостности данных

- Cron обработчик для ежедневной проверки целостности данных
- Проверка наличия таблиц, колонок, триггеров
- Автоматическое восстановление при нарушениях

---

### 3. Партнёрская программа (Affiliate Module)

#### class-affiliate-service.php

- **Ядро реферальной программы**
- Обработка визитов с `?ref={partner_token}`:
  - Установка HMAC-подписанной cookie (cashback_ref + cashback_ref_sig)
  - Серверный fallback: transient по IP (если cookie недоступна)
  - 302 редирект для очистки URL от параметра
- Привязка реферала при регистрации пользователя:
  - Антифрод проверки (валидность реферера, self-referral, IP/fingerprint совпадения)
  - Атомарная привязка (UPDATE WHERE referred_by_user_id IS NULL — immutable)
  - Логирование клика в cashback_affiliate_clicks
- **Batch-начисление партнёрских комиссий**:
  - Вызывается внутри process_ready_transactions() под глобальным lock
  - Расчёт комиссии по индивидуальной ставке реферера или глобальной ставке
  - Запись в cashback_affiliate_accruals (идемпотентно)
  - Запись в cashback_balance_ledger (единый ledger)
  - Обновление баланса рефереров
  - Уведомления рефереров о начислении
- Cookie параметры: HttpOnly, SameSite=Lax, Secure (SSL), настраиваемый TTL

#### class-affiliate-db.php

- Создание таблиц affiliate модуля:
  - `cashback_affiliate_profiles` — профили участников (referred_by_user_id, affiliate_status)
  - `cashback_affiliate_clicks` — лог реферальных кликов
  - `cashback_affiliate_accruals` — начисления партнёрских комиссий
- Генерация reference ID для accruals
- Проверка активности модуля
- Batch-получение ставок рефереров

#### class-affiliate-antifraud.php

- **Антифрод реферальной программы**:
  - Запрет self-referral (реферер = реферал)
  - Запрет реферала для забаненных пользователей
  - Проверка совпадения IP (multi-account IP)
  - Проверка совпадения fingerprint
  - Проверка времени между кликом и регистрацией
  - Проверка активности реферера

#### class-affiliate-frontend.php

- Фронтенд реферальной программы (WooCommerce My Account endpoint)
- Отображение:
  - Партнёрская ссылка с token
  - Количество рефералов
  - Статистика начислений
  - Правила партнёрской программы
- Копирование ссылки в буфер обмена
- QR-код для мобильных устройств

#### class-affiliate-admin.php

- Админ-страница настроек партнёрской программы:
  - Глобальная ставка (%)
  - Cookie TTL (дней)
  - URL правил
  - Включение/выключение модуля
  - Включение/выключение антифрода

---

### 4. Антифрод система (Anti-Fraud Module)

#### class-fraud-detector.php

- **Ядро детекции фрода** — 7 автоматических проверок + риск-скоринг
- Запускается ежечасно через WP Cron
- **7 проверок**:
  1. **Shared IP** — множественные аккаунты на одном IP (исключая private ranges)
  2. **Shared Fingerprint** — множественные аккаунты с одним fingerprint браузера
  3. **Shared Payment Details** — общие платёжные реквизиты (через details_hash, без расшифровки)
  4. **Cancellation Rate** — высокий процент отклонённых транзакций (за 90 дней)
  5. **Withdrawal Velocity** — высокая частота заявок на вывод (день/неделя)
  6. **Amount Anomalies** — аномальные суммы кэшбэка (сравнение со средней за 30 дней)
  7. **New Account Risk** — новые аккаунты с ранним выводом (cooling period)
- **Композитный риск-скор** (0-100): сумма весов всех открытых алертов
- **Дедупликация алертов**: не создаёт дубликаты за 30 дней
- **Атомарное создание алертов**: транзакция + FOR UPDATE (защита от race conditions)
- Сигналы (signals) с весом и evidence для каждого алерта

#### class-fraud-collector.php

- Сбор fingerprint браузера пользователя
- Генерация хеша fingerprint (SHA-256)
- Сохранение IP, user_agent, fingerprint_hash
- Очистка старых fingerprint (WP Cron ежедневно)

#### class-fraud-settings.php

- Настройки антифрод модуля:
  - Включение/выключение модуля
  - Макс. пользователей на один IP
  - Макс. пользователей на один fingerprint
  - Макс. аккаунтов на одни платёжные реквизиты
  - Порог процента отклонённых транзакций (%)
  - Мин. количество транзакций для анализа
  - Множитель аномальной суммы
  - Период охлаждения для новых аккаунтов (дней)
  - Макс. заявок на вывод в день/неделю
  - Порог автоматической пометки «подозрительный»
  - Email-уведомления админу

#### class-fraud-admin.php

- Админ-страница антифрод системы:
  - Список всех алертов с фильтрацией
  - Детальная информация по каждому алерту (сигналы, evidence)
  - Композитный риск-скор пользователя
  - Действия: подтвердить, отклонить, заблокировать пользователя
  - Статистика алертов

#### class-fraud-db.php

- Создание таблиц антифрод модуля:
  - `cashback_fraud_signals` — сигналы фрода
  - `cashback_fraud_alerts` — алерты фрода
  - `cashback_user_fingerprints` — fingerprint'и пользователей
- Методы для работы с таблицами

---

### 5. Заявки на неначисленный кэшбэк (Claims Module)

#### class-claims-manager.php

- **Ядро управления заявками на неначисленный кэшбэк**
- Создание заявки:
  - Проверка права подачи (eligibility)
  - Антифрод предпроверка
  - Скоринг вероятности успеха
  - Логирование события
- Управление статусами (конечный автомат):
  - `draft` → `submitted`
  - `submitted` → `sent_to_network`, `approved`, `declined`
  - `sent_to_network` → `approved`, `declined`
  - `approved`, `declined` — финальные статусы
- Логирование всех событий (claim_events)
- Получение stats для админки

#### class-claims-eligibility.php

- **Проверка права подачи заявки**:
  - Пользователь авторизован
  - Клик залогирован в cashback_click_log
  - Товар принадлежит активной CPA-сети
  - Заказ не старше N дней (настраивается)
  - У пользователя нет другой заявки с этим order_id
  - Лимит заявок в день/неделю не превышен
  - Мерчант не в чёрном списке

#### class-claims-scoring.php

- **Скоринг вероятности успеха заявки**:
  - Наличие клика в логе
  - Время между кликом и заказом
  - История заявок пользователя
  - Риск-скор пользователя (антифрод)
  - Результат: 0-100%

#### class-claims-antifraud.php

- **Антифрод для заявок**:
  - Предпроверка: лимиты, подозрительные IP
  - Постпроверка: множественные заявки с одного IP, аномалии
  - Пометка заявки как «подозрительная» (is_suspicious)

#### class-claims-frontend.php

- Фронтенд заявок (WooCommerce My Account endpoint)
- Форма создания заявки:
  - Order ID
  - Дата заказа
  - Сумма заказа
  - Комментарий
  - Прикрепление скриншотов
- Список заявок пользователя с пагинацией и фильтрами
- Детальная страница заявки с историей событий
- Уведомления о новых событиях (badge)

#### class-claims-admin.php

- Админ-страница заявок:
  - Список всех заявок с фильтрами (статус, подозрительные, мерчант, поиск, даты)
  - Детальная страница заявки
  - Изменение статуса + добавление заметок
  - Отправка в CPA-сеть
  - Статистика заявок

#### class-claims-db.php

- Создание таблиц claims модуля:
  - `cashback_claims` — заявки
  - `cashback_claim_events` — события заявок
- Миграции (добавление колонки is_read)

#### class-claims-notifications.php

- Уведомления о заявках (email)
- Обработка actions: claim_created, claim_status_changed

---

### 6. Система уведомлений (Notifications Module)

#### class-cashback-notifications.php

- **Оркестратор email-уведомлений**
- Подписка на WordPress actions и отправка email
- **Типы уведомлений**:
  1. `transaction_new` — Новая транзакция (покупка через партнёра)
  2. `transaction_status` — Изменение статуса транзакции
  3. `cashback_credited` — Начисление кэшбэка на баланс
  4. `user_registered` — Регистрация нового пользователя (welcome email)
  5. `ticket_reply` — Ответ администратора на тикет
  6. `ticket_admin_alert` — Новый тикет / ответ пользователя → админу
  7. `claim_created` — Заявка на кэшбэк создана
  8. `claim_status` — Статус заявки изменён
  9. `affiliate_referral` — Новый реферал
  10. `affiliate_commission` — Партнёрская комиссия начислена
- **Обработка очереди уведомлений** из MySQL триггеров (WP Cron каждую минуту)
- Регистрация cron-интервала «every_minute»

#### class-cashback-email-sender.php

- **Отправка email через прямой SMTP** (без зависимости от WordPress wp_mail)
- Direct SMTP подключение (PHPMailer)
- Настройка From Name и From Email
- Логирование отправки
- Защита от дублирования (already_sent флаг)

#### class-cashback-notifications-db.php

- Создание таблиц уведомлений:
  - `cashback_notification_queue` — очередь уведомлений
  - `cashback_notification_preferences` — пользовательские настройки уведомлений
- Методы для работы с очередью

#### class-cashback-notifications-frontend.php

- Фронтенд настроек уведомлений
- Возможность отписаться от определённых типов уведомлений

#### class-cashback-notifications-admin.php

- Админ-страница настроек уведомлений:
  - Глобальные вкл/выкл для каждого типа
  - Настройка email отправителя (From Name, From Email)

---

### 7. Система поддержки (Support Module)

#### support-db.php

- Создание таблиц поддержки:
  - `cashback_support_tickets` — тикеты
  - `cashback_support_messages` — сообщения в тикетах
  - `cashback_support_attachments` — вложения
- Обеспечение директории для вложений (wp-content/uploads/cashback-support/)
- Автоматическое удаление закрытых тикетов через 1 месяц (WP Cron)

#### user-support.php

- **Фронтенд системы поддержки** (WooCommerce My Account endpoint)
- Создание тикетов:
  - Тема, категория, описание
  - Прикрепление файлов (до 5 файлов, настраиваемый размер и типы)
  - CAPTCHA для защиты от ботов
- Просмотр тикетов с пагинацией и фильтрами (статус, поиск)
- Просмотр сообщений в тикете
- Добавление ответов
- Отображение статуса тикета (open, closed, admin-reply)
- Защита XSS (DOMPurify + safe HTML)

#### admin-support.php

- **Админ-страница системы поддержки**
- Список всех тикетов с фильтрами
- Детальная страница тикета
- Ответы администратора
- Изменение статуса тикета
- Управление вложениями

---

### 8. API клиент и синхронизация (API Client & Cron)

#### class-cashback-api-client.php

- **Универсальный API-клиент для CPA-сетей**
- Фасад для работы с адаптерами CPA-сетей
- **Встроенные адаптеры**:
  - Admitad (OAuth2 авторизация)
  - EPN (API key авторизация)
- **Стратегия reconciliation** (индустриальный стандарт):
  - Мэтчинг: API.subid1 == DB.click_id (UUID, генерируемый кэшбэк-сервисом)
  - Сравнение: status, payment/comission, cart/sum_order
  - Фильтрация: API.subid2 == DB.user_id
  - Логирование: action_id (для lost order claims), order_id (для поддержки)
- Шифрование API credentials через AES-256
- Регистрация внешних адаптеров через хук `cashback_register_network_adapters`

#### class-cashback-api-cron.php

- **Фоновая синхронизация с API CPA-сетей** (WP Cron)
- Периодическое получение действий (actions) от CPA-сетей
- Сравнение с локальными транзакциями
- Обновление статусов, сумм, комиссий
- Логирование результатов синхронизации
- Чекпоинты для возобновления синхронизации
- Rate limiting API запросов

#### class-cashback-admin-api-validation.php

- Админ-страница для настройки API валидации
- Ручной запуск синхронизации
- Настройка расписания
- Просмотр результатов последней синхронизации

---

### 9. REST API для браузерного расширения

#### class-cashback-rest-api.php

- **REST API namespace: `cashback/v1`**
- Аутентификация через WordPress cookie (без nonce для browser extension)
- **Эндпоинты**:
  - `GET /stores` — Список магазинов с кэшбэком (публичный)
  - `GET /me` — Профиль и баланс текущего пользователя
  - `GET /me/transactions` — Транзакции текущего пользователя (пагинация)
  - `POST /activate` — Активация кэшбэка (генерация redirect-ссылки, логирование клика)
  - `GET /session-status` — Статус активации для домена или по click_id
- **Безопасность**:
  - Блокировка user enumeration через REST API
  - Блокировка author enumeration через `/?author=N`
  - Проверка Origin (chrome-extension://, moz-extension://)
  - Rate limiting (per-product и global)
- Кэширование списка магазинов (transient, 6 часов)
- Окно активации: 30 минут

---

### 10. Shortcodes

#### class-cashback-shortcodes.php

- Регистрация шорткодов для вставки на страницы/посты
- Шорткод баланса пользователя
- Шорткод истории транзакций
- Шорткод формы заявки на вывод

---

### 11. Бот-защита (Bot Protection)

#### class-cashback-rate-limiter.php

- **Rate limiting** для защиты от ботов и brute-force
- Двухуровневые ключи (IP + resource, IP global)
- Три статуса: normal → spam (лог + флаг) → blocked (429)
- Без использования User-Agent (боты меняют UA, CGNAT-safe)
- Хранение счётчиков в transients (Redis/Memcached recommended для production)

#### class-cashback-captcha.php

- **CAPTCHA** для «серых» IP (grey scoring)
- Интеграция с hCaptcha / reCAPTCHA
- Отображение CAPTCHA только для подозрительных IP
- Контейнер для CAPTCHA (рендер в формах)

#### class-cashback-bot-protection.php

- **Комплексная защита от ботов**
- Grey scoring IP (на основе поведения)
- Блокировка по threshold
- AJAX обработчики для проверки CAPTCHA
- Интеграция с rate limiter

---

### 12. Утилиты и вспомогательные компоненты

#### class-cashback-encryption.php

- **AES-256-CBC шифрование** для чувствительных данных
- Шифрование платёжных реквизитов (payout_account)
- Шифрование API credentials
- Генерация и хранение ключа в `wp-content/.cashback-encryption-key.php`
- SHA-256 хеширование для дедупликации и антифрода
- Маскирование реквизитов для отображения

#### class-cashback-lock.php

- **Глобальный lock для атомарной синхронизации и начислений**
- Предотвращение race conditions при batch-начислениях
- MySQL GET_LOCK / RELEASE_LOCK
- Таймаут блокировки

#### class-cashback-trigger-fallbacks.php

- **PHP-фолбэки для логики MySQL-триггеров**
- Установка banned_at при бане
- Очистка полей бана при разбане
- Заморозка/разморозка баланса

#### class-cashback-user-status.php

- Утилита проверки статуса пользователя
- Проверка на забаненность
- Получение информации о бане
- Формирование сообщения о блокировке

#### class-cashback-rate-limiter.php

- Rate limiting для AJAX endpoint'ов

#### includes/adapters/ — Адаптеры CPA-сетей

- `interface-cashback-network-adapter.php` — интерфейс адаптера
- `abstract-cashback-network-adapter.php` — абстрактный базовый класс
- `class-admitad-adapter.php` — адаптер для Admitad (OAuth2)
- `class-epn-adapter.php` — адаптер для EPN (API key)

#### partner/partner-management.php

- Управление партнёрскими сетями в админке
- CRUD для cashback_affiliate_networks
- Настройка API credentials, маппинга полей, статусов
- Тестирование API подключения

---

### 13. Инфраструктура развёртывания (server-stack/stack/)

**Это не часть плагина**, а Docker-инфраструктура для production развёртывания всего сервиса:

- **Docker Compose** с сервисами:
  - WordPress + PHP-FPM
  - Nginx (reverse proxy)
  - MariaDB (база данных)
  - Redis (кеширование, очереди)
  - Traefik (SSL, routing)
  - VictoriaMetrics (метрики)
  - Grafana (мониторинг, алертинг)
  - msmtp (SMTP relay)
- Скрипты: установка, бэкап, entrypoint
- Конфигурации: MariaDB, Nginx, PHP-FPM, WordPress.ini
- Мониторинг: правила алертов, contact points, scrape config

---

## Часть 2: Webhook Receiver — Микросервис на Python/FastAPI

> **Важно:** Это **отдельный сервис**, не являющийся частью WordPress плагина. Находится в `server-stack/webhook-receiver/`.

### Назначение

Принимает постбэки (webhooks) от CPA-сетей (Admitad, ActionPay, CityAds и др.), трансформирует через настраиваемый маппинг и записывает в базу WordPress/WooCommerce.

### Архитектура

```
CPA-сеть → GET/POST /wh/{slug}/{secret}
                │
                ▼
        ┌──────────────┐
        │   Receiver   │  (FastAPI, 4 workers, порт 8099)
        │  rate limit  │
        └──────┬───────┘
               │ LPUSH
               ▼
        ┌──────────────┐
        │    Redis     │  (очередь + статистика)
        └──────┬───────┘
               │ BRPOP
               ▼
        ┌──────────────┐
        │   Worker     │  (4 потока)
        │  маппинг     │  → cashback_webhooks (дедупликация)
        │  валидация   │  → cashback_transactions
        │  запись в БД │  → cashback_unregistered_transactions
        └──────────────┘

        ┌──────────────┐
        │  Admin UI    │  (порт 8098, только localhost)
        │  SSH tunnel  │
        └──────────────┘
```

### Компоненты

#### app/receiver.py — FastAPI Webhook Endpoint

- Приём GET/POST запросов от CPA-сетей
- URL формат: `/{slug}/{secret}` и `/wh/{slug}/{secret}`
- **Безопасность**:
  - Валидация slug (только `[a-z0-9_-]`, макс 64 символа)
  - Проверка secret_path (24-байт token_urlsafe)
  - Rate limiting (200 хуков/мин на сеть, 429 при превышении)
  - Lua script для атомарного INCR + EXPIRE в Redis
  - Отклонение oversized payload (макс. 512 KB)
  - Проверка HTTP метода (настраивается для каждой сети)
- Извлечение параметров из query string, JSON body, form data
- Отправка raw данных в Redis очередь (LPUSH)
- Статистика хуков по часам (Redis keys с TTL 7 дней)
- Healthcheck endpoint: `/health`
- Производительность: ~3000+ req/s (4 uvicorn workers)

#### worker/processor.py — Redis Consumer + Бизнес-логика

- 4 рабочих потока (настраиваемая CONCURRENCY)
- BRPOP из Redis очереди (блокирующий pop, 2 сек таймаут)
- **Логика обработки**:
  1. Сохранение raw webhook в `cashback_webhooks` (дедупликация по SHA-256)
  2. Применение маппинга полей (настраивается для каждой сети)
  3. Применение field transforms (например, Unix timestamp → datetime)
  4. Маппинг статуса заказа (approved → completed, pending → waiting)
  5. **Click-ID security validation**:
     - Проверка наличия click_id в `cashback_click_log`
     - Проверка совпадения user_id (click_log vs postback)
     - Resolution partner_token → numeric user_id
     - При несовпадении — запись в webhook status = «user_mismatch», без вставки транзакции
  6. Проверка user_id в `wp_users`
  7. Вставка в `cashback_transactions` (если зарегистрирован) или `cashback_unregistered_transactions`
  8. Email-уведомление (direct SMTP) для зарегистрированных пользователей
  9. Enqueue notification для audit log
- **Идемпотентность**: UNIQUE KEY на `idempotency_key`
- Dead Letter Queue (DLQ) для сообщений с ошибками (до 10K сообщений)
- Graceful shutdown (SIGTERM, SIGINT)
- Производительность: ~500 msg/s (4 threads)

#### app/config.py — JSON Конфигурация

- Загрузка конфигурации сетей из JSON файла
- Настройки подключения к MySQL
- Маппинг статусов по умолчанию (DEFAULT_STATUS_MAP)

#### app/db.py — MySQL Операции

- Все запросы через parameterized queries PyMySQL (защита от SQL-инъекций)
- Функции:
  - `save_raw_webhook()` — сохранение raw payload с SHA-256 хешем
  - `check_user_exists()` — проверка существования пользователя в wp_users
  - `check_click_id_and_get_user()` — проверка click_id и получение user_id
  - `update_webhook_processing_status()` — обновление статуса обработки webhook
  - `insert_transaction()` — вставка транзакции (registered/unregistered)
  - `transaction_exists()` — проверка существования транзакции по click_id
  - `resolve_partner_token()` — разрешение partner_token → numeric user_id
  - `enqueue_notification()` — добавление уведомления в очередь

#### app/email_sender.py — Direct SMTP Отправка

- Прямая отправка email через SMTP (без зависимости от WordPress)
- Настройки SMTP из конфигурации
- Уведомления о новых транзакциях

#### admin/panel.py — Admin UI

- Веб-интерфейс для управления сервисом
- Доступен **только через SSH tunnel** (127.0.0.1:8098)
- **Функции**:
  - Авторизация (HMAC-сравнение пароля, защита от brute-force)
  - Дашборд (размер очереди Redis, DLQ, статистика)
  - Настройки базы данных (MySQL host, port, user, password, database)
  - Управление сетями (CRUD, импорт из БД)
  - Редактор сети:
    - Настройка маппинга полей (наше поле ← параметр CPA-сети)
    - Настройка маппинга статусов
    - Настройка field transforms
    - Secret path, rate limit, HTTP method
  - Журнал вебхуков
- **Безопасность**:
  - Session cookies (httponly, samesite=strict)
  - CSRF защита
  - Rate limiting на логин

#### templates/ — HTML Шаблоны

- `base.html` — базовый layout
- `login.html` — страница входа
- `dashboard.html` — дашборд
- `db_settings.html` — настройки БД
- `networks.html` — список сетей
- `network_edit.html` — редактор сети + маппинг
- `logs.html` — журнал вебхуков

### Безопасность Webhook Receiver

| Мера             | Реализация                                      |
| ---------------- | ----------------------------------------------- |
| SQL-инъекции     | Все запросы через `%s` placeholders PyMySQL     |
| CSRF             | Session cookies с `httponly`, `samesite=strict` |
| Brute-force      | HMAC-сравнение пароля                           |
| Rate limiting    | 200 хуков/мин на сеть, 429 при превышении       |
| Доступ к админке | Только `127.0.0.1:8098` (через SSH)             |
| Secret path      | 24-байт `token_urlsafe` в URL вебхука           |
| Дедупликация     | SHA-256 от payload в `cashback_webhooks`        |
| Идемпотентность  | UNIQUE KEY на `idempotency_key`                 |
| Валидация slug   | Только `[a-z0-9_-]`, макс 64 символа            |
| Префикс таблиц   | Regex-валидация `^[a-zA-Z0-9_]+$`               |

### Производительность

- **Receiver:** 4 uvicorn workers, async Redis → ~3000+ req/s
- **Worker:** 4 потока, BRPOP → обработка ~500 msg/s
- **Redis:** буфер между приёмом и записью, сглаживает пики
- **1000 хуков/мин** = ~17/сек — запас x10 минимум

---

## Общая Архитектура Системы

### Поток данных (Data Flow)

1. **Клик пользователя**:

   ```
   Пользователь кликает на партнёрскую ссылку
     → WC_Affiliate_URL_Params модифицирует URL
     → Логирование клика в cashback_click_log
     → Редирект на CPA-сеть
   ```

2. **Покупка через CPA-сеть**:

   ```
   Пользователь покупает товар на CPA-сети
     → CPA-сеть отправляет webhook
     → Webhook Receiver (Python/FastAPI) принимает
     → Сохраняет в Redis очередь
     → Worker обрабатывает:
       - Дедупликация (SHA-256)
       - Маппинг полей
       - Проверка click_id
       - Вставка в cashback_transactions/unregistered_transactions
     → MySQL триггер автоматически рассчитывает кэшбэк
   ```

3. **Синхронизация с API CPA-сети**:

   ```
   WP Cron (каждые N минут)
     → Cashback_API_Cron вызывает API CPA-сети
     → Сравнение с локальными транзакциями
     → Обновление статусов, сумм
     → Логирование в cashback_sync_log
   ```

4. **Начисление кэшбэка на баланс**:

   ```
   WP Cron (MySQL Event cashback_ev_confirmed_cashback)
     → Глобальный lock (Cashback_Lock)
     → Batch-обработка подтверждённых транзакций
     → Начисление available_balance
     → Affiliate module: начисление партнёрских комиссий
     → Уведомления пользователю
   ```

5. **Вывод средств**:
   ```
   Пользователь создаёт заявку
     → Проверка баланса, настроек, CAPTCHA
     → Заявка в cashback_payout_requests (idempotency key)
     → Админ обрабатывает заявку
     → Выплата через провайдера
     → Обновление статуса
     → MySQL триггер: update paid_balance / frozen_balance
   ```

### База данных

**Основные таблицы**:

- `cashback_transactions` — транзакции (FK к user_id, click_id)
- `cashback_unregistered_transactions` — транзакции незарегистрированных
- `cashback_user_balance` — балансы пользователей (optimistic locking via version)
- `cashback_user_profile` — профили (ставки, выплаты, статусы)
- `cashback_payout_requests` — заявки на вывод
- `cashback_click_log` — лог кликов (90 дней retention)
- `cashback_webhooks` — сырые webhook'и (дедупликация)

**Модульные таблицы**:

- Affiliate: `cashback_affiliate_profiles`, `cashback_affiliate_clicks`, `cashback_affiliate_accruals`
- Anti-fraud: `cashback_fraud_signals`, `cashback_fraud_alerts`, `cashback_user_fingerprints`
- Claims: `cashback_claims`, `cashback_claim_events`
- Notifications: `cashback_notification_queue`, `cashback_notification_preferences`
- Support: `cashback_support_tickets`, `cashback_support_messages`, `cashback_support_attachments`
- Audit/Log: `cashback_audit_log`, `cashback_sync_log`, `cashback_validation_checkpoints`, `cashback_rate_history`

**Справочники**:

- `cashback_payout_methods` — способы выплат
- `cashback_banks` — банки
- `cashback_affiliate_networks` — CPA-сети
- `cashback_affiliate_network_params` — параметры CPA-сетей

### Безопасность

- **Шифрование**: AES-256-CBC для платёжных реквизитов, API credentials
- **Хеширование**: SHA-256 для дедупликации webhook'ов, fingerprint'ов, details_hash
- **Idempotency**: UUIDv7 для предотвращения дублирования транзакций и заявок
- **Rate Limiting**: Двухуровневый (per-resource + global), CGNAT-safe
- **CAPTCHA**: Для «серых» IP (grey scoring)
- **Nonce**: WordPress nonce для всех AJAX операций
- **FOR UPDATE**: Транзакционная целостность при обновлении критичных данных
- **Partner Token**: Криптографический токен (32 hex) вместо user_id в партнёрских ссылках
- **Click-ID Security**: Проверка наличия click_id в click_log + проверка user_id match

### Масштабируемость

- **Redis**: Очередь webhook'ов, rate limiting, кэширование
- **Docker Compose**: Production развёртывание с горизонтальным масштабированием
- **VictoriaMetrics + Grafana**: Мониторинг и алертинг
- **Traefik**: SSL termination, load balancing
- **Nginx**: Reverse proxy, static files
- **MariaDB**: InnoDB engine, foreign keys, triggers, events
- **WP Cron + MySQL Events**: Фоновые задачи

---

## Резюме

Cashback Plugin — это полнофункциональная кэшбэк-платформа, которая включает:

✅ **Интеграцию с CPA-сетями** (Admitad, EPN) через вебхуки и API  
✅ **Партнёрскую/реферальную программу** с антифродом  
✅ **Многоуровневую антифрод систему** (7 проверок + риск-скоринг)  
✅ **Заявки на неначисленный кэшбэк** (claims) с скорингом  
✅ **Систему выплат** (СБП, МИР, ЮMoney) с шифрованием реквизитов  
✅ **Систему поддержки** с тикетами и вложениями  
✅ **Email-уведомления** (10 типов) через прямой SMTP  
✅ **REST API** для браузерного расширения  
✅ **REST API** для мобильной интеграции  
✅ **Комплексную бот-защиту** (rate limiting, CAPTCHA, grey scoring)  
✅ **Административную панель** с полной статистикой и управлением

**Webhook Receiver** (Python/FastAPI) — отдельный микросервис для приёма постбэков от CPA-сетей, обеспечивающий высокую производительность (~3000 req/s) и надёжность (Redis queue, dead letter queue, идемпотентность).

## Принципы разработки

- Безопасность прежде всего
- Транзакционность критических операций
- Идемпотентность запросов
- Аудит чувствительных действий
- Валидация на всех уровнях
- Authenticated encryption (GCM)
- Rate limiting на всех публичных эндпоинтах

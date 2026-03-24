<?php

declare(strict_types=1);

/**
 * Plugin Name: Cashback Plugin
 * Description: Объединенный плагин для системы кэшбэка и аффилиат-партнерства
 * Version: 1.0.0
 * Author: Cashback
 * Author URI: https://example.com
 * Text Domain: cashback-plugin
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Запрет прямого доступа
defined('ABSPATH') or die('No script kiddies please!');

// Минимальные требования к версиям
define('CASHBACK_MIN_PHP_VERSION', '7.4');
define('CASHBACK_MIN_WP_VERSION', '6.2');
define('CASHBACK_MIN_WC_VERSION', '5.0');

/**
 * Проверка совместимости с текущими версиями PHP и WordPress
 *
 * @return void
 */
function cashback_check_requirements()
{
    $errors = [];

    // Проверка версии PHP
    if (version_compare(PHP_VERSION, CASHBACK_MIN_PHP_VERSION, '<')) {
        $errors[] = sprintf(
            /* translators: 1: Current PHP version, 2: Required PHP version */
            __('Cashback Plugin requires PHP %2$s or higher. You are running PHP %1$s.', 'cashback-plugin'),
            PHP_VERSION,
            CASHBACK_MIN_PHP_VERSION
        );
    }

    // Проверка версии WordPress
    if (version_compare(get_bloginfo('version'), CASHBACK_MIN_WP_VERSION, '<')) {
        $errors[] = sprintf(
            /* translators: 1: Current WordPress version, 2: Required WordPress version */
            __('Cashback Plugin requires WordPress %2$s or higher. You are running WordPress %1$s.', 'cashback-plugin'),
            get_bloginfo('version'),
            CASHBACK_MIN_WP_VERSION
        );
    }

    // Проверка версии WooCommerce (если установлен)
    if (defined('WC_VERSION') && version_compare(WC_VERSION, CASHBACK_MIN_WC_VERSION, '<')) {
        $errors[] = sprintf(
            /* translators: 1: Current WooCommerce version, 2: Required WooCommerce version */
            __('Cashback Plugin requires WooCommerce %2$s or higher. You are running WooCommerce %1$s.', 'cashback-plugin'),
            WC_VERSION,
            CASHBACK_MIN_WC_VERSION
        );
    }

    // Если есть ошибки, деактивируем плагин и показываем сообщение
    if (!empty($errors)) {
        deactivate_plugins(plugin_basename(__FILE__));

        $error_message = '<h1>' . esc_html__('Plugin Activation Error', 'cashback-plugin') . '</h1>';
        $error_message .= '<p><strong>' . esc_html__('Cashback Plugin', 'cashback-plugin') . '</strong></p>';
        $error_message .= '<ul>';
        foreach ($errors as $error) {
            $error_message .= '<li>' . esc_html($error) . '</li>';
        }
        $error_message .= '</ul>';

        wp_die(
            wp_kses_post($error_message),
            esc_html__('Plugin Activation Error', 'cashback-plugin'),
            array('back_link' => true)
        );
    }
}

// Проверяем требования при активации плагина
register_activation_hook(__FILE__, 'cashback_check_requirements');

/**
 * Основной класс плагина Cashback
 */
class CashbackPlugin
{

    /**
     * Конструктор класса
     */
    public function __construct()
    {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array($this, 'load_textdomain'));
        add_action('before_woocommerce_init', array($this, 'declare_woocommerce_compatibility'));
        // WooCommerce транзакционные письма используют собственный фильтр woocommerce_email_from_name
        add_filter('woocommerce_email_from_name', function (string $name): string {
            $custom = (string) get_option('cashback_email_sender_name', '');
            return trim($custom) !== '' ? $custom : $name;
        });
        // WordPress core и прочие письма — приоритет 20 перекрывает WC_Emails (приоритет 10)
        add_filter('wp_mail_from_name', function (string $name): string {
            $custom = (string) get_option('cashback_email_sender_name', '');
            return trim($custom) !== '' ? $custom : $name;
        }, 20);
    }

    /**
     * Загрузка текстового домена для переводов
     *
     * @return void
     */
    public function load_textdomain()
    {
        load_plugin_textdomain(
            'cashback-plugin',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Объявление совместимости с функциями WooCommerce
     *
     * @return void
     */
    public function declare_woocommerce_compatibility()
    {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }

    /**
     * Метод активации плагина
     */
    public function activate()
    {
        // Подключаем утилиту шифрования (используется в миграции при активации)
        $this->require_file('includes/class-cashback-encryption.php');

        // Автоматически генерируем ключ шифрования (wp-content/.cashback-encryption-key.php)
        $this->maybe_generate_encryption_key();

        // Подключаем файл mariadb.php для активации
        $this->require_file('mariadb.php');
        // Активация основного функционала (таблицы, триггеры, события)
        if (class_exists('Mariadb_Plugin')) {
            try {
                Mariadb_Plugin::activate();
            } catch (Exception $e) {
                // Логируем детальную ошибку
                error_log('Cashback Plugin Activation Error: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                // Показываем пользователю
                wp_die(
                    '<h1>Ошибка активации плагина</h1>' .
                        '<p><strong>Cashback Plugin:</strong> ' . esc_html($e->getMessage()) . '</p>' .
                        '<p>Проверьте логи ошибок для получения дополнительной информации.</p>',
                    'Ошибка активации плагина',
                    array('back_link' => true)
                );
            }
        } else {
            wp_die(
                '<h1>Ошибка активации плагина</h1>' .
                    '<p><strong>Cashback Plugin:</strong> Класс Mariadb_Plugin не найден.</p>',
                'Ошибка активации плагина',
                array('back_link' => true)
            );
        }

        // --- API Валидация: миграции БД ---
        $this->require_file('includes/class-cashback-api-migration.php');
        if (class_exists('Cashback_API_Migration')) {
            try {
                Cashback_API_Migration::run();
            } catch (Exception $e) {
                error_log('Cashback API Migration Error: ' . $e->getMessage());
            }
        }

        // Создание таблиц поддержки и директории для вложений
        $this->require_file('support/support-db.php');
        if (class_exists('Cashback_Support_DB')) {
            Cashback_Support_DB::create_tables();
            Cashback_Support_DB::ensure_upload_dir();
        }

        // Планируем cron для автоудаления закрытых тикетов (через 1 месяц)
        if (!wp_next_scheduled('cashback_support_auto_delete_cron')) {
            wp_schedule_event(time(), 'daily', 'cashback_support_auto_delete_cron');
        }

        // Планируем cron для мониторинга целостности данных
        if (!wp_next_scheduled('cashback_health_check_cron')) {
            wp_schedule_event(time(), 'daily', 'cashback_health_check_cron');
        }

        // Создание таблиц антифрод-модуля
        $this->require_file('antifraud/class-fraud-db.php');
        if (class_exists('Cashback_Fraud_DB')) {
            Cashback_Fraud_DB::create_tables();
        }

        // Планируем cron для антифрод-детекции (ежечасно)
        if (!wp_next_scheduled('cashback_fraud_detection_cron')) {
            wp_schedule_event(time(), 'hourly', 'cashback_fraud_detection_cron');
        }

        // Планируем cron для очистки старых fingerprints (ежедневно)
        if (!wp_next_scheduled('cashback_fraud_cleanup_cron')) {
            wp_schedule_event(time(), 'daily', 'cashback_fraud_cleanup_cron');
        }

        // --- API Валидация: cron фоновой синхронизации ---
        $this->require_file('includes/adapters/interface-cashback-network-adapter.php');
        $this->require_file('includes/adapters/abstract-cashback-network-adapter.php');
        $this->require_file('includes/adapters/class-admitad-adapter.php');
        $this->require_file('includes/adapters/class-epn-adapter.php');
        $this->require_file('includes/class-cashback-api-client.php');
        $this->require_file('includes/class-cashback-api-cron.php');
        if (class_exists('Cashback_API_Cron')) {
            Cashback_API_Cron::init();
        }

        // Регистрируем endpoints перед flush, т.к. init хук ещё не сработал
        add_rewrite_endpoint('cashback-withdrawal', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('cashback-history', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('history-payout', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('cashback-support', EP_ROOT | EP_PAGES);

        // Сбрасываем переписывание URL
        flush_rewrite_rules();
    }

    /**
     * Метод деактивации плагина
     */
    public function deactivate()
    {
        $cron_hooks = [
            'cashback_support_auto_delete_cron',
            'cashback_health_check_cron',
            'cashback_fraud_detection_cron',
            'cashback_fraud_cleanup_cron',
            'cashback_api_sync_statuses', // API Валидация: фоновая синхронизация
        ];

        foreach ($cron_hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }
    }

    /**
     * Инициализация основного функционала плагина
     */
    public function init()
    {
        // Проверяем, что WooCommerce активирован
        if (class_exists('WooCommerce')) {
            $this->load_dependencies();
            $this->maybe_run_migrations();
            $this->initialize_components();

            // Одноразовый сброс rewrite rules после обновления кода
            add_action('init', function () {
                if (get_transient('cashback_flush_rewrite_rules')) {
                    delete_transient('cashback_flush_rewrite_rules');
                    flush_rewrite_rules();
                }
            }, 999);

            // Предупреждение если ключ шифрования не настроен
            if (class_exists('Cashback_Encryption') && !Cashback_Encryption::is_configured()) {
                add_action('admin_notices', array($this, 'encryption_key_missing_notice'));
            }

            // Предупреждение если триггеры не созданы
            if (get_option('cashback_triggers_active') === false) {
                add_action('admin_notices', array($this, 'triggers_unavailable_notice'));
            }
        } else {
            add_action('admin_notices', array($this, 'woocommerce_required_notice'));
        }
    }

    /**
     * Загрузка зависимостей плагина
     */
    public function load_dependencies()
    {
        // Подключаем ключ шифрования из wp-content/.cashback-encryption-key.php
        $this->load_encryption_key();

        // Утилита шифрования (загружаем первой, т.к. используется в других компонентах)
        $this->require_file('includes/class-cashback-encryption.php');

        // Утилита проверки статуса пользователя (для блокировки забаненных)
        $this->require_file('includes/class-cashback-user-status.php');

        // PHP-фолбэки для логики MySQL-триггеров
        $this->require_file('includes/class-cashback-trigger-fallbacks.php');

        // Подключение зависимых файлов (общие — нужны на фронтенде и в админке)
        $this->require_file('mariadb.php');
        $this->require_file('cashback-history.php');
        $this->require_file('cashback-withdrawal.php');
        $this->require_file('history-payout.php');
        $this->require_file('wc-affiliate-url-params.php');

        // Модуль поддержки (support-db и user-support нужны на фронтенде)
        $this->require_file('support/support-db.php');
        $this->require_file('support/user-support.php');

        // Антифрод: collector нужен на фронтенде (fingerprint), detector — для WP Cron
        $this->require_file('antifraud/class-fraud-db.php');
        $this->require_file('antifraud/class-fraud-settings.php');
        $this->require_file('antifraud/class-fraud-collector.php');
        $this->require_file('antifraud/class-fraud-detector.php');

        // Health-check cron обработчик (WP Cron работает через фронтенд-запросы)
        $this->require_file('admin/health-check.php');

        // API адаптеры CPA-сетей (загружаются перед API-клиентом)
        $this->require_file('includes/adapters/interface-cashback-network-adapter.php');
        $this->require_file('includes/adapters/abstract-cashback-network-adapter.php');
        $this->require_file('includes/adapters/class-admitad-adapter.php');
        $this->require_file('includes/adapters/class-epn-adapter.php');

        // API клиент и cron (синхронизация работает через WP Cron)
        $this->require_file('includes/class-cashback-api-client.php');
        $this->require_file('includes/class-cashback-api-migration.php');
        $this->require_file('includes/class-cashback-api-cron.php');

        // --- REST API для браузерного расширения ---
        $this->require_file('includes/class-cashback-rest-api.php');

        // Шорткоды (доступны на фронтенде и в превью редактора)
        $this->require_file('includes/class-cashback-shortcodes.php');

        // Admin-only файлы (is_admin() = true для admin pages, admin-ajax.php, REST через admin)
        if (is_admin()) {
            $this->require_file('admin/traits/AdminPaginationTrait.php');
            $this->require_file('admin/payout-methods.php');
            $this->require_file('admin/users-management.php');
            $this->require_file('admin/payouts.php');
            $this->require_file('admin/bank-management.php');
            $this->require_file('admin/click-log.php');
            $this->require_file('admin/transactions.php');
            $this->require_file('admin/statistics.php');
            $this->require_file('partner/partner-management.php');
            $this->require_file('support/admin-support.php');
            $this->require_file('antifraud/class-fraud-admin.php');
            $this->require_file('admin/class-cashback-admin-api-validation.php');
        }
    }

    /**
     * Подключение файла
     *
     * @param string $filename Имя файла для подключения
     */
    private function require_file($filename)
    {
        $filepath = plugin_dir_path(__FILE__) . $filename;
        if (file_exists($filepath)) {
            require_once $filepath;
        } else {
            error_log(sprintf('[Cashback Plugin] Required file not found: %s', $filepath));
        }
    }

    /**
     * Автоматический запуск миграций при обновлении кода плагина (без ре-активации)
     * Использует версию в wp_options для отслеживания выполненных миграций
     */
    private function maybe_run_migrations(): void
    {
        $db_version = get_option('cashback_plugin_db_version', '0');

        // Миграция 1: bank_required колонка в cashback_payout_methods
        if (version_compare($db_version, '1.1.0', '<')) {
            if (class_exists('Mariadb_Plugin')) {
                global $wpdb;
                $table = $wpdb->prefix . 'cashback_payout_methods';

                $column_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'bank_required'",
                    DB_NAME,
                    $table
                ));

                if (!$column_exists) {
                    $wpdb->query(
                        "ALTER TABLE `{$table}` ADD COLUMN `bank_required` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = для этого способа нужно выбрать банк' AFTER `sort_order`"
                    );
                }
            }

            // Сбросить rewrite rules при следующей загрузке (после регистрации endpoints)
            set_transient('cashback_flush_rewrite_rules', 1, 300);

            update_option('cashback_plugin_db_version', '1.1.0');
        }
    }

    /**
     * Инициализация компонентов плагина
     */
    private function initialize_components()
    {
        // Инициализация Mariadb_Plugin (регистрирует user_register хук)
        // mariadb.php загружается в load_dependencies(), но его add_action('plugins_loaded', ...)
        // не срабатывает, т.к. plugins_loaded уже выполнен к этому моменту
        if (class_exists('Mariadb_Plugin')) {
            Mariadb_Plugin::get_instance();
        }

        // Инициализация компонентов
        if (class_exists('CashbackHistory')) {
            CashbackHistory::get_instance();
        }

        if (class_exists('CashbackWithdrawal')) {
            CashbackWithdrawal::get_instance();
        }

        if (class_exists('HistoryPayout')) {
            HistoryPayout::get_instance();
        }

        // Инициализация WC_Affiliate_URL_Params
        if (class_exists('WC_Affiliate_URL_Params')) {
            new WC_Affiliate_URL_Params();
        }

        // Инициализация модуля поддержки (кабинет пользователя)
        if (class_exists('Cashback_User_Support')) {
            Cashback_User_Support::get_instance();
        }

        // Инициализация антифрод-модуля
        if (class_exists('Cashback_Fraud_Collector')) {
            Cashback_Fraud_Collector::get_instance();
        }

        if (is_admin() && class_exists('Cashback_Fraud_Admin')) {
            new Cashback_Fraud_Admin();
        }

        // --- API Валидация: админ-страница + AJAX (только в админке) ---
        if (is_admin() && class_exists('Cashback_Admin_API_Validation')) {
            Cashback_Admin_API_Validation::get_instance();
        }

        // --- API Валидация: cron фоновой синхронизации (фронт + админка) ---
        if (class_exists('Cashback_API_Cron')) {
            Cashback_API_Cron::init();
        }

        // --- REST API для браузерного расширения ---
        if (class_exists('Cashback_REST_API')) {
            Cashback_REST_API::get_instance();
        }

        // Шорткоды
        if (class_exists('Cashback_Shortcodes')) {
            Cashback_Shortcodes::get_instance();
        }
    }

    /**
     * Путь к файлу с ключом шифрования
     */
    private function get_encryption_key_path(): string
    {
        return WP_CONTENT_DIR . '/.cashback-encryption-key.php';
    }

    /**
     * Подключает файл с ключом шифрования если он существует
     */
    private function load_encryption_key(): void
    {
        if (defined('CB_ENCRYPTION_KEY')) {
            return;
        }

        $key_file = $this->get_encryption_key_path();
        if (file_exists($key_file)) {
            require_once $key_file;
        }
    }

    /**
     * Генерирует ключ шифрования и сохраняет в отдельный файл wp-content/.cashback-encryption-key.php
     *
     * @return bool true если ключ уже существует или был успешно создан
     */
    private function maybe_generate_encryption_key(): bool
    {
        // Ключ уже определён (из файла или wp-config.php) — ничего не делаем
        if (defined('CB_ENCRYPTION_KEY')) {
            return true;
        }

        // Пробуем подключить существующий файл ключа
        $this->load_encryption_key();
        if (defined('CB_ENCRYPTION_KEY')) {
            return true;
        }

        $key_file = $this->get_encryption_key_path();
        $key_dir = dirname($key_file);

        if (!is_writable($key_dir)) {
            error_log('Cashback Plugin: Directory not writable for encryption key: ' . $key_dir);
            return false;
        }

        // Генерируем криптографически стойкий ключ
        $key = bin2hex(random_bytes(32));

        // Defence-in-depth: проверяем длину ключа
        if (strlen($key) !== 64) {
            error_log('Cashback Plugin: Generated encryption key has unexpected length: ' . strlen($key));
            return false;
        }

        $content = "<?php\n"
            . "/**\n"
            . " * Cashback Plugin — Encryption Key (auto-generated)\n"
            . " *\n"
            . " * WARNING: Do not share, commit to VCS, or delete this file.\n"
            . " * Loss of this key = loss of access to encrypted user payment details.\n"
            . " */\n"
            . "if (!defined('ABSPATH')) { exit; }\n"
            . "define('CB_ENCRYPTION_KEY', '{$key}');\n";

        $result = file_put_contents($key_file, $content, LOCK_EX);

        if ($result === false) {
            error_log('Cashback Plugin: Failed to write encryption key file: ' . $key_file);
            return false;
        }

        // Определяем константу для текущего запроса
        define('CB_ENCRYPTION_KEY', $key);

        error_log('Cashback Plugin: Encryption key generated and saved to ' . $key_file);
        return true;
    }

    /**
     * Уведомление об отсутствии ключа шифрования
     */
    public function encryption_key_missing_notice()
    {
        $key_file = $this->get_encryption_key_path();
        printf(
            '<div class="notice notice-error"><p><strong>%s:</strong> %s <code>%s</code></p></div>',
            esc_html__('Cashback Plugin', 'cashback-plugin'),
            esc_html__('Не удалось создать файл с ключом шифрования. Проверьте права на запись в директорию wp-content. Ожидаемый путь:', 'cashback-plugin'),
            esc_html($key_file)
        );
    }

    public function triggers_unavailable_notice()
    {
        printf(
            '<div class="notice notice-warning"><p><strong>%s:</strong> %s</p></div>',
            esc_html__('Cashback Plugin', 'cashback-plugin'),
            esc_html__('MySQL-триггеры не были созданы (binary logging без SUPER привилегии). Плагин работает в режиме PHP-фолбэков. Для полной защиты данных на уровне БД обратитесь к хостинг-провайдеру.', 'cashback-plugin')
        );
    }

    /**
     * Уведомление о необходимости установки WooCommerce
     */
    public function woocommerce_required_notice()
    {
        $message = sprintf(
            '<strong>%s</strong> %s',
            esc_html__('Cashback Plugin', 'cashback-plugin'),
            esc_html__('requires WooCommerce to be installed and active.', 'cashback-plugin')
        );
        printf('<div class="notice notice-error"><p>%s</p></div>', wp_kses_post($message));
    }
}

// Инициализация плагина
new CashbackPlugin();

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
        // Создание таблиц поддержки
        $this->require_file('support/support-db.php');
        if (class_exists('Cashback_Support_DB')) {
            Cashback_Support_DB::create_tables();
        }

        // Планируем cron для автоудаления закрытых тикетов (через 1 месяц)
        if (!wp_next_scheduled('cashback_support_auto_delete_cron')) {
            wp_schedule_event(time(), 'daily', 'cashback_support_auto_delete_cron');
        }

        // Планируем cron для мониторинга целостности данных
        if (!wp_next_scheduled('cashback_health_check_cron')) {
            wp_schedule_event(time(), 'daily', 'cashback_health_check_cron');
        }

        // Сбрасываем переписывание URL
        flush_rewrite_rules();
    }

    /**
     * Метод деактивации плагина
     */
    public function deactivate()
    {
        $timestamp = wp_next_scheduled('cashback_support_auto_delete_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'cashback_support_auto_delete_cron');
        }

        $timestamp = wp_next_scheduled('cashback_health_check_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'cashback_health_check_cron');
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
            $this->initialize_components();
        } else {
            add_action('admin_notices', array($this, 'woocommerce_required_notice'));
        }
    }

    /**
     * Загрузка зависимостей плагина
     */
    public function load_dependencies()
    {
        // Подключение зависимых файлов
        $this->require_file('mariadb.php');
        $this->require_file('cashback-history.php');
        $this->require_file('cashback-withdrawal.php');
        $this->require_file('history-payout.php');
        $this->require_file('wc-affiliate-url-params.php');
        $this->require_file('admin/traits/AdminPaginationTrait.php');
        $this->require_file('admin/payout-methods.php');
        $this->require_file('admin/users-management.php');
        $this->require_file('admin/payouts.php');
        $this->require_file('admin/bank-management.php');
        $this->require_file('admin/health-check.php');

        // Модуль поддержки
        $this->require_file('support/support-db.php');
        $this->require_file('support/admin-support.php');
        $this->require_file('support/user-support.php');
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

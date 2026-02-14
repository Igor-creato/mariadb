<?php

declare(strict_types=1);

/**
 * Plugin Name: Cashback Plugin
 * Description: Объединенный плагин для системы кэшбэка и аффилиат-партнерства
 * Version: 1.0.0
 * Author: Cashback
 * Text Domain: cashback-plugin
 */

// Запрет прямого доступа
defined('ABSPATH') or die('No script kiddies please!');

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
        $this->require_file('admin/payout-methods.php');
        $this->require_file('admin/users-management.php');
        $this->require_file('admin/payouts.php');
        $this->require_file('admin/bank-management.php');

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
        }
    }

    /**
     * Инициализация компонентов плагина
     */
    private function initialize_components()
    {
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
        echo '<div class="notice notice-error"><p><strong>Cashback Plugin</strong> requires WooCommerce to be installed and active.</p></div>';
    }
}

// Инициализация плагина
new CashbackPlugin();

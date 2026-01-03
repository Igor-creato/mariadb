<?php

/**
 * Plugin Name: Cashback Plugin
 * Description: Объединенный плагин для системы кэшбэка и аффилиат-партнерства
 * Version: 1.0.0
 * Author: Cashback
 * Text Domain: cashback-plugin
 */

// Запрет прямого доступа
defined('ABSPATH') or die('No script kiddies please!');

class CashbackPlugin
{

    public function __construct()
    {
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('plugins_loaded', array($this, 'init'));
    }

    public function activate()
    {
        // Подключаем файл mariadb.php для активации
        $this->require_file('mariadb.php');
        // Активация основного функционала (таблицы, триггеры, события)
        if (class_exists('Mariadb_Plugin')) {
            Mariadb_Plugin::activate();
        }
        // Сбрасываем переписывание URL
        flush_rewrite_rules();
    }

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

    public function load_dependencies()
    {
        // Подключение зависимых файлов
        $this->require_file('mariadb.php');
        $this->require_file('cashback-history.php');
        $this->require_file('cashback-withdrawal.php');
        $this->require_file('history-payout.php');
        $this->require_file('wc-affiliate-url-params.php');
    }

    private function require_file($filename)
    {
        $filepath = plugin_dir_path(__FILE__) . $filename;
        if (file_exists($filepath)) {
            require_once $filepath;
        }
    }

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

        // WC_Affiliate_URL_Params будет инициализирован через хук, когда будет доступен WooCommerce
        add_action('init', function () {
            if (class_exists('WooCommerce') && class_exists('WC_Affiliate_URL_Params')) {
                new WC_Affiliate_URL_Params();
            }
        });
    }

    public function woocommerce_required_notice()
    {
        echo '<div class="notice notice-error"><p><strong>Cashback Plugin</strong> requires WooCommerce to be installed and active.</p></div>';
    }
}

// Инициализация плагина
new CashbackPlugin();

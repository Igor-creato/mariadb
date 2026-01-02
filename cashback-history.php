<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CashbackHistory
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'register_endpoint'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_item'));
        add_action('woocommerce_account_cashback-history_endpoint', array($this, 'content'));
        add_action('wp_ajax_load_page_transactions', array($this, 'ajax_load_page'));
        add_action('wp_ajax_nopriv_load_page_transactions', array($this, 'ajax_load_page'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function register_endpoint()
    {
        add_rewrite_endpoint('cashback-history', EP_ROOT | EP_PAGES);
    }

    public function add_query_vars($vars)
    {
        $vars[] = 'cashback-history';
        return $vars;
    }

    public function add_menu_item($items)
    {
        $logout = $items['customer-logout'];
        unset($items['customer-logout']);
        $items['cashback-history'] = __('История покупок', 'cashback-history');
        $items['customer-logout'] = $logout;
        return $items;
    }

    public function content()
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            echo '<p>' . __('Вы должны быть авторизованы.', 'cashback-history') . '</p>';
            return;
        }

        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $per_page = 10;
        $offset = ($page - 1) * $per_page;

        $transactions = $this->get_transactions($user_id, $per_page, $offset);
        $total = $this->get_total_transactions($user_id);
        $total_pages = ceil($total / $per_page);

        echo '<div class="wd-cashback-history">';
        echo '<h2>' . __('История покупок', 'cashback-history') . '</h2>';

        if (empty($transactions)) {
            echo '<p>' . __('У вас нет истории покупок.', 'cashback-history') . '</p>';
        } else {
            echo '<table class="wd-table shop_table_responsive">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>' . __('Дата', 'cashback-history') . '</th>';
            echo '<th>' . __('Магазин', 'cashback-history') . '</th>';
            echo '<th>' . __('Кэшбэк', 'cashback-history') . '</th>';
            echo '<th>' . __('Статус', 'cashback-history') . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody id="transactions-body">';

            foreach ($transactions as $transaction) {
                echo '<tr>';
                echo '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($transaction->created_at))) . '</td>';
                echo '<td>' . esc_html($transaction->offer_name) . '</td>';
                echo '<td>' . esc_html($transaction->cashback) . '</td>';
                echo '<td>' . esc_html($this->get_status_label($transaction->order_status)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';

            if ($total_pages > 1) {
                echo '<div class="wd-pagination" id="pagination-container">';
                $this->render_pagination($page, $total_pages);
                echo '</div>';
            }
        }

        echo '</div>';
    }

    private function render_pagination($current_page, $total_pages)
    {
        echo '<nav class="woocommerce-pagination">';
        echo '<ul class="page-numbers">';
        for ($i = 1; $i <= $total_pages; $i++) {
            $class = ($i == $current_page) ? 'current' : '';
            echo '<li><a href="#" class="page-numbers ' . esc_attr($class) . '" data-page="' . esc_attr($i) . '">' . esc_html($i) . '</a></li>';
        }
        echo '</ul>';
        echo '</nav>';
    }

    private function get_transactions($user_id, $limit, $offset)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_transactions';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT created_at, offer_name, cashback, order_status FROM {$table_name} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        ));
    }

    private function get_total_transactions($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_transactions';
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));
    }

    public function ajax_load_page()
    {
        check_ajax_referer('load_page_transactions_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_die(__('Вы должны быть авторизованы.', 'cashback-history'));
        }

        $page = intval($_POST['page']);
        $per_page = 10;
        $offset = ($page - 1) * $per_page;

        $transactions = $this->get_transactions($user_id, $per_page, $offset);

        $html = '';
        foreach ($transactions as $transaction) {
            $html .= '<tr>';
            $html .= '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($transaction->created_at))) . '</td>';
            $html .= '<td>' . esc_html($transaction->offer_name) . '</td>';
            $html .= '<td>' . esc_html($transaction->cashback) . '</td>';
            $html .= '<td>' . esc_html($this->get_status_label($transaction->order_status)) . '</td>';
            $html .= '</tr>';
        }

        wp_send_json_success(array('html' => $html));
    }

    public function enqueue_scripts()
    {
        if (is_account_page()) {
            wp_enqueue_script('cashback-history-ajax', plugin_dir_url(__FILE__) . 'cashback-history.js', array('jquery'), '1.0.0', true);
            wp_localize_script('cashback-history-ajax', 'cashback_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('load_page_transactions_nonce')
            ));
        }
    }

    private function get_status_label($status)
    {
        switch ($status) {
            case 'waiting':
                return __('В ожидании', 'cashback-history');
            case 'completed':
                return __('Подтвержден', 'cashback-history');
            case 'declined':
                return __('Отклонен', 'cashback-history');
            case 'balance':
                return __('Зачислен на баланс', 'cashback-history');
            default:
                return esc_html($status);
        }
    }
}

// Инициализация будет происходить в основном файле плагина

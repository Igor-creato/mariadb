<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CashbackHistory
{
    private const PER_PAGE = 10;
    private const MAX_ALLOWED_PAGES = 1000; // Защита от DoS (макс. 10 000 записей)

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
        if (isset($items['customer-logout'])) {
            $logout = $items['customer-logout'];
            unset($items['customer-logout']);
            $items['cashback-history'] = __('История покупок', 'cashback-history');
            $items['customer-logout'] = $logout;
        } else {
            $items['cashback-history'] = __('История покупок', 'cashback-history');
        }
        return $items;
    }

    public function content()
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            echo '<p>' . esc_html__('Вы должны быть авторизованы.', 'cashback-history') . '</p>';
            return;
        }

        $per_page = self::PER_PAGE;
        $total = $this->get_total_transactions($user_id);
        $total_pages = $total > 0 ? ceil($total / $per_page) : 1;
        $total_pages = min($total_pages, self::MAX_ALLOWED_PAGES);

        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $page = max(1, min($page, $total_pages));
        $offset = ($page - 1) * $per_page;

        $transactions = $this->get_transactions($user_id, $per_page, $offset);

        echo '<div class="wd-cashback-history">';
        echo '<h2>' . esc_html__('История покупок', 'cashback-history') . '</h2>';

        if (empty($transactions)) {
            echo '<p>' . esc_html__('У вас нет истории покупок.', 'cashback-history') . '</p>';
        } else {
            echo '<table class="wd-table shop_table_responsive">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>' . esc_html__('Дата', 'cashback-history') . '</th>';
            echo '<th>' . esc_html__('Магазин', 'cashback-history') . '</th>';
            echo '<th>' . esc_html__('Кэшбэк', 'cashback-history') . '</th>';
            echo '<th>' . esc_html__('Статус', 'cashback-history') . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody id="transactions-body">';

            foreach ($transactions as $transaction) {
                echo '<tr>';
                echo '<td>' . $this->format_date($transaction->created_at) . '</td>';
                echo '<td>' . esc_html($transaction->offer_name ?? __('Н/Д', 'cashback-history')) . '</td>';
                echo '<td>' . esc_html($transaction->cashback ?? '0.00') . '</td>';
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
            "SELECT created_at, offer_name, cashback, order_status 
             FROM {$table_name} 
             WHERE user_id = %d 
             ORDER BY created_at DESC 
             LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        ));
    }

    private function get_total_transactions($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_transactions';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));
    }

    public function ajax_load_page()
    {
        check_ajax_referer('load_page_transactions_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(esc_html__('Вы должны быть авторизованы.', 'cashback-history'));
        }

        if (!isset($_POST['page'])) {
            wp_send_json_error(esc_html__('Некорректный запрос.', 'cashback-history'));
        }

        $per_page = self::PER_PAGE;
        $total = $this->get_total_transactions($user_id);
        $total_pages = $total > 0 ? ceil($total / $per_page) : 1;
        $total_pages = min($total_pages, self::MAX_ALLOWED_PAGES);

        $page = intval($_POST['page']);
        $page = max(1, min($page, $total_pages));
        $offset = ($page - 1) * $per_page;

        $transactions = $this->get_transactions($user_id, $per_page, $offset);

        $html = '';
        foreach ($transactions as $transaction) {
            $html .= '<tr>';
            $html .= '<td>' . $this->format_date($transaction->created_at) . '</td>';
            $html .= '<td>' . esc_html($transaction->offer_name ?? __('Н/Д', 'cashback-history')) . '</td>';
            $html .= '<td>' . esc_html($transaction->cashback ?? '0.00') . '</td>';
            $html .= '<td>' . esc_html($this->get_status_label($transaction->order_status)) . '</td>';
            $html .= '</tr>';
        }

        wp_send_json_success(array(
            'html' => $html,
            'current_page' => $page,
            'total_pages' => $total_pages
        ));
    }

    public function enqueue_scripts()
    {
        if (is_account_page() && $this->is_cashback_history_page()) {
            wp_enqueue_script(
                'cashback-history-ajax',
                plugin_dir_url(__FILE__) . 'cashback-history.js',
                array('jquery'),
                '1.0.0',
                true
            );
            wp_localize_script('cashback-history-ajax', 'cashback_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('load_page_transactions_nonce')
            ));
        }
    }

    private function is_cashback_history_page()
    {
        global $wp;
        return isset($wp->query_vars['cashback-history']);
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
                return esc_html($status ?: __('Неизвестно', 'cashback-history'));
        }
    }

    /**
     * Безопасное форматирование даты с защитой от некорректных значений
     */
    private function format_date($date_string)
    {
        if (empty($date_string) || $date_string === '0000-00-00 00:00:00') {
            return esc_html__('Н/Д', 'cashback-history');
        }

        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return esc_html__('Некорректная дата', 'cashback-history');
        }

        return esc_html(date_i18n(get_option('date_format'), $timestamp));
    }
}

// Инициализация будет происходить в основном файле плагина
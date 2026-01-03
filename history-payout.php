<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class HistoryPayout
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
        add_action('woocommerce_account_history-payout_endpoint', array($this, 'content'));
        add_action('wp_ajax_load_page_payouts', array($this, 'ajax_load_page'));
        add_action('wp_ajax_nopriv_load_page_payouts', array($this, 'ajax_load_page'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function register_endpoint()
    {
        add_rewrite_endpoint('history-payout', EP_ROOT | EP_PAGES);
    }

    public function add_query_vars($vars)
    {
        $vars[] = 'history-payout';
        return $vars;
    }

    public function add_menu_item($items)
    {
        if (isset($items['customer-logout'])) {
            $logout = $items['customer-logout'];
            unset($items['customer-logout']);
            $items['history-payout'] = __('История выплат', 'history-payout');
            $items['customer-logout'] = $logout;
        } else {
            $items['history-payout'] = __('История выплат', 'history-payout');
        }
        return $items;
    }

    public function content()
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            echo '<p>' . esc_html__('Вы должны быть авторизованы.', 'history-payout') . '</p>';
            return;
        }

        $per_page = self::PER_PAGE;
        $total = $this->get_total_payouts($user_id);
        $total_pages = $total > 0 ? ceil($total / $per_page) : 1;
        $total_pages = min($total_pages, self::MAX_ALLOWED_PAGES);

        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $page = max(1, min($page, $total_pages));
        $offset = ($page - 1) * $per_page;

        $payouts = $this->get_payouts($user_id, $per_page, $offset);

        echo '<div class="wd-history-payout">';
        echo '<h2>' . esc_html__('История выплат', 'history-payout') . '</h2>';

        if (empty($payouts)) {
            echo '<p>' . esc_html__('У вас нет истории выплат.', 'history-payout') . '</p>';
        } else {
            echo '<table class="wd-table shop_table_responsive">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>' . esc_html__('Дата', 'history-payout') . '</th>';
            echo '<th>' . esc_html__('Сумма', 'history-payout') . '</th>';
            echo '<th>' . esc_html__('Статус', 'history-payout') . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody id="payouts-body">';

            foreach ($payouts as $payout) {
                echo '<tr>';
                echo '<td>' . $this->format_date($payout->created_at) . '</td>';
                echo '<td>' . esc_html($payout->total_amount ?? '0.00') . '</td>';
                echo '<td>' . esc_html($this->get_status_label($payout->status)) . '</td>';
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

    private function get_payouts($user_id, $limit, $offset)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_payout_requests';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT created_at, total_amount, status 
             FROM {$table_name} 
             WHERE user_id = %d 
             ORDER BY created_at DESC 
             LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        ));
    }

    private function get_total_payouts($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_payout_requests';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));
    }

    public function ajax_load_page()
    {
        check_ajax_referer('load_page_payouts_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(esc_html__('Вы должны быть авторизованы.', 'history-payout'));
        }

        if (!isset($_POST['page'])) {
            wp_send_json_error(esc_html__('Некорректный запрос.', 'history-payout'));
        }

        $per_page = self::PER_PAGE;
        $total = $this->get_total_payouts($user_id);
        $total_pages = $total > 0 ? ceil($total / $per_page) : 1;
        $total_pages = min($total_pages, self::MAX_ALLOWED_PAGES);

        $page = intval($_POST['page']);
        $page = max(1, min($page, $total_pages));
        $offset = ($page - 1) * $per_page;

        $payouts = $this->get_payouts($user_id, $per_page, $offset);

        $html = '';
        foreach ($payouts as $payout) {
            $html .= '<tr>';
            $html .= '<td>' . $this->format_date($payout->created_at) . '</td>';
            $html .= '<td>' . esc_html($payout->total_amount ?? '0.00') . '</td>';
            $html .= '<td>' . esc_html($this->get_status_label($payout->status)) . '</td>';
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
        if (is_account_page() && $this->is_history_payout_page()) {
            wp_enqueue_script(
                'history-payout-ajax',
                plugin_dir_url(__FILE__) . 'assets/css/history-payout.js',
                array('jquery'),
                '1.0.0',
                true
            );
            wp_localize_script('history-payout-ajax', 'payout_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('load_page_payouts_nonce')
            ));
        }
    }

    private function is_history_payout_page()
    {
        global $wp;
        return isset($wp->query_vars['history-payout']);
    }

    private function get_status_label($status)
    {
        switch ($status) {
            case 'waiting':
                return __('В обработке', 'history-payout');
            case 'payd':
                return __('Выплачен', 'history-payout');
            case 'declined':
                return __('Отклонен', 'history-payout');
            default:
                return esc_html($status ?: __('Неизвестно', 'history-payout'));
        }
    }

    /**
     * Безопасное форматирование даты с защитой от некорректных значений
     */
    private function format_date($date_string)
    {
        if (empty($date_string) || $date_string === '0000-00-00 00:00:00') {
            return esc_html__('Н/Д', 'history-payout');
        }

        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return esc_html__('Некорректная дата', 'history-payout');
        }

        return esc_html(date_i18n(get_option('date_format'), $timestamp));
    }
}

// Инициализация будет происходить в основном файле плагина
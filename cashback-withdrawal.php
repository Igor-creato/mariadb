<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class CashbackWithdrawal
 *
 * Handles the cashback withdrawal functionality in WooCommerce My Account.
 */
class CashbackWithdrawal
{

    /**
     * Instance of the class (singleton pattern)
     */
    private static $instance = null;

    /**
     * Get instance of the class
     *
     * @return CashbackWithdrawal
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        add_action('init', array($this, 'register_endpoint'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_item'));
        add_action('woocommerce_account_cashback-withdrawal_endpoint', array($this, 'endpoint_content'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    /**
     * Register the custom endpoint
     */
    public function register_endpoint()
    {
        add_rewrite_endpoint('cashback-withdrawal', EP_ROOT | EP_PAGES);
        add_filter('query_vars', array($this, 'add_query_vars'));
    }

    /**
     * Add query vars
     */
    public function add_query_vars($vars)
    {
        $vars[] = 'cashback-withdrawal';
        return $vars;
    }

    /**
     * Add menu item to My Account navigation
     *
     * @param array $items
     * @return array
     */
    public function add_menu_item($items)
    {
        // Insert after 'orders'
        $new_items = array();
        $new_items['cashback-withdrawal'] = __('Вывод кэшбэка', 'woocommerce');

        return $this->insert_after_helper($items, $new_items, 'orders');
    }

    /**
     * Helper function to insert items after a specific key
     *
     * @param array $items
     * @param array $new_items
     * @param string $after
     * @return array
     */
    private function insert_after_helper($items, $new_items, $after)
    {
        $position = array_search($after, array_keys($items)) + 1;
        $array = array_slice($items, 0, $position, true);
        $array += $new_items;
        $array += array_slice($items, $position, count($items) - $position, true);
        return $array;
    }

    /**
     * Get user's available balance
     *
     * @param int $user_id
     * @return float
     */
    private function get_available_balance($user_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_balance';
        $balance = $wpdb->get_var($wpdb->prepare(
            "SELECT available_balance FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));

        return (float) ($balance ?: 0.0);
    }

    /**
     * Display content for the endpoint
     */
    public function endpoint_content()
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            echo '<p>' . __('Вы должны быть авторизованы для просмотра этой страницы.', 'woocommerce') . '</p>';
            return;
        }

        $balance = $this->get_available_balance($user_id);

        echo '<div class="cashback-withdrawal-container">';
        echo '<h2>' . __('Вывод кэшбэка', 'woocommerce') . '</h2>';
        echo '<div class="balance-display">';
        echo '<p>' . __('Доступный баланс:', 'woocommerce') . ' <span id="cashback-balance-amount" class="balance-amount ' . ($balance > 0 ? 'balance-green' : 'balance-gray') . '">' . wc_price($balance) . '</span></p>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Enqueue custom styles
     */
    public function enqueue_styles()
    {
        if (is_user_logged_in() && !is_admin()) {
            wp_enqueue_style(
                'cashback-withdrawal-styles',
                plugins_url('assets/css/frontend.css', __FILE__),
                array(),
                '1.0.0'
            );
        }
    }
}

// Инициализация будет происходить в основном файле плагина

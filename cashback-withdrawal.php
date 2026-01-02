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
        // AJAX обработчики для вывода кэшбэка
        add_action('wp_ajax_process_cashback_withdrawal', array($this, 'process_cashback_withdrawal'));
        add_action('wp_ajax_nopriv_process_cashback_withdrawal', array($this, 'process_cashback_withdrawal'));
        // AJAX обработчик для обновления баланса
        add_action('wp_ajax_get_user_balance', array($this, 'get_user_balance_ajax'));
        add_action('wp_ajax_nopriv_get_user_balance', array($this, 'get_user_balance_ajax'));
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
     * Get minimum payout amount for user
     *
     * @param int $user_id
     * @return float
     */
    private function get_min_payout_amount($user_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_profile';
        $min_payout_amount = $wpdb->get_var($wpdb->prepare(
            "SELECT min_payout_amount FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));

        return (float) ($min_payout_amount ?: 100.00); // Default to 100.00 if not set
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
        $min_payout_amount = $this->get_min_payout_amount($user_id);

        echo '<div class="cashback-withdrawal-container">';
        echo '<h2>' . __('Вывод кэшбэка', 'woocommerce') . '</h2>';
        echo '<div class="balance-display">';
        echo '<p>' . __('Доступный баланс:', 'woocommerce') . ' <span id="cashback-balance-amount" class="balance-amount ' . ($balance > 0 ? 'balance-green' : 'balance-gray') . '">' . wc_price($balance) . '</span></p>';
        echo '</div>';
        echo '<p>' . __('Минимальная сумма выплаты:', 'woocommerce') . ' <span class="min-payout-amount">' . wc_price($min_payout_amount) . '</span></p>';
        echo '</div>';

        // Добавляем форму вывода кэшбэка
        echo '<div class="cashback-withdrawal-form">';
        echo '<form id="withdrawal-form">';
        echo '<p class="form-row">';
        echo '<label for="withdrawal-amount">' . __('Сумма вывода', 'woocommerce') . ' <span class="required">*</span></label>';
        echo '<input type="number" class="input-text" name="withdrawal_amount" id="withdrawal-amount" placeholder="' . __('Введите сумму', 'woocommerce') . '" value="" min="' . $min_payout_amount . '" max="' . $balance . '" step="0.01" />';
        echo '</p>';
        echo '<p class="form-row">';
        echo '<button type="submit" class="button alt" id="withdrawal-submit" name="withdrawal_submit" value="' . esc_attr__('Вывести', 'woocommerce') . '">' . __('Вывести', 'woocommerce') . '</button>';
        echo '</p>';
        echo '<div id="withdrawal-messages"></div>';
        echo '</form>';
        echo '</div>';

        // Добавляем nonce для безопасности
        wp_nonce_field('cashback_withdrawal_nonce', 'withdrawal_nonce');
    }

    /**
     * Process cashback withdrawal request
     */
    public function process_cashback_withdrawal()
    {
        // Проверяем nonce для безопасности
        if (!wp_verify_nonce($_POST['nonce'], 'cashback_withdrawal_nonce')) {
            wp_die('Security check failed');
        }

        // Проверяем, авторизован ли пользователь
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Вы должны быть авторизованы для выполнения этого действия.', 'woocommerce'));
            return;
        }

        $user_id = get_current_user_id();
        $withdrawal_amount = floatval($_POST['withdrawal_amount']);

        // Получаем минимальную сумму вывода для пользователя
        $min_payout_amount = $this->get_min_payout_amount($user_id);
        // Получаем доступный баланс пользователя
        $available_balance = $this->get_available_balance($user_id);

        // Проверяем, что введенная сумма больше или равна минимальной
        if ($withdrawal_amount < $min_payout_amount) {
            wp_send_json_error(__('Вы ввели сумму меньше минимально допустимой, введите другую сумму', 'woocommerce'));
            return;
        }

        // Проверяем, что введенная сумма не превышает доступный баланс
        if ($withdrawal_amount > $available_balance) {
            wp_send_json_error(__('Вы ввели сумму больше доступной, введите другую сумму', 'woocommerce'));
            return;
        }

        global $wpdb;
        $table_name_balance = $wpdb->prefix . 'cashback_user_balance';
        $table_name_requests = $wpdb->prefix . 'cashback_payout_requests';

        // Используем транзакцию для обеспечения безопасности и целостности данных
        $wpdb->query('START TRANSACTION');

        try {
            // Обновляем баланс: уменьшаем available_balance и увеличиваем pending_balance
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table_name_balance}
                SET available_balance = available_balance - %f,
                    pending_balance = pending_balance + %f
                WHERE user_id = %d",
                $withdrawal_amount,
                $withdrawal_amount,
                $user_id
            ));

            if ($result === false) {
                throw new Exception('Failed to update user balance');
            }

            // Добавляем запись в таблицу запросов на вывод
            $result = $wpdb->insert(
                $table_name_requests,
                array(
                    'user_id' => $user_id,
                    'total_amount' => $withdrawal_amount,
                    'status' => 'waiting'
                ),
                array('%d', '%f', '%s')
            );

            if ($result === false) {
                throw new Exception('Failed to insert payout request');
            }

            // Фиксируем транзакцию
            $wpdb->query('COMMIT');

            // Отправляем успешный ответ
            wp_send_json_success(sprintf(__('Заявка на вывод кэшбэка на сумму %s руб. успешно добавлена', 'woocommerce'), number_format($withdrawal_amount, 2, '.', ' ')));
        } catch (Exception $e) {
            // Откатываем транзакцию в случае ошибки
            $wpdb->query('ROLLBACK');
            wp_send_json_error(__('Ошибка при обработке запроса на вывод. Пожалуйста, попробуйте еще раз.', 'woocommerce'));
        }
    }

    /**
     * Enqueue custom styles and scripts
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

            // Подключаем скрипты для обработки формы вывода
            wp_enqueue_script(
                'cashback-withdrawal-js',
                plugins_url('assets/js/frontend.js', __FILE__),
                array('jquery'),
                '1.0.0',
                true
            );

            // Передаем AJAX URL в JavaScript
            wp_localize_script('cashback-withdrawal-js', 'cashback_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cashback_withdrawal_nonce')
            ));
        }
    }

    /**
     * AJAX обработчик для получения баланса пользователя
     */
    public function get_user_balance_ajax()
    {
        // Проверяем, авторизован ли пользователь
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Вы должны быть авторизованы для выполнения этого действия.', 'woocommerce'));
            return;
        }

        $user_id = get_current_user_id();
        $balance = $this->get_available_balance($user_id);

        wp_send_json_success(array(
            'balance' => $balance,
            'formatted_balance' => wc_price($balance)
        ));
    }
}

// Инициализация будет происходить в основном файле плагина

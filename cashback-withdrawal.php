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
     * Get payout method for user
     *
     * @param int $user_id
     * @return string|null
     */
    private function get_payout_method($user_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_profile';
        $payout_method = $wpdb->get_var($wpdb->prepare(
            "SELECT payout_method FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));

        return $payout_method;
    }

    /**
     * Get payout account for user
     *
     * @param int $user_id
     * @return string|null
     */
    private function get_payout_account($user_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_profile';
        $payout_account = $wpdb->get_var($wpdb->prepare(
            "SELECT payout_account FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));

        return $payout_account;
    }

    /**
     * Get payout method label for display
     *
     * @param string $method
     * @return string
     */
    private function get_payout_method_label($method)
    {
        $labels = array(
            'sbp' => __('Система быстрых платежей (СБП)', 'woocommerce'),
            'mir' => __('Карта МИР', 'woocommerce'),
            'yoomoney' => __('ЮMoney', 'woocommerce')
        );

        return isset($labels[$method]) ? $labels[$method] : ucfirst($method);
    }

    /**
     * Display content for the endpoint
     */
    public function endpoint_content()
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            // Вместо вывода сообщения об ошибке, просто выходим
            // Ошибка авторизации будет обрабатываться через AJAX
            echo '<div class="cashback-withdrawal-container">';
            echo '<h2>' . __('Вывод кэшбэка', 'woocommerce') . '</h2>';
            echo '<div id="withdrawal-messages"></div>';
            echo '<div id="cashback-content">';
            echo '<div class="balance-display">';
            echo '<p>' . __('Доступный баланс:', 'woocommerce') . ' <span id="cashback-balance-amount" class="balance-amount">0</span></p>';
            echo '</div>';
            echo '<p>' . __('Минимальная сумма выплаты:', 'woocommerce') . ' <span class="min-payout-amount">0</span></p>';
            echo '<div class="error-message">' . __('Вы должны быть авторизованы для просмотра этой страницы.', 'woocommerce') . '</div>';
            echo '</div>';
            echo '</div>';
            echo '<div class="cashback-withdrawal-form">';
            echo '<form id="withdrawal-form">';
            echo '<p class="form-row">';
            echo '<label for="withdrawal-amount">' . __('Сумма вывода', 'woocommerce') . ' <span class="required">*</span></label>';
            echo '<input type="number" class="input-text" name="withdrawal_amount" id="withdrawal-amount" placeholder="' . __('Введите сумму', 'woocommerce') . '" value="" min="0" max="0" step="0.01" disabled/>';
            echo '</p>';
            echo '<p class="form-row">';
            echo '<button type="submit" class="button alt" id="withdrawal-submit" name="withdrawal_submit" value="' . esc_attr__('Вывести', 'woocommerce') . '" disabled>' . __('Вывести', 'woocommerce') . '</button>';
            echo '</p>';
            echo '<div id="withdrawal-messages"></div>';
            echo '</form>';
            echo '</div>';
            return;
        }

        $balance = $this->get_available_balance($user_id);
        $min_payout_amount = $this->get_min_payout_amount($user_id);

        // Получаем информацию о способе вывода и номере счета
        $payout_method = $this->get_payout_method($user_id);
        $payout_account = $this->get_payout_account($user_id);

        echo '<div class="cashback-withdrawal-container">';
        echo '<h2>' . __('Вывод кэшбэка', 'woocommerce') . '</h2>';
        echo '<div class="balance-display">';
        echo '<p>' . __('Доступный баланс:', 'woocommerce') . ' <span id="cashback-balance-amount" class="balance-amount ' . ($balance > 0 ? 'balance-green' : 'balance-gray') . '">' . wc_price($balance) . '</span></p>';
        echo '</div>';
        echo '<p>' . __('Минимальная сумма выплаты:', 'woocommerce') . ' <span class="min-payout-amount">' . wc_price($min_payout_amount) . '</span></p>';

        // Отображаем способ вывода и номер счета
        echo '<div class="payout-details">';
        if ($payout_method && $payout_account) {
            echo '<p>' . __('Способ вывода:', 'woocommerce') . ' <strong>' . $this->get_payout_method_label($payout_method) . '</strong></p>';
            echo '<p>' . __('Номер счета:', 'woocommerce') . ' <strong>' . esc_html($payout_account) . '</strong></p>';
        } else {
            echo '<p class="no-payout-details">' . __('Выберите способ вывода и номер счета.', 'woocommerce') . '</p>';
        }
        echo '</div>';
        echo '</div>';

        // Добавляем форму вывода кэшбэка
        echo '<div class="cashback-withdrawal-form">';
        echo '<form id="withdrawal-form">';
        echo '<p class="form-row">';
        echo '<label for="withdrawal-amount">' . __('Сумма вывода', 'woocommerce') . ' <span class="required">*</span></label>';
        echo '<input type="number" class="input-text" name="withdrawal_amount" id="withdrawal-amount" placeholder="' . __('Введите сумму', 'woocommerce') . '" value="" step="0.01" />';
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
    /**
    /**
     * Process cashback withdrawal request with concurrency-safe balance handling.
     * Allows multiple withdrawal requests (as long as balance permits),
     * but prevents race conditions during balance deduction.
     */
    public function process_cashback_withdrawal()
    {
        // === 1. Security: nonce and authentication ===
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'cashback_withdrawal_nonce')) {
            wp_die('Security check failed', 403);
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(__('Вы должны быть авторизованы для выполнения этого действия.', 'woocommerce'));
            return;
        }

        $user_id = get_current_user_id();
        $withdrawal_amount = floatval($_POST['withdrawal_amount'] ?? 0);

        // === 2. Input validation ===
        $min_payout_amount = $this->get_min_payout_amount($user_id);
        $available_balance = $this->get_available_balance($user_id);

        if ($withdrawal_amount <= 0) {
            wp_send_json_error(__('Сумма вывода должна быть положительной.', 'woocommerce'));
            return;
        }

        // Проверяем, что баланс пользователя больше или равен минимальной сумме для вывода
        if ($available_balance < $min_payout_amount) {
            wp_send_json_error(sprintf(__('Вы не можете вывести средства, Ваш баланс %s меньше минимально допустимой суммы для вывода %s', 'woocommerce'), wc_price($available_balance), wc_price($min_payout_amount)));
            return;
        }

        if ($withdrawal_amount < $min_payout_amount) {
            wp_send_json_error(sprintf(__('Вы ввели сумму меньше минимально допустимой, введите сумму больше или равно %s', 'woocommerce'), wc_price($min_payout_amount)));
            return;
        }

        if ($withdrawal_amount > $available_balance) {
            wp_send_json_error(sprintf(__('Вы ввели сумму больше доступной, введите сумму меньше или равно %s', 'woocommerce'), wc_price($available_balance)));
            return;
        }

        // === 3. Atomic balance deduction with row-level locking ===
        global $wpdb;
        $table_balance = $wpdb->prefix . 'cashback_user_balance';
        $table_requests = $wpdb->prefix . 'cashback_payout_requests';

        $wpdb->query('START TRANSACTION');

        try {
            // 🔒 CRITICAL: Lock the user's balance row to prevent race conditions
            $user_balance = $wpdb->get_row($wpdb->prepare(
                "SELECT available_balance, pending_balance
             FROM {$table_balance}
             WHERE user_id = %d FOR UPDATE",
                $user_id
            ));

            if (!$user_balance) {
                throw new Exception('User balance record not found');
            }

            // Re-check balance under lock (in case it changed after initial read)
            if ($withdrawal_amount > $user_balance->available_balance) {
                throw new Exception('Insufficient available balance after lock');
            }

            // 📝 Deduct from available, add to pending
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table_balance}
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

            // 📝 Create new withdrawal request (multiple are allowed)
            $result = $wpdb->insert(
                $table_requests,
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

            $wpdb->query('COMMIT');

            wp_send_json_success(sprintf(
                __('Заявка на вывод кэшбэка на сумму %s руб. успешно добавлена', 'woocommerce'),
                number_format($withdrawal_amount, 2, '.', ' ')
            ));
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $error_message = $e->getMessage();

            // Log unexpected errors
            if ($error_message !== 'Insufficient available balance after lock') {
                error_log("CashbackWithdrawal error for user {$user_id}: " . $error_message);
            }

            if ($error_message === 'Insufficient available balance after lock') {
                wp_send_json_error(__('Недостаточно средств для вывода. Пожалуйста, обновите страницу и попробуйте снова.', 'woocommerce'));
            } else {
                wp_send_json_error(__('Ошибка при обработке запроса на вывод. Пожалуйста, попробуйте еще раз.', 'woocommerce'));
            }
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

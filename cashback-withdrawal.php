<?php

declare(strict_types=1);

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
    public static function get_instance(): self
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
        // AJAX обработчик для сохранения настроек вывода
        add_action('wp_ajax_save_payout_settings', array($this, 'save_payout_settings'));
        add_action('wp_ajax_nopriv_save_payout_settings', array($this, 'save_payout_settings'));
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
    private function get_available_balance(int $user_id): float
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
     * Get all active payout methods
     *
     * @return array
     */
    private function get_payout_methods(): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';
        $methods = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name FROM {$table_name} WHERE is_active = %d ORDER BY sort_order ASC, name ASC",
                1
            ),
            ARRAY_A
        );

        return $methods ?: array();
    }

    /**
     * Get all active banks
     *
     * @return array
     */
    private function get_banks(): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_banks';
        $banks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name FROM {$table_name} WHERE is_active = %d ORDER BY sort_order ASC, name ASC",
                1
            ),
            ARRAY_A
        );

        return $banks ?: array();
    }

    /**
     * Get user's payout method ID
     *
     * @param int $user_id
     * @return int
     */
    private function get_user_payout_method_id(int $user_id): int
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_profile';
        $payout_method_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT payout_method_id FROM {$table_name} WHERE user_id = %d",
                $user_id
            )
        );

        return $payout_method_id ? intval($payout_method_id) : 0;
    }

    /**
     * Get user's bank ID
     *
     * @param int $user_id
     * @return int
     */
    private function get_user_bank_id(int $user_id): int
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_profile';
        $bank_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT bank_id FROM {$table_name} WHERE user_id = %d",
                $user_id
            )
        );

        return $bank_id ? intval($bank_id) : 0;
    }

    /**
     * Get minimum payout amount for user
     *
     * @param int $user_id
     * @return float
     */
    private function get_min_payout_amount(int $user_id): float
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
    private function get_payout_method(int $user_id): ?string
    {
        global $wpdb;

        $table_profile = $wpdb->prefix . 'cashback_user_profile';
        $table_methods = $wpdb->prefix . 'cashback_payout_methods';

        $payout_method = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.slug
             FROM {$table_profile} up
             LEFT JOIN {$table_methods} pm ON up.payout_method_id = pm.id
             WHERE up.user_id = %d",
            $user_id
        ));

        return $payout_method;
    }

    /**
     * Get bank info for user
     *
     * @param int $user_id
     * @return array|null Array containing bank id, code and name
     */
    private function get_user_bank_info(int $user_id): ?array
    {
        global $wpdb;

        $table_profile = $wpdb->prefix . 'cashback_user_profile';
        $table_banks = $wpdb->prefix . 'cashback_banks';

        $bank_info = $wpdb->get_row($wpdb->prepare(
            "SELECT b.id, b.bank_code, b.name
             FROM {$table_profile} up
             LEFT JOIN {$table_banks} b ON up.bank_id = b.id AND b.is_active = 1
             WHERE up.user_id = %d",
            $user_id
        ), ARRAY_A);

        return $bank_info ?: null;
    }

    /**
     * Get payout account for user
     *
     * @param int $user_id
     * @return string|null
     */
    private function get_payout_account(int $user_id): ?string
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
    private function get_payout_method_label(string $method): string
    {
        global $wpdb;

        // Получаем название способа вывода из базы данных
        $table_name = $wpdb->prefix . 'cashback_payout_methods';
        $method_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT name FROM {$table_name} WHERE slug = %s",
                $method
            )
        );

        if ($method_name) {
            return $method_name;
        }

        // Если не найдено в базе, используем старую логику
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
            echo '<button type="submit" class="woocommerce-Button button" id="withdrawal-submit" name="withdrawal_submit" value="' . esc_attr__('Вывести', 'woocommerce') . '" disabled>' . __('Вывести', 'woocommerce') . '</button>';
            echo '</p>';
            echo '<div id="withdrawal-messages"></div>';
            echo '</form>';
            echo '</div>';
            return;
        }

        $balance = $this->get_available_balance($user_id);
        $min_payout_amount = $this->get_min_payout_amount($user_id);

        // Получаем информацию о способе вывода и номере счета
        $payout_method_id = $this->get_user_payout_method_id($user_id);
        $payout_account = $this->get_payout_account($user_id);
        $bank_id = $this->get_user_bank_id($user_id);

        // Получаем доступные способы вывода и банки
        $payout_methods = $this->get_payout_methods();
        $banks = $this->get_banks();

        echo '<div class="cashback-withdrawal-container">';
        echo '<h2>' . __('Вывод кэшбэка', 'woocommerce') . '</h2>';
        echo '<div class="balance-display">';
        echo '<p>' . __('Доступный баланс:', 'woocommerce') . ' <span id="cashback-balance-amount" class="balance-amount ' . ($balance > 0 ? 'balance-green' : 'balance-gray') . '">' . wc_price($balance) . '</span></p>';
        echo '</div>';
        echo '<p>' . __('Минимальная сумма выплаты:', 'woocommerce') . ' <span class="min-payout-amount">' . wc_price($min_payout_amount) . '</span></p>';

        // Форма настройки способа вывода
        echo '<div class="payout-settings-form woocommerce-EditAccountForm edit-account">';
        echo '<h3>' . __('Настройки вывода', 'woocommerce') . '</h3>';
        echo '<div id="payout_settings_message"></div>';
        echo '<form id="payout-settings-form">';

        echo '<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">';
        echo '<label for="payout_method_id">' . __('Способ вывода', 'woocommerce') . ' <span class="required">*</span></label>';
        echo '<select name="payout_method_id" id="payout_method_id" class="woocommerce-Input woocommerce-Input--text input-text">';
        echo '<option value="">' . __('Выберите платежную систему', 'woocommerce') . '</option>';
        foreach ($payout_methods as $method) {
            $selected = ($payout_method_id === intval($method['id'])) ? 'selected' : '';
            echo '<option value="' . esc_attr($method['id']) . '" ' . $selected . '>' . esc_html($method['name']) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        echo '<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">';
        echo '<label for="payout_account">' . __('Номер счета или телефона', 'woocommerce') . ' <span class="required">*</span></label>';
        echo '<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="payout_account" id="payout_account" value="' . esc_attr($payout_account) . '" />';
        echo '</p>';

        echo '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">';
        echo '<label for="bank_id">' . __('Банк', 'woocommerce') . ' <span class="required">*</span></label>';
        echo '<select name="bank_id" id="bank_id" class="woocommerce-Input woocommerce-Input--text input-text">';
        echo '<option value="">' . __('Выберите банк', 'woocommerce') . '</option>';
        foreach ($banks as $bank) {
            $selected = ($bank_id === intval($bank['id'])) ? 'selected' : '';
            echo '<option value="' . esc_attr($bank['id']) . '" ' . $selected . '>' . esc_html($bank['name']) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        echo '<div class="clear"></div>';

        echo '<p class="woocommerce-form-row form-row">';
        echo '<button type="button" class="woocommerce-Button button" id="save_payout_settings_btn">' . __('Сохранить настройки', 'woocommerce') . '</button>';
        echo '</p>';

        echo '</form>';
        echo '</div>';

        // Добавляем форму вывода кэшбэка
        echo '<div class="cashback-withdrawal-form">';
        echo '<form id="withdrawal-form">';
        echo '<p class="form-row">';
        echo '<label for="withdrawal-amount">' . __('Сумма вывода', 'woocommerce') . ' <span class="required">*</span></label>';
        echo '<input type="number" class="input-text" name="withdrawal_amount" id="withdrawal-amount" placeholder="' . __('Введите сумму', 'woocommerce') . '" value="" step="0.01" />';
        echo '</p>';
        echo '<p class="form-row">';
        echo '<button type="submit" class="woocommerce-Button button" id="withdrawal-submit" name="withdrawal_submit" value="' . esc_attr__('Вывести', 'woocommerce') . '">' . __('Вывести', 'woocommerce') . '</button>';
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

        // Проверяем, что пользователь имеет корректный ID
        if (!$user_id || $user_id <= 0) {
            wp_send_json_error(__('Некорректный идентификатор пользователя.', 'woocommerce'));
            return;
        }

        // === 2. Защита от повторных запросов ===
        $transient_key = 'withdrawal_request_' . $user_id;
        if (get_transient($transient_key)) {
            wp_send_json_error(__('Предыдущий запрос еще обрабатывается. Пожалуйста, подождите.', 'woocommerce'));
            return;
        }

        // Устанавливаем блокировку на 30 секунд
        set_transient($transient_key, true, 30);

        $withdrawal_amount = floatval($_POST['withdrawal_amount'] ?? 0);

        // === 3. Check if payout method and account are filled ===
        $payout_method = $this->get_payout_method($user_id);
        $payout_account = $this->get_payout_account($user_id);

        if (empty($payout_method) || empty($payout_account)) {
            delete_transient($transient_key); // Снимаем блокировку
            wp_send_json_error(__('Для вывода средств пожалуйста, заполните способ вывода и номер счета в вашем профиле.', 'woocommerce'));
            return;
        }

        // === 4. Input validation ===
        $min_payout_amount = $this->get_min_payout_amount($user_id);
        $available_balance = $this->get_available_balance($user_id);
        $max_withdrawal_amount = 50000.00; // Максимальная сумма вывода

        if ($withdrawal_amount <= 0) {
            delete_transient($transient_key); // Снимаем блокировку
            wp_send_json_error(__('Сумма вывода должна быть положительной.', 'woocommerce'));
            return;
        }

        // Проверяем, что баланс пользователя больше или равен минимальной сумме для вывода
        if ($available_balance < $min_payout_amount) {
            delete_transient($transient_key); // Снимаем блокировку
            wp_send_json_error(sprintf(__('Вы не можете вывести средства, Ваш баланс %s меньше минимально допустимой суммы для вывода %s', 'woocommerce'), wc_price($available_balance), wc_price($min_payout_amount)));
            return;
        }

        if ($withdrawal_amount < $min_payout_amount) {
            delete_transient($transient_key); // Снимаем блокировку
            wp_send_json_error(sprintf(__('Вы ввели сумму меньше минимально допустимой, введите сумму больше или равно %s', 'woocommerce'), wc_price($min_payout_amount)));
            return;
        }

        if ($withdrawal_amount > $available_balance) {
            delete_transient($transient_key); // Снимаем блокировку
            wp_send_json_error(sprintf(__('Вы ввели сумму больше доступной, введите сумму меньше или равно %s', 'woocommerce'), wc_price($available_balance)));
            return;
        }

        if ($withdrawal_amount > $max_withdrawal_amount) {
            delete_transient($transient_key); // Снимаем блокировку
            wp_send_json_error(sprintf(__('Максимальная сумма вывода %s', 'woocommerce'), wc_price($max_withdrawal_amount)));
            return;
        }

        // === 5. Atomic balance deduction with row-level locking ===
        global $wpdb;
        $table_balance = $wpdb->prefix . 'cashback_user_balance';
        $table_requests = $wpdb->prefix . 'cashback_payout_requests';

        // Дополнительная блокировка на уровне MariaDB
        $lock_acquired = $wpdb->get_var($wpdb->prepare(
            "SELECT GET_LOCK('user_withdrawal_%d', 10)",
            $user_id
        ));

        if (!$lock_acquired) {
            delete_transient($transient_key); // Снимаем блокировку
            wp_send_json_error(__('Не удалось получить блокировку для операции. Пожалуйста, попробуйте позже.', 'woocommerce'));
            return;
        }

        $wpdb->query('START TRANSACTION');

        try {
            // 🔒 CRITICAL: Lock the user's balance row to prevent race conditions
            $user_balance = $wpdb->get_row($wpdb->prepare(
                "SELECT available_balance, pending_balance, version
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

            // 🔐 КРИТИЧНО: Генерируем криптографически стойкий идемпотентный ключ
            // Формат: SHA256(user_id + timestamp + nonce + random_bytes)
            $idempotency_key = hash(
                'sha256',
                $user_id .
                    '_' . microtime(true) .
                    '_' . wp_create_nonce('cashback_withdrawal_' . $user_id) .
                    '_' . bin2hex(random_bytes(16))
            );

            // Получаем информацию о способе вывода, аккаунте и банке из профиля пользователя
            $payout_method = $this->get_payout_method($user_id);
            $payout_account = $this->get_payout_account($user_id);
            $bank_info = $this->get_user_bank_info($user_id);

            // Получаем bank_id и bank_code для сохранения в заявку
            $bank_id = $bank_info['id'] ?? null;
            $bank_code = $bank_info['bank_code'] ?? '';

            // 📝 АТОМАРНАЯ ОПЕРАЦИЯ: Создаем заявку на выплату с идемпотентным ключом
            // UNIQUE KEY на idempotency_key гарантирует отсутствие дублей даже при повторных попытках
            $result = $wpdb->insert(
                $table_requests,
                array(
                    'user_id' => $user_id,
                    'total_amount' => $withdrawal_amount,
                    'payout_method' => $payout_method ?: '',
                    'payout_account' => $payout_account ?: '',
                    'provider' => $bank_code, // Сохраняем код банка как провайдера
                    'idempotency_key' => $idempotency_key,
                    'status' => 'waiting'
                ),
                array('%d', '%f', '%s', '%s', '%s', '%s', '%s')
            );

            if ($result === false) {
                // Проверяем, не произошло ли нарушение уникального ключа (дубль)
                if (
                    strpos($wpdb->last_error, 'uk_idempotency') !== false ||
                    strpos($wpdb->last_error, 'Duplicate entry') !== false
                ) {
                    throw new Exception('Duplicate payout request detected');
                }
                throw new Exception('Failed to insert payout request: ' . $wpdb->last_error);
            }

            $payout_id = $wpdb->insert_id;

            // 📝 Списываем с доступного баланса и добавляем в pending с оптимистичной блокировкой
            // Делаем это ПОСЛЕ создания заявки для корректного rollback
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table_balance}
                SET available_balance = available_balance - %f,
                    pending_balance = pending_balance + %f,
                    version = version + 1
                WHERE user_id = %d AND version = %d",
                $withdrawal_amount,
                $withdrawal_amount,
                $user_id,
                $user_balance->version
            ));

            if ($result === false || $result === 0) {
                throw new Exception('Failed to update user balance - version conflict');
            }

            $wpdb->query('COMMIT');

            // Логирование успешной операции с идемпотентным ключом
            wc_get_logger()->info(sprintf(
                'User %d withdrew %f. New balance: %f. Payout ID: %d. Idempotency: %s',
                $user_id,
                $withdrawal_amount,
                $user_balance->available_balance - $withdrawal_amount,
                $payout_id,
                substr($idempotency_key, 0, 16) . '...' // Логируем только первые 16 символов
            ));

            // Освобождаем блокировку MariaDB
            $wpdb->query($wpdb->prepare(
                "SELECT RELEASE_LOCK('user_withdrawal_%d')",
                $user_id
            ));

            delete_transient($transient_key); // Снимаем блокировку

            wp_send_json_success(sprintf(
                __('Заявка на вывод кэшбэка на сумму %s руб. успешно добавлена', 'woocommerce'),
                number_format($withdrawal_amount, 2, '.', ' ')
            ));
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $error_message = $e->getMessage();

            // Освобождаем блокировку MariaDB
            $wpdb->query($wpdb->prepare(
                "SELECT RELEASE_LOCK('user_withdrawal_%d')",
                $user_id
            ));

            delete_transient($transient_key); // Снимаем блокировку

            // Log unexpected errors
            if ($error_message !== 'Insufficient available balance after lock') {
                wc_get_logger()->error(sprintf(
                    "CashbackWithdrawal error for user %d: %s. Amount: %f",
                    $user_id,
                    $error_message,
                    $withdrawal_amount
                ));
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
                '1.0.2'
            );

            // Подключаем скрипты для обработки формы вывода
            wp_enqueue_script(
                'cashback-withdrawal-js',
                plugins_url('assets/js/frontend.js', __FILE__),
                array('jquery'),
                '1.0.2',
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
     * AJAX обработчик для сохранения настроек вывода
     */
    public function save_payout_settings()
    {
        // Проверяем nonce
        if (!wp_verify_nonce($_POST['security'] ?? '', 'cashback_withdrawal_nonce')) {
            wp_send_json_error(array('message' => __('Неверный nonce.', 'woocommerce')));
            return;
        }

        // Проверяем авторизацию пользователя
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Пользователь не авторизован.', 'woocommerce')));
            return;
        }

        $user_id = get_current_user_id();

        if (!$user_id) {
            wp_send_json_error(array('message' => __('Пользователь не найден.', 'woocommerce')));
            return;
        }

        $payout_method_id = intval($_POST['payout_method_id'] ?? 0);
        $payout_account = sanitize_text_field($_POST['payout_account'] ?? '');
        $bank_id = intval($_POST['bank_id'] ?? 0);

        // Валидация данных
        if (empty($payout_method_id)) {
            wp_send_json_error(array('message' => __('Пожалуйста, выберите способ вывода.', 'woocommerce')));
            return;
        }

        if (empty($payout_account)) {
            wp_send_json_error(array('message' => __('Пожалуйста, введите номер счета или телефона.', 'woocommerce')));
            return;
        }

        if (!$bank_id || $bank_id <= 0) {
            wp_send_json_error(array('message' => __('Пожалуйста, выберите банк.', 'woocommerce')));
            return;
        }

        // Проверяем, что способ вывода существует и активен
        $valid_method = $this->validate_payout_method($payout_method_id);
        if (!$valid_method) {
            wp_send_json_error(array('message' => __('Недопустимый способ вывода.', 'woocommerce')));
            return;
        }

        // Проверяем, что банк существует и активен
        $valid_bank = $this->validate_bank($bank_id);
        if (!$valid_bank) {
            wp_send_json_error(array('message' => __('Недопустимый банк.', 'woocommerce')));
            return;
        }

        // Обновляем данные пользователя
        $result = $this->update_user_payout_details($user_id, $payout_method_id, $payout_account, $bank_id);

        if ($result) {
            wp_send_json_success(array(
                'message' => __('Настройки успешно сохранены.', 'woocommerce')
            ));
        } else {
            wp_send_json_error(array('message' => __('Ошибка при сохранении данных.', 'woocommerce')));
        }
    }

    /**
     * Validate payout method
     *
     * @param int $method_id
     * @return bool
     */
    private function validate_payout_method(int $method_id): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';
        $method = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE id = %d AND is_active = %d",
                $method_id,
                1
            ),
            ARRAY_A
        );

        return !empty($method);
    }

    /**
     * Validate bank
     *
     * @param int $bank_id
     * @return bool
     */
    private function validate_bank(int $bank_id): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_banks';
        $bank = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE id = %d AND is_active = %d",
                $bank_id,
                1
            ),
            ARRAY_A
        );

        return !empty($bank);
    }

    /**
     * Update user payout details
     *
     * @param int $user_id
     * @param int $payout_method_id
     * @param string $payout_account
     * @param int $bank_id
     * @return bool
     */
    private function update_user_payout_details(int $user_id, int $payout_method_id, string $payout_account, int $bank_id): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_profile';

        // Проверяем, существует ли запись профиля для пользователя
        $existing_record = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$table_name} WHERE user_id = %d",
                $user_id
            )
        );

        if ($existing_record) {
            // Обновляем существующую запись
            $result = $wpdb->update(
                $table_name,
                array(
                    'payout_method_id' => $payout_method_id,
                    'payout_account' => $payout_account,
                    'bank_id' => $bank_id,
                    'payout_details_updated_at' => current_time('mysql')
                ),
                array('user_id' => $user_id),
                array('%d', '%s', '%d', '%s'),
                array('%d')
            );
        } else {
            // Создаем новую запись
            $result = $wpdb->insert(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'payout_method_id' => $payout_method_id,
                    'payout_account' => $payout_account,
                    'bank_id' => $bank_id,
                    'payout_details_updated_at' => current_time('mysql')
                ),
                array('%d', '%d', '%s', '%d', '%s')
            );
        }

        if ($result !== false) {
            // Обновляем записи в истории выплат со статусом "waiting" для этого пользователя
            $this->update_payout_requests_for_user($user_id, $payout_method_id, $payout_account);
        }

        return $result !== false;
    }

    /**
     * Update payout requests for user
     *
     * @param int $user_id
     * @param int $payout_method_id
     * @param string $payout_account
     * @return bool
     */
    private function update_payout_requests_for_user(int $user_id, int $payout_method_id, string $payout_account): bool
    {
        global $wpdb;

        // Получаем slug способа выплаты по его ID
        $payout_method_slug = $this->get_payout_method_slug($payout_method_id);

        if (!$payout_method_slug) {
            return false;
        }

        $payout_requests_table = $wpdb->prefix . 'cashback_payout_requests';

        // Обновляем только записи со статусом "waiting"
        $result = $wpdb->update(
            $payout_requests_table,
            array(
                'payout_method' => $payout_method_slug,
                'payout_account' => $payout_account
            ),
            array(
                'user_id' => $user_id,
                'status' => 'waiting'
            ),
            array('%s', '%s'),
            array('%d', '%s')
        );

        return $result !== false;
    }

    /**
     * Get payout method slug by ID
     *
     * @param int $method_id
     * @return string|null
     */
    private function get_payout_method_slug(int $method_id): ?string
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';
        $method_slug = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT slug FROM {$table_name} WHERE id = %d",
                $method_id
            )
        );

        return $method_slug ?: null;
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

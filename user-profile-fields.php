<?php

/**
 * Класс для добавления полей способа вывода и аккаунта в профиль пользователя
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CashbackUserProfileFields
{
    /**
     * Конструктор класса
     */
    public function __construct()
    {
        // Добавляем поля в форму редактирования профиля
        add_action('woocommerce_edit_account_form', array($this, 'add_payout_fields'));

        // Обрабатываем сохранение полей
        add_action('woocommerce_save_account_details', array($this, 'save_payout_fields'));

        // Добавляем обработку AJAX-запроса для сохранения полей
        add_action('wp_ajax_save_payout_details', array($this, 'handle_save_payout_details'));
        add_action('wp_ajax_nopriv_save_payout_details', array($this, 'handle_save_payout_details'));

        // Подключаем JavaScript на странице редактирования профиля
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Добавляем поля способа вывода и аккаунта в форму редактирования профиля
     */
    public function add_payout_fields()
    {
        $user_id = get_current_user_id();

        // Получаем текущие значения из профиля пользователя
        $payout_method_id = $this->get_user_payout_method_id($user_id);
        $payout_account = $this->get_user_payout_account($user_id);

        // Получаем доступные способы вывода
        $payout_methods = $this->get_payout_methods();
?>
        <fieldset>
            <legend>Настройки вывода кэшбэка</legend>

            <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
                <label for="payout_method_id">Способ вывода *</label>
                <select name="payout_method_id" id="payout_method_id" class="woocommerce-Input woocommerce-Select">
                    <option value="">Выберите платежную систему</option>
                    <?php foreach ($payout_methods as $method): ?>
                        <option value="<?php echo esc_attr($method['id']); ?>" <?php selected($payout_method_id, $method['id']); ?>>
                            <?php echo esc_html($method['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
                <label for="payout_account">Номер счета или телефона *</label>
                <input type="text" class="woocommerce-Input Input--withAddon" name="payout_account" id="payout_account" value="<?php echo esc_attr($payout_account); ?>" />
            </p>

            <p class="woocommerce-form-row form-row">
                <button type="button" class="button" id="save_payout_details_btn">Сохранить</button>
                <span id="payout_details_message"></span>
            </p>

            <!-- Отображение сохраненных данных -->
            <?php if ($payout_method_id && $payout_account):
                $method_name = $this->get_payout_method_name($payout_method_id);
            ?>
                <div class="payout-current-data">
                    <p class="woocommerce-info">
                        <strong>Текущие данные:</strong><br>
                        Способ вывода: <?php echo esc_html($method_name); ?><br>
                        Номер счета/телефона: <?php echo esc_html($payout_account); ?>
                    </p>
                </div>
            <?php endif; ?>
        </fieldset>
<?php
    }

    /**
     * Обработка сохранения полей при редактировании профиля
     */
    public function save_payout_fields($user_id)
    {
        if (isset($_POST['payout_method_id']) && isset($_POST['payout_account'])) {
            $payout_method_id = intval($_POST['payout_method_id']);
            $payout_account = sanitize_text_field($_POST['payout_account']);

            $this->update_user_payout_details($user_id, $payout_method_id, $payout_account);
        }
    }

    /**
     * AJAX-обработчик для сохранения деталей вывода
     */
    public function handle_save_payout_details()
    {
        // Проверяем nonce
        if (!wp_verify_nonce($_POST['security'], 'save_payout_details_nonce')) {
            wp_send_json_error(array('message' => 'Неверный nonce.'));
            return;
        }

        // Проверяем авторизацию пользователя
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Пользователь не авторизован.'));
            return;
        }

        $user_id = get_current_user_id();

        if (!$user_id) {
            wp_send_json_error(array('message' => 'Пользователь не найден.'));
            return;
        }

        $payout_method_id = intval($_POST['payout_method_id']);
        $payout_account = sanitize_text_field($_POST['payout_account']);

        // Валидация данных
        if (empty($payout_method_id)) {
            wp_send_json_error(array('message' => 'Пожалуйста, выберите способ вывода.'));
            return;
        }

        if (empty($payout_account)) {
            wp_send_json_error(array('message' => 'Пожалуйста, введите номер счета или телефона.'));
            return;
        }

        // Проверяем, что способ вывода существует и активен
        $valid_method = $this->get_payout_method_by_id($payout_method_id);
        if (!$valid_method) {
            wp_send_json_error(array('message' => 'Недопустимый способ вывода.'));
            return;
        }

        // Обновляем данные пользователя
        $result = $this->update_user_payout_details($user_id, $payout_method_id, $payout_account);

        if ($result) {
            wp_send_json_success(array('message' => 'Данные успешно сохранены.'));
        } else {
            wp_send_json_error(array('message' => 'Ошибка при сохранении данных.'));
        }
    }

    /**
     * Получить способы вывода
     */
    private function get_payout_methods()
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
     * Получить способ вывода по ID
     */
    private function get_payout_method_by_id($method_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';
        $method = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name, is_active FROM {$table_name} WHERE id = %d AND is_active = %d",
                $method_id,
                1
            ),
            ARRAY_A
        );

        return $method ?: null;
    }

    /**
     * Получить название способа вывода по ID
     */
    private function get_payout_method_name($method_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';
        $method_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT name FROM {$table_name} WHERE id = %d",
                $method_id
            )
        );

        return $method_name ?: 'Не указан';
    }

    /**
     * Получить ID способа вывода пользователя
     */
    private function get_user_payout_method_id($user_id)
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
     * Получить аккаунт для вывода пользователя
     */
    private function get_user_payout_account($user_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_profile';
        $payout_account = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT payout_account FROM {$table_name} WHERE user_id = %d",
                $user_id
            )
        );

        return $payout_account ? $payout_account : '';
    }

    /**
     * Обновить детали вывода пользователя
     */
    private function update_user_payout_details($user_id, $payout_method_id, $payout_account)
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
                    'payout_details_updated_at' => current_time('mysql')
                ),
                array('user_id' => $user_id),
                array('%d', '%s', '%s'),
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
                    'payout_details_updated_at' => current_time('mysql')
                ),
                array('%d', '%d', '%s', '%s')
            );
        }

        return $result !== false;
    }

    /**
     * Подключить скрипты
     */
    public function enqueue_scripts()
    {
        if (is_account_page() && isset($_GET['edit-account'])) {
            // Подключаем наш JavaScript-файл
            wp_enqueue_script(
                'user-profile-fields-js',
                plugin_dir_url(__FILE__) . 'assets/js/user-profile-fields.js',
                array('jquery'),
                '1.0.0',
                true
            );

            // Локализуем скрипт для передачи AJAX URL и nonce
            wp_localize_script('user-profile-fields-js', 'ajax_object', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('save_payout_details_nonce')
            ));
        }
    }
}

// Инициализация класса
new CashbackUserProfileFields();

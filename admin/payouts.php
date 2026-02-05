<?php

declare(strict_types=1);

namespace WP_Cashback_Plugin\Admin;

/**
 * Класс для управления выплатами кэшбэка в админ-панели.
 *
 * @package WP_Cashback_Plugin\Admin
 */

// Проверяем, что файл вызывается из WordPress
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс управления выплатами в админ-панели
 */
class Cashback_Payouts_Admin
{
    /**
     * Имя таблицы запросов на выплату
     *
     * @var string
     */
    private string $table_name;

    /**
     * WooCommerce logger instance
     *
     * @var \WC_Logger|null
     */
    private ?\WC_Logger $logger = null;

    /**
     * Конструктор класса
     */
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cashback_payout_requests';
        $this->logger = function_exists('wc_get_logger') ? wc_get_logger() : null;

        // Регистрируем хук для добавления пункта меню
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Обработка AJAX запросов
        add_action('wp_ajax_update_payout_request', [$this, 'handle_update_payout_request']);
        add_action('wp_ajax_get_payout_request', [$this, 'handle_get_payout_request']);

        // Подключение скриптов
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Подключение скриптов и стилей для админ-панели
     *
     * @param string $hook Текущая страница админки
     * @return void
     */
    public function enqueue_admin_scripts(string $hook): void
    {
        // Подключаем только на странице выплат
        // Проверяем различные варианты идентификатора страницы
        $allowed_hooks = [
            'cashback-overview_page_cashback-payouts',
            'toplevel_page_cashback-payouts',
            'admin_page_cashback-payouts'
        ];

        // Также проверяем через $_GET параметр
        $is_payouts_page = in_array($hook, $allowed_hooks, true) ||
            (isset($_GET['page']) && $_GET['page'] === 'cashback-payouts');

        if (!$is_payouts_page) {
            return;
        }

        wp_enqueue_script(
            'cashback-admin-payouts',
            plugins_url('../assets/js/admin-payouts.js', __FILE__),
            ['jquery'],
            '1.0.1',
            true
        );

        // Передаем данные в JavaScript
        wp_localize_script('cashback-admin-payouts', 'cashbackPayoutsData', [
            'updateNonce' => wp_create_nonce('update_payout_request_nonce'),
            'getNonce' => wp_create_nonce('get_payout_request_nonce'),
        ]);
    }

    /**
     * Добавляем подпункт меню в админке
     *
     * @return void
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'cashback-overview',
            __('Выплаты', 'cashback-plugin'),
            __('Выплаты', 'cashback-plugin'),
            'manage_options',
            'cashback-payouts',
            [$this, 'render_payouts_page']
        );
    }

    /**
     * Отображаем страницу управления выплатами
     *
     * @return void
     */
    public function render_payouts_page(): void
    {
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_die(__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // Получаем параметры для пагинации и фильтрации
        $current_page = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 10;
        $offset = ($current_page - 1) * $per_page;

        // Получаем фильтры с валидацией
        $filter_status = sanitize_text_field($_GET['status'] ?? '');
        $filter_date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $filter_date_to = sanitize_text_field($_GET['date_to'] ?? '');

        // Валидация дат
        if (!empty($filter_date_from) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
            $filter_date_from = '';
        }
        if (!empty($filter_date_to) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
            $filter_date_to = '';
        }

        // Валидация статуса
        $allowed_statuses = ['waiting', 'processing', 'paid', 'failed', 'declined', 'needs_retry'];
        if (!empty($filter_status) && !in_array($filter_status, $allowed_statuses, true)) {
            $filter_status = '';
        }

        // Подготовка условий для фильтрации
        $where_conditions = [];
        $where_params = [];

        if (!empty($filter_status)) {
            $where_conditions[] = 'status = %s';
            $where_params[] = $filter_status;
        }

        if (!empty($filter_date_from)) {
            $where_conditions[] = 'DATE(created_at) >= %s';
            $where_params[] = $filter_date_from;
        }

        if (!empty($filter_date_to)) {
            $where_conditions[] = 'DATE(created_at) <= %s';
            $where_params[] = $filter_date_to;
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        // Подсчет общего количества выплат
        if (!empty($where_params)) {
            $total_payouts = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) 
            FROM {$this->table_name}
            {$where_clause}",
                    $where_params
                )
            );
        } else {
            $total_payouts = $wpdb->get_var(
                "SELECT COUNT(*) 
        FROM {$this->table_name}"
            );
        }

        // Получаем выплаты
        if (!empty($where_params)) {
            $payouts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, user_id, total_amount, payout_method, payout_account, 
                    provider, provider_payout_id, attempts, fail_reason, status, 
                    created_at, updated_at
            FROM {$this->table_name}
            {$where_clause}
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d",
                    array_merge($where_params, [$per_page, $offset])
                ),
                'ARRAY_A'
            );
        } else {
            $payouts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, user_id, total_amount, payout_method, payout_account, 
                    provider, provider_payout_id, attempts, fail_reason, status, 
                    created_at, updated_at
            FROM {$this->table_name}
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d",
                    [$per_page, $offset]
                ),
                'ARRAY_A'
            );
        }

        // Получаем уникальные статусы для фильтра
        $statuses = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT status 
                FROM {$this->table_name} 
                WHERE status IS NOT NULL 
                ORDER BY status ASC 
                LIMIT %d",
                100
            )
        );

        // Выводим сообщения об ошибках или успехе
        $message = '';
        $message_type = '';
        if (isset($_GET['message'])) {
            $message_code = sanitize_text_field($_GET['message']);
            if ($message_code === 'updated') {
                $message = __('Запрос на выплату успешно обновлен.', 'cashback-plugin');
                $message_type = 'success';
            } elseif ($message_code === 'error') {
                $message = __('Ошибка при обновлении запроса на выплату.', 'cashback-plugin');
                $message_type = 'error';
            }
        }

?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__('Выплаты', 'cashback-plugin'); ?></h1>
            <hr class="wp-header-end">

            <?php if (!empty($message)): ?>
                <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <!-- Фильтры -->
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <label for="filter-status" class="screen-reader-text"><?php echo esc_html__('Фильтр по статусу', 'cashback-plugin'); ?></label>
                    <select name="filter-status" id="filter-status">
                        <option value=""><?php echo esc_html__('Все статусы', 'cashback-plugin'); ?></option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo esc_attr($status); ?>" <?php selected($filter_status, $status); ?>>
                                <?php echo esc_html($this->get_admin_status_label($status)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="filter-date-from" class="screen-reader-text"><?php echo esc_html__('Дата от', 'cashback-plugin'); ?></label>
                    <input type="date" id="filter-date-from" name="filter-date-from" value="<?php echo esc_attr($filter_date_from); ?>" />

                    <label for="filter-date-to" class="screen-reader-text"><?php echo esc_html__('Дата до', 'cashback-plugin'); ?></label>
                    <input type="date" id="filter-date-to" name="filter-date-to" value="<?php echo esc_attr($filter_date_to); ?>" />

                    <button type="submit" id="filter-submit" class="button action"><?php echo esc_html__('Фильтровать', 'cashback-plugin'); ?></button>
                    <button type="submit" id="filter-reset" class="button action"><?php echo esc_html__('Сбросить', 'cashback-plugin'); ?></button>
                </div>
                <br class="clear">
            </div>

            <!-- Таблица выплат -->
            <div class="wp-list-table-wrap">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html__('ID пользователя', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Сумма', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Платежная система', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Номер счета/телефона', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Банк', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('ID Транзакции', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Количество попыток', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Описание ошибки', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Статус платежа', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Дата заявки на выплату', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Дата выплаты или ошибки выплаты', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Действия', 'cashback-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tfoot>
                        <tr>
                            <th scope="col"><?php echo esc_html__('ID пользователя', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Сумма', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Платежная система', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Номер счета/телефона', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Банк', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('ID Транзакции', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Количество попыток', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Описание ошибки', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Статус платежа', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Дата заявки на выплату', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Дата выплаты или ошибки выплаты', 'cashback-plugin'); ?></th>
                            <th scope="col"><?php echo esc_html__('Действия', 'cashback-plugin'); ?></th>
                        </tr>
                    </tfoot>
                    <tbody id="payouts-tbody">
                        <?php if (!empty($payouts)): ?>
                            <?php foreach ($payouts as $payout): ?>
                                <tr data-payout-id="<?php echo esc_attr($payout['id']); ?>">
                                    <td><?php echo esc_html($payout['user_id']); ?></td>
                                    <td><?php echo esc_html(number_format(floatval($payout['total_amount']), 2, '.', ' ')); ?></td>
                                    <td><?php echo esc_html($this->get_payout_method_name_by_slug($payout['payout_method'])); ?></td>
                                    <td><?php echo esc_html($payout['payout_account']); ?></td>
                                    <td class="edit-field" data-field="provider">
                                        <?php echo esc_html($this->get_bank_name_by_code($payout['provider'] ?? '')); ?>
                                    </td>
                                    <td class="edit-field" data-field="provider_payout_id">
                                        <?php echo esc_html($payout['provider_payout_id'] ?? ''); ?>
                                    </td>
                                    <td class="edit-field" data-field="attempts">
                                        <?php echo esc_html($payout['attempts']); ?>
                                    </td>
                                    <td class="edit-field" data-field="fail_reason">
                                        <?php echo esc_html($payout['fail_reason'] ?? ''); ?>
                                    </td>
                                    <td class="edit-field" data-field="status" title="<?php echo esc_attr($this->get_admin_status_description($payout['status'])); ?>">
                                        <?php echo esc_html($this->get_admin_status_label($payout['status'])); ?>
                                    </td>
                                    <td><?php echo esc_html(date('Y-m-d H:i', strtotime($payout['created_at']))); ?></td>
                                    <td><?php echo esc_html(!empty($payout['updated_at']) ? date('Y-m-d H:i', strtotime($payout['updated_at'])) : ''); ?></td>
                                    <td>
                                        <button class="button button-secondary edit-btn"><?php echo esc_html__('Редактировать', 'cashback-plugin'); ?></button>
                                        <button class="button button-primary save-btn" style="display:none;"><?php echo esc_html__('Сохранить', 'cashback-plugin'); ?></button>
                                        <button class="button button-default cancel-btn" style="display:none;"><?php echo esc_html__('Отмена', 'cashback-plugin'); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12"><?php echo esc_html__('Нет выплат для отображения.', 'cashback-plugin'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Пагинация -->
            <?php
            $pagination_args = array(
                'total_items' => $total_payouts,
                'per_page'    => $per_page,
                'current_page' => $current_page,
                'total_pages' => ceil($total_payouts / $per_page),
                'format'      => '?paged=%#%',
                'add_args'    => array_filter([
                    'status' => $filter_status,
                    'date_from' => $filter_date_from,
                    'date_to' => $filter_date_to
                ])
            );

            $this->render_pagination($pagination_args);
            ?>
        </div>
<?php
    }

    /**
     * Обработка AJAX запроса на обновление запроса выплаты
     *
     * @return void
     */
    public function handle_update_payout_request(): void
    {
        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'update_payout_request_nonce')) {
            wp_send_json_error(['message' => __('Неверный nonce.', 'cashback-plugin')]);
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостаточно прав для выполнения этого действия.', 'cashback-plugin')]);
            return;
        }

        global $wpdb;

        $payout_id = intval($_POST['payout_id'] ?? 0);

        // Подготовим массив для обновления, включая только те поля, которые были переданы
        $update_data = array();
        $update_formats = array();

        // Проверяем и добавляем только измененные поля
        if (isset($_POST['provider'])) {
            $provider = sanitize_text_field($_POST['provider']);
            $update_data['provider'] = $provider;
            $update_formats[] = '%s';
        }

        if (isset($_POST['provider_payout_id'])) {
            $provider_payout_id = sanitize_text_field($_POST['provider_payout_id']);
            $update_data['provider_payout_id'] = $provider_payout_id;
            $update_formats[] = '%s';
        }

        if (isset($_POST['attempts'])) {
            $attempts = intval($_POST['attempts']);

            if ($attempts < 0) {
                wp_send_json_error(['message' => __('Количество попыток должно быть неотрицательным числом.', 'cashback-plugin')]);
                return;
            }

            $update_data['attempts'] = $attempts;
            $update_formats[] = '%d';
        }

        if (isset($_POST['fail_reason'])) {
            $fail_reason = sanitize_text_field($_POST['fail_reason']);
            $update_data['fail_reason'] = $fail_reason;
            $update_formats[] = '%s';
        }

        if (isset($_POST['status'])) {
            $status = sanitize_text_field($_POST['status']);

            // Проверяем, что статус допустим
            $allowed_statuses = ['waiting', 'processing', 'paid', 'failed', 'declined', 'needs_retry'];
            if (!in_array($status, $allowed_statuses, true)) {
                wp_send_json_error(['message' => __('Недопустимый статус выплаты.', 'cashback-plugin')]);
                return;
            }

            // Если статус изменяется на 'paid', обновляем баланс пользователя
            $old_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$this->table_name} WHERE id = %d",
                $payout_id
            ));

            $update_data['status'] = $status;
            $update_formats[] = '%s';

            // Если статус меняется с любого другого на 'paid', обновляем баланс
            if ($old_status !== 'paid' && $status === 'paid') {
                $this->update_user_balance_on_payout($payout_id);
            }

            // Если статус меняется с любого другого на 'declined', обновляем баланс
            if ($old_status !== 'declined' && $status === 'declined') {
                $this->update_user_balance_on_declined($payout_id);
            }
        }

        // Добавляем дату обновления
        $update_data['updated_at'] = current_time('mysql');
        $update_formats[] = '%s';

        // Обновляем только те поля, которые были изменены
        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            ['id' => $payout_id],
            $update_formats,
            ['%d']  // Формат условия
        );

        if ($result === false) {
            wp_send_json_error(['message' => __('Ошибка при обновлении запроса выплаты в базе данных.', 'cashback-plugin')]);
            return;
        }

        // Получаем обновленные данные из базы
        $updated_payout_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT provider, provider_payout_id, attempts, fail_reason, status 
                 FROM {$this->table_name} 
                 WHERE id = %d",
                $payout_id
            ),
            ARRAY_A
        );

        if (!$updated_payout_data) {
            wp_send_json_error(['message' => __('Не удалось получить обновленные данные запроса выплаты.', 'cashback-plugin')]);
            return;
        }

        // Получаем уникальные статусы для обновления фильтра
        $statuses = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT status 
                FROM {$this->table_name} 
                WHERE status IS NOT NULL 
                ORDER BY status ASC
                LIMIT %d",
                100
            )
        );

        // Возвращаем обновленные данные и статусы
        wp_send_json_success([
            'payout_data' => $updated_payout_data,
            'statuses' => $statuses
        ]);
    }

    /**
     * Обновление баланса пользователя при изменении статуса выплаты на "paid"
     * 
     * @param int $payout_id ID запроса на выплату
     * @return bool Результат операции
     */
    private function update_user_balance_on_payout(int $payout_id): bool
    {
        global $wpdb;

        // Получаем информацию о запросе на выплату
        $payout_request = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT user_id, total_amount FROM {$this->table_name} WHERE id = %d",
                $payout_id
            ),
            ARRAY_A
        );

        if (!$payout_request) {
            $this->log_error("Не найден запрос на выплату с ID {$payout_id}");
            return false;
        }

        $user_id = $payout_request['user_id'];
        $amount = floatval($payout_request['total_amount']);

        // Начинаем транзакцию для обеспечения целостности данных
        $wpdb->query('START TRANSACTION');

        try {
            // Получаем текущий баланс пользователя
            $balance_table = $wpdb->prefix . 'cashback_user_balance';
            $current_balance = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT pending_balance, paid_balance FROM {$balance_table} WHERE user_id = %d",
                    $user_id
                ),
                ARRAY_A
            );

            if (!$current_balance) {
                $this->log_error("Не найден баланс для пользователя {$user_id}");
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Проверяем, достаточно ли средств в pending_balance
            $pending_balance = floatval($current_balance['pending_balance']);
            if ($pending_balance < $amount) {
                $this->log_error("Недостаточно средств в pending_balance для пользователя {$user_id}. Требуется: {$amount}, доступно: {$pending_balance}");
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Обновляем баланс: вычитаем из pending_balance и добавляем к paid_balance
            $new_pending_balance = $pending_balance - $amount;
            $new_paid_balance = floatval($current_balance['paid_balance']) + $amount;

            $result = $wpdb->update(
                $balance_table,
                [
                    'pending_balance' => $new_pending_balance,
                    'paid_balance' => $new_paid_balance
                ],
                ['user_id' => $user_id],
                ['%f', '%f'],
                ['%d']
            );

            if ($result === false) {
                $this->log_error("Ошибка обновления баланса пользователя {$user_id}");
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Фиксируем транзакцию
            $wpdb->query('COMMIT');

            // Логируем изменение баланса
            $this->log_info("Баланс пользователя {$user_id} обновлен. Выплачено: {$amount}, pending_balance: {$new_pending_balance}, paid_balance: {$new_paid_balance}");

            return true;
        } catch (\Exception $e) {
            // Откатываем транзакцию в случае ошибки
            $wpdb->query('ROLLBACK');
            $this->log_error("Ошибка при обновлении баланса пользователя {$user_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Обновление баланса пользователя при изменении статуса выплаты на "declined"
     * 
     * @param int $payout_id ID запроса на выплату
     * @return bool Результат операции
     */
    private function update_user_balance_on_declined(int $payout_id): bool
    {
        global $wpdb;

        // Получаем информацию о запросе на выплату
        $payout_request = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT user_id, total_amount FROM {$this->table_name} WHERE id = %d",
                $payout_id
            ),
            ARRAY_A
        );

        if (!$payout_request) {
            $this->log_error("Не найден запрос на выплату с ID {$payout_id}");
            return false;
        }

        $user_id = $payout_request['user_id'];
        $amount = floatval($payout_request['total_amount']);

        // Начинаем транзакцию для обеспечения целостности данных
        $wpdb->query('START TRANSACTION');

        try {
            // Получаем текущий баланс пользователя
            $balance_table = $wpdb->prefix . 'cashback_user_balance';
            $current_balance = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT pending_balance, frozen_balance FROM {$balance_table} WHERE user_id = %d",
                    $user_id
                ),
                ARRAY_A
            );

            if (!$current_balance) {
                $this->log_error("Не найден баланс для пользователя {$user_id}");
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Проверяем, достаточно ли средств в pending_balance
            $pending_balance = floatval($current_balance['pending_balance']);
            if ($pending_balance < $amount) {
                $this->log_error("Недостаточно средств в pending_balance для пользователя {$user_id}. Требуется: {$amount}, доступно: {$pending_balance}");
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Обновляем баланс: вычитаем из pending_balance и добавляем к frozen_balance
            $new_pending_balance = $pending_balance - $amount;
            $new_frozen_balance = floatval($current_balance['frozen_balance']) + $amount;

            $result = $wpdb->update(
                $balance_table,
                [
                    'pending_balance' => $new_pending_balance,
                    'frozen_balance' => $new_frozen_balance
                ],
                ['user_id' => $user_id],
                ['%f', '%f'],
                ['%d']
            );

            if ($result === false) {
                $this->log_error("Ошибка обновления баланса пользователя {$user_id}");
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Фиксируем транзакцию
            $wpdb->query('COMMIT');

            // Логируем изменение баланса
            $this->log_info("Баланс пользователя {$user_id} обновлен при отклонении выплаты. Сумма: {$amount}, pending_balance: {$new_pending_balance}, frozen_balance: {$new_frozen_balance}");

            return true;
        } catch (\Exception $e) {
            // Откатываем транзакцию в случае ошибки
            $wpdb->query('ROLLBACK');
            $this->log_error("Ошибка при обновлении баланса пользователя {$user_id} при отклонении выплаты: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Обработка AJAX запроса на получение данных запроса выплаты
     *
     * @return void
     */
    public function handle_get_payout_request(): void
    {
        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'get_payout_request_nonce')) {
            wp_send_json_error(['message' => __('Неверный nonce.', 'cashback-plugin')]);
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостаточно прав для выполнения этого действия.', 'cashback-plugin')]);
            return;
        }

        global $wpdb;

        $payout_id = intval($_POST['payout_id'] ?? 0);

        // Получаем данные из базы
        $payout_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT provider, provider_payout_id, attempts, fail_reason, status 
                 FROM {$this->table_name} 
                 WHERE id = %d",
                $payout_id
            ),
            ARRAY_A
        );

        if (!$payout_data) {
            wp_send_json_error(['message' => __('Не удалось получить данные запроса выплаты.', 'cashback-plugin')]);
            return;
        }

        // Возвращаем данные
        wp_send_json_success($payout_data);
    }

    /**
     * Получение метки статуса для администратора
     * 
     * @param string $status Статус выплаты
     * @return string Текстовое описание статуса
     */
    private function get_admin_status_label(string $status): string
    {
        $labels = [
            'waiting' => __('Не выплачен', 'cashback-plugin'),
            'processing' => __('В обработке', 'cashback-plugin'),
            'paid' => __('Выплачен', 'cashback-plugin'),
            'failed' => __('Выплата не прошла', 'cashback-plugin'),
            'declined' => __('Выплата заморожена', 'cashback-plugin'),
            'needs_retry' => __('Проверить выплату', 'cashback-plugin'),
        ];

        return $labels[$status] ?? $status;
    }

    /**
     * Получение описания статуса для администратора
     * 
     * @param string $status Статус выплаты
     * @return string Описание статуса
     */
    private function get_admin_status_description(string $status): string
    {
        $descriptions = [
            'waiting' => __('Платеж еще не обрабатывался', 'cashback-plugin'),
            'processing' => __('Платеж осуществляется', 'cashback-plugin'),
            'paid' => __('Платеж выплачен', 'cashback-plugin'),
            'failed' => __('Выплату невозможно осуществить по каким либо причинам', 'cashback-plugin'),
            'declined' => __('Выплата заморожена из-за мошенничества', 'cashback-plugin'),
            'needs_retry' => __('Выплата не прошла, попробовать повторить выплату', 'cashback-plugin'),
        ];

        return $descriptions[$status] ?? $status;
    }

    /**
     * Вывод пагинации
     *
     * @param array $args Параметры пагинации
     * @return void
     */
    private function render_pagination(array $args): void
    {
        $total_items = $args['total_items'];
        $per_page = $args['per_page'];
        $current_page = $args['current_page'];
        $total_pages = $args['total_pages'];
        $format = $args['format'];
        $add_args = $args['add_args'];

        if ($total_pages <= 1) {
            return;
        }

        // Проверяем, доступна ли функция paginate_links
        if (function_exists('paginate_links')) {
            $pagination_links = paginate_links([
                'total' => $total_pages,
                'current' => $current_page,
                'format' => $format,
                'add_args' => $add_args,
                'type' => 'plain',
                'prev_text' => '&lsaquo; ' . __('Предыдущая', 'cashback-plugin'),
                'next_text' => __('Следующая', 'cashback-plugin') . ' &rsaquo;',
            ]);

            if ($pagination_links) {
                echo '<div class="tablenav bottom">';
                echo '<div class="tablenav-pages">';
                echo '<span class="displaying-num">' . sprintf(_n('%s запись', '%s записей', $total_items, 'cashback-plugin'), number_format_i18n($total_items)) . '</span>';
                echo '<span class="pagination-links">';
                echo wp_kses_post($pagination_links);
                echo '</span>';
                echo '<br class="clear"></div>';
            }
        } else {
            // Альтернативная реализация пагинации
            echo '<div class="tablenav bottom">';
            echo '<div class="tablenav-pages">';
            echo '<span class="displaying-num">' . sprintf(_n('%s запись', '%s записей', $total_items, 'cashback-plugin'), number_format_i18n($total_items)) . '</span>';
            echo '<span class="pagination-links">';

            $base_url = admin_url('admin.php?page=cashback-payouts');
            if (!empty($add_args)) {
                foreach ($add_args as $key => $value) {
                    $base_url = add_query_arg($key, $value, $base_url);
                }
            }

            // Предыдущая страница
            if ($current_page > 1) {
                $prev_page = $current_page - 1;
                $prev_url = add_query_arg('paged', $prev_page, $base_url);
                echo '<a class="prev-page button" href="' . esc_url($prev_url) . '">&lsaquo; ' . esc_html__('Предыдущая', 'cashback-plugin') . '</a>';
            }

            // Текущая страница
            echo '<span class="paging-input">';
            echo '<span class="tablenav-paging-text">' . esc_html($current_page) . ' ' . esc_html__('из', 'cashback-plugin') . ' ' . esc_html($total_pages) . '</span>';
            echo '</span>';

            // Следующая страница
            if ($current_page < $total_pages) {
                $next_page = $current_page + 1;
                $next_url = add_query_arg('paged', $next_page, $base_url);
                echo '<a class="next-page button" href="' . esc_url($next_url) . '">' . esc_html__('Следующая', 'cashback-plugin') . ' &rsaquo;</a>';
            }

            echo '</span>';
            echo '</div>';
            echo '<br class="clear"></div>';
        }
    }

    /**
     * Логирование ошибок
     *
     * @param string $message Сообщение об ошибке
     * @return void
     */
    private function log_error(string $message): void
    {
        if ($this->logger) {
            $this->logger->error($message, ['source' => 'cashback-payouts']);
        }
    }

    /**
     * Логирование информационных сообщений
     *
     * @param string $message Информационное сообщение
     * @return void
     */
    private function log_info(string $message): void
    {
        if ($this->logger) {
            $this->logger->info($message, ['source' => 'cashback-payouts']);
        }
    }

    /**
     * Получить название платежной системы по slug
     *
     * @param string $slug Slug платежной системы
     * @return string Название платежной системы
     */
    private function get_payout_method_name_by_slug(string $slug): string
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';
        $method_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT name FROM {$table_name} WHERE slug = %s",
                $slug
            )
        );

        return $method_name ?: $slug;
    }

    /**
     * Получить название банка по коду
     *
     * @param string $bank_code Код банка
     * @return string Название банка
     */
    private function get_bank_name_by_code(string $bank_code): string
    {
        global $wpdb;

        if (empty($bank_code)) {
            return '';
        }

        $table_name = $wpdb->prefix . 'cashback_banks';
        $bank_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT name FROM {$table_name} WHERE bank_code = %s",
                $bank_code
            )
        );

        return $bank_name ?: $bank_code;
    }
}

// Инициализируем класс
$payouts_admin = new Cashback_Payouts_Admin();

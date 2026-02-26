<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Transactions_Admin
{
    use AdminPaginationTrait;

    private string $registered_table;
    private string $unregistered_table;
    private int $per_page = 20;

    public function __construct()
    {
        global $wpdb;
        $this->registered_table   = $wpdb->prefix . 'cashback_transactions';
        $this->unregistered_table = $wpdb->prefix . 'cashback_unregistered_transactions';

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_update_transaction', [$this, 'handle_update_transaction']);
        add_action('wp_ajax_get_transaction', [$this, 'handle_get_transaction']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function add_admin_menu(): void
    {
        add_submenu_page(
            'cashback-overview',
            'Транзакции',
            'Транзакции',
            'manage_options',
            'cashback-transactions',
            [$this, 'render_page']
        );
    }

    public function enqueue_admin_scripts(string $hook): void
    {
        $allowed_hooks = [
            'cashback-overview_page_cashback-transactions',
            'toplevel_page_cashback-transactions',
            'admin_page_cashback-transactions'
        ];

        $is_target_page = in_array($hook, $allowed_hooks, true) ||
            (isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-transactions');

        if (!$is_target_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-transactions-css',
            plugins_url('../assets/css/admin.css', __FILE__),
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'cashback-admin-transactions',
            plugins_url('../assets/js/admin-transactions.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('cashback-admin-transactions', 'cashbackTransactionsData', [
            'updateNonce' => wp_create_nonce('update_transaction_nonce'),
            'getNonce'    => wp_create_nonce('get_transaction_nonce'),
        ]);
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // Tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'registered';
        if (!in_array($current_tab, ['registered', 'unregistered'], true)) {
            $current_tab = 'registered';
        }

        $table_name = ($current_tab === 'registered') ? $this->registered_table : $this->unregistered_table;

        // Filters
        $filter_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $search_query  = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';

        $allowed_statuses = ['waiting', 'completed', 'declined', 'hold', 'balance'];
        if (!empty($filter_status) && !in_array($filter_status, $allowed_statuses, true)) {
            $filter_status = '';
        }

        // Pagination
        $current_page = max(1, absint($_GET['paged'] ?? 1));
        $offset = ($current_page - 1) * $this->per_page;

        // Build WHERE
        $where_conditions = [];
        $where_params = [];

        if (!empty($filter_status)) {
            $where_conditions[] = 'order_status = %s';
            $where_params[] = $filter_status;
        }

        if (!empty($search_query)) {
            $like_pattern = '%' . $wpdb->esc_like($search_query) . '%';
            $where_conditions[] = '(click_id LIKE %s OR order_number LIKE %s)';
            $where_params[] = $like_pattern;
            $where_params[] = $like_pattern;
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
        }

        // Count
        if (!empty($where_params)) {
            $total_items = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name}{$where_clause}",
                    ...$where_params
                )
            );
        } else {
            $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        }

        $total_pages = (int) ceil($total_items / $this->per_page);

        // Fetch rows
        $query_params = array_merge($where_params, [$this->per_page, $offset]);
        if (!empty($query_params)) {
            $transactions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, user_id, order_number, order_status, sum_order, comission, cashback, click_id, created_at
                     FROM {$table_name}{$where_clause}
                     ORDER BY created_at DESC
                     LIMIT %d OFFSET %d",
                    ...$query_params
                ),
                ARRAY_A
            );
        } else {
            $transactions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, user_id, order_number, order_status, sum_order, comission, cashback, click_id, created_at
                     FROM {$table_name}
                     ORDER BY created_at DESC
                     LIMIT %d OFFSET %d",
                    $this->per_page,
                    $offset
                ),
                ARRAY_A
            );
        }

        $base_url = admin_url('admin.php?page=cashback-transactions');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Транзакции</h1>
            <hr class="wp-header-end">

            <!-- Tabs -->
            <nav class="nav-tab-wrapper" style="margin-bottom: 15px;">
                <a href="<?php echo esc_url(add_query_arg('tab', 'registered', $base_url)); ?>"
                   class="nav-tab <?php echo $current_tab === 'registered' ? 'nav-tab-active' : ''; ?>">
                    Зарегистрированные
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'unregistered', $base_url)); ?>"
                   class="nav-tab <?php echo $current_tab === 'unregistered' ? 'nav-tab-active' : ''; ?>">
                    Незарегистрированные
                </a>
            </nav>

            <!-- Filters -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select id="filter-status">
                        <option value="">Все статусы</option>
                        <?php foreach ($allowed_statuses as $status): ?>
                            <option value="<?php echo esc_attr($status); ?>" <?php selected($filter_status, $status); ?>>
                                <?php echo esc_html($this->get_status_label($status)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="search" id="filter-search"
                           value="<?php echo esc_attr($search_query); ?>"
                           placeholder="Поиск по click_id или order_number"
                           style="min-width: 280px;" />

                    <button type="button" id="filter-submit" class="button action">Фильтровать</button>
                    <?php if (!empty($filter_status) || !empty($search_query)): ?>
                        <button type="button" id="filter-reset" class="button action">Сбросить</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($search_query)): ?>
                <p>Результаты поиска: <strong>&laquo;<?php echo esc_html($search_query); ?>&raquo;</strong>
                    &mdash; найдено: <?php echo esc_html((string) $total_items); ?></p>
            <?php endif; ?>

            <!-- Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" style="width: 50px;">ID</th>
                        <th scope="col" style="width: 80px;">User ID</th>
                        <th scope="col">Номер заказа</th>
                        <th scope="col" style="width: 140px;">Статус</th>
                        <th scope="col" style="width: 120px;">Сумма заказа</th>
                        <th scope="col" style="width: 120px;">Комиссия</th>
                        <th scope="col" style="width: 100px;">Кэшбэк</th>
                        <th scope="col">Click ID</th>
                        <th scope="col" style="width: 140px;">Дата</th>
                        <th scope="col" style="width: 200px;">Действия</th>
                    </tr>
                </thead>
                <tbody id="transactions-tbody">
                    <?php if (!empty($transactions)): ?>
                        <?php foreach ($transactions as $tx): ?>
                            <?php $is_editable = ($tx['order_status'] !== 'balance'); ?>
                            <tr data-transaction-id="<?php echo esc_attr($tx['id']); ?>"
                                data-tab="<?php echo esc_attr($current_tab); ?>">
                                <td><?php echo esc_html($tx['id']); ?></td>
                                <td><?php echo esc_html($tx['user_id']); ?></td>
                                <td><?php echo esc_html($tx['order_number'] ?? ''); ?></td>
                                <td class="<?php echo $is_editable ? 'edit-field' : ''; ?>" data-field="order_status">
                                    <?php echo esc_html($this->get_status_label($tx['order_status'])); ?>
                                </td>
                                <td class="<?php echo $is_editable ? 'edit-field' : ''; ?>" data-field="sum_order">
                                    <?php echo esc_html($tx['sum_order'] ?? '0.00'); ?>
                                </td>
                                <td class="<?php echo $is_editable ? 'edit-field' : ''; ?>" data-field="comission">
                                    <?php echo esc_html($tx['comission'] ?? '0.00'); ?>
                                </td>
                                <td class="cashback-display"><?php echo esc_html($tx['cashback'] ?? '0.00'); ?></td>
                                <td><?php echo esc_html($tx['click_id'] ?? ''); ?></td>
                                <td><?php echo esc_html($tx['created_at'] ?? ''); ?></td>
                                <td>
                                    <?php if ($is_editable): ?>
                                        <button class="button button-secondary edit-btn">Редактировать</button>
                                        <button class="button button-primary save-btn" style="display:none;">Сохранить</button>
                                        <button class="button button-default cancel-btn" style="display:none;">Отмена</button>
                                    <?php else: ?>
                                        <span class="description">Финальный статус</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10">
                                <?php if (!empty($search_query)): ?>
                                    По запросу &laquo;<?php echo esc_html($search_query); ?>&raquo; транзакции не найдены.
                                <?php else: ?>
                                    Нет транзакций.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            $this->render_pagination([
                'total_items'  => $total_items,
                'per_page'     => $this->per_page,
                'current_page' => $current_page,
                'total_pages'  => $total_pages,
                'page_slug'    => 'cashback-transactions',
                'add_args'     => array_filter([
                    'tab'    => $current_tab,
                    'status' => $filter_status,
                    'search' => $search_query,
                ]),
            ]);
            ?>
        </div>
        <?php
    }

    public function handle_update_transaction(): void
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'])),
            'update_transaction_nonce'
        )) {
            wp_send_json_error(['message' => 'Неверный токен безопасности.']);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав.']);
            return;
        }

        global $wpdb;

        $transaction_id = intval($_POST['transaction_id'] ?? 0);
        if ($transaction_id <= 0) {
            wp_send_json_error(['message' => 'Некорректный ID транзакции.']);
            return;
        }

        $tab = sanitize_text_field(wp_unslash($_POST['tab'] ?? 'registered'));
        $table_name = ($tab === 'unregistered') ? $this->unregistered_table : $this->registered_table;

        // Pre-check current status
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT order_status FROM {$table_name} WHERE id = %d",
            $transaction_id
        ), ARRAY_A);

        if (!$current) {
            wp_send_json_error(['message' => 'Транзакция не найдена.']);
            return;
        }

        if ($current['order_status'] === 'balance') {
            wp_send_json_error(['message' => 'Транзакция с финальным статусом не может быть изменена.']);
            return;
        }

        $update_data = [];
        $update_formats = [];

        if (isset($_POST['order_status'])) {
            $new_status = sanitize_text_field(wp_unslash($_POST['order_status']));
            $allowed_statuses = ['waiting', 'completed', 'declined', 'hold', 'balance'];
            if (!in_array($new_status, $allowed_statuses, true)) {
                wp_send_json_error(['message' => 'Недопустимый статус.']);
                return;
            }
            $update_data['order_status'] = $new_status;
            $update_formats[] = '%s';
        }

        if (isset($_POST['sum_order'])) {
            $sum_order = floatval($_POST['sum_order']);
            if ($sum_order < 0) {
                wp_send_json_error(['message' => 'Сумма заказа не может быть отрицательной.']);
                return;
            }
            $update_data['sum_order'] = $sum_order;
            $update_formats[] = '%f';
        }

        if (isset($_POST['comission'])) {
            $comission = floatval($_POST['comission']);
            if ($comission < 0) {
                wp_send_json_error(['message' => 'Комиссия не может быть отрицательной.']);
                return;
            }
            $update_data['comission'] = $comission;
            $update_formats[] = '%f';
        }

        if (empty($update_data)) {
            wp_send_json_error(['message' => 'Нет данных для обновления.']);
            return;
        }

        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $transaction_id],
            $update_formats,
            ['%d']
        );

        if ($result === false) {
            $db_error = $wpdb->last_error;
            wp_send_json_error([
                'message' => 'Ошибка при обновлении транзакции.' .
                    (!empty($db_error) ? ' ' . $db_error : '')
            ]);
            return;
        }

        // Return fresh data (cashback may have been recalculated by trigger)
        $updated = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, order_number, order_status, sum_order, comission, cashback, click_id, created_at
             FROM {$table_name} WHERE id = %d",
            $transaction_id
        ), ARRAY_A);

        wp_send_json_success(['transaction_data' => $updated]);
    }

    public function handle_get_transaction(): void
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'])),
            'get_transaction_nonce'
        )) {
            wp_send_json_error(['message' => 'Неверный токен безопасности.']);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав.']);
            return;
        }

        global $wpdb;

        $transaction_id = intval($_POST['transaction_id'] ?? 0);
        if ($transaction_id <= 0) {
            wp_send_json_error(['message' => 'Некорректный ID транзакции.']);
            return;
        }

        $tab = sanitize_text_field(wp_unslash($_POST['tab'] ?? 'registered'));
        $table_name = ($tab === 'unregistered') ? $this->unregistered_table : $this->registered_table;

        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, order_number, order_status, sum_order, comission, cashback, click_id, created_at
             FROM {$table_name} WHERE id = %d",
            $transaction_id
        ), ARRAY_A);

        if (!$data) {
            wp_send_json_error(['message' => 'Транзакция не найдена.']);
            return;
        }

        wp_send_json_success($data);
    }

    private function get_status_label(string $status): string
    {
        $labels = [
            'waiting'   => 'В ожидании',
            'completed' => 'Подтверждена',
            'declined'  => 'Отклонена',
            'hold'      => 'Удержание',
            'balance'   => 'Зачислена на баланс',
        ];
        return $labels[$status] ?? $status;
    }
}

new Cashback_Transactions_Admin();

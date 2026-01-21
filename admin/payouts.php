<?php

declare(strict_types=1);

/**
 * Файл для управления выплатами в админке WordPress
 */

// Проверяем, что файл вызывается из WordPress
if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Payouts_Admin
{

    private string $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cashback_payout_requests';

        // Регистрируем хук для добавления пункта меню
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Обработка AJAX запросов
        add_action('wp_ajax_update_payout_request', [$this, 'handle_update_payout_request']);
        add_action('wp_ajax_get_payout_request', [$this, 'handle_get_payout_request']);
    }

    /**
     * Добавляем подпункт меню в админке
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'cashback-overview',
            'Выплаты',
            'Выплаты',
            'manage_options',
            'cashback-payouts',
            [$this, 'render_payouts_page']
        );
    }

    /**
     * Отображаем страницу управления выплатами
     */
    public function render_payouts_page(): void
    {
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_die(__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // Получаем параметры для пагинации и фильтрации
        $current_page = max(1, absint($_GET['paged'] ?? 0));
        $per_page = 10;
        $offset = ($current_page - 1) * $per_page;

        // Получаем фильтры
        $filter_status = sanitize_text_field($_GET['status'] ?? '');
        $filter_date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $filter_date_to = sanitize_text_field($_GET['date_to'] ?? '');

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
        $total_payouts = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) 
                FROM {$this->table_name}
                {$where_clause}",
                $where_params
            )
        );

        // Получаем выплаты
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

        // Получаем уникальные статусы для фильтра
        $statuses = $wpdb->get_col(
            "SELECT DISTINCT status 
            FROM {$this->table_name} 
            WHERE status IS NOT NULL 
            ORDER BY status ASC"
        );

        // Выводим сообщения об ошибках или успехе
        $message = '';
        if (isset($_GET['message'])) {
            if ($_GET['message'] === 'updated') {
                $message = '<div class="notice notice-success is-dismissible"><p>Запрос на выплату успешно обновлен.</p></div>';
            } elseif ($_GET['message'] === 'error') {
                $message = '<div class="notice notice-error is-dismissible"><p>Ошибка при обновлении запроса на выплату.</p></div>';
            }
        }

?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Выплаты</h1>
            <hr class="wp-header-end">

            <?php echo $message; ?>

            <!-- Фильтры -->
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <label for="filter-status" class="screen-reader-text">Фильтр по статусу</label>
                    <select name="filter-status" id="filter-status">
                        <option value="">Все статусы</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo esc_attr($status); ?>" <?php selected($filter_status, $status); ?>>
                                <?php echo esc_html($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="filter-date-from" class="screen-reader-text">Дата от</label>
                    <input type="date" id="filter-date-from" name="filter-date-from" value="<?php echo esc_attr($filter_date_from); ?>" />

                    <label for="filter-date-to" class="screen-reader-text">Дата до</label>
                    <input type="date" id="filter-date-to" name="filter-date-to" value="<?php echo esc_attr($filter_date_to); ?>" />

                    <button type="submit" id="filter-submit" class="button action">Фильтровать</button>
                    <button type="submit" id="filter-reset" class="button action">Сбросить</button>
                </div>
                <br class="clear">
            </div>

            <!-- Таблица выплат -->
            <div class="wp-list-table-wrap">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col">ID пользователя</th>
                            <th scope="col">Сумма</th>
                            <th scope="col">Платежная система</th>
                            <th scope="col">Номер счета/телефона</th>
                            <th scope="col">Банк</th>
                            <th scope="col">ID Транзакции</th>
                            <th scope="col">Количество попыток</th>
                            <th scope="col">Описание ошибки</th>
                            <th scope="col">Статус платежа</th>
                            <th scope="col">Дата заявки на выплату</th>
                            <th scope="col">Дата выплаты или ошибки выплаты</th>
                            <th scope="col">Действия</th>
                        </tr>
                    </thead>
                    <tfoot>
                        <tr>
                            <th scope="col">ID пользователя</th>
                            <th scope="col">Сумма</th>
                            <th scope="col">Платежная система</th>
                            <th scope="col">Номер счета/телефона</th>
                            <th scope="col">Банк</th>
                            <th scope="col">ID Транзакции</th>
                            <th scope="col">Количество попыток</th>
                            <th scope="col">Описание ошибки</th>
                            <th scope="col">Статус платежа</th>
                            <th scope="col">Дата заявки на выплату</th>
                            <th scope="col">Дата выплаты или ошибки выплаты</th>
                            <th scope="col">Действия</th>
                        </tr>
                    </tfoot>
                    <tbody id="payouts-tbody">
                        <?php if (!empty($payouts)): ?>
                            <?php foreach ($payouts as $payout): ?>
                                <tr data-payout-id="<?php echo esc_attr($payout['id']); ?>">
                                    <td><?php echo esc_html($payout['user_id']); ?></td>
                                    <td><?php echo esc_html(number_format(floatval($payout['total_amount']), 2, '.', ' ')); ?></td>
                                    <td><?php echo esc_html($payout['payout_method']); ?></td>
                                    <td><?php echo esc_html($payout['payout_account']); ?></td>
                                    <td class="edit-field" data-field="provider">
                                        <?php echo esc_html($payout['provider'] ?? ''); ?>
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
                                    <td class="edit-field" data-field="status">
                                        <?php echo esc_html($payout['status']); ?>
                                    </td>
                                    <td><?php echo esc_html(date('Y-m-d H:i', strtotime($payout['created_at']))); ?></td>
                                    <td><?php echo esc_html(!empty($payout['updated_at']) ? date('Y-m-d H:i', strtotime($payout['updated_at'])) : ''); ?></td>
                                    <td>
                                        <button class="button button-secondary edit-btn">Редактировать</button>
                                        <button class="button button-primary save-btn" style="display:none;">Сохранить</button>
                                        <button class="button button-default cancel-btn" style="display:none;">Отмена</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12">Нет выплат для отображения.</td>
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

            <!-- Скрипты для работы с формой -->
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Обработка фильтра
                    $('#filter-submit').on('click', function() {
                        var status = $('#filter-status').val();
                        var dateFrom = $('#filter-date-from').val();
                        var dateTo = $('#filter-date-to').val();

                        var url = new URL(window.location);
                        if (status) {
                            url.searchParams.set('status', status);
                        } else {
                            url.searchParams.delete('status');
                        }

                        if (dateFrom) {
                            url.searchParams.set('date_from', dateFrom);
                        } else {
                            url.searchParams.delete('date_from');
                        }

                        if (dateTo) {
                            url.searchParams.set('date_to', dateTo);
                        } else {
                            url.searchParams.delete('date_to');
                        }

                        url.searchParams.delete('paged'); // Сброс пагинации
                        window.location.href = url.toString();
                    });

                    // Сброс фильтров
                    $('#filter-reset').on('click', function() {
                        var url = new URL(window.location);
                        url.searchParams.delete('status');
                        url.searchParams.delete('date_from');
                        url.searchParams.delete('date_to');
                        url.searchParams.delete('paged');
                        window.location.href = url.toString();
                    });

                    // Обработка клика по кнопке "Редактировать"
                    $('.edit-btn').on('click', function() {
                        var row = $(this).closest('tr');
                        var cells = row.find('.edit-field');

                        cells.each(function() {
                            var cell = $(this);
                            var field = cell.data('field');
                            var currentValue = cell.text();

                            // Устанавливаем минимальную ширину для ячейки, чтобы она не сжималась
                            cell.css('min-width', cell.width() + 'px');

                            if (field === 'status') {
                                // Для поля status создаем select
                                var selectHtml = '<select class="edit-input" data-field="' + field + '" style="width:100%; box-sizing:border-box;">';
                                selectHtml += '<option value="waiting"' + (currentValue === 'waiting' ? ' selected' : '') + '>waiting</option>';
                                selectHtml += '<option value="processing"' + (currentValue === 'processing' ? ' selected' : '') + '>processing</option>';
                                selectHtml += '<option value="paid"' + (currentValue === 'paid' ? ' selected' : '') + '>paid</option>';
                                selectHtml += '<option value="failed"' + (currentValue === 'failed' ? ' selected' : '') + '>failed</option>';
                                selectHtml += '<option value="declined"' + (currentValue === 'declined' ? ' selected' : '') + '>declined</option>';
                                selectHtml += '</select>';
                                cell.html(selectHtml);
                            } else if (field === 'attempts') {
                                // Для числового поля attempts создаем input
                                cell.html('<input type="number" min="0" class="edit-input regular-text" data-field="' + field + '" value="' + currentValue + '" style="width:100%; box-sizing:border-box;" />');
                            } else {
                                // Для остальных полей создаем input
                                cell.html('<input type="text" class="edit-input regular-text" data-field="' + field + '" value="' + currentValue + '" style="width:100%; box-sizing:border-box;" />');
                            }
                        });

                        row.find('.edit-btn').hide();
                        row.find('.save-btn, .cancel-btn').show();
                    });

                    // Обработка клика по кнопке "Отмена"
                    $('.cancel-btn').on('click', function() {
                        var row = $(this).closest('tr');
                        resetRowToViewMode(row);
                    });

                    // Обработка клика по кнопке "Сохранить"
                    $('.save-btn').on('click', function() {
                        var row = $(this).closest('tr');
                        var payoutId = row.data('payout-id');
                        var originalValues = {};
                        var changedData = {};

                        // Сохраняем оригинальные значения из ячеек перед редактированием
                        row.find('.edit-field').each(function() {
                            var cell = $(this);
                            var field = cell.data('field');
                            originalValues[field] = cell.text();
                        });

                        // Собираем только измененные данные
                        row.find('.edit-input').each(function() {
                            var input = $(this);
                            var field = input.data('field');
                            var newValue = input.val();
                            var originalValue = originalValues[field];

                            // Проверяем, изменилось ли значение
                            if (originalValue != newValue) {
                                changedData[field] = newValue;
                            }
                        });

                        // Добавляем только необходимые данные для обновления
                        var data = {
                            'action': 'update_payout_request',
                            'payout_id': payoutId,
                            'nonce': '<?php echo wp_create_nonce('update_payout_request_nonce'); ?>'
                        };

                        // Добавляем только измененные поля
                        Object.assign(data, changedData);

                        // Проверяем, есть ли вообще изменения
                        var hasChanges = Object.keys(changedData).length > 0;

                        if (!hasChanges) {
                            alert('Нет изменений для сохранения.');
                            // Переключаем строку обратно в режим просмотра
                            row.find('.save-btn, .cancel-btn').hide();
                            row.find('.edit-btn').show();
                            return;
                        }

                        // Валидация только измененных данных
                        if (changedData.hasOwnProperty('attempts')) {
                            var attempts = parseInt(changedData['attempts']);
                            if (isNaN(attempts) || attempts < 0) {
                                alert('Количество попыток должно быть неотрицательным числом');
                                return;
                            }
                        }

                        if (changedData.hasOwnProperty('status')) {
                            var status = changedData['status'];
                            var allowedStatuses = ['waiting', 'processing', 'paid', 'failed', 'declined'];
                            if (allowedStatuses.indexOf(status) === -1) {
                                alert('Недопустимый статус выплаты');
                                return;
                            }
                        }

                        $.post(ajaxurl, data, function(response) {
                            if (response.success) {
                                // Обновляем все значения в ячейках, используя полученные данные из базы
                                row.find('.edit-field[data-field="provider"]').text(response.data.provider || '');
                                row.find('.edit-field[data-field="provider_payout_id"]').text(response.data.provider_payout_id || '');
                                row.find('.edit-field[data-field="attempts"]').text(response.data.attempts);
                                row.find('.edit-field[data-field="fail_reason"]').text(response.data.fail_reason || '');
                                row.find('.edit-field[data-field="status"]').text(response.data.status);

                                // Переключаем строку в режим просмотра
                                row.find('.edit-input').each(function() {
                                    var cell = $(this).closest('.edit-field');
                                    var field = $(this).data('field');
                                    cell.text(response.data[field] || '');

                                    // Восстанавливаем исходные стили ячейки
                                    cell.css('min-width', '');
                                });

                                row.find('.save-btn, .cancel-btn').hide();
                                row.find('.edit-btn').show();

                                // Показываем сообщение об успешном обновлении
                                $('.wp-header-end').after('<div class="notice notice-success is-dismissible"><p>Запрос на выплату успешно обновлен.</p></div>');
                                setTimeout(function() {
                                    $('.notice-success').fadeOut().remove();
                                }, 3000);
                            } else {
                                alert('Ошибка при обновлении запроса на выплату: ' + response.data.message);
                            }
                        }).fail(function() {
                            alert('Ошибка соединения при обновлении запроса на выплату');
                        });
                    });

                    // Загрузка актуальных данных из базы
                    function loadPayoutData(payoutId, callback) {
                        var data = {
                            'action': 'get_payout_request',
                            'payout_id': payoutId,
                            'nonce': '<?php echo wp_create_nonce('get_payout_request_nonce'); ?>'
                        };

                        $.post(ajaxurl, data, function(response) {
                            if (response.success) {
                                callback(null, response.data);
                            } else {
                                callback(response.data.message || 'Ошибка при загрузке данных выплаты', null);
                            }
                        }).fail(function() {
                            callback('Ошибка соединения при загрузке данных выплаты', null);
                        });
                    }

                    // Сброс строки к режиму просмотра
                    function resetRowToViewMode(row) {
                        var payoutId = row.data('payout-id');

                        // Загружаем актуальные данные из базы
                        loadPayoutData(payoutId, function(error, payoutData) {
                            if (error) {
                                console.error('Ошибка загрузки данных выплаты:', error);
                                alert('Ошибка загрузки данных выплаты: ' + error);
                                return;
                            }

                            // Обновляем все значения в ячейках, используя полученные данные из базы
                            row.find('.edit-field[data-field="provider"]').text(payoutData.provider || '');
                            row.find('.edit-field[data-field="provider_payout_id"]').text(payoutData.provider_payout_id || '');
                            row.find('.edit-field[data-field="attempts"]').text(payoutData.attempts);
                            row.find('.edit-field[data-field="fail_reason"]').text(payoutData.fail_reason || '');
                            row.find('.edit-field[data-field="status"]').text(payoutData.status);

                            // Восстанавливаем исходные стили ячеек
                            row.find('.edit-field').each(function() {
                                var cell = $(this);
                                cell.css('min-width', '');
                            });

                            row.find('.save-btn, .cancel-btn').hide();
                            row.find('.edit-btn').show();
                        });
                    }
                });
            </script>
        </div>
<?php
    }

    /**
     * Обработка AJAX запроса на обновление запроса выплаты
     */
    public function handle_update_payout_request(): void
    {
        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'update_payout_request_nonce')) {
            wp_send_json_error(['message' => 'Неверный nonce.']);
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав для выполнения этого действия.']);
            return;
        }

        global $wpdb;

        $payout_id = intval($_POST['payout_id']);

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
                wp_send_json_error(['message' => 'Количество попыток должно быть неотрицательным числом.']);
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
            $allowed_statuses = ['waiting', 'processing', 'paid', 'failed', 'declined'];
            if (!in_array($status, $allowed_statuses)) {
                wp_send_json_error(['message' => 'Недопустимый статус выплаты.']);
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
            wp_send_json_error(['message' => 'Ошибка при обновлении запроса выплаты в базе данных.']);
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
            wp_send_json_error(['message' => 'Не удалось получить обновленные данные запроса выплаты.']);
            return;
        }

        // Возвращаем обновленные данные
        wp_send_json_success($updated_payout_data);
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
            error_log("Cashback_Payouts_Admin: Не найден запрос на выплату с ID {$payout_id}");
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
                error_log("Cashback_Payouts_Admin: Не найден баланс для пользователя {$user_id}");
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Проверяем, достаточно ли средств в pending_balance
            $pending_balance = floatval($current_balance['pending_balance']);
            if ($pending_balance < $amount) {
                error_log("Cashback_Payouts_Admin: Недостаточно средств в pending_balance для пользователя {$user_id}. Требуется: {$amount}, доступно: {$pending_balance}");
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
                error_log("Cashback_Payouts_Admin: Ошибка обновления баланса пользователя {$user_id}");
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Фиксируем транзакцию
            $wpdb->query('COMMIT');

            // Логируем изменение баланса
            error_log("Cashback_Payouts_Admin: Баланс пользователя {$user_id} обновлен. Выплачено: {$amount}, pending_balance: {$new_pending_balance}, paid_balance: {$new_paid_balance}");

            return true;
        } catch (Exception $e) {
            // Откатываем транзакцию в случае ошибки
            $wpdb->query('ROLLBACK');
            error_log("Cashback_Payouts_Admin: Ошибка при обновлении баланса пользователя {$user_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Обработка AJAX запроса на получение данных запроса выплаты
     */
    public function handle_get_payout_request(): void
    {
        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'get_payout_request_nonce')) {
            wp_send_json_error(['message' => 'Неверный nonce.']);
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав для выполнения этого действия.']);
            return;
        }

        global $wpdb;

        $payout_id = intval($_POST['payout_id']);

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
            wp_send_json_error(['message' => 'Не удалось получить данные запроса выплаты.']);
            return;
        }

        // Возвращаем данные
        wp_send_json_success($payout_data);
    }

    /**
     * Вывод пагинации
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
                'type' => 'plain',  // Используем plain для более гибкого контроля над HTML
                'prev_text' => '&lsaquo; ' . __('Предыдущая'),
                'next_text' => __('Следующая') . ' &rsaquo;',
            ]);

            if ($pagination_links) {
                echo '<div class="tablenav bottom">';
                echo '<div class="tablenav-pages">';
                echo '<span class="displaying-num">' . sprintf(_n('%s запись', '%s записей', $total_items, 'cashback-plugin'), number_format_i18n($total_items)) . '</span>';
                echo '<span class="pagination-links">';
                echo $pagination_links;
                echo '</span>';
                echo '<br class="clear"></div>';
            }
        } else {
            // Альтернативная реализация пагинации, если paginate_links недоступна
            echo '<div class="tablenav bottom">';
            echo '<div class="tablenav-pages">';
            echo '<span class="displaying-num">' . sprintf(_n('%s запись', '%s записей', $total_items, 'cashback-plugin'), number_format_i18n($total_items)) . '</span>';
            echo '<span class="pagination-links">';

            // Создаем простую пагинацию вручную
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
                echo '<a class="prev-page button" href="' . esc_url($prev_url) . '">&lsaquo; ' . __('Предыдущая') . '</a>';
            }

            // Текущая страница
            echo '<span class="paging-input">';
            echo '<span class="tablenav-paging-text">' . esc_html($current_page) . ' из ' . esc_html($total_pages) . '</span>';
            echo '</span>';

            // Следующая страница
            if ($current_page < $total_pages) {
                $next_page = $current_page + 1;
                $next_url = add_query_arg('paged', $next_page, $base_url);
                echo '<a class="next-page button" href="' . esc_url($next_url) . '">' . __('Следующая') . ' &rsaquo;</a>';
            }

            echo '</span>';
            echo '</div>';
            echo '<br class="clear"></div>';
        }
    }
}

// Инициализируем класс
$payouts_admin = new Cashback_Payouts_Admin();

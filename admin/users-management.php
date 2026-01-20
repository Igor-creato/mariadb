<?php

declare(strict_types=1);

/**
 * Файл для управления пользователями кэшбэка в админке WordPress
 */

// Проверяем, что файл вызывается из WordPress
if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Users_Management_Admin
{

    private string $table_name;
    private string $profile_table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'users';
        $this->profile_table_name = $wpdb->prefix . 'cashback_user_profile';

        // Регистрируем хук для добавления пункта меню
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Обработка AJAX запросов
        add_action('wp_ajax_update_user_profile', [$this, 'handle_update_user_profile']);
        add_action('wp_ajax_get_user_profile', [$this, 'handle_get_user_profile']);
    }

    /**
     * Добавляем подпункт меню в админке
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'cashback-overview',
            'Пользователи',
            'Пользователи',
            'manage_options',
            'cashback-users',
            [$this, 'render_users_page']
        );
    }

    /**
     * Отображаем страницу управления пользователями
     */
    public function render_users_page(): void
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

        // Получаем фильтр статуса
        $filter_status = sanitize_text_field($_GET['status'] ?? '');

        // Подготовка условий для фильтрации
        $where_clause = '';
        $where_params = [];

        if (!empty($filter_status)) {
            $where_clause = 'WHERE cup.status = %s';
            $where_params[] = $filter_status;
        }

        // Подсчет общего количества пользователей
        $total_users = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) 
                FROM {$this->table_name} u
                LEFT JOIN {$this->profile_table_name} cup ON u.ID = cup.user_id
                {$where_clause}",
                $where_params
            )
        );

        // Получаем пользователей с профилями
        $users = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.ID, u.display_name, 
                        cup.cashback_rate, cup.min_payout_amount, cup.status, cup.ban_reason, cup.banned_at
                FROM {$this->table_name} u
                LEFT JOIN {$this->profile_table_name} cup ON u.ID = cup.user_id
                {$where_clause}
                ORDER BY u.ID DESC
                LIMIT %d OFFSET %d",
                array_merge($where_params, [$per_page, $offset])
            ),
            'ARRAY_A'
        );

        // Получаем уникальные статусы для фильтра
        $statuses = $wpdb->get_col(
            "SELECT DISTINCT status 
            FROM {$this->profile_table_name} 
            WHERE status IS NOT NULL 
            ORDER BY status ASC"
        );

        // Выводим сообщения об ошибках или успехе
        $message = '';
        if (isset($_GET['message'])) {
            if ($_GET['message'] === 'updated') {
                $message = '<div class="notice notice-success is-dismissible"><p>Профиль пользователя успешно обновлен.</p></div>';
            } elseif ($_GET['message'] === 'error') {
                $message = '<div class="notice notice-error is-dismissible"><p>Ошибка при обновлении профиля пользователя.</p></div>';
            }
        }

?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Пользователи</h1>
            <hr class="wp-header-end">

            <?php echo $message; ?>

            <!-- Фильтр по статусу -->
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
                    <button type="submit" id="filter-submit" class="button action">Фильтровать</button>
                </div>
                <br class="clear">
            </div>

            <!-- Таблица пользователей -->
            <div class="wp-list-table-wrap">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Имя пользователя</th>
                            <th scope="col">Ставка кэшбэка (%)</th>
                            <th scope="col">Мин. сумма выплаты</th>
                            <th scope="col">Статус</th>
                            <th scope="col">Причина бана</th>
                            <th scope="col">Дата бана</th>
                            <th scope="col">Действия</th>
                        </tr>
                    </thead>
                    <tfoot>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Имя пользователя</th>
                            <th scope="col">Ставка кэшбэка (%)</th>
                            <th scope="col">Мин. сумма выплаты</th>
                            <th scope="col">Статус</th>
                            <th scope="col">Причина бана</th>
                            <th scope="col">Дата бана</th>
                            <th scope="col">Действия</th>
                        </tr>
                    </tfoot>
                    <tbody id="users-tbody">
                        <?php if (!empty($users)): ?>
                            <?php foreach ($users as $user): ?>
                                <tr data-user-id="<?php echo esc_attr($user['ID']); ?>">
                                    <td><?php echo esc_html($user['ID']); ?></td>
                                    <td><?php echo esc_html($user['display_name']); ?></td>
                                    <td class="edit-field" data-field="cashback_rate">
                                        <?php echo esc_html($user['cashback_rate'] ?? '60.00'); ?>
                                    </td>
                                    <td class="edit-field" data-field="min_payout_amount">
                                        <?php echo esc_html($user['min_payout_amount'] ?? '100.00'); ?>
                                    </td>
                                    <td class="edit-field" data-field="status">
                                        <?php echo esc_html($user['status'] ?? 'active'); ?>
                                    </td>
                                    <td class="edit-field" data-field="ban_reason">
                                        <?php echo esc_html($user['ban_reason'] ?? ''); ?>
                                    </td>
                                    <td class="edit-field" data-field="banned_at">
                                        <?php echo esc_html($user['banned_at'] ? date('Y-m-d H:i:s', strtotime($user['banned_at'])) : ''); ?>
                                    </td>
                                    <td>
                                        <button class="button button-secondary edit-btn">Редактировать</button>
                                        <button class="button button-primary save-btn" style="display:none;">Сохранить</button>
                                        <button class="button button-default cancel-btn" style="display:none;">Отмена</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">Нет пользователей для отображения.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Пагинация -->
            <?php
            $pagination_args = array(
                'total_items' => $total_users,
                'per_page'    => $per_page,
                'current_page' => $current_page,
                'total_pages' => ceil($total_users / $per_page),
                'format'      => '?paged=%#%',
                'add_args'    => !empty($filter_status) ? array('status' => $filter_status) : array(),
            );

            $this->render_pagination($pagination_args);
            ?>

            <!-- Скрипты для работы с формой -->
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Обработка фильтра по статусу
                    $('#filter-submit').on('click', function() {
                        var status = $('#filter-status').val();
                        var url = new URL(window.location);
                        if (status) {
                            url.searchParams.set('status', status);
                            url.searchParams.delete('paged'); // Сброс пагинации
                        } else {
                            url.searchParams.delete('status');
                            url.searchParams.delete('paged');
                        }
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

                            if (field === 'status') {
                                // Для поля status создаем select
                                var selectHtml = '<select class="edit-input" data-field="' + field + '">';
                                selectHtml += '<option value="active"' + (currentValue === 'active' ? ' selected' : '') + '>active</option>';
                                selectHtml += '<option value="noactive"' + (currentValue === 'noactive' ? ' selected' : '') + '>noactive</option>';
                                selectHtml += '<option value="banned"' + (currentValue === 'banned' ? ' selected' : '') + '>banned</option>';
                                selectHtml += '<option value="deleted"' + (currentValue === 'deleted' ? ' selected' : '') + '>deleted</option>';
                                selectHtml += '</select>';
                                cell.html(selectHtml);
                            } else if (field === 'cashback_rate' || field === 'min_payout_amount') {
                                // Для числовых полей создаем input с типом number
                                var inputType = field === 'cashback_rate' ? 'number' : 'number';
                                var step = field === 'cashback_rate' ? '0.01' : '0.01';
                                var placeholder = field === 'cashback_rate' ? 'Ставка кэшбэка' : 'Мин. сумма';
                                cell.html('<input type="number" step="' + step + '" class="edit-input regular-text" data-field="' + field + '" value="' + currentValue + '" placeholder="' + placeholder + '" />');
                            } else {
                                // Для остальных полей создаем input
                                cell.html('<input type="text" class="edit-input regular-text" data-field="' + field + '" value="' + currentValue + '" />');
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
                        var userId = row.data('user-id');
                        var data = {
                            'action': 'update_user_profile',
                            'user_id': userId,
                            'nonce': '<?php echo wp_create_nonce('update_user_profile_nonce'); ?>'
                        };

                        row.find('.edit-input').each(function() {
                            var input = $(this);
                            var field = input.data('field');
                            data[field] = input.val();
                        });

                        // Валидация данных
                        var cashbackRate = parseFloat(data['cashback_rate']);
                        var minPayoutAmount = parseFloat(data['min_payout_amount']);

                        if (isNaN(cashbackRate) || cashbackRate < 0 || cashbackRate > 100) {
                            alert('Ставка кэшбэка должна быть числом от 0 до 100');
                            return;
                        }

                        if (isNaN(minPayoutAmount) || minPayoutAmount < 0) {
                            alert('Минимальная сумма выплаты должна быть положительным числом');
                            return;
                        }

                        $.post(ajaxurl, data, function(response) {
                            if (response.success) {
                                // Обновляем значения в ячейках
                                row.find('.edit-field[data-field="cashback_rate"]').text(response.data.cashback_rate);
                                row.find('.edit-field[data-field="min_payout_amount"]').text(response.data.min_payout_amount);
                                row.find('.edit-field[data-field="status"]').text(response.data.status);
                                row.find('.edit-field[data-field="ban_reason"]').text(response.data.ban_reason);
                                row.find('.edit-field[data-field="banned_at"]').text(response.data.banned_at ? response.data.banned_at : '');

                                // Переключаем строку в режим просмотра
                                row.find('.save-btn, .cancel-btn').hide();
                                row.find('.edit-btn').show();

                                // Показываем сообщение об успешном обновлении
                                $('.wp-header-end').after('<div class="notice notice-success is-dismissible"><p>Профиль пользователя успешно обновлен.</p></div>');
                                setTimeout(function() {
                                    $('.notice-success').fadeOut().remove();
                                }, 3000);
                            } else {
                                alert('Ошибка при обновлении профиля пользователя: ' + response.data.message);
                            }
                        }).fail(function() {
                            alert('Ошибка соединения при обновлении профиля пользователя');
                        });
                    });

                    // Сброс строки к режиму просмотра
                    function resetRowToViewMode(row) {
                        // Обновляем строку данными из базы данных
                        var userId = row.data('user-id');
                        var data = {
                            'action': 'get_user_profile',
                            'user_id': userId,
                            'nonce': '<?php echo wp_create_nonce('get_user_profile_nonce'); ?>'
                        };

                        $.post(ajaxurl, data, function(response) {
                            if (response.success) {
                                row.find('.edit-field[data-field="cashback_rate"]').text(response.data.cashback_rate);
                                row.find('.edit-field[data-field="min_payout_amount"]').text(response.data.min_payout_amount);
                                row.find('.edit-field[data-field="status"]').text(response.data.status);
                                row.find('.edit-field[data-field="ban_reason"]').text(response.data.ban_reason);
                                row.find('.edit-field[data-field="banned_at"]').text(response.data.banned_at ? response.data.banned_at : '');
                            } else {
                                // Если не удалось получить данные, восстанавливаем старые значения
                                row.find('.edit-field').each(function() {
                                    var cell = $(this);
                                    var field = cell.data('field');
                                    var currentValue = cell.find('.edit-input').val();

                                    if (field === 'status') {
                                        cell.text(currentValue);
                                    } else {
                                        cell.text(currentValue);
                                    }
                                });
                            }
                        }).fail(function() {
                            // Если ошибка соединения, восстанавливаем старые значения
                            row.find('.edit-field').each(function() {
                                var cell = $(this);
                                var field = cell.data('field');
                                var currentValue = cell.find('.edit-input').val();

                                if (field === 'status') {
                                    cell.text(currentValue);
                                } else {
                                    cell.text(currentValue);
                                }
                            });
                        });

                        row.find('.save-btn, .cancel-btn').hide();
                        row.find('.edit-btn').show();
                    }
                });
            </script>
        </div>
<?php
    }

    /**
     * Обработка AJAX запроса на обновление профиля пользователя
     */
    public function handle_update_user_profile(): void
    {
        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'update_user_profile_nonce')) {
            wp_send_json_error(['message' => 'Неверный nonce.']);
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав для выполнения этого действия.']);
            return;
        }

        global $wpdb;

        $user_id = intval($_POST['user_id']);
        $cashback_rate = floatval($_POST['cashback_rate']);
        $min_payout_amount = floatval($_POST['min_payout_amount']);
        $status = sanitize_text_field($_POST['status']);
        $ban_reason = sanitize_text_field($_POST['ban_reason']);

        // Валидация данных
        if ($cashback_rate < 0 || $cashback_rate > 100) {
            wp_send_json_error(['message' => 'Ставка кэшбэка должна быть числом от 0 до 100.']);
            return;
        }

        if ($min_payout_amount < 0) {
            wp_send_json_error(['message' => 'Минимальная сумма выплаты должна быть положительным числом.']);
            return;
        }

        // Проверяем, что статус допустим
        $allowed_statuses = ['active', 'noactive', 'banned', 'deleted'];
        if (!in_array($status, $allowed_statuses)) {
            wp_send_json_error(['message' => 'Недопустимый статус пользователя.']);
            return;
        }

        // Обновляем запись в базе данных
        $result = $wpdb->replace(
            $this->profile_table_name,
            [
                'user_id' => $user_id,
                'cashback_rate' => $cashback_rate,
                'min_payout_amount' => $min_payout_amount,
                'status' => $status,
                'ban_reason' => $ban_reason,
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%f', '%f', '%s', '%s', '%s']
        );

        if ($result === false) {
            wp_send_json_error(['message' => 'Ошибка при обновлении профиля пользователя в базе данных.']);
            return;
        }

        // Возвращаем обновленные данные
        wp_send_json_success([
            'user_id' => $user_id,
            'cashback_rate' => $cashback_rate,
            'min_payout_amount' => $min_payout_amount,
            'status' => $status,
            'ban_reason' => $ban_reason
        ]);
    }

    /**
     * Обработка AJAX запроса на получение профиля пользователя
     */
    public function handle_get_user_profile(): void
    {
        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'get_user_profile_nonce')) {
            wp_send_json_error(['message' => 'Неверный nonce.']);
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав для выполнения этого действия.']);
            return;
        }

        global $wpdb;

        $user_id = intval($_POST['user_id']);

        // Получаем данные пользователя из базы данных
        $user_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cashback_rate, min_payout_amount, status, ban_reason, banned_at 
                 FROM {$this->profile_table_name} 
                 WHERE user_id = %d",
                $user_id
            ),
            ARRAY_A
        );

        if (!$user_data) {
            // Если записи нет, возвращаем значения по умолчанию
            $user_data = [
                'cashback_rate' => '60.00',
                'min_payout_amount' => '100.00',
                'status' => 'active',
                'ban_reason' => '',
                'banned_at' => null
            ];
        }

        // Возвращаем данные
        wp_send_json_success($user_data);
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
                'type' => 'array',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]);

            if ($pagination_links) {
                echo '<div class="tablenav bottom">';
                echo '<div class="tablenav-pages">';
                echo '<span class="pagination-links">';
                echo implode('', $pagination_links);
                echo '</span>';
                echo '</div>';
                echo '<br class="clear"></div>';
            }
        } else {
            // Альтернативная реализация пагинации, если paginate_links недоступна
            echo '<div class="tablenav bottom">';
            echo '<div class="tablenav-pages">';
            echo '<span class="pagination-links">';

            // Создаем простую пагинацию вручную
            $base_url = admin_url('admin.php?page=cashback-users');
            if (!empty($add_args)) {
                foreach ($add_args as $key => $value) {
                    $base_url = add_query_arg($key, $value, $base_url);
                }
            }

            // Предыдущая страница
            if ($current_page > 1) {
                $prev_page = $current_page - 1;
                $prev_url = add_query_arg('paged', $prev_page, $base_url);
                echo '<a class="prev-page button" href="' . esc_url($prev_url) . '">&laquo;</a>';
            }

            // Текущая страница
            echo '<span class="paging-input">';
            echo '<span class="tablenav-paging-text">' . esc_html($current_page) . ' из ' . esc_html($total_pages) . '</span>';
            echo '</span>';

            // Следующая страница
            if ($current_page < $total_pages) {
                $next_page = $current_page + 1;
                $next_url = add_query_arg('paged', $next_page, $base_url);
                echo '<a class="next-page button" href="' . esc_url($next_url) . '">&raquo;</a>';
            }

            echo '</span>';
            echo '</div>';
            echo '<br class="clear"></div>';
        }
    }
}

// Инициализируем класс
$users_management_admin = new Cashback_Users_Management_Admin();

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
    use AdminPaginationTrait;

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

        // Подключение скриптов
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Подключение скриптов и стилей для админ-панели
     */
    public function enqueue_admin_scripts(string $hook): void
    {
        $allowed_hooks = [
            'cashback-overview_page_cashback-users',
            'toplevel_page_cashback-users',
            'admin_page_cashback-users'
        ];

        $is_users_page = in_array($hook, $allowed_hooks, true) ||
            (isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-users');

        if (!$is_users_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-users-css',
            plugins_url('../assets/css/admin.css', __FILE__),
            [],
            '1.0.1'
        );

        wp_enqueue_script(
            'cashback-admin-users',
            plugins_url('../assets/js/admin-users-management.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('cashback-admin-users', 'cashbackUsersData', [
            'updateNonce' => wp_create_nonce('update_user_profile_nonce'),
            'getNonce' => wp_create_nonce('get_user_profile_nonce'),
        ]);
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
        $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 10;
        $offset = ($current_page - 1) * $per_page;

        // Получаем фильтр статуса с валидацией по допустимому списку
        $filter_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $allowed_filter_statuses = ['active', 'inactive', 'blocked'];
        if (!empty($filter_status) && !in_array($filter_status, $allowed_filter_statuses, true)) {
            $filter_status = '';
        }

        // Подсчет общего количества пользователей
        if (!empty($filter_status)) {
            $total_users = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                    FROM {$this->table_name} u
                    LEFT JOIN {$this->profile_table_name} cup ON u.ID = cup.user_id
                    WHERE cup.status = %s",
                    $filter_status
                )
            );
        } else {
            $total_users = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                    FROM {$this->table_name} u
                    LEFT JOIN {$this->profile_table_name} cup ON u.ID = cup.user_id
                    WHERE %d = %d",
                    1,
                    1
                )
            );
        }

        // Получаем пользователей с профилями
        if (!empty($filter_status)) {
            $users = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT u.ID, u.display_name,
                            cup.cashback_rate, cup.min_payout_amount, cup.status, cup.ban_reason, cup.banned_at
                    FROM {$this->table_name} u
                    LEFT JOIN {$this->profile_table_name} cup ON u.ID = cup.user_id
                    WHERE cup.status = %s
                    ORDER BY u.ID ASC
                    LIMIT %d OFFSET %d",
                    $filter_status,
                    $per_page,
                    $offset
                ),
                'ARRAY_A'
            );
        } else {
            $users = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT u.ID, u.display_name,
                            cup.cashback_rate, cup.min_payout_amount, cup.status, cup.ban_reason, cup.banned_at
                    FROM {$this->table_name} u
                    LEFT JOIN {$this->profile_table_name} cup ON u.ID = cup.user_id
                    ORDER BY u.ID ASC
                    LIMIT %d OFFSET %d",
                    $per_page,
                    $offset
                ),
                'ARRAY_A'
            );
        }

        // Определяем все доступные статусы для фильтра
        $statuses = ['active', 'noactive', 'banned', 'deleted'];

        // Выводим сообщения об ошибках или успехе
        $message = '';
        if (isset($_GET['message'])) {
            $message_type = sanitize_text_field(wp_unslash($_GET['message']));
            if ($message_type === 'updated') {
                $message = '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Профиль пользователя успешно обновлен.', 'cashback-plugin') . '</p></div>';
            } elseif ($message_type === 'error') {
                $message = '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Ошибка при обновлении профиля пользователя.', 'cashback-plugin') . '</p></div>';
            }
        }

?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Пользователи</h1>
            <hr class="wp-header-end">

            <?php echo wp_kses_post($message); ?>

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
                                <td colspan="8">Нет пользователей для отображения.</td>
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
                'total_pages' => (int) ceil($total_users / $per_page),
                'page_slug'   => 'cashback-users',
                'add_args'    => !empty($filter_status) ? array('status' => $filter_status) : array(),
            );

            $this->render_pagination($pagination_args);
            ?>

        </div>
<?php
    }

    /**
     * Обработка AJAX запроса на обновление профиля пользователя
     */
    public function handle_update_user_profile(): void
    {
        // Проверяем наличие nonce
        if (!isset($_POST['nonce'])) {
            wp_send_json_error(['message' => 'Отсутствует nonce.']);
            return;
        }

        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'update_user_profile_nonce')) {
            wp_send_json_error(['message' => 'Неверный nonce.']);
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав для выполнения этого действия.']);
            return;
        }

        // Проверяем наличие user_id
        if (!isset($_POST['user_id'])) {
            wp_send_json_error(['message' => 'Отсутствует ID пользователя.']);
            return;
        }

        global $wpdb;

        $user_id = intval($_POST['user_id']);

        // Подготовим массив для обновления, включая только те поля, которые были переданы
        $update_data = array();
        $update_formats = array();

        // Проверяем и добавляем только измененные поля
        if (isset($_POST['cashback_rate'])) {
            $cashback_rate = sanitize_text_field(wp_unslash($_POST['cashback_rate']));

            // Валидация данных
            if (!is_numeric($cashback_rate) || bccomp($cashback_rate, '0', 2) < 0 || bccomp($cashback_rate, '100', 2) > 0) {
                wp_send_json_error(['message' => 'Ставка кэшбэка должна быть числом от 0 до 100.']);
                return;
            }

            $update_data['cashback_rate'] = $cashback_rate;
            $update_formats[] = '%s';
        }

        if (isset($_POST['min_payout_amount'])) {
            $min_payout_amount = sanitize_text_field(wp_unslash($_POST['min_payout_amount']));

            if (!is_numeric($min_payout_amount) || bccomp($min_payout_amount, '0', 2) < 0) {
                wp_send_json_error(['message' => 'Минимальная сумма выплаты должна быть положительным числом.']);
                return;
            }

            $update_data['min_payout_amount'] = $min_payout_amount;
            $update_formats[] = '%s';
        }

        if (isset($_POST['status'])) {
            $status = sanitize_text_field(wp_unslash($_POST['status']));

            // Проверяем, что статус допустим
            $allowed_statuses = ['active', 'noactive', 'banned', 'deleted'];
            if (!in_array($status, $allowed_statuses, true)) {
                wp_send_json_error(['message' => 'Недопустимый статус пользователя.']);
                return;
            }

            // Если устанавливаем статус "banned", проверяем обязательность причины бана
            if ($status === 'banned') {
                $ban_reason = isset($_POST['ban_reason']) ? trim(sanitize_text_field(wp_unslash($_POST['ban_reason']))) : '';
                if (empty($ban_reason)) {
                    wp_send_json_error(['message' => 'Заполните причину бана пользователя.']);
                    return;
                }
            }

            $update_data['status'] = $status;
            $update_formats[] = '%s';
        }

        if (isset($_POST['ban_reason'])) {
            $ban_reason = sanitize_text_field(wp_unslash($_POST['ban_reason']));

            $update_data['ban_reason'] = $ban_reason;
            $update_formats[] = '%s';
        }

        // Получаем текущий статус пользователя (для проверки бана/разбана)
        $old_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$this->profile_table_name} WHERE user_id = %d",
            $user_id
        ));

        // Добавляем дату обновления
        $update_data['updated_at'] = current_time('mysql');
        $update_formats[] = '%s';

        // Обновляем только те поля, которые были изменены
        $result = $wpdb->update(
            $this->profile_table_name,
            $update_data,
            ['user_id' => $user_id],
            $update_formats,
            ['%d']  // Формат условия
        );

        if ($result === false) {
            wp_send_json_error(['message' => 'Ошибка при обновлении профиля пользователя в базе данных.']);
            return;
        }

        // Если пользователь был забанен - обрабатываем последствия
        if (isset($_POST['status']) && $_POST['status'] === 'banned') {
            try {
                $ban_reason = isset($_POST['ban_reason']) ? sanitize_text_field(wp_unslash($_POST['ban_reason'])) : '';

                // Перехватываем любой вывод, который может сломать JSON-ответ
                ob_start();
                $this->handle_user_ban($user_id, $ban_reason);
                ob_end_clean();
            } catch (Exception $e) {
                wp_send_json_error(['message' => 'Ошибка при обработке бана: ' . $e->getMessage()]);
                return;
            }
        }

        // Если пользователь был разбанен - обрабатываем последствия
        if ($old_status === 'banned' && isset($_POST['status']) && $_POST['status'] !== 'banned') {
            // Перехватываем любой вывод, который может сломать JSON-ответ
            ob_start();
            $this->handle_user_unban($user_id);
            ob_end_clean();
        }

        // Получаем обновленные данные из базы
        $updated_user_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cashback_rate, min_payout_amount, status, ban_reason, banned_at 
                 FROM {$this->profile_table_name} 
                 WHERE user_id = %d",
                $user_id
            ),
            ARRAY_A
        );

        if (!$updated_user_data) {
            wp_send_json_error(['message' => 'Не удалось получить обновленные данные пользователя.']);
            return;
        }

        // Возвращаем обновленные данные
        wp_send_json_success($updated_user_data);
    }

    /**
     * Обработка AJAX запроса на получение профиля пользователя
     */
    public function handle_get_user_profile(): void
    {
        // Проверяем наличие nonce
        if (!isset($_POST['nonce'])) {
            wp_send_json_error(['message' => 'Отсутствует nonce.']);
            return;
        }

        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'get_user_profile_nonce')) {
            wp_send_json_error(['message' => 'Неверный nonce.']);
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав для выполнения этого действия.']);
            return;
        }

        // Проверяем наличие user_id
        if (!isset($_POST['user_id'])) {
            wp_send_json_error(['message' => 'Отсутствует ID пользователя.']);
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
     * Обработка последствий бана пользователя
     *
     * @param int $user_id ID забаненного пользователя
     * @param string $ban_reason Причина бана
     */
    private function handle_user_ban(int $user_id, string $ban_reason): void
    {
        global $wpdb;

        // 1. Отменяем активные заявки на выплату (все кроме failed и paid)
        $requests_table = $wpdb->prefix . 'cashback_payout_requests';

        $active_requests = $wpdb->get_results($wpdb->prepare(
            "SELECT id, total_amount, status FROM {$requests_table}
             WHERE user_id = %d
             AND status NOT IN ('failed', 'paid', 'declined')",
            $user_id
        ));

        foreach ($active_requests as $request) {
            $wpdb->update(
                $requests_table,
                [
                    'status' => 'declined',
                    'fail_reason' => '(Аккаунт забанен)',
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $request->id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            // Логируем отмену
            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'payout_declined_on_ban',
                    get_current_user_id(),
                    'payout_request',
                    $request->id,
                    ['amount' => $request->total_amount, 'user_id' => $user_id]
                );
            }
        }

        // 2. Логируем бан пользователя
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'user_banned',
                get_current_user_id(),
                'user',
                $user_id,
                ['ban_reason' => $ban_reason]
            );
        }

        // 3. Отправляем email уведомление
        $user = get_userdata($user_id);
        if ($user && $user->user_email) {
            $subject = 'Ваш аккаунт кэшбэк заблокирован';
            $message = sprintf(
                "Здравствуйте, %s!\n\nВаш аккаунт кэшбэк был заблокирован.\nПричина: %s\n\nВаш баланс был заморожен.\nДля разблокировки обратитесь к администратору: %s",
                $user->display_name,
                $ban_reason ?: 'Не указана',
                get_option('admin_email')
            );

            wp_mail($user->user_email, $subject, $message);
        }
    }

    /**
     * Обработка последствий разбана пользователя
     *
     * @param int $user_id ID разбаненного пользователя
     */
    private function handle_user_unban(int $user_id): void
    {
        global $wpdb;

        // Обновляем надпись в declined выплатах которые были отменены при бане
        $requests_table = $wpdb->prefix . 'cashback_payout_requests';

        // Обновляем новые записи (русский текст)
        $wpdb->query($wpdb->prepare(
            "UPDATE {$requests_table}
             SET fail_reason = '(Аккаунт был забанен)'
             WHERE user_id = %d
             AND status = 'declined'
             AND fail_reason = '(Аккаунт забанен)'",
            $user_id
        ));

        // Обновляем старые записи (английский текст)
        $wpdb->query($wpdb->prepare(
            "UPDATE {$requests_table}
             SET fail_reason = '(Аккаунт был забанен)'
             WHERE user_id = %d
             AND status = 'declined'
             AND fail_reason = 'Account banned'",
            $user_id
        ));

        // Логируем разбан
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'user_unbanned',
                get_current_user_id(),
                'user',
                $user_id,
                []
            );
        }
    }

    // render_pagination() предоставляется через AdminPaginationTrait
}

// Инициализируем класс
$users_management_admin = new Cashback_Users_Management_Admin();

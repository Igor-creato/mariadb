<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс управления поддержкой в админ-панели
 */
class Cashback_Support_Admin
{
    private string $tickets_table;
    private string $messages_table;

    public function __construct()
    {
        global $wpdb;
        $this->tickets_table = $wpdb->prefix . 'cashback_support_tickets';
        $this->messages_table = $wpdb->prefix . 'cashback_support_messages';

        add_action('admin_menu', [$this, 'add_admin_menu']);

        // AJAX обработчики (регистрируем всегда для работы кнопки toggle)
        add_action('wp_ajax_support_toggle_module', [$this, 'handle_toggle_module']);
        add_action('wp_ajax_support_admin_reply', [$this, 'handle_admin_reply']);
        add_action('wp_ajax_support_change_status', [$this, 'handle_change_status']);
        add_action('wp_ajax_support_admin_unread_count', [$this, 'handle_get_unread_count']);

        add_action('admin_footer', [$this, 'render_badge_updater_script']);
    }

    /**
     * Регистрация подменю "Поддержка" в меню "Кэшбэк"
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'cashback-overview',
            'Поддержка',
            $this->get_menu_title(),
            'manage_options',
            'cashback-support',
            [$this, 'render_support_page']
        );
    }

    /**
     * Получить заголовок меню с бейджем непрочитанных
     */
    private function get_menu_title(): string
    {
        $title = 'Поддержка';

        if (!Cashback_Support_DB::is_module_enabled()) {
            return $title;
        }

        $count = Cashback_Support_DB::get_unread_tickets_count();
        if ($count > 0) {
            $title .= sprintf(
                ' <span class="awaiting-mod count-%d"><span class="pending-count">%s</span></span>',
                $count,
                number_format_i18n($count)
            );
        }

        return $title;
    }

    /**
     * Главная страница рендеринга
     */
    public function render_support_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('У вас недостаточно прав для просмотра этой страницы.');
        }

        $module_enabled = Cashback_Support_DB::is_module_enabled();

        echo '<div class="wrap">';

        if (!$module_enabled) {
            $this->render_module_disabled();
            echo '</div>';
            return;
        }

        // Определяем текущее действие
        $action = sanitize_text_field(wp_unslash($_GET['action'] ?? ''));
        $ticket_id = absint($_GET['ticket_id'] ?? 0);

        if ($action === 'view' && $ticket_id > 0) {
            $this->render_ticket_view($ticket_id);
        } else {
            $this->render_ticket_list();
        }

        echo '</div>';
    }

    /**
     * Вывод контейнера уведомлений и JS-функции showAdminNotice
     */
    private function render_admin_notice_container(): void
    {
        ?>
        <div id="support-admin-notice"></div>
        <script>
        function showAdminNotice(type, message, container) {
            var cssClass = type === 'success' ? 'notice-success' : 'notice-error';
            var $container = jQuery(container || '#support-admin-notice');
            $container.html(
                '<div class="notice ' + cssClass + ' is-dismissible" style="margin: 10px 0;">' +
                '<p>' + jQuery('<span>').text(message).html() + '</p>' +
                '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Закрыть</span></button>' +
                '</div>'
            ).show();
            $container.find('.notice-dismiss').on('click', function() {
                jQuery(this).closest('.notice').fadeOut(300, function() { jQuery(this).remove(); });
            });
            if (type === 'success') {
                setTimeout(function() { $container.find('.notice').fadeOut(300, function() { jQuery(this).remove(); }); }, 5000);
            }
        }
        </script>
        <?php
    }

    /**
     * Рендеринг состояния "модуль выключен"
     */
    private function render_module_disabled(): void
    {
        $nonce = wp_create_nonce('support_toggle_module_nonce');
        ?>
        <h1 class="wp-heading-inline">Поддержка</h1>
        <hr class="wp-header-end">
        <?php $this->render_admin_notice_container(); ?>
        <div class="notice notice-info">
            <p>Модуль поддержки отключен. Включите его, чтобы активировать систему тикетов.</p>
        </div>
        <p>
            <button type="button" class="button button-primary" id="support-enable-module"
                    data-nonce="<?php echo esc_attr($nonce); ?>">
                Включить модуль
            </button>
        </p>
        <script>
        jQuery(document).ready(function($) {
            $('#support-enable-module').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Включение...');
                $.post(ajaxurl, {
                    action: 'support_toggle_module',
                    enabled: 1,
                    nonce: btn.data('nonce')
                }, function(response) {
                    if (response.success) {
                        showAdminNotice('success', 'Модуль поддержки включен');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showAdminNotice('error', response.data.message || 'Ошибка');
                        btn.prop('disabled', false).text('Включить модуль');
                    }
                }).fail(function() {
                    showAdminNotice('error', 'Ошибка сервера');
                    btn.prop('disabled', false).text('Включить модуль');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Рендеринг списка тикетов
     */
    private function render_ticket_list(): void
    {
        global $wpdb;

        $nonce = wp_create_nonce('support_toggle_module_nonce');

        // Фильтры
        $filter_status = sanitize_text_field(wp_unslash($_GET['filter_status'] ?? ''));
        $filter_priority = sanitize_text_field(wp_unslash($_GET['filter_priority'] ?? ''));
        $filter_unread = sanitize_text_field(wp_unslash($_GET['filter_unread'] ?? ''));

        // Пагинация
        $current_page = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 10;
        $offset = ($current_page - 1) * $per_page;

        // Построение WHERE
        $where_conditions = [];
        $where_params = [];
        $need_unread_join = false;

        if (!empty($filter_status) && in_array($filter_status, ['open', 'answered', 'closed'], true)) {
            $where_conditions[] = 't.status = %s';
            $where_params[] = $filter_status;
        }

        if (!empty($filter_priority) && in_array($filter_priority, ['urgent', 'normal', 'not_urgent'], true)) {
            $where_conditions[] = 't.priority = %s';
            $where_params[] = $filter_priority;
        }

        if ($filter_unread === '1') {
            $where_conditions[] = 'EXISTS (SELECT 1 FROM `' . $this->messages_table . '` m WHERE m.ticket_id = t.id AND m.is_admin = 0 AND m.is_read = 0)';
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        // Подсчёт общего количества
        $count_sql = "SELECT COUNT(*) FROM `{$this->tickets_table}` t {$where_clause}";
        if (!empty($where_params)) {
            $total_items = (int) $wpdb->get_var($wpdb->prepare($count_sql, $where_params));
        } else {
            $total_items = (int) $wpdb->get_var($count_sql);
        }

        $total_pages = (int) ceil($total_items / $per_page);

        // Получение тикетов
        $select_sql = "SELECT t.*, u.user_login, u.user_email,
            (SELECT COUNT(*) FROM `{$this->messages_table}` m WHERE m.ticket_id = t.id AND m.is_admin = 0 AND m.is_read = 0) as unread_count
            FROM `{$this->tickets_table}` t
            LEFT JOIN `{$wpdb->users}` u ON t.user_id = u.ID
            {$where_clause}
            ORDER BY
                CASE t.status WHEN 'open' THEN 0 WHEN 'answered' THEN 1 WHEN 'closed' THEN 2 END,
                CASE t.priority WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 WHEN 'not_urgent' THEN 2 END,
                t.updated_at DESC
            LIMIT %d OFFSET %d";

        $query_params = array_merge($where_params, [$per_page, $offset]);
        $tickets = $wpdb->get_results($wpdb->prepare($select_sql, $query_params));

        ?>
        <style>
            .support-admin-badge {
                display: inline-block;
                padding: 2px 8px;
                font-size: 0.85em;
                border-radius: 3px;
                color: #fff;
            }
            .status-open { background: #2196F3; }
            .status-answered { background: #4CAF50; }
            .status-closed { background: #9E9E9E; }
            .priority-urgent { background: #f44336; }
            .priority-normal { background: #ff9800; }
            .priority-not_urgent { background: #607d8b; }
        </style>

        <h1 class="wp-heading-inline">Поддержка</h1>
        <hr class="wp-header-end">
        <?php $this->render_admin_notice_container(); ?>

        <p>
            <button type="button" class="button" id="support-disable-module"
                    data-nonce="<?php echo esc_attr($nonce); ?>">
                Отключить модуль
            </button>
        </p>

        <div class="notice notice-info" style="margin: 15px 0;">
            <p>Закрытые тикеты автоматически удаляются через 1 месяц после закрытия.</p>
        </div>

        <!-- Фильтры -->
        <div class="tablenav top">
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="cashback-support">
                <div class="alignleft actions">
                    <select name="filter_status">
                        <option value="">Все статусы</option>
                        <option value="open" <?php selected($filter_status, 'open'); ?>>Открыт</option>
                        <option value="answered" <?php selected($filter_status, 'answered'); ?>>Отвечен</option>
                        <option value="closed" <?php selected($filter_status, 'closed'); ?>>Закрыт</option>
                    </select>
                    <select name="filter_priority">
                        <option value="">Все приоритеты</option>
                        <option value="urgent" <?php selected($filter_priority, 'urgent'); ?>>Срочный</option>
                        <option value="normal" <?php selected($filter_priority, 'normal'); ?>>Обычный</option>
                        <option value="not_urgent" <?php selected($filter_priority, 'not_urgent'); ?>>Не срочный</option>
                    </select>
                    <select name="filter_unread">
                        <option value="">Все сообщения</option>
                        <option value="1" <?php selected($filter_unread, '1'); ?>>Только непрочитанные</option>
                    </select>
                    <button type="submit" class="button action">Фильтровать</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-support')); ?>" class="button action">Сбросить</a>
                </div>
            </form>
            <br class="clear">
        </div>

        <!-- Таблица тикетов -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 60px;">№</th>
                    <th scope="col">Тема</th>
                    <th scope="col" style="width: 150px;">Пользователь</th>
                    <th scope="col" style="width: 100px;">Приоритет</th>
                    <th scope="col" style="width: 100px;">Статус</th>
                    <th scope="col" style="width: 40px;" title="Непрочитанные сообщения">✉</th>
                    <th scope="col" style="width: 140px;">Создан</th>
                    <th scope="col" style="width: 140px;">Обновлён</th>
                    <th scope="col" style="width: 100px;">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($tickets)): ?>
                    <?php foreach ($tickets as $ticket): ?>
                        <tr<?php echo $ticket->unread_count > 0 ? ' style="font-weight: bold;"' : ''; ?>>
                            <td><?php echo esc_html(Cashback_Support_DB::format_ticket_number((int) $ticket->id)); ?></td>
                            <td><?php echo esc_html($ticket->subject); ?></td>
                            <td>
                                <?php echo esc_html($ticket->user_login ?? 'Удалён'); ?>
                                <?php if ($ticket->user_email): ?>
                                    <br><small><?php echo esc_html($ticket->user_email); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="support-admin-badge <?php echo esc_attr($this->get_priority_css_class($ticket->priority)); ?>">
                                    <?php echo esc_html($this->get_priority_label($ticket->priority)); ?>
                                </span>
                            </td>
                            <td>
                                <span class="support-admin-badge <?php echo esc_attr($this->get_status_css_class($ticket->status)); ?>">
                                    <?php echo esc_html($this->get_status_label($ticket->status)); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($ticket->unread_count > 0): ?>
                                    <span class="awaiting-mod count-<?php echo (int) $ticket->unread_count; ?>">
                                        <span class="pending-count"><?php echo (int) $ticket->unread_count; ?></span>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($ticket->created_at))); ?></td>
                            <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($ticket->updated_at))); ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(['page' => 'cashback-support', 'action' => 'view', 'ticket_id' => $ticket->id], admin_url('admin.php'))); ?>" class="button button-small">
                                    Ответить
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9">Тикеты не найдены.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        // Пагинация
        if ($total_pages > 1) {
            $base_url = remove_query_arg('paged', add_query_arg('page', 'cashback-support', admin_url('admin.php')));
            $pagination_links = paginate_links([
                'base'      => add_query_arg('paged', '%#%', $base_url),
                'format'    => '',
                'total'     => $total_pages,
                'current'   => $current_page,
                'add_args'  => array_filter([
                    'filter_status' => $filter_status,
                    'filter_priority' => $filter_priority,
                    'filter_unread' => $filter_unread,
                ]),
                'type'      => 'plain',
                'prev_text' => '&lsaquo; Предыдущая',
                'next_text' => 'Следующая &rsaquo;',
            ]);

            if ($pagination_links) {
                echo '<div class="tablenav bottom">';
                echo '<div class="tablenav-pages">';
                echo '<span class="displaying-num">' . sprintf(_n('%s запись', '%s записей', $total_items, 'cashback-plugin'), number_format_i18n($total_items)) . '</span>';
                echo '<span class="pagination-links">' . wp_kses_post($pagination_links) . '</span>';
                echo '</div><br class="clear"></div>';
            }
        }
        ?>

        <script>
        jQuery(document).ready(function($) {
            $('#support-disable-module').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Отключение...');
                $.post(ajaxurl, {
                    action: 'support_toggle_module',
                    enabled: 0,
                    nonce: btn.data('nonce')
                }, function(response) {
                    if (response.success) {
                        showAdminNotice('success', 'Модуль поддержки отключен');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showAdminNotice('error', response.data.message || 'Ошибка');
                        btn.prop('disabled', false).text('Отключить модуль');
                    }
                }).fail(function() {
                    showAdminNotice('error', 'Ошибка сервера');
                    btn.prop('disabled', false).text('Отключить модуль');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Рендеринг просмотра тикета
     */
    private function render_ticket_view(int $ticket_id): void
    {
        global $wpdb;

        // Получаем тикет
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_login, u.user_email
             FROM `{$this->tickets_table}` t
             LEFT JOIN `{$wpdb->users}` u ON t.user_id = u.ID
             WHERE t.id = %d",
            $ticket_id
        ));

        if (!$ticket) {
            echo '<div class="notice notice-error"><p>Тикет не найден.</p></div>';
            return;
        }

        // Помечаем сообщения пользователя как прочитанные
        $wpdb->update(
            $this->messages_table,
            ['is_read' => 1],
            ['ticket_id' => $ticket_id, 'is_admin' => 0, 'is_read' => 0],
            ['%d'],
            ['%d', '%d', '%d']
        );

        // Получаем все сообщения
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, u.user_login
             FROM `{$this->messages_table}` m
             LEFT JOIN `{$wpdb->users}` u ON m.user_id = u.ID
             WHERE m.ticket_id = %d
             ORDER BY m.created_at ASC",
            $ticket_id
        ));

        $is_closed = ($ticket->status === 'closed');
        $reply_nonce = wp_create_nonce('support_admin_reply_nonce');
        $status_nonce = wp_create_nonce('support_change_status_nonce');

        ?>
        <h1 class="wp-heading-inline">
            <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-support')); ?>">&larr; Назад к списку</a>
            &nbsp;|&nbsp;
            Тикет <?php echo esc_html(Cashback_Support_DB::format_ticket_number($ticket_id)); ?>
        </h1>
        <hr class="wp-header-end">

        <!-- Информация о тикете -->
        <table class="form-table">
            <tr>
                <th>Тема</th>
                <td><?php echo esc_html($ticket->subject); ?></td>
            </tr>
            <tr>
                <th>Пользователь</th>
                <td>
                    <?php echo esc_html($ticket->user_login ?? 'Удалён'); ?>
                    <?php if ($ticket->user_email): ?>
                        (<?php echo esc_html($ticket->user_email); ?>)
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Приоритет</th>
                <td>
                    <span class="support-admin-badge <?php echo esc_attr($this->get_priority_css_class($ticket->priority)); ?>">
                        <?php echo esc_html($this->get_priority_label($ticket->priority)); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Статус</th>
                <td>
                    <span class="support-admin-badge <?php echo esc_attr($this->get_status_css_class($ticket->status)); ?>">
                        <?php echo esc_html($this->get_status_label($ticket->status)); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Создан</th>
                <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($ticket->created_at))); ?></td>
            </tr>
            <?php if ($ticket->closed_at): ?>
            <tr>
                <th>Закрыт</th>
                <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($ticket->closed_at))); ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <?php $this->render_admin_notice_container(); ?>

        <h2>Переписка</h2>

        <!-- Лента сообщений -->
        <div id="support-messages" style="max-width: 800px;">
            <?php foreach ($messages as $msg): ?>
                <?php echo $this->render_message_html($msg); ?>
            <?php endforeach; ?>
        </div>

        <?php if (!$is_closed): ?>
        <!-- Форма ответа и управление -->
        <div id="support-reply-section" style="max-width: 800px; margin-top: 20px;">
            <h3>Ответить</h3>
            <textarea id="support-admin-message" rows="5" class="large-text" placeholder="Введите ваш ответ..."></textarea>
            <p>
                <button type="button" class="button button-primary" id="support-send-reply">Отправить ответ</button>
                <button type="button" class="button" id="support-close-ticket" style="margin-left: 10px; color: #a00;">Закрыть тикет</button>
            </p>
        </div>
        <?php else: ?>
        <div class="notice notice-warning" style="max-width: 800px; margin-top: 20px;">
            <p>Тикет закрыт. Ответить невозможно.</p>
        </div>
        <?php endif; ?>

        <script>
        jQuery(document).ready(function($) {
            var ticketId = <?php echo (int) $ticket_id; ?>;

            // Обновляем бейдж при открытии тикета (сообщения помечены прочитанными)
            if (typeof updateSupportBadge === 'function') updateSupportBadge();

            // Снимаем подсветку ошибки при вводе
            $('#support-admin-message').on('input', function() {
                $(this).css({'border-color': '', 'box-shadow': ''});
            });

            // Ответ администратора
            $('#support-send-reply').on('click', function() {
                var message = $('#support-admin-message').val().trim();
                if (!message) {
                    showAdminNotice('error', 'Введите сообщение пожалуйста');
                    $('#support-admin-message').css({'border-color': '#f44336', 'box-shadow': '0 0 0 1px #f44336'}).focus();
                    return;
                }

                var btn = $(this);
                btn.prop('disabled', true).text('Отправка...');

                $.post(ajaxurl, {
                    action: 'support_admin_reply',
                    ticket_id: ticketId,
                    message: message,
                    nonce: '<?php echo esc_js($reply_nonce); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#support-messages').append(response.data.html);
                        $('#support-admin-message').val('').css({'border-color': '', 'box-shadow': ''});
                        var $statusBadge = $('.form-table .support-admin-badge.status-open, .form-table .support-admin-badge.status-answered, .form-table .support-admin-badge.status-closed');
                        $statusBadge.removeClass('status-open status-closed').addClass('status-answered').text('Отвечен');
                        showAdminNotice('success', 'Сообщение отправлено');
                        if (typeof updateSupportBadge === 'function') updateSupportBadge();
                    } else {
                        showAdminNotice('error', response.data.message || 'Ошибка при отправке');
                    }
                    btn.prop('disabled', false).text('Отправить ответ');
                }).fail(function() {
                    showAdminNotice('error', 'Ошибка сервера');
                    btn.prop('disabled', false).text('Отправить ответ');
                });
            });

            // Закрытие тикета
            $('#support-close-ticket').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Закрытие...');

                $.post(ajaxurl, {
                    action: 'support_change_status',
                    ticket_id: ticketId,
                    status: 'closed',
                    nonce: '<?php echo esc_js($status_nonce); ?>'
                }, function(response) {
                    if (response.success) {
                        var $statusBadge = $('.form-table .status-open, .form-table .status-answered, .form-table .status-closed');
                        $statusBadge.removeClass('status-open status-answered status-closed')
                            .addClass('status-closed').text('Закрыт');
                        $('#support-reply-section').hide();
                        $('#support-messages').after(
                            '<div class="notice notice-warning" style="max-width: 800px; margin-top: 20px;">' +
                            '<p>Тикет закрыт. Ответить невозможно.</p></div>'
                        );
                        showAdminNotice('success', 'Тикет закрыт');
                        if (typeof updateSupportBadge === 'function') updateSupportBadge();
                    } else {
                        showAdminNotice('error', response.data.message || 'Ошибка при закрытии');
                        btn.prop('disabled', false).text('Закрыть тикет');
                    }
                }).fail(function() {
                    showAdminNotice('error', 'Ошибка сервера');
                    btn.prop('disabled', false).text('Закрыть тикет');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Генерация HTML одного сообщения
     */
    private function render_message_html(object $msg): string
    {
        $is_admin = (int) $msg->is_admin === 1;
        $bg_color = $is_admin ? '#e8f4f8' : '#f9f9f9';
        $sender = $is_admin ? 'Администратор' : esc_html($msg->user_login ?? 'Пользователь');
        $date = date_i18n('d.m.Y H:i', strtotime($msg->created_at));

        return sprintf(
            '<div style="background: %s; border: 1px solid #ddd; border-left: 4px solid %s; padding: 12px 16px; margin-bottom: 10px;">
                <div style="margin-bottom: 8px;">
                    <strong>%s</strong>
                    <span style="color: #888; float: right;">%s</span>
                </div>
                <div>%s</div>
            </div>',
            esc_attr($bg_color),
            $is_admin ? '#0073aa' : '#999',
            $sender,
            esc_html($date),
            nl2br(esc_html($msg->message))
        );
    }

    // ========= AJAX обработчики =========

    /**
     * Включение/выключение модуля
     */
    public function handle_toggle_module(): void
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'support_toggle_module_nonce')) {
            wp_send_json_error(['message' => 'Неверный токен безопасности.']);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав.']);
            return;
        }

        $enabled = (int) ($_POST['enabled'] ?? 0) === 1;
        Cashback_Support_DB::set_module_enabled($enabled);

        if ($enabled) {
            // При включении убедимся что таблицы существуют
            Cashback_Support_DB::create_tables();
            // Endpoint зарегистрирован в этом запросе — можно сбросить правила сразу
            flush_rewrite_rules();
        } else {
            // При отключении endpoint ещё зарегистрирован в текущем запросе,
            // поэтому откладываем flush до следующего запроса, где endpoint не будет добавлен
            set_transient('cashback_support_flush_rules', 1, 60);
        }

        wp_send_json_success(['enabled' => $enabled]);
    }

    /**
     * Ответ администратора на тикет
     */
    public function handle_admin_reply(): void
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'support_admin_reply_nonce')) {
            wp_send_json_error(['message' => 'Неверный токен безопасности.']);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав.']);
            return;
        }

        global $wpdb;

        $ticket_id = absint($_POST['ticket_id'] ?? 0);
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        if (!$ticket_id || empty($message)) {
            wp_send_json_error(['message' => 'Заполните все поля.']);
            return;
        }

        if (mb_strlen($message) > 5000) {
            wp_send_json_error(['message' => 'Сообщение слишком длинное (максимум 5000 символов).']);
            return;
        }

        // Проверяем что тикет существует и не закрыт
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, subject, status FROM `{$this->tickets_table}` WHERE id = %d",
            $ticket_id
        ));

        if (!$ticket) {
            wp_send_json_error(['message' => 'Тикет не найден.']);
            return;
        }

        if ($ticket->status === 'closed') {
            wp_send_json_error(['message' => 'Невозможно ответить на закрытый тикет.']);
            return;
        }

        // Вставляем сообщение
        $inserted = $wpdb->insert(
            $this->messages_table,
            [
                'ticket_id' => $ticket_id,
                'user_id' => get_current_user_id(),
                'message' => $message,
                'is_admin' => 1,
                'is_read' => 0,
            ],
            ['%d', '%d', '%s', '%d', '%d']
        );

        if (!$inserted) {
            wp_send_json_error(['message' => 'Ошибка при сохранении сообщения.']);
            return;
        }

        // Обновляем статус тикета
        $wpdb->update(
            $this->tickets_table,
            [
                'status' => 'answered',
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $ticket_id],
            ['%s', '%s'],
            ['%d']
        );

        // Отправляем email пользователю
        $this->send_user_notification($ticket_id, $ticket->subject, (int) $ticket->user_id, $message);

        // Генерируем HTML нового сообщения
        $msg = (object) [
            'is_admin' => 1,
            'user_login' => wp_get_current_user()->user_login,
            'message' => $message,
            'created_at' => current_time('mysql'),
        ];

        wp_send_json_success(['html' => $this->render_message_html($msg)]);
    }

    /**
     * Смена статуса тикета
     */
    public function handle_change_status(): void
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'support_change_status_nonce')) {
            wp_send_json_error(['message' => 'Неверный токен безопасности.']);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав.']);
            return;
        }

        global $wpdb;

        $ticket_id = absint($_POST['ticket_id'] ?? 0);
        $new_status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));

        if (!$ticket_id || !in_array($new_status, ['open', 'answered', 'closed'], true)) {
            wp_send_json_error(['message' => 'Некорректные данные.']);
            return;
        }

        // Проверяем что тикет существует
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM `{$this->tickets_table}` WHERE id = %d",
            $ticket_id
        ));

        if (!$ticket) {
            wp_send_json_error(['message' => 'Тикет не найден.']);
            return;
        }

        if ($ticket->status === 'closed') {
            wp_send_json_error(['message' => 'Невозможно изменить статус закрытого тикета.']);
            return;
        }

        $update_data = [
            'status' => $new_status,
            'updated_at' => current_time('mysql'),
        ];
        $update_format = ['%s', '%s'];

        if ($new_status === 'closed') {
            $update_data['closed_at'] = current_time('mysql');
            $update_format[] = '%s';
        }

        $wpdb->update(
            $this->tickets_table,
            $update_data,
            ['id' => $ticket_id],
            $update_format,
            ['%d']
        );

        wp_send_json_success(['status' => $new_status]);
    }

    /**
     * Получение количества непрочитанных тикетов (для AJAX-обновления бейджа)
     */
    public function handle_get_unread_count(): void
    {
        if (!check_ajax_referer('support_admin_unread_count_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Неверный токен безопасности.']);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error();
            return;
        }

        wp_send_json_success(['count' => Cashback_Support_DB::get_unread_tickets_count()]);
    }

    /**
     * Скрипт автообновления бейджа в меню
     */
    public function render_badge_updater_script(): void
    {
        if (!Cashback_Support_DB::is_module_enabled()) {
            return;
        }
        ?>
        <script>
        (function($) {
            var unreadCountNonce = '<?php echo esc_js(wp_create_nonce('support_admin_unread_count_nonce')); ?>';
            window.updateSupportBadge = function() {
                $.post(ajaxurl, { action: 'support_admin_unread_count', nonce: unreadCountNonce }, function(response) {
                    if (!response.success) return;
                    var count = response.data.count;
                    var $menuLink = $('#adminmenu a[href*="cashback-support"]');
                    if (!$menuLink.length) return;
                    var $badge = $menuLink.find('.awaiting-mod');
                    if (count > 0) {
                        if ($badge.length) {
                            $badge.find('.pending-count').text(count);
                        } else {
                            $menuLink.append(' <span class="awaiting-mod count-' + count + '"><span class="pending-count">' + count + '</span></span>');
                        }
                    } else {
                        $badge.remove();
                    }
                });
            };
        })(jQuery);
        </script>
        <?php
    }

    // ========= Утилиты =========

    /**
     * Отправка email уведомления пользователю
     */
    private function send_user_notification(int $ticket_id, string $subject, int $user_id, string $admin_message): void
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $ticket_number = Cashback_Support_DB::format_ticket_number($ticket_id);
        $email_subject = sprintf('Ответ на тикет %s: %s', $ticket_number, $subject);

        $account_url = '';
        if (function_exists('wc_get_account_endpoint_url')) {
            $account_url = wc_get_account_endpoint_url('cashback-support');
        }

        $body = sprintf(
            "Здравствуйте, %s!\n\nВы получили ответ на ваш тикет %s «%s».\n\nОтвет:\n%s\n\n%s",
            $user->display_name,
            $ticket_number,
            $subject,
            wp_trim_words($admin_message, 100, '...'),
            $account_url ? "Просмотреть: {$account_url}" : ''
        );

        wp_mail($user->user_email, $email_subject, $body);
    }

    private function get_priority_label(string $priority): string
    {
        $labels = [
            'urgent' => 'Срочный',
            'normal' => 'Обычный',
            'not_urgent' => 'Не срочный',
        ];
        return $labels[$priority] ?? $priority;
    }

    private function get_status_label(string $status): string
    {
        $labels = [
            'open' => 'Открыт',
            'answered' => 'Отвечен',
            'closed' => 'Закрыт',
        ];
        return $labels[$status] ?? $status;
    }

    private function get_status_css_class(string $status): string
    {
        $classes = [
            'open' => 'status-open',
            'answered' => 'status-answered',
            'closed' => 'status-closed',
        ];
        return $classes[$status] ?? '';
    }

    private function get_priority_css_class(string $priority): string
    {
        $classes = [
            'urgent' => 'priority-urgent',
            'normal' => 'priority-normal',
            'not_urgent' => 'priority-not_urgent',
        ];
        return $classes[$priority] ?? '';
    }
}

// Инициализируем класс в админке
if (is_admin()) {
    new Cashback_Support_Admin();
}

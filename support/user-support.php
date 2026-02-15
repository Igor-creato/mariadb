<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс поддержки в кабинете пользователя (WooCommerce My Account)
 */
class Cashback_User_Support
{
    private static ?self $instance = null;
    private string $tickets_table;
    private string $messages_table;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        global $wpdb;
        $this->tickets_table = $wpdb->prefix . 'cashback_support_tickets';
        $this->messages_table = $wpdb->prefix . 'cashback_support_messages';

        // Отложенный flush rewrite rules после отключения модуля
        add_action('init', [__CLASS__, 'maybe_flush_rewrite_rules'], 999);

        // Не регистрируем хуки если модуль выключен
        if (!Cashback_Support_DB::is_module_enabled()) {
            return;
        }

        add_action('init', [$this, 'register_endpoint']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
        add_action('woocommerce_account_cashback-support_endpoint', [$this, 'endpoint_content']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_footer', [$this, 'render_menu_badge']);

        // AJAX обработчики
        add_action('wp_ajax_support_create_ticket', [$this, 'handle_create_ticket']);
        add_action('wp_ajax_support_user_reply', [$this, 'handle_user_reply']);
        add_action('wp_ajax_support_user_close_ticket', [$this, 'handle_close_ticket']);
        add_action('wp_ajax_support_load_ticket', [$this, 'handle_load_ticket']);
    }

    /**
     * Регистрация endpoint
     */
    public function register_endpoint(): void
    {
        add_rewrite_endpoint('cashback-support', EP_ROOT | EP_PAGES);
    }

    /**
     * Добавление query vars
     */
    public function add_query_vars(array $vars): array
    {
        $vars[] = 'cashback-support';
        return $vars;
    }

    /**
     * Добавление пункта меню "Поддержка" в My Account
     */
    public function add_menu_item(array $items): array
    {
        if (isset($items['customer-logout'])) {
            $logout = $items['customer-logout'];
            unset($items['customer-logout']);
            $items['cashback-support'] = 'Поддержка';
            $items['customer-logout'] = $logout;
        } else {
            $items['cashback-support'] = 'Поддержка';
        }
        return $items;
    }

    /**
     * Подключение скриптов
     */
    public function enqueue_scripts(): void
    {
        if (!is_user_logged_in() || is_admin()) {
            return;
        }

        $is_account_page = function_exists('is_account_page') && is_account_page();
        if (!$is_account_page) {
            return;
        }

        wp_enqueue_script(
            'cashback-user-support',
            plugins_url('assets/js/user-support.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('cashback-user-support', 'cashback_support', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'create_nonce' => wp_create_nonce('support_create_ticket_nonce'),
            'reply_nonce' => wp_create_nonce('support_user_reply_nonce'),
            'close_nonce' => wp_create_nonce('support_close_ticket_nonce'),
            'load_nonce' => wp_create_nonce('support_load_ticket_nonce'),
        ]);
    }

    /**
     * Вывод бейджа непрочитанных ответов в меню My Account через CSS ::after
     */
    public function render_menu_badge(): void
    {
        if (!is_user_logged_in() || is_admin()) {
            return;
        }

        if (!function_exists('is_account_page') || !is_account_page()) {
            return;
        }

        $count = Cashback_Support_DB::get_unread_admin_replies_count(get_current_user_id());
        if ($count <= 0) {
            return;
        }

        ?>
        <style id="cashback-support-menu-badge-style">
            .woocommerce-MyAccount-navigation-link--cashback-support a::after {
                content: '<?php echo esc_js((string) absint($count)); ?>';
                display: inline-block;
                min-width: 18px;
                height: 18px;
                line-height: 18px;
                padding: 0 5px;
                border-radius: 50%;
                background: #f44336;
                color: #fff !important;
                font-size: 11px;
                font-weight: bold;
                text-align: center;
                margin-left: 6px;
                vertical-align: middle;
            }
        </style>
        <?php
    }

    /**
     * Рендеринг содержимого endpoint
     */
    public function endpoint_content(): void
    {
        if (!is_user_logged_in()) {
            echo '<p>Для доступа к поддержке необходимо войти в аккаунт.</p>';
            return;
        }

        ?>
        <h2>Поддержка</h2>

        <!-- Вкладки -->
        <?php $unread_count = Cashback_Support_DB::get_unread_admin_replies_count(get_current_user_id()); ?>
        <div class="cashback-support-tabs">
            <button type="button" class="cashback-support-tab active" data-tab="new">Новый тикет</button>
            <button type="button" class="cashback-support-tab" data-tab="history">История тикетов<?php if ($unread_count > 0): ?><span class="support-tab-badge" id="support-tab-unread-badge"><?php echo absint($unread_count); ?></span><?php endif; ?></button>
        </div>

        <!-- Вкладка: Новый тикет -->
        <div class="cashback-support-tab-content active" id="tab-new">
            <?php $this->render_create_form(); ?>
        </div>

        <!-- Вкладка: История тикетов -->
        <div class="cashback-support-tab-content" id="tab-history" style="display: none;">
            <?php $this->render_tickets_list(); ?>
        </div>

        <!-- Область просмотра тикета -->
        <div id="support-ticket-detail" style="display: none;">
            <p><a href="#" id="support-back-to-list">&larr; Назад к списку</a></p>
            <div id="support-ticket-detail-content"></div>
        </div>

        <style>
            .cashback-support-tabs {
                display: flex;
                gap: 8px;
                margin-bottom: 20px;
            }
            .cashback-support-tab {
                padding: 10px 24px;
                border: 1px solid #ddd;
                background: #f5f5f5;
                color: #666;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
                border-radius: 8px;
                transition: all 0.2s ease;
                box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            }
            .cashback-support-tab:hover {
                background: #eaeaea;
                color: #333;
                box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            }
            .cashback-support-tab.active {
                background: #333;
                color: #fff;
                border-color: #333;
                font-weight: 600;
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }
            .support-tab-badge {
                display: inline-block;
                min-width: 18px;
                height: 18px;
                line-height: 18px;
                padding: 0 5px;
                border-radius: 50%;
                background: #f44336;
                color: #fff !important;
                font-size: 11px;
                font-weight: bold;
                text-align: center;
                margin-left: 6px;
                vertical-align: middle;
            }
            .cashback-support-tab-content {
                padding: 10px 0;
            }
            .support-ticket-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 14px 18px;
                border: 1px solid #e0e0e0;
                margin-bottom: 10px;
                cursor: pointer;
                border-radius: 8px;
                box-shadow: 0 1px 4px rgba(0,0,0,0.06);
                transition: all 0.2s ease;
            }
            .support-ticket-row:hover {
                background-color: #f5f5f5;
                box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            }
            .support-ticket-info {
                flex: 1;
            }
            .support-ticket-info strong {
                display: block;
                margin-bottom: 4px;
            }
            .support-ticket-meta {
                font-size: 0.85em;
                color: #666;
            }
            .support-ticket-meta span {
                margin-right: 12px;
            }
            .support-badge {
                display: inline-block;
                padding: 2px 8px;
                font-size: 0.8em;
                border-radius: 3px;
                color: #fff;
            }
            .support-badge-open { background: #2196F3; }
            .support-badge-answered { background: #4CAF50; }
            .support-badge-closed { background: #9E9E9E; }
            .support-badge-urgent { background: #f44336; }
            .support-badge-normal { background: #ff9800; }
            .support-badge-not_urgent { background: #607d8b; }
            .support-message {
                padding: 14px 18px;
                margin-bottom: 10px;
                border: 1px solid #e0e0e0;
                border-left: 4px solid #999;
                border-radius: 8px;
                box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            }
            .support-message-admin {
                background: #e8f4f8;
                border-left-color: #2196F3;
            }
            .support-message-user {
                background: #f9f9f9;
                border-left-color: #999;
            }
            .support-message-header {
                display: flex;
                justify-content: space-between;
                margin-bottom: 8px;
                font-size: 0.9em;
            }
            .support-message-header .date {
                color: #888;
            }
            .support-form-group {
                margin-bottom: 15px;
            }
            .support-form-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
            }
            .support-form-group input[type="text"],
            .support-form-group select,
            .support-form-group textarea {
                width: 100%;
                max-width: 600px;
                padding: 8px;
                border: 1px solid #ddd;
            }
            .support-form-group textarea {
                min-height: 120px;
                resize: vertical;
            }
            .support-btn {
                display: inline-block;
                padding: 10px 24px;
                border: none;
                cursor: pointer;
                font-size: 14px;
                transition: opacity 0.2s, background-color 0.2s;
            }
            .support-btn:hover { opacity: 0.85; }
            .support-btn-primary {
                border-radius: var(--btn-accented-brd-radius) !important;
                color: var(--btn-accented-color, #fff) !important;
                box-shadow: var(--btn-accented-box-shadow) !important;
                background-color: var(--btn-accented-bgcolor, #333) !important;
                text-transform: var(--btn-accented-transform, var(--btn-transform, uppercase)) !important;
                font-weight: var(--btn-accented-font-weight, var(--btn-font-weight, 600)) !important;
                font-family: var(--btn-accented-font-family, var(--btn-font-family, inherit)) !important;
                font-style: var(--btn-accented-font-style, var(--btn-font-style, unset)) !important;
            }
            .support-btn-primary:hover {
                color: var(--btn-accented-color-hover, #fff) !important;
                box-shadow: var(--btn-accented-box-shadow-hover) !important;
                background-color: var(--btn-accented-bgcolor-hover, #555) !important;
                opacity: 1;
            }
            .support-btn-danger {
                border-radius: var(--btn-accented-brd-radius) !important;
                color: #fff !important;
                background-color: #f44336 !important;
                text-transform: var(--btn-accented-transform, var(--btn-transform, uppercase)) !important;
                font-weight: var(--btn-accented-font-weight, var(--btn-font-weight, 600)) !important;
                font-family: var(--btn-accented-font-family, var(--btn-font-family, inherit)) !important;
                font-style: var(--btn-accented-font-style, var(--btn-font-style, unset)) !important;
            }
            .support-btn-danger:hover {
                background-color: #d32f2f !important;
                opacity: 1;
            }
            .support-btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
            .support-alert {
                padding: 12px 16px;
                margin-bottom: 15px;
                border: 1px solid;
            }
            .support-alert-success {
                background: #e8f5e9;
                border-color: #4CAF50;
                color: #2e7d32;
            }
            .support-alert-error {
                background: #ffebee;
                border-color: #f44336;
                color: #c62828;
            }
            .support-ticket-actions {
                margin-top: 15px;
                display: flex;
                gap: 10px;
                align-items: center;
            }
            #support-pagination {
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 10px;
                margin-top: 15px;
                padding: 10px 0;
            }
            #support-pagination button:disabled {
                opacity: 0.4;
                cursor: not-allowed;
            }
            #support-pagination .support-page-info {
                font-size: 14px;
                color: #666;
            }
            .support-ticket-row.has-unread {
                border-left: 4px solid #4CAF50;
                background-color: #f0f9f0;
            }
            .support-ticket-row.has-unread:hover {
                background-color: #e5f5e5;
            }
            .support-unread-badge {
                display: inline-block;
                min-width: 18px;
                height: 18px;
                line-height: 18px;
                padding: 0 5px;
                border-radius: 50%;
                background: #f44336;
                color: #fff;
                font-size: 11px;
                font-weight: bold;
                text-align: center;
                margin-left: 8px;
            }
            .support-field-error {
                border-color: #f44336 !important;
                box-shadow: 0 0 0 1px #f44336 !important;
            }
        </style>
        <?php
    }

    /**
     * Рендеринг списка тикетов пользователя
     */
    private function render_tickets_list(): void
    {
        global $wpdb;

        $user_id = get_current_user_id();

        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*,
                (SELECT COUNT(*) FROM `{$this->messages_table}` m WHERE m.ticket_id = t.id AND m.is_admin = 1 AND m.is_read = 0) as unread_count
             FROM `{$this->tickets_table}` t
             WHERE t.user_id = %d
             ORDER BY t.created_at DESC",
            $user_id
        ));

        echo '<p style="color: #666; font-size: 0.9em; margin-bottom: 15px;">Все закрытые тикеты автоматически удаляются через месяц!</p>';

        if (empty($tickets)) {
            echo '<p>У вас пока нет тикетов. Создайте новый тикет, чтобы связаться с поддержкой.</p>';
            return;
        }

        echo '<div id="support-tickets-list">';
        foreach ($tickets as $ticket) {
            $ticket_number = Cashback_Support_DB::format_ticket_number((int) $ticket->id);
            $priority_label = $this->get_priority_label($ticket->priority);
            $status_label = $this->get_status_label($ticket->status);
            $date = date_i18n('d.m.Y H:i', strtotime($ticket->created_at));

            $has_unread = (int) $ticket->unread_count > 0;
            ?>
            <div class="support-ticket-row<?php echo $has_unread ? ' has-unread' : ''; ?>" data-ticket-id="<?php echo (int) $ticket->id; ?>">
                <div class="support-ticket-info">
                    <strong>
                        <?php echo esc_html($ticket_number . ' — ' . $ticket->subject); ?>
                        <?php if ($has_unread): ?>
                            <span class="support-unread-badge"><?php echo (int) $ticket->unread_count; ?></span>
                        <?php endif; ?>
                    </strong>
                    <div class="support-ticket-meta">
                        <span class="support-badge support-badge-<?php echo esc_attr($ticket->status); ?>"><?php echo esc_html($status_label); ?></span>
                        <span class="support-badge support-badge-<?php echo esc_attr($ticket->priority); ?>"><?php echo esc_html($priority_label); ?></span>
                        <span><?php echo esc_html($date); ?></span>
                    </div>
                </div>
            </div>
            <?php
        }
        echo '</div>';
        echo '<div id="support-pagination"></div>';
    }

    /**
     * Рендеринг формы создания тикета
     */
    private function render_create_form(): void
    {
        ?>
        <div id="support-create-alert" style="display: none;"></div>

        <form id="support-create-form" novalidate>
            <div class="support-form-group">
                <label for="support-subject">Тема</label>
                <input type="text" id="support-subject" name="subject" maxlength="255" placeholder="Опишите тему обращения">
            </div>
            <div class="support-form-group">
                <label for="support-priority">Срочность</label>
                <select id="support-priority" name="priority">
                    <option value="" disabled selected>Выберите срочность</option>
                    <option value="not_urgent">Не срочный</option>
                    <option value="normal">Обычный</option>
                    <option value="urgent">Срочный</option>
                </select>
            </div>
            <div class="support-form-group">
                <label for="support-message">Сообщение</label>
                <textarea id="support-message" name="message" placeholder="Опишите вашу проблему или вопрос подробно..."></textarea>
            </div>
            <button type="submit" class="support-btn support-btn-primary" id="support-submit-btn">Отправить</button>
        </form>
        <?php
    }

    // ========= AJAX обработчики =========

    /**
     * Создание нового тикета
     */
    public function handle_create_ticket(): void
    {
        if (!check_ajax_referer('support_create_ticket_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ошибка при отправке, попробуйте еще раз']);
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Ошибка при отправке, попробуйте еще раз']);
            return;
        }

        global $wpdb;

        $user_id = get_current_user_id();
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $priority = sanitize_text_field($_POST['priority'] ?? 'not_urgent');
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        if (empty($subject) || empty($priority) || empty($message)) {
            wp_send_json_error(['message' => 'Заполните все обязательные поля.']);
            return;
        }

        if (mb_strlen($subject) > 255) {
            wp_send_json_error(['message' => 'Тема слишком длинная (максимум 255 символов).']);
            return;
        }

        if (mb_strlen($message) > 5000) {
            wp_send_json_error(['message' => 'Сообщение слишком длинное (максимум 5000 символов).']);
            return;
        }

        // Защита от спама: максимум 5 тикетов в час
        $rate_key = 'support_ticket_rate_' . $user_id;
        $ticket_count = (int) get_transient($rate_key);
        if ($ticket_count >= 5) {
            wp_send_json_error(['message' => 'Слишком много тикетов. Попробуйте позже.']);
            return;
        }
        set_transient($rate_key, $ticket_count + 1, HOUR_IN_SECONDS);

        if (!in_array($priority, ['urgent', 'normal', 'not_urgent'], true)) {
            wp_send_json_error(['message' => 'Выберите срочность.']);
            return;
        }

        // Вставляем тикет и первое сообщение в транзакции
        $wpdb->query('START TRANSACTION');

        $inserted = $wpdb->insert(
            $this->tickets_table,
            [
                'user_id' => $user_id,
                'subject' => $subject,
                'priority' => $priority,
                'status' => 'open',
            ],
            ['%d', '%s', '%s', '%s']
        );

        if (!$inserted) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'Ошибка при отправке, попробуйте еще раз']);
            return;
        }

        $ticket_id = (int) $wpdb->insert_id;

        // Вставляем первое сообщение
        $message_inserted = $wpdb->insert(
            $this->messages_table,
            [
                'ticket_id' => $ticket_id,
                'user_id' => $user_id,
                'message' => $message,
                'is_admin' => 0,
                'is_read' => 0,
            ],
            ['%d', '%d', '%s', '%d', '%d']
        );

        if (!$message_inserted) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'Ошибка при отправке, попробуйте еще раз']);
            return;
        }

        $wpdb->query('COMMIT');

        // Отправляем email администратору
        $this->send_admin_notification($ticket_id, 'new_ticket', $subject);

        $ticket_number = Cashback_Support_DB::format_ticket_number($ticket_id);
        $priority_label = $this->get_priority_label($priority);
        $date = date_i18n('d.m.Y H:i', current_time('timestamp'));

        $ticket_html = sprintf(
            '<div class="support-ticket-row" data-ticket-id="%d">
                <div class="support-ticket-info">
                    <strong>%s</strong>
                    <div class="support-ticket-meta">
                        <span class="support-badge support-badge-open">Открыт</span>
                        <span class="support-badge support-badge-%s">%s</span>
                        <span>%s</span>
                    </div>
                </div>
            </div>',
            $ticket_id,
            esc_html($ticket_number . ' — ' . $subject),
            esc_attr($priority),
            esc_html($priority_label),
            esc_html($date)
        );

        wp_send_json_success([
            'message' => 'Ваше сообщение отправлено, мы уже готовим ответ',
            'ticket_id' => $ticket_id,
            'ticket_number' => $ticket_number,
            'ticket_html' => $ticket_html,
        ]);
    }

    /**
     * Ответ пользователя на тикет
     */
    public function handle_user_reply(): void
    {
        if (!check_ajax_referer('support_user_reply_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ошибка при отправке, попробуйте еще раз']);
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Ошибка при отправке, попробуйте еще раз']);
            return;
        }

        global $wpdb;

        $user_id = get_current_user_id();
        $ticket_id = absint($_POST['ticket_id'] ?? 0);
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        if (!$ticket_id || empty($message)) {
            wp_send_json_error(['message' => 'Ошибка при отправке, попробуйте еще раз']);
            return;
        }

        if (mb_strlen($message) > 5000) {
            wp_send_json_error(['message' => 'Сообщение слишком длинное (максимум 5000 символов).']);
            return;
        }

        // Защита от спама: максимум 20 ответов в час
        $rate_key = 'support_reply_rate_' . $user_id;
        $reply_count = (int) get_transient($rate_key);
        if ($reply_count >= 20) {
            wp_send_json_error(['message' => 'Слишком много сообщений. Попробуйте позже.']);
            return;
        }
        set_transient($rate_key, $reply_count + 1, HOUR_IN_SECONDS);

        // Проверяем что тикет принадлежит пользователю и не закрыт
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, subject, status FROM `{$this->tickets_table}` WHERE id = %d AND user_id = %d",
            $ticket_id,
            $user_id
        ));

        if (!$ticket) {
            wp_send_json_error(['message' => 'Ошибка при отправке, попробуйте еще раз']);
            return;
        }

        if ($ticket->status === 'closed') {
            wp_send_json_error(['message' => 'Невозможно ответить на закрытый тикет. Создайте новый тикет.']);
            return;
        }

        // Вставляем сообщение
        $wpdb->insert(
            $this->messages_table,
            [
                'ticket_id' => $ticket_id,
                'user_id' => $user_id,
                'message' => $message,
                'is_admin' => 0,
                'is_read' => 0,
            ],
            ['%d', '%d', '%s', '%d', '%d']
        );

        // Обновляем статус тикета на "open"
        $wpdb->update(
            $this->tickets_table,
            [
                'status' => 'open',
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $ticket_id],
            ['%s', '%s'],
            ['%d']
        );

        // Отправляем email администратору
        $this->send_admin_notification($ticket_id, 'user_reply', $ticket->subject);

        $user = wp_get_current_user();

        wp_send_json_success([
            'html' => $this->render_message_html($message, $user->user_login, false, current_time('mysql')),
        ]);
    }

    /**
     * Закрытие тикета пользователем
     */
    public function handle_close_ticket(): void
    {
        if (!check_ajax_referer('support_close_ticket_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ошибка при выполнении, попробуйте еще раз']);
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Ошибка при выполнении, попробуйте еще раз']);
            return;
        }

        global $wpdb;

        $user_id = get_current_user_id();
        $ticket_id = absint($_POST['ticket_id'] ?? 0);

        if (!$ticket_id) {
            wp_send_json_error(['message' => 'Ошибка при выполнении, попробуйте еще раз']);
            return;
        }

        // Проверяем что тикет принадлежит пользователю
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM `{$this->tickets_table}` WHERE id = %d AND user_id = %d",
            $ticket_id,
            $user_id
        ));

        if (!$ticket) {
            wp_send_json_error(['message' => 'Ошибка при выполнении, попробуйте еще раз']);
            return;
        }

        if ($ticket->status === 'closed') {
            wp_send_json_error(['message' => 'Тикет уже закрыт.']);
            return;
        }

        $wpdb->update(
            $this->tickets_table,
            [
                'status' => 'closed',
                'closed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $ticket_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        wp_send_json_success(['message' => 'Тикет закрыт.']);
    }

    /**
     * Загрузка переписки тикета
     */
    public function handle_load_ticket(): void
    {
        if (!check_ajax_referer('support_load_ticket_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ошибка загрузки']);
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Ошибка загрузки']);
            return;
        }

        global $wpdb;

        $user_id = get_current_user_id();
        $ticket_id = absint($_POST['ticket_id'] ?? 0);

        if (!$ticket_id) {
            wp_send_json_error(['message' => 'Ошибка загрузки']);
            return;
        }

        // Проверяем что тикет принадлежит пользователю
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$this->tickets_table}` WHERE id = %d AND user_id = %d",
            $ticket_id,
            $user_id
        ));

        if (!$ticket) {
            wp_send_json_error(['message' => 'Тикет не найден']);
            return;
        }

        // Помечаем сообщения админа как прочитанные
        $wpdb->update(
            $this->messages_table,
            ['is_read' => 1],
            ['ticket_id' => $ticket_id, 'is_admin' => 1, 'is_read' => 0],
            ['%d'],
            ['%d', '%d', '%d']
        );

        // Получаем сообщения
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, u.user_login
             FROM `{$this->messages_table}` m
             LEFT JOIN `{$wpdb->users}` u ON m.user_id = u.ID
             WHERE m.ticket_id = %d
             ORDER BY m.created_at ASC",
            $ticket_id
        ));

        $is_closed = ($ticket->status === 'closed');

        // Генерируем HTML
        $html = '';

        // Заголовок
        $ticket_number = Cashback_Support_DB::format_ticket_number($ticket_id);
        $html .= '<h3>' . esc_html($ticket_number . ' — ' . $ticket->subject) . '</h3>';
        $html .= '<p>';
        $html .= '<span class="support-badge support-badge-' . esc_attr($ticket->status) . '">' . esc_html($this->get_status_label($ticket->status)) . '</span> ';
        $html .= '<span class="support-badge support-badge-' . esc_attr($ticket->priority) . '">' . esc_html($this->get_priority_label($ticket->priority)) . '</span> ';
        $html .= '<span style="color: #666;">' . esc_html(date_i18n('d.m.Y H:i', strtotime($ticket->created_at))) . '</span>';
        $html .= '</p>';

        // Контейнер уведомлений
        $html .= '<div id="support-detail-alert" style="display:none;"></div>';

        // Сообщения
        $html .= '<div id="support-messages-list">';
        foreach ($messages as $msg) {
            $is_admin = (int) $msg->is_admin === 1;
            $html .= $this->render_message_html(
                $msg->message,
                $is_admin ? 'Администратор' : ($msg->user_login ?? 'Вы'),
                $is_admin,
                $msg->created_at
            );
        }
        $html .= '</div>';

        // Форма ответа (если тикет не закрыт)
        if (!$is_closed) {
            $html .= '<div class="support-ticket-actions">';
            $html .= '<div style="flex: 1;">';
            $html .= '<textarea id="support-reply-message" rows="3" style="width: 100%; padding: 8px; border: 1px solid #ddd;" placeholder="Введите ваш ответ..."></textarea>';
            $html .= '<div style="margin-top: 10px;">';
            $html .= '<button type="button" class="support-btn support-btn-primary" id="support-reply-btn" data-ticket-id="' . (int) $ticket_id . '">Ответить</button> ';
            $html .= '<button type="button" class="support-btn support-btn-danger" id="support-close-btn" data-ticket-id="' . (int) $ticket_id . '">Закрыть тикет</button>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        } else {
            $html .= '<p style="color: #666; margin-top: 15px;">Тикет закрыт. Для нового обращения создайте новый тикет.</p>';
        }

        // Возвращаем актуальный счётчик непрочитанных для обновления бейджа в меню
        $unread_total = Cashback_Support_DB::get_unread_admin_replies_count($user_id);

        wp_send_json_success(['html' => $html, 'unread_total' => $unread_total]);
    }

    /**
     * Отложенный сброс rewrite rules после отключения модуля
     */
    public static function maybe_flush_rewrite_rules(): void
    {
        if (get_transient('cashback_support_flush_rules')) {
            delete_transient('cashback_support_flush_rules');
            flush_rewrite_rules();
        }
    }

    // ========= Утилиты =========

    /**
     * Генерация HTML одного сообщения
     */
    private function render_message_html(string $message, string $sender, bool $is_admin, string $date): string
    {
        $css_class = $is_admin ? 'support-message-admin' : 'support-message-user';
        $formatted_date = date_i18n('d.m.Y H:i', strtotime($date));

        return sprintf(
            '<div class="support-message %s">
                <div class="support-message-header">
                    <strong>%s</strong>
                    <span class="date">%s</span>
                </div>
                <div>%s</div>
            </div>',
            esc_attr($css_class),
            esc_html($sender),
            esc_html($formatted_date),
            nl2br(esc_html($message))
        );
    }

    /**
     * Отправка email уведомления администратору
     */
    private function send_admin_notification(int $ticket_id, string $event_type, string $subject): void
    {
        $admin_email = get_option('admin_email');
        if (!$admin_email) {
            return;
        }

        $user = wp_get_current_user();
        $ticket_number = Cashback_Support_DB::format_ticket_number($ticket_id);

        if ($event_type === 'new_ticket') {
            $email_subject = sprintf('Новый тикет %s: %s', $ticket_number, $subject);
        } else {
            $email_subject = sprintf('Новый ответ в тикете %s: %s', $ticket_number, $subject);
        }

        $admin_url = admin_url('admin.php?page=cashback-support&action=view&ticket_id=' . $ticket_id);

        $body = sprintf(
            "Пользователь: %s (%s)\nТема: %s\n\nПросмотреть в админке: %s",
            $user->user_login,
            $user->user_email,
            $subject,
            $admin_url
        );

        wp_mail($admin_email, $email_subject, $body);
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
}

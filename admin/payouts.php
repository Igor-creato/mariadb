<?php

declare(strict_types=1);

/**
 * Класс для управления выплатами кэшбэка в админ-панели.
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
    use AdminPaginationTrait;

    /**
     * Имя таблицы запросов на выплату
     *
     * @var string
     */
    private string $table_name;

    /**
     * WooCommerce logger instance
     *
     * @var WC_Logger_Interface|null
     */
    private ?WC_Logger_Interface $logger = null;

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
        add_action('wp_ajax_decrypt_payout_details', [$this, 'handle_decrypt_payout_details']);

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
            (isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-payouts');

        if (!$is_payouts_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-payouts-css',
            plugins_url('../assets/css/admin.css', __FILE__),
            [],
            '1.0.2'
        );

        // Определяем, открыта ли детальная страница
        $is_detail_view = isset($_GET['action']) && sanitize_text_field(wp_unslash($_GET['action'])) === 'view'
            && !empty($_GET['payout_id']);

        if ($is_detail_view) {
            wp_enqueue_script(
                'cashback-admin-payout-detail',
                plugins_url('../assets/js/admin-payout-detail.js', __FILE__),
                ['jquery'],
                '1.0.0',
                true
            );

            wp_localize_script('cashback-admin-payout-detail', 'cashbackPayoutDetailData', [
                'updateNonce' => wp_create_nonce('update_payout_request_nonce'),
                'decryptNonce' => wp_create_nonce('decrypt_payout_details_nonce'),
                'payoutId' => absint($_GET['payout_id']),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'listUrl' => admin_url('admin.php?page=cashback-payouts'),
            ]);
        } else {
            wp_enqueue_script(
                'cashback-admin-payouts',
                plugins_url('../assets/js/admin-payouts.js', __FILE__),
                ['jquery'],
                '1.0.4',
                true
            );

            wp_localize_script('cashback-admin-payouts', 'cashbackPayoutsData', [
                'updateNonce' => wp_create_nonce('update_payout_request_nonce'),
                'getNonce' => wp_create_nonce('get_payout_request_nonce'),
                'decryptNonce' => wp_create_nonce('decrypt_payout_details_nonce'),
                'banks' => $this->get_all_banks(),
            ]);
        }
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

        // Роутинг: если action=view — показываем детальную страницу
        $action = sanitize_text_field(wp_unslash($_GET['action'] ?? ''));
        $view_payout_id = absint($_GET['payout_id'] ?? 0);
        if ($action === 'view' && $view_payout_id > 0) {
            $this->render_payout_detail_page($view_payout_id);
            return;
        }

        global $wpdb;

        // Получаем параметры для пагинации и фильтрации
        $max_allowed_pages = 1000;
        $current_page = max(1, absint($_GET['paged'] ?? 1));
        if ($current_page > $max_allowed_pages) {
            $current_page = $max_allowed_pages;
        }
        $per_page = 10;
        $offset = ($current_page - 1) * $per_page;

        // Получаем фильтры с валидацией
        $filter_status = sanitize_text_field(wp_unslash($_GET['status'] ?? ''));
        $filter_date_from = sanitize_text_field(wp_unslash($_GET['date_from'] ?? ''));
        $filter_date_to = sanitize_text_field(wp_unslash($_GET['date_to'] ?? ''));

        // Валидация дат
        if (!empty($filter_date_from) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
            $filter_date_from = '';
        }
        if (!empty($filter_date_to) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
            $filter_date_to = '';
        }

        // Валидация статуса по допустимому списку
        $allowed_filter_statuses = ['waiting', 'processing', 'paid', 'failed', 'declined', 'needs_retry'];
        if (!empty($filter_status) && !in_array($filter_status, $allowed_filter_statuses, true)) {
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
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE %d = %d",
                    1,
                    1
                )
            );
        }

        // Получаем выплаты
        if (!empty($where_params)) {
            $payouts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, user_id, total_amount, payout_method, payout_account, masked_details,
                    encrypted_details, provider, provider_payout_id, attempts, fail_reason, status,
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
                    "SELECT id, user_id, total_amount, payout_method, payout_account, masked_details,
                    encrypted_details, provider, provider_payout_id, attempts, fail_reason, status,
                    created_at, updated_at
            FROM {$this->table_name}
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d",
                    [$per_page, $offset]
                ),
                'ARRAY_A'
            );
        }

        // Получаем все доступные статусы из ENUM колонки status
        // Используем список всех возможных статусов независимо от наличия записей
        $statuses = ['waiting', 'processing', 'paid', 'failed', 'declined', 'needs_retry'];

        // Выводим сообщения об ошибках или успехе
        $message = '';
        $message_type = '';
        if (isset($_GET['message'])) {
            $message_code = sanitize_text_field(wp_unslash($_GET['message']));
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
                                <?php
                                // Проверяем активность платежной системы и банка
                                $payout_method_info = $this->get_payout_method_info_by_slug($payout['payout_method']);
                                $bank_info = $this->get_bank_info_by_code($payout['provider'] ?? '');
                                $is_actionable_status = in_array($payout['status'], ['waiting', 'processing', 'needs_retry'], true);
                                $method_inactive = !$payout_method_info['is_active'];
                                $bank_inactive = !$bank_info['is_active'];
                                ?>
                                <tr data-payout-id="<?php echo esc_attr($payout['id']); ?>">
                                    <td><?php echo esc_html($payout['user_id']); ?></td>
                                    <td><?php echo esc_html(number_format((float) $payout['total_amount'], 2, '.', ' ')); ?></td>
                                    <td<?php if ($method_inactive && $is_actionable_status): ?> class="cashback-inactive-warning" title="<?php echo esc_attr__('Платежная система деактивирована', 'cashback-plugin'); ?>" <?php endif; ?>>
                                        <?php echo esc_html($payout_method_info['name']); ?>
                                        <?php if ($method_inactive && $is_actionable_status): ?>
                                            <span class="cashback-inactive-badge"><?php echo esc_html__('(неактивна)', 'cashback-plugin'); ?></span>
                                        <?php endif; ?>
                                        </td>
                                        <td class="payout-account-cell" data-payout-id="<?php echo esc_attr($payout['id']); ?>">
                                            <span class="masked-account"><?php echo esc_html($this->get_display_account($payout)); ?></span>
                                            <span class="decrypted-account" style="display:none;"></span>
                                            <?php if ($payout['status'] === 'processing' && (!empty($payout['encrypted_details']) || !empty($payout['payout_account']))): ?>
                                                <button type="button" class="button button-small decrypt-btn" title="<?php echo esc_attr__('Показать реквизиты', 'cashback-plugin'); ?>">&#128065;</button>
                                            <?php endif; ?>
                                        </td>
                                        <td<?php if ($bank_inactive && $is_actionable_status && !empty($bank_info['name'])): ?> class="cashback-inactive-warning" title="<?php echo esc_attr__('Банк деактивирован', 'cashback-plugin'); ?>" <?php endif; ?>>
                                            <?php echo esc_html($bank_info['name']); ?>
                                            <?php if ($bank_inactive && $is_actionable_status && !empty($bank_info['name'])): ?>
                                                <span class="cashback-inactive-badge"><?php echo esc_html__('(неактивен)', 'cashback-plugin'); ?></span>
                                            <?php endif; ?>
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
                                                <?php if ($payout['status'] === 'processing'): ?>
                                                    <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-payouts&action=view&payout_id=' . $payout['id'])); ?>" class="button button-primary view-btn"><?php echo esc_html__('Просмотр', 'cashback-plugin'); ?></a>
                                                <?php endif; ?>
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
                'total_pages' => (int) ceil($total_payouts / $per_page),
                'page_slug'   => 'cashback-payouts',
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
     * Отображение детальной страницы заявки на выплату
     *
     * @param int $payout_id ID заявки
     * @return void
     */
    private function render_payout_detail_page(int $payout_id): void
    {
        global $wpdb;

        // Получаем данные заявки
        $payout = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, user_id, total_amount, payout_method, payout_account, masked_details,
                        encrypted_details, provider, provider_payout_id, attempts, fail_reason,
                        status, created_at, updated_at
                 FROM {$this->table_name}
                 WHERE id = %d",
                $payout_id
            ),
            ARRAY_A
        );

        if (!$payout) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' .
                esc_html__('Заявка на выплату не найдена.', 'cashback-plugin') .
                '</p></div></div>';
            return;
        }

        // Получаем данные пользователя
        $user = get_userdata((int) $payout['user_id']);
        $user_login = $user ? $user->user_login : __('Неизвестно', 'cashback-plugin');
        $user_email = $user ? $user->user_email : '';
        $user_display_name = $user ? $user->display_name : '';

        // Получаем информацию о платежной системе и банке
        $payout_method_info = $this->get_payout_method_info_by_slug($payout['payout_method'] ?? '');
        $bank_info = $this->get_bank_info_by_code($payout['provider'] ?? '');

        // Маскированный номер счета
        $masked_account = $this->get_display_account($payout);

        // Есть ли зашифрованные данные
        $has_encrypted = !empty($payout['encrypted_details']) || !empty($payout['payout_account']);

        // Допустимые переходы статусов
        $allowed_transitions = [
            'waiting'     => ['processing', 'paid', 'failed', 'declined', 'needs_retry'],
            'processing'  => ['paid', 'failed', 'declined', 'needs_retry'],
            'needs_retry' => ['processing', 'paid', 'failed', 'declined'],
            'paid'        => [],
            'failed'      => [],
            'declined'    => [],
        ];
        $current_status = $payout['status'];
        $available_statuses = $allowed_transitions[$current_status] ?? [];

        // Аудит: просмотр детальной страницы
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'payout_detail_viewed',
                get_current_user_id(),
                'payout_request',
                $payout_id,
                ['target_user_id' => (int) $payout['user_id']]
            );
        }

        $back_url = admin_url('admin.php?page=cashback-payouts');
        ?>
        <div class="wrap payout-detail-wrap">
            <h1 class="wp-heading-inline">
                <?php echo esc_html(sprintf(__('Заявка на выплату #%d', 'cashback-plugin'), $payout_id)); ?>
            </h1>
            <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">&larr; <?php echo esc_html__('Назад к списку', 'cashback-plugin'); ?></a>
            <hr class="wp-header-end">

            <div id="payout-detail-notices"></div>

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">

                    <!-- Левая колонка: информация -->
                    <div id="post-body-content">

                        <!-- Информация о пользователе -->
                        <div class="postbox">
                            <h2 class="hndle"><span><?php echo esc_html__('Информация о пользователе', 'cashback-plugin'); ?></span></h2>
                            <div class="inside">
                                <table class="form-table payout-detail-table">
                                    <tr>
                                        <th><?php echo esc_html__('ID пользователя', 'cashback-plugin'); ?></th>
                                        <td>
                                            <span class="detail-value" data-copy-value="<?php echo esc_attr($payout['user_id']); ?>"><?php echo esc_html($payout['user_id']); ?></span>
                                            <button type="button" class="button button-small copy-btn" data-copy="<?php echo esc_attr($payout['user_id']); ?>" title="<?php echo esc_attr__('Скопировать', 'cashback-plugin'); ?>">&#128203;</button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php echo esc_html__('Логин', 'cashback-plugin'); ?></th>
                                        <td>
                                            <span class="detail-value"><?php echo esc_html($user_login); ?></span>
                                            <button type="button" class="button button-small copy-btn" data-copy="<?php echo esc_attr($user_login); ?>" title="<?php echo esc_attr__('Скопировать', 'cashback-plugin'); ?>">&#128203;</button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php echo esc_html__('Email', 'cashback-plugin'); ?></th>
                                        <td>
                                            <span class="detail-value"><?php echo esc_html($user_email); ?></span>
                                            <button type="button" class="button button-small copy-btn" data-copy="<?php echo esc_attr($user_email); ?>" title="<?php echo esc_attr__('Скопировать', 'cashback-plugin'); ?>">&#128203;</button>
                                        </td>
                                    </tr>
                                    <?php if (!empty($user_display_name) && $user_display_name !== $user_login): ?>
                                    <tr>
                                        <th><?php echo esc_html__('Отображаемое имя', 'cashback-plugin'); ?></th>
                                        <td>
                                            <span class="detail-value"><?php echo esc_html($user_display_name); ?></span>
                                            <button type="button" class="button button-small copy-btn" data-copy="<?php echo esc_attr($user_display_name); ?>" title="<?php echo esc_attr__('Скопировать', 'cashback-plugin'); ?>">&#128203;</button>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>

                        <!-- Данные заявки -->
                        <div class="postbox">
                            <h2 class="hndle"><span><?php echo esc_html__('Данные заявки', 'cashback-plugin'); ?></span></h2>
                            <div class="inside">
                                <table class="form-table payout-detail-table">
                                    <tr>
                                        <th><?php echo esc_html__('Сумма выплаты', 'cashback-plugin'); ?></th>
                                        <td>
                                            <strong class="detail-value payout-amount"><?php echo esc_html(number_format((float) $payout['total_amount'], 2, '.', ' ')); ?> &#8381;</strong>
                                            <button type="button" class="button button-small copy-btn" data-copy="<?php echo esc_attr($payout['total_amount']); ?>" title="<?php echo esc_attr__('Скопировать', 'cashback-plugin'); ?>">&#128203;</button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php echo esc_html__('Платежная система', 'cashback-plugin'); ?></th>
                                        <td>
                                            <span class="detail-value"><?php echo esc_html($payout_method_info['name']); ?></span>
                                            <?php if (!$payout_method_info['is_active']): ?>
                                                <span class="cashback-inactive-badge"><?php echo esc_html__('(неактивна)', 'cashback-plugin'); ?></span>
                                            <?php endif; ?>
                                            <button type="button" class="button button-small copy-btn" data-copy="<?php echo esc_attr($payout_method_info['name']); ?>" title="<?php echo esc_attr__('Скопировать', 'cashback-plugin'); ?>">&#128203;</button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php echo esc_html__('Банк', 'cashback-plugin'); ?></th>
                                        <td>
                                            <span class="detail-value"><?php echo esc_html($bank_info['name']); ?></span>
                                            <?php if (!$bank_info['is_active'] && !empty($bank_info['name'])): ?>
                                                <span class="cashback-inactive-badge"><?php echo esc_html__('(неактивен)', 'cashback-plugin'); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($bank_info['name'])): ?>
                                                <button type="button" class="button button-small copy-btn" data-copy="<?php echo esc_attr($bank_info['name']); ?>" title="<?php echo esc_attr__('Скопировать', 'cashback-plugin'); ?>">&#128203;</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php echo esc_html__('Номер счета / телефона', 'cashback-plugin'); ?></th>
                                        <td class="payout-account-detail-cell" data-payout-id="<?php echo esc_attr($payout_id); ?>">
                                            <span class="masked-account"><?php echo esc_html($masked_account); ?></span>
                                            <span class="decrypted-account" style="display:none;"></span>
                                            <span class="decrypted-account-copy-btn" style="display:none;">
                                                <button type="button" class="button button-small copy-btn" data-copy="" title="<?php echo esc_attr__('Скопировать', 'cashback-plugin'); ?>">&#128203;</button>
                                            </span>
                                            <?php if ($has_encrypted && $payout['status'] === 'processing'): ?>
                                                <button type="button" class="button button-small decrypt-detail-btn" title="<?php echo esc_attr__('Показать реквизиты', 'cashback-plugin'); ?>">&#128065;</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr class="full-name-row" style="display:none;">
                                        <th><?php echo esc_html__('ФИО получателя', 'cashback-plugin'); ?></th>
                                        <td>
                                            <span class="decrypted-full-name"></span>
                                            <button type="button" class="button button-small copy-btn full-name-copy-btn" data-copy="" title="<?php echo esc_attr__('Скопировать', 'cashback-plugin'); ?>" style="display:none;">&#128203;</button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php echo esc_html__('Текущий статус', 'cashback-plugin'); ?></th>
                                        <td>
                                            <span class="detail-status-label" title="<?php echo esc_attr($this->get_admin_status_description($current_status)); ?>">
                                                <strong><?php echo esc_html($this->get_admin_status_label($current_status)); ?></strong>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php echo esc_html__('Дата заявки', 'cashback-plugin'); ?></th>
                                        <td><?php echo esc_html(date('d.m.Y H:i', strtotime($payout['created_at']))); ?></td>
                                    </tr>
                                    <?php if (!empty($payout['updated_at'])): ?>
                                    <tr>
                                        <th><?php echo esc_html__('Дата обновления', 'cashback-plugin'); ?></th>
                                        <td><?php echo esc_html(date('d.m.Y H:i', strtotime($payout['updated_at']))); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Правая колонка: форма редактирования -->
                    <div id="postbox-container-1" class="postbox-container">
                        <div class="postbox">
                            <h2 class="hndle"><span><?php echo esc_html__('Управление заявкой', 'cashback-plugin'); ?></span></h2>
                            <div class="inside">
                                <div class="payout-detail-form">
                                    <?php if (!empty($available_statuses)): ?>
                                    <p>
                                        <label for="detail-status"><strong><?php echo esc_html__('Изменить статус', 'cashback-plugin'); ?></strong></label><br>
                                        <select id="detail-status" class="widefat">
                                            <option value="<?php echo esc_attr($current_status); ?>" selected>
                                                <?php echo esc_html($this->get_admin_status_label($current_status)); ?> (<?php echo esc_html__('текущий', 'cashback-plugin'); ?>)
                                            </option>
                                            <?php foreach ($available_statuses as $avail_status): ?>
                                                <option value="<?php echo esc_attr($avail_status); ?>">
                                                    <?php echo esc_html($this->get_admin_status_label($avail_status)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </p>
                                    <?php else: ?>
                                    <p>
                                        <label><strong><?php echo esc_html__('Статус', 'cashback-plugin'); ?></strong></label><br>
                                        <em><?php echo esc_html($this->get_admin_status_label($current_status)); ?> &mdash; <?php echo esc_html__('финальный статус, изменение невозможно', 'cashback-plugin'); ?></em>
                                    </p>
                                    <?php endif; ?>

                                    <p>
                                        <label for="detail-provider-payout-id"><strong><?php echo esc_html__('ID Транзакции', 'cashback-plugin'); ?></strong></label><br>
                                        <input type="text" id="detail-provider-payout-id" class="widefat" value="<?php echo esc_attr($payout['provider_payout_id'] ?? ''); ?>">
                                    </p>

                                    <p>
                                        <label for="detail-attempts"><strong><?php echo esc_html__('Количество попыток', 'cashback-plugin'); ?></strong></label><br>
                                        <input type="number" id="detail-attempts" class="widefat" min="0" value="<?php echo esc_attr($payout['attempts']); ?>">
                                    </p>

                                    <p>
                                        <label for="detail-fail-reason"><strong><?php echo esc_html__('Описание ошибки', 'cashback-plugin'); ?></strong></label><br>
                                        <textarea id="detail-fail-reason" class="widefat" rows="4"><?php echo esc_textarea($payout['fail_reason'] ?? ''); ?></textarea>
                                    </p>

                                    <p>
                                        <button type="button" id="save-detail-btn" class="button button-primary button-large widefat"
                                                data-payout-id="<?php echo esc_attr($payout_id); ?>"
                                                data-original-status="<?php echo esc_attr($current_status); ?>"
                                                data-original-provider-payout-id="<?php echo esc_attr($payout['provider_payout_id'] ?? ''); ?>"
                                                data-original-attempts="<?php echo esc_attr($payout['attempts']); ?>"
                                                data-original-fail-reason="<?php echo esc_attr($payout['fail_reason'] ?? ''); ?>">
                                            <?php echo esc_html__('Сохранить изменения', 'cashback-plugin'); ?>
                                        </button>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
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
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'update_payout_request_nonce')) {
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
        $in_transaction = false;

        // Проверяем и добавляем только измененные поля
        // Поле provider (банк) НЕ редактируется администратором вручную
        // Оно обновляется автоматически только для статуса 'waiting' при изменении настроек пользователя

        if (isset($_POST['provider_payout_id'])) {
            $provider_payout_id = sanitize_text_field(wp_unslash($_POST['provider_payout_id']));
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
            $fail_reason = sanitize_text_field(wp_unslash($_POST['fail_reason']));
            $update_data['fail_reason'] = $fail_reason;
            $update_formats[] = '%s';
        }

        if (isset($_POST['status'])) {
            $status = sanitize_text_field(wp_unslash($_POST['status']));

            // Проверяем, что статус допустим
            $allowed_statuses = ['waiting', 'processing', 'paid', 'failed', 'declined', 'needs_retry'];
            if (!in_array($status, $allowed_statuses, true)) {
                wp_send_json_error(['message' => __('Недопустимый статус выплаты.', 'cashback-plugin')]);
                return;
            }

            // 🔒 НАЧИНАЕМ ТРАНЗАКЦИЮ ДО чтения статуса для предотвращения TOCTOU
            $wpdb->query('START TRANSACTION');
            $in_transaction = true;

            try {
                // БЛОКИРУЕМ строку выплаты с FOR UPDATE
                $payout_request = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, status, user_id, total_amount
                     FROM {$this->table_name}
                     WHERE id = %d
                     FOR UPDATE",
                    $payout_id
                ), ARRAY_A);

                if (!$payout_request) {
                    throw new Exception(__('Запрос выплаты не найден.', 'cashback-plugin'));
                }

                $old_status = $payout_request['status'];

                // Валидация допустимых переходов статусов
                $allowed_transitions = [
                    'waiting'     => ['processing', 'paid', 'failed', 'declined', 'needs_retry'],
                    'processing'  => ['paid', 'failed', 'declined', 'needs_retry'],
                    'needs_retry' => ['processing', 'paid', 'failed', 'declined'],
                    'paid'        => [],
                    'failed'      => [],
                    'declined'    => [],
                ];

                if ($old_status === $status) {
                    // Статус не изменился — пропускаем проверку перехода
                } elseif (!isset($allowed_transitions[$old_status]) || !in_array($status, $allowed_transitions[$old_status], true)) {
                    throw new Exception(sprintf(
                        __('Недопустимый переход статуса: %s → %s.', 'cashback-plugin'),
                        esc_html($old_status),
                        esc_html($status)
                    ));
                }

                $update_data['status'] = $status;
                $update_formats[] = '%s';

                // Определяем, нужно ли обновление баланса (используем ЗАБЛОКИРОВАННЫЙ статус)
                $needs_balance_update = ($old_status !== 'paid' && $status === 'paid')
                    || ($old_status !== 'declined' && $status === 'declined')
                    || ($old_status !== 'failed' && $status === 'failed');

                if ($needs_balance_update) {
                    $balance_result = false;
                    if ($old_status !== 'paid' && $status === 'paid') {
                        $balance_result = $this->update_user_balance_on_payout($payout_id, true);
                    } elseif ($old_status !== 'declined' && $status === 'declined') {
                        $balance_result = $this->update_user_balance_on_declined($payout_id, true);
                    } elseif ($old_status !== 'failed' && $status === 'failed') {
                        $balance_result = $this->update_user_balance_on_failed($payout_id, true);
                    }

                    if (!$balance_result) {
                        throw new Exception(__('Ошибка при обновлении баланса.', 'cashback-plugin'));
                    }
                }

                // Добавляем дату обновления (ВНУТРИ транзакции для атомарности)
                $update_data['updated_at'] = current_time('mysql');
                $update_formats[] = '%s';

                // Обновляем запись (ВНУТРИ try/catch для гарантии ROLLBACK)
                $result = $wpdb->update(
                    $this->table_name,
                    $update_data,
                    ['id' => $payout_id],
                    $update_formats,
                    ['%d']
                );

                if ($result === false) {
                    throw new Exception(__('Ошибка при обновлении запроса выплаты в базе данных.', 'cashback-plugin'));
                }

                $wpdb->query('COMMIT');
                $in_transaction = false;

            } catch (\Throwable $e) {
                if ($in_transaction) {
                    $wpdb->query('ROLLBACK');
                }
                error_log('[Cashback Payouts] Error updating payout #' . $payout_id . ': ' . $e->getMessage());
                wp_send_json_error(['message' => __('Ошибка при обновлении запроса выплаты.', 'cashback-plugin')]);
                return;
            }
        } else {
            // Обновление метаданных (без смены статуса) — оборачиваем в транзакцию
            // чтобы предотвратить чтение устаревших данных при параллельных запросах
            $wpdb->query('START TRANSACTION');

            try {
                // Блокируем строку для консистентного обновления
                $payout_check = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$this->table_name} WHERE id = %d FOR UPDATE",
                    $payout_id
                ), ARRAY_A);

                if (!$payout_check) {
                    throw new Exception(__('Запрос выплаты не найден.', 'cashback-plugin'));
                }

                $update_data['updated_at'] = current_time('mysql');
                $update_formats[] = '%s';

                $result = $wpdb->update(
                    $this->table_name,
                    $update_data,
                    ['id' => $payout_id],
                    $update_formats,
                    ['%d']
                );

                if ($result === false) {
                    throw new Exception(__('Ошибка при обновлении запроса выплаты в базе данных.', 'cashback-plugin'));
                }

                $wpdb->query('COMMIT');
            } catch (\Throwable $e) {
                $wpdb->query('ROLLBACK');
                error_log('[Cashback Payouts] Error updating payout metadata #' . $payout_id . ': ' . $e->getMessage());
                wp_send_json_error(['message' => __('Ошибка при обновлении запроса выплаты.', 'cashback-plugin')]);
                return;
            }
        }

        // Аудит-лог: записываем все изменения
        if (class_exists('Cashback_Encryption')) {
            $audit_details = ['changed_fields' => array_values(array_diff(array_keys($update_data), ['updated_at']))];
            if (isset($update_data['status'])) {
                $audit_details['old_status'] = $old_status ?? null;
                $audit_details['new_status'] = $update_data['status'];
            }
            if (isset($update_data['provider_payout_id'])) {
                $audit_details['provider_payout_id'] = $update_data['provider_payout_id'];
            }
            if (isset($update_data['attempts'])) {
                $audit_details['attempts'] = $update_data['attempts'];
            }
            Cashback_Encryption::write_audit_log(
                'payout_request_updated',
                get_current_user_id(),
                'payout_request',
                $payout_id,
                $audit_details
            );
        }

        // Получаем обновленные данные из базы
        $updated_payout_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT provider, provider_payout_id, attempts, fail_reason, status, encrypted_details, payout_account
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

        // Добавляем информацию о наличии зашифрованных данных
        $updated_payout_data['has_encrypted_data'] = !empty($updated_payout_data['encrypted_details']) || !empty($updated_payout_data['payout_account']);

        // Возвращаем только обновленные данные выплаты
        wp_send_json_success([
            'payout_data' => $updated_payout_data
        ]);
    }

    /**
     * Обновление баланса пользователя при изменении статуса выплаты на "paid"
     * 
     * @param int $payout_id ID запроса на выплату
     * @return bool Результат операции
     */
    private function update_user_balance_on_payout(int $payout_id, bool $in_transaction = false): bool
    {
        global $wpdb;

        if (!$in_transaction) {
            $wpdb->query('START TRANSACTION');
        }

        try {
            // Получаем информацию о запросе на выплату с блокировкой строки
            $payout_request = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT user_id, total_amount, status
                     FROM {$this->table_name}
                     WHERE id = %d
                     FOR UPDATE",
                    $payout_id
                ),
                ARRAY_A
            );

            if (!$payout_request) {
                throw new Exception("Не найден запрос на выплату с ID {$payout_id}");
            }

            // Защита от повторного выполнения: если статус уже 'paid', баланс уже был обновлён
            if ($payout_request['status'] === 'paid') {
                $this->log_error("Баланс для выплаты {$payout_id} уже был обновлён (статус: paid)");
                if (!$in_transaction) {
                    $wpdb->query('ROLLBACK');
                }
                return false;
            }

            $user_id = (int) $payout_request['user_id'];
            $amount = $payout_request['total_amount'];

            // Получаем текущий баланс пользователя с блокировкой строки
            $balance_table = $wpdb->prefix . 'cashback_user_balance';
            $current_balance = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT pending_balance, paid_balance, version FROM {$balance_table} WHERE user_id = %d FOR UPDATE",
                    $user_id
                ),
                ARRAY_A
            );

            if (!$current_balance) {
                throw new Exception("Не найден баланс для пользователя {$user_id}");
            }

            // Проверяем, достаточно ли средств в pending_balance (bcmath для точной арифметики)
            $pending_balance = $current_balance['pending_balance'];
            if (bccomp($pending_balance, $amount, 2) < 0) {
                throw new Exception("Недостаточно средств в pending_balance для пользователя {$user_id}. Требуется: {$amount}, доступно: {$pending_balance}");
            }

            // Обновляем баланс: вычитаем из pending_balance и добавляем к paid_balance
            $new_pending_balance = bcsub($pending_balance, $amount, 2);
            $new_paid_balance = bcadd($current_balance['paid_balance'], $amount, 2);
            $old_version = intval($current_balance['version']);

            // Используем оптимистичную блокировку через version
            $result = $wpdb->update(
                $balance_table,
                [
                    'pending_balance' => $new_pending_balance,
                    'paid_balance' => $new_paid_balance,
                    'version' => $old_version + 1
                ],
                [
                    'user_id' => $user_id,
                    'version' => $old_version
                ],
                ['%s', '%s', '%d'],
                ['%d', '%d']
            );

            if ($result === false || $result === 0) {
                throw new Exception("Ошибка обновления баланса пользователя {$user_id} или конфликт версий");
            }

            if (!$in_transaction) {
                $wpdb->query('COMMIT');
            }

            // Логируем изменение баланса
            $this->log_info("Баланс пользователя {$user_id} обновлен. Выплачено: {$amount}, pending_balance: {$new_pending_balance}, paid_balance: {$new_paid_balance}");

            return true;
        } catch (Exception $e) {
            if (!$in_transaction) {
                $wpdb->query('ROLLBACK');
            }
            $this->log_error($e->getMessage());
            return false;
        }
    }

    /**
     * Обновление баланса пользователя при изменении статуса выплаты на "declined"
     *
     * @param int $payout_id ID запроса на выплату
     * @return bool Результат операции
     */
    private function update_user_balance_on_declined(int $payout_id, bool $in_transaction = false): bool
    {
        global $wpdb;

        if (!$in_transaction) {
            $wpdb->query('START TRANSACTION');
        }

        try {
            // Получаем информацию о запросе на выплату с блокировкой строки
            $payout_request = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT user_id, total_amount, status
                     FROM {$this->table_name}
                     WHERE id = %d
                     FOR UPDATE",
                    $payout_id
                ),
                ARRAY_A
            );

            if (!$payout_request) {
                throw new Exception("Не найден запрос на выплату с ID {$payout_id}");
            }

            // Защита от повторного выполнения: если статус уже 'declined', баланс уже был обновлён
            if ($payout_request['status'] === 'declined') {
                $this->log_error("Баланс для выплаты {$payout_id} уже был обновлён (статус: declined)");
                if (!$in_transaction) {
                    $wpdb->query('ROLLBACK');
                }
                return false;
            }

            $user_id = (int) $payout_request['user_id'];
            $amount = $payout_request['total_amount'];

            // Получаем текущий баланс пользователя с блокировкой строки
            $balance_table = $wpdb->prefix . 'cashback_user_balance';
            $current_balance = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT pending_balance, frozen_balance, version FROM {$balance_table} WHERE user_id = %d FOR UPDATE",
                    $user_id
                ),
                ARRAY_A
            );

            if (!$current_balance) {
                throw new Exception("Не найден баланс для пользователя {$user_id}");
            }

            // Проверяем, достаточно ли средств в pending_balance (bcmath для точной арифметики)
            $pending_balance = $current_balance['pending_balance'];
            if (bccomp($pending_balance, $amount, 2) < 0) {
                throw new Exception("Недостаточно средств в pending_balance для пользователя {$user_id}. Требуется: {$amount}, доступно: {$pending_balance}");
            }

            // Обновляем баланс: вычитаем из pending_balance и добавляем к frozen_balance
            $new_pending_balance = bcsub($pending_balance, $amount, 2);
            $new_frozen_balance = bcadd($current_balance['frozen_balance'], $amount, 2);
            $old_version = intval($current_balance['version']);

            // Используем оптимистичную блокировку через version
            $result = $wpdb->update(
                $balance_table,
                [
                    'pending_balance' => $new_pending_balance,
                    'frozen_balance' => $new_frozen_balance,
                    'version' => $old_version + 1
                ],
                [
                    'user_id' => $user_id,
                    'version' => $old_version
                ],
                ['%s', '%s', '%d'],
                ['%d', '%d']
            );

            if ($result === false || $result === 0) {
                throw new Exception("Ошибка обновления баланса пользователя {$user_id} или конфликт версий");
            }

            if (!$in_transaction) {
                $wpdb->query('COMMIT');
            }

            // Логируем изменение баланса
            $this->log_info("Баланс пользователя {$user_id} обновлен при отклонении выплаты. Сумма: {$amount}, pending_balance: {$new_pending_balance}, frozen_balance: {$new_frozen_balance}");

            return true;
        } catch (Exception $e) {
            if (!$in_transaction) {
                $wpdb->query('ROLLBACK');
            }
            $this->log_error($e->getMessage());
            return false;
        }
    }

    /**
     * Обновление баланса пользователя при изменении статуса выплаты на "failed"
     * Возвращает средства из pending_balance в available_balance
     *
     * @param int $payout_id ID запроса на выплату
     * @return bool Результат операции
     */
    private function update_user_balance_on_failed(int $payout_id, bool $in_transaction = false): bool
    {
        global $wpdb;

        if (!$in_transaction) {
            // Начинаем транзакцию СРАЗУ для предотвращения race condition
            $wpdb->query('START TRANSACTION');
        }

        try {
            // Получаем информацию о запросе на выплату с блокировкой строки (FOR UPDATE)
            // Это предотвращает дублирование возврата при параллельных запросах
            $payout_request = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT user_id, total_amount, status, refunded_at
                     FROM {$this->table_name}
                     WHERE id = %d
                     FOR UPDATE",
                    $payout_id
                ),
                ARRAY_A
            );

            if (!$payout_request) {
                throw new Exception("Не найден запрос на выплату с ID {$payout_id}");
            }

            // Проверка на повторный возврат средств ВНУТРИ транзакции (защита от race condition)
            if (!empty($payout_request['refunded_at'])) {
                $this->log_error("Средства для заявки {$payout_id} уже были возвращены ранее: " . $payout_request['refunded_at']);
                if (!$in_transaction) {
                    $wpdb->query('ROLLBACK');
                }
                return false;
            }

            $user_id = (int) $payout_request['user_id'];
            $amount = $payout_request['total_amount'];

            // Получаем текущий баланс пользователя с блокировкой строки
            $balance_table = $wpdb->prefix . 'cashback_user_balance';
            $current_balance = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT pending_balance, available_balance, version
                     FROM {$balance_table}
                     WHERE user_id = %d
                     FOR UPDATE",
                    $user_id
                ),
                ARRAY_A
            );

            if (!$current_balance) {
                throw new Exception("Не найден баланс для пользователя {$user_id}");
            }

            // Проверяем, достаточно ли средств в pending_balance (bcmath для точной арифметики)
            $pending_balance = $current_balance['pending_balance'];
            if (bccomp($pending_balance, $amount, 2) < 0) {
                throw new Exception("Недостаточно средств в pending_balance для пользователя {$user_id}. Требуется: {$amount}, доступно: {$pending_balance}");
            }

            // Обновляем баланс: вычитаем из pending_balance и добавляем к available_balance
            $new_pending_balance = bcsub($pending_balance, $amount, 2);
            $new_available_balance = bcadd($current_balance['available_balance'], $amount, 2);
            $old_version = intval($current_balance['version']);

            // Используем оптимистичную блокировку через version
            $result = $wpdb->update(
                $balance_table,
                [
                    'pending_balance' => $new_pending_balance,
                    'available_balance' => $new_available_balance,
                    'version' => $old_version + 1
                ],
                [
                    'user_id' => $user_id,
                    'version' => $old_version
                ],
                ['%s', '%s', '%d'],
                ['%d', '%d']
            );

            if ($result === false || $result === 0) {
                throw new Exception("Ошибка обновления баланса пользователя {$user_id} или конфликт версий");
            }

            // Обновляем запись заявки, устанавливаем refunded_at для предотвращения повторного возврата
            $refund_time = current_time('mysql');
            $update_result = $wpdb->update(
                $this->table_name,
                [
                    'refunded_at' => $refund_time,
                    'updated_at' => $refund_time
                ],
                ['id' => $payout_id],
                ['%s', '%s'],
                ['%d']
            );

            if ($update_result === false) {
                throw new Exception("Ошибка обновления поля refunded_at для заявки {$payout_id}");
            }

            if (!$in_transaction) {
                $wpdb->query('COMMIT');
            }

            // Логируем успешное изменение баланса
            $this->log_info("Баланс пользователя {$user_id} обновлен при failed-статусе. Возвращено: {$amount}, pending_balance: {$new_pending_balance}, available_balance: {$new_available_balance}, refunded_at: {$refund_time}");

            return true;
        } catch (Exception $e) {
            if (!$in_transaction) {
                $wpdb->query('ROLLBACK');
            }
            $this->log_error($e->getMessage());
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
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'get_payout_request_nonce')) {
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
                "SELECT provider, provider_payout_id, attempts, fail_reason, status, encrypted_details, payout_account
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

        // Добавляем информацию о наличии зашифрованных данных (до удаления шифротекста)
        $payout_data['has_encrypted_data'] = !empty($payout_data['encrypted_details']) || !empty($payout_data['payout_account']);

        // Убираем шифротекст из ответа — не нужно отправлять в браузер
        unset($payout_data['encrypted_details']);

        // Возвращаем данные
        wp_send_json_success($payout_data);
    }

    /**
     * Получает маскированный номер счёта для отображения в таблице.
     */
    private function get_display_account(array $payout): string
    {
        if (class_exists('Cashback_Encryption')) {
            return Cashback_Encryption::get_masked_account(
                $payout['masked_details'] ?? null,
                $payout['payout_account'] ?? null
            );
        }
        return $payout['payout_account'] ?? '';
    }

    /**
     * AJAX: Расшифровка реквизитов заявки на выплату
     *
     * Доступно только Администраторам и Менеджерам магазина.
     * Только для заявок в статусе 'processing'.
     * Логирует действие в аудит-лог.
     */
    public function handle_decrypt_payout_details(): void
    {
        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'decrypt_payout_details_nonce')) {
            wp_send_json_error(['message' => __('Неверный nonce.', 'cashback-plugin')]);
            return;
        }

        // Проверяем роль: Администратор или Менеджер магазина
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Недостаточно прав для выполнения этого действия.', 'cashback-plugin')]);
            return;
        }

        // Rate limiting: max 20 расшифровок в минуту для защиты от массового экспорта
        $rate_key = 'cb_decrypt_rate_' . get_current_user_id();
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 20) {
            wp_send_json_error(['message' => __('Слишком много запросов на расшифровку. Подождите минуту.', 'cashback-plugin')]);
            return;
        }
        set_transient($rate_key, $rate_count + 1, MINUTE_IN_SECONDS);

        global $wpdb;
        $payout_id = intval($_POST['payout_id'] ?? 0);

        if ($payout_id <= 0) {
            wp_send_json_error(['message' => __('Некорректный ID заявки.', 'cashback-plugin')]);
            return;
        }

        // Получаем заявку и проверяем статус
        $payout = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, user_id, status, encrypted_details, payout_account FROM {$this->table_name} WHERE id = %d",
                $payout_id
            ),
            ARRAY_A
        );

        if (!$payout) {
            wp_send_json_error(['message' => __('Заявка не найдена.', 'cashback-plugin')]);
            return;
        }

        if ($payout['status'] !== 'processing') {
            wp_send_json_error(['message' => __('Расшифровка доступна только для заявок в статусе "В обработке".', 'cashback-plugin')]);
            return;
        }

        // Если есть зашифрованные данные — расшифровываем
        if (!empty($payout['encrypted_details']) && class_exists('Cashback_Encryption') && Cashback_Encryption::is_configured()) {
            try {
                $decrypted = Cashback_Encryption::decrypt_details($payout['encrypted_details']);

                // Аудит-лог
                Cashback_Encryption::write_audit_log(
                    'payout_details_decrypted',
                    get_current_user_id(),
                    'payout_request',
                    $payout_id,
                    ['target_user_id' => (int) $payout['user_id']]
                );

                wp_send_json_success([
                    'account' => $decrypted['account'] ?? '',
                    'full_name' => $decrypted['full_name'] ?? '',
                    'bank' => $decrypted['bank'] ?? '',
                ]);
                return;
            } catch (\Exception $e) {
                $this->log_error('Decrypt failed for payout ' . $payout_id . ': ' . $e->getMessage());
            }
        }

        // Fallback: возвращаем plaintext payout_account (для записей до миграции)
        if (!empty($payout['payout_account'])) {
            // Аудит-лог
            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'payout_details_viewed_plaintext',
                    get_current_user_id(),
                    'payout_request',
                    $payout_id,
                    ['target_user_id' => (int) $payout['user_id']]
                );
            }

            wp_send_json_success([
                'account' => $payout['payout_account'],
                'full_name' => '',
                'bank' => '',
            ]);
            return;
        }

        wp_send_json_error(['message' => __('Реквизиты отсутствуют.', 'cashback-plugin')]);
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
            'failed' => __('Возврат в доступный баланс', 'cashback-plugin'),
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
            'failed' => __('Выплату невозможно осуществить по каким либо причинам и она возвращена в доступный баланс', 'cashback-plugin'),
            'declined' => __('Выплата заморожена из-за мошенничества', 'cashback-plugin'),
            'needs_retry' => __('Выплата не прошла, попробовать повторить выплату', 'cashback-plugin'),
        ];

        return $descriptions[$status] ?? $status;
    }

    // render_pagination() предоставляется через AdminPaginationTrait

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
        $info = $this->get_payout_method_info_by_slug($slug);
        return $info['name'];
    }

    /**
     * Получить информацию о платежной системе по slug (название и статус активности)
     *
     * @param string $slug Slug платежной системы
     * @return array{name: string, is_active: bool} Название и статус активности
     */
    private function get_payout_method_info_by_slug(string $slug): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name, is_active FROM {$table_name} WHERE slug = %s",
                $slug
            ),
            ARRAY_A
        );

        if ($row) {
            return [
                'name' => $row['name'],
                'is_active' => (int) $row['is_active'] === 1,
            ];
        }

        return [
            'name' => $slug,
            'is_active' => false,
        ];
    }

    /**
     * Получить название банка по коду
     *
     * @param string $bank_code Код банка
     * @return string Название банка
     */
    private function get_bank_name_by_code(string $bank_code): string
    {
        $info = $this->get_bank_info_by_code($bank_code);
        return $info['name'];
    }

    /**
     * Получить информацию о банке по коду (название и статус активности)
     *
     * @param string $bank_code Код банка
     * @return array{name: string, is_active: bool} Название и статус активности
     */
    private function get_bank_info_by_code(string $bank_code): array
    {
        global $wpdb;

        if (empty($bank_code)) {
            return [
                'name' => '',
                'is_active' => true,
            ];
        }

        $table_name = $wpdb->prefix . 'cashback_banks';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name, is_active FROM {$table_name} WHERE bank_code = %s",
                $bank_code
            ),
            ARRAY_A
        );

        if ($row) {
            return [
                'name' => $row['name'],
                'is_active' => (int) $row['is_active'] === 1,
            ];
        }

        return [
            'name' => $bank_code,
            'is_active' => false,
        ];
    }

    /**
     * Получить код банка по ID
     *
     * @param int $bank_id ID банка
     * @return string|null Код банка
     */
    private function get_bank_code_by_id(int $bank_id): ?string
    {
        global $wpdb;

        if ($bank_id <= 0) {
            return null;
        }

        $table_name = $wpdb->prefix . 'cashback_banks';
        $bank_code = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT bank_code FROM {$table_name} WHERE id = %d",
                $bank_id
            )
        );

        return $bank_code ?: null;
    }

    /**
     * Получить все активные банки с их кодами
     *
     * @return array Массив банков
     */
    private function get_all_banks(): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_banks';
        $banks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, bank_code, name FROM {$table_name} WHERE is_active = %d ORDER BY name ASC",
                1
            ),
            ARRAY_A
        );

        return $banks ?: [];
    }
}

// Инициализируем класс
$payouts_admin = new Cashback_Payouts_Admin();

<?php
/**
 * Affiliate Module — Admin Panel.
 *
 * Меню «Партнёрская программа» под cashback-overview.
 * 3 вкладки: Настройки, Начисления, Партнёры.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Affiliate_Admin
{
    use AdminPaginationTrait;

    const PER_PAGE = 20;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // AJAX handlers
        add_action('wp_ajax_affiliate_toggle_module', [$this, 'handle_toggle_module']);
        add_action('wp_ajax_affiliate_save_settings', [$this, 'handle_save_settings']);
        add_action('wp_ajax_affiliate_update_partner', [$this, 'handle_update_partner']);
        add_action('wp_ajax_affiliate_get_partner_details', [$this, 'handle_get_partner_details']);
    }

    public function add_admin_menu(): void
    {
        add_submenu_page(
            'cashback-overview',
            __('Партнёрская программа', 'cashback-plugin'),
            __('Партнёрская программа', 'cashback-plugin'),
            'manage_options',
            'cashback-affiliate',
            [$this, 'render_page']
        );
    }

    public function enqueue_admin_scripts(string $hook): void
    {
        $allowed_hooks = [
            'cashback-overview_page_cashback-affiliate',
            'toplevel_page_cashback-affiliate',
            'admin_page_cashback-affiliate',
        ];

        $is_page = in_array($hook, $allowed_hooks, true)
            || (isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-affiliate');

        if (!$is_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-affiliate',
            plugins_url('../assets/css/admin-affiliate.css', __FILE__),
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'cashback-admin-affiliate-js',
            plugins_url('../assets/js/admin-affiliate.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('cashback-admin-affiliate-js', 'cashbackAffiliateAdmin', [
            'ajaxurl'       => admin_url('admin-ajax.php'),
            'toggleNonce'   => wp_create_nonce('affiliate_toggle_module_nonce'),
            'settingsNonce' => wp_create_nonce('affiliate_save_settings_nonce'),
            'partnerNonce'  => wp_create_nonce('affiliate_update_partner_nonce'),
            'detailsNonce'  => wp_create_nonce('affiliate_get_partner_details_nonce'),
        ]);
    }

    /* ═══════════════════════════════════════
     *  РЕНДЕР СТРАНИЦЫ
     * ═══════════════════════════════════════ */

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }

        $enabled = Cashback_Affiliate_DB::is_module_enabled();

        echo '<div class="wrap cashback-affiliate-admin">';
        echo '<h1>' . esc_html__('Партнёрская программа', 'cashback-plugin') . '</h1>';

        // Toggle module
        echo '<div class="cashback-affiliate-toggle">';
        echo '<label class="cashback-toggle-switch">';
        echo '<input type="checkbox" id="affiliate-module-toggle" ' . checked($enabled, true, false) . '>';
        echo '<span class="cashback-toggle-slider"></span>';
        echo '</label>';
        echo '<span class="cashback-toggle-label">'
            . ($enabled ? esc_html__('Модуль включён', 'cashback-plugin') : esc_html__('Модуль выключен', 'cashback-plugin'))
            . '</span>';
        echo '</div>';

        if (!$enabled) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('Модуль партнёрской программы выключен. Включите его для работы.', 'cashback-plugin')
                . '</p></div>';
            echo '</div>';
            return;
        }

        // Tabs
        $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'settings';
        $tabs        = [
            'settings' => __('Настройки', 'cashback-plugin'),
            'accruals' => __('Начисления', 'cashback-plugin'),
            'partners' => __('Партнёры', 'cashback-plugin'),
        ];

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $url    = add_query_arg(['page' => 'cashback-affiliate', 'tab' => $slug], admin_url('admin.php'));
            $active = $slug === $current_tab ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . $active . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        echo '<div class="cashback-affiliate-tab-content">';
        switch ($current_tab) {
            case 'accruals':
                $this->render_accruals_tab();
                break;
            case 'partners':
                $this->render_partners_tab();
                break;
            default:
                $this->render_settings_tab();
        }
        echo '</div>';

        echo '</div>'; // wrap
    }

    /* ═══════════════════════════════════════
     *  ВКЛАДКА: НАСТРОЙКИ
     * ═══════════════════════════════════════ */

    private function render_settings_tab(): void
    {
        $global_rate = Cashback_Affiliate_DB::get_global_rate();
        $cookie_ttl  = Cashback_Affiliate_DB::get_cookie_ttl_days();
        $rules_url   = Cashback_Affiliate_DB::get_rules_page_url();

        echo '<form id="affiliate-settings-form" class="cashback-affiliate-form">';

        echo '<table class="form-table">';

        // Глобальная ставка
        echo '<tr>';
        echo '<th><label for="aff-global-rate">' . esc_html__('Глобальная ставка (%)', 'cashback-plugin') . '</label></th>';
        echo '<td><input type="number" id="aff-global-rate" name="global_rate" value="' . esc_attr($global_rate) . '" min="0" max="100" step="0.01" class="small-text">';
        echo '<p class="description">' . esc_html__('Процент от кешбэка, начисляемый рефереру.', 'cashback-plugin') . '</p></td>';
        echo '</tr>';

        // Срок cookie
        echo '<tr>';
        echo '<th><label for="aff-cookie-ttl">' . esc_html__('Срок cookie (дни)', 'cashback-plugin') . '</label></th>';
        echo '<td><input type="number" id="aff-cookie-ttl" name="cookie_ttl" value="' . esc_attr($cookie_ttl) . '" min="1" max="365" class="small-text">';
        echo '<p class="description">' . esc_html__('Сколько дней cookie реферальной ссылки будет активна.', 'cashback-plugin') . '</p></td>';
        echo '</tr>';

        // URL правил
        echo '<tr>';
        echo '<th><label for="aff-rules-url">' . esc_html__('Страница правил', 'cashback-plugin') . '</label></th>';
        echo '<td><input type="url" id="aff-rules-url" name="rules_url" value="' . esc_attr($rules_url) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('URL страницы с правилами партнёрской программы.', 'cashback-plugin') . '</p></td>';
        echo '</tr>';

        echo '</table>';

        echo '<p class="submit"><button type="submit" class="button button-primary" id="affiliate-save-settings">'
            . esc_html__('Сохранить настройки', 'cashback-plugin') . '</button></p>';
        echo '</form>';
    }

    /* ═══════════════════════════════════════
     *  ВКЛАДКА: НАЧИСЛЕНИЯ
     * ═══════════════════════════════════════ */

    private function render_accruals_tab(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $current_page = max(1, absint($_GET['paged'] ?? 1));
        $per_page     = self::PER_PAGE;

        // Фильтры
        $filter_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $filter_search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $where_clauses = [];
        $where_args    = [];

        if ($filter_status && in_array($filter_status, ['available', 'frozen', 'paid'], true)) {
            $where_clauses[] = 'a.status = %s';
            $where_args[]    = $filter_status;
        }

        if ($filter_search) {
            $where_clauses[] = '(a.reference_id LIKE %s OR u1.display_name LIKE %s OR u2.display_name LIKE %s)';
            $like            = '%' . $wpdb->esc_like($filter_search) . '%';
            $where_args[]    = $like;
            $where_args[]    = $like;
            $where_args[]    = $like;
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Count
        $count_sql = "SELECT COUNT(*) FROM `{$prefix}cashback_affiliate_accruals` a
                      LEFT JOIN `{$wpdb->users}` u1 ON u1.ID = a.referrer_id
                      LEFT JOIN `{$wpdb->users}` u2 ON u2.ID = a.referred_user_id
                      {$where_sql}";

        if (!empty($where_args)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$where_args));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var($count_sql);
        }

        $total_pages  = max(1, (int) ceil($total / $per_page));
        $current_page = min($current_page, $total_pages);
        $offset       = ($current_page - 1) * $per_page;

        // Data
        $data_sql = "SELECT a.*, u1.display_name AS referrer_name, u2.display_name AS referred_name
                     FROM `{$prefix}cashback_affiliate_accruals` a
                     LEFT JOIN `{$wpdb->users}` u1 ON u1.ID = a.referrer_id
                     LEFT JOIN `{$wpdb->users}` u2 ON u2.ID = a.referred_user_id
                     {$where_sql}
                     ORDER BY a.created_at DESC
                     LIMIT %d OFFSET %d";

        $all_args = array_merge($where_args, [$per_page, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $accruals = $wpdb->get_results($wpdb->prepare($data_sql, ...$all_args), ARRAY_A);

        // Filters UI
        echo '<div class="tablenav top">';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="cashback-affiliate">';
        echo '<input type="hidden" name="tab" value="accruals">';

        echo '<select name="status">';
        echo '<option value="">' . esc_html__('Все статусы', 'cashback-plugin') . '</option>';
        foreach (['available' => 'Доступно', 'frozen' => 'Заморожено', 'paid' => 'Выплачено'] as $val => $lbl) {
            echo '<option value="' . esc_attr($val) . '"' . selected($filter_status, $val, false) . '>' . esc_html($lbl) . '</option>';
        }
        echo '</select>';

        echo '<input type="search" name="s" value="' . esc_attr($filter_search) . '" placeholder="' . esc_attr__('Поиск...', 'cashback-plugin') . '">';
        echo '<button type="submit" class="button">' . esc_html__('Фильтр', 'cashback-plugin') . '</button>';
        echo '</form>';
        echo '</div>';

        // Table
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Реферер', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Реферал', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Кешбэк', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Ставка', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Комиссия', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Статус', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Дата', 'cashback-plugin') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($accruals)) {
            echo '<tr><td colspan="8">' . esc_html__('Начислений нет.', 'cashback-plugin') . '</td></tr>';
        } else {
            $status_labels = [
                'available' => '<span class="aff-status aff-status-available">Доступно</span>',
                'frozen'    => '<span class="aff-status aff-status-frozen">Заморожено</span>',
                'paid'      => '<span class="aff-status aff-status-paid">Выплачено</span>',
            ];

            foreach ($accruals as $row) {
                echo '<tr>';
                echo '<td><code>' . esc_html($row['reference_id']) . '</code></td>';
                echo '<td>' . esc_html($row['referrer_name'] ?: '#' . $row['referrer_id']) . '</td>';
                echo '<td>' . esc_html($row['referred_name'] ?: '#' . $row['referred_user_id']) . '</td>';
                echo '<td>' . esc_html(number_format((float) $row['cashback_amount'], 2, '.', ' ')) . ' ₽</td>';
                echo '<td>' . esc_html($row['commission_rate']) . '%</td>';
                echo '<td><strong>' . esc_html(number_format((float) $row['commission_amount'], 2, '.', ' ')) . ' ₽</strong></td>';
                echo '<td>' . ($status_labels[$row['status']] ?? esc_html($row['status'])) . '</td>';
                echo '<td>' . esc_html(wp_date('d.m.Y H:i', strtotime($row['created_at']))) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        // Pagination
        $this->render_pagination([
            'total_items'  => $total,
            'per_page'     => $per_page,
            'current_page' => $current_page,
            'total_pages'  => $total_pages,
            'page_slug'    => 'cashback-affiliate',
            'add_args'     => [
                'tab'    => 'accruals',
                'status' => $filter_status,
                's'      => $filter_search,
            ],
        ]);
    }

    /* ═══════════════════════════════════════
     *  ВКЛАДКА: ПАРТНЁРЫ
     * ═══════════════════════════════════════ */

    private function render_partners_tab(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $current_page  = max(1, absint($_GET['paged'] ?? 1));
        $per_page      = self::PER_PAGE;
        $filter_status = isset($_GET['aff_status']) ? sanitize_text_field(wp_unslash($_GET['aff_status'])) : '';
        $filter_search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $where_clauses = [];
        $where_args    = [];

        if ($filter_status && in_array($filter_status, ['active', 'disabled'], true)) {
            $where_clauses[] = 'ap.affiliate_status = %s';
            $where_args[]    = $filter_status;
        }

        if ($filter_search) {
            $where_clauses[] = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
            $like            = '%' . $wpdb->esc_like($filter_search) . '%';
            $where_args[]    = $like;
            $where_args[]    = $like;
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $count_sql = "SELECT COUNT(*)
                      FROM `{$prefix}cashback_affiliate_profiles` ap
                      INNER JOIN `{$wpdb->users}` u ON u.ID = ap.user_id
                      {$where_sql}";

        if (!empty($where_args)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$where_args));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var($count_sql);
        }

        $total_pages  = max(1, (int) ceil($total / $per_page));
        $current_page = min($current_page, $total_pages);
        $offset       = ($current_page - 1) * $per_page;

        $data_sql = "SELECT ap.*, u.display_name, u.user_email,
                        (SELECT COUNT(*) FROM `{$prefix}cashback_affiliate_profiles` r
                         WHERE r.referred_by_user_id = ap.user_id) AS referral_count,
                        (SELECT COALESCE(SUM(commission_amount), 0)
                         FROM `{$prefix}cashback_affiliate_accruals`
                         WHERE referrer_id = ap.user_id) AS total_earned
                     FROM `{$prefix}cashback_affiliate_profiles` ap
                     INNER JOIN `{$wpdb->users}` u ON u.ID = ap.user_id
                     {$where_sql}
                     ORDER BY ap.created_at DESC
                     LIMIT %d OFFSET %d";

        $all_args = array_merge($where_args, [$per_page, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $partners = $wpdb->get_results($wpdb->prepare($data_sql, ...$all_args), ARRAY_A);

        $global_rate = Cashback_Affiliate_DB::get_global_rate();

        // Filters
        echo '<div class="tablenav top">';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="cashback-affiliate">';
        echo '<input type="hidden" name="tab" value="partners">';

        echo '<select name="aff_status">';
        echo '<option value="">' . esc_html__('Все статусы', 'cashback-plugin') . '</option>';
        echo '<option value="active"' . selected($filter_status, 'active', false) . '>' . esc_html__('Активные', 'cashback-plugin') . '</option>';
        echo '<option value="disabled"' . selected($filter_status, 'disabled', false) . '>' . esc_html__('Отключённые', 'cashback-plugin') . '</option>';
        echo '</select>';

        echo '<input type="search" name="s" value="' . esc_attr($filter_search) . '" placeholder="' . esc_attr__('Поиск...', 'cashback-plugin') . '">';
        echo '<button type="submit" class="button">' . esc_html__('Фильтр', 'cashback-plugin') . '</button>';
        echo '</form>';
        echo '</div>';

        // Table
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Пользователь', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Email', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Ставка', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Рефералы', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Заработано', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Статус', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Действия', 'cashback-plugin') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($partners)) {
            echo '<tr><td colspan="7">' . esc_html__('Партнёров нет.', 'cashback-plugin') . '</td></tr>';
        } else {
            foreach ($partners as $row) {
                $rate_display = $row['affiliate_rate'] !== null
                    ? esc_html($row['affiliate_rate']) . '%'
                    : esc_html($global_rate) . '% <em>(' . esc_html__('глоб.', 'cashback-plugin') . ')</em>';

                $is_active = $row['affiliate_status'] === 'active';
                $status_html = $is_active
                    ? '<span class="aff-status aff-status-available">' . esc_html__('Активен', 'cashback-plugin') . '</span>'
                    : '<span class="aff-status aff-status-frozen">' . esc_html__('Отключён', 'cashback-plugin') . '</span>';

                echo '<tr data-user-id="' . esc_attr($row['user_id']) . '">';
                echo '<td>' . esc_html($row['display_name']) . ' <small>(#' . esc_html($row['user_id']) . ')</small></td>';
                echo '<td>' . esc_html($row['user_email']) . '</td>';
                echo '<td>' . $rate_display . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '<td>' . esc_html($row['referral_count']) . '</td>';
                echo '<td>' . esc_html(number_format((float) $row['total_earned'], 2, '.', ' ')) . ' ₽</td>';
                echo '<td>' . $status_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '<td>';

                // Кнопка изменения ставки
                echo '<button type="button" class="button button-small aff-edit-rate" data-user-id="' . esc_attr($row['user_id']) . '" data-rate="' . esc_attr($row['affiliate_rate'] ?? '') . '">'
                    . esc_html__('Ставка', 'cashback-plugin') . '</button> ';

                // Кнопка включения/отключения
                if ($is_active) {
                    echo '<button type="button" class="button button-small aff-disable-partner" data-user-id="' . esc_attr($row['user_id']) . '">'
                        . esc_html__('Отключить', 'cashback-plugin') . '</button>';
                } else {
                    echo '<button type="button" class="button button-small button-primary aff-enable-partner" data-user-id="' . esc_attr($row['user_id']) . '">'
                        . esc_html__('Подключить', 'cashback-plugin') . '</button>';
                }

                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        $this->render_pagination([
            'total_items'  => $total,
            'per_page'     => $per_page,
            'current_page' => $current_page,
            'total_pages'  => $total_pages,
            'page_slug'    => 'cashback-affiliate',
            'add_args'     => [
                'tab'        => 'partners',
                'aff_status' => $filter_status,
                's'          => $filter_search,
            ],
        ]);
    }

    /* ═══════════════════════════════════════
     *  AJAX HANDLERS
     * ═══════════════════════════════════════ */

    /**
     * Включение/выключение модуля.
     */
    public function handle_toggle_module(): void
    {
        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')),
            'affiliate_toggle_module_nonce'
        )) {
            wp_send_json_error(['message' => 'Неверный nonce.']);
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав.']);
            return;
        }

        $enabled = !empty($_POST['enabled']);
        Cashback_Affiliate_DB::set_module_enabled($enabled);

        wp_send_json_success([
            'message' => $enabled
                ? __('Модуль включён.', 'cashback-plugin')
                : __('Модуль выключен.', 'cashback-plugin'),
        ]);
    }

    /**
     * Сохранение настроек.
     */
    public function handle_save_settings(): void
    {
        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')),
            'affiliate_save_settings_nonce'
        )) {
            wp_send_json_error(['message' => 'Неверный nonce.']);
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав.']);
            return;
        }

        if (isset($_POST['global_rate'])) {
            Cashback_Affiliate_DB::set_global_rate(sanitize_text_field(wp_unslash($_POST['global_rate'])));
        }
        if (isset($_POST['cookie_ttl'])) {
            Cashback_Affiliate_DB::set_cookie_ttl_days((int) $_POST['cookie_ttl']);
        }
        if (isset($_POST['rules_url'])) {
            Cashback_Affiliate_DB::set_rules_page_url(sanitize_text_field(wp_unslash($_POST['rules_url'])));
        }

        wp_send_json_success(['message' => __('Настройки сохранены.', 'cashback-plugin')]);
    }

    /**
     * Обновление партнёра: ставка, включение/отключение.
     */
    public function handle_update_partner(): void
    {
        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')),
            'affiliate_update_partner_nonce'
        )) {
            wp_send_json_error(['message' => 'Неверный nonce.']);
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав.']);
            return;
        }

        $user_id = absint($_POST['user_id'] ?? 0);
        $action  = sanitize_text_field(wp_unslash($_POST['partner_action'] ?? ''));

        if ($user_id < 1) {
            wp_send_json_error(['message' => 'Неверный ID пользователя.']);
            return;
        }

        global $wpdb;

        switch ($action) {
            case 'set_rate':
                $rate = isset($_POST['rate']) && $_POST['rate'] !== ''
                    ? max(0, min(100, (float) $_POST['rate']))
                    : null;

                $wpdb->update(
                    $wpdb->prefix . 'cashback_affiliate_profiles',
                    ['affiliate_rate' => $rate !== null ? number_format($rate, 2, '.', '') : null],
                    ['user_id' => $user_id],
                    [$rate !== null ? '%s' : null],
                    ['%d']
                );

                // Если rate=null, нужен прямой запрос для SET NULL
                if ($rate === null) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE `{$wpdb->prefix}cashback_affiliate_profiles`
                         SET affiliate_rate = NULL
                         WHERE user_id = %d",
                        $user_id
                    ));
                }

                wp_send_json_success(['message' => __('Ставка обновлена.', 'cashback-plugin')]);
                break;

            case 'disable':
                $admin_id = get_current_user_id();
                $result   = Cashback_Affiliate_Service::freeze_affiliate_balance($user_id, $admin_id);

                if ($result) {
                    wp_send_json_success(['message' => __('Партнёр отключён, средства заморожены.', 'cashback-plugin')]);
                } else {
                    wp_send_json_error(['message' => __('Не удалось отключить партнёра.', 'cashback-plugin')]);
                }
                break;

            case 'enable':
                $admin_id = get_current_user_id();
                $result   = Cashback_Affiliate_Service::unfreeze_affiliate_balance($user_id, $admin_id);

                if ($result) {
                    wp_send_json_success(['message' => __('Партнёр подключён, средства разморожены.', 'cashback-plugin')]);
                } else {
                    wp_send_json_error(['message' => __('Не удалось подключить партнёра.', 'cashback-plugin')]);
                }
                break;

            default:
                wp_send_json_error(['message' => 'Неизвестное действие.']);
        }
    }

    /**
     * Получение деталей партнёра.
     */
    public function handle_get_partner_details(): void
    {
        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')),
            'affiliate_get_partner_details_nonce'
        )) {
            wp_send_json_error(['message' => 'Неверный nonce.']);
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав.']);
            return;
        }

        $user_id = absint($_POST['user_id'] ?? 0);
        if ($user_id < 1) {
            wp_send_json_error(['message' => 'Неверный ID.']);
            return;
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        $profile = $wpdb->get_row($wpdb->prepare(
            "SELECT ap.*, u.display_name, u.user_email
             FROM `{$prefix}cashback_affiliate_profiles` ap
             INNER JOIN `{$wpdb->users}` u ON u.ID = ap.user_id
             WHERE ap.user_id = %d LIMIT 1",
            $user_id
        ), ARRAY_A);

        if (!$profile) {
            wp_send_json_error(['message' => 'Профиль не найден.']);
            return;
        }

        $stats = Cashback_Affiliate_Service::get_referrer_stats($user_id);

        wp_send_json_success([
            'profile' => $profile,
            'stats'   => $stats,
        ]);
    }
}

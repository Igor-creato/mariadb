<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC Affiliate URL Params
 *
 * Управление партнерскими параметрами URL для внешних товаров WooCommerce.
 * Параметры берутся из таблицы cashback_affiliate_network_params
 * на основе выбранной партнерской сети для товара.
 *
 * @since 2.0.0
 */
class WC_Affiliate_URL_Params
{
    private const CACHE_GROUP = 'wc_affiliate_url_params';
    private const CACHE_EXPIRATION = 3600;
    private const LOGGER_SOURCE = 'wc-affiliate-url-params';

    /**
     * Rate limiting: 3 уровня за RATE_LIMIT_WINDOW_SECONDS (60 сек).
     *
     * <= SPAM_THRESHOLD:  Норма — redirect + лог (spam_click=0).
     * > SPAM_THRESHOLD и < BLOCK_THRESHOLD: Redirect + лог (spam_click=1). Кэшбэк только после ручной проверки.
     * >= BLOCK_THRESHOLD: Redirect НЕТ. Защита от DDoS и бана CPA.
     */
    private const RATE_LIMIT_SPAM_THRESHOLD = 5;
    private const RATE_LIMIT_BLOCK_THRESHOLD = 100;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * Конструктор класса.
     *
     * @since 2.0.0
     */
    public function __construct()
    {
        // Хуки для добавления полей в админке
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_custom_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_custom_fields']);

        // Админ-уведомления (ошибки валидации при сохранении)
        add_action('admin_notices', [$this, 'show_admin_notices']);

        // Хуки для модификации URL на фронтенде
        add_filter('woocommerce_product_add_to_cart_url', [$this, 'modify_external_url'], 10, 2);

        // Добавляем data-product-id к ссылкам внешних товаров для JavaScript
        add_filter('woocommerce_loop_add_to_cart_link', [$this, 'add_product_id_to_link'], 10, 2);

        // Модифицируем кнопку внешнего товара на странице товара
        add_action('woocommerce_external_add_to_cart', [$this, 'modify_single_product_button'], 5);
        // Удаляем стандартный вывод кнопки, чтобы избежать дублирования
        remove_action('woocommerce_external_add_to_cart', 'woocommerce_external_add_to_cart', 30);

        // Подключение JS и CSS
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Server-side redirect endpoint для логирования кликов
        add_action('template_redirect', [$this, 'handle_click_redirect']);
    }

    /**
     * Добавление полей в Product Data метабокс.
     *
     * Отображает выпадающий список партнерских сетей и таблицу параметров
     * только для внешних товаров WooCommerce.
     *
     * @since 2.0.0
     *
     * @return void
     */
    public function add_custom_fields(): void
    {
        global $post, $wpdb;

        $product = wc_get_product($post->ID);

        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
        $params_table = $wpdb->prefix . 'cashback_affiliate_network_params';

        // Получаем все сети
        $networks = $wpdb->get_results(
            "SELECT id, name, is_active FROM {$networks_table} ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        );

        // Текущая выбранная сеть
        $selected_network_id = (int) get_post_meta($post->ID, '_affiliate_network_id', true);

        // Проверяем активность выбранной сети
        $selected_network_active = true;
        if ($selected_network_id > 0) {
            foreach ($networks as $network) {
                if ((int) $network['id'] === $selected_network_id) {
                    $selected_network_active = (bool) $network['is_active'];
                    break;
                }
            }
        }

        // Параметры выбранной сети (для начальной отрисовки)
        $network_params = [];
        if ($selected_network_id > 0) {
            $network_params = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, param_name, param_type FROM {$params_table} WHERE network_id = %d ORDER BY id ASC",
                    $selected_network_id
                ),
                ARRAY_A
            ) ?: [];
        }

        echo '<div class="options_group show_if_external">';
        echo '<h4 style="padding: 10px 12px; margin: 0; border-bottom: 1px solid #ddd;">'
            . esc_html__('Партнерская сеть', 'wc-affiliate-url-params') . '</h4>';
        echo '<p class="description" style="padding: 5px 12px; color: #666; margin: 0;">'
            . wp_kses(
                __('В поле <b>Значение</b>: для подстановки ID пользователя введите <code>user</code>, для уникального идентификатора клика — <code>uuid</code>, иначе значение будет передано как есть.', 'wc-affiliate-url-params'),
                ['b' => [], 'code' => []]
            )
            . '</p>';

        // Выпадающий список сетей
        echo '<p class="form-field _affiliate_network_id_field" style="padding: 5px 12px;">';
        echo '<label for="_affiliate_network_id">' . esc_html__('Партнерская сеть', 'wc-affiliate-url-params') . '</label>';
        echo '<select id="_affiliate_network_id" name="_affiliate_network_id" class="select short">';
        echo '<option value="">' . esc_html__('Выберите сеть', 'wc-affiliate-url-params') . '</option>';

        foreach ($networks as $network) {
            $network_id = (int) $network['id'];
            $is_active = (bool) $network['is_active'];
            $is_selected = ($network_id === $selected_network_id);

            // Неактивные сети: показываем только если они уже выбраны у товара
            if (!$is_active && !$is_selected) {
                continue;
            }

            $label = esc_html($network['name']);
            if (!$is_active) {
                $label .= ' (' . esc_html__('неактивна', 'wc-affiliate-url-params') . ')';
            }

            printf(
                '<option value="%d"%s>%s</option>',
                $network_id,
                selected($selected_network_id, $network_id, false),
                $label
            );
        }

        echo '</select>';
        echo '</p>';

        // Предупреждение если сеть отключена
        if ($selected_network_id > 0 && !$selected_network_active) {
            echo '<div class="affiliate-network-warning" style="padding: 8px 12px; margin: 5px 12px; background: #fff3cd; border-left: 4px solid #d63638; color: #856404;">';
            echo esc_html__('Сеть отключена. Товар будет переведен в статус "На утверждении".', 'wc-affiliate-url-params');
            echo '</div>';
        }

        // Контейнер для параметров сети
        echo '<div id="affiliate-network-params-container">';
        if (!empty($network_params)) {
            $this->render_network_params_table($network_params);
        }
        echo '</div>';

        echo '</div>';

        // Индивидуальные параметры товара
        $product_params = get_post_meta($post->ID, '_affiliate_product_params', true);
        if (!is_array($product_params)) {
            $product_params = [];
        }

        echo '<div class="options_group show_if_external">';
        echo '<h4 style="padding: 10px 12px; margin: 0; border-bottom: 1px solid #ddd;">'
            . esc_html__('Индивидуальные параметры товара', 'wc-affiliate-url-params') . '</h4>';
        echo '<p class="description" style="padding: 5px 12px; color: #666; margin: 0;">'
            . esc_html__('Добавляются только к URL этого товара. При совпадении ключа с параметром сети — используется значение товара.', 'wc-affiliate-url-params')
            . '</p>';

        echo '<div id="affiliate-product-params-container" style="padding: 10px 12px;">';

        // Заголовки колонок (без рамки)
        echo '<div class="product-params-labels" style="display: flex; gap: 10px; margin-bottom: 6px;">';
        echo '<span style="flex: 1; font-weight: 600; font-size: 13px;">' . esc_html__('Параметр', 'wc-affiliate-url-params') . '</span>';
        echo '<span style="flex: 1; font-weight: 600; font-size: 13px;">' . esc_html__('Значение', 'wc-affiliate-url-params') . '</span>';
        echo '<span style="width: 36px;"></span>';
        echo '</div>';

        // Контейнер для строк параметров
        echo '<div id="affiliate-product-params-rows">';

        foreach ($product_params as $pp) {
            $key = isset($pp['key']) ? esc_attr($pp['key']) : '';
            $value = isset($pp['value']) ? esc_attr($pp['value']) : '';
            echo '<div class="product-param-row" style="display: flex; gap: 10px; margin-bottom: 6px; align-items: center;">';
            echo '<input type="text" name="affiliate_product_param_key[]" value="' . $key . '" class="regular-text" placeholder="param_key" pattern="[a-zA-Z0-9_\-]+" title="' . esc_attr__('Только латиница, цифры, _ и -', 'wc-affiliate-url-params') . '" style="flex:1;" />';
            echo '<input type="text" name="affiliate_product_param_value[]" value="' . $value . '" class="regular-text" placeholder="user / uuid / значение" style="flex:1;" />';
            echo '<button type="button" class="button button-small remove-product-param-btn" style="color:#a00; min-width:36px;" title="' . esc_attr__('Удалить', 'wc-affiliate-url-params') . '">&times;</button>';
            echo '</div>';
        }

        echo '</div>';

        echo '<p style="margin-top: 8px;">';
        echo '<button type="button" id="add-product-param-row" class="button button-small"'
            . (count($product_params) >= 5 ? ' disabled' : '') . '>'
            . esc_html__('+ Добавить параметр', 'wc-affiliate-url-params') . '</button>';
        echo '<span class="description" style="margin-left: 8px;">'
            . esc_html__('Макс. 5 параметров.', 'wc-affiliate-url-params')
            . '</span>';
        echo '</p>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Отрисовка таблицы параметров сети.
     *
     * @since 2.0.0
     *
     * @param array $params Массив параметров из cashback_affiliate_network_params.
     *
     * @return void
     */
    private function render_network_params_table(array $params): void
    {
        if (empty($params)) {
            echo '<p style="padding: 5px 12px; color: #666; font-style: italic;">'
                . esc_html__('У этой сети нет настроенных параметров.', 'wc-affiliate-url-params') . '</p>';
            return;
        }

        echo '<table class="affiliate-network-params-table widefat" style="margin: 10px 12px; width: calc(100% - 24px);">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Параметр', 'wc-affiliate-url-params') . '</th>';
        echo '<th>' . esc_html__('Значение', 'wc-affiliate-url-params') . '</th>';
        echo '<th style="width: 220px;">' . esc_html__('Действия', 'wc-affiliate-url-params') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($params as $param) {
            printf(
                '<tr data-param-id="%d">'
                . '<td class="param-cell" data-field="param_name">%s</td>'
                . '<td class="param-cell" data-field="param_type">%s</td>'
                . '<td>'
                . '<button type="button" class="button button-small affiliate-param-edit-btn">%s</button> '
                . '<button type="button" class="button button-small affiliate-param-save-btn" style="display:none;">%s</button> '
                . '<button type="button" class="button button-small affiliate-param-cancel-btn" style="display:none;">%s</button> '
                . '<button type="button" class="button button-small affiliate-param-delete-btn" style="color:#a00;">%s</button>'
                . '</td></tr>',
                (int) $param['id'],
                esc_html($param['param_name']),
                esc_html($param['param_type'] ?? ''),
                esc_html__('Редактировать', 'wc-affiliate-url-params'),
                esc_html__('Сохранить', 'wc-affiliate-url-params'),
                esc_html__('Отмена', 'wc-affiliate-url-params'),
                esc_html__('Удалить', 'wc-affiliate-url-params')
            );
        }

        echo '</tbody></table>';
    }

    /**
     * Сохранение выбранной партнерской сети для товара.
     *
     * @since 2.0.0
     *
     * @param int $post_id ID товара.
     *
     * @return void
     */
    public function save_custom_fields(int $post_id): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_product', $post_id)) {
            return;
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'update-post_' . $post_id)) {
            return;
        }

        $product = wc_get_product($post_id);

        if (!$product || $product->get_type() !== 'external') {
            return;
        }

        $network_id = isset($_POST['_affiliate_network_id'])
            ? absint($_POST['_affiliate_network_id'])
            : 0;

        // Валидация: сеть обязательна для внешних товаров
        if ($network_id === 0) {
            set_transient(
                'cashback_affiliate_network_error_' . get_current_user_id(),
                __('Выберите партнерскую сеть', 'wc-affiliate-url-params'),
                30
            );
            return;
        }

        // Сохраняем ID сети
        update_post_meta($post_id, '_affiliate_network_id', $network_id);

        // Очищаем кэш
        wp_cache_delete('affiliate_params_' . $post_id, self::CACHE_GROUP);

        // Индивидуальные параметры товара
        $param_keys = isset($_POST['affiliate_product_param_key']) && is_array($_POST['affiliate_product_param_key'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['affiliate_product_param_key']))
            : [];
        $param_values = isset($_POST['affiliate_product_param_value']) && is_array($_POST['affiliate_product_param_value'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['affiliate_product_param_value']))
            : [];

        $product_params = [];
        $max_product_params = 5;

        for ($i = 0; $i < min(count($param_keys), $max_product_params); $i++) {
            $key = trim($param_keys[$i]);
            $value = trim($param_values[$i]);

            if ($key === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $key)) {
                continue;
            }

            $product_params[] = [
                'key'   => $key,
                'value' => $value,
            ];
        }

        update_post_meta($post_id, '_affiliate_product_params', $product_params);

        // Проверяем активность выбранной сети
        global $wpdb;
        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
        $is_active = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$networks_table} WHERE id = %d",
            $network_id
        ));

        if ($is_active === 0) {
            // Переводим товар в статус "На утверждении"
            wp_update_post([
                'ID' => $post_id,
                'post_status' => 'pending',
            ]);

            set_transient(
                'cashback_affiliate_network_warning_' . get_current_user_id(),
                __('Сеть отключена. Товар переведен в статус "На утверждении".', 'wc-affiliate-url-params'),
                30
            );
        }

        // Удаляем старые мета-ключи (миграция со старого формата)
        for ($i = 1; $i <= 3; $i++) {
            delete_post_meta($post_id, "_affiliate_param_{$i}_key");
            delete_post_meta($post_id, "_affiliate_param_{$i}_value");
        }
    }

    /**
     * Отображение админ-уведомлений (ошибки валидации, предупреждения).
     *
     * @since 2.0.0
     *
     * @return void
     */
    public function show_admin_notices(): void
    {
        $user_id = get_current_user_id();

        // Ошибка: сеть не выбрана
        $error = get_transient('cashback_affiliate_network_error_' . $user_id);
        if ($error) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($error)
            );
            delete_transient('cashback_affiliate_network_error_' . $user_id);
        }

        // Предупреждение: сеть отключена
        $warning = get_transient('cashback_affiliate_network_warning_' . $user_id);
        if ($warning) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                esc_html($warning)
            );
            delete_transient('cashback_affiliate_network_warning_' . $user_id);
        }
    }

    /**
     * Модификация URL внешнего товара с добавлением партнерских параметров.
     *
     * Параметры берутся из cashback_affiliate_network_params для выбранной сети.
     * param_name становится ключом URL-параметра, param_type — значением.
     * Специальное значение "user" подставляет ID текущего пользователя.
     *
     * @since 2.0.0
     *
     * @param string     $url     Исходный URL товара.
     * @param WC_Product $product Объект товара WooCommerce.
     *
     * @return string Модифицированный URL с партнерскими параметрами.
     */
    public function modify_external_url(string $url, WC_Product $product): string
    {
        if ($product->get_type() !== 'external') {
            return $url;
        }

        $product_id = $product->get_id();

        // Проверяем кэш
        $cache_key = 'affiliate_params_' . $product_id;
        $cached_params = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false === $cached_params) {
            $cached_params = $this->get_affiliate_params($product_id);
            wp_cache_set($cache_key, $cached_params, self::CACHE_GROUP, self::CACHE_EXPIRATION);
        }

        if (empty($cached_params)) {
            return $url;
        }

        // URL ведёт на server-side redirect endpoint (query param — работает на любом сервере)
        return home_url('/?cashback_click=' . $product_id);
    }

    /**
     * Получение партнерских параметров из БД для товара.
     *
     * @since 2.0.0
     *
     * @param int $product_id ID товара.
     *
     * @return array Массив партнерских параметров [ i => ['key' => param_name, 'value' => param_type] ].
     */
    private function get_affiliate_params(int $product_id): array
    {
        global $wpdb;

        $network_id = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        if ($network_id <= 0) {
            return [];
        }

        // Проверяем активность сети
        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
        $is_active = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$networks_table} WHERE id = %d",
            $network_id
        ));

        if ($is_active === 0) {
            return [];
        }

        $params_table = $wpdb->prefix . 'cashback_affiliate_network_params';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT param_name, param_type FROM {$params_table} WHERE network_id = %d ORDER BY id ASC",
            $network_id
        ), ARRAY_A);

        $params = [];
        if (!empty($rows)) {
            foreach ($rows as $i => $row) {
                $params[$i] = [
                    'key'   => $row['param_name'],
                    'value' => $row['param_type'],
                ];
            }
        }

        // Мерж индивидуальных параметров товара
        $product_params = get_post_meta($product_id, '_affiliate_product_params', true);
        if (is_array($product_params) && !empty($product_params)) {
            $key_to_index = [];
            foreach ($params as $idx => $p) {
                $key_to_index[$p['key']] = $idx;
            }

            foreach ($product_params as $pp) {
                if (empty($pp['key'])) {
                    continue;
                }

                if (isset($key_to_index[$pp['key']])) {
                    // Переопределяем значение сетевого параметра
                    $params[$key_to_index[$pp['key']]]['value'] = $pp['value'];
                } else {
                    // Добавляем новый параметр
                    $params[] = [
                        'key'   => $pp['key'],
                        'value' => $pp['value'],
                    ];
                }
            }
        }

        return $params;
    }

    /**
     * Server-side redirect: генерация click_id, логирование, 302 redirect.
     *
     * Работает через query parameter ?cashback_click={product_id}.
     * Не зависит от rewrite rules — работает на Apache, Nginx, любом сервере.
     *
     * При любой ошибке пользователь всё равно получает redirect.
     * Лучше потерять лог клика, чем потерять пользователя.
     *
     * @since 4.0.0
     *
     * @return void
     */
    public function handle_click_redirect(): void
    {
        if (!isset($_GET['cashback_click'])) {
            return;
        }

        $product_id = absint($_GET['cashback_click']);
        if ($product_id <= 0) {
            return;
        }

        // Запрещаем кеширование (click_id уникален каждый раз)
        nocache_headers();

        try {
            $product = wc_get_product($product_id);
            if (!$product || $product->get_type() !== 'external') {
                wp_redirect(home_url(), 302);
                exit;
            }

            $fallback_url = $product->get_product_url();
            if (empty($fallback_url)) {
                wp_redirect(home_url(), 302);
                exit;
            }

            // Генерация click_id через random_bytes()
            $click_id = bin2hex(random_bytes(16));

            // Валидация: 32 hex символа
            if (!ctype_xdigit($click_id) || strlen($click_id) !== 32) {
                error_log('[wc-affiliate-url-params] click_id validation failed: ' . $click_id);
                wp_redirect($fallback_url, 302);
                exit;
            }

            // Контекст пользователя
            $user_id = get_current_user_id(); // 0 для гостей
            $session_id = $this->get_session_id();

            // Построение финального affiliate URL
            $affiliate_url = $this->build_final_affiliate_url($product_id, $user_id, $click_id);
            if (empty($affiliate_url)) {
                $affiliate_url = $fallback_url;
            }

            // CPA-сеть
            $cpa_network = $this->get_network_slug_for_product($product_id);

            // Метаданные запроса
            $ip_address = Cashback_Encryption::get_client_ip();
            $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
                : null;
            $referer = isset($_SERVER['HTTP_REFERER'])
                ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']))
                : null;

            // Rate Limiting: 3 уровня (normal / spam / blocked)
            $rate_status = $this->get_click_rate_status($ip_address, $product_id, $user_agent ?? '');

            // 100+ кликов/мин → блокировка (защита от DDoS и бана CPA)
            if ($rate_status === 'blocked') {
                status_header(429);
                nocache_headers();
                exit;
            }

            // Логирование клика в БД (ошибка НЕ блокирует редирект)
            $this->log_click_to_db([
                'click_id'      => $click_id,
                'user_id'       => $user_id,
                'session_id'    => $session_id,
                'product_id'    => $product_id,
                'cpa_network'   => $cpa_network,
                'affiliate_url' => $affiliate_url,
                'ip_address'    => $ip_address,
                'user_agent'    => $user_agent,
                'referer'       => $referer,
                'spam_click'    => $rate_status === 'spam' ? 1 : 0,
            ]);

            // 302 redirect (не 301 — URL уникален каждый раз из-за click_id)
            wp_redirect($affiliate_url, 302);
            exit;
        } catch (\Throwable $e) {
            // Лучше потерять лог клика, чем потерять пользователя
            error_log('[wc-affiliate-url-params] Redirect error: ' . $e->getMessage());

            try {
                $product = wc_get_product($product_id);
                $url = ($product && $product->get_type() === 'external')
                    ? $product->get_product_url()
                    : home_url();
            } catch (\Throwable $e2) {
                $url = home_url();
            }

            wp_redirect($url ?: home_url(), 302);
            exit;
        }
    }

    /**
     * Построение финального affiliate URL с подстановкой реальных значений параметров.
     *
     * @since 3.0.0
     *
     * @param int    $product_id ID товара WooCommerce.
     * @param int    $user_id    ID текущего пользователя (0 для гостей).
     * @param string $click_id   UUID v4, сгенерированный на сервере.
     *
     * @return string|null Полный affiliate URL или null если товар не найден.
     */
    private function build_final_affiliate_url(int $product_id, int $user_id, string $click_id): ?string
    {
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'external') {
            return null;
        }

        $base_url = $product->get_product_url();
        if (empty($base_url)) {
            return null;
        }

        $affiliate_params = $this->get_affiliate_params($product_id);
        if (empty($affiliate_params)) {
            return $base_url;
        }

        $params = [];
        foreach ($affiliate_params as $param) {
            if (empty($param['key']) || empty($param['value'])) {
                continue;
            }

            $param_type = strtolower(trim($param['value']));

            if ($param_type === 'user') {
                $params[$param['key']] = $user_id > 0 ? (string) $user_id : 'unregistered';
            } elseif ($param_type === 'uuid') {
                $params[$param['key']] = $click_id;
            } else {
                $params[$param['key']] = $param['value'];
            }
        }

        return add_query_arg($params, $base_url);
    }

    /**
     * Получение slug CPA-сети для товара.
     *
     * @since 3.0.0
     *
     * @param int $product_id ID товара.
     *
     * @return string|null Slug сети или null.
     */
    private function get_network_slug_for_product(int $product_id): ?string
    {
        global $wpdb;

        $network_id = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        if ($network_id <= 0) {
            return null;
        }

        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
        $slug = $wpdb->get_var($wpdb->prepare(
            "SELECT slug FROM {$networks_table} WHERE id = %d AND is_active = 1",
            $network_id
        ));

        return $slug ?: null;
    }

    /**
     * Получение идентификатора сессии для текущего посетителя.
     *
     * Для авторизованных пользователей возвращает null (достаточно user_id).
     * Для гостей используется только WooCommerce session.
     * PHP session_start() не используется — конфликтует с page cache и object cache.
     *
     * @since 3.0.0
     *
     * @return string|null Идентификатор WC-сессии или null.
     */
    private function get_session_id(): ?string
    {
        if (is_user_logged_in()) {
            return null;
        }

        if (function_exists('WC') && WC()->session) {
            $wc_session_id = WC()->session->get_customer_id();
            if (!empty($wc_session_id)) {
                return (string) $wc_session_id;
            }
        }

        return null;
    }

    /**
     * Трёхуровневый rate limit по IP + User Agent + product_id.
     *
     * Использует WordPress transients (wp_options без Redis, RAM с Redis).
     * Счётчик инкрементируется при каждом вызове (включая spam).
     *
     * @since 4.1.0
     *
     * @param string $ip_address IP адрес клиента.
     * @param int    $product_id ID товара.
     * @param string $user_agent User-Agent браузера.
     *
     * @return string 'normal' | 'spam' | 'blocked'
     */
    private function get_click_rate_status(string $ip_address, int $product_id, string $user_agent): string
    {
        $raw_key = $ip_address . '|' . $user_agent . '|' . $product_id;
        $hash = substr(md5($raw_key), 0, 12);
        $transient_key = 'cb_clk_' . $hash;

        $count = (int) get_transient($transient_key);
        $new_count = $count + 1;

        set_transient($transient_key, $new_count, self::RATE_LIMIT_WINDOW_SECONDS);

        if ($new_count >= self::RATE_LIMIT_BLOCK_THRESHOLD) {
            return 'blocked';
        }

        if ($new_count > self::RATE_LIMIT_SPAM_THRESHOLD) {
            return 'spam';
        }

        return 'normal';
    }

    /**
     * Запись клика в cashback_click_log с транзакцией.
     *
     * Ошибка записи логируется, но не блокирует редирект пользователя.
     * user_id = 0 для гостей (не NULL), чтобы использовать единый %d плейсхолдер.
     *
     * @since 3.0.0
     *
     * @param array $data Данные клика (user_id: int, 0 для гостей).
     *
     * @return bool true при успехе, false при ошибке.
     */
    private function log_click_to_db(array $data): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_click_log';

        // Время в UTC с микросекундами
        $created_at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        try {
            $wpdb->query('START TRANSACTION');

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a safe prefixed table name
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$table}` (click_id, user_id, session_id, product_id, cpa_network, affiliate_url, ip_address, user_agent, referer, spam_click, created_at)
                 VALUES (%s, %d, %s, %d, %s, %s, %s, %s, %s, %d, %s)",
                $data['click_id'],
                absint($data['user_id']),
                $data['session_id'],
                $data['product_id'],
                $data['cpa_network'],
                $data['affiliate_url'],
                $data['ip_address'],
                $data['user_agent'],
                $data['referer'],
                absint($data['spam_click'] ?? 0),
                $created_at
            ));

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                $logger = wc_get_logger();
                $logger->error(
                    sprintf('Ошибка записи клика для товара %d: %s', $data['product_id'], $wpdb->last_error),
                    ['source' => self::LOGGER_SOURCE]
                );
                return false;
            }

            $wpdb->query('COMMIT');
            return true;
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            $logger = wc_get_logger();
            $logger->error(
                sprintf('Исключение при записи клика: %s', $e->getMessage()),
                ['source' => self::LOGGER_SOURCE]
            );
            return false;
        }
    }

    /**
     * Добавление data-product-id к ссылкам внешних товаров.
     *
     * @since 1.0.0
     *
     * @param string     $link    HTML код ссылки.
     * @param WC_Product $product Объект товара WooCommerce.
     *
     * @return string Модифицированная ссылка с data-атрибутом.
     */
    public function add_product_id_to_link(string $link, WC_Product $product): string
    {
        if ($product->get_type() === 'external') {
            $base_url = $product->get_product_url();
            $link = str_replace(
                '<a ',
                '<a data-product-id="' . esc_attr((string) $product->get_id()) . '"'
                . ' data-product-url="' . esc_url($base_url) . '"'
                . ' target="_blank"'
                . ' rel="nofollow" ',
                $link
            );
        }
        return $link;
    }

    /**
     * Модификация кнопки внешнего товара на странице товара.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function modify_single_product_button(): void
    {
        global $product;

        if (!$product || $product->get_type() !== 'external') {
            return;
        }

        $base_url = $product->get_product_url();
        $product_url = $this->modify_external_url($base_url, $product);
        $button_text = $product->single_add_to_cart_text();

        echo '<p class="cart">';
        echo '<a href="' . esc_url($product_url) . '"'
            . ' class="single_add_to_cart_button button alt"'
            . ' data-product-id="' . esc_attr((string) $product->get_id()) . '"'
            . ' data-product-url="' . esc_url($base_url) . '"'
            . ' target="_blank"'
            . ' rel="nofollow">';
        echo esc_html($button_text);
        echo '</a>';
        echo '</p>';
    }

    /**
     * Подключение скриптов и стилей для фронтенда.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function enqueue_frontend_scripts(): void
    {
        if (!is_admin()) {
            wp_enqueue_script(
                'wc-affiliate-url-params',
                plugins_url('assets/js/frontend.js', __FILE__),
                ['jquery'],
                '4.0.0',
                true
            );

            wp_localize_script('wc-affiliate-url-params', 'wcAffiliateParams', [
                'isLoggedIn' => is_user_logged_in(),
                'warningMessage' => __(
                    'Вы не авторизованы, при переходе покупка не будет учтена сервисом. Продолжить?',
                    'wc-affiliate-url-params'
                ),
                'loginUrl' => add_query_arg('action', 'register', get_permalink(wc_get_page_id('myaccount'))),
            ]);

            wp_enqueue_style(
                'wc-affiliate-url-params',
                plugins_url('assets/css/frontend.css', __FILE__),
                [],
                '1.0.0'
            );
        }
    }

    /**
     * Подключение стилей и скриптов для админки.
     *
     * @since 2.0.0
     *
     * @param string $hook Текущая страница админки.
     *
     * @return void
     */
    public function enqueue_admin_scripts(string $hook): void
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        global $post;

        if (!$post || get_post_type($post->ID) !== 'product') {
            return;
        }

        wp_enqueue_style(
            'wc-affiliate-url-params-admin',
            plugins_url('assets/css/admin.css', __FILE__),
            [],
            '1.2.0'
        );

        wp_enqueue_script(
            'wc-affiliate-network-admin',
            plugins_url('assets/js/admin-affiliate-network.js', __FILE__),
            ['jquery'],
            '1.1.0',
            true
        );

        wp_localize_script('wc-affiliate-network-admin', 'wcAffiliateNetworkData', [
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'getParamsNonce'   => wp_create_nonce('get_network_params_nonce'),
            'updateParamNonce' => wp_create_nonce('update_network_param_nonce'),
            'deleteParamNonce' => wp_create_nonce('delete_network_param_nonce'),
        ]);
    }
}

// Объявление совместимости с HPOS
add_action('before_woocommerce_init', function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

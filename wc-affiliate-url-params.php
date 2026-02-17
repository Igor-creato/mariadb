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

        if (!$product || $product->get_type() !== 'external') {
            return;
        }

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

        $logger = wc_get_logger();
        $product_id = $product->get_id();

        $logger->debug(
            sprintf('Modifying URL for product %d, original URL: %s', $product_id, $url),
            ['source' => self::LOGGER_SOURCE]
        );

        // Проверяем кэш
        $cache_key = 'affiliate_params_' . $product_id;
        $cached_params = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false === $cached_params) {
            $cached_params = $this->get_affiliate_params($product_id);
            wp_cache_set($cache_key, $cached_params, self::CACHE_GROUP, self::CACHE_EXPIRATION);
        }

        if (empty($cached_params)) {
            $logger->debug('No params to add, returning original URL', ['source' => self::LOGGER_SOURCE]);
            return $url;
        }

        $params = [];

        foreach ($cached_params as $i => $param) {
            if (empty($param['key']) || empty($param['value'])) {
                continue;
            }

            $logger->debug(
                sprintf('Param %d: key=%s, value=%s', $i, $param['key'], $param['value']),
                ['source' => self::LOGGER_SOURCE]
            );

            $param_type = strtolower(trim($param['value']));

            if ($param_type === 'user') {
                // Подстановка ID пользователя
                if (is_user_logged_in()) {
                    $params[$param['key']] = get_current_user_id();
                    $logger->debug(
                        sprintf('User logged in, using user ID: %d', get_current_user_id()),
                        ['source' => self::LOGGER_SOURCE]
                    );
                } else {
                    $params[$param['key']] = 'USER_PLACEHOLDER_' . $i;
                    $logger->debug(
                        sprintf('User not logged in, using placeholder: USER_PLACEHOLDER_%d', $i),
                        ['source' => self::LOGGER_SOURCE]
                    );
                }
            } elseif ($param_type === 'uuid') {
                // UUID генерируется на клиенте при каждом клике
                $params[$param['key']] = 'UUID_PLACEHOLDER_' . $i;
                $logger->debug(
                    sprintf('UUID param, using placeholder: UUID_PLACEHOLDER_%d', $i),
                    ['source' => self::LOGGER_SOURCE]
                );
            } else {
                // Статическое значение — подставляется как есть
                $params[$param['key']] = $param['value'];
            }
        }

        // Добавляем параметры в URL (add_query_arg обрабатывает ? и & автоматически)
        $url = add_query_arg($params, $url);
        $logger->debug(sprintf('Final URL: %s', $url), ['source' => self::LOGGER_SOURCE]);

        return $url;
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

        if (empty($rows)) {
            return [];
        }

        $params = [];
        foreach ($rows as $i => $row) {
            $params[$i] = [
                'key'   => $row['param_name'],
                'value' => $row['param_type'],
            ];
        }

        return $params;
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
            $link = str_replace('<a ', '<a data-product-id="' . esc_attr((string) $product->get_id()) . '" target="_blank" ', $link);
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
        echo '<a href="' . esc_url($product_url) . '" class="single_add_to_cart_button button alt" data-product-id="' . esc_attr((string) $product->get_id()) . '" target="_blank">';
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
                '1.0.0',
                true
            );

            wp_localize_script('wc-affiliate-url-params', 'wcAffiliateParams', [
                'isLoggedIn' => is_user_logged_in(),
                'userId' => is_user_logged_in() ? get_current_user_id() : 0,
                'warningMessage' => __(
                    'Вы не авторизованы, при переходе покупка не будет учтена сервисом. Продолжить?',
                    'wc-affiliate-url-params'
                ),
                'loginUrl' => add_query_arg('action', 'register', get_permalink(wc_get_page_id('myaccount'))),
                'nonce' => wp_create_nonce('wc_affiliate_url_params')
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
            '1.1.0'
        );

        wp_enqueue_script(
            'wc-affiliate-network-admin',
            plugins_url('assets/js/admin-affiliate-network.js', __FILE__),
            ['jquery'],
            '1.0.0',
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

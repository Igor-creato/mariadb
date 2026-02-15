<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC Affiliate URL Params
 *
 * Управление партнерскими параметрами URL для внешних товаров WooCommerce.
 *
 * @since 1.0.0
 */
class WC_Affiliate_URL_Params
{
    private const PARAM_COUNT = 3;
    private const CACHE_GROUP = 'wc_affiliate_url_params';
    private const CACHE_EXPIRATION = 3600; // 1 час
    private const LOGGER_SOURCE = 'wc-affiliate-url-params';

    /**
     * Конструктор класса.
     *
     * Регистрирует все необходимые хуки WordPress и WooCommerce.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        // Хуки для добавления полей в админке
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_custom_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_custom_fields']);

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
     * Отображает поля для настройки партнерских параметров URL
     * только для внешних товаров WooCommerce.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function add_custom_fields(): void
    {
        global $post;

        $product = wc_get_product($post->ID);

        // Показываем только для внешних товаров
        if (!$product || $product->get_type() !== 'external') {
            return;
        }

        echo '<div class="options_group show_if_external">';
        echo '<h4 style="padding: 10px 12px; margin: 0; border-bottom: 1px solid #ddd;">' .
            esc_html__('Параметры URL партнерской ссылки', 'wc-affiliate-url-params') . '</h4>';

        for ($i = 1; $i <= self::PARAM_COUNT; $i++) {
            $param_key = get_post_meta($post->ID, "_affiliate_param_{$i}_key", true);
            $param_value = get_post_meta($post->ID, "_affiliate_param_{$i}_value", true);

            echo '<div class="affiliate-url-param-group" style="padding: 12px; border-bottom: 1px solid #f0f0f0;">';
            echo '<p style="margin: 0 8px;"><strong>' .
                sprintf(esc_html__('Параметр %d', 'wc-affiliate-url-params'), $i) . '</strong></p>';

            woocommerce_wp_text_input([
                'id' => "_affiliate_param_{$i}_key",
                'label' => __('Параметр', 'wc-affiliate-url-params'),
                'placeholder' => 'subid' . $i,
                'value' => $param_key,
                'desc_tip' => true,
                'description' => __('Имя параметра URL (например: subid1)', 'wc-affiliate-url-params'),
                'wrapper_class' => 'form-field-wide'
            ]);

            woocommerce_wp_text_input([
                'id' => "_affiliate_param_{$i}_value",
                'label' => __('Значение', 'wc-affiliate-url-params'),
                'placeholder' => 'user или Admitad',
                'value' => $param_value,
                'desc_tip' => true,
                'description' => __('Значение параметра. Используйте "user" для подстановки ID пользователя', 'wc-affiliate-url-params'),
                'wrapper_class' => 'form-field-wide'
            ]);

            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Сохранение данных полей партнерских параметров.
     *
     * Выполняет проверки безопасности (autosave, nonce, права доступа)
     * и сохраняет данные партнерских параметров в post meta.
     *
     * @since 1.0.0
     *
     * @param int $post_id ID товара.
     *
     * @return void
     */
    public function save_custom_fields(int $post_id): void
    {
        // Проверка autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Проверка прав пользователя
        if (!current_user_can('edit_product', $post_id)) {
            return;
        }

        // Проверка nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'update-post_' . $post_id)) {
            return;
        }

        $product = wc_get_product($post_id);

        if (!$product || $product->get_type() !== 'external') {
            return;
        }

        for ($i = 1; $i <= self::PARAM_COUNT; $i++) {
            $param_key = isset($_POST["_affiliate_param_{$i}_key"])
                ? sanitize_text_field(wp_unslash($_POST["_affiliate_param_{$i}_key"]))
                : '';

            $param_value = isset($_POST["_affiliate_param_{$i}_value"])
                ? sanitize_text_field(wp_unslash($_POST["_affiliate_param_{$i}_value"]))
                : '';

            update_post_meta($post_id, "_affiliate_param_{$i}_key", $param_key);
            update_post_meta($post_id, "_affiliate_param_{$i}_value", $param_value);

            // Очищаем кэш при сохранении
            wp_cache_delete('affiliate_params_' . $post_id, self::CACHE_GROUP);
        }
    }

    /**
     * Модификация URL внешнего товара с добавлением партнерских параметров.
     *
     * Добавляет настроенные партнерские параметры к URL внешнего товара.
     * Поддерживает динамическую подстановку ID пользователя через ключевое слово "user".
     * Использует кэширование для оптимизации производительности.
     *
     * @since 1.0.0
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
        $has_user_param = false;

        foreach ($cached_params as $i => $param) {
            if (empty($param['key']) || empty($param['value'])) {
                continue;
            }

            $logger->debug(
                sprintf('Param %d: key=%s, value=%s', $i, $param['key'], $param['value']),
                ['source' => self::LOGGER_SOURCE]
            );

            // Проверка на ключевое слово "user"
            if (strtolower(trim($param['value'])) === 'user') {
                $has_user_param = true;

                if (is_user_logged_in()) {
                    $params[$param['key']] = get_current_user_id();
                    $logger->debug(
                        sprintf('User logged in, using user ID: %d', get_current_user_id()),
                        ['source' => self::LOGGER_SOURCE]
                    );
                } else {
                    // Для неавторизованных пользователей добавим placeholder
                    $params[$param['key']] = 'USER_PLACEHOLDER_' . $i;
                    $logger->debug(
                        sprintf('User not logged in, using placeholder: USER_PLACEHOLDER_%d', $i),
                        ['source' => self::LOGGER_SOURCE]
                    );
                }
            } else {
                $params[$param['key']] = $param['value'];
            }
        }

        // Добавляем параметры в URL
        $url = add_query_arg($params, $url);
        $logger->debug(sprintf('Final URL: %s', $url), ['source' => self::LOGGER_SOURCE]);

        return $url;
    }

    /**
     * Получение партнерских параметров из метаданных товара.
     *
     * @since 1.0.0
     *
     * @param int $product_id ID товара.
     *
     * @return array Массив партнерских параметров.
     */
    private function get_affiliate_params(int $product_id): array
    {
        $params = [];

        for ($i = 1; $i <= self::PARAM_COUNT; $i++) {
            $param_key = get_post_meta($product_id, "_affiliate_param_{$i}_key", true);
            $param_value = get_post_meta($product_id, "_affiliate_param_{$i}_value", true);

            $params[$i] = [
                'key' => $param_key,
                'value' => $param_value,
            ];
        }

        return $params;
    }

    /**
     * Добавление data-product-id к ссылкам внешних товаров.
     *
     * Добавляет атрибут data-product-id для обработки JavaScript
     * и target="_blank" для открытия в новой вкладке.
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
     * Выводит кастомную кнопку с партнерскими параметрами
     * и data-атрибутом для JavaScript обработки.
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

        // Получаем базовый URL и применяем модификацию
        $base_url = $product->get_product_url();
        $product_url = $this->modify_external_url($base_url, $product);
        $button_text = $product->single_add_to_cart_text();

        // Выводим кнопку с data-product-id
        echo '<p class="cart">';
        echo '<a href="' . esc_url($product_url) . '" class="single_add_to_cart_button button alt" data-product-id="' . esc_attr((string) $product->get_id()) . '" target="_blank">';
        echo esc_html($button_text);
        echo '</a>';
        echo '</p>';
    }
    // Этот метод больше не нужен, так как мы используем data-атрибуты
    // public function modify_external_url_button_text(string $text, WC_Product $product): string ...

    /**
     * Подключение скриптов и стилей для фронтенда.
     *
     * Загружает JavaScript для обработки кликов по партнерским ссылкам
     * и CSS для модального окна авторизации.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function enqueue_frontend_scripts(): void
    {
        // Загружаем скрипты на всех страницах, где может быть WooCommerce контент
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
     * Подключение стилей для админки.
     *
     * Загружает CSS для стилизации полей партнерских параметров
     * в админ-панели редактирования товара.
     *
     * @since 1.0.0
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
            '1.0.0'
        );
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

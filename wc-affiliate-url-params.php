<?php

/**
 * Plugin Name: Cashback WooCommerce Affiliate URL
 * Description: Добавляет настраиваемые параметры URL для внешних партнерских товаров с поддержкой динамического ID пользователя
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.4
 * Author: Cashback
 * Text Domain: wc-affiliate-url-params
 * Domain Path: /languages
 * WC requires at least: 10.0
 * WC tested up to: 10.3.5
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Affiliate_URL_Params
{

    private const PARAM_COUNT = 3;

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
     * Добавление полей в Product Data метабокс
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
            echo '<p style="margin: 0 0 8px;"><strong>' .
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
     * Сохранение данных полей
     */
    public function save_custom_fields(int $post_id): void
    {
        $product = wc_get_product($post_id);

        if (!$product || $product->get_type() !== 'external') {
            return;
        }

        for ($i = 1; $i <= self::PARAM_COUNT; $i++) {
            $param_key = isset($_POST["_affiliate_param_{$i}_key"])
                ? sanitize_text_field($_POST["_affiliate_param_{$i}_key"])
                : '';

            $param_value = isset($_POST["_affiliate_param_{$i}_value"])
                ? sanitize_text_field($_POST["_affiliate_param_{$i}_value"])
                : '';

            update_post_meta($post_id, "_affiliate_param_{$i}_key", $param_key);
            update_post_meta($post_id, "_affiliate_param_{$i}_value", $param_value);
        }
    }

    /**
     * Модификация URL внешнего товара
     */
    public function modify_external_url(string $url, WC_Product $product): string
    {
        if ($product->get_type() !== 'external') {
            return $url;
        }

        error_log('WC Affiliate: Modifying URL for product ' . $product->get_id() . ', original URL: ' . $url);

        $params = [];
        $has_user_param = false;

        for ($i = 1; $i <= self::PARAM_COUNT; $i++) {
            $param_key = get_post_meta($product->get_id(), "_affiliate_param_{$i}_key", true);
            $param_value = get_post_meta($product->get_id(), "_affiliate_param_{$i}_value", true);

            if (empty($param_key) || empty($param_value)) {
                continue;
            }

            error_log('WC Affiliate: Param ' . $i . ': key=' . $param_key . ', value=' . $param_value);

            // Проверка на ключевое слово "user"
            if (strtolower(trim($param_value)) === 'user') {
                $has_user_param = true;

                if (is_user_logged_in()) {
                    $params[$param_key] = get_current_user_id();
                    error_log('WC Affiliate: User logged in, using user ID: ' . get_current_user_id());
                } else {
                    // Для неавторизованных пользователей добавим placeholder
                    $params[$param_key] = 'USER_PLACEHOLDER_' . $i;
                    error_log('WC Affiliate: User not logged in, using placeholder: USER_PLACEHOLDER_' . $i);
                }
            } else {
                $params[$param_key] = $param_value;
            }
        }

        if (empty($params)) {
            error_log('WC Affiliate: No params to add, returning original URL');
            return $url;
        }

        // Добавляем параметры в URL
        $url = add_query_arg($params, $url);
        error_log('WC Affiliate: Final URL: ' . $url);

        // Сохраняем информацию о необходимости проверки авторизации
        if ($has_user_param) {
            $product->update_meta_data('_requires_auth_warning', 'yes');
            error_log('WC Affiliate: Set _requires_auth_warning to yes');
        } else {
            $product->delete_meta_data('_requires_auth_warning');
            error_log('WC Affiliate: Removed _requires_auth_warning');
        }
        $product->save_meta_data();

        return $url;
    }

    /**
     * Добавляем data-product-id к ссылкам внешних товаров для JavaScript
     */
    public function add_product_id_to_link(string $link, WC_Product $product): string
    {
        if ($product->get_type() === 'external') {
            $link = str_replace('<a ', '<a data-product-id="' . $product->get_id() . '" target="_blank" ', $link);
        }
        return $link;
    }

    /**
     * Модифицируем кнопку внешнего товара на странице товара
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
        echo '<a href="' . esc_url($product_url) . '" class="single_add_to_cart_button button alt" data-product-id="' . $product->get_id() . '" target="_blank">';
        echo esc_html($button_text);
        echo '</a>';
        echo '</p>';
    }

    // Этот метод больше не нужен, так как мы используем data-атрибуты
    // public function modify_external_url_button_text(string $text, WC_Product $product): string ...

    /**
     * Подключение скриптов для фронтенда
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
                'loginUrl' => 'http://localhost/kash-back/?page_id=13&action=register',
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
     * Подключение скриптов для админки
     */
    public function enqueue_admin_scripts($hook): void
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

// Инициализация плагина
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        new WC_Affiliate_URL_Params();
    }
});

// Объявление совместимости с HPOS
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

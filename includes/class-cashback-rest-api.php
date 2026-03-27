<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API для браузерного расширения кэшбэк-сервиса.
 *
 * Регистрирует эндпоинты в namespace cashback/v1 для:
 * - Получения списка магазинов с кэшбэком
 * - Баланса и профиля пользователя
 * - Истории транзакций
 * - Активации кэшбэка (генерация redirect-ссылки)
 * - Проверки статуса активации
 *
 * @since 5.0.0
 */
class Cashback_REST_API
{
    private const NAMESPACE = 'cashback/v1';
    private const STORES_CACHE_KEY = 'cashback_ext_stores_cache';
    private const STORES_CACHE_TTL = 6 * HOUR_IN_SECONDS;
    private const ACTIVATION_WINDOW = 30 * MINUTE_IN_SECONDS;
    private const TRANSACTIONS_PER_PAGE = 10;

    // Rate limiting (аналогично WC_Affiliate_URL_Params)
    private const RATE_PER_PRODUCT_SPAM  = 3;
    private const RATE_PER_PRODUCT_BLOCK = 10;
    private const RATE_GLOBAL_SPAM       = 10;
    private const RATE_GLOBAL_BLOCK      = 60;
    private const RATE_LIMIT_WINDOW      = 60;

    private static ?self $instance = null;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('rest_authentication_errors', [$this, 'authenticate_extension_cookie'], 99);
        add_filter('rest_pre_dispatch', [$this, 'block_user_enumeration'], 10, 3);
        add_action('template_redirect', [$this, 'block_author_enumeration']);
        // Сброс кеша магазинов при сохранении или удалении товара
        add_action('save_post_product', [$this, 'flush_stores_cache']);
        add_action('delete_post', [$this, 'flush_stores_cache']);
        add_action('woocommerce_update_product', [$this, 'flush_stores_cache']);
    }

    /**
     * Сброс transient-кеша списка магазинов.
     */
    public function flush_stores_cache(): void
    {
        delete_transient(self::STORES_CACHE_KEY);
    }

    /**
     * Блокировка user enumeration через REST API для неаутентифицированных запросов.
     *
     * Закрывает /wp/v2/users и /wp/v2/users/<id> — возвращает 403
     * если у текущего пользователя нет capability `list_users`.
     *
     * @param mixed            $result  Response to replace the requested version with.
     * @param \WP_REST_Server  $server  Server instance.
     * @param \WP_REST_Request $request Request used to generate the response.
     * @return mixed|\WP_Error
     */
    public function block_user_enumeration($result, \WP_REST_Server $server, \WP_REST_Request $request)
    {
        if (null !== $result) {
            return $result;
        }

        $route = $request->get_route();

        if (preg_match('#^/wp/v2/users(?:/|$)#', $route) && !current_user_can('list_users')) {
            return new \WP_Error(
                'rest_user_cannot_view',
                'Доступ запрещён.',
                ['status' => 403]
            );
        }

        return $result;
    }

    /**
     * Блокировка author enumeration через /?author=N.
     *
     * WordPress редиректит /?author=1 на /author/username/, раскрывая логин.
     * Для неаутентифицированных — редирект на главную.
     */
    public function block_author_enumeration(): void
    {
        if (isset($_GET['author']) && !is_user_logged_in()) {
            wp_safe_redirect(home_url(), 301);
            exit;
        }
    }

    /**
     * Регистрация REST-маршрутов.
     */
    public function register_routes(): void
    {
        // Публичный: список магазинов с кэшбэком
        register_rest_route(self::NAMESPACE, '/stores', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_stores'],
            'permission_callback' => '__return_true',
        ]);

        // Профиль и баланс текущего пользователя
        register_rest_route(self::NAMESPACE, '/me', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_me'],
            'permission_callback' => [$this, 'check_user_logged_in'],
        ]);

        // Транзакции текущего пользователя
        register_rest_route(self::NAMESPACE, '/me/transactions', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_transactions'],
            'permission_callback' => [$this, 'check_user_logged_in'],
            'args'                => [
                'page' => [
                    'type'              => 'integer',
                    'default'           => 1,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'type'              => 'integer',
                    'default'           => self::TRANSACTIONS_PER_PAGE,
                    'minimum'           => 1,
                    'maximum'           => 50,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Активация кэшбэка (генерация redirect URL).
        // POST — т.к. эндпоинт создаёт записи в click_log (побочный эффект).
        // GET для state-changing операций уязвим к CSRF через <img>, prefetch и т.д.
        register_rest_route(self::NAMESPACE, '/activate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'activate_cashback'],
            'permission_callback' => [$this, 'check_user_logged_in'],
            'args'                => [
                'product_id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Статус активации для домена
        register_rest_route(self::NAMESPACE, '/session-status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_session_status'],
            'permission_callback' => [$this, 'check_user_logged_in'],
            'args'                => [
                'domain' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Аутентификация запросов браузерного расширения по WordPress cookie
     * без требования nonce.
     *
     * WordPress REST API требует nonce для cookie-аутентификации (CSRF-защита).
     * Браузерные расширения не подвержены CSRF — расширение контролирует все запросы,
     * внешние сайты не могут инициировать запросы от имени расширения.
     *
     * Для защиты от CSRF со стороны обычных сайтов, nonce обходится ТОЛЬКО если:
     * - Origin = chrome-extension:// или moz-extension:// (браузерное расширение)
     * - Origin отсутствует и запрос содержит заголовок X-Cashback-Extension: 1
     *   (service worker расширения, где Origin может не передаваться)
     *
     * Фильтр на приоритете 99 (до rest_cookie_check_errors на приоритете 100).
     * Возврат true вызывает short-circuit core-функции, предотвращая сброс пользователя.
     *
     * @param \WP_Error|null|true $result Результат аутентификации от предыдущих фильтров.
     * @return \WP_Error|null|true
     */
    public function authenticate_extension_cookie($result)
    {
        if (null !== $result) {
            return $result;
        }

        if (!$this->is_cashback_rest_request()) {
            return $result;
        }

        if (!$this->is_extension_origin()) {
            return $result;
        }

        $user_id = wp_validate_auth_cookie('', 'logged_in');

        if (empty($user_id)) {
            return $result;
        }

        wp_set_current_user($user_id);

        return true;
    }

    /**
     * Проверяет, что запрос пришёл от браузерного расширения, а не от стороннего сайта.
     *
     * Браузерные расширения отправляют Origin: chrome-extension://<id> или moz-extension://<id>.
     * Обычные сайты отправляют Origin: https://evil-site.com — такие запросы не должны
     * обходить nonce-проверку WordPress.
     */
    private function is_extension_origin(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Расширения Chrome и Firefox
        if (
            str_starts_with($origin, 'chrome-extension://') ||
            str_starts_with($origin, 'moz-extension://')
        ) {
            return true;
        }

        // Service worker расширения может не отправлять Origin.
        // Проверяем кастомный заголовок, который обычные сайты не могут подделать
        // в cross-origin запросе (CORS preflight заблокирует нестандартный заголовок).
        if (empty($origin)) {
            $extension_header = $_SERVER['HTTP_X_CASHBACK_EXTENSION'] ?? '';
            if ('1' === $extension_header) {
                return true;
            }
        }

        // Запросы с того же домена (same-origin) — допускаются,
        // т.к. same-origin запросы не являются CSRF
        if (!empty($origin)) {
            $site_url = site_url();
            $site_origin = rtrim($site_url, '/');
            if ($origin === $site_origin) {
                return true;
            }
        }

        return false;
    }

    /**
     * Permission callback: пользователь авторизован.
     */
    public function check_user_logged_in(): bool
    {
        return is_user_logged_in();
    }

    /**
     * GET /stores — Список магазинов с кэшбэком.
     *
     * Кешируется в transient на 6 часов.
     * Домены берутся из post_meta `_store_domain` (заполняется в админке товара).
     */
    public function get_stores(\WP_REST_Request $request): \WP_REST_Response
    {
        $cached = get_transient(self::STORES_CACHE_KEY);
        if (false !== $cached) {
            return new \WP_REST_Response($cached, 200);
        }

        global $wpdb;

        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';

        // Получаем все товары-магазины с заполненным доменом
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title,
                        pm_domain.meta_value AS store_domain,
                        pm_label.meta_value AS cashback_label,
                        pm_value.meta_value AS cashback_value,
                        pm_popup.meta_value AS popup_mode,
                        n.name AS network_name,
                        n.slug AS network_slug
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_net ON p.ID = pm_net.post_id AND pm_net.meta_key = '_affiliate_network_id'
                 INNER JOIN {$wpdb->postmeta} pm_domain ON p.ID = pm_domain.post_id AND pm_domain.meta_key = '_store_domain'
                 LEFT JOIN {$wpdb->postmeta} pm_label ON p.ID = pm_label.post_id AND pm_label.meta_key = '_cashback_display_label'
                 LEFT JOIN {$wpdb->postmeta} pm_value ON p.ID = pm_value.post_id AND pm_value.meta_key = '_cashback_display_value'
                 LEFT JOIN {$wpdb->postmeta} pm_popup ON p.ID = pm_popup.post_id AND pm_popup.meta_key = '_store_popup_mode'
                 LEFT JOIN {$networks_table} n ON n.id = pm_net.meta_value AND n.is_active = 1
                 WHERE p.post_type = %s
                   AND p.post_status = 'publish'
                   AND pm_net.meta_value > 0
                   AND pm_domain.meta_value != ''",
                'product'
            ),
            ARRAY_A
        );

        if (empty($products)) {
            $stores = [];
            set_transient(self::STORES_CACHE_KEY, $stores, self::STORES_CACHE_TTL);
            return new \WP_REST_Response($stores, 200);
        }

        $stores = [];
        foreach ($products as $product) {
            $domain = $product['store_domain'] ?? '';
            if (empty($domain)) {
                continue;
            }

            // Нормализуем домен: убираем протокол, www., trailing slash и путь
            $domain = preg_replace('#^https?://#i', '', $domain);
            $domain = preg_replace('#^www\.#i', '', $domain);
            $domain = strtolower(explode('/', $domain)[0]);

            if (empty($domain)) {
                continue;
            }

            $stores[] = [
                'domain'         => $domain,
                'store_name'     => $product['post_title'] ?: ($product['network_name'] ?: $domain),
                'cashback_label' => $product['cashback_label'] ?: 'Кэшбэк',
                'cashback_value' => $product['cashback_value'] ?: '',
                'product_id'     => (int) $product['ID'],
                'network_slug'   => $product['network_slug'] ?: '',
                'popup_mode'     => $product['popup_mode'] ?: 'show',
            ];
        }

        set_transient(self::STORES_CACHE_KEY, $stores, self::STORES_CACHE_TTL);

        return new \WP_REST_Response($stores, 200);
    }

    /**
     * GET /me — Баланс и профиль текущего пользователя.
     */
    public function get_me(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $user_id = get_current_user_id();
        $user = wp_get_current_user();

        $balance_table = $wpdb->prefix . 'cashback_user_balance';
        $profile_table = $wpdb->prefix . 'cashback_user_profile';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $balance = $wpdb->get_row($wpdb->prepare(
            "SELECT available_balance, pending_balance, paid_balance
             FROM {$balance_table} WHERE user_id = %d",
            $user_id
        ), ARRAY_A);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $profile = $wpdb->get_row($wpdb->prepare(
            "SELECT cashback_rate, status FROM {$profile_table} WHERE user_id = %d",
            $user_id
        ), ARRAY_A);

        return new \WP_REST_Response([
            'user_id'      => $user_id,
            'display_name' => $user->display_name,
            'balance'      => [
                'available' => (float) ($balance['available_balance'] ?? 0),
                'pending'   => (float) ($balance['pending_balance'] ?? 0),
                'paid'      => (float) ($balance['paid_balance'] ?? 0),
            ],
            'cashback_rate' => (float) ($profile['cashback_rate'] ?? 60),
            'status'        => $profile['status'] ?? 'active',
        ], 200);
    }

    /**
     * GET /me/transactions — Последние транзакции пользователя.
     */
    public function get_transactions(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $user_id  = get_current_user_id();
        $page     = $request->get_param('page');
        $per_page = $request->get_param('per_page');
        $offset   = ($page - 1) * $per_page;

        $table = $wpdb->prefix . 'cashback_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
            $user_id
        ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT offer_name, cashback, order_status, currency, partner, action_date, created_at
             FROM {$table}
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ), ARRAY_A);

        $formatted = [];
        foreach ($items as $item) {
            $formatted[] = [
                'offer_name'   => $item['offer_name'],
                'cashback'     => (float) $item['cashback'],
                'currency'     => $item['currency'] ?: 'RUB',
                'order_status' => $item['order_status'],
                'partner'      => $item['partner'],
                'action_date'  => $item['action_date'],
                'created_at'   => $item['created_at'],
            ];
        }

        return new \WP_REST_Response([
            'items' => $formatted,
            'total' => $total,
            'pages' => (int) ceil($total / $per_page),
            'page'  => $page,
        ], 200);
    }

    /**
     * GET /activate — Активация кэшбэка для товара.
     *
     * Генерирует click_id, логирует клик, возвращает redirect URL.
     * Использует rate limiting аналогично WC_Affiliate_URL_Params.
     */
    public function activate_cashback(\WP_REST_Request $request): \WP_REST_Response
    {
        $product_id = $request->get_param('product_id');
        $user_id    = get_current_user_id();

        // Проверяем, что товар существует и является external
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'external') {
            return new \WP_REST_Response([
                'code'    => 'invalid_product',
                'message' => 'Товар не найден или не является внешним.',
            ], 404);
        }

        $base_url = $product->get_product_url();
        if (empty($base_url)) {
            return new \WP_REST_Response([
                'code'    => 'no_url',
                'message' => 'У товара отсутствует партнёрская ссылка.',
            ], 400);
        }

        // Rate limiting
        $ip_address  = Cashback_Encryption::get_client_ip();
        $rate_status = $this->get_click_rate_status($ip_address, $product_id);

        if ($rate_status === 'blocked') {
            return new \WP_REST_Response([
                'code'    => 'rate_limited',
                'message' => 'Слишком много запросов. Попробуйте позже.',
            ], 429);
        }

        // Генерация click_id
        $click_id = bin2hex(random_bytes(16));

        // Построение affiliate URL
        $affiliate_url = $this->build_affiliate_url($product_id, $user_id, $click_id);
        if (empty($affiliate_url)) {
            $affiliate_url = $base_url;
        }

        // CPA-сеть
        $cpa_network = $this->get_network_slug($product_id);

        // User-Agent из заголовка REST-запроса
        $user_agent = $request->get_header('user_agent');

        // Логирование клика
        $this->log_click([
            'click_id'      => $click_id,
            'user_id'       => $user_id,
            'product_id'    => $product_id,
            'cpa_network'   => $cpa_network,
            'affiliate_url' => $affiliate_url,
            'ip_address'    => $ip_address,
            'user_agent'    => $user_agent ? sanitize_text_field($user_agent) : null,
            'spam_click'    => $rate_status === 'spam' ? 1 : 0,
        ]);

        $expires_at = gmdate('Y-m-d H:i:s', time() + self::ACTIVATION_WINDOW);

        return new \WP_REST_Response([
            'redirect_url' => $affiliate_url,
            'click_id'     => $click_id,
            'expires_at'   => $expires_at,
        ], 200);
    }

    /**
     * GET /session-status — Статус активации кэшбэка для домена.
     *
     * Проверяет cashback_click_log на наличие клика за последние 30 минут.
     */
    public function get_session_status(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $user_id = get_current_user_id();
        $domain  = $request->get_param('domain');

        // Нормализация: удаляем протокол, www., trailing slash
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = strtolower(preg_replace('/^www\./i', '', $domain));
        $domain = explode('/', $domain)[0];

        // Находим product_id по домену магазина.
        // Ищем как нормализованный домен, так и варианты с протоколом (legacy)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_store_domain'
               AND (meta_value = %s
                    OR meta_value = %s
                    OR meta_value = %s)",
            $domain,
            'https://' . $domain,
            'http://' . $domain
        ));

        if (empty($product_ids)) {
            return new \WP_REST_Response([
                'activated'    => false,
                'activated_at' => null,
                'expires_at'   => null,
            ], 200);
        }

        $click_log_table = $wpdb->prefix . 'cashback_click_log';
        $threshold = gmdate('Y-m-d H:i:s', time() - self::ACTIVATION_WINDOW);

        // Ищем последний клик пользователя на товары этого домена
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $query_args = array_merge([$user_id], array_map('intval', $product_ids), [$threshold]);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $click = $wpdb->get_row($wpdb->prepare(
            "SELECT click_id, created_at
             FROM {$click_log_table}
             WHERE user_id = %d
               AND product_id IN ({$placeholders})
               AND created_at >= %s
             ORDER BY created_at DESC
             LIMIT 1",
            ...$query_args
        ), ARRAY_A);

        if ($click) {
            $activated_at = $click['created_at'];
            $click_time = strtotime($activated_at);
            $expires_at = gmdate('Y-m-d H:i:s', $click_time + self::ACTIVATION_WINDOW);

            return new \WP_REST_Response([
                'activated'    => true,
                'activated_at' => $activated_at,
                'expires_at'   => $expires_at,
                'click_id'     => $click['click_id'],
            ], 200);
        }

        return new \WP_REST_Response([
            'activated'    => false,
            'activated_at' => null,
            'expires_at'   => null,
        ], 200);
    }

    // ─── Private helpers ───

    /**
     * Проверка, что текущий запрос направлен к REST-маршрутам cashback/v1.
     */
    private function is_cashback_rest_request(): bool
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $rest_prefix = rest_get_url_prefix();

        if (false !== strpos($request_uri, '/' . $rest_prefix . '/' . self::NAMESPACE)) {
            return true;
        }

        if (isset($_GET['rest_route'])) {
            $route = sanitize_text_field(wp_unslash($_GET['rest_route']));
            if (0 === strpos($route, '/' . self::NAMESPACE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rate limiting (двухуровневый, аналогично WC_Affiliate_URL_Params).
     */
    private function get_click_rate_status(string $ip_address, int $product_id): string
    {
        $window = self::RATE_LIMIT_WINDOW;

        $pp_hash = substr(md5($ip_address . '|' . $product_id), 0, 12);
        $pp_key  = 'cb_pp_' . $pp_hash;

        $gl_hash = substr(md5($ip_address), 0, 12);
        $gl_key  = 'cb_gl_' . $gl_hash;

        $pp_count = (int) get_transient($pp_key);
        $gl_count = (int) get_transient($gl_key);

        if (
            $pp_count >= self::RATE_PER_PRODUCT_BLOCK ||
            $gl_count >= self::RATE_GLOBAL_BLOCK
        ) {
            return 'blocked';
        }

        set_transient($pp_key, $pp_count + 1, $window);
        set_transient($gl_key, $gl_count + 1, $window);

        if (
            ($pp_count + 1) > self::RATE_PER_PRODUCT_SPAM ||
            ($gl_count + 1) > self::RATE_GLOBAL_SPAM
        ) {
            return 'spam';
        }

        return 'normal';
    }

    /**
     * Построение affiliate URL с подстановкой параметров.
     */
    private function build_affiliate_url(int $product_id, int $user_id, string $click_id): ?string
    {
        global $wpdb;

        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'external') {
            return null;
        }

        $base_url = $product->get_product_url();
        if (empty($base_url)) {
            return null;
        }

        $network_id = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        if ($network_id <= 0) {
            return $base_url;
        }

        // Получаем параметры из post_meta (продуктовые) или из сети
        $product_params = get_post_meta($product_id, '_affiliate_product_params', true);

        if (!is_array($product_params) || empty($product_params)) {
            // Fallback: параметры из сети
            $params_table = $wpdb->prefix . 'cashback_affiliate_network_params';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $network_params = $wpdb->get_results($wpdb->prepare(
                "SELECT param_name, param_type, default_value
                 FROM {$params_table}
                 WHERE network_id = %d",
                $network_id
            ), ARRAY_A);

            $product_params = [];
            foreach ($network_params as $np) {
                $product_params[] = [
                    'key'   => $np['param_name'],
                    'value' => $np['param_type'],
                ];
            }
        }

        if (empty($product_params)) {
            return $base_url;
        }

        $params = [];
        foreach ($product_params as $param) {
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
     */
    private function get_network_slug(int $product_id): ?string
    {
        global $wpdb;

        $network_id = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        if ($network_id <= 0) {
            return null;
        }

        $table = $wpdb->prefix . 'cashback_affiliate_networks';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $slug = $wpdb->get_var($wpdb->prepare(
            "SELECT slug FROM {$table} WHERE id = %d AND is_active = 1",
            $network_id
        ));

        return $slug ?: null;
    }

    /**
     * Логирование клика в cashback_click_log.
     */
    private function log_click(array $data): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_click_log';
        $created_at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (click_id, user_id, product_id, cpa_network, affiliate_url, ip_address, user_agent, spam_click, created_at)
             VALUES (%s, %d, %d, %s, %s, %s, %s, %d, %s)",
            $data['click_id'],
            $data['user_id'],
            $data['product_id'],
            $data['cpa_network'] ?? '',
            $data['affiliate_url'] ?? '',
            $data['ip_address'] ?? '',
            $data['user_agent'] ?? '',
            $data['spam_click'] ?? 0,
            $created_at
        ));

        if (false === $result) {
            error_log('[Cashback REST API] Failed to log click: ' . $wpdb->last_error);
            return false;
        }

        return true;
    }
}

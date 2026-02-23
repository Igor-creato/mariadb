<?php

/**
 * Универсальный API-клиент для CPA-сетей
 *
 * Поддерживает: Admitad, EPN (расширяемо).
 * Хранит credentials зашифрованными через Cashback_Encryption.
 * Использует wp_remote_* для HTTP-запросов.
 *
 * Стратегия reconciliation (индустриальный стандарт кэшбэк-сервисов):
 *   МАТЧИНГ:    API.subid1 == DB.click_id (UUID, генерируемый кэшбэк-сервисом)
 *   СРАВНЕНИЕ:  status, payment/comission, cart/sum_order
 *   ФИЛЬТРАЦИЯ: API.subid2 == DB.user_id
 *   ЛОГИРОВАНИЕ: action_id (для lost order claims), order_id (для поддержки)
 *
 * @package CashbackPlugin
 * @since   6.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_API_Client
{
    /** @var self|null */
    private static ?self $instance = null;

    /** @var string Таблица сетей */
    private string $networks_table;

    /** @var string Таблица параметров сетей */
    private string $params_table;

    /** @var string Таблица чекпоинтов */
    private string $checkpoints_table;

    /** @var string Таблица транзакций */
    private string $transactions_table;

    /** @var string Таблица незарегистрированных транзакций */
    private string $unregistered_table;

    /** @var string Таблица синк-логов */
    private string $sync_log_table;

    /** @var string Таблица кликов */
    private string $click_log_table;

    /** @var array Кеш токенов в рамках одного запроса */
    private array $token_cache = [];

    /**
     * @return self
     */
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
        $this->networks_table     = $wpdb->prefix . 'cashback_affiliate_networks';
        $this->params_table       = $wpdb->prefix . 'cashback_affiliate_network_params';
        $this->checkpoints_table  = $wpdb->prefix . 'cashback_validation_checkpoints';
        $this->transactions_table = $wpdb->prefix . 'cashback_transactions';
        $this->unregistered_table = $wpdb->prefix . 'cashback_unregistered_transactions';
        $this->sync_log_table     = $wpdb->prefix . 'cashback_sync_log';
        $this->click_log_table    = $wpdb->prefix . 'cashback_click_log';
    }

    // =========================================================================
    // Credentials management
    // =========================================================================

    /**
     * Сохранить API credentials для сети (зашифрованные)
     *
     * @param int   $network_id ID сети
     * @param array $credentials ['client_id' => ..., 'client_secret' => ..., ...]
     * @return bool
     */
    public function save_credentials(int $network_id, array $credentials): bool
    {
        global $wpdb;

        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            error_log('Cashback API Client: Encryption not configured');
            return false;
        }

        $json = wp_json_encode($credentials);
        if (false === $json) {
            return false;
        }

        $encrypted = Cashback_Encryption::encrypt($json);
        if (false === $encrypted) {
            return false;
        }

        $result = $wpdb->update(
            $this->networks_table,
            ['api_credentials' => $encrypted],
            ['id' => $network_id],
            ['%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Получить расшифрованные credentials сети
     *
     * @param int $network_id
     * @return array|null
     */
    public function get_credentials(int $network_id): ?array
    {
        global $wpdb;

        $encrypted = $wpdb->get_var($wpdb->prepare(
            "SELECT api_credentials FROM {$this->networks_table} WHERE id = %d",
            $network_id
        ));

        if (empty($encrypted)) {
            return null;
        }

        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            return null;
        }

        $json = Cashback_Encryption::decrypt($encrypted);
        if (false === $json) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Получить конфигурацию сети
     *
     * @param string $slug Slug сети (admitad, epn)
     * @return array|null
     */
    public function get_network_config(string $slug): ?array
    {
        global $wpdb;

        $network = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->networks_table} WHERE slug = %s AND is_active = 1",
            $slug
        ), ARRAY_A);

        if (!$network) {
            return null;
        }

        // Расшифровать credentials
        if (!empty($network['api_credentials'])) {
            $creds = $this->get_credentials((int) $network['id']);
            $network['credentials'] = $creds;
        }

        // Парсим маппинг статусов
        if (!empty($network['api_status_map'])) {
            $network['status_map'] = json_decode($network['api_status_map'], true) ?: [];
        } else {
            $network['status_map'] = $this->get_default_status_map($slug);
        }

        return $network;
    }

    /**
     * Получить все активные сети с API-конфигурацией
     *
     * @return array
     */
    public function get_all_active_networks(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT id, name, slug, api_base_url, api_user_field, api_click_field, api_website_id, api_status_map, is_active
             FROM {$this->networks_table}
             WHERE is_active = 1 AND api_base_url IS NOT NULL AND api_base_url != ''
             ORDER BY sort_order, name",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Маппинг статусов по умолчанию
     *
     * Admitad документация: status = pending / approved / declined / approved_but_stalled
     * https://developers.admitad.com/knowledge-base/article/publisher-reports_1
     */
    private function get_default_status_map(string $slug): array
    {
        $maps = [
            'admitad' => [
                'pending'              => 'waiting',
                'approved'             => 'completed',
                'approved_but_stalled' => 'completed',  // подтверждён, но у рекламодателя нет средств
                'declined'             => 'declined',
                'rejected'             => 'declined',
                'open'                 => 'waiting',
                'hold'                 => 'waiting',
            ],
            'epn' => [
                'pending'    => 'waiting',
                'approved'   => 'completed',
                'rejected'   => 'declined',
                'canceled'   => 'declined',
                'hold'       => 'waiting',
            ],
        ];

        return $maps[$slug] ?? [
            'pending'  => 'waiting',
            'approved' => 'completed',
            'declined' => 'declined',
        ];
    }

    // =========================================================================
    // URL builder
    // =========================================================================

    /**
     * Собрать URL из конфига сети (api_base_url + endpoint) или вернуть fallback
     */
    private function build_api_url(array $network_config, string $endpoint_key, string $fallback_url): string
    {
        $base     = rtrim($network_config['api_base_url'] ?? '', '/');
        $endpoint = $network_config[$endpoint_key] ?? '';

        if ($base !== '' && $endpoint !== '') {
            return $base . '/' . ltrim($endpoint, '/');
        }

        return $fallback_url;
    }

    // =========================================================================
    // OAuth2 — Admitad
    // =========================================================================

    /**
     * Получить OAuth2 токен Admitad (с кешированием в transient)
     */
    public function get_admitad_token(array $credentials, array $network_config = []): ?string
    {
        $cache_key = 'cashback_admitad_token_' . md5($credentials['client_id'] ?? '');

        // Проверяем transient
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }

        // Проверяем runtime кеш
        if (isset($this->token_cache[$cache_key])) {
            return $this->token_cache[$cache_key];
        }

        $client_id     = $credentials['client_id'] ?? '';
        $client_secret = $credentials['client_secret'] ?? '';
        $scope         = $credentials['scope'] ?? 'statistics';

        if (empty($client_id) || empty($client_secret)) {
            error_log('Cashback API Client: Admitad credentials incomplete');
            return null;
        }

        $token_url = $this->build_api_url($network_config, 'api_token_endpoint', 'https://api.admitad.com/token/');

        $response = wp_remote_post($token_url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id'  => $client_id,
                'scope'      => $scope,
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('Cashback API Client: Admitad token error: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['access_token'])) {
            error_log('Cashback API Client: Admitad token failed. Code: ' . $code . ', Body: ' . wp_json_encode($body));
            return null;
        }

        $token    = $body['access_token'];
        $expires  = (int) ($body['expires_in'] ?? 3600);

        // Кешируем с запасом 5 минут
        set_transient($cache_key, $token, max(60, $expires - 300));
        $this->token_cache[$cache_key] = $token;

        return $token;
    }

    // =========================================================================
    // Fetch actions from CPA networks
    // =========================================================================

    /**
     * Получить действия из Admitad API
     *
     * Параметры фильтрации по документации:
     * https://developers.admitad.com/knowledge-base/article/publisher-reports_1
     *
     * @param array  $credentials  API credentials
     * @param array  $params       Параметры запроса (subid, subid1..4, date_start, date_end, etc)
     * @param array  $network_config Конфигурация сети
     * @return array ['success' => bool, 'actions' => [...], 'total' => int, 'error' => string|null]
     */
    public function fetch_admitad_actions(array $credentials, array $params, array $network_config = []): array
    {
        $token = $this->get_admitad_token($credentials, $network_config);
        if (!$token) {
            return ['success' => false, 'actions' => [], 'total' => 0, 'error' => 'Failed to get access token'];
        }

        $query_params = [];

        // Поддержка всех subid-вариантов (subid, subid1-subid4)
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null && preg_match('/^subid\d?$/', $key)) {
                $query_params[$key] = $value;
            }
        }

        // Даты
        foreach (['date_start', 'date_end', 'status_updated_start', 'status_updated_end'] as $date_key) {
            if (!empty($params[$date_key])) {
                $query_params[$date_key] = $params[$date_key];
            }
        }

        // Площадка
        if (!empty($params['website'])) {
            $query_params['website'] = $params['website'];
        }

        $query_params['limit']  = min((int) ($params['limit'] ?? 500), 500);
        $query_params['offset'] = (int) ($params['offset'] ?? 0);
        $query_params['order_by'] = $params['order_by'] ?? 'datetime';

        $actions_url = $this->build_api_url($network_config, 'api_actions_endpoint', 'https://api.admitad.com/statistics/actions/');
        $url = $actions_url . '?' . http_build_query($query_params);

        $response = wp_remote_get($url, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'actions' => [],
                'total'   => 0,
                'error'   => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            return [
                'success' => false,
                'actions' => [],
                'total'   => 0,
                'error'   => "HTTP {$code}: " . wp_json_encode($body),
            ];
        }

        $results = $body['results'] ?? [];
        $total   = (int) ($body['_meta']['count'] ?? count($results));

        return [
            'success' => true,
            'actions' => $results,
            'total'   => $total,
            'error'   => null,
        ];
    }

    /**
     * Получить ВСЕ действия из Admitad с автоматической пагинацией
     */
    public function fetch_all_admitad_actions(array $credentials, array $params, int $max_pages = 20, array $network_config = []): array
    {
        $all_actions = [];
        $offset      = 0;
        $limit       = 500;
        $total       = 0;
        $page        = 0;

        do {
            $params['offset'] = $offset;
            $params['limit']  = $limit;

            $result = $this->fetch_admitad_actions($credentials, $params, $network_config);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'actions' => $all_actions,
                    'total'   => $total,
                    'error'   => $result['error'],
                ];
            }

            $actions      = $result['actions'];
            $total        = $result['total'];
            $all_actions  = array_merge($all_actions, $actions);
            $offset      += $limit;
            $page++;

            // Защита от rate limit — пауза между запросами
            if (count($actions) === $limit && $page < $max_pages) {
                usleep(300000); // 300ms
            }
        } while (count($actions) === $limit && $page < $max_pages);

        return [
            'success' => true,
            'actions' => $all_actions,
            'total'   => $total,
            'error'   => null,
        ];
    }

    // =========================================================================
    // Validation logic
    // =========================================================================

    /**
     * Валидация пользователя: сравнение данных API с локальными транзакциями
     *
     * Стратегия (индустриальный стандарт кэшбэк-сервисов):
     *   1. Запрос API с фильтром subid2 = user_id (все транзакции пользователя)
     *   2. Матчинг: API.subid1 == DB.click_id (наш UUID)
     *   3. Сравнение сматченных: status, payment/comission, cart/sum_order
     *   4. Выявление: missing_local (в API, нет у нас), missing_api (у нас, нет в API)
     *
     * @param int    $user_id
     * @param string $network_slug Slug сети (admitad, epn)
     * @param bool   $use_checkpoint Использовать инкрементальный чекпоинт
     * @return array Результат валидации
     */
    public function validate_user(int $user_id, string $network_slug = 'admitad', bool $use_checkpoint = true): array
    {
        global $wpdb;

        $network = $this->get_network_config($network_slug);
        if (!$network || empty($network['credentials'])) {
            return [
                'success'    => false,
                'error'      => 'Сеть не найдена или API не настроен: ' . $network_slug,
                'user_id'    => $user_id,
                'network'    => $network_slug,
            ];
        }

        // ─── Определяем дату начала ───
        $date_start = '01.01.2020';

        if ($use_checkpoint) {
            $checkpoint = $this->get_checkpoint($user_id, $network_slug);
            if ($checkpoint && !empty($checkpoint['last_validated_date'])) {
                $dt = new DateTime($checkpoint['last_validated_date']);
                $dt->modify('-7 days');
                $date_start = $dt->format('d.m.Y');
            } else {
                $reg_date = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_registered FROM {$wpdb->users} WHERE ID = %d",
                    $user_id
                ));
                if ($reg_date) {
                    $date_start = (new DateTime($reg_date))->format('d.m.Y');
                }
            }
        }

        $date_end = (new DateTime())->format('d.m.Y');

        // ─── Запрос к API ───
        // api_user_field = 'subid2' (user_id передаётся в subid2 при генерации ссылки)
        // api_click_field = 'subid1' (click_id передаётся в subid1 — ключ матчинга)
        $user_field = $network['api_user_field'] ?? 'subid2';
        $api_params = [
            $user_field  => (string) $user_id,
            'date_start' => $date_start,
            'date_end'   => $date_end,
        ];

        if (!empty($network['api_website_id'])) {
            $api_params['website'] = $network['api_website_id'];
        }

        $api_result = $this->fetch_all_admitad_actions($network['credentials'], $api_params, 20, $network);

        if (!$api_result['success']) {
            return [
                'success'    => false,
                'error'      => 'API error: ' . $api_result['error'],
                'user_id'    => $user_id,
                'network'    => $network_slug,
            ];
        }

        $api_actions = $api_result['actions'];

        // ─── Локальные транзакции ───
        // ВАЖНО: включаем click_id для матчинга и order_number для fallback
        $local_start = DateTime::createFromFormat('d.m.Y', $date_start)->format('Y-m-d');

        // Матчим partner по slug И name сети (case-insensitive),
        // т.к. webhook может записывать partner_name по-разному
        $network_name = $network['name'] ?? '';

        $local_transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.click_id, t.uniq_id, t.order_number, t.offer_name,
                    t.comission, t.cashback, t.order_status, t.partner,
                    t.sum_order, t.created_at, t.updated_at
             FROM {$this->transactions_table} t
             WHERE t.user_id = %d
               AND (LOWER(t.partner) = LOWER(%s) OR LOWER(t.partner) = LOWER(%s))
               AND t.created_at >= %s
             ORDER BY t.created_at",
            $user_id,
            $network_slug,
            $network_name,
            $local_start
        ), ARRAY_A);

        // ─── Индексы для матчинга ───
        // Основной: по click_id (= API.subid1)
        $local_by_click_id = [];
        // Fallback: по order_number (= API.order_id)
        $local_by_order_number = [];

        foreach ($local_transactions as $tx) {
            if (!empty($tx['click_id'])) {
                $local_by_click_id[$tx['click_id']] = $tx;
            }
            if (!empty($tx['order_number'])) {
                // Может быть несколько транзакций с одним order_number (разные магазины)
                // Используем первую непривязанную
                $local_by_order_number[$tx['order_number']] = $tx;
            }
        }

        // Маппинг статусов
        $status_map = $network['status_map'];

        // Имя поля для click_id в API (по умолчанию subid1)
        $click_field = $network['api_click_field'] ?? 'subid1';

        // ─── Сравнение ───
        $matched       = [];
        $mismatched    = [];
        $missing_local = []; // Есть в API, нет локально

        // Суммы по API (по замапленным статусам)
        $api_sums = ['approved' => 0.0, 'pending' => 0.0, 'declined' => 0.0];

        // Суммы по локальным сматченным (по статусам)
        $local_sums = ['approved' => 0.0, 'pending' => 0.0, 'declined' => 0.0];

        // Множество сматченных click_id для обратной проверки
        $matched_click_ids = [];

        foreach ($api_actions as $action) {
            $api_click_id = (string) ($action[$click_field] ?? '');
            $api_status   = strtolower($action['status'] ?? 'pending');
            $api_payment  = (float) ($action['payment'] ?? 0);
            $api_cart     = (float) ($action['cart'] ?? 0);
            $mapped_status = $status_map[$api_status] ?? 'waiting';

            // Подсчёт сумм по API
            // balance — эквивалент completed (из кастомного маппинга approved→balance)
            if ($mapped_status === 'completed' || $mapped_status === 'balance') {
                $api_sums['approved'] += $api_payment;
            } elseif ($mapped_status === 'waiting') {
                $api_sums['pending'] += $api_payment;
            } elseif ($mapped_status === 'declined') {
                $api_sums['declined'] += $api_payment;
            }

            // ─── МАТЧИНГ: API.subid1 → DB.click_id ───
            $local_tx = null;

            // 1. Основной ключ: click_id
            if ($api_click_id !== '' && isset($local_by_click_id[$api_click_id])) {
                $local_tx = $local_by_click_id[$api_click_id];
            }

            // 2. Fallback: order_id → order_number
            //    Используем только если click_id не сматчился
            //    (order_id может быть неуникален между разными магазинами)
            if (!$local_tx) {
                $order_id = (string) ($action['order_id'] ?? '');
                if ($order_id !== '' && isset($local_by_order_number[$order_id])) {
                    $local_tx = $local_by_order_number[$order_id];
                }
            }

            if (!$local_tx) {
                $missing_local[] = [
                    'action_id'   => $action['action_id'] ?? '',
                    'click_id'    => $api_click_id,
                    'order_id'    => $action['order_id'] ?? '',
                    'status'      => $api_status,
                    'payment'     => $api_payment,
                    'cart'        => $api_cart,
                    'date'        => $action['action_date'] ?? '',
                    'campaign'    => $action['advcampaign_name'] ?? '',
                ];
                continue;
            }

            // Запоминаем что эта локальная транзакция сматчена
            if (!empty($local_tx['click_id'])) {
                $matched_click_ids[$local_tx['click_id']] = true;
            }

            // ─── СРАВНЕНИЕ ───
            $local_status     = $local_tx['order_status'];
            $local_commission = (float) $local_tx['comission'];
            $local_cart       = (float) ($local_tx['sum_order'] ?? 0);

            // Суммы по локальным
            if ($local_status === 'completed' || $local_status === 'balance') {
                $local_sums['approved'] += $local_commission;
            } elseif ($local_status === 'waiting' || $local_status === 'hold') {
                $local_sums['pending'] += $local_commission;
            } elseif ($local_status === 'declined') {
                $local_sums['declined'] += $local_commission;
            }

            // Статус: completed и balance — оба эквивалентны approved в API
            // (balance = финализированный completed, зачислено в баланс)
            $approved_statuses = ['completed', 'balance'];
            $status_match = ($local_status === $mapped_status)
                || (in_array($local_status, $approved_statuses, true)
                    && in_array($mapped_status, $approved_statuses, true));

            // Комиссия: допускаем погрешность 0.02 (округление)
            $commission_match = abs($api_payment - $local_commission) < 0.02;

            // Сумма заказа: допускаем погрешность 0.02
            // Не считаем mismatch если у одной из сторон 0 (не всегда передаётся)
            $cart_match = ($api_cart == 0 || $local_cart == 0)
                || abs($api_cart - $local_cart) < 0.02;

            if ($status_match && $commission_match && $cart_match) {
                $matched[] = [
                    'click_id'         => $api_click_id,
                    'api_status'       => $api_status,
                    'local_status'     => $local_status,
                    'api_payment'      => $api_payment,
                    'local_commission' => $local_commission,
                ];
            } else {
                $mismatched[] = [
                    'uniq_id'            => $local_tx['uniq_id'] ?? '',
                    'click_id'           => $api_click_id,
                    'local_id'           => $local_tx['id'],
                    'api_status'         => $api_status,
                    'local_status'       => $local_status,
                    'mapped_api_status'  => $mapped_status,
                    'api_payment'        => $api_payment,
                    'local_commission'   => $local_commission,
                    'api_cart'           => $api_cart,
                    'local_cart'         => $local_cart,
                    'status_mismatch'    => !$status_match,
                    'commission_mismatch' => !$commission_match,
                    'cart_mismatch'      => !$cart_match,
                    'action_id'          => $action['action_id'] ?? '',
                    'order_id'           => $action['order_id'] ?? '',
                ];
            }
        }

        // ─── Обратная проверка: транзакции есть у нас, но нет в API ───
        $missing_api = [];
        foreach ($local_transactions as $tx) {
            // Пропускаем если уже сматчено
            if (!empty($tx['click_id']) && isset($matched_click_ids[$tx['click_id']])) {
                continue;
            }
            // Пропускаем balance — финализировано, может быть за пределами API
            if ($tx['order_status'] === 'balance') {
                continue;
            }
            // Пропускаем если нет click_id — невозможно сверить
            if (empty($tx['click_id'])) {
                continue;
            }

            $missing_api[] = [
                'local_id'     => $tx['id'],
                'uniq_id'      => $tx['uniq_id'] ?? '',
                'click_id'     => $tx['click_id'],
                'order_number' => $tx['order_number'],
                'status'       => $tx['order_status'],
                'commission'   => (float) $tx['comission'],
                'created'      => $tx['created_at'],
            ];
        }

        // ─── Результат ───
        $has_issues    = !empty($mismatched) || !empty($missing_local) || !empty($missing_api);
        $total_checked = count($api_actions);
        $validation_status = $has_issues ? 'mismatch' : 'match';

        // Расхождение: разница между API approved и локальными approved суммами
        $discrepancy = abs($api_sums['approved'] - $local_sums['approved']);

        // Обновляем чекпоинт
        $this->update_checkpoint($user_id, $network_slug, [
            'last_validated_date'      => (new DateTime())->format('Y-m-d'),
            'api_sum_approved'         => $api_sums['approved'],
            'api_sum_pending'          => $api_sums['pending'],
            'api_sum_declined'         => $api_sums['declined'],
            'api_actions_count'        => $total_checked,
            'local_sum_approved'       => $local_sums['approved'],
            'local_sum_pending'        => $local_sums['pending'],
            'local_sum_declined'       => $local_sums['declined'],
            'local_transactions_count' => count($local_transactions),
            'validation_status'        => $validation_status,
            'discrepancy_amount'       => $discrepancy,
            'matched_count'            => count($matched),
            'mismatch_count'           => count($mismatched),
            'missing_local_count'      => count($missing_local),
            'missing_api_count'        => count($missing_api),
        ]);

        return [
            'success'         => true,
            'user_id'         => $user_id,
            'network'         => $network_slug,
            'status'          => $validation_status,
            'date_range'      => ['start' => $date_start, 'end' => $date_end],
            'api_total'       => $total_checked,
            'local_total'     => count($local_transactions),
            'matched_count'   => count($matched),
            'mismatch_count'  => count($mismatched),
            'missing_local'   => $missing_local,
            'missing_api'     => $missing_api,
            'mismatched'      => $mismatched,
            'sums' => [
                'api_approved'    => $api_sums['approved'],
                'api_pending'     => $api_sums['pending'],
                'api_declined'    => $api_sums['declined'],
                'local_approved'  => $local_sums['approved'],
                'local_pending'   => $local_sums['pending'],
                'local_declined'  => $local_sums['declined'],
                'discrepancy'     => $discrepancy,
            ],
        ];
    }

    // =========================================================================
    // Background sync — обновление статусов через cron
    // =========================================================================

    /**
     * Фоновая синхронизация статусов по всем сетям
     *
     * Матчинг: API.subid1 → DB.click_id
     * Вместо N+1 запросов — загружаем все нужные транзакции одним SELECT
     * и индексируем в PHP.
     *
     * Вызывается через WP Cron каждые 2-4 часа.
     *
     * @return array Результаты синхронизации
     */
    public function background_sync(): array
    {
        global $wpdb;

        $results = [];

        $networks = $this->get_all_active_networks();

        foreach ($networks as $network) {
            $slug = $network['slug'];
            $config = $this->get_network_config($slug);

            if (!$config || empty($config['credentials'])) {
                continue;
            }

            // Дата последней синхронизации
            $last_sync = get_option("cashback_last_sync_{$slug}", '');

            if (empty($last_sync)) {
                $date_start = (new DateTime())->modify('-30 days')->format('d.m.Y');
            } else {
                $dt = new DateTime($last_sync);
                $dt->modify('-1 day');
                $date_start = $dt->format('d.m.Y');
            }

            $date_end = (new DateTime())->format('d.m.Y');

            // Запрос по status_updated_start — получаем все действия с обновлёнными статусами
            $sync_params = [
                'status_updated_start' => $date_start . ' 00:00:00',
                'status_updated_end'   => $date_end . ' 23:59:59',
                'date_start'           => '01.01.2020',
            ];

            if (!empty($config['api_website_id'])) {
                $sync_params['website'] = $config['api_website_id'];
            }

            $api_result = $this->fetch_all_admitad_actions($config['credentials'], $sync_params, 20, $config);

            if (!$api_result['success']) {
                $results[$slug] = ['success' => false, 'error' => $api_result['error']];
                continue;
            }

            $api_actions = $api_result['actions'];

            if (empty($api_actions)) {
                $results[$slug] = ['success' => true, 'total' => 0, 'updated' => 0, 'skipped' => 0, 'not_found' => 0];
                update_option("cashback_last_sync_{$slug}", (new DateTime())->format('Y-m-d H:i:s'));
                continue;
            }

            // ─── Загружаем ВСЕ нужные локальные транзакции одним запросом ───
            $click_field = $config['api_click_field'] ?? 'subid1';

            // Собираем click_id и order_id из API-ответа
            $api_click_ids = [];
            $api_order_ids = [];
            foreach ($api_actions as $action) {
                $cid = (string) ($action[$click_field] ?? '');
                if ($cid !== '') {
                    $api_click_ids[] = $cid;
                }
                $oid = (string) ($action['order_id'] ?? '');
                if ($oid !== '') {
                    $api_order_ids[] = $oid;
                }
            }

            // Единый запрос: все транзакции по click_id OR order_number
            $local_map_by_click = [];
            $local_map_by_order = [];

            if (!empty($api_click_ids)) {
                $placeholders = implode(',', array_fill(0, count($api_click_ids), '%s'));
                $query_args = array_merge($api_click_ids, [$slug]);
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, click_id, order_number, order_status, comission, sum_order
                     FROM {$this->transactions_table}
                     WHERE click_id IN ({$placeholders}) AND partner = %s",
                    ...$query_args
                ), ARRAY_A);

                foreach ($rows as $row) {
                    $local_map_by_click[$row['click_id']] = $row;
                }
            }

            if (!empty($api_order_ids)) {
                $placeholders = implode(',', array_fill(0, count($api_order_ids), '%s'));
                $query_args = array_merge($api_order_ids, [$slug]);
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, click_id, order_number, order_status, comission, sum_order
                     FROM {$this->transactions_table}
                     WHERE order_number IN ({$placeholders}) AND partner = %s",
                    ...$query_args
                ), ARRAY_A);

                foreach ($rows as $row) {
                    if (!isset($local_map_by_order[$row['order_number']])) {
                        $local_map_by_order[$row['order_number']] = $row;
                    }
                }
            }

            $status_map = $config['status_map'];
            $updated    = 0;
            $skipped    = 0;
            $not_found  = 0;

            foreach ($api_actions as $action) {
                $api_click_id  = (string) ($action[$click_field] ?? '');
                $api_status    = strtolower($action['status'] ?? 'pending');
                $mapped_status = $status_map[$api_status] ?? 'waiting';
                $api_payment   = (float) ($action['payment'] ?? 0);

                // ─── Матчинг ───
                $local = null;

                // 1. Основной: click_id
                if ($api_click_id !== '' && isset($local_map_by_click[$api_click_id])) {
                    $local = $local_map_by_click[$api_click_id];
                }

                // 2. Fallback: order_id → order_number
                if (!$local) {
                    $order_id = (string) ($action['order_id'] ?? '');
                    if ($order_id !== '' && isset($local_map_by_order[$order_id])) {
                        $local = $local_map_by_order[$order_id];
                    }
                }

                if (!$local) {
                    $not_found++;
                    continue;
                }

                $local_status = $local['order_status'];

                // Защита: balance — финальный, не трогаем
                if ($local_status === 'balance') {
                    $skipped++;
                    continue;
                }

                // Защита от понижения: completed не откатываем в waiting
                if ($local_status === 'completed' && $mapped_status === 'waiting') {
                    $skipped++;
                    continue;
                }

                // Обновляем если статус или сумма изменились
                $status_changed = ($local_status !== $mapped_status);
                $commission_changed = abs($api_payment - (float) $local['comission']) >= 0.02;

                if (!$status_changed && !$commission_changed) {
                    $skipped++;
                    continue;
                }

                $update_data = [];
                $update_formats = [];

                if ($status_changed) {
                    $update_data['order_status'] = $mapped_status;
                    $update_formats[] = '%s';
                }

                if ($commission_changed) {
                    $update_data['comission'] = $api_payment;
                    $update_formats[] = '%s';
                }

                $wpdb->update(
                    $this->transactions_table,
                    $update_data,
                    ['id' => $local['id']],
                    $update_formats,
                    ['%d']
                );

                if (!$wpdb->last_error) {
                    $updated++;

                    $this->log_sync_event(
                        $slug,
                        (int) $local['id'],
                        $api_click_id ?: ($action['action_id'] ?? ''),
                        $local_status,
                        $mapped_status,
                        $api_payment
                    );
                }
            }

            // Сохраняем время последней синхронизации
            update_option("cashback_last_sync_{$slug}", (new DateTime())->format('Y-m-d H:i:s'));

            $results[$slug] = [
                'success'   => true,
                'total'     => count($api_actions),
                'updated'   => $updated,
                'skipped'   => $skipped,
                'not_found' => $not_found,
            ];
        }

        return $results;
    }

    // =========================================================================
    // Checkpoints
    // =========================================================================

    /**
     * Получить чекпоинт валидации пользователя
     */
    public function get_checkpoint(int $user_id, string $network_slug): ?array
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->checkpoints_table} WHERE user_id = %d AND network_slug = %s",
            $user_id,
            $network_slug
        ), ARRAY_A);
    }

    /**
     * Обновить чекпоинт валидации
     */
    public function update_checkpoint(int $user_id, string $network_slug, array $data): bool
    {
        global $wpdb;

        $data['user_id']       = $user_id;
        $data['network_slug']  = $network_slug;
        $data['validated_at']  = current_time('mysql');
        $data['validated_by']  = get_current_user_id() ?: 0;

        $existing = $this->get_checkpoint($user_id, $network_slug);

        if ($existing) {
            $result = $wpdb->update(
                $this->checkpoints_table,
                $data,
                ['user_id' => $user_id, 'network_slug' => $network_slug]
            );
        } else {
            $result = $wpdb->insert($this->checkpoints_table, $data);
        }

        return $result !== false;
    }

    // =========================================================================
    // Sync log
    // =========================================================================

    /**
     * Залогировать событие синхронизации
     */
    private function log_sync_event(
        string $network_slug,
        int $transaction_id,
        string $match_key,
        string $old_status,
        string $new_status,
        float $api_payment
    ): void {
        global $wpdb;

        $wpdb->insert($this->sync_log_table, [
            'network_slug'   => $network_slug,
            'transaction_id' => $transaction_id,
            'action_id'      => $match_key,
            'old_status'     => $old_status,
            'new_status'     => $new_status,
            'api_payment'    => $api_payment,
            'synced_at'      => current_time('mysql'),
        ]);
    }
}

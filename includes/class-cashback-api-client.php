<?php
/**
 * Универсальный API-клиент для CPA-сетей
 *
 * Поддерживает: Admitad, EPN (расширяемо).
 * Хранит credentials зашифрованными через Cashback_Encryption.
 * Использует wp_remote_* для HTTP-запросов.
 *
 * @package CashbackPlugin
 * @since   5.0.0
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
            "SELECT id, name, slug, api_base_url, api_user_field, api_click_field, api_status_map, is_active
             FROM {$this->networks_table}
             WHERE is_active = 1 AND api_base_url IS NOT NULL AND api_base_url != ''
             ORDER BY sort_order, name",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Маппинг статусов по умолчанию
     */
    private function get_default_status_map(string $slug): array
    {
        $maps = [
            'admitad' => [
                'pending'   => 'waiting',
                'approved'  => 'completed',
                'declined'  => 'declined',
                'rejected'  => 'declined',
                'open'      => 'waiting',
                'hold'      => 'waiting',
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
     *
     * @param array  $network_config Конфигурация сети из БД
     * @param string $endpoint_key   Ключ эндпоинта (api_token_endpoint, api_actions_endpoint)
     * @param string $fallback_url   URL по умолчанию если конфиг пуст
     * @return string
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
     *
     * @param array $credentials ['client_id' => ..., 'client_secret' => ..., 'scope' => ...]
     * @return string|null Access token
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
     * @param array  $credentials  API credentials
     * @param array  $params       [
     *   'subid'      => user_id (строка),
     *   'date_start' => 'dd.mm.YYYY',
     *   'date_end'   => 'dd.mm.YYYY',
     *   'status_updated_start' => 'dd.mm.YYYY' (опционально),
     *   'limit'      => int,
     *   'offset'     => int,
     *   'website'    => int (опционально, ID площадки),
     * ]
     * @return array ['success' => bool, 'actions' => [...], 'total' => int, 'error' => string|null]
     */
    public function fetch_admitad_actions(array $credentials, array $params, array $network_config = []): array
    {
        $token = $this->get_admitad_token($credentials, $network_config);
        if (!$token) {
            return ['success' => false, 'actions' => [], 'total' => 0, 'error' => 'Failed to get access token'];
        }

        $query_params = [];

        if (!empty($params['subid'])) {
            $query_params['subid'] = $params['subid'];
        }
        if (!empty($params['subid1'])) {
            $query_params['subid1'] = $params['subid1'];
        }
        if (!empty($params['date_start'])) {
            $query_params['date_start'] = $params['date_start'];
        }
        if (!empty($params['date_end'])) {
            $query_params['date_end'] = $params['date_end'];
        }
        if (!empty($params['status_updated_start'])) {
            $query_params['status_updated_start'] = $params['status_updated_start'];
        }
        if (!empty($params['status_updated_end'])) {
            $query_params['status_updated_end'] = $params['status_updated_end'];
        }
        if (!empty($params['website'])) {
            $query_params['website'] = $params['website'];
        }

        $query_params['limit']  = min((int) ($params['limit'] ?? 500), 500);
        $query_params['offset'] = (int) ($params['offset'] ?? 0);
        $query_params['order_by'] = 'datetime';

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
     *
     * @param array $credentials
     * @param array $params
     * @param int   $max_pages Защита от бесконечного цикла
     * @return array ['success' => bool, 'actions' => [...], 'total' => int, 'error' => string|null]
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

        // Определяем дату начала
        $date_start = '01.01.2020';

        if ($use_checkpoint) {
            $checkpoint = $this->get_checkpoint($user_id, $network_slug);
            if ($checkpoint && !empty($checkpoint['last_validated_date'])) {
                // Откатываем на 7 дней назад для подстраховки (статусы меняются задним числом)
                $dt = new DateTime($checkpoint['last_validated_date']);
                $dt->modify('-7 days');
                $date_start = $dt->format('d.m.Y');
            } else {
                // Берём дату регистрации пользователя
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

        // Запрос к API
        $api_result = $this->fetch_all_admitad_actions($network['credentials'], [
            'subid'      => (string) $user_id,
            'date_start' => $date_start,
            'date_end'   => $date_end,
        ], 20, $network);

        if (!$api_result['success']) {
            return [
                'success'    => false,
                'error'      => 'API error: ' . $api_result['error'],
                'user_id'    => $user_id,
                'network'    => $network_slug,
            ];
        }

        $api_actions = $api_result['actions'];

        // Получаем локальные транзакции за тот же период
        $local_start = DateTime::createFromFormat('d.m.Y', $date_start)->format('Y-m-d');

        $local_transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT id, uniq_id, order_number, comission, cashback, order_status, partner, sum_order, created_at, updated_at
             FROM {$this->transactions_table}
             WHERE user_id = %d AND partner = %s AND created_at >= %s
             ORDER BY created_at",
            $user_id,
            $network_slug,
            $local_start
        ), ARRAY_A);

        // Индексируем локальные по uniq_id для быстрого матчинга
        $local_by_uniq = [];
        foreach ($local_transactions as $tx) {
            if (!empty($tx['uniq_id'])) {
                $local_by_uniq[$tx['uniq_id']] = $tx;
            }
        }

        // Маппинг статусов
        $status_map = $network['status_map'];

        // Сравнение
        $matched       = [];
        $mismatched    = [];
        $missing_local = []; // Есть в API, нет локально
        $api_sum_approved  = 0;
        $api_sum_pending   = 0;
        $api_sum_declined  = 0;
        $local_sum         = 0;

        foreach ($api_actions as $action) {
            // Admitad возвращает action_id или order_id как уникальный идентификатор
            $action_id  = (string) ($action['action_id'] ?? $action['id'] ?? '');
            $api_status = strtolower($action['status'] ?? 'pending');
            $api_payment = (float) ($action['payment'] ?? 0);
            $mapped_status = $status_map[$api_status] ?? 'waiting';

            // Подсчёт сумм по API
            if ($mapped_status === 'completed') {
                $api_sum_approved += $api_payment;
            } elseif ($mapped_status === 'waiting') {
                $api_sum_pending += $api_payment;
            } elseif ($mapped_status === 'declined') {
                $api_sum_declined += $api_payment;
            }

            // Поиск в локальных транзакциях
            $local_tx = $local_by_uniq[$action_id] ?? null;

            if (!$local_tx) {
                // Пробуем по order_id если action_id не сматчился
                $order_id = (string) ($action['order_id'] ?? '');
                $local_tx = $local_by_uniq[$order_id] ?? null;
            }

            if (!$local_tx) {
                $missing_local[] = [
                    'action_id'  => $action_id,
                    'order_id'   => $action['order_id'] ?? '',
                    'status'     => $api_status,
                    'payment'    => $api_payment,
                    'date'       => $action['action_date'] ?? '',
                    'campaign'   => $action['advcampaign_name'] ?? '',
                ];
                continue;
            }

            $local_sum += (float) $local_tx['comission'];

            // Сравнение статусов
            $local_status = $local_tx['order_status'];
            $status_match = ($local_status === $mapped_status)
                || ($local_status === 'balance' && $mapped_status === 'completed');

            // Сравнение сумм (допускаем погрешность 0.01)
            $sum_match = abs($api_payment - (float) $local_tx['comission']) < 0.02;

            if ($status_match && $sum_match) {
                $matched[] = [
                    'uniq_id'      => $action_id,
                    'api_status'   => $api_status,
                    'local_status' => $local_status,
                    'api_payment'  => $api_payment,
                    'local_commission' => (float) $local_tx['comission'],
                ];
            } else {
                $mismatched[] = [
                    'uniq_id'            => $action_id,
                    'api_status'         => $api_status,
                    'local_status'       => $local_status,
                    'mapped_api_status'  => $mapped_status,
                    'api_payment'        => $api_payment,
                    'local_commission'   => (float) $local_tx['comission'],
                    'status_mismatch'    => !$status_match,
                    'sum_mismatch'       => !$sum_match,
                ];
            }
        }

        // Транзакции, которые есть локально, но нет в API (за проверяемый период)
        $api_action_ids = [];
        foreach ($api_actions as $a) {
            $api_action_ids[] = (string) ($a['action_id'] ?? $a['id'] ?? '');
            if (!empty($a['order_id'])) {
                $api_action_ids[] = (string) $a['order_id'];
            }
        }

        $missing_api = [];
        foreach ($local_transactions as $tx) {
            if (!empty($tx['uniq_id']) && !in_array($tx['uniq_id'], $api_action_ids, true)) {
                // Пропускаем если статус balance — уже зачислено и может быть за пределами API
                if ($tx['order_status'] !== 'balance') {
                    $missing_api[] = [
                        'local_id'   => $tx['id'],
                        'uniq_id'    => $tx['uniq_id'],
                        'status'     => $tx['order_status'],
                        'commission' => (float) $tx['comission'],
                        'created'    => $tx['created_at'],
                    ];
                }
            }
        }

        // Определяем общий результат
        $has_issues    = !empty($mismatched) || !empty($missing_local) || !empty($missing_api);
        $total_checked = count($api_actions);

        $validation_status = $has_issues ? 'mismatch' : 'match';

        $discrepancy = abs($api_sum_approved - $local_sum);

        // Обновляем чекпоинт
        $this->update_checkpoint($user_id, $network_slug, [
            'last_validated_date'   => (new DateTime())->format('Y-m-d'),
            'admitad_sum_approved'  => $api_sum_approved,
            'admitad_sum_pending'   => $api_sum_pending,
            'admitad_sum_declined'  => $api_sum_declined,
            'admitad_actions_count' => $total_checked,
            'local_commission_sum'  => $local_sum,
            'local_transactions_count' => count($local_transactions),
            'validation_status'     => $validation_status,
            'discrepancy_amount'    => $discrepancy,
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
                'api_approved'  => $api_sum_approved,
                'api_pending'   => $api_sum_pending,
                'api_declined'  => $api_sum_declined,
                'local_total'   => $local_sum,
                'discrepancy'   => $discrepancy,
            ],
        ];
    }

    // =========================================================================
    // Background sync — обновление статусов через cron
    // =========================================================================

    /**
     * Фоновая синхронизация статусов по всем сетям
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
                // Первый запуск — берём 30 дней назад
                $date_start = (new DateTime())->modify('-30 days')->format('d.m.Y');
            } else {
                // Откат на 1 день для подстраховки
                $dt = new DateTime($last_sync);
                $dt->modify('-1 day');
                $date_start = $dt->format('d.m.Y');
            }

            $date_end = (new DateTime())->format('d.m.Y');

            // Запрос по status_updated_start — получаем все действия с обновлёнными статусами
            $api_result = $this->fetch_all_admitad_actions($config['credentials'], [
                'status_updated_start' => $date_start . ' 00:00:00',
                'status_updated_end'   => $date_end . ' 23:59:59',
                'date_start'           => '01.01.2020',
            ], 20, $config);

            if (!$api_result['success']) {
                $results[$slug] = ['success' => false, 'error' => $api_result['error']];
                continue;
            }

            $status_map = $config['status_map'];
            $updated    = 0;
            $skipped    = 0;
            $not_found  = 0;

            foreach ($api_result['actions'] as $action) {
                $action_id     = (string) ($action['action_id'] ?? $action['id'] ?? '');
                $api_status    = strtolower($action['status'] ?? 'pending');
                $mapped_status = $status_map[$api_status] ?? 'waiting';
                $api_payment   = (float) ($action['payment'] ?? 0);

                // Ищем локальную транзакцию
                $local = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, order_status, comission FROM {$this->transactions_table}
                     WHERE uniq_id = %s AND partner = %s",
                    $action_id,
                    $slug
                ), ARRAY_A);

                // Если не нашли по action_id — пробуем по order_id
                if (!$local && !empty($action['order_id'])) {
                    $local = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, order_status, comission FROM {$this->transactions_table}
                         WHERE uniq_id = %s AND partner = %s",
                        (string) $action['order_id'],
                        $slug
                    ), ARRAY_A);
                }

                if (!$local) {
                    $not_found++;
                    continue;
                }

                $local_status = $local['order_status'];

                // Защита от понижения статуса: balance и completed не откатываем
                if (in_array($local_status, ['balance', 'completed'], true) && $mapped_status === 'waiting') {
                    $skipped++;
                    continue;
                }

                // balance — финальный, не трогаем вообще
                if ($local_status === 'balance') {
                    $skipped++;
                    continue;
                }

                // Обновляем если статус изменился
                if ($local_status !== $mapped_status) {
                    $update_data = ['order_status' => $mapped_status];

                    // Обновляем сумму комиссии если изменилась
                    if (abs($api_payment - (float) $local['comission']) >= 0.02) {
                        $update_data['comission'] = $api_payment;
                    }

                    $wpdb->update(
                        $this->transactions_table,
                        $update_data,
                        ['id' => $local['id']],
                        array_fill(0, count($update_data), '%s'),
                        ['%d']
                    );

                    if (!$wpdb->last_error) {
                        $updated++;

                        // Логируем изменение
                        $this->log_sync_event($slug, (int) $local['id'], $action_id, $local_status, $mapped_status, $api_payment);
                    }
                } else {
                    $skipped++;
                }
            }

            // Сохраняем время последней синхронизации
            update_option("cashback_last_sync_{$slug}", (new DateTime())->format('Y-m-d H:i:s'));

            $results[$slug] = [
                'success'   => true,
                'total'     => count($api_result['actions']),
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
        string $action_id,
        string $old_status,
        string $new_status,
        float $api_payment
    ): void {
        global $wpdb;

        $wpdb->insert($this->sync_log_table, [
            'network_slug'   => $network_slug,
            'transaction_id' => $transaction_id,
            'action_id'      => $action_id,
            'old_status'     => $old_status,
            'new_status'     => $new_status,
            'api_payment'    => $api_payment,
            'synced_at'      => current_time('mysql'),
        ]);
    }
}

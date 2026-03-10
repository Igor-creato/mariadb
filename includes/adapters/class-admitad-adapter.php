<?php

/**
 * Адаптер CPA-сети Admitad
 *
 * OAuth2: Basic Auth + client_credentials grant.
 * Пагинация: offset/limit.
 * Даты: dd.mm.yyyy.
 * Auth header: Authorization: Bearer {token}.
 *
 * @package CashbackPlugin
 * @since   7.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Admitad_Adapter extends Cashback_Network_Adapter_Base
{
    /**
     * {@inheritdoc}
     */
    public function get_slug(): string
    {
        return 'admitad';
    }

    /**
     * {@inheritdoc}
     */
    public function get_aliases(): array
    {
        return ['adm'];
    }

    /**
     * {@inheritdoc}
     *
     * Admitad OAuth2: Basic Auth header + client_credentials grant.
     * Кеширование в transient + runtime cache.
     */
    public function get_token(array $credentials, array $network_config): ?string
    {
        $client_id     = $credentials['client_id'] ?? '';
        $client_secret = $credentials['client_secret'] ?? '';
        $scope         = $credentials['scope'] ?? 'statistics';

        if (empty($client_id) || empty($client_secret)) {
            $this->last_token_error = 'Admitad credentials incomplete (client_id или client_secret пустые)';
            error_log('Cashback API Client: ' . $this->last_token_error);
            return null;
        }

        $cache_key = 'cashback_admitad_token_' . md5($client_id);

        // Проверяем transient
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }

        // Проверяем runtime кеш
        if (isset($this->token_cache[$cache_key])) {
            return $this->token_cache[$cache_key];
        }

        $token_url = $this->build_api_url($network_config, 'api_token_endpoint', 'https://api.admitad.com/token/');

        $response = $this->http_post($token_url, [
            'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ], [
            'grant_type' => 'client_credentials',
            'client_id'  => $client_id,
            'scope'      => $scope,
        ]);

        if (is_wp_error($response)) {
            $this->last_token_error = 'Admitad token ошибка сети: ' . $response->get_error_message();
            error_log('Cashback API Client: ' . $this->last_token_error);
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['access_token'])) {
            // Санитизация: удаляем чувствительные данные перед логированием
            $safe_body = $body;
            if (is_array($safe_body)) {
                unset($safe_body['access_token'], $safe_body['refresh_token'], $safe_body['client_secret']);
            }
            $this->last_token_error = 'Admitad token failed (HTTP ' . $code . ')';
            error_log('Cashback API Client: Admitad token failed. Code: ' . $code . ', Body: ' . wp_json_encode($safe_body));
            return null;
        }

        $token    = $body['access_token'];
        $expires  = (int) ($body['expires_in'] ?? 3600);

        // Кешируем с запасом 5 минут
        set_transient($cache_key, $token, max(60, $expires - 300));
        $this->token_cache[$cache_key] = $token;

        return $token;
    }

    /**
     * {@inheritdoc}
     */
    public function build_auth_headers(array $credentials, array $network_config): ?array
    {
        $token = $this->get_token($credentials, $network_config);
        if (!$token) {
            return null;
        }
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Получить действия из Admitad API (одна страница)
     *
     * @param array $credentials   API credentials
     * @param array $params        Параметры запроса
     * @param array $network_config Конфигурация сети
     * @return array ['success' => bool, 'actions' => [...], 'total' => int, 'error' => string|null]
     */
    public function fetch_actions(array $credentials, array $params, array $network_config): array
    {
        $auth_headers = $this->build_auth_headers($credentials, $network_config);
        if (!$auth_headers) {
            return $this->fetch_error('Failed to authenticate');
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

        $query_params['limit']    = min((int) ($params['limit'] ?? 500), 500);
        $query_params['offset']   = (int) ($params['offset'] ?? 0);
        $query_params['order_by'] = $params['order_by'] ?? 'datetime';

        $actions_url = $this->build_api_url($network_config, 'api_actions_endpoint', 'https://api.admitad.com/statistics/actions/');
        $url = $actions_url . '?' . http_build_query($query_params);

        $response = $this->http_get($url, $auth_headers);

        if (is_wp_error($response)) {
            return $this->fetch_error($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // На 401 — сбрасываем кеш токена и повторяем один раз
        if ($code === 401 && empty($params['_retry_after_401'])) {
            $client_id = $credentials['client_id'] ?? '';
            $cache_key = 'cashback_admitad_token_' . md5($client_id);
            delete_transient($cache_key);
            unset($this->token_cache[$cache_key]);

            error_log('Cashback Admitad: 401 on actions endpoint, invalidating token and retrying');

            $params['_retry_after_401'] = true;
            return $this->fetch_actions($credentials, $params, $network_config);
        }

        if ($code !== 200) {
            return $this->fetch_error("HTTP {$code}: " . wp_json_encode($body));
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
     * {@inheritdoc}
     */
    public function fetch_all_actions(array $credentials, array $params, int $max_pages, array $network_config): array
    {
        $all_actions = [];
        $offset      = 0;
        $limit       = 500;
        $total       = 0;
        $page        = 0;

        do {
            $params['offset'] = $offset;
            $params['limit']  = $limit;

            $result = $this->fetch_actions($credentials, $params, $network_config);

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

            // Защита от rate limit — пауза между запросами (100ms)
            if (count($actions) === $limit && $page < $max_pages) {
                usleep(100000);
            }
        } while (count($actions) === $limit && $page < $max_pages);

        return [
            'success' => true,
            'actions' => $all_actions,
            'total'   => $total,
            'error'   => null,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function get_default_status_map(): array
    {
        return [
            'pending'              => 'waiting',
            'approved'             => 'completed',
            'approved_but_stalled' => 'completed',
            'declined'             => 'declined',
            'rejected'             => 'declined',
            'open'                 => 'waiting',
            'hold'                 => 'waiting',
        ];
    }
}

<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Утилитный класс шифрования реквизитов пользователей.
 *
 * AES-256-CBC через openssl_encrypt/openssl_decrypt.
 * Ключ хранится в wp-config.php как CB_ENCRYPTION_KEY (64 hex-символа).
 * IV уникален для каждой записи (16 байт, prepend к шифротексту).
 */
class Cashback_Encryption
{
    private const CIPHER = 'aes-256-cbc';
    private const IV_LENGTH = 16;

    /**
     * Проверяет, настроен ли ключ шифрования
     */
    public static function is_configured(): bool
    {
        return defined('CB_ENCRYPTION_KEY') && strlen(CB_ENCRYPTION_KEY) === 64;
    }

    /**
     * Возвращает бинарный ключ из hex-константы
     */
    private static function get_key(): string
    {
        if (!self::is_configured()) {
            throw new \RuntimeException('CB_ENCRYPTION_KEY is not configured or invalid (expected 64 hex characters).');
        }
        return hex2bin(CB_ENCRYPTION_KEY);
    }

    /**
     * Шифрует строку AES-256-CBC. Результат: base64(iv . ciphertext)
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::get_key();
        $iv = openssl_random_pseudo_bytes(self::IV_LENGTH);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return base64_encode($iv . $ciphertext);
    }

    /**
     * Расшифровывает строку. Ожидает base64(iv . ciphertext)
     */
    public static function decrypt(string $encrypted): string
    {
        $key = self::get_key();
        $data = base64_decode($encrypted, true);

        if ($data === false || strlen($data) < self::IV_LENGTH + 1) {
            throw new \RuntimeException('Decryption failed: invalid data format.');
        }

        $iv = substr($data, 0, self::IV_LENGTH);
        $ciphertext = substr($data, self::IV_LENGTH);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed: ' . openssl_error_string());
        }

        return $plaintext;
    }

    /**
     * Шифрует массив реквизитов и возвращает encrypted_details, masked_details, details_hash
     *
     * @param array{account: string, full_name?: string, bank?: string} $details
     * @return array{encrypted_details: string, masked_details: string, details_hash: string}
     */
    public static function encrypt_details(array $details): array
    {
        $account = $details['account'] ?? '';
        $full_name = $details['full_name'] ?? '';
        $bank = $details['bank'] ?? '';

        // Формируем JSON для шифрования
        $plaintext_data = [
            'account' => $account,
            'full_name' => $full_name,
            'bank' => $bank,
        ];
        $json = wp_json_encode($plaintext_data, JSON_UNESCAPED_UNICODE);

        // Шифруем
        $encrypted_details = self::encrypt($json);

        // Формируем маскированное представление
        $masked_data = [
            'account' => self::mask_account($account),
            'full_name' => self::mask_name($full_name),
            'bank' => $bank, // Название банка не секретное
        ];
        $masked_details = wp_json_encode($masked_data, JSON_UNESCAPED_UNICODE);

        // Хеш для антифрода (каноническое представление)
        $details_hash = self::hash_details($plaintext_data);

        return [
            'encrypted_details' => $encrypted_details,
            'masked_details' => $masked_details,
            'details_hash' => $details_hash,
        ];
    }

    /**
     * Расшифровывает encrypted_details обратно в массив
     *
     * @return array{account: string, full_name: string, bank: string}
     */
    public static function decrypt_details(string $encrypted_details): array
    {
        $json = self::decrypt($encrypted_details);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Decrypted data is not valid JSON.');
        }

        return [
            'account' => $data['account'] ?? '',
            'full_name' => $data['full_name'] ?? '',
            'bank' => $data['bank'] ?? '',
        ];
    }

    /**
     * Маскирует номер счёта/карты/телефона: оставляет последние 4 символа
     *
     * Примеры:
     *  "4276 1234 5678 4523" → "**** **** **** 4523"
     *  "+79031234567"        → "+7903***4567"
     *  "410012345678"        → "********5678"
     */
    public static function mask_account(string $account): string
    {
        if ($account === '') {
            return '';
        }

        // Убираем пробелы для вычисления длины
        $clean = preg_replace('/\s+/', '', $account);
        $len = mb_strlen($clean);

        if ($len <= 4) {
            return $account; // Слишком короткий для маскирования
        }

        // Если номер содержит пробелы (формат карты: "4276 1234 5678 4523")
        if (strpos($account, ' ') !== false) {
            $parts = explode(' ', $account);
            $last_part = array_pop($parts);
            $masked_parts = array_map(function () {
                return '****';
            }, $parts);
            $masked_parts[] = $last_part;
            return implode(' ', $masked_parts);
        }

        // Без пробелов: маскируем всё кроме последних 4
        $visible = mb_substr($clean, -4);
        $hidden_len = $len - 4;
        return str_repeat('*', $hidden_len) . $visible;
    }

    /**
     * Маскирует ФИО: первая буква каждого слова + ****
     *
     * Примеры:
     *  "Иванов Петр Сидорович" → "И**** П**** С****"
     *  "Иванов Петр"           → "И**** П****"
     *  ""                       → ""
     */
    public static function mask_name(string $name): string
    {
        if ($name === '') {
            return '';
        }

        $words = preg_split('/\s+/', trim($name));
        $masked_words = array_map(function (string $word): string {
            if (mb_strlen($word) === 0) {
                return '';
            }
            return mb_substr($word, 0, 1) . '****';
        }, $words);

        return implode(' ', array_filter($masked_words));
    }

    /**
     * SHA-256 хеш от канонического представления реквизитов
     */
    public static function hash_details(array $details): string
    {
        // Канонический формат: отсортированный JSON без пробелов, lowercase account
        $canonical = [
            'account' => mb_strtolower(preg_replace('/\s+/', '', $details['account'] ?? '')),
            'full_name' => mb_strtolower(trim($details['full_name'] ?? '')),
        ];
        ksort($canonical);

        return hash('sha256', wp_json_encode($canonical, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Извлекает маскированный номер счёта из masked_details JSON.
     * Fallback на plaintext payout_account.
     */
    public static function get_masked_account(?string $masked_details_json, ?string $fallback_payout_account = null): string
    {
        if (!empty($masked_details_json)) {
            $data = json_decode($masked_details_json, true);
            if (is_array($data) && isset($data['account'])) {
                return $data['account'];
            }
        }

        // Fallback: маскируем plaintext если есть
        if (!empty($fallback_payout_account)) {
            return self::mask_account($fallback_payout_account);
        }

        return '';
    }

    /**
     * Записывает событие в аудит-лог
     */
    public static function write_audit_log(string $action, int $actor_id, ?string $entity_type = null, ?int $entity_id = null, ?array $extra_details = null): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_audit_log';

        $wpdb->insert(
            $table,
            [
                'action' => $action,
                'actor_id' => $actor_id,
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'ip_address' => self::get_client_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : null,
                'details' => $extra_details ? wp_json_encode($extra_details, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Получает IP-адрес клиента.
     *
     * Прокси-заголовки читаются ТОЛЬКО если REMOTE_ADDR в CASHBACK_TRUSTED_PROXIES.
     * Из прокси-заголовков принимаются только публичные IP (не приватные/зарезервированные),
     * чтобы предотвратить спуфинг через X-Forwarded-For: 10.0.0.1.
     */
    public static function get_client_ip(): string
    {
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';

        // Доверять прокси-заголовкам только если REMOTE_ADDR — доверенный прокси
        $trusted_proxies = defined('CASHBACK_TRUSTED_PROXIES') ? (array) CASHBACK_TRUSTED_PROXIES : [];

        if (!empty($trusted_proxies) && in_array($remote_addr, $trusted_proxies, true)) {
            // Приоритет: CF-Connecting-IP (Cloudflare) → X-Forwarded-For → X-Real-IP
            $proxy_headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
            foreach ($proxy_headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                    // X-Forwarded-For может содержать цепочку: client, proxy1, proxy2
                    if (strpos($ip, ',') !== false) {
                        $ip = trim(explode(',', $ip)[0]);
                    }
                    // Принимаем только публичные IP — приватные/зарезервированные = спуфинг
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }

        return filter_var($remote_addr, FILTER_VALIDATE_IP) ? $remote_addr : '0.0.0.0';
    }
}

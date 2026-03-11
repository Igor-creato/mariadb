<?php
/**
 * WP Cron для фоновой синхронизации статусов транзакций
 *
 * Каждые 2 часа запрашивает обновлённые статусы из CPA-сетей
 * и обновляет локальные транзакции.
 *
 * @package CashbackPlugin
 * @since   5.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_API_Cron
{
    /** @var string Имя cron-хука */
    const HOOK_NAME = 'cashback_api_sync_statuses';

    /** @var string Интервал */
    const INTERVAL_NAME = 'cashback_every_2_hours';

    /**
     * Инициализация: регистрация хуков и расписания
     */
    public static function init(): void
    {
        // Регистрация кастомного интервала
        add_filter('cron_schedules', [self::class, 'add_cron_interval']);

        // Регистрация обработчика
        add_action(self::HOOK_NAME, [self::class, 'run_sync']);

        // Планирование если не запланировано
        if (!wp_next_scheduled(self::HOOK_NAME)) {
            wp_schedule_event(time(), self::INTERVAL_NAME, self::HOOK_NAME);
        }
    }

    /**
     * Добавить кастомный интервал 2 часа
     *
     * @param array $schedules
     * @return array
     */
    public static function add_cron_interval(array $schedules): array
    {
        $schedules[self::INTERVAL_NAME] = [
            'interval' => 2 * HOUR_IN_SECONDS,
            'display'  => __('Каждые 2 часа (кэшбэк синхронизация)', 'cashback-plugin'),
        ];
        return $schedules;
    }

    /**
     * Запуск фоновой синхронизации
     *
     * Вызывается WP Cron. Логирует результаты.
     */
    public static function run_sync(): void
    {
        $start = microtime(true);

        error_log('Cashback API Cron: Starting background sync');

        try {
            $client  = Cashback_API_Client::get_instance();
            $results = $client->background_sync();

            $elapsed = round(microtime(true) - $start, 2);

            foreach ($results as $network => $result) {
                if ($result['success']) {
                    error_log(sprintf(
                        'Cashback API Cron [%s]: total=%d, updated=%d, inserted=%d, skipped=%d, not_found=%d, insert_errors=%d, declined_stale=%d (%.2fs)',
                        $network,
                        $result['total'],
                        $result['updated'],
                        $result['inserted'] ?? 0,
                        $result['skipped'],
                        $result['not_found'],
                        $result['insert_errors'] ?? 0,
                        $result['declined_stale'] ?? 0,
                        $elapsed
                    ));
                } else {
                    error_log(sprintf(
                        'Cashback API Cron [%s]: FAILED — %s',
                        $network,
                        $result['error'] ?? 'Unknown error'
                    ));
                }
            }

            // Автоматический перенос незарегистрированных транзакций к реальным пользователям
            $transfer_result = null;
            try {
                $transfer_result = $client->auto_transfer_unregistered(50);
                if ($transfer_result['transferred'] > 0 || $transfer_result['errors'] > 0) {
                    error_log(sprintf(
                        'Cashback API Cron: auto_transfer: transferred=%d, skipped_duplicate=%d, errors=%d, checked=%d',
                        $transfer_result['transferred'],
                        $transfer_result['skipped_duplicate'],
                        $transfer_result['errors'],
                        $transfer_result['checked']
                    ));
                }
            } catch (Exception $e) {
                error_log('Cashback API Cron: auto_transfer exception — ' . $e->getMessage());
            }

            // Сохраняем результат последней синхронизации для отображения в админке
            update_option('cashback_last_sync_result', [
                'timestamp'        => current_time('mysql'),
                'elapsed'          => $elapsed,
                'results'          => $results,
                'auto_transferred' => $transfer_result,
            ]);
        } catch (Exception $e) {
            error_log('Cashback API Cron: Exception — ' . $e->getMessage());
        }
    }

    /**
     * Деактивация: снять cron при деактивации плагина
     */
    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK_NAME);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK_NAME);
        }
    }

    /**
     * Ручной запуск синхронизации (из админки)
     *
     * @return array Результаты синхронизации
     */
    public static function manual_sync(): array
    {
        self::run_sync();
        return get_option('cashback_last_sync_result', []);
    }
}

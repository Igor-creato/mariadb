<?php

declare(strict_types=1);

/**
 * PHPStan Bootstrap File
 *
 * Provides type hints for WordPress functions that PHPStan doesn't understand correctly.
 * This helps eliminate false positives in static analysis.
 */

if (false) {
    /**
     * WordPress function that sends JSON error response and exits.
     *
     * @param mixed $data
     * @param int|null $status_code
     * @param int $options
     * @return never
     */
    function wp_send_json_error($data = null, $status_code = null, $options = 0)
    {
        exit;
    }

    /**
     * WordPress function that sends JSON success response and exits.
     *
     * @param mixed $data
     * @param int|null $status_code
     * @param int $options
     * @return never
     */
    function wp_send_json_success($data = null, $status_code = null, $options = 0)
    {
        exit;
    }

    /**
     * WordPress function that kills execution and displays HTML message.
     *
     * @param string|WP_Error $message
     * @param string|int $title
     * @param string|array|int $args
     * @return never
     */
    function wp_die($message = '', $title = '', $args = [])
    {
        exit;
    }
}

<?php

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Logger
{
    private static function prune_old_logs()
    {
        if (get_transient('wss_log_retention_last_run')) {
            return;
        }

        $directory = self::get_log_directory();
        if (!is_dir($directory) || !is_readable($directory) || !is_writable($directory)) {
            return;
        }

        $cutoff = strtotime('-1 month');
        if ($cutoff === false) {
            error_log('[WSS] Could not calculate log retention cutoff.');
            return;
        }

        $files = glob($directory . '*-????-??.log');
        if ($files === false) {
            error_log('[WSS] Could not enumerate log files for retention cleanup.');
            return;
        }

        foreach ($files as $filename) {
            if (!preg_match('/\A[a-z0-9_-]+-\d{4}-\d{2}\.log\z/i', basename($filename))) {
                continue;
            }

            $handle = fopen($filename, 'c+');
            if ($handle === false) {
                error_log('[WSS] Could not open a log file for retention cleanup.');
                return;
            }
            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                error_log('[WSS] Could not lock a log file for retention cleanup.');
                return;
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            if (!is_string($contents)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                error_log('[WSS] Could not read a log file for retention cleanup.');
                return;
            }

            $kept_lines = [];
            foreach (explode("\n", $contents) as $line) {
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);
                $timestamp = is_array($record) && isset($record['timestamp'])
                    ? strtotime((string) $record['timestamp'])
                    : false;
                if ($timestamp === false || $timestamp >= $cutoff) {
                    $kept_lines[] = $line;
                }
            }

            $updated_contents = empty($kept_lines)
                ? ''
                : implode("\n", $kept_lines) . "\n";
            if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
                flock($handle, LOCK_UN);
                fclose($handle);
                error_log('[WSS] Could not truncate a log file during retention cleanup.');
                return;
            }
            $written_total = 0;
            $updated_length = strlen($updated_contents);
            while ($written_total < $updated_length) {
                $written = fwrite($handle, substr($updated_contents, $written_total));
                if ($written === false || $written === 0) {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                    error_log('[WSS] Could not rewrite a log file during retention cleanup.');
                    return;
                }
                $written_total += $written;
            }

            flock($handle, LOCK_UN);
            fclose($handle);
        }

        set_transient('wss_log_retention_last_run', time(), DAY_IN_SECONDS);
    }

    public static function get_recent_logs($limit = 200)
    {
        self::prune_old_logs();
        $limit = min(500, max(1, absint($limit)));
        $directory = self::get_log_directory();
        if (!is_dir($directory) || !is_readable($directory)) {
            return [];
        }

        $files = glob($directory . '*-????-??.log');
        if ($files === false || empty($files)) {
            return [];
        }

        usort($files, static function ($left, $right) {
            return (filemtime($right) ?: 0) <=> (filemtime($left) ?: 0);
        });
        $files = array_slice($files, 0, 24);
        $records = [];
        foreach ($files as $filename) {
            if (strpos(basename($filename), 'errors-') === 0) {
                continue;
            }
            $handle = fopen($filename, 'rb');
            if ($handle === false) {
                continue;
            }

            $size = filesize($filename);
            if ($size === false) {
                fclose($handle);
                continue;
            }

            $offset = max(0, $size - 131072);
            if ($offset > 0) {
                fseek($handle, $offset);
                fgets($handle);
            } else {
                rewind($handle);
            }

            $contents = stream_get_contents($handle);
            fclose($handle);
            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $lines = array_slice(explode("\n", trim($contents)), -$limit);
            foreach ($lines as $line) {
                $record = json_decode($line, true);
                if (
                    is_array($record) &&
                    isset($record['timestamp'], $record['module'], $record['message'])
                ) {
                    $record['log_file'] = basename($filename);
                    $records[] = $record;
                }
            }
        }

        usort($records, static function ($left, $right) {
            return (strtotime((string) $right['timestamp']) ?: 0) <=>
                (strtotime((string) $left['timestamp']) ?: 0);
        });

        return array_slice($records, 0, $limit);
    }

    private static function get_log_directory()
    {
        $upload_dir = wp_upload_dir();

        return trailingslashit($upload_dir['basedir'])
            . 'woo-shopier-sync-uploads/logs/';
    }

    private static function ensure_log_directory($directory)
    {
        if (!file_exists($directory)) {
            wp_mkdir_p($directory);
        }

        if (!is_dir($directory) || !is_writable($directory)) {
            return false;
        }

        // Prevent direct access on Apache servers.
        $htaccess = dirname($directory) . '/.htaccess';

        if (!file_exists($htaccess)) {
            file_put_contents(
                $htaccess,
                "Require all denied\nDeny from all\n"
            );
        }

        // Prevent directory listing.
        $index_file = dirname($directory) . '/index.php';

        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden.');
        }

        return true;
    }

    public static function log(
        $module,
        $message,
        $level = 'INFO',
        $context = []
    ) {
        self::prune_old_logs();
        $module = sanitize_key($module);
        $level  = strtoupper(sanitize_key($level));

        $directory = self::get_log_directory();

        if (!self::ensure_log_directory($directory)) {
            error_log('[WSS] Log directory is not writable.');
            return false;
        }

        $allowed_context = [
            'event',
            'status',
            'reference_id',
            'duration_ms',
            'payload_structure',
            'endpoint',
            'response_error',
            'request_body',
            'remote_value',
            'job_id',
            'item_id',
            'wc_product_id',
            'product_id',
            'shopier_id',
            'job_type',
            'error_code',
            'error_message',
            'body_media_exists',
            'body_media_is_array',
            'body_media_count',
            'payload_media_count',
            'media_index',
            'body_media_item',
            'payload_media_item',
            'variant_index',
            'selection_signature',
            'body_variant',
            'payload_variant',
            'expected_variant_empty',
            'matches_result',
            'actual_type',
            'expected_type',
            'actual_selectionId',
            'expected_selectionId',
            'actual_priceData',
            'expected_priceData',
            'actual_stockQuantity',
            'expected_stockQuantity',
            'actual_media_exists',
            'actual_media_is_array',
            'actual_media_count',
            'expected_media_count',
            'body_media_type',
            'expected_media_type',
            'body_media_placement',
            'expected_media_placement',
            'actual_primary',
            'expected_primary',
            'http_status',
            'response_body',
            'response_message',
            'payload_size',
            'method',
            'payload',
            'response',
        ];

        $safe_context = array_intersect_key(
            $context,
            array_flip($allowed_context)
        );
        if (isset($safe_context['request_body']) && !is_array($safe_context['request_body'])) {
            unset($safe_context['request_body']);
        }
        if (isset($safe_context['remote_value'])) {
            $safe_context['remote_value'] = substr(
                sanitize_text_field((string) $safe_context['remote_value']),
                0,
                250
            );
        }
        if (isset($safe_context['endpoint'])) {
            $safe_context['endpoint'] = sanitize_text_field(
                (string) $safe_context['endpoint']
            );
        }
        if (isset($safe_context['response_error'])) {
            $safe_context['response_error'] = substr(
                sanitize_text_field((string) $safe_context['response_error']),
                0,
                1000
            );
        }

        $record = array_merge([
            'timestamp' => current_time('c'),
            'level'     => $level,
            'module'    => $module,
            'message'   => sanitize_text_field($message),
        ], $safe_context);

        $filename = $directory
            . $module
            . '-'
            . current_time('Y-m')
            . '.log';

        $json = wp_json_encode($record);

        if ($json === false) {
            return false;
        }

        $written = file_put_contents(
            $filename,
            $json . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        // All errors are also written to the central error log.
        if (in_array($level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
            $error_file = $directory
                . 'errors-'
                . current_time('Y-m')
                . '.log';

            file_put_contents(
                $error_file,
                $json . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }

        return $written !== false;
    }
}

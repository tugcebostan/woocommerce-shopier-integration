
<?php

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Logger
{
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
        ];

        $safe_context = array_intersect_key(
            $context,
            array_flip($allowed_context)
        );

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

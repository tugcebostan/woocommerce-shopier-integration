<?php

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Queue
{
    const MUTATING_JOB_TYPES = [
        'product_sync',
        'product_match_update',
        'product_update',
        'product_create',
    ];

    public static function init()
    {
        add_filter('cron_schedules', [self::class, 'register_schedule']);
        add_action('init', [self::class, 'schedule_worker']);
        add_action('wss_process_queue', [self::class, 'process_pending_jobs'], 10, 1);
    }

    public static function register_schedule($schedules)
    {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => __('Every minute'),
        ];

        return $schedules;
    }

    public static function schedule_worker()
    {
        if (!wp_next_scheduled('wss_process_queue')) {
            wp_schedule_event(time(), 'every_minute', 'wss_process_queue');
        }
    }

    public static function acquire_write_lock()
    {
        global $wpdb;

        $lock_name = self::get_write_lock_name();
        return (string) $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock_name)
        ) === '1';
    }

    public static function release_write_lock()
    {
        global $wpdb;

        $wpdb->get_var(
            $wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::get_write_lock_name())
        );
    }

    private static function get_write_lock_name()
    {
        global $wpdb;

        return 'wss_write_' . substr(hash('sha256', $wpdb->prefix), 0, 40);
    }

    public static function has_active_write_job()
    {
        self::fail_stale_jobs();

        global $wpdb;
        $table = $wpdb->prefix . 'wss_sync_jobs';
        $job_type_placeholders = implode(
            ',',
            array_fill(0, count(self::MUTATING_JOB_TYPES), '%s')
        );
        $query = $wpdb->prepare(
            "SELECT id
            FROM `{$table}`
            WHERE status IN ('pending', 'processing')
              AND job_type IN ({$job_type_placeholders})
            ORDER BY id ASC
            LIMIT 1",
            self::MUTATING_JOB_TYPES
        );

        $active_job_id = $wpdb->get_var($query);
        return $active_job_id !== null && $active_job_id !== ''
            ? true
            : $wpdb->last_error !== '';
    }

    public static function get_active_job()
    {
        self::fail_stale_jobs();

        global $wpdb;
        $table = $wpdb->prefix . 'wss_sync_jobs';
        $job_type_placeholders = implode(
            ',',
            array_fill(0, count(self::MUTATING_JOB_TYPES), '%s')
        );
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                FROM `{$table}`
                WHERE status IN ('pending', 'processing')
                  AND job_type IN ({$job_type_placeholders})
                ORDER BY id ASC
                LIMIT 1",
                self::MUTATING_JOB_TYPES
            ),
            ARRAY_A
        );
    }

    public static function get_job_report($job_id)
    {
        global $wpdb;

        $jobs_table = $wpdb->prefix . 'wss_sync_jobs';
        $items_table = $wpdb->prefix . 'wss_sync_job_items';
        $job = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$jobs_table}` WHERE id = %d LIMIT 1",
                absint($job_id)
            ),
            ARRAY_A
        );
        if (!$job) {
            return null;
        }

        $job['items'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ji.wc_product_id, ji.status, ji.error_message, p.post_title
                FROM `{$items_table}` ji
                LEFT JOIN `{$wpdb->posts}` p ON p.ID = ji.wc_product_id
                WHERE ji.job_id = %d
                ORDER BY ji.id ASC",
                absint($job_id)
            ),
            ARRAY_A
        ) ?: [];

        return $job;
    }

    public static function enqueue_product_sync($wc_product_id)
    {
        $result = self::enqueue_job('product_sync', [absint($wc_product_id)]);
        return is_wp_error($result) ? 0 : $result;
    }

    public static function enqueue_job($job_type, $product_ids, $job_data = [])
    {
        global $wpdb;

        if (!in_array($job_type, self::MUTATING_JOB_TYPES, true)) {
            return new WP_Error('wss_job_type_invalid', 'The requested job type is not supported.');
        }

        $product_ids = array_values(array_unique(array_filter(array_map('absint', (array) $product_ids))));
        if (empty($product_ids) || count($product_ids) > 100) {
            return new WP_Error(
                'wss_job_products_invalid',
                'Select between 1 and 100 products for a job.'
            );
        }

        if (!self::acquire_write_lock()) {
            return new WP_Error(
                'wss_job_lock_unavailable',
                'Another product write operation is starting or running.'
            );
        }

        $jobs_table = $wpdb->prefix . 'wss_sync_jobs';
        $items_table = $wpdb->prefix . 'wss_sync_job_items';
        $job_type_placeholders = implode(
            ',',
            array_fill(0, count(self::MUTATING_JOB_TYPES), '%s')
        );
        $active_job = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                FROM `{$jobs_table}`
                WHERE status IN ('pending', 'processing')
                  AND job_type IN ({$job_type_placeholders})
                LIMIT 1",
                self::MUTATING_JOB_TYPES
            )
        );
        if ($active_job || $wpdb->last_error !== '') {
            self::release_write_lock();
            return new WP_Error(
                'wss_job_already_active',
                $active_job
                    ? 'Finish the active product job before starting another write operation.'
                    : 'Could not verify the product job lock: ' . $wpdb->last_error
            );
        }

        $encoded_data = wp_json_encode($job_data);
        if ($encoded_data === false) {
            self::release_write_lock();
            return new WP_Error('wss_job_data_invalid', 'Job settings could not be encoded.');
        }

        $now = current_time('mysql');
        if ($wpdb->query('START TRANSACTION') === false) {
            self::release_write_lock();
            return new WP_Error('wss_job_transaction_failed', 'Could not start the product job.');
        }

        $inserted = $wpdb->insert(
            $jobs_table,
            [
                'job_type' => $job_type,
                'job_data' => $encoded_data,
                'status' => 'pending',
                'total_items' => count($product_ids),
                'processed_items' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'started_at' => null,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            $wpdb->query('ROLLBACK');
            self::release_write_lock();
            return new WP_Error('wss_job_create_failed', 'Could not create the product job.');
        }

        $job_id = (int) $wpdb->insert_id;
        $batch_id = 0;
        foreach ($product_ids as $index => $product_id) {
            if ($index > 0 && $index % 5 === 0) {
                $batch_id++;
            }
            $result = $wpdb->insert(
                $items_table,
                [
                    'job_id' => $job_id,
                    'batch_id' => $batch_id,
                    'wc_product_id' => $product_id,
                    'status' => 'pending',
                    'attempts' => 0,
                    'error_message' => null,
                    'processed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s']
            );
            if ($result === false) {
                $wpdb->query('ROLLBACK');
                self::release_write_lock();
                return new WP_Error('wss_job_items_create_failed', 'Could not add all products to the job.');
            }
        }

        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            self::release_write_lock();
            return new WP_Error('wss_job_commit_failed', 'Could not save the product job.');
        }

        self::release_write_lock();
        wp_schedule_single_event(time() + 5, 'wss_process_queue', [$job_id]);

        return $job_id;
    }

    public static function process_pending_jobs($scheduled_job_id = 0)
    {
        global $wpdb;

        self::fail_stale_jobs();
        if (!self::acquire_write_lock()) {
            return;
        }

        $jobs_table = $wpdb->prefix . 'wss_sync_jobs';
        $items_table = $wpdb->prefix . 'wss_sync_job_items';
        $job_type_placeholders = implode(
            ',',
            array_fill(0, count(self::MUTATING_JOB_TYPES), '%s')
        );
        $job = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                FROM `{$jobs_table}`
                WHERE status IN ('pending', 'processing')
                  AND job_type IN ({$job_type_placeholders})
                ORDER BY id ASC
                LIMIT 1",
                self::MUTATING_JOB_TYPES
            ),
            ARRAY_A
        );

        if (!$job || (
            absint($scheduled_job_id) > 0 &&
            (int) $job['id'] !== absint($scheduled_job_id)
        )) {
            self::release_write_lock();
            return;
        }

        $job_id = (int) $job['id'];
        $now = current_time('mysql');
        if ($job['status'] === 'pending') {
            $wpdb->update(
                $jobs_table,
                ['status' => 'processing', 'started_at' => $now, 'updated_at' => $now],
                ['id' => $job_id],
                ['%s', '%s', '%s'],
                ['%d']
            );
        }

        // Interrupted items: fail them (worker crashed mid-operation)
        $interrupted_items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id
                FROM `{$items_table}`
                WHERE job_id = %d AND status = 'processing'
                ORDER BY id ASC",
                $job_id
            ),
            ARRAY_A
        );
        foreach ($interrupted_items as $interrupted) {
            self::finish_item(
                $job_id,
                (int) $interrupted['id'],
                false,
                'Worker stopped during this item. Shopier may have completed the request; check the Shopier product before retrying.'
            );
        }

        // Claim and process one product per worker request to bound request time.
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                FROM `{$items_table}`
                WHERE job_id = %d AND status = 'pending'
                ORDER BY id ASC
                LIMIT 1",
                $job_id
            ),
            ARRAY_A
        );

        if (!$item) {
            self::finish_job_if_complete($job_id);
            self::release_write_lock();
            return;
        }

        $item_id = (int) $item['id'];
        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$items_table}`
                SET status = 'processing', attempts = attempts + 1, updated_at = %s
                WHERE id = %d AND job_id = %d AND status = 'pending'",
                $now,
                $item_id,
                $job_id
            )
        );
        if ($claimed !== 1) {
            self::release_write_lock();
            return;
        }

        $result = self::run_job_item($job, $item);
        $message = is_wp_error($result)
            ? $result->get_error_message()
            : (string) ($result['message'] ?? 'Completed.');
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            if (is_array($error_data) && !empty($error_data['shopier_product_id'])) {
                $message .= ' Shopier product ID: ' . sanitize_text_field(
                    (string) $error_data['shopier_product_id']
                ) . '.';
            }
        }

        self::finish_item($job_id, $item_id, !is_wp_error($result), $message);
        self::finish_job_if_complete($job_id);
        self::release_write_lock();

        if (self::has_active_write_job()) {
            wp_schedule_single_event(time() + 5, 'wss_process_queue', [$job_id]);
        }
    }

    private static function run_job_item($job, $item)
    {
        $product_id = (int) $item['wc_product_id'];
        $job_data = json_decode((string) ($job['job_data'] ?? ''), true);
        $job_data = is_array($job_data) ? $job_data : [];

        switch ((string) $job['job_type']) {
            case 'product_sync':
                $result = WSS_Product_Sync::sync_product($product_id);
                return !empty($result['success'])
                    ? $result
                    : new WP_Error('wss_product_sync_failed', (string) ($result['message'] ?? 'Product sync failed.'));

            case 'product_match_update':
                $shopier_ids = $job_data['shopier_ids'] ?? [];
                $shopier_product_id = isset($shopier_ids[$product_id])
                    ? (string) $shopier_ids[$product_id]
                    : '';
                if ($shopier_product_id === '') {
                    return new WP_Error('wss_job_shopier_id_missing', 'No Shopier product ID was selected.');
                }
                $mapping = WSS_Product_Sync::save_manual_mapping($product_id, $shopier_product_id);
                if (is_wp_error($mapping)) {
                    return $mapping;
                }
                return [
                    'success' => true,
                    'message' => 'Ürün eşleştirmesi kaydedildi; Shopier’e aktarım yapılmadı.',
                ];

            case 'product_update':
                return self::normalize_result(
                    WSS_Admin::run_product_update_job($product_id),
                    'wss_product_update_failed'
                );

            case 'product_create':
                $next_placement_score = absint($job_data['next_placement_score'] ?? 0);
                if ($next_placement_score < 2) {
                    $score_result = WSS_Shopier_API::get_next_placement_score();
                    if (empty($score_result['success'])) {
                        $details = WSS_Shopier_API::get_error_summary(
                            $score_result['body'] ?? null,
                            $score_result['message'] ?? ''
                        );
                        return new WP_Error(
                            'wss_shopier_placement_score_unavailable',
                            'Shopier sıralama puanı alınamadı.'
                                . ($details !== '' ? ' ' . $details : '')
                        );
                    }
                    $next_placement_score = absint($score_result['placement_score'] ?? 0);
                }
                if ($next_placement_score < 2 || $next_placement_score >= PHP_INT_MAX) {
                    return new WP_Error(
                        'wss_shopier_placement_score_invalid',
                        'Shopier için yeni bir sıralama puanı güvenle ayrılamadı.'
                    );
                }

                // Reserve the next score before the remote create request so an interrupted
                // request cannot reuse a score if Shopier completed the create.
                $job_data['next_placement_score'] = $next_placement_score + 1;
                if (!self::save_job_data((int) $job['id'], $job_data)) {
                    return new WP_Error(
                        'wss_job_data_save_failed',
                        'Ürün oluşturma sıralama bilgisi kuyruğa kaydedilemedi.'
                    );
                }

                return self::normalize_result(
                    WSS_Product_Sync::create_product(
                        $product_id,
                        $next_placement_score
                    ),
                    'wss_product_create_failed'
                );
        }

        return new WP_Error('wss_job_type_invalid', 'The product job type is not supported.');
    }

    private static function save_job_data($job_id, $job_data)
    {
        global $wpdb;

        $encoded_data = wp_json_encode($job_data);
        if ($encoded_data === false) {
            return false;
        }

        return $wpdb->update(
            $wpdb->prefix . 'wss_sync_jobs',
            [
                'job_data' => $encoded_data,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => absint($job_id)],
            ['%s', '%s'],
            ['%d']
        ) !== false;
    }

    private static function normalize_result($result, $error_code)
    {
        if (is_wp_error($result)) {
            return $result;
        }
        if (is_array($result) && !empty($result['success'])) {
            return $result;
        }

        return new WP_Error(
            $error_code,
            is_array($result) && !empty($result['message'])
                ? (string) $result['message']
                : 'The product operation failed without a result message.'
        );
    }

    private static function finish_item($job_id, $item_id, $success, $message)
    {
        global $wpdb;

        $items_table = $wpdb->prefix . 'wss_sync_job_items';
        $jobs_table = $wpdb->prefix . 'wss_sync_jobs';
        $now = current_time('mysql');
        
        if (!$success) {
            // Log error message to log file for debugging
            WSS_Logger::log(
                'shopier-sync-job',
                'Job item error: ' . $message,
                'ERROR',
                [
                    'job_id' => $job_id,
                    'item_id' => $item_id,
                ]
            );
        }
        
        $wpdb->update(
            $items_table,
            [
                'status' => $success ? 'success' : 'error',
                'error_message' => sanitize_textarea_field($message),
                'processed_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $item_id, 'job_id' => $job_id],
            ['%s', '%s', '%s', '%s'],
            ['%d', '%d']
        );
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$jobs_table}`
                SET processed_items = processed_items + 1,
                    success_count = success_count + %d,
                    error_count = error_count + %d,
                    updated_at = %s
                WHERE id = %d",
                $success ? 1 : 0,
                $success ? 0 : 1,
                $now,
                $job_id
            )
        );
    }

    private static function finish_job_if_complete($job_id)
    {
        global $wpdb;

        $jobs_table = $wpdb->prefix . 'wss_sync_jobs';
        $items_table = $wpdb->prefix . 'wss_sync_job_items';
        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                FROM `{$items_table}`
                WHERE job_id = %d AND status IN ('pending', 'processing')",
                $job_id
            )
        );
        if ($remaining > 0) {
            return;
        }

        $job = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT success_count, error_count FROM `{$jobs_table}` WHERE id = %d",
                $job_id
            ),
            ARRAY_A
        );
        if (!$job) {
            return;
        }

        $status = (int) $job['error_count'] === 0
            ? 'success'
            : ((int) $job['success_count'] === 0 ? 'failed' : 'partial');
        $now = current_time('mysql');
        $wpdb->update(
            $jobs_table,
            ['status' => $status, 'completed_at' => $now, 'updated_at' => $now],
            ['id' => $job_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    private static function fail_stale_jobs()
    {
        global $wpdb;

        $lock_owner = $wpdb->get_var(
            $wpdb->prepare('SELECT IS_USED_LOCK(%s)', self::get_write_lock_name())
        );
        if ($lock_owner !== null && $lock_owner !== false) {
            return;
        }

        $jobs_table = $wpdb->prefix . 'wss_sync_jobs';
        $items_table = $wpdb->prefix . 'wss_sync_job_items';
        $stale_before = wp_date('Y-m-d H:i:s', time() - 1800, wp_timezone());
        $job_type_placeholders = implode(
            ',',
            array_fill(0, count(self::MUTATING_JOB_TYPES), '%s')
        );
        $stale_jobs = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id
                FROM `{$jobs_table}`
                WHERE status = 'processing'
                  AND updated_at < %s
                  AND job_type IN ({$job_type_placeholders})",
                array_merge([$stale_before], self::MUTATING_JOB_TYPES)
            )
        );

        foreach ($stale_jobs as $job_id) {
            $message = 'The job timed out. A Shopier request may have completed; inspect Shopier before retrying.';
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$items_table}`
                    SET status = 'error', error_message = %s,
                        processed_at = %s, updated_at = %s
                    WHERE job_id = %d AND status IN ('pending', 'processing')",
                    $message,
                    current_time('mysql'),
                    current_time('mysql'),
                    (int) $job_id
                )
            );
            $success_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT success_count FROM `{$jobs_table}` WHERE id = %d",
                    (int) $job_id
                )
            );
            $wpdb->update(
                $jobs_table,
                [
                    'status' => $success_count > 0 ? 'partial' : 'failed',
                    'processed_items' => (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM `{$items_table}` WHERE job_id = %d AND status IN ('success', 'error')",
                            (int) $job_id
                        )
                    ),
                    'error_count' => (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM `{$items_table}` WHERE job_id = %d AND status = 'error'",
                            (int) $job_id
                        )
                    ),
                    'completed_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => (int) $job_id],
                ['%s', '%d', '%d', '%s', '%s'],
                ['%d']
            );
        }
    }
}

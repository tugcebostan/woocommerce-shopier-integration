<?php

if (!defined('ABSPATH')) {
    exit;
}
class WSS_Admin
{
    const PAGE_SLUG = 'wss-shopier';

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 99);
        add_action('admin_post_wss_test_shopier_connection', [__CLASS__, 'handle_shopier_connection_test']);
        add_action('admin_post_wss_save_shopier_settings', [__CLASS__, 'handle_save_shopier_settings']);
        add_action('admin_post_wss_save_shopier_product_mapping', [__CLASS__, 'handle_save_shopier_product_mapping']);
        add_action('admin_post_wss_load_shopier_product', [__CLASS__, 'handle_load_shopier_product']);
        add_action('admin_post_wss_save_attribute_mappings', [__CLASS__, 'handle_save_attribute_mappings']);
        add_action('admin_post_wss_save_variation_mapping', [__CLASS__, 'handle_save_variation_mapping']);
        add_action('admin_post_wss_load_global_variation_catalog', [__CLASS__, 'handle_load_global_variation_catalog']);
        add_action('admin_post_wss_save_global_variation_mappings', [__CLASS__, 'handle_save_global_variation_mappings']);
        add_action('admin_post_wss_save_preview_product_mapping', [__CLASS__, 'handle_save_preview_product_mapping']);
        add_action('admin_post_wss_load_shopier_variation_comparison', [__CLASS__, 'handle_load_shopier_variation_comparison']);
        add_action('admin_post_wss_save_product_variation_mappings', [__CLASS__, 'handle_save_product_variation_mappings']);
        add_action('admin_post_wss_load_shopier_products_catalog', [__CLASS__, 'handle_load_shopier_products_catalog']);
        add_action('admin_post_wss_load_shopier_orders', [__CLASS__, 'handle_load_shopier_orders']);
        add_action('admin_post_wss_load_shopier_shippings', [__CLASS__, 'handle_load_shopier_shippings']);
        add_action('admin_post_wss_products_action', [__CLASS__, 'handle_products_action']);
        add_action('admin_post_wss_sync_shopier_stocks', [__CLASS__, 'handle_sync_shopier_stocks']);
        add_action('admin_post_wss_export_products_csv', [__CLASS__, 'handle_export_products_csv']);
        add_action('admin_post_wss_import_products_csv', [__CLASS__, 'handle_import_products_csv']);
    }
    public static function handle_load_shopier_products_catalog()
    {
        self::require_mapping_capability();
        check_admin_referer('wss_load_shopier_products_catalog');

        $result = WSS_Shopier_API::get_products_catalog();
        if (empty($result['success']) || !is_array($result['body'] ?? null)) {
            self::redirect_products([
                'wss_product_notice' => 'shopier_products_load_failed',
                'wss_product_http_status' => absint($result['status'] ?? 0),
            ]);
        }

        set_transient(
            self::get_shopier_products_catalog_transient_key(),
            $result['body'],
            HOUR_IN_SECONDS
        );
        self::redirect_products(['wss_product_notice' => 'shopier_products_loaded']);
    }

    public static function handle_load_shopier_orders()
    {
        self::require_mapping_capability();
        check_admin_referer('wss_load_shopier_orders');

        $order_page = isset($_POST['order_page']) ? max(1, absint(wp_unslash($_POST['order_page']))) : 1;
        $per_page = isset($_POST['order_per_page']) ? absint(wp_unslash($_POST['order_per_page'])) : 25;
        $per_page = in_array($per_page, [10, 25, 50, 100], true) ? $per_page : 25;
        $result = WSS_Shopier_API::get_orders_page($order_page, $per_page);
        if (empty($result['success']) || !is_array($result['body'] ?? null)) {
            self::redirect_orders(['order_notice' => 'shopier_orders_load_failed']);
        }

        set_transient(self::get_shopier_orders_transient_key($order_page, $per_page), $result['body'], 10 * MINUTE_IN_SECONDS);
        self::redirect_orders([
            'order_notice' => 'shopier_orders_loaded',
            'order_count' => count($result['body']['orders'] ?? []),
            'order_page' => $order_page,
            'order_per_page' => $per_page,
        ]);
    }

    public static function handle_load_shopier_shippings()
    {
        self::require_mapping_capability();
        check_admin_referer('wss_load_shopier_shippings');
        $page = isset($_POST['shipping_page']) ? max(1, absint(wp_unslash($_POST['shipping_page']))) : 1;
        $per_page = isset($_POST['shipping_per_page']) ? absint(wp_unslash($_POST['shipping_per_page'])) : 25;
        $per_page = in_array($per_page, [10, 25, 50], true) ? $per_page : 25;
        $filters = self::get_shipping_filters($_POST);
        $result = WSS_Shopier_API::get_shippings_page($page, $per_page, $filters);
        if (empty($result['success']) || !is_array($result['body'] ?? null)) {
            self::redirect_shippings(['shipping_notice' => 'load_failed']);
        }
        set_transient(self::get_shopier_shippings_transient_key($page, $per_page, $filters), $result['body'], 10 * MINUTE_IN_SECONDS);
        self::redirect_shippings([
            'shipping_page' => $page,
            'shipping_per_page' => $per_page,
            'shipping_notice' => 'loaded',
            'shipping_count' => count($result['body']['shippings'] ?? []),
        ]);
    }

    public static function handle_products_action()
    {
        self::require_mapping_capability();
        check_admin_referer('wss_products_action');

        if (WSS_Queue::has_active_write_job()) {
            self::redirect_products(['wss_product_notice' => 'product_job_already_active']);
        }

        $raw_operation = isset($_POST['operation']) ? wp_unslash($_POST['operation']) : '';
        $operation = is_scalar($raw_operation) ? sanitize_key((string) $raw_operation) : '';
        $raw_row_action = isset($_POST['row_action']) ? wp_unslash($_POST['row_action']) : '';
        $row_action = is_scalar($raw_row_action)
            ? sanitize_text_field((string) $raw_row_action)
            : '';
        $single_product_id = 0;
        if (preg_match('/\A(single_map|single_create|single_update|single_unmap|single_delete)\|([0-9]+)\z/', $row_action, $row_match)) {
            $operation = $row_match[1];
            $single_product_id = absint($row_match[2]);
        }
        $selected_product_ids = isset($_POST['selected_products']) &&
            is_array($_POST['selected_products'])
            ? array_values(array_unique(array_filter(array_map(
                static function ($value) {
                    return is_scalar($value) ? absint($value) : 0;
                },
                wp_unslash($_POST['selected_products'])
            ))))
            : [];
        if ($single_product_id > 0) {
            $selected_product_ids = [$single_product_id];
        }
        if (count($selected_product_ids) > 20) {
            self::redirect_products(['wss_product_notice' => 'product_job_selection_limit']);
        }

        if ($operation === 'bulk_test') {
            if (!$selected_product_ids) {
                self::redirect_products(['wss_product_notice' => 'product_test_selection_required']);
            }
            $results = self::run_local_product_write(static function () use ($selected_product_ids) {
                $test_results = [];
                foreach ($selected_product_ids as $product_id) {
                    $test_results[$product_id] = self::test_product_transfer($product_id);
                    $test_results[$product_id]['mode'] = 'validation';
                }
                return $test_results;
            });
            if (is_wp_error($results)) {
                self::redirect_products(['wss_product_notice' => 'product_job_already_active']);
            }
            set_transient(
                self::get_product_test_results_transient_key(),
                $results,
                15 * MINUTE_IN_SECONDS
            );
            $has_errors = count(array_filter($results, static function ($result) {
                return empty($result['success']);
            })) > 0;
            self::redirect_products([
                'wss_product_notice' => $has_errors
                    ? 'product_bulk_validation_finished_with_errors'
                    : 'product_test_finished',
            ]);
        }

        if (!$selected_product_ids) {
            self::redirect_products(['wss_product_notice' => 'product_test_selection_required']);
        }

        if ($operation === 'single_delete') {
            $delete_mode = isset($_POST['delete_mode'])
                ? sanitize_key(wp_unslash($_POST['delete_mode']))
                : '';
            if (!in_array($delete_mode, ['wc', 'both'], true) || !$single_product_id) {
                self::redirect_products(['wss_product_notice' => 'product_action_invalid']);
            }
            $result = self::run_local_product_write(static function () use ($single_product_id, $delete_mode) {
                $product = wc_get_product($single_product_id);
                if (!$product) {
                    return new WP_Error('wss_delete_missing', 'Ürün bulunamadı.');
                }
                $shopier_id = WSS_Product_Sync::get_existing_shopier_product_id($single_product_id);
                if ($delete_mode === 'both' && $shopier_id) {
                    $response = WSS_Shopier_API::delete_product($shopier_id);
                    $status = (int) ($response['status'] ?? 0);
                    // 404: Shopier ürünü zaten yok; yerel silmeye devam edilebilir.
                    if (empty($response['success']) && $status !== 404) {
                        return new WP_Error('wss_shopier_delete_failed', 'shopier');
                    }
                }
                $unmapped = self::remove_product_mapping($single_product_id);
                if (is_wp_error($unmapped)) {
                    return $unmapped;
                }
                if (!wp_trash_post($single_product_id)) {
                    return new WP_Error('wss_wc_trash_failed', 'wc');
                }
                return true;
            });
            $notice = 'product_deleted_wc';
            if (is_wp_error($result)) {
                $notice = $result->get_error_code() === 'wss_shopier_delete_failed'
                    ? 'product_delete_shopier_failed'
                    : 'product_delete_failed';
            } elseif ($delete_mode === 'both') {
                $notice = 'product_deleted_both';
            }
            self::redirect_products(['wss_product_notice' => $notice]);
        }

        if (in_array($operation, ['single_map', 'bulk_map'], true)) {
            $catalog = get_transient(self::get_shopier_products_catalog_transient_key());
            $catalog_ids = is_array($catalog)
                ? array_map(static function ($product) {
                    return (string) ($product['id'] ?? '');
                }, $catalog)
                : [];
            $shopier_ids = [];
            foreach ($selected_product_ids as $product_id) {
                $raw_shopier_id = isset($_POST['shopier_product_id'][$product_id])
                    ? wp_unslash($_POST['shopier_product_id'][$product_id])
                    : '';
                $shopier_product_id = is_scalar($raw_shopier_id)
                    ? sanitize_text_field((string) $raw_shopier_id)
                    : '';
                if (
                    $shopier_product_id === '' ||
                    !in_array($shopier_product_id, $catalog_ids, true) ||
                    WSS_Product_Sync::get_existing_shopier_product_id($product_id)
                ) {
                    self::redirect_products(['wss_product_notice' => 'shopier_product_selection_invalid']);
                }
                $shopier_ids[$product_id] = $shopier_product_id;
            }
            $result = self::run_local_product_write(static function () use ($shopier_ids) {
                foreach ($shopier_ids as $product_id => $shopier_product_id) {
                    $saved = WSS_Product_Sync::save_manual_mapping($product_id, $shopier_product_id);
                    if (is_wp_error($saved)) {
                        return $saved;
                    }
                }
                return true;
            });
            self::redirect_products([
                'wss_product_notice' => is_wp_error($result)
                    ? 'product_mapping_save_failed'
                    : 'product_mapping_saved',
                'product_status' => 'matched',
            ]);
        }

        if (in_array($operation, ['single_create', 'bulk_create'], true)) {
            foreach ($selected_product_ids as $product_id) {
                if (WSS_Product_Sync::get_existing_shopier_product_id($product_id)) {
                    self::redirect_products(['wss_product_notice' => 'product_create_requires_unmatched']);
                }
            }
            $job_id = self::enqueue_product_job(
                'product_create',
                $selected_product_ids
            );
            self::redirect_products([
                'wss_product_notice' => is_wp_error($job_id)
                    ? 'product_job_start_failed'
                    : 'product_job_started',
                'wss_job_id' => is_wp_error($job_id) ? '' : $job_id,
                'product_status' => 'unmatched',
            ]);
        }

        if (in_array($operation, ['single_update', 'bulk_update'], true)) {
            foreach ($selected_product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product || !WSS_Product_Sync::get_existing_shopier_product_id($product_id)) {
                    self::redirect_products(['wss_product_notice' => 'product_update_requires_matched']);
                }
            }
            $job_id = self::enqueue_product_job('product_update', $selected_product_ids);
            self::redirect_products([
                'wss_product_notice' => is_wp_error($job_id)
                    ? 'product_job_start_failed'
                    : 'product_job_started',
                'wss_job_id' => is_wp_error($job_id) ? '' : $job_id,
                'product_status' => 'matched',
            ]);
        }

        if (in_array($operation, ['single_unmap', 'bulk_unmap'], true)) {
            $result = self::run_local_product_write(static function () use ($selected_product_ids) {
                foreach ($selected_product_ids as $product_id) {
                    $unmapped = self::remove_product_mapping($product_id);
                    if (is_wp_error($unmapped)) {
                        return $unmapped;
                    }
                }
                return true;
            });
            self::redirect_products([
                'wss_product_notice' => is_wp_error($result)
                    ? 'product_mapping_remove_failed'
                    : 'product_mapping_removed',
                'product_status' => 'matched',
            ]);
        }

        self::redirect_products(['wss_product_notice' => 'product_action_invalid']);
    }

    public static function handle_sync_shopier_stocks()
    {
        self::require_mapping_capability();
        check_admin_referer('wss_sync_shopier_stocks');

        global $wpdb;
        $product_map = $wpdb->prefix . 'wss_product_map';
        $variation_map = $wpdb->prefix . 'wss_variation_map';
        $shopier_ids = $wpdb->get_col(
            "SELECT shopier_product_id FROM `{$product_map}` WHERE sync_status = 'active'
            UNION SELECT shopier_product_id FROM `{$variation_map}` WHERE sync_status = 'active'"
        );
        $shopier_ids = array_values(array_unique(array_filter(array_map(static function ($id) {
            $id = trim((string) $id);
            return preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $id) ? $id : '';
        }, is_array($shopier_ids) ? $shopier_ids : []))));

        if (!$shopier_ids) {
            self::redirect_products(['wss_product_notice' => 'stock_sync_no_mappings']);
        }

        $queued = 0;
        foreach ($shopier_ids as $index => $shopier_id) {
            $hook = 'wss_process_shopier_stock_webhook';
            $args = [$shopier_id];
            if (!wp_next_scheduled($hook, $args) && wp_schedule_single_event(time() + 1 + $index, $hook, $args)) {
                $queued++;
            }
        }

        update_option('wss_last_manual_stock_refresh', [
            'requested_at' => current_time('mysql'),
            'queued_count' => $queued,
            'mapped_count' => count($shopier_ids),
        ], false);
        self::redirect_products([
            'wss_product_notice' => $queued > 0 ? 'stock_sync_queued' : 'stock_sync_already_queued',
            'wss_stock_queued' => $queued,
            'product_status' => 'matched',
        ]);
    }

    public static function run_product_update_job($product_id)
    {
        return self::update_product_transfer($product_id);
    }

    public static function handle_export_products_csv()
    {
        self::require_mapping_capability();
        check_admin_referer('wss_export_products_csv');

        $status = isset($_POST['product_status'])
            ? sanitize_key(wp_unslash($_POST['product_status']))
            : 'unmatched';
        if (!in_array($status, ['matched', 'unmatched'], true)) {
            $status = 'unmatched';
        }
        $product_type = isset($_POST['wss_product_type'])
            ? sanitize_key(wp_unslash($_POST['wss_product_type']))
            : 'all';
        if (!in_array($product_type, ['all', 'simple', 'variable'], true)) {
            $product_type = 'all';
        }
        $stock_status = isset($_POST['wss_stock_status'])
            ? sanitize_key(wp_unslash($_POST['wss_stock_status']))
            : 'all';
        if (!in_array($stock_status, ['all', 'instock', 'outofstock', 'onbackorder'], true)) {
            $stock_status = 'all';
        }
        $search = isset($_POST['wss_search'])
            ? sanitize_text_field(wp_unslash($_POST['wss_search']))
            : '';

        $query_args = [
            'status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'type' => $product_type === 'all'
                ? ['simple', 'variable', 'grouped', 'external']
                : [$product_type],
            'limit' => 100,
            'page' => 1,
            'paginate' => true,
            'orderby' => 'title',
            'order' => 'ASC',
            'return' => 'objects',
            's' => $search,
        ];
        if ($stock_status !== 'all') {
            $query_args['stock_status'] = $stock_status;
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=woo-shopier-product-mappings.csv');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['wc_product_id', 'wc_variation_id', 'sku', 'product_name', 'shopier_product_url']);

        do {
            $result = wc_get_products($query_args);
            foreach ($result->products as $product) {
                $mapping = self::get_product_mapping($product->get_id());
                if (
                    ($status === 'matched' && !$mapping) ||
                    ($status === 'unmatched' && $mapping)
                ) {
                    continue;
                }
                $url = $mapping && !empty($mapping->shopier_product_id)
                    ? WSS_Product_Sync::get_shopier_product_url($mapping->shopier_product_id)
                    : (string) $product->get_meta('_wss_shopier_product_url', true);
                fputcsv($output, [
                    (string) $product->get_id(),
                    '',
                    self::csv_safe_cell((string) $product->get_sku()),
                    self::csv_safe_cell((string) $product->get_name()),
                    $url,
                ]);
            }
            $query_args['page']++;
        } while ($query_args['page'] <= (int) $result->max_num_pages);

        fclose($output);
        exit;
    }

    public static function handle_import_products_csv()
    {
        self::require_mapping_capability();
        check_admin_referer('wss_import_products_csv');

        if (!WSS_Queue::acquire_write_lock()) {
            self::redirect_products(['wss_product_notice' => 'product_job_already_active']);
        }
        if (WSS_Queue::has_active_write_job()) {
            WSS_Queue::release_write_lock();
            self::redirect_products(['wss_product_notice' => 'product_job_already_active']);
        }

        $report = [];
        $file = $_FILES['wss_mapping_csv'] ?? null;
        if (
            !is_array($file) ||
            (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            empty($file['tmp_name']) ||
            !is_string($file['tmp_name']) ||
            (int) ($file['size'] ?? 0) <= 0 ||
            (int) $file['size'] > 2 * MB_IN_BYTES ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            $report[] = ['line' => 0, 'wc_product_id' => '', 'status' => 'error', 'message' => 'CSV dosyası seçin (en fazla 2 MB).'];
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                $report[] = ['line' => 0, 'wc_product_id' => '', 'status' => 'error', 'message' => 'CSV dosyası okunamadı.'];
            } else {
                $header = fgetcsv($handle);
                $header = is_array($header)
                    ? array_map(static function ($value) {
                        return strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $value)));
                    }, $header)
                    : [];
                $wc_id_column = array_search('wc_product_id', $header, true);
                $variation_id_column = array_search('wc_variation_id', $header, true);
                $url_column = array_search('shopier_product_url', $header, true);
                $legacy_id_column = array_search('shopier_product_id', $header, true);
                if ($wc_id_column === false || ($url_column === false && $legacy_id_column === false)) {
                    $report[] = [
                        'line' => 1,
                        'wc_product_id' => '',
                        'status' => 'error',
                        'message' => 'CSV başlığında wc_product_id ve shopier_product_url sütunları bulunmalıdır (eski shopier_product_id sütunu da kabul edilir).',
                    ];
                } else {
                    $seen_products = [];
                    $line = 1;
                    while (($row = fgetcsv($handle)) !== false) {
                        $line++;
                        if ($line > 501) {
                            $report[] = [
                                'line' => $line,
                                'wc_product_id' => '',
                                'status' => 'error',
                                'message' => 'Bir CSV işleminde en fazla 500 veri satırı kullanılabilir.',
                            ];
                            break;
                        }
                        $product_id = isset($row[$wc_id_column]) ? absint($row[$wc_id_column]) : 0;
                        $variation_id = $variation_id_column !== false && isset($row[$variation_id_column])
                            ? absint($row[$variation_id_column])
                            : 0;
                        $url = $url_column !== false && isset($row[$url_column])
                            ? WSS_Shopier_Purchase::sanitize_shopier_url((string) $row[$url_column])
                            : '';
                        if ($url === '' && $legacy_id_column !== false && isset($row[$legacy_id_column])) {
                            $legacy_id = trim(sanitize_text_field((string) $row[$legacy_id_column]));
                            $url = WSS_Product_Sync::get_shopier_product_url($legacy_id);
                        }
                        if ($product_id <= 0 || $url === '') {
                            $report[] = [
                                'line' => $line,
                                'wc_product_id' => $product_id ?: '',
                                'status' => 'error',
                                'message' => 'WooCommerce ürün/varyasyon ID’si veya geçerli Shopier ürün bağlantısı eksik.',
                            ];
                            continue;
                        }
                        $row_key = $variation_id > 0 ? 'v:' . $variation_id : 'p:' . $product_id;
                        if (isset($seen_products[$row_key])) {
                            $report[] = [
                                'line' => $line,
                                'wc_product_id' => $product_id,
                                'status' => 'error',
                                'message' => 'Aynı WooCommerce ürün ID’si CSV’de birden fazla kez bulunuyor.',
                            ];
                            continue;
                        }
                        $seen_products[$row_key] = true;
                        $product = wc_get_product($product_id);
                        $variation = $variation_id > 0 ? wc_get_product($variation_id) : false;
                        if (!$product || !$product->is_type(['simple', 'variable']) || $variation_id > 0) {
                            $report[] = [
                                'line' => $line,
                                'wc_product_id' => $product_id,
                                'status' => 'error',
                                'message' => 'WooCommerce ürün/varyasyon ID’si geçerli değil veya değişken ürün satırında varyasyon ID’si eksik.',
                            ];
                            continue;
                        }
                        $url_parts = wp_parse_url($url);
                        $url_path = trim((string) ($url_parts['path'] ?? ''), '/');
                        if (!preg_match('~(?:^|/)zevrakdesign/([A-Za-z0-9_-]+)$~', $url_path, $id_match)) {
                            $report[] = ['line' => $line, 'wc_product_id' => $product_id, 'status' => 'error', 'message' => 'Shopier bağlantısından ürün ID’si okunamadı.'];
                            continue;
                        }
                        $saved = WSS_Product_Sync::save_manual_mapping($product_id, $id_match[1]);
                        $report[] = [
                            'line' => $line,
                            'wc_product_id' => $product_id,
                            'status' => is_wp_error($saved) ? 'error' : 'success',
                            'message' => is_wp_error($saved)
                                ? $saved->get_error_message()
                                : 'Shopier ürün bağlantısı yerel ürüne kaydedildi; Shopier kataloğunda değişiklik yapılmadı.',
                        ];
                    }
                }
                fclose($handle);
            }
        }

        WSS_Queue::release_write_lock();
        set_transient(
            self::get_product_csv_report_transient_key(),
            $report,
            HOUR_IN_SECONDS
        );
        self::redirect_products(['wss_product_notice' => 'product_csv_import_finished']);
    }

    private static function csv_safe_cell($value)
    {
        return preg_match('/^[\s]*[=+\-@]/', $value) ? "'" . $value : $value;
    }

    private static function get_product_csv_report_transient_key()
    {
        return 'wss_product_csv_report_' . get_current_user_id();
    }

    private static function enqueue_product_job($job_type, $product_ids, $job_data = [])
    {
        $result = WSS_Queue::enqueue_job($job_type, $product_ids, $job_data);
        $error_key = 'wss_product_job_start_error_' . get_current_user_id();
        if (is_wp_error($result)) {
            set_transient($error_key, $result->get_error_message(), 5 * MINUTE_IN_SECONDS);
        } else {
            delete_transient($error_key);
        }

        return $result;
    }

    private static function run_local_product_write($callback)
    {
        if (!WSS_Queue::acquire_write_lock()) {
            return new WP_Error(
                'wss_product_job_lock_unavailable',
                'Başka bir ürün yazma işlemi başlatılıyor veya çalışıyor.'
            );
        }

        try {
            if (WSS_Queue::has_active_write_job()) {
                return new WP_Error(
                    'wss_product_job_already_active',
                    'Aktif ürün job’ı tamamlanmadan başka bir yazma işlemi yapılamaz.'
                );
            }
            return $callback();
        } finally {
            WSS_Queue::release_write_lock();
        }
    }

    private static function remove_product_mapping($product_id)
    {
        global $wpdb;

        $product_id = absint($product_id);
        $product_table = $wpdb->prefix . 'wss_product_map';
        $variation_table = $wpdb->prefix . 'wss_variation_map';
        $items_table = $wpdb->prefix . 'wss_variation_map_items';

        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('wss_mapping_remove_transaction_failed', 'Eşleme silme işlemi başlatılamadı.');
        }

        $items_deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE items
                FROM `{$items_table}` items
                INNER JOIN `{$variation_table}` variations
                    ON variations.id = items.variation_map_id
                WHERE variations.wc_product_id = %d",
                $product_id
            )
        );
        $variations_deleted = $wpdb->delete(
            $variation_table,
            ['wc_product_id' => $product_id],
            ['%d']
        );
        $product_deleted = $wpdb->delete(
            $product_table,
            ['wc_product_id' => $product_id],
            ['%d']
        );

        if ($items_deleted === false || $variations_deleted === false || $product_deleted === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'wss_mapping_remove_failed',
                'Ürün veya varyasyon eşlemeleri silinemedi: ' . $wpdb->last_error
            );
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wss_mapping_remove_commit_failed', 'Eşleme silme işlemi kaydedilemedi.');
        }

        delete_post_meta($product_id, '_wss_shopier_product_url');
        delete_post_meta($product_id, '_wss_shopier_product_id');
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                delete_post_meta($variation_id, '_wss_shopier_product_url');
                delete_post_meta($variation_id, '_wss_shopier_product_id');
            }
        }

        return true;
    }

    public static function handle_save_preview_product_mapping()
    {
        self::require_mapping_capability();

        $product_id = isset($_POST['wc_product_id'])
            ? absint(wp_unslash($_POST['wc_product_id']))
            : 0;
        check_admin_referer('wss_save_preview_product_mapping_' . $product_id);

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            self::redirect_variation_preview($product_id, 'preview_product_invalid');
        }

        $shopier_product_id = isset($_POST['shopier_product_id'])
            ? sanitize_text_field(wp_unslash($_POST['shopier_product_id']))
            : '';
        $result = self::run_local_product_write(static function () use ($product_id, $shopier_product_id) {
            return WSS_Product_Sync::save_manual_mapping($product_id, $shopier_product_id);
        });

        self::redirect_variation_preview(
            $product_id,
            is_wp_error($result) ? 'preview_product_mapping_error' : 'preview_product_mapping_saved'
        );
    }

    public static function handle_load_shopier_variation_comparison()
    {
        self::require_mapping_capability();

        $product_id = isset($_POST['wc_product_id'])
            ? absint(wp_unslash($_POST['wc_product_id']))
            : 0;
        check_admin_referer('wss_load_shopier_variation_comparison_' . $product_id);

        $product = wc_get_product($product_id);
        $shopier_product_id = WSS_Product_Sync::get_existing_shopier_product_id($product_id);
        if (!$product || !$product->is_type('variable') || empty($shopier_product_id)) {
            self::redirect_variation_preview($product_id, 'preview_product_mapping_required');
        }

        $response = WSS_Shopier_API::get_product($shopier_product_id);
        if (empty($response['success']) || !is_array($response['body'] ?? null)) {
            self::redirect_variation_preview(
                $product_id,
                'shopier_variation_load_failed',
                absint($response['status'] ?? 0)
            );
        }

        $catalog = self::parse_shopier_product_variations(
            $response['body'],
            (string) $shopier_product_id
        );
        if (is_wp_error($catalog)) {
            self::redirect_variation_preview($product_id, 'shopier_variation_data_invalid');
        }

        set_transient(
            self::get_shopier_variations_transient_key($product_id),
            $catalog,
            HOUR_IN_SECONDS
        );
        self::redirect_variation_preview($product_id, 'shopier_variations_loaded');
    }

    public static function handle_save_product_variation_mappings()
    {
        self::require_mapping_capability();

        $product_id = isset($_POST['wc_product_id'])
            ? absint(wp_unslash($_POST['wc_product_id']))
            : 0;
        check_admin_referer('wss_save_product_variation_mappings_' . $product_id);

        $product = wc_get_product($product_id);
        $shopier_product_id = WSS_Product_Sync::get_existing_shopier_product_id($product_id);
        if (!$product || !$product->is_type('variable') || empty($shopier_product_id)) {
            self::redirect_variation_preview($product_id, 'preview_product_mapping_required');
        }

        $response = WSS_Shopier_API::get_product($shopier_product_id);
        if (empty($response['success']) || !is_array($response['body'] ?? null)) {
            self::redirect_variation_preview(
                $product_id,
                'shopier_variation_load_failed',
                absint($response['status'] ?? 0)
            );
        }

        $shopier_catalog = self::parse_shopier_product_variations(
            $response['body'],
            (string) $shopier_product_id
        );
        if (is_wp_error($shopier_catalog)) {
            self::redirect_variation_preview($product_id, 'shopier_variation_data_invalid');
        }

        $comparison = self::compare_product_variations($product, $shopier_catalog);
        if (empty($comparison['complete'])) {
            self::redirect_variation_preview($product_id, 'variation_mapping_comparison_incomplete');
        }

        $saved = self::run_local_product_write(static function () use ($product_id, $shopier_catalog) {
            return WSS_Variation_Map::save_product_from_global_mappings(
                $product_id,
                array_column($shopier_catalog['variants'], 'selection_signature')
            );
        });
        if (is_wp_error($saved)) {
            self::redirect_variation_preview(
                $product_id,
                'variation_mapping_save_error'
            );
        }

        self::redirect_variation_preview($product_id, 'variation_mapping_save_success');
    }

    public static function handle_load_global_variation_catalog()
    {
        self::require_mapping_capability();
        check_admin_referer('wss_load_global_variation_catalog');

        $result = WSS_Shopier_API::get_global_variation_catalog();
        if (empty($result['success']) || !is_array($result['body'] ?? null)) {
            self::redirect_global_mappings(
                'global_catalog_load_failed',
                absint($result['status'] ?? 0)
            );
        }

        set_transient(
            self::get_global_catalog_transient_key(),
            $result['body'],
            HOUR_IN_SECONDS
        );
        self::redirect_global_mappings('global_catalog_loaded');
    }

    public static function handle_save_global_variation_mappings()
    {
        self::require_mapping_capability();
        check_admin_referer('wss_save_global_variation_mappings');

        $catalog = get_transient(self::get_global_catalog_transient_key());
        if (!is_array($catalog)) {
            self::redirect_global_mappings('global_catalog_required');
        }

        $groups = [];
        foreach ($catalog as $group) {
            if (is_array($group) && !empty($group['id'])) {
                $groups[(string) $group['id']] = $group;
            }
        }

        $posted_groups = isset($_POST['group_mapping']) && is_array($_POST['group_mapping'])
            ? wp_unslash($_POST['group_mapping'])
            : [];
        $posted_selections = isset($_POST['selection_mapping']) && is_array($_POST['selection_mapping'])
            ? wp_unslash($_POST['selection_mapping'])
            : [];

        $chosen = [];
        $used_groups = [];
        foreach ($posted_groups as $raw_taxonomy => $raw_group_id) {
            $taxonomy = WSS_Attribute_Map::normalize_attribute_name($raw_taxonomy);
            if (!is_scalar($raw_group_id)) {
                self::redirect_global_mappings('global_mapping_error');
            }
            $group_id = trim(sanitize_text_field((string) $raw_group_id));

            if ($group_id === '') {
                continue;
            }
            if (!taxonomy_exists($taxonomy) || !isset($groups[$group_id])) {
                self::redirect_global_mappings('global_mapping_error');
            }
            if (isset($used_groups[$group_id])) {
                self::redirect_global_mappings('global_mapping_error');
            }

            $used_groups[$group_id] = true;
            $chosen[$taxonomy] = [
                'group' => $groups[$group_id],
                'selections' => isset($posted_selections[$taxonomy]) && is_array($posted_selections[$taxonomy])
                    ? $posted_selections[$taxonomy]
                    : [],
            ];
        }

        if (empty($chosen)) {
            self::redirect_global_mappings('global_mapping_none');
        }

        $saved = 0;
        $errors = 0;
        $write_result = self::run_local_product_write(static function () use ($chosen, &$saved, &$errors) {
            foreach ($chosen as $taxonomy => $mapping) {
                $result = WSS_Attribute_Map::replace_global_attribute_mappings(
                    $taxonomy,
                    $mapping['group'],
                    $mapping['selections']
                );
                if (is_wp_error($result)) {
                    $errors++;
                } else {
                    $saved += (int) $result;
                }
            }
            return true;
        });
        if (is_wp_error($write_result)) {
            self::redirect_global_mappings('global_mapping_error');
        }

        if ($errors > 0) {
            self::redirect_global_mappings(
                $saved > 0 ? 'global_mapping_partial' : 'global_mapping_error'
            );
        }

        self::redirect_global_mappings('global_mapping_saved');
    }

    public static function handle_shopier_connection_test()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Bu işlemi yapma yetkiniz yok.');
        }

        check_admin_referer('wss_test_shopier_connection');

        $result = WSS_Shopier_API::test_connection();
        $query_args = [
            'page' => self::PAGE_SLUG,
            'tab' => 'settings',
            'wss_notice' => !empty($result['success'])
                ? 'shopier_connection_ok'
                : 'shopier_connection_failed',
        ];

        if (isset($result['status'])) {
            $query_args['wss_http_status'] = absint($result['status']);
        }

        wp_safe_redirect(add_query_arg($query_args, admin_url('admin.php')));
        exit;
    }

    public static function handle_save_shopier_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Bu işlemi yapma yetkiniz yok.');
        }

        check_admin_referer('wss_save_shopier_settings');

        $secret_fields = [
            'wss_shopier_pat',
            'wss_shopier_client_secret',
            'wss_shopier_webhook_token',
            'wss_shopier_webhook_product_updated_token',
        ];
        foreach ($secret_fields as $option) {
            $value = isset($_POST[$option]) && is_scalar($_POST[$option])
                ? trim((string) wp_unslash($_POST[$option]))
                : '';
            if ($value !== '') {
                update_option($option, $value, false);
            }
        }

        $text_fields = [
            'wss_shopier_client_id' => 'sanitize_text_field',
            'wss_shopier_api_base_url' => 'esc_url_raw',
        ];
        foreach ($text_fields as $option => $sanitizer) {
            if (!isset($_POST[$option]) || !is_scalar($_POST[$option])) {
                continue;
            }
            $value = trim((string) wp_unslash($_POST[$option]));
            if ($option === 'wss_shopier_client_id') {
                update_option($option, call_user_func($sanitizer, $value), false);
            } elseif ($option === 'wss_shopier_api_base_url' && !WSS_Shopier_API::is_allowed_base_url($value)) {
                wp_safe_redirect(add_query_arg([
                    'page' => self::PAGE_SLUG,
                    'tab' => 'settings',
                    'wss_notice' => 'shopier_api_url_invalid',
                ], admin_url('admin.php')));
                exit;
            } elseif ($value === '') {
                delete_option($option);
            } else {
                update_option($option, untrailingslashit(call_user_func($sanitizer, $value)), false);
            }
        }

        wp_safe_redirect(add_query_arg([
            'page' => self::PAGE_SLUG,
            'tab' => 'settings',
            'wss_notice' => 'shopier_settings_saved',
        ], admin_url('admin.php')));
        exit;
    }

    public static function handle_save_shopier_product_mapping()
    {
        self::require_mapping_capability();

        $product_id = isset($_POST['wc_product_id'])
            ? absint(wp_unslash($_POST['wc_product_id']))
            : 0;
        $variation_id = isset($_POST['wc_variation_id'])
            ? absint(wp_unslash($_POST['wc_variation_id']))
            : 0;
        check_admin_referer('wss_save_shopier_product_mapping_' . $product_id);

        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if (
                !$variation ||
                !$variation->is_type('variation') ||
                absint($variation->get_parent_id()) !== $product_id
            ) {
                $variation_id = 0;
            }
        }

        $shopier_product_id = isset($_POST['shopier_product_id'])
            ? sanitize_text_field(wp_unslash($_POST['shopier_product_id']))
            : '';

        $result = self::run_local_product_write(static function () use ($product_id, $shopier_product_id) {
            return WSS_Product_Sync::save_manual_mapping($product_id, $shopier_product_id);
        });

        self::redirect_variations(
            $product_id,
            is_wp_error($result) ? 'product_mapping_error' : 'product_mapping_saved',
            0,
            $variation_id
        );
    }

    public static function handle_load_shopier_product()
    {
        self::require_mapping_capability();

        $product_id = isset($_POST['wc_product_id'])
            ? absint(wp_unslash($_POST['wc_product_id']))
            : 0;
        $variation_id = isset($_POST['wc_variation_id'])
            ? absint(wp_unslash($_POST['wc_variation_id']))
            : 0;
        check_admin_referer('wss_load_shopier_product_' . $product_id);

        $product = wc_get_product($product_id);
        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if (
                !$variation ||
                !$variation->is_type('variation') ||
                absint($variation->get_parent_id()) !== $product_id
            ) {
                $variation_id = 0;
            }
        }

        $shopier_product_id = WSS_Product_Sync::get_existing_shopier_product_id(
            $product_id
        );

        if (!$product || !$product->is_type('variable') || empty($shopier_product_id)) {
            self::redirect_variations($product_id, 'product_mapping_required', 0, $variation_id);
        }

        $response = WSS_Shopier_API::get_product($shopier_product_id);

        if (empty($response['success']) || !is_array($response['body'])) {
            self::redirect_variations(
                $product_id,
                'shopier_product_load_failed',
                isset($response['status']) ? absint($response['status']) : 0,
                $variation_id
            );
        }

        $catalog = self::parse_shopier_product_catalog(
            $response['body'],
            (string) $shopier_product_id
        );

        if (is_wp_error($catalog)) {
            self::redirect_variations($product_id, 'shopier_product_data_invalid', 0, $variation_id);
        }

        set_transient(
            self::get_shopier_catalog_transient_key($product_id),
            $catalog,
            HOUR_IN_SECONDS
        );

        self::redirect_variations($product_id, 'shopier_product_loaded', 0, $variation_id);
    }

    public static function handle_save_attribute_mappings()
    {
        self::require_mapping_capability();

        $product_id = isset($_POST['wc_product_id'])
            ? absint(wp_unslash($_POST['wc_product_id']))
            : 0;
        $variation_id = isset($_POST['wc_variation_id'])
            ? absint(wp_unslash($_POST['wc_variation_id']))
            : 0;
        check_admin_referer('wss_save_attribute_mappings_' . $product_id);

        $product = wc_get_product($product_id);
        $selected_variation = $variation_id ? wc_get_product($variation_id) : false;
        $catalog = get_transient(self::get_shopier_catalog_transient_key($product_id));
        $shopier_product_id = WSS_Product_Sync::get_existing_shopier_product_id(
            $product_id
        );

        if (
            !$product ||
            !$product->is_type('variable') ||
            ($variation_id && (
                !$selected_variation ||
                !$selected_variation->is_type('variation') ||
                absint($selected_variation->get_parent_id()) !== $product_id
            )) ||
            !is_array($catalog) ||
            empty($catalog['groups']) ||
            (string) ($catalog['product_id'] ?? '') !== (string) $shopier_product_id
        ) {
            self::redirect_variations($product_id, 'shopier_catalog_required', 0, $variation_id);
        }

        $attribute_options = self::get_product_attribute_options($product);
        $posted_mappings = isset($_POST['selection_mapping']) &&
            is_array($_POST['selection_mapping'])
            ? wp_unslash($_POST['selection_mapping'])
            : [];
        $errors = 0;
        $saved = 0;
        $write_result = self::run_local_product_write(static function () use (
            $catalog,
            $posted_mappings,
            $attribute_options,
            &$errors,
            &$saved
        ) {
            foreach ($catalog['groups'] as $group_id => $group) {
                foreach ($group['selections'] as $selection_id => $selection_title) {
                    $option_key = isset($posted_mappings[$group_id]) &&
                        is_array($posted_mappings[$group_id]) &&
                        isset($posted_mappings[$group_id][$selection_id])
                        ? sanitize_key($posted_mappings[$group_id][$selection_id])
                        : '';

                    if ($option_key === '') {
                        continue;
                    }

                    if (!isset($attribute_options[$option_key])) {
                        $errors++;
                        continue;
                    }

                    $option = $attribute_options[$option_key];
                    $result = WSS_Attribute_Map::save([
                        'shopier_group_id' => $group_id,
                        'shopier_group_title' => $group['title'],
                        'shopier_selection_id' => $selection_id,
                        'shopier_selection_title' => $selection_title,
                        'wc_scope_id' => $option['scope_id'],
                        'wc_attribute' => $option['attribute'],
                        'wc_value' => $option['value'],
                    ]);

                    if (is_wp_error($result)) {
                        $errors++;
                    } else {
                        $saved++;
                    }
                }
            }
            return true;
        });
        if (is_wp_error($write_result)) {
            self::redirect_variations($product_id, 'attribute_mapping_error', 0, $variation_id);
        }

        if ($errors > 0) {
            self::redirect_variations(
                $product_id,
                $saved > 0 ? 'attribute_mapping_partial' : 'attribute_mapping_error',
                0,
                $variation_id
            );
        }

        self::redirect_variations(
            $product_id,
            $saved > 0 ? 'attribute_mapping_saved' : 'attribute_mapping_none',
            0,
            $variation_id
        );
    }

    public static function handle_save_variation_mapping()
    {
        self::require_mapping_capability();

        $product_id = isset($_POST['wc_product_id'])
            ? absint(wp_unslash($_POST['wc_product_id']))
            : 0;
        $variation_id = isset($_POST['wc_variation_id'])
            ? absint(wp_unslash($_POST['wc_variation_id']))
            : 0;
        check_admin_referer(
            'wss_save_variation_mapping_' . $product_id . '_' . $variation_id
        );

        $variation = wc_get_product($variation_id);

        if (!$variation || !$variation->is_type('variation') ||
            absint($variation->get_parent_id()) !== $product_id) {
            self::redirect_variations($product_id, 'variation_mapping_error');
        }

        $mapping_ids = isset($_POST['attribute_map_ids']) &&
            is_array($_POST['attribute_map_ids'])
            ? array_map('absint', wp_unslash($_POST['attribute_map_ids']))
            : [];
        $result = self::run_local_product_write(static function () use ($variation_id, $mapping_ids) {
            return WSS_Variation_Map::save_for_variation($variation_id, $mapping_ids);
        });

        self::redirect_variations(
            $product_id,
            is_wp_error($result) ? 'variation_mapping_error' : 'variation_mapping_saved',
            0,
            $variation_id
        );
    }

    private static function require_mapping_capability()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Bu işlemi yapma yetkiniz yok.');
        }
    }

    private static function redirect_variations(
        $product_id,
        $notice,
        $http_status = 0,
        $variation_id = 0
    )
    {
        $query_args = [
            'page' => self::PAGE_SLUG,
            'tab' => 'variations',
            'wss_variation_product' => absint($product_id),
            'wss_mapping_notice' => sanitize_key($notice),
        ];

        if ($variation_id > 0) {
            $query_args['wss_variation_id'] = absint($variation_id);
        }

        if ($http_status > 0) {
            $query_args['wss_mapping_http_status'] = $http_status;
        }

        wp_safe_redirect(
            add_query_arg($query_args, admin_url('admin.php'))
        );
        exit;
    }

    private static function redirect_global_mappings($notice, $http_status = 0)
    {
        $query_args = [
            'page' => self::PAGE_SLUG,
            'tab' => 'variations',
            'wss_mapping_notice' => sanitize_key($notice),
        ];
        if ($http_status > 0) {
            $query_args['wss_mapping_http_status'] = $http_status;
        }

        wp_safe_redirect(add_query_arg($query_args, admin_url('admin.php')));
        exit;
    }

    private static function redirect_variation_preview(
        $product_id,
        $notice,
        $http_status = 0
    ) {
        $query_args = [
            'page' => self::PAGE_SLUG,
            'tab' => 'variations',
            'wss_preview_product_id' => absint($product_id),
            'wss_mapping_notice' => sanitize_key($notice),
        ];
        if ($http_status > 0) {
            $query_args['wss_mapping_http_status'] = $http_status;
        }

        wp_safe_redirect(add_query_arg($query_args, admin_url('admin.php')));
        exit;
    }

    private static function redirect_products($query_args = [])
    {
        $preserved = [
            'page' => self::PAGE_SLUG,
            'tab' => 'products',
            'product_status' => isset($_POST['product_status'])
                ? sanitize_key(wp_unslash($_POST['product_status']))
                : 'unmatched',
            'wss_search' => isset($_POST['wss_search'])
                ? sanitize_text_field(wp_unslash($_POST['wss_search']))
                : '',
            'wss_product_type' => isset($_POST['wss_product_type'])
                ? sanitize_key(wp_unslash($_POST['wss_product_type']))
                : 'all',
            'wss_stock_status' => isset($_POST['wss_stock_status'])
                ? sanitize_key(wp_unslash($_POST['wss_stock_status']))
                : 'all',
            'wss_product_page' => isset($_POST['wss_product_page'])
                ? max(1, absint(wp_unslash($_POST['wss_product_page'])))
                : 1,
        ];
        wp_safe_redirect(
            add_query_arg(array_merge($preserved, $query_args), admin_url('admin.php'))
        );
        exit;
    }

    private static function redirect_orders($query_args = [])
    {
        $preserved = [
            'page' => self::PAGE_SLUG,
            'tab' => 'orders',
            'order_search' => isset($_POST['order_search']) ? sanitize_text_field(wp_unslash($_POST['order_search'])) : '',
            'order_status' => isset($_POST['order_status']) ? sanitize_key(wp_unslash($_POST['order_status'])) : 'all',
            'payment_status' => isset($_POST['payment_status']) ? sanitize_key(wp_unslash($_POST['payment_status'])) : 'all',
            'order_date_from' => isset($_POST['order_date_from']) ? sanitize_text_field(wp_unslash($_POST['order_date_from'])) : '',
            'order_date_to' => isset($_POST['order_date_to']) ? sanitize_text_field(wp_unslash($_POST['order_date_to'])) : '',
            'order_page' => isset($_POST['order_page']) ? max(1, absint(wp_unslash($_POST['order_page']))) : 1,
            'order_per_page' => isset($_POST['order_per_page']) ? absint(wp_unslash($_POST['order_per_page'])) : 25,
        ];
        wp_safe_redirect(add_query_arg(array_merge($preserved, $query_args), admin_url('admin.php')));
        exit;
    }

    private static function get_shipping_filters($source)
    {
        $source = is_array($source) ? $source : [];
        $filters = [];
        $allowed = [
            'shipping_status' => ['shipped' => 'shipped', 'notShipped' => 'notShipped'],
            'shipping_type' => ['firstShipment' => 'firstShipment', 'secondShipment' => 'secondShipment', 'returnShipment' => 'returnShipment'],
            'shipping_company' => ['yurtici' => 'yurtici', 'mng' => 'mng', 'ptt' => 'ptt'],
        ];
        foreach ($allowed as $field => $options) {
            $value = isset($source[$field]) && is_scalar($source[$field]) ? sanitize_text_field(wp_unslash($source[$field])) : '';
            if (isset($options[$value])) {
                $api_key = str_replace('shipping_', '', $field);
                $filters[$api_key] = $options[$value];
            }
        }
        foreach (['shipping_date_from' => 'dateCreatedStart', 'shipping_date_to' => 'dateCreatedEnd'] as $field => $api_key) {
            $value = isset($source[$field]) && is_scalar($source[$field]) ? sanitize_text_field(wp_unslash($source[$field])) : '';
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
                $filters[$api_key] = $value;
            }
        }
        return $filters;
    }

    private static function redirect_shippings($query_args = [])
    {
        $preserved = [
            'page' => self::PAGE_SLUG,
            'tab' => 'shippings',
            'shipping_search' => isset($_POST['shipping_search']) ? sanitize_text_field(wp_unslash($_POST['shipping_search'])) : '',
            'shipping_status' => isset($_POST['shipping_status']) ? sanitize_text_field(wp_unslash($_POST['shipping_status'])) : 'all',
            'shipping_type' => isset($_POST['shipping_type']) ? sanitize_text_field(wp_unslash($_POST['shipping_type'])) : 'all',
            'shipping_company' => isset($_POST['shipping_company']) ? sanitize_text_field(wp_unslash($_POST['shipping_company'])) : 'all',
            'shipping_date_from' => isset($_POST['shipping_date_from']) ? sanitize_text_field(wp_unslash($_POST['shipping_date_from'])) : '',
            'shipping_date_to' => isset($_POST['shipping_date_to']) ? sanitize_text_field(wp_unslash($_POST['shipping_date_to'])) : '',
            'shipping_page' => isset($_POST['shipping_page']) ? max(1, absint(wp_unslash($_POST['shipping_page']))) : 1,
            'shipping_per_page' => isset($_POST['shipping_per_page']) ? absint(wp_unslash($_POST['shipping_per_page'])) : 25,
        ];
        wp_safe_redirect(add_query_arg(array_merge($preserved, $query_args), admin_url('admin.php')));
        exit;
    }

    private static function get_global_catalog_transient_key()
    {
        return 'wss_global_variation_catalog_' . get_current_user_id();
    }

    private static function get_shopier_products_catalog_transient_key()
    {
        return 'wss_shopier_products_catalog_' . get_current_user_id();
    }

    private static function get_shopier_orders_transient_key($page = 1, $per_page = 25)
    {
        return 'wss_shopier_orders_' . get_current_user_id() . '_' . absint($page) . '_' . absint($per_page);
    }

    private static function get_shopier_shippings_transient_key($page, $per_page, $filters)
    {
        return 'wss_shopier_shippings_' . get_current_user_id() . '_' . absint($page) . '_' . absint($per_page) . '_' . md5(wp_json_encode($filters));
    }

    private static function get_product_test_results_transient_key()
    {
        return 'wss_product_test_results_' . get_current_user_id();
    }

    private static function get_shopier_variations_transient_key($product_id)
    {
        return 'wss_shopier_variations_' . get_current_user_id() . '_' . absint($product_id);
    }

    private static function get_shopier_catalog_transient_key($product_id)
    {
        return 'wss_shopier_catalog_' . get_current_user_id() . '_' . absint($product_id);
    }

    private static function normalize_mapping_candidate($value)
    {
        $value = remove_accents((string) $value);
        $value = strtolower($value);
        return preg_replace('/[^a-z0-9]+/', '', $value);
    }

    private static function get_variation_attribute_display($variation)
    {
        $product = wc_get_product($variation->get_parent_id());
        $display = [];

        foreach ($variation->get_attributes() as $name => $value) {
            $attribute = WSS_Attribute_Map::normalize_attribute_name($name);
            $label = function_exists('wc_attribute_label')
                ? wc_attribute_label($name, $product)
                : $name;
            $value_label = (string) $value;

            if (function_exists('taxonomy_exists') && taxonomy_exists($attribute)) {
                $term = get_term_by('slug', sanitize_title((string) $value), $attribute);

                if ($term && !is_wp_error($term)) {
                    $value_label = (string) $term->name;
                }
            }

            $display[$attribute] = [
                'label' => $label,
                'value' => $value_label,
            ];
        }

        return $display;
    }

    private static function parse_shopier_product_catalog($body, $expected_product_id)
    {
        if (isset($body['product']) && is_array($body['product'])) {
            $body = $body['product'];
        }

        if (
            empty($body['id']) ||
            (string) $body['id'] !== $expected_product_id ||
            !isset($body['variants']) ||
            !is_array($body['variants'])
        ) {
            return new WP_Error(
                'wss_shopier_product_response_invalid',
                'Shopier product response did not contain the expected product and variants.'
            );
        }

        $groups = [];

        foreach ($body['variants'] as $variant) {
            if (!is_array($variant)) {
                return new WP_Error(
                    'wss_shopier_variant_invalid',
                    'Shopier returned an invalid variant record.'
                );
            }

            $group_ids = isset($variant['variationId']) && is_array($variant['variationId'])
                ? $variant['variationId']
                : [];
            $group_titles = isset($variant['variationTitle']) && is_array($variant['variationTitle'])
                ? $variant['variationTitle']
                : [];
            $selection_ids = isset($variant['selectionId']) && is_array($variant['selectionId'])
                ? $variant['selectionId']
                : [];
            $selection_titles = isset($variant['selectionTitle']) && is_array($variant['selectionTitle'])
                ? $variant['selectionTitle']
                : [];

            if (
                count($group_ids) !== count($selection_ids) ||
                count($group_titles) !== count($selection_titles) ||
                count($group_ids) !== count($group_titles)
            ) {
                return new WP_Error(
                    'wss_shopier_variant_ids_incomplete',
                    'Shopier variant group and selection IDs could not be paired safely.'
                );
            }

            foreach ($group_ids as $index => $raw_group_id) {
                $group_id = trim((string) $raw_group_id);
                $selection_id = trim((string) $selection_ids[$index]);
                $group_title = trim((string) $group_titles[$index]);
                $selection_title = trim((string) $selection_titles[$index]);

                if (
                    $group_id === '' ||
                    $selection_id === '' ||
                    strlen($group_id) > 100 ||
                    strlen($selection_id) > 100 ||
                    $group_title === '' ||
                    $selection_title === ''
                ) {
                    return new WP_Error(
                        'wss_shopier_variant_ids_invalid',
                        'Shopier returned an empty or invalid variation/selection ID.'
                    );
                }

                if (!isset($groups[$group_id])) {
                    $groups[$group_id] = [
                        'title' => sanitize_text_field($group_title),
                        'selections' => [],
                    ];
                } elseif ($groups[$group_id]['title'] !== sanitize_text_field($group_title)) {
                    return new WP_Error(
                        'wss_shopier_group_title_conflict',
                        'Shopier returned conflicting titles for the same variation ID.'
                    );
                }

                if (
                    isset($groups[$group_id]['selections'][$selection_id]) &&
                    $groups[$group_id]['selections'][$selection_id] !== sanitize_text_field($selection_title)
                ) {
                    return new WP_Error(
                        'wss_shopier_selection_title_conflict',
                        'Shopier returned conflicting titles for the same selection ID.'
                    );
                }

                $groups[$group_id]['selections'][$selection_id] = sanitize_text_field($selection_title);
            }
        }

        return [
            'product_id' => $expected_product_id,
            'title' => !empty($body['title']) ? sanitize_text_field($body['title']) : '',
            'groups' => $groups,
        ];
    }

    private static function parse_shopier_product_variations($body, $expected_product_id)
    {
        if (isset($body['product']) && is_array($body['product'])) {
            $body = $body['product'];
        }

        if (
            !is_array($body) ||
            !isset($body['id']) ||
            !is_scalar($body['id']) ||
            (string) $body['id'] !== $expected_product_id ||
            !isset($body['variants']) ||
            !is_array($body['variants']) ||
            empty($body['variants'])
        ) {
            return new WP_Error(
                'wss_shopier_product_variants_invalid',
                'Shopier product response did not contain the expected product and variant combinations.'
            );
        }

        $variants = [];
        $signatures = [];
        foreach ($body['variants'] as $variant) {
            if (
                !is_array($variant) ||
                !isset($variant['variationId'], $variant['selectionId']) ||
                !is_array($variant['variationId']) ||
                !is_array($variant['selectionId']) ||
                count($variant['variationId']) === 0 ||
                count($variant['variationId']) !== count($variant['selectionId'])
            ) {
                return new WP_Error(
                    'wss_shopier_product_variant_ids_invalid',
                    'Shopier returned an incomplete group/selection ID combination.'
                );
            }

            $pairs = [];
            $api_selection_ids = [];
            $seen_groups = [];
            foreach ($variant['variationId'] as $index => $raw_group_id) {
                $raw_selection_id = $variant['selectionId'][$index] ?? null;
                if (!is_scalar($raw_group_id) || !is_scalar($raw_selection_id)) {
                    return new WP_Error(
                        'wss_shopier_product_variant_ids_invalid',
                        'Shopier returned a non-scalar group or selection ID.'
                    );
                }

                $group_id = trim((string) $raw_group_id);
                $selection_id = trim((string) $raw_selection_id);
                if (
                    $group_id === '' ||
                    $selection_id === '' ||
                    strlen($group_id) > 100 ||
                    strlen($selection_id) > 100 ||
                    !preg_match('/\A[a-zA-Z0-9_-]+\z/', $group_id) ||
                    !preg_match('/\A[a-zA-Z0-9_-]+\z/', $selection_id) ||
                    isset($seen_groups[$group_id])
                ) {
                    return new WP_Error(
                        'wss_shopier_product_variant_ids_invalid',
                        'Shopier returned invalid IDs or repeated a variation group in a combination.'
                    );
                }

                $seen_groups[$group_id] = true;
                $pairs[] = [$group_id, $selection_id];
                $api_selection_ids[] = $selection_id;
            }

            usort($pairs, static function ($left, $right) {
                return [$left[0], $left[1]] <=> [$right[0], $right[1]];
            });
            $signature = hash('sha256', wp_json_encode($pairs));
            if (isset($signatures[$signature])) {
                return new WP_Error(
                    'wss_shopier_product_duplicate_variant',
                    'Shopier product contains duplicate variation ID combinations.'
                );
            }
            $signatures[$signature] = true;

            $variants[] = [
                'selection_pairs' => $pairs,
                'selection_signature' => $signature,
                'api_selection_ids' => $api_selection_ids,
                'stockQuantity' => $variant['stockQuantity'] ?? null,
                'media' => isset($variant['media']) && is_array($variant['media'])
                    ? $variant['media']
                    : [],
                'primary' => $variant['primary'] ?? null,
            ];
        }

        return [
            'product_id' => $expected_product_id,
            'title' => isset($body['title']) && is_scalar($body['title'])
                ? sanitize_text_field((string) $body['title'])
                : '',
            'variants' => $variants,
        ];
    }

    private static function compare_product_variations($product, $shopier_catalog)
    {
        $shopier_signatures = [];
        foreach ($shopier_catalog['variants'] as $variant) {
            $shopier_signatures[$variant['selection_signature']] = $variant;
        }

        $rows = [];
        $wc_signatures = [];
        $complete = true;
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation')) {
                $complete = false;
                continue;
            }

            $resolved = WSS_Variation_Map::resolve_global_selection_signature(
                $variation_id
            );
            $shopier_variant = false;
            if (is_wp_error($resolved)) {
                $complete = false;
            } else {
                $signature = $resolved['selection_signature'];
                if (isset($wc_signatures[$signature])) {
                    $complete = false;
                }
                $wc_signatures[$signature] = true;
                if (isset($shopier_signatures[$signature])) {
                    $shopier_variant = $shopier_signatures[$signature];
                } else {
                    $complete = false;
                }
            }

            $rows[] = [
                'variation_id' => absint($variation_id),
                'attributes' => self::get_variation_attribute_display($variation),
                'resolved' => $resolved,
                'shopier_variant' => $shopier_variant,
            ];
        }

        if (
            count($rows) !== count($shopier_catalog['variants']) ||
            count($wc_signatures) !== count($shopier_catalog['variants'])
        ) {
            $complete = false;
        }

        return [
            'complete' => $complete,
            'rows' => $rows,
            'shopier_variant_count' => count($shopier_catalog['variants']),
        ];
    }

    private static function update_product_transfer($product_id)
    {
        $product_id = absint($product_id);
        $product = wc_get_product($product_id);
        if (!$product) {
            return [
                'success' => false,
                'message' => 'WooCommerce ürünü bulunamadı.',
            ];
        }

        $shopier_product_id = WSS_Product_Sync::get_existing_shopier_product_id($product_id);
        if (empty($shopier_product_id)) {
            return [
                'success' => false,
                'message' => 'Önce ürün için Shopier ID eşleştirmesini kaydedin.',
            ];
        }
        if (!$product->is_type(['simple', 'variable'])) {
            return [
                'success' => false,
                'message' => 'Gerçek aktarım şu anda yalnızca basit ve değişken ürünleri destekliyor.',
            ];
        }

        $verified_variation_count = 0;
        $comparison_builder = static function ($fresh_product, $remote_body) use (
            $shopier_product_id,
            &$verified_variation_count
        ) {
            if (!$fresh_product->is_type('variable')) {
                return null;
            }

            $catalog = self::parse_shopier_product_variations(
                $remote_body,
                (string) $shopier_product_id
            );
            if (is_wp_error($catalog)) {
                return new WP_Error(
                    'wss_shopier_variation_preflight_failed',
                    'Shopier varyasyon verisi güvenli biçimde çözümlenemedi; hiçbir güncelleme yapılmadı.'
                );
            }

            $comparison = self::compare_product_variations($fresh_product, $catalog);
            if (empty($comparison['complete'])) {
                return new WP_Error(
                    'wss_shopier_variation_preflight_failed',
                    sprintf(
                        'Varyasyon ID kombinasyonları birebir eşleşmiyor (WooCommerce: %d, Shopier: %d); hiçbir güncelleme yapılmadı.',
                        count($comparison['rows']),
                        $comparison['shopier_variant_count']
                    )
                );
            }

            $verified_variation_count = count($comparison['rows']);
            return $comparison;
        };
        $updated = WSS_Product_Sync::update_mapped_product(
            $product_id,
            null,
            $comparison_builder
        );
        if (is_wp_error($updated)) {
            return [
                'success' => false,
                'message' => $updated->get_error_message(),
            ];
        }

        return [
            'success' => true,
            'message' => $product->is_type('variable')
                ? sprintf(
                    '%d WooCommerce varyasyonu Shopier ID kombinasyonlarıyla doğrulandı; eşleşen Shopier ürünü güncellendi ve yeniden okundu.',
                    $verified_variation_count
                )
                : 'Eşleşen Shopier ürünü güncellendi ve yeniden okundu.',
        ];
    }

    private static function test_product_transfer($product_id)
    {
        $product_id = absint($product_id);
        $product = wc_get_product($product_id);
        if (!$product) {
            return [
                'success' => false,
                'message' => 'WooCommerce ürünü bulunamadı.',
            ];
        }

        $shopier_product_id = WSS_Product_Sync::get_existing_shopier_product_id($product_id);
        if (empty($shopier_product_id)) {
            return [
                'success' => false,
                'message' => 'Önce Shopier ürün ID eşleştirmesini kaydedin.',
            ];
        }

        if (!$product->is_type(['simple', 'variable'])) {
            return [
                'success' => false,
                'message' => 'Test aktarımı şu anda basit ve değişken ürünleri destekliyor.',
            ];
        }

        $response = WSS_Shopier_API::get_product($shopier_product_id);
        if (empty($response['success']) || !is_array($response['body'] ?? null)) {
            return [
                'success' => false,
                'message' => 'Shopier ürünü okunamadı.'
                    . (!empty($response['status']) ? ' HTTP ' . absint($response['status']) . '.' : ''),
            ];
        }

        $body = isset($response['body']['product']) &&
            is_array($response['body']['product'])
            ? $response['body']['product']
            : $response['body'];
        if (
            !isset($body['id']) ||
            !is_scalar($body['id']) ||
            (string) $body['id'] !== (string) $shopier_product_id
        ) {
            return [
                'success' => false,
                'message' => 'Shopier ürün yanıtındaki ID, kayıtlı Shopier ürün ID’siyle eşleşmiyor.',
            ];
        }

        if ($product->is_type('simple')) {
            return [
                'success' => true,
                'message' => 'Shopier ürün ID’si doğrulandı. Shopier’e ürün yazma yapılmadı.',
            ];
        }

        $catalog = self::parse_shopier_product_variations(
            $response['body'],
            (string) $shopier_product_id
        );
        if (is_wp_error($catalog)) {
            return [
                'success' => false,
                'message' => 'Shopier varyasyon verisi doğrulanamadı.',
            ];
        }

        $comparison = self::compare_product_variations($product, $catalog);
        if (empty($comparison['complete'])) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Varyasyon ID kombinasyonları birebir eşleşmiyor (WooCommerce: %d, Shopier: %d).',
                    count($comparison['rows']),
                    $comparison['shopier_variant_count']
                ),
            ];
        }

        $saved = WSS_Variation_Map::save_product_from_global_mappings(
            $product_id,
            array_column($catalog['variants'], 'selection_signature')
        );
        if (is_wp_error($saved)) {
            return [
                'success' => false,
                'message' => 'Varyasyon eşleştirmeleri kaydedilmedi: '
                    . $saved->get_error_message(),
            ];
        }

        return [
            'success' => true,
            'message' => sprintf(
                '%d/%d varyasyon ID kombinasyonu doğrulandı ve yerel eşleştirmeler atomik kaydedildi. Shopier’e yazma yapılmadı.',
                count($comparison['rows']),
                $comparison['shopier_variant_count']
            ),
        ];
    }

    private static function render_products_notice()
    {
        if (!isset($_GET['wss_product_notice'])) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['wss_product_notice']));
        $messages = [
            'shopier_products_loaded' => ['success', 'Shopier ürün listesi salt-okunur olarak yüklendi.'],
            'variation_product_mapping_saved' => ['success', 'Varyasyon seçilen mevcut Shopier ürününe bağlandı.'],
            'variation_product_mapping_failed' => ['error', 'Varyasyon Shopier ürününe bağlanamadı. Ürün ve varyasyon ilişkisini kontrol edin.'],
            'variation_mapping_selection_required' => ['warning', 'Önce eşleştirilecek varyasyonlar için Shopier ürün ID’lerini seçin.'],
            'shopier_products_load_failed' => ['error', 'Shopier ürün listesi yüklenemedi. PAT yetkisini ve API yanıtını kontrol edin.'],
            'stock_sync_no_mappings' => ['warning', 'Stok güncellemesi için etkin Shopier ürün eşleştirmesi bulunamadı.'],
            'stock_sync_queued' => ['success', sprintf('%d Shopier ürünü için WooCommerce stok güncellemesi sıraya alındı.', absint($_GET['wss_stock_queued'] ?? 0))],
            'stock_sync_already_queued' => ['info', 'Eşleştirilmiş ürünlerin stok güncellemeleri zaten sırada veya yeni görev eklenemedi. Sonuçları Senkronizasyon Detayları bölümünden kontrol edin.'],
            'shopier_product_selection_invalid' => ['error', 'Seçilen Shopier ürün ID’si yüklenen Shopier ürün listesinde bulunmuyor.'],
            'product_deleted_wc' => ['success', 'Ürün WooCommerce’te çöp kutusuna taşındı ve eşlemeleri kaldırıldı. Shopier ürünü silinmedi.'],
            'product_deleted_both' => ['success', 'Ürün Shopier’den silindi ve WooCommerce’te çöp kutusuna taşındı.'],
            'product_delete_shopier_failed' => ['error', 'Shopier ürünü silinemedi; güvenlik için WooCommerce ürününe dokunulmadı.'],
            'product_delete_failed' => ['error', 'Ürün silinemedi. Lütfen tekrar deneyin.'],
            'product_mapping_saved' => ['success', 'WooCommerce–Shopier ürün ID eşleştirmesi kaydedildi. Bu işlem Shopier’de değişiklik yapmadı.'],
            'product_mapping_save_failed' => ['error', 'Ürün ID eşleştirmesi kaydedilemedi. ID çakışmasını veya mevcut varyasyon eşlemelerini kontrol edin.'],
            'product_test_selection_required' => ['warning', 'Test için en az bir eşleştirilmiş ürün seçin.'],
            'product_test_limit_exceeded' => ['error', 'Bir toplu testte en fazla 20 ürün çalıştırılabilir.'],
            'product_test_finished' => ['success', 'Seçilen ürünlerin Shopier varyasyon ID eşleşmeleri doğrulandı ve yerel veritabanına kaydedildi. Toplu kontrol Shopier ürün verisini değiştirmez.'],
            'product_transfer_finished' => ['success', 'Eşleştirilmiş Shopier ürünü WooCommerce verileriyle güncellendi ve yeniden okunarak doğrulandı.'],
            'product_bulk_validation_finished_with_errors' => ['warning', 'Toplu salt-okunur kontrolde bazı ürünler başarısız oldu. Hata ayrıntılarını işlem durumu sütununda inceleyin; Shopier ürünleri değiştirilmedi.'],
            'product_transfer_finished_with_errors' => ['warning', 'Bazı ürünlerin aktarımı başarısız oldu. Hata ayrıntılarını işlem durumu sütununda inceleyin. Shopier güncellemesinin sonucu belirsizse ürünü tekrar aktarmadan önce Shopier’den kontrol edin.'],
            'product_job_started' => ['success', 'Ürün job’ı sıraya alındı. Tamamlanana kadar başka ürün ekleme, güncelleme veya eşleme işlemi başlatılamaz.'],
            'product_job_start_failed' => ['error', 'Ürün job’ı başlatılamadı. Aktif bir job veya veritabanı hatası olabilir.'],
            'product_job_already_active' => ['warning', 'Aktif ürün job’ı tamamlanana kadar başka bir ürün yazma işlemi başlatılamaz.'],
            'product_job_selection_limit' => ['error', 'Bir job’da en fazla 20 ürün seçilebilir.'],
            'product_create_requires_unmatched' => ['error', 'Shopier’de yeni ürün oluşturmak için WooCommerce ürünü eşleştirilmemiş olmalıdır.'],
            'product_update_requires_matched' => ['error', 'Güncelleme için seçilen tüm WooCommerce ürünlerinin Shopier eşlemesi olmalıdır.'],
            'product_mapping_removed' => ['success', 'Ürün ve bağlı varyasyon eşlemeleri kaldırıldı. WooCommerce ve Shopier ürünleri silinmedi.'],
            'product_mapping_remove_failed' => ['error', 'Ürün eşleştirmesi kaldırılamadı. Veritabanı hatasını kontrol edin.'],
            'product_csv_import_finished' => ['info', 'CSV sonucu aşağıdaki raporda gösteriliyor. İçe aktarma yalnızca yerel eşlemeleri kaydeder; Shopier’e aktarım yapılmaz.'],
            'product_action_invalid' => ['error', 'Geçersiz ürün işlemi.'],
        ];
        if (!isset($messages[$notice])) {
            return;
        }

        [$type, $message] = $messages[$notice];
        if (
            $notice === 'shopier_products_load_failed' &&
            isset($_GET['wss_product_http_status']) &&
            absint($_GET['wss_product_http_status']) > 0
        ) {
            $message .= ' HTTP ' . absint($_GET['wss_product_http_status']) . '.';
        }
        if ($notice === 'product_job_start_failed') {
            $job_error = get_transient('wss_product_job_start_error_' . get_current_user_id());
            if (is_string($job_error) && $job_error !== '') {
                $message .= ' ' . $job_error;
                delete_transient('wss_product_job_start_error_' . get_current_user_id());
            }
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    private static function render_product_job_report($job_id)
    {
        $job_id = absint($job_id);
        if ($job_id <= 0) {
            return;
        }

        $job = WSS_Queue::get_job_report($job_id);
        if (!$job) {
            return;
        }

        $job_active = in_array($job['status'], ['pending', 'processing'], true);
        $status_labels = [
            'pending' => 'Bekliyor',
            'processing' => 'Çalışıyor',
            'success' => 'Tamamlandı',
            'partial' => 'Kısmen tamamlandı',
            'failed' => 'Başarısız',
        ];
        $job_labels = [
            'product_match_update' => 'Eşleştir',
            'product_create' => 'Yeni ürün oluştur ve eşleştir',
            'product_update' => 'Ürün güncelle',
            'product_sync' => 'Ürün senkronizasyonu',
        ];

        echo '<div class="notice ' . ($job_active ? 'notice-info' : ($job['status'] === 'success' ? 'notice-success' : 'notice-warning')) . '" style="padding:10px 12px;margin:16px 0;">';
        echo '<p><strong>Ürün job #' . esc_html($job['id']) . '</strong> — '
            . esc_html($job_labels[$job['job_type']] ?? $job['job_type']) . ' — '
            . esc_html($status_labels[$job['status']] ?? $job['status']) . '. ';
        echo esc_html((int) $job['processed_items']) . '/' . esc_html((int) $job['total_items'])
            . ' işlendi; ' . esc_html((int) $job['success_count']) . ' başarılı, '
            . esc_html((int) $job['error_count']) . ' hatalı.</p>';
        if ($job_active) {
            echo '<p>İşlem tamamlanana kadar ekleme, güncelleme ve eşleme işlemleri kilitlidir. Bu sayfa ilerlemeyi otomatik yeniler.</p>';
        }
        echo '<table class="widefat striped"><thead><tr><th>Ürün</th><th>WooCommerce ID</th><th>Sonuç</th><th>Ayrıntı</th></tr></thead><tbody>';
        foreach ($job['items'] as $item) {
            echo '<tr><td>' . esc_html($item['post_title'] ?: 'Silinmiş ürün') . '</td>';
            echo '<td>' . esc_html($item['wc_product_id']) . '</td>';
            echo '<td>' . esc_html($status_labels[$item['status']] ?? $item['status']) . '</td>';
            echo '<td>' . esc_html((string) $item['error_message']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($job_active) {
            echo '<script>window.setTimeout(function(){window.location.reload();},5000);</script>';
        }
    }

    private static function render_product_csv_report()
    {
        $report = get_transient(self::get_product_csv_report_transient_key());
        if (!is_array($report) || empty($report)) {
            return;
        }

        $success_count = count(array_filter($report, static function ($item) {
            return ($item['status'] ?? '') === 'success';
        }));
        echo '<h3>Son CSV içe aktarma raporu</h3>';
        echo '<p>' . esc_html($success_count) . ' başarılı, '
            . esc_html(count($report) - $success_count) . ' hatalı satır. '
            . 'Bu işlem Shopier’e ürün eklemedi veya ürün güncellemedi.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Satır</th><th>WooCommerce ID</th><th>Durum</th><th>Ayrıntı</th></tr></thead><tbody>';
        foreach ($report as $item) {
            echo '<tr><td>' . esc_html($item['line'] ?? '') . '</td>';
            echo '<td>' . esc_html($item['wc_product_id'] ?? '') . '</td>';
            echo '<td>' . esc_html(($item['status'] ?? '') === 'success' ? 'Başarılı' : 'Hatalı') . '</td>';
            echo '<td>' . esc_html($item['message'] ?? '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function register_menu()
    {
        add_submenu_page(
            'woocommerce',
            'Shopier Entegrasyonu',
            'Shopier Entegrasyonu',
            'manage_woocommerce',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Bu sayfayı görüntüleme yetkiniz yok.');
        }

        $tabs = [
            'overview'   => 'Genel Bakış',
            'products'   => 'Ürünler',
            'variations' => 'Varyasyonlar',
            'orders'     => 'Siparişler',
            'shippings'  => 'Kargolar',
            'logs'       => 'Senkronizasyon Detayları',
            'help'       => 'Yardım',
        ];
        if (current_user_can('manage_options')) {
            $tabs['settings'] = 'Ayarlar';
        }

        $active_tab = isset($_GET['tab'])
            ? sanitize_key(wp_unslash($_GET['tab']))
            : 'overview';

        if (!array_key_exists($active_tab, $tabs)) {
            $active_tab = 'overview';
        }
        if ($active_tab === 'settings' && !current_user_can('manage_options')) {
            wp_die('Shopier entegrasyon ayarlarını görüntüleme yetkiniz yok.');
        }

        echo '<style>
            .wss-wrap .notice{position:relative;margin:14px 0;padding:12px 40px 12px 16px;border:0;border-left:4px solid #2271b1;border-radius:10px;background:#f0f6fc;box-shadow:0 1px 3px rgba(0,0,0,.08);animation:wssNotice .2s ease-out}
            .wss-wrap .notice p{margin:0;padding:2px 0;font-size:13.5px;line-height:1.5;color:#1d2327}
            .wss-wrap .notice-success{border-left-color:#00a32a;background:#edfaef}
            .wss-wrap .notice-error{border-left-color:#d63638;background:#fcf0f1}
            .wss-wrap .notice-warning{border-left-color:#dba617;background:#fcf9e8}
            .wss-wrap .notice-info{border-left-color:#2271b1;background:#f0f6fc}
            .wss-wrap .notice .notice-dismiss{top:50%;transform:translateY(-50%);border-radius:50%}
            .wss-wrap .notice .notice-dismiss:before{font-size:18px}
            @keyframes wssNotice{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
            .wss-wrap .nav-tab{border-radius:8px 8px 0 0}
        </style>';
        echo '<div class="wrap wss-wrap">';
        echo '<h1>Shopier Entegrasyonu</h1>';
        echo '<nav class="nav-tab-wrapper">';

        foreach ($tabs as $slug => $label) {
            $url = add_query_arg(
                ['page' => self::PAGE_SLUG, 'tab' => $slug],
                admin_url('admin.php')
            );

            $class = ($active_tab === $slug)
                ? 'nav-tab nav-tab-active'
                : 'nav-tab';

            printf(
                '<a href="%s" class="%s">%s</a>',
                esc_url($url),
                esc_attr($class),
                esc_html($label)
            );
        }

        echo '</nav><div style="margin-top:20px;">';

        switch ($active_tab) {
            case 'overview':
                self::render_overview();
                break;
            case 'products':
                self::render_products();
                break;
            case 'variations':
                self::render_variations();
                break;
            case 'orders':
                self::render_orders();
                break;
            case 'shippings':
                self::render_shippings();
                break;
            case 'logs':
                self::render_sync_details();
                break;
            case 'settings':
                self::render_settings();
                break;
            case 'help':
                self::render_help();
                break;
            default:
                self::render_placeholder($tabs[$active_tab]);
                break;
        }

        echo '</div></div>';
    }

    private static function get_count($table, $where = '1=1')
    {
        global $wpdb;

        $allowed_tables = [
            'attribute_map',
            'product_map',
            'variation_map',
            'sync_jobs',
            'sync_job_items',
            'stock_sync_log',
            'order_map',
        ];

        if (!in_array($table, $allowed_tables, true)) {
            return 0;
        }

        $table_name = $wpdb->prefix . 'wss_' . $table;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table_name}` WHERE {$where}"
        );
    }

    private static function get_recent_records($table, $limit = 5)
    {
        global $wpdb;

        $allowed_tables = [
            'sync_jobs',
            'stock_sync_log',
        ];

        if (!in_array($table, $allowed_tables, true)) {
            return [];
        }

        $table_name = $wpdb->prefix . 'wss_' . $table;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table_name}` ORDER BY id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }

    private static function render_overview()
    {
        global $wpdb;

        $stats = [
            'Eşleştirilmiş Özellikler' => self::get_count('attribute_map'),
            'Eşleştirilmiş Ürünler' => self::get_count('product_map'),
            'Eşleştirilmiş Varyasyonlar' => self::get_count('variation_map'),
            'Bekleyen İşler' => self::get_count('sync_jobs', "status = 'pending'"),
            'Başarılı İşlemler' => self::get_count('sync_job_items', "status = 'success'"),
            'Hatalı İşlemler' => self::get_count('sync_job_items', "status = 'error'"),
            'Eşleştirilmiş Siparişler' => self::get_count('order_map'),
        ];

        echo '<h2>Entegrasyon Durumu</h2>';
        echo '<p>Veritabanından alınan güncel kayıt sayıları.</p>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;">';

        foreach ($stats as $title => $value) {
            echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;">';
            echo '<h3 style="margin-top:0;">' . esc_html($title) . '</h3>';
            echo '<p style="font-size:28px;font-weight:600;margin-bottom:0;">';
            echo esc_html(number_format_i18n($value));
            echo '</p></div>';
        }

        echo '</div>';

    }

    private static function render_sync_details()
    {
        echo '<h2>Senkronizasyon Detayları</h2>';
        echo '<p>Son senkronizasyon kayıtları ve Shopier API hata ayrıntıları gösterilir. İstek body’si yalnızca kayıtta mevcutsa açılır bölümde görünür.</p>';
        self::render_recent_jobs();
        self::render_recent_stock();
        self::render_sync_logs();
    }

    private static function render_sync_logs()
    {
        $records = WSS_Logger::get_recent_logs(500);
        $search = isset($_GET['wss_log_search'])
            ? sanitize_text_field(wp_unslash($_GET['wss_log_search']))
            : '';
        $level_filter = isset($_GET['wss_log_level'])
            ? strtoupper(sanitize_key(wp_unslash($_GET['wss_log_level'])))
            : '';
        $event_filter = isset($_GET['wss_log_event'])
            ? sanitize_key(wp_unslash($_GET['wss_log_event']))
            : '';

        $events = [];
        foreach ($records as $record) {
            $event = sanitize_key((string) ($record['event'] ?? $record['module'] ?? ''));
            if ($event !== '') {
                $events[$event] = (string) ($record['event'] ?? $record['module']);
            }
        }
        asort($events, SORT_NATURAL | SORT_FLAG_CASE);

        echo '<h2 style="margin-top:30px;">API ve senkronizasyon logları</h2>';
        echo '<form method="get" style="margin:15px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
        echo '<input type="hidden" name="tab" value="logs">';
        echo '<input type="search" name="wss_log_search" value="' . esc_attr($search) . '"'
            . ' placeholder="Log içeriğinde ara" style="min-width:260px;"> ';
        echo '<select name="wss_log_level"><option value="">Tüm seviyeler</option>';
        foreach (['ERROR', 'WARNING', 'INFO', 'DEBUG'] as $level) {
            echo '<option value="' . esc_attr($level) . '"'
                . selected($level_filter, $level, false) . '>'
                . esc_html($level) . '</option>';
        }
        echo '</select> ';
        echo '<select name="wss_log_event"><option value="">Tüm olaylar</option>';
        foreach ($events as $event_key => $event_label) {
            echo '<option value="' . esc_attr($event_key) . '"'
                . selected($event_filter, $event_key, false) . '>'
                . esc_html($event_label) . '</option>';
        }
        echo '</select> ';
        submit_button('Filtrele', 'secondary', '', false);
        $clear_url = add_query_arg(
            ['page' => self::PAGE_SLUG, 'tab' => 'logs'],
            admin_url('admin.php')
        );
        echo ' <a class="button" href="' . esc_url($clear_url) . '">Temizle</a>';
        echo '</form>';

        $records = array_values(array_filter($records, static function ($record) use ($search, $level_filter, $event_filter) {
            if (
                $level_filter !== '' &&
                strtoupper((string) ($record['level'] ?? 'INFO')) !== $level_filter
            ) {
                return false;
            }
            if ($event_filter !== '') {
                $event = sanitize_key((string) ($record['event'] ?? $record['module'] ?? ''));
                if ($event !== $event_filter) {
                    return false;
                }
            }
            if ($search !== '') {
                $content = wp_json_encode(
                    $record,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                if (
                    !is_string($content) ||
                    (function_exists('mb_stripos')
                        ? mb_stripos($content, $search) === false
                        : stripos($content, $search) === false)
                ) {
                    return false;
                }
            }
            return true;
        }));

        if (empty($records)) {
            echo '<p>Filtrelerle eşleşen senkronizasyon logu bulunamadı.</p>';
            return;
        }

        echo '<p>' . esc_html(number_format_i18n(count($records)))
            . ' eşleşen kayıt gösteriliyor (son 500 kayıt içinden).</p>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Tarih</th><th>Seviye / Olay</th><th>Ürün / Endpoint</th><th>Ayrıntı</th><th>İstek body</th>';
        echo '</tr></thead><tbody>';

        foreach ($records as $record) {
            $reference_id = (string) ($record['reference_id'] ?? '');
            $request_body = $record['request_body'] ?? null;
            $details = [];
            foreach (['response_error', 'remote_value'] as $field) {
                if (isset($record[$field]) && $record[$field] !== '') {
                    $details[] = (string) $record[$field];
                }
            }

            echo '<tr>';
            echo '<td>' . esc_html((string) $record['timestamp']) . '</td>';
            echo '<td><strong>' . esc_html((string) ($record['level'] ?? 'INFO')) . '</strong><br>';
            echo esc_html((string) ($record['event'] ?? $record['module'] ?? '')) . '<br>';
            echo '<small>' . esc_html((string) ($record['log_file'] ?? '')) . '</small></td>';
            echo '<td>';
            if ($reference_id !== '') {
                echo esc_html($reference_id) . '<br>';
            }
            echo esc_html((string) ($record['endpoint'] ?? ''));
            if (!empty($record['status'])) {
                echo '<br>HTTP ' . esc_html((string) $record['status']);
            }
            echo '</td>';
            echo '<td>' . esc_html((string) $record['message']);
            if (!empty($details)) {
                echo '<br>' . esc_html(implode(' ', $details));
            }
            echo '</td><td>';
            if (is_array($request_body)) {
                $encoded_body = wp_json_encode(
                    $request_body,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                if (is_string($encoded_body)) {
                    echo '<details><summary>Body’yi göster</summary><pre style="white-space:pre-wrap;max-width:600px;">'
                        . esc_html($encoded_body) . '</pre></details>';
                }
            } else {
                echo '—';
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    private static function render_recent_jobs()
    {
        $records = self::get_recent_records('sync_jobs');

        echo '<h2 style="margin-top:30px;">Son Senkronizasyon İşleri</h2>';

        if (empty($records)) {
            echo '<p>Henüz senkronizasyon işi bulunmuyor.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>ID</th><th>İşlem</th><th>Durum</th>';
        echo '<th>Toplam</th><th>Başarılı</th><th>Hatalı</th>';
        echo '</tr></thead><tbody>';

        foreach ($records as $record) {
            echo '<tr>';
            $job_url = add_query_arg([
                'page' => self::PAGE_SLUG,
                'tab' => 'products',
                'wss_job_id' => absint($record['id']),
            ], admin_url('admin.php'));
            echo '<td><a href="' . esc_url($job_url) . '">' . esc_html($record['id']) . '</a></td>';
            echo '<td>' . esc_html($record['job_type']) . '</td>';
            echo '<td>' . esc_html($record['status']) . '</td>';
            echo '<td>' . esc_html($record['total_items'] ?? $record['total_count'] ?? 0) . '</td>';
            echo '<td>' . esc_html($record['success_count']) . '</td>';
            echo '<td>' . esc_html($record['error_count']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function render_recent_stock()
    {
        $records = self::get_recent_records('stock_sync_log');

        echo '<h2 style="margin-top:30px;">Son Stok Hareketleri</h2>';

        if (empty($records)) {
            echo '<p>Henüz stok hareketi bulunmuyor.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>WooCommerce ürünü / varyasyonu</th><th>Shopier ürün ID</th><th>Kaynak</th><th>Eski Stok</th>';
        echo '<th>Yeni Stok</th><th>Durum</th><th>Tarih</th>';
        echo '</tr></thead><tbody>';

        foreach ($records as $record) {
            $wc_product_id = absint($record['wc_product_id'] ?? 0);
            $wc_variation_id = absint($record['wc_variation_id'] ?? 0);
            $wc_product = wc_get_product($wc_product_id);
            $wc_variation = $wc_variation_id ? wc_get_product($wc_variation_id) : false;
            $product_name = $wc_product ? $wc_product->get_name() : ('ID ' . $wc_product_id);
            $variation_name = $wc_variation
                ? (wc_get_formatted_variation($wc_variation, true, false, true) ?: $wc_variation->get_name())
                : '';
            echo '<tr>';
            echo '<td>' . esc_html($product_name) . ' <small>(ID: ' . esc_html($wc_product_id) . ')</small>';
            if ($variation_name !== '') {
                echo '<br><small>' . esc_html($variation_name) . ' (ID: ' . esc_html($wc_variation_id) . ')</small>';
            }
            echo '</td>';
            echo '<td>' . esc_html((string) ($record['shopier_product_id'] ?? '')) . '</td>';
            echo '<td>' . esc_html($record['source']) . '</td>';
            echo '<td>' . esc_html($record['old_quantity']) . '</td>';
            echo '<td>' . esc_html($record['new_quantity']) . '</td>';
            echo '<td>' . esc_html($record['sync_status'] ?? $record['status'] ?? '') . '</td>';
            echo '<td>' . esc_html($record['created_at']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }


    private static function render_orders()
    {
        $requested_page = isset($_GET['order_page']) && is_scalar($_GET['order_page']) ? max(1, absint(wp_unslash($_GET['order_page']))) : 1;
        $per_page = isset($_GET['order_per_page']) && is_scalar($_GET['order_per_page']) ? absint(wp_unslash($_GET['order_per_page'])) : 25;
        $per_page = in_array($per_page, [10, 25, 50, 100], true) ? $per_page : 25;
        $page_data = get_transient(self::get_shopier_orders_transient_key($requested_page, $per_page));
        if (!is_array($page_data)) {
            $result = WSS_Shopier_API::get_orders_page($requested_page, $per_page);
            if (!empty($result['success']) && is_array($result['body'] ?? null)) {
                $page_data = $result['body'];
                set_transient(self::get_shopier_orders_transient_key($requested_page, $per_page), $page_data, 10 * MINUTE_IN_SECONDS);
            } else {
                $page_data = null;
            }
        }
        $orders = is_array($page_data['orders'] ?? null) ? $page_data['orders'] : null;
        $has_more = !empty($page_data['has_more']);
        $total_count = isset($page_data['total_count']) && is_numeric($page_data['total_count']) ? absint($page_data['total_count']) : null;

        if (isset($_GET['order_notice']) && is_scalar($_GET['order_notice'])) {
            $notice = sanitize_key(wp_unslash($_GET['order_notice']));
            if ($notice === 'shopier_orders_loaded') {
                echo '<div class="notice notice-success inline"><p>' . esc_html(sprintf('%d Shopier siparişi yüklendi.', absint($_GET['order_count'] ?? 0))) . '</p></div>';
            } elseif ($notice === 'shopier_orders_load_failed') {
                echo '<div class="notice notice-error inline"><p>Shopier siparişleri alınamadı. API erişimini ve PAT yetkisini kontrol edin.</p></div>';
            }
        }

        echo '<h2>Shopier Siparişleri</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0">';
        echo '<input type="hidden" name="action" value="wss_load_shopier_orders">';
        echo '<input type="hidden" name="order_page" value="' . esc_attr($requested_page) . '"><input type="hidden" name="order_per_page" value="' . esc_attr($per_page) . '">';
        foreach (['order_search', 'order_status', 'payment_status', 'order_date_from', 'order_date_to'] as $field) {
            $value = isset($_GET[$field]) && is_scalar($_GET[$field]) ? sanitize_text_field(wp_unslash($_GET[$field])) : '';
            echo '<input type="hidden" name="' . esc_attr($field) . '" value="' . esc_attr($value) . '">';
        }
        wp_nonce_field('wss_load_shopier_orders');
        submit_button('Siparişleri yenile', 'primary', 'submit', false);
        if ($orders !== null) {
            echo ' <span class="description">Bu API sayfasında ' . esc_html(count($orders)) . ' sipariş var; önbellek 10 dakika saklanır.</span>';
        }
        echo '</form>';

        if ($orders === null) {
            echo '<p>Shopier siparişleri alınamadı. API erişimini ve PAT yetkisini kontrol edip yeniden deneyin.</p>';
            return;
        }

        $search = isset($_GET['order_search']) && is_scalar($_GET['order_search']) ? sanitize_text_field(wp_unslash($_GET['order_search'])) : '';
        $status_filter = isset($_GET['order_status']) && is_scalar($_GET['order_status']) ? sanitize_key(wp_unslash($_GET['order_status'])) : 'all';
        $payment_filter = isset($_GET['payment_status']) && is_scalar($_GET['payment_status']) ? sanitize_key(wp_unslash($_GET['payment_status'])) : 'all';
        $date_from = isset($_GET['order_date_from']) && is_scalar($_GET['order_date_from']) ? sanitize_text_field(wp_unslash($_GET['order_date_from'])) : '';
        $date_to = isset($_GET['order_date_to']) && is_scalar($_GET['order_date_to']) ? sanitize_text_field(wp_unslash($_GET['order_date_to'])) : '';
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date_from)) {
            $date_from = '';
        }
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date_to)) {
            $date_to = '';
        }

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;padding:12px;margin:12px 0;background:#fff;border:1px solid #dcdcde">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '"><input type="hidden" name="tab" value="orders">';
        echo '<label>Ara (sipariş, müşteri, ürün)<br><input type="search" name="order_search" value="' . esc_attr($search) . '" class="regular-text"></label>';
        echo '<label>Sipariş durumu<br><select name="order_status"><option value="all">Tümü</option><option value="unfulfilled" ' . selected($status_filter, 'unfulfilled', false) . '>Hazırlanıyor</option><option value="fulfilled" ' . selected($status_filter, 'fulfilled', false) . '>Tamamlandı</option></select></label>';
        echo '<label>Ödeme durumu<br><select name="payment_status"><option value="all">Tümü</option><option value="paid" ' . selected($payment_filter, 'paid', false) . '>Ödendi</option><option value="unpaid" ' . selected($payment_filter, 'unpaid', false) . '>Ödenmedi</option></select></label>';
        echo '<label>Başlangıç tarihi<br><input type="date" name="order_date_from" value="' . esc_attr($date_from) . '"></label>';
        echo '<label>Bitiş tarihi<br><input type="date" name="order_date_to" value="' . esc_attr($date_to) . '"></label>';
        echo '<label>Sayfa başına sipariş<br><select name="order_per_page">';
        foreach ([10, 25, 50, 100] as $page_size) {
            echo '<option value="' . esc_attr($page_size) . '" ' . selected($per_page, $page_size, false) . '>' . esc_html($page_size) . '</option>';
        }
        echo '</select></label><input type="hidden" name="order_page" value="1">';
        submit_button('Filtrele', 'secondary', '', false);
        echo '</form>';

        $filtered = array_values(array_filter($orders, static function ($order) use ($search, $status_filter, $payment_filter, $date_from, $date_to) {
            $status = sanitize_key((string) ($order['status'] ?? ''));
            $payment = sanitize_key((string) ($order['paymentStatus'] ?? ''));
            if ($status_filter !== 'all' && $status !== $status_filter) {
                return false;
            }
            if ($payment_filter !== 'all' && $payment !== $payment_filter) {
                return false;
            }
            $date = substr((string) ($order['dateCreated'] ?? ''), 0, 10);
            if ($date_from !== '' && ($date === '' || $date < $date_from)) {
                return false;
            }
            if ($date_to !== '' && ($date === '' || $date > $date_to)) {
                return false;
            }
            if ($search !== '') {
                $shipping = is_array($order['shippingInfo'] ?? null) ? $order['shippingInfo'] : [];
                $billing = is_array($order['billingInfo'] ?? null) ? $order['billingInfo'] : [];
                $parts = [
                    (string) ($order['id'] ?? ''),
                    (string) ($shipping['firstName'] ?? ''),
                    (string) ($shipping['lastName'] ?? ''),
                    (string) ($shipping['email'] ?? ''),
                    (string) ($billing['firstName'] ?? ''),
                    (string) ($billing['lastName'] ?? ''),
                    (string) ($billing['email'] ?? ''),
                ];
                $items = is_array($order['lineItems'] ?? null) ? $order['lineItems'] : [];
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $parts[] = (string) ($item['title'] ?? '');
                        $parts[] = (string) ($item['productId'] ?? '');
                    }
                }
                $haystack = strtolower(remove_accents(implode(' ', $parts)));
                $needle = strtolower(remove_accents($search));
                if (strpos($haystack, $needle) === false) {
                    return false;
                }
            }
            return true;
        }));

        $visible_orders = $filtered;
        echo '<p>' . esc_html($total_count !== null ? sprintf('Toplam %d sipariş; API sayfası %d, bu sayfada filtre sonrası %d sipariş.', $total_count, $requested_page, count($filtered)) : sprintf('API sayfası %d: %d sipariş; filtre sonrası %d sipariş.', $requested_page, count($orders), count($filtered))) . '</p>';

        if (!$visible_orders) {
            echo '<div class="notice notice-info inline"><p>Bu filtrelerle eşleşen sipariş bulunamadı.</p></div>';
            // Keep API pagination controls visible so a filter with no matches cannot trap the user.
        }

        if ($visible_orders) {
        echo '<table class="widefat striped"><thead><tr><th>Sipariş</th><th>Tarih</th><th>Müşteri</th><th>Ürünler</th><th>Ödeme</th><th>Sipariş durumu</th><th>Toplam</th></tr></thead><tbody>';
        foreach ($visible_orders as $order) {
            $shipping = is_array($order['shippingInfo'] ?? null) ? $order['shippingInfo'] : [];
            $billing = is_array($order['billingInfo'] ?? null) ? $order['billingInfo'] : [];
            $first_name = (string) ($shipping['firstName'] ?? $billing['firstName'] ?? '');
            $last_name = (string) ($shipping['lastName'] ?? $billing['lastName'] ?? '');
            $email = (string) ($shipping['email'] ?? $billing['email'] ?? '');
            $items = is_array($order['lineItems'] ?? null) ? $order['lineItems'] : [];
            $item_labels = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $label = (string) ($item['title'] ?? 'Ürün');
                $selection = is_array($item['selection'] ?? null) ? $item['selection'] : [];
                $selection_titles = array_filter(array_map(static function ($selected) {
                    return is_array($selected) ? (string) ($selected['title'] ?? '') : '';
                }, $selection));
                if ($selection_titles) {
                    $label .= ' (' . implode(' | ', $selection_titles) . ')';
                }
                $item_labels[] = $label . ' × ' . absint($item['quantity'] ?? 1);
            }
            $date_timestamp = strtotime((string) ($order['dateCreated'] ?? ''));
            $date_label = $date_timestamp ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $date_timestamp) : '—';
            $total_amount = (string) ($order['totals']['total'] ?? '');
            $currency = (string) ($order['currency'] ?? 'TRY');
            $status = sanitize_key((string) ($order['status'] ?? ''));
            $payment = sanitize_key((string) ($order['paymentStatus'] ?? ''));
            $order_id = (string) ($order['id'] ?? '');
            $shopier_order_url = add_query_arg('orderid', $order_id, 'https://www.shopier.com/m/orders.php');
            echo '<tr><td><strong><a href="' . esc_url($shopier_order_url) . '" target="_blank" rel="noopener noreferrer">#' . esc_html($order_id) . '</a></strong></td>';
            echo '<td>' . esc_html($date_label) . '</td>';
            echo '<td>' . esc_html(trim($first_name . ' ' . $last_name) ?: '—') . ($email !== '' ? '<br><small>' . esc_html($email) . '</small>' : '') . '</td>';
            echo '<td>' . esc_html($item_labels ? implode(', ', $item_labels) : '—') . '</td>';
            echo '<td>' . esc_html($payment === 'paid' ? 'Ödendi' : ($payment === 'unpaid' ? 'Ödenmedi' : ($payment ?: '—'))) . '</td>';
            echo '<td>' . esc_html($status === 'fulfilled' ? 'Tamamlandı' : ($status === 'unfulfilled' ? 'Hazırlanıyor' : ($status ?: '—'))) . '</td>';
            echo '<td>' . esc_html(trim($total_amount . ' ' . $currency)) . '</td></tr>';
        }
        echo '</tbody></table>';
        }
        $pager_args = ['page' => self::PAGE_SLUG, 'tab' => 'orders', 'order_search' => $search, 'order_status' => $status_filter, 'payment_status' => $payment_filter, 'order_date_from' => $date_from, 'order_date_to' => $date_to, 'order_per_page' => $per_page];
        echo '<div style="display:flex;gap:12px;align-items:center;margin-top:15px">';
        if ($requested_page > 1) {
            echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($pager_args, ['order_page' => $requested_page - 1]), admin_url('admin.php'))) . '">‹ Önceki</a>';
        }
        echo '<span>Sayfa ' . esc_html($requested_page) . '</span>';
        if ($has_more) {
            echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($pager_args, ['order_page' => $requested_page + 1]), admin_url('admin.php'))) . '">Sonraki ›</a>';
        }
        echo '</div>';
    }

    private static function render_shippings()
    {
        $requested_page = isset($_GET['shipping_page']) && is_scalar($_GET['shipping_page']) ? max(1, absint(wp_unslash($_GET['shipping_page']))) : 1;
        $per_page = isset($_GET['shipping_per_page']) && is_scalar($_GET['shipping_per_page']) ? absint(wp_unslash($_GET['shipping_per_page'])) : 25;
        $per_page = in_array($per_page, [10, 25, 50], true) ? $per_page : 25;
        $filters = self::get_shipping_filters($_GET);
        $search = isset($_GET['shipping_search']) && is_scalar($_GET['shipping_search']) ? sanitize_text_field(wp_unslash($_GET['shipping_search'])) : '';
        $page_data = get_transient(self::get_shopier_shippings_transient_key($requested_page, $per_page, $filters));
        if (!is_array($page_data)) {
            $result = WSS_Shopier_API::get_shippings_page($requested_page, $per_page, $filters);
            if (!empty($result['success']) && is_array($result['body'] ?? null)) {
                $page_data = $result['body'];
                set_transient(self::get_shopier_shippings_transient_key($requested_page, $per_page, $filters), $page_data, 10 * MINUTE_IN_SECONDS);
            } else {
                $page_data = null;
            }
        }
        $shippings = is_array($page_data['shippings'] ?? null) ? $page_data['shippings'] : null;
        $has_more = !empty($page_data['has_more']);
        $total_count = isset($page_data['total_count']) && is_numeric($page_data['total_count']) ? absint($page_data['total_count']) : null;

        if (isset($_GET['shipping_notice']) && is_scalar($_GET['shipping_notice'])) {
            $notice = sanitize_key(wp_unslash($_GET['shipping_notice']));
            if ($notice === 'loaded') {
                echo '<div class="notice notice-success inline"><p>' . esc_html(sprintf('%d kargo kaydı yüklendi.', absint($_GET['shipping_count'] ?? 0))) . '</p></div>';
            } elseif ($notice === 'load_failed') {
                echo '<div class="notice notice-error inline"><p>Shopier kargo kayıtları alınamadı. API erişimini, PAT yetkisini ve Shopier hesabınızın kargo API erişimini kontrol edin.</p></div>';
            }
        }

        echo '<h2>Shopier Kargoları</h2>';
        echo '<p>Kargo durumu, taşıyıcı ve tarih filtreleri Shopier API’sine gönderilir. Arama, seçili API sayfasındaki kayıtları tarar.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0">';
        echo '<input type="hidden" name="action" value="wss_load_shopier_shippings">';
        echo '<input type="hidden" name="shipping_page" value="' . esc_attr($requested_page) . '"><input type="hidden" name="shipping_per_page" value="' . esc_attr($per_page) . '">';
        $hidden_fields = ['shipping_search' => $search, 'shipping_status' => $_GET['shipping_status'] ?? 'all', 'shipping_type' => $_GET['shipping_type'] ?? 'all', 'shipping_company' => $_GET['shipping_company'] ?? 'all', 'shipping_date_from' => $_GET['shipping_date_from'] ?? '', 'shipping_date_to' => $_GET['shipping_date_to'] ?? ''];
        foreach ($hidden_fields as $field => $value) {
            $value = is_scalar($value) ? sanitize_text_field(wp_unslash($value)) : '';
            echo '<input type="hidden" name="' . esc_attr($field) . '" value="' . esc_attr($value) . '">';
        }
        wp_nonce_field('wss_load_shopier_shippings');
        submit_button('Kargoları yenile', 'primary', 'submit', false);
        if ($shippings !== null) {
            echo ' <span class="description">Bu API sayfasında ' . esc_html(count($shippings)) . ' kayıt var; önbellek 10 dakika saklanır.</span>';
        }
        echo '</form>';
        if ($shippings === null) {
            echo '<p>Kargo kayıtları alınamadı. Ayarları kontrol edip sayfayı yenileyin.</p>';
            return;
        }

        $status_filter = isset($_GET['shipping_status']) && is_scalar($_GET['shipping_status']) ? sanitize_text_field(wp_unslash($_GET['shipping_status'])) : 'all';
        $type_filter = isset($_GET['shipping_type']) && is_scalar($_GET['shipping_type']) ? sanitize_text_field(wp_unslash($_GET['shipping_type'])) : 'all';
        $company_filter = isset($_GET['shipping_company']) && is_scalar($_GET['shipping_company']) ? sanitize_text_field(wp_unslash($_GET['shipping_company'])) : 'all';
        $date_from = isset($_GET['shipping_date_from']) && is_scalar($_GET['shipping_date_from']) ? sanitize_text_field(wp_unslash($_GET['shipping_date_from'])) : '';
        $date_to = isset($_GET['shipping_date_to']) && is_scalar($_GET['shipping_date_to']) ? sanitize_text_field(wp_unslash($_GET['shipping_date_to'])) : '';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;padding:12px;margin:12px 0;background:#fff;border:1px solid #dcdcde">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '"><input type="hidden" name="tab" value="shippings">';
        echo '<label>Ara (sipariş, takip no, kargo kodu)<br><input type="search" name="shipping_search" value="' . esc_attr($search) . '" class="regular-text"></label>';
        echo '<label>Kargo durumu<br><select name="shipping_status"><option value="all">Tümü</option><option value="shipped" ' . selected($status_filter, 'shipped', false) . '>Gönderildi</option><option value="notShipped" ' . selected($status_filter, 'notShipped', false) . '>Gönderilmedi</option></select></label>';
        echo '<label>Kargo türü<br><select name="shipping_type"><option value="all">Tümü</option><option value="firstShipment" ' . selected($type_filter, 'firstShipment', false) . '>İlk gönderi</option><option value="secondShipment" ' . selected($type_filter, 'secondShipment', false) . '>İkinci gönderi</option><option value="returnShipment" ' . selected($type_filter, 'returnShipment', false) . '>İade</option></select></label>';
        echo '<label>Taşıyıcı<br><select name="shipping_company"><option value="all">Tümü</option>';
        $companies = ['yurtici' => 'Yurtiçi Kargo', 'mng' => 'MNG Kargo', 'ptt' => 'PTT'];
        foreach ($companies as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($company_filter, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Oluşturulma tarihi (başlangıç)<br><input type="date" name="shipping_date_from" value="' . esc_attr($date_from) . '"></label>';
        echo '<label>Bitiş<br><input type="date" name="shipping_date_to" value="' . esc_attr($date_to) . '"></label>';
        echo '<label>Sayfa başına kayıt<br><select name="shipping_per_page">';
        foreach ([10, 25, 50] as $page_size) {
            echo '<option value="' . esc_attr($page_size) . '" ' . selected($per_page, $page_size, false) . '>' . esc_html($page_size) . '</option>';
        }
        echo '</select></label><input type="hidden" name="shipping_page" value="1">';
        submit_button('Filtrele', 'secondary', '', false);
        echo '</form>';

        $filtered = array_values(array_filter($shippings, static function ($shipping) use ($search) {
            if ($search === '') {
                return true;
            }
            $parts = [(string) ($shipping['orderId'] ?? ''), (string) ($shipping['code'] ?? ''), (string) ($shipping['trackingNumber'] ?? ''), (string) ($shipping['company'] ?? '')];
            return strpos(strtolower(remove_accents(implode(' ', $parts))), strtolower(remove_accents($search))) !== false;
        }));
        echo '<p>' . esc_html($total_count !== null ? sprintf('Toplam %d kargo kaydı; API sayfası %d, filtre sonrası %d kayıt.', $total_count, $requested_page, count($filtered)) : sprintf('API sayfası %d: %d kargo kaydı; filtre sonrası %d kayıt.', $requested_page, count($shippings), count($filtered))) . '</p>';
        if (!$filtered) {
            echo '<div class="notice notice-info inline"><p>Bu filtrelerle eşleşen kargo kaydı bulunamadı.</p></div>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Sipariş</th><th>Kargo kodu</th><th>Taşıyıcı</th><th>Takip numarası</th><th>Durum</th><th>Tür</th><th>Yöntem</th><th>Tarih</th><th>Ücret</th></tr></thead><tbody>';
            foreach ($filtered as $shipping) {
                $order_id = (string) ($shipping['orderId'] ?? '');
                $order_link = $order_id !== '' ? add_query_arg('orderid', $order_id, 'https://www.shopier.com/m/orders.php') : '';
                $code = (string) ($shipping['code'] ?? '');
                $tracking_number = (string) ($shipping['trackingNumber'] ?? '');
                $tracking_url = isset($shipping['trackingUrl']) ? esc_url($shipping['trackingUrl']) : '';
                $company = (string) ($shipping['company'] ?? '');
                $date_value = (string) ($shipping['dateDispatched'] ?? $shipping['dateCreated'] ?? '');
                $date_timestamp = strtotime($date_value);
                $date_label = $date_timestamp ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $date_timestamp) : '—';
                $cost = (string) ($shipping['cost'] ?? '');
                $currency = (string) ($shipping['currency'] ?? '');
                $type_labels = ['firstShipment' => 'İlk gönderi', 'secondShipment' => 'İkinci gönderi', 'returnShipment' => 'İade'];
                $status_label = ($shipping['status'] ?? '') === 'shipped' ? 'Gönderildi' : (($shipping['status'] ?? '') === 'notShipped' ? 'Gönderilmedi' : (string) ($shipping['status'] ?? '—'));
                echo '<tr><td>' . ($order_link ? '<a href="' . esc_url($order_link) . '" target="_blank" rel="noopener noreferrer">#' . esc_html($order_id) . '</a>' : '—') . '</td>';
                echo '<td>' . esc_html($code ?: '—') . '</td><td>' . esc_html($companies[$company] ?? ($company ?: '—')) . '</td>';
                echo '<td>' . ($tracking_number !== '' ? ($tracking_url ? '<a href="' . esc_url($tracking_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($tracking_number) . '</a>' : esc_html($tracking_number)) : '—') . '</td>';
                echo '<td>' . esc_html($status_label) . '</td><td>' . esc_html($type_labels[$shipping['type'] ?? ''] ?? ($shipping['type'] ?? '—')) . '</td>';
                echo '<td>' . esc_html(($shipping['method'] ?? '') === 'contracted' ? 'Anlaşmalı' : (($shipping['method'] ?? '') === 'standard' ? 'Standart' : ($shipping['method'] ?? '—'))) . '</td>';
                echo '<td>' . esc_html($date_label) . '</td><td>' . esc_html(trim($cost . ' ' . $currency) ?: '—') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        $pager_args = ['page' => self::PAGE_SLUG, 'tab' => 'shippings', 'shipping_search' => $search, 'shipping_status' => $status_filter, 'shipping_type' => $type_filter, 'shipping_company' => $company_filter, 'shipping_date_from' => $date_from, 'shipping_date_to' => $date_to, 'shipping_per_page' => $per_page];
        echo '<div style="display:flex;gap:12px;align-items:center;margin-top:15px">';
        if ($requested_page > 1) {
            echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($pager_args, ['shipping_page' => $requested_page - 1]), admin_url('admin.php'))) . '">‹ Önceki</a>';
        }
        echo '<span>Sayfa ' . esc_html($requested_page) . '</span>';
        if ($has_more) {
            echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($pager_args, ['shipping_page' => $requested_page + 1]), admin_url('admin.php'))) . '">Sonraki ›</a>';
        }
        echo '</div>';
    }

    private static function render_variations()
    {
        $catalog = get_transient(self::get_global_catalog_transient_key());
        $catalog = is_array($catalog) ? $catalog : [];
        $existing = WSS_Attribute_Map::get_for_product(0);
        $existing_by_taxonomy = [];

        foreach ($existing as $mapping) {
            $taxonomy = WSS_Attribute_Map::normalize_attribute_name($mapping['wc_attribute']);
            $existing_by_taxonomy[$taxonomy][] = $mapping;
        }

        echo '<h2>Global WooCommerce özellik eşleştirmesi</h2>';
        echo '<p>Her global WooCommerce özelliğini bir Shopier global varyasyon grubuna bağlayın. Ardından her WooCommerce terimini Shopier seçim ID’sine eşleyin. İsim benzerliği yalnızca öneri üretir; kaydedilen eşleştirme ve sonraki çözümleme ID ve WooCommerce terim slug’ı üzerinden yapılır.</p>';
        self::render_mapping_notice();
        self::render_variation_mapping_preview();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:15px 0;">';
        echo '<input type="hidden" name="action" value="wss_load_global_variation_catalog">';
        wp_nonce_field('wss_load_global_variation_catalog');
        submit_button('Shopier global varyasyon ve seçimlerini yükle', 'secondary', 'submit', false);
        echo '</form>';

        if (empty($catalog)) {
            echo '<p>Önce Shopier API’sinden global grupları ve seçimleri yükleyin. Bu istek yalnızca okuma yapar.</p>';
            return;
        }
        if (empty(array_filter($catalog, static function ($group) {
            return is_array($group) && !empty($group['id']);
        }))) {
            echo '<div class="notice notice-warning inline"><p>Shopier hesabında eşleştirilebilir global varyasyon grubu bulunamadı.</p></div>';
            return;
        }

        $attributes = function_exists('wc_get_attribute_taxonomies')
            ? wc_get_attribute_taxonomies()
            : [];
        if (empty($attributes)) {
            echo '<div class="notice notice-warning inline"><p>WooCommerce global özellikleri bulunamadı.</p></div>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wss_save_global_variation_mappings">';
        wp_nonce_field('wss_save_global_variation_mappings');
        echo '<table class="widefat striped"><thead><tr><th>WooCommerce global özelliği / terimi</th><th>Shopier global grup</th><th>Shopier seçimi (ID)</th></tr></thead><tbody>';

        foreach ($attributes as $attribute) {
            $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
            ]);
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            $attribute_mappings = $existing_by_taxonomy[$taxonomy] ?? [];
            $mapped_group_ids = array_values(array_unique(array_map(
                'strval',
                array_column($attribute_mappings, 'shopier_group_id')
            )));
            $selected_group_id = count($mapped_group_ids) === 1
                ? $mapped_group_ids[0]
                : '';
            $existing_selections = [];
            foreach ($attribute_mappings as $mapping) {
                $existing_selections[(string) $mapping['wc_value']] = [
                    'group_id' => (string) $mapping['shopier_group_id'],
                    'selection_id' => (string) $mapping['shopier_selection_id'],
                ];
            }

            $term_name_counts = [];
            foreach ($terms as $term) {
                $candidate_name = self::normalize_mapping_candidate($term->name);
                $term_name_counts[$candidate_name] = ($term_name_counts[$candidate_name] ?? 0) + 1;
            }

            foreach ($terms as $term_index => $term) {
                echo '<tr>';
                echo '<td><strong>' . esc_html($attribute->attribute_label) . '</strong><br>' . esc_html($term->name) . ' <code>' . esc_html($term->slug) . '</code></td>';
                if ($term_index === 0) {
                    echo '<td rowspan="' . esc_attr(count($terms)) . '"><select class="wss-shopier-group" data-taxonomy="' . esc_attr($taxonomy) . '" name="group_mapping[' . esc_attr($taxonomy) . ']">';
                    echo '<option value="">Eşleştirme yok</option>';
                    foreach ($catalog as $group) {
                        if (!is_array($group) || empty($group['id'])) {
                            continue;
                        }
                        printf(
                            '<option value="%1$s" %2$s>%3$s (ID: %1$s)</option>',
                            esc_attr((string) $group['id']),
                            selected($selected_group_id, (string) $group['id'], false),
                            esc_html((string) $group['title'])
                        );
                    }
                    echo '</select></td>';
                }

                $selected_selection_id = '';
                if (
                    isset($existing_selections[$term->slug]) &&
                    $existing_selections[$term->slug]['group_id'] === $selected_group_id
                ) {
                    $selected_selection_id = $existing_selections[$term->slug]['selection_id'];
                } elseif ($selected_group_id !== '' && isset($catalog)) {
                    foreach ($catalog as $group) {
                        if ((string) ($group['id'] ?? '') !== $selected_group_id) {
                            continue;
                        }

                        $matching_ids = [];
                        $selection_name_counts = [];
                        foreach ((array) ($group['selections'] ?? []) as $selection_id => $selection_title) {
                            $normalized = self::normalize_mapping_candidate($selection_title);
                            $selection_name_counts[$normalized] = ($selection_name_counts[$normalized] ?? 0) + 1;
                            if ($normalized === self::normalize_mapping_candidate($term->name)) {
                                $matching_ids[] = (string) $selection_id;
                            }
                        }
                        $candidate_name = self::normalize_mapping_candidate($term->name);
                        if (
                            ($term_name_counts[$candidate_name] ?? 0) === 1 &&
                            count($matching_ids) === 1 &&
                            ($selection_name_counts[$candidate_name] ?? 0) === 1
                        ) {
                            $selected_selection_id = $matching_ids[0];
                        }
                        break;
                    }
                }

                echo '<td><select class="wss-shopier-selection" data-taxonomy="' . esc_attr($taxonomy) . '" data-term-match="' . esc_attr(self::normalize_mapping_candidate($term->name)) . '" name="selection_mapping[' . esc_attr($taxonomy) . '][' . esc_attr($term->term_id) . ']" aria-label="' . esc_attr($attribute->attribute_label . ': ' . $term->name) . '">';
                echo '<option value="">Shopier seçimi seçin</option>';
                foreach ($catalog as $group) {
                    foreach ((array) ($group['selections'] ?? []) as $selection_id => $selection_title) {
                        printf(
                            '<option value="%1$s" data-group="%2$s" data-match="%6$s" %3$s>%4$s → %5$s (ID: %1$s)</option>',
                            esc_attr((string) $selection_id),
                            esc_attr((string) ($group['id'] ?? '')),
                            selected($selected_selection_id, (string) $selection_id, false),
                            esc_html((string) ($group['title'] ?? '')),
                            esc_html((string) $selection_title),
                            esc_attr(self::normalize_mapping_candidate($selection_title))
                        );
                    }
                }
                echo '</select></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '<p class="description">Başlık eşleşmeleri otomatik kaydedilmez. Her seçim satırında Shopier ID’sini kontrol edin; aynı Shopier grubunda bir seçim iki WooCommerce terimine bağlanamaz.</p>';
        submit_button('Global özellik eşleştirmelerini kaydet');
        echo '</form>';
        echo '<script>
            document.querySelectorAll(".wss-shopier-group").forEach(function (groupSelect) {
                function filterSelections() {
                    var taxonomy = groupSelect.dataset.taxonomy;
                    var groupId = groupSelect.value;
                    var selections = Array.from(document.querySelectorAll(".wss-shopier-selection[data-taxonomy=\"" + taxonomy + "\"]"));
                    selections.forEach(function (selectionSelect) {
                        Array.from(selectionSelect.options).forEach(function (option) {
                            if (!option.value) {
                                option.hidden = false;
                                return;
                            }
                            option.hidden = option.dataset.group !== groupId;
                            if (option.selected && option.hidden) {
                                selectionSelect.value = "";
                            }
                        });
                        if (!selectionSelect.value && groupId) {
                            var matchingOptions = Array.from(selectionSelect.options).filter(function (option) {
                                return option.dataset.group === groupId &&
                                    option.dataset.match === selectionSelect.dataset.termMatch;
                            });
                            var duplicateTerm = selections.filter(function (candidate) {
                                return candidate.dataset.termMatch === selectionSelect.dataset.termMatch;
                            }).length > 1;
                            if (matchingOptions.length === 1 && !duplicateTerm) {
                                selectionSelect.value = matchingOptions[0].value;
                            }
                        }
                    });
                }
                groupSelect.addEventListener("change", filterSelections);
                filterSelections();
            });
        </script>';
    }

    private static function render_variation_mapping_preview()
    {
        $product_id = isset($_GET['wss_preview_product_id'])
            ? absint(wp_unslash($_GET['wss_preview_product_id']))
            : 0;
        $products = wc_get_products([
            'type' => ['variable'],
            'status' => ['publish', 'private', 'draft', 'pending', 'future'],
            'limit' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
            'return' => 'objects',
        ]);
        if (!is_array($products)) {
            $products = [];
        }
        $listed_product_ids = array_map(static function ($product) {
            return absint($product->get_id());
        }, $products);

        $selected_product = $product_id ? wc_get_product($product_id) : false;
        if (
            $selected_product &&
            $selected_product->is_type('variable') &&
            !in_array($product_id, $listed_product_ids, true)
        ) {
            $products[] = $selected_product;
        }

        echo '<h3>WooCommerce varyasyon eşleştirmesi — salt okunur önizleme</h3>';
        echo '<p>Ürün seçildiğinde her WooCommerce varyasyonunun kayıtlı global özellik eşleştirmelerinden çözülen Shopier grup/seçim ID’leri gösterilir. Önizleme hiçbir kayıt yapmaz ve Shopier’e istek göndermez.</p>';
        echo '<form method="get" style="margin:12px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
        echo '<input type="hidden" name="tab" value="variations">';
        echo '<label for="wss-preview-product"><strong>Değişken WooCommerce ürünü</strong></label> ';
        echo '<select id="wss-preview-product" name="wss_preview_product_id">';
        echo '<option value="0">Ürün seçin</option>';
        foreach ($products as $candidate) {
            if (!$candidate || !$candidate->is_type('variable')) {
                continue;
            }
            printf(
                '<option value="%1$d" %2$s>%3$s (Ürün ID: %1$d, varyasyon: %4$d)</option>',
                absint($candidate->get_id()),
                selected($product_id, $candidate->get_id(), false),
                esc_html($candidate->get_name()),
                count($candidate->get_children())
            );
        }
        echo '</select> ';
        submit_button('Varyasyon önizlemesini göster', 'secondary', '', false);
        echo '</form>';

        if (!$product_id) {
            echo '<p>Varyasyonların ID bazlı eşleştirme sonucunu görmek için bir ürün seçin.</p>';
            return;
        }

        if (!$selected_product || !$selected_product->is_type('variable')) {
            echo '<div class="notice notice-error inline"><p>Seçilen WooCommerce ürünü bulunamadı veya değişken ürün değil.</p></div>';
            return;
        }

        $rows = [];
        $signature_rows = [];
        foreach ($selected_product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation')) {
                continue;
            }

            $attributes = WSS_WooCommerce_Attribute_Resolver::get_variation_attributes(
                $variation_id
            );
            $resolved = is_wp_error($attributes)
                ? $attributes
                : WSS_Variation_Map::resolve_global_selection_signature($variation_id);

            $row = [
                'variation_id' => absint($variation_id),
                'attributes' => is_wp_error($attributes)
                    ? []
                    : self::get_variation_attribute_display($variation),
                'resolved' => $resolved,
                'duplicate' => false,
                'saved' => !is_wp_error($resolved)
                    && WSS_Variation_Map::has_current_mapping($variation_id),
            ];
            if (!is_wp_error($resolved)) {
                $signature_rows[$resolved['selection_signature']][] = count($rows);
            }
            $rows[] = $row;
        }

        foreach ($signature_rows as $row_indexes) {
            if (count($row_indexes) < 2) {
                continue;
            }
            foreach ($row_indexes as $row_index) {
                $rows[$row_index]['duplicate'] = true;
            }
        }

        $resolved_count = 0;
        $error_count = 0;
        $duplicate_count = 0;
        $saved_count = 0;
        foreach ($rows as $row) {
            if (is_wp_error($row['resolved'])) {
                $error_count++;
            } else {
                $resolved_count++;
            }
            if ($row['saved']) {
                $saved_count++;
            }
            if ($row['duplicate']) {
                $duplicate_count++;
            }
        }

        echo '<p><strong>' . esc_html($selected_product->get_name()) . '</strong> — '
            . esc_html(count($rows)) . ' varyasyon; '
            . esc_html($resolved_count) . ' çözümlendi, '
            . esc_html($error_count) . ' eksik/hatalı, '
            . esc_html($duplicate_count) . ' çakışan kombinasyon, '
            . esc_html($saved_count) . ' güncel eşleme kaydedildi.</p>';

        if (empty($rows)) {
            echo '<p>Bu ürün için WooCommerce varyasyonu bulunamadı.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>WooCommerce varyasyonu</th><th>WooCommerce özellikleri</th><th>Shopier ID kombinasyonu</th><th>Yerel kayıt</th><th>Sonuç</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td><code>' . esc_html($row['variation_id']) . '</code></td>';
            echo '<td>';
            if (empty($row['attributes'])) {
                echo 'Özellik çözümlenemedi';
            } else {
                $labels = [];
                foreach ($row['attributes'] as $attribute) {
                    $labels[] = $attribute['label'] . ': ' . $attribute['value'];
                }
                echo esc_html(implode(', ', $labels));
            }
            echo '</td><td>';
            if (is_wp_error($row['resolved'])) {
                echo '—';
            } else {
                $pairs = [];
                foreach ($row['resolved']['attribute_mappings'] as $mapping) {
                    $pairs[] = esc_html($mapping['shopier_group_title'])
                        . ' (<code>' . esc_html($mapping['shopier_group_id']) . '</code>)'
                        . ' → ' . esc_html($mapping['shopier_selection_title'])
                        . ' (<code>' . esc_html($mapping['shopier_selection_id']) . '</code>)';
                }
                echo implode('<br>', $pairs);
                echo '<br><small>Kombinasyon imzası: <code>'
                    . esc_html($row['resolved']['selection_signature'])
                    . '</code></small>';
            }
            echo '</td><td>';
            if ($row['saved']) {
                echo '<span style="color:#008a20;">Kaydedildi ve güncel</span>';
            } else {
                echo '<span style="color:#b32d2e;">Kaydedilmedi veya güncel değil</span>';
            }
            echo '</td><td>';
            if ($row['duplicate']) {
                echo '<span style="color:#b32d2e;font-weight:600;">Shopier kombinasyonu başka bir varyasyonla aynı</span>';
            } elseif (is_wp_error($row['resolved'])) {
                echo '<span style="color:#b32d2e;">' . esc_html($row['resolved']->get_error_message()) . '</span>';
            } else {
                echo '<span style="color:#008a20;">Eşleme tamam</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">Shopier varyasyon kontrolü ve yerel ID eşlemelerinin kaydı, Ürünler sekmesindeki <strong>Aktar</strong> düğmesiyle tek işlemde yapılır.</p>';
    }

    private static function render_shopier_variation_comparison($product, $preview_rows)
    {
        $product_id = absint($product->get_id());
        $shopier_product_id = WSS_Product_Sync::get_existing_shopier_product_id($product_id);

        echo '<h3>Shopier ürünüyle karşılaştır ve yerel eşlemeyi kaydet</h3>';
        echo '<p>Önce ürün ID eşleştirmesini yapın. Shopier varyasyonları GET isteğiyle okunur; yerel kayıt düğmesi yalnızca kombinasyonlar birebir eşleşirse WooCommerce eşlemelerini kaydeder. Shopier’de ürün oluşturma veya güncelleme yapılmaz.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:620px;">';
        echo '<input type="hidden" name="action" value="wss_save_preview_product_mapping">';
        echo '<input type="hidden" name="wc_product_id" value="' . esc_attr($product_id) . '">';
        wp_nonce_field('wss_save_preview_product_mapping_' . $product_id);
        echo '<label for="wss-preview-shopier-product-id"><strong>Shopier ürün ID</strong></label><br>';
        echo '<input id="wss-preview-shopier-product-id" name="shopier_product_id" type="text" class="regular-text" value="' . esc_attr($shopier_product_id ?: '') . '" required pattern="[A-Za-z0-9_-]+" maxlength="100"> ';
        submit_button('Shopier ürün ID eşleştirmesini kaydet', 'secondary', 'submit', false);
        echo '</form>';

        if (empty($shopier_product_id)) {
            echo '<p>Shopier ürün ID eşleştirmesi kaydedilince Shopier varyasyon karşılaştırması açılır.</p>';
            return;
        }

        echo '<p>Bağlı Shopier ürün ID: <code>' . esc_html($shopier_product_id) . '</code></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0;">';
        echo '<input type="hidden" name="action" value="wss_load_shopier_variation_comparison">';
        echo '<input type="hidden" name="wc_product_id" value="' . esc_attr($product_id) . '">';
        wp_nonce_field('wss_load_shopier_variation_comparison_' . $product_id);
        submit_button('Shopier varyasyonlarını salt-okunur karşılaştır', 'secondary', 'submit', false);
        echo '</form>';

        $catalog = get_transient(self::get_shopier_variations_transient_key($product_id));
        if (
            !is_array($catalog) ||
            (string) ($catalog['product_id'] ?? '') !== (string) $shopier_product_id
        ) {
            echo '<p>Shopier varyasyonlarını karşılaştırmak için yükleme düğmesine basın.</p>';
            return;
        }

        $comparison = self::compare_product_variations($product, $catalog);
        echo '<p>Shopier ürünü: <strong>' . esc_html($catalog['title'] ?: 'Başlık yok') . '</strong> '
            . '(ID: <code>' . esc_html($catalog['product_id']) . '</code>) — '
            . esc_html(count($preview_rows)) . ' WooCommerce varyasyonu / '
            . esc_html($comparison['shopier_variant_count']) . ' Shopier varyasyonu. '
            . ($comparison['complete']
                ? '<strong style="color:#008a20;">Birebir eşleşme</strong>'
                : '<strong style="color:#b32d2e;">Eksik veya uyuşmayan kombinasyon</strong>')
            . '</p>';

        echo '<table class="widefat striped"><thead><tr><th>WC varyasyon ID</th><th>WooCommerce kombinasyonu</th><th>Shopier kombinasyonu</th><th>Durum</th></tr></thead><tbody>';
        foreach ($comparison['rows'] as $row) {
            echo '<tr><td><code>' . esc_html($row['variation_id']) . '</code></td><td>';
            $attribute_labels = [];
            foreach ($row['attributes'] as $attribute) {
                $attribute_labels[] = $attribute['label'] . ': ' . $attribute['value'];
            }
            echo esc_html(implode(', ', $attribute_labels));
            echo '</td><td>';

            if (is_wp_error($row['resolved'])) {
                echo '—</td><td><span style="color:#b32d2e;">'
                    . esc_html($row['resolved']->get_error_message())
                    . '</span></td></tr>';
                continue;
            }

            if (!$row['shopier_variant']) {
                echo '—</td><td><span style="color:#b32d2e;">Shopier’de bu ID kombinasyonu yok</span></td></tr>';
                continue;
            }

            $shopier_labels = [];
            foreach ($row['shopier_variant']['selection_pairs'] as $pair) {
                $shopier_labels[] = '<code>' . esc_html($pair[0]) . '</code>'
                    . ' → <code>' . esc_html($pair[1]) . '</code>';
            }
            echo implode('<br>', $shopier_labels);
            echo '</td><td><span style="color:#008a20;">Birebir ID eşleşmesi</span></td></tr>';
        }
        echo '</tbody></table>';

        if (!$comparison['complete']) {
            return;
        }

        echo '<p>Onaylandığında yalnızca WooCommerce–Shopier varyasyon bağlantıları veritabanına kaydedilir. Bu işlem Shopier API’sine yazma isteği göndermez.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wss_save_product_variation_mappings">';
        echo '<input type="hidden" name="wc_product_id" value="' . esc_attr($product_id) . '">';
        wp_nonce_field('wss_save_product_variation_mappings_' . $product_id);
        submit_button('Doğrulanan yerel varyasyon eşleştirmelerini kaydet', 'primary');
        echo '</form>';
    }

    private static function render_product_variations_legacy()
    {
        $page = isset($_GET['wss_variation_page'])
            ? max(1, absint(wp_unslash($_GET['wss_variation_page'])))
            : 1;
        $variations = wc_get_products([
            'status' => ['publish', 'private'],
            'type' => ['variation'],
            'limit' => 50,
            'page' => $page,
            'paginate' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
            'return' => 'objects',
        ]);
        $variation_id = isset($_GET['wss_variation_id'])
            ? absint(wp_unslash($_GET['wss_variation_id']))
            : 0;
        $variation = $variation_id ? wc_get_product($variation_id) : false;
        $product_id = $variation && $variation->is_type('variation')
            ? absint($variation->get_parent_id())
            : 0;
        $product = $product_id ? wc_get_product($product_id) : false;

        echo '<h2>Varyasyon Eşleştirme</h2>';
        echo '<p>Eşleştirmenin hedefi seçtiğiniz WooCommerce varyasyonudur. Shopier seçim ID’leri, varyasyonun bağlı olduğu ürünün Shopier kataloğundan alınır. Bu ekran Shopier üzerinde değişiklik yapmaz.</p>';

        echo '<form method="get" style="margin:15px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
        echo '<input type="hidden" name="tab" value="variations">';
        echo '<input type="hidden" name="wss_variation_page" value="' . esc_attr($page) . '">';
        echo '<label for="wss-variation-id"><strong>WooCommerce varyasyonu</strong></label> ';
        echo '<select id="wss-variation-id" name="wss_variation_id" required>';
        echo '<option value="">Varyasyon seçin</option>';
        $listed_variation_ids = [];

        foreach ($variations->products as $candidate) {
            $parent = wc_get_product($candidate->get_parent_id());

            if (!$parent || !$parent->is_type('variable')) {
                continue;
            }

            $attributes = WSS_WooCommerce_Attribute_Resolver::get_variation_attributes(
                $candidate->get_id()
            );
            $display_attributes = self::get_variation_attribute_display($candidate);
            $attribute_label = is_wp_error($attributes)
                ? 'Özellik bilgisi eksik'
                : implode(', ', array_map(static function ($attribute) {
                    return $attribute['label'] . ': ' . $attribute['value'];
                }, $display_attributes));
            $label = sprintf(
                '%s — %s (Varyasyon ID: %d)',
                $parent->get_name(),
                $attribute_label,
                $candidate->get_id()
            );

            printf(
                '<option value="%1$d" %2$s>%3$s</option>',
                absint($candidate->get_id()),
                selected($variation_id, $candidate->get_id(), false),
                esc_html($label)
            );
            $listed_variation_ids[] = absint($candidate->get_id());
        }

        if (
            $variation &&
            $variation->is_type('variation') &&
            !in_array($variation_id, $listed_variation_ids, true)
        ) {
            $parent = wc_get_product($variation->get_parent_id());
            if ($parent && $parent->is_type('variable')) {
                $attributes = WSS_WooCommerce_Attribute_Resolver::get_variation_attributes(
                    $variation_id
                );
                $display_attributes = self::get_variation_attribute_display($variation);
                $attribute_label = is_wp_error($attributes)
                    ? 'Özellik bilgisi eksik'
                    : implode(', ', array_map(static function ($attribute) {
                        return $attribute['label'] . ': ' . $attribute['value'];
                    }, $display_attributes));
                printf(
                    '<option value="%1$d" selected="selected">%2$s — %3$s (Varyasyon ID: %1$d)</option>',
                    $variation_id,
                    esc_html($parent->get_name()),
                    esc_html($attribute_label)
                );
            }
        }

        echo '</select> ';
        submit_button('Varyasyonu aç', 'secondary', '', false);
        echo '</form>';

        if ($variations->max_num_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post(paginate_links([
                'base' => add_query_arg([
                    'page' => self::PAGE_SLUG,
                    'tab' => 'variations',
                    'wss_variation_page' => '%#%',
                ], admin_url('admin.php')),
                'format' => '',
                'current' => $page,
                'total' => $variations->max_num_pages,
                'add_args' => $variation_id ? ['wss_variation_id' => $variation_id] : false,
            ]));
            echo '</div></div>';
        }

        if (!$variation_id) {
            echo '<p>İşlem yapmak için listeden bir WooCommerce varyasyonu seçin.</p>';
            return;
        }

        if (
            !$variation ||
            !$variation->is_type('variation') ||
            !$product ||
            !$product->is_type('variable')
        ) {
            echo '<div class="notice notice-error inline"><p>Seçilen WooCommerce varyasyonu bulunamadı veya değişken bir ürüne bağlı değil.</p></div>';
            return;
        }

        self::render_mapping_notice();

        $shopier_product_id = WSS_Product_Sync::get_existing_shopier_product_id(
            $product_id
        );

        $variation_attributes = WSS_WooCommerce_Attribute_Resolver::get_variation_attributes(
            $variation_id
        );
        $display_attributes = self::get_variation_attribute_display($variation);
        $selected_variation_label = is_wp_error($variation_attributes)
            ? $variation->get_name()
            : implode(', ', array_map(static function ($attribute) {
                return $attribute['label'] . ': ' . $attribute['value'];
            }, $display_attributes));

        echo '<h3>Seçilen varyasyon: ' . esc_html($selected_variation_label) . ' <small>(Varyasyon ID: ' . esc_html($variation_id) . ')</small></h3>';
        echo '<p>Ana ürün: ' . esc_html($product->get_name()) . ' (WooCommerce ürün ID: ' . esc_html($product_id) . '). Shopier ürün ID bu ana ürüne aittir.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:620px;">';
        echo '<input type="hidden" name="action" value="wss_save_shopier_product_mapping">';
        echo '<input type="hidden" name="wc_product_id" value="' . esc_attr($product_id) . '">';
        echo '<input type="hidden" name="wc_variation_id" value="' . esc_attr($variation_id) . '">';
        wp_nonce_field('wss_save_shopier_product_mapping_' . $product_id);
        echo '<p><label for="wss-shopier-product-id"><strong>Shopier ürün ID</strong></label><br>';
        echo '<input id="wss-shopier-product-id" name="shopier_product_id" type="text" class="regular-text" value="' . esc_attr($shopier_product_id ?: '') . '" required pattern="[A-Za-z0-9_-]+" maxlength="100">';
        echo '<p class="description">Bu alan ID eşleştirmesidir; Shopier ürün başlığı eşleştirme amacıyla kullanılmaz.</p>';
        submit_button('Ürün eşleştirmesini kaydet', 'secondary', 'submit', false);
        echo '</form>';

        if (!$shopier_product_id) {
            echo '<p>Shopier ürün ID’sini kaydedin; ardından Shopier ürününün grup/seçim ID’lerini salt-okunur olarak yükleyebilirsiniz.</p>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0;">';
        echo '<input type="hidden" name="action" value="wss_load_shopier_product">';
        echo '<input type="hidden" name="wc_product_id" value="' . esc_attr($product_id) . '">';
        echo '<input type="hidden" name="wc_variation_id" value="' . esc_attr($variation_id) . '">';
        wp_nonce_field('wss_load_shopier_product_' . $product_id);
        submit_button('Shopier grup ve seçim ID’lerini yükle', 'secondary', 'submit', false);
        echo '</form>';

        $catalog = get_transient(self::get_shopier_catalog_transient_key($product_id));

        if (!is_array($catalog) || ($catalog['product_id'] ?? '') !== (string) $shopier_product_id) {
            echo '<p>Shopier ürün eşleştirmesini kontrol etmek ve güncel seçim ID’lerini almak için yükleme düğmesine basın.</p>';
            return;
        }

        if ($catalog['title'] !== '') {
            echo '<p>Shopier ürünü: <strong>' . esc_html($catalog['title']) . '</strong> — ID: <code>' . esc_html($catalog['product_id']) . '</code></p>';
        }

        if (empty($catalog['groups'])) {
            echo '<div class="notice notice-warning inline"><p>Shopier ürün yanıtında varyasyon grubu/seçim bulunamadı. Bu ürün için eşleştirme oluşturulamaz.</p></div>';
            return;
        }

        self::render_shopier_selection_mapping_form($product, $catalog, $variation_id);
        self::render_wc_variation_mapping_form($product, $variation);
    }

    private static function render_mapping_notice()
    {
        if (!isset($_GET['wss_mapping_notice'])) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['wss_mapping_notice']));
        $messages = [
            'global_catalog_loaded' => ['success', 'Shopier global varyasyon grupları ve seçim ID’leri salt-okunur yüklendi.'],
            'global_catalog_load_failed' => ['error', 'Shopier global varyasyon kataloğu yüklenemedi. PAT yetkisini ve API yanıtını kontrol edin.'],
            'global_catalog_required' => ['error', 'Eşleştirmeyi kaydetmeden önce Shopier global varyasyon ve seçimlerini yeniden yükleyin.'],
            'global_mapping_saved' => ['success', 'Global WooCommerce özellik eşleştirmeleri kaydedildi.'],
            'global_mapping_none' => ['warning', 'Kaydedilecek bir global özellik eşleştirmesi seçilmedi.'],
            'global_mapping_partial' => ['warning', 'Bazı global özellik eşleştirmeleri kaydedildi; başarısız özelliklerin seçimlerini ve mevcut varyasyon eşleştirmelerini kontrol edin.'],
            'global_mapping_error' => ['error', 'Global özellik eşleştirmeleri kaydedilemedi. Her WooCommerce terimi için aynı seçili Shopier grubundan benzersiz bir seçim ID’si seçin.'],
            'preview_product_invalid' => ['error', 'Önizleme için değişken bir WooCommerce ürünü seçin.'],
            'preview_product_mapping_saved' => ['success', 'WooCommerce ürününün Shopier ürün ID eşleştirmesi kaydedildi.'],
            'preview_product_mapping_error' => ['error', 'Shopier ürün ID eşleştirmesi kaydedilemedi; ürün ID’sini veya mevcut eşlemeleri kontrol edin.'],
            'preview_product_mapping_required' => ['error', 'Önce bu WooCommerce ürününü Shopier ürün ID’siyle eşleştirin.'],
            'shopier_variation_load_failed' => ['error', 'Shopier ürün varyasyonları okunamadı. Ürün ID’sini, PAT yetkisini ve API bağlantısını kontrol edin.'],
            'shopier_variation_data_invalid' => ['error', 'Shopier ürün yanıtı güvenli varyasyon ID kombinasyonları içermiyor; eşleştirme kaydedilmedi.'],
            'shopier_variations_loaded' => ['success', 'Shopier ürün varyasyonları salt-okunur olarak yüklendi ve WooCommerce ile karşılaştırıldı.'],
            'variation_mapping_comparison_incomplete' => ['error', 'Varyasyon kombinasyonları birebir eşleşmiyor. Yerel varyasyon eşleştirmeleri kaydedilmedi.'],
            'variation_mapping_save_success' => ['success', 'Shopier ile birebir doğrulanan yerel varyasyon eşleştirmeleri kaydedildi. Shopier üzerinde değişiklik yapılmadı.'],
            'variation_mapping_save_partial' => ['warning', 'Bazı yerel varyasyon eşleştirmeleri kaydedildi, bazıları başarısız oldu. Önizlemeyi yenileyip sonucu kontrol edin.'],
            'variation_mapping_save_error' => ['error', 'Yerel varyasyon eşleştirmeleri kaydedilemedi. Kombinasyon çakışmalarını ve ürün eşleştirmesini kontrol edin.'],
            'product_mapping_saved' => ['success', 'Shopier ürün ID eşleştirmesi kaydedildi.'],
            'product_mapping_error' => ['error', 'Shopier ürün ID eşleştirmesi kaydedilemedi. ID çakışmasını veya WooCommerce ürününü kontrol edin.'],
            'product_mapping_required' => ['error', 'Önce WooCommerce ürününü Shopier ürün ID’siyle eşleştirin.'],
            'shopier_product_load_failed' => ['error', 'Shopier ürünü alınamadı. PAT yetkisini, ürün ID’sini ve API bağlantısını kontrol edin.'],
            'shopier_product_data_invalid' => ['error', 'Shopier yanıtı ürün ve grup/seçim ID’leriyle eşleşmedi; veri güvenliği için eşleştirme yapılmadı.'],
            'shopier_product_loaded' => ['success', 'Shopier ürün ve ID tabanlı varyasyon bilgileri salt-okunur yüklendi.'],
            'shopier_catalog_required' => ['error', 'Eşleştirme kaydedilmeden önce Shopier ürün ID’lerini yeniden yükleyin.'],
            'attribute_mapping_saved' => ['success', 'Shopier seçimlerinin WooCommerce özellik eşleştirmeleri kaydedildi.'],
            'attribute_mapping_none' => ['warning', 'Kaydedilecek bir eşleştirme seçilmedi.'],
            'attribute_mapping_partial' => ['warning', 'Bazı seçim eşleştirmeleri kaydedildi, bazıları reddedildi. Lütfen alanları ve mevcut eşleştirmeleri kontrol edin.'],
            'attribute_mapping_error' => ['error', 'Seçim eşleştirmeleri kaydedilemedi. Aynı grup/değer çakışmalarını ve WooCommerce özellik değerlerini kontrol edin.'],
            'variation_mapping_saved' => ['success', 'Varyasyon eşleştirmesi kaydedildi.'],
            'variation_mapping_error' => ['error', 'Varyasyon eşleştirmesi kaydedilemedi. Her WooCommerce özelliği için doğru Shopier seçim ID’sini seçin; tekrar eden grup veya kombinasyon kullanmayın.'],
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        [$type, $message] = $messages[$notice];

        if (
            in_array(
                $notice,
                ['shopier_product_load_failed', 'global_catalog_load_failed', 'shopier_variation_load_failed'],
                true
            ) &&
            isset($_GET['wss_mapping_http_status']) &&
            absint($_GET['wss_mapping_http_status']) > 0
        ) {
            $message .= ' HTTP ' . absint($_GET['wss_mapping_http_status']) . '.';
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    private static function get_product_attribute_options($product)
    {
        $options = [];

        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute->get_variation()) {
                continue;
            }

            $taxonomy = $attribute->is_taxonomy()
                ? $attribute->get_name()
                : '';
            $attribute_name = WSS_Attribute_Map::normalize_attribute_name(
                $attribute->get_name()
            );

            if ($attribute_name === '') {
                continue;
            }

            if ($taxonomy !== '') {
                $terms = wc_get_product_terms(
                    $product->get_id(),
                    $taxonomy,
                    ['fields' => 'all']
                );

                if (is_wp_error($terms)) {
                    continue;
                }

                foreach ($terms as $term) {
                    $key = 'attribute-option-' . count($options);
                    $options[$key] = [
                        'attribute' => $attribute_name,
                        'attribute_label' => $attribute->get_name(),
                        'value' => (string) $term->slug,
                        'value_label' => (string) $term->name,
                        'scope_id' => 0,
                    ];
                }
            } else {
                foreach ($attribute->get_options() as $value) {
                    $value = trim((string) $value);

                    if ($value === '') {
                        continue;
                    }

                    $key = 'attribute-option-' . count($options);
                    $options[$key] = [
                        'attribute' => $attribute_name,
                        'attribute_label' => $attribute->get_name(),
                        'value' => $value,
                        'value_label' => $value,
                        'scope_id' => absint($product->get_id()),
                    ];
                }
            }
        }

        return $options;
    }

    private static function render_shopier_selection_mapping_form(
        $product,
        $catalog,
        $variation_id
    )
    {
        $product_id = absint($product->get_id());
        $attribute_options = self::get_product_attribute_options($product);
        $existing_mappings = WSS_Attribute_Map::get_for_product($product_id);
        $existing_by_selection = [];

        foreach ($existing_mappings as $mapping) {
            $existing_by_selection[
                $mapping['shopier_group_id'] . ':' . $mapping['shopier_selection_id']
            ] = $mapping;
        }

        echo '<h3>Shopier seçimlerini WooCommerce özelliklerine bağla</h3>';
        echo '<p>Her Shopier seçim satırında hedef WooCommerce özellik/değerini seçin. ID’ler Shopier API yanıtından alınır; adlar yalnızca ekranda ayırt etmeye yarar.</p>';

        if (empty($attribute_options)) {
            echo '<div class="notice notice-error inline"><p>Bu ürünün varyasyonlara açık WooCommerce özellikleri veya kullanılabilir değerleri bulunamadı.</p></div>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wss_save_attribute_mappings">';
        echo '<input type="hidden" name="wc_product_id" value="' . esc_attr($product_id) . '">';
        echo '<input type="hidden" name="wc_variation_id" value="' . esc_attr($variation_id) . '">';
        wp_nonce_field('wss_save_attribute_mappings_' . $product_id);
        echo '<table class="widefat striped"><thead><tr><th>Shopier grubu</th><th>Shopier seçimi</th><th>WooCommerce özelliği / değeri</th></tr></thead><tbody>';

        foreach ($catalog['groups'] as $group_id => $group) {
            foreach ($group['selections'] as $selection_id => $selection_title) {
                $selected_key = '';
                $existing = $existing_by_selection[$group_id . ':' . $selection_id] ?? null;

                if ($existing) {
                    foreach ($attribute_options as $option_key => $option) {
                        if (
                            $option['attribute'] === WSS_Attribute_Map::normalize_attribute_name($existing['wc_attribute']) &&
                            $option['value'] === (string) $existing['wc_value'] &&
                            (int) $option['scope_id'] === (int) $existing['wc_scope_id']
                        ) {
                            $selected_key = $option_key;
                            break;
                        }
                    }
                }

                echo '<tr>';
                echo '<td>' . esc_html($group['title']) . '<br><code>' . esc_html($group_id) . '</code></td>';
                echo '<td>' . esc_html($selection_title) . '<br><code>' . esc_html($selection_id) . '</code></td>';
                echo '<td><select name="selection_mapping[' . esc_attr($group_id) . '][' . esc_attr($selection_id) . ']">';
                echo '<option value="">Eşleştirme yok</option>';

                foreach ($attribute_options as $option_key => $option) {
                    $label = $option['attribute_label'] . ' → ' . $option['value_label'];
                    printf(
                        '<option value="%1$s" %2$s>%3$s</option>',
                        esc_attr($option_key),
                        selected($selected_key, $option_key, false),
                        esc_html($label)
                    );
                }

                echo '</select></td></tr>';
            }
        }

        echo '</tbody></table>';
        echo '<p class="description">Eşleştirme yok seçeneği, mevcut Shopier seçim eşleştirmesini silmez; yalnızca bu gönderimde atlanır.</p>';
        submit_button('Seçim eşleştirmelerini kaydet');
        echo '</form>';
    }

    private static function render_wc_variation_mapping_form($product, $variation)
    {
        $product_id = absint($product->get_id());
        $variation_id = absint($variation->get_id());
        $attributes = WSS_WooCommerce_Attribute_Resolver::get_variation_attributes(
            $variation_id
        );
        $display_attributes = self::get_variation_attribute_display($variation);

        echo '<h3>Seçilen WooCommerce varyasyonunu Shopier kombinasyonuna bağla</h3>';
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:15px;margin:12px 0;">';
        echo '<strong>' . esc_html($variation->get_name()) . '</strong> — ID: ' . esc_html($variation_id);

        if (is_wp_error($attributes)) {
            echo '<p class="description">' . esc_html($attributes->get_error_message()) . '</p></div>';
            return;
        }

        $existing = WSS_Variation_Map::get_for_variation($variation_id);
        $selected_mapping_ids = array_map(
            'intval',
            array_column($existing, 'attribute_map_id')
        );
        $product_mappings = WSS_Attribute_Map::get_for_product($product_id);
        $available_by_attribute = [];

        foreach ($product_mappings as $mapping) {
            $attribute = WSS_Attribute_Map::normalize_attribute_name(
                $mapping['wc_attribute']
            );

            if (
                isset($attributes[$attribute]) &&
                (string) $attributes[$attribute] === (string) $mapping['wc_value']
            ) {
                $available_by_attribute[$attribute][] = $mapping;
            }
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wss_save_variation_mapping">';
        echo '<input type="hidden" name="wc_product_id" value="' . esc_attr($product_id) . '">';
        echo '<input type="hidden" name="wc_variation_id" value="' . esc_attr($variation_id) . '">';
        wp_nonce_field('wss_save_variation_mapping_' . $product_id . '_' . $variation_id);

        foreach ($attributes as $attribute => $value) {
            $matching = $available_by_attribute[$attribute] ?? [];
            $selected_id = '';
            $attribute_display = $display_attributes[$attribute] ?? [
                'label' => $attribute,
                'value' => $value,
            ];

            foreach ($matching as $mapping) {
                if (in_array((int) $mapping['id'], $selected_mapping_ids, true)) {
                    $selected_id = (string) $mapping['id'];
                    break;
                }
            }

            echo '<p><label><strong>' . esc_html($attribute_display['label']) . '</strong> (' . esc_html($attribute_display['value']) . ')<br>';
            echo '<select name="attribute_map_ids[]" ' . (empty($matching) ? 'disabled' : 'required') . '>';
            echo '<option value="">Shopier seçimi seçin</option>';

            foreach ($matching as $mapping) {
                $label = $mapping['shopier_group_title'] . ' → ' .
                    $mapping['shopier_selection_title'] . ' [' .
                    $mapping['shopier_group_id'] . ' / ' .
                    $mapping['shopier_selection_id'] . ']';
                printf(
                    '<option value="%1$d" %2$s>%3$s</option>',
                    (int) $mapping['id'],
                    selected($selected_id, (string) $mapping['id'], false),
                    esc_html($label)
                );
            }

            echo '</select></label></p>';

            if (empty($matching)) {
                echo '<p class="description">Bu WooCommerce özellik/değeri için Shopier seçim eşleştirmesi yok. Önce yukarıdaki seçim eşleştirmelerini kaydedin.</p>';
            }
        }

        submit_button(
            'Seçilen varyasyon eşleştirmesini kaydet',
            'secondary',
            'submit',
            false,
            ['disabled' => empty($attributes) || count($available_by_attribute) !== count($attributes)]
        );
        echo '</form></div>';
    }

    private static function render_products()
    {
        $status = isset($_GET['product_status'])
            ? sanitize_key(wp_unslash($_GET['product_status']))
            : 'unmatched';

        if (!in_array($status, ['matched', 'unmatched'], true)) {
            $status = 'unmatched';
        }

        if (!isset($_GET['product_status'])) {
            global $wpdb;
            $unmatched_count = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} AS p
                LEFT JOIN {$wpdb->prefix}wss_product_map AS pm ON pm.wc_product_id = p.ID
                WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'pending', 'private', 'future')
                AND pm.id IS NULL"
            );

            if ($unmatched_count !== null && (int) $unmatched_count === 0) {
                $status = 'matched';
            }
        }

        $search = isset($_GET['wss_search'])
            ? sanitize_text_field(wp_unslash($_GET['wss_search']))
            : '';

        $page = isset($_GET['wss_product_page'])
            ? max(1, absint($_GET['wss_product_page']))
            : 1;

        $product_type = isset($_GET['wss_product_type'])
            ? sanitize_key(wp_unslash($_GET['wss_product_type']))
            : 'all';

        if (!in_array($product_type, ['all', 'simple', 'variable'], true)) {
            $product_type = 'all';
        }

        $stock_status = isset($_GET['wss_stock_status'])
            ? sanitize_key(wp_unslash($_GET['wss_stock_status']))
            : 'all';

        if (!in_array($stock_status, ['all', 'instock', 'outofstock', 'onbackorder'], true)) {
            $stock_status = 'all';
        }

        $query_args = [
            'status'   => ['publish', 'draft', 'pending', 'private', 'future'],
            'type'     => $product_type === 'all'
                ? ['simple', 'variable', 'grouped', 'external']
                : [$product_type],
            'limit'    => 20,
            'page'     => $page,
            'paginate' => true,
            'orderby'  => 'title',
            'order'    => 'ASC',
            'return'   => 'objects',
            's'        => $search,
        ];

        if ($stock_status !== 'all') {
            $query_args['stock_status'] = $stock_status;
        }

        $result = wc_get_products($query_args);

        $products = [];
        foreach ($result->products as $product) {
            $mapping = self::get_product_mapping($product->get_id());
            $matched = !empty($mapping);

            if (($status === 'matched' && $matched) ||
                ($status === 'unmatched' && !$matched)) {
                $products[] = [$product, $mapping];
            }
        }

        $base = admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=products');

        echo '<h2>Ürün Yönetimi</h2>';
        echo '<nav class="nav-tab-wrapper">';
        foreach ([
            'unmatched' => 'Eşleştirilmemiş Ürünler',
            'matched' => 'Eşleştirilmiş Ürünler',
        ] as $key => $label) {
            $url = add_query_arg('product_status', $key, $base);
            $class = $status === $key ? 'nav-tab nav-tab-active' : 'nav-tab';
            printf('<a class="%s" href="%s">%s</a>',
                esc_attr($class), esc_url($url), esc_html($label));
        }
        echo '</nav>';

        echo '<form method="get" style="margin:15px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
        echo '<input type="hidden" name="tab" value="products">';
        echo '<input type="hidden" name="product_status" value="' . esc_attr($status) . '">';
        echo '<input type="search" name="wss_search" value="' . esc_attr($search) . '" placeholder="Ürün adı veya SKU ara"> ';

        echo '<select name="wss_product_type">';
        foreach ([
            'all' => 'Tüm ürün türleri',
            'simple' => 'Basit ürün',
            'variable' => 'Değişken ürün',
        ] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' .
                selected($product_type, $value, false) . '>' .
                esc_html($label) . '</option>';
        }
        echo '</select> ';

        echo '<select name="wss_stock_status">';
        foreach ([
            'all' => 'Tüm stok durumları',
            'instock' => 'Stokta',
            'outofstock' => 'Stokta yok',
            'onbackorder' => 'Ön sipariş',
        ] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' .
                selected($stock_status, $value, false) . '>' .
                esc_html($label) . '</option>';
        }
        echo '</select> ';

        submit_button('Filtrele', 'secondary', '', false);
        echo '</form>';

        self::render_products_notice();
        $shopier_catalog = get_transient(self::get_shopier_products_catalog_transient_key());
        $shopier_catalog = is_array($shopier_catalog) ? $shopier_catalog : [];
        usort($shopier_catalog, static function ($left, $right) {
            $title_order = strnatcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
            return $title_order !== 0
                ? $title_order
                : strnatcasecmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });
        if (empty($shopier_catalog)) {
            echo '<div class="notice notice-info inline"><p>Shopier ID eşleştirmesi için ürün listesini yükleyin.</p></div>';
        }
        $shopier_by_id = [];
        foreach ($shopier_catalog as $shopier_product) {
            if (is_array($shopier_product) && !empty($shopier_product['id'])) {
                $shopier_by_id[(string) $shopier_product['id']] = $shopier_product;
            }
        }

        $active_job = WSS_Queue::get_active_job();
        $active_job_id = $active_job ? (int) $active_job['id'] : 0;
        $job_writes_blocked = $active_job_id > 0 || WSS_Queue::has_active_write_job();
        $requested_job_id = isset($_GET['wss_job_id'])
            ? absint(wp_unslash($_GET['wss_job_id']))
            : 0;
        if ($active_job_id && $requested_job_id && $active_job_id !== $requested_job_id) {
            self::render_product_job_report($active_job_id);
            self::render_product_job_report($requested_job_id);
        } else {
            self::render_product_job_report($requested_job_id ?: $active_job_id);
        }
        self::render_product_csv_report();

        echo '<style>
            .wss-toolbar{display:flex;gap:12px;align-items:stretch;flex-wrap:wrap;margin:15px 0;padding:12px 14px;background:#fff;border:1px solid #dcdcde;border-radius:6px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
            .wss-toolbar form{margin:0;display:flex;align-items:center;gap:8px}
            .wss-toolbar .button{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:36px;min-height:36px;padding:0 14px;line-height:1;margin:0;box-sizing:border-box}
            .wss-toolbar .button .dashicons,.wss-icon-btn .dashicons,.wss-file-picker .dashicons{display:block;flex:0 0 auto;font-size:16px;width:16px;height:16px;line-height:16px;margin:0;vertical-align:baseline}
            .wss-file-picker{position:relative;display:inline-flex;align-items:center;gap:8px;height:36px;box-sizing:border-box;padding:0 12px;border:1px dashed #a7aaad;border-radius:4px;background:#f6f7f7;color:#50575e;cursor:pointer;max-width:280px;transition:border-color .15s,background .15s}
            .wss-file-picker:hover,.wss-file-picker:focus-within{border-color:#2271b1;background:#f0f6fc;color:#2271b1}
            .wss-file-picker input[type=file]{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
            .wss-file-picker .wss-file-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px}
            .wss-toolbar-group{display:flex;align-items:center;gap:8px;padding-right:12px;border-right:1px solid #dcdcde}
            .wss-toolbar-group:last-child{border-right:0;padding-right:0}
            .wss-toolbar-meta{margin:0 0 10px;color:#50575e}
            .wss-icon-btn{position:relative;display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;padding:0;border:1px solid #c3c4c7;border-radius:6px;background:#fff;color:#2271b1;cursor:pointer;transition:background .15s,color .15s,border-color .15s}
            .wss-icon-btn .dashicons{font-size:18px;width:18px;height:18px;line-height:18px}
            .wss-icon-btn:hover:not(:disabled),.wss-icon-btn:focus-visible:not(:disabled){background:#2271b1;border-color:#2271b1;color:#fff}
            .wss-icon-btn.is-danger{color:#b32d2e}
            .wss-icon-btn.is-danger:hover:not(:disabled){background:#b32d2e;border-color:#b32d2e;color:#fff}
            .wss-icon-btn.is-success{background:#00a32a;border-color:#00a32a;color:#fff}
            .wss-icon-btn.is-success:hover:not(:disabled){background:#008a20;border-color:#008a20}
            .wss-icon-btn:disabled{opacity:.45;cursor:not-allowed}
            .wss-row-actions{display:flex;gap:6px;flex-wrap:wrap}
            [data-wss-tip]{position:relative}
            [data-wss-tip]::after{content:attr(data-wss-tip);position:absolute;bottom:calc(100% + 8px);right:0;max-width:280px;width:max-content;padding:6px 9px;border-radius:4px;background:#1d2327;color:#fff;font-size:12px;font-weight:400;line-height:1.4;text-align:left;white-space:normal;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .12s;z-index:100000}
            [data-wss-tip]:hover::after,[data-wss-tip]:focus-visible::after{opacity:1;visibility:visible}
            .wss-info-icon{display:inline-flex;vertical-align:middle;margin-left:4px;color:#2271b1;cursor:help}
            .wss-info-icon .dashicons{font-size:18px;width:18px;height:18px}
        </style>';

        echo '<div class="wss-toolbar">';
        echo '<div class="wss-toolbar-group"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wss_sync_shopier_stocks">';
        wp_nonce_field('wss_sync_shopier_stocks');
        echo '<button type="submit" class="button button-primary"><span class="dashicons dashicons-update"></span>Shopier stoklarını güncelle</button>';
        echo '</form></div>';

        echo '<div class="wss-toolbar-group"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wss_load_shopier_products_catalog">';
        wp_nonce_field('wss_load_shopier_products_catalog');
        echo '<button type="submit" class="button button-primary"><span class="dashicons dashicons-update"></span>Shopier ürün listesini yükle / yenile</button>';
        echo '</form></div>';

        echo '<div class="wss-toolbar-group"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wss_export_products_csv">';
        echo '<input type="hidden" name="product_status" value="' . esc_attr($status) . '">';
        echo '<input type="hidden" name="wss_search" value="' . esc_attr($search) . '">';
        echo '<input type="hidden" name="wss_product_type" value="' . esc_attr($product_type) . '">';
        echo '<input type="hidden" name="wss_stock_status" value="' . esc_attr($stock_status) . '">';
        wp_nonce_field('wss_export_products_csv');
        echo '<button type="submit" class="button"><span class="dashicons dashicons-download"></span>Shopier ürün bağlantılarını CSV dışa aktar</button>';
        echo '</form></div>';

        echo '<div class="wss-toolbar-group"><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wss_import_products_csv">';
        wp_nonce_field('wss_import_products_csv');
        echo '<label class="screen-reader-text" for="wss-mapping-csv">Shopier ürün bağlantıları CSV dosyası</label>';
        echo '<label class="wss-file-picker"><span class="dashicons dashicons-media-spreadsheet"></span><span class="wss-file-name">CSV dosyası seç…</span>';
        echo '<input id="wss-mapping-csv" type="file" name="wss_mapping_csv" accept=".csv,text/csv" required></label>';
        echo '<button type="submit" class="button"' . ($job_writes_blocked ? ' disabled' : '') . '><span class="dashicons dashicons-upload"></span>Shopier ürün bağlantılarını CSV içe aktar</button>';
        echo '</form></div></div>';
        echo '<script>(function(){var i=document.getElementById("wss-mapping-csv");if(!i)return;i.addEventListener("change",function(){var n=i.parentNode.querySelector(".wss-file-name");n.textContent=i.files.length?i.files[0].name:"CSV dosyası seç…";});})();</script>';

        if (!empty($shopier_catalog)) {
            echo '<p class="wss-toolbar-meta">Shopier kataloğundan ' . esc_html(count($shopier_catalog)) . ' ürün yüklendi. Ürün ID eşleştirmesi Shopier’de işlem yapmaz.</p>';
        }

        echo '<p>Toplam WooCommerce ürünü: ' . esc_html($result->total) . '</p>';

        if (!$products) {
            echo '<p>Bu listede gösterilecek ürün bulunamadı.</p>';
        } else {
            $test_results = get_transient(self::get_product_test_results_transient_key());
            $test_results = is_array($test_results) ? $test_results : [];
            $filter_fields = [
                'product_status' => $status,
                'wss_search' => $search,
                'wss_product_type' => $product_type,
                'wss_stock_status' => $stock_status,
                'wss_product_page' => $page,
            ];
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="wss_products_action">';
            foreach ($filter_fields as $field => $value) {
                echo '<input type="hidden" name="' . esc_attr($field) . '" value="' . esc_attr($value) . '">';
            }
            wp_nonce_field('wss_products_action');
            echo '<div class="wss-product-bulk-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0;">';
            if ($status === 'unmatched') {
                echo '<button type="submit" class="button button-primary wss-bulk-map" name="operation" value="bulk_map" disabled>Eşleştir</button> ';
                echo '<button type="submit" class="button button-primary wss-bulk-create" name="operation" value="bulk_create"' . ($job_writes_blocked ? ' disabled' : '') . '>Seçilenleri Shopier’de oluştur/senkronize et</button> ';
                echo '<span class="description">Değişken ürünler Shopier’de tek bir varyasyonlu ürün olarak oluşturulur. Ürün eşleme ve aktarım işlemleri seçilen ürün başına yürütülür.</span>';
            } else {
                echo '<button type="submit" class="button button-primary" name="operation" value="bulk_update"' . ($job_writes_blocked ? ' disabled' : '') . '>Toplu güncelle</button> ';
                echo '<button type="submit" class="button" name="operation" value="bulk_unmap"' . ($job_writes_blocked ? ' disabled' : '') . '>Seçilen eşleşmeleri kaldır</button> ';
            }
            echo '<span class="description">Seçili ürünler: <strong class="wss-selected-product-count">0</strong></span>';
            echo '</div>';
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th><input type="checkbox" class="wss-select-all-products" aria-label="Listelenen ürünleri seç"></th><th>Görsel</th><th>WooCommerce ürünü</th><th>ID</th><th>SKU</th>';
            echo '<th>Tür</th><th>Kategori</th><th>Fiyat</th>';
            echo '<th>Stok</th><th>Varyasyon</th><th>Shopier ürünü / ID</th><th>İşlem</th>';
            echo '</tr></thead><tbody>';

            foreach ($products as [$product, $mapping]) {
                $product_id = $product->get_id();
                $edit = get_edit_post_link($product_id);

                $stock_status = $product->get_stock_status();
                $stock_labels = [
                    'instock' => 'Stokta',
                    'outofstock' => 'Stokta yok',
                    'onbackorder' => 'Ön sipariş',
                ];

                $stock_quantity = $product->managing_stock()
                    ? $product->get_stock_quantity()
                    : 'Yönetilmiyor';

                $variation_count = $product->is_type('variable')
                    ? count($product->get_children())
                    : '—';
                $category_names = [];
                foreach ($product->get_category_ids() as $category_id) {
                    $term = get_term($category_id, 'product_cat');
                    if ($term && !is_wp_error($term)) {
                        $category_names[] = $term->name;
                    }
                }

                echo '<tr>';
                echo '<td>';
                echo '<input type="checkbox" name="selected_products[]" value="' . esc_attr($product_id) . '" aria-label="' . esc_attr($product->get_name() . ' ürününü seç') . '">';
                echo '</td>';
                echo '<td>' . wp_kses_post($product->get_image([60, 60])) . '</td>';
                echo '<td>';
                if ($edit) {
                 printf( '<a href="%s" target="_blank" rel="noopener noreferrer"><strong>%s</strong></a>', esc_url($edit), esc_html($product->get_name()) );
                } else {
                    echo '<strong>' . esc_html($product->get_name()) . '</strong>';
                }
                echo '</td>';
                echo '<td>' . esc_html($product_id) . '</td>';
                echo '<td>' . esc_html($product->get_sku() ?: '—') . '</td>';
               	$type_labels = ['simple' => 'Basit ürün', 'variable' => 'Değişken ürün', 'grouped' => 'Gruplanmış ürün', 'external' => 'Harici ürün',];
		$product_type_label = $type_labels[$product->get_type()] ?? $product->get_type();
		echo '<td>' . esc_html($product_type_label) . '</td>';
                echo '<td>' . esc_html($category_names ? implode(', ', $category_names) : '—') . '</td>';
                echo '<td>' . wp_kses_post($product->get_price_html() ?: '—') . '</td>';
                echo '<td>' . esc_html($stock_quantity) . '<br>';
                echo '<small>' . esc_html($stock_labels[$stock_status] ?? $stock_status) . '</small></td>';
                echo '<td>' . esc_html($variation_count);

                echo '</td>';
                echo '<td>';
                if (!$mapping && !empty($shopier_catalog)) {
                    $picker_id = 'wss-shopier-picker-' . $product_id;
                    $selected_shopier_id = '';
                    $selected_shopier_label = 'Shopier ürünü seçin';
                    echo '<div class="wss-shopier-product-picker" id="' . esc_attr($picker_id) . '" style="position:relative;min-width:190px;">';
                    echo '<select class="wss-shopier-product-value" name="shopier_product_id[' . esc_attr($product_id) . ']" style="display:none;" aria-hidden="true" tabindex="-1">';
                    echo '<option value="" ' . selected($selected_shopier_id, '', false) . '>Shopier ürünü seçin</option>';
                    foreach ($shopier_catalog as $shopier_product) {
                        $shopier_id = (string) $shopier_product['id'];
                        $shopier_title = (string) $shopier_product['title'];
                        $shopier_label = $shopier_title . ' (ID: ' . $shopier_id . ')';
                        printf(
                            '<option value="%1$s" %2$s>%3$s</option>',
                            esc_attr($shopier_id),
                            selected($selected_shopier_id, $shopier_id, false),
                            esc_html($shopier_label)
                        );
                    }
                    echo '</select>';
                    echo '<button type="button" class="button wss-shopier-product-trigger" aria-haspopup="listbox" aria-controls="' . esc_attr($picker_id . '-menu') . '" aria-expanded="false" style="width:100%;text-align:left;">'
                        . esc_html($selected_shopier_label) . '</button>';
                    echo '<div id="' . esc_attr($picker_id . '-menu') . '" class="wss-shopier-product-menu" hidden style="position:absolute;z-index:1000;top:100%;left:0;width:100%;min-width:240px;padding:8px;background:#fff;border:1px solid #8c8f94;box-shadow:0 2px 6px rgba(0,0,0,.15);">';
                    echo '<input type="search" class="regular-text wss-shopier-product-search" style="width:100%;margin-bottom:6px;" aria-label="' . esc_attr('Shopier ürün adında ara: ' . $product->get_name()) . '" placeholder="Ürün adında ara...">';
                    echo '<div class="wss-shopier-product-options" role="listbox" aria-label="' . esc_attr('Shopier ürünleri: ' . $product->get_name()) . '" style="max-height:220px;overflow-y:auto;">';
                    foreach ($shopier_catalog as $shopier_product) {
                        $shopier_id = (string) $shopier_product['id'];
                        $shopier_title = (string) $shopier_product['title'];
                        $shopier_label = $shopier_title . ' (ID: ' . $shopier_id . ')';
                        printf(
                            '<button type="button" class="wss-shopier-product-option" role="option" data-value="%1$s" data-search-title="%2$s" data-label="%3$s" aria-selected="%4$s" style="display:block;width:100%%;text-align:left;padding:6px;border:0;background:#fff;cursor:pointer;">%3$s</button>',
                            esc_attr($shopier_id),
                            esc_attr($shopier_title),
                            esc_attr($shopier_label),
                            $selected_shopier_id === $shopier_id ? 'true' : 'false'
                        );
                    }
                    echo '<button type="button" class="wss-shopier-product-option" role="option" data-value="" data-search-title="" data-label="Shopier ürünü seçin" aria-selected="' . ($selected_shopier_id === '' ? 'true' : 'false') . '" style="display:block;width:100%;text-align:left;padding:6px;border:0;background:#fff;cursor:pointer;">Shopier ürünü seçin</button>';
                    echo '</div><p class="description wss-shopier-product-search-status" aria-live="polite" style="margin:6px 0 0;"></p></div></div><br>';
                } elseif ($mapping) {
                    $mapped_id = (string) $mapping->shopier_product_id;
                    $mapped_title = isset($shopier_by_id[$mapped_id])
                        ? (string) $shopier_by_id[$mapped_id]['title']
                        : 'Shopier ürünü';
                    echo '<strong>' . esc_html($mapped_title) . '</strong><br>';
                    echo '<small>ID: ' . esc_html($mapped_id) . '</small><br>';
                } else {
                    echo '<span>Önce Shopier ürün listesini yükleyin.</span><br>';
                }
                echo '</td>';
                echo '<td><div class="wss-row-actions">';
                $disabled = $job_writes_blocked ? ' disabled' : '';
                if ($mapping) {
                    echo '<button type="submit" class="wss-icon-btn" name="row_action" value="single_update|' . esc_attr($product_id) . '" data-wss-tip="Güncelle" aria-label="Güncelle"' . $disabled . '><span class="dashicons dashicons-update"></span></button>';
                    echo '<button type="submit" class="wss-icon-btn is-danger" name="row_action" value="single_unmap|' . esc_attr($product_id) . '" data-wss-tip="Eşleştirmeyi kaldır" aria-label="Eşleştirmeyi kaldır"' . $disabled . '><span class="dashicons dashicons-dismiss"></span></button>';
                    echo '<button type="button" class="wss-icon-btn is-danger wss-delete-trigger" data-product-id="' . esc_attr($product_id) . '" data-product-name="' . esc_attr($product->get_name()) . '" data-mapped="1" data-wss-tip="Ürünü sil" aria-label="Ürünü sil"' . $disabled . '><span class="dashicons dashicons-trash"></span></button>';
                } else {
                    if (!empty($shopier_catalog)) {
                        echo '<button type="submit" class="wss-icon-btn is-success wss-product-map-button" name="row_action" value="single_map|' . esc_attr($product_id) . '" data-wss-tip="Eşleştir" aria-label="Eşleştir" disabled><span class="dashicons dashicons-admin-links"></span></button>';
                    }
                    $create_tip = 'Yeni ürün oluştur ve eşleştir';
                    echo '<button type="submit" class="wss-icon-btn wss-single-create" name="row_action" value="single_create|' . esc_attr($product_id) . '" data-wss-tip="' . esc_attr($create_tip) . '" aria-label="' . esc_attr($create_tip) . '"' . $disabled . '><span class="dashicons dashicons-plus-alt2"></span></button>';
                    echo '<button type="button" class="wss-icon-btn is-danger wss-delete-trigger" data-product-id="' . esc_attr($product_id) . '" data-product-name="' . esc_attr($product->get_name()) . '" data-mapped="0" data-wss-tip="Ürünü sil" aria-label="Ürünü sil"' . $disabled . '><span class="dashicons dashicons-trash"></span></button>';
                }
                echo '</div></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '<p><button type="submit" class="button button-secondary" name="operation" value="bulk_test"' . ($job_writes_blocked ? ' disabled' : '') . '>Seçilenleri salt-okunur kontrol et</button> ';
            echo '<span class="description">Toplu kontrol salt okunurdur. Toplu güncelleme, seçilen her WooCommerce ürününü tek Shopier ürün eşlemesi üzerinden günceller.</span></p>';
            echo '</form>';
            echo '<div class="wss-modal" id="wss-delete-modal" hidden role="dialog" aria-modal="true" aria-labelledby="wss-delete-title">
                <div class="wss-modal-backdrop" data-wss-close></div>
                <div class="wss-modal-card">
                    <div class="wss-modal-icon"><span class="dashicons dashicons-trash"></span></div>
                    <h3 id="wss-delete-title">Ürünü sil</h3>
                    <p class="wss-modal-product"></p>
                    <p class="wss-modal-text">Bu ürünü nasıl silmek istiyorsunuz?</p>
                    <div class="wss-modal-options">
                        <button type="button" class="wss-modal-option" data-delete-mode="wc">
                            <span class="dashicons dashicons-cart"></span>
                            <span><strong>Sadece WooCommerce’ten sil</strong><small>Ürün çöp kutusuna taşınır, eşlemeler kaldırılır. Shopier ürünü kalır.</small></span>
                        </button>
                        <button type="button" class="wss-modal-option is-danger" data-delete-mode="both" id="wss-delete-both">
                            <span class="dashicons dashicons-warning"></span>
                            <span><strong>Her ikisinden sil</strong><small>Shopier ürünü kalıcı silinir (geri alınamaz), ardından WooCommerce ürünü çöpe taşınır.</small></span>
                        </button>
                    </div>
                    <button type="button" class="button wss-modal-cancel" data-wss-close>Vazgeç</button>
                </div>
            </div>';
            echo '<style>
                .wss-modal[hidden]{display:none}
                .wss-modal{position:fixed;inset:0;z-index:100001;display:flex;align-items:center;justify-content:center}
                .wss-modal-backdrop{position:absolute;inset:0;background:rgba(16,24,40,.55);backdrop-filter:blur(2px)}
                .wss-modal-card{position:relative;width:min(460px,92vw);padding:28px;background:#fff;border-radius:14px;box-shadow:0 20px 50px rgba(0,0,0,.28);text-align:center;animation:wssPop .18s ease-out}
                @keyframes wssPop{from{opacity:0;transform:translateY(8px) scale(.97)}to{opacity:1;transform:none}}
                .wss-modal-icon{display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:50%;background:#fcf0f1;color:#b32d2e;margin-bottom:8px}
                .wss-modal-icon .dashicons{font-size:26px;width:26px;height:26px}
                .wss-modal-card h3{margin:0 0 4px;font-size:18px}
                .wss-modal-product{margin:0;font-weight:600;color:#1d2327;word-break:break-word}
                .wss-modal-text{color:#646970;margin:6px 0 16px}
                .wss-modal-options{display:grid;gap:10px;margin-bottom:14px}
                .wss-modal-option{display:flex;gap:12px;align-items:flex-start;text-align:left;padding:12px 14px;border:1px solid #dcdcde;border-radius:10px;background:#fff;cursor:pointer;transition:border-color .15s,background .15s,box-shadow .15s}
                .wss-modal-option .dashicons{flex:0 0 auto;margin-top:2px;color:#2271b1}
                .wss-modal-option small{display:block;color:#646970;margin-top:2px;line-height:1.4}
                .wss-modal-option:hover,.wss-modal-option:focus-visible{border-color:#2271b1;background:#f0f6fc;box-shadow:0 0 0 1px #2271b1}
                .wss-confirm-actions{display:flex;gap:10px;justify-content:center}
                .wss-confirm-actions .button{height:36px;min-width:100px;border-radius:8px}
                .wss-modal-icon.is-info{background:#f0f6fc;color:#2271b1}
                .wss-modal-option.is-danger .dashicons{color:#b32d2e}
                .wss-modal-option.is-danger:hover,.wss-modal-option.is-danger:focus-visible{border-color:#b32d2e;background:#fcf0f1;box-shadow:0 0 0 1px #b32d2e}
            </style>';
            echo '<script>
                (function () {
                    window.wssConfirm = function (opts) {
                        var root = document.createElement("div");
                        root.className = "wss-modal";
                        root.setAttribute("role", "alertdialog");
                        root.setAttribute("aria-modal", "true");
                        var card = document.createElement("div");
                        card.className = "wss-modal-card";
                        var backdrop = document.createElement("div");
                        backdrop.className = "wss-modal-backdrop";
                        var icon = document.createElement("div");
                        icon.className = "wss-modal-icon" + (opts.danger ? "" : " is-info");
                        icon.innerHTML = "<span class=\"dashicons dashicons-" + (opts.danger ? "warning" : "info-outline") + "\"></span>";
                        var title = document.createElement("h3");
                        title.textContent = opts.title || "Onay gerekli";
                        var text = document.createElement("p");
                        text.className = "wss-modal-text";
                        text.textContent = opts.message || "";
                        var actions = document.createElement("div");
                        actions.className = "wss-confirm-actions";
                        var cancel = document.createElement("button");
                        cancel.type = "button";
                        cancel.className = "button";
                        cancel.textContent = "Vazgeç";
                        var ok = document.createElement("button");
                        ok.type = "button";
                        ok.className = "button button-primary";
                        ok.textContent = opts.confirmLabel || "Devam et";
                        if (opts.danger) { ok.style.cssText = "background:#b32d2e;border-color:#b32d2e"; }
                        actions.appendChild(cancel);
                        actions.appendChild(ok);
                        [icon, title, text, actions].forEach(function (el) { card.appendChild(el); });
                        root.appendChild(backdrop);
                        root.appendChild(card);
                        function done(result) {
                            document.removeEventListener("keydown", onKey);
                            root.remove();
                            if (result && opts.onConfirm) { opts.onConfirm(); }
                        }
                        function onKey(e) { if (e.key === "Escape") { done(false); } }
                        document.addEventListener("keydown", onKey);
                        backdrop.addEventListener("click", function () { done(false); });
                        cancel.addEventListener("click", function () { done(false); });
                        ok.addEventListener("click", function () { done(true); });
                        document.body.appendChild(root);
                        ok.focus();
                    };
                })();
                (function () {
                    var modal = document.getElementById("wss-delete-modal");
                    if (!modal) { return; }
                    var form = modal.closest("form") || document.querySelector("form input[name=action][value=wss_products_action]").form;
                    var current = null;
                    function close() { modal.hidden = true; current = null; }
                    document.querySelectorAll(".wss-delete-trigger").forEach(function (btn) {
                        btn.addEventListener("click", function () {
                            current = btn.dataset.productId;
                            modal.querySelector(".wss-modal-product").textContent = btn.dataset.productName;
                            modal.querySelector("#wss-delete-both").hidden = btn.dataset.mapped !== "1";
                            modal.hidden = false;
                            modal.querySelector(".wss-modal-option").focus();
                        });
                    });
                    modal.querySelectorAll("[data-wss-close]").forEach(function (el) { el.addEventListener("click", close); });
                    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !modal.hidden) { close(); } });
                    modal.querySelectorAll("[data-delete-mode]").forEach(function (opt) {
                        opt.addEventListener("click", function () {
                            if (!current) { return; }
                            var mode = opt.dataset.deleteMode;
                            var product = current;
                            function submitDelete() {
                                [["row_action", "single_delete|" + product], ["delete_mode", mode]].forEach(function (pair) {
                                    var input = document.createElement("input");
                                    input.type = "hidden"; input.name = pair[0]; input.value = pair[1];
                                    form.appendChild(input);
                                });
                                form.submit();
                            }
                            if (mode === "both") {
                                wssConfirm({
                                    title: "Shopier ürünü kalıcı silinecek",
                                    message: "Bu işlem geri alınamaz. Emin misiniz?",
                                    confirmLabel: "Evet, sil",
                                    danger: true,
                                    onConfirm: submitDelete
                                });
                            } else {
                                submitDelete();
                            }
                        });
                    });
                })();
            </script>';
            echo '<style>
                .wss-product-map-button,
                .wss-product-map-button:hover,
                .wss-product-map-button:focus {
                    background: #00a32a;
                    border-color: #00a32a;
                    color: #fff;
                }
                .wss-product-map-button:hover,
                .wss-product-map-button:focus {
                    background: #008a20;
                    border-color: #008a20;
                }
            </style>';
            echo '<script>
                var productJobActive = ' . ($job_writes_blocked ? 'true' : 'false') . ';
                document.querySelectorAll(".wss-shopier-product-picker").forEach(function (picker) {
                    var trigger = picker.querySelector(".wss-shopier-product-trigger");
                    var menu = picker.querySelector(".wss-shopier-product-menu");
                    var searchInput = picker.querySelector(".wss-shopier-product-search");
                    var select = picker.querySelector(".wss-shopier-product-value");
                    var options = Array.from(picker.querySelectorAll(".wss-shopier-product-option"));
                    var status = picker.querySelector(".wss-shopier-product-search-status");
                    if (!trigger || !menu || !searchInput || !select || !status) {
                        return;
                    }

                    function closeMenu() {
                        menu.hidden = true;
                        trigger.setAttribute("aria-expanded", "false");
                    }

                    function filterOptions() {
                        var query = searchInput.value.trim().toLocaleLowerCase("tr-TR");
                        var matches = 0;
                        options.forEach(function (option) {
                            var title = (option.dataset.searchTitle || "").toLocaleLowerCase("tr-TR");
                            var isMatch = title.includes(query);
                            option.style.display = isMatch ? "block" : "none";
                            if (title && isMatch) {
                                matches++;
                            }
                        });
                        status.textContent = query
                            ? matches + " Shopier ürünü bulundu."
                            : options.length + " Shopier ürünü yüklü.";
                    }

                    trigger.addEventListener("click", function () {
                        menu.hidden = !menu.hidden;
                        trigger.setAttribute("aria-expanded", menu.hidden ? "false" : "true");
                        if (!menu.hidden) {
                            searchInput.focus();
                        }
                    });

                    searchInput.addEventListener("input", filterOptions);
                    options.forEach(function (option) {
                        option.addEventListener("click", function () {
                            select.value = option.dataset.value;
                            trigger.textContent = option.dataset.label;
                            options.forEach(function (item) {
                                item.setAttribute("aria-selected", item === option ? "true" : "false");
                            });
                            searchInput.value = "";
                            filterOptions();
                            closeMenu();
                            updateProductBulkActions();
                        });
                    });

                    searchInput.addEventListener("keydown", function (event) {
                        if (event.key === "Escape") {
                            closeMenu();
                            trigger.focus();
                        }
                    });

                    document.addEventListener("click", function (event) {
                        if (!picker.contains(event.target)) {
                            closeMenu();
                        }
                    });
                    filterOptions();
                });

                function updateProductBulkActions() {
                    var selected = Array.from(document.querySelectorAll("input[name=\"selected_products[]\"]:checked"));
                    var count = document.querySelector(".wss-selected-product-count");
                    if (count) {
                        count.textContent = String(selected.length);
                    }
                    var bulkCreate = document.querySelector(".wss-bulk-create");
                    if (bulkCreate) {
                        bulkCreate.disabled = productJobActive || selected.length === 0;
                    }
                    var bulkMap = document.querySelector(".wss-bulk-map");
                    if (bulkMap) {
                        bulkMap.disabled = productJobActive || selected.length === 0 || selected.some(function (checkbox) {
                            var picker = checkbox.closest("tr").querySelector(".wss-shopier-product-value");
                            return !picker || !picker.value;
                        });
                    }
                    document.querySelectorAll(".wss-product-map-button").forEach(function (button) {
                        var row = button.closest("tr");
                        var picker = row ? row.querySelector(".wss-shopier-product-value") : null;
                        button.disabled = productJobActive || !picker || !picker.value;
                    });
                    document.querySelectorAll(".wss-single-create").forEach(function (button) {
                        button.disabled = productJobActive;
                    });
                    ["bulk_update", "bulk_unmap"].forEach(function (operation) {
                        var button = document.querySelector("button[name=\"operation\"][value=\"" + operation + "\"]");
                        if (button) {
                            button.disabled = productJobActive || selected.length === 0;
                        }
                    });
                    document.querySelectorAll(".wss-select-all-products").forEach(function (master) {
                        master.checked = selected.length > 0 &&
                            selected.length === document.querySelectorAll("input[name=\"selected_products[]\"]").length;
                    });
                }

                document.querySelectorAll(".wss-select-all-products").forEach(function (master) {
                    master.addEventListener("change", function () {
                        document.querySelectorAll("input[name=\"selected_products[]\"]").forEach(function (checkbox) {
                            checkbox.checked = master.checked;
                        });
                        updateProductBulkActions();
                    });
                });

                document.querySelectorAll("input[name=\"selected_products[]\"]").forEach(function (checkbox) {
                    checkbox.addEventListener("change", updateProductBulkActions);
                });

                document.querySelectorAll(".wss-shopier-product-value").forEach(function (select) {
                    select.addEventListener("change", updateProductBulkActions);
                });

                var titles = {
                    single_update: "Ürün güncellensin mi?", bulk_update: "Ürünler güncellensin mi?",
                    single_map: "Ürün eşleştirilsin mi?", bulk_map: "Ürünler eşleştirilsin mi?",
                    single_create: "Yeni ürün oluşturulsun mu?", bulk_create: "Yeni ürünler oluşturulsun mu?",
                    single_unmap: "Eşleştirme kaldırılsın mı?", bulk_unmap: "Eşleştirmeler kaldırılsın mı?"
                };
                document.querySelectorAll("button[name=\"operation\"], button[name=\"row_action\"]").forEach(function (button) {
                    button.addEventListener("click", function (event) {
                        var action = button.name === "row_action"
                            ? button.value.split("|")[0]
                            : button.value;
                        var message = "";
                        if (action === "single_update" || action === "bulk_update") {
                            message = "Seçilen WooCommerce ürünleri Shopier’deki eşleşmiş ürün ID’leri üzerinden güncellenecek. Devam edilsin mi?";
                        } else if (action === "single_map" || action === "bulk_map") {
                            message = "Shopier ID eşleştirmesi yalnızca yerel olarak kaydedilecek. Shopier’de değişiklik yapılmayacak. Devam edilsin mi?";
                        } else if (action === "single_create" || action === "bulk_create") {
                            message = "Shopier’de yeni ürün(ler) oluşturulup WooCommerce ürünleriyle eşleştirilecek. Değişken ürünler tek varyasyonlu Shopier ürünü olarak oluşturulur. Devam edilsin mi?";
                        } else if (action === "single_unmap" || action === "bulk_unmap") {
                            message = "Seçilen Shopier eşlemeleri ve bağlı varyasyon eşlemeleri kaldırılacak. Ürünler silinmeyecek. Devam edilsin mi?";
                        }
                        if (message) {
                            event.preventDefault();
                            wssConfirm({
                                title: titles[action] || "Onay gerekli",
                                message: message,
                                danger: action === "single_unmap" || action === "bulk_unmap",
                                onConfirm: function () {
                                    var input = document.createElement("input");
                                    input.type = "hidden"; input.name = button.name; input.value = button.value;
                                    button.form.appendChild(input);
                                    button.form.submit();
                                }
                            });
                        }
                    });
                });
                updateProductBulkActions();
            </script>';
        }

        if ($result->max_num_pages > 1) {
            echo '<div style="margin-top:15px;">';
            echo wp_kses_post(paginate_links([
                'base' => add_query_arg([
                    'product_status' => $status,
                    'wss_search' => $search,
                    'wss_product_type' => $product_type,
                    'wss_stock_status' => $stock_status,
                    'wss_product_page' => '%#%',
                ], $base),
                'format' => '',
                'current' => $page,
                'total' => $result->max_num_pages,
            ]));
            echo '</div>';
        }
        $last_stock_refresh = get_option('wss_last_manual_stock_refresh', []);
        if (is_array($last_stock_refresh) && !empty($last_stock_refresh['requested_at'])) {
            echo '<p class="description">Son manuel stok güncelleme isteği: ' . esc_html($last_stock_refresh['requested_at']) . ' (' . esc_html(absint($last_stock_refresh['queued_count'] ?? 0)) . '/' . esc_html(absint($last_stock_refresh['mapped_count'] ?? 0)) . ' ürün sıraya alındı).</p>';
        }
        self::render_recent_stock();
    }

    private static function sort_variation_ids_by_title($variation_ids)
    {
        $titles = [];
        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            $titles[absint($variation_id)] = $variation
                ? (string) (wc_get_formatted_variation($variation, true, false, true) ?: $variation->get_name())
                : '';
        }
        usort($variation_ids, static function ($left, $right) use ($titles) {
            return strnatcasecmp($titles[absint($left)] ?? '', $titles[absint($right)] ?? '');
        });
        return $variation_ids;
    }

    private static function shopier_catalog_groups_for_variation($catalog, $parent, $variation)
    {
        $groups = [
            'variation_match' => [],
            'same_product' => [],
            'other_products' => [],
        ];
        $normalize = static function ($value) {
            $value = remove_accents((string) $value);
            return strtolower(trim(preg_replace('/\s+/', ' ', $value)));
        };
        $parent_name = $normalize($parent->get_name());
        $attribute_values = [];
        foreach ($variation->get_attributes() as $name => $value) {
            if (!is_scalar($value) || (string) $value === '') {
                continue;
            }
            $term = taxonomy_exists($name) ? get_term_by('slug', (string) $value, $name) : false;
            $attribute_values[] = $normalize($term && !is_wp_error($term) ? $term->name : $value);
        }

        foreach ($catalog as $shopier_product) {
            $title = $normalize($shopier_product['title'] ?? '');
            $same_product = $parent_name !== '' && strpos($title, $parent_name) !== false;
            $variation_match = false;
            foreach ($attribute_values as $attribute_value) {
                if (strlen($attribute_value) >= 2 && strpos($title, $attribute_value) !== false) {
                    $variation_match = true;
                    break;
                }
            }
            if ($variation_match) {
                $groups['variation_match'][] = $shopier_product;
            } elseif ($same_product) {
                $groups['same_product'][] = $shopier_product;
            } else {
                $groups['other_products'][] = $shopier_product;
            }
        }
        return $groups;
    }

    private static function get_product_mapping($product_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wss_product_map';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT shopier_product_id, sync_status FROM `{$table}` WHERE wc_product_id = %d LIMIT 1",
            absint($product_id)
        ));
    }

    private static function render_settings()
    {
        echo '<h2>Ayarlar</h2>';

        if (isset($_GET['wss_notice'])) {
            $notice = sanitize_key(wp_unslash($_GET['wss_notice']));

            if ($notice === 'shopier_settings_saved') {
                echo '<div class="notice notice-success is-dismissible"><p>Shopier ayarları kaydedildi.</p></div>';
            } elseif ($notice === 'shopier_api_url_invalid') {
                echo '<div class="notice notice-error is-dismissible"><p>API adresi yalnızca HTTPS üzerinden api.shopier.com üzerinde ve /v1 yolunda olabilir.</p></div>';
            } elseif ($notice === 'shopier_connection_ok') {
                echo '<div class="notice notice-success is-dismissible"><p>Shopier API bağlantısı doğrulandı (salt-okunur test).</p></div>';
            } elseif ($notice === 'shopier_connection_failed') {
                $http_status = isset($_GET['wss_http_status'])
                    ? absint($_GET['wss_http_status'])
                    : 0;
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo esc_html(
                    $http_status
                        ? sprintf('Shopier API bağlantısı başarısız oldu (HTTP %d).', $http_status)
                        : 'Shopier API bağlantısı doğrulanamadı. PAT veya geçerli OAuth erişim tokeni gerekli.'
                );
                echo '</p></div>';
            }
        }

        $secret_options = [
            'wss_shopier_client_secret' => 'Client Secret / Secret Key',
            'wss_shopier_pat' => 'PAT / API token',
            'wss_shopier_webhook_product_updated_token' => 'Webhook token — product.updated',
        ];
        $constant_names = [
            'wss_shopier_client_secret' => ['SHOPIER_CLIENT_SECRET', 'SHOPIER_API_SECRET', 'SHOPIER_SECRET_KEY'],
            'wss_shopier_pat' => ['SHOPIER_API_KEY', 'SHOPIER_PAT', 'SHOPIER_BEARER_TOKEN'],
            'wss_shopier_webhook_product_updated_token' => ['SHOPIER_WEBHOOK_PRODUCT_UPDATED_TOKEN', 'SHOPIER_WEBHOOK_TOKEN'],
        ];
        echo '<div class="card" style="max-width:760px;padding:12px 20px;margin-bottom:20px;">';
        echo '<h3>Shopier kimlik bilgileri</h3>';
        echo '<p>Mevcut gizli değerler forma geri gönderilmez. Bir değeri değiştirmek için yeni değeri girin; değiştirmiyorsanız alanı boş bırakın.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wss_save_shopier_settings">';
        wp_nonce_field('wss_save_shopier_settings');
        $client_id = WSS_Shopier_API::get_client_id();
        echo '<p><label for="wss_shopier_client_id"><strong>Client ID</strong></label><br>';
        echo '<input class="regular-text" type="text" id="wss_shopier_client_id" name="wss_shopier_client_id" value="' . esc_attr($client_id) . '"></p>';
        foreach ($secret_options as $option => $label) {
            $configured = get_option($option, '') !== '';
            foreach ($constant_names[$option] as $constant) {
                $configured = $configured || (defined($constant) && constant($constant) !== '');
            }
            echo '<p><label for="' . esc_attr($option) . '"><strong>' . esc_html($label) . '</strong></label><br>';
            echo '<input class="regular-text" type="password" autocomplete="new-password" id="' . esc_attr($option) . '" name="' . esc_attr($option) . '" value="" placeholder="' . esc_attr($configured ? 'Yapılandırılmış; yalnızca değiştirmek için girin' : 'Henüz ayarlanmamış') . '"></p>';
        }
        $api_base_url = get_option('wss_shopier_api_base_url', 'https://api.shopier.com/v1');
        echo '<p><label for="wss_shopier_api_base_url"><strong>API Base URL</strong></label><br><input class="regular-text" type="url" id="wss_shopier_api_base_url" name="wss_shopier_api_base_url" value="' . esc_attr($api_base_url) . '"></p>';
        echo '<p class="description">PAT API çağrılarında kullanılabilir. Client ID ve Secret saklanır; OAuth yönlendirme ve yetkilendirme akışı henüz bu eklentide uygulanmamıştır. Webhook tokenları olay adına göre ayrı doğrulanır.</p>';
        echo '<p><strong>Stok webhook ayarı:</strong> Shopier’de <code>product.updated</code> olayı için aşağıdaki REST adresini tanımlayın ve o olay için verilen imza tokenını yukarıdaki alana kaydedin. Başka olaylar için Shopier ayrı token verirse her olaya özel alan eklenebilir.</p>';
        echo '<p><code>' . esc_html(rest_url('woo-shopier-sync/v1/webhook')) . '</code></p>';
        submit_button('Ayarları kaydet');
        echo '</form></div>';

        echo '<div class="card" style="max-width:640px;padding:12px 20px;">';
        echo '<h3>Shopier bağlantısı</h3>';
        echo '<p>Shopier API erişiminin çalışıp çalışmadığını doğrular. Bu test yalnızca okuma yapar; Shopier’de hiçbir şey değiştirmez.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wss_test_shopier_connection">';
        wp_nonce_field('wss_test_shopier_connection');
        submit_button('Shopier bağlantısını salt-okunur test et', 'secondary', 'submit', false);
        echo '</form></div>';
    }

    private static function render_help()
    {
        $products_url = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'products'], admin_url('admin.php'));
        $variations_url = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'variations'], admin_url('admin.php'));
        $settings_url = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'settings'], admin_url('admin.php'));

        echo '<h2>Kullanım Kılavuzu</h2>';
        echo '<p>WooCommerce menüsündeki Shopier Entegrasyonu sayfasında ürünlerinizi eşleyebilir, senkronize edebilir ve sipariş/stok işlemlerini takip edebilirsiniz.</p>';
        echo '<style>.wss-guide{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;max-width:1200px}.wss-guide section{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px}.wss-guide h3{margin:0 0 10px}.wss-guide ol,.wss-guide ul{padding-left:21px}.wss-guide li{margin:0 0 8px;line-height:1.55}.wss-guide-note{padding:12px 15px;border-left:4px solid #2271b1;background:#f0f6fc;max-width:1160px}</style>';
        echo '<div class="wss-guide">';
        echo '<section><h3>Bağlantıyı hazırlayın</h3><ol>';
        echo '<li><a href="' . esc_url($settings_url) . '">Ayarlar</a> sekmesinde Shopier PAT/API tokenını girip kaydedin.</li>';
        echo '<li><strong>Shopier bağlantısını salt-okunur test et</strong> düğmesiyle API erişimini kontrol edin.</li>';
        echo '<li><a href="' . esc_url($products_url) . '">Ürünler</a> sekmesinde <strong>Shopier ürün listesini yükle / yenile</strong> düğmesine basın.</li>';
        echo '</ol></section>';

        echo '<section><h3>Varyasyonları düzenleyin</h3><ol>';
        echo '<li>WooCommerce ürün düzenleme ekranında ürün türünü <strong>Değişken ürün</strong> seçin. Öznitelikler bölümünde özellik/değerleri ekleyip <strong>Varyasyonlar için kullan</strong> seçeneğini işaretleyin ve kaydedin.</li>';
        echo '<li><a href="' . esc_url($variations_url) . '">Varyasyonlar</a> sekmesinde Shopier global varyasyon ve seçimlerini yükleyin.</li>';
        echo '<li>Her WooCommerce global özelliğini bir Shopier grubuna; her özellik değerini doğru Shopier seçimine bağlayın. İsim önerilerini ve seçim ID’lerini kontrol edip eşlemeleri kaydedin.</li>';
        echo '<li>Ürünü eklemeden veya güncellemeden önce varyasyon önizlemesinde kombinasyonların eşleştiğini doğrulayın.</li>';
        echo '</ol><p>WooCommerce’e yeni bir özellik değeri veya varyasyon eklediğinizde eşlemesini de kaydedip ürünü yeniden senkronize edin.</p></section>';

        echo '<section><h3>Ürün ekleyin veya eşleyin</h3><ol>';
        echo '<li>Ürünler sekmesinde <strong>Eşleştirilmemiş Ürünler</strong> bölümünde WooCommerce ürününüzü bulun.</li>';
        echo '<li>Ürün Shopier’de zaten varsa seçim alanından Shopier ürününü seçip <strong>Eşleştir</strong> düğmesine basın.</li>';
        echo '<li>Yeni ürün oluşturmak için satırdaki ekleme düğmesini veya ürünleri seçip toplu ekleme düğmesini kullanın. Sonucu ürün satırındaki durumdan izleyin.</li>';
        echo '</ol><p>Değişken WooCommerce ürünü Shopier’de <strong>tek bir varyasyonlu ürün</strong> olarak oluşturulur; her varyasyon için ayrı Shopier ürünü açılmaz.</p></section>';

        echo '<section><h3>Kargo ücretini kimin ödeyeceği</h3><p>WooCommerce ürününde gönderim sınıfı varsa satıcı öder (<code>sellerPays</code>); gönderim sınıfı yoksa alıcı öder (<code>buyerPays</code>). Bu kural ürün oluşturma ve güncellemede uygulanır.</p></section>';

        echo '<section><h3>Ürünleri güncelleyin</h3><ol>';
        echo '<li>Ürünler sekmesinde <strong>Eşleştirilmiş Ürünler</strong> bölümünü açın.</li>';
        echo '<li>Tek ürün için satırdaki güncelleme düğmesini, birden fazla ürün için ürünleri seçip <strong>Toplu güncelle</strong> düğmesini kullanın.</li>';
        echo '<li>Değişken ürünlerde tek Shopier ürünü ve varyasyonları eşleme ID’leri üzerinden güncellenir. Karşılaştırma başarısızsa önce varyasyon eşlemelerini düzeltin.</li>';
        echo '</ol><p>İşlemler sıraya alınır; aktif ürün işi bitene kadar başka bir ürün yazma işlemi başlatılamaz.</p></section>';

        echo '<section><h3>Stok, sipariş ve kargo</h3><ul>';
        echo '<li><strong>Shopier stoklarını güncelle</strong>, eşleştirilmiş ürünlerin stok miktarlarını WooCommerce’e aktarır.</li>';
        echo '<li>Shopier’de <code>product.updated</code> webhook olayını kurarak stok güncellemelerini otomatik alabilirsiniz. Değişken ürünlerde her Shopier seçimi kendi WooCommerce varyasyonuyla eşleştirilir. Adres ve token Ayarlar sekmesindedir.</li>';
        echo '<li>Webhook sonucunu <strong>Senkronizasyon Detayları</strong> sekmesindeki API loglarında ve <strong>Son Stok Hareketleri</strong> tablosunda izleyin; değişken ürünler için tabloda güncellenen varyasyon da gösterilir.</li>';
        echo '<li><strong>Siparişler</strong> sekmesinden Shopier siparişlerini, <strong>Kargolar</strong> sekmesinden kargo kayıtlarını görüntüleyip yenileyebilirsiniz.</li>';
        echo '</ul></section>';

        echo '<section><h3>Kontrol ve sorun giderme</h3><ul>';
        echo '<li>Ürünleri seçip <strong>Seçilenleri salt-okunur kontrol et</strong> düğmesini kullanın. Bu kontrol Shopier’de ürün oluşturmaz veya güncellemez.</li>';
        echo '<li>Sonuçları ürün satırındaki durum/açıklamadan, işlem geçmişini <strong>Senkronizasyon Detayları</strong> sekmesinden inceleyin.</li>';
        echo '<li>Satın alma bağlantısını WooCommerce ürün düzenleme ekranındaki <strong>Shopier ürün bağlantısı</strong> alanına girin. Değişken üründeki tüm varyasyonlar ana ürünün aynı bağlantısını kullanır.</li>';
        echo '</ul></section></div>';

        echo '<div class="wss-guide" style="margin-top:14px">';
        echo '<section><h3>Eşlemeyi kaldırma</h3><p>Eşleştirilmiş ürün satırındaki <strong>Eşleştirmeyi kaldır</strong> düğmesi veya toplu eşleme kaldırma işlemi, WooCommerce ile Shopier arasındaki yerel bağlantıyı siler. WooCommerce ürünü ve Shopier ürünü silinmez. Ürünü daha sonra başka bir Shopier ürünüyle eşleyebilirsiniz.</p></section>';
        echo '<section><h3>Shopier ürününü silme</h3><p>Ürün satırındaki çöp kutusu düğmesine basıp <strong>Her ikisinden sil</strong> seçeneğini seçerseniz eşlenmiş Shopier ürünü kalıcı olarak silinir; WooCommerce ürünü de çöp kutusuna taşınır. Shopier’deki silme işlemi geri alınamaz.</p><p><strong>Sadece WooCommerce’ten sil</strong> seçeneği Shopier ürününü bırakır, WooCommerce ürününü çöp kutusuna taşır ve yerel eşlemeyi kaldırır.</p></section>';
        echo '</div>';
        echo '<p class="wss-guide-note"><strong>İpucu:</strong> Ürün ekleme veya toplu güncelleme öncesinde Shopier ürün bağlantısını ve varyasyon eşlemelerini kontrol edin.</p>';
    }
    private static function render_placeholder($title)
    {
        echo '<h2>' . esc_html($title) . '</h2>';
        echo '<p>Bu bölüm sonraki geliştirme aşamasında etkinleştirilecek.</p>';
    }
}

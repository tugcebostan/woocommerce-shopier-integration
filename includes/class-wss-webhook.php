<?php

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Webhook
{
    /**
     * Register webhook REST endpoint.
     */
    public static function init()
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('wss_process_shopier_stock_webhook', [self::class, 'process_scheduled_stock_sync'], 10, 1);
    }

    /**
     * Register REST route.
     */
    public static function register_routes()
    {
        register_rest_route('woo-shopier-sync/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Verify Shopier webhook signature.
     */
    private static function verify_signature($payload, $signature, $event)
    {
        $webhook_token = WSS_Shopier_API::get_webhook_token($event);
        if (empty($webhook_token) || empty($signature)) {
            return false;
        }

        $expected_signature = hash_hmac(
            'sha256',
            $payload,
            $webhook_token,
            false
        );

        return hash_equals($expected_signature, $signature);
    }
    
    /**
     * Return payload field names and data types only.
     * Never include actual customer or order values.
     */
    private static function describe_payload($data, $depth = 0)
    {
        if ($depth >= 4 || !is_array($data)) {
            return gettype($data);
        }
    
        $structure = [];
    
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $structure[$key] = self::describe_payload(
                    $value,
                    $depth + 1
                );
            } else {
                $structure[$key] = gettype($value);
            }
        }
    
        return $structure;
    }


    /**
     * Handle incoming webhook request.
     */
    public static function handle(WP_REST_Request $request)
    {
        $payload = $request->get_body();

        $signature  = $request->get_header('shopier-signature');
        $event      = $request->get_header('shopier-event');
        $account_id = $request->get_header('shopier-account-id');

        // 1. Verify signature.
        if (!self::verify_signature($payload, $signature, $event)) {

            WSS_Logger::log(
                'webhook',
                'Webhook signature verification failed.',
                'WARNING',
                [
                    'event'  => $event ?: 'unknown',
                    'status' => 'rejected',
                ]
            );

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        // 2. Decode JSON payload.
        $data = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {

            WSS_Logger::log(
                'webhook',
                'Webhook JSON payload is invalid.',
                'ERROR',
                [
                    'event'  => $event ?: 'unknown',
                    'status' => 'invalid_json',
                ]
            );

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid JSON payload.',
            ], 400);
        }
        
        // 3. Log successful verification.
        WSS_Logger::log(
            'webhook',
            'Webhook received and verified successfully.',
            'INFO',
            [
                'event'  => $event ?: 'unknown',
                'status' => 'verified',
            ]
        );

        if (sanitize_key((string) $event) === 'product.updated') {
            $shopier_product_id = self::extract_product_id($data);
            if ($shopier_product_id === '') {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Webhook payload did not include a Shopier product ID.',
                ], 400);
            }

            $hook = 'wss_process_shopier_stock_webhook';
            $args = [$shopier_product_id];
            if (!wp_next_scheduled($hook, $args) && !wp_schedule_single_event(time() + 1, $hook, $args)) {
                WSS_Logger::log('webhook', 'Shopier stok görevi kuyruğa alınamadı.', 'ERROR', [
                    'event' => 'shopier_stock_sync_schedule_failed',
                    'reference_id' => $shopier_product_id,
                    'status' => 'failed',
                ]);
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Product stock synchronization could not be scheduled.',
                ], 500);
            }

            WSS_Logger::log('webhook', 'Shopier stok güncellemesi kuyruğa alındı.', 'INFO', [
                'event' => 'shopier_stock_sync_queued',
                'reference_id' => $shopier_product_id,
                'status' => 'queued',
            ]);
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Product stock synchronization queued.',
                'shopier_product_id' => $shopier_product_id,
            ], 200);
        }

        return new WP_REST_Response([
            'success'    => true,
            'message'    => 'Webhook signature verified.',
            'event'      => $event,
            'account_id' => $account_id,
        ], 200);
    }

    public static function process_scheduled_stock_sync($shopier_product_id)
    {
        $shopier_product_id = trim((string) $shopier_product_id);
        if ($shopier_product_id === '' || strlen($shopier_product_id) > 100 || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $shopier_product_id)) {
            return;
        }

        $result = self::sync_shopier_product_stock($shopier_product_id);
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            WSS_Logger::log('webhook', 'Shopier ürün stoğu WooCommerce’e aktarılamadı.', 'ERROR', [
                'event' => 'shopier_stock_sync_failed',
                'reference_id' => is_array($error_data) ? (string) ($error_data['shopier_product_id'] ?? $shopier_product_id) : $shopier_product_id,
                'response_error' => $result->get_error_message(),
                'status' => 'failed',
            ]);
        }
    }

    private static function sync_shopier_product_stock($shopier_product_id)
    {
        if ($shopier_product_id === '') {
            return new WP_Error('wss_webhook_product_id_missing', 'Shopier product ID is required.');
        }

        $response = WSS_Shopier_API::get_product($shopier_product_id);
        if (empty($response['success'])) {
            return new WP_Error(
                'wss_webhook_product_fetch_failed',
                'Shopier product could not be fetched: ' . sanitize_text_field((string) ($response['message'] ?? 'API request failed.')),
                ['shopier_product_id' => $shopier_product_id]
            );
        }
        $remote_product = $response['body']['product'] ?? $response['body'];
        if (
            !is_array($remote_product) ||
            (string) ($remote_product['id'] ?? '') !== $shopier_product_id ||
            !isset($remote_product['stockQuantity']) ||
            !is_numeric($remote_product['stockQuantity'])
        ) {
            return new WP_Error(
                'wss_webhook_stock_quantity_invalid',
                'Shopier product response did not contain a valid stock quantity.',
                ['shopier_product_id' => $shopier_product_id]
            );
        }
        $stock_quantity = (int) $remote_product['stockQuantity'];
        if ($stock_quantity < 0) {
            return new WP_Error(
                'wss_webhook_stock_quantity_invalid',
                'Shopier returned a negative stock quantity.',
                ['shopier_product_id' => $shopier_product_id]
            );
        }

        $wc_post_id = self::find_wc_post_id($shopier_product_id);
        if (!$wc_post_id) {
            WSS_Logger::log('webhook', 'Shopier ürününün WooCommerce eşlemesi bulunamadı; stok aktarımı atlandı.', 'INFO', [
                'event' => 'shopier_stock_mapping_missing',
                'reference_id' => $shopier_product_id,
                'status' => 'ignored',
            ]);
            return [
                'message' => 'No WooCommerce mapping was found; stock update was ignored.',
                'shopier_product_id' => $shopier_product_id,
                'wc_product_id' => 0,
                'wc_variation_id' => 0,
                'stock_quantity' => $stock_quantity,
            ];
        }

        $wc_object = wc_get_product($wc_post_id);
        if (!$wc_object || !$wc_object->is_type(['simple', 'variable', 'variation'])) {
            return new WP_Error(
                'wss_webhook_wc_product_invalid',
                'The mapped WooCommerce product type is not supported for stock synchronization.',
                ['shopier_product_id' => $shopier_product_id]
            );
        }

        if ($wc_object->is_type('variable')) {
            return self::sync_variable_product_stock($shopier_product_id, $wc_object, $remote_product);
        }

        $wc_product_id = $wc_object->is_type('variation')
            ? absint($wc_object->get_parent_id())
            : absint($wc_object->get_id());
        $wc_variation_id = $wc_object->is_type('variation')
            ? absint($wc_object->get_id())
            : 0;
        $old_quantity = $wc_object->get_stock_quantity();
        $changed = !$wc_object->managing_stock() || (int) $old_quantity !== $stock_quantity;

        if ($changed) {
            $wc_object->set_manage_stock(true);
            $wc_object->set_stock_quantity($stock_quantity);
            $wc_object->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
            $saved_id = $wc_object->save();
            if (!$saved_id) {
                return new WP_Error(
                    'wss_webhook_wc_stock_save_failed',
                    'WooCommerce stock could not be saved.',
                    ['shopier_product_id' => $shopier_product_id]
                );
            }
            if ($wc_variation_id && class_exists('WC_Product_Variable')) {
                WC_Product_Variable::sync($wc_product_id);
            }
        }

        self::log_stock_change(
            $wc_product_id,
            $wc_variation_id,
            $shopier_product_id,
            $old_quantity,
            $stock_quantity,
            $changed ? 'success' : 'unchanged'
        );

        WSS_Logger::log('webhook', $changed ? 'Shopier stoğu WooCommerce’e aktarıldı.' : 'WooCommerce stoğu Shopier ile zaten aynı.', 'INFO', [
            'event' => 'shopier_stock_synced',
            'reference_id' => $shopier_product_id,
            'wc_product_id' => $wc_product_id,
            'wc_variation_id' => $wc_variation_id,
            'remote_value' => $stock_quantity,
            'status' => $changed ? 'success' : 'unchanged',
        ]);

        return [
            'message' => $changed ? 'Shopier stock was synchronized to WooCommerce.' : 'WooCommerce stock already matched Shopier.',
            'shopier_product_id' => $shopier_product_id,
            'wc_product_id' => $wc_product_id,
            'wc_variation_id' => $wc_variation_id,
            'stock_quantity' => $stock_quantity,
        ];
    }

    /** Synchronize each Shopier variant to its exact WooCommerce variation by selection IDs. */
    private static function sync_variable_product_stock($shopier_product_id, $wc_product, $remote_product)
    {
        if (empty($remote_product['variants']) || !is_array($remote_product['variants'])) {
            return new WP_Error(
                'wss_webhook_variants_missing',
                'Shopier variable product response did not contain variants.',
                ['shopier_product_id' => $shopier_product_id]
            );
        }

        $wc_variations = [];
        foreach (array_map('absint', $wc_product->get_children()) as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation')) {
                return new WP_Error(
                    'wss_webhook_wc_variation_invalid',
                    'A WooCommerce variation could not be loaded.',
                    ['shopier_product_id' => $shopier_product_id]
                );
            }
            $resolved = WSS_Variation_Map::resolve_global_selection_signature($variation_id);
            if (is_wp_error($resolved)) {
                return new WP_Error(
                    'wss_webhook_variation_mapping_missing',
                    'A WooCommerce variation has no valid Shopier selection mapping: ' . $resolved->get_error_message(),
                    ['shopier_product_id' => $shopier_product_id]
                );
            }
            $signature = (string) ($resolved['selection_signature'] ?? '');
            if ($signature === '' || isset($wc_variations[$signature])) {
                return new WP_Error(
                    'wss_webhook_variation_mapping_duplicate',
                    'WooCommerce variations have an empty or duplicate Shopier selection combination.',
                    ['shopier_product_id' => $shopier_product_id]
                );
            }
            $wc_variations[$signature] = [
                'variation' => $variation,
                'selection_key' => $signature,
            ];
        }

        if (!$wc_variations || count($wc_variations) !== count($remote_product['variants'])) {
            return new WP_Error(
                'wss_webhook_variation_count_mismatch',
                'WooCommerce and Shopier variation counts do not match.',
                ['shopier_product_id' => $shopier_product_id]
            );
        }

        $shopier_variations = [];
        foreach ($remote_product['variants'] as $remote_variant) {
            if (
                !is_array($remote_variant) ||
                empty($remote_variant['variationId']) ||
                !is_array($remote_variant['variationId']) ||
                empty($remote_variant['selectionId']) ||
                !is_array($remote_variant['selectionId'])
            ) {
                return new WP_Error(
                    'wss_webhook_shopier_variant_invalid',
                    'Shopier returned an incomplete variation selection.',
                    ['shopier_product_id' => $shopier_product_id]
                );
            }

            $group_ids = array_values($remote_variant['variationId']);
            $selection_ids = array_values($remote_variant['selectionId']);
            if (count($group_ids) !== count($selection_ids)) {
                return new WP_Error(
                    'wss_webhook_shopier_variant_invalid',
                    'Shopier returned mismatched variation and selection IDs.',
                    ['shopier_product_id' => $shopier_product_id]
                );
            }

            $pairs = [];
            $seen_groups = [];
            foreach ($group_ids as $index => $group_id) {
                $selection_id = $selection_ids[$index] ?? null;
                if (
                    !is_scalar($group_id) ||
                    !is_scalar($selection_id) ||
                    trim((string) $group_id) === '' ||
                    trim((string) $selection_id) === '' ||
                    isset($seen_groups[trim((string) $group_id)])
                ) {
                    return new WP_Error(
                        'wss_webhook_shopier_variant_invalid',
                        'Shopier returned invalid or duplicate variation selection IDs.',
                        ['shopier_product_id' => $shopier_product_id]
                    );
                }
                $group_id = trim((string) $group_id);
                $selection_id = trim((string) $selection_id);
                $seen_groups[$group_id] = true;
                $pairs[] = [$group_id, $selection_id];
            }
            usort($pairs, static function ($left, $right) {
                return [$left[0], $left[1]] <=> [$right[0], $right[1]];
            });
            $signature = hash('sha256', wp_json_encode($pairs));
            if (
                isset($shopier_variations[$signature]) ||
                !isset($wc_variations[$signature]) ||
                !isset($remote_variant['stockQuantity']) ||
                !is_numeric($remote_variant['stockQuantity']) ||
                (float) $remote_variant['stockQuantity'] < 0 ||
                floor((float) $remote_variant['stockQuantity']) !== (float) $remote_variant['stockQuantity']
            ) {
                return new WP_Error(
                    'wss_webhook_shopier_variant_invalid',
                    'Shopier variations do not match WooCommerce exactly or contain an invalid stock quantity.',
                    ['shopier_product_id' => $shopier_product_id]
                );
            }
            $shopier_variations[$signature] = (int) $remote_variant['stockQuantity'];
        }

        if (count($shopier_variations) !== count($wc_variations)) {
            return new WP_Error(
                'wss_webhook_variation_count_mismatch',
                'Shopier and WooCommerce variation combinations do not match.',
                ['shopier_product_id' => $shopier_product_id]
            );
        }

        $updates = [];
        foreach ($wc_variations as $signature => $entry) {
            $variation = $entry['variation'];
            $new_quantity = $shopier_variations[$signature];
            $old_quantity = $variation->get_stock_quantity();
            $changed = !$variation->managing_stock() || (int) $old_quantity !== $new_quantity;
            $updates[] = [
                'variation' => $variation,
                'signature' => $signature,
                'old_quantity' => $old_quantity,
                'new_quantity' => $new_quantity,
                'changed' => $changed,
            ];
        }

        foreach ($updates as $update) {
            if (!$update['changed']) {
                continue;
            }
            $variation = $update['variation'];
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($update['new_quantity']);
            $variation->set_stock_status($update['new_quantity'] > 0 ? 'instock' : 'outofstock');
            if (!$variation->save()) {
                return new WP_Error(
                    'wss_webhook_wc_stock_save_failed',
                    'WooCommerce variation stock could not be saved.',
                    ['shopier_product_id' => $shopier_product_id]
                );
            }
        }

        WC_Product_Variable::sync($wc_product->get_id());
        $changed_count = 0;
        foreach ($updates as $update) {
            $changed_count += $update['changed'] ? 1 : 0;
            self::log_stock_change(
                $wc_product->get_id(),
                $update['variation']->get_id(),
                $shopier_product_id,
                $update['old_quantity'],
                $update['new_quantity'],
                $update['changed'] ? 'success' : 'unchanged',
                $update['signature']
            );
        }

        WSS_Logger::log('webhook', $changed_count > 0 ? 'Shopier varyasyon stokları WooCommerce’e aktarıldı.' : 'WooCommerce varyasyon stokları Shopier ile zaten aynı.', 'INFO', [
            'event' => 'shopier_stock_synced',
            'reference_id' => $shopier_product_id,
            'wc_product_id' => $wc_product->get_id(),
            'wc_variation_id' => 0,
            'remote_value' => (int) $remote_product['stockQuantity'],
            'status' => $changed_count > 0 ? 'success' : 'unchanged',
        ]);

        return [
            'message' => $changed_count > 0 ? 'Shopier variation stocks were synchronized to WooCommerce.' : 'WooCommerce variation stocks already matched Shopier.',
            'shopier_product_id' => $shopier_product_id,
            'wc_product_id' => $wc_product->get_id(),
            'wc_variation_id' => 0,
            'updated_variation_count' => $changed_count,
            'stock_quantity' => (int) $remote_product['stockQuantity'],
        ];
    }

    private static function extract_product_id($data)
    {
        $candidates = [
            $data['productId'] ?? null,
            $data['product_id'] ?? null,
            $data['product']['id'] ?? null,
            $data['data']['productId'] ?? null,
            $data['data']['product_id'] ?? null,
            $data['data']['product']['id'] ?? null,
            $data['id'] ?? null,
            $data['data']['id'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate)) {
                $candidate = trim((string) $candidate);
                if ($candidate !== '' && strlen($candidate) <= 100 && preg_match('/\A[a-zA-Z0-9_-]+\z/', $candidate)) {
                    return $candidate;
                }
            }
        }
        return '';
    }

    private static function find_wc_post_id($shopier_product_id)
    {
        global $wpdb;

        $product_url = WSS_Product_Sync::get_shopier_product_url($shopier_product_id);
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM `{$wpdb->postmeta}`
            WHERE (meta_key = '_wss_shopier_product_id' AND meta_value = %s)
               OR (meta_key = '_wss_shopier_product_url' AND meta_value = %s)
            LIMIT 2",
            $shopier_product_id,
            $product_url
        ));
        if (count($post_ids) > 1) {
            return 0;
        }
        return !empty($post_ids) ? absint($post_ids[0]) : 0;
    }

    private static function log_stock_change($wc_product_id, $wc_variation_id, $shopier_product_id, $old_quantity, $new_quantity, $status, $selection_key = '')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wss_stock_sync_log';
        $wpdb->insert(
            $table,
            [
                'wc_product_id' => absint($wc_product_id),
                'wc_variation_id' => absint($wc_variation_id),
                'shopier_product_id' => sanitize_text_field((string) $shopier_product_id),
                'selection_key' => sanitize_text_field((string) $selection_key),
                'source' => 'shopier',
                'old_quantity' => $old_quantity === null ? null : (float) $old_quantity,
                'new_quantity' => (float) $new_quantity,
                'sync_status' => sanitize_key($status),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s']
        );
    }
}

// Initialize webhook handler.
WSS_Webhook::init();

<?php

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Product_Sync
{
    public static function save_manual_mapping($wc_product_id, $shopier_product_id)
    {
        global $wpdb;

        $wc_product_id = absint($wc_product_id);
        $shopier_product_id = trim(sanitize_text_field((string) $shopier_product_id));

        if ($wc_product_id <= 0 || !wc_get_product($wc_product_id)) {
            return new WP_Error(
                'wss_product_mapping_invalid_wc_product',
                'WooCommerce product was not found.'
            );
        }

        if (
            $shopier_product_id === '' ||
            strlen($shopier_product_id) > 100 ||
            !preg_match('/\A[a-zA-Z0-9_-]+\z/', $shopier_product_id)
        ) {
            return new WP_Error(
                'wss_product_mapping_invalid_shopier_id',
                'Enter a valid Shopier product ID.'
            );
        }

        $table = $wpdb->prefix . 'wss_product_map';
        $now = current_time('mysql');

        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error(
                'wss_product_mapping_transaction_failed',
                'Could not start the product mapping transaction.'
            );
        }

        $conflict = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT wc_product_id
                FROM `{$table}`
                WHERE shopier_product_id = %s
                  AND wc_product_id != %d
                LIMIT 1
                FOR UPDATE",
                $shopier_product_id,
                $wc_product_id
            )
        );

        if ($conflict) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'wss_product_mapping_shopier_id_in_use',
                'This Shopier product is already mapped to a different WooCommerce product.'
            );
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, shopier_product_id
                FROM `{$table}`
                WHERE wc_product_id = %d
                LIMIT 1
                FOR UPDATE",
                $wc_product_id
            ),
            ARRAY_A
        );

        if ($existing) {
            if ((string) $existing['shopier_product_id'] !== $shopier_product_id) {
                $variation_table = $wpdb->prefix . 'wss_variation_map';
                $variation_count = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*)
                        FROM `{$variation_table}`
                        WHERE wc_product_id = %d",
                        $wc_product_id
                    )
                );

                if ($variation_count > 0) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error(
                        'wss_product_mapping_has_variations',
                        'This product has variation mappings. Remove or review them before changing the Shopier product ID.'
                    );
                }
            }

            $result = $wpdb->update(
                $table,
                [
                    'shopier_product_id' => $shopier_product_id,
                    'sync_status' => 'mapped',
                    'updated_at' => $now,
                ],
                ['id' => (int) $existing['id']],
                ['%s', '%s', '%s'],
                ['%d']
            );
        } else {
            $result = $wpdb->insert(
                $table,
                [
                    'wc_product_id' => $wc_product_id,
                    'shopier_product_id' => $shopier_product_id,
                    'sync_status' => 'mapped',
                    'last_synced_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s']
            );
        }

        if ($result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'wss_product_mapping_save_failed',
                'Could not save product mapping: ' . $wpdb->last_error
            );
        }

        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'wss_product_mapping_commit_failed',
                'Could not commit product mapping.'
            );
        }

        return true;
    }

    public static function get_existing_shopier_product_id($wc_product_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wss_product_map';

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT shopier_product_id FROM `{$table}` WHERE wc_product_id = %d LIMIT 1",
                absint($wc_product_id)
            )
        );
    }

    public static function create_product($wc_product_id, $shipping_payer)
    {
        global $wpdb;

        $wc_product_id = absint($wc_product_id);
        $product = wc_get_product($wc_product_id);
        if (!$product || self::get_existing_shopier_product_id($wc_product_id)) {
            return new WP_Error(
                'wss_product_create_mapping_exists',
                'A valid unmatched WooCommerce product is required to create a new Shopier product.'
            );
        }

        $payload = self::build_create_payload($product, $shipping_payer);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $placement_score = WSS_Shopier_API::get_next_placement_score();
        if (empty($placement_score['success'])) {
            $error_details = WSS_Shopier_API::get_error_summary(
                $placement_score['body'] ?? null,
                $placement_score['message'] ?? ''
            );
            return new WP_Error(
                'wss_shopier_placement_score_unavailable',
                'Yeni ürün oluşturulmadan önce Shopier sıralama puanı belirlenemedi.'
                    . ($error_details !== '' ? ' ' . $error_details : '')
            );
        }
        $payload['placementScore'] = absint($placement_score['placement_score'] ?? 0);
        if ($payload['placementScore'] < 2) {
            return new WP_Error(
                'wss_shopier_placement_score_invalid',
                'Shopier için geçerli yeni bir sıralama puanı belirlenemedi.'
            );
        }

        $response = WSS_Shopier_API::create_product($payload);
        if (empty($response['success'])) {
            $status = absint($response['status'] ?? 0);
            $error_details = WSS_Shopier_API::get_error_summary(
                $response['body'] ?? null,
                $response['message'] ?? ''
            );
            WSS_Logger::log(
                'shopier-api',
                'Shopier ürün oluşturma isteği başarısız oldu.',
                'ERROR',
                [
                    'event' => 'product_create_failed',
                    'status' => $status,
                    'reference_id' => $wc_product_id,
                    'endpoint' => 'POST /products',
                    'response_error' => $error_details,
                    'payload_structure' => [
                        'fields' => array_keys($payload),
                        'variation_count' => count($payload['variants'] ?? []),
                    ],
                    'request_body' => $payload,
                ]
            );
            $error_suffix = $error_details !== ''
                ? ' Shopier yanıtı: ' . $error_details
                : '';

            return new WP_Error(
                'wss_shopier_product_create_failed',
                $status >= 400 && $status < 500
                    ? sprintf('Shopier ürünü oluşturulamadı (HTTP %d).', $status) . $error_suffix
                    : ($status >= 500
                        ? sprintf('Shopier oluşturma yanıtı belirsiz (HTTP %d); ürün oluşmuş olabilir. Tekrar denemeden önce kataloğu kontrol edin.', $status) . $error_suffix
                        : 'Shopier yanıtı alınamadı; yeni ürün oluşmuş olabilir. Tekrar denemeden önce Shopier kataloğunu kontrol edin.' . $error_suffix)
            );
        }

        $shopier_product_id = self::extract_shopier_product_id($response);
        if (
            $shopier_product_id === '' ||
            strlen($shopier_product_id) > 100 ||
            !preg_match('/\A[a-zA-Z0-9_-]+\z/', $shopier_product_id)
        ) {
            return new WP_Error(
                'wss_shopier_product_create_unconfirmed',
                'Shopier ürünü oluşturdu ancak yanıt geçerli bir ürün ID’si içermiyor. Shopier kataloğunu kontrol edin.'
            );
        }

        $verified = [];
        $verified_body = null;
        $verification_failure = '';
        $verification_attempts = 0;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) {
                usleep(500000);
            }

            $verification_attempts = $attempt;
            $verified = WSS_Shopier_API::get_product($shopier_product_id);
            $verified_body = isset($verified['body']['product']) &&
                is_array($verified['body']['product'])
                ? $verified['body']['product']
                : ($verified['body'] ?? null);
            $verified_placement_score = is_array($verified_body)
                ? self::validate_remote_integer(
                    $verified_body['placementScore'] ?? null,
                    'placementScore',
                    2
                )
                : new WP_Error(
                    'wss_shopier_preserved_field_invalid',
                    'Shopier did not return a valid placement score.'
                );

            if (
                !empty($verified['success']) &&
                is_array($verified_body) &&
                (string) ($verified_body['id'] ?? '') === $shopier_product_id &&
                (string) ($verified_body['title'] ?? '') === $payload['title'] &&
                !is_wp_error($verified_placement_score) &&
                $verified_placement_score === $payload['placementScore']
            ) {
                $verification_failure = '';
                break;
            }

            $verification_failure = self::describe_create_verification_failure(
                $verified,
                $verified_body,
                $shopier_product_id,
                $payload
            );
        }

        if ($verification_failure !== '') {
            WSS_Logger::log(
                'shopier-api',
                'Shopier ürünü oluşturuldu ancak tekrar sorgulamalara rağmen doğrulanamadı.',
                'ERROR',
                [
                    'event' => 'product_create_verification_failed',
                    'status' => absint($verified['status'] ?? 0),
                    'reference_id' => $wc_product_id,
                    'endpoint' => 'GET /products/' . $shopier_product_id,
                    'response_error' => $verification_failure
                        . ' Verification attempts: ' . $verification_attempts
                        . '; POST was not repeated.',
                    'remote_value' => is_array($verified_body)
                        ? self::format_log_value($verified_body['placementScore'] ?? null)
                        : '[no product body]',
                    'payload_structure' => [
                        'shopier_product_id' => $shopier_product_id,
                        'expected_placement_score' => $payload['placementScore'],
                        'verification_attempts' => $verification_attempts,
                    ],
                ]
            );

            return new WP_Error(
                'wss_shopier_product_create_verification_failed',
                'Shopier ürünü oluşturmuş olabilir ancak ürün doğrulanamadı. Shopier ID: ' . $shopier_product_id . '.',
                [
                    'shopier_product_id' => $shopier_product_id,
                    'verification_attempts' => $verification_attempts,
                    'verification_failure' => $verification_failure,
                ]
            );
        } elseif ($verification_attempts > 1) {
            WSS_Logger::log(
                'shopier-api',
                'Shopier ürün doğrulaması tekrar sorgulama ile başarılı oldu.',
                'INFO',
                [
                    'event' => 'product_create_verification_retry_succeeded',
                    'status' => absint($verified['status'] ?? 0),
                    'reference_id' => $wc_product_id,
                    'endpoint' => 'GET /products/' . $shopier_product_id,
                    'remote_value' => self::format_log_value(
                        $verified_body['placementScore'] ?? null
                    ),
                    'payload_structure' => [
                        'shopier_product_id' => $shopier_product_id,
                        'verification_attempts' => $verification_attempts,
                    ],
                ]
            );
        }

        $verified_variants = null;
        if ($product->is_type('variable')) {
            $verified_variants = self::parse_verified_shopier_variants(
                $verified_body,
                $shopier_product_id
            );
            if (is_wp_error($verified_variants)) {
                return new WP_Error(
                    'wss_shopier_product_create_variation_verification_failed',
                    'Shopier ürünü oluşturdu ancak varyasyonları doğrulanamadı. Shopier ID: '
                        . $shopier_product_id . '.',
                    ['shopier_product_id' => $shopier_product_id]
                );
            }
        }

        if (self::get_existing_shopier_product_id($wc_product_id)) {
            return new WP_Error(
                'wss_product_mapping_appeared_during_create',
                'Shopier ürünü oluşturuldu ancak WooCommerce ürününe bu sırada başka bir eşleme kaydedildi. Shopier ID: ' . $shopier_product_id . '.',
                ['shopier_product_id' => $shopier_product_id]
            );
        }

        $mapping = self::save_manual_mapping($wc_product_id, $shopier_product_id);
        if (is_wp_error($mapping)) {
            return new WP_Error(
                'wss_product_create_mapping_failed',
                'Shopier ürünü oluşturuldu ancak WooCommerce eşlemesi kaydedilemedi: '
                    . $mapping->get_error_message()
                    . ' Shopier ID: ' . $shopier_product_id . '.',
                ['shopier_product_id' => $shopier_product_id]
            );
        }

        if ($product->is_type('variable')) {
            $saved_variations = WSS_Variation_Map::save_product_from_global_mappings(
                $wc_product_id,
                array_keys($verified_variants),
                true
            );
            if (is_wp_error($saved_variations)) {
                return new WP_Error(
                    'wss_product_create_variation_mapping_failed',
                    'Shopier ürünü oluşturuldu ve ürün ID’si eşleştirildi (Shopier ID: '
                        . $shopier_product_id
                        . '), ancak varyasyon eşlemeleri kaydedilemedi: '
                        . $saved_variations->get_error_message(),
                    ['shopier_product_id' => $shopier_product_id]
                );
            }

            return [
                'success' => true,
                'shopier_product_id' => $shopier_product_id,
                'message' => 'Shopier’de yeni ürün oluşturuldu, ürün ve varyasyonları WooCommerce ile eşleştirildi. Shopier ID: '
                    . $shopier_product_id . '.',
            ];
        }

        $table = $wpdb->prefix . 'wss_product_map';
        $now = current_time('mysql');
        $updated = $wpdb->update(
            $table,
            [
                'sync_status' => 'active',
                'last_synced_at' => $now,
                'updated_at' => $now,
            ],
            ['wc_product_id' => $wc_product_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        if ($updated === false) {
            return new WP_Error(
                'wss_product_create_status_failed',
                'Shopier ürünü oluşturuldu ve eşleştirildi (Shopier ID: ' . $shopier_product_id
                    . '), ancak yerel aktarım durumu güncellenemedi.',
                ['shopier_product_id' => $shopier_product_id]
            );
        }

        return [
            'success' => true,
            'shopier_product_id' => $shopier_product_id,
            'message' => 'Shopier’de yeni ürün oluşturuldu ve WooCommerce ürünüyle eşleştirildi. Shopier ID: '
                . $shopier_product_id . '.',
        ];
    }

    private static function build_create_payload($product, $shipping_payer)
    {
        if (!in_array($shipping_payer, ['sellerPays', 'buyerPays'], true)) {
            return new WP_Error(
                'wss_product_create_shipping_payer_invalid',
                'Yeni ürün oluşturmadan önce kargo ücretini kimin ödeyeceğini seçin.'
            );
        }
        if (
            !$product->is_type(['simple', 'variable']) ||
            sanitize_text_field((string) $product->get_name()) === ''
        ) {
            return new WP_Error(
                'wss_product_create_type_unsupported',
                'Shopier’e yeni ürün olarak yalnızca adı olan basit veya değişken ürünler eklenebilir.'
            );
        }

        $media = self::get_product_media($product);
        if (empty($media)) {
            return new WP_Error(
                'wss_product_create_media_required',
                'Shopier yeni ürün için en az bir geçerli ürün görseli gerektiriyor.'
            );
        }

        $currency = 'TRY';

        $payload = [
            'title' => sanitize_text_field((string) $product->get_name()),
            'description' => wp_kses_post((string) $product->get_description()),
            'type' => $product->is_virtual() || $product->is_downloadable()
                ? 'digital'
                : 'physical',
            'media' => $media,
            'shippingPayer' => $shipping_payer,
        ];

        if ($product->is_type('simple')) {
            $price = self::apply_product_price([], $product);
            if (is_wp_error($price)) {
                return $price;
            }
            $price['currency'] = $currency;
            $payload['priceData'] = $price;

            if ($product->managing_stock()) {
                $quantity = self::get_integer_stock_quantity($product);
                if (is_wp_error($quantity)) {
                    return $quantity;
                }
                $payload['stockQuantity'] = $quantity;
            }

            return $payload;
        }

        $variation_ids = array_values(array_map('absint', $product->get_children()));
        if (empty($variation_ids)) {
            return new WP_Error(
                'wss_product_create_variations_missing',
                'Shopier’e eklemek için değişken üründe en az bir varyasyon bulunmalıdır.'
            );
        }

        $variants = [];
        $signatures = [];
        $prices = [];
        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation')) {
                return new WP_Error(
                    'wss_product_create_variation_invalid',
                    'WooCommerce varyasyonlarından biri okunamadı; Shopier’de ürün oluşturulmadı.'
                );
            }

            $resolved = WSS_Variation_Map::resolve_global_selection_signature($variation_id);
            if (is_wp_error($resolved)) {
                return new WP_Error(
                    'wss_product_create_variation_mapping_missing',
                    'Yeni Shopier ürünü oluşturmadan önce tüm varyasyon değerlerini mevcut Shopier seçim ID’leriyle eşleştirin. '
                        . $resolved->get_error_message()
                );
            }
            if (empty($resolved['selection_pairs'])) {
                return new WP_Error(
                    'wss_product_create_variation_mapping_missing',
                    'A WooCommerce variation has no Shopier selection IDs.'
                );
            }

            $signature = (string) $resolved['selection_signature'];
            if (isset($signatures[$signature])) {
                return new WP_Error(
                    'wss_product_create_variation_duplicate',
                    'Birden fazla WooCommerce varyasyonu aynı Shopier seçim kombinasyonuna karşılık geliyor.'
                );
            }
            $signatures[$signature] = true;

            $price = $variation->get_price();
            if ($price === '' || !is_numeric($price) || (float) $price < 0) {
                return new WP_Error(
                    'wss_product_variation_price_invalid',
                    'A WooCommerce variation has no valid price.'
                );
            }
            $variant_payload = [
                'selectionId' => array_values(array_map(
                    static function ($pair) {
                        return (string) $pair[1];
                    },
                    $resolved['selection_pairs']
                )),
                'priceData' => [
                    'currency' => $currency,
                    'price' => wc_format_decimal($price, wc_get_price_decimals()),
                ],
            ];
            if ($variation->managing_stock()) {
                $quantity = self::get_integer_stock_quantity($variation);
                if (is_wp_error($quantity)) {
                    return $quantity;
                }
                $variant_payload['stockQuantity'] = $quantity;
            }
            $variants[] = $variant_payload;
            $prices[] = (float) $price;
        }

        $payload['priceData'] = [
            'currency' => $currency,
            'price' => wc_format_decimal(min($prices), wc_get_price_decimals()),
        ];
        $payload['variants'] = $variants;

        return $payload;
    }

    public static function build_update_payload($product, $remote_body, $comparison = null)
    {
        if (
            !is_object($product) ||
            !is_array($remote_body) ||
            empty($remote_body['id']) ||
            !is_scalar($remote_body['id'])
        ) {
            return new WP_Error(
                'wss_shopier_product_data_invalid',
                'WooCommerce or Shopier product data is invalid.'
            );
        }

        $payload = [];
        if (isset($remote_body['media']) && $remote_body['media'] !== null) {
            if (!is_array($remote_body['media'])) {
                return new WP_Error(
                    'wss_shopier_media_invalid',
                    'Shopier returned invalid media data; no update was sent.'
                );
            }
            $filtered = self::filter_remote_list($remote_body['media'], ['type', 'url', 'placement'], 'media');
            if (is_wp_error($filtered)) {
                return $filtered;
            }
            $payload['media'] = $filtered;
        }

        $payload['title'] = sanitize_text_field((string) $product->get_name());
        $payload['description'] = wp_kses_post((string) $product->get_description());
        $payload['type'] = $product->is_virtual() || $product->is_downloadable()
            ? 'digital'
            : 'physical';

        $product_media = self::get_product_media($product);
        if (!empty($product_media)) {
            $payload['media'] = $product_media;
        }

        if ($product->is_type('simple')) {
            $price_data = isset($remote_body['priceData']) && is_array($remote_body['priceData'])
                ? $remote_body['priceData']
                : [];
            $price_result = self::apply_product_price($price_data, $product);
            if (is_wp_error($price_result)) {
                return $price_result;
            }
            // Always use TRY for Shopier
            $price_result['currency'] = 'TRY';
            
            // Only include fields that Shopier expects: currency, price, discount, discountedPrice
            // Remove any extra fields like vatPercent, shippingPrice that cause validation errors
            $price_result = array_intersect_key(
                $price_result,
                array_flip(['currency', 'price', 'discount', 'discountedPrice'])
            );
            
            $payload['priceData'] = $price_result;

            if ($product->managing_stock()) {
                $quantity = self::get_integer_stock_quantity($product);
                if (is_wp_error($quantity)) {
                    return $quantity;
                }
            } else {
                $quantity = 0;
            }
            $payload['stockQuantity'] = $quantity;

            return $payload;
        }

        if (!$product->is_type('variable') || !is_array($comparison) || empty($comparison['complete'])) {
            return new WP_Error(
                'wss_product_update_variations_unverified',
                'Variable product combinations must be verified before an update payload can be built.'
            );
        }

        // Always use TRY for Shopier - variations are stored in TRY regardless of site currency
        $currency = 'TRY';

        $variants = [];
        foreach ($comparison['rows'] as $row) {
            $variation = wc_get_product(absint($row['variation_id']));
            if (!$variation || !$variation->is_type('variation') || empty($row['shopier_variant'])) {
                return new WP_Error(
                    'wss_product_variation_invalid',
                    'A WooCommerce variation could not be matched to a Shopier combination.'
                );
            }

            $variant_payload = [
                'selectionId' => array_values($row['shopier_variant']['api_selection_ids']),
            ];
            if (empty($variant_payload['selectionId'])) {
                return new WP_Error(
                    'wss_product_variation_ids_missing',
                    'A matched Shopier variation has no selection IDs.'
                );
            }

            $price = $variation->get_price();
            if ($price === '' || !is_numeric($price) || (float) $price < 0) {
                return new WP_Error(
                    'wss_product_variation_price_invalid',
                    'A WooCommerce variation has no valid price.'
                );
            }
            $variant_payload['priceData'] = [
                'currency' => $currency,
                'price' => wc_format_decimal($price, wc_get_price_decimals()),
            ];

            if ($variation->managing_stock()) {
                $quantity = self::get_integer_stock_quantity($variation);
                if (is_wp_error($quantity)) {
                    return $quantity;
                }
            } else {
                $quantity = 0;
            }
            $variant_payload['stockQuantity'] = $quantity;

            if (array_key_exists('media', $row['shopier_variant']) && $row['shopier_variant']['media'] !== null) {
                if (!is_array($row['shopier_variant']['media'])) {
                    return new WP_Error(
                        'wss_shopier_preserved_field_invalid',
                        'Shopier returned invalid variation media; no update was sent.'
                    );
                }
                $variant_media = self::filter_remote_list(
                    $row['shopier_variant']['media'],
                    ['type', 'url'],
                    'variation media'
                );
                if (is_wp_error($variant_media)) {
                    return $variant_media;
                }
                $variant_payload['media'] = $variant_media;
            }
            if (array_key_exists('primary', $row['shopier_variant']) && $row['shopier_variant']['primary'] !== null) {
                if (!is_bool($row['shopier_variant']['primary'])) {
                    return new WP_Error(
                        'wss_shopier_preserved_field_invalid',
                        'Shopier returned invalid primary variation data; no update was sent.'
                    );
                }
                $variant_payload['primary'] = $row['shopier_variant']['primary'];
            }

            $variants[] = $variant_payload;
        }

        $payload['variants'] = $variants;
        return $payload;
    }

    public static function update_mapped_product($wc_product_id, $comparison = null)
    {
        global $wpdb;

        $wc_product_id = absint($wc_product_id);
        $product = wc_get_product($wc_product_id);
        $shopier_product_id = self::get_existing_shopier_product_id($wc_product_id);
        if (!$product || empty($shopier_product_id)) {
            return new WP_Error(
                'wss_product_mapping_required',
                'A WooCommerce product and its Shopier product ID mapping are required.'
            );
        }
        if ($product->is_type('variable')) {
            $atomic_supported = WSS_Variation_Map::ensure_atomic_save_supported();
            if (is_wp_error($atomic_supported)) {
                return $atomic_supported;
            }
        }

        $lock_name = 'wss_product_' . substr(hash('sha256', $wpdb->prefix . $wc_product_id), 0, 40);
        $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock_name));
        if ((string) $locked !== '1') {
            return new WP_Error(
                'wss_product_update_in_progress',
                'This product is already being updated by another request.'
            );
        }

        try {
            if ((string) self::get_existing_shopier_product_id($wc_product_id) !== (string) $shopier_product_id) {
                return new WP_Error(
                    'wss_shopier_product_mapping_changed',
                    'The saved Shopier product mapping changed before the update started.'
                );
            }

            $fresh_product = wc_get_product($wc_product_id);
            if (!$fresh_product || !$fresh_product->is_type(['simple', 'variable'])) {
                return new WP_Error(
                    'wss_product_update_product_unavailable',
                    'The WooCommerce product is no longer available for updating.'
                );
            }

            $fresh_response = WSS_Shopier_API::get_product($shopier_product_id);
            if (empty($fresh_response['success']) || !is_array($fresh_response['body'] ?? null)) {
                return new WP_Error(
                    'wss_shopier_product_update_preflight_failed',
                    'Shopier could not be re-read after acquiring the update lock; no update was sent.'
                );
            }
            $remote_body = isset($fresh_response['body']['product']) &&
                is_array($fresh_response['body']['product'])
                ? $fresh_response['body']['product']
                : $fresh_response['body'];
            if (
                !isset($remote_body['id']) ||
                !is_scalar($remote_body['id']) ||
                (string) $remote_body['id'] !== (string) $shopier_product_id
            ) {
                return new WP_Error(
                    'wss_shopier_product_mapping_mismatch',
                    'The Shopier product response does not match the saved product ID mapping.'
                );
            }

            if ($fresh_product->is_type('variable')) {
                if (empty($comparison['complete']) || empty($comparison['rows'])) {
                    return new WP_Error(
                        'wss_product_update_variations_unverified',
                        'Variable product combinations must be verified before an update.'
                    );
                }
                $fresh_variants = self::parse_verified_shopier_variants(
                    $remote_body,
                    (string) $shopier_product_id
                );
                if (is_wp_error($fresh_variants) || count($fresh_variants) !== count($comparison['rows'])) {
                    return new WP_Error(
                        'wss_shopier_variation_preflight_failed',
                        'Shopier variation combinations changed before the update; no update was sent.'
                    );
                }
                foreach ($comparison['rows'] as $index => $row) {
                    $signature = $row['resolved']['selection_signature'] ?? '';
                    if ($signature === '' || !isset($fresh_variants[$signature])) {
                        return new WP_Error(
                            'wss_shopier_variation_preflight_failed',
                            'Shopier variation combinations changed before the update; no update was sent.'
                        );
                    }
                    $variant = $fresh_variants[$signature];
                    $comparison['rows'][$index]['shopier_variant'] = [
                        'api_selection_ids' => array_values($variant['selectionId']),
                        'stockQuantity' => $variant['stockQuantity'] ?? null,
                        'media' => $variant['media'] ?? [],
                        'primary' => $variant['primary'] ?? null,
                    ];
                }
            }

            $payload = self::build_update_payload($fresh_product, $remote_body, $comparison);
            if (is_wp_error($payload)) {
                return $payload;
            }

            $response = WSS_Shopier_API::update_product($shopier_product_id, $payload);
            if (empty($response['success'])) {
                $status = absint($response['status'] ?? 0);
                
                // Log error response for debugging
                WSS_Logger::log(
                    'shopier-api',
                    'Product update failed',
                    'ERROR',
                    [
                        'event' => 'product_update_failed',
                        'reference_id' => (string) $shopier_product_id,
                        'http_status' => $status,
                        'response_body' => self::format_log_value($response['body'] ?? null),
                        'response_message' => (string) ($response['message'] ?? ''),
                    ]
                );
                
                return new WP_Error(
                    'wss_shopier_product_update_failed',
                    $status > 0
                        ? sprintf('Shopier ürünü güncellenemedi (HTTP %d). Otomatik tekrar denenmedi.', $status)
                        : 'Shopier yanıtı alınamadı; güncelleme gerçekleşmiş olabilir. Otomatik tekrar denenmedi. Tekrar aktarmadan önce Shopier ürününü kontrol edin.'
                );
            }

            $response_body = isset($response['body']['product']) &&
                is_array($response['body']['product'])
                ? $response['body']['product']
                : ($response['body'] ?? null);
            if (
                !is_array($response_body) ||
                !isset($response_body['id']) ||
                !is_scalar($response_body['id']) ||
                (string) $response_body['id'] !== (string) $shopier_product_id
            ) {
                return new WP_Error(
                    'wss_shopier_product_update_unconfirmed',
                    'Shopier accepted the update request but did not confirm the expected product ID. Read the product before retrying.'
                );
            }

            $verified = WSS_Shopier_API::get_product($shopier_product_id);
            if (empty($verified['success']) || !is_array($verified['body'] ?? null)) {
                return new WP_Error(
                    'wss_shopier_product_update_verification_failed',
                    'Shopier returned an update response, but the updated product could not be read back. Review the Shopier product before retrying.'
                );
            }
            $verified_body = isset($verified['body']['product']) &&
                is_array($verified['body']['product'])
                ? $verified['body']['product']
                : $verified['body'];
            if (
                !isset($verified_body['id']) ||
                (string) $verified_body['id'] !== (string) $shopier_product_id ||
                (string) ($verified_body['title'] ?? '') !== $payload['title'] ||
                (string) ($verified_body['description'] ?? '') !== $payload['description'] ||
                (string) ($verified_body['type'] ?? '') !== $payload['type'] ||
                !self::matches_update_payload($verified_body, $payload)
            ) {
                return new WP_Error(
                    'wss_shopier_product_update_verification_mismatch',
                    'Shopier product read-back did not match the requested product fields. Review the product before retrying.'
                );
            }

            if ($fresh_product->is_type('variable')) {
                $verified_catalog = self::parse_verified_shopier_variants(
                    $verified_body,
                    (string) $shopier_product_id
                );
                if (
                    is_wp_error($verified_catalog) ||
                    empty($comparison['complete']) ||
                    count($verified_catalog) !== count($comparison['rows'])
                ) {
                    return new WP_Error(
                        'wss_shopier_variation_update_verification_failed',
                        'Shopier variation combinations could not be verified after update.'
                    );
                }
                foreach ($comparison['rows'] as $index => $row) {
                    $signature = $row['resolved']['selection_signature'] ?? '';
                    if (!isset($verified_catalog[$signature])) {
                        return new WP_Error(
                            'wss_shopier_variation_update_verification_failed',
                            'Shopier variation combinations changed or no longer match after update.'
                        );
                    }
                    $expected_variant = $payload['variants'][$index] ?? [];
                    $matches = !empty($expected_variant) && self::matches_variant_payload(
                        $verified_catalog[$signature],
                        $expected_variant
                    );

                    // Debug log to understand mismatch
                    WSS_Logger::log(
                        'shopier-api',
                        'Variation verification result',
                        'INFO',
                        [
                            'event' => 'variation_verification_check',
                            'reference_id' => (string) $shopier_product_id,
                            'variant_index' => $index,
                            'selection_signature' => $signature,
                            'expected_variant_empty' => empty($expected_variant) ? 'true' : 'false',
                            'matches_result' => $matches ? 'true' : 'false',
                            'body_variant' => self::format_log_value($verified_catalog[$signature]),
                            'payload_variant' => self::format_log_value($expected_variant),
                        ]
                    );

                    if (!$matches) {
                        return new WP_Error(
                            'wss_shopier_variation_update_verification_failed',
                            'Shopier variation data did not match the requested prices or stock after update.'
                        );
                    }
                }
            }

            if ($fresh_product->is_type('variable')) {
                $saved = WSS_Variation_Map::save_product_from_global_mappings(
                    $wc_product_id,
                    array_keys($verified_catalog),
                    true
                );
                if (is_wp_error($saved)) {
                    return new WP_Error(
                        'wss_local_variation_mapping_failed_after_remote_update',
                        'Shopier was updated, but local variation mappings were not saved: '
                            . $saved->get_error_message()
                    );
                }
            } else {
                $marked = self::mark_product_synced($wc_product_id);
                if (is_wp_error($marked)) {
                    return new WP_Error(
                            'wss_product_sync_timestamp_failed_after_remote_update',
                            'Shopier was updated, but the local synchronization status could not be saved.'
                    );
                }
            }

            return true;
        } finally {
            $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            if ((string) $released !== '1') {
                error_log('WSS: Could not release the product update lock.');
            }
        }
    }

    private static function mark_product_synced($wc_product_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wss_product_map';
        $now = current_time('mysql');
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error(
                'wss_product_sync_transaction_failed',
                'Could not start the product synchronization status transaction.'
            );
        }

        $result = $wpdb->update(
            $table,
            [
                'sync_status' => 'active',
                'last_synced_at' => $now,
                'updated_at' => $now,
            ],
            ['wc_product_id' => absint($wc_product_id)],
            ['%s', '%s', '%s'],
            ['%d']
        );
        if ($result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'wss_product_sync_status_failed',
                'Could not save product synchronization status.'
            );
        }

        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'wss_product_sync_commit_failed',
                'Could not commit product synchronization status.'
            );
        }

        return true;
    }

    private static function apply_product_price($price_data, $product)
    {
        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        $regular_price = $regular_price !== '' ? $regular_price : $product->get_price();
        if ($regular_price === '' || !is_numeric($regular_price) || (float) $regular_price < 0) {
            return new WP_Error(
                'wss_product_price_invalid',
                'WooCommerce product has no valid price.'
            );
        }

        $price_data['price'] = wc_format_decimal($regular_price, wc_get_price_decimals());
        $on_sale = $product->is_on_sale() &&
            $sale_price !== '' &&
            is_numeric($sale_price) &&
            (float) $sale_price >= 0;
        $price_data['discount'] = $on_sale;
        if ($on_sale) {
            $price_data['discountedPrice'] = wc_format_decimal($sale_price, wc_get_price_decimals());
        } else {
            unset($price_data['discountedPrice']);
        }

        return $price_data;
    }

    private static function get_integer_stock_quantity($product)
    {
        $quantity = $product->get_stock_quantity();
        if ($quantity === null || !is_numeric($quantity) || (float) $quantity < 0 || floor((float) $quantity) !== (float) $quantity) {
            return new WP_Error(
                'wss_product_stock_quantity_invalid',
                'Shopier accepts whole-number stock quantities only; WooCommerce stock could not be safely converted.'
            );
        }

        return (int) $quantity;
    }

    private static function get_product_media($product)
    {
        $image_ids = array_slice(array_values(array_unique(array_filter(array_merge(
            [(int) $product->get_image_id()],
            array_map('absint', (array) $product->get_gallery_image_ids())
        )))), 0, 5);
        $media = [];
        foreach ($image_ids as $index => $image_id) {
            $url = wp_get_attachment_url($image_id);
            if (!$url || !wp_http_validate_url($url)) {
                continue;
            }
            $media[] = [
                'type' => 'image',
                'url' => esc_url_raw($url),
                'placement' => $index + 1,
            ];
        }

        return $media;
    }

    private static function filter_remote_list($items, $allowed_keys, $field)
    {
        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                return new WP_Error(
                    'wss_shopier_preserved_field_invalid',
                    'Shopier returned an invalid ' . $field . ' entry; no update was sent.'
                );
            }
            $filtered_item = array_intersect_key($item, array_flip($allowed_keys));
            if (empty($filtered_item)) {
                return new WP_Error(
                    'wss_shopier_preserved_field_invalid',
                    'Shopier returned an unsupported ' . $field . ' entry; no update was sent.'
                );
            }
            $required_keys = [
                'media' => ['type', 'url', 'placement'],
                'variation media' => ['type', 'url'],
                'categories' => ['categoryId'],
                'options' => ['optionId'],
            ][$field] ?? [];
            foreach ($required_keys as $required_key) {
                if (!isset($filtered_item[$required_key]) || !is_scalar($filtered_item[$required_key])) {
                    return new WP_Error(
                        'wss_shopier_preserved_field_invalid',
                        'Shopier returned incomplete ' . $field . ' data; no update was sent.'
                    );
                }
            }
            $filtered[] = $filtered_item;
        }

        return $filtered;
    }

    private static function validate_remote_integer($value, $field, $minimum, $maximum = null)
    {
        if (
            !is_scalar($value) ||
            !is_numeric($value) ||
            floor((float) $value) !== (float) $value ||
            (float) $value < $minimum ||
            ($maximum !== null && (float) $value > $maximum)
        ) {
            return new WP_Error(
                'wss_shopier_preserved_field_invalid',
                'Shopier returned an invalid ' . $field . ' value; no update was sent.'
            );
        }

        return (int) $value;
    }

    private static function format_log_value($value)
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return substr((string) $value, 0, 250);
        }

        $encoded_value = wp_json_encode($value);
        return $encoded_value === false
            ? '[unserializable]'
            : substr($encoded_value, 0, 250);
    }

    private static function describe_create_verification_failure(
        $response,
        $body,
        $expected_id,
        $payload
    ) {
        if (empty($response['success'])) {
            return WSS_Shopier_API::get_error_summary(
                $response['body'] ?? null,
                $response['message'] ?? 'Shopier product read-back failed.'
            );
        }
        if (!is_array($body)) {
            return 'Shopier product response did not contain a product object.';
        }
        if ((string) ($body['id'] ?? '') !== (string) $expected_id) {
            return 'Shopier returned a product ID that did not match the created product.';
        }
        if ((string) ($body['title'] ?? '') !== (string) $payload['title']) {
            return 'Shopier returned a title that did not match the create request.';
        }

        $score = self::validate_remote_integer(
            $body['placementScore'] ?? null,
            'placementScore',
            2
        );
        if (is_wp_error($score)) {
            return 'Shopier returned an invalid placementScore: '
                . self::format_log_value($body['placementScore'] ?? null) . '.';
        }
        if ($score !== (int) $payload['placementScore']) {
            return 'Shopier placementScore did not match the create request.';
        }

        return 'Shopier product read-back did not pass verification.';
    }

    private static function parse_verified_shopier_variants($body, $expected_product_id)
    {
        if (
            !is_array($body) ||
            (string) ($body['id'] ?? '') !== (string) $expected_product_id ||
            empty($body['variants']) ||
            !is_array($body['variants'])
        ) {
            return new WP_Error(
                'wss_shopier_variants_invalid',
                'Shopier returned invalid variation data.'
            );
        }

        $signatures = [];
        foreach ($body['variants'] as $variant) {
            if (
                !is_array($variant) ||
                empty($variant['variationId']) ||
                !is_array($variant['variationId']) ||
                empty($variant['selectionId']) ||
                !is_array($variant['selectionId']) ||
                count($variant['variationId']) !== count($variant['selectionId'])
            ) {
                return new WP_Error(
                    'wss_shopier_variants_invalid',
                    'Shopier returned incomplete variation ID combinations.'
                );
            }

            $pairs = [];
            $seen_groups = [];
            foreach ($variant['variationId'] as $index => $group_id) {
                $selection_id = $variant['selectionId'][$index] ?? null;
                if (
                    !is_scalar($group_id) ||
                    !is_scalar($selection_id) ||
                    trim((string) $group_id) === '' ||
                    trim((string) $selection_id) === '' ||
                    isset($seen_groups[trim((string) $group_id)])
                ) {
                    return new WP_Error(
                        'wss_shopier_variants_invalid',
                        'Shopier returned invalid variation IDs.'
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
            if (isset($signatures[$signature])) {
                return new WP_Error(
                    'wss_shopier_variants_invalid',
                    'Shopier returned duplicate variation combinations.'
                );
            }
            $signatures[$signature] = $variant;
        }

        return $signatures;
    }

    private static function matches_update_payload($body, $payload)
    {
        foreach (['title', 'description', 'type'] as $field) {
            if ((string) ($body[$field] ?? '') !== (string) ($payload[$field] ?? '')) {
                return false;
            }
        }

        if (isset($payload['priceData'])) {
            if (!self::matches_array_fields($body['priceData'] ?? null, $payload['priceData'])) {
                return false;
            }
        }

        if (
            array_key_exists('stockQuantity', $payload) &&
            (int) ($body['stockQuantity'] ?? -1) !== (int) $payload['stockQuantity']
        ) {
            return false;
        }

        if (isset($payload['media'])) {
            if (!isset($body['media']) || !is_array($body['media']) || count($body['media']) !== count($payload['media'])) {
                WSS_Logger::log(
                    'shopier-api',
                    'Media mismatch during verification',
                    'WARNING',
                    [
                        'event' => 'media_count_mismatch',
                        'body_media_exists' => isset($body['media']),
                        'body_media_is_array' => is_array($body['media'] ?? null),
                        'body_media_count' => is_array($body['media'] ?? null) ? count($body['media']) : 0,
                        'payload_media_count' => count($payload['media']),
                    ]
                );
                return false;
            }
            // Media verification: only check type and placement, not URL (Shopier changes URL to CDN)
            foreach ($payload['media'] as $index => $media) {
                $body_media = $body['media'][$index] ?? null;
                if (!is_array($body_media)) {
                    return false;
                }
                // Only check type and placement; URL will differ (CDN upload)
                if (
                    ($media['type'] ?? '') !== ($body_media['type'] ?? '') ||
                    ($media['placement'] ?? 0) !== ($body_media['placement'] ?? 0)
                ) {
                    WSS_Logger::log(
                        'shopier-api',
                        'Media item mismatch during verification',
                        'WARNING',
                        [
                            'event' => 'media_item_mismatch',
                            'media_index' => $index,
                            'body_media_type' => (string) ($body_media['type'] ?? ''),
                            'payload_media_type' => (string) ($media['type'] ?? ''),
                            'body_media_placement' => (int) ($body_media['placement'] ?? 0),
                            'payload_media_placement' => (int) ($media['placement'] ?? 0),
                        ]
                    );
                    return false;
                }
            }
        }

        return true;
    }

    private static function matches_variant_payload($actual, $expected)
    {
        if (!is_array($actual) || !is_array($expected)) {
            WSS_Logger::log(
                'shopier-api',
                'Variant payload type check failed',
                'DEBUG',
                [
                    'event' => 'variant_type_mismatch',
                    'actual_type' => gettype($actual),
                    'expected_type' => gettype($expected),
                ]
            );
            return false;
        }
        if (
            array_values((array) ($actual['selectionId'] ?? [])) !==
            array_values((array) ($expected['selectionId'] ?? []))
        ) {
            WSS_Logger::log(
                'shopier-api',
                'Variant selectionId mismatch',
                'DEBUG',
                [
                    'event' => 'variant_selectionId_mismatch',
                    'actual_selectionId' => self::format_log_value($actual['selectionId'] ?? null),
                    'expected_selectionId' => self::format_log_value($expected['selectionId'] ?? null),
                ]
            );
            return false;
        }
        if (
            isset($expected['priceData']) &&
            !self::matches_array_fields($actual['priceData'] ?? null, $expected['priceData'])
        ) {
            WSS_Logger::log(
                'shopier-api',
                'Variant priceData mismatch',
                'DEBUG',
                [
                    'event' => 'variant_priceData_mismatch',
                    'actual_priceData' => self::format_log_value($actual['priceData'] ?? null),
                    'expected_priceData' => self::format_log_value($expected['priceData'] ?? null),
                ]
            );
            return false;
        }
        if (
            array_key_exists('stockQuantity', $expected) &&
            (int) ($actual['stockQuantity'] ?? -1) !== (int) $expected['stockQuantity']
        ) {
            WSS_Logger::log(
                'shopier-api',
                'Variant stockQuantity mismatch',
                'DEBUG',
                [
                    'event' => 'variant_stockQuantity_mismatch',
                    'actual_stockQuantity' => (int) ($actual['stockQuantity'] ?? -1),
                    'expected_stockQuantity' => (int) $expected['stockQuantity'],
                ]
            );
            return false;
        }
        if (isset($expected['media'])) {
            if (!isset($actual['media']) || !is_array($actual['media']) || count($actual['media']) !== count($expected['media'])) {
                WSS_Logger::log(
                    'shopier-api',
                    'Variant media count or type mismatch',
                    'DEBUG',
                    [
                        'event' => 'variant_media_count_mismatch',
                        'actual_media_exists' => isset($actual['media']) ? 'true' : 'false',
                        'actual_media_is_array' => is_array($actual['media'] ?? null) ? 'true' : 'false',
                        'actual_media_count' => is_array($actual['media'] ?? null) ? count($actual['media']) : 'N/A',
                        'expected_media_count' => count($expected['media']),
                    ]
                );
                return false;
            }
            // Media verification: only check type and placement, not URL (Shopier changes URL to CDN)
            foreach ($expected['media'] as $index => $media) {
                $body_media = $actual['media'][$index] ?? null;
                if (!is_array($body_media)) {
                    WSS_Logger::log(
                        'shopier-api',
                        'Variant media item not array',
                        'DEBUG',
                        [
                            'event' => 'variant_media_item_not_array',
                            'media_index' => $index,
                            'actual_type' => gettype($body_media),
                        ]
                    );
                    return false;
                }
                if (
                    ($media['type'] ?? '') !== ($body_media['type'] ?? '') ||
                    ($media['placement'] ?? 0) !== ($body_media['placement'] ?? 0)
                ) {
                    WSS_Logger::log(
                        'shopier-api',
                        'Variant media type/placement mismatch',
                        'DEBUG',
                        [
                            'event' => 'variant_media_mismatch',
                            'media_index' => $index,
                            'body_media_type' => (string) ($body_media['type'] ?? ''),
                            'expected_media_type' => (string) ($media['type'] ?? ''),
                            'body_media_placement' => (int) ($body_media['placement'] ?? 0),
                            'expected_media_placement' => (int) ($media['placement'] ?? 0),
                        ]
                    );
                    return false;
                }
            }
        }
        if (
            array_key_exists('primary', $expected) &&
            (bool) ($actual['primary'] ?? false) !== (bool) $expected['primary']
        ) {
            WSS_Logger::log(
                'shopier-api',
                'Variant primary mismatch',
                'DEBUG',
                [
                    'event' => 'variant_primary_mismatch',
                    'actual_primary' => (bool) ($actual['primary'] ?? false) ? 'true' : 'false',
                    'expected_primary' => (bool) $expected['primary'] ? 'true' : 'false',
                ]
            );
            return false;
        }

        return true;
    }

    private static function matches_array_fields($actual, $expected)
    {
        if (!is_array($actual) || !is_array($expected)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) {
                return false;
            }
            if (is_array($value)) {
                if (!self::matches_array_fields($actual[$key], $value)) {
                    return false;
                }
            } elseif (is_array($actual[$key]) || is_object($actual[$key])) {
                return false;
            } elseif (is_bool($value)) {
                if ((bool) $actual[$key] !== $value) {
                    return false;
                }
            } elseif (
                is_numeric($value) &&
                is_numeric($actual[$key])
            ) {
                if (abs((float) $actual[$key] - (float) $value) > 0.000001) {
                    return false;
                }
            } elseif ($actual[$key] !== $value && (string) $actual[$key] !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    public static function sync_product($wc_product_id)
    {
        $wc_product_id = absint($wc_product_id);

        if ($wc_product_id <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid product id.',
            ];
        }

        $product = wc_get_product($wc_product_id);
        if (!$product) {
            return [
                'success' => false,
                'message' => 'Product not found.',
            ];
        }

        $response = WSS_Shopier_API::sync_product($wc_product_id);

        if (empty($response['success'])) {
            return [
                'success' => false,
                'message' => !empty($response['message']) ? $response['message'] : 'Shopier sync failed.',
                'response' => $response,
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wss_product_map';
        $shopier_product_id = self::extract_shopier_product_id($response);
        $now = current_time('mysql');

        if (empty($shopier_product_id)) {
            $shopier_product_id = self::get_existing_shopier_product_id($wc_product_id);
        }

        if (empty($shopier_product_id)) {
            return [
                'success' => false,
                'message' => 'Shopier product id could not be extracted from response.',
                'response' => $response,
            ];
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE wc_product_id = %d LIMIT 1",
                $wc_product_id
            )
        );

        if ($existing) {
            $wpdb->update(
                $table,
                [
                    'shopier_product_id' => $shopier_product_id,
                    'sync_status' => 'active',
                    'last_synced_at' => $now,
                    'updated_at' => $now,
                ],
                ['wc_product_id' => $wc_product_id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
        } else {
            $wpdb->insert(
                $table,
                [
                    'wc_product_id' => $wc_product_id,
                    'shopier_product_id' => $shopier_product_id,
                    'sync_status' => 'active',
                    'last_synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s']
            );
        }

        return [
            'success' => true,
            'shopier_product_id' => $shopier_product_id,
            'message' => 'Product synchronized successfully.',
            'response' => $response,
        ];
    }

    private static function extract_shopier_product_id($response)
    {
        if (empty($response['body'])) {
            return '';
        }

        $body = $response['body'];

        if (!is_array($body)) {
            return '';
        }

        if (!empty($body['id'])) {
            return (string) $body['id'];
        }

        if (!empty($body['product']['id'])) {
            return (string) $body['product']['id'];
        }

        if (!empty($body['data']['id'])) {
            return (string) $body['data']['id'];
        }

        return '';
    }
}

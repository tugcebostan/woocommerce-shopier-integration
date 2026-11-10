<?php

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Variation_Map
{
    public static function save_for_variation($wc_variation_id, $attribute_map_ids, $manage_transaction = true)
    {
        global $wpdb;

        if (!function_exists('wc_get_product')) {
            return new WP_Error(
                'wss_woocommerce_unavailable',
                'WooCommerce product APIs are not available.'
            );
        }

        $wc_variation_id = absint($wc_variation_id);
        $variation = wc_get_product($wc_variation_id);

        if (!$variation || !$variation->is_type('variation')) {
            return new WP_Error(
                'wss_variation_not_found',
                'WooCommerce variation was not found.'
            );
        }

        $wc_product_id = absint($variation->get_parent_id());
        $product = wc_get_product($wc_product_id);

        if (!$product || !$product->is_type('variable')) {
            return new WP_Error(
                'wss_variable_product_not_found',
                'The variation does not belong to a variable WooCommerce product.'
            );
        }

        $variation_attributes = WSS_WooCommerce_Attribute_Resolver::get_variation_attributes(
            $wc_variation_id
        );

        if (is_wp_error($variation_attributes)) {
            return $variation_attributes;
        }

        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $attribute_map_ids))));
        sort($ids, SORT_NUMERIC);

        if (empty($ids)) {
            return new WP_Error(
                'wss_mapping_selections_required',
                'Select the Shopier selection mappings for this variation.'
            );
        }

        $variation_table = $wpdb->prefix . 'wss_variation_map';
        $items_table = $wpdb->prefix . 'wss_variation_map_items';
        $product_table = $wpdb->prefix . 'wss_product_map';
        $now = current_time('mysql');

        if ($manage_transaction && $wpdb->query('START TRANSACTION') === false) {
            return new WP_Error(
                'wss_variation_transaction_failed',
                'Could not start the variation mapping transaction.'
            );
        }

        $product_mapping = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT shopier_product_id
                FROM `{$product_table}`
                WHERE wc_product_id = %d
                LIMIT 1
                FOR UPDATE",
                $wc_product_id
            ),
            ARRAY_A
        );

        if (!$product_mapping || trim((string) $product_mapping['shopier_product_id']) === '') {
            return self::rollback_with_error(
                'wss_shopier_product_not_mapped',
                'Map the WooCommerce parent product to a Shopier product before mapping its variations.',
                $manage_transaction
            );
        }

        $mappings = WSS_Attribute_Map::get_by_ids($ids, $wc_product_id);

        if (is_wp_error($mappings)) {
            if ($manage_transaction) {
                $wpdb->query('ROLLBACK');
            }
            return $mappings;
        }

        $seen_groups = [];
        $seen_attributes = [];
        $selection_pairs = [];

        foreach ($mappings as $mapping) {
            $group_id = (string) $mapping['shopier_group_id'];
            $attribute = WSS_Attribute_Map::normalize_attribute_name(
                $mapping['wc_attribute']
            );

            if (isset($seen_groups[$group_id])) {
                return self::rollback_with_error(
                    'wss_duplicate_shopier_group',
                    'A variation cannot use more than one selection from the same Shopier group.',
                    $manage_transaction
                );
            }

            if (isset($seen_attributes[$attribute])) {
                return self::rollback_with_error(
                    'wss_duplicate_wc_attribute',
                    'A WooCommerce variation attribute cannot be mapped to multiple Shopier groups.',
                    $manage_transaction
                );
            }

            if (!array_key_exists($attribute, $variation_attributes)) {
                return self::rollback_with_error(
                    'wss_mapping_attribute_mismatch',
                    'The selected Shopier mapping does not correspond to an attribute on this WooCommerce variation.',
                    $manage_transaction
                );
            }

            $expected_value = (string) $variation_attributes[$attribute];
            $mapped_value = trim(sanitize_text_field((string) $mapping['wc_value']));

            if (
                function_exists('taxonomy_exists') &&
                taxonomy_exists($attribute)
            ) {
                $mapped_value = sanitize_title($mapped_value);
            }

            if ($mapped_value !== $expected_value) {
                return self::rollback_with_error(
                    'wss_mapping_value_mismatch',
                    'The selected Shopier selection is mapped to a different WooCommerce attribute value.',
                    $manage_transaction
                );
            }

            $seen_groups[$group_id] = true;
            $seen_attributes[$attribute] = true;
            $selection_pairs[] = [
                $group_id,
                (string) $mapping['shopier_selection_id'],
            ];
        }

        if (count($seen_attributes) !== count($variation_attributes)) {
            return self::rollback_with_error(
                'wss_variation_mapping_incomplete',
                'Every concrete WooCommerce variation attribute must have exactly one Shopier selection mapping.',
                $manage_transaction
            );
        }

        usort($selection_pairs, static function ($left, $right) {
            return [$left[0], $left[1]] <=> [$right[0], $right[1]];
        });

        $selection_signature = hash('sha256', wp_json_encode($selection_pairs));
        $shopier_product_id = (string) $product_mapping['shopier_product_id'];

        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                FROM `{$variation_table}`
                WHERE wc_variation_id = %d
                LIMIT 1
                FOR UPDATE",
                $wc_variation_id
            )
        );

        if ($existing_id) {
            $result = $wpdb->update(
                $variation_table,
                [
                    'wc_product_id' => $wc_product_id,
                    'shopier_product_id' => $shopier_product_id,
                    'selection_signature' => $selection_signature,
                    'sync_status' => 'active',
                    'updated_at' => $now,
                ],
                ['id' => (int) $existing_id],
                ['%d', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            $variation_map_id = (int) $existing_id;
        } else {
            $result = $wpdb->insert(
                $variation_table,
                [
                    'wc_product_id' => $wc_product_id,
                    'wc_variation_id' => $wc_variation_id,
                    'shopier_product_id' => $shopier_product_id,
                    'selection_signature' => $selection_signature,
                    'sync_status' => 'active',
                    'last_synced_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            $variation_map_id = (int) $wpdb->insert_id;
        }

        if ($result === false) {
            return self::rollback_with_error(
                'wss_variation_combination_conflict',
                'Could not save this variation mapping. Its combination may already be assigned to another WooCommerce variation, or the database rejected the mapping.',
                $manage_transaction
            );
        }

        $deleted = $wpdb->delete(
            $items_table,
            ['variation_map_id' => $variation_map_id],
            ['%d']
        );

        if ($deleted === false) {
            return self::rollback_with_error(
                'wss_variation_items_delete_failed',
                'Could not replace the previous Shopier selection links.',
                $manage_transaction
            );
        }

        foreach ($mappings as $mapping) {
            $inserted = $wpdb->insert(
                $items_table,
                [
                    'variation_map_id' => $variation_map_id,
                    'attribute_map_id' => (int) $mapping['id'],
                    'created_at' => $now,
                ],
                ['%d', '%d', '%s']
            );

            if ($inserted === false) {
                return self::rollback_with_error(
                    'wss_variation_item_save_failed',
                    'Could not save a Shopier selection link: ' . $wpdb->last_error,
                    $manage_transaction
                );
            }
        }

        if ($manage_transaction) {
            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error(
                    'wss_variation_commit_failed',
                    'Could not commit the variation mapping.'
                );
            }
        }

        return $variation_map_id;
    }

    public static function get_for_variation($wc_variation_id)
    {
        global $wpdb;

        $variation_table = $wpdb->prefix . 'wss_variation_map';
        $items_table = $wpdb->prefix . 'wss_variation_map_items';
        $attribute_table = $wpdb->prefix . 'wss_attribute_map';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT vm.id AS variation_map_id,
                        vm.wc_product_id,
                        vm.wc_variation_id,
                        vm.shopier_product_id,
                        vm.selection_signature,
                        a.id AS attribute_map_id,
                        a.shopier_group_id,
                        a.shopier_selection_id,
                        a.wc_scope_id,
                        a.wc_attribute,
                        a.wc_value
                FROM `{$variation_table}` vm
                INNER JOIN `{$items_table}` i
                    ON i.variation_map_id = vm.id
                INNER JOIN `{$attribute_table}` a
                    ON a.id = i.attribute_map_id
                WHERE vm.wc_variation_id = %d
                ORDER BY a.shopier_group_id ASC",
                absint($wc_variation_id)
            ),
            ARRAY_A
        );
    }

    public static function has_current_mapping($wc_variation_id)
    {
        global $wpdb;

        $wc_variation_id = absint($wc_variation_id);
        $variation_table = $wpdb->prefix . 'wss_variation_map';
        $stored = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT wc_product_id, shopier_product_id, selection_signature, sync_status
                FROM `{$variation_table}`
                WHERE wc_variation_id = %d
                LIMIT 1",
                $wc_variation_id
            ),
            ARRAY_A
        );

        if (
            !is_array($stored) ||
            ($stored['sync_status'] ?? '') !== 'active'
        ) {
            return false;
        }

        $resolved = self::resolve_global_selection_signature($wc_variation_id);
        if (
            is_wp_error($resolved) ||
            !hash_equals(
                (string) $stored['selection_signature'],
                (string) $resolved['selection_signature']
            )
        ) {
            return false;
        }

        $product_mapping = WSS_Product_Sync::get_existing_shopier_product_id(
            absint($stored['wc_product_id'])
        );
        if ((string) $product_mapping !== (string) $stored['shopier_product_id']) {
            return false;
        }

        $items = self::get_for_variation($wc_variation_id);
        if (!is_array($items) || count($items) !== count($resolved['selection_pairs'])) {
            return false;
        }

        $stored_pairs = [];
        foreach ($items as $item) {
            $stored_pairs[] = [
                (string) $item['shopier_group_id'],
                (string) $item['shopier_selection_id'],
            ];
        }
        usort($stored_pairs, static function ($left, $right) {
            return [$left[0], $left[1]] <=> [$right[0], $right[1]];
        });

        return $stored_pairs === $resolved['selection_pairs'];
    }

    public static function resolve_global_selection_signature($wc_variation_id, $for_update = false)
    {
        $mappings = WSS_Attribute_Map::resolve_global_mappings_for_variation(
            $wc_variation_id,
            $for_update
        );
        if (is_wp_error($mappings)) {
            return $mappings;
        }

        $pairs = [];
        foreach ($mappings as $mapping) {
            $pairs[] = [
                (string) $mapping['shopier_group_id'],
                (string) $mapping['shopier_selection_id'],
            ];
        }
        usort($pairs, static function ($left, $right) {
            return [$left[0], $left[1]] <=> [$right[0], $right[1]];
        });

        return [
            'selection_pairs' => $pairs,
            'selection_signature' => hash('sha256', wp_json_encode($pairs)),
            'attribute_mappings' => $mappings,
        ];
    }

    public static function save_from_global_mappings($wc_variation_id)
    {
        $mappings = WSS_Attribute_Map::resolve_global_mappings_for_variation(
            $wc_variation_id
        );
        if (is_wp_error($mappings)) {
            return $mappings;
        }

        return self::save_for_variation(
            $wc_variation_id,
            array_column($mappings, 'id')
        );
    }

    public static function ensure_atomic_save_supported()
    {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'wss_variation_map',
            $wpdb->prefix . 'wss_variation_map_items',
            $wpdb->prefix . 'wss_product_map',
            $wpdb->prefix . 'wss_attribute_map',
        ];
        foreach ($tables as $table) {
            $table_status = $wpdb->get_row(
                $wpdb->prepare(
                    'SHOW TABLE STATUS WHERE Name = %s',
                    $table
                ),
                ARRAY_A
            );
            if (
                !is_array($table_status) ||
                strcasecmp((string) ($table_status['Engine'] ?? ''), 'InnoDB') !== 0
            ) {
                return new WP_Error(
                    'wss_variation_transaction_unsupported',
                    'All product and variation mapping tables must use InnoDB to guarantee all-or-nothing saving.'
                );
            }
        }

        return true;
    }

    public static function save_product_from_global_mappings($wc_product_id, $shopier_signatures, $mark_product_synced = false)
    {
        global $wpdb;

        $wc_product_id = absint($wc_product_id);
        $product = wc_get_product($wc_product_id);
        if (!$product || !$product->is_type('variable')) {
            return new WP_Error(
                'wss_variable_product_not_found',
                'The WooCommerce product is not a variable product.'
            );
        }

        $shopier_signatures = (array) $shopier_signatures;
        if (empty($shopier_signatures)) {
            return new WP_Error(
                'wss_shopier_signatures_required',
                'Shopier variation combinations are required.'
            );
        }
        foreach ($shopier_signatures as $signature) {
            if (
                !is_string($signature) ||
                !preg_match('/\A[a-f0-9]{64}\z/', $signature)
            ) {
                return new WP_Error(
                    'wss_shopier_signature_invalid',
                    'Shopier returned an invalid variation combination signature.'
                );
            }
        }
        $shopier_signatures = array_values(array_unique($shopier_signatures));
        $shopier_signature_lookup = array_fill_keys($shopier_signatures, true);

        $atomic_supported = self::ensure_atomic_save_supported();
        if (is_wp_error($atomic_supported)) {
            return $atomic_supported;
        }

        $variation_table = $wpdb->prefix . 'wss_variation_map';
        $items_table = $wpdb->prefix . 'wss_variation_map_items';
        $product_table = $wpdb->prefix . 'wss_product_map';
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error(
                'wss_variation_transaction_failed',
                'Could not start the product variation mapping transaction.'
            );
        }

        $product_mapping = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT shopier_product_id
                FROM `{$product_table}`
                WHERE wc_product_id = %d
                LIMIT 1
                FOR UPDATE",
                $wc_product_id
            ),
            ARRAY_A
        );
        if (!$product_mapping || trim((string) $product_mapping['shopier_product_id']) === '') {
            return self::rollback_with_error(
                'wss_shopier_product_not_mapped',
                'Map the WooCommerce parent product to a Shopier product before mapping its variations.'
            );
        }

        $variation_ids = array_map('absint', $product->get_children());
        if (empty($variation_ids) || count($variation_ids) !== count($shopier_signatures)) {
            return self::rollback_with_error(
                'wss_variation_count_mismatch',
                'WooCommerce and Shopier variation counts do not match.'
            );
        }

        $resolved_rows = [];
        $seen_signatures = [];
        foreach ($variation_ids as $variation_id) {
            $resolved = self::resolve_global_selection_signature($variation_id, true);
            if (is_wp_error($resolved)) {
                return self::rollback_with_error(
                    $resolved->get_error_code(),
                    $resolved->get_error_message()
                );
            }

            $signature = (string) $resolved['selection_signature'];
            if (
                isset($seen_signatures[$signature]) ||
                !isset($shopier_signature_lookup[$signature])
            ) {
                return self::rollback_with_error(
                    'wss_variation_signature_mismatch',
                    'A WooCommerce variation combination is duplicated or does not match a Shopier combination.'
                );
            }

            $seen_signatures[$signature] = true;
            $resolved_rows[] = [
                'variation_id' => $variation_id,
                'mapping_ids' => array_column($resolved['attribute_mappings'], 'id'),
            ];
        }

        if (count($seen_signatures) !== count($shopier_signature_lookup)) {
            return self::rollback_with_error(
                'wss_variation_signature_mismatch',
                'WooCommerce and Shopier variation combinations do not match exactly.'
            );
        }

        foreach ($resolved_rows as $row) {
            $saved = self::save_for_variation(
                $row['variation_id'],
                $row['mapping_ids'],
                false
            );
            if (is_wp_error($saved)) {
                $wpdb->query('ROLLBACK');
                return $saved;
            }
        }

        if ($mark_product_synced) {
            $now = current_time('mysql');
            $updated = $wpdb->update(
                $product_table,
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
                return self::rollback_with_error(
                    'wss_product_sync_timestamp_failed',
                    'Could not save the product synchronization status.'
                );
            }
        }

        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'wss_variation_commit_failed',
                'Could not commit all product variation mappings.'
            );
        }

        return count($resolved_rows);
    }

    private static function rollback_with_error($code, $message, $rollback = true)
    {
        global $wpdb;

        if ($rollback) {
            $wpdb->query('ROLLBACK');
        }
        return new WP_Error($code, $message);
    }
}

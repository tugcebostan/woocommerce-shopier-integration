<?php

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Attribute_Map
{
    public static function normalize_attribute_name($attribute)
    {
        $attribute = trim(sanitize_text_field((string) $attribute));

        if (strpos($attribute, 'attribute_') === 0) {
            $attribute = substr($attribute, strlen('attribute_'));
        }

        return sanitize_title(strtolower($attribute));
    }

    public static function save($mapping)
    {
        global $wpdb;

        $required = [
            'shopier_group_id',
            'shopier_group_title',
            'shopier_selection_id',
            'shopier_selection_title',
            'wc_scope_id',
            'wc_attribute',
            'wc_value',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $mapping)) {
                return new WP_Error(
                    'wss_mapping_field_required',
                    sprintf('Missing required mapping field: %s.', $field)
                );
            }
        }

        $group_id = trim(sanitize_text_field((string) $mapping['shopier_group_id']));
        $group_title = trim(sanitize_text_field((string) $mapping['shopier_group_title']));
        $selection_id = trim(sanitize_text_field((string) $mapping['shopier_selection_id']));
        $selection_title = trim(sanitize_text_field((string) $mapping['shopier_selection_title']));

        $scope_value = (string) $mapping['wc_scope_id'];

        if (!preg_match('/\A[0-9]+\z/', $scope_value)) {
            return new WP_Error(
                'wss_mapping_invalid_scope',
                'WooCommerce scope must be zero for a global attribute or a positive product ID for a custom attribute.'
            );
        }

        $scope_id = absint($mapping['wc_scope_id']);
        $attribute = self::normalize_attribute_name($mapping['wc_attribute']);
        $value = trim(sanitize_text_field((string) $mapping['wc_value']));

        if (
            $group_id === '' ||
            $selection_id === '' ||
            $group_title === '' ||
            $selection_title === '' ||
            $attribute === '' ||
            $value === ''
        ) {
            return new WP_Error(
                'wss_mapping_value_required',
                'Shopier IDs, titles, WooCommerce attribute, and value are required.'
            );
        }

        if (strlen($group_id) > 100 || strlen($selection_id) > 100) {
            return new WP_Error(
                'wss_mapping_id_too_long',
                'Shopier group and selection IDs must be 100 characters or fewer.'
            );
        }

        if (
            strlen($group_title) > 255 ||
            strlen($selection_title) > 255 ||
            strlen($attribute) > 100 ||
            strlen($value) > 200
        ) {
            return new WP_Error(
                'wss_mapping_value_too_long',
                'Mapping titles, WooCommerce attribute, or value exceed the database field limit.'
            );
        }

        $is_global_attribute = function_exists('taxonomy_exists')
            && taxonomy_exists($attribute);

        if ($scope_id === 0 && !$is_global_attribute) {
            return new WP_Error(
                'wss_mapping_global_attribute_not_found',
                'Global WooCommerce attributes must use their registered taxonomy name.'
            );
        }

        if ($scope_id > 0) {
            $product = function_exists('wc_get_product')
                ? wc_get_product($scope_id)
                : false;

            if (!$product || !$product->is_type('variable') || $is_global_attribute) {
                return new WP_Error(
                    'wss_mapping_invalid_scope',
                    'A custom attribute scope must be a variable product and must not use a global attribute taxonomy.'
                );
            }
        }

        if ($is_global_attribute) {
            $term = get_term_by('slug', sanitize_title($value), $attribute);

            if (!$term || is_wp_error($term)) {
                return new WP_Error(
                    'wss_mapping_term_not_found',
                    'The WooCommerce global attribute value does not exist.'
                );
            }

            $value = (string) $term->slug;
        }

        $table = $wpdb->prefix . 'wss_attribute_map';
        $now = current_time('mysql');

        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error(
                'wss_mapping_transaction_failed',
                'Could not start the attribute mapping transaction.'
            );
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, wc_scope_id, wc_attribute, wc_value
                FROM `{$table}`
                WHERE shopier_group_id = %s
                  AND shopier_selection_id = %s
                  AND wc_scope_id = %d
                LIMIT 1
                FOR UPDATE",
                $group_id,
                $selection_id,
                $scope_id
            ),
            ARRAY_A
        );

        $conflict_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                FROM `{$table}`
                WHERE wc_scope_id = %d
                  AND wc_attribute = %s
                  AND wc_value = %s
                  AND shopier_group_id = %s
                LIMIT 1
                FOR UPDATE",
                $scope_id,
                $attribute,
                $value,
                $group_id
            )
        );

        if ($conflict_id && (int) $conflict_id !== (int) ($existing['id'] ?? 0)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'wss_mapping_duplicate_wc_value',
                'This WooCommerce attribute value is already mapped to another selection in the same Shopier group.'
            );
        }

        if (
            $existing &&
            (
                (int) $existing['wc_scope_id'] !== $scope_id ||
                (string) $existing['wc_attribute'] !== $attribute ||
                (string) $existing['wc_value'] !== $value
            )
        ) {
            $items_table = $wpdb->prefix . 'wss_variation_map_items';
            $usage_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                    FROM `{$items_table}`
                    WHERE attribute_map_id = %d",
                    (int) $existing['id']
                )
            );

            if ($usage_count > 0) {
                $wpdb->query('ROLLBACK');
                return new WP_Error(
                    'wss_mapping_in_use',
                    'This selection is already used by a variation. Unmap or review affected variations before changing its WooCommerce value.'
                );
            }
        }

        $data = [
            'shopier_group_title' => $group_title,
            'shopier_selection_title' => $selection_title,
            'wc_attribute' => $attribute,
            'wc_value' => $value,
            'updated_at' => $now,
        ];

        if ($existing) {
            $result = $wpdb->update(
                $table,
                $data,
                ['id' => (int) $existing['id']],
                ['%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            $mapping_id = (int) $existing['id'];
        } else {
            $data = array_merge([
                'shopier_group_id' => $group_id,
                'shopier_selection_id' => $selection_id,
                'wc_scope_id' => $scope_id,
                'created_at' => $now,
            ], $data);
            $result = $wpdb->insert(
                $table,
                $data,
                ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            $mapping_id = (int) $wpdb->insert_id;
        }

        if ($result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'wss_mapping_save_failed',
                'Could not save attribute mapping: ' . $wpdb->last_error
            );
        }

        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'wss_mapping_commit_failed',
                'Could not commit attribute mapping.'
            );
        }

        return $mapping_id;
    }

    public static function replace_global_attribute_mappings(
        $taxonomy,
        $group,
        $term_selection_ids
    ) {
        global $wpdb;

        if (
            !is_scalar($taxonomy) ||
            !is_array($group) ||
            !is_array($term_selection_ids)
        ) {
            return new WP_Error(
                'wss_global_mapping_invalid',
                'The global attribute mapping payload is invalid.'
            );
        }

        $taxonomy = self::normalize_attribute_name($taxonomy);
        $group_id_value = $group['id'] ?? '';
        $group_title_value = $group['title'] ?? '';
        if (!is_scalar($group_id_value) || !is_scalar($group_title_value)) {
            return new WP_Error(
                'wss_global_mapping_invalid',
                'The global attribute or Shopier group is invalid.'
            );
        }
        $group_id = trim(sanitize_text_field((string) $group_id_value));
        $group_title = trim(sanitize_text_field((string) $group_title_value));
        $selections = isset($group['selections']) && is_array($group['selections'])
            ? $group['selections']
            : [];
        $terms = function_exists('taxonomy_exists')
            && taxonomy_exists($taxonomy)
            ? get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
            ])
            : [];
        $is_wc_global_attribute = false;
        if (function_exists('wc_get_attribute_taxonomies')) {
            foreach ((array) wc_get_attribute_taxonomies() as $attribute_taxonomy) {
                if (
                    wc_attribute_taxonomy_name($attribute_taxonomy->attribute_name) ===
                    $taxonomy
                ) {
                    $is_wc_global_attribute = true;
                    break;
                }
            }
        }

        if (
            $taxonomy === '' ||
            !$is_wc_global_attribute ||
            $group_id === '' ||
            $group_title === '' ||
            !is_array($terms) ||
            is_wp_error($terms) ||
            empty($terms)
        ) {
            return new WP_Error(
                'wss_global_mapping_invalid',
                'The global attribute, Shopier group, or WooCommerce terms are invalid.'
            );
        }

        $term_values = [];
        foreach ($terms as $term) {
            $term_values[(int) $term->term_id] = (string) $term->slug;
        }
        if (
            !is_array($term_selection_ids) ||
            count($term_selection_ids) !== count($term_values)
        ) {
            return new WP_Error(
                'wss_global_mapping_incomplete',
                'Every WooCommerce global attribute term must be mapped exactly once.'
            );
        }

        $rows = [];
        $used_selection_ids = [];
        foreach ($term_values as $term_id => $term_slug) {
            $raw_selection_id = $term_selection_ids[$term_id] ?? '';
            if (!is_scalar($raw_selection_id)) {
                return new WP_Error(
                    'wss_global_mapping_selection_invalid',
                    'Each WooCommerce term must use a distinct Shopier selection ID from the selected group.'
                );
            }
            $selection_id = trim(sanitize_text_field((string) $raw_selection_id));

            if (
                $selection_id === '' ||
                strlen($selection_id) > 100 ||
                !preg_match('/\A[a-zA-Z0-9_-]+\z/', $selection_id) ||
                !array_key_exists($selection_id, $selections) ||
                isset($used_selection_ids[$selection_id])
            ) {
                return new WP_Error(
                    'wss_global_mapping_selection_invalid',
                    'Each WooCommerce term must use a distinct Shopier selection ID from the selected group.'
                );
            }

            if (!is_scalar($selections[$selection_id])) {
                return new WP_Error(
                    'wss_global_mapping_selection_invalid',
                    'Shopier returned an invalid selection title.'
                );
            }
            $selection_title = trim(sanitize_text_field((string) $selections[$selection_id]));
            if ($selection_title === '' || strlen($selection_title) > 255 || strlen($term_slug) > 200) {
                return new WP_Error(
                    'wss_global_mapping_value_invalid',
                    'A WooCommerce term or Shopier selection title exceeds the supported field limits.'
                );
            }

            $rows[] = [
                'wc_value' => $term_slug,
                'selection_id' => $selection_id,
                'selection_title' => $selection_title,
            ];
            $used_selection_ids[$selection_id] = true;
        }

        if (
            strlen($group_id) > 100 ||
            !preg_match('/\A[a-zA-Z0-9_-]+\z/', $group_id) ||
            strlen($group_title) > 255
        ) {
            return new WP_Error(
                'wss_global_mapping_value_too_long',
                'The Shopier group ID or title exceeds the database field limit.'
            );
        }

        $table = $wpdb->prefix . 'wss_attribute_map';
        $items_table = $wpdb->prefix . 'wss_variation_map_items';

        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error(
                'wss_mapping_transaction_failed',
                'Could not start the global attribute mapping transaction.'
            );
        }

        $lock_name = 'wss_global_mapping_' . md5($wpdb->prefix);
        $lock_acquired = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock_name)
        );
        if ($lock_acquired !== 1) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'wss_global_mapping_lock_failed',
                'Could not acquire the global attribute mapping lock. Please retry.'
            );
        }

        $taxonomy_lock = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT term_taxonomy_id
                FROM `{$wpdb->term_taxonomy}`
                WHERE taxonomy = %s
                ORDER BY term_taxonomy_id ASC
                LIMIT 1
                FOR UPDATE",
                $taxonomy
            )
        );
        if (!$taxonomy_lock) {
            $wpdb->query('ROLLBACK');
            self::release_global_mapping_lock($lock_name);
            return new WP_Error(
                'wss_global_mapping_lock_failed',
                'Could not lock the WooCommerce attribute while saving its mappings.'
            );
        }

        $group_in_use = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                FROM `{$table}`
                WHERE wc_scope_id = 0
                  AND shopier_group_id = %s
                  AND wc_attribute <> %s
                LIMIT 1
                FOR UPDATE",
                $group_id,
                $taxonomy
            )
        );
        if ($group_in_use) {
            $wpdb->query('ROLLBACK');
            self::release_global_mapping_lock($lock_name);
            return new WP_Error(
                'wss_global_mapping_group_in_use',
                'This Shopier variation group is already assigned to another WooCommerce global attribute.'
            );
        }

        $existing_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id
                FROM `{$table}`
                WHERE wc_scope_id = 0 AND wc_attribute = %s
                FOR UPDATE",
                $taxonomy
            )
        );

        if (!empty($existing_ids)) {
            $id_list = implode(',', array_map('absint', $existing_ids));
            $usage_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$items_table}`
                WHERE attribute_map_id IN ({$id_list})"
            );

            if ($usage_count > 0) {
                $wpdb->query('ROLLBACK');
                self::release_global_mapping_lock($lock_name);
                return new WP_Error(
                    'wss_global_mapping_in_use',
                    'This global attribute is already used by saved variation mappings and cannot be replaced yet.'
                );
            }

            if (
                $wpdb->delete(
                    $table,
                    ['wc_scope_id' => 0, 'wc_attribute' => $taxonomy],
                    ['%d', '%s']
                ) === false
            ) {
                $wpdb->query('ROLLBACK');
                self::release_global_mapping_lock($lock_name);
                return new WP_Error(
                    'wss_global_mapping_delete_failed',
                    'Could not replace the previous global attribute mappings.'
                );
            }
        }

        foreach ($rows as $row) {
            $result = $wpdb->insert(
                $table,
                [
                    'shopier_group_id' => $group_id,
                    'shopier_group_title' => $group_title,
                    'shopier_selection_id' => $row['selection_id'],
                    'shopier_selection_title' => $row['selection_title'],
                    'wc_scope_id' => 0,
                    'wc_attribute' => $taxonomy,
                    'wc_value' => $row['wc_value'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                self::release_global_mapping_lock($lock_name);
                return new WP_Error(
                    'wss_global_mapping_save_failed',
                    'Could not save global attribute mappings. Check for duplicate Shopier selections or database conflicts.'
                );
            }
        }

        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            self::release_global_mapping_lock($lock_name);
            return new WP_Error(
                'wss_mapping_commit_failed',
                'Could not commit global attribute mappings.'
            );
        }

        self::release_global_mapping_lock($lock_name);
        return count($rows);
    }

    private static function release_global_mapping_lock($lock_name)
    {
        global $wpdb;

        $released = $wpdb->get_var(
            $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name)
        );
        if ((int) $released !== 1) {
            error_log('WSS: Could not release the global attribute mapping lock.');
        }
    }

    public static function get_by_ids($mapping_ids, $product_scope_id)
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $mapping_ids))));
        sort($ids, SORT_NUMERIC);

        if (empty($ids)) {
            return new WP_Error(
                'wss_mapping_selections_required',
                'At least one mapped Shopier selection is required.'
            );
        }

        $id_list = implode(',', $ids);
        $table = $wpdb->prefix . 'wss_attribute_map';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, shopier_group_id, shopier_selection_id,
                        wc_scope_id, wc_attribute, wc_value
                FROM `{$table}`
                WHERE id IN ({$id_list})
                  AND wc_scope_id IN (0, %d)
                ORDER BY id ASC
                FOR UPDATE",
                absint($product_scope_id)
            ),
            ARRAY_A
        );

        if (count($rows) !== count($ids)) {
            return new WP_Error(
                'wss_mapping_selection_not_found',
                'One or more selections are missing or outside this product scope.'
            );
        }

        return $rows;
    }

    public static function get_for_product($product_id, $for_update = false)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wss_attribute_map';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, shopier_group_id, shopier_group_title,
                        shopier_selection_id, shopier_selection_title,
                        wc_scope_id, wc_attribute, wc_value
                FROM `{$table}`
                WHERE wc_scope_id IN (0, %d)
                ORDER BY shopier_group_title ASC,
                         shopier_selection_title ASC,
                         wc_scope_id ASC" . ($for_update ? ' FOR UPDATE' : ''),
                absint($product_id)
            ),
            ARRAY_A
        );
    }

    public static function resolve_global_mappings_for_variation($variation_id, $for_update = false)
    {
        $attributes = WSS_WooCommerce_Attribute_Resolver::get_variation_attributes(
            $variation_id
        );
        if (is_wp_error($attributes)) {
            return $attributes;
        }

        $available = self::get_for_product(0, $for_update);
        $by_attribute_value = [];
        foreach ($available as $mapping) {
            if ((int) $mapping['wc_scope_id'] !== 0) {
                continue;
            }
            $key = self::normalize_attribute_name($mapping['wc_attribute']) . ':' .
                (string) $mapping['wc_value'];
            $by_attribute_value[$key][] = $mapping;
        }

        $resolved = [];
        $seen_groups = [];
        foreach ($attributes as $attribute => $value) {
            $key = self::normalize_attribute_name($attribute) . ':' . (string) $value;
            $matches = $by_attribute_value[$key] ?? [];
            if (count($matches) !== 1) {
                return new WP_Error(
                    count($matches) > 1
                        ? 'wss_global_mapping_ambiguous'
                        : 'wss_global_mapping_missing',
                    count($matches) > 1
                        ? 'A WooCommerce variation attribute resolves to multiple Shopier selection IDs.'
                        : 'A WooCommerce variation attribute has no exact global Shopier selection mapping.'
                );
            }

            $mapping = $matches[0];
            $group_id = (string) $mapping['shopier_group_id'];
            if (isset($seen_groups[$group_id])) {
                return new WP_Error(
                    'wss_global_mapping_duplicate_group',
                    'A WooCommerce variation resolves to more than one selection in the same Shopier group.'
                );
            }

            $seen_groups[$group_id] = true;
            $resolved[] = $mapping;
        }

        usort($resolved, static function ($left, $right) {
            return (string) $left['shopier_group_id'] <=> (string) $right['shopier_group_id'];
        });

        return $resolved;
    }
}

class WSS_WooCommerce_Attribute_Resolver
{
    public static function get_variation_attributes($variation_id)
    {
        if (!function_exists('wc_get_product')) {
            return new WP_Error(
                'wss_woocommerce_unavailable',
                'WooCommerce product APIs are not available.'
            );
        }

        $variation = wc_get_product(absint($variation_id));

        if (!$variation || !$variation->is_type('variation')) {
            return new WP_Error(
                'wss_variation_not_found',
                'WooCommerce variation was not found.'
            );
        }

        $attributes = [];

        foreach ($variation->get_attributes() as $name => $value) {
            $name = WSS_Attribute_Map::normalize_attribute_name($name);
            $value = trim(sanitize_text_field((string) $value));

            if ($name === '' || $value === '') {
                return new WP_Error(
                    'wss_variation_attribute_incomplete',
                    'Every mapped WooCommerce variation must have a concrete value for each attribute.'
                );
            }

            if (function_exists('taxonomy_exists') && taxonomy_exists($name)) {
                $term = get_term_by('slug', sanitize_title($value), $name);

                if (!$term || is_wp_error($term)) {
                    return new WP_Error(
                        'wss_variation_term_not_found',
                        sprintf('Could not resolve WooCommerce attribute term for %s.', $name)
                    );
                }

                $value = (string) $term->slug;
            }

            if (array_key_exists($name, $attributes)) {
                return new WP_Error(
                    'wss_variation_attribute_duplicate',
                    'WooCommerce variation contains a duplicate attribute.'
                );
            }

            $attributes[$name] = $value;
        }

        if (empty($attributes)) {
            return new WP_Error(
                'wss_variation_attributes_empty',
                'WooCommerce variation has no concrete attributes to map.'
            );
        }

        ksort($attributes, SORT_STRING);
        return $attributes;
    }
}

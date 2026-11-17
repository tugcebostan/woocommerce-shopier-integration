<?php

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Database
{
    const SCHEMA_VERSION = '1.4.0';

    public static function init()
    {
        add_action('plugins_loaded', [self::class, 'maybe_upgrade']);
    }

    public static function maybe_upgrade()
    {
        $installed_version = get_option('wss_db_version', '0');

        if (version_compare($installed_version, self::SCHEMA_VERSION, '<')) {
            self::install();
        }
    }

    public static function install()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'wss_';

        $tables = [];

        $tables[] = "CREATE TABLE {$prefix}attribute_map (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            shopier_variation_id varchar(100) NOT NULL,
            shopier_selection_id varchar(100) NOT NULL,
            shopier_variation_title varchar(255) NOT NULL,
            shopier_selection_title varchar(255) NOT NULL,
            wc_attribute varchar(100) NOT NULL,
            wc_term_slug varchar(200) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY shopier_selection (shopier_variation_id,shopier_selection_id),
            KEY wc_attribute_term (wc_attribute,wc_term_slug)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$prefix}product_map (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wc_product_id bigint(20) unsigned NOT NULL,
            shopier_product_id varchar(100) NOT NULL,
            sync_status varchar(30) NOT NULL DEFAULT 'active',
            last_synced_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY wc_product_id (wc_product_id),
            UNIQUE KEY shopier_product_id (shopier_product_id),
            KEY sync_status (sync_status)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$prefix}variation_map (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wc_product_id bigint(20) unsigned NOT NULL,
            wc_variation_id bigint(20) unsigned NOT NULL,
            shopier_product_id varchar(100) NOT NULL,
            selection_key varchar(500) NOT NULL,
            sync_status varchar(30) NOT NULL DEFAULT 'active',
            last_synced_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY wc_variation_id (wc_variation_id),
            UNIQUE KEY shopier_combination (shopier_product_id,selection_key),
            KEY wc_product_id (wc_product_id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$prefix}sync_jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_type varchar(50) NOT NULL,
            job_data longtext DEFAULT NULL,
            status varchar(30) NOT NULL DEFAULT 'pending',
            total_items int(10) unsigned NOT NULL DEFAULT 0,
            processed_items int(10) unsigned NOT NULL DEFAULT 0,
            success_count int(10) unsigned NOT NULL DEFAULT 0,
            error_count int(10) unsigned NOT NULL DEFAULT 0,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY job_status (status),
            KEY job_type (job_type)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$prefix}sync_job_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            wc_product_id bigint(20) unsigned NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'pending',
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            error_message text DEFAULT NULL,
            processed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_product (job_id,wc_product_id),
            KEY item_status (status),
            KEY wc_product_id (wc_product_id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$prefix}stock_sync_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wc_product_id bigint(20) unsigned NOT NULL,
            wc_variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
            shopier_product_id varchar(100) NOT NULL,
            selection_key varchar(500) NOT NULL DEFAULT '',
            source varchar(30) NOT NULL,
            old_quantity decimal(20,6) DEFAULT NULL,
            new_quantity decimal(20,6) DEFAULT NULL,
            sync_status varchar(30) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY product_variation (wc_product_id,wc_variation_id),
            KEY shopier_product (shopier_product_id),
            KEY source_status (source,sync_status)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$prefix}order_map (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            shopier_order_id varchar(100) NOT NULL,
            wc_order_id bigint(20) unsigned NOT NULL,
            shopier_status varchar(50) DEFAULT NULL,
            payment_status varchar(50) DEFAULT NULL,
            last_synced_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY shopier_order_id (shopier_order_id),
            UNIQUE KEY wc_order_id (wc_order_id)
        ) $charset_collate;";

        $installed_version = get_option('wss_db_version', '0');

        if (version_compare($installed_version, '1.1.0', '<')) {
            foreach ($tables as $sql) {
                dbDelta($sql);
            }

            if (!self::migrate_to_110()) {
                return;
            }
        }

        if (
            version_compare($installed_version, '1.2.0', '<') &&
            !self::migrate_to_120()
        ) {
            return;
        }

        if (
            version_compare($installed_version, '1.3.0', '<') &&
            !self::migrate_to_130($tables)
        ) {
            return;
        }

        if (
            version_compare($installed_version, '1.4.0', '<') &&
            !self::migrate_to_140()
        ) {
            return;
        }

        update_option('wss_db_version', self::SCHEMA_VERSION);
    }

    /**
     * Add batch_id column to sync_job_items for parallel batch processing.
     */
    private static function migrate_to_140()
    {
        global $wpdb;

        $items_table = $wpdb->prefix . 'wss_sync_job_items';
        $column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `{$items_table}` LIKE %s",
                'batch_id'
            )
        );

        if ($column === 'batch_id') {
            return true;
        }

        $result = $wpdb->query(
            "ALTER TABLE `{$items_table}` ADD COLUMN batch_id int(10) unsigned NOT NULL DEFAULT 0 AFTER job_id"
        );

        if ($result === false) {
            error_log('WSS migration 1.4.0 stopped: Could not add batch_id column to sync_job_items.');
            return false;
        }

        return true;
    }

    private static function migrate_to_130($tables)
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        if (
            !isset($tables[3]) ||
            dbDelta($tables[3]) === false
        ) {
            error_log('WSS migration 1.3.0 stopped: Could not add job data storage.');
            return false;
        }

        $jobs_table = $wpdb->prefix . 'wss_sync_jobs';
        $column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `{$jobs_table}` LIKE %s",
                'job_data'
            )
        );

        if ($column !== 'job_data') {
            error_log('WSS migration 1.3.0 stopped: Job data column is missing.');
            return false;
        }

        return true;
    }

    /**
     * Add a database-enforced uniqueness key for Shopier selection combinations.
     */
    private static function migrate_to_120()
    {
        global $wpdb;

        $prefix = $wpdb->prefix . 'wss_';
        $variation_table = $prefix . 'variation_map';
        $items_table = $prefix . 'variation_map_items';
        $attribute_table = $prefix . 'attribute_map';

        foreach ([$variation_table, $items_table, $attribute_table] as $table) {
            $exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $table)
            );

            if ($exists !== $table) {
                error_log("WSS migration 1.2.0: Table not found: {$table}");
                return false;
            }
        }

        $signature_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `{$variation_table}` LIKE %s",
                'selection_signature'
            )
        );

        if (!$signature_exists) {
            $result = $wpdb->query(
                "ALTER TABLE `{$variation_table}`
                ADD COLUMN selection_signature CHAR(64) NOT NULL DEFAULT ''
                AFTER shopier_product_id"
            );

            if ($result === false) {
                error_log(
                    'WSS migration 1.2.0: Could not add selection_signature. ' .
                    $wpdb->last_error
                );
                return false;
            }
        }

        $variations = $wpdb->get_results(
            "SELECT id, shopier_product_id
            FROM `{$variation_table}`
            ORDER BY id ASC",
            ARRAY_A
        );

        foreach ($variations as $variation) {
            $pairs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT a.shopier_group_id, a.shopier_selection_id
                    FROM `{$items_table}` i
                    INNER JOIN `{$attribute_table}` a
                        ON a.id = i.attribute_map_id
                    WHERE i.variation_map_id = %d",
                    (int) $variation['id']
                ),
                ARRAY_A
            );

            if (empty($pairs)) {
                error_log(
                    'WSS migration 1.2.0 stopped: A variation mapping has no selection IDs.'
                );
                return false;
            }

            $groups = [];
            $signature_parts = [];

            foreach ($pairs as $pair) {
                $group_id = (string) $pair['shopier_group_id'];
                $selection_id = (string) $pair['shopier_selection_id'];

                if ($group_id === '' || $selection_id === '' || isset($groups[$group_id])) {
                    error_log(
                        'WSS migration 1.2.0 stopped: Existing selection mapping is invalid.'
                    );
                    return false;
                }

                $groups[$group_id] = true;
                $signature_parts[] = [$group_id, $selection_id];
            }

            usort($signature_parts, static function ($left, $right) {
                return [$left[0], $left[1]] <=> [$right[0], $right[1]];
            });

            $signature = hash('sha256', wp_json_encode($signature_parts));
            $updated = $wpdb->update(
                $variation_table,
                ['selection_signature' => $signature],
                ['id' => (int) $variation['id']],
                ['%s'],
                ['%d']
            );

            if ($updated === false) {
                error_log(
                    'WSS migration 1.2.0: Could not backfill selection signature. ' .
                    $wpdb->last_error
                );
                return false;
            }
        }

        $duplicate_signature = $wpdb->get_var(
            "SELECT shopier_product_id
            FROM `{$variation_table}`
            GROUP BY shopier_product_id, selection_signature
            HAVING COUNT(*) > 1
            LIMIT 1"
        );

        if ($duplicate_signature) {
            error_log(
                'WSS migration 1.2.0 stopped: Duplicate Shopier variation combinations require manual review.'
            );
            return false;
        }

        $index_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW INDEX FROM `{$variation_table}` WHERE Key_name = %s",
                'shopier_selection_signature'
            )
        );

        if (!$index_exists) {
            $result = $wpdb->query(
                "ALTER TABLE `{$variation_table}`
                ADD UNIQUE KEY shopier_selection_signature
                (shopier_product_id, selection_signature)"
            );

            if ($result === false) {
                error_log(
                    'WSS migration 1.2.0: Could not add selection signature index. ' .
                    $wpdb->last_error
                );
                return false;
            }
        }

        return true;
    }

/**
 * 1.0.0 -> 1.1.0 migration.
 *
 * Mevcut eşleştirme kayıtları varsa otomatik olarak
 * kapsam varsayımı yapmaz ve migration'ı durdurur.
 */
private static function migrate_to_110()
{
    global $wpdb;

    $prefix = $wpdb->prefix . 'wss_';

    $attribute_table = $prefix . 'attribute_map';
    $variation_table = $prefix . 'variation_map';
    $items_table = $prefix . 'variation_map_items';

    // Tabloların varlığını kontrol et.
    foreach ([$attribute_table, $variation_table] as $table) {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $table
            )
        );

        if ($exists !== $table) {
            error_log("WSS migration: Table not found: {$table}");
            return false;
        }
    }

    /*
     * Mevcut mapping kayıtları varsa dur.
     *
     * Eski attribute kayıtlarının global mi custom mı
     * olduğunu mevcut şemadan kesin olarak bilemeyiz.
     *
     * Eski selection_key formatını da henüz doğrulamadık.
     */
    $attribute_count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `{$attribute_table}`"
    );

    $variation_count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `{$variation_table}`"
    );

    if ($attribute_count > 0 || $variation_count > 0) {
        error_log(
            'WSS migration stopped: Existing mappings require manual migration.'
        );

        return false;
    }

    /*
     * 1. attribute_map yeni sütunları.
     */

    $columns = [
        'shopier_group_id' => "
            ALTER TABLE `{$attribute_table}`
            ADD COLUMN shopier_group_id VARCHAR(100) NOT NULL DEFAULT ''
            AFTER id
        ",

        'shopier_group_title' => "
            ALTER TABLE `{$attribute_table}`
            ADD COLUMN shopier_group_title VARCHAR(255) NOT NULL DEFAULT ''
            AFTER shopier_group_id
        ",

        'wc_scope_id' => "
            ALTER TABLE `{$attribute_table}`
            ADD COLUMN wc_scope_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0
            AFTER shopier_selection_title
        ",

        'wc_value' => "
            ALTER TABLE `{$attribute_table}`
            ADD COLUMN wc_value VARCHAR(200) NOT NULL DEFAULT ''
            AFTER wc_attribute
        ",
    ];

    foreach ($columns as $column => $sql) {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `{$attribute_table}` LIKE %s",
                $column
            )
        );

        if (!$exists) {
            $result = $wpdb->query($sql);

            if ($result === false) {
                error_log(
                    "WSS migration: Could not add column {$column}. " .
                    $wpdb->last_error
                );

                return false;
            }
        }
    }

    /*
     * 2. Yeni indeksler.
     */

    $indexes = [
        'shopier_selection_scope' => "
            UNIQUE KEY shopier_selection_scope
            (shopier_group_id, shopier_selection_id, wc_scope_id)
        ",

        'wc_value_group' => "
            UNIQUE KEY wc_value_group
            (wc_scope_id, wc_attribute, wc_value, shopier_group_id)
        ",
    ];

    foreach ($indexes as $index => $definition) {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW INDEX FROM `{$attribute_table}` WHERE Key_name = %s",
                $index
            )
        );

        if (!$exists) {
            $result = $wpdb->query(
                "ALTER TABLE `{$attribute_table}` ADD {$definition}"
            );

            if ($result === false) {
                error_log(
                    "WSS migration: Could not add index {$index}. " .
                    $wpdb->last_error
                );

                return false;
            }
        }
    }

    /*
     * 3. Yeni ilişki tablosu.
     */

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$items_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        variation_map_id bigint(20) unsigned NOT NULL,
        attribute_map_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY variation_attribute (variation_map_id,attribute_map_id),
        KEY attribute_map_id (attribute_map_id)
    ) $charset_collate;";

    dbDelta($sql);

    $items_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $items_table
        )
    );

    if ($items_exists !== $items_table) {
        error_log('WSS migration: variation_map_items creation failed.');
        return false;
    }

    /*
     * 4. Eski indeksleri ve sütunları kaldır.
     *
     * Bu aşamaya yalnızca iki mapping tablosu da boşsa
     * ulaşılabilir.
     */

    $old_index = $wpdb->get_var(
        $wpdb->prepare(
            "SHOW INDEX FROM `{$attribute_table}` WHERE Key_name = %s",
            'shopier_selection'
        )
    );

    if ($old_index) {
        $result = $wpdb->query(
            "ALTER TABLE `{$attribute_table}` DROP INDEX shopier_selection"
        );

        if ($result === false) {
            error_log('WSS migration: Could not drop old attribute index.');
            return false;
        }
    }

    $old_variation_index = $wpdb->get_var(
        $wpdb->prepare(
            "SHOW INDEX FROM `{$variation_table}` WHERE Key_name = %s",
            'shopier_combination'
        )
    );

    if ($old_variation_index) {
        $result = $wpdb->query(
            "ALTER TABLE `{$variation_table}` DROP INDEX shopier_combination"
        );

        if ($result === false) {
            error_log('WSS migration: Could not drop old variation index.');
            return false;
        }
    }

    // Eski attribute_map sütunları.
    foreach ([
        'shopier_variation_id',
        'shopier_variation_title',
        'wc_term_slug',
    ] as $column) {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `{$attribute_table}` LIKE %s",
                $column
            )
        );

        if ($exists) {
            $result = $wpdb->query(
                "ALTER TABLE `{$attribute_table}` DROP COLUMN `{$column}`"
            );

            if ($result === false) {
                error_log(
                    "WSS migration: Could not drop {$column}. " .
                    $wpdb->last_error
                );

                return false;
            }
        }
    }

    // Eski variation_map.selection_key.
    $selection_key_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SHOW COLUMNS FROM `{$variation_table}` LIKE %s",
            'selection_key'
        )
    );

    if ($selection_key_exists) {
        $result = $wpdb->query(
            "ALTER TABLE `{$variation_table}` DROP COLUMN selection_key"
        );

        if ($result === false) {
            error_log(
                'WSS migration: Could not drop selection_key. ' .
                $wpdb->last_error
            );

            return false;
        }
    }

    return true;
}
}

WSS_Database::init();

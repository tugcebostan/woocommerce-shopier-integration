<?php

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Database
{
    const SCHEMA_VERSION = '1.1.0';

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

        foreach ($tables as $sql) {
            dbDelta($sql);
        }
        
        // Yeni şemaya geçiş tamamlanmadan sürümü yükseltme.
        if (!self::migrate_to_110()) {
            return;
        }
        
        update_option('wss_db_version', self::SCHEMA_VERSION);
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

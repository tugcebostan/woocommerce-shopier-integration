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
            'stock'      => 'Stok Yönetimi',
            'orders'     => 'Siparişler',
            'logs'       => 'İşlem Geçmişi',
            'settings'   => 'Ayarlar',
        ];

        $active_tab = isset($_GET['tab'])
            ? sanitize_key(wp_unslash($_GET['tab']))
            : 'overview';

        if (!array_key_exists($active_tab, $tabs)) {
            $active_tab = 'overview';
        }

        echo '<div class="wrap">';
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

        self::render_recent_jobs();
        self::render_recent_stock();
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
            echo '<td>' . esc_html($record['id']) . '</td>';
            echo '<td>' . esc_html($record['job_type']) . '</td>';
            echo '<td>' . esc_html($record['status']) . '</td>';
            echo '<td>' . esc_html($record['total_count']) . '</td>';
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
        echo '<th>ID</th><th>Kaynak</th><th>Eski Stok</th>';
        echo '<th>Yeni Stok</th><th>Durum</th><th>Tarih</th>';
        echo '</tr></thead><tbody>';

        foreach ($records as $record) {
            echo '<tr>';
            echo '<td>' . esc_html($record['id']) . '</td>';
            echo '<td>' . esc_html($record['source']) . '</td>';
            echo '<td>' . esc_html($record['old_quantity']) . '</td>';
            echo '<td>' . esc_html($record['new_quantity']) . '</td>';
            echo '<td>' . esc_html($record['status']) . '</td>';
            echo '<td>' . esc_html($record['created_at']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }


    private static function render_products()
    {
        $status = isset($_GET['product_status'])
            ? sanitize_key(wp_unslash($_GET['product_status']))
            : 'unmatched';

        if (!in_array($status, ['matched', 'unmatched'], true)) {
            $status = 'unmatched';
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
            'orderby'  => 'name',
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

        echo '<p>Toplam WooCommerce ürünü: ' . esc_html($result->total) . '</p>';

        if (!$products) {
            echo '<p>Bu listede gösterilecek ürün bulunamadı.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>Görsel</th><th>Ürün</th><th>ID</th><th>SKU</th>';
            echo '<th>Tür</th><th>Kategori</th><th>Fiyat</th>';
            echo '<th>Stok</th><th>Varyasyon</th><th>Shopier ID</th><th>Durum</th>';
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
                echo '<td>' . esc_html($variation_count) . '</td>';
                echo '<td>' . esc_html($mapping ? $mapping->shopier_product_id : '—') . '</td>';
                echo '<td>' . esc_html($mapping ? $mapping->sync_status : 'Eşleştirilmemiş') . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
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
    }

    private static function get_product_mapping($product_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wss_product_map';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT shopier_product_id, sync_status
             FROM `{$table}` WHERE wc_product_id = %d LIMIT 1",
            $product_id
        ));
    }

    private static function render_placeholder($title)
    {
        echo '<h2>' . esc_html($title) . '</h2>';
        echo '<p>Bu bölüm sonraki geliştirme aşamasında etkinleştirilecek.</p>';
    }
}

WSS_Admin::init();

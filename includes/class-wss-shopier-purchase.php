<?php

if (!defined('ABSPATH')) {
    exit;
}

/** External Shopier purchase links for WooCommerce products. */
class WSS_Shopier_Purchase
{
    public static function init()
    {
        add_action('woocommerce_product_options_general_product_data', [self::class, 'render_simple_product_field']);
        add_action('woocommerce_admin_process_product_object', [self::class, 'save_simple_product_field']);
        add_filter('woocommerce_loop_add_to_cart_link', [self::class, 'replace_loop_button'], 10, 3);
        add_action('wp', [self::class, 'replace_single_add_to_cart'], 20);
        add_action('woocommerce_after_add_to_cart_button', [self::class, 'render_variable_purchase_link']);
        add_action('wp_head', [self::class, 'print_product_styles']);
    }

    public static function render_simple_product_field()
    {
        global $post;
        $product = isset($post->ID) ? wc_get_product($post->ID) : false;
        $value = $product instanceof WC_Product
            ? (string) $product->get_meta('_wss_shopier_product_url', true)
            : '';
        if ($value === '' && $product instanceof WC_Product) {
            $value = WSS_Product_Sync::get_shopier_product_url(
                WSS_Product_Sync::get_existing_shopier_product_id($product->get_id())
            );
        }
        woocommerce_wp_text_input([
            'id' => '_wss_shopier_product_url',
            'value' => $value,
            'label' => 'Shopier ürün bağlantısı',
            'description' => 'Shopier ürün bağlantısını girin. Değişken ürünlerde tüm varyasyonlar aynı Shopier ürün bağlantısını kullanır.',
            'desc_tip' => true,
            'type' => 'url',
            'wrapper_class' => 'show_if_simple show_if_variable',
            'custom_attributes' => ['placeholder' => 'https://www.shopier.com/zevrakdesign/ürün-id'],
        ]);
    }

    public static function save_simple_product_field($product)
    {
        if (!$product instanceof WC_Product || !$product->is_type(['simple', 'variable']) || !isset($_POST['_wss_shopier_product_url'])) {
            return;
        }

        $url = self::sanitize_shopier_url(wp_unslash($_POST['_wss_shopier_product_url']));
        if ($url === '') {
            $product->delete_meta_data('_wss_shopier_product_url');
        } else {
            $product->update_meta_data('_wss_shopier_product_url', $url);
        }
    }

    public static function replace_loop_button($html, $product, $args)
    {
        // Keep WooCommerce's native add-to-cart controls inside the cart page,
        // including cross-sells rendered there.
        if (function_exists('is_cart') && is_cart()) {
            return $html;
        }

        if (!$product instanceof WC_Product) {
            return $html;
        }

        if ($product->is_type('simple')) {
            $url = self::get_simple_product_url($product);
            if ($url !== '') {
                return self::purchase_anchor($url, "Shopier'den Satın Al", 'button product_type_simple');
            }
        }

        if ($product->is_type('variable')) {
            return self::loop_options_icon(get_permalink($product->get_id()));
        }

        return self::purchase_anchor(
            get_permalink($product->get_id()),
            'Ürünü görüntüle',
            'button product_type_variable'
        );
    }

    public static function replace_single_add_to_cart()
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        add_action('woocommerce_single_product_summary', [self::class, 'render_single_purchase'], 30);
    }

    public static function render_single_purchase()
    {
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }

        if ($product->is_type('variable')) {
            woocommerce_variable_add_to_cart();
            return;
        }

        if (!$product->is_type('simple')) {
            return;
        }

        $url = self::get_simple_product_url($product);
        if ($url === '') {
            if (current_user_can('edit_post', $product->get_id()) && $url === '') {
                echo '<p class="wss-shopier-link-missing">Shopier ürün bağlantısı ürün düzenleme sayfasından eklenmeli.</p>';
            }
            return;
        }

        echo '<div class="wss-shopier-purchase">' . self::purchase_anchor($url, "Shopier'den Satın Al", 'button alt') . '</div>';
    }

    public static function render_variable_purchase_link()
    {
        global $product;
        if (!$product instanceof WC_Product || !$product->is_type('variable')) {
            return;
        }
        $url = self::get_simple_product_url($product);
        if ($url === '') {
            if (current_user_can('edit_post', $product->get_id())) {
                echo '<p class="wss-shopier-link-missing">Shopier ürün bağlantısı ürün düzenleme sayfasından eklenmeli.</p>';
            }
            return;
        }
        echo self::purchase_anchor($url, "Shopier'den Satın Al", 'wss-shopier-buy button alt');
    }

    public static function print_product_styles()
    {
        $is_product_page = function_exists('is_product') && is_product();
        $is_shop_archive = function_exists('is_shop') && (is_shop() || is_product_taxonomy());
        if (!$is_product_page && !$is_shop_archive) {
            return;
        }
        echo '<style>';
        if ($is_shop_archive) {
            echo '.woocommerce ul.products li.product .buttons-icon{display:none!important}';
        }
        if ($is_product_page) {
            echo '.single-product .wcboost-wishlist-button{display:none!important}
                .variations_form .single_add_to_cart_button:not(.wss-shopier-buy){display:none!important}
                .single-product .cart .quantity{display:none!important}
                .wss-shopier-buy[hidden]{display:none!important}
                .wss-shopier-purchase{margin:1em 0}
                .wss-shopier-link-missing{color:#a00}';
        }
        echo '</style>';
    }

    private static function get_simple_product_url($product)
    {
        $url = self::sanitize_shopier_url((string) $product->get_meta('_wss_shopier_product_url', true));
        if ($url !== '') {
            return $url;
        }

        $shopier_id = WSS_Product_Sync::get_existing_shopier_product_id($product->get_id());
        return WSS_Product_Sync::get_shopier_product_url($shopier_id);
    }

    public static function sanitize_shopier_url($url)
    {
        if (!is_scalar($url)) {
            return '';
        }
        $url = esc_url_raw(trim((string) $url), ['https']);
        if ($url === '') {
            return '';
        }
        $parts = wp_parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (
            ($parts['scheme'] ?? '') !== 'https' ||
            ($host !== 'shopier.com' && $host !== 'www.shopier.com')
        ) {
            return '';
        }
        return $url;
    }

    private static function purchase_anchor($url, $label, $classes)
    {
        return sprintf(
            '<a href="%1$s" class="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
            esc_url($url),
            esc_attr($classes),
            esc_html($label)
        );
    }

    private static function loop_options_icon($url)
    {
        return sprintf(
            '<a href="%1$s" class="button product_type_variable add_to_cart_button" target="_blank" rel="noopener noreferrer" aria-label="%2$s" title="%2$s"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></a>',
            esc_url($url),
            esc_attr__('Seçenekler', 'woo-shopier-sync')
        );
    }

}

<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Deprecated legacy cart transfer. The purchase flow now uses product links. */
class WSS_Shopier_Cart
{
    const STORE_URL = 'https://www.shopier.com/s/basket/zevrakdesign';
    const ADD_ITEM_URL = 'https://www.shopier.com/s/api/v1/add_chart_item/zevrakdesign';

    public static function init()
    {
        // Intentionally disabled: WooCommerce carts are no longer sent to Shopier.
    }

    public static function handle_checkout_redirect()
    {
        if (
            !function_exists('is_checkout') ||
            !is_checkout() ||
            (function_exists('is_order_received_page') && is_order_received_page()) ||
            (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url()) ||
            !function_exists('WC') ||
            !WC()->cart ||
            WC()->cart->is_empty()
        ) {
            return;
        }

        $result = self::push_cart(WC()->cart->get_cart());
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $http_status = is_array($error_data) && isset($error_data['http_status'])
                ? (int) $error_data['http_status']
                : 0;
            error_log(
                '[WooCommerce Shopier Sync] Cart transfer failed: ' .
                sanitize_key($result->get_error_code()) .
                ($http_status ? ' (HTTP ' . $http_status . ')' : '')
            );
        }

        wp_redirect(self::STORE_URL, 302, 'WooCommerce Shopier Sync');
        exit;
    }

    /** Add the current WooCommerce cart to a single, fresh Shopier session. */
    public static function push_cart($cart_items)
    {
        if (!function_exists('curl_init') || empty($cart_items) || !is_array($cart_items)) {
            return new WP_Error('wss_shopier_cart_unavailable', 'The cart or cURL extension is unavailable.');
        }

        $items = [];
        foreach ($cart_items as $cart_item) {
            $item = self::build_item_payload($cart_item);
            if (is_wp_error($item)) {
                return $item;
            }
            $items[] = $item;
        }

        $cookie_file = function_exists('wp_tempnam')
            ? wp_tempnam('wss-shopier-cookie-')
            : tempnam(sys_get_temp_dir(), 'wss-shopier-');
        if (!$cookie_file) {
            return new WP_Error('wss_shopier_cookie_jar_failed', 'Could not create a temporary Shopier session.');
        }
        @chmod($cookie_file, 0600);

        try {
            $first_product_id = $items[0]['product_id'];
            $session = self::request(
                'GET',
                'https://www.shopier.com/zevrakdesign/' . rawurlencode($first_product_id),
                $cookie_file
            );
            if (is_wp_error($session)) {
                return $session;
            }

            $csrf_token = self::extract_csrf_token($session['body']);
            if (!$csrf_token) {
                return new WP_Error('wss_shopier_csrf_missing', 'Shopier did not return a CSRF token.');
            }

            foreach ($items as $item) {
                $referer = 'https://www.shopier.com/zevrakdesign/' . rawurlencode($item['product_id']);
                $response = self::request('POST', self::ADD_ITEM_URL, $cookie_file, [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ] + $item['variations'], $csrf_token, $referer);

                if (is_wp_error($response)) {
                    return $response;
                }

                $decoded = json_decode($response['body'], true);
                if (!is_array($decoded) || (int) ($decoded['status'] ?? 0) !== 1) {
                    return new WP_Error('wss_shopier_add_failed', 'Shopier rejected a cart item.');
                }
            }
        } finally {
            if (is_file($cookie_file)) {
                @unlink($cookie_file);
            }
        }

        return true;
    }

    private static function build_item_payload($cart_item)
    {
        $product_id = absint($cart_item['product_id'] ?? 0);
        $variation_id = absint($cart_item['variation_id'] ?? 0);
        $quantity = absint($cart_item['quantity'] ?? 0);
        $wc_product_id = $variation_id ? absint(wp_get_post_parent_id($variation_id)) : $product_id;
        $shopier_product_id = WSS_Product_Sync::get_existing_shopier_product_id($wc_product_id);

        if (!$shopier_product_id || !$quantity || !preg_match('/\A[a-zA-Z0-9_-]+\z/', (string) $shopier_product_id)) {
            return new WP_Error('wss_shopier_product_unmapped', 'A cart item has no valid Shopier product mapping.');
        }

        $variations = [];
        if ($variation_id) {
            if (!WSS_Variation_Map::has_current_mapping($variation_id)) {
                return new WP_Error('wss_shopier_variation_unmapped', 'A cart variation has no current Shopier mapping.');
            }

            $mappings = WSS_Variation_Map::get_for_variation($variation_id);
            if (!is_array($mappings) || count($mappings) > 2) {
                return new WP_Error('wss_shopier_variation_unsupported', 'The Shopier variation mapping is unsupported.');
            }

            foreach (array_values($mappings) as $index => $mapping) {
                $number = $index + 1;
                $prefix = $number === 1 ? 'first' : 'second';
                $group_title = (string) ($mapping['shopier_group_title'] ?? '');
                $selection_id = (string) ($mapping['shopier_selection_id'] ?? '');
                if ($group_title === '' || $selection_id === '') {
                    return new WP_Error('wss_shopier_variation_invalid', 'A Shopier variation mapping is incomplete.');
                }

                $variations[$prefix . '_variation_name'] = $group_title;
                $variations[$prefix . '_variation_id'] = $selection_id;
                $variations[$number === 1 ? 'size' : 'color'] = $selection_id;
                $variations[$prefix] = $selection_id;
            }
        }

        return [
            'product_id' => (string) $shopier_product_id,
            'quantity' => $quantity,
            'variations' => $variations,
        ];
    }

    private static function request($method, $url, $cookie_file, $data = null, $csrf_token = '', $referer = '')
    {
        $headers = [
            'Accept: */*',
            'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/157.0.0.0 Safari/537.36',
        ];
        if ($method === 'POST') {
            $headers[] = 'Origin: https://www.shopier.com';
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'X-Requested-With: XMLHttpRequest';
            $headers[] = 'X-CSRF-TOKEN: ' . $csrf_token;
            $headers[] = 'Referer: ' . $referer;
        } else {
            $headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
            $headers[] = 'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7';
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/157.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $cookie_file,
            CURLOPT_COOKIEFILE => $cookie_file,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data, '', '&', PHP_QUERY_RFC1738));
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            return new WP_Error('wss_shopier_curl_failed', 'Shopier request failed: ' . sanitize_text_field($error));
        }
        if ($status < 200 || $status >= 300) {
            return new WP_Error(
                'wss_shopier_http_failed',
                'Shopier returned HTTP ' . $status . '.',
                ['http_status' => $status]
            );
        }

        return ['body' => $body, 'status' => $status];
    }

    private static function extract_csrf_token($html)
    {
        if (preg_match('/<meta\s+name=["\']csrf-token["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        }
        return '';
    }
}

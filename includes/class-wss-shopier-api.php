<?php

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Shopier_API
{
    /**
     * Local helper to read credential constants from wp-config.
     */
    public static function get_client_id()
    {
        return defined('SHOPIER_CLIENT_ID') ? constant('SHOPIER_CLIENT_ID') : '';
    }

    public static function get_client_secret()
    {
        return defined('SHOPIER_CLIENT_SECRET') ? constant('SHOPIER_CLIENT_SECRET') : '';
    }

    public static function get_webhook_token()
    {
        return defined('SHOPIER_WEBHOOK_TOKEN') ? constant('SHOPIER_WEBHOOK_TOKEN') : '';
    }

    public static function get_base_url()
    {
        if (defined('SHOPIER_API_BASE_URL') && constant('SHOPIER_API_BASE_URL')) {
            return rtrim(constant('SHOPIER_API_BASE_URL'), '/');
        }

        return 'https://api.shopier.com/v1';
    }

    public static function get_token_url()
    {
        if (defined('SHOPIER_TOKEN_URL') && constant('SHOPIER_TOKEN_URL')) {
            return constant('SHOPIER_TOKEN_URL');
        }

        return 'https://api.shopier.com:8443/v1/oauth2/token';
    }

    private static function get_access_token()
    {
        $pat = '';

        foreach (['SHOPIER_API_KEY', 'SHOPIER_PAT', 'SHOPIER_BEARER_TOKEN'] as $constant_name) {
            if (defined($constant_name) && constant($constant_name)) {
                $pat = trim((string) constant($constant_name));
                break;
            }
        }

        if (!empty($pat)) {
            return [
                'success' => true,
                'token' => self::normalize_token($pat),
            ];
        }

        $oauth_token = get_option('wss_shopier_access_token', '');
        $expires_at = (int) get_option('wss_shopier_access_token_expires_at', 0);

        if (!empty($oauth_token) && $expires_at > time()) {
            return [
                'success' => true,
                'token' => self::normalize_token($oauth_token),
            ];
        }

        return [
            'success' => false,
            'message' => 'No Shopier PAT or valid OAuth access token is configured. Client credentials alone cannot access the API.',
        ];
    }

    private static function normalize_token($token)
    {
        return preg_replace('/^Bearer\s+/i', '', trim((string) $token));
    }

    public static function request($method, $path, $payload = [], $token = null)
    {
        $url = self::get_base_url() . $path;
        $token = $token ?: self::get_access_token();

        if (!is_array($token) || empty($token['success']) || empty($token['token'])) {
            return [
                'success' => false,
                'message' => 'Shopier authentication failed.',
                'details' => !empty($token['message']) ? $token['message'] : '',
            ];
        }

        $args = [
            'method' => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token['token'],
                'Accept' => 'application/json',
            ],
        ];

        if (!empty($payload)) {
            $encoded_payload = wp_json_encode($payload);
            if ($encoded_payload === false) {
                return [
                    'success' => false,
                    'message' => 'Shopier request payload could not be encoded as JSON.',
                ];
            }
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $encoded_payload;
            
            // Log payload for debugging
            require_once __DIR__ . '/class-wss-logger.php';
            if (strlen($encoded_payload) > 10000) {
                WSS_Logger::log('shopier-api', 'Large payload being sent', 'DEBUG', [
                    'event' => 'large_payload',
                    'method' => $method,
                    'endpoint' => $url,
                    'payload_size' => strlen($encoded_payload),
                ]);
            }
            // Log full payload on errors (will be checked after response)
            if ($method === 'PUT') {
                // Store payload for verification logging if needed
                // This is used to cross-reference error responses
                $GLOBALS['wss_last_payload'] = [
                    'method' => $method,
                    'endpoint' => $url,
                    'payload' => $payload,
                ];
            }
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status = wp_remote_retrieve_response_code($response);
        
        // Log error responses for debugging
        if ($status >= 400) {
            $response_body = wp_remote_retrieve_body($response);
            error_log('Shopier API Error (HTTP ' . $status . '): ' . $response_body);
            
            // Also log to plugin logger
            require_once __DIR__ . '/class-wss-logger.php';
            WSS_Logger::log('shopier-api', 'API request error', 'ERROR', [
                'event' => 'api_request_error',
                'http_status' => $status,
                'response_body' => $response_body,
            ]);
        }

        return [
            'success' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body,
            'message' => $status >= 400 ? 'Shopier request failed.' : 'Shopier request succeeded.',
        ];
    }

    /**
     * Perform a read-only API request to verify authentication and connectivity.
     */
    public static function test_connection()
    {
        $token = self::get_access_token();

        if (empty($token['success'])) {
            return $token;
        }

        return self::request('GET', '/products?limit=1', [], $token);
    }

    public static function get_product($shopier_product_id)
    {
        $shopier_product_id = trim((string) $shopier_product_id);

        if (
            $shopier_product_id === '' ||
            strlen($shopier_product_id) > 100 ||
            !preg_match('/\A[a-zA-Z0-9_-]+\z/', $shopier_product_id)
        ) {
            return [
                'success' => false,
                'message' => 'Invalid Shopier product ID.',
            ];
        }

        return self::request(
            'GET',
            '/products/' . rawurlencode($shopier_product_id)
        );
    }

    public static function update_product($shopier_product_id, $payload)
    {
        $shopier_product_id = trim((string) $shopier_product_id);
        if (
            $shopier_product_id === '' ||
            strlen($shopier_product_id) > 100 ||
            !preg_match('/\A[a-zA-Z0-9_-]+\z/', $shopier_product_id) ||
            !is_array($payload) ||
            empty($payload)
        ) {
            return [
                'success' => false,
                'message' => 'Invalid Shopier product ID or update payload.',
            ];
        }

        return self::request(
            'PUT',
            '/products/' . rawurlencode($shopier_product_id),
            $payload
        );
    }

    public static function delete_product($shopier_product_id)
    {
        $shopier_product_id = trim((string) $shopier_product_id);
        if (
            $shopier_product_id === '' ||
            strlen($shopier_product_id) > 100 ||
            !preg_match('/\A[a-zA-Z0-9_-]+\z/', $shopier_product_id)
        ) {
            return [
                'success' => false,
                'message' => 'Invalid Shopier product ID.',
            ];
        }

        return self::request(
            'DELETE',
            '/products/' . rawurlencode($shopier_product_id)
        );
    }

    public static function create_product($payload)
    {
        if (
            !is_array($payload) ||
            !isset($payload['title']) ||
            !is_scalar($payload['title']) ||
            trim((string) $payload['title']) === '' ||
            !isset($payload['type'], $payload['media'], $payload['priceData'], $payload['shippingPayer']) ||
            !in_array($payload['type'], ['physical', 'digital'], true) ||
            !is_array($payload['media']) ||
            !is_array($payload['priceData']) ||
            !in_array($payload['shippingPayer'], ['sellerPays', 'buyerPays'], true) ||
            !isset($payload['placementScore']) ||
            !is_scalar($payload['placementScore']) ||
            !is_numeric($payload['placementScore']) ||
            floor((float) $payload['placementScore']) !== (float) $payload['placementScore'] ||
            (float) $payload['placementScore'] < 2 ||
            !isset($payload['priceData']['currency']) ||
            !in_array($payload['priceData']['currency'], ['TRY', 'USD', 'EUR'], true) ||
            !isset($payload['priceData']['price']) ||
            !is_scalar($payload['priceData']['price']) ||
            trim((string) $payload['priceData']['price']) === ''
        ) {
            return [
                'success' => false,
                'message' => 'Invalid Shopier product creation payload.',
            ];
        }

        return self::request('POST', '/products', $payload);
    }

    public static function get_error_summary($body, $fallback = '')
    {
        $messages = [];
        if (is_array($body)) {
            foreach (['message', 'error', 'detail', 'error_description', 'title', 'code'] as $key) {
                if (isset($body[$key]) && is_scalar($body[$key])) {
                    $messages[] = $key . ': ' . (string) $body[$key];
                }
            }
            if (isset($body['errors'])) {
                self::collect_error_messages($body['errors'], $messages, 0);
            }
        }

        if (empty($messages) && is_string($fallback)) {
            $fallback = trim($fallback);
            if ($fallback !== '' && $fallback !== 'Shopier request failed.') {
                $messages[] = $fallback;
            }
        }

        $summary = sanitize_text_field(implode('; ', array_unique($messages)));
        $summary = preg_replace('/\bBearer\s+\S+/i', 'Bearer [redacted]', $summary);
        $summary = preg_replace(
            '/\b(token|secret|password|api[_-]?key)\b\s*[:=]\s*["\']?[^,\s;"\']+/i',
            '$1=[redacted]',
            $summary
        );
        $summary = preg_replace('~https?://\S+~i', '[URL]', $summary);

        return substr($summary, 0, 1000);
    }

    private static function collect_error_messages($errors, &$messages, $depth, $field = '')
    {
        if ($depth > 3 || count($messages) >= 10) {
            return;
        }
        if (is_scalar($errors)) {
            $message = trim((string) $errors);
            if ($message !== '') {
                $messages[] = ($field !== '' ? $field . ': ' : '') . $message;
            }
            return;
        }
        if (!is_array($errors)) {
            return;
        }

        foreach ($errors as $key => $value) {
            if (count($messages) >= 10) {
                return;
            }
            if (is_scalar($value)) {
                $message = trim((string) $value);
                if ($message !== '') {
                    $child_field = $field;
                    if (is_string($key)) {
                        $child_field = $child_field !== ''
                            ? $child_field . '.' . $key
                            : $key;
                    }
                    $messages[] = ($child_field !== '' ? $child_field . ': ' : '')
                        . $message;
                }
            } elseif (is_array($value)) {
                $child_field = $field;
                if (is_string($key)) {
                    $child_field = $child_field !== ''
                        ? $child_field . '.' . $key
                        : $key;
                }
                self::collect_error_messages($value, $messages, $depth + 1, $child_field);
            }
        }
    }

    public static function get_products_catalog()
    {
        $products = [];

        for ($page = 1; $page <= 20; $page++) {
            $response = self::request(
                'GET',
                add_query_arg([
                    'limit' => 50,
                    'page' => $page,
                ], '/products')
            );

            if (empty($response['success']) || !is_array($response['body'])) {
                return $response;
            }

            foreach ($response['body'] as $product) {
                if (
                    !is_array($product) ||
                    !isset($product['id'], $product['title']) ||
                    !is_scalar($product['id']) ||
                    !is_scalar($product['title'])
                ) {
                    return [
                        'success' => false,
                        'status' => absint($response['status'] ?? 0),
                        'message' => 'Shopier returned an invalid product catalog record.',
                    ];
                }

                $product_id = trim((string) $product['id']);
                $title = sanitize_text_field((string) $product['title']);
                if (
                    $product_id === '' ||
                    strlen($product_id) > 100 ||
                    !preg_match('/\A[a-zA-Z0-9_-]+\z/', $product_id) ||
                    $title === ''
                ) {
                    return [
                        'success' => false,
                        'status' => absint($response['status'] ?? 0),
                        'message' => 'Shopier returned an invalid product ID or title.',
                    ];
                }

                $products[$product_id] = [
                    'id' => $product_id,
                    'title' => $title,
                ];
            }

            if (count($response['body']) < 50) {
                break;
            }

            if ($page === 20) {
                return [
                    'success' => false,
                    'status' => absint($response['status'] ?? 0),
                    'message' => 'Shopier product pagination exceeded the supported safety limit.',
                ];
            }
        }

        return [
            'success' => true,
            'body' => array_values($products),
        ];
    }

    public static function get_next_placement_score()
    {
        $max_score = 1;

        for ($page = 1; $page <= 20; $page++) {
            $response = self::request(
                'GET',
                add_query_arg([
                    'limit' => 50,
                    'page' => $page,
                ], '/products')
            );

            if (empty($response['success']) || !is_array($response['body'])) {
                return $response;
            }

            foreach ($response['body'] as $product) {
                if (
                    !is_array($product) ||
                    empty($product['id']) ||
                    !is_scalar($product['id'])
                ) {
                    return [
                        'success' => false,
                        'status' => absint($response['status'] ?? 0),
                        'message' => 'Shopier returned an invalid product while reading placement scores.',
                    ];
                }

                $score = $product['placementScore'] ?? null;
                if ($score === null || (is_string($score) && trim($score) === '')) {
                    $detail = self::get_product($product['id']);
                    $detail_body = isset($detail['body']['product']) &&
                        is_array($detail['body']['product'])
                        ? $detail['body']['product']
                        : ($detail['body'] ?? null);
                    if (empty($detail['success']) || !is_array($detail_body)) {
                        return [
                            'success' => false,
                            'status' => absint($detail['status'] ?? 0),
                            'message' => 'Shopier product details could not be read while calculating placement scores.',
                        ];
                    }
                    $score = $detail_body['placementScore'] ?? null;
                }

                if ($score === null || (is_string($score) && trim($score) === '')) {
                    continue;
                }

                if (
                    !is_scalar($score) ||
                    !is_numeric($score) ||
                    floor((float) $score) !== (float) $score ||
                    (float) $score < 2 ||
                    (float) $score > PHP_INT_MAX
                ) {
                    return [
                        'success' => false,
                        'status' => absint($response['status'] ?? 0),
                        'message' => 'Shopier returned an invalid placement score for an existing product.',
                    ];
                }

                $max_score = max($max_score, (int) $score);
            }

            if (count($response['body']) < 50) {
                break;
            }

            if ($page === 20) {
                return [
                    'success' => false,
                    'status' => absint($response['status'] ?? 0),
                    'message' => 'Shopier product pagination exceeded the supported safety limit.',
                ];
            }
        }

        if ($max_score >= PHP_INT_MAX) {
            return [
                'success' => false,
                'message' => 'Shopier placement score cannot be incremented safely.',
            ];
        }

        return [
            'success' => true,
            'placement_score' => $max_score + 1,
        ];
    }

    public static function get_global_variation_catalog()
    {
        $response = self::request('GET', '/variations');

        if (empty($response['success']) || !is_array($response['body'])) {
            return $response;
        }

        $groups = [];

        foreach ($response['body'] as $group) {
            if (
                !is_array($group) ||
                empty($group['id']) ||
                empty($group['title']) ||
                !is_scalar($group['id']) ||
                !is_scalar($group['title'])
            ) {
                return [
                    'success' => false,
                    'message' => 'Shopier returned an invalid global variation group.',
                ];
            }

            $group_id = trim((string) $group['id']);
            $group_title = sanitize_text_field((string) $group['title']);
            if (strlen($group_id) > 100 || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $group_id)) {
                return [
                    'success' => false,
                    'message' => 'Shopier returned an invalid global variation ID.',
                ];
            }
            if (isset($groups[$group_id])) {
                return [
                    'success' => false,
                    'message' => 'Shopier returned a duplicate global variation ID.',
                ];
            }
            if ($group_title === '' || strlen($group_title) > 255) {
                return [
                    'success' => false,
                    'message' => 'Shopier returned an invalid global variation title.',
                ];
            }

            $groups[$group_id] = [
                'id' => $group_id,
                'title' => $group_title,
                'selections' => [],
            ];
        }

        foreach ($groups as $group_id => &$group) {
            for ($page = 1; $page <= 100; $page++) {
                $path = add_query_arg([
                    'limit' => 50,
                    'page' => $page,
                    'variationId' => [$group_id],
                ], '/selections');
                $selection_response = self::request('GET', $path);

                if (
                    empty($selection_response['success']) ||
                    !is_array($selection_response['body'])
                ) {
                    unset($group);
                    return $selection_response;
                }

                foreach ($selection_response['body'] as $selection) {
                    if (
                        !is_array($selection) ||
                        empty($selection['id']) ||
                        empty($selection['title']) ||
                        !is_scalar($selection['id']) ||
                        !is_scalar($selection['title']) ||
                        !isset($selection['variationId']) ||
                        !is_scalar($selection['variationId']) ||
                        (string) ($selection['variationId'] ?? '') !== $group_id
                    ) {
                        unset($group);
                        return [
                            'success' => false,
                            'message' => 'Shopier returned an invalid selection or a selection for another variation group.',
                        ];
                    }

                    $selection_id = trim((string) $selection['id']);
                    if (
                        strlen($selection_id) > 100 ||
                        !preg_match('/\A[a-zA-Z0-9_-]+\z/', $selection_id)
                    ) {
                        unset($group);
                        return [
                            'success' => false,
                            'message' => 'Shopier returned an invalid selection ID.',
                        ];
                    }

                    $selection_title = sanitize_text_field(
                        (string) $selection['title']
                    );
                    if ($selection_title === '' || strlen($selection_title) > 255) {
                        unset($group);
                        return [
                            'success' => false,
                            'message' => 'Shopier returned an invalid selection title.',
                        ];
                    }
                    if (
                        isset($group['selections'][$selection_id]) &&
                        $group['selections'][$selection_id] !== $selection_title
                    ) {
                        unset($group);
                        return [
                            'success' => false,
                            'message' => 'Shopier returned conflicting titles for the same selection ID.',
                        ];
                    }
                    $group['selections'][$selection_id] = $selection_title;
                }

                if (count($selection_response['body']) < 50) {
                    break;
                }

                if ($page === 100) {
                    unset($group);
                    return [
                        'success' => false,
                        'message' => 'Shopier selection pagination exceeded the supported safety limit.',
                    ];
                }
            }
        }
        unset($group);

        return [
            'success' => true,
            'body' => array_values($groups),
        ];
    }

    public static function sync_product($product_id)
    {
        return [
            'success' => false,
            'message' => 'Product writes are disabled until required product settings and ID-based variation mappings are implemented.',
        ];
    }
}

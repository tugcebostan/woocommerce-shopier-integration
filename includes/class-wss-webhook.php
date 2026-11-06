
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
    private static function verify_signature($payload, $signature)
    {
        if (
            !defined('SHOPIER_WEBHOOK_TOKEN') ||
            empty(SHOPIER_WEBHOOK_TOKEN) ||
            empty($signature)
        ) {
            return false;
        }

        $expected_signature = hash_hmac(
            'sha256',
            $payload,
            SHOPIER_WEBHOOK_TOKEN,
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
        if (!self::verify_signature($payload, $signature)) {

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
        WSS_Logger::log(
            'webhook',
            'Webhook payload structure inspected.',
            'INFO',
            [
                'event' => $event ?: 'unknown',
                'status' => 'structure_inspected',
                'payload_structure' => self::describe_payload($data),
            ]
        );

        // Event processing will be added in the next stage.

        return new WP_REST_Response([
            'success'    => true,
            'message'    => 'Webhook signature verified.',
            'event'      => $event,
            'account_id' => $account_id,
        ], 200);
    }
}

// Initialize webhook handler.
WSS_Webhook::init();

<?php
/**
 * Plugin Name: WooCommerce Shopier Sync
 * Description: Shopier and WooCommerce integration.
 * Version: 1.3.0
 * Author: Zevrak
 */

if (!defined('ABSPATH')) {
    exit;
}

$wss_plugin_path = plugin_dir_path(__FILE__);

require_once $wss_plugin_path . 'includes/class-wss-logger.php';
require_once $wss_plugin_path . 'includes/class-wss-webhook.php';
require_once $wss_plugin_path . 'includes/class-wss-database.php';
require_once $wss_plugin_path . 'includes/class-wss-attribute-map.php';
require_once $wss_plugin_path . 'includes/class-wss-variation-map.php';
require_once $wss_plugin_path . 'includes/class-wss-queue.php';
require_once $wss_plugin_path . 'includes/class-wss-shopier-api.php';
require_once $wss_plugin_path . 'includes/class-wss-product-sync.php';
require_once $wss_plugin_path . 'includes/class-wss-admin.php';

WSS_Queue::init();
WSS_Admin::init();

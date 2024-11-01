<?php
/*
Plugin Name: Shiplee plug-in for WooCommerce
Plugin URI: https://shiplee.com/de-woocommerce-shiplee-plug-in-voor-jouw-webshop/
Description: A WooCommerce plug-in enabling you to create Shiplee shipment labels directly from the WooCommerce Orders section.
Version: 1.0
Author: Shiplee
Author URI: https://shiplee.com/
License: GPLv2 or later
*/

defined( 'ABSPATH' ) || exit;

require_once 'classes/class-shiplee-main.php';

if( ! defined( 'SHIPLEE_PLUGIN_URL' ) ) {
    define( 'SHIPLEE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if( ! defined( 'SHIPLEE_PLUGIN_ROOT_PATH' ) ) {
    define( 'SHIPLEE_PLUGIN_ROOT_PATH', __DIR__ . '/');
}

if( ! defined( 'SHIPLEE_CALLBACK_URL' ) ) {
    define( 'SHIPLEE_CALLBACK_URL', get_site_url() . '/index.php?shiplee-woocommerce-callback=true' );
}

if( ! defined( 'SHIPLEE_CALLBACK_QUERY_VAR' ) ) {
    define( 'SHIPLEE_CALLBACK_QUERY_VAR', 'shiplee-woocommerce-callback' );
}

register_activation_hook( __FILE__, array('Shiplee_Main', 'activate' ));

add_action( 'woocommerce_shipping_init', array('Shiplee_Main', 'init' ));
add_filter( 'woocommerce_shipping_methods', array('Shiplee_Main', 'add_shipping_method' ));
add_action( 'woocommerce_admin_order_items_after_shipping', array('Shiplee_Main', 'order_items_after_shipping' ));
add_action( 'init', array('Shiplee_Main', 'wp_init' ));
add_filter( 'manage_shop_order_posts_custom_column', array('Shiplee_Main', 'shop_order_columns'), 20 );
add_action( 'admin_enqueue_scripts', array('Shiplee_Main', 'enqueue_scripts' ));
add_action( 'admin_footer', array('Shiplee_Main', 'admin_footer'), 99 );
add_action( 'wp_ajax_create_label', array('Shiplee_Main', 'ajax_create_label' ));
add_action( 'wp_ajax_get_product_availability', array('Shiplee_Main', 'ajax_get_product_availability' ));
add_action( 'template_include', array('Shiplee_Main', 'template_include' ));
add_action( 'query_vars', array('Shiplee_Main', 'rewrite_query_vars' ));

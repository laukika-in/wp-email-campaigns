<?php
/**
 * Plugin Name: WP Email Campaigns
 * Description: Simple contact lists, imports, and email campaigns manager.
 * Version: 2.1.0
 * Author: AniBytes
 * Text Domain: wp-email-campaigns
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Core constants
define( 'WPEC_VERSION', '2.1.0' );
define( 'WPEC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPEC_URL', plugin_dir_url( __FILE__ ) );

// Load helpers first (defines DB helpers, caps, etc.)
require_once WPEC_DIR . 'includes/helpers.php';

// Load plugin orchestrator (kept minimal)
require_once WPEC_DIR . 'includes/class-plugin.php';

// Bootstrap
add_action( 'plugins_loaded', function () {
    // Initialize the plugin (register CPTs, hooks, screens, etc.)
    if ( class_exists( '\\WPEC\\Plugin' ) ) {
        $plugin = new \WPEC\Plugin();
        $plugin->init();
    }
} );

// Activation/Deactivation (optional: for DB tables or scheduled events)
if ( class_exists( '\\WPEC\\Plugin' ) ) {
    register_activation_hook( __FILE__, [ '\\WPEC\\Plugin', 'activate' ] );
    register_deactivation_hook( __FILE__, [ '\\WPEC\\Plugin', 'deactivate' ] );
}

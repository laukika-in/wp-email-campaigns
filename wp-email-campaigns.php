<?php
/**
 * Plugin Name:       WP Email Campaigns
 * Description:       Transactional email campaigns via CPT with Excel/CSV import, Action Scheduler (1 email/3s), contacts, and reporting.
 * Version:           1.2.1
 * Author:            Anirudh
 * Text Domain:       wp-email-campaigns
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$plugin_data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
define( 'WPEC_VER', $plugin_data['Version'] );
define( 'WPEC_FILE', __FILE__ );
define( 'WPEC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPEC_URL', plugin_dir_url( __FILE__ ) );

// Composer autoload (for Action Scheduler & PhpSpreadsheet)
$autoload = WPEC_DIR . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require_once $autoload;
} else {
    // Soft notice so admin remembers to run composer install.
    add_action( 'admin_notices', function() {
        if ( current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-warning"><p><strong>WP Email Campaigns:</strong> Missing <code>vendor/</code> autoloader. Run <code>composer install</code> inside the plugin to enable Excel parsing and background queue.</p></div>';
        }
    } );
}

// Bootstrap
require_once WPEC_DIR . 'includes/helpers.php';
require_once WPEC_DIR . 'includes/class-activator.php';
require_once WPEC_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, [ 'WPEC\\Activator', 'activate' ] );
register_uninstall_hook( __FILE__, 'wpec_uninstall' );
function wpec_uninstall() {
    // Intentionally non-destructive by default. Keep data.
}

add_action( 'plugins_loaded', function() {
    // Ensure Action Scheduler exists
    if ( ! function_exists( 'as_schedule_single_action' ) ) {
        // Action Scheduler not loaded yet. If WooCommerce or separate plugin provides it,
        // it will load on init. Otherwise, we rely on composer-installed lib.
    }
    ( new WPEC\Plugin() )->init();
} );

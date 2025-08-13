<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Plugin {
    public function init() {
        // Load all classes we need on admin
        if ( is_admin() ) {
            require_once WPEC_DIR . 'includes/class-contacts.php';
            require_once WPEC_DIR . 'includes/class-logs-table.php';
            require_once WPEC_DIR . 'includes/class-lists-table.php';
            require_once WPEC_DIR . 'includes/class-list-items-table.php';
            require_once WPEC_DIR . 'includes/class-duplicates-table.php';

            // Spin up Contacts UI
            $contacts = new Contacts();
            $contacts->init();
        }

        // If you have CPTs or other shared hooks, add them here.
        // add_action('init', [$this, 'register_post_types']);
    }

    public static function activate() {
        // Placeholder for any activation logic (e.g., create tables, caps)
        // Make sure capabilities exist
        if ( function_exists( 'add_role' ) ) {
            // No-op for now
        }
    }

    public static function deactivate() {
        // Placeholder for cleanup if needed
    }
}

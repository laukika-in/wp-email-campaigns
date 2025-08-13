<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-cpt.php';
require_once __DIR__ . '/class-uploader.php';
require_once __DIR__ . '/class-scheduler.php';
require_once __DIR__ . '/class-sender.php';
require_once __DIR__ . '/class-webhooks.php';
require_once __DIR__ . '/class-contacts.php';

class Plugin {
    public function init() {
        ( new CPT )->init();
        ( new Uploader )->init();
        ( new Scheduler )->init();
        ( new Sender )->init();
        ( new Webhooks )->init();
        ( new Contacts )->init();

        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
    }

    public function admin_assets( $hook ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $should = false;

        // Load CSS/JS on campaign CPT screens and Contacts page
        if ( $screen ) {
            if ( isset( $screen->post_type ) && $screen->post_type === 'email_campaign' ) {
                $should = true;
            }
            $id = (string) $screen->id;
            if ( strpos( $id, 'wpec-contacts' ) !== false ) {
                $should = true;
            }
        }

        if ( isset( $_GET['post_type'], $_GET['page'] ) 
            && $_GET['post_type'] === 'email_campaign' 
            && $_GET['page'] === 'wpec-contacts' ) {
            $should = true;
        }

        if ( ! $should ) return;

        wp_enqueue_style( 'wpec-admin', WPEC_URL . 'admin/admin.css', [], WPEC_VER );
        wp_enqueue_script( 'wpec-admin', WPEC_URL . 'admin/admin.js', [ 'jquery' ], WPEC_VER, true );

        $start_import = isset( $_GET['wpec_start_import'] ) ? (int) $_GET['wpec_start_import'] : 0;

        wp_localize_script( 'wpec-admin', 'WPEC', [
            'nonce'       => wp_create_nonce( 'wpec_admin' ),
            'rest'        => esc_url_raw( rest_url( 'email-campaigns/v1' ) ),
            'startImport' => $start_import,
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        ] );
    }
}

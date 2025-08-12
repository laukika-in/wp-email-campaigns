<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-cpt.php';
require_once __DIR__ . '/class-uploader.php'; // remains, but not used for campaigns anymore
require_once __DIR__ . '/class-scheduler.php';
require_once __DIR__ . '/class-sender.php';
require_once __DIR__ . '/class-webhooks.php';
require_once __DIR__ . '/class-contacts.php'; // this now manages Lists & Contacts

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
        $screen = get_current_screen();
        if ( ! $screen ) return;
        if ( in_array( $screen->id, [ 'edit-email_campaign', 'email_campaign', 'email-campaign_page_wpec-contacts' ], true ) ) {
            wp_enqueue_style( 'wpec-admin', WPEC_URL . 'admin/admin.css', [], WPEC_VER );
            wp_enqueue_script( 'wpec-admin', WPEC_URL . 'admin/admin.js', [ 'jquery' ], WPEC_VER, true );
            wp_localize_script( 'wpec-admin', 'WPEC', [
                'nonce' => wp_create_nonce( 'wpec_admin' ),
                'rest'  => esc_url_raw( rest_url( 'email-campaigns/v1' ) ),
            ] );
        }
    }
}

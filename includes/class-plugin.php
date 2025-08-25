<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-cpt.php';
require_once __DIR__ . '/class-uploader.php'; // still present; safe to keep
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
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    $is_campaign_edit  = false; // single campaign editor (post.php / post-new.php)
    $is_contacts_suite = false; // Lists, All Contacts, Import, Duplicates

    if ( $screen ) {
        $pt = isset($screen->post_type) ? (string) $screen->post_type : '';
        $id = (string) $screen->id;
        $pg = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

        // Campaign edit screens only
        if ( $pt === 'email_campaign' && in_array($hook, ['post.php','post-new.php'], true) ) {
            $is_campaign_edit = true;
        }

        // Our router pages (new slugs)
        if ( in_array($pg, ['wpec-lists','wpec-contacts','wpec-import','wpec-duplicates'], true) ) {
            $is_contacts_suite = true;
        }
        // Fallback: some builds embed slug in screen->id
        if (
            strpos($id, 'wpec-lists')      !== false ||
            strpos($id, 'wpec-contacts')   !== false ||
            strpos($id, 'wpec-import')     !== false ||
            strpos($id, 'wpec-duplicates') !== false
        ) {
            $is_contacts_suite = true;
        }
    }

    if ( ! $is_campaign_edit && ! $is_contacts_suite ) {
        return;
    }

    // Shared CSS + hide WP footer on our pages
    wp_enqueue_style( 'wpec-admin', WPEC_URL . 'admin/admin.css', [], WPEC_VER );
    wp_add_inline_style( 'wpec-admin', '#wpfooter{display:none !important;}' );

    // Shared data
    $common = [
        'nonce'      => wp_create_nonce( 'wpec_admin' ),
        'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
        'adminBase'  => admin_url( 'edit.php' ),
        // Used by linkification (Lists column)
        'listViewBase' => add_query_arg(
            ['post_type'=>'email_campaign','page'=>'wpec-lists','view'=>'list','list_id'=>''],
            admin_url('edit.php')
        ),
    ];

    if ( $is_contacts_suite ) {
        // Contacts/Lists/etc keep using admin.js
        wp_enqueue_script( 'wpec-admin', WPEC_URL . 'admin/admin.js', [ 'jquery' ], WPEC_VER, true );
        $contacts_loc = $common + [
            'rest'        => esc_url_raw( rest_url( 'email-campaigns/v1' ) ),
            'startImport' => isset($_GET['wpec_start_import']) ? (int) $_GET['wpec_start_import'] : 0,
        ];
        wp_localize_script( 'wpec-admin', 'WPEC', $contacts_loc );
    }

    if ( $is_campaign_edit ) {
        // NEW: campaigns-only JS (keep contacts JS off)
        wp_enqueue_script( 'wpec-campaigns', WPEC_URL . 'admin/campaigns.js', [ 'jquery' ], WPEC_VER, true );
        wp_localize_script( 'wpec-campaigns', 'WPEC', $common );
    }
}

}

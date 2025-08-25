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

        $is_campaign_edit  = false; // post.php / post-new.php for email_campaign
        $is_campaign_tool  = false; // our extra campaign tools pages (wpec-send, later scheduler, etc.)
        $is_contacts_suite = false; // Lists/Contacts/Import/Duplicates

        $pg = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        $pt = ($screen && isset($screen->post_type)) ? (string) $screen->post_type : '';
        $id = $screen ? (string) $screen->id : '';

        // Campaign post editor screens
        if ( $pt === 'email_campaign' && in_array($hook, ['post.php','post-new.php'], true) ) {
            $is_campaign_edit = true;
        }

        // Our campaign tools (include the new Send page)
        if ( in_array($pg, ['wpec-send'], true) ) {
            $is_campaign_tool = true;
        }

        // Lists / All Contacts / Import / Duplicates
        if (
            in_array($pg, ['wpec-lists','wpec-contacts','wpec-import','wpec-duplicates'], true) ||
            strpos($id, 'wpec-lists')      !== false ||
            strpos($id, 'wpec-contacts')   !== false ||
            strpos($id, 'wpec-import')     !== false ||
            strpos($id, 'wpec-duplicates') !== false
        ) {
            $is_contacts_suite = true;
        }

        // If none of our screens, bail.
        if ( ! ($is_campaign_edit || $is_campaign_tool || $is_contacts_suite) ) {
            return;
        }

        // Shared CSS + hide WP footer on our pages
        wp_enqueue_style( 'wpec-admin', WPEC_URL . 'admin/admin.css', [], WPEC_VER );
        wp_add_inline_style( 'wpec-admin', '#wpfooter{display:none !important;}' );

        $common = [
            'nonce'        => wp_create_nonce( 'wpec_admin' ),
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'adminBase'    => admin_url( 'edit.php' ),
            'listViewBase' => add_query_arg(
                ['post_type'=>'email_campaign','page'=>'wpec-lists','view'=>'list','list_id'=>''],
                admin_url('edit.php')
            ),
        ];

        // Contacts suite uses admin.js (Lists / All Contacts / Import / Duplicates)
        if ( $is_contacts_suite ) {
            wp_enqueue_script( 'wpec-admin', WPEC_URL . 'admin/admin.js', [ 'jquery' ], WPEC_VER, true );
            wp_localize_script( 'wpec-admin', 'WPEC', $common + [
                'rest'        => esc_url_raw( rest_url( 'email-campaigns/v1' ) ),
                'startImport' => isset($_GET['wpec_start_import']) ? (int) $_GET['wpec_start_import'] : 0,
            ] );
        }

        // Campaign tools (Send screen) use campaigns.js
        if ( $is_campaign_edit || $is_campaign_tool ) {
            wp_enqueue_script( 'wpec-campaigns', WPEC_URL . 'admin/campaigns.js', [ 'jquery' ], WPEC_VER, true );
            // IMPORTANT: campaigns.js expects WPECCAMPAIGN (not WPEC)
            wp_localize_script( 'wpec-campaigns', 'WPECCAMPAIGN', [
                'nonce'   => wp_create_nonce( 'wpec_admin' ),
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            ] );
        }
    }


}

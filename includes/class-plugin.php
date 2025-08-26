<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-cpt.php';
require_once __DIR__ . '/class-uploader.php'; // still present; safe to keep
require_once __DIR__ . '/class-scheduler.php';
require_once __DIR__ . '/class-sender.php';
require_once __DIR__ . '/class-webhooks.php';
require_once __DIR__ . '/class-contacts.php'; 
require_once __DIR__ . '/class-campaigns.php';
require_once __DIR__ . '/class-queue.php';

class Plugin {
    public function init() {
        ( new CPT )->init();
        ( new Uploader )->init();
        ( new Scheduler )->init();
        ( new Sender )->init();
        ( new Webhooks )->init();
        ( new Contacts )->init(); 
        (new Campaigns)->init();
        (new Queue)->init();

        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
    }
    
public function admin_assets( $hook ) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    $is_campaign_edit  = false; // post.php / post-new.php for email_campaign
    $is_campaign_tool  = false; // our “Send” screen
    $is_contacts_suite = false; // Lists/Contacts/Import/Duplicates

    $pg = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
    $pt = ($screen && isset($screen->post_type)) ? (string) $screen->post_type : '';
    $id = $screen ? (string) $screen->id : '';

    $is_history = in_array($pg, ['wpec-campaigns'], true);
    $is_queue   = in_array($pg, ['wpec-queue'], true);


    if ( $pt === 'email_campaign' && in_array($hook, ['post.php','post-new.php'], true) ) {
        $is_campaign_edit = true;
    }
    if ( in_array($pg, ['wpec-send'], true) ) {
        $is_campaign_tool = true;
    }
    if (
        in_array($pg, ['wpec-lists','wpec-contacts','wpec-import','wpec-duplicates'], true) ||
        strpos($id, 'wpec-lists')      !== false ||
        strpos($id, 'wpec-contacts')   !== false ||
        strpos($id, 'wpec-import')     !== false ||
        strpos($id, 'wpec-duplicates') !== false
    ) {
        $is_contacts_suite = true;
    }

    if ( ! ($is_campaign_edit || $is_campaign_tool || $is_contacts_suite) ) {
        return;
    }

    // Styles (shared) + hide WP footer on our pages
    wp_enqueue_style( 'wpec-admin', WPEC_URL . 'admin/admin.css', [], WPEC_VER );
    wp_add_inline_style( 'wpec-admin', '#wpfooter{display:none !important;}' );

    // --- NEW: Select2 URLs (local first, with CDN fallback used in JS) ---
    $select2_cdn_css   = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css';
    $select2_cdn_js    = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js';

    $common = [
        'nonce'        => wp_create_nonce( 'wpec_admin' ),
        'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
        'adminBase'    => admin_url( 'edit.php' ),
        'listViewBase' => add_query_arg(
            ['post_type'=>'email_campaign','page'=>'wpec-lists','view'=>'list','list_id'=>''],
            admin_url('edit.php')
        ),
        // expose local + CDN to JS 
        'select2CdnCss'   => $select2_cdn_css,
        'select2CdnJs'    => $select2_cdn_js,
    ];

    // Contacts suite → admin.js (uses WPEC + ensureSelect2)
    if ( $is_contacts_suite ) {
        wp_enqueue_script( 'wpec-admin', WPEC_URL . 'admin/admin.js', [ 'jquery' ], WPEC_VER, true );
        wp_localize_script( 'wpec-admin', 'WPEC', array_merge( $common, [
            'rest'        => esc_url_raw( rest_url( 'email-campaigns/v1' ) ),
            'startImport' => isset($_GET['wpec_start_import']) ? (int) $_GET['wpec_start_import'] : 0,
        ] ) );
    }

    // Campaign tools (Send screen) → campaigns.js (uses WPECCAMPAIGN + ensureSelect2)
    if ( $is_campaign_edit || $is_campaign_tool ) {
        wp_enqueue_script( 'wpec-campaigns', WPEC_URL . 'admin/campaigns.js', [ 'jquery' ], WPEC_VER, true );
        wp_localize_script( 'wpec-campaigns', 'WPECCAMPAIGN', array_merge( $common, [
            // keep any extras specific to this screen here
            'nonce'            => wp_create_nonce( 'wpec_admin' ),
        'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
        ] ) );
    }
    if ( $is_history ) {
    wp_enqueue_script('wpec-history', WPEC_URL.'admin/history.js', ['jquery'], WPEC_VER, true);
    wp_localize_script('wpec-history', 'WPECHISTORY', [
        'nonce'   => wp_create_nonce('wpec_admin'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ]);
    }

    if ( $is_queue ) {
        wp_enqueue_script('wpec-queue', WPEC_URL.'admin/queue.js', ['jquery'], WPEC_VER, true);
        wp_localize_script('wpec-queue', 'WPECQUEUE', array_merge( $common, [
            'nonce'   => wp_create_nonce('wpec_admin'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
    ] ) );
    }

}



}

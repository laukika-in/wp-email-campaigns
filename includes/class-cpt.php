<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CPT {
    const POST_TYPE = 'email_campaign';

    public function init() {
        add_action( 'init', [ $this, 'register' ] );
        add_action( 'add_meta_boxes', [ $this, 'metaboxes' ] );
        add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_meta' ], 10, 3 );
        add_filter( 'manage_edit-' . self::POST_TYPE . '_columns', [ $this, 'columns' ] );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'column_content' ], 10, 2 );
        add_filter( 'post_updated_messages', [ $this, 'messages' ] );
        // ADD inside CPT->init() (or your constructor) — once. 
        add_action( 'save_post_email_campaign', [ $this, 'save_campaign_meta' ] );
add_action('admin_menu', [ $this, 'register' ]);
       

    }

    public function register() {
    // register_post_type( self::POST_TYPE, [
    //     'labels' => [
    //         'name'          => __( 'Email Campaigns', 'wp-email-campaigns' ),
    //         'singular_name' => __( 'Email Campaign', 'wp-email-campaigns' ),
    //     ],
    //     'public'          => true,
    //     'show_ui'         => true,
    //     'show_in_menu'    => true,          // keep CPT menu
    //     'menu_icon'       => 'dashicons-email',
    //     'supports'        => [ 'title', 'editor' ],
    //     'capability_type' => 'post',
    // ] );
 
   
    $cap = method_exists(Helpers::class,'manage_cap') ? Helpers::manage_cap() : 'manage_options';

    add_menu_page(
        __( 'Lists','wp-email-campaigns' ),
        __( 'Lists','wp-email-campaigns' ),
        $cap,
        'wpec-lists',
        [ $this, 'render_contacts_page' ],
        'dashicons-list-view',
        25
    );
}


    public function render_contacts_page() {
   
        do_action( 'wpec_render_contacts_table' ); // Implemented in class-contacts.php (now List manager)
        echo '</div>';
    }

    public function metaboxes() {
        add_meta_box( 'wpec_settings', __( 'Email Settings', 'wp-email-campaigns' ), [ $this, 'mb_settings' ], self::POST_TYPE, 'normal', 'high' );
        add_meta_box( 'wpec_controls', __( 'Campaign Controls', 'wp-email-campaigns' ), [ $this, 'mb_controls' ], self::POST_TYPE, 'side', 'high' );
        add_meta_box( 'wpec_stats', __( 'Campaign Progress', 'wp-email-campaigns' ), [ $this, 'mb_stats' ], self::POST_TYPE, 'normal', 'default' );

        // NEW: select uploaded lists (multi-select)
        add_meta_box( 'wpec_lists', __( 'Recipient Lists', 'wp-email-campaigns' ), [ $this, 'mb_lists' ], self::POST_TYPE, 'side', 'default' );
    }

    public function mb_settings( $post ) {
        wp_nonce_field( 'wpec_save', 'wpec_nonce' );
        $subject   = get_post_meta( $post->ID, '_wpec_subject', true );
        $preheader = get_post_meta( $post->ID, '_wpec_preheader', true );
        echo '<p><label><strong>' . esc_html__( 'Email Subject', 'wp-email-campaigns' ) . '</strong><br/>';
        echo '<input type="text" name="wpec_subject" class="widefat" value="' . esc_attr( $subject ) . '"></label></p>';
        echo '<p><label><strong>' . esc_html__( 'Preheader (optional)', 'wp-email-campaigns' ) . '</strong><br/>';
        echo '<input type="text" name="wpec_preheader" class="widefat" value="' . esc_attr( $preheader ) . '"></label></p>';

        // FILE UPLOAD REMOVED from here by design.
        echo '<p class="description">' . esc_html__( 'Compose your HTML email above. Upload contacts in Contacts → Lists.', 'wp-email-campaigns' ) . '</p>';
    }

    public function mb_controls( $post ) {
        $status = get_post_meta( $post->ID, '_wpec_status', true ) ?: 'draft';
        echo '<p><strong>' . esc_html__( 'Status:', 'wp-email-campaigns' ) . '</strong> ' . esc_html( ucfirst( $status ) ) . '</p>';
        echo '<button type="button" class="button button-secondary wpec-pause" data-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Pause', 'wp-email-campaigns' ) . '</button> ';
        echo '<button type="button" class="button button-secondary wpec-resume" data-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Resume', 'wp-email-campaigns' ) . '</button> ';
        echo '<button type="button" class="button button-link-delete wpec-cancel" data-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Cancel', 'wp-email-campaigns' ) . '</button>';
        echo '<p class="description">' . esc_html__( 'Publishing will start sending when configured (next phase).', 'wp-email-campaigns' ) . '</p>';
    }

    public function mb_stats( $post ) {
        global $wpdb;
        $subs = Helpers::table('subs');
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $subs WHERE campaign_id=%d", $post->ID ) );
        $sent  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $subs WHERE campaign_id=%d AND status IN ('sent','bounced')", $post->ID ) );
        $failed= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $subs WHERE campaign_id=%d AND status='failed'", $post->ID ) );
        $pending = $total - $sent - $failed;
        echo '<p>' . sprintf( esc_html__( 'Progress: %1$d / %2$d sent. Pending: %3$d, Failed: %4$d', 'wp-email-campaigns' ),
            $sent, $total, max(0,$pending), $failed ) . '</p>';
        if ( $total > 0 ) {
            $pct = min( 100, round( ( $sent / max(1,$total) ) * 100 ) );
            echo '<div class="wpec-progress"><span style="width:' . esc_attr( $pct ) . '%"></span></div>';
        }
    }

    public function mb_lists( $post ) {
        global $wpdb;
        $lists_table = Helpers::table('lists');
        $lists = $wpdb->get_results( "SELECT id, name, status, imported FROM $lists_table ORDER BY id DESC" );
        $selected = (array) get_post_meta( $post->ID, '_wpec_list_ids', true );
        echo '<p><strong>' . esc_html__( 'Select Lists', 'wp-email-campaigns' ) . '</strong></p>';
        echo '<select name="wpec_list_ids[]" multiple class="widefat" size="6">';
        foreach ( (array) $lists as $l ) {
            $label = sprintf( '%s (%s, %d)', $l->name, ucfirst($l->status), (int)$l->imported );
            printf(
                '<option value="%d" %s>%s</option>',
                (int) $l->id,
                selected( in_array( (string)$l->id, array_map('strval', $selected), true ), true, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Upload lists under Contacts. You can select multiple lists.', 'wp-email-campaigns' ) . '</p>';
    }

    public function columns( $cols ) {
        $cols['wpec_status']   = __( 'Status', 'wp-email-campaigns' );
        $cols['wpec_progress'] = __( 'Progress', 'wp-email-campaigns' );
        return $cols;
    }

    public function column_content( $col, $post_id ) {
        global $wpdb;
        $subs = Helpers::table('subs');
        if ( 'wpec_status' === $col ) {
            $status = get_post_meta( $post_id, '_wpec_status', true ) ?: 'draft';
            echo esc_html( ucfirst( $status ) );
        } elseif ( 'wpec_progress' === $col ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $subs WHERE campaign_id=%d", $post_id ) );
            $sent  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $subs WHERE campaign_id=%d AND status IN ('sent','bounced')", $post_id ) );
            echo esc_html( $sent . '/' . $total );
        }
    }

    public function messages( $messages ) {
        $messages[self::POST_TYPE][6]  = __( 'Campaign updated.', 'wp-email-campaigns' );
        $messages[self::POST_TYPE][1]  = __( 'Campaign updated.', 'wp-email-campaigns' );
        $messages[self::POST_TYPE][10] = __( 'Campaign draft updated.', 'wp-email-campaigns' );
        return $messages;
    }

    public function save_meta( $post_id, $post, $update ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! Helpers::user_can_manage() ) { return; }
        if ( ! isset( $_POST['wpec_nonce'] ) || ! wp_verify_nonce( $_POST['wpec_nonce'], 'wpec_save' ) ) { return; }

        $subject   = isset($_POST['wpec_subject']) ? sanitize_text_field( $_POST['wpec_subject'] ) : '';
        $preheader = isset($_POST['wpec_preheader']) ? sanitize_text_field( $_POST['wpec_preheader'] ) : '';
        update_post_meta( $post_id, '_wpec_subject', $subject );
        update_post_meta( $post_id, '_wpec_preheader', $preheader );

        // Store selected list IDs (used later when we wire sending)
        $list_ids = isset($_POST['wpec_list_ids']) ? array_map('intval', (array) $_POST['wpec_list_ids']) : [];
        update_post_meta( $post_id, '_wpec_list_ids', $list_ids );

        // Do NOT queue sending here yet. We’ll do this in the scheduler phase.
    }

    public function save_campaign_meta( $post_id ) {
        if ( ! isset($_POST['wpec_campaign_meta_nonce']) ) return;
        if ( ! wp_verify_nonce( $_POST['wpec_campaign_meta_nonce'], 'wpec_campaign_meta' ) ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $from_name  = isset($_POST['wpec_from_name']) ? sanitize_text_field( $_POST['wpec_from_name'] ) : '';
        $from_email = isset($_POST['wpec_from_email']) ? sanitize_email( $_POST['wpec_from_email'] ) : '';
        $subject    = isset($_POST['wpec_subject']) ? sanitize_text_field( $_POST['wpec_subject'] ) : '';
        $body_raw   = isset($_POST['wpec_body']) ? (string) $_POST['wpec_body'] : '';

        update_post_meta( $post_id, '_wpec_from_name',  $from_name );
        update_post_meta( $post_id, '_wpec_from_email', $from_email );
        update_post_meta( $post_id, '_wpec_subject',    $subject );
        update_post_meta( $post_id, '_wpec_body_html',  wp_kses_post( $body_raw ) );
    } 

}

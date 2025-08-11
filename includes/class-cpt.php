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
    }

    public function register() {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name' => __( 'Email Campaigns', 'wp-email-campaigns' ),
                'singular_name' => __( 'Email Campaign', 'wp-email-campaigns' ),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-email',
            'supports' => [ 'title', 'editor', 'custom-fields' ],
            'capability_type' => 'post',
        ] );
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __( 'Contacts', 'wp-email-campaigns' ),
            __( 'Contacts', 'wp-email-campaigns' ),
            'manage_options',
            'wpec-contacts',
            [ $this, 'render_contacts_page' ]
        );
    }

    public function render_contacts_page() {
        echo '<div class="wrap"><h1>' . esc_html__( 'Contacts', 'wp-email-campaigns' ) . '</h1>';
        do_action( 'wpec_render_contacts_table' );
        echo '</div>';
    }

    public function metaboxes() {
        add_meta_box( 'wpec_settings', __( 'Email Settings', 'wp-email-campaigns' ), [ $this, 'mb_settings' ], self::POST_TYPE, 'normal', 'high' );
        add_meta_box( 'wpec_controls', __( 'Campaign Controls', 'wp-email-campaigns' ), [ $this, 'mb_controls' ], self::POST_TYPE, 'side', 'high' );
        add_meta_box( 'wpec_stats', __( 'Campaign Progress', 'wp-email-campaigns' ), [ $this, 'mb_stats' ], self::POST_TYPE, 'normal', 'default' );
    }

    public function mb_settings( $post ) {
        wp_nonce_field( 'wpec_save', 'wpec_nonce' );
        $subject   = get_post_meta( $post->ID, '_wpec_subject', true );
        $preheader = get_post_meta( $post->ID, '_wpec_preheader', true );
        echo '<p><label><strong>' . esc_html__( 'Email Subject', 'wp-email-campaigns' ) . '</strong><br/>';
        echo '<input type="text" name="wpec_subject" class="widefat" value="' . esc_attr( $subject ) . '"></label></p>';
        echo '<p><label><strong>' . esc_html__( 'Preheader (optional)', 'wp-email-campaigns' ) . '</strong><br/>';
        echo '<input type="text" name="wpec_preheader" class="widefat" value="' . esc_attr( $preheader ) . '"></label></p>';
        echo '<p><label><strong>' . esc_html__( 'Upload Excel/CSV', 'wp-email-campaigns' ) . '</strong><br/>';
        echo '<input type="file" name="wpec_upload" accept=".csv,.xlsx"></label></p>';
        echo '<p class="description">' . esc_html__( 'Format: Column A = Email (required), Column B = First Name (optional).', 'wp-email-campaigns' ) . '</p>';
    }

    public function mb_controls( $post ) {
        $status = get_post_meta( $post->ID, '_wpec_status', true ) ?: 'draft';
        echo '<p><strong>' . esc_html__( 'Status:', 'wp-email-campaigns' ) . '</strong> ' . esc_html( ucfirst( $status ) ) . '</p>';
        echo '<button type="button" class="button button-secondary wpec-pause" data-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Pause', 'wp-email-campaigns' ) . '</button> ';
        echo '<button type="button" class="button button-secondary wpec-resume" data-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Resume', 'wp-email-campaigns' ) . '</button> ';
        echo '<button type="button" class="button button-link-delete wpec-cancel" data-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Cancel', 'wp-email-campaigns' ) . '</button>';
        echo '<p class="description">' . esc_html__( 'Publishing will ask for confirmation and then queue emails (1/3s).', 'wp-email-campaigns' ) . '</p>';
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

    public function columns( $cols ) {
        $cols['wpec_status'] = __( 'Status', 'wp-email-campaigns' );
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
        $messages[self::POST_TYPE][6] = __( 'Campaign updated.', 'wp-email-campaigns' );
        $messages[self::POST_TYPE][1] = __( 'Campaign updated.', 'wp-email-campaigns' );
        $messages[self::POST_TYPE][10]= __( 'Campaign draft updated.', 'wp-email-campaigns' );
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

        // Handle upload
        if ( ! empty( $_FILES['wpec_upload']['name'] ) && ! empty( $_FILES['wpec_upload']['tmp_name'] ) ) {
            ( new Uploader )->handle_upload_for_campaign( $post_id, $_FILES['wpec_upload'] );
        }

        // Mark status if publishing
        if ( 'publish' === $post->post_status && ( ! $update || ( $update && isset($_POST['publish']) ) ) ) {
            // Only set to queued on first publish
            update_post_meta( $post_id, '_wpec_status', 'queued' );
            ( new Scheduler )->schedule_campaign( $post_id );
        }
    }
}

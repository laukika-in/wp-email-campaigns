<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Helpers {
    public static function db() {
        global $wpdb;
        return $wpdb;
    }

    public static function table( $key ) {
        global $wpdb;
        $map = [
            'subs'       => $wpdb->prefix . 'email_campaigns_subscribers',
            'contacts'   => $wpdb->prefix . 'email_contacts',
            'logs'       => $wpdb->prefix . 'email_campaigns_logs',
            // NEW:
            'lists'      => $wpdb->prefix . 'email_lists',
            'list_items' => $wpdb->prefix . 'email_list_items',
        ];
        return $map[ $key ] ?? '';
    }

    public static function now() {
        return current_time( 'mysql', 1 );
    }

    public static function sanitize_email_name( $email, $name ) {
        $email = is_email( $email ) ? $email : '';
        $name  = sanitize_text_field( $name );
        return [ $email, $name ];
    }

    public static function replace_tokens( $text, $data = [] ) {
        $replacements = [
            '{FIRST_NAME}' => $data['first_name'] ?? $data['name'] ?? '',
        ];
        return strtr( $text, $replacements );
    }

    public static function render_preheader( $preheader ) {
        if ( ! $preheader ) return '';
        $preheader = esc_html( $preheader );
        return '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;visibility:hidden;">' . $preheader . str_repeat('&nbsp;', 50) . '</div>';
    }

    public static function campaign_group( $campaign_id ) {
        return 'wpec_campaign_' . absint( $campaign_id );
    }

    public static function user_can_manage() {
        return current_user_can( 'manage_options' );
    }

    // File helpers for large CSV imports
    public static function uploads_dir() {
        $u = wp_upload_dir();
        return trailingslashit( $u['basedir'] ) . 'wpec/';
    }

    public static function ensure_uploads_dir() {
        $dir = self::uploads_dir();
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir;
    }
}

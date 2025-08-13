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
            'lists'      => $wpdb->prefix . 'email_lists',
            'list_items' => $wpdb->prefix . 'email_list_items',
            'dupes'      => $wpdb->prefix . 'email_import_duplicates',
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
  public static function manage_cap(): string {
        return apply_filters('wpec_manage_cap', 'manage_options'); // change if you use something else
    }

    public static function user_can_manage(): bool {
        return current_user_can( self::manage_cap() );
    }

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

 
    // ---------- Header mapping for imports ----------
    public static function norm_header( $str ) {
        $str = trim( strtolower( $str ) );
        $str = preg_replace( '/[^a-z0-9]+/', '_', $str );
        return trim( $str, '_' );
    }

    public static function header_aliases() {
        // map normalized header -> canonical field
        return [
            'first_name' => 'first_name',
            'firstname' => 'first_name',
            'given_name' => 'first_name',
            'last_name' => 'last_name',
            'lastname' => 'last_name',
            'surname' => 'last_name',
            'email' => 'email',
            'email_address' => 'email',
            'company_name' => 'company_name',
            'company' => 'company_name',
            'company_number_of_employees' => 'company_employees',
            'employees' => 'company_employees',
            'company_employees' => 'company_employees',
            'company_annual_revenue' => 'company_annual_revenue',
            'annual_revenue' => 'company_annual_revenue',
            'revenue' => 'company_annual_revenue',
            'contact_number' => 'contact_number',
            'phone' => 'contact_number',
            'mobile' => 'contact_number',
            'job_title' => 'job_title',
            'title' => 'job_title',
            'industry' => 'industry',
            'country' => 'country',
            'state' => 'state',
            'region' => 'state',
            'city' => 'city',
            'postal_code' => 'postal_code',
            'zip' => 'postal_code',
            'zipcode' => 'postal_code',
            'pin' => 'postal_code',
        ];
    }

    public static function parse_header_map( $header_row ) {
        $aliases = self::header_aliases();
        $map = [];
        foreach ( $header_row as $idx => $col ) {
            $norm = self::norm_header( $col );
            if ( isset( $aliases[ $norm ] ) ) {
                $map[ $aliases[ $norm ] ] = $idx;
            }
        }
        return $map; // e.g. ['email'=>0,'first_name'=>1,'last_name'=>2,...]
    }

    public static function required_fields_present( $map ) {
        return isset( $map['email'], $map['first_name'], $map['last_name'] );
    }
}

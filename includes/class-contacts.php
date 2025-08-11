<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( '\WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Contacts {
    public function init() {
        add_action( 'admin_post_wpec_export_contacts', [ $this, 'export_contacts' ] );
        add_action( 'wpec_render_contacts_table', [ $this, 'render_table' ] );
    }

    public function render_table() {
        $table = new Contacts_Table();
        $table->prepare_items();
        echo '<form method="post">';
        $table->search_box( __( 'Search Email', 'wp-email-campaigns' ), 'wpecs' );
        $table->display();
        echo '</form>';

        $export_url = admin_url( 'admin-post.php?action=wpec_export_contacts&_wpnonce=' . wp_create_nonce('wpec_export_contacts') );
        echo '<p><a href="' . esc_url( $export_url ) . '" class="button button-primary">' . esc_html__( 'Export All to CSV', 'wp-email-campaigns' ) . '</a></p>';
    }

    public function export_contacts() {
        if ( ! Helpers::user_can_manage() ) wp_die( 'Denied' );
        check_admin_referer( 'wpec_export_contacts' );
        global $wpdb;
        $table = Helpers::table('contacts');
        $rows = $wpdb->get_results( "SELECT email,name,status,created_at FROM $table ORDER BY id DESC", ARRAY_A );
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename=contacts-' . date('Ymd-His') . '.csv' );
        $out = fopen('php://output', 'w');
        fputcsv( $out, [ 'Email', 'Name', 'Status', 'Created At' ] );
        foreach ( $rows as $r ) {
            fputcsv( $out, $r );
        }
        fclose($out);
        exit;
    }
}

class Contacts_Table extends \WP_List_Table {
    public function get_columns() {
        return [
            'cb'     => '<input type="checkbox" />',
            'email'  => __( 'Email', 'wp-email-campaigns' ),
            'name'   => __( 'Name', 'wp-email-campaigns' ),
            'status' => __( 'Status', 'wp-email-campaigns' ),
            'created_at' => __( 'Created', 'wp-email-campaigns' ),
        ];
    }
    public function prepare_items() {
        global $wpdb;
        $table = Helpers::table('contacts');
        $per_page = 20;
        $paged = max(1, (int)($_GET['paged'] ?? 1));
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

        $where = 'WHERE 1=1';
        $args = [];
        if ( $search ) {
            $where .= " AND (email LIKE %s OR name LIKE %s)";
            $args[] = '%' . $wpdb->esc_like($search) . '%';
            $args[] = '%' . $wpdb->esc_like($search) . '%';
        }
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table $where", $args ) );
        $offset = ( $paged - 1 ) * $per_page;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, [ $per_page, $offset ] ) ), ARRAY_A );

        $this->items = $rows;
        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total / $per_page )
        ] );
    }
    public function column_default( $item, $column_name ) {
        return esc_html( $item[ $column_name ] ?? '' );
    }
    public function column_cb( $item ) {
        return '<input type="checkbox" name="id[]" value="' . (int)$item['id'] . '" />';
    }
}

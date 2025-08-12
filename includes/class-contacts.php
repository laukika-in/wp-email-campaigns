<?php
namespace WPEC;

use PhpOffice\PhpSpreadsheet\IOFactory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( '\WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Contacts {
    const BATCH_SIZE = 2000;

    public function init() {
        add_action( 'wpec_render_contacts_table', [ $this, 'render_lists_screen' ] );

        add_action( 'wp_ajax_wpec_list_upload',   [ $this, 'ajax_list_upload' ] );
        add_action( 'wp_ajax_wpec_list_process',  [ $this, 'ajax_list_process' ] );

        add_action( 'admin_post_wpec_list_upload', [ $this, 'admin_post_list_upload' ] );

        add_action( 'admin_init', [ $this, 'maybe_render_list_items_page' ] );

        add_action( 'admin_post_wpec_export_list', [ $this, 'export_list' ] );
    }

    public function render_lists_screen() {
        if ( isset($_GET['view']) && $_GET['view'] === 'list' ) {
            $this->render_list_items();
            return;
        }

        echo '<div id="wpec-upload-panel" class="wpec-card">';
        echo '<h2>' . esc_html__( 'Upload New List', 'wp-email-campaigns' ) . '</h2>';

        $action_url = esc_url( admin_url( 'admin-post.php' ) );
        echo '<form id="wpec-list-upload-form" method="post" action="' . $action_url . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="wpec_list_upload" />';
        echo '<input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce('wpec_admin') ) . '"/>';

        echo '<p><label><strong>' . esc_html__( 'List Name', 'wp-email-campaigns' ) . '</strong><br/>';
        echo '<input type="text" name="list_name" class="regular-text" required></label></p>';

        echo '<p><label><strong>' . esc_html__( 'CSV or XLSX file', 'wp-email-campaigns' ) . '</strong><br/>';
        echo '<input type="file" name="file" accept=".csv,.xlsx" required></label></p>';

        echo '<p class="description">' . esc_html__( 'Format: Column A = Email (required), Column B = First Name (optional).', 'wp-email-campaigns' ) . '</p>';

        echo '<p><button type="submit" class="button button-primary" id="wpec-upload-btn">' . esc_html__( 'Upload & Import', 'wp-email-campaigns' ) . '</button> ';
        echo '<span class="wpec-loader" style="display:none;"></span></p>';

        echo '<div id="wpec-progress-wrap" style="display:none;"><div class="wpec-progress"><span id="wpec-progress-bar" style="width:0%"></span></div><p id="wpec-progress-text"></p></div>';

        echo '</form></div>';

        $table = new WPEC_Lists_Table();
        $table->prepare_items();
        echo '<h2 style="margin-top:30px;">' . esc_html__( 'Lists', 'wp-email-campaigns' ) . '</h2>';
        echo '<form method="get">';
        echo '<input type="hidden" name="post_type" value="email_campaign" />';
        echo '<input type="hidden" name="page" value="wpec-contacts" />';
        $table->search_box( __( 'Search Lists', 'wp-email-campaigns' ), 'wpecl' );
        $table->display();
        echo '</form>';
    }

    public function maybe_render_list_items_page() {
        if ( ! Helpers::user_can_manage() ) return;
        if ( ! isset($_GET['post_type'], $_GET['page'], $_GET['view']) ) return;
        if ( $_GET['post_type'] !== 'email_campaign' || $_GET['page'] !== 'wpec-contacts' || $_GET['view'] !== 'list' ) return;
        add_action( 'admin_notices', function(){} );
    }

    private function render_list_items() {
        if ( ! Helpers::user_can_manage() ) { wp_die( 'Denied' ); }
        $list_id = isset($_GET['list_id']) ? absint($_GET['list_id']) : 0;
        if ( ! $list_id ) { echo '<div class="notice notice-error"><p>Invalid list.</p></div>'; return; }

        $db = Helpers::db();
        $lists_table = Helpers::table('lists');
        $list = $db->get_row( $db->prepare( "SELECT * FROM $lists_table WHERE id=%d", $list_id ) );
        if ( ! $list ) { echo '<div class="notice notice-error"><p>List not found.</p></div>'; return; }

        echo '<div class="wrap"><h1>' . esc_html( sprintf( __( 'List: %s', 'wp-email-campaigns' ), $list->name ) ) . '</h1>';

        $export_url = admin_url( 'admin-post.php?action=wpec_export_list&list_id=' . $list_id . '&_wpnonce=' . wp_create_nonce('wpec_export_list') );
        echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export this list to CSV', 'wp-email-campaigns' ) . '</a></p>';

        $table = new WPEC_List_Items_Table( $list_id );
        $table->prepare_items();
        echo '<form method="get">';
        echo '<input type="hidden" name="post_type" value="email_campaign" />';
        echo '<input type="hidden" name="page" value="wpec-contacts" />';
        echo '<input type="hidden" name="view" value="list" />';
        echo '<input type="hidden" name="list_id" value="' . (int) $list_id . '" />';
        $table->search_box( __( 'Search Contacts', 'wp-email-campaigns' ), 'wpecli' );
        $table->display();
        echo '</form></div>';
    }

    public function export_list() {
        if ( ! Helpers::user_can_manage() ) wp_die( 'Denied' );
        check_admin_referer( 'wpec_export_list' );
        $list_id = isset($_GET['list_id']) ? absint($_GET['list_id']) : 0;
        if ( ! $list_id ) wp_die( 'Invalid list' );

        $db   = Helpers::db();
        $li   = Helpers::table('list_items');
        $ct   = Helpers::table('contacts');
        $rows = $db->get_results( $db->prepare(
            "SELECT c.email, c.name, c.status, c.created_at
             FROM $li li INNER JOIN $ct c ON c.id=li.contact_id
             WHERE li.list_id=%d ORDER BY li.id DESC", $list_id
        ), ARRAY_A );

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename=list-' . $list_id . '-' . date('Ymd-His') . '.csv' );
        $out = fopen('php://output', 'w');
        fputcsv( $out, [ 'Email', 'Name', 'Status', 'Created At' ] );
        foreach ( $rows as $r ) { fputcsv( $out, $r ); }
        fclose($out);
        exit;
    }

    public function admin_post_list_upload() {
        if ( ! Helpers::user_can_manage() ) wp_die( 'Denied' );
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if ( ! wp_verify_nonce( $nonce, 'wpec_admin' ) ) wp_die( 'Bad nonce' );

        $name = sanitize_text_field( $_POST['list_name'] ?? '' );
        if ( ! $name ) wp_die( 'List name required' );
        if ( empty($_FILES['file']['tmp_name']) ) wp_die( 'No file' );

        $result = $this->handle_upload_to_csv_path( $name, $_FILES['file'] );
        if ( is_wp_error( $result ) ) {
            wp_die( $result->get_error_message() );
        }

        $url = add_query_arg( [
            'post_type'         => 'email_campaign',
            'page'              => 'wpec-contacts',
            'wpec_start_import' => (int) $result['list_id'],
        ], admin_url( 'edit.php' ) );

        wp_safe_redirect( $url );
        exit;
    }

    private function handle_upload_to_csv_path( $list_name, $file_arr ) {
        $tmp_name = $file_arr['tmp_name'];
        $orig     = sanitize_file_name( $file_arr['name'] );
        $ext      = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );

        $dir = Helpers::ensure_uploads_dir();
        $dest = $dir . uniqid('wpec_', true) . '.csv';

        if ( $ext === 'csv' ) {
            if ( ! @move_uploaded_file( $tmp_name, $dest ) ) {
                return new \WP_Error( 'move_failed', 'Failed to move file' );
            }
        } else {
            if ( ! class_exists( IOFactory::class ) ) {
                return new \WP_Error( 'phpspreadsheet_missing', 'PhpSpreadsheet not installed (composer install).' );
            }
            try {
                $spreadsheet = IOFactory::load( $tmp_name );
                $writer = IOFactory::createWriter( $spreadsheet, 'Csv' );
                $writer->setSheetIndex(0);
                $writer->save( $dest );
            } catch ( \Throwable $e ) {
                return new \WP_Error( 'xlsx_convert_error', 'XLSX convert error: ' . $e->getMessage() );
            }
        }

        $db    = Helpers::db();
        $lists = Helpers::table('lists');
        $db->insert( $lists, [
            'name'           => $list_name,
            'status'         => 'importing',
            'created_at'     => Helpers::now(),
            'updated_at'     => null,
            'source_filename'=> $orig,
            'file_path'      => $dest,
            'file_pointer'   => 0,
            'total'          => 0,
            'imported'       => 0,
            'invalid'        => 0,
            'duplicates'     => 0,
        ] );
        $list_id = (int) $db->insert_id;

        return [
            'list_id'  => $list_id,
            'csv_path' => $dest,
        ];
    }

    public function ajax_list_upload() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error( [ 'message' => 'Denied' ] );

        $name = sanitize_text_field( $_POST['list_name'] ?? '' );
        if ( ! $name ) wp_send_json_error( [ 'message' => 'List name required' ] );
        if ( empty($_FILES['file']['tmp_name']) ) wp_send_json_error( [ 'message' => 'No file' ] );

        $result = $this->handle_upload_to_csv_path( $name, $_FILES['file'] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [ 'list_id' => (int) $result['list_id'] ] );
    }

    public function ajax_list_process() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error( [ 'message' => 'Denied' ] );

        $list_id = absint( $_POST['list_id'] ?? 0 );
        if ( ! $list_id ) wp_send_json_error( [ 'message' => 'Bad list id' ] );

        $db     = Helpers::db();
        $lists  = Helpers::table('lists');
        $ct     = Helpers::table('contacts');
        $li     = Helpers::table('list_items');

        $list = $db->get_row( $db->prepare( "SELECT * FROM $lists WHERE id=%d", $list_id ) );
        if ( ! $list ) wp_send_json_error( [ 'message' => 'List not found' ] );
        if ( $list->status === 'ready' ) {
            wp_send_json_success( [
                'done' => true,
                'progress' => 100,
                'stats' => [
                    'imported' => (int)$list->imported,
                    'invalid'  => (int)$list->invalid,
                    'duplicates'=> (int)$list->duplicates,
                    'total'    => (int)$list->total,
                ],
            ] );
        }

        $path = $list->file_path;
        if ( ! $path || ! file_exists( $path ) ) {
            $db->update( $lists, [ 'status' => 'failed', 'updated_at' => Helpers::now() ], [ 'id' => $list_id ] );
            wp_send_json_error( [ 'message' => 'Upload file missing' ] );
        }

        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            wp_send_json_error( [ 'message' => 'Unable to open file' ] );
        }

        $pointer = (int) $list->file_pointer;
        if ( $pointer > 0 ) {
            fseek( $handle, $pointer );
        }

        $processed = 0;
        $valid     = 0;
        $invalid   = 0;
        $dupes     = 0;

        while ( $processed < self::BATCH_SIZE && ( $row = fgetcsv( $handle ) ) !== false ) {
            $processed++;
            $email = $row[0] ?? '';
            $name  = $row[1] ?? '';
            list( $email, $name ) = Helpers::sanitize_email_name( $email, $name );
            if ( empty( $email ) ) { $invalid++; continue; }

            $cid = $db->get_var( $db->prepare( "SELECT id FROM $ct WHERE email=%s", $email ) );
            if ( ! $cid ) {
                $db->insert( $ct, [
                    'email' => $email,
                    'name'  => $name,
                    'status'=> 'active',
                    'created_at' => Helpers::now(),
                    'updated_at' => null,
                    'last_campaign_id' => null,
                ] );
                $cid = (int) $db->insert_id;
            }

            $exists = $db->get_var( $db->prepare( "SELECT id FROM $li WHERE list_id=%d AND contact_id=%d", $list_id, $cid ) );
            if ( $exists ) { $dupes++; continue; }

            $db->insert( $li, [
                'list_id'    => $list_id,
                'contact_id' => $cid,
            ] );
            $valid++;
        }

        $new_pointer = ftell( $handle );
        $eof = feof( $handle );
        fclose( $handle );

        $total     = (int) $list->total + $processed;
        $imported  = (int) $list->imported + $valid;
        $invalid_t = (int) $list->invalid + $invalid;
        $dupes_t   = (int) $list->duplicates + $dupes;

        $data = [
            'file_pointer' => $new_pointer,
            'total'        => $total,
            'imported'     => $imported,
            'invalid'      => $invalid_t,
            'duplicates'   => $dupes_t,
            'updated_at'   => Helpers::now(),
        ];
        if ( $eof ) {
            $data['status'] = 'ready';
            @unlink( $path );
            $data['file_path'] = null;
            $data['file_pointer'] = null;
        }
        $db->update( $lists, $data, [ 'id' => $list_id ] );

        $done = $eof;
        $progress = $done ? 100 : ( $total > 0 ? min( 99, round( ( $imported / max(1,$total) ) * 100 ) ) : 0 );

        wp_send_json_success( [
            'done'     => $done,
            'progress' => $progress,
            'stats'    => [
                'imported'   => $imported,
                'invalid'    => $invalid_t,
                'duplicates' => $dupes_t,
                'total'      => $total,
            ],
        ] );
    }
}

// ───── Lists table (master) ─────
class WPEC_Lists_Table extends \WP_List_Table {
    public function get_columns() {
        return [
            'name'       => __( 'Name', 'wp-email-campaigns' ),
            'status'     => __( 'Status', 'wp-email-campaigns' ),
            'counts'     => __( 'Counts', 'wp-email-campaigns' ),
            'created_at' => __( 'Created', 'wp-email-campaigns' ),
            'actions'    => __( 'Actions', 'wp-email-campaigns' ),
        ];
    }
    public function get_primary_column_name() {
        return 'name';
    }
    public function prepare_items() {
        global $wpdb;
        $lists = Helpers::table('lists');
        $per_page = 20;
        $paged = max(1, (int)($_GET['paged'] ?? 1));
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

        $where = 'WHERE 1=1';
        $args  = [];
        if ( $search ) {
            $where .= " AND (name LIKE %s)";
            $args[] = '%' . $wpdb->esc_like($search) . '%';
        }
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $lists $where", $args ) );
        $offset = ( $paged - 1 ) * $per_page;

        $sql = "SELECT * FROM $lists $where ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, [ $per_page, $offset ] ) ), ARRAY_A );

        $this->items = $rows;
        $this->_column_headers = [ $this->get_columns(), [], [] ];

        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total / $per_page )
        ] );
    }
    public function column_default( $item, $col ) {
        switch ( $col ) {
            case 'name': return esc_html($item['name']);
            case 'status': return esc_html( ucfirst($item['status']) );
            case 'counts': return esc_html( sprintf('Imported: %d | Invalid: %d | Duplicates: %d | Total: %d', $item['imported'], $item['invalid'], $item['duplicates'], $item['total'] ) );
            case 'created_at': return esc_html( $item['created_at'] );
            case 'actions':
                $view = add_query_arg( [
                    'post_type' => 'email_campaign',
                    'page'      => 'wpec-contacts',
                    'view'      => 'list',
                    'list_id'   => (int)$item['id'],
                ], admin_url('edit.php') );
                return sprintf('<a class="button" href="%s">%s</a>', esc_url($view), esc_html__('View', 'wp-email-campaigns'));
        }
        return '';
    }
    public function no_items() {
        _e( 'No lists found.', 'wp-email-campaigns' );
    }
}

// ───── List items table ─────
class WPEC_List_Items_Table extends \WP_List_Table {
    protected $list_id;
    public function __construct( $list_id ) {
        parent::__construct();
        $this->list_id = (int) $list_id;
    }
    public function get_columns() {
        return [
            'email'      => __( 'Email', 'wp-email-campaigns' ),
            'name'       => __( 'Name', 'wp-email-campaigns' ),
            'status'     => __( 'Status', 'wp-email-campaigns' ),
            'created_at' => __( 'Created', 'wp-email-campaigns' ),
        ];
    }
    public function get_primary_column_name() { return 'email'; }
    public function prepare_items() {
        global $wpdb;
        $li = Helpers::table('list_items');
        $ct = Helpers::table('contacts');
        $per_page = 50;
        $paged = max(1, (int)($_GET['paged'] ?? 1));
        $offset = ( $paged - 1 ) * $per_page;
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

        $where = "WHERE li.list_id=%d";
        $args  = [ $this->list_id ];
        if ( $search ) {
            $where .= " AND (c.email LIKE %s OR c.name LIKE %s)";
            $args[] = '%' . $wpdb->esc_like($search) . '%';
            $args[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $li li INNER JOIN $ct c ON c.id=li.contact_id $where", $args
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.email, c.name, c.status, c.created_at
             FROM $li li INNER JOIN $ct c ON c.id=li.contact_id
             $where ORDER BY li.id DESC LIMIT %d OFFSET %d",
            array_merge( $args, [ $per_page, $offset ] )
        ), ARRAY_A );

        $this->items = $rows;
        $this->_column_headers = [ $this->get_columns(), [], [] ];
        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total / $per_page )
        ] );
    }
    public function column_default( $item, $col ) {
        return esc_html( $item[$col] ?? '' );
    }
}

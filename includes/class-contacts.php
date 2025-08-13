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
        add_action( 'wpec_render_contacts_table', [ $this, 'render_router' ] );

        // AJAX import
        add_action( 'wp_ajax_wpec_list_upload',   [ $this, 'ajax_list_upload' ] );
        add_action( 'wp_ajax_wpec_list_process',  [ $this, 'ajax_list_process' ] );

        // AJAX delete mapping (individual + bulk)
        add_action( 'wp_ajax_wpec_delete_list_mapping', [ $this, 'ajax_delete_list_mapping' ] );

        // Fallback + actions
        add_action( 'admin_post_wpec_list_upload',         [ $this, 'admin_post_list_upload' ] );
        add_action( 'admin_post_wpec_delete_list_mapping', [ $this, 'admin_post_delete_list_mapping' ] );

        // Export list
        add_action( 'admin_post_wpec_export_list', [ $this, 'export_list' ] );
    }

    // ---------- Router for Contacts screens ----------
    public function render_router() {
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : '';
        if ( $view === 'list' ) {
            $this->render_list_items();
            return;
        }
        if ( $view === 'dupes' ) {
            $this->render_duplicates(); // all duplicates
            return;
        }
        if ( $view === 'dupes_list' ) {
            $this->render_duplicates( absint( $_GET['list_id'] ?? 0 ) ); // per list
            return;
        }
        $this->render_lists_screen(); // default
    }

    // ---------- Default Contacts screen (upload + lists + link to duplicates) ----------
    public function render_lists_screen() {
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

        echo '<p class="description">' . esc_html__( 'The file must include a header row. Required columns: First name, Last name, Email. Optional: Company name, Company number of employees, Company annual revenue, Contact number, Job title, Industry, Country, State, City, Postal code.', 'wp-email-campaigns' ) . '</p>';

        echo '<p><button type="submit" class="button button-primary" id="wpec-upload-btn">' . esc_html__( 'Upload & Import', 'wp-email-campaigns' ) . '</button> ';
        echo '<span class="wpec-loader" style="display:none;"></span></p>';

        echo '<div id="wpec-progress-wrap" style="display:none;"><div class="wpec-progress"><span id="wpec-progress-bar" style="width:0%"></span></div><p id="wpec-progress-text"></p></div>';

        // Result panel (hidden initially)
        echo '<div id="wpec-import-result" class="wpec-result" style="display:none;"></div>';

        echo '</form></div>';

        // Links
        $dupes_url = add_query_arg([
            'post_type'=>'email_campaign','page'=>'wpec-contacts','view'=>'dupes'
        ], admin_url('edit.php'));
        echo '<p><a class="button" href="'.esc_url($dupes_url).'">'.esc_html__('View Duplicates (All Lists)','wp-email-campaigns').'</a></p>';

        // Lists table
        $table = new WPEC_Lists_Table();
        $table->prepare_items();
        echo '<h2 style="margin-top:20px;">' . esc_html__( 'Lists', 'wp-email-campaigns' ) . '</h2>';
        echo '<form method="get">';
        echo '<input type="hidden" name="post_type" value="email_campaign" />';
        echo '<input type="hidden" name="page" value="wpec-contacts" />';
        $table->search_box( __( 'Search Lists', 'wp-email-campaigns' ), 'wpecl' );
        $table->display();
        echo '</form>';
    }

    // ---------- Per-list contacts ----------
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
        $dupes_url  = add_query_arg([
            'post_type'=>'email_campaign','page'=>'wpec-contacts','view'=>'dupes_list','list_id'=>$list_id
        ], admin_url('edit.php'));

        echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export this list to CSV', 'wp-email-campaigns' ) . '</a> ';
        echo '<a class="button" href="' . esc_url( $dupes_url ) . '">' . esc_html__( 'View Duplicates for this List', 'wp-email-campaigns' ) . '</a></p>';

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

    // ---------- Duplicates views ----------
    private function render_duplicates( $list_id = 0 ) {
        if ( ! Helpers::user_can_manage() ) { wp_die( 'Denied' ); }

        $title = $list_id ? sprintf( __( 'Duplicates — List #%d', 'wp-email-campaigns' ), $list_id )
                          : __( 'Duplicates — All Lists', 'wp-email-campaigns' );

        echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';

        // Bulk delete toolbar + progress
        echo '<div class="wpec-dup-toolbar" style="margin:10px 0;">';
        echo '<button id="wpec-dup-bulk-delete" class="button" disabled>' . esc_html__( 'Delete selected from current lists', 'wp-email-campaigns' ) . '</button> ';
        echo '<span class="wpec-loader" id="wpec-dup-bulk-loader" style="display:none;"></span>';
        echo '</div>';

        echo '<div id="wpec-dup-bulk-progress" style="display:none;"><div class="wpec-progress"><span id="wpec-dup-progress-bar" style="width:0%"></span></div><p id="wpec-dup-progress-text"></p></div>';

        $table = new WPEC_Duplicates_Table( $list_id );
        $table->prepare_items();
        echo '<form id="wpec-dup-form" method="get">';
        echo '<input type="hidden" name="post_type" value="email_campaign" />';
        echo '<input type="hidden" name="page" value="wpec-contacts" />';
        echo '<input type="hidden" name="view" value="dupes' . ( $list_id ? '_list' : '' ) . '" />';
        if ( $list_id ) {
            echo '<input type="hidden" name="list_id" value="' . (int) $list_id . '" />';
        }
        $table->search_box( __( 'Search email/name', 'wp-email-campaigns' ), 'wpecdup' );
        $table->display();
        echo '</form></div>';
    }

    // ---------- Export ----------
    public function export_list() {
        if ( ! Helpers::user_can_manage() ) wp_die( 'Denied' );
        check_admin_referer( 'wpec_export_list' );
        $list_id = isset($_GET['list_id']) ? absint($_GET['list_id']) : 0;
        if ( ! $list_id ) wp_die( 'Invalid list' );

        $db   = Helpers::db();
        $li   = Helpers::table('list_items');
        $ct   = Helpers::table('contacts');
        $rows = $db->get_results( $db->prepare(
            "SELECT c.email, c.first_name, c.last_name, c.company_name, c.company_employees, c.company_annual_revenue,
                    c.contact_number, c.job_title, c.industry, c.country, c.state, c.city, c.postal_code, c.status, li.created_at, li.is_duplicate_import
             FROM $li li INNER JOIN $ct c ON c.id=li.contact_id
             WHERE li.list_id=%d ORDER BY li.id DESC", $list_id
        ), ARRAY_A );

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename=list-' . $list_id . '-' . date('Ymd-His') . '.csv' );
        $out = fopen('php://output', 'w');
        fputcsv( $out, [ 'Email','First name','Last name','Company','Employees','Annual revenue','Contact number','Job title','Industry','Country','State','City','Postal code','Status','Imported at','Duplicate import' ] );
        foreach ( $rows as $r ) { fputcsv( $out, $r ); }
        fclose($out);
        exit;
    }

    // ---------- Non-JS upload handler ----------
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

    // ---------- Delete mapping (non-AJAX fallback) ----------
    public function admin_post_delete_list_mapping() {
        if ( ! Helpers::user_can_manage() ) wp_die( 'Denied' );
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';
        if ( ! wp_verify_nonce( $nonce, 'wpec_delete_list_mapping' ) ) wp_die( 'Bad nonce' );
        $list_id = absint( $_GET['list_id'] ?? 0 );
        $contact_id = absint( $_GET['contact_id'] ?? 0 );
        if ( ! $list_id || ! $contact_id ) wp_die( 'Bad params' );

        $li = Helpers::table('list_items');
        $dupes = Helpers::table('dupes');
        Helpers::db()->delete( $li, [ 'list_id' => $list_id, 'contact_id' => $contact_id ] );
        Helpers::db()->delete( $dupes, [ 'list_id' => $list_id, 'contact_id' => $contact_id ] );

        wp_safe_redirect( wp_get_referer() ?: admin_url('edit.php?post_type=email_campaign&page=wpec-contacts') );
        exit;
    }

    // ---------- Delete mapping (AJAX) ----------
    public function ajax_delete_list_mapping() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error( [ 'message' => 'Denied' ] );

        $list_id = absint( $_POST['list_id'] ?? 0 );
        $contact_id = absint( $_POST['contact_id'] ?? 0 );
        if ( ! $list_id || ! $contact_id ) {
            wp_send_json_error( [ 'message' => 'Bad params' ] );
        }

        $li = Helpers::table('list_items');
        $dupes = Helpers::table('dupes');
        $deleted = Helpers::db()->delete( $li, [ 'list_id' => $list_id, 'contact_id' => $contact_id ] );
        Helpers::db()->delete( $dupes, [ 'list_id' => $list_id, 'contact_id' => $contact_id ] );

        wp_send_json_success( [ 'deleted' => (int) $deleted ] );
    }

    // ---------- Upload normalize to CSV ----------
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
            'header_map'     => null,
            'total'          => 0,
            'imported'       => 0,
            'invalid'        => 0,
            'duplicates'     => 0,
        ] );
        $list_id = (int) $db->insert_id;

        return [ 'list_id'  => $list_id, 'csv_path' => $dest ];
    }

    // ---------- AJAX: upload ----------
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

    // ---------- AJAX: process chunk ----------
    public function ajax_list_process() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error( [ 'message' => 'Denied' ] );

        $list_id = absint( $_POST['list_id'] ?? 0 );
        if ( ! $list_id ) wp_send_json_error( [ 'message' => 'Bad list id' ] );

        $db     = Helpers::db();
        $lists  = Helpers::table('lists');
        $ct     = Helpers::table('contacts');
        $li     = Helpers::table('list_items');
        $dupes  = Helpers::table('dupes');

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
                'list_id' => $list_id,
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

        // If first chunk, parse header and save header_map
        $header_map = $list->header_map ? json_decode( $list->header_map, true ) : null;
        if ( empty( $header_map ) ) {
            $header_row = fgetcsv( $handle );
            if ( ! $header_row ) {
                fclose($handle);
                wp_send_json_error( [ 'message' => 'File missing header row' ] );
            }
            $map = Helpers::parse_header_map( $header_row );
            if ( ! Helpers::required_fields_present( $map ) ) {
                fclose($handle);
                wp_send_json_error( [ 'message' => 'Required headers missing: First name, Last name, Email' ] );
            }
            $header_map = $map;
            // Store pointer after header
            $after_header_ptr = ftell( $handle );
            $db->update( $lists, [
                'header_map'   => wp_json_encode( $header_map ),
                'file_pointer' => $after_header_ptr,
                'updated_at'   => Helpers::now(),
            ], [ 'id' => $list_id ] );
        }

        // Resume position
        $pointer = (int) $db->get_var( $db->prepare( "SELECT file_pointer FROM $lists WHERE id=%d", $list_id ) );
        if ( $pointer > 0 ) {
            fseek( $handle, $pointer );
        }

        $processed = 0;
        $valid_imports = 0;
        $invalid_rows  = 0;
        $dup_count     = 0;

        while ( $processed < self::BATCH_SIZE && ( $row = fgetcsv( $handle ) ) !== false ) {
            $processed++;

            // Build data from header map
            $data = [
                'first_name' => isset($header_map['first_name']) ? sanitize_text_field( $row[ $header_map['first_name'] ] ?? '' ) : '',
                'last_name'  => isset($header_map['last_name'])  ? sanitize_text_field( $row[ $header_map['last_name'] ] ?? '' ) : '',
                'email'      => isset($header_map['email'])      ? sanitize_email( $row[ $header_map['email'] ] ?? '' ) : '',
                'company_name' => isset($header_map['company_name']) ? sanitize_text_field( $row[ $header_map['company_name'] ] ?? '' ) : null,
                'company_employees' => isset($header_map['company_employees']) ? intval( $row[ $header_map['company_employees'] ] ?? 0 ) : null,
                'company_annual_revenue' => isset($header_map['company_annual_revenue']) ? intval( preg_replace('/[^0-9]/','', (string)($row[ $header_map['company_annual_revenue'] ] ?? '0') ) ) : null,
                'contact_number' => isset($header_map['contact_number']) ? sanitize_text_field( $row[ $header_map['contact_number'] ] ?? '' ) : null,
                'job_title' => isset($header_map['job_title']) ? sanitize_text_field( $row[ $header_map['job_title'] ] ?? '' ) : null,
                'industry'  => isset($header_map['industry'])  ? sanitize_text_field( $row[ $header_map['industry'] ] ?? '' ) : null,
                'country'   => isset($header_map['country'])   ? sanitize_text_field( $row[ $header_map['country'] ] ?? '' ) : null,
                'state'     => isset($header_map['state'])     ? sanitize_text_field( $row[ $header_map['state'] ] ?? '' ) : null,
                'city'      => isset($header_map['city'])      ? sanitize_text_field( $row[ $header_map['city'] ] ?? '' ) : null,
                'postal_code' => isset($header_map['postal_code']) ? sanitize_text_field( $row[ $header_map['postal_code'] ] ?? '' ) : null,
            ];

            // Validate required
            if ( empty($data['email']) || ! is_email($data['email']) || empty($data['first_name']) || empty($data['last_name']) ) {
                $invalid_rows++;
                continue;
            }

            // Upsert contact (by email)
            $contact_id = $db->get_var( $db->prepare( "SELECT id FROM $ct WHERE email=%s", $data['email'] ) );
            $is_duplicate = false;

            if ( $contact_id ) {
                $is_duplicate = true;
                // Update missing fields if empty in DB (light touch)
                $existing = $db->get_row( $db->prepare("SELECT * FROM $ct WHERE id=%d", $contact_id), ARRAY_A );
                $updates = [];
                foreach ( ['first_name','last_name','company_name','company_employees','company_annual_revenue','contact_number','job_title','industry','country','state','city','postal_code'] as $k ) {
                    if ( $data[$k] !== null && $data[$k] !== '' && ( ! isset($existing[$k]) || $existing[$k] === '' || is_null($existing[$k]) ) ) {
                        $updates[$k] = $data[$k];
                    }
                }
                if ( ! empty($updates) ) {
                    $updates['updated_at'] = Helpers::now();
                    $db->update( $ct, $updates, [ 'id' => $contact_id ] );
                }
            } else {
                $db->insert( $ct, [
                    'email' => $data['email'],
                    'name'  => trim( $data['first_name'].' '.$data['last_name'] ),
                    'first_name' => $data['first_name'],
                    'last_name'  => $data['last_name'],
                    'company_name' => $data['company_name'],
                    'company_employees' => $data['company_employees'],
                    'company_annual_revenue' => $data['company_annual_revenue'],
                    'contact_number' => $data['contact_number'],
                    'job_title' => $data['job_title'],
                    'industry'  => $data['industry'],
                    'country'   => $data['country'],
                    'state'     => $data['state'],
                    'city'      => $data['city'],
                    'postal_code' => $data['postal_code'],
                    'status'=> 'active',
                    'created_at' => Helpers::now(),
                    'updated_at' => null,
                    'last_campaign_id' => null,
                ] );
                $contact_id = (int) $db->insert_id;
            }

            // Map into list; mark duplicate import if contact pre-existed
            $exists_map = $db->get_var( $db->prepare( "SELECT id FROM $li WHERE list_id=%d AND contact_id=%d", $list_id, $contact_id ) );
            if ( ! $exists_map ) {
                $db->insert( $li, [
                    'list_id'    => $list_id,
                    'contact_id' => $contact_id,
                    'is_duplicate_import' => $is_duplicate ? 1 : 0,
                    'created_at' => Helpers::now(),
                ] );
                $valid_imports++;
            } else {
                // already in this list; count as invalid (not uploaded)
                $invalid_rows++;
                continue;
            }

            if ( $is_duplicate ) {
                $dup_count++;
                $db->insert( $dupes, [
                    'list_id'    => $list_id,
                    'contact_id' => $contact_id,
                    'email'      => $data['email'],
                    'first_name' => $data['first_name'],
                    'last_name'  => $data['last_name'],
                    'created_at' => Helpers::now(),
                ] );
            }
        }

        $new_pointer = ftell( $handle );
        $eof = feof( $handle );
        fclose( $handle );

        // Update running totals in lists
        $total     = (int) $list->total + $processed;
        $imported  = (int) $list->imported + $valid_imports;
        $invalid_t = (int) $list->invalid + $invalid_rows;
        $dupes_t   = (int) $list->duplicates + $dup_count;

        $data_upd = [
            'file_pointer' => $new_pointer,
            'total'        => $total,
            'imported'     => $imported,
            'invalid'      => $invalid_t,
            'duplicates'   => $dupes_t,
            'updated_at'   => Helpers::now(),
        ];
        if ( $eof ) {
            $data_upd['status'] = 'ready';
            @unlink( $path );
            $data_upd['file_path'] = null;
            $data_upd['file_pointer'] = null;
        }
        $db->update( $lists, $data_upd, [ 'id' => $list_id ] );

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
            'list_id'  => $list_id,
        ] );
    }
}

// ---------- Lists table ----------
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
    public function get_primary_column_name() { return 'name'; }
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
            case 'counts':
                return esc_html( sprintf(
                    'Imported: %d | Duplicates: %d | Not uploaded: %d | Total seen: %d',
                    $item['imported'], $item['duplicates'], $item['invalid'], $item['total']
                ) );
            case 'created_at': return esc_html( $item['created_at'] );
            case 'actions':
                $view = add_query_arg( [
                    'post_type' => 'email_campaign',
                    'page'      => 'wpec-contacts',
                    'view'      => 'list',
                    'list_id'   => (int)$item['id'],
                ], admin_url('edit.php') );
                $dupes = add_query_arg( [
                    'post_type' => 'email_campaign',
                    'page'      => 'wpec-contacts',
                    'view'      => 'dupes_list',
                    'list_id'   => (int)$item['id'],
                ], admin_url('edit.php') );
                return sprintf(
                    '<a class="button" href="%s">%s</a> <a class="button" href="%s">%s</a>',
                    esc_url($view), esc_html__('View', 'wp-email-campaigns'),
                    esc_url($dupes), esc_html__('View Duplicates', 'wp-email-campaigns')
                );
        }
        return '';
    }
    public function no_items() { _e( 'No lists found.', 'wp-email-campaigns' ); }
}

// ---------- Per-list contacts table ----------
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
            'created_at' => __( 'Imported At', 'wp-email-campaigns' ),
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
            $where .= " AND (c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)";
            $args[] = '%' . $wpdb->esc_like($search) . '%';
            $args[] = '%' . $wpdb->esc_like($search) . '%';
            $args[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $li li INNER JOIN $ct c ON c.id=li.contact_id $where", $args
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.email, CONCAT_WS(' ', c.first_name, c.last_name) AS name, c.status, li.created_at, li.is_duplicate_import, c.id AS contact_id
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
    public function column_email( $item ) {
        $pill = !empty($item['is_duplicate_import']) ? ' <span class="wpec-pill wpec-pill-dup">'.esc_html__('Duplicate','wp-email-campaigns').'</span>' : '';
        return esc_html( $item['email'] ) . $pill;
    }
    public function column_default( $item, $col ) {
        return esc_html( $item[$col] ?? '' );
    }
}

// ---------- Duplicates table (with checkboxes, clickable list, AJAX delete) ----------
class WPEC_Duplicates_Table extends \WP_List_Table {
    protected $list_id;
    public function __construct( $list_id = 0 ) {
        parent::__construct( [ 'plural' => 'duplicates', 'singular' => 'duplicate' ] );
        $this->list_id = (int) $list_id;
    }
    public function get_columns() {
        return [
            'cb'            => '<input type="checkbox" />',
            'email'         => __( 'Email', 'wp-email-campaigns' ),
            'first_name'    => __( 'First name', 'wp-email-campaigns' ),
            'last_name'     => __( 'Last name', 'wp-email-campaigns' ),
            'current_list'  => __( 'Current list', 'wp-email-campaigns' ),
            'imported_at'   => __( 'Last imported date', 'wp-email-campaigns' ),
            'previous_list' => __( 'Previous list', 'wp-email-campaigns' ),
            'actions'       => __( 'Actions', 'wp-email-campaigns' ),
        ];
    }
    public function get_primary_column_name() { return 'email'; }
    protected function column_cb( $item ) {
        // value encodes list_id and contact_id for AJAX bulk deletion
        $value = (int)$item['list_id'] . ':' . (int)$item['contact_id'];
        return sprintf('<input type="checkbox" name="ids[]" value="%s"/>', esc_attr($value));
    }
    public function prepare_items() {
        global $wpdb;
        $dupes = Helpers::table('dupes');
        $lists = Helpers::table('lists');
        $li    = Helpers::table('list_items');

        $per_page = 30;
        $paged = max(1, (int)($_GET['paged'] ?? 1));
        $offset = ( $paged - 1 ) * $per_page;
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

        $where = "WHERE 1=1";
        $args = [];
        if ( $this->list_id ) {
            $where .= " AND d.list_id=%d";
            $args[] = $this->list_id;
        }
        if ( $search ) {
            $where .= " AND (d.email LIKE %s OR d.first_name LIKE %s OR d.last_name LIKE %s)";
            $args[] = '%'.$wpdb->esc_like($search).'%';
            $args[] = '%'.$wpdb->esc_like($search).'%';
            $args[] = '%'.$wpdb->esc_like($search).'%';
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $dupes d $where", $args ) );

        $sql = "
            SELECT d.id, d.email, d.first_name, d.last_name, d.created_at AS imported_at,
                   d.list_id, l.name AS current_list,
                   (
                     SELECT l2.name
                     FROM $li li2
                     INNER JOIN $lists l2 ON l2.id = li2.list_id
                     WHERE li2.contact_id = d.contact_id
                       AND li2.list_id <> d.list_id
                     ORDER BY li2.created_at DESC
                     LIMIT 1
                   ) AS previous_list,
                   d.contact_id
            FROM $dupes d
            INNER JOIN $lists l ON l.id = d.list_id
            $where
            ORDER BY d.id DESC
            LIMIT %d OFFSET %d
        ";
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
            case 'email':
            case 'first_name':
            case 'last_name':
                return esc_html( $item[ $col ] ?? '' );
            case 'current_list':
                $url = add_query_arg( [
                    'post_type' => 'email_campaign',
                    'page'      => 'wpec-contacts',
                    'view'      => 'list',
                    'list_id'   => (int) $item['list_id'],
                ], admin_url('edit.php') );
                return sprintf('<a href="%s">%s</a>',
                    esc_url($url),
                    esc_html($item['current_list'] ?? ('#'.(int)$item['list_id']))
                );
            case 'previous_list':
                return esc_html( $item['previous_list'] ?? '' );
            case 'imported_at':
                return esc_html( $item['imported_at'] ?? '' );
            case 'actions':
                $btn = sprintf(
                    '<button type="button" class="button button-small wpec-del-dup" data-list-id="%d" data-contact-id="%d">%s</button>',
                    (int)$item['list_id'],
                    (int)$item['contact_id'],
                    esc_html__('Delete from current list', 'wp-email-campaigns')
                );
                return $btn;
        }
        return '';
    }
    public function no_items() {
        _e( 'No duplicates found.', 'wp-email-campaigns' );
    }
}

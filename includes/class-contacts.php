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
        // Router for Lists page (legacy slug kept; menu label changed to "Lists")
        add_action( 'wpec_render_contacts_table', [ $this, 'render_router' ] );

        // Menus
        add_action( 'admin_menu', [ $this, 'admin_menu_adjustments' ], 999 );

        // Assets
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // AJAX: import/process
        add_action( 'wp_ajax_wpec_list_upload',   [ $this, 'ajax_list_upload' ] );
        add_action( 'wp_ajax_wpec_list_process',  [ $this, 'ajax_list_process' ] );

        // AJAX: create
        add_action( 'wp_ajax_wpec_contact_create', [ $this, 'ajax_contact_create' ] );
        add_action( 'wp_ajax_wpec_list_create',    [ $this, 'ajax_list_create' ] );

        // AJAX: delete mapping
        add_action( 'wp_ajax_wpec_delete_list_mapping', [ $this, 'ajax_delete_list_mapping' ] );

        // AJAX: list metrics dropdown (Lists page)
        add_action( 'wp_ajax_wpec_list_metrics', [ $this, 'ajax_list_metrics' ] );

        // NEW: AJAX for Contacts directory (filters + pagination + bulk ops + export)
        add_action( 'wp_ajax_wpec_contacts_query',        [ $this, 'ajax_contacts_query' ] );
        add_action( 'wp_ajax_wpec_contacts_bulk_delete',  [ $this, 'ajax_contacts_bulk_delete' ] );
        add_action( 'wp_ajax_wpec_contacts_bulk_move',    [ $this, 'ajax_contacts_bulk_move' ] );
        add_action( 'wp_ajax_wpec_contacts_export',       [ $this, 'ajax_contacts_export' ] );

        // Fallback actions (non-AJAX)
        add_action( 'admin_post_wpec_list_upload',         [ $this, 'admin_post_list_upload' ] );
        add_action( 'admin_post_wpec_delete_list_mapping', [ $this, 'admin_post_delete_list_mapping' ] );

        // Export per-list
        add_action( 'admin_post_wpec_export_list', [ $this, 'export_list' ] ); 

        // Special lists (Do Not Send / Bounced)
        add_action( 'wp_ajax_wpec_status_bulk_update', [ $this, 'ajax_status_bulk_update' ] );
        add_action( 'wp_ajax_wpec_status_add_by_email', [ $this, 'ajax_status_add_by_email' ] );
    }

    /** Register submenus; rename old "Contacts" to "Lists"; add "Import" and "Duplicates" */
    public function admin_menu_adjustments() {
        // Capability
        $cap = 'manage_options';
        if ( class_exists(__NAMESPACE__ . '\\Helpers') ) {
            if ( method_exists( Helpers::class, 'manage_cap' ) ) {
                $cap = Helpers::manage_cap();
            } elseif ( method_exists( Helpers::class, 'cap' ) ) {
                $cap = Helpers::cap();
            }
        }

        // Rename existing submenu "Contacts" (slug wpec-contacts) under CPT to "Lists"
        global $submenu;
        $parent = 'edit.php?post_type=email_campaign';
        if ( isset( $submenu[ $parent ] ) ) {
            foreach ( $submenu[ $parent ] as &$item ) {
                if ( isset( $item[2] ) && $item[2] === 'wpec-contacts' ) {
                    $item[0] = __( 'Lists', 'wp-email-campaigns' );
                    $item[3] = __( 'Lists', 'wp-email-campaigns' );
                }
            }
        }

        // Add new "Import" submenu (moved upload UI here)
        add_submenu_page(
            $parent,
            __( 'Import', 'wp-email-campaigns' ),
            __( 'Import', 'wp-email-campaigns' ),
            $cap,
            'wpec-import',
            [ $this, 'render_import_screen' ],
            20
        );

        // Add new "Contacts" directory page
        add_submenu_page(
            $parent,
            __( 'Contacts', 'wp-email-campaigns' ),
            __( 'Contacts', 'wp-email-campaigns' ),
            $cap,
            'wpec-all-contacts',
            [ $this, 'render_all_contacts' ],
            21
        );

        // Add new "Duplicates" page (all lists)
        add_submenu_page(
            $parent,
            __( 'Duplicates', 'wp-email-campaigns' ),
            __( 'Duplicates', 'wp-email-campaigns' ),
            $cap,
            'wpec-duplicates',
            [ $this, 'render_duplicates_page' ],
            22
        );
                // Special, non-deletable lists
        add_submenu_page(
            $parent,
            __( 'Do Not Send', 'wp-email-campaigns' ),
            __( 'Do Not Send', 'wp-email-campaigns' ),
           $cap,
            'wpec-donotsend',
            function(){ $this->render_status_list( 'donotsend' ); },
            23
        );

        add_submenu_page(
            $parent,
            __( 'Bounced', 'wp-email-campaigns' ),
            __( 'Bounced', 'wp-email-campaigns' ),
           $cap,
            'wpec-bounced',
            function(){ $this->render_status_list( 'bounced' ); },
            24
        );
    }

    /** Ensure CSS/JS on all our admin pages; also expose Select2 sources */
    public function enqueue_admin_assets( $hook ) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $ok = false;
        if ( $screen ) {
            $ok = (
                $screen->post_type === 'email_campaign' ||
                $screen->id === 'email_campaign_page_wpec-contacts' ||
                $screen->id === 'email_campaign_page_wpec-all-contacts' ||
                $screen->id === 'email_campaign_page_wpec-import' ||
                $screen->id === 'email_campaign_page_wpec-duplicates'
            );
        }
        $page = $_GET['page'] ?? '';
        $ok = $ok || in_array( $page, ['wpec-contacts','wpec-all-contacts','wpec-import','wpec-duplicates'], true );
        if ( ! $ok ) return;

        $css_path = plugin_dir_path(__FILE__) . '../admin/admin.css';
        $js_path  = plugin_dir_path(__FILE__) . '../admin/admin.js';
        $css_url  = plugins_url('../admin/admin.css', __FILE__);
        $js_url   = plugins_url('../admin/admin.js',  __FILE__);

        wp_enqueue_style( 'wpec-admin', $css_url, [], @filemtime($css_path) ?: '1.0' );
        wp_enqueue_script( 'wpec-admin', $js_url, ['jquery'], @filemtime($js_path) ?: '1.0', true );

        wp_localize_script( 'wpec-admin', 'WPEC', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'wpec_admin' ),
            'startImport'    => isset($_GET['wpec_start_import']) ? intval($_GET['wpec_start_import']) : 0,
            'select2CdnJs'   => 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            'select2CdnCss'  => 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
                'listViewBase'    => admin_url('edit.php?post_type=email_campaign&page=wpec-contacts&view=list&list_id=')

        ] );
    }

    // ===================== ROUTER (legacy Lists page) =====================
    public function render_router() {
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : '';
        if ( $view === 'list' ) { $this->render_list_items(); return; }
        if ( $view === 'dupes' ) { $this->render_duplicates(); return; }
        if ( $view === 'dupes_list' ) { $this->render_duplicates( absint( $_GET['list_id'] ?? 0 ) ); return; }
        if ( $view === 'contact' ) { $this->render_contact_detail( absint( $_GET['contact_id'] ?? 0 ) ); return; }
        $this->render_lists_screen();
    }

    // ===================== LISTS PAGE =====================
    public function render_lists_screen() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Lists', 'wp-email-campaigns' ) . '</h1>';
        echo '<p style="margin:6px 0 14px 0">';
        echo '<a class="button" href="'.esc_url( admin_url('edit.php?post_type=email_campaign&page=wpec-import') ).'">'.esc_html__('Go to Import','wp-email-campaigns').'</a> ';
        echo '<a class="button" href="'.esc_url( admin_url('edit.php?post_type=email_campaign&page=wpec-duplicates') ).'">'.esc_html__('View Duplicates (All Lists)','wp-email-campaigns').'</a>';
        echo '</p>';

        $table = new WPEC_Lists_Table();
        $table->prepare_items();
        echo '<form method="get">';
        echo '<input type="hidden" name="post_type" value="email_campaign" />';
        echo '<input type="hidden" name="page" value="wpec-contacts" />';
        $table->search_box( __( 'Search Lists', 'wp-email-campaigns' ), 'wpecl' );
        $table->display();
        echo '</form></div>';
    }

    // ===================== IMPORT PAGE =====================
    public function render_import_screen() {
        if ( ! Helpers::user_can_manage() ) { wp_die( 'Denied' ); }

        $db = Helpers::db();
        $lists_table = Helpers::table('lists');
        $li = Helpers::table('list_items');

        // Lists + counts for dropdowns
        $lists = $db->get_results(
            "SELECT l.id, l.name, COUNT(li.id) AS cnt
             FROM $lists_table l
             LEFT JOIN $li li ON li.list_id = l.id
             GROUP BY l.id
             ORDER BY l.name ASC LIMIT 1000", ARRAY_A
        );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Import Contacts', 'wp-email-campaigns' ) . '</h1>';

        echo '<div id="wpec-upload-panel" class="wpec-card">';
        echo '<h2 style="margin-top:0;">' . esc_html__( 'Upload CSV/XLSX', 'wp-email-campaigns' ) . '</h2>';

        echo '<p style="margin-bottom:12px">';
        echo '<button id="wpec-open-add-contact" class="button">'.esc_html__('Add Contact','wp-email-campaigns').'</button> ';
        echo '<button id="wpec-open-create-list" class="button">'.esc_html__('Create List','wp-email-campaigns').'</button>';
        echo '</p>';

        $action_url = esc_url( admin_url( 'admin-post.php' ) );
        echo '<form id="wpec-list-upload-form" method="post" action="' . $action_url . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="wpec_list_upload" />';
        echo '<input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce('wpec_admin') ) . '"/>';

        echo '<fieldset style="margin:10px 0 6px 0">';
        echo '<label><input type="radio" name="list_mode" value="new" checked> '.esc_html__('Create new list','wp-email-campaigns').'</label> ';
        echo '<label style="margin-left:12px;"><input type="radio" name="list_mode" value="existing"> '.esc_html__('Add to existing list','wp-email-campaigns').'</label>';
        echo '</fieldset>';

        echo '<div id="wpec-list-target-new">';
        echo '<p><label><strong>' . esc_html__( 'New List Name', 'wp-email-campaigns' ) . '</strong><br/>';
        echo '<input type="text" name="list_name" class="regular-text" placeholder="'.esc_attr__('e.g. Oct Leads','wp-email-campaigns').'" ></label></p>';
        echo '</div>';

        echo '<div id="wpec-list-target-existing" style="display:none">';
        echo '<p><label><strong>' . esc_html__( 'Existing List', 'wp-email-campaigns' ) . '</strong><br/>';
        echo '<select id="wpec-existing-list" name="existing_list_id" style="min-width:320px;max-width:100%">';
        echo '<option value="">'.esc_html__('— Select —','wp-email-campaigns').'</option>';
        foreach ( (array) $lists as $row ) {
            printf('<option value="%d">%s (%s)</option>', (int)$row['id'], esc_html($row['name']), number_format_i18n((int)$row['cnt']));
        }
        echo '</select></label></p>';
        echo '</div>';

        echo '<p><label><strong>' . esc_html__( 'CSV or XLSX file', 'wp-email-campaigns' ) . '</strong><br/>';
        echo '<input type="file" name="file" accept=".csv,.xlsx" required></label></p>';

        echo '<p class="description">' . esc_html__( 'File must have a header row. Required: First name, Last name, Email. Optional: Company name, Company number of employees, Company annual revenue, Contact number, Job title, Industry, Country, State, City, Postal code.', 'wp-email-campaigns' ) . '</p>';

        echo '<p><button type="submit" class="button button-primary" id="wpec-upload-btn">' . esc_html__( 'Upload & Import', 'wp-email-campaigns' ) . '</button> ';
        echo '<span class="wpec-loader" style="display:none;"></span></p>';

        echo '<div id="wpec-progress-wrap" style="display:none;"><div class="wpec-progress"><span id="wpec-progress-bar" style="width:0%"></span></div><p id="wpec-progress-text"></p></div>';
        echo '<div id="wpec-import-result" class="wpec-result" style="display:none;"></div>';

        echo '</form></div>';

        // Modals
        $this->render_add_contact_modal( $lists );
        $this->render_create_list_modal();
        echo '</div>'; // wrap
    }

    // ===================== ALL CONTACTS (directory) =====================
    public function render_all_contacts() {
        if ( ! Helpers::user_can_manage() ) { wp_die( 'Denied' ); }

        $db = Helpers::db();
        $ct = Helpers::table('contacts');
        $lists_table = Helpers::table('lists');
        $li = Helpers::table('list_items');

        // Distinct values (limited) for filter dropdowns
        $distinct = function($col, $limit = 500) use ($db, $ct) {
            $col = preg_replace('/[^a-z_]/', '', $col);
            return $db->get_col( "SELECT DISTINCT $col FROM $ct WHERE $col IS NOT NULL AND $col <> '' ORDER BY $col ASC LIMIT " . (int)$limit );
        };
        $companies = $distinct('company_name');
        $cities    = $distinct('city');
        $states    = $distinct('state');
        $countries = $distinct('country');
        $jobs      = $distinct('job_title');
        $postcodes = $distinct('postal_code');

        // Lists + counts (for filter and bulk-move)
        $lists = $db->get_results(
            "SELECT l.id, l.name, COUNT(li.id) AS cnt
             FROM $lists_table l
             LEFT JOIN $li li ON li.list_id = l.id
             GROUP BY l.id
             ORDER BY l.name ASC LIMIT 1000", ARRAY_A
        );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Contacts', 'wp-email-campaigns') . '</h1>';

        echo '<div id="wpec-bulkbar" class="wpec-card" style="display:none;align-items:center;gap:8px;">';
echo '<div id="wpec-bulkbar" class="wpec-card" style="display:none;align-items:center;gap:8px;">';
echo '<label style="margin-right:8px;">'.esc_html__('Move selected to','wp-email-campaigns').'</label>';
echo '<select id="wpec-bulk-dest" style="min-width:240px">';
echo '<option value="">'.esc_html__('— Select —','wp-email-campaigns').'</option>';
foreach ( $lists as $l ) {
    printf('<option value="list:%d">%s</option>', (int)$l['id'], esc_html($l['name'].' ('.$l['cnt'].')'));
}
echo '<option value="status:unsubscribed">→ '.esc_html__('Do Not Send','wp-email-campaigns').'</option>';
echo '<option value="status:bounced">→ '.esc_html__('Bounced','wp-email-campaigns').'</option>';
echo '<option value="status:active">→ '.esc_html__('Remove DND/Bounced (Active)','wp-email-campaigns').'</option>';
echo '</select> ';
echo '<button class="button" id="wpec-bulk-apply" disabled>'.esc_html__('Apply','wp-email-campaigns').'</button> ';
echo '<button class="button button-secondary" id="wpec-bulk-delete" disabled>'.esc_html__('Delete selected','wp-email-campaigns').'</button> ';
echo '<span class="wpec-loader" id="wpec-bulk-loader" style="display:none"></span>';
echo '</div>';



        // Controls: columns toggle + filters + export
        echo '<div id="wpec-contacts-controls" class="wpec-card">';

        echo '<div class="wpec-controls-top">';
        echo '<div class="wpec-export-wrap"><button class="button" id="wpec-export-contacts">'.esc_html__('Export CSV (filtered)','wp-email-campaigns').'</button></div>';
        echo '</div>';

        // Column chooser
        echo '<details class="wpec-columns-toggle"><summary>'.esc_html__('Show more columns','wp-email-campaigns').'</summary>';
        echo '<div class="wpec-columns-grid">';
        $cols = [
            'company_name'=>'Company name','company_employees'=>'Employees','company_annual_revenue'=>'Annual revenue',
            'contact_number'=>'Contact number','job_title'=>'Job title','industry'=>'Industry',
            'country'=>'Country','state'=>'State','city'=>'City','postal_code'=>'Postal code','status'=>'Status','created_at'=>'Created'
        ];
        foreach ( $cols as $key=>$label ) {
            printf('<label><input type="checkbox" class="wpec-col-toggle" value="%s"> %s</label>', esc_attr($key), esc_html($label));
        }
        echo '</div></details>';

        // Filters (Select2-enhanced)
        echo '<div class="wpec-filters">';
        echo '<div class="wpec-filter-row">';
        echo '<label>'.esc_html__('Search','wp-email-campaigns').'<br><input type="search" id="wpec-f-search" class="regular-text" placeholder="'.esc_attr__('Name or email','wp-email-campaigns').'"></label>';
        echo '<label>'.esc_html__('Company name','wp-email-campaigns').'<br><select id="wpec-f-company" multiple class="wpec-s2" data-placeholder="'.esc_attr__('Select companies…','wp-email-campaigns').'">';
        foreach ( $companies as $v ) { echo '<option value="'.esc_attr($v).'">'.esc_html($v).'</option>'; } echo '</select></label>';
        echo '<label>'.esc_html__('City','wp-email-campaigns').'<br><select id="wpec-f-city" multiple class="wpec-s2" data-placeholder="'.esc_attr__('Select cities…','wp-email-campaigns').'">';
        foreach ( $cities as $v ) { echo '<option value="'.esc_attr($v).'">'.esc_html($v).'</option>'; } echo '</select></label>';
        echo '<label>'.esc_html__('State','wp-email-campaigns').'<br><select id="wpec-f-state" multiple class="wpec-s2" data-placeholder="'.esc_attr__('Select states…','wp-email-campaigns').'">';
        foreach ( $states as $v ) { echo '<option value="'.esc_attr($v).'">'.esc_html($v).'</option>'; } echo '</select></label>';
        echo '<label>'.esc_html__('Country','wp-email-campaigns').'<br><select id="wpec-f-country" multiple class="wpec-s2" data-placeholder="'.esc_attr__('Select countries…','wp-email-campaigns').'">';
        foreach ( $countries as $v ) { echo '<option value="'.esc_attr($v).'">'.esc_html($v).'</option>'; } echo '</select></label>';
        echo '</div>';

        echo '<div class="wpec-filter-row">';
        echo '<label>'.esc_html__('Job title','wp-email-campaigns').'<br><select id="wpec-f-job" multiple class="wpec-s2" data-placeholder="'.esc_attr__('Select jobs…','wp-email-campaigns').'">';
        foreach ( $jobs as $v ) { echo '<option value="'.esc_attr($v).'">'.esc_html($v).'</option>'; } echo '</select></label>';
        echo '<label>'.esc_html__('Postal code','wp-email-campaigns').'<br><select id="wpec-f-postcode" multiple class="wpec-s2" data-placeholder="'.esc_attr__('Select postcodes…','wp-email-campaigns').'">';
        foreach ( $postcodes as $v ) { echo '<option value="'.esc_attr($v).'">'.esc_html($v).'</option>'; } echo '</select></label>';
        echo '<label>'.esc_html__('List name','wp-email-campaigns').'<br><select id="wpec-f-list" multiple class="wpec-s2" data-placeholder="'.esc_attr__('Select lists…','wp-email-campaigns').'">';
        foreach ( $lists as $l ) {
            printf('<option value="%d">%s (%s)</option>', (int)$l['id'], esc_html($l['name']), number_format_i18n((int)$l['cnt']));
        }
        echo '</select></label>';

        echo '<label>'.esc_html__('Employees','wp-email-campaigns').'<br>';
        echo '<div class="wpec-number-range">';
        echo '<input type="number" id="wpec-f-emp-min" placeholder="≥ min" min="0"> ';
        echo '<input type="number" id="wpec-f-emp-max" placeholder="≤ max" min="0">';
        echo '</div></label>';

        echo '<label>'.esc_html__('Annual revenue','wp-email-campaigns').'<br>';
        echo '<div class="wpec-number-range">';
        echo '<input type="number" id="wpec-f-rev-min" placeholder="≥ min" min="0"> ';
        echo '<input type="number" id="wpec-f-rev-max" placeholder="≤ max" min="0">';
        echo '</div></label>';
        echo '</div>'; // row

        echo '<div class="wpec-filter-actions">';
        echo '<button class="button button-primary" id="wpec-f-apply">'.esc_html__('Apply filters','wp-email-campaigns').'</button> ';
        echo '<button class="button" id="wpec-f-reset">'.esc_html__('Reset','wp-email-campaigns').'</button>';
        echo '</div>';

        echo '</div>'; // filters
        echo '</div>'; // card

        // Table + pagination
        echo '<div id="wpec-contacts-table-wrap" class="wpec-card">';
        echo '<div class="wpec-table-scroll"><table class="widefat striped" id="wpec-contacts-table">';
       echo '<thead><tr>';
        echo '<th style="width:24px"><input type="checkbox" id="wpec-master-cb"></th>';
        echo '<th>'.esc_html__('ID','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Full name','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Email','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('List(s)','wp-email-campaigns').'</th>';
        echo '</tr></thead>';

        echo '<tbody></tbody>';
        echo '</table></div>';

        echo '<div class="wpec-pager">';
        echo '<button class="button" id="wpec-page-prev" disabled>&laquo; ' . esc_html__('Prev','wp-email-campaigns') . '</button>';
        echo '<span id="wpec-page-numbers"></span>';
        echo '<button class="button" id="wpec-page-next" disabled>' . esc_html__('Next','wp-email-campaigns') . ' &raquo;</button>';
        echo '<select id="wpec-page-size"><option value="25">25</option><option value="50" selected>50</option><option value="100">100</option></select>';
        echo '<span id="wpec-page-info"></span>';
        echo '</div>';
        echo '</div>'; // table wrap

        echo '</div>'; // wrap
    }
/** Special “lists” based on status: donotsend (=unsubscribed) and bounced */
public function render_status_list( $status_slug ) {
    if ( ! Helpers::user_can_manage() ) { wp_die( 'Denied' ); }

    $title      = $status_slug === 'bounced' ? __( 'Bounced', 'wp-email-campaigns' ) : __( 'Do Not Send', 'wp-email-campaigns' );
    $status_val = $status_slug === 'bounced' ? 'bounced' : 'unsubscribed';

    echo '<div class="wrap" id="wpec-contacts-app" data-page="special" data-status="'.esc_attr($status_val).'">';
    echo '<h1>'.esc_html( $title ).'</h1>';

    // Help
    echo '<p class="description">'.esc_html__( 'This is a non-deletable list. You can add or remove contacts here; deleting contacts is disabled.', 'wp-email-campaigns' ).'</p>';

    // Toolbar: add by email + remove selected (move out)
    echo '<div class="wpec-card" style="display:flex; gap:8px; align-items:center;">';
    echo '<button type="button" class="button" id="wpec-status-add">'.esc_html__('Add contacts (by email)','wp-email-campaigns').'</button>';
    echo '<button type="button" class="button" id="wpec-status-remove" disabled>'.esc_html__('Remove selected from this list','wp-email-campaigns').'</button>';
    echo '<span class="wpec-loader" id="wpec-status-loader" style="display:none"></span>';
    echo '</div>';

    // Simple add modal (hidden; JS toggles)
    echo '<div id="wpec-modal-overlay" style="display:none"></div>';
    echo '<div id="wpec-modal" class="wpec-modal" style="display:none"><div class="wpec-modal-inner">';
    echo '<button class="wpec-modal-close" aria-label="Close">&times;</button>';
    echo '<h2>'.esc_html__('Add contacts to list','wp-email-campaigns').'</h2>';
    echo '<p>'.esc_html__('Paste emails separated by comma or newline. Only existing contacts will be updated.','wp-email-campaigns').'</p>';
    echo '<textarea id="wpec-status-emails" rows="8" style="width:100%"></textarea>';
    echo '<p><button class="button button-primary" id="wpec-status-save">'.esc_html__('Add','wp-email-campaigns').'</button></p>';
    echo '</div></div>';

    // Table (checkboxes + View details only — NO delete)
    echo '<div id="wpec-contacts-table-wrap" class="wpec-card" data-initial="1">';
    echo '<div class="wpec-table-scroll"><table class="widefat striped" id="wpec-contacts-table">';
    echo '<thead><tr>';
    echo '<th style="width:24px"><input type="checkbox" id="wpec-master-cb"></th>';
    echo '<th>'.esc_html__('ID','wp-email-campaigns').'</th>';
    echo '<th>'.esc_html__('Full name','wp-email-campaigns').'</th>';
    echo '<th>'.esc_html__('Email','wp-email-campaigns').'</th>';
    echo '<th>'.esc_html__('Actions','wp-email-campaigns').'</th>';
    echo '</tr></thead><tbody></tbody></table></div>';

    echo '<div class="wpec-pager">';
    echo '<button class="button" id="wpec-page-prev" disabled>&laquo; ' . esc_html__('Prev','wp-email-campaigns') . '</button>';
    echo '<span id="wpec-page-numbers"></span>';
    echo '<button class="button" id="wpec-page-next" disabled>' . esc_html__('Next','wp-email-campaigns') . ' &raquo;</button>';
    echo '<select id="wpec-page-size"><option value="25">25</option><option value="50" selected>50</option><option value="100">100</option></select>';
    echo '</div>';

    echo '</div>'; // table wrap
    echo '</div>'; // wrap
}

    private function render_multi_select( $id, $values ) {
        // (no longer used for All Contacts – kept for compatibility elsewhere if needed)
        $html  = '<input type="search" class="wpec-ms-search" data-target="#'.$id.'" placeholder="'.esc_attr__('Type to filter…','wp-email-campaigns').'" />';
        $html .= '<select id="'.$id.'" multiple size="5" style="min-width:220px;max-width:100%;">';
        foreach ( (array)$values as $val ) { $html .= '<option value="'.esc_attr($val).'">'.esc_html($val).'</option>'; }
        $html .= '</select>';
        return $html;
    }

    // ===================== PER-LIST CONTACTS =====================
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
        $dupes_url  = admin_url('edit.php?post_type=email_campaign&page=wpec-duplicates');

        echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export this list to CSV', 'wp-email-campaigns' ) . '</a> ';
        echo '<a class="button" href="' . esc_url( $dupes_url ) . '">' . esc_html__( 'View Duplicates (All Lists)', 'wp-email-campaigns' ) . '</a></p>';

        echo '<div class="wpec-dup-toolbar" style="margin:10px 0;">';
        echo '<button id="wpec-list-bulk-delete" class="button" disabled>' . esc_html__( 'Delete selected from this list', 'wp-email-campaigns' ) . '</button> ';
        echo '<span class="wpec-loader" id="wpec-list-bulk-loader" style="display:none;"></span>';
        echo '</div>';
        echo '<div id="wpec-list-bulk-progress" style="display:none;"><div class="wpec-progress"><span id="wpec-list-progress-bar" style="width:0%"></span></div><p id="wpec-list-progress-text"></p></div>';

        $table = new WPEC_List_Items_Table( $list_id );
        $table->prepare_items();
        echo '<form id="wpec-list-form" method="get">';
        echo '<input type="hidden" name="post_type" value="email_campaign" />';
        echo '<input type="hidden" name="page" value="wpec-contacts" />';
        echo '<input type="hidden" name="view" value="list" />';
        echo '<input type="hidden" name="list_id" value="' . (int) $list_id . '" />';
        $table->search_box( __( 'Search Contacts', 'wp-email-campaigns' ), 'wpecli' );
        $table->display();
        echo '</form></div>';
    }

    // ===================== CONTACT DETAIL =====================
    private function render_contact_detail( $contact_id ) {
        if ( ! Helpers::user_can_manage() ) { wp_die( 'Denied' ); }
        if ( ! $contact_id ) { echo '<div class="notice notice-error"><p>Invalid contact.</p></div>'; return; }

        $db = Helpers::db();
        $ct = Helpers::table('contacts');
        $li = Helpers::table('list_items');
        $lists = Helpers::table('lists');

        $row = $db->get_row( $db->prepare("SELECT * FROM $ct WHERE id=%d", $contact_id), ARRAY_A );
        if ( ! $row ) { echo '<div class="notice notice-error"><p>Contact not found.</p></div>'; return; }

        $contacts_url = function($param, $value){
            $url = add_query_arg( [
                'post_type' => 'email_campaign',
                'page'      => 'wpec-all-contacts',
            ], admin_url('edit.php') );
            $url = add_query_arg( [ $param => $value ], $url );
            return $url;
        };

        $facet = function($label, $value, $param) use ($contacts_url) {
            if ($value === '' || is_null($value)) {
                printf('<tr><th style="width:220px">%s</th><td><em>-</em></td></tr>', esc_html($label));
            } else {
                $link = $contacts_url($param, $value);
                printf('<tr><th style="width:220px">%s</th><td><a href="%s">%s</a></td></tr>',
                    esc_html($label), esc_url($link), esc_html($value));
            }
        };

        echo '<div class="wrap"><h1>'.esc_html__('Contact Detail','wp-email-campaigns').'</h1>';
        echo '<table class="widefat striped" style="max-width:900px">';
        $facet('First name', $row['first_name'], 'first_name');
        $facet('Last name',  $row['last_name'],  'last_name');
        printf('<tr><th style="width:220px">%s</th><td>%s</td></tr>', esc_html__('Email','wp-email-campaigns'), esc_html($row['email']));
        $facet('Company name', $row['company_name'], 'company_name');
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Company number of employees','wp-email-campaigns'), esc_html($row['company_employees'] ?? ''));
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Company annual revenue','wp-email-campaigns'), esc_html($row['company_annual_revenue'] ?? ''));
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Contact number','wp-email-campaigns'), esc_html($row['contact_number'] ?? ''));
        $facet('Job title', $row['job_title'], 'job_title');
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Industry','wp-email-campaigns'), esc_html($row['industry'] ?? ''));
        $facet('Country', $row['country'], 'country');
        $facet('State',   $row['state'],   'state');
        $facet('City',    $row['city'],    'city');
        $facet('Postal code', $row['postal_code'], 'postal_code');
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Status','wp-email-campaigns'), esc_html(ucfirst($row['status'])));
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Created','wp-email-campaigns'), esc_html($row['created_at']));
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Updated','wp-email-campaigns'), esc_html($row['updated_at']));
        echo '</table>';

        $memberships = $db->get_results( $db->prepare(
            "SELECT l.id, l.name, li.created_at
             FROM $li li INNER JOIN $lists l ON l.id=li.list_id
             WHERE li.contact_id=%d ORDER BY li.created_at DESC", $contact_id
        ), ARRAY_A );
        echo '<h2 style="margin-top:20px">'.esc_html__('Lists','wp-email-campaigns').'</h2>';
        if ( empty($memberships) ) {
            echo '<p><em>'.esc_html__('This contact is not in any list.','wp-email-campaigns').'</em></p>';
        } else {
            echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>'.esc_html__('List','wp-email-campaigns').'</th><th>'.esc_html__('Added','wp-email-campaigns').'</th></tr></thead><tbody>';
            foreach ( $memberships as $m ) {
                $url = add_query_arg( [
                    'post_type' => 'email_campaign',
                    'page'      => 'wpec-contacts',
                    'view'      => 'list',
                    'list_id'   => (int)$m['id'],
                ], admin_url('edit.php') );
                printf('<tr><td><a href="%s">%s</a></td><td>%s</td></tr>',
                    esc_url($url), esc_html($m['name'].' (#'.$m['id'].')'), esc_html($m['created_at'])
                );
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    // ===================== DUPLICATES =====================
    private function render_duplicates( $list_id = 0 ) {
        if ( ! Helpers::user_can_manage() ) { wp_die( 'Denied' ); }

        $title = $list_id ? sprintf( __( 'Duplicates — List #%d', 'wp-email-campaigns' ), $list_id )
                          : __( 'Duplicates — All Lists', 'wp-email-campaigns' );

        echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';

        echo '<div class="wpec-dup-toolbar" style="margin:10px 0;">';
        echo '<button id="wpec-dup-bulk-delete" class="button" disabled>' . esc_html__( 'Delete selected from current lists', 'wp-email-campaigns' ) . '</button> ';
        echo '<span class="wpec-loader" id="wpec-dup-bulk-loader" style="display:none;"></span>';
        echo '</div>';
        echo '<div id="wpec-dup-bulk-progress" style="display:none;"><div class="wpec-progress"><span id="wpec-dup-progress-bar" style="width:0%"></span></div><p id="wpec-dup-progress-text"></p></div>';

        $table = new WPEC_Duplicates_Table( $list_id );
        $table->prepare_items();
        echo '<form id="wpec-dup-form" method="get">';
        echo '<input type="hidden" name="post_type" value="email_campaign" />';
        echo '<input type="hidden" name="page" value="wpec-contacts" />'; // legacy in-table actions reuse
        echo '<input type="hidden" name="view" value="dupes' . ( $list_id ? '_list' : '' ) . '" />';
        if ( $list_id ) { echo '<input type="hidden" name="list_id" value="' . (int) $list_id . '" />'; }
        $table->search_box( __( 'Search email/name', 'wp-email-campaigns' ), 'wpecdup' );
        $table->display();
        echo '</form></div>';
    }
    public function render_duplicates_page() { $this->render_duplicates(0); }

    // ===================== EXPORTS =====================
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
        fclose($out); exit;
    }

    /** Export All Contacts (filtered) via AJAX */
    public function ajax_contacts_export() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_die( 'Denied' );

        global $wpdb;
        $ct  = Helpers::table('contacts');
        $li  = Helpers::table('list_items');
        $ls  = Helpers::table('lists');

        // Collect filters (POST)
        $get_multi = function($key){
            $vals = isset($_POST[$key]) ? (array) $_POST[$key] : [];
            $vals = array_filter(array_map('sanitize_text_field', $vals), function($v){ return $v !== ''; });
            return array_values(array_unique($vals));
        };
        $search   = sanitize_text_field($_POST['search'] ?? '');
        $company  = $get_multi('company_name');
        $city     = $get_multi('city');
        $state    = $get_multi('state');
        $country  = $get_multi('country');
        $job      = $get_multi('job_title');
        $postcode = $get_multi('postal_code');
        $list_ids = array_map('absint', $get_multi('list_ids'));
        $emp_min  = isset($_POST['emp_min']) && $_POST['emp_min'] !== '' ? (int)$_POST['emp_min'] : null;
        $emp_max  = isset($_POST['emp_max']) && $_POST['emp_max'] !== '' ? (int)$_POST['emp_max'] : null;
        $rev_min  = isset($_POST['rev_min']) && $_POST['rev_min'] !== '' ? (int)$_POST['rev_min'] : null;
        $rev_max  = isset($_POST['rev_max']) && $_POST['rev_max'] !== '' ? (int)$_POST['rev_max'] : null;

        $where = ['1=1']; $args=[];
        if ( $search ) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)";
            $args[] = $like; $args[] = $like; $args[] = $like;
        }
        $in = function($vals,$col) use (&$where,&$args){ if($vals){ $where[]="($col IN (".implode(',',array_fill(0,count($vals),'%s'))."))"; foreach($vals as $v){$args[]=$v;} } };
        $in($company,'c.company_name'); $in($city,'c.city'); $in($state,'c.state'); $in($country,'c.country'); $in($job,'c.job_title'); $in($postcode,'c.postal_code');
        if ( $emp_min !== null ) { $where[] = "c.company_employees >= %d"; $args[] = $emp_min; }
        if ( $emp_max !== null ) { $where[] = "c.company_employees <= %d"; $args[] = $emp_max; }
        if ( $rev_min !== null ) { $where[] = "c.company_annual_revenue >= %d"; $args[] = $rev_min; }
        if ( $rev_max !== null ) { $where[] = "c.company_annual_revenue <= %d"; $args[] = $rev_max; }

        $join_list = '';
        if ( !empty($list_ids) ) {
            $join_list = "INNER JOIN $li li0 ON li0.contact_id = c.id AND li0.list_id IN (".implode(',',array_fill(0,count($list_ids),'%d')).")";
            foreach($list_ids as $lid){ $args[] = $lid; }
        }

        $where_sql = implode(' AND ', $where);
        $select = "SELECT c.id, c.first_name, c.last_name, c.email, c.company_name, c.company_employees, c.company_annual_revenue, c.contact_number, c.job_title, c.industry, c.country, c.state, c.city, c.postal_code, c.status, c.created_at, c.updated_at,
            (SELECT GROUP_CONCAT(DISTINCT l.name ORDER BY li.created_at DESC SEPARATOR ', ')
             FROM $li li INNER JOIN $ls l ON l.id=li.list_id WHERE li.contact_id=c.id) AS lists
        FROM $ct c
        $join_list
        WHERE $where_sql
        ORDER BY c.id DESC";

        nocache_headers();
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename=contacts-filtered-' . date('Ymd-His') . '.csv' );
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','First name','Last name','Email','Company','Employees','Annual revenue','Contact number','Job title','Industry','Country','State','City','Postal code','Status','Created','Updated','Lists']);

        $chunk = 5000;
        $offset = 0;
        do {
            $sql = $select . " LIMIT %d OFFSET %d";
            $rows = $wpdb->get_results( $wpdb->prepare($sql, array_merge($args, [$chunk, $offset]) ), ARRAY_A );
            foreach ( (array)$rows as $r ) { fputcsv($out, $r); }
            $count = count($rows);
            $offset += $chunk;
            if ( $count < $chunk ) break;
            if ( function_exists('flush') ) { @flush(); }
        } while ( true );

        fclose($out); exit;
    }
// Bulk set/unset status for selected IDs
public function ajax_status_bulk_update() {
    check_ajax_referer( 'wpec_admin', 'nonce' );
    if ( ! Helpers::user_can_manage() ) wp_send_json_error(['message'=>'Denied']);

    $ids    = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('absint', $_POST['ids']) : [];
    $mode   = sanitize_key($_POST['mode'] ?? 'remove'); // 'add' -> set to status; 'remove' -> set back to active
    $status = sanitize_key($_POST['status'] ?? 'unsubscribed'); // 'unsubscribed' or 'bounced'

    if ( empty($ids) ) wp_send_json_error(['message'=>'No contacts']);

    $ct = Helpers::table('contacts');
    $db = Helpers::db();

    if ( $mode === 'add' ) {
        $updated = $db->query( "UPDATE $ct SET status='".esc_sql($status)."' WHERE id IN (".implode(',', $ids ).")" );
    } else {
        $updated = $db->query( "UPDATE $ct SET status='active' WHERE id IN (".implode(',', $ids ).")" );
    }
    wp_send_json_success([ 'updated' => (int)$updated ]);
}

// Add to special list via emails (existing contacts only)
public function ajax_status_add_by_email() {
    check_ajax_referer( 'wpec_admin', 'nonce' );
    if ( ! Helpers::user_can_manage() ) wp_send_json_error(['message'=>'Denied']);

    $status = sanitize_key($_POST['status'] ?? 'unsubscribed');
    $raw    = trim( (string)($_POST['emails'] ?? '') );
    if ( $raw === '' ) wp_send_json_error(['message'=>'No emails provided']);

    $emails = preg_split('/[\s,;]+/', $raw);
    $emails = array_values( array_unique( array_filter( array_map( 'sanitize_email', $emails ), 'is_email' ) ) );
    if ( empty( $emails ) ) wp_send_json_error(['message'=>'No valid emails']);

    $ct = Helpers::table('contacts');
    $db = Helpers::db();

    $placeholders = implode( ',', array_fill(0, count($emails), '%s') );
    $ids = $db->get_col( $db->prepare( "SELECT id FROM $ct WHERE email IN ($placeholders)", $emails ) );

    $updated = 0;
    if ( $ids ) {
        $updated = $db->query( "UPDATE $ct SET status='".esc_sql($status)."' WHERE id IN (".implode(',', array_map('intval',$ids)).")" );
    }

    wp_send_json_success([
        'found'   => count($ids),
        'updated' => (int)$updated,
        'skipped' => count($emails) - count($ids),
    ]);
}

    // ===================== BULK OPS (All Contacts) =====================
    public function ajax_contacts_bulk_delete() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error(['message'=>'Denied']);

        $ids = isset($_POST['contact_ids']) ? array_map('absint', (array)$_POST['contact_ids']) : [];
        if (empty($ids)) wp_send_json_error(['message'=>'No contacts selected']);

        $db = Helpers::db();
        $ct = Helpers::table('contacts');
        $li = Helpers::table('list_items');
        $du = Helpers::table('dupes');
        $ls = Helpers::table('lists');

        // Increment deleted count per list for mappings removed
        $maps = $db->get_results( "SELECT list_id, COUNT(*) cnt FROM $li WHERE contact_id IN (".implode(',', array_fill(0,count($ids),'%d')).") GROUP BY list_id", ARRAY_A, $ids );
        foreach ( $maps as $m ) {
            $db->query( $db->prepare("UPDATE $ls SET deleted = COALESCE(deleted,0)+%d WHERE id=%d", (int)$m['cnt'], (int)$m['list_id']) );
        }

        // Delete mappings, dupes, then contacts
        $db->query( "DELETE FROM $li WHERE contact_id IN (".implode(',', array_fill(0,count($ids),'%d')).")" , $ids );
        $db->query( "DELETE FROM $du WHERE contact_id IN (".implode(',', array_fill(0,count($ids),'%d')).")" , $ids );
        $in = implode(',', array_fill(0, count($ids), '%d'));
        $db->query( $db->prepare( "DELETE FROM $ct WHERE id IN ($in)", $ids ) );

        wp_send_json_success(['deleted'=>count($ids)]);
    }

    public function ajax_contacts_bulk_move() {
    check_ajax_referer( 'wpec_admin', 'nonce' );
    if ( ! Helpers::user_can_manage() ) wp_send_json_error(['message'=>'Denied']);

    $ids     = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('absint', $_POST['ids']) : [];
    $list_id = absint($_POST['list_id'] ?? 0);
    if ( empty($ids) || ! $list_id ) wp_send_json_error(['message'=>'Bad params']);

    $db = Helpers::db();
    $li = Helpers::table('list_items');
    $now = Helpers::now();

    $mapped = 0;
    foreach ( $ids as $cid ) {
        $exists = $db->get_var( $db->prepare( "SELECT id FROM $li WHERE list_id=%d AND contact_id=%d", $list_id, $cid ) );
        if ( ! $exists ) {
            $db->insert( $li, [
                'list_id' => $list_id,
                'contact_id' => $cid,
                'is_duplicate_import' => 0,
                'created_at' => $now,
            ] );
            $mapped++;
        }
    }
    wp_send_json_success([ 'mapped' => $mapped ]);
}

    // ===================== AJAX Contacts directory query =====================
    public function ajax_contacts_query() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error(['message'=>'Denied']);

        global $wpdb;
        $ct  = Helpers::table('contacts');
        $li  = Helpers::table('list_items');
        $ls  = Helpers::table('lists');

        $page     = max(1, (int)($_POST['page'] ?? 1));
        $per_page = min(200, max(1, (int)($_POST['per_page'] ?? 50)));

        $search   = sanitize_text_field($_POST['search'] ?? '');

        $multi = function($key){
            $vals = $_POST[$key] ?? [];
            if (!is_array($vals)) $vals = [$vals];
            $vals = array_filter(array_map('sanitize_text_field', $vals), function($v){ return $v !== ''; });
            return array_values(array_unique($vals));
        };

        $company  = $multi('company_name');
        $city     = $multi('city');
        $state    = $multi('state');
        $country  = $multi('country');
        $job      = $multi('job_title');
        $postcode = $multi('postal_code');
        $list_ids = array_map('absint', $multi('list_ids'));

        $emp_min  = isset($_POST['emp_min']) && $_POST['emp_min'] !== '' ? (int)$_POST['emp_min'] : null;
        $emp_max  = isset($_POST['emp_max']) && $_POST['emp_max'] !== '' ? (int)$_POST['emp_max'] : null;
        $rev_min  = isset($_POST['rev_min']) && $_POST['rev_min'] !== '' ? (int)$_POST['rev_min'] : null;
        $rev_max  = isset($_POST['rev_max']) && $_POST['rev_max'] !== '' ? (int)$_POST['rev_max'] : null;

        $allowed_cols = [
            'company_name','company_employees','company_annual_revenue','contact_number',
            'job_title','industry','country','state','city','postal_code','status','created_at'
        ];
        $cols = $multi('cols');
        $cols = array_values(array_intersect($cols, $allowed_cols));

        $where = ['1=1'];
        $args  = [];

        if ( $search ) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)";
            $args[] = $like; $args[] = $like; $args[] = $like;
        }

        $in_clause = function($vals, $col) use (&$where, &$args) {
            if (!empty($vals)) {
                $placeholders = implode(',', array_fill(0, count($vals), '%s'));
                $where[] = "($col IN ($placeholders))";
                foreach ($vals as $v) { $args[] = $v; }
            }
        };

        $in_clause($company,  'c.company_name');
        $in_clause($city,     'c.city');
        $in_clause($state,    'c.state');
        $in_clause($country,  'c.country');
        $in_clause($job,      'c.job_title');
        $in_clause($postcode, 'c.postal_code');

        if ( $emp_min !== null ) { $where[] = "c.company_employees >= %d"; $args[] = $emp_min; }
        if ( $emp_max !== null ) { $where[] = "c.company_employees <= %d"; $args[] = $emp_max; }
        if ( $rev_min !== null ) { $where[] = "c.company_annual_revenue >= %d"; $args[] = $rev_min; }
        if ( $rev_max !== null ) { $where[] = "c.company_annual_revenue <= %d"; $args[] = $rev_max; }

        $join_list = '';
        if ( !empty($list_ids) ) {
            $place = implode(',', array_fill(0, count($list_ids), '%d'));
            $join_list = "INNER JOIN $li li0 ON li0.contact_id = c.id AND li0.list_id IN ($place)";
            foreach ($list_ids as $lid) { $args[] = $lid; }
        }

        $select_cols = "c.id, CONCAT_WS(' ', c.first_name, c.last_name) AS full_name, c.email";
        foreach ( $cols as $cname ) { $select_cols .= ", c." . $cname; }
        $select_cols .= ", (SELECT GROUP_CONCAT(DISTINCT l.name ORDER BY li.created_at DESC SEPARATOR ', ')
                            FROM $li li INNER JOIN $ls l ON l.id=li.list_id
                            WHERE li.contact_id=c.id) AS lists";

        $where_sql = implode(' AND ', $where);

        // Count distinct contacts
        $count_sql = "SELECT COUNT(DISTINCT c.id)
                      FROM $ct c
                      $join_list
                      WHERE $where_sql";
        $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );

        // Data query
        $offset = ($page - 1) * $per_page;
        $data_sql = "SELECT $select_cols
                     FROM $ct c
                     $join_list
                     WHERE $where_sql
                     GROUP BY c.id
                     ORDER BY c.id DESC
                     LIMIT %d OFFSET %d";
        $args_data = array_merge( $args, [ $per_page, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $args_data ), ARRAY_A );

        wp_send_json_success( [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => max(1, (int)ceil($total / $per_page)),
        ] );
    }

    public function admin_post_list_upload() {
        if ( ! Helpers::user_can_manage() ) wp_die( 'Denied' );
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if ( ! wp_verify_nonce( $nonce, 'wpec_admin' ) ) wp_die( 'Bad nonce' );

        $mode = sanitize_text_field($_POST['list_mode'] ?? 'new');
        $existing_list_id = absint($_POST['existing_list_id'] ?? 0);
        $name = sanitize_text_field( $_POST['list_name'] ?? '' );
        if ( $mode === 'new' && ! $name ) wp_die( 'List name required' );
        if ( empty($_FILES['file']['tmp_name']) ) wp_die( 'No file' );

        $result = $this->handle_upload_to_csv_path( $name, $_FILES['file'], $existing_list_id );
        if ( is_wp_error( $result ) ) { wp_die( $result->get_error_message() ); }

        // Redirect back to Import page with resume flag
        $url = add_query_arg( [
            'post_type'         => 'email_campaign',
            'page'              => 'wpec-import',
            'wpec_start_import' => (int) $result['list_id'],
        ], admin_url( 'edit.php' ) );
        wp_safe_redirect( $url ); exit;
    }

    public function admin_post_delete_list_mapping() {
        if ( ! Helpers::user_can_manage() ) wp_die( 'Denied' );
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';
        if ( ! wp_verify_nonce( $nonce, 'wpec_delete_list_mapping' ) ) wp_die( 'Bad nonce' );
        $list_id = absint( $_GET['list_id'] ?? 0 );
        $contact_id = absint( $_GET['contact_id'] ?? 0 );
        if ( ! $list_id || ! $contact_id ) wp_die( 'Bad params' );

        $db = Helpers::db();
        $li = Helpers::table('list_items');
        $dupes = Helpers::table('dupes');
        $deleted = $db->delete( $li, [ 'list_id' => $list_id, 'contact_id' => $contact_id ] );
        $db->delete( $dupes, [ 'list_id' => $list_id, 'contact_id' => $contact_id ] );
        if ( $deleted ) {
            $lists = Helpers::table('lists');
            $db->query( $db->prepare( "UPDATE $lists SET deleted = COALESCE(deleted,0)+1 WHERE id=%d", $list_id ) );
        }

        wp_safe_redirect( wp_get_referer() ?: admin_url('edit.php?post_type=email_campaign&page=wpec-contacts') ); exit;
    }

    public function ajax_delete_list_mapping() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error( [ 'message' => 'Denied' ] );

        $list_id = absint( $_POST['list_id'] ?? 0 );
        $contact_id = absint( $_POST['contact_id'] ?? 0 );
        if ( ! $list_id || ! $contact_id ) wp_send_json_error( [ 'message' => 'Bad params' ] );

        $db = Helpers::db();
        $li = Helpers::table('list_items');
        $dupes = Helpers::table('dupes');
        $deleted = $db->delete( $li, [ 'list_id' => $list_id, 'contact_id' => $contact_id ] );
        $db->delete( $dupes, [ 'list_id' => $list_id, 'contact_id' => $contact_id ] );
        if ( $deleted ) {
            $lists = Helpers::table('lists');
            $db->query( $db->prepare( "UPDATE $lists SET deleted = COALESCE(deleted,0)+1 WHERE id=%d", $list_id ) );
        }
        wp_send_json_success( [ 'deleted' => (int) $deleted ] );
    }

    // Return live metrics for a list (for dropdown)
    public function ajax_list_metrics() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error(['message'=>'Denied']);
        $list_id = absint($_POST['list_id'] ?? 0);
        if ( ! $list_id ) wp_send_json_error(['message'=>'Bad list id']);

        $db = Helpers::db();
        $lists = Helpers::table('lists');
        $li    = Helpers::table('list_items');
        $dupes = Helpers::table('dupes');

        $row = $db->get_row( $db->prepare("SELECT imported, invalid, duplicates, deleted, manual_added, last_invalid FROM $lists WHERE id=%d", $list_id ), ARRAY_A );
        if ( ! $row ) wp_send_json_error(['message'=>'List not found']);

        $total = (int) $db->get_var( $db->prepare("SELECT COUNT(*) FROM $li WHERE list_id=%d", $list_id ) );
        $duplicates_current = (int) $db->get_var( $db->prepare("SELECT COUNT(*) FROM $dupes WHERE list_id=%d", $list_id ) );

        wp_send_json_success([
            'imported'   => (int)$row['imported'],           // cumulative imported via file uploads
            'duplicates' => $duplicates_current,             // current duplicates count
            'not_uploaded_last' => (int)$row['last_invalid'],// invalid rows in the last import only
            'deleted'    => (int)$row['deleted'],            // deletions via UI
            'manual_added'=> (int)$row['manual_added'],      // manual adds via modal
            'total'      => $total                           // current list size
        ]);
    }

    // Manual contact create (no duplicate emails)
    public function ajax_contact_create() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error(['message'=>'Denied']);

        $db = Helpers::db();
        $ct = Helpers::table('contacts'); $li = Helpers::table('list_items'); $lists = Helpers::table('lists');

        $req = function($k){ return sanitize_text_field($_POST[$k] ?? ''); };
        $first = $req('first_name'); $last = $req('last_name'); $email = sanitize_email($_POST['email'] ?? '');
        if ( empty($first) || empty($last) || !is_email($email) ) wp_send_json_error(['message'=>'First name, Last name, and a valid Email are required']);

        // Disallow duplicate emails entirely
        $existing_id = $db->get_var( $db->prepare("SELECT id FROM $ct WHERE email=%s", $email) );
        if ( $existing_id ) {
            wp_send_json_error(['message'=>'This email already exists in contacts.']);
        }

        $data = [
            'first_name'=>$first, 'last_name'=>$last, 'email'=>$email,
            'company_name'=>$req('company_name') ?: null,
            'company_employees'=> ($_POST['company_employees'] ?? '') !== '' ? intval($_POST['company_employees']) : null,
            'company_annual_revenue'=> ($_POST['company_annual_revenue'] ?? '') !== '' ? intval($_POST['company_annual_revenue']) : null,
            'contact_number'=>$req('contact_number') ?: null,
            'job_title'=>$req('job_title') ?: null,
            'industry'=>$req('industry') ?: null,
            'country'=>$req('country') ?: null,
            'state'=>$req('state') ?: null,
            'city'=>$req('city') ?: null,
            'postal_code'=>$req('postal_code') ?: null,
        ];

        $db->insert( $ct, array_merge( $data, [
            'name'=>trim($first.' '.$last),
            'status'=>'active', 'created_at'=>Helpers::now(), 'updated_at'=>null, 'last_campaign_id'=>null
        ] ) );
        $contact_id = (int) $db->insert_id;

        // Optional: inline create list
        $list_id = absint($_POST['list_id'] ?? 0);
        $new_list_name = $req('new_list_name');
        if ( ! $list_id && $new_list_name ) {
            $db->insert( $lists, [
                'name'=>$new_list_name, 'status'=>'ready',
                'created_at'=>Helpers::now(), 'updated_at'=>null,
                'source_filename'=>null,'file_path'=>null,'file_pointer'=>null,'header_map'=>null,
                'total'=>0,'imported'=>0,'invalid'=>0,'duplicates'=>0,'deleted'=>0,'manual_added'=>0,'last_invalid'=>0
            ] );
            $list_id = (int)$db->insert_id;
        }

        $mapped = false;
        if ( $list_id ) {
            $exists = $db->get_var( $db->prepare("SELECT id FROM $li WHERE list_id=%d AND contact_id=%d", $list_id, $contact_id) );
            if ( ! $exists ) {
                $db->insert( $li, [ 'list_id'=>$list_id, 'contact_id'=>$contact_id, 'is_duplicate_import'=>0, 'created_at'=>Helpers::now() ] );
                // increment manual_added
                $db->query( $db->prepare( "UPDATE $lists SET manual_added = COALESCE(manual_added,0)+1 WHERE id=%d", $list_id ) );
                $mapped = true;
            }
        }
        wp_send_json_success([ 'contact_id'=>$contact_id, 'mapped'=>$mapped?1:0, 'list_id'=>$list_id ]);
    }

    public function ajax_list_create() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error(['message'=>'Denied']);

        $name = sanitize_text_field($_POST['list_name'] ?? '');
        if ( ! $name ) wp_send_json_error(['message'=>'List name required']);

        $db = Helpers::db(); $lists = Helpers::table('lists');
        $db->insert( $lists, [
            'name'=>$name, 'status'=>'ready', 'created_at'=>Helpers::now(), 'updated_at'=>null,
            'source_filename'=>null,'file_path'=>null,'file_pointer'=>null,'header_map'=>null,
            'total'=>0,'imported'=>0,'invalid'=>0,'duplicates'=>0,'deleted'=>0,'manual_added'=>0,'last_invalid'=>0
        ] );
        $id = (int)$db->insert_id;
        wp_send_json_success([ 'list_id'=>$id, 'name'=>$name ]);
    }

    private function handle_upload_to_csv_path( $list_name, $file_arr, $existing_list_id = 0 ) {
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
                return new \WP_Error( 'phpsreadsheet_missing', 'PhpSpreadsheet not installed.' );
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

        if ( $existing_list_id ) {
            // Append into existing list: mark importing & reset session counters for last_invalid
            $db->update( $lists, [
                'status'         => 'importing',
                'updated_at'     => Helpers::now(),
                'source_filename'=> $orig,
                'file_path'      => $dest,
                'file_pointer'   => 0,
                'header_map'     => null,
                'last_invalid'   => 0, // reset last import invalid count
            ], [ 'id' => (int)$existing_list_id ] );
            return [ 'list_id'  => (int)$existing_list_id, 'csv_path' => $dest ];
        }

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
            'deleted'        => 0,
            'manual_added'   => 0,
            'last_invalid'   => 0,
        ] );
        $list_id = (int) $db->insert_id;

        return [ 'list_id'  => $list_id, 'csv_path' => $dest ];
    }

    public function ajax_list_upload() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error( [ 'message' => 'Denied' ] );

        $mode = sanitize_text_field($_POST['list_mode'] ?? 'new');
        $existing_list_id = absint($_POST['existing_list_id'] ?? 0);
        $name = sanitize_text_field( $_POST['list_name'] ?? '' );
        if ( $mode === 'new' && ! $name ) wp_send_json_error(['message'=>'List name required']);
        if ( empty($_FILES['file']['tmp_name']) ) wp_send_json_error( [ 'message' => 'No file' ] );

        $result = $this->handle_upload_to_csv_path( $name, $_FILES['file'], $existing_list_id );
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
        if ( ! $handle ) { wp_send_json_error( [ 'message' => 'Unable to open file' ] ); }

        $header_map = $list->header_map ? json_decode( $list->header_map, true ) : null;
        $starting_chunk = false;
        if ( empty( $header_map ) ) {
            $starting_chunk = true;
            $header_row = fgetcsv( $handle );
            if ( ! $header_row ) { fclose($handle); wp_send_json_error( [ 'message' => 'File missing header row' ] ); }
            $map = Helpers::parse_header_map( $header_row );
            if ( ! Helpers::required_fields_present( $map ) ) { fclose($handle); wp_send_json_error( [ 'message' => 'Required headers missing: First name, Last name, Email' ] ); }
            $header_map = $map;
            $after_header_ptr = ftell( $handle );
            $db->update( $lists, [
                'header_map'   => wp_json_encode( $header_map ),
                'file_pointer' => $after_header_ptr,
                'updated_at'   => Helpers::now(),
                'last_invalid' => 0,
            ], [ 'id' => $list_id ] );
        }

        $pointer = (int) $db->get_var( $db->prepare( "SELECT file_pointer FROM $lists WHERE id=%d", $list_id ) );
        if ( $pointer > 0 ) { fseek( $handle, $pointer ); }

        $processed = 0; $valid_imports = 0; $invalid_rows  = 0; $dup_count = 0;

        while ( $processed < self::BATCH_SIZE && ( $row = fgetcsv( $handle ) ) !== false ) {
            $processed++;
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

            if ( empty($data['email']) || ! is_email($data['email']) || empty($data['first_name']) || empty($data['last_name']) ) { $invalid_rows++; continue; }

            $contact_id = $db->get_var( $db->prepare( "SELECT id FROM $ct WHERE email=%s", $data['email'] ) );
            $is_duplicate = false;

            if ( $contact_id ) {
                $is_duplicate = true;
                $existing = $db->get_row( $db->prepare("SELECT * FROM $ct WHERE id=%d", $contact_id), ARRAY_A );
                $updates = [];
                foreach ( ['first_name','last_name','company_name','company_employees','company_annual_revenue','contact_number','job_title','industry','country','state','city','postal_code'] as $k ) {
                    if ( $data[$k] !== null && $data[$k] !== '' && ( ! isset($existing[$k]) || $existing[$k] === '' || is_null($existing[$k]) ) ) {
                        $updates[$k] = $data[$k];
                    }
                }
                if ( ! empty($updates) ) { $updates['updated_at'] = Helpers::now(); $db->update( $ct, $updates, [ 'id' => $contact_id ] ); }
            } else {
                $db->insert( $ct, [
                    'email' => $data['email'], 'name'=>trim( $data['first_name'].' '.$data['last_name'] ),
                    'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                    'company_name' => $data['company_name'], 'company_employees' => $data['company_employees'],
                    'company_annual_revenue' => $data['company_annual_revenue'], 'contact_number' => $data['contact_number'],
                    'job_title' => $data['job_title'], 'industry' => $data['industry'], 'country' => $data['country'],
                    'state' => $data['state'], 'city' => $data['city'], 'postal_code' => $data['postal_code'],
                    'status'=> 'active','created_at'=>Helpers::now(),'updated_at'=>null,'last_campaign_id'=>null
                ] );
                $contact_id = (int) $db->insert_id;
            }

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
                $invalid_rows++; continue;
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

        $new_pointer = ftell( $handle ); $eof = feof( $handle ); fclose( $handle );

        $total     = (int) $list->total + $processed;
        $imported  = (int) $list->imported + $valid_imports;
        $invalid_t = (int) $list->invalid + $invalid_rows;
        $dupes_t   = (int) $list->duplicates + $dup_count;

        // accumulate last_invalid for this import session
        $last_invalid = (int) $list->last_invalid + $invalid_rows;

        $data_upd = [
            'file_pointer' => $new_pointer, 'total' => $total, 'imported' => $imported,
            'invalid' => $invalid_t, 'duplicates' => $dupes_t, 'updated_at' => Helpers::now(),
            'last_invalid' => $last_invalid,
        ];
        if ( $eof ) {
            $data_upd['status'] = 'ready'; @unlink( $path );
            $data_upd['file_path'] = null; $data_upd['file_pointer'] = null;
        }
        $db->update( $lists, $data_upd, [ 'id' => $list_id ] );

        $done = $eof;
        $progress = $done ? 100 : ( $total > 0 ? min( 99, round( ( $imported / max(1,$total) ) * 100 ) ) : 0 );

        wp_send_json_success( [
            'done'     => $done,
            'progress' => $progress,
            'stats'    => [ 'imported'=>$imported, 'invalid'=>$invalid_t, 'duplicates'=>$dupes_t, 'total'=>$total ],
            'list_id'  => $list_id,
        ] );
    }
// ===================== Modals used on Import page =====================
    private function render_add_contact_modal( $lists ) {
        echo '<div id="wpec-modal-overlay" style="display:none;"></div>';
        echo '<div id="wpec-modal" class="wpec-modal" style="display:none">';
        echo '<div class="wpec-modal-inner">';
        echo '<button class="wpec-modal-close" type="button">&times;</button>';
        echo '<h2>'.esc_html__('Add contact','wp-email-campaigns').'</h2>';
        echo '<form id="wpec-add-contact-form">';
        echo '<input type="hidden" name="nonce" value="'.esc_attr(wp_create_nonce('wpec_admin')).'">';
        echo '<p><label>'.esc_html__('First name','wp-email-campaigns').'<br><input name="first_name" type="text" required></label></p>';
        echo '<p><label>'.esc_html__('Last name','wp-email-campaigns').'<br><input name="last_name" type="text" required></label></p>';
        echo '<p><label>'.esc_html__('Email','wp-email-campaigns').'<br><input name="email" type="email" required></label></p>';
        echo '<p><label>'.esc_html__('Company name','wp-email-campaigns').'<br><input name="company_name" type="text"></label></p>';
        echo '<p class="wpec-two"><label>'.esc_html__('Employees','wp-email-campaigns').'<br><input name="company_employees" type="number" min="0"></label><label>'.esc_html__('Annual revenue','wp-email-campaigns').'<br><input name="company_annual_revenue" type="number" min="0"></label></p>';
        echo '<p class="wpec-two"><label>'.esc_html__('Contact number','wp-email-campaigns').'<br><input name="contact_number" type="text"></label><label>'.esc_html__('Job title','wp-email-campaigns').'<br><input name="job_title" type="text"></label></p>';
        echo '<p class="wpec-two"><label>'.esc_html__('Industry','wp-email-campaigns').'<br><input name="industry" type="text"></label><label>'.esc_html__('Country','wp-email-campaigns').'<br><input name="country" type="text"></label></p>';
        echo '<p class="wpec-two"><label>'.esc_html__('State','wp-email-campaigns').'<br><input name="state" type="text"></label><label>'.esc_html__('City','wp-email-campaigns').'<br><input name="city" type="text"></label></p>';
        echo '<p><label>'.esc_html__('Postal code','wp-email-campaigns').'<br><input name="postal_code" type="text"></label></p>';

        echo '<h3>'.esc_html__('List','wp-email-campaigns').'</h3>';
        echo '<p><select name="list_id" id="wpec-add-contact-list"><option value="">'.esc_html__('— None —','wp-email-campaigns').'</option>';
        foreach ( $lists as $l ) {
            printf('<option value="%d">%s (%s)</option>', (int)$l['id'], esc_html($l['name']), number_format_i18n((int)$l['cnt']));
        }
        echo '</select></p>';
        echo '<p><label><input type="checkbox" id="wpec-add-contact-newlist-toggle"> '.esc_html__('Or create new list','wp-email-campaigns').'</label></p>';
        echo '<div id="wpec-add-contact-newlist" style="display:none"><input type="text" name="new_list_name" placeholder="'.esc_attr__('New list name','wp-email-campaigns').'"></div>';

        echo '<p><button class="button button-primary" type="submit">'.esc_html__('Save','wp-email-campaigns').'</button> <span class="wpec-loader" id="wpec-add-contact-loader" style="display:none;"></span></p>';
        echo '</form>';
        echo '</div></div>';

        // Create list modal
        echo '<div id="wpec-modal-list" class="wpec-modal" style="display:none">';
        echo '<div class="wpec-modal-inner">';
        echo '<button class="wpec-modal-close" type="button">&times;</button>';
        echo '<h2>'.esc_html__('Create list','wp-email-campaigns').'</h2>';
        echo '<form id="wpec-create-list-form">';
        echo '<input type="hidden" name="nonce" value="'.esc_attr(wp_create_nonce('wpec_admin')).'">';
        echo '<p><label>'.esc_html__('List name','wp-email-campaigns').'<br><input name="list_name" type="text" required></label></p>';
        echo '<p><button class="button button-primary" type="submit">'.esc_html__('Create','wp-email-campaigns').'</button> <span class="wpec-loader" id="wpec-create-list-loader" style="display:none;"></span></p>';
        echo '</form>';
        echo '</div></div>';
    }
    private function render_create_list_modal() { /* included in render_add_contact_modal */ }
}

// ---------- Lists table ----------
class WPEC_Lists_Table extends \WP_List_Table {
    public function get_columns() {
        return [
            'name'       => __( 'Name', 'wp-email-campaigns' ),
            'status'     => __( 'Status', 'wp-email-campaigns' ),
            'metrics'    => __( 'Counts', 'wp-email-campaigns' ),
            'created_at' => __( 'Created', 'wp-email-campaigns' ),
            'actions'    => __( 'Actions', 'wp-email-campaigns' ),
        ];
    }
    public function get_primary_column_name() { return 'name'; }
    public function prepare_items() {
        global $wpdb;
        $lists = Helpers::table('lists');
        $per_page = 20; $paged = max(1, (int)($_GET['paged'] ?? 1)); $offset = ( $paged - 1 ) * $per_page;
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $where = 'WHERE 1=1'; $args = [];
        if ( $search ) { $where .= " AND (name LIKE %s)"; $args[] = '%'.$wpdb->esc_like($search).'%'; }
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $lists $where", $args ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $lists $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, [ $per_page, $offset ] ) ), ARRAY_A );
        $this->items = $rows; $this->_column_headers = [ $this->get_columns(), [], [] ];
        $this->set_pagination_args( [ 'total_items'=>$total, 'per_page'=>$per_page, 'total_pages'=>ceil($total/$per_page) ] );
    }
    public function column_metrics( $item ) {
        return sprintf('<button type="button" class="button wpec-toggle-counts" data-list-id="%d">%s</button>',
            (int)$item['id'], esc_html__('View counts','wp-email-campaigns') );
    }
    public function column_default( $item, $col ) {
        switch ( $col ) {
            case 'name': return esc_html($item['name']);
            case 'status': return esc_html( ucfirst($item['status']) );
            case 'created_at': return esc_html( $item['created_at'] );
            case 'actions':
                $view = add_query_arg( [
                    'post_type' => 'email_campaign', 'page' => 'wpec-contacts', 'view' => 'list', 'list_id' => (int)$item['id'],
                ], admin_url('edit.php') );
                $dupes = admin_url('edit.php?post_type=email_campaign&page=wpec-duplicates');
                return sprintf('<a class="button" href="%s">%s</a> <a class="button" href="%s">%s</a>',
                    esc_url($view), esc_html__('View','wp-email-campaigns'),
                    esc_url($dupes), esc_html__('View Duplicates','wp-email-campaigns')
                );
        }
        return '';
    }
    public function no_items() { _e( 'No lists found.', 'wp-email-campaigns' ); }
}

// ---------- Per-list contacts table ----------
class WPEC_List_Items_Table extends \WP_List_Table {
    protected $list_id;
    public function __construct( $list_id ) { parent::__construct(['plural'=>'list_contacts','singular'=>'list_contact']); $this->list_id = (int) $list_id; }
    public function get_columns() {
        return [
            'cb'         => '<input type="checkbox" />',
            'email'      => __( 'Email', 'wp-email-campaigns' ),
            'name'       => __( 'Name', 'wp-email-campaigns' ),
            'status'     => __( 'Status', 'wp-email-campaigns' ),
            'created_at' => __( 'Imported At', 'wp-email-campaigns' ),
            'actions'    => __( 'Actions', 'wp-email-campaigns' ),
        ];
    }
    public function get_primary_column_name() { return 'email'; }
    protected function column_cb( $item ) {
        $value = (int)$this->list_id . ':' . (int)$item['contact_id'];
        return sprintf('<input type="checkbox" name="ids[]" value="%s"/>', esc_attr($value));
    }
    public function prepare_items() {
        global $wpdb;
        $li = Helpers::table('list_items'); $ct = Helpers::table('contacts');
        $per_page = 50; $paged = max(1, (int)($_GET['paged'] ?? 1)); $offset = ( $paged - 1 ) * $per_page;
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $where = "WHERE li.list_id=%d"; $args  = [ $this->list_id ];
        if ( $search ) {
            $where .= " AND (c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)";
            $args[] = '%'.$wpdb->esc_like($search).'%'; $args[] = '%'.$wpdb->esc_like($search).'%'; $args[] = '%'.$wpdb->esc_like($search).'%';
        }
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $li li INNER JOIN $ct c ON c.id=li.contact_id $where", $args ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id AS contact_id, c.email, CONCAT_WS(' ', c.first_name, c.last_name) AS name, c.status, li.created_at, li.is_duplicate_import
             FROM $li li INNER JOIN $ct c ON c.id=li.contact_id
             $where ORDER BY li.id DESC LIMIT %d OFFSET %d", array_merge( $args, [ $per_page, $offset ] ) ), ARRAY_A );
        $this->items = $rows; $this->_column_headers = [ $this->get_columns(), [], [] ];
        $this->set_pagination_args( [ 'total_items'=>$total, 'per_page'=>$per_page, 'total_pages'=>ceil( $total / $per_page ) ] );
    }
    public function column_email( $item ) {
        $pill = !empty($item['is_duplicate_import']) ? ' <span class="wpec-pill wpec-pill-dup">'.esc_html__('Duplicate','wp-email-campaigns').'</span>' : '';
        return esc_html( $item['email'] ) . $pill;
    }
    public function column_actions( $item ) {
        $url = add_query_arg( [
            'post_type' => 'email_campaign','page'=>'wpec-contacts','view'=>'contact','contact_id'=> (int)$item['contact_id']
        ], admin_url('edit.php'));
        return sprintf('<a class="button button-small" href="%s">%s</a>', esc_url($url), esc_html__('View detail','wp-email-campaigns'));
    }
    public function column_default( $item, $col ) { return esc_html( $item[$col] ?? '' ); }
}

// ---------- Duplicates table (with Duplicate Count) ----------
class WPEC_Duplicates_Table extends \WP_List_Table {
    protected $list_id;
    public function __construct( $list_id = 0 ) { parent::__construct( [ 'plural' => 'duplicates', 'singular' => 'duplicate' ] ); $this->list_id = (int) $list_id; }
    public function get_columns() {
        return [
            'cb'            => '<input type="checkbox" />',
            'email'         => __( 'Email', 'wp-email-campaigns' ),
            'first_name'    => __( 'First name', 'wp-email-campaigns' ),
            'last_name'     => __( 'Last name', 'wp-email-campaigns' ),
            'dup_count'     => __( 'Duplicate count', 'wp-email-campaigns' ),
            'current_list'  => __( 'Current list', 'wp-email-campaigns' ),
            'imported_at'   => __( 'Last imported date', 'wp-email-campaigns' ),
            'previous_list' => __( 'Previous list', 'wp-email-campaigns' ),
            'actions'       => __( 'Actions', 'wp-email-campaigns' ),
        ];
    }
    public function get_primary_column_name() { return 'email'; }
    protected function column_cb( $item ) {
        $value = (int)$item['list_id'] . ':' . (int)$item['contact_id'];
        return sprintf('<input type="checkbox" name="ids[]" value="%s"/>', esc_attr($value));
    }
    public function prepare_items() {
        global $wpdb;
        $dupes = Helpers::table('dupes'); $lists = Helpers::table('lists'); $li = Helpers::table('list_items');
        $per_page = 30; $paged = max(1, (int)($_GET['paged'] ?? 1)); $offset = ( $paged - 1 ) * $per_page;
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $where = "WHERE 1=1"; $args = [];
        if ( $this->list_id ) { $where .= " AND d.list_id=%d"; $args[] = $this->list_id; }
        if ( $search ) {
            $where .= " AND (d.email LIKE %s OR d.first_name LIKE %s OR d.last_name LIKE %s)";
            $args[] = '%'.$wpdb->esc_like($search).'%'; $args[] = '%'.$wpdb->esc_like($search).'%'; $args[] = '%'.$wpdb->esc_like($search).'%';
        }
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $dupes d $where", $args ) );
        $sql = "
            SELECT d.id, d.email, d.first_name, d.last_name, d.created_at AS imported_at,
                   d.list_id, l.name AS current_list,
                   (
                     SELECT COUNT(*) FROM $dupes d2 WHERE d2.contact_id = d.contact_id
                   ) AS dup_count,
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
        $this->items = $rows; $this->_column_headers = [ $this->get_columns(), [], [] ];
        $this->set_pagination_args( [ 'total_items' => $total, 'per_page' => $per_page, 'total_pages' => ceil( $total / $per_page ) ] );
    }
    public function column_default( $item, $col ) {
        switch ( $col ) {
            case 'email':
            case 'first_name':
            case 'last_name':
                return esc_html( $item[ $col ] ?? '' );
            case 'dup_count':
                return esc_html( (string)($item['dup_count'] ?? '0') );
            case 'current_list':
                $url = add_query_arg( [ 'post_type'=>'email_campaign','page'=>'wpec-contacts','view'=>'list','list_id'=>(int)$item['list_id'] ], admin_url('edit.php') );
                return sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($item['current_list'] ?? ('#'.(int)$item['list_id'])) );
            case 'previous_list':
                return esc_html( $item['previous_list'] ?? '' );
            case 'imported_at':
                return esc_html( $item['imported_at'] ?? '' );
            case 'actions':
                $view = add_query_arg( [ 'post_type'=>'email_campaign','page'=>'wpec-contacts','view'=>'contact','contact_id'=>(int)$item['contact_id'] ], admin_url('edit.php') );
                $btn = sprintf(
                    '<button type="button" class="button button-small wpec-del-dup" data-list-id="%d" data-contact-id="%d">%s</button> <a class="button button-small" href="%s">%s</a>',
                    (int)$item['list_id'], (int)$item['contact_id'],
                    esc_html__('Delete from current list', 'wp-email-campaigns'),
                    esc_url($view), esc_html__('View detail','wp-email-campaigns')
                );
                return $btn;
        }
        return '';
    }
    public function no_items() { _e( 'No duplicates found.', 'wp-email-campaigns' ); }
}
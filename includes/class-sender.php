<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Sender {
    public function init() {
        // Admin screen
        add_action('admin_menu', [ $this, 'add_send_screen' ]);

        // AJAX: test + queue + status + cancel
        add_action('wp_ajax_wpec_send_test',      [ $this, 'ajax_send_test' ]);
        add_action('wp_ajax_wpec_campaign_queue', [ $this, 'ajax_campaign_queue' ]);
        add_action('wp_ajax_wpec_campaign_status',[ $this, 'ajax_campaign_status' ]);
        add_action('wp_ajax_wpec_campaign_cancel',[ $this, 'ajax_campaign_cancel' ]);

        // Cron (every minute)
        add_action('wpec_send_tick', [ $this, 'cron_process_queue' ]);
        $this->maybe_schedule_cron();

        // Make sure queue table exists
        $this->maybe_create_queue_table();
    }
    public function add_send_screen() {
        $parent = 'edit.php?post_type=email_campaign';
        $cap = method_exists(Helpers::class, 'manage_cap') ? Helpers::manage_cap() : 'manage_options';

        add_submenu_page(
            $parent,
            __('Send', 'wp-email-campaigns'),
            __('Send', 'wp-email-campaigns'),
            $cap,
            'wpec-send',
            [ $this, 'render_send_screen' ],
            8
        );
    }

    public function render_send_screen() {
        if ( ! Helpers::user_can_manage() ) { wp_die('Denied'); }

        global $wpdb;
        $lists = $wpdb->get_results( "SELECT id, name FROM " . Helpers::table('lists') . " ORDER BY name ASC LIMIT 1000", ARRAY_A );

        echo '<div class="wrap"><h1>'.esc_html__('Compose & Send','wp-email-campaigns').'</h1>';

        echo '<div class="wpec-card" style="max-width:980px;padding:16px;">';

        // Subject / From / Body
        echo '<p><label><strong>'.esc_html__('Subject','wp-email-campaigns').'</strong><br>';
        echo '<input type="text" id="wpec-subject" class="regular-text" style="width:100%"></label></p>';

        echo '<div style="display:flex;gap:12px">';
        echo '  <p style="flex:1"><label><strong>'.esc_html__('From name','wp-email-campaigns').'</strong><br>';
        echo '  <input type="text" id="wpec-from-name" class="regular-text" style="width:100%"></label></p>';
        echo '  <p style="flex:1"><label><strong>'.esc_html__('From email','wp-email-campaigns').'</strong><br>';
        echo '  <input type="email" id="wpec-from-email" class="regular-text" style="width:100%"></label></p>';
        echo '</div>';

        echo '<p><label><strong>'.esc_html__('HTML body','wp-email-campaigns').'</strong><br>';
        echo '<textarea id="wpec-body" rows="12" style="width:100%"></textarea></label></p>';

        // Lists select
        echo '<p><label><strong>'.esc_html__('Recipient lists','wp-email-campaigns').'</strong><br>';
        echo '<select id="wpec-list-ids" multiple style="min-width:420px;height:140px">';
        foreach ( (array)$lists as $l ) {
            printf('<option value="%d">%s</option>', (int)$l['id'], esc_html($l['name']));
        }
        echo '</select></p>';

        // Test send
        echo '<div style="display:flex;gap:8px;align-items:center;margin:10px 0">';
        echo '  <input type="email" id="wpec-test-to" placeholder="'.esc_attr__('Test email address','wp-email-campaigns').'" class="regular-text" style="min-width:280px">';
        echo '  <button class="button" id="wpec-send-test">'.esc_html__('Send test','wp-email-campaigns').'</button>';
        echo '  <span class="wpec-loader" id="wpec-test-loader" style="display:none"></span>';
        echo '</div>';

        // Actions
        echo '<div style="display:flex;gap:8px;align-items:center;margin:10px 0">';
        echo '  <button class="button button-primary" id="wpec-queue-campaign">'.esc_html__('Queue Send','wp-email-campaigns').'</button>';
        echo '  <button class="button" id="wpec-cancel-campaign" disabled>'.esc_html__('Cancel job','wp-email-campaigns').'</button>';
        echo '  <span id="wpec-send-status" style="margin-left:8px;color:#555"></span>';
        echo '</div>';

        echo '</div></div>';
    } 
    private function maybe_create_queue_table() {
            global $wpdb;
            $table = $wpdb->prefix . 'wpec_send_queue';
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE IF NOT EXISTS $table (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                campaign_id BIGINT UNSIGNED NOT NULL,
                contact_id BIGINT UNSIGNED NULL,
                email VARCHAR(190) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'queued',
                last_error TEXT NULL,
                scheduled_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY status_sched (status, scheduled_at),
                KEY campaign_status (campaign_id, status),
                KEY email_idx (email)
            ) $charset;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }

      private function maybe_schedule_cron() {
        add_filter('cron_schedules', function($s){
            if ( ! isset($s['minute']) ) {
                $s['minute'] = [ 'interval' => 60, 'display' => __('Every Minute') ];
            }
            return $s;
        });

        if ( ! wp_next_scheduled('wpec_send_tick') ) {
            wp_schedule_event( time() + 60, 'minute', 'wpec_send_tick' );
        }
    }

    public function ajax_send_test() {
        check_ajax_referer('wpec_admin','nonce');
        if ( ! Helpers::user_can_manage() ) wp_send_json_error(['message'=>'Denied']);

        $to         = sanitize_email($_POST['to'] ?? '');
        $subject    = sanitize_text_field($_POST['subject'] ?? '');
        $body_html  = wp_kses_post($_POST['body'] ?? '');
        $from_name  = sanitize_text_field($_POST['from_name'] ?? '');
        $from_email = sanitize_email($_POST['from_email'] ?? '');

        if ( ! $to || ! $subject || ! $body_html ) {
            wp_send_json_error(['message'=>'Missing test fields']);
        }

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        if ( $from_email ) {
            $from = $from_name ? sprintf('%s <%s>',$from_name,$from_email) : $from_email;
            $headers[] = 'From: ' . $from;
            $headers[] = 'Reply-To: ' . $from_email;
        }

        $ok = wp_mail($to, $subject, $body_html, $headers);
        if ( ! $ok ) wp_send_json_error(['message'=>'wp_mail failed']);

        wp_send_json_success(['sent'=>true]);
    }
    public function ajax_campaign_queue() {
        check_ajax_referer('wpec_admin','nonce');
        if ( ! Helpers::user_can_manage() ) wp_send_json_error(['message'=>'Denied']);

        $subject    = sanitize_text_field($_POST['subject'] ?? '');
        $from_name  = sanitize_text_field($_POST['from_name'] ?? '');
        $from_email = sanitize_email($_POST['from_email'] ?? '');
        $body_html  = wp_kses_post($_POST['body'] ?? '');
        $list_ids   = isset($_POST['list_ids']) ? (array) $_POST['list_ids'] : [];

        $list_ids = array_filter(array_map('absint', $list_ids));
        if ( ! $subject || ! $body_html || empty($list_ids) ) {
            wp_send_json_error(['message'=>'Subject, Body, and at least one list are required.']);
        }

        // Create a campaign post as a record
        $post_id = wp_insert_post([
            'post_type'  => 'email_campaign',
            'post_status'=> 'draft', // publish later if you want
            'post_title' => $subject,
            'post_content' => $body_html,
        ], true);
        if ( is_wp_error($post_id) ) wp_send_json_error(['message'=>'Could not create campaign.']);

        update_post_meta($post_id, '_wpec_from_name',  $from_name);
        update_post_meta($post_id, '_wpec_from_email', $from_email);
        update_post_meta($post_id, '_wpec_list_ids',   $list_ids);

        // Build recipient set (unique emails, status=active)
        global $wpdb;
        $ct = Helpers::table('contacts');
        $li = Helpers::table('list_items');

        $place = implode(',', array_fill(0, count($list_ids), '%d'));
        $sql = "SELECT MIN(c.id) AS contact_id, c.email
                FROM $ct c
                INNER JOIN $li li ON li.contact_id=c.id
                WHERE li.list_id IN ($place)
                  AND c.status='active'
                  AND c.email <> ''
                GROUP BY c.email";
        $recips = $wpdb->get_results( $wpdb->prepare($sql, $list_ids), ARRAY_A );

        if ( empty($recips) ) {
            wp_send_json_error(['message'=>'No eligible recipients (active) found in the selected list(s).']);
        }

        // Queue rows—scheduled_at = now; throttling happens in cron by batch size
        $table = $wpdb->prefix . 'wpec_send_queue';
        $now = current_time('mysql');
        $values = [];
        foreach ( $recips as $r ) {
            $values[] = $wpdb->prepare("(%d,%d,%s,%s,%s,%s)",
                $post_id, (int)$r['contact_id'], (string)$r['email'], 'queued', $now, $now
            );
        }
        $chunks = array_chunk($values, 500); // bulk insert in chunks
        foreach ($chunks as $chunk) {
            $wpdb->query("INSERT INTO $table (campaign_id, contact_id, email, status, scheduled_at, created_at) VALUES " . implode(',', $chunk));
        }

        // Mark job started
        update_post_meta($post_id, '_wpec_job_state', 'queued');
        update_post_meta($post_id, '_wpec_job_started_at', time());

        wp_send_json_success([
            'campaign_id' => (int)$post_id,
            'queued'      => (int)count($recips),
            'message'     => 'Queued for background sending.'
        ]);
    }
    public function ajax_campaign_status() {
        check_ajax_referer('wpec_admin','nonce');
        if ( ! Helpers::user_can_manage() ) wp_send_json_error(['message'=>'Denied']);

        $cid = absint($_POST['campaign_id'] ?? 0);
        if ( ! $cid ) wp_send_json_error(['message'=>'Bad campaign id']);

        global $wpdb;
        $table = $wpdb->prefix . 'wpec_send_queue';

        $tot   = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE campaign_id=%d", $cid) );
        $sent  = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE campaign_id=%d AND status='sent'", $cid) );
        $fail  = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE campaign_id=%d AND status='failed'", $cid) );
        $queue = $tot - $sent - $fail;

        $state = get_post_meta($cid, '_wpec_job_state', true) ?: 'queued';

        wp_send_json_success([
            'total' => $tot, 'sent' => $sent, 'failed' => $fail, 'queued' => $queue,
            'state' => $state
        ]);
    }

    public function ajax_campaign_cancel() {
        check_ajax_referer('wpec_admin','nonce');
        if ( ! Helpers::user_can_manage() ) wp_send_json_error(['message'=>'Denied']);

        $cid = absint($_POST['campaign_id'] ?? 0);
        if ( ! $cid ) wp_send_json_error(['message'=>'Bad campaign id']);

        update_post_meta($cid, '_wpec_job_state', 'cancelled');
        wp_send_json_success(['cancelled'=>true]);
    }
    public function cron_process_queue() {
        // Prevent overlap
        if ( get_transient('wpec_send_lock') ) return;
        set_transient('wpec_send_lock', 1, 55);

        global $wpdb;
        $table = $wpdb->prefix . 'wpec_send_queue';

        // Batch size = 30 per minute (≈1/2s average)
        $batch = apply_filters('wpec_send_batch_per_minute', 30);

        // Pick next queued rows whose campaign isn't cancelled
        $rows = $wpdb->get_results("
            SELECT q.*
            FROM $table q
            INNER JOIN {$wpdb->posts} p ON p.ID=q.campaign_id
            LEFT JOIN {$wpdb->postmeta} m ON (m.post_id=p.ID AND m.meta_key='_wpec_job_state')
            WHERE q.status='queued'
              AND (m.meta_value IS NULL OR m.meta_value NOT IN ('cancelled'))
            ORDER BY q.id ASC
            LIMIT {$batch}
        ", ARRAY_A);

        if ( empty($rows) ) { delete_transient('wpec_send_lock'); return; }

        foreach ($rows as $r) {
            $ok = $this->deliver_row($r); // send and return bool
            if ( $ok ) {
                $wpdb->update($table, [ 'status'=>'sent', 'last_error'=>null ], [ 'id'=>$r['id'] ], [ '%s','%s' ], [ '%d' ]);
            } else {
                $wpdb->update($table, [ 'status'=>'failed', 'last_error'=>'wp_mail failed' ], [ 'id'=>$r['id'] ], [ '%s','%s' ], [ '%d' ]);
            }
        }

        delete_transient('wpec_send_lock');
    }

    private function deliver_row(array $row): bool {
        $cid = (int)$row['campaign_id'];
        $subject    = get_the_title($cid);
        $body_html  = get_post_field('post_content', $cid);
        $from_name  = get_post_meta($cid, '_wpec_from_name', true );
        $from_email = get_post_meta($cid, '_wpec_from_email', true );
        $to         = $row['email'];

        if ( ! $to || ! $subject || ! $body_html ) return false;

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        if ( $from_email ) {
            $from = $from_name ? sprintf('%s <%s>',$from_name,$from_email) : $from_email;
            $headers[] = 'From: ' . $from;
            $headers[] = 'Reply-To: ' . $from_email;
        }
        return (bool) wp_mail($to, $subject, $body_html, $headers);
    }
  
}

 

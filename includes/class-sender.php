<?php
namespace WPEC;

if ( ! defined('ABSPATH') ) exit;

class Sender {
    public function init() {
        add_action('admin_menu', [ $this, 'add_send_screen' ]);

        // AJAX
        add_action('wp_ajax_wpec_send_test',       [ $this, 'ajax_send_test' ]);
        add_action('wp_ajax_wpec_campaign_queue',  [ $this, 'ajax_campaign_queue' ]);
        add_action('wp_ajax_wpec_campaign_status', [ $this, 'ajax_campaign_status' ]);
        add_action('wp_ajax_wpec_campaign_cancel', [ $this, 'ajax_campaign_cancel' ]);
        add_action('wp_ajax_wpec_campaign_pause',  [ $this, 'ajax_campaign_pause' ]);
        add_action('wp_ajax_wpec_campaign_resume', [ $this, 'ajax_campaign_resume' ]);

        // Cron tick
        add_action('wpec_send_tick', [ $this, 'cron_process_queue' ]);
        $this->maybe_schedule_cron();

        // Tables
        $this->maybe_create_queue_table();
    }

    public function add_send_screen() {
        $parent = 'edit.php?post_type=email_campaign';
        $cap = method_exists(Helpers::class,'manage_cap') ? Helpers::manage_cap() : 'manage_options';
        add_submenu_page(
            $parent,
            __('Send','wp-email-campaigns'),
            __('Send','wp-email-campaigns'),
            $cap,
            'wpec-send',
            [ $this, 'render_send_screen' ],
            6
        );
    }

    public function render_send_screen() {
        if ( ! Helpers::user_can_manage() ) wp_die('Denied');

        global $wpdb;
        $lists = $wpdb->get_results( "SELECT id, name FROM ".Helpers::table('lists')." ORDER BY name ASC LIMIT 1000", ARRAY_A );

        // Optional: load draft to prefill
        $prefill = [
            'id'         => 0,
            'name'       => '',
            'subject'    => '',
            'from_name'  => '',
            'from_email' => '',
            'body_html'  => '',
            'list_ids'   => [],
        ];
        if ( isset($_GET['load_campaign']) && ($cid = absint($_GET['load_campaign'])) ) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wpec_campaigns WHERE id=%d",$cid), ARRAY_A);
            if ($row) {
                $prefill['id']         = (int)$row['id'];
                $prefill['name']       = (string)$row['name'];
                $prefill['subject']    = (string)$row['subject'];
                $prefill['from_name']  = (string)$row['from_name'];
                $prefill['from_email'] = (string)$row['from_email'];
                $prefill['body_html']  = (string)$row['body_html'];
                $prefill['list_ids']   = $wpdb->get_col($wpdb->prepare(
                    "SELECT list_id FROM {$wpdb->prefix}wpec_campaign_lists WHERE campaign_id=%d", $cid
                ));
            }
        }

        echo '<div class="wrap"><h1>'.esc_html__('Compose & Send','wp-email-campaigns').'</h1>';
        echo '<div class="wpec-card" style="max-width:980px;padding:16px;">';

        echo '<input type="hidden" id="wpec-campaign-id" value="'.(int)$prefill['id'].'">';

        echo '<p><label><strong>'.esc_html__('Internal name (optional)','wp-email-campaigns').'</strong><br>';
        echo '<input type="text" id="wpec-name" class="regular-text" style="width:100%" value="'.esc_attr($prefill['name']).'"></label></p>';

        echo '<p><label><strong>'.esc_html__('Subject','wp-email-campaigns').'</strong><br>';
        echo '<input type="text" id="wpec-subject" class="regular-text" style="width:100%" value="'.esc_attr($prefill['subject']).'"></label></p>';

        echo '<div style="display:flex;gap:12px">';
        echo '  <p style="flex:1"><label><strong>'.esc_html__('From name','wp-email-campaigns').'</strong><br>';
        echo '  <input type="text" id="wpec-from-name" class="regular-text" style="width:100%" value="'.esc_attr($prefill['from_name']).'"></label></p>';
        echo '  <p style="flex:1"><label><strong>'.esc_html__('From email','wp-email-campaigns').'</strong><br>';
        echo '  <input type="email" id="wpec-from-email" class="regular-text" style="width:100%" value="'.esc_attr($prefill['from_email']).'"></label></p>';
        echo '</div>';

        echo '<div class="wpec-field"><label><strong>'.esc_html__('Email body (HTML)','wp-email-campaigns').'</strong></label>';
        ob_start();
        wp_editor($prefill['body_html'], 'wpec_camp_html', [
            'textarea_name'=>'wpec_camp_html',
            'editor_height'=> 280,
            'media_buttons'=> true,
            'tinymce'      => true,
            'quicktags'    => true,
        ]);
        echo ob_get_clean();
        echo '</div>';

        echo '<p><label><strong>'.esc_html__('Recipient lists','wp-email-campaigns').'</strong><br>';
        echo '<select id="wpec-list-ids" multiple style="min-width:420px;height:140px">';
        foreach ($lists as $l) {
            $sel = in_array($l['id'], (array)$prefill['list_ids'], true) ? ' selected' : '';
            printf('<option value="%d"%s>%s</option>', (int)$l['id'], $sel, esc_html($l['name']));
        }
        echo '</select></p>';

        echo '<div style="display:flex;gap:8px;align-items:center;margin:10px 0">';
        echo '  <input type="email" id="wpec-test-to" placeholder="'.esc_attr__('Test email address','wp-email-campaigns').'" class="regular-text" style="min-width:280px">';
        echo '  <button class="button" id="wpec-send-test">'.esc_html__('Send test','wp-email-campaigns').'</button>';
        echo '  <span class="wpec-loader" id="wpec-test-loader" style="display:none"></span>';
        echo '</div>';

        echo '<div style="display:flex;gap:8px;align-items:center;margin:10px 0">';
        echo '  <button class="button" id="wpec-save-draft">'.esc_html__('Save draft','wp-email-campaigns').'</button>';
        echo '  <button class="button button-primary" id="wpec-queue-campaign">'.esc_html__('Queue Send','wp-email-campaigns').'</button>';
        echo '  <button class="button" id="wpec-cancel-campaign" disabled>'.esc_html__('Cancel job','wp-email-campaigns').'</button>';
        echo '  <span id="wpec-send-status" style="margin-left:8px;color:#555"></span>';
        echo '</div>';

        echo '</div></div>';
    }

    private function maybe_create_queue_table() {
        global $wpdb;
        $table = $wpdb->prefix.'wpec_send_queue';
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
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private function maybe_schedule_cron() {
        add_filter('cron_schedules', function($s){
            if (!isset($s['minute'])) {
                $s['minute'] = ['interval'=>60,'display'=>__('Every Minute')];
            }
            return $s;
        });
        if (!wp_next_scheduled('wpec_send_tick')) {
            wp_schedule_event(time()+60, 'minute', 'wpec_send_tick');
        }
    }

    // ===== AJAX =====

    public function ajax_send_test() {
        check_ajax_referer('wpec_admin','nonce');
        if (!Helpers::user_can_manage()) wp_send_json_error(['message'=>'Denied']);

        $to         = sanitize_email($_POST['to'] ?? '');
        $subject    = sanitize_text_field($_POST['subject'] ?? '');
        $body_html  = wp_kses_post($_POST['body'] ?? '');
        $from_name  = sanitize_text_field($_POST['from_name'] ?? '');
        $from_email = sanitize_email($_POST['from_email'] ?? '');

        if (!$to || !$subject || !$body_html) wp_send_json_error(['message'=>'Missing test fields']);

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if ($from_email) {
            $from = $from_name ? sprintf('%s <%s>', $from_name, $from_email) : $from_email;
            $headers[] = 'From: '.$from;
            $headers[] = 'Reply-To: '.$from_email;
        }

        $ok = wp_mail($to, $subject, $body_html, $headers);
        if (!$ok) wp_send_json_error(['message'=>'wp_mail failed']);

        wp_send_json_success(['sent'=>true]);
    }

    public function ajax_campaign_queue() {
        check_ajax_referer('wpec_admin','nonce');
        if (!Helpers::user_can_manage()) wp_send_json_error(['message'=>'Denied']);

        global $wpdb;
        $ct = Helpers::table('contacts');
        $li = Helpers::table('list_items');

        $name       = sanitize_text_field($_POST['name'] ?? '');
        $subject    = sanitize_text_field($_POST['subject'] ?? '');
        $from_name  = sanitize_text_field($_POST['from_name'] ?? '');
        $from_email = sanitize_email($_POST['from_email'] ?? '');
        $body_html  = wp_kses_post($_POST['body'] ?? '');
        $list_ids   = isset($_POST['list_ids']) ? array_filter(array_map('absint',(array)$_POST['list_ids'])) : [];
        $campaign_id = absint($_POST['campaign_id'] ?? 0);
        $as_draft    = isset($_POST['save_only']) && $_POST['save_only'] == '1';

        if (!$subject || !$body_html) wp_send_json_error(['message'=>'Subject and Body are required.']);
        if (!$as_draft && empty($list_ids)) wp_send_json_error(['message'=>'At least one list is required.']);

        $cam = $wpdb->prefix.'wpec_campaigns';
        $map = $wpdb->prefix.'wpec_campaign_lists';
        $now = current_time('mysql');

        // Upsert campaign row
        $data = [
            'name'        => $name,
            'subject'     => $subject,
            'from_name'   => $from_name,
            'from_email'  => $from_email,
            'body_html'   => $body_html,
            'updated_at'  => $now,
        ];
        if (!$campaign_id) {
            $data += [
                'status'     => $as_draft ? 'draft' : 'queued',
                'created_at' => $now,
                'published_at' => $as_draft ? null : $now,
            ];
            $wpdb->insert($cam, $data, ['%s','%s','%s','%s','%s','%s','%s','%s']);
            $campaign_id = (int)$wpdb->insert_id;
        } else {
            if ($as_draft) {
                $wpdb->update($cam, $data + ['status'=>'draft'], ['id'=>$campaign_id]);
            } else {
                $wpdb->update($cam, $data + ['status'=>'queued','published_at'=>$now], ['id'=>$campaign_id]);
            }
        }

        // Update list mappings
        if (!empty($list_ids)) {
            $wpdb->delete($map, ['campaign_id'=>$campaign_id], ['%d']);
            foreach ($list_ids as $lid) {
                $wpdb->insert($map, ['campaign_id'=>$campaign_id,'list_id'=>$lid], ['%d','%d']);
            }
        }

        if ($as_draft) {
            wp_send_json_success(['campaign_id'=>$campaign_id, 'message'=>'Draft saved.']);
        }

        // Build recipients (active only)
        $place = implode(',', array_fill(0, count($list_ids), '%d'));
        $sql = "SELECT MIN(c.id) AS contact_id, c.email
                FROM $ct c
                INNER JOIN $li li ON li.contact_id=c.id
                WHERE li.list_id IN ($place)
                  AND c.status='active'
                  AND c.email <> ''
                GROUP BY c.email";
        $recips = $wpdb->get_results($wpdb->prepare($sql, $list_ids), ARRAY_A);

        if (empty($recips)) {
            wp_send_json_error(['message'=>'No eligible recipients (active) found in the selected list(s).']);
        }

        // Queue rows
        $q = $wpdb->prefix.'wpec_send_queue';
        $values = [];
        foreach ($recips as $r) {
            $values[] = $wpdb->prepare("(%d,%d,%s,%s,%s,%s)",
                $campaign_id, (int)$r['contact_id'], (string)$r['email'], 'queued', $now, $now
            );
        }
        foreach (array_chunk($values, 500) as $chunk) {
            $wpdb->query("INSERT INTO $q (campaign_id, contact_id, email, status, scheduled_at, created_at) VALUES ".implode(',', $chunk));
        }
        $wpdb->update($cam, ['queued_count'=>count($recips)], ['id'=>$campaign_id]);

        wp_send_json_success([
            'campaign_id'=>$campaign_id,
            'queued'=>count($recips),
            'message'=>'Queued for background sending.'
        ]);
    }

    public function ajax_campaign_status() {
        check_ajax_referer('wpec_admin','nonce');
        if (!Helpers::user_can_manage()) wp_send_json_error(['message'=>'Denied']);
        global $wpdb;
        $cid = absint($_POST['campaign_id'] ?? 0);
        if (!$cid) wp_send_json_error(['message'=>'Bad campaign id']);

        $q   = $wpdb->prefix.'wpec_send_queue';
        $cam = $wpdb->prefix.'wpec_campaigns';

        $tot   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d", $cid));
        $sent  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND status='sent'", $cid));
        $fail  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND status='failed'", $cid));
        $queue = $tot - $sent - $fail;
        $state = (string)$wpdb->get_var($wpdb->prepare("SELECT status FROM $cam WHERE id=%d", $cid));

        // update rollups
        $wpdb->update($cam, ['sent_count'=>$sent, 'failed_count'=>$fail], ['id'=>$cid]);

        wp_send_json_success(['total'=>$tot,'sent'=>$sent,'failed'=>$fail,'queued'=>$queue,'state'=>$state]);
    }

    public function ajax_campaign_cancel() {
        check_ajax_referer('wpec_admin','nonce');
        if (!Helpers::user_can_manage()) wp_send_json_error(['message'=>'Denied']);
        global $wpdb;
        $cid = absint($_POST['campaign_id'] ?? 0);
        if (!$cid) wp_send_json_error(['message'=>'Bad campaign id']);
        $wpdb->update($wpdb->prefix.'wpec_campaigns', ['status'=>'cancelled'], ['id'=>$cid], ['%s'], ['%d']);
        wp_send_json_success(['cancelled'=>true]);
    }
    public function ajax_campaign_pause() {
        check_ajax_referer('wpec_admin','nonce');
        if (!Helpers::user_can_manage()) wp_send_json_error(['message'=>'Denied']);
        global $wpdb;
        $cid = absint($_POST['campaign_id'] ?? 0);
        if (!$cid) wp_send_json_error(['message'=>'Bad campaign id']);
        $wpdb->update($wpdb->prefix.'wpec_campaigns', ['status'=>'paused'], ['id'=>$cid]);
        wp_send_json_success(['paused'=>true]);
    }
    public function ajax_campaign_resume() {
        check_ajax_referer('wpec_admin','nonce');
        if (!Helpers::user_can_manage()) wp_send_json_error(['message'=>'Denied']);
        global $wpdb;
        $cid = absint($_POST['campaign_id'] ?? 0);
        if (!$cid) wp_send_json_error(['message'=>'Bad campaign id']);
        $wpdb->update($wpdb->prefix.'wpec_campaigns', ['status'=>'queued'], ['id'=>$cid]);
        wp_send_json_success(['resumed'=>true]);
    }

    // ===== CRON =====

    public function cron_process_queue() {
        if ( get_transient('wpec_send_lock') ) return;
        set_transient('wpec_send_lock', 1, 55);

        global $wpdb;
        $q   = $wpdb->prefix.'wpec_send_queue';
        $cam = $wpdb->prefix.'wpec_campaigns';

        // ~30/minute (≈1 email / 2s)
        $batch = (int)apply_filters('wpec_send_batch_per_minute', 30);

        // pick next queued rows for campaigns not paused/cancelled/failed/sent
        $rows = $wpdb->get_results("
            SELECT q.*
            FROM $q q
            INNER JOIN $cam c ON c.id=q.campaign_id
            WHERE q.status='queued'
              AND c.status IN ('queued','sending')
            ORDER BY q.id ASC
            LIMIT {$batch}
        ", ARRAY_A);

        if (empty($rows)) { delete_transient('wpec_send_lock'); return; }

        foreach ($rows as $r) {
            $ok = $this->deliver_row($r);
            if ($ok) {
                $wpdb->update($q, ['status'=>'sent','last_error'=>null], ['id'=>$r['id']], ['%s','%s'], ['%d']);
            } else {
                $wpdb->update($q, ['status'=>'failed','last_error'=>'wp_mail failed'], ['id'=>$r['id']], ['%s','%s'], ['%d']);
            }
        }

        // set campaign status to 'sending' if still has queue
        $ids = array_unique(array_map(function($r){ return (int)$r['campaign_id']; }, $rows));
        foreach ($ids as $cid) {
            $queued_left = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND status='queued'", $cid));
            if ($queued_left > 0) {
                $wpdb->update($cam, ['status'=>'sending'], ['id'=>$cid]);
            } else {
                // finished
                $failed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND status='failed'", $cid));
                $wpdb->update($cam, ['status'=> $failed ? 'failed' : 'sent' ], ['id'=>$cid]);
            }
        }

        delete_transient('wpec_send_lock');
    }

    private function deliver_row(array $row): bool {
        global $wpdb;
        $cam = $wpdb->prefix.'wpec_campaigns';
        $c = $wpdb->get_row($wpdb->prepare("SELECT subject, body_html, from_name, from_email FROM $cam WHERE id=%d", (int)$row['campaign_id']), ARRAY_A);
        if (!$c) return false;

        $subject    = (string)$c['subject'];
        $body_html  = (string)$c['body_html'];
        $from_name  = (string)$c['from_name'];
        $from_email = (string)$c['from_email'];
        $to         = (string)$row['email'];

        if (!$to || !$subject || !$body_html) return false;

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if ($from_email) {
            $from = $from_name ? sprintf('%s <%s>',$from_name,$from_email) : $from_email;
            $headers[] = 'From: '.$from;
            $headers[] = 'Reply-To: '.$from_email;
        }
        return (bool) wp_mail($to, $subject, $body_html, $headers);
    }
}

<?php
namespace WPEC;

if ( ! defined('ABSPATH') ) exit;

class Campaigns {
    public function init() {
        add_action('admin_menu', [ $this, 'add_menu' ]);
        add_action('admin_post_wpec_campaign_duplicate', [ $this, 'handle_duplicate' ]);
    }

    public function add_menu() {
        $parent = 'edit.php?post_type=email_campaign';
        $cap = method_exists(Helpers::class,'manage_cap') ? Helpers::manage_cap() : 'manage_options';

        add_submenu_page(
            $parent,
            __('Campaigns','wp-email-campaigns'),
            __('Campaigns','wp-email-campaigns'),
            $cap,
            'wpec-campaigns',
            [ $this, 'render_list' ],
            7
        );
    }

    public function render_list() {
        if ( ! Helpers::user_can_manage() ) wp_die('Denied');
        global $wpdb;
        $queue_tbl = $wpdb->prefix . 'wpec_send_queue';
        $tbl = $wpdb->prefix.'wpec_campaigns';
        $map = $wpdb->prefix.'wpec_campaign_lists';
        $ls  = Helpers::table('lists');
        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : '';
        if ($view === 'detail') {
            $this->render_campaign_detail( absint($_GET['campaign_id'] ?? 0) );
            return;
        }
        // Filters
        $q    = isset($_GET['q'])    ? sanitize_text_field($_GET['q']) : '';$q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';

        $stat = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $d1   = isset($_GET['d1']) ? sanitize_text_field($_GET['d1']) : '';
        $d2   = isset($_GET['d2']) ? sanitize_text_field($_GET['d2']) : '';

        $where = ['1=1']; $args=[];
        if ($q !== '') {
            $like = '%'.$wpdb->esc_like($q).'%';
            $where[] = "(c.name LIKE %s OR c.subject LIKE %s)";
            $args[] = $like; $args[] = $like;
        }
        if ($stat !== '') { $where[] = "c.status=%s"; $args[] = $stat; }
        if ($d1 !== '')   { $where[] = "c.published_at >= %s"; $args[] = $d1.' 00:00:00'; }
        if ($d2 !== '')   { $where[] = "c.published_at <= %s"; $args[] = $d2.' 23:59:59'; }

        $sql = " 
        SELECT c.*,
       (SELECT GROUP_CONCAT(l.name ORDER BY l.name SEPARATOR ', ')
        FROM $map m INNER JOIN $ls l ON l.id=m.list_id
        WHERE m.campaign_id=c.id) AS list_names,
 
       (SELECT COUNT(*) FROM $queue_tbl WHERE campaign_id=c.id AND status='sent')   AS sent_count_live,
       (SELECT COUNT(*) FROM $queue_tbl WHERE campaign_id=c.id AND status='failed') AS failed_count_live,
       (SELECT COUNT(*) FROM $queue_tbl WHERE campaign_id=c.id AND status='queued') AS queued_count_live
        FROM $tbl c
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(c.published_at, c.updated_at) DESC
        LIMIT 500";

        $rows = $wpdb->get_results($wpdb->prepare($sql,$args), ARRAY_A);

        echo '<div class="wrap"><h1>'.esc_html__('Campaigns','wp-email-campaigns').'</h1>';

        // Toolbar
        echo '<form method="GET" class="wpec-toolbar" style="display:flex;gap:8px;align-items:end;margin:10px 0;">';
        echo '<input type="hidden" name="post_type" value="email_campaign">';
        echo '<input type="hidden" name="page" value="wpec-campaigns">';
        echo '<p><label>'.esc_html__('Search','wp-email-campaigns').'<br><input type="text" name="q" value="'.esc_attr($q).'"></label></p>';
        echo '<p><label>'.esc_html__('Status','wp-email-campaigns').'<br><select name="status"><option value="">—</option>';
        foreach (['draft','queued','sending','paused','sent','cancelled','failed'] as $st) {
            printf('<option value="%s"%s>%s</option>', esc_attr($st), selected($stat,$st,false), esc_html(ucfirst($st)));
        }
        echo '</select></label></p>';
        echo '<p><label>'.esc_html__('From','wp-email-campaigns').'<br><input type="date" name="d1" value="'.esc_attr($d1).'"></label></p>';
        echo '<p><label>'.esc_html__('To','wp-email-campaigns').'<br><input type="date" name="d2" value="'.esc_attr($d2).'"></label></p>';
        $send_url = add_query_arg(['post_type'=>'email_campaign','page'=>'wpec-send'], admin_url('edit.php'));
        echo '<p><button class="button">Filter</button> <a class="button button-primary" href="'.esc_url($send_url).'">'.esc_html__('Send new mail','wp-email-campaigns').'</a></p>';
        echo '</form>';

        // Table
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>'.esc_html__('Name','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Subject','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Lists','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Status','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Sent/Failed','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Published','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Actions','wp-email-campaigns').'</th>';
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="7"><em>'.esc_html__('No campaigns yet.','wp-email-campaigns').'</em></td></tr>';
        } else {
            foreach ($rows as $r) {
               $view_url = add_query_arg(
                    [
                        'post_type'   => 'email_campaign',
                        'page'        => 'wpec-campaigns',
                        'view'        => 'detail',
                        'campaign_id' => (int)$r['id'],
                    ],
                    admin_url('edit.php')
                );

                $dup_url = wp_nonce_url(
                    admin_url('admin-post.php?action=wpec_campaign_duplicate&cid=' . (int)$r['id']),
                    'wpec_admin', 'nonce'
                );

                // Drafts can be continued in Send screen:
                $cont_url = add_query_arg([
                    'post_type' => 'email_campaign',
                    'page'      => 'wpec-send',
                    'load_campaign' => (int)$r['id'],
                ], admin_url('edit.php'));

                $actions = [];
                $actions[] = '<a class="button" href="'.esc_url($view_url).'">'.esc_html__('View','wp-email-campaigns').'</a>';
                $actions[] = '<a class="button" href="'.esc_url($dup_url).'">'.esc_html__('Duplicate','wp-email-campaigns').'</a>';
                if (($r['status'] ?? '') === 'draft') {
                    $actions[] = '<a class="button button-primary" href="'.esc_url($cont_url).'">'.esc_html__('Continue in Send','wp-email-campaigns').'</a>';
                }
                $sent_live   = (int)($r['sent_count_live'] ?? 0);
                $failed_live = (int)($r['failed_count_live'] ?? 0);
                $queued_live = (int)($r['queued_count_live'] ?? 0);
                $raw_status  = (string)($r['status'] ?? 'draft');
                $display_status = $raw_status;
                if ($queued_live === 0 && in_array($raw_status, ['queued','sending','paused'], true)) {
                    $display_status = ($failed_live > 0) ? 'failed' : 'sent';
                }
                printf(
                    '<tr>
                        <td>%s</td><td>%s</td><td>%s</td>
                        <td><span class="wpec-status-pill">%s</span></td>
                        <td>%d / %d</td>
                        <td>%s</td>
                        <td>%s</td>
                    </tr>',
                    
                    esc_html($r['name'] ?: '—'),
                    esc_html($r['subject'] ?: '—'),
                    esc_html($r['list_names'] ?: '—'),
                    esc_html($display_status),
(int)$sent_live, (int)$failed_live,
                    $r['published_at'] ? esc_html($r['published_at']) : '—',
                    implode(' ', $actions)
                );
            }
        }

        echo '</tbody></table>';

      
        echo '</div>';
    }

    public function handle_duplicate() {
        if ( ! Helpers::user_can_manage() ) wp_die('Denied');
        check_admin_referer('wpec_admin','nonce');
        $cid = absint($_GET['cid'] ?? 0);
        if (!$cid) wp_die('Bad id');

        global $wpdb;
        $tbl = $wpdb->prefix.'wpec_campaigns';
        $map = $wpdb->prefix.'wpec_campaign_lists';

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d",$cid), ARRAY_A);
        if (!$row) wp_die('Not found');

        $now = current_time('mysql');
        $ins = [
            'name'        => $row['name'].' (Copy)',
            'subject'     => $row['subject'],
            'from_name'   => $row['from_name'],
            'from_email'  => $row['from_email'],
            'body_html'   => $row['body_html'],
            'status'      => 'draft',
            'queued_count'=> 0,
            'sent_count'  => 0,
            'failed_count'=> 0,
            'options_json'=> $row['options_json'],
            'created_at'  => $now,
            'updated_at'  => $now,
            'published_at'=> null,
        ];
        $wpdb->insert($tbl, $ins, ['%s','%s','%s','%s','%s','%s','%d','%d','%d','%s','%s','%s','%s']);
        $new_id = (int)$wpdb->insert_id;

        // copy list mappings
        $lists = $wpdb->get_col($wpdb->prepare("SELECT list_id FROM $map WHERE campaign_id=%d",$cid));
        if ($lists) {
            foreach ($lists as $lid) {
                $wpdb->insert($map, ['campaign_id'=>$new_id,'list_id'=>(int)$lid], ['%d','%d']);
            }
        }

        
        wp_safe_redirect( add_query_arg(
        [ 'post_type' => 'email_campaign', 'page' => 'wpec-send', 'load_campaign' => $new_id ],
        admin_url('edit.php')
        ) );
        exit;
    }

private function render_campaign_detail( int $cid ) {
    if ( ! Helpers::user_can_manage() ) { wp_die('Denied'); }
    global $wpdb;

    if ( ! $cid ) {
        echo '<div class="wrap"><h1>Campaign</h1><div class="notice notice-error"><p>Invalid campaign.</p></div></div>';
        return;
    }

    $tbl = $wpdb->prefix.'wpec_campaigns';
    $map = $wpdb->prefix.'wpec_campaign_lists';
    $ls  = Helpers::table('lists');
    $q   = $wpdb->prefix.'wpec_send_queue';

    // Campaign row from custom table
    $c = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $cid), ARRAY_A );
    if ( ! $c ) {
        echo '<div class="wrap"><h1>Campaign</h1><div class="notice notice-error"><p>Campaign not found.</p></div></div>';
        return;
    }

    // Lists mapped to this campaign
    $lists = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.id, l.name
         FROM $map m INNER JOIN $ls l ON l.id=m.list_id
         WHERE m.campaign_id=%d
         ORDER BY l.name ASC", $cid
    ), ARRAY_A );

    // Progress (prefer stored counters if present; fallback to live queue counts)
    $hasStored = isset($c['sent_count']) && isset($c['failed_count']) && isset($c['queued_count']);
    if ( $hasStored ) {
        $sent   = (int)$c['sent_count'];
        $failed = (int)$c['failed_count'];
        $queued = (int)$c['queued_count'];
    } else {
        $tot    = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d", $cid) );
        $sent   = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND status='sent'",   $cid) );
        $failed = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND status='failed'", $cid) );
        $queued = max(0, $tot - $sent - $failed);
    }

    $back = add_query_arg(['post_type'=>'email_campaign','page'=>'wpec-campaigns'], admin_url('edit.php'));

    echo '<div class="wrap">';
    echo '  <h1>'.esc_html__('Campaign','wp-email-campaigns').'</h1>';
    echo '  <p><a class="button" href="'.esc_url($back).'">'.esc_html__('← Back to Campaigns','wp-email-campaigns').'</a></p>';

    echo '  <div class="wpec-card" style="max-width:1080px;padding:16px;">';
    echo '    <table class="widefat striped"><tbody>';
    printf('<tr><th style="width:220px">%s</th><td>%s</td></tr>',
        esc_html__('Name','wp-email-campaigns'),   esc_html($c['name'] ?: '—'));
    printf('<tr><th>%s</th><td>%s</td></tr>',
        esc_html__('Subject','wp-email-campaigns'), esc_html($c['subject'] ?: '—'));
    printf('<tr><th>%s</th><td>%s</td></tr>',
        esc_html__('From','wp-email-campaigns'),
        esc_html( trim(($c['from_name']?:'').' <'.$c['from_email'].'>') )
    );
    printf('<tr><th>%s</th><td><span class="wpec-status-pill">%s</span></td></tr>',
        esc_html__('Status','wp-email-campaigns'), esc_html($c['status'] ?: '—'));
    printf('<tr><th>%s</th><td>%s</td></tr>',
        esc_html__('Published','wp-email-campaigns'),
        esc_html( $c['published_at'] ?: '—' )
    );

    // Lists
    $listHtml = '—';
    if ( !empty($lists) ) {
        $links = [];
        foreach ($lists as $l) {
            $url = add_query_arg([
                'post_type' => 'email_campaign',
                'page'      => 'wpec-lists',
                'view'      => 'list',
                'list_id'   => (int)$l['id'],
            ], admin_url('edit.php'));
            $links[] = '<a href="'.esc_url($url).'">'.esc_html($l['name']).'</a>';
        }
        $listHtml = implode(', ', $links);
    }
    printf('<tr><th>%s</th><td>%s</td></tr>',
        esc_html__('Lists','wp-email-campaigns'), $listHtml);

    // Progress
    printf('<tr><th>%s</th><td>%d %s / %d %s / %d %s</td></tr>',
        esc_html__('Progress','wp-email-campaigns'),
        $sent,   esc_html__('sent','wp-email-campaigns'),
        $failed, esc_html__('failed','wp-email-campaigns'),
        $queued, esc_html__('queued','wp-email-campaigns')
    );

    echo '    </tbody></table>';

    echo '    <h2 style="margin-top:18px">'.esc_html__('Preview','wp-email-campaigns').'</h2>';
    echo '    <div class="wpec-campaign-preview" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1080px;overflow:auto">';
    echo          (string)$c['body_html']; // already your saved HTML
    echo '    </div>';

    echo '  </div>';
    echo '</div>';
}


}

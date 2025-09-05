<?php
namespace WPEC;

if ( ! defined('ABSPATH') ) exit;

class Campaigns {
    public function init() {
        add_action('admin_menu', [ $this, 'add_menu' ]);
        add_action('admin_post_wpec_campaign_duplicate', [ $this, 'handle_duplicate' ]);
         add_action('add_meta_boxes', [$this, 'add_metrics_metabox']);
    }

public function add_menu() {
    $cap = method_exists(Helpers::class,'manage_cap') ? Helpers::manage_cap() : 'manage_options';

    add_menu_page(
        __( 'Campaigns','wp-email-campaigns' ),
        __( 'Campaigns','wp-email-campaigns' ),
        $cap,
        'wpec-campaigns',
        [ $this, 'render_list' ],
        'dashicons-megaphone',
        29
    );
}
public function add_metrics_metabox() {
    add_meta_box(
        'wpec_campaign_metrics',
        __('Performance','wp-email-campaigns'),
        [$this, 'render_metrics_metabox'],
        'email_campaign',
        'side',
        'high'
    );
}

public function render_metrics_metabox(\WP_Post $post) {
    $db    = Helpers::db();
    $queue = Helpers::table('send_queue');
    $cid   = (int) $post->ID;

    $row = $db->get_row( $db->prepare("
        SELECT
            SUM(COALESCE(opens_count,0))               AS total_opens,
            SUM(COALESCE(clicks_count,0))              AS total_clicks,
            SUM(CASE WHEN COALESCE(opens_count,0)>0  THEN 1 ELSE 0 END) AS unique_opens,
            SUM(CASE WHEN COALESCE(clicks_count,0)>0 THEN 1 ELSE 0 END) AS unique_clicks,
            MAX(GREATEST(
                COALESCE(last_activity_at,'0000-00-00 00:00:00'),
                COALESCE(last_open_at,'0000-00-00 00:00:00'),
                COALESCE(last_click_at,'0000-00-00 00:00:00')
            )) AS last_activity
        FROM $queue
        WHERE campaign_id = %d
    ", $cid ), ARRAY_A );

    echo '<div class="wpec-meta-metrics">';
    if (!$row) {
        echo '<p>'.esc_html__('No data yet.','wp-email-campaigns').'</p>';
    } else {
        echo '<p><strong>'.esc_html__('Unique opens','wp-email-campaigns').':</strong> '.number_format_i18n((int)$row['unique_opens']).'</p>';
        echo '<p><strong>'.esc_html__('Total opens','wp-email-campaigns').':</strong> '.number_format_i18n((int)$row['total_opens']).'</p>';
        echo '<p><strong>'.esc_html__('Unique clicks','wp-email-campaigns').':</strong> '.number_format_i18n((int)$row['unique_clicks']).'</p>';
        echo '<p><strong>'.esc_html__('Total clicks','wp-email-campaigns').':</strong> '.number_format_i18n((int)$row['total_clicks']).'</p>';
        echo '<p><strong>'.esc_html__('Last activity','wp-email-campaigns').':</strong> '.( $row['last_activity'] ? esc_html( date_i18n(get_option('date_format').' '.get_option('time_format'), strtotime($row['last_activity'])) ) : '—').'</p>';
        $reports = add_query_arg(['page'=>'wpec-reports','cid'=>$cid], admin_url('admin.php'));
        echo '<p><a class="button" href="'.esc_url($reports).'">'.esc_html__('View recipients','wp-email-campaigns').'</a></p>';
    }
    echo '</div>';
}
    /**
     * List page (also routes to detail view when ?view=detail&campaign_id=ID)
     */
    public function render_list() {
        if ( ! Helpers::user_can_manage() ) wp_die('Denied');

        global $wpdb;
        $tbl = $wpdb->prefix.'wpec_campaigns';
        $map = $wpdb->prefix.'wpec_campaign_lists';
        $queue = $wpdb->prefix.'wpec_send_queue';
        $ls  = Helpers::table('lists');

        // Route to detail view
        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : '';
        if ($view === 'detail') {
            $this->render_campaign_detail( absint($_GET['campaign_id'] ?? 0) );
            return;
        }

        // Filters
        $q    = isset($_GET['q'])    ? sanitize_text_field($_GET['q']) : '';
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

        // Pull rows with live rollups from the queue
        $sql = "
        SELECT
            c.*,
            (SELECT GROUP_CONCAT(l.name ORDER BY l.name SEPARATOR ', ')
               FROM $map m
               INNER JOIN $ls l ON l.id=m.list_id
              WHERE m.campaign_id=c.id) AS list_names,
            COALESCE(SUM(CASE WHEN q.status='queued' THEN 1 ELSE 0 END),0) AS queued,
            COALESCE(SUM(CASE WHEN q.status='sent'   THEN 1 ELSE 0 END),0) AS sent,
            COALESCE(SUM(CASE WHEN q.status='failed' THEN 1 ELSE 0 END),0) AS failed
        FROM $tbl c
        LEFT JOIN $queue q ON q.campaign_id=c.id
        WHERE ".implode(' AND ',$where)."
        GROUP BY c.id
        ORDER BY COALESCE(c.published_at, c.updated_at) DESC
        LIMIT 500";
        $rows = $wpdb->get_results($wpdb->prepare($sql,$args), ARRAY_A);

        echo '<div class="wrap"><h1>'.esc_html__('Campaigns','wp-email-campaigns').'</h1>';

        // Toolbar
        echo '<form method="GET" class="wpec-toolbar" style="display:flex;gap:8px;align-items:end;margin:10px 0;">';
    
        echo '<input type="hidden" name="page" value="wpec-campaigns">';
        echo '<p><label>'.esc_html__('Search','wp-email-campaigns').'<br><input type="text" name="q" value="'.esc_attr($q).'"></label></p>';
        echo '<p><label>'.esc_html__('Status','wp-email-campaigns').'<br><select name="status"><option value="">—</option>';
        foreach (['draft','queued','sending','paused','sent','cancelled','failed'] as $st) {
            printf('<option value="%s"%s>%s</option>', esc_attr($st), selected($stat,$st,false), esc_html(ucfirst($st)));
        }
        echo '</select></label></p>';
        echo '<p><label>'.esc_html__('From','wp-email-campaigns').'<br><input type="date" name="d1" value="'.esc_attr($d1).'"></label></p>';
        echo '<p><label>'.esc_html__('To','wp-email-campaigns').'<br><input type="date" name="d2" value="'.esc_attr($d2).'"></label></p>';
        $send_url = add_query_arg([ 'page'=>'wpec-send'], admin_url('admin.php'));
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
                        'page'        => 'wpec-campaigns',
                        'view'        => 'detail',
                        'campaign_id' => (int)$r['id'],
                    ],
                    admin_url('admin.php')
                );

                $dup_url = wp_nonce_url(
                    admin_url('admin-post.php?action=wpec_campaign_duplicate&cid=' . (int)$r['id']),
                    'wpec_admin', 'nonce'
                );

                $cont_url = add_query_arg([ 
                    'page'          => 'wpec-send',
                    'load_campaign' => (int)$r['id'],
                ], admin_url('admin.php'));

                // Compute a display status from live queue counts to avoid stale UI
                $display_status = $this->compute_display_status($r['status'] ?? '', (int)$r['queued'], (int)$r['failed'], (int)$r['sent']);

                $actions = [];
                $actions[] = '<a class="button" href="'.esc_url($view_url).'">'.esc_html__('View','wp-email-campaigns').'</a>';
                $actions[] = '<a class="button" href="'.esc_url($dup_url).'">'.esc_html__('Duplicate','wp-email-campaigns').'</a>';
                if (($r['status'] ?? '') === 'draft') {
                    $actions[] = '<a class="button button-primary" href="'.esc_url($cont_url).'">'.esc_html__('Continue in Send','wp-email-campaigns').'</a>';
                }

             $name    = $r['name'] ?: '—';
$subject = $r['subject'] ?: '—';

printf(
    '<tr>
        <td><a href="%s">%s</a></td>
        <td><a href="%s">%s</a></td>
        <td>%s</td>
        <td><span class="wpec-status-pill">%s</span></td>
        <td>%d / %d</td>
        <td>%s</td>
        <td>%s</td>
     </tr>',
    esc_url($view_url), esc_html($name),
    esc_url($view_url), esc_html($subject),
    esc_html($r['list_names'] ?: '—'),
    esc_html($r['status'] ?: '—'),
    (int)$r['sent_count'], (int)$r['failed_count'],
    $r['published_at'] ? esc_html($r['published_at']) : '—',
    implode(' ', $actions)
);

            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * Detail page for a single campaign. 
     */
    private function render_campaign_detail( int $cid ) {
    if ( ! \WPEC\Helpers::user_can_manage() ) { wp_die('Denied'); }
    global $wpdb;

    if ( ! $cid ) {
        echo '<div class="wrap"><h1>'.esc_html__('Campaign','wp-email-campaigns').'</h1><div class="notice notice-error"><p>Invalid campaign.</p></div></div>';
        return;
    }

    $tbl   = $wpdb->prefix . 'wpec_campaigns';
    $queue = $wpdb->prefix . 'wpec_send_queue';
    $map   = $wpdb->prefix . 'wpec_campaign_lists';
    $ls    = \WPEC\Helpers::table('lists');

    $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $cid), ARRAY_A );
    if ( ! $row ) {
        echo '<div class="wrap"><h1>'.esc_html__('Campaign','wp-email-campaigns').'</h1><div class="notice notice-error"><p>Not found.</p></div></div>';
        return;
    }

    // Live counts from queue
    $counts = $wpdb->get_row( $wpdb->prepare("
        SELECT
          SUM(CASE WHEN status='queued' THEN 1 ELSE 0 END) AS queued,
          SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS sent,
          SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed
        FROM $queue
        WHERE campaign_id=%d
    ", $cid ), ARRAY_A );

    $queued = (int)($counts['queued'] ?? 0);
    $sent   = (int)($counts['sent']   ?? 0);
    $failed = (int)($counts['failed'] ?? 0);

    // Compute display status:
    // - paused stays paused
    // - if any queued remain → sending
    // - if none queued remain → sent (even if some failed)
    $raw_status = (string)($row['status'] ?? '');
    if ( $raw_status === 'paused' ) {
        $display_status = 'paused';
    } elseif ( $queued > 0 ) {
        $display_status = 'sending';
    } else {
        $display_status = 'sent';
    }

    // Lists (id + name so we can make them clickable)
    $lists = $wpdb->get_results( $wpdb->prepare("
        SELECT l.id, l.name
          FROM $map m
          INNER JOIN $ls l ON l.id = m.list_id
         WHERE m.campaign_id = %d
         ORDER BY l.name ASC
    ", $cid ), ARRAY_A );

    // Build clickable list names
    $list_links = '—';
    if ( !empty($lists) ) {
        $bits = [];
        foreach ( $lists as $l ) {
            $list_url = add_query_arg(
                [ 
                    'page'      => 'wpec-lists',
                    'view'      => 'list',
                    'list_id'   => (int)$l['id'],
                ],
                admin_url('admin.php')
            );
            $bits[] = '<a href="'.esc_url($list_url).'">'.esc_html($l['name']).'</a>';
        }
        $list_links = implode(', ', $bits);
    }

    $back = add_query_arg(
        [  'page' => 'wpec-campaigns'],
        admin_url('admin.php')
    );

    // Finished if no queued remain and status is terminal (we still show "sent" even with some failed)
    $finished = ($queued === 0) && in_array($display_status, ['sent','failed','cancelled'], true);

    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Campaign','wp-email-campaigns').'</h1>';
    echo '<p><a class="button" href="'.esc_url($back).'">'.esc_html__('← Back to Campaigns','wp-email-campaigns').'</a></p>';

    // Wrapper carries campaign id for JS
    echo '<div id="wpec-campaign-detail" class="wpec-card" data-cid="'.(int)$cid.'" style="max-width:1080px;padding:16px;">';

    // Meta table
    echo '<table class="widefat striped"><tbody>';
    printf('<tr><th style="width:220px">%s</th><td>%s</td></tr>',
        esc_html__('Name','wp-email-campaigns'), esc_html($row['name'] ?: '—'));
    printf('<tr><th>%s</th><td>%s</td></tr>',
        esc_html__('Subject','wp-email-campaigns'), esc_html($row['subject'] ?: '—'));
    printf('<tr><th>%s</th><td>%s</td></tr>',
        esc_html__('Lists','wp-email-campaigns'), $list_links);
    printf('<tr><th>%s</th><td><span id="wpec-det-state" class="wpec-status-pill">%s</span></td></tr>',
        esc_html__('Status','wp-email-campaigns'), esc_html($display_status));
    printf('<tr><th>%s</th><td><span id="wpec-det-queued">%d</span> %s / <span id="wpec-det-sent">%d</span> %s / <span id="wpec-det-failed">%d</span> %s</td></tr>',
        esc_html__('Progress','wp-email-campaigns'),
        (int)$queued, esc_html__('queued','wp-email-campaigns'),
        (int)$sent,   esc_html__('sent','wp-email-campaigns'),
        (int)$failed, esc_html__('failed','wp-email-campaigns')
    );
    printf('<tr><th>%s</th><td>%s</td></tr>',
        esc_html__('Published','wp-email-campaigns'),
        $row['published_at'] ? esc_html($row['published_at']) : '—');
    printf('<tr><th>%s</th><td>%s</td></tr>',
        esc_html__('From','wp-email-campaigns'),
        esc_html(trim(($row['from_name'] ?: '').' <'.$row['from_email'].'>'))
    );
    echo '</tbody></table>';

    // Actions (Pause/Resume/Cancel)
    echo '<div style="margin:14px 0; display:flex; gap:8px; align-items:center;">';
    if ( ! $finished ) {
        if ( $display_status === 'paused' ) {
            echo '<button class="button button-primary" id="wpec-det-resume">'.esc_html__('Resume','wp-email-campaigns').'</button>';
            echo '<button class="button" id="wpec-det-pause" style="display:none">'.esc_html__('Pause','wp-email-campaigns').'</button>';
        } else {
            echo '<button class="button" id="wpec-det-pause">'.esc_html__('Pause','wp-email-campaigns').'</button>';
            echo '<button class="button button-primary" id="wpec-det-resume" style="display:none">'.esc_html__('Resume','wp-email-campaigns').'</button>';
        }
        echo '<button class="button" id="wpec-det-cancel">'.esc_html__('Cancel','wp-email-campaigns').'</button>';
    } else {
        echo '<em>'.esc_html__('Completed','wp-email-campaigns').'</em>';
    }
    echo '<span id="wpec-det-msg" style="margin-left:8px;color:#555"></span>';
    echo '</div>';

    // Preview
    echo '<h2 style="margin-top:18px">'.esc_html__('Preview','wp-email-campaigns').'</h2>';
    echo '<div class="wpec-campaign-preview" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1080px;overflow:auto">';
    echo wp_kses_post( (string)$row['body_html'] );
    echo '</div>';

    echo '</div>'; // /#wpec-campaign-detail
    echo '</div>'; // /.wrap
}

    /**
     * Duplicate: creates a new draft copy and redirects to Send screen pre-loaded.
     */
    public function handle_duplicate() {
        if ( ! Helpers::user_can_manage() ) wp_die('Denied');
        check_admin_referer('wpec_admin','nonce');

        global $wpdb;
        $tbl = $wpdb->prefix.'wpec_campaigns';
        $map = $wpdb->prefix.'wpec_campaign_lists';

        $cid = absint($_GET['cid'] ?? 0);
        if (!$cid) wp_die('Bad id');

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d",$cid), ARRAY_A);
        if (!$row) wp_die('Not found');

        $now = current_time('mysql');
        $ins = [
            'name'         => ($row['name'] ? $row['name'].' (Copy)' : 'Copy of #'.$cid),
            'subject'      => $row['subject'],
            'from_name'    => $row['from_name'],
            'from_email'   => $row['from_email'],
            'body_html'    => $row['body_html'],
            'status'       => 'draft',
            'queued_count' => 0,
            'sent_count'   => 0,
            'failed_count' => 0,
            'options_json' => $row['options_json'],
            'created_at'   => $now,
            'updated_at'   => $now,
            'published_at' => null,
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

        // Go directly to Send screen with the new draft loaded
        wp_safe_redirect( add_query_arg([ 
            'page'=>'wpec-send',
            'load_campaign'=>$new_id
        ], admin_url('admin.php')) );
        exit;
    }

    /**
     * Compute a UI status from current queue numbers to avoid stale display.
     * - paused stays paused
     * - if any queued > 0 ⇒ sending
     * - if queued == 0 ⇒ sent (unless failed > 0 ⇒ failed)
     * - otherwise fall back to stored status (draft, cancelled, etc.)
     */
    private function compute_display_status(string $stored, int $queued, int $failed, int $sent): string {
        if ($stored === 'paused') return 'paused';
        if ($queued > 0) return 'sending';
        if ($queued === 0 && ($sent > 0 || $failed > 0)) {
            return ($failed > 0) ? 'failed' : 'sent';
        }
        return $stored ?: 'draft';
    }
}

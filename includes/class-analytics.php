<?php
namespace WPEC;
if ( ! defined('ABSPATH') ) exit;

class Analytics {
    public function init() {
        add_action('admin_menu', [$this, 'add_menu']);
    }

    public function add_menu() {
        $cap = method_exists(Helpers::class,'manage_cap') ? Helpers::manage_cap() : 'manage_options';
        add_menu_page(
            __('Reports','wp-email-campaigns'),
            __('Reports','wp-email-campaigns'),
            $cap,
            'wpec-reports',
            [$this, 'render'],
            'dashicons-chart-bar',
            9
        );
    }

    public function render() {
        if ( ! Helpers::user_can_manage() ) { wp_die('Denied'); }

        $db    = Helpers::db();
        $subs  = Helpers::table('subs');
        $ct    = Helpers::table('contacts');
        $logs = Helpers::table('logs');

        $cid = isset($_GET['cid']) ? absint($_GET['cid']) : 0;

        echo '<div class="wrap wpec-admin"><h1>'.esc_html__('Reports','wp-email-campaigns').'</h1>';

        // Campaign summary from per-recipient counters
        $summary = $db->get_results("
            SELECT
                l.campaign_id,
                COUNT(DISTINCT CASE WHEN l.event='opened'  THEN l.subscriber_id END) AS unique_opens,
                SUM(CASE WHEN l.event='opened'  THEN 1 ELSE 0 END)                  AS total_opens,
                COUNT(DISTINCT CASE WHEN l.event='clicked' THEN l.subscriber_id END) AS unique_clicks,
                SUM(CASE WHEN l.event='clicked' THEN 1 ELSE 0 END)                  AS total_clicks,
                MAX(l.event_time) AS last_activity
            FROM $logs l
            GROUP BY l.campaign_id
            ORDER BY last_activity DESC
            LIMIT 200
        ", ARRAY_A);

        echo '<div class="wpec-card"><h2>'.esc_html__('Campaign summary','wp-email-campaigns').'</h2>';
        if (empty($summary)) {
            echo '<p>'.esc_html__('No tracking data yet. Send a campaign and open/click it.','wp-email-campaigns').'</p>';
        } else {
            echo '<div class="wpec-table-scroll"><table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>'.esc_html__('Campaign','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Unique opens','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Total opens','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Unique clicks','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Total clicks','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Last activity','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('View','wp-email-campaigns').'</th>';
            echo '</tr></thead><tbody>';
            foreach ($summary as $row) {
                $campaign_id = (int)$row['campaign_id'];
                $edit = $campaign_id ? get_edit_post_link($campaign_id) : '';
                $view = add_query_arg(['page'=>'wpec-reports','cid'=>$campaign_id], admin_url('admin.php'));
                echo '<tr>';
                echo '<td>'.($edit ? '<a href="'.esc_url($edit).'">#'.esc_html($campaign_id).'</a>' : '#'.esc_html($campaign_id)).'</td>';
                echo '<td>'.number_format_i18n((int)$row['unique_opens']).'</td>';
                echo '<td>'.number_format_i18n((int)$row['total_opens']).'</td>';
                echo '<td>'.number_format_i18n((int)$row['unique_clicks']).'</td>';
                echo '<td>'.number_format_i18n((int)$row['total_clicks']).'</td>';
                echo '<td>'.esc_html( $row['last_activity'] ? date_i18n( get_option('date_format').' '.get_option('time_format'), strtotime($row['last_activity']) ) : '—' ).'</td>';
                echo '<td><a class="button" href="'.esc_url($view).'">'.esc_html__('Recipients','wp-email-campaigns').'</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';

        // Per-campaign recipients table when ?cid= is present
        if ($cid) {
              $ct = Helpers::table('contacts');
    $recipients = $db->get_results( $db->prepare("
        SELECT
            l.subscriber_id  AS contact_id,
            c.email,
            CONCAT_WS(' ', c.first_name, c.last_name) AS full_name,
            c.status,
            SUM(CASE WHEN l.event='opened'  THEN 1 ELSE 0 END) AS opens,
            SUM(CASE WHEN l.event='clicked' THEN 1 ELSE 0 END) AS clicks,
            MAX(CASE WHEN l.event='opened'  THEN l.event_time END) AS last_open_at,
            MAX(CASE WHEN l.event='clicked' THEN l.event_time END) AS last_click_at,
            MAX(l.event_time) AS last_activity_at
        FROM $logs l
        LEFT JOIN $ct c ON c.id = l.subscriber_id
        WHERE l.campaign_id = %d
        GROUP BY l.subscriber_id, c.email, c.first_name, c.last_name, c.status
        ORDER BY last_activity_at DESC
        LIMIT 1000
    ", $cid ), ARRAY_A );

            echo '<div class="wpec-card"><h2>'.sprintf( esc_html__('Recipients — Campaign #%d','wp-email-campaigns'), $cid ).'</h2>';
            if (empty($recipients)) {
                echo '<p>'.esc_html__('No recipients found for this campaign.','wp-email-campaigns').'</p>';
            } else {
                echo '<div class="wpec-table-scroll"><table class="widefat striped">';
                echo '<thead><tr>';
                echo '<th>'.esc_html__('Contact','wp-email-campaigns').'</th>';
                echo '<th>'.esc_html__('Email','wp-email-campaigns').'</th>';
                echo '<th>'.esc_html__('Status','wp-email-campaigns').'</th>';
                echo '<th>'.esc_html__('Opens','wp-email-campaigns').'</th>';
                echo '<th>'.esc_html__('First open','wp-email-campaigns').'</th>';
                echo '<th>'.esc_html__('Last open','wp-email-campaigns').'</th>';
                echo '<th>'.esc_html__('Clicks','wp-email-campaigns').'</th>';
                echo '<th>'.esc_html__('Last click','wp-email-campaigns').'</th>';
                echo '<th>'.esc_html__('Last activity','wp-email-campaigns').'</th>';
                echo '</tr></thead><tbody>';

                foreach ($recipients as $r) {
                    echo '<tr>';
                    echo '<td>'.esc_html($r['full_name'] ?: '—').'</td>';
                    echo '<td>'.esc_html($r['email'] ?: '—').'</td>';
                    echo '<td>'.esc_html( $r['status'] ?: '—').'</td>';
                    echo '<td>'.number_format_i18n((int)$r['opens']).'</td>';
                    echo '<td>'.( $r['first_open_at'] ? esc_html( date_i18n(get_option('date_format').' '.get_option('time_format'), strtotime($r['first_open_at'])) ) : '—').'</td>';
                    echo '<td>'.( $r['last_open_at']  ? esc_html( date_i18n(get_option('date_format').' '.get_option('time_format'), strtotime($r['last_open_at'])) )  : '—').'</td>';
                    echo '<td>'.number_format_i18n((int)$r['clicks']).'</td>';
                    echo '<td>'.( $r['last_click_at'] ? esc_html( date_i18n(get_option('date_format').' '.get_option('time_format'), strtotime($r['last_click_at'])) ) : '—').'</td>';
                    echo '<td>'.( $r['last_activity_at'] ? esc_html( date_i18n(get_option('date_format').' '.get_option('time_format'), strtotime($r['last_activity_at'])) ) : '—').'</td>';
                    echo '</tr>';
                }

                echo '</tbody></table></div>';
            }
            echo '</div>';
        }

        echo '</div>'; // wrap
    }
}

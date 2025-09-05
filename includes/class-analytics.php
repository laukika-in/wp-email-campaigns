<?php
namespace WPEC;
if ( ! defined('ABSPATH') ) exit;

class Analytics {
    public function init() {
        add_action('admin_menu', [$this, 'add_menu']);
    }

    public function add_menu() {
        $cap = method_exists(Helpers::class,'manage_cap') ? Helpers::manage_cap() : 'manage_options';
        // Separate Reports menu item
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

        $db   = Helpers::db();
        $logs = Helpers::table('logs');

        // Summary by campaign (unique + total)
        $summary = $db->get_results("
            SELECT
                campaign_id,
                SUM(CASE WHEN event='opened'  THEN 1 ELSE 0 END) AS opens,
                COUNT(DISTINCT CASE WHEN event='opened'  THEN queue_id END) AS unique_opens,
                SUM(CASE WHEN event='clicked' THEN 1 ELSE 0 END) AS clicks,
                COUNT(DISTINCT CASE WHEN event='clicked' THEN queue_id END) AS unique_clicks,
                MIN(event_time) AS first_event,
                MAX(event_time) AS last_event
            FROM $logs
            GROUP BY campaign_id
            ORDER BY last_event DESC
            LIMIT 50
        ", ARRAY_A);

        // Recent events
        $recent = $db->get_results("
            SELECT id, event, campaign_id, queue_id, link_url, event_time
            FROM $logs
            ORDER BY id DESC
            LIMIT 50
        ", ARRAY_A);

        echo '<div class="wrap wpec-admin"><h1>'.esc_html__('Reports','wp-email-campaigns').'</h1>';

        // Summary table
        echo '<div class="wpec-card"><h2>'.esc_html__('Campaign summary','wp-email-campaigns').'</h2>';
        if (empty($summary)) {
            echo '<p>'.esc_html__('No tracking data yet. Send a test campaign and open/click it.','wp-email-campaigns').'</p>';
        } else {
            echo '<div class="wpec-table-scroll"><table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>'.esc_html__('Campaign','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Unique opens','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Total opens','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Unique clicks','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Total clicks','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Last activity','wp-email-campaigns').'</th>';
            echo '</tr></thead><tbody>';
            foreach ($summary as $row) {
                $cid = (int)$row['campaign_id'];
                $edit = $cid ? get_edit_post_link($cid) : '';
                echo '<tr>';
                echo '<td>'.($edit ? '<a href="'.esc_url($edit).'">#'.esc_html($cid).'</a>' : '#'.esc_html($cid)).'</td>';
                echo '<td>'.number_format_i18n((int)$row['unique_opens']).'</td>';
                echo '<td>'.number_format_i18n((int)$row['opens']).'</td>';
                echo '<td>'.number_format_i18n((int)$row['unique_clicks']).'</td>';
                echo '<td>'.number_format_i18n((int)$row['clicks']).'</td>';
                echo '<td>'.esc_html( $row['last_event'] ? date_i18n( get_option('date_format').' '.get_option('time_format'), strtotime($row['last_event']) ) : '—' ).'</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';

        // Recent events
        echo '<div class="wpec-card"><h2>'.esc_html__('Recent events','wp-email-campaigns').'</h2>';
        if (empty($recent)) {
            echo '<p>'.esc_html__('No events yet.','wp-email-campaigns').'</p>';
        } else {
            echo '<div class="wpec-table-scroll"><table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>'.esc_html__('ID','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Event','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Campaign','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Queue','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('Link','wp-email-campaigns').'</th>';
            echo '<th>'.esc_html__('When','wp-email-campaigns').'</th>';
            echo '</tr></thead><tbody>';
            foreach ($recent as $r) {
                $cid = (int)$r['campaign_id'];
                $edit = $cid ? get_edit_post_link($cid) : '';
                echo '<tr>';
                echo '<td>'.(int)$r['id'].'</td>';
                echo '<td>'.esc_html($r['event']).'</td>';
                echo '<td>'.($edit ? '<a href="'.esc_url($edit).'">#'.esc_html($cid).'</a>' : '#'.esc_html($cid)).'</td>';
                echo '<td>#'.(int)$r['queue_id'].'</td>';
                echo '<td>'.($r['link_url'] ? '<a href="'.esc_url($r['link_url']).'" target="_blank" rel="noopener">'.esc_html( mb_strimwidth($r['link_url'],0,64,'…') ).'</a>' : '—').'</td>';
                echo '<td>'.esc_html( date_i18n( get_option('date_format').' '.get_option('time_format'), strtotime($r['event_time']) ) ).'</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';

        echo '</div>'; // wrap
    }
}

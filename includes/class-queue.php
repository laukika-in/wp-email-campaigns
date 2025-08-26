<?php
namespace WPEC;

if ( ! defined('ABSPATH') ) exit;

class Queue {
    public function init() {
        add_action('admin_menu', [ $this, 'add_menu' ]);
    }

    public function add_menu() {
        $parent = 'edit.php?post_type=email_campaign';
        $cap = method_exists(Helpers::class,'manage_cap') ? Helpers::manage_cap() : 'manage_options';

        add_submenu_page(
            $parent,
            __('Queue','wp-email-campaigns'),
            __('Queue','wp-email-campaigns'),
            $cap,
            'wpec-queue',
            [ $this, 'render' ],
            8
        );
    }

    public function render() {
        if ( ! Helpers::user_can_manage() ) wp_die('Denied');
        global $wpdb;
        $q   = $wpdb->prefix.'wpec_send_queue';
        $cam = $wpdb->prefix.'wpec_campaigns';

        // Per-campaign rollup
        $rows = $wpdb->get_results("
            SELECT c.id, c.name, c.subject, c.status,
                   SUM(CASE WHEN q.status='queued' THEN 1 ELSE 0 END) AS queued,
                   SUM(CASE WHEN q.status='sent'   THEN 1 ELSE 0 END) AS sent,
                   SUM(CASE WHEN q.status='failed' THEN 1 ELSE 0 END) AS failed
            FROM $cam c
            LEFT JOIN $q q ON q.campaign_id=c.id
            WHERE c.status IN ('queued','sending','paused','sent','cancelled','failed')
            GROUP BY c.id
            ORDER BY c.id DESC
            LIMIT 200
        ", ARRAY_A);

        echo '<div class="wrap"><h1>'.esc_html__('Queue','wp-email-campaigns').'</h1>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>'.esc_html__('Campaign','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Status','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Queued','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Sent','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Failed','wp-email-campaigns').'</th>';
        echo '<th>'.esc_html__('Actions','wp-email-campaigns').'</th>';
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="6"><em>No jobs.</em></td></tr>';
        } else {
            foreach ($rows as $r) {
                $vid = add_query_arg(['post_type'=>'email_campaign','page'=>'wpec-campaigns','view'=>'detail','id'=>$r['id']], admin_url('edit.php'));
                echo '<tr>';
                echo '<td><a href="'.esc_url($vid).'">'.esc_html($r['subject']).'</a></td>';
                echo '<td><span class="wpec-status-pill">'.esc_html($r['status']).'</span></td>';
                echo '<td>'.(int)$r['queued'].'</td>';
                echo '<td>'.(int)$r['sent'].'</td>';
                echo '<td>'.(int)$r['failed'].'</td>';
                echo '<td>';
                echo '<button class="button wpec-q-pause" data-id="'.(int)$r['id'].'">Pause</button> ';
                echo '<button class="button wpec-q-resume" data-id="'.(int)$r['id'].'">Resume</button> ';
                echo '<button class="button wpec-q-cancel" data-id="'.(int)$r['id'].'">Cancel</button>';
                echo '</td></tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
    }
}

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
            echo '<tr><td colspan="6"><em>'.esc_html__('No jobs.','wp-email-campaigns').'</em></td></tr>';
        } else {
        foreach ($rows as $r) {
            $vid = add_query_arg(
                ['post_type'=>'email_campaign','page'=>'wpec-campaigns','view'=>'detail','id'=>$r['id']],
                admin_url('edit.php')
            );

           $queued   = (int)$r['queued'];
$status   = (string)$r['status'];

// Consider the job "finished" as soon as the queue is empty OR it's in a final state
$finished = ($queued === 0) || in_array($status, ['sent','failed','cancelled'], true);

// Button availability
$canPause  = (!$finished) && in_array($status, ['queued','sending'], true);
$canResume = (!$finished) && ($status === 'paused');
$canCancel = (!$finished) && in_array($status, ['queued','sending','paused'], true);
   echo '<tr data-id="'.(int)$r['id'].'" data-status="'.esc_attr($status).'">';
            echo '  <td><a href="'.esc_url($vid).'">'.esc_html($r['subject'] ?: ($r['name'] ?: ('#'.$r['id']))).'</a></td>';
            echo '  <td><span class="wpec-status-pill">'.esc_html($status).'</span></td>';
            echo '  <td>'.(int)$queued.'</td>';
            echo '  <td>'.(int)$r['sent'].'</td>';
                echo '  <td>'.(int)$r['failed'].'</td>';
                echo '<td>';
$queued = (int)$r['queued'];
$status = (string)$r['status'];

$finished = ($queued === 0) && in_array($status, ['sent','failed','cancelled'], true);

if ( $finished ) {
    if ( $status === 'sent' ) {
        echo '<em>'.esc_html__('All sent','wp-email-campaigns').'</em>';
    } elseif ( $status === 'failed' ) {
        echo '<em>'.esc_html__('Completed with errors','wp-email-campaigns').'</em>';
    } else {
        echo '<em>'.esc_html__('Cancelled','wp-email-campaigns').'</em>';
    }
} else {
    if ( in_array($status, ['queued','sending'], true) ) {
        echo '<button class="button wpec-q-pause" data-id="'.(int)$r['id'].'">'.esc_html__('Pause','wp-email-campaigns').'</button> ';
        echo '<button class="button wpec-q-cancel" data-id="'.(int)$r['id'].'">'.esc_html__('Cancel','wp-email-campaigns').'</button>';
    } elseif ( $status === 'paused' ) {
        echo '<button class="button wpec-q-resume" data-id="'.(int)$r['id'].'">'.esc_html__('Resume','wp-email-campaigns').'</button> ';
        echo '<button class="button wpec-q-cancel" data-id="'.(int)$r['id'].'">'.esc_html__('Cancel','wp-email-campaigns').'</button>';
    } else {
        echo '<em>—</em>';
    }
}
echo '</td>';


                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
    }

}

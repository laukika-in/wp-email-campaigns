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
private function render_campaign_detail( int $cid ) {
    if ( ! Helpers::user_can_manage() ) { wp_die('Denied'); }
    global $wpdb;

    $cam = $wpdb->prefix.'wpec_campaigns';
    $q   = $wpdb->prefix.'wpec_send_queue';
    $ls  = Helpers::table('lists');
    $map = $wpdb->prefix.'wpec_campaign_lists';

    $one = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cam WHERE id=%d", $cid), ARRAY_A);
    if ( ! $one ) {
        echo '<div class="wrap"><h1>Campaign</h1><div class="notice notice-error"><p>Not found.</p></div></div>';
        return;
    }

    $lists = $wpdb->get_col($wpdb->prepare("
        SELECT l.name
        FROM $map m INNER JOIN $ls l ON l.id=m.list_id
        WHERE m.campaign_id=%d
        ORDER BY l.name ASC
    ", $cid));

    $tot   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d", $cid));
    $sent  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND status='sent'", $cid));
    $fail  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND status='failed'", $cid));
    $queue = $tot - $sent - $fail;

    $back = add_query_arg(['post_type'=>'email_campaign','page'=>'wpec-campaigns'], admin_url('edit.php'));

    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Campaign','wp-email-campaigns').'</h1>';
    echo '<p><a class="button" href="'.esc_url($back).'">'.esc_html__('← Back to Campaigns','wp-email-campaigns').'</a></p>';

    echo '<div class="wpec-card" style="max-width:1080px;padding:16px;">';
    echo '<table class="widefat striped"><tbody>';
    printf('<tr><th style="width:220px">%s</th><td>%s</td></tr>', esc_html__('Name','wp-email-campaigns'),    esc_html($one['name'] ?: '—'));
    printf('<tr><th>%s</th><td>%s</td></tr>',                   esc_html__('Subject','wp-email-campaigns'), esc_html($one['subject']));
    printf('<tr><th>%s</th><td>%s</td></tr>',                   esc_html__('From','wp-email-campaigns'),    esc_html(trim(($one['from_name']?:'').' <'.$one['from_email'].'>')));
    printf('<tr><th>%s</th><td>%s</td></tr>',                   esc_html__('Lists','wp-email-campaigns'),   esc_html($lists ? implode(', ', $lists) : '—'));
    printf('<tr><th>%s</th><td><span class="wpec-status-pill">%s</span></td></tr>', esc_html__('Status','wp-email-campaigns'), esc_html($one['status']));
    printf('<tr><th>%s</th><td>%d sent / %d failed / %d queued (%d total)</td></tr>', esc_html__('Progress','wp-email-campaigns'), $sent, $fail, $queue, $tot);
    printf('<tr><th>%s</th><td>%s</td></tr>',                   esc_html__('Published','wp-email-campaigns'),
        esc_html( $one['published_at'] ?: '—' ));
    echo '</tbody></table>';

    echo '<h2 style="margin-top:18px">'.esc_html__('Preview','wp-email-campaigns').'</h2>';
    echo '<div class="wpec-campaign-preview" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1080px;overflow:auto">';
    echo wp_kses_post( $one['body_html'] );
    echo '</div>';

    echo '</div></div>';
}

}

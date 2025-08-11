<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Scheduler {
    public function init() {
        add_action( 'wp_ajax_wpec_pause', [ $this, 'ajax_pause' ] );
        add_action( 'wp_ajax_wpec_resume', [ $this, 'ajax_resume' ] );
        add_action( 'wp_ajax_wpec_cancel', [ $this, 'ajax_cancel' ] );

        add_action( 'wpec_send_single_email', [ $this, 'dispatch_send' ], 10, 2 );
    }

    public function schedule_campaign( $campaign_id ) {
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            error_log( 'WPEC: Action Scheduler not available' );
            return;
        }
        global $wpdb;
        $subs = Helpers::table('subs');
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM $subs WHERE campaign_id=%d AND status='pending' ORDER BY id ASC", $campaign_id ) );
        if ( ! $rows ) return;

        $group = Helpers::campaign_group( $campaign_id );
        $i = 0;
        foreach ( $rows as $row ) {
            $timestamp = time() + ( $i * 3 ); // 1 email every 3 seconds
            as_schedule_single_action( $timestamp, 'wpec_send_single_email', [ 'campaign_id' => $campaign_id, 'subscriber_id' => (int) $row->id ], $group );
            $i++;
            // mark scheduled
            $wpdb->update( $subs, [ 'status' => 'scheduled', 'updated_at' => Helpers::now() ], [ 'id' => $row->id ] );
        }
        update_post_meta( $campaign_id, '_wpec_status', 'in_progress' );
    }

    public function dispatch_send( $campaign_id, $subscriber_id ) {
        ( new Sender )->send_single( $campaign_id, $subscriber_id );
    }

    public function ajax_pause() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error();

        $campaign_id = absint( $_POST['id'] ?? 0 );
        if ( ! $campaign_id ) wp_send_json_error();

        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'wpec_send_single_email', [], Helpers::campaign_group( $campaign_id ) );
        }
        update_post_meta( $campaign_id, '_wpec_status', 'paused' );
        wp_send_json_success( [ 'message' => __( 'Paused and unscheduled pending actions.', 'wp-email-campaigns' ) ] );
    }

    public function ajax_resume() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error();
        $campaign_id = absint( $_POST['id'] ?? 0 );
        if ( ! $campaign_id ) wp_send_json_error();
        $this->schedule_campaign( $campaign_id );
        wp_send_json_success( [ 'message' => __( 'Rescheduled pending emails.', 'wp-email-campaigns' ) ] );
    }

    public function ajax_cancel() {
        check_ajax_referer( 'wpec_admin', 'nonce' );
        if ( ! Helpers::user_can_manage() ) wp_send_json_error();
        $campaign_id = absint( $_POST['id'] ?? 0 );
        if ( ! $campaign_id ) wp_send_json_error();
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'wpec_send_single_email', [], Helpers::campaign_group( $campaign_id ) );
        }
        global $wpdb;
        $subs = Helpers::table('subs');
        $wpdb->query( $wpdb->prepare( "UPDATE $subs SET status='cancelled', updated_at=%s WHERE campaign_id=%d AND status IN ('pending','scheduled')", Helpers::now(), $campaign_id ) );
        update_post_meta( $campaign_id, '_wpec_status', 'cancelled' );
        wp_send_json_success( [ 'message' => __( 'Cancelled campaign.', 'wp-email-campaigns' ) ] );
    }
}

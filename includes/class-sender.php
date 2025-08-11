<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Sender {
    public function init() {
        // No global hooks
    }

    public function send_single( $campaign_id, $subscriber_id ) {
        global $wpdb;
        $subs = Helpers::table('subs');
        $contacts = Helpers::table('contacts');
        $logs = Helpers::table('logs');

        $sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $subs WHERE id=%d", $subscriber_id ) );
        if ( ! $sub ) { return; }

        // Skip if already sent
        if ( in_array( $sub->status, [ 'sent', 'bounced' ], true ) ) return;

        $post = get_post( $campaign_id );
        if ( ! $post || 'email_campaign' !== $post->post_type ) return;

        $subject   = get_post_meta( $campaign_id, '_wpec_subject', true );
        $preheader = get_post_meta( $campaign_id, '_wpec_preheader', true );
        $content   = $post->post_content;

        $data = [
            'name'       => $sub->name,
            'first_name' => preg_split('/\s+/', trim((string)$sub->name))[0] ?? '',
        ];

        $subject = Helpers::replace_tokens( $subject, $data );
        $content = Helpers::render_preheader( $preheader ) . Helpers::replace_tokens( $content, $data );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        // Custom headers to correlate webhooks
        $headers[] = 'X-WPEC-Campaign: ' . $campaign_id;
        $headers[] = 'X-WPEC-Subscriber: ' . $subscriber_id;

        $sent = wp_mail( $sub->email, $subject, $content, $headers );

        if ( $sent ) {
            $wpdb->update( $subs, [
                'status' => 'sent',
                'sent_at'=> Helpers::now(),
                'updated_at' => Helpers::now(),
            ], [ 'id' => $subscriber_id ] );
            $wpdb->insert( $logs, [
                'campaign_id' => $campaign_id,
                'subscriber_id' => $subscriber_id,
                'event' => 'sent',
                'provider_message_id' => null, // can be filled from phpmailer_init if SMTP provider exposes it
                'info'  => null,
                'event_time' => Helpers::now(),
                'created_at' => Helpers::now(),
            ] );
        } else {
            $attempts = (int) $sub->attempts + 1;
            $wpdb->update( $subs, [
                'status' => ( $attempts >= 3 ) ? 'failed' : 'pending',
                'attempts' => $attempts,
                'last_error' => 'wp_mail returned false',
                'updated_at' => Helpers::now(),
            ], [ 'id' => $subscriber_id ] );

            $wpdb->insert( $logs, [
                'campaign_id' => $campaign_id,
                'subscriber_id' => $subscriber_id,
                'event' => 'failed',
                'provider_message_id' => null,
                'info'  => 'wp_mail returned false',
                'event_time' => Helpers::now(),
                'created_at' => Helpers::now(),
            ] );

            // Retry in 5 minutes up to 3 attempts
            if ( $attempts < 3 && function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action( time() + 5*60, 'wpec_send_single_email', [ 'campaign_id' => $campaign_id, 'subscriber_id' => $subscriber_id ], Helpers::campaign_group( $campaign_id ) );
                $wpdb->update( $subs, [ 'status' => 'scheduled' ], [ 'id' => $subscriber_id ] );
            }
        }
    }
}

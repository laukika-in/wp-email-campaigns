<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Webhooks {
    public function init() {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    public function routes() {
        register_rest_route( 'email-campaigns/v1', '/webhook/brevo', [
            'methods'  => 'POST',
            'callback' => [ $this, 'handle_brevo' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle_brevo( $request ) {
        $secret = get_option( 'wpec_brevo_secret' );
        $provided = $request->get_header( 'x-wpec-secret' );
        if ( $secret && $provided !== $secret ) {
            return new \WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
        }

        $payload = $request->get_json_params();
        if ( ! $payload ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        // Brevo events: 'delivered','open','hard_bounce','soft_bounce' etc.
        $event = $payload['event'] ?? '';
        $email = $payload['email'] ?? '';
        $message_id = $payload['message-id'] ?? ($payload['messageId'] ?? null);
        $headers = $payload['headers'] ?? [];
        $campaign_id = isset($headers['X-WPEC-Campaign']) ? (int) $headers['X-WPEC-Campaign'] : 0;
        $subscriber_id = isset($headers['X-WPEC-Subscriber']) ? (int) $headers['X-WPEC-Subscriber'] : 0;

        global $wpdb;
        $subs = Helpers::table('subs');
        $logs = Helpers::table('logs');
        $contacts = Helpers::table('contacts');

        $map = [
            'delivered'   => 'delivered',
            'open'        => 'opened',
            'hard_bounce' => 'bounced',
            'soft_bounce' => 'bounced',
        ];
        $evt = $map[$event] ?? null;
        if ( ! $evt ) {
            return new \WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        if ( $subscriber_id ) {
            if ( 'bounced' === $evt ) {
                $wpdb->update( $subs, [ 'status' => 'bounced', 'updated_at' => Helpers::now() ], [ 'id' => $subscriber_id ] );
                // mark contact as bounced
                $sub = $wpdb->get_row( $wpdb->prepare( "SELECT contact_id FROM $subs WHERE id=%d", $subscriber_id ) );
                if ( $sub && $sub->contact_id ) {
                    $wpdb->update( $contacts, [ 'status' => 'bounced', 'updated_at' => Helpers::now() ], [ 'id' => $sub->contact_id ] );
                }
            }
            $wpdb->insert( $logs, [
                'campaign_id' => $campaign_id,
                'subscriber_id' => $subscriber_id,
                'event' => $evt,
                'provider_message_id' => $message_id,
                'info'  => isset($payload['reason']) ? maybe_serialize($payload['reason']) : null,
                'event_time' => Helpers::now(),
                'created_at' => Helpers::now(),
            ] );
        }

        return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }
}

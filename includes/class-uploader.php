<?php
namespace WPEC;

use PhpOffice\PhpSpreadsheet\IOFactory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Uploader {
    public function init() {
        // Nothing to hook globally yet.
    }

    public function handle_upload_for_campaign( $campaign_id, $file ) {
        if ( ! Helpers::user_can_manage() ) return;

        $tmp_name = $file['tmp_name'];
        $name     = sanitize_file_name( $file['name'] );
        $ext      = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

        $valid = $invalid = $dupes = 0;
        $db = Helpers::db();
        $subs_table = Helpers::table('subs');
        $contacts   = Helpers::table('contacts');

        $emails_seen = [];

        if ( $ext === 'csv' ) {
            if ( ( $handle = fopen( $tmp_name, 'r' ) ) ) {
                while ( ( $row = fgetcsv( $handle ) ) !== false ) {
                    $email = $row[0] ?? '';
                    $name  = $row[1] ?? '';
                    list( $email, $name ) = Helpers::sanitize_email_name( $email, $name );
                    if ( empty( $email ) ) { $invalid++; continue; }

                    if ( isset( $emails_seen[ $email ] ) ) { $dupes++; continue; }
                    $emails_seen[ $email ] = true;

                    // Upsert into contacts
                    $contact_id = $db->get_var( $db->prepare( "SELECT id FROM $contacts WHERE email=%s", $email ) );
                    if ( ! $contact_id ) {
                        $db->insert( $contacts, [
                            'email' => $email,
                            'name'  => $name,
                            'status'=> 'active',
                            'created_at' => Helpers::now(),
                            'updated_at' => null,
                            'last_campaign_id' => $campaign_id,
                        ] );
                        $contact_id = $db->insert_id;
                    }

                    // Insert into subs (avoid duplicates per campaign)
                    $exists = $db->get_var( $db->prepare( "SELECT id FROM $subs_table WHERE campaign_id=%d AND email=%s", $campaign_id, $email ) );
                    if ( $exists ) { $dupes++; continue; }

                    $db->insert( $subs_table, [
                        'campaign_id' => $campaign_id,
                        'contact_id'  => $contact_id,
                        'email'       => $email,
                        'name'        => $name,
                        'status'      => 'pending',
                        'attempts'    => 0,
                        'created_at'  => Helpers::now(),
                        'updated_at'  => null,
                        'sent_at'     => null,
                    ] );
                    $valid++;
                }
                fclose( $handle );
            }
        } else {
            // XLSX via PhpSpreadsheet
            if ( ! class_exists( IOFactory::class ) ) {
                wp_die( esc_html__( 'PhpSpreadsheet not installed. Run composer install.', 'wp-email-campaigns' ) );
            }
            $spreadsheet = IOFactory::load( $tmp_name );
            $sheet = $spreadsheet->getActiveSheet();
            foreach ( $sheet->getRowIterator() as $row ) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                $values = [];
                foreach ( $cellIterator as $cell ) {
                    $values[] = (string) $cell->getValue();
                }
                $email = $values[0] ?? '';
                $name  = $values[1] ?? '';
                list( $email, $name ) = Helpers::sanitize_email_name( $email, $name );
                if ( empty( $email ) ) { $invalid++; continue; }

                if ( isset( $emails_seen[ $email ] ) ) { $dupes++; continue; }
                $emails_seen[ $email ] = true;

                $contact_id = $db->get_var( $db->prepare( "SELECT id FROM $contacts WHERE email=%s", $email ) );
                if ( ! $contact_id ) {
                    $db->insert( $contacts, [
                        'email' => $email,
                        'name'  => $name,
                        'status'=> 'active',
                        'created_at' => Helpers::now(),
                        'updated_at' => null,
                        'last_campaign_id' => $campaign_id,
                    ] );
                    $contact_id = $db->insert_id;
                }

                $exists = $db->get_var( $db->prepare( "SELECT id FROM $subs_table WHERE campaign_id=%d AND email=%s", $campaign_id, $email ) );
                if ( $exists ) { $dupes++; continue; }

                $db->insert( $subs_table, [
                    'campaign_id' => $campaign_id,
                    'contact_id'  => $contact_id,
                    'email'       => $email,
                    'name'        => $name,
                    'status'      => 'pending',
                    'attempts'    => 0,
                    'created_at'  => Helpers::now(),
                    'updated_at'  => null,
                    'sent_at'     => null,
                ] );
                $valid++;
            }
        }

        // Feedback notice
        $msg = sprintf( __( '%1$d valid emails imported, %2$d invalid, %3$d duplicates skipped.', 'wp-email-campaigns' ), $valid, $invalid, $dupes );
        add_action( 'admin_notices', function() use ( $msg ) {
            echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
        } );
    }
}

<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Activator {
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset   = $wpdb->get_charset_collate();
        $contacts  = Helpers::table('contacts');
        $subs      = Helpers::table('subs');
        $logs      = Helpers::table('logs');
        $lists     = Helpers::table('lists');      // NEW
        $listItems = Helpers::table('list_items'); // NEW

        $sql_contacts = "CREATE TABLE $contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(191) NOT NULL,
            name VARCHAR(191) NULL,
            status ENUM('active','unsubscribed','bounced') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            last_campaign_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            KEY status (status),
            KEY last_campaign_id (last_campaign_id)
        ) $charset;";

        $sql_subs = "CREATE TABLE $subs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NULL,
            email VARCHAR(191) NOT NULL,
            name VARCHAR(191) NULL,
            status ENUM('pending','scheduled','sent','failed','bounced','cancelled') NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            sent_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY campaign_id (campaign_id),
            KEY email (email),
            KEY contact_id (contact_id),
            KEY status (status)
        ) $charset;";

        $sql_logs = "CREATE TABLE $logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            subscriber_id BIGINT UNSIGNED NULL,
            event ENUM('queued','sent','delivered','opened','bounced','failed') NOT NULL,
            provider_message_id VARCHAR(191) NULL,
            info TEXT NULL,
            event_time DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY campaign_id (campaign_id),
            KEY subscriber_id (subscriber_id),
            KEY provider_message_id (provider_message_id),
            KEY event (event)
        ) $charset;";

        // NEW: lists master table
        $sql_lists = "CREATE TABLE $lists (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            status ENUM('importing','ready','failed') NOT NULL DEFAULT 'importing',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            source_filename VARCHAR(191) NULL,
            file_path TEXT NULL,        -- temp file path while importing
            file_pointer BIGINT NULL,   -- current byte offset for resumable reads
            total INT UNSIGNED NOT NULL DEFAULT 0,
            imported INT UNSIGNED NOT NULL DEFAULT 0,
            invalid INT UNSIGNED NOT NULL DEFAULT 0,
            duplicates INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;";

        // NEW: list items (mapping list -> contact)
        $sql_list_items = "CREATE TABLE $listItems (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            list_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY list_contact (list_id, contact_id),
            KEY list_id (list_id),
            KEY contact_id (contact_id)
        ) $charset;";

        dbDelta( $sql_contacts );
        dbDelta( $sql_subs );
        dbDelta( $sql_logs );
        dbDelta( $sql_lists );
        dbDelta( $sql_list_items );

        // Ensure uploads dir exists
        Helpers::ensure_uploads_dir();
    }
}

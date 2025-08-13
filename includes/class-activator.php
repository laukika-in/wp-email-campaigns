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
        $lists     = Helpers::table('lists');
        $listItems = Helpers::table('list_items');

        // Contacts table — now with richer schema
        $sql_contacts = "CREATE TABLE $contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(191) NOT NULL,
            -- legacy 'name' kept for compatibility; we will populate it as 'first_name last_name'
            name VARCHAR(191) NULL,
            first_name VARCHAR(191) NULL,
            last_name VARCHAR(191) NULL,
            company_name VARCHAR(191) NULL,
            company_employees INT NULL,
            company_annual_revenue BIGINT NULL,
            contact_number VARCHAR(64) NULL,
            job_title VARCHAR(191) NULL,
            industry VARCHAR(191) NULL,
            country VARCHAR(191) NULL,
            state VARCHAR(191) NULL,
            city VARCHAR(191) NULL,
            postal_code VARCHAR(32) NULL,
            status ENUM('active','unsubscribed','bounced') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            last_campaign_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            KEY status (status),
            KEY last_campaign_id (last_campaign_id)
        ) $charset;";

        // Subscribers table (unchanged)
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

        // Logs table (unchanged)
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

        // Lists master table — add header_map for header-driven import
        $sql_lists = "CREATE TABLE $lists (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            status ENUM('importing','ready','failed') NOT NULL DEFAULT 'importing',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            source_filename VARCHAR(191) NULL,
            file_path TEXT NULL,
            file_pointer BIGINT NULL,
            header_map LONGTEXT NULL, -- JSON of normalized header -> column index
            total INT UNSIGNED NOT NULL DEFAULT 0,
            imported INT UNSIGNED NOT NULL DEFAULT 0,
            invalid INT UNSIGNED NOT NULL DEFAULT 0,
            duplicates INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;";

        // List items mapping — add duplicate flag + created_at
        $sql_list_items = "CREATE TABLE $listItems (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            list_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            is_duplicate_import TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY list_contact (list_id, contact_id),
            KEY list_id (list_id),
            KEY contact_id (contact_id),
            KEY is_duplicate_import (is_duplicate_import)
        ) $charset;";

        dbDelta( $sql_contacts );
        dbDelta( $sql_subs );
        dbDelta( $sql_logs );
        dbDelta( $sql_lists );
        dbDelta( $sql_list_items );

        // NEW: duplicates ledger (per import duplicate rows)
        $dup_table = $wpdb->prefix . 'email_import_duplicates';
        $sql_dupes = "CREATE TABLE $dup_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            list_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(191) NOT NULL,
            first_name VARCHAR(191) NULL,
            last_name VARCHAR(191) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY list_id (list_id),
            KEY contact_id (contact_id),
            KEY email (email),
            KEY created_at (created_at)
        ) $charset;";
        dbDelta( $sql_dupes );

        // Ensure uploads dir exists
        Helpers::ensure_uploads_dir();
    }
}

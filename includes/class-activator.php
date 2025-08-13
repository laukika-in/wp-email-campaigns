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
        $dupes     = Helpers::table('dupes');

        // ---- Clean CREATE statements (no inline comments) ----
        $sql_contacts = "CREATE TABLE $contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(191) NOT NULL,
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

        $sql_lists = "CREATE TABLE $lists (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            status ENUM('importing','ready','failed') NOT NULL DEFAULT 'importing',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            source_filename VARCHAR(191) NULL,
            file_path TEXT NULL,
            file_pointer BIGINT NULL,
            header_map LONGTEXT NULL,
            total INT UNSIGNED NOT NULL DEFAULT 0,
            imported INT UNSIGNED NOT NULL DEFAULT 0,
            invalid INT UNSIGNED NOT NULL DEFAULT 0,
            duplicates INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;";

        $sql_list_items = "CREATE TABLE $listItems (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            list_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            is_duplicate_import TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY list_contact (list_id, contact_id),
            KEY list_id (list_id),
            KEY contact_id (contact_id),
            KEY is_duplicate_import (is_duplicate_import)
        ) $charset;";

        $sql_dupes = "CREATE TABLE $dupes (
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

        dbDelta( $sql_contacts );
        dbDelta( $sql_subs );
        dbDelta( $sql_logs );
        dbDelta( $sql_lists );
        dbDelta( $sql_list_items );
        dbDelta( $sql_dupes );

        // ---- Post-dbDelta safety: add missing columns explicitly (idempotent) ----
        self::ensure_column( $lists, 'header_map', "LONGTEXT NULL" );
        self::ensure_column( $lists, 'file_pointer', "BIGINT NULL" );
        self::ensure_column( $lists, 'file_path', "TEXT NULL" );

        self::ensure_column( $listItems, 'is_duplicate_import', "TINYINT(1) NOT NULL DEFAULT 0" );
        self::ensure_column( $listItems, 'created_at', "DATETIME NULL" );

        // New contact fields (in case table pre-existed)
        self::ensure_column( $contacts, 'first_name', "VARCHAR(191) NULL" );
        self::ensure_column( $contacts, 'last_name', "VARCHAR(191) NULL" );
        self::ensure_column( $contacts, 'company_name', "VARCHAR(191) NULL" );
        self::ensure_column( $contacts, 'company_employees', "INT NULL" );
        self::ensure_column( $contacts, 'company_annual_revenue', "BIGINT NULL" );
        self::ensure_column( $contacts, 'contact_number', "VARCHAR(64) NULL" );
        self::ensure_column( $contacts, 'job_title', "VARCHAR(191) NULL" );
        self::ensure_column( $contacts, 'industry', "VARCHAR(191) NULL" );
        self::ensure_column( $contacts, 'country', "VARCHAR(191) NULL" );
        self::ensure_column( $contacts, 'state', "VARCHAR(191) NULL" );
        self::ensure_column( $contacts, 'city', "VARCHAR(191) NULL" );
        self::ensure_column( $contacts, 'postal_code', "VARCHAR(32) NULL" );
    }

    private static function column_exists( $table, $column ) {
        global $wpdb;
        $table_esc = esc_sql( $table );
        $column_esc = esc_sql( $column );
        $sql = "SHOW COLUMNS FROM `$table_esc` LIKE %s";
        $found = $wpdb->get_var( $wpdb->prepare( $sql, $column_esc ) );
        return ! is_null( $found );
    }

    private static function ensure_column( $table, $column, $definition ) {
        if ( ! self::column_exists( $table, $column ) ) {
            global $wpdb;
            $table_esc = esc_sql( $table );
            // Avoid reserved words and ensure safe identifiers
            $col_esc = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            $sql = "ALTER TABLE `$table_esc` ADD COLUMN `$col_esc` $definition";
            $wpdb->query( $sql );
        }
    }
}

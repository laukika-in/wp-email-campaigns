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
            deleted INT UNSIGNED NOT NULL DEFAULT 0,
            manual_added INT UNSIGNED NOT NULL DEFAULT 0,
            last_invalid INT UNSIGNED NOT NULL DEFAULT 0,
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

        // Ensure missing columns (idempotent)
        self::ensure_column( $lists, 'header_map',    "LONGTEXT NULL" );
        self::ensure_column( $lists, 'file_pointer',  "BIGINT NULL" );
        self::ensure_column( $lists, 'file_path',     "TEXT NULL" );
        self::ensure_column( $lists, 'deleted',       "INT UNSIGNED NOT NULL DEFAULT 0" );
        self::ensure_column( $lists, 'manual_added',  "INT UNSIGNED NOT NULL DEFAULT 0" );
        self::ensure_column( $lists, 'last_invalid',  "INT UNSIGNED NOT NULL DEFAULT 0" );

        self::ensure_column( $listItems, 'is_duplicate_import', "TINYINT(1) NOT NULL DEFAULT 0" );
        self::ensure_column( $listItems, 'created_at',          "DATETIME NULL" );

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
          self::maybe_create_campaign_tables();

          // Secret handling
if (defined('WPEC_SECRET') && WPEC_SECRET) {
    // Mirror the constant in the DB for consistency (optional)
    update_option('wpec_secret', WPEC_SECRET, false);
} else {
    $secret = get_option('wpec_secret');
    if (!$secret) {
        $secret = self::generate_secret();
        add_option('wpec_secret', $secret, '', 'no');
    }
    // OPTIONAL: try writing to wp-config.php (best-effort)
    $wrote = self::maybe_write_wp_config_define($secret);
    if (!$wrote) {
        // show an admin notice once with copy/paste instruction
        update_option('wpec_secret_write_needed', 1, false);
    }
}



    }
public static function maybe_admin_notice() {
    if (!get_option('wpec_secret_write_needed')) return;
    delete_option('wpec_secret_write_needed');
    $secret = esc_html(get_option('wpec_secret'));
    echo '<div class="notice notice-warning"><p>'
       . 'WP Email Campaigns could not write <code>WPEC_SECRET</code> to <code>wp-config.php</code>. '
       . 'Please add this line manually:<br>'
       . '<code>define(\'WPEC_SECRET\', \'' . $secret . '\');</code>'
       . '</p></div>';
}

    private static function column_exists( $table, $column ) {
        global $wpdb;
        $sql = "SHOW COLUMNS FROM `$table` LIKE %s";
        $found = $wpdb->get_var( $wpdb->prepare( $sql, $column ) );
        return ! is_null( $found );
    }

    private static function ensure_column( $table, $column, $definition ) {
        if ( ! self::column_exists( $table, $column ) ) {
            global $wpdb;
            $col = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `$col` $definition" );
        }
    }
    public static function maybe_create_campaign_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $campaigns = $wpdb->prefix . 'wpec_campaigns';
    $maps      = $wpdb->prefix . 'wpec_campaign_lists';

    $sql1 = "CREATE TABLE IF NOT EXISTS $campaigns (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(190) NOT NULL DEFAULT '',
        subject VARCHAR(255) NOT NULL DEFAULT '',
        from_name VARCHAR(190) NULL,
        from_email VARCHAR(190) NULL,
        body_html LONGTEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        queued_count INT UNSIGNED NOT NULL DEFAULT 0,
        sent_count INT UNSIGNED NOT NULL DEFAULT 0,
        failed_count INT UNSIGNED NOT NULL DEFAULT 0,
        options_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        published_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY status_idx (status),
        KEY published_idx (published_at)
    ) $charset;";

    $sql2 = "CREATE TABLE IF NOT EXISTS $maps (
        campaign_id BIGINT UNSIGNED NOT NULL,
        list_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (campaign_id, list_id),
        KEY list_idx (list_id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql1);
    dbDelta($sql2);
}
private static function locate_wp_config(): ?string {
    if (file_exists(ABSPATH . 'wp-config.php')) return ABSPATH . 'wp-config.php';
    $up = dirname(ABSPATH);
    if (file_exists($up . '/wp-config.php')) return $up . '/wp-config.php';
    return null;
}

public static function generate_secret(): string {
    try {
        $bytes = random_bytes(48);
        $raw   = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    } catch (\Throwable $e) {
        $raw = wp_generate_password(64, true, true);
    }
    return $raw;
}

private static function maybe_write_wp_config_define(string $secret): bool {
    $path = self::locate_wp_config();
    if (!$path || !is_writable($path)) return false;

    $cfg = @file_get_contents($path);
    if ($cfg === false) return false;

    if (strpos($cfg, "define('WPEC_SECRET'") !== false) return true; // already there

    $line = "define('WPEC_SECRET', '" . addslashes($secret) . "');\n";
    $needle = "/* That's all, stop editing! */";
    if (strpos($cfg, $needle) !== false) {
        $cfg = str_replace($needle, $line . $needle, $cfg);
    } else {
        $cfg .= "\n" . $line;
    }
    return (bool) @file_put_contents($path, $cfg);
}

}

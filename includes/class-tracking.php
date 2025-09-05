<?php
namespace WPEC;
if ( ! defined('ABSPATH') ) exit;

class Tracking {

    public static function init() {
        // Ensure columns exist both front and admin so REST hits never fail.
        add_action('init',       [__CLASS__, 'maybe_add_columns']);
        add_action('admin_init', [__CLASS__, 'maybe_add_columns']);

        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /* --------------------------
     * Minimal lazy migration
     * ------------------------*/
    public static function maybe_add_columns() {
        global $wpdb;

        // Logs table
        $logs = Helpers::table('logs');
        $subs      = Helpers::table('subs');

        if ($logs) {
            $cols = $wpdb->get_col( "SHOW COLUMNS FROM `$logs`", 0 );
            if ($cols) {
                $has = fn($n) => in_array($n, $cols, true);
            if (! $has('queue_id'))   $wpdb->query("ALTER TABLE `$logs` ADD `queue_id` BIGINT UNSIGNED NULL");
            if (! $has('link_url'))   $wpdb->query("ALTER TABLE `$logs` ADD `link_url` TEXT NULL");
            if (! $has('user_agent')) $wpdb->query("ALTER TABLE `$logs` ADD `user_agent` TEXT NULL");
            if (! $has('ip'))         $wpdb->query("ALTER TABLE `$logs` ADD `ip` VARBINARY(16) NULL");
// In logs table ensures (after $cols check)
if (!in_array('email',     $cols, true)) $wpdb->query("ALTER TABLE `$logs` ADD `email` VARCHAR(191) NULL");
if (!in_array('status',    $cols, true)) $wpdb->query("ALTER TABLE `$logs` ADD `status` VARCHAR(20) NULL");
if (!in_array('sent_at',   $cols, true)) $wpdb->query("ALTER TABLE `$logs` ADD `sent_at` DATETIME NULL");
if (!in_array('opened_at', $cols, true)) $wpdb->query("ALTER TABLE `$logs` ADD `opened_at` DATETIME NULL");
if (!in_array('bounced_at',$cols, true)) $wpdb->query("ALTER TABLE `$logs` ADD `bounced_at` DATETIME NULL");

                // Add 'clicked' to ENUM if missing
                $row = $wpdb->get_row( $wpdb->prepare("SHOW COLUMNS FROM `$logs` LIKE %s", 'event') );
                if ($row && isset($row->Type) && strpos($row->Type, 'ENUM(') === 0 && strpos($row->Type, "'clicked'") === false) {
                    $wpdb->query("
                        ALTER TABLE `$logs`
                        MODIFY COLUMN `event`
                        ENUM('queued','sent','delivered','opened','bounced','failed','clicked') NOT NULL
                    ");
                }
            }
        }

        // Per-recipient counters live on the subscribers table 
        if ($subs) {
            $scols = $wpdb->get_col( "SHOW COLUMNS FROM `$subs`", 0 );
            if ($scols) {
                $has = fn($n) => in_array($n, $scols, true);
                if (! $has('opens_count'))      $wpdb->query("ALTER TABLE `$subs` ADD `opens_count` INT UNSIGNED NOT NULL DEFAULT 0");
                if (! $has('first_open_at'))    $wpdb->query("ALTER TABLE `$subs` ADD `first_open_at` DATETIME NULL");
                if (! $has('last_open_at'))     $wpdb->query("ALTER TABLE `$subs` ADD `last_open_at` DATETIME NULL");
                if (! $has('clicks_count'))     $wpdb->query("ALTER TABLE `$subs` ADD `clicks_count` INT UNSIGNED NOT NULL DEFAULT 0");
                if (! $has('last_click_at'))    $wpdb->query("ALTER TABLE `$subs` ADD `last_click_at` DATETIME NULL");
                if (! $has('last_activity_at')) $wpdb->query("ALTER TABLE `$subs` ADD `last_activity_at` DATETIME NULL");
                // Optional: delivery status for later (bounced/complaint/unsub)
                if (! $has('delivery_status'))  $wpdb->query("ALTER TABLE `$subs` ADD `delivery_status` VARCHAR(20) NOT NULL DEFAULT 'sent'");
                
            if (! $has('attempts'))   $wpdb->query("ALTER TABLE `$subs` ADD `attempts` INT UNSIGNED NOT NULL DEFAULT 0");
            if (! $has('last_error')) $wpdb->query("ALTER TABLE `$subs` ADD `last_error` TEXT NULL");
            if (! $has('sent_at'))    $wpdb->query("ALTER TABLE `$subs` ADD `sent_at` DATETIME NULL");
            if (! $has('updated_at')) $wpdb->query("ALTER TABLE `$subs` ADD `updated_at` DATETIME NULL");
            }
        }
    }

    /* --------------------------
     * Secret + token helpers
     * ------------------------*/
    protected static function secret() {
        if (defined('WPEC_SECRET') && WPEC_SECRET) return WPEC_SECRET;
        $s = get_option('wpec_secret');
        if (!$s) { $s = wp_generate_password(64, true, true); update_option('wpec_secret', $s); }
        return $s;
    }

    protected static function b64u($s){ return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    protected static function ub64($s){ return base64_decode(strtr($s, '-_', '+/')); }

    protected static function sign(array $payload): string {
        $body = self::b64u(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $h   = hash_hmac('sha256', $body, self::secret(), true);
        return $body.'.'.self::b64u($h);
    }
    protected static function verify(string $token): ?array {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return null;
        [$body, $sig] = $parts;
        $calc = self::b64u(hash_hmac('sha256', $body, self::secret(), true));
        if (!hash_equals($calc, $sig)) return null;
        $json = self::ub64($body);
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /* --------------------------
     * HTML instrumentation
     * ------------------------*/
    public static function instrument_html(int $campaign_id, int $contact_id, string $html): string {
        $rest = rest_url('email-campaigns/v1');

        // 1) Rewrite links (skip mailto, tel, js, anchors, and our own endpoints)
        $html = preg_replace_callback(
            '#<a\b([^>]*?)\bhref=("|\')(.*?)\2([^>]*)>#si',
            function($m) use ($campaign_id,$contact_id,$rest){
                $pre  = $m[1]; $q = $m[2]; $href = trim(html_entity_decode($m[3], ENT_QUOTES)); $post = $m[4];

                if ($href === '' ||
                    str_starts_with($href, 'mailto:') ||
                    str_starts_with($href, 'tel:') ||
                    str_starts_with($href, 'javascript:') ||
                    $href[0] === '#' ||
                    str_contains($href, '/email-campaigns/v1/')
                ) {
                    return "<a{$pre}href={$q}".esc_attr($href)."{$q}{$post}>";
                }

                $tok   = self::sign(['t'=>'c','c'=>$campaign_id,'ct'=>$contact_id,'u'=>$href]);
                $track = trailingslashit($rest).'c/'.$tok;
                return "<a{$pre}href={$q}".esc_attr($track)."{$q}{$post}>";
            },
            $html
        );

        // 2) Tracking pixel
        // 2) Tracking pixel (use query param endpoint)
        $tok   = self::sign(['t'=>'o','c'=>$campaign_id,'ct'=>$contact_id,'r'=>wp_rand()]);
        $pix   = add_query_arg('token', $tok, rest_url('email-campaigns/v1/open'));
        $pixel = sprintf(
        '<img src="%s" width="1" height="1" alt="" style="display:none!important;max-width:1px!important;max-height:1px!important;border:0;" />',
        esc_url($pix)
        );

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $pixel.'</body>', $html, 1);
        }
        return $html.$pixel;
    }

    /* --------------------------
     * REST routes
     * ------------------------*/
 
   public static function register_routes() {
    // OPEN (pixel) — allow dots in token and the .gif suffix
    register_rest_route('email-campaigns/v1', '/o/(?P<token>.+)\.gif', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => [__CLASS__, 'rest_open'],
    ]);

    // OPEN (fallback, no .gif) — some servers mis-handle .gif under /wp-json
    register_rest_route('email-campaigns/v1', '/o/(?P<token>.+)', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => [__CLASS__, 'rest_open'],
    ]);

    // OPEN (query fallback) — /wp-json/email-campaigns/v1/open?token=...
    register_rest_route('email-campaigns/v1', '/open', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function( \WP_REST_Request $req ) {
            $req['token'] = (string) $req->get_param('token');
            return call_user_func([__CLASS__, 'rest_open'], $req);
        },
    ]);

    // CLICK — allow dots just in case
    register_rest_route('email-campaigns/v1', '/c/(?P<token>.+)', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => [__CLASS__, 'rest_click'],
    ]);
}


    public static function rest_open(\WP_REST_Request $req) {
        error_log('[WPEC] OPEN hit token='.substr((string)$req['token'],0,24));
        $d = self::verify((string)$req['token']);
        if (!$d || ($d['t']??'')!=='o') return self::gif_1x1();
        self::log_event((int)$d['c'], (int)$d['ct'], 'opened', null);
        return self::gif_1x1();
    }

    protected static function is_link_scanner(?string $ua): bool {
        if (!$ua) return false;
        $ua = strtolower($ua);
        return (
            str_contains($ua, 'googleimageproxy') ||
            str_contains($ua, 'facebookexternalhit') ||
            str_contains($ua, 'linkedinbot') ||
            str_contains($ua, 'skypeuripreview') ||
            str_contains($ua, 'twitterbot') ||
            str_contains($ua, 'microsoft office') ||    // Outlook desktop
            str_contains($ua, 'outlook') ||              // Outlook/Microsoft safe links
            str_contains($ua, 'curl/') ||
            str_contains($ua, 'wget/')
        );
    }

   public static function rest_click(\WP_REST_Request $req) {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $d  = self::verify((string)$req['token']);
    $dest = home_url('/');
    if ($d && ($d['t']??'')==='c' && !empty($d['u'])) {
        $dest = (string)$d['u'];
        if (!self::is_link_scanner($ua)) {
            self::log_event((int)$d['c'], (int)$d['ct'], 'clicked', $dest);
        }
    }
    return new \WP_REST_Response(null, 302, ['Location' => $dest]);
}



    /* --------------------------
     * Log + update per-recipient counters (on `subs`)
     * ------------------------*/
    protected static function log_event_and_counters(int $campaign_id, int $contact_id, string $event, ?string $link_url) {
        global $wpdb;
        $logs = Helpers::table('logs');
        $subs = Helpers::table('subs');
        $ct   = Helpers::table('contacts');

        // UA + IP
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr(wp_unslash($_SERVER['HTTP_USER_AGENT']), 0, 1000) : null;
        $ip = null;
        if (!empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            $ip = @inet_pton($_SERVER['REMOTE_ADDR']);
        }

        // Resolve email for logs.email (optional)
        $email = null;
        if ($ct && $contact_id) {
            $email = $wpdb->get_var($wpdb->prepare("SELECT email FROM $ct WHERE id=%d", $contact_id));
        }

        // 1) Append detailed event row to LOGS
        if ($logs) {
            $row = [
                'campaign_id'   => $campaign_id,
                'subscriber_id' => $contact_id,
                'email'         => $email,
                'status'        => $event,           // convenience mirror
                'event'         => $event,           // 'opened' | 'clicked'
                'provider_message_id' => null,
                'info'          => null,
                'event_time'    => Helpers::now(),   // always set
                'created_at'    => Helpers::now(),   // always set
                'queue_id'      => null,
                'link_url'      => $link_url,
                'user_agent'    => $ua,
                'ip'            => $ip,
            ];
            if ($event === 'opened') {
                $row['opened_at'] = Helpers::now();
            }
            $wpdb->insert($logs, $row);
        }

        // 2) Update per-recipient counters on SUBS
        if ($subs && $campaign_id && $contact_id) {
            if ($event === 'opened') {
                $wpdb->query($wpdb->prepare("
                    UPDATE $subs
                    SET opens_count      = COALESCE(opens_count,0) + 1,
                        first_open_at    = IF(first_open_at IS NULL, NOW(), first_open_at),
                        last_open_at     = NOW(),
                        last_activity_at = NOW()
                    WHERE campaign_id = %d AND contact_id = %d
                ", $campaign_id, $contact_id));
            } elseif ($event === 'clicked') {
                $wpdb->query($wpdb->prepare("
                    UPDATE $subs
                    SET clicks_count     = COALESCE(clicks_count,0) + 1,
                        last_click_at    = NOW(),
                        last_activity_at = NOW()
                    WHERE campaign_id = %d AND contact_id = %d
                ", $campaign_id, $contact_id));
            }
        }
    }


    // Back-compat alias so REST calls work
    protected static function log_event(int $campaign_id, int $contact_id, string $event, ?string $link_url) {
        return self::log_event_and_counters($campaign_id, $contact_id, $event, $link_url);
    }


    protected static function gif_1x1() {
        $gif = base64_decode('R0lGODlhAQABAPAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
        return new \WP_REST_Response($gif, 200, [
            'Content-Type'  => 'image/gif',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }
}

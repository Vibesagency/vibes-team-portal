<?php
defined('ABSPATH') || exit;

final class VPM_Push {
    private static $queue = [];
    const ICON = 'https://team.vibesagency.co/wp-content/uploads/2026/11/vibes-app-logo-s-192.png';

    public static function b64u_enc(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    public static function b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

    private static function point(array $ec): string { return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT); }

    // Server (VAPID) key pair, created once and kept in the database. The private half is never sent to browsers.
    public static function keys(): array {
        $k = get_option('vpm_push_vapid');
        if (is_array($k) && !empty($k['priv']) && !empty($k['pub'])) return $k;
        $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($res, $pem);
        $k = ['priv' => $pem, 'pub' => self::b64u_enc(self::point(openssl_pkey_get_details($res)['ec']))];
        update_option('vpm_push_vapid', $k, false);
        return $k;
    }

    // RFC 8291 (aes128gcm) payload encryption for Web Push.
    public static function encrypt(string $payload, string $ua_pub_b64, string $auth_b64) {
        $ua = self::b64u_dec($ua_pub_b64); $auth = self::b64u_dec($auth_b64);
        if (strlen($ua) !== 65 || $ua[0] !== "\x04" || strlen($auth) < 16) return false;
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $ua;
        $peer = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n");
        $eph = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$peer || !$eph) return false;
        $as_pub = self::point(openssl_pkey_get_details($eph)['ec']);
        $secret = openssl_pkey_derive($peer, $eph, 32);
        if ($secret === false) return false;
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $ua . $as_pub, $auth);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ct === false) return false;
        return $salt . pack('N', 4096) . chr(65) . $as_pub . $ct . $tag;
    }

    public static function jwt(string $aud, string $pem, string $sub) {
        $in = self::b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . self::b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => $sub]));
        $key = openssl_pkey_get_private($pem);
        if (!$key || !openssl_sign($in, $der, $key, OPENSSL_ALGO_SHA256)) return false;
        $o = 2; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
        $rl = ord($der[$o + 1]); $r = substr($der, $o + 2, $rl); $o2 = $o + 2 + $rl; $sl = ord($der[$o2 + 1]); $s = substr($der, $o2 + 2, $sl);
        $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT); $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
        return $in . '.' . self::b64u_enc($r . $s);
    }

    public static function send(array $sub, array $payload): int {
        $k = self::keys();
        $body = self::encrypt(wp_json_encode($payload), (string) $sub['p256dh'], (string) $sub['auth']);
        $p = wp_parse_url((string) $sub['endpoint']);
        if ($body === false || empty($p['host'])) return 0;
        $jwt = self::jwt($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''), $k['priv'], 'mailto:admin@vibesagency.co');
        if (!$jwt) return 0;
        $r = wp_remote_post($sub['endpoint'], ['timeout' => 8, 'body' => $body, 'headers' => ['Authorization' => 'vapid t=' . $jwt . ', k=' . $k['pub'], 'Content-Encoding' => 'aes128gcm', 'Content-Type' => 'application/octet-stream', 'TTL' => '86400', 'Urgency' => 'normal']]);
        $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        $log = (array) get_option('vpm_push_log', []); $log[] = ['t' => current_time('mysql'), 'tag' => (string) ($payload['tag'] ?? ''), 'code' => $code, 'err' => is_wp_error($r) ? substr($r->get_error_message(), 0, 80) : '', 'host' => $p['host']];
        update_option('vpm_push_log', array_slice($log, -15), false);
        return $code;
    }

    public static function rest_status(WP_REST_Request $request) {
        global $wpdb; $N = VPM_Permissions::table('notifications');
        if ($request->get_param('test')) self::send_user(get_current_user_id(), ['title' => 'Vibes test', 'body' => 'Direct push test from the portal', 'tag' => 'dbg-' . time(), 'url' => '/team-portal/', 'icon' => self::ICON]);
        return rest_ensure_response(['my_subscriptions' => count((array) get_user_meta(get_current_user_id(), 'vpm_push_subs', true)), 'last_id' => $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='vpm_push_last_id'"), 'max_notification_id' => (int) $wpdb->get_var("SELECT MAX(id) FROM {$N}"), 'log' => (array) get_option('vpm_push_log', [])]);
    }

    public static function send_user(int $uid, array $payload): void {
        global $wpdb; $payload['count'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . VPM_Permissions::table('notifications') . ' WHERE user_id=%d AND is_read=0', $uid));
        $subs = (array) get_user_meta($uid, 'vpm_push_subs', true); $changed = false;
        foreach ($subs as $key => $sub) {
            if (!is_array($sub) || empty($sub['endpoint'])) { unset($subs[$key]); $changed = true; continue; }
            $code = self::send($sub, $payload);
            if ($code === 404 || $code === 410) { unset($subs[$key]); $changed = true; }
        }
        if ($changed) update_user_meta($uid, 'vpm_push_subs', $subs);
    }

    public static function queue(int $uid, array $payload): void {
        if (!get_user_meta($uid, 'vpm_push_subs', true)) return;
        self::$queue[] = [$uid, $payload];
        if (count(self::$queue) === 1) add_action('shutdown', [self::class, 'flush'], 99);
    }

    public static function flush(): void {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        foreach (self::$queue as $item) self::send_user($item[0], $item[1]);
        self::$queue = [];
    }

    // Sends a phone push for every portal notification (tasks, approvals, leave, announcements...) shortly after it is created.
    public static function sweep(): void {
        $rest_write = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
        if (!$rest_write && !(defined('DOING_CRON') && DOING_CRON)) return;
        global $wpdb; $N = VPM_Permissions::table('notifications');
        $last = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='vpm_push_last_id'");
        $max = (int) $wpdb->get_var("SELECT MAX(id) FROM {$N}");
        if ($last === null) { add_option('vpm_push_last_id', (string) $max, '', 'no'); return; }
        if ($max <= (int) $last) return;
        $won = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name='vpm_push_last_id' AND option_value=%s", (string) $max, (string) $last));
        if (!$won) return;
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,user_id,type,title,body FROM {$N} WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT 100", (int) $last, $max), ARRAY_A);
        foreach ((array) $rows as $r) {
            if ($r['type'] === 'connect_mention') continue; // chat messages and mentions are pushed directly when sent
            if ($r['type'] === 'connect_message' && preg_match('/^(New message in|\d+ new messages)/', (string) $r['title'])) continue;
            $uid = (int) $r['user_id']; if (!$uid || !get_user_meta($uid, 'vpm_push_subs', true)) continue;
            self::send_user($uid, ['title' => html_entity_decode(wp_strip_all_tags((string) $r['title']), ENT_QUOTES, 'UTF-8'), 'body' => html_entity_decode(wp_trim_words(wp_strip_all_tags((string) $r['body']), 22, '…'), ENT_QUOTES, 'UTF-8'), 'tag' => 'n-' . $r['id'], 'url' => '/team-portal/', 'icon' => self::ICON]);
        }
    }

    public static function rest_key(WP_REST_Request $request) { return rest_ensure_response(['key' => self::keys()['pub']]); }

    public static function rest_subscribe(WP_REST_Request $request) {
        $b = (array) $request->get_json_params(); $keys = (array) ($b['keys'] ?? []);
        $endpoint = esc_url_raw((string) ($b['endpoint'] ?? '')); $host = (string) wp_parse_url($endpoint, PHP_URL_HOST);
        if (!$endpoint || strpos($endpoint, 'https://') !== 0 || !preg_match('/(^|\.)(googleapis\.com|push\.services\.mozilla\.com|push\.apple\.com|notify\.windows\.com)$/i', $host)) return new WP_Error('bad_endpoint', 'This browser push service is not supported.', ['status' => 400]);
        $p256 = (string) ($keys['p256dh'] ?? ''); $auth = (string) ($keys['auth'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{80,100}$/', $p256) || !preg_match('/^[A-Za-z0-9_-]{16,40}$/', $auth)) return new WP_Error('bad_keys', 'Invalid subscription.', ['status' => 400]);
        $me = get_current_user_id(); $subs = (array) get_user_meta($me, 'vpm_push_subs', true);
        $subs[md5($endpoint)] = ['endpoint' => $endpoint, 'p256dh' => $p256, 'auth' => $auth, 'at' => current_time('mysql')];
        if (count($subs) > 5) $subs = array_slice($subs, -5, null, true);
        update_user_meta($me, 'vpm_push_subs', $subs);
        $code = self::send($subs[md5($endpoint)], ['title' => 'Vibes Connect', 'body' => 'Phone notifications are on. You will see new messages and mentions here.', 'tag' => 'vc-welcome', 'url' => '/team-portal/', 'icon' => self::ICON]);
        return rest_ensure_response(['subscribed' => true, 'test_status' => $code]);
    }

    public static function rest_unsubscribe(WP_REST_Request $request) {
        $b = (array) $request->get_json_params(); $endpoint = esc_url_raw((string) ($b['endpoint'] ?? ''));
        $me = get_current_user_id(); $subs = (array) get_user_meta($me, 'vpm_push_subs', true);
        if ($endpoint) unset($subs[md5($endpoint)]); else $subs = [];
        update_user_meta($me, 'vpm_push_subs', $subs);
        return rest_ensure_response(['subscribed' => false]);
    }
}

final class VPM_UI {
    public static function boot(): void {
        self::sync_admin_display_name();
        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 10);
        add_action('wp_enqueue_scripts', [self::class, 'trim_portal_scripts'], 1000);
        add_action('init', [self::class, 'pwa_serve'], 1);
        add_action('wp_head', [self::class, 'pwa_head'], 3);
        add_action('wp', static function () { if (is_page(['team-portal', 'team-login'])) remove_action('wp_head', ['VTP_App', 'favicon'], 2); });
        add_action('shutdown', ['VPM_Push', 'sweep'], 98);
    }

    private const PWA_I192 = 'https://team.vibesagency.co/wp-content/uploads/2026/11/vibes-app-logo-s-192.png';
    private const PWA_I512 = 'https://team.vibesagency.co/wp-content/uploads/2026/11/vibes-app-logo-s-512.png';
    private const PWA_M512 = 'https://team.vibesagency.co/wp-content/uploads/2026/11/vibes-app-logo-s-512-maskable.png';

    // Installable app support: web app manifest + a tiny service worker served from the site root.
    public static function pwa_serve(): void {
        $path = rtrim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        if ($path !== '/vibes-sw.js' && $path !== '/vibes-manifest.webmanifest') return;
        do_action('litespeed_control_set_nocache', 'vibes pwa');
        nocache_headers();
        if ($path === '/vibes-sw.js') {
            header('Content-Type: application/javascript; charset=utf-8');
            header('Service-Worker-Allowed: /');
            echo <<<'JS'
const OFFLINE = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vibes</title><body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#F7F5FA;color:#1B1530;text-align:center"><div><h2 style="color:#5B2A9A">You are offline</h2><p>Check your internet connection, then reopen the Vibes app.</p></div></body>';
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (e) => {
  if (e.request.mode === 'navigate') e.respondWith(fetch(e.request).catch(() => new Response(OFFLINE, {headers: {'Content-Type': 'text/html; charset=utf-8'}})));
});
self.addEventListener('push', (e) => {
  let d = {}; try { d = e.data ? e.data.json() : {}; } catch (_) { d = {body: e.data ? e.data.text() : ''}; }
  e.waitUntil(self.registration.showNotification(d.title || 'Vibes Connect', {body: d.body || '', icon: d.icon, badge: d.icon, tag: d.tag || 'vc', renotify: true, data: {url: d.url || '/team-portal/'}}).then(() => (self.navigator && self.navigator.setAppBadge && d.count > 0) ? self.navigator.setAppBadge(d.count) : null).catch(() => {}));
});
self.addEventListener('notificationclick', (e) => {
  e.notification.close(); const url = (e.notification.data && e.notification.data.url) || '/team-portal/';
  e.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then((list) => { for (const c of list) { if ('focus' in c) return c.focus(); } return self.clients.openWindow(url); }));
});
JS;
            exit;
        }
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo wp_json_encode([
            'name' => 'Vibes Team Portal', 'short_name' => 'Vibes', 'description' => 'Vibes Agency team portal and Vibes Connect chat.',
            'id' => '/team-portal/', 'start_url' => '/team-portal/', 'scope' => '/', 'display' => 'standalone', 'orientation' => 'portrait-primary',
            'background_color' => '#F7F5FA', 'theme_color' => '#5B2A9A',
            'icons' => [
                ['src' => self::PWA_I192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => self::PWA_I512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => self::PWA_M512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function pwa_head(): void {
        if (!is_page(['team-portal', 'team-login'])) return;
        echo '<link rel="manifest" href="/vibes-manifest.webmanifest">' . "\n";
        echo '<meta name="theme-color" content="#5B2A9A"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="Vibes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
        echo '<link rel="apple-touch-icon" href="' . esc_url(self::PWA_I512) . '">' . "\n";
        echo '<link rel="icon" type="image/png" sizes="192x192" href="' . esc_url(self::PWA_I192) . '">' . "\n";
        echo '<link rel="icon" type="image/png" sizes="512x512" href="' . esc_url(self::PWA_I512) . '">' . "\n";
        echo '<link rel="shortcut icon" href="' . esc_url(self::PWA_I192) . '">' . "\n";
        $banner = is_page('team-portal') && is_user_logged_in();
        echo "<script>(function(){if('serviceWorker' in navigator){window.addEventListener('load',function(){navigator.serviceWorker.register('/vibes-sw.js',{scope:'/'}).catch(function(){});});}\n";
        echo "(function(){if(!('setAppBadge' in navigator)||!document.cookie)return;function b(){if(document.visibilityState!=='visible'||!window.VTP)return;fetch('/wp-json/vibes/v1/notifications',{headers:{'X-WP-Nonce':VTP.nonce},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(l){var n=Array.isArray(l)?l.filter(function(x){return x.is_read==='0'||x.is_read===0}).length:0;return n>0?navigator.setAppBadge(n):navigator.clearAppBadge()}).catch(function(){})}window.addEventListener('load',function(){setTimeout(b,1500)});document.addEventListener('visibilitychange',b);setInterval(b,60000)})();\n";
        if ($banner) echo <<<'JS'
var sa=window.matchMedia('(display-mode: standalone)').matches||navigator.standalone;if(sa)return;
try{if(localStorage.getItem('vpmInstallDismissed'))return;}catch(e){}
var ua=navigator.userAgent,ios=/iphone|ipad|ipod/i.test(ua),mob=/android|iphone|ipad|ipod/i.test(ua);if(!mob)return;
function show(msg,label,fn){if(document.getElementById('vpm-install'))return;var d=document.createElement('div');d.id='vpm-install';d.setAttribute('style','position:fixed;left:12px;right:12px;bottom:12px;z-index:99999;background:#5B2A9A;color:#fff;border-radius:14px;padding:12px 14px;display:flex;gap:10px;align-items:center;font:600 14px/1.3 system-ui,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.25)');var t=document.createElement('span');t.style.flex='1';t.textContent=msg;d.appendChild(t);if(label){var b=document.createElement('button');b.textContent=label;b.setAttribute('style','background:#fff;color:#5B2A9A;border:0;border-radius:10px;padding:8px 14px;font:700 14px system-ui');b.onclick=function(){fn();d.remove();};d.appendChild(b);}var x=document.createElement('button');x.textContent='×';x.setAttribute('aria-label','Dismiss');x.setAttribute('style','background:none;border:0;color:#fff;font-size:22px;line-height:1;padding:0 4px');x.onclick=function(){try{localStorage.setItem('vpmInstallDismissed','1');}catch(e){}d.remove();};d.appendChild(x);document.body.appendChild(d);}
var dp=null;window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();dp=e;show('Install the Vibes app on your phone','Install',function(){dp.prompt();});});
if(ios)window.addEventListener('load',function(){setTimeout(function(){show('Install Vibes: tap Share, then Add to Home Screen','',null);},2500);});
JS;
        echo "\n})();</script>\n";
    }

    public static function sync_admin_display_name(): void {
        $user = get_user_by('email', 'admin@vibesagency.co') ?: get_user_by('login', 'admin');
        if (!$user || $user->display_name === 'Mohammad Alty') return;
        wp_update_user([
            'ID' => $user->ID,
            'display_name' => 'Mohammad Alty',
            'first_name' => 'Mohammad',
            'last_name' => 'Alty',
        ]);
    }

    public static function trim_portal_scripts(): void {
        if (!is_page('team-portal')) return;
        wp_dequeue_script('hostinger_chatbot');
        wp_dequeue_script('hostinger-reach-embed');
        wp_dequeue_script('wp-emoji');
        wp_dequeue_script('wp-emoji-release');
        wp_dequeue_style('wp-emoji');
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
    }

    public static function enqueue(): void {
        if (!is_page('team-portal') || !is_user_logged_in()) return;
        $asset_version = '0.5.46-microtask-fix';
        add_filter('script_loader_src', static fn($src, $handle) => $handle === 'vtp-app' ? add_query_arg('vpm', 'ournavguard1', $src) : $src, 10, 2);
        wp_enqueue_style('vpm-modules', VPM_URL . 'assets/modules.css', [], $asset_version);
        wp_enqueue_script('vpm-modules', VPM_URL . 'assets/modules.js', [], $asset_version, true);
        wp_localize_script('vpm-modules', 'VPM', [
            'root' => esc_url_raw(rest_url('vibes/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'userId' => get_current_user_id(),
            'assetsUrl' => esc_url_raw(VPM_URL . 'assets/'),
        ]);
    }
}

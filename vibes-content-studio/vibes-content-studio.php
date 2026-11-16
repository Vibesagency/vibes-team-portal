<?php
/**
 * Plugin Name: Vibes Content Studio
 * Description: Internal workspace for Vibes employees: per-client brand profiles, AI content plans and image generation. No client access.
 * Version: 0.1.0
 * Author: Vibes Agency
 */
if (!defined('ABSPATH')) exit;

final class VCS {
    const NS = 'vcs/v1';
    const VER = '0.1.0';
    private const ASPECTS = ['square_1_1' => [1080, 1080], 'social_post_4_5' => [1080, 1350], 'social_story_9_16' => [1080, 1920], 'widescreen_16_9' => [1600, 900], 'classic_4_3' => [1200, 900]];

    public static function boot(): void {
        register_activation_hook(__FILE__, [self::class, 'install']);
        add_action('init', [self::class, 'maybe_install'], 5);
        add_action('template_redirect', [self::class, 'page'], 0);
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('login_redirect', [self::class, 'login_redirect'], 10, 3);
        add_filter('show_admin_bar', static function ($show) { return current_user_can('manage_options') ? $show : false; });
    }

    private static function t(string $n): string { global $wpdb; return $wpdb->prefix . 'vcs_' . $n; }

    public static function maybe_install(): void { if (get_option('vcs_db') !== self::VER) self::install(); }

    public static function install(): void {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::t('clients') . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(190) NOT NULL, brand LONGTEXT NULL, archived TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, PRIMARY KEY (id)) $c;");
        dbDelta("CREATE TABLE " . self::t('plans') . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, client_id BIGINT UNSIGNED NOT NULL, month VARCHAR(7) NOT NULL, brief TEXT NULL, data LONGTEXT NULL, created_by BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY client_id (client_id)) $c;");
        dbDelta("CREATE TABLE " . self::t('images') . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, client_id BIGINT UNSIGNED NOT NULL, plan_id BIGINT UNSIGNED NOT NULL DEFAULT 0, post_n INT NOT NULL DEFAULT 0, prompt TEXT NULL, aspect VARCHAR(30) NOT NULL DEFAULT 'social_post_4_5', task_id VARCHAR(80) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'queued', attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0, error VARCHAR(255) NOT NULL DEFAULT '', created_by BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY client_id (client_id), KEY plan_id (plan_id)) $c;");
        dbDelta("CREATE TABLE " . self::t('usage') . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, action VARCHAR(30) NOT NULL, units INT NOT NULL DEFAULT 0, note VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY created_at (created_at)) $c;");
        if (!get_role('vibes_employee')) add_role('vibes_employee', 'Vibes Employee', ['read' => true, 'vcs_use' => true]);
        $admin = get_role('administrator'); if ($admin) $admin->add_cap('vcs_use');
        update_option('vcs_db', self::VER, false);
    }

    /* ---------- settings ---------- */

    private static function cfg(): array {
        return array_merge(['claude_key' => '', 'magnific_key' => '', 'claude_model' => 'claude-sonnet-5-5', 'img_model' => 'flux-dev', 'cap' => 200, 'mock' => 1], (array) get_option('vcs_cfg', []));
    }

    /* ---------- page ---------- */

    public static function login_redirect($to, $req, $user) {
        if ($user instanceof WP_User && user_can($user, 'vcs_use') && !user_can($user, 'manage_options')) return home_url('/app/');
        return $to;
    }

    public static function page(): void {
        $path = rtrim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        if ($path !== '/app') return;
        status_header(200); nocache_headers(); header('X-Robots-Tag: noindex, nofollow'); do_action('litespeed_control_set_nocache', 'vcs app');
        if (!is_user_logged_in()) { wp_safe_redirect(wp_login_url(home_url('/app/'))); exit; }
        if (!current_user_can('vcs_use')) wp_die('This workspace is for Vibes employees only.', 'No access', ['response' => 403]);
        $u = wp_get_current_user();
        $boot = wp_json_encode(['root' => esc_url_raw(rest_url(self::NS . '/')), 'nonce' => wp_create_nonce('wp_rest'), 'admin' => current_user_can('manage_options'), 'user' => $u->display_name, 'logout' => wp_logout_url(home_url('/app/'))]);
        $base = plugin_dir_url(__FILE__) . 'assets/';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Vibes Studio</title><link rel="stylesheet" href="' . esc_url($base . 'app.css?v=' . self::VER) . '"></head><body><div id="app"><p style="padding:40px;color:#aaa">Loading…</p></div><script>window.VCS=' . $boot . ';</script><script src="' . esc_url($base . 'app.js?v=' . self::VER) . '"></script></body></html>';
        exit;
    }

    /* ---------- REST ---------- */

    public static function routes(): void {
        $use = static function () { return current_user_can('vcs_use'); };
        $adm = static function () { return current_user_can('manage_options'); };
        $r = static function (string $path, string $method, string $cb, callable $perm) { register_rest_route(self::NS, $path, ['methods' => $method, 'callback' => [self::class, $cb], 'permission_callback' => $perm]); };
        $r('/state', 'GET', 'api_state', $use);
        $r('/clients', 'POST', 'api_client_create', $use);
        $r('/clients/(?P<id>\d+)', 'GET', 'api_client_get', $use);
        $r('/clients/(?P<id>\d+)/brand', 'POST', 'api_brand_save', $use);
        $r('/clients/(?P<id>\d+)/plan', 'POST', 'api_plan_generate', $use);
        $r('/plans/(?P<id>\d+)', 'GET', 'api_plan_get', $use);
        $r('/images', 'POST', 'api_image_create', $use);
        $r('/images/upload', 'POST', 'api_image_upload', $use);
        $r('/clients/(?P<id>\d+)/plan-import', 'POST', 'api_plan_import', $use);
        $r('/images/(?P<id>\d+)/poll', 'GET', 'api_image_poll', $use);
        $r('/settings', 'GET', 'api_settings_get', $adm);
        $r('/settings', 'POST', 'api_settings_save', $adm);
        $r('/usage', 'GET', 'api_usage', $adm);
    }

    private static function used_this_month(): int {
        global $wpdb;
        return max(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(units),0) FROM ' . self::t('usage') . " WHERE action IN ('image','refund') AND created_at >= %s", wp_date('Y-m-01 00:00:00'))));
    }

    private static function log_use(string $action, int $units, string $note = ''): void {
        global $wpdb; $wpdb->insert(self::t('usage'), ['user_id' => get_current_user_id(), 'action' => $action, 'units' => $units, 'note' => mb_substr($note, 0, 250), 'created_at' => current_time('mysql')]);
    }

    public static function api_state() {
        global $wpdb; $cfg = self::cfg();
        $clients = $wpdb->get_results('SELECT c.id, c.name, (SELECT COUNT(*) FROM ' . self::t('plans') . ' p WHERE p.client_id=c.id) plans, (SELECT COUNT(*) FROM ' . self::t('images') . " i WHERE i.client_id=c.id AND i.status='done') images FROM " . self::t('clients') . ' c WHERE c.archived=0 ORDER BY c.name ASC', ARRAY_A) ?: [];
        return rest_ensure_response(['clients' => $clients, 'used' => self::used_this_month(), 'cap' => (int) $cfg['cap'], 'mock' => (bool) $cfg['mock'], 'ai' => ($cfg['mock'] || $cfg['claude_key'] !== ''), 'img' => ($cfg['mock'] || $cfg['magnific_key'] !== '')]);
    }

    public static function api_client_create(WP_REST_Request $r) {
        global $wpdb; $name = mb_substr(trim(sanitize_text_field((string) $r['name'])), 0, 190);
        if ($name === '') return new WP_Error('name', 'Enter the client name.', ['status' => 400]);
        $wpdb->insert(self::t('clients'), ['name' => $name, 'brand' => wp_json_encode(new stdClass()), 'created_at' => current_time('mysql')]);
        return rest_ensure_response(['id' => (int) $wpdb->insert_id]);
    }

    private static function client_row(int $id): ?array {
        global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('clients') . ' WHERE id=%d AND archived=0', $id), ARRAY_A);
        return $row ?: null;
    }

    private static function image_out(array $i): array {
        $url = $i['attachment_id'] ? (string) wp_get_attachment_url((int) $i['attachment_id']) : '';
        return ['id' => (int) $i['id'], 'client_id' => (int) $i['client_id'], 'plan_id' => (int) $i['plan_id'], 'post_n' => (int) $i['post_n'], 'prompt' => (string) $i['prompt'], 'aspect' => $i['aspect'], 'status' => $i['status'], 'error' => $i['error'], 'url' => $url, 'at' => $i['created_at']];
    }

    public static function api_client_get(WP_REST_Request $r) {
        global $wpdb; $id = (int) $r['id']; $c = self::client_row($id);
        if (!$c) return new WP_Error('nf', 'Client not found.', ['status' => 404]);
        $plans = $wpdb->get_results($wpdb->prepare('SELECT id, month, brief, created_at FROM ' . self::t('plans') . ' WHERE client_id=%d ORDER BY month DESC, id DESC', $id), ARRAY_A) ?: [];
        $imgs = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::t('images') . ' WHERE client_id=%d ORDER BY id DESC LIMIT 300', $id), ARRAY_A) ?: [];
        return rest_ensure_response(['id' => $id, 'name' => $c['name'], 'brand' => json_decode((string) $c['brand'], true) ?: new stdClass(), 'plans' => $plans, 'images' => array_map([self::class, 'image_out'], $imgs)]);
    }

    public static function api_brand_save(WP_REST_Request $r) {
        global $wpdb; $id = (int) $r['id']; if (!self::client_row($id)) return new WP_Error('nf', 'Client not found.', ['status' => 404]);
        $b = [];
        foreach (['name', 'industry', 'audience', 'tone', 'language', 'colors', 'image_style', 'avoid', 'notes'] as $k) $b[$k] = mb_substr(sanitize_textarea_field((string) $r[$k]), 0, 2000);
        $upd = ['brand' => wp_json_encode($b)];
        $nm = mb_substr(trim(sanitize_text_field((string) $r['client_name'])), 0, 190); if ($nm !== '') $upd['name'] = $nm;
        $wpdb->update(self::t('clients'), $upd, ['id' => $id]);
        return rest_ensure_response(['ok' => true]);
    }

    /* ---------- plan generation ---------- */

    private static function mock_plan(array $c, string $month, int $count): array {
        $posts = []; $fmts = ['Carousel', 'Single', 'Reel']; $day = 1;
        for ($i = 1; $i <= $count; $i++) {
            $posts[] = ['n' => $i, 'date' => $month . '-' . str_pad((string) min(28, $day), 2, '0', STR_PAD_LEFT), 'format' => $fmts[($i - 1) % 3], 'pillar' => 'Brand', 'topic' => 'Sample topic ' . $i, 'headline' => 'Sample headline ' . $i,
                'caption' => '[TEST MODE] Sample caption for ' . $c['name'] . '. Connect the Claude key in Settings to get real content.', 'hashtags' => '#sample #test', 'image_prompt' => 'A clean branded social post for ' . $c['name'] . ', sample ' . $i . ', premium look, empty space for text.'];
            $day += 3;
        }
        return ['posts' => $posts, 'mock' => true];
    }

    public static function api_plan_generate(WP_REST_Request $r) {
        global $wpdb; $id = (int) $r['id']; $c = self::client_row($id); $cfg = self::cfg();
        if (!$c) return new WP_Error('nf', 'Client not found.', ['status' => 404]);
        $month = (string) $r['month']; if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) return new WP_Error('month', 'Pick a month.', ['status' => 400]);
        $count = max(1, min(14, (int) ($r['count'] ?: 8)));
        $brief = mb_substr(trim(sanitize_textarea_field((string) $r['brief'])), 0, 3000);
        $brand = json_decode((string) $c['brand'], true) ?: [];
        if (!$cfg['mock'] && !$cfg['claude_key']) return new WP_Error('nokey', 'No Claude key is set. Use the free workflow instead.', ['status' => 400]);
        if ($cfg['mock']) { $data = self::mock_plan(['name' => $c['name']] + $brand, $month, $count); }
        else {
            @set_time_limit(180);
            $sys = "You are a senior social media content strategist at Vibes Agency. Create a monthly Instagram content plan for ONE client. Reply with JSON only, no prose and no code fences, in this exact shape: {\"posts\":[{\"n\":1,\"date\":\"YYYY-MM-DD\",\"format\":\"Carousel|Single|Reel\",\"pillar\":\"\",\"topic\":\"\",\"headline\":\"\",\"caption\":\"\",\"hashtags\":\"\",\"image_prompt\":\"\"}]}. Write captions in the requested language and tone. image_prompt is an English prompt for an AI image generator for the cover image; follow the client's image style and never include readable Arabic text inside the image.";
            $user = "Client: {$c['name']}\nMonth: {$month}\nNumber of posts: {$count}\nBrand profile: " . wp_json_encode($brand, JSON_UNESCAPED_UNICODE) . "\nBrief from the employee: " . ($brief ?: 'none');
            $res = wp_remote_post('https://api.anthropic.com/v1/messages', ['timeout' => 170, 'headers' => ['x-api-key' => $cfg['claude_key'], 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'], 'body' => wp_json_encode(['model' => $cfg['claude_model'], 'max_tokens' => 8000, 'system' => $sys, 'messages' => [['role' => 'user', 'content' => $user]]])]);
            if (is_wp_error($res)) return new WP_Error('claude', 'Could not reach Claude: ' . $res->get_error_message(), ['status' => 502]);
            $code = (int) wp_remote_retrieve_response_code($res); $body = json_decode((string) wp_remote_retrieve_body($res), true);
            if ($code !== 200) return new WP_Error('claude', 'Claude error ' . $code . ': ' . ($body['error']['message'] ?? 'unknown'), ['status' => 502]);
            $text = (string) ($body['content'][0]['text'] ?? '');
            $s = strpos($text, '{'); $e = strrpos($text, '}');
            $data = ($s !== false && $e !== false) ? json_decode(substr($text, $s, $e - $s + 1), true) : null;
            if (!is_array($data) || empty($data['posts'])) return new WP_Error('claude', 'Claude returned an unreadable plan. Please try again.', ['status' => 502]);
            self::log_use('plan', 0, $c['name'] . ' ' . $month);
        }
        $wpdb->insert(self::t('plans'), ['client_id' => $id, 'month' => $month, 'brief' => $brief, 'data' => wp_json_encode($data, JSON_UNESCAPED_UNICODE), 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql')]);
        return rest_ensure_response(['id' => (int) $wpdb->insert_id]);
    }

    public static function api_plan_get(WP_REST_Request $r) {
        global $wpdb; $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('plans') . ' WHERE id=%d', (int) $r['id']), ARRAY_A);
        if (!$p) return new WP_Error('nf', 'Plan not found.', ['status' => 404]);
        return rest_ensure_response(['id' => (int) $p['id'], 'client_id' => (int) $p['client_id'], 'month' => $p['month'], 'brief' => $p['brief'], 'data' => json_decode((string) $p['data'], true)]);
    }

    /* ---------- images ---------- */

    private static function attach(string $tmp, string $name, string $title) {
        require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
        return media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0, $title);
    }

    private static function mock_png(string $prompt, string $aspect, int $id): string {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!function_exists('imagecreatetruecolor')) return '';
        [$w, $h] = self::ASPECTS[$aspect] ?? [1080, 1350]; $w = (int) ($w / 3); $h = (int) ($h / 3);
        $im = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) { $t = $y / $h; $col = imagecolorallocate($im, (int) (20 + 60 * $t), (int) (10 + 20 * $t), (int) (60 + 120 * $t)); imageline($im, 0, $y, $w, $y, $col); }
        $white = imagecolorallocate($im, 255, 255, 255);
        imagestring($im, 5, 12, 12, 'VIBES STUDIO - TEST IMAGE #' . $id, $white);
        $words = explode("\n", wordwrap(preg_replace('/[^\x20-\x7E]/', '', $prompt), (int) ($w / 8), "\n", true)); $y = 44;
        foreach (array_slice($words, 0, 12) as $line) { imagestring($im, 3, 12, $y, $line, $white); $y += 16; }
        $tmp = wp_tempnam('vcs-mock.png'); imagepng($im, $tmp); imagedestroy($im);
        return $tmp;
    }

    public static function api_image_create(WP_REST_Request $r) {
        global $wpdb; $cfg = self::cfg();
        $cid = (int) $r['client_id']; $c = self::client_row($cid); if (!$c) return new WP_Error('nf', 'Client not found.', ['status' => 404]);
        $prompt = mb_substr(trim(sanitize_textarea_field((string) $r['prompt'])), 0, 3500); if ($prompt === '') return new WP_Error('prompt', 'Write an image prompt first.', ['status' => 400]);
        $aspect = isset(self::ASPECTS[(string) $r['aspect']]) ? (string) $r['aspect'] : 'social_post_4_5';
        if (!$cfg['mock'] && !$cfg['magnific_key']) return new WP_Error('nokey', 'No Magnific key is set. Upload the image instead.', ['status' => 400]);
        if (self::used_this_month() >= (int) $cfg['cap']) return new WP_Error('cap', 'The monthly image limit (' . (int) $cfg['cap'] . ') has been reached. An admin can raise it in Settings.', ['status' => 429]);
        $brand = json_decode((string) $c['brand'], true) ?: [];
        $full = $prompt . (!empty($brand['image_style']) ? ' Style: ' . $brand['image_style'] : '') . (!empty($brand['avoid']) ? ' Avoid: ' . $brand['avoid'] : '');
        $wpdb->insert(self::t('images'), ['client_id' => $cid, 'plan_id' => (int) $r['plan_id'], 'post_n' => (int) $r['post_n'], 'prompt' => $prompt, 'aspect' => $aspect, 'status' => 'queued', 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql')]);
        $iid = (int) $wpdb->insert_id; self::log_use('image', 1, $c['name'] . ' #' . $iid);
        if ($cfg['mock'] || !$cfg['magnific_key']) {
            $tmp = self::mock_png($prompt, $aspect, $iid);
            if (!$tmp) { self::fail($iid, 'Test image tool unavailable on this server.'); }
            else { $aid = self::attach($tmp, 'vcs-test-' . $iid . '.png', $c['name'] . ' test image'); if (is_wp_error($aid)) self::fail($iid, $aid->get_error_message()); else $wpdb->update(self::t('images'), ['status' => 'done', 'attachment_id' => (int) $aid], ['id' => $iid]); }
        } else {
            $res = wp_remote_post('https://api.magnific.com/v1/ai/text-to-image/' . rawurlencode($cfg['img_model']), ['timeout' => 40, 'headers' => ['x-magnific-api-key' => $cfg['magnific_key'], 'Content-Type' => 'application/json'], 'body' => wp_json_encode(['prompt' => $full, 'aspect_ratio' => $aspect])]);
            $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res); $b = is_wp_error($res) ? [] : (json_decode((string) wp_remote_retrieve_body($res), true) ?: []);
            if ($code >= 200 && $code < 300 && !empty($b['data']['task_id'])) $wpdb->update(self::t('images'), ['task_id' => (string) $b['data']['task_id'], 'status' => 'generating'], ['id' => $iid]);
            else self::fail($iid, is_wp_error($res) ? $res->get_error_message() : 'Magnific error ' . $code);
        }
        return rest_ensure_response(self::image_out($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('images') . ' WHERE id=%d', $iid), ARRAY_A)));
    }

    private static function fail(int $iid, string $msg): void {
        global $wpdb; $wpdb->update(self::t('images'), ['status' => 'failed', 'error' => mb_substr($msg, 0, 250)], ['id' => $iid]);
        self::log_use('refund', -1, 'failed #' . $iid);
    }

    public static function api_image_poll(WP_REST_Request $r) {
        global $wpdb; $cfg = self::cfg(); $iid = (int) $r['id'];
        $i = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('images') . ' WHERE id=%d', $iid), ARRAY_A);
        if (!$i) return new WP_Error('nf', 'Image not found.', ['status' => 404]);
        if ($i['status'] === 'generating' && $i['task_id']) {
            $res = wp_remote_get('https://api.magnific.com/v1/ai/text-to-image/' . rawurlencode($cfg['img_model']) . '/' . rawurlencode($i['task_id']), ['timeout' => 30, 'headers' => ['x-magnific-api-key' => $cfg['magnific_key']]]);
            $b = is_wp_error($res) ? [] : (json_decode((string) wp_remote_retrieve_body($res), true) ?: []);
            $st = (string) ($b['data']['status'] ?? '');
            if ($st === 'COMPLETED' && !empty($b['data']['generated'][0])) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                $url = (string) $b['data']['generated'][0]; $tmp = download_url($url, 90);
                if (is_wp_error($tmp)) self::fail($iid, 'Could not download the finished image.');
                else {
                    $ext = strtolower(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)); if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) $ext = 'png';
                    $aid = self::attach($tmp, 'vcs-' . $iid . '.' . $ext, 'Studio image ' . $iid);
                    if (is_wp_error($aid)) self::fail($iid, $aid->get_error_message()); else $wpdb->update(self::t('images'), ['status' => 'done', 'attachment_id' => (int) $aid], ['id' => $iid]);
                }
            } elseif ($st === 'FAILED') self::fail($iid, 'Magnific could not generate this image.');
            $i = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('images') . ' WHERE id=%d', $iid), ARRAY_A);
        }
        return rest_ensure_response(self::image_out($i));
    }

    public static function api_plan_import(WP_REST_Request $r) {
        global $wpdb; $id = (int) $r['id']; $c = self::client_row($id);
        if (!$c) return new WP_Error('nf', 'Client not found.', ['status' => 404]);
        $month = (string) $r['month']; if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) return new WP_Error('month', 'Pick a month.', ['status' => 400]);
        $raw = (string) $r['json']; if (strlen($raw) > 300000) return new WP_Error('big', 'That reply is too long.', ['status' => 400]);
        $raw = trim(preg_replace('/^```(?:json)?|```$/m', '', $raw));
        $data = null; $s = strpos($raw, '{'); $e = strrpos($raw, '}');
        if ($s !== false && $e !== false) $data = json_decode(substr($raw, $s, $e - $s + 1), true);
        if (!is_array($data) || empty($data['posts'])) { $s = strpos($raw, '['); $e = strrpos($raw, ']'); $list = ($s !== false && $e !== false) ? json_decode(substr($raw, $s, $e - $s + 1), true) : null; if (is_array($list) && $list) $data = ['posts' => $list]; }
        if (!is_array($data) || empty($data['posts']) || !is_array($data['posts'])) return new WP_Error('json', 'I could not read that. Paste Claude\'s whole reply, starting with {"posts": ...}.', ['status' => 400]);
        $posts = []; $n = 0;
        foreach (array_slice($data['posts'], 0, 40) as $p) {
            if (!is_array($p)) continue; $n++;
            $row = ['n' => $n];
            foreach (['date', 'format', 'pillar', 'topic', 'headline', 'caption', 'hashtags', 'image_prompt'] as $k) $row[$k] = mb_substr(sanitize_textarea_field(is_scalar($p[$k] ?? '') ? (string) ($p[$k] ?? '') : wp_json_encode($p[$k])), 0, 4000);
            $posts[] = $row;
        }
        if (!$posts) return new WP_Error('json', 'No posts found in that reply.', ['status' => 400]);
        $brief = mb_substr(trim(sanitize_textarea_field((string) $r['brief'])), 0, 3000);
        $wpdb->insert(self::t('plans'), ['client_id' => $id, 'month' => $month, 'brief' => $brief, 'data' => wp_json_encode(['posts' => $posts, 'imported' => true], JSON_UNESCAPED_UNICODE), 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql')]);
        return rest_ensure_response(['id' => (int) $wpdb->insert_id, 'posts' => count($posts)]);
    }

    public static function api_image_upload(WP_REST_Request $r) {
        global $wpdb; $cid = (int) $r['client_id']; $c = self::client_row($cid);
        if (!$c) return new WP_Error('nf', 'Client not found.', ['status' => 404]);
        $f = $r->get_file_params()['file'] ?? null;
        if (!$f || !empty($f['error']) || empty($f['tmp_name'])) return new WP_Error('file', 'Choose an image to upload (max 8 MB).', ['status' => 400]);
        if ((int) $f['size'] > 8 * 1024 * 1024) return new WP_Error('file', 'That image is larger than 8 MB.', ['status' => 400]);
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) return new WP_Error('file', 'Only PNG, JPG, WebP or GIF images can be uploaded.', ['status' => 400]);
        $aid = self::attach($f['tmp_name'], sanitize_file_name((string) $f['name']), $c['name'] . ' upload');
        if (is_wp_error($aid)) return new WP_Error('file', $aid->get_error_message(), ['status' => 400]);
        $wpdb->insert(self::t('images'), ['client_id' => $cid, 'plan_id' => (int) $r['plan_id'], 'post_n' => (int) $r['post_n'], 'prompt' => mb_substr(trim(sanitize_textarea_field((string) $r['prompt'])), 0, 3500), 'aspect' => 'upload', 'status' => 'done', 'attachment_id' => (int) $aid, 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql')]);
        $iid = (int) $wpdb->insert_id; self::log_use('upload', 0, $c['name'] . ' #' . $iid);
        return rest_ensure_response(self::image_out($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('images') . ' WHERE id=%d', $iid), ARRAY_A)));
    }

    /* ---------- admin ---------- */

    public static function api_settings_get() {
        $c = self::cfg();
        return rest_ensure_response(['claude_key_set' => $c['claude_key'] !== '', 'magnific_key_set' => $c['magnific_key'] !== '', 'claude_model' => $c['claude_model'], 'img_model' => $c['img_model'], 'cap' => (int) $c['cap'], 'mock' => (bool) $c['mock']]);
    }

    public static function api_settings_save(WP_REST_Request $r) {
        $c = self::cfg();
        foreach (['claude_key', 'magnific_key'] as $k) { $v = trim((string) $r[$k]); if ($v !== '') $c[$k] = sanitize_text_field($v); }
        if (trim((string) $r['claude_model']) !== '') $c['claude_model'] = sanitize_text_field((string) $r['claude_model']);
        if (preg_match('/^[a-z0-9._-]+$/', (string) $r['img_model'])) $c['img_model'] = (string) $r['img_model'];
        $c['cap'] = max(0, min(100000, (int) $r['cap']));
        $c['mock'] = $r['mock'] ? 1 : 0;
        update_option('vcs_cfg', $c, false);
        return rest_ensure_response(['ok' => true]);
    }

    public static function api_usage() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT u.action, u.units, u.note, u.created_at, COALESCE(w.display_name, \'—\') who FROM ' . self::t('usage') . ' u LEFT JOIN ' . $wpdb->users . ' w ON w.ID=u.user_id ORDER BY u.id DESC LIMIT 100', ARRAY_A) ?: [];
        return rest_ensure_response(['rows' => $rows, 'used' => self::used_this_month(), 'cap' => (int) self::cfg()['cap']]);
    }
}
VCS::boot();

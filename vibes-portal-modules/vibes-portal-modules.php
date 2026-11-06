<?php
/**
 * Plugin Name: Vibes Portal Modules
 * Description: Integrated Vibes Connect, Analytics, Finance, CRM and Lead Hunter modules for the existing Team Portal.
 * Version: 0.4.0
 * Author: Vibes Agency
 * Text Domain: vibes-portal-modules
 */

defined('ABSPATH') || exit;

define('VPM_VERSION', '0.4.0');
define('VPM_FILE', __FILE__);
define('VPM_DIR', plugin_dir_path(__FILE__));
define('VPM_URL', plugin_dir_url(__FILE__));

require_once VPM_DIR . 'includes/class-vpm-activator.php';
require_once VPM_DIR . 'includes/class-vpm-permissions.php';
require_once VPM_DIR . 'includes/class-vpm-rest.php';
require_once VPM_DIR . 'includes/class-vpm-cron.php';
require_once VPM_DIR . 'includes/class-vpm-ui.php';

register_activation_hook(__FILE__, ['VPM_Activator', 'activate']);
register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook('vpm_daily_lead_scan');
});

add_action('plugins_loaded', static function () {
    VPM_Activator::maybe_upgrade();
    VPM_REST::boot();
    VPM_Cron::boot();
    VPM_UI::boot();
});


/* ------------------------------------------------------------------------
 * Vibes Client Portal: private-link review page with inline deliverables,
 * per-item approval, admin-only comments and an automatic Drive hand-off.
 * ---------------------------------------------------------------------- */
final class VPM_ClientPortal {
    const NS = 'vibes/v1';

    public static function boot(): void {
        add_action('init', [self::class, 'maybe_install_tables'], 5);
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('template_redirect', [self::class, 'page'], 0);
        add_action('vpm_client_finalize', [self::class, 'finalize'], 10, 1);
        add_filter('rest_post_dispatch', [self::class, 'no_cache'], 10, 3);
    }

    private static function t(string $name): string { global $wpdb; return $wpdb->prefix . 'vtp_' . $name; }

    public static function maybe_install_tables(): void {
        if (get_option('vcr_db_version') === '2') return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS " . self::t('client_items') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint unsigned NOT NULL,
            task_id bigint unsigned NOT NULL,
            state varchar(20) NOT NULL DEFAULT 'pending',
            approved_by varchar(190) DEFAULT '',
            approved_at datetime DEFAULT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY project_task (project_id,task_id)
        ) {$charset};");
        $wpdb->query("CREATE TABLE IF NOT EXISTS " . self::t('client_comments') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint unsigned NOT NULL,
            task_id bigint unsigned NOT NULL,
            author varchar(190) DEFAULT '',
            message longtext,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), KEY project_id (project_id), KEY task_id (task_id)
        ) {$charset};");
        if (!$wpdb->get_results("SHOW INDEX FROM " . self::t('client_items') . " WHERE Key_name='project_task'")) $wpdb->query("ALTER TABLE " . self::t('client_items') . " ADD UNIQUE KEY project_task (project_id,task_id)");
        if ($wpdb->get_var("SHOW TABLES LIKE '" . esc_sql(self::t('client_items')) . "'") && $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql(self::t('client_comments')) . "'")) update_option('vcr_db_version', '2', false);
    }

    public static function no_cache($result, $server, $request) {
        if (strpos((string) $request->get_route(), '/vibes/v1/public/projects/') === 0 || strpos((string) $request->get_route(), '/vibes/v1/projects/') === 0) {
            $server->send_header('Cache-Control', 'no-store, private');
            $server->send_header('X-LiteSpeed-Cache-Control', 'no-cache');
        }
        return $result;
    }

    public static function routes(): void {
        $tok = '(?P<token>[a-f0-9]{64})';
        $pub = '__return_true';
        register_rest_route(self::NS, "/public/projects/{$tok}/portal", ['methods' => 'GET', 'callback' => [self::class, 'api_portal'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, "/public/projects/{$tok}/file/(?P<fid>[A-Za-z0-9_-]{10,80})", ['methods' => 'GET', 'callback' => [self::class, 'api_file'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, "/public/projects/{$tok}/items/(?P<task>\\d+)/approve", ['methods' => 'POST', 'callback' => [self::class, 'api_approve'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, "/public/projects/{$tok}/items/(?P<task>\\d+)/undo", ['methods' => 'POST', 'callback' => [self::class, 'api_undo'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, "/public/projects/{$tok}/items/(?P<task>\\d+)/comment", ['methods' => 'POST', 'callback' => [self::class, 'api_comment'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, "/public/projects/{$tok}/items/(?P<task>\\d+)/changes", ['methods' => 'POST', 'callback' => [self::class, 'api_changes'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, '/client-reviews', ['methods' => 'GET', 'callback' => [self::class, 'api_reviews'], 'permission_callback' => static function () { return current_user_can('vtp_manage_projects') || current_user_can('manage_options'); }]);
        register_rest_route(self::NS, '/projects/(?P<id>\\d+)/client-feedback', ['methods' => 'GET', 'callback' => [self::class, 'api_feedback'], 'permission_callback' => static function () { return current_user_can('vtp_manage_projects') || current_user_can('manage_options'); }]);
    }

    /* ---------- data helpers ---------- */

    private static function project_by_token(string $token): ?array {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $row = $wpdb->get_row($wpdb->prepare('SELECT p.*, q.email AS client_email, q.client_name AS quote_client, q.quote_number FROM ' . self::t('projects') . ' p LEFT JOIN ' . self::t('quotations') . ' q ON q.id=p.quotation_id WHERE p.client_review_token=%s', $token), ARRAY_A);
        return $row ?: null;
    }

    private static function state(int $pid): array {
        $s = get_option('vcr_state_' . $pid, []);
        return is_array($s) ? $s : [];
    }

    private static function cycle_tasks(array $project): array {
        global $wpdb;
        $month = (string) ($project['client_review_month'] ?? '');
        $where = $month ? $wpdb->prepare('project_id=%d AND delivery_month=%s', (int) $project['id'], $month) : $wpdb->prepare('project_id=%d', (int) $project['id']);
        return $wpdb->get_results('SELECT id, task_number, service_label, title, submission_note, status, files_json FROM ' . self::t('tasks') . " WHERE {$where} ORDER BY id ASC", ARRAY_A) ?: [];
    }

    private static function item_rows(int $pid): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::t('client_items') . ' WHERE project_id=%d', $pid), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) $out[(int) $r['task_id']] = $r;
        return $out;
    }

    private static function folder_for_task(int $pid, array $task): string {
        if (!function_exists('vibes_drive_map')) return '';
        $project = (vibes_drive_map())[(string) $pid] ?? (vibes_drive_map())[$pid] ?? [];
        foreach (($project['submissions'] ?? []) as $s) {
            if ((string) ($s['task_id'] ?? '') === (string) $task['id']) {
                $f = $s['current_folder_id'] ?? '';
                if (!$f) $f = $s['admin_review_folder_id'] ?? '';
                if ($f) return (string) $f;
            }
        }
        $files = json_decode($task['files_json'] ?: '[]', true) ?: [];
        foreach ($files as $f) {
            if (!empty($f['url']) && function_exists('vibes_drive_id_from_url')) { $id = vibes_drive_id_from_url($f['url']); if ($id) return $id; }
        }
        return '';
    }

    private static function kind(string $mime): string {
        if (strpos($mime, 'image/') === 0 && $mime !== 'image/svg+xml') return 'image';
        if (strpos($mime, 'video/') === 0) return 'video';
        if (strpos($mime, 'audio/') === 0) return 'audio';
        if ($mime === 'application/pdf' || strpos($mime, 'application/vnd.google-apps.') === 0) return 'pdf';
        return 'file';
    }

    /** Files inside a task folder (two levels deep), cached briefly. */
    private static function list_files(string $folder_id): array {
        if (!$folder_id || !function_exists('vibes_drive_api')) return [];
        $key = 'vcr_ls_' . md5($folder_id);
        $cached = get_transient($key);
        if (is_array($cached)) return $cached;
        $files = [];
        $walk = static function (string $id, string $prefix, int $depth) use (&$walk, &$files) {
            $res = vibes_drive_api('GET', 'files', null, ['q' => sprintf("'%s' in parents and trashed = false", esc_sql($id)), 'pageSize' => 100, 'orderBy' => 'name', 'fields' => 'files(id,name,mimeType,size)']);
            if (is_wp_error($res)) return;
            foreach (($res['files'] ?? []) as $f) {
                if (count($files) >= 80) return;
                if (($f['mimeType'] ?? '') === 'application/vnd.google-apps.folder') { if ($depth < 2) $walk($f['id'], $prefix . $f['name'] . ' / ', $depth + 1); continue; }
                $mime = (string) ($f['mimeType'] ?? 'application/octet-stream');
                $files[] = ['id' => $f['id'], 'name' => $prefix . ($f['name'] ?? 'File'), 'mime' => $mime, 'kind' => self::kind($mime), 'size' => (int) ($f['size'] ?? 0)];
            }
        };
        $walk($folder_id, '', 1);
        set_transient($key, $files, 900);
        return $files;
    }

    private static function build(array $project): array {
        $pid = (int) $project['id'];
        $tasks = self::cycle_tasks($project);
        $rows = self::item_rows($pid);
        $items = []; $waiting = 0;
        foreach ($tasks as $t) {
            if ($t['status'] !== 'done') { $waiting++; continue; }
            $row = $rows[(int) $t['id']] ?? null;
            $items[] = [
                'id' => (int) $t['id'], 'number' => $t['task_number'], 'label' => $t['service_label'], 'title' => $t['title'], 'note' => (string) $t['submission_note'],
                'state' => $row ? $row['state'] : 'pending', 'approved_by' => $row['approved_by'] ?? '', 'approved_at' => $row['approved_at'] ?? null,
                'files' => self::list_files(self::folder_for_task($pid, $t)),
            ];
        }
        $approved = count(array_filter($items, static fn($i) => $i['state'] === 'approved'));
        $all = $items && $approved === count($items) && !$waiting;
        $st = self::state($pid);
        if (($st['status'] ?? '') === 'complete' && !$all) { delete_option('vcr_state_' . $pid); $st = []; }
        $idx = []; foreach ($items as $it) foreach ($it['files'] as $fl) $idx[$fl['id']] = $fl;
        set_transient('vcr_idx_' . $pid, $idx, 900);
        return ['items' => $items, 'waiting' => $waiting, 'approved' => $approved, 'all' => $all, 'state' => $st];
    }

    /* ---------- public API ---------- */

    public static function api_portal(WP_REST_Request $r) {
        $project = self::project_by_token((string) $r['token']);
        if (!$project) return new WP_Error('not_found', 'This link is invalid or has expired.', ['status' => 404]);
        $b = self::build($project);
        $st = $b['state'];
        if (($st['status'] ?? '') === 'finalizing' && time() - (int) ($st['at'] ?? 0) > 120 && !wp_next_scheduled('vpm_client_finalize', [(int) $project['id']])) wp_schedule_single_event(time() + 5, 'vpm_client_finalize', [(int) $project['id']]);
        return rest_ensure_response([
            'project' => ['name' => $project['name'], 'client' => $project['client'] ?: $project['quote_client']],
            'status' => $st['status'] ?? 'open', 'download_url' => ($st['status'] ?? '') === 'complete' ? ($st['url'] ?? '') : '',
            'items' => $b['items'], 'waiting' => $b['waiting'], 'approved' => $b['approved'],
        ]);
    }

    private static function guard_item(WP_REST_Request $r, bool $needs_open = true) {
        $project = self::project_by_token((string) $r['token']);
        if (!$project) return new WP_Error('not_found', 'This link is invalid or has expired.', ['status' => 404]);
        $task_id = (int) $r['task'];
        $b = self::build($project);
        $item = null; foreach ($b['items'] as $i) if ($i['id'] === $task_id) $item = $i;
        if (!$item) return new WP_Error('not_found', 'This item is not available for review.', ['status' => 404]);
        if ($needs_open && in_array($b['state']['status'] ?? '', ['finalizing', 'complete'], true)) return new WP_Error('closed', 'This delivery has already been approved.', ['status' => 409]);
        return [$project, $b, $item];
    }

    private static function name_of(WP_REST_Request $r): string {
        return mb_substr(trim(sanitize_text_field((string) $r['name'])), 0, 120);
    }

    public static function api_approve(WP_REST_Request $r) {
        $g = self::guard_item($r); if (is_wp_error($g)) return $g;
        [$project, , $item] = $g;
        $name = self::name_of($r);
        if (!$name) return new WP_Error('missing_name', 'Please enter your name first.', ['status' => 400]);
        global $wpdb; $now = current_time('mysql'); $pid = (int) $project['id'];
        $wpdb->query($wpdb->prepare('INSERT INTO ' . self::t('client_items') . " (project_id,task_id,state,approved_by,approved_at,updated_at) VALUES (%d,%d,'approved',%s,%s,%s) ON DUPLICATE KEY UPDATE state='approved', approved_by=VALUES(approved_by), approved_at=VALUES(approved_at), updated_at=VALUES(updated_at)", $pid, $item['id'], $name, $now, $now));
        $b = self::build($project);
        $payload = ['success' => true, 'approved' => $b['approved'], 'total' => count($b['items']), 'all_approved' => $b['all']];
        if (!$b['all']) return rest_ensure_response($payload);
        // Everything is approved: hand off to the finalizer after the response is sent.
        update_option('vcr_state_' . $pid, ['status' => 'finalizing', 'name' => $name, 'at' => time()], false);
        $payload['status'] = 'finalizing';
        if (!wp_next_scheduled('vpm_client_finalize', [$pid])) wp_schedule_single_event(time() + 90, 'vpm_client_finalize', [$pid]);
        while (ob_get_level()) @ob_end_clean();
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        $json = wp_json_encode($payload); header('Content-Length: ' . strlen($json)); echo $json;
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) litespeed_finish_request(); else flush();
        self::finalize($pid);
        exit;
    }

    public static function api_undo(WP_REST_Request $r) {
        $g = self::guard_item($r); if (is_wp_error($g)) return $g;
        [$project, , $item] = $g;
        global $wpdb;
        $wpdb->update(self::t('client_items'), ['state' => 'pending', 'approved_by' => '', 'approved_at' => null, 'updated_at' => current_time('mysql')], ['project_id' => (int) $project['id'], 'task_id' => $item['id']]);
        $b = self::build($project);
        return rest_ensure_response(['success' => true, 'approved' => $b['approved'], 'total' => count($b['items'])]);
    }

    public static function api_comment(WP_REST_Request $r) {
        $g = self::guard_item($r); if (is_wp_error($g)) return $g;
        [$project, , $item] = $g;
        $name = self::name_of($r);
        $message = trim(sanitize_textarea_field((string) $r['message']));
        if (!$name) return new WP_Error('missing_name', 'Please enter your name first.', ['status' => 400]);
        if (!$message) return new WP_Error('missing_message', 'Write your comment first.', ['status' => 400]);
        $message = mb_substr($message, 0, 3000);
        $rl = 'vcr_rl_' . md5((string) $r['token']);
        $n = (int) get_transient($rl); if ($n >= 30) return new WP_Error('slow_down', 'Too many comments. Please try again later.', ['status' => 429]);
        set_transient($rl, $n + 1, HOUR_IN_SECONDS);
        global $wpdb; $now = current_time('mysql'); $pid = (int) $project['id'];
        $wpdb->insert(self::t('client_comments'), ['project_id' => $pid, 'task_id' => $item['id'], 'author' => $name, 'message' => $message, 'created_at' => $now]);
        $wpdb->query($wpdb->prepare('INSERT INTO ' . self::t('client_items') . " (project_id,task_id,state,updated_at) VALUES (%d,%d,'commented',%s) ON DUPLICATE KEY UPDATE state=IF(state='approved','approved','commented'), updated_at=VALUES(updated_at)", $pid, $item['id'], $now));
        self::notify($project['name'] . ' · ' . $item['title'], 'client_comment', 'Client comment · ' . $project['name'], $name . ': ' . mb_substr($message, 0, 240), $pid);
        wp_mail(['info@vibesagency.co', 'info@solaraevents.net'], 'Client comment — ' . $project['name'], "Client: {$name}\nProject: {$project['name']}\nItem: {$item['title']}\n\n{$message}\n\nOpen the Vibes Team Portal to review it.", ['From: Vibes Agency <admin@vibesagency.co>']);
        return rest_ensure_response(['success' => true]);
    }

    /** Client asks for changes on one item: send it back to the team, move its Drive folder back to Working. */
    public static function api_changes(WP_REST_Request $r) {
        $g = self::guard_item($r); if (is_wp_error($g)) return $g;
        [$project, , $item] = $g;
        $name = self::name_of($r);
        $message = trim(sanitize_textarea_field((string) $r['message']));
        if (!$name) return new WP_Error('missing_name', 'Please enter your name first.', ['status' => 400]);
        if (!$message) return new WP_Error('missing_message', 'Please describe the changes you would like.', ['status' => 400]);
        $message = mb_substr($message, 0, 3000);
        $rl = 'vcr_rl_' . md5((string) $r['token']);
        $n = (int) get_transient($rl); if ($n >= 30) return new WP_Error('slow_down', 'Too many requests. Please try again later.', ['status' => 429]);
        set_transient($rl, $n + 1, HOUR_IN_SECONDS);
        global $wpdb; $now = current_time('mysql'); $pid = (int) $project['id']; $tid = (int) $item['id'];
        $wpdb->insert(self::t('client_comments'), ['project_id' => $pid, 'task_id' => $tid, 'author' => $name, 'message' => 'CHANGES REQUESTED: ' . $message, 'created_at' => $now]);
        $wpdb->query($wpdb->prepare('INSERT INTO ' . self::t('client_items') . " (project_id,task_id,state,approved_by,approved_at,updated_at) VALUES (%d,%d,'pending','',NULL,%s) ON DUPLICATE KEY UPDATE state='pending', approved_by='', approved_at=NULL, updated_at=VALUES(updated_at)", $pid, $tid, $now));
        $task = $wpdb->get_row($wpdb->prepare('SELECT assignee_id FROM ' . self::t('tasks') . ' WHERE id=%d', $tid), ARRAY_A);
        $wpdb->update(self::t('tasks'), ['status' => 'in_progress', 'completed_at' => null, 'updated_at' => $now], ['id' => $tid]);
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t('tasks') . ' WHERE project_id=%d', $pid));
        $sub = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::t('tasks') . " WHERE project_id=%d AND status IN ('review','done')", $pid));
        $wpdb->update(self::t('projects'), ['status' => 'active', 'progress' => $total ? min(100, (int) round($sub / $total * 100)) : 0, 'updated_at' => $now], ['id' => $pid]);
        if (function_exists('vibes_drive_review_sync')) { $res = vibes_drive_review_sync(['project_id' => $pid, 'task_id' => $tid], 'revision'); if (is_wp_error($res)) error_log('[vibes] revision drive move failed: ' . $res->get_error_message()); }
        $title = 'Changes requested · ' . $project['name'];
        $body = $name . ' on "' . $item['title'] . '": ' . mb_substr($message, 0, 240);
        self::notify('', 'client_changes', $title, $body, $pid);
        if (!empty($task['assignee_id'])) $wpdb->insert(self::t('notifications'), ['user_id' => (int) $task['assignee_id'], 'type' => 'client_changes', 'title' => $title, 'body' => $body, 'object_type' => 'task', 'object_id' => $tid, 'created_at' => $now]);
        wp_mail(['info@vibesagency.co', 'info@solaraevents.net'], 'Client requested changes — ' . $project['name'], "Client: {$name}\nProject: {$project['name']}\nItem: {$item['title']}\n\n{$message}\n\nThe task is back In progress and its Drive folder is back in 01 - Working.", ['From: Vibes Agency <admin@vibesagency.co>']);
        return rest_ensure_response(['success' => true]);
    }

    private static function notify(string $unused, string $type, string $title, string $body, int $pid): void {
        global $wpdb;
        foreach (get_users(['role' => 'administrator', 'fields' => 'ID']) as $uid) {
            $wpdb->insert(self::t('notifications'), ['user_id' => $uid, 'type' => $type, 'title' => $title, 'body' => $body, 'object_type' => 'project', 'object_id' => $pid, 'created_at' => current_time('mysql')]);
        }
    }

    /* ---------- file streaming (Drive stays private, files are proxied) ---------- */

    private static function drive_token() {
        $t = get_transient('vcr_drive_token');
        if ($t) return $t;
        if (!function_exists('vibes_drive_access_token')) return new WP_Error('no_drive', 'Drive is not connected.');
        $t = vibes_drive_access_token();
        if (!is_wp_error($t)) set_transient('vcr_drive_token', $t, 3000);
        return $t;
    }

    public static function api_file(WP_REST_Request $r) {
        $project = self::project_by_token((string) $r['token']);
        if (!$project) return new WP_Error('not_found', 'Not found.', ['status' => 404]);
        $fid = (string) $r['fid']; $idx = get_transient('vcr_idx_' . (int) $project['id']); $meta = is_array($idx) ? ($idx[$fid] ?? null) : null;
        foreach ($meta ? [] : self::cycle_tasks($project) as $t) {
            if ($t['status'] !== 'done') continue;
            foreach (self::list_files(self::folder_for_task((int) $project['id'], $t)) as $f) if ($f['id'] === $fid) { $meta = $f; break 2; }
        }
        if (!$meta) return new WP_Error('not_found', 'Not found.', ['status' => 404]);
        $token = self::drive_token(); if (is_wp_error($token)) return new WP_Error('drive', 'File temporarily unavailable.', ['status' => 503]);
        $native = strpos($meta['mime'], 'application/vnd.google-apps.') === 0;
        $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fid) . ($native ? '/export?mimeType=application%2Fpdf' : '?alt=media');
        $mime = $native ? 'application/pdf' : $meta['mime'];
        $inline = in_array($meta['kind'], ['image', 'video', 'audio', 'pdf'], true) && empty($_GET['dl']);
        $name = sanitize_file_name($meta['name']) . ($native ? '.pdf' : '');
        $hdr = ['Authorization: Bearer ' . $token];
        if (!empty($_SERVER['HTTP_RANGE']) && !$native && preg_match('/^bytes=\d*-\d*$/', $_SERVER['HTTP_RANGE'])) $hdr[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        while (ob_get_level()) @ob_end_clean();
        @set_time_limit(0);
        $status = 200; $out = []; $started = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $hdr, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 0,
            CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$status, &$out) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; $out = []; }
                elseif (preg_match('/^(content-length|content-range):\s*(.+)$/i', trim($line), $m)) $out[strtolower($m[1])] = trim($m[2]);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, $chunk) use (&$status, &$out, &$started, $mime, $inline, $name) {
                if ($status >= 400) return 0;
                if (!$started) {
                    $started = true; http_response_code($status);
                    header('Content-Type: ' . $mime); header('Accept-Ranges: bytes');
                    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
                    header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600'); header('X-LiteSpeed-Cache-Control: no-cache');
                    header('Referrer-Policy: no-referrer');
                    if (isset($out['content-length'])) header('Content-Length: ' . $out['content-length']);
                    if (isset($out['content-range'])) header('Content-Range: ' . $out['content-range']);
                }
                echo $chunk; flush();
                return strlen($chunk);
            }]);
        curl_exec($ch); curl_close($ch);
        if (!$started) { status_header(404); header('Content-Type: text/plain'); echo 'File unavailable.'; }
        exit;
    }

    /* ---------- finalize: archive, share the client folder, email the link ---------- */

    public static function finalize($pid): void {
        $pid = (int) $pid; global $wpdb;
        $st = self::state($pid);
        if (($st['status'] ?? '') === 'complete') return;
        $lock = (int) get_option('vcr_lock_' . $pid, 0);
        if ($lock && time() - $lock < 240) return;
        update_option('vcr_lock_' . $pid, time(), false);
        @ignore_user_abort(true); @set_time_limit(600);
        $project = $wpdb->get_row($wpdb->prepare('SELECT p.*, q.email AS client_email, q.client_name AS quote_client FROM ' . self::t('projects') . ' p LEFT JOIN ' . self::t('quotations') . ' q ON q.id=p.quotation_id WHERE p.id=%d', $pid), ARRAY_A);
        if (!$project || !($project['client_review_token'] ?? '')) { delete_option('vcr_lock_' . $pid); return; }
        $name = (string) ($st['name'] ?? 'Client');
        // Reuse the existing approval workflow: status, invoice e-mail and Drive archive copies.
        $req = new WP_REST_Request('POST', '/vibes/v1/public/projects/' . $project['client_review_token'] . '/respond');
        $req->set_body_params(['action' => 'approve', 'name' => $name, 'accepted' => true, 'message' => 'All deliverables were approved item by item.']);
        rest_do_request($req);
        // The generic route hook cannot see the project id on that response, so run the archive step explicitly.
        if (function_exists('vibes_drive_archive_sync')) vibes_drive_archive_sync(['project_id' => $pid]);
        $url = '';
        if (function_exists('vibes_drive_map') && function_exists('vibes_drive_api')) {
            $m = vibes_drive_map(); $entry = $m[(string) $pid] ?? $m[$pid] ?? [];
            foreach (['04 - Client Approved', '03 - Client Approval'] as $stage) {
                $fid = $entry['stages'][$stage]['id'] ?? '';
                if (!$fid) continue;
                $perm = vibes_drive_api('POST', 'files/' . rawurlencode($fid) . '/permissions', ['type' => 'anyone', 'role' => 'reader', 'allowFileDiscovery' => false]);
                if (!is_wp_error($perm)) { $url = 'https://drive.google.com/drive/folders/' . $fid . '?usp=sharing'; break; }
            }
        }
        if (!$url) { $url = (string) ($project['drive_client_url'] ?? ''); }
        if (!$url) { delete_option('vcr_lock_' . $pid); $tries = (int) ($st['tries'] ?? 0) + 1; self::notify('', 'client_delivery_issue', 'Client approved but no Drive link could be created', $project['name'], $pid); update_option('vcr_state_' . $pid, ['status' => $tries >= 3 ? 'complete' : 'finalizing', 'name' => $name, 'url' => '', 'at' => time(), 'tries' => $tries], false); return; }
        $wpdb->update(self::t('projects'), ['drive_client_url' => esc_url_raw($url), 'updated_at' => current_time('mysql')], ['id' => $pid]);
        update_option('vcr_state_' . $pid, ['status' => 'complete', 'name' => $name, 'url' => $url, 'at' => time()], false);
        delete_option('vcr_lock_' . $pid);
        $to = sanitize_email((string) ($project['client_email'] ?? ''));
        $page = add_query_arg('token', $project['client_review_token'], home_url('/project-review/'));
        if ($to) {
            $html = '<div style="font-family:Arial,sans-serif;color:#231a30;line-height:1.6;max-width:600px;margin:auto"><h1 style="color:#60338d">All approved — your files are ready</h1><p>Hello ' . esc_html($name) . ',</p><p>Thank you for approving every deliverable of <strong>' . esc_html($project['name']) . '</strong>. You can download all of your work from the link below.</p><p><a href="' . esc_url($url) . '" style="display:inline-block;background:#60338d;color:#fff;text-decoration:none;padding:13px 22px;border-radius:12px;font-weight:700">Download all my work</a></p><p style="color:#6f6482;font-size:13px">You can also find the link any time on your review page: <a href="' . esc_url($page) . '">' . esc_html($page) . '</a></p><p>WE SET VIBES.<br>Vibes Agency</p></div>';
            wp_mail($to, 'Your Vibes files are ready to download — ' . $project['name'], $html, ['From: Vibes Agency <admin@vibesagency.co>', 'Reply-To: admin@vibesagency.co', 'Content-Type: text/html; charset=UTF-8']);
        }
        self::notify('', 'client_all_approved', 'Client approved every deliverable', $project['name'] . ' · download link sent' . ($to ? ' to ' . $to : ''), $pid);
    }

    /* ---------- admin view ---------- */

    public static function api_feedback(WP_REST_Request $r) {
        global $wpdb; $pid = (int) $r['id'];
        $project = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('projects') . ' WHERE id=%d', $pid), ARRAY_A);
        if (!$project) return new WP_Error('not_found', 'Project not found.', ['status' => 404]);
        $b = self::build($project);
        $items = array_map(static fn($i) => ['id' => $i['id'], 'number' => $i['number'], 'title' => $i['title'], 'state' => $i['state'], 'approved_by' => $i['approved_by'], 'approved_at' => $i['approved_at'], 'files' => count($i['files'])], $b['items']);
        $titles = []; foreach ($b['items'] as $i) $titles[$i['id']] = $i['title'];
        $rows = $wpdb->get_results($wpdb->prepare('SELECT task_id, author, message, created_at FROM ' . self::t('client_comments') . ' WHERE project_id=%d ORDER BY id DESC LIMIT 100', $pid), ARRAY_A) ?: [];
        foreach ($rows as &$c) { $c['task_id'] = (int) $c['task_id']; $c['title'] = $titles[$c['task_id']] ?? 'Deliverable'; }
        $st = $b['state'];
        return rest_ensure_response(['status' => $st['status'] ?? 'open', 'download_url' => $st['url'] ?? '', 'waiting' => $b['waiting'], 'items' => $items, 'comments' => $rows, 'page' => $project['client_review_token'] ? add_query_arg('token', $project['client_review_token'], home_url('/project-review/')) : '']);
    }

    public static function api_reviews(WP_REST_Request $r) {
        global $wpdb;
        $projects = $wpdb->get_results('SELECT p.id, p.name, p.client, p.client_review_token, p.client_review_status, p.client_review_month, p.client_review_at, p.drive_client_url, q.email AS client_email FROM ' . self::t('projects') . ' p LEFT JOIN ' . self::t('quotations') . ' q ON q.id=p.quotation_id WHERE (p.archived_at IS NULL OR LENGTH(p.client_review_token)>0) ORDER BY p.id DESC LIMIT 200', ARRAY_A) ?: [];
        $out = [];
        foreach ($projects as $p) {
            $pid = (int) $p['id']; $tok = (string) $p['client_review_token'];
            $tasks = self::cycle_tasks($p);
            $done = 0; foreach ($tasks as $t) if ($t['status'] === 'done') $done++;
            $rows = self::item_rows($pid); $approved = 0; $last = '';
            foreach ($rows as $row) { if ($row['state'] === 'approved') $approved++; if (!empty($row['approved_at']) && $row['approved_at'] > $last) $last = $row['approved_at']; }
            $cm = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) n, MAX(created_at) at FROM ' . self::t('client_comments') . ' WHERE project_id=%d', $pid), ARRAY_A);
            if (!empty($cm['at']) && $cm['at'] > $last) $last = $cm['at'];
            $st = get_option('vcr_state_' . $pid, []); $sst = $st['status'] ?? 'open';
            $status = !$tok ? 'not_sent' : ($sst === 'complete' ? 'complete' : ($sst === 'finalizing' ? 'finalizing' : 'in_review'));
            $out[] = ['id' => $pid, 'name' => $p['name'], 'client' => $p['client'], 'email' => (string) $p['client_email'], 'status' => $status, 'total' => count($tasks), 'ready' => $done, 'approved' => $approved, 'comments' => (int) ($cm['n'] ?? 0), 'last' => $last ?: (string) $p['client_review_at'], 'last_ts' => ($last ?: (string) $p['client_review_at']) ? (int) strtotime(get_gmt_from_date($last ?: (string) $p['client_review_at'])) : 0, 'sent_at' => (string) $p['client_review_at'], 'page' => $tok ? add_query_arg('token', $tok, home_url('/project-review/')) : '', 'download_url' => $sst === 'complete' ? (string) ($st['url'] ?? '') : ''];
        }
        return rest_ensure_response(['projects' => $out]);
    }

    /* ---------- the public page ---------- */

    public static function page(): void {
        if (!is_page('project-review')) return;
        status_header(200); nocache_headers();
        header('X-Robots-Tag: noindex, nofollow, noarchive'); header('Referrer-Policy: no-referrer'); header('X-LiteSpeed-Cache-Control: no-cache');
        $root = esc_url_raw(rest_url('vibes/v1/public/projects/'));
        $logo = defined('VTP_URL') ? VTP_URL . 'assets/images/vibes-logo-cropped.png' : '';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Your Vibes Deliverables</title><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet"><style>' . self::css() . '</style></head><body><div id="app"><div class="vcr-boot"><span></span><p>Loading your deliverables…</p></div></div><script>window.VCR=' . wp_json_encode(['root' => $root, 'logo' => $logo]) . ';' . self::js() . '</script></body></html>';
        exit;
    }

    private static function css(): string {
        return <<<'CSS'
:root{--p:#60338d;--p2:#8a5cc4;--ink:#231a30;--mut:#6f6482;--line:#e8e0f2;--wash:#f7f3fc;--ok:#16794c;--okbg:#e6f6ee;--warn:#b45309}
*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}body{margin:0;font-family:'DM Sans',system-ui,sans-serif;color:var(--ink);background:linear-gradient(180deg,#f4effa,#fbf9fe 280px);min-height:100vh}
button,input,textarea{font:inherit;color:inherit}a{color:var(--p)}
.vcr-wrap{max-width:860px;margin:0 auto;padding:0 16px 80px}
.vcr-top{display:flex;align-items:center;justify-content:space-between;padding:20px 0}.vcr-top img{height:34px}.vcr-top span{font:700 11px 'Manrope';letter-spacing:.14em;color:var(--mut)}
.vcr-hero{background:#fff;border:1px solid var(--line);border-radius:22px;padding:24px;box-shadow:0 14px 40px rgba(96,51,141,.08)}
.vcr-hero h1{font:800 26px/1.2 'Manrope';margin:0 0 4px}.vcr-hero p{margin:0;color:var(--mut)}
.vcr-bar{height:8px;border-radius:99px;background:var(--wash);overflow:hidden;margin:18px 0 8px}.vcr-bar i{display:block;height:100%;background:linear-gradient(90deg,var(--p2),var(--p));border-radius:99px;transition:width .4s}
.vcr-count{font-size:13px;color:var(--mut);display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}
.vcr-who{display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap}.vcr-who label{font-size:13px;font-weight:600;color:var(--mut)}
.vcr-who input{flex:1;min-width:180px;height:42px;border:1px solid var(--line);border-radius:12px;padding:0 14px;background:var(--wash)}.vcr-who input:focus,textarea:focus{outline:2px solid var(--p2);outline-offset:1px}
.vcr-done{margin-top:18px;border-radius:18px;padding:20px;background:linear-gradient(135deg,#5a2d93,#8a5cc4);color:#fff}.vcr-done h2{margin:0 0 6px;font:800 20px 'Manrope'}.vcr-hero .vcr-done p{margin:0 0 14px;color:#fff;opacity:.92}
.vcr-dl{display:inline-flex;align-items:center;gap:8px;background:#fff;color:var(--p);font-weight:700;text-decoration:none;border-radius:12px;padding:12px 18px}
.vcr-spin{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:s 1s linear infinite;vertical-align:-3px;margin-right:8px}@keyframes s{to{transform:rotate(360deg)}}
.vcr-item{background:#fff;border:1px solid var(--line);border-radius:20px;margin-top:18px;overflow:hidden;box-shadow:0 8px 28px rgba(96,51,141,.06)}
.vcr-item.is-ok{border-color:#bfe6d1}
.vcr-head{padding:18px 20px 12px;display:flex;gap:12px;justify-content:space-between;align-items:flex-start}.vcr-head small{font:700 11px 'Manrope';letter-spacing:.1em;color:var(--mut);text-transform:uppercase}.vcr-head h2{margin:2px 0 0;font:700 18px/1.3 'Manrope'}
.vcr-badge{flex:none;font-size:12px;font-weight:700;border-radius:99px;padding:5px 11px;background:var(--wash);color:var(--p)}.is-ok .vcr-badge{background:var(--okbg);color:var(--ok)}
.vcr-note{padding:0 20px 12px;color:var(--mut);font-size:14px;white-space:pre-wrap}
.vcr-media{display:grid;gap:12px;padding:4px 20px 8px}.vcr-media figure{margin:0}
.vcr-media img{display:block;width:100%;height:auto;border-radius:14px;background:var(--wash);cursor:zoom-in}
.vcr-media video{display:block;width:100%;max-height:70vh;border-radius:14px;background:#000}.vcr-media audio{width:100%}
.vcr-media iframe{width:100%;height:520px;border:1px solid var(--line);border-radius:14px;background:var(--wash)}
.vcr-media figcaption{font-size:12px;color:var(--mut);margin-top:6px;display:flex;justify-content:space-between;gap:8px}
.vcr-file{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--line);border-radius:14px;text-decoration:none;color:var(--ink);background:var(--wash)}.vcr-file b{font-weight:600;word-break:break-word}.vcr-file span{margin-left:auto;color:var(--p);font-weight:700;font-size:13px;white-space:nowrap}
.vcr-empty{padding:4px 20px 12px;color:var(--mut);font-size:14px}
.vcr-act{display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:14px 20px 18px;border-top:1px solid var(--line);margin-top:8px;background:#fcfaff}
.vcr-btn{height:44px;padding:0 20px;border-radius:13px;border:1px solid var(--line);background:#fff;font-weight:700;cursor:pointer}.vcr-btn.pri{background:var(--p);border-color:var(--p);color:#fff}.vcr-btn:disabled{opacity:.55;cursor:default}
.vcr-link{background:none;border:0;color:var(--mut);text-decoration:underline;cursor:pointer;padding:0 4px;font-size:13px}
.vcr-okmsg{color:var(--ok);font-weight:700;font-size:14px}.vcr-sent{color:var(--mut);font-size:13px}
.vcr-cm{flex-basis:100%;display:grid;gap:8px}.vcr-cm textarea{width:100%;min-height:96px;border:1px solid var(--line);border-radius:12px;padding:12px;resize:vertical;background:#fff}.vcr-cm div{display:flex;gap:8px;justify-content:flex-end}
.vcr-wait{margin-top:18px;padding:14px 18px;border:1px dashed #cdbbe4;border-radius:16px;color:var(--mut);font-size:14px;background:rgba(255,255,255,.6)}
.vcr-err{max-width:480px;margin:18vh auto;text-align:center;padding:0 20px}.vcr-err h1{font:800 22px 'Manrope'}.vcr-boot{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;min-height:60vh;color:var(--mut)}.vcr-boot p{margin:0}.vcr-boot span{width:30px;height:30px;border:3px solid var(--line);border-top-color:var(--p);border-radius:50%;animation:s 1s linear infinite}
.vcr-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#231a30;color:#fff;padding:11px 18px;border-radius:12px;font-size:14px;z-index:30;max-width:90vw}
.vcr-lb{position:fixed;inset:0;background:rgba(15,10,24,.92);display:grid;place-items:center;z-index:40;padding:16px;cursor:zoom-out}.vcr-lb img{max-width:100%;max-height:100%;border-radius:8px}
@media(max-width:560px){.vcr-hero{padding:18px}.vcr-hero h1{font-size:22px}.vcr-head{padding:16px 16px 10px}.vcr-media,.vcr-note,.vcr-empty{padding-left:16px;padding-right:16px}.vcr-act{padding:14px 16px 16px}.vcr-media iframe{height:380px}.vcr-act .vcr-btn.pri{flex:1}}
CSS;
    }

    private static function js(): string {
        return <<<'JS'
(()=>{
const {root,logo}=window.VCR,token=new URLSearchParams(location.search).get('token')||'',base=root+token+'/',app=document.getElementById('app');
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
let data=null,name='',poll=null;try{name=localStorage.getItem('vcr_name')||''}catch(e){}
const bytes=n=>!n?'':n>1048576?(n/1048576).toFixed(1)+' MB':Math.max(1,Math.round(n/1024))+' KB';
const toast=m=>{document.querySelectorAll('.vcr-toast').forEach(x=>x.remove());const t=document.createElement('div');t.className='vcr-toast';t.textContent=m;document.body.appendChild(t);setTimeout(()=>t.remove(),3200)};
async function call(path,method='GET',body){const r=await fetch(base+path,{method,headers:{Accept:'application/json','Content-Type':'application/json'},body:body?JSON.stringify(body):undefined,cache:'no-store'});let j={};try{j=await r.json()}catch(e){}if(!r.ok)throw new Error(j.message||'Something went wrong. Please try again.');return j}
function media(f){const u=base+'file/'+f.id,cap=`<figcaption><span>${esc(f.name)}</span><a href="${u}?dl=1">Download</a></figcaption>`;
if(f.kind==='image')return `<figure><img loading="lazy" src="${u}" alt="${esc(f.name)}" data-lb="${u}">${cap}</figure>`;
if(f.kind==='video')return `<figure><video controls preload="metadata" playsinline src="${u}"></video>${cap}</figure>`;
if(f.kind==='audio')return `<figure><audio controls preload="none" src="${u}"></audio>${cap}</figure>`;
if(f.kind==='pdf')return `<figure><iframe loading="lazy" src="${u}#toolbar=0&view=FitH" title="${esc(f.name)}"></iframe><figcaption><span>${esc(f.name)}</span><a href="${u}" target="_blank" rel="noopener">Open full size</a></figcaption></figure>`;
return `<a class="vcr-file" href="${u}?dl=1"><b>${esc(f.name)}</b><small>${bytes(f.size)}</small><span>Download</span></a>`}
const locked=()=>data&&data.status!=='open';
function actions(i){if(i.state==='approved')return `<span class="vcr-okmsg">✓ Approved${i.approved_by?' by '+esc(i.approved_by):''}</span>${locked()?'':'<button class="vcr-link" data-undo>Undo</button>'}`;
if(locked())return '';
return `<button class="vcr-btn pri" data-approve>Approve</button><button class="vcr-btn" data-cm>Add a comment</button><button class="vcr-btn" data-chg>Request changes</button>${i.sent?'<span class="vcr-sent">Comment sent to Vibes ✓</span>':''}<div class="vcr-cm" hidden><textarea placeholder="Tell us what you would like changed or add a note…" maxlength="3000"></textarea><div><button class="vcr-btn" data-cancel>Cancel</button><button class="vcr-btn pri" data-send>Send comment</button></div></div>`}
function card(i){return `<article class="vcr-item ${i.state==='approved'?'is-ok':''}" data-id="${i.id}"><div class="vcr-head"><div><small>${esc(i.label||'Deliverable')}${i.number?' · '+esc(i.number):''}</small><h2>${esc(i.title)}</h2></div><span class="vcr-badge">${i.state==='approved'?'Approved':'Awaiting your approval'}</span></div>${i.note?`<div class="vcr-note">${esc(i.note)}</div>`:''}${i.files.length?`<div class="vcr-media">${i.files.map(media).join('')}</div>`:'<div class="vcr-empty">Files for this item will appear here shortly.</div>'}<div class="vcr-act">${actions(i)}</div></article>`}
function top(){const total=data.items.length,pct=total?Math.round(data.approved/total*100):0;let done='';
if(data.status==='complete')done=`<div class="vcr-done"><h2>All approved — thank you!</h2><p>Your complete work is ready. We also emailed you this link.</p>${data.download_url?`<a class="vcr-dl" href="${esc(data.download_url)}" target="_blank" rel="noopener">⬇ Download all my work</a>`:'<p>Vibes Agency will send your download link shortly.</p>'}</div>`;
else if(data.status==='finalizing')done=`<div class="vcr-done"><h2><span class="vcr-spin"></span>Preparing your download…</h2><p>Everything is approved. We are packaging your files — this page updates automatically.</p></div>`;
return `<section class="vcr-hero"><h1>${esc(data.project.name)}</h1><p>${esc(data.project.client||'')}</p><div class="vcr-bar"><i style="width:${pct}%"></i></div><div class="vcr-count"><span>${data.approved} of ${total} approved</span>${data.waiting?`<span>${data.waiting} more coming soon</span>`:''}</div>${locked()?'':`<div class="vcr-who"><label for="vcr-name">Your name</label><input id="vcr-name" value="${esc(name)}" placeholder="So we know who approved" autocomplete="name"></div>`}${done}</section>`}
function render(){app.innerHTML=`<div class="vcr-wrap"><div class="vcr-top"><img src="${esc(logo)}" alt="Vibes Agency"><span>SECURE DELIVERY REVIEW</span></div><div id="vcr-top">${top()}</div><div id="vcr-items">${data.items.map(card).join('')||'<div class="vcr-wait">Your deliverables will appear here as soon as they are ready.</div>'}</div>${data.waiting&&data.items.length?`<div class="vcr-wait">${data.waiting} more deliverable${data.waiting>1?'s are':' is'} still being finalized and will appear here when ready.</div>`:''}</div>`}
function refreshState(){document.getElementById('vcr-top').innerHTML=top();data.items.forEach(i=>{const el=app.querySelector(`[data-id="${i.id}"]`);if(!el)return;el.classList.toggle('is-ok',i.state==='approved');el.querySelector('.vcr-badge').textContent=i.state==='approved'?'Approved':'Awaiting your approval';el.querySelector('.vcr-act').innerHTML=actions(i)});schedule()}
function schedule(){clearInterval(poll);if(data.status==='finalizing')poll=setInterval(async()=>{try{const d=await call('portal');d.items.forEach(n=>{const o=data.items.find(x=>x.id===n.id);if(o)n.sent=o.sent});data=d;refreshState()}catch(e){}},4000)}
const need=()=>{const el=document.getElementById('vcr-name');const v=(el?el.value:name).trim();if(!v){toast('Please enter your name first.');el&&el.focus();return ''}name=v;try{localStorage.setItem('vcr_name',v)}catch(e){}return v};
app.addEventListener('click',async e=>{const lb=e.target.closest('[data-lb]');if(lb){const o=document.createElement('div');o.className='vcr-lb';o.innerHTML=`<img src="${esc(lb.dataset.lb)}" alt="">`;o.onclick=()=>o.remove();document.body.appendChild(o);return}
const card=e.target.closest('.vcr-item');if(!card)return;const item=data.items.find(i=>String(i.id)===card.dataset.id);const btn=e.target.closest('button');if(!btn||!item)return;
const cm=card.querySelector('.vcr-cm');
if(btn.hasAttribute('data-cm')||btn.hasAttribute('data-chg')){const ch=btn.hasAttribute('data-chg');cm.dataset.mode=ch?'changes':'comment';const ta=cm.querySelector('textarea');ta.placeholder=ch?'Describe exactly what you would like us to change…':'Add a note for the team…';cm.querySelector('[data-send]').textContent=ch?'Send change request':'Send comment';cm.hidden=false;ta.focus();card.querySelectorAll('[data-cm],[data-chg]').forEach(x=>x.hidden=true);return}
if(btn.hasAttribute('data-cancel')){cm.hidden=true;card.querySelectorAll('[data-cm],[data-chg]').forEach(x=>x.hidden=false);return}
try{
if(btn.hasAttribute('data-approve')){const n=need();if(!n)return;btn.disabled=true;const r=await call(`items/${item.id}/approve`,'POST',{name:n});item.state='approved';item.approved_by=n;data.approved=r.approved;if(r.all_approved){data.status='finalizing'}refreshState();if(!r.all_approved)toast('Approved ✓')}
else if(btn.hasAttribute('data-undo')){btn.disabled=true;const r=await call(`items/${item.id}/undo`,'POST',{});item.state='pending';data.approved=r.approved;refreshState()}
else if(btn.hasAttribute('data-send')){const n=need();if(!n)return;const t=cm.querySelector('textarea').value.trim();if(!t){toast('Write your comment first.');return}btn.disabled=true;if(cm.dataset.mode==='changes'){await call(`items/${item.id}/changes`,'POST',{name:n,message:t});data.items=data.items.filter(x=>x.id!==item.id);data.waiting=(data.waiting||0)+1;render();toast('Change request sent — we will update this item')}else{await call(`items/${item.id}/comment`,'POST',{name:n,message:t});item.sent=true;refreshState();toast('Comment sent to Vibes ✓')}}
}catch(err){toast(err.message);btn.disabled=false}});
(async()=>{try{if(!/^[a-f0-9]{64}$/.test(token))throw new Error('This link is not valid. Please use the link from your email.');data=await call('portal');render();schedule()}catch(e){app.innerHTML=`<div class="vcr-err"><h1>Link unavailable</h1><p>${esc(e.message)}</p><p><a href="https://vibesagency.co/contact-creative-agency">Contact Vibes Agency</a></p></div>`}})();
})();
JS;
    }
}
VPM_ClientPortal::boot();

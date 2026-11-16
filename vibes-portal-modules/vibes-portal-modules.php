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
        add_action('vpm_client_rating_reminder', [self::class, 'rating_reminder'], 10, 1);
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
        register_rest_route(self::NS, "/public/projects/{$tok}/rating", ['methods' => 'POST', 'callback' => [self::class, 'api_rating'], 'permission_callback' => $pub]);
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
            'rating' => ($rt = get_option('vcr_rating_' . (int) $project['id'])) ? ['stars' => (int) $rt['stars']] : null,
        ]);
    }

    public static function api_rating(WP_REST_Request $r) {
        $project = self::project_by_token((string) $r['token']);
        if (!$project) return new WP_Error('not_found', 'This link is invalid or has expired.', ['status' => 404]);
        $pid = (int) $project['id'];
        $st = get_option('vcr_state_' . $pid, []);
        if (($st['status'] ?? '') !== 'complete') return new WP_Error('not_ready', 'You can rate us once everything is approved.', ['status' => 409]);
        $stars = (int) $r['stars'];
        if ($stars < 1 || $stars > 5) return new WP_Error('bad_stars', 'Please choose 1 to 5 stars.', ['status' => 400]);
        $rl = 'vcr_rate_rl_' . md5((string) $r['token']);
        $n = (int) get_transient($rl); if ($n >= 10) return new WP_Error('slow_down', 'Too many attempts. Please try again later.', ['status' => 429]);
        set_transient($rl, $n + 1, HOUR_IN_SECONDS);
        $name = self::name_of($r) ?: (string) ($st['name'] ?? 'Client');
        $comment = mb_substr(trim(sanitize_textarea_field((string) $r['comment'])), 0, 2000);
        $had = (bool) get_option('vcr_rating_' . $pid);
        update_option('vcr_rating_' . $pid, ['stars' => $stars, 'comment' => $comment, 'name' => $name, 'at' => current_time('mysql')], false);
        $bar = str_repeat('★', $stars) . str_repeat('☆', 5 - $stars);
        $title = ($stars <= 2 ? 'Low client rating ⚠ ' : 'Client rating ') . $bar;
        $body = $project['name'] . ' · ' . $name . ($comment ? ' · “' . wp_trim_words($comment, 18, '…') . '”' : '');
        self::notify('', 'client_rating', $title, $body, $pid);
        return rest_ensure_response(['ok' => true, 'rating' => ['stars' => $stars], 'updated' => $had]);
    }

    public static function rating_reminder($pid): void {
        global $wpdb; $pid = (int) $pid;
        if (get_option('vcr_rating_' . $pid) || get_option('vcr_rating_rem_' . $pid)) return;
        $project = $wpdb->get_row($wpdb->prepare('SELECT p.*, q.email AS client_email, q.client_name AS quote_client FROM ' . self::t('projects') . ' p LEFT JOIN ' . self::t('quotations') . ' q ON q.id=p.quotation_id WHERE p.id=%d', $pid), ARRAY_A);
        if (!$project || !($project['client_review_token'] ?? '')) return;
        $to = sanitize_email((string) ($project['client_email'] ?? '')); if (!$to) return;
        update_option('vcr_rating_rem_' . $pid, time(), false);
        $st = get_option('vcr_state_' . $pid, []); $name = (string) ($st['name'] ?? 'there');
        $page = add_query_arg('token', $project['client_review_token'], home_url('/project-review/'));
        $html = '<div style="font-family:Arial,sans-serif;color:#231a30;line-height:1.6;max-width:600px;margin:auto"><h1 style="color:#60338d">How did we do?</h1><p>Hello ' . esc_html($name) . ',</p><p>We hope you are happy with <strong>' . esc_html($project['name']) . '</strong>. It takes ten seconds to tell us how it went — your feedback helps us improve.</p><p><a href="' . esc_url($page) . '" style="display:inline-block;background:#60338d;color:#fff;text-decoration:none;padding:13px 22px;border-radius:12px;font-weight:700">Rate our work ★</a></p></div>';
        wp_mail($to, 'How did we do? — ' . $project['name'], $html, ['From: Vibes Agency <admin@vibesagency.co>', 'Reply-To: admin@vibesagency.co', 'Content-Type: text/html; charset=UTF-8']);
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
        if (!wp_next_scheduled('vpm_client_rating_reminder', [$pid])) wp_schedule_single_event(time() + 2 * DAY_IN_SECONDS, 'vpm_client_rating_reminder', [$pid]);
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
        return rest_ensure_response(['status' => $st['status'] ?? 'open', 'download_url' => $st['url'] ?? '', 'waiting' => $b['waiting'], 'items' => $items, 'comments' => $rows, 'rating' => get_option('vcr_rating_' . $pid) ?: null, 'page' => $project['client_review_token'] ? add_query_arg('token', $project['client_review_token'], home_url('/project-review/')) : '']);
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
            $out[] = ['id' => $pid, 'name' => $p['name'], 'client' => $p['client'], 'email' => (string) $p['client_email'], 'status' => $status, 'total' => count($tasks), 'ready' => $done, 'approved' => $approved, 'comments' => (int) ($cm['n'] ?? 0), 'last' => $last ?: (string) $p['client_review_at'], 'last_ts' => ($last ?: (string) $p['client_review_at']) ? (int) strtotime(get_gmt_from_date($last ?: (string) $p['client_review_at'])) : 0, 'sent_at' => (string) $p['client_review_at'], 'page' => $tok ? add_query_arg('token', $tok, home_url('/project-review/')) : '', 'download_url' => $sst === 'complete' ? (string) ($st['url'] ?? '') : '', 'rating' => get_option('vcr_rating_' . $pid) ?: null];
        }
        return rest_ensure_response(['projects' => $out]);
    }

    /* ---------- the public page ---------- */

    public static function page(): void {
        if (!is_page('project-review')) return;
        status_header(200); nocache_headers(); do_action('litespeed_control_set_nocache', 'vibes client review');
        header('X-Robots-Tag: noindex, nofollow, noarchive'); header('Referrer-Policy: no-referrer'); header('X-LiteSpeed-Cache-Control: no-cache');
        $root = esc_url_raw(rest_url('vibes/v1/public/projects/'));
        $logo = defined('VTP_URL') ? VTP_URL . 'assets/images/vibes-logo-cropped.png' : '';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><link rel="icon" type="image/png" href="https://team.vibesagency.co/wp-content/uploads/2026/11/vibes-app-logo-s-192.png"><title>Your Vibes Deliverables</title><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet"><style>' . self::css() . '</style></head><body><div id="app"><div class="vcr-boot"><span></span><p>Loading your deliverables…</p></div></div><script>window.VCR=' . wp_json_encode(['root' => $root, 'logo' => $logo]) . ';' . self::js() . '</script></body></html>';
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
.vcr-cm{flex-basis:100%;display:grid;gap:8px}.vcr-cm[hidden]{display:none}.vcr-cm textarea{width:100%;min-height:96px;border:1px solid var(--line);border-radius:12px;padding:12px;resize:vertical;background:#fff}.vcr-cm div{display:flex;gap:8px;justify-content:flex-end}
.vcr-wait{margin-top:18px;padding:14px 18px;border:1px dashed #cdbbe4;border-radius:16px;color:var(--mut);font-size:14px;background:rgba(255,255,255,.6)}
.vcr-err{max-width:480px;margin:18vh auto;text-align:center;padding:0 20px}.vcr-err h1{font:800 22px 'Manrope'}.vcr-boot{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;min-height:60vh;color:var(--mut)}.vcr-boot p{margin:0}.vcr-boot span{width:30px;height:30px;border:3px solid var(--line);border-top-color:var(--p);border-radius:50%;animation:s 1s linear infinite}
.vcr-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#231a30;color:#fff;padding:11px 18px;border-radius:12px;font-size:14px;z-index:30;max-width:90vw}
.vcr-lb{position:fixed;inset:0;background:rgba(15,10,24,.92);display:grid;place-items:center;z-index:40;padding:16px;cursor:zoom-out}.vcr-lb img{max-width:100%;max-height:100%;border-radius:8px}
.vcr-rate{margin-top:18px;background:#fff;border:1px solid var(--line);border-radius:20px;padding:22px;text-align:center;box-shadow:0 8px 28px rgba(96,51,141,.06)}.vcr-rate h2{margin:0 0 4px;font:800 19px 'Manrope'}.vcr-rate p{margin:0 0 10px;color:var(--mut)}
.vcr-stars{display:flex;justify-content:center;gap:6px;margin:8px 0 14px}.vcr-star{background:none;border:0;font-size:40px;line-height:1;color:#d9cfe8;cursor:pointer;padding:2px 4px}.vcr-star.on{color:#f5a623}.vcr-stars.ro .vcr-star{font-size:30px;cursor:default}
.vcr-rate textarea{width:100%;min-height:84px;border:1px solid var(--line);border-radius:12px;padding:12px;resize:vertical;margin-bottom:12px}
@media(max-width:560px){.vcr-hero{padding:18px}.vcr-hero h1{font-size:22px}.vcr-head{padding:16px 16px 10px}.vcr-media,.vcr-note,.vcr-empty{padding-left:16px;padding-right:16px}.vcr-act{padding:14px 16px 16px}.vcr-media iframe{height:380px}.vcr-act .vcr-btn.pri{flex:1}}
CSS;
    }

    private static function js(): string {
        return <<<'JS'
(()=>{
const {root,logo}=window.VCR,token=new URLSearchParams(location.search).get('token')||'',base=root+token+'/',app=document.getElementById('app');
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
let data=null,name='',poll=null,rate={s:0};try{name=localStorage.getItem('vcr_name')||''}catch(e){}
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
function top0(){const total=data.items.length,pct=total?Math.round(data.approved/total*100):0;let done='';
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
function ratingBox(){if(!data||data.status!=='complete')return '';
if(data.rating)return `<section class="vcr-rate"><h2>Thank you for your feedback</h2><div class="vcr-stars ro">${[1,2,3,4,5].map(n=>`<span class="vcr-star${n<=data.rating.stars?' on':''}">★</span>`).join('')}</div></section>`;
return `<section class="vcr-rate"><h2>How was your experience with Vibes?</h2><p>Tap a star to rate our work on this project.</p><div class="vcr-stars">${[1,2,3,4,5].map(n=>`<button type="button" class="vcr-star${n<=rate.s?' on':''}" data-star="${n}" aria-label="${n} star${n>1?'s':''}">★</button>`).join('')}</div><textarea id="vcr-rc" maxlength="2000" placeholder="Anything you would like to tell us? (optional)"></textarea><button class="vcr-btn pri" data-rate-send ${rate.s?'':'disabled'}>Send my rating</button></section>`}
function top(){return top0()+ratingBox()}
app.addEventListener('click',async e=>{const st=e.target.closest('[data-star]');if(st){rate.s=+st.dataset.star;app.querySelectorAll('.vcr-star').forEach(b=>b.classList.toggle('on',+b.dataset.star<=rate.s));const b0=app.querySelector('[data-rate-send]');if(b0)b0.disabled=false;return}
const sb=e.target.closest('[data-rate-send]');if(!sb)return;sb.disabled=true;try{await call('rating','POST',{stars:rate.s,comment:(document.getElementById('vcr-rc')||{}).value||'',name:name});data.rating={stars:rate.s};document.getElementById('vcr-top').innerHTML=top();toast('Thank you for your feedback!')}catch(err){toast(err.message);sb.disabled=false}});
(async()=>{try{if(!/^[a-f0-9]{64}$/.test(token))throw new Error('This link is not valid. Please use the link from your email.');data=await call('portal');render();schedule()}catch(e){app.innerHTML=`<div class="vcr-err"><h1>Link unavailable</h1><p>${esc(e.message)}</p><p><a href="https://vibesagency.co/contact-creative-agency">Contact Vibes Agency</a></p></div>`}})();
})();
JS;
    }
}
VPM_ClientPortal::boot();

/* ------------------------------------------------------------------------
 * Library: Google Drive-backed shared folders (Templates, Music, ...) shown
 * in Files & Brand. Admin creates/removes folders and deletes files; the whole
 * team can view, download and upload. Deletion is enforced here because a
 * personal Drive has no "add but never delete" role.
 * ---------------------------------------------------------------------- */
final class VPM_Library {
    const NS = 'vibes/v1';
    const OPT = 'vpm_library';
    const DEFAULTS = ['Templates', 'Music', 'Sound Effects', 'Videos', 'Images', 'Style References'];
    const FOLDER = 'application/vnd.google-apps.folder';

    public static function boot(): void { add_action('rest_api_init', [self::class, 'routes']); }

    public static function team(): bool { return is_user_logged_in() && (current_user_can('manage_options') || current_user_can('vpm_use_connect') || current_user_can('read')); }
    public static function admin(): bool { return current_user_can('manage_options'); }

    public static function routes(): void {
        register_rest_route(self::NS, '/library', ['methods' => 'GET', 'callback' => [self::class, 'index'], 'permission_callback' => [self::class, 'team']]);
        register_rest_route(self::NS, '/library/search', ['methods' => 'GET', 'callback' => [self::class, 'search'], 'permission_callback' => [self::class, 'team']]);
        register_rest_route(self::NS, '/library/recent', ['methods' => 'GET', 'callback' => [self::class, 'recent'], 'permission_callback' => [self::class, 'team']]);
        register_rest_route(self::NS, '/library/setup', ['methods' => 'POST', 'callback' => [self::class, 'setup'], 'permission_callback' => [self::class, 'admin']]);
        register_rest_route(self::NS, '/library/folders', ['methods' => 'POST', 'callback' => [self::class, 'add_folder'], 'permission_callback' => [self::class, 'admin']]);
        register_rest_route(self::NS, '/library/folders/(?P<id>[A-Za-z0-9_-]+)', ['methods' => 'DELETE', 'callback' => [self::class, 'remove_folder'], 'permission_callback' => [self::class, 'admin']]);
        register_rest_route(self::NS, '/library/folders/(?P<id>[A-Za-z0-9_-]+)/files', ['methods' => 'GET', 'callback' => [self::class, 'files'], 'permission_callback' => [self::class, 'team']]);
        register_rest_route(self::NS, '/library/folders/(?P<id>[A-Za-z0-9_-]+)/upload', ['methods' => 'POST', 'callback' => [self::class, 'upload'], 'permission_callback' => [self::class, 'team']]);
        register_rest_route(self::NS, '/library/files/(?P<id>[A-Za-z0-9_-]+)', ['methods' => 'DELETE', 'callback' => [self::class, 'delete_file'], 'permission_callback' => [self::class, 'admin']]);
    }

    private static function state(): array { $s = get_option(self::OPT, []); return is_array($s) ? $s : []; }
    private static function save(array $s): void { update_option(self::OPT, $s, false); }
    private static function url(string $id): string { return 'https://drive.google.com/drive/folders/' . $id; }
    private static function drive_ok() { return function_exists('vibes_drive_api') && function_exists('vibes_drive_create_folder'); }
    private static function err($e) {
        $m = is_wp_error($e) ? $e->get_error_message() : 'Google Drive request failed.';
        $d = is_wp_error($e) ? $e->get_error_data() : null;
        $detail = is_array($d) && !empty($d['body']['error']['message']) ? ' (' . $d['body']['error']['message'] . ')' : '';
        return new WP_Error('vpm_library', $m . $detail, ['status' => 502]);
    }
    private static function share($id) {
        return vibes_drive_api('POST', 'files/' . rawurlencode($id) . '/permissions', ['type' => 'anyone', 'role' => 'reader'], ['supportsAllDrives' => 'true']);
    }
    private static function known(string $id): bool {
        foreach ((self::state()['folders'] ?? []) as $f) if (($f['id'] ?? '') === $id) return true;
        return false;
    }

    private static function log_get(): array { $l = get_option('vpm_library_log', []); return is_array($l) ? $l : []; }
    private static function folder_name(string $id): string { foreach ((self::state()['folders'] ?? []) as $f) if (($f['id'] ?? '') === $id) return (string) $f['name']; return ''; }

    public static function recent() {
        $out = [];
        foreach (array_slice(array_reverse(self::log_get()), 0, 12) as $e) {
            $out[] = ['id' => $e['id'], 'name' => $e['name'], 'folderId' => $e['folder'], 'folder' => self::folder_name($e['folder']), 'by' => $e['user'], 'at' => $e['at'], 'view' => 'https://drive.google.com/file/d/' . $e['id'] . '/view'];
        }
        return rest_ensure_response(['items' => array_values(array_filter($out, static fn($x) => $x['folder'] !== ''))]);
    }

    public static function search(WP_REST_Request $r) {
        $q = trim((string) $r->get_param('q'));
        $s = self::state();
        if (mb_strlen($q) < 2 || empty($s['folders'])) return rest_ensure_response(['files' => []]);
        $par = implode(' or ', array_map(static fn($f) => "'" . esc_sql($f['id']) . "' in parents", $s['folders']));
        $res = vibes_drive_api('GET', 'files', null, ['q' => "name contains '" . addcslashes($q, "'\\") . "' and trashed = false and (" . $par . ")", 'pageSize' => 40, 'orderBy' => 'name', 'fields' => 'files(id,name,mimeType,size,modifiedTime,webViewLink,thumbnailLink,parents)']);
        if (is_wp_error($res)) return self::err($res);
        $out = [];
        foreach (($res['files'] ?? []) as $f) { $out[] = self::shape($f, self::folder_name((string) ($f['parents'][0] ?? ''))); }
        return rest_ensure_response(['files' => $out]);
    }

    private static function shape(array $f, string $folder = ''): array {
        $dir = ($f['mimeType'] ?? '') === self::FOLDER; $log = [];
        foreach (self::log_get() as $e) $log[$e['id']] = $e;
        return [
            'id' => $f['id'], 'name' => $f['name'], 'folder' => $dir, 'mime' => $f['mimeType'] ?? '', 'size' => (int) ($f['size'] ?? 0),
            'modified' => $f['modifiedTime'] ?? '', 'view' => $f['webViewLink'] ?? '',
            'download' => $dir ? '' : 'https://drive.google.com/uc?export=download&id=' . $f['id'],
            'preview' => $dir ? '' : 'https://drive.google.com/file/d/' . $f['id'] . '/preview',
            'thumb' => $f['thumbnailLink'] ?? '', 'by' => $log[$f['id']]['user'] ?? '', 'inFolder' => $folder,
        ];
    }

    public static function index() {
        $s = self::state();
        return rest_ensure_response([
            'admin' => self::admin(),
            'ready' => !empty($s['root_id']),
            'drive' => self::drive_ok() && function_exists('vibes_drive_access_token') && !is_wp_error(vibes_drive_access_token()),
            'rootUrl' => !empty($s['root_id']) ? self::url($s['root_id']) : '',
            'folders' => array_values(array_map(static fn($f) => ['id' => $f['id'], 'name' => $f['name'], 'url' => self::url($f['id'])], $s['folders'] ?? [])),
        ]);
    }

    public static function setup() {
        if (!self::drive_ok()) return new WP_Error('vpm_library', 'Google Drive is not connected.', ['status' => 400]);
        $s = self::state();
        if (empty($s['root_id'])) {
            $settings = function_exists('vibes_drive_settings') ? vibes_drive_settings() : [];
            $parent = (string) ($settings['root_folder_id'] ?? '');
            if (!$parent) return new WP_Error('vpm_library', 'The portal Drive root folder is not configured.', ['status' => 400]);
            $found = vibes_drive_find_child($parent, 'Library');
            if (is_wp_error($found)) return self::err($found);
            if (empty($found['id'])) { $found = vibes_drive_create_folder('Library', $parent); if (is_wp_error($found)) return self::err($found); }
            $s['root_id'] = $found['id'];
            $s['folders'] = [];
            $r = self::share($s['root_id']); if (is_wp_error($r)) return self::err($r);
        }
        $have = array_column($s['folders'] ?? [], 'name');
        $existing = vibes_drive_list_children($s['root_id']);
        if (is_wp_error($existing)) return self::err($existing);
        $by = []; foreach ($existing as $c) if (($c['mimeType'] ?? '') === self::FOLDER) $by[$c['name']] = $c['id'];
        foreach (self::DEFAULTS as $name) {
            if (in_array($name, $have, true)) continue;
            $id = $by[$name] ?? '';
            if (!$id) { $made = vibes_drive_create_folder($name, $s['root_id']); if (is_wp_error($made)) { self::save($s); return self::err($made); } $id = $made['id']; }
            $s['folders'][] = ['id' => $id, 'name' => $name];
        }
        self::save($s);
        VPM_Permissions::audit('library', 'setup', 'library', 0, ['root' => $s['root_id']]);
        return self::index();
    }

    public static function add_folder(WP_REST_Request $r) {
        $s = self::state();
        if (empty($s['root_id'])) return new WP_Error('vpm_library', 'Set up the Library first.', ['status' => 400]);
        $name = trim(wp_strip_all_tags((string) $r->get_param('name')));
        $name = trim(preg_replace('#[\\\\/]+#', ' ', $name));
        if ($name === '' || mb_strlen($name) > 60) return new WP_Error('vpm_library', 'Give the folder a name (up to 60 characters).', ['status' => 400]);
        foreach ($s['folders'] as $f) if (mb_strtolower($f['name']) === mb_strtolower($name)) return new WP_Error('vpm_library', 'A folder with that name already exists.', ['status' => 409]);
        $made = vibes_drive_create_folder($name, $s['root_id']);
        if (is_wp_error($made)) return self::err($made);
        $s['folders'][] = ['id' => $made['id'], 'name' => $name];
        self::save($s);
        VPM_Permissions::audit('library', 'folder_added', 'library', 0, ['name' => $name]);
        return self::index();
    }

    public static function remove_folder(WP_REST_Request $r) {
        $id = (string) $r['id']; $s = self::state();
        if (!self::known($id)) return new WP_Error('vpm_library', 'Folder not found.', ['status' => 404]);
        $res = vibes_drive_api('PATCH', 'files/' . rawurlencode($id), ['trashed' => true], ['supportsAllDrives' => 'true']);
        if (is_wp_error($res)) return self::err($res);
        $name = '';
        $s['folders'] = array_values(array_filter($s['folders'], static function ($f) use ($id, &$name) { if ($f['id'] === $id) { $name = $f['name']; return false; } return true; }));
        self::save($s);
        VPM_Permissions::audit('library', 'folder_removed', 'library', 0, ['name' => $name]);
        return self::index();
    }

    public static function files(WP_REST_Request $r) {
        $id = (string) $r['id'];
        if (!self::known($id)) return new WP_Error('vpm_library', 'Folder not found.', ['status' => 404]);
        $res = vibes_drive_api('GET', 'files', null, [
            'q' => sprintf("'%s' in parents and trashed = false", esc_sql($id)), 'pageSize' => 200, 'orderBy' => 'folder,name',
            'fields' => 'files(id,name,mimeType,size,modifiedTime,webViewLink,thumbnailLink)',
        ]);
        if (is_wp_error($res)) return self::err($res);
        $out = [];
        foreach (($res['files'] ?? []) as $f) $out[] = self::shape($f);
        return rest_ensure_response(['files' => $out, 'folderUrl' => self::url($id)]);
    }

    public static function upload(WP_REST_Request $r) {
        $id = (string) $r['id'];
        if (!self::known($id)) return new WP_Error('vpm_library', 'Folder not found.', ['status' => 404]);
        $files = $r->get_file_params();
        $f = $files['file'] ?? null;
        if (!$f || !empty($f['error']) || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) return new WP_Error('vpm_library', 'The file did not upload. It may be larger than the server allows.', ['status' => 400]);
        $name = sanitize_file_name((string) ($f['name'] ?? 'file'));
        if (preg_match('/\.(php\d?|phtml|phar|exe|bat|cmd|sh|js|html?)$/i', $name)) return new WP_Error('vpm_library', 'That file type is not allowed.', ['status' => 400]);
        $bytes = file_get_contents($f['tmp_name']);
        $mime = (string) ($f['type'] ?: 'application/octet-stream');
        $res = vibes_drive_upload_raw($name, $bytes, $mime, $id);
        if (is_wp_error($res)) return self::err($res);
        VPM_Permissions::audit('library', 'file_uploaded', 'library', 0, ['name' => $name, 'folder' => $id]);
        $log = self::log_get(); $u = wp_get_current_user();
        $log[] = ['id' => (string) ($res['id'] ?? ''), 'name' => (string) ($res['name'] ?? $name), 'folder' => $id, 'user' => $u->display_name ?: $u->user_login, 'at' => gmdate('c')];
        update_option('vpm_library_log', array_slice($log, -300), false);
        return rest_ensure_response(['ok' => true, 'id' => $res['id'] ?? '', 'name' => $res['name'] ?? $name]);
    }

    public static function delete_file(WP_REST_Request $r) {
        $id = (string) $r['id'];
        $meta = vibes_drive_api('GET', 'files/' . rawurlencode($id), null, ['fields' => 'id,name,parents', 'supportsAllDrives' => 'true']);
        if (is_wp_error($meta)) return self::err($meta);
        $ok = false; foreach (($meta['parents'] ?? []) as $p) if (self::known($p)) $ok = true;
        if (!$ok) return new WP_Error('vpm_library', 'That file is not directly inside a Library folder.', ['status' => 403]);
        $res = vibes_drive_api('PATCH', 'files/' . rawurlencode($id), ['trashed' => true], ['supportsAllDrives' => 'true']);
        if (is_wp_error($res)) return self::err($res);
        VPM_Permissions::audit('library', 'file_trashed', 'library', 0, ['name' => $meta['name'] ?? '']);
        return rest_ensure_response(['ok' => true]);
    }
}
VPM_Library::boot();

/* ------------------------------------------------------------------------
 * Extras: global search, test push, health check, invoice overdue
 * automation and weekly off-site code backup to Google Drive.
 * ---------------------------------------------------------------------- */
final class VPM_Extras {
    const NS = 'vibes/v1';
    const DIRS = ['vibes-team-portal', 'vibes-portal-modules', 'vibes-drive-automation', 'vibes-portal-enhancements-v3', 'vibes-video-library'];

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('rest_api_init', [self::class, 'maybe_overdue'], 20);
        add_action('init', static function () { if (!wp_next_scheduled('vpm_weekly_backup')) wp_schedule_event(time() + 3600, 'weekly', 'vpm_weekly_backup'); });
        add_action('vpm_weekly_backup', [self::class, 'backup']);
    }

    public static function routes(): void {
        $in = static function () { return is_user_logged_in(); };
        $admin = static function () { return current_user_can('manage_options'); };
        register_rest_route(self::NS, '/search', ['methods' => 'GET', 'callback' => [self::class, 'search'], 'permission_callback' => $in]);
        register_rest_route(self::NS, '/connect/push-test', ['methods' => 'POST', 'callback' => [self::class, 'push_test'], 'permission_callback' => $in]);
        register_rest_route(self::NS, '/health', ['methods' => 'GET', 'callback' => [self::class, 'health'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/backup', ['methods' => 'GET', 'callback' => [self::class, 'backup_status'], 'permission_callback' => $admin]);
        register_rest_route(self::NS, '/backup/run', ['methods' => 'POST', 'callback' => [self::class, 'backup_run'], 'permission_callback' => $admin]);
    }

    private static function t(string $n): string { global $wpdb; return $wpdb->prefix . 'vtp_' . $n; }

    /* ---------- global search ---------- */
    public static function search(WP_REST_Request $r) {
        global $wpdb;
        $q = trim((string) $r->get_param('q'));
        if (mb_strlen($q) < 2) return rest_ensure_response(['groups' => []]);
        $like = '%' . $wpdb->esc_like($q) . '%'; $uid = get_current_user_id();
        $tasks_all = current_user_can('vtp_manage_tasks'); $sales = current_user_can('vtp_manage_sales');
        $g = [];
        $add = static function (string $label, array $items) use (&$g) { if ($items) $g[] = ['label' => $label, 'items' => $items]; };
        // Projects
        $pw = $tasks_all ? '1=1' : $wpdb->prepare('p.id IN (SELECT project_id FROM ' . self::t('tasks') . ' WHERE assignee_id=%d)', $uid);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT p.name,p.client,p.status FROM ' . self::t('projects') . " p WHERE $pw AND (p.name LIKE %s OR p.client LIKE %s) ORDER BY p.updated_at DESC LIMIT 6", $like, $like), ARRAY_A) ?: [];
        $add('Projects', array_map(static fn($x) => ['title' => $x['name'], 'sub' => trim(($x['client'] ?: '') . ' · ' . $x['status'], ' ·'), 'go' => '#projects'], $rows));
        // Tasks
        $tw = $tasks_all ? '1=1' : $wpdb->prepare('t.assignee_id=%d', $uid);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT t.title,t.status,p.name pn FROM ' . self::t('tasks') . ' t LEFT JOIN ' . self::t('projects') . " p ON p.id=t.project_id WHERE $tw AND t.title LIKE %s ORDER BY t.updated_at DESC LIMIT 6", $like), ARRAY_A) ?: [];
        $add('Tasks', array_map(static fn($x) => ['title' => $x['title'], 'sub' => trim(($x['pn'] ?: '') . ' · ' . $x['status'], ' ·'), 'go' => '#tasks'], $rows));
        // People
        $us = get_users(['search' => '*' . $q . '*', 'search_columns' => ['display_name', 'user_login', 'user_email'], 'number' => 5, 'fields' => ['ID', 'display_name']]);
        $add('People', array_map(static fn($u) => ['title' => $u->display_name, 'sub' => (string) get_user_meta($u->ID, 'vtp_position', true), 'go' => '#directory'], $us));
        // Clients (Files & Brand)
        $fb = get_option('vtp_files_brand', []); $cl = [];
        foreach ((array) ($fb['clients'] ?? []) as $c) if (!empty($c['name']) && stripos($c['name'], $q) !== false) $cl[] = ['title' => $c['name'], 'sub' => (string) ($c['location'] ?? 'Client folder'), 'go' => '#files'];
        $add('Clients', array_slice($cl, 0, 5));
        if ($sales) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT request_number,client_name,company,status FROM ' . self::t('inquiries') . ' WHERE client_name LIKE %s OR company LIKE %s OR request_number LIKE %s ORDER BY id DESC LIMIT 5', $like, $like, $like), ARRAY_A) ?: [];
            $add('Inquiries', array_map(static fn($x) => ['title' => $x['request_number'] . ' · ' . ($x['company'] ?: $x['client_name']), 'sub' => $x['status'], 'go' => '#inquiries'], $rows));
            $rows = $wpdb->get_results($wpdb->prepare('SELECT quote_number,client_name,company,status,total,currency FROM ' . self::t('quotations') . ' WHERE parent_quotation_id IS NULL AND (client_name LIKE %s OR company LIKE %s OR quote_number LIKE %s) ORDER BY id DESC LIMIT 5', $like, $like, $like), ARRAY_A) ?: [];
            $add('Quotations', array_map(static fn($x) => ['title' => $x['quote_number'] . ' · ' . ($x['company'] ?: $x['client_name']), 'sub' => $x['status'] . ' · ' . $x['currency'] . ' ' . number_format((float) $x['total'], 2), 'go' => '#quotations'], $rows));
            $rows = $wpdb->get_results($wpdb->prepare('SELECT invoice_number,client_name,company,status,total,currency FROM ' . self::t('invoices') . ' WHERE client_name LIKE %s OR company LIKE %s OR invoice_number LIKE %s ORDER BY id DESC LIMIT 5', $like, $like, $like), ARRAY_A) ?: [];
            $add('Invoices', array_map(static fn($x) => ['title' => $x['invoice_number'] . ' · ' . ($x['company'] ?: $x['client_name']), 'sub' => $x['status'] . ' · ' . $x['currency'] . ' ' . number_format((float) $x['total'], 2), 'go' => '#invoices'], $rows));
        }
        // Library files
        if (class_exists('VPM_Library')) {
            $lib = VPM_Library::search($r);
            if (!is_wp_error($lib)) { $d = $lib->get_data(); $add('Library', array_map(static fn($f) => ['title' => $f['name'], 'sub' => $f['inFolder'], 'url' => $f['view']], array_slice($d['files'] ?? [], 0, 6))); }
        }
        return rest_ensure_response(['groups' => $g]);
    }

    /* ---------- test push ---------- */
    public static function push_test() {
        $uid = get_current_user_id();
        $subs = (array) get_user_meta($uid, 'vpm_push_subs', true);
        $subs = array_filter($subs, 'is_array');
        if (!$subs) return rest_ensure_response(['devices' => 0, 'message' => 'No device is registered for notifications yet. Open the portal from your home-screen app, allow notifications when asked, then try again.']);
        VPM_Push::send_user($uid, ['title' => 'Vibes test notification', 'body' => 'If you can read this on your phone, notifications are working.', 'url' => '/team-portal/', 'tag' => 'vibes-test-' . time()]);
        return rest_ensure_response(['devices' => count($subs), 'message' => 'Test sent to ' . count($subs) . ' device' . (count($subs) > 1 ? 's' : '') . '. It should arrive in a few seconds.']);
    }

    /* ---------- health check for uptime monitors ---------- */
    public static function health() {
        global $wpdb; $ok = (bool) $wpdb->get_var('SELECT 1');
        $drive = function_exists('vibes_drive_access_token') ? !is_wp_error(vibes_drive_access_token()) : null;
        $res = rest_ensure_response(['ok' => $ok, 'time' => gmdate('c'), 'drive' => $drive]);
        $res->set_status($ok ? 200 : 503); $res->header('Cache-Control', 'no-store');
        return $res;
    }

    /* ---------- invoice overdue automation ---------- */
    public static function maybe_overdue(): void {
        if (!is_user_logged_in() || !current_user_can('vtp_manage_sales')) return;
        if (get_transient('vpm_overdue_checked')) return;
        set_transient('vpm_overdue_checked', 1, HOUR_IN_SECONDS);
        self::overdue_sweep();
    }

    public static function overdue_sweep(): int {
        global $wpdb; $T = self::t('invoices');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $T)) !== $T) return 0;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,invoice_number,client_name,company,total,currency,due_date FROM {$T} WHERE status='sent' AND due_date IS NOT NULL AND due_date < %s", current_time('Y-m-d')), ARRAY_A) ?: [];
        $admins = get_users(['role' => 'administrator', 'fields' => 'ID']);
        foreach ($rows as $inv) {
            $wpdb->update($T, ['status' => 'overdue', 'updated_at' => current_time('mysql')], ['id' => $inv['id']]);
            foreach ($admins as $a) {
                $wpdb->insert(self::t('notifications'), ['user_id' => $a, 'type' => 'invoice_overdue', 'title' => 'Invoice ' . $inv['invoice_number'] . ' is overdue',
                    'body' => ($inv['company'] ?: $inv['client_name']) . ' · ' . $inv['currency'] . ' ' . number_format((float) $inv['total'], 2) . ' · was due ' . $inv['due_date'],
                    'object_type' => 'invoice', 'object_id' => $inv['id'], 'created_at' => current_time('mysql')]);
            }
        }
        return count($rows);
    }

    /* ---------- off-site code backup (Google Drive) ---------- */
    public static function backup_status() { return rest_ensure_response(self::bstate()); }
    private static function bstate(): array { $s = get_option('vpm_backup', []); $s = is_array($s) ? $s : []; $s['zip'] = class_exists('ZipArchive'); $s['drive'] = function_exists('vibes_drive_api'); return $s; }
    public static function backup_run() { $r = self::backup(); return is_wp_error($r) ? $r : rest_ensure_response(self::bstate()); }

    public static function backup() {
        if (!class_exists('ZipArchive')) return self::bfail('The server has no zip support.');
        if (!function_exists('vibes_drive_api')) return self::bfail('Google Drive is not connected.');
        @set_time_limit(120);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = wp_tempnam('vibes-backup.zip'); $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return self::bfail('Could not create the archive.');
        $n = 0;
        foreach (self::DIRS as $d) {
            $base = WP_PLUGIN_DIR . '/' . $d; if (!is_dir($base)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) { if (!$f->isFile() || $f->getSize() > 20 * 1048576 || preg_match('/\.(zip|log)$/i', $f->getFilename())) continue; $zip->addFile($f->getPathname(), $d . '/' . substr($f->getPathname(), strlen($base) + 1)); $n++; }
        }
        $zip->close();
        $bytes = file_get_contents($tmp); @unlink($tmp);
        if ($bytes === false || !$n) return self::bfail('Nothing to back up.');
        $settings = function_exists('vibes_drive_settings') ? vibes_drive_settings() : [];
        $root = (string) ($settings['root_folder_id'] ?? ''); if (!$root) return self::bfail('Drive root folder missing.');
        $st = get_option('vpm_backup', []); $fid = (string) ($st['folder'] ?? '');
        if (!$fid) {
            $found = vibes_drive_find_child($root, 'Vibes Backups');
            if (is_wp_error($found)) return self::bfail('Drive: ' . $found->get_error_message());
            if (empty($found['id'])) { $found = vibes_drive_create_folder('Vibes Backups', $root); if (is_wp_error($found)) return self::bfail('Drive: ' . $found->get_error_message()); }
            $fid = $found['id'];
        }
        $name = 'vibes-portal-code-' . wp_date('Y-m-d-His') . '.zip';
        $up = vibes_drive_upload_raw($name, $bytes, 'application/zip', $fid);
        if (is_wp_error($up)) return self::bfail('Upload failed: ' . $up->get_error_message());
        // keep the newest 8 archives
        $ls = vibes_drive_api('GET', 'files', null, ['q' => sprintf("'%s' in parents and trashed = false and name contains 'vibes-portal-code-'", esc_sql($fid)), 'orderBy' => 'createdTime desc', 'pageSize' => 100, 'fields' => 'files(id)']);
        if (!is_wp_error($ls)) foreach (array_slice($ls['files'] ?? [], 8) as $old) vibes_drive_api('PATCH', 'files/' . rawurlencode($old['id']), ['trashed' => true]);
        update_option('vpm_backup', ['ok' => true, 'at' => gmdate('c'), 'name' => $name, 'size' => strlen($bytes), 'files' => $n, 'folder' => $fid, 'url' => 'https://drive.google.com/drive/folders/' . $fid], false);
        return true;
    }
    private static function bfail(string $m) {
        $st = get_option('vpm_backup', []); $st = is_array($st) ? $st : [];
        update_option('vpm_backup', array_merge($st, ['ok' => false, 'error' => $m, 'failed_at' => gmdate('c')]), false);
        return new WP_Error('vpm_backup', $m, ['status' => 500]);
    }
}
VPM_Extras::boot();

/* ------------------------------------------------------------------------
 * Announcement reactions (emoji) and comments.
 * ---------------------------------------------------------------------- */
final class VPM_Social {
    const NS = 'vibes/v1';
    const EMOJI = ['👍', '❤️', '🎉', '🔥', '👏', '😂', '😮', '🙏', '💯', '✅', '👀', '🚀'];

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        // Deleting an announcement also removes its photo, comments and reactions.
        add_filter('rest_post_dispatch', static function ($res, $srv, $req) {
            if ($req->get_method() === 'DELETE' && preg_match('#^/vibes/v1/announcements/(\d+)$#', $req->get_route(), $m) && $res instanceof WP_REST_Response && $res->get_status() < 300) {
                global $wpdb; $id = (int) $m[1]; self::ensure();
                $wpdb->delete(self::t('vpm_ann_comments'), ['announcement_id' => $id]); $wpdb->delete(self::t('vpm_ann_reactions'), ['announcement_id' => $id]); self::drop_photo($id);
            }
            return $res;
        }, 10, 3);
    }

    private static function photos(): array { $p = get_option('vpm_ann_photos', []); return is_array($p) ? $p : []; }
    private static function drop_photo(int $id): void { $p = self::photos(); if (!empty($p[$id])) { wp_delete_attachment((int) $p[$id], true); unset($p[$id]); update_option('vpm_ann_photos', $p, false); } }

    public static function photo_set(WP_REST_Request $r) {
        $id = (int) $r['id']; $a = self::ann($id); if (is_wp_error($a)) return $a;
        $f = $r->get_file_params()['photo'] ?? null;
        if (!$f || !empty($f['error']) || empty($f['tmp_name'])) return new WP_Error('vpm_social', 'The photo did not upload.', ['status' => 400]);
        if ($f['size'] > 8 * 1048576) return new WP_Error('vpm_social', 'The photo is larger than 8 MB.', ['status' => 400]);
        $ft = wp_check_filetype_and_ext($f['tmp_name'], $f['name']);
        if (!in_array($ft['type'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return new WP_Error('vpm_social', 'Please choose a JPG, PNG, WebP or GIF image.', ['status' => 400]);
        require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/image.php'; require_once ABSPATH . 'wp-admin/includes/media.php';
        $_FILES['photo'] = $f;
        $att = media_handle_upload('photo', 0, ['post_title' => 'Announcement photo ' . $id], ['test_form' => false]);
        if (is_wp_error($att)) return new WP_Error('vpm_social', $att->get_error_message(), ['status' => 500]);
        self::drop_photo($id);
        $p = self::photos(); $p[$id] = (int) $att; update_option('vpm_ann_photos', $p, false);
        return rest_ensure_response(self::bundle([$id])[$id]);
    }

    public static function photo_del(WP_REST_Request $r) { $id = (int) $r['id']; self::drop_photo($id); return rest_ensure_response(self::bundle([$id])[$id]); }

    private static function t(string $n): string { global $wpdb; return $wpdb->prefix . $n; }

    private static function ensure(): void {
        if (get_option('vpm_social_db') === '2') return;
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::t('vpm_ann_comments') . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, announcement_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY ann (announcement_id)) $c;");
        dbDelta('CREATE TABLE ' . self::t('vpm_ann_reactions') . " (announcement_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, emoji VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (announcement_id, user_id, emoji), KEY ann (announcement_id)) $c;");
        update_option('vpm_social_db', '2', false);
    }

    public static function routes(): void {
        $in = static fn() => is_user_logged_in();
        register_rest_route(self::NS, '/announcements/social', ['methods' => 'GET', 'callback' => [self::class, 'all'], 'permission_callback' => $in]);
        register_rest_route(self::NS, '/announcements/(?P<id>\d+)/react', ['methods' => 'POST', 'callback' => [self::class, 'react'], 'permission_callback' => $in]);
        register_rest_route(self::NS, '/announcements/(?P<id>\d+)/comments', ['methods' => 'POST', 'callback' => [self::class, 'comment'], 'permission_callback' => $in]);
        $mg = static fn() => current_user_can('vtp_manage_announcements');
        register_rest_route(self::NS, '/announcements/(?P<id>\d+)/photo', [
            ['methods' => 'POST', 'callback' => [self::class, 'photo_set'], 'permission_callback' => $mg],
            ['methods' => 'DELETE', 'callback' => [self::class, 'photo_del'], 'permission_callback' => $mg],
        ]);
        register_rest_route(self::NS, '/announcements/(?P<id>\d+)/comments/(?P<cid>\d+)', ['methods' => 'DELETE', 'callback' => [self::class, 'remove'], 'permission_callback' => $in]);
    }

    private static function ann(int $id) {
        global $wpdb;
        $manage = current_user_can('vtp_manage_announcements');
        $row = $wpdb->get_row($wpdb->prepare('SELECT id,title,created_by,publish_at,archived_at FROM ' . self::t('vtp_announcements') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$row || $row['archived_at'] || (!$manage && $row['publish_at'] && strtotime($row['publish_at']) > current_time('timestamp'))) return new WP_Error('vpm_social', 'Announcement not found.', ['status' => 404]);
        return $row;
    }

    private static function bundle(array $ids): array {
        global $wpdb; self::ensure(); $uid = get_current_user_id(); $out = [];
        $ph = self::photos();
        foreach ($ids as $i) { $u = !empty($ph[$i]) ? wp_get_attachment_image_url((int) $ph[$i], 'large') : ''; $out[$i] = ['reactions' => [], 'comments' => [], 'photo' => $u ?: '']; }
        if (!$ids) return $out;
        $in = implode(',', array_map('intval', $ids));
        foreach ($wpdb->get_results('SELECT r.announcement_id a, r.emoji e, r.user_id u, d.display_name n FROM ' . self::t('vpm_ann_reactions') . " r LEFT JOIN {$wpdb->users} d ON d.ID=r.user_id WHERE r.announcement_id IN ($in) ORDER BY r.created_at", ARRAY_A) ?: [] as $r) {
            $a = (int) $r['a']; $e = $r['e'];
            $out[$a]['reactions'][$e] = $out[$a]['reactions'][$e] ?? ['emoji' => $e, 'count' => 0, 'mine' => false, 'names' => []];
            $out[$a]['reactions'][$e]['count']++; if ((int) $r['u'] === $uid) $out[$a]['reactions'][$e]['mine'] = true;
            if (count($out[$a]['reactions'][$e]['names']) < 8) $out[$a]['reactions'][$e]['names'][] = $r['n'];
        }
        foreach ($wpdb->get_results('SELECT c.id,c.announcement_id a,c.user_id u,c.body,c.created_at,d.display_name n FROM ' . self::t('vpm_ann_comments') . " c LEFT JOIN {$wpdb->users} d ON d.ID=c.user_id WHERE c.announcement_id IN ($in) ORDER BY c.id", ARRAY_A) ?: [] as $c) {
            $out[(int) $c['a']]['comments'][] = ['id' => (int) $c['id'], 'name' => $c['n'] ?: 'Team member', 'body' => $c['body'], 'at' => mysql2date('c', $c['created_at'], false), 'mine' => (int) $c['u'] === $uid];
        }
        foreach ($out as $k => $v) $out[$k]['reactions'] = array_values($v['reactions']);
        return $out;
    }

    public static function all() {
        global $wpdb; self::ensure();
        $manage = current_user_can('vtp_manage_announcements');
        $vis = $manage ? '' : ' AND (publish_at IS NULL OR publish_at <= NOW())';
        $ids = array_map('intval', $wpdb->get_col('SELECT id FROM ' . self::t('vtp_announcements') . ' WHERE archived_at IS NULL' . $vis));
        return rest_ensure_response(['items' => self::bundle($ids), 'emoji' => self::EMOJI]);
    }

    public static function react(WP_REST_Request $r) {
        global $wpdb; self::ensure(); $id = (int) $r['id']; $a = self::ann($id); if (is_wp_error($a)) return $a;
        $emoji = (string) $r->get_param('emoji');
        if (!in_array($emoji, self::EMOJI, true)) return new WP_Error('vpm_social', 'That emoji is not available.', ['status' => 400]);
        $uid = get_current_user_id(); $T = self::t('vpm_ann_reactions');
        $has = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM $T WHERE announcement_id=%d AND user_id=%d AND emoji=%s", $id, $uid, $emoji));
        if ($has) $wpdb->delete($T, ['announcement_id' => $id, 'user_id' => $uid, 'emoji' => $emoji]);
        else $wpdb->insert($T, ['announcement_id' => $id, 'user_id' => $uid, 'emoji' => $emoji, 'created_at' => current_time('mysql')]);
        return rest_ensure_response(self::bundle([$id])[$id]);
    }

    public static function comment(WP_REST_Request $r) {
        global $wpdb; self::ensure(); $id = (int) $r['id']; $a = self::ann($id); if (is_wp_error($a)) return $a;
        $body = trim(wp_strip_all_tags((string) $r->get_param('body')));
        if ($body === '' || mb_strlen($body) > 1000) return new WP_Error('vpm_social', 'Write a comment (up to 1000 characters).', ['status' => 400]);
        $uid = get_current_user_id();
        $wpdb->insert(self::t('vpm_ann_comments'), ['announcement_id' => $id, 'user_id' => $uid, 'body' => $body, 'created_at' => current_time('mysql')]);
        $me = wp_get_current_user(); $author = (int) $a['created_by'];
        if ($author && $author !== $uid) {
            $wpdb->insert(self::t('vtp_notifications'), ['user_id' => $author, 'type' => 'announcement_comment', 'title' => ($me->display_name ?: 'Someone') . ' commented on “' . mb_strimwidth($a['title'], 0, 60, '…') . '”',
                'body' => mb_strimwidth($body, 0, 120, '…'), 'object_type' => 'announcement', 'object_id' => $id, 'created_at' => current_time('mysql')]);
        }
        return rest_ensure_response(self::bundle([$id])[$id]);
    }

    public static function remove(WP_REST_Request $r) {
        global $wpdb; self::ensure(); $id = (int) $r['id']; $cid = (int) $r['cid']; $T = self::t('vpm_ann_comments');
        $c = $wpdb->get_row($wpdb->prepare("SELECT user_id FROM $T WHERE id=%d AND announcement_id=%d", $cid, $id), ARRAY_A);
        if (!$c) return new WP_Error('vpm_social', 'Comment not found.', ['status' => 404]);
        if ((int) $c['user_id'] !== get_current_user_id() && !current_user_can('vtp_manage_announcements')) return new WP_Error('vpm_social', 'You can only delete your own comments.', ['status' => 403]);
        $wpdb->delete($T, ['id' => $cid]);
        return rest_ensure_response(self::bundle([$id])[$id]);
    }
}
VPM_Social::boot();

/* ------------------------------------------------------------------------
 * Reporting manager: stored per employee in user meta, validated before the
 * core employee endpoints save, and added to the employee payloads.
 * ---------------------------------------------------------------------- */
if (!function_exists('vpm_reporting_roles')) {
    function vpm_reporting_roles(): array { return ['administrator', 'vibes_manager', 'vibes_employee']; }

    function vpm_reporting_manager_of(int $uid): int {
        $id = (int) get_user_meta($uid, 'vtp_reporting_manager', true);
        if (!$id) return 0;
        $m = get_userdata($id);
        return ($m && array_intersect($m->roles, vpm_reporting_roles())) ? $id : 0;
    }

    add_filter('rest_request_before_callbacks', static function ($response, $handler, $request) {
        if (!($request instanceof WP_REST_Request) || $request->get_method() !== 'POST') return $response;
        $route = $request->get_route();
        $is_create = $route === '/vibes/v1/employees';
        $is_update = (bool) preg_match('#^/vibes/v1/employees/(\d+)$#', $route, $m);
        if (!$is_create && !$is_update) return $response;
        if (!$request->has_param('reportingManager')) return $response;
        $mid = (int) $request->get_param('reportingManager');
        if (!$mid) return $response;
        $target = $is_update ? (int) $m[1] : 0;
        $mgr = get_userdata($mid);
        if (!$mgr || !array_intersect($mgr->roles, vpm_reporting_roles()) || get_user_meta($mid, 'vtp_disabled', true)) {
            return new WP_Error('vpm_reporting', 'Choose an active team member as the reporting manager.', ['status' => 400]);
        }
        if ($target) {
            $tu = get_userdata($target);
            if ($tu && in_array('administrator', $tu->roles, true)) return new WP_Error('vpm_reporting', 'The admin does not report to anyone.', ['status' => 400]);
            if ($mid === $target) return new WP_Error('vpm_reporting', 'An employee cannot report to themselves.', ['status' => 400]);
            for ($up = $mid, $n = 0; $up && $n < 20; $up = vpm_reporting_manager_of($up), $n++) {
                if ($up === $target) return new WP_Error('vpm_reporting', 'That would create a reporting loop. Choose a different manager.', ['status' => 400]);
            }
        }
        return $response;
    }, 10, 3);

    add_filter('rest_post_dispatch', static function ($result, $server, $request) {
        if (!($result instanceof WP_REST_Response) || !($request instanceof WP_REST_Request)) return $result;
        $route = $request->get_route();
        $method = $request->get_method();
        $attach = static function (array $u): array {
            $uid = (int) ($u['id'] ?? 0);
            $isAdmin = ($u['role'] ?? '') === 'admin';
            $mid = ($uid && !$isAdmin) ? vpm_reporting_manager_of($uid) : 0;
            $u['reportingManagerId'] = $mid;
            $u['reportingManagerName'] = $mid ? (string) get_userdata($mid)->display_name : '';
            return $u;
        };
        if ($method === 'GET' && $route === '/vibes/v1/employees') {
            $data = $result->get_data();
            if (is_array($data)) { $result->set_data(array_map(static fn($u) => is_array($u) ? $attach($u) : $u, $data)); }
            return $result;
        }
        $is_create = $method === 'POST' && $route === '/vibes/v1/employees';
        $is_update = $method === 'POST' && preg_match('#^/vibes/v1/employees/(\d+)$#', $route, $m);
        if (($is_create || $is_update) && $request->has_param('reportingManager') && $result->get_status() < 300) {
            $data = $result->get_data();
            $uid = $is_update ? (int) $m[1] : (int) ($data['employee']['id'] ?? 0);
            $u = $uid ? get_userdata($uid) : null;
            if ($u && !in_array('administrator', $u->roles, true)) {
                $mid = (int) $request->get_param('reportingManager');
                if ($mid) update_user_meta($uid, 'vtp_reporting_manager', $mid); else delete_user_meta($uid, 'vtp_reporting_manager');
            }
        }
        return $result;
    }, 20, 3);
}

/* ------------------------------------------------------------------------
 * Working hours and automatic attendance: each person has their own working
 * hours and days (set by the admin). The portal records, on its own, when
 * they are online during those hours. Nothing is entered by hand.
 * ---------------------------------------------------------------------- */
final class VPM_Presence {
    const TZ = 'Asia/Beirut';
    const SCHEMA = 2;

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('rest_request_before_callbacks', [self::class, 'validate_schedule'], 11, 3);
        add_filter('rest_post_dispatch', [self::class, 'schedule_dispatch'], 21, 3);
    }

    private static function t(): string { global $wpdb; return $wpdb->prefix . 'vpm_attendance'; }

    private static function ensure(): void {
        static $done = false; if ($done) return; $done = true;
        if ((int) get_option('vpm_att_schema') === self::SCHEMA) return;
        global $wpdb; $T = self::t();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $T (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            work_date date NOT NULL,
            start_at datetime NOT NULL,
            end_at datetime DEFAULT NULL,
            break_started_at datetime DEFAULT NULL,
            break_seconds int(10) unsigned NOT NULL DEFAULT 0,
            status varchar(12) NOT NULL DEFAULT 'working',
            plan_note text,
            done_note text,
            auto tinyint(1) NOT NULL DEFAULT 0,
            online_seconds int(10) unsigned NOT NULL DEFAULT 0,
            active_seconds int(10) unsigned NOT NULL DEFAULT 0,
            sched_start varchar(5) NOT NULL DEFAULT '',
            sched_end varchar(5) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY user_day (user_id,work_date)
        ) " . $wpdb->get_charset_collate() . ';');
        if (!get_option('vpm_att_v2_clean')) { $wpdb->query("DELETE FROM $T"); update_option('vpm_att_v2_clean', 1); } // earlier entries were tests of the manual clock
        update_option('vpm_att_schema', self::SCHEMA);
    }

    private static function local_now(): DateTime { return new DateTime('now', new DateTimeZone(self::TZ)); }
    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function iso($v) { return $v ? str_replace(' ', 'T', $v) . 'Z' : null; }

    public static function member(): bool {
        if (!is_user_logged_in()) return false;
        return (bool) array_intersect(wp_get_current_user()->roles, ['administrator', 'vibes_manager', 'vibes_employee']);
    }
    private static function manager(): bool { return current_user_can('vtp_manage_employees'); }

    public static function routes(): void {
        $ns = 'vibes/v1';
        foreach (['ping' => 'POST', 'team' => 'GET', 'report' => 'GET', 'summary' => 'GET'] as $path => $method) {
            register_rest_route($ns, '/presence/' . $path, ['methods' => $method, 'callback' => [self::class, $path], 'permission_callback' => [self::class, 'member']]);
        }
    }

    /** The working hours set by the admin: ['start' => 'HH:MM', 'end' => 'HH:MM', 'days' => [1..7]] or null. */
    public static function sched(int $uid): ?array {
        $s = (string) get_user_meta($uid, 'vpm_work_start', true); $e = (string) get_user_meta($uid, 'vpm_work_end', true);
        $ok = static fn($t) => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
        if (!$ok($s) || !$ok($e) || $e <= $s) return null;
        $days = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) get_user_meta($uid, 'vpm_work_days', true))), static fn($x) => $x >= 1 && $x <= 7)));
        return ['start' => $s, 'end' => $e, 'days' => $days ?: [1, 2, 3, 4, 5]];
    }

    private static function phase(?array $sch, DateTime $n): string {
        if (!$sch) return 'no_schedule';
        if (!in_array((int) $n->format('N'), $sch['days'], true)) return 'day_off';
        $hm = $n->format('H:i');
        if ($hm < $sch['start']) return 'before';
        return $hm >= $sch['end'] ? 'ended' : 'on_shift';
    }

    private static function on_leave(int $uid): bool { return (string) get_user_meta($uid, 'vtp_availability', true) === 'leave'; }

    private static function row(int $uid, string $date): ?array {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t() . ' WHERE user_id=%d AND work_date=%s', $uid, $date), ARRAY_A);
        return $r ?: null;
    }

    private static function me(int $uid): array {
        $n = self::local_now(); $sch = self::sched($uid); $row = self::row($uid, $n->format('Y-m-d'));
        return ['status' => self::on_leave($uid) ? 'leave' : self::phase($sch, $n), 'schedule' => $sch,
            'online_seconds' => (int) ($row['online_seconds'] ?? 0), 'active_seconds' => (int) ($row['active_seconds'] ?? 0),
            'first_seen' => self::iso($row['start_at'] ?? null), 'last_online' => self::iso($row['end_at'] ?? null)];
    }

    public static function ping(WP_REST_Request $r) {
        self::ensure(); global $wpdb; $T = self::t(); $uid = get_current_user_id();
        $ts = time(); $last = (int) get_user_meta($uid, 'vpm_last_seen', true); $active = (bool) $r->get_param('active');
        update_user_meta($uid, 'vpm_last_seen', $ts);
        if ($active) update_user_meta($uid, 'vpm_last_active', $ts);
        $n = self::local_now(); $sch = self::sched($uid);
        if (self::phase($sch, $n) === 'on_shift' && !self::on_leave($uid)) {
            $delta = ($last && $ts - $last <= 120) ? min($ts - $last, 90) : 45;
            $date = $n->format('Y-m-d'); $now = self::now();
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $T (user_id,work_date,start_at,end_at,status,sched_start,sched_end) VALUES (%d,%s,%s,%s,'working',%s,%s)", $uid, $date, $now, $now, $sch['start'], $sch['end']));
            $wpdb->query($wpdb->prepare("UPDATE $T SET online_seconds=online_seconds+%d, active_seconds=active_seconds+%d, end_at=%s WHERE user_id=%d AND work_date=%s", $delta, $active ? $delta : 0, $now, $uid, $date));
        }
        return rest_ensure_response(['me' => self::me($uid), 'now' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    public static function team() {
        self::ensure(); global $wpdb; $T = self::t(); $n = self::local_now();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $T WHERE work_date=%s", $n->format('Y-m-d')), ARRAY_A);
        $by = []; foreach ($rows as $x) $by[(int) $x['user_id']] = $x;
        $out = [];
        foreach (get_users(['role__in' => ['administrator', 'vibes_manager', 'vibes_employee'], 'orderby' => 'display_name']) as $u) {
            if (get_user_meta($u->ID, 'vtp_disabled', true)) continue;
            $seen = (int) get_user_meta($u->ID, 'vpm_last_seen', true); $act = (int) get_user_meta($u->ID, 'vpm_last_active', true);
            $online = $seen && (time() - $seen) < 120 ? (($act && (time() - $act) < 300) ? 'online' : 'idle') : 'offline';
            $sch = self::sched($u->ID); $phase = self::phase($sch, $n); $row = $by[$u->ID] ?? null;
            if (self::on_leave($u->ID)) $work = 'leave';
            elseif ($phase === 'no_schedule') $work = 'no_schedule';
            elseif ($phase === 'day_off') $work = 'off';
            elseif ($phase === 'before') $work = 'before';
            elseif ($phase === 'on_shift') $work = $online === 'online' ? 'working' : ($online === 'idle' ? 'idle' : 'not_online');
            else $work = $row ? 'ended' : 'absent';
            $out[] = ['id' => $u->ID, 'work' => $work, 'online' => $online, 'schedule' => $sch,
                'last_seen' => $seen ? gmdate('Y-m-d\TH:i:s\Z', $seen) : null,
                'online_seconds' => (int) ($row['online_seconds'] ?? 0), 'active_seconds' => (int) ($row['active_seconds'] ?? 0),
                'first_seen' => self::iso($row['start_at'] ?? null), 'last_online' => self::iso($row['end_at'] ?? null)];
        }
        return rest_ensure_response(['team' => $out, 'now' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    public static function report(WP_REST_Request $r) {
        self::ensure(); global $wpdb; $T = self::t();
        $uid = (int) $r->get_param('user') ?: get_current_user_id();
        if ($uid !== get_current_user_id() && !self::manager()) return new WP_Error('vpm_presence', 'You can only view your own attendance.', ['status' => 403]);
        $days = max(1, min(31, (int) ($r->get_param('days') ?: 7)));
        $n = self::local_now(); $today = $n->format('Y-m-d'); $sch = self::sched($uid); $since = (string) get_user_meta($uid, 'vpm_work_since', true) ?: $today;
        $from = (clone $n)->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $T WHERE user_id=%d AND work_date >= %s", $uid, $from), ARRAY_A);
        $by = []; foreach ($rows as $x) $by[$x['work_date']] = $x;
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = (clone $n)->modify("-$i days"); $date = $d->format('Y-m-d'); $x = $by[$date] ?? null;
            if ($x) {
                $out[] = ['date' => $date, 'state' => 'present', 'sched_start' => $x['sched_start'], 'sched_end' => $x['sched_end'], 'first_seen' => self::iso($x['start_at']), 'last_online' => self::iso($x['end_at']), 'online_seconds' => (int) $x['online_seconds'], 'active_seconds' => (int) $x['active_seconds']];
            } elseif ($sch && $date >= $since && in_array((int) $d->format('N'), $sch['days'], true) && ($date < $today || self::phase($sch, $n) === 'ended')) {
                $out[] = ['date' => $date, 'state' => 'absent', 'sched_start' => $sch['start'], 'sched_end' => $sch['end'], 'first_seen' => null, 'last_online' => null, 'online_seconds' => 0, 'active_seconds' => 0];
            }
        }
        return rest_ensure_response(['days' => $out]);
    }

    public static function summary(WP_REST_Request $r) {
        self::ensure(); global $wpdb; $T = self::t();
        if (!self::manager()) return new WP_Error('vpm_presence', 'Managers only.', ['status' => 403]);
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->get_param('from')) ? (string) $r->get_param('from') : (new DateTime('monday this week', new DateTimeZone(self::TZ)))->format('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id, COUNT(*) days, SUM(online_seconds) online, SUM(active_seconds) active FROM $T WHERE work_date >= %s GROUP BY user_id", $from), ARRAY_A);
        $users = array_map(static fn($x) => ['user_id' => (int) $x['user_id'], 'days' => (int) $x['days'], 'worked_seconds' => (int) $x['online'], 'active_seconds' => (int) $x['active']], $rows);
        return rest_ensure_response(['from' => $from, 'users' => $users]);
    }

    /** Working hours sent with the employee forms are checked before anything is saved. */
    public static function validate_schedule($response, $handler, $request) {
        if (!($request instanceof WP_REST_Request) || $request->get_method() !== 'POST') return $response;
        $route = $request->get_route();
        if ($route !== '/vibes/v1/employees' && !preg_match('#^/vibes/v1/employees/\d+$#', $route)) return $response;
        if (!$request->has_param('workStart') && !$request->has_param('workEnd') && !$request->has_param('workDays')) return $response;
        $s = trim((string) $request->get_param('workStart')); $e = trim((string) $request->get_param('workEnd'));
        if ($s === '' && $e === '') return $response;
        $ok = static fn($t) => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
        if (!$ok($s) || !$ok($e)) return new WP_Error('vpm_schedule', 'Enter both a start time and an end time for the working hours.', ['status' => 400]);
        if ($e <= $s) return new WP_Error('vpm_schedule', 'The working hours must end after they start.', ['status' => 400]);
        $d = trim((string) $request->get_param('workDays'));
        if ($d !== '' && !preg_match('/^[1-7](,[1-7])*$/', $d)) return new WP_Error('vpm_schedule', 'Choose valid working days.', ['status' => 400]);
        return $response;
    }

    public static function schedule_dispatch($result, $server, $request) {
        if (!($result instanceof WP_REST_Response) || !($request instanceof WP_REST_Request)) return $result;
        $route = $request->get_route(); $method = $request->get_method();
        if ($method === 'GET' && $route === '/vibes/v1/employees') {
            $data = $result->get_data();
            if (is_array($data)) {
                $result->set_data(array_map(static function ($u) {
                    if (!is_array($u)) return $u;
                    $sch = !empty($u['id']) ? self::sched((int) $u['id']) : null;
                    $u['workStart'] = $sch['start'] ?? ''; $u['workEnd'] = $sch['end'] ?? ''; $u['workDays'] = $sch ? implode(',', $sch['days']) : '';
                    return $u;
                }, $data));
            }
            return $result;
        }
        $is_create = $method === 'POST' && $route === '/vibes/v1/employees';
        $is_update = $method === 'POST' && preg_match('#^/vibes/v1/employees/(\d+)$#', $route, $m);
        if (($is_create || $is_update) && $result->get_status() < 300 && ($request->has_param('workStart') || $request->has_param('workEnd') || $request->has_param('workDays'))) {
            $data = $result->get_data();
            $uid = $is_update ? (int) $m[1] : (int) ($data['employee']['id'] ?? 0);
            if ($uid && get_userdata($uid)) {
                $s = trim((string) $request->get_param('workStart')); $e = trim((string) $request->get_param('workEnd')); $d = trim((string) $request->get_param('workDays'));
                if ($s !== '' && $e !== '') {
                    if (!get_user_meta($uid, 'vpm_work_since', true)) update_user_meta($uid, 'vpm_work_since', self::local_now()->format('Y-m-d')); update_user_meta($uid, 'vpm_work_start', $s); update_user_meta($uid, 'vpm_work_end', $e); update_user_meta($uid, 'vpm_work_days', $d !== '' ? $d : '1,2,3,4,5');
                } else {
                    delete_user_meta($uid, 'vpm_work_since'); delete_user_meta($uid, 'vpm_work_start'); delete_user_meta($uid, 'vpm_work_end'); delete_user_meta($uid, 'vpm_work_days');
                }
            }
        }
        return $result;
    }
}
VPM_Presence::boot();

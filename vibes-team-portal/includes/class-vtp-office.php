<?php
/**
 * Vibes Office — the always-on virtual office feature.
 *
 * Covers:
 *  - Desktop-app login (username/password → a long-lived bearer token), so the
 *    Electron app can authenticate without a browser cookie jar.
 *  - Room management (admin only): rooms are just named Jitsi rooms; the
 *    actual video/audio runs on Jitsi's free public server, this plugin only
 *    tracks who is in which room and for how long.
 *  - Presence pings and (optionally) periodic snapshot uploads from the
 *    desktop app, so admins can see who is in the office right now and
 *    review a light attendance/analytics history.
 *
 * Privacy note: snapshots are stored outside the public uploads path and are
 * only ever served back through the gated /office/snapshots/{id} endpoint to
 * someone with the vtp_manage_office capability — never a public URL.
 */

if (!defined('ABSPATH')) {
    exit;
}

class VTP_Office {
    private const NS = 'vibes/v1';
    private const TOKEN_TTL_DAYS = 30;
    private const STALE_SESSION_MINUTES = 20; // no ping in this long => treat as ended
    private const SNAPSHOT_RETENTION_DAYS = 30;
    private const SNAPSHOT_MAX_BYTES = 1.5 * 1024 * 1024;

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('init', [self::class, 'maybe_schedule_cleanup']);
        add_action('vtp_office_cleanup', [self::class, 'cleanup']);
    }

    // ----------------------------- helpers -----------------------------

    private static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'vtp_' . $name;
    }

    private static function body(WP_REST_Request $request): array {
        $body = $request->get_json_params();
        return is_array($body) ? $body : $request->get_params();
    }

    private static function error(string $code, string $message, int $status = 400): WP_Error {
        return new WP_Error($code, $message, ['status' => $status]);
    }

    /** VPM_Permissions::audit() lives in the (usually co-installed) Vibes Portal
     *  Modules plugin. Guard the call so Office still works standalone. */
    private static function audit(string $action, string $object_type, int $object_id = 0, array $details = []): void {
        if (class_exists('VPM_Permissions') && method_exists('VPM_Permissions', 'audit')) {
            VPM_Permissions::audit('office', $action, $object_type, $object_id, $details);
        }
    }

    private static function office_users_can(int $user_id): bool {
        return user_can($user_id, 'vtp_use_office');
    }

    public static function logged_in_office(): bool {
        return is_user_logged_in() && current_user_can('vtp_use_office');
    }

    public static function manage_office(): bool {
        return current_user_can('vtp_manage_office');
    }

    /** Resolve the bearer token on the request to a user id, or null. */
    private static function token_user_id(WP_REST_Request $request): ?int {
        global $wpdb;
        $header = (string) $request->get_header('authorization');
        $token = '';
        if ($header && stripos($header, 'bearer ') === 0) {
            $token = trim(substr($header, 7));
        }
        if (!$token) {
            $token = (string) $request->get_header('x-vibes-office-token');
        }
        if (!$token) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, user_id FROM ' . self::table('office_tokens') . ' WHERE token=%s AND revoked_at IS NULL AND expires_at > %s',
            $token,
            current_time('mysql')
        ), ARRAY_A);
        if (!$row) return null;
        $wpdb->update(self::table('office_tokens'), ['last_used_at' => current_time('mysql')], ['id' => (int) $row['id']]);
        return (int) $row['user_id'];
    }

    /** Permission callback: a valid device token is required. */
    public static function device_auth(WP_REST_Request $request): bool {
        $user_id = self::token_user_id($request);
        if (!$user_id) return false;
        $request->set_param('__office_user_id', $user_id);
        return self::office_users_can($user_id);
    }

    private static function current_office_user(WP_REST_Request $request): int {
        $id = (int) $request->get_param('__office_user_id');
        return $id ?: get_current_user_id();
    }

    private static function user_payload(WP_User $user): array {
        return [
            'id' => (int) $user->ID,
            'name' => $user->display_name,
            'email' => $user->user_email,
            'avatarUrl' => method_exists('VTP_REST', 'avatar_url') ? VTP_REST::avatar_url((int) $user->ID) : get_avatar_url($user->ID),
        ];
    }

    // ----------------------------- routes -----------------------------

    public static function routes(): void {
        register_rest_route(self::NS, '/office/login', [
            'methods' => 'POST', 'callback' => [self::class, 'login'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/office/logout', [
            'methods' => 'POST', 'callback' => [self::class, 'logout'], 'permission_callback' => [self::class, 'device_auth'],
        ]);
        register_rest_route(self::NS, '/office/rooms', [
            ['methods' => 'GET', 'callback' => [self::class, 'rooms_list'], 'permission_callback' => [self::class, 'require_any_office_auth']],
            ['methods' => 'POST', 'callback' => [self::class, 'rooms_create'], 'permission_callback' => [self::class, 'manage_office']],
        ]);
        register_rest_route(self::NS, '/office/rooms/(?P<id>\d+)', [
            ['methods' => 'PATCH', 'callback' => [self::class, 'rooms_update'], 'permission_callback' => [self::class, 'manage_office']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'rooms_delete'], 'permission_callback' => [self::class, 'manage_office']],
        ]);
        register_rest_route(self::NS, '/office/presence', [
            'methods' => 'POST', 'callback' => [self::class, 'presence_ping'], 'permission_callback' => [self::class, 'device_auth'],
        ]);
        register_rest_route(self::NS, '/office/snapshot', [
            'methods' => 'POST', 'callback' => [self::class, 'snapshot_upload'], 'permission_callback' => [self::class, 'device_auth'],
        ]);
        register_rest_route(self::NS, '/office/analytics', [
            'methods' => 'GET', 'callback' => [self::class, 'analytics'], 'permission_callback' => [self::class, 'manage_office'],
        ]);
        register_rest_route(self::NS, '/office/online', [
            'methods' => 'GET', 'callback' => [self::class, 'online_now'], 'permission_callback' => [self::class, 'require_any_office_auth'],
        ]);
        register_rest_route(self::NS, '/office/snapshots/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [self::class, 'snapshot_serve'], 'permission_callback' => [self::class, 'manage_office'],
        ]);
    }

    public static function require_any_office_auth(WP_REST_Request $request): bool {
        return self::device_auth($request) || self::logged_in_office();
    }

    // ----------------------------- login/logout -----------------------------

    public static function login(WP_REST_Request $request) {
        global $wpdb;
        $body = self::body($request);
        $username = sanitize_user((string) ($body['username'] ?? ''), true);
        $password = (string) ($body['password'] ?? '');
        $device_label = sanitize_text_field((string) ($body['deviceLabel'] ?? ''));
        if ($username === '' || $password === '') return self::error('missing_credentials', 'Enter your username and password.', 400);

        $user = wp_authenticate($username, $password);
        if (is_wp_error($user)) {
            // Don't reveal which half of the credential was wrong.
            return self::error('invalid_login', 'Incorrect username or password.', 401);
        }
        if (!self::office_users_can((int) $user->ID)) {
            return self::error('not_authorized', 'This account is not set up for Vibes Office.', 403);
        }

        $token = bin2hex(random_bytes(32));
        $now = current_time('mysql');
        $expires = gmdate('Y-m-d H:i:s', time() + self::TOKEN_TTL_DAYS * DAY_IN_SECONDS);
        $wpdb->insert(self::table('office_tokens'), [
            'token' => $token,
            'user_id' => (int) $user->ID,
            'device_label' => $device_label,
            'created_at' => $now,
            'expires_at' => $expires,
        ]);

        self::audit('device_login', 'user', (int) $user->ID, ['device' => $device_label]);

        return rest_ensure_response([
            'token' => $token,
            'expiresAt' => $expires,
            'user' => self::user_payload($user),
        ]);
    }

    public static function logout(WP_REST_Request $request) {
        global $wpdb;
        $user_id = self::current_office_user($request);
        $body = self::body($request);
        self::end_open_sessions($user_id, sanitize_key((string) ($body['reason'] ?? 'manual')));

        $header = (string) $request->get_header('authorization');
        $token = (stripos($header, 'bearer ') === 0) ? trim(substr($header, 7)) : (string) $request->get_header('x-vibes-office-token');
        if ($token) {
            $wpdb->update(self::table('office_tokens'), ['revoked_at' => current_time('mysql')], ['token' => $token]);
        }
        return rest_ensure_response(['ok' => true]);
    }

    // ----------------------------- rooms -----------------------------

    private static function room_row(array $row): array {
        return [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'label' => $row['label'],
            'jitsiRoom' => $row['jitsi_room'],
        ];
    }

    public static function rooms_list() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table('office_rooms') . ' WHERE is_active=1 ORDER BY sort_order ASC, id ASC', ARRAY_A);
        return rest_ensure_response(array_map([self::class, 'room_row'], $rows ?: []));
    }

    public static function rooms_create(WP_REST_Request $request) {
        global $wpdb;
        $body = self::body($request);
        $label = sanitize_text_field((string) ($body['label'] ?? ''));
        if ($label === '') return self::error('missing_label', 'Give the room a name.', 400);
        $slug = sanitize_title($label);
        $base_slug = $slug ?: 'room';
        $n = 2;
        while ((int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('office_rooms') . ' WHERE slug=%s', $slug))) {
            $slug = $base_slug . '-' . $n++;
        }
        $jitsi_room = 'VibesAgency-' . preg_replace('/[^A-Za-z0-9]/', '', ucwords(str_replace('-', ' ', $base_slug))) . '-' . bin2hex(random_bytes(5));
        $max_order = (int) $wpdb->get_var('SELECT MAX(sort_order) FROM ' . self::table('office_rooms'));
        $wpdb->insert(self::table('office_rooms'), [
            'slug' => $slug, 'label' => $label, 'jitsi_room' => $jitsi_room,
            'sort_order' => $max_order + 1, 'is_active' => 1,
            'created_by' => get_current_user_id(), 'created_at' => current_time('mysql'),
        ]);
        self::audit('room_created', 'room', (int) $wpdb->insert_id, ['label' => $label]);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('office_rooms') . ' WHERE id=%d', $wpdb->insert_id), ARRAY_A);
        return new WP_REST_Response(self::room_row($row), 201);
    }

    public static function rooms_update(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('office_rooms') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$row) return self::error('not_found', 'Room not found.', 404);
        $body = self::body($request);
        $update = [];
        if (isset($body['label'])) $update['label'] = sanitize_text_field((string) $body['label']);
        if (isset($body['isActive'])) $update['is_active'] = $body['isActive'] ? 1 : 0;
        if ($update) $wpdb->update(self::table('office_rooms'), $update, ['id' => $id]);
        self::audit('room_updated', 'room', $id, $update);
        $fresh = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('office_rooms') . ' WHERE id=%d', $id), ARRAY_A);
        return rest_ensure_response(self::room_row($fresh));
    }

    public static function rooms_delete(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $wpdb->update(self::table('office_rooms'), ['is_active' => 0], ['id' => $id]);
        self::audit('room_deleted', 'room', $id);
        return rest_ensure_response(['deleted' => true]);
    }

    // ----------------------------- presence -----------------------------

    private static function end_open_sessions(int $user_id, string $reason = 'manual'): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table('office_sessions') . " SET ended_at=%s, ended_reason=%s WHERE user_id=%d AND ended_at IS NULL",
            current_time('mysql'), $reason, $user_id
        ));
    }

    /** Lazily close any session across all users that hasn't pinged in a while. */
    private static function sweep_stale_sessions(): void {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::STALE_SESSION_MINUTES * MINUTE_IN_SECONDS);
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table('office_sessions') . " SET ended_at=last_ping_at, ended_reason='stale' WHERE ended_at IS NULL AND last_ping_at < %s",
            $cutoff
        ));
    }

    public static function presence_ping(WP_REST_Request $request) {
        global $wpdb;
        $user_id = self::current_office_user($request);
        $body = self::body($request);
        $room_id = absint($body['roomId'] ?? 0) ?: null;
        $status = in_array($body['status'] ?? 'online', ['online', 'away'], true) ? $body['status'] : 'online';
        $now = current_time('mysql');

        self::sweep_stale_sessions();

        $open = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table('office_sessions') . ' WHERE user_id=%d AND ended_at IS NULL ORDER BY id DESC LIMIT 1',
            $user_id
        ), ARRAY_A);

        if ($open && $room_id && (int) $open['room_id'] !== (int) $room_id) {
            // Switched rooms: close the old session, open a fresh one.
            $wpdb->update(self::table('office_sessions'), ['ended_at' => $now, 'ended_reason' => 'room_switch'], ['id' => (int) $open['id']]);
            $open = null;
        }

        if (!$open) {
            $wpdb->insert(self::table('office_sessions'), [
                'user_id' => $user_id, 'room_id' => $room_id, 'status' => $status,
                'started_at' => $now, 'last_ping_at' => $now,
            ]);
            $session_id = (int) $wpdb->insert_id;
        } else {
            $session_id = (int) $open['id'];
            $wpdb->update(self::table('office_sessions'), ['last_ping_at' => $now, 'status' => $status], ['id' => $session_id]);
        }

        return rest_ensure_response(['sessionId' => $session_id, 'ok' => true]);
    }

    // ----------------------------- snapshots -----------------------------

    private static function snapshot_dir(): string {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'vtp-office-snapshots/';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            // Defense in depth: keep this folder out of direct public reach even
            // if something else on the server ever lists wp-content/uploads.
            file_put_contents($dir . '.htaccess', "Deny from all\n");
            file_put_contents($dir . 'index.php', "<?php\n// Silence is golden.\n");
        }
        return $dir;
    }

    public static function snapshot_upload(WP_REST_Request $request) {
        global $wpdb;
        $user_id = self::current_office_user($request);
        $files = $request->get_file_params();
        if (empty($files['image']) || !is_array($files['image'])) return self::error('missing_image', 'No image received.', 400);
        $file = $files['image'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return self::error('upload_error', 'The image upload failed.', 400);
        if ((int) ($file['size'] ?? 0) > self::SNAPSHOT_MAX_BYTES) return self::error('too_large', 'Image is too large.', 400);

        $info = @getimagesize($file['tmp_name']);
        if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
            return self::error('invalid_image', 'Only JPEG or PNG snapshots are accepted.', 400);
        }
        $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';

        $body = self::body($request);
        $face_detected = !empty($body['faceDetected']) && $body['faceDetected'] !== 'false';
        $session_id = absint($body['sessionId'] ?? 0);
        if (!$session_id) {
            $open = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . self::table('office_sessions') . ' WHERE user_id=%d AND ended_at IS NULL ORDER BY id DESC LIMIT 1', $user_id), ARRAY_A);
            $session_id = $open ? (int) $open['id'] : 0;
        }
        if (!$session_id) return self::error('no_session', 'No active office session for this snapshot.', 400);

        $dir = self::snapshot_dir();
        $sub = gmdate('Y/m/');
        if (!file_exists($dir . $sub)) wp_mkdir_p($dir . $sub);
        $filename = $sub . wp_generate_password(24, false, false) . '.' . $ext;
        $full_path = $dir . $filename;
        if (!@move_uploaded_file($file['tmp_name'], $full_path)) {
            // Fall back for non-standard upload contexts (e.g. tests).
            if (!@rename($file['tmp_name'], $full_path)) return self::error('save_failed', 'Could not save the snapshot.', 500);
        }

        $wpdb->insert(self::table('office_snapshots'), [
            'session_id' => $session_id, 'user_id' => $user_id,
            'face_detected' => $face_detected ? 1 : 0,
            'file_path' => $filename, 'captured_at' => current_time('mysql'),
        ]);

        return rest_ensure_response(['id' => (int) $wpdb->insert_id, 'ok' => true]);
    }

    public static function snapshot_serve(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('office_snapshots') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$row) return self::error('not_found', 'Snapshot not found.', 404);
        $path = self::snapshot_dir() . $row['file_path'];
        if (!file_exists($path)) return self::error('not_found', 'Snapshot file is missing.', 404);
        $mime = str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';
        $response = new WP_REST_Response(file_get_contents($path), 200);
        $response->header('Content-Type', $mime);
        $response->header('Cache-Control', 'private, max-age=600');
        return $response;
    }

    // ----------------------------- analytics -----------------------------

    public static function analytics(WP_REST_Request $request) {
        global $wpdb;
        self::sweep_stale_sessions();
        $date = sanitize_text_field((string) ($request->get_param('date') ?: wp_date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = wp_date('Y-m-d');
        $day_start = $date . ' 00:00:00';
        $day_end = $date . ' 23:59:59';

        $employees = get_users(['role__in' => ['administrator', 'vibes_manager', 'vibes_employee'], 'orderby' => 'display_name']);
        $S = self::table('office_sessions');
        $R = self::table('office_rooms');
        $N = self::table('office_snapshots');

        $out = [];
        foreach ($employees as $user) {
            if (!user_can((int) $user->ID, 'vtp_use_office')) continue;
            $uid = (int) $user->ID;

            $sessions = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, r.label room_label FROM {$S} s LEFT JOIN {$R} r ON r.id=s.room_id
                 WHERE s.user_id=%d AND s.started_at <= %s AND (s.ended_at IS NULL OR s.ended_at >= %s)
                 ORDER BY s.started_at ASC",
                $uid, $day_end, $day_start
            ), ARRAY_A) ?: [];

            $seconds = 0;
            $current_room = null;
            $is_online_now = false;
            foreach ($sessions as $s) {
                $start = max(strtotime($s['started_at']), strtotime($day_start));
                $end = $s['ended_at'] ? min(strtotime($s['ended_at']), strtotime($day_end)) : min(time(), strtotime($day_end));
                $seconds += max(0, $end - $start);
                if (!$s['ended_at']) {
                    $is_online_now = true;
                    $current_room = $s['room_label'];
                }
            }

            $last_snapshot = $wpdb->get_row($wpdb->prepare(
                "SELECT id, face_detected, captured_at FROM {$N} WHERE user_id=%d ORDER BY id DESC LIMIT 1", $uid
            ), ARRAY_A);
            $snapshot_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$N} WHERE user_id=%d AND captured_at BETWEEN %s AND %s", $uid, $day_start, $day_end
            ));

            $out[] = [
                'userId' => $uid,
                'name' => $user->display_name,
                'avatarUrl' => method_exists('VTP_REST', 'avatar_url') ? VTP_REST::avatar_url($uid) : get_avatar_url($uid),
                'onlineNow' => $is_online_now,
                'currentRoom' => $current_room,
                'secondsToday' => $seconds,
                'snapshotCount' => $snapshot_count,
                'lastSnapshot' => $last_snapshot ? [
                    'id' => (int) $last_snapshot['id'],
                    'faceDetected' => (bool) $last_snapshot['face_detected'],
                    'capturedAt' => $last_snapshot['captured_at'],
                ] : null,
            ];
        }

        usort($out, static fn($a, $b) => $b['secondsToday'] <=> $a['secondsToday']);
        return rest_ensure_response(['date' => $date, 'employees' => $out]);
    }

    /** Light "who's online now" view for any employee — no seconds, no snapshots. */
    public static function online_now() {
        global $wpdb;
        self::sweep_stale_sessions();
        $S = self::table('office_sessions');
        $R = self::table('office_rooms');
        $rows = $wpdb->get_results(
            "SELECT s.user_id, r.label room_label FROM {$S} s LEFT JOIN {$R} r ON r.id=s.room_id WHERE s.ended_at IS NULL",
            ARRAY_A
        ) ?: [];
        $by_user = [];
        foreach ($rows as $row) $by_user[(int) $row['user_id']] = $row['room_label'];
        $out = [];
        foreach ($by_user as $uid => $room_label) {
            $user = get_user_by('id', $uid);
            if (!$user) continue;
            $out[] = [
                'userId' => $uid,
                'name' => $user->display_name,
                'avatarUrl' => method_exists('VTP_REST', 'avatar_url') ? VTP_REST::avatar_url($uid) : get_avatar_url($uid),
                'onlineNow' => true,
                'currentRoom' => $room_label,
            ];
        }
        return rest_ensure_response(['employees' => $out]);
    }

    // ----------------------------- cleanup -----------------------------

    public static function maybe_schedule_cleanup(): void {
        if (!wp_next_scheduled('vtp_office_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'vtp_office_cleanup');
        }
    }

    public static function cleanup(): void {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::SNAPSHOT_RETENTION_DAYS * DAY_IN_SECONDS);
        $dir = self::snapshot_dir();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, file_path FROM ' . self::table('office_snapshots') . ' WHERE captured_at < %s', $cutoff), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $path = $dir . $row['file_path'];
            if (file_exists($path)) @unlink($path);
        }
        if ($rows) {
            $ids = implode(',', array_map('intval', array_column($rows, 'id')));
            $wpdb->query('DELETE FROM ' . self::table('office_snapshots') . " WHERE id IN ({$ids})");
        }
        // Expired device tokens don't need to linger either.
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('office_tokens') . ' WHERE expires_at < %s', current_time('mysql')));
    }
}

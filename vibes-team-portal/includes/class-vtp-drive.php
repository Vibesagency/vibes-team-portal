<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin Google Drive API v3 client, authenticated as a service account via a
 * JWT bearer assertion (no OAuth consent screen, no refresh tokens).
 *
 * Everything this class creates lives inside one Shared Drive (a Workspace
 * "Shared Drive" / "Team Drive"), because a service account cannot own files
 * in a regular "My Drive" that syncs to a person's computer — only a Shared
 * Drive that the admin's Google Drive for Desktop is also syncing will mirror
 * these folders locally.
 */
class VTP_Drive {
    const OPTION = 'vtp_drive_settings';
    const FOLDER_MIME = 'application/vnd.google-apps.folder';

    public static function raw_settings(): array {
        $raw = get_option(self::OPTION, []);
        return is_array($raw) ? $raw : [];
    }

    public static function configured(): bool {
        $s = self::raw_settings();
        return !empty($s['service_account_json']) && !empty($s['shared_drive_id']);
    }

    public static function folder_url(string $id): string {
        return 'https://drive.google.com/drive/folders/' . rawurlencode($id);
    }

    /** Extracts a Drive file/folder id from a drive.google.com URL, or null. */
    public static function extract_folder_id(string $url): ?string {
        if (preg_match('#/folders/([a-zA-Z0-9_-]{10,})#', $url, $m)) return $m[1];
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]{10,})#', $url, $m)) return $m[1];
        return null;
    }

    private static function b64url(string $s): string {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function access_token() {
        $cached = get_transient('vtp_drive_token');
        if ($cached) return $cached;
        $s = self::raw_settings();
        if (empty($s['service_account_json'])) return new WP_Error('drive_not_configured', 'Google Drive automation is not set up yet.');
        $key = json_decode((string) $s['service_account_json'], true);
        if (!is_array($key) || empty($key['private_key']) || empty($key['client_email'])) {
            return new WP_Error('drive_bad_key', 'The stored Google service-account key is invalid.');
        }
        $now = time();
        $header = self::b64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::b64url(wp_json_encode([
            'iss' => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $sign_input = $header . '.' . $claims;
        $signature = '';
        $ok = openssl_sign($sign_input, $signature, (string) $key['private_key'], 'sha256WithRSAEncryption');
        if (!$ok) return new WP_Error('drive_sign_failed', 'Could not sign the Google service-account token.');
        $jwt = $sign_input . '.' . self::b64url($signature);
        $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt],
        ]);
        if (is_wp_error($resp)) return $resp;
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (empty($data['access_token'])) {
            $msg = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? 'Google rejected the service-account credentials.') : 'Google rejected the service-account credentials.';
            return new WP_Error('drive_auth_failed', (string) $msg);
        }
        set_transient('vtp_drive_token', $data['access_token'], max(60, (int) ($data['expires_in'] ?? 3600) - 60));
        return $data['access_token'];
    }

    private static function request(string $method, string $url, ?array $body = null) {
        $token = self::access_token();
        if (is_wp_error($token)) return $token;
        $args = [
            'method' => $method,
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) return $resp;
        $code = (int) wp_remote_retrieve_response_code($resp);
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code >= 300) {
            $msg = is_array($data) ? ($data['error']['message'] ?? 'Google Drive request failed.') : 'Google Drive request failed.';
            return new WP_Error('drive_api_error', (string) $msg, ['status' => $code]);
        }
        return is_array($data) ? $data : [];
    }

    public static function create_folder(string $name, string $parent_id) {
        if (!self::configured()) return new WP_Error('drive_not_configured', 'Google Drive automation is not set up yet.');
        $url = 'https://www.googleapis.com/drive/v3/files?supportsAllDrives=true&fields=id,webViewLink';
        return self::request('POST', $url, [
            'name' => $name,
            'mimeType' => self::FOLDER_MIME,
            'parents' => [$parent_id],
        ]);
    }

    private static function find_folder(string $name, string $parent_id) {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);
        $q = "'{$parent_id}' in parents and name = '{$escaped}' and mimeType = '" . self::FOLDER_MIME . "' and trashed = false";
        $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $q,
            'fields' => 'files(id,name,webViewLink)',
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
            'corpora' => 'allDrives',
            'pageSize' => 1,
        ]);
        $data = self::request('GET', $url);
        if (is_wp_error($data)) return $data;
        return $data['files'][0] ?? null;
    }

    public static function find_or_create_folder(string $name, string $parent_id) {
        $existing = self::find_folder($name, $parent_id);
        if (is_wp_error($existing)) return $existing;
        if ($existing) return $existing;
        return self::create_folder($name, $parent_id);
    }

    /** Lazily creates (once) and caches the top-level "Projects" folder inside the configured Shared Drive. */
    public static function ensure_root_folder() {
        $s = self::raw_settings();
        if (!empty($s['root_folder_id'])) return $s['root_folder_id'];
        if (empty($s['shared_drive_id'])) return new WP_Error('drive_not_configured', 'Add the Shared Drive ID first.');
        $created = self::create_folder('Projects', (string) $s['shared_drive_id']);
        if (is_wp_error($created)) return $created;
        $s['root_folder_id'] = $created['id'];
        update_option(self::OPTION, $s, false);
        return $created['id'];
    }

    private static function list_children(string $folder_id) {
        $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => "'{$folder_id}' in parents and trashed = false",
            'fields' => 'files(id,name,mimeType)',
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
            'corpora' => 'allDrives',
            'pageSize' => 1000,
        ]);
        $data = self::request('GET', $url);
        if (is_wp_error($data)) return $data;
        return $data['files'] ?? [];
    }

    private static function copy_file(string $file_id, string $name, string $parent_id) {
        $url = "https://www.googleapis.com/drive/v3/files/{$file_id}/copy?supportsAllDrives=true&fields=id";
        return self::request('POST', $url, ['name' => $name, 'parents' => [$parent_id]]);
    }

    /** Best-effort recursive copy of everything inside $source_id into $dest_id. Skips items it fails to copy rather than aborting the whole transfer. */
    public static function copy_folder_contents(string $source_id, string $dest_id): bool {
        $children = self::list_children($source_id);
        if (is_wp_error($children)) return false;
        foreach ($children as $child) {
            if (($child['mimeType'] ?? '') === self::FOLDER_MIME) {
                $sub = self::create_folder((string) $child['name'], $dest_id);
                if (is_wp_error($sub)) continue;
                self::copy_folder_contents((string) $child['id'], (string) $sub['id']);
            } else {
                self::copy_file((string) $child['id'], (string) $child['name'], $dest_id);
            }
        }
        return true;
    }
}

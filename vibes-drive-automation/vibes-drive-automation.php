<?php
/**
 * Plugin Name: Vibes Drive Automation
 * Description: Connects the Vibes Team Portal project lifecycle to a controlled Google Drive folder workflow.
 * Version: 0.4.4
 * Author: Vibes Agency
 */

defined('ABSPATH') || exit;

const VIBES_DRIVE_OPTION = 'vibes_drive_automation_settings';
const VIBES_DRIVE_MAP_OPTION = 'vibes_drive_automation_project_map';
const VIBES_DRIVE_INQUIRY_MAP_OPTION = 'vibes_drive_automation_inquiry_map';
const VIBES_DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive';

function vibes_drive_settings() {
    $defaults = [
        'client_id' => '',
        'client_secret' => '',
        'refresh_token' => '',
        'root_folder_id' => '1Jvo8n1Cn9th6ROzKfaThznv8Zuvm5WDK',
        'clients_folder_id' => '1VYGXp3ziM1KXePGrtoYqE3_qpXctpGK1',
        'inquiries_folder_id' => '1ehacYI0rV2lIV0FYFNThN63-Bo3AMSqe',
        'quotations_folder_id' => '1rRQckJ1T7YKfbBUvVq8cc9mP5YI8XKfr',
        'template_folder_id' => '112q1F3cwa6rZxGUffT2-raJxiV-mvYlJ',
    ];
    return wp_parse_args((array) get_option(VIBES_DRIVE_OPTION, []), $defaults);
}

function vibes_drive_map() {
    return (array) get_option(VIBES_DRIVE_MAP_OPTION, []);
}

function vibes_drive_save_map($map) {
    update_option(VIBES_DRIVE_MAP_OPTION, $map, false);
}

function vibes_drive_inquiry_map() {
    return (array) get_option(VIBES_DRIVE_INQUIRY_MAP_OPTION, []);
}

function vibes_drive_save_inquiry_map($map) {
    update_option(VIBES_DRIVE_INQUIRY_MAP_OPTION, $map, false);
}

function vibes_drive_safe_name($value, $fallback = '') {
    $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
    $value = preg_replace('/[^A-Za-z0-9]+/', '_', $value);
    $value = trim((string) $value, '_');
    return $value ?: $fallback;
}

function vibes_drive_inquiries_root_id() {
    $s = vibes_drive_settings();
    return (string) ($s['inquiries_folder_id'] ?: $s['root_folder_id']);
}

function vibes_drive_quotations_root_id() {
    $s = vibes_drive_settings();
    return (string) ($s['quotations_folder_id'] ?: $s['clients_folder_id']);
}

function vibes_drive_admin_url() {
    return admin_url('options-general.php?page=vibes-drive-automation');
}

add_action('admin_menu', static function () {
    add_options_page('Vibes Drive Automation', 'Vibes Drive Automation', 'manage_options', 'vibes-drive-automation', 'vibes_drive_render_settings');
});

add_action('admin_init', static function () {
    register_setting('vibes_drive_automation', VIBES_DRIVE_OPTION, [
        'type' => 'array',
        'sanitize_callback' => static function ($input) {
            $old = vibes_drive_settings();
            return [
                'client_id' => sanitize_text_field($input['client_id'] ?? ''),
                'client_secret' => sanitize_text_field($input['client_secret'] ?? ''),
                'refresh_token' => $old['refresh_token'],
                'root_folder_id' => sanitize_text_field($input['root_folder_id'] ?? $old['root_folder_id']),
                'clients_folder_id' => sanitize_text_field($input['clients_folder_id'] ?? $old['clients_folder_id']),
                'inquiries_folder_id' => sanitize_text_field($input['inquiries_folder_id'] ?? $old['inquiries_folder_id']),
                'quotations_folder_id' => sanitize_text_field($input['quotations_folder_id'] ?? $old['quotations_folder_id']),
                'template_folder_id' => sanitize_text_field($input['template_folder_id'] ?? $old['template_folder_id']),
            ];
        },
    ]);
});

function vibes_drive_render_settings() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $s = vibes_drive_settings();
    $connected = !empty($s['refresh_token']);
    $state = wp_create_nonce('vibes_drive_oauth');
    $oauth_url = add_query_arg([
        'vibes_drive_oauth' => 'start',
        'state' => $state,
    ], vibes_drive_admin_url());
    ?>
    <div class="wrap">
        <h1>Vibes Drive Automation</h1>
        <p>Drive root and templates are prefilled for the Vibes Agency folder structure. The connection is stored on this WordPress site and can be revoked from Google.</p>
        <form method="post" action="options.php">
            <?php settings_fields('vibes_drive_automation'); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="vibes-drive-client-id">OAuth client ID</label></th><td><input id="vibes-drive-client-id" class="regular-text" name="<?php echo esc_attr(VIBES_DRIVE_OPTION); ?>[client_id]" value="<?php echo esc_attr($s['client_id']); ?>" /></td></tr>
                <tr><th scope="row"><label for="vibes-drive-client-secret">OAuth client secret</label></th><td><input id="vibes-drive-client-secret" class="regular-text" type="password" name="<?php echo esc_attr(VIBES_DRIVE_OPTION); ?>[client_secret]" value="<?php echo esc_attr($s['client_secret']); ?>" autocomplete="off" /></td></tr>
                <tr><th scope="row"><label for="vibes-drive-root">Drive root folder ID</label></th><td><input id="vibes-drive-root" class="regular-text" name="<?php echo esc_attr(VIBES_DRIVE_OPTION); ?>[root_folder_id]" value="<?php echo esc_attr($s['root_folder_id']); ?>" /></td></tr>
                <tr><th scope="row"><label for="vibes-drive-clients">Clients folder ID</label></th><td><input id="vibes-drive-clients" class="regular-text" name="<?php echo esc_attr(VIBES_DRIVE_OPTION); ?>[clients_folder_id]" value="<?php echo esc_attr($s['clients_folder_id']); ?>" /></td></tr>
                <tr><th scope="row"><label for="vibes-drive-inquiries">Inquiries folder ID</label></th><td><input id="vibes-drive-inquiries" class="regular-text" name="<?php echo esc_attr(VIBES_DRIVE_OPTION); ?>[inquiries_folder_id]" value="<?php echo esc_attr($s['inquiries_folder_id']); ?>" /></td></tr>
                <tr><th scope="row"><label for="vibes-drive-quotations">Quotations folder ID</label></th><td><input id="vibes-drive-quotations" class="regular-text" name="<?php echo esc_attr(VIBES_DRIVE_OPTION); ?>[quotations_folder_id]" value="<?php echo esc_attr($s['quotations_folder_id']); ?>" /></td></tr>
                <tr><th scope="row"><label for="vibes-drive-template">Project template folder ID</label></th><td><input id="vibes-drive-template" class="regular-text" name="<?php echo esc_attr(VIBES_DRIVE_OPTION); ?>[template_folder_id]" value="<?php echo esc_attr($s['template_folder_id']); ?>" /></td></tr>
            </table>
            <?php submit_button('Save settings'); ?>
        </form>
        <hr>
        <h2>Google connection</h2>
        <?php $token_ok = $connected ? !is_wp_error(vibes_drive_access_token()) : false; ?>
        <p>Status: <strong style="color:<?php echo $token_ok ? '#1E6B45' : '#9A3F0B'; ?>"><?php echo $token_ok ? 'Connected and working' : ($connected ? 'Login expired — Google rejected the saved connection. Click “Connect Google Drive” to sign in again.' : 'Not connected'); ?></strong></p>
        <?php if ($connected && !$token_ok) : ?><p>If this keeps happening every week, the Google Cloud OAuth app is in “Testing” mode. Publish it (Google Cloud Console → APIs &amp; Services → OAuth consent screen → Publish app) so the connection stops expiring.</p><?php endif; ?>
        <p><strong>OAuth redirect URL</strong><br><code><?php echo esc_html(vibes_drive_admin_url()); ?></code></p>
        <?php if ($s['client_id'] && $s['client_secret']) : ?>
            <p><a class="button button-primary" href="<?php echo esc_url($oauth_url); ?>">Connect Google Drive</a></p>
        <?php else : ?>
            <p>Save the OAuth client ID and secret first, then connect the Drive account.</p>
        <?php endif; ?>
        <p>Project mappings: <?php echo esc_html((string) count(vibes_drive_map())); ?></p>
        <hr>
        <h2>Quotation PDFs</h2>
        <p>Generated quotations are stored in Drive by client and quotation number. The portal reads the Drive copy, so you can replace the PDF in Drive without uploading it again.</p>
        <?php if (isset($_GET['synced'])) : ?>
            <div class="notice notice-success"><p>Synced <?php echo esc_html((string) absint($_GET['synced'])); ?> quotation families to Drive<?php echo !empty($_GET['drive_errors']) ? ' (' . esc_html((string) absint($_GET['drive_errors'])) . ' errors)' : ''; ?>.</p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="vibes_drive_sync_quotations">
            <?php wp_nonce_field('vibes_drive_sync_quotations'); ?>
            <?php submit_button('Sync existing quotations to Drive', 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php
}

function vibes_drive_oauth_start() {
    if (!current_user_can('manage_options') || !isset($_GET['vibes_drive_oauth']) || $_GET['vibes_drive_oauth'] !== 'start') {
        return;
    }
    $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
    if (!$state || !wp_verify_nonce($state, 'vibes_drive_oauth')) {
        wp_die('Invalid OAuth state.');
    }
    $s = vibes_drive_settings();
    $params = [
        'client_id' => $s['client_id'],
        'redirect_uri' => vibes_drive_admin_url(),
        'response_type' => 'code',
        'scope' => VIBES_DRIVE_SCOPE,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => wp_create_nonce('vibes_drive_oauth_callback'),
    ];
    wp_redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    exit;
}
add_action('admin_init', 'vibes_drive_oauth_start', 1);

function vibes_drive_oauth_callback() {
    if (!current_user_can('manage_options') || empty($_GET['code'])) {
        return;
    }
    $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
    if (!$state || !wp_verify_nonce($state, 'vibes_drive_oauth_callback')) {
        wp_die('Invalid OAuth callback state.');
    }
    $s = vibes_drive_settings();
    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 20,
        'body' => [
            'code' => sanitize_text_field(wp_unslash($_GET['code'])),
            'client_id' => $s['client_id'],
            'client_secret' => $s['client_secret'],
            'redirect_uri' => vibes_drive_admin_url(),
            'grant_type' => 'authorization_code',
        ],
    ]);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (is_wp_error($response) || empty($body['refresh_token'])) {
        wp_die('Google did not return a refresh token. Reconnect and approve offline access.');
    }
    $s['refresh_token'] = sanitize_text_field($body['refresh_token']);
    delete_transient('vibes_drive_working');
    update_option(VIBES_DRIVE_OPTION, $s, false);
    wp_safe_redirect(vibes_drive_admin_url() . '&connected=1');
    exit;
}
add_action('admin_init', 'vibes_drive_oauth_callback', 2);

function vibes_drive_sync_existing_quotations() {
    if (!current_user_can('manage_options')) { wp_die('You do not have permission to do this.'); }
    check_admin_referer('vibes_drive_sync_quotations');
    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'vtp_quotations WHERE parent_quotation_id IS NULL ORDER BY id ASC', ARRAY_A);
    $done = 0; $errors = 0;
    foreach ($rows as $row) {
        $result = vibes_drive_quotation_sync($row);
        if (is_wp_error($result)) { $errors++; } else { $done++; }
    }
    $url = add_query_arg(['page' => 'vibes-drive-automation', 'synced' => $done, 'drive_errors' => $errors], admin_url('options-general.php'));
    wp_safe_redirect($url);
    exit;
}
add_action('admin_post_vibes_drive_sync_quotations', 'vibes_drive_sync_existing_quotations');

function vibes_drive_access_token() {
    $s = vibes_drive_settings();
    if (!$s['client_id'] || !$s['client_secret'] || !$s['refresh_token']) {
        return new WP_Error('vibes_drive_not_connected', 'Google Drive is not connected.');
    }
    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 20,
        'body' => [
            'client_id' => $s['client_id'],
            'client_secret' => $s['client_secret'],
            'refresh_token' => $s['refresh_token'],
            'grant_type' => 'refresh_token',
        ],
    ]);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (is_wp_error($response) || empty($body['access_token'])) {
        return new WP_Error('vibes_drive_token_error', 'Could not refresh the Google Drive token.');
    }
    return $body['access_token'];
}

function vibes_drive_api($method, $path, $body = null, $query = []) {
    $token = vibes_drive_access_token();
    if (is_wp_error($token)) {
        return $token;
    }
    // Harmless no-op against a regular My Drive item, but required by Drive
    // v3 for any call that ever does touch a Shared Drive item (or lists
    // one's children) — keeps this safe if that ever changes.
    if (!array_key_exists('supportsAllDrives', $query)) {
        $query['supportsAllDrives'] = 'true';
    }
    if ($method === 'GET' && $path === 'files') {
        if (!array_key_exists('includeItemsFromAllDrives', $query)) { $query['includeItemsFromAllDrives'] = 'true'; }
        if (!array_key_exists('corpora', $query)) { $query['corpora'] = 'allDrives'; }
    }
    $url = 'https://www.googleapis.com/drive/v3/' . ltrim($path, '/');
    if ($query) {
        $url = add_query_arg($query, $url);
    }
    $args = [
        'method' => $method,
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ],
    ];
    if ($body !== null) {
        $args['body'] = wp_json_encode($body);
    }
    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        return $response;
    }
    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('vibes_drive_api_error', 'Google Drive API error.', ['status' => $code, 'body' => $data]);
    }
    return is_array($data) ? $data : [];
}

/** Upload or replace a raw file in Drive using the already-authorized site OAuth connection. */
function vibes_drive_upload_raw($name, $bytes, $mime, $parent_id, $existing_id = '') {
    $token = vibes_drive_access_token();
    if (is_wp_error($token)) return $token;
    $boundary = 'vibes_drive_' . wp_generate_uuid4();
    $meta = ['name' => sanitize_file_name($name), 'mimeType' => $mime];
    if (!$existing_id) $meta['parents'] = [$parent_id];
    $body = '--' . $boundary . "\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . wp_json_encode($meta) . "\r\n";
    $body .= '--' . $boundary . "\r\nContent-Type: " . $mime . "\r\n\r\n" . $bytes . "\r\n--" . $boundary . "--";
    $path = $existing_id ? 'files/' . rawurlencode($existing_id) : 'files';
    $url = 'https://www.googleapis.com/upload/drive/v3/' . $path . '?uploadType=multipart&supportsAllDrives=true&fields=id,name,mimeType,webViewLink,parents';
    $response = wp_remote_request($url, [
        'method' => $existing_id ? 'PATCH' : 'POST', 'timeout' => 60,
        'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'multipart/related; boundary="' . $boundary . '"'],
        'body' => $body,
    ]);
    if (is_wp_error($response)) return $response;
    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300) return new WP_Error('vibes_drive_upload_error', 'Google Drive quotation upload failed.', ['status' => $code, 'body' => $data]);
    return is_array($data) ? $data : [];
}

function vibes_drive_pdf_escape($text) {
    $text = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $text) : (string) $text;
    $text = preg_replace('/[^\x20-\x7E]/', '?', $text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

/** Create a reliable, printable quotation PDF without requiring a server PDF package. */
function vibes_drive_quote_pdf(array $quote) {
    $items = json_decode($quote['items_json'] ?? '[]', true) ?: [];
    $currency = in_array(($quote['currency'] ?? 'SAR'), ['SAR', 'USD'], true) ? $quote['currency'] : 'SAR';
    $lines = []; $y = 760;
    $add = static function ($text, $x, $size = 9, $color = '0.14 0.10 0.19') use (&$lines, &$y) { $lines[] = [(string) $text, $x, $y, $size, $color]; $y -= $size + 7; };
    $add('VIBES AGENCY', 48, 22, '0.38 0.20 0.55');
    $add('QUOTATION ' . ($quote['quote_number'] ?? ''), 340, 11, '0.38 0.20 0.55');
    $add('Issued: ' . wp_date('d M Y', strtotime((string) ($quote['created_at'] ?? current_time('mysql')))), 340, 8, '0.36 0.32 0.42');
    $add('Valid until: ' . (($quote['valid_until'] ?? '') ?: '—'), 340, 8, '0.36 0.32 0.42');
    $y = 665; $add('PREPARED FOR', 48, 8, '0.38 0.20 0.55'); $add((string) ($quote['company'] ?: $quote['client_name']), 48, 14); $add((string) $quote['email'], 48, 8, '0.36 0.32 0.42');
    $y = 665; $add('PREPARED BY', 330, 8, '0.38 0.20 0.55'); $add('Vibes Agency', 330, 14); $add('finance@vibesagency.co', 330, 8, '0.36 0.32 0.42');
    $y = 575;
    if ((int) ($quote['contract_months'] ?? 1) > 1) { $add('CONTRACT TERM: ' . ((int) $quote['contract_months'] === 12 ? '1 year' : ((int) $quote['contract_months'] . ' months')), 48, 10, '0.38 0.20 0.55'); $add('Start: ' . ($quote['contract_start'] ?? '—') . '   End: ' . ($quote['contract_end'] ?? '—'), 48, 8, '0.36 0.32 0.42'); }
    $y = 500; $lines[] = ['NO.   LABEL                         SERVICE / DELIVERABLE', 48, $y, 9, '1 1 1']; $lines[] = ['QTY             UNIT PRICE              TOTAL', 408, $y, 9, '1 1 1']; $y -= 24;
    foreach ($items as $index => $item) {
        $desc = trim((string) ($item['description'] ?? $item['name'] ?? 'Service')); $desc = function_exists('mb_strimwidth') ? mb_strimwidth($desc, 0, 52, '...') : substr($desc, 0, 52);
        $label = strtoupper((string) ($item['label'] ?? '')); $lines[] = [($index + 1) . '   ' . ($label ?: '—') . '   ' . $desc, 48, $y, 8, '0.14 0.10 0.19'];
        $lines[] = [number_format((float) ($item['quantity'] ?? 1), 2), 410, $y, 8, '0.14 0.10 0.19'];
        $lines[] = [$currency . ' ' . number_format((float) ($item['unitPrice'] ?? 0), 2), 462, $y, 8, '0.14 0.10 0.19'];
        $lines[] = [$currency . ' ' . number_format((float) ($item['total'] ?? 0), 2), 530, $y, 8, '0.14 0.10 0.19']; $y -= 22;
    }
    $y -= 10; $add('Subtotal', 392, 9, '0.36 0.32 0.42'); $lines[] = [$currency . ' ' . number_format((float) ($quote['subtotal'] ?? 0), 2), 520, $y + 17, 9, '0.14 0.10 0.19'];
    $discount_rate = (float) ($quote['discount_rate'] ?? 0);
    $discount_amount = (float) ($quote['discount_amount'] ?? 0);
    if ($discount_rate > 0 && $discount_amount <= 0) {
        $discount_amount = round(((float) ($quote['subtotal'] ?? 0)) * $discount_rate / 100, 2);
    }
    if ($discount_rate > 0 && $discount_amount > 0) {
        $add('Discount (' . number_format($discount_rate, 2) . '%)', 392, 9, '0.36 0.32 0.42');
        $lines[] = ['-' . $currency . ' ' . number_format($discount_amount, 2), 520, $y + 17, 9, '0.14 0.10 0.19'];
    }
    $add('VAT (' . number_format((float) ($quote['vat_rate'] ?? 0), 2) . '%)', 392, 9, '0.36 0.32 0.42'); $lines[] = [$currency . ' ' . number_format((float) ($quote['vat_amount'] ?? 0), 2), 520, $y + 17, 9, '0.14 0.10 0.19'];
    $lines[] = ['TOTAL', 392, $y - 4, 12, '0.38 0.20 0.55']; $lines[] = [$currency . ' ' . number_format((float) ($quote['total'] ?? 0), 2), 520, $y - 4, 12, '0.38 0.20 0.55'];
    $y -= 45;
    if ((int) ($quote['contract_months'] ?? 1) > 1) {
        $months = (int) $quote['contract_months'];
        $monthly_total = $months > 0 ? ((float) ($quote['total'] ?? 0) / $months) : 0;
        $add('Monthly payment: ' . $currency . ' ' . number_format($monthly_total, 2) . ' x ' . $months . ' equal installments.', 48, 8, '0.36 0.32 0.42');
    }
    if (!empty($quote['notes'])) $add('Notes: ' . preg_replace('/\s+/', ' ', (string) $quote['notes']), 48, 8, '0.36 0.32 0.42'); if (!empty($quote['terms'])) $add('Terms: ' . preg_replace('/\s+/', ' ', (string) $quote['terms']), 48, 8, '0.36 0.32 0.42');
    // Keep the header text readable: the original dark fill sat directly
    // behind the dark metadata lines and made the generated Drive PDF look
    // corrupted. Use a light branded tint for the header and reserve the
    // dark purple fill for the table header, whose labels are white.
    $stream = "q\n0.97 0.95 0.99 rg\n40 700 532 66 re f\n0.38 0.20 0.55 rg\n40 480 532 22 re f\n";
    foreach ($lines as $line) { [$text, $x, $line_y, $size, $color] = $line; $stream .= $color . " rg\nBT /F1 " . $size . " Tf " . $x . ' ' . $line_y . " Td (" . vibes_drive_pdf_escape($text) . ") Tj ET\n"; }
    $stream .= "Q\n";
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream'];
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0]; foreach ($objects as $index => $object) { $offsets[$index + 1] = strlen($pdf); $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n"; } $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n"; for ($i = 1; $i <= 5; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]); $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

function vibes_drive_inquiry_pdf(array $inquiry) {
    $service_items = json_decode($inquiry['services_json'] ?? '[]', true) ?: [];
    $lines = [];
    $push = static function ($text, $x, $y, $size = 10, $color = '0.14 0.10 0.19') use (&$lines) {
        $lines[] = [(string) $text, $x, $y, $size, $color];
    };
    $push('VIBES AGENCY', 48, 742, 22, '0.38 0.20 0.55');
    $push('INQUIRY ' . ($inquiry['request_number'] ?? ''), 350, 742, 11, '0.38 0.20 0.55');
    $push('Issued: ' . wp_date('d F Y', strtotime((string) ($inquiry['created_at'] ?? current_time('mysql')))), 350, 721, 9, '0.36 0.32 0.42');
    $push('Prepared for', 48, 680, 9, '0.38 0.20 0.55');
    $push((string) ($inquiry['company'] ?: $inquiry['client_name']), 48, 660, 15);
    $push((string) $inquiry['client_name'], 48, 638, 9, '0.36 0.32 0.42');
    $push((string) $inquiry['email'], 48, 620, 9, '0.36 0.32 0.42');
    if (!empty($inquiry['phone'])) { $push((string) $inquiry['phone'], 48, 602, 9, '0.36 0.32 0.42'); }
    $push('Requested services', 48, 550, 10, '0.38 0.20 0.55');
    $y = 525;
    foreach ($service_items as $index => $item) {
        $name = trim((string) ($item['name'] ?? $item['description'] ?? 'Service'));
        $name = function_exists('mb_strimwidth') ? mb_strimwidth($name, 0, 80, '...') : substr($name, 0, 80);
        $qty = (float) ($item['quantity'] ?? 1);
        $push(($index + 1) . '. ' . $name . ' (Qty: ' . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . ')', 48, $y, 9);
        $y -= 18;
    }
    $y -= 10;
    $push('Project brief', 48, $y, 10, '0.38 0.20 0.55'); $y -= 20;
    $brief = preg_replace('/\s+/', ' ', (string) ($inquiry['brief'] ?? ''));
    $push(function_exists('mb_strimwidth') ? mb_strimwidth($brief, 0, 115, '...') : substr($brief, 0, 115), 48, $y, 9, '0.36 0.32 0.42'); $y -= 24;
    if (!empty($inquiry['budget'])) { $push('Budget: ' . $inquiry['budget'], 48, $y, 9, '0.36 0.32 0.42'); $y -= 18; }
    if (!empty($inquiry['deadline'])) { $push('Deadline: ' . $inquiry['deadline'], 48, $y, 9, '0.36 0.32 0.42'); }
    $stream = "q\n0.38 0.20 0.55 rg\n40 700 532 58 re f\nQ\n";
    foreach ($lines as $line) {
        [$text, $x, $line_y, $size, $color] = $line;
        $stream .= $color . " rg\nBT /F1 " . $size . " Tf " . $x . ' ' . $line_y . " Td (" . vibes_drive_pdf_escape($text) . ") Tj ET\n";
    }
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream'];
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0]; foreach ($objects as $index => $object) { $offsets[$index + 1] = strlen($pdf); $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n"; } $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n"; for ($i = 1; $i <= 5; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]); $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

function vibes_drive_inquiry_sync(array $inquiry) {
    $root_id = vibes_drive_inquiries_root_id();
    if (!$root_id || empty($inquiry['id'])) { return new WP_Error('vibes_drive_inquiry_config', 'Inquiry Drive folder is not configured.'); }
    $client_part = vibes_drive_name_part($inquiry['client_name'] ?? '', 'Client');
    $company_part = vibes_drive_name_part($inquiry['company'] ?? '', '');
    $client = $company_part && strcasecmp($client_part, $company_part) !== 0 ? $client_part . '_' . $company_part : $client_part;
    $client_folder = vibes_drive_find_child($root_id, $client);
    if (!$client_folder) { $client_folder = vibes_drive_create_folder($client, $root_id); }
    if (is_wp_error($client_folder)) { return $client_folder; }
    $inquiry_folder_name = vibes_drive_safe_name($inquiry['request_number'] ?? ('IN_' . $inquiry['id']), 'Inquiry');
    $inquiry_folder = vibes_drive_find_child($client_folder['id'], $inquiry_folder_name);
    if (!$inquiry_folder) { $inquiry_folder = vibes_drive_create_folder($inquiry_folder_name, $client_folder['id']); }
    if (is_wp_error($inquiry_folder)) { return $inquiry_folder; }
    $file_name = $inquiry_folder_name . '.pdf';
    $existing = vibes_drive_find_child($inquiry_folder['id'], $file_name);
    if (is_wp_error($existing)) { return $existing; }
    $uploaded = vibes_drive_upload_raw($file_name, vibes_drive_inquiry_pdf($inquiry), 'application/pdf', $inquiry_folder['id'], $existing['id'] ?? '');
    if (is_wp_error($uploaded)) { return $uploaded; }
    $map = vibes_drive_inquiry_map();
    $map[(string) $inquiry['id']] = [
        'client_folder_id' => $client_folder['id'],
        'inquiry_folder_id' => $inquiry_folder['id'],
        'inquiry_folder_url' => $inquiry_folder['webViewLink'] ?? ('https://drive.google.com/drive/folders/' . $inquiry_folder['id']),
        'file_id' => $uploaded['id'] ?? ($existing['id'] ?? ''),
        'file_url' => $uploaded['webViewLink'] ?? ('https://drive.google.com/file/d/' . ($uploaded['id'] ?? ($existing['id'] ?? '')) . '/view'),
        'updated_at' => current_time('mysql', true),
    ];
    vibes_drive_save_inquiry_map($map);
    return $map[(string) $inquiry['id']];
}

function vibes_drive_quotation_sync(array $quote) {
    global $wpdb;
    $s = vibes_drive_settings();
    $quotations_root_id = vibes_drive_quotations_root_id();
    if (!$quotations_root_id || empty($quote['id'])) return new WP_Error('vibes_drive_quote_config', 'Quotation Drive folders are not configured.');
    $master_id = (int) ($quote['parent_quotation_id'] ?: $quote['id']);
    $master = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'vtp_quotations WHERE id=%d', $master_id), ARRAY_A);
    if (!$master) return new WP_Error('vibes_drive_quote_missing', 'Quotation family not found.');
    $client_part = vibes_drive_name_part($master['client_name'] ?? '', 'Client');
    $company_part = vibes_drive_name_part($master['company'] ?? '', '');
    $client = $company_part && strcasecmp($client_part, $company_part) !== 0 ? $client_part . '_' . $company_part : $client_part;
    $client_folder = vibes_drive_find_child($quotations_root_id, $client); if (!$client_folder) $client_folder = vibes_drive_create_folder($client, $quotations_root_id); if (is_wp_error($client_folder)) return $client_folder;
    $family = vibes_drive_find_child($client_folder['id'], $master['quote_number']); if (!$family) $family = vibes_drive_create_folder($master['quote_number'], $client_folder['id']); if (is_wp_error($family)) return $family;
    $master_folder = vibes_drive_find_child($family['id'], 'Master quotation'); if (!$master_folder) $master_folder = vibes_drive_create_folder('Master quotation', $family['id']);
    $monthly_folder = vibes_drive_find_child($family['id'], 'Monthly quotations'); if (!$monthly_folder) $monthly_folder = vibes_drive_create_folder('Monthly quotations', $family['id']);
    if (is_wp_error($master_folder) || is_wp_error($monthly_folder)) return new WP_Error('vibes_drive_quote_folder', 'Could not create quotation subfolders.');
    $folder_url = $family['webViewLink'] ?? ('https://drive.google.com/drive/folders/' . $family['id']);
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'vtp_quotations WHERE id=%d OR parent_quotation_id=%d ORDER BY period_index ASC', $master_id, $master_id), ARRAY_A);
    foreach ($rows as $row) {
        $parent = ((int) ($row['period_index'] ?? 0) > 0) ? $monthly_folder['id'] : $master_folder['id'];
        $name = sanitize_file_name($row['quote_number'] . '.pdf');
        $existing = vibes_drive_find_child($parent, $name); if (is_wp_error($existing)) return $existing;
        $uploaded = vibes_drive_upload_raw($name, vibes_drive_quote_pdf($row), 'application/pdf', $parent, $existing['id'] ?? ''); if (is_wp_error($uploaded)) return $uploaded;
        $file_url = $uploaded['webViewLink'] ?? ('https://drive.google.com/file/d/' . $uploaded['id'] . '/view');
        $wpdb->update($wpdb->prefix . 'vtp_quotations', ['drive_folder_url' => $folder_url, 'drive_file_url' => $file_url, 'updated_at' => current_time('mysql')], ['id' => (int) $row['id']]);
    }
    return ['folder_url' => $folder_url, 'master_id' => $master_id];
}

function vibes_drive_copy_file_tree($source_id, $destination_id, $name_override = '') {
    $meta = vibes_drive_api('GET', 'files/' . rawurlencode($source_id), null, ['fields' => 'id,name,mimeType,webViewLink']);
    if (is_wp_error($meta)) { return $meta; }
    $name = $name_override ?: ($meta['name'] ?? 'Copy');
    if (($meta['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
        $copy = vibes_drive_find_child($destination_id, $name);
        if (!$copy) { $copy = vibes_drive_create_folder($name, $destination_id); }
        if (is_wp_error($copy)) { return $copy; }
        $children = vibes_drive_list_children($source_id);
        if (is_wp_error($children)) { return $children; }
        foreach ($children as $child) {
            $result = vibes_drive_copy_file_tree($child['id'], $copy['id']);
            if (is_wp_error($result)) { return $result; }
        }
        return $copy;
    }
    return vibes_drive_api('POST', 'files/' . rawurlencode($source_id) . '/copy', ['name' => $name, 'parents' => [$destination_id]], ['fields' => 'id,name,mimeType,webViewLink,parents']);
}

/** Move a Drive file or folder while preserving its ID and its child files. */
function vibes_drive_move_file_tree($source_id, $destination_id, $name_override = '') {
    if (!$source_id || !$destination_id) { return new WP_Error('vibes_drive_move_missing_id', 'Drive source or destination is missing.'); }
    $meta = vibes_drive_api('GET', 'files/' . rawurlencode($source_id), null, ['fields' => 'id,name,mimeType,webViewLink,parents']);
    if (is_wp_error($meta)) { return $meta; }
    $parents = array_values(array_filter((array) ($meta['parents'] ?? [])));
    $query = ['addParents' => $destination_id, 'fields' => 'id,name,mimeType,webViewLink,parents'];
    $remove = array_values(array_diff($parents, [$destination_id]));
    if ($remove) { $query['removeParents'] = implode(',', $remove); }
    $body = $name_override ? ['name' => $name_override] : [];
    return vibes_drive_api('PATCH', 'files/' . rawurlencode($source_id), $body, $query);
}

function vibes_drive_file_name($file_id) {
    $meta = vibes_drive_api('GET', 'files/' . rawurlencode($file_id), null, ['fields' => 'name']);
    return is_wp_error($meta) ? 'Work' : ($meta['name'] ?? 'Work');
}

function vibes_drive_rename_if_generic($file_id, $current_name, $desired_name, $generic_names) {
    if (!$file_id || !$desired_name || strcasecmp((string) $current_name, (string) $desired_name) === 0) { return ['id' => $file_id, 'name' => $desired_name]; }
    foreach ($generic_names as $generic) {
        if (strcasecmp((string) $current_name, (string) $generic) === 0) {
            $updated = vibes_drive_api('PATCH', 'files/' . rawurlencode($file_id), ['name' => $desired_name], ['fields' => 'id,name,webViewLink,parents']);
            if (!is_wp_error($updated) && $updated) { return $updated; }
            break;
        }
    }
    return ['id' => $file_id, 'name' => $current_name ?: $desired_name];
}

function vibes_drive_id_from_url($url) {
    if (!is_string($url) || !$url) { return ''; }
    if (preg_match('#/folders/([A-Za-z0-9_-]+)#', $url, $match)) { return $match[1]; }
    if (preg_match('#[?&]id=([A-Za-z0-9_-]+)#', $url, $match)) { return $match[1]; }
    return '';
}

/**
 * Resolve a Drive folder URL to the exact Windows-mapped path used by the
 * Vibes Accounts Google Drive desktop mount. This walks the real Drive
 * parents instead of reconstructing names from a portal quote/project label,
 * which keeps legacy and newly-created folder trees working alike.
 */
function vibes_drive_mapped_local_path($folder_id) {
    $folder_id = sanitize_text_field((string) $folder_id);
    if (!$folder_id) { return new WP_Error('vibes_drive_path_missing_id', 'Drive folder ID is missing.'); }

    $segments = [];
    $seen = [];
    $current = $folder_id;
    $top_names = ['Client Work', 'Business Administration'];
    for ($depth = 0; $depth < 24 && $current; $depth++) {
        if (isset($seen[$current])) { break; }
        $seen[$current] = true;
        $meta = vibes_drive_api('GET', 'files/' . rawurlencode($current), null, ['fields' => 'id,name,mimeType,parents']);
        if (is_wp_error($meta)) { return $meta; }
        $name = trim((string) ($meta['name'] ?? ''));
        if ($name !== '') { array_unshift($segments, $name); }
        if (in_array($name, $top_names, true)) { break; }
        $next = '';
        foreach ((array) ($meta['parents'] ?? []) as $parent_id) {
            $parent_id = (string) $parent_id;
            if ($parent_id && !isset($seen[$parent_id])) { $next = $parent_id; break; }
        }
        if (!$next) { break; }
        $current = $next;
    }

    $top_index = null;
    foreach ($segments as $index => $segment) {
        if (in_array($segment, $top_names, true)) { $top_index = $index; break; }
    }
    if ($top_index === null) {
        return new WP_Error('vibes_drive_path_root_missing', 'The Drive folder is not under Client Work or Business Administration.');
    }
    $segments = array_slice($segments, $top_index);
    return 'C:\\Users\\mhdal\\My Drive (accounts@vibesagency.co)\\' . implode('\\', $segments);
}



function vibes_drive_file_id_from_url($url) {
    if (!is_string($url) || !$url) { return ''; }
    if (preg_match('#/file/d/([A-Za-z0-9_-]+)#', $url, $match)) { return $match[1]; }
    if (preg_match('#(?:[?&]id=)([A-Za-z0-9_-]+)#', $url, $match)) { return $match[1]; }
    return '';
}

function vibes_drive_quote_file_id(array $quote) {
    $direct = vibes_drive_file_id_from_url((string) ($quote['drive_file_url'] ?? ''));
    if ($direct) { return $direct; }
    global $wpdb;
    $master_id = (int) ($quote['parent_quotation_id'] ?: $quote['id']);
    $master = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'vtp_quotations WHERE id=%d', $master_id), ARRAY_A);
    if (!$master) { return ''; }
    if (!vibes_drive_quotations_root_id()) { return ''; }
    $client_part = vibes_drive_name_part($master['client_name'] ?? '', 'Client');
    $company_part = vibes_drive_name_part($master['company'] ?? '', '');
    $client = $company_part && strcasecmp($client_part, $company_part) !== 0 ? $client_part . '_' . $company_part : $client_part;
    $client_folder = vibes_drive_find_child(vibes_drive_quotations_root_id(), $client);
    if (is_wp_error($client_folder) || empty($client_folder['id'])) { return ''; }
    $family = vibes_drive_find_child($client_folder['id'], (string) $master['quote_number']);
    if (is_wp_error($family) || empty($family['id'])) { return ''; }
    $parent_name = ((int) ($quote['period_index'] ?? 0) > 0) ? 'Monthly quotations' : 'Master quotation';
    $parent = vibes_drive_find_child($family['id'], $parent_name);
    if (is_wp_error($parent) || empty($parent['id'])) { return ''; }
    $file = vibes_drive_find_child($parent['id'], sanitize_file_name($quote['quote_number'] . '.pdf'));
    return is_array($file) ? (string) ($file['id'] ?? '') : '';
}

function vibes_drive_quote_project_id(array $quote) {
    global $wpdb;
    $ids = [(int) ($quote['id'] ?? 0), (int) ($quote['parent_quotation_id'] ?? 0)];
    foreach (array_values(array_unique(array_filter($ids))) as $quotation_id) {
        $project_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'vtp_projects WHERE quotation_id=%d ORDER BY id DESC LIMIT 1', $quotation_id));
        if ($project_id) { return $project_id; }
    }
    return 0;
}

function vibes_drive_quotation_delete(array $quote) {
    $file_id = vibes_drive_quote_file_id($quote);
    if (!$file_id) { return ['status' => 'missing']; }
    $deleted = vibes_drive_api('DELETE', 'files/' . rawurlencode($file_id));
    if (is_wp_error($deleted)) {
        $data = $deleted->get_error_data();
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        if ($status === 404) { return ['status' => 'already_missing', 'file_id' => $file_id]; }
        return $deleted;
    }
    return ['status' => 'deleted', 'file_id' => $file_id];
}

/**
 * Delete the synchronized Drive PDF before the portal removes its quotation
 * row.  This keeps a successful portal deletion and Drive deletion together;
 * if Drive is unavailable, the REST request is stopped and the quotation is
 * left in the portal so it can be retried safely.
 */
function vibes_drive_prepare_quotation_delete($response, $handler, $request) {
    if (!$request instanceof WP_REST_Request || $request->get_method() !== 'DELETE') { return $response; }
    $route = $request->get_route();
    if (!preg_match('#/quotations/([0-9]+)$#', $route, $match)) { return $response; }
    global $wpdb;
    $quote_id = (int) $match[1];
    $quote = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'vtp_quotations WHERE id=%d', $quote_id), ARRAY_A);
    // Let the portal return its normal 404 when the row is already gone.
    if (!$quote) { return $response; }
    $deleted = vibes_drive_quotation_delete($quote);
    if (is_wp_error($deleted)) {
        // An administrator can choose to delete the portal record anyway; the Drive PDF is then left in place.
        if (!empty($request['force']) && current_user_can('manage_options')) {
            error_log('[vibes-drive] Quotation ' . $quote_id . ' deleted without removing its Drive PDF: ' . $deleted->get_error_message());
            return $response;
        }
        return new WP_Error(
            'vibes_drive_quotation_delete_failed',
            'The quotation could not be deleted because its Google Drive PDF could not be removed. Please retry after Drive reconnects.',
            ['status' => 502, 'drive_error' => $deleted->get_error_message()]
        );
    }
    return $response;
}
add_filter('rest_request_before_callbacks', 'vibes_drive_prepare_quotation_delete', 10, 3);

function vibes_drive_quotation_archive(array $quote, $project_id = 0) {
    global $wpdb;
    $project_id = (int) $project_id ?: vibes_drive_quote_project_id($quote);
    if (!$project_id) { return ['status' => 'pending', 'reason' => 'project_not_created']; }
    [$map, $project] = vibes_drive_project_map_for($project_id);
    if (!$project) { return ['status' => 'pending', 'reason' => 'project_drive_mapping_pending', 'project_id' => $project_id]; }
    $archive_id = (string) ($project['stages']['05 - Archive']['id'] ?? '');
    if (!$archive_id) { return new WP_Error('vibes_drive_quote_archive_stage_missing', 'Project Archive folder is missing.'); }
    $file_id = vibes_drive_quote_file_id($quote);
    if (!$file_id) { return ['status' => 'pending', 'reason' => 'quotation_file_not_found', 'project_id' => $project_id]; }
    $meta = vibes_drive_api('GET', 'files/' . rawurlencode($file_id), null, ['fields' => 'id,name,mimeType,webViewLink,parents']);
    if (is_wp_error($meta)) { return $meta; }
    $parents = array_values(array_filter((array) ($meta['parents'] ?? [])));
    if (in_array($archive_id, $parents, true)) {
        $archive_url = 'https://drive.google.com/drive/folders/' . $archive_id;
        $wpdb->update($wpdb->prefix . 'vtp_quotations', ['drive_folder_url' => $archive_url, 'updated_at' => current_time('mysql')], ['id' => (int) $quote['id']]);
        return ['status' => 'archived', 'file_id' => $file_id, 'archive_folder_id' => $archive_id, 'webViewLink' => $meta['webViewLink'] ?? ''];
    }
    $query = ['addParents' => $archive_id, 'fields' => 'id,name,mimeType,webViewLink,parents'];
    // Move the file from its quotation folder when Drive reports one parent.
    // If a file has multiple parents, preserve unrelated parents and only add the archive parent.
    if (count($parents) === 1) { $query['removeParents'] = $parents[0]; }
    $moved = vibes_drive_api('PATCH', 'files/' . rawurlencode($file_id), [], $query);
    if (is_wp_error($moved)) { return $moved; }
    $archive_url = 'https://drive.google.com/drive/folders/' . $archive_id;
    $wpdb->update($wpdb->prefix . 'vtp_quotations', ['drive_folder_url' => $archive_url, 'updated_at' => current_time('mysql')], ['id' => (int) $quote['id']]);
    return ['status' => 'archived', 'file_id' => $file_id, 'archive_folder_id' => $archive_id, 'webViewLink' => $moved['webViewLink'] ?? ($meta['webViewLink'] ?? '')];
}

function vibes_drive_recursive_value($data, $keys) {
    if (!is_array($data)) { return ''; }
    foreach ($keys as $key) {
        if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
            return trim((string) $data[$key]);
        }
    }
    foreach ($data as $value) {
        if (is_array($value)) {
            $found = vibes_drive_recursive_value($value, $keys);
            if ($found !== '') { return $found; }
        }
    }
    return '';
}

function vibes_drive_sync_portal_links($project_id, $internal_url, $client_url = '') {
    static $updating = false;
    if ($updating || !$project_id || !$internal_url) { return; }
    $updating = true;
    $request = new WP_REST_Request('PATCH', '/vibes/v1/projects/' . rawurlencode($project_id) . '/drive');
    $request->set_body_params(['internalDriveUrl' => $internal_url, 'clientDriveUrl' => $client_url]);
    rest_do_request($request);
    $updating = false;
}

function vibes_drive_internal_get($route) {
    $request = new WP_REST_Request('GET', $route);
    $response = rest_do_request($request);
    if (is_wp_error($response)) { return $response; }
    return $response instanceof WP_REST_Response ? $response->get_data() : $response;
}

function vibes_drive_create_folder($name, $parent_id) {
    return vibes_drive_api('POST', 'files', [
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parent_id],
    ], ['fields' => 'id,name,webViewLink,parents']);
}

function vibes_drive_list_children($parent_id) {
    $items = [];
    $page = '';
    do {
        $query = [
            'q' => sprintf("'%s' in parents and trashed = false", esc_sql($parent_id)),
            'pageSize' => 1000,
            'fields' => 'nextPageToken,files(id,name,mimeType,webViewLink)',
        ];
        if ($page) { $query['pageToken'] = $page; }
        $result = vibes_drive_api('GET', 'files', null, $query);
        if (is_wp_error($result)) { return $result; }
        $items = array_merge($items, $result['files'] ?? []);
        $page = $result['nextPageToken'] ?? '';
    } while ($page);
    return $items;
}

function vibes_drive_find_child($parent_id, $name) {
    $children = vibes_drive_list_children($parent_id);
    if (is_wp_error($children)) { return $children; }
    foreach ($children as $child) {
        if (($child['name'] ?? '') === $name) { return $child; }
    }
    return [];
}

function vibes_drive_clone_template($template_id, $destination_id) {
    $children = vibes_drive_list_children($template_id);
    if (is_wp_error($children)) { return $children; }
    $created = [];
    foreach ($children as $child) {
        if (($child['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') { continue; }
        // Reuse an existing stage folder when a project is being repaired or
        // when the plugin is reactivated. This prevents duplicate stage trees.
        $folder = vibes_drive_find_child($destination_id, $child['name']);
        if (!$folder) { $folder = vibes_drive_create_folder($child['name'], $destination_id); }
        if (is_wp_error($folder)) { return $folder; }
        $nested = vibes_drive_clone_template($child['id'], $folder['id']);
        if (is_wp_error($nested)) { return $nested; }
        $created[$child['name']] = $folder;
    }
    return $created;
}

function vibes_drive_pick($data, $keys, $fallback = '') {
    foreach ($keys as $key) {
        if (is_array($data) && isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
            return trim((string) $data[$key]);
        }
    }
    return $fallback;
}

function vibes_drive_name_part($value, $fallback = '') {
    $value = sanitize_file_name((string) $value);
    $value = preg_replace('/[^A-Za-z0-9]+/', '', (string) $value);
    return $value ?: $fallback;
}

function vibes_drive_project_details($data) {
    if (!is_array($data)) { return []; }
    $data = $data['project'] ?? $data['data'] ?? $data;
    if (!is_array($data)) { return []; }
    $client = $data['client'] ?? [];
    if (is_string($client)) { $client = ['name' => $client]; }
    $client_part = vibes_drive_name_part(vibes_drive_pick($data, ['client_name', 'clientName'], vibes_drive_pick((array) $client, ['name', 'title'], 'Client')), 'Client');
    $company_part = vibes_drive_name_part(vibes_drive_pick($data, ['company'], vibes_drive_pick((array) $client, ['company'], '')), '');
    $client_name = $company_part && strcasecmp($client_part, $company_part) !== 0 ? $client_part . '_' . $company_part : $client_part;
    $project_no = vibes_drive_pick($data, ['project_number', 'projectNumber', 'reference', 'quotation_number', 'number', 'code', 'name', 'id'], 'Project');
    $structure = $data['driveStructure'] ?? $data['drive_structure'] ?? [];
    if (!$structure && !empty($data['drive_structure_json']) && is_string($data['drive_structure_json'])) {
        $decoded = json_decode($data['drive_structure_json'], true);
        if (is_array($decoded)) { $structure = $decoded; }
    }
    $services = $data['services'] ?? $data['service_items'] ?? $data['items'] ?? [];
    if (!$services && is_array($structure)) {
        foreach (($structure['months'] ?? []) as $month) {
            foreach (($month['services'] ?? []) as $service) { $services[] = $service; }
        }
    }
    if (!is_array($services)) { $services = []; }
    return [
        'id' => vibes_drive_pick($data, ['id', 'project_id', 'projectId']),
        'quotation_id' => vibes_drive_pick($data, ['quotation_id', 'quotationId']),
        'client_name' => $client_name,
        'project_number' => $project_no,
        'services' => $services,
        'drive_structure' => $structure,
        'raw' => $data,
    ];
}

function vibes_drive_normalize_name($value) {
    $value = strtolower((string) $value);
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function vibes_drive_service_folder($working_id, $service) {
    $service = (array) $service;
    $label = vibes_drive_pick($service, ['label', 'service_label', 'serviceLabel', 'code'], '');
    $name = vibes_drive_pick($service, ['name', 'service', 'title', 'deliverable'], 'Service');
    $desired = vibes_drive_pick($service, ['folderName', 'folder_name'], '');
    if (!$desired) { $desired = $label ? ($label . '_' . $name) : $name; }
    $desired = vibes_drive_safe_name($desired, 'Service');
    $existing = vibes_drive_find_child($working_id, $desired);
    if (is_wp_error($existing)) { return $existing; }
    if (!$existing) {
        $children = vibes_drive_list_children($working_id);
        if (is_wp_error($children)) { return $children; }
        $needle = vibes_drive_normalize_name($name);
        $label_needle = vibes_drive_normalize_name($label);
        foreach ($children as $child) {
            if (($child['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') { continue; }
            $candidate = vibes_drive_normalize_name($child['name'] ?? '');
            if (($label_needle && strpos($candidate, $label_needle) === 0) || ($needle && strpos($candidate, $needle) !== false)) {
                $existing = $child;
                break;
            }
        }
    }
    return $existing ?: vibes_drive_create_folder($desired, $working_id);
}

function vibes_drive_task_file_url($payload) {
    if (!is_array($payload)) { return ''; }
    $files = $payload['files'] ?? $payload['attachments'] ?? [];
    if (is_array($files)) {
        foreach ($files as $file) {
            if (is_array($file)) {
                foreach (['url', 'drive_url', 'driveUrl', 'folder_url', 'folderUrl'] as $key) {
                    if (!empty($file[$key]) && is_string($file[$key])) { return $file[$key]; }
                }
            }
        }
    }
    return vibes_drive_recursive_value($payload, ['drive_url', 'driveUrl', 'folder_url', 'folderUrl']);
}

function vibes_drive_project_sync($payload) {
    $details = vibes_drive_project_details($payload);
    if (!empty($details['quotation_id'])) {
        $quotation = vibes_drive_internal_get('/vibes/v1/quotations/' . rawurlencode($details['quotation_id']));
        if (is_array($quotation)) {
            $qdetails = vibes_drive_project_details($quotation);
            foreach (['client_name', 'project_number', 'services'] as $key) {
                if (($details[$key] === 'Client' || $details[$key] === 'Project' || empty($details[$key])) && !empty($qdetails[$key])) {
                    $details[$key] = $qdetails[$key];
                }
            }
        }
    }
    if (empty($details['id'])) { return new WP_Error('vibes_drive_project_id_missing', 'Project ID missing.'); }
    // Name the project folder after the quotation number, never the bare numeric project id.
    if (!empty($quotation) && is_array($quotation) && !empty($quotation['quote_number'])) { $details['project_number'] = $quotation['quote_number']; }
    $map = vibes_drive_map();
    $existing = $map[$details['id']] ?? [];
    $s = vibes_drive_settings();
    $client_name = vibes_drive_name_part($details['client_name'], 'Client');
    $client_folder = [];
    if (!empty($existing['client_folder_id'])) {
        $current_client_name = vibes_drive_file_name($existing['client_folder_id']);
        $client_folder = vibes_drive_rename_if_generic($existing['client_folder_id'], $current_client_name, $client_name, ['Client']);
    }
    if (!$client_folder || empty($client_folder['id'])) { $client_folder = vibes_drive_find_child($s['clients_folder_id'], $client_name); }
    if (is_wp_error($client_folder)) { return $client_folder; }
    if (!$client_folder) { $client_folder = vibes_drive_create_folder($client_name, $s['clients_folder_id']); }
    if (is_wp_error($client_folder)) { return $client_folder; }
    $project_name = vibes_drive_safe_name($details['project_number'], 'Project');
    $project_folder = [];
    if (!empty($existing['project_folder_id'])) {
        $current_project_name = vibes_drive_file_name($existing['project_folder_id']);
        $project_folder = vibes_drive_rename_if_generic($existing['project_folder_id'], $current_project_name, $project_name, ['Project', (string) $details['id']]);
        $project_folder['webViewLink'] = $existing['project_folder_url'] ?? ('https://drive.google.com/drive/folders/' . $existing['project_folder_id']);
    }
    if (!$project_folder || empty($project_folder['id'])) { $project_folder = vibes_drive_find_child($client_folder['id'], $project_name); }
    if (!$project_folder) {
        // Older runs may have created the folder from the full project name
        // while newer payloads expose only the quotation number. Reuse that
        // folder instead of creating a second project tree.
        $children = vibes_drive_list_children($client_folder['id']);
        if (is_wp_error($children)) { return $children; }
        $project_needle = vibes_drive_normalize_name($project_name);
        $full_name = vibes_drive_normalize_name(vibes_drive_pick($details['raw'], ['name', 'project_name', 'projectName'], ''));
        foreach ($children as $child) {
            if (($child['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') { continue; }
            $candidate = vibes_drive_normalize_name($child['name'] ?? '');
            if (($project_needle && strpos($candidate, $project_needle) !== false) || ($full_name && strpos($candidate, $full_name) !== false)) {
                $project_folder = $child;
                break;
            }
        }
    }
    if (!$project_folder) { $project_folder = vibes_drive_create_folder($project_name, $client_folder['id']); }
    if (is_wp_error($project_folder)) { return $project_folder; }
    $stages = vibes_drive_clone_template($s['template_folder_id'], $project_folder['id']);
    if (is_wp_error($stages)) { return $stages; }
    // If the Drive template is empty or missing stages, create the standard stage folders so the pipeline never silently skips them.
    foreach (['01 - Working', '02 - Admin Review', '03 - Client Approval', '04 - Client Approved', '05 - Archive'] as $stage_name) {
        if (!empty($stages[$stage_name]['id'])) { continue; }
        $stage_folder = vibes_drive_find_child($project_folder['id'], $stage_name);
        if (!$stage_folder) { $stage_folder = vibes_drive_create_folder($stage_name, $project_folder['id']); }
        if (is_wp_error($stage_folder)) { return $stage_folder; }
        $stages[$stage_name] = $stage_folder;
    }
    $working_id = $stages['01 - Working']['id'] ?? $project_folder['id'];
    $services = $existing['services'] ?? [];
    foreach ($details['services'] as $service) {
        if (is_string($service)) { $service = ['name' => $service]; }
        $service = (array) $service;
        $name = vibes_drive_pick($service, ['name', 'service', 'title', 'label'], 'Service');
        $label = vibes_drive_pick($service, ['label', 'service_label', 'serviceLabel', 'code'], '');
        // Employees sign in to Drive with their own personal Google account, not
        // their @vibesagency.co address, so sharing must use the personal email
        // on file for the assignee rather than any vibes-email field the payload carries.
        $assignee_id = (int) vibes_drive_pick($service, ['assignee_id', 'assigneeId', 'employee_id', 'employeeId'], 0);
        $email = $assignee_id ? (string) get_user_meta($assignee_id, 'vtp_personal_email', true) : '';
        // A service folder that was already submitted has moved on to a later stage; never recreate an empty copy in 01 - Working.
        $submitted_sources = array_filter(array_map(static fn($sub) => (string) ($sub['source_folder_id'] ?? ''), (array) ($existing['submissions'] ?? [])));
        $already_submitted = false;
        foreach ($services as $saved_chk) {
            $same = strcasecmp((string) ($saved_chk['name'] ?? ''), $name) === 0 || ($label && strcasecmp((string) ($saved_chk['label'] ?? ''), $label) === 0);
            if ($same && !empty($saved_chk['folder_id']) && in_array((string) $saved_chk['folder_id'], $submitted_sources, true)) { $already_submitted = true; break; }
        }
        if ($already_submitted) { continue; }
        $folder = vibes_drive_service_folder($working_id, $service);
        if (is_wp_error($folder)) { return $folder; }
        $matched = false;
        foreach ($services as &$saved) {
            if (strcasecmp((string) ($saved['name'] ?? ''), $name) === 0 || ($label && strcasecmp((string) ($saved['label'] ?? ''), $label) === 0)) {
                $saved = array_merge($saved, ['name' => $name, 'label' => $label, 'folder_id' => $folder['id'], 'folder_url' => $folder['webViewLink'] ?? ('https://drive.google.com/drive/folders/' . $folder['id']), 'employee_email' => $email]);
                $matched = true;
                break;
            }
        }
        unset($saved);
        if ($matched) { continue; }
        $services[] = ['name' => $name, 'label' => $label, 'folder_id' => $folder['id'], 'folder_url' => $folder['webViewLink'] ?? ('https://drive.google.com/drive/folders/' . $folder['id']), 'employee_email' => $email];
    }
    // Give every employee assigned to this project writer access to the whole
    // project folder, using each employee's personal Google account (not their
    // @vibesagency.co address). Tracked by email so a repeat sync never re-invites
    // someone already shared in.
    $shared_emails = (array) ($existing['project_shared_emails'] ?? []);
    foreach ($services as $svc) {
        $svc_email = strtolower(trim((string) ($svc['employee_email'] ?? '')));
        if (!$svc_email || !is_email($svc_email) || in_array($svc_email, $shared_emails, true)) { continue; }
        $share = vibes_drive_api('POST', 'files/' . rawurlencode($project_folder['id']) . '/permissions', ['type' => 'user', 'role' => 'writer', 'emailAddress' => $svc_email], ['sendNotificationEmail' => 'true']);
        if (!is_wp_error($share)) { $shared_emails[] = $svc_email; }
    }
    $map[$details['id']] = [
        'client_folder_id' => $client_folder['id'],
        'project_folder_id' => $project_folder['id'],
        'project_folder_url' => $project_folder['webViewLink'] ?? 'https://drive.google.com/drive/folders/' . $project_folder['id'],
        'stages' => $stages,
        'services' => $services,
        'project_shared_emails' => $shared_emails,
        'client_name' => $client_name,
        'project_name' => $project_name,
        'raw' => $details['raw'] ?? [],
        'submissions' => $existing['submissions'] ?? [],
        'created_at' => current_time('mysql', true),
    ];
    vibes_drive_save_map($map);
    vibes_drive_sync_portal_links($details['id'], $map[$details['id']]['project_folder_url'], '');
    return $map[$details['id']];
}

function vibes_drive_project_map_for($project_id) {
    $map = vibes_drive_map();
    return [$map, $map[$project_id] ?? []];
}

function vibes_drive_project_id_for_task($task_id) {
    if (!$task_id) { return ''; }
    foreach (vibes_drive_map() as $project_id => $project) {
        foreach (($project['submissions'] ?? []) as $submission) {
            if ((string) ($submission['task_id'] ?? '') === (string) $task_id) { return (string) $project_id; }
        }
    }
    return '';
}

function vibes_drive_task_sync($payload, $action = 'submit') {
    $project_id = vibes_drive_recursive_value((array) $payload, ['project_id', 'projectId', 'parent_project_id', 'parentProjectId']);
    if (!$project_id) { return new WP_Error('vibes_drive_task_project_missing', 'Project ID missing for task.'); }
    [$map, $project] = vibes_drive_project_map_for($project_id);
    $task_id = vibes_drive_recursive_value((array) $payload, ['id', 'task_id', 'taskId']);
    if (!$project) {
        $project = vibes_drive_project_sync([
            'id' => $project_id,
            'client_name' => vibes_drive_recursive_value((array) $payload, ['client_name', 'clientName', 'client']),
            'project_number' => vibes_drive_recursive_value((array) $payload, ['project_name', 'projectName', 'project_number', 'projectNumber']) ?: ('Project ' . $project_id),
            'services' => [$payload],
        ]);
        if (is_wp_error($project)) { return $project; }
        [$map, $project] = vibes_drive_project_map_for($project_id);
    }
    $folder_id = vibes_drive_id_from_url(vibes_drive_task_file_url((array) $payload));
    if (!$folder_id) {
        $service = vibes_drive_recursive_value((array) $payload, ['service', 'service_name', 'serviceName', 'title', 'name']);
        $label = vibes_drive_recursive_value((array) $payload, ['service_label', 'serviceLabel', 'label']);
        foreach (($project['services'] ?? []) as $candidate) {
            if (($service && strcasecmp((string) ($candidate['name'] ?? ''), $service) === 0) || ($label && strcasecmp((string) ($candidate['label'] ?? ''), $label) === 0)) { $folder_id = $candidate['folder_id'] ?? ''; break; }
        }
    }
    if (!$folder_id) { return new WP_Error('vibes_drive_task_folder_missing', 'Task Drive folder missing.'); }
    $parent = $project['stages']['02 - Admin Review']['id'] ?? '';
    if (!$parent) { return new WP_Error('vibes_drive_review_stage_missing', 'Admin Review folder missing.'); }
    $task_name = vibes_drive_safe_name(vibes_drive_file_name($folder_id), 'Task');
    $copy = vibes_drive_move_file_tree($folder_id, $parent, $task_name);
    if (is_wp_error($copy)) { return $copy; }
    $project['submissions'] = $project['submissions'] ?? [];
    $project['submissions'][$task_id ?: wp_generate_uuid4()] = [
        'task_id' => $task_id,
        'source_folder_id' => $folder_id,
        'admin_review_folder_id' => $copy['id'],
        'admin_review_url' => $copy['webViewLink'] ?? 'https://drive.google.com/drive/folders/' . $copy['id'],
        'submitted_at' => current_time('mysql', true),
    ];
    $map[$project_id] = $project;
    vibes_drive_save_map($map);
    return $project['submissions'];
}

function vibes_drive_task_lookup($task_id) {
    if (!$task_id) { return []; }
    $detail = vibes_drive_internal_get('/vibes/v1/tasks/' . rawurlencode($task_id));
    if (is_array($detail)) {
        $row = $detail['data'] ?? $detail;
        if (is_array($row) && (string) ($row['id'] ?? '') === (string) $task_id) { return $row; }
    }
    $list = vibes_drive_internal_get('/vibes/v1/tasks');
    $rows = is_array($list) ? ($list['data'] ?? $list) : [];
    if (!is_array($rows)) { return []; }
    foreach ($rows as $row) {
        if (is_array($row) && (string) ($row['id'] ?? '') === (string) $task_id) { return $row; }
    }
    return [];
}

function vibes_drive_review_sync($payload, $decision = '') {
    $project_id = vibes_drive_recursive_value((array) $payload, ['project_id', 'projectId', 'id']);
    $task_id = vibes_drive_recursive_value((array) $payload, ['task_id', 'taskId']);
    if (!$project_id && $task_id) { $project_id = vibes_drive_project_id_for_task($task_id); }
    if (!$project_id) { return new WP_Error('vibes_drive_review_project_missing', 'Project ID missing for review.'); }
    [$map, $project] = vibes_drive_project_map_for($project_id);
    if (!$project) { return new WP_Error('vibes_drive_review_project_unmapped', 'Project has no Drive mapping.'); }
    $decision = strtolower($decision ?: vibes_drive_recursive_value((array) $payload, ['decision', 'status', 'review_status', 'reviewStatus', 'outcome']));
    $submissions = $project['submissions'] ?? [];
    $submission_key = null;
    if ($task_id) {
        foreach ($submissions as $key => $candidate) {
            if ((string) ($candidate['task_id'] ?? '') === (string) $task_id) { $submission_key = $key; break; }
        }
    }
    if ($submission_key === null) { $submission_key = array_key_last($submissions); }
    $submission = $submission_key !== null ? $submissions[$submission_key] : [];
    $review_folder = $submission['admin_review_folder_id'] ?? '';
    if (!$review_folder) { return new WP_Error('vibes_drive_review_folder_missing', 'No Admin Review folder found.'); }
    $is_revision = (bool) preg_match('/draft|amend|revision|changes|reject|needs[_ -]?review/', $decision);
    $target_key = $is_revision ? '01 - Working' : '03 - Client Approval';
    $parent = $project['stages'][$target_key]['id'] ?? '';
    if (!$parent) { return new WP_Error('vibes_drive_stage_missing', 'Target Drive stage missing.'); }
    $name = vibes_drive_safe_name(vibes_drive_file_name($review_folder), 'Task');
    if ($is_revision) { $name .= '_draft_' . gmdate('dFY'); }
    $copy = vibes_drive_move_file_tree($review_folder, $parent, $name);
    if (is_wp_error($copy)) { return $copy; }
    $submission['status'] = $is_revision ? 'draft' : 'client_approval';
    $submission['current_folder_id'] = $copy['id'];
    $submission['current_folder_url'] = $copy['webViewLink'] ?? 'https://drive.google.com/drive/folders/' . $copy['id'];
    if ($submission_key !== null) { $project['submissions'][$submission_key] = $submission; }
    $map[$project_id] = $project;
    vibes_drive_save_map($map);
    return $submission;
}

function vibes_drive_archive_sync($payload) {
    $project_id = vibes_drive_recursive_value((array) $payload, ['project_id', 'projectId', 'id']);
    $task_id = vibes_drive_recursive_value((array) $payload, ['task_id', 'taskId']);
    if (!$project_id && $task_id) { $project_id = vibes_drive_project_id_for_task($task_id); }
    if (!$project_id) { return new WP_Error('vibes_drive_archive_project_missing', 'Project ID missing for archive.'); }
    [$map, $project] = vibes_drive_project_map_for($project_id);
    if (!$project) { return new WP_Error('vibes_drive_archive_project_unmapped', 'Project has no Drive mapping.'); }
    $submissions = $project['submissions'] ?? [];
    if (!$submissions) { return new WP_Error('vibes_drive_archive_folder_missing', 'No approved folder found.'); }
    $approved_parent = $project['stages']['04 - Client Approved']['id'] ?? '';
    $archive_parent = $project['stages']['05 - Archive']['id'] ?? '';
    if (!$approved_parent || !$archive_parent) { return new WP_Error('vibes_drive_archive_stage_missing', 'Approved or Archive folder missing.'); }
    foreach ($submissions as $submission_key => $submission) {
        $source = $submission['current_folder_id'] ?? $submission['admin_review_folder_id'] ?? '';
        if (!$source) { continue; }
        $approved = vibes_drive_copy_file_tree($source, $approved_parent);
        if (is_wp_error($approved)) { return $approved; }
        $archived = vibes_drive_copy_file_tree($source, $archive_parent);
        if (is_wp_error($archived)) { return $archived; }
        $submission['status'] = 'client_approved';
        $submission['approved_folder_id'] = $approved['id'];
        $submission['archive_folder_id'] = $archived['id'];
        $project['submissions'][$submission_key] = $submission;
    }
    $map[$project_id] = $project;
    vibes_drive_save_map($map);
    return $submission;
}

function vibes_drive_route_sync($response, $handler, $request) {
    $route = $request->get_route();
    if (strpos($route, '/vibes/v1/') !== 0 || is_wp_error($response)) { return $response; }
    $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
    if (preg_match('#/inquiries$#', $route) && $request->get_method() === 'POST' && is_array($data) && !empty($data['id'])) {
        global $wpdb;
        $inquiry_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'vtp_inquiries WHERE id=%d', (int) $data['id']), ARRAY_A);
        if ($inquiry_row) { vibes_drive_inquiry_sync($inquiry_row); }
    }
    if (preg_match('#/quotations$#', $route) && $request->get_method() === 'POST' && is_array($data) && !empty($data['id'])) {
        global $wpdb;
        $quote_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'vtp_quotations WHERE id=%d', (int) $data['id']), ARRAY_A);
        if ($quote_row) { vibes_drive_quotation_sync($quote_row); }
    }
    if (preg_match('#/projects$#', $route) && is_array($data)) {
        foreach ($data as $project_row) {
            if (is_array($project_row) && !empty($project_row['id'])) { vibes_drive_project_sync($project_row); }
        }
    }
    if (preg_match('#/tasks$#', $route) && is_array($data)) {
        $by_project = [];
        foreach ($data as $task_row) {
            if (!is_array($task_row)) { continue; }
            $pid = vibes_drive_recursive_value($task_row, ['project_id', 'projectId']);
            if ($pid) { $by_project[$pid][] = $task_row; }
        }
        foreach ($by_project as $pid => $tasks) {
            [$map, $project] = vibes_drive_project_map_for($pid);
            $first = $tasks[0];
            $needs_sync = !$project || count($project['services'] ?? []) < count($tasks) || in_array(($project['client_name'] ?? ''), ['', 'Client'], true) || in_array(($project['project_name'] ?? ''), ['', 'Project'], true);
            if (!$project || $needs_sync) {
                vibes_drive_project_sync([
                    'id' => $pid,
                    'client_name' => $first['client_name'] ?? 'Client',
                    'project_number' => $first['project_name'] ?? ('Project ' . $pid),
                    'services' => $tasks,
                ]);
            }
        }
    }
    if (preg_match('#/quotations/([0-9]+)/project$#', $route, $project_match)) {
        global $wpdb;
        $quotation_id = (int) $project_match[1];
        $project_id = (int) ($data['projectId'] ?? $data['project_id'] ?? 0);
        $project_payload = is_array($data) ? $data : [];
        if ($project_id) { $project_payload['id'] = $project_id; }
        $project_payload['quotation_id'] = $quotation_id;
        $project_map = vibes_drive_project_sync($project_payload);
        if (!is_wp_error($project_map) && $project_id) {
            $quote = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'vtp_quotations WHERE id=%d', $quotation_id), ARRAY_A);
            if ($quote) {
                $master_id = (int) ($quote['parent_quotation_id'] ?: $quote['id']);
                $family = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'vtp_quotations WHERE (id=%d OR parent_quotation_id=%d) AND status=%s ORDER BY period_index ASC', $master_id, $master_id, 'approved'), ARRAY_A);
                foreach ($family as $approved_quote) { vibes_drive_quotation_archive($approved_quote, $project_id); }
            }
        }
    }
    if (preg_match('#/quotations/([0-9]+)/status$#', $route, $status_match) && $request->get_method() === 'PATCH') {
        global $wpdb;
        $status = sanitize_key($request['status']);
        if ($status === 'approved') {
            $quote = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'vtp_quotations WHERE id=%d', (int) $status_match[1]), ARRAY_A);
            if ($quote) { vibes_drive_quotation_archive($quote); }
        }
    }
    if (preg_match('#/public/quotations/[^/]+/respond$#', $route) && $request->get_method() === 'POST') {
        global $wpdb;
        $action = sanitize_key($request['action']);
        if ($action === 'approve') {
            $token = sanitize_key($request['token']);
            $quote = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'vtp_quotations WHERE approval_token=%s', $token), ARRAY_A);
            if ($quote) { vibes_drive_quotation_archive($quote); }
        }
    }
    if (preg_match('#/tasks/([^/]+)/submit$#', $route, $match)) {
        $request_data = $request->get_json_params();
        if (!is_array($request_data)) { $request_data = $request->get_params(); }
        if (is_array($request_data)) { $data = array_merge($request_data, (array) $data); }
        if (is_array($data)) { $data['task_id'] = $data['task_id'] ?? $match[1]; }
        $task_details = vibes_drive_task_lookup($match[1]);
        if (is_array($task_details) && $task_details) {
            $data = array_merge($task_details, (array) $data);
            $data['task_id'] = $data['task_id'] ?? $match[1];
        }
        vibes_drive_task_sync($data, 'submit');
    }
    if (preg_match('#/tasks/([^/]+)/status$#', $route, $match)) {
        $request_data = $request->get_json_params();
        if (!is_array($request_data)) { $request_data = $request->get_params(); }
        if (is_array($request_data)) { $data = array_merge($request_data, (array) $data); }
        if (is_array($data)) { $data['task_id'] = $data['task_id'] ?? $match[1]; }
        $status = strtolower(vibes_drive_recursive_value((array) $data, ['status', 'task_status', 'taskStatus', 'outcome']));
        if (in_array($status, ['done', 'completed', 'approved', 'revision', 'needs_revision', 'changes_requested', 'rejected'], true)) {
            $task_details = vibes_drive_task_lookup($match[1]);
            if (is_array($task_details) && $task_details) { $data = array_merge($task_details, (array) $data); }
            $data['task_id'] = $data['task_id'] ?? $match[1];
            vibes_drive_review_sync($data, $status);
        }
    }
    if (preg_match('#/projects/([^/]+)/review$#', $route, $match)) {
        if (is_array($data)) { $data['project_id'] = $data['project_id'] ?? $match[1]; }
        vibes_drive_review_sync($data);
    }
    if (preg_match('#/projects/([^/]+)/archive$#', $route, $match)) {
        if (is_array($data)) { $data['project_id'] = $data['project_id'] ?? $match[1]; }
        vibes_drive_archive_sync($data);
    }
    if (preg_match('#/public/projects/[^/]+/respond$#', $route) && is_array($data)) {
        $decision = strtolower(vibes_drive_recursive_value($data, ['decision', 'status', 'response', 'outcome']));
        if (preg_match('/approve|approved|accept/', $decision)) { vibes_drive_archive_sync($data); }
        elseif ($decision) { vibes_drive_review_sync($data, $decision); }
    }
    return $response;
}
add_filter('rest_request_after_callbacks', 'vibes_drive_route_sync', 10, 3);

add_action('rest_api_init', static function () {
    register_rest_route('vibes/v1', '/drive/mapped-path', [
        'methods' => 'GET',
        'permission_callback' => static function () { return current_user_can('manage_options'); },
        'args' => [
            'folderUrl' => ['required' => false, 'sanitize_callback' => 'esc_url_raw'],
            'folderId' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
        ],
        'callback' => static function (WP_REST_Request $request) {
            $folder_id = vibes_drive_id_from_url((string) $request->get_param('folderUrl'));
            if (!$folder_id) { $folder_id = sanitize_text_field((string) $request->get_param('folderId')); }
            $path = vibes_drive_mapped_local_path($folder_id);
            if (is_wp_error($path)) { return $path; }
            return ['folder_id' => $folder_id, 'mapped_path' => $path];
        },
    ]);

    // TEMPORARY debug endpoint: trash a single folder by id, surfacing the
    // full Drive API error data. Remove after use.
    register_rest_route('vibes-drive/v1', '/trash-folder', [
        'methods' => 'POST',
        'permission_callback' => static function () { return current_user_can('manage_options'); },
        'callback' => static function (WP_REST_Request $request) {
            $folder_id = sanitize_text_field((string) $request->get_param('folder_id'));
            if (!$folder_id) { return new WP_Error('missing_folder_id', 'folder_id required.'); }
            $trash = vibes_drive_api('PATCH', 'files/' . rawurlencode($folder_id), ['trashed' => true]);
            if (is_wp_error($trash)) {
                return ['ok' => false, 'error' => $trash->get_error_message(), 'data' => $trash->get_error_data()];
            }
            return ['ok' => true, 'result' => $trash];
        },
    ]);

    // TEMPORARY one-off cleanup endpoint: wipes all currently-mapped test
    // projects (portal rows + tasks + their Drive folders). Remove after use.
    register_rest_route('vibes-drive/v1', '/wipe-test-projects', [
        'methods' => 'POST',
        'permission_callback' => static function () { return current_user_can('manage_options'); },
        'callback' => static function () {
            $map = vibes_drive_map();
            $project_ids = array_keys($map);
            $client_ids = [];
            $drive_results = [];
            foreach ($map as $pid => $entry) {
                if (!empty($entry['client_folder_id'])) { $client_ids[$entry['client_folder_id']] = true; }
                if (!empty($entry['project_folder_id'])) {
                    $trash = vibes_drive_api('PATCH', 'files/' . rawurlencode($entry['project_folder_id']), ['trashed' => true]);
                    $drive_results['project_' . $pid] = is_wp_error($trash) ? $trash->get_error_message() : 'trashed';
                }
            }
            foreach (array_keys($client_ids) as $cid) {
                $trash = vibes_drive_api('PATCH', 'files/' . rawurlencode($cid), ['trashed' => true]);
                $drive_results['client_' . $cid] = is_wp_error($trash) ? $trash->get_error_message() : 'trashed';
            }
            $deleted_projects = 0;
            $deleted_tasks = 0;
            foreach ($project_ids as $pid) {
                $response = rest_do_request(new WP_REST_Request('DELETE', '/vibes/v1/projects/' . (int) $pid));
                if (!is_wp_error($response) && $response->get_status() < 300) {
                    $data = $response->get_data();
                    $deleted_projects++;
                    $deleted_tasks += (int) ($data['deletedTasks'] ?? 0);
                } else {
                    $drive_results['portal_delete_' . $pid] = is_wp_error($response) ? $response->get_error_message() : wp_json_encode($response->get_data());
                }
            }
            vibes_drive_save_map([]);
            return ['deleted_projects' => $deleted_projects, 'deleted_tasks' => $deleted_tasks, 'drive_results' => $drive_results];
        },
    ]);

    register_rest_route('vibes-drive/v1', '/status', [
        'methods' => 'GET',
        'permission_callback' => static function () { return current_user_can('manage_options'); },
        'callback' => static function () {
            $s = vibes_drive_settings();
            return [
                'connected' => !empty($s['refresh_token']),
                'working' => (static function () use ($s) {
                    if (empty($s['refresh_token'])) return false;
                    $cached = get_transient('vibes_drive_working');
                    if ($cached !== false) return $cached === 'yes';
                    $ok = !is_wp_error(vibes_drive_access_token());
                    set_transient('vibes_drive_working', $ok ? 'yes' : 'no', 10 * MINUTE_IN_SECONDS);
                    return $ok;
                })(),
                'root_folder_id' => $s['root_folder_id'],
                'projects_mapped' => count(vibes_drive_map()),
                'employees_missing_personal_email' => (static function () {
                    $missing = 0;
                    foreach (get_users(['fields' => ['ID']]) as $u) {
                        if (!get_user_meta($u->ID, 'vtp_personal_email', true)) { $missing++; }
                    }
                    return $missing;
                })(),
            ];
        },
    ]);
});

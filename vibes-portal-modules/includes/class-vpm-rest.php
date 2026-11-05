<?php

defined('ABSPATH') || exit;

final class VPM_REST {
    private const NS = 'vibes/v1';

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('rest_request_after_callbacks', [self::class, 'observe_core_workflow'], 20, 3);
        add_filter('rest_pre_serve_request', [self::class, 'serve_binary_export'], 10, 4);
    }

    private static function table(string $name): string { return VPM_Permissions::table($name); }
    private static function body(WP_REST_Request $request): array {
        $body = $request->get_json_params();
        return is_array($body) ? $body : $request->get_params();
    }
    private static function user_name(int $id): string {
        $user = get_user_by('id', $id);
        return $user ? $user->display_name : 'Team member';
    }
    private static function avatar_url(int $id, int $size = 96): string {
        return method_exists('VTP_REST', 'avatar_url') ? (string) VTP_REST::avatar_url($id, $size) : (string) get_avatar_url($id, ['size' => $size]);
    }
    private static function json_array($value): array {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
    private static function page(WP_REST_Request $request): array {
        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = min(100, max(10, absint($request->get_param('perPage') ?: 50)));
        return [$page, $per_page, ($page - 1) * $per_page];
    }
    private static function error(string $code, string $message, int $status = 400): WP_Error {
        return new WP_Error($code, $message, ['status' => $status]);
    }

    // ======================= Prospects CRM (2026-11) =======================
    private const PROSPECT_FIELDS = ['name','sector','city','priority','why','pitch','source','website','instagram','email','phone','contactNote','emailSubject','emailBody','addedBy'];
    private const REQUEST_FIELDS = ['title','type','platform','need','fit','poster','location','budget','postedAt','deadline','howToRespond','url','countryVerified','addedBy'];
    private const ENRICH_FIELDS = ['website','instagram','email','phone','contactNote','emailSubject','emailBody'];
    private const PROSPECT_STATUSES = ['New','Contacted','Replied','Won','Not a fit'];
    private const REQUEST_STATUSES = ['New','Applied','In talks','Won','Not a fit'];

    private static function no_cache(): void {
        if (function_exists('nocache_headers')) nocache_headers();
        do_action('litespeed_control_set_nocache', 'vibes prospects api');
    }

    public static function agent_auth(WP_REST_Request $request): bool {
        $key = (string) get_option('vpm_agent_key', '');
        $given = (string) $request->get_header('x-vibes-agent-key');
        return $key !== '' && $given !== '' && hash_equals($key, $given);
    }

    private static function prospect_row(array $row): array {
        $data = self::json_array($row['data'] ?? '');
        return array_merge($data, [
            'id' => (int) $row['id'], 'kind' => $row['kind'], 'slug' => $row['slug'], 'status' => $row['status'],
            'priority' => $row['priority'] ?: ($data['priority'] ?? ''), 'sector' => $row['sector'] ?: ($data['sector'] ?? ''),
            'added' => $row['added'], 'updatedAt' => $row['updated_at'],
        ]);
    }

    private static function clean_fields(array $item, string $kind): array {
        $allowed = $kind === 'request' ? self::REQUEST_FIELDS : self::PROSPECT_FIELDS;
        $out = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $item)) continue;
            $value = $item[$field];
            if ($field === 'countryVerified') { $out[$field] = (bool) $value; continue; }
            $value = is_scalar($value) ? (string) $value : '';
            if (in_array($field, ['website','instagram','source','url'], true)) { $value = esc_url_raw($value); }
            elseif ($field === 'email') { $value = is_email($value) ? sanitize_email($value) : ''; }
            elseif (in_array($field, ['why','pitch','emailBody','need','fit','contactNote','howToRespond'], true)) { $value = sanitize_textarea_field($value); }
            else { $value = sanitize_text_field($value); }
            $out[$field] = mb_substr($value, 0, 6000);
        }
        return $out;
    }

    public static function prospects_list(WP_REST_Request $request) {
        global $wpdb; self::no_cache();
        $kind = $request->get_param('kind') === 'request' ? 'request' : 'prospect';
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table('prospects') . ' WHERE kind=%s ORDER BY added DESC, id DESC LIMIT 1000', $kind), ARRAY_A);
        return rest_ensure_response(array_map([self::class, 'prospect_row'], $rows ?: []));
    }

    public static function prospects_update(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('prospects') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$row) return self::error('not_found', 'Prospect not found.', 404);
        $body = self::body($request);
        $update = ['updated_at' => current_time('mysql')];
        if (isset($body['status'])) {
            $allowed = $row['kind'] === 'request' ? self::REQUEST_STATUSES : self::PROSPECT_STATUSES;
            if (!in_array($body['status'], $allowed, true)) return self::error('invalid_status', 'Choose a valid status.');
            $update['status'] = $body['status'];
        }
        if (isset($body['notes'])) {
            $data = self::json_array($row['data']);
            $data['notes'] = sanitize_textarea_field((string) $body['notes']);
            $update['data'] = wp_json_encode($data);
        }
        $wpdb->update(self::table('prospects'), $update, ['id' => $id]);
        VPM_Permissions::audit('crm', 'prospect_updated', 'prospect', $id, ['status' => $update['status'] ?? null]);
        $fresh = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('prospects') . ' WHERE id=%d', $id), ARRAY_A);
        return rest_ensure_response(self::prospect_row($fresh));
    }

    private static function default_search_settings(): array {
        return [
            'sectors' => ['Beauty','Fashion','E-commerce','Coffee','Restaurants','Real estate','Clinics & wellness','Fitness','Hospitality','Startups'],
            'includeKeywords' => ['افتتاح فرع','متجر سلة','إطلاق مجموعة جديدة','new branch','store launch','grand opening','funding round','Riyadh Season'],
            'excludeKeywords' => ['government','وظائف','recruitment','job vacancy'],
            'cities' => ['Riyadh','Jeddah','Dammam','Khobar','Makkah','Madinah'],
            'platforms' => ['Mostaql','Khamsat','LinkedIn','X','Etimad tenders'],
            'prospectsPerDay' => 6, 'requestsPerDay' => 5, 'minBudgetUsd' => 0, 'smallBrandsOnly' => true, 'notes' => '',
        ];
    }

    private static function search_settings(): array {
        $saved = get_option('vpm_prospect_search', []);
        return array_merge(self::default_search_settings(), is_array($saved) ? $saved : []);
    }

    public static function prospects_settings_get() { self::no_cache(); return rest_ensure_response(self::search_settings()); }

    public static function prospects_settings_save(WP_REST_Request $request) {
        $body = self::body($request); $current = self::search_settings();
        $list = static function ($value, int $max = 40): array {
            $items = is_array($value) ? $value : preg_split('/[\n,،]+/u', (string) $value);
            $items = array_values(array_unique(array_filter(array_map(static fn($v) => mb_substr(sanitize_text_field((string) $v), 0, 80), $items))));
            return array_slice($items, 0, $max);
        };
        foreach (['sectors','includeKeywords','excludeKeywords','cities','platforms'] as $key) if (array_key_exists($key, $body)) $current[$key] = $list($body[$key]);
        foreach (['prospectsPerDay' => [1, 15], 'requestsPerDay' => [0, 15], 'minBudgetUsd' => [0, 100000]] as $key => [$min, $max]) if (array_key_exists($key, $body)) $current[$key] = max($min, min($max, (int) $body[$key]));
        if (array_key_exists('smallBrandsOnly', $body)) $current['smallBrandsOnly'] = (bool) $body['smallBrandsOnly'];
        if (array_key_exists('notes', $body)) $current['notes'] = mb_substr(sanitize_textarea_field((string) $body['notes']), 0, 1500);
        update_option('vpm_prospect_search', $current, false);
        VPM_Permissions::audit('crm', 'search_settings_saved', 'settings', 0);
        return rest_ensure_response($current);
    }

    public static function prospects_agent_key() { self::no_cache(); return rest_ensure_response(['key' => (string) get_option('vpm_agent_key', '')]); }

    public static function agent_brief() {
        global $wpdb; self::no_cache();
        $rows = $wpdb->get_results('SELECT id, kind, slug, status, data FROM ' . self::table('prospects'), ARRAY_A) ?: [];
        $existing = ['prospect' => [], 'request' => []]; $needs = [];
        foreach ($rows as $row) {
            $data = self::json_array($row['data']);
            $existing[$row['kind']][] = ['slug' => $row['slug'], 'name' => $data['name'] ?? ($data['title'] ?? '')];
            if ($row['kind'] === 'prospect' && (preg_match('/^(Contacts pending|No public email)/', (string) ($data['contactNote'] ?? '')) || empty($data['emailBody']))) {
                $needs[] = ['slug' => $row['slug'], 'name' => $data['name'] ?? '', 'website' => $data['website'] ?? '', 'missingEmailDraft' => empty($data['emailBody'])];
            }
        }
        return rest_ensure_response(['today' => wp_date('Y-m-d'), 'settings' => self::search_settings(), 'existing' => $existing, 'needsWork' => array_slice($needs, 0, 10)]);
    }

    public static function agent_leads(WP_REST_Request $request) {
        global $wpdb; self::no_cache();
        $items = self::body($request)['items'] ?? [];
        if (!is_array($items) || !$items) return self::error('no_items', 'Send items: [{kind, slug, ...fields}].');
        if (count($items) > 50) return self::error('too_many', 'Send at most 50 items per call.');
        $table = self::table('prospects'); $now = current_time('mysql'); $result = ['created' => 0, 'updated' => 0, 'skipped' => []];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $kind = ($item['kind'] ?? '') === 'request' ? 'request' : 'prospect';
            $slug = sanitize_title((string) ($item['slug'] ?? ($item['name'] ?? ($item['title'] ?? ''))));
            if ($slug === '') { $result['skipped'][] = 'missing slug'; continue; }
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE kind=%s AND slug=%s", $kind, $slug), ARRAY_A);
            if ($row) {
                // Existing lead: only fill in contacts and email drafts; never touch its status.
                $data = self::json_array($row['data']);
                $patch = array_intersect_key(self::clean_fields($item, $kind), array_flip(self::ENRICH_FIELDS));
                $patch = array_filter($patch, static fn($v) => $v !== '');
                if (!$patch) { $result['skipped'][] = $slug . ': already exists'; continue; }
                $wpdb->update($table, ['data' => wp_json_encode(array_merge($data, $patch)), 'updated_at' => $now], ['id' => (int) $row['id']]);
                $result['updated']++;
                continue;
            }
            $data = self::clean_fields($item, $kind);
            $required = $kind === 'request' ? ['title', 'url'] : ['name', 'why'];
            foreach ($required as $field) if (empty($data[$field])) { $result['skipped'][] = $slug . ': missing ' . $field; continue 2; }
            $added = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($item['added'] ?? '')) ? $item['added'] : wp_date('Y-m-d');
            $status_list = $kind === 'request' ? self::REQUEST_STATUSES : self::PROSPECT_STATUSES;
            $status = in_array($item['status'] ?? 'New', $status_list, true) ? ($item['status'] ?? 'New') : 'New';
            $wpdb->insert($table, [
                'kind' => $kind, 'slug' => $slug, 'status' => $status,
                'priority' => in_array($data['priority'] ?? '', ['High','Medium','Low'], true) ? $data['priority'] : '',
                'sector' => mb_substr((string) ($data['sector'] ?? ''), 0, 60), 'added' => $added,
                'data' => wp_json_encode($data), 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($wpdb->insert_id) { $result['created']++; } else { $result['skipped'][] = $slug . ': could not save'; }
        }
        VPM_Permissions::audit('crm', 'agent_leads_received', 'prospect', 0, ['created' => $result['created'], 'updated' => $result['updated']]);
        return rest_ensure_response($result);
    }

    public static function routes(): void {
        register_rest_route(self::NS, '/modules/bootstrap', [
            'methods' => 'GET', 'callback' => [self::class, 'module_bootstrap'], 'permission_callback' => [self::class, 'logged_in'],
        ]);

        register_rest_route(self::NS, '/connect/conversations', [
            ['methods' => 'GET', 'callback' => [self::class, 'connect_conversations'], 'permission_callback' => [self::class, 'connect']],
            ['methods' => 'POST', 'callback' => [self::class, 'connect_create_conversation'], 'permission_callback' => [self::class, 'connect']],
        ]);
        register_rest_route(self::NS, '/connect/members', [
            'methods' => 'GET', 'callback' => [self::class, 'connect_members'], 'permission_callback' => [self::class, 'connect'],
        ]);
        register_rest_route(self::NS, '/connect/conversations/(?P<id>\d+)/messages', [
            ['methods' => 'GET', 'callback' => [self::class, 'connect_messages'], 'permission_callback' => [self::class, 'connect']],
            ['methods' => 'POST', 'callback' => [self::class, 'connect_create_message'], 'permission_callback' => [self::class, 'connect']],
        ]);
        register_rest_route(self::NS, '/connect/conversations/(?P<id>\d+)/messages/(?P<message>\d+)', [
            ['methods' => 'PATCH', 'callback' => [self::class, 'connect_edit_message'], 'permission_callback' => [self::class, 'connect']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'connect_delete_message'], 'permission_callback' => [self::class, 'connect']],
        ]);
        register_rest_route(self::NS, '/connect/conversations/(?P<id>\d+)/read', [
            'methods' => 'POST', 'callback' => [self::class, 'connect_mark_read'], 'permission_callback' => [self::class, 'connect'],
        ]);
        register_rest_route(self::NS, '/connect/messages/(?P<id>\d+)/reactions', [
            'methods' => 'POST', 'callback' => [self::class, 'connect_reaction'], 'permission_callback' => [self::class, 'connect'],
        ]);
        register_rest_route(self::NS, '/connect/attachments', [
            'methods' => 'POST', 'callback' => [self::class, 'connect_upload_attachment'], 'permission_callback' => [self::class, 'connect'],
        ]);
        register_rest_route(self::NS, '/connect/attachments/(?P<id>\d+)/download', [
            'methods' => 'GET', 'callback' => [self::class, 'connect_download_attachment'], 'permission_callback' => [self::class, 'connect'],
        ]);

        register_rest_route(self::NS, '/analytics/summary', [
            'methods' => 'GET', 'callback' => [self::class, 'analytics_summary'], 'permission_callback' => [self::class, 'analytics'],
        ]);
        register_rest_route(self::NS, '/analytics/export', [
            'methods' => 'GET', 'callback' => [self::class, 'analytics_export'], 'permission_callback' => [self::class, 'analytics'],
        ]);
        register_rest_route(self::NS, '/analytics/complexity', [
            ['methods' => 'GET', 'callback' => [self::class, 'analytics_complexity'], 'permission_callback' => [self::class, 'analytics_team']],
            ['methods' => 'POST', 'callback' => [self::class, 'analytics_save_complexity'], 'permission_callback' => [self::class, 'analytics_team']],
        ]);
        register_rest_route(self::NS, '/analytics/settings', [
            ['methods' => 'GET', 'callback' => [self::class, 'analytics_settings'], 'permission_callback' => [self::class, 'analytics_team']],
            ['methods' => 'POST', 'callback' => [self::class, 'analytics_save_settings'], 'permission_callback' => [self::class, 'analytics_team']],
        ]);

        register_rest_route(self::NS, '/finance/dashboard', [
            'methods' => 'GET', 'callback' => [self::class, 'finance_dashboard'], 'permission_callback' => [self::class, 'finance'],
        ]);
        register_rest_route(self::NS, '/finance/export', [
            'methods' => 'GET', 'callback' => [self::class, 'finance_export'], 'permission_callback' => [self::class, 'finance'],
        ]);
        register_rest_route(self::NS, '/finance/payments', [
            ['methods' => 'GET', 'callback' => [self::class, 'finance_payments'], 'permission_callback' => [self::class, 'finance']],
            ['methods' => 'POST', 'callback' => [self::class, 'finance_add_payment'], 'permission_callback' => [self::class, 'finance']],
        ]);
        register_rest_route(self::NS, '/finance/expenses', [
            ['methods' => 'GET', 'callback' => [self::class, 'finance_expenses'], 'permission_callback' => [self::class, 'finance']],
            ['methods' => 'POST', 'callback' => [self::class, 'finance_add_expense'], 'permission_callback' => [self::class, 'finance']],
        ]);
        register_rest_route(self::NS, '/finance/expenses/(?P<id>\\d+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'finance_void_expense'], 'permission_callback' => [self::class, 'finance'],
        ]);
        register_rest_route(self::NS, '/finance/payments/(?P<id>\\d+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'finance_delete_payment'], 'permission_callback' => [self::class, 'finance'],
        ]);
        register_rest_route(self::NS, '/finance/recurring-expenses', [
            ['methods' => 'GET', 'callback' => [self::class, 'finance_recurring_expenses'], 'permission_callback' => [self::class, 'finance']],
            ['methods' => 'POST', 'callback' => [self::class, 'finance_add_recurring_expense'], 'permission_callback' => [self::class, 'finance']],
        ]);
        register_rest_route(self::NS, '/finance/salaries', [
            ['methods' => 'GET', 'callback' => [self::class, 'finance_salaries'], 'permission_callback' => [self::class, 'finance']],
            ['methods' => 'POST', 'callback' => [self::class, 'finance_add_salary'], 'permission_callback' => [self::class, 'finance']],
        ]);
        register_rest_route(self::NS, '/payslips/pdf', [
            ['methods' => 'POST', 'callback' => [self::class, 'payslip_pdf'], 'permission_callback' => [self::class, 'finance']],
        ]);
        register_rest_route(self::NS, '/payslips/send', [
            ['methods' => 'POST', 'callback' => [self::class, 'payslip_send'], 'permission_callback' => [self::class, 'finance']],
        ]);
        register_rest_route(self::NS, '/finance/salary-payments', [
            ['methods' => 'GET', 'callback' => [self::class, 'finance_salary_payments'], 'permission_callback' => [self::class, 'finance']],
            ['methods' => 'POST', 'callback' => [self::class, 'finance_add_salary_payment'], 'permission_callback' => [self::class, 'finance']],
        ]);
        register_rest_route(self::NS, '/finance/fx', [
            ['methods' => 'GET', 'callback' => [self::class, 'finance_fx'], 'permission_callback' => [self::class, 'finance']],
            ['methods' => 'POST', 'callback' => [self::class, 'finance_add_fx'], 'permission_callback' => [self::class, 'finance']],
        ]);

        register_rest_route(self::NS, '/crm/stages', [
            ['methods' => 'GET', 'callback' => [self::class, 'crm_stages'], 'permission_callback' => [self::class, 'crm']],
            ['methods' => 'POST', 'callback' => [self::class, 'crm_save_stage'], 'permission_callback' => [self::class, 'crm']],
        ]);
        // ---- Prospects CRM (2026-11) ----
        register_rest_route(self::NS, '/crm/prospects', [
            'methods' => 'GET', 'callback' => [self::class, 'prospects_list'], 'permission_callback' => [self::class, 'crm'],
        ]);
        register_rest_route(self::NS, '/crm/prospects/(?P<id>\d+)', [
            'methods' => 'PATCH', 'callback' => [self::class, 'prospects_update'], 'permission_callback' => [self::class, 'crm'],
        ]);
        register_rest_route(self::NS, '/crm/search-settings', [
            ['methods' => 'GET', 'callback' => [self::class, 'prospects_settings_get'], 'permission_callback' => [self::class, 'crm']],
            ['methods' => 'POST', 'callback' => [self::class, 'prospects_settings_save'], 'permission_callback' => [self::class, 'crm']],
        ]);
        register_rest_route(self::NS, '/crm/agent-key', [
            'methods' => 'GET', 'callback' => [self::class, 'prospects_agent_key'], 'permission_callback' => static fn() => current_user_can('manage_options'),
        ]);
        register_rest_route(self::NS, '/agent/brief', [
            'methods' => 'GET', 'callback' => [self::class, 'agent_brief'], 'permission_callback' => static fn(WP_REST_Request $request) => self::agent_auth($request) || self::crm(),
        ]);
        register_rest_route(self::NS, '/agent/leads', [
            'methods' => 'POST', 'callback' => [self::class, 'agent_leads'], 'permission_callback' => static fn(WP_REST_Request $request) => self::agent_auth($request) || self::crm(),
        ]);
        register_rest_route(self::NS, '/crm/leads', [
            ['methods' => 'GET', 'callback' => [self::class, 'crm_leads'], 'permission_callback' => [self::class, 'crm']],
            ['methods' => 'POST', 'callback' => [self::class, 'crm_create_lead'], 'permission_callback' => [self::class, 'crm']],
        ]);
        register_rest_route(self::NS, '/crm/leads/(?P<id>\d+)', [
            ['methods' => 'PATCH', 'callback' => [self::class, 'crm_update_lead'], 'permission_callback' => [self::class, 'crm']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'crm_delete_lead'], 'permission_callback' => [self::class, 'crm']],
        ]);
        register_rest_route(self::NS, '/crm/leads/(?P<id>\d+)/activities', [
            ['methods' => 'GET', 'callback' => [self::class, 'crm_activities'], 'permission_callback' => [self::class, 'crm']],
            ['methods' => 'POST', 'callback' => [self::class, 'crm_add_activity'], 'permission_callback' => [self::class, 'crm']],
        ]);
        register_rest_route(self::NS, '/crm/leads/(?P<id>\d+)/convert', [
            'methods' => 'POST', 'callback' => [self::class, 'crm_convert_lead'], 'permission_callback' => [self::class, 'crm'],
        ]);
        register_rest_route(self::NS, '/crm/leads/(?P<id>\d+)/outreach-draft', [
            'methods' => 'POST', 'callback' => [self::class, 'crm_outreach_draft'], 'permission_callback' => [self::class, 'crm'],
        ]);
        register_rest_route(self::NS, '/crm/lead-hunter/sources', [
            ['methods' => 'GET', 'callback' => [self::class, 'hunter_sources'], 'permission_callback' => [self::class, 'lead_hunter']],
            ['methods' => 'POST', 'callback' => [self::class, 'hunter_save_source'], 'permission_callback' => [self::class, 'lead_hunter']],
        ]);
        register_rest_route(self::NS, '/crm/lead-hunter/scan', [
            'methods' => 'POST', 'callback' => [self::class, 'hunter_scan'], 'permission_callback' => [self::class, 'lead_hunter'],
        ]);
        register_rest_route(self::NS, '/crm/lead-hunter/review', [
            'methods' => 'POST', 'callback' => [self::class, 'hunter_review'], 'permission_callback' => [self::class, 'crm'],
        ]);
        register_rest_route(self::NS, '/crm/lead-hunter/google-status', [
            'methods' => 'GET', 'callback' => [self::class, 'hunter_google_status'], 'permission_callback' => [self::class, 'lead_hunter'],
        ]);
        register_rest_route(self::NS, '/crm/lead-hunter/google-search', [
            'methods' => 'POST', 'callback' => [self::class, 'hunter_google_search'], 'permission_callback' => [self::class, 'lead_hunter'],
        ]);
        register_rest_route(self::NS, '/crm/lead-hunter/google-configure', [
            'methods' => 'POST', 'callback' => [self::class, 'hunter_google_configure'], 'permission_callback' => [self::class, 'lead_hunter'],
        ]);
    }

    public static function logged_in(): bool { return VPM_Permissions::logged_in(); }
    public static function connect(): bool { return VPM_Permissions::connect(); }
    public static function analytics(): bool { return VPM_Permissions::own_analytics() || VPM_Permissions::team_analytics(); }
    public static function analytics_team(): bool { return VPM_Permissions::team_analytics(); }
    public static function finance(): bool { return VPM_Permissions::finance(); }
    public static function crm(): bool { return VPM_Permissions::crm(); }
    public static function lead_hunter(): bool { return VPM_Permissions::lead_hunter(); }

    public static function module_bootstrap(): WP_REST_Response {
        return rest_ensure_response([
            'user' => ['id' => get_current_user_id(), 'name' => self::user_name(get_current_user_id())],
            'capabilities' => [
                'connect' => VPM_Permissions::connect(),
                'ownAnalytics' => VPM_Permissions::own_analytics(),
                'teamAnalytics' => VPM_Permissions::team_analytics(),
                'finance' => VPM_Permissions::finance(),
                'crm' => VPM_Permissions::crm(),
                'leadHunter' => VPM_Permissions::lead_hunter(),
            ],
            'limits' => [
                'leadHunter' => 'Public RSS/Atom and configured official JSON sources only; no scraping or mandatory paid provider.',
                'polling' => 'Connect uses incremental polling and slows when the tab is inactive.',
            ],
        ]);
    }

    private static function ensure_general_conversation(): int {
        global $wpdb;
        $id = (int) $wpdb->get_var("SELECT id FROM " . self::table('connect_conversations') . " WHERE type='general' ORDER BY id ASC LIMIT 1");
        $now = current_time('mysql');
        if (!$id) {
            $wpdb->insert(self::table('connect_conversations'), ['type' => 'general', 'title' => 'General Team', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $wpdb->insert_id;
        }
        $users = get_users(['fields' => 'ID', 'role__in' => ['administrator', 'vibes_manager', 'vibes_employee']]);
        foreach ($users as $user_id) {
            if (!user_can((int) $user_id, 'vpm_use_connect')) continue;
            $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::table('connect_members') . ' (conversation_id,user_id,joined_at) VALUES (%d,%d,%s)', $id, (int) $user_id, $now));
        }
        return $id;
    }

    public static function connect_conversations(WP_REST_Request $request) {
        global $wpdb;
        self::ensure_general_conversation();
        $user_id = get_current_user_id();
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT c.*, (SELECT COUNT(*) FROM ' . self::table('connect_messages') . ' m WHERE m.conversation_id=c.id AND m.deleted_at IS NULL) message_count, (SELECT MAX(m.created_at) FROM ' . self::table('connect_messages') . ' m WHERE m.conversation_id=c.id AND m.deleted_at IS NULL) last_message_at, (SELECT m2.body FROM ' . self::table('connect_messages') . ' m2 WHERE m2.conversation_id=c.id AND m2.deleted_at IS NULL ORDER BY m2.id DESC LIMIT 1) last_message_body, (SELECT COUNT(*) FROM ' . self::table('connect_messages') . ' um LEFT JOIN ' . self::table('connect_reads') . ' ur ON ur.conversation_id=um.conversation_id AND ur.user_id=%d WHERE um.conversation_id=c.id AND um.deleted_at IS NULL AND um.user_id<>%d AND um.id>COALESCE(ur.last_read_message_id,0)) unread_count FROM ' . self::table('connect_conversations') . ' c INNER JOIN ' . self::table('connect_members') . ' cm ON cm.conversation_id=c.id WHERE cm.user_id=%d ORDER BY COALESCE(last_message_at,c.updated_at) DESC', $user_id, $user_id, $user_id
        ), ARRAY_A);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['project_id'] = $row['project_id'] ? (int) $row['project_id'] : null;
            $row['task_id'] = $row['task_id'] ? (int) $row['task_id'] : null;
            $row['message_count'] = (int) $row['message_count'];
            $row['unread_count'] = (int) $row['unread_count'];
            $row['members'] = $wpdb->get_results($wpdb->prepare('SELECT u.ID id,u.display_name name,u.user_email email FROM ' . $wpdb->users . ' u INNER JOIN ' . self::table('connect_members') . ' cm ON cm.user_id=u.ID WHERE cm.conversation_id=%d ORDER BY u.display_name ASC', $row['id']), ARRAY_A);
            foreach ($row['members'] as &$member) { $member['id'] = (int) $member['id']; $member['avatar_url'] = self::avatar_url($member['id']); }
        }
        return rest_ensure_response($rows);
    }

    public static function connect_members(WP_REST_Request $request) {
        // Keep the General Team membership in sync whenever the member picker is opened,
        // so newly created employees appear without an administrator having to add them.
        self::ensure_general_conversation();
        $current = get_current_user_id();
        $users = get_users(['fields' => ['ID', 'display_name', 'user_email'], 'role__in' => ['administrator', 'vibes_manager', 'vibes_employee'], 'orderby' => 'display_name', 'order' => 'ASC']);
        $rows = [];
        foreach ($users as $user) {
            if (!user_can((int) $user->ID, 'vpm_use_connect')) continue;
            $rows[] = ['id' => (int) $user->ID, 'name' => $user->display_name, 'email' => $user->user_email, 'avatar_url' => self::avatar_url((int) $user->ID), 'is_current' => (int) $user->ID === $current];
        }
        return rest_ensure_response($rows);
    }

    public static function connect_create_conversation(WP_REST_Request $request) {
        global $wpdb;
        $body = self::body($request);
        $type = sanitize_key($body['type'] ?? 'group');
        if (!in_array($type, ['direct', 'group', 'project', 'task'], true)) return self::error('invalid_type', 'Choose a valid conversation type.');
        $members = array_values(array_unique(array_filter(array_map('absint', (array) ($body['memberIds'] ?? [])))));
        $members[] = get_current_user_id();
        $members = array_values(array_unique($members));
        if ($type === 'direct' && count($members) !== 2) return self::error('direct_members', 'A private conversation needs exactly two members.');
        foreach ($members as $member) if (!get_user_by('id', $member) || !user_can($member, 'vpm_use_connect')) return self::error('member_forbidden', 'Every member must be an authorized portal user.', 403);
        $now = current_time('mysql');
        $title = sanitize_text_field($body['title'] ?? '');
        if (!$title && $type === 'direct') {
            $names = [];
            foreach ($members as $member) $names[] = self::user_name($member);
            $title = implode(' · ', $names);
        }
        if (!$title) $title = ucfirst($type) . ' conversation';
        $wpdb->insert(self::table('connect_conversations'), [
            'type' => $type, 'title' => $title, 'project_id' => absint($body['projectId'] ?? 0) ?: null,
            'task_id' => absint($body['taskId'] ?? 0) ?: null, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $wpdb->insert_id;
        foreach ($members as $member) $wpdb->insert(self::table('connect_members'), ['conversation_id' => $id, 'user_id' => $member, 'joined_at' => $now]);
        VPM_Permissions::audit('connect', 'conversation_created', 'conversation', $id, ['type' => $type, 'member_count' => count($members)]);
        foreach ($members as $member) {
            if ((int) $member === get_current_user_id()) continue;
            $wpdb->insert(self::table('notifications'), ['user_id' => (int) $member, 'type' => 'connect_conversation', 'title' => 'You were added to a conversation', 'body' => $title, 'object_type' => 'connect_conversation', 'object_id' => $id, 'created_at' => $now]);
        }
        return new WP_REST_Response(['id' => $id, 'title' => $title], 201);
    }

    private static function assert_conversation(WP_REST_Request $request): int|WP_Error {
        $id = absint($request['id']);
        if (!$id || !VPM_Permissions::user_can_read_conversation($id)) return self::error('forbidden', 'You are not a member of this conversation.', 403);
        return $id;
    }

    public static function connect_messages(WP_REST_Request $request) {
        global $wpdb;
        $id = self::assert_conversation($request);
        if (is_wp_error($id)) return $id;
        $after = absint($request->get_param('after') ?: 0);
        $limit = min(100, max(1, absint($request->get_param('limit') ?: 50)));
        $current = get_current_user_id();
        $sql = 'SELECT m.*, u.display_name user_name, p.body parent_body, pu.display_name parent_user_name FROM ' . self::table('connect_messages') . ' m LEFT JOIN ' . $wpdb->users . ' u ON u.ID=m.user_id LEFT JOIN ' . self::table('connect_messages') . ' p ON p.id=m.parent_id LEFT JOIN ' . $wpdb->users . ' pu ON pu.ID=p.user_id WHERE m.conversation_id=%d AND m.deleted_at IS NULL';
        $args = [$id];
        if ($after) { $sql .= ' AND m.id>%d'; $args[] = $after; }
        $sql .= ' ORDER BY m.id ASC LIMIT %d'; $args[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id']; $row['user_id'] = (int) $row['user_id']; $row['parent_id'] = $row['parent_id'] ? (int) $row['parent_id'] : null; $row['avatar_url'] = self::avatar_url($row['user_id']); $row['edited'] = !empty($row['edited_at']);
            $row['parent'] = $row['parent_id'] ? ['body' => $row['parent_body'], 'user_name' => $row['parent_user_name']] : null;
            $row['attachments'] = $wpdb->get_results($wpdb->prepare('SELECT id,filename,mime_type,file_url FROM ' . self::table('connect_attachments') . ' WHERE message_id=%d ORDER BY id ASC', $row['id']), ARRAY_A);
            $reactions = $wpdb->get_results($wpdb->prepare('SELECT reaction,COUNT(*) total,MAX(CASE WHEN user_id=%d THEN 1 ELSE 0 END) active FROM ' . self::table('connect_reactions') . ' WHERE message_id=%d GROUP BY reaction', $current, $row['id']), ARRAY_A);
            foreach ($reactions as &$reaction) { $reaction['total'] = (int) $reaction['total']; $reaction['active'] = (bool) $reaction['active']; }
            $row['reactions'] = $reactions ?: [];
            unset($row['parent_body'], $row['parent_user_name']);
        }
        return rest_ensure_response($rows);
    }

    public static function connect_create_message(WP_REST_Request $request) {
        global $wpdb;
        $id = self::assert_conversation($request);
        if (is_wp_error($id)) return $id;
        $body = self::body($request);
        $content = sanitize_textarea_field($body['body'] ?? '');
        if (!$content) return self::error('empty_message', 'Write a message before sending.');
        $now = current_time('mysql');
        $wpdb->insert(self::table('connect_messages'), ['conversation_id' => $id, 'user_id' => get_current_user_id(), 'parent_id' => absint($body['parentId'] ?? 0) ?: null, 'body' => $content, 'created_at' => $now]);
        $message_id = (int) $wpdb->insert_id;
        foreach ((array) ($body['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) continue;
            $url = esc_url_raw((string) ($attachment['url'] ?? ''), ['https']);
            if (!$url) continue;
            $wpdb->insert(self::table('connect_attachments'), ['message_id' => $message_id, 'filename' => sanitize_file_name($attachment['filename'] ?? 'Attachment'), 'mime_type' => sanitize_text_field($attachment['mime'] ?? ''), 'file_url' => $url, 'created_at' => $now]);
        }
        $wpdb->update(self::table('connect_conversations'), ['updated_at' => $now], ['id' => $id]);
        $members = $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . self::table('connect_members') . ' WHERE conversation_id=%d AND user_id<>%d', $id, get_current_user_id()));
        $title = (string) $wpdb->get_var($wpdb->prepare('SELECT title FROM ' . self::table('connect_conversations') . ' WHERE id=%d', $id));
        foreach ($members as $member) $wpdb->insert(self::table('notifications'), ['user_id' => (int) $member, 'type' => 'connect_message', 'title' => 'New message in ' . $title, 'body' => wp_trim_words($content, 18, '…'), 'object_type' => 'connect_conversation', 'object_id' => $id, 'created_at' => $now]);
        return new WP_REST_Response(['id' => $message_id], 201);
    }

    public static function connect_edit_message(WP_REST_Request $request) {
        global $wpdb;
        $conversation = self::assert_conversation($request); if (is_wp_error($conversation)) return $conversation;
        $message_id = absint($request['message']);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('connect_messages') . ' WHERE id=%d AND conversation_id=%d', $message_id, $conversation), ARRAY_A);
        if (!$row || (int) $row['user_id'] !== get_current_user_id() || $row['deleted_at']) return self::error('message_forbidden', 'You can only edit your own active messages.', 403);
        $content = sanitize_textarea_field(self::body($request)['body'] ?? ''); if (!$content) return self::error('empty_message', 'Write a message before saving.');
        $wpdb->update(self::table('connect_messages'), ['body' => $content, 'edited_at' => current_time('mysql')], ['id' => $message_id]);
        return rest_ensure_response(['id' => $message_id, 'edited' => true]);
    }

    public static function connect_delete_message(WP_REST_Request $request) {
        global $wpdb;
        $conversation = self::assert_conversation($request); if (is_wp_error($conversation)) return $conversation;
        $message_id = absint($request['message']);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('connect_messages') . ' WHERE id=%d AND conversation_id=%d', $message_id, $conversation), ARRAY_A);
        $moderator = current_user_can('manage_options');
        if (!$row || $row['deleted_at'] || ((int) $row['user_id'] !== get_current_user_id() && !$moderator)) return self::error('message_forbidden', 'You can only delete your own active messages.', 403);
        $wpdb->update(self::table('connect_messages'), ['deleted_at' => current_time('mysql'), 'body' => 'Message deleted'], ['id' => $message_id]);
        return rest_ensure_response(['id' => $message_id, 'deleted' => true]);
    }

    public static function connect_mark_read(WP_REST_Request $request) {
        global $wpdb;
        $id = self::assert_conversation($request);
        if (is_wp_error($id)) return $id;
        $message_id = absint(self::body($request)['messageId'] ?? 0) ?: null;
        $wpdb->query($wpdb->prepare('INSERT INTO ' . self::table('connect_reads') . ' (conversation_id,user_id,last_read_message_id,updated_at) VALUES (%d,%d,%d,%s) ON DUPLICATE KEY UPDATE last_read_message_id=VALUES(last_read_message_id), updated_at=VALUES(updated_at)', $id, get_current_user_id(), $message_id ?: 0, current_time('mysql')));
        return rest_ensure_response(['read' => true]);
    }

    public static function connect_reaction(WP_REST_Request $request) {
        global $wpdb;
        $message_id = absint($request['id']);
        $conversation_id = (int) $wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . self::table('connect_messages') . ' WHERE id=%d', $message_id));
        if (!$conversation_id || !VPM_Permissions::user_can_read_conversation($conversation_id)) return self::error('forbidden', 'You cannot react to this message.', 403);
        $reaction = sanitize_key(self::body($request)['reaction'] ?? '');
        if (!$reaction || !preg_match('/^[a-z0-9_+:-]{1,40}$/', $reaction)) return self::error('invalid_reaction', 'Use a short reaction name.');
        $exists = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('connect_reactions') . ' WHERE message_id=%d AND user_id=%d AND reaction=%s', $message_id, get_current_user_id(), $reaction));
        if ($exists) $wpdb->delete(self::table('connect_reactions'), ['id' => $exists]);
        else $wpdb->insert(self::table('connect_reactions'), ['message_id' => $message_id, 'user_id' => get_current_user_id(), 'reaction' => $reaction, 'created_at' => current_time('mysql')]);
        return rest_ensure_response(['active' => !$exists]);
    }

    public static function connect_upload_attachment(WP_REST_Request $request) {
        global $wpdb; $body = self::body($request); $message_id = absint($body['messageId'] ?? $request->get_param('messageId')); $conversation_id = (int) $wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . self::table('connect_messages') . ' WHERE id=%d', $message_id));
        if (!$message_id || !$conversation_id || !VPM_Permissions::user_can_read_conversation($conversation_id)) return self::error('forbidden', 'You cannot attach files to this conversation.', 403);
        $file = $request->get_file_params()['file'] ?? ($_FILES['file'] ?? null); if (!is_array($file) || !empty($file['error'])) return self::error('missing_file', 'Choose a file to upload.');
        if ((int) ($file['size'] ?? 0) > 20 * 1024 * 1024) return self::error('file_too_large', 'Attachments must be 20 MB or smaller.');
        $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name']); $allowed = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        if (empty($checked['type']) || !in_array($checked['type'], $allowed, true)) return self::error('invalid_attachment', 'Use an image, PDF, DOCX, XLSX or ZIP attachment.');
        $upload = wp_upload_dir(); $dir = trailingslashit($upload['basedir']) . 'vpm-private'; if (!wp_mkdir_p($dir)) return self::error('storage_error', 'Private attachment storage could not be created.', 500);
        $deny = $dir . DIRECTORY_SEPARATOR . '.htaccess'; if (!file_exists($deny)) @file_put_contents($deny, "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
        $name = sanitize_file_name($file['name']); $name = wp_unique_filename($dir, $name); $path = trailingslashit($dir) . $name; if (!move_uploaded_file($file['tmp_name'], $path)) return self::error('upload_failed', 'The attachment could not be stored.', 500);
        $wpdb->insert(self::table('connect_attachments'), ['message_id' => $message_id, 'filename' => $name, 'mime_type' => $checked['type'], 'file_url' => rest_url('vibes/v1/connect/attachments/0/download'), 'file_path' => $path, 'created_at' => current_time('mysql')]); $id = (int) $wpdb->insert_id; $url = rest_url('vibes/v1/connect/attachments/' . $id . '/download'); $wpdb->update(self::table('connect_attachments'), ['file_url' => $url], ['id' => $id]);
        return new WP_REST_Response(['id' => $id, 'url' => $url, 'filename' => $name], 201);
    }

    public static function connect_download_attachment(WP_REST_Request $request) {
        global $wpdb; $id = absint($request['id']); $row = $wpdb->get_row($wpdb->prepare('SELECT a.*,m.conversation_id FROM ' . self::table('connect_attachments') . ' a INNER JOIN ' . self::table('connect_messages') . ' m ON m.id=a.message_id WHERE a.id=%d', $id), ARRAY_A); if (!$row || !VPM_Permissions::user_can_read_conversation((int) $row['conversation_id']) || !$row['file_path'] || !is_readable($row['file_path'])) return self::error('not_found', 'Attachment not found.', 404);
        return new WP_REST_Response(['__vpm_binary_attachment' => true, 'content' => file_get_contents($row['file_path']), 'filename' => $row['filename'], 'mime' => $row['mime_type']], 200);
    }

    private static function complexity_points(array $task): float {
        global $wpdb;
        $stored = $wpdb->get_var($wpdb->prepare('SELECT points FROM ' . self::table('analytics_task_points') . ' WHERE task_id=%d', (int) ($task['id'] ?? 0)));
        if ($stored !== null) return (float) $stored;
        $key = sanitize_key((string) ($task['service_label'] ?? ''));
        $title = strtolower((string) ($task['title'] ?? ''));
        $map = [
            'ai' => 'ai_video', 'video' => 'short_video_editing', 'motion' => 'complex_motion_graphics',
            'carousel' => 'carousel', 'brand' => 'brand_identity', 'static' => 'social_media_static', 'graphic' => 'advanced_graphic_design',
        ];
        $service_key = $key;
        foreach ($map as $needle => $mapped) if (strpos($key . ' ' . $title, $needle) !== false) { $service_key = $mapped; break; }
        $points = $wpdb->get_var($wpdb->prepare('SELECT points FROM ' . self::table('analytics_complexity') . ' WHERE service_key=%s ORDER BY effective_from DESC LIMIT 1', $service_key));
        return $points !== null ? (float) $points : 1.0;
    }

    private static function analytics_scope(WP_REST_Request $request): array {
        $team = $request->get_param('scope') === 'team' && VPM_Permissions::team_analytics();
        return [$team, $team ? 0 : get_current_user_id()];
    }

    public static function analytics_summary(WP_REST_Request $request) {
        global $wpdb;
        [$team, $user_id] = self::analytics_scope($request);
        $where = $team ? '1=1' : $wpdb->prepare('assignee_id=%d', $user_id);
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table('tasks') . " WHERE {$where}", ARRAY_A);
        $completed = array_values(array_filter($rows, static fn($row) => $row['status'] === 'done' || !empty($row['completed_at'])));
        $points = 0.0; $on_time = 0; $overdue = 0;
        foreach ($rows as $task) {
            if (in_array($task['status'], ['done', 'review'], true)) $points += self::complexity_points($task);
            if (!empty($task['due_date']) && $task['status'] !== 'done' && $task['due_date'] < current_time('Y-m-d')) $overdue++;
            if ($task['status'] === 'done' && !empty($task['due_date']) && !empty($task['completed_at']) && substr($task['completed_at'], 0, 10) <= $task['due_date']) $on_time++;
        }
        $months = [];
        foreach ($completed as $task) {
            $month = substr((string) ($task['completed_at'] ?: $task['updated_at']), 0, 7);
            if (!$month) continue;
            if (!isset($months[$month])) $months[$month] = ['month' => $month, 'tasks' => 0, 'points' => 0];
            $months[$month]['tasks']++;
            $months[$month]['points'] += self::complexity_points($task);
        }
        ksort($months);
        $events_where = $team ? '1=1' : $wpdb->prepare('user_id=%d', $user_id);
        $revisions = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('analytics_task_events') . " WHERE {$events_where} AND event_type IN ('revision','changes_requested')");
        $weights = self::analytics_weights();
        $components = [
            'completedWork' => count($rows) ? round((count($completed) / count($rows)) * 100, 1) : null,
            'qualityApprovals' => count($completed) ? round(max(0, 100 - (($revisions / max(1, count($completed))) * 30)), 1) : null,
            'deadlineReliability' => count($completed) ? round(($on_time / max(1, count($completed))) * 100, 1) : null,
            'collaboration' => null,
            'processCompliance' => count($rows) ? round((count($wpdb->get_col($wpdb->prepare('SELECT DISTINCT task_id FROM ' . self::table('analytics_task_events') . ' WHERE event_type="submitted" AND ' . ($team ? '1=1' : 'user_id=%d'), ...($team ? [] : [$user_id])))) / max(1, count($rows))) * 100, 1) : null,
        ];
        $weighted = 0.0; $weight_total = 0.0; foreach ($components as $key => $value) if ($value !== null && isset($weights[$key])) { $weighted += $value * $weights[$key]; $weight_total += $weights[$key]; }
        return rest_ensure_response([
            'scope' => $team ? 'team' : 'own',
            'assigned' => count($rows), 'completed' => count($completed), 'open' => count($rows) - count($completed),
            'complexityPoints' => round($points, 2), 'onTime' => $completed ? round(($on_time / max(1, count($completed))) * 100, 1) : 0,
            'overdue' => $overdue, 'revisions' => $revisions, 'monthly' => array_values($months), 'performanceScore' => $weight_total ? round($weighted / $weight_total, 1) : null, 'scoreComponents' => $components, 'scoringWeights' => $weights,
        ]);
    }

    public static function analytics_export(WP_REST_Request $request) {
        global $wpdb; [$team, $user_id] = self::analytics_scope($request); $where = $team ? '1=1' : $wpdb->prepare('assignee_id=%d', $user_id); $rows = $wpdb->get_results('SELECT id,project_id,assignee_id,title,status,due_date,completed_at,updated_at FROM ' . self::table('tasks') . " WHERE {$where} ORDER BY updated_at DESC", ARRAY_A);
        $handle = fopen('php://temp', 'w+'); fputcsv($handle, ['task_id', 'project_id', 'assignee_id', 'title', 'status', 'due_date', 'completed_at', 'points']); foreach ($rows as $row) fputcsv($handle, [$row['id'], $row['project_id'], $row['assignee_id'], $row['title'], $row['status'], $row['due_date'], $row['completed_at'], self::complexity_points($row)]); rewind($handle); $csv = stream_get_contents($handle); fclose($handle); $response = new WP_REST_Response($csv, 200); $response->header('Content-Type', 'text/csv; charset=utf-8'); $response->header('Content-Disposition', 'attachment; filename="vibes-analytics-export.csv"'); return $response;
    }

    private static function analytics_weights(): array {
        $weights = get_option('vpm_analytics_weights', []); if (!is_array($weights)) $weights = [];
        return wp_parse_args($weights, ['completedWork' => 0.35, 'qualityApprovals' => 0.25, 'deadlineReliability' => 0.2, 'collaboration' => 0.1, 'processCompliance' => 0.1]);
    }

    public static function analytics_settings() { return rest_ensure_response(['weights' => self::analytics_weights(), 'note' => 'Weights affect reporting only; they never change salaries automatically.']); }

    public static function analytics_save_settings(WP_REST_Request $request) {
        $body = self::body($request); $weights = []; foreach (array_keys(self::analytics_weights()) as $key) $weights[$key] = max(0, min(1, (float) ($body[$key] ?? 0))); $sum = array_sum($weights); if ($sum <= 0) return self::error('invalid_weights', 'At least one positive scoring weight is required.'); foreach ($weights as $key => $value) $weights[$key] = round($value / $sum, 4); update_option('vpm_analytics_weights', $weights, false); VPM_Permissions::audit('analytics', 'scoring_settings_updated', 'settings', 0, ['weights' => $weights]); return self::analytics_settings();
    }

    public static function analytics_complexity() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table('analytics_complexity') . ' ORDER BY service_key,effective_from DESC', ARRAY_A);
        foreach ($rows as &$row) $row['points'] = (float) $row['points'];
        return rest_ensure_response($rows);
    }

    public static function analytics_save_complexity(WP_REST_Request $request) {
        global $wpdb;
        $body = self::body($request); $key = sanitize_key($body['serviceKey'] ?? ''); $label = sanitize_text_field($body['label'] ?? '');
        $points = max(0, (float) ($body['points'] ?? 0)); $from = sanitize_text_field($body['effectiveFrom'] ?? current_time('Y-m-d'));
        if (!$key || !$label || !$points || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) return self::error('invalid_complexity', 'Service key, label, positive points and effective date are required.');
        $current = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . self::table('analytics_complexity') . ' WHERE service_key=%s AND effective_from=%s', $key, $from), ARRAY_A);
        $data = ['service_key' => $key, 'label' => $label, 'points' => $points, 'effective_from' => $from, 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql')];
        if ($current) $wpdb->update(self::table('analytics_complexity'), ['label' => $label, 'points' => $points], ['id' => (int) $current['id']]);
        else $wpdb->insert(self::table('analytics_complexity'), $data);
        VPM_Permissions::audit('analytics', 'complexity_updated', 'complexity', (int) $wpdb->insert_id, ['service_key' => $key, 'points' => $points]);
        return self::analytics_complexity();
    }

    private static function invoice_paid_amount(array $invoice): float {
        global $wpdb;
        $ledger = (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount),0) FROM ' . self::table('finance_payments') . ' WHERE invoice_id=%d', (int) $invoice['id']));
        if ($ledger > 0) return $ledger;
        return in_array($invoice['status'], ['paid'], true) ? (float) $invoice['total'] : 0.0;
    }

    /** Convert an amount to USD (the agency's book currency). */
    public static function to_usd(float $amount, string $currency): float {
        global $wpdb;
        $currency = strtoupper($currency ?: 'USD');
        if ($currency === 'USD') return $amount;
        if ($currency === 'SAR') return $amount / 3.75;
        $rate = (float) $wpdb->get_var($wpdb->prepare('SELECT rate FROM ' . self::table('finance_fx_rates') . " WHERE base_currency='USD' AND quote_currency=%s ORDER BY effective_date DESC,id DESC LIMIT 1", $currency));
        if ($rate > 0) return $amount / $rate;
        $inverse = (float) $wpdb->get_var($wpdb->prepare('SELECT rate FROM ' . self::table('finance_fx_rates') . " WHERE base_currency=%s AND quote_currency='USD' ORDER BY effective_date DESC,id DESC LIMIT 1", $currency));
        if ($inverse > 0) return $amount * $inverse;
        return $currency === 'LBP' ? $amount / 89500 : $amount;
    }

    public static function finance_void_expense(WP_REST_Request $request) {
        global $wpdb; $id = (int) $request['id'];
        if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('finance_expenses') . ' WHERE id=%d', $id))) return self::error('not_found', 'Expense not found.', 404);
        $wpdb->update(self::table('finance_expenses'), ['status' => 'voided'], ['id' => $id]);
        VPM_Permissions::audit('finance', 'expense_voided', 'expense', $id);
        return rest_ensure_response(['voided' => true]);
    }

    public static function finance_delete_payment(WP_REST_Request $request) {
        global $wpdb; $id = (int) $request['id'];
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('finance_payments') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$row) return self::error('not_found', 'Payment not found.', 404);
        if ((int) $row['invoice_id'] && $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . self::table('invoices') . ' WHERE id=%d', (int) $row['invoice_id'])) === 'paid') {
            return self::error('payment_locked', 'This payment belongs to a paid invoice. Change the invoice status first.', 409);
        }
        $wpdb->delete(self::table('finance_payments'), ['id' => $id]);
        VPM_Permissions::audit('finance', 'payment_removed', 'payment', $id, ['amount' => $row['amount'], 'currency' => $row['currency']]);
        return rest_ensure_response(['deleted' => true]);
    }

    private static function finance_totals(): array {
        global $wpdb;
        $invoices = $wpdb->get_results('SELECT * FROM ' . self::table('invoices') . " WHERE status NOT IN ('voided','cancelled')", ARRAY_A);
        $currency = [];
        foreach ($invoices as $invoice) {
            $code = strtoupper((string) ($invoice['currency'] ?: 'SAR')); if (!isset($currency[$code])) $currency[$code] = ['invoiced' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0];
            $currency[$code]['invoiced'] += (float) $invoice['total']; $currency[$code]['collected'] += self::invoice_paid_amount($invoice);
        }
        foreach ($currency as &$bucket) { $bucket['outstanding'] = max(0, $bucket['invoiced'] - $bucket['collected']); foreach ($bucket as $key => $value) $bucket[$key] = round($value, 2); }
        $expenses = [];
        foreach ($wpdb->get_results('SELECT currency,SUM(amount) total FROM ' . self::table('finance_expenses') . " WHERE status <> 'voided' GROUP BY currency", ARRAY_A) as $row) $expenses[strtoupper($row['currency'])] = round((float) $row['total'], 2);
        $payroll = [];
        foreach ($wpdb->get_results('SELECT currency,SUM(amount) total FROM ' . self::table('finance_salary_payments') . " WHERE status IN ('planned','paid') GROUP BY currency", ARRAY_A) as $row) $payroll[strtoupper($row['currency'])] = round((float) $row['total'], 2);
        // Book currency is USD. SAR is pegged at 3.75; other currencies use the latest recorded FX rate.
        $usd = static function (array $buckets): float { $sum = 0.0; foreach ($buckets as $code => $value) $sum += self::to_usd((float) $value, (string) $code); return round($sum, 2); };
        $col = static function (string $key) use ($currency): array { $out = []; foreach ($currency as $code => $bucket) $out[$code] = $bucket[$key]; return $out; };
        $invoiced = $usd($col('invoiced')); $collected = $usd($col('collected')); $outstanding = $usd($col('outstanding'));
        $expenses_usd = $usd($expenses); $payroll_usd = $usd($payroll);
        return ['currency' => 'USD', 'invoiced' => $invoiced, 'collected' => $collected, 'outstanding' => $outstanding, 'expenses' => $expenses_usd, 'payroll' => $payroll_usd, 'estimatedProfit' => round($collected - $expenses_usd - $payroll_usd, 2), 'byCurrency' => $currency, 'expensesByCurrency' => $expenses, 'payrollByCurrency' => $payroll];
    }

    public static function finance_dashboard() {
        return rest_ensure_response(array_merge(self::finance_totals(), ['currencies' => ['USD', 'SAR'], 'note' => 'Totals are in USD. SAR is converted at the fixed 3.75 peg; original records keep their own currency.']));
    }

    public static function finance_export(WP_REST_Request $request) {
        global $wpdb;
        $format = sanitize_key($request->get_param('format') ?: 'csv');
        if ($format === 'pdf') return self::finance_pdf_export();
        if ($format !== 'csv') return self::error('unsupported_export', 'Use CSV or PDF.', 406);
        $handle = fopen('php://temp', 'w+'); fputcsv($handle, ['type', 'reference', 'client_or_vendor', 'amount', 'currency', 'date', 'status']);
        foreach ($wpdb->get_results('SELECT invoice_number,client_name,total,currency,due_date,status FROM ' . self::table('invoices') . ' ORDER BY id DESC', ARRAY_A) as $row) fputcsv($handle, ['invoice', $row['invoice_number'], $row['client_name'], $row['total'], $row['currency'], $row['due_date'], $row['status']]);
        foreach ($wpdb->get_results('SELECT id,vendor,amount,currency,expense_date,status FROM ' . self::table('finance_expenses') . ' ORDER BY id DESC', ARRAY_A) as $row) fputcsv($handle, ['expense', 'EXP-' . $row['id'], $row['vendor'], $row['amount'], $row['currency'], $row['expense_date'], $row['status']]);
        rewind($handle); $csv = stream_get_contents($handle); fclose($handle); $response = new WP_REST_Response($csv, 200); $response->header('Content-Type', 'text/csv; charset=utf-8'); $response->header('Content-Disposition', 'attachment; filename="vibes-finance-export.csv"'); return $response;
    }

    private static function pdf_text(string $text): string {
        $text = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;
        $text = preg_replace('/[^\x20-\x7E]/', '?', $text);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private static function simple_pdf(array $lines): string {
        $stream = "q\n0.38 0.20 0.55 rg\n0 720 612 72 re f\n0.14 0.10 0.19 rg\n";
        $y = 680;
        foreach ($lines as $line) { $stream .= ($line[3] ?? '0.14 0.10 0.19') . " rg\nBT /F1 " . (float) ($line[2] ?? 10) . ' Tf ' . (float) ($line[0] ?? 52) . ' ' . (float) $y . " Td (" . self::pdf_text((string) ($line[1] ?? '')) . ") Tj ET\n"; $y -= (float) ($line[2] ?? 10) + 10; }
        $stream .= "Q\n";
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream'];
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0]; foreach ($objects as $index => $object) { $offsets[$index + 1] = strlen($pdf); $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n"; } $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n"; for ($i = 1; $i <= 5; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]); return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private static function finance_pdf_export(): WP_REST_Response {
        $totals = self::finance_totals(); $lines = [['52', 'VIBES AGENCY', '24', '1 1 1'], ['52', 'CONFIDENTIAL FINANCE REPORT', '11', '1 1 1'], ['52', 'Generated ' . current_time('Y-m-d H:i'), '10', '0.36 0.32 0.42'], ['52', 'Invoiced: ' . $totals['invoiced'], '12', '0.14 0.10 0.19'], ['52', 'Collected: ' . $totals['collected'], '12', '0.14 0.10 0.19'], ['52', 'Outstanding: ' . $totals['outstanding'], '12', '0.14 0.10 0.19'], ['52', 'Expenses: ' . $totals['expenses'], '12', '0.14 0.10 0.19'], ['52', 'Payroll: ' . $totals['payroll'], '12', '0.14 0.10 0.19'], ['52', 'Currency totals are kept separate; no silent FX conversion.', '10', '0.36 0.32 0.42']]; $response = new WP_REST_Response(['__vpm_binary_export' => true, 'content' => self::simple_pdf($lines), 'filename' => 'vibes-finance-report.pdf', 'mime' => 'application/pdf'], 200); return $response;
    }

    public static function serve_binary_export($served, $result, $request, $server) {
        if (!$result instanceof WP_REST_Response) return $served; $data = $result->get_data(); if (!is_array($data) || (empty($data['__vpm_binary_export']) && empty($data['__vpm_binary_attachment']))) return $served;
        $result->header('Content-Type', $data['mime'] ?? 'application/octet-stream'); $result->header('Content-Disposition', 'attachment; filename="' . sanitize_file_name($data['filename'] ?? 'export.bin') . '"'); echo $data['content'] ?? ''; return true;
    }

    public static function finance_payments(WP_REST_Request $request) {
        global $wpdb; [$page, $per_page, $offset] = self::page($request);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT p.*,i.invoice_number,i.client_name FROM ' . self::table('finance_payments') . ' p LEFT JOIN ' . self::table('invoices') . ' i ON i.id=p.invoice_id ORDER BY p.paid_at DESC,p.id DESC LIMIT %d OFFSET %d', $per_page, $offset), ARRAY_A);
        return rest_ensure_response(['rows' => $rows, 'page' => $page, 'perPage' => $per_page]);
    }

    public static function finance_add_payment(WP_REST_Request $request) {
        global $wpdb; $body = self::body($request); $invoice_id = absint($body['invoiceId'] ?? 0); $amount = round(max(0, (float) ($body['amount'] ?? 0)), 2);
        $invoice = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('invoices') . ' WHERE id=%d', $invoice_id), ARRAY_A);
        if (!$invoice || !$amount) return self::error('invalid_payment', 'Choose an invoice and a positive payment amount.');
        $outstanding = max(0, (float) $invoice['total'] - self::invoice_paid_amount($invoice));
        if ($amount > $outstanding + 0.01) return self::error('overpayment', 'Payment exceeds the outstanding invoice balance.');
        $date = sanitize_text_field($body['paidAt'] ?? current_time('Y-m-d')); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return self::error('invalid_date', 'Use a valid payment date.');
        $wpdb->insert(self::table('finance_payments'), ['invoice_id' => $invoice_id, 'amount' => $amount, 'currency' => in_array($body['currency'] ?? $invoice['currency'], ['USD', 'SAR', 'LBP'], true) ? $body['currency'] : $invoice['currency'], 'paid_at' => $date, 'reference' => sanitize_text_field($body['reference'] ?? ''), 'note' => sanitize_textarea_field($body['note'] ?? ''), 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql')]);
        $new_paid = self::invoice_paid_amount(array_merge($invoice, ['status' => 'sent']));
        $status = $new_paid >= (float) $invoice['total'] - 0.01 ? 'paid' : 'partial';
        $wpdb->update(self::table('invoices'), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $invoice_id]);
        VPM_Permissions::audit('finance', 'payment_recorded', 'invoice', $invoice_id, ['amount' => $amount, 'currency' => $invoice['currency']]);
        return new WP_REST_Response(['id' => (int) $wpdb->insert_id, 'status' => $status], 201);
    }

    public static function finance_expenses(WP_REST_Request $request) {
        global $wpdb; [$page, $per_page, $offset] = self::page($request);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table('finance_expenses') . ' ORDER BY expense_date DESC,id DESC LIMIT %d OFFSET %d', $per_page, $offset), ARRAY_A);
        return rest_ensure_response(['rows' => $rows, 'page' => $page, 'perPage' => $per_page]);
    }

    public static function finance_add_expense(WP_REST_Request $request) {
        global $wpdb; $body = self::body($request); $amount = round(max(0, (float) ($body['amount'] ?? 0)), 2); $date = sanitize_text_field($body['expenseDate'] ?? current_time('Y-m-d'));
        if (!$amount || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return self::error('invalid_expense', 'Positive amount and valid expense date are required.');
        $currency = in_array($body['currency'] ?? 'USD', ['USD', 'SAR'], true) ? $body['currency'] : 'USD';
        $wpdb->insert(self::table('finance_expenses'), ['category' => sanitize_key($body['category'] ?? 'general'), 'vendor' => sanitize_text_field($body['vendor'] ?? ''), 'description' => sanitize_textarea_field($body['description'] ?? ''), 'amount' => $amount, 'currency' => $currency, 'expense_date' => $date, 'receipt_attachment_id' => absint($body['receiptAttachmentId'] ?? 0) ?: null, 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql')]);
        VPM_Permissions::audit('finance', 'expense_recorded', 'expense', (int) $wpdb->insert_id, ['amount' => $amount, 'currency' => $currency]);
        return new WP_REST_Response(['id' => (int) $wpdb->insert_id], 201);
    }

    public static function finance_recurring_expenses() {
        global $wpdb; return rest_ensure_response($wpdb->get_results('SELECT * FROM ' . self::table('finance_recurring_expenses') . ' ORDER BY active DESC,next_date ASC,id DESC', ARRAY_A));
    }

    public static function finance_add_recurring_expense(WP_REST_Request $request) {
        global $wpdb; $body = self::body($request); $amount = round(max(0, (float) ($body['amount'] ?? 0)), 2); if (!$amount) return self::error('invalid_recurring_expense', 'Positive amount is required.');
        $currency = in_array($body['currency'] ?? 'SAR', ['USD', 'SAR', 'LBP'], true) ? $body['currency'] : 'SAR'; $cadence = in_array($body['cadence'] ?? 'monthly', ['monthly', 'yearly'], true) ? $body['cadence'] : 'monthly';
        $wpdb->insert(self::table('finance_recurring_expenses'), ['category' => sanitize_key($body['category'] ?? 'general'), 'vendor' => sanitize_text_field($body['vendor'] ?? ''), 'description' => sanitize_textarea_field($body['description'] ?? ''), 'amount' => $amount, 'currency' => $currency, 'cadence' => $cadence, 'next_date' => sanitize_text_field($body['nextDate'] ?? '') ?: null, 'active' => isset($body['active']) ? (int) (bool) $body['active'] : 1, 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql')]);
        VPM_Permissions::audit('finance', 'recurring_expense_created', 'recurring_expense', (int) $wpdb->insert_id, ['amount' => $amount, 'currency' => $currency, 'cadence' => $cadence]); return new WP_REST_Response(['id' => (int) $wpdb->insert_id], 201);
    }

    private static function payslip_days_in_period(string $start, string $end, string $period): int {
        $zone = wp_timezone();
        $period_start = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01', $zone);
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', substr($start, 0, 10), $zone);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', substr($end, 0, 10), $zone);
        if (!$period_start || !$from || !$to) return 0;
        $period_end = $period_start->modify('last day of this month');
        $clipped_from = $from > $period_start ? $from : $period_start;
        $clipped_to = $to < $period_end ? $to : $period_end;
        return $clipped_to < $clipped_from ? 0 : (int) $clipped_from->diff($clipped_to)->days + 1;
    }

    private static function payslip_data(WP_REST_Request $request) {
        global $wpdb;
        $body = self::body($request);
        $user_id = absint($body['employeeId'] ?? $body['employee'] ?? 0);
        $period = sanitize_text_field((string) ($body['period'] ?? ''));
        if (!$user_id || !preg_match('/^\\d{4}-\\d{2}$/', $period)) return self::error('invalid_payslip', 'Choose an employee and a valid pay period.');
        $user = get_user_by('id', $user_id);
        if (!$user) return self::error('employee_not_found', 'Employee not found.', 404);
        $period_start = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01', wp_timezone());
        if (!$period_start) return self::error('invalid_payslip_period', 'Choose a valid pay period.');
        $period_end = $period_start->modify('last day of this month')->format('Y-m-d');
        $month_start = $period_start->format('Y-m-d');
        $salary_amount = (float) get_user_meta($user_id, 'vtp_salary_amount', true);
        if ($salary_amount <= 0) {
            $salary_row = $wpdb->get_row($wpdb->prepare('SELECT amount FROM ' . self::table('finance_salary_history') . ' WHERE user_id=%d AND effective_from<=%s AND (effective_to IS NULL OR effective_to>=%s) ORDER BY effective_from DESC,id DESC LIMIT 1', $user_id, $month_start, $month_start), ARRAY_A);
            if ($salary_row) $salary_amount = (float) ($salary_row['amount'] ?? 0);
        }
        $leave_table = $wpdb->prefix . 'vtp_leave_requests';
        $leave_rows = $wpdb->get_results($wpdb->prepare("SELECT leave_type,start_date,end_date FROM {$leave_table} WHERE user_id=%d AND status='approved' AND start_date<=%s AND end_date>=%s", $user_id, $period_end, $month_start), ARRAY_A);
        $annual = 0; $sick = 0;
        foreach ($leave_rows as $leave_row) {
            $days = self::payslip_days_in_period((string) ($leave_row['start_date'] ?? ''), (string) ($leave_row['end_date'] ?? ''), $period);
            if (!$days) continue;
            $type = sanitize_key((string) ($leave_row['leave_type'] ?? 'vacation'));
            if (in_array($type, ['sick', 'sick_leave', 'sick-leave'], true)) $sick += $days; else $annual += $days;
        }
        $issued = current_time('timestamp');
        return [
            'employeeId' => $user_id,
            'employeeName' => (string) $user->display_name,
            'email' => (string) $user->user_email,
            'period' => $period,
            'periodLabel' => $period_start->format('F Y'),
            'issued' => wp_date('d M Y', $issued, wp_timezone()),
            'salary' => round($salary_amount, 2),
            'currency' => 'USD',
            'annualLeave' => (int) $annual,
            'sickLeave' => (int) $sick,
            'paymentMethod' => 'Bank transfer',
            'total' => round($salary_amount, 2),
        ];
    }

    private static function payslip_pdf_document(array $data): string {
        $stream = "q\n1 1 1 rg\n0 0 612 792 re f\n";
        $text = static function (float $x, float $y, string $value, float $size = 10, string $font = '/F1', string $color = '0.14 0.10 0.19') use (&$stream): void {
            $stream .= $color . " rg\nBT " . $font . ' ' . $size . " Tf " . $x . ' ' . $y . " Td (" . self::pdf_text($value) . ") Tj ET\n";
        };
        $rect = static function (float $x, float $y, float $w, float $h, string $fill, string $stroke = '', float $line = 1) use (&$stream): void {
            $stream .= $fill . " rg\n" . $x . ' ' . $y . ' ' . $w . ' ' . $h . " re f\n";
            if ($stroke) $stream .= $stroke . " RG\n" . $line . " w\n" . $x . ' ' . $y . ' ' . $w . ' ' . $h . " re S\n";
        };
        $purple = '0.38 0.20 0.55'; $dark = '0.14 0.10 0.19'; $muted = '0.43 0.39 0.52'; $lavender = '0.98 0.97 1'; $coral = '0.93 0.42 0.33';
        $text(48, 735, 'VIBES', 25, '/F2', $dark); $text(48, 712, 'agency', 12, '/F1', $muted);
        $text(395, 735, 'PAYSLIP', 22, '/F2', $dark); $rect(520, 741, 70, 8, $purple);
        $text(395, 708, 'PAY PERIOD', 8, '/F2', $muted); $text(480, 708, (string) $data['periodLabel'], 10, '/F2', $dark);
        $text(395, 688, 'ISSUED', 8, '/F2', $muted); $text(480, 688, (string) $data['issued'], 10, '/F2', $dark);
        $text(395, 668, 'CURRENCY', 8, '/F2', $muted); $text(480, 668, 'USD', 10, '/F2', $dark);
        $rect(48, 570, 242, 76, $lavender, $purple, 1.2); $rect(322, 570, 242, 76, $lavender, $purple, 1.2);
        $text(62, 625, 'EMPLOYEE', 9, '/F2', $muted); $text(62, 600, (string) $data['employeeName'], 17, '/F1', $dark); $text(62, 581, (string) $data['email'], 9, '/F1', $muted);
        $text(336, 625, 'PAYMENT METHOD', 9, '/F2', $muted); $text(336, 600, (string) $data['paymentMethod'], 15, '/F1', $dark); $text(336, 581, 'Confidential employee document', 9, '/F1', $muted);
        $rect(48, 500, 516, 34, $purple); $text(62, 513, 'BASIC SALARY', 8, '/F2', '1 1 1'); $text(220, 513, 'SICK LEAVE TAKEN', 8, '/F2', '1 1 1'); $text(360, 513, 'ANNUAL LEAVE TAKEN', 8, '/F2', '1 1 1'); $text(495, 513, 'TOTAL', 8, '/F2', '1 1 1');
        $rect(48, 456, 516, 44, '1 1 1', '0.82 0.74 0.88', .8); $text(62, 475, 'USD ' . number_format((float) $data['salary'], 2), 12, '/F2', $dark); $text(242, 475, (string) $data['sickLeave'] . ' day' . ((int) $data['sickLeave'] === 1 ? '' : 's'), 11, '/F1', $dark); $text(393, 475, (string) $data['annualLeave'] . ' day' . ((int) $data['annualLeave'] === 1 ? '' : 's'), 11, '/F1', $dark); $text(500, 475, 'USD ' . number_format((float) $data['total'], 2), 10, '/F2', $purple);
        $rect(48, 392, 342, 42, '1 0.98 0.97'); $text(62, 414, 'Monthly payroll note', 9, '/F2', $coral); $text(62, 397, 'Approved leave is counted only inside this pay period.', 8, '/F1', $muted);
        $rect(412, 380, 152, 68, $lavender, '0.82 0.74 0.88', .8); $text(426, 424, 'TOTAL', 9, '/F2', $muted); $text(426, 399, 'USD ' . number_format((float) $data['total'], 2), 16, '/F2', $purple);
        $text(58, 105, 'VIBES AGENCY · CONFIDENTIAL PAYSLIP', 9, '/F2', $muted); $rect(48, 82, 516, 4, $purple); $rect(390, 82, 174, 4, $coral); $text(48, 58, 'We don’t follow trends. WE SET VIBES.', 10, '/F2', $dark); $text(477, 58, 'vibesagency.co', 9, '/F1', $purple);
        $stream .= "Q\n";
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>', '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream'];
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0]; foreach ($objects as $index => $object) { $offsets[$index + 1] = strlen($pdf); $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n"; } $xref = strlen($pdf); $pdf .= "xref\n0 7\n0000000000 65535 f \n"; for ($i = 1; $i <= 6; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]); return $pdf . "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    public static function payslip_pdf(WP_REST_Request $request) {
        $data = self::payslip_data($request); if (is_wp_error($data)) return $data;
        $filename = 'Payslip_' . sanitize_file_name((string) $data['employeeName']) . '_' . str_replace('-', '', (string) $data['period']) . '.pdf';
        return new WP_REST_Response(['__vpm_binary_export' => true, 'content' => self::payslip_pdf_document($data), 'filename' => $filename, 'mime' => 'application/pdf'], 200);
    }

    public static function payslip_send(WP_REST_Request $request) {
        $data = self::payslip_data($request); if (is_wp_error($data)) return $data;
        $recipient = sanitize_email((string) $data['email']); if (!$recipient || !is_email($recipient)) return self::error('missing_employee_email', 'The employee has no valid work email configured.');
        $filename = 'Payslip_' . sanitize_file_name((string) $data['employeeName']) . '_' . str_replace('-', '', (string) $data['period']) . '.pdf';
        $path = tempnam(sys_get_temp_dir(), 'vibes-payslip-'); if (!$path || file_put_contents($path, self::payslip_pdf_document($data)) === false) return self::error('payslip_file_failed', 'The payslip PDF could not be prepared.', 500);
        $headers = ['From: Vibes Agency <admin@vibesagency.co>', 'Reply-To: admin@vibesagency.co'];
        $subject = 'Vibes Agency payslip — ' . (string) $data['periodLabel'];
        $sent = wp_mail($recipient, $subject, 'Hello ' . (string) $data['employeeName'] . ",\\n\\nAttached is your confidential Vibes Agency payslip for " . (string) $data['periodLabel'] . ".\\n\\nVibes Agency", $headers, [$path]);
        @unlink($path); if (!$sent) return self::error('payslip_send_failed', 'The payslip could not be sent. Check the portal mail configuration.', 500);
        VPM_Permissions::audit('finance', 'payslip_sent', 'employee', (int) $data['employeeId'], ['period' => $data['period'], 'recipient' => $recipient]);
        return rest_ensure_response(['sent' => true, 'recipient' => $recipient, 'period' => $data['period']]);
    }

    public static function finance_salaries() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT s.*,u.display_name,u.user_email FROM ' . self::table('finance_salary_history') . ' s LEFT JOIN ' . $wpdb->users . ' u ON u.ID=s.user_id ORDER BY s.effective_from DESC,s.id DESC', ARRAY_A);
        foreach ($rows as &$row) $row['amount'] = (float) $row['amount'];
        return rest_ensure_response($rows);
    }

    public static function finance_add_salary(WP_REST_Request $request) {
        global $wpdb; $body = self::body($request); $user_id = absint($body['userId'] ?? 0); $amount = round(max(0, (float) ($body['amount'] ?? 0)), 2); $from = sanitize_text_field($body['effectiveFrom'] ?? '');
        if (!$user_id || !get_user_by('id', $user_id) || !$amount || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) return self::error('invalid_salary', 'Employee, positive amount and effective date are required.');
        $currency = in_array($body['currency'] ?? 'USD', ['USD', 'SAR', 'LBP'], true) ? $body['currency'] : 'USD';
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table('finance_salary_history') . ' SET effective_to=DATE_SUB(%s, INTERVAL 1 DAY) WHERE user_id=%d AND effective_from<%s AND (effective_to IS NULL OR effective_to>=%s)', $from, $user_id, $from, $from));
        $wpdb->insert(self::table('finance_salary_history'), ['user_id' => $user_id, 'amount' => $amount, 'currency' => $currency, 'effective_from' => $from, 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql')]);
        VPM_Permissions::audit('finance', 'salary_changed', 'employee', $user_id, ['amount' => $amount, 'currency' => $currency, 'effective_from' => $from]);
        return new WP_REST_Response(['id' => (int) $wpdb->insert_id], 201);
    }

    public static function finance_salary_payments() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT p.*,u.display_name FROM ' . self::table('finance_salary_payments') . ' p LEFT JOIN ' . $wpdb->users . ' u ON u.ID=p.user_id ORDER BY p.pay_period DESC,p.id DESC', ARRAY_A);
        foreach ($rows as &$row) $row['amount'] = (float) $row['amount'];
        return rest_ensure_response($rows);
    }

    public static function finance_add_salary_payment(WP_REST_Request $request) {
        global $wpdb; $body = self::body($request); $user_id = absint($body['userId'] ?? 0); $period = sanitize_text_field($body['payPeriod'] ?? ''); $amount = round(max(0, (float) ($body['amount'] ?? 0)), 2);
        if (!$user_id || !get_user_by('id', $user_id) || !preg_match('/^\d{4}-\d{2}$/', $period) || !$amount) return self::error('invalid_salary_payment', 'Employee, YYYY-MM pay period and positive amount are required.');
        $currency = in_array($body['currency'] ?? 'USD', ['USD', 'SAR', 'LBP'], true) ? $body['currency'] : 'USD'; $status = in_array($body['status'] ?? 'planned', ['planned', 'paid', 'voided'], true) ? $body['status'] : 'planned';
        $wpdb->query($wpdb->prepare('INSERT INTO ' . self::table('finance_salary_payments') . ' (user_id,pay_period,amount,currency,payment_date,status,reference,created_by,created_at) VALUES (%d,%s,%f,%s,%s,%s,%s,%d,%s) ON DUPLICATE KEY UPDATE amount=VALUES(amount),currency=VALUES(currency),payment_date=VALUES(payment_date),status=VALUES(status),reference=VALUES(reference)', $user_id, $period, $amount, $currency, sanitize_text_field($body['paymentDate'] ?? '') ?: null, $status, sanitize_text_field($body['reference'] ?? ''), get_current_user_id(), current_time('mysql')));
        VPM_Permissions::audit('finance', 'salary_payment_recorded', 'employee', $user_id, ['pay_period' => $period, 'amount' => $amount, 'currency' => $currency, 'status' => $status]);
        return self::finance_salary_payments();
    }

    public static function finance_fx() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table('finance_fx_rates') . ' ORDER BY effective_date DESC,id DESC', ARRAY_A);
        foreach ($rows as &$row) $row['rate'] = (float) $row['rate'];
        return rest_ensure_response($rows);
    }

    public static function finance_add_fx(WP_REST_Request $request) {
        global $wpdb; $body = self::body($request); $base = strtoupper(sanitize_text_field($body['baseCurrency'] ?? '')); $quote = strtoupper(sanitize_text_field($body['quoteCurrency'] ?? '')); $rate = (float) ($body['rate'] ?? 0); $date = sanitize_text_field($body['effectiveDate'] ?? '');
        if (!in_array($base, ['USD', 'SAR', 'LBP'], true) || !in_array($quote, ['USD', 'SAR', 'LBP'], true) || $base === $quote || $rate <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return self::error('invalid_fx', 'Use two supported currencies, a positive rate and a valid effective date.');
        $wpdb->query($wpdb->prepare('INSERT INTO ' . self::table('finance_fx_rates') . ' (base_currency,quote_currency,rate,effective_date,source,created_by,created_at) VALUES (%s,%s,%f,%s,%s,%d,%s) ON DUPLICATE KEY UPDATE rate=VALUES(rate),source=VALUES(source)', $base, $quote, $rate, $date, sanitize_text_field($body['source'] ?? 'manual'), get_current_user_id(), current_time('mysql')));
        VPM_Permissions::audit('finance', 'fx_rate_recorded', 'fx_rate', 0, ['base' => $base, 'quote' => $quote, 'rate' => $rate, 'effective_date' => $date]);
        return self::finance_fx();
    }

    public static function crm_stages() {
        global $wpdb;
        return rest_ensure_response($wpdb->get_results('SELECT * FROM ' . self::table('crm_stages') . ' WHERE active=1 ORDER BY sort_order,id', ARRAY_A));
    }

    public static function crm_save_stage(WP_REST_Request $request) {
        global $wpdb; $body = self::body($request); $key = sanitize_key($body['stageKey'] ?? ''); $label = sanitize_text_field($body['label'] ?? '');
        if (!$key || !$label) return self::error('invalid_stage', 'Stage key and label are required.');
        $wpdb->query($wpdb->prepare('INSERT INTO ' . self::table('crm_stages') . ' (stage_key,label,sort_order,active,created_at) VALUES (%s,%s,%d,%d,%s) ON DUPLICATE KEY UPDATE label=VALUES(label),sort_order=VALUES(sort_order),active=VALUES(active)', $key, $label, absint($body['sortOrder'] ?? 99), isset($body['active']) ? (int) (bool) $body['active'] : 1, current_time('mysql')));
        return self::crm_stages();
    }

    private static function normalize_lead(array $body): string {
        $company = strtolower(trim((string) ($body['company'] ?? '')));
        $email = strtolower(trim((string) ($body['contactEmail'] ?? '')));
        $website = strtolower(trim((string) ($body['website'] ?? '')));
        $evidence = strtolower(trim((string) ($body['evidenceUrl'] ?? '')));
        $identity = $email ?: ($website ?: $company);
        return substr(hash('sha256', preg_replace('/\s+/', ' ', $identity . '|' . $evidence)), 0, 64);
    }

    private static function lead_dto(array $row): array {
        $row['id'] = (int) $row['id']; $row['services'] = self::json_array($row['services_json']); unset($row['services_json']);
        $row['score_reasons'] = self::json_array($row['score_reasons_json']); unset($row['score_reasons_json']);
        $row['lead_score'] = (float) $row['lead_score'];
        return $row;
    }

    public static function crm_leads(WP_REST_Request $request) {
        global $wpdb; [$page, $per_page, $offset] = self::page($request); $where = ['1=1']; $args = [];
        if ($search = trim(sanitize_text_field($request->get_param('search') ?: ''))) { $where[] = '(company LIKE %s OR contact_name LIKE %s OR contact_email LIKE %s)'; $needle = '%' . $wpdb->esc_like($search) . '%'; array_push($args, $needle, $needle, $needle); }
        if ($stage = sanitize_key($request->get_param('stage') ?: '')) { $where[] = 'stage_key=%s'; $args[] = $stage; }
        if ($review = sanitize_key($request->get_param('reviewStatus') ?: '')) { $where[] = 'review_status=%s'; $args[] = $review; }
        $sql = 'SELECT * FROM ' . self::table('crm_leads') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC,id DESC LIMIT %d OFFSET %d'; array_push($args, $per_page, $offset);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        return rest_ensure_response(['rows' => array_map([self::class, 'lead_dto'], $rows ?: []), 'page' => $page, 'perPage' => $per_page]);
    }

    public static function crm_create_lead(WP_REST_Request $request) {
        global $wpdb; $body = self::body($request); $company = sanitize_text_field($body['company'] ?? '');
        if (!$company) return self::error('missing_company', 'Company name is required.');
        $key = self::normalize_lead($body); $email = sanitize_email($body['contactEmail'] ?? ''); $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('crm_leads') . ' WHERE normalized_key=%s OR (company=%s AND company<>"") OR (contact_email=%s AND contact_email<>"") LIMIT 1', $key, $company, $email));
        if ($existing) return self::error('duplicate_lead', 'A lead with the same normalized identity already exists.', 409);
        $services = array_values(array_filter(array_map('sanitize_text_field', (array) ($body['services'] ?? [])))); $now = current_time('mysql');
        $wpdb->insert(self::table('crm_leads'), [
            'lead_type' => in_array($body['leadType'] ?? 'manual', ['manual', 'public_search', 'verified_demand', 'potential_opportunity'], true) ? $body['leadType'] : 'manual',
            'company' => $company, 'industry' => sanitize_text_field($body['industry'] ?? ''), 'country' => sanitize_text_field($body['country'] ?? ''), 'city' => sanitize_text_field($body['city'] ?? ''), 'website' => esc_url_raw($body['website'] ?? '', ['https']),
            'contact_name' => sanitize_text_field($body['contactName'] ?? ''), 'contact_role' => sanitize_text_field($body['contactRole'] ?? ''), 'contact_email' => sanitize_email($body['contactEmail'] ?? ''), 'contact_phone' => sanitize_text_field($body['contactPhone'] ?? ''), 'services_json' => wp_json_encode($services), 'opportunity_description' => sanitize_textarea_field($body['description'] ?? ''), 'lead_source' => sanitize_text_field($body['leadSource'] ?? 'manual'), 'evidence_url' => esc_url_raw($body['evidenceUrl'] ?? '', ['https']), 'publication_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['publicationDate'] ?? '') ? $body['publicationDate'] : null, 'deadline' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['deadline'] ?? '') ? $body['deadline'] : null, 'estimated_budget' => sanitize_text_field($body['estimatedBudget'] ?? ''), 'lead_score' => min(100, max(0, (float) ($body['leadScore'] ?? 0))), 'score_reasons_json' => wp_json_encode((array) ($body['scoreReasons'] ?? [])), 'assigned_user_id' => absint($body['assignedUserId'] ?? 0) ?: null, 'stage_key' => sanitize_key($body['stageKey'] ?? 'new_lead'), 'review_status' => 'pending', 'notes' => sanitize_textarea_field($body['notes'] ?? ''), 'next_follow_up_at' => sanitize_text_field($body['nextFollowUpAt'] ?? '') ?: null, 'normalized_key' => $key, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $wpdb->insert_id; VPM_Permissions::audit('crm', 'lead_created', 'lead', $id, ['lead_type' => $body['leadType'] ?? 'manual']);
        return new WP_REST_Response(['id' => $id], 201);
    }

    public static function crm_update_lead(WP_REST_Request $request) {
        global $wpdb; $id = absint($request['id']); $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('crm_leads') . ' WHERE id=%d', $id), ARRAY_A); if (!$row) return self::error('not_found', 'Lead not found.', 404);
        $body = self::body($request); $data = [];
        $map = ['company' => 'company', 'industry' => 'industry', 'country' => 'country', 'city' => 'city', 'contactName' => 'contact_name', 'contactRole' => 'contact_role', 'contactPhone' => 'contact_phone', 'leadSource' => 'lead_source', 'estimatedBudget' => 'estimated_budget', 'notes' => 'notes', 'stageKey' => 'stage_key', 'reviewStatus' => 'review_status', 'nextFollowUpAt' => 'next_follow_up_at'];
        foreach ($map as $input => $column) if (array_key_exists($input, $body)) $data[$column] = in_array($column, ['notes'], true) ? sanitize_textarea_field($body[$input]) : sanitize_text_field($body[$input]);
        if (array_key_exists('contactEmail', $body)) $data['contact_email'] = sanitize_email($body['contactEmail']);
        if (array_key_exists('website', $body)) $data['website'] = esc_url_raw($body['website'], ['https']);
        if (array_key_exists('description', $body)) $data['opportunity_description'] = sanitize_textarea_field($body['description']);
        if (array_key_exists('leadScore', $body)) $data['lead_score'] = min(100, max(0, (float) $body['leadScore']));
        if (array_key_exists('assignedUserId', $body)) $data['assigned_user_id'] = absint($body['assignedUserId']) ?: null;
        if (array_key_exists('services', $body)) $data['services_json'] = wp_json_encode(array_values(array_filter(array_map('sanitize_text_field', (array) $body['services']))));
        if (array_key_exists('scoreReasons', $body)) $data['score_reasons_json'] = wp_json_encode((array) $body['scoreReasons']);
        if (!$data) return self::error('empty_update', 'No lead fields were provided.');
        $data['updated_at'] = current_time('mysql'); $wpdb->update(self::table('crm_leads'), $data, ['id' => $id]);
        VPM_Permissions::audit('crm', 'lead_updated', 'lead', $id, ['fields' => array_keys($data)]);
        return rest_ensure_response(['updated' => true]);
    }

    public static function crm_delete_lead(WP_REST_Request $request) {
        global $wpdb; $id = absint($request['id']); if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('crm_leads') . ' WHERE id=%d', $id))) return self::error('not_found', 'Lead not found.', 404);
        $wpdb->delete(self::table('crm_lead_activities'), ['lead_id' => $id]); $wpdb->delete(self::table('crm_leads'), ['id' => $id]); VPM_Permissions::audit('crm', 'lead_deleted', 'lead', $id);
        return rest_ensure_response(['deleted' => true]);
    }

    public static function crm_activities(WP_REST_Request $request) {
        global $wpdb; $id = absint($request['id']); return rest_ensure_response($wpdb->get_results($wpdb->prepare('SELECT a.*,u.display_name FROM ' . self::table('crm_lead_activities') . ' a LEFT JOIN ' . $wpdb->users . ' u ON u.ID=a.created_by WHERE a.lead_id=%d ORDER BY a.created_at DESC', $id), ARRAY_A));
    }

    public static function crm_add_activity(WP_REST_Request $request) {
        global $wpdb; $id = absint($request['id']); $body = self::body($request); $text = sanitize_textarea_field($body['body'] ?? ''); if (!$id || !$text) return self::error('invalid_activity', 'Lead and activity text are required.');
        $wpdb->insert(self::table('crm_lead_activities'), ['lead_id' => $id, 'activity_type' => sanitize_key($body['activityType'] ?? 'note'), 'body' => $text, 'follow_up_at' => sanitize_text_field($body['followUpAt'] ?? '') ?: null, 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql')]);
        $wpdb->update(self::table('crm_leads'), ['next_follow_up_at' => sanitize_text_field($body['followUpAt'] ?? '') ?: null, 'updated_at' => current_time('mysql')], ['id' => $id]);
        return new WP_REST_Response(['id' => (int) $wpdb->insert_id], 201);
    }

    public static function hunter_sources() {
        global $wpdb; $rows = $wpdb->get_results('SELECT id,name,source_type,endpoint_url,config_json,active,last_success_at,last_error,created_at,updated_at FROM ' . self::table('crm_sources') . ' ORDER BY name ASC', ARRAY_A);
        foreach ($rows as &$row) { $row['id'] = (int) $row['id']; $row['active'] = (bool) $row['active']; $row['config'] = self::json_array($row['config_json']); unset($row['config_json']); }
        return rest_ensure_response($rows);
    }

    public static function hunter_save_source(WP_REST_Request $request) {
        global $wpdb; $body = self::body($request); $name = sanitize_text_field($body['name'] ?? ''); $url = esc_url_raw($body['endpointUrl'] ?? '', ['https']); $type = sanitize_key($body['sourceType'] ?? 'rss');
        if (!$name || !$url || !in_array($type, ['rss', 'json'], true)) return self::error('invalid_source', 'Name, HTTPS endpoint and RSS/JSON source type are required.');
        $now = current_time('mysql'); $wpdb->insert(self::table('crm_sources'), ['name' => $name, 'source_type' => $type, 'endpoint_url' => $url, 'config_json' => wp_json_encode((array) ($body['config'] ?? [])), 'active' => isset($body['active']) ? (int) (bool) $body['active'] : 1, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now]);
        return new WP_REST_Response(['id' => (int) $wpdb->insert_id], 201);
    }

    public static function hunter_scan(WP_REST_Request $request) {
        $body = self::body($request); $source_id = absint($body['sourceId'] ?? 0);
        $result = VPM_Cron::run_scan($source_id);
        if (is_wp_error($result)) return $result;
        return rest_ensure_response($result);
    }

    public static function hunter_google_status() {
        return rest_ensure_response(VPM_Cron::google_status());
    }

    public static function hunter_google_configure(WP_REST_Request $request) {
        $key = sanitize_text_field((string) (self::body($request)['apiKey'] ?? ''));
        if (!$key || strlen($key) < 20) return self::error('invalid_google_key', 'Enter the restricted Google Places API key.', 400);
        update_option('vpm_google_places_api_key', $key, false);
        VPM_Permissions::audit('crm', 'google_places_configured', 'settings', 0, ['provider' => 'Google Places API (New)']);
        return rest_ensure_response(['configured' => true, 'provider' => 'Google Places API (New)']);
    }

    public static function hunter_google_search(WP_REST_Request $request) {
        $result = VPM_Cron::google_places_search(self::body($request));
        if (is_wp_error($result)) return $result;
        VPM_Permissions::audit('crm', 'google_places_search', 'lead_discovery', 0, ['query' => $result['query'] ?? '', 'found' => (int) ($result['found'] ?? 0), 'new' => (int) ($result['new'] ?? 0)]);
        return rest_ensure_response($result);
    }

    public static function hunter_review(WP_REST_Request $request) {
        global $wpdb; $body = self::body($request); $id = absint($body['leadId'] ?? 0); $action = sanitize_key($body['action'] ?? '');
        if (!$id || !in_array($action, ['approve', 'reject', 'save'], true)) return self::error('invalid_review', 'Lead and review action are required.');
        $status = ['approve' => 'approved', 'reject' => 'rejected', 'save' => 'saved'][$action];
        $wpdb->update(self::table('crm_leads'), ['review_status' => $status, 'notes' => sanitize_textarea_field($body['note'] ?? ''), 'updated_at' => current_time('mysql')], ['id' => $id]);
        VPM_Permissions::audit('crm', 'lead_reviewed', 'lead', $id, ['review_status' => $status]);
        return rest_ensure_response(['reviewStatus' => $status]);
    }

    public static function crm_convert_lead(WP_REST_Request $request) {
        global $wpdb; $id = absint($request['id']); $lead = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('crm_leads') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$lead) return self::error('not_found', 'Lead not found.', 404);
        if (($lead['review_status'] ?? '') !== 'approved') return self::error('not_approved', 'Approve the lead before conversion.', 409);
        $company = trim((string) $lead['company']); $email = sanitize_email($lead['contact_email']); $needle = '%' . $wpdb->esc_like($company) . '%';
        $existing = $wpdb->get_row($wpdb->prepare('SELECT id,request_number,client_name,company,email FROM ' . self::table('inquiries') . ' WHERE (company LIKE %s OR (%s<>"" AND email=%s)) ORDER BY id ASC LIMIT 1', $needle, $email, $email), ARRAY_A);
        $client_key = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $company . '-' . ($email ?: '')));
        if (!$existing) {
            $now = current_time('mysql'); $number = 'CRM-' . gmdate('Ymd') . '-' . wp_rand(100000, 999999);
            $wpdb->insert(self::table('inquiries'), ['request_number' => $number, 'source' => 'crm', 'client_name' => $lead['contact_name'] ?: $company, 'company' => $company, 'email' => $email, 'phone' => $lead['contact_phone'], 'services_json' => $lead['services_json'], 'brief' => $lead['opportunity_description'], 'budget' => $lead['estimated_budget'], 'deadline' => $lead['deadline'] ?: null, 'status' => 'new', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now]);
            $existing = ['id' => (int) $wpdb->insert_id, 'request_number' => $number, 'created' => true];
        }
        $wpdb->update(self::table('crm_leads'), ['stage_key' => 'won', 'converted_client_key' => $client_key, 'updated_at' => current_time('mysql')], ['id' => $id]);
        VPM_Permissions::audit('crm', 'lead_converted', 'lead', $id, ['client_key' => $client_key, 'inquiry_id' => (int) $existing['id']]);
        return rest_ensure_response(['converted' => true, 'clientKey' => $client_key, 'inquiry' => $existing]);
    }

    public static function crm_outreach_draft(WP_REST_Request $request) {
        global $wpdb; $id = absint($request['id']); $lead = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('crm_leads') . ' WHERE id=%d', $id), ARRAY_A); if (!$lead) return self::error('not_found', 'Lead not found.', 404);
        $language = sanitize_key(self::body($request)['language'] ?? 'en'); $language = in_array($language, ['en', 'ar'], true) ? $language : 'en'; $services = implode(', ', self::json_array($lead['services_json'])); $evidence = esc_url_raw((string) $lead['evidence_url']);
        $evidence_line_en = $evidence ? "Published evidence: " . $evidence : 'We are reaching out based on your public company information.'; $evidence_line_ar = $evidence ? "المصدر المنشور: " . $evidence : 'نتواصل معكم استناداً إلى المعلومات العامة المنشورة عن شركتكم.';
        $draft = $language === 'ar' ? "الموضوع: شراكة إبداعية مع Vibes Agency\n\nمرحباً " . ($lead['contact_name'] ?: $lead['company']) . "،\n\nاطلعنا على طلبكم المنشور بخصوص " . ($services ?: 'الخدمات الإبداعية') . ". نود أن نشارككم كيف يمكن لفريق Vibes Agency دعمكم من لبنان عن بُعد.\n\n" . $evidence_line_ar . "\n\nيسعدنا إرسال نماذج أعمال وعرض مناسب لاحتياجكم.\n\nمع التحية،\nVibes Agency" : "Subject: Creative partnership with Vibes Agency\n\nHello " . ($lead['contact_name'] ?: $lead['company']) . ",\n\nWe saw your published opportunity regarding " . ($services ?: 'creative services') . ". Vibes Agency can support your team remotely from Lebanon across the requested services.\n\n" . $evidence_line_en . "\n\nWe would be happy to share relevant portfolio examples and prepare a focused proposal.\n\nBest,\nVibes Agency";
        $wpdb->insert(self::table('crm_lead_activities'), ['lead_id' => $id, 'activity_type' => 'outreach_draft', 'body' => $draft, 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql')]); VPM_Permissions::audit('crm', 'outreach_draft_created', 'lead', $id, ['language' => $language]); return rest_ensure_response(['draft' => $draft, 'requiresApprovalToSend' => true]);
    }

    private static function record_task_event(int $task_id, string $type, string $from = '', string $to = '', array $meta = []): void {
        global $wpdb; $task = $wpdb->get_row($wpdb->prepare('SELECT project_id,assignee_id FROM ' . self::table('tasks') . ' WHERE id=%d', $task_id), ARRAY_A); if (!$task) return;
        $last = $wpdb->get_row($wpdb->prepare('SELECT event_type,to_status FROM ' . self::table('analytics_task_events') . ' WHERE task_id=%d ORDER BY id DESC LIMIT 1', $task_id), ARRAY_A);
        if ($last && $last['event_type'] === $type && $last['to_status'] === $to) return;
        $wpdb->insert(self::table('analytics_task_events'), ['task_id' => $task_id, 'project_id' => $task['project_id'] ?: null, 'user_id' => $task['assignee_id'] ?: null, 'event_type' => sanitize_key($type), 'from_status' => sanitize_key($from), 'to_status' => sanitize_key($to), 'metadata_json' => wp_json_encode($meta), 'created_at' => current_time('mysql')]);
    }

    private static function direct_conversation(int $sender, int $recipient): int {
        global $wpdb;
        $conversation = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT c.id FROM ' . self::table('connect_conversations') . ' c WHERE c.type=%s AND EXISTS (SELECT 1 FROM ' . self::table('connect_members') . ' a WHERE a.conversation_id=c.id AND a.user_id=%d) AND EXISTS (SELECT 1 FROM ' . self::table('connect_members') . ' b WHERE b.conversation_id=c.id AND b.user_id=%d) AND (SELECT COUNT(*) FROM ' . self::table('connect_members') . ' m WHERE m.conversation_id=c.id)=2 ORDER BY c.id ASC LIMIT 1',
            'direct', $sender, $recipient
        ));
        if ($conversation) return $conversation;
        $now = current_time('mysql');
        $wpdb->insert(self::table('connect_conversations'), ['type' => 'direct', 'title' => self::user_name($recipient), 'created_by' => $sender, 'created_at' => $now, 'updated_at' => $now]);
        $conversation = (int) $wpdb->insert_id;
        if (!$conversation) return 0;
        $wpdb->insert(self::table('connect_members'), ['conversation_id' => $conversation, 'user_id' => $sender, 'joined_at' => $now]);
        $wpdb->insert(self::table('connect_members'), ['conversation_id' => $conversation, 'user_id' => $recipient, 'joined_at' => $now]);
        return $conversation;
    }

    private static function send_task_assignment_message(int $task_id): void {
        global $wpdb;
        $task = $wpdb->get_row($wpdb->prepare('SELECT id,title,task_number,assignee_id FROM ' . self::table('tasks') . ' WHERE id=%d', $task_id), ARRAY_A);
        $sender = get_current_user_id(); $recipient = absint($task['assignee_id'] ?? 0);
        if (!$task || !$recipient || $recipient === $sender) return;
        $conversation = self::direct_conversation($sender, $recipient); if (!$conversation) return;
        $token = '[[task:' . $task_id . ']]';
        $exists = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('connect_messages') . ' WHERE conversation_id=%d AND body LIKE %s LIMIT 1', $conversation, '%' . $wpdb->esc_like($token) . '%'));
        if ($exists) return;
        $label = trim((string) ($task['task_number'] ?: 'Task #' . $task_id));
        $body = 'Task assigned: ' . (string) $task['title'] . "\n" . $label . " · Open task manager → " . $token;
        $now = current_time('mysql');
        $wpdb->insert(self::table('connect_messages'), ['conversation_id' => $conversation, 'user_id' => $sender, 'parent_id' => null, 'body' => sanitize_textarea_field($body), 'created_at' => $now]);
        $wpdb->update(self::table('connect_conversations'), ['updated_at' => $now], ['id' => $conversation]);
        $wpdb->insert(self::table('notifications'), ['user_id' => $recipient, 'type' => 'connect_message', 'title' => 'New task assignment from ' . self::user_name($sender), 'body' => (string) $task['title'], 'object_type' => 'task', 'object_id' => $task_id, 'created_at' => $now]);
    }

    public static function observe_core_workflow($response, $handler, $request) {
        if (!$request instanceof WP_REST_Request || is_wp_error($response)) return $response;
        $route = $request->get_route(); $method = $request->get_method();
        if (preg_match('#/vibes/v1/tasks/(\d+)/(status|submit)$#', $route, $match)) {
            $status = sanitize_key($request->get_param('status') ?: ($match[2] === 'submit' ? 'review' : ''));
            self::record_task_event((int) $match[1], $match[2] === 'submit' ? 'submitted' : 'status_change', '', $status, ['route' => $route]);
        }
        if (preg_match('#/vibes/v1/tasks$#', $route) && $method === 'POST') {
            $data = $response instanceof WP_REST_Response ? $response->get_data() : (array) $response; $task_id = absint($data['id'] ?? 0); if ($task_id) { self::record_task_event($task_id, 'created', '', 'todo'); self::send_task_assignment_message($task_id); }
        }
        if (preg_match('#/vibes/v1/quotations/(\d+)/project$#', $route, $match) && $method === 'POST') {
            global $wpdb;
            $data = $response instanceof WP_REST_Response ? $response->get_data() : (array) $response;
            $project_id = absint($data['projectId'] ?? 0);
            if ($project_id) {
                $task_ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::table('tasks') . ' WHERE project_id=%d ORDER BY id ASC', $project_id));
                foreach ($task_ids as $task_id) { self::record_task_event((int) $task_id, 'created', '', 'todo', ['route' => $route]); self::send_task_assignment_message((int) $task_id); }
            }
        }
        if (preg_match('#/vibes/v1/public/projects/[^/]+/respond$#', $route) && $method === 'POST') VPM_Permissions::audit('workflow', 'client_response', 'project', 0, ['action' => sanitize_key($request->get_param('action') ?: '')]);
        return $response;
    }
}





<?php

if (!defined('ABSPATH')) {
    exit;
}

class VTP_REST {
    private const NS = 'vibes/v1';

    private static function mail_headers(bool $html = false): array {
        $headers = [
            'From: Vibes Agency <admin@vibesagency.co>',
            'Reply-To: admin@vibesagency.co',
        ];
        if ($html) $headers[] = 'Content-Type: text/html; charset=UTF-8';
        return $headers;
    }

    /**
     * Send a portal email with a normalized recipient and a conservative fallback.
     * Hostinger's SMTP mailer can reject a message when another plugin supplies a
     * conflicting From header, so the fallback lets WP Mail SMTP apply its forced
     * sender settings while preserving the message content.
     */
    private static function send_mail(string $to, string $subject, string $message, bool $html = false, array $attachments = []): bool {
        $recipient = sanitize_email(trim($to));
        if (!$recipient || !is_email($recipient)) return false;

        if (wp_mail($recipient, $subject, $message, self::mail_headers($html), $attachments)) return true;

        $fallback_headers = $html ? ['Content-Type: text/html; charset=UTF-8'] : [];
        if (wp_mail($recipient, $subject, $message, $fallback_headers, $attachments)) return true;

        return (bool) wp_mail($recipient, $subject, wp_strip_all_tags($message), [], $attachments);
    }

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void {
        register_rest_route(self::NS, '/bootstrap', [
            'methods' => 'GET', 'callback' => [self::class, 'bootstrap'],
            'permission_callback' => [self::class, 'logged_in'],
        ]);
        register_rest_route(self::NS, '/employees', [
            ['methods' => 'GET', 'callback' => [self::class, 'employees'], 'permission_callback' => [self::class, 'logged_in']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_employee'], 'permission_callback' => [self::class, 'manage_employees']],
        ]);
        register_rest_route(self::NS, '/employees/(?P<id>\d+)', [
            ['methods' => 'POST', 'callback' => [self::class, 'update_employee'], 'permission_callback' => [self::class, 'manage_employees']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'delete_employee'], 'permission_callback' => [self::class, 'manage_employees']],
        ]);
        register_rest_route(self::NS, '/projects', [
            ['methods' => 'GET', 'callback' => [self::class, 'projects'], 'permission_callback' => [self::class, 'logged_in']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_project'], 'permission_callback' => [self::class, 'manage_projects']],
        ]);
        register_rest_route(self::NS, '/projects/(?P<id>\d+)/review', [
            'methods' => 'POST', 'callback' => [self::class, 'publish_project_review'],
            'permission_callback' => [self::class, 'manage_projects'],
        ]);
        register_rest_route(self::NS, '/projects/(?P<id>\d+)/drive', [
            'methods' => 'PATCH', 'callback' => [self::class, 'update_project_drive'],
            'permission_callback' => [self::class, 'manage_projects'],
        ]);
        register_rest_route(self::NS, '/projects/(?P<id>\d+)/archive', [
            'methods' => 'POST', 'callback' => [self::class, 'archive_project'],
            'permission_callback' => [self::class, 'manage_projects'],
        ]);
        register_rest_route(self::NS, '/projects/(?P<id>\d+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'delete_project'],
            'permission_callback' => [self::class, 'manage_projects'],
        ]);
        register_rest_route(self::NS, '/public/projects/(?P<token>[a-f0-9]{64})', [
            'methods' => 'GET', 'callback' => [self::class, 'public_project_review'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/public/projects/(?P<token>[a-f0-9]{64})/respond', [
            'methods' => 'POST', 'callback' => [self::class, 'public_project_response'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/public/inquiries', [
            'methods' => 'POST', 'callback' => [self::class, 'public_inquiry'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/public/quotations/(?P<token>[a-f0-9]{64})', [
            'methods' => 'GET', 'callback' => [self::class, 'public_quotation'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/public/quotations/(?P<token>[a-f0-9]{64})/respond', [
            'methods' => 'POST', 'callback' => [self::class, 'public_quotation_response'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/inquiries', [
            ['methods' => 'GET', 'callback' => [self::class, 'inquiries'], 'permission_callback' => [self::class, 'manage_sales']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_inquiry'], 'permission_callback' => [self::class, 'manage_sales']],
        ]);
        register_rest_route(self::NS, '/inquiries/(?P<id>\d+)/status', [
            'methods' => 'PATCH', 'callback' => [self::class, 'inquiry_status'],
            'permission_callback' => [self::class, 'manage_sales'],
        ]);
        register_rest_route(self::NS, '/inquiries/(?P<id>\d+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'delete_inquiry'],
            'permission_callback' => [self::class, 'manage_sales'],
        ]);
        register_rest_route(self::NS, '/quotations', [
            ['methods' => 'GET', 'callback' => [self::class, 'quotations'], 'permission_callback' => [self::class, 'manage_sales']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_quotation'], 'permission_callback' => [self::class, 'manage_sales']],
        ]);
        register_rest_route(self::NS, '/quotations/(?P<id>\d+)/status', [
            'methods' => 'PATCH', 'callback' => [self::class, 'quotation_status'],
            'permission_callback' => [self::class, 'manage_sales'],
        ]);
        register_rest_route(self::NS, '/quotations/(?P<id>\d+)/send', [
            'methods' => 'POST', 'callback' => [self::class, 'send_quotation'],
            'permission_callback' => [self::class, 'manage_sales'],
        ]);
        register_rest_route(self::NS, '/quotations/(?P<id>\d+)/project', [
            'methods' => 'POST', 'callback' => [self::class, 'quotation_project'],
            'permission_callback' => [self::class, 'manage_sales'],
        ]);
        register_rest_route(self::NS, '/quotations/(?P<id>\d+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'delete_quotation'],
            'permission_callback' => [self::class, 'manage_sales'],
        ]);
        register_rest_route(self::NS, '/invoices', [
            ['methods' => 'GET', 'callback' => [self::class, 'invoices'], 'permission_callback' => [self::class, 'manage_sales']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_invoice'], 'permission_callback' => [self::class, 'manage_sales']],
        ]);
        register_rest_route(self::NS, '/invoices/(?P<id>\d+)/status', [
            'methods' => 'PATCH', 'callback' => [self::class, 'invoice_status'],
            'permission_callback' => [self::class, 'manage_sales'],
        ]);
        register_rest_route(self::NS, '/invoices/(?P<id>\d+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'delete_invoice'],
            'permission_callback' => [self::class, 'manage_sales'],
        ]);
        register_rest_route(self::NS, '/tasks', [
            ['methods' => 'GET', 'callback' => [self::class, 'tasks'], 'permission_callback' => [self::class, 'logged_in']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_task'], 'permission_callback' => [self::class, 'manage_tasks']],
        ]);
        register_rest_route(self::NS, '/tasks/(?P<id>\d+)/status', [
            'methods' => 'PATCH', 'callback' => [self::class, 'task_status'],
            'permission_callback' => [self::class, 'logged_in'],
        ]);
        register_rest_route(self::NS, '/tasks/(?P<id>\d+)/submit', [
            'methods' => 'POST', 'callback' => [self::class, 'submit_task'],
            'permission_callback' => [self::class, 'logged_in'],
        ]);
        register_rest_route(self::NS, '/tasks/(?P<id>\d+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'delete_task'],
            'permission_callback' => [self::class, 'manage_tasks'],
        ]);
        register_rest_route(self::NS, '/leave', [
            ['methods' => 'GET', 'callback' => [self::class, 'leave'], 'permission_callback' => [self::class, 'logged_in']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_leave'], 'permission_callback' => [self::class, 'logged_in']],
        ]);
        register_rest_route(self::NS, '/leave/(?P<id>\d+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'delete_leave'],
            'permission_callback' => [self::class, 'logged_in'],
        ]);
        register_rest_route(self::NS, '/leave/(?P<id>\d+)/review', [
            'methods' => 'PATCH', 'callback' => [self::class, 'review_leave'],
            'permission_callback' => [self::class, 'manage_leave'],
        ]);
        register_rest_route(self::NS, '/holidays', [
            ['methods' => 'GET', 'callback' => [self::class, 'holidays'], 'permission_callback' => [self::class, 'logged_in']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_holiday'], 'permission_callback' => [self::class, 'manage_leave']],
        ]);
        register_rest_route(self::NS, '/holidays/(?P<id>\d+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'delete_holiday'],
            'permission_callback' => [self::class, 'manage_leave'],
        ]);
        register_rest_route(self::NS, '/announcements', [
            ['methods' => 'GET', 'callback' => [self::class, 'announcements'], 'permission_callback' => [self::class, 'logged_in']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_announcement'], 'permission_callback' => [self::class, 'manage_announcements']],
        ]);
        register_rest_route(self::NS, '/announcements/(?P<id>\d+)', [
            ['methods' => 'PATCH', 'callback' => [self::class, 'update_announcement'], 'permission_callback' => [self::class, 'manage_announcements']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'delete_announcement'], 'permission_callback' => [self::class, 'manage_announcements']],
        ]);
        register_rest_route(self::NS, '/announcements/(?P<id>\d+)/archive', [
            'methods' => 'POST', 'callback' => [self::class, 'archive_announcement'],
            'permission_callback' => [self::class, 'manage_announcements'],
        ]);
        register_rest_route(self::NS, '/notifications', [
            'methods' => 'GET', 'callback' => [self::class, 'notifications'],
            'permission_callback' => [self::class, 'logged_in'],
        ]);
        register_rest_route(self::NS, '/profile/password', [
            'methods' => 'POST', 'callback' => [self::class, 'change_password'],
            'permission_callback' => [self::class, 'logged_in'],
        ]);
        register_rest_route(self::NS, '/profile/avatar', [
            'methods' => 'POST', 'callback' => [self::class, 'change_avatar'],
            'permission_callback' => [self::class, 'logged_in'],
        ]);
        register_rest_route(self::NS, '/profile/details', [
            'methods' => 'POST', 'callback' => [self::class, 'update_profile_details'],
            'permission_callback' => [self::class, 'manage_employees'],
        ]);
        register_rest_route(self::NS, '/files-brand', [
            ['methods' => 'GET', 'callback' => [self::class, 'files_brand'], 'permission_callback' => [self::class, 'logged_in']],
            ['methods' => 'POST', 'callback' => [self::class, 'save_files_brand'], 'permission_callback' => [self::class, 'manage_files_brand']],
        ]);
        register_rest_route(self::NS, '/quotation-templates', [
            ['methods' => 'GET', 'callback' => [self::class, 'quotation_templates'], 'permission_callback' => [self::class, 'logged_in']],
            ['methods' => 'POST', 'callback' => [self::class, 'save_quotation_templates'], 'permission_callback' => [self::class, 'manage_files_brand']],
        ]);
        register_rest_route(self::NS, '/files-brand/upload', [
            'methods' => 'POST', 'callback' => [self::class, 'upload_files_brand'],
            'permission_callback' => [self::class, 'manage_files_brand'],
        ]);
        register_rest_route(self::NS, '/files-brand/client/(?P<id>[A-Za-z0-9_-]+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'delete_files_brand_client'],
            'permission_callback' => [self::class, 'manage_files_brand'],
        ]);
    }

    public static function logged_in(): bool { return is_user_logged_in(); }
    public static function manage_employees(): bool { return current_user_can('vtp_manage_employees'); }
    public static function manage_tasks(): bool { return current_user_can('vtp_manage_tasks'); }
    public static function manage_projects(): bool { return current_user_can('vtp_manage_projects'); }
    public static function manage_sales(): bool { return current_user_can('vtp_manage_sales'); }
    public static function manage_leave(): bool { return current_user_can('vtp_manage_leave'); }
    public static function manage_announcements(): bool { return current_user_can('vtp_manage_announcements'); }
    public static function manage_files_brand(): bool {
        $user = wp_get_current_user();
        return $user && in_array('administrator', (array) $user->roles, true);
    }

    private static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'vtp_' . $name;
    }

    private static function valid_date(string $value): bool {
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }

    public static function avatar_url(int $user_id, int $size = 96): string {
        $attachment_id = (int) get_user_meta($user_id, 'vtp_avatar_id', true);
        if ($attachment_id) {
            $custom = wp_get_attachment_image_url($attachment_id, [$size, $size]);
            if ($custom) return $custom;
        }
        return get_avatar_url($user_id, ['size' => $size]);
    }

    private static function user_payload(WP_User $user): array {
        $payload = [
            'id' => $user->ID,
            'name' => $user->display_name,
            'email' => $user->user_email,
            'position' => (string) get_user_meta($user->ID, 'vtp_position', true),
            'department' => (string) get_user_meta($user->ID, 'vtp_department', true),
            'avatar' => self::avatar_url($user->ID, 192),
            'role' => in_array('administrator', $user->roles, true) ? 'admin' : (in_array('vibes_manager', $user->roles, true) ? 'manager' : 'employee'),
            'active' => !((bool) get_user_meta($user->ID, 'vtp_disabled', true)),
            'availability' => (string) get_user_meta($user->ID, 'vtp_availability', true) ?: 'available',
            'mustChangePassword' => (bool) get_user_meta($user->ID, 'vtp_force_password_change', true),
        ];
        if (current_user_can('vtp_manage_employees') || get_current_user_id() === (int) $user->ID) {
            global $wpdb;
            $payload['gender'] = (string) get_user_meta($user->ID, 'vtp_gender', true);
            $payload['phone'] = (string) get_user_meta($user->ID, 'vtp_phone', true);
            $payload['dateJoining'] = (string) get_user_meta($user->ID, 'vtp_date_joining', true);
            $payload['dateBirth'] = (string) get_user_meta($user->ID, 'vtp_date_birth', true);
            $payload['skills'] = (string) get_user_meta($user->ID, 'vtp_skills', true);
            $payload['audit'] = array_slice((array) get_user_meta($user->ID, 'vtp_employee_audit', true), 0, 8);
            $summary = ['total' => 0, 'todo' => 0, 'in_progress' => 0, 'review' => 0, 'done' => 0];
            $task_table = self::table('tasks');
            $task_rows = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS total FROM {$task_table} WHERE assignee_id=%d GROUP BY status", $user->ID), ARRAY_A);
            foreach ($task_rows as $task_row) {
                $status = sanitize_key((string) ($task_row['status'] ?? ''));
                $count = (int) ($task_row['total'] ?? 0);
                if (array_key_exists($status, $summary)) $summary[$status] = $count;
                $summary['total'] += $count;
            }
            $payload['taskSummary'] = $summary;
            $payload['salaryAmount'] = (string) get_user_meta($user->ID, 'vtp_salary_amount', true);
            $payload['salaryCurrency'] = (string) get_user_meta($user->ID, 'vtp_salary_currency', true) ?: 'USD';
            $payload['salaryCycle'] = 'monthly';
            $payload['employmentStatus'] = (string) get_user_meta($user->ID, 'vtp_employment_status', true) ?: (in_array('administrator', (array) $user->roles, true) || in_array('vibes_manager', (array) $user->roles, true) ? 'permanent' : 'probation');
        }
        return $payload;
    }

    private static function notify_admins(string $type, string $title, string $body = '', ?string $object_type = null, ?int $object_id = null): void {
        global $wpdb;
        $admins = get_users(['role' => 'administrator', 'fields' => 'ID']);
        foreach ($admins as $admin_id) {
            $wpdb->insert(self::table('notifications'), [
                'user_id' => $admin_id, 'type' => $type, 'title' => $title, 'body' => $body,
                'object_type' => $object_type, 'object_id' => $object_id, 'created_at' => current_time('mysql'),
            ]);
        }
    }

    public static function bootstrap(): WP_REST_Response {
        global $wpdb;
        $user = wp_get_current_user();
        $is_admin = current_user_can('vtp_manage_employees');
        $task_where = $is_admin ? '1=1' : $wpdb->prepare('assignee_id=%d', $user->ID);
        return rest_ensure_response([
            'user' => self::user_payload($user),
            'capabilities' => [
                'employees' => $is_admin,
                'manageTasks' => current_user_can('vtp_manage_tasks'),
                'manageProjects' => current_user_can('vtp_manage_projects'),
                'manageSales' => current_user_can('vtp_manage_sales'),
                'manageLeave' => current_user_can('vtp_manage_leave'),
                'announcements' => current_user_can('vtp_manage_announcements'),
                'catalogAdmin' => $is_admin,
                'filesAdmin' => self::manage_files_brand(),
            ],
            'stats' => [
                'employees' => count(get_users(['role__in' => ['vibes_employee', 'vibes_manager']])),
                'projects' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('projects') . " WHERE status='active'"),
                'tasks' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('tasks') . " WHERE {$task_where} AND status <> 'done'"),
                'pendingLeave' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('leave_requests') . " WHERE status='pending'"),
                'unread' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('notifications') . ' WHERE user_id=%d AND is_read=0', $user->ID)),
                'newInquiries' => current_user_can('vtp_manage_sales') ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('inquiries') . " WHERE status='new'") : 0,
                'unpaidInvoices' => current_user_can('vtp_manage_sales') ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('invoices') . " WHERE status IN ('sent','overdue')") : 0,
            ],
        ]);
    }

    private static function reference(string $prefix, string $table, string $column): string {
        global $wpdb;
        do {
            $value = $prefix . '-' . current_time('Ymd') . '-' . wp_rand(100000, 999999);
            $exists = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table($table) . " WHERE {$column}=%s", $value));
        } while ($exists);
        return $value;
    }

    private static function normalize_term($term): string {
        $term = sanitize_key((string) $term);
        if (in_array($term, ['once', 'month', 'year'], true)) return $term;
        if (preg_match('/^m([1-9]|1[0-2])$/', $term)) return $term;
        return 'once';
    }

    private static function term_months($term): int {
        $term = self::normalize_term($term);
        if ($term === 'month') return 1;
        if ($term === 'year') return 12;
        if (preg_match('/^m([1-9]|1[0-2])$/', $term, $matches)) return (int) $matches[1];
        return 0;
    }

    private static function term_label($term): string {
        $months = self::term_months($term);
        if ($months > 0) return $months . ' month' . ($months === 1 ? '' : 's');
        return 'One-time project';
    }

    private static function clean_service_items($value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value)));
        }
        if (!is_array($value)) return [];
        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $name = sanitize_text_field($item['name'] ?? $item['description'] ?? '');
                if (!$name) continue;
                $quantity = max(0.01, (float) ($item['quantity'] ?? $item['qty'] ?? 1));
                $unit_price = max(0, (float) ($item['unitPrice'] ?? $item['price'] ?? 0));
                $term = self::normalize_term($item['term'] ?? $item['billing'] ?? 'once');
                $duration_months = max(0, min(12, (int) ($item['durationMonths'] ?? self::term_months($term))));
                $duration_rate = min(100, max(0, (float) ($item['durationDiscount'] ?? $item['annualDiscount'] ?? 0)));
                $items[] = [
                    'name' => $name,
                    'label' => strtoupper(substr(sanitize_key($item['label'] ?? ''), 0, 12)),
                    'quantity' => $quantity,
                    'unitPrice' => round($unit_price, 2),
                    'total' => round(max(0, (float) ($item['total'] ?? ($quantity * $unit_price))), 2),
                    'billing' => $term === 'once' ? 'once' : 'month',
                    'term' => $term,
                    'durationMonths' => $duration_months,
                    'durationDiscount' => round($duration_rate, 2),
                    'annualDiscount' => round($duration_rate, 2),
                    'packageName' => sanitize_text_field($item['packageName'] ?? ''),
                    'currency' => in_array(($item['currency'] ?? 'SAR'), ['SAR', 'USD'], true) ? $item['currency'] : 'SAR',
                    'custom' => !empty($item['custom']),
                    'fromPrice' => !empty($item['fromPrice']),
                ];
                continue;
            }
            $line = sanitize_text_field($item);
            if (!$line) continue;
            $quantity = 1;
            $name = $line;
            if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*[x×]\s*(.+)$/u', $line, $matches)) {
                $quantity = max(0.01, (float) $matches[1]);
                $name = sanitize_text_field($matches[2]);
            }
            $items[] = ['name' => $name, 'label' => '', 'quantity' => $quantity, 'unitPrice' => 0, 'total' => 0, 'billing' => 'once', 'term' => 'once', 'durationMonths' => 0, 'durationDiscount' => 0, 'annualDiscount' => 0, 'packageName' => '', 'currency' => 'SAR', 'custom' => false, 'fromPrice' => false];
        }
        return array_values($items);
    }

    private static function insert_inquiry(WP_REST_Request $request, string $source, ?int $creator) {
        global $wpdb;
        $name = sanitize_text_field($request['clientName']);
        $email = sanitize_email($request['email']);
        $brief = sanitize_textarea_field($request['brief']);
        if (!$name || !$email || !$brief) return new WP_Error('missing_fields', 'Client name, email and project brief are required.', ['status' => 400]);
        $deadline = sanitize_text_field($request['deadline']);
        if ($deadline && !self::valid_date($deadline)) return new WP_Error('invalid_deadline', 'Choose a valid deadline.', ['status' => 400]);
        $now = current_time('mysql');
        $number = self::reference('VR', 'inquiries', 'request_number');
        $service_input = $request->get_param('serviceDetails');
        if ($service_input === null || $service_input === '') $service_input = $request->get_param('services');
        $service_items = self::clean_service_items($service_input);
        $wpdb->insert(self::table('inquiries'), [
            'request_number' => $number, 'source' => sanitize_key($source), 'client_name' => $name,
            'company' => sanitize_text_field($request['company']), 'email' => $email,
            'phone' => sanitize_text_field($request['phone']), 'services_json' => wp_json_encode($service_items),
            'brief' => $brief, 'budget' => sanitize_text_field($request['budget']), 'deadline' => $deadline ?: null,
            'status' => 'new', 'created_by' => $creator, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $wpdb->insert_id;
        self::notify_admins('new_inquiry', 'New client request ' . $number, $name . ($request['company'] ? ' · ' . sanitize_text_field($request['company']) : ''), 'inquiry', $id);
        return ['id' => $id, 'requestNumber' => $number];
    }

    public static function public_inquiry(WP_REST_Request $request) {
        if ((string) $request['website'] !== '') return new WP_Error('spam', 'Unable to submit this request.', ['status' => 400]);
        $origin = get_http_origin();
        $host = strtolower((string) wp_parse_url($origin, PHP_URL_HOST));
        if (!in_array($host, ['vibesagency.co', 'www.vibesagency.co'], true)) return new WP_Error('invalid_origin', 'Submit requests from vibesagency.co.', ['status' => 403]);
        $ip = sanitize_key(md5((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')));
        if (get_transient('vtp_inquiry_' . $ip)) return new WP_Error('rate_limited', 'Please wait a minute before submitting another request.', ['status' => 429]);
        set_transient('vtp_inquiry_' . $ip, 1, MINUTE_IN_SECONDS);
        $result = self::insert_inquiry($request, 'website', null);
        if (is_wp_error($result)) return $result;
        wp_mail(['info@vibesagency.co', 'info@solaraevents.net'], 'New Vibes project request ' . $result['requestNumber'], "A new project request was submitted on vibesagency.co.\n\nClient: " . sanitize_text_field($request['clientName']) . "\nEmail: " . sanitize_email($request['email']) . "\n\nOpen the Vibes Team Portal to review it.", self::mail_headers());
        return new WP_REST_Response($result, 201);
    }

    public static function inquiries(): WP_REST_Response {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table('inquiries') . ' ORDER BY created_at DESC', ARRAY_A);
        foreach ($rows as &$row) {
            $decoded = json_decode($row['services_json'] ?: '[]', true) ?: [];
            $row['serviceItems'] = self::clean_service_items($decoded);
            $row['services'] = array_values(array_filter(array_map(static fn($item) => $item['name'] ?? '', $row['serviceItems'])));
        }
        return rest_ensure_response($rows);
    }

    public static function create_inquiry(WP_REST_Request $request) {
        $source = sanitize_key($request['source']) ?: 'manual';
        $allowed = ['manual', 'email', 'phone', 'whatsapp', 'referral', 'website'];
        $result = self::insert_inquiry($request, in_array($source, $allowed, true) ? $source : 'manual', get_current_user_id());
        if (is_wp_error($result)) return $result;
        return new WP_REST_Response($result, 201);
    }

    public static function inquiry_status(WP_REST_Request $request) {
        global $wpdb;
        $status = sanitize_key($request['status']);
        $allowed = ['new', 'contacted', 'brief_prepared', 'quote_sent', 'approved', 'closed'];
        if (!in_array($status, $allowed, true)) return new WP_Error('invalid_status', 'Choose a valid inquiry status.', ['status' => 400]);
        $wpdb->update(self::table('inquiries'), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => (int) $request['id']]);
        return rest_ensure_response(['success' => true]);
    }

    public static function delete_inquiry(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('inquiries') . ' WHERE id=%d', $id))) {
            return new WP_Error('not_found', 'Inquiry not found.', ['status' => 404]);
        }
        // Keep any quotation history intact while removing the test inquiry.
        $wpdb->update(self::table('quotations'), ['inquiry_id' => null], ['inquiry_id' => $id]);
        $wpdb->delete(self::table('notifications'), ['object_type' => 'inquiry', 'object_id' => $id]);
        $wpdb->delete(self::table('inquiries'), ['id' => $id]);
        return rest_ensure_response(['deleted' => true]);
    }

    public static function quotations(): WP_REST_Response {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT q.*, i.request_number FROM ' . self::table('quotations') . ' q LEFT JOIN ' . self::table('inquiries') . ' i ON i.id=q.inquiry_id ORDER BY q.created_at DESC', ARRAY_A);
        foreach ($rows as &$row) $row['items'] = json_decode($row['items_json'] ?: '[]', true) ?: [];
        return rest_ensure_response($rows);
    }

    public static function create_quotation(WP_REST_Request $request) {
        global $wpdb;
        $items = $request->get_param('items');
        if (!is_array($items) || !$items) return new WP_Error('missing_items', 'Add at least one quotation item.', ['status' => 400]);
        $safe_items = [];
        // The form quantity is the recurring monthly quantity for long-term
        // quotations. Keep that value on the child quotations, then expand the
        // master quotation to the full contract scope after the term is known.
        $monthly_subtotal = 0.0;
        foreach ($items as $item) {
            $description = sanitize_text_field($item['description'] ?? '');
            if (!$description) continue;
            $quantity = max(0.01, (float) ($item['quantity'] ?? 1));
            $unit_price = max(0, (float) ($item['unitPrice'] ?? 0));
            $total = round($quantity * $unit_price, 2);
            $monthly_subtotal += $total;
            $term = self::normalize_term($item['term'] ?? $item['billing'] ?? 'once');
            $duration_months = max(0, min(12, (int) ($item['durationMonths'] ?? self::term_months($term))));
            $duration_rate = min(100, max(0, (float) ($item['durationDiscount'] ?? $item['annualDiscount'] ?? 0)));
            $safe_items[] = [
                'label' => strtoupper(substr(sanitize_key($item['label'] ?? ''), 0, 12)),
                'description' => $description,
                'quantity' => $quantity,
                'unitPrice' => round($unit_price, 2),
                'total' => $total,
                'billing' => $term === 'once' ? 'once' : 'month',
                'term' => $term,
                'durationMonths' => $duration_months,
                'durationDiscount' => round($duration_rate, 2),
                'annualDiscount' => round($duration_rate, 2),
                'packageName' => sanitize_text_field($item['packageName'] ?? ''),
                'quantityScope' => 'monthly',
            ];
        }
        if (!$safe_items) return new WP_Error('missing_items', 'Add at least one valid quotation item.', ['status' => 400]);
        $name = sanitize_text_field($request['clientName']);
        $email = sanitize_email($request['email']);
        if (!$name || !$email) return new WP_Error('missing_fields', 'Client name and email are required.', ['status' => 400]);
        $vat_rate = min(100, max(0, (float) $request['vatRate']));
        $valid_until = sanitize_text_field($request['validUntil']);
        if ($valid_until && !self::valid_date($valid_until)) return new WP_Error('invalid_date', 'Choose a valid quotation date.', ['status' => 400]);
        $contract_type = sanitize_key((string) ($request['contractType'] ?? 'one_time'));
        $contract_months = max(1, min(60, (int) ($request['contractMonths'] ?? 1)));
        if ($contract_type !== 'long_term' || $contract_months < 2) {
            $contract_type = 'one_time';
            $contract_months = 1;
        }
        $contract_start = sanitize_text_field((string) ($request['contractStart'] ?? ''));
        if ($contract_start && !self::valid_date($contract_start)) return new WP_Error('invalid_contract_start', 'Choose a valid contract start date.', ['status' => 400]);
        if ($contract_months > 1 && !$contract_start) return new WP_Error('missing_contract_start', 'Choose a contract start date for a long-term quotation.', ['status' => 400]);
        $contract_end = sanitize_text_field((string) ($request['contractEnd'] ?? ''));
        if ($contract_end && !self::valid_date($contract_end)) return new WP_Error('invalid_contract_end', 'Choose a valid contract end date.', ['status' => 400]);
        if ($contract_months > 1) {
            $start_date = new DateTime($contract_start);
            $end_date = (clone $start_date)->modify('+' . $contract_months . ' months -1 day');
            $contract_end = $end_date->format('Y-m-d');
        }
        // Contract discounts are applied to the full contracted scope and to
        // each monthly quotation. Allow an explicit rate, but keep the
        // agreed defaults for six- and twelve-month contracts.
        $default_discount = $contract_months === 6 ? 5 : ($contract_months === 12 ? 10 : 0);
        $discount_rate = min(100, max(0, (float) ($request['discountRate'] ?? $default_discount)));
        // The master quotation represents the complete contracted scope. A
        // service requested at 2 units per month therefore appears as 12 units
        // on a six-month master and 24 units on a one-year master. Monthly
        // children retain the original monthly quantity.
        $master_items = $safe_items;
        $subtotal = 0.0;
        foreach ($master_items as &$master_item) {
            if ($contract_months > 1) {
                $master_item['quantity'] = round((float) $master_item['quantity'] * $contract_months, 2);
                $master_item['total'] = round((float) $master_item['quantity'] * (float) $master_item['unitPrice'], 2);
                $master_item['quantityScope'] = 'contract';
            } else {
                $master_item['quantityScope'] = 'one_time';
            }
            $subtotal += (float) $master_item['total'];
        }
        unset($master_item);
        $discount_amount = round($subtotal * ($discount_rate / 100), 2);
        $discounted_subtotal = round($subtotal - $discount_amount, 2);
        $vat_amount = round($discounted_subtotal * ($vat_rate / 100), 2);
        $template_key = $contract_months === 12 ? 'one_year' : ($contract_months === 6 ? 'six_months' : 'one_time');
        $token = bin2hex(random_bytes(32));
        $now = current_time('mysql');
        $quote_number = self::reference('VQ', 'quotations', 'quote_number');
        $wpdb->insert(self::table('quotations'), [
            'quote_number' => $quote_number,
            'inquiry_id' => (int) $request['inquiryId'] ?: null,
            'client_name' => $name,
            'company' => sanitize_text_field($request['company']),
            'email' => $email,
            'phone' => sanitize_text_field($request['phone']),
            'currency' => in_array($request['currency'], ['SAR', 'USD'], true) ? $request['currency'] : 'SAR',
            'subtotal' => round($subtotal, 2),
            'discount_rate' => round($discount_rate, 2),
            'discount_amount' => $discount_amount,
            'vat_rate' => round($vat_rate, 2),
            'vat_amount' => $vat_amount,
            'total' => round($discounted_subtotal + $vat_amount, 2),
            'valid_until' => $valid_until ?: null,
            'status' => 'draft',
            'terms' => sanitize_textarea_field($request['terms']),
            'notes' => sanitize_textarea_field($request['notes']),
            'items_json' => wp_json_encode($master_items),
            'contract_type' => $contract_type,
            'contract_months' => $contract_months,
            'contract_start' => $contract_start ?: null,
            'contract_end' => $contract_end ?: null,
            'parent_quotation_id' => null,
            'period_index' => 0,
            'period_start' => null,
            'period_end' => null,
            'template_key' => $template_key,
            'approval_token' => $token,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $wpdb->insert_id;
        if (!$id) return new WP_Error('save_failed', 'The quotation could not be saved.', ['status' => 500]);
        $monthly_count = 0;
        if ($contract_months > 1) {
            $start_date = new DateTime($contract_start);
            for ($period = 1; $period <= $contract_months; $period++) {
                $period_start = (clone $start_date)->modify('+' . ($period - 1) . ' months');
                $period_end = (clone $start_date)->modify('+' . $period . ' months -1 day');
                $period_items = [];
                $period_subtotal = 0.0;
                foreach ($safe_items as $item) {
                    $monthly_quantity = max(0.01, round((float) $item['quantity'], 2));
                    $line_total = round($monthly_quantity * (float) $item['unitPrice'], 2);
                    $period_subtotal += $line_total;
                    $period_items[] = array_merge($item, ['quantity' => $monthly_quantity, 'total' => $line_total, 'quantityScope' => 'monthly']);
                }
                $period_discount = round($period_subtotal * ($discount_rate / 100), 2);
                $period_discounted_subtotal = round($period_subtotal - $period_discount, 2);
                $period_vat = round($period_discounted_subtotal * ($vat_rate / 100), 2);
                $wpdb->insert(self::table('quotations'), [
                    'quote_number' => $quote_number . '-M' . str_pad((string) $period, 2, '0', STR_PAD_LEFT),
                    'inquiry_id' => (int) $request['inquiryId'] ?: null,
                    'client_name' => $name,
                    'company' => sanitize_text_field($request['company']),
                    'email' => $email,
                    'phone' => sanitize_text_field($request['phone']),
                    'currency' => in_array($request['currency'], ['SAR', 'USD'], true) ? $request['currency'] : 'SAR',
                    'subtotal' => round($period_subtotal, 2),
                    'discount_rate' => round($discount_rate, 2),
                    'discount_amount' => $period_discount,
                    'vat_rate' => round($vat_rate, 2),
                    'vat_amount' => $period_vat,
                    'total' => round($period_discounted_subtotal + $period_vat, 2),
                    'valid_until' => $valid_until ?: null,
                    'status' => 'draft',
                    'terms' => sanitize_textarea_field($request['terms']),
                    'notes' => trim(sanitize_textarea_field($request['notes']) . "\nMonthly quotation {$period} of {$contract_months} · " . $period_start->format('F Y') . '.'),
                    'items_json' => wp_json_encode($period_items),
                    'contract_type' => 'monthly',
                    'contract_months' => $contract_months,
                    'contract_start' => $contract_start,
                    'contract_end' => $contract_end,
                    'parent_quotation_id' => $id,
                    'period_index' => $period,
                    'period_start' => $period_start->format('Y-m-d'),
                    'period_end' => $period_end->format('Y-m-d'),
                    'template_key' => 'one_time',
                    'approval_token' => bin2hex(random_bytes(32)),
                    'created_by' => get_current_user_id(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($wpdb->insert_id) $monthly_count++;
            }
        }
        if ((int) $request['inquiryId']) $wpdb->update(self::table('inquiries'), ['status' => 'brief_prepared', 'updated_at' => $now], ['id' => (int) $request['inquiryId']]);
        return new WP_REST_Response(['id' => $id, 'monthlyCount' => $monthly_count, 'contractMonths' => $contract_months, 'discountRate' => $discount_rate, 'templateKey' => $template_key], 201);
    }

    public static function create_quotation_legacy(WP_REST_Request $request) {
        global $wpdb;
        $items = $request->get_param('items');
        if (!is_array($items) || !$items) return new WP_Error('missing_items', 'Add at least one quotation item.', ['status' => 400]);
        $safe_items = [];
        $subtotal = 0.0;
        foreach ($items as $item) {
            $description = sanitize_text_field($item['description'] ?? '');
            $quantity = max(0.01, (float) ($item['quantity'] ?? 1));
            $unit = max(0, (float) ($item['unitPrice'] ?? 0));
            if (!$description) continue;
            $line = round($quantity * $unit, 2);
            $subtotal += $line;
            $safe_items[] = ['description' => $description, 'quantity' => $quantity, 'unitPrice' => $unit, 'total' => $line];
        }
        if (!$safe_items) return new WP_Error('missing_items', 'Add at least one valid quotation item.', ['status' => 400]);
        $name = sanitize_text_field($request['clientName']);
        $email = sanitize_email($request['email']);
        if (!$name || !$email) return new WP_Error('missing_client', 'Client name and email are required.', ['status' => 400]);
        $vat_rate = max(0, min(100, (float) $request['vatRate']));
        $vat = round($subtotal * $vat_rate / 100, 2);
        $now = current_time('mysql');
        $valid = sanitize_text_field($request['validUntil']);
        $wpdb->insert(self::table('quotations'), [
            'quote_number' => self::reference('VQ', 'quotations', 'quote_number'), 'inquiry_id' => (int) $request['inquiryId'] ?: null,
            'client_name' => $name, 'company' => sanitize_text_field($request['company']), 'email' => $email,
            'phone' => sanitize_text_field($request['phone']), 'currency' => in_array($request['currency'], ['SAR', 'USD'], true) ? $request['currency'] : 'SAR',
            'subtotal' => round($subtotal, 2), 'vat_rate' => $vat_rate, 'vat_amount' => $vat, 'total' => round($subtotal + $vat, 2),
            'valid_until' => $valid && self::valid_date($valid) ? $valid : null, 'status' => 'draft',
            'terms' => sanitize_textarea_field($request['terms']), 'notes' => sanitize_textarea_field($request['notes']),
            'items_json' => wp_json_encode($safe_items), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $wpdb->insert_id;
        if ((int) $request['inquiryId']) $wpdb->update(self::table('inquiries'), ['status' => 'brief_prepared', 'updated_at' => $now], ['id' => (int) $request['inquiryId']]);
        return new WP_REST_Response(['id' => $id], 201);
    }

    private static function public_quote_payload(array $quote): array {
        return [
            'quote_number' => $quote['quote_number'],
            'client_name' => $quote['client_name'],
            'company' => $quote['company'],
            'email' => $quote['email'],
            'phone' => $quote['phone'],
            'created_at' => $quote['created_at'],
            'currency' => $quote['currency'],
            'subtotal' => (float) $quote['subtotal'],
            'discount_rate' => (float) ($quote['discount_rate'] ?? 0),
            'discount_amount' => (float) ($quote['discount_amount'] ?? 0),
            'vat_rate' => (float) $quote['vat_rate'],
            'vat_amount' => (float) $quote['vat_amount'],
            'total' => (float) $quote['total'],
            'valid_until' => $quote['valid_until'],
            'status' => $quote['status'],
            'terms' => $quote['terms'],
            'notes' => $quote['notes'],
            'contract_type' => $quote['contract_type'] ?? 'one_time',
            'contract_months' => (int) ($quote['contract_months'] ?? 1),
            'contract_start' => $quote['contract_start'] ?? null,
            'contract_end' => $quote['contract_end'] ?? null,
            'parent_quotation_id' => (int) ($quote['parent_quotation_id'] ?? 0),
            'period_index' => (int) ($quote['period_index'] ?? 0),
            'period_start' => $quote['period_start'] ?? null,
            'period_end' => $quote['period_end'] ?? null,
            'template_key' => $quote['template_key'] ?? 'one_time',
            'drive_folder_url' => $quote['drive_folder_url'] ?? '',
            'drive_file_url' => $quote['drive_file_url'] ?? '',
            'items' => json_decode($quote['items_json'] ?: '[]', true) ?: [],
        ];
    }

    public static function send_quotation(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $quote = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('quotations') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$quote) return new WP_Error('not_found', 'Quotation not found.', ['status' => 404]);
        if (!$quote['email']) return new WP_Error('missing_email', 'Add the client email before sending.', ['status' => 400]);
        $token = preg_match('/^[a-f0-9]{64}$/', (string) $quote['approval_token']) ? $quote['approval_token'] : bin2hex(random_bytes(32));
        $review_url = add_query_arg('token', $token, home_url('/quotation-review/'));
        $subject = 'Vibes Agency quotation ' . $quote['quote_number'];
        $client = esc_html($quote['client_name']);
        $total = esc_html($quote['currency'] . ' ' . number_format((float) $quote['total'], 2));
        $message = '<div style="font-family:Arial,sans-serif;color:#231a30;line-height:1.6;max-width:620px;margin:auto"><h1 style="color:#60338d">Your Vibes quotation is ready</h1><p>Hello ' . $client . ',</p><p>We prepared quotation <strong>' . esc_html($quote['quote_number']) . '</strong> with a total of <strong>' . $total . '</strong>.</p><p>Use the secure button below to review the scope and price, approve the quotation, request changes or ask a question.</p><p style="margin:28px 0"><a href="' . esc_url($review_url) . '" style="background:#60338d;color:#fff;text-decoration:none;padding:13px 22px;border-radius:9px;font-weight:bold">Review quotation</a></p><p>No payment is required at this stage. Work begins after your approval and confirmation from Vibes Agency.</p><p>Vibes Agency<br>Beirut, Lebanon<br>+96170606046<br><a href="https://vibesagency.co">vibesagency.co</a></p></div>';
        if (!self::send_mail((string) $quote['email'], $subject, $message, true)) {
            return new WP_Error('mail_failed', 'The quotation could not be emailed. Check the site email configuration and try again.', ['status' => 500]);
        }
        $now = current_time('mysql');
        $wpdb->update(self::table('quotations'), ['approval_token' => $token, 'status' => 'sent', 'sent_at' => $now, 'updated_at' => $now], ['id' => $id]);
        if ((int) $quote['inquiry_id']) $wpdb->update(self::table('inquiries'), ['status' => 'quote_sent', 'updated_at' => $now], ['id' => (int) $quote['inquiry_id']]);
        return rest_ensure_response(['success' => true, 'reviewUrl' => $review_url, 'message' => 'Quotation emailed to ' . $quote['email'] . '.']);
    }

    public static function public_quotation(WP_REST_Request $request) {
        global $wpdb;
        $token = sanitize_key($request['token']);
        $quote = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('quotations') . ' WHERE approval_token=%s', $token), ARRAY_A);
        if (!$quote) return new WP_Error('not_found', 'This quotation link is invalid or unavailable.', ['status' => 404]);
        if ($quote['valid_until'] && $quote['valid_until'] < current_time('Y-m-d') && !in_array($quote['status'], ['approved', 'changes_requested'], true)) {
            $wpdb->update(self::table('quotations'), ['status' => 'expired', 'updated_at' => current_time('mysql')], ['id' => (int) $quote['id']]);
            $quote['status'] = 'expired';
        }
        return rest_ensure_response(self::public_quote_payload($quote));
    }

    public static function public_quotation_response(WP_REST_Request $request) {
        global $wpdb;
        $token = sanitize_key($request['token']);
        $quote = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('quotations') . ' WHERE approval_token=%s', $token), ARRAY_A);
        if (!$quote) return new WP_Error('not_found', 'This quotation link is invalid or unavailable.', ['status' => 404]);
        if ($quote['valid_until'] && $quote['valid_until'] < current_time('Y-m-d')) return new WP_Error('expired', 'This quotation has expired. Please contact Vibes Agency for an updated quotation.', ['status' => 410]);
        if ($quote['status'] === 'approved') return new WP_Error('already_approved', 'This quotation has already been approved.', ['status' => 409]);
        $action = sanitize_key($request['action']);
        if (!in_array($action, ['approve', 'changes', 'question'], true)) return new WP_Error('invalid_action', 'Choose a valid response.', ['status' => 400]);
        $name = sanitize_text_field($request['name']);
        $message = sanitize_textarea_field($request['message']);
        if (!$name) return new WP_Error('missing_name', 'Enter your full name.', ['status' => 400]);
        if ($action === 'approve' && !$request['accepted']) return new WP_Error('approval_required', 'Confirm that you are authorized to approve the quotation.', ['status' => 400]);
        if ($action !== 'approve' && !$message) return new WP_Error('missing_message', 'Add your question or requested changes.', ['status' => 400]);
        $ip_key = 'vtp_quote_response_' . md5($token . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        if (get_transient($ip_key)) return new WP_Error('rate_limited', 'Please wait before sending another response.', ['status' => 429]);
        set_transient($ip_key, 1, 10);
        $now = current_time('mysql');
        $status = $action === 'approve' ? 'approved' : ($action === 'changes' ? 'changes_requested' : $quote['status']);
        $wpdb->update(self::table('quotations'), ['status' => $status, 'client_action_at' => $now, 'client_action_name' => $name, 'client_message' => $message, 'updated_at' => $now], ['id' => (int) $quote['id']]);
        if ($action === 'approve' && (int) $quote['inquiry_id']) $wpdb->update(self::table('inquiries'), ['status' => 'approved', 'updated_at' => $now], ['id' => (int) $quote['inquiry_id']]);
        $titles = ['approve' => 'Quotation approved', 'changes' => 'Quotation changes requested', 'question' => 'New quotation question'];
        self::notify_admins('quotation_' . $action, $titles[$action] . ' · ' . $quote['quote_number'], $name . ($message ? ' · ' . $message : ''), 'quotation', (int) $quote['id']);
        wp_mail(['info@vibesagency.co', 'info@solaraevents.net'], $titles[$action] . ' — ' . $quote['quote_number'], "Client: {$name}\nQuotation: {$quote['quote_number']}\nResponse: {$action}\n\n{$message}\n\nOpen the Vibes Team Portal to review it.", self::mail_headers());
        $response_message = $action === 'approve' ? 'Quotation approved. Vibes Agency has been notified.' : ($action === 'changes' ? 'Your requested changes were sent to Vibes Agency.' : 'Your question was sent to Vibes Agency.');
        return rest_ensure_response(['success' => true, 'message' => $response_message]);
    }

    public static function quotation_status(WP_REST_Request $request) {
        global $wpdb;
        $status = sanitize_key($request['status']);
        if (!in_array($status, ['draft', 'sent', 'approved', 'changes_requested', 'declined', 'expired'], true)) return new WP_Error('invalid_status', 'Choose a valid quotation status.', ['status' => 400]);
        $wpdb->update(self::table('quotations'), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => (int) $request['id']]);
        return rest_ensure_response(['success' => true]);
    }

    public static function delete_quotation(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $root = $wpdb->get_row($wpdb->prepare('SELECT id, parent_quotation_id FROM ' . self::table('quotations') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$root) {
            return new WP_Error('not_found', 'Quotation not found.', ['status' => 404]);
        }
        // Deleting a master quotation must remove its monthly children too.
        // Keep projects, tasks, and issued invoices available for accounting,
        // but unlink every affected quotation so no orphaned child drafts remain.
        $root_id = (int) ($root['parent_quotation_id'] ?: $root['id']);
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . self::table('quotations') . ' WHERE id=%d OR parent_quotation_id=%d',
            $root_id,
            $root_id
        )));
        if (!in_array($root_id, $ids, true)) $ids[] = $root_id;
        foreach ($ids as $quotation_id) {
            $wpdb->update(self::table('projects'), ['quotation_id' => null], ['quotation_id' => $quotation_id]);
            $wpdb->update(self::table('tasks'), ['quotation_id' => null], ['quotation_id' => $quotation_id]);
            $wpdb->update(self::table('invoices'), ['quotation_id' => null], ['quotation_id' => $quotation_id]);
            $wpdb->delete(self::table('notifications'), ['object_type' => 'quotation', 'object_id' => $quotation_id]);
        }
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('quotations') . ' WHERE id IN (' . $placeholders . ')', ...$ids));
        }
        return rest_ensure_response(['deleted' => true, 'deletedCount' => count($ids), 'rootQuotationId' => $root_id]);
    }

    private static function project_months(string $term): array {
        $count = self::term_months($term);
        if ($count <= 0) return [];
        $start = new DateTimeImmutable(wp_date('Y-m-01', strtotime('+1 month', current_time('timestamp'))), wp_timezone());
        $months = [];
        for ($index = 0; $index < $count; $index++) {
            $month = $start->modify('+' . $index . ' months');
            $months[] = ['key' => $month->format('Y-m'), 'label' => $month->format('F Y')];
        }
        return $months;
    }

    private static function project_structure(string $project_name, array $items, string $term): array {
        $months = self::project_months($term);
        if (!$months) $months = [['key' => wp_date('Y-m', current_time('timestamp')), 'label' => wp_date('F Y', current_time('timestamp'))]];
        foreach ($months as &$month) {
            $month['services'] = [];
            foreach ($items as $index => $item) {
                $month['services'][] = [
                    'itemIndex' => (int) $index,
                    'label' => strtoupper(substr(sanitize_key($item['label'] ?? ''), 0, 20)) ?: 'SERVICE-' . ($index + 1),
                    'name' => sanitize_text_field($item['description'] ?? ('Service ' . ($index + 1))),
                    'folderName' => strtoupper(substr(sanitize_key($item['label'] ?? ''), 0, 20)) . ' — ' . sanitize_text_field($item['description'] ?? ('Service ' . ($index + 1))),
                ];
            }
        }
        return [
            'internalRootName' => sanitize_text_field($project_name) . ' — Internal',
            'clientRootName' => sanitize_text_field($project_name) . ' — Client Review',
            'term' => $term,
            'months' => array_values($months),
        ];
    }

    private static function pdf_text(string $text): string {
        $text = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;
        $text = preg_replace('/[^\x20-\x7E]/', '?', $text);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /** Create a compact, printable Vibes invoice PDF without requiring a third-party library. */
    private static function invoice_pdf(array $invoice, array $project): ?string {
        $items = json_decode($invoice['items_json'] ?? '[]', true) ?: [];
        $currency = (string) ($invoice['currency'] ?: 'SAR');
        $lines = [];
        $y = 680;
        $add = static function (string $text, float $x, float $size, string $color = '0.14 0.10 0.19') use (&$lines, &$y): void {
            $lines[] = [$text, $x, $y, $size, $color];
            $y -= $size + 8;
        };
        $add('VIBES AGENCY', 52, 24, '1 1 1');
        $add('CREATIVE SERVICES INVOICE', 52, 10, '1 1 1');
        $y = 676;
        $add('Invoice ' . ($invoice['invoice_number'] ?? ''), 380, 12, '0.38 0.20 0.55');
        $add('Issued ' . wp_date('d M Y', strtotime((string) ($invoice['created_at'] ?? current_time('mysql')))), 380, 9, '0.36 0.32 0.42');
        $add('Due ' . wp_date('d M Y', strtotime((string) ($invoice['due_date'] ?? current_time('mysql')))), 380, 9, '0.36 0.32 0.42');
        $y = 590;
        $add('BILLED TO', 52, 8, '0.38 0.20 0.55');
        $add((string) ($invoice['company'] ?: $invoice['client_name']), 52, 14);
        $add((string) $invoice['email'], 52, 9, '0.36 0.32 0.42');
        if (!empty($invoice['phone'])) $add((string) $invoice['phone'], 52, 9, '0.36 0.32 0.42');
        $y = 590;
        $add('PROJECT', 330, 8, '0.38 0.20 0.55');
        $add((string) ($project['name'] ?? 'Vibes Agency project'), 330, 11);
        $y = 480;
        $lines[] = ['SERVICE / DELIVERABLE', 52, $y, 9, '1 1 1'];
        $lines[] = ['QTY', 400, $y, 9, '1 1 1'];
        $lines[] = ['TOTAL', 500, $y, 9, '1 1 1'];
        $y -= 26;
        foreach ($items as $item) {
            $label = strtoupper((string) ($item['label'] ?? ''));
            $description = trim(($label ? $label . ' - ' : '') . (string) ($item['description'] ?? 'Service'));
            $description = function_exists('mb_strimwidth') ? mb_strimwidth($description, 0, 54, '...') : substr($description, 0, 54);
            $lines[] = [$description, 52, $y, 9, '0.14 0.10 0.19'];
            $lines[] = [number_format((float) ($item['quantity'] ?? 1), 2), 400, $y, 9, '0.14 0.10 0.19'];
            $lines[] = [$currency . ' ' . number_format((float) ($item['total'] ?? 0), 2), 500, $y, 9, '0.14 0.10 0.19'];
            $y -= 22;
        }
        $y -= 8;
        $add('Subtotal', 390, 9, '0.36 0.32 0.42');
        $lines[] = [$currency . ' ' . number_format((float) $invoice['subtotal'], 2), 500, $y + 17, 10, '0.14 0.10 0.19'];
        if ((float) ($invoice['discount_amount'] ?? 0) > 0) {
            $add('Discount (' . number_format((float) ($invoice['discount_rate'] ?? 0), 2) . '%)', 390, 9, '0.36 0.32 0.42');
            $lines[] = ['-' . $currency . ' ' . number_format((float) $invoice['discount_amount'], 2), 500, $y + 17, 10, '0.14 0.10 0.19'];
        }
        $add('VAT (' . number_format((float) $invoice['vat_rate'], 2) . '%)', 390, 9, '0.36 0.32 0.42');
        $lines[] = [$currency . ' ' . number_format((float) $invoice['vat_amount'], 2), 500, $y + 17, 10, '0.14 0.10 0.19'];
        $y -= 5;
        $lines[] = ['TOTAL DUE', 390, $y, 12, '0.38 0.20 0.55'];
        $lines[] = [$currency . ' ' . number_format((float) $invoice['total'], 2), 500, $y, 12, '0.38 0.20 0.55'];
        $y -= 42;
        $add('Payment is due after delivery according to the agreed payment terms.', 52, 9, '0.36 0.32 0.42');
        $add('Pay invoice: https://vibesagency.co/pay-invoice', 52, 9, '0.38 0.20 0.55');
        $add('VIBES AGENCY  |  Beirut, Lebanon  |  +96170606046  |  vibesagency.co', 52, 9, '0.38 0.20 0.55');

        $stream = "q\n0.38 0.20 0.55 rg\n0 720 612 72 re f\n0.92 0.88 0.96 RG\n52 548 m 560 548 l S\n0.38 0.20 0.55 rg\n52 464 508 22 re f\n0.14 0.10 0.19 rg\n";
        foreach ($lines as $line) {
            [$text, $x, $line_y, $size, $color] = $line;
            $stream .= $color . " rg\nBT /F1 " . $size . " Tf " . $x . ' ' . $line_y . " Td (" . self::pdf_text($text) . ") Tj ET\n";
        }
        $stream .= "Q\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
        ];
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[$index + 1] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) $pdf .= sprintf('%010d 00000 n \n', $offsets[$i]);
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        $tmp = tempnam(sys_get_temp_dir(), 'vtp-invoice-');
        if (!$tmp) return null;
        $path = $tmp . '.pdf';
        @rename($tmp, $path);
        if (file_put_contents($path, $pdf) === false) {
            @unlink($path);
            return null;
        }
        return $path;
    }

    private static function send_delivery_invoice(array $invoice, array $project): bool {
        $subject = 'Vibes Agency invoice ' . $invoice['invoice_number'];
        $total = esc_html($invoice['currency'] . ' ' . number_format((float) $invoice['total'], 2));
        $client = esc_html($invoice['client_name']);
        $payment_url = 'https://vibesagency.co/pay-invoice';
        $message = '<div style="font-family:Arial,sans-serif;color:#231a30;line-height:1.6;max-width:620px;margin:auto"><h1 style="color:#60338d">Your Vibes invoice is ready</h1><p>Hello ' . $client . ',</p><p>Your project <strong>' . esc_html($project['name']) . '</strong> has been approved and completed.</p><p>Invoice <strong>' . esc_html($invoice['invoice_number']) . '</strong> total: <strong>' . $total . '</strong>.</p><p>The branded invoice PDF is attached for your records. Payment is due after delivery according to the agreed payment terms.</p><p style="margin:26px 0"><a href="' . esc_url($payment_url) . '" style="background:#60338d;color:#fff;text-decoration:none;padding:13px 22px;border-radius:9px;font-weight:bold">View payment options</a></p><p>Payment options include bank transfer, OMT and Wish. Please include your invoice number with your payment.</p><p>Vibes Agency<br>Beirut, Lebanon<br>+96170606046<br><a href="https://vibesagency.co">vibesagency.co</a></p></div>';
        $pdf = self::invoice_pdf($invoice, $project);
        if (!$pdf) return false;
        $sent = self::send_mail((string) $invoice['email'], $subject, $message, true, [$pdf]);
        @unlink($pdf);
        return $sent;
    }

    private static function finalize_project_after_client_approval(int $project_id): ?int {
        global $wpdb;
        $project = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('projects') . ' WHERE id=%d', $project_id), ARRAY_A);
        if (!$project) return null;
        $total_tasks = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('tasks') . ' WHERE project_id=%d', $project_id));
        $finished_tasks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('tasks') . " WHERE project_id=%d AND status IN ('review','done')", $project_id));
        if (!$total_tasks || $finished_tasks < $total_tasks) return null;

        // Older projects may have been created manually with the quotation number
        // in their name but without quotation_id populated. Link those projects
        // back to the approved quotation before generating the invoice.
        $quotation_id = (int) ($project['quotation_id'] ?? 0);
        $quote = $quotation_id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('quotations') . ' WHERE id=%d AND status=%s', $quotation_id, 'approved'), ARRAY_A) : null;
        if (!$quote) {
            $quote_number = '';
            if (preg_match('/VQ-\d{8}-\d+/', (string) ($project['name'] ?? ''), $match)) $quote_number = $match[0];
            if ($quote_number) {
                $quote = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('quotations') . ' WHERE quote_number=%s AND status=%s LIMIT 1', $quote_number, 'approved'), ARRAY_A);
                if ($quote) {
                    $quotation_id = (int) $quote['id'];
                    $wpdb->update(self::table('projects'), ['quotation_id' => $quotation_id, 'updated_at' => current_time('mysql')], ['id' => $project_id]);
                    $project['quotation_id'] = $quotation_id;
                }
            }
        }
        if (!$quote || !$quotation_id) return null;
        $invoice_id = (int) ($project['invoice_id'] ?? 0);
        if (!$invoice_id) {
            $invoice_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('invoices') . ' WHERE quotation_id=%d LIMIT 1', (int) $quote['id']));
        }
        $now = current_time('mysql');
        if (!$invoice_id) {
            $due = wp_date('Y-m-d', strtotime('+14 days', current_time('timestamp')));
            $wpdb->insert(self::table('invoices'), [
                'invoice_number' => self::reference('VI', 'invoices', 'invoice_number'), 'quotation_id' => (int) $quote['id'], 'project_id' => $project_id,
                'client_name' => $quote['client_name'], 'company' => $quote['company'], 'email' => $quote['email'], 'phone' => $quote['phone'], 'currency' => $quote['currency'],
                'subtotal' => $quote['subtotal'], 'discount_rate' => $quote['discount_rate'] ?? 0, 'discount_amount' => $quote['discount_amount'] ?? 0, 'vat_rate' => $quote['vat_rate'], 'vat_amount' => $quote['vat_amount'], 'total' => $quote['total'], 'items_json' => $quote['items_json'],
                'due_date' => $due, 'status' => 'draft', 'payment_link' => 'https://vibesagency.co/pay-invoice', 'generated_automatically' => 1, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $invoice_id = (int) $wpdb->insert_id;
        }
        if (!$invoice_id) return null;
        $invoice = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('invoices') . ' WHERE id=%d', $invoice_id), ARRAY_A);
        if ($invoice && $invoice['status'] === 'draft') {
            $sent = self::send_delivery_invoice($invoice, $project);
            $wpdb->update(self::table('invoices'), ['status' => $sent ? 'sent' : 'draft', 'sent_at' => $sent ? $now : null, 'updated_at' => $now], ['id' => $invoice_id]);
        }
        $wpdb->update(self::table('projects'), ['status' => 'finished', 'progress' => 100, 'invoice_id' => $invoice_id, 'archived_at' => $now, 'updated_at' => $now], ['id' => $project_id]);
        self::notify_admins('project_finished', 'Project completed and archived', $project['name'] . ' · invoice ' . ($invoice['invoice_number'] ?? ''), 'project', $project_id);
        return $invoice_id;
    }

    public static function quotation_project(WP_REST_Request $request) {
        global $wpdb;
        $quote = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('quotations') . ' WHERE id=%d', (int) $request['id']), ARRAY_A);
        if (!$quote) return new WP_Error('not_found', 'Quotation not found.', ['status' => 404]);
        if ($quote['status'] !== 'approved') return new WP_Error('not_approved', 'Approve the quotation before creating the project.', ['status' => 409]);
        $existing = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('projects') . ' WHERE quotation_id=%d LIMIT 1', (int) $quote['id']));
        if ($existing) return new WP_Error('project_exists', 'A project already exists for this quotation.', ['status' => 409, 'projectId' => $existing]);
        $items = json_decode($quote['items_json'] ?: '[]', true) ?: [];
        $assignments = $request->get_param('assignments');
        if (!is_array($assignments)) $assignments = [];
        $assignment_map = [];
        foreach ($assignments as $assignment) {
            $index = isset($assignment['itemIndex']) ? (int) $assignment['itemIndex'] : -1;
            $assignee = (int) ($assignment['assigneeId'] ?? 0);
            if (!isset($items[$index]) || !$assignee || !get_user_by('id', $assignee)) continue;
            $due = sanitize_text_field($assignment['dueDate'] ?? '');
            if ($due && !self::valid_date($due)) return new WP_Error('invalid_due_date', 'Choose a valid due date for every assigned service.', ['status' => 400]);
            $assignment_map[$index] = [
                'assignee' => $assignee,
                'due' => $due,
                'priority' => in_array(($assignment['priority'] ?? ''), ['low', 'medium', 'high'], true) ? $assignment['priority'] : 'medium',
            ];
        }
        if (count($assignment_map) !== count($items)) return new WP_Error('missing_assignments', 'Assign every quoted service to an employee.', ['status' => 400]);
        $brief = implode("\n", array_map(static fn($item) => '• ' . $item['description'], $items));
        $term = 'once';
        $max_months = 0;
        foreach ($items as $item) {
            $candidate = self::normalize_term($item['term'] ?? $item['billing'] ?? 'once');
            $candidate_months = self::term_months($candidate);
            if ($candidate_months > $max_months) {
                $max_months = $candidate_months;
                $term = $candidate;
            }
        }
        $structure = self::project_structure((string) ($request['name'] ?: (($quote['company'] ?: $quote['client_name']) . ' — ' . $quote['quote_number'])), $items, $term);
        $now = current_time('mysql');
        $project_due = sanitize_text_field($request['dueDate']);
        if ($project_due && !self::valid_date($project_due)) return new WP_Error('invalid_project_due_date', 'Choose a valid project delivery date.', ['status' => 400]);
        $wpdb->query('START TRANSACTION');
        $wpdb->insert(self::table('projects'), [
            'quotation_id' => (int) $quote['id'],
            'name' => sanitize_text_field($request['name']) ?: ($quote['company'] ?: $quote['client_name']) . ' — ' . $quote['quote_number'],
            'client' => $quote['company'] ?: $quote['client_name'], 'description' => $brief,
            'status' => 'active', 'progress' => 0, 'lead_user_id' => (int) $request['leadUserId'] ?: null,
            'due_date' => $project_due ?: null, 'delivery_term' => $term,
            'drive_internal_url' => esc_url_raw((string) $request['internalDriveUrl'], ['https']),
            'drive_client_url' => esc_url_raw((string) $request['clientDriveUrl'], ['https']),
            'drive_structure_json' => wp_json_encode($structure), 'client_review_status' => 'not_ready',
            'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $project_id = (int) $wpdb->insert_id;
        if (!$project_id) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('project_failed', 'The project could not be created.', ['status' => 500]);
        }
        $task_count = 0;
        foreach ($items as $index => $item) {
            $assignment = $assignment_map[$index];
            $label = strtoupper(substr(sanitize_key($item['label'] ?? ''), 0, 20));
            $quantity = max(0.01, (float) ($item['quantity'] ?? 1));
            $title = sanitize_text_field($item['description'] ?? ('Quotation service ' . ($index + 1)));
            $item_term = self::normalize_term($item['term'] ?? $item['billing'] ?? 'once');
            $item_months = self::term_months($item_term);
            $task_quantity = (($item['quantityScope'] ?? '') === 'contract' && $item_months > 1)
                ? max(0.01, round($quantity / $item_months, 2))
                : $quantity;
            $months = $item_months > 0 ? self::project_months($item_term) : [['key' => wp_date('Y-m', current_time('timestamp')), 'label' => wp_date('F Y', current_time('timestamp'))]];
            foreach ($months as $month) {
                $task_number = self::reference('VT', 'tasks', 'task_number');
                $month_title = $item_months > 0 ? $title . ' — ' . $month['label'] : $title;
                $wpdb->insert(self::table('tasks'), [
                    'project_id' => $project_id,
                    'quotation_id' => (int) $quote['id'],
                    'quotation_item_index' => $index,
                    'service_label' => $label,
                    'service_quantity' => $task_quantity,
                    'service_term' => $item_term,
                    'task_number' => $task_number,
                    'client_name' => $quote['company'] ?: $quote['client_name'],
                    'title' => $month_title,
                    'description' => 'Quoted service: ' . ($label ? $label . ' · ' : '') . $title . "\nQuantity: " . $task_quantity . "\nTerm: " . self::term_label($item_term) . "\nDelivery month: " . $month['label'],
                    'files_json' => '[]',
                    'assignee_id' => $assignment['assignee'],
                    'created_by' => get_current_user_id(),
                    'priority' => $assignment['priority'],
                    'status' => 'todo',
                    'due_date' => $item_months > 0 ? gmdate('Y-m-t', strtotime($month['key'] . '-01')) : ($assignment['due'] ?: ($project_due ?: null)),
                    'delivery_month' => $month['key'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $task_id = (int) $wpdb->insert_id;
                if (!$task_id) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('task_failed', 'A quoted service task could not be created.', ['status' => 500]);
                }
                $task_count++;
                $wpdb->insert(self::table('notifications'), [
                    'user_id' => $assignment['assignee'], 'type' => 'task_assigned',
                    'title' => 'A quoted service was assigned to you',
                    'body' => $task_number . ' · ' . $month_title,
                    'object_type' => 'task', 'object_id' => $task_id, 'created_at' => $now,
                ]);
            }
        }
        $wpdb->update(self::table('quotations'), ['status' => 'approved', 'updated_at' => $now], ['id' => (int) $request['id']]);
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['projectId' => $project_id, 'taskCount' => $task_count, 'term' => $term, 'driveStructure' => $structure], 201);
    }

    public static function invoices(): WP_REST_Response {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table('invoices') . ' ORDER BY created_at DESC', ARRAY_A);
        foreach ($rows as &$row) $row['items'] = json_decode($row['items_json'] ?: '[]', true) ?: [];
        return rest_ensure_response($rows);
    }

    public static function update_project_drive(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $project = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . self::table('projects') . ' WHERE id=%d', $id));
        if (!$project) return new WP_Error('not_found', 'Project not found.', ['status' => 404]);
        $wpdb->update(self::table('projects'), [
            'drive_internal_url' => esc_url_raw((string) $request['internalDriveUrl'], ['https']),
            'drive_client_url' => esc_url_raw((string) $request['clientDriveUrl'], ['https']),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
        return rest_ensure_response(['success' => true]);
    }

    public static function publish_project_review(WP_REST_Request $request) {
        global $wpdb;
        $project_id = (int) $request['id'];
        $project = $wpdb->get_row($wpdb->prepare('SELECT p.*, q.quote_number, q.email, q.client_name, q.company FROM ' . self::table('projects') . ' p LEFT JOIN ' . self::table('quotations') . ' q ON q.id=p.quotation_id WHERE p.id=%d', $project_id), ARRAY_A);
        if (!$project) return new WP_Error('not_found', 'Project not found.', ['status' => 404]);

        // Older projects can have been created manually with a quotation number
        // in their name but without quotation_id populated. Recover the linked
        // quotation so the review email has the client recipient.
        if (!sanitize_email(trim((string) ($project['email'] ?? '')))) {
            $quote_number = trim((string) ($project['quote_number'] ?? ''));
            if (!$quote_number && preg_match('/VQ-\d{8}-\d+/', (string) ($project['name'] ?? ''), $match)) $quote_number = $match[0];
            if ($quote_number) {
                $fallback_quote = $wpdb->get_row($wpdb->prepare('SELECT quote_number, email, client_name, company FROM ' . self::table('quotations') . ' WHERE quote_number=%s LIMIT 1', $quote_number), ARRAY_A);
                if ($fallback_quote) $project = array_merge($project, $fallback_quote);
            }
        }
        if (!sanitize_email(trim((string) ($project['email'] ?? '')))) {
            return new WP_Error('missing_client_email', 'This project is not linked to a client email address. Link it to its approved quotation before sending the review.', ['status' => 400]);
        }
        $month = sanitize_text_field($request['month']);
        if ($month && !preg_match('/^\d{4}-\d{2}$/', $month)) return new WP_Error('invalid_month', 'Choose a valid delivery month.', ['status' => 400]);
        if (!$month) $month = (string) $wpdb->get_var($wpdb->prepare("SELECT MAX(delivery_month) FROM " . self::table('tasks') . " WHERE project_id=%d AND status IN ('review','done')", $project_id));
        $where = $month ? $wpdb->prepare('project_id=%d AND delivery_month=%s', $project_id, $month) : $wpdb->prepare('project_id=%d', $project_id);
        $tasks = $wpdb->get_results('SELECT * FROM ' . self::table('tasks') . " WHERE {$where} ORDER BY id ASC", ARRAY_A);
        if (!$tasks) return new WP_Error('no_tasks', 'There are no tasks in this delivery cycle yet.', ['status' => 400]);
        $unfinished = array_filter($tasks, static fn($task) => !in_array($task['status'], ['review', 'done'], true));
        if ($unfinished) return new WP_Error('unfinished_tasks', 'Every task in this delivery cycle must be submitted before sending it to the client.', ['status' => 409]);
        $token = preg_match('/^[a-f0-9]{64}$/', (string) ($project['client_review_token'] ?? '')) ? $project['client_review_token'] : bin2hex(random_bytes(32));
        $client_drive_url = esc_url_raw((string) $request['clientDriveUrl'], ['https']);
        $internal_drive_url = esc_url_raw((string) $request['internalDriveUrl'], ['https']);
        $now = current_time('mysql');
        $wpdb->update(self::table('projects'), [
            'client_review_token' => $token, 'client_review_status' => 'sent', 'client_review_month' => $month,
            'client_review_at' => $now, 'drive_client_url' => $client_drive_url ?: ($project['drive_client_url'] ?? ''),
            'drive_internal_url' => $internal_drive_url ?: ($project['drive_internal_url'] ?? ''), 'updated_at' => $now,
        ], ['id' => $project_id]);
        $review_url = add_query_arg('token', $token, home_url('/project-review/'));
        $message = '<div style="font-family:Arial,sans-serif;color:#231a30;line-height:1.6;max-width:620px;margin:auto"><h1 style="color:#60338d">Your Vibes project delivery is ready</h1><p>Hello ' . esc_html($project['client_name']) . ',</p><p>Project <strong>' . esc_html($project['name']) . '</strong>' . ($month ? ' for <strong>' . esc_html($month) . '</strong>' : '') . ' is ready for your review.</p><p style="margin:28px 0"><a href="' . esc_url($review_url) . '" style="background:#60338d;color:#fff;text-decoration:none;padding:13px 22px;border-radius:9px;font-weight:bold">Review project delivery</a></p><p>You can approve the delivery, request changes, leave comments, or ask for a meeting or direct call. No payment is requested until the delivery is approved.</p><p>Vibes Agency<br>Beirut, Lebanon<br>+96170606046<br><a href="https://vibesagency.co">vibesagency.co</a></p></div>';
        if (!self::send_mail((string) $project['email'], 'Vibes project delivery ready — ' . $project['name'], $message, true)) {
            return new WP_Error('mail_failed', 'The client review link was prepared but the email could not be sent.', ['status' => 500]);
        }
        return rest_ensure_response(['success' => true, 'reviewUrl' => $review_url, 'message' => 'Client review link sent to ' . $project['email'] . '.']);
    }

    public static function archive_project(WP_REST_Request $request) {
        global $wpdb;
        $project_id = (int) $request['id'];
        $project = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('projects') . ' WHERE id=%d', $project_id), ARRAY_A);
        if (!$project) return new WP_Error('not_found', 'Project not found.', ['status' => 404]);
        if (($project['client_review_status'] ?? '') !== 'approved') return new WP_Error('not_approved', 'The client must approve the delivery before archiving it.', ['status' => 409]);
        $invoice_id = self::finalize_project_after_client_approval($project_id);
        if (!$invoice_id) return new WP_Error('not_ready', 'The project still has unfinished tasks or no approved quotation.', ['status' => 409]);
        return rest_ensure_response(['archived' => true, 'invoiceId' => $invoice_id]);
    }

    public static function public_project_review(WP_REST_Request $request) {
        global $wpdb;
        $token = sanitize_key($request['token']);
        $project = $wpdb->get_row($wpdb->prepare('SELECT p.*, q.quote_number, q.client_name, q.company, q.email, q.phone FROM ' . self::table('projects') . ' p LEFT JOIN ' . self::table('quotations') . ' q ON q.id=p.quotation_id WHERE p.client_review_token=%s', $token), ARRAY_A);
        if (!$project) return new WP_Error('not_found', 'This project review link is invalid or unavailable.', ['status' => 404]);
        $month = (string) ($project['client_review_month'] ?? '');
        $where = $month ? $wpdb->prepare('project_id=%d AND delivery_month=%s', (int) $project['id'], $month) : $wpdb->prepare('project_id=%d', (int) $project['id']);
        $tasks = $wpdb->get_results('SELECT task_number, service_label, title, description, submission_note, status, files_json, delivery_month FROM ' . self::table('tasks') . " WHERE {$where} ORDER BY id ASC", ARRAY_A);
        foreach ($tasks as &$task) { $task['files'] = json_decode($task['files_json'] ?: '[]', true) ?: []; unset($task['files_json']); }
        $structure = json_decode($project['drive_structure_json'] ?? '', true) ?: [];
        $month_label = $month;
        foreach (($structure['months'] ?? []) as $entry) if (($entry['key'] ?? '') === $month) $month_label = $entry['label'];
        return rest_ensure_response(['project' => [
            'id' => (int) $project['id'], 'name' => $project['name'], 'client' => $project['client'], 'client_name' => $project['client_name'],
            'quote_number' => $project['quote_number'], 'progress' => (int) $project['progress'], 'client_review_status' => $project['client_review_status'],
            'client_review_month' => $month, 'client_review_month_label' => $month_label, 'client_drive_url' => $project['drive_client_url'],
        ], 'tasks' => $tasks]);
    }

    public static function public_project_response(WP_REST_Request $request) {
        global $wpdb;
        $token = sanitize_key($request['token']);
        $project = $wpdb->get_row($wpdb->prepare('SELECT p.*, q.quote_number, q.email FROM ' . self::table('projects') . ' p LEFT JOIN ' . self::table('quotations') . ' q ON q.id=p.quotation_id WHERE p.client_review_token=%s', $token), ARRAY_A);
        if (!$project) return new WP_Error('not_found', 'This project review link is invalid or unavailable.', ['status' => 404]);
        $action = sanitize_key($request['action']);
        if (!in_array($action, ['approve', 'changes', 'meeting', 'call', 'comment'], true)) return new WP_Error('invalid_action', 'Choose a valid project response.', ['status' => 400]);
        $name = sanitize_text_field($request['name']);
        $message = sanitize_textarea_field($request['message']);
        if (!$name) return new WP_Error('missing_name', 'Enter your full name.', ['status' => 400]);
        if (!$request['accepted']) return new WP_Error('approval_required', 'Confirm that you are authorized to respond to this project.', ['status' => 400]);
        if ($action !== 'approve' && !$message) return new WP_Error('missing_message', 'Add a comment or scheduling detail.', ['status' => 400]);
        $status = ['approve' => 'approved', 'changes' => 'changes_requested', 'meeting' => 'meeting_requested', 'call' => 'call_requested', 'comment' => 'feedback'][$action];
        $now = current_time('mysql');
        $wpdb->update(self::table('projects'), ['client_review_status' => $status, 'client_review_name' => $name, 'client_review_message' => $message, 'client_review_at' => $now, 'updated_at' => $now], ['id' => (int) $project['id']]);
        $titles = ['approve' => 'Project delivery approved', 'changes' => 'Project changes requested', 'meeting' => 'Client requested a meeting', 'call' => 'Client requested a direct call', 'comment' => 'Client left a project comment'];
        self::notify_admins('project_' . $action, $titles[$action] . ' · ' . $project['name'], $name . ($message ? ' · ' . $message : ''), 'project', (int) $project['id']);
        wp_mail(['info@vibesagency.co', 'info@solaraevents.net'], $titles[$action] . ' — ' . $project['name'], "Client: {$name}\nProject: {$project['name']}\nResponse: {$action}\n\n{$message}\n\nOpen the Vibes Team Portal to review it.", self::mail_headers());
        $invoice_id = $action === 'approve' ? self::finalize_project_after_client_approval((int) $project['id']) : null;
        $message_out = $action === 'approve' ? ($invoice_id ? 'Delivery approved. The invoice was emailed to you.' : 'Delivery approved. Vibes Agency has been notified.') : 'Your response was sent to Vibes Agency.';
        return rest_ensure_response(['success' => true, 'invoiceCreated' => (bool) $invoice_id, 'message' => $message_out]);
    }

    public static function create_invoice(WP_REST_Request $request) {
        global $wpdb;
        $name = sanitize_text_field($request['clientName']);
        $email = sanitize_email($request['email']);
        $total = max(0, (float) $request['total']);
        if (!$name || !$email || !$total) return new WP_Error('missing_fields', 'Client, email and total are required.', ['status' => 400]);
        $now = current_time('mysql');
        $due = sanitize_text_field($request['dueDate']);
        $wpdb->insert(self::table('invoices'), [
            'invoice_number' => self::reference('VI', 'invoices', 'invoice_number'), 'quotation_id' => (int) $request['quotationId'] ?: null,
            'project_id' => (int) $request['projectId'] ?: null, 'client_name' => $name, 'email' => $email,
            'currency' => in_array($request['currency'], ['SAR', 'USD'], true) ? $request['currency'] : 'SAR',
            'company' => sanitize_text_field($request['company']), 'phone' => sanitize_text_field($request['phone']),
            'subtotal' => round($total, 2), 'discount_rate' => 0, 'discount_amount' => 0, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => round($total, 2),
            'items_json' => '[]',
            'due_date' => $due && self::valid_date($due) ? $due : null, 'status' => 'draft',
            'payment_link' => esc_url_raw($request['paymentLink'], ['https']) ?: 'https://vibesagency.co/pay-invoice', 'generated_automatically' => 0,
            'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        return new WP_REST_Response(['id' => (int) $wpdb->insert_id], 201);
    }

    private static function sync_invoice_payment(array $invoice, string $paid_at): array|WP_Error {
        global $wpdb;
        $table = self::table('finance_payments');
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) return new WP_Error('finance_unavailable', 'Finance payment storage is unavailable. Enable Finance Overview before marking an invoice paid.', ['status' => 500]);
        $invoice_id = (int) ($invoice['id'] ?? 0);
        $total = round(max(0, (float) ($invoice['total'] ?? 0)), 2);
        $currency = in_array((string) ($invoice['currency'] ?? 'SAR'), ['USD', 'SAR', 'LBP'], true) ? (string) $invoice['currency'] : 'SAR';
        $reference = 'Invoice ' . sanitize_text_field((string) ($invoice['invoice_number'] ?? $invoice_id));
        $existing = $wpdb->get_row($wpdb->prepare('SELECT id,amount FROM ' . $table . ' WHERE invoice_id=%d AND reference=%s ORDER BY id ASC LIMIT 1', $invoice_id, $reference), ARRAY_A);
        $other_paid = (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount),0) FROM ' . $table . ' WHERE invoice_id=%d AND reference<>%s', $invoice_id, $reference));
        $required = max(0, round($total - $other_paid, 2));
        $payment_id = 0;
        $created = false;
        if ($existing) {
            $payment_id = (int) $existing['id'];
            if ($required > 0) {
                $wpdb->update($table, ['amount' => $required, 'currency' => $currency, 'paid_at' => $paid_at, 'note' => 'Automatically synced from invoice status.'], ['id' => $payment_id]);
            }
        } elseif ($required > 0) {
            $wpdb->insert($table, [
                'invoice_id' => $invoice_id, 'amount' => $required, 'currency' => $currency, 'paid_at' => $paid_at,
                'reference' => $reference, 'note' => 'Automatically synced from invoice status.',
                'created_by' => get_current_user_id(), 'created_at' => current_time('mysql'),
            ]);
            $payment_id = (int) $wpdb->insert_id;
            $created = $payment_id > 0;
        } else {
            $payment_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $table . ' WHERE invoice_id=%d ORDER BY id DESC LIMIT 1', $invoice_id));
        }
        if (!$payment_id && $total > 0) return new WP_Error('payment_sync_failed', 'The invoice could not be synced to Finance Overview.', ['status' => 500]);
        if (class_exists('VPM_Permissions') && method_exists('VPM_Permissions', 'audit')) {
            VPM_Permissions::audit('finance', 'invoice_marked_paid', 'invoice', $invoice_id, ['payment_id' => $payment_id, 'amount' => $required, 'currency' => $currency, 'paid_at' => $paid_at]);
        }
        return ['paymentId' => $payment_id, 'paymentCreated' => $created, 'amount' => $required, 'currency' => $currency, 'paidAt' => $paid_at];
    }

    public static function invoice_status(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $status = sanitize_key($request['status']);
        if (!in_array($status, ['draft', 'sent', 'paid', 'overdue', 'cancelled'], true)) return new WP_Error('invalid_status', 'Choose a valid invoice status.', ['status' => 400]);
        $invoice = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('invoices') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$invoice) return new WP_Error('not_found', 'Invoice not found.', ['status' => 404]);
        $payment = null;
        if ($status === 'paid') {
            $paid_at = sanitize_text_field((string) ($request['paidAt'] ?? ''));
            if (!$paid_at) $paid_at = substr((string) ($invoice['created_at'] ?? ''), 0, 10) ?: current_time('Y-m-d');
            if (!self::valid_date($paid_at)) return new WP_Error('invalid_payment_date', 'Choose a valid payment date.', ['status' => 400]);
            $payment = self::sync_invoice_payment($invoice, $paid_at);
            if (is_wp_error($payment)) return $payment;
        }
        $wpdb->update(self::table('invoices'), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id]);
        $response = ['success' => true, 'invoiceId' => $id, 'status' => $status];
        if (is_array($payment)) $response['payment'] = $payment;
        return rest_ensure_response($response);
    }

    public static function delete_invoice(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('invoices') . ' WHERE id=%d', $id))) {
            return new WP_Error('not_found', 'Invoice not found.', ['status' => 404]);
        }
        $wpdb->update(self::table('projects'), ['invoice_id' => null], ['invoice_id' => $id]);
        $wpdb->delete(self::table('notifications'), ['object_type' => 'invoice', 'object_id' => $id]);
        $wpdb->delete(self::table('invoices'), ['id' => $id]);
        return rest_ensure_response(['deleted' => true]);
    }

    public static function employees(): WP_REST_Response {
        $users = get_users(['role__in' => ['administrator', 'vibes_manager', 'vibes_employee'], 'orderby' => 'display_name']);
        return rest_ensure_response(array_map([self::class, 'user_payload'], $users));
    }

    public static function create_employee(WP_REST_Request $request) {
        $name = sanitize_text_field($request['name']);
        $email = sanitize_email($request['email']);
        $position = sanitize_text_field($request['position']);
        $department = sanitize_text_field($request['department']);
        $gender = sanitize_key($request['gender']);
        $phone = sanitize_text_field($request['phone']);
        $date_joining = sanitize_text_field($request['dateJoining']);
        $date_birth = sanitize_text_field($request['dateBirth']);
        $salary_amount = sanitize_text_field((string) $request['salaryAmount']);
        $salary_currency = strtoupper(sanitize_key((string) ($request['salaryCurrency'] ?: 'USD')));
        $employment_status = sanitize_key((string) ($request['employmentStatus'] ?: 'probation'));
        $role = $request['role'] === 'manager' ? 'vibes_manager' : 'vibes_employee';
        if (!$name || !$email || !$position || !$gender || !$phone || !$date_joining || !$date_birth) return new WP_Error('missing_fields', 'Complete every employee detail before creating the account.', ['status' => 400]);
        if (!in_array($gender, ['female', 'male', 'other', 'prefer_not_to_say'], true)) return new WP_Error('invalid_gender', 'Choose a valid gender option.', ['status' => 400]);
        if ($salary_amount !== '' && (!is_numeric($salary_amount) || (float) $salary_amount < 0)) return new WP_Error('invalid_salary', 'Enter a valid non-negative monthly salary.', ['status' => 400]);
        if (!in_array($salary_currency, ['USD'], true)) return new WP_Error('invalid_salary_currency', 'Salary currency must be USD.', ['status' => 400]);
        if (!in_array($employment_status, ['probation', 'confirmed', 'permanent', 'ended'], true)) $employment_status = 'probation';
        if ($role === 'vibes_manager') $salary_amount = '';
        if (!self::valid_date($date_joining) || !self::valid_date($date_birth)) return new WP_Error('invalid_dates', 'Choose valid joining and birth dates.', ['status' => 400]);
        if (!str_ends_with(strtolower($email), '@vibesagency.co')) return new WP_Error('invalid_domain', 'Use a @vibesagency.co work email.', ['status' => 400]);
        if (email_exists($email)) return new WP_Error('email_exists', 'An account already uses this email.', ['status' => 409]);
        $password = wp_generate_password(14, true, false);
        $base = sanitize_user(strstr($email, '@', true), true);
        $username = $base;
        $n = 2;
        while (username_exists($username)) $username = $base . $n++;
        $id = wp_insert_user(['user_login' => $username, 'user_email' => $email, 'display_name' => $name, 'user_pass' => $password, 'role' => $role]);
        if (is_wp_error($id)) return $id;
        update_user_meta($id, 'vtp_position', $position);
        update_user_meta($id, 'vtp_department', $department);
        update_user_meta($id, 'vtp_gender', $gender);
        update_user_meta($id, 'vtp_phone', $phone);
        update_user_meta($id, 'vtp_date_joining', $date_joining);
        update_user_meta($id, 'vtp_date_birth', $date_birth);
        update_user_meta($id, 'vtp_availability', 'available');
        update_user_meta($id, 'vtp_skills', sanitize_textarea_field((string) $request['skills']));
        update_user_meta($id, 'vtp_employee_audit', [['at' => current_time('mysql'), 'by' => get_current_user_id(), 'action' => 'created', 'summary' => 'Employee account created']]);
        update_user_meta($id, 'vtp_force_password_change', 1);
        update_user_meta($id, 'vtp_salary_amount', $role === 'vibes_manager' ? '' : $salary_amount);
        update_user_meta($id, 'vtp_salary_currency', $salary_currency);
        update_user_meta($id, 'vtp_employment_status', $role === 'vibes_manager' ? 'permanent' : $employment_status);
        $user = get_user_by('id', $id);
        return new WP_REST_Response(['employee' => self::user_payload($user), 'temporaryPassword' => $password], 201);
    }

    public static function update_employee(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $user = get_user_by('id', $id);
        if (!$user) return new WP_Error('not_found', 'Employee not found.', ['status' => 404]);

        $name = sanitize_text_field((string) $request['name']);
        $position = sanitize_text_field((string) $request['position']);
        $department = sanitize_text_field((string) $request['department']);
        $phone = sanitize_text_field((string) $request['phone']);
        $skills = sanitize_textarea_field((string) $request['skills']);
        $role = sanitize_key((string) ($request['role'] ?: 'employee'));
        $availability = sanitize_key((string) ($request['availability'] ?: 'available'));
        $salary_amount = sanitize_text_field((string) $request['salaryAmount']);
        $salary_currency = strtoupper(sanitize_key((string) ($request['salaryCurrency'] ?: 'USD')));
        $employment_status = sanitize_key((string) ($request['employmentStatus'] ?: 'probation'));
        $active = filter_var($request['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) $active = true;

        if (!$name || !$position || !$department || !$phone) return new WP_Error('missing_fields', 'Complete the employee profile before saving.', ['status' => 400]);
        if (!in_array($role, ['employee', 'manager'], true)) return new WP_Error('invalid_role', 'Choose a valid employee role.', ['status' => 400]);
        if (!in_array($availability, ['available', 'busy', 'leave', 'offline'], true)) return new WP_Error('invalid_availability', 'Choose a valid availability state.', ['status' => 400]);
        if ($salary_amount !== '' && (!is_numeric($salary_amount) || (float) $salary_amount < 0)) return new WP_Error('invalid_salary', 'Enter a valid non-negative monthly salary.', ['status' => 400]);
        if (!in_array($salary_currency, ['USD'], true)) return new WP_Error('invalid_salary_currency', 'Salary currency must be USD.', ['status' => 400]);
        if (!in_array($employment_status, ['probation', 'confirmed', 'permanent', 'ended'], true)) $employment_status = 'probation';
        $manager_profile = $role === 'manager' || in_array('administrator', (array) $user->roles, true);
        if ($manager_profile) $salary_amount = '';
        if ($id === get_current_user_id() && !$active) return new WP_Error('self_disable', 'You cannot disable your own account.', ['status' => 400]);

        $before = self::user_payload($user);
        wp_update_user(['ID' => $id, 'display_name' => $name]);
        foreach (['vtp_position' => $position, 'vtp_department' => $department, 'vtp_phone' => $phone, 'vtp_availability' => $availability, 'vtp_skills' => $skills] as $key => $value) update_user_meta($id, $key, $value);
        update_user_meta($id, 'vtp_salary_amount', $salary_amount);
        update_user_meta($id, 'vtp_salary_currency', $salary_currency);
        update_user_meta($id, 'vtp_employment_status', $manager_profile ? 'permanent' : $employment_status);
        if (!user_can($id, 'administrator')) {
            $user->set_role($role === 'manager' ? 'vibes_manager' : 'vibes_employee');
            update_user_meta($id, 'vtp_disabled', $active ? 0 : 1);
        }
        $audit = (array) get_user_meta($id, 'vtp_employee_audit', true);
        array_unshift($audit, ['at' => current_time('mysql'), 'by' => get_current_user_id(), 'action' => 'updated', 'summary' => ($before['active'] === $active ? 'Profile updated' : ($active ? 'Account restored' : 'Account deactivated'))]);
        update_user_meta($id, 'vtp_employee_audit', array_slice($audit, 0, 20));
        if ($id !== get_current_user_id()) {
            $wpdb->insert(self::table('notifications'), [
                'user_id' => $id, 'type' => 'employee_profile_updated', 'title' => 'Your team profile was updated',
                'body' => 'Your profile, role, availability or access settings were updated by an administrator.', 'object_type' => 'employee', 'object_id' => $id, 'created_at' => current_time('mysql'),
            ]);
        }
        clean_user_cache($id);
        return rest_ensure_response(self::user_payload(get_user_by('id', $id)));
    }

    public static function delete_employee(WP_REST_Request $request) {
        $id = (int) $request['id'];
        if ($id === get_current_user_id() || user_can($id, 'administrator')) return new WP_Error('protected_user', 'This account cannot be deleted here.', ['status' => 400]);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        if (!wp_delete_user($id, get_current_user_id())) return new WP_Error('delete_failed', 'Could not delete the account.', ['status' => 500]);
        return rest_ensure_response(['deleted' => true]);
    }

    public static function projects(): WP_REST_Response {
        global $wpdb;
        // Reconcile approvals recorded before the project finalization fix.
        // This also makes an approved, fully submitted project finish as soon
        // as the Projects panel is opened, without requiring another client action.
        $approved_projects = $wpdb->get_col("SELECT id FROM " . self::table('projects') . " WHERE client_review_status='approved' AND status <> 'finished'");
        foreach ($approved_projects as $approved_project_id) self::finalize_project_after_client_approval((int) $approved_project_id);
        $rows = $wpdb->get_results('SELECT p.*, u.display_name lead_name, q.quote_number, '
            . '(SELECT COUNT(*) FROM ' . self::table('tasks') . ' t WHERE t.project_id=p.id) task_count, '
            . '(SELECT COUNT(*) FROM ' . self::table('tasks') . " t WHERE t.project_id=p.id AND t.status IN ('review','done')) submitted_count "
            . 'FROM ' . self::table('projects') . ' p LEFT JOIN ' . $wpdb->users . ' u ON u.ID=p.lead_user_id '
            . 'LEFT JOIN ' . self::table('quotations') . ' q ON q.id=p.quotation_id ORDER BY p.updated_at DESC', ARRAY_A);
        foreach ($rows as &$row) {
            $structure = json_decode($row['drive_structure_json'] ?? '', true) ?: [];
            $row['driveStructure'] = $structure;
            $row['task_count'] = (int) ($row['task_count'] ?? 0);
            $row['submitted_count'] = (int) ($row['submitted_count'] ?? 0);
        }
        return rest_ensure_response($rows);
    }

    public static function create_project(WP_REST_Request $request) {
        global $wpdb;
        $name = sanitize_text_field($request['name']);
        if (!$name) return new WP_Error('missing_name', 'Project name is required.', ['status' => 400]);
        $now = current_time('mysql');
        $wpdb->insert(self::table('projects'), [
            'name' => $name, 'client' => sanitize_text_field($request['client']),
            'description' => sanitize_textarea_field($request['description']),
            'status' => 'active', 'progress' => 0, 'lead_user_id' => (int) $request['leadUserId'] ?: null,
            'due_date' => sanitize_text_field($request['dueDate']) ?: null, 'created_by' => get_current_user_id(),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return new WP_REST_Response(['id' => $wpdb->insert_id], 201);
    }

    public static function delete_project(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('projects') . ' WHERE id=%d', $id))) {
            return new WP_Error('not_found', 'Project not found.', ['status' => 404]);
        }
        $task_ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::table('tasks') . ' WHERE project_id=%d', $id));
        foreach ($task_ids as $task_id) {
            $wpdb->delete(self::table('notifications'), ['object_type' => 'task', 'object_id' => (int) $task_id]);
        }
        $wpdb->delete(self::table('tasks'), ['project_id' => $id]);
        // Keep any issued invoice available for accounting, but remove its project link.
        $wpdb->update(self::table('invoices'), ['project_id' => null], ['project_id' => $id]);
        $wpdb->delete(self::table('notifications'), ['object_type' => 'project', 'object_id' => $id]);
        $wpdb->delete(self::table('projects'), ['id' => $id]);
        return rest_ensure_response(['deleted' => true, 'deletedTasks' => count($task_ids)]);
    }

    public static function tasks(): WP_REST_Response {
        global $wpdb;
        $where = current_user_can('vtp_manage_tasks') ? '1=1' : $wpdb->prepare('t.assignee_id=%d', get_current_user_id());
        $sql = 'SELECT t.*, p.name project_name, u.display_name assignee_name, u.user_email assignee_email FROM ' . self::table('tasks') . ' t LEFT JOIN ' . self::table('projects') . ' p ON p.id=t.project_id LEFT JOIN ' . $wpdb->users . " u ON u.ID=t.assignee_id WHERE {$where} ORDER BY FIELD(t.status,'in_progress','todo','review','done'), t.due_date ASC";
        $rows = $wpdb->get_results($sql, ARRAY_A);
        foreach ($rows as &$row) {
            $row['files'] = !empty($row['files_json']) ? (json_decode($row['files_json'], true) ?: []) : [];
            unset($row['files_json']);
        }
        return rest_ensure_response($rows);
    }

    public static function create_task(WP_REST_Request $request) {
        global $wpdb;
        $title = sanitize_text_field($request['title']);
        $task_number = sanitize_text_field($request['taskNumber']);
        $client_name = sanitize_text_field($request['clientName']);
        $assignee = (int) $request['assigneeId'];
        if (!$title || !$task_number || !$client_name || !$assignee || !get_user_by('id', $assignee)) return new WP_Error('missing_fields', 'Task number, client name, title and assignee are required.', ['status' => 400]);
        $now = current_time('mysql');
        $files = self::task_drive_links($request);
        if (is_wp_error($files)) return $files;
        $wpdb->insert(self::table('tasks'), [
            'project_id' => (int) $request['projectId'] ?: null,
            'task_number' => $task_number,
            'client_name' => $client_name,
            'title' => $title,
            'description' => sanitize_textarea_field($request['description']), 'assignee_id' => $assignee,
            'files_json' => wp_json_encode($files),
            'created_by' => get_current_user_id(), 'priority' => in_array($request['priority'], ['low','medium','high'], true) ? $request['priority'] : 'medium',
            'status' => 'todo', 'due_date' => sanitize_text_field($request['dueDate']) ?: null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $task_id = (int) $wpdb->insert_id;
        $wpdb->insert(self::table('notifications'), [
            'user_id' => $assignee, 'type' => 'task_assigned', 'title' => 'A new task was assigned to you',
            'body' => $title, 'object_type' => 'task', 'object_id' => $task_id, 'created_at' => $now,
        ]);
        return new WP_REST_Response(['id' => $task_id], 201);
    }

    public static function delete_task(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $task = $wpdb->get_row($wpdb->prepare('SELECT project_id FROM ' . self::table('tasks') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$task) return new WP_Error('not_found', 'Task not found.', ['status' => 404]);
        $wpdb->delete(self::table('notifications'), ['object_type' => 'task', 'object_id' => $id]);
        $wpdb->delete(self::table('tasks'), ['id' => $id]);
        if (!empty($task['project_id'])) self::refresh_project_status((int) $task['project_id']);
        return rest_ensure_response(['deleted' => true]);
    }

    private static function task_drive_links(WP_REST_Request $request) {
        $links = $request->get_param('driveLinks');
        if (!$links) return [];
        if (!is_array($links)) return new WP_Error('invalid_drive_links', 'Google Drive links must be provided as a list.', ['status' => 400]);
        if (count($links) > 5) return new WP_Error('too_many_drive_links', 'Add up to five Google Drive links per task.', ['status' => 400]);
        $safe = [];
        foreach ($links as $link) {
            if (!is_array($link)) continue;
            $url = esc_url_raw((string) ($link['url'] ?? ''), ['https']);
            if (!$url) continue;
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            if (!in_array($host, ['drive.google.com', 'docs.google.com'], true)) {
                return new WP_Error('invalid_drive_link', 'Use a Google Drive, Docs, Sheets or Slides link.', ['status' => 400]);
            }
            $name = sanitize_text_field((string) ($link['name'] ?? 'Google Drive'));
            $safe[] = ['name' => $name ?: 'Google Drive', 'url' => $url, 'source' => 'google_drive'];
        }
        return $safe;
    }

    public static function submit_task(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $task = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('tasks') . ' WHERE id=%d', $id));
        if (!$task) return new WP_Error('not_found', 'Task not found.', ['status' => 404]);
        if (!current_user_can('vtp_manage_tasks') && (int) $task->assignee_id !== get_current_user_id()) {
            return new WP_Error('forbidden', 'You cannot submit this task.', ['status' => 403]);
        }
        $links = self::task_drive_links($request);
        if (is_wp_error($links)) return $links;
        $existing_links = json_decode($task->files_json ?: '[]', true) ?: [];
        $all_links = array_slice(array_merge($existing_links, $links), 0, 10);
        $note = sanitize_textarea_field($request['note']);
        if (!$note && !$links) return new WP_Error('missing_submission', 'Add a short delivery note or a Google Drive link.', ['status' => 400]);
        $now = current_time('mysql');
        $wpdb->update(self::table('tasks'), [
            'status' => 'review', 'files_json' => wp_json_encode($all_links),
            'submission_note' => $note, 'submitted_at' => $now, 'updated_at' => $now,
        ], ['id' => $id]);
        $user = wp_get_current_user();
        self::notify_admins('task_submitted', $user->display_name . ' submitted work for review', $task->task_number . ' · ' . $task->title, 'task', $id);
        $project_state = self::refresh_project_status((int) $task->project_id);
        return rest_ensure_response(['submitted' => true, 'invoiceCreated' => false, 'projectReady' => $project_state['ready']]);
    }

    private static function refresh_project_status(int $project_id): array {
        global $wpdb;
        if (!$project_id) return ['ready' => false];
        $project = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('projects') . ' WHERE id=%d', $project_id), ARRAY_A);
        if (!$project) return ['ready' => false];
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('tasks') . ' WHERE project_id=%d', $project_id));
        $submitted = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('tasks') . " WHERE project_id=%d AND status IN ('review','done')", $project_id));
        $progress = $total ? min(100, (int) round(($submitted / $total) * 100)) : 0;
        $ready = $total > 0 && $submitted === $total;
        $wpdb->update(self::table('projects'), [
            'progress' => $progress,
            'status' => $ready ? 'review' : 'active',
            'client_review_status' => $ready && ($project['client_review_status'] ?? '') === 'not_ready' ? 'ready' : ($project['client_review_status'] ?? 'not_ready'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $project_id]);
        if ($ready && ($project['client_review_status'] ?? '') !== 'ready') {
            self::notify_admins('project_ready', 'Project ready for client review', $project['name'], 'project', $project_id);
        }
        return ['ready' => $ready, 'progress' => $progress];
    }

    public static function task_status(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $task = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('tasks') . ' WHERE id=%d', $id));
        if (!$task) return new WP_Error('not_found', 'Task not found.', ['status' => 404]);
        if (!current_user_can('vtp_manage_tasks') && (int) $task->assignee_id !== get_current_user_id()) return new WP_Error('forbidden', 'You cannot update this task.', ['status' => 403]);
        $status = sanitize_key($request['status']);
        if (!in_array($status, ['todo','in_progress','review','done'], true)) return new WP_Error('invalid_status', 'Invalid task status.', ['status' => 400]);
        if (!current_user_can('vtp_manage_tasks') && $status !== 'in_progress') return new WP_Error('submit_required', 'Use Submit work to send the completed service for review.', ['status' => 403]);
        $wpdb->update(self::table('tasks'), ['status' => $status, 'completed_at' => $status === 'done' ? current_time('mysql') : null, 'updated_at' => current_time('mysql')], ['id' => $id]);
        if ($status === 'done' && $task->status !== 'done') {
            $user = wp_get_current_user();
            self::notify_admins('task_completed', $user->display_name . ' completed a task', $task->title, 'task', $id);
        }
        $project_state = self::refresh_project_status((int) $task->project_id);
        return rest_ensure_response(['updated' => true, 'invoiceCreated' => false, 'projectReady' => $project_state['ready']]);
    }

    /**
     * Count inclusive calendar days in the WordPress site timezone.
     * Dates are intentionally whole-day values; fractional balances are handled
     * separately and rounded to two decimal places.
     */
    private static function leave_days(string $start, string $end): int {
        $timezone = wp_timezone();
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $timezone);
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', $end, $timezone);
        if (!$a || !$b || $a->format('Y-m-d') !== $start || $b->format('Y-m-d') !== $end || $b < $a) return 0;
        return (int) $a->diff($b)->days + 1;
    }

    /** Return the first pending/approved request that overlaps a new date range. */
    private static function leave_overlap(int $user_id, string $start, string $end): ?object {
        global $wpdb;
        $table = self::table('leave_requests');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, leave_type, start_date, end_date, status FROM {$table} WHERE user_id=%d AND status IN ('pending','approved') AND start_date <= %s AND end_date >= %s ORDER BY start_date ASC LIMIT 1",
            $user_id, $end, $start
        ));
    }

    private static function leave_cycle(int $user_id): array {
        $today_ts = current_time('timestamp');
        $joining = (string) get_user_meta($user_id, 'vtp_date_joining', true);
        $join_ts = $joining && strtotime($joining) ? strtotime($joining) : $today_ts;
        $month_day = wp_date('m-d', $join_ts);
        $year = (int) wp_date('Y', $today_ts);
        $anniversary_ts = strtotime($year . '-' . $month_day);
        $cycle_start_ts = $anniversary_ts <= $today_ts ? $anniversary_ts : strtotime('-1 year', $anniversary_ts);
        if ($cycle_start_ts < $join_ts) $cycle_start_ts = $join_ts;
        $probation_end_ts = strtotime('+3 months', $join_ts);
        $accrual_start_ts = max($cycle_start_ts, $probation_end_ts);
        $months = ((int) wp_date('Y', $today_ts) - (int) wp_date('Y', $accrual_start_ts)) * 12 + ((int) wp_date('n', $today_ts) - (int) wp_date('n', $accrual_start_ts));
        if ((int) wp_date('j', $today_ts) < (int) wp_date('j', $accrual_start_ts)) $months--;
        $accrued = min(15, max(0, round($months * 1.25, 2)));
        return [
            'start' => wp_date('Y-m-d', $cycle_start_ts),
            'end' => wp_date('Y-m-d', strtotime('+1 year -1 day', $cycle_start_ts)),
            'accrualStart' => wp_date('Y-m-d', $accrual_start_ts),
            'accrued' => (float) $accrued,
        ];
    }

    private static function leave_used(int $user_id, string $leave_type, string $from, string $to, ?int $exclude_id = null): float {
        global $wpdb;
        $table = self::table('leave_requests');
        $types = in_array($leave_type, ['sick', 'sick_leave', 'sick-leave'], true) ? ['sick', 'sick_leave', 'sick-leave'] : ['vacation'];
        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $args = array_merge([$user_id], $types, [$to, $from]);
        $sql = "SELECT id, start_date, end_date FROM {$table} WHERE user_id=%d AND leave_type IN ({$placeholders}) AND status IN ('pending','approved') AND start_date <= %s AND end_date >= %s";
        if ($exclude_id) { $sql .= ' AND id <> %d'; $args[] = $exclude_id; }
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        $total = 0.0;
        foreach ($rows as $row) {
            $overlap_start = max(strtotime($from), strtotime((string) $row['start_date']));
            $overlap_end = min(strtotime($to), strtotime((string) $row['end_date']));
            if ($overlap_end >= $overlap_start) $total += (float) self::leave_days(wp_date('Y-m-d', $overlap_start), wp_date('Y-m-d', $overlap_end));
        }
        return $total;
    }

    public static function leave(): WP_REST_Response {
        global $wpdb;
        $where = current_user_can('vtp_manage_leave') ? '1=1' : $wpdb->prepare('l.user_id=%d', get_current_user_id());
        $sql = 'SELECT l.*, u.display_name user_name, u.user_email FROM ' . self::table('leave_requests') . ' l LEFT JOIN ' . $wpdb->users . " u ON u.ID=l.user_id WHERE {$where} ORDER BY l.created_at DESC";
        return rest_ensure_response($wpdb->get_results($sql, ARRAY_A));
    }

    public static function create_leave(WP_REST_Request $request) {
        global $wpdb;
        $start = sanitize_text_field($request['startDate']); $end = sanitize_text_field($request['endDate']);
        if (!$start || !$end || strtotime($end) < strtotime($start)) return new WP_Error('invalid_dates', 'Choose a valid date range.', ['status' => 400]);
        $user_id = get_current_user_id();
        $overlap = self::leave_overlap($user_id, $start, $end);
        if ($overlap) {
            $label = ucfirst(str_replace(['_', '-'], ' ', (string) $overlap->leave_type));
            return new WP_Error('overlapping_leave', sprintf('This request overlaps an existing %s leave request from %s to %s.', $label, $overlap->start_date, $overlap->end_date), [
                'status' => 409,
                'existingRequest' => [
                    'id' => (int) $overlap->id,
                    'leaveType' => (string) $overlap->leave_type,
                    'startDate' => (string) $overlap->start_date,
                    'endDate' => (string) $overlap->end_date,
                    'status' => (string) $overlap->status,
                ],
            ]);
        }
        $leave_type = sanitize_key($request['leaveType'] ?: 'vacation');
        $requested_days = self::leave_days($start, $end);
        if ($requested_days < 1) return new WP_Error('invalid_dates', 'Choose a valid date range.', ['status' => 400]);
        $user = wp_get_current_user();
        $manager_like = in_array('administrator', (array) $user->roles, true) || in_array('vibes_manager', (array) $user->roles, true);
        $cycle = self::leave_cycle($user_id);
        $is_sick = in_array($leave_type, ['sick', 'sick_leave', 'sick-leave'], true);
        if (!$manager_like && $leave_type === 'vacation') {
            $employment_status = (string) get_user_meta($user_id, 'vtp_employment_status', true);
            if ($employment_status === 'probation' || (!$employment_status && strtotime($cycle['accrualStart']) > current_time('timestamp'))) return new WP_Error('vacation_not_available', 'Annual leave becomes available after the three-month probation period.', ['status' => 400]);
            $used = self::leave_used($user_id, 'vacation', $cycle['start'], $cycle['end']);
            if ($used + $requested_days > (float) $cycle['accrued']) return new WP_Error('insufficient_leave_balance', 'This request exceeds the employee accrued annual leave balance.', ['status' => 400]);
        }
        if (!$manager_like && $is_sick) {
            $used_sick = self::leave_used($user_id, 'sick', $cycle['start'], $cycle['end']);
            if ($used_sick + $requested_days > 10) return new WP_Error('sick_leave_limit', 'Sick leave is limited to 10 days per employment year.', ['status' => 400]);
        }
        $wpdb->insert(self::table('leave_requests'), [
            'user_id' => $user_id, 'leave_type' => $is_sick ? 'sick' : $leave_type,
            'start_date' => $start, 'end_date' => $end, 'reason' => sanitize_textarea_field($request['reason']),
            'coverage_user_id' => (int) $request['coverageUserId'] ?: null, 'handover_notes' => sanitize_textarea_field($request['handoverNotes']),
            'status' => 'pending', 'created_at' => current_time('mysql'),
        ]);
        $id = (int) $wpdb->insert_id; $user = wp_get_current_user();
        self::notify_admins('leave_requested', $user->display_name . ' submitted a leave request', "{$start} to {$end}", 'leave', $id);
        return new WP_REST_Response(['id' => $id], 201);
    }

    public static function delete_leave(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $leave = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('leave_requests') . ' WHERE id=%d', $id));
        if (!$leave) return new WP_Error('leave_not_found', 'Leave request not found.', ['status' => 404]);
        $user_id = get_current_user_id();
        $admin = current_user_can('vtp_manage_leave');
        $owner = (int) $leave->user_id === (int) $user_id;
        $before_start = strtotime((string) $leave->start_date) >= current_time('timestamp');
        if (!$admin && (!$owner || $leave->status !== 'pending' || !$before_start)) return new WP_Error('leave_locked', 'Only your pending leave requests before their start date can be cancelled.', ['status' => 400]);
        if (!$admin && !$owner) return new WP_Error('forbidden', 'You cannot manage this leave request.', ['status' => 403]);
        $wpdb->delete(self::table('leave_requests'), ['id' => $id], ['%d']);
        if ($admin && !$owner) {
            $wpdb->insert(self::table('notifications'), [
                'user_id' => (int) $leave->user_id, 'type' => 'leave_restored',
                'title' => 'Leave request removed', 'body' => 'The administrator removed this leave request. Your available leave balance has been restored.',
                'object_type' => 'leave', 'object_id' => $id, 'created_at' => current_time('mysql'),
            ]);
        }
        return rest_ensure_response(['deleted' => true, 'restoredBalance' => true]);
    }

    public static function review_leave(WP_REST_Request $request) {
        global $wpdb;
        $status = sanitize_key($request['status']);
        if (!in_array($status, ['approved','declined'], true)) return new WP_Error('invalid_status', 'Choose approved or declined.', ['status' => 400]);
        $id = (int) $request['id'];
        $leave = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('leave_requests') . ' WHERE id=%d', $id));
        if (!$leave) return new WP_Error('not_found', 'Leave request not found.', ['status' => 404]);
        $wpdb->update(self::table('leave_requests'), ['status' => $status, 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time('mysql')], ['id' => $id]);
        $wpdb->insert(self::table('notifications'), ['user_id' => $leave->user_id, 'type' => 'leave_' . $status, 'title' => 'Your leave request was ' . $status, 'object_type' => 'leave', 'object_id' => $id, 'created_at' => current_time('mysql')]);
        return rest_ensure_response(['updated' => true]);
    }

    public static function holidays(): WP_REST_Response {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table('holidays') . ' ORDER BY holiday_date ASC', ARRAY_A);
        return rest_ensure_response($rows);
    }

    public static function create_holiday(WP_REST_Request $request) {
        global $wpdb;
        $name = sanitize_text_field($request['name']);
        $date = sanitize_text_field($request['date']);
        $end = sanitize_text_field($request['endDate']);
        if (!$name || !self::valid_date($date)) return new WP_Error('invalid_holiday', 'Holiday name and date are required.', ['status' => 400]);
        if ($end && (!self::valid_date($end) || strtotime($end) < strtotime($date))) return new WP_Error('invalid_holiday_dates', 'Choose a valid holiday date range.', ['status' => 400]);
        $wpdb->insert(self::table('holidays'), [
            'name' => $name,
            'holiday_date' => $date,
            'end_date' => $end ?: $date,
            'description' => sanitize_textarea_field($request['description']),
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);
        return new WP_REST_Response(['id' => (int) $wpdb->insert_id], 201);
    }

    public static function delete_holiday(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $wpdb->delete(self::table('holidays'), ['id' => (int) $request['id']], ['%d']);
        return rest_ensure_response(['deleted' => true]);
    }

    private static function announcement_publish_at($value) {
        $value = sanitize_text_field((string) $value);
        if ($value === '') return null;
        $date = DateTime::createFromFormat('Y-m-d\TH:i', $value, wp_timezone());
        if (!$date || DateTime::getLastErrors() && (DateTime::getLastErrors()['warning_count'] || DateTime::getLastErrors()['error_count'])) return new WP_Error('invalid_publish_at', 'Choose a valid publish date and time.', ['status' => 400]);
        return $date->format('Y-m-d H:i:s');
    }

    private static function announcement_audience($value): string {
        $audience = sanitize_key((string) $value);
        return in_array($audience, ['everyone', 'creative', 'managers'], true) ? $audience : 'everyone';
    }

    private static function notify_announcement_recipients(int $announcement_id, string $title, string $audience): void {
        global $wpdb;
        $roles = ['administrator', 'vibes_employee', 'vibes_manager'];
        foreach (get_users(['role__in' => $roles, 'fields' => 'ID']) as $user_id) {
            $user = get_user_by('id', (int) $user_id);
            if (!$user) continue;
            if ($audience === 'managers' && !in_array('vibes_manager', (array) $user->roles, true) && !in_array('administrator', (array) $user->roles, true)) continue;
            if ($audience === 'creative' && in_array('vibes_manager', (array) $user->roles, true)) continue;
            $wpdb->insert(self::table('notifications'), ['user_id' => (int) $user_id, 'type' => 'announcement_published', 'title' => $title, 'body' => 'A new team announcement is available.', 'object_type' => 'announcement', 'object_id' => $announcement_id, 'created_at' => current_time('mysql')]);
        }
    }

    public static function announcements(): WP_REST_Response {
        global $wpdb;
        $visibility = current_user_can('vtp_manage_announcements') ? '' : ' AND (a.publish_at IS NULL OR a.publish_at <= NOW())';
        $rows = $wpdb->get_results('SELECT a.*, u.display_name author_name FROM ' . self::table('announcements') . ' a LEFT JOIN ' . $wpdb->users . ' u ON u.ID=a.created_by WHERE a.archived_at IS NULL' . $visibility . ' ORDER BY a.is_pinned DESC, COALESCE(a.publish_at, a.created_at) DESC', ARRAY_A);
        return rest_ensure_response($rows);
    }

    public static function create_announcement(WP_REST_Request $request) {
        global $wpdb;
        $title = sanitize_text_field($request['title']); $content = wp_kses_post($request['content']);
        if (!$title || !$content) return new WP_Error('missing_fields', 'Title and message are required.', ['status' => 400]);
        $publish_at = self::announcement_publish_at($request['publishAt']);
        if (is_wp_error($publish_at)) return $publish_at;
        $audience = self::announcement_audience($request['audience']);
        $now = current_time('mysql');
        $wpdb->insert(self::table('announcements'), ['title' => $title, 'content' => $content, 'audience' => $audience, 'is_pinned' => $request['isPinned'] ? 1 : 0, 'publish_at' => $publish_at, 'archived_at' => null, 'created_by' => get_current_user_id(), 'created_at' => $now]);
        $id = (int) $wpdb->insert_id;
        if ($id && (!$publish_at || strtotime($publish_at) <= current_time('timestamp'))) self::notify_announcement_recipients($id, $title, $audience);
        return new WP_REST_Response(['id' => $id], 201);
    }

    public static function update_announcement(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('announcements') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$existing) return new WP_Error('not_found', 'Announcement not found.', ['status' => 404]);
        $title = sanitize_text_field((string) ($request['title'] ?: $existing['title']));
        $content = wp_kses_post((string) ($request['content'] ?: $existing['content']));
        if (!$title || !$content) return new WP_Error('missing_fields', 'Title and message are required.', ['status' => 400]);
        $publish_at = self::announcement_publish_at($request->get_param('publishAt'));
        if (is_wp_error($publish_at)) return $publish_at;
        if ($request->get_param('publishAt') === null) $publish_at = $existing['publish_at'];
        $audience = self::announcement_audience($request->get_param('audience') ?: $existing['audience']);
        $is_pinned = $request->get_param('isPinned') === null ? (int) $existing['is_pinned'] : ($request['isPinned'] ? 1 : 0);
        $wpdb->update(self::table('announcements'), ['title' => $title, 'content' => $content, 'audience' => $audience, 'is_pinned' => $is_pinned, 'publish_at' => $publish_at], ['id' => $id]);
        return rest_ensure_response(['updated' => true]);
    }

    public static function archive_announcement(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('announcements') . ' WHERE id=%d', $id))) return new WP_Error('not_found', 'Announcement not found.', ['status' => 404]);
        $wpdb->update(self::table('announcements'), ['archived_at' => current_time('mysql')], ['id' => $id]);
        return rest_ensure_response(['archived' => true]);
    }

    public static function delete_announcement(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('announcements') . ' WHERE id=%d', $id))) return new WP_Error('not_found', 'Announcement not found.', ['status' => 404]);
        $wpdb->delete(self::table('notifications'), ['object_type' => 'announcement', 'object_id' => $id]);
        $wpdb->delete(self::table('announcements'), ['id' => $id]);
        return rest_ensure_response(['deleted' => true]);
    }

    public static function notifications(): WP_REST_Response {
        global $wpdb;
        $uid = get_current_user_id();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table('notifications') . ' WHERE user_id=%d ORDER BY created_at DESC LIMIT 100', $uid), ARRAY_A);
        $wpdb->update(self::table('notifications'), ['is_read' => 1], ['user_id' => $uid]);
        return rest_ensure_response($rows);
    }

    public static function update_profile_details(WP_REST_Request $request) {
        $position = sanitize_text_field($request['position']);
        $phone = sanitize_text_field($request['phone']);
        $gender = sanitize_key($request['gender']);
        $joined = sanitize_text_field($request['dateJoining']);
        $birth = sanitize_text_field($request['dateBirth']);
        if (!$position || !$phone || !$gender || !$joined || !$birth) {
            return new WP_Error('missing_fields', 'Complete each profile detail before saving.', ['status' => 400]);
        }
        if (!in_array($gender, ['female', 'male', 'other', 'prefer_not_to_say'], true)) {
            return new WP_Error('invalid_gender', 'Choose a valid gender option.', ['status' => 400]);
        }
        if (!self::valid_date($joined) || !self::valid_date($birth)) {
            return new WP_Error('invalid_dates', 'Choose valid joining and birth dates.', ['status' => 400]);
        }
        // Only the signed-in administrator's employment details are editable.
        // Ignore account IDs, roles and permissions supplied in the request.
        $id = get_current_user_id();
        foreach ([
            'vtp_position' => $position, 'vtp_phone' => $phone,
            'vtp_gender' => $gender, 'vtp_date_joining' => $joined,
            'vtp_date_birth' => $birth,
        ] as $key => $value) update_user_meta($id, $key, $value);
        clean_user_cache($id);
        return rest_ensure_response(self::user_payload(get_user_by('id', $id)));
    }

    public static function change_password(WP_REST_Request $request) {
        $password = (string) $request['password'];
        if (strlen($password) < 10) return new WP_Error('weak_password', 'Use at least 10 characters.', ['status' => 400]);
        $id = get_current_user_id(); wp_set_password($password, $id); delete_user_meta($id, 'vtp_force_password_change');
        return rest_ensure_response(['changed' => true]);
    }

    public static function change_avatar() {
        if (empty($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
            return new WP_Error('missing_avatar', 'Choose an image to upload.', ['status' => 400]);
        }
        $file = $_FILES['avatar'];
        if (!empty($file['size']) && (int) $file['size'] > 5 * MB_IN_BYTES) {
            return new WP_Error('avatar_too_large', 'Use an image smaller than 5 MB.', ['status' => 400]);
        }
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        if (empty($checked['type']) || !in_array($checked['type'], $allowed, true)) {
            return new WP_Error('invalid_avatar', 'Use a JPG, PNG or WebP image.', ['status' => 400]);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload('avatar', 0, [], ['test_form' => false]);
        if (is_wp_error($attachment_id)) return $attachment_id;
        $user_id = get_current_user_id();
        update_user_meta($user_id, 'vtp_avatar_id', $attachment_id);
        return rest_ensure_response(['avatar' => self::avatar_url($user_id, 192)]);
    }

    private static function files_brand_defaults(): array {
        return [
            'vibes' => [
                ['key' => 'profile', 'title' => 'Vibes Agency Profile', 'kind' => 'pdf', 'meta' => 'PDF · Managed in portal', 'url' => 'https://team.vibesagency.co/wp-content/uploads/2026/10/vibes-profile.pdf', 'adminOnly' => false],
                ['key' => 'guidelines', 'title' => 'Vibes Brand Guideline', 'kind' => 'pdf', 'meta' => 'PDF · Managed in portal', 'url' => '', 'adminOnly' => false],
                ['key' => 'presentation', 'title' => 'Vibes Presentation Template', 'kind' => 'pdf', 'meta' => 'PDF · Managed in portal', 'url' => '', 'adminOnly' => false],
                ['key' => 'social', 'title' => 'Social Media Toolkit', 'kind' => 'pdf', 'meta' => 'PDF · Managed in portal', 'url' => '', 'adminOnly' => false],
                ['key' => 'assets', 'title' => 'Vibes Brand Assets', 'kind' => 'drive', 'meta' => 'Google Drive · Shared folder', 'url' => '', 'adminOnly' => true],
                ['key' => 'access', 'title' => 'Vibes Account Access', 'kind' => 'doc', 'meta' => 'Google Doc · Admin only', 'url' => '', 'adminOnly' => true],
            ],
            'clients' => [],
        ];
    }

    private static function quotation_template_data(): array {
        $defaults = [
            'one_time' => ['label' => 'One-time / monthly quotation', 'url' => 'https://drive.google.com/file/d/1Cw9JIBzZR_dFmfb7eKPoeQZnoGIK73bG/view?usp=drivesdk'],
            'six_months' => ['label' => '6-month long-term quotation', 'url' => 'https://drive.google.com/file/d/1f1xAgmCwRqfKwSIRWYQc2jTwB-PYzS0N/view?usp=drivesdk'],
            'one_year' => ['label' => '1-year long-term quotation', 'url' => 'https://drive.google.com/file/d/1YICO7Erc03Ld2Zj8GVnCA6Av6vzjkoAp/view?usp=drivesdk'],
        ];
        $saved = get_option('vtp_quotation_templates', []);
        if (!is_array($saved)) $saved = [];
        $out = [];
        foreach ($defaults as $key => $default) {
            $item = array_merge($default, is_array($saved[$key] ?? null) ? $saved[$key] : []);
            $out[$key] = [
                'key' => $key,
                'label' => sanitize_text_field((string) $item['label']),
                'url' => esc_url_raw((string) $item['url']),
            ];
        }
        return $out;
    }

    public static function quotation_templates(): WP_REST_Response {
        return rest_ensure_response(['admin' => self::manage_files_brand(), 'templates' => array_values(self::quotation_template_data())]);
    }

    public static function save_quotation_templates(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!is_array($params)) $params = [];
        $key = sanitize_key((string) ($params['key'] ?? ''));
        if (!in_array($key, ['one_time', 'six_months', 'one_year'], true)) return new WP_Error('invalid_template_key', 'Choose a valid quotation template.', ['status' => 400]);
        $url = esc_url_raw((string) ($params['url'] ?? ''));
        if (!$url || !wp_http_validate_url($url)) return new WP_Error('invalid_template_url', 'Enter a valid Google Drive template link.', ['status' => 400]);
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['drive.google.com', 'docs.google.com'], true)) return new WP_Error('invalid_template_host', 'Quotation templates must be stored on Google Drive or Google Docs.', ['status' => 400]);
        $saved = get_option('vtp_quotation_templates', []);
        if (!is_array($saved)) $saved = [];
        $defaults = self::quotation_template_data();
        $saved[$key] = ['label' => $defaults[$key]['label'], 'url' => $url];
        update_option('vtp_quotation_templates', $saved, false);
        return self::quotation_templates();
    }

    private static function files_brand_data(): array {
        $defaults = self::files_brand_defaults();
        $saved = get_option('vtp_files_brand', []);
        if (!is_array($saved)) $saved = [];
        $vibes = [];
        $saved_vibes = is_array($saved['vibes'] ?? null) ? $saved['vibes'] : [];
        foreach ($defaults['vibes'] as $default) {
            $match = null;
            foreach ($saved_vibes as $item) {
                if (is_array($item) && ($item['key'] ?? '') === $default['key']) { $match = $item; break; }
            }
            $item = array_merge($default, is_array($match) ? $match : []);
            $item['key'] = sanitize_key((string) $item['key']);
            $item['title'] = sanitize_text_field((string) $item['title']);
            $item['kind'] = in_array($item['kind'], ['pdf', 'drive', 'doc'], true) ? $item['kind'] : 'pdf';
            $item['meta'] = sanitize_text_field((string) $item['meta']);
            $item['url'] = esc_url_raw((string) ($item['url'] ?? ''));
            $item['adminOnly'] = !empty($item['adminOnly']);
            $vibes[] = $item;
        }
        $clients = [];
        $saved_clients = is_array($saved['clients'] ?? null) ? $saved['clients'] : [];
        foreach ($saved_clients as $client) {
            if (!is_array($client)) continue;
            $name = sanitize_text_field((string) ($client['name'] ?? ''));
            if (!$name) continue;
            $saved_social = is_array($client['social'] ?? null) ? $client['social'] : [];
            $social = [];
            foreach (['facebook', 'instagram', 'youtube', 'tiktok', 'linkedin', 'snapchat'] as $network) {
                $social[$network] = esc_url_raw((string) (($saved_social[$network] ?? $client[$network] ?? '') ?: ''));
            }
            $clients[] = [
                'id' => sanitize_key((string) ($client['id'] ?? wp_generate_uuid4())),
                'name' => $name,
                'logo' => esc_url_raw((string) ($client['logo'] ?? '')),
                'location' => sanitize_text_field((string) ($client['location'] ?? '')),
                'website' => esc_url_raw((string) ($client['website'] ?? '')),
                'accounts_sheet' => esc_url_raw((string) ($client['accounts_sheet'] ?? '')),
                'social' => $social,
                'url' => esc_url_raw((string) ($client['url'] ?? '')),
                'meta' => sanitize_text_field((string) ($client['meta'] ?? 'Google Drive · Client folder')),
            ];
        }
        return ['vibes' => $vibes, 'clients' => $clients];
    }

    private static function files_brand_response(): array {
        $data = self::files_brand_data();
        if (!self::manage_files_brand()) {
            foreach ($data['vibes'] as &$item) {
                if (!empty($item['adminOnly'])) $item['url'] = '';
            }
            unset($item);
        }
        return ['admin' => self::manage_files_brand(), 'vibes' => $data['vibes'], 'clients' => $data['clients'], 'quotationTemplates' => array_values(self::quotation_template_data())];
    }

    public static function files_brand(): WP_REST_Response {
        return rest_ensure_response(self::files_brand_response());
    }

    public static function save_files_brand(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!is_array($params)) $params = [];
        $data = self::files_brand_data();
        $type = sanitize_key((string) ($params['type'] ?? ''));
        if ($type === 'vibes') {
            $key = sanitize_key((string) ($params['key'] ?? ''));
            $allowed = ['assets', 'access'];
            if (!in_array($key, $allowed, true)) return new WP_Error('invalid_file_key', 'Choose a valid shared resource.', ['status' => 400]);
            $url = esc_url_raw((string) ($params['url'] ?? ''));
            if ($url && !wp_http_validate_url($url)) return new WP_Error('invalid_file_url', 'Enter a valid link.', ['status' => 400]);
            foreach ($data['vibes'] as &$item) {
                if ($item['key'] === $key) { $item['url'] = $url; break; }
            }
            unset($item);
        } elseif ($type === 'client') {
            $name = sanitize_text_field((string) ($params['name'] ?? ''));
            $url = esc_url_raw((string) ($params['url'] ?? ''));
            $logo = esc_url_raw((string) ($params['logo'] ?? ''));
            $location = sanitize_text_field((string) ($params['location'] ?? ''));
            $website = esc_url_raw((string) ($params['website'] ?? ''));
            $accounts_sheet = esc_url_raw((string) ($params['accounts_sheet'] ?? ''));
            if (!$name) return new WP_Error('missing_client_name', 'Add a client name.', ['status' => 400]);
            if ($url && !wp_http_validate_url($url)) return new WP_Error('invalid_client_url', 'Enter a valid Google Drive folder link.', ['status' => 400]);
            if ($logo && !wp_http_validate_url($logo)) return new WP_Error('invalid_client_logo', 'Enter a valid logo URL.', ['status' => 400]);
            if ($website && !wp_http_validate_url($website)) return new WP_Error('invalid_client_website', 'Enter a valid website URL.', ['status' => 400]);
            if ($accounts_sheet && !wp_http_validate_url($accounts_sheet)) return new WP_Error('invalid_client_sheet', 'Enter a valid Google Sheet link.', ['status' => 400]);
            $social = [];
            foreach (['facebook', 'instagram', 'youtube', 'tiktok', 'linkedin', 'snapchat'] as $network) {
                $social[$network] = esc_url_raw((string) ($params[$network] ?? ($params['social'][$network] ?? '')));
                if ($social[$network] && !wp_http_validate_url($social[$network])) $social[$network] = '';
            }
            $id = sanitize_key((string) ($params['id'] ?? ''));
            $client = ['id' => $id ?: wp_generate_uuid4(), 'name' => $name, 'logo' => $logo, 'location' => $location, 'website' => $website, 'accounts_sheet' => $accounts_sheet, 'social' => $social, 'url' => $url, 'meta' => 'Google Drive · Client folder'];
            $updated = false;
            foreach ($data['clients'] as &$item) {
                if ($id && $item['id'] === $id) { $item = $client; $updated = true; break; }
            }
            unset($item);
            if (!$updated) $data['clients'][] = $client;
        } else {
            return new WP_Error('invalid_file_type', 'Choose a Vibes resource or client folder.', ['status' => 400]);
        }
        update_option('vtp_files_brand', $data, false);
        return rest_ensure_response(self::files_brand_response());
    }

    public static function upload_files_brand(WP_REST_Request $request) {
        $key = sanitize_key((string) ($_POST['key'] ?? ''));
        if (strpos($key, 'client_logo_') === 0) {
            $client_id = sanitize_key(substr($key, 12));
            if (!$client_id) return new WP_Error('invalid_client_logo_key', 'Choose a valid client.', ['status' => 400]);
            if (empty($_FILES['file']) || !is_array($_FILES['file'])) return new WP_Error('missing_client_logo', 'Choose a client logo.', ['status' => 400]);
            $logo_file = $_FILES['file'];
            if (!empty($logo_file['size']) && (int) $logo_file['size'] > 5 * MB_IN_BYTES) return new WP_Error('logo_too_large', 'Use an image smaller than 5 MB.', ['status' => 400]);
            $logo_checked = wp_check_filetype_and_ext($logo_file['tmp_name'], $logo_file['name']);
            $logo_types = ['image/jpeg', 'image/png', 'image/webp'];
            if (empty($logo_checked['type']) || !in_array($logo_checked['type'], $logo_types, true)) return new WP_Error('invalid_client_logo', 'Use a JPG, PNG or WebP logo.', ['status' => 400]);
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachment_id = media_handle_upload('file', 0, [], ['test_form' => false]);
            if (is_wp_error($attachment_id)) return $attachment_id;
            $logo_url = wp_get_attachment_url($attachment_id);
            $data = self::files_brand_data();
            $found = false;
            foreach ($data['clients'] as &$client) {
                if (($client['id'] ?? '') === $client_id) { $client['logo'] = esc_url_raw((string) $logo_url); $found = true; break; }
            }
            unset($client);
            if (!$found) return new WP_Error('client_not_found', 'Client folder was not found.', ['status' => 404]);
            update_option('vtp_files_brand', $data, false);
            return rest_ensure_response(self::files_brand_response());
        }
        $allowed = ['profile', 'guidelines', 'presentation', 'social'];
        if (!in_array($key, $allowed, true)) return new WP_Error('invalid_pdf_key', 'Choose a valid PDF resource.', ['status' => 400]);
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) return new WP_Error('missing_pdf', 'Choose a PDF to upload.', ['status' => 400]);
        $file = $_FILES['file'];
        if (!empty($file['size']) && (int) $file['size'] > 25 * MB_IN_BYTES) return new WP_Error('pdf_too_large', 'Use a PDF smaller than 25 MB.', ['status' => 400]);
        $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        if (empty($checked['type']) || $checked['type'] !== 'application/pdf') return new WP_Error('invalid_pdf', 'Use a PDF file.', ['status' => 400]);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload('file', 0, [], ['test_form' => false]);
        if (is_wp_error($attachment_id)) return $attachment_id;
        $url = wp_get_attachment_url($attachment_id);
        $size = (int) get_attached_file($attachment_id) && file_exists(get_attached_file($attachment_id)) ? size_format(filesize(get_attached_file($attachment_id))) : 'PDF';
        $data = self::files_brand_data();
        foreach ($data['vibes'] as &$item) {
            if ($item['key'] === $key) { $item['url'] = esc_url_raw($url); $item['meta'] = 'PDF · ' . $size . ' · Managed in portal'; break; }
        }
        unset($item);
        update_option('vtp_files_brand', $data, false);
        return rest_ensure_response(self::files_brand_response());
    }

    public static function delete_files_brand_client(WP_REST_Request $request) {
        $id = sanitize_key((string) $request['id']);
        $data = self::files_brand_data();
        $before = count($data['clients']);
        $data['clients'] = array_values(array_filter($data['clients'], static fn($item) => ($item['id'] ?? '') !== $id));
        if (count($data['clients']) === $before) return new WP_Error('client_not_found', 'Client folder was not found.', ['status' => 404]);
        update_option('vtp_files_brand', $data, false);
        return rest_ensure_response(self::files_brand_response());
    }
}
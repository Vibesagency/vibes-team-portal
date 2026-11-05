<?php

if (!defined('ABSPATH')) {
    exit;
}

class VTP_Activator {
    public static function maybe_upgrade(): void {
        if (get_option('vtp_version') !== VTP_VERSION) {
            self::roles();
            self::tables();
            VTP_Catalog::install();
            self::pages();
            self::quotation_templates();
            update_option('vtp_version', VTP_VERSION);
            flush_rewrite_rules(false);
        }
    }

    public static function activate(): void {
        self::roles();
        self::tables();
        VTP_Catalog::install();
        self::pages();
        self::quotation_templates();
        update_option('vtp_version', VTP_VERSION);
        flush_rewrite_rules();
    }

    private static function roles(): void {
        $employee = [
            'read' => true,
            'vtp_use_portal' => true,
            'vtp_update_own_tasks' => true,
            'vtp_request_leave' => true,
        ];
        add_role('vibes_employee', 'Vibes Employee', $employee);
        add_role('vibes_manager', 'Vibes Manager', array_merge($employee, [
            'vtp_manage_tasks' => true,
            'vtp_manage_projects' => true,
            'vtp_manage_sales' => true,
            'vtp_review_creative' => true,
            'vtp_manage_leave' => true,
        ]));

        // add_role() does not update roles that already exist on an upgraded site.
        $employee_role = get_role('vibes_employee');
        if ($employee_role) {
            foreach ($employee as $cap => $enabled) {
                if ($enabled) $employee_role->add_cap($cap);
            }
        }
        $manager_role = get_role('vibes_manager');
        if ($manager_role) {
            foreach (array_merge($employee, [
                'vtp_manage_tasks' => true,
                'vtp_manage_projects' => true,
                'vtp_manage_sales' => true,
                'vtp_review_creative' => true,
                'vtp_manage_leave' => true,
            ]) as $cap => $enabled) {
                if ($enabled) $manager_role->add_cap($cap);
            }
        }

        $admin = get_role('administrator');
        if ($admin) {
            foreach ([
                'vtp_use_portal', 'vtp_manage_employees', 'vtp_manage_tasks',
                'vtp_manage_projects', 'vtp_manage_sales', 'vtp_manage_leave', 'vtp_review_creative',
                'vtp_manage_announcements',
            ] as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    private static function tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'vtp_';

        $sql = [
            "CREATE TABLE {$prefix}projects (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                quotation_id bigint unsigned DEFAULT NULL,
                name varchar(190) NOT NULL,
                client varchar(190) DEFAULT '',
                description longtext,
                status varchar(30) NOT NULL DEFAULT 'active',
                progress tinyint unsigned NOT NULL DEFAULT 0,
                lead_user_id bigint unsigned DEFAULT NULL,
                due_date date DEFAULT NULL,
                delivery_term varchar(20) NOT NULL DEFAULT 'once',
                drive_internal_url text,
                drive_client_url text,
                drive_structure_json longtext,
                client_review_token varchar(64) DEFAULT '',
                client_review_status varchar(30) NOT NULL DEFAULT 'not_ready',
                client_review_month varchar(7) DEFAULT '',
                client_review_at datetime DEFAULT NULL,
                client_review_name varchar(190) DEFAULT '',
                client_review_message longtext,
                invoice_id bigint unsigned DEFAULT NULL,
                archived_at datetime DEFAULT NULL,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id), KEY quotation_id (quotation_id), KEY status (status), KEY lead_user_id (lead_user_id), KEY client_review_token (client_review_token)
            ) {$charset};",
            "CREATE TABLE {$prefix}tasks (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                project_id bigint unsigned DEFAULT NULL,
                quotation_id bigint unsigned DEFAULT NULL,
                quotation_item_index int unsigned DEFAULT NULL,
                service_label varchar(20) DEFAULT '',
                service_quantity decimal(10,2) NOT NULL DEFAULT 1,
                service_term varchar(20) NOT NULL DEFAULT 'once',
                task_number varchar(80) DEFAULT '',
                client_name varchar(190) DEFAULT '',
                title varchar(190) NOT NULL,
                description longtext,
                files_json longtext,
                assignee_id bigint unsigned NOT NULL,
                created_by bigint unsigned NOT NULL,
                priority varchar(20) NOT NULL DEFAULT 'medium',
                status varchar(30) NOT NULL DEFAULT 'todo',
                due_date date DEFAULT NULL,
                delivery_month varchar(7) DEFAULT '',
                submitted_at datetime DEFAULT NULL,
                submission_note longtext,
                completed_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id), KEY assignee_id (assignee_id), KEY project_id (project_id), KEY quotation_id (quotation_id), KEY status (status)
            ) {$charset};",
            "CREATE TABLE {$prefix}holidays (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                name varchar(190) NOT NULL,
                holiday_date date NOT NULL,
                end_date date DEFAULT NULL,
                description text,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY holiday_date (holiday_date)
            ) {$charset};",
            "CREATE TABLE {$prefix}leave_requests (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                leave_type varchar(30) NOT NULL,
                start_date date NOT NULL,
                end_date date NOT NULL,
                reason text,
                coverage_user_id bigint unsigned DEFAULT NULL,
                handover_notes text,
                status varchar(30) NOT NULL DEFAULT 'pending',
                reviewed_by bigint unsigned DEFAULT NULL,
                reviewed_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY user_id (user_id), KEY status (status)
            ) {$charset};",
            "CREATE TABLE {$prefix}announcements (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                title varchar(190) NOT NULL,
                content longtext NOT NULL,
                audience varchar(40) NOT NULL DEFAULT 'everyone',
                is_pinned tinyint(1) NOT NULL DEFAULT 0,
                publish_at datetime DEFAULT NULL,
                archived_at datetime DEFAULT NULL,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY publish_at (publish_at)
            ) {$charset};",
            // Team Hub was retired from the UI and REST surface. Keep this
            // legacy table intact until an administrator confirms a backup and
            // an empty, non-shared table; dbDelta() must never drop it here.
            "CREATE TABLE {$prefix}hub_posts (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                channel varchar(80) NOT NULL DEFAULT 'general',
                content longtext NOT NULL,
                parent_id bigint unsigned DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY channel (channel), KEY parent_id (parent_id)
            ) {$charset};",
            "CREATE TABLE {$prefix}notifications (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                type varchar(50) NOT NULL,
                title varchar(190) NOT NULL,
                body text,
                object_type varchar(50) DEFAULT NULL,
                object_id bigint unsigned DEFAULT NULL,
                is_read tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY user_id (user_id), KEY is_read (is_read)
            ) {$charset};",
            "CREATE TABLE {$prefix}inquiries (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                request_number varchar(80) NOT NULL,
                source varchar(30) NOT NULL DEFAULT 'manual',
                client_name varchar(190) NOT NULL,
                company varchar(190) DEFAULT '',
                email varchar(190) NOT NULL,
                phone varchar(80) DEFAULT '',
                services_json longtext,
                brief longtext,
                budget varchar(100) DEFAULT '',
                deadline date DEFAULT NULL,
                status varchar(30) NOT NULL DEFAULT 'new',
                created_by bigint unsigned DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY request_number (request_number), KEY status (status), KEY email (email)
            ) {$charset};",
            "CREATE TABLE {$prefix}quotations (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                quote_number varchar(80) NOT NULL,
                inquiry_id bigint unsigned DEFAULT NULL,
                client_name varchar(190) NOT NULL,
                company varchar(190) DEFAULT '',
                email varchar(190) NOT NULL,
                phone varchar(80) DEFAULT '',
                currency varchar(3) NOT NULL DEFAULT 'SAR',
                subtotal decimal(14,2) NOT NULL DEFAULT 0,
                discount_rate decimal(5,2) NOT NULL DEFAULT 0,
                discount_amount decimal(14,2) NOT NULL DEFAULT 0,
                vat_rate decimal(5,2) NOT NULL DEFAULT 0,
                vat_amount decimal(14,2) NOT NULL DEFAULT 0,
                total decimal(14,2) NOT NULL DEFAULT 0,
                valid_until date DEFAULT NULL,
                status varchar(30) NOT NULL DEFAULT 'draft',
                terms longtext,
                notes longtext,
                items_json longtext,
                contract_type varchar(20) NOT NULL DEFAULT 'one_time',
                contract_months smallint unsigned NOT NULL DEFAULT 1,
                contract_start date DEFAULT NULL,
                contract_end date DEFAULT NULL,
                parent_quotation_id bigint unsigned DEFAULT NULL,
                period_index smallint unsigned NOT NULL DEFAULT 0,
                period_start date DEFAULT NULL,
                period_end date DEFAULT NULL,
                template_key varchar(30) NOT NULL DEFAULT 'one_time',
                approval_token varchar(64) DEFAULT '',
                sent_at datetime DEFAULT NULL,
                client_action_at datetime DEFAULT NULL,
                client_action_name varchar(190) DEFAULT '',
                client_message longtext,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY quote_number (quote_number), KEY inquiry_id (inquiry_id), KEY status (status), KEY approval_token (approval_token), KEY parent_quotation_id (parent_quotation_id), KEY period_index (period_index)
            ) {$charset};",
            "CREATE TABLE {$prefix}invoices (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                invoice_number varchar(80) NOT NULL,
                quotation_id bigint unsigned DEFAULT NULL,
                project_id bigint unsigned DEFAULT NULL,
                client_name varchar(190) NOT NULL,
                email varchar(190) NOT NULL,
                currency varchar(3) NOT NULL DEFAULT 'SAR',
                company varchar(190) DEFAULT '',
                phone varchar(80) DEFAULT '',
                subtotal decimal(14,2) NOT NULL DEFAULT 0,
                discount_rate decimal(5,2) NOT NULL DEFAULT 0,
                discount_amount decimal(14,2) NOT NULL DEFAULT 0,
                vat_rate decimal(5,2) NOT NULL DEFAULT 0,
                vat_amount decimal(14,2) NOT NULL DEFAULT 0,
                total decimal(14,2) NOT NULL DEFAULT 0,
                items_json longtext,
                due_date date DEFAULT NULL,
                status varchar(30) NOT NULL DEFAULT 'draft',
                payment_link text,
                generated_automatically tinyint(1) NOT NULL DEFAULT 0,
                sent_at datetime DEFAULT NULL,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY invoice_number (invoice_number), KEY quotation_id (quotation_id), KEY project_id (project_id), KEY status (status)
            ) {$charset};",
        ];

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    private static function pages(): void {
        $pages = [
            'team-login' => ['Vibes Team Login', '[vtp_login]'],
            'team-portal' => ['Vibes Team Portal', '[vtp_portal]'],
            'quotation-review' => ['Review Vibes Quotation', '[vtp_quote_review]'],
            'project-review' => ['Review Vibes Project', '[vtp_project_review]'],
        ];
        foreach ($pages as $slug => [$title, $content]) {
            if (!get_page_by_path($slug)) {
                wp_insert_post([
                    'post_title' => $title,
                    'post_name' => $slug,
                    'post_content' => $content,
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'meta_input' => ['_vtp_system_page' => 1],
                ]);
            }
        }
    }

    private static function quotation_templates(): void {
        $defaults = [
            'one_time' => [
                'label' => 'One-time / monthly quotation',
                'url' => 'https://drive.google.com/file/d/1Cw9JIBzZR_dFmfb7eKPoeQZnoGIK73bG/view?usp=drivesdk',
            ],
            'six_months' => [
                'label' => '6-month long-term quotation',
                'url' => 'https://drive.google.com/file/d/1f1xAgmCwRqfKwSIRWYQc2jTwB-PYzS0N/view?usp=drivesdk',
            ],
            'one_year' => [
                'label' => '1-year long-term quotation',
                'url' => 'https://drive.google.com/file/d/1YICO7Erc03Ld2Zj8GVnCA6Av6vzjkoAp/view?usp=drivesdk',
            ],
        ];
        $saved = get_option('vtp_quotation_templates', []);
        if (!is_array($saved)) $saved = [];
        $merged = [];
        foreach ($defaults as $key => $default) {
            $merged[$key] = array_merge($default, is_array($saved[$key] ?? null) ? $saved[$key] : []);
        }
        update_option('vtp_quotation_templates', $merged, false);
    }
}


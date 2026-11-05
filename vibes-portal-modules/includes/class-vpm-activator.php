<?php

defined('ABSPATH') || exit;

final class VPM_Activator {
    public static function maybe_upgrade(): void {
        if (get_option('vpm_version') !== VPM_VERSION) {
            self::roles();
            self::tables();
            self::seed();
            update_option('vpm_version', VPM_VERSION, false);
        }
        if (!wp_next_scheduled('vpm_daily_lead_scan')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'vpm_daily_lead_scan');
    }

    public static function activate(): void {
        self::roles();
        self::tables();
        self::seed();
        update_option('vpm_version', VPM_VERSION, false);
        if (!wp_next_scheduled('vpm_daily_lead_scan')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'vpm_daily_lead_scan');
        }
    }

    private static function roles(): void {
        $employee = get_role('vibes_employee');
        $manager = get_role('vibes_manager');
        $admin = get_role('administrator');
        if ($employee) {
            $employee->add_cap('vpm_use_connect');
            $employee->add_cap('vpm_view_own_analytics');
        }
        if ($manager) {
            $manager->add_cap('vpm_use_connect');
            $manager->add_cap('vpm_view_own_analytics');
        }
        if ($admin) {
            foreach ([
                'vpm_use_connect', 'vpm_view_own_analytics', 'vpm_view_team_analytics',
                'vpm_manage_finance', 'vpm_manage_crm', 'vpm_run_lead_hunter',
            ] as $cap) $admin->add_cap($cap);
        }
    }

    private static function tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'vtp_';
        $sql = [
            "CREATE TABLE {$p}connect_conversations (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                type varchar(20) NOT NULL DEFAULT 'group',
                title varchar(190) NOT NULL DEFAULT '',
                project_id bigint unsigned DEFAULT NULL,
                task_id bigint unsigned DEFAULT NULL,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id), KEY project_id (project_id), KEY task_id (task_id), KEY updated_at (updated_at)
            ) {$charset};",
            "CREATE TABLE {$p}connect_members (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                conversation_id bigint unsigned NOT NULL,
                user_id bigint unsigned NOT NULL,
                joined_at datetime NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY member (conversation_id,user_id), KEY user_id (user_id)
            ) {$charset};",
            "CREATE TABLE {$p}connect_messages (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                conversation_id bigint unsigned NOT NULL,
                user_id bigint unsigned NOT NULL,
                parent_id bigint unsigned DEFAULT NULL,
                body longtext NOT NULL,
                created_at datetime NOT NULL,
                edited_at datetime DEFAULT NULL,
                deleted_at datetime DEFAULT NULL,
                PRIMARY KEY (id), KEY conversation_id (conversation_id), KEY parent_id (parent_id), KEY created_at (created_at)
            ) {$charset};",
            "CREATE TABLE {$p}connect_attachments (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                message_id bigint unsigned NOT NULL,
                attachment_id bigint unsigned DEFAULT NULL,
                filename varchar(190) NOT NULL,
                mime_type varchar(120) NOT NULL DEFAULT '',
                file_url text NOT NULL,
                file_path varchar(1024) NOT NULL DEFAULT '',
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY message_id (message_id)
            ) {$charset};",
            "CREATE TABLE {$p}connect_reactions (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                message_id bigint unsigned NOT NULL,
                user_id bigint unsigned NOT NULL,
                reaction varchar(40) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY reaction (message_id,user_id,reaction)
            ) {$charset};",
            "CREATE TABLE {$p}connect_reads (
                conversation_id bigint unsigned NOT NULL,
                user_id bigint unsigned NOT NULL,
                last_read_message_id bigint unsigned DEFAULT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (conversation_id,user_id)
            ) {$charset};",
            "CREATE TABLE {$p}analytics_task_events (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                task_id bigint unsigned NOT NULL,
                project_id bigint unsigned DEFAULT NULL,
                user_id bigint unsigned DEFAULT NULL,
                event_type varchar(40) NOT NULL,
                from_status varchar(30) DEFAULT '',
                to_status varchar(30) DEFAULT '',
                metadata_json longtext,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY task_id (task_id), KEY project_id (project_id), KEY user_id (user_id), KEY event_type (event_type)
            ) {$charset};",
            "CREATE TABLE {$p}analytics_complexity (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                service_key varchar(100) NOT NULL,
                label varchar(190) NOT NULL,
                points decimal(8,2) NOT NULL DEFAULT 1,
                effective_from date NOT NULL,
                effective_to date DEFAULT NULL,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY service_period (service_key,effective_from), KEY effective_from (effective_from)
            ) {$charset};",
            "CREATE TABLE {$p}analytics_task_points (
                task_id bigint unsigned NOT NULL,
                points decimal(8,2) NOT NULL DEFAULT 1,
                source varchar(30) NOT NULL DEFAULT 'default',
                recorded_at datetime NOT NULL,
                PRIMARY KEY (task_id)
            ) {$charset};",
            "CREATE TABLE {$p}finance_payments (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                invoice_id bigint unsigned NOT NULL,
                amount decimal(14,2) NOT NULL DEFAULT 0,
                currency varchar(3) NOT NULL DEFAULT 'SAR',
                paid_at date NOT NULL,
                reference varchar(190) DEFAULT '',
                note text,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY invoice_id (invoice_id), KEY paid_at (paid_at)
            ) {$charset};",
            "CREATE TABLE {$p}finance_expenses (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                category varchar(40) NOT NULL DEFAULT 'general',
                vendor varchar(190) DEFAULT '',
                description text,
                amount decimal(14,2) NOT NULL DEFAULT 0,
                currency varchar(3) NOT NULL DEFAULT 'SAR',
                expense_date date NOT NULL,
                recurring_template_id bigint unsigned DEFAULT NULL,
                receipt_attachment_id bigint unsigned DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'recorded',
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY category (category), KEY expense_date (expense_date)
            ) {$charset};",
            "CREATE TABLE {$p}finance_recurring_expenses (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                category varchar(40) NOT NULL DEFAULT 'general',
                vendor varchar(190) DEFAULT '',
                description text,
                amount decimal(14,2) NOT NULL DEFAULT 0,
                currency varchar(3) NOT NULL DEFAULT 'SAR',
                cadence varchar(20) NOT NULL DEFAULT 'monthly',
                next_date date DEFAULT NULL,
                active tinyint(1) NOT NULL DEFAULT 1,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY next_date (next_date), KEY active (active)
            ) {$charset};",
            "CREATE TABLE {$p}finance_salary_history (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                amount decimal(14,2) NOT NULL DEFAULT 0,
                currency varchar(3) NOT NULL DEFAULT 'USD',
                effective_from date NOT NULL,
                effective_to date DEFAULT NULL,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY user_id (user_id), KEY effective_from (effective_from)
            ) {$charset};",
            "CREATE TABLE {$p}finance_salary_payments (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                salary_history_id bigint unsigned DEFAULT NULL,
                pay_period varchar(7) NOT NULL,
                amount decimal(14,2) NOT NULL DEFAULT 0,
                currency varchar(3) NOT NULL DEFAULT 'USD',
                payment_date date DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'planned',
                reference varchar(190) DEFAULT '',
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY user_period (user_id,pay_period), KEY status (status)
            ) {$charset};",
            "CREATE TABLE {$p}finance_fx_rates (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                base_currency varchar(3) NOT NULL,
                quote_currency varchar(3) NOT NULL,
                rate decimal(18,8) NOT NULL,
                effective_date date NOT NULL,
                source varchar(120) NOT NULL DEFAULT 'manual',
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY currency_day (base_currency,quote_currency,effective_date)
            ) {$charset};",
            "CREATE TABLE {$p}crm_stages (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                stage_key varchar(40) NOT NULL,
                label varchar(80) NOT NULL,
                sort_order smallint unsigned NOT NULL DEFAULT 0,
                active tinyint(1) NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY stage_key (stage_key), KEY sort_order (sort_order)
            ) {$charset};",
            "CREATE TABLE {$p}crm_leads (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                lead_type varchar(30) NOT NULL DEFAULT 'manual',
                company varchar(190) NOT NULL,
                industry varchar(120) DEFAULT '',
                country varchar(100) DEFAULT '',
                city varchar(100) DEFAULT '',
                website text,
                contact_name varchar(190) DEFAULT '',
                contact_role varchar(120) DEFAULT '',
                contact_email varchar(190) DEFAULT '',
                contact_phone varchar(80) DEFAULT '',
                services_json longtext,
                opportunity_description longtext,
                lead_source varchar(120) DEFAULT 'manual',
                evidence_url text,
                publication_date date DEFAULT NULL,
                deadline date DEFAULT NULL,
                estimated_budget varchar(100) DEFAULT '',
                lead_score decimal(5,2) NOT NULL DEFAULT 0,
                score_reasons_json longtext,
                assigned_user_id bigint unsigned DEFAULT NULL,
                stage_key varchar(40) NOT NULL DEFAULT 'new_lead',
                review_status varchar(20) NOT NULL DEFAULT 'pending',
                notes longtext,
                next_follow_up_at datetime DEFAULT NULL,
                normalized_key varchar(190) NOT NULL,
                converted_client_key varchar(190) DEFAULT '',
                created_by bigint unsigned DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY normalized_key (normalized_key), KEY stage_key (stage_key), KEY review_status (review_status), KEY assigned_user_id (assigned_user_id), KEY deadline (deadline)
            ) {$charset};",
            "CREATE TABLE {$p}crm_lead_activities (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                lead_id bigint unsigned NOT NULL,
                activity_type varchar(30) NOT NULL DEFAULT 'note',
                body longtext NOT NULL,
                follow_up_at datetime DEFAULT NULL,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY lead_id (lead_id), KEY follow_up_at (follow_up_at)
            ) {$charset};",
            "CREATE TABLE {$p}crm_sources (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                name varchar(190) NOT NULL,
                source_type varchar(20) NOT NULL DEFAULT 'rss',
                endpoint_url text NOT NULL,
                config_json longtext,
                active tinyint(1) NOT NULL DEFAULT 1,
                last_success_at datetime DEFAULT NULL,
                last_error text,
                created_by bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id), KEY active (active)
            ) {$charset};",
            "CREATE TABLE {$p}crm_scans (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                source_id bigint unsigned DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'queued',
                started_at datetime DEFAULT NULL,
                finished_at datetime DEFAULT NULL,
                found_count int unsigned NOT NULL DEFAULT 0,
                accepted_count int unsigned NOT NULL DEFAULT 0,
                error text,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY source_id (source_id), KEY status (status), KEY started_at (started_at)
            ) {$charset};",
            "CREATE TABLE {$p}crm_evidence (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                lead_id bigint unsigned NOT NULL,
                evidence_url text NOT NULL,
                excerpt longtext,
                published_at date DEFAULT NULL,
                verified_at datetime DEFAULT NULL,
                verification_status varchar(20) NOT NULL DEFAULT 'unverified',
                source_hash varchar(64) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY source_hash (source_hash), KEY lead_id (lead_id), KEY verification_status (verification_status)
            ) {$charset};",
            "CREATE TABLE {$p}audit_events (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned DEFAULT NULL,
                area varchar(40) NOT NULL,
                action varchar(60) NOT NULL,
                object_type varchar(60) DEFAULT '',
                object_id bigint unsigned DEFAULT NULL,
                details_json longtext,
                created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY area (area), KEY object_type (object_type,object_id), KEY created_at (created_at)
            ) {$charset};",
        ];
        foreach ($sql as $statement) dbDelta($statement);
    }

    private static function seed(): void {
        global $wpdb;
        $now = current_time('mysql');
        $stages = [
            ['new_lead', 'New Lead'], ['qualified', 'Qualified'], ['contacted', 'Contacted'],
            ['interested', 'Interested'], ['meeting_scheduled', 'Meeting Scheduled'], ['proposal_sent', 'Proposal Sent'],
            ['negotiation', 'Negotiation'], ['won', 'Won'], ['lost', 'Lost'],
        ];
        foreach ($stages as $index => [$key, $label]) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . self::table('crm_stages') . ' (stage_key,label,sort_order,active,created_at) VALUES (%s,%s,%d,1,%s) ON DUPLICATE KEY UPDATE label=VALUES(label), sort_order=VALUES(sort_order)',
                $key, $label, $index + 1, $now
            ));
        }
        $complexity = [
            ['social_media_static', 'Social media static', 1], ['advanced_graphic_design', 'Advanced graphic design', 2],
            ['carousel', 'Carousel', 3], ['short_video_editing', 'Short video editing', 3], ['ai_video', 'AI video', 4],
            ['complex_motion_graphics', 'Complex motion graphics', 5], ['brand_identity', 'Brand identity', 8],
        ];
        foreach ($complexity as [$key, $label, $points]) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . self::table('analytics_complexity') . ' (service_key,label,points,effective_from,created_by,created_at) VALUES (%s,%s,%f,%s,%d,%s) ON DUPLICATE KEY UPDATE label=VALUES(label), points=VALUES(points)',
                $key, $label, $points, gmdate('Y-m-d'), get_current_user_id() ?: 0, $now
            ));
        }
    }

    private static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'vtp_' . $name;
    }
}


<?php

defined('ABSPATH') || exit;

final class VPM_Permissions {
    public static function logged_in(): bool { return is_user_logged_in(); }

    public static function connect(): bool {
        // Administrators retain access even if the module was activated before
        // the custom capability was registered. Employees and managers continue
        // to use the dedicated capability.
        return current_user_can('manage_options') || current_user_can('vpm_use_connect');
    }

    public static function own_analytics(): bool {
        return current_user_can('vpm_view_own_analytics');
    }

    public static function team_analytics(): bool {
        return current_user_can('vpm_view_team_analytics');
    }

    public static function finance(): bool {
        if (current_user_can('vpm_manage_finance')) return true;
        return (bool) get_user_meta(get_current_user_id(), 'vpm_finance_access', true);
    }

    public static function crm(): bool {
        return current_user_can('vpm_manage_crm');
    }

    public static function lead_hunter(): bool {
        return current_user_can('vpm_run_lead_hunter');
    }

    public static function table(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix . 'vtp_' . $suffix;
    }

    public static function audit(string $area, string $action, string $object_type = '', int $object_id = 0, array $details = []): void {
        global $wpdb;
        $wpdb->insert(self::table('audit_events'), [
            'user_id' => get_current_user_id() ?: null,
            'area' => sanitize_key($area),
            'action' => sanitize_key($action),
            'object_type' => sanitize_key($object_type),
            'object_id' => $object_id ?: null,
            'details_json' => wp_json_encode($details),
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function user_can_read_conversation(int $conversation_id): bool {
        global $wpdb;
        if (!self::connect()) return false;
        // Every conversation is membership-gated. Administrators do not automatically
        // inherit access to private one-to-one, group, project, or task conversations.
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('connect_members') . ' WHERE conversation_id=%d AND user_id=%d',
            $conversation_id,
            get_current_user_id()
        ));
    }
}


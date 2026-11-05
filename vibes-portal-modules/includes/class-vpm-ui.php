<?php
defined('ABSPATH') || exit;

final class VPM_UI {
    public static function boot(): void {
        self::sync_admin_display_name();
        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 10);
        add_action('wp_enqueue_scripts', [self::class, 'trim_portal_scripts'], 1000);
    }

    public static function sync_admin_display_name(): void {
        $user = get_user_by('email', 'admin@vibesagency.co') ?: get_user_by('login', 'admin');
        if (!$user || $user->display_name === 'Mohammad Alty') return;
        wp_update_user([
            'ID' => $user->ID,
            'display_name' => 'Mohammad Alty',
            'first_name' => 'Mohammad',
            'last_name' => 'Alty',
        ]);
    }

    public static function trim_portal_scripts(): void {
        if (!is_page('team-portal')) return;
        wp_dequeue_script('hostinger_chatbot');
        wp_dequeue_script('hostinger-reach-embed');
        wp_dequeue_script('wp-emoji');
        wp_dequeue_script('wp-emoji-release');
        wp_dequeue_style('wp-emoji');
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
    }

    public static function enqueue(): void {
        if (!is_page('team-portal') || !is_user_logged_in()) return;
        $asset_version = '0.4.6-prospects';
        wp_enqueue_style('vpm-modules', VPM_URL . 'assets/modules.css', [], $asset_version);
        wp_enqueue_script('vpm-modules', VPM_URL . 'assets/modules.js', [], $asset_version, true);
        wp_localize_script('vpm-modules', 'VPM', [
            'root' => esc_url_raw(rest_url('vibes/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'userId' => get_current_user_id(),
            'assetsUrl' => esc_url_raw(VPM_URL . 'assets/'),
        ]);
    }
}

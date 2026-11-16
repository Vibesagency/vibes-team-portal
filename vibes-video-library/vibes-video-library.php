<?php
/**
 * Plugin Name: Vibes Video Library
 * Description: Shared video content storage for the Vibes public site and Team Admin Portal.
 * Version: 1.0.16
 */
if (!defined('ABSPATH')) { exit; }
add_action('wp_enqueue_scripts', function () {
    if (!is_page('team-portal')) { return; }
    // Unconditional dependency on vpm-modules (defines window.VTPR): WP resolves script
    // dependencies by handle at print time, not at registration time, so this is safe even
    // though both plugins register on the same wp_enqueue_scripts priority and this callback
    // can run before vpm-modules' own registration call.
    wp_register_script('vtp-video-admin', plugins_url('video-admin.js', __FILE__), ['vpm-modules'], '1.0.16', true);
    wp_enqueue_script('vtp-video-admin');
    wp_localize_script('vtp-video-admin', 'VTP', [
        'root' => esc_url_raw(rest_url('vibes/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
    ]);
});
function vtp_video_defaults(): array {
    return [
        ['id'=>'vibes-1','section'=>'vibes','platform'=>'youtube','link'=>'https://www.youtube.com/embed/Kw5XQjg7TTQ?si=KOgZ0j2oUkHEZDss','title'=>'Big Ideas. No Limits.','description'=>'A Vibes-made AI video built for bold visual storytelling.','thumbnail'
            =>'https://i.ytimg.com/vi/Kw5XQjg7TTQ/hqdefault.jpg','logo'=>'','sort'=>1],
        ['id'=>'vibes-2','section'=>'vibes','platform'=>'youtube','link'=>'https://www.youtube-nocookie.com/embed/3bQnur3hNq0?rel=0','title'=>'Featured AI Video','description'=>'An original Vibes experiment in AI-powered production.','thumbnail'=>'https://i.ytimg
            .com/vi/3bQnur3hNq0/hqdefault.jpg','logo'=>'','sort'=>2],
        ['id'=>'vibes-3','section'=>'vibes','platform'=>'youtube','link'=>'https://www.youtube-nocookie.com/embed/Qhpm0h8qy-g?rel=0','title'=>'Logo Animation Story','description'=>'A visual identity brought to life through motion.','thumbnail'=>'https://i.ytimg
            .com/vi/Qhpm0h8qy-g/hqdefault.jpg','logo'=>'','sort'=>3],
        ['id'=>'vibes-4','section'=>'vibes','platform'=>'youtube','link'=>'https://www.youtube-nocookie.com/embed/dC0AWY0pDUY?rel=0','title'=>'Storage War','description'=>'A playful AI concept from the Vibes studio.','thumbnail'=>'https://i.ytimg.com/vi
            /dC0AWY0pDUY/hqdefault.jpg','logo'=>'','sort'=>4],
        ['id'=>'vibes-5','section'=>'vibes','platform'=>'youtube','link'=>'https://www.youtube-nocookie.com/embed/5ZgkovsNrnI?rel=0','title'=>'Printer vs AI','description'=>'An original short-form concept made by Vibes.','thumbnail'=>'https://i.ytimg.com/vi
            /5ZgkovsNrnI/hqdefault.jpg','logo'=>'','sort'=>5],
        ['id'=>'vibes-6','section'=>'vibes','platform'=>'youtube','link'=>'https://www.youtube-nocookie.com/embed/ugl0eu0oe3I?rel=0','title'=>'Calculator vs Excel','description'=>'A concise motion idea from the Vibes creative team.','thumbnail'=>'https://i.ytimg
            .com/vi/ugl0eu0oe3I/hqdefault.jpg','logo'=>'','sort'=>6],
    ];
}
function vtp_video_items(): array {
    $items = get_option('vtp_video_library', null);
    if (!is_array($items)) { $items = vtp_video_defaults(); update_option('vtp_video_library', $items, false); }
    return array_values($items);
}
add_action('rest_api_init', function () {
    register_rest_route('vibes/v1', '/video-library', [
        'methods'=>'GET', 'callback'=>function () { return rest_ensure_response(vtp_video_items()); }, 'permission_callback'=>'__return_true'
    ]);
    register_rest_route('vibes/v1', '/video-library', [
        'methods'=>'POST', 'callback'=>function (WP_REST_Request $request) {
            $items = $request->get_param('items');
            if (!is_array($items)) { return new WP_Error('invalid_items', 'items must be an array', ['status'=>400]); }
            $clean=[]; $sort=1;
            foreach ($items as $item) {
                if (!is_array($item)) { continue; }
                $clean[]=[
                    'id'=>sanitize_key($item['id'] ?? ('video-'.wp_generate_uuid4())),
                    'section'=>in_array(($item['section'] ?? 'client'), ['vibes','client'], true) ? $item['section'] : 'client',
                    'platform'=>sanitize_key($item['platform'] ?? 'youtube'),
                    'link'=>esc_url_raw($item['link'] ?? ''),
                    'title'=>sanitize_text_field($item['title'] ?? ''),
                    'description'=>sanitize_textarea_field($item['description'] ?? ''),
                    'thumbnail'=>esc_url_raw($item['thumbnail'] ?? ''),
                    'logo'=>esc_url_raw($item['logo'] ?? ''),
                    'sort'=>$sort++,
                ];
            }
            update_option('vtp_video_library', $clean, false);
            return rest_ensure_response($clean);
        },
        'permission_callback'=>function () { return is_user_logged_in(); }
    ]);
});
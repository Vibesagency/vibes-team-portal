<?php
/**
 * Plugin Name: Vibes Quotation Reference Override
 * Description: Keeps quotation references and sales records administrator-only.
 * Version: 1.0.2
 * Author: Vibes Agency
 */
if (!defined('ABSPATH')) exit;

function vce_admin_only_user(): bool {
    $user = wp_get_current_user();
    return current_user_can('manage_options') || ($user && in_array('administrator', (array) $user->roles, true));
}

add_action('wp_head', static function () {
    if (!is_user_logged_in() || vce_admin_only_user()) return;
    echo '<style id="vce-hide-admin-sales">.vtp-page > .vtp-files-section:nth-of-type(2){display:none!important}.vtp-nav a[href="#inquiries"],.vtp-nav a[href="#quotations"],.vtp-nav a[href="#invoices"]{display:none!important}</style>';
}, 99);

add_filter('rest_pre_dispatch', static function ($result, $server, $request) {
    $route = $request->get_route();
    $is_admin = vce_admin_only_user();

    if (!$is_admin) {
        foreach (['/vibes/v1/inquiries', '/vibes/v1/quotations', '/vibes/v1/invoices'] as $base) {
            if ($route === $base || strpos($route, $base . '/') === 0) {
                return new WP_Error('forbidden', 'Sales records are restricted to administrators.', ['status' => 403]);
            }
        }
        if ($route === '/vibes/v1/files-brand' && $request->get_method() === 'GET') {
            if ($result instanceof WP_REST_Response) {
                $data = $result->get_data();
                if (is_array($data)) {
                    $data['quotationTemplates'] = [];
                    $result->set_data($data);
                }
            } elseif (is_array($result)) {
                $result['quotationTemplates'] = [];
            }
            return $result;
        }
    }

    if ($route !== '/vibes/v1/quotation-template-links') return $result;

    $defaults = [
        'one_time' => ['label' => 'One-time project quotation', 'url' => 'https://drive.google.com/file/d/1OgUtWtiKgGC-J2XRgzB2D8eSx7AwYWZd/view?usp=drivesdk'],
        'six_months_up_to_7' => ['label' => '6-month quotation · up to 7 services', 'url' => 'https://drive.google.com/file/d/1HO2cwpKlVD30W0_YPHHfqygO35f0NuPN/view?usp=drivesdk'],
        'six_months_more_than_7' => ['label' => '6-month quotation · more than 7 services', 'url' => 'https://drive.google.com/file/d/1dAURkQGtD-WPcWehkL8q75dKYpGsdhVC/view?usp=drivesdk'],
        'one_year_up_to_7' => ['label' => '1-year quotation · up to 7 services', 'url' => 'https://drive.google.com/file/d/1741QmzMXiVrxgBDaFs_Q1W3jwfZ8FHyR/view?usp=drivesdk'],
        'one_year_more_than_7' => ['label' => '1-year quotation · more than 7 services', 'url' => 'https://drive.google.com/file/d/1xsufa8Vd9rzF8AdzfxxeJq-Pym-xWrSL/view?usp=drivesdk'],
    ];
    $saved = get_option('vce_quotation_templates', []);
    if (!is_array($saved)) $saved = [];

    if ($request->get_method() === 'POST') {
        if (!$is_admin) return new WP_Error('forbidden', 'Only administrators can update quotation references.', ['status' => 403]);
        $data = $request->get_json_params();
        $key = sanitize_key((string) ($data['key'] ?? ''));
        $url = esc_url_raw((string) ($data['url'] ?? ''));
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (!isset($defaults[$key])) return new WP_Error('invalid_template', 'Choose a valid quotation template.', ['status' => 400]);
        if (!$url || !wp_http_validate_url($url) || !in_array($host, ['drive.google.com', 'docs.google.com'], true)) return new WP_Error('invalid_template_url', 'Use a Google Drive or Google Docs link.', ['status' => 400]);
        $saved[$key] = ['label' => $defaults[$key]['label'], 'url' => $url];
        update_option('vce_quotation_templates', $saved, false);
    }

    if (!$is_admin) return rest_ensure_response(['admin' => false, 'templates' => []]);
    $templates = [];
    foreach ($defaults as $key => $default) {
        $item = array_merge($default, is_array($saved[$key] ?? null) ? $saved[$key] : []);
        $templates[] = ['key' => $key, 'label' => sanitize_text_field($item['label']), 'url' => esc_url_raw($item['url'])];
    }
    return rest_ensure_response(['admin' => true, 'templates' => $templates]);
}, 99, 3);

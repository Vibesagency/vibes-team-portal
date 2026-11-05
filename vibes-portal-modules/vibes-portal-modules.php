<?php
/**
 * Plugin Name: Vibes Portal Modules
 * Description: Integrated Vibes Connect, Analytics, Finance, CRM and Lead Hunter modules for the existing Team Portal.
 * Version: 0.3.1
 * Author: Vibes Agency
 * Text Domain: vibes-portal-modules
 */

defined('ABSPATH') || exit;

define('VPM_VERSION', '0.3.2');
define('VPM_FILE', __FILE__);
define('VPM_DIR', plugin_dir_path(__FILE__));
define('VPM_URL', plugin_dir_url(__FILE__));

require_once VPM_DIR . 'includes/class-vpm-activator.php';
require_once VPM_DIR . 'includes/class-vpm-permissions.php';
require_once VPM_DIR . 'includes/class-vpm-rest.php';
require_once VPM_DIR . 'includes/class-vpm-cron.php';
require_once VPM_DIR . 'includes/class-vpm-ui.php';

register_activation_hook(__FILE__, ['VPM_Activator', 'activate']);
register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook('vpm_daily_lead_scan');
});

add_action('plugins_loaded', static function () {
    VPM_Activator::maybe_upgrade();
    VPM_REST::boot();
    VPM_Cron::boot();
    VPM_UI::boot();
});


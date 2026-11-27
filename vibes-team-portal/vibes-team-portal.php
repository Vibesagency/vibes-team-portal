<?php
/**
 * Plugin Name: Vibes Team Portal
 * Description: Private employee workspace for Vibes Agency: client requests, quotations, projects, tasks, invoices, announcements and leave.
 * Version: 0.6.46
 * Author: Vibes Agency
 * Text Domain: vibes-team-portal
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VTP_VERSION', '0.6.77');
define('VTP_FILE', __FILE__);
define('VTP_DIR', plugin_dir_path(__FILE__));
define('VTP_URL', plugin_dir_url(__FILE__));

require_once VTP_DIR . 'includes/class-vtp-catalog.php';
require_once VTP_DIR . 'includes/class-vtp-activator.php';
require_once VTP_DIR . 'includes/class-vtp-rest.php';
require_once VTP_DIR . 'includes/class-vtp-office.php';
require_once VTP_DIR . 'includes/class-vtp-app.php';

register_activation_hook(__FILE__, ['VTP_Activator', 'activate']);

add_action('plugins_loaded', static function () {
    VTP_Activator::maybe_upgrade();
    VTP_Catalog::boot();
    VTP_REST::boot();
    VTP_Office::boot();
    VTP_App::boot();
});

register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook('vtp_office_cleanup');
});



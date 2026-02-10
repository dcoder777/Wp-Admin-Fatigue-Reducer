<?php
/**
 * Plugin Name: Admin Fatigue Reducer
 * Description: Reduces admin notification overload by learning notice interaction patterns and intelligently hiding or batching low-value notices.
 * Version: 1.0.0
 * Author: Admin Fatigue Reducer
 * License: GPL-2.0-or-later
 * Text Domain: admin-fatigue-reducer
 */

if (! defined('ABSPATH')) {
    exit;
}

define('AFR_PLUGIN_VERSION', '1.0.0');
define('AFR_PLUGIN_FILE', __FILE__);
define('AFR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AFR_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once AFR_PLUGIN_DIR . 'includes/class-afr-plugin.php';

AFR_Plugin::instance();

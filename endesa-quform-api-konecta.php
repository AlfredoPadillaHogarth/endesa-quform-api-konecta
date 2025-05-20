<?php
/**
* Plugin Name: Endesa Qform Konecta API Integration
* Description: A plugin to integrate the Konecta API with Quform submissions.
* @version ENDESA_API_KONECTA_VERSION
* Author: Hogarth
**/

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('ENDESA_API_KONECTA_VERSION', '1.0.0');
define('ENDESA_API_KONECTA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ENDESA_API_KONECTA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ENDESA_API_KONECTA_TABLE_NAME', 'endesa_konecta_qform_submissions');

// Activation hook
function activate_endesa_quform_api_konecta() {
    require_once ENDESA_API_KONECTA_PLUGIN_DIR . 'includes/class-endesa-quform-api-konecta-activator.php';
    Endesa_Quform_API_Konecta_Activator::activate();
}
register_activation_hook(__FILE__, 'activate_endesa_quform_api_konecta');

// Deactivation hook
function deactivate_endesa_quform_api_konecta() {
    require_once ENDESA_API_KONECTA_PLUGIN_DIR . 'includes/class-endesa-quform-api-konecta-deactivator.php';
    Endesa_Quform_API_Konecta_Deactivator::deactivate();
}
register_deactivation_hook(__FILE__, 'deactivate_endesa_quform_api_konecta');

// Instantiate the plugin
function endesa_api_konecta_init() {
    global $endesa_api_konecta;
    if (!isset($endesa_api_konecta)) {
        require_once ENDESA_API_KONECTA_PLUGIN_DIR . 'includes/class-endesa-quform-api-konecta.php';
        $endesa_api_konecta = new Endesa_Quform_API_Konecta();
    }

    // Add settings link to the plugin page
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$endesa_api_konecta, 'add_settings_link']);
}
add_action('plugins_loaded', 'endesa_api_konecta_init');
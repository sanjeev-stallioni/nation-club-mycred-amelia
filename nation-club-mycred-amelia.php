<?php
/**
 * Plugin Name: Nation Club myCRED Amelia
 * Description: Custom integration between myCRED and Amelia Pro that manages vendor-funded loyalty points, role-based expiry rules, enhanced points visibility, and auditable transaction exports.
 * Version:     1.0.0
 * Author:      Stallioni Net Solutions
 * Author URI:  https://www.stallioni.com/
 * Text Domain: nation-club-mycred-amelia
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('NC_MYCRE_AMELIA_PATH', plugin_dir_path(__FILE__));
define('NC_MYCRE_AMELIA_URL', plugin_dir_url(__FILE__));


add_action( 'amelia_after_appointment_status_updated', function( $appointment, $status ) {

    if ( strtolower( $status ) === 'approved' ) {

        error_log( '=== Amelia Appointment Approved ===' );
        error_log( json_encode( $appointment) );

    }

}, 10, 2 );

// Enqueue JS
function enqueue_amelia_custom_js() {
    global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'ameliaemployeepanel')) {
        $js_path = NC_MYCRE_AMELIA_PATH . 'assets/custom-tab.js';
        $ver     = file_exists($js_path) ? filemtime($js_path) : '1.0';
        wp_enqueue_script('amelia-custom-tab-js', NC_MYCRE_AMELIA_URL . 'assets/custom-tab.js', array('jquery'), $ver, true);
        wp_localize_script('amelia-custom-tab-js', 'ameliaAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('amelia-custom-nonce')
        ));
    }
}
add_action('wp_enqueue_scripts', 'enqueue_amelia_custom_js');

// Load includes
require_once NC_MYCRE_AMELIA_PATH . 'includes/nc_log.php';
// require_once NC_MYCRE_AMELIA_PATH . 'includes/admin.php';
require_once NC_MYCRE_AMELIA_PATH . 'includes/mycred-hooks.php';
require_once NC_MYCRE_AMELIA_PATH . 'includes/vendor-transactions.php';




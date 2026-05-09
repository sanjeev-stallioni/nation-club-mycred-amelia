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

// Composer autoloader (dompdf and other vendor libs)
if ( file_exists( NC_MYCRE_AMELIA_PATH . 'vendor/autoload.php' ) ) {
    require_once NC_MYCRE_AMELIA_PATH . 'vendor/autoload.php';
}


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
require_once NC_MYCRE_AMELIA_PATH . 'includes/expiry-rules.php';            // Defines get_mycred_customer_expiry_timestamp() — must load BEFORE mycred-hooks.php
require_once NC_MYCRE_AMELIA_PATH . 'includes/customer-point-batches.php'; // Per-batch expiry — must load BEFORE mycred-hooks.php (earn/redeem hooks call into it)
require_once NC_MYCRE_AMELIA_PATH . 'includes/mycred-hooks.php';
require_once NC_MYCRE_AMELIA_PATH . 'includes/vendor-transactions.php';
require_once NC_MYCRE_AMELIA_PATH . 'includes/vendor-pool.php';
require_once NC_MYCRE_AMELIA_PATH . 'includes/vendor-statements.php';
require_once NC_MYCRE_AMELIA_PATH . 'includes/reconciliation.php';
require_once NC_MYCRE_AMELIA_PATH . 'includes/customer-points-shortcode.php'; // [nc_my_points] — customer-facing points + batch breakdown
require_once NC_MYCRE_AMELIA_PATH . 'includes/log-viewer.php';                  // Nation Club → Log
require_once NC_MYCRE_AMELIA_PATH . 'includes/cancellation-reason.php';         // Required reason modal + customer email + Cancellation Log admin page
require_once NC_MYCRE_AMELIA_PATH . 'includes/vendor-exit.php';                 // Proposal 5 — managed offboarding (notice → hide listing → final settlement)
require_once NC_MYCRE_AMELIA_PATH . 'includes/test-reset.php'; // FOR TESTING ONLY — remove or gate before production




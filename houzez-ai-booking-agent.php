<?php
/**
 * Plugin Name: Houzez AI Viewing-Booking Agent
 * Description: Chat agent for Houzez listings that checks the agent's real Google Calendar and books viewings. Connects an agent's Google Calendar, chats with visitors, books/reschedules/cancels viewings.
 * Version:     0.4.0
 * Requires PHP: 7.4
 * Text Domain: houzez-ai-booking
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HZB_VERSION', '0.4.0' );
define( 'HZB_DIR', plugin_dir_path( __FILE__ ) );
define( 'HZB_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'crypto', 'settings', 'slot-calculator', 'google-oauth', 'bookings', 'calendar', 'booking-service', 'groq', 'chat', 'front', 'dashboard', 'admin' ) as $f ) {
	require_once HZB_DIR . 'includes/class-' . $f . '.php';
}

register_activation_hook( __FILE__, array( 'HZB_Bookings', 'install' ) );
add_action( 'plugins_loaded', function () { // Create the table after a plain file copy too.
	if ( get_option( 'hzb_db_version' ) !== HZB_VERSION ) {
		HZB_Bookings::install();
		update_option( 'hzb_db_version', HZB_VERSION );
	}
	HZB_Front::init();
	HZB_Dashboard::init();
} );
add_action( 'init', function () { // After a plain file copy there is no activation hook, so create the dashboard page here.
	if ( get_option( 'hzb_page_version' ) !== HZB_VERSION ) {
		HZB_Dashboard::ensure_page();
		update_option( 'hzb_page_version', HZB_VERSION );
	}
}, 20 );
add_action( 'rest_api_init', array( 'HZB_Google_OAuth', 'register_routes' ) );
add_action( 'rest_api_init', array( 'HZB_Chat', 'register_routes' ) );
add_action( 'admin_menu', array( 'HZB_Admin', 'menu' ) );
add_action( 'admin_init', array( 'HZB_Settings', 'register' ) );
add_action( 'admin_init', array( 'HZB_Admin', 'handle_actions' ) );
add_action( 'add_meta_boxes', array( 'HZB_Admin', 'meta_box' ) );

/**
 * Open slots for a Houzez agent (houzez_agent post ID) in a date range.
 *
 * @param int    $agent_id   houzez_agent post ID.
 * @param string $start_date Y-m-d (agent timezone), inclusive.
 * @param string $end_date   Y-m-d (agent timezone), inclusive.
 * @return array|WP_Error    List of array{start:string,end:string} in ISO 8601 with agent offset.
 */
function get_agent_availability( $agent_id, $start_date, $end_date ) {
	return HZB_Calendar::get_availability( (int) $agent_id, $start_date, $end_date );
}

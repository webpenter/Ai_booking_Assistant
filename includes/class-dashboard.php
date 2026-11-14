<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Houzez frontend dashboard integration: a "Viewing Calendar" page (own page template that reuses the Houzez
 * dashboard shell) where an agent connects their Google Calendar and sees their viewings. No theme edits:
 * the template is registered by filter and the sidebar link is added with a small script.
 */
class HZB_Dashboard {
	const TEMPLATE = 'hzb-dashboard-calendar.php';
	const OPT_PAGE = 'hzb_dashboard_page_id';

	public static function init() {
		add_filter( 'theme_page_templates', array( __CLASS__, 'register_template' ) );
		add_filter( 'template_include', array( __CLASS__, 'use_template' ) );
		add_filter( 'houzez_is_dashboard_filter', array( __CLASS__, 'mark_dashboard' ) );
		add_action( 'wp_footer', array( __CLASS__, 'menu_link' ), 30 );
		add_action( 'admin_post_hzb_connect', array( __CLASS__, 'action_connect' ) );
		add_action( 'admin_post_hzb_disconnect', array( __CLASS__, 'action_disconnect' ) );
	}

	/* ---------- page + template ---------- */

	public static function register_template( $t ) {
		$t[ self::TEMPLATE ] = 'User Dashboard: Viewing Calendar';
		return $t;
	}

	public static function mark_dashboard( $files ) {
		$files[] = self::TEMPLATE;
		return $files;
	}

	public static function use_template( $tpl ) {
		if ( is_page() && get_page_template_slug() === self::TEMPLATE ) {
			return HZB_DIR . 'templates/dashboard-calendar.php';
		}
		return $tpl;
	}

	/** Creates the dashboard page once (on activation / first load after an update). */
	public static function ensure_page() {
		$id = (int) get_option( self::OPT_PAGE );
		if ( $id && get_post_status( $id ) ) {
			return $id;
		}
		$found = get_posts( array( 'post_type' => 'page', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_wp_page_template', 'meta_value' => self::TEMPLATE, 'post_status' => 'any' ) );
		$id    = $found ? (int) $found[0] : (int) wp_insert_post( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Viewing Calendar',
			'post_name'   => 'viewing-calendar',
		) );
		if ( $id ) {
			update_post_meta( $id, '_wp_page_template', self::TEMPLATE );
			update_option( self::OPT_PAGE, $id, false );
		}
		return $id;
	}

	public static function page_url() {
		$id = (int) get_option( self::OPT_PAGE );
		return $id && get_post_status( $id ) ? get_permalink( $id ) : home_url( '/' );
	}

	/** Adds the sidebar entry to the Houzez dashboard menu (the theme offers no hook for it). */
	public static function menu_link() {
		if ( ! is_user_logged_in() || ! function_exists( 'houzez_is_dashboard' ) || ! houzez_is_dashboard() || ! self::agent_for_user( get_current_user_id(), true ) ) {
			return;
		}
		$active = is_page() && get_page_template_slug() === self::TEMPLATE;
		$li     = '<li class="side-menu-item"><a' . ( $active ? ' class="active"' : '' ) . ' href="' . esc_url( self::page_url() ) . '">'
			. '<svg class="mr-2" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" style="vertical-align:-3px"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg> Viewing Calendar</a></li>';
		echo '<script>(function(){var m=document.querySelector(".side-menu");if(m){m.insertAdjacentHTML("beforeend",' . wp_json_encode( $li ) . ');}})();</script>';
	}

	/* ---------- access ---------- */

	/**
	 * The houzez_agent post a user manages. Admins/managers may pick any agent (returns 0 here, the page lets them choose).
	 * With $any true, returns a truthy value for anyone who may use the page.
	 */
	public static function agent_for_user( $user_id, $any = false ) {
		$agent = (int) get_user_meta( $user_id, 'fave_author_agent_id', true );
		if ( $agent && get_post_type( $agent ) === 'houzez_agent' ) {
			return $agent;
		}
		// Not linked via user meta: match the agent profile by the same email address.
		$user  = get_userdata( $user_id );
		$found = $user ? get_posts( array(
			'post_type' => 'houzez_agent', 'post_status' => array( 'publish', 'draft', 'pending' ), 'numberposts' => 1, 'fields' => 'ids',
			'meta_query' => array( array( 'key' => 'fave_agent_email', 'value' => $user->user_email ) ),
		) ) : array();
		if ( $found ) {
			return (int) $found[0];
		}
		if ( $any && user_can( $user_id, 'manage_options' ) ) {
			return -1;
		}
		return 0;
	}

	public static function can_manage( $user_id, $agent_id ) {
		if ( ! $user_id || get_post_type( $agent_id ) !== 'houzez_agent' ) {
			return false;
		}
		return user_can( $user_id, 'manage_options' ) || self::agent_for_user( $user_id ) === (int) $agent_id;
	}

	/* ---------- actions (admin-post.php works for logged-in front-end users) ---------- */

	public static function action_connect() {
		$agent = (int) ( $_GET['agent'] ?? 0 );
		check_admin_referer( 'hzb_connect_' . $agent );
		if ( ! self::can_manage( get_current_user_id(), $agent ) ) {
			wp_die( 'You may not manage this agent.', 403 );
		}
		if ( ! HZB_Settings::get( 'google_client_id' ) || ! HZB_Settings::get( 'google_client_secret' ) ) {
			wp_safe_redirect( add_query_arg( 'hzb_msg', 'notready', self::page_url() ) );
			exit;
		}
		wp_redirect( HZB_Google_OAuth::auth_url( $agent, 'front' ) ); // External Google URL.
		exit;
	}

	public static function action_disconnect() {
		$agent = (int) ( $_GET['agent'] ?? 0 );
		check_admin_referer( 'hzb_disconnect_' . $agent );
		if ( ! self::can_manage( get_current_user_id(), $agent ) ) {
			wp_die( 'You may not manage this agent.', 403 );
		}
		HZB_Google_OAuth::disconnect( $agent );
		wp_safe_redirect( add_query_arg( array( 'agent' => $agent, 'hzb_msg' => 'disconnected' ), self::page_url() ) );
		exit;
	}

	public static function action_url( $action, $agent ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=hzb_' . $action . '&agent=' . (int) $agent ), 'hzb_' . $action . '_' . (int) $agent );
	}

	/** Upcoming viewings for an agent (confirmed, plus holds still waiting for the buyer's email). */
	public static function upcoming( $agent_id, $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . HZB_Bookings::table() . ' WHERE agent_id = %d AND start_utc > UTC_TIMESTAMP() AND ' . HZB_Bookings::occupying_sql() . ' ORDER BY start_utc ASC LIMIT %d',
			$agent_id, $limit
		) );
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end "Viewing Calendar" page where an agent/host connects their Google Calendar and sees their bookings.
 * Houzez: rendered inside the Houzez dashboard shell (own page template, no theme edits).
 * Any other theme: a normal page containing the [hzb_calendar_connect] shortcode.
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
		add_shortcode( 'hzb_calendar_connect', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* ---------- page + template ---------- */

	public static function register_template( $t ) {
		if ( HZB_Listings::family() === 'houzez' ) {
			$t[ self::TEMPLATE ] = 'User Dashboard: Viewing Calendar';
		}
		return $t;
	}

	public static function mark_dashboard( $files ) {
		$files[] = self::TEMPLATE;
		return $files;
	}

	public static function use_template( $tpl ) {
		if ( is_page() && get_page_template_slug() === self::TEMPLATE && HZB_Listings::family() === 'houzez' ) {
			return HZB_DIR . 'templates/dashboard-calendar.php';
		}
		return $tpl;
	}

	public static function is_page() {
		$id = (int) get_option( self::OPT_PAGE );
		return $id && is_page( $id );
	}

	public static function assets() {
		if ( self::is_page() ) {
			wp_enqueue_style( 'hzb-booking', HZB_URL . 'assets/booking.css', array(), HZB_VERSION );
		}
	}

	/** Creates the connect page once. The Houzez template is applied only on Houzez; elsewhere the shortcode renders it. */
	public static function ensure_page() {
		$id = (int) get_option( self::OPT_PAGE );
		if ( ! $id || ! get_post_status( $id ) ) {
			$found = get_posts( array( 'post_type' => 'page', 'numberposts' => 1, 'fields' => 'ids', 's' => '[hzb_calendar_connect]', 'post_status' => 'any' ) );
			$id    = $found ? (int) $found[0] : (int) wp_insert_post( array(
				'post_type'    => 'page', 'post_status' => 'publish', 'post_title' => 'Viewing Calendar',
				'post_name'    => 'viewing-calendar', 'post_content' => '[hzb_calendar_connect]',
			) );
		}
		if ( $id ) {
			if ( HZB_Listings::family() === 'houzez' ) {
				update_post_meta( $id, '_wp_page_template', self::TEMPLATE );
			} elseif ( get_post_meta( $id, '_wp_page_template', true ) === self::TEMPLATE ) {
				update_post_meta( $id, '_wp_page_template', 'default' );
			}
			update_option( self::OPT_PAGE, $id, false );
		}
		return $id;
	}

	public static function page_url() {
		$id = (int) get_option( self::OPT_PAGE );
		return $id && get_post_status( $id ) ? get_permalink( $id ) : home_url( '/' );
	}

	/** Adds a "Viewing Calendar" link to the theme's dashboard menu (Houzez built in; others via the menu selector setting). */
	public static function menu_link() {
		if ( ! is_user_logged_in() || ! self::owner_for_user( get_current_user_id(), false, true ) ) {
			return;
		}
		$sel = HZB_Settings::get( 'menu_selector' );
		if ( ! $sel && ( HZB_Listings::family() !== 'houzez' || ! function_exists( 'houzez_is_dashboard' ) || ! houzez_is_dashboard() ) ) {
			return;
		}
		$sel    = $sel ? $sel : '.side-menu';
		$active = self::is_page();
		$li     = '<li class="side-menu-item"><a' . ( $active ? ' class="active"' : '' ) . ' href="' . esc_url( self::page_url() ) . '">Viewing Calendar</a></li>';
		echo '<script>(function(){var m=document.querySelector(' . wp_json_encode( $sel ) . ');if(m){m.insertAdjacentHTML("beforeend",' . wp_json_encode( $li ) . ');}})();</script>';
	}

	/* ---------- who may manage which calendar ---------- */

	/** True if the user owns at least one listing of a bookable type. */
	private static function authors_listings( $user_id ) {
		$n = get_posts( array( 'post_type' => HZB_Listings::types(), 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'author' => $user_id, 'numberposts' => 1, 'fields' => 'ids' ) );
		return (bool) $n;
	}

	/**
	 * The calendar owner (post ID) a user manages: their linked/email-matched agent profile, else, on author-based
	 * themes, their own owner record. $peek true: only tell whether the user may use the page (no records created).
	 */
	public static function owner_for_user( $user_id, $create = true, $peek = false ) {
		$linked = (int) get_user_meta( $user_id, 'fave_author_agent_id', true ); // Houzez link.
		if ( $linked && HZB_Owner::is_owner( $linked ) ) {
			return $linked;
		}
		$user = get_userdata( $user_id );
		if ( $user && ( $by_email = HZB_Owner::profile_by_email( $user->user_email ) ) ) {
			return $by_email;
		}
		if ( HZB_Listings::family() !== 'houzez' && ( user_can( $user_id, 'manage_options' ) || self::authors_listings( $user_id ) ) ) {
			return $peek ? -1 : HZB_Owner::for_user( $user_id, $create );
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return -1; // Admins can pick any agent on the page.
		}
		return 0;
	}

	public static function can_manage( $user_id, $owner_id ) {
		if ( ! $user_id || ! HZB_Owner::is_owner( $owner_id ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		if ( get_post_type( $owner_id ) === HZB_Owner::CPT ) {
			return HZB_Owner::user_id( $owner_id ) === (int) $user_id;
		}
		return self::owner_for_user( $user_id, false ) === (int) $owner_id;
	}

	/* ---------- actions (admin-post.php works for logged-in front-end users) ---------- */

	public static function action_connect() {
		$agent = (int) ( $_GET['agent'] ?? 0 );
		check_admin_referer( 'hzb_connect_' . $agent );
		if ( ! self::can_manage( get_current_user_id(), $agent ) ) {
			wp_die( 'You may not manage this calendar.', 403 );
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
			wp_die( 'You may not manage this calendar.', 403 );
		}
		HZB_Google_OAuth::disconnect( $agent );
		wp_safe_redirect( add_query_arg( array( 'agent' => $agent, 'hzb_msg' => 'disconnected' ), self::page_url() ) );
		exit;
	}

	public static function action_url( $action, $agent ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=hzb_' . $action . '&agent=' . (int) $agent ), 'hzb_' . $action . '_' . (int) $agent );
	}

	/** Upcoming bookings for a calendar owner (confirmed, plus holds still waiting for the buyer's email). */
	public static function upcoming( $agent_id, $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . HZB_Bookings::table() . ' WHERE agent_id = %d AND start_utc > UTC_TIMESTAMP() AND ' . HZB_Bookings::occupying_sql() . ' ORDER BY start_utc ASC LIMIT %d',
			$agent_id, $limit
		) );
	}

	/* ---------- the panel ---------- */

	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>Please <a href="' . esc_url( wp_login_url( self::page_url() ) ) . '">log in</a> to connect your calendar.</p>';
		}
		return self::panel( get_current_user_id() );
	}

	/** HTML for the connect/disconnect card and upcoming bookings. Used by the shortcode and the Houzez template. */
	public static function panel( $uid ) {
		$own = self::owner_for_user( $uid, true );
		if ( ! $own ) {
			return '<p>Your account has no calendar to manage yet. Please contact the site admin.</p>';
		}
		$is_admin = user_can( $uid, 'manage_options' );
		$agent    = $own > 0 ? $own : 0;
		if ( $is_admin && ! empty( $_GET['agent'] ) && HZB_Owner::is_owner( (int) $_GET['agent'] ) ) {
			$agent = (int) $_GET['agent'];
		}
		$choices = array();
		if ( $is_admin ) {
			$choices = get_posts( array( 'post_type' => HZB_Owner::types(), 'post_status' => array( 'publish', 'draft', 'pending' ), 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
			if ( ! $agent && $choices ) {
				$agent = $choices[0]->ID;
			}
		}
		$msgs = array(
			'connected'    => array( 'ok', 'Google Calendar connected. Visitors can now book with you.' ),
			'disconnected' => array( 'info', 'Google Calendar disconnected. The booking assistant is off for your listings until you reconnect.' ),
			'denied'       => array( 'warn', 'Access was declined on the Google screen. Press Connect and allow calendar access to continue.' ),
			'failed'       => array( 'err', 'Connecting failed. Please try again; if it keeps failing, contact the site admin.' ),
			'notready'     => array( 'warn', 'The site admin has not finished the Google setup yet.' ),
		);
		$m   = isset( $_GET['hzb_msg'] ) ? sanitize_key( $_GET['hzb_msg'] ) : '';
		$out = '<div class="hzb-panel">';
		if ( isset( $msgs[ $m ] ) ) {
			$out .= '<div class="hzb-alert hzb-alert--' . esc_attr( $msgs[ $m ][0] ) . '" role="alert">' . esc_html( $msgs[ $m ][1] ) . '</div>';
		}
		if ( $is_admin && $choices ) {
			$out .= '<form method="get" action="' . esc_url( self::page_url() ) . '" class="hzb-pick"><label for="hzb-agent"><strong>Calendar owner</strong></label> <select id="hzb-agent" name="agent" onchange="this.form.submit()">';
			foreach ( $choices as $c ) {
				$out .= '<option value="' . (int) $c->ID . '"' . selected( $agent, $c->ID, false ) . '>' . esc_html( HZB_Owner::name( $c->ID ) ) . ( HZB_Google_OAuth::is_connected( $c->ID ) ? ' (connected)' : '' ) . '</option>';
			}
			$out .= '</select></form>';
		}
		$out .= '<div class="hzb-block"><h2>Google Calendar</h2>';
		if ( $agent && HZB_Google_OAuth::is_connected( $agent ) ) {
			$email = get_post_meta( $agent, HZB_Google_OAuth::META_EMAIL, true );
			$out  .= '<p><span class="hzb-dot-on">&#9679;</span> <strong>Connected</strong>' . ( $email ? ' as ' . esc_html( $email ) : '' ) . ' for ' . esc_html( HZB_Owner::name( $agent ) ) . '.</p>'
				. '<p class="hzb-muted">The booking assistant on your listings reads your free/busy times and creates ' . esc_html( HZB_Settings::get( 'noun' ) ) . ' events on this calendar. It never reads your event details.</p>'
				. '<a class="hzb-btn hzb-btn--ghost" href="' . esc_url( self::action_url( 'disconnect', $agent ) ) . '" onclick="return confirm(\'Disconnect your Google Calendar? The booking assistant will stop offering your times.\');">Disconnect</a>';
		} elseif ( $agent ) {
			$out .= '<p><span class="hzb-dot-off">&#9679;</span> <strong>Not connected</strong></p>'
				. '<p>Connect your Google Calendar so visitors to your listings can book in your real free time. You choose the Google account and can disconnect at any time.</p>'
				. '<a class="hzb-btn" href="' . esc_url( self::action_url( 'connect', $agent ) ) . '">Connect Google Calendar</a>';
		} else {
			$out .= '<p>No calendar owner found for your account. Please contact the site admin.</p>';
		}
		$out .= '</div>';

		if ( $agent && HZB_Google_OAuth::is_connected( $agent ) ) {
			$rows = self::upcoming( $agent );
			$tz   = HZB_Calendar::timezone( $agent );
			$out .= '<div class="hzb-block"><h2>Upcoming</h2>';
			if ( ! $rows ) {
				$out .= '<p class="hzb-muted">Nothing booked yet.</p>';
			} else {
				$out .= '<div class="hzb-table"><table><thead><tr><th>When (' . esc_html( $tz->getName() ) . ')</th><th>Listing</th><th>Guest</th><th>Type</th><th>Status</th></tr></thead><tbody>';
				foreach ( $rows as $r ) {
					$out .= '<tr><td>' . esc_html( HZB_Chat::format_ts( strtotime( $r->start_utc . ' UTC' ), $tz ) ) . '</td>'
						. '<td><a href="' . esc_url( get_permalink( $r->listing_id ) ) . '">' . esc_html( get_the_title( $r->listing_id ) ) . '</a></td>'
						. '<td>' . esc_html( $r->buyer_name ) . '<br><small>' . esc_html( $r->buyer_email ) . '</small></td>'
						. '<td>' . ( $r->is_virtual ? 'Virtual' : 'In person' ) . ( $r->meet_link ? '<br><a href="' . esc_url( $r->meet_link ) . '" target="_blank" rel="noopener">Meet link</a>' : '' ) . '</td>'
						. '<td>' . ( $r->status === 'pending' ? 'Awaiting email confirmation' : 'Confirmed' ) . '</td></tr>';
				}
				$out .= '</tbody></table></div>';
			}
			$out .= '</div>';
		}
		return $out . '</div>';
	}
}

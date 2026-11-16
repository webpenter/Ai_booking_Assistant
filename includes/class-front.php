<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Booking widget on the listing page: an inline card, not a floating bubble (the site already has a floating chatbot).
 * Printed in the footer and moved by JS into the Houzez sidebar (desktop) or under the description (mobile),
 * so no theme files are edited. [houzez_booking_button] renders it in place instead.
 */
class HZB_Front {
	private static $printed = false;

	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'auto_print' ) );
		add_action( 'template_redirect', array( __CLASS__, 'confirm_page' ) );
		add_shortcode( 'houzez_booking_button', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'viewing_booking', array( __CLASS__, 'shortcode' ) );
	}

	public static function auto_print() {
		if ( is_singular( HZB_Listings::types() ) && HZB_Settings::get( 'placement' ) !== 'shortcode' ) {
			echo self::render( get_the_ID(), false );
		}
	}

	/**
	 * Email-confirmation landing page. A GET only shows a button; the booking is made by the POST, so mail
	 * scanners that pre-open links cannot confirm a viewing on the buyer's behalf.
	 */
	public static function confirm_page() {
		if ( empty( $_GET['hzb_confirm'] ) ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		$b = HZB_Bookings::by_token( sanitize_text_field( wp_unslash( $_GET['hzb_confirm'] ) ) );
		if ( ! $b ) {
			self::message_page( 'Link not valid', 'This confirmation link is not valid.' );
		}
		$listing = get_permalink( $b->listing_id );
		if ( $b->status === 'confirmed' ) {
			wp_safe_redirect( add_query_arg( array( 'hzb_booking' => $b->token, 'hzb_msg' => 'confirmed' ), $listing ) );
			exit;
		}
		if ( $b->status !== 'pending' ) {
			self::message_page( 'Viewing not available', 'This viewing was cancelled or has expired. You can request a new time from the listing page.', $listing );
		}
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			$r = HZB_Booking_Service::confirm_hold( $b );
			if ( is_wp_error( $r ) ) {
				$msg = $r->get_error_code() === 'hzb_taken' ? 'Sorry, that time was taken in the meantime. Please pick another time on the listing page.' : 'We could not confirm the viewing right now. Please try again in a minute.';
				self::message_page( 'Could not confirm', $msg, $listing );
			}
			HZB_Booking_Service::email_buyer( $r, 'booked' );
			HZB_Booking_Service::email_agent( $r, 'booked' );
			wp_safe_redirect( add_query_arg( array( 'hzb_booking' => $r->token, 'hzb_msg' => 'confirmed' ), $listing ) );
			exit;
		}
		$tz   = HZB_Calendar::timezone( (int) $b->agent_id );
		$when = HZB_Chat::format_ts( strtotime( $b->start_utc . ' UTC' ), $tz ) . ' (' . $tz->getName() . ')';
		$form = '<p><strong>' . esc_html( get_the_title( $b->listing_id ) ) . '</strong><br>' . esc_html( $when ) . '<br>' . ( $b->is_virtual ? 'Virtual viewing' : 'In-person viewing' ) . '</p>'
			. '<form method="post"><button type="submit" style="background:#00aeff;color:#fff;border:0;border-radius:6px;padding:12px 22px;font-size:16px;cursor:pointer">Confirm my viewing</button></form>';
		self::message_page( 'Confirm your viewing', $form, '', true );
	}

	private static function message_page( $title, $body, $back = '', $html = false ) {
		status_header( 200 );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $title ) . '</title></head>'
			. '<body style="font:16px/1.5 system-ui,sans-serif;background:#f6f8fa;margin:0"><div style="max-width:440px;margin:12vh auto;background:#fff;padding:28px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.08)">'
			. '<h1 style="font-size:22px;margin-top:0">' . esc_html( $title ) . '</h1>' . ( $html ? $body : '<p>' . esc_html( $body ) . '</p>' )
			. ( $back ? '<p><a href="' . esc_url( $back ) . '">Back to the listing</a></p>' : '' ) . '</div></body></html>';
		exit;
	}

	public static function shortcode( $atts ) {
		$a  = shortcode_atts( array( 'listing' => 0 ), $atts );
		$id = $a['listing'] ? (int) $a['listing'] : get_the_ID();
		return self::render( $id, true );
	}

	private static function render( $listing_id, $inline ) {
		$agent_id = HZB_Listings::is_listing( $listing_id ) ? HZB_Listings::resolve( $listing_id ) : 0;
		if ( self::$printed || ! $agent_id ) {
			return '';
		}
		self::$printed = true;
		wp_enqueue_style( 'hzb-booking', HZB_URL . 'assets/booking.css', array(), HZB_VERSION );
		wp_enqueue_script( 'hzb-booking', HZB_URL . 'assets/booking.js', array(), HZB_VERSION, true );

		$tok      = isset( $_GET['hzb_booking'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $_GET['hzb_booking'] ) : '';
		$b        = $tok ? HZB_Bookings::by_token( $tok ) : null;
		$managing = $b && (int) $b->listing_id === (int) $listing_id && $b->status === 'confirmed';
		if ( $managing ) {
			$agent_id = (int) $b->agent_id;
			$tz       = HZB_Calendar::timezone( $agent_id );
			$prefix   = ( isset( $_GET['hzb_msg'] ) && $_GET['hzb_msg'] === 'confirmed' ) ? "You're confirmed! A calendar invitation is on its way to your email. " : '';
			$greeting = $prefix . 'Hi! I found your viewing on ' . HZB_Chat::format_ts( strtotime( $b->start_utc . ' UTC' ), $tz ) . ' (' . $tz->getName() . '). Would you like to reschedule or cancel it?';
			$chips    = array( array( 'label' => 'Reschedule', 'text' => 'I want to reschedule' ), array( 'label' => HZB_Settings::t( 'Cancel viewing' ), 'text' => 'I want to cancel my viewing' ) );
		} else {
			$greeting = HZB_Chat::intro( $agent_id );
			$chips    = array(
				array( 'label' => HZB_Settings::t( 'In-person viewing' ), 'text' => 'I would like an in-person viewing' ),
				array( 'label' => 'Virtual tour', 'text' => 'I would like a virtual viewing' ),
				array( 'label' => 'See free times', 'text' => 'What times are available this week?' ),
			);
		}
		wp_localize_script( 'hzb-booking', 'HZB', array(
			'chatUrl'      => esc_url_raw( rest_url( 'houzez-booking/v1/chat' ) ),
			'listingId'    => (int) $listing_id,
			'bookingToken' => $managing ? $tok : '',
			'agentName'    => HZB_Owner::name( $agent_id ),
			'agentPhoto'   => HZB_Owner::photo( $agent_id ),
			'greeting'     => HZB_Settings::t( $greeting ),
			'chips'        => $chips,
			'inline'       => (bool) $inline,
			'placement'    => HZB_Settings::get( 'placement' ),
			'selectors'    => array_values( array_filter( array_merge( array( HZB_Settings::get( 'placement_selector' ) ), (array) apply_filters( 'hzb_placement_selectors', array( '#sidebar', '.listing-sidebar', '.sidebar-wrap', '.property-sidebar', '#secondary', 'aside.sidebar', 'aside' ) ) ) ) ),
		) );
		return '<div id="hzb-root" class="hzb" hidden></div>';
	}
}

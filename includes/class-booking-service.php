<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hold / confirm / reschedule / cancel. Every write re-verifies the slot under a per-agent lock.
 * New viewings are two-step: hold_viewing() reserves the slot (no Google event, no invitation),
 * confirm_hold() runs when the buyer clicks the emailed link and only then creates the event.
 */
class HZB_Booking_Service {
	/** Is $start (timestamp) still an offered slot right now? Fresh Google read, no cache. */
	public static function slot_is_free( $agent_id, $start, $ignore_booking_id = 0 ) {
		$tz    = HZB_Calendar::timezone( $agent_id );
		$day   = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$slots = HZB_Calendar::get_availability( $agent_id, $day, $day, $ignore_booking_id );
		if ( is_wp_error( $slots ) ) {
			return $slots;
		}
		foreach ( $slots as $s ) {
			if ( strtotime( $s['start'] ) === (int) $start ) {
				return true;
			}
		}
		return false;
	}

	private static function lock( $agent_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 8)', 'hzb_agent_' . (int) $agent_id ) ) === 1;
	}

	private static function unlock( $agent_id ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'hzb_agent_' . (int) $agent_id ) );
	}

	public static function confirm_url( $booking ) {
		return add_query_arg( 'hzb_confirm', $booking->token, get_permalink( $booking->listing_id ) );
	}

	/** Abuse limits: how many upcoming viewings one email or one IP may hold. */
	private static function check_limits( $email, $ip_hash ) {
		$max = (int) HZB_Settings::get( 'max_active' );
		if ( HZB_Bookings::active_count( 'buyer_email', strtolower( $email ) ) >= $max || ( $ip_hash && HZB_Bookings::active_count( 'ip_hash', $ip_hash ) >= $max ) ) {
			return new WP_Error( 'hzb_limit', 'Too many upcoming viewings.' );
		}
		return true;
	}

	/**
	 * Step 1 for a visitor: reserve the slot for hold_minutes. Creates no Google event.
	 *
	 * @param int $start Unix timestamp of the slot start (must be an offered slot).
	 * @return object|WP_Error The pending booking row.
	 */
	public static function hold_viewing( $agent_id, $start, $buyer_email, $listing_id, $is_virtual, $buyer_name = '', $buyer_tz = '', $ip_hash = '' ) {
		$buyer_email = strtolower( $buyer_email );
		if ( ! is_email( $buyer_email ) ) {
			return new WP_Error( 'hzb_email', 'Invalid email.' );
		}
		$ok = self::check_limits( $buyer_email, $ip_hash );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( ! self::lock( $agent_id ) ) {
			return new WP_Error( 'hzb_busy', 'Busy, try again.' );
		}
		try {
			$free = self::slot_is_free( $agent_id, $start );
			if ( $free !== true ) {
				return is_wp_error( $free ) ? $free : new WP_Error( 'hzb_taken', 'That time was just taken.' );
			}
			$b = HZB_Bookings::insert( array(
				'agent_id' => $agent_id, 'listing_id' => $listing_id, 'buyer_name' => $buyer_name, 'buyer_email' => $buyer_email,
				'is_virtual' => $is_virtual, 'start' => $start, 'end' => $start + (int) HZB_Settings::get( 'duration' ) * 60,
				'buyer_tz' => $buyer_tz, 'status' => 'pending', 'ip_hash' => $ip_hash,
			) );
			return $b ? $b : new WP_Error( 'hzb_db', 'Could not save the booking.' );
		} finally {
			self::unlock( $agent_id );
		}
	}

	/** Step 2: the buyer clicked the email link. Creates the Google event (and invitation) and confirms the row. */
	public static function confirm_hold( $booking ) {
		if ( ! $booking || $booking->status !== 'pending' ) {
			return new WP_Error( 'hzb_state', 'This booking cannot be confirmed.' );
		}
		$agent_id = (int) $booking->agent_id;
		if ( ! self::lock( $agent_id ) ) {
			return new WP_Error( 'hzb_busy', 'Busy, try again.' );
		}
		try {
			$start = strtotime( $booking->start_utc . ' UTC' );
			$free  = self::slot_is_free( $agent_id, $start, (int) $booking->id ); // Our own hold does not count against us.
			if ( $free !== true ) {
				if ( $free === false ) {
					HZB_Bookings::update( $booking->id, array( 'status' => 'cancelled' ) );
				}
				return is_wp_error( $free ) ? $free : new WP_Error( 'hzb_taken', 'That time was taken in the meantime.' );
			}
			$ev = self::create_event( $booking );
			if ( is_wp_error( $ev ) ) {
				return $ev;
			}
			HZB_Bookings::update( $booking->id, array( 'status' => 'confirmed', 'event_id' => $ev['id'], 'meet_link' => $ev['meet'], 'hold_until' => null ) );
			return HZB_Bookings::by_token( $booking->token );
		} finally {
			self::unlock( $agent_id );
		}
	}

	/** Direct booking without the email step (admin tools, tests). */
	public static function book_viewing( $agent_id, $start, $buyer_email, $listing_id, $is_virtual, $buyer_name = '', $buyer_tz = '' ) {
		$b = self::hold_viewing( $agent_id, $start, $buyer_email, $listing_id, $is_virtual, $buyer_name, $buyer_tz );
		return is_wp_error( $b ) ? $b : self::confirm_hold( $b );
	}

	private static function create_event( $b ) {
		$agent_id = (int) $b->agent_id;
		$ev       = HZB_Calendar::insert_event( $agent_id, array(
			'summary'        => 'Property viewing: ' . get_the_title( $b->listing_id ) . ( $b->is_virtual ? ' (virtual)' : '' ),
			'description'    => self::description( $b->listing_id, $b->buyer_name, $b->buyer_email, (bool) $b->is_virtual ),
			'location'       => $b->is_virtual ? '' : (string) get_post_meta( $b->listing_id, 'fave_property_address', true ),
			'start'          => strtotime( $b->start_utc . ' UTC' ),
			'end'            => strtotime( $b->end_utc . ' UTC' ),
			'tz'             => HZB_Calendar::timezone( $agent_id )->getName(),
			'virtual'        => (bool) $b->is_virtual,
			'attendee_email' => $b->buyer_email,
			'attendee_name'  => $b->buyer_name,
		) );
		return $ev;
	}

	public static function reschedule( $booking, $new_start ) {
		if ( ! self::lock( $booking->agent_id ) ) {
			return new WP_Error( 'hzb_busy', 'Busy, try again.' );
		}
		try {
			$free = self::slot_is_free( (int) $booking->agent_id, $new_start );
			if ( $free !== true ) {
				return is_wp_error( $free ) ? $free : new WP_Error( 'hzb_taken', 'That time was just taken.' );
			}
			$end = $new_start + (int) HZB_Settings::get( 'duration' ) * 60;
			$tz  = HZB_Calendar::timezone( (int) $booking->agent_id )->getName();
			$r   = HZB_Calendar::patch_event_time( (int) $booking->agent_id, $booking->event_id, $new_start, $end, $tz );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			HZB_Bookings::update( $booking->id, array( 'start_utc' => gmdate( 'Y-m-d H:i:s', $new_start ), 'end_utc' => gmdate( 'Y-m-d H:i:s', $end ) ) );
			return HZB_Bookings::by_token( $booking->token );
		} finally {
			self::unlock( $booking->agent_id );
		}
	}

	public static function cancel( $booking ) {
		if ( $booking->event_id !== '' ) {
			$r = HZB_Calendar::delete_event( (int) $booking->agent_id, $booking->event_id );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		HZB_Bookings::update( $booking->id, array( 'status' => 'cancelled' ) );
		return true;
	}

	private static function description( $listing_id, $name, $email, $virtual ) {
		return "Viewing requested through the website chat (buyer email confirmed).\n\nProperty: " . get_the_title( $listing_id ) . "\nAddress: " . get_post_meta( $listing_id, 'fave_property_address', true )
			. "\nListing: " . get_permalink( $listing_id ) . "\nBuyer: $name <$email>\nType: " . ( $virtual ? 'Virtual (Google Meet)' : 'In person' );
	}

	/* ---------- emails ---------- */

	private static function when_text( $booking ) {
		$tz = $booking->buyer_tz ? $booking->buyer_tz : HZB_Calendar::timezone( (int) $booking->agent_id )->getName();
		return HZB_Chat::format_ts( strtotime( $booking->start_utc . ' UTC' ), new DateTimeZone( $tz ) ) . ' (' . $tz . ')';
	}

	/** To the buyer. kind: hold (confirm link), booked, rescheduled, cancelled. */
	public static function email_buyer( $booking, $kind ) {
		$subject = array(
			'hold'        => 'Please confirm your viewing',
			'booked'      => 'Your viewing is confirmed',
			'rescheduled' => 'Your viewing was rescheduled',
			'cancelled'   => 'Your viewing was cancelled',
		);
		$lines = array( 'Hi ' . ( $booking->buyer_name ? $booking->buyer_name : 'there' ) . ',', '' );
		if ( $kind === 'hold' ) {
			$lines[] = 'You asked to view "' . get_the_title( $booking->listing_id ) . '" on ' . self::when_text( $booking ) . '.';
			$lines[] = 'We are holding that time for ' . (int) HZB_Settings::get( 'hold_minutes' ) . ' minutes. Open this link and press Confirm to book it:';
			$lines[] = self::confirm_url( $booking );
			$lines[] = '';
			$lines[] = 'If you did not ask for this, just ignore this email and nothing will be booked.';
		} else {
			$lines[] = $subject[ $kind ] . '.';
			$lines[] = '';
			$lines[] = 'Property: ' . get_the_title( $booking->listing_id );
			$lines[] = 'When: ' . self::when_text( $booking );
			if ( $kind !== 'cancelled' ) {
				$lines[] = 'Type: ' . ( $booking->is_virtual ? 'Virtual' . ( $booking->meet_link ? ' - ' . $booking->meet_link : '' ) : 'In person' );
				$lines[] = '';
				$lines[] = 'Need to change or cancel? ' . add_query_arg( 'hzb_booking', $booking->token, get_permalink( $booking->listing_id ) );
			}
		}
		wp_mail( $booking->buyer_email, $subject[ $kind ] . ': ' . get_the_title( $booking->listing_id ), implode( "\n", $lines ) );
	}

	/** To the agent, so they hear about changes even if they never open the calendar. */
	public static function email_agent( $booking, $kind ) {
		$agent_id = (int) $booking->agent_id;
		$to       = get_post_meta( $agent_id, 'fave_agent_email', true );
		if ( ! is_email( $to ) ) {
			$to = get_post_meta( $agent_id, HZB_Google_OAuth::META_EMAIL, true );
		}
		if ( ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}
		$label = array( 'booked' => 'New viewing booked', 'rescheduled' => 'Viewing rescheduled', 'cancelled' => 'Viewing cancelled' );
		$tz    = HZB_Calendar::timezone( $agent_id );
		$body  = $label[ $kind ] . "\n\nProperty: " . get_the_title( $booking->listing_id ) . "\nWhen: " . HZB_Chat::format_ts( strtotime( $booking->start_utc . ' UTC' ), $tz ) . ' (' . $tz->getName() . ")\nBuyer: " . $booking->buyer_name . ' <' . $booking->buyer_email . ">\nType: " . ( $booking->is_virtual ? 'Virtual (Google Meet)' : 'In person' ) . "\nListing: " . get_permalink( $booking->listing_id );
		wp_mail( $to, $label[ $kind ] . ': ' . get_the_title( $booking->listing_id ), $body );
	}
}

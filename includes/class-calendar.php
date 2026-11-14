<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Google Calendar access: freebusy reads, event writes, calendar timezone. */
class HZB_Calendar {
	const API     = 'https://www.googleapis.com/calendar/v3';
	const META_TZ = '_hzb_google_tz';

	/** Agent timezone from their Google calendar (cached 1 day in postmeta), else the site zone. */
	public static function timezone( $agent_id ) {
		$cached = get_post_meta( $agent_id, self::META_TZ, true );
		if ( is_array( $cached ) && $cached['at'] > time() - DAY_IN_SECONDS ) {
			return new DateTimeZone( $cached['tz'] );
		}
		$tz = null;
		$r  = self::request( $agent_id, 'GET', '/calendars/primary/events', array( 'maxResults' => 1, 'fields' => 'timeZone' ), null, true );
		if ( ! is_wp_error( $r ) && ! empty( $r['timeZone'] ) ) {
			try {
				$tz = new DateTimeZone( $r['timeZone'] );
			} catch ( Exception $e ) {
				$tz = null;
			}
		}
		if ( $tz ) {
			update_post_meta( $agent_id, self::META_TZ, array( 'tz' => $tz->getName(), 'at' => time() ) );
			return $tz;
		}
		if ( is_array( $cached ) ) { // Network hiccup: keep the last known zone so slot times stay consistent.
			return new DateTimeZone( $cached['tz'] );
		}
		return wp_timezone();
	}

	/** Authorised JSON request to the Calendar API. Returns decoded body (array) or WP_Error. */
	/** $retry only for idempotent reads: this machine's route to Google is sometimes slow. */
	public static function request( $agent_id, $method, $path, array $query = array(), $body = null, $retry = false ) {
		$token = HZB_Google_OAuth::access_token( $agent_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url  = self::API . $path . ( $query ? '?' . http_build_query( $query ) : '' );
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
		);
		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( $url, $args );
		if ( $retry && is_wp_error( $res ) ) {
			$res = wp_remote_request( $url, $args );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 300 ) {
			return new WP_Error( 'hzb_google_' . $code, 'Google Calendar: ' . ( $data['error']['message'] ?? $code ), array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/** @return array|WP_Error List of array{start,end}. Fails closed if the calendar cannot be read. */
	public static function busy_blocks( $agent_id, DateTimeInterface $from, DateTimeInterface $to ) {
		$r = self::request( $agent_id, 'POST', '/freeBusy', array(), array(
			'timeMin' => $from->format( 'c' ),
			'timeMax' => $to->format( 'c' ),
			'items'   => array( array( 'id' => 'primary' ) ),
		), true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$cal = $r['calendars']['primary'] ?? array();
		if ( ! empty( $cal['errors'] ) ) {
			return new WP_Error( 'hzb_freebusy', 'Google could not read the calendar: ' . ( $cal['errors'][0]['reason'] ?? 'unknown' ) );
		}
		return $cal['busy'] ?? array();
	}

	/** @return array|WP_Error Open slots (see get_agent_availability()). */
	public static function get_availability( $agent_id, $start_date, $end_date, $exclude_booking_id = 0 ) {
		$ok = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $start_date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $end_date );
		if ( ! $ok || $end_date < $start_date ) {
			return new WP_Error( 'hzb_range', 'Invalid date range.' );
		}
		$s   = HZB_Settings::all();
		$tz  = self::timezone( $agent_id );
		$now = new DateTimeImmutable( 'now', $tz );
		$end_date = min( $end_date, $now->modify( '+' . (int) $s['max_days_ahead'] . ' days' )->format( 'Y-m-d' ) );
		if ( $end_date < $start_date ) {
			return array();
		}
		$from = new DateTimeImmutable( $start_date . ' 00:00:00', $tz );
		$to   = new DateTimeImmutable( $end_date . ' 23:59:59', $tz );

		$busy = self::busy_blocks( $agent_id, $from, $to );
		if ( is_wp_error( $busy ) ) {
			return $busy;
		}
		// Bookings we hold locally (confirmed, or pending inside their hold) count as busy, in case Google is slightly behind.
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT start_utc, end_utc FROM ' . HZB_Bookings::table() . ' WHERE agent_id = %d AND id <> %d AND ' . HZB_Bookings::occupying_sql() . ' AND start_utc < %s AND end_utc > %s',
			$agent_id, $exclude_booking_id, gmdate( 'Y-m-d H:i:s', $to->getTimestamp() ), gmdate( 'Y-m-d H:i:s', $from->getTimestamp() )
		) );
		$per_day = array();
		foreach ( (array) $rows as $r ) {
			$busy[] = array( 'start' => $r->start_utc . ' UTC', 'end' => $r->end_utc . ' UTC' );
			$d      = ( new DateTimeImmutable( $r->start_utc . ' UTC' ) )->setTimezone( $tz )->format( 'Y-m-d' );
			$per_day[ $d ] = ( $per_day[ $d ] ?? 0 ) + 1;
		}
		$slots = HZB_Slot_Calculator::compute( $busy, $start_date, $end_date, $tz, $s, $now );
		// Daily cap: a day with enough chat bookings stops offering slots.
		return array_values( array_filter( $slots, function ( $x ) use ( $per_day, $tz, $s ) {
			$d = ( new DateTimeImmutable( $x['start'] ) )->setTimezone( $tz )->format( 'Y-m-d' );
			return ( $per_day[ $d ] ?? 0 ) < (int) $s['max_per_day'];
		} ) );
	}

	/** Create the viewing event. Returns array{id,meet} or WP_Error. */
	public static function insert_event( $agent_id, array $e ) {
		$body = array(
			'summary'     => $e['summary'],
			'description' => $e['description'],
			'location'    => $e['location'],
			'start'       => array( 'dateTime' => gmdate( 'c', $e['start'] ), 'timeZone' => $e['tz'] ),
			'end'         => array( 'dateTime' => gmdate( 'c', $e['end'] ), 'timeZone' => $e['tz'] ),
			'attendees'   => array( array( 'email' => $e['attendee_email'], 'displayName' => $e['attendee_name'] ) ),
			'reminders'   => array( 'useDefault' => true ),
		);
		if ( $e['virtual'] ) {
			$body['conferenceData'] = array( 'createRequest' => array(
				'requestId'             => wp_generate_uuid4(),
				'conferenceSolutionKey' => array( 'type' => 'hangoutsMeet' ),
			) );
		}
		$r = self::request( $agent_id, 'POST', '/calendars/primary/events', array( 'sendUpdates' => 'all', 'conferenceDataVersion' => 1 ), $body );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array( 'id' => $r['id'], 'meet' => $r['hangoutLink'] ?? '' );
	}

	public static function patch_event_time( $agent_id, $event_id, $start, $end, $tz ) {
		return self::request( $agent_id, 'PATCH', '/calendars/primary/events/' . rawurlencode( $event_id ), array( 'sendUpdates' => 'all' ), array(
			'start' => array( 'dateTime' => gmdate( 'c', $start ), 'timeZone' => $tz ),
			'end'   => array( 'dateTime' => gmdate( 'c', $end ), 'timeZone' => $tz ),
		) );
	}

	public static function delete_event( $agent_id, $event_id ) {
		$r = self::request( $agent_id, 'DELETE', '/calendars/primary/events/' . rawurlencode( $event_id ), array( 'sendUpdates' => 'all' ) );
		// 404/410: already gone, which is the outcome we wanted.
		if ( is_wp_error( $r ) && in_array( $r->get_error_code(), array( 'hzb_google_404', 'hzb_google_410' ), true ) ) {
			return array();
		}
		return $r;
	}
}

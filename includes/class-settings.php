<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin-wide settings. Google credentials/Groq key may also be set via constants in wp-config.php. */
class HZB_Settings {
	const OPTION = 'hzb_settings';

	public static function defaults() {
		return array(
			'google_client_id'     => '',
			'google_client_secret' => '',
			'groq_api_key'         => '',
			'hours_start'          => '09:00',
			'hours_end'            => '17:00',
			'days'                 => array( 1, 2, 3, 4, 5 ), // ISO weekdays, 1 = Monday.
			'duration'             => 30,  // Minutes.
			'buffer'               => 15,  // Minutes between viewings.
			'min_notice_hours'     => 4,
			'max_days_ahead'       => 30,
			'visible_days'         => 14,  // How many days ahead the chat offers.
			'max_active'           => 2,   // Upcoming viewings one email / one IP may hold.
			'max_per_day'          => 8,   // Viewings the agent accepts through the chat per day.
			'hold_minutes'         => 15,  // Slot hold while waiting for the email confirmation.
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$consts = array(
			'google_client_id'     => 'HZB_GOOGLE_CLIENT_ID',
			'google_client_secret' => 'HZB_GOOGLE_CLIENT_SECRET',
			'groq_api_key'         => 'HZB_GROQ_API_KEY',
		);
		if ( isset( $consts[ $key ] ) && defined( $consts[ $key ] ) ) {
			return constant( $consts[ $key ] );
		}
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function register() {
		register_setting( 'hzb_settings_group', self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ) {
		$d   = self::defaults();
		$old = self::all();
		$in  = is_array( $in ) ? $in : array();
		$out = array();
		foreach ( array( 'google_client_id', 'google_client_secret', 'groq_api_key' ) as $k ) {
			$v         = isset( $in[ $k ] ) ? trim( sanitize_text_field( $in[ $k ] ) ) : '';
			$out[ $k ] = $v !== '' ? $v : $old[ $k ]; // Blank keeps the stored secret.
		}
		foreach ( array( 'hours_start', 'hours_end' ) as $k ) {
			$out[ $k ] = ( isset( $in[ $k ] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $in[ $k ] ) ) ? $in[ $k ] : $d[ $k ];
		}
		if ( $out['hours_end'] <= $out['hours_start'] ) {
			$out['hours_start'] = $d['hours_start'];
			$out['hours_end']   = $d['hours_end'];
		}
		$days        = isset( $in['days'] ) && is_array( $in['days'] ) ? array_unique( array_map( 'intval', $in['days'] ) ) : array();
		$out['days'] = array_values( array_filter( $days, function ( $x ) { return $x >= 1 && $x <= 7; } ) );
		$out['duration']         = max( 10, min( 240, (int) ( $in['duration'] ?? $d['duration'] ) ) );
		$out['buffer']           = max( 0, min( 120, (int) ( $in['buffer'] ?? $d['buffer'] ) ) );
		$out['min_notice_hours'] = max( 0, min( 168, (int) ( $in['min_notice_hours'] ?? $d['min_notice_hours'] ) ) );
		$out['max_days_ahead']   = max( 1, min( 90, (int) ( $in['max_days_ahead'] ?? $d['max_days_ahead'] ) ) );
		$out['visible_days']     = max( 1, min( 90, (int) ( $in['visible_days'] ?? $d['visible_days'] ) ) );
		$out['max_active']       = max( 1, min( 20, (int) ( $in['max_active'] ?? $d['max_active'] ) ) );
		$out['max_per_day']      = max( 1, min( 100, (int) ( $in['max_per_day'] ?? $d['max_per_day'] ) ) );
		$out['hold_minutes']     = max( 5, min( 120, (int) ( $in['hold_minutes'] ?? $d['hold_minutes'] ) ) );
		return $out;
	}
}

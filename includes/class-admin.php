<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Settings page + "Google Calendar" box on the Houzez agent edit screen. */
class HZB_Admin {
	public static function agent_url( $agent_id ) {
		return admin_url( 'post.php?post=' . (int) $agent_id . '&action=edit' );
	}

	public static function menu() {
		add_options_page( 'AI Viewing Booking', 'AI Viewing Booking', 'manage_options', 'hzb-settings', array( __CLASS__, 'settings_page' ) );
		add_menu_page( 'Viewings', 'Viewings', 'manage_options', 'hzb-bookings', array( __CLASS__, 'bookings_page' ), 'dashicons-calendar-alt', 59 );
	}

	public static function meta_box() {
		add_meta_box( 'hzb_calendar', 'Viewing calendar (Google)', array( __CLASS__, 'render_box' ), 'houzez_agent', 'side' );
	}

	public static function render_box( $post ) {
		$msgs = array(
			'connected' => 'Google Calendar connected.',
			'denied'    => 'Access was declined on the Google screen.',
			'failed'    => 'Connecting failed. Check the Google credentials in settings and try again.',
		);
		$m = isset( $_GET['hzb_msg'] ) ? sanitize_key( $_GET['hzb_msg'] ) : '';
		if ( isset( $msgs[ $m ] ) ) {
			echo '<p><strong>' . esc_html( $msgs[ $m ] ) . '</strong></p>';
		}
		if ( HZB_Google_OAuth::is_connected( $post->ID ) ) {
			$email = get_post_meta( $post->ID, HZB_Google_OAuth::META_EMAIL, true );
			echo '<p>Connected' . ( $email ? ' as <code>' . esc_html( $email ) . '</code>' : '' ) . '.</p>';
			echo '<a class="button" href="' . esc_url( self::action_url( 'disconnect', $post->ID ) ) . '">Disconnect</a>';
		} elseif ( ! HZB_Settings::get( 'google_client_id' ) || ! HZB_Settings::get( 'google_client_secret' ) ) {
			echo '<p>Add the Google client ID and secret under Settings &rarr; AI Viewing Booking first.</p>';
		} else {
			echo '<p>Not connected.</p><a class="button button-primary" href="' . esc_url( self::action_url( 'connect', $post->ID ) ) . '">Connect Google Calendar</a>';
		}
	}

	private static function action_url( $action, $agent_id ) {
		return wp_nonce_url( admin_url( 'admin.php?hzb_action=' . $action . '&agent=' . (int) $agent_id ), 'hzb_' . $action . '_' . (int) $agent_id );
	}

	/** Runs on admin_init: connect redirects to Google, disconnect clears tokens. */
	public static function handle_actions() {
		if ( empty( $_GET['hzb_action'] ) || empty( $_GET['agent'] ) ) {
			return;
		}
		$action = sanitize_key( $_GET['hzb_action'] );
		$agent  = (int) $_GET['agent'];
		if ( ! in_array( $action, array( 'connect', 'disconnect' ), true ) || get_post_type( $agent ) !== 'houzez_agent' ) {
			return;
		}
		check_admin_referer( 'hzb_' . $action . '_' . $agent );
		if ( ! current_user_can( 'edit_post', $agent ) ) {
			wp_die( 'You may not edit this agent.', 403 );
		}
		if ( $action === 'connect' ) {
			wp_redirect( HZB_Google_OAuth::auth_url( $agent ) ); // External Google URL.
			exit;
		}
		HZB_Google_OAuth::disconnect( $agent );
		wp_safe_redirect( self::agent_url( $agent ) );
		exit;
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = HZB_Settings::all();
		$o    = HZB_Settings::OPTION;
		$days = array( 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun' );
		echo '<div class="wrap"><h1>AI Viewing Booking</h1><form method="post" action="options.php">';
		settings_fields( 'hzb_settings_group' );
		echo '<h2>Google</h2><table class="form-table">';
		echo '<tr><th>Redirect URI</th><td><code>' . esc_html( HZB_Google_OAuth::redirect_uri() ) . '</code><p class="description">Add this under Authorised redirect URIs on the OAuth client.</p></td></tr>';
		echo '<tr><th>Client ID</th><td><input class="regular-text" name="' . esc_attr( $o ) . '[google_client_id]" value="' . esc_attr( $s['google_client_id'] ) . '"></td></tr>';
		echo '<tr><th>Client secret</th><td><input class="regular-text" type="password" autocomplete="off" name="' . esc_attr( $o ) . '[google_client_secret]" placeholder="' . ( $s['google_client_secret'] ? 'Saved (leave blank to keep)' : '' ) . '"></td></tr>';
		echo '<tr><th>Groq API key</th><td><input class="regular-text" type="password" autocomplete="off" name="' . esc_attr( $o ) . '[groq_api_key]" placeholder="' . ( $s['groq_api_key'] ? 'Saved (leave blank to keep)' : '' ) . '"></td></tr>';
		echo '</table><h2>Viewing rules</h2><table class="form-table">';
		echo '<tr><th>Business hours</th><td><input type="time" name="' . esc_attr( $o ) . '[hours_start]" value="' . esc_attr( $s['hours_start'] ) . '"> to <input type="time" name="' . esc_attr( $o ) . '[hours_end]" value="' . esc_attr( $s['hours_end'] ) . '"></td></tr>';
		echo '<tr><th>Days</th><td>';
		foreach ( $days as $n => $label ) {
			echo '<label style="margin-right:10px"><input type="checkbox" name="' . esc_attr( $o ) . '[days][]" value="' . $n . '"' . checked( in_array( $n, $s['days'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</td></tr>';
		foreach ( array( 'duration' => 'Viewing length (min)', 'buffer' => 'Buffer between viewings (min)', 'min_notice_hours' => 'Minimum notice (hours)', 'max_days_ahead' => 'Bookable days ahead', 'visible_days' => 'Days the chat offers at a time', 'max_active' => 'Max upcoming viewings per email / per IP', 'max_per_day' => 'Max chat bookings per agent per day', 'hold_minutes' => 'Slot hold while awaiting email confirmation (min)' ) as $k => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="number" class="small-text" name="' . esc_attr( $o ) . '[' . $k . ']" value="' . (int) $s[ $k ] . '"></td></tr>';
		}
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	private static function status_label( $r ) {
		if ( $r->status === 'pending' && strtotime( $r->hold_until . ' UTC' ) < time() ) {
			return 'expired hold';
		}
		return $r->status === 'pending' ? 'awaiting email confirmation' : $r->status;
	}

	public static function bookings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>Viewings</h1><table class="widefat striped"><thead><tr><th>When (UTC)</th><th>Listing</th><th>Agent</th><th>Buyer</th><th>Type</th><th>Status</th><th>Confirm link (pending only)</th></tr></thead><tbody>';
		$rows = HZB_Bookings::recent();
		if ( ! $rows ) {
			echo '<tr><td colspan="7">No bookings yet.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r->start_utc ) . '</td><td>' . esc_html( get_the_title( $r->listing_id ) ) . '</td><td>' . esc_html( get_the_title( $r->agent_id ) ) . '</td><td>' . esc_html( $r->buyer_name . ' <' . $r->buyer_email . '>' ) . '</td><td>' . ( $r->is_virtual ? 'Virtual' : 'In person' ) . '</td><td>' . esc_html( $r->status ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

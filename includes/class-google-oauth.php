<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Google OAuth 2.0 web-server flow. Tokens are stored encrypted in postmeta on the houzez_agent post. */
class HZB_Google_OAuth {
	const META_TOKENS = '_hzb_google_tokens';
	const META_EMAIL  = '_hzb_google_email';
	const SCOPES      = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy openid email';

	public static function redirect_uri() {
		return rest_url( 'houzez-booking/v1/oauth/callback' );
	}

	public static function register_routes() {
		register_rest_route( 'houzez-booking/v1', '/oauth/callback', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'callback' ),
			'permission_callback' => '__return_true', // Authorised by the single-use `state` token below.
		) );
	}

	/** Build the consent URL. Caller must already have checked the user may edit this agent. */
	public static function auth_url( $agent_id, $return = 'admin' ) {
		$state = wp_generate_password( 32, false );
		set_transient( 'hzb_oauth_' . $state, array( 'agent' => (int) $agent_id, 'user' => get_current_user_id(), 'return' => $return === 'front' ? 'front' : 'admin' ), 10 * MINUTE_IN_SECONDS );
		return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array(
			'client_id'     => HZB_Settings::get( 'google_client_id' ),
			'redirect_uri'  => self::redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPES,
			'access_type'   => 'offline',
			'prompt'        => 'consent', // Forces a refresh_token on every connect.
			'state'         => $state,
		) );
	}

	public static function callback( WP_REST_Request $req ) {
		$state = sanitize_text_field( (string) $req->get_param( 'state' ) );
		$ctx   = $state ? get_transient( 'hzb_oauth_' . $state ) : false;
		if ( ! $ctx ) {
			return new WP_Error( 'hzb_state', 'Invalid or expired authorisation request. Start the connection again.', array( 'status' => 400 ) );
		}
		delete_transient( 'hzb_oauth_' . $state ); // Single use.
		$agent_id = (int) $ctx['agent'];
		$back     = ( $ctx['return'] ?? 'admin' ) === 'front' ? add_query_arg( 'agent', $agent_id, HZB_Dashboard::page_url() ) : HZB_Admin::agent_url( $agent_id );

		if ( $req->get_param( 'error' ) ) {
			return self::go( add_query_arg( 'hzb_msg', 'denied', $back ) );
		}
		$code = (string) $req->get_param( 'code' );
		if ( $code === '' || ! ( HZB_Dashboard::can_manage( (int) $ctx['user'], $agent_id ) || user_can( (int) $ctx['user'], 'edit_post', $agent_id ) ) ) {
			return self::go( add_query_arg( 'hzb_msg', 'failed', $back ) );
		}

		$tok = self::token_request( array(
			'code'         => $code,
			'grant_type'   => 'authorization_code',
			'redirect_uri' => self::redirect_uri(),
		) );
		if ( is_wp_error( $tok ) || empty( $tok['refresh_token'] ) ) {
			return self::go( add_query_arg( 'hzb_msg', 'failed', $back ) );
		}
		self::store( $agent_id, $tok );
		update_post_meta( $agent_id, self::META_EMAIL, self::email_from_id_token( $tok ) );
		return self::go( add_query_arg( 'hzb_msg', 'connected', $back ) );
	}

	private static function go( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	public static function is_connected( $agent_id ) {
		return (bool) get_post_meta( $agent_id, self::META_TOKENS, true );
	}

	public static function disconnect( $agent_id ) {
		$t = self::load( $agent_id );
		if ( $t && ! empty( $t['refresh_token'] ) ) {
			wp_remote_post( 'https://oauth2.googleapis.com/revoke', array( 'timeout' => 10, 'body' => array( 'token' => $t['refresh_token'] ) ) );
		}
		delete_post_meta( $agent_id, self::META_TOKENS );
		delete_post_meta( $agent_id, self::META_EMAIL );
	}

	/** @return string|WP_Error A valid access token, refreshed silently when within 60s of expiry. */
	public static function access_token( $agent_id ) {
		$t = self::load( $agent_id );
		if ( ! $t ) {
			return new WP_Error( 'hzb_not_connected', 'Agent has not connected a Google Calendar.' );
		}
		if ( $t['expires_at'] - 60 > time() ) {
			return $t['access_token'];
		}
		$new = self::token_request( array( 'refresh_token' => $t['refresh_token'], 'grant_type' => 'refresh_token' ) );
		if ( is_wp_error( $new ) ) {
			if ( $new->get_error_code() === 'invalid_grant' ) { // Revoked or expired by Google: force reconnect.
				delete_post_meta( $agent_id, self::META_TOKENS );
			}
			return $new;
		}
		$new['refresh_token'] = $t['refresh_token']; // Google omits it on refresh.
		self::store( $agent_id, $new );
		return $new['access_token'];
	}

	private static function token_request( array $params ) {
		$res = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'timeout' => 15,
			'body'    => $params + array(
				'client_id'     => HZB_Settings::get( 'google_client_id' ),
				'client_secret' => HZB_Settings::get( 'google_client_secret' ),
			),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( wp_remote_retrieve_response_code( $res ) !== 200 || empty( $body['access_token'] ) ) {
			$code = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : 'hzb_token';
			return new WP_Error( $code, 'Google token request failed: ' . ( $body['error_description'] ?? $code ) );
		}
		return $body;
	}

	private static function store( $agent_id, array $tok ) {
		$data = array(
			'access_token'  => $tok['access_token'],
			'refresh_token' => $tok['refresh_token'],
			'expires_at'    => time() + (int) ( $tok['expires_in'] ?? 3600 ),
		);
		update_post_meta( $agent_id, self::META_TOKENS, wp_slash( HZB_Crypto::encrypt( wp_json_encode( $data ) ) ) );
	}

	private static function load( $agent_id ) {
		$raw = get_post_meta( $agent_id, self::META_TOKENS, true );
		if ( ! $raw ) {
			return null;
		}
		$plain = HZB_Crypto::decrypt( $raw );
		$data  = $plain ? json_decode( $plain, true ) : null;
		return ( is_array( $data ) && ! empty( $data['refresh_token'] ) ) ? $data : null;
	}

	/** Display-only email from the id_token payload (received directly from Google over TLS). */
	private static function email_from_id_token( array $tok ) {
		if ( empty( $tok['id_token'] ) ) {
			return '';
		}
		$parts   = explode( '.', $tok['id_token'] );
		$payload = isset( $parts[1] ) ? json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ) : null;
		return is_array( $payload ) && ! empty( $payload['email'] ) ? sanitize_email( $payload['email'] ) : '';
	}
}

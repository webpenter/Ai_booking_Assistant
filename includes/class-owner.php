<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A "calendar owner" is always a post ID, so tokens, bookings and the chat work the same for every theme:
 *  - themes with agent profiles (Houzez houzez_agent, WPResidence estate_agent, ...): the agent post itself;
 *  - themes where the listing author is the host/owner (Listeo, Homey, generic): a hidden hzb_owner post that
 *    represents one WordPress user.
 */
class HZB_Owner {
	const CPT = 'hzb_owner';

	public static function register_cpt() {
		register_post_type( self::CPT, array(
			'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'supports' => array( 'title' ),
			'label'  => 'Calendar owners', 'rewrite' => false, 'query_var' => false,
		) );
	}

	/** Every post type that can own a calendar (theme agent types, our user-owner type, and an optional custom one). */
	public static function types() {
		$types = array( 'houzez_agent', 'estate_agent', self::CPT );
		$custom = HZB_Settings::get( 'agent_post_type' );
		if ( $custom ) {
			$types[] = sanitize_key( $custom );
		}
		return array_values( array_unique( (array) apply_filters( 'hzb_owner_post_types', $types ) ) );
	}

	/** Agent-profile types a person edits in wp-admin (excludes our own hidden user type). */
	public static function profile_types() {
		return array_values( array_filter( self::types(), function ( $t ) { return $t !== self::CPT && post_type_exists( $t ); } ) );
	}

	public static function is_owner( $id ) {
		return $id > 0 && in_array( get_post_type( $id ), self::types(), true );
	}

	/** The hzb_owner post that stands for a WordPress user. Created on demand. */
	public static function for_user( $user_id, $create = true ) {
		$found = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_hzb_user', 'meta_value' => (int) $user_id ) );
		if ( $found ) {
			return (int) $found[0];
		}
		$u = get_userdata( $user_id );
		if ( ! $create || ! $u ) {
			return 0;
		}
		$id = wp_insert_post( array( 'post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => $u->display_name, 'post_author' => $user_id ) );
		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_hzb_user', (int) $user_id );
			return (int) $id;
		}
		return 0;
	}

	public static function user_id( $owner_id ) {
		return (int) get_post_meta( $owner_id, '_hzb_user', true );
	}

	public static function name( $owner_id ) {
		if ( get_post_type( $owner_id ) === self::CPT && ( $u = get_userdata( self::user_id( $owner_id ) ) ) ) {
			return $u->display_name; // Follows the user's current name.
		}
		return get_the_title( $owner_id );
	}

	public static function photo( $owner_id ) {
		if ( get_post_type( $owner_id ) === self::CPT ) {
			return (string) get_avatar_url( self::user_id( $owner_id ), array( 'size' => 96 ) );
		}
		return (string) get_the_post_thumbnail_url( $owner_id, 'thumbnail' );
	}

	/** Where to send the owner's booking notifications. */
	public static function email( $owner_id ) {
		if ( get_post_type( $owner_id ) === self::CPT && ( $u = get_userdata( self::user_id( $owner_id ) ) ) ) {
			return $u->user_email;
		}
		foreach ( array( 'fave_agent_email', 'agent_email', 'REAL_HOMES_agent_email', HZB_Google_OAuth::META_EMAIL ) as $k ) {
			$v = get_post_meta( $owner_id, $k, true );
			if ( is_email( $v ) ) {
				return $v;
			}
		}
		return get_option( 'admin_email' );
	}

	/** Agent-profile post matching a user's email address, for themes that do not link users to agent posts. */
	public static function profile_by_email( $email ) {
		foreach ( self::profile_types() as $type ) {
			foreach ( array( 'fave_agent_email', 'agent_email', 'REAL_HOMES_agent_email' ) as $k ) {
				$f = get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'draft', 'pending' ), 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => $k, 'meta_value' => $email ) );
				if ( $f ) {
					return (int) $f[0];
				}
			}
		}
		return 0;
	}
}

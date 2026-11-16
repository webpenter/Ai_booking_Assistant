<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme adapter: which post types are bookable listings, whose calendar serves a listing, and what facts to tell
 * the assistant. Houzez is the tested adapter. WPResidence uses keys from its documentation. Every other theme
 * (Listeo, Homey, RealHomes, ...) falls back to "the listing's author owns the calendar", which needs no theme
 * specific keys. Anything can be overridden in settings or with the hzb_* filters.
 */
class HZB_Listings {
	/** Theme family from the active theme (and parent) slug. */
	public static function family() {
		$slugs = array();
		$t     = wp_get_theme();
		while ( $t && $t->exists() ) {
			$slugs[] = strtolower( $t->get_stylesheet() ) . ' ' . strtolower( $t->get_template() );
			$t       = $t->parent();
		}
		$s = implode( ' ', $slugs );
		foreach ( array( 'houzez' => 'houzez', 'wpresidence' => 'wpresidence', 'wp-residence' => 'wpresidence', 'listeo' => 'listeo', 'homey' => 'homey', 'realhomes' => 'realhomes', 'real-homes' => 'realhomes' ) as $needle => $fam ) {
			if ( strpos( $s, $needle ) !== false ) {
				return (string) apply_filters( 'hzb_theme_family', $fam );
			}
		}
		return (string) apply_filters( 'hzb_theme_family', 'generic' );
	}

	/** Per-family settings. 'agent_meta' = listing meta holding agent post ID(s); keys are facts the assistant may quote. */
	public static function adapters() {
		return (array) apply_filters( 'hzb_adapters', array(
			'houzez'      => array( 'types' => array( 'property' ), 'agent_meta' => 'fave_agents', 'author_agent_user_meta' => 'fave_author_agent_id', 'author_fallback' => false,
				'facts' => array( 'address' => 'fave_property_address', 'price' => 'fave_property_price', 'bedrooms' => 'fave_property_bedrooms', 'bathrooms' => 'fave_property_bathrooms', 'size' => 'fave_property_size' ) ),
			'wpresidence' => array( 'types' => array( 'estate_property' ), 'agent_meta' => 'property_agent', 'author_fallback' => true,
				'facts' => array( 'address' => 'property_address', 'price' => 'property_price', 'bedrooms' => 'property_bedrooms', 'bathrooms' => 'property_bathrooms', 'size' => 'property_size' ) ),
			'listeo'      => array( 'types' => array( 'listing' ), 'author_fallback' => true, 'facts' => array( 'address' => '_friendly_address' ) ),
			'homey'       => array( 'types' => array( 'listing' ), 'author_fallback' => true, 'facts' => array() ),
			'realhomes'   => array( 'types' => array( 'property' ), 'author_fallback' => true,
				'facts' => array( 'address' => 'REAL_HOMES_property_address', 'price' => 'REAL_HOMES_property_price', 'bedrooms' => 'REAL_HOMES_property_bedrooms', 'bathrooms' => 'REAL_HOMES_property_bathrooms', 'size' => 'REAL_HOMES_property_size' ) ),
			'generic'     => array( 'types' => array( 'property', 'estate_property', 'listing' ), 'author_fallback' => true, 'facts' => array() ),
		) );
	}

	public static function adapter() {
		$all = self::adapters();
		$fam = self::family();
		return isset( $all[ $fam ] ) ? $all[ $fam ] : $all['generic'];
	}

	/** Post types that get the booking widget: the setting if chosen, otherwise the adapter's (and generic) types that exist. */
	public static function types() {
		$chosen = HZB_Settings::get( 'listing_types' );
		if ( is_array( $chosen ) && $chosen ) {
			return array_values( array_filter( array_map( 'sanitize_key', $chosen ), 'post_type_exists' ) );
		}
		$types = self::adapter()['types'];
		if ( self::family() === 'generic' ) {
			$types = array( 'property', 'estate_property', 'listing' );
		}
		return array_values( array_filter( (array) apply_filters( 'hzb_listing_types', $types ), 'post_type_exists' ) );
	}

	public static function is_listing( $id ) {
		return $id > 0 && in_array( get_post_type( $id ), self::types(), true ) && get_post_status( $id ) === 'publish';
	}

	/** Candidate calendar owners for a listing, best first. Only the listing's own people, never another agent. */
	public static function candidates( $listing_id ) {
		$ad  = self::adapter();
		$ids = array();
		$metas = array_filter( array( isset( $ad['agent_meta'] ) ? $ad['agent_meta'] : '', HZB_Settings::get( 'agent_meta_key' ) ) );
		foreach ( $metas as $m ) {
			foreach ( (array) get_post_meta( $listing_id, $m ) as $v ) {
				foreach ( (array) maybe_unserialize( $v ) as $one ) {
					$ids[] = (int) $one;
				}
			}
		}
		$author = (int) get_post_field( 'post_author', $listing_id );
		if ( isset( $ad['author_agent_user_meta'] ) && ! array_filter( $ids ) && get_post_meta( $listing_id, 'fave_agent_display_option', true ) === 'author_info' ) {
			$ids[] = (int) get_user_meta( $author, $ad['author_agent_user_meta'], true ); // Listing shows its author's agent profile.
		}
		$ids = array_values( array_filter( $ids, array( 'HZB_Owner', 'is_owner' ) ) );
		if ( ! empty( $ad['author_fallback'] ) && $author ) {
			$own = HZB_Owner::for_user( $author, false ); // The author's own calendar, if they connected one.
			if ( $own ) {
				$ids[] = $own;
			}
		}
		return (array) apply_filters( 'hzb_listing_owners', $ids, $listing_id );
	}

	/** The connected calendar owner for a listing, or 0 (then no widget is shown). */
	public static function resolve( $listing_id ) {
		foreach ( self::candidates( $listing_id ) as $id ) {
			if ( HZB_Google_OAuth::is_connected( $id ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	private static function fact( $listing_id, $key, $extra = array() ) {
		$map  = self::adapter()['facts'];
		$keys = array_merge( isset( $map[ $key ] ) ? array( $map[ $key ] ) : array(), $extra );
		foreach ( $keys as $k ) {
			$v = get_post_meta( $listing_id, $k, true );
			if ( is_scalar( $v ) && trim( (string) $v ) !== '' ) {
				return trim( (string) $v );
			}
		}
		return '';
	}

	public static function address( $listing_id ) {
		return self::fact( $listing_id, 'address', array( 'fave_property_address', 'property_address', '_friendly_address', '_address', 'REAL_HOMES_property_address' ) );
	}

	/** One line of facts for the assistant prompt; only what the listing actually has. */
	public static function facts_text( $listing_id ) {
		$parts = array();
		foreach ( array( 'price' => 'price', 'bedrooms' => 'bedrooms', 'bathrooms' => 'bathrooms', 'size' => 'size' ) as $label => $key ) {
			$v = self::fact( $listing_id, $key );
			if ( $v !== '' ) {
				$parts[] = $label . ' ' . $v;
			}
		}
		return $parts ? implode( ', ', $parts ) : 'none stored';
	}
}

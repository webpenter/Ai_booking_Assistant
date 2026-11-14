<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own record of bookings (independent of Google) in {prefix}hzb_bookings. Times stored in UTC.
 * Status: pending (slot held until hold_until, waiting for the buyer's email confirmation),
 * confirmed (Google event exists), cancelled.
 */
class HZB_Bookings {
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'hzb_bookings';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(32) NOT NULL,
			agent_id bigint(20) unsigned NOT NULL,
			listing_id bigint(20) unsigned NOT NULL,
			buyer_name varchar(120) NOT NULL DEFAULT '',
			buyer_email varchar(190) NOT NULL,
			is_virtual tinyint(1) NOT NULL DEFAULT 0,
			start_utc datetime NOT NULL,
			end_utc datetime NOT NULL,
			buyer_tz varchar(64) NOT NULL DEFAULT '',
			event_id varchar(190) NOT NULL DEFAULT '',
			meet_link varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'confirmed',
			hold_until datetime DEFAULT NULL,
			ip_hash char(32) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY agent_time (agent_id, start_utc, status),
			KEY buyer_email (buyer_email),
			KEY ip_hash (ip_hash)
		) $charset;" );
	}

	/** SQL fragment: rows that currently occupy a slot (confirmed, or pending and still inside the hold window). */
	public static function occupying_sql() {
		return "(status = 'confirmed' OR (status = 'pending' AND hold_until > UTC_TIMESTAMP()))";
	}

	public static function insert( array $d ) {
		global $wpdb;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$token  = bin2hex( random_bytes( 16 ) );
		$status = isset( $d['status'] ) ? $d['status'] : 'confirmed';
		$ok     = $wpdb->insert( self::table(), array(
			'token'       => $token,
			'agent_id'    => (int) $d['agent_id'],
			'listing_id'  => (int) $d['listing_id'],
			'buyer_name'  => $d['buyer_name'],
			'buyer_email' => $d['buyer_email'],
			'is_virtual'  => $d['is_virtual'] ? 1 : 0,
			'start_utc'   => gmdate( 'Y-m-d H:i:s', $d['start'] ),
			'end_utc'     => gmdate( 'Y-m-d H:i:s', $d['end'] ),
			'buyer_tz'    => $d['buyer_tz'],
			'event_id'    => isset( $d['event_id'] ) ? $d['event_id'] : '',
			'meet_link'   => isset( $d['meet_link'] ) ? $d['meet_link'] : '',
			'status'      => $status,
			'hold_until'  => $status === 'pending' ? gmdate( 'Y-m-d H:i:s', time() + (int) HZB_Settings::get( 'hold_minutes' ) * 60 ) : null,
			'ip_hash'     => isset( $d['ip_hash'] ) ? $d['ip_hash'] : '',
			'created_at'  => $now,
			'updated_at'  => $now,
		) );
		return $ok ? self::by_token( $token ) : null;
	}

	public static function by_token( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $token ) );
	}

	public static function update( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		return $wpdb->update( self::table(), $fields, array( 'id' => (int) $id ) );
	}

	/** Upcoming viewings still alive for a buyer email or IP hash (abuse limit). */
	public static function active_count( $column, $value ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'buyer_email', 'ip_hash' ), true ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . " WHERE $column = %s AND start_utc > UTC_TIMESTAMP() AND " . self::occupying_sql(),
			$value
		) );
	}

	public static function recent( $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY start_utc DESC LIMIT %d', $limit ) );
	}
}

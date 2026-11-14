<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Symmetric encryption for stored OAuth tokens (libsodium secretbox, key derived from WP salts). */
class HZB_Crypto {
	private static function key() {
		$salt = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		return sodium_crypto_generichash( 'hzb-token-key|' . $salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( $plain ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, self::key() ) );
	}

	/** @return string|false false if the payload is invalid or the key changed. */
	public static function decrypt( $payload ) {
		$raw = base64_decode( (string) $payload, true );
		if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
	}
}

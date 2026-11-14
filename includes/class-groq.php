<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Minimal Groq chat client (OpenAI-compatible). JSON mode, short timeout, one retry, never throws. */
class HZB_Groq {
	const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

	public static function key() {
		$k = HZB_Settings::get( 'groq_api_key' );
		if ( ! $k && defined( 'GROQ_API_KEY' ) ) {
			$k = GROQ_API_KEY;
		}
		return (string) $k;
	}

	public static function model() {
		if ( defined( 'HZB_GROQ_MODEL' ) ) {
			return HZB_GROQ_MODEL;
		}
		return defined( 'GROQ_MODEL' ) ? GROQ_MODEL : 'openai/gpt-oss-120b';
	}

	/** @return array|WP_Error Decoded JSON object returned by the model. */
	public static function complete_json( array $messages ) {
		$key = self::key();
		if ( $key === '' ) {
			return new WP_Error( 'hzb_no_groq', 'No Groq API key configured.' );
		}
		$last = null;
		for ( $try = 0; $try < 2; $try++ ) {
			$payload = array(
				'model'           => self::model(),
				'messages'        => $messages,
				'temperature'     => 0.2,
				'max_tokens'      => 1200, // Headroom for reasoning models.
				'response_format' => array( 'type' => 'json_object' ),
			);
			if ( strpos( $payload['model'], 'gpt-oss' ) !== false ) {
				$payload['reasoning_effort'] = 'low';
			}
			$res = wp_remote_post( self::ENDPOINT, array(
				'timeout' => 25,
				'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			) );
			if ( is_wp_error( $res ) ) {
				$last = $res;
				continue;
			}
			$code = wp_remote_retrieve_response_code( $res );
			if ( $code === 429 || $code >= 500 ) {
				$last = new WP_Error( 'hzb_groq_' . $code, 'Groq unavailable.' );
				usleep( 400000 );
				continue;
			}
			$body    = json_decode( wp_remote_retrieve_body( $res ), true );
			$content = $body['choices'][0]['message']['content'] ?? '';
			$json    = $code === 200 ? json_decode( $content, true ) : null;
			if ( is_array( $json ) ) {
				return $json;
			}
			$last = new WP_Error( 'hzb_groq_bad', 'Unusable Groq response (HTTP ' . $code . '): ' . substr( wp_remote_retrieve_body( $res ), 0, 300 ) );
			if ( $code >= 400 && $code < 500 ) {
				break; // Not retryable (bad key, unknown model).
			}
		}
		$last = $last ? $last : new WP_Error( 'hzb_groq', 'Groq failed.' );
		update_option( 'hzb_last_groq_error', gmdate( 'c' ) . ' ' . $last->get_error_code() . ' ' . $last->get_error_message(), false ); // For diagnosing "trouble thinking" reports.
		return $last;
	}
}

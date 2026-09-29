<?php
/**
 * Signed calls to ChatPuff's Integration API.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Every call is signed with the connection's private key (api-contract.md §5).
 */
final class Api_Client {

	public const API_CONTRACT_VERSION = '1';
	public const PAIRING_KEY_ID       = 'pairing';

	/**
	 * ChatPuff's API address.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Seconds to add to the local clock, learned from ChatPuff's Date header.
	 *
	 * @var int
	 */
	private $clock_offset = 0;

	/**
	 * Developers point the plugin at a local ChatPuff with CHATPUFF_API_URL in wp-config.php; shops
	 * always use the production address.
	 *
	 * @param string|null $base_url another address, for tests.
	 */
	public function __construct( ?string $base_url = null ) {
		if ( null === $base_url ) {
			$base_url = defined( 'CHATPUFF_API_URL' ) ? (string) constant( 'CHATPUFF_API_URL' ) : 'https://api.chatpuff.com';
		}
		$this->base_url = rtrim( $base_url, '/' );
	}

	/**
	 * ChatPuff's API address, which also serves the storefront widget's and the inbox's scripts.
	 */
	public function base_url(): string {
		return $this->base_url;
	}

	/**
	 * A signed call.
	 *
	 * @param string                    $method     the HTTP method.
	 * @param string                    $path       the path and query string.
	 * @param array<string, mixed>|null $body       the JSON body.
	 * @param string                    $key_id     the key ID, or "pairing".
	 * @param string                    $secret_key the base64 private key.
	 *
	 * @return array<string, mixed> the decoded response body
	 *
	 * @throws Api_Exception When ChatPuff cannot be reached or answers with an error.
	 */
	public function send( string $method, string $path, ?array $body, string $key_id, string $secret_key ): array {
		$response = $this->exchange( $method, $path, $body, $key_id, $secret_key );

		// A wrong server clock: correct it from ChatPuff's Date header and try once more.
		if ( 401 === $response['status'] && 'timestamp_out_of_range' === self::problem_code( $response ) && null !== $response['date'] ) {
			$this->clock_offset = $response['date'] - time();
			$response           = $this->exchange( $method, $path, $body, $key_id, $secret_key );
		}
		if ( $response['status'] >= 200 && $response['status'] < 300 ) {
			return $response['body'];
		}

		// Codes for the plugin's own messages, never printed as they are.
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new Api_Exception( self::problem_code( $response ), $response['status'], is_string( $response['body']['reference'] ?? null ) ? $response['body']['reference'] : '' );
	}

	/**
	 * The string that is signed.
	 *
	 * @param string $method         the HTTP method.
	 * @param string $path_and_query the path and query string exactly as sent.
	 * @param string $timestamp      Unix time.
	 * @param string $nonce          32 hex characters.
	 * @param string $body           the raw body.
	 */
	public static function signing_string( string $method, string $path_and_query, string $timestamp, string $nonce, string $body ): string {
		return implode( "\n", array( 'CHATPUFF-ED25519-V1', strtoupper( $method ), $path_and_query, $timestamp, $nonce, hash( 'sha256', $body ) ) );
	}

	/**
	 * The Chatpuff-Client header, for ChatPuff's logs and support only.
	 */
	public static function client_header(): string {
		return sprintf( 'woocommerce-plugin/%s (WordPress %s; WooCommerce %s; PHP %s)', CHATPUFF_VERSION, get_bloginfo( 'version' ), Plugin::woocommerce_version(), PHP_VERSION );
	}

	/**
	 * The problem code of an answer.
	 *
	 * @param array{status: int, body: array<string, mixed>, date: int|null} $response the answer.
	 */
	private static function problem_code( array $response ): string {
		return is_string( $response['body']['code'] ?? null ) ? $response['body']['code'] : 'http_' . $response['status'];
	}

	/**
	 * One request.
	 *
	 * @param string                    $method     the HTTP method.
	 * @param string                    $path       the path and query string.
	 * @param array<string, mixed>|null $body       the JSON body.
	 * @param string                    $key_id     the key ID.
	 * @param string                    $secret_key the base64 private key.
	 *
	 * @return array{status: int, body: array<string, mixed>, date: int|null}
	 *
	 * @throws Api_Exception When ChatPuff cannot be reached.
	 */
	private function exchange( string $method, string $path, ?array $body, string $key_id, string $secret_key ): array {
		$content   = null === $body ? '' : (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$timestamp = (string) ( time() + $this->clock_offset );
		$nonce     = bin2hex( random_bytes( 16 ) );
		$signature = Crypto::sign( $secret_key, self::signing_string( $method, $path, $timestamp, $nonce, $content ) );

		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => 15,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Accept'             => 'application/json',
				'Content-Type'       => 'application/json',
				'Chatpuff-Key-Id'    => $key_id,
				'Chatpuff-Timestamp' => $timestamp,
				'Chatpuff-Nonce'     => $nonce,
				'Chatpuff-Signature' => $signature,
				'Chatpuff-Client'    => self::client_header(),
			),
		);
		if ( '' !== $content ) {
			$args['body'] = $content;
		}

		$response = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new Api_Exception( 'network_error', 0 );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 0 === $status ) {
			throw new Api_Exception( 'network_error', 0 );
		}

		$date_header = wp_remote_retrieve_header( $response, 'date' );
		$date        = is_string( $date_header ) && '' !== $date_header ? strtotime( $date_header ) : false;
		$raw         = wp_remote_retrieve_body( $response );
		$decoded     = '' === $raw ? array() : json_decode( $raw, true );

		return array(
			'status' => $status,
			'body'   => is_array( $decoded ) ? $decoded : array(),
			'date'   => false === $date ? null : $date,
		);
	}
}

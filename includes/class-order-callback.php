<?php
/**
 * ChatPuff's signed question about an order.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * ChatPuff asks about an order so that a customer can prove it is theirs (api-contract.md §7.7).
 * Only ChatPuff may ask: the call is signed with ChatPuff's own key, which the plugin received when
 * the site connected, within five minutes, and never twice. The answer names the order, its
 * customer and the email address on it, nothing more.
 */
final class Order_Callback {

	public const CAPABILITY      = 'order_verification';
	public const SCHEME          = 'CHATPUFF-SAAS-ED25519-V1';
	public const NONCES          = 'chatpuff_saas_nonces';
	private const CLOCK_WINDOW   = 300;
	private const NONCE_LIFETIME = 600;
	private const NONCE_LIMIT    = 500;

	/**
	 * The ChatPuff API client, to fetch ChatPuff's current keys.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Uses this client to ask ChatPuff for its keys.
	 *
	 * @param Api_Client $api the client.
	 */
	public function __construct( Api_Client $api ) {
		$this->api = $api;
	}

	/**
	 * Whether ChatPuff signed this call, within the clock window, and for the first time.
	 *
	 * @param array<string, string> $headers        Chatpuff-Key-Id, Chatpuff-Timestamp, Chatpuff-Nonce, Chatpuff-Signature.
	 * @param string                $method         the HTTP method.
	 * @param string                $path_and_query the request target exactly as sent.
	 */
	public function is_signed_by_chatpuff( array $headers, string $method, string $path_and_query ): bool {
		$connection = Settings::connection();
		$key_id     = $headers['Chatpuff-Key-Id'] ?? '';
		$timestamp  = $headers['Chatpuff-Timestamp'] ?? '';
		$nonce      = $headers['Chatpuff-Nonce'] ?? '';
		$signature  = $headers['Chatpuff-Signature'] ?? '';
		if ( null === $connection || '' === $key_id || 1 !== preg_match( '/^\d{1,12}$/', $timestamp ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', $nonce ) || '' === $signature ) {
			return false;
		}
		if ( abs( time() - (int) $timestamp ) > self::CLOCK_WINDOW ) {
			return false;
		}
		$public_key = $this->public_key( $connection, $key_id );
		$message    = implode( "\n", array( self::SCHEME, strtoupper( $method ), $path_and_query, $timestamp, $nonce, hash( 'sha256', '' ) ) );
		if ( null === $public_key || ! Crypto::verify( $public_key, $message, $signature ) ) {
			return false;
		}

		return self::first_use( $nonce );
	}

	/**
	 * One of this site's orders by the number its customer sees.
	 *
	 * @param string $reference what the customer typed, upper-cased by ChatPuff.
	 *
	 * @return array{id: string, reference: string, customer_id: string|null, email: string, language: string|null}|null
	 */
	public function find_order( string $reference ): ?array {
		// WooCommerce deactivated: there are no orders to tell about.
		if ( ! self::available() ) {
			return null;
		}
		$id = ctype_digit( $reference ) ? (int) $reference : 0;
		/**
		 * The order ID for a reference, for shops whose order numbers are not their IDs (such as a
		 * sequential numbering plugin).
		 *
		 * @param int    $id        the order ID found so far, 0 for none.
		 * @param string $reference what the customer typed.
		 */
		$id    = (int) apply_filters( 'chatpuff_order_id_from_reference', $id, $reference );
		$order = $id > 0 ? wc_get_order( $id ) : false;
		if ( ! $order instanceof \WC_Order || in_array( $order->get_status(), array( 'trash', 'checkout-draft', 'auto-draft' ), true ) ) {
			return null;
		}
		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return null;
		}
		$language = strtolower( substr( (string) $order->get_meta( 'wpml_language' ), 0, 2 ) );

		return array(
			'id'          => (string) $order->get_id(),
			'reference'   => (string) $order->get_order_number(),
			// The WordPress user who placed it, as in customer tokens; a guest order has none.
			'customer_id' => $order->get_customer_id() > 0 ? (string) $order->get_customer_id() : null,
			'email'       => (string) $email,
			'language'    => 1 === preg_match( '/^[a-z]{2}$/', $language ) ? $language : null,
		);
	}

	/**
	 * Whether WooCommerce's order functions are there, so that the plugin can answer about orders.
	 */
	public static function available(): bool {
		return function_exists( 'wc_get_order' ) && class_exists( 'WC_Order' );
	}

	/**
	 * The public half of ChatPuff's key, from the keys saved when the site connected; an unknown
	 * key ID makes the plugin ask ChatPuff for its current keys once.
	 *
	 * @param array<string, mixed> $connection the site's connection.
	 * @param string               $key_id     the key ID of the call.
	 */
	private function public_key( array $connection, string $key_id ): ?string {
		$key = self::key_in( is_array( $connection['saas_keys'] ?? null ) ? $connection['saas_keys'] : array(), $key_id );
		if ( null !== $key ) {
			return $key;
		}
		try {
			$answer = $this->api->send( 'GET', '/integration/v1/saas-keys', null, (string) $connection['key_id'], (string) $connection['secret_key'] );
		} catch ( Api_Exception $exception ) {
			return null;
		}
		$keys = is_array( $answer['keys'] ?? null ) ? $answer['keys'] : array();
		if ( array() !== $keys ) {
			$connection['saas_keys'] = $keys;
			Settings::save_connection( $connection );
		}

		return self::key_in( $keys, $key_id );
	}

	/**
	 * The public key with this ID among ChatPuff's keys.
	 *
	 * @param array<mixed> $keys   ChatPuff's keys.
	 * @param string       $key_id the key ID.
	 */
	private static function key_in( array $keys, string $key_id ): ?string {
		foreach ( $keys as $key ) {
			if ( is_array( $key ) && ( $key['key_id'] ?? null ) === $key_id && is_string( $key['public_key'] ?? null ) ) {
				return $key['public_key'];
			}
		}

		return null;
	}

	/**
	 * Remembers the nonces of the last ten minutes, so that a recorded call cannot be sent again.
	 *
	 * @param string $nonce the call's nonce.
	 */
	private static function first_use( string $nonce ): bool {
		$now  = time();
		$seen = get_option( self::NONCES, array() );
		$seen = is_array( $seen ) ? $seen : array();
		$seen = array_filter(
			$seen,
			static function ( $at ) use ( $now ): bool {
				return is_int( $at ) && $at > $now - self::NONCE_LIFETIME;
			}
		);
		if ( isset( $seen[ $nonce ] ) ) {
			return false;
		}
		$seen[ $nonce ] = $now;
		if ( count( $seen ) > self::NONCE_LIMIT ) {
			arsort( $seen );
			$seen = array_slice( $seen, 0, self::NONCE_LIMIT, true );
		}
		update_option( self::NONCES, $seen, false );

		return true;
	}
}

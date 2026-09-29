<?php
/**
 * The customer token for logged-in customers.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * A short-lived JWT, signed with the site's connection key, that tells the chat who the logged-in
 * customer is, so they skip the name and email form (api-contract.md §7.2).
 *
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
 */
final class Customer_Token {

	/** The longest lifetime ChatPuff accepts. */
	public const LIFETIME = 600;

	/**
	 * A token for one page view.
	 *
	 * @param array<string, mixed> $connection      key_id and secret_key, as Settings::connection() returns them.
	 * @param string               $installation_id this WordPress's installation ID.
	 * @param string               $shop_context    the site's blog ID.
	 * @param string               $customer_id     the user's ID.
	 * @param string               $name            the customer's name.
	 * @param string               $email           the customer's email address.
	 * @param int                  $now             Unix time.
	 */
	public static function create( array $connection, string $installation_id, string $shop_context, string $customer_id, string $name, string $email, int $now ): string {
		$header   = array(
			'alg' => 'EdDSA',
			'kid' => (string) $connection['key_id'],
			'typ' => 'JWT',
		);
		$claims   = array(
			'iss'   => $installation_id,
			'aud'   => 'chatpuff-widget',
			'ctx'   => $shop_context,
			'sub'   => $customer_id,
			'name'  => $name,
			'email' => $email,
			'iat'   => $now,
			'exp'   => $now + self::LIFETIME,
			'jti'   => bin2hex( random_bytes( 16 ) ),
		);
		$unsigned = self::encode( (string) wp_json_encode( $header ) ) . '.' . self::encode( (string) wp_json_encode( $claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		return $unsigned . '.' . self::encode( (string) base64_decode( Crypto::sign( (string) $connection['secret_key'], $unsigned ), true ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $value raw bytes.
	 */
	private static function encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}
}

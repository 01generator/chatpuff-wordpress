<?php
/**
 * Ed25519 keys and the encryption of stored private keys.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The connection's key pair is created here and its private half never leaves this site. WordPress
 * provides the sodium functions on every PHP version it supports (ext-sodium or its bundled
 * sodium_compat).
 *
 * Base64 is how the API carries keys and signatures (api-contract.md §5), not a way to hide code.
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
 */
final class Crypto {

	/**
	 * A new key pair.
	 *
	 * @return array{secret: string, public: string} base64 keys
	 */
	public static function generate_key_pair(): array {
		$pair = sodium_crypto_sign_keypair();

		return array(
			'secret' => base64_encode( sodium_crypto_sign_secretkey( $pair ) ),
			'public' => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
		);
	}

	/**
	 * A base64 Ed25519 signature of the message.
	 *
	 * @param string $secret_key base64 private key.
	 * @param string $message    what to sign.
	 *
	 * @throws \InvalidArgumentException When the key is not an Ed25519 private key.
	 */
	public static function sign( string $secret_key, string $message ): string {
		$key = base64_decode( $secret_key, true );
		if ( false === $key || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $key ) ) {
			throw new \InvalidArgumentException( 'The ChatPuff signing key is not valid.' );
		}

		return base64_encode( sodium_crypto_sign_detached( $message, $key ) );
	}

	/**
	 * Whether the base64 signature is the Ed25519 signature of the message by the base64 public key.
	 *
	 * @param string $public_key base64 public key.
	 * @param string $message    what was signed.
	 * @param string $signature  base64 signature.
	 */
	public static function verify( string $public_key, string $message, string $signature ): bool {
		$key   = base64_decode( $public_key, true );
		$bytes = base64_decode( $signature, true );
		if ( false === $key || false === $bytes || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $bytes ) ) {
			return false;
		}

		return sodium_crypto_sign_verify_detached( $bytes, $message, $key );
	}

	/**
	 * Private keys are stored encrypted with a key derived from this site's security keys, which
	 * live in wp-config.php, not in the database. A database dump alone therefore does not reveal
	 * them.
	 *
	 * @param string $plaintext what to encrypt.
	 */
	public static function encrypt( string $plaintext ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, self::storage_key() ) );
	}

	/**
	 * The plaintext, or null when it cannot be read, for example after the site's security keys
	 * were changed.
	 *
	 * @param string $ciphertext what encrypt() returned.
	 */
	public static function decrypt( string $ciphertext ): ?string {
		$raw = base64_decode( $ciphertext, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plaintext = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::storage_key() );

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * The encryption key: a hash of the site's AUTH_KEY and AUTH_SALT.
	 */
	private static function storage_key(): string {
		return sodium_crypto_generichash( 'chatpuff-key-storage|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}

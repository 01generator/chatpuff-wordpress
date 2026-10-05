<?php
/**
 * The plugin's stored state.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The installation ID belongs to the whole WordPress (the network, on a multisite); the connection
 * and a pairing in progress belong to one site, with private keys encrypted.
 */
final class Settings {

	public const INSTALLATION_ID = 'chatpuff_installation_id';
	public const CONNECTION      = 'chatpuff_connection';
	public const PAIRING         = 'chatpuff_pairing';
	public const REPORTED        = 'chatpuff_reported';

	/** The installation report doubles as the connection's heartbeat: at most once an hour (api-contract.md §7). */
	public const REPORT_INTERVAL = 3600;

	/**
	 * This WordPress's identity in ChatPuff.
	 */
	public static function installation_id(): string {
		$id = (string) get_site_option( self::INSTALLATION_ID, '' );
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/', $id ) ) {
			$id = self::reset_installation_id();
		}

		return $id;
	}

	/**
	 * A new identity for this WordPress, for example on a copy of a live shop. Existing connections
	 * keep working; the next pairing counts as a new installation.
	 */
	public static function reset_installation_id(): string {
		$id = wp_generate_uuid4();
		update_site_option( self::INSTALLATION_ID, $id );

		return $id;
	}

	/**
	 * This site's connection, with its private key decrypted.
	 *
	 * @return array<string, mixed>|null key_id, shop_id, domain, secret_key, saas_keys, dashboard_url, connected_at
	 */
	public static function connection(): ?array {
		$data = self::read( self::CONNECTION );
		if ( null === $data || ! is_string( $data['secret_key'] ?? null ) ) {
			return null;
		}
		$secret = Crypto::decrypt( $data['secret_key'] );
		if ( null === $secret ) {
			return null;
		}
		$data['secret_key'] = $secret;

		return $data;
	}

	/**
	 * A stored connection whose private key can no longer be read: the site's security keys
	 * (wp-config.php) changed since it was connected.
	 */
	public static function connection_unreadable(): bool {
		return null !== self::read( self::CONNECTION ) && null === self::connection();
	}

	/**
	 * What the storefront needs on every page: the ChatPuff shop ID and the domain the site was
	 * connected with. Unlike connection(), it does not decrypt the private key.
	 *
	 * @return array{shop_id: string, domain: string}|null
	 */
	public static function widget(): ?array {
		$data = self::read( self::CONNECTION );
		if ( null === $data || ! is_string( $data['shop_id'] ?? null ) || '' === $data['shop_id'] || ! is_string( $data['domain'] ?? null ) ) {
			return null;
		}

		return array(
			'shop_id' => $data['shop_id'],
			'domain'  => $data['domain'],
		);
	}

	/**
	 * Stores the connection.
	 *
	 * @param array<string, mixed> $connection with the private key in plain text.
	 */
	public static function save_connection( array $connection ): void {
		$connection['secret_key'] = Crypto::encrypt( (string) $connection['secret_key'] );
		// Read on every storefront page: autoloaded.
		update_option( self::CONNECTION, $connection, true );
	}

	/**
	 * Forgets the connection.
	 */
	public static function clear_connection(): void {
		delete_option( self::CONNECTION );
		delete_option( self::REPORTED );
	}

	/**
	 * A pairing in progress, or its outcome.
	 *
	 * @return array<string, mixed>|null request_id, public_key, secret_key (decrypted), domain, confirmation_url, expires_at, outcome, code
	 */
	public static function pairing(): ?array {
		$data = self::read( self::PAIRING );
		if ( null === $data ) {
			return null;
		}
		if ( is_string( $data['secret_key'] ?? null ) ) {
			$data['secret_key'] = Crypto::decrypt( $data['secret_key'] );
		}

		return $data;
	}

	/**
	 * Stores a pairing.
	 *
	 * @param array<string, mixed> $pairing with the private key in plain text.
	 */
	public static function save_pairing( array $pairing ): void {
		if ( is_string( $pairing['secret_key'] ?? null ) ) {
			$pairing['secret_key'] = Crypto::encrypt( $pairing['secret_key'] );
		}
		update_option( self::PAIRING, $pairing, false );
	}

	/**
	 * Forgets the pairing.
	 */
	public static function clear_pairing(): void {
		delete_option( self::PAIRING );
	}

	/**
	 * Whether this site's installation report is due: an hour after the last one, or at once after
	 * the plugin was updated. Claiming it at once keeps another request of the same moment from
	 * sending it too.
	 *
	 * @param int $now Unix time.
	 */
	public static function claim_report( int $now ): bool {
		$last = self::read( self::REPORTED );
		if ( null !== $last && CHATPUFF_VERSION === ( $last['version'] ?? '' ) && $now - (int) ( $last['at'] ?? 0 ) < self::REPORT_INTERVAL ) {
			return false;
		}
		update_option(
			self::REPORTED,
			array(
				'at'      => $now,
				'version' => CHATPUFF_VERSION,
			),
			false
		);

		return true;
	}

	/**
	 * Removes everything this site stored.
	 */
	public static function delete_site_data(): void {
		foreach ( array( self::CONNECTION, self::PAIRING, self::REPORTED, Order_Callback::NONCES ) as $key ) {
			delete_option( $key );
		}
	}

	/**
	 * Removes the installation ID, once no site uses it.
	 */
	public static function delete_installation_id(): void {
		delete_site_option( self::INSTALLATION_ID );
	}

	/**
	 * An array option.
	 *
	 * @param string $key the option name.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function read( string $key ): ?array {
		$value = get_option( $key, null );

		return is_array( $value ) && array() !== $value ? $value : null;
	}
}

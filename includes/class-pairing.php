<?php
/**
 * Connecting a site to ChatPuff.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Connects this site to ChatPuff (api-contract.md §6): the key pair is created here and its private
 * half never leaves the site. ChatPuff then checks the site's domain with a challenge that only this
 * plugin can sign. On a multisite, each site is connected on its own.
 */
final class Pairing {

	/**
	 * The API client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * A pairing service.
	 *
	 * @param Api_Client $api the API client.
	 */
	public function __construct( Api_Client $api ) {
		$this->api = $api;
	}

	/**
	 * Starts a pairing.
	 *
	 * @return string the owner's confirmation link
	 *
	 * @throws Api_Exception When ChatPuff refuses or cannot be reached.
	 */
	public function start(): string {
		$storefront = home_url( '/' );
		$keys       = Crypto::generate_key_pair();

		$response = $this->api->send(
			'POST',
			'/integration/v1/pairing-requests',
			array(
				'platform_type'           => 'woocommerce',
				'installation_identifier' => Settings::installation_id(),
				'external_shop_context'   => self::shop_context(),
				'storefront_url'          => $storefront,
				'callback_base_url'       => self::callback_url(),
				'public_key'              => $keys['public'],
				'shop_name'               => self::shop_name(),
				'default_locale'          => self::language( get_locale() ),
				'timezone'                => wp_timezone_string(),
				'metadata'                => self::metadata(),
			),
			Api_Client::PAIRING_KEY_ID,
			$keys['secret']
		);

		Settings::save_pairing(
			array(
				'request_id'       => (string) ( $response['pairing_request_id'] ?? '' ),
				'public_key'       => $keys['public'],
				'secret_key'       => $keys['secret'],
				'domain'           => Shop_Domain::normalize( $storefront ),
				'confirmation_url' => (string) ( $response['confirmation_url'] ?? '' ),
				'expires_at'       => (string) ( $response['expires_at'] ?? '' ),
				'outcome'          => 'pending',
			)
		);

		return (string) ( $response['confirmation_url'] ?? '' );
	}

	/**
	 * Asks ChatPuff how the pairing is going and stores the connection once it is complete.
	 *
	 * @return array{state: string, code?: string} state: none, pending, connected, rejected or expired
	 */
	public function poll(): array {
		$pairing = Settings::pairing();
		if ( null === $pairing ) {
			return array( 'state' => 'none' );
		}
		if ( 'pending' !== ( $pairing['outcome'] ?? 'pending' ) ) {
			return array(
				'state' => (string) $pairing['outcome'],
				'code'  => (string) ( $pairing['code'] ?? '' ),
			);
		}
		if ( ! is_string( $pairing['secret_key'] ?? null ) ) {
			return array( 'state' => 'none' );
		}

		try {
			$status = $this->api->send( 'GET', '/integration/v1/pairing-requests/' . rawurlencode( (string) $pairing['request_id'] ), null, Api_Client::PAIRING_KEY_ID, $pairing['secret_key'] );
		} catch ( Api_Exception $exception ) {
			if ( 'not_found' === $exception->get_problem_code() ) {
				return $this->finish( $pairing, 'expired', '' );
			}

			return array(
				'state' => 'pending',
				'code'  => $exception->get_problem_code(),
			);
		}

		switch ( $status['status'] ?? '' ) {
			case 'consumed':
				Settings::save_connection(
					array(
						'key_id'        => (string) ( $status['key_id'] ?? '' ),
						'shop_id'       => (string) ( $status['shop_id'] ?? '' ),
						'domain'        => (string) $pairing['domain'],
						'secret_key'    => $pairing['secret_key'],
						'saas_keys'     => is_array( $status['saas_signing_keys'] ?? null ) ? $status['saas_signing_keys'] : array(),
						'dashboard_url' => (string) preg_replace( '~^(https?://[^/]+).*$~', '$1', (string) $pairing['confirmation_url'] ),
						'connected_at'  => gmdate( 'c' ),
					)
				);
				Settings::clear_pairing();

				return array( 'state' => 'connected' );
			case 'rejected':
				return $this->finish( $pairing, 'rejected', (string) ( $status['code'] ?? '' ) );
			case 'expired':
				return $this->finish( $pairing, 'expired', '' );
			default:
				return array( 'state' => 'pending' );
		}
	}

	/**
	 * The signed answer to ChatPuff's domain challenge, or null when no pairing is waiting for one.
	 *
	 * @param string $challenge 64 hex characters.
	 */
	public function answer_challenge( string $challenge ): ?string {
		$pairing = Settings::pairing();
		if ( null === $pairing || 'pending' !== ( $pairing['outcome'] ?? '' ) || ! is_string( $pairing['secret_key'] ?? null ) ) {
			return null;
		}

		return Crypto::sign( $pairing['secret_key'], "CHATPUFF-DOMAIN-V1\n" . $pairing['domain'] . "\n" . $challenge );
	}

	/**
	 * The connection's state in ChatPuff.
	 *
	 * @return array<string, mixed> shop_name, organization_name, widget_published
	 *
	 * @throws Api_Exception When ChatPuff refuses or cannot be reached.
	 */
	public function connection_status(): array {
		return $this->send( 'GET', '/integration/v1/connection' );
	}

	/**
	 * Reports this installation's versions; also the connection's heartbeat.
	 *
	 * @throws Api_Exception When ChatPuff refuses or cannot be reached.
	 */
	public function report_installation(): void {
		$this->send( 'PUT', '/integration/v1/installation', array( 'metadata' => self::metadata() ) );
	}

	/**
	 * A signed Integration API call for a connected site, never from a copy of it.
	 *
	 * @param string                    $method the HTTP method.
	 * @param string                    $path   the path.
	 * @param array<string, mixed>|null $body   the JSON body.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws Api_Exception When not connected, or ChatPuff refuses or cannot be reached.
	 */
	public function send( string $method, string $path, ?array $body = null ): array {
		$connection = $this->usable_connection();
		try {
			return $this->api->send( $method, $path, $body, (string) $connection['key_id'], (string) $connection['secret_key'] );
		} catch ( Api_Exception $exception ) {
			if ( in_array( $exception->get_problem_code(), array( 'connection_revoked', 'unknown_key' ), true ) ) {
				// Disconnected on the ChatPuff side: forget the key here too.
				Settings::clear_connection();
			}

			throw $exception;
		}
	}

	/**
	 * Disconnects the site.
	 *
	 * @throws Api_Exception When ChatPuff cannot be reached; the connection is then kept.
	 */
	public function disconnect(): void {
		$connection = $this->usable_connection();
		try {
			$this->api->send( 'DELETE', '/integration/v1/connection', null, (string) $connection['key_id'], (string) $connection['secret_key'] );
		} catch ( Api_Exception $exception ) {
			if ( ! in_array( $exception->get_problem_code(), array( 'connection_revoked', 'unknown_key' ), true ) ) {
				throw $exception;
			}
		}
		Settings::clear_connection();
	}

	/**
	 * A copy of a connected site (a staging site, a restored backup on another address) carries the
	 * live site's key. It must not act for the live shop, so the connection is used only on the
	 * domain it was made for.
	 */
	public static function is_copy(): bool {
		$widget = Settings::widget();

		return null !== $widget && Shop_Domain::current() !== $widget['domain'];
	}

	/**
	 * On a copy: drop the live site's key and take a new installation identity, so that this copy
	 * can be connected as a shop of its own.
	 */
	public function forget_copy(): void {
		if ( self::is_copy() ) {
			Settings::clear_connection();
			Settings::clear_pairing();
			Settings::reset_installation_id();
		}
	}

	/**
	 * Forgets a connection whose private key can no longer be read, so the site can connect again.
	 * Connecting again as the same owner reattaches the same ChatPuff shop (api-contract.md §6.5).
	 */
	public function forget_unreadable(): void {
		if ( Settings::connection_unreadable() ) {
			Settings::clear_connection();
		}
	}

	/**
	 * The address ChatPuff calls to check the domain (api-contract.md §6.3).
	 */
	public static function callback_url(): string {
		return get_rest_url( null, Rest_Callback::ROUTE_NAMESPACE . Rest_Callback::ROUTE );
	}

	/**
	 * The site's blog ID: each site of a multisite is a shop context of its own.
	 */
	public static function shop_context(): string {
		return (string) get_current_blog_id();
	}

	/**
	 * The two-letter language of a WordPress locale, such as "el" for "el" or "de" for "de_DE".
	 *
	 * @param string $locale a WordPress locale.
	 */
	public static function language( string $locale ): string {
		$language = strtolower( substr( $locale, 0, 2 ) );

		return 1 === preg_match( '/^[a-z]{2}$/', $language ) ? $language : 'en';
	}

	/**
	 * Versions and capabilities, for pairing and the hourly report.
	 *
	 * @return array<string, mixed>
	 */
	public static function metadata(): array {
		return array(
			// ChatPuff's platform version is WooCommerce's; WordPress's goes in the client header.
			'platform_version'     => Plugin::woocommerce_version(),
			'php_version'          => self::version_token( PHP_VERSION ),
			'integration_version'  => CHATPUFF_VERSION,
			'api_contract_version' => Api_Client::API_CONTRACT_VERSION,
			'capabilities'         => array_merge(
				array( 'customer_identity', 'back_office_inbox', 'knowledge_sync' ),
				// Orders can be checked only while WooCommerce runs.
				Order_Callback::available() ? array( Order_Callback::CAPABILITY, Order_Callback::DETAILS_CAPABILITY, Order_Callback::LIST_CAPABILITY ) : array()
			),
		);
	}

	/**
	 * A version as ChatPuff accepts it: letters, digits and . + _ -, at most 32 characters.
	 *
	 * @param string $version a version.
	 */
	public static function version_token( string $version ): string {
		$token = substr( (string) preg_replace( '/[^0-9A-Za-z.+_-]/', '', $version ), 0, 32 );

		return '' === $token ? '0' : $token;
	}

	/**
	 * The site's name, as ChatPuff shows it.
	 */
	private static function shop_name(): string {
		$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );

		return '' === $name ? Shop_Domain::current() : mb_substr( $name, 0, 120 );
	}

	/**
	 * The connection, when this site may use it.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws Api_Exception When the site is not connected, or is a copy.
	 */
	private function usable_connection(): array {
		$connection = Settings::connection();
		if ( null === $connection || self::is_copy() ) {
			throw new Api_Exception( 'not_connected', 0 );
		}

		return $connection;
	}

	/**
	 * Records the pairing's outcome and drops its key.
	 *
	 * @param array<string, mixed> $pairing the pairing.
	 * @param string               $outcome rejected or expired.
	 * @param string               $code    the reason.
	 *
	 * @return array{state: string, code: string}
	 */
	private function finish( array $pairing, string $outcome, string $code ): array {
		unset( $pairing['secret_key'] );
		$pairing['outcome'] = $outcome;
		$pairing['code']    = $code;
		Settings::save_pairing( $pairing );

		return array(
			'state' => $outcome,
			'code'  => $code,
		);
	}
}

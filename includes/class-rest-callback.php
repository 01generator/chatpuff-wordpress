<?php
/**
 * The endpoint ChatPuff calls on this site.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * ChatPuff's servers call this address to check the site's domain while it is being connected
 * (api-contract.md §6.3). It is a REST route, so page caches and maintenance-mode pages normally
 * leave it alone. It answers only while a pairing waits, and reveals nothing without the challenge.
 */
final class Rest_Callback {

	public const ROUTE_NAMESPACE = 'chatpuff/v1';
	public const ROUTE           = '/callback';

	/**
	 * Registers the route.
	 */
	public static function register(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle' ),
				// Anyone may call it: ChatPuff's servers are not WordPress users.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Answers ChatPuff's call.
	 *
	 * @param \WP_REST_Request $request the request.
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'verify' !== $request->get_param( 'action' ) ) {
			return self::respond( array( 'code' => 'not_found' ), 404 );
		}

		$challenge = (string) $request->get_param( 'challenge' );
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $challenge ) ) {
			return self::respond( array( 'code' => 'invalid_challenge' ), 400 );
		}

		$signature = ( new Pairing( new Api_Client() ) )->answer_challenge( $challenge );
		if ( null === $signature ) {
			return self::respond( array( 'code' => 'no_pairing_in_progress' ), 404 );
		}

		return self::respond(
			array(
				'challenge' => $challenge,
				'signature' => $signature,
			),
			200
		);
	}

	/**
	 * A JSON answer that no cache keeps.
	 *
	 * @param array<string, string> $data   the body.
	 * @param int                   $status the HTTP status.
	 */
	private static function respond( array $data, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Robots-Tag', 'noindex' );

		return $response;
	}
}

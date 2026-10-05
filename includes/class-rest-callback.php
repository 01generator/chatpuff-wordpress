<?php
/**
 * The endpoint ChatPuff calls on this site.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * ChatPuff's servers call this address. It is a REST route, so page caches and maintenance-mode
 * pages normally leave it alone.
 *
 * - `action=verify` checks the site's domain while it is being connected (api-contract.md §6.3). It
 *   answers only while a pairing waits, and reveals nothing without the challenge.
 * - `action=order`, signed by ChatPuff, asks about an order a customer wants to verify (§7.7).
 * - `action=order_details`, signed the same way, asks what may be told about a verified order.
 * - `action=customer_orders`, signed the same way, lists a signed-in customer's latest orders.
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
		if ( 'order' === $request->get_param( 'action' ) ) {
			return self::order( $request );
		}
		if ( 'order_details' === $request->get_param( 'action' ) ) {
			return self::order_details( $request );
		}
		if ( 'customer_orders' === $request->get_param( 'action' ) ) {
			return self::customer_orders( $request );
		}
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
	 * Answers ChatPuff's signed question about an order (api-contract.md §7.7).
	 *
	 * @param \WP_REST_Request $request the request.
	 */
	private static function order( \WP_REST_Request $request ): \WP_REST_Response {
		$callback = new Order_Callback( new Api_Client() );
		if ( ! self::signed_by_chatpuff( $request, $callback ) ) {
			return self::respond( array( 'code' => 'invalid_signature' ), 401 );
		}
		$reference = (string) $request->get_param( 'reference' );
		if ( 1 !== preg_match( '/^[A-Z0-9_-]{1,40}$/', $reference ) ) {
			return self::respond( array( 'code' => 'invalid_reference' ), 400 );
		}

		$order = $callback->find_order( $reference );
		if ( null === $order ) {
			return self::respond( array( 'code' => 'order_not_found' ), 404 );
		}
		$response = new \WP_REST_Response( array( 'order' => $order ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Robots-Tag', 'noindex' );

		return $response;
	}

	/**
	 * Answers ChatPuff's signed question about what may be told about a verified order (§7.7).
	 *
	 * @param \WP_REST_Request $request the request.
	 */
	private static function order_details( \WP_REST_Request $request ): \WP_REST_Response {
		$callback = new Order_Callback( new Api_Client() );
		if ( ! self::signed_by_chatpuff( $request, $callback ) ) {
			return self::respond( array( 'code' => 'invalid_signature' ), 401 );
		}
		$order = $callback->describe_order( (string) $request->get_param( 'order_id' ) );
		if ( null === $order ) {
			return self::respond( array( 'code' => 'order_not_found' ), 404 );
		}
		$response = new \WP_REST_Response( array( 'order' => $order ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Robots-Tag', 'noindex' );

		return $response;
	}

	/**
	 * Answers ChatPuff's signed question about a signed-in customer's latest orders (§7.7).
	 *
	 * @param \WP_REST_Request $request the request.
	 */
	private static function customer_orders( \WP_REST_Request $request ): \WP_REST_Response {
		$callback = new Order_Callback( new Api_Client() );
		if ( ! self::signed_by_chatpuff( $request, $callback ) ) {
			return self::respond( array( 'code' => 'invalid_signature' ), 401 );
		}
		$response = new \WP_REST_Response( array( 'orders' => $callback->customer_orders( (string) $request->get_param( 'customer_id' ) ) ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Robots-Tag', 'noindex' );

		return $response;
	}

	/**
	 * Whether ChatPuff signed this call to this site.
	 *
	 * @param \WP_REST_Request $request  the request.
	 * @param Order_Callback   $callback the order callback.
	 */
	private static function signed_by_chatpuff( \WP_REST_Request $request, Order_Callback $callback ): bool {
		$headers = array();
		foreach ( array( 'Chatpuff-Key-Id', 'Chatpuff-Timestamp', 'Chatpuff-Nonce', 'Chatpuff-Signature' ) as $name ) {
			$headers[ $name ] = (string) $request->get_header( $name );
		}
		// The signature covers the request target exactly as ChatPuff sent it, so it is read as sent.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$target       = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$on_this_site = (string) $request->get_param( 'shop_context' ) === Pairing::shop_context();

		return $on_this_site && $callback->is_signed_by_chatpuff( $headers, $request->get_method(), $target );
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

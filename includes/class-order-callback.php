<?php
/**
 * ChatPuff's signed question about an order.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * ChatPuff asks about an order so that a customer can prove it is theirs, and then for what may be
 * told about it (api-contract.md §7.7). Only ChatPuff may ask: the call is signed with ChatPuff's
 * own key, which the plugin received when the site connected, within five minutes, and never twice.
 * The check names the order, its customer and the email address on it; the details its status,
 * items and tracking, nothing more.
 */
final class Order_Callback {

	public const CAPABILITY         = 'order_verification';
	public const DETAILS_CAPABILITY = 'order_details';
	public const LIST_CAPABILITY    = 'customer_orders';
	public const SCHEME             = 'CHATPUFF-SAAS-ED25519-V1';
	public const NONCES             = 'chatpuff_saas_nonces';
	public const STATUSES           = array( 'pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded' );
	private const CLOCK_WINDOW      = 300;
	private const NONCE_LIFETIME    = 600;
	private const NONCE_LIMIT       = 500;
	private const MAX_ITEMS         = 50;
	private const MAX_SHIPMENTS     = 10;
	private const MAX_RECENT        = 5;
	// A stand-in order ID, replaced by {id} in the admin's order address.
	private const SAMPLE_ORDER_ID = 987654321;
	// WooCommerce's own statuses as ChatPuff names them; WooCommerce does not record delivery, so a
	// completed (fulfilled) order counts as shipped. Shipping plugins' own statuses are mapped too.
	private const STATUS_MAP = array(
		'pending'    => 'pending',
		'on-hold'    => 'pending',
		'failed'     => 'pending',
		'processing' => 'processing',
		'completed'  => 'shipped',
		'shipped'    => 'shipped',
		'delivered'  => 'delivered',
		'cancelled'  => 'cancelled',
		'refunded'   => 'refunded',
	);

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
	 * What may be told about one of this site's orders, by its ID (api-contract.md §7.7: the date
	 * placed, the status, the items and the tracking; no prices, addresses or payment details).
	 *
	 * @param string $order_id the order ID of the verification answer.
	 *
	 * @return array<string, mixed>|null
	 */
	public function describe_order( string $order_id ): ?array {
		if ( ! self::available() || 1 !== preg_match( '/^[1-9]\d{0,9}$/', $order_id ) ) {
			return null;
		}
		$order   = wc_get_order( (int) $order_id );
		$created = $order instanceof \WC_Order ? $order->get_date_created() : null;
		if ( ! $order instanceof \WC_Order || null === $created || in_array( $order->get_status(), array( 'trash', 'checkout-draft', 'auto-draft' ), true ) ) {
			return null;
		}
		return self::summary( $order, $created ) + array(
			'items'    => self::items( $order ),
			'tracking' => self::tracking( $order ),
		);
	}

	/**
	 * A signed-in customer's latest orders on this site, newest first, so that they can pick one in
	 * the chat (api-contract.md §7.7, customer_orders). Guest orders have no customer and never show.
	 *
	 * @param string $customer_id the WordPress user ID the customer token named.
	 *
	 * @return list<array<string, string>>
	 */
	public function customer_orders( string $customer_id ): array {
		if ( ! self::available() || 1 !== preg_match( '/^[1-9]\d{0,9}$/', $customer_id ) ) {
			return array();
		}
		$statuses = array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) );
		$found    = wc_get_orders(
			array(
				'customer_id' => (int) $customer_id,
				'type'        => 'shop_order',
				'status'      => $statuses,
				'limit'       => self::MAX_RECENT,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$orders   = array();
		foreach ( is_array( $found ) ? $found : array() as $order ) {
			$created = $order->get_date_created();
			if ( null !== $created ) {
				$orders[] = self::summary( $order, $created );
			}
		}

		return $orders;
	}

	/**
	 * The order's ID, number, date and status, with WooCommerce's own name for the status.
	 *
	 * @param \WC_Order    $order   the order.
	 * @param \WC_DateTime $created the date it was placed.
	 *
	 * @return array<string, string>
	 */
	private static function summary( \WC_Order $order, \WC_DateTime $created ): array {
		$status = self::status( $order );
		$label  = trim( wp_strip_all_tags( wc_get_order_status_name( $order->get_status() ) ) );

		return array(
			'id'           => (string) $order->get_id(),
			'reference'    => (string) $order->get_order_number(),
			'placed_at'    => $created->format( DATE_ATOM ),
			'status'       => $status,
			'status_label' => '' !== $label ? mb_substr( $label, 0, 100 ) : ucfirst( $status ),
		);
	}

	/**
	 * The address of an order's page in this admin, with {id} for the order ID: the inbox links a
	 * verified order there, and WordPress checks the user's own permissions.
	 */
	public static function admin_order_url(): string {
		if ( ! self::available() ) {
			return '';
		}
		// High-Performance Order Storage (WooCommerce 6.9 and later) has its own order pages.
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$url  = $hpos
			? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . self::SAMPLE_ORDER_ID )
			: admin_url( 'post.php?post=' . self::SAMPLE_ORDER_ID . '&action=edit' );

		return str_replace( (string) self::SAMPLE_ORDER_ID, '{id}', $url );
	}

	/**
	 * The order's status as ChatPuff names it.
	 *
	 * @param \WC_Order $order the order.
	 */
	private static function status( \WC_Order $order ): string {
		$status = $order->get_status();
		$mapped = self::STATUS_MAP[ $status ] ?? ( $order->is_paid() ? 'processing' : 'pending' );
		/**
		 * The status ChatPuff gives the customer for an order: one of pending, processing, shipped,
		 * delivered, cancelled, refunded. For shops with their own order statuses.
		 *
		 * @param string    $mapped the status the plugin chose.
		 * @param string    $status WooCommerce's status, without "wc-".
		 * @param \WC_Order $order  the order.
		 */
		$filtered = apply_filters( 'chatpuff_order_status', $mapped, $status, $order );

		return in_array( $filtered, self::STATUSES, true ) ? $filtered : $mapped;
	}

	/**
	 * Each line's product name as ordered and its quantity.
	 *
	 * @param \WC_Order $order the order.
	 *
	 * @return list<array{name: string, quantity: int}>
	 */
	private static function items( \WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$name     = trim( wp_strip_all_tags( (string) $item->get_name() ) );
			$quantity = (int) $item->get_quantity();
			if ( '' !== $name && $quantity > 0 ) {
				$items[] = array(
					'name'     => mb_substr( $name, 0, 255 ),
					'quantity' => $quantity,
				);
			}
			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * The shipments with a tracking number, as WooCommerce Shipment Tracking (or a plugin that
	 * keeps its data, such as Advanced Shipment Tracking) records them, or as the filter gives them.
	 *
	 * @param \WC_Order $order the order.
	 *
	 * @return list<array{carrier: string|null, number: string, url: string|null}>
	 */
	private static function tracking( \WC_Order $order ): array {
		$recorded = $order->get_meta( '_wc_shipment_tracking_items' );
		// The extension formats its known carriers' names and tracking links.
		if ( is_callable( array( 'WC_Shipment_Tracking_Actions', 'get_instance' ) ) ) {
			$actions = call_user_func( array( 'WC_Shipment_Tracking_Actions', 'get_instance' ) );
			if ( is_object( $actions ) && is_callable( array( $actions, 'get_tracking_items' ) ) ) {
				$formatted = call_user_func( array( $actions, 'get_tracking_items' ), $order->get_id(), true );
				$recorded  = is_array( $formatted ) ? $formatted : $recorded;
			}
		}
		$shipments = array();
		foreach ( is_array( $recorded ) ? $recorded : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$shipments[] = array(
				'carrier' => self::first_text( $item, array( 'formatted_tracking_provider', 'custom_tracking_provider', 'tracking_provider' ) ),
				'number'  => self::first_text( $item, array( 'tracking_number' ) ),
				'url'     => self::first_text( $item, array( 'formatted_tracking_link', 'custom_tracking_link' ) ),
			);
		}
		/**
		 * The order's shipments for ChatPuff, for shops that record tracking another way: a list of
		 * arrays with carrier, number and url (carrier and url may be null).
		 *
		 * @param array<int, array<string, mixed>> $shipments the shipments the plugin found.
		 * @param \WC_Order                        $order     the order.
		 */
		$shipments = apply_filters( 'chatpuff_order_tracking', $shipments, $order );

		$tracking = array();
		foreach ( $shipments as $shipment ) {
			$number  = is_string( $shipment['number'] ?? null ) ? trim( $shipment['number'] ) : '';
			$carrier = is_string( $shipment['carrier'] ?? null ) ? trim( $shipment['carrier'] ) : '';
			$url     = is_string( $shipment['url'] ?? null ) ? trim( $shipment['url'] ) : '';
			if ( '' === $number || mb_strlen( $number ) > 100 ) {
				continue;
			}
			$tracking[] = array(
				'carrier' => '' !== $carrier ? mb_substr( $carrier, 0, 100 ) : null,
				'number'  => $number,
				'url'     => 1 === preg_match( '#^https?://\S+$#', $url ) && strlen( $url ) <= 500 ? $url : null,
			);
			if ( count( $tracking ) >= self::MAX_SHIPMENTS ) {
				break;
			}
		}

		return $tracking;
	}

	/**
	 * The first of these keys that holds text.
	 *
	 * @param array<mixed>  $item the recorded item.
	 * @param array<string> $keys the keys, in order.
	 */
	private static function first_text( array $item, array $keys ): ?string {
		foreach ( $keys as $key ) {
			if ( is_string( $item[ $key ] ?? null ) && '' !== trim( $item[ $key ] ) ) {
				return trim( wp_strip_all_tags( $item[ $key ] ) );
			}
		}

		return null;
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

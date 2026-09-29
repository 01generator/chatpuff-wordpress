<?php
/**
 * The ChatPuff inbox in the WordPress admin.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * A WordPress user links their account to their ChatPuff account once; after that, the plugin asks
 * ChatPuff for 15-minute staff tokens that the inbox script uses (api-contract.md §7.3). ChatPuff
 * decides what the person may do; the plugin only vouches for which user is asking.
 */
final class Back_Office_Inbox {

	/**
	 * The pairing service.
	 *
	 * @var Pairing
	 */
	private $pairing;

	/**
	 * An inbox service.
	 *
	 * @param Pairing $pairing the pairing service.
	 */
	public function __construct( Pairing $pairing ) {
		$this->pairing = $pairing;
	}

	/**
	 * A staff token for the user.
	 *
	 * @param int $user_id the WordPress user.
	 *
	 * @return array<string, string> state "ready" with access_token, expires_at and shop_id, or state
	 *                               "not_linked" or "no_access"
	 *
	 * @throws Api_Exception When ChatPuff refuses or cannot be reached.
	 */
	public function token( int $user_id ): array {
		try {
			$response = $this->pairing->send( 'POST', '/integration/v1/staff-sessions', array( 'external_employee_id' => (string) $user_id ) );
		} catch ( Api_Exception $exception ) {
			if ( 'employee_not_linked' === $exception->get_problem_code() ) {
				return array( 'state' => 'not_linked' );
			}
			if ( 403 === $exception->getCode() ) {
				return array( 'state' => 'no_access' );
			}

			throw $exception;
		}

		return array(
			'state'        => 'ready',
			'access_token' => (string) ( $response['access_token'] ?? '' ),
			'expires_at'   => (string) ( $response['expires_at'] ?? '' ),
			'shop_id'      => (string) ( $response['shop_id'] ?? '' ),
		);
	}

	/**
	 * A single-use link, valid for 10 minutes, where the user signs in to ChatPuff and confirms.
	 *
	 * @param \WP_User $user the WordPress user.
	 *
	 * @throws Api_Exception When ChatPuff refuses or cannot be reached.
	 */
	public function link_url( \WP_User $user ): string {
		$response = $this->pairing->send(
			'POST',
			'/integration/v1/employee-link-requests',
			array(
				'external_employee_id' => (string) $user->ID,
				'display_name'         => self::display_name( $user ),
			)
		);

		return (string) ( $response['link_url'] ?? '' );
	}

	/**
	 * Shown on ChatPuff's confirmation page so the person recognises their WordPress account; the
	 * first name and an initial are enough.
	 *
	 * @param \WP_User $user the WordPress user.
	 */
	public static function display_name( \WP_User $user ): string {
		$first = trim( (string) $user->first_name );
		$last  = trim( (string) $user->last_name );
		$name  = trim( $first . ( '' !== $last ? ' ' . mb_substr( $last, 0, 1 ) . '.' : '' ) );
		if ( '' === $name ) {
			$name = trim( (string) $user->display_name );
		}

		/* translators: %d: the WordPress user ID */
		return '' !== $name ? mb_substr( $name, 0, 100 ) : sprintf( __( 'User %d', 'chatpuff' ), $user->ID );
	}
}

<?php
/**
 * A ChatPuff API call that failed.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the problem code ChatPuff answered with (api-contract.md §11), or network_error.
 */
final class Api_Exception extends \RuntimeException {

	/**
	 * The problem code.
	 *
	 * @var string
	 */
	private $problem_code;

	/**
	 * The error reference to give ChatPuff support.
	 *
	 * @var string
	 */
	private $reference;

	/**
	 * A failed call.
	 *
	 * @param string $problem_code the problem code, or network_error.
	 * @param int    $status       the HTTP status, 0 without an answer.
	 * @param string $reference    ChatPuff's error reference.
	 */
	public function __construct( string $problem_code, int $status, string $reference = '' ) {
		parent::__construct( sprintf( 'ChatPuff answered %d %s', $status, $problem_code ), $status );
		$this->problem_code = $problem_code;
		$this->reference    = $reference;
	}

	/**
	 * The problem code.
	 */
	public function get_problem_code(): string {
		return $this->problem_code;
	}

	/**
	 * The error reference to give ChatPuff support.
	 */
	public function get_reference(): string {
		return $this->reference;
	}
}

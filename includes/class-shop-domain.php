<?php
/**
 * Normalized domains, as ChatPuff compares them.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The host of an address, in lower case, in ASCII and without "www.".
 */
final class Shop_Domain {

	/**
	 * The normalized domain of an address or host.
	 *
	 * @param string $url an address or a host.
	 */
	public static function normalize( string $url ): string {
		$host = false !== strpos( $url, '://' ) ? (string) wp_parse_url( $url, PHP_URL_HOST ) : (string) preg_replace( '~[/?#:].*$~', '', trim( $url ) );
		$host = rtrim( strtolower( $host ), '.' );
		if ( '' !== $host && 1 === preg_match( '/[^\x20-\x7e]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
			$host  = false === $ascii ? $host : $ascii;
		}
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return $host;
	}

	/**
	 * This site's domain, from its home address.
	 */
	public static function current(): string {
		return self::normalize( home_url( '/' ) );
	}
}

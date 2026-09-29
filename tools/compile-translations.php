<?php
/**
 * Compiles languages/*.po into the .mo files WordPress reads.
 *
 * Run it after changing a .po file: php tools/compile-translations.php. It has no dependencies and
 * is not part of the plugin package.
 *
 * @package ChatPuff
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.YodaConditions.NotYoda, WordPress.Security.EscapeOutput.OutputNotEscaped

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/**
 * The msgid => msgstr pairs of a .po file, with the header under the empty msgid.
 *
 * @param string $file a .po file.
 *
 * @return array<string, string>
 */
function chatpuff_read_po( string $file ): array {
	$pairs   = array();
	$msgid   = null;
	$msgstr  = null;
	$current = null;
	$flush   = static function () use ( &$pairs, &$msgid, &$msgstr ): void {
		if ( null !== $msgid && null !== $msgstr && ( '' === $msgid || '' !== $msgstr ) ) {
			$pairs[ $msgid ] = $msgstr;
		}
		$msgid  = null;
		$msgstr = null;
	};
	foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( 0 === strpos( $line, 'msgid ' ) ) {
			$flush();
			$msgid   = chatpuff_unquote( substr( $line, 6 ) );
			$current = 'msgid';
		} elseif ( 0 === strpos( $line, 'msgstr ' ) ) {
			$msgstr  = chatpuff_unquote( substr( $line, 7 ) );
			$current = 'msgstr';
		} elseif ( 0 === strpos( $line, '"' ) ) {
			if ( 'msgid' === $current ) {
				$msgid .= chatpuff_unquote( $line );
			} elseif ( 'msgstr' === $current ) {
				$msgstr .= chatpuff_unquote( $line );
			}
		}
	}
	$flush();

	return $pairs;
}

/**
 * A .po string's value.
 *
 * @param string $quoted a quoted string.
 */
function chatpuff_unquote( string $quoted ): string {
	return stripcslashes( substr( trim( $quoted ), 1, -1 ) );
}

/**
 * A .mo file (GNU gettext, little-endian) of the pairs.
 *
 * @param array<string, string> $pairs msgid => msgstr.
 */
function chatpuff_mo( array $pairs ): string {
	ksort( $pairs, SORT_STRING );
	$count     = count( $pairs );
	$ids       = '';
	$strs      = '';
	$id_table  = array();
	$str_table = array();
	foreach ( $pairs as $id => $str ) {
		$id_table[]  = array( strlen( (string) $id ), strlen( $ids ) );
		$ids        .= $id . "\0";
		$str_table[] = array( strlen( $str ), strlen( $strs ) );
		$strs       .= $str . "\0";
	}
	$ids_start  = 28 + 16 * $count;
	$strs_start = $ids_start + strlen( $ids );
	$mo         = pack( 'V7', 0x950412de, 0, $count, 28, 28 + 8 * $count, 0, $ids_start );
	foreach ( $id_table as list( $length, $offset ) ) {
		$mo .= pack( 'V2', $length, $ids_start + $offset );
	}
	foreach ( $str_table as list( $length, $offset ) ) {
		$mo .= pack( 'V2', $length, $strs_start + $offset );
	}

	return $mo . $ids . $strs;
}

foreach ( glob( dirname( __DIR__ ) . '/languages/*.po' ) as $chatpuff_po ) {
	$chatpuff_pairs = chatpuff_read_po( $chatpuff_po );
	file_put_contents( substr( $chatpuff_po, 0, -3 ) . '.mo', chatpuff_mo( $chatpuff_pairs ) );
	echo basename( $chatpuff_po ), ': ', count( $chatpuff_pairs ) - 1, " strings\n";
}

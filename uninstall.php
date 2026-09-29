<?php
/**
 * Deleting the plugin disconnects its sites from ChatPuff and removes what it stored.
 *
 * @package ChatPuff
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/load.php';

if ( ! defined( 'CHATPUFF_VERSION' ) ) {
	$chatpuff_header = get_file_data( __DIR__ . '/chatpuff.php', array( 'version' => 'Version' ) );
	define( 'CHATPUFF_VERSION', (string) $chatpuff_header['version'] );
}

/**
 * Disconnects the current site, telling ChatPuff so its owners know. Best effort: deleting the
 * plugin must work offline too. A copy of a connected site (a staging site) never disconnects the
 * live shop: it has no usable connection.
 */
function chatpuff_uninstall_site(): void {
	try {
		( new \ChatPuff\WooCommerce\Pairing( new \ChatPuff\WooCommerce\Api_Client() ) )->disconnect();
	} catch ( \ChatPuff\WooCommerce\Api_Exception $exception ) {
		// The owner sees the shop as connected until they disconnect it in ChatPuff.
		unset( $exception );
	}
	\ChatPuff\WooCommerce\Settings::delete_site_data();
	wp_clear_scheduled_hook( \ChatPuff\WooCommerce\Storefront::REPORT_HOOK );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $chatpuff_site_id ) {
		switch_to_blog( (int) $chatpuff_site_id );
		chatpuff_uninstall_site();
		restore_current_blog();
	}
} else {
	chatpuff_uninstall_site();
}

\ChatPuff\WooCommerce\Settings::delete_installation_id();

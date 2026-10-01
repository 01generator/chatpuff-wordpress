<?php
/**
 * The plugin's hooks.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress and WooCommerce.
 */
final class Plugin {

	/**
	 * Registers the hooks.
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'init' ) );
		add_action( 'rest_api_init', array( Rest_Callback::class, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( Storefront::class, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( Storefront::class, 'script_tag' ), 10, 3 );
		add_action( Storefront::REPORT_HOOK, array( Storefront::class, 'report' ) );
		add_action( Knowledge::HOOK, array( Knowledge::class, 'run' ) );
		add_action( 'before_woocommerce_init', array( self::class, 'declare_compatibility' ) );
		if ( is_admin() ) {
			Admin::register();
		}
	}

	/**
	 * Translations, and the hourly report and knowledge synchronization while connected.
	 */
	public static function init(): void {
		load_plugin_textdomain( 'chatpuff', false, dirname( plugin_basename( CHATPUFF_FILE ) ) . '/languages' );
		Storefront::schedule_report();
		Knowledge::schedule();
	}

	/**
	 * The plugin reads no orders, so it works with WooCommerce's order tables and its block cart
	 * and checkout alike.
	 */
	public static function declare_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CHATPUFF_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CHATPUFF_FILE, true );
		}
	}

	/**
	 * Stops the hourly report and the knowledge synchronization; the connection stays, so
	 * reactivating needs no new pairing.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Storefront::REPORT_HOOK );
		wp_clear_scheduled_hook( Knowledge::HOOK );
	}

	/**
	 * Whether WooCommerce is active.
	 */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * WooCommerce's version, or "0" without it.
	 */
	public static function woocommerce_version(): string {
		return defined( 'WC_VERSION' ) ? Pairing::version_token( (string) constant( 'WC_VERSION' ) ) : '0';
	}
}

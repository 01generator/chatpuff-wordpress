<?php
/**
 * The chat on the storefront, and the hourly report.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the chat widget's script to every storefront page of a connected site (api-contract.md §7.1).
 * ChatPuff shows the chat only once the owner publishes it, and the script loads async, so it never
 * slows the page down or breaks it.
 */
final class Storefront {

	public const SCRIPT_HANDLE = 'chatpuff-loader';
	public const REPORT_HOOK   = 'chatpuff_report';

	/**
	 * Enqueues the loader on storefront pages. Themes and page builders all print the footer
	 * scripts, so the chat appears whatever builds the page.
	 */
	public static function enqueue(): void {
		if ( null === self::widget() ) {
			return;
		}
		// No version: ChatPuff serves the loader with a short cache lifetime and versions the chat itself.
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( self::SCRIPT_HANDLE, ( new Api_Client() )->base_url() . '/widget/v1/loader.js', array(), null, true );
	}

	/**
	 * The loader's tag with the shop's details. Cloudflare's Rocket Loader leaves it alone
	 * (data-cfasync), and when an optimiser still moves it, the loader finds its tag by src and
	 * data-shop.
	 *
	 * @param string $tag    the tag WordPress built.
	 * @param string $handle the script's handle.
	 * @param string $src    the script's address.
	 */
	public static function script_tag( $tag, $handle, $src ): string {
		if ( self::SCRIPT_HANDLE !== $handle ) {
			return (string) $tag;
		}
		$widget = self::widget();
		if ( null === $widget ) {
			return '';
		}

		$attributes = array(
			'data-shop'   => $widget['shop_id'],
			'data-locale' => Pairing::language( determine_locale() ),
		);
		// The chat links to it from its notice about the name and email it asks for.
		$privacy = get_privacy_policy_url();
		if ( '' !== $privacy ) {
			$attributes['data-privacy-url'] = $privacy;
		}
		$token = self::customer_token();
		if ( null !== $token ) {
			$attributes['data-customer-token'] = $token;
		}

		// The enqueued script's own tag, rebuilt with its attributes (script_loader_tag).
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
		$html = '<script async data-cfasync="false" src="' . esc_url( (string) $src ) . '"';
		foreach ( $attributes as $name => $value ) {
			$html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}

		// Output built from escaped values only.
		return $html . '></script>' . "\n";
	}

	/**
	 * Keeps the hourly report scheduled while the site is connected, and not on a copy.
	 */
	public static function schedule_report(): void {
		$scheduled = wp_next_scheduled( self::REPORT_HOOK );
		if ( null !== Settings::widget() && ! Pairing::is_copy() ) {
			if ( false === $scheduled ) {
				wp_schedule_event( time() + 60, 'hourly', self::REPORT_HOOK );
			}
		} elseif ( false !== $scheduled ) {
			wp_clear_scheduled_hook( self::REPORT_HOOK );
		}
	}

	/**
	 * The hourly installation report, which is also the connection's heartbeat (api-contract.md §7).
	 * WP-Cron sends it in the background, so ChatPuff being slow or unreachable never delays a page.
	 */
	public static function report(): void {
		if ( null === Settings::widget() || Pairing::is_copy() || ! Settings::claim_report( time() ) ) {
			return;
		}
		try {
			( new Pairing( new Api_Client() ) )->report_installation();
		} catch ( Api_Exception $exception ) {
			// The next report is due in an hour; the chat works either way.
			unset( $exception );
		}
	}

	/**
	 * The shop's widget details, only on the domain the site was connected with: a copy of the shop
	 * (a staging site) never shows the live shop's chat.
	 *
	 * @return array{shop_id: string, domain: string}|null
	 */
	private static function widget(): ?array {
		$widget = Settings::widget();
		if ( null === $widget || Shop_Domain::current() !== $widget['domain'] ) {
			return null;
		}

		return $widget;
	}

	/**
	 * A logged-in customer skips the chat's name and email form: the page carries a token, signed
	 * with this site's connection key, that says who they are (api-contract.md §7.2). Guests who
	 * check out without an account are not logged in and get no token. Page caches do not keep
	 * pages of logged-in users, so a token is never served to someone else.
	 */
	private static function customer_token(): ?string {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		$user       = wp_get_current_user();
		$connection = Settings::connection();
		if ( ! $user->exists() || '' === (string) $user->user_email || null === $connection ) {
			return null;
		}

		return Customer_Token::create(
			$connection,
			Settings::installation_id(),
			Pairing::shop_context(),
			(string) $user->ID,
			self::customer_name( $user ),
			(string) $user->user_email,
			time()
		);
	}

	/**
	 * The customer's name: the account's first and last name, else the billing name, else the name
	 * shown on the site.
	 *
	 * @param \WP_User $user the customer.
	 */
	private static function customer_name( \WP_User $user ): string {
		$name = trim( $user->first_name . ' ' . $user->last_name );
		if ( '' === $name ) {
			$name = trim( get_user_meta( $user->ID, 'billing_first_name', true ) . ' ' . get_user_meta( $user->ID, 'billing_last_name', true ) );
		}

		return '' !== $name ? $name : (string) $user->display_name;
	}
}

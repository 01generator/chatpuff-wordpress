<?php
/**
 * The ChatPuff pages in the WordPress admin.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Two pages under the ChatPuff menu: the inbox, for the shop's team, and the settings, where an
 * administrator connects the site. Forms work without JavaScript; with it, Connect opens ChatPuff in
 * a new tab and the page follows the confirmation by itself.
 */
final class Admin {

	/** Who may answer chats here. Granted to shop managers and administrators; role editors can grant it to others. */
	public const INBOX_CAPABILITY = 'chatpuff_inbox';

	/** Who may connect and disconnect the site. */
	public const SETTINGS_CAPABILITY = 'manage_options';

	public const INBOX_PAGE    = 'chatpuff';
	public const SETTINGS_PAGE = 'chatpuff-settings';

	private const SETTINGS_NONCE = 'chatpuff_settings';
	private const INBOX_NONCE    = 'chatpuff_inbox';

	/**
	 * Registers the admin hooks.
	 */
	public static function register(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant_inbox' ), 10, 1 );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CHATPUFF_FILE ), array( self::class, 'action_links' ) );

		foreach ( array( 'connect', 'disconnect', 'forget_copy', 'forget_unreadable', 'sync_knowledge' ) as $action ) {
			add_action( 'admin_post_chatpuff_' . $action, array( self::class, 'post_' . $action ) );
		}
		add_action( 'wp_ajax_chatpuff_start_pairing', array( self::class, 'ajax_start_pairing' ) );
		add_action( 'wp_ajax_chatpuff_pairing_status', array( self::class, 'ajax_pairing_status' ) );
		add_action( 'wp_ajax_chatpuff_staff_token', array( self::class, 'ajax_staff_token' ) );
		add_action( 'wp_ajax_chatpuff_employee_link', array( self::class, 'ajax_employee_link' ) );
		add_action( 'wp_ajax_chatpuff_sync_knowledge', array( self::class, 'ajax_sync_knowledge' ) );
	}

	/**
	 * Everyone who manages the WooCommerce shop may use the inbox.
	 *
	 * @param array<string, bool> $allcaps the user's capabilities.
	 *
	 * @return array<string, bool>
	 */
	public static function grant_inbox( $allcaps ): array {
		$allcaps = (array) $allcaps;
		if ( ! empty( $allcaps['manage_woocommerce'] ) || ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::INBOX_CAPABILITY ] = true;
		}

		return $allcaps;
	}

	/**
	 * The ChatPuff menu, below WooCommerce.
	 */
	public static function menu(): void {
		add_menu_page( __( 'ChatPuff inbox', 'chatpuff' ), 'ChatPuff', self::INBOX_CAPABILITY, self::INBOX_PAGE, array( self::class, 'render_inbox' ), 'dashicons-format-chat', 56 );
		add_submenu_page( self::INBOX_PAGE, __( 'ChatPuff inbox', 'chatpuff' ), __( 'Inbox', 'chatpuff' ), self::INBOX_CAPABILITY, self::INBOX_PAGE, array( self::class, 'render_inbox' ) );
		add_submenu_page( self::INBOX_PAGE, __( 'ChatPuff settings', 'chatpuff' ), __( 'Settings', 'chatpuff' ), self::SETTINGS_CAPABILITY, self::SETTINGS_PAGE, array( self::class, 'render_settings' ) );
	}

	/**
	 * A Settings link on the Plugins page.
	 *
	 * @param array<int|string, string> $links the plugin's links.
	 *
	 * @return array<int|string, string>
	 */
	public static function action_links( $links ): array {
		$links = (array) $links;
		if ( current_user_can( self::SETTINGS_CAPABILITY ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'chatpuff' ) . '</a>' );
		}

		return $links;
	}

	/**
	 * The plugin's styles and script, on its own pages only. The version makes browsers fetch them
	 * again after an update.
	 *
	 * @param string $hook_suffix the admin page.
	 */
	public static function assets( $hook_suffix ): void {
		self::badge();
		if ( false === strpos( (string) $hook_suffix, self::INBOX_PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'chatpuff-admin', plugins_url( 'assets/admin.css', CHATPUFF_FILE ), array(), CHATPUFF_VERSION );
		wp_enqueue_script( 'chatpuff-admin', plugins_url( 'assets/admin.js', CHATPUFF_FILE ), array(), CHATPUFF_VERSION, true );
	}

	/**
	 * On every admin page of a user who may open the inbox, while the site is connected: the badge
	 * next to ChatPuff and Inbox in the menu with what waits in ChatPuff (api-contract.md §9), kept
	 * fresh every minute, and a chime when a chat starts waiting. The script asks for a staff token
	 * the way the inbox does; a user whose account is not linked is left alone for an hour.
	 */
	private static function badge(): void {
		if ( ! current_user_can( self::INBOX_CAPABILITY ) || null === Settings::connection() || Pairing::is_copy() ) {
			return;
		}
		wp_enqueue_script( 'chatpuff-badge', plugins_url( 'assets/badge.js', CHATPUFF_FILE ), array(), CHATPUFF_VERSION, true );
		$setup = array(
			'token_url' => self::ajax_url( 'chatpuff_staff_token', wp_create_nonce( self::INBOX_NONCE ) ),
			'api'       => ( new Api_Client() )->base_url(),
		);
		wp_add_inline_script( 'chatpuff-badge', 'window.chatpuffBadge = ' . (string) wp_json_encode( $setup ) . ';', 'before' );
	}

	/**
	 * Connect, without JavaScript: the page then shows the link to ChatPuff.
	 */
	public static function post_connect(): void {
		self::settings_action(
			static function ( Pairing $pairing ): void {
				$pairing->start();
			}
		);
	}

	/**
	 * Disconnect the site.
	 */
	public static function post_disconnect(): void {
		self::settings_action(
			static function ( Pairing $pairing ): void {
				$pairing->disconnect();
			}
		);
	}

	/**
	 * On a copy of the site: forget the live site's connection.
	 */
	public static function post_forget_copy(): void {
		self::settings_action(
			static function ( Pairing $pairing ): void {
				$pairing->forget_copy();
			}
		);
	}

	/**
	 * Forget a connection whose key can no longer be read, and connect again.
	 */
	public static function post_forget_unreadable(): void {
		self::settings_action(
			static function ( Pairing $pairing ): void {
				$pairing->forget_unreadable();
				$pairing->start();
			}
		);
	}

	/**
	 * One step of the knowledge synchronization for assets/admin.js, which calls it until the pass
	 * is complete and draws the progress; each step runs for a few seconds.
	 */
	public static function ajax_sync_knowledge(): void {
		self::json(
			self::SETTINGS_CAPABILITY,
			self::SETTINGS_NONCE,
			static function (): array {
				if ( function_exists( 'set_time_limit' ) ) {
					set_time_limit( Knowledge::STEP_BUDGET + 20 );
				}
				$knowledge = new Knowledge( new Api_Client() );
				$cursor    = $knowledge->pass( Knowledge::STEP_BUDGET );
				$progress  = $knowledge->progress();

				return array(
					'state' => null !== $cursor['error'] ? 'failed' : ( null === $cursor['kind'] ? 'complete' : 'running' ),
					'error' => (string) $cursor['error'],
					'done'  => (string) $progress['done'],
					'total' => (string) $progress['total'],
					'sent'  => (string) $cursor['sent'],
				);
			}
		);
	}

	/**
	 * A run of the knowledge synchronization now, with a larger budget than WP-Cron gives it
	 * (api-contract.md §7.6): the form without JavaScript; the page then shows where the pass stands.
	 */
	public static function post_sync_knowledge(): void {
		self::settings_action(
			static function ( Pairing $pairing ): void {
				unset( $pairing );
				if ( function_exists( 'set_time_limit' ) ) {
					set_time_limit( Knowledge::ADMIN_BUDGET + 30 );
				}
				$cursor = ( new Knowledge( new Api_Client() ) )->pass( Knowledge::ADMIN_BUDGET );
				if ( null !== $cursor['error'] ) {
					throw new Api_Exception( esc_html( $cursor['error'] ), 0 );
				}
			}
		);
	}

	/**
	 * The Connect button with JavaScript: assets/admin.js opens the returned link in a new tab.
	 */
	public static function ajax_start_pairing(): void {
		self::json(
			self::SETTINGS_CAPABILITY,
			self::SETTINGS_NONCE,
			static function (): array {
				return array( 'confirmation_url' => self::pairing()->start() );
			}
		);
	}

	/**
	 * Polled every three seconds by assets/admin.js while the owner confirms in ChatPuff.
	 */
	public static function ajax_pairing_status(): void {
		self::json(
			self::SETTINGS_CAPABILITY,
			self::SETTINGS_NONCE,
			static function (): array {
				return self::pairing()->poll();
			}
		);
	}

	/**
	 * A staff token for the inbox (api-contract.md §7.3), asked for by assets/admin.js when the page
	 * opens and before the token expires.
	 */
	public static function ajax_staff_token(): void {
		self::json(
			self::INBOX_CAPABILITY,
			self::INBOX_NONCE,
			static function (): array {
				return ( new Back_Office_Inbox( self::pairing() ) )->token( get_current_user_id() );
			}
		);
	}

	/**
	 * The link that opens in a ChatPuff window, where the user signs in and confirms.
	 */
	public static function ajax_employee_link(): void {
		self::json(
			self::INBOX_CAPABILITY,
			self::INBOX_NONCE,
			static function (): array {
				return array(
					'state'    => 'linking',
					'link_url' => ( new Back_Office_Inbox( self::pairing() ) )->link_url( wp_get_current_user() ),
				);
			}
		);
	}

	/**
	 * The inbox page.
	 */
	public static function render_inbox(): void {
		echo '<div class="wrap chatpuff-wrap">';
		self::heading( __( 'ChatPuff inbox', 'chatpuff' ) );

		if ( null === Settings::connection() || Pairing::is_copy() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This site is not connected to ChatPuff yet.', 'chatpuff' ) . '</p></div>';
			if ( current_user_can( self::SETTINGS_CAPABILITY ) ) {
				echo '<p><a class="button button-primary" href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Connect to ChatPuff', 'chatpuff' ) . '</a></p>';
			} else {
				echo '<p>' . esc_html__( 'Ask an administrator of this site to connect it in ChatPuff > Settings.', 'chatpuff' ) . '</p>';
			}
			echo '</div>';

			return;
		}

		$nonce = wp_create_nonce( self::INBOX_NONCE );
		?>
		<div class="chatpuff-backoffice"
			data-chatpuff-backoffice
			data-token-url="<?php echo esc_url( self::ajax_url( 'chatpuff_staff_token', $nonce ) ); ?>"
			data-link-url="<?php echo esc_url( self::ajax_url( 'chatpuff_employee_link', $nonce ) ); ?>"
			data-api="<?php echo esc_url( ( new Api_Client() )->base_url() ); ?>"
			data-locale="<?php echo esc_attr( Pairing::language( get_user_locale() ) ); ?>"
			data-order-url="<?php echo esc_attr( Order_Callback::admin_order_url() ); ?>"
			data-popup-blocked="<?php esc_attr_e( 'Your browser blocked the ChatPuff window. Allow pop-ups for this site and try again.', 'chatpuff' ); ?>"
			data-failed="<?php esc_attr_e( 'The ChatPuff inbox could not be loaded. Check your internet connection and reload the page.', 'chatpuff' ); ?>">
			<p class="chatpuff-muted" data-chatpuff-when="loading"><span class="spinner is-active"></span> <?php esc_html_e( 'Loading the inbox…', 'chatpuff' ); ?></p>
			<div class="card chatpuff-card" data-chatpuff-when="not_linked" hidden>
				<h2><?php esc_html_e( 'Link your ChatPuff account', 'chatpuff' ); ?></h2>
				<p><?php esc_html_e( 'To answer chats here, link your WordPress account to your ChatPuff account once. A ChatPuff window opens where you sign in and confirm.', 'chatpuff' ); ?></p>
				<p><button type="button" class="button button-primary" data-chatpuff-link><?php esc_html_e( 'Link my ChatPuff account', 'chatpuff' ); ?></button></p>
			</div>
			<p class="chatpuff-muted" data-chatpuff-when="linking" hidden><span class="spinner is-active"></span> <?php esc_html_e( 'Waiting for you to confirm in the ChatPuff window…', 'chatpuff' ); ?></p>
			<div data-chatpuff-when="no_access" hidden>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Your ChatPuff account has no access to this shop\'s chats. Ask an owner or admin of your ChatPuff organization to give you access, or link another account.', 'chatpuff' ); ?></p></div>
				<p><button type="button" class="button" data-chatpuff-link><?php esc_html_e( 'Link another account', 'chatpuff' ); ?></button></p>
			</div>
			<div class="notice notice-error inline" data-chatpuff-when="error" hidden></div>
			<div class="chatpuff-inbox" data-chatpuff-when="ready" hidden></div>
			<noscript><p><?php esc_html_e( 'The inbox needs JavaScript.', 'chatpuff' ); ?></p></noscript>
		</div>
		</div>
		<?php
	}

	/**
	 * The settings page: the connection's state and what can be done about it.
	 */
	public static function render_settings(): void {
		if ( ! current_user_can( self::SETTINGS_CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'chatpuff' ), 403 );
		}

		echo '<div class="wrap chatpuff-wrap">';
		self::heading( __( 'ChatPuff settings', 'chatpuff' ) );
		self::render_notices();

		$pairing = self::pairing();
		$domain  = Shop_Domain::current();
		$request = Settings::pairing();

		if ( ! Plugin::woocommerce_active() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'ChatPuff needs WooCommerce. Activate WooCommerce, then connect the shop here.', 'chatpuff' ) . '</p></div></div>';

			return;
		}
		if ( 'https' !== wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This site does not use HTTPS. ChatPuff needs HTTPS to check the shop when you connect it.', 'chatpuff' ) . '</p></div>';
		}

		echo '<div class="card chatpuff-card">';
		if ( Settings::connection_unreadable() ) {
			self::render_unreadable();
		} elseif ( null !== Settings::connection() && Pairing::is_copy() ) {
			self::render_copy( $domain );
		} elseif ( null !== Settings::connection() ) {
			self::render_connected( $pairing, $domain );
		} elseif ( null !== $request ) {
			$result = $pairing->poll();
			if ( 'connected' === $result['state'] ) {
				self::render_connected( $pairing, $domain );
			} elseif ( 'pending' === $result['state'] ) {
				self::render_pending( (string) ( $request['confirmation_url'] ?? '' ) );
			} else {
				self::render_connect( $domain, $result['state'], (string) ( $result['code'] ?? '' ) );
			}
		} else {
			self::render_connect( $domain, 'none', '' );
		}
		echo '</div></div>';
	}

	/**
	 * The page title with the ChatPuff icon.
	 *
	 * @param string $title the title.
	 */
	private static function heading( string $title ): void {
		echo '<h1 class="chatpuff-title"><img src="' . esc_url( plugins_url( 'assets/icon.png', CHATPUFF_FILE ) ) . '" alt="" width="28" height="28"> ' . esc_html( $title ) . '</h1>';
	}

	/**
	 * The outcome of the last form, carried in the address after the redirect.
	 */
	private static function render_notices(): void {
		// Codes and references only, set by this plugin's own redirects; nothing is changed here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['chatpuff_error'] ) ) {
			$code      = sanitize_key( wp_unslash( $_GET['chatpuff_error'] ) );
			$reference = isset( $_GET['chatpuff_reference'] ) ? sanitize_text_field( wp_unslash( $_GET['chatpuff_reference'] ) ) : '';
			echo '<div class="notice notice-error"><p>' . esc_html( self::error_message( $code, $reference ) ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The site is connected: its state in ChatPuff, the dashboard and Disconnect.
	 *
	 * @param Pairing $pairing the pairing service.
	 * @param string  $domain  the site's domain.
	 */
	private static function render_connected( Pairing $pairing, string $domain ): void {
		$status = null;
		$error  = null;
		try {
			$status = $pairing->connection_status();
			// When the page opens after an update, ChatPuff learns the new version at once.
			if ( Settings::claim_report( time() ) ) {
				$pairing->report_installation();
			}
		} catch ( Api_Exception $exception ) {
			$error = self::error_message( $exception->get_problem_code(), $exception->get_reference() );
		}
		if ( null === Settings::connection() ) {
			// ChatPuff says the connection is gone: disconnected from the dashboard.
			self::render_connect( $domain, 'none', '' );

			return;
		}
		if ( null !== $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}

		$connection = Settings::connection();
		// The shop's setup page in ChatPuff, where the owner publishes the chat widget.
		$dashboard = rtrim( (string) ( $connection['dashboard_url'] ?? 'https://app.chatpuff.com' ), '/' );
		if ( is_string( $connection['shop_id'] ?? null ) && '' !== $connection['shop_id'] ) {
			$dashboard .= '/shops/' . rawurlencode( $connection['shop_id'] );
		}

		echo '<div class="notice notice-success inline"><p>' . esc_html__( 'This shop is connected to ChatPuff.', 'chatpuff' ) . '</p></div>';
		if ( null !== $status ) {
			echo '<dl class="chatpuff-details">';
			echo '<dt>' . esc_html__( 'Organization', 'chatpuff' ) . '</dt><dd>' . esc_html( (string) ( $status['organization_name'] ?? '' ) ) . '</dd>';
			echo '<dt>' . esc_html__( 'Shop', 'chatpuff' ) . '</dt><dd>' . esc_html( (string) ( $status['shop_name'] ?? '' ) . ' (' . $domain . ')' ) . '</dd>';
			echo '<dt>' . esc_html__( 'Chat widget', 'chatpuff' ) . '</dt><dd>' . ( ! empty( $status['widget_published'] ) ? esc_html__( 'Published', 'chatpuff' ) : esc_html__( 'Not published yet: publish it from the ChatPuff dashboard when you are ready.', 'chatpuff' ) ) . '</dd>';
			echo '<dt>' . esc_html__( 'Privacy policy page', 'chatpuff' ) . '</dt><dd>';
			$privacy = get_privacy_policy_url();
			if ( '' !== $privacy ) {
				echo '<a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html( $privacy ) . '</a>';
			} else {
				esc_html_e( 'None', 'chatpuff' );
			}
			echo '<br><span class="chatpuff-muted">' . esc_html__( 'The chat links to this page where it asks customers for their name and email.', 'chatpuff' ) . ' ';
			if ( current_user_can( 'manage_privacy_options' ) ) {
				echo '<a href="' . esc_url( admin_url( 'options-privacy.php' ) ) . '">' . esc_html__( 'Choose it in Settings > Privacy.', 'chatpuff' ) . '</a>';
			}
			echo '</span></dd></dl>';
		}
		self::render_knowledge();
		echo '<p><a class="button button-primary" href="' . esc_url( $dashboard ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the ChatPuff dashboard', 'chatpuff' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::INBOX_PAGE ) ) . '">' . esc_html__( 'Open the inbox', 'chatpuff' ) . '</a></p>';
		self::form( 'disconnect', __( 'Disconnect', 'chatpuff' ), 'button', __( 'Disconnect this shop from ChatPuff? Its chat history stays in ChatPuff.', 'chatpuff' ) );
	}

	/**
	 * What the plugin sends for the AI assistant's knowledge, and where the synchronization stands.
	 */
	private static function render_knowledge(): void {
		$cursor = Knowledge::cursor();
		echo '<h2 class="chatpuff-subtitle">' . esc_html__( 'Knowledge for the AI assistant', 'chatpuff' ) . '</h2>';
		echo '<p class="chatpuff-muted">' . esc_html__( 'The plugin sends this shop\'s published products, product categories and pages to ChatPuff every hour, so the assistant can answer from them. Choose what it may use on the ChatPuff dashboard; nothing about customers is sent.', 'chatpuff' ) . '</p>';
		echo '<p>';
		if ( null !== $cursor['completed_at'] ) {
			/* translators: %s: a date and time */
			echo esc_html( sprintf( __( 'Last complete synchronization: %s.', 'chatpuff' ), wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $cursor['completed_at'] ) ) );
		} else {
			esc_html_e( 'Not synchronized yet.', 'chatpuff' );
		}
		if ( null !== $cursor['kind'] ) {
			echo ' ' . esc_html__( 'A synchronization is in progress and continues in the background.', 'chatpuff' );
		}
		if ( null !== $cursor['error'] ) {
			/* translators: %s: an error code */
			echo ' <span class="chatpuff-error">' . esc_html( sprintf( __( 'The last attempt failed (%s); it is retried automatically.', 'chatpuff' ), $cursor['error'] ) ) . '</span>';
		}
		echo '</p>';
		echo '<div data-chatpuff-sync="' . esc_url( self::ajax_url( 'chatpuff_sync_knowledge', wp_create_nonce( self::SETTINGS_NONCE ) ) ) . '"'
			. ' data-label-progress="' . esc_attr__( 'Checked {done} of {total} items ({percent} %)…', 'chatpuff' ) . '"'
			. ' data-label-complete="' . esc_attr__( 'Synchronization complete: {sent} items sent to ChatPuff.', 'chatpuff' ) . '"'
			. ' data-label-error="' . esc_attr__( 'The synchronization stopped with the error {code}. It is retried automatically.', 'chatpuff' ) . '">';
		self::form( 'sync_knowledge', __( 'Synchronize now', 'chatpuff' ), 'button' );
		echo '<div class="chatpuff-progress" data-chatpuff-sync-bar role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><div class="chatpuff-progress-fill"></div></div>';
		echo '<p class="chatpuff-muted" data-chatpuff-sync-label aria-live="polite" hidden></p>';
		echo '</div>';
	}

	/**
	 * The site's address is not the one it was connected with: a copy, such as a staging site.
	 *
	 * @param string $domain the site's domain.
	 */
	private static function render_copy( string $domain ): void {
		$widget = Settings::widget();
		echo '<div class="notice notice-warning inline"><p>';
		printf(
			/* translators: 1: this site's address, 2: the address it was connected with */
			esc_html__( 'This site\'s address (%1$s) is not the one it was connected with (%2$s). It looks like a copy of your shop, such as a staging site, so the connection is paused here: a copy must never act for your live shop.', 'chatpuff' ),
			esc_html( $domain ),
			esc_html( null !== $widget ? $widget['domain'] : '' )
		);
		echo '</p></div>';
		echo '<p>' . esc_html__( 'Disconnecting this copy leaves your live shop connected. You can then connect this copy as a separate shop.', 'chatpuff' ) . '</p>';
		self::form( 'forget_copy', __( 'Disconnect this copy', 'chatpuff' ), 'button' );
	}

	/**
	 * The stored key can no longer be read.
	 */
	private static function render_unreadable(): void {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The ChatPuff key of this site can no longer be read, because the site\'s security keys (in wp-config.php) changed. Connect again: the shop keeps its chats and settings in ChatPuff.', 'chatpuff' ) . '</p></div>';
		self::form( 'forget_unreadable', __( 'Connect again', 'chatpuff' ), 'button button-primary', '', true );
	}

	/**
	 * A pairing waits for the owner's confirmation.
	 *
	 * @param string $confirmation_url ChatPuff's confirmation page.
	 */
	private static function render_pending( string $confirmation_url ): void {
		echo '<h2>' . esc_html__( 'Confirm the connection in ChatPuff', 'chatpuff' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sign in to ChatPuff, or create a free account, and confirm this shop. This page updates by itself.', 'chatpuff' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $confirmation_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open ChatPuff to confirm', 'chatpuff' ) . '</a></p>';
		echo '<p class="chatpuff-muted" data-chatpuff-poll="' . esc_url( self::ajax_url( 'chatpuff_pairing_status', wp_create_nonce( self::SETTINGS_NONCE ) ) ) . '"><span class="spinner is-active"></span> ' . esc_html__( 'Waiting for your confirmation…', 'chatpuff' ) . '</p>';
		self::form( 'connect', __( 'Start again', 'chatpuff' ), 'button-link', '', true );
	}

	/**
	 * Not connected: why a previous attempt failed, and Connect.
	 *
	 * @param string $domain the site's domain.
	 * @param string $state  none, rejected or expired.
	 * @param string $code   the rejection's reason.
	 */
	private static function render_connect( string $domain, string $state, string $code ): void {
		if ( 'rejected' === $state ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( self::rejection_message( $code ) ) . '</p></div>';
			if ( 'domain_verification_failed' === $code ) {
				self::render_verification_help();
			}
		} elseif ( 'expired' === $state ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The confirmation link expired before it was used. Please start again.', 'chatpuff' ) . '</p></div>';
		}
		echo '<h2>' . esc_html__( 'Connect this shop to ChatPuff', 'chatpuff' ) . '</h2>';
		echo '<p>' . esc_html__( 'Chat live with your customers from this WordPress admin, the ChatPuff dashboard or your phone. Connecting takes a minute: sign in to ChatPuff, or create a free account, and confirm the shop.', 'chatpuff' ) . '</p>';
		/* translators: %s: the shop's domain */
		echo '<p class="chatpuff-muted">' . esc_html( sprintf( __( 'Shop address: %s', 'chatpuff' ), $domain ) ) . '</p>';
		self::form( 'connect', __( 'Connect to ChatPuff', 'chatpuff' ), 'button button-primary', '', true );
	}

	/**
	 * What to do when ChatPuff could not reach the site to check it.
	 */
	private static function render_verification_help(): void {
		echo '<div class="chatpuff-help">';
		echo '<p>' . esc_html__( 'ChatPuff checks the shop by calling this address:', 'chatpuff' ) . '<br><code>' . esc_html( Pairing::callback_url() ) . '</code></p>';
		echo '<p>' . esc_html__( 'Behind Cloudflare, the bot protection often blocks this check. In Cloudflare, add a custom rule (Security > WAF > Custom rules, or Security rules in the newer dashboard) with this expression:', 'chatpuff' ) . '</p>';
		echo '<pre class="chatpuff-rule">(http.request.uri contains "/chatpuff/v1/callback")</pre>';
		echo '<p>' . esc_html__( 'Choose the action Skip. Tick "All remaining custom rules", and under the other components "Security Level" and "Browser Integrity Check". Place the rule first, save it, then connect again.', 'chatpuff' ) . '</p>';
		echo '<p>' . esc_html__( 'Cloudflare\'s Bot Fight Mode cannot be skipped by a rule: switch it off while you connect. A security plugin that blocks the WordPress REST API for visitors, a maintenance-mode plugin, another firewall or a password on the site must let requests to this address through.', 'chatpuff' ) . '</p>';
		echo '</div>';
	}

	/**
	 * A form that posts one of the plugin's actions.
	 *
	 * @param string $action  connect, disconnect, forget_copy or forget_unreadable.
	 * @param string $label   the button.
	 * @param string $classes the button's classes.
	 * @param string $confirm a question to confirm first, or nothing.
	 * @param bool   $starts  whether it starts a pairing: with JavaScript, ChatPuff then opens in a new tab.
	 */
	private static function form( string $action, string $label, string $classes, string $confirm = '', bool $starts = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="chatpuff-inline"';
		if ( '' !== $confirm ) {
			echo ' data-chatpuff-confirm="' . esc_attr( $confirm ) . '"';
		}
		if ( $starts && 'forget_unreadable' !== $action ) {
			echo ' data-chatpuff-connect="' . esc_url( self::ajax_url( 'chatpuff_start_pairing', wp_create_nonce( self::SETTINGS_NONCE ) ) ) . '"';
		}
		echo '>';
		wp_nonce_field( self::SETTINGS_NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( 'chatpuff_' . $action ) . '">';
		echo '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Runs a settings form's action, then returns to the settings page.
	 *
	 * @param \Closure $action what to do, given the pairing service.
	 */
	private static function settings_action( \Closure $action ): void {
		if ( ! current_user_can( self::SETTINGS_CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'chatpuff' ), 403 );
		}
		check_admin_referer( self::SETTINGS_NONCE );

		$url = self::settings_url();
		try {
			$action( self::pairing() );
		} catch ( Api_Exception $exception ) {
			$url = add_query_arg(
				array(
					'chatpuff_error'     => rawurlencode( $exception->get_problem_code() ),
					'chatpuff_reference' => rawurlencode( $exception->get_reference() ),
				),
				$url
			);
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Answers an admin-ajax call with JSON.
	 *
	 * @param string   $capability who may call it.
	 * @param string   $nonce      the nonce action.
	 * @param \Closure $action     what to do; returns the answer.
	 */
	private static function json( string $capability, string $nonce, \Closure $action ): void {
		if ( ! current_user_can( $capability ) || false === check_ajax_referer( $nonce, false, false ) ) {
			$result = array(
				'state' => 'error',
				'error' => __( 'Your session expired. Please reload the page and try again.', 'chatpuff' ),
			);
		} else {
			try {
				$result = $action();
			} catch ( Api_Exception $exception ) {
				$result = array(
					'state'     => 'error',
					'error'     => self::error_message( $exception->get_problem_code(), $exception->get_reference() ),
					'code'      => $exception->get_problem_code(),
					'reference' => $exception->get_reference(),
				);
			}
		}

		nocache_headers();
		wp_send_json( $result );
	}

	/**
	 * An admin-ajax address with its action and nonce.
	 *
	 * @param string $action the ajax action.
	 * @param string $nonce  the nonce.
	 */
	private static function ajax_url( string $action, string $nonce ): string {
		return add_query_arg(
			array(
				'action'      => $action,
				'_ajax_nonce' => $nonce,
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * The settings page's address.
	 */
	private static function settings_url(): string {
		return admin_url( 'admin.php?page=' . self::SETTINGS_PAGE );
	}

	/**
	 * The pairing service.
	 */
	private static function pairing(): Pairing {
		return new Pairing( new Api_Client() );
	}

	/**
	 * A failed call, in words.
	 *
	 * @param string $code      the problem code.
	 * @param string $reference ChatPuff's error reference.
	 */
	private static function error_message( string $code, string $reference ): string {
		if ( 'network_error' === $code ) {
			return __( 'ChatPuff could not be reached. Check that your server can make outgoing HTTPS connections, then try again.', 'chatpuff' );
		}
		if ( 'rate_limited' === $code ) {
			return __( 'Too many attempts. Please wait a few minutes and try again.', 'chatpuff' );
		}
		if ( 'not_connected' === $code ) {
			return __( 'This site is not connected to ChatPuff.', 'chatpuff' );
		}

		/* translators: 1: an error code, 2: an error reference for ChatPuff support */
		return sprintf( __( 'ChatPuff could not complete the request (%1$s). Error reference: %2$s', 'chatpuff' ), $code, '' !== $reference ? $reference : '-' );
	}

	/**
	 * Why ChatPuff refused the connection, in words.
	 *
	 * @param string $code the rejection's reason.
	 */
	private static function rejection_message( string $code ): string {
		switch ( $code ) {
			case 'domain_verification_failed':
				return __( 'ChatPuff could not reach this shop to check it. Make sure the site is online over HTTPS and not in maintenance mode, and that no firewall blocks ChatPuff. Then try again.', 'chatpuff' );
			case 'domain_already_claimed':
				return __( 'This shop address is already connected to another ChatPuff account. Contact ChatPuff support to move it.', 'chatpuff' );
			case 'installation_in_other_organization':
				return __( 'This WordPress is already connected to another ChatPuff organization. Contact ChatPuff support to move it.', 'chatpuff' );
			case 'domain_changed':
				return __( 'This shop was connected under another address. Contact ChatPuff support to change it.', 'chatpuff' );
			case 'pairing_cancelled':
				return __( 'The connection was cancelled in ChatPuff. You can start again at any time.', 'chatpuff' );
			default:
				return __( 'The shop was not connected.', 'chatpuff' );
		}
	}
}

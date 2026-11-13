<?php
/**
 * The shop's content for the AI assistant's knowledge.
 *
 * @package ChatPuff
 */

namespace ChatPuff\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the shop's published products, product categories and pages to ChatPuff, for the AI
 * assistant's knowledge (api-contract.md §7.6, capability knowledge_sync). A pass walks each kind
 * in order within a time budget and continues at the next run until it is complete; only what
 * changed since it was last sent travels, and each kind ends with the inventory of what is
 * published. Nothing about customers or orders is ever sent. A product that changes in the shop
 * does not wait for the next pass (0.7.2): it is noted (watch()) and sent on its own a minute later
 * (send_changes()), with whatever else changed in that minute. A sale is written in words, with the
 * regular price, the cut and the end (0.7.3), and the "Current offers" page source (OFFERS_ID)
 * lists the products on sale, so that the assistant can say what is on offer.
 *
 * @phpstan-type Source array{kind: string, external_id: string, locale: string, title: string, url: string|null, text: string, hash: string}
 */
final class Knowledge {

	public const HOOK   = 'chatpuff_knowledge';
	public const CURSOR = 'chatpuff_knowledge_cursor';
	/** The hash of what was last sent, kept on the product, page or category itself. */
	public const HASH_META = '_chatpuff_knowledge';
	/** Seconds a run may take from WP-Cron, from the settings page's button without JavaScript, and per step of its progress bar. */
	public const CRON_BUDGET  = 25;
	public const ADMIN_BUDGET = 40;
	public const STEP_BUDGET  = 8;
	/** Seconds until the next run while a pass is in progress; an hour once it is complete. */
	public const CONTINUE_IN = 300;
	/** The products changed since they were last sent, waiting for their run (0.7.2). */
	public const PENDING = 'chatpuff_knowledge_pending';
	/** The run that sends them, a minute after the first change, so that a bulk edit travels together. */
	public const CHANGES_HOOK = 'chatpuff_knowledge_changes';
	public const CHANGES_IN   = 60;
	/** More changed products than this at once (an import, a bulk edit) start the whole pass instead. */
	public const PENDING_MAX = 100;

	private const KINDS = array( 'product', 'category', 'page' );
	/** Items read per step, and sent per request (the contract allows 100). */
	private const PAGE = 40;
	/** Identifiers an inventory may list (the contract's limit); a larger kind keeps what ChatPuff has. */
	private const MAX_INVENTORY = 100000;
	private const MAX_TEXT      = 60000;
	/** The "Current offers" page (0.7.3): a page source listing the products on sale, and the option with the hash of what was last sent. */
	public const OFFERS_ID   = 'chatpuff-offers';
	public const OFFERS_HASH = 'chatpuff_knowledge_offers';
	/** Products the offers page lists at most, the largest cuts first. */
	private const OFFERS_MAX = 200;

	/**
	 * Whether this request's noted products overflowed: the pass takes them.
	 *
	 * @var bool
	 */
	private static $overflowed = false;

	/**
	 * The API client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Takes the API client.
	 *
	 * @param Api_Client $api the API client.
	 */
	public function __construct( Api_Client $api ) {
		$this->api = $api;
	}

	/**
	 * Keeps the hourly run scheduled while the site is connected, and not on a copy.
	 */
	public static function schedule(): void {
		$scheduled = wp_next_scheduled( self::HOOK );
		if ( null !== Settings::widget() && ! Pairing::is_copy() ) {
			if ( false === $scheduled ) {
				wp_schedule_event( time() + 120, 'hourly', self::HOOK );
			}
		} elseif ( false !== $scheduled ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * The WP-Cron run: a slice of the pass, and a continuation in a few minutes while it is not
	 * complete. ChatPuff being slow or unreachable never delays a page.
	 */
	public static function run(): void {
		if ( null === Settings::widget() || Pairing::is_copy() ) {
			return;
		}
		$cursor = ( new self( new Api_Client() ) )->pass( self::CRON_BUDGET );
		if ( null === $cursor['kind'] || null !== $cursor['error'] ) {
			// Complete, or failed: the hourly run takes over.
			return;
		}
		$next = wp_next_scheduled( self::HOOK );
		if ( false === $next || $next > time() + self::CONTINUE_IN ) {
			wp_schedule_single_event( time() + self::CONTINUE_IN, self::HOOK );
		}
	}

	/**
	 * Notes what changes a product for the assistant: a save through WooCommerce (the editor, quick
	 * and bulk edit, imports, the REST API, scheduled sales), its variations, its stock, and its
	 * status or its deletion through WordPress itself.
	 */
	public static function watch(): void {
		add_action( 'woocommerce_new_product', array( self::class, 'product_changed' ) );
		add_action( 'woocommerce_update_product', array( self::class, 'product_changed' ) );
		add_action( 'woocommerce_product_set_stock_status', array( self::class, 'product_changed' ) );
		add_action( 'woocommerce_new_product_variation', array( self::class, 'variation_changed' ) );
		add_action( 'woocommerce_update_product_variation', array( self::class, 'variation_changed' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( self::class, 'variation_changed' ) );
		add_action( 'woocommerce_product_set_stock', array( self::class, 'stock_changed' ) );
		add_action( 'woocommerce_variation_set_stock', array( self::class, 'stock_changed' ) );
		add_action( 'transition_post_status', array( self::class, 'status_changed' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'post_deleted' ) );
		add_action( self::CHANGES_HOOK, array( self::class, 'send_changes' ) );
	}

	/**
	 * A product was created or saved, or its stock status changed.
	 *
	 * @param int|string $product_id the product.
	 */
	public static function product_changed( $product_id ): void {
		self::note( (int) $product_id );
	}

	/**
	 * A variation was created or saved, or its stock status changed: its product.
	 *
	 * @param int|string $variation_id the variation.
	 */
	public static function variation_changed( $variation_id ): void {
		self::note( (int) wp_get_post_parent_id( (int) $variation_id ) );
	}

	/**
	 * A stock quantity changed (an order, a refund, the admin): the product, which is sent only
	 * when its text changed.
	 *
	 * @param mixed $product the product or variation.
	 */
	public static function stock_changed( $product ): void {
		if ( $product instanceof \WC_Product ) {
			self::note( $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id() );
		}
	}

	/**
	 * A product was published, unpublished or trashed, whichever way.
	 *
	 * @param string $new_status the status now.
	 * @param string $old_status the status before.
	 * @param mixed  $post       the post.
	 */
	public static function status_changed( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && $post instanceof \WP_Post && 'product' === $post->post_type ) {
			self::note( (int) $post->ID );
		}
	}

	/**
	 * A product or a variation is about to be deleted for good.
	 *
	 * @param int|string $post_id the post.
	 */
	public static function post_deleted( $post_id ): void {
		$type = get_post_type( (int) $post_id );
		if ( 'product' === $type ) {
			self::note( (int) $post_id );
		} elseif ( 'product_variation' === $type ) {
			self::variation_changed( $post_id );
		}
	}

	/**
	 * Notes a changed product and schedules its run, a minute away; nothing leaves the site here,
	 * so a save stays as fast as ever whatever ChatPuff does. More than PENDING_MAX at once start
	 * the whole pass now instead, which sends only what changed.
	 *
	 * @param int $product_id the product.
	 */
	private static function note( int $product_id ): void {
		if ( $product_id <= 0 || self::$overflowed || null === Settings::widget() || Pairing::is_copy() ) {
			return;
		}
		$pending = self::pending();
		if ( ! in_array( $product_id, $pending, true ) ) {
			$pending[] = $product_id;
			if ( count( $pending ) > self::PENDING_MAX ) {
				self::$overflowed = true;
				update_option( self::PENDING, array(), false );
				$next = wp_next_scheduled( self::HOOK );
				if ( false === $next || $next > time() + self::CHANGES_IN ) {
					wp_schedule_single_event( time(), self::HOOK );
				}

				return;
			}
			update_option( self::PENDING, $pending, false );
		}
		if ( false === wp_next_scheduled( self::CHANGES_HOOK ) ) {
			wp_schedule_single_event( time() + self::CHANGES_IN, self::CHANGES_HOOK );
		}
	}

	/**
	 * The products noted.
	 *
	 * @return list<int>
	 */
	private static function pending(): array {
		$ids = get_option( self::PENDING, array() );

		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
	}

	/**
	 * The WP-Cron run a minute after a change: the noted products that changed are sent as
	 * sources and, when one that ChatPuff has is no longer published (deleted, trashed, hidden),
	 * the products' inventory, so that it leaves the assistant's knowledge at once. The list is
	 * cleared first; when ChatPuff fails, nothing is remembered as sent and the next pass sends it.
	 */
	public static function send_changes(): void {
		$ids = self::pending();
		if ( array() === $ids ) {
			return;
		}
		update_option( self::PENDING, array(), false );
		if ( null === Settings::connection() || Pairing::is_copy() ) {
			return;
		}
		( new self( new Api_Client() ) )->send_products( $ids );
	}

	/**
	 * Sends these products where they changed, the inventory when one that ChatPuff has left, and
	 * the offers page where it changed, since a product's sale may have changed it.
	 *
	 * @param int[] $ids the products.
	 */
	private function send_products( array $ids ): void {
		global $wpdb;
		// The noted products that are published, in one query; the result changes with every product.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows      = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND ID IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY ID ASC', $ids ) );
		$published = array_values( array_diff( array_map( 'intval', $rows ), $this->hidden_product_ids() ) );
		$gone      = array_values( array_diff( $ids, $published ) );
		$locale    = Pairing::language( get_locale() );
		$pairing   = new Pairing( $this->api );
		try {
			foreach ( array_chunk( $published, self::PAGE ) as $chunk ) {
				$changed = $this->changed( 'product', $this->sources( 'product', $chunk, $locale ) );
				if ( array() !== $changed ) {
					$this->send_sources( $pairing, 'product', $changed );
				}
			}
			if ( $this->known( $gone ) ) {
				$this->send_inventory( $pairing, 'product', $locale );
			}
			$this->send_offers( $pairing, $locale );
		} catch ( Api_Exception $exception ) {
			// The next pass sends them: nothing was remembered as sent.
			unset( $exception );
		}
	}

	/**
	 * Whether ChatPuff may have any of these products: one deleted, or one sent and not forgotten since.
	 *
	 * @param int[] $ids the products.
	 */
	private function known( array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( null === get_post( $id ) || '' !== (string) get_post_meta( $id, self::HASH_META, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Where the pass stands: the kind being walked (null between passes) and the last ID sent in
	 * it, when the pass started and when the last one completed, what it checked and sent, and
	 * the code of the last error.
	 *
	 * @return array{kind: string|null, last_id: int, started_at: int|null, completed_at: int|null, checked: int, sent: int, error: string|null}
	 */
	public static function cursor(): array {
		$data = get_option( self::CURSOR, null );
		$data = is_array( $data ) ? $data : array();

		return array(
			'kind'         => in_array( $data['kind'] ?? null, self::KINDS, true ) ? (string) $data['kind'] : null,
			'last_id'      => (int) ( $data['last_id'] ?? 0 ),
			'started_at'   => isset( $data['started_at'] ) ? (int) $data['started_at'] : null,
			'completed_at' => isset( $data['completed_at'] ) ? (int) $data['completed_at'] : null,
			'checked'      => (int) ( $data['checked'] ?? 0 ),
			'sent'         => (int) ( $data['sent'] ?? 0 ),
			'error'        => isset( $data['error'] ) ? (string) $data['error'] : null,
		);
	}

	/**
	 * Runs the pass for up to the budget, then saves where it stands.
	 *
	 * @param int $budget_seconds how long it may take.
	 *
	 * @return array{kind: string|null, last_id: int, started_at: int|null, completed_at: int|null, checked: int, sent: int, error: string|null}
	 */
	public function pass( int $budget_seconds ): array {
		$cursor = self::cursor();
		if ( null === Settings::connection() || Pairing::is_copy() ) {
			return $cursor;
		}
		if ( null === $cursor['kind'] ) {
			$cursor = array(
				'kind'         => self::KINDS[0],
				'last_id'      => 0,
				'started_at'   => time(),
				'completed_at' => $cursor['completed_at'],
				'checked'      => 0,
				'sent'         => 0,
				'error'        => null,
			);
		}
		$deadline = microtime( true ) + $budget_seconds;
		$locale   = Pairing::language( get_locale() );
		$pairing  = new Pairing( $this->api );
		try {
			while ( null !== $cursor['kind'] && microtime( true ) < $deadline ) {
				$kind = (string) $cursor['kind'];
				$ids  = $this->ids( $kind, $cursor['last_id'], self::PAGE );
				if ( array() === $ids ) {
					if ( 'page' === $kind && $this->send_offers( $pairing, $locale ) ) {
						++$cursor['sent'];
					}
					$this->send_inventory( $pairing, $kind, $locale );
					$next              = (int) array_search( $kind, self::KINDS, true ) + 1;
					$cursor['kind']    = $next < count( self::KINDS ) ? self::KINDS[ $next ] : null;
					$cursor['last_id'] = 0;
					if ( null === $cursor['kind'] ) {
						$cursor['completed_at'] = time();
					}
					self::save_cursor( $cursor );
					continue;
				}
				$sources            = $this->sources( $kind, $ids, $locale );
				$cursor['checked'] += count( $sources );
				$changed            = $this->changed( $kind, $sources );
				if ( array() !== $changed ) {
					$this->send_sources( $pairing, $kind, $changed );
					$cursor['sent'] += count( $changed );
				}
				$cursor['last_id'] = max( $ids );
				$cursor['error']   = null;
				self::save_cursor( $cursor );
			}
		} catch ( Api_Exception $exception ) {
			$cursor['error'] = $exception->get_problem_code();
			self::save_cursor( $cursor );
		}

		return $cursor;
	}

	/**
	 * Sends these sources and remembers them as sent.
	 *
	 * @param Pairing  $pairing the signed client.
	 * @param string   $kind    product, category or page.
	 * @param Source[] $changed the sources that changed.
	 *
	 * @throws Api_Exception When ChatPuff refuses or cannot be reached.
	 */
	private function send_sources( Pairing $pairing, string $kind, array $changed ): void {
		$pairing->send(
			'PUT',
			'/integration/v1/knowledge/sources',
			array(
				'sources' => array_map(
					static function ( array $source ): array {
						unset( $source['hash'] );

						return $source;
					},
					$changed
				),
			)
		);
		$this->remember( $kind, $changed );
	}

	/**
	 * Sends what the shop publishes of a kind, so that ChatPuff removes the rest, and forgets the
	 * hashes of what it no longer publishes.
	 *
	 * @param Pairing $pairing the signed client.
	 * @param string  $kind    product, category or page.
	 * @param string  $locale  the shop's language.
	 *
	 * @throws Api_Exception When ChatPuff refuses or cannot be reached.
	 */
	private function send_inventory( Pairing $pairing, string $kind, string $locale ): void {
		$all = array_map( 'strval', $this->published( $kind ) );
		if ( 'page' === $kind ) {
			$all[] = self::OFFERS_ID;
		}
		if ( count( $all ) > self::MAX_INVENTORY ) {
			return;
		}
		$pairing->send(
			'PUT',
			'/integration/v1/knowledge/inventory',
			array(
				'kind'         => $kind,
				'locale'       => $locale,
				'external_ids' => $all,
			)
		);
		$this->forget_unpublished( $kind );
	}

	/**
	 * How far the pass is, for the settings page's progress bar: the items walked so far (every
	 * kind before the current one, and the current one up to the last ID sent) out of all the
	 * items published.
	 *
	 * @return array{done: int, total: int}
	 */
	public function progress(): array {
		$cursor  = self::cursor();
		$done    = 0;
		$total   = 0;
		$reached = null === $cursor['kind'];
		foreach ( self::KINDS as $kind ) {
			$ids    = $this->published( $kind );
			$count  = count( $ids );
			$total += $count;
			if ( $reached ) {
				$done += $count;
			} elseif ( $kind === $cursor['kind'] ) {
				$last    = $cursor['last_id'];
				$done   += count(
					array_filter(
						$ids,
						static function ( int $id ) use ( $last ): bool {
							return $id <= $last;
						}
					)
				);
				$reached = true;
			} else {
				$done += $count;
			}
		}

		return array(
			'done'  => min( $done, $total ),
			'total' => $total,
		);
	}

	/**
	 * Saves where the pass stands.
	 *
	 * @param array{kind: string|null, last_id: int, started_at: int|null, completed_at: int|null, checked: int, sent: int, error: string|null} $cursor the cursor.
	 */
	private static function save_cursor( array $cursor ): void {
		update_option( self::CURSOR, $cursor, false );
	}

	/**
	 * The IDs of a kind that the shop publishes after a given one, in order: the next step of the
	 * pass. Hidden products are among them, so that the step advances past them; they are not sent.
	 *
	 * @param string $kind  product, category or page.
	 * @param int    $after the last ID of the previous step.
	 * @param int    $limit how many at most.
	 *
	 * @return list<int>
	 */
	private function ids( string $kind, int $after, int $limit ): array {
		if ( 'product' === $kind ) {
			global $wpdb;
			// A step of the pass by ID, which WP_Query cannot express; the result changes with every product.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d", $after, $limit ) );

			return array_values( array_map( 'intval', $rows ) );
		}
		$ids = array_values(
			array_filter(
				$this->published( $kind ),
				static function ( int $id ) use ( $after ): bool {
					return $id > $after;
				}
			)
		);

		return array_slice( $ids, 0, $limit );
	}

	/**
	 * Everything of a kind that the shop publishes, in order: the inventory.
	 *
	 * @param string $kind product, category or page.
	 *
	 * @return list<int>
	 */
	private function published( string $kind ): array {
		switch ( $kind ) {
			case 'product':
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID ASC" );

				return array_values( array_diff( array_map( 'intval', $rows ), $this->hidden_product_ids() ) );
			case 'category':
				$terms = get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'hide_empty' => false,
						'fields'     => 'ids',
						'orderby'    => 'id',
						'order'      => 'ASC',
					)
				);

				return is_array( $terms ) ? array_map( 'intval', $terms ) : array();
			default:
				$pages = get_posts(
					array(
						'post_type'        => 'page',
						'post_status'      => 'publish',
						'fields'           => 'ids',
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'posts_per_page'   => -1,
						'no_found_rows'    => true,
						'suppress_filters' => false,
					)
				);

				return array_values( array_map( 'intval', $pages ) );
		}
	}

	/**
	 * The products hidden from the catalog and from search: never sent.
	 *
	 * @return list<int>
	 */
	private function hidden_product_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT tr.object_id FROM {$wpdb->term_relationships} tr"
				. " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
				. " INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id"
				. ' WHERE tt.taxonomy = %s AND t.slug IN (%s, %s) GROUP BY tr.object_id HAVING COUNT(DISTINCT t.slug) = 2',
				'product_visibility',
				'exclude-from-catalog',
				'exclude-from-search'
			)
		);

		return array_values( array_map( 'intval', $rows ) );
	}

	/**
	 * The sources of a kind, as the contract wants them, with their hash.
	 *
	 * @param string $kind   product, category or page.
	 * @param int[]  $ids    the IDs of this step.
	 * @param string $locale the shop's language.
	 *
	 * @return list<Source>
	 */
	private function sources( string $kind, array $ids, string $locale ): array {
		$sources = array();
		switch ( $kind ) {
			case 'product':
				_prime_post_caches( $ids );
				foreach ( $ids as $id ) {
					$product = wc_get_product( $id );
					if ( ! $product instanceof \WC_Product || 'hidden' === $product->get_catalog_visibility() ) {
						continue;
					}
					$lines = array();
					if ( '' !== trim( (string) $product->get_sku() ) ) {
						$lines[] = __( 'SKU', 'chatpuff' ) . ': ' . trim( (string) $product->get_sku() );
					}
					$categories = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
					if ( is_array( $categories ) && array() !== $categories ) {
						$lines[] = __( 'Categories', 'chatpuff' ) . ': ' . implode( ', ', array_map( 'strval', $categories ) );
					}
					$price = $this->price_text( $product );
					if ( '' !== $price ) {
						$lines[] = __( 'Price', 'chatpuff' ) . ': ' . $price;
					}
					$lines[] = $this->availability( $product );
					$text    = implode( "\n", $lines ) . "\n\n" . self::plain_text( $product->get_short_description() ) . "\n\n" . self::plain_text( $product->get_description() );
					$source  = self::source( $kind, (string) $id, $locale, $product->get_name(), (string) $product->get_permalink(), $text );
					if ( null !== $source ) {
						$sources[] = $source;
					}
				}
				break;
			case 'category':
				foreach ( $ids as $id ) {
					$term = get_term( $id, 'product_cat' );
					if ( ! $term instanceof \WP_Term ) {
						continue;
					}
					$link   = get_term_link( $term );
					$source = self::source( $kind, (string) $id, $locale, $term->name, is_string( $link ) ? $link : '', self::plain_text( $term->description ) );
					if ( null !== $source ) {
						$sources[] = $source;
					}
				}
				break;
			default:
				_prime_post_caches( $ids );
				foreach ( $ids as $id ) {
					$post = get_post( $id );
					if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
						continue;
					}
					$source = self::source( $kind, (string) $id, $locale, $post->post_title, (string) get_permalink( $post ), self::plain_text( strip_shortcodes( $post->post_content ) ) );
					if ( null !== $source ) {
						$sources[] = $source;
					}
				}
		}

		return $sources;
	}

	/**
	 * The price line's value: the price as the shop shows it and, for a product on sale, the sale
	 * price with the regular price, the cut and the end day, "47,20 € (Regular price: 59,00 €, -20%,
	 * until 2026-11-30)", so that the assistant can say a product is on offer (0.7.3). A product not
	 * on sale keeps the shop's own price text.
	 *
	 * @param \WC_Product $product the product.
	 */
	private function price_text( \WC_Product $product ): string {
		$sale = self::sale( $product );
		if ( null === $sale ) {
			return str_replace( "\n", ' ', self::plain_text( $product->get_price_html() ) );
		}
		$parts = array( __( 'Regular price', 'chatpuff' ) . ': ' . $sale['regular'], $sale['cut'] );
		if ( null !== $sale['until'] ) {
			/* translators: %s: the last day of a sale, as 2026-11-30 */
			$parts[] = sprintf( __( 'until %s', 'chatpuff' ), $sale['until'] );
		}

		return $sale['price'] . ' (' . implode( ', ', $parts ) . ')';
	}

	/**
	 * A product's sale, while it is on sale: the sale and the regular prices as the shop shows them
	 * (with or without tax as it chose), the cut, the end day and the cut as a fraction; null
	 * otherwise. A variable product is described by its variation on sale with the largest cut.
	 *
	 * @param \WC_Product $product the product.
	 *
	 * @return array{price: string, regular: string, cut: string, until: string|null, fraction: float}|null
	 */
	private static function sale( \WC_Product $product ): ?array {
		if ( ! $product->is_on_sale() ) {
			return null;
		}
		$subject = $product;
		if ( $product instanceof \WC_Product_Variable ) {
			$subject = null;
			$best    = 0.0;
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation instanceof \WC_Product || ! $variation->is_on_sale() ) {
					continue;
				}
				$fraction = self::fraction( $variation );
				if ( $fraction > $best ) {
					$best    = $fraction;
					$subject = $variation;
				}
			}
			if ( null === $subject ) {
				return null;
			}
		}
		$fraction = self::fraction( $subject );
		if ( $fraction <= 0 ) {
			return null;
		}
		$to = $subject->get_date_on_sale_to();

		return array(
			'price'    => self::plain_text( wc_price( wc_get_price_to_display( $subject ) ) ),
			'regular'  => self::plain_text( wc_price( wc_get_price_to_display( $subject, array( 'price' => (float) $subject->get_regular_price() ) ) ) ),
			'cut'      => '-' . self::percent( $fraction ) . '%',
			'until'    => $to instanceof \WC_DateTime ? $to->date( 'Y-m-d' ) : null,
			'fraction' => $fraction,
		);
	}

	/**
	 * How much of the regular price a product's current price takes off, 0.2 for a fifth; 0 when nothing.
	 *
	 * @param \WC_Product $product the product or variation.
	 */
	private static function fraction( \WC_Product $product ): float {
		$regular = (float) $product->get_regular_price();
		$price   = (float) $product->get_price();

		return $regular > 0 && $price < $regular ? ( $regular - $price ) / $regular : 0.0;
	}

	/**
	 * A fraction as a percentage without needless decimals: 0.2 gives "20", 0.125 gives "12.5".
	 *
	 * @param float $fraction the fraction.
	 */
	private static function percent( float $fraction ): string {
		return rtrim( rtrim( number_format( $fraction * 100, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Sends the "Current offers" page where it changed (0.7.3): the products on sale, the largest
	 * cuts first, each with its prices, its cut, its end and its link, or the line that says there
	 * are none; whether it was sent. The same page again changes nothing, like any other source.
	 *
	 * @param Pairing $pairing the signed client.
	 * @param string  $locale  the shop's language.
	 *
	 * @throws Api_Exception When ChatPuff refuses or cannot be reached.
	 */
	private function send_offers( Pairing $pairing, string $locale ): bool {
		$source = $this->offers_source( $locale );
		if ( null === $source || (string) get_option( self::OFFERS_HASH, '' ) === $source['hash'] ) {
			return false;
		}
		$hash = $source['hash'];
		unset( $source['hash'] );
		$pairing->send( 'PUT', '/integration/v1/knowledge/sources', array( 'sources' => array( $source ) ) );
		update_option( self::OFFERS_HASH, $hash, false );

		return true;
	}

	/**
	 * The "Current offers" page: WooCommerce's list of what is on sale, kept to the published
	 * products that are not hidden, at most OFFERS_MAX of them.
	 *
	 * @param string $locale the shop's language.
	 *
	 * @return Source|null
	 */
	private function offers_source( string $locale ): ?array {
		$hidden = $this->hidden_product_ids();
		$sales  = array();
		foreach ( array_map( 'intval', (array) wc_get_product_ids_on_sale() ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof \WC_Product_Variation ) {
				$product = wc_get_product( $product->get_parent_id() );
			}
			if ( ! $product instanceof \WC_Product || isset( $sales[ $product->get_id() ] ) ) {
				continue;
			}
			if ( 'publish' !== $product->get_status() || 'hidden' === $product->get_catalog_visibility() || in_array( $product->get_id(), $hidden, true ) ) {
				continue;
			}
			$sale = self::sale( $product );
			if ( null !== $sale ) {
				$sales[ $product->get_id() ] = array(
					'product' => $product,
					'sale'    => $sale,
				);
			}
		}
		uasort(
			$sales,
			static function ( array $a, array $b ): int {
				$order = $b['sale']['fraction'] <=> $a['sale']['fraction'];

				return 0 !== $order ? $order : $a['product']->get_id() <=> $b['product']->get_id();
			}
		);
		if ( array() === $sales ) {
			$text = __( 'No product is on sale at the moment.', 'chatpuff' );
		} else {
			$lines = array( __( 'Products on sale at the moment:', 'chatpuff' ) );
			foreach ( array_slice( $sales, 0, self::OFFERS_MAX, true ) as $entry ) {
				$parts = array( __( 'Regular price', 'chatpuff' ) . ': ' . $entry['sale']['regular'], $entry['sale']['cut'] );
				if ( null !== $entry['sale']['until'] ) {
					/* translators: %s: the last day of a sale, as 2026-11-30 */
					$parts[] = sprintf( __( 'until %s', 'chatpuff' ), $entry['sale']['until'] );
				}
				$name    = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $entry['product']->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				$lines[] = $name . ': ' . $entry['sale']['price'] . ' (' . implode( ', ', $parts ) . ') ' . $entry['product']->get_permalink();
			}
			$text = implode( "\n", $lines );
		}

		return self::source( 'page', self::OFFERS_ID, $locale, __( 'Current offers', 'chatpuff' ), '', $text );
	}

	/**
	 * Whether the product can be bought now, in the shop's words.
	 *
	 * @param \WC_Product $product the product.
	 */
	private function availability( \WC_Product $product ): string {
		$availability = $product->get_availability();
		$text         = isset( $availability['availability'] ) ? self::plain_text( (string) $availability['availability'] ) : '';
		if ( '' !== $text ) {
			return $text;
		}

		return $product->is_in_stock() ? __( 'In stock', 'chatpuff' ) : __( 'Out of stock', 'chatpuff' );
	}

	/**
	 * One source as the contract wants it, with its hash; nothing without a title.
	 *
	 * @param string $kind        product, category or page.
	 * @param string $external_id the ID in this shop.
	 * @param string $locale      the shop's language.
	 * @param string $title       the name or title.
	 * @param string $url         the page's address, or nothing.
	 * @param string $text        the text, as a customer reads it.
	 *
	 * @return Source|null
	 */
	private static function source( string $kind, string $external_id, string $locale, string $title, string $url, string $text ): ?array {
		$title = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		if ( '' === $title ) {
			return null;
		}
		$title = mb_substr( $title, 0, 300 );
		$text  = trim( (string) preg_replace( "/\n{3,}/u", "\n\n", $text ) );
		$text  = mb_substr( $text, 0, self::MAX_TEXT );

		return array(
			'kind'        => $kind,
			'external_id' => $external_id,
			'locale'      => $locale,
			'title'       => $title,
			'url'         => '' !== $url && 1 === preg_match( '#^https?://#i', $url ) ? mb_substr( $url, 0, 1000 ) : null,
			'text'        => $text,
			'hash'        => hash( 'sha256', $title . "\n" . $text ),
		);
	}

	/**
	 * The sources whose hash differs from what was last sent.
	 *
	 * @param string   $kind    product, category or page.
	 * @param Source[] $sources the sources.
	 *
	 * @return list<Source>
	 */
	private function changed( string $kind, array $sources ): array {
		if ( 'category' === $kind ) {
			update_termmeta_cache( array_map( 'intval', array_column( $sources, 'external_id' ) ) );
		}

		return array_values(
			array_filter(
				$sources,
				static function ( array $source ) use ( $kind ): bool {
					$id   = (int) $source['external_id'];
					$last = 'category' === $kind ? get_term_meta( $id, self::HASH_META, true ) : get_post_meta( $id, self::HASH_META, true );

					return $last !== $source['hash'];
				}
			)
		);
	}

	/**
	 * Remembers what was sent, on the items themselves.
	 *
	 * @param string   $kind    product, category or page.
	 * @param Source[] $sources the sources sent.
	 */
	private function remember( string $kind, array $sources ): void {
		foreach ( $sources as $source ) {
			$id = (int) $source['external_id'];
			if ( 'category' === $kind ) {
				update_term_meta( $id, self::HASH_META, $source['hash'] );
			} else {
				update_post_meta( $id, self::HASH_META, $source['hash'] );
			}
		}
	}

	/**
	 * Forgets what the shop no longer publishes, so that an item that returns is sent again. A
	 * deleted product, page or category loses its hash with its other data.
	 *
	 * @param string $kind product, category or page.
	 */
	private function forget_unpublished( string $kind ): void {
		if ( 'category' === $kind ) {
			return;
		}
		global $wpdb;
		$sql    = "DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status <> 'publish'";
		$values = array( self::HASH_META, 'product' === $kind ? 'product' : 'page' );
		$hidden = 'product' === $kind ? $this->hidden_product_ids() : array();
		if ( array() !== $hidden ) {
			$sql   .= ' OR (pm.meta_key = %s AND p.ID IN (' . implode( ',', array_fill( 0, count( $hidden ), '%d' ) ) . '))';
			$values = array_merge( $values, array( self::HASH_META ), $hidden );
		}
		// Housekeeping on this plugin's own meta rows, once per pass.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * HTML as the text a customer reads: blocks become lines, tags go, entities are decoded.
	 *
	 * @param string $html the HTML.
	 */
	public static function plain_text( string $html ): string {
		$text = (string) preg_replace( '#<\s*(br|/p|/div|/li|/h[1-6]|/tr|/table|/blockquote)\s*/?>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = (string) preg_replace( '/[ \t]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n */u', "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/u", "\n\n", $text );

		return trim( $text );
	}
}

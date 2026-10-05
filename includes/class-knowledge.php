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
 * published. Nothing about customers or orders is ever sent.
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

	private const KINDS = array( 'product', 'category', 'page' );
	/** Items read per step, and sent per request (the contract allows 100). */
	private const PAGE = 40;
	/** Identifiers an inventory may list (the contract's limit); a larger kind keeps what ChatPuff has. */
	private const MAX_INVENTORY = 100000;
	private const MAX_TEXT      = 60000;

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
					$all = $this->published( $kind );
					if ( count( $all ) <= self::MAX_INVENTORY ) {
						$pairing->send(
							'PUT',
							'/integration/v1/knowledge/inventory',
							array(
								'kind'         => $kind,
								'locale'       => $locale,
								'external_ids' => array_map( 'strval', $all ),
							)
						);
						$this->forget_unpublished( $kind );
					}
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
					$price = self::plain_text( $product->get_price_html() );
					if ( '' !== $price ) {
						$lines[] = __( 'Price', 'chatpuff' ) . ': ' . str_replace( "\n", ' ', $price );
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

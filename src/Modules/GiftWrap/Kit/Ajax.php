<?php
/**
 * The kit's requests: read the draft, change it, send it to the cart.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Kit;

use Galaxie\Woo\Modules\GiftWrap\Module;
use Galaxie\Woo\Support\GiftKit;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-ajax.php?action=galaxie_kit_{name}`, public (a guest builds kits too)
 * and only ever about the caller's own session and account.
 *
 * - `get` reads: the draft, and with `catalog=1` the boxes, cards and sizes
 *   the popup lists (with `pending_id` / `pending_qty`, the candle a product
 *   page starts a kit with). It changes nothing, so it needs no nonce, and it
 *   hands one out: pages are cached (LiteSpeed, for days) and never carry a
 *   nonce or any draft state — the badge, the Buy Box label and the widgets
 *   all fill themselves from here.
 * - Every other action changes something and checks the nonce first. Each
 *   answer carries a fresh one: a guest's first change opens a WooCommerce
 *   session, and a nonce made before it no longer matches.
 * - Every answer carries the draft as it now is (`kit`, null without one), so
 *   whatever asked can redraw from it, and notices kept for the visitor.
 * - All of them send no-cache headers, LiteSpeed's included.
 *
 * Input is bounded before use (ids as positive ints, quantities 1–12, texts cut
 * off at RAW_LIMIT bytes and then cleaned and measured by {@see Kits}), and
 * every change runs through the kit rules and the packing engine.
 */
final class Ajax {

	public const NONCE  = 'galaxie_kit';
	public const PREFIX = 'galaxie_kit_';

	public const ACTIONS = array(
		'get',
		'start',
		'rename',
		'set_box',
		'set_card',
		'set_message',
		'add_candle',
		'update_candle',
		'remove_candle',
		'discard',
		'to_cart',
		'to_cart_and_new',
		'edit_from_cart',
		'restore_previous',
	);

	/** Seconds after which a kit lock left behind (a crashed request) no longer counts. */
	private const LOCK_TTL = 15;

	/** The option name of the lock this request holds, or ''. */
	private static string $lock = '';

	/** Longest text field accepted, in bytes, before it is even cleaned. */
	private const RAW_LIMIT = 4000;

	private static ?Kits $kits = null;

	private static ?CartKits $carts = null;

	public static function hooks(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'wp_ajax_' . self::PREFIX . $action, array( self::class, 'dispatch' ) );
			add_action( 'wp_ajax_nopriv_' . self::PREFIX . $action, array( self::class, 'dispatch' ) );
		}

		// After WooCommerce loaded the cart: a guest draft meets the account.
		add_action( 'wp_loaded', array( self::class, 'merge_on_load' ), 30 );

		// The next person on this browser is not told about this account's kit.
		add_action( 'wp_logout', array( Store::class, 'forget_hint' ) );
	}

	public static function kits(): Kits {
		return self::$kits ??= new Kits( new WooCatalog(), array( Store::class, 'remember' ) );
	}

	/** Another catalog for every later request in this process (tests/kit/run.php). */
	public static function use_catalog( Catalog $catalog ): void {
		self::$kits  = new Kits( $catalog, array( Store::class, 'remember' ) );
		self::$carts = null;
	}

	public static function carts(): CartKits {
		return self::$carts ??= new CartKits( self::kits() );
	}

	public static function merge_on_load(): void {
		if ( ! is_user_logged_in() || ( is_admin() && ! wp_doing_ajax() ) || ! function_exists( 'WC' ) || ! isset( WC()->session ) ) {
			return;
		}

		self::carts()->merge_login();

		// A kit started or finished on another device: the hint follows.
		Store::sync_hint();
	}

	public static function dispatch(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- checked below for every action that changes something.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		$name   = 0 === strpos( $action, self::PREFIX ) ? substr( $action, strlen( self::PREFIX ) ) : '';

		self::no_cache();

		if ( ! in_array( $name, self::ACTIONS, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Pedido inválido.', 'galaxie-woo' ) ), 400 );
		}

		if ( 'get' !== $name ) {
			// A change must come from the store's own pages, whatever its nonce:
			// a guest's nonce is only as private as their session.
			if ( ! self::same_origin() ) {
				wp_send_json_error(
					array(
						'message' => __( 'Pedido recusado.', 'galaxie-woo' ),
						'reason'  => 'cross_site',
					),
					403
				);
			}

			check_ajax_referer( self::nonce_action(), 'nonce' );

			// Only `start` may open a session; any other change is about a draft
			// that lives in one.
			if ( 'start' !== $name && ! self::has_session() ) {
				wp_send_json_error(
					array(
						'message' => __( 'Nenhum kit em montagem. Comece um kit primeiro.', 'galaxie-woo' ),
						'reason'  => 'no_session',
						'kit'     => null,
					)
				);
			}
		}

		if ( ! CartKits::cart() ) {
			self::fail( new KitError( 'no_cart', __( 'Carrinho indisponível.', 'galaxie-woo' ) ) );
		}

		// One change at a time per visitor: two quick clicks (add, then add to
		// cart) must not both read the draft and the cart as they were.
		if ( 'get' !== $name && ! self::lock() ) {
			self::fail( new KitError( 'busy', __( 'Outra alteração do kit está em andamento. Tente de novo.', 'galaxie-woo' ) ) );
		}

		self::carts()->merge_login();

		try {
			$extra = self::run( $name );
		} catch ( KitError $error ) {
			self::fail( $error );
		}

		self::respond( $extra );
	}

	/**
	 * Takes this visitor's kit lock: an option added only if absent (one row,
	 * so the database decides between two requests). Released on `shutdown`,
	 * after WooCommerce has saved the session, so the next change reads it.
	 * A lock older than LOCK_TTL is taken over. No session yet (a first
	 * `start`): nothing to protect, no lock.
	 */
	private static function lock(): bool {
		$who = is_user_logged_in() ? 'u' . get_current_user_id() : ( self::has_session() ? 's' . self::session_id() : '' );

		if ( '' === $who ) {
			return true;
		}

		$name = 'galaxie_kit_lock_' . md5( $who );

		if ( ! add_option( $name, time(), '', false ) ) {
			$since = (int) get_option( $name, 0 );

			if ( $since > time() - self::LOCK_TTL ) {
				return false;
			}

			delete_option( $name );

			if ( ! add_option( $name, time(), '', false ) ) {
				return false;
			}
		}

		self::$lock = $name;
		// After WC_Session_Handler::save_data (shutdown, 20).
		add_action( 'shutdown', array( self::class, 'release_lock' ), 99 );

		return true;
	}

	/** Frees the lock this request took, if any. */
	public static function release_lock(): void {
		if ( '' !== self::$lock ) {
			delete_option( self::$lock );
			self::$lock = '';
		}
	}

	private static function session_id(): string {
		$session = function_exists( 'WC' ) && isset( WC()->session ) && is_object( WC()->session ) ? WC()->session : null;

		return $session && method_exists( $session, 'get_customer_id' ) ? (string) $session->get_customer_id() : '';
	}

	/**
	 * @return array<string,mixed> Extra fields for the answer.
	 */
	private static function run( string $name ): array {
		$kits  = self::kits();
		$carts = self::carts();
		$draft = Store::get();
		$user  = is_user_logged_in() ? (int) get_current_user_id() : 0;

		switch ( $name ) {
			case 'get':
				return self::read();

			case 'start':
				if ( $draft ) {
					throw new KitError( 'draft_open', __( 'Já existe um kit em montagem. Adicione-o ao carrinho ou descarte-o antes de começar outro.', 'galaxie-woo' ) );
				}

				Store::ensure_session();
				Store::put(
					$kits->start(
						Store::new_id(),
						$user,
						array(
							'name'    => self::text( 'name' ),
							'box'     => self::id( 'box' ),
							'card'    => self::id( 'card' ),
							'message' => self::text( 'message' ),
							'candle'  => self::id( 'candle' ),
							'qty'     => self::quantity( 'qty', 1 ),
						),
						$carts->kit_names(),
						$carts->in_cart()
					)
				);
				return array();

			case 'discard':
				Store::clear();
				return array();

			case 'restore_previous':
				return self::restore( $draft );

			case 'to_cart':
			case 'to_cart_and_new':
				$group = $carts->add( self::need( $draft ) );
				Store::clear();
				return self::cart_fields() + array(
					'added' => $group,
					'next'  => 'to_cart_and_new' === $name ? 'new' : 'close',
				);

			case 'edit_from_cart':
				return self::edit( $draft );
		}

		$draft = self::need( $draft );

		switch ( $name ) {
			case 'rename':
				$draft = $kits->rename( $draft, self::text( 'name' ), $carts->kit_names() );
				break;
			case 'set_box':
				$draft = $kits->set_box( $draft, self::id( 'box' ), $carts->in_cart() );
				break;
			case 'set_card':
				$draft = $kits->set_card( $draft, self::id( 'card' ) );
				break;
			case 'set_message':
				$draft = $kits->set_message( $draft, self::text( 'message' ) );
				break;
			case 'add_candle':
				$draft = $kits->add_candle( $draft, self::id( 'candle' ), self::quantity( 'qty', 1 ), $carts->in_cart() );
				break;
			case 'update_candle':
				$draft = $kits->update_candle( $draft, self::id( 'candle' ), self::quantity( 'qty', 0 ), $carts->in_cart() );
				break;
			case 'remove_candle':
				$draft = $kits->remove_candle( $draft, self::id( 'candle' ) );
				break;
		}

		Store::put( $draft );

		return array();
	}

	/**
	 * "Editar kit". With another draft open, the shopper is asked first
	 * (`confirm=1` answers yes): that draft goes to the cart — or, with no
	 * candle yet, is dropped — and the kit comes out.
	 *
	 * @return array<string,mixed>
	 */
	private static function edit( ?array $draft ): array {
		$carts = self::carts();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in dispatch().
		$group = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( (string) $_POST['group'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in dispatch().
		$yes = ! empty( $_POST['confirm'] );

		// The kit must be there to edit before anything else moves.
		$gift = \Galaxie\Woo\Modules\GiftWrap\Groups::groups( CartKits::cart()->get_cart_contents() )[ $group ] ?? null;

		if ( ! $gift || ! \Galaxie\Woo\Modules\GiftWrap\Groups::editable( $gift ) ) {
			throw new KitError( 'gone', __( 'Esse kit não está mais no carrinho.', 'galaxie-woo' ) );
		}

		if ( $draft && ! $yes ) {
			throw new KitError(
				'needs_confirm',
				$draft['candles']
					/* translators: %s: the open kit's name. */
					? sprintf( __( 'Adicionar o kit %s ao carrinho e editar este?', 'galaxie-woo' ), $draft['name'] )
					/* translators: %s: the open kit's name. */
					: sprintf( __( 'O kit %s ainda não tem velas. Descartá-lo e editar este?', 'galaxie-woo' ), $draft['name'] ),
				array( 'current' => $draft['name'] )
			);
		}

		if ( $draft && $draft['candles'] ) {
			$carts->add( $draft );
		}

		Store::clear();
		Store::ensure_session();
		Store::put( $carts->extract( $group ) );

		return self::cart_fields() + array( 'edited' => $group );
	}

	/**
	 * "Recuperar kit anterior": the kit kept aside at login becomes the draft.
	 * An open draft is handled as "Editar kit" does: asked first, then sent to
	 * the cart (or dropped, with no candle).
	 *
	 * @return array<string,mixed>
	 */
	private static function restore( ?array $draft ): array {
		$previous = Store::previous();

		if ( ! $previous ) {
			throw new KitError( 'no_previous', __( 'Não há kit anterior para recuperar.', 'galaxie-woo' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in dispatch().
		$yes = ! empty( $_POST['confirm'] );

		if ( $draft && ! $yes ) {
			throw new KitError(
				'needs_confirm',
				$draft['candles']
					/* translators: %s: the open kit's name. */
					? sprintf( __( 'Adicionar o kit %s ao carrinho e recuperar o anterior?', 'galaxie-woo' ), $draft['name'] )
					/* translators: %s: the open kit's name. */
					: sprintf( __( 'O kit %s ainda não tem velas. Descartá-lo e recuperar o anterior?', 'galaxie-woo' ), $draft['name'] ),
				array( 'current' => $draft['name'] )
			);
		}

		$carts = self::carts();

		if ( $draft && $draft['candles'] ) {
			$carts->add( $draft );
		}

		Store::clear();

		// Its name may clash with a kit now in the cart; a store-given one makes way.
		if ( ! $previous['named'] ) {
			$previous = self::kits()->rename( $previous, '', $carts->kit_names() );
		}

		Store::put( $previous );
		Store::forget_previous();

		return self::cart_fields() + array( 'restored' => $previous['id'] );
	}

	/** @return array<string,mixed> */
	private static function read(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- a read of the caller's own draft.
		$out = array();

		if ( empty( $_REQUEST['catalog'] ) ) {
			return $out;
		}

		$kits    = self::kits();
		$catalog = $kits->catalog();
		$boxes   = array();
		$cards   = array();

		$in_cart = self::carts()->in_cart();

		foreach ( $catalog->boxes() as $id => $box ) {
			$row      = Kits::box_json( $box );
			// One more can be sold, counting the kits already in the cart.
			$row['available'] = Kits::box_available( $box, $in_cart );
			$row['priceText'] = $catalog->money( (float) $box['price'] );
			// "Leva até …", worked out once for every shopper, not in each browser.
			$row['holds'] = $kits->box_holds( $box );
			$row['cards']     = new \stdClass();

			foreach ( $catalog->cards() as $card ) {
				$for = $catalog->card_for( (int) $card['parent'], (int) $id );

				if ( $for ) {
					$row['cards']->{(string) $card['parent']} = $for;
				}
			}

			$boxes[] = $row;
		}

		foreach ( $catalog->cards() as $card ) {
			$cards[] = array(
				'id'        => (int) $card['id'],
				'parent'    => (int) $card['parent'],
				'name'      => $card['name'],
				'title'     => $card['title'],
				'image'     => $card['image'],
				'price'     => $card['price'],
				'priceText' => $catalog->money( (float) $card['price'] ),
				'inStock'   => 0 !== $card['stock'],
			);
		}

		$out['catalog'] = array(
			'boxes'      => $boxes,
			'cards'      => $cards,
			'sizes'      => array_values( $catalog->sizes() ),
			// The taxonomy the sizes came from: with none found, this is the first
			// thing to check, and it saves guessing from outside the site.
			'sizeAttribute' => Module::size_attribute(),
			// Counts only, and only when there is nothing to pack with: the step
			// where the sizes are lost is otherwise invisible from outside.
			'sizeReport'    => $catalog->sizes() ? null : \Galaxie\Woo\Support\GiftPacking::size_report( Module::size_attribute() ),
			'options'    => $catalog->options(),
			'messageMax' => $catalog->message_max(),
			'shopUrl'    => function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' ),
			'kitNames'   => self::carts()->kit_names(),
		);

		$pending = self::id( 'pending_id' );

		if ( $pending ) {
			$candle = $catalog->candle( $pending );
			// Shown as asked ("13 × …" fits no box), within reason.
			$qty = isset( $_REQUEST['pending_qty'] ) && is_scalar( $_REQUEST['pending_qty'] ) ? max( 1, min( 99, (int) $_REQUEST['pending_qty'] ) ) : 1;

			$out['pending'] = $candle ? array(
				'id'        => (int) $candle['id'],
				'name'      => $candle['name'],
				'image'     => $candle['image'],
				'price'     => $candle['price'],
				'priceText' => $catalog->money( (float) $candle['price'] ),
				'qty'       => $qty,
				'candle'    => $candle['candle'],
			) : null;

			if ( ! $candle ) {
				$out['pendingError'] = __( 'Não foi possível calcular a embalagem deste produto. Fale com a loja.', 'galaxie-woo' );
			}
		}

		// phpcs:enable
		return $out;
	}

	/** @return array<string,mixed> What a page needs to redraw its cart. */
	private static function cart_fields(): array {
		$cart = CartKits::cart();

		return array(
			'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
			'cart_hash' => $cart ? $cart->get_cart_hash() : '',
			'cartCount' => $cart ? (int) $cart->get_cart_contents_count() : 0,
		);
	}

	private static function need( ?array $draft ): array {
		if ( ! $draft ) {
			throw new KitError( 'no_draft', __( 'Nenhum kit em montagem. Comece um kit primeiro.', 'galaxie-woo' ) );
		}

		return $draft;
	}

	/** @param array<string,mixed> $extra */
	private static function respond( array $extra ): void {
		wp_send_json_success( self::state() + $extra );
	}

	/** @return never */
	private static function fail( KitError $error ): void {
		wp_send_json_error(
			self::state() + $error->data + array(
				'message' => $error->getMessage(),
				'reason'  => $error->reason,
			)
		);
	}

	/** @return array<string,mixed> The draft, a fresh nonce and kept notices. */
	private static function state(): array {
		$draft = Store::get();

		// The hint cookie follows every answer, whatever else went wrong.
		Store::sync_hint();

		$previous = Store::previous();

		return array(
			'kit'      => $draft ? self::kits()->view( $draft, self::carts()->in_cart() ) : null,
			'nonce'    => wp_create_nonce( self::nonce_action() ),
			'notices'  => Store::take_notices(),
			// A kit kept aside at login, offered back ("Recuperar kit anterior").
			'previous' => $previous ? array(
				'name'  => $previous['name'],
				'count' => GiftKit::count( $previous['candles'] ),
			) : null,
		);
	}

	/**
	 * Whether this request comes from the store's own pages.
	 *
	 * The browser's `Sec-Fetch-Site` says so directly (only `same-origin`
	 * passes); without it, `Origin` must be the store's; without either, the
	 * `Referer` must be one of the store's pages. None of the three: refused.
	 */
	public static function same_origin(): bool {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- compared only.
		$site    = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? strtolower( trim( (string) $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? trim( (string) $_SERVER['HTTP_ORIGIN'] ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? trim( (string) $_SERVER['HTTP_REFERER'] ) : '';
		// phpcs:enable

		if ( '' !== $site ) {
			return 'same-origin' === $site;
		}

		$ours = array_unique( array_filter( array( self::origin_of( home_url( '/' ) ), self::origin_of( site_url( '/' ) ) ) ) );

		if ( '' !== $origin ) {
			return in_array( self::origin_of( $origin ), $ours, true );
		}

		return '' !== $referer && in_array( self::origin_of( $referer ), $ours, true );
	}

	/** `scheme://host:port`, lowercased; '' for anything else. */
	private static function origin_of( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
	}

	private static function has_session(): bool {
		$session = function_exists( 'WC' ) && isset( WC()->session ) && is_object( WC()->session ) ? WC()->session : null;

		return is_user_logged_in() || ( $session && method_exists( $session, 'has_session' ) && $session->has_session() );
	}

	/**
	 * The nonce action, tied to the visitor's WooCommerce session.
	 *
	 * WordPress gives every guest the same nonce (user 0), and WooCommerce only
	 * swaps in the session for actions named `woocommerce*`. Naming the session
	 * here keeps a nonce fetched by someone else useless against this visitor.
	 * A guest with no session yet gets the bare action; the answer to their
	 * first change, which opens the session, brings the new one.
	 */
	private static function nonce_action(): string {
		$session = function_exists( 'WC' ) && isset( WC()->session ) && is_object( WC()->session ) ? WC()->session : null;
		$id      = $session && method_exists( $session, 'has_session' ) && $session->has_session() && method_exists( $session, 'get_customer_id' ) ? (string) $session->get_customer_id() : '';

		return self::NONCE . '|' . $id;
	}

	private static function id( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification -- checked in dispatch() for changes; reads are the caller's own.
		$raw = $_REQUEST[ $key ] ?? 0;

		return is_scalar( $raw ) ? min( PHP_INT_MAX, absint( $raw ) ) : 0;
	}

	private static function quantity( string $key, int $default ): int {
		// phpcs:ignore WordPress.Security.NonceVerification -- as above.
		if ( ! isset( $_REQUEST[ $key ] ) || ! is_scalar( $_REQUEST[ $key ] ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cast and bounded.
		$value = (int) $_REQUEST[ $key ];

		// Out of range goes through as -1 and the rules refuse it.
		return $value >= 0 && $value <= 12 ? $value : -1;
	}

	private static function text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in dispatch().
		$raw = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		if ( strlen( $raw ) > self::RAW_LIMIT ) {
			throw new KitError( 'too_long', __( 'Texto longo demais.', 'galaxie-woo' ) );
		}

		// Kept as typed, cleaned by the kit rules; escaped where printed.
		return $raw;
	}

	/** Tells WordPress, the browser and LiteSpeed not to keep this answer. */
	private static function no_cache(): void {
		do_action( 'litespeed_control_set_nocache', 'galaxie gift kit' );

		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}
}

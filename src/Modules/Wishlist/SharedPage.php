<?php
/**
 * The public page a shared wishlist's link opens.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Wishlist;

use Galaxie\Woo\Support\AccountParts;

defined( 'ABSPATH' ) || exit;

/**
 * `/lista/{secret}/`, drawn inside the theme: the Elementor template chosen
 * under the Wishlist module's settings, or the Galaxie Shared Wishlist widget
 * on its own. A link that no longer leads to a shared list is a 404.
 *
 * Kept out of search engines: a list is shared with people, not published.
 *
 * "Give as a gift" on a product with options opens the product page with
 * `?galaxie_gift={secret}`; that visit is remembered on the session and
 * redirected to the product page without the secret, and the add-to-cart that
 * follows — the buy box's AJAX or WooCommerce's own form — picks it up (see
 * {@see Gifts::attach()}).
 */
final class SharedPage {

	public const QUERY_VAR = 'galaxie_wishlist_share';

	private const RULES_VERSION = '1';

	/** @var array{user_id:int,list:array<string,mixed>}|false|null */
	private static $current = null;

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'rewrite' ) );
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
		// Before WooCommerce's add-to-cart handler (wp_loaded, 20), which may redirect and exit.
		add_action( 'wp_loaded', array( self::class, 'no_cache_gift_requests' ), 0 );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'remember_gift' ) );
		add_filter( 'template_include', array( self::class, 'template' ), 99 );
		add_filter( 'document_title_parts', array( self::class, 'title' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^lista/([a-z0-9]{12,})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/** The rule reaches WordPress's stored rules once, not on every request. */
	public static function maybe_flush(): void {
		if ( get_option( 'galaxie_wishlist_rules' ) !== self::RULES_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'galaxie_wishlist_rules', self::RULES_VERSION, true );
		}
	}

	/** @param array<int,string> $vars */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** @return array{user_id:int,list:array<string,mixed>}|null The list this request opens. */
	public static function current(): ?array {
		if ( null === self::$current ) {
			$token          = (string) get_query_var( self::QUERY_VAR );
			self::$current  = '' !== $token ? ( Lists::find_shared( $token ) ?? false ) : false;
		}

		return self::$current ?: null;
	}

	public static function is_request(): bool {
		return '' !== (string) get_query_var( self::QUERY_VAR );
	}

	/** @param string $template */
	public static function template( $template ) {
		if ( ! self::is_request() ) {
			return $template;
		}

		if ( ! self::current() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			// Otherwise LiteSpeed keeps the 404 for every guess at a secret.
			self::no_cache();

			return get_404_template() ?: $template;
		}

		// Drawn differently for its owner and for everyone else.
		self::no_cache();

		return __DIR__ . '/templates/shared-wishlist.php';
	}

	/** The page itself: the chosen template, or the widget with its defaults. */
	public static function content(): string {
		$template = (int) ( \Galaxie\Woo\Core\Plugin::instance()->settings()->module_settings( 'wishlist' )['shared_template'] ?? 0 );

		if ( $template > 0 && class_exists( '\Elementor\Plugin' ) && 'publish' === get_post_status( $template ) ) {
			return (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $template, true );
		}

		return AccountParts::render_widget( 'galaxie-shared-wishlist' );
	}

	/** @param array<string,string> $parts */
	public static function title( array $parts ): array {
		$shared = self::is_request() ? self::current() : null;

		if ( $shared ) {
			$parts['title'] = (string) $shared['list']['name'];
		}

		return $parts;
	}

	/** @param array<string,bool|string> $robots */
	public static function robots( array $robots ): array {
		if ( self::is_request() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}

	/**
	 * Any request carrying a gift token — valid or not, a product page or the
	 * cart's add-to-cart link — or the marker of a page a gift link redirected
	 * to, out of every cache. A junk token was otherwise cached publicly, and a
	 * real one would put the secret, and whose gift it is, in a shared copy.
	 */
	public static function no_cache_gift_requests(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- only decides caching.
		if ( isset( $_GET[ Gifts::REQUEST_ARG ] ) || isset( $_POST[ Gifts::REQUEST_ARG ] ) || isset( $_GET[ Gifts::VIEW_ARG ] ) ) {
			self::no_cache();
		}
		// phpcs:enable
	}

	/**
	 * A product page opened from "Give as a gift": remembered for the add-to-cart
	 * that follows. The same product opened any other way — "Choose options", a
	 * search, the menu — is the shopper's own purchase again, so it is forgotten.
	 *
	 * The visit with the secret is remembered and at once redirected (302) to the
	 * same page without it, so the secret does not sit in the address bar, in
	 * analytics or in server logs. The page it lands on carries
	 * `galaxie_gift_view=1` instead — no secret, only "this is the gift view":
	 *
	 * - it keeps the remembered gift, where a clean visit would forget it; and a
	 *   reload keeps it too, which a one-time flag on the session would not;
	 * - it keeps the page out of LiteSpeed's cache. The plain product URL is
	 *   served from cache to guests whatever WooCommerce cookies they carry, so
	 *   landing there would show a cached page without the gift notice — while
	 *   the add-to-cart still made the product a gift.
	 *
	 * The gift notice reads the session ({@see Gifts::pending_for()}), so it is
	 * drawn after the redirect as before.
	 */
	public static function remember_gift(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! WC()->session ) {
			return;
		}

		$product = (int) get_queried_object_id();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only remembers where a visitor came from; the token is validated by for_token().
		if ( empty( $_GET[ Gifts::REQUEST_ARG ] ) ) {
			$pending = Gifts::pending_gift();

			if ( ! $pending || $pending['product'] !== $product ) {
				return;
			}

			// The page a gift link redirected to, or WooCommerce's add-to-cart form
			// posting back to the product's own URL: still the gift.
			if ( ! empty( $_GET[ Gifts::VIEW_ARG ] ) || isset( $_REQUEST['add-to-cart'] ) ) {
				// The page says whose gift this is; a cached copy would say it to strangers.
				self::no_cache();
				return;
			}

			WC()->session->set( Gifts::PENDING, null );
			return;
		}

		$token = sanitize_key( wp_unslash( $_GET[ Gifts::REQUEST_ARG ] ) );
		// phpcs:enable

		$gift = Gifts::for_token( $token );

		// Kept out of the cache already, valid or not (see no_cache_gift_requests()).
		if ( ! $gift || ! Gifts::on_list( $gift, $product ) ) {
			return;
		}

		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		WC()->session->set( Gifts::PENDING, array( 'token' => $token, 'product' => $product, 'time' => time() ) );

		// The session is written on shutdown, which exit runs. The relative URL
		// keeps any variation attributes the link carried.
		wp_safe_redirect( add_query_arg( Gifts::VIEW_ARG, '1', remove_query_arg( Gifts::REQUEST_ARG ) ), 302 );
		exit;
	}

	/**
	 * Out of every cache. `nocache_headers()` reaches browsers and proxies;
	 * LiteSpeed caches regardless unless told through its own control.
	 */
	public static function no_cache(): void {
		nocache_headers();

		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}

		do_action( 'litespeed_control_set_nocache', 'galaxie shared wish list' );
	}
}

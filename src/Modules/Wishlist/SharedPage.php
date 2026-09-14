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
 * `?galaxie_gift={secret}`; that visit is remembered on the session, and the
 * add-to-cart that follows — the buy box's AJAX or WooCommerce's own form —
 * picks it up (see {@see Gifts::attach()}).
 */
final class SharedPage {

	public const QUERY_VAR = 'galaxie_wishlist_share';

	private const RULES_VERSION = '1';

	/** @var array{user_id:int,list:array<string,mixed>}|false|null */
	private static $current = null;

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'rewrite' ) );
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
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
			nocache_headers();

			return get_404_template() ?: $template;
		}

		nocache_headers();

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
	 * A product page opened from "Give as a gift": remembered for the add-to-cart
	 * that follows. The same product opened any other way — "Choose options", a
	 * search, the menu — is the shopper's own purchase again, so it is forgotten.
	 */
	public static function remember_gift(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! WC()->session ) {
			return;
		}

		$product = (int) get_queried_object_id();

		if ( empty( $_GET[ Gifts::REQUEST_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only remembers where a visitor came from.
			$pending = WC()->session->get( Gifts::PENDING );

			if ( is_array( $pending ) && (int) ( $pending['product'] ?? 0 ) === $product ) {
				WC()->session->set( Gifts::PENDING, null );
			}

			return;
		}

		$token = sanitize_key( wp_unslash( $_GET[ Gifts::REQUEST_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated by for_token().

		if ( ! Gifts::for_token( $token ) ) {
			return;
		}

		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		// The page says whose gift this is; a cached copy would say it to strangers.
		nocache_headers();

		WC()->session->set( Gifts::PENDING, array( 'token' => $token, 'product' => $product ) );
	}
}

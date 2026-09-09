<?php
/**
 * Wishlist module — our own implementation (replaces XStore's add-on).
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Wishlist;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Modules\Wishlist\Widget\WishlistButtonWidget;

defined( 'ABSPATH' ) || exit;

/**
 * Storage is server-authoritative, deliberately unlike the localStorage-based
 * wishlists most themes (XStore included) ship: user meta once the visitor has
 * an account, the WooCommerce session before that, and the guest list is folded
 * into the account on `wp_login`. The list therefore survives a new device, a
 * cleared browser, and the moment of signing up — which is the whole point of
 * a wishlist for a store that emails people about it later.
 */
final class Module implements ModuleContract, ProvidesElementorWidgets, ProvidesBootData {

	public const AJAX_ACTION  = 'galaxie_wishlist_toggle';
	public const NONCE_ACTION = 'galaxie_woo_wishlist';

	private const META_KEY    = '_galaxie_wishlist';
	private const SESSION_KEY = 'galaxie_wishlist';

	public function id(): string {
		return 'wishlist';
	}

	public function title(): string {
		return __( 'Wishlist', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Own wishlist feature (add/remove, My Account view) — no theme dependency.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function boot(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_toggle' ) );
		add_action( 'wp_login', array( $this, 'merge_guest_wishlist' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		// The button is an Elementor widget and can land anywhere — a single
		// product template, a loop item, a landing page — so there is nothing
		// meaningful to gate the bundle on.
		\Galaxie\Woo\Support\Assets::enqueue();
	}

	public function elementor_widgets(): array {
		return array( WishlistButtonWidget::class );
	}

	public function boot_data(): array {
		return array(
			'wishlist' => array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			),
		);
	}

	public function ajax_toggle(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Produto inválido.', 'galaxie-woo' ) ) );
		}

		$items = self::get_items();
		$index = array_search( $product_id, $items, true );

		if ( false === $index ) {
			$items[] = $product_id;
		} else {
			unset( $items[ $index ] );
		}

		self::set_items( $items );

		wp_send_json_success(
			array(
				'in_wishlist' => false === $index,
				'count'       => count( $items ),
			)
		);
	}

	/**
	 * Reads the session directly instead of {@see get_items()}: `wp_login` fires
	 * mid-sign-in, when whether the current user is already "logged in" for the
	 * rest of the request is not something we want to depend on.
	 *
	 * @param string   $user_login Unused, part of the `wp_login` signature.
	 * @param \WP_User $user       The user that just signed in.
	 */
	public function merge_guest_wishlist( string $user_login, \WP_User $user ): void {
		$session = self::session();
		if ( ! $session ) {
			return;
		}

		$guest = self::normalize( $session->get( self::SESSION_KEY, array() ) );
		if ( ! $guest ) {
			return;
		}

		$stored = self::normalize( get_user_meta( $user->ID, self::META_KEY, true ) );
		update_user_meta( $user->ID, self::META_KEY, array_values( array_unique( array_merge( $stored, $guest ) ) ) );

		$session->set( self::SESSION_KEY, array() );
	}

	/** Public because the Elementor widget renders the button's initial state. */
	public static function is_in_wishlist( int $product_id ): bool {
		return in_array( $product_id, self::get_items(), true );
	}

	/** @return int[] */
	private static function get_items(): array {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			return self::normalize( get_user_meta( $user_id, self::META_KEY, true ) );
		}

		$session = self::session();

		return $session ? self::normalize( $session->get( self::SESSION_KEY, array() ) ) : array();
	}

	/** @param int[] $ids */
	private static function set_items( array $ids ): void {
		$ids     = self::normalize( $ids );
		$user_id = get_current_user_id();

		if ( $user_id ) {
			update_user_meta( $user_id, self::META_KEY, $ids );
			return;
		}

		$session = self::session();
		if ( $session ) {
			$session->set( self::SESSION_KEY, $ids );
		}
	}

	/** `WC()->session` is only loaded on the front-end — never assume it's there. */
	private static function session(): ?\WC_Session {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$wc = WC();

		return isset( $wc->session ) && $wc->session instanceof \WC_Session ? $wc->session : null;
	}

	/** @return int[] */
	private static function normalize( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
	}
}

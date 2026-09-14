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
 * Several named lists per account (see {@see Lists}), and nothing for visitors
 * who are not signed in: a wishlist is for a shopper the store can reach again,
 * so saving asks for an account first.
 *
 * A visitor who taps the heart is sent to sign in or create an account, and
 * the product they tapped is remembered in a short-lived cookie together with
 * the page they were on. The first page they load signed in saves it to their
 * default list and takes them back. That runs on any page load rather than on
 * `wp_login`, because the passwordless sign-in never fires `wp_login`.
 */
final class Module implements ModuleContract, ProvidesElementorWidgets, ProvidesBootData {

	public const AJAX_ACTION  = 'galaxie_wishlist_toggle';
	public const NONCE_ACTION = 'galaxie_woo_wishlist';

	private const PENDING_COOKIE = 'galaxie_wishlist_pending';

	/** AJAX action suffix => handler. Every one needs an account. */
	private const ACTIONS = array(
		'toggle'  => 'ajax_toggle',
		'lists'   => 'ajax_lists',
		'set'     => 'ajax_set',
		'create'  => 'ajax_create',
		'rename'  => 'ajax_rename',
		'delete'  => 'ajax_delete',
		'default' => 'ajax_default',
		'share'   => 'ajax_share',
		'gifts'   => 'ajax_gifts',
	);

	public function id(): string {
		return 'wishlist';
	}

	public function title(): string {
		return __( 'Wishlist', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Named wishlists for signed-in customers: save, organise, share by link — no theme dependency.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function boot(): void {
		foreach ( self::ACTIONS as $action => $method ) {
			add_action( 'wp_ajax_galaxie_wishlist_' . $action, array( $this, $method ) );
		}

		// Only so a visitor gets sent to sign in rather than a silent failure.
		add_action( 'wp_ajax_nopriv_galaxie_wishlist_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_nopriv_galaxie_wishlist_lists', array( $this, 'ajax_lists' ) );

		add_action( 'template_redirect', array( $this, 'apply_pending' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		// The button is an Elementor widget and can land anywhere — a single
		// product template, a loop item, a landing page — so there is nothing
		// meaningful to gate the bundle on.
		\Galaxie\Woo\Support\Assets::enqueue();
	}

	public function elementor_widgets(): array {
		return array( WishlistButtonWidget::class, \Galaxie\Woo\Modules\Wishlist\Widget\AccountWishlistWidget::class );
	}

	public function boot_data(): array {
		return array(
			'wishlist' => array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
				'loggedIn' => is_user_logged_in(),
			),
		);
	}

	// ------------------------------------------------------------------ AJAX

	/** Saves to, or removes from, the default list — the heart. */
	public function ajax_toggle(): void {
		$user_id    = $this->account();
		$product_id = $this->product();
		$result     = Lists::toggle_default( $user_id, $product_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$list = Lists::default_list( $user_id );

		wp_send_json_success(
			array(
				'in_wishlist' => $result,
				'list'        => array( 'id' => $list['id'], 'name' => $list['name'] ),
				'lists'       => self::lists_json( $user_id, $product_id ),
			)
		);
	}

	/** Every list, and whether the product (when given) is on each. */
	public function ajax_lists(): void {
		$user_id    = $this->account();
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in account().

		wp_send_json_success( array( 'lists' => self::lists_json( $user_id, $product_id ) ) );
	}

	/** Puts a product on one list, or takes it off. */
	public function ajax_set(): void {
		$user_id    = $this->account();
		$product_id = $this->product();
		$on         = ! empty( $_POST['on'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in account().
		$result     = Lists::set_item( $user_id, $this->list_id(), $product_id, $on );

		$this->answer( $result, $user_id, $product_id );
	}

	/** A new list, with the product already on it when one is given. */
	public function ajax_create(): void {
		$user_id    = $this->account();
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in account().
		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in account().
		$items      = $product_id && wc_get_product( $product_id ) ? array( $product_id ) : array();
		$list       = Lists::create( $user_id, $name, $items );

		if ( is_wp_error( $list ) ) {
			wp_send_json_error( array( 'message' => $list->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'list'  => self::list_json( $list, $product_id ),
				'lists' => self::lists_json( $user_id, $product_id ),
			)
		);
	}

	public function ajax_rename(): void {
		$user_id = $this->account();
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in account().

		$this->answer( Lists::rename( $user_id, $this->list_id(), $name ), $user_id );
	}

	public function ajax_delete(): void {
		$user_id = $this->account();

		$this->answer( Lists::delete( $user_id, $this->list_id() ), $user_id );
	}

	public function ajax_default(): void {
		$user_id = $this->account();

		$this->answer( Lists::set_default( $user_id, $this->list_id() ), $user_id );
	}

	/** Turns the public link on or off, or gives the list a new one. */
	public function ajax_share(): void {
		$user_id = $this->account();
		$shared  = ! empty( $_POST['shared'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in account().
		$renew   = ! empty( $_POST['renew'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in account().
		$list    = Lists::set_sharing( $user_id, $this->list_id(), $shared, $renew );

		if ( is_wp_error( $list ) ) {
			wp_send_json_error( array( 'message' => $list->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'list'  => self::list_json( $list ),
				'lists' => self::lists_json( $user_id ),
			)
		);
	}

	public function ajax_gifts(): void {
		$user_id = $this->account();
		$gifts   = ! empty( $_POST['gifts'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in account().

		$this->answer( Lists::set_gifts( $user_id, $this->list_id(), $gifts ), $user_id );
	}

	// ---------------------------------------------------- signing in to save

	/**
	 * The product a visitor tapped before signing in, saved on the first page
	 * they load signed in, then back to where they were.
	 */
	public function apply_pending(): void {
		if ( ! is_user_logged_in() || empty( $_COOKIE[ self::PENDING_COOKIE ] ) ) {
			return;
		}

		$pending = json_decode( wp_unslash( (string) $_COOKIE[ self::PENDING_COOKIE ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and validated field by field below.

		self::set_pending_cookie( '', time() - HOUR_IN_SECONDS );

		$product_id = is_array( $pending ) ? absint( $pending['p'] ?? 0 ) : 0;

		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! Lists::contains( $user_id, $product_id, (string) Lists::default_list( $user_id )['id'] ) ) {
			Lists::toggle_default( $user_id, $product_id );
		}

		$return = wp_validate_redirect( esc_url_raw( (string) ( $pending['r'] ?? '' ) ), '' );

		if ( '' !== $return ) {
			wp_safe_redirect( add_query_arg( 'galaxie_wishlist_saved', $product_id, $return ) );
			exit;
		}
	}

	// ------------------------------------------------------------ for others

	/**
	 * The product ids on the current customer's default list, newest last.
	 *
	 * @return int[]
	 */
	public static function items(): array {
		$user_id = get_current_user_id();

		return $user_id ? Lists::default_list( $user_id )['items'] : array();
	}

	/** Whether the heart is filled: the product is on the default list. */
	public static function is_in_wishlist( int $product_id ): bool {
		$user_id = get_current_user_id();

		return $user_id && in_array( $product_id, Lists::default_list( $user_id )['items'], true );
	}

	/** @return array<int,array<string,mixed>> */
	public static function lists_json( int $user_id, int $product_id = 0 ): array {
		return array_map( static fn( array $list ): array => self::list_json( $list, $product_id ), Lists::all( $user_id ) );
	}

	/** @return array<string,mixed> What the browser may know about one list. */
	public static function list_json( array $list, int $product_id = 0 ): array {
		return array(
			'id'       => (string) $list['id'],
			'name'     => (string) $list['name'],
			'default'  => ! empty( $list['default'] ),
			'gifts'    => ! empty( $list['gifts'] ),
			'shared'   => '' !== (string) $list['share'],
			'shareUrl' => Lists::share_url( $list ),
			'count'    => count( (array) $list['items'] ),
			'has'      => $product_id > 0 && in_array( $product_id, (array) $list['items'], true ),
		);
	}

	// ------------------------------------------------------------ internals

	/**
	 * The signed-in customer, or — for a visitor — a remembered product and an
	 * answer telling the browser where to sign in.
	 */
	private function account(): int {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$user_id = get_current_user_id();

		if ( $user_id ) {
			return $user_id;
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$return     = isset( $_POST['return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return'] ) ), '' ) : '';

		if ( $product_id && wc_get_product( $product_id ) ) {
			self::set_pending_cookie( (string) wp_json_encode( array( 'p' => $product_id, 'r' => $return ) ), time() + HOUR_IN_SECONDS );
		}

		wp_send_json_error(
			array(
				'login'   => (string) wc_get_page_permalink( 'myaccount' ),
				'message' => __( 'Entre na sua conta para salvar seus favoritos.', 'galaxie-woo' ),
			),
			401
		);
	}

	private function product(): int {
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in account().

		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Produto inválido.', 'galaxie-woo' ) ) );
		}

		return $product_id;
	}

	private function list_id(): string {
		return isset( $_POST['list_id'] ) ? sanitize_key( wp_unslash( $_POST['list_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in account().
	}

	/** @param mixed $result true/bool on success, a WP_Error otherwise. */
	private function answer( $result, int $user_id, int $product_id = 0 ): void {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'result' => $result,
				'lists'  => self::lists_json( $user_id, $product_id ),
			)
		);
	}

	private static function set_pending_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::PENDING_COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}

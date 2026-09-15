<?php
/**
 * Which cart or order a Melhor Envio quote request is about.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ShippingCartons;

use Galaxie\Woo\Modules\GiftWrap\Groups;
use Galaxie\Woo\Support\CartonQuote;

defined( 'ABSPATH' ) || exit;

/**
 * The quote request carries product ids and quantities, not which gift a line
 * is in. That lives on the cart item (`galaxie_gift_group`) or the order line
 * (`_galaxie_gift_group` / `_galaxie_gift_role`), so the rewrite needs the cart
 * or order the request was built from.
 *
 * The Melhor Envio plugin never says, so candidates are collected as they go
 * by and each is checked against the request:
 * - orders whose items were just read (`woocommerce_order_get_items`): the
 *   plugin reads an order's items (`OrdersProductsService::getProductsOrder`)
 *   right before quoting it — at checkout (`makeCotationOrder`), in its order
 *   list (one order after another in one request), and before adding a label
 *   to its cart (`CartService::createPayloadToCart`);
 * - the order named by its admin AJAX actions (`add_cart`, `add_order`,
 *   `update_order`, …) and by `woocommerce_checkout_order_processed`, captured
 *   before its callbacks run and released after;
 * - the shopper's cart, outside wp-admin and admin-ajax only.
 *
 * A candidate is used only when its shippable lines hold exactly the request's
 * (product id, quantity) entries ({@see CartonQuote::align()}). None matching:
 * no gift grouping, every line is its own item — larger, never wrong.
 */
final class Context {

	/** Orders remembered per request. */
	private const RECENT = 20;

	/** Melhor Envio admin AJAX actions that quote an order, with the query arg naming it. */
	private const AJAX = array(
		'add_cart'         => 'post_id',
		'add_order'        => 'post_id',
		'update_order'     => 'id',
		'get_payload_cart' => 'post_id',
		'buy_click'        => 'ids',
		'pay_ticket'       => 'id',
	);

	/** @var int[] Orders named by the running action, newest last. */
	private static array $named = array();

	/** @var int[] Orders whose items were read, newest last. */
	private static array $recent = array();

	/** Set while this class reads items itself, so it does not record them. */
	private static bool $reading = false;

	/** Where the last matched lines came from, for the log. */
	private static string $source = '';

	public static function hooks(): void {
		add_filter( 'woocommerce_order_get_items', array( self::class, 'record' ), 999, 2 );

		// Around the Melhor Envio plugin's own makeCotationOrder (priority 10).
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'enter_checkout' ), 1, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'leave' ), PHP_INT_MAX, 0 );

		foreach ( array_keys( self::AJAX ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( self::class, 'enter_ajax' ), 1, 0 );
		}
	}

	/**
	 * @param mixed $items Order items, passed through.
	 * @param mixed $order The order.
	 * @return mixed
	 */
	public static function record( $items, $order = null ) {
		if ( ! self::$reading && $order instanceof \WC_Order && $order->get_id() > 0 ) {
			self::$recent   = array_values( array_diff( self::$recent, array( $order->get_id() ) ) );
			self::$recent[] = $order->get_id();
			self::$recent   = array_slice( self::$recent, -self::RECENT );
		}

		return $items;
	}

	/** @param mixed $order_id */
	public static function enter_checkout( $order_id = 0 ): void {
		// Pushed even when 0, so leave() always pops what this pushed.
		self::$named[] = absint( $order_id );
	}

	public static function leave(): void {
		array_pop( self::$named );
	}

	/**
	 * The order(s) a Melhor Envio admin action is about. Only read to choose
	 * lines to compare against; the action's own nonce and capability checks
	 * still run after this.
	 */
	public static function enter_ajax(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$action = substr( (string) current_action(), strlen( 'wp_ajax_' ) );
		$arg    = self::AJAX[ $action ] ?? '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which order to compare the quote with.
		$raw = '' !== $arg && isset( $_GET[ $arg ] ) ? wp_unslash( $_GET[ $arg ] ) : '';
		$ids = is_array( $raw ) ? $raw : explode( ',', (string) $raw );

		foreach ( array_slice( $ids, 0, self::RECENT ) as $id ) {
			if ( is_scalar( $id ) && absint( $id ) > 0 ) {
				self::$named[] = absint( $id );
			}
		}
	}

	/**
	 * Gift group and role for each request product, from the first candidate
	 * that holds exactly these lines; null when none does.
	 *
	 * @param array $products Request products: { id, quantity }.
	 * @return array<int, array{group:string, role:string}>|null
	 */
	public static function roles_for( array $products ): ?array {
		self::$source = '';

		$cart   = self::cart_lines();
		$orders = array_values( array_unique( array_merge( array_reverse( self::$named ), array_reverse( self::$recent ) ) ) );

		// On the storefront the cart is what is being quoted. In wp-admin and
		// admin-ajax it never is — it would be the merchant's own cart — so only
		// orders are candidates there.
		if ( null !== $cart && ! is_admin() ) {
			$aligned = CartonQuote::align( $products, $cart );

			if ( null !== $aligned ) {
				self::$source = 'cart';
				return $aligned;
			}
		}

		foreach ( $orders as $id ) {
			$order = $id > 0 ? wc_get_order( $id ) : null;

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$aligned = CartonQuote::align( $products, self::order_lines( $order ) );

			if ( null !== $aligned ) {
				self::$source = 'order #' . $order->get_id();
				return $aligned;
			}
		}

		return null;
	}

	/** Where the last {@see self::roles_for()} found its lines: 'cart', 'order #123' or ''. */
	public static function source(): string {
		return self::$source;
	}

	/**
	 * An order's shippable lines, as the Melhor Envio plugin sends them.
	 *
	 * @return array<int, array{id:int, quantity:int, group:string, role:string, product:\WC_Product}>
	 */
	public static function order_lines( \WC_Order $order ): array {
		self::$reading = true;

		try {
			$items = $order->get_items();
		} finally {
			self::$reading = false;
		}

		$lines = array();

		foreach ( $items as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			// Skipped like the plugin skips them: virtual products, and the
			// components of WPC composite / bundle products.
			if ( ! $product instanceof \WC_Product || $product->is_virtual() || $item->get_meta( 'wooco_parent_id', true ) || $item->get_meta( '_woosb_parent_id', true ) ) {
				continue;
			}

			$lines[] = array(
				'id'       => $product->get_id(),
				'quantity' => (int) $item->get_quantity(),
				'group'    => (string) $item->get_meta( Groups::ITEM_GROUP ),
				'role'     => (string) $item->get_meta( Groups::ITEM_ROLE ),
				'product'  => $product,
			);
		}

		return $lines;
	}

	/**
	 * The cart's shippable lines, or null with no cart.
	 *
	 * @return array<int, array{id:int, quantity:int, group:string, role:string, product:\WC_Product}>|null
	 */
	private static function cart_lines(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}

		$lines = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product instanceof \WC_Product || $product->is_virtual() || isset( $item['wooco_parent_id'] ) || isset( $item['woosb_parent_id'] ) ) {
				continue;
			}

			$group = Groups::group_of( $item );

			$lines[] = array(
				'id'       => $product->get_id(),
				'quantity' => (int) ( $item['quantity'] ?? 0 ),
				'group'    => $group ? $group['id'] : '',
				'role'     => $group ? $group['role'] : '',
				'product'  => $product,
			);
		}

		return $lines;
	}
}

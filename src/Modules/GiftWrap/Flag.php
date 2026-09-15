<?php
/**
 * The gift flag, from the product page to the order.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

use Galaxie\Woo\Modules\Wishlist\Gifts;
use Galaxie\Woo\Support\GiftOrders;

defined( 'ABSPATH' ) || exit;

/**
 * "Estou comprando um presente para alguém", carried through every step
 * WooCommerce can take a cart through.
 *
 * The flag rides on the cart item, not on the session. A gift and the same
 * candle bought for oneself are then two lines — WooCommerce hashes item data
 * into the cart key — and item data is what the classic cart and the Store API
 * (the block cart and checkout) already know how to show and copy to an order,
 * so nothing here is specific to either checkout:
 *
 * - Adding: `woocommerce_add_cart_item_data` reads the posted field, which the
 *   Buy Box's AJAX add and its native submit (Buy Now) both send.
 * - Showing: `woocommerce_get_item_data` is what the classic templates print
 *   and what the Store API serialises as a cart item's `item_data`.
 * - Ordering: `woocommerce_checkout_create_order_line_item` runs for both
 *   checkouts (the Store API builds its line items through WC_Checkout too),
 *   and the order-level tag is written where each checkout finalises the order.
 *
 * The tag's reader — the "Presente" badge in wp-admin — is not here but in
 * {@see GiftOrders}, which runs whether or not this module is on.
 */
final class Flag {

	/** Cart item data key. Phase 2 puts the gift group beside the flag. */
	public const CART_KEY = 'galaxie_gift_wrap';

	/** The Buy Box checkbox's name, and the field the AJAX add copies it into. */
	public const REQUEST_FIELD = 'galaxie_gift_wrap';

	/** On the line item, hidden: which lines were bought as a gift. */
	public const ITEM_META = '_galaxie_gift_wrap';

	public static function hooks(): void {
		add_filter( 'woocommerce_add_cart_item_data', array( self::class, 'attach' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( self::class, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'line_item' ), 10, 3 );

		// After Wishlist\Gifts::address_order (priority 10), which tags its own gift orders.
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'tag_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'tag_order' ), 20, 1 );
	}

	/**
	 * @param mixed $data
	 * @param mixed $product_id
	 * @param mixed $variation_id
	 * @return mixed
	 */
	public static function attach( $data, $product_id, $variation_id = 0 ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- a display flag on WooCommerce's own add-to-cart request, which carries no nonce; the Buy Box AJAX add checks its nonce before adding.
		if ( ! is_array( $data ) || empty( $_REQUEST[ self::REQUEST_FIELD ] ) ) {
			return $data;
		}

		$data[ self::CART_KEY ] = array( 'gift' => true );

		return $data;
	}

	/**
	 * "Presente: Sim" under the product in the cart and checkout, classic and block.
	 *
	 * @param mixed $data
	 * @param mixed $cart_item
	 * @return mixed
	 */
	public static function item_data( $data, $cart_item ) {
		// A candle in a built gift says which one instead ("Presente 1: Vela", Groups).
		if ( is_array( $data ) && self::is_gift_item( $cart_item ) && ! Groups::group_of( $cart_item ) ) {
			$data[] = array(
				'key'   => __( 'Presente', 'galaxie-woo' ),
				'value' => __( 'Sim', 'galaxie-woo' ),
			);
		}

		return $data;
	}

	/** @param mixed $item @param mixed $cart_item_key @param mixed $values */
	public static function line_item( $item, $cart_item_key, $values ): void {
		if ( ! $item instanceof \WC_Order_Item_Product || ! self::is_gift_item( $values ) ) {
			return;
		}

		$item->add_meta_data( self::ITEM_META, 'yes', true );

		if ( ! Groups::group_of( $values ) ) {
			$item->add_meta_data( __( 'Presente', 'galaxie-woo' ), __( 'Sim', 'galaxie-woo' ), true );
		}
	}

	/**
	 * `_galaxie_is_gift = yes` while the cart holds a gift.
	 *
	 * Removed otherwise, because the block checkout reuses its draft order
	 * across attempts: a shopper who unticks the gift and pays must not leave
	 * the tag from the first try behind. A shared wish list's gift is the one
	 * exception — Gifts::address_order tagged it, and it knows its own cart.
	 *
	 * @param mixed $order
	 */
	public static function tag_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( self::cart_has_gift() ) {
			$order->update_meta_data( GiftOrders::ORDER_META, 'yes' );
			return;
		}

		if ( '' === (string) $order->get_meta( Gifts::ORDER_OWNER ) ) {
			$order->delete_meta_data( GiftOrders::ORDER_META );
		}
	}

	/** @param mixed $cart_item */
	public static function is_gift_item( $cart_item ): bool {
		return is_array( $cart_item ) && ! empty( $cart_item[ self::CART_KEY ]['gift'] );
	}

	private static function cart_has_gift(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( self::is_gift_item( $cart_item ) ) {
				return true;
			}
		}

		return false;
	}
}

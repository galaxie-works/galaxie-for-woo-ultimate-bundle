<?php
/**
 * Gifts from a shared wishlist, delivered to the list's owner.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Wishlist;

defined( 'ABSPATH' ) || exit;

/**
 * Someone opens a shared list and gives one of its products. The cart item
 * remembers whose list it came from, and the order ships to that person's
 * saved shipping address — which the buyer never sees.
 *
 * Where the address could leak, and what stops it:
 *
 * - The cart. Rates need a destination, so only the owner's country, state,
 *   city and postcode are put on the session; the street never is.
 * - The checkout. The block checkout sends the shipping address it holds back
 *   to the server and saves it on the buyer's account. Placeholder values are
 *   sent by the gift script, and the buyer's own saved address is put back on
 *   their account before it is saved.
 * - The order. The full address is written onto the order only as it is
 *   created, and anyone but the store's staff sees it formatted as
 *   "Gift for <name> — city/state".
 * - The address book. Gift orders are not filed into the buyer's addresses
 *   (see Modules\AddressBook\Module::file_order_address()).
 *
 * A gift cart holds gifts for one list only: mixing them with the buyer's own
 * shopping would put both under a single delivery address.
 */
final class Gifts {

	public const CART_KEY    = 'galaxie_gift';
	public const REQUEST_ARG = 'galaxie_gift';
	public const PENDING     = 'galaxie_gift_pending';
	public const ORDER_OWNER = '_galaxie_gift_owner';
	public const ORDER_LIST  = '_galaxie_gift_list';

	private const FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );

	public static function hooks(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'validate' ), 20, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( self::class, 'attach' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( self::class, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'ship_to_owner_area' ), 5 );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( self::class, 'keep_buyer_address' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'address_order' ), 10, 1 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'address_order' ), 10, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'line_item' ), 10, 3 );
		add_filter( 'woocommerce_order_get_formatted_shipping_address', array( self::class, 'mask' ), 10, 3 );
	}

	/**
	 * A shared list that accepts gifts, its owner and the owner's address — or
	 * null when any of that is missing, so nothing can be sent nowhere.
	 *
	 * @return array{token:string,user_id:int,list:array<string,mixed>,name:string,address:array<string,string>}|null
	 */
	public static function for_token( string $token ): ?array {
		$shared = Lists::find_shared( $token );

		if ( ! $shared || empty( $shared['list']['gifts'] ) ) {
			return null;
		}

		$customer = new \WC_Customer( $shared['user_id'] );
		$address  = array();

		foreach ( self::FIELDS as $field ) {
			$getter            = 'get_shipping_' . $field;
			$address[ $field ] = is_callable( array( $customer, $getter ) ) ? (string) $customer->{$getter}() : '';
		}

		if ( '' === $address['address_1'] || '' === $address['postcode'] || '' === $address['country'] ) {
			return null;
		}

		$user = get_userdata( $shared['user_id'] );
		$name = trim( (string) ( $user ? $user->first_name : '' ) );

		if ( '' === $name ) {
			$name = '' !== $address['first_name'] ? $address['first_name'] : ( $user ? (string) $user->display_name : '' );
		}

		return array(
			'token'   => (string) $shared['list']['share'],
			'user_id' => (int) $shared['user_id'],
			'list'    => $shared['list'],
			'name'    => $name,
			'address' => $address,
		);
	}

	/** The gift the cart holds, still valid, or null. @return array<string,mixed>|null */
	public static function cart_gift(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item[ self::CART_KEY ]['token'] ) ) {
				return self::for_token( (string) $item[ self::CART_KEY ]['token'] );
			}
		}

		return null;
	}

	/** The gift token this add-to-cart carries: in the request, or remembered from the shared page. */
	private static function incoming_token( int $product_id ): string {
		$token = isset( $_REQUEST[ self::REQUEST_ARG ] ) ? sanitize_key( wp_unslash( $_REQUEST[ self::REQUEST_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an add-to-cart, validated below.

		if ( '' !== $token ) {
			return $token;
		}

		$pending = function_exists( 'WC' ) && WC()->session ? WC()->session->get( self::PENDING ) : null;

		return is_array( $pending ) && (int) ( $pending['product'] ?? 0 ) === $product_id ? (string) ( $pending['token'] ?? '' ) : '';
	}

	/** One delivery per cart: gifts for one list, or the buyer's own shopping, never both. */
	public static function validate( $passed, $product_id ) {
		if ( ! $passed || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $passed;
		}

		$token = self::incoming_token( (int) $product_id );

		if ( '' !== $token && ! self::for_token( $token ) ) {
			wc_add_notice( __( 'Esta lista não está aceitando presentes no momento.', 'galaxie-woo' ), 'error' );
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$held = (string) ( $item[ self::CART_KEY ]['token'] ?? '' );

			if ( $held !== $token ) {
				wc_add_notice(
					'' === $token
						? __( 'Seu carrinho é um presente de uma lista. Finalize esse pedido ou esvazie o carrinho antes de comprar para você.', 'galaxie-woo' )
						: __( 'Seu carrinho já tem outros produtos. Finalize esse pedido ou esvazie o carrinho antes de dar um presente.', 'galaxie-woo' ),
					'error'
				);
				return false;
			}
		}

		return $passed;
	}

	/** @param array<string,mixed> $data */
	public static function attach( $data, $product_id ) {
		$token = self::incoming_token( (int) $product_id );
		$gift  = '' !== $token ? self::for_token( $token ) : null;

		if ( $gift ) {
			$data[ self::CART_KEY ] = array(
				'token' => $gift['token'],
				'owner' => $gift['user_id'],
				'list'  => (string) $gift['list']['id'],
				'name'  => $gift['name'],
			);

			if ( WC()->session ) {
				WC()->session->set( self::PENDING, null );
			}
		}

		return $data;
	}

	/** "Presente para Wagner" under the product in the cart and checkout. */
	public static function item_data( $data, $cart_item ) {
		if ( ! empty( $cart_item[ self::CART_KEY ]['name'] ) ) {
			$data[] = array(
				'key'   => __( 'Presente para', 'galaxie-woo' ),
				'value' => (string) $cart_item[ self::CART_KEY ]['name'],
			);
		}

		return $data;
	}

	/** Rates to the owner's area: country, state, postcode and city — never the street. */
	public static function ship_to_owner_area(): void {
		static $running = false;

		if ( $running || ! WC()->customer ) {
			return;
		}

		$gift = self::cart_gift();

		if ( ! $gift ) {
			return;
		}

		$running = true;
		WC()->customer->set_shipping_location( $gift['address']['country'], $gift['address']['state'], $gift['address']['postcode'], $gift['address']['city'] );
		$running = false;
	}

	/** The placeholders the checkout sent must not replace the buyer's own saved address. */
	public static function keep_buyer_address( $customer ): void {
		if ( ! $customer instanceof \WC_Customer || ! $customer->get_id() || ! self::cart_gift() ) {
			return;
		}

		foreach ( self::FIELDS as $field ) {
			$setter = 'set_shipping_' . $field;

			if ( is_callable( array( $customer, $setter ) ) ) {
				$customer->{$setter}( (string) get_user_meta( $customer->get_id(), 'shipping_' . $field, true ) );
			}
		}
	}

	/** The owner's full address onto the order, as it is created. */
	public static function address_order( $order ): void {
		$gift = $order instanceof \WC_Order ? self::cart_gift() : null;

		if ( ! $gift ) {
			return;
		}

		foreach ( $gift['address'] as $field => $value ) {
			$setter = 'set_shipping_' . $field;

			if ( is_callable( array( $order, $setter ) ) ) {
				$order->{$setter}( $value );
			}
		}

		$order->update_meta_data( self::ORDER_OWNER, $gift['user_id'] );
		$order->update_meta_data( self::ORDER_LIST, (string) $gift['list']['id'] );
	}

	public static function line_item( $item, $cart_item_key, $values ): void {
		if ( $item instanceof \WC_Order_Item_Product && ! empty( $values[ self::CART_KEY ]['name'] ) ) {
			$item->add_meta_data( __( 'Presente para', 'galaxie-woo' ), (string) $values[ self::CART_KEY ]['name'], true );
		}
	}

	/**
	 * A gift order's address as the buyer sees it — on the thank-you page, in
	 * their orders and in their e-mails. Staff in wp-admin and the owner see it
	 * whole.
	 */
	public static function mask( $address, $raw, $order ) {
		if ( ! $order instanceof \WC_Order || ! $order->get_meta( self::ORDER_OWNER ) ) {
			return $address;
		}

		$owner = (int) $order->get_meta( self::ORDER_OWNER );

		if ( ( is_admin() && ! wp_doing_ajax() && current_user_can( 'edit_shop_orders' ) ) || get_current_user_id() === $owner ) {
			return $address;
		}

		$user  = get_userdata( $owner );
		$name  = $user && '' !== $user->first_name ? $user->first_name : (string) $order->get_shipping_first_name();
		$place = trim( $order->get_shipping_city() . ( $order->get_shipping_state() ? '/' . $order->get_shipping_state() : '' ), '/' );

		/* translators: 1: the list owner's first name, 2: city/state. */
		return esc_html( sprintf( __( 'Presente para %1$s — %2$s', 'galaxie-woo' ), $name, $place ) );
	}
}

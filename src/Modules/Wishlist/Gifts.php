<?php
/**
 * Gifts from a shared wishlist, delivered to the list's owner.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Wishlist;

use Galaxie\Woo\Support\BrazilianPostcode;

defined( 'ABSPATH' ) || exit;

/**
 * Someone opens a shared list and gives one of its products. The cart item
 * remembers whose list it came from, and the order ships to that person's
 * saved shipping address — which the buyer never sees.
 *
 * Where the address could leak, and what stops it:
 *
 * - The cart. Rates are quoted on the server, with the owner's real address
 *   put on the shipping packages only. The session — and so everything the
 *   browser is sent — holds the owner's country, state and city with a stand-in
 *   CEP of that state, and every Store API answer has its addresses cut down
 *   to that before it leaves. The "Shipping to" lines and the calculator are
 *   not drawn for a gift.
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
		add_filter( 'woocommerce_cart_shipping_packages', array( self::class, 'ship_packages' ), 5 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'scrub_store_api' ), 10, 3 );
		add_filter( 'galaxie_cart_shipping_destination', array( self::class, 'hide_destination' ) );
		add_filter( 'galaxie_cart_shipping_calculator_hidden', array( self::class, 'hide_calculator' ) );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
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

	/** The gift this product page was opened for, still valid, or null. @return array<string,mixed>|null */
	public static function pending_for( int $product_id ): ?array {
		$pending = function_exists( 'WC' ) && WC()->session ? WC()->session->get( self::PENDING ) : null;

		if ( ! is_array( $pending ) || (int) ( $pending['product'] ?? 0 ) !== $product_id ) {
			return null;
		}

		return self::for_token( (string) ( $pending['token'] ?? '' ) );
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

	/**
	 * A CEP of the owner's state, for everything the browser sees; the real one
	 * stays on the server. Outside Brazil there is no range to pick from, and the
	 * real postcode is used — the store ships within Brazil.
	 *
	 * @param array<string,string> $address
	 */
	public static function placeholder_postcode( array $address ): string {
		if ( 'BR' === strtoupper( (string) ( $address['country'] ?? '' ) ) ) {
			$first = BrazilianPostcode::first_of( (string) ( $address['state'] ?? '' ) );

			if ( null !== $first ) {
				return $first;
			}
		}

		return (string) ( $address['postcode'] ?? '' );
	}

	public static function address_placeholder(): string {
		return __( 'Endereço de quem recebe o presente', 'galaxie-woo' );
	}

	/** The session's shipping location: the owner's country, state and city, with a stand-in CEP. */
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
		WC()->customer->set_shipping_location( $gift['address']['country'], $gift['address']['state'], self::placeholder_postcode( $gift['address'] ), $gift['address']['city'] );
		$running = false;
	}

	/**
	 * Rates quoted to the owner's real address. Carriers and zones read the
	 * package's destination, so this is where it goes — and nowhere the browser
	 * is sent.
	 *
	 * @param array<int,array<string,mixed>> $packages
	 */
	public static function ship_packages( $packages ) {
		$gift = is_array( $packages ) ? self::cart_gift() : null;

		if ( ! $gift ) {
			return $packages;
		}

		foreach ( $packages as $index => $package ) {
			$packages[ $index ]['destination'] = array_merge(
				(array) ( $package['destination'] ?? array() ),
				array_intersect_key( $gift['address'], array_flip( array( 'country', 'state', 'postcode', 'city', 'address_1', 'address_2' ) ) )
			);
		}

		return $packages;
	}

	/**
	 * Store API answers — the cart, its rates, the checkout, an order — with
	 * every address in them cut down to the area, whenever a gift is involved.
	 * Runs per request inside a batch, and for the data a block page preloads.
	 *
	 * @param mixed $response
	 * @param mixed $handler
	 * @param mixed $request
	 */
	public static function scrub_store_api( $response, $handler, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request || 0 !== strpos( $request->get_route(), '/wc/store' ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		$gift    = self::cart_gift();
		$address = $gift ? $gift['address'] : null;

		if ( null === $address ) {
			$order = self::response_order( $request->get_route(), $data );

			if ( ! $order || ! $order->get_meta( self::ORDER_OWNER ) || current_user_can( 'edit_shop_orders' ) || get_current_user_id() === (int) $order->get_meta( self::ORDER_OWNER ) ) {
				return $response;
			}

			$address = array( 'country' => $order->get_shipping_country(), 'state' => $order->get_shipping_state(), 'postcode' => $order->get_shipping_postcode() );
		}

		$response->set_data( self::scrub( $data, self::placeholder_postcode( $address ) ) );

		// A gift answer belongs to one shopper. LiteSpeed caches REST answers
		// unless told not to, and served one of these to every visitor.
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		do_action( 'litespeed_control_set_nocache', 'galaxie gift cart' );

		return $response;
	}

	/** @param array<string,mixed> $data */
	private static function response_order( string $route, array $data ): ?\WC_Order {
		$id    = preg_match( '#/order/(\d+)#', $route, $match ) ? (int) $match[1] : (int) ( $data['order_id'] ?? 0 );
		$order = $id ? wc_get_order( $id ) : null;

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Walks arrays and plain objects alike: the Store API casts its addresses
	 * and each package's destination to `(object)` so they encode as `{}`, and
	 * a walk over arrays alone passed straight over them.
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	private static function scrub( $data, string $postcode ) {
		$is_object = $data instanceof \stdClass;

		if ( ! $is_object && ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $is_object ? get_object_vars( $data ) : $data as $key => $value ) {
			if ( in_array( $key, array( 'shipping_address', 'destination' ), true ) ) {
				$value = self::hide_address( $value, $postcode );
			}

			$value = self::scrub( $value, $postcode );

			if ( $is_object ) {
				$data->{$key} = $value;
			} else {
				$data[ $key ] = $value;
			}
		}

		return $data;
	}

	/**
	 * One address down to its area, in the shape it came in.
	 *
	 * @param mixed $address
	 * @return mixed
	 */
	private static function hide_address( $address, string $postcode ) {
		$is_object = $address instanceof \stdClass;
		$fields    = $is_object ? get_object_vars( $address ) : $address;

		if ( ! is_array( $fields ) || ! array_key_exists( 'postcode', $fields ) ) {
			return $address;
		}

		$fields['postcode'] = $postcode;

		if ( array_key_exists( 'address_1', $fields ) ) {
			$fields['address_1'] = self::address_placeholder();
		}

		foreach ( array( 'address_2', 'company', 'phone' ) as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$fields[ $field ] = '';
			}
		}

		return $is_object ? (object) $fields : $fields;
	}

	/** Our cart widgets' "Shipping to …" line, which would print the stand-in. */
	public static function hide_destination( $destination ) {
		return self::cart_gift() ? '' : $destination;
	}

	/** A calculator for a parcel whose destination is already decided, and hidden. */
	public static function hide_calculator( $hidden ) {
		return $hidden || (bool) self::cart_gift();
	}

	/** @param array<int,string> $classes */
	public static function body_class( $classes ) {
		$page = ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() );

		if ( $page && is_array( $classes ) && self::cart_gift() ) {
			$classes[] = 'galaxie-gift-checkout';
		}

		return $classes;
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

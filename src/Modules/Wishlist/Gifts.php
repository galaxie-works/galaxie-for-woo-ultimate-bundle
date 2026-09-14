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
 * saved shipping address — which the buyer never sees. The buyer may see the
 * owner's first name and city/state, nothing more.
 *
 * Where the address could leak, and what stops it:
 *
 * - The cart. Rates are quoted on the server, with the owner's real address
 *   put on the shipping packages only. The session — and so everything the
 *   browser is sent — holds the owner's country, state and city with a stand-in
 *   CEP of that state, and every Store API answer has its addresses cut down
 *   to that before it leaves (the surname too). The "Shipping to" lines and the
 *   calculator are not drawn for a gift. Once the cart holds no gift, the
 *   buyer's own shipping location is put back on the session.
 * - The checkout. The block checkout sends the shipping address it holds back
 *   to the server and saves it on the buyer's account. Placeholder values are
 *   sent by the gift script, and the buyer's own saved address is put back on
 *   their account before it is saved.
 * - The buyer's account. After the order is written, WooCommerce copies its
 *   shipping address onto the customer's account (Store API
 *   `sync_customer_data_with_order()`, classic `WC_Checkout::process_customer()`).
 *   For the rest of a gift checkout request, writes to that account's
 *   `shipping_*` user meta are refused (see {@see self::block_shipping_meta()}).
 * - The order. The full address is written onto the order only as it is
 *   created. Who sees it whole is decided by who is reading, in one place
 *   ({@see self::reveals_address()}): e-mails to the customer are masked
 *   whoever sends them, e-mails to the store are not; elsewhere staff and the
 *   owner see it whole and everyone else sees "Gift for <name> — city/state",
 *   without the owner's phone.
 * - The payment gateway. Stripe is not sent a shipping address for a gift: a
 *   PaymentIntent's shipping can be read in the browser with its client secret.
 * - The address book. Gift orders are not filed into the buyer's addresses
 *   (see Modules\AddressBook\Module::file_order_address()).
 *
 * A gift cart holds gifts for one list only: mixing them with the buyer's own
 * shopping would put both under a single delivery address. Nor can a gift be
 * picked up at the store — it would never reach the owner.
 */
final class Gifts {

	public const CART_KEY    = 'galaxie_gift';
	public const REQUEST_ARG = 'galaxie_gift';
	public const PENDING     = 'galaxie_gift_pending';
	public const ORDER_OWNER = '_galaxie_gift_owner';
	public const ORDER_LIST  = '_galaxie_gift_list';

	/** Set on the product page a gift link redirects to; carries no secret (see SharedPage::remember_gift()). */
	public const VIEW_ARG = 'galaxie_gift_view';

	/** How long a product page opened from a gift link keeps its gift, in seconds. */
	public const PENDING_TTL = 1800;

	/** The buyer's shipping location before a gift replaced it on the session. */
	private const AREA_BEFORE = 'galaxie_gift_area_before';

	private const FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );

	/** Classic Local Pickup, and the block checkout's pickup locations. */
	private const PICKUP_METHODS = array( 'local_pickup', 'pickup_location' );

	/**
	 * The account a gift checkout in this request is placing an order for:
	 * null when none is, 0 while the buyer is still a guest (an account may be
	 * created for them mid-request, and becomes the current user).
	 *
	 * @var int|null
	 */
	private static $checkout_user = null;

	/**
	 * Who the WooCommerce e-mail being drawn is addressed to — 'customer' or
	 * 'admin' — as learned from its header, and from its customer details
	 * section (plain-text e-mails have no header). Null outside an e-mail.
	 *
	 * @var array{header:?string,details:?string}
	 */
	private static $email = array( 'header' => null, 'details' => null );

	public static function hooks(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'validate' ), 20, 4 );
		add_filter( 'woocommerce_add_cart_item_data', array( self::class, 'attach' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( self::class, 'item_data' ), 10, 2 );
		// Merging runs before the restore below, so it restores for a cart it just emptied of gifts.
		add_action( 'woocommerce_cart_loaded_from_session', array( self::class, 'separate_merged_cart' ), 5 );
		add_action( 'woocommerce_cart_loaded_from_session', array( self::class, 'restore_buyer_area' ), 20, 0 );
		// An emptied cart skips `woocommerce_before_calculate_totals`, so the restore listens here as well.
		add_action( 'woocommerce_cart_emptied', array( self::class, 'restore_buyer_area' ), 20, 0 );
		add_action( 'woocommerce_cart_item_removed', array( self::class, 'restore_buyer_area' ), 20, 0 );
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'ship_to_owner_area' ), 5 );
		add_filter( 'woocommerce_cart_shipping_packages', array( self::class, 'ship_packages' ), 5 );
		add_filter( 'woocommerce_package_rates', array( self::class, 'no_pickup' ), 10, 1 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'scrub_store_api' ), 10, 3 );
		// The block cart and checkout preload their Store API data by calling the
		// route directly, which skips the REST filter above and fires this instead.
		add_filter( 'woocommerce_hydration_request_after_callbacks', array( self::class, 'scrub_store_api' ), 10, 3 );
		add_filter( 'galaxie_cart_shipping_destination', array( self::class, 'hide_destination' ) );
		add_filter( 'galaxie_cart_shipping_calculator_hidden', array( self::class, 'hide_calculator' ) );
		add_filter( 'body_class', array( self::class, 'body_class' ) );

		// The buyer's account, for the length of a gift checkout. Store API order:
		// update_customer_from_request (fires the action below, then saves the
		// session customer) → update_order_from_request (address_order writes the
		// owner's address) → process_customer → sync_customer_data_with_order,
		// which copies the order's shipping_* onto the account. Classic:
		// woocommerce_checkout_process → create_order (address_order) →
		// process_customer, which saves the posted shipping_* onto the account.
		// The guard starts first, at priority 1, and lasts until the request ends.
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( self::class, 'guard_account' ), 1, 1 );
		add_action( 'woocommerce_checkout_process', array( self::class, 'guard_account' ), 1, 0 );
		add_filter( 'update_user_metadata', array( self::class, 'block_shipping_meta' ), 1, 3 );
		add_filter( 'add_user_metadata', array( self::class, 'block_shipping_meta' ), 1, 3 );
		add_filter( 'delete_user_metadata', array( self::class, 'block_shipping_meta' ), 1, 3 );
		// Classic checkout stores unknown `shipping_*` fields as WC meta data, which updates and deletes rows by id.
		add_filter( 'update_user_metadata_by_mid', array( self::class, 'block_shipping_meta_by_mid' ), 1, 2 );
		add_filter( 'delete_user_metadata_by_mid', array( self::class, 'block_shipping_meta_by_mid' ), 1, 2 );

		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( self::class, 'keep_buyer_address' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'address_order' ), 10, 1 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'address_order' ), 10, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'line_item' ), 10, 3 );

		// Who an e-mail is for. Header and footer wrap an HTML e-mail's body;
		// `woocommerce_email_customer_details` prints the addresses (WC_Emails
		// ::email_addresses at priority 20) in HTML and plain text alike, so it is
		// bracketed on both sides. `woocommerce_mail_content` runs in
		// WC_Email::send() once the content is drawn: nothing survives it.
		add_action( 'woocommerce_email_header', array( self::class, 'email_header' ), 0, 2 );
		add_action( 'woocommerce_email_footer', array( self::class, 'email_footer' ), PHP_INT_MAX, 0 );
		add_action( 'woocommerce_email_customer_details', array( self::class, 'email_details_open' ), 0, 4 );
		add_action( 'woocommerce_email_customer_details', array( self::class, 'email_details_close' ), PHP_INT_MAX, 0 );
		add_filter( 'woocommerce_mail_content', array( self::class, 'email_sent' ), 0 );

		add_filter( 'woocommerce_order_get_formatted_shipping_address', array( self::class, 'mask' ), 10, 3 );
		add_filter( 'woocommerce_order_get_shipping_phone', array( self::class, 'mask_phone' ), 10, 2 );

		// Stripe's own request builders (legacy card gateway and intents), and the
		// HTTP call itself for any builder these filters do not reach.
		add_filter( 'wc_stripe_generate_payment_request', array( self::class, 'stripe_request' ), 10, 2 );
		add_filter( 'wc_stripe_generate_create_intent_request', array( self::class, 'stripe_request' ), 10, 2 );
		add_filter( 'http_request_args', array( self::class, 'stripe_http' ), 10, 2 );
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

		return array(
			'token'   => (string) $shared['list']['share'],
			'user_id' => (int) $shared['user_id'],
			'list'    => $shared['list'],
			'name'    => self::owner_name( (int) $shared['user_id'], $address['first_name'] ),
			'address' => $address,
		);
	}

	/**
	 * The name a gift is given under: the owner's first name, else the first
	 * name on their shipping address, else a neutral word. Never the display
	 * name — for an account made from an e-mail address, that is the e-mail.
	 */
	public static function owner_name( int $user_id, string $shipping_first_name = '' ): string {
		$user = $user_id ? get_userdata( $user_id ) : false;
		$name = trim( (string) ( $user ? $user->first_name : '' ) );

		if ( '' === $name ) {
			$name = trim( '' !== $shipping_first_name ? $shipping_first_name : ( $user ? (string) get_user_meta( $user_id, 'shipping_first_name', true ) : '' ) );
		}

		return '' !== $name ? $name : __( 'alguém', 'galaxie-woo' );
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

	/**
	 * The gift a product page was opened for, as the session remembers it, or
	 * null once it is older than {@see self::PENDING_TTL}.
	 *
	 * @return array{token:string,product:int,time:int}|null
	 */
	public static function pending_gift(): ?array {
		$pending = function_exists( 'WC' ) && WC()->session ? WC()->session->get( self::PENDING ) : null;

		if ( ! is_array( $pending ) || empty( $pending['token'] ) || time() - (int) ( $pending['time'] ?? 0 ) > self::PENDING_TTL ) {
			return null;
		}

		return array(
			'token'   => (string) $pending['token'],
			'product' => (int) ( $pending['product'] ?? 0 ),
			'time'    => (int) $pending['time'],
		);
	}

	/** The gift token this add-to-cart carries: in the request, or remembered from the shared page. */
	private static function incoming_token( int $product_id ): string {
		$token = isset( $_REQUEST[ self::REQUEST_ARG ] ) ? sanitize_key( wp_unslash( $_REQUEST[ self::REQUEST_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an add-to-cart, validated below.

		if ( '' !== $token ) {
			return $token;
		}

		$pending = self::pending_gift();

		return $pending && $pending['product'] === $product_id ? $pending['token'] : '';
	}

	/** The gift this product page was opened for, still valid, or null. @return array<string,mixed>|null */
	public static function pending_for( int $product_id ): ?array {
		$pending = self::pending_gift();

		if ( ! $pending || $pending['product'] !== $product_id ) {
			return null;
		}

		return self::for_token( $pending['token'] );
	}

	/**
	 * Whether a product is one the list asks for: the product itself, or — for
	 * a variation — its parent. A token opens that list's products, not the shop.
	 *
	 * @param array<string,mixed> $gift
	 */
	public static function on_list( array $gift, int $product_id, int $variation_id = 0 ): bool {
		$items = array_map( 'intval', (array) ( $gift['list']['items'] ?? array() ) );

		foreach ( array_filter( array( $variation_id, $product_id ) ) as $id ) {
			if ( in_array( $id, $items, true ) ) {
				return true;
			}

			$product = wc_get_product( $id );
			$parent  = $product ? (int) $product->get_parent_id() : 0;

			if ( $parent && in_array( $parent, $items, true ) ) {
				return true;
			}
		}

		return false;
	}

	/** One delivery per cart: gifts for one list, or the buyer's own shopping, never both. */
	public static function validate( $passed, $product_id, $quantity = 0, $variation_id = 0 ) {
		if ( ! $passed || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $passed;
		}

		$token = self::incoming_token( (int) $product_id );
		$gift  = '' !== $token ? self::for_token( $token ) : null;

		if ( '' !== $token && ! $gift ) {
			wc_add_notice( __( 'Esta lista não está aceitando presentes no momento.', 'galaxie-woo' ), 'error' );
			return false;
		}

		if ( $gift && ! self::on_list( $gift, (int) $product_id, (int) $variation_id ) ) {
			wc_add_notice( __( 'Este produto não faz parte desta lista de presentes.', 'galaxie-woo' ), 'error' );
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
	public static function attach( $data, $product_id, $variation_id = 0 ) {
		$token = self::incoming_token( (int) $product_id );
		$gift  = '' !== $token ? self::for_token( $token ) : null;

		if ( $gift && self::on_list( $gift, (int) $product_id, (int) $variation_id ) ) {
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
	 * A cart that holds gifts beside other things after logging in. WooCommerce
	 * merges the account's saved cart into the session's in
	 * WC_Cart_Session::get_cart_from_session() without add-to-cart validation,
	 * then fires this action — and, because it merged, saves the session and the
	 * saved cart right after, so what is removed here stays removed.
	 *
	 * The buyer's own shopping is kept and the gifts go; with no shopping of
	 * their own, the gifts for the first list are kept. Items are dropped, not
	 * removed with an "undo" link: restoring one would mix the cart again.
	 *
	 * @param mixed $cart
	 */
	public static function separate_merged_cart( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		$contents = $cart->get_cart_contents();
		$gifts    = array();
		$own      = false;

		foreach ( $contents as $key => $item ) {
			$token = (string) ( $item[ self::CART_KEY ]['token'] ?? '' );

			if ( '' === $token ) {
				$own = true;
			} else {
				$gifts[ $token ][] = $key;
			}
		}

		if ( ! $gifts || ( ! $own && 1 === count( $gifts ) ) ) {
			return;
		}

		$drop = $own ? $gifts : array_slice( $gifts, 1, null, true );

		foreach ( $drop as $keys ) {
			foreach ( $keys as $key ) {
				unset( $contents[ $key ] );
			}
		}

		$cart->set_cart_contents( $contents );

		if ( ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		wc_add_notice(
			$own
				? __( 'Tiramos do carrinho os presentes de uma lista, porque ele já tinha as suas compras. Para dar o presente, finalize ou esvazie este carrinho e abra a lista de novo.', 'galaxie-woo' )
				: __( 'Tiramos do carrinho os presentes de outra lista: cada pedido vai para uma pessoa só. Para dar esses presentes, finalize este pedido e abra a outra lista de novo.', 'galaxie-woo' ),
			'notice'
		);
	}

	/** Pickup options, for a parcel that must reach the list's owner. */
	public static function no_pickup( $rates ) {
		if ( ! is_array( $rates ) || ! $rates || ! self::cart_gift() ) {
			return $rates;
		}

		foreach ( $rates as $id => $rate ) {
			if ( $rate instanceof \WC_Shipping_Rate && in_array( $rate->get_method_id(), self::PICKUP_METHODS, true ) ) {
				unset( $rates[ $id ] );
			}
		}

		return $rates;
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

	/**
	 * The session's shipping location: the owner's country, state and city, with
	 * a stand-in CEP. The buyer's own shipping fields are kept aside on the
	 * session the first time, for {@see self::restore_buyer_area()}.
	 */
	public static function ship_to_owner_area(): void {
		static $running = false;

		if ( $running || ! WC()->customer ) {
			return;
		}

		$gift = self::cart_gift();

		if ( ! $gift ) {
			self::restore_buyer_area();
			return;
		}

		$running = true;

		if ( WC()->session && ! is_array( WC()->session->get( self::AREA_BEFORE ) ) ) {
			$before = array();

			foreach ( self::FIELDS as $field ) {
				$getter           = 'get_shipping_' . $field;
				$before[ $field ] = is_callable( array( WC()->customer, $getter ) ) ? (string) WC()->customer->{$getter}( 'edit' ) : '';
			}

			// Whose fields these are: a guest's snapshot is not a logged-in account's address.
			$before['user'] = get_current_user_id();

			WC()->session->set( self::AREA_BEFORE, $before );
		}

		WC()->customer->set_shipping_location( $gift['address']['country'], $gift['address']['state'], self::placeholder_postcode( $gift['address'] ), $gift['address']['city'] );
		$running = false;
	}

	/**
	 * The buyer's own shipping fields back on the session once the cart holds no
	 * gift — emptied after the order, cleared by hand, or its gifts dropped by a
	 * merge — instead of the owner's city and the stand-in CEP and address line.
	 */
	public static function restore_buyer_area(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->customer ) {
			return;
		}

		$before = WC()->session->get( self::AREA_BEFORE );

		if ( ! is_array( $before ) || self::cart_gift() ) {
			return;
		}

		WC()->session->set( self::AREA_BEFORE, null );

		// Taken for someone else — a guest who has since logged in, or had an
		// account made at checkout: the guest session's fields are not this
		// account's address. After login WooCommerce reads the account and lays
		// the session's customer data over it, which still holds the gift's
		// placeholders, so the account's own saved shipping address is put back.
		// A new account has none (see block_shipping_meta()), and gets blanks.
		$user = get_current_user_id();

		if ( $user && (int) ( $before['user'] ?? 0 ) !== $user ) {
			foreach ( self::FIELDS as $field ) {
				$before[ $field ] = (string) get_user_meta( $user, 'shipping_' . $field, true );
			}
		}

		foreach ( $before as $field => $value ) {
			$setter = 'set_shipping_' . $field;

			if ( in_array( $field, self::FIELDS, true ) && is_callable( array( WC()->customer, $setter ) ) ) {
				WC()->customer->{$setter}( (string) $value );
			}
		}

		// Saved now, not left to the shutdown save WooCommerce hooks onto the
		// customer it made in initialize_cart(): the snapshot is already gone
		// from the session, so a restore that never reached it could not be
		// redone, and the next request would quote the recipient's area again.
		// The session customer writes to the session only; were this a database
		// customer, its shipping_* writes during a gift checkout are refused by
		// block_shipping_meta(), and otherwise they are the buyer's own values.
		WC()->customer->save();
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

			if ( ! $order || ! $order->get_meta( self::ORDER_OWNER ) || self::reveals_address( $order ) ) {
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
	 * One address down to its area and a first name, in the shape it came in.
	 *
	 * The surname becomes the first name again. That is the stand-in the gift
	 * checkout script fills in itself (the block checkout wants a surname), so
	 * the address it gets back matches what it sent and it has nothing to
	 * resend; a blank surname would set the two against each other.
	 *
	 * Fields added to the address by extensions are left alone: the order takes
	 * only the owner's core address fields (see {@see self::address_order()}),
	 * so any others hold what the buyer typed.
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

		if ( array_key_exists( 'last_name', $fields ) ) {
			$fields['last_name'] = (string) ( $fields['first_name'] ?? '' );
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

	/**
	 * A gift checkout has begun: its buyer's account is guarded for the rest of
	 * the request. The Store API hands over the session customer (id 0 for a
	 * guest); the classic checkout hands over nothing, and the current user is
	 * the buyer.
	 *
	 * @param mixed $customer
	 */
	public static function guard_account( $customer = null ): void {
		if ( ! self::cart_gift() ) {
			return;
		}

		self::$checkout_user = $customer instanceof \WC_Customer ? (int) $customer->get_id() : get_current_user_id();
	}

	/**
	 * Refuses writes to the buyer's `shipping_*` user meta while a gift checkout
	 * is in flight, so the owner's address WooCommerce copies from the order —
	 * or the placeholders the checkout posted — never reach the account, and
	 * the buyer's own saved address stays as it was. Returning non-null
	 * short-circuits add/update/delete_metadata(); true reports success, so
	 * WooCommerce carries on as if saved.
	 *
	 * A guest's guard follows whoever becomes the current user: an account made
	 * during the checkout is logged in (wc_set_customer_auth_cookie() →
	 * wp_set_current_user()) before its data is saved.
	 *
	 * @param mixed $check
	 * @param mixed $object_id
	 * @param mixed $meta_key
	 * @return mixed
	 */
	public static function block_shipping_meta( $check, $object_id, $meta_key ) {
		if ( null !== $check || ! self::guards_user( (int) $object_id ) || 0 !== strpos( (string) $meta_key, 'shipping_' ) ) {
			return $check;
		}

		return true;
	}

	/**
	 * The same, for meta written by row id.
	 *
	 * @param mixed $check
	 * @param mixed $meta_id
	 * @return mixed
	 */
	public static function block_shipping_meta_by_mid( $check, $meta_id ) {
		if ( null !== $check || null === self::$checkout_user ) {
			return $check;
		}

		$meta = get_metadata_by_mid( 'user', (int) $meta_id );

		return $meta && self::guards_user( (int) $meta->user_id ) && 0 === strpos( (string) $meta->meta_key, 'shipping_' ) ? true : $check;
	}

	private static function guards_user( int $user_id ): bool {
		if ( null === self::$checkout_user || ! $user_id ) {
			return false;
		}

		return $user_id === ( self::$checkout_user ?: get_current_user_id() );
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

	/** @param mixed $heading @param mixed $email */
	public static function email_header( $heading = '', $email = null ): void {
		self::$email['header'] = self::audience( $email, null );
	}

	public static function email_footer(): void {
		self::$email['header'] = null;
	}

	/** @param mixed $order @param mixed $sent_to_admin @param mixed $plain_text @param mixed $email */
	public static function email_details_open( $order = null, $sent_to_admin = null, $plain_text = false, $email = null ): void {
		self::$email['details'] = self::audience( $email, $sent_to_admin );
	}

	public static function email_details_close(): void {
		self::$email['details'] = null;
	}

	/** @param mixed $content */
	public static function email_sent( $content ) {
		self::$email = array( 'header' => null, 'details' => null );
		return $content;
	}

	/**
	 * 'customer' or 'admin' for a WooCommerce e-mail, from the e-mail itself or,
	 * when a template passes none, from `$sent_to_admin`; null if neither says.
	 *
	 * @param mixed $email
	 * @param mixed $sent_to_admin
	 */
	private static function audience( $email, $sent_to_admin ): ?string {
		if ( $email instanceof \WC_Email ) {
			return $email->is_customer_email() ? 'customer' : 'admin';
		}

		return null === $sent_to_admin ? null : ( $sent_to_admin ? 'admin' : 'customer' );
	}

	/**
	 * Whether a gift order's full shipping address — and the owner's phone —
	 * may be shown here. Decided by who reads it, not who causes it to be drawn:
	 *
	 * - Inside a WooCommerce e-mail: whole in e-mails to the store, masked in
	 *   e-mails to the customer — even one staff send from wp-admin (completed
	 *   order, customer note, order details), and even when the buyer is logged
	 *   in as they trigger an e-mail to the store.
	 * - Anywhere else: whole for staff (wp-admin, its AJAX order preview, the
	 *   front end) and for the list's owner; masked for everyone else.
	 */
	public static function reveals_address( \WC_Order $order ): bool {
		$audience = self::$email['details'] ?? self::$email['header'];

		if ( null !== $audience ) {
			return 'admin' === $audience;
		}

		$owner = (int) $order->get_meta( self::ORDER_OWNER );

		return current_user_can( 'edit_shop_orders' ) || ( $owner && get_current_user_id() === $owner );
	}

	private static function is_gift_order( $order ): bool {
		return $order instanceof \WC_Order && (bool) $order->get_meta( self::ORDER_OWNER );
	}

	/**
	 * A gift order's address where the reader may not see it whole: "Presente
	 * para <first name> — city/state" — on the thank-you page, in the buyer's
	 * orders and in e-mails to them.
	 */
	public static function mask( $address, $raw, $order ) {
		if ( ! self::is_gift_order( $order ) || self::reveals_address( $order ) ) {
			return $address;
		}

		$name  = self::owner_name( (int) $order->get_meta( self::ORDER_OWNER ), (string) $order->get_shipping_first_name() );
		$place = trim( $order->get_shipping_city() . ( $order->get_shipping_state() ? '/' . $order->get_shipping_state() : '' ), '/' );

		/* translators: 1: the list owner's first name, 2: city/state. */
		return esc_html( sprintf( __( 'Presente para %1$s — %2$s', 'galaxie-woo' ), $name, $place ) );
	}

	/**
	 * The owner's phone, which core templates print beside the address (the
	 * order details and e-mail address blocks), under the same rule. Filters
	 * the 'view' context only: the order edit screen and saving read 'edit'.
	 *
	 * @param mixed $phone
	 * @param mixed $order
	 * @return mixed
	 */
	public static function mask_phone( $phone, $order = null ) {
		if ( '' === (string) $phone || ! self::is_gift_order( $order ) || self::reveals_address( $order ) ) {
			return $phone;
		}

		return '';
	}

	/**
	 * No shipping address on a Stripe request for a gift order. Stripe copies it
	 * onto the PaymentIntent, which the buyer's browser can read with the
	 * intent's client secret. Filters that do not exist in the installed
	 * gateway version — or no gateway at all — simply never call this.
	 *
	 * @param mixed $request
	 * @param mixed $order
	 * @return mixed
	 */
	public static function stripe_request( $request, $order = null ) {
		if ( is_array( $request ) && array_key_exists( 'shipping', $request ) && self::is_gift_order( $order ) ) {
			unset( $request['shipping'] );
		}

		return $request;
	}

	/**
	 * The last stop before Stripe, for request builders the filters above miss
	 * (the gateway's newer payment-element code has changed across versions).
	 * Drops `shipping` from a call to api.stripe.com while a gift checkout is in
	 * flight, or when the call names a gift order in its metadata.
	 *
	 * @param mixed $args
	 * @param mixed $url
	 * @return mixed
	 */
	public static function stripe_http( $args, $url ) {
		if ( ! is_array( $args ) || ! is_array( $args['body'] ?? null ) || ! array_key_exists( 'shipping', $args['body'] ) ) {
			return $args;
		}

		if ( 'api.stripe.com' !== wp_parse_url( (string) $url, PHP_URL_HOST ) ) {
			return $args;
		}

		$order_id = absint( $args['body']['metadata']['order_id'] ?? 0 );

		if ( null !== self::$checkout_user || ( $order_id && function_exists( 'wc_get_order' ) && self::is_gift_order( wc_get_order( $order_id ) ) ) ) {
			unset( $args['body']['shipping'] );
		}

		return $args;
	}
}

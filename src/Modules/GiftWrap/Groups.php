<?php
/**
 * Gifts in the cart: candles and their box, ribbons and cards as one group.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

use Galaxie\Woo\Support\GiftGroups;
use Galaxie\Woo\Support\GiftPacking;

defined( 'ABSPATH' ) || exit;

/**
 * A gift is a group id carried in the cart item data of everything in it —
 * `galaxie_gift_group => [ id, role, message? ]` on each candle, the box, each
 * ribbon and each card. Accessories are ordinary cart lines at their own price;
 * the id is what ties them to the candles.
 *
 * Why item data and not a session table of groups:
 * - WooCommerce hashes item data into the cart key, so the same candle in two
 *   gifts, or two cards with different messages, are two lines on their own.
 * - Item data is saved with the cart — session, persistent cart, login merge —
 *   so a gift survives a reload and a sign-in with nothing of ours to sync.
 * - Moving a candle to another gift (Phase 3, "Mover para Caixa N") is only
 *   giving its line another id.
 *
 * Gifts are numbered by first appearance in the cart ("Presente 1"), so the
 * numbers need no storage either; the order copies the number it had at checkout.
 *
 * What keeps a group honest once it is in the cart:
 * - A group left without candles loses its accessories (removal, a product
 *   that vanished on session load). Removing a candle otherwise changes
 *   nothing: fewer candles always fit.
 * - An accessory's quantity belongs to its gift: refused in the classic and
 *   Galaxie carts, fixed in the block cart (Store API quantity limits). Removing
 *   one on its own is allowed — every accessory is optional.
 * - A boxed candle's quantity can grow only while the box still closes.
 */
final class Groups {

	/** Cart item data key. */
	public const CART_KEY = 'galaxie_gift_group';

	public const ROLE_CANDLE = 'candle';
	public const ROLE_BOX    = 'box';
	public const ROLE_RIBBON = 'ribbon';
	public const ROLE_CARD   = 'card';

	/** Roles in the order a gift's lines are listed. */
	private const ROLES = array( self::ROLE_CANDLE, self::ROLE_BOX, self::ROLE_RIBBON, self::ROLE_CARD );

	/** Hidden order line item meta. */
	public const ITEM_GROUP   = '_galaxie_gift_group';
	public const ITEM_ROLE    = '_galaxie_gift_role';
	public const ITEM_NUMBER  = '_galaxie_gift_number';
	public const ITEM_MESSAGE = '_galaxie_gift_message';

	public static function hooks(): void {
		// After Flag::item_data (10), which leaves grouped lines to this one.
		add_filter( 'woocommerce_get_item_data', array( self::class, 'item_data' ), 11, 2 );

		add_action( 'woocommerce_cart_item_removed', array( self::class, 'after_removal' ), 20, 2 );
		// After WooCommerce dropped what can no longer be bought, and after a login merge.
		add_action( 'woocommerce_cart_loaded_from_session', array( self::class, 'tidy' ), 20, 1 );

		add_filter( 'woocommerce_update_cart_validation', array( self::class, 'validate_update' ), 20, 4 );
		add_filter( 'woocommerce_cart_item_quantity', array( self::class, 'quantity_html' ), 20, 3 );
		add_filter( 'galaxie_cart_item_quantity_locked', array( self::class, 'locked_filter' ), 10, 2 );

		add_filter( 'woocommerce_store_api_product_quantity_editable', array( self::class, 'store_editable' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_minimum', array( self::class, 'store_minimum' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( self::class, 'store_maximum' ), 10, 3 );

		// After Flag::line_item (10).
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'line_item' ), 20, 3 );
	}

	// ------------------------------------------------------------ reading

	/**
	 * The gift a cart item belongs to, or null.
	 *
	 * @param mixed $item Cart item.
	 * @return array{id:string, role:string, message:string}|null
	 */
	public static function group_of( $item ): ?array {
		$group = is_array( $item ) ? ( $item[ self::CART_KEY ] ?? null ) : null;

		if ( ! is_array( $group ) || ! is_string( $group['id'] ?? null ) || '' === $group['id'] || ! in_array( $group['role'] ?? '', self::ROLES, true ) ) {
			return null;
		}

		return array(
			'id'      => $group['id'],
			'role'    => (string) $group['role'],
			'message' => (string) ( $group['message'] ?? '' ),
		);
	}

	/** A fresh group id: short, and never one the cart already uses. */
	public static function new_id(): string {
		return strtolower( wp_generate_password( 12, false ) );
	}

	/** The cart item data that puts a line in a gift. */
	public static function data( string $id, string $role, string $message = '' ): array {
		$group = array(
			'id'   => $id,
			'role' => $role,
		);

		if ( self::ROLE_CARD === $role && '' !== $message ) {
			$group['message'] = $message;
		}

		return array( self::CART_KEY => $group );
	}

	/**
	 * Gift numbers, by first appearance.
	 *
	 * @param array $contents Cart contents.
	 * @return array<string,int> group id => 1, 2, …
	 */
	public static function numbers( array $contents ): array {
		$numbers = array();

		foreach ( $contents as $item ) {
			$group = self::group_of( $item );

			if ( $group && ! isset( $numbers[ $group['id'] ] ) ) {
				$numbers[ $group['id'] ] = count( $numbers ) + 1;
			}
		}

		return $numbers;
	}

	/**
	 * Every gift in the cart with what is in it, numbered.
	 *
	 * @param array $contents Cart contents.
	 * @return array<string, array{number:int, candles:array<string,array>, box:?array, ribbons:array<string,array>, cards:array<string,array>}>
	 *         Lines by cart key; `box` is [ key => item ] or null.
	 */
	public static function groups( array $contents ): array {
		$groups  = array();
		$numbers = self::numbers( $contents );

		foreach ( $contents as $key => $item ) {
			$group = self::group_of( $item );

			if ( ! $group ) {
				continue;
			}

			$id = $group['id'];

			if ( ! isset( $groups[ $id ] ) ) {
				$groups[ $id ] = array(
					'number'  => $numbers[ $id ],
					'candles' => array(),
					'box'     => null,
					'ribbons' => array(),
					'cards'   => array(),
				);
			}

			switch ( $group['role'] ) {
				case self::ROLE_CANDLE:
					$groups[ $id ]['candles'][ $key ] = $item;
					break;
				case self::ROLE_BOX:
					$groups[ $id ]['box'] = array( $key => $item );
					break;
				case self::ROLE_RIBBON:
					$groups[ $id ]['ribbons'][ $key ] = $item;
					break;
				case self::ROLE_CARD:
					$groups[ $id ]['cards'][ $key ] = $item;
					break;
			}
		}

		return $groups;
	}

	/**
	 * A gift's candles as packing arrays, one per unit, keyed lines left out.
	 *
	 * @param array  $lines  Candle cart items by key.
	 * @param string $except Cart key to leave out.
	 * @return array|null Null when a candle has no dimensions to pack with.
	 */
	public static function candles( array $lines, string $except = '' ): ?array {
		$attribute = (string) Module::setting( 'size_attribute' );
		$candles   = array();

		foreach ( $lines as $key => $item ) {
			if ( (string) $key === $except || ! ( $item['data'] ?? null ) instanceof \WC_Product ) {
				continue;
			}

			$candle = GiftPacking::candle_from_product( $item['data'], $attribute );

			if ( ! $candle ) {
				return null;
			}

			// One past the packing limit is enough to know a gift is over it.
			for ( $i = 0; $i < (int) $item['quantity'] && count( $candles ) <= GiftPacking::MAX_ITEMS; $i++ ) {
				$candles[] = $candle;
			}
		}

		return $candles;
	}

	/**
	 * The box of a gift as a packing array, or null for none (or one without sizes).
	 *
	 * @param array|null $box [ key => item ] from groups().
	 */
	public static function box( ?array $box ): ?array {
		$item = $box ? reset( $box ) : null;

		return is_array( $item ) && ( $item['data'] ?? null ) instanceof \WC_Product ? GiftPacking::box_from_product( $item['data'] ) : null;
	}

	/** "Vela", "Caixa", "Fita", "Cartão". */
	public static function role_label( string $role ): string {
		switch ( $role ) {
			case self::ROLE_BOX:
				return __( 'Caixa', 'galaxie-woo' );
			case self::ROLE_RIBBON:
				return __( 'Fita', 'galaxie-woo' );
			case self::ROLE_CARD:
				return __( 'Cartão', 'galaxie-woo' );
			default:
				return __( 'Vela', 'galaxie-woo' );
		}
	}

	/** "Presente 1". */
	public static function label( int $number ): string {
		/* translators: %d: gift number in the cart or order. */
		return sprintf( __( 'Presente %d', 'galaxie-woo' ), $number );
	}

	/** An accessory: its quantity is its gift's. */
	public static function is_locked( $item ): bool {
		$group = self::group_of( $item );

		return null !== $group && self::ROLE_CANDLE !== $group['role'];
	}

	// ----------------------------------------------------------- showing

	/**
	 * "Presente 1: Caixa" under each line of a gift, and the card's message —
	 * in the classic cart and, through the Store API's `item_data`, the block
	 * cart and checkout.
	 *
	 * @param mixed $data
	 * @param mixed $cart_item
	 * @return mixed
	 */
	public static function item_data( $data, $cart_item ) {
		$group = self::group_of( $cart_item );

		if ( ! is_array( $data ) || ! $group || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $data;
		}

		$number = self::numbers( WC()->cart->get_cart() )[ $group['id'] ] ?? 0;

		if ( $number ) {
			$data[] = array(
				'key'   => self::label( $number ),
				'value' => self::role_label( $group['role'] ),
			);
		}

		if ( self::ROLE_CARD === $group['role'] && '' !== $group['message'] ) {
			$data[] = array(
				'key'   => __( 'Mensagem', 'galaxie-woo' ),
				'value' => $group['message'],
			);
		}

		return $data;
	}

	/**
	 * WooCommerce's classic cart template: a linked accessory shows its number.
	 *
	 * @param mixed $html
	 * @param mixed $cart_item_key
	 * @param mixed $cart_item
	 * @return mixed
	 */
	public static function quantity_html( $html, $cart_item_key, $cart_item ) {
		if ( ! self::is_locked( $cart_item ) ) {
			return $html;
		}

		return sprintf(
			'<span class="galaxie-gift-qty-fixed">%1$d</span><input type="hidden" name="cart[%2$s][qty]" value="%1$d" />',
			(int) $cart_item['quantity'],
			esc_attr( (string) $cart_item_key )
		);
	}

	/**
	 * The Galaxie Cart widget asks the same through its own filter.
	 *
	 * @param mixed $locked
	 * @param mixed $item
	 */
	public static function locked_filter( $locked, $item ): bool {
		return (bool) $locked || self::is_locked( $item );
	}

	// ------------------------------------------------------------- rules

	/**
	 * A gift left without candles takes its accessories with it.
	 *
	 * @param mixed $cart_item_key
	 * @param mixed $cart
	 */
	public static function after_removal( $cart_item_key, $cart ): void {
		self::tidy( $cart );
	}

	/**
	 * Drops accessories whose gift has no candle left, and lists each gift's
	 * lines together — candles, box, ribbons, cards — where its first line was.
	 *
	 * Written straight to the contents rather than through remove_cart_item(),
	 * so the removal fires no hooks of its own and offers no "undo" that would
	 * bring back a ribbon with nothing to tie.
	 *
	 * @param mixed $cart
	 */
	public static function tidy( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		$contents = $cart->get_cart_contents();
		$groups   = self::groups( $contents );

		if ( ! $groups ) {
			return;
		}

		$ordered = array();
		$placed  = array();

		foreach ( $contents as $key => $item ) {
			$group = self::group_of( $item );

			if ( ! $group ) {
				$ordered[ $key ] = $item;
				continue;
			}

			$id = $group['id'];

			if ( isset( $placed[ $id ] ) ) {
				continue;
			}

			$placed[ $id ] = true;
			$gift          = $groups[ $id ];

			if ( ! $gift['candles'] ) {
				continue;
			}

			$ordered += $gift['candles'] + (array) $gift['box'] + $gift['ribbons'] + $gift['cards'];
		}

		if ( array_keys( $ordered ) !== array_keys( $contents ) ) {
			$cart->set_cart_contents( $ordered );
		}
	}

	/**
	 * The classic cart form and the Galaxie Cart's AJAX: an accessory's
	 * quantity stays its gift's, and a boxed candle grows only while it fits.
	 *
	 * Zero is let through: it is how both carts remove a line.
	 *
	 * @param mixed $passed
	 * @param mixed $cart_item_key
	 * @param mixed $values
	 * @param mixed $quantity
	 * @return mixed
	 */
	public static function validate_update( $passed, $cart_item_key, $values, $quantity ) {
		$group = self::group_of( $values );

		if ( ! $passed || ! $group || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $passed;
		}

		$quantity = (int) $quantity;
		$current  = (int) ( $values['quantity'] ?? 0 );
		$name     = ( $values['data'] ?? null ) instanceof \WC_Product ? $values['data']->get_name() : '';
		$contents = WC()->cart->get_cart();
		$number   = self::numbers( $contents )[ $group['id'] ] ?? 1;

		if ( 0 === $quantity || $quantity === $current ) {
			return $passed;
		}

		if ( self::ROLE_CANDLE !== $group['role'] ) {
			/* translators: 1: product name, 2: "Presente 1". */
			wc_add_notice( sprintf( __( 'A quantidade de %1$s acompanha o %2$s e não muda sozinha.', 'galaxie-woo' ), $name, self::label( $number ) ), 'error' );
			return false;
		}

		if ( $quantity < $current ) {
			return $passed;
		}

		$gift = self::groups( $contents )[ $group['id'] ] ?? null;
		$box  = $gift ? self::box( $gift['box'] ) : null;

		if ( ! $box ) {
			return $passed;
		}

		$others = self::candles( $gift['candles'], (string) $cart_item_key );
		$candle = ( $values['data'] ?? null ) instanceof \WC_Product ? GiftPacking::candle_from_product( $values['data'], (string) Module::setting( 'size_attribute' ) ) : null;

		if ( null === $others || null === $candle ) {
			return $passed;
		}

		// The quantity is the shopper's input: past the packing limit it cannot
		// fit, and it is never expanded.
		if ( count( $others ) + $quantity > GiftPacking::MAX_ITEMS ) {
			/* translators: 1: product name, 2: "Presente 1". */
			wc_add_notice( sprintf( __( 'Não cabe mais %1$s na caixa do %2$s.', 'galaxie-woo' ), $name, self::label( $number ) ), 'error' );
			return false;
		}

		for ( $i = 0; $i < $quantity; $i++ ) {
			$others[] = $candle;
		}

		if ( GiftPacking::fits( $box, $others, Module::packing_options() ) ) {
			return $passed;
		}

		/* translators: 1: product name, 2: "Presente 1". */
		wc_add_notice( sprintf( __( 'Não cabe mais %1$s na caixa do %2$s.', 'galaxie-woo' ), $name, self::label( $number ) ), 'error' );

		return false;
	}

	/**
	 * @param mixed $value
	 * @param mixed $product
	 * @param mixed $cart_item
	 * @return mixed
	 */
	public static function store_editable( $value, $product, $cart_item ) {
		return self::is_locked( $cart_item ) ? false : $value;
	}

	/**
	 * @param mixed $value
	 * @param mixed $product
	 * @param mixed $cart_item
	 * @return mixed
	 */
	public static function store_minimum( $value, $product, $cart_item ) {
		return self::is_locked( $cart_item ) ? (int) $cart_item['quantity'] : $value;
	}

	/**
	 * A linked accessory: exactly what it has. A boxed candle: what still fits.
	 *
	 * @param mixed $value
	 * @param mixed $product
	 * @param mixed $cart_item
	 * @return mixed
	 */
	public static function store_maximum( $value, $product, $cart_item ) {
		if ( self::is_locked( $cart_item ) ) {
			return (int) $cart_item['quantity'];
		}

		$group = self::group_of( $cart_item );

		if ( ! $group || ! function_exists( 'WC' ) || ! WC()->cart || ! $product instanceof \WC_Product ) {
			return $value;
		}

		$contents = WC()->cart->get_cart();
		$key      = (string) ( $cart_item['key'] ?? '' );
		$gift     = self::groups( $contents )[ $group['id'] ] ?? null;
		$box      = $gift ? self::box( $gift['box'] ) : null;
		$others   = $gift && '' !== $key ? self::candles( $gift['candles'], $key ) : null;
		$candle   = GiftPacking::candle_from_product( $product, (string) Module::setting( 'size_attribute' ) );

		if ( ! $box || null === $others || ! $candle ) {
			return $value;
		}

		$fit = GiftGroups::max_quantity( $box, $others, $candle, (int) $cart_item['quantity'], Module::packing_options() );

		return is_numeric( $value ) ? min( (int) $value, $fit ) : $fit;
	}

	// ------------------------------------------------------------- order

	/**
	 * The gift onto the order line: hidden id, role, number and message for the
	 * packing summary, and the same "Presente 1: Caixa" the cart showed.
	 *
	 * @param mixed $item
	 * @param mixed $cart_item_key
	 * @param mixed $values
	 */
	public static function line_item( $item, $cart_item_key, $values ): void {
		$group = self::group_of( $values );

		if ( ! $item instanceof \WC_Order_Item_Product || ! $group || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$number = self::numbers( WC()->cart->get_cart() )[ $group['id'] ] ?? 1;

		$item->add_meta_data( self::ITEM_GROUP, $group['id'], true );
		$item->add_meta_data( self::ITEM_ROLE, $group['role'], true );
		$item->add_meta_data( self::ITEM_NUMBER, $number, true );
		$item->add_meta_data( self::label( $number ), self::role_label( $group['role'] ), true );

		if ( self::ROLE_CARD === $group['role'] && '' !== $group['message'] ) {
			$item->add_meta_data( self::ITEM_MESSAGE, $group['message'], true );
			$item->add_meta_data( __( 'Mensagem', 'galaxie-woo' ), $group['message'], true );
		}
	}
}

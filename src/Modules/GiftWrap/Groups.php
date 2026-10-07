<?php
/**
 * Gifts in the cart: candles and their box, ribbons and cards as one group.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

use Galaxie\Woo\Support\GiftGroups;
use Galaxie\Woo\Support\GiftKit;
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
 * A kit (Kit flow, {@see Kit\Kits}) also carries its name in that same item
 * data, and the name is its title everywhere instead of the number.
 *
 * What keeps a group honest once it is in the cart:
 * - A group left without candles loses its accessories (removal, a product
 *   that vanished on session load). Removing a candle otherwise changes
 *   nothing: fewer candles always fit.
 * - A kit (a group with a name) stands or falls whole: removing its box, or
 *   its last candle, takes every line of it out, with one notice, and the
 *   cart's "Desfazer" brings every line back. A kit line that finds itself
 *   without a box anyway (the box sold out and WooCommerce dropped it) stops
 *   being a kit line and stays as an ordinary product.
 * - An accessory's quantity belongs to its gift, and every quantity in a kit
 *   belongs to the kit ("Editar kit" changes it): refused in the classic and
 *   Galaxie carts, fixed in the block cart (Store API quantity limits).
 *   Removing a card or ribbon on its own is allowed — they are optional.
 * - A boxed candle's quantity (outside kits) can grow only while the box
 *   still closes; a search that runs out of time does not refuse it.
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
	public const ITEM_NAME    = '_galaxie_gift_name';

	/** Set while the classic cart table or checkout review prints its lines. */
	private static bool $edit_here = false;

	/** "Editar kit" links point here, followed by the group id; kit-cart.ts answers them. */
	public const EDIT_HASH = '#galaxie-kit-edit-';

	public static function hooks(): void {
		// After Flag::item_data (10), which leaves grouped lines to this one.
		add_filter( 'woocommerce_get_item_data', array( self::class, 'item_data' ), 11, 2 );

		// "Editar kit": the classic cart and checkout templates print the name
		// through this filter; the Galaxie Cart widget has its own place for it.
		add_filter( 'woocommerce_cart_item_name', array( self::class, 'name_with_edit' ), 20, 3 );

		// Only inside the cart table and the checkout review: the mini cart wraps
		// the name in its own product link, and a link inside it breaks both.
		add_action( 'woocommerce_before_cart_contents', array( self::class, 'edit_on' ) );
		add_action( 'woocommerce_after_cart_contents', array( self::class, 'edit_off' ) );
		add_action( 'woocommerce_review_order_before_cart_contents', array( self::class, 'edit_on' ) );
		add_action( 'woocommerce_review_order_after_cart_contents', array( self::class, 'edit_off' ) );
		// The Galaxie Cart widget prints "Editar kit" in the kit's header row
		// (print_kit_head()); print_edit() stays for a widget saved without it.

		add_action( 'woocommerce_cart_item_removed', array( self::class, 'after_removal' ), 20, 2 );
		add_action( 'woocommerce_cart_item_restored', array( self::class, 'after_restore' ), 5, 2 );
		// After WooCommerce dropped what can no longer be bought, and after a login merge.
		add_action( 'woocommerce_cart_loaded_from_session', array( self::class, 'tidy' ), 20, 1 );
		// Before checkout, and on the cart page: the last word on half a kit.
		add_action( 'woocommerce_check_cart_items', array( self::class, 'check_items' ), 5 );

		// "Pedir novamente" would take a kit apart into loose products.
		add_filter( 'woocommerce_order_again_cart_item_data', array( self::class, 'order_again_data' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'refuse_order_again' ), 1, 6 );

		// The Galaxie Cart widget: a header row over each kit, its lines under it.
		add_action( 'galaxie_cart_before_line', array( self::class, 'print_kit_head' ), 10, 2 );
		add_filter( 'galaxie_cart_line_class', array( self::class, 'line_class' ), 10, 2 );
		// WooCommerce's own templates (cart table, mini cart, checkout review): a class to hang a style on.
		add_filter( 'woocommerce_cart_item_class', array( self::class, 'line_class' ), 20, 2 );
		add_filter( 'woocommerce_mini_cart_item_class', array( self::class, 'line_class' ), 20, 2 );

		// The block cart and checkout: the kit as data of its own, not markup inside `item_data`.
		add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_store_api' ) );

		// The card message is the shopper's text: printed as text in wp-admin and e-mails.
		add_filter( 'woocommerce_order_item_display_meta_value', array( self::class, 'display_meta_value' ), 20, 3 );

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
	 * @return array{id:string, role:string, message:string, name:string}|null
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
			// A kit's name (Kit flow); gifts built before kits have none.
			'name'    => is_string( $group['name'] ?? null ) ? $group['name'] : '',
		);
	}

	/** A fresh group id: short, and never one the cart already uses. */
	public static function new_id(): string {
		return strtolower( wp_generate_password( 12, false ) );
	}

	/**
	 * The cart item data that puts a line in a gift. A kit's name rides on every
	 * line of it, so the cart, the order and the e-mails all title it the same.
	 */
	public static function data( string $id, string $role, string $message = '', string $name = '' ): array {
		$group = array(
			'id'   => $id,
			'role' => $role,
		);

		if ( self::ROLE_CARD === $role && '' !== $message ) {
			$group['message'] = $message;
		}

		if ( '' !== $name ) {
			$group['name'] = $name;
		}

		return array( self::CART_KEY => $group );
	}

	/**
	 * Gift numbers, by first appearance among the gifts that have a box.
	 *
	 * A gift without a box is candles that go out in the standard packaging
	 * ("Fora das caixas"): it gets 0, and its lines are labelled that way.
	 *
	 * @param array $contents Cart contents.
	 * @return array<string,int> group id => 1, 2, … (0 for no box)
	 */
	public static function numbers( array $contents ): array {
		$boxed = array();

		foreach ( $contents as $item ) {
			$group = self::group_of( $item );

			if ( $group && self::ROLE_BOX === $group['role'] ) {
				$boxed[ $group['id'] ] = true;
			}
		}

		$numbers = array();
		$next    = 0;

		foreach ( $contents as $item ) {
			$group = self::group_of( $item );

			if ( $group && ! isset( $numbers[ $group['id'] ] ) ) {
				$numbers[ $group['id'] ] = isset( $boxed[ $group['id'] ] ) ? ++$next : 0;
			}
		}

		return $numbers;
	}

	/**
	 * Every gift in the cart with what is in it, numbered.
	 *
	 * @param array $contents Cart contents.
	 * @return array<string, array{number:int, name:string, candles:array<string,array>, box:?array, ribbons:array<string,array>, cards:array<string,array>}>
	 *         Lines by cart key; `box` is [ key => item ] or null; `name` the kit's, or ''.
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
					'name'    => '',
					'candles' => array(),
					'box'     => null,
					'ribbons' => array(),
					'cards'   => array(),
				);
			}

			if ( '' === $groups[ $id ]['name'] ) {
				$groups[ $id ]['name'] = $group['name'];
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
		$attribute = Module::size_attribute();
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
				// Written into the order item's meta: the word the store used then.
				return Module::noun( false, true );
		}
	}

	/**
	 * A gift's title: the kit's name when it has one, else "Presente 1", or for
	 * 0 the candles outside any box.
	 */
	public static function label( int $number, string $name = '' ): string {
		if ( '' !== $name ) {
			return $name;
		}

		if ( $number < 1 ) {
			return __( 'Fora das caixas (embalagem padrão)', 'galaxie-woo' );
		}

		/* translators: %d: gift number in the cart or order. */
		return sprintf( __( 'Presente %d', 'galaxie-woo' ), $number );
	}

	/** The title of the gift a cart line is in, from the cart it is in. */
	private static function title_of( array $group, array $contents ): string {
		return self::label( self::numbers( $contents )[ $group['id'] ] ?? 0, $group['name'] );
	}

	/**
	 * Whether a gift in the cart can go back to the kit popup ("Editar kit"): a
	 * kit's shape — a box, at least one candle and no more than a box takes, at
	 * most one card of one, no ribbons. Gifts built before kits may not be.
	 *
	 * @param array $gift One entry of groups().
	 */
	public static function editable( array $gift ): bool {
		if ( ! $gift['box'] || ! $gift['candles'] || $gift['ribbons'] || count( $gift['cards'] ) > 1 ) {
			return false;
		}

		$box = reset( $gift['box'] );

		if ( (int) ( $box['quantity'] ?? 0 ) !== 1 ) {
			return false;
		}

		foreach ( $gift['cards'] as $card ) {
			if ( (int) ( $card['quantity'] ?? 0 ) !== 1 ) {
				return false;
			}
		}

		$count = array_sum( array_map( static fn( array $item ): int => (int) ( $item['quantity'] ?? 0 ), $gift['candles'] ) );

		return $count <= GiftPacking::MAX_ITEMS;
	}

	/** `<a href="#galaxie-kit-edit-{id}">Editar kit</a>`. */
	public static function edit_link( string $id ): string {
		return sprintf(
			'<a href="%1$s" class="galaxie-kit-edit">%2$s</a>',
			esc_attr( self::EDIT_HASH . $id ),
			esc_html__( 'Editar kit', 'galaxie-woo' )
		);
	}

	/**
	 * The group id when this cart line is the one of its kit that carries
	 * "Editar kit" (the box), and the kit can be edited; '' otherwise.
	 *
	 * @param mixed $item Cart item.
	 */
	private static function edit_target( $item ): string {
		$group = self::group_of( $item );

		// The link opens the kit popup: none set, no link (the kit script is
		// only on the page when there is one, see Kit\Launcher).
		if ( ! $group || self::ROLE_BOX !== $group['role'] || ! function_exists( 'WC' ) || ! WC()->cart || ! Module::kit_popup_id() ) {
			return '';
		}

		$gift = self::groups( WC()->cart->get_cart() )[ $group['id'] ] ?? null;

		return $gift && self::editable( $gift ) ? $group['id'] : '';
	}

	/**
	 * An accessory, or any line of a kit: its quantity is its gift's. A kit's
	 * quantities change through "Editar kit", where the box is checked again;
	 * a stepper on the cart line looked live and did nothing useful.
	 */
	public static function is_locked( $item ): bool {
		$group = self::group_of( $item );

		return null !== $group && ( self::ROLE_CANDLE !== $group['role'] || self::is_kit( $group ) );
	}

	/** A kit (Kit flow) rather than a gift built before kits: it has a name. */
	public static function is_kit( array $group ): bool {
		return '' !== ( $group['name'] ?? '' );
	}

	/**
	 * "Kit 1" as a sentence says it: a name that already starts with the word
	 * stands alone, any other gets it in front ("kit Presente da Ana").
	 */
	public static function kit_phrase( string $name ): string {
		/* translators: %s: the kit's name as the shopper typed it. */
		return preg_match( '/^kit\b/iu', $name ) ? $name : sprintf( __( 'kit %s', 'galaxie-woo' ), $name );
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

		$contents = WC()->cart->get_cart();

		if ( isset( self::numbers( $contents )[ $group['id'] ] ) ) {
			// Plain text only, `display` included: the Store API hands it to every
			// cart that reads it (the block cart, the pixfort mini cart), and some
			// print it as text — a link inside showed up as raw markup. "Editar
			// kit" lives in the classic templates (name_with_edit()), the Galaxie
			// Cart widget (print_edit(), print_kit_head()) and, for the block
			// cart, the `galaxie-kit` extension data (register_store_api()).
			// The name is escaped: it is the shopper's text, and WooCommerce
			// prints item data keys through wp_kses_post().
			$data[] = array(
				'key'   => esc_html( self::title_of( $group, $contents ) ),
				'value' => self::role_label( $group['role'] ),
			);
		}

		if ( self::ROLE_CARD === $group['role'] && '' !== $group['message'] ) {
			$data[] = array(
				'key'   => __( 'Mensagem', 'galaxie-woo' ),
				// Plain text (tags are stripped when it is typed, GiftGroups::clean_message());
				// WooCommerce and the Store API print it through wp_kses_post().
				'value' => $group['message'],
			);
		}

		return $data;
	}

	/**
	 * "Editar kit" after the box's name in the classic cart table and checkout
	 * review, which print the name as markup (the cart table after its own
	 * product link). Only while those print their lines: the same filter feeds
	 * the mini cart (which wraps the name in a link) and places that want text.
	 *
	 * @param mixed $name
	 * @param mixed $cart_item
	 * @param mixed $cart_item_key
	 * @return mixed
	 */
	public static function name_with_edit( $name, $cart_item = array(), $cart_item_key = '' ) {
		// Only between the cart table's (or checkout review's) own actions: not the
		// mini cart, which nests the name in a link, nor any other caller.
		if ( ! self::$edit_here || ! is_string( $name ) || self::is_store_api() || did_action( 'woocommerce_checkout_process' ) ) {
			return $name;
		}

		$edit = self::edit_target( $cart_item );

		return '' !== $edit ? $name . ' <span class="galaxie-kit-edit-wrap">' . self::edit_link( $edit ) . '</span>' : $name;
	}

	/** Starts the cart table or checkout review (see hooks()). */
	public static function edit_on(): void {
		self::$edit_here = true;
	}

	/** Ends them. */
	public static function edit_off(): void {
		self::$edit_here = false;
	}

	/**
	 * The Galaxie Cart widget's line: "Editar kit" under the item data.
	 *
	 * @param mixed $cart_item
	 * @param mixed $cart_item_key
	 */
	public static function print_edit( $cart_item, $cart_item_key = '' ): void {
		$edit = self::edit_target( $cart_item );

		if ( '' !== $edit ) {
			echo '<span class="galaxie-cart-meta galaxie-kit-edit-wrap">' . self::edit_link( $edit ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in edit_link().
		}
	}

	/**
	 * The Galaxie Cart widget: a header row before the first line of each kit —
	 * its name, what the kit costs, the start of the card's message and "Editar
	 * kit" — so the kit reads as one thing and its lines sit under it.
	 *
	 * @param mixed $cart_item
	 * @param mixed $cart_item_key
	 */
	public static function print_kit_head( $cart_item, $cart_item_key = '' ): void {
		static $printed = array();

		$group = self::group_of( $cart_item );

		if ( ! $group || ! self::is_kit( $group ) || isset( $printed[ $group['id'] ] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$contents = WC()->cart->get_cart();
		$gift     = self::groups( $contents )[ $group['id'] ] ?? null;

		if ( ! $gift ) {
			return;
		}

		$printed[ $group['id'] ] = true;

		$lines   = $gift['candles'] + (array) $gift['box'] + $gift['ribbons'] + $gift['cards'];
		$total   = 0.0;
		$tax     = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() && WC()->cart->display_prices_including_tax();
		$message = '';

		foreach ( $lines as $line ) {
			$total += (float) ( $line['line_subtotal'] ?? 0 ) + ( $tax ? (float) ( $line['line_subtotal_tax'] ?? 0 ) : 0.0 );
		}

		foreach ( $gift['cards'] as $card ) {
			$message = (string) ( self::group_of( $card )['message'] ?? '' );
		}

		if ( '' !== $message && function_exists( 'mb_strlen' ) && mb_strlen( $message ) > 80 ) {
			$message = rtrim( mb_substr( $message, 0, 79 ) ) . '…';
		}

		$edit = self::editable( $gift ) && Module::kit_popup_id() ? self::edit_link( $group['id'] ) : '';

		printf(
			'<div class="galaxie-cart-kit-head" data-galaxie-kit="%1$s"><span class="galaxie-cart-kit-title">%2$s</span><span class="galaxie-cart-kit-total">%3$s</span>%4$s%5$s</div>',
			esc_attr( $group['id'] ),
			esc_html( $group['name'] ),
			wp_kses_post( function_exists( 'wc_price' ) ? wc_price( $total ) : (string) $total ),
			'' !== $message ? '<span class="galaxie-cart-kit-message">' . esc_html( sprintf( /* translators: %s: the start of the card's message. */ __( 'Mensagem: “%s”', 'galaxie-woo' ), $message ) ) . '</span>' : '',
			'' !== $edit ? '<span class="galaxie-cart-kit-edit galaxie-kit-edit-wrap">' . $edit . '</span>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in edit_link().
		);
	}

	/**
	 * `galaxie-kit-line` (and the kit's id) on the lines of a kit, wherever a
	 * cart prints a class for its lines.
	 *
	 * @param mixed $class
	 * @param mixed $cart_item
	 * @return mixed
	 */
	public static function line_class( $class, $cart_item = array() ) {
		$group = self::group_of( $cart_item );

		if ( ! is_string( $class ) || ! $group || ! self::is_kit( $group ) ) {
			return $class;
		}

		return trim( $class . ' galaxie-kit-line galaxie-kit-line--' . sanitize_html_class( $group['role'] ) );
	}

	/**
	 * The block cart and checkout: `extensions.galaxie-kit` on each cart item —
	 * the kit's id, name, role and whether "Editar kit" applies — instead of a
	 * link inside the item data's text.
	 */
	public static function register_store_api(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
				'namespace'       => 'galaxie-kit',
				'data_callback'   => array( self::class, 'store_api_data' ),
				'schema_callback' => array( self::class, 'store_api_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * @param mixed $cart_item
	 * @return array<string,mixed>
	 */
	public static function store_api_data( $cart_item ): array {
		$group = self::group_of( $cart_item );

		if ( ! $group || ! self::is_kit( $group ) ) {
			return array();
		}

		return array(
			'group'    => $group['id'],
			'name'     => $group['name'],
			'role'     => $group['role'],
			'editable' => '' !== self::edit_target( $cart_item ),
		);
	}

	/** @return array<string,mixed> */
	public static function store_api_schema(): array {
		return array(
			'group'    => array(
				'description' => 'Kit id in the cart.',
				'type'        => 'string',
				'readonly'    => true,
			),
			'name'     => array(
				'description' => 'Kit name.',
				'type'        => 'string',
				'readonly'    => true,
			),
			'role'     => array(
				'description' => 'candle, box, ribbon or card.',
				'type'        => 'string',
				'readonly'    => true,
			),
			'editable' => array(
				'description' => 'Whether "Editar kit" applies (on the box line).',
				'type'        => 'boolean',
				'readonly'    => true,
			),
		);
	}

	/** Whether this request is the Store API's (the block cart and checkout). */
	private static function is_store_api(): bool {
		if ( function_exists( 'WC' ) && is_object( WC() ) && method_exists( WC(), 'is_store_api_request' ) ) {
			return (bool) WC()->is_store_api_request();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

		return false !== strpos( $uri, '/wc/store/' ) || false !== strpos( $uri, 'rest_route=%2Fwc%2Fstore' ) || false !== strpos( $uri, 'rest_route=/wc/store' );
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

	/** Set while this class restores a kit's lines itself (see after_restore()). */
	private static bool $restoring = false;

	/**
	 * A gift left without candles takes its accessories with it, and a kit
	 * whose box or last candle was removed goes whole: its other lines leave
	 * the cart the way the removed one did — into WooCommerce's removed items,
	 * so the cart's "Desfazer" (after_restore()) brings the kit back entire.
	 *
	 * A card on its own may go: a kit without a card is still a kit.
	 *
	 * @param mixed $cart_item_key
	 * @param mixed $cart
	 */
	public static function after_removal( $cart_item_key, $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		$removed = $cart->get_removed_cart_contents()[ (string) $cart_item_key ] ?? null;
		$group   = self::group_of( $removed );

		if ( $group && self::is_kit( $group ) && ! self::$restoring ) {
			$gift = self::groups( $cart->get_cart_contents() )[ $group['id'] ] ?? null;

			if ( $gift && ( self::ROLE_BOX === $group['role'] || ! $gift['candles'] ) ) {
				self::take_out( $cart, $group['id'] );
				self::say_removed( $group['name'] );
			}
		}

		self::tidy( $cart );
	}

	/**
	 * "Desfazer" on any line of a kit taken out whole brings back every line
	 * of it that is still among the removed items. What could not come back
	 * (the box, after the removed list was cleaned) is settled by tidy().
	 *
	 * @param mixed $cart_item_key
	 * @param mixed $cart
	 */
	public static function after_restore( $cart_item_key, $cart ): void {
		if ( self::$restoring || ! $cart instanceof \WC_Cart ) {
			return;
		}

		$group = self::group_of( $cart->get_cart_contents()[ (string) $cart_item_key ] ?? null );

		if ( ! $group || ! self::is_kit( $group ) ) {
			return;
		}

		self::$restoring = true;

		try {
			foreach ( $cart->get_removed_cart_contents() as $key => $item ) {
				$other = self::group_of( $item );

				if ( $other && $other['id'] === $group['id'] ) {
					$cart->restore_cart_item( (string) $key );
				}
			}
		} finally {
			self::$restoring = false;
		}

		self::tidy( $cart );
	}

	/**
	 * Every line of one gift, out of the cart and into its removed items (as
	 * WC_Cart::remove_cart_item() keeps them: without the product object),
	 * firing no removal hooks of its own.
	 */
	private static function take_out( \WC_Cart $cart, string $id ): void {
		$contents = $cart->get_cart_contents();
		$removed  = $cart->get_removed_cart_contents();

		foreach ( $contents as $key => $item ) {
			$group = self::group_of( $item );

			if ( $group && $group['id'] === $id ) {
				unset( $item['data'] );
				$removed[ $key ] = $item;
				unset( $contents[ $key ] );
			}
		}

		$cart->set_removed_cart_contents( $removed );
		$cart->set_cart_contents( $contents );
		self::persist( $cart );
	}

	/** "O Kit 1 foi removido do carrinho.", once per kit and request. */
	private static function say_removed( string $name ): void {
		static $said = array();

		if ( isset( $said[ $name ] ) || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		$said[ $name ] = true;

		/* translators: %s: "Kit 1", or "kit Presente da Ana". */
		wc_add_notice( esc_html( sprintf( __( 'O %s foi removido do carrinho.', 'galaxie-woo' ), self::kit_phrase( $name ) ) ), 'notice' );
	}

	/**
	 * The persistent cart (user meta) follows a change written straight to the
	 * contents: WooCommerce only saves it on its own add, remove, restore and
	 * quantity hooks, so a kit taken out here — or put back as a draft by
	 * "Editar kit" — would come back on the shopper's next device.
	 *
	 * @param mixed $cart
	 */
	public static function persist( $cart ): void {
		if ( ! is_object( $cart ) ) {
			return;
		}

		$session = $cart->session ?? null;

		if ( is_object( $session ) && method_exists( $session, 'persistent_cart_update' ) ) {
			$session->persistent_cart_update();
		} elseif ( method_exists( $cart, 'persistent_cart_update' ) ) {
			$cart->persistent_cart_update();
		}
	}

	/**
	 * `woocommerce_check_cart_items` (the cart page and checkout): tidy() once
	 * more, so no half kit reaches an order whatever path left it.
	 */
	public static function check_items(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			self::tidy( WC()->cart );
		}
	}

	/**
	 * Drops accessories whose gift has no candle left, turns the lines of a kit
	 * left without its box into ordinary products (a kit is a box; candles
	 * tagged "Kit 1: Vela" with no box to go in must not reach an order as a
	 * kit), and lists each gift's lines together — candles, box, ribbons,
	 * cards — where its first line was.
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
		$changed = false;

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

			$lines = $gift['candles'] + (array) $gift['box'] + $gift['ribbons'] + $gift['cards'];

			if ( ! $gift['box'] && self::is_kit( $group ) ) {
				foreach ( $lines as $line_key => $line ) {
					unset( $lines[ $line_key ][ self::CART_KEY ], $lines[ $line_key ][ Flag::CART_KEY ] );
				}

				$changed = true;

				/* translators: %s: "Kit 1", or "kit Presente da Ana". */
				$text = sprintf( __( 'O %s ficou sem a caixa e foi desfeito: os produtos continuam no carrinho, sem embalagem de presente.', 'galaxie-woo' ), self::kit_phrase( $gift['name'] ) );

				if ( function_exists( 'wc_add_notice' ) && ( ! function_exists( 'wc_has_notice' ) || ! wc_has_notice( esc_html( $text ), 'notice' ) ) ) {
					wc_add_notice( esc_html( $text ), 'notice' );
				}
			}

			$ordered += $lines;
		}

		if ( $changed || array_keys( $ordered ) !== array_keys( $contents ) ) {
			$cart->set_cart_contents( $ordered );
			self::persist( $cart );
		}
	}

	/**
	 * "Pedir novamente": a kit's lines are marked on the way in, so the
	 * add-to-cart check below can turn them away — repeated line by line, a
	 * kit came back as loose products with no box around them.
	 *
	 * @param mixed $data
	 * @param mixed $item
	 * @param mixed $order
	 * @return mixed
	 */
	public static function order_again_data( $data, $item = null, $order = null ) {
		if ( is_array( $data ) && $item instanceof \WC_Order_Item_Product && '' !== (string) $item->get_meta( self::ITEM_GROUP ) && '' !== (string) $item->get_meta( self::ITEM_NAME ) ) {
			$data['galaxie_kit_again'] = 1;
		}

		return $data;
	}

	/**
	 * Refuses the lines order_again_data() marked, with one notice.
	 *
	 * @param mixed $passed
	 * @param mixed $product_id
	 * @param mixed $quantity
	 * @param mixed $variation_id
	 * @param mixed $variations
	 * @param mixed $data
	 * @return mixed
	 */
	public static function refuse_order_again( $passed, $product_id = 0, $quantity = 0, $variation_id = 0, $variations = array(), $data = array() ) {
		if ( ! is_array( $data ) || empty( $data['galaxie_kit_again'] ) ) {
			return $passed;
		}

		static $said = false;

		if ( ! $said && function_exists( 'wc_add_notice' ) ) {
			$said = true;
			wc_add_notice( esc_html__( 'Kits precisam ser montados novamente: os produtos dos kits deste pedido não foram adicionados ao carrinho.', 'galaxie-woo' ), 'notice' );
		}

		return false;
	}

	/**
	 * The card message in wp-admin and the e-mails: text, escaped here, since
	 * WooCommerce prints order item meta values as markup.
	 *
	 * @param mixed $display
	 * @param mixed $meta
	 * @param mixed $item
	 * @return mixed
	 */
	public static function display_meta_value( $display, $meta = null, $item = null ) {
		if ( ! is_object( $meta ) || ! $item instanceof \WC_Order_Item_Product || '' === (string) $item->get_meta( self::ITEM_MESSAGE ) ) {
			return $display;
		}

		if ( ( $meta->key ?? '' ) !== __( 'Mensagem', 'galaxie-woo' ) || ! is_string( $meta->value ?? null ) ) {
			return $display;
		}

		// Escaped, not stripped: "<3" is text, and a tag typed before tags were
		// stripped on the way in prints as the characters it is.
		return nl2br( esc_html( $meta->value ) );
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

		if ( self::is_kit( $group ) ) {
			/* translators: %s: "Kit 1", or "kit Presente da Ana". */
			wc_add_notice( esc_html( sprintf( __( 'As quantidades do %s mudam em “Editar kit”.', 'galaxie-woo' ), self::kit_phrase( $group['name'] ) ) ), 'error' );
			return false;
		}

		if ( self::ROLE_CANDLE !== $group['role'] ) {
			/* translators: 1: product name, 2: "Presente 1". */
			wc_add_notice( sprintf( __( 'A quantidade de %1$s acompanha o %2$s e não muda sozinha.', 'galaxie-woo' ), $name, esc_html( self::label( $number, $group['name'] ) ) ), 'error' );
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

		// The classic "Update cart" form validates one line at a time against the
		// cart as it was: the gift's other candles are taken at the quantities
		// posted beside this one, so lowering one while raising another passes.
		$lines = $gift['candles'];

		foreach ( $lines as $line_key => $line ) {
			$lines[ $line_key ]['quantity'] = self::proposed( (string) $line_key, (int) $line['quantity'] );
		}

		$others = self::candles( $lines, (string) $cart_item_key );
		$candle = ( $values['data'] ?? null ) instanceof \WC_Product ? GiftPacking::candle_from_product( $values['data'], Module::size_attribute() ) : null;

		if ( null === $others || null === $candle ) {
			return $passed;
		}

		// The quantity is the shopper's input: past the packing limit it cannot
		// fit, and it is never expanded.
		if ( count( $others ) + $quantity > GiftPacking::MAX_ITEMS ) {
			/* translators: 1: product name, 2: "Presente 1". */
			wc_add_notice( sprintf( __( 'Não cabe mais %1$s na caixa do %2$s.', 'galaxie-woo' ), $name, esc_html( self::label( $number, $group['name'] ) ) ), 'error' );
			return false;
		}

		for ( $i = 0; $i < $quantity; $i++ ) {
			$others[] = $candle;
		}

		// Only a proved "no" refuses, within the kit's budget: a search that ran
		// out of work or of clock has not shown the candle will not go in.
		$options        = Module::packing_options();
		list( $answer ) = GiftPacking::with_deadline(
			(float) GiftKit::BUDGET_MS,
			static fn(): ?bool => GiftPacking::fits_known( $box, $others, $options )
		);

		if ( false !== $answer ) {
			return $passed;
		}

		/* translators: 1: product name, 2: "Presente 1". */
		wc_add_notice( sprintf( __( 'Não cabe mais %1$s na caixa do %2$s.', 'galaxie-woo' ), $name, esc_html( self::label( $number, $group['name'] ) ) ), 'error' );

		return false;
	}

	/**
	 * The quantity WooCommerce's cart form is saving for a line, or the one it
	 * has when the request is not that form (the Galaxie Cart sends one line).
	 *
	 * @param string $key     Cart item key.
	 * @param int    $current The line's quantity now.
	 */
	private static function proposed( string $key, int $current ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- WooCommerce verified the cart form before validating it; cast below.
		$raw = isset( $_POST['cart'][ $key ]['qty'] ) ? $_POST['cart'][ $key ]['qty'] : null;

		return is_scalar( $raw ) ? max( 0, (int) wc_stock_amount( wp_unslash( (string) $raw ) ) ) : $current;
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

		static $cache = array();

		$contents = WC()->cart->get_cart();
		$key      = (string) ( $cart_item['key'] ?? '' );

		// The Store API asks this for every line of every cart response; the fit
		// search behind it runs once per line and cart state in a request.
		$state = $key . '|' . md5(
			(string) wp_json_encode(
				array_map(
					static fn( $item ): array => array( (int) ( $item['quantity'] ?? 0 ), ( $item['data'] ?? null ) instanceof \WC_Product ? $item['data']->get_id() : 0 ),
					$contents
				)
			)
		);

		if ( isset( $cache[ $state ] ) ) {
			return null === $cache[ $state ] ? $value : ( is_numeric( $value ) ? min( (int) $value, $cache[ $state ] ) : $cache[ $state ] );
		}

		$gift     = self::groups( $contents )[ $group['id'] ] ?? null;
		$box      = $gift ? self::box( $gift['box'] ) : null;
		$others   = $gift && '' !== $key ? self::candles( $gift['candles'], $key ) : null;
		$candle   = GiftPacking::candle_from_product( $product, Module::size_attribute() );

		if ( ! $box || null === $others || ! $candle ) {
			$cache[ $state ] = null;
			return $value;
		}

		$options      = Module::packing_options();
		$current      = (int) $cart_item['quantity'];
		list( $fit )  = GiftPacking::with_deadline(
			(float) GiftKit::BUDGET_MS,
			static fn(): int => GiftGroups::max_quantity( $box, $others, $candle, $current, $options )
		);
		$fit          = $cache[ $state ] = (int) $fit;

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

		$number = self::numbers( WC()->cart->get_cart() )[ $group['id'] ] ?? 0;

		$item->add_meta_data( self::ITEM_GROUP, $group['id'], true );
		$item->add_meta_data( self::ITEM_ROLE, $group['role'], true );
		$item->add_meta_data( self::ITEM_NUMBER, $number, true );

		if ( '' !== $group['name'] ) {
			$item->add_meta_data( self::ITEM_NAME, $group['name'], true );
		}

		// The visible key is escaped: wp-admin and the e-mails print meta keys as
		// markup (wp_kses_post()), and a kit's name is the shopper's text. The
		// name itself is kept as typed in ITEM_NAME.
		$item->add_meta_data( esc_html( self::label( $number, $group['name'] ) ), self::role_label( $group['role'] ), true );

		if ( self::ROLE_CARD === $group['role'] && '' !== $group['message'] ) {
			$item->add_meta_data( self::ITEM_MESSAGE, $group['message'], true );
			$item->add_meta_data( __( 'Mensagem', 'galaxie-woo' ), $group['message'], true );
		}
	}
}

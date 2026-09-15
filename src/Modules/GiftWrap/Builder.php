<?php
/**
 * The Gift Builder's two requests: what can go in a gift, and putting it in the cart.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

use Galaxie\Woo\Support\CartParts;
use Galaxie\Woo\Support\GiftGroups;
use Galaxie\Woo\Support\GiftPacking;

defined( 'ABSPATH' ) || exit;

/**
 * Both are public — a guest can build a gift — and both only ever read or
 * write the current session's cart, behind a nonce.
 *
 * DATA (`galaxie_gift_builder_data`): the candle the Buy Box is about to add,
 * the gifts already in the cart, gift candles in the cart that are not in a
 * gift yet, and the boxes, ribbons and cards on offer with price, stock and
 * size. Which categories hold those comes from the widget that asked — read
 * from its saved Elementor settings by post and element id, never from the
 * request — or from the module settings.
 *
 * ADD (`galaxie_gift_builder_add`): the plan the popup built. Nothing in it is
 * taken on trust: every product is checked against that same offer, every box
 * against the candles through GiftPacking, stock and message length through
 * GiftGroups, and WooCommerce's add-to-cart validation runs for each product.
 * Then the cart is written, and put back as it was if any add fails, so a gift
 * never lands half-built.
 *
 * "Seguir sem incrementar o presente" does not come here: it is the Buy Box's
 * own add, carrying only the gift flag.
 */
final class Builder {

	public const DATA_ACTION = 'galaxie_gift_builder_data';
	public const ADD_ACTION  = 'galaxie_gift_builder_add';
	public const NONCE       = 'galaxie_gift_builder';

	/** Accessory products read per kind. A gift builder, not a catalogue. */
	private const CATALOGUE_LIMIT = 50;

	/** What a shopper is told when a candle has no dimensions to pack with. */
	private static function no_dimensions(): string {
		return __( 'Não foi possível calcular a embalagem deste produto. Fale com a loja.', 'galaxie-woo' );
	}

	/** Cart item keys that are WooCommerce's own, not item data. */
	private const CART_FIELDS = array( 'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' );

	public static function hooks(): void {
		foreach ( array( self::DATA_ACTION => 'ajax_data', self::ADD_ACTION => 'ajax_add' ) as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( self::class, $method ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( self::class, $method ) );
		}
	}

	// ------------------------------------------------------------------ data

	public static function ajax_data(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		self::require_cart();

		$settings  = self::widget_settings();
		$pending   = self::pending();
		$attribute = (string) Module::setting( 'size_attribute' );
		$contents  = WC()->cart->get_cart();

		wp_send_json_success(
			array(
				'pending'    => self::pending_json( $pending, $attribute ),
				'gifts'      => self::gifts_json( $contents ),
				'loose'      => self::loose_json( $contents, $attribute ),
				'boxes'      => array_map( array( self::class, 'option_json' ), self::catalogue( 'box', $settings ) ),
				'ribbons'    => array_map( array( self::class, 'option_json' ), self::catalogue( 'ribbon', $settings ) ),
				'cards'      => array_map( array( self::class, 'option_json' ), self::catalogue( 'card', $settings ) ),
				'sizes'      => array_values( GiftPacking::store_sizes( $attribute ) ),
				'inCart'     => self::in_cart( $contents ),
				'options'    => Module::packing_options(),
				'messageMax' => (int) Module::setting( 'card_message_max' ),
				'currency'   => array(
					'symbol'      => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, get_bloginfo( 'charset' ) ),
					'format'      => get_woocommerce_price_format(),
					'decimals'    => wc_get_price_decimals(),
					'decimalSep'  => wc_get_price_decimal_separator(),
					'thousandSep' => wc_get_price_thousand_separator(),
				),
			)
		);
	}

	// ------------------------------------------------------------------- add

	public static function ajax_add(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		self::require_cart();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, decoded and every field validated below.
		$raw = isset( $_POST['plan'] ) ? json_decode( wp_unslash( (string) $_POST['plan'] ), true ) : null;

		if ( ! is_array( $raw ) || ! is_array( $raw['groups'] ?? null ) || ! $raw['groups'] ) {
			self::fail( __( 'Não foi possível montar o presente. Tente de novo.', 'galaxie-woo' ) );
		}

		$settings  = self::widget_settings();
		$pending   = self::pending();
		$attribute = (string) Module::setting( 'size_attribute' );
		$options   = Module::packing_options();
		$contents  = WC()->cart->get_cart();
		$gifts     = Groups::groups( $contents );
		$extend    = 'extend' === ( $raw['mode'] ?? '' );
		$target    = $extend ? (string) ( $raw['target'] ?? '' ) : '';

		if ( $extend && ( ! isset( $gifts[ $target ] ) || ! $gifts[ $target ]['candles'] || 1 !== count( $raw['groups'] ) ) ) {
			self::fail( __( 'Esse presente não está mais no carrinho. Abra o presente de novo.', 'galaxie-woo' ) );
		}

		// Bounds first, before any count in the plan is expanded or any card is
		// looked up (GiftGroups::check_request()).
		$loose_units = $extend ? array() : array_map( static fn( array $item ): int => (int) $item['quantity'], self::loose( $contents ) );
		$existing    = $extend ? array_sum( array_map( static fn( array $item ): int => (int) $item['quantity'], $gifts[ $target ]['candles'] ) ) : 0;
		$bounds      = GiftGroups::check_request( $raw, $pending['quantity'], $loose_units, $existing );

		if ( '' !== $bounds ) {
			self::fail( self::request_error( $bounds ) );
		}

		$offer = array(
			'box'    => self::catalogue( 'box', $settings ),
			'ribbon' => self::catalogue( 'ribbon', $settings ),
			'card'   => self::catalogue( 'card', $settings ),
		);

		$pending_candle = GiftPacking::candle_from_product( $pending['product'], $attribute );
		$loose          = self::loose( $contents );
		$max_message    = (int) Module::setting( 'card_message_max' );

		// Only gifts with a box are numbered; the rest is "Fora das caixas" (Groups::numbers()).
		$boxed        = count( array_filter( $gifts, static fn( array $gift ): bool => null !== $gift['box'] ) );
		$planned      = array();
		$plan_groups  = array();
		$pending_used = 0;
		$loose_used   = array();
		$stock        = array( (string) $pending['product']->get_id() => self::stock( $pending['product'] ) );

		foreach ( array_values( $raw['groups'] ) as $g => $group ) {
			if ( ! is_array( $group ) ) {
				self::fail( __( 'Não foi possível montar o presente. Tente de novo.', 'galaxie-woo' ) );
			}

			$box_id  = absint( $group['box'] ?? 0 );
			$number  = 0;

			if ( $box_id ) {
				$number = $extend && $gifts[ $target ]['number'] ? $gifts[ $target ]['number'] : ++$boxed;
			}

			$candles = array();
			$moves   = array();
			$count   = 0;

			// A gift being added to keeps what it has: checked for fit, no new stock.
			if ( $extend ) {
				foreach ( $gifts[ $target ]['candles'] as $item ) {
					$candle = GiftPacking::candle_from_product( $item['data'], $attribute );

					if ( ! $candle ) {
						self::fail( self::no_dimensions() );
					}

					for ( $i = 0; $i < (int) $item['quantity']; $i++ ) {
						$candles[] = $candle + array( 'id' => $item['data']->get_id(), 'added' => false );
					}
				}
			}

			foreach ( (array) ( $group['candles'] ?? array() ) as $source ) {
				$from = is_array( $source ) ? (string) ( $source['source'] ?? '' ) : '';
				$n    = is_array( $source ) ? absint( $source['count'] ?? 0 ) : 0;

				if ( $n < 1 ) {
					continue;
				}

				// Counts are checked against what is left before they are expanded.
				if ( 'pending' === $from ) {
					if ( $n > $pending['quantity'] - $pending_used ) {
						self::fail( __( 'A quantidade de velas mudou. Abra o presente de novo.', 'galaxie-woo' ) );
					}

					$pending_used += $n;
					$count        += $n;

					for ( $i = 0; $i < $n; $i++ ) {
						$candles[] = $pending_candle + array( 'id' => $pending['product']->get_id() );
					}
				} elseif ( ! $extend && isset( $loose[ $from ] ) ) {
					if ( $n > (int) $loose[ $from ]['quantity'] - ( $loose_used[ $from ] ?? 0 ) ) {
						self::fail( __( 'As velas no carrinho mudaram. Abra o presente de novo.', 'galaxie-woo' ) );
					}

					$loose_used[ $from ] = ( $loose_used[ $from ] ?? 0 ) + $n;
					$moves[ $from ]      = ( $moves[ $from ] ?? 0 ) + $n;
					$item                = $loose[ $from ];
					$candle              = GiftPacking::candle_from_product( $item['data'], $attribute );

					for ( $i = 0; $i < $n; $i++ ) {
						$candles[] = $candle + array( 'id' => $item['data']->get_id(), 'added' => false );
					}
				} else {
					self::fail( __( 'Uma das velas não está mais disponível para este presente. Abra o presente de novo.', 'galaxie-woo' ) );
				}
			}

			$box = null;

			if ( $box_id ) {
				if ( ! isset( $offer['box'][ $box_id ] ) ) {
					self::fail( __( 'Essa caixa não está mais disponível.', 'galaxie-woo' ) );
				}

				// The packing search is for a gift's dozen; the popup offers no box past it.
				if ( count( $candles ) > GiftPacking::MAX_ITEMS ) {
					/* translators: %s: "Presente 1". */
					self::fail( sprintf( __( 'O %s tem velas demais para uma caixa.', 'galaxie-woo' ), Groups::label( $number ) ) );
				}

				$current = $extend && $gifts[ $target ]['box'] ? reset( $gifts[ $target ]['box'] )['data']->get_id() : 0;
				$box     = GiftPacking::box_from_product( $offer['box'][ $box_id ] ) + array( 'added' => $current !== $box_id );

				$stock[ (string) $box_id ] = self::stock( $offer['box'][ $box_id ] );
			}

			$items   = array();
			$adds    = array();
			$entries = array(
				'ribbon' => (array) ( $group['ribbons'] ?? array() ),
				'card'   => (array) ( $group['cards'] ?? array() ),
			);

			if ( count( $entries['ribbon'] ) + count( $entries['card'] ) > GiftGroups::MAX_ENTRIES ) {
				self::fail( __( 'Não foi possível montar o presente. Tente de novo.', 'galaxie-woo' ) );
			}

			foreach ( $entries as $kind => $list ) {
				foreach ( $list as $entry ) {
					$id = is_array( $entry ) ? absint( $entry['id'] ?? 0 ) : 0;

					if ( ! $id || ! isset( $offer[ $kind ][ $id ] ) ) {
						self::fail( __( 'Um dos itens escolhidos não está mais disponível.', 'galaxie-woo' ) );
					}

					$stock[ (string) $id ] = self::stock( $offer[ $kind ][ $id ] );

					if ( 'card' === $kind ) {
						// The card goes with the box: the variation sized like it, never another one.
						$card   = $offer['card'][ $id ];
						$parent = $card->is_type( 'variation' ) ? $card->get_parent_id() : $card->get_id();

						if ( self::card_for( $parent, $box_id ? $offer['box'][ $box_id ] : null, $offer['card'] ) !== $id ) {
							self::fail( __( 'O cartão escolhido não corresponde à caixa deste presente. Abra o presente de novo.', 'galaxie-woo' ) );
						}

						$message = trim( sanitize_textarea_field( (string) ( $entry['message'] ?? '' ) ) );
						$items[] = array( 'id' => $id, 'kind' => 'card', 'quantity' => 1, 'message' => $message );
						$key     = $id . '|' . md5( $message );
						$adds[ $key ] = array( 'kind' => 'card', 'id' => $id, 'quantity' => ( $adds[ $key ]['quantity'] ?? 0 ) + 1, 'message' => $message );
						continue;
					}

					$quantity = absint( $entry['quantity'] ?? 0 );

					if ( $quantity < 1 ) {
						continue;
					}

					$items[]      = array( 'id' => $id, 'kind' => 'ribbon', 'quantity' => $quantity );
					$key          = (string) $id;
					$adds[ $key ] = array( 'kind' => 'ribbon', 'id' => $id, 'quantity' => ( $adds[ $key ]['quantity'] ?? 0 ) + $quantity, 'message' => '' );
				}
			}

			// A gift being added to that changes box keeps its cards, each resized
			// to the new box: same quantity and message, the card_for() variation.
			$swaps = array();

			if ( $extend ) {
				$current_box = $gifts[ $target ]['box'] ? reset( $gifts[ $target ]['box'] )['data']->get_id() : 0;

				foreach ( $current_box !== $box_id ? $gifts[ $target ]['cards'] : array() as $key => $item ) {
					$card   = $item['data'];
					$parent = $card->is_type( 'variation' ) ? $card->get_parent_id() : $card->get_id();
					$next   = self::card_for( $parent, $box_id ? $offer['box'][ $box_id ] : null, $offer['card'] );

					if ( ! $next ) {
						self::fail( __( 'Um cartão deste presente não existe para a caixa escolhida.', 'galaxie-woo' ) );
					}

					if ( $next === $card->get_id() ) {
						continue;
					}

					$in_group = Groups::group_of( $item );
					$swaps[]  = array(
						'key'      => (string) $key,
						'id'       => $next,
						'quantity' => (int) $item['quantity'],
						'message'  => $in_group ? $in_group['message'] : '',
					);

					// Stock for the new size; the old line's units leave the cart.
					$items[]                 = array( 'id' => $next, 'kind' => 'card', 'quantity' => (int) $item['quantity'] );
					$stock[ (string) $next ] = self::stock( $offer['card'][ $next ] );
				}
			}

			$plan_groups[] = array(
				'candles' => $extend || $count || $moves ? $candles : array(),
				'box'     => $box,
				'items'   => $items,
			);

			$planned[] = array(
				'number'  => $number,
				'pending' => $count,
				'moves'   => $moves,
				'box'     => $box_id,
				'adds'    => $adds,
				'swaps'   => $swaps,
			);
		}

		// Every unit of the candle being bought goes somewhere, and every loose
		// candle chosen goes whole: the popup never sends a part of either.
		if ( $pending_used !== $pending['quantity'] ) {
			self::fail( __( 'A quantidade de velas mudou. Abra o presente de novo.', 'galaxie-woo' ) );
		}

		foreach ( $loose_used as $key => $n ) {
			if ( $n !== (int) $loose[ $key ]['quantity'] ) {
				self::fail( __( 'As velas no carrinho mudaram. Abra o presente de novo.', 'galaxie-woo' ) );
			}
		}

		$errors = GiftGroups::validate(
			array(
				'groups'      => $plan_groups,
				'stock'       => $stock,
				'in_cart'     => self::in_cart( $contents ),
				'message_max' => $max_message,
			),
			$options
		);

		if ( $errors ) {
			self::fail( self::error_message( $errors[0], $planned, $max_message ), $errors );
		}

		self::validate_adds( $pending, $planned, $offer );

		$snapshot = WC()->cart->get_cart_contents();
		$ids      = array();

		foreach ( $planned as $g => $plan ) {
			$id    = $extend ? $target : Groups::new_id();
			$ids[] = $id;

			foreach ( $plan['moves'] as $key => $n ) {
				self::move( (string) $key, $n, $id );
			}

			$added = true;

			if ( $plan['pending'] > 0 ) {
				$added = (bool) WC()->cart->add_to_cart(
					$pending['parent'],
					$plan['pending'],
					$pending['variation'],
					$pending['attributes'],
					array( Flag::CART_KEY => array( 'gift' => true ) ) + Groups::data( $id, Groups::ROLE_CANDLE )
				);
			}

			if ( $added && $extend ) {
				$added = self::replace_box( $gifts[ $target ]['box'], $plan['box'] );
			}

			foreach ( $plan['swaps'] as $swap ) {
				if ( ! $added ) {
					break;
				}

				$lines = WC()->cart->get_cart_contents();
				unset( $lines[ $swap['key'] ] );
				WC()->cart->set_cart_contents( $lines );

				$added = self::add_accessory( $offer['card'][ $swap['id'] ], $swap['quantity'], $id, Groups::ROLE_CARD, $swap['message'] );
			}

			if ( $added && $plan['box'] && ( ! $extend || self::box_changed( $gifts[ $target ]['box'], $plan['box'] ) ) ) {
				$added = self::add_accessory( $offer['box'][ $plan['box'] ], 1, $id, Groups::ROLE_BOX );
			}

			foreach ( $plan['adds'] as $add ) {
				if ( ! $added ) {
					break;
				}

				$added = self::add_accessory( $offer[ $add['kind'] ][ $add['id'] ], $add['quantity'], $id, $add['kind'], $add['message'] );
			}

			if ( ! $added ) {
				$notices = wc_get_notices( 'error' );
				wc_clear_notices();

				WC()->cart->set_cart_contents( $snapshot );
				WC()->cart->calculate_totals();

				self::fail( $notices ? wp_strip_all_tags( (string) $notices[0]['notice'] ) : __( 'Não foi possível adicionar o presente ao carrinho.', 'galaxie-woo' ) );
			}
		}

		Groups::tidy( WC()->cart );
		WC()->cart->calculate_totals();

		do_action( 'woocommerce_ajax_added_to_cart', $pending['parent'] );

		wp_send_json_success(
			array(
				'gifts'        => $ids,
				'fragments'    => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
				'cart_hash'    => WC()->cart->get_cart_hash(),
				'checkout_url' => wc_get_checkout_url(),
			)
		);
	}

	// ------------------------------------------------------------- internals

	private static function require_cart(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			self::fail( __( 'Carrinho indisponível.', 'galaxie-woo' ) );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $errors
	 * @return never
	 */
	private static function fail( string $message, array $errors = array() ): void {
		wp_send_json_error(
			array(
				'message' => $message,
				'errors'  => $errors,
			)
		);
	}

	/**
	 * The settings of the Gift Builder that asked, from the ids Elementor put
	 * in the page — so category overrides come from what the merchant saved.
	 *
	 * @return array<string,mixed>
	 */
	private static function widget_settings(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by the caller.
		$post_id    = isset( $_POST['elementor_post'] ) ? absint( $_POST['elementor_post'] ) : 0;
		$element_id = isset( $_POST['element_id'] ) ? sanitize_key( wp_unslash( $_POST['element_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return CartParts::element_settings( $post_id, $element_id );
	}

	/**
	 * The candle the Buy Box is about to add, as the add would see it.
	 *
	 * @return array{product:\WC_Product, parent:int, variation:int, attributes:array, quantity:int}
	 */
	private static function pending(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by the caller.
		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$quantity     = isset( $_POST['quantity'] ) ? (int) wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );

		if ( ! $product || ( $variation_id && ! $product->is_type( 'variation' ) ) || ( ! $variation_id && $product->is_type( 'variable' ) ) || ! $product->is_purchasable() || $quantity < 1 || $quantity > GiftGroups::MAX_CANDLES ) {
			self::fail( __( 'Selecione uma variação válida.', 'galaxie-woo' ) );
		}

		// Only a candle — the size attribute, and gift or WooCommerce dimensions —
		// can be put in a gift. Never a gift that cannot be packed.
		if ( ! GiftPacking::candle_from_product( $product, (string) Module::setting( 'size_attribute' ) ) ) {
			self::fail( self::no_dimensions() );
		}

		return array(
			'product'    => $product,
			'parent'     => $variation_id ? $product->get_parent_id() : $product->get_id(),
			'variation'  => $variation_id,
			'attributes' => $variation_id ? $product->get_variation_attributes() : array(),
			'quantity'   => $quantity,
		);
	}

	/**
	 * Purchasable products of one kind, by product (variation) id. Variable
	 * products offer each variation; boxes only those with inside dimensions.
	 *
	 * @param array<string,mixed> $settings Gift Builder widget settings.
	 * @return array<int,\WC_Product>
	 */
	private static function catalogue( string $kind, array $settings ): array {
		static $cache = array();

		$ids = Module::categories( $kind, $settings[ $kind . '_categories' ] ?? array() );
		$key = $kind . ':' . implode( ',', $ids );

		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$slugs = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$slugs[] = $term->slug;
			}
		}

		$found = array();

		if ( $slugs ) {
			$products = wc_get_products(
				array(
					'status'   => 'publish',
					'limit'    => self::CATALOGUE_LIMIT,
					'category' => $slugs,
					'orderby'  => 'menu_order',
					'order'    => 'ASC',
				)
			);

			foreach ( is_array( $products ) ? $products : array() as $product ) {
				$options = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );

				foreach ( $options as $option ) {
					if ( ! $option instanceof \WC_Product || ! $option->is_purchasable() || $option->is_type( 'variable' ) ) {
						continue;
					}

					if ( 'box' === $kind && ! GiftPacking::box_from_product( $option ) ) {
						continue;
					}

					$found[ $option->get_id() ] = $option;
				}
			}
		}

		return $cache[ $key ] = $found;
	}

	/**
	 * The card a gift gets for its box, from one card product.
	 *
	 * Cards come in one size per box, told apart by an attribute the box has too
	 * (e.g. "Tamanho" = Quadrada / Grande on both): the variation whose shared
	 * attributes all equal the box's, by name and value, case-insensitive. With
	 * no box, the first variation in stock, size unsaid. A simple card goes with
	 * any box. 0 when none is in stock or none matches: the card is not offered.
	 * Mirrored by cardFor() in gift-builder.ts.
	 *
	 * @param int                    $parent Card product id (the parent of a variation).
	 * @param \WC_Product|null       $box    The gift's box, or null.
	 * @param array<int,\WC_Product> $cards  The card offer, in catalogue order.
	 */
	private static function card_for( int $parent, ?\WC_Product $box, array $cards ): int {
		// One request, one offer: the rows are built once and every answer is
		// kept per (card product, box), however many cards the plan names.
		static $rows = null;
		static $memo = array();

		$key = $parent . '|' . ( $box ? $box->get_id() : 0 );

		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		if ( null === $rows ) {
			$rows = array();

			foreach ( $cards as $id => $card ) {
				$rows[] = array(
					'id'     => (int) $id,
					'parent' => $card->is_type( 'variation' ) ? $card->get_parent_id() : $card->get_id(),
					'attrs'  => self::attributes( $card ),
					'stock'  => self::stock( $card ),
				);
			}
		}

		return $memo[ $key ] = GiftGroups::card_for( $rows, $parent, $box ? self::attributes( $box ) : null );
	}

	/**
	 * A variation's attributes as label => value, both sanitize_title()'d, so a
	 * local "Tamanho: Quadrada" and a global one compare equal whatever the case.
	 *
	 * @return array<string,string>
	 */
	private static function attributes( \WC_Product $product ): array {
		$out = array();

		if ( ! $product->is_type( 'variation' ) ) {
			return $out;
		}

		foreach ( $product->get_variation_attributes( false ) as $name => $value ) {
			$name  = (string) $name;
			$value = (string) $value;

			if ( '' === $value ) {
				continue;
			}

			if ( taxonomy_exists( $name ) ) {
				$term  = get_term_by( 'slug', $value, $name );
				$value = $term instanceof \WP_Term ? $term->name : $value;
			}

			$out[ sanitize_title( wc_attribute_label( $name, $product ) ) ] = sanitize_title( $value );
		}

		return $out;
	}

	/**
	 * Units left to sell, or null when not limited.
	 */
	private static function stock( \WC_Product $product ): ?int {
		if ( ! $product->is_in_stock() ) {
			return 0;
		}

		if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
			return max( 0, (int) $product->get_stock_quantity() );
		}

		return null;
	}

	/**
	 * Units of each product already in the cart, by product (variation) id.
	 *
	 * @return array<string,int>
	 */
	private static function in_cart( array $contents ): array {
		$counts = array();

		foreach ( $contents as $item ) {
			if ( ( $item['data'] ?? null ) instanceof \WC_Product ) {
				$id            = (string) $item['data']->get_id();
				$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + (int) $item['quantity'];
			}
		}

		return $counts;
	}

	/**
	 * Candles marked as a gift but in no gift yet — added with "Seguir sem
	 * incrementar o presente", or before gifts had boxes.
	 *
	 * @return array<string,array> Cart items by key.
	 */
	private static function loose( array $contents ): array {
		return array_filter(
			$contents,
			static fn( $item ): bool => Flag::is_gift_item( $item )
				&& ! Groups::group_of( $item )
				&& ( $item['data'] ?? null ) instanceof \WC_Product
				// Without dimensions it cannot be packed: not offered.
				&& null !== GiftPacking::candle_from_product( $item['data'], (string) Module::setting( 'size_attribute' ) )
		);
	}

	/**
	 * Runs WooCommerce's add-to-cart validation for everything the gift adds,
	 * before the cart is touched: a plugin that refuses one product refuses the
	 * gift, with its own sentence.
	 */
	private static function validate_adds( array $pending, array $planned, array $offer ): void {
		$checks = array();

		foreach ( $planned as $plan ) {
			if ( $plan['box'] ) {
				$checks[] = array( $offer['box'][ $plan['box'] ], 1 );
			}

			foreach ( $plan['adds'] as $add ) {
				$checks[] = array( $offer[ $add['kind'] ][ $add['id'] ], $add['quantity'] );
			}
		}

		$notices_before = wc_get_notices();
		wc_clear_notices();

		$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $pending['parent'], $pending['quantity'], $pending['variation'], $pending['attributes'] );

		foreach ( $checks as $check ) {
			if ( ! $passed ) {
				break;
			}

			$product = $check[0];
			$is_var  = $product->is_type( 'variation' );
			$passed  = apply_filters( 'woocommerce_add_to_cart_validation', true, $is_var ? $product->get_parent_id() : $product->get_id(), $check[1], $is_var ? $product->get_id() : 0, $is_var ? $product->get_variation_attributes() : array() );
		}

		$errors = wc_get_notices( 'error' );
		wc_set_notices( $notices_before );

		if ( ! $passed ) {
			self::fail( $errors ? wp_strip_all_tags( (string) $errors[0]['notice'] ) : __( 'Não foi possível adicionar o presente ao carrinho.', 'galaxie-woo' ) );
		}
	}

	private static function add_accessory( \WC_Product $product, int $quantity, string $id, string $role, string $message = '' ): bool {
		$is_var = $product->is_type( 'variation' );

		return (bool) WC()->cart->add_to_cart(
			$is_var ? $product->get_parent_id() : $product->get_id(),
			$quantity,
			$is_var ? $product->get_id() : 0,
			$is_var ? $product->get_variation_attributes() : array(),
			Groups::data( $id, $role, $message )
		);
	}

	/**
	 * Moves units of a loose gift candle into a gift: the same line under the
	 * gift's id, merged with one already there. Written to the contents — the
	 * product is already in the cart and needs no second validation.
	 */
	private static function move( string $key, int $count, string $id ): void {
		$contents = WC()->cart->get_cart_contents();
		$item     = $contents[ $key ] ?? null;

		if ( ! $item || $count < 1 ) {
			return;
		}

		$data    = array_diff_key( $item, array_flip( self::CART_FIELDS ) );
		$data    = array_merge( $data, Groups::data( $id, Groups::ROLE_CANDLE ) );
		$new_key = WC()->cart->generate_cart_id( $item['product_id'], $item['variation_id'], $item['variation'], $data );

		if ( $count >= (int) $item['quantity'] ) {
			unset( $contents[ $key ] );
		} else {
			$contents[ $key ]['quantity'] = (int) $item['quantity'] - $count;
		}

		if ( isset( $contents[ $new_key ] ) ) {
			$contents[ $new_key ]['quantity'] += $count;
		} else {
			$contents[ $new_key ] = array_merge( $item, $data, array( 'key' => $new_key, 'quantity' => $count ) );
		}

		WC()->cart->set_cart_contents( $contents );
	}

	/** @param array|null $box [ key => item ] from Groups::groups(). */
	private static function box_changed( ?array $box, int $box_id ): bool {
		$item = $box ? reset( $box ) : null;

		return ! $item || $item['data']->get_id() !== $box_id;
	}

	/**
	 * A gift being added to that gets another box, or none, loses the one it had.
	 *
	 * @param array|null $box [ key => item ] from Groups::groups().
	 */
	private static function replace_box( ?array $box, int $box_id ): bool {
		if ( ! $box || ! self::box_changed( $box, $box_id ) ) {
			return true;
		}

		$contents = WC()->cart->get_cart_contents();
		unset( $contents[ (string) array_key_first( $box ) ] );
		WC()->cart->set_cart_contents( $contents );

		return true;
	}

	/** A shopper's sentence for GiftGroups::check_request()'s answer. */
	private static function request_error( string $code ): string {
		switch ( $code ) {
			case 'pending_count':
				return __( 'A quantidade de velas mudou. Abra o presente de novo.', 'galaxie-woo' );
			case 'loose_count':
			case 'unknown_candle':
				return __( 'As velas no carrinho mudaram. Abra o presente de novo.', 'galaxie-woo' );
			case 'box_too_full':
				return __( 'Uma caixa não comporta tantas velas.', 'galaxie-woo' );
			case 'too_many_candles':
				return __( 'São velas demais para montar num pedido só. Fale com a loja.', 'galaxie-woo' );
			default:
				return __( 'Não foi possível montar o presente. Tente de novo.', 'galaxie-woo' );
		}
	}

	/**
	 * @param array{code:string, group:int, id:?string} $error
	 */
	private static function error_message( array $error, array $planned, int $max_message ): string {
		$label = isset( $planned[ $error['group'] ] ) ? Groups::label( $planned[ $error['group'] ]['number'] ) : '';
		$name  = '';

		if ( null !== $error['id'] ) {
			$product = wc_get_product( (int) $error['id'] );
			$name    = $product ? wp_strip_all_tags( $product->get_name() ) : '';
		}

		switch ( $error['code'] ) {
			case 'no_candles':
				/* translators: %s: "Presente 1". */
				return sprintf( __( 'O %s está sem velas.', 'galaxie-woo' ), $label );
			case 'box_too_small':
				/* translators: %s: "Presente 1". */
				return sprintf( __( 'As velas do %s não cabem na caixa escolhida.', 'galaxie-woo' ), $label );
			case 'message_too_long':
				/* translators: 1: "Presente 1", 2: character limit. */
				return sprintf( __( 'A mensagem de um cartão do %1$s passa de %2$d caracteres.', 'galaxie-woo' ), $label, $max_message );
			case 'out_of_stock':
				/* translators: %s: product name. */
				return sprintf( __( 'Não há estoque suficiente de %s.', 'galaxie-woo' ), $name );
			default:
				/* translators: %s: "Presente 1". */
				return sprintf( __( 'Quantidade inválida no %s.', 'galaxie-woo' ), $label );
		}
	}

	// ------------------------------------------------------------ JSON views

	/** @return array<string,mixed> */
	private static function product_json( \WC_Product $product ): array {
		$image = wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_gallery_thumbnail' );

		return array(
			'id'    => $product->get_id(),
			'name'  => wp_strip_all_tags( $product->get_name() ),
			'image' => $image ? $image : ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'woocommerce_gallery_thumbnail' ) : '' ),
			'price' => (float) wc_get_price_to_display( $product ),
			'stock' => self::stock( $product ),
		);
	}

	/** @return array<string,mixed> */
	private static function option_json( \WC_Product $product ): array {
		$json = self::product_json( $product );
		$box  = GiftPacking::box_from_product( $product );

		// A card row is a product, whatever its size: the title without the variation.
		$json['parent'] = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$json['title']  = wp_strip_all_tags( $product->get_title() );
		$json['attrs']  = (object) self::attributes( $product );

		if ( $box ) {
			$json['box'] = $box + array( 'price' => $json['price'] );
		}

		return $json;
	}

	/** @return array<string,mixed> */
	private static function pending_json( array $pending, string $attribute ): array {
		return self::product_json( $pending['product'] ) + array(
			'quantity' => $pending['quantity'],
			'candle'   => GiftPacking::candle_from_product( $pending['product'], $attribute ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function loose_json( array $contents, string $attribute ): array {
		$out = array();

		foreach ( self::loose( $contents ) as $key => $item ) {
			$out[] = self::product_json( $item['data'] ) + array(
				'key'      => (string) $key,
				'quantity' => (int) $item['quantity'],
				'candle'   => GiftPacking::candle_from_product( $item['data'], $attribute ),
			);
		}

		return $out;
	}

	/** @return array<int,array<string,mixed>> Gifts in the cart, in number order. */
	private static function gifts_json( array $contents ): array {
		$attribute = (string) Module::setting( 'size_attribute' );
		$out       = array();

		foreach ( Groups::groups( $contents ) as $id => $gift ) {
			$lines = static function ( array $items ) use ( $attribute ): array {
				$rows = array();
				foreach ( $items as $key => $item ) {
					$group  = Groups::group_of( $item );
					$rows[] = self::product_json( $item['data'] ) + array(
						'parent'   => $item['data']->is_type( 'variation' ) ? $item['data']->get_parent_id() : $item['data']->get_id(),
						'key'      => (string) $key,
						'quantity' => (int) $item['quantity'],
						'candle'   => GiftPacking::candle_from_product( $item['data'], $attribute ),
						'message'  => $group ? $group['message'] : '',
					);
				}
				return $rows;
			};

			if ( ! $gift['candles'] ) {
				continue;
			}

			$box   = $gift['box'] ? reset( $gift['box'] ) : null;
			$out[] = array(
				'id'      => (string) $id,
				'number'  => $gift['number'],
				'label'   => Groups::label( $gift['number'] ),
				'candles' => $lines( $gift['candles'] ),
				'box'     => $box ? self::option_json( $box['data'] ) : null,
				'ribbons' => $lines( $gift['ribbons'] ),
				'cards'   => $lines( $gift['cards'] ),
			);
		}

		return $out;
	}
}

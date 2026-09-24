<?php
/**
 * What the store offers in gifts: boxes and cards (and ribbons), one role each.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

use Galaxie\Woo\Support\GiftGroups;
use Galaxie\Woo\Support\GiftPacking;

defined( 'ABSPATH' ) || exit;

/**
 * The accessory offer the kit flow ({@see Kit\WooCatalog}) and the editor
 * previews read. Which categories hold boxes, ribbons and cards comes from the
 * module settings only (wp-admin → Galaxie → Gift Wrap), and each product
 * plays one role: a box product is never offered as a card or a ribbon, a card
 * never as a ribbon ({@see GiftGroups::roles()}).
 *
 * This class also held the Phase 2 gift builder's own requests
 * (`galaxie_gift_builder_data` / `_add`). The kit flow replaced that builder
 * (PR #21): kits are built outside the cart and put in it by
 * {@see Kit\CartKits}, through the same checks (fit, card for the box, stock,
 * WooCommerce's add-to-cart validation) and the same all-or-nothing write.
 */
final class Builder {

	/** Accessory products read per kind. A gift builder, not a catalogue. */
	private const CATALOGUE_LIMIT = 50;

	/**
	 * The boxes, ribbons and cards the store offers in gifts, by product
	 * (variation) id.
	 *
	 * @return array{box: array<int,\WC_Product>, ribbon: array<int,\WC_Product>, card: array<int,\WC_Product>}
	 */
	public static function offer(): array {
		return array(
			'box'    => self::catalogue( 'box' ),
			'ribbon' => self::catalogue( 'ribbon' ),
			'card'   => self::catalogue( 'card' ),
		);
	}

	/** Units left to sell, or null when not limited. */
	public static function units_left( \WC_Product $product ): ?int {
		if ( ! $product->is_in_stock() ) {
			return 0;
		}

		if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
			return max( 0, (int) $product->get_stock_quantity() );
		}

		return null;
	}

	/**
	 * The card a gift gets for its box, from one card product.
	 *
	 * Cards come in one size per box, told apart by an attribute the box has too
	 * (e.g. "Tamanho" = Quadrada / Grande on both): the variation whose shared
	 * attributes all equal the box's, by name and value, case-insensitive. With
	 * no box, the first variation in stock, size unsaid. A simple card goes with
	 * any box. 0 when none is in stock or none matches: the card is not offered.
	 * Mirrored by cardFor() in gift-groups.ts.
	 *
	 * @param int                    $parent Card product id (the parent of a variation).
	 * @param \WC_Product|null       $box    The gift's box, or null.
	 * @param array<int,\WC_Product> $cards  The card offer, in catalogue order.
	 */
	public static function card_id( int $parent, ?\WC_Product $box, array $cards ): int {
		// One request, one offer: the rows are built once and every answer is
		// kept per (card product, box).
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
					'stock'  => self::units_left( $card ),
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
	public static function attributes( \WC_Product $product ): array {
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
	 * A real candle to show in the editor: of the first published product with
	 * the candle size attribute and dimensions to pack with, its largest size.
	 */
	public static function sample_candle(): ?\WC_Product {
		$attribute = Module::size_attribute();

		if ( '' === $attribute ) {
			return null;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => array(
					'parent' => 'ASC',
					'ID'     => 'ASC',
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'attribute_' . $attribute,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$best   = null;
		$parent = 0;
		$volume = 0.0;

		foreach ( $ids as $id ) {
			$variation = wc_get_product( $id );

			if ( ! $variation instanceof \WC_Product || 'publish' !== get_post_status( $variation->get_parent_id() ) || ! $variation->is_purchasable() ) {
				continue;
			}

			$candle = GiftPacking::candle_from_product( $variation, $attribute );

			if ( ! $candle ) {
				continue;
			}

			// The first product found is the one shown; only its sizes compete.
			if ( $parent && $variation->get_parent_id() !== $parent ) {
				break;
			}

			$parent = $variation->get_parent_id();
			$size   = $candle['length'] * $candle['width'] * $candle['height'];

			if ( $size > $volume ) {
				$volume = $size;
				$best   = $variation;
			}
		}

		return $best;
	}

	// ------------------------------------------------------------- internals

	/**
	 * Purchasable products of one role, by product (variation) id.
	 *
	 * @param string $kind `box`, `card` or `ribbon`.
	 * @return array<int,\WC_Product>
	 */
	private static function catalogue( string $kind ): array {
		static $roles = null;

		if ( null === $roles ) {
			$roles = self::roles();
		}

		return $roles[ $kind ] ?? array();
	}

	/**
	 * Every accessory on offer, each in exactly one role (GiftGroups::roles()):
	 * the module's categories say where to look, the product says what it is.
	 * Variable products offer each purchasable variation; a product with box
	 * dimensions on any variation is a box product and only its sized
	 * variations are offered, as boxes.
	 *
	 * @return array{box: array<int,\WC_Product>, card: array<int,\WC_Product>, ribbon: array<int,\WC_Product>}
	 */
	private static function roles(): array {
		$categories = array(
			'box'    => Module::categories( 'box' ),
			'card'   => Module::categories( 'card' ),
			'ribbon' => Module::categories( 'ribbon' ),
		);
		$out        = array(
			'box'    => array(),
			'card'   => array(),
			'ribbon' => array(),
		);
		$slugs      = array();

		foreach ( array_unique( array_merge( $categories['box'], $categories['card'], $categories['ribbon'] ) ) as $id ) {
			$term = get_term( (int) $id, 'product_cat' );

			if ( $term instanceof \WP_Term ) {
				$slugs[] = $term->slug;
			}
		}

		if ( ! $slugs ) {
			return $out;
		}

		$products = wc_get_products(
			array(
				'status'   => 'publish',
				'limit'    => self::CATALOGUE_LIMIT * 3,
				'category' => $slugs,
				'orderby'  => 'menu_order',
				'order'    => 'ASC',
			)
		);

		$rows  = array();
		$found = array();

		foreach ( is_array( $products ) ? $products : array() as $product ) {
			$options = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
			$boxed   = false;
			$usable  = array();

			foreach ( $options as $option ) {
				if ( ! $option instanceof \WC_Product || $option->is_type( 'variable' ) ) {
					continue;
				}

				$sized = null !== GiftPacking::box_from_product( $option );
				$boxed = $boxed || $sized;

				if ( $option->is_purchasable() ) {
					$usable[] = array( $option, $sized );
				}
			}

			foreach ( $usable as $pair ) {
				$found[ $pair[0]->get_id() ] = $pair[0];
				$rows[]                      = array(
					'id'          => $pair[0]->get_id(),
					'box_product' => $boxed,
					'box_size'    => $pair[1],
					'categories'  => $product->get_category_ids(),
				);
			}
		}

		foreach ( GiftGroups::roles( $rows, $categories ) as $kind => $ids ) {
			foreach ( $ids as $id ) {
				$out[ $kind ][ $id ] = $found[ $id ];
			}
		}

		return $out;
	}
}

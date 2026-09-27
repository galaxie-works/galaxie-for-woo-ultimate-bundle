<?php
/**
 * The kit catalog, read from WooCommerce.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Kit;

use Galaxie\Woo\Modules\GiftWrap\Builder;
use Galaxie\Woo\Modules\GiftWrap\Module;
use Galaxie\Woo\Support\GiftPacking;

defined( 'ABSPATH' ) || exit;

/**
 * Boxes and cards are the Gift Wrap offer ({@see Builder::offer()}); a candle
 * is any purchasable simple product or variation, of a published product, with
 * the size attribute and dimensions to pack with, that is not itself on offer
 * as a box or card. Rows are built once per request.
 */
final class WooCatalog implements Catalog {

	/** Shared packing answers kept at once. */
	private const HOLDS_KEPT = 100;

	/**
	 * How long an answer the search could not finish is kept before it is asked
	 * again. A settled answer keeps for a day; this one is a guess that stops
	 * the endpoint recomputing it on every request, and nothing more.
	 *
	 * A quarter of an hour, from measuring the boxes the store sells: two of ten
	 * "Leva até …" answers do not settle, and each costs the combinations'
	 * 250 ms budget to ask. Long enough that one request in a quarter of an hour
	 * pays it, short enough that a store whose slow moment has passed is not
	 * stuck with the answer it gave during it.
	 */
	private const UNSETTLED_SECONDS = 900;

	/** @var array<int, array|null> */
	private array $candles = array();

	/** @var array<int, array>|null */
	private ?array $boxes = null;

	/** @var array<int, array>|null */
	private ?array $cards = null;

	/** @var array{box: array<int,\WC_Product>, ribbon: array<int,\WC_Product>, card: array<int,\WC_Product>}|null */
	private ?array $offer = null;

	public function candle( int $id ): ?array {
		if ( array_key_exists( $id, $this->candles ) ) {
			return $this->candles[ $id ];
		}

		$offer   = $this->offer();
		$product = $id > 0 && ! isset( $offer['box'][ $id ] ) && ! isset( $offer['card'][ $id ] ) ? wc_get_product( $id ) : null;

		if ( ! $product instanceof \WC_Product || $product->is_type( 'variable' ) || ! $product->is_purchasable() ) {
			return $this->candles[ $id ] = null;
		}

		$parent = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		if ( 'publish' !== get_post_status( $parent ) ) {
			return $this->candles[ $id ] = null;
		}

		$candle = GiftPacking::candle_from_product( $product, Module::size_attribute() );

		if ( ! $candle ) {
			return $this->candles[ $id ] = null;
		}

		return $this->candles[ $id ] = self::row( $product ) + array( 'candle' => $candle );
	}

	public function boxes(): array {
		if ( null === $this->boxes ) {
			$this->boxes = array();

			foreach ( $this->offer()['box'] as $id => $product ) {
				$shape = GiftPacking::box_from_product( $product );

				if ( $shape ) {
					$this->boxes[ (int) $id ] = self::row( $product ) + array(
						'shape'       => $shape,
						'attrs'       => Builder::attributes( $product ),
						'description' => trim( wp_strip_all_tags( (string) $product->get_description() ) ),
					);
				}
			}
		}

		return $this->boxes;
	}

	public function cards(): array {
		if ( null === $this->cards ) {
			$this->cards = array();

			foreach ( $this->offer()['card'] as $id => $product ) {
				$this->cards[ (int) $id ] = self::row( $product ) + array( 'attrs' => Builder::attributes( $product ) );
			}
		}

		return $this->cards;
	}

	public function card_for( int $parent, int $box ): int {
		$offer   = $this->offer();
		$product = $box ? ( $offer['box'][ $box ] ?? null ) : null;

		if ( $box && ! $product ) {
			return 0;
		}

		return Builder::card_id( $parent, $product, $offer['card'] );
	}

	public function sizes(): array {
		return array_values( GiftPacking::store_sizes( Module::size_attribute(), Module::accessory_categories() ) );
	}

	public function options(): array {
		return Module::packing_options();
	}

	public function message_max(): int {
		return (int) Module::setting( 'card_message_max' );
	}

	/**
	 * WooCommerce's add-to-cart validation for one product, with its notices
	 * kept aside: a plugin that refuses the product refuses the kit, in its own
	 * words, and nothing is left on the page's notice stack.
	 */
	public function can_add( array $product, int $quantity ): string {
		$before = wc_get_notices();
		wc_clear_notices();

		$passed = apply_filters(
			'woocommerce_add_to_cart_validation',
			true,
			(int) $product['parent'],
			$quantity,
			$product['variation'] ? (int) $product['id'] : 0,
			(array) $product['attributes']
		);

		$errors = wc_get_notices( 'error' );
		wc_set_notices( $before );

		if ( $passed ) {
			return '';
		}

		return $errors ? wp_strip_all_tags( (string) $errors[0]['notice'] ) : __( 'Não foi possível adicionar o kit ao carrinho.', 'galaxie-woo' );
	}

	public function shows_stock(): bool {
		return 'no_amount' !== get_option( 'woocommerce_stock_format', '' );
	}

	public function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
	}

	/**
	 * In GiftPacking::HOLDS_TRANSIENT, a day at most, the newest HOLDS_KEPT
	 * answers; GiftPacking::flush_sizes() clears it with the sizes.
	 *
	 * Every row carries whether the search finished and when it ran. An answer
	 * it could not finish used to be kept for a day like any other, so a single
	 * slow request set "Leva até …" for every shopper until the sizes changed;
	 * it is now asked again after UNSETTLED_SECONDS. Rows written before this
	 * rule have no marker and are asked again.
	 *
	 * @param callable $compute (): array{0:mixed, 1:bool}
	 * @return mixed
	 */
	public function remember( string $key, callable $compute ) {
		$kept = get_transient( GiftPacking::HOLDS_TRANSIENT );
		$kept = is_array( $kept ) ? $kept : array();
		$row  = $kept[ $key ] ?? null;
		$now  = time();

		if ( is_array( $row ) && array_key_exists( 'value', $row ) && array_key_exists( 'settled', $row )
			&& ( $row['settled'] || $now - (int) ( $row['at'] ?? 0 ) < self::UNSETTLED_SECONDS ) ) {
			return $row['value'];
		}

		list( $value, $settled ) = $compute();

		unset( $kept[ $key ] );
		$kept[ $key ] = array(
			'value'   => $value,
			'settled' => (bool) $settled,
			'at'      => $now,
		);

		set_transient( GiftPacking::HOLDS_TRANSIENT, array_slice( $kept, -self::HOLDS_KEPT, null, true ), DAY_IN_SECONDS );

		return $value;
	}

	/** @return array{box: array<int,\WC_Product>, ribbon: array<int,\WC_Product>, card: array<int,\WC_Product>} */
	private function offer(): array {
		return $this->offer ??= Builder::offer();
	}

	/**
	 * The fields every row has.
	 *
	 * @return array<string,mixed>
	 */
	private static function row( \WC_Product $product ): array {
		$variation = $product->is_type( 'variation' );
		$image     = wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_thumbnail' );

		return array(
			'id'         => $product->get_id(),
			'parent'     => $variation ? $product->get_parent_id() : $product->get_id(),
			'variation'  => $variation,
			'attributes' => $variation ? $product->get_variation_attributes() : array(),
			'name'       => wp_strip_all_tags( $product->get_name() ),
			'title'      => wp_strip_all_tags( $product->get_title() ),
			// The variation's picture, falling back to the product's (get_image_id() does), then the placeholder.
			'image'      => $image ? (string) $image : ( function_exists( 'wc_placeholder_img_src' ) ? (string) wc_placeholder_img_src( 'woocommerce_thumbnail' ) : '' ),
			'price'      => (float) wc_get_price_to_display( $product ),
			'stock'      => Builder::units_left( $product ),
		);
	}
}

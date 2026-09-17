<?php
/**
 * What a kit can be made of, as the kit rules ask it.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Kit;

defined( 'ABSPATH' ) || exit;

/**
 * The store's side of a kit, in plain arrays, so {@see Kits} and {@see CartKits}
 * never read WooCommerce themselves: {@see WooCatalog} answers on the site, a
 * fake answers in tests/kit/run.php.
 *
 * A product row: `{ id, parent, variation (bool), attributes (for the cart),
 * name, title, image, price, stock (int|null) }`, plus `candle` (packing array)
 * for candles, `shape` (packing box), `attrs` and `description` for boxes and
 * `attrs` for cards.
 */
interface Catalog {

	/** A candle a kit can hold, by product (variation) id; null for anything else. */
	public function candle( int $id ): ?array;

	/** @return array<int, array> Boxes on offer, by variation id, in catalogue order. */
	public function boxes(): array;

	/** @return array<int, array> Cards on offer, by variation id, in catalogue order. */
	public function cards(): array;

	/** The card variation a kit with this box gets from one card product; 0 when none. */
	public function card_for( int $parent, int $box ): int;

	/** @return array<int, array> One candle per size the store sells, with a `label`. */
	public function sizes(): array;

	/** @return array{gap:float, stacking:bool, orientation:string} */
	public function options(): array;

	/** Longest card message, in characters. */
	public function message_max(): int;

	/** '' when the store (and its plugins) let this be added to the cart, else why not. */
	public function can_add( array $product, int $quantity ): string;

	/** A price as the store prints it, as plain text. */
	public function money( float $amount ): string;
}

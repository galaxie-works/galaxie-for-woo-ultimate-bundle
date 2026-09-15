<?php
/**
 * Gifts as groups: how full a box is, what may be added, what it costs.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The rules the Gift Builder and the server share about a planned gift.
 *
 * Like {@see GiftPacking}, plain arrays in and out, no WordPress, and a
 * TypeScript twin (`frontend/src/lib/gift-groups.ts`) held to the same
 * `tests/gift-packing/fixtures.json`. The popup uses the twin to paint a fill
 * bar and a running total; the server uses this one to refuse what the popup
 * should never have sent. Change one, change the other, run both tests.
 *
 * Every question about space goes to {@see GiftPacking::fits()} with the
 * options it was given (gap, stacking, orientation), so a box is never judged
 * by two models.
 *
 *   plan  { groups: [ { candles: [candle + id + added?], box: box + added? | null,
 *                       items: [ { id, kind, quantity, message? } ] } ],
 *           stock: { id: int|null }, in_cart: { id: int }, message_max: int }
 *
 * `added` false marks what is already in the cart (a candle moved into a gift,
 * the box a gift already has): it is checked for fit but asks no stock.
 */
final class GiftGroups {

	/**
	 * How full a box is, 0–100: the candles in it against the candles it could
	 * hold, filling what is left with the smallest size on offer.
	 *
	 * Counted, not measured, so it follows whatever the packing model says fits
	 * — lying down, upright, with or without a gap — instead of an area that is
	 * only right for one of them.
	 *
	 * @param array $box     Box array.
	 * @param array $candles Candles in the box.
	 * @param array $sizes   One candle per size the store sells; defaults to the ones in the box.
	 * @param array $options { gap, stacking, orientation }.
	 */
	public static function fill( array $box, array $candles, array $sizes = array(), array $options = array() ): int {
		$n = count( $candles );

		if ( 0 === $n ) {
			return 0;
		}

		if ( ! GiftPacking::fits( $box, $candles, $options ) ) {
			return 100;
		}

		$smallest = self::smallest( $sizes ? $sizes : $candles );
		$with     = $candles;
		$extra    = 0;

		while ( $smallest && count( $with ) < GiftPacking::MAX_ITEMS ) {
			$with[] = $smallest;

			if ( ! GiftPacking::fits( $box, $with, $options ) ) {
				break;
			}

			++$extra;
		}

		return intdiv( $n * 100 + intdiv( $n + $extra, 2 ), $n + $extra );
	}

	/**
	 * The most of one candle line a boxed gift can take, never less than it has.
	 *
	 * For the block cart's stepper: the Store API refuses a quantity past this.
	 *
	 * @param array $box     Box array.
	 * @param array $others  Every other candle in the gift.
	 * @param array $candle  The line's candle.
	 * @param int   $current The line's quantity now.
	 * @param array $options { gap, stacking, orientation }.
	 */
	public static function max_quantity( array $box, array $others, array $candle, int $current, array $options = array() ): int {
		$n = max( 0, $current );

		while ( count( $others ) + $n < GiftPacking::MAX_ITEMS ) {
			$with = $others;
			for ( $i = 0; $i <= $n; $i++ ) {
				$with[] = $candle;
			}

			if ( ! GiftPacking::fits( $box, $with, $options ) ) {
				break;
			}

			++$n;
		}

		return $n;
	}

	/**
	 * Everything wrong with a planned gift, in a fixed order: each group's own
	 * problems, group by group, then stock by product in the order first asked.
	 *
	 * @param array $plan    See the class comment.
	 * @param array $options { gap, stacking, orientation }.
	 * @return array<int, array{code:string, group:int, id:?string}>
	 */
	public static function validate( array $plan, array $options = array() ): array {
		$errors = array();
		$demand = array();
		$max    = max( 0, (int) ( $plan['message_max'] ?? 0 ) );

		$ask = static function ( $id, int $quantity ) use ( &$demand ): void {
			$key            = (string) $id;
			$demand[ $key ] = ( $demand[ $key ] ?? 0 ) + $quantity;
		};

		foreach ( array_values( (array) ( $plan['groups'] ?? array() ) ) as $g => $group ) {
			$candles = array_values( (array) ( $group['candles'] ?? array() ) );
			$box     = $group['box'] ?? null;

			if ( ! $candles ) {
				$errors[] = self::error( 'no_candles', $g );
			}

			foreach ( $candles as $candle ) {
				if ( false !== ( $candle['added'] ?? true ) ) {
					$ask( $candle['id'] ?? '', 1 );
				}
			}

			if ( is_array( $box ) ) {
				if ( $candles && ! GiftPacking::fits( $box, $candles, $options ) ) {
					$errors[] = self::error( 'box_too_small', $g, $box['id'] ?? '' );
				}

				if ( false !== ( $box['added'] ?? true ) ) {
					$ask( $box['id'] ?? '', 1 );
				}
			}

			foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
				$quantity = $item['quantity'] ?? 0;

				if ( ! is_int( $quantity ) || $quantity < 1 ) {
					$errors[] = self::error( 'bad_quantity', $g, $item['id'] ?? '' );
					continue;
				}

				if ( 'card' === ( $item['kind'] ?? '' ) && $max > 0 && self::message_length( (string) ( $item['message'] ?? '' ) ) > $max ) {
					$errors[] = self::error( 'message_too_long', $g, $item['id'] ?? '' );
				}

				$ask( $item['id'] ?? '', $quantity );
			}
		}

		$stock   = (array) ( $plan['stock'] ?? array() );
		$in_cart = (array) ( $plan['in_cart'] ?? array() );

		foreach ( $demand as $id => $quantity ) {
			$id    = (string) $id;
			$limit = $stock[ $id ] ?? null;

			if ( null !== $limit && $quantity + (int) ( $in_cart[ $id ] ?? 0 ) > (int) $limit ) {
				$errors[] = self::error( 'out_of_stock', -1, $id );
			}
		}

		return $errors;
	}

	/**
	 * The sum of price × quantity, in cents.
	 *
	 * @param array $lines [ { price, quantity } ].
	 */
	public static function total( array $lines ): int {
		$cents = 0;

		foreach ( $lines as $line ) {
			$cents += self::cents( $line['price'] ?? 0 ) * max( 0, (int) ( $line['quantity'] ?? 0 ) );
		}

		return $cents;
	}

	/**
	 * A card message's length as a shopper counts it: characters, not bytes,
	 * with a Windows line break counted once.
	 *
	 * @param string $message Message.
	 */
	public static function message_length( string $message ): int {
		return (int) preg_match_all( '/./su', str_replace( "\r\n", "\n", $message ) );
	}

	/**
	 * @return array{code:string, group:int, id:?string}
	 */
	private static function error( string $code, int $group, $id = null ): array {
		return array(
			'code'  => $code,
			'group' => $group,
			'id'    => null === $id ? null : (string) $id,
		);
	}

	/**
	 * The candle taking least room (volume), first seen on a tie.
	 *
	 * @param array $candles Candle arrays.
	 */
	private static function smallest( array $candles ): ?array {
		$best = null;
		$low  = PHP_INT_MAX;

		foreach ( $candles as $candle ) {
			$volume = self::units( $candle['length'] ?? 0 ) * self::units( $candle['width'] ?? 0 ) * self::units( $candle['height'] ?? 0 );

			if ( $volume > 0 && $volume < $low ) {
				$low  = $volume;
				$best = $candle;
			}
		}

		return $best;
	}

	/** @param mixed $cm */
	private static function units( $cm ): int {
		$cm = is_numeric( $cm ) ? (float) $cm : 0.0;
		return $cm > 0 ? (int) floor( $cm * 100 + 0.5 ) : 0;
	}

	/** @param mixed $price */
	private static function cents( $price ): int {
		$price = is_numeric( $price ) ? (float) $price : 0.0;
		return $price > 0 ? (int) floor( $price * 100 + 0.5 ) : 0;
	}
}

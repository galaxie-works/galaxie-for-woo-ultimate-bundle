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
	 * Shares any number of candles out over boxes, leaving loose only what no
	 * box can take at all.
	 *
	 * {@see GiftPacking::arrange()} is exact but made for a gift's dozen, and its
	 * search cap is per box. So: candles no box holds even alone are loose; up to
	 * MAX_ITEMS of the rest go to arrange() as they are; past that, boxes are
	 * filled one at a time — for each box, candles largest first join while
	 * fits() still says yes (exact per box, at most MAX_ITEMS in one), the box
	 * taking the most wins (then the cheaper, then the earlier) — until
	 * MAX_ITEMS or fewer are left, and those go to arrange(). Deterministic, and
	 * the same in the TypeScript twin.
	 *
	 * @param array $candles Candle arrays.
	 * @param array $boxes   Box arrays.
	 * @param array $options { gap, stacking, orientation }.
	 * @return array{gifts: array<int, array{box: array, candles: array}>, loose: array}
	 */
	public static function arrange_all( array $candles, array $boxes, array $options = array() ): array {
		$loose = array();
		$rest  = array();

		foreach ( array_values( $candles ) as $candle ) {
			$alone = false;

			foreach ( $boxes as $box ) {
				if ( GiftPacking::fits( $box, array( $candle ), $options ) ) {
					$alone = true;
					break;
				}
			}

			if ( $alone ) {
				$rest[] = $candle;
			} else {
				$loose[] = $candle;
			}
		}

		// Largest first, input order on a tie.
		$order = array_keys( $rest );
		usort(
			$order,
			static function ( int $p, int $q ) use ( $rest ): int {
				return ( self::volume( $rest[ $q ] ) <=> self::volume( $rest[ $p ] ) ) ?: ( $p <=> $q );
			}
		);

		$left  = array_map( static fn( int $i ): array => $rest[ $i ], $order );
		$gifts = array();

		while ( count( $left ) > GiftPacking::MAX_ITEMS ) {
			$best = null;

			foreach ( array_values( $boxes ) as $b => $box ) {
				$taken  = array();
				$chosen = array();

				foreach ( $left as $i => $candle ) {
					if ( count( $chosen ) >= GiftPacking::MAX_ITEMS ) {
						break;
					}

					$with   = $chosen;
					$with[] = $candle;

					if ( GiftPacking::fits( $box, $with, $options ) ) {
						$chosen  = $with;
						$taken[] = $i;
					}
				}

				if ( ! $taken ) {
					continue;
				}

				if ( null === $best || count( $taken ) > count( $best['taken'] ) || ( count( $taken ) === count( $best['taken'] ) && self::cents( $box['price'] ?? 0 ) < self::cents( $best['box']['price'] ?? 0 ) ) ) {
					$best = array(
						'box'     => $box,
						'taken'   => $taken,
						'candles' => $chosen,
					);
				}
			}

			foreach ( $best['taken'] as $i ) {
				unset( $left[ $i ] );
			}

			$gifts[] = array(
				'box'     => $best['box'],
				'candles' => $best['candles'],
			);
		}

		if ( $left ) {
			$gifts = array_merge( $gifts, GiftPacking::arrange( array_values( $left ), $boxes, $options ) );
		}

		return array(
			'gifts' => $gifts,
			'loose' => $loose,
		);
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
	 * Which card a gift gets from one card product, for its box.
	 *
	 * Cards come one size per box, told apart by an attribute the box has too
	 * ("Tamanho" = Quadrada / Grande). In catalogue order, among the product's
	 * cards in stock:
	 * - no box: the first;
	 * - a card with no attributes (a simple card): any box;
	 * - a card sharing attribute labels with the box: every shared value equal;
	 * - none sharing a label at all (the two products named the attribute
	 *   differently): the one card whose values include one of the box's, but
	 *   only when exactly one does.
	 * Values compare case-insensitively. 0: no card for this box.
	 *
	 * @param array      $cards  [ { id, parent, attrs: { label: value }, stock: int|null } ].
	 * @param int        $parent Card product id.
	 * @param array|null $box    The box's attributes, or null for no box.
	 */
	public static function card_for( array $cards, int $parent, ?array $box ): int {
		$lower      = static fn( array $attrs ): array => array_change_key_case( array_map( static fn( $v ): string => strtolower( (string) $v ), $attrs ), CASE_LOWER );
		$candidates = array();

		foreach ( $cards as $card ) {
			if ( (int) ( $card['parent'] ?? 0 ) === $parent && 0 !== ( $card['stock'] ?? null ) ) {
				$candidates[] = array(
					'id'    => (int) ( $card['id'] ?? 0 ),
					'attrs' => $lower( (array) ( $card['attrs'] ?? array() ) ),
				);
			}
		}

		if ( null === $box ) {
			return $candidates ? $candidates[0]['id'] : 0;
		}

		$box    = $lower( $box );
		$labels = false;

		foreach ( $candidates as $card ) {
			$shared = array_intersect_key( $card['attrs'], $box );

			if ( ! $card['attrs'] ) {
				return $card['id'];
			}

			if ( ! $shared ) {
				continue;
			}

			$labels = true;

			if ( array_intersect_assoc( $shared, $box ) === $shared ) {
				return $card['id'];
			}
		}

		if ( $labels || ! $box ) {
			return 0;
		}

		$values  = array_values( $box );
		$matches = array_values( array_filter( $candidates, static fn( array $card ): bool => (bool) array_intersect( array_values( $card['attrs'] ), $values ) ) );

		return 1 === count( $matches ) ? $matches[0]['id'] : 0;
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

	/** @param array $candle Candle array. */
	private static function volume( array $candle ): int {
		return self::units( $candle['length'] ?? 0 ) * self::units( $candle['width'] ?? 0 ) * self::units( $candle['height'] ?? 0 );
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

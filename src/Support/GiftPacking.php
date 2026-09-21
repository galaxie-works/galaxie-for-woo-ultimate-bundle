<?php
/**
 * Which candles fit in which gift box.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The gift box fitting engine.
 *
 * The math takes plain arrays and never calls WordPress, so the TypeScript twin
 * (`frontend/src/lib/gift-packing.ts`) can give the same answer in the builder
 * popup, and both are held to it by `tests/gift-packing/fixtures.json`. Change
 * one, change the other, run both tests.
 *
 *   candle  { size, length, width, height, price? }        cm, as WooCommerce stores them
 *   box     { id, length, width, height, max?, overflow?, price }
 *           internal cm; max 0 = no limit; overflow = extra height the lid still closes over (0–2)
 *   options { gap, orientation, stacking }                 gap in cm, default 0 (tissue paper fills); orientation default 'lying'; stacking default off
 *
 * The model:
 * - `orientation` says how a candle sits. 'upright': floor length × width,
 *   height used = height. 'lying' (default; the jar on its side): floor
 *   height × max(length, width), height used = max(length, width). 'any': each
 *   candle may take either, whichever lets the set fit (searched exactly).
 *   'faces' is for a box-shaped item (soap, a book, a boxed mug): any of its
 *   three faces may go down, L × W under H, L × H under W or W × H under L.
 *   The first three model a round item; a rectangular one needs 'faces'.
 *   Unknown values count as 'lying'.
 * - The gap is added to both floor sides and to the height used, which must be
 *   ≤ the box height. Neighbours are a full gap apart and every candle keeps
 *   half a gap from the walls. Footprints may turn 90° on the floor. One layer
 *   by default: a candle resting on another is not how one is packed. With
 *   `stacking` on, identical items may also stand in columns as tall as the
 *   box allows (stacks_fit()); a mixed stack is never counted, so the answer
 *   can fall short of what fits but never over it.
 * - The floor search is exact: it tries every "normal pattern" placement (x and
 *   y are sums of other footprints, which any packing can be pushed into), in
 *   bottom-left order so each layout is visited once. Failed states are
 *   remembered, and a conservative-scale bound proves most hopeless cases
 *   early. Past STEP_LIMIT units of work the first pass gives up; the footprints
 *   are then turned the other way round and the search runs again for
 *   RETRY_LIMIT more, because a shelf layout of elongated footprints is found at
 *   once that way and never the first. A search that still has not finished is
 *   `null` from `fits_known()` — "not known", never "does not fit": the caller
 *   must not refuse a shopper on it. Work is counted the same way in both
 *   languages, so both give up on the same case.
 * - Everything is compared in hundredths of a centimetre and prices in cents,
 *   so PHP floats and JS numbers cannot disagree.
 */
final class GiftPacking {

	/** Search work (states entered + corners tried) the first pass gets. */
	public const STEP_LIMIT = 300000;

	/** Work the second pass gets, with every footprint turned the other way round. */
	public const RETRY_LIMIT = 75000;

	/** Failed search states remembered per `fits()` call. */
	public const MEMO_LIMIT = 50000;

	/** Search steps after which the conservative-scale bound is tried once. */
	public const BOUND_AT = 1000;

	/** Largest group `arrange()` and `summary()` consider at once. */
	public const MAX_ITEMS = 12;

	/**
	 * Most ways of standing a kit's items in columns tried per question (one
	 * way per item kind, multiplied): a kit has a few kinds, each with up to
	 * three ways, so this is only reached by an unusual mix.
	 */
	public const STACK_COMBOS = 81;

	/** Transient holding the kit popup's "Leva até …" per box, flushed with the sizes. */
	public const HOLDS_TRANSIENT = 'galaxie_kit_holds';

	/** hrtime() past which fits() gives up; null for no clock (the default). */
	private static ?int $deadline = null;

	/** Whether a fits() gave up on the clock since the deadline was set. */
	private static bool $expired = false;

	/**
	 * Runs `$run` with a wall-clock limit on every fits() inside it. A fits()
	 * that runs out answers "does not fit", like the step limit, and the second
	 * value says whether any did — so the caller can tell a real "no" from an
	 * unknown one. Nested calls keep the earlier deadline when it is sooner.
	 *
	 * @param float    $ms  Milliseconds.
	 * @param callable $run Called with no arguments.
	 * @return array{0:mixed, 1:bool} [ what $run returned, whether the clock ran out ]
	 */
	public static function with_deadline( float $ms, callable $run ): array {
		$previous = self::$deadline;
		$was      = self::$expired;
		$mine     = hrtime( true ) + (int) round( max( 0.0, $ms ) * 1e6 );

		self::$deadline = null === $previous ? $mine : min( $previous, $mine );
		self::$expired  = false;

		try {
			$result  = $run();
			$expired = self::$expired;
		} finally {
			$inner          = self::$expired;
			self::$deadline = $previous;
			self::$expired  = $was || ( null !== $previous && $inner );
		}

		return array( $result, $expired );
	}

	/** Whether the running deadline has passed (and remembers it). */
	private static function late(): bool {
		if ( null === self::$deadline ) {
			return false;
		}

		if ( self::$expired || hrtime( true ) > self::$deadline ) {
			self::$expired = true;
			return true;
		}

		return false;
	}

	/**
	 * Whether all these candles go in the box together; an unfinished search
	 * reads as "no". Only for the places where "not known" and "no" may be
	 * answered alike — never where a shopper is refused; those ask
	 * {@see self::fits_known()}.
	 *
	 * @param array $box     Box array.
	 * @param array $candles List of candle arrays.
	 * @param array $options { gap }.
	 */
	public static function fits( array $box, array $candles, array $options = array() ): bool {
		return true === self::fits_known( $box, $candles, $options );
	}

	/**
	 * Whether all these candles go in the box together: true, false, or null
	 * when the search ran out of work or of clock before it could tell.
	 *
	 * Null is not a refusal. A box is only too small when the search said so
	 * with a layout ruled out or a bound proved, so a shopper is never turned
	 * away by a slow request.
	 *
	 * @param array $box     Box array.
	 * @param array $candles List of candle arrays.
	 * @param array $options { gap }.
	 */
	public static function fits_known( array $box, array $candles, array $options = array() ): ?bool {
		// Round against the fit: candles and gap up, the box down, so a converted
		// 5.004 cm candle never slips into a 5.00 cm box.
		$gap = self::up( $options['gap'] ?? 0 );
		$bx  = self::down( $box['length'] ?? 0 );
		$by  = self::down( $box['width'] ?? 0 );
		$bz  = self::down( $box['height'] ?? 0 );
		$max = max( 0, (int) ( $box['max'] ?? 0 ) );
		$n   = count( $candles );

		if ( 0 === $n ) {
			return true;
		}

		if ( ( $max > 0 && $n > $max ) || $bx <= 0 || $by <= 0 || $bz <= 0 ) {
			return false;
		}

		if ( self::late() ) {
			return null;
		}

		// Candles may stand a little proud of the base when the lid still closes
		// over them: usable height = height + overflow (the gap still applies).
		$bz += self::down( $box['overflow'] ?? 0 );

		$orientation = self::orientation_of( $options );
		$pieces      = array();

		foreach ( $candles as $candle ) {
			$shapes = self::shapes_of( $candle, $orientation, $gap, $bx, $by, $bz );

			if ( ! $shapes ) {
				return false;
			}

			$pieces[] = $shapes;
		}

		$answer = self::floor_fits( $pieces, $bx, $by );

		if ( true === $answer || empty( $options['stacking'] ) ) {
			return $answer;
		}

		// Stacking on: a yes from the columns is a yes. Their no is the floor's
		// answer, which is exact for one layer; a mixed stack (a small item on
		// a big one) is not modelled, so with stacking a "no" can be short.
		return self::stacks_fit( $candles, $orientation, $gap, $bx, $by, $bz ) ? true : $answer;
	}

	/**
	 * The exact one-layer search: whether these footprints share the floor.
	 *
	 * @param array<int, array<int, int[]>> $pieces Each piece's possible footprints, from shapes_of().
	 * @param int                          $bx     Box length in units.
	 * @param int                          $by     Box width in units.
	 * @return bool|null Null when the search ran out of work or time.
	 */
	private static function floor_fits( array $pieces, int $bx, int $by ): ?bool {
		$n = count( $pieces );

		// Pieces with the same possible footprints are one type with a count:
		// the search then never tries swapping two of them.
		$types = array();
		$area  = 0;

		foreach ( $pieces as $shapes ) {
			$key = implode( ';', array_map( static fn( array $s ): string => $s[0] . 'x' . $s[1], $shapes ) );

			if ( ! isset( $types[ $key ] ) ) {
				$types[ $key ] = array(
					'shapes' => $shapes,
					'area'   => min( array_map( static fn( array $s ): int => $s[0] * $s[1], $shapes ) ),
					'key'    => $key,
					'count'  => 0,
					'total'  => 0,
				);
			}

			++$types[ $key ]['count'];
			++$types[ $key ]['total'];
			$area += $types[ $key ]['area'];
		}

		if ( $area > $bx * $by ) {
			return false;
		}

		// Largest footprint first: it is the one with fewest places to go.
		$types = array_values( $types );
		usort(
			$types,
			static function ( array $p, array $q ): int {
				return ( $q['area'] <=> $p['area'] ) ?: ( $p['shapes'][0][0] <=> $q['shapes'][0][0] ) ?: self::by_string( $p['key'], $q['key'] );
			}
		);

		// A pushed-into-the-corner layout never reaches past the largest sum of
		// sides that fits, so the box can shrink to it.
		$xs = self::normal( $types, $bx );
		$ys = self::normal( $types, $by );
		$bx = (int) end( $xs );
		$by = (int) end( $ys );

		if ( $area > $bx * $by ) {
			return false;
		}

		$search = array(
			'bx'     => $bx,
			'by'     => $by,
			'types'  => $types,
			'xs'     => self::corners( $xs, $types, $bx ),
			'ys'     => self::corners( $ys, $types, $by ),
			'placed' => array(),
			'nodes'  => 0,
			'steps'  => 0,
			'dead'   => array(),
			'stop'   => false,
			'proved' => false,
			'clock'  => 0,
			'cap'    => self::STEP_LIMIT,
			'wide'   => false,
		);

		if ( self::place( $search, -1, $n, $area ) ) {
			return true;
		}

		if ( ! $search['stop'] || $search['proved'] ) {
			return false;
		}

		// The first pass lays every footprint short side along the box's length;
		// a shelf of elongated footprints wants the long side there instead, and
		// the search walks past it for hundreds of thousands of steps. Turning
		// them round costs nothing when the first pass answered, and answers the
		// cases it could not: of 1,285 measured queries it settles eight the
		// first pass gave up on, in 85 steps for the worst of them.
		if ( self::late() ) {
			return null;
		}

		$search['placed'] = array();
		$search['dead']   = array();
		$search['nodes']  = 0;
		$search['steps']  = 0;
		$search['clock']  = 0;
		$search['stop']   = false;
		$search['cap']    = self::RETRY_LIMIT;
		$search['wide']   = true;

		if ( self::place( $search, -1, $n, $area ) ) {
			return true;
		}

		return ( $search['stop'] && ! $search['proved'] ) ? null : false;
	}

	/**
	 * Shares the candles out over as few boxes as possible, then the cheapest.
	 *
	 * Any box can be used more than once. Returns `[ [ 'box' => box, 'candles' =>
	 * [...] ], ... ]`, or an empty list when some candle fits in no box at all.
	 * Exact over every way to split the candles (fine for a gift's dozen; the
	 * work grows with the product of (count + 1) per candle type). Ties go to the
	 * earlier box in `$boxes`, then to a fixed split order that both languages
	 * share; candles keep their input order inside each type.
	 *
	 * @param array $candles List of candle arrays.
	 * @param array $boxes   List of box arrays.
	 * @param array $options { gap, orientation }.
	 */
	public static function arrange( array $candles, array $boxes, array $options = array() ): array {
		if ( ! $candles || ! $boxes ) {
			return array();
		}

		// Candle types in first-seen order; a group is a count per type.
		$types = array();
		$pool  = array();
		foreach ( $candles as $candle ) {
			$key = self::candle_key( $candle );

			if ( ! isset( $pool[ $key ] ) ) {
				$types[]      = $candle;
				$pool[ $key ] = array();
			}

			$pool[ $key ][] = $candle;
		}

		$keys   = array_keys( $pool );
		$counts = array_map( 'count', array_values( $pool ) );
		$k      = count( $counts );

		// Mixed-radix index of every count vector 0..counts.
		$radix = array();
		$total = 1;
		foreach ( $counts as $i => $c ) {
			$radix[ $i ] = $total;
			$total      *= $c + 1;
		}

		$decode = static function ( int $index ) use ( $counts, $k ): array {
			$v = array();
			for ( $i = 0; $i < $k; $i++ ) {
				$v[ $i ]  = $index % ( $counts[ $i ] + 1 );
				$index    = intdiv( $index, $counts[ $i ] + 1 );
			}
			return $v;
		};

		// Cheapest single box for each group (null = none holds it).
		$single = array();
		for ( $u = 1; $u < $total; $u++ ) {
			$v     = $decode( $u );
			$group = array();
			foreach ( $v as $i => $c ) {
				for ( $j = 0; $j < $c; $j++ ) {
					$group[] = $types[ $i ];
				}
			}

			$single[ $u ] = null;
			foreach ( $boxes as $b => $box ) {
				if ( null !== $single[ $u ] && self::cents( $boxes[ $single[ $u ] ]['price'] ?? 0 ) <= self::cents( $box['price'] ?? 0 ) ) {
					continue;
				}

				if ( self::fits( $box, $group, $options ) ) {
					$single[ $u ] = $b;
				}
			}
		}

		// best[v] = [ boxes, cents, first group, box index ] over all ways to split v.
		$best = array( 0 => array( 0, 0, 0, -1 ) );
		for ( $v = 1; $v < $total; $v++ ) {
			$vec = $decode( $v );

			// The first type still present must be in the first group: splits
			// that only differ in group order are then tried once.
			$lead = 0;
			while ( 0 === $vec[ $lead ] ) {
				++$lead;
			}

			$best[ $v ] = null;
			for ( $u = $v; $u >= 1; $u-- ) {
				$sub = $decode( $u );
				$ok  = $sub[ $lead ] > 0;

				for ( $i = 0; $ok && $i < $k; $i++ ) {
					$ok = $sub[ $i ] <= $vec[ $i ];
				}

				if ( ! $ok || null === $single[ $u ] || null === $best[ $v - $u ] ) {
					continue;
				}

				$rest  = $best[ $v - $u ];
				$count = $rest[0] + 1;
				$cents = $rest[1] + self::cents( $boxes[ $single[ $u ] ]['price'] ?? 0 );

				if ( null === $best[ $v ] || $count < $best[ $v ][0] || ( $count === $best[ $v ][0] && $cents < $best[ $v ][1] ) ) {
					$best[ $v ] = array( $count, $cents, $u, $single[ $u ] );
				}
			}
		}

		if ( null === $best[ $total - 1 ] ) {
			return array();
		}

		$gifts = array();
		$v     = $total - 1;
		$taken = array_fill( 0, $k, 0 );
		while ( $v > 0 ) {
			$step  = $best[ $v ];
			$group = array();

			foreach ( $decode( $step[2] ) as $i => $c ) {
				for ( $j = 0; $j < $c; $j++ ) {
					$group[] = $pool[ $keys[ $i ] ][ $taken[ $i ]++ ];
				}
			}

			$gifts[] = array(
				'box'     => $boxes[ $step[3] ],
				'candles' => $group,
			);
			$v      -= $step[2];
		}

		return $gifts;
	}

	/**
	 * The sizes that still fit if one more is added: "cabe mais 1 × 50g".
	 *
	 * @param array $box     Box array.
	 * @param array $candles Candles already in the box.
	 * @param array $sizes   One candle per size to try; defaults to the sizes already in the box.
	 * @param array $options { gap, orientation }.
	 * @return string[] Size keys, in the order given.
	 */
	public static function room( array $box, array $candles, array $sizes = array(), array $options = array() ): array {
		$sizes = $sizes ? $sizes : self::distinct( $candles );
		$fit   = array();

		foreach ( self::distinct( $sizes ) as $size ) {
			$with   = $candles;
			$with[] = $size;

			if ( self::fits( $box, $with, $options ) ) {
				$fit[] = (string) $size['size'];
			}
		}

		return array_values( array_unique( $fit ) );
	}

	/**
	 * Representative fits for a box: the most of each size alone, then every
	 * mix that cannot take one more of anything.
	 *
	 * For a 14 × 11 × 9.5 box and the Eir sizes that is
	 * `[ {counts: {50g: 4}}, {counts: {190g: 1}}, {counts: {50g: 2, 190g: 1}} ]`
	 * (each with `capped` false).
	 *
	 * The search stops at MAX_ITEMS candles, so a row that reaches it is
	 * `capped`: the box may hold more, 12 is not its capacity. A box `max` at or
	 * below MAX_ITEMS is a real limit and never caps.
	 *
	 * @param array $box     Box array.
	 * @param array $sizes   One candle per size.
	 * @param array $options { gap, orientation }.
	 * @return array<int, array{counts: array<string, int>, capped: bool}> Sizes in the order given.
	 */
	public static function summary( array $box, array $sizes, array $options = array() ): array {
		$sizes = self::distinct( $sizes );
		$k     = count( $sizes );
		$fits  = array();

		$check = static function ( array $v ) use ( $box, $sizes, $options, &$fits ): bool {
			$key = implode( ',', $v );

			if ( ! isset( $fits[ $key ] ) ) {
				$group = array();
				foreach ( $v as $i => $c ) {
					for ( $j = 0; $j < $c; $j++ ) {
						$group[] = $sizes[ $i ];
					}
				}
				$fits[ $key ] = array_sum( $v ) <= self::MAX_ITEMS && self::fits( $box, $group, $options );
			}

			return $fits[ $key ];
		};

		$pure  = array();
		$mixes = array();

		// Every vector that fits, smallest first; fitting is monotone (take a
		// candle out and it still fits), so each is grown from one that did.
		$queue = array( array_fill( 0, $k, 0 ) );
		$seen  = array( implode( ',', $queue[0] ) => true );
		for ( $q = 0; $q < count( $queue ); $q++ ) {
			$v       = $queue[ $q ];
			$maximal = array_sum( $v ) > 0;

			for ( $i = 0; $i < $k; $i++ ) {
				$next = $v;
				++$next[ $i ];

				if ( ! $check( $next ) ) {
					continue;
				}

				$maximal = false;
				$key     = implode( ',', $next );

				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$queue[]      = $next;
				}
			}

			$used = count( array_filter( $v ) );

			if ( 1 === $used ) {
				// Most of one size alone: nothing more of that size fits.
				$i    = (int) array_key_first( array_filter( $v ) );
				$next = $v;
				++$next[ $i ];

				if ( ! $check( $next ) ) {
					$pure[ $i ] = $v;
				}
			} elseif ( $used > 1 && $maximal ) {
				$mixes[] = $v;
			}
		}

		ksort( $pure );

		$max   = max( 0, (int) ( $box['max'] ?? 0 ) );
		$named = array();
		foreach ( array_merge( array_values( $pure ), $mixes ) as $v ) {
			$row = array();
			foreach ( $v as $i => $c ) {
				if ( $c > 0 ) {
					$row[ (string) $sizes[ $i ]['size'] ] = $c;
				}
			}
			$named[] = array(
				'counts' => $row,
				'capped' => array_sum( $v ) >= self::MAX_ITEMS && ( 0 === $max || $max > self::MAX_ITEMS ),
			);
		}

		return $named;
	}

	/**
	 * One candle per size, the smallest of it the store actually sells: least
	 * volume, the first seen on a tie — the rule {@see GiftGroups::smallest()}
	 * follows.
	 *
	 * This used to be a covering shape: the longest long side, the longest short
	 * side and the tallest height taken from whichever variation had each. That
	 * shape is a jar nobody sells, and one variation never measured for gifts —
	 * whose dimensions are then the jar inside its shipping box — widened its
	 * whole size until the size disappeared from "ainda cabem …" although adding
	 * it still worked, because `add_candle()` packs the exact variation the
	 * shopper picked. The two paths have to answer about the same jar. They
	 * answer about a real one now: the sentence says a size still goes in when at
	 * least one variation of it does, the shopper gets the variation they picked,
	 * and the add has the last word on which.
	 *
	 * @param array $candles Candle arrays, sizes repeated freely.
	 * @return array<int, array> Sizes in first-seen order.
	 */
	public static function offered( array $candles ): array {
		$out = array();

		foreach ( $candles as $candle ) {
			$key = (string) ( $candle['size'] ?? '' );

			if ( ! isset( $out[ $key ] ) || self::bulk( $candle ) < self::bulk( $out[ $key ] ) ) {
				$out[ $key ] = $candle;
			}
		}

		return array_values( $out );
	}

	/** Gift dimension meta keys, on variations and on size terms alike. */
	private const GIFT_KEYS = array( '_galaxie_gift_length', '_galaxie_gift_width', '_galaxie_gift_height' );

	/**
	 * Size term gift dimensions already read in this request, by attribute|size.
	 *
	 * @var array<string, float[]>
	 */
	private static array $terms = array();

	/**
	 * A candle array from a WooCommerce variation (or simple product), with
	 * dimensions in cm whatever unit the store uses.
	 *
	 * Which dimensions, most specific first, each set only when all three are:
	 * the variation's own gift dimensions (the jar alone, lid on, in cm), then
	 * its size term's (`term_dimensions()`), then WooCommerce's, which are the
	 * jar in its shipping box and stay for freight.
	 *
	 * @param \WC_Product $product   Candle variation.
	 * @param string      $attribute Size attribute, e.g. `pa_peso`.
	 */
	public static function candle_from_product( \WC_Product $product, string $attribute = 'pa_peso' ): ?array {
		$size = '';

		if ( $product->is_type( 'variation' ) ) {
			$size = (string) ( $product->get_attributes()[ $attribute ] ?? '' );
		}

		if ( '' === $size ) {
			$size = (string) $product->get_attribute( $attribute );
		}

		$gift = array_map( static fn( string $key ): float => (float) $product->get_meta( $key ), self::GIFT_KEYS );

		// No value for the size attribute: a store that does not size its goods
		// by it (simple products, variations by colour). Such a product is its
		// own size, and it counts only once the merchant has filled its gift
		// measures: the shipping dimensions every product carries are no sign
		// that it belongs in a gift, and a box or a card would pass that test.
		if ( '' === $size ) {
			return min( $gift ) > 0 ? array(
				'size'   => self::item_size( $product ),
				'length' => $gift[0],
				'width'  => $gift[1],
				'height' => $gift[2],
				'price'  => (float) $product->get_price(),
			) : null;
		}

		if ( min( $gift ) <= 0 ) {
			$gift = self::term_dimensions( $attribute, $size );
		}

		$candle = min( $gift ) > 0 ? array(
			'size'   => $size,
			'length' => $gift[0],
			'width'  => $gift[1],
			'height' => $gift[2],
			'price'  => (float) $product->get_price(),
		) : array(
			'size'   => $size,
			'length' => self::cm( $product->get_length() ),
			'width'  => self::cm( $product->get_width() ),
			'height' => self::cm( $product->get_height() ),
			'price'  => (float) $product->get_price(),
		);

		return ( '' !== $size && $candle['length'] > 0 && $candle['width'] > 0 && $candle['height'] > 0 ) ? $candle : null;
	}

	/**
	 * The size key of a product that has no size attribute value: itself.
	 * Prefixed so it can never meet a size term's slug.
	 *
	 * @param \WC_Product $product Simple product or variation.
	 */
	public static function item_size( \WC_Product $product ): string {
		return self::ITEM_PREFIX . $product->get_id();
	}

	/** What starts an {@see self::item_size()} key. */
	public const ITEM_PREFIX = 'item-';

	/**
	 * A box array from a gift box variation's `_galaxie_box_*` meta.
	 *
	 * @param \WC_Product $product Box variation.
	 */
	public static function box_from_product( \WC_Product $product ): ?array {
		$box = array(
			'id'     => $product->get_id(),
			'length' => (float) $product->get_meta( '_galaxie_box_length' ),
			'width'  => (float) $product->get_meta( '_galaxie_box_width' ),
			'height' => (float) $product->get_meta( '_galaxie_box_height' ),
			'max'      => absint( $product->get_meta( '_galaxie_box_max' ) ),
			'overflow' => min( 2.0, max( 0.0, (float) $product->get_meta( '_galaxie_box_overflow' ) ) ),
			'price'    => (float) $product->get_price(),
		);

		return ( $box['length'] > 0 && $box['width'] > 0 && $box['height'] > 0 ) ? $box : null;
	}

	/** Transient holding `store_sizes()` results, by attribute. */
	public const SIZES_TRANSIENT = 'galaxie_gift_sizes';

	/**
	 * `store_sizes()` results already read in this request, by attribute.
	 *
	 * @var array<string, array>
	 */
	private static array $sizes = array();

	/**
	 * One candle per term of the size attribute, as the store sells them.
	 *
	 * Every variation of each term whose parent product is published is read, and
	 * when they disagree on dimensions the smallest of them stands for the size
	 * ({@see self::offered()}), because that is a jar the store really sells and
	 * the add packs the exact variation anyway. Drafts and trashed products do
	 * not count. Term order as WooCommerce sorts the attribute.
	 *
	 * Each candle's dimensions resolve as in `candle_from_product()`: variation
	 * gift dimensions, then its size term's, then WooCommerce's.
	 *
	 * Cached in the SIZES_TRANSIENT transient (a day at most); `watch_sizes()`
	 * clears it whenever a product or variation is saved, deleted, trashed or
	 * changes status, and when a size term or its gift dimensions change.
	 *
	 * @param string $attribute Size attribute, e.g. `pa_peso`.
	 * @return array<int, array> Candle arrays plus `label` (the term name).
	 */
	public static function store_sizes( string $attribute = 'pa_peso' ): array {
		if ( isset( self::$sizes[ $attribute ] ) ) {
			return self::$sizes[ $attribute ];
		}

		$stored = get_transient( self::SIZES_TRANSIENT );
		$stored = is_array( $stored ) ? $stored : array();

		// An empty entry is not an answer to keep: it means the store looked like
		// it had no candle size at all, which is worth asking again rather than
		// serving for a day. (One written before this rule is dropped here.)
		if ( ! empty( $stored[ $attribute ] ) && is_array( $stored[ $attribute ] ) ) {
			return self::$sizes[ $attribute ] = $stored[ $attribute ];
		}

		$terms = taxonomy_exists( $attribute ) ? get_terms(
			array(
				'taxonomy'   => $attribute,
				'hide_empty' => false,
			)
		) : array();
		$sizes = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$rows = get_posts(
				array(
					'post_type'      => 'product_variation',
					'post_status'    => array( 'publish', 'private' ),
					'posts_per_page' => -1, // IDs only; a size missed here could break a fit.
					'fields'         => 'id=>parent',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => 'attribute_' . $attribute,
							'value' => $term->slug,
						),
					),
				)
			);

			// `fields => 'id=>parent'` hands back [ variation id => parent id ],
			// not rows: read as objects, every variation was thrown away here and
			// the store ended up with no size at all.
			//
			// Only variations of published products: a draft or trashed candle
			// must not widen the shape of its size.
			$parents   = array_values( array_unique( array_map( 'intval', (array) $rows ) ) );
			$published = $parents ? array_flip(
				get_posts(
					array(
						'post_type'      => 'product',
						'post_status'    => 'publish',
						'post__in'       => $parents,
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				)
			) : array();

			$found = array();
			foreach ( (array) $rows as $variation => $parent ) {
				if ( ! isset( $published[ (int) $parent ] ) ) {
					continue;
				}

				$product = wc_get_product( (int) $variation );
				$candle  = $product ? self::candle_from_product( $product, $attribute ) : null;

				if ( $candle ) {
					$candle['size'] = $term->slug;
					$found[]        = $candle;
				}
			}

			if ( $found ) {
				$size = self::offered( $found )[0];
				unset( $size['price'] );
				$size['label'] = $term->name;
				$sizes[]       = $size;
			}
		}

		// Products that are their own size (no size attribute value, gift
		// measures filled): one size each, named after the product.
		$items = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::GIFT_KEYS[0],
						'value'   => 0,
						'compare' => '>',
						'type'    => 'DECIMAL(10,3)',
					),
				),
			)
		);

		foreach ( (array) $items as $id ) {
			$product = wc_get_product( (int) $id );

			if ( ! $product || $product->is_type( 'variable' ) ) {
				continue;
			}

			$parent = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

			if ( 'publish' !== get_post_status( $parent ) ) {
				continue;
			}

			$item = self::candle_from_product( $product, $attribute );

			if ( $item && str_starts_with( $item['size'], self::ITEM_PREFIX ) ) {
				unset( $item['price'] );
				$item['label'] = wp_strip_all_tags( $product->get_name() );
				$sizes[]       = $item;
			}
		}

		// An empty answer is not cached for a day: no size at all means the
		// store cannot fit a candle in any box — every "cabem …" sentence turns
		// into "a caixa está completa" — and that is a state to re-check on the
		// next request, not to keep. It stays in the per-request cache only.
		if ( $sizes ) {
			$stored[ $attribute ] = $sizes;
		} else {
			unset( $stored[ $attribute ] );
		}

		set_transient( self::SIZES_TRANSIENT, $stored, DAY_IN_SECONDS );

		return self::$sizes[ $attribute ] = $sizes;
	}

	/**
	 * Why a store has no candle size, counted step by step: the taxonomy, its
	 * terms, the variations each term matches, the ones whose product is
	 * published, and the ones that come back with usable dimensions.
	 *
	 * Counts only — no names, no ids. It answers the one question the sizes
	 * cannot: whether it is the attribute, the terms, the variations or the
	 * dimensions that are missing.
	 *
	 * @return array<string,int|bool|string>
	 */
	public static function size_report( string $attribute = 'pa_peso' ): array {
		$report = array(
			'attribute' => $attribute,
			'taxonomy'  => taxonomy_exists( $attribute ),
			'terms'     => 0,
			'matched'   => 0,
			'published' => 0,
			'measured'  => 0,
			'sizes'     => count( self::store_sizes( $attribute ) ),
		);

		if ( ! $report['taxonomy'] ) {
			return $report;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $attribute,
				'hide_empty' => false,
			)
		);

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			++$report['terms'];

			$rows = get_posts(
				array(
					'post_type'      => 'product_variation',
					'post_status'    => array( 'publish', 'private' ),
					'posts_per_page' => 20,
					'fields'         => 'id=>parent',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => 'attribute_' . $attribute,
							'value' => $term->slug,
						),
					),
				)
			);

			$report['matched'] += count( (array) $rows );

			foreach ( (array) $rows as $variation => $parent ) {
				if ( 'publish' !== get_post_status( (int) $parent ) ) {
					continue;
				}

				++$report['published'];
				$product = wc_get_product( (int) $variation );

				if ( $product && self::candle_from_product( $product, $attribute ) ) {
					++$report['measured'];
				}
			}
		}

		return $report;
	}

	/**
	 * A size term's gift dimensions, [ length, width, height ] in cm; zeros when
	 * the term is missing or has none. The term is the one whose slug (a
	 * variation's attribute value) or name (a simple product's) is `$size`.
	 *
	 * Read once per request; `flush_sizes()` forgets them.
	 *
	 * @param string $attribute Size attribute taxonomy, e.g. `pa_peso`.
	 * @param string $size      Slug or name.
	 * @return float[]
	 */
	public static function term_dimensions( string $attribute, string $size ): array {
		$key = $attribute . '|' . $size;

		if ( isset( self::$terms[ $key ] ) ) {
			return self::$terms[ $key ];
		}

		$dims = array( 0.0, 0.0, 0.0 );

		if ( '' !== $attribute && '' !== $size && function_exists( 'get_term_by' ) ) {
			$term = get_term_by( 'slug', $size, $attribute );
			$term = $term ? $term : get_term_by( 'name', $size, $attribute );

			if ( is_object( $term ) && isset( $term->term_id ) ) {
				$dims = array_map( static fn( string $meta ): float => (float) get_term_meta( (int) $term->term_id, $meta, true ), self::GIFT_KEYS );
			}
		}

		return self::$terms[ $key ] = $dims;
	}

	/**
	 * Forgets the cached store sizes and size term dimensions, in this request
	 * and in the transient.
	 */
	public static function flush_sizes(): void {
		self::$sizes = array();
		self::$terms = array();
		delete_transient( self::SIZES_TRANSIENT );
		delete_transient( self::HOLDS_TRANSIENT );
	}

	/**
	 * Clears the store sizes whenever a product or variation is created, saved,
	 * deleted, trashed, restored or changes status, and when an attribute term is
	 * renamed. Safe to call more than once; BoxFields and CandleFields call it
	 * from `register()`, so it runs wherever the Gift Wrap module boots.
	 */
	public static function watch_sizes(): void {
		static $watching = false;

		if ( $watching ) {
			return;
		}

		$watching = true;
		$flush    = array( self::class, 'flush_sizes' );

		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation', 'woocommerce_save_product_variation', 'woocommerce_delete_product_variation', 'woocommerce_trash_product_variation' ) as $hook ) {
			add_action( $hook, $flush, 10, 0 );
		}

		$by_id = static function ( $post_id ): void {
			if ( in_array( get_post_type( (int) $post_id ), array( 'product', 'product_variation' ), true ) ) {
				self::flush_sizes();
			}
		};

		// before_delete_post still knows the post type; deleted_post runs after.
		foreach ( array( 'before_delete_post', 'deleted_post', 'trashed_post', 'untrashed_post' ) as $hook ) {
			add_action( $hook, $by_id, 10, 1 );
		}

		add_action(
			'transition_post_status',
			static function ( $new_status, $old_status, $post ): void {
				if ( $new_status !== $old_status && $post instanceof \WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
					self::flush_sizes();
				}
			},
			10,
			3
		);

		$by_taxonomy = static function ( $term_id, $tt_id, $taxonomy ): void {
			if ( str_starts_with( (string) $taxonomy, 'pa_' ) ) {
				self::flush_sizes();
			}
		};

		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, $by_taxonomy, 10, 3 );
		}

		// A size term's gift dimensions, however they changed (the term form,
		// `wp/v2`, WP-CLI): the sizes worked out from them are stale.
		$by_term_meta = static function ( $meta_id, $term_id, $meta_key ): void {
			if ( in_array( (string) $meta_key, self::GIFT_KEYS, true ) ) {
				self::flush_sizes();
			}
		};

		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, $by_term_meta, 10, 3 );
		}
	}

	/**
	 * Depth-first placement in bottom-left order: the next candle's corner comes
	 * after the last one's (row, then column), so a layout is found at most once.
	 *
	 * @param array $s     Search state.
	 * @param int   $last  Index of the last corner used.
	 * @param int   $left  Candles still to place.
	 * @param int   $area  Their footprint area.
	 */
	private static function place( array &$s, int $last, int $left, int $area ): bool {
		if ( 0 === $left ) {
			return true;
		}

		if ( $s['stop'] ) {
			return false;
		}

		++$s['nodes'];

		if ( ++$s['steps'] > $s['cap'] ) {
			$s['stop'] = true;
			return false;
		}

		// Long search: check once whether the candles can fit at all.
		if ( self::BOUND_AT === $s['nodes'] && self::bound( $s ) ) {
			$s['stop']   = true;
			$s['proved'] = true;
			return false;
		}

		$cols  = count( $s['xs'] );
		$cells = $cols * count( $s['ys'] );

		// Corners inside a placed candle can take nothing: start at the first free one.
		$first = $last + 1;
		for ( ; $first < $cells; $first++ ) {
			++$s['steps'];
			if ( ! self::covered( $s['placed'], $s['xs'][ $first % $cols ], $s['ys'][ intdiv( $first, $cols ) ] ) ) {
				break;
			}
		}

		if ( $first >= $cells ) {
			return false;
		}

		// What is left to decide depends only on that corner, the candles still
		// to place and the tops of the ones that reach its row — not on how the
		// rows below were filled. A state that failed once fails again.
		$floor = $s['ys'][ intdiv( $first, $cols ) ];
		$tops  = array();
		foreach ( $s['placed'] as $r ) {
			if ( $r[1] + $r[3] > $floor ) {
				$tops[] = $r[0] . ':' . $r[2] . ':' . ( $r[1] + $r[3] );
			}
		}
		sort( $tops, SORT_STRING );

		$key = $first . '|' . implode( ',', array_column( $s['types'], 'count' ) ) . '|' . implode( ',', $tops );

		if ( isset( $s['dead'][ $key ] ) ) {
			return false;
		}

		// The state's own copies: the corner loop reads them thousands of times
		// and `$s` is a reference, which PHP cannot keep in a register.
		$placed = $s['placed'];
		$xs     = $s['xs'];
		$ys     = $s['ys'];
		$bx     = $s['bx'];
		$by     = $s['by'];
		$cap    = $s['cap'];
		$steps  = $s['steps'];
		$clock  = $s['clock'];

		// Set once per row of corners (see the free-floor bound below).
		$at    = -1;
		$y     = 0;
		$top   = 0;
		$deep  = 0;
		$above = 0;
		$band  = array();

		for ( $idx = $first; $idx < $cells; $idx++ ) {
			// Work, not just states, is what the limit counts: a fine grid of
			// corners makes each state expensive.
			if ( ++$steps > $cap ) {
				$s['steps'] = $steps;
				$s['clock'] = $clock;
				$s['stop']  = true;
				return false;
			}

			// The clock, every 1024 corners, when with_deadline() set one.
			if ( null !== self::$deadline && 0 === ( ++$clock & 1023 ) && self::late() ) {
				$s['steps'] = $steps;
				$s['clock'] = $clock;
				$s['stop']  = true;
				return false;
			}

			$row = intdiv( $idx, $cols );

			// Everything left sits at or above this row, and not left of this
			// corner within it. If that much floor, less what is taken, is smaller
			// than the candles left, no later corner helps either. Only the part
			// left of the corner moves along a row, so the rest is worked out once
			// when the row changes.
			if ( $row !== $at ) {
				$at    = $row;
				$y     = $ys[ $row ];
				$top   = $ys[ $row + 1 ] ?? $by;
				$deep  = $top - $y;
				$above = $bx * ( $by - $y );
				$band  = array();

				foreach ( $placed as $r ) {
					$base = $r[1] > $y ? $r[1] : $y;
					$rise = $r[1] + $r[3];

					if ( $rise > $base ) {
						$above -= $r[2] * ( $rise - $base );
					}

					$cut = $rise < $top ? $rise : $top;

					if ( $cut > $base ) {
						$band[] = array( $r[0], $r[0] + $r[2], $cut - $base );
					}
				}
			}

			$x    = $xs[ $idx % $cols ];
			$free = $above - $x * $deep;

			foreach ( $band as $r ) {
				if ( $r[0] < $x ) {
					$free += ( ( $r[1] < $x ? $r[1] : $x ) - $r[0] ) * $r[2];
				}
			}

			if ( $area > $free ) {
				break;
			}

			foreach ( $s['types'] as $t => $type ) {
				if ( 0 === $type['count'] ) {
					continue;
				}

				foreach ( $type['shapes'] as $shape ) {
					if ( $shape[0] === $shape[1] ) {
						$turns = array( array( $shape[0], $shape[1] ) );
					} elseif ( $s['wide'] ) {
						$turns = array( array( $shape[1], $shape[0] ), array( $shape[0], $shape[1] ) );
					} else {
						$turns = array( array( $shape[0], $shape[1] ), array( $shape[1], $shape[0] ) );
					}

					foreach ( $turns as $turn ) {
						list( $w, $d ) = $turn;

						if ( $x + $w > $s['bx'] || $y + $d > $s['by'] ) {
							continue;
						}

						$clear = true;
						foreach ( $placed as $r ) {
							if ( $x < $r[0] + $r[2] && $r[0] < $x + $w && $y < $r[1] + $r[3] && $r[1] < $y + $d ) {
								$clear = false;
								break;
							}
						}

						if ( ! $clear ) {
							continue;
						}

						$s['steps']    = $steps;
						$s['clock']    = $clock;
						$s['placed'][] = array( $x, $y, $w, $d );
						--$s['types'][ $t ]['count'];

						// `$area` counts each candle left at its smallest shape: a bound, not a sum of placements.
						$done = self::place( $s, $idx, $left - 1, $area - $type['area'] );

						++$s['types'][ $t ]['count'];
						array_pop( $s['placed'] );

						if ( $done ) {
							return true;
						}

						if ( $s['stop'] ) {
							return false;
						}

						$steps = $s['steps'];
						$clock = $s['clock'];
					}
				}
			}
		}

		$s['steps'] = $steps;
		$s['clock'] = $clock;

		if ( ! $s['stop'] && count( $s['dead'] ) < self::MEMO_LIMIT ) {
			$s['dead'][ $key ] = true;
		}

		return false;
	}

	/**
	 * Whether a corner lies inside a placed candle.
	 *
	 * @param array $placed [ x, y, w, d ] rectangles.
	 * @param int   $x      Corner x.
	 * @param int   $y      Corner y.
	 */
	private static function covered( array $placed, int $x, int $y ): bool {
		foreach ( $placed as $r ) {
			if ( $r[0] <= $x && $x < $r[0] + $r[2] && $r[1] <= $y && $y < $r[1] + $r[3] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Proves "does not fit" for layouts the area alone lets through, e.g. nine
	 * 190g and a 50g in 30 × 30: along a 30 cm line at most three 190g fit, or
	 * two 190g and two 50g... so a 190g is worth ⅓ of a side and a 50g ⅙, and
	 * 9 × ⅓ × ⅓ + ⅙ × ⅙ > 1 box floor.
	 *
	 * Those worths are conservative scales (Fekete & Schepers): small integer
	 * weights per side length, divided by the most weight any line of candles
	 * across the box can carry. Tried for every weight combination, per axis,
	 * when there are at most three distinct side lengths. Integer arithmetic.
	 *
	 * @param array $s Search state (uses the original types and box sides).
	 * @return bool True when the candles certainly do not fit.
	 */
	private static function bound( array $s ): bool {
		// How many candles could show each side length along a line.
		$sides = array();
		foreach ( $s['types'] as $type ) {
			foreach ( self::sides_of( $type ) as $side ) {
				$sides[ $side ] = ( $sides[ $side ] ?? 0 ) + $type['total'];
			}
		}
		ksort( $sides );

		$m = count( $sides );

		if ( $m > 3 ) {
			return false;
		}

		$lengths = array_keys( $sides );
		$avail   = array_values( $sides );
		$top     = array( 1 => 1, 2 => 6, 3 => 3 )[ $m ];
		$index   = array_flip( $lengths );

		$scales = static function ( int $cap ) use ( $m, $lengths, $avail, $top ): array {
			$out = array();
			for ( $code = 1; $code < ( $top + 1 ) ** $m; $code++ ) {
				$v = array();
				for ( $j = 0, $c = $code; $j < $m; $j++, $c = intdiv( $c, $top + 1 ) ) {
					$v[ $j ] = $c % ( $top + 1 );
				}

				// Heaviest line: every count of the first sides, the last one filled up.
				$most = 0;
				$walk = static function ( int $j, int $room, int $weight ) use ( &$walk, &$most, $m, $lengths, $avail, $v ): void {
					if ( $j === $m - 1 ) {
						$most = max( $most, $weight + $v[ $j ] * min( $avail[ $j ], intdiv( $room, $lengths[ $j ] ) ) );
						return;
					}
					for ( $n = 0; $n <= $avail[ $j ] && $n * $lengths[ $j ] <= $room; $n++ ) {
						$walk( $j + 1, $room - $n * $lengths[ $j ], $weight + $n * $v[ $j ] );
					}
				};
				$walk( 0, $cap, 0 );

				if ( $most > 0 ) {
					$out[] = array( $v, $most );
				}
			}
			return $out;
		};

		$along  = $scales( $s['bx'] );
		$across = $scales( $s['by'] );

		foreach ( $along as $fx ) {
			foreach ( $across as $fy ) {
				$sum = 0;
				foreach ( $s['types'] as $type ) {
					// Each candle takes the cheapest of its shapes and turns.
					$least = PHP_INT_MAX;
					foreach ( $type['shapes'] as $shape ) {
						$w     = $index[ $shape[0] ];
						$d     = $index[ $shape[1] ];
						$least = min( $least, $fx[0][ $w ] * $fy[0][ $d ], $fx[0][ $d ] * $fy[0][ $w ] );
					}
					$sum += $type['total'] * $least;
				}

				if ( $sum > $fx[1] * $fy[1] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Every sum of footprint sides up to the box side: the only coordinates and
	 * right edges a pushed-into-the-corner layout can have.
	 *
	 * @param array $types Candle types with shapes, count.
	 * @param int   $limit Box side in units.
	 * @return int[] Ascending, starting at 0.
	 */
	private static function normal( array $types, int $limit ): array {
		$sums = array( 0 => true );

		// Each candle adds any side of any of its shapes, or nothing.
		foreach ( $types as $type ) {
			$steps = self::sides_of( $type );
			for ( $c = 0; $c < $type['count']; $c++ ) {
				$next = $sums;
				foreach ( array_keys( $sums ) as $sum ) {
					foreach ( $steps as $step ) {
						if ( $sum + $step <= $limit ) {
							$next[ $sum + $step ] = true;
						}
					}
				}
				$sums = $next;
			}
		}

		$sums = array_keys( $sums );
		sort( $sums );

		return $sums;
	}

	/**
	 * The sums a candle's corner can sit on: room left for the narrowest side.
	 *
	 * @param int[] $sums  From normal().
	 * @param array $types Candle types with shapes.
	 * @param int   $side  Shrunk box side.
	 * @return int[] Ascending.
	 */
	private static function corners( array $sums, array $types, int $side ): array {
		$narrow = PHP_INT_MAX;
		foreach ( $types as $type ) {
			foreach ( $type['shapes'] as $shape ) {
				$narrow = min( $narrow, $shape[0] );
			}
		}

		return array_values( array_filter( $sums, static fn( int $sum ): bool => $sum + $narrow <= $side ) );
	}

	/**
	 * Distinct side lengths across a type's shapes, ascending.
	 *
	 * @param array $type Candle type with shapes.
	 * @return int[]
	 */
	private static function sides_of( array $type ): array {
		$sides = array();
		foreach ( $type['shapes'] as $shape ) {
			foreach ( $shape as $side ) {
				if ( ! in_array( $side, $sides, true ) ) {
					$sides[] = $side;
				}
			}
		}
		sort( $sides );

		return $sides;
	}

	/**
	 * 'upright', 'lying' or 'any'; anything else is 'lying'.
	 *
	 * @param array $options Packing options.
	 */
	private static function orientation_of( array $options ): string {
		$orientation = $options['orientation'] ?? 'lying';

		return in_array( $orientation, array( 'upright', 'any', 'faces' ), true ) ? $orientation : 'lying';
	}

	/**
	 * The ways an item can go into this box, gap included: [ short, long,
	 * height used ] in units, those too tall or too big for the floor either
	 * way round dropped, sorted.
	 *
	 * @param array  $candle      Item.
	 * @param string $orientation From orientation_of().
	 * @param int    $gap         Gap in units.
	 * @param int    $bx          Box length in units.
	 * @param int    $by          Box width in units.
	 * @param int    $bz          Box height in units.
	 * @return array<int, int[]>
	 */
	private static function ways_of( array $candle, string $orientation, int $gap, int $bx, int $by, int $bz ): array {
		$l = self::up( $candle['length'] ?? 0 );
		$w = self::up( $candle['width'] ?? 0 );
		$h = self::up( $candle['height'] ?? 0 );

		if ( $l <= 0 || $w <= 0 || $h <= 0 ) {
			return array();
		}

		$across = max( $l, $w );
		$ways   = array();

		// 'faces' is for a box-shaped item (a soap bar, a book, a boxed mug): any
		// of its three faces may go down. 'lying' stays the jar rolled onto its
		// side, which only a round item can do and which a rectangular one would
		// get wrong both ways: too wide one way, too narrow the other.
		if ( 'faces' === $orientation ) {
			$ways = array(
				array( $l + $gap, $w + $gap, $h + $gap ),
				array( $l + $gap, $h + $gap, $w + $gap ),
				array( $w + $gap, $h + $gap, $l + $gap ),
			);
		} elseif ( 'lying' !== $orientation ) {
			$ways[] = array( $l + $gap, $w + $gap, $h + $gap );
		}
		if ( 'upright' !== $orientation && 'faces' !== $orientation ) {
			$ways[] = array( $h + $gap, $across + $gap, $across + $gap );
		}

		$out = array();
		foreach ( $ways as $way ) {
			$short = min( $way[0], $way[1] );
			$long  = max( $way[0], $way[1] );

			if ( $way[2] > $bz || ! ( ( $short <= $bx && $long <= $by ) || ( $long <= $bx && $short <= $by ) ) ) {
				continue;
			}
			if ( ! in_array( array( $short, $long, $way[2] ), $out, true ) ) {
				$out[] = array( $short, $long, $way[2] );
			}
		}

		usort( $out, static fn( array $p, array $q ): int => ( $p[0] <=> $q[0] ) ?: ( $p[1] <=> $q[1] ) ?: ( $p[2] <=> $q[2] ) );

		return $out;
	}

	/**
	 * The floor footprints an item may take in this box, gap included, each as
	 * [ short, long ] and sorted: its ways without the height.
	 *
	 * @param array  $candle      Item.
	 * @param string $orientation From orientation_of().
	 * @param int    $gap         Gap in units.
	 * @param int    $bx          Box length in units.
	 * @param int    $by          Box width in units.
	 * @param int    $bz          Box height in units.
	 * @return array<int, int[]>
	 */
	private static function shapes_of( array $candle, string $orientation, int $gap, int $bx, int $by, int $bz ): array {
		$shapes = array();
		foreach ( self::ways_of( $candle, $orientation, $gap, $bx, $by, $bz ) as $way ) {
			if ( ! in_array( array( $way[0], $way[1] ), $shapes, true ) ) {
				$shapes[] = array( $way[0], $way[1] );
			}
		}

		return $shapes;
	}

	/**
	 * Whether the items fit when identical ones may stand one on another, in
	 * columns: each kind of item takes one of its ways, a column of it holds
	 * as many as the box height allows, and the columns share the floor (the
	 * exact search). Every way per kind is tried, while that stays under
	 * STACK_COMBOS. Only identical items stack; a small item on a big one is
	 * not modelled, so this can miss a fit but never invents one.
	 *
	 * @param array  $candles     Items.
	 * @param string $orientation From orientation_of().
	 * @param int    $gap         Gap in units.
	 * @param int    $bx          Box length in units.
	 * @param int    $by          Box width in units.
	 * @param int    $bz          Usable box height in units.
	 */
	private static function stacks_fit( array $candles, string $orientation, int $gap, int $bx, int $by, int $bz ): bool {
		$groups = array();

		foreach ( $candles as $candle ) {
			$key = self::up( $candle['length'] ?? 0 ) . 'x' . self::up( $candle['width'] ?? 0 ) . 'x' . self::up( $candle['height'] ?? 0 );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'candle' => $candle,
					'n'      => 0,
				);
			}

			++$groups[ $key ]['n'];
		}

		ksort( $groups, SORT_STRING );

		// Each option: [ footprint, columns needed, whether it stacks at all ].
		$combos = array( array() );
		foreach ( $groups as $group ) {
			$options = array();
			foreach ( self::ways_of( $group['candle'], $orientation, $gap, $bx, $by, $bz ) as $way ) {
				$high      = max( 1, intdiv( $bz, $way[2] ) );
				$options[] = array( array( $way[0], $way[1] ), (int) ceil( $group['n'] / $high ), $high > 1 );
			}

			$next = array();
			foreach ( $combos as $combo ) {
				foreach ( $options as $option ) {
					$next[] = array_merge( $combo, array( $option ) );
				}
			}

			$combos = $next;

			if ( count( $combos ) > self::STACK_COMBOS ) {
				return false;
			}
		}

		foreach ( $combos as $combo ) {
			// Nothing in it stacks: the one-layer search already answered.
			if ( ! in_array( true, array_column( $combo, 2 ), true ) ) {
				continue;
			}

			if ( self::late() ) {
				return false;
			}

			$pieces = array();
			foreach ( $combo as $option ) {
				for ( $i = 0; $i < $option[1]; $i++ ) {
					$pieces[] = array( $option[0] );
				}
			}

			if ( true === self::floor_fits( $pieces, $bx, $by ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Byte order, -1/0/1 — the same as the TS twin's byString().
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 */
	private static function by_string( string $a, string $b ): int {
		return strcmp( $a, $b ) <=> 0;
	}

	/**
	 * One candle per size key, first seen wins.
	 *
	 * @param array $candles Candle arrays.
	 */
	private static function distinct( array $candles ): array {
		$out = array();
		foreach ( $candles as $candle ) {
			$key = self::candle_key( $candle );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $candle;
			}
		}
		return array_values( $out );
	}

	/**
	 * Size plus dimensions: two candles with this key are interchangeable.
	 *
	 * Rounded up like `fits()` rounds candles, so 5.000 and 5.004 cm stay
	 * apart: `arrange()` tests a group with its first candle's dimensions.
	 *
	 * @param array $candle Candle array.
	 */
	private static function candle_key( array $candle ): string {
		return (string) ( $candle['size'] ?? '' ) . '|' . self::up( $candle['length'] ?? 0 ) . '|' . self::up( $candle['width'] ?? 0 ) . '|' . self::up( $candle['height'] ?? 0 );
	}

	/**
	 * Centimetres to hundredths, rounded up (candles, gap). The 1e-6 keeps float
	 * noise such as 6.5 × 100 = 650.0000000001 from adding a unit.
	 *
	 * @param mixed $cm Value in cm.
	 */
	private static function up( $cm ): int {
		$cm = is_numeric( $cm ) ? (float) $cm : 0.0;
		return $cm > 0 ? (int) ceil( $cm * 100 - 1e-6 ) : 0;
	}

	/**
	 * Centimetres to hundredths, rounded down (box sides).
	 *
	 * @param mixed $cm Value in cm.
	 */
	private static function down( $cm ): int {
		$cm = is_numeric( $cm ) ? (float) $cm : 0.0;
		return $cm > 0 ? (int) floor( $cm * 100 + 1e-6 ) : 0;
	}

	/**
	 * A candle's volume in hundredths of a centimetre cubed, rounded as
	 * GiftGroups and GiftKit round theirs so all three rank sizes alike.
	 *
	 * @param array $candle Candle array.
	 */
	private static function bulk( array $candle ): int {
		$units = static function ( $cm ): int {
			$cm = is_numeric( $cm ) ? (float) $cm : 0.0;
			return $cm > 0 ? (int) floor( $cm * 100 + 0.5 ) : 0;
		};

		return $units( $candle['length'] ?? 0 ) * $units( $candle['width'] ?? 0 ) * $units( $candle['height'] ?? 0 );
	}

	/**
	 * Price to cents.
	 *
	 * @param mixed $price Price.
	 */
	private static function cents( $price ): int {
		$price = is_numeric( $price ) ? (float) $price : 0.0;
		return $price > 0 ? (int) floor( $price * 100 + 0.5 ) : 0;
	}

	/**
	 * A WooCommerce dimension in the store's unit, in cm.
	 *
	 * @param mixed $value Dimension.
	 */
	private static function cm( $value ): float {
		if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
			return 0.0;
		}

		return function_exists( 'wc_get_dimension' ) ? (float) wc_get_dimension( (float) $value, 'cm' ) : (float) $value;
	}
}

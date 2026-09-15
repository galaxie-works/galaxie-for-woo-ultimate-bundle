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
 *   box     { id, length, width, height, max?, price }     internal cm; max 0 = no limit
 *   options { gap, stacking }                              gap in cm, default 0.5
 *
 * The model:
 * - Candles stand upright (they are in glass). Height + gap must be ≤ the box
 *   height. `stacking` is accepted and ignored: one layer only, always.
 * - Each candle takes (length + gap) × (width + gap) of floor, so neighbours are
 *   a full gap apart and every candle keeps half a gap from the walls. Turning a
 *   candle 90° is allowed.
 * - The floor search is exact: it tries every "normal pattern" placement (x and
 *   y are sums of other footprints, which any packing can be pushed into), in
 *   bottom-left order so each layout is visited once. Failed states are
 *   remembered, and a conservative-scale bound proves most hopeless cases
 *   early. Past STEP_LIMIT units of work it gives up and answers "does not fit"
 *   (never a box that will not close), counted the same way in both languages.
 *   Square footprints (the Eir jars): over 13,770 checks of 1–12 candles
 *   (50g/190g) in floors from 10 × 10 to 35 × 35 cm, every fit took at most
 *   176,883 steps. Non-square footprints make a much finer grid of corners: in
 *   big boxes with 9+ of them a real fit can come back "does not fit".
 * - Everything is compared in hundredths of a centimetre and prices in cents,
 *   so PHP floats and JS numbers cannot disagree.
 */
final class GiftPacking {

	/** Search work (states entered + corners tried) before `fits()` gives up and says no. */
	public const STEP_LIMIT = 250000;

	/** Failed search states remembered per `fits()` call. */
	public const MEMO_LIMIT = 50000;

	/** Search steps after which the conservative-scale bound is tried once. */
	public const BOUND_AT = 1000;

	/** Largest group `arrange()` and `summary()` consider at once. */
	public const MAX_ITEMS = 12;

	/**
	 * Whether all these candles go in the box together.
	 *
	 * @param array $box     Box array.
	 * @param array $candles List of candle arrays.
	 * @param array $options { gap, stacking }.
	 */
	public static function fits( array $box, array $candles, array $options = array() ): bool {
		$gap = self::units( $options['gap'] ?? 0.5 );
		$bx  = self::units( $box['length'] ?? 0 );
		$by  = self::units( $box['width'] ?? 0 );
		$bz  = self::units( $box['height'] ?? 0 );
		$max = max( 0, (int) ( $box['max'] ?? 0 ) );
		$n   = count( $candles );

		if ( 0 === $n ) {
			return true;
		}

		if ( ( $max > 0 && $n > $max ) || $bx <= 0 || $by <= 0 || $bz <= 0 ) {
			return false;
		}

		// Identical candles are one type with a count: the search then never
		// tries swapping two of them.
		$types = array();
		$area  = 0;

		foreach ( $candles as $candle ) {
			$a = self::units( $candle['length'] ?? 0 ) + $gap;
			$b = self::units( $candle['width'] ?? 0 ) + $gap;
			$h = self::units( $candle['height'] ?? 0 ) + $gap;

			if ( $a <= $gap || $b <= $gap || $h <= $gap || $h > $bz ) {
				return false;
			}

			$w = min( $a, $b );
			$d = max( $a, $b );

			if ( ! ( ( $w <= $bx && $d <= $by ) || ( $d <= $bx && $w <= $by ) ) ) {
				return false;
			}

			$key = $w . 'x' . $d;

			if ( ! isset( $types[ $key ] ) ) {
				$types[ $key ] = array(
					'w'     => $w,
					'd'     => $d,
					'count' => 0,
					'total' => 0,
				);
			}

			++$types[ $key ]['count'];
			++$types[ $key ]['total'];
			$area += $w * $d;
		}

		if ( $area > $bx * $by ) {
			return false;
		}

		// Largest footprint first: it is the one with fewest places to go.
		$types = array_values( $types );
		usort(
			$types,
			static function ( array $p, array $q ): int {
				return ( $q['w'] * $q['d'] <=> $p['w'] * $p['d'] ) ?: ( $p['w'] <=> $q['w'] );
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
		);

		return self::place( $search, -1, $n, $area );
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
	 * @param array $options { gap, stacking }.
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
	 * @param array $options { gap, stacking }.
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
	 * `[ {50g: 4}, {190g: 1}, {50g: 2, 190g: 1} ]`.
	 *
	 * @param array $box     Box array.
	 * @param array $sizes   One candle per size.
	 * @param array $options { gap, stacking }.
	 * @return array<int, array<string, int>> Size key => count, sizes in the order given.
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

		$named = array();
		foreach ( array_merge( array_values( $pure ), $mixes ) as $v ) {
			$row = array();
			foreach ( $v as $i => $c ) {
				if ( $c > 0 ) {
					$row[ (string) $sizes[ $i ]['size'] ] = $c;
				}
			}
			$named[] = $row;
		}

		return $named;
	}

	/**
	 * A candle array from a WooCommerce variation (or simple product), with
	 * dimensions in cm whatever unit the store uses.
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

		$candle = array(
			'size'   => $size,
			'length' => self::cm( $product->get_length() ),
			'width'  => self::cm( $product->get_width() ),
			'height' => self::cm( $product->get_height() ),
			'price'  => (float) $product->get_price(),
		);

		return ( '' !== $size && $candle['length'] > 0 && $candle['width'] > 0 && $candle['height'] > 0 ) ? $candle : null;
	}

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
			'max'    => absint( $product->get_meta( '_galaxie_box_max' ) ),
			'price'  => (float) $product->get_price(),
		);

		return ( $box['length'] > 0 && $box['width'] > 0 && $box['height'] > 0 ) ? $box : null;
	}

	/**
	 * One candle per term of the size attribute, as the store sells them.
	 *
	 * When variations of the same size disagree on dimensions, the largest (by
	 * volume) stands for the size, so a preview never promises a fit that one of
	 * those candles would break. Term order as WooCommerce sorts the attribute.
	 *
	 * @param string $attribute Size attribute, e.g. `pa_peso`.
	 * @return array<int, array> Candle arrays plus `label` (the term name).
	 */
	public static function store_sizes( string $attribute = 'pa_peso' ): array {
		static $cache = array();

		if ( isset( $cache[ $attribute ] ) ) {
			return $cache[ $attribute ];
		}

		$terms = taxonomy_exists( $attribute ) ? get_terms(
			array(
				'taxonomy'   => $attribute,
				'hide_empty' => false,
			)
		) : array();
		$sizes = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$ids = get_posts(
				array(
					'post_type'      => 'product_variation',
					'post_status'    => array( 'publish', 'private' ),
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => 'attribute_' . $attribute,
							'value' => $term->slug,
						),
					),
				)
			);

			$largest = null;
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				$candle  = $product ? self::candle_from_product( $product, $attribute ) : null;

				if ( $candle && ( ! $largest || $candle['length'] * $candle['width'] * $candle['height'] > $largest['length'] * $largest['width'] * $largest['height'] ) ) {
					$largest = $candle;
				}
			}

			if ( $largest ) {
				unset( $largest['price'] );
				$largest['size']  = $term->slug;
				$largest['label'] = $term->name;
				$sizes[]          = $largest;
			}
		}

		return $cache[ $attribute ] = $sizes;
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

		if ( ++$s['steps'] > self::STEP_LIMIT ) {
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

		for ( $idx = $first; $idx < $cells; $idx++ ) {
			// Work, not just states, is what the limit counts: a fine grid of
			// corners makes each state expensive.
			if ( ++$s['steps'] > self::STEP_LIMIT ) {
				$s['stop'] = true;
				return false;
			}

			$row = intdiv( $idx, $cols );
			$x   = $s['xs'][ $idx % $cols ];
			$y   = $s['ys'][ $row ];
			$top = $s['ys'][ $row + 1 ] ?? $s['by'];

			// Everything left sits at or above this row, and not left of this
			// corner within it. If that much floor, less what is taken, is smaller
			// than the candles left, no later corner helps either.
			$free = $s['bx'] * ( $s['by'] - $y ) - $x * ( $top - $y );

			foreach ( $s['placed'] as $r ) {
				$high  = max( 0, $r[1] + $r[3] - max( $r[1], $y ) );
				$wide  = max( 0, min( $r[0] + $r[2], $x ) - $r[0] );
				$free -= $r[2] * $high - $wide * max( 0, min( $r[1] + $r[3], $top ) - max( $r[1], $y ) );
			}

			if ( $area > $free ) {
				break;
			}

			foreach ( $s['types'] as $t => $type ) {
				if ( 0 === $type['count'] ) {
					continue;
				}

				$turns = $type['w'] === $type['d'] ? array( array( $type['w'], $type['d'] ) ) : array( array( $type['w'], $type['d'] ), array( $type['d'], $type['w'] ) );

				foreach ( $turns as $turn ) {
					list( $w, $d ) = $turn;

					if ( $x + $w > $s['bx'] || $y + $d > $s['by'] ) {
						continue;
					}

					$clear = true;
					foreach ( $s['placed'] as $r ) {
						if ( $x < $r[0] + $r[2] && $r[0] < $x + $w && $y < $r[1] + $r[3] && $r[1] < $y + $d ) {
							$clear = false;
							break;
						}
					}

					if ( ! $clear ) {
						continue;
					}

					$s['placed'][] = array( $x, $y, $w, $d );
					--$s['types'][ $t ]['count'];

					$done = self::place( $s, $idx, $left - 1, $area - $w * $d );

					++$s['types'][ $t ]['count'];
					array_pop( $s['placed'] );

					if ( $done ) {
						return true;
					}

					if ( $s['stop'] ) {
						return false;
					}
				}
			}
		}

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
		$sides = array();
		foreach ( $s['types'] as $type ) {
			$sides[ $type['w'] ] = ( $sides[ $type['w'] ] ?? 0 ) + $type['total'];
			if ( $type['d'] !== $type['w'] ) {
				$sides[ $type['d'] ] = ( $sides[ $type['d'] ] ?? 0 ) + $type['total'];
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
					$w    = $index[ $type['w'] ];
					$d    = $index[ $type['d'] ];
					$sum += $type['total'] * min( $fx[0][ $w ] * $fy[0][ $d ], $fx[0][ $d ] * $fy[0][ $w ] );
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
	 * @param array $types Candle types with w, d, count.
	 * @param int   $limit Box side in units.
	 * @return int[] Ascending, starting at 0.
	 */
	private static function normal( array $types, int $limit ): array {
		$sums = array( 0 => true );

		// Each candle adds either of its sides, or nothing.
		foreach ( $types as $type ) {
			for ( $c = 0; $c < $type['count']; $c++ ) {
				$next = $sums;
				foreach ( array_keys( $sums ) as $sum ) {
					foreach ( array( $type['w'], $type['d'] ) as $step ) {
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
	 * @param array $types Candle types with w, d, count.
	 * @param int   $side  Shrunk box side.
	 * @return int[] Ascending.
	 */
	private static function corners( array $sums, array $types, int $side ): array {
		$narrow = PHP_INT_MAX;
		foreach ( $types as $type ) {
			$narrow = min( $narrow, $type['w'] );
		}

		return array_values( array_filter( $sums, static fn( int $sum ): bool => $sum + $narrow <= $side ) );
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
	 * @param array $candle Candle array.
	 */
	private static function candle_key( array $candle ): string {
		return (string) ( $candle['size'] ?? '' ) . '|' . self::units( $candle['length'] ?? 0 ) . '|' . self::units( $candle['width'] ?? 0 ) . '|' . self::units( $candle['height'] ?? 0 );
	}

	/**
	 * Centimetres to hundredths of a centimetre.
	 *
	 * @param mixed $cm Value in cm.
	 */
	private static function units( $cm ): int {
		$cm = is_numeric( $cm ) ? (float) $cm : 0.0;
		return $cm > 0 ? (int) floor( $cm * 100 + 0.5 ) : 0;
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

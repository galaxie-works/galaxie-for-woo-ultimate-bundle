<?php
/**
 * Which shipping carton (or cartons) an order goes out in.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The shipping carton packer.
 *
 * Plain arrays in and out, no WordPress, so `tests/carton-packing/run.php` can
 * hold it to the merchant's real cartons.
 *
 *   item    { length, width, height, weight, rotate?, ... }   cm and grams; rotate 'any' (default) or 'upright'
 *   carton  { code, length, width, height, empty_weight?, max_load?, ... }
 *           internal cm; grams; max_load 0 or absent = no limit
 *   options { margin, gap, density, stacking, split }        cm, cm, g/L, bool, bool
 *
 * The model:
 * - Filler: every item keeps `margin` from each carton wall (1.5 cm → 3 cm less
 *   per axis) and `gap` from its neighbours (default 0: the filler around the
 *   group is enough). In integer units that is: usable = inside − 2 × margin +
 *   gap, and each item takes its size + gap.
 * - `rotate` 'any': any face down (a candle may stand or lie). 'upright': the
 *   height stays vertical (a gift box keeps its lid up) and only turns on the
 *   floor. The carton itself may be packed on any face — it is a plain box.
 * - Placement is a 3D guillotine heuristic: items largest first, each put in the
 *   corner of a free space, and that space cut into what is left beside, in
 *   front of and on top of the item. The space on top has exactly the item's
 *   footprint, so a stacked item always stands on something (no overhang onto
 *   filler), and `stacking` off simply never keeps it. Several placement and
 *   cut rules and every carton orientation are tried; any one that places
 *   everything is a real, non-overlapping layout, so "fits" is never wrong.
 *   "Does not fit" can be: the heuristic misses some tight layouts (two items
 *   side by side never share the space above them, for one), and past
 *   STEP_LIMIT units of work it gives up. The cost is a bigger carton, never
 *   one that will not close.
 * - Flaps up first: every strategy is tried with the carton's stated height as
 *   height before the carton is laid on a side; the result says which measure
 *   stood vertical, so the packer can be told ("caixa deitada").
 * - `pack()`: the smallest carton by internal volume that takes everything and
 *   its load; otherwise (with `split`) first-fit decreasing by volume over
 *   groups, each then given its smallest carton. Not proven fewest cartons.
 * - Filler weight = (internal volume − items' volume) × density.
 * - Lengths are compared in hundredths of a centimetre, rounded against the
 *   fit (items up, cartons down).
 */
final class CartonPacking {

	/** Most item units one `pack()` call accepts; past this the caller keeps its own quote. */
	public const MAX_UNITS = 60;

	/** Placement tries per `fits()` call before it answers "does not fit". */
	public const STEP_LIMIT = 400000;

	/** Wall-clock milliseconds one `pack()` may take, all its `fits()` calls together. */
	public const TIME_LIMIT_MS = 300;

	/** Defaults for every option; `time_limit` in ms, 0 for none. */
	public const DEFAULTS = array(
		'margin'     => 1.5,
		'gap'        => 0.0,
		'density'    => 29.0,
		'stacking'   => true,
		'split'      => true,
		'time_limit' => self::TIME_LIMIT_MS,
	);

	/** hrtime() past which the running pack() gives up; null outside pack(). */
	private static ?int $deadline = null;

	/** Whether the last pack() ran out of time. */
	private static bool $expired = false;

	/**
	 * Placement strategies, tried in order: where an item goes (`floor`: lowest
	 * free space first, then the smallest; `fit`: the least room left over) and
	 * how the space it went into is cut (`max`: whichever cut leaves the largest
	 * single space; `x` / `y`: always the same way).
	 */
	private const STRATEGIES = array(
		array( 'floor', 'max' ),
		array( 'fit', 'max' ),
		array( 'floor', 'x' ),
		array( 'floor', 'y' ),
		array( 'fit', 'x' ),
		array( 'fit', 'y' ),
	);

	/**
	 * Whether all these items go in the carton together (geometry only; the
	 * carton's load is {@see self::load_ok()}).
	 *
	 * @param array $carton  Carton array.
	 * @param array $items   Item arrays.
	 * @param array $options { margin, gap, stacking }.
	 */
	public static function fits( array $carton, array $items, array $options = array() ): bool {
		return null !== self::layout( $carton, $items, $options );
	}

	/**
	 * Which of the carton's own measures stands vertical in a layout that
	 * holds these items, or null when none is found.
	 *
	 * Flaps up — the carton's stated height as height — is tried with every
	 * strategy first; the carton goes on its side only when that fails.
	 *
	 * @param array $carton  Carton array.
	 * @param array $items   Item arrays.
	 * @param array $options { margin, gap, stacking }.
	 * @return string|null 'height' (flaps up), 'length' or 'width'.
	 */
	public static function layout( array $carton, array $items, array $options = array() ): ?string {
		// Inside pack(): the clock on every call too, since most calls give up
		// long before the in-search check every 1024 tries.
		if ( null !== self::$deadline && ( self::$expired || hrtime( true ) > self::$deadline ) ) {
			self::$expired = true;
			return null;
		}

		$options = self::options( $options );
		$gap     = self::up( $options['gap'] );
		$margin  = self::up( $options['margin'] );
		$space   = array();

		foreach ( array( 'length', 'width', 'height' ) as $axis ) {
			$usable = self::down( $carton[ $axis ] ?? 0 ) - 2 * $margin;

			if ( $usable <= 0 ) {
				return null;
			}

			$space[] = $usable + $gap;
		}

		if ( ! $items ) {
			return 'height';
		}

		$boxes  = array();
		$volume = 0;

		foreach ( array_values( $items ) as $i => $item ) {
			$l = self::up( $item['length'] ?? 0 );
			$w = self::up( $item['width'] ?? 0 );
			$h = self::up( $item['height'] ?? 0 );

			if ( $l <= 0 || $w <= 0 || $h <= 0 ) {
				return null;
			}

			$l += $gap;
			$w += $gap;
			$h += $gap;

			$boxes[] = array(
				'shapes' => self::shapes( $l, $w, $h, (string) ( $item['rotate'] ?? 'any' ) ),
				'volume' => $l * $w * $h,
				'long'   => max( $l, $w, $h ),
				'index'  => $i,
			);

			$volume += $l * $w * $h;
		}

		if ( $volume > $space[0] * $space[1] * $space[2] ) {
			return null;
		}

		// Largest first: it has the fewest places to go.
		usort(
			$boxes,
			static function ( array $p, array $q ): int {
				return ( $q['volume'] <=> $p['volume'] ) ?: ( $q['long'] <=> $p['long'] ) ?: ( $p['index'] <=> $q['index'] );
			}
		);

		$budget = self::STEP_LIMIT;

		foreach ( self::orientations( $space ) as list( $container, $vertical ) ) {
			foreach ( self::STRATEGIES as $strategy ) {
				if ( self::attempt( $container, $boxes, $strategy[0], $strategy[1], (bool) $options['stacking'], $budget ) ) {
					return $vertical;
				}

				if ( $budget <= 0 ) {
					return null;
				}
			}
		}

		return null;
	}

	/**
	 * Cartons for a set of items.
	 *
	 * @param array $items   Item arrays, one per unit.
	 * @param array $cartons Carton arrays.
	 * @param array $options { margin, gap, density, stacking, split }.
	 * @return array<int, array{carton:array, items:int[], contents:int, filler:int, weight:int, vertical:string}>|null
	 *         Grams; `vertical` as {@see self::layout()}. Null when there are no
	 *         items, too many, an item no carton takes, (split off) no single
	 *         carton takes them all, or the time limit ran out ({@see self::timed_out()}).
	 */
	public static function pack( array $items, array $cartons, array $options = array() ): ?array {
		$limit          = self::options( $options )['time_limit'];
		self::$expired  = false;
		self::$deadline = $limit > 0 ? hrtime( true ) + (int) round( $limit * 1e6 ) : null;

		try {
			$packed = self::pack_within( $items, $cartons, $options );
		} finally {
			self::$deadline = null;
		}

		// A half-finished search is not an answer: the caller keeps its own quote.
		return self::$expired ? null : $packed;
	}

	/** Whether the last pack() gave up on its time limit. */
	public static function timed_out(): bool {
		return self::$expired;
	}

	/**
	 * pack() without the clock.
	 *
	 * @param array $items   Item arrays.
	 * @param array $cartons Carton arrays.
	 * @param array $options Options.
	 */
	private static function pack_within( array $items, array $cartons, array $options ): ?array {
		$options = self::options( $options );
		$items   = array_values( $items );
		$count   = count( $items );

		if ( 0 === $count || $count > self::MAX_UNITS ) {
			return null;
		}

		$list = self::by_volume( $cartons );

		if ( ! $list ) {
			return null;
		}

		// [ carton, vertical ] for the smallest carton taking these items, or null.
		$smallest = static function ( array $indices ) use ( $list, $items, $options ): ?array {
			$set = array_map( static fn( int $i ): array => $items[ $i ], $indices );

			foreach ( $list as $carton ) {
				if ( ! self::load_ok( $carton, $set, $options ) ) {
					continue;
				}

				$vertical = self::layout( $carton, $set, $options );

				if ( null !== $vertical ) {
					return array( $carton, $vertical );
				}
			}

			return null;
		};

		// An item no carton takes on its own: nothing to split, the caller keeps its quote.
		$alone = array();

		foreach ( $items as $i => $item ) {
			$key = self::signature( $item );

			if ( ! isset( $alone[ $key ] ) ) {
				$alone[ $key ] = null !== $smallest( array( $i ) );
			}

			if ( ! $alone[ $key ] ) {
				return null;
			}
		}

		$all = range( 0, $count - 1 );
		$one = $smallest( $all );

		if ( $one ) {
			return array( self::result( $one[0], $all, $items, $options, $one[1] ) );
		}

		if ( ! $options['split'] ) {
			return null;
		}

		// First-fit decreasing: largest items first, each into the first group
		// some carton still takes it in, else a group of its own.
		usort(
			$all,
			static function ( int $p, int $q ) use ( $items ): int {
				return ( self::volume( $items[ $q ] ) <=> self::volume( $items[ $p ] ) ) ?: ( $p <=> $q );
			}
		);

		$largest = array_reverse( $list );
		$groups  = array();

		foreach ( $all as $i ) {
			foreach ( $groups as $g => $group ) {
				$with = array_merge( $group, array( $i ) );
				$set  = array_map( static fn( int $n ): array => $items[ $n ], $with );

				foreach ( $largest as $carton ) {
					if ( self::load_ok( $carton, $set, $options ) && self::fits( $carton, $set, $options ) ) {
						$groups[ $g ] = $with;
						continue 3;
					}
				}
			}

			$groups[] = array( $i );
		}

		$out = array();

		foreach ( $groups as $group ) {
			sort( $group );
			$carton = $smallest( $group );

			// Every group was accepted by some carton, so this cannot be null; stay safe anyway.
			if ( ! $carton ) {
				return null;
			}

			$out[] = self::result( $carton[0], $group, $items, $options, $carton[1] );
		}

		return $out;
	}

	/**
	 * Whether a carton carries these items and their filler within its max load.
	 *
	 * @param array $carton  Carton array.
	 * @param array $items   Item arrays.
	 * @param array $options { density }.
	 */
	public static function load_ok( array $carton, array $items, array $options = array() ): bool {
		$max = (int) ( $carton['max_load'] ?? 0 );

		if ( $max <= 0 ) {
			return true;
		}

		return self::grams( $items ) + self::filler( $carton, $items, $options ) <= $max;
	}

	/**
	 * Grams of loose fill: the carton's internal volume the items leave empty.
	 *
	 * @param array $carton  Carton array.
	 * @param array $items   Item arrays.
	 * @param array $options { density } in g/L.
	 */
	public static function filler( array $carton, array $items, array $options = array() ): int {
		$options = self::options( $options );
		$inside  = self::cm( $carton['length'] ?? 0 ) * self::cm( $carton['width'] ?? 0 ) * self::cm( $carton['height'] ?? 0 );
		$used    = 0.0;

		foreach ( $items as $item ) {
			$used += self::volume( $item );
		}

		return (int) round( max( 0.0, $inside - $used ) * max( 0.0, (float) $options['density'] ) / 1000 );
	}

	/**
	 * @param array $carton  Carton array.
	 * @param int[] $indices Items in it.
	 * @param array $items   All items.
	 * @param array  $options  Options.
	 * @param string $vertical The carton measure standing vertical.
	 * @return array{carton:array, items:int[], contents:int, filler:int, weight:int, vertical:string}
	 */
	private static function result( array $carton, array $indices, array $items, array $options, string $vertical = 'height' ): array {
		$set      = array_map( static fn( int $i ): array => $items[ $i ], $indices );
		$contents = self::grams( $set );
		$filler   = self::filler( $carton, $set, $options );

		return array(
			'carton'   => $carton,
			'items'    => array_values( $indices ),
			'contents' => $contents,
			'filler'   => $filler,
			'weight'   => $contents + $filler + max( 0, (int) round( (float) ( $carton['empty_weight'] ?? 0 ) ) ),
			'vertical' => $vertical,
		);
	}

	/**
	 * One try at placing every box in the container with one strategy.
	 *
	 * Free spaces are [ z, dx, dy, dz ]: where they sit does not matter, only
	 * their size (and z, to prefer the floor), because every cut keeps them
	 * apart from each other and from what is placed.
	 *
	 * @param int[]  $container [ x, y, z ].
	 * @param array  $boxes     From fits(), largest first.
	 * @param string $select    'floor' or 'fit'.
	 * @param string $cut       'max', 'x' or 'y'.
	 * @param bool   $stacking  Whether the space on top of an item is kept.
	 * @param int    $budget    Tries left, shared by the whole fits() call.
	 */
	private static function attempt( array $container, array $boxes, string $select, string $cut, bool $stacking, int &$budget ): bool {
		$free = array( array( 0, $container[0], $container[1], $container[2] ) );

		foreach ( $boxes as $box ) {
			$best = null;

			foreach ( $free as $k => $space ) {
				$room = $space[1] * $space[2] * $space[3];

				foreach ( $box['shapes'] as $shape ) {
					if ( --$budget < 0 ) {
						return false;
					}

					// The clock, every 1024 tries: hrtime() is cheap, not free.
					if ( null !== self::$deadline && 0 === ( $budget & 1023 ) && hrtime( true ) > self::$deadline ) {
						self::$expired = true;
						$budget        = 0;
						return false;
					}

					if ( $shape[0] > $space[1] || $shape[1] > $space[2] || $shape[2] > $space[3] ) {
						continue;
					}

					$score = 'floor' === $select
						? array( $space[0], $room, $shape[2] )
						: array( $room - $box['volume'], $space[0], $shape[2] );

					if ( null === $best || self::less( $score, $best[0] ) ) {
						$best = array( $score, $k, $shape );
					}
				}
			}

			if ( null === $best ) {
				return false;
			}

			$space = $free[ $best[1] ];
			unset( $free[ $best[1] ] );

			list( $a, $b, $h ) = $best[2];
			list( $z, $dx, $dy, $dz ) = $space;

			// Beside (x) and in front (y): cut "x" gives the side piece the full
			// depth, cut "y" gives the front piece the full width.
			$by_x = array( array( $z, $dx - $a, $dy, $dz ), array( $z, $a, $dy - $b, $dz ) );
			$by_y = array( array( $z, $dx - $a, $b, $dz ), array( $z, $dx, $dy - $b, $dz ) );

			if ( 'x' === $cut ) {
				$pieces = $by_x;
			} elseif ( 'y' === $cut ) {
				$pieces = $by_y;
			} else {
				$pieces = self::biggest( $by_x ) >= self::biggest( $by_y ) ? $by_x : $by_y;
			}

			if ( $stacking ) {
				$pieces[] = array( $z + $h, $a, $b, $dz - $h );
			}

			foreach ( $pieces as $piece ) {
				if ( $piece[1] > 0 && $piece[2] > 0 && $piece[3] > 0 ) {
					$free[] = $piece;
				}
			}
		}

		return true;
	}

	/**
	 * The ways a box of this size may sit, as [ x, y, z ], without repeats.
	 *
	 * @return array<int, int[]>
	 */
	private static function shapes( int $l, int $w, int $h, string $rotate ): array {
		$all = 'upright' === $rotate
			? array( array( $l, $w, $h ), array( $w, $l, $h ) )
			: array( array( $l, $w, $h ), array( $w, $l, $h ), array( $l, $h, $w ), array( $h, $l, $w ), array( $w, $h, $l ), array( $h, $w, $l ) );

		$out = array();

		foreach ( $all as $shape ) {
			$out[ implode( 'x', $shape ) ] = $shape;
		}

		return array_values( $out );
	}

	/**
	 * The carton on each of its faces, flaps up first, without repeats: a
	 * repeated size keeps the first (flaps-up) way it was reached.
	 *
	 * @param int[] $space Usable [ length, width, height ].
	 * @return array<int, array{0:int[], 1:string}> [ [ x, y, z ], carton measure used as z ].
	 */
	private static function orientations( array $space ): array {
		$axes  = array( 'length', 'width', 'height' );
		$perms = array( array( 0, 1, 2 ), array( 1, 0, 2 ), array( 0, 2, 1 ), array( 2, 0, 1 ), array( 1, 2, 0 ), array( 2, 1, 0 ) );
		$out   = array();

		foreach ( $perms as $p ) {
			$dims = array( $space[ $p[0] ], $space[ $p[1] ], $space[ $p[2] ] );
			$key  = implode( 'x', $dims );

			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( $dims, $axes[ $p[2] ] );
			}
		}

		return array_values( $out );
	}

	/** @param array $pieces [ z, dx, dy, dz ] spaces. */
	private static function biggest( array $pieces ): int {
		$max = 0;

		foreach ( $pieces as $piece ) {
			$max = max( $max, max( 0, $piece[1] ) * max( 0, $piece[2] ) * max( 0, $piece[3] ) );
		}

		return $max;
	}

	/** Lexicographic "less than" for equal-length int lists. */
	private static function less( array $p, array $q ): bool {
		foreach ( $p as $i => $value ) {
			if ( $value !== $q[ $i ] ) {
				return $value < $q[ $i ];
			}
		}

		return false;
	}

	/**
	 * Valid cartons, smallest internal volume first (then code).
	 *
	 * @param array $cartons Carton arrays.
	 * @return array[]
	 */
	private static function by_volume( array $cartons ): array {
		$list = array_values(
			array_filter(
				$cartons,
				static fn( $c ): bool => is_array( $c ) && self::cm( $c['length'] ?? 0 ) > 0 && self::cm( $c['width'] ?? 0 ) > 0 && self::cm( $c['height'] ?? 0 ) > 0
			)
		);

		usort(
			$list,
			static function ( array $p, array $q ): int {
				return ( self::volume( $p ) <=> self::volume( $q ) ) ?: strcmp( (string) ( $p['code'] ?? '' ), (string) ( $q['code'] ?? '' ) );
			}
		);

		return $list;
	}

	/** @param array $items Item arrays. */
	private static function grams( array $items ): int {
		$sum = 0.0;

		foreach ( $items as $item ) {
			$sum += max( 0.0, is_numeric( $item['weight'] ?? null ) ? (float) $item['weight'] : 0.0 );
		}

		return (int) round( $sum );
	}

	/** Items the same size, weight and rotation give the same answer alone. */
	private static function signature( array $item ): string {
		return implode( '|', array( self::up( $item['length'] ?? 0 ), self::up( $item['width'] ?? 0 ), self::up( $item['height'] ?? 0 ), self::cm( $item['weight'] ?? 0 ), (string) ( $item['rotate'] ?? 'any' ) ) );
	}

	/** @param array $box Anything with length, width and height in cm. */
	private static function volume( array $box ): float {
		return self::cm( $box['length'] ?? 0 ) * self::cm( $box['width'] ?? 0 ) * self::cm( $box['height'] ?? 0 );
	}

	/**
	 * @param array $options Given options.
	 * @return array{margin:float, gap:float, density:float, stacking:bool, split:bool, time_limit:float}
	 */
	private static function options( array $options ): array {
		$out = array_merge( self::DEFAULTS, $options );

		return array(
			'margin'     => max( 0.0, self::cm( $out['margin'] ) ),
			'gap'        => max( 0.0, self::cm( $out['gap'] ) ),
			'density'    => max( 0.0, self::cm( $out['density'] ) ),
			'stacking'   => (bool) $out['stacking'],
			'split'      => (bool) $out['split'],
			'time_limit' => self::cm( $out['time_limit'] ),
		);
	}

	/** @param mixed $value */
	private static function cm( $value ): float {
		return is_numeric( $value ) && (float) $value > 0 ? (float) $value : 0.0;
	}

	/** Hundredths of a cm, rounded up (for what goes in). @param mixed $cm */
	private static function up( $cm ): int {
		return (int) ceil( round( self::cm( $cm ) * 100, 6 ) );
	}

	/** Hundredths of a cm, rounded down (for what it goes in). @param mixed $cm */
	private static function down( $cm ): int {
		return (int) floor( round( self::cm( $cm ) * 100, 6 ) );
	}
}

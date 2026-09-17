<?php
/**
 * Gift kits: what still fits in a kit's box, in words, and the kit's name.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The rules a kit shares between the server and the popup, like
 * {@see GiftGroups}: plain arrays in and out, no WordPress, and a TypeScript
 * twin (`frontend/src/lib/gift-kit.ts`) held to `tests/kit/fixtures.json`
 * (`php tests/kit/run.php`, `node tests/kit/run.ts`). Change one, change the
 * other, run both.
 *
 * COMBINATIONS. "Ainda cabem 2 × 190g ou 4 × 50g ou 1 × 190g + 2 × 50g": what
 * can still go in a box that already holds some candles, by the packing engine
 * ({@see GiftPacking::fits()} with the store's options), never by area:
 * - first the most of each size on its own, largest size first;
 * - then at most two mixes of sizes that leave no room for one more candle of
 *   any size, the fullest (by volume) first;
 * - one row of one candle is "cabe", anything else "cabem", nothing "completa".
 * An empty box gives the same rows, for "Leva até …".
 *
 * NAMES. Optional to type, always set: cleaned like a card message, on one
 * line, at most NAME_MAX characters; empty becomes "Kit N", the first number no
 * kit in the cart uses.
 */
final class GiftKit {

	/** Longest kit name, in characters. */
	public const NAME_MAX = 40;

	/** Mixed combinations shown after the single sizes. */
	public const MIXES = 2;

	/** Fit checks one combos() call may make: the answer stays cheap on every change. */
	public const CHECK_LIMIT = 600;

	/**
	 * What still fits, as rows of { size, count } in size order.
	 *
	 * @param array $box     Box array.
	 * @param array $candles Candles already in it.
	 * @param array $sizes   One candle per size the store sells.
	 * @param array $options { gap, stacking, orientation }.
	 * @return array{singles: array<int, array<int, array{size:string, count:int}>>, mixes: array<int, array<int, array{size:string, count:int}>>}
	 */
	public static function combos( array $box, array $candles, array $sizes, array $options = array() ): array {
		$empty = array(
			'singles' => array(),
			'mixes'   => array(),
		);
		$sizes = self::ordered( $sizes );
		$k     = count( $sizes );
		$limit = GiftPacking::MAX_ITEMS;
		$max   = (int) ( $box['max'] ?? 0 );

		if ( $max > 0 ) {
			$limit = min( $limit, $max );
		}

		$room = $limit - count( $candles );

		if ( 0 === $k || $room < 1 ) {
			return $empty;
		}

		$candles = array_values( $candles );
		$checks  = 0;
		$memo    = array();

		$check = static function ( array $v ) use ( $box, $candles, $sizes, $options, $room, &$checks, &$memo ): bool {
			$key = implode( ',', $v );

			if ( isset( $memo[ $key ] ) ) {
				return $memo[ $key ];
			}

			if ( array_sum( $v ) > $room || $checks >= self::CHECK_LIMIT ) {
				return $memo[ $key ] = false;
			}

			++$checks;
			$group = $candles;

			foreach ( $v as $i => $c ) {
				for ( $j = 0; $j < $c; $j++ ) {
					$group[] = $sizes[ $i ];
				}
			}

			return $memo[ $key ] = GiftPacking::fits( $box, $group, $options );
		};

		$singles = array();
		$mixes   = array();
		$queue   = array( array_fill( 0, $k, 0 ) );
		$seen    = array( implode( ',', $queue[0] ) => true );

		// Every addition that fits, smallest first; fitting is monotone, so each
		// is grown from one that did (as GiftPacking::summary() does for a box).
		for ( $q = 0; $q < count( $queue ); $q++ ) {
			$v       = $queue[ $q ];
			$maximal = true;

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
				$i    = (int) array_key_first( array_filter( $v ) );
				$next = $v;
				++$next[ $i ];

				if ( ! $check( $next ) ) {
					$singles[ $i ] = $v;
				}
			} elseif ( $used > 1 && $maximal ) {
				$mixes[] = $v;
			}
		}

		ksort( $singles );

		$volumes = array_map( array( self::class, 'volume' ), $sizes );

		usort(
			$mixes,
			static function ( array $a, array $b ) use ( $volumes ): int {
				$va = 0;
				$vb = 0;

				foreach ( $volumes as $i => $volume ) {
					$va += $a[ $i ] * $volume;
					$vb += $b[ $i ] * $volume;
				}

				// Fullest first, then more candles, then more of the larger sizes.
				return ( $vb <=> $va ) ?: ( array_sum( $b ) <=> array_sum( $a ) ) ?: ( $b <=> $a );
			}
		);

		$row = static function ( array $v ) use ( $sizes ): array {
			$out = array();

			foreach ( $v as $i => $c ) {
				if ( $c > 0 ) {
					$out[] = array(
						'size'  => (string) $sizes[ $i ]['size'],
						'count' => (int) $c,
					);
				}
			}

			return $out;
		};

		return array(
			'singles' => array_values( array_map( $row, $singles ) ),
			'mixes'   => array_map( $row, array_slice( $mixes, 0, self::MIXES ) ),
		);
	}

	/**
	 * combos() in words: "2 × 190g ou 4 × 50g ou 1 × 190g + 2 × 50g".
	 *
	 * `state` picks the sentence: `full` (nothing fits), `one` (a single candle
	 * is all that fits) or `many`.
	 *
	 * @param array                $combos From combos().
	 * @param array<string,string> $labels Size => label ("190g"); the size itself otherwise.
	 * @param string               $or     Between rows.
	 * @param string               $plus   Between sizes of one row.
	 * @return array{state:string, combos:string}
	 */
	public static function wording( array $combos, array $labels = array(), string $or = ' ou ', string $plus = ' + ' ): array {
		$rows = array_merge( (array) ( $combos['singles'] ?? array() ), (array) ( $combos['mixes'] ?? array() ) );

		if ( ! $rows ) {
			return array(
				'state'  => 'full',
				'combos' => '',
			);
		}

		$parts = array();

		foreach ( $rows as $row ) {
			$parts[] = implode(
				$plus,
				array_map(
					static fn( array $entry ): string => (int) $entry['count'] . ' × ' . ( $labels[ (string) $entry['size'] ] ?? (string) $entry['size'] ),
					$row
				)
			);
		}

		$one = 1 === count( $rows ) && 1 === array_sum( array_map( static fn( array $entry ): int => (int) $entry['count'], $rows[0] ) );

		return array(
			'state'  => $one ? 'one' : 'many',
			'combos' => implode( $or, $parts ),
		);
	}

	/**
	 * A merchant's text with `{name}` placeholders filled. Unknown ones stay as
	 * typed; values are inserted as they are (escape the result where printed).
	 *
	 * @param array<string,string> $values Placeholder name (without braces) => value.
	 */
	public static function fill( string $text, array $values ): string {
		return (string) preg_replace_callback(
			'/\{([a-zà-ú_]+)\}/u',
			static fn( array $m ): string => array_key_exists( $m[1], $values ) ? (string) $values[ $m[1] ] : $m[0],
			$text
		);
	}

	/**
	 * A kit name as it is kept: cleaned like a card message, whitespace and
	 * line breaks folded into single spaces, at most NAME_MAX characters.
	 */
	public static function clean_name( string $name ): string {
		$name = GiftGroups::clean_message( $name );
		// Only the whitespace clean_message() leaves, so both twins fold the same.
		$name = trim( (string) preg_replace( '/[\t\n ]+/', ' ', $name ), " \t\n" );

		if ( preg_match_all( '/./su', $name, $chars ) > self::NAME_MAX ) {
			$name = rtrim( implode( '', array_slice( $chars[0], 0, self::NAME_MAX ) ), " \t\n" );
		}

		return $name;
	}

	/**
	 * "Kit N" with the first N no name in use takes (compared without case).
	 *
	 * @param string[] $used   Names of the other kits.
	 * @param string   $format With one `%d`.
	 */
	public static function default_name( array $used, string $format = 'Kit %d' ): string {
		$taken = array();

		foreach ( $used as $name ) {
			$taken[ self::fold( (string) $name ) ] = true;
		}

		for ( $n = 1; ; $n++ ) {
			$name = str_replace( '%d', (string) $n, $format );

			if ( ! isset( $taken[ self::fold( $name ) ] ) ) {
				return $name;
			}
		}
	}

	/**
	 * A stored draft, or null when it is not one. Everything in it is brought
	 * back within bounds: it comes from the session or user meta, and a draft is
	 * only ever trusted after this.
	 *
	 * @param mixed $raw
	 * @return array{id:string, owner:int, name:string, named:bool, box:int, card:int, message:string, candles:array<int, array{id:int, qty:int}>, updated:int}|null
	 */
	public static function normalize( $raw ): ?array {
		if ( ! is_array( $raw ) || ! is_string( $raw['id'] ?? null ) || ! preg_match( '/^[a-z0-9]{1,32}$/', $raw['id'] ) ) {
			return null;
		}

		$candles = array();
		$total   = 0;

		foreach ( array_slice( (array) ( $raw['candles'] ?? array() ), 0, GiftPacking::MAX_ITEMS ) as $line ) {
			$id  = is_array( $line ) ? max( 0, (int) ( $line['id'] ?? 0 ) ) : 0;
			$qty = is_array( $line ) ? max( 0, (int) ( $line['qty'] ?? 0 ) ) : 0;
			$qty = min( $qty, GiftPacking::MAX_ITEMS - $total );

			if ( $id < 1 || $qty < 1 ) {
				continue;
			}

			$total += $qty;

			foreach ( $candles as $i => $existing ) {
				if ( $existing['id'] === $id ) {
					$candles[ $i ]['qty'] += $qty;
					continue 2;
				}
			}

			$candles[] = array(
				'id'  => $id,
				'qty' => $qty,
			);
		}

		return array(
			'id'      => $raw['id'],
			'owner'   => max( 0, (int) ( $raw['owner'] ?? 0 ) ),
			'name'    => self::clean_name( is_string( $raw['name'] ?? null ) ? $raw['name'] : '' ),
			'named'   => ! empty( $raw['named'] ),
			'box'     => max( 0, (int) ( $raw['box'] ?? 0 ) ),
			'card'    => max( 0, (int) ( $raw['card'] ?? 0 ) ),
			'message' => GiftGroups::clean_message( is_string( $raw['message'] ?? null ) ? $raw['message'] : '' ),
			'candles' => $candles,
			'updated' => max( 0, (int) ( $raw['updated'] ?? 0 ) ),
		);
	}

	/**
	 * Candles in a draft, one per unit.
	 *
	 * @param array<int, array{id:int, qty:int}> $lines
	 * @param array<int, array>                  $shapes Candle id => candle array.
	 * @return array
	 */
	public static function units( array $lines, array $shapes ): array {
		$out = array();

		foreach ( $lines as $line ) {
			$shape = $shapes[ (int) $line['id'] ] ?? null;

			for ( $i = 0; $shape && $i < (int) $line['qty'] && count( $out ) < GiftPacking::MAX_ITEMS; $i++ ) {
				$out[] = $shape;
			}
		}

		return $out;
	}

	/** Candles in a draft. */
	public static function count( array $lines ): int {
		return (int) array_sum( array_map( static fn( $line ): int => (int) ( $line['qty'] ?? 0 ), $lines ) );
	}

	/**
	 * Distinct sizes, largest first (first seen on a tie), each with a volume.
	 *
	 * @return array<int, array>
	 */
	private static function ordered( array $sizes ): array {
		$seen = array();

		foreach ( $sizes as $size ) {
			$key = (string) ( $size['size'] ?? '' );

			if ( ! isset( $seen[ $key ] ) && self::volume( $size ) > 0 ) {
				$seen[ $key ] = $size;
			}
		}

		$list  = array_values( $seen );
		$order = array_keys( $list );

		usort(
			$order,
			static fn( int $a, int $b ): int => ( self::volume( $list[ $b ] ) <=> self::volume( $list[ $a ] ) ) ?: ( $a <=> $b )
		);

		return array_map( static fn( int $i ): array => $list[ $i ], $order );
	}

	/** @param array $candle Candle array. */
	private static function volume( array $candle ): int {
		$units = static function ( $cm ): int {
			$cm = is_numeric( $cm ) ? (float) $cm : 0.0;
			return $cm > 0 ? (int) floor( $cm * 100 + 0.5 ) : 0;
		};

		return $units( $candle['length'] ?? 0 ) * $units( $candle['width'] ?? 0 ) * $units( $candle['height'] ?? 0 );
	}

	private static function fold( string $name ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
	}
}

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
 * ({@see GiftPacking::fits_known()} with the store's options), never by area:
 * - first the most of each size on its own, largest size first;
 * - then at most two mixes of sizes that leave no room for one more candle of
 *   any size, the fullest (by volume) first;
 * - one row of one candle is "cabe", anything else "cabem", nothing "completa".
 * An empty box gives the same rows, for "Leva até …".
 *
 * Two flags carry what the search could not do, because a shorter sentence and
 * a wrong sentence look the same otherwise: `complete` (every size's most was
 * settled) and `settled` (the mixes were searched to the end, so no mixes means
 * there are none). A row that reaches GiftPacking::MAX_ITEMS is `capped` — the
 * box may hold more, twelve is where the search stops — and the sentence says
 * "ou mais" rather than a number nobody proved.
 *
 * NAMES. Optional to type, always set: cleaned like a card message, on one
 * line, at most NAME_MAX characters; empty becomes "Kit N", the first number no
 * kit in the cart uses.
 */
final class GiftKit {

	/**
	 * A title or a text as the merchant wrote it in the Elementor panel: a line
	 * break and a little emphasis survive, everything else is stripped. The kit
	 * script allows the same set when it fills those slots live, so a text reads
	 * the same whether the server or the browser put it there.
	 */
	public static function html( string $text ): string {
		return wp_kses(
			$text,
			array(
				'br'     => array(),
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'u'      => array(),
				'small'  => array(),
				'span'   => array( 'class' => array() ),
			)
		);
	}

	/** Longest kit name, in characters. */
	public const NAME_MAX = 40;

	/** Mixed combinations shown after the single sizes. */
	public const MIXES = 2;

	/** Fit checks one combos() call may make: the answer stays cheap on every change. */
	public const CHECK_LIMIT = 600;

	/**
	 * Wall-clock budget of one combos() call, in ms. A mixed fit that fails can
	 * take half a second on its own; the budget, not the number of checks, is
	 * what keeps a public request short.
	 */
	public const BUDGET_MS = 250;

	/**
	 * What still fits, as rows of { entries: [ { size, count } ], capped } in
	 * size order.
	 *
	 * Never less room than there is: a check that ran out of budget (or of
	 * CHECK_LIMIT), and one the packing search could not settle, is "unknown",
	 * not "no".
	 * - A size whose most is unknown is left out of `singles`, and `complete`
	 *   is false.
	 * - A mix is listed once it is shown to leave no room for one more of any
	 *   size, so the search stopping does not delete the ones already found;
	 *   `settled` then says the list may be short and the fullest may be
	 *   missing. Throwing them all away read as a shorter sentence, which looks
	 *   exactly like "there are no mixes".
	 * - With no size at all nothing is known: `complete` and `settled` are both
	 *   false. A store whose sizes went missing must not read "a caixa está
	 *   completa".
	 *
	 * @param array $box       Box array.
	 * @param array $candles   Candles already in it.
	 * @param array $sizes     One candle per size the store sells.
	 * @param array $options   { gap, orientation }.
	 * @param float $budget_ms Wall-clock budget; see BUDGET_MS.
	 * @return array{singles: array<int, array{entries: array<int, array{size:string, count:int}>, capped: bool}>, mixes: array<int, array{entries: array<int, array{size:string, count:int}>, capped: bool}>, complete: bool, settled: bool}
	 */
	public static function combos( array $box, array $candles, array $sizes, array $options = array(), float $budget_ms = self::BUDGET_MS ): array {
		$sizes = self::ordered( $sizes );
		$k     = count( $sizes );
		$limit = GiftPacking::MAX_ITEMS;
		$max   = (int) ( $box['max'] ?? 0 );

		if ( $max > 0 ) {
			$limit = min( $limit, $max );
		}

		$inside = count( $candles );
		$room   = $limit - $inside;

		// No room left is an answer; no size to try is not one.
		if ( $room < 1 ) {
			return array(
				'singles'  => array(),
				'mixes'    => array(),
				'complete' => true,
				'settled'  => true,
			);
		}

		if ( 0 === $k ) {
			return array(
				'singles'  => array(),
				'mixes'    => array(),
				'complete' => false,
				'settled'  => false,
			);
		}

		$candles = array_values( $candles );

		list( $found ) = GiftPacking::with_deadline(
			$budget_ms,
			static function () use ( $box, $candles, $sizes, $options, $room, $k ): array {
				$checks = 0;
				$memo   = array();

				// true / false, or null when the answer is not known (budget, limit).
				$check = static function ( array $v ) use ( $box, $candles, $sizes, $options, $room, &$checks, &$memo ): ?bool {
					$key = implode( ',', $v );

					if ( array_key_exists( $key, $memo ) ) {
						return $memo[ $key ];
					}

					if ( array_sum( $v ) > $room ) {
						return $memo[ $key ] = false;
					}

					if ( $checks >= self::CHECK_LIMIT ) {
						return null;
					}

					++$checks;
					$group = $candles;

					foreach ( $v as $i => $c ) {
						for ( $j = 0; $j < $c; $j++ ) {
							$group[] = $sizes[ $i ];
						}
					}

					// null is the engine's own "not known" — the clock, the work
					// limit, either way nothing was shown against the group.
					return $memo[ $key ] = GiftPacking::fits_known( $box, $group, $options );
				};

				// The most of each size alone: one size, so each check is quick.
				$singles  = array();
				$complete = true;

				for ( $i = 0; $i < $k; $i++ ) {
					$v = array_fill( 0, $k, 0 );

					while ( true ) {
						$next = $v;
						++$next[ $i ];
						$fits = $check( $next );

						if ( true !== $fits ) {
							break;
						}

						$v = $next;
					}

					if ( null === $fits ) {
						$complete = false;
						continue;
					}

					if ( $v[ $i ] > 0 ) {
						$singles[ $i ] = $v;
					}
				}

				// Mixes that leave no room for one more candle of any size, every
				// vector that fits grown from one that did (fitting is monotone).
				$mixes   = array();
				$settled = $complete;
				$queue   = array( array_fill( 0, $k, 0 ) );
				$seen    = array( implode( ',', $queue[0] ) => true );

				for ( $q = 0; $settled && $q < count( $queue ); $q++ ) {
					$v       = $queue[ $q ];
					$maximal = true;

					for ( $i = 0; $i < $k; $i++ ) {
						$next = $v;
						++$next[ $i ];
						$fits = $check( $next );

						if ( null === $fits ) {
							$settled = false;
							break;
						}

						if ( ! $fits ) {
							continue;
						}

						$maximal = false;
						$key     = implode( ',', $next );

						if ( ! isset( $seen[ $key ] ) ) {
							$seen[ $key ] = true;
							$queue[]      = $next;
						}
					}

					if ( $settled && $maximal && count( array_filter( $v ) ) > 1 ) {
						$mixes[] = $v;
					}
				}

				// Every mix collected was shown maximal before the search stopped,
				// so it is listed; `settled` says whether the ones listed are all
				// there are.
				return array( $singles, $mixes, $complete, $settled );
			}
		);

		list( $singles, $mixes, $complete, $settled ) = $found;

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

		// A row that fills the box to MAX_ITEMS is where the search stops, not
		// where the box does — unless the box's own `max` is the smaller limit.
		$capping = 0 === $max || $max > GiftPacking::MAX_ITEMS;

		$row = static function ( array $v ) use ( $sizes, $inside, $capping ): array {
			$out = array();

			foreach ( $v as $i => $c ) {
				if ( $c > 0 ) {
					$out[] = array(
						'size'  => (string) $sizes[ $i ]['size'],
						'count' => (int) $c,
					);
				}
			}

			return array(
				'entries' => $out,
				'capped'  => $capping && $inside + array_sum( $v ) >= GiftPacking::MAX_ITEMS,
			);
		};

		return array(
			'singles'  => array_values( array_map( $row, $singles ) ),
			'mixes'    => array_map( $row, array_slice( $mixes, 0, self::MIXES ) ),
			'complete' => $complete,
			'settled'  => $settled,
		);
	}

	/**
	 * How full a box is, 0–100, or null when nothing about it is settled.
	 *
	 * Space, not candles: the volume in the box against the fullest the box was
	 * shown to take — what is in it plus the fullest row combos() listed. So a
	 * bar moves by what a candle takes up, and it only reaches the end when the
	 * search found nothing more that goes in.
	 *
	 * Counting candles instead read 100 % whenever the smallest size happened to
	 * fit nowhere while a larger one still did, 0 % with a box full of candles
	 * whenever nothing had been settled, and stepped one candle 25 → 67 → 100.
	 *
	 * With rows listed the box is not full, so the bar stops at 99: the last
	 * hundredth belongs to a box with nothing more to add. When the rows are all
	 * that was searched (`settled` false) a mix nobody looked for may go in too,
	 * and the bar is then the fullest the box can honestly be said to be.
	 *
	 * @param array $combos From combos() of the box with these candles.
	 * @param array $sizes  The sizes combos() was given.
	 * @param array $inside The candles in the box, one per unit.
	 */
	public static function fill_percent( array $combos, array $sizes, array $inside ): ?int {
		$volumes = array();

		foreach ( self::ordered( $sizes ) as $size ) {
			$volumes[ (string) $size['size'] ] = self::volume( $size );
		}

		$used = 0;

		foreach ( $inside as $candle ) {
			$used += self::volume( $candle );
		}

		$rows = array_merge( (array) ( $combos['singles'] ?? array() ), (array) ( $combos['mixes'] ?? array() ) );

		// Nothing listed and everything searched: the box is full. Nothing
		// listed because nothing was searched: say nothing at all.
		if ( ! $rows ) {
			$known = ( ! array_key_exists( 'complete', $combos ) || ! empty( $combos['complete'] ) )
				&& ( ! array_key_exists( 'settled', $combos ) || ! empty( $combos['settled'] ) );

			if ( ! $known ) {
				return null;
			}

			return $used > 0 ? 100 : 0;
		}

		if ( $used < 1 ) {
			return 0;
		}

		$most = $used;

		foreach ( $rows as $row ) {
			$more = 0;

			foreach ( (array) ( $row['entries'] ?? array() ) as $entry ) {
				$more += (int) $entry['count'] * (int) ( $volumes[ (string) $entry['size'] ] ?? 0 );
			}

			$most = max( $most, $used + $more );
		}

		return min( 99, intdiv( $used * 100 + intdiv( $most, 2 ), $most ) );
	}

	/**
	 * combos() in words: "2 × 190g ou 4 × 50g ou 1 × 190g + 2 × 50g".
	 *
	 * `state` picks the sentence: `full` (nothing fits), `one` (a single candle
	 * is all that fits), `many`, or `unknown` (nothing could be settled in time:
	 * say nothing rather than "full").
	 *
	 * @param array                $combos From combos().
	 * @param array<string,string> $labels Size => label ("190g"); the size itself otherwise.
	 * @param string               $or     Between rows.
	 * @param string               $plus   Between sizes of one row.
	 * @param string               $more   After a row the search stopped counting at.
	 * @return array{state:string, combos:string}
	 */
	public static function wording( array $combos, array $labels = array(), string $or = ' ou ', string $plus = ' + ', string $more = ' ou mais' ): array {
		$rows = array_merge( (array) ( $combos['singles'] ?? array() ), (array) ( $combos['mixes'] ?? array() ) );

		$complete = ! array_key_exists( 'complete', $combos ) || ! empty( $combos['complete'] );
		// Missing means an older or hand-written answer: read as searched out.
		$settled = ! array_key_exists( 'settled', $combos ) || ! empty( $combos['settled'] );

		// "Nothing fits" is only "a caixa está completa" when every size and
		// every mix was settled; otherwise the box may well have room nobody
		// looked for, and the honest sentence is none at all.
		if ( ! $rows ) {
			return array(
				'state'  => $complete && $settled ? 'full' : 'unknown',
				'combos' => '',
			);
		}

		$parts = array();

		foreach ( $rows as $row ) {
			$text = implode(
				$plus,
				array_map(
					static fn( array $entry ): string => (int) $entry['count'] . ' × ' . ( $labels[ (string) $entry['size'] ] ?? (string) $entry['size'] ),
					(array) ( $row['entries'] ?? array() )
				)
			);

			$parts[] = empty( $row['capped'] ) ? $text : $text . $more;
		}

		// "Only one fits" is only said when every size and every mix was settled,
		// and when that one is a real most rather than where the search stopped.
		$one = $complete && $settled && 1 === count( $rows ) && empty( $rows[0]['capped'] )
			&& 1 === array_sum( array_map( static fn( array $entry ): int => (int) $entry['count'], (array) $rows[0]['entries'] ) );

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
	 * Distinct sizes, largest first, each with a volume.
	 *
	 * Sizes of the same volume come out in reverse order of appearance, so that
	 * the last of the list is the size {@see GiftGroups::smallest()} picks —
	 * which keeps the first one seen. The two used to break that tie opposite
	 * ways, and "the smallest size" then meant two different candles depending
	 * on which one was asked.
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
			static fn( int $a, int $b ): int => ( self::volume( $list[ $b ] ) <=> self::volume( $list[ $a ] ) ) ?: ( $b <=> $a )
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

<?php
/**
 * The cart urgency timer's state, which lives on the server.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * "Hurry up, these products are limited, checkout within 04:32."
 *
 * The clock is anchored to a timestamp in the WooCommerce session, not to the
 * moment the page loaded. That is the whole point of it: a countdown that
 * restarts on every reload is not a deadline, it is a decoration, and a shopper
 * who reloads twice learns that in about four seconds. XStore anchors it the
 * same way, in `etheme_last_added_cart_time`.
 *
 * The browser is only ever told how many seconds are left. It counts down from
 * that and formats it; it never decides when time is up on its own.
 */
final class CartCountdown {

	private const SESSION_KEY = 'galaxie_cart_countdown_start';

	/**
	 * Restart the clock whenever something is added.
	 *
	 * Adding a second item is a shopper still shopping, and cutting their time
	 * short for it would punish exactly the behaviour the timer is there to
	 * encourage. XStore restarts on add too.
	 */
	public static function touch(): void {
		if ( ! self::session() ) {
			return;
		}

		WC()->session->set( self::SESSION_KEY, time() );
	}

	/**
	 * Seconds left, the total, and whether time has run out.
	 *
	 * @return array{remaining:int,total:int,expired:bool}
	 */
	public static function state( int $minutes, bool $loop ): array {
		$total = max( 1, $minutes ) * 60;

		if ( ! self::session() ) {
			return array(
				'remaining' => $total,
				'total'     => $total,
				'expired'   => false,
			);
		}

		$start = (int) WC()->session->get( self::SESSION_KEY );

		// No anchor yet — a cart filled before this widget existed, or a
		// session that lost it. Starting the clock now is the only honest
		// answer: the alternative is showing an expired timer to someone who
		// has never seen a live one.
		if ( ! $start ) {
			$start = time();
			WC()->session->set( self::SESSION_KEY, $start );
		}

		$remaining = $total - ( time() - $start );

		if ( $remaining > 0 ) {
			return array(
				'remaining' => $remaining,
				'total'     => $total,
				'expired'   => false,
			);
		}

		if ( $loop ) {
			WC()->session->set( self::SESSION_KEY, time() );

			return array(
				'remaining' => $total,
				'total'     => $total,
				'expired'   => false,
			);
		}

		return array(
			'remaining' => 0,
			'total'     => $total,
			'expired'   => true,
		);
	}

	/** `mm:ss`, and hours when a merchant asks for more than sixty minutes. */
	public static function format( int $seconds ): string {
		$seconds = max( 0, $seconds );

		return $seconds >= HOUR_IN_SECONDS
			? gmdate( 'H:i:s', $seconds )
			: gmdate( 'i:s', $seconds );
	}

	private static function session(): bool {
		return function_exists( 'WC' ) && null !== WC()->session;
	}
}

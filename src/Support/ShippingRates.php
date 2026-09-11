<?php
/**
 * Reading a shipping rate the way a shopper reads it.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Carrier, delivery estimate and price, pulled apart.
 *
 * Carriers hand WooCommerce one label with everything in it. Melhor Envio's
 * reads "Jadlog (4 a 6 dias úteis)". The shipping card shows the carrier and
 * the estimate on separate lines, and the free shipping rule needs the estimate
 * as a number to find the fastest quote. Both read it from here, so they
 * cannot disagree about which part is the estimate.
 */
final class ShippingRates {

	/**
	 * @return array{name:string,days:string}
	 */
	public static function parts( \WC_Shipping_Rate $rate ): array {
		$name = trim( wp_strip_all_tags( (string) $rate->get_label() ) );
		$meta = (array) $rate->get_meta_data();

		// Melhor Envio also stores the estimate on its own, which is the better
		// source when the label has been rewritten.
		$days = isset( $meta['delivery_time'] ) ? trim( (string) $meta['delivery_time'], " \t()" ) : '';

		// A trailing parenthesis with a number in it is the estimate:
		// "Jadlog (4 a 6 dias úteis)", "Frete grátis (4 a 6 dias úteis)".
		if ( preg_match( '/^(.*?)\s*\(([^()]*\d[^()]*)\)\s*$/u', $name, $match ) ) {
			$name = trim( $match[1] );

			if ( '' === $days ) {
				$days = trim( $match[2] );
			}
		}

		return array(
			'name' => $name,
			'days' => $days,
		);
	}

	/**
	 * The longest the estimate allows: 6 for "4 a 6 dias úteis".
	 *
	 * Comparing the upper bounds is the promise a shopper holds a store to.
	 * A rate with no estimate sorts last.
	 */
	public static function max_days( \WC_Shipping_Rate $rate ): int {
		preg_match_all( '/\d+/', self::parts( $rate )['days'], $numbers );

		return $numbers[0] ? max( array_map( 'intval', $numbers[0] ) ) : PHP_INT_MAX;
	}

	/**
	 * The price as the cart shows it, with or without tax per the store's setting.
	 */
	public static function display_cost( \WC_Shipping_Rate $rate ): float {
		$cost = (float) $rate->get_cost();

		if ( function_exists( 'WC' ) && WC()->cart && WC()->cart->display_prices_including_tax() ) {
			$cost += array_sum( array_map( 'floatval', (array) $rate->get_taxes() ) );
		}

		return $cost;
	}
}

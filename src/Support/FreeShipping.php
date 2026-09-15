<?php
/**
 * How far the cart is from free shipping.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * "Faltam R$ 40,10 para o frete grátis."
 *
 * XStore's progress bar asks the merchant to type the threshold and compares
 * against it. That works until the free shipping method changes and the bar
 * keeps promising the old number, so the threshold is read from WooCommerce by
 * default: the zone that matches this customer, the Free Shipping method in it,
 * its `min_amount`. Typing one by hand stays available for stores whose real
 * rule lives somewhere this cannot see.
 *
 * The comparison is against the cart contents total AFTER discounts, which is
 * what WooCommerce's own Free Shipping method compares by default.
 */
final class FreeShipping {

	/**
	 * The store's own threshold, or 0.0 when nothing sets one.
	 */
	public static function threshold(): float {
		// Ours first. The Free Shipping module is the one place a merchant sets
		// this number, and it is the number checkout actually honours — reading
		// the zone ahead of it would let the bar promise something else.
		$ours = \Galaxie\Woo\Modules\FreeShipping\Module::threshold();

		if ( $ours > 0 ) {
			return $ours;
		}

		if ( ! function_exists( 'WC' ) || null === WC()->cart || ! class_exists( '\WC_Shipping_Zones' ) ) {
			return 0.0;
		}

		$packages = WC()->cart->get_shipping_packages();
		$package  = reset( $packages );

		if ( ! $package ) {
			return 0.0;
		}

		$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );

		if ( ! $zone ) {
			return 0.0;
		}

		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( 'free_shipping' !== $method->id ) {
				continue;
			}

			$minimum = (float) ( $method->get_option( 'min_amount' ) ?: 0 );

			// A Free Shipping method with no minimum is free shipping for
			// everyone; there is no distance to report and no bar to draw.
			if ( $minimum > 0 ) {
				return $minimum;
			}
		}

		return 0.0;
	}

	/**
	 * Has the shopper told us where the parcel goes?
	 *
	 * A CEP saved by the calculator or on the customer's account. In Brazil it
	 * has to be a whole CEP, since that is what decides the zone.
	 */
	public static function destination_known(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return false;
		}

		$postcode = (string) WC()->customer->get_shipping_postcode();

		if ( '' === $postcode ) {
			return false;
		}

		return 'BR' !== WC()->customer->get_shipping_country() || BrazilianPostcode::is_valid( $postcode );
	}

	/**
	 * @return array{threshold:float,total:float,remaining:float,percent:float,achieved:bool}
	 */
	public static function state( float $threshold ): array {
		$total = function_exists( 'WC' ) && WC()->cart ? (float) WC()->cart->get_cart_contents_total() : 0.0;

		if ( $threshold <= 0 ) {
			return array(
				'threshold' => 0.0,
				'total'     => $total,
				'remaining' => 0.0,
				'percent'   => 100.0,
				'achieved'  => true,
			);
		}

		$remaining = max( 0.0, $threshold - $total );

		return array(
			'threshold' => $threshold,
			'total'     => $total,
			'remaining' => $remaining,
			'percent'   => min( 100.0, ( $total / $threshold ) * 100 ),
			'achieved'  => $remaining <= 0,
		);
	}

	/**
	 * The message with `{amount}` replaced, as HTML.
	 *
	 * The amount is wrapped so it can be styled apart from the sentence around
	 * it — it is the number the shopper is deciding against.
	 */
	public static function message( string $template, float $remaining ): string {
		return str_replace(
			'{amount}',
			'<span class="galaxie-free-shipping-amount">' . wp_kses_post( wc_price( $remaining ) ) . '</span>',
			esc_html( $template )
		);
	}
}

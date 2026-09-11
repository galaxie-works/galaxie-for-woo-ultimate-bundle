<?php
/**
 * Which state a Brazilian CEP belongs to.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The CEP says where a parcel goes; the State field only says what someone picked.
 *
 * WooCommerce matches shipping zones by the state on the address, and the
 * shopper chooses that state from a dropdown that nothing checks against the
 * postcode. On the test store the calculator arrived with São Paulo already
 * selected. Type a Recife CEP into it and the order lands in the "Sul e
 * Sudeste" zone, which is free shipping for a parcel going to Pernambuco.
 *
 * Correios assigns CEPs in fixed ranges per state, so the answer is a lookup,
 * not a network call. A CEP outside every known range returns null, and callers
 * treat null as "cannot tell". For free shipping that means no promise.
 */
final class BrazilianPostcode {

	/**
	 * Correios' ranges, as eight-digit integers: from, to, state.
	 *
	 * Some states have more than one range, which is why this is a list and not
	 * a map.
	 */
	private const RANGES = array(
		array( 1000000, 19999999, 'SP' ),
		array( 20000000, 28999999, 'RJ' ),
		array( 29000000, 29999999, 'ES' ),
		array( 30000000, 39999999, 'MG' ),
		array( 40000000, 48999999, 'BA' ),
		array( 49000000, 49999999, 'SE' ),
		array( 50000000, 56999999, 'PE' ),
		array( 57000000, 57999999, 'AL' ),
		array( 58000000, 58999999, 'PB' ),
		array( 59000000, 59999999, 'RN' ),
		array( 60000000, 63999999, 'CE' ),
		array( 64000000, 64999999, 'PI' ),
		array( 65000000, 65999999, 'MA' ),
		array( 66000000, 68899999, 'PA' ),
		array( 68900000, 68999999, 'AP' ),
		array( 69000000, 69299999, 'AM' ),
		array( 69300000, 69399999, 'RR' ),
		array( 69400000, 69899999, 'AM' ),
		array( 69900000, 69999999, 'AC' ),
		array( 70000000, 72799999, 'DF' ),
		array( 72800000, 72999999, 'GO' ),
		array( 73000000, 73699999, 'DF' ),
		array( 73700000, 76799999, 'GO' ),
		array( 76800000, 76999999, 'RO' ),
		array( 77000000, 77999999, 'TO' ),
		array( 78000000, 78899999, 'MT' ),
		array( 79000000, 79999999, 'MS' ),
		array( 80000000, 87999999, 'PR' ),
		array( 88000000, 89999999, 'SC' ),
		array( 90000000, 99999999, 'RS' ),
	);

	public static function digits( string $postcode ): string {
		return (string) preg_replace( '/\D+/', '', $postcode );
	}

	public static function is_valid( string $postcode ): bool {
		return 8 === strlen( self::digits( $postcode ) );
	}

	/**
	 * WooCommerce's state code for this CEP ("SP", "PE"), or null.
	 */
	public static function state( string $postcode ): ?string {
		if ( ! self::is_valid( $postcode ) ) {
			return null;
		}

		$number = (int) self::digits( $postcode );

		foreach ( self::RANGES as $range ) {
			if ( $number >= $range[0] && $number <= $range[1] ) {
				return $range[2];
			}
		}

		return null;
	}
}

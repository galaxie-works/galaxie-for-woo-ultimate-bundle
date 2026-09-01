<?php
/**
 * Brazilian CPF validation.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure CPF check-digit validation, ported from eir-my-account-ux's
 * `is_valid_cpf()`. Used by the auth (registration) and My Account (profile)
 * modules — a single implementation so the rule can't drift.
 */
final class Cpf {

	/** Validate a CPF string (any punctuation is stripped first). */
	public static function is_valid( string $cpf ): bool {
		$cpf = (string) preg_replace( '/\D/', '', $cpf );

		if ( strlen( $cpf ) !== 11 ) {
			return false;
		}

		// Reject known-invalid sequences of a single repeated digit (e.g. 111.111.111-11).
		if ( preg_match( '/^(\d)\1{10}$/', $cpf ) ) {
			return false;
		}

		for ( $t = 9; $t < 11; $t++ ) {
			$sum = 0;
			for ( $i = 0; $i < $t; $i++ ) {
				$sum += (int) $cpf[ $i ] * ( ( $t + 1 ) - $i );
			}
			$digit = ( ( 10 * $sum ) % 11 ) % 10;
			if ( (int) $cpf[ $t ] !== $digit ) {
				return false;
			}
		}

		return true;
	}

	/** Format 11 digits as `000.000.000-00`; returns the input untouched if not 11 digits. */
	public static function format( string $cpf ): string {
		$digits = (string) preg_replace( '/\D/', '', $cpf );
		if ( strlen( $digits ) !== 11 ) {
			return $cpf;
		}
		return substr( $digits, 0, 3 ) . '.' . substr( $digits, 3, 3 ) . '.' . substr( $digits, 6, 3 ) . '-' . substr( $digits, 9, 2 );
	}
}

<?php
/**
 * Phone numbers kept in one format: E.164.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * FluentCRM stores a contact's phone with its country code (`+5511980409005`),
 * and the phone fields in My Account and checkout now send the same thing —
 * what intl-tel-input's `getNumber()` returns. Numbers saved before that were
 * typed the Brazilian way, `(11) 98040-9005`, and a customer without the
 * script still types them so. Everything that writes `billing_phone` passes
 * through here, so both systems hold the same string and a number that came
 * back from FluentCRM saves again without being retyped.
 */
final class Phone {

	/** What may sit between the digits of a typed number. */
	private const SEPARATORS = '/[\s().\-]+/';

	/**
	 * The number in E.164, or null when it is not a phone number.
	 *
	 * - `+` and 8 to 15 digits (separators allowed) is taken as it is, minus the
	 *   separators. A `+55` number must still be a Brazilian one.
	 * - Without a `+`, and only for Brazil: 10 or 11 digits with the area code,
	 *   optionally after a trunk `0` or the country code `55`, becomes `+55…`.
	 *
	 * An empty value returns null too; what empty means (clear the number, or
	 * leave it alone) is the caller's call.
	 */
	public static function normalize( string $raw, string $default_country = 'BR' ): ?string {
		$compact = (string) preg_replace( self::SEPARATORS, '', trim( $raw ) );

		if ( '' === $compact ) {
			return null;
		}

		if ( str_starts_with( $compact, '+' ) ) {
			if ( ! preg_match( '/^\+[1-9]\d{7,14}$/', $compact ) ) {
				return null;
			}
			if ( str_starts_with( $compact, '+55' ) && null === self::brazilian_national( substr( $compact, 3 ) ) ) {
				return null;
			}
			return $compact;
		}

		// Without a country code there is only one country whose rules we know.
		if ( 'BR' !== strtoupper( $default_country ) || ! preg_match( '/^\d+$/', $compact ) ) {
			return null;
		}

		// A national number never starts with 0 and is at most 11 digits long, so
		// a leading `55` on 12 or 13 digits is the country code, and a leading 0
		// the trunk prefix. An 11-digit number starting with 55 is area code 55.
		if ( strlen( $compact ) >= 12 && str_starts_with( $compact, '55' ) ) {
			$compact = substr( $compact, 2 );
		} elseif ( str_starts_with( $compact, '0' ) ) {
			$compact = substr( $compact, 1 );
		}

		$national = self::brazilian_national( $compact );

		return null === $national ? null : '+55' . $national;
	}

	/**
	 * A Brazilian number after the country code: an area code (11–99), then a
	 * nine-digit mobile starting with 9 or an eight-digit landline.
	 */
	private static function brazilian_national( string $digits ): ?string {
		return preg_match( '/^[1-9][1-9](?:9\d{8}|[2-8]\d{7})$/', $digits ) ? $digits : null;
	}
}

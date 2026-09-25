<?php
/**
 * The CEP a shopper quoted on the cart, carried through signing in.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce keeps the cart calculator's destination in the session's
 * customer record, stamped with the id of whoever it belongs to. Signing in
 * migrates the session to the user, but on the next request
 * `WC_Customer_Data_Store_Session::read()` sees an id that is not the user's
 * (the guest's "0") and throws the whole record away: the customer falls back
 * to the account's address, or the store's own for a new account. On the test
 * store that meant a shopper who quoted São Paulo on the cart reached the
 * delivery step, after the e-mail code, with no CEP and no carriers.
 *
 * So the destination is copied aside while the shopper is still a guest (a
 * session key of our own survives the migration untouched) and written back
 * into the signed-in customer on the next request. A new account also keeps
 * it on file, as the start of its address; an existing one keeps its own
 * addresses, and the quote only lives in the session, where the delivery step
 * reads it to preselect or offer it.
 */
final class QuotedDestination {

	private const SESSION_KEY = 'galaxie_quoted_destination';

	public static function hooks(): void {
		// WooCommerce builds the customer at `init` 0, for pages and AJAX alike.
		add_action( 'wp_loaded', array( self::class, 'restore' ), 20 );
	}

	/**
	 * Called while still a guest, just before signing in.
	 *
	 * @return array{country:string,state:string,postcode:string,city:string}|null
	 */
	public static function remember(): ?array {
		if ( is_user_logged_in() || ! FreeShipping::destination_known() || ! WC()->session ) {
			return null;
		}

		$customer    = WC()->customer;
		$destination = array(
			'country'  => (string) $customer->get_shipping_country(),
			'state'    => (string) $customer->get_shipping_state(),
			'postcode' => (string) $customer->get_shipping_postcode(),
			'city'     => (string) $customer->get_shipping_city(),
		);

		WC()->session->set( self::SESSION_KEY, $destination );

		return $destination;
	}

	/**
	 * A brand-new account starts from the CEP it quoted. Only the location is
	 * known — no street — so the Address Book still has no entry (it needs
	 * one) and the delivery step opens its form already holding this CEP.
	 *
	 * @param array{country:string,state:string,postcode:string,city:string} $destination
	 */
	public static function keep_in_account( int $user_id, array $destination ): void {
		foreach ( array( 'shipping', 'billing' ) as $type ) {
			if ( '' !== (string) get_user_meta( $user_id, $type . '_postcode', true ) ) {
				continue;
			}
			foreach ( $destination as $field => $value ) {
				update_user_meta( $user_id, $type . '_' . $field, $value );
			}
		}
	}

	/** First request signed in: the quote goes back into the customer's session. */
	public static function restore(): void {
		if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->session || ! WC()->customer ) {
			return;
		}

		$destination = WC()->session->get( self::SESSION_KEY );
		if ( ! is_array( $destination ) ) {
			return;
		}

		WC()->session->set( self::SESSION_KEY, null );

		if ( '' === (string) ( $destination['postcode'] ?? '' ) ) {
			return;
		}

		WC()->customer->set_shipping_location(
			(string) ( $destination['country'] ?? 'BR' ),
			(string) ( $destination['state'] ?? '' ),
			(string) $destination['postcode'],
			(string) ( $destination['city'] ?? '' )
		);
		WC()->customer->set_calculated_shipping( true );
		WC()->customer->save();
	}
}

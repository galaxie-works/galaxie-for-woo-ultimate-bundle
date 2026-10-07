<?php
/**
 * No orders from signed-out shoppers while the Galaxie checkout is the store's.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * The Galaxie checkout starts with signing in (the e-mail code), and the rest
 * of the store relies on every order having an account: the minimum-age
 * check reads the account's date of birth, the profile its CPF. The stepper
 * only hides the order form from a guest, though; a request posted straight
 * to WooCommerce (`?wc-ajax=checkout`, or the Store API's checkout route)
 * would still be taken. This refuses it on the server, for both.
 *
 * On while the Checkout module is on (it is booted from there). Express
 * payments (Google Pay / Apple Pay through FunnelKit's Stripe) go through the
 * same checkout and pass for a signed-in customer; offered to a signed-out
 * one, they are refused with the same message.
 *
 * `galaxie_woo/checkout_requires_login` (default true) turns it off in code.
 */
final class GuestCheckoutGuard {

	public static function hooks(): void {
		add_action( 'woocommerce_checkout_process', array( self::class, 'check_classic' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'check_store_api' ), 5, 2 );
	}

	/** The classic checkout (`?wc-ajax=checkout`), before WooCommerce validates or creates anything. */
	public static function check_classic(): void {
		if ( self::refuses() && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::message(), 'error' );
		}
	}

	/**
	 * The Store API's checkout: throwing here stops the order.
	 *
	 * @param \WC_Order        $order
	 * @param \WP_REST_Request $request
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException For a signed-out shopper.
	 */
	public static function check_store_api( $order, $request ): void {
		if ( self::refuses() ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'galaxie_woo_login_required', esc_html( self::message() ), 401 );
		}
	}

	private static function refuses(): bool {
		/**
		 * Whether the store takes orders only from signed-in customers.
		 *
		 * @param bool $required
		 */
		return ! is_user_logged_in() && (bool) apply_filters( 'galaxie_woo/checkout_requires_login', true );
	}

	public static function message(): string {
		return __( 'Entre na sua conta para finalizar a compra.', 'galaxie-woo' );
	}
}

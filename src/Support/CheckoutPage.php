<?php
/**
 * Which checkout page this is: the form, or one of WooCommerce's order pages under it.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * `is_checkout()` answers true on three different pages that share the
 * checkout URL: the checkout form itself, the thank-you page
 * (`/checkout/order-received/{id}/`) and paying a pending order again
 * (`/checkout/order-pay/{id}/`). The last two are about an order that already
 * exists — the cart is usually empty there, there is no address or shipping
 * step, and WooCommerce's own `[woocommerce_checkout]` prints the page. Code
 * that dresses the checkout form (the stepper, the saved-address picker, a
 * gift's address, the Maps loader) asks `is_form()`; the widget asks
 * `is_order_endpoint()` to step aside and let WooCommerce print the page.
 *
 * Payment plugins hang real work on those two pages: FunnelKit's Stripe
 * marks a Pix order paid from `woocommerce_thankyou`, and a shopper pays a
 * pending Pix order again from order-pay. Both only run if the native page
 * is printed, visibly.
 */
final class CheckoutPage {

	/** The thank-you page or the order-pay page, both under the checkout URL. */
	public static function is_order_endpoint(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) );
	}

	/** The checkout form (or its order-review refresh), not an order page under it. */
	public static function is_form(): bool {
		return function_exists( 'is_checkout' ) && is_checkout() && ! self::is_order_endpoint();
	}
}

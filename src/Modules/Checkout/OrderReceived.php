<?php
/**
 * The thank-you page drawn from an Elementor template of the merchant's.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's thank-you page (`/checkout/order-received/{id}/?key=…`) is a
 * template of its own with no Elementor controls: an overview strip, the
 * order table and two address boxes, in whatever the theme makes of them. A
 * merchant who picks a template under the Galaxie Checkout widget's "Order
 * received" gets that template instead, with Galaxie Account Order inside it
 * showing this order, styled like every other order screen.
 *
 * WooCommerce's page still runs underneath, because real work hangs on it:
 * the shortcode checks the order key, empties the cart and the "awaiting
 * payment" session, and its template fires `woocommerce_thankyou` — where
 * FunnelKit's Stripe marks a Pix order paid — and the payment method's own
 * `woocommerce_thankyou_{method}`. Only what it prints changes: its template is
 * swapped for one that fires those hooks and nothing else, and WooCommerce's
 * order table (hung on `woocommerce_thankyou`) is taken off for the call, so
 * the order is not shown twice. Anything a payment method prints there still
 * appears, below the merchant's template.
 */
final class OrderReceived {

	/**
	 * The order this thank-you page is about, when the address carries its key
	 * and the shopper may see it; null anywhere else.
	 */
	public static function order(): ?\WC_Order {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
			return null;
		}

		global $wp;

		$id    = absint( $wp->query_vars['order-received'] ?? 0 );
		$key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the credential here, as in WooCommerce.
		$order = $id ? wc_get_order( $id ) : false;

		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), (string) $key ) ) {
			return null;
		}

		// A signed-in shopper sees only their own orders, as WooCommerce asks.
		if ( $order->get_customer_id() && get_current_user_id() !== $order->get_customer_id() ) {
			return null;
		}

		return $order;
	}

	/**
	 * The page: WooCommerce's run first (so a Pix marked paid there shows as
	 * paid in the template), then the template, then whatever a payment method
	 * printed. Null when there is nothing of ours to draw — no template, or an
	 * order this address cannot show — and the caller prints the native page.
	 */
	public static function render( int $template ): ?string {
		if ( $template <= 0 || ! class_exists( '\Elementor\Plugin' ) || 'publish' !== get_post_status( $template ) || ! self::order() ) {
			return null;
		}

		$swap = static function ( $located, $name ) {
			return 'checkout/thankyou.php' === $name ? __DIR__ . '/views/thankyou-hooks.php' : $located;
		};

		$table = has_action( 'woocommerce_thankyou', 'woocommerce_order_details_table' );

		add_filter( 'wc_get_template', $swap, 10, 2 );

		if ( false !== $table ) {
			remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', $table );
		}

		try {
			$native = (string) do_shortcode( '[woocommerce_checkout]' );
		} finally {
			remove_filter( 'wc_get_template', $swap, 10 );

			if ( false !== $table ) {
				add_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', $table );
			}
		}

		$page = (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $template, true );

		return $page . '<div class="galaxie-thankyou-extras">' . $native . '</div>';
	}
}

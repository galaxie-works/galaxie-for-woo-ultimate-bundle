<?php
/**
 * The order pages under the checkout URL when there is no order to show.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Checkout;

use Galaxie\Woo\Support\CheckoutPage;

defined( 'ABSPATH' ) || exit;

/**
 * Two dead ends WooCommerce leaves on /checkout/order-received/ and
 * /checkout/order-pay/:
 *
 * - order-received with an unknown order or a wrong key still says "Obrigado.
 *   Seu pedido foi recebido." (WooCommerce prints the generic thank-you so
 *   order ids cannot be probed). A shopper who followed a broken link reads
 *   that an order went through. Here it says the order was not found — the
 *   same sentence for a bad id and a bad key, so nothing is given away.
 * - order-pay with an invalid order prints one error ("Esse pedido é inválido
 *   e não pode ser pago.") and nothing else: a page titled "Checkout" with no
 *   way on. Both get two ways out, the account's orders and the shop.
 */
final class OrderEndpoints {

	/** Marks our "not found" sentence, so the page knows to add the way out. */
	private const MISSING = 'galaxie-order-missing';

	public static function hooks(): void {
		add_filter( 'woocommerce_thankyou_order_received_text', array( self::class, 'order_received_text' ), 20, 2 );
	}

	/**
	 * @param string          $text  WooCommerce's (escaped) thank-you sentence.
	 * @param \WC_Order|false $order False when WooCommerce has no order to show.
	 */
	public static function order_received_text( $text, $order ) {
		if ( $order instanceof \WC_Order || ! self::order_missing() ) {
			return $text;
		}

		// The links follow the page (dead_end_links()), as on order-pay.
		return sprintf( '<span class="%1$s">%2$s</span>', esc_attr( self::MISSING ), esc_html__( 'Não encontramos este pedido.', 'galaxie-woo' ) );
	}

	/**
	 * Is the order in the URL unknown, or its key wrong? False is also what
	 * WooCommerce passes for a real order it will not show yet (another
	 * account's, or a guest's awaiting e-mail verification); that one keeps
	 * WooCommerce's own text and the sign-in or verify form under it.
	 */
	private static function order_missing(): bool {
		global $wp;

		$id  = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, as WooCommerce reads it.

		$order = $id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;

		return ! $order instanceof \WC_Order || ! is_string( $key ) || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key );
	}

	/**
	 * The way out, after WooCommerce's own page, when that page has nothing
	 * else to offer: our "not found" on order-received, or an order-pay that
	 * ended in an error with no payment form, no receipt and no sign-in form.
	 *
	 * @param string $page The endpoint page as WooCommerce printed it.
	 */
	public static function dead_end_links( string $page ): string {
		$missing  = str_contains( $page, self::MISSING );
		$pay_dead = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' )
			&& str_contains( $page, 'woocommerce-error' )
			&& ! str_contains( $page, 'id="order_review"' )
			&& ! str_contains( $page, 'woocommerce-form-login' )
			&& ! str_contains( $page, 'woocommerce-order' );

		if ( ! $missing && ! $pay_dead ) {
			return '';
		}

		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );

		return sprintf(
			'<p class="galaxie-order-dead-end"><a class="button" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p>',
			esc_url( self::orders_url() ),
			esc_html__( 'Ver meus pedidos', 'galaxie-woo' ),
			esc_url( (string) $shop ),
			esc_html__( 'Voltar à loja', 'galaxie-woo' )
		);
	}

	private static function orders_url(): string {
		return function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'orders' ) : home_url( '/' );
	}

	/** For the endpoint pages, the notices stay where WooCommerce prints them (see ToastNotices). */
	public static function is_order_page(): bool {
		return CheckoutPage::is_order_endpoint() || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) );
	}
}

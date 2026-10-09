<?php
/**
 * WooCommerce's thank-you template with nothing drawn, only its hooks.
 *
 * Swapped in for `checkout/thankyou.php` by OrderReceived when the merchant's
 * own template draws the page. The hooks are the ones the original fires, in
 * its order, so payment methods behave as they would on the native page.
 *
 * @package Galaxie\Woo
 *
 * @var WC_Order|false $order
 */

defined( 'ABSPATH' ) || exit;

if ( ! $order instanceof WC_Order ) {
	return;
}

do_action( 'woocommerce_before_thankyou', $order->get_id() );
do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() );
do_action( 'woocommerce_thankyou', $order->get_id() );

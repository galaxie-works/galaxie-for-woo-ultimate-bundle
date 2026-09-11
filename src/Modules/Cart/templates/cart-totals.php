<?php
/**
 * The totals box WooCommerce's cart.js asks for, drawn by our own widget.
 *
 * WooCommerce's `get_cart_totals` and `update_shipping_method` endpoints answer
 * with `woocommerce_cart_totals()`, and cart.js swaps the result in for
 * `div.cart_totals`. Our totals box carries that class, so choosing a carrier
 * or removing a coupon used to replace it with WooCommerce's plain default
 * template in the middle of the page. The browser now sends the totals widget's
 * ids with those requests, and this file is served in place of the template.
 *
 * @package Galaxie\Woo
 */

defined( 'ABSPATH' ) || exit;

echo \Galaxie\Woo\Support\CartParts::totals_markup( \Galaxie\Woo\Modules\Cart\Module::request_settings( 'totals' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

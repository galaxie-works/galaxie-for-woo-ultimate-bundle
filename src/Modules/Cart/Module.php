<?php
/**
 * Cart experience module — the "Galaxie Cart" Elementor widget.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Cart;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Modules\Cart\Widget\CartWidget;
use Galaxie\Woo\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * The cart page as one widget the merchant drops in and configures, in the
 * same shape as the buy box: our markup, our controls, WooCommerce's rules.
 *
 * Nothing here reimplements a cart. The form carries the field names
 * WooCommerce's own `WC_Form_Handler::update_cart_action()` reads —
 * `cart[{key}][qty]`, the `woocommerce-cart` nonce, `update_cart` — so quantity
 * changes, removals and coupons are processed by WooCommerce on `wp_loaded`
 * before the page renders. XStore's cart widget posts to exactly the same
 * place, which is a good sign that it is the intended seam rather than a
 * workaround.
 *
 * The one thing added on top is the AJAX path below, because the native
 * behaviour — type a quantity, then find and click "Update cart", then watch
 * the page reload — is the same complaint that got add-to-cart rebuilt.
 */
final class Module implements ModuleContract, ProvidesElementorWidgets, ProvidesBootData {

	private const ACTION = 'galaxie_cart_update';
	private const NONCE  = 'galaxie-cart';

	public function id(): string {
		return 'cart';
	}

	public function title(): string {
		return __( 'Cart Experience', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'The cart page as an Elementor widget: item table, quantity, coupon and totals, styled through the same pixfort control sets as the product page.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( Assets::class, 'enqueue' ) );

		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax_update' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'ajax_update' ) );
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		return array( CartWidget::class );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function boot_data(): array {
		return array(
			'cart' => array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			),
		);
	}

	/**
	 * Change one line's quantity and hand back what changed.
	 *
	 * `WC_Cart::set_quantity()` is the same call WooCommerce's own form handler
	 * makes, so stock rules, `sold_individually` and the cart-updated hooks all
	 * behave identically — this is a different door into the same room, not a
	 * second set of rules.
	 *
	 * Removing is a quantity of zero, which is also WooCommerce's own
	 * convention, so the button and the stepper share one path.
	 */
	public function ajax_update(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Cart unavailable.', 'galaxie-woo' ) ) );
		}

		$key      = isset( $_POST['cart_item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) ) : '';
		$quantity = isset( $_POST['quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : null;

		if ( '' === $key || null === $quantity || ! WC()->cart->get_cart_item( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'That item is no longer in your cart.', 'galaxie-woo' ) ) );
		}

		WC()->cart->set_quantity( $key, (int) $quantity, true );
		WC()->cart->calculate_totals();

		$item = WC()->cart->get_cart_item( $key );

		wp_send_json_success(
			array(
				'removed'   => ! $item,
				'subtotal'  => $item ? WC()->cart->get_product_subtotal( $item['data'], $item['quantity'] ) : '',
				'count'     => WC()->cart->get_cart_contents_count(),
				'empty'     => WC()->cart->is_empty(),
				// The totals block is markup the widget owns, so the widget is
				// what re-renders it — the script only swaps what it is given.
				'totals'    => CartWidget::totals_markup(),
				// WooCommerce's own fragments, so a mini cart in the header
				// updates from the same response instead of going stale.
				'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
			)
		);
	}
}

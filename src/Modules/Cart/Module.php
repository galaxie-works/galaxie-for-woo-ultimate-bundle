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
use Galaxie\Woo\Modules\Cart\Widget\CartCountdownWidget;
use Galaxie\Woo\Modules\Cart\Widget\CartTableWidget;
use Galaxie\Woo\Modules\Cart\Widget\CartTotalsWidget;
use Galaxie\Woo\Modules\Cart\Widget\ShippingCalculatorWidget;
use Galaxie\Woo\Modules\Cart\Widget\CartWidget;
use Galaxie\Woo\Support\CartCountdown;
use Galaxie\Woo\Support\CartParts;
use Galaxie\Woo\Support\FreeShipping;
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

		// The countdown's anchor. Registered by the module rather than by the
		// widget because a widget only exists while a page renders, and the
		// clock has to start at the moment of the add — which happens on a
		// different request entirely.
		add_action( 'woocommerce_add_to_cart', array( CartCountdown::class, 'touch' ) );

		add_action( 'wp_loaded', array( $this, 'handle_shipping_calculator' ), 20 );
	}

	/**
	 * Process the shipping calculator on a cart built from widgets.
	 *
	 * WooCommerce handles `calc_shipping` in exactly one place:
	 * `WC_Shortcode_Cart::output()`, the [woocommerce_cart] shortcode. There is
	 * no form handler for it. A cart page assembled from Elementor widgets
	 * never runs that shortcode, so the calculator posted, got a 200, and
	 * nothing was saved — the postcode was gone on the next load and no rate
	 * was ever quoted. Found by submitting it for real on the test store.
	 *
	 * This does not reimplement the calculation. It calls WooCommerce's own
	 * public `WC_Shortcode_Cart::calculate_shipping()`, with the same nonce
	 * check the shortcode performs, and then clears `calc_shipping` so a page
	 * that DOES also render the shortcode does not process the same address a
	 * second time and print "Shipping costs updated" twice.
	 */
	public function handle_shipping_calculator(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified just below, as the shortcode does.
		if ( empty( $_POST['calc_shipping'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$nonce = isset( $_REQUEST['woocommerce-shipping-calculator-nonce'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['woocommerce-shipping-calculator-nonce'] ) )
			: ( isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! wp_verify_nonce( $nonce, 'woocommerce-shipping-calculator' ) && ! wp_verify_nonce( $nonce, 'woocommerce-cart' ) ) {
			return;
		}

		if ( ! class_exists( '\WC_Shortcode_Cart' ) && defined( 'WC_ABSPATH' ) ) {
			include_once WC_ABSPATH . 'includes/shortcodes/class-wc-shortcode-cart.php';
		}

		if ( ! class_exists( '\WC_Shortcode_Cart' ) ) {
			return;
		}

		\WC_Shortcode_Cart::calculate_shipping();
		WC()->cart->calculate_totals();

		unset( $_POST['calc_shipping'] );
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		return array( CartWidget::class, CartTableWidget::class, CartTotalsWidget::class, CartCountdownWidget::class, ShippingCalculatorWidget::class );
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
	/**
	 * The settings of the widget that drew the totals, found from the ids the
	 * browser read off the page.
	 *
	 * Without this the rows came back from an update styled by nothing, which
	 * is why they used to be driven by CSS selectors while the rest of the cart
	 * used pixfort's classes.
	 *
	 * @return array<string,mixed>
	 */
	private static function widget_settings(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the request is nonce-checked at the top of ajax_update().
		$post_id    = isset( $_POST['elementor_post'] ) ? (int) $_POST['elementor_post'] : 0;
		$element_id = isset( $_POST['element_id'] ) ? sanitize_key( wp_unslash( $_POST['element_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return CartParts::element_settings( $post_id, $element_id );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function free_shipping_state(): ?array {
		$threshold = isset( $_POST['free_shipping_threshold'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the request is nonce-checked by the caller.
			? (float) wp_unslash( $_POST['free_shipping_threshold'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to float.
			: FreeShipping::threshold();

		if ( $threshold <= 0 ) {
			return null;
		}

		$state = FreeShipping::state( $threshold );

		return array(
			'percent'   => round( $state['percent'], 2 ),
			'achieved'  => $state['achieved'],
			'remaining' => wp_strip_all_tags( wc_price( $state['remaining'] ) ),
		);
	}

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
				// Only the amounts. This endpoint has no widget settings, so
				// re-rendering the whole totals box from here would hand back
				// one stripped of the background, radius and checkout button
				// the merchant configured — see CartParts::rows_markup().
				'totals'    => CartParts::rows_markup( self::widget_settings() ),
				// Numbers only, for the free shipping line the widget already
				// rendered — the sentence and the styling stay on the page,
				// because this request has no widget settings to rebuild them
				// from. Threshold comes from WooCommerce here; a widget set to
				// a typed one sends its own along with the request.
				'freeShipping' => self::free_shipping_state(),
				// WooCommerce's own fragments, so a mini cart in the header
				// updates from the same response instead of going stale.
				'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
			)
		);
	}
}

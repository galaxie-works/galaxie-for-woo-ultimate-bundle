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
use Galaxie\Woo\Modules\Cart\Widget\CartCouponWidget;
use Galaxie\Woo\Modules\Cart\Widget\ShippingOptionsWidget;
use Galaxie\Woo\Modules\Cart\Widget\FreeShippingProgressWidget;
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

	/**
	 * The calculator's own refusal, kept as a constant because two places need
	 * the exact sentence: the one that raises it, and the one that makes sure a
	 * shopper who never saw it cannot be stopped by it at the checkout.
	 */
	private const CALCULATOR_NOTICE = 'Informe um CEP válido para calcular o frete.';

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

		// A notice nobody printed is a notice nobody cleared, and the Store API
		// turns whatever is in the queue into a 409 the block checkout can only
		// describe as "an error occurred during payment processing". A shopper
		// who typed a bad postcode on the cart page an hour ago could not pay
		// for anything, in any cart, until the session expired.
		add_filter( 'rest_pre_dispatch', array( $this, 'drop_stale_calculator_notice' ), 10, 3 );

		// The coupon widget's messages, swapped in at WooCommerce's own filters.
		add_filter( 'woocommerce_coupon_message', array( $this, 'coupon_message' ), 10, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'coupon_error' ), 10, 3 );
		add_filter( 'woocommerce_add_success', array( $this, 'coupon_removed_notice' ) );

		// Our totals box, not WooCommerce's default, in the answer cart.js gets
		// after a carrier is chosen or a coupon removed.
		add_filter( 'wc_get_template', array( $this, 'totals_template' ), 10, 2 );
	}

	/**
	 * Marks the queue so the cart page prints what the calculator just said.
	 *
	 * A cart assembled from Elementor widgets runs no WooCommerce template, so
	 * nothing calls `woocommerce_output_all_notices()` and the notice waits in
	 * the session for a page that never comes.
	 */
	private function print_notices_next(): void {
		add_action( 'galaxie_cart_before_table', 'woocommerce_output_all_notices', 5 );
	}

	/**
	 * Drops the calculator's refusal from a Store API request it cannot belong
	 * to: that endpoint takes JSON, never `calc_shipping`, so anything of ours
	 * in the queue there was raised by an earlier request and never shown.
	 *
	 * @param mixed            $result  Whatever an earlier filter decided.
	 * @param \WP_REST_Server  $server  Unused.
	 * @param \WP_REST_Request $request The request about to run.
	 * @return mixed
	 */
	public function drop_stale_calculator_notice( $result, $server, $request ) {
		if ( ! function_exists( 'wc_get_notices' ) || ! is_object( $request ) || 0 !== strpos( (string) $request->get_route(), '/wc/store/' ) ) {
			return $result;
		}

		$errors = wc_get_notices( 'error' );

		if ( ! $errors ) {
			return $result;
		}

		$kept = array_values(
			array_filter(
				$errors,
				static fn( $notice ): bool => self::CALCULATOR_NOTICE !== trim( wp_strip_all_tags( (string) ( $notice['notice'] ?? '' ) ) )
			)
		);

		if ( count( $kept ) !== count( $errors ) ) {
			$all          = wc_get_notices();
			$all['error'] = $kept;
			wc_set_notices( $all );
		}

		return $result;
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

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$country = isset( $_POST['calc_shipping_country'] ) ? wc_clean( wp_unslash( $_POST['calc_shipping_country'] ) ) : '';

		// A calculator showing only the CEP sends no usable country. WooCommerce
		// reads an empty one as "reset to the store's address", which throws the
		// CEP away, so the store's own country stands in.
		if ( '' === $country || 'default' === $country ) {
			$country                        = WC()->countries->get_base_country();
			$_POST['calc_shipping_country'] = $country;
		}

		if ( 'BR' === $country ) {
			$postcode = isset( $_POST['calc_shipping_postcode'] ) ? wc_clean( wp_unslash( $_POST['calc_shipping_postcode'] ) ) : '';

			// Every carrier quotes by CEP. Accepting the form without one saved
			// "Shipping to Sao Paulo." and quoted nothing, which left a free
			// shipping method as the only option on the page.
			if ( ! \Galaxie\Woo\Support\BrazilianPostcode::is_valid( $postcode ) ) {
				wc_add_notice( __( self::CALCULATOR_NOTICE, 'galaxie-woo' ), 'error' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- one sentence, two readers.
				$this->print_notices_next();
				unset( $_POST['calc_shipping'] );
				return;
			}

			$state = \Galaxie\Woo\Support\BrazilianPostcode::state( $postcode );

			if ( null !== $state ) {
				$_POST['calc_shipping_state'] = $state;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

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

	/**
	 * WooCommerce coupon codes, grouped by what a shopper needs to hear.
	 *
	 * @see CartCouponWidget::messages()
	 */
	private const COUPON_MESSAGES = array(
		200 => 'msg_applied',
		201 => 'msg_removed',
		111 => 'msg_empty',
		105 => 'msg_not_exist',
		107 => 'msg_expired',
		106 => 'msg_usage_limit',
		115 => 'msg_usage_limit',
		116 => 'msg_usage_limit',
		103 => 'msg_already',
		104 => 'msg_individual',
		108 => 'msg_min',
		112 => 'msg_max',
		109 => 'msg_not_applicable',
		110 => 'msg_not_applicable',
		113 => 'msg_not_applicable',
		114 => 'msg_not_applicable',
		100 => 'msg_invalid',
		101 => 'msg_invalid',
		102 => 'msg_invalid',
	);

	/**
	 * Does this request name a widget of this kind (`coupon`, `totals`)?
	 */
	public static function has_request( string $kind ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only chooses which saved widget styles a response is drawn with.
		return ! empty( $_REQUEST[ "galaxie_{$kind}_post" ] ) && ! empty( $_REQUEST[ "galaxie_{$kind}_element" ] );
	}

	/**
	 * The saved settings of the widget a request came from.
	 *
	 * @return array<string,mixed>
	 */
	public static function request_settings( string $kind ): array {
		static $cache = array();

		if ( isset( $cache[ $kind ] ) ) {
			return $cache[ $kind ];
		}

		if ( ! self::has_request( $kind ) ) {
			return array();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, see has_request().
		$post_id    = absint( $_REQUEST[ "galaxie_{$kind}_post" ] );
		$element_id = sanitize_key( wp_unslash( $_REQUEST[ "galaxie_{$kind}_element" ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$cache[ $kind ] = CartParts::element_settings( $post_id, $element_id );

		return $cache[ $kind ];
	}

	/**
	 * @param string          $message
	 * @param int             $code
	 * @param \WC_Coupon|null $coupon
	 */
	public function coupon_message( $message, $code, $coupon ): string {
		return self::coupon_text( (string) $message, (int) $code, $coupon );
	}

	/**
	 * @param string          $error
	 * @param int             $code
	 * @param \WC_Coupon|null $coupon
	 */
	public function coupon_error( $error, $code, $coupon ): string {
		return self::coupon_text( (string) $error, (int) $code, $coupon );
	}

	/**
	 * The merchant's text for this code, or WooCommerce's when there is none.
	 *
	 * Only for requests that came from a coupon widget. Everywhere else,
	 * checkout included, WooCommerce says what it always said.
	 *
	 * @param \WC_Coupon|null $coupon
	 */
	private static function coupon_text( string $original, int $code, $coupon ): string {
		$key = self::COUPON_MESSAGES[ $code ] ?? '';

		if ( '' === $key || ! self::has_request( 'coupon' ) ) {
			return $original;
		}

		$custom = trim( (string) ( self::request_settings( 'coupon' )[ $key ] ?? '' ) );

		if ( '' === $custom ) {
			return $original;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified this request before raising the message.
		$typed = isset( $_POST['coupon_code'] ) ? wc_format_coupon_code( wp_unslash( $_POST['coupon_code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$coupon_code = $coupon instanceof \WC_Coupon ? $coupon->get_code() : $typed;
		$amount      = '';

		if ( $coupon instanceof \WC_Coupon && 108 === $code ) {
			$amount = wc_price( (float) $coupon->get_minimum_amount() );
		} elseif ( $coupon instanceof \WC_Coupon && 112 === $code ) {
			$amount = wc_price( (float) $coupon->get_maximum_amount() );
		}

		return str_replace( array( '{code}', '{amount}' ), array( esc_html( $coupon_code ), $amount ), esc_html( $custom ) );
	}

	/**
	 * "Coupon has been removed." is added straight to the notices by
	 * WooCommerce's `remove_coupon` endpoint, not through the coupon filters,
	 * so it is caught here instead.
	 *
	 * @param string $message
	 */
	public function coupon_removed_notice( $message ): string {
		$message = (string) $message;

		if ( ! self::has_request( 'coupon' ) || __( 'Coupon has been removed.', 'woocommerce' ) !== $message ) {
			return $message;
		}

		$custom = trim( (string) ( self::request_settings( 'coupon' )['msg_removed'] ?? '' ) );

		if ( '' === $custom ) {
			return $message;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified this request before adding the notice.
		$coupon = isset( $_POST['coupon'] ) ? wc_format_coupon_code( wp_unslash( $_POST['coupon'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return str_replace( '{code}', esc_html( $coupon ), esc_html( $custom ) );
	}

	/**
	 * Serve our totals box when WooCommerce renders its totals template for a
	 * request that named our totals widget.
	 *
	 * @param string $template
	 * @param string $template_name
	 */
	public function totals_template( $template, $template_name ): string {
		if ( 'cart/cart-totals.php' !== $template_name || ! self::has_request( 'totals' ) ) {
			return (string) $template;
		}

		return __DIR__ . '/templates/cart-totals.php';
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		return array( CartWidget::class, CartTableWidget::class, CartTotalsWidget::class, CartCountdownWidget::class, ShippingCalculatorWidget::class, CartCouponWidget::class, ShippingOptionsWidget::class, FreeShippingProgressWidget::class );
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
			// wc_price() encodes the currency symbol (R$ arrives as &#82;&#36;)
			// and the browser writes this with textContent, which prints
			// entities literally. Stripping the tags alone left "Faltam
			// &#82;&#36;290,20" on the page after the first quantity change.
			'remaining' => html_entity_decode( wp_strip_all_tags( wc_price( $state['remaining'] ) ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ),
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

		$values = WC()->cart->get_cart_item( $key );
		$remove = ! empty( $_POST['remove'] ); // The remove link, not the stepper.

		// Same sanitising filter WC_Form_Handler::update_cart_action() applies.
		$quantity = apply_filters( 'woocommerce_stock_amount_cart_item', $quantity, $key );

		// A removal is never validated: not from the remove link (WooCommerce's
		// own remove link skips it too) and not from a stepper taken down to
		// zero, which the quantity field allows. Minimum and pack-size rules
		// commonly reject zero, and they are there to shape a quantity, not to
		// keep an unwanted product in the cart. Every other change goes through
		// the checks WooCommerce's cart form runs.
		if ( ! $remove && $quantity > 0 && $quantity !== $values['quantity'] ) {
			// Notices already queued for the shopper (other plugins, earlier
			// cart actions) are set aside and put back afterwards, so only
			// errors raised by this validation are read, and none are lost.
			$notices_before = wc_get_notices();
			wc_clear_notices();

			$passed = apply_filters( 'woocommerce_update_cart_validation', true, $key, $values, $quantity );

			if ( $values['data']->is_sold_individually() && $quantity > 1 ) {
				/* translators: %s: product name. */
				wc_add_notice( sprintf( __( 'You can only have 1 %s in your cart.', 'woocommerce' ), $values['data']->get_name() ), 'error' );
				$passed = false;
			}

			$messages = array();

			foreach ( wc_get_notices( 'error' ) as $notice ) {
				$messages[] = wp_strip_all_tags( is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice );
			}

			wc_set_notices( $notices_before );

			if ( ! $passed ) {

				wp_send_json_error(
					array(
						'message'  => implode( ' ', array_filter( $messages ) ) ?: __( 'That quantity could not be saved.', 'galaxie-woo' ),
						// What the cart still holds, so the stepper can go back to it.
						'quantity' => (float) $values['quantity'],
					)
				);
			}
		}

		WC()->cart->set_quantity( $key, (int) $quantity, true );
		WC()->cart->calculate_totals();

		$item = WC()->cart->get_cart_item( $key );

		ob_start();
		woocommerce_mini_cart();
		$mini_cart = ob_get_clean();

		wp_send_json_success(
			array(
				'removed'   => ! $item,
				// A removal can take other lines with it (a gift's accessories), so
				// the table is told what is left rather than only what went.
				'keys'      => array_map( 'strval', array_keys( WC()->cart->get_cart() ) ),
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
				// WooCommerce's own fragments, built the way
				// WC_AJAX::get_refreshed_fragments() builds them (mini cart
				// included), so a mini cart in the header updates from the
				// same response instead of going stale.
				'fragments' => apply_filters(
					'woocommerce_add_to_cart_fragments',
					array(
						'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
					)
				),
				'cart_hash' => WC()->cart->get_cart_hash(),
			)
		);
	}
}

<?php
/**
 * Toast notices module — WooCommerce notices rendered as modern toasts.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ToastNotices;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Modules\Checkout\OrderEndpoints;
use Galaxie\Woo\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * A global (non-widget) module: it loads the bundle on the front-end and flags
 * `toastNotices` so the JS boots the WC-notice interceptor. First module ported
 * from eir-my-account-ux (assets/js/toast-notices.js), and the one that proves
 * the module + boot-data + asset pipeline end to end.
 */
final class Module implements ModuleContract, ProvidesBootData {

	public function id(): string {
		return 'toast-notices';
	}

	public function title(): string {
		return __( 'Toast Notices', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Intercepts WooCommerce notices and re-renders them as toasts.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( Assets::class, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'print_leftover_notices' ), 5 );

		// A page carrying a shopper's notices is that shopper's page.
		add_action( 'template_redirect', array( self::class, 'no_cache_with_notices' ), 1 );
	}

	/**
	 * Keeps LiteSpeed (and any page cache) from storing a page that prints
	 * someone's notices — "X foi adicionado ao carrinho", a coupon error — and
	 * serving it to the next visitor. LSCWP decides when the page is done
	 * (its output buffer), so the call still counts from the footer; the
	 * headers are sent when they still can be.
	 */
	public static function no_cache_with_notices(): void {
		if ( ! function_exists( 'wc_notice_count' ) || ! function_exists( 'WC' ) || ! WC()->session || 0 === wc_notice_count() ) {
			return;
		}

		do_action( 'litespeed_control_set_nocache', 'galaxie leftover notices' );

		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/**
	 * Notices no template printed, printed at the end of the page, where the
	 * interceptor turns them into toasts.
	 *
	 * Pages built from Elementor widgets never run the templates that print
	 * WooCommerce's notices, so they stayed in the session and piled up: every
	 * "Shipping costs updated." from the cart calculator, every "added to your
	 * cart". Nothing showed them until the checkout's first `update_order_review`
	 * after signing in, which returns pending notices as a failure — the whole
	 * pile at once, as toasts, on the page where the shopper had just typed a
	 * code. Shown here, each one appears on the page that caused it.
	 *
	 * Checkout is left alone: its own form and AJAX print (and judge) notices.
	 * So are the order pages, whose shortcode prints its notices in place.
	 */
	public function print_leftover_notices(): void {
		if ( is_admin() || ! function_exists( 'wc_notice_count' ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() && is_user_logged_in() ) {
			return;
		}
		if ( OrderEndpoints::is_order_page() || 0 === wc_notice_count() ) {
			return;
		}

		self::no_cache_with_notices();

		echo '<div class="woocommerce-notices-wrapper galaxie-leftover-notices" hidden>';
		wc_print_notices();
		echo '</div>';
	}

	/**
	 * Off on the order pages (order-pay, order-received, view-order): there a
	 * notice is often the whole page ("Esse pedido é inválido…"), and as a
	 * toast it vanished and left an empty page behind. `showToast()` from
	 * module code works either way.
	 */
	public function boot_data(): array {
		return array( 'toastNotices' => ! OrderEndpoints::is_order_page() );
	}
}

<?php
/**
 * Toast notices module — WooCommerce notices rendered as modern toasts.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ToastNotices;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
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
	 */
	public function print_leftover_notices(): void {
		if ( is_admin() || ! function_exists( 'wc_notice_count' ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() && is_user_logged_in() ) {
			return;
		}
		if ( 0 === wc_notice_count() ) {
			return;
		}

		echo '<div class="woocommerce-notices-wrapper galaxie-leftover-notices" hidden>';
		wc_print_notices();
		echo '</div>';
	}

	public function boot_data(): array {
		return array( 'toastNotices' => true );
	}
}

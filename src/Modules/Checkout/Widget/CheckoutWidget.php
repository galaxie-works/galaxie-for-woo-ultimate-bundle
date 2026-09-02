<?php
/**
 * "Galaxie Checkout" Elementor widget — the self-contained checkout stepper.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Checkout\Widget;

use Galaxie\Woo\Elementor\AbstractIslandWidget;
use Galaxie\Woo\Support\CustomerProfile;

defined( 'ABSPATH' ) || exit;

/**
 * Self-contained by design (locked in the Phase 1 plan): this widget renders
 * WooCommerce's own `[woocommerce_checkout]` output — hidden — alongside the
 * stepper island, and the island's JS moves specific live nodes out of the
 * hidden native form (`#shipping_method`, `#payment`) into its own step
 * mounts, mirroring form values into the native (hidden) fields the rest of
 * WooCommerce still submits against. Same mechanism proven in
 * eir-my-account-ux, just hosted by our own widget instead of the theme's
 * checkout widget — so it drops onto ANY page/theme, no XStore dependency.
 *
 * Requires WooCommerce's "Enable guest checkout" setting ON: the native
 * shortcode must render its field markup for a not-yet-authenticated visitor
 * (our OTP/Google login happens after the page has already loaded); once the
 * customer verifies, we log them in server-side and reload, at which point
 * the widget re-renders with `loggedIn: true` and the stepper skips ahead.
 */
final class CheckoutWidget extends AbstractIslandWidget {

	public function get_name(): string {
		return 'galaxie-checkout';
	}

	public function get_title(): string {
		return __( 'Galaxie Checkout', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-cart-medium';
	}

	protected function island_name(): string {
		return 'checkout';
	}

	protected function island_props(): array {
		$logged_in = is_user_logged_in();
		$user      = $logged_in ? wp_get_current_user() : null;

		return array(
			'loggedIn' => $logged_in,
			'userEmail' => $user ? $user->user_email : '',
			'profile'  => $logged_in ? CustomerProfile::status( $user->ID ) : array(
				'complete' => false,
				'missing'  => array(),
				'values'   => array(),
			),
			'address'  => $logged_in ? CustomerProfile::saved_address( $user->ID ) : array( 'has_address' => false ),
			'i18n'     => array(
				'genericError' => __( 'Something went wrong. Please try again.', 'galaxie-woo' ),
				'noShipping'   => __( 'No shipping options are available for this address. Please check the address and try again.', 'galaxie-woo' ),
			),
		);
	}

	protected function render(): void {
		if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			echo '<div style="padding:2rem;text-align:center;border:1px dashed #ccc;border-radius:8px;">';
			esc_html_e( 'Galaxie Checkout — renders the live checkout stepper on the front-end.', 'galaxie-woo' );
			echo '</div>';
			return;
		}

		if ( ! function_exists( 'is_checkout' ) || null === WC()->cart || WC()->cart->is_empty() ) {
			echo '<p>' . esc_html__( 'Your cart is empty.', 'galaxie-woo' ) . '</p>';
			return;
		}

		echo '<div class="galaxie-checkout">';
		echo '<div class="galaxie-checkout-native" data-galaxie-native-checkout hidden>';
		echo do_shortcode( '[woocommerce_checkout]' );
		echo '</div>';

		parent::render(); // Enqueues assets + prints the `data-galaxie-island="checkout"` mount.

		echo '</div>';
	}
}

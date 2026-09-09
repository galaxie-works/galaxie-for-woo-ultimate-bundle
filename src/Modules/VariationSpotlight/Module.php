<?php
/**
 * Variation Spotlight module — an Elementor widget that showcases one specific
 * product variation, chosen by the admin from a dropdown.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\VariationSpotlight;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Modules\VariationSpotlight\Widget\VariationSpotlightWidget;

defined( 'ABSPATH' ) || exit;

/**
 * Distinct from {@see \Galaxie\Woo\Modules\VariationSwatches\Module}: that one
 * is the interactive selector a customer uses on the actual product page.
 * This one is a static, curated block — drop it anywhere (a landing page, the
 * homepage, a promo section) to feature ONE fixed variation (its image, price,
 * a buy button), picked once by the admin in the Elementor editor, independent
 * of whatever the customer might pick elsewhere.
 */
final class Module implements ModuleContract, ProvidesElementorWidgets, ProvidesBootData {

	public const AJAX_ACTION  = 'galaxie_spotlight_add_to_cart';
	public const NONCE_ACTION = 'galaxie_woo_spotlight';

	public function id(): string {
		return 'variation-spotlight';
	}

	public function title(): string {
		return __( 'Variation Spotlight', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Elementor widget that showcases one specific product variation (image, price, buy button) anywhere on the site.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function boot(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		// The widget can land on any page (landing pages, home…), not just a
		// product page, so — unlike VariationSwatches — this can't be gated to
		// is_product(); always available on the front-end.
		\Galaxie\Woo\Support\Assets::enqueue();
	}

	public function elementor_widgets(): array {
		return array( VariationSpotlightWidget::class );
	}

	public function boot_data(): array {
		return array(
			'variationSpotlight' => array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			),
		);
	}

	/**
	 * Adds the exact variation the widget points at to the cart, mirroring
	 * WooCommerce's own `WC_AJAX::add_to_cart()` (same filters/actions/response
	 * shape) so third-party cart-fragment integrations keep working — the only
	 * difference is we already know the variation's attributes server-side, so
	 * the customer never has to re-select anything.
	 */
	public function ajax_add_to_cart(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$quantity     = isset( $_POST['quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : 1;
		$variation    = $variation_id ? wc_get_product( $variation_id ) : null;

		if ( ! $variation || 'variation' !== $variation->get_type() ) {
			wp_send_json_error( array( 'message' => __( 'Variação inválida.', 'galaxie-woo' ) ) );
		}

		$parent_id  = $variation->get_parent_id();
		$attributes = $variation->get_variation_attributes();

		$passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $parent_id, $quantity, $variation_id, $attributes );

		if ( ! $passed_validation ) {
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			wp_send_json_error( array(
				'message' => $notices ? wp_strip_all_tags( $notices[0]['notice'] ) : __( 'Não foi possível adicionar ao carrinho.', 'galaxie-woo' ),
			) );
		}

		$cart_item_key = WC()->cart->add_to_cart( $parent_id, $quantity, $variation_id, $attributes );

		if ( ! $cart_item_key ) {
			wp_send_json_error( array( 'message' => __( 'Não foi possível adicionar ao carrinho.', 'galaxie-woo' ) ) );
		}

		do_action( 'woocommerce_ajax_added_to_cart', $parent_id );

		wp_send_json_success( array(
			'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
			'cart_hash' => WC()->cart->get_cart_hash(),
		) );
	}
}

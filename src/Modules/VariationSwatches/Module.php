<?php
/**
 * Variation Swatches module — turns variation <select> dropdowns into clickable badges.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\VariationSwatches;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Modules\VariationSwatches\Widget\BuyBoxWidget;
use Galaxie\Woo\Modules\VariationSwatches\Widget\VariationBadgesWidget;
use Galaxie\Woo\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * A pure progressive-enhancement module, not a widget: WooCommerce's native
 * product page already renders a `<select>` per variation attribute (e.g.
 * "Peso" → 50g/190g) and its own JS reads that select's value to match a
 * variation and recalculate price/stock/image. We don't replace any of that —
 * we hide the select (kept in the DOM, still the source of truth) and render
 * badges next to it that set the select's value + dispatch `change`, so every
 * bit of WooCommerce's and the theme's own variation logic keeps working
 * untouched. Styling is pixfort-token-aware (falls back to neutral values on
 * any other theme), matching the rest of the bundle's design system.
 *
 * Ships theme-independent on purpose — this was going to be "install YITH or
 * ShopLentor for swatches", but the whole point of the bundle is not needing
 * a third-party suite for a WooCommerce storefront behavior this contained.
 *
 * Also ships an Elementor widget ({@see VariationBadgesWidget}) for when the
 * automatic behavior above (which just skins whatever `<select>` already
 * exists on the page) isn't enough — Wagner wanted the label/badges
 * themselves to be styled through pixfort's own Text/Badge component system
 * (the same global color dropdown — Primary, Gray 1-9, Dynamic Colors — every
 * other pixfort widget uses), not a separate hardcoded palette. That widget
 * literally renders through pixfort-core's own component functions.
 */
final class Module implements ModuleContract, ProvidesBootData, ProvidesElementorWidgets, ProvidesSettings {

	public const AJAX_ACTION  = 'galaxie_variation_add_to_cart';
	public const NONCE_ACTION = 'galaxie_woo_variation_badges';

	public function id(): string {
		return 'variation-swatches';
	}

	public function title(): string {
		return __( 'Variation Swatches', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Turns variation dropdowns (weight, color, size…) into clickable badges on the product page.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		// Off by default — it visibly changes the native product page the
		// moment it's on, so that should be a deliberate opt-in.
		return false;
	}

	/** Hidden field the Buy Now button flips before submitting WooCommerce's own form. */
	public const BUY_NOW_FIELD = 'galaxie_buy_now';

	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_add_to_cart' ) );

		// Buy Now deliberately does NOT use our AJAX endpoint: it submits
		// WooCommerce's own form so every native validation, stock check and
		// third-party add-to-cart hook still applies, then this filter sends
		// the shopper to checkout. Add to Cart stays on AJAX (no navigation,
		// mini-cart refresh); Buy Now navigates away anyway, so there is
		// nothing to gain from AJAX and a lot of compatibility to lose.
		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'print_buy_now_field' ) );
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'buy_now_redirect' ) );
	}

	public function print_buy_now_field(): void {
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( self::BUY_NOW_FIELD ) );
	}

	public function buy_now_redirect( $url ) {
		return empty( $_REQUEST[ self::BUY_NOW_FIELD ] ) ? $url : wc_get_checkout_url(); // phpcs:ignore WordPress.Security.NonceVerification -- read-only flag; WooCommerce already validated the add-to-cart request itself.
	}

	/**
	 * Adds the currently-selected variation to the cart for the Galaxie
	 * Variation Badges widget's own Add to Cart / Buy Now buttons — mirrors
	 * WooCommerce's own `WC_AJAX::add_to_cart()` (same filters/actions/
	 * response shape) so third-party cart-fragment integrations keep working,
	 * same pattern already proven in Modules/VariationSpotlight.
	 */
	public function ajax_add_to_cart(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$quantity     = isset( $_POST['quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : 1;
		$variation    = $variation_id ? wc_get_product( $variation_id ) : null;

		if ( ! $variation || 'variation' !== $variation->get_type() ) {
			wp_send_json_error( array( 'message' => __( 'Selecione uma variação válida.', 'galaxie-woo' ) ) );
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
			'fragments'    => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
			'cart_hash'    => WC()->cart->get_cart_hash(),
			'checkout_url' => wc_get_checkout_url(),
		) );
	}

	public function maybe_enqueue(): void {
		if ( function_exists( 'is_product' ) && is_product() ) {
			Assets::enqueue();
		}
	}

	/**
	 * VariationBadgesWidget stays registered only so templates already built on
	 * it keep rendering — {@see BuyBoxWidget} replaces it. Do not add features
	 * to the old one.
	 */
	public function elementor_widgets(): array {
		return array( BuyBoxWidget::class, VariationBadgesWidget::class );
	}

	public function boot_data(): array {
		$values = Plugin::instance()->settings()->module_settings( $this->id() );

		return array(
			'variationSwatches' => array(
				'attributes' => $this->attribute_slugs( $values ),
				'buyBox'     => array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				),
			),
		);
	}

	public function settings_tab_label(): string {
		return __( 'Variation Swatches', 'galaxie-woo' );
	}

	/** @return Field[] */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'attributes',
				label: __( 'Attributes', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'Comma-separated attribute slugs to render as badges (e.g. pa_peso, pa_cor). Every other variation attribute keeps the native dropdown.', 'galaxie-woo' ),
				default: 'pa_peso',
				placeholder: 'pa_peso'
			),
		);
	}

	public function render_extra_settings( array $values ): void {}

	public function sanitize_settings( array $submitted, array $current ): array {
		return Field::sanitize_all( $this->settings_fields(), $submitted );
	}

	/** @param array<string,mixed> $values */
	private function attribute_slugs( array $values ): array {
		$raw = (string) ( $values['attributes'] ?? 'pa_peso' );
		$slugs = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		return array_values( $slugs );
	}
}

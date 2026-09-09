<?php
/**
 * Quantity Discounts module — tiered "buy more, pay less" pricing.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\QuantityDiscounts;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Modules\QuantityDiscounts\Widget\QuantityDiscountsWidget;

defined( 'ABSPATH' ) || exit;

/**
 * Tiered per-line pricing: "3+ unidades → 5% off". Tiers are configured
 * globally on the module's settings tab and can be overridden per product on
 * its own "Quantity Discounts" product-data tab.
 *
 * The discount is applied by mutating the cart item's price in
 * `woocommerce_before_calculate_totals`, which is the ONE hook where a price
 * change is picked up by everything downstream: line subtotals, order totals,
 * tax calculation, and the WooCommerce Blocks / Store API cart. The competitor
 * implementation we studied instead wrote a discounted price into the cart
 * session and then patched the display filters (`woocommerce_cart_subtotal`,
 * `woocommerce_cart_item_price`, …) one by one, which produces three real bugs
 * we deliberately do not reproduce: tax computed on the undiscounted price
 * within the same request, the checkout's `edit` context reading straight
 * through the display filters, and Blocks/Store API never seeing the discount
 * at all because it does not go through those filters.
 */
final class Module implements ModuleContract, ProvidesElementorWidgets, ProvidesSettings {

	public const META_TIERS = '_galaxie_quantity_discount_tiers';
	public const META_TYPE  = '_galaxie_quantity_discount_type';

	public const TYPE_PERCENTAGE = 'percentage';
	public const TYPE_FIXED      = 'fixed';

	private const PANEL_TARGET = 'galaxie_quantity_discounts_data';

	/**
	 * Undiscounted price per product/variation id, for this request only.
	 *
	 * @var array<int,float|null>
	 */
	private array $original_prices = array();

	public function id(): string {
		return 'quantity-discounts';
	}

	public function title(): string {
		return __( 'Quantity Discounts', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Tiered per-line pricing ("3+ units, 5% off"), globally or per product, with an Elementor widget for the tier table.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		// Off by default — it changes what customers are charged the moment it
		// is on, so that has to be a deliberate opt-in.
		return false;
	}

	public function boot(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'register_product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );

		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_discounts' ), 20 );
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		return array( QuantityDiscountsWidget::class );
	}

	public function settings_tab_label(): string {
		return __( 'Quantity Discounts', 'galaxie-woo' );
	}

	/** @return Field[] */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'tiers',
				label: __( 'Tiers', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'One rule per line, written as minQty:discount — for example "3:5" then "6:10" means 5% off from 3 units and 10% off from 6. On this single-line field you can also separate the rules with commas ("3:5, 6:10"). Malformed rules are ignored. Any product can override this list on its own Quantity Discounts tab.', 'galaxie-woo' ),
				default: '',
				placeholder: '3:5, 6:10'
			),
			new Field(
				key: 'type',
				label: __( 'Discount type', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'How the number after the colon is read: as a percentage off the unit price, or as a fixed amount off it in the store currency.', 'galaxie-woo' ),
				default: self::TYPE_PERCENTAGE,
				options: array(
					self::TYPE_PERCENTAGE => __( 'Percentage off (%)', 'galaxie-woo' ),
					self::TYPE_FIXED      => __( 'Fixed amount off', 'galaxie-woo' ),
				)
			),
			new Field(
				key: 'apply_to_variations',
				label: __( 'Apply to variations', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Tiers are always evaluated per cart line: a line of 3× "Vela 190g" qualifies for the 3+ tier, but 2× 50g plus 2× 190g are two separate lines of 2 and neither qualifies. Turn this off to leave variation lines at full price and discount only simple products.', 'galaxie-woo' ),
				default: true
			),
		);
	}

	public function render_extra_settings( array $values ): void {}

	public function sanitize_settings( array $submitted, array $current ): array {
		return Field::sanitize_all( $this->settings_fields(), $submitted );
	}

	/**
	 * The tiers that actually apply to one product: its own override if it has
	 * one, otherwise the global list. Public because
	 * {@see QuantityDiscountsWidget} renders exactly what this returns.
	 *
	 * @return array{tiers:array<int,array{min:int,value:float}>,type:string}
	 */
	public function tiers_for_product( \WC_Product $product ): array {
		// A variation never carries its own tiers — the panel lives on the
		// parent product, so that is where the override is read from.
		$meta_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		$tiers = $this->parse_tiers( (string) get_post_meta( $meta_id, self::META_TIERS, true ) );
		$type  = (string) get_post_meta( $meta_id, self::META_TYPE, true );

		$settings = $this->settings();

		if ( ! $tiers ) {
			$tiers = $this->parse_tiers( (string) ( $settings['tiers'] ?? '' ) );
			$type  = '';
		}

		if ( '' === $type ) {
			$type = (string) ( $settings['type'] ?? self::TYPE_PERCENTAGE );
		}

		return array(
			'tiers' => $tiers,
			'type'  => self::TYPE_FIXED === $type ? self::TYPE_FIXED : self::TYPE_PERCENTAGE,
		);
	}

	/** One unit price with a tier's discount applied. */
	public function apply_tier( float $original, float $value, string $type ): float {
		$new = self::TYPE_FIXED === $type
			? max( 0.0, $original - $value )
			: $original * ( 1 - $value / 100 );

		return (float) wc_format_decimal( $new, wc_get_price_decimals() );
	}

	/**
	 * Rewrites each cart line's unit price before WooCommerce totals the cart.
	 *
	 * @param \WC_Cart $cart
	 */
	public function apply_discounts( $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		$settings      = $this->settings();
		$do_variations = ! array_key_exists( 'apply_to_variations', $settings ) || ! empty( $settings['apply_to_variations'] );

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
			if ( $variation_id && ! $do_variations ) {
				continue;
			}

			$resolved = $this->tiers_for_product( $product );
			$value    = $this->matching_tier_value( $resolved['tiers'], (int) ( $cart_item['quantity'] ?? 0 ) );
			if ( null === $value ) {
				continue;
			}

			$original = $this->original_price( $variation_id ?: (int) ( $cart_item['product_id'] ?? 0 ) );
			if ( null === $original ) {
				continue;
			}

			$product->set_price( $this->apply_tier( $original, $value, $resolved['type'] ) );
		}
	}

	/**
	 * WooCommerce fires `woocommerce_before_calculate_totals` more than once per
	 * request (mini-cart fragments, shipping recalculation, the Store API). The
	 * cart's own product objects keep whatever we set on the previous pass, so
	 * reading `$cart_item['data']->get_price()` would compound the discount.
	 * A freshly loaded product always reports the catalog price, which makes
	 * every pass produce the same result. Memoized so repeated passes cost one
	 * load per product.
	 */
	private function original_price( int $product_id ): ?float {
		if ( array_key_exists( $product_id, $this->original_prices ) ) {
			return $this->original_prices[ $product_id ];
		}

		$product = $product_id ? wc_get_product( $product_id ) : null;
		// Deliberately the `view` context: other plugins' price filters (sale
		// prices, currency switchers) are part of the price we discount from.
		$price = $product instanceof \WC_Product ? $product->get_price() : '';

		return $this->original_prices[ $product_id ] = ( '' === $price || null === $price ) ? null : (float) $price;
	}

	/**
	 * The value of the highest tier this quantity reaches, or null for none.
	 *
	 * @param array<int,array{min:int,value:float}> $tiers Sorted by `min` ascending.
	 */
	private function matching_tier_value( array $tiers, int $quantity ): ?float {
		$match = null;
		foreach ( $tiers as $tier ) {
			if ( $quantity >= $tier['min'] ) {
				$match = $tier['value'];
			}
		}
		return $match;
	}

	/**
	 * Parses the merchant's tier string. Accepts one rule per line as well as
	 * comma/semicolon separated rules, because the global setting is rendered
	 * as a single-line text input while the per-product override is a textarea.
	 * Anything that is not `<int>:<number>` is dropped without complaint —
	 * a typo must never take the storefront's prices with it.
	 *
	 * @return array<int,array{min:int,value:float}> Sorted by `min` ascending.
	 */
	private function parse_tiers( string $raw ): array {
		$tiers = array();

		foreach ( preg_split( '/[\r\n,;]+/', $raw ) ?: array() as $line ) {
			$parts = explode( ':', trim( $line ) );
			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$min = trim( $parts[0] );
			// Decimal point only — the comma is spent as a rule separator above,
			// so a fractional discount has to be typed "3:7.5", not "3:7,5"
			// (which reads as the rule 3:7 plus a dropped fragment). Both field
			// descriptions state the format; whole numbers are the normal case.
			$value = trim( $parts[1] );

			if ( ! is_numeric( $min ) || ! is_numeric( $value ) ) {
				continue;
			}

			$min   = (int) $min;
			$value = (float) $value;
			if ( $min < 1 || $value <= 0 ) {
				continue;
			}

			// Keyed by min so a repeated threshold keeps only the last rule.
			$tiers[ $min ] = array(
				'min'   => $min,
				'value' => $value,
			);
		}

		ksort( $tiers );

		return array_values( $tiers );
	}

	/**
	 * @param array<string,mixed> $tabs
	 * @return array<string,mixed>
	 */
	public function register_product_tab( array $tabs ): array {
		$tabs['galaxie_quantity_discounts'] = array(
			'label'    => __( 'Quantity Discounts', 'galaxie-woo' ),
			'target'   => self::PANEL_TARGET,
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);

		return $tabs;
	}

	public function render_product_panel(): void {
		global $post;

		$post_id = $post instanceof \WP_Post ? $post->ID : 0;

		echo '<div id="' . esc_attr( self::PANEL_TARGET ) . '" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group">';

		woocommerce_wp_textarea_input(
			array(
				'id'          => self::META_TIERS,
				'value'       => (string) get_post_meta( $post_id, self::META_TIERS, true ),
				'label'       => __( 'Tiers', 'galaxie-woo' ),
				'description' => __( 'One rule per line, written as minQty:discount — e.g. "3:5" then "6:10". Leave empty to use the global tiers from Galaxie → Quantity Discounts. Malformed rules are ignored.', 'galaxie-woo' ),
				'desc_tip'    => true,
				'placeholder' => "3:5\n6:10",
				'rows'        => 4,
			)
		);

		woocommerce_wp_select(
			array(
				'id'          => self::META_TYPE,
				'value'       => (string) get_post_meta( $post_id, self::META_TYPE, true ),
				'label'       => __( 'Discount type', 'galaxie-woo' ),
				'description' => __( 'How the number after the colon is read for this product.', 'galaxie-woo' ),
				'desc_tip'    => true,
				'options'     => array(
					''                    => __( 'Inherit global setting', 'galaxie-woo' ),
					self::TYPE_PERCENTAGE => __( 'Percentage off (%)', 'galaxie-woo' ),
					self::TYPE_FIXED      => __( 'Fixed amount off', 'galaxie-woo' ),
				),
			)
		);

		echo '</div>';
		echo '</div>';
	}

	public function save_product_meta( int $post_id ): void {
		$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) {
			return;
		}

		$tiers = isset( $_POST[ self::META_TIERS ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::META_TIERS ] ) ) : '';
		$type  = isset( $_POST[ self::META_TYPE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_TYPE ] ) ) : '';

		update_post_meta( $post_id, self::META_TIERS, $tiers );
		update_post_meta(
			$post_id,
			self::META_TYPE,
			in_array( $type, array( self::TYPE_PERCENTAGE, self::TYPE_FIXED ), true ) ? $type : ''
		);
	}

	/** @return array<string,mixed> */
	private function settings(): array {
		return Plugin::instance()->settings()->module_settings( $this->id() );
	}
}

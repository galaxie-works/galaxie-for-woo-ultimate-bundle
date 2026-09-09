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
 * Tiered per-line pricing: "3 a 5 unidades → 5% off". Tiers are configured
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
 *
 * The CONFIGURATION surface, by contrast, is modelled directly on XStore's —
 * a real repeater of rows, not a syntax the merchant has to learn. Same three
 * choices per product (inherit the global rules / none / custom), the same two
 * rule kinds, and the same row shape.
 */
final class Module implements ModuleContract, ProvidesElementorWidgets, ProvidesSettings {

	public const META_MODE      = '_galaxie_qd_mode';
	public const META_TYPE      = '_galaxie_qd_type';
	public const META_RULES     = '_galaxie_qd_rules';
	public const META_INTERVALS = '_galaxie_qd_intervals';
	public const META_STEPS     = '_galaxie_qd_steps';

	public const TYPE_PERCENTAGE = 'percentage';
	public const TYPE_FIXED      = 'fixed';

	/** A row matches any quantity from `min` up to `max` (blank max = no ceiling). */
	public const RULES_INTERVALS = 'intervals';
	/** A row matches one exact quantity only. */
	public const RULES_STEPS = 'steps';

	public const MODE_INHERIT = 'inherit';
	public const MODE_NONE    = 'none';
	public const MODE_CUSTOM  = 'custom';

	private const PANEL_TARGET = 'galaxie_quantity_discounts_data';

	/** Guards against printing the shared repeater script twice on one screen. */
	private bool $script_printed = false;

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
		return __( 'Tiered per-line pricing ("3 to 5 units, 5% off"), globally or per product, with an Elementor widget for the tier table.', 'galaxie-woo' );
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
				key: 'type',
				label: __( 'Discount type', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'How the discount column in the table below is read.', 'galaxie-woo' ),
				default: self::TYPE_PERCENTAGE,
				options: self::type_options()
			),
			new Field(
				key: 'rules',
				label: __( 'Discount rules', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'Ranges ("3 to 5 units") or an exact quantity ("exactly 3 units"). Each keeps its own table, so switching back and forth never loses the other one.', 'galaxie-woo' ),
				default: self::RULES_INTERVALS,
				options: self::rules_options()
			),
			new Field(
				key: 'apply_to_variations',
				label: __( 'Apply to variations', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Tiers are always evaluated per cart line: a line of 3× "Vela 190g" qualifies for a 3+ tier, but 2× 50g plus 2× 190g are two separate lines of 2 and neither qualifies. Turn this off to leave variation lines at full price and discount only simple products.', 'galaxie-woo' ),
				default: true
			),
		);
	}

	/**
	 * The tier tables themselves, which the declarative {@see Field} types
	 * can't express — this is exactly the escape hatch `ProvidesSettings`
	 * exists for. Inputs post under `fields[...]` like every other field on
	 * this page, so {@see sanitize_settings()} receives them normally.
	 *
	 * @param array<string,mixed> $values
	 */
	public function render_extra_settings( array $values ): void {
		echo '<div class="galaxie-qd-settings">';

		foreach ( array( self::RULES_INTERVALS, self::RULES_STEPS ) as $rules ) {
			echo '<div class="galaxie-qd-table-wrap" data-galaxie-qd-rules="' . esc_attr( $rules ) . '">';
			echo '<h3>' . esc_html( self::rules_options()[ $rules ] ) . '</h3>';
			$this->render_repeater( $rules, is_array( $values[ $rules ] ?? null ) ? $values[ $rules ] : array(), 'settings' );
			echo '</div>';
		}

		echo '</div>';

		$this->print_repeater_script();
	}

	/**
	 * @param array<string,mixed> $submitted
	 * @param array<string,mixed> $current
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $submitted, array $current ): array {
		$values = Field::sanitize_all( $this->settings_fields(), $submitted );

		$values[ self::RULES_INTERVALS ] = self::parse_rows( $submitted, self::RULES_INTERVALS );
		$values[ self::RULES_STEPS ]     = self::parse_rows( $submitted, self::RULES_STEPS );

		return $values;
	}

	/**
	 * The tiers that actually apply to one product: its own override when it
	 * declares one, otherwise the global list. Public because
	 * {@see QuantityDiscountsWidget} renders exactly what this returns.
	 *
	 * @return array{tiers:array<int,array<string,float|int|null>>,type:string,rules:string}
	 */
	public function tiers_for_product( \WC_Product $product ): array {
		// A variation never carries its own tiers — the panel lives on the
		// parent product, so that is where the override is read from.
		$meta_id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$settings = $this->settings();
		$mode     = (string) get_post_meta( $meta_id, self::META_MODE, true );

		// An explicit "None" is the whole point of having the choice: it lets
		// one product sit out the global rules, which "leave it blank" cannot
		// express (blank is how a product says "inherit").
		if ( self::MODE_NONE === $mode ) {
			return array(
				'tiers' => array(),
				'type'  => self::TYPE_PERCENTAGE,
				'rules' => self::RULES_INTERVALS,
			);
		}

		if ( self::MODE_CUSTOM === $mode ) {
			$rules  = self::normalize_rules( (string) get_post_meta( $meta_id, self::META_RULES, true ) );
			$stored = get_post_meta( $meta_id, self::rules_meta_key( $rules ), true );

			return array(
				'tiers' => is_array( $stored ) ? $stored : array(),
				'type'  => self::normalize_type( (string) get_post_meta( $meta_id, self::META_TYPE, true ) ),
				'rules' => $rules,
			);
		}

		$rules  = self::normalize_rules( (string) ( $settings['rules'] ?? '' ) );
		$global = $settings[ $rules ] ?? array();

		return array(
			'tiers' => is_array( $global ) ? $global : array(),
			'type'  => self::normalize_type( (string) ( $settings['type'] ?? '' ) ),
			'rules' => $rules,
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
			$value    = self::matching_tier_value( $resolved['tiers'], $resolved['rules'], (int) ( $cart_item['quantity'] ?? 0 ) );
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
	 * The discount of the last row this quantity satisfies, or null for none.
	 * "Last wins" rather than "best wins" keeps the merchant's row order
	 * meaningful, and matches the reference implementation.
	 *
	 * @param array<int,array<string,float|int|null>> $tiers
	 */
	public static function matching_tier_value( array $tiers, string $rules, int $quantity ): ?float {
		$match = null;

		foreach ( $tiers as $tier ) {
			$hit = self::RULES_STEPS === $rules
				? $quantity === (int) ( $tier['every'] ?? 0 )
				: $quantity >= (int) ( $tier['min'] ?? 0 ) && ( null === ( $tier['max'] ?? null ) || $quantity <= (int) $tier['max'] );

			if ( $hit ) {
				$match = (float) ( $tier['value'] ?? 0 );
			}
		}

		return $match;
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
		$mode    = (string) get_post_meta( $post_id, self::META_MODE, true );

		echo '<div id="' . esc_attr( self::PANEL_TARGET ) . '" class="panel woocommerce_options_panel hidden galaxie-qd-panel">';
		echo '<div class="options_group">';

		woocommerce_wp_select(
			array(
				'id'          => self::META_MODE,
				'value'       => $mode ? $mode : self::MODE_INHERIT,
				'label'       => __( 'Quantity discounts', 'galaxie-woo' ),
				'description' => __( 'Inherit uses the global tiers from Galaxie → Quantity Discounts. None keeps this product at full price no matter what the global tiers say.', 'galaxie-woo' ),
				'desc_tip'    => true,
				'options'     => array(
					self::MODE_INHERIT => __( 'Inherit', 'galaxie-woo' ),
					self::MODE_NONE    => __( 'None', 'galaxie-woo' ),
					self::MODE_CUSTOM  => __( 'Custom', 'galaxie-woo' ),
				),
			)
		);

		woocommerce_wp_radio(
			array(
				'id'      => self::META_TYPE,
				'value'   => self::normalize_type( (string) get_post_meta( $post_id, self::META_TYPE, true ) ),
				'label'   => __( 'Discount type', 'galaxie-woo' ),
				'options' => self::type_options(),
			)
		);

		woocommerce_wp_radio(
			array(
				'id'      => self::META_RULES,
				'value'   => self::normalize_rules( (string) get_post_meta( $post_id, self::META_RULES, true ) ),
				'label'   => __( 'Discount rules', 'galaxie-woo' ),
				'options' => self::rules_options(),
			)
		);

		foreach ( array( self::RULES_INTERVALS, self::RULES_STEPS ) as $rules ) {
			$stored = get_post_meta( $post_id, self::rules_meta_key( $rules ), true );

			echo '<fieldset class="form-field galaxie-qd-table-wrap" data-galaxie-qd-rules="' . esc_attr( $rules ) . '">';
			echo '<legend>' . esc_html( self::rules_options()[ $rules ] ) . '</legend>';
			$this->render_repeater( $rules, is_array( $stored ) ? $stored : array(), 'product' );
			echo '</fieldset>';
		}

		echo '</div>';
		echo '</div>';

		$this->print_repeater_script();
	}

	public function save_product_meta( int $post_id ): void {
		$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) {
			return;
		}

		$mode = isset( $_POST[ self::META_MODE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_MODE ] ) ) : self::MODE_INHERIT;
		$mode = in_array( $mode, array( self::MODE_INHERIT, self::MODE_NONE, self::MODE_CUSTOM ), true ) ? $mode : self::MODE_INHERIT;

		update_post_meta( $post_id, self::META_MODE, $mode );
		update_post_meta( $post_id, self::META_TYPE, self::normalize_type( isset( $_POST[ self::META_TYPE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_TYPE ] ) ) : '' ) );
		update_post_meta( $post_id, self::META_RULES, self::normalize_rules( isset( $_POST[ self::META_RULES ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_RULES ] ) ) : '' ) );

		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is cast in parse_rows().
		foreach ( array( self::RULES_INTERVALS, self::RULES_STEPS ) as $rules ) {
			update_post_meta( $post_id, self::rules_meta_key( $rules ), self::parse_rows( $raw, $rules, 'product' ) );
		}
	}

	/**
	 * One tier table. Shared by the product panel and the settings tab so the
	 * two surfaces can never drift apart in markup, column order or field
	 * names — only the input-name shape differs, since the settings page posts
	 * everything under `fields[...]`.
	 *
	 * @param array<int,array<string,float|int|null>> $rows
	 */
	private function render_repeater( string $rules, array $rows, string $context ): void {
		$columns = self::RULES_STEPS === $rules
			? array( 'every' => __( 'Exactly X units', 'galaxie-woo' ) )
			: array(
				'min' => __( 'From', 'galaxie-woo' ),
				'max' => __( 'To', 'galaxie-woo' ),
			);

		echo '<table class="galaxie-qd-repeater widefat striped">';

		echo '<thead><tr>';
		foreach ( $columns as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Discount', 'galaxie-woo' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead>';

		echo '<tbody>';
		foreach ( $rows as $row ) {
			$this->render_repeater_row( $rules, $columns, $context, is_array( $row ) ? $row : array() );
		}
		echo '</tbody>';
		echo '</table>';

		// The blank row lives in a <template> rather than a hidden <tr>: its
		// inputs are then genuinely outside the form, so an untouched table
		// can't post a phantom empty row.
		echo '<template class="galaxie-qd-row-template">';
		$this->render_repeater_row( $rules, $columns, $context, array() );
		echo '</template>';

		printf(
			'<p><button type="button" class="button galaxie-qd-add">%s</button></p>',
			esc_html__( '+ Add tier', 'galaxie-woo' )
		);
	}

	/**
	 * @param array<string,string>         $columns
	 * @param array<string,float|int|null> $row
	 */
	private function render_repeater_row( string $rules, array $columns, string $context, array $row ): void {
		echo '<tr>';

		foreach ( array_keys( $columns ) as $column ) {
			printf(
				'<td><input type="number" min="1" step="1" name="%1$s" value="%2$s" placeholder="%3$s" /></td>',
				esc_attr( self::input_name( $rules, $column, $context ) ),
				isset( $row[ $column ] ) && null !== $row[ $column ] ? esc_attr( (string) $row[ $column ] ) : '',
				// Only the range ceiling is optional, and saying so inside the
				// field is what stops "3 to blank" reading like a mistake.
				'max' === $column ? esc_attr__( 'no limit', 'galaxie-woo' ) : ''
			);
		}

		printf(
			'<td><input type="number" min="0" step="0.01" name="%1$s" value="%2$s" /></td>',
			esc_attr( self::input_name( $rules, 'value', $context ) ),
			isset( $row['value'] ) ? esc_attr( (string) $row['value'] ) : ''
		);

		printf(
			'<td><button type="button" class="button galaxie-qd-remove" aria-label="%s">&times;</button></td>',
			esc_attr__( 'Remove tier', 'galaxie-woo' )
		);

		echo '</tr>';
	}

	/** Adds/removes rows and hides what the current choices make irrelevant. */
	private function print_repeater_script(): void {
		if ( $this->script_printed ) {
			return;
		}
		$this->script_printed = true;
		?>
		<script>
		( function () {
			document.addEventListener( 'click', function ( event ) {
				var add = event.target.closest( '.galaxie-qd-add' );
				if ( add ) {
					var wrap = add.closest( '.galaxie-qd-table-wrap' );
					var template = wrap && wrap.querySelector( '.galaxie-qd-row-template' );
					var body = wrap && wrap.querySelector( '.galaxie-qd-repeater tbody' );
					if ( template && body ) {
						body.appendChild( template.content.cloneNode( true ) );
					}
					return;
				}

				var remove = event.target.closest( '.galaxie-qd-remove' );
				if ( remove && remove.closest( 'tr' ) ) {
					remove.closest( 'tr' ).remove();
				}
			} );

			// Only the table for the selected rule kind is shown; the other one
			// stays in the DOM (and keeps posting) so switching back and forth
			// never costs the merchant rows they already typed.
			function sync( scope ) {
				var mode = scope.querySelector( '[name="_galaxie_qd_mode"]' );
				var rules = scope.querySelector( '[name="_galaxie_qd_rules"]:checked' ) ||
					scope.querySelector( '[name="fields[rules]"]' );
				var custom = ! mode || mode.value === 'custom';

				scope.querySelectorAll( '[name="_galaxie_qd_type"], [name="_galaxie_qd_rules"]' ).forEach( function ( input ) {
					var field = input.closest( 'fieldset, .form-field' );
					if ( field ) {
						field.hidden = ! custom;
					}
				} );

				scope.querySelectorAll( '.galaxie-qd-table-wrap' ).forEach( function ( wrap ) {
					wrap.hidden = ! custom || ( !! rules && wrap.dataset.galaxieQdRules !== rules.value );
				} );
			}

			function syncAll() {
				document.querySelectorAll( '.galaxie-qd-panel, .galaxie-qd-settings' ).forEach( sync );
			}

			document.addEventListener( 'change', function ( event ) {
				if ( event.target.closest( '[name="_galaxie_qd_mode"], [name="_galaxie_qd_rules"], [name="fields[rules]"]' ) ) {
					syncAll();
				}
			} );

			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', syncAll );
			} else {
				syncAll();
			}
		}() );
		</script>
		<?php
	}

	/** `fields[intervals_min][]` on the settings page, `_galaxie_qd_intervals_min[]` on a product. */
	private static function input_name( string $rules, string $column, string $context ): string {
		return 'settings' === $context
			? sprintf( 'fields[%s_%s][]', $rules, $column )
			: sprintf( '_galaxie_qd_%s_%s[]', $rules, $column );
	}

	/**
	 * Turns the parallel arrays the repeater posts into tier rows. A row is
	 * dropped when it has no usable quantity or no discount — that is how an
	 * added-then-abandoned blank row disappears on save, with no "please fill
	 * this in" bounce for something the merchant clearly did not want.
	 *
	 * @param array<string,mixed> $src
	 * @return array<int,array<string,float|int|null>>
	 */
	private static function parse_rows( array $src, string $rules, string $context = 'settings' ): array {
		$read = static function ( string $column ) use ( $src, $rules, $context ): array {
			$key = 'settings' === $context ? $rules . '_' . $column : '_galaxie_qd_' . $rules . '_' . $column;
			return isset( $src[ $key ] ) && is_array( $src[ $key ] ) ? array_values( $src[ $key ] ) : array();
		};

		$values = $read( 'value' );
		$firsts = self::RULES_STEPS === $rules ? $read( 'every' ) : $read( 'min' );
		$maxes  = self::RULES_STEPS === $rules ? array() : $read( 'max' );

		$rows = array();

		foreach ( $firsts as $i => $first ) {
			$quantity = (int) $first;
			$value    = (float) ( $values[ $i ] ?? 0 );

			if ( $quantity < 1 || $value <= 0 ) {
				continue;
			}

			if ( self::RULES_STEPS === $rules ) {
				$rows[] = array(
					'every' => $quantity,
					'value' => $value,
				);
				continue;
			}

			$max = isset( $maxes[ $i ] ) && '' !== trim( (string) $maxes[ $i ] ) ? (int) $maxes[ $i ] : null;
			// A ceiling below the floor can only be a typo, and keeping it
			// would leave a row that silently never matches anything.
			if ( null !== $max && $max < $quantity ) {
				$max = null;
			}

			$rows[] = array(
				'min'   => $quantity,
				'max'   => $max,
				'value' => $value,
			);
		}

		return $rows;
	}

	/** @return array<string,string> */
	private static function type_options(): array {
		return array(
			self::TYPE_PERCENTAGE => __( 'By percentage (e.g. 10% OFF)', 'galaxie-woo' ),
			self::TYPE_FIXED      => sprintf(
				/* translators: %s: the store's currency symbol. */
				__( 'By fixed amount (e.g. 10%s OFF)', 'galaxie-woo' ),
				get_woocommerce_currency_symbol()
			),
		);
	}

	/** @return array<string,string> */
	private static function rules_options(): array {
		return array(
			self::RULES_INTERVALS => __( 'Ranges', 'galaxie-woo' ),
			self::RULES_STEPS     => __( 'Exact quantity', 'galaxie-woo' ),
		);
	}

	private static function rules_meta_key( string $rules ): string {
		return self::RULES_STEPS === $rules ? self::META_STEPS : self::META_INTERVALS;
	}

	private static function normalize_type( string $type ): string {
		return self::TYPE_FIXED === $type ? self::TYPE_FIXED : self::TYPE_PERCENTAGE;
	}

	private static function normalize_rules( string $rules ): string {
		return self::RULES_STEPS === $rules ? self::RULES_STEPS : self::RULES_INTERVALS;
	}

	/** @return array<string,mixed> */
	private function settings(): array {
		return Plugin::instance()->settings()->module_settings( $this->id() );
	}
}

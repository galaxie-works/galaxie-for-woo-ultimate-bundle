<?php
/**
 * "Galaxie Quantity Discounts" Elementor widget — the tier table for one product.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\QuantityDiscounts\Widget;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Modules\QuantityDiscounts\Module;
use Galaxie\Woo\Modules\VariationSwatches\Widget\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered table of the tiers that actually apply to the product being
 * viewed — resolved through {@see Module::tiers_for_product()}, so the product's
 * own override and the global list are read exactly the same way here as they
 * are when the cart is priced. No JS: nothing on this table changes between
 * page load and add-to-cart.
 *
 * Styling follows the pixfort pattern the rest of the bundle uses (the theme's
 * own global color dropdown — Primary, Gray 1-9, Dynamic Colors — behind a
 * `class_exists('\PixfortCore')` guard), with plain Elementor COLOR controls as
 * the fallback on any other theme.
 */
final class QuantityDiscountsWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-quantity-discounts';
	}

	public function get_title(): string {
		return __( 'Galaxie Quantity Discounts', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-table';
	}

	public function get_categories(): array {
		return array( 'galaxie' );
	}

	private function pixfort_active(): bool {
		return class_exists( '\PixfortCore' );
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'content_section',
			array( 'label' => __( 'Content', 'galaxie-woo' ) )
		);

		$this->add_control(
			'heading',
			array(
				'label'       => __( 'Heading', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Leve mais, pague menos', 'galaxie-woo' ),
				'placeholder' => __( 'Leave empty for no heading', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'show_unit_price',
			array(
				'label'        => __( 'Show unit price', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'galaxie-woo' ),
				'label_off'    => __( 'No', 'galaxie-woo' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_button',
			array(
				'label'        => __( 'Add button on each row', 'galaxie-woo' ),
				'description'  => __( 'A tier the shopper cannot act on is a poster, not an offer — this puts that quantity in the cart in one click.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'galaxie-woo' ),
				'label_off'    => __( 'No', 'galaxie-woo' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'hide_unavailable',
			array(
				'label'        => __( 'Hide tiers stock cannot honour', 'galaxie-woo' ),
				'description'  => __( 'With 4 in stock, a "6 to 10 units" row is a promise the cart breaks. Off shows every tier regardless.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'galaxie-woo' ),
				'label_off'    => __( 'No', 'galaxie-woo' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->register_button_section();
		$this->register_text_controls( 'heading', 'heading_style_section', __( 'Heading', 'galaxie-woo' ) );
		$this->register_text_controls( 'row', 'row_style_section', __( 'Table text', 'galaxie-woo' ) );
	}

	/**
	 * The row button, with pixfort's own Button control set behind it — the same
	 * registrar the Buy Box uses, so a merchant configures this button exactly
	 * the way they configure that one.
	 */
	private function register_button_section(): void {
		$this->start_controls_section(
			'button_section',
			array(
				'label'     => __( 'Add button', 'galaxie-woo' ),
				'condition' => array( 'show_button' => 'yes' ),
			)
		);

		PixfortControls::button(
			$this,
			'btn',
			array(
				'text'  => __( 'Adicionar', 'galaxie-woo' ),
				'style' => 'outline',
				'color' => 'primary',
				'size'  => 'sm',
			),
			array( 'show_button' => 'yes' ),
			'{{WRAPPER}} .galaxie-qd-add'
		);

		$this->end_controls_section();
	}

	/**
	 * Where each prefix's non-pixfort fallback color and its additive Elementor
	 * typography land. The row group control keeps its original `table_typography`
	 * name so layouts saved before the heading/table split keep their font.
	 *
	 * @return array{selector:string,typography:string}
	 */
	private function text_target( string $prefix ): array {
		if ( 'heading' === $prefix ) {
			return array(
				'selector'   => '{{WRAPPER}} .galaxie-qd-heading',
				'typography' => 'heading_typography',
			);
		}

		return array(
			'selector'   => '{{WRAPPER}} .galaxie-qd-table',
			'typography' => 'table_typography',
		);
	}

	/**
	 * Full pixfort Text parity for one piece of the table, keyed by $prefix so
	 * heading and rows can be styled apart on the same widget — pixfort's own
	 * shared helpers hardcode unprefixed control ids, so they can only serve one
	 * text block per widget; this mirrors the Text element's controls with our
	 * own prefixed ids instead, matching its attr keys 1:1 in {@see render_text()}.
	 */
	private function register_text_controls( string $prefix, string $section_id, string $label ): void {
		$target = $this->text_target( $prefix );

		$this->start_controls_section(
			$section_id,
			array(
				'label' => $label,
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			$prefix . '_size',
			array(
				'label'   => __( 'Size', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					''        => __( 'Default', 'galaxie-woo' ),
					'text-xs' => '12px',
					'text-sm' => '14px',
					'text-18' => '18px',
					'text-20' => '20px',
					'text-24' => '24px',
				),
				'default' => '',
			)
		);

		$this->add_control(
			$prefix . '_bold',
			array(
				'label'        => __( 'Bold', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'font-weight-bold',
				'default'      => '',
			)
		);

		$this->add_control(
			$prefix . '_italic',
			array(
				'label'        => __( 'Italic', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'font-italic',
				'default'      => '',
			)
		);

		$this->add_control(
			$prefix . '_secondary_font',
			array(
				'label'        => __( 'Secondary font', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'secondary-font',
				'default'      => '',
			)
		);

		if ( $this->pixfort_active() ) {
			$this->add_control(
				$prefix . '_content_color',
				array(
					'label'   => __( 'Content color', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'groups'  => \PixfortCore::instance()->coreFunctions->getColorsArray(),
					'default' => '',
				)
			);
			$this->add_control(
				$prefix . '_content_custom_color',
				array(
					'label'     => __( 'Custom content color', 'galaxie-woo' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '',
					'condition' => array( $prefix . '_content_color' => 'custom' ),
				)
			);
		} else {
			$this->add_control(
				$prefix . '_content_color_fallback',
				array(
					'label'     => __( 'Content color', 'galaxie-woo' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array(
						$target['selector'] => 'color: {{VALUE}};',
					),
				)
			);
		}

		$this->add_control(
			$prefix . '_position',
			array(
				'label'   => __( 'Position', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'text-center' => __( 'Center', 'galaxie-woo' ),
					'text-left'   => __( 'Start', 'galaxie-woo' ),
					'text-right'  => __( 'End', 'galaxie-woo' ),
				),
				'default' => 'text-left',
			)
		);

		$this->add_control(
			$prefix . '_animation',
			array(
				'label'   => __( 'Animation', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => function_exists( 'pix_get_animations' ) ? pix_get_animations( true ) : array( '' => __( 'None', 'galaxie-woo' ) ),
			)
		);
		$this->add_control(
			$prefix . '_delay',
			array(
				'label'     => __( 'Animation delay (in miliseconds)', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => '0',
				'condition' => array( $prefix . '_animation!' => '' ),
			)
		);

		// Defaults to `m-0` because these strings sit in a table cell and a
		// heading strip, where pixfort's paragraph margin would break the rows
		// apart — the switcher only exists so a layout can opt back into it.
		$this->add_control(
			$prefix . '_remove_pb_padding',
			array(
				'label'        => __( 'Remove margin under paragraphs', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'm-0',
				'default'      => 'm-0',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => $target['typography'],
				'selector' => $target['selector'],
			)
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		$product  = $this->current_product();
		$resolved = $product
			? $this->module()->tiers_for_product( $product )
			: array( 'tiers' => array(), 'type' => Module::TYPE_PERCENTAGE, 'rules' => Module::RULES_INTERVALS );

		$tiers = $product
			? $this->applicable_tiers( $resolved, $product, 'yes' === ( $this->get_settings_for_display()['hide_unavailable'] ?? 'yes' ) )
			: array();

		if ( ! $product || ! $tiers ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div style="padding:2rem;text-align:center;border:1px dashed #ccc;border-radius:8px;">';
				esc_html_e( 'Galaxie Quantity Discounts — place inside a single product template whose product has quantity tiers (its own, or the module\'s global ones).', 'galaxie-woo' );
				echo '</div>';
			}
			return;
		}

		$settings = $this->get_settings_for_display();
		$heading  = trim( (string) ( $settings['heading'] ?? '' ) );
		$base     = 'yes' === ( $settings['show_unit_price'] ?? 'yes' ) ? $this->base_price( $product ) : null;
		$button   = 'yes' === ( $settings['show_button'] ?? 'yes' );
		$variable = $product->is_type( 'variable' );

		if ( $button ) {
			// WooCommerce's own handler is what these buttons ride on: it binds
			// `.add_to_cart_button`, posts `data-product_id` + `data-quantity` to
			// its `add_to_cart` endpoint, refreshes the cart fragments and fires
			// `added_to_cart`. Registering our own endpoint would mean
			// re-implementing all of that, badly. On a page where the theme has
			// not already enqueued it, this is what makes the click work.
			wp_enqueue_script( 'wc-add-to-cart' );
		}

		echo '<div class="galaxie-ui galaxie-qd">';

		if ( '' !== $heading ) {
			echo '<div class="galaxie-qd-heading">' . $this->render_text( $heading, 'heading', $settings ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by render_text().
		}

		echo '<table class="galaxie-qd-table">';
		echo '<tbody>';

		foreach ( $tiers as $tier ) {
			echo '<tr class="galaxie-qd-row">';

			echo '<td class="galaxie-qd-qty">' . $this->render_text( $this->quantity_label( $tier, $resolved['rules'] ), 'row', $settings ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by render_text().
			echo '<td class="galaxie-qd-discount">' . $this->render_text( $this->discount_label( $tier['value'], $resolved['type'], $product ), 'row', $settings ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by render_text().

			// A variable product whose variations differ in price has no single
			// "each" figure to show, so the column is simply left out rather
			// than quoting one variation's price as if it were the product's.
			if ( null !== $base ) {
				$unit = $this->module()->apply_tier( $base, $tier['value'], $resolved['type'] );
				echo '<td class="galaxie-qd-unit">' . $this->render_text( $this->unit_label( $unit, $product ), 'row', $settings ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by render_text().
			}

			if ( $button ) {
				echo '<td class="galaxie-qd-action">';
				$this->render_add_button( $settings, $tier, $resolved['rules'], $product, $variable );
				echo '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}

	/**
	 * The tiers this product can actually honour, with any ceiling brought down
	 * to the stock that exists.
	 *
	 * A "6 to 10 units" row on a product with 4 left is a promise the cart
	 * breaks at the fifth, so the row is dropped rather than shown and denied.
	 * `has_enough_stock()` answers correctly for every stock mode — unmanaged
	 * stock says yes, backorders say yes — so this only removes rows that are
	 * genuinely unbuyable.
	 *
	 * Note the limit: on a variable product this runs against the parent, which
	 * usually manages no stock of its own. The per-variation quantity is only
	 * known once the shopper picks one, and the script narrows the rows then.
	 *
	 * @param array{tiers:array<int,array<string,float|int|null>>,type:string,rules:string} $resolved
	 * @return array<int,array<string,float|int|null>>
	 */
	private function applicable_tiers( array $resolved, \WC_Product $product, bool $hide_unavailable ): array {
		$steps = Module::RULES_STEPS === $resolved['rules'];

		// Stock constrains nothing unless the product is actually counting it.
		// With "Manage stock" off there is no number to compare against, and
		// with backorders on the number is not a limit — in both cases the
		// tiers stand as written. This is deliberately the same test
		// WooCommerce uses in `get_max_purchase_quantity()` to decide whether a
		// purchase ceiling exists at all, so the table and the quantity field
		// on the page can never disagree about what is buyable.
		$counts_stock = $product->managing_stock() && ! $product->backorders_allowed();
		$stock        = $counts_stock ? $product->get_stock_quantity() : null;
		$out          = array();

		foreach ( $resolved['tiers'] as $tier ) {
			$needed = (int) ( $steps ? ( $tier['every'] ?? 0 ) : ( $tier['min'] ?? 0 ) );

			if ( $hide_unavailable && $counts_stock && $needed > 0 && ! $product->has_enough_stock( $needed ) ) {
				continue;
			}

			if ( ! $steps && null !== $stock && isset( $tier['max'] ) && null !== $tier['max'] && $stock < (int) $tier['max'] ) {
				$tier['max'] = $stock;
			}

			// An open-ended row on a stock-managed product still has a real
			// ceiling; saying "3+" when only 7 exist is the same broken promise
			// in a different shape.
			if ( ! $steps && null !== $stock && ( ! isset( $tier['max'] ) || null === $tier['max'] ) ) {
				$tier['max'] = $stock;
			}

			$out[] = $tier;
		}

		return $out;
	}

	/**
	 * One row's Add button: our own element as the click target, a real pixfort
	 * Button inside it as the visible control — the same split the Buy Box
	 * uses, so the theme's own button styling applies without us restyling it.
	 *
	 * On a variable product there is nothing to add until a variation is chosen,
	 * so the button carries no product id and the script fills it in from
	 * whatever the shopper selects, clearing it again on reset — a button that
	 * posts an unresolvable id would fail silently on the server.
	 *
	 * It is deliberately NOT disabled while it waits. Three greyed-out buttons
	 * on page load read as broken, not as "choose a size first", and the first
	 * report this shipped with was exactly that: the faded look was mistaken
	 * for the text colour failing. It stays fully painted, and clicking it
	 * before a choice is made asks for the choice.
	 *
	 * @param array<string,mixed>          $settings
	 * @param array<string,float|int|null> $tier
	 */
	private function render_add_button( array $settings, array $tier, string $rules, \WC_Product $product, bool $variable ): void {
		$quantity = (int) ( Module::RULES_STEPS === $rules ? ( $tier['every'] ?? 0 ) : ( $tier['min'] ?? 0 ) );
		if ( $quantity < 1 ) {
			return;
		}

		$text = (string) ( $settings['btn_text'] ?? __( 'Adicionar', 'galaxie-woo' ) );

		printf(
			'<button type="button" class="galaxie-qd-add add_to_cart_button ajax_add_to_cart" data-quantity="%1$d" data-galaxie-qd-qty="%1$d" data-product_id="%2$s"%3$s>',
			$quantity,
			$variable ? '' : (int) $product->get_id(),
			$variable ? ' data-galaxie-qd-needs-variation="1"' : ''
		);

		if ( PixfortControls::available() ) {
			echo \PixfortCore::instance()->elementsManager->renderElement( 'Button', PixfortControls::button_attr( $settings, 'btn', $text ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
		} else {
			echo '<span class="btn">' . esc_html( $text ) . '</span>';
		}

		echo '</button>';
	}

	/**
	 * How one tier's quantity condition reads to a shopper. A range with no
	 * ceiling says "3+"; one with a ceiling says "3 a 5", because "3+" would
	 * be a promise the cart then breaks at 6.
	 *
	 * @param array<string,float|int|null> $tier
	 */
	private function quantity_label( array $tier, string $rules ): string {
		if ( Module::RULES_STEPS === $rules ) {
			return sprintf(
				/* translators: %d: the exact quantity this tier applies to. */
				__( 'exatamente %d unidades', 'galaxie-woo' ),
				(int) ( $tier['every'] ?? 0 )
			);
		}

		$min = (int) ( $tier['min'] ?? 0 );
		$max = isset( $tier['max'] ) && null !== $tier['max'] ? (int) $tier['max'] : null;

		if ( null === $max ) {
			return sprintf(
				/* translators: %d: minimum quantity that unlocks this tier. */
				__( '%d+ unidades', 'galaxie-woo' ),
				$min
			);
		}

		return sprintf(
			/* translators: 1: lowest quantity in the range, 2: highest. */
			__( '%1$d a %2$d unidades', 'galaxie-woo' ),
			$min,
			$max
		);
	}

	private function discount_label( float $value, string $type, \WC_Product $product ): string {
		if ( Module::TYPE_FIXED === $type ) {
			return sprintf(
				/* translators: %s: formatted money amount taken off each unit. */
				__( '%s off', 'galaxie-woo' ),
				$this->money( $value, $product )
			);
		}

		return sprintf(
			/* translators: %s: percentage taken off each unit. */
			__( '%s%% off', 'galaxie-woo' ),
			wc_format_decimal( $value, false, true )
		);
	}

	private function unit_label( float $unit, \WC_Product $product ): string {
		return sprintf(
			/* translators: %s: formatted money amount charged per unit at this tier. */
			__( '%s cada', 'galaxie-woo' ),
			$this->money( $unit, $product )
		);
	}

	/**
	 * Money as plain text. Tier math runs on the stored price, so the amount has
	 * to go through `wc_get_price_to_display()` before it is shown or a store
	 * that enters prices ex-tax and displays them incl-tax (or the reverse)
	 * would quote a figure the shopper never gets charged. `wc_price()` then
	 * applies the store's own currency settings but returns its own markup; the
	 * tags come off because these strings are composed into sentences that are
	 * rendered as text.
	 */
	private function money( float $amount, \WC_Product $product ): string {
		return wp_strip_all_tags( wc_price( wc_get_price_to_display( $product, array( 'price' => $amount ) ) ) );
	}

	/**
	 * One piece of text, styled the pixfort way when the theme is active —
	 * rendered through pixfort-core's own Text component, the same call the
	 * Variation Badges widget's label uses, which is what makes the theme's
	 * global color dropdown actually apply.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function render_text( string $text, string $prefix, array $settings ): string {
		if ( $this->pixfort_active() ) {
			$attr = array(
				'content_type'         => 'simple',
				'content'              => $text,
				'size'                 => $settings[ $prefix . '_size' ] ?? '',
				'bold'                 => $settings[ $prefix . '_bold' ] ?? '',
				'italic'               => $settings[ $prefix . '_italic' ] ?? '',
				'secondary_font'       => $settings[ $prefix . '_secondary_font' ] ?? '',
				'content_color'        => $settings[ $prefix . '_content_color' ] ?? '',
				'content_custom_color' => $settings[ $prefix . '_content_custom_color' ] ?? '',
				'position'             => $settings[ $prefix . '_position' ] ?? 'text-left',
				'animation'            => $settings[ $prefix . '_animation' ] ?? '',
				'delay'                => $settings[ $prefix . '_delay' ] ?? '0',
				'remove_pb_padding'    => $settings[ $prefix . '_remove_pb_padding' ] ?? 'm-0',
			);
			return \PixfortCore::instance()->elementsManager->renderElement( 'Text', $attr, $text );
		}

		$classes = array();
		foreach ( array( '_bold', '_italic', '_secondary_font', '_position' ) as $suffix ) {
			if ( ! empty( $settings[ $prefix . $suffix ] ) ) {
				$classes[] = (string) $settings[ $prefix . $suffix ];
			}
		}

		return '<span class="' . esc_attr( implode( ' ', $classes ) ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * The price one unit costs before any tier applies, or null when the
	 * product has no single price to discount from.
	 */
	private function base_price( \WC_Product $product ): ?float {
		if ( $product->is_type( 'variable' ) ) {
			$min = (float) $product->get_variation_price( 'min' );
			$max = (float) $product->get_variation_price( 'max' );

			return $min === $max ? $min : null;
		}

		$price = $product->get_price();

		return ( '' === $price || null === $price ) ? null : (float) $price;
	}

	/** Current product on a real single-product page, or Elementor's preview post. */
	private function current_product(): ?\WC_Product {
		global $product;

		if ( $product instanceof \WC_Product ) {
			return $product;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return null;
		}

		$candidate = wc_get_product( $post_id );
		return $candidate instanceof \WC_Product ? $candidate : null;
	}

	/**
	 * The registered module instance, so the widget reads tiers through the
	 * same object the cart does. Falls back to a fresh one — the module holds
	 * no state that matters for reading tiers.
	 */
	private function module(): Module {
		$registered = Plugin::instance()->modules()->all()['quantity-discounts'] ?? null;

		return $registered instanceof Module ? $registered : new Module();
	}
}

<?php
/**
 * "Galaxie Buy Box" Elementor widget — the product page's whole purchase area.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\VariationSwatches\Widget;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Widget_Base;
use Galaxie\Woo\Modules\VariationSwatches\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Price, variation swatches, quantity, Add to Cart and Buy Now, each an
 * independently orderable and removable block, each styled through pixfort's
 * own components ({@see PixfortControls}).
 *
 * WHY THIS REPLACES VariationBadgesWidget. That widget rendered its own UI
 * *beside* WooCommerce's native form and then hid the native one piece by
 * piece. Every visual bug it produced was the same bug: a native block nobody
 * remembered to hide (the duplicated price and stock), or a control that fell
 * outside `form.cart` and so missed the theme's own scoped CSS (the misaligned
 * quantity stepper). Here the form IS ours, so there is no second copy of
 * anything and every control sits inside `form.cart` where theme styling
 * reaches it.
 *
 * What is NOT reimplemented is variation matching. The real `<select>` per
 * attribute stays in the form (visually hidden), so WooCommerce's own
 * `wc-add-to-cart-variation.js` keeps doing the matching it has always done and
 * keeps firing `found_variation` / `show_variation` / `hide_variation` /
 * `reset_data` — the events third-party plugins actually bind to.
 *
 * CONTROL LAYOUT, and why it is what it is. The repeater holds ONLY the block
 * type: it decides order, and deleting a row is how a block is turned off, so
 * an unwanted block never reaches the canvas. Everything else lives in a
 * clearly-named section per block, for two reasons. One is legibility — with
 * every set flattened into one repeater row there was no telling whether
 * "Bold" belonged to the caption or the badge. The other is a hard constraint:
 * pixfort's `pixfort_icon_selector` prints its entire icon library inline in
 * its `content_template()`, and Elementor re-renders a repeater row's controls
 * on every interaction, so thousands of nodes were being rebuilt per click —
 * that is what froze the editor. Custom controls of that shape must stay out
 * of repeater rows.
 */
final class BuyBoxWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-buybox';
	}

	public function get_title(): string {
		return __( 'Galaxie Buy Box', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-cart-medium';
	}

	public function get_categories(): array {
		return array( 'galaxie' );
	}

	protected function register_controls(): void {
		$this->register_blocks_section();
		$this->register_price_section();
		$this->register_variations_section();
		$this->register_quantity_section();
		$this->register_button_section( 'addcart', __( 'Add to Cart button', 'galaxie-woo' ), __( 'Adicionar ao carrinho', 'galaxie-woo' ), '' );
		$this->register_button_section( 'buynow', __( 'Buy Now button', 'galaxie-woo' ), __( 'Comprar agora', 'galaxie-woo' ), 'outline' );
		$this->register_layout_section();
	}

	/** Order and presence. Nothing else — see the class docblock. */
	private function register_blocks_section(): void {
		$this->start_controls_section(
			'blocks_section',
			array( 'label' => __( 'Blocks', 'galaxie-woo' ) )
		);

		$this->add_control(
			'blocks_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Drag to reorder. Remove a row to leave that block out entirely — its own section below is then ignored.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$repeater = new Repeater();
		$repeater->add_control(
			'block',
			array(
				'label'   => __( 'Block', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => self::block_options(),
				'default' => 'price',
			)
		);

		$this->add_control(
			'blocks',
			array(
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ block }}}',
				'default'     => array(
					array( 'block' => 'price' ),
					array( 'block' => 'variations' ),
					array( 'block' => 'quantity' ),
					array( 'block' => 'addcart' ),
				),
			)
		);

		$this->end_controls_section();
	}

	private function register_price_section(): void {
		$this->start_controls_section(
			'price_section',
			array( 'label' => __( 'Price', 'galaxie-woo' ) )
		);

		$this->heading( 'price_regular_heading', __( 'Regular price', 'galaxie-woo' ), false );
		PixfortControls::text( $this, 'price_regular', array( 'size' => 'h4', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-buybox-price-regular', 'heading' );

		$this->heading( 'price_sale_heading', __( 'Sale price', 'galaxie-woo' ) );
		$this->add_control(
			'sale_display',
			array(
				'label'   => __( 'Sale price display', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'simple'   => __( 'Simple (text)', 'galaxie-woo' ),
					'advanced' => __( 'Advanced (badge)', 'galaxie-woo' ),
				),
				'default' => 'simple',
			)
		);
		PixfortControls::text( $this, 'price_sale', array( 'size' => 'h4', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array( 'sale_display' => 'simple' ), '{{WRAPPER}} .galaxie-buybox-price-sale', 'heading' );
		PixfortControls::badge( $this, 'price_sale_badge', array(), array( 'sale_display' => 'advanced' ) );

		$this->heading( 'stock_heading', __( 'Stock', 'galaxie-woo' ) );
		$this->add_control(
			'show_stock',
			array(
				'label'        => __( 'Show stock', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		PixfortControls::text( $this, 'stock', array( 'bold' => '', 'remove_pb_padding' => 'm-0' ), array( 'show_stock' => 'yes' ), '{{WRAPPER}} .galaxie-buybox-stock' );

		$this->end_controls_section();
	}

	private function register_variations_section(): void {
		$this->start_controls_section(
			'variations_section',
			array( 'label' => __( 'Variations', 'galaxie-woo' ) )
		);

		$this->add_control(
			'attribute',
			array(
				'label'   => __( 'Attribute', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => self::attribute_options(),
				'default' => 'pa_peso',
			)
		);

		$this->heading( 'label_heading', __( 'Caption', 'galaxie-woo' ) );
		$this->add_control(
			'label_text',
			array(
				'label'       => __( 'Caption text', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Defaults to the attribute name', 'galaxie-woo' ),
			)
		);
		$this->add_control(
			'show_label',
			array(
				'label'        => __( 'Show caption', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		PixfortControls::text( $this, 'label', array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array( 'show_label' => 'yes' ), '{{WRAPPER}} .galaxie-variation-label' );

		$this->heading( 'badge_heading', __( 'Badge', 'galaxie-woo' ) );
		PixfortControls::badge( $this, 'badge', array( 'text_color' => 'primary', 'bg_color' => 'primary-light', 'rounded' => 'badge-pill' ) );

		$this->heading( 'badgesel_heading', __( 'Badge — selected', 'galaxie-woo' ) );
		PixfortControls::badge( $this, 'badgesel', array( 'text_color' => 'white', 'bg_color' => 'primary', 'rounded' => 'badge-pill' ) );

		$this->end_controls_section();
	}

	/**
	 * The quantity field is native WooCommerce markup, so it can't go through a
	 * pixfort component — but it still has to follow the theme's light/dark and
	 * Dynamic Colors, which is what {@see PixfortControls::palette_control()}
	 * is for.
	 */
	private function register_quantity_section(): void {
		$this->start_controls_section(
			'quantity_section',
			array( 'label' => __( 'Quantity', 'galaxie-woo' ) )
		);

		$this->add_control(
			'qty_style',
			array(
				'label'   => __( 'Style', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'input'  => __( 'Number input (+/-)', 'galaxie-woo' ),
					'select' => __( 'Dropdown', 'galaxie-woo' ),
				),
				'default' => 'input',
			)
		);

		$this->add_control(
			'qty_max',
			array(
				'label'     => __( 'Dropdown goes up to', 'galaxie-woo' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 50,
				'default'   => 5,
				'condition' => array( 'qty_style' => 'select' ),
			)
		);

		$field = '{{WRAPPER}} .galaxie-buybox-quantity .quantity';
		$input = '{{WRAPPER}} .galaxie-buybox-quantity .qty';

		PixfortControls::palette_control( $this, 'qty_text_color', __( 'Text color', 'galaxie-woo' ), $input, 'color' );
		PixfortControls::palette_control( $this, 'qty_bg_color', __( 'Background color', 'galaxie-woo' ), $field, 'background-color' );
		// The theme gives the quantity box a background, radius and shadow but
		// no border at all, so a colour on its own lands on a zero-width border
		// and shows nothing. Picking a colour therefore also brings a style and
		// a 1px baseline, which the width control below can then override.
		PixfortControls::palette_control(
			$this,
			'qty_border_color',
			__( 'Border color', 'galaxie-woo' ),
			$field,
			'border-color',
			array(),
			' border-style: solid !important; border-width: 1px;'
		);

		$this->add_responsive_control(
			'qty_border_width',
			array(
				'label'      => __( 'Border width', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 8 ) ),
				// No default on purpose: an untouched slider must not paint a
				// border on a box the theme deliberately ships without one.
				'selectors'  => array( $field => 'border-width: {{SIZE}}{{UNIT}} !important; border-style: solid;' ),
				'condition'  => array( 'qty_border_color!' => '' ),
			)
		);

		$this->add_responsive_control(
			'qty_width',
			array(
				'label'      => __( 'Width', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array( 'px' => array( 'min' => 60, 'max' => 400 ) ),
				'selectors'  => array( $field => 'width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'qty_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( $field => 'border-radius: {{SIZE}}{{UNIT}}; overflow: hidden;' ),
			)
		);

		$this->end_controls_section();
	}

	private function register_button_section( string $prefix, string $label, string $default_text, string $default_style ): void {
		$this->start_controls_section(
			$prefix . '_section',
			array( 'label' => $label )
		);

		PixfortControls::button(
			$this,
			$prefix,
			array( 'text' => $default_text, 'style' => $default_style, 'color' => 'primary' ),
			array(),
			'{{WRAPPER}} .galaxie-buybox-' . $prefix
		);

		$this->end_controls_section();
	}

	/** Spacing between and inside blocks — the thing the previous widget had no answer for at all. */
	private function register_layout_section(): void {
		$this->start_controls_section(
			'layout_section',
			array(
				'label' => __( 'Layout', 'galaxie-woo' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'block_gap',
			array(
				'label'      => __( 'Gap between blocks', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 12 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-buybox' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'inline_actions',
			array(
				'label'        => __( 'Buttons beside quantity', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Lays adjacent quantity and button blocks out on one row.', 'galaxie-woo' ),
			)
		);

		$this->add_responsive_control(
			'variations_cols_gap',
			array(
				'label'      => __( 'Swatch column gap', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 8 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-swatch-options' => 'column-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'variations_rows_gap',
			array(
				'label'      => __( 'Swatch row gap', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 8 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-swatch-options' => 'row-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'label_spacing',
			array(
				'label'      => __( 'Caption spacing', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 6 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-variation-label' => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	private function heading( string $id, string $label, bool $separator = true ): void {
		$this->add_control(
			$id,
			array(
				'label'     => $label,
				'type'      => Controls_Manager::HEADING,
				'separator' => $separator ? 'before' : 'default',
			)
		);
	}

	protected function render(): void {
		$product = $this->current_product();

		if ( ! $product ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div style="padding:2rem;text-align:center;border:1px dashed #ccc;border-radius:8px;">';
				esc_html_e( 'Galaxie Buy Box — place inside a single product template, or anywhere a product is in context.', 'galaxie-woo' );
				echo '</div>';
			}
			return;
		}

		// Takes a post ID or WP_Post, never a WC_Product: the guard clause reads
		// $post->post_type, which a WC_Product has no such property for, so it
		// silently returns false and leaves the globals unset.
		wc_setup_product_data( $product->get_id() );

		$settings = $this->get_settings_for_display();
		$rows     = is_array( $settings['blocks'] ?? null ) ? $settings['blocks'] : array();
		$variable = $product->is_type( 'variable' );

		printf(
			'<form class="cart galaxie-buybox%1$s" method="post" enctype="multipart/form-data" action="%2$s" data-product_id="%3$d" data-product_variations="%4$s">',
			$variable ? ' variations_form' : '',
			esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ),
			(int) $product->get_id(),
			$variable ? wc_esc_json( (string) wp_json_encode( $product->get_available_variations() ) ) : '' // phpcs:ignore WordPress.Security.EscapeOutput -- wc_esc_json() is the escaper.
		);

		if ( $variable ) {
			// WooCommerce enqueues its own variation script from inside
			// woocommerce_variable_add_to_cart(), which this widget replaces —
			// so it has to be asked for explicitly. Without it nothing matches
			// variations at all: no price update, no narrowing of options.
			wp_enqueue_script( 'wc-add-to-cart-variation' );
			$this->render_attribute_selects( $product );
		}

		$this->render_blocks( $rows, $product, $settings );
		$this->render_hidden_fields( $product, $variable );

		echo '</form>';
	}

	/**
	 * Walks the configured order, pairing an adjacent quantity and button into
	 * one row when asked — the common storefront layout, and impossible to get
	 * from the block list alone.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed>            $settings
	 */
	private function render_blocks( array $rows, \WC_Product $product, array $settings ): void {
		$inline  = 'yes' === ( $settings['inline_actions'] ?? 'yes' );
		$buttons = array( 'addcart', 'buynow' );
		$count   = count( $rows );

		for ( $i = 0; $i < $count; $i++ ) {
			$block = (string) ( $rows[ $i ]['block'] ?? '' );
			$next  = (string) ( $rows[ $i + 1 ]['block'] ?? '' );

			$pairs = $inline
				&& ( 'quantity' === $block || in_array( $block, $buttons, true ) )
				&& in_array( $next, $buttons, true );

			if ( ! $pairs ) {
				$this->render_block( $block, $product, $settings );
				continue;
			}

			echo '<div class="galaxie-buybox-row">';
			$this->render_block( $block, $product, $settings );

			// Keep absorbing following button blocks so quantity + Add to Cart
			// + Buy Now all land on the same row rather than just the first two.
			while ( $i + 1 < $count && in_array( (string) ( $rows[ $i + 1 ]['block'] ?? '' ), $buttons, true ) ) {
				++$i;
				$this->render_block( (string) $rows[ $i ]['block'], $product, $settings );
			}
			echo '</div>';
		}
	}

	/**
	 * The real attribute dropdowns, visually hidden. WooCommerce's own
	 * `wc-add-to-cart-variation.js` only recognises a `<select>` inside
	 * `.variations`, and keeping them is what lets it go on matching
	 * variations and firing the events plugins listen for.
	 */
	private function render_attribute_selects( \WC_Product $product ): void {
		echo '<div class="variations galaxie-buybox-attrs" aria-hidden="true">';

		foreach ( $product->get_variation_attributes() as $name => $options ) {
			printf(
				'<select name="attribute_%1$s" data-attribute_name="attribute_%1$s" tabindex="-1"><option value="">%2$s</option>',
				esc_attr( sanitize_title( $name ) ),
				esc_html__( 'Choose an option', 'galaxie-woo' )
			);

			foreach ( $options as $option ) {
				printf(
					'<option value="%s">%s</option>',
					esc_attr( $option ),
					esc_html( self::term_label( $name, $option ) )
				);
			}

			echo '</select>';
		}

		echo '</div>';
	}

	private function render_hidden_fields( \WC_Product $product, bool $variable ): void {
		printf( '<input type="hidden" name="add-to-cart" value="%d" />', (int) $product->get_id() );

		if ( $variable ) {
			printf( '<input type="hidden" name="product_id" value="%d" />', (int) $product->get_id() );
			echo '<input type="hidden" name="variation_id" class="variation_id" value="0" />';
		}

		printf( '<input type="hidden" name="%s" value="" />', esc_attr( Module::BUY_NOW_FIELD ) );
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_block( string $block, \WC_Product $product, array $settings ): void {
		if ( '' === $block ) {
			return;
		}

		// Deliberately NOT `galaxie-buybox-{block}`: the block renderers emit
		// their own element with that exact class, and two nested nodes sharing
		// it meant every rule written for the inner one also hit the wrapper —
		// which is what put the stock line beside the price instead of under it.
		echo '<div class="galaxie-buybox-block galaxie-buybox-block--' . esc_attr( sanitize_html_class( $block ) ) . '">';

		switch ( $block ) {
			case 'price':
				$this->render_price( $settings, $product );
				break;
			case 'variations':
				$this->render_variations( $settings, $product );
				break;
			case 'quantity':
				$this->render_quantity( $settings, $product );
				break;
			case 'addcart':
			case 'buynow':
				$this->render_button( $settings, $block );
				break;
		}

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_price( array $settings, \WC_Product $product ): void {
		// Both slots always ship, because on a variable product there is no
		// price to split until a variation is chosen — the JS fills them from
		// the matched variation's own `price_html`. Rendering only what the
		// parent product currently knows would mean the sale styling could
		// never apply to a variable product at all, which is most of the
		// catalogue here.
		// Kept as markup rather than stripped: `get_price_html()` carries a
		// `screen-reader-text` span ("Price range: X through Y") whose whole
		// job is to be visually hidden, and stripping the tags leaves the text
		// behind with nothing left to hide it.
		$regular = $product->is_on_sale() && '' !== $product->get_regular_price()
			? wc_price( wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ) )
			: $product->get_price_html();

		$sale = $product->is_on_sale() && '' !== $product->get_regular_price()
			? wc_price( wc_get_price_to_display( $product ) )
			: '';

		echo '<div class="galaxie-buybox-price">';

		printf(
			'<span class="galaxie-buybox-price-regular%s">%s</span>',
			'' === $sale ? '' : ' is-struck',
			$this->text( $settings, 'price_regular', $regular ) // phpcs:ignore WordPress.Security.EscapeOutput -- rendered through pixfort's own component.
		);

		printf(
			'<span class="galaxie-buybox-price-sale" data-galaxie-sale="%s"%s>%s</span>',
			esc_attr( (string) ( $settings['sale_display'] ?? 'simple' ) ),
			'' === $sale ? ' hidden' : '',
			'advanced' === ( $settings['sale_display'] ?? 'simple' )
				? $this->badge( $settings, 'price_sale_badge', $sale ) // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
				: $this->text( $settings, 'price_sale', $sale ) // phpcs:ignore WordPress.Security.EscapeOutput -- rendered through pixfort's own component.
		);

		echo '</div>';

		if ( 'yes' === ( $settings['show_stock'] ?? 'yes' ) ) {
			$stock = wp_strip_all_tags( wc_get_stock_html( $product ) );
			echo '<div class="galaxie-buybox-stock">' . $this->text( $settings, 'stock', $stock ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered through pixfort's own component.
		}
	}


	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_variations( array $settings, \WC_Product $product ): void {
		$slug       = (string) ( $settings['attribute'] ?? 'pa_peso' );
		$attributes = $product->get_variation_attributes();

		$options = null;
		foreach ( $attributes as $name => $values ) {
			if ( sanitize_title( $name ) === sanitize_title( $slug ) ) {
				$options = $values;
				$slug    = $name;
				break;
			}
		}

		if ( ! $options ) {
			return;
		}

		printf(
			'<div class="galaxie-variation-picker" data-galaxie-attribute="%s">',
			esc_attr( sanitize_title( $slug ) )
		);

		if ( 'yes' === ( $settings['show_label'] ?? 'yes' ) ) {
			$caption = trim( (string) ( $settings['label_text'] ?? '' ) );
			if ( '' === $caption ) {
				$caption = wc_attribute_label( $slug, $product );
			}
			echo '<div class="galaxie-variation-label">' . $this->text( $settings, 'label', $caption ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered through pixfort's own component.
		}

		echo '<div class="galaxie-swatch-options">';
		foreach ( $options as $option ) {
			printf( '<button type="button" class="galaxie-swatch-option" data-value="%s">', esc_attr( $option ) );
			echo '<span class="galaxie-swatch-normal">' . $this->badge( $settings, 'badge', self::term_label( $slug, $option ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
			echo '<span class="galaxie-swatch-selected">' . $this->badge( $settings, 'badgesel', self::term_label( $slug, $option ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
			echo '</button>';
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_quantity( array $settings, \WC_Product $product ): void {
		echo '<div class="galaxie-buybox-quantity">';

		if ( 'select' === ( $settings['qty_style'] ?? 'input' ) ) {
			$this->render_quantity_select( $product, (int) ( $settings['qty_max'] ?? 5 ) );
		} else {
			woocommerce_quantity_input( array(), $product );
		}

		echo '</div>';
	}

	/**
	 * Dropdown alternative to the number input. Carries `.quantity` and `.qty`
	 * so it inherits exactly the same styling — ours and the theme's — as the
	 * number input it replaces, rather than arriving unstyled.
	 */
	private function render_quantity_select( \WC_Product $product, int $max ): void {
		$min         = max( 1, (int) $product->get_min_purchase_quantity() );
		$product_max = (int) $product->get_max_purchase_quantity();

		if ( $product_max > 0 ) {
			$max = min( $max, $product_max );
		}
		$max = max( $min, $max );

		echo '<div class="quantity galaxie-quantity-select">';
		echo '<select name="quantity" class="qty">';
		for ( $i = $min; $i <= $max; $i++ ) {
			printf( '<option value="%1$d">%1$d</option>', $i );
		}
		echo '</select>';
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_button( array $settings, string $prefix ): void {
		$text = (string) ( $settings[ $prefix . '_text' ] ?? '' );

		// Both are real submit buttons: with JS off the form still posts and
		// WooCommerce still adds the item. The JS upgrades Add to Cart to AJAX
		// and flags Buy Now for the checkout redirect. `single_add_to_cart_button`
		// is kept on Add to Cart because WooCommerce's own variation JS toggles
		// its disabled state as combinations narrow.
		printf(
			'<button type="submit" class="galaxie-buybox-btn galaxie-buybox-%s%s">',
			esc_attr( $prefix ),
			'addcart' === $prefix ? ' single_add_to_cart_button' : ''
		);

		if ( PixfortControls::available() ) {
			echo \PixfortCore::instance()->elementsManager->renderElement( 'Button', PixfortControls::button_attr( $settings, $prefix, $text ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
		} else {
			echo '<span class="btn">' . esc_html( $text ) . '</span>';
		}

		echo '</button>';
	}

	/**
	 * `PixText::render()` reads the text from its SECOND argument and ignores
	 * `$attr['content']` entirely — passing it only in the attributes renders
	 * an empty paragraph.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function text( array $settings, string $prefix, string $content ): string {
		if ( PixfortControls::available() ) {
			return \PixfortCore::instance()->elementsManager->renderElement( 'Text', PixfortControls::text_attr( $settings, $prefix, $content ), $content );
		}

		return '<span>' . wp_kses_post( $content ) . '</span>';
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function badge( array $settings, string $prefix, string $text ): string {
		if ( PixfortControls::available() ) {
			return \PixfortCore::instance()->elementsManager->renderElement( 'Badge', PixfortControls::badge_attr( $settings, $prefix, $text ) );
		}

		return '<span class="galaxie-swatch-badge">' . esc_html( $text ) . '</span>';
	}

	/** A taxonomy attribute stores slugs; the shopper should see the term name. */
	private static function term_label( string $taxonomy, string $option ): string {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $option;
		}

		$term = get_term_by( 'slug', $option, $taxonomy );

		return $term instanceof \WP_Term ? $term->name : $option;
	}

	/** @return array<string,string> */
	private static function block_options(): array {
		return array(
			'price'      => __( 'Price', 'galaxie-woo' ),
			'variations' => __( 'Variations', 'galaxie-woo' ),
			'quantity'   => __( 'Quantity', 'galaxie-woo' ),
			'addcart'    => __( 'Add to Cart', 'galaxie-woo' ),
			'buynow'     => __( 'Buy Now', 'galaxie-woo' ),
		);
	}

	/** @return array<string,string> */
	private static function attribute_options(): array {
		$options = array();

		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
				$slug             = wc_attribute_taxonomy_name( $taxonomy->attribute_name );
				$options[ $slug ] = $taxonomy->attribute_label;
			}
		}

		return $options ? $options : array( 'pa_peso' => 'pa_peso' );
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
}

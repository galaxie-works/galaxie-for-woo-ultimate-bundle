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
 * `reset_data` — the events third-party plugins actually bind to. Rewriting
 * that matcher would have bought nothing and quietly dropped that ecosystem.
 *
 * Block presence lives in the repeater rows rather than in separate switches:
 * deleting a row is how a block is turned off, so an unwanted block leaves
 * neither markup on the canvas nor an orphaned control in the panel.
 */
final class BuyBoxWidget extends Widget_Base {

	/** Scopes a repeater row's `selectors` to that row, so rows can't style each other. */
	private const ROW_SCOPE = '{{WRAPPER}} {{CURRENT_ITEM}}';

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
		$this->register_layout_section();
	}

	private function register_blocks_section(): void {
		$this->start_controls_section(
			'blocks_section',
			array( 'label' => __( 'Blocks', 'galaxie-woo' ) )
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

		$this->add_price_controls( $repeater );
		$this->add_variations_controls( $repeater );
		$this->add_quantity_controls( $repeater );

		// One button control set serves both button blocks: the row's own type
		// decides which of them it is, so a second prefixed copy would only
		// duplicate 25 controls to say the same thing.
		PixfortControls::button(
			$repeater,
			'btn',
			array( 'text' => __( 'Adicionar ao carrinho', 'galaxie-woo' ), 'color' => 'primary' ),
			array( 'block' => array( 'addcart', 'buynow' ) ),
			self::ROW_SCOPE
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
					array( 'block' => 'addcart', 'btn_text' => __( 'Adicionar ao carrinho', 'galaxie-woo' ) ),
				),
			)
		);

		$this->end_controls_section();
	}

	private function add_price_controls( Repeater $repeater ): void {
		$price = array( 'block' => 'price' );

		PixfortControls::text( $repeater, 'price_regular', array( 'size' => 'h4', 'bold' => 'font-weight-bold' ), $price, self::ROW_SCOPE );

		$repeater->add_control(
			'sale_display',
			array(
				'label'     => __( 'Sale price display', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'simple'   => __( 'Simple (text)', 'galaxie-woo' ),
					'advanced' => __( 'Advanced (badge)', 'galaxie-woo' ),
				),
				'default'   => 'simple',
				'condition' => $price,
			)
		);

		PixfortControls::text( $repeater, 'price_sale', array( 'size' => 'h4', 'bold' => 'font-weight-bold' ), $price + array( 'sale_display' => 'simple' ), self::ROW_SCOPE );
		PixfortControls::badge( $repeater, 'price_sale_badge', array(), $price + array( 'sale_display' => 'advanced' ), self::ROW_SCOPE );

		$repeater->add_control(
			'show_stock',
			array(
				'label'        => __( 'Show stock', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => $price,
			)
		);

		PixfortControls::text( $repeater, 'stock', array(), $price + array( 'show_stock' => 'yes' ), self::ROW_SCOPE );
	}

	private function add_variations_controls( Repeater $repeater ): void {
		$variations = array( 'block' => 'variations' );

		$repeater->add_control(
			'attribute',
			array(
				'label'     => __( 'Attribute', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => self::attribute_options(),
				'default'   => 'pa_peso',
				'condition' => $variations,
			)
		);

		$repeater->add_control(
			'label_text',
			array(
				'label'       => __( 'Caption', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Defaults to the attribute name', 'galaxie-woo' ),
				'condition'   => $variations,
			)
		);

		PixfortControls::text( $repeater, 'label', array( 'bold' => 'font-weight-bold' ), $variations, self::ROW_SCOPE );
		PixfortControls::badge( $repeater, 'badge', array( 'text_color' => 'primary', 'bg_color' => 'primary-light' ), $variations, self::ROW_SCOPE );
		PixfortControls::badge( $repeater, 'badgesel', array( 'text_color' => 'white', 'bg_color' => 'primary' ), $variations, self::ROW_SCOPE );
	}

	private function add_quantity_controls( Repeater $repeater ): void {
		$quantity = array( 'block' => 'quantity' );

		$repeater->add_control(
			'qty_type',
			array(
				'label'     => __( 'Quantity field', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'input'  => __( 'Number input (+/-)', 'galaxie-woo' ),
					'select' => __( 'Dropdown', 'galaxie-woo' ),
				),
				'default'   => 'input',
				'condition' => $quantity,
			)
		);

		$repeater->add_control(
			'qty_max',
			array(
				'label'     => __( 'Dropdown goes up to', 'galaxie-woo' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 50,
				'default'   => 5,
				'condition' => $quantity + array( 'qty_type' => 'select' ),
			)
		);

		$repeater->add_responsive_control(
			'qty_width',
			array(
				'label'      => __( 'Field width', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array( 'px' => array( 'min' => 60, 'max' => 400 ) ),
				'selectors'  => array( self::ROW_SCOPE . ' .quantity' => 'width: {{SIZE}}{{UNIT}};' ),
				'condition'  => $quantity,
			)
		);
	}

	/**
	 * Spacing between and inside blocks — the thing the previous widget had no
	 * answer for at all.
	 */
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
			$this->render_attribute_selects( $product );
		}

		foreach ( $rows as $row ) {
			$this->render_block( $row, $product, $settings );
		}

		$this->render_hidden_fields( $product, $variable );

		echo '</form>';
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
				$label = taxonomy_exists( $name )
					? ( get_term_by( 'slug', $option, $name )->name ?? $option )
					: $option;

				printf(
					'<option value="%s">%s</option>',
					esc_attr( $option ),
					esc_html( $label )
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
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $settings
	 */
	private function render_block( array $row, \WC_Product $product, array $settings ): void {
		$block = (string) ( $row['block'] ?? '' );
		$class = 'galaxie-buybox-block galaxie-buybox-' . sanitize_html_class( $block )
			. ' elementor-repeater-item-' . sanitize_html_class( (string) ( $row['_id'] ?? '' ) );

		echo '<div class="' . esc_attr( $class ) . '">';

		switch ( $block ) {
			case 'price':
				$this->render_price( $row, $product );
				break;
			case 'variations':
				$this->render_variations( $row, $product );
				break;
			case 'quantity':
				$this->render_quantity( $row, $product );
				break;
			case 'addcart':
			case 'buynow':
				$this->render_button( $row, $block );
				break;
		}

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function render_price( array $row, \WC_Product $product ): void {
		$regular = $product->get_regular_price();
		$active  = $product->get_price();
		$on_sale = $product->is_on_sale();

		echo '<div class="galaxie-buybox-price">';

		if ( $on_sale && '' !== $regular ) {
			echo '<del class="galaxie-buybox-price-regular">' . wp_kses_post( wc_price( wc_get_price_to_display( $product, array( 'price' => $regular ) ) ) ) . '</del>';
			$this->render_sale( $row, $product, (string) $active );
		} else {
			echo '<span class="galaxie-buybox-price-current">' . $this->text( $row, 'price_regular', $product->get_price_html() ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered through pixfort's own component.
		}

		echo '</div>';

		if ( 'yes' === ( $row['show_stock'] ?? 'yes' ) ) {
			echo '<div class="galaxie-buybox-stock">' . $this->text( $row, 'stock', wp_strip_all_tags( wc_get_stock_html( $product ) ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered through pixfort's own component.
		}
	}

	/**
	 * The sale price either reads as styled text or as a pixfort badge — the
	 * two are different enough visually that one control set could not serve
	 * both without becoming a compromise.
	 *
	 * @param array<string,mixed> $row
	 */
	private function render_sale( array $row, \WC_Product $product, string $price ): void {
		$formatted = wp_strip_all_tags( wc_price( wc_get_price_to_display( $product, array( 'price' => $price ) ) ) );

		if ( 'advanced' === ( $row['sale_display'] ?? 'simple' ) && PixfortControls::available() ) {
			echo '<span class="galaxie-buybox-price-sale galaxie-badge-price_sale_badge">';
			echo \PixfortCore::instance()->elementsManager->renderElement( 'Badge', PixfortControls::badge_attr( $row, 'price_sale_badge', $formatted ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
			echo '</span>';
			return;
		}

		echo '<span class="galaxie-buybox-price-sale">' . $this->text( $row, 'price_sale', $formatted ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered through pixfort's own component.
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function render_variations( array $row, \WC_Product $product ): void {
		$slug       = (string) ( $row['attribute'] ?? 'pa_peso' );
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

		$caption = trim( (string) ( $row['label_text'] ?? '' ) );
		if ( '' === $caption ) {
			$caption = wc_attribute_label( $slug, $product );
		}

		printf(
			'<div class="galaxie-variation-picker" data-galaxie-attribute="%s">',
			esc_attr( sanitize_title( $slug ) )
		);

		echo '<div class="galaxie-variation-label">' . $this->text( $row, 'label', $caption ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered through pixfort's own component.

		echo '<div class="galaxie-swatch-options">';
		foreach ( $options as $option ) {
			$label = taxonomy_exists( $slug )
				? ( get_term_by( 'slug', $option, $slug )->name ?? $option )
				: $option;

			printf( '<button type="button" class="galaxie-swatch-option" data-value="%s">', esc_attr( $option ) );
			echo '<span class="galaxie-swatch-normal">' . $this->badge( $row, 'badge', $label ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
			echo '<span class="galaxie-swatch-selected">' . $this->badge( $row, 'badgesel', $label ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
			echo '</button>';
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function render_quantity( array $row, \WC_Product $product ): void {
		echo '<div class="galaxie-buybox-quantity">';

		if ( 'select' === ( $row['qty_type'] ?? 'input' ) ) {
			$this->render_quantity_select( $product, (int) ( $row['qty_max'] ?? 5 ) );
		} else {
			woocommerce_quantity_input( array(), $product );
		}

		echo '</div>';
	}

	/**
	 * Dropdown alternative to the number input. Keeps `name="quantity"` and the
	 * `qty` class so both WooCommerce and our own JS treat it like any other
	 * quantity field.
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
	 * @param array<string,mixed> $row
	 */
	private function render_button( array $row, string $block ): void {
		$text = (string) ( $row['btn_text'] ?? '' );

		// Both are real submit buttons: with JS off the form still posts and
		// WooCommerce still adds the item. The JS upgrades Add to Cart to AJAX
		// and flags Buy Now for the checkout redirect. `single_add_to_cart_button`
		// is kept on Add to Cart because WooCommerce's own variation JS toggles
		// its disabled state as combinations narrow.
		printf(
			'<button type="submit" class="galaxie-buybox-btn %s">',
			'addcart' === $block ? 'galaxie-buybox-addcart single_add_to_cart_button' : 'galaxie-buybox-buynow'
		);

		if ( PixfortControls::available() ) {
			echo \PixfortCore::instance()->elementsManager->renderElement( 'Button', PixfortControls::button_attr( $row, 'btn', $text ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
		} else {
			echo '<span class="btn">' . esc_html( $text ) . '</span>';
		}

		echo '</button>';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function text( array $row, string $prefix, string $content ): string {
		if ( PixfortControls::available() ) {
			return \PixfortCore::instance()->elementsManager->renderElement( 'Text', PixfortControls::text_attr( $row, $prefix, $content ) );
		}

		return '<span>' . wp_kses_post( $content ) . '</span>';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function badge( array $row, string $prefix, string $text ): string {
		if ( PixfortControls::available() ) {
			return \PixfortCore::instance()->elementsManager->renderElement( 'Badge', PixfortControls::badge_attr( $row, $prefix, $text ) );
		}

		return '<span class="galaxie-swatch-badge">' . esc_html( $text ) . '</span>';
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

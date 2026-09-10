<?php
/**
 * The cart, in pieces the three cart widgets share.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

use Elementor\Controls_Manager;
use Elementor\Repeater;

defined( 'ABSPATH' ) || exit;

/**
 * Why three widgets exist, and why their insides live here.
 *
 * "Galaxie Cart" renders the table and the totals together, which is what most
 * carts need and the fastest thing to drop into a template. But welding them
 * together is also what stops an Elementor container from putting the totals in
 * a sticky right-hand column — so "Galaxie Cart Table" and "Galaxie Cart
 * Totals" exist for that, and the layout stays the builder's job rather than
 * becoming a "columns / reverse columns" control inside a widget. XStore ships
 * both shapes for the same reason.
 *
 * Three widgets rendering a cart three slightly different ways is exactly how
 * a cart ends up looking like three carts, so none of them owns any markup:
 * the parts are here, and each widget only decides which ones to call.
 */
final class CartParts {

	/** Every column a line can show. Order comes from the repeater, not here. */
	public const FIELDS = array(
		'thumb'    => 'Image',
		'name'     => 'Product',
		'price'    => 'Price',
		'quantity' => 'Quantity',
		'subtotal' => 'Subtotal',
		'remove'   => 'Remove',
	);

	public static function available(): bool {
		return function_exists( 'WC' ) && null !== WC()->cart;
	}

	/* ---------------------------------------------------------------------
	 * Controls
	 * ------------------------------------------------------------------ */

	public static function register_line_controls( object $widget ): void {
		$widget->start_controls_section( 'lines_section', array( 'label' => __( 'Line fields', 'galaxie-woo' ) ) );

		$widget->add_control(
			'lines_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Drag to reorder. Remove a row to leave that field out of every line.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$repeater = new Repeater();
		$repeater->add_control(
			'field',
			array(
				'label'   => __( 'Field', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => self::field_options(),
				'default' => 'thumb',
			)
		);
		$repeater->add_control(
			'heading',
			array(
				'label'       => __( 'Column heading', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
			)
		);

		$widget->add_control(
			'lines',
			array(
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ field }}}',
				'default'     => array(
					array( 'field' => 'thumb', 'heading' => '' ),
					array( 'field' => 'name', 'heading' => __( 'Produto', 'galaxie-woo' ) ),
					array( 'field' => 'price', 'heading' => __( 'Preço', 'galaxie-woo' ) ),
					array( 'field' => 'quantity', 'heading' => __( 'Quantidade', 'galaxie-woo' ) ),
					array( 'field' => 'subtotal', 'heading' => __( 'Subtotal', 'galaxie-woo' ) ),
					array( 'field' => 'remove', 'heading' => '' ),
				),
			)
		);

		$widget->add_control(
			'show_headings',
			array(
				'label'        => __( 'Show column headings', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		PixfortControls::icon_select( $widget, 'remove_icon', __( 'Remove icon', 'galaxie-woo' ), 'Line/pixfort-icon-trash-can-1' );

		$widget->end_controls_section();
	}

	public static function register_behaviour_controls( object $widget ): void {
		$widget->start_controls_section( 'behaviour_section', array( 'label' => __( 'Behaviour', 'galaxie-woo' ) ) );

		$widget->add_control(
			'auto_update',
			array(
				'label'        => __( 'Update automatically', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Changing a quantity updates the cart straight away. With this off, the shopper presses Update cart and the page reloads — WooCommerce\'s own behaviour.', 'galaxie-woo' ),
			)
		);

		$widget->add_control(
			'update_text',
			array(
				'label'     => __( 'Update button', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Atualizar carrinho', 'galaxie-woo' ),
				'condition' => array( 'auto_update' => '' ),
			)
		);

		$widget->add_control(
			'show_coupon',
			array(
				'label'        => __( 'Coupon field', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$widget->add_control(
			'coupon_placeholder',
			array(
				'label'     => __( 'Coupon placeholder', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Cupom de desconto', 'galaxie-woo' ),
				'condition' => array( 'show_coupon' => 'yes' ),
			)
		);

		$widget->add_control(
			'coupon_text',
			array(
				'label'     => __( 'Coupon button', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Aplicar', 'galaxie-woo' ),
				'condition' => array( 'show_coupon' => 'yes' ),
			)
		);

		$widget->end_controls_section();
	}

	public static function register_totals_controls( object $widget ): void {
		$widget->start_controls_section( 'totals_section', array( 'label' => __( 'Totals', 'galaxie-woo' ) ) );

		$widget->add_control(
			'totals_heading',
			array(
				'label'   => __( 'Heading', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Resumo', 'galaxie-woo' ),
			)
		);

		$widget->add_control(
			'show_shipping',
			array(
				'label'        => __( 'Shipping row', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$widget->add_control(
			'checkout_text',
			array(
				'label'   => __( 'Checkout button', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Finalizar compra', 'galaxie-woo' ),
			)
		);

		$widget->add_control(
			'show_continue',
			array(
				'label'        => __( 'Continue shopping link', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$widget->add_control(
			'continue_text',
			array(
				'label'     => __( 'Continue shopping', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Continuar comprando', 'galaxie-woo' ),
				'condition' => array( 'show_continue' => 'yes' ),
			)
		);

		$widget->end_controls_section();
	}

	public static function register_empty_controls( object $widget ): void {
		$widget->start_controls_section( 'empty_section', array( 'label' => __( 'Empty cart', 'galaxie-woo' ) ) );

		$widget->add_control(
			'empty_mode',
			array(
				'label'   => __( 'Show', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'message',
				'options' => array(
					'message'  => __( 'A message and a button', 'galaxie-woo' ),
					'template' => __( 'A saved Elementor template', 'galaxie-woo' ),
				),
			)
		);

		$widget->add_control(
			'empty_template',
			array(
				'label'       => __( 'Template', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => self::template_options(),
				'label_block' => true,
				'condition'   => array( 'empty_mode' => 'template' ),
			)
		);

		$widget->add_control(
			'empty_text',
			array(
				'label'       => __( 'Message', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => __( 'Seu carrinho está vazio.', 'galaxie-woo' ),
				'label_block' => true,
				'condition'   => array( 'empty_mode' => 'message' ),
			)
		);

		$widget->add_control(
			'empty_button_text',
			array(
				'label'     => __( 'Button', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Ver produtos', 'galaxie-woo' ),
				'condition' => array( 'empty_mode' => 'message' ),
			)
		);

		$widget->end_controls_section();
	}

	public static function register_table_style( object $widget ): void {
		$widget->start_controls_section( 'thumb_style', array( 'label' => __( 'Image', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::thumb( $widget, 'thumb', '{{WRAPPER}} .galaxie-cart-thumb img' );
		$widget->end_controls_section();

		$widget->start_controls_section( 'quantity_style', array( 'label' => __( 'Quantity', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		// The buy box's own set, so a spinner cannot look like two things.
		PixfortControls::quantity( $widget, 'qty', '{{WRAPPER}} .galaxie-cart-qty .quantity', '{{WRAPPER}} .galaxie-cart-qty .qty' );
		$widget->end_controls_section();

		$widget->start_controls_section( 'name_style', array( 'label' => __( 'Product name', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $widget, 'name', array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-cart-name' );
		$widget->end_controls_section();

		$widget->start_controls_section( 'price_style', array( 'label' => __( 'Prices', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $widget, 'price', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-cart-price, {{WRAPPER}} .galaxie-cart-subtotal' );
		$widget->end_controls_section();

		$widget->start_controls_section( 'remove_style', array( 'label' => __( 'Remove', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::icon_color( $widget, 'remove_icon_color', __( 'Icon color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-cart-remove' );
		$widget->add_responsive_control(
			'remove_size',
			array(
				'label'      => __( 'Icon size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 10, 'max' => 48 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 20 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-remove svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);
		$widget->end_controls_section();

		$widget->start_controls_section( 'layout_style', array( 'label' => __( 'Layout', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		$widget->add_responsive_control(
			'row_gap',
			array(
				'label'      => __( 'Line padding', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 16 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-line' => 'padding-top: {{SIZE}}{{UNIT}}; padding-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);
		$widget->add_responsive_control(
			'field_gap',
			array(
				'label'      => __( 'Gap between fields', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 16 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-line' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$widget->end_controls_section();
	}

	public static function register_totals_style( object $widget ): void {
		$widget->start_controls_section( 'checkout_style', array( 'label' => __( 'Checkout button', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::button( $widget, 'checkout', array( 'full' => 'w-100', 'size' => 'lg' ) );
		$widget->end_controls_section();

		$widget->start_controls_section( 'box_style', array( 'label' => __( 'Totals box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::palette_control( $widget, 'box_bg', __( 'Background', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-cart-totals', 'background-color' );
		$widget->add_responsive_control(
			'box_padding',
			array(
				'label'      => __( 'Padding', 'galaxie-woo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'rem' ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-totals' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$widget->add_control(
			'box_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-totals' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);
		$widget->end_controls_section();
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * A stored icon identifier turned into pixfort's own SVG.
	 *
	 * The stored value is a path like `Line/pixfort-icon-trash-can-1`, and
	 * echoing it is how the remove column once printed that string on screen to
	 * a shopper. `getIcon()` is what turns it into markup — and it returns an
	 * empty string for a name it cannot resolve, so a bare × stands in rather
	 * than an invisible link.
	 */
	public static function icon_markup( string $identifier ): string {
		if ( '' === $identifier || ! class_exists( '\PixfortCore' ) ) {
			return '&times;';
		}

		$svg = (string) \PixfortCore::instance()->icons->getIcon( $identifier, 20, 'galaxie-cart-remove-icon' );

		return '' !== trim( $svg ) ? $svg : '&times;';
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<int,array<string,mixed>>
	 */
	public static function fields( array $settings ): array {
		$fields = array();

		foreach ( (array) ( $settings['lines'] ?? array() ) as $row ) {
			if ( isset( $row['field'] ) && isset( self::FIELDS[ $row['field'] ] ) ) {
				$fields[] = $row;
			}
		}

		return $fields;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	public static function render_table( array $settings ): void {
		$fields = self::fields( $settings );

		printf(
			'<form class="woocommerce-cart-form galaxie-cart-form" action="%s" method="post" data-galaxie-auto="%s">',
			esc_url( wc_get_cart_url() ),
			esc_attr( 'yes' === ( $settings['auto_update'] ?? 'yes' ) ? '1' : '0' )
		);

		if ( 'yes' === ( $settings['show_headings'] ?? 'yes' ) ) {
			echo '<div class="galaxie-cart-head galaxie-cart-line">';
			foreach ( $fields as $row ) {
				printf(
					'<div class="galaxie-cart-cell galaxie-cart-cell-%s">%s</div>',
					esc_attr( $row['field'] ),
					esc_html( (string) ( $row['heading'] ?? '' ) )
				);
			}
			echo '</div>';
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product instanceof \WC_Product || ! apply_filters( 'woocommerce_cart_item_visible', true, $item, $key ) ) {
				continue;
			}

			printf( '<div class="galaxie-cart-line" data-galaxie-key="%s">', esc_attr( $key ) );

			foreach ( $fields as $row ) {
				printf( '<div class="galaxie-cart-cell galaxie-cart-cell-%s">', esc_attr( $row['field'] ) );
				self::render_field( (string) $row['field'], $key, $item, $product, $settings );
				echo '</div>';
			}

			echo '</div>';
		}

		self::render_actions( $settings );

		wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' );
		echo '</form>';
	}

	/**
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $settings
	 */
	private static function render_field( string $field, string $key, array $item, \WC_Product $product, array $settings ): void {
		switch ( $field ) {
			case 'thumb':
				$link = $product->is_visible() ? $product->get_permalink( $item ) : '';
				$img  = $product->get_image( 'woocommerce_thumbnail' );
				printf(
					'<span class="galaxie-cart-thumb">%s</span>',
					$link
						? sprintf( '<a href="%s">%s</a>', esc_url( $link ), $img ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_image() returns escaped markup.
						: $img // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same.
				);
				break;

			case 'name':
				$name = apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $item, $key );
				$link = $product->is_visible() ? $product->get_permalink( $item ) : '';
				printf(
					'<span class="galaxie-cart-name">%s</span>',
					$link
						? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( wp_strip_all_tags( $name ) ) )
						: esc_html( wp_strip_all_tags( $name ) )
				);

				$meta = wc_get_formatted_cart_item_data( $item, true );
				if ( $meta ) {
					printf( '<span class="galaxie-cart-meta">%s</span>', esc_html( $meta ) );
				}
				break;

			case 'price':
				printf(
					'<span class="galaxie-cart-price">%s</span>',
					wp_kses_post( apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $product ), $item, $key ) )
				);
				break;

			case 'quantity':
				self::render_quantity( $key, $item, $product );
				break;

			case 'subtotal':
				printf(
					'<span class="galaxie-cart-subtotal">%s</span>',
					wp_kses_post( apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $product, $item['quantity'] ), $item, $key ) )
				);
				break;

			case 'remove':
				printf(
					'<a href="%s" class="galaxie-cart-remove" aria-label="%s" data-galaxie-remove="%s">%s</a>',
					esc_url( wc_get_cart_remove_url( $key ) ),
					esc_attr__( 'Remove this item', 'galaxie-woo' ),
					esc_attr( $key ),
					self::icon_markup( PixfortControls::icon_value( $settings, 'remove_icon' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own SVG.
				);
				break;
		}
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private static function render_quantity( string $key, array $item, \WC_Product $product ): void {
		echo '<span class="galaxie-cart-qty">';

		if ( $product->is_sold_individually() ) {
			printf( '<input type="hidden" name="cart[%s][qty]" value="1" />', esc_attr( $key ) );
			echo '<span class="galaxie-cart-qty-fixed">1</span>';
		} else {
			woocommerce_quantity_input(
				array(
					'input_name'   => "cart[{$key}][qty]",
					'input_value'  => $item['quantity'],
					'max_value'    => $product->get_max_purchase_quantity(),
					'min_value'    => '0',
					'product_name' => $product->get_name(),
				),
				$product,
				true
			);
		}

		echo '</span>';
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private static function render_actions( array $settings ): void {
		echo '<div class="galaxie-cart-actions">';

		if ( 'yes' === ( $settings['show_coupon'] ?? 'yes' ) && wc_coupons_enabled() ) {
			printf(
				'<div class="galaxie-cart-coupon"><input type="text" name="coupon_code" class="input-text" placeholder="%s" /><button type="submit" name="apply_coupon" value="1" class="galaxie-cart-coupon-apply">%s</button></div>',
				esc_attr( (string) ( $settings['coupon_placeholder'] ?? '' ) ),
				esc_html( (string) ( $settings['coupon_text'] ?? '' ) )
			);
		}

		// Kept in the markup and hidden by CSS while the script runs, so a
		// shopper without JavaScript still has a way to commit a change.
		printf(
			'<button type="submit" name="update_cart" value="1" class="galaxie-cart-update">%s</button>',
			esc_html( (string) ( $settings['update_text'] ?? __( 'Atualizar carrinho', 'galaxie-woo' ) ) )
		);

		echo '</div>';
	}

	/**
	 * The totals block.
	 *
	 * The rows are WooCommerce's own helper functions, in WooCommerce's own
	 * order and under WooCommerce's own conditions — every tax and shipping
	 * rule a store has configured still decides what appears. What is NOT used
	 * is `woocommerce_cart_totals()`, which loads the whole cart-totals
	 * template: that template brings its own `<h2>Cart totals</h2>`, its own
	 * subtotal row and its own Proceed to checkout button, so calling it and
	 * then adding a heading and a checkout button of our own printed each of
	 * them twice. That is exactly what it did on the first cut.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function totals_markup( array $settings = array() ): string {
		if ( ! self::available() ) {
			return '';
		}

		$cart     = WC()->cart;
		$heading  = (string) ( $settings['totals_heading'] ?? '' );
		$checkout = (string) ( $settings['checkout_text'] ?? __( 'Finalizar compra', 'galaxie-woo' ) );

		ob_start();
		echo '<div class="galaxie-cart-totals">';

		if ( '' !== $heading ) {
			printf( '<h3 class="galaxie-cart-totals-heading">%s</h3>', esc_html( $heading ) );
		}

		echo '<div class="galaxie-cart-totals-rows">';

		self::row( __( 'Subtotal', 'woocommerce' ), self::capture( 'wc_cart_totals_subtotal_html' ), 'subtotal' );

		foreach ( $cart->get_coupons() as $code => $coupon ) {
			self::row(
				wc_cart_totals_coupon_label( $coupon, false ),
				self::capture( 'wc_cart_totals_coupon_html', $coupon ),
				'coupon coupon-' . sanitize_title( $code )
			);
		}

		if ( 'yes' === ( $settings['show_shipping'] ?? 'yes' ) && $cart->needs_shipping() && $cart->show_shipping() ) {
			// This one prints its own <tr>s, so it is given the row slot whole
			// rather than wrapped — its markup is what the shipping methods and
			// the calculator link expect.
			echo '<div class="galaxie-cart-total-row galaxie-cart-total-shipping">' . self::capture( 'wc_cart_totals_shipping_html' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own markup.
		}

		foreach ( $cart->get_fees() as $fee ) {
			self::row( $fee->name, self::capture( 'wc_cart_totals_fee_html', $fee ), 'fee' );
		}

		if ( wc_tax_enabled() && ! $cart->display_prices_including_tax() ) {
			if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) {
				foreach ( $cart->get_tax_totals() as $tax ) {
					self::row( $tax->label, wp_kses_post( $tax->formatted_amount ), 'tax' );
				}
			} else {
				self::row( WC()->countries->tax_or_vat(), self::capture( 'wc_cart_totals_taxes_total_html' ), 'tax' );
			}
		}

		self::row( __( 'Total', 'woocommerce' ), self::capture( 'wc_cart_totals_order_total_html' ), 'order-total' );

		echo '</div>';

		printf(
			'<a class="galaxie-cart-checkout" href="%s">%s</a>',
			esc_url( wc_get_checkout_url() ),
			esc_html( $checkout )
		);

		if ( 'yes' === ( $settings['show_continue'] ?? '' ) ) {
			printf(
				'<a class="galaxie-cart-continue" href="%s">%s</a>',
				esc_url( wc_get_page_permalink( 'shop' ) ),
				esc_html( (string) ( $settings['continue_text'] ?? '' ) )
			);
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/** One label/value pair in the totals block. */
	private static function row( string $label, string $value, string $modifier ): void {
		printf(
			'<div class="galaxie-cart-total-row galaxie-cart-total-%s"><span class="galaxie-cart-total-label">%s</span><span class="galaxie-cart-total-value">%s</span></div>',
			esc_attr( $modifier ),
			esc_html( wp_strip_all_tags( $label ) ),
			$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own formatted amount.
		);
	}

	/**
	 * WooCommerce's totals helpers echo rather than return.
	 *
	 * @param mixed ...$args
	 */
	private static function capture( string $fn, ...$args ): string {
		if ( ! function_exists( $fn ) ) {
			return '';
		}

		ob_start();
		$fn( ...$args );

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	public static function render_empty( array $settings ): void {
		$template = 'template' === ( $settings['empty_mode'] ?? 'message' )
			? (int) ( $settings['empty_template'] ?? 0 )
			: 0;

		if ( $template ) {
			$content = self::rendered_template( $template );

			// A template that was deleted, or emptied, must not leave a blank
			// page where a cart used to be.
			if ( '' !== trim( $content ) ) {
				echo '<div class="galaxie-cart galaxie-cart-empty galaxie-cart-empty-template">'
					. $content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor renders its own escaped markup.
					. '</div>';
				return;
			}
		}

		printf(
			'<div class="galaxie-cart galaxie-cart-empty"><p>%s</p><a class="galaxie-cart-empty-button" href="%s">%s</a></div>',
			esc_html( (string) ( $settings['empty_text'] ?? '' ) ),
			esc_url( wc_get_page_permalink( 'shop' ) ),
			esc_html( (string) ( $settings['empty_button_text'] ?? '' ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Option lists
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,string>
	 */
	private static function field_options(): array {
		$options = array();

		foreach ( self::FIELDS as $key => $label ) {
			$options[ $key ] = __( $label, 'galaxie-woo' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- a fixed, known list.
		}

		return $options;
	}

	/**
	 * Read from the library post type rather than through an Elementor helper:
	 * a helper that changes empties this dropdown silently, which is the
	 * failure mode this project has paid for most often.
	 *
	 * @return array<int|string,string>
	 */
	private static function template_options(): array {
		$options = array( '' => __( '— Select —', 'galaxie-woo' ) );

		foreach ( get_posts(
			array(
				'post_type'        => 'elementor_library',
				'post_status'      => 'publish',
				'posts_per_page'   => 100,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		) as $template ) {
			$options[ $template->ID ] = '' !== $template->post_title
				? $template->post_title
				/* translators: %d is a template ID. */
				: sprintf( __( 'Template #%d', 'galaxie-woo' ), $template->ID );
		}

		return $options;
	}

	private static function rendered_template( int $id ): string {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! get_post( $id ) ) {
			return '';
		}

		return (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $id, true );
	}
}

<?php
/**
 * "Galaxie Cart" Elementor widget — the cart page as one configurable block.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Cart\Widget;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * One widget, dropped into the cart template and configured — not behaviour
 * bolted onto WooCommerce's template, and not a pile of separate parts.
 *
 * The line fields are a repeater, so their ORDER is the render order and
 * removing a row is how a column is turned off. That is the same shape the buy
 * box uses, and the same shape XStore's cart widget uses for its "Sortable
 * Fields", which is reassuring: three independent designs landing on the same
 * control means it is the one people expect.
 *
 * Styling comes from the shared registrar rather than from controls invented
 * here. The quantity stepper in particular is {@see PixfortControls::quantity()}
 * — the very same set the buy box uses — because a shopper who meets one
 * spinner on the product page and a different one in the cart is looking at two
 * shops.
 */
final class CartWidget extends Widget_Base {

	/** Every column a line can show. Order comes from the repeater, not here. */
	private const FIELDS = array(
		'thumb'    => 'Image',
		'name'     => 'Product',
		'price'    => 'Price',
		'quantity' => 'Quantity',
		'subtotal' => 'Subtotal',
		'remove'   => 'Remove',
	);

	public function get_name(): string {
		return 'galaxie-cart';
	}

	public function get_title(): string {
		return __( 'Galaxie Cart', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-cart';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_script_depends(): array {
		return array( 'wc-cart-fragments' );
	}

	protected function register_controls(): void {
		$this->register_lines_section();
		$this->register_behaviour_section();
		$this->register_totals_section();
		$this->register_empty_section();
		$this->register_style_sections();
	}

	private function register_lines_section(): void {
		$this->start_controls_section( 'lines_section', array( 'label' => __( 'Line fields', 'galaxie-woo' ) ) );

		$this->add_control(
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

		$this->add_control(
			'lines',
			array(
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ field }}}',
				'default'     => array(
					array( 'field' => 'thumb', 'heading' => '' ),
					array( 'field' => 'name', 'heading' => __( 'Product', 'galaxie-woo' ) ),
					array( 'field' => 'price', 'heading' => __( 'Price', 'galaxie-woo' ) ),
					array( 'field' => 'quantity', 'heading' => __( 'Quantity', 'galaxie-woo' ) ),
					array( 'field' => 'subtotal', 'heading' => __( 'Subtotal', 'galaxie-woo' ) ),
					array( 'field' => 'remove', 'heading' => '' ),
				),
			)
		);

		$this->add_control(
			'show_headings',
			array(
				'label'        => __( 'Show column headings', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		PixfortControls::icon_select( $this, 'remove_icon', __( 'Remove icon', 'galaxie-woo' ), 'Line/pixfort-icon-trash-can-1' );

		$this->end_controls_section();
	}

	private function register_behaviour_section(): void {
		$this->start_controls_section( 'behaviour_section', array( 'label' => __( 'Behaviour', 'galaxie-woo' ) ) );

		$this->add_control(
			'auto_update',
			array(
				'label'        => __( 'Update automatically', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Changing a quantity updates the cart straight away. With this off, the shopper has to press Update cart and the page reloads — WooCommerce\'s own behaviour.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'update_text',
			array(
				'label'     => __( 'Update button', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Atualizar carrinho', 'galaxie-woo' ),
				'condition' => array( 'auto_update' => '' ),
			)
		);

		$this->add_control(
			'show_coupon',
			array(
				'label'        => __( 'Coupon field', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'coupon_placeholder',
			array(
				'label'     => __( 'Coupon placeholder', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Cupom de desconto', 'galaxie-woo' ),
				'condition' => array( 'show_coupon' => 'yes' ),
			)
		);

		$this->add_control(
			'coupon_text',
			array(
				'label'     => __( 'Coupon button', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Aplicar', 'galaxie-woo' ),
				'condition' => array( 'show_coupon' => 'yes' ),
			)
		);

		$this->end_controls_section();
	}

	private function register_totals_section(): void {
		$this->start_controls_section( 'totals_section', array( 'label' => __( 'Totals', 'galaxie-woo' ) ) );

		$this->add_control(
			'totals_heading',
			array(
				'label'   => __( 'Heading', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Resumo', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'show_shipping',
			array(
				'label'        => __( 'Shipping calculator', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'checkout_text',
			array(
				'label'   => __( 'Checkout button', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Finalizar compra', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'show_continue',
			array(
				'label'        => __( 'Continue shopping link', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'continue_text',
			array(
				'label'     => __( 'Continue shopping', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Continuar comprando', 'galaxie-woo' ),
				'condition' => array( 'show_continue' => 'yes' ),
			)
		);

		$this->end_controls_section();
	}

	private function register_empty_section(): void {
		$this->start_controls_section( 'empty_section', array( 'label' => __( 'Empty cart', 'galaxie-woo' ) ) );

		$this->add_control(
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

		$this->add_control(
			'empty_template',
			array(
				'label'       => __( 'Template', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => self::template_options(),
				'label_block' => true,
				'condition'   => array( 'empty_mode' => 'template' ),
				'description' => sprintf(
					/* translators: %1$s and %2$s are the opening and closing tags of a link to the template library. */
					esc_html__( 'Anything built in %1$sSaved Templates%2$s. An empty cart is a page in its own right — it is where a shopper decides whether to keep shopping.', 'galaxie-woo' ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=elementor_library' ) ) . '" target="_blank">',
					'</a>'
				),
			)
		);

		$this->add_control(
			'empty_text',
			array(
				'label'       => __( 'Message', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => __( 'Seu carrinho está vazio.', 'galaxie-woo' ),
				'label_block' => true,
				'condition'   => array( 'empty_mode' => 'message' ),
			)
		);

		$this->add_control(
			'empty_button_text',
			array(
				'label'     => __( 'Button', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Ver produtos', 'galaxie-woo' ),
				'condition' => array( 'empty_mode' => 'message' ),
			)
		);

		$this->end_controls_section();
	}

	private function register_style_sections(): void {
		$this->start_controls_section(
			'thumb_style',
			array( 'label' => __( 'Image', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::thumb( $this, 'thumb', '{{WRAPPER}} .galaxie-cart-thumb img' );
		$this->end_controls_section();

		$this->start_controls_section(
			'quantity_style',
			array( 'label' => __( 'Quantity', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		// The buy box's set, verbatim. See the class docblock.
		PixfortControls::quantity(
			$this,
			'qty',
			'{{WRAPPER}} .galaxie-cart-qty .quantity',
			'{{WRAPPER}} .galaxie-cart-qty .qty'
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'text_style',
			array( 'label' => __( 'Product name', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::text( $this, 'name', array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-cart-name' );
		$this->end_controls_section();

		$this->start_controls_section(
			'price_style',
			array( 'label' => __( 'Prices', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::text( $this, 'price', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-cart-price, {{WRAPPER}} .galaxie-cart-subtotal' );
		$this->end_controls_section();

		$this->start_controls_section(
			'remove_style',
			array( 'label' => __( 'Remove', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::icon_color( $this, 'remove_icon_color', __( 'Icon color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-cart-remove' );
		$this->add_responsive_control(
			'remove_size',
			array(
				'label'      => __( 'Icon size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 10, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-remove svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'checkout_style',
			array( 'label' => __( 'Checkout button', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::button( $this, 'checkout', array( 'full' => 'w-100', 'size' => 'lg' ) );
		$this->end_controls_section();

		$this->start_controls_section(
			'box_style',
			array( 'label' => __( 'Totals box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::palette_control( $this, 'box_bg', __( 'Background', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-cart-totals', 'background-color' );
		$this->add_responsive_control(
			'box_padding',
			array(
				'label'      => __( 'Padding', 'galaxie-woo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'rem' ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-totals' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_control(
			'box_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-totals' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'layout_style',
			array( 'label' => __( 'Layout', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		$this->add_responsive_control(
			'row_gap',
			array(
				'label'      => __( 'Gap between lines', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-line' => 'padding-top: {{SIZE}}{{UNIT}}; padding-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'field_gap',
			array(
				'label'      => __( 'Gap between fields', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-line' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();
	}

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

	protected function render(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$settings = $this->get_settings_for_display();

		if ( WC()->cart->is_empty() ) {
			$this->render_empty( $settings );
			return;
		}

		$fields = array();
		foreach ( (array) ( $settings['lines'] ?? array() ) as $row ) {
			if ( isset( $row['field'], self::FIELDS[ $row['field'] ] ) ) {
				$fields[] = $row;
			}
		}

		echo '<div class="galaxie-cart">';
		$this->render_form( $settings, $fields );
		echo self::totals_markup( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param array<int,array<string,mixed>> $fields
	 */
	private function render_form( array $settings, array $fields ): void {
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
				$this->render_field( (string) $row['field'], $key, $item, $product, $settings );
				echo '</div>';
			}

			echo '</div>';
		}

		$this->render_actions( $settings );

		wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' );
		echo '</form>';
	}

	/**
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $settings
	 */
	private function render_field( string $field, string $key, array $item, \WC_Product $product, array $settings ): void {
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
				// The variation's own attributes, which is the only way a line
				// for "50g" is distinguishable from the one for "190g".
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
				$this->render_quantity( $key, $item, $product );
				break;

			case 'subtotal':
				printf(
					'<span class="galaxie-cart-subtotal">%s</span>',
					wp_kses_post( apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $product, $item['quantity'] ), $item, $key ) )
				);
				break;

			case 'remove':
				$icon = PixfortControls::icon_value( $settings, 'remove_icon' );
				printf(
					'<a href="%s" class="galaxie-cart-remove" aria-label="%s" data-galaxie-remove="%s">%s</a>',
					esc_url( wc_get_cart_remove_url( $key ) ),
					esc_attr__( 'Remove this item', 'galaxie-woo' ),
					esc_attr( $key ),
					$icon ? $icon : '&times;' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own SVG.
				);
				break;
		}
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private function render_quantity( string $key, array $item, \WC_Product $product ): void {
		echo '<span class="galaxie-cart-qty">';

		if ( $product->is_sold_individually() ) {
			printf( '<input type="hidden" name="cart[%s][qty]" value="1" />', esc_attr( $key ) );
			echo '<span class="galaxie-cart-qty-fixed">1</span>';
		} else {
			// woocommerce_quantity_input() renders the theme's own stepper, so
			// the markup the shared quantity controls target is identical to the
			// buy box's. The field name is what the form handler reads.
			woocommerce_quantity_input(
				array(
					'input_name'  => "cart[{$key}][qty]",
					'input_value' => $item['quantity'],
					'max_value'   => $product->get_max_purchase_quantity(),
					'min_value'   => '0',
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
	private function render_actions( array $settings ): void {
		echo '<div class="galaxie-cart-actions">';

		if ( 'yes' === ( $settings['show_coupon'] ?? 'yes' ) && wc_coupons_enabled() ) {
			printf(
				'<div class="galaxie-cart-coupon"><input type="text" name="coupon_code" class="input-text" placeholder="%s" /><button type="submit" name="apply_coupon" value="1" class="galaxie-cart-coupon-apply">%s</button></div>',
				esc_attr( (string) ( $settings['coupon_placeholder'] ?? '' ) ),
				esc_html( (string) ( $settings['coupon_text'] ?? '' ) )
			);
		}

		// Always rendered, never removed: with automatic updating on it is
		// hidden by CSS rather than dropped, so a shopper whose JavaScript
		// failed still has a way to commit a quantity change.
		printf(
			'<button type="submit" name="update_cart" value="1" class="galaxie-cart-update">%s</button>',
			esc_html( (string) ( $settings['update_text'] ?? __( 'Atualizar carrinho', 'galaxie-woo' ) ) )
		);

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_empty( array $settings ): void {
		$template = 'template' === ( $settings['empty_mode'] ?? 'message' )
			? (int) ( $settings['empty_template'] ?? 0 )
			: 0;

		if ( $template ) {
			$content = self::rendered_template( $template );

			// A template that was deleted, or emptied, must not leave a blank
			// page where a cart used to be. Falling through to the message is
			// the one behaviour that is never worse than what was asked for.
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

	/**
	 * Every saved Elementor template, for the empty-cart picker.
	 *
	 * Read at control-registration time from the library post type itself
	 * rather than from an Elementor helper, so a change in their internals
	 * cannot empty this dropdown silently — the failure mode that has cost this
	 * project the most time.
	 *
	 * @return array<int|string,string>
	 */
	private static function template_options(): array {
		$options = array( '' => __( '— Select —', 'galaxie-woo' ) );

		$templates = get_posts(
			array(
				'post_type'        => 'elementor_library',
				'post_status'      => 'publish',
				'posts_per_page'   => 100,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		foreach ( $templates as $template ) {
			$options[ $template->ID ] = $template->post_title !== ''
				? $template->post_title
				/* translators: %d is a template ID. */
				: sprintf( __( 'Template #%d', 'galaxie-woo' ), $template->ID );
		}

		return $options;
	}

	/**
	 * A saved template's markup, with its own CSS.
	 *
	 * `get_builder_content_for_display()` is Elementor's own method for exactly
	 * this, and it already guards the case that matters here: a template whose
	 * ID is the document being edited would recurse, and it refuses instead.
	 */
	private static function rendered_template( int $id ): string {
		if ( ! class_exists( '\\Elementor\\Plugin' ) || ! get_post( $id ) ) {
			return '';
		}

		return (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $id, true );
	}

	/**
	 * The totals block, also called by the AJAX handler after a change.
	 *
	 * Static because the handler has no widget instance — and it needs the same
	 * markup, not a second version of it. Settings are optional for that reason;
	 * the parts that vary are text the shopper already saw.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function totals_markup( array $settings = array() ): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		$heading  = (string) ( $settings['totals_heading'] ?? '' );
		$checkout = (string) ( $settings['checkout_text'] ?? __( 'Finalizar compra', 'galaxie-woo' ) );

		ob_start();
		echo '<div class="galaxie-cart-totals">';

		if ( '' !== $heading ) {
			printf( '<h3 class="galaxie-cart-totals-heading">%s</h3>', esc_html( $heading ) );
		}

		// WooCommerce's own totals template: subtotal, coupons, shipping,
		// taxes, fees and the order total, each through its own filters. Every
		// tax and shipping rule a store has already configured lives in here,
		// and none of it is worth reimplementing for a different border radius.
		echo '<div class="galaxie-cart-totals-table">';
		wc_cart_totals_subtotal_html();
		echo '</div>';

		woocommerce_cart_totals();

		printf(
			'<a class="galaxie-cart-checkout checkout-button button" href="%s">%s</a>',
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
}

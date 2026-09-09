<?php
/**
 * "Galaxie Variation Badges" Elementor widget — pixfort-native styled swatches.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\VariationSwatches\Widget;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Renders ONE variation attribute (e.g. "Peso") as a styled label + clickable
 * badge per option. Unlike the module's automatic global behavior (which just
 * skins whatever `<select>` is already on the page with our own fixed CSS),
 * this widget gives a real "Estilo" tab — Label/Badge/Badge selecionado — that
 * mirrors pixfort's own Text/Badge widgets control-for-control, because it
 * literally renders through the same pixfort-core component functions
 * (`PixfortCore::elementsManager->renderElement('Text'|'Badge', …)`) those
 * widgets use. That's how it inherits the exact same global color dropdown
 * (Primary, Gray 1-9, Dynamic Colors…) instead of a separate hardcoded
 * palette — Wagner's ask: "copia o que o Custom Add To Cart faz, poe nome
 * Galaxie, expande com as opções que os widgets do pixfort têm".
 *
 * Falls back to plain Elementor COLOR controls when pixfort-core isn't
 * active, so the widget still works (with a plainer look) on any theme —
 * keeping with the bundle's theme-independence goal even though this is
 * deliberately pixfort-optimized here.
 *
 * The real WooCommerce variation form (native `<select>`, quantity, price,
 * add-to-cart button) is still rendered alongside — WooCommerce's own
 * variation-matching JS only recognizes a `<select>` inside `.variations`,
 * so we can't move it out. Instead, JS hides just this attribute's row in
 * that native table and drives its (still real, still functional) select
 * from our badges — see `frontend/src/globals/variation-badges-widget.ts`.
 */
final class VariationBadgesWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-variation-badges';
	}

	public function get_title(): string {
		return __( 'Galaxie Variation Badges (deprecated)', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-tags';
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
			'attribute',
			array(
				'label'   => __( 'Attribute', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $this->attribute_options(),
				'default' => 'pa_peso',
			)
		);

		$this->end_controls_section();

		$this->register_label_style_controls();
		$this->register_badge_style_controls();
		$this->register_selected_badge_style_controls();
		$this->register_layout_style_controls();
		$this->register_button_controls( 'addcart', 'addcart_style_section', __( 'Add to Cart button', 'galaxie-woo' ), __( 'Adicionar ao carrinho', 'galaxie-woo' ), 'primary', '' );
		$this->register_button_controls( 'buynow', 'buynow_style_section', __( 'Buy Now button', 'galaxie-woo' ), __( 'Comprar agora', 'galaxie-woo' ), 'primary', 'outline' );
	}

	private function register_label_style_controls(): void {
		$this->start_controls_section(
			'label_style_section',
			array(
				'label' => __( 'Label', 'galaxie-woo' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'label_size',
			array(
				'label'   => __( 'Size', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					''         => __( 'Default', 'galaxie-woo' ),
					'text-xs'  => '12px',
					'text-sm'  => '14px',
					'text-18'  => '18px',
					'text-20'  => '20px',
					'text-24'  => '24px',
				),
				'default' => '',
			)
		);

		$this->add_control(
			'label_bold',
			array(
				'label'        => __( 'Bold', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'font-weight-bold',
				'default'      => 'font-weight-bold',
			)
		);

		$this->add_control(
			'label_italic',
			array(
				'label'        => __( 'Italic', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'font-italic',
				'default'      => '',
			)
		);

		$this->add_control(
			'label_secondary_font',
			array(
				'label'        => __( 'Secondary font', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'secondary-font',
				'default'      => '',
			)
		);

		if ( $this->pixfort_active() ) {
			$this->add_control(
				'label_content_color',
				array(
					'label'   => __( 'Content color', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'groups'  => \PixfortCore::instance()->coreFunctions->getColorsArray(),
					'default' => '',
				)
			);
			$this->add_control(
				'label_content_custom_color',
				array(
					'label'     => __( 'Custom content color', 'galaxie-woo' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '',
					'condition' => array( 'label_content_color' => 'custom' ),
				)
			);
		} else {
			$this->add_control(
				'label_content_color_fallback',
				array(
					'label'     => __( 'Content color', 'galaxie-woo' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array(
						'{{WRAPPER}} .galaxie-variation-label-text' => 'color: {{VALUE}};',
					),
				)
			);
		}

		$this->add_control(
			'label_position',
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
			'label_animation',
			array(
				'label'   => __( 'Animation', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => function_exists( 'pix_get_animations' ) ? pix_get_animations( true ) : array( '' => __( 'None', 'galaxie-woo' ) ),
			)
		);
		$this->add_control(
			'label_delay',
			array(
				'label'     => __( 'Animation delay (in miliseconds)', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => '0',
				'condition' => array( 'label_animation!' => '' ),
			)
		);

		$this->add_control(
			'label_remove_pb_padding',
			array(
				'label'        => __( 'Remove margin under paragraphs', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'm-0',
				'default'      => '',
			)
		);

		$this->end_controls_section();
	}

	private function register_badge_style_controls(): void {
		$this->start_controls_section(
			'badge_style_section',
			array(
				'label' => __( 'Badge', 'galaxie-woo' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		if ( $this->pixfort_active() ) {
			$this->add_control(
				'badge_text_color',
				array(
					'label'   => __( 'Text color', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'groups'  => \PixfortCore::instance()->coreFunctions->getColorsArray(),
					'default' => 'primary',
				)
			);
			$this->add_control(
				'badge_bg_color',
				array(
					'label'   => __( 'Background color', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'groups'  => \PixfortCore::instance()->coreFunctions->getColorsArray( array( 'bg' => true, 'transparent' => true ) ),
					'default' => 'primary-light',
				)
			);
			$this->add_control(
				'badge_rounded',
				array(
					'label'   => __( 'Border radius', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'options' => array_merge(
						array(
							''           => __( 'Default', 'galaxie-woo' ),
							'badge-pill' => __( 'Pill', 'galaxie-woo' ),
						),
						function_exists( 'pixfort_get_border_radius_options' ) ? pixfort_get_border_radius_options() : array()
					),
					'default' => '',
				)
			);
		} else {
			$this->add_control(
				'badge_text_color_fallback',
				array(
					'label'     => __( 'Text color', 'galaxie-woo' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .galaxie-swatch-option .badge' => 'color: {{VALUE}};' ),
				)
			);
			$this->add_control(
				'badge_bg_color_fallback',
				array(
					'label'     => __( 'Background color', 'galaxie-woo' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .galaxie-swatch-option .badge' => 'background-color: {{VALUE}};' ),
				)
			);
		}

		$this->add_control(
			'badge_text_size',
			array(
				'label'   => __( 'Text size', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'h1'     => 'H1',
					'h2'     => 'H2',
					'h3'     => 'H3',
					'h4'     => 'H4',
					'h5'     => 'H5',
					'h6'     => 'H6',
					'custom' => __( 'Custom', 'galaxie-woo' ),
				),
				'default' => 'h6',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'badge_typography',
				'selector' => '{{WRAPPER}} .galaxie-swatch-option .badge',
			)
		);

		$this->end_controls_section();
	}

	private function register_selected_badge_style_controls(): void {
		$this->start_controls_section(
			'badge_selected_style_section',
			array(
				'label' => __( 'Badge — selecionado', 'galaxie-woo' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		if ( $this->pixfort_active() ) {
			$this->add_control(
				'badge_text_color_selected',
				array(
					'label'   => __( 'Text color', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'groups'  => \PixfortCore::instance()->coreFunctions->getColorsArray(),
					'default' => 'white',
				)
			);
			$this->add_control(
				'badge_bg_color_selected',
				array(
					'label'   => __( 'Background color', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'groups'  => \PixfortCore::instance()->coreFunctions->getColorsArray( array( 'bg' => true ) ),
					'default' => 'primary',
				)
			);
		} else {
			$this->add_control(
				'badge_text_color_selected_fallback',
				array(
					'label'     => __( 'Text color', 'galaxie-woo' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '#ffffff',
					'selectors' => array( '{{WRAPPER}} .galaxie-swatch-option.is-selected .badge' => 'color: {{VALUE}};' ),
				)
			);
			$this->add_control(
				'badge_bg_color_selected_fallback',
				array(
					'label'     => __( 'Background color', 'galaxie-woo' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .galaxie-swatch-option.is-selected .badge' => 'background-color: {{VALUE}};' ),
				)
			);
		}

		$this->end_controls_section();
	}

	private function register_layout_style_controls(): void {
		$this->start_controls_section(
			'layout_style_section',
			array(
				'label' => __( 'Layout', 'galaxie-woo' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'badge_gap',
			array(
				'label'      => __( 'Gap between badges', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'size' => 8, 'unit' => 'px' ),
				'selectors'  => array(
					'{{WRAPPER}} .galaxie-swatch-options' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'show_stock',
			array(
				'label'        => __( 'Show stock', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'galaxie-woo' ),
				'label_off'    => __( 'No', 'galaxie-woo' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'quantity_type',
			array(
				'label'   => __( 'Quantity field', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'input'  => __( 'Number input (+/-)', 'galaxie-woo' ),
					'select' => __( 'Dropdown', 'galaxie-woo' ),
				),
				'default' => 'input',
			)
		);
		$this->add_control(
			'quantity_max',
			array(
				'label'     => __( 'Dropdown goes up to', 'galaxie-woo' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 20,
				'default'   => 5,
				'condition' => array( 'quantity_type' => 'select' ),
			)
		);

		$this->add_control(
			'order_heading',
			array(
				'label'     => __( 'Block order', 'galaxie-woo' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
		$this->add_control(
			'order_note',
			array(
				'type' => Controls_Manager::RAW_HTML,
				'raw'  => __( 'Give each block a number — they render in ascending order (ties keep the order below).', 'galaxie-woo' ),
			)
		);
		$order_defaults = array(
			'order_price'      => 1,
			'order_variations' => 2,
			'order_spinner'    => 3,
			'order_buttons'    => 4,
		);
		$order_labels = array(
			'order_price'      => __( 'Price', 'galaxie-woo' ),
			'order_variations' => __( 'Variations', 'galaxie-woo' ),
			'order_spinner'    => __( 'Spinner (quantity)', 'galaxie-woo' ),
			'order_buttons'    => __( 'Buttons', 'galaxie-woo' ),
		);
		foreach ( $order_defaults as $key => $default ) {
			$this->add_control(
				$key,
				array(
					'label'   => $order_labels[ $key ],
					'type'    => Controls_Manager::NUMBER,
					'min'     => 1,
					'max'     => 4,
					'default' => $default,
				)
			);
		}

		$this->end_controls_section();
	}

	/**
	 * Full pixfort Button parity for one of our two buttons (Add to Cart /
	 * Buy Now), keyed by $prefix so both can coexist on the same widget —
	 * pixfort's own shared helper (`pix_get_elementor_btn()`) hardcodes
	 * unprefixed control ids, so it can only be used once per widget; this
	 * mirrors its meaningful controls (button/icon/style/color/size) with our
	 * own prefixed ids instead, matching `PixButton::render()`'s `btn_*` attr
	 * keys 1:1 in {@see render_button()}. Includes `pixfort_icon_selector` —
	 * pixfort-core's own globally-registered icon-picker control type; any
	 * widget can add it directly, no re-registration needed.
	 */
	private function register_button_controls( string $prefix, string $section_id, string $label, string $default_text, string $default_color, string $default_style ): void {
		$this->start_controls_section(
			$section_id,
			array(
				'label' => $label,
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			$prefix . '_text',
			array(
				'label'   => __( 'Button text', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXT,
				'default' => $default_text,
			)
		);

		if ( $this->pixfort_active() ) {
			$this->add_control(
				$prefix . '_icon',
				array(
					'label'   => __( 'Icon', 'galaxie-woo' ),
					'type'    => \Elementor\CustomControl\PixfortIconSelector_Control::PixfortIconSelector,
					'default' => '',
				)
			);
			$this->add_control(
				$prefix . '_icon_position',
				array(
					'label'     => __( 'Icon position', 'galaxie-woo' ),
					'type'      => Controls_Manager::SELECT,
					'options'   => array(
						''      => __( 'Before text', 'galaxie-woo' ),
						'after' => __( 'After text', 'galaxie-woo' ),
					),
					'default'   => '',
					'condition' => array( $prefix . '_icon!' => '' ),
				)
			);
			$this->add_control(
				$prefix . '_style',
				array(
					'label'   => __( 'Button style', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'options' => array(
						''          => __( 'Default', 'galaxie-woo' ),
						'flat'      => __( 'Flat', 'galaxie-woo' ),
						'line'      => __( 'Line', 'galaxie-woo' ),
						'outline'   => __( 'Outline', 'galaxie-woo' ),
						'underline' => __( 'Underline', 'galaxie-woo' ),
						'link'      => __( 'Link', 'galaxie-woo' ),
						'blink'     => __( 'Blink', 'galaxie-woo' ),
					),
					'default' => $default_style,
				)
			);
			$this->add_control(
				$prefix . '_color',
				array(
					'label'   => __( 'Button color', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'groups'  => \PixfortCore::instance()->coreFunctions->getColorsArray( array( 'defaultColors' => false, 'mainLight' => true, 'custom' => false ) ),
					'default' => $default_color,
				)
			);
			$this->add_control(
				$prefix . '_text_color',
				array(
					'label'   => __( 'Text color', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'groups'  => \PixfortCore::instance()->coreFunctions->getColorsArray( array( 'defaultValue' => array( '' => __( 'Default', 'galaxie-woo' ) ), 'mainLight' => true ) ),
					'default' => '',
				)
			);
		} else {
			$this->add_control(
				$prefix . '_color_fallback',
				array(
					'label'     => __( 'Button color', 'galaxie-woo' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .' . $prefix . '-btn' => 'background-color: {{VALUE}};' ),
				)
			);
		}

		$this->add_control(
			$prefix . '_size',
			array(
				'label'   => __( 'Button size', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'sm'     => __( 'Small', 'galaxie-woo' ),
					'normal' => __( 'Normal', 'galaxie-woo' ),
					'md'     => __( 'Medium', 'galaxie-woo' ),
					'lg'     => __( 'Large', 'galaxie-woo' ),
					'xl'     => __( 'X-Large', 'galaxie-woo' ),
				),
				'default' => 'md',
			)
		);
		$this->add_control(
			$prefix . '_rounded',
			array(
				'label'        => __( 'Rounded corners', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'btn-rounded',
				'default'      => '',
			)
		);
		$this->add_control(
			$prefix . '_full',
			array(
				'label'        => __( 'Full width', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->end_controls_section();
	}

	/** @return array<string,string> */
	private function attribute_options(): array {
		$product = $this->current_product();
		if ( ! $product ) {
			return array( 'pa_peso' => 'pa_peso' );
		}

		$options = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_a( $attribute, '\WC_Product_Attribute' ) || ! $attribute->get_variation() ) {
				continue;
			}
			$slug             = $attribute->get_name();
			$options[ $slug ] = wc_attribute_label( $slug, $product );
		}

		return $options ?: array( 'pa_peso' => 'pa_peso' );
	}

	protected function render(): void {
		$product = $this->current_product();

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div style="padding:2rem;text-align:center;border:1px dashed #ccc;border-radius:8px;">';
				esc_html_e( 'Galaxie Variation Badges — place inside a single product template with a variable product.', 'galaxie-woo' );
				echo '</div>';
			}
			return;
		}

		// wc_setup_product_data() expects a post ID/WP_Post, NOT a WC_Product —
		// passing $product silently no-ops (WC_Product has no ->post_type, so
		// the function's own guard clause returns false without setting
		// $GLOBALS['product']/$GLOBALS['post']). That was the real cause of
		// the native form printing nothing below: `global $product;` inside
		// woocommerce_variable_add_to_cart() saw whatever was already set
		// (often nothing), not this widget's product.
		wc_setup_product_data( $product->get_id() );

		$settings = $this->get_settings_for_display();
		$slug     = (string) ( $settings['attribute'] ?? 'pa_peso' );

		$attribute_data = $this->find_attribute( $product, $slug );
		if ( ! $attribute_data ) {
			return;
		}

		$blocks = array(
			'price'      => (int) ( $settings['order_price'] ?? 1 ),
			'variations' => (int) ( $settings['order_variations'] ?? 2 ),
			'spinner'    => (int) ( $settings['order_spinner'] ?? 3 ),
			'buttons'    => (int) ( $settings['order_buttons'] ?? 4 ),
		);
		asort( $blocks );

		$show_stock = 'yes' === ( $settings['show_stock'] ?? 'yes' );

		echo '<div class="galaxie-ui galaxie-buybox' . ( $show_stock ? '' : ' galaxie-buybox--no-stock' ) . '">';

		// Spinner + Buttons render side-by-side in one flex row whenever they
		// land adjacent in the configured order (the common case, and what
		// Wagner asked for) — any other order just renders each separately.
		$order = array_keys( $blocks );
		$i     = 0;
		$count = count( $order );
		while ( $i < $count ) {
			$block = $order[ $i ];
			$next  = $order[ $i + 1 ] ?? null;
			$pair  = array( 'spinner', 'buttons' );

			if ( in_array( $block, $pair, true ) && in_array( $next, $pair, true ) ) {
				echo '<div class="galaxie-buybox-row">';
				$this->render_block( $block, $product, $attribute_data, $slug, $settings, $show_stock );
				$this->render_block( $next, $product, $attribute_data, $slug, $settings, $show_stock );
				echo '</div>';
				$i += 2;
				continue;
			}

			$this->render_block( $block, $product, $attribute_data, $slug, $settings, $show_stock );
			++$i;
		}

		echo '</div>'; // .galaxie-buybox

		// The real, still-functional WooCommerce variation form — kept for its
		// <select> (WooCommerce's own variation-matching JS only recognizes one
		// inside `.variations`) and its price/stock/description block, which our
		// JS mirrors into .galaxie-buybox-price / .galaxie-buybox-stock above on
		// `found_variation`/`reset_data`. Its own quantity input, button, and
		// reset link are visually hidden (CSS) — we render our own pixfort-
		// styled ones above and drive everything via our own AJAX endpoint.
		echo '<div class="galaxie-variation-native">';
		$fn = 'woocommerce_' . $product->get_type() . '_add_to_cart';
		if ( function_exists( $fn ) ) {
			$fn();
		} else {
			woocommerce_template_single_add_to_cart();
		}
		echo '</div>';
	}

	/**
	 * @param array{label:string,options:array<string,string>} $attribute_data
	 * @param array<string,mixed>                               $settings
	 */
	private function render_block( string $block, \WC_Product $product, array $attribute_data, string $slug, array $settings, bool $show_stock ): void {
		switch ( $block ) {
			case 'price':
				echo '<div class="galaxie-buybox-price">' . wp_kses_post( $product->get_price_html() ) . '</div>';
				if ( $show_stock ) {
					echo '<div class="galaxie-buybox-stock">' . wp_kses_post( wc_get_stock_html( $product ) ) . '</div>';
				}
				break;

			case 'variations':
				echo '<div class="galaxie-variation-picker" data-galaxie-attribute="' . esc_attr( $slug ) . '">';

				echo '<div class="galaxie-variation-label">';
				echo $this->render_label( $attribute_data['label'], $settings ); // phpcs:ignore -- already escaped by pixfort/our own renderer.
				echo '</div>';

				echo '<div class="galaxie-swatch-options">';
				foreach ( $attribute_data['options'] as $option_value => $option_label ) {
					printf(
						'<button type="button" class="galaxie-swatch-option" data-value="%s">',
						esc_attr( $option_value )
					);
					echo '<span class="galaxie-swatch-normal">' . $this->render_badge( $option_label, $settings, false ) . '</span>'; // phpcs:ignore
					echo '<span class="galaxie-swatch-selected">' . $this->render_badge( $option_label, $settings, true ) . '</span>'; // phpcs:ignore
					echo '</button>';
				}
				echo '</div>';

				echo '</div>'; // .galaxie-variation-picker
				break;

			case 'spinner':
				echo '<div class="galaxie-buybox-quantity">';
				if ( 'select' === ( $settings['quantity_type'] ?? 'input' ) ) {
					$this->render_quantity_select( $product, (int) ( $settings['quantity_max'] ?? 5 ) );
				} else {
					woocommerce_quantity_input();
				}
				echo '</div>';
				break;

			case 'buttons':
				echo '<div class="galaxie-buybox-actions">';
				printf(
					'<button type="button" class="galaxie-buybox-btn galaxie-buybox-addcart">%s</button>',
					$this->render_button( 'addcart', $settings ) // phpcs:ignore
				);
				printf(
					'<button type="button" class="galaxie-buybox-btn galaxie-buybox-buynow">%s</button>',
					$this->render_button( 'buynow', $settings ) // phpcs:ignore
				);
				echo '</div>';
				break;
		}
	}

	/**
	 * Dropdown alternative to the number input. Keeps `name="quantity"` and the
	 * `qty` class so both WooCommerce and our own JS treat it like any other
	 * quantity field. Respects the product's min/max where it declares them.
	 */
	private function render_quantity_select( \WC_Product $product, int $max ): void {
		$min = max( 1, (int) $product->get_min_purchase_quantity() );
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

	/** @param array<string,mixed> $settings */
	private function render_button( string $prefix, array $settings ): string {
		$text = (string) ( $settings[ $prefix . '_text' ] ?? '' );

		if ( $this->pixfort_active() ) {
			$attr = array(
				'is_elementor'      => 'true',
				'btn_text'          => $text,
				'btn_link'          => '', // Empty on purpose: renders a <span>, not an <a> — our own wrapping <button> handles the click.
				'btn_icon'          => $settings[ $prefix . '_icon' ] ?? '',
				'btn_icon_position' => $settings[ $prefix . '_icon_position' ] ?? '',
				'btn_style'         => $settings[ $prefix . '_style' ] ?? '',
				'btn_color'         => $settings[ $prefix . '_color' ] ?? 'primary',
				'btn_text_color'    => $settings[ $prefix . '_text_color' ] ?? '',
				'btn_size'          => $settings[ $prefix . '_size' ] ?? 'md',
				'btn_rounded'       => $settings[ $prefix . '_rounded' ] ?? '',
				'btn_full'          => $settings[ $prefix . '_full' ] ?? '',
			);
			return \PixfortCore::instance()->elementsManager->renderElement( 'Button', $attr );
		}

		return '<span class="' . esc_attr( $prefix ) . '-btn">' . esc_html( $text ) . '</span>';
	}

	/** @param array<string,mixed> $settings */
	private function render_label( string $label, array $settings ): string {
		if ( $this->pixfort_active() ) {
			$attr = array(
				'content_type'         => 'simple',
				'content'              => $label,
				'size'                 => $settings['label_size'] ?? '',
				'bold'                 => $settings['label_bold'] ?? '',
				'italic'               => $settings['label_italic'] ?? '',
				'secondary_font'       => $settings['label_secondary_font'] ?? '',
				'content_color'        => $settings['label_content_color'] ?? '',
				'content_custom_color' => $settings['label_content_custom_color'] ?? '',
				'position'             => $settings['label_position'] ?? 'text-left',
				'animation'            => $settings['label_animation'] ?? '',
				'delay'                => $settings['label_delay'] ?? '0',
				'remove_pb_padding'    => $settings['label_remove_pb_padding'] ?? '',
			);
			return \PixfortCore::instance()->elementsManager->renderElement( 'Text', $attr, $label );
		}

		$classes = 'galaxie-variation-label-text';
		if ( ! empty( $settings['label_bold'] ) ) {
			$classes .= ' ' . $settings['label_bold'];
		}
		if ( ! empty( $settings['label_italic'] ) ) {
			$classes .= ' ' . $settings['label_italic'];
		}
		$style = '';
		if ( ! empty( $settings['label_content_color_fallback'] ) ) {
			$style = 'color:' . $settings['label_content_color_fallback'] . ';';
		}

		return '<p class="' . esc_attr( $classes ) . '" style="' . esc_attr( $style ) . '">' . esc_html( $label ) . '</p>';
	}

	/** @param array<string,mixed> $settings */
	private function render_badge( string $label, array $settings, bool $selected ): string {
		if ( $this->pixfort_active() ) {
			$attr = array(
				'text'      => $label,
				'text_color' => $selected
					? ( $settings['badge_text_color_selected'] ?? 'white' )
					: ( $settings['badge_text_color'] ?? 'primary' ),
				'bg_color'  => $selected
					? ( $settings['badge_bg_color_selected'] ?? 'primary' )
					: ( $settings['badge_bg_color'] ?? 'primary-light' ),
				'text_size' => $settings['badge_text_size'] ?? 'h6',
				'rounded'   => $settings['badge_rounded'] ?? '',
			);
			return \PixfortCore::instance()->elementsManager->renderElement( 'Badge', $attr );
		}

		return '<span class="badge">' . esc_html( $label ) . '</span>';
	}

	/**
	 * @return array{label:string,options:array<string,string>}|null
	 */
	private function find_attribute( \WC_Product $product, string $slug ): ?array {
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_a( $attribute, '\WC_Product_Attribute' ) || ! $attribute->get_variation() ) {
				continue;
			}
			if ( $attribute->get_name() !== $slug ) {
				continue;
			}

			$options = array();
			if ( $attribute->is_taxonomy() ) {
				foreach ( wc_get_product_terms( $product->get_id(), $slug, array( 'fields' => 'all' ) ) as $term ) {
					$options[ $term->slug ] = $term->name;
				}
			} else {
				foreach ( $attribute->get_options() as $option ) {
					$options[ sanitize_title( $option ) ] = $option;
				}
			}

			return array(
				'label'   => wc_attribute_label( $slug, $product ),
				'options' => $options,
			);
		}

		return null;
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

	public function get_script_depends(): array {
		return array();
	}
}

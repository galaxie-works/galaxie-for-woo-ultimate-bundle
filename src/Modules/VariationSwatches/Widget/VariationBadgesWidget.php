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
		return __( 'Galaxie Variation Badges', 'galaxie-woo' );
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

		wc_setup_product_data( $product );

		$settings = $this->get_settings_for_display();
		$slug     = (string) ( $settings['attribute'] ?? 'pa_peso' );

		$attribute_data = $this->find_attribute( $product, $slug );
		if ( ! $attribute_data ) {
			return;
		}

		echo '<div class="galaxie-ui galaxie-variation-picker" data-galaxie-attribute="' . esc_attr( $slug ) . '">';

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

		// The real, still-functional WooCommerce variation form. Our JS hides
		// this attribute's own row inside it and drives its <select> instead.
		$fn = 'woocommerce_' . $product->get_type() . '_add_to_cart';
		if ( function_exists( $fn ) ) {
			$fn();
		} else {
			woocommerce_template_single_add_to_cart();
		}
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

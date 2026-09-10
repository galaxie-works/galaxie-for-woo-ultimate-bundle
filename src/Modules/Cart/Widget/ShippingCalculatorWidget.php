<?php
/**
 * "Galaxie Shipping Calculator" — WooCommerce's calculator, dressed.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Cart\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\CartParts;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * The shipping estimate, as a widget the merchant can put wherever it belongs.
 *
 * The form is WooCommerce's own — `woocommerce_shipping_calculator()`, the same
 * template, the same `calc_shipping` nonce and handler. Nothing here
 * reimplements a quote: a calculator that disagreed with checkout by a real
 * about anything would be worse than no calculator.
 *
 * Which fields appear is WooCommerce's decision too, made through its
 * `woocommerce_shipping_calculator_enable_*` filters. They are exposed as
 * switches and applied only while this widget renders, so a store that already
 * filters them elsewhere keeps its answer everywhere else.
 */
final class ShippingCalculatorWidget extends Widget_Base {

	/** @var array<string,string> Filter suffix => control id. */
	private const FIELDS = array(
		'country'  => 'show_country',
		'state'    => 'show_state',
		'city'     => 'show_city',
		'postcode' => 'show_postcode',
	);

	public function get_name(): string {
		return 'galaxie-shipping-calculator';
	}

	public function get_title(): string {
		return __( 'Galaxie Shipping Calculator', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-shipping';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'calculator_section', array( 'label' => __( 'Calculator', 'galaxie-woo' ) ) );

		$this->add_control(
			'heading',
			array(
				'label'       => __( 'Heading', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Calcular frete', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'always_open',
			array(
				'label'        => __( 'Always open', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Off hides the form behind a link, which is WooCommerce\'s own default.', 'galaxie-woo' ),
			)
		);

		foreach ( array(
			'show_country'  => __( 'Country field', 'galaxie-woo' ),
			'show_state'    => __( 'State field', 'galaxie-woo' ),
			'show_city'     => __( 'City field', 'galaxie-woo' ),
			'show_postcode' => __( 'Postcode field', 'galaxie-woo' ),
		) as $id => $label ) {
			$this->add_control(
				$id,
				array(
					'label'        => $label,
					'type'         => Controls_Manager::SWITCHER,
					'default'      => 'yes',
					'return_value' => 'yes',
				)
			);
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'calculator_box_style',
			array( 'label' => __( 'Box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::surface( $this, 'calc_box', '{{WRAPPER}} .galaxie-shipping-calculator' );
		$this->end_controls_section();

		$this->start_controls_section(
			'calculator_heading_style',
			array( 'label' => __( 'Heading', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::text(
			$this,
			'calc_heading',
			array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ),
			array(),
			'{{WRAPPER}} .galaxie-shipping-calculator-heading'
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'calculator_field_style',
			array( 'label' => __( 'Fields', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);

		$fields = '{{WRAPPER}} .shipping-calculator-form input.input-text, {{WRAPPER}} .shipping-calculator-form select, {{WRAPPER}} .shipping-calculator-form .select2-selection';

		PixfortControls::palette_control( $this, 'calc_field_bg', __( 'Background', 'galaxie-woo' ), $fields, 'background-color' );
		PixfortControls::palette_control( $this, 'calc_field_color', __( 'Text color', 'galaxie-woo' ), $fields, 'color' );
		PixfortControls::palette_control( $this, 'calc_field_border', __( 'Border color', 'galaxie-woo' ), $fields, 'border-color', array(), ' border-style: solid; border-width: 1px;' );

		$this->add_responsive_control(
			'calc_field_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( $fields => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'calc_field_gap',
			array(
				'label'      => __( 'Space between fields', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 12 ),
				'selectors'  => array( '{{WRAPPER}} .shipping-calculator-form .form-row' => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'calculator_button_style',
			array( 'label' => __( 'Button', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		// Selector-driven rather than pixfort's Button set, and deliberately.
		// That set works by putting classes on markup we print; this button is
		// printed by WooCommerce's own template, so those classes would have
		// nowhere to land and the merchant would get a full panel where nothing
		// moved. Fewer controls that work beat more that do not.
		$button = '{{WRAPPER}} .shipping-calculator-form button[name="calc_shipping"]';

		$this->add_control(
			'calc_button_text',
			array(
				'label'       => __( 'Button text', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Calcular', 'galaxie-woo' ),
			)
		);

		PixfortControls::palette_control( $this, 'calc_button_bg', __( 'Background', 'galaxie-woo' ), $button, 'background-color' );
		PixfortControls::palette_control( $this, 'calc_button_color', __( 'Text color', 'galaxie-woo' ), $button, 'color' );
		PixfortControls::palette_control( $this, 'calc_button_hover_bg', __( 'Hover background', 'galaxie-woo' ), $button . ':hover', 'background-color' );

		$this->add_responsive_control(
			'calc_button_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( $button => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'calc_button_padding',
			array(
				'label'      => __( 'Padding', 'galaxie-woo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( $button => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'calc_button_full',
			array(
				'label'        => __( 'Full width', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'selectors'    => array( $button => 'width: 100%;' ),
			)
		);
		$this->end_controls_section();
	}

	protected function render(): void {
		if ( ! CartParts::available() || ! function_exists( 'woocommerce_shipping_calculator' ) ) {
			return;
		}

		$settings = $this->get_settings_for_display();
		$filters  = array();

		foreach ( self::FIELDS as $field => $control ) {
			$show    = 'yes' === ( $settings[ $control ] ?? 'yes' );
			$filter  = 'woocommerce_shipping_calculator_enable_' . $field;
			$closure = static fn() => $show;

			add_filter( $filter, $closure );
			$filters[ $filter ] = $closure;
		}

		printf(
			'<div class="galaxie-shipping-calculator %1$s %2$s">',
			esc_attr( PixfortControls::surface_classes( $settings, 'calc_box' ) ),
			esc_attr( 'yes' === ( $settings['always_open'] ?? 'yes' ) ? 'is-open' : '' )
		);

		$heading = (string) ( $settings['heading'] ?? '' );

		if ( '' !== $heading ) {
			printf(
				'<div class="galaxie-shipping-calculator-heading">%s</div>',
				PixfortControls::render_text( $settings, 'calc_heading', esc_html( $heading ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
			);
		}

		// WooCommerce's own template, its own `calc_shipping` nonce and
		// handler. "Always open" is CSS on the wrapper rather than an argument,
		// because the template's own answer is a toggle link and a hidden
		// section — there is nothing to pass it.
		woocommerce_shipping_calculator( (string) ( $settings['calc_button_text'] ?? '' ) );

		echo '</div>';

		// Removed the moment the widget is done. Left in place they would
		// answer for the checkout page and for anyone else asking, which is a
		// widget deciding something that is not its to decide.
		foreach ( $filters as $filter => $closure ) {
			remove_filter( $filter, $closure );
		}
	}
}

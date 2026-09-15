<?php
/**
 * "Galaxie Shipping Calculator": WooCommerce's calculator, in pixfort's parts.
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
 * The quote is WooCommerce's. The form posts the same field names, the same
 * `calc_shipping` nonce and the same handler as WooCommerce's own template, so a
 * calculator and checkout can never disagree about a price.
 *
 * The markup is ours, and it had to be. The first cut called
 * `woocommerce_shipping_calculator()` and styled the result from outside. What
 * reached the page was an unstyled grey "Update" button and bare inputs.
 * pixfort's Button set only works on markup pixfort prints, so the selector
 * controls standing in for it were a panel that barely moved anything.
 * Printing the form here lets the button be pixfort's own component inside a
 * real submit button, and lets the inputs carry pixfort's `form-control`.
 *
 * Fields default to the CEP alone, which is how the production store's
 * calculator works: in Brazil the CEP decides both the price and the state.
 * The State field used to be trusted, and it could say São Paulo for a parcel
 * going to Recife. WooCommerce's `woocommerce_shipping_calculator_enable_*`
 * filters still get the last word on every field, so a store that filters them
 * elsewhere keeps its answer.
 */
final class ShippingCalculatorWidget extends Widget_Base {

	/** @var array<string,array{0:string,1:string}> Field => [control id, default]. */
	private const FIELDS = array(
		'country'  => array( 'show_country', '' ),
		'state'    => array( 'show_state', '' ),
		'city'     => array( 'show_city', '' ),
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
		) as $id => $label ) {
			$field = str_replace( 'show_', '', $id );

			$this->add_control(
				$id,
				array(
					'label'        => $label,
					'type'         => Controls_Manager::SWITCHER,
					'default'      => self::FIELDS[ $field ][1],
					'return_value' => 'yes',
				)
			);
		}

		$this->add_control(
			'fields_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'The CEP field is always shown: every carrier quotes by CEP, and the state is read from it. A State field left visible cannot override it.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'postcode_label',
			array(
				'label'     => __( 'CEP label', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'CEP', 'galaxie-woo' ),
				'separator' => 'before',
			)
		);

		$this->add_control(
			'postcode_placeholder',
			array(
				'label'     => __( 'CEP placeholder', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( '00000-000', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'show_address_search',
			array(
				'label'        => __( 'Address search (Google)', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'separator'    => 'before',
				'description'  => __( 'The shopper types a street and picks it from Google\'s suggestions, and the CEP is filled in. Needs the Address Autocomplete module on, with a Maps key.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'address_label',
			array(
				'label'     => __( 'Search label', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => '',
				'condition' => array( 'show_address_search' => 'yes' ),
			)
		);

		$this->add_control(
			'address_placeholder',
			array(
				'label'     => __( 'Search placeholder', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Digite seu endereço', 'galaxie-woo' ),
				'condition' => array( 'show_address_search' => 'yes' ),
			)
		);

		$this->end_controls_section();

		// The same registrar as the buy box and the cart totals, so this button
		// has the same panel as every other button in the plugin.
		$this->start_controls_section( 'calculator_button_section', array( 'label' => __( 'Button', 'galaxie-woo' ) ) );
		PixfortControls::button(
			$this,
			'calcbtn',
			array( 'text' => __( 'Calcular', 'galaxie-woo' ), 'color' => 'primary', 'full' => 'yes' ),
			array(),
			'{{WRAPPER}} .galaxie-shipping-calculator-submit'
		);
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
			'calculator_label_style',
			array( 'label' => __( 'Labels', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::text(
			$this,
			'calc_label',
			array( 'remove_pb_padding' => 'm-0' ),
			array(),
			'{{WRAPPER}} .galaxie-shipping-calculator label',
			'text',
			array( 'position' )
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'calculator_field_style',
			array( 'label' => __( 'Fields', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);

		$fields = '{{WRAPPER}} .galaxie-shipping-calculator .form-control, {{WRAPPER}} .galaxie-shipping-calculator .select2-selection';

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
	}

	/**
	 * Is Google's address search set up at all? Without the module or its key
	 * there is no script to drive the field, and an input that suggests nothing
	 * is worse than no input.
	 */
	private static function address_search_available(): bool {
		$settings = \Galaxie\Woo\Core\Plugin::instance()->settings();

		return $settings->is_enabled( 'address-autocomplete', false )
			&& '' !== (string) ( $settings->module_settings( 'address-autocomplete' )['maps_api_key'] ?? '' );
	}

	protected function render(): void {
		if ( ! CartParts::available() || WC()->cart->is_empty() || ! WC()->cart->needs_shipping() ) {
			// Nothing to quote. The heading on its own over an empty cart is
			// what this used to print.
			return;
		}

		// A cart whose destination is already decided — a gift from a shared
		// wish list — has nothing for the shopper to type here.
		if ( apply_filters( 'galaxie_cart_shipping_calculator_hidden', false ) ) {
			return;
		}

		$settings = $this->get_settings_for_display();
		$label    = PixfortControls::text_classes( $settings, 'calc_label' );
		$show     = array();

		foreach ( self::FIELDS as $field => $control ) {
			$show[ $field ] = (bool) apply_filters(
				'woocommerce_shipping_calculator_enable_' . $field,
				'yes' === ( $settings[ $control[0] ] ?? $control[1] )
			);
		}

		$customer = WC()->customer;
		$country  = $customer->get_shipping_country() ? $customer->get_shipping_country() : WC()->countries->get_base_country();
		$heading  = (string) ( $settings['heading'] ?? '' );

		printf(
			'<div class="galaxie-shipping-calculator %1$s %2$s">',
			esc_attr( PixfortControls::surface_classes( $settings, 'calc_box' ) ),
			esc_attr( 'yes' === ( $settings['always_open'] ?? 'yes' ) ? 'is-open' : '' )
		);

		if ( '' !== $heading ) {
			printf(
				'<div class="galaxie-shipping-calculator-heading">%s</div>',
				PixfortControls::render_text( $settings, 'calc_heading', esc_html( $heading ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
			);
		}

		do_action( 'woocommerce_before_shipping_calculator' );

		// WooCommerce's cart.js binds to this form class, the toggle link and
		// the section id, and posts the form by AJAX. All three are kept.
		printf( '<form class="woocommerce-shipping-calculator" action="%s" method="post">', esc_url( wc_get_cart_url() ) );
		printf(
			'<a href="#" class="shipping-calculator-button" aria-expanded="false" aria-controls="shipping-calculator-form" role="button">%s</a>',
			esc_html( '' !== $heading ? $heading : __( 'Calcular frete', 'galaxie-woo' ) )
		);
		echo '<section class="shipping-calculator-form" id="shipping-calculator-form" style="display:none;">';

		if ( $show['country'] ) {
			printf(
				'<p class="form-row form-row-wide" id="calc_shipping_country_field"><label for="calc_shipping_country" class="%1$s">%2$s</label><select name="calc_shipping_country" id="calc_shipping_country" class="country_to_state country_select form-control" rel="calc_shipping_state"><option value="default">%3$s</option>',
				esc_attr( $label ),
				esc_html__( 'Country / region', 'woocommerce' ),
				esc_html__( 'Select a country / region&hellip;', 'woocommerce' )
			);

			foreach ( WC()->countries->get_shipping_countries() as $key => $value ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $key ), selected( $country, $key, false ), esc_html( $value ) );
			}

			echo '</select></p>';
		} else {
			// Hidden, not absent. With no country at all WooCommerce resets the
			// customer to the store's base address and discards the CEP.
			printf( '<input type="hidden" name="calc_shipping_country" id="calc_shipping_country" value="%s" />', esc_attr( $country ) );
		}

		if ( $show['state'] ) {
			$states  = WC()->countries->get_states( $country );
			$current = $customer->get_shipping_state();

			echo '<p class="form-row form-row-wide" id="calc_shipping_state_field">';

			if ( is_array( $states ) && empty( $states ) ) {
				echo '<input type="hidden" name="calc_shipping_state" id="calc_shipping_state" />';
			} elseif ( is_array( $states ) ) {
				printf(
					'<label for="calc_shipping_state" class="%1$s">%2$s</label><select name="calc_shipping_state" class="state_select form-control" id="calc_shipping_state"><option value="">%3$s</option>',
					esc_attr( $label ),
					esc_html__( 'State / County', 'woocommerce' ),
					esc_html__( 'Select an option&hellip;', 'woocommerce' )
				);

				foreach ( $states as $code => $name ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $code ), selected( $current, $code, false ), esc_html( $name ) );
				}

				echo '</select>';
			} else {
				printf(
					'<label for="calc_shipping_state" class="%1$s">%2$s</label><input type="text" class="input-text form-control" value="%3$s" name="calc_shipping_state" id="calc_shipping_state" />',
					esc_attr( $label ),
					esc_html__( 'State / County', 'woocommerce' ),
					esc_attr( $current )
				);
			}

			echo '</p>';
		}

		if ( $show['city'] ) {
			printf(
				'<p class="form-row form-row-wide" id="calc_shipping_city_field"><label for="calc_shipping_city" class="%1$s">%2$s</label><input type="text" class="input-text form-control" value="%3$s" name="calc_shipping_city" id="calc_shipping_city" /></p>',
				esc_attr( $label ),
				esc_html__( 'City', 'woocommerce' ),
				esc_attr( $customer->get_shipping_city() )
			);
		}

		// The search field is printed here rather than injected by the script,
		// so it sits where the merchant expects, above the CEP it fills, and
		// takes this widget's label and field styling. It used to be inserted
		// next to the CEP field, so turning that field off removed the search too.
		if ( 'yes' === ( $settings['show_address_search'] ?? 'yes' ) && self::address_search_available() ) {
			$search_id    = 'galaxie_places_' . $this->get_id();
			$search_label = (string) ( $settings['address_label'] ?? '' );

			printf(
				'<p class="form-row form-row-wide galaxie-places-row" id="calc_shipping_address_search_field">%1$s<input type="text" class="input-text form-control galaxie-places-input" id="%2$s" placeholder="%3$s" autocomplete="off" /></p>',
				'' !== $search_label ? '<label for="' . esc_attr( $search_id ) . '" class="' . esc_attr( $label ) . '">' . esc_html( $search_label ) . '</label>' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				esc_attr( $search_id ),
				esc_attr( (string) ( $settings['address_placeholder'] ?? '' ) )
			);
		}

		// Always printed. Every carrier quotes by CEP and the handler refuses a
		// calculation without one, so a switch that hid it could only produce a
		// calculator that never calculates.
		printf(
			'<p class="form-row form-row-wide" id="calc_shipping_postcode_field"><label for="calc_shipping_postcode" class="%1$s">%2$s</label><input type="text" class="input-text form-control" value="%3$s" name="calc_shipping_postcode" id="calc_shipping_postcode" placeholder="%4$s" inputmode="numeric" autocomplete="postal-code" /></p>',
			esc_attr( $label ),
			esc_html( (string) ( $settings['postcode_label'] ?? '' ) ),
			esc_attr( $customer->get_shipping_postcode() ),
			esc_attr( (string) ( $settings['postcode_placeholder'] ?? '' ) )
		);

		printf(
			'<p class="galaxie-shipping-calculator-actions"><button type="submit" name="calc_shipping" value="1" class="galaxie-shipping-calculator-submit">%s</button></p>',
			PixfortControls::render_button( $settings, 'calcbtn', (string) ( $settings['calcbtn_text'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own component markup.
		);

		wp_nonce_field( 'woocommerce-shipping-calculator', 'woocommerce-shipping-calculator-nonce' );

		echo '</section></form>';

		do_action( 'woocommerce_after_shipping_calculator' );

		echo '</div>';
	}
}

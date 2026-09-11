<?php
/**
 * "Galaxie Shipping Options": the carriers to choose from, on their own.
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
 * The shipping choice, wherever the merchant wants it: beside the calculator,
 * usually, rather than squeezed into the totals column.
 *
 * The options are WooCommerce's rates for the saved address, and each one is a
 * radio named `shipping_method[n]`, exactly like WooCommerce's own list. That
 * name is what makes WooCommerce's cart.js store the choice and redraw the
 * totals, so nothing here saves a selection itself.
 *
 * It prints its own markup instead of `wc_cart_totals_shipping_html()` so the
 * carrier and the price are separate elements with separate controls. That
 * template joins them into one label string: "JeT (1 a 2 dias úteis): R$16,72".
 */
final class ShippingOptionsWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-shipping-options';
	}

	public function get_title(): string {
		return __( 'Galaxie Shipping Options', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-radio';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'options_section', array( 'label' => __( 'Shipping options', 'galaxie-woo' ) ) );

		$this->add_control(
			'options_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'Using this widget? Set the Totals widget\'s "Shipping row" to "Chosen method only", so the options are not listed twice.', 'galaxie-woo' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->add_control(
			'heading',
			array(
				'label'       => __( 'Heading', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Opções de frete', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'show_destination',
			array(
				'label'        => __( 'Show destination', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'free_text',
			array(
				'label'   => __( 'Price when free', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Grátis', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'empty_text',
			array(
				'label'   => __( 'Before a CEP is entered', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 2,
				'default' => __( 'Informe o CEP para ver as opções de frete.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'none_text',
			array(
				'label'   => __( 'When no carrier delivers', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 2,
				'default' => __( 'Nenhuma opção de frete encontrada para este CEP.', 'galaxie-woo' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'options_box_style', array( 'label' => __( 'Box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'options_box', '{{WRAPPER}} .galaxie-shipping-options' );
		$this->end_controls_section();

		$this->start_controls_section( 'options_heading_style', array( 'label' => __( 'Heading', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $this, 'options_heading', array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-shipping-options-heading' );
		$this->end_controls_section();

		$this->start_controls_section( 'option_card_style', array( 'label' => __( 'Option', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		$card     = '{{WRAPPER}} .galaxie-shipping-option label';
		$selected = '{{WRAPPER}} .galaxie-shipping-option label:has(input:checked), {{WRAPPER}} .galaxie-shipping-option.is-only label';

		PixfortControls::palette_control( $this, 'option_bg', __( 'Background', 'galaxie-woo' ), $card, 'background-color' );
		PixfortControls::palette_control( $this, 'option_border', __( 'Border color', 'galaxie-woo' ), $card, 'border-color', array(), ' border-style: solid; border-width: 1px;' );

		$this->add_control( 'option_selected_heading', array( 'label' => __( 'Selected', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $this, 'option_selected_bg', __( 'Background', 'galaxie-woo' ), $selected, 'background-color' );
		PixfortControls::palette_control( $this, 'option_selected_border', __( 'Border color', 'galaxie-woo' ), $selected, 'border-color', array(), ' border-style: solid; border-width: 1px;' );

		$this->add_responsive_control(
			'option_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'separator'  => 'before',
				'selectors'  => array( $card => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'option_padding',
			array(
				'label'      => __( 'Padding', 'galaxie-woo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( $card => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'option_gap',
			array(
				'label'      => __( 'Space between options', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-shipping-options-list' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'option_name_style', array( 'label' => __( 'Carrier', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $this, 'option_name', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-shipping-option-name', 'text', array( 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'option_price_style', array( 'label' => __( 'Price', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $this, 'option_price', array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-shipping-option-price', 'text', array( 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'options_note_style', array( 'label' => __( 'Destination and notes', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $this, 'options_note', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-shipping-options-note' );
		$this->end_controls_section();
	}

	protected function render(): void {
		if ( ! CartParts::available() || WC()->cart->is_empty() || ! WC()->cart->needs_shipping() ) {
			return;
		}

		CartParts::ensure_totals();

		$settings = $this->get_settings_for_display();
		$id       = $this->get_id();
		$packages = WC()->shipping()->get_packages();
		$chosen   = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		$tax      = WC()->cart->display_prices_including_tax();
		$note     = PixfortControls::text_classes( $settings, 'options_note' );
		$name     = PixfortControls::text_classes( $settings, 'option_name' );
		$price    = PixfortControls::text_classes( $settings, 'option_price' );
		$free     = (string) ( $settings['free_text'] ?? '' );
		$listed   = false;

		printf(
			'<div class="galaxie-shipping-options %1$s" data-galaxie-fragment="shipping-options-%2$s">',
			esc_attr( PixfortControls::surface_classes( $settings, 'options_box' ) ),
			esc_attr( $id )
		);

		$heading = (string) ( $settings['heading'] ?? '' );

		if ( '' !== $heading ) {
			printf(
				'<div class="galaxie-shipping-options-heading">%s</div>',
				PixfortControls::render_text( $settings, 'options_heading', esc_html( $heading ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
			);
		}

		foreach ( $packages as $index => $package ) {
			$rates = (array) ( $package['rates'] ?? array() );

			if ( ! $rates ) {
				continue;
			}

			$listed  = true;
			$current = isset( $chosen[ $index ], $rates[ $chosen[ $index ] ] ) ? $chosen[ $index ] : (string) array_key_first( $rates );
			$only    = 1 === count( $rates );

			echo '<ul class="galaxie-shipping-options-list">';

			foreach ( $rates as $rate ) {
				$rate_id  = $rate->get_id();
				$input_id = sprintf( 'galaxie_shipping_method_%d_%s_%s', $index, sanitize_title( $rate_id ), $id );
				$cost     = (float) $rate->get_cost();
				$taxes    = array_sum( array_map( 'floatval', (array) $rate->get_taxes() ) );
				$shown    = $tax ? $cost + $taxes : $cost;

				// WooCommerce's own convention: a single option is a hidden
				// input, not a radio nobody can change.
				$input = $only
					? sprintf( '<input type="hidden" name="shipping_method[%1$d]" data-index="%1$d" id="%2$s" value="%3$s" class="shipping_method" />', $index, esc_attr( $input_id ), esc_attr( $rate_id ) )
					: sprintf( '<input type="radio" name="shipping_method[%1$d]" data-index="%1$d" id="%2$s" value="%3$s" class="shipping_method" %4$s />', $index, esc_attr( $input_id ), esc_attr( $rate_id ), checked( $rate_id, $current, false ) );

				printf(
					'<li class="galaxie-shipping-option%1$s"><label for="%2$s">%3$s<span class="galaxie-shipping-option-name %4$s">%5$s</span><span class="galaxie-shipping-option-price %6$s">%7$s</span></label>',
					$only ? ' is-only' : '',
					esc_attr( $input_id ),
					$input, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
					esc_attr( $name ),
					esc_html( $rate->get_label() ),
					esc_attr( $price ),
					$shown > 0 ? wp_kses_post( wc_price( $shown ) ) : esc_html( $free )
				);

				do_action( 'woocommerce_after_shipping_rate', $rate, $index );

				echo '</li>';
			}

			echo '</ul>';
		}

		$postcode = WC()->customer->get_shipping_postcode();

		if ( ! $listed ) {
			printf(
				'<p class="galaxie-shipping-options-note %1$s">%2$s</p>',
				esc_attr( $note ),
				esc_html( (string) ( '' === $postcode ? ( $settings['empty_text'] ?? '' ) : ( $settings['none_text'] ?? '' ) ) )
			);
		} elseif ( 'yes' === ( $settings['show_destination'] ?? 'yes' ) && '' !== $postcode ) {
			$package     = reset( $packages );
			$destination = WC()->countries->get_formatted_address( (array) ( $package['destination'] ?? array() ), ', ' );

			if ( '' !== $destination ) {
				printf(
					'<p class="galaxie-shipping-options-note galaxie-shipping-options-destination %1$s">%2$s</p>',
					esc_attr( $note ),
					/* translators: %s: shipping destination. */
					sprintf( esc_html__( 'Shipping to %s.', 'woocommerce' ), '<strong>' . esc_html( $destination ) . '</strong>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				);
			}
		}

		echo '</div>';
	}
}

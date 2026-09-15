<?php
/**
 * "Galaxie Shipping Options": the carriers to choose from, as one card.
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
 * The shipping choice, shown once there is something to choose.
 *
 * Nothing shows before a CEP is calculated; the card appears when the
 * calculator comes back. The wrapper is still printed, empty and hidden, because
 * the refresh after the calculator needs an element to replace. Without it the
 * card could never appear without a page reload.
 *
 * The card is pixfort's Card composition (`card`, its rounded and effect
 * classes, a background), with one row per carrier inside: a radio, the
 * carrier, the estimate under it and the price at the end. Each radio is named
 * `shipping_method[n]`, exactly like WooCommerce's own list, so WooCommerce's
 * cart.js stores the choice and redraws the totals. The markup lives in
 * {@see CartParts::shipping_card()} because the totals box draws the same card
 * when it lists the options itself.
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
				'raw'             => __( 'Using this widget? Set the Totals widget\'s "Shipping row shows" to "Chosen method only", so the options are not listed twice.', 'galaxie-woo' ),
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
			'show_days',
			array(
				'label'        => __( 'Show delivery estimate', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
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
			'empty_display',
			array(
				'label'     => __( 'Before a CEP is calculated', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'hide'    => __( 'Show nothing', 'galaxie-woo' ),
					'message' => __( 'Show a message', 'galaxie-woo' ),
				),
				'default'   => 'hide',
				'separator' => 'before',
			)
		);

		$this->add_control(
			'empty_text',
			array(
				'label'     => __( 'Message', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXTAREA,
				'rows'      => 2,
				'default'   => __( 'Informe o CEP para ver as opções de frete.', 'galaxie-woo' ),
				'condition' => array( 'empty_display' => 'message' ),
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

		// pixfort's Card: background, rounded corners, shadow and border.
		$this->start_controls_section( 'card_style', array( 'label' => __( 'Card', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'card', '{{WRAPPER}} .galaxie-shipping-card', array( 'rounded' => 'rounded-xl' ), array(), false );
		$this->end_controls_section();

		$this->start_controls_section( 'row_style', array( 'label' => __( 'Rows', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		$row = '{{WRAPPER}} .galaxie-shipping-row label';

		$this->add_responsive_control(
			'row_padding',
			array(
				'label'      => __( 'Padding', 'galaxie-woo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( $row => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'row_gap',
			array(
				'label'      => __( 'Space between radio and text', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array( $row => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		PixfortControls::palette_control( $this, 'row_divider', __( 'Divider color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-shipping-row + .galaxie-shipping-row', 'border-top-color', array(), ' border-top-style: solid;' );

		$this->add_responsive_control(
			'row_divider_width',
			array(
				'label'      => __( 'Divider width', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 4 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-shipping-row + .galaxie-shipping-row' => 'border-top-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		PixfortControls::palette_control( $this, 'row_hover_bg', __( 'Hover background', 'galaxie-woo' ), $row . ':hover', 'background-color' );
		PixfortControls::palette_control( $this, 'row_selected_bg', __( 'Selected background', 'galaxie-woo' ), $row . ':has(input:checked)', 'background-color' );

		$this->add_control( 'radio_heading', array( 'label' => __( 'Radio', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );

		$radio = '{{WRAPPER}} .galaxie-shipping-row input[type="radio"]';

		PixfortControls::palette_control( $this, 'radio_color', __( 'Border', 'galaxie-woo' ), $radio, 'border-color' );
		PixfortControls::palette_control( $this, 'radio_checked_color', __( 'Selected', 'galaxie-woo' ), $radio . ':checked', 'border-color' );

		$this->add_responsive_control(
			'radio_size',
			array(
				'label'      => __( 'Size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 12, 'max' => 32 ) ),
				'selectors'  => array( $radio => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'option_name_style', array( 'label' => __( 'Carrier', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $this, 'option_name', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-shipping-row-name', 'text', array( 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section(
			'option_days_style',
			array( 'label' => __( 'Delivery estimate', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'show_days' => 'yes' ) )
		);
		PixfortControls::text( $this, 'option_days', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-shipping-row-days', 'text', array( 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'option_price_style', array( 'label' => __( 'Price', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $this, 'option_price', array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-shipping-row-price', 'text', array( 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'options_note_style', array( 'label' => __( 'Destination and messages', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $this, 'options_note', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-shipping-options-note' );
		$this->end_controls_section();
	}

	protected function render(): void {
		if ( ! CartParts::available() || WC()->cart->is_empty() || ! WC()->cart->needs_shipping() ) {
			return;
		}

		$settings = $this->get_settings_for_display();
		$card     = CartParts::shipping_card(
			$settings,
			$this->get_id(),
			array(
				'free_text'        => (string) ( $settings['free_text'] ?? '' ),
				'show_days'        => 'yes' === ( $settings['show_days'] ?? 'yes' ),
				'show_destination' => 'yes' === ( $settings['show_destination'] ?? 'yes' ),
			)
		);
		$postcode = (string) WC()->customer->get_shipping_postcode();
		$message  = '';

		if ( '' === $card ) {
			if ( '' !== $postcode ) {
				$message = (string) ( $settings['none_text'] ?? '' );
			} elseif ( 'message' === ( $settings['empty_display'] ?? 'hide' ) ) {
				$message = (string) ( $settings['empty_text'] ?? '' );
			}
		}

		$hidden = '' === $card && '' === $message;

		printf(
			'<div class="galaxie-shipping-options %1$s" data-galaxie-fragment="shipping-options-%2$s"%3$s>',
			esc_attr( PixfortControls::surface_classes( $settings, 'options_box' ) ),
			esc_attr( $this->get_id() ),
			$hidden ? ' hidden' : ''
		);

		if ( ! $hidden ) {
			$heading = (string) ( $settings['heading'] ?? '' );

			if ( '' !== $heading ) {
				printf(
					'<div class="galaxie-shipping-options-heading">%s</div>',
					PixfortControls::render_text( $settings, 'options_heading', esc_html( $heading ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
				);
			}

			if ( '' !== $card ) {
				echo $card; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			} else {
				printf(
					'<p class="galaxie-shipping-options-note %1$s">%2$s</p>',
					esc_attr( PixfortControls::text_classes( $settings, 'options_note' ) ),
					esc_html( $message )
				);
			}
		}

		echo '</div>';
	}
}

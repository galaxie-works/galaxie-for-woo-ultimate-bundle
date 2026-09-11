<?php
/**
 * "Galaxie Free Shipping Progress": how far the cart is from free shipping.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Cart\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\CartParts;
use Galaxie\Woo\Support\FreeShipping;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * "Faltam R$ 23,30 para o frete grátis", anywhere on the page, with confetti
 * when it is reached.
 *
 * It says nothing until the shopper has calculated shipping to a place free
 * shipping reaches. A countdown to free shipping for a CEP outside the zone is
 * a promise checkout will not keep. The wrapper is printed anyway, empty and
 * hidden, so the refresh after the calculator has something to replace and the
 * line can appear without a reload.
 *
 * Every refresh brings back the server's own copy of this widget (see
 * cart-fragments.ts), so the numbers are never computed in the browser. The
 * browser only compares "reached" before and after, and throws the confetti
 * when it flips.
 */
final class FreeShippingProgressWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-free-shipping-progress';
	}

	public function get_title(): string {
		return __( 'Galaxie Free Shipping Progress', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-skill-bar';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'progress_section', array( 'label' => __( 'Progress', 'galaxie-woo' ) ) );

		$this->add_control(
			'source',
			array(
				'label'       => __( 'Threshold', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'auto',
				'options'     => array(
					'auto'  => __( 'From the Free Shipping module', 'galaxie-woo' ),
					'fixed' => __( 'A number I type', 'galaxie-woo' ),
				),
				'description' => __( 'Either way it only shows for a calculated CEP that free shipping reaches.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'amount',
			array(
				'label'     => __( 'Free shipping from', 'galaxie-woo' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 0,
				'default'   => 0,
				'condition' => array( 'source' => 'fixed' ),
			)
		);

		$this->add_control(
			'message',
			array(
				'label'       => __( 'While there is a way to go', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Faltam {amount} para o frete grátis', 'galaxie-woo' ),
				'description' => __( '{amount} becomes what is missing.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'when_reached',
			array(
				'label'     => __( 'Once it is reached', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'message',
				'options'   => array(
					'message' => __( 'Show a message', 'galaxie-woo' ),
					'hide'    => __( 'Hide the widget', 'galaxie-woo' ),
				),
				'separator' => 'before',
			)
		);

		$this->add_control(
			'done',
			array(
				'label'       => __( 'Message', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Você ganhou frete grátis!', 'galaxie-woo' ),
				'condition'   => array( 'when_reached' => 'message' ),
			)
		);

		$this->add_control(
			'show_bar',
			array(
				'label'        => __( 'Show the bar', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'separator'    => 'before',
			)
		);

		PixfortControls::icon_select( $this, 'icon', __( 'Icon', 'galaxie-woo' ), '' );

		$this->end_controls_section();

		$this->start_controls_section( 'celebration_section', array( 'label' => __( 'Celebration', 'galaxie-woo' ) ) );

		$this->add_control(
			'confetti',
			array(
				'label'        => __( 'Confetti when it is reached', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Fires when a change in the cart crosses the threshold. Skipped for visitors whose system asks for reduced motion.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'confetti_origin',
			array(
				'label'     => __( 'From', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'widget',
				'options'   => array(
					'widget' => __( 'This widget', 'galaxie-woo' ),
					'screen' => __( 'The top of the screen', 'galaxie-woo' ),
				),
				'condition' => array( 'confetti' => 'yes' ),
			)
		);

		$this->add_control(
			'confetti_amount',
			array(
				'label'     => __( 'Amount', 'galaxie-woo' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array( 'px' => array( 'min' => 30, 'max' => 400, 'step' => 10 ) ),
				'default'   => array( 'unit' => 'px', 'size' => 150 ),
				'condition' => array( 'confetti' => 'yes' ),
			)
		);

		PixfortControls::color_select( $this, 'confetti_color_1', __( 'Color 1', 'galaxie-woo' ), 'primary', array( 'confetti' => 'yes' ) );
		PixfortControls::color_select( $this, 'confetti_color_2', __( 'Color 2', 'galaxie-woo' ), '', array( 'confetti' => 'yes' ) );
		PixfortControls::color_select( $this, 'confetti_color_3', __( 'Color 3', 'galaxie-woo' ), '', array( 'confetti' => 'yes' ) );

		$this->add_control(
			'confetti_on_load',
			array(
				'label'        => __( 'Also when the page opens already reached', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'description'  => __( 'Once per visit, not on every reload.', 'galaxie-woo' ),
				'condition'    => array( 'confetti' => 'yes' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'progress_box_style', array( 'label' => __( 'Box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'progress_box', '{{WRAPPER}} .galaxie-free-progress' );

		$this->add_responsive_control(
			'progress_gap',
			array(
				'label'      => __( 'Space between text and bar', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-free-progress' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'progress_text_style', array( 'label' => __( 'Message', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $this, 'progress_text', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-free-progress-text' );
		$this->end_controls_section();

		$this->start_controls_section( 'progress_amount_style', array( 'label' => __( 'Amount', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $this, 'progress_amount', array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-free-progress-amount', 'text', array( 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section(
			'progress_done_style',
			array( 'label' => __( 'Message once reached', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'when_reached' => 'message' ) )
		);
		PixfortControls::text( $this, 'progress_done', array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-free-progress-text' );
		$this->end_controls_section();

		$this->start_controls_section(
			'progress_bar_style',
			array( 'label' => __( 'Bar', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'show_bar' => 'yes' ) )
		);

		PixfortControls::palette_control( $this, 'progress_track', __( 'Track', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-free-progress-track', 'background-color' );
		PixfortControls::palette_control( $this, 'progress_fill', __( 'Fill', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-free-progress-fill', 'background-color' );
		PixfortControls::palette_control( $this, 'progress_fill_done', __( 'Fill once reached', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-free-progress.is-achieved .galaxie-free-progress-fill', 'background-color' );

		$this->add_responsive_control(
			'progress_bar_height',
			array(
				'label'      => __( 'Height', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 2, 'max' => 32 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-free-progress-track' => 'height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'progress_bar_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 20 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-free-progress-track, {{WRAPPER}} .galaxie-free-progress-fill' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'progress_icon_style',
			array( 'label' => __( 'Icon', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'icon!' => '' ) )
		);
		PixfortControls::icon_color( $this, 'icon_color', __( 'Icon color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-free-progress-icon' );

		$this->add_responsive_control(
			'icon_size',
			array(
				'label'      => __( 'Icon size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 10, 'max' => 64 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-free-progress-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();
	}

	protected function render(): void {
		if ( ! CartParts::available() || WC()->cart->is_empty() ) {
			return;
		}

		$settings = $this->get_settings_for_display();
		$reach    = FreeShipping::destination_known() ? FreeShipping::threshold() : 0.0;
		$fixed    = 'fixed' === ( $settings['source'] ?? 'auto' );

		// A typed amount still needs a destination free shipping reaches.
		$threshold = $fixed ? ( $reach > 0 ? (float) ( $settings['amount'] ?? 0 ) : 0.0 ) : $reach;
		$state     = $threshold > 0 ? FreeShipping::state( $threshold ) : null;
		$achieved  = $state && $state['achieved'];
		$hidden    = ! $state || ( $achieved && 'hide' === ( $settings['when_reached'] ?? 'message' ) );

		$colors = array_filter(
			array(
				PixfortControls::color_value( $settings, 'confetti_color_1' ),
				PixfortControls::color_value( $settings, 'confetti_color_2' ),
				PixfortControls::color_value( $settings, 'confetti_color_3' ),
			)
		);

		printf(
			'<div class="galaxie-free-progress %1$s %2$s" data-galaxie-fragment="free-progress-%3$s" data-achieved="%4$s" data-confetti="%5$s" data-confetti-origin="%6$s" data-confetti-amount="%7$d" data-confetti-colors="%8$s" data-confetti-on-load="%9$s"%10$s>',
			esc_attr( PixfortControls::surface_classes( $settings, 'progress_box' ) ),
			esc_attr( $achieved ? 'is-achieved' : 'is-pending' ),
			esc_attr( $this->get_id() ),
			$achieved ? '1' : '0',
			'yes' === ( $settings['confetti'] ?? 'yes' ) ? '1' : '0',
			esc_attr( 'screen' === ( $settings['confetti_origin'] ?? 'widget' ) ? 'screen' : 'widget' ),
			(int) ( $settings['confetti_amount']['size'] ?? 150 ),
			esc_attr( implode( '|', $colors ) ),
			'yes' === ( $settings['confetti_on_load'] ?? '' ) ? '1' : '0',
			$hidden ? ' hidden' : ''
		);

		if ( ! $hidden && $state ) {
			$icon = PixfortControls::icon_value( $settings, 'icon' );

			echo '<div class="galaxie-free-progress-head">';

			if ( '' !== $icon ) {
				printf( '<span class="galaxie-free-progress-icon">%s</span>', CartParts::icon_markup( $icon ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own SVG.
			}

			if ( $achieved ) {
				$text = PixfortControls::render_text( $settings, 'progress_done', esc_html( (string) ( $settings['done'] ?? '' ) ) );
			} else {
				$amount = sprintf(
					'<span class="galaxie-free-progress-amount %1$s">%2$s</span>',
					esc_attr( PixfortControls::text_classes( $settings, 'progress_amount' ) ),
					wp_kses_post( wc_price( $state['remaining'] ) )
				);
				$text   = PixfortControls::render_text( $settings, 'progress_text', str_replace( '{amount}', $amount, esc_html( (string) ( $settings['message'] ?? '' ) ) ) );
			}

			printf( '<div class="galaxie-free-progress-text">%s</div>', $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.

			echo '</div>';

			if ( 'yes' === ( $settings['show_bar'] ?? 'yes' ) ) {
				printf(
					'<div class="galaxie-free-progress-track"><div class="galaxie-free-progress-fill" style="width: %s%%;"></div></div>',
					esc_attr( (string) round( $state['percent'], 2 ) )
				);
			}
		}

		echo '</div>';
	}
}

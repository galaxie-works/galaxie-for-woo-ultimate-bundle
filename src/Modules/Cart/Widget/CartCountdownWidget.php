<?php
/**
 * "Galaxie Cart Countdown" — the urgency timer above the cart.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Cart\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\CartCountdown;
use Galaxie\Woo\Support\CartParts;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * XStore's sales-booster countdown, in this plugin's idiom.
 *
 * Ported rather than invented: the message with its `{timer}` and `{fire}`
 * placeholders, the expired message, the loop option and the minutes are all
 * XStore's design, and it is a design that has been shipped to a lot of stores.
 * What changed is where the styling comes from — pixfort's own box and text
 * sets, like everything else here — and that the widget renders nothing at all
 * on an empty cart, because a deadline for buying nothing is just a lie with a
 * clock on it.
 */
final class CartCountdownWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-cart-countdown';
	}

	public function get_title(): string {
		return __( 'Galaxie Cart Countdown', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-countdown';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'countdown_section', array( 'label' => __( 'Countdown', 'galaxie-woo' ) ) );

		$this->add_control(
			'minutes',
			array(
				'label'   => __( 'Minutes', 'galaxie-woo' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 180,
				'default' => 5,
			)
		);

		$this->add_control(
			'loop',
			array(
				'label'        => __( 'Restart when it runs out', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'A timer that never really ends. Off is the honest setting; on is the one that keeps selling.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'message',
			array(
				'label'       => __( 'Message', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 3,
				'default'     => __( '{fire} Corre! Estes produtos são limitados — finalize em {timer}', 'galaxie-woo' ),
				'description' => __( '{timer} becomes the clock, {fire} becomes 🔥.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'expired_message',
			array(
				'label'     => __( 'When time runs out', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXTAREA,
				'rows'      => 3,
				'default'   => __( 'Seu tempo acabou! Finalize agora para não perder o pedido.', 'galaxie-woo' ),
				'condition' => array( 'loop' => '' ),
			)
		);

		PixfortControls::icon_select( $this, 'countdown_icon', __( 'Icon', 'galaxie-woo' ), '' );

		$this->end_controls_section();

		$this->start_controls_section(
			'countdown_box_style',
			array( 'label' => __( 'Box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::surface( $this, 'countdown_box', '{{WRAPPER}} .galaxie-countdown' );
		PixfortControls::icon_color( $this, 'countdown_icon_color', __( 'Icon color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-countdown-icon', array( 'countdown_icon!' => '' ) );
		$this->add_responsive_control(
			'countdown_icon_size',
			array(
				'label'      => __( 'Icon size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 10, 'max' => 64 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 24 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-countdown-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
				'condition'  => array( 'countdown_icon!' => '' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'countdown_text_style',
			array( 'label' => __( 'Message', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::text(
			$this,
			'countdown',
			array( 'bold' => '', 'remove_pb_padding' => 'm-0', 'position' => 'text-center' ),
			array(),
			'{{WRAPPER}} .galaxie-countdown'
		);
		$this->end_controls_section();

		// The clock gets its own controls because it is the part a shopper
		// looks at, and because it is inside the message rather than beside it
		// — a selector is the only thing that can reach it.
		$this->start_controls_section(
			'countdown_time_style',
			array( 'label' => __( 'Clock', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		// pixfort's own text vocabulary, not a Size and a Weight of our own.
		// The clock sits inside the message rather than beside it, so it cannot
		// be a pixfort Text element — but those classes are just CSS, and a
		// span can carry them. {@see PixfortControls::text_classes()}.
		//
		// The home-made Weight select is gone with them. Its 400 and 700 did
		// reach the page — measured — but the theme's font ships 500 and 600,
		// so two of its four options quietly did nothing. pixfort offers Bold
		// or not for exactly that reason.
		PixfortControls::text(
			$this,
			'time',
			array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0', 'position' => '' ),
			array(),
			'{{WRAPPER}} .galaxie-countdown-time'
		);

		PixfortControls::palette_control( $this, 'time_bg', __( 'Background', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-countdown-time', 'background-color' );

		$this->add_responsive_control(
			'time_padding',
			array(
				'label'      => __( 'Padding', 'galaxie-woo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-countdown-time' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'time_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-countdown-time' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		if ( ! CartParts::available() || WC()->cart->is_empty() ) {
			return;
		}

		$settings            = $this->get_settings_for_display();
		self::$time_classes  = PixfortControls::text_classes( $settings, 'time' );
		$loop                = 'yes' === ( $settings['loop'] ?? '' );
		$state    = CartCountdown::state( (int) ( $settings['minutes'] ?? 5 ), $loop );

		printf(
			'<div class="galaxie-countdown %1$s %2$s" data-galaxie-countdown="1" data-remaining="%3$d" data-total="%4$d" data-loop="%5$s">',
			esc_attr( PixfortControls::surface_classes( $settings, 'countdown_box' ) ),
			esc_attr( $state['expired'] ? 'is-expired' : 'is-live' ),
			(int) $state['remaining'],
			(int) $state['total'],
			$loop ? '1' : '0'
		);

		$icon = PixfortControls::icon_value( $settings, 'countdown_icon' );

		if ( '' !== $icon ) {
			printf( '<span class="galaxie-countdown-icon">%s</span>', CartParts::icon_markup( $icon ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own SVG.
		}

		echo '<span class="galaxie-countdown-body">';

		// Both messages are rendered now, styled identically, and the browser
		// only ever swaps which one is visible. Handing the JS a template to
		// build the second one from would mean the expired state was the one
		// place the merchant's styling did not reach.
		printf(
			'<span class="galaxie-countdown-live"%s>%s</span>',
			$state['expired'] ? ' hidden' : '',
			PixfortControls::render_text( $settings, 'countdown', self::message( (string) ( $settings['message'] ?? '' ), $state['remaining'] ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		);

		if ( ! $loop ) {
			printf(
				'<span class="galaxie-countdown-expired"%s>%s</span>',
				$state['expired'] ? '' : ' hidden',
				PixfortControls::render_text( $settings, 'countdown', esc_html( (string) ( $settings['expired_message'] ?? '' ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
			);
		}

		echo '</span></div>';
	}

	/**
	 * XStore's two placeholders, kept because merchants already write them.
	 */
	/** Set before {@see message()} runs, which is a static callee of render(). */
	private static string $time_classes = '';

	private static function message( string $template, int $remaining ): string {
		return str_replace(
			array( '{timer}', '{fire}' ),
			array(
				'<span class="galaxie-countdown-time ' . esc_attr( self::$time_classes ) . '">' . esc_html( CartCountdown::format( $remaining ) ) . '</span>',
				'&#128293;',
			),
			esc_html( $template )
		);
	}
}

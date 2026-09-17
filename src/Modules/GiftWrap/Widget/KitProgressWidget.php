<?php
/**
 * "Galaxie Kit Progress": how full the kit being built is, anywhere on a page.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\CartParts;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * "Kit Stella · Ainda cabem 1 × 190g ou 2 × 50g", a fill bar and "Ver kit";
 * confetti and "Adicionar kit ao carrinho" once the box is full. Modelled on
 * the Galaxie Free Shipping Progress widget.
 *
 * The page is cached, so this prints a hidden frame with its texts and nothing
 * about the visitor's kit; kit-progress.ts fills it from the kit endpoint and
 * keeps it in step with every change made anywhere on the page (the Buy Box,
 * the popup, another tab) without a reload. Without a kit it stays hidden, or
 * shows an invitation that opens the kit popup.
 */
final class KitProgressWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-kit-progress';
	}

	public function get_title(): string {
		return __( 'Galaxie Kit Progress', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-skill-bar';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'kit_progress_section', array( 'label' => __( 'Kit progress', 'galaxie-woo' ) ) );

		$this->add_control(
			'kit_progress_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Follows the kit the shopper is building. It opens the popup set in wp-admin → Galaxie → Gift Wrap ("Popup do kit"); the room-left sentence ({room}) is set there too.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'message',
			array(
				'label'       => __( 'With a kit open', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( '{kit} · {room}', 'galaxie-woo' ),
				'description' => __( '{kit}: the kit\'s name. {room}: "Ainda cabem …" or "Caixa completa! 🎉". {combos}: the bare list. {preço}: the kit\'s total.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'view_text',
			array(
				'label'       => __( '"Ver kit" link', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Ver kit', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'cart_text',
			array(
				'label'       => __( 'Button when the box is full', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Adicionar kit ao carrinho', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'show_invite',
			array(
				'label'        => __( 'With no kit: show an invitation', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'separator'    => 'before',
				'description'  => __( 'Off: the widget stays hidden until a kit is started.', 'galaxie-woo' ),
			)
		);

		$invite = array( 'show_invite' => 'yes' );

		$this->add_control(
			'invite_text',
			array(
				'label'       => __( 'Invitation', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Monte um kit de presente', 'galaxie-woo' ),
				'condition'   => $invite,
			)
		);

		$this->add_control(
			'invite_button',
			array(
				'label'       => __( 'Invitation button', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Montar um kit', 'galaxie-woo' ),
				'condition'   => $invite,
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

		PixfortControls::icon_select( $this, 'icon', __( 'Icon', 'galaxie-woo' ), 'Line/pixfort-icon-gift-1' );

		$this->add_control(
			'editor_state',
			array(
				'label'     => __( 'Show in the editor', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'kit',
				'options'   => array(
					'kit'    => __( 'A kit with room left', 'galaxie-woo' ),
					'full'   => __( 'A full kit', 'galaxie-woo' ),
					'invite' => __( 'No kit (invitation)', 'galaxie-woo' ),
				),
				'separator' => 'before',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'kit_celebration_section', array( 'label' => __( 'Celebration', 'galaxie-woo' ) ) );

		$this->add_control(
			'confetti',
			array(
				'label'        => __( 'Confetti when the box fills', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Fires when a change fills the box. Skipped for visitors whose system asks for reduced motion.', 'galaxie-woo' ),
			)
		);

		$on = array( 'confetti' => 'yes' );

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
				'condition' => $on,
			)
		);

		$this->add_control(
			'confetti_amount',
			array(
				'label'     => __( 'Amount', 'galaxie-woo' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array( 'px' => array( 'min' => 30, 'max' => 400, 'step' => 10 ) ),
				'default'   => array( 'unit' => 'px', 'size' => 150 ),
				'condition' => $on,
			)
		);

		$this->add_control(
			'confetti_style',
			array(
				'label'     => __( 'Colors', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'festive',
				'options'   => array(
					'festive' => __( 'Festive (many colors)', 'galaxie-woo' ),
					'palette' => __( 'From the theme palette', 'galaxie-woo' ),
				),
				'condition' => $on,
			)
		);

		$palette = array( 'confetti' => 'yes', 'confetti_style' => 'palette' );

		PixfortControls::color_select( $this, 'confetti_color_1', __( 'Color 1', 'galaxie-woo' ), 'primary', $palette );
		PixfortControls::color_select( $this, 'confetti_color_2', __( 'Color 2', 'galaxie-woo' ), '', $palette );
		PixfortControls::color_select( $this, 'confetti_color_3', __( 'Color 3', 'galaxie-woo' ), '', $palette );

		$this->add_control(
			'confetti_on_load',
			array(
				'label'        => __( 'Also when the page opens with a full box', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'description'  => __( 'Once per visit, not on every reload.', 'galaxie-woo' ),
				'condition'    => $on,
			)
		);

		$this->end_controls_section();

		$style = static fn( string $label, array $condition = array() ): array => array(
			'label'     => $label,
			'tab'       => Controls_Manager::TAB_STYLE,
			'condition' => $condition,
		);

		$this->start_controls_section( 'kit_progress_box_style', $style( __( 'Box', 'galaxie-woo' ) ) );
		PixfortControls::surface( $this, 'progress_box', '{{WRAPPER}} .galaxie-kit-progress' );
		$this->add_responsive_control(
			'progress_gap',
			array(
				'label'      => __( 'Space between parts', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-progress-part' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section( 'kit_progress_text_style', $style( __( 'Message', 'galaxie-woo' ) ) );
		PixfortControls::text( $this, 'progress_text', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-kit-progress-text' );
		$this->end_controls_section();

		$this->start_controls_section( 'kit_progress_bar_style', $style( __( 'Bar', 'galaxie-woo' ), array( 'show_bar' => 'yes' ) ) );
		PixfortControls::palette_control( $this, 'progress_track', __( 'Track', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-progress-track', 'background-color' );
		PixfortControls::palette_control( $this, 'progress_fill', __( 'Fill', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-progress-fill', 'background-color' );
		PixfortControls::palette_control( $this, 'progress_fill_done', __( 'Fill when full', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-progress.is-full .galaxie-kit-progress-fill', 'background-color' );
		$this->add_responsive_control(
			'progress_bar_height',
			array(
				'label'      => __( 'Height', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 2, 'max' => 32 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-progress-track' => 'height: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'progress_bar_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 20 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-progress-track, {{WRAPPER}} .galaxie-kit-progress-fill' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section( 'kit_progress_icon_style', $style( __( 'Icon', 'galaxie-woo' ), array( 'icon!' => '' ) ) );
		PixfortControls::icon_color( $this, 'icon_color', __( 'Icon color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-progress-icon' );
		$this->add_responsive_control(
			'icon_size',
			array(
				'label'      => __( 'Icon size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 10, 'max' => 64 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-progress-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section( 'kit_progress_primary_style', $style( __( 'Primary button (full box, invitation)', 'galaxie-woo' ) ) );
		PixfortControls::button( $this, 'progress_primary', array( 'color' => 'primary', 'size' => 'sm' ), array(), '{{WRAPPER}}', array( 'text' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'kit_progress_link_style', $style( __( '"Ver kit" link', 'galaxie-woo' ) ) );
		PixfortControls::button( $this, 'progress_link', array( 'style' => 'link', 'color' => 'primary', 'size' => 'sm', 'remove_padding' => 'no-padding' ), array(), '{{WRAPPER}}', array( 'text' ) );
		$this->end_controls_section();
	}

	protected function render(): void {
		Assets::enqueue();
		Assets::enqueue_kit();

		$settings = $this->get_settings_for_display();
		$editing  = class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode();
		$preview  = $editing ? (string) ( $settings['editor_state'] ?? 'kit' ) : '';
		$full     = 'full' === $preview;
		$invite   = 'yes' === ( $settings['show_invite'] ?? '' );

		// No colours sent means the confetti script's own festive mix.
		$colors = 'palette' === ( $settings['confetti_style'] ?? 'festive' )
			? array_filter(
				array(
					PixfortControls::color_value( $settings, 'confetti_color_1' ),
					PixfortControls::color_value( $settings, 'confetti_color_2' ),
					PixfortControls::color_value( $settings, 'confetti_color_3' ),
				)
			)
			: array();

		printf(
			'<div class="galaxie-kit-progress %1$s%2$s" data-galaxie-kit-progress data-message="%3$s" data-invite="%4$s" data-confetti="%5$s" data-confetti-origin="%6$s" data-confetti-amount="%7$d" data-confetti-colors="%8$s" data-confetti-on-load="%9$s"%10$s>',
			esc_attr( PixfortControls::surface_classes( $settings, 'progress_box' ) ),
			$full ? ' is-full' : '',
			esc_attr( (string) ( $settings['message'] ?? '' ) ),
			$invite ? '1' : '0',
			'yes' === ( $settings['confetti'] ?? 'yes' ) ? '1' : '0',
			esc_attr( 'screen' === ( $settings['confetti_origin'] ?? 'widget' ) ? 'screen' : 'widget' ),
			(int) ( $settings['confetti_amount']['size'] ?? 150 ),
			esc_attr( implode( '|', $colors ) ),
			'yes' === ( $settings['confetti_on_load'] ?? '' ) ? '1' : '0',
			$editing ? ' data-sample="1"' : ' hidden'
		);

		// Without a kit.
		printf( '<div class="galaxie-kit-progress-part galaxie-kit-progress-invite" data-kit-invite%s>', 'invite' === $preview ? '' : ' hidden' );
		printf( '<div class="galaxie-kit-progress-text">%s</div>', PixfortControls::render_text( $settings, 'progress_text', esc_html( (string) ( $settings['invite_text'] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's element around escaped text.
		echo $this->button( $settings, 'progress_primary', 'open', (string) ( $settings['invite_button'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</div>';

		// With one.
		$line = $full ? __( 'Kit 1 · Caixa completa! 🎉', 'galaxie-woo' ) : __( 'Kit 1 · Ainda cabem 1 × 190g ou 2 × 50g', 'galaxie-woo' );

		printf( '<div class="galaxie-kit-progress-part galaxie-kit-progress-kit" data-kit-state%s>', in_array( $preview, array( 'kit', 'full' ), true ) ? '' : ' hidden' );
		echo '<div class="galaxie-kit-progress-head">';

		$icon = PixfortControls::icon_value( $settings, 'icon' );

		// Only pixfort's icon: CartParts' fallback is a remove cross.
		if ( '' !== $icon && PixfortControls::available() ) {
			printf( '<span class="galaxie-kit-progress-icon">%s</span>', CartParts::icon_markup( $icon ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own SVG.
		}

		printf(
			'<div class="galaxie-kit-progress-text">%s</div>',
			PixfortControls::render_text( $settings, 'progress_text', '<span data-slot="line">' . esc_html( $editing ? $line : '' ) . '</span>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's element around escaped text.
		);

		echo $this->button( $settings, 'progress_link', 'view', (string) ( $settings['view_text'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</div>';

		if ( 'yes' === ( $settings['show_bar'] ?? 'yes' ) ) {
			printf( '<div class="galaxie-kit-progress-track"><div class="galaxie-kit-progress-fill" data-slot="bar" style="width:%d%%"></div></div>', $full ? 100 : ( $editing ? 60 : 0 ) );
		}

		printf( '<div class="galaxie-kit-progress-actions" data-kit-when="full"%s>', $full ? '' : ' hidden' );
		echo $this->button( $settings, 'progress_primary', 'to-cart', (string) ( $settings['cart_text'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</div>';

		echo '</div>';
		echo '</div>';
	}

	/** @param array<string,mixed> $settings */
	private function button( array $settings, string $prefix, string $action, string $text ): string {
		return sprintf(
			'<button type="button" class="galaxie-buybox-btn galaxie-kit-progress-btn" data-kit-action="%1$s">%2$s</button>',
			esc_attr( $action ),
			PixfortControls::render_button( $settings, $prefix, $text )
		);
	}
}

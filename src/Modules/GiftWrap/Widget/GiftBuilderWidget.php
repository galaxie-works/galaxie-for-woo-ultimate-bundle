<?php
/**
 * "Galaxie Gift Builder" Elementor widget — the inside of the gift popup.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * Placed inside a pixfort popup that the Buy Box's "Presente" section opens.
 *
 * The popup is fetched over AJAX with no product in context, so nothing about
 * the candle is known when this renders: it prints an empty card and the Buy
 * Box script fills it from the form that opened the popup — name, chosen
 * variation, quantity, picture (see frontend/src/globals/gift-wrap.ts).
 *
 * Phase 1 is only the candle and the two ways on. Both buttons close the popup
 * and continue the click that opened it (add to cart, or Buy Now); the popup is
 * meant to have pixfort's own close button, click-outside and Esc turned off,
 * so these two are the only ways out. Phase 2 fills the space between with
 * boxes, ribbons and cards.
 */
final class GiftBuilderWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-gift-builder';
	}

	public function get_title(): string {
		return __( 'Galaxie Gift Builder', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-cart-medium';
	}

	public function get_categories(): array {
		return array( 'galaxie' );
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'builder_section',
			array( 'label' => __( 'Gift Builder', 'galaxie-woo' ) )
		);

		$this->add_control(
			'builder_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Put this widget inside a pixfort popup, then choose that popup in the Galaxie Buy Box, section "Gift (Presente)". The candle is filled in on the product page from what the shopper chose; both buttons carry on with the Add to Cart or Buy Now that opened the popup.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'title_text',
			array(
				'label'       => __( 'Title', 'galaxie-woo' ),
				'label_block' => true,
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Monte seu presente', 'galaxie-woo' ),
				'placeholder' => __( 'Leave empty for no title', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'empty_text',
			array(
				'label'       => __( 'Shown with no candle', 'galaxie-woo' ),
				'description' => __( 'When the popup is opened from anywhere but a Buy Box.', 'galaxie-woo' ),
				'label_block' => true,
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => __( 'Escolha a vela na página do produto para montar o presente.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'builder_preview',
			array(
				'label'        => __( 'Show a sample candle in the editor', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'title_style_section',
			array( 'label' => __( 'Title', 'galaxie-woo' ) )
		);
		PixfortControls::text( $this, 'title', array( 'size' => 'h5', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-gift-builder-title', 'heading' );
		$this->end_controls_section();

		$this->start_controls_section(
			'item_section',
			array( 'label' => __( 'Candle', 'galaxie-woo' ) )
		);

		$this->add_control(
			'item_name_heading',
			array(
				'label' => __( 'Name', 'galaxie-woo' ),
				'type'  => Controls_Manager::HEADING,
			)
		);
		PixfortControls::text( $this, 'item_name', array( 'bold' => 'font-weight-bold' ), array(), '{{WRAPPER}} .galaxie-gift-builder-name', 'text', array( 'inline', 'position' ) );

		$this->add_control(
			'item_meta_heading',
			array(
				'label'     => __( 'Size and quantity', 'galaxie-woo' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
		PixfortControls::text( $this, 'item_meta', array( 'size' => 'text-sm', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-builder-meta', 'text', array( 'inline', 'position' ) );

		$this->add_control(
			'item_card_heading',
			array(
				'label'     => __( 'Card', 'galaxie-woo' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
		PixfortControls::surface( $this, 'item_card', '{{WRAPPER}} .galaxie-gift-builder-item' );

		$this->add_responsive_control(
			'item_image_size',
			array(
				'label'      => __( 'Picture size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 32, 'max' => 160 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 64 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-gift-builder-thumb' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'confirm_section',
			array( 'label' => __( 'Confirm button', 'galaxie-woo' ) )
		);
		PixfortControls::button( $this, 'confirm', array( 'text' => __( 'Confirmar presente', 'galaxie-woo' ), 'color' => 'primary', 'icon' => 'Line/pixfort-icon-gift-1' ) );
		$this->end_controls_section();

		$this->start_controls_section(
			'bypass_section',
			array( 'label' => __( 'Continue-without button', 'galaxie-woo' ) )
		);
		PixfortControls::button( $this, 'bypass', array( 'text' => __( 'Seguir sem incrementar o presente', 'galaxie-woo' ), 'style' => 'link', 'color' => 'primary' ) );
		$this->end_controls_section();

		$this->start_controls_section(
			'builder_layout_section',
			array(
				'label' => __( 'Layout', 'galaxie-woo' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'builder_gap',
			array(
				'label'      => __( 'Gap between parts', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 16 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-gift-builder' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		// The popup is usually on a product page that loaded the bundle already;
		// asking again costs nothing and covers a popup placed anywhere else.
		Assets::enqueue();

		$settings = $this->get_settings_for_display();
		$sample   = self::is_editing() && 'yes' === ( $settings['builder_preview'] ?? 'yes' );
		$title    = trim( (string) ( $settings['title_text'] ?? '' ) );
		$empty    = trim( (string) ( $settings['empty_text'] ?? '' ) );

		printf( '<div class="galaxie-gift-builder" data-galaxie-gift-builder%s>', $sample ? ' data-sample="1"' : '' );

		if ( '' !== $title ) {
			echo '<div class="galaxie-gift-builder-title">' . PixfortControls::render_text( $settings, 'title', esc_html( $title ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup around escaped text.
		}

		if ( '' !== $empty ) {
			printf(
				'<p class="galaxie-gift-builder-empty %1$s" data-gift-empty%2$s>%3$s</p>',
				esc_attr( PixfortControls::text_classes( $settings, 'item_meta' ) ),
				$sample ? ' hidden' : '',
				esc_html( $empty )
			);
		}

		printf(
			'<div class="galaxie-gift-builder-item %1$s" data-gift-item%2$s>',
			esc_attr( PixfortControls::surface_classes( $settings, 'item_card' ) ),
			$sample ? '' : ' hidden'
		);

		// No src until there is a picture: an empty one is still a request.
		$image = $sample && function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'woocommerce_thumbnail' ) : '';
		printf(
			'<img class="galaxie-gift-builder-thumb" alt="" data-gift-image%s />',
			'' !== $image ? ' src="' . esc_url( $image ) . '"' : ' hidden'
		);

		echo '<span class="galaxie-gift-builder-text">';
		printf(
			'<span class="galaxie-gift-builder-name %1$s" data-gift-name>%2$s</span>',
			esc_attr( PixfortControls::text_classes( $settings, 'item_name' ) ),
			$sample ? esc_html__( 'Vela aromática', 'galaxie-woo' ) : ''
		);
		printf(
			'<span class="galaxie-gift-builder-meta %1$s" data-gift-meta>%2$s</span>',
			esc_attr( PixfortControls::text_classes( $settings, 'item_meta' ) ),
			$sample ? esc_html__( '190g · 1 unidade', 'galaxie-woo' ) : ''
		);
		echo '</span></div>';

		echo '<div class="galaxie-gift-builder-actions">';
		foreach ( array( 'confirm', 'bypass' ) as $action ) {
			printf( '<button type="button" class="galaxie-buybox-btn galaxie-gift-builder-%1$s" data-galaxie-gift-action="%1$s">', esc_attr( $action ) );
			echo PixfortControls::render_button( $settings, $action, (string) ( $settings[ $action . '_text' ] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
			echo '</button>';
		}
		echo '</div>';

		echo '</div>';
	}

	/** True inside the Elementor editor's canvas. */
	private static function is_editing(): bool {
		return class_exists( '\\Elementor\\Plugin' )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}

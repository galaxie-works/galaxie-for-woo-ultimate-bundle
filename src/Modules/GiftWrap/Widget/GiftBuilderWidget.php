<?php
/**
 * "Galaxie Gift Builder" Elementor widget — the inside of the gift popup.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Modules\GiftWrap\Module;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\GiftGroups;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * Placed inside a pixfort popup that the Buy Box's "Presente" section opens.
 *
 * The popup is fetched over AJAX with no product in context, so nothing about
 * the candle or the cart is known when this renders. It prints the frame — the
 * candle card, the empty places for the choices, the buttons — and one
 * `<template>` per repeated part (a way to add the candle, a loose candle, a
 * gift, a box, an accessory row, a card message), each already carrying the
 * pixfort classes its controls chose. The script (frontend/src/globals/
 * gift-builder.ts) asks the server what the cart and the store hold, then
 * clones those templates and fills their `data-slot`s, so every styling
 * control reaches markup that did not exist at render time.
 *
 * In the editor the same part renderers draw a sample gift instead, so each
 * control moves something on the canvas.
 *
 * Confirm puts the whole gift in the cart (candles, box, ribbons, cards) in
 * place of the Add to Cart or Buy Now that opened the popup; "Seguir sem
 * incrementar o presente" lets that click carry on with the gift flag alone.
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

	/**
	 * Texts the script writes into the page, by key => [ label, default ].
	 * `%s`, `%d` and `%1$d` are filled in by the script.
	 *
	 * @return array<string, array{0:string, 1:string}>
	 */
	private static function texts(): array {
		return array(
			'loading_text'      => array( __( 'Loading', 'galaxie-woo' ), __( 'Carregando as opções do presente…', 'galaxie-woo' ) ),
			'error_text'        => array( __( 'Could not load', 'galaxie-woo' ), __( 'Não foi possível carregar as opções. Você pode seguir sem incrementar o presente.', 'galaxie-woo' ) ),
			'mode_heading_text' => array( __( 'Where to put the candle (heading)', 'galaxie-woo' ), __( 'Onde colocar esta vela', 'galaxie-woo' ) ),
			'mode_new_text'     => array( __( 'New gift', 'galaxie-woo' ), __( 'Novo presente', 'galaxie-woo' ) ),
			/* translators: %s: "Presente 1". Kept for the script. */
			'mode_extend_text'  => array( __( 'Add to an existing gift (%s = its name)', 'galaxie-woo' ), __( 'Adicionar ao %s', 'galaxie-woo' ) ),
			'loose_heading_text' => array( __( 'Gift candles already in the cart (heading)', 'galaxie-woo' ), __( 'Incluir velas de presente que já estão no carrinho', 'galaxie-woo' ) ),
			'arrange_hint_text' => array( __( 'Under "Organizar automaticamente"', 'galaxie-woo' ), __( 'Distribuímos as velas no menor número de caixas, pelo menor preço.', 'galaxie-woo' ) ),
			/* translators: %d: gift number. Kept for the script. */
			'group_title_text'  => array( __( 'Gift title (%d = number)', 'galaxie-woo' ), __( 'Presente %d', 'galaxie-woo' ) ),
			'box_heading_text'  => array( __( 'Box (heading)', 'galaxie-woo' ), __( 'Caixa', 'galaxie-woo' ) ),
			'no_box_text'       => array( __( 'No box', 'galaxie-woo' ), __( 'Sem caixa', 'galaxie-woo' ) ),
			/* translators: %s: e.g. "1 × 50g · 2 × 190g". Kept for the script. */
			'room_text'         => array( __( 'Room left (%s = sizes)', 'galaxie-woo' ), __( 'Cabe mais %s', 'galaxie-woo' ) ),
			'full_text'         => array( __( 'Box full', 'galaxie-woo' ), __( 'Caixa cheia', 'galaxie-woo' ) ),
			'no_fit_text'       => array( __( 'No box fits', 'galaxie-woo' ), __( 'Nenhuma caixa comporta estas velas.', 'galaxie-woo' ) ),
			'ribbons_heading_text' => array( __( 'Ribbons (heading)', 'galaxie-woo' ), __( 'Fitas', 'galaxie-woo' ) ),
			'cards_heading_text'   => array( __( 'Cards (heading)', 'galaxie-woo' ), __( 'Cartões', 'galaxie-woo' ) ),
			/* translators: %s: what the gift already holds. Kept for the script. */
			'existing_text'     => array( __( 'Already in the gift (%s = items)', 'galaxie-woo' ), __( 'Já no presente: %s', 'galaxie-woo' ) ),
			'message_placeholder_text' => array( __( 'Card message placeholder', 'galaxie-woo' ), __( 'Escreva a mensagem do cartão (opcional)', 'galaxie-woo' ) ),
			'out_of_stock_text' => array( __( 'Out of stock', 'galaxie-woo' ), __( 'Esgotado', 'galaxie-woo' ) ),
			'total_text'        => array( __( 'Total label', 'galaxie-woo' ), __( 'Total a adicionar', 'galaxie-woo' ) ),
		);
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
				'raw'             => esc_html__( 'Put this widget inside a pixfort popup, then paste that popup\'s link in the Galaxie Buy Box, section "Gift (Presente)". On the product page it shows the candle the shopper chose, the gifts already in the cart, and the boxes, ribbons and cards from the categories set in wp-admin → Galaxie → Gift Wrap (or below). Confirm adds the whole gift; the other button continues with the gift flag only.', 'galaxie-woo' ),
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
				'label'        => __( 'Show a sample gift in the editor', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'texts_section',
			array( 'label' => __( 'Texts', 'galaxie-woo' ) )
		);

		foreach ( self::texts() as $key => $text ) {
			$this->add_control(
				$key,
				array(
					'label'       => $text[0],
					'label_block' => true,
					'type'        => Controls_Manager::TEXT,
					'default'     => $text[1],
				)
			);
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'categories_section',
			array( 'label' => __( 'Accessory categories', 'galaxie-woo' ) )
		);

		$this->add_control(
			'categories_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Leave empty to use the categories chosen in wp-admin → Galaxie → Gift Wrap.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$categories = class_exists( Module::class ) ? Module::category_options() : array();

		foreach ( array(
			'box_categories'    => __( 'Gift box categories', 'galaxie-woo' ),
			'ribbon_categories' => __( 'Ribbon categories', 'galaxie-woo' ),
			'card_categories'   => __( 'Card categories', 'galaxie-woo' ),
		) as $key => $label ) {
			$this->add_control(
				$key,
				array(
					'label'       => $label,
					'label_block' => true,
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $categories,
					'default'     => array(),
				)
			);
		}

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

		$this->heading( 'item_name_heading', __( 'Name', 'galaxie-woo' ), false );
		PixfortControls::text( $this, 'item_name', array( 'bold' => 'font-weight-bold' ), array(), '{{WRAPPER}} .galaxie-gift-builder-name', 'text', array( 'inline', 'position' ) );

		$this->heading( 'item_meta_heading', __( 'Size and quantity', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'item_meta', array( 'size' => 'text-sm', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-builder-meta', 'text', array( 'inline', 'position' ) );

		$this->heading( 'item_card_heading', __( 'Card', 'galaxie-woo' ) );
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
			'modes_style_section',
			array( 'label' => __( 'New or existing gift', 'galaxie-woo' ) )
		);
		$this->heading( 'section_title_heading', __( 'Headings ("Onde colocar", "Caixa", "Fitas"…)', 'galaxie-woo' ), false );
		PixfortControls::text( $this, 'section_title', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ), array(), '{{WRAPPER}} .galaxie-gift-heading', 'text', array( 'inline', 'position' ) );
		$this->heading( 'mode_label_heading', __( 'Option', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'mode_label', array( 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-mode-label', 'text', array( 'inline', 'position' ) );
		$this->heading( 'mode_meta_heading', __( 'Option detail', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'mode_meta', array( 'size' => 'text-xs', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-mode-meta', 'text', array( 'inline', 'position' ) );
		$this->heading( 'mode_box_heading', __( 'Option box', 'galaxie-woo' ) );
		PixfortControls::surface( $this, 'mode', '{{WRAPPER}} .galaxie-gift-mode' );
		PixfortControls::palette_control( $this, 'mode_tick_color', __( 'Tick color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-gift-mode input, {{WRAPPER}} .galaxie-gift-loose input', 'accent-color' );
		$this->end_controls_section();

		$this->start_controls_section(
			'group_style_section',
			array( 'label' => __( 'Gift card', 'galaxie-woo' ) )
		);
		$this->heading( 'group_title_heading', __( 'Title ("Presente 1")', 'galaxie-woo' ), false );
		PixfortControls::text( $this, 'group_title', array( 'bold' => 'font-weight-bold' ), array(), '{{WRAPPER}} .galaxie-gift-group-title', 'text', array( 'inline', 'position' ) );
		$this->heading( 'group_candles_heading', __( 'Candles in it', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'group_candles', array( 'size' => 'text-sm', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-group-candles', 'text', array( 'inline', 'position' ) );
		$this->heading( 'existing_heading', __( 'Already in the gift', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'existing', array( 'size' => 'text-xs', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-existing', 'text', array( 'inline', 'position' ) );
		$this->heading( 'group_box_heading', __( 'Card box', 'galaxie-woo' ) );
		PixfortControls::surface( $this, 'group', '{{WRAPPER}} .galaxie-gift-group' );
		$this->end_controls_section();

		$this->start_controls_section(
			'box_style_section',
			array( 'label' => __( 'Box choice', 'galaxie-woo' ) )
		);
		$this->heading( 'box_name_heading', __( 'Name', 'galaxie-woo' ), false );
		PixfortControls::text( $this, 'box_name', array( 'size' => 'text-sm', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-box-name', 'text', array( 'inline', 'position' ) );
		$this->heading( 'box_price_heading', __( 'Price', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'box_price', array( 'size' => 'text-xs', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-box-price', 'text', array( 'inline', 'position' ) );
		$this->heading( 'box_option_heading', __( 'Option box', 'galaxie-woo' ) );
		PixfortControls::surface( $this, 'box_option', '{{WRAPPER}} .galaxie-gift-box' );
		PixfortControls::palette_control( $this, 'box_selected_color', __( 'Chosen: border color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-gift-box[aria-pressed="true"]', 'border-color', array(), ' border-style: solid; border-width: 2px;' );
		$this->end_controls_section();

		$this->start_controls_section(
			'fill_style_section',
			array( 'label' => __( 'Fill bar', 'galaxie-woo' ) )
		);
		PixfortControls::palette_control( $this, 'fill_track_color', __( 'Track color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-gift-fill-track', 'background-color' );
		PixfortControls::palette_control( $this, 'fill_bar_color', __( 'Bar color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-gift-fill-bar', 'background-color' );
		$this->add_responsive_control(
			'fill_height',
			array(
				'label'      => __( 'Height', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 2, 'max' => 24 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 6 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-gift-fill-track' => 'height: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->heading( 'room_heading', __( '"Cabe mais…" line', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'room', array( 'size' => 'text-xs', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-room', 'text', array( 'inline', 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section(
			'row_style_section',
			array( 'label' => __( 'Ribbons and cards', 'galaxie-woo' ) )
		);
		$this->heading( 'row_name_heading', __( 'Name', 'galaxie-woo' ), false );
		PixfortControls::text( $this, 'row_name', array( 'size' => 'text-sm', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-row-name', 'text', array( 'inline', 'position' ) );
		$this->heading( 'row_price_heading', __( 'Price', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'row_price', array( 'size' => 'text-xs', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-row-price', 'text', array( 'inline', 'position' ) );
		$this->add_responsive_control(
			'row_image_size',
			array(
				'label'      => __( 'Picture size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 120 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 40 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-gift-thumb' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
				'separator'  => 'before',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'stepper_style_section',
			array( 'label' => __( 'Quantity', 'galaxie-woo' ) )
		);
		PixfortControls::quantity( $this, 'stepper', '{{WRAPPER}} .galaxie-gift-stepper', '{{WRAPPER}} .galaxie-gift-stepper .qty', array(), array(), '{{WRAPPER}} .galaxie-gift-stepper-wrap' );
		$this->end_controls_section();

		$this->start_controls_section(
			'message_style_section',
			array( 'label' => __( 'Card message', 'galaxie-woo' ) )
		);
		PixfortControls::palette_control( $this, 'message_text_color', __( 'Text color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-gift-message-input', 'color' );
		PixfortControls::palette_control( $this, 'message_bg_color', __( 'Background color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-gift-message-input', 'background-color' );
		PixfortControls::palette_control( $this, 'message_border_color', __( 'Border color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-gift-message-input', 'border-color', array(), ' border-style: solid; border-width: 1px;' );
		$this->add_responsive_control(
			'message_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 30 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-gift-message-input' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->heading( 'message_count_heading', __( 'Character count', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'message_count', array( 'size' => 'text-xs', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-message-count', 'text', array( 'inline', 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section(
			'total_style_section',
			array( 'label' => __( 'Total and messages', 'galaxie-woo' ) )
		);
		$this->heading( 'total_label_heading', __( 'Total label', 'galaxie-woo' ), false );
		PixfortControls::text( $this, 'total_label', array( 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-total-label', 'text', array( 'inline', 'position' ) );
		$this->heading( 'total_amount_heading', __( 'Total amount', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'total_amount', array( 'bold' => 'font-weight-bold' ), array(), '{{WRAPPER}} .galaxie-gift-total-amount', 'text', array( 'inline', 'position' ) );
		$this->heading( 'notice_heading', __( 'Loading, hints and errors', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'notice', array( 'size' => 'text-sm', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-gift-notice', 'text', array( 'inline', 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section(
			'arrange_section',
			array( 'label' => __( '"Organizar automaticamente" button', 'galaxie-woo' ) )
		);
		PixfortControls::button( $this, 'arrange', array( 'text' => __( 'Organizar automaticamente', 'galaxie-woo' ), 'style' => 'outline', 'color' => 'primary', 'size' => 'btn-sm' ) );
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

		$this->add_responsive_control(
			'group_gap',
			array(
				'label'      => __( 'Gap inside a gift card', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 12 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-gift-group' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'builder_max_height',
			array(
				'label'       => __( 'Scroll the gifts after', 'galaxie-woo' ),
				'description' => __( 'Keeps the buttons in view in a tall popup. Empty: no scrolling.', 'galaxie-woo' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'vh' ),
				'range'       => array( 'px' => array( 'min' => 200, 'max' => 1200 ), 'vh' => array( 'min' => 20, 'max' => 90 ) ),
				'selectors'   => array( '{{WRAPPER}} .galaxie-gift-scroll' => 'max-height: {{SIZE}}{{UNIT}}; overflow-y: auto;' ),
			)
		);

		$this->end_controls_section();
	}

	private function heading( string $id, string $label, bool $separator = true ): void {
		$this->add_control(
			$id,
			array(
				'label'     => $label,
				'type'      => Controls_Manager::HEADING,
				'separator' => $separator ? 'before' : 'none',
			)
		);
	}

	protected function render(): void {
		// The popup is usually on a product page that loaded the bundle already;
		// asking again costs nothing and covers a popup placed anywhere else.
		Assets::enqueue();

		$settings = $this->get_settings_for_display();
		$sample   = self::is_editing() && 'yes' === ( $settings['builder_preview'] ?? 'yes' );
		$title    = trim( (string) ( $settings['title_text'] ?? '' ) );
		$empty    = trim( (string) ( $settings['empty_text'] ?? '' ) );
		$texts    = array();

		foreach ( self::texts() as $key => $text ) {
			$texts[ substr( $key, 0, -5 ) ] = (string) ( $settings[ $key ] ?? $text[1] );
		}

		printf(
			'<div class="galaxie-gift-builder" data-galaxie-gift-builder data-texts="%1$s"%2$s>',
			esc_attr( (string) wp_json_encode( $texts ) ),
			$sample ? ' data-sample="1"' : ''
		);

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

		$this->render_candle( $settings, $sample );

		$notice = esc_attr( PixfortControls::text_classes( $settings, 'notice' ) );
		printf( '<p class="galaxie-gift-notice %1$s" data-gift-loading hidden>%2$s</p>', $notice, esc_html( $texts['loading'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		echo '<div class="galaxie-gift-scroll">';

		// Where the candle goes: a new gift, or one already in the cart.
		printf( '<div class="galaxie-gift-part galaxie-gift-modes" data-gift-modes%s>', $sample ? '' : ' hidden' );
		$this->render_heading( $settings, $texts['mode_heading'] );
		echo '<div class="galaxie-gift-list" data-gift-mode-list>';
		if ( $sample ) {
			$this->render_mode( $settings, $texts['mode_new'], '', true );
			$this->render_mode( $settings, self::fill_text( $texts['mode_extend'], self::fill_text( $texts['group_title'], '1' ) ), self::fill_text( $texts['room'], '2 × 50g' ), false );
		}
		echo '</div></div>';

		// Gift candles already in the cart but in no gift, to fold into this one.
		echo '<div class="galaxie-gift-part galaxie-gift-loose-part" data-gift-loose hidden>';
		$this->render_heading( $settings, $texts['loose_heading'] );
		echo '<div class="galaxie-gift-list" data-gift-loose-list></div></div>';

		printf( '<div class="galaxie-gift-part galaxie-gift-arrange" data-gift-arrange-wrap%s>', $sample ? '' : ' hidden' );
		echo '<button type="button" class="galaxie-buybox-btn galaxie-gift-arrange-btn" data-gift-arrange>';
		echo PixfortControls::render_button( $settings, 'arrange', (string) ( $settings['arrange_text'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
		echo '</button>';
		printf( '<span class="galaxie-gift-notice %1$s">%2$s</span>', $notice, esc_html( $texts['arrange_hint'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '</div>';

		echo '<div class="galaxie-gift-groups" data-gift-groups>';
		if ( $sample ) {
			$this->render_sample_group( $settings, $texts );
		}
		echo '</div>';

		echo '</div>';

		printf( '<p class="galaxie-gift-notice galaxie-gift-error %1$s" data-gift-error role="alert" hidden></p>', $notice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		printf( '<div class="galaxie-gift-total" data-gift-total%s>', $sample ? '' : ' hidden' );
		printf( '<span class="galaxie-gift-total-label %1$s">%2$s</span>', esc_attr( PixfortControls::text_classes( $settings, 'total_label' ) ), esc_html( $texts['total'] ) );
		printf( '<span class="galaxie-gift-total-amount %1$s" data-slot="total">%2$s</span>', esc_attr( PixfortControls::text_classes( $settings, 'total_amount' ) ), $sample ? esc_html( self::money( 214.7 ) ) : '' );
		echo '</div>';

		echo '<div class="galaxie-gift-builder-actions">';
		foreach ( array( 'confirm', 'bypass' ) as $action ) {
			printf( '<button type="button" class="galaxie-buybox-btn galaxie-gift-builder-%1$s" data-galaxie-gift-action="%1$s">', esc_attr( $action ) );
			echo PixfortControls::render_button( $settings, $action, (string) ( $settings[ $action . '_text' ] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
			echo '</button>';
		}
		echo '</div>';

		if ( ! $sample ) {
			$this->render_templates( $settings, $texts );
		}

		echo '</div>';
	}

	// ------------------------------------------------------------------ parts

	/** @param array<string,mixed> $settings */
	private function render_candle( array $settings, bool $sample ): void {
		printf(
			'<div class="galaxie-gift-builder-item %1$s" data-gift-item%2$s>',
			esc_attr( PixfortControls::surface_classes( $settings, 'item_card' ) ),
			$sample ? '' : ' hidden'
		);

		// No src until there is a picture: an empty one is still a request.
		$image = $sample ? self::placeholder() : '';
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
			$sample ? esc_html__( '190g · 2 unidades', 'galaxie-woo' ) : ''
		);
		echo '</span></div>';
	}

	/** @param array<string,mixed> $settings */
	private function render_heading( array $settings, string $text ): void {
		printf( '<span class="galaxie-gift-heading %1$s">%2$s</span>', esc_attr( PixfortControls::text_classes( $settings, 'section_title' ) ), esc_html( $text ) );
	}

	/** @param array<string,mixed> $settings */
	private function render_mode( array $settings, string $label, string $meta, bool $checked ): void {
		printf( '<label class="galaxie-gift-mode %s">', esc_attr( PixfortControls::surface_classes( $settings, 'mode' ) ) );
		printf( '<input type="radio" name="galaxie-gift-mode" data-slot="input"%s />', $checked ? ' checked' : '' );
		echo '<span class="galaxie-gift-mode-text">';
		printf( '<span class="galaxie-gift-mode-label %1$s" data-slot="label">%2$s</span>', esc_attr( PixfortControls::text_classes( $settings, 'mode_label' ) ), esc_html( $label ) );
		printf( '<span class="galaxie-gift-mode-meta %1$s" data-slot="meta"%2$s>%3$s</span>', esc_attr( PixfortControls::text_classes( $settings, 'mode_meta' ) ), '' === $meta ? ' hidden' : '', esc_html( $meta ) );
		echo '</span></label>';
	}

	/** @param array<string,mixed> $settings */
	private function render_loose( array $settings ): void {
		printf( '<label class="galaxie-gift-mode galaxie-gift-loose %s">', esc_attr( PixfortControls::surface_classes( $settings, 'mode' ) ) );
		echo '<input type="checkbox" data-slot="input" />';
		printf( '<span class="galaxie-gift-mode-label %s" data-slot="label"></span>', esc_attr( PixfortControls::text_classes( $settings, 'mode_label' ) ) );
		echo '</label>';
	}

	/**
	 * One gift: its candles, the box choice and fill bar, ribbons, cards.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $data     title, candles, existing, boxes (html), fill, room, ribbons (html), cards (html).
	 * @param array<string,string> $texts
	 */
	private function render_group( array $settings, array $data, array $texts ): void {
		$text = static fn( string $prefix ): string => esc_attr( PixfortControls::text_classes( $settings, $prefix ) );

		printf( '<div class="galaxie-gift-group %s" data-gift-group>', esc_attr( PixfortControls::surface_classes( $settings, 'group' ) ) );

		echo '<div class="galaxie-gift-group-head">';
		printf( '<span class="galaxie-gift-group-title %1$s" data-slot="title">%2$s</span>', $text( 'group_title' ), esc_html( (string) ( $data['title'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $text.
		printf( '<span class="galaxie-gift-group-candles %1$s" data-slot="candles">%2$s</span>', $text( 'group_candles' ), esc_html( (string) ( $data['candles'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $text.
		echo '</div>';

		$existing = (string) ( $data['existing'] ?? '' );
		printf( '<span class="galaxie-gift-existing %1$s" data-slot="existing"%2$s>%3$s</span>', $text( 'existing' ), '' === $existing ? ' hidden' : '', esc_html( $existing ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $text.

		echo '<div class="galaxie-gift-part">';
		$this->render_heading( $settings, $texts['box_heading'] );
		echo '<div class="galaxie-gift-boxes" data-slot="boxes">' . ( $data['boxes'] ?? '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup from render_box(), escaped there.
		$fill = max( 0, min( 100, (int) ( $data['fill'] ?? 0 ) ) );
		printf( '<div class="galaxie-gift-fill" data-slot="fill-wrap"%s>', isset( $data['fill'] ) ? '' : ' hidden' );
		printf( '<div class="galaxie-gift-fill-track"><div class="galaxie-gift-fill-bar" data-slot="fill" style="width:%d%%"></div></div>', (int) $fill );
		printf( '<span class="galaxie-gift-room %1$s" data-slot="room">%2$s</span>', $text( 'room' ), esc_html( (string) ( $data['room'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $text.
		echo '</div></div>';

		foreach ( array( 'ribbons', 'cards' ) as $kind ) {
			printf( '<div class="galaxie-gift-part" data-slot="%s-wrap">', esc_attr( $kind ) );
			$this->render_heading( $settings, $texts[ $kind . '_heading' ] );
			printf( '<div class="galaxie-gift-rows" data-slot="%1$s">%2$s</div>', esc_attr( $kind ), $data[ $kind ] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup from render_row(), escaped there.
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $data     name, price, image, pressed.
	 */
	private function render_box( array $settings, array $data = array() ): string {
		$image = (string) ( $data['image'] ?? '' );

		return sprintf(
			'<button type="button" class="galaxie-gift-box %1$s" data-gift-box aria-pressed="%2$s"><img class="galaxie-gift-thumb" alt="" data-slot="image"%3$s /><span class="galaxie-gift-box-text"><span class="galaxie-gift-box-name %4$s" data-slot="name">%5$s</span><span class="galaxie-gift-box-price %6$s" data-slot="price">%7$s</span></span></button>',
			esc_attr( PixfortControls::surface_classes( $settings, 'box_option' ) ),
			! empty( $data['pressed'] ) ? 'true' : 'false',
			'' !== $image ? ' src="' . esc_url( $image ) . '"' : ' hidden',
			esc_attr( PixfortControls::text_classes( $settings, 'box_name' ) ),
			esc_html( (string) ( $data['name'] ?? '' ) ),
			esc_attr( PixfortControls::text_classes( $settings, 'box_price' ) ),
			esc_html( (string) ( $data['price'] ?? '' ) )
		);
	}

	/**
	 * A ribbon or card with its quantity; a card row carries its messages below.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $data     name, price, image, quantity, messages (html).
	 */
	private function render_row( array $settings, array $data = array() ): string {
		$image    = (string) ( $data['image'] ?? '' );
		$quantity = (int) ( $data['quantity'] ?? 0 );

		return sprintf(
			'<div class="galaxie-gift-row" data-gift-row><div class="galaxie-gift-row-main"><img class="galaxie-gift-thumb" alt="" data-slot="image"%1$s /><span class="galaxie-gift-row-text"><span class="galaxie-gift-row-name %2$s" data-slot="name">%3$s</span><span class="galaxie-gift-row-price %4$s" data-slot="price">%5$s</span></span>%6$s</div><div class="galaxie-gift-messages" data-slot="messages">%7$s</div></div>',
			'' !== $image ? ' src="' . esc_url( $image ) . '"' : ' hidden',
			esc_attr( PixfortControls::text_classes( $settings, 'row_name' ) ),
			esc_html( (string) ( $data['name'] ?? '' ) ),
			esc_attr( PixfortControls::text_classes( $settings, 'row_price' ) ),
			esc_html( (string) ( $data['price'] ?? '' ) ),
			$this->stepper( $settings, $quantity ),
			$data['messages'] ?? ''
		);
	}

	/**
	 * The quantity for one accessory. The theme's own quantity box classes, so
	 * it looks like the product page's; our own +/- classes, so pixfort's
	 * delegated `.quantity a.minus` handler does not step it a second time.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function stepper( array $settings, int $quantity ): string {
		$field = 'select' === ( $settings['stepper_style'] ?? 'input' )
			? self::stepper_select( $settings, $quantity )
			: sprintf(
				'<button type="button" class="galaxie-gift-step text-body-default" data-step="-1" aria-label="%1$s">%2$s</button><input type="number" class="qty text form-control shadow-0" min="0" step="1" value="%3$d" inputmode="numeric" data-slot="qty" /><button type="button" class="galaxie-gift-step text-body-default" data-step="1" aria-label="%4$s">%5$s</button>',
				esc_attr__( 'Menos', 'galaxie-woo' ),
				self::icon( 'Line/pixfort-icon-minus-1', '−' ),
				$quantity,
				esc_attr__( 'Mais', 'galaxie-woo' ),
				self::icon( 'Line/pixfort-icon-plus-1', '+' )
			);

		return '<span class="galaxie-gift-stepper-wrap"><span class="galaxie-gift-stepper quantity pix-px-10 pix-base-background rounded-lg shadow-sm d-inline-flex justify-content-between">' . $field . '</span></span>';
	}

	/** @param array<string,mixed> $settings */
	private static function stepper_select( array $settings, int $quantity ): string {
		$max = max( 1, min( 50, (int) ( $settings['stepper_max'] ?? 5 ) ) );
		$out = '<select class="qty" data-slot="qty">';

		for ( $i = 0; $i <= $max; $i++ ) {
			$out .= sprintf( '<option value="%1$d"%2$s>%1$d</option>', $i, selected( $i, $quantity, false ) );
		}

		return $out . '</select>';
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_message( array $settings, string $message = '', string $count = '' ): string {
		return sprintf(
			'<div class="galaxie-gift-message" data-gift-message><textarea class="galaxie-gift-message-input" rows="2" data-slot="input" placeholder="%1$s">%2$s</textarea><span class="galaxie-gift-message-count %3$s" data-slot="count">%4$s</span></div>',
			esc_attr( (string) ( $settings['message_placeholder_text'] ?? '' ) ),
			esc_textarea( $message ),
			esc_attr( PixfortControls::text_classes( $settings, 'message_count' ) ),
			esc_html( $count )
		);
	}

	/**
	 * @param array<string,mixed>  $settings
	 * @param array<string,string> $texts
	 */
	private function render_templates( array $settings, array $texts ): void {
		$templates = array(
			'mode'    => fn() => $this->render_mode( $settings, '', '', false ),
			'loose'   => fn() => $this->render_loose( $settings ),
			'group'   => fn() => $this->render_group( $settings, array(), $texts ),
			'box'     => fn() => print( $this->render_box( $settings ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_box().
			'row'     => fn() => print( $this->render_row( $settings ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_row().
			'message' => fn() => print( $this->render_message( $settings ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_message().
		);

		foreach ( $templates as $name => $render ) {
			printf( '<template data-gift-tpl="%s">', esc_attr( $name ) );
			$render();
			echo '</template>';
		}
	}

	/**
	 * The editor's sample: two 190g candles, the smaller box chosen, a ribbon
	 * and a card with a message.
	 *
	 * @param array<string,mixed>  $settings
	 * @param array<string,string> $texts
	 */
	private function render_sample_group( array $settings, array $texts ): void {
		$image = self::placeholder();
		$max   = (int) Module::setting( 'card_message_max' );
		$note  = __( 'Feliz aniversário! Com carinho.', 'galaxie-woo' );

		$boxes = $this->render_box( $settings, array( 'name' => $texts['no_box'], 'price' => '' ) )
			. $this->render_box( $settings, array( 'name' => __( 'Caixa kraft M', 'galaxie-woo' ), 'price' => self::money( 24.9 ), 'image' => $image, 'pressed' => true ) )
			. $this->render_box( $settings, array( 'name' => __( 'Caixa kraft G', 'galaxie-woo' ), 'price' => self::money( 34.9 ), 'image' => $image ) );

		$ribbons = $this->render_row( $settings, array( 'name' => __( 'Fita de cetim', 'galaxie-woo' ), 'price' => self::money( 6.9 ), 'image' => $image, 'quantity' => 1 ) );
		$cards   = $this->render_row(
			$settings,
			array(
				'name'     => __( 'Cartão ilustrado', 'galaxie-woo' ),
				'price'    => self::money( 9.9 ),
				'image'    => $image,
				'quantity' => 1,
				'messages' => $this->render_message( $settings, $note, GiftGroups::message_length( $note ) . '/' . $max ),
			)
		);

		$this->render_group(
			$settings,
			array(
				'title'   => self::fill_text( $texts['group_title'], '1' ),
				'candles' => __( '2 × Vela aromática 190g', 'galaxie-woo' ),
				'boxes'   => $boxes,
				'fill'    => 67,
				'room'    => self::fill_text( $texts['room'], '1 × 190g' ),
				'ribbons' => $ribbons,
				'cards'   => $cards,
			),
			$texts
		);
	}

	/**
	 * A merchant's text with its one placeholder filled. Not sprintf(): a text
	 * reading "100% natural" would stop the editor preview.
	 */
	private static function fill_text( string $text, string $value ): string {
		return (string) preg_replace_callback( '/%(?:1\$)?[sd]/', static fn(): string => $value, $text, 1 );
	}

	private static function money( float $amount ): string {
		return function_exists( 'wc_price' ) ? html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ) : number_format( $amount, 2, ',', '.' );
	}

	private static function placeholder(): string {
		return function_exists( 'wc_placeholder_img_src' ) ? (string) wc_placeholder_img_src( 'woocommerce_thumbnail' ) : '';
	}

	/** pixfort's icon when the theme has it, the character otherwise. */
	private static function icon( string $name, string $fallback ): string {
		if ( PixfortControls::available() && isset( \PixfortCore::instance()->icons ) && method_exists( \PixfortCore::instance()->icons, 'getIcon' ) ) {
			return (string) \PixfortCore::instance()->icons->getIcon( $name, 20, 'align-self-center qty-icon' );
		}

		return esc_html( $fallback );
	}

	/** True inside the Elementor editor's canvas. */
	private static function is_editing(): bool {
		return class_exists( '\\Elementor\\Plugin' )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}

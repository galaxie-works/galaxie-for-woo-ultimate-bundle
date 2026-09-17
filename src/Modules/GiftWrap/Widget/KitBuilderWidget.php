<?php
/**
 * "Galaxie Kit Builder" Elementor widget — the inside of the kit popup.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Modules\GiftWrap\Builder;
use Galaxie\Woo\Modules\GiftWrap\Kit\Kits;
use Galaxie\Woo\Modules\GiftWrap\Kit\WooCatalog;
use Galaxie\Woo\Modules\GiftWrap\Module;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\GiftGroups;
use Galaxie\Woo\Support\GiftKit;
use Galaxie\Woo\Support\GiftPacking;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * Placed inside the pixfort popup named in wp-admin → Galaxie → Gift Wrap
 * ("Popup do kit"). It replaces the Galaxie Gift Builder and keeps its
 * Elementor name (`galaxie-gift-builder`), so a popup that held the old widget
 * now holds this one (with this widget's defaults).
 *
 * SCREENS. Welcome → 1 Nome → 2 Caixa → 3 Cartão → 4 "Continue escolhendo",
 * and the kit summary. All six are printed, hidden; kit-builder.ts shows the
 * one that fits (the summary when a kit is open, the name step when a product
 * page starts one, the welcome otherwise) and fills them from the kit
 * endpoint. Nothing about the visitor's kit is printed: the popup is part of
 * cached pages.
 *
 * REPEATED PARTS (a box, a card choice, a candle line) are `<template>`s that
 * already carry the pixfort classes the style controls chose; the script
 * clones them and fills their `data-slot`s.
 *
 * CONTROLS. Content has one section per screen with its texts and icon, and
 * "Tela mostrada no editor" at the top: the editor draws that screen from the
 * store's own products (a real candle, the boxes it fits, the card for the
 * box). Style has shared sections — step indicator, titles and text, box
 * cards, fields, fill bar, the Primary / Secondary / Danger buttons and the
 * welcome image — one control per property: texts live in Content, looks in
 * Style.
 */
final class KitBuilderWidget extends Widget_Base {

	private const SCREENS = array( 'welcome', 'name', 'box', 'card', 'continue', 'summary' );

	public function get_name(): string {
		// The Gift Builder's name: saved popups keep their widget.
		return 'galaxie-gift-builder';
	}

	public function get_title(): string {
		return __( 'Galaxie Kit Builder', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-cart-medium';
	}

	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/**
	 * Texts by screen: key => [ label, default ]. Placeholders the script fills:
	 * {kit}, {combos}, {room}, {preço}, {candles}, {n}.
	 *
	 * @return array<string, array<string, array{0:string, 1:string}>>
	 */
	private static function texts(): array {
		return array(
			'general'  => array(
				'starting'   => array( __( 'Started from a product ({candles})', 'galaxie-woo' ), __( 'Começando com: {candles}', 'galaxie-woo' ) ),
				'back'       => array( __( 'Back button', 'galaxie-woo' ), __( 'Voltar', 'galaxie-woo' ) ),
				'next'       => array( __( 'Next button', 'galaxie-woo' ), __( 'Próximo', 'galaxie-woo' ) ),
				'loading'    => array( __( 'Loading', 'galaxie-woo' ), __( 'Carregando…', 'galaxie-woo' ) ),
				'error'      => array( __( 'Could not load', 'galaxie-woo' ), __( 'Não foi possível carregar o kit. Tente de novo.', 'galaxie-woo' ) ),
				'step_name'  => array( __( 'Step 1 label', 'galaxie-woo' ), __( 'Nome', 'galaxie-woo' ) ),
				'step_box'   => array( __( 'Step 2 label', 'galaxie-woo' ), __( 'Caixa', 'galaxie-woo' ) ),
				'step_card'  => array( __( 'Step 3 label', 'galaxie-woo' ), __( 'Cartão', 'galaxie-woo' ) ),
				'step_more'  => array( __( 'Step 4 label', 'galaxie-woo' ), __( 'Velas', 'galaxie-woo' ) ),
			),
			'welcome'  => array(
				'welcome_title'  => array( __( 'Title', 'galaxie-woo' ), __( 'Monte um kit de presente', 'galaxie-woo' ) ),
				'welcome_text'   => array( __( 'Text', 'galaxie-woo' ), __( 'Escolha a caixa, um cartão com mensagem e as velas. Nós montamos e enviamos prontinho.', 'galaxie-woo' ) ),
				'welcome_button' => array( __( 'Button', 'galaxie-woo' ), __( 'Montar um kit', 'galaxie-woo' ) ),
			),
			'name'     => array(
				'name_title'       => array( __( 'Title', 'galaxie-woo' ), __( 'Como vamos chamar este kit?', 'galaxie-woo' ) ),
				'name_text'        => array( __( 'Text', 'galaxie-woo' ), __( 'O nome ajuda você a encontrar o kit no carrinho. Se deixar em branco, chamamos de {kit}.', 'galaxie-woo' ) ),
				'name_label'       => array( __( 'Field label', 'galaxie-woo' ), __( 'Nome do kit', 'galaxie-woo' ) ),
				'name_placeholder' => array( __( 'Field placeholder', 'galaxie-woo' ), __( 'Ex.: Presente para Stella', 'galaxie-woo' ) ),
			),
			'box'      => array(
				'box_title'   => array( __( 'Title', 'galaxie-woo' ), __( 'Escolha a caixa', 'galaxie-woo' ) ),
				'box_text'    => array( __( 'Text', 'galaxie-woo' ), __( 'Cada caixa leva um tanto de velas. Você completa o kit depois, na loja.', 'galaxie-woo' ) ),
				'box_reason'  => array( __( 'A box that cannot hold the candles ({candles})', 'galaxie-woo' ), __( 'Não comporta {candles}', 'galaxie-woo' ) ),
				'box_sold'    => array( __( 'A sold-out box', 'galaxie-woo' ), __( 'Esgotada', 'galaxie-woo' ) ),
				'box_none'    => array( __( 'No box holds the candles ({candles})', 'galaxie-woo' ), __( 'Nenhuma caixa comporta {candles}. Diminua a quantidade na página do produto e tente de novo.', 'galaxie-woo' ) ),
				'box_empty'   => array( __( 'No boxes on offer', 'galaxie-woo' ), __( 'Nenhuma caixa disponível no momento.', 'galaxie-woo' ) ),
			),
			'card'     => array(
				'card_title'       => array( __( 'Title', 'galaxie-woo' ), __( 'Quer um cartão?', 'galaxie-woo' ) ),
				'card_text'        => array( __( 'Text', 'galaxie-woo' ), __( 'Escrevemos sua mensagem num cartão do tamanho da caixa.', 'galaxie-woo' ) ),
				'card_add'         => array( __( 'Add a card ({preço}, {card})', 'galaxie-woo' ), __( 'Adicionar cartão ({preço})', 'galaxie-woo' ) ),
				'card_skip'        => array( __( 'No card', 'galaxie-woo' ), __( 'Seguir sem cartão', 'galaxie-woo' ) ),
				'card_label'       => array( __( 'Message label', 'galaxie-woo' ), __( 'Mensagem do cartão', 'galaxie-woo' ) ),
				'card_placeholder' => array( __( 'Message placeholder', 'galaxie-woo' ), __( 'Escreva sua mensagem (opcional)', 'galaxie-woo' ) ),
				'card_next'        => array( __( 'Next button on this step', 'galaxie-woo' ), __( 'Criar kit', 'galaxie-woo' ) ),
			),
			'continue' => array(
				'continue_title'  => array( __( 'Title', 'galaxie-woo' ), __( 'Kit {kit} criado!', 'galaxie-woo' ) ),
				'continue_text'   => array( __( 'Text ({combos})', 'galaxie-woo' ), __( 'Você ainda pode adicionar {combos}. Continue pesquisando nossos produtos e adicionando a este kit.', 'galaxie-woo' ) ),
				'continue_full'   => array( __( 'Text when the box is already full', 'galaxie-woo' ), __( 'A caixa já está completa. Veja o kit e adicione ao carrinho.', 'galaxie-woo' ) ),
				'continue_button' => array( __( 'Keep choosing button', 'galaxie-woo' ), __( 'Continuar escolhendo', 'galaxie-woo' ) ),
				'continue_view'   => array( __( 'See the kit button', 'galaxie-woo' ), __( 'Ver kit', 'galaxie-woo' ) ),
			),
			'summary'  => array(
				'summary_title'    => array( __( 'Title', 'galaxie-woo' ), __( 'Seu kit', 'galaxie-woo' ) ),
				'summary_name'     => array( __( 'Name label', 'galaxie-woo' ), __( 'Nome', 'galaxie-woo' ) ),
				'summary_box'      => array( __( 'Box label', 'galaxie-woo' ), __( 'Caixa', 'galaxie-woo' ) ),
				'summary_card'     => array( __( 'Card label', 'galaxie-woo' ), __( 'Cartão', 'galaxie-woo' ) ),
				'summary_no_card'  => array( __( 'No card', 'galaxie-woo' ), __( 'Sem cartão', 'galaxie-woo' ) ),
				'summary_change'   => array( __( '"Trocar" link', 'galaxie-woo' ), __( 'trocar', 'galaxie-woo' ) ),
				'summary_message'  => array( __( 'Message label', 'galaxie-woo' ), __( 'Mensagem', 'galaxie-woo' ) ),
				'summary_no_msg'   => array( __( 'Card without a message', 'galaxie-woo' ), __( 'Cartão sem mensagem', 'galaxie-woo' ) ),
				'summary_candles'  => array( __( 'Candles label', 'galaxie-woo' ), __( 'Velas', 'galaxie-woo' ) ),
				'summary_empty'    => array( __( 'No candles yet', 'galaxie-woo' ), __( 'Nenhuma vela ainda. Escolha velas na loja e use "Adicionar ao kit".', 'galaxie-woo' ) ),
				'summary_remove'   => array( __( 'Remove a candle', 'galaxie-woo' ), __( 'Remover', 'galaxie-woo' ) ),
				'summary_total'    => array( __( 'Total label', 'galaxie-woo' ), __( 'Total do kit', 'galaxie-woo' ) ),
				'action_cart'      => array( __( 'Add to cart button', 'galaxie-woo' ), __( 'Adicionar kit ao carrinho', 'galaxie-woo' ) ),
				'action_continue'  => array( __( 'Keep choosing button', 'galaxie-woo' ), __( 'Continuar escolhendo', 'galaxie-woo' ) ),
				'action_new'       => array( __( 'Add and start another button', 'galaxie-woo' ), __( 'Adicionar ao carrinho e começar um novo', 'galaxie-woo' ) ),
				'action_discard'   => array( __( 'Discard button', 'galaxie-woo' ), __( 'Descartar kit', 'galaxie-woo' ) ),
				'discard_confirm'  => array( __( 'Discard question ({kit})', 'galaxie-woo' ), __( 'Descartar o kit {kit}? Isso não pode ser desfeito.', 'galaxie-woo' ) ),
				'need_candle'      => array( __( 'Adding with no candle', 'galaxie-woo' ), __( 'Adicione pelo menos uma vela ao kit.', 'galaxie-woo' ) ),
			),
		);
	}

	/** Icons by screen, shown before the title. */
	private const ICONS = array(
		'welcome'  => 'Line/pixfort-icon-gift-1',
		'name'     => '',
		'box'      => '',
		'card'     => '',
		'continue' => '',
		'summary'  => '',
	);

	protected function register_controls(): void {
		$this->start_controls_section( 'kit_editor_section', array( 'label' => __( 'Kit Builder', 'galaxie-woo' ) ) );

		$this->add_control(
			'kit_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Put this widget inside the pixfort popup named in wp-admin → Galaxie → Gift Wrap ("Popup do kit"). It shows the kit being built, or the steps to start one. Boxes and cards come from the categories set there.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'editor_screen',
			array(
				'label'   => __( 'Tela mostrada no editor', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'summary',
				'options' => array(
					'welcome'  => __( 'Welcome', 'galaxie-woo' ),
					'name'     => __( '1 · Name', 'galaxie-woo' ),
					'box'      => __( '2 · Box', 'galaxie-woo' ),
					'card'     => __( '3 · Card', 'galaxie-woo' ),
					'continue' => __( '4 · Keep choosing', 'galaxie-woo' ),
					'summary'  => __( 'Kit summary', 'galaxie-woo' ),
				),
			)
		);

		$this->end_controls_section();

		$labels = array(
			'general'  => __( 'Steps and messages', 'galaxie-woo' ),
			'welcome'  => __( 'Welcome', 'galaxie-woo' ),
			'name'     => __( '1 · Name', 'galaxie-woo' ),
			'box'      => __( '2 · Box', 'galaxie-woo' ),
			'card'     => __( '3 · Card', 'galaxie-woo' ),
			'continue' => __( '4 · Keep choosing', 'galaxie-woo' ),
			'summary'  => __( 'Kit summary', 'galaxie-woo' ),
		);

		foreach ( self::texts() as $screen => $texts ) {
			$this->start_controls_section( 'kit_' . $screen . '_section', array( 'label' => $labels[ $screen ] ) );

			if ( isset( self::ICONS[ $screen ] ) ) {
				PixfortControls::icon_select( $this, $screen . '_icon', __( 'Icon', 'galaxie-woo' ), self::ICONS[ $screen ] );
			}

			foreach ( $texts as $key => $text ) {
				$this->add_control(
					$key . '_text',
					array(
						'label'       => $text[0],
						'label_block' => true,
						'type'        => in_array( $key, array( 'welcome_text', 'continue_text', 'box_none', 'name_text' ), true ) ? Controls_Manager::TEXTAREA : Controls_Manager::TEXT,
						'default'     => $text[1],
					)
				);
			}

			$this->end_controls_section();
		}

		$this->register_style_controls();
	}

	private function register_style_controls(): void {
		$style = static fn( string $label ): array => array(
			'label' => $label,
			'tab'   => Controls_Manager::TAB_STYLE,
		);

		$this->start_controls_section( 'kit_steps_style', $style( __( 'Step indicator', 'galaxie-woo' ) ) );
		$this->add_control(
			'steps_show',
			array(
				'label'        => __( 'Show the steps (1•2•3•4)', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$shown = array( 'steps_show' => 'yes' );
		PixfortControls::palette_control( $this, 'steps_dot_bg', __( 'Dot', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-step-dot', 'background-color', $shown );
		PixfortControls::palette_control( $this, 'steps_dot_color', __( 'Dot number', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-step-dot', 'color', $shown );
		PixfortControls::palette_control( $this, 'steps_active_bg', __( 'Current and done dots', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-step.is-current .galaxie-kit-step-dot, {{WRAPPER}} .galaxie-kit-step.is-done .galaxie-kit-step-dot', 'background-color', $shown );
		PixfortControls::palette_control( $this, 'steps_active_color', __( 'Current and done numbers', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-step.is-current .galaxie-kit-step-dot, {{WRAPPER}} .galaxie-kit-step.is-done .galaxie-kit-step-dot', 'color', $shown );
		PixfortControls::text( $this, 'steps_label', array( 'size' => 'text-xs', 'bold' => '' ), $shown, '{{WRAPPER}} .galaxie-kit-step-label', 'text', array( 'inline', 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'kit_text_style', $style( __( 'Titles and text', 'galaxie-woo' ) ) );
		$this->heading( 'title_style_heading', __( 'Titles', 'galaxie-woo' ), false );
		PixfortControls::text( $this, 'title', array( 'size' => 'h5', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-kit-title', 'heading' );
		PixfortControls::icon_color( $this, 'title_icon_color', __( 'Icon color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-icon' );
		$this->heading( 'body_style_heading', __( 'Text', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'body', array( 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-kit-text', 'text', array( 'inline', 'position' ) );
		$this->heading( 'small_style_heading', __( 'Labels, hints and the room-left line', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'small', array( 'size' => 'text-sm', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-kit-small', 'text', array( 'inline', 'position' ) );
		PixfortControls::palette_control( $this, 'error_color', __( 'Error color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-error, {{WRAPPER}} .galaxie-kit-warning', 'color' );
		$this->end_controls_section();

		$this->start_controls_section( 'kit_box_style', $style( __( 'Box cards', 'galaxie-woo' ) ) );
		PixfortControls::surface( $this, 'box_card', '{{WRAPPER}} .galaxie-kit-choice' );
		PixfortControls::palette_control( $this, 'box_selected', __( 'Selected: border color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-choice[aria-pressed="true"]', 'border-color', array(), ' border-style: solid; border-width: 2px;' );
		$this->add_control(
			'box_disabled_opacity',
			array(
				'label'     => __( 'Unavailable: opacity', 'galaxie-woo' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array( 'px' => array( 'min' => 0.1, 'max' => 1, 'step' => 0.05 ) ),
				'default'   => array( 'size' => 0.5 ),
				'selectors' => array( '{{WRAPPER}} .galaxie-kit-choice[aria-disabled="true"]' => 'opacity: {{SIZE}};' ),
			)
		);
		$this->heading( 'box_name_heading', __( 'Name', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'box_name', array( 'bold' => 'font-weight-bold' ), array(), '{{WRAPPER}} .galaxie-kit-choice-name', 'text', array( 'inline', 'position' ) );
		$this->heading( 'box_meta_heading', __( 'Price, description and what it holds', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'box_meta', array( 'size' => 'text-sm', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-kit-choice-meta', 'text', array( 'inline', 'position' ) );
		$this->add_responsive_control(
			'box_image_size',
			array(
				'label'      => __( 'Picture size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 160 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 64 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-thumb' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
				'separator'  => 'before',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section( 'kit_field_style', $style( __( 'Name and message fields', 'galaxie-woo' ) ) );
		PixfortControls::palette_control( $this, 'field_text', __( 'Text color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-input', 'color' );
		PixfortControls::palette_control( $this, 'field_bg', __( 'Background color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-input', 'background-color' );
		PixfortControls::palette_control( $this, 'field_border', __( 'Border color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-input', 'border-color', array(), ' border-style: solid; border-width: 1px;' );
		$this->add_responsive_control(
			'field_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 30 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-input' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section( 'kit_fill_style', $style( __( 'Fill bar', 'galaxie-woo' ) ) );
		PixfortControls::palette_control( $this, 'fill_track', __( 'Track color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-fill-track', 'background-color' );
		PixfortControls::palette_control( $this, 'fill_bar', __( 'Bar color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-fill-bar', 'background-color' );
		PixfortControls::palette_control( $this, 'fill_bar_full', __( 'Bar color when full', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-fill.is-full .galaxie-kit-fill-bar', 'background-color' );
		$this->add_responsive_control(
			'fill_height',
			array(
				'label'      => __( 'Height', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 2, 'max' => 24 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 8 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-fill-track' => 'height: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_control(
			'confetti',
			array(
				'label'        => __( 'Confetti when the box fills', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$this->end_controls_section();

		$buttons = array(
			'primary'   => array( __( 'Primary buttons', 'galaxie-woo' ), array( 'color' => 'primary' ) ),
			'secondary' => array( __( 'Secondary buttons', 'galaxie-woo' ), array( 'style' => 'outline', 'color' => 'primary' ) ),
			'danger'    => array( __( 'Danger button', 'galaxie-woo' ), array( 'style' => 'link', 'color' => 'red' ) ),
		);

		foreach ( $buttons as $kind => $button ) {
			$this->start_controls_section( 'kit_btn_' . $kind . '_style', $style( $button[0] ) );
			PixfortControls::button( $this, 'kit_' . $kind, $button[1] + array( 'size' => 'md' ), array(), '{{WRAPPER}}', array( 'text' ) );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'kit_welcome_style', $style( __( 'Welcome image', 'galaxie-woo' ) ) );
		$this->add_control(
			'welcome_image',
			array(
				'label'   => __( 'Image', 'galaxie-woo' ),
				'type'    => Controls_Manager::MEDIA,
				'default' => array( 'url' => '' ),
			)
		);
		$this->add_responsive_control(
			'welcome_image_width',
			array(
				'label'      => __( 'Width', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array( 'px' => array( 'min' => 40, 'max' => 600 ) ),
				'default'    => array( 'unit' => '%', 'size' => 100 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-welcome-image' => 'width: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'welcome_image_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-welcome-image' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section( 'kit_layout_style', $style( __( 'Layout', 'galaxie-woo' ) ) );
		$this->add_responsive_control(
			'kit_gap',
			array(
				'label'      => __( 'Gap between parts', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 16 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-screen' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'kit_max_height',
			array(
				'label'       => __( 'Scroll the lists after', 'galaxie-woo' ),
				'description' => __( 'Keeps the buttons in view in a tall popup. Empty: no scrolling.', 'galaxie-woo' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'vh' ),
				'range'       => array( 'px' => array( 'min' => 150, 'max' => 1000 ), 'vh' => array( 'min' => 20, 'max' => 90 ) ),
				'selectors'   => array( '{{WRAPPER}} .galaxie-kit-scroll' => 'max-height: {{SIZE}}{{UNIT}}; overflow-y: auto;' ),
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

	// ------------------------------------------------------------------ render

	protected function render(): void {
		Assets::enqueue();
		Assets::enqueue_kit();

		$settings = $this->get_settings_for_display();
		$editing  = self::is_editing();
		$screen   = $editing ? (string) ( $settings['editor_screen'] ?? 'summary' ) : '';
		$screen   = in_array( $screen, self::SCREENS, true ) || '' === $screen ? $screen : 'summary';
		$texts    = array();

		foreach ( self::texts() as $group ) {
			foreach ( $group as $key => $text ) {
				$texts[ $key ] = (string) ( $settings[ $key . '_text' ] ?? $text[1] );
			}
		}

		$sample = $editing ? $this->sample( $texts ) : null;

		printf(
			'<div class="galaxie-kit-builder%1$s" data-galaxie-kit-builder data-texts="%2$s" data-confetti="%3$s"%4$s>',
			'yes' === ( $settings['steps_show'] ?? 'yes' ) ? '' : ' no-steps', // Nothing to hide then: the indicator is not printed.
			esc_attr( (string) wp_json_encode( $texts ) ),
			'yes' === ( $settings['confetti'] ?? 'yes' ) ? '1' : '0',
			$editing ? ' data-sample="' . esc_attr( $screen ) . '"' : ''
		);

		if ( 'yes' === ( $settings['steps_show'] ?? 'yes' ) ) {
			$this->render_steps( $settings, $texts, $screen, $editing );
		}

		printf(
			'<p class="galaxie-kit-starting galaxie-kit-small %1$s" data-kit-starting%2$s>%3$s</p>',
			esc_attr( PixfortControls::text_classes( $settings, 'small' ) ),
			in_array( $screen, array( 'name', 'box' ), true ) && $sample ? '' : ' hidden',
			$sample ? esc_html( GiftKit::fill( $texts['starting'], array( 'candles' => $sample['starting'] ) ) ) : ''
		);

		printf( '<p class="galaxie-kit-small galaxie-kit-loading %1$s" data-kit-loading hidden>%2$s</p>', esc_attr( PixfortControls::text_classes( $settings, 'small' ) ), esc_html( $texts['loading'] ) );

		foreach ( self::SCREENS as $name ) {
			printf( '<section class="galaxie-kit-screen galaxie-kit-screen--%1$s" data-kit-screen="%1$s"%2$s>', esc_attr( $name ), $screen === $name ? '' : ' hidden' );
			$this->{'render_' . $name}( $settings, $texts, $screen === $name ? $sample : null );
			echo '</section>';
		}

		printf( '<p class="galaxie-kit-error galaxie-kit-small %1$s" data-kit-error role="alert" hidden></p>', esc_attr( PixfortControls::text_classes( $settings, 'small' ) ) );

		if ( ! $editing ) {
			$this->render_templates( $settings, $texts );
		}

		echo '</div>';
	}

	/**
	 * The 1•2•3•4 indicator, printed only with "Show the steps" on.
	 *
	 * Live it starts hidden and kit-builder.ts shows it on the four stepper
	 * screens. In the editor it is always visible, whichever screen is drawn —
	 * on the welcome and the summary with no step marked current — so its style
	 * controls can be seen.
	 *
	 * @param array<string,mixed>  $settings
	 * @param array<string,string> $texts
	 */
	private function render_steps( array $settings, array $texts, string $screen, bool $editing ): void {
		$steps   = array( 'name' => 'step_name', 'box' => 'step_box', 'card' => 'step_card', 'continue' => 'step_more' );
		$current = array_search( $screen, array_keys( $steps ), true );

		printf( '<ol class="galaxie-kit-steps" data-kit-steps%s>', $editing || false !== $current ? '' : ' hidden' );

		$n = 0;

		foreach ( $steps as $name => $label ) {
			$class = false !== $current && $n < $current ? ' is-done' : ( $n === $current ? ' is-current' : '' );
			printf(
				'<li class="galaxie-kit-step%1$s" data-step="%2$s"><span class="galaxie-kit-step-dot">%3$d</span><span class="galaxie-kit-step-label %4$s">%5$s</span></li>',
				esc_attr( $class ),
				esc_attr( $name ),
				$n + 1,
				esc_attr( PixfortControls::text_classes( $settings, 'steps_label' ) ),
				esc_html( $texts[ $label ] )
			);
			++$n;
		}

		echo '</ol>';
	}

	/** Title (with the screen's icon) and text. @param array<string,mixed> $settings */
	private function head( array $settings, string $screen, string $title, string $text, bool $slot_text = false ): void {
		$icon = PixfortControls::icon_value( $settings, $screen . '_icon' );

		echo '<div class="galaxie-kit-head">';

		if ( '' !== $icon ) {
			echo '<span class="galaxie-kit-icon">' . self::icon( $icon ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own SVG.
		}

		printf( '<div class="galaxie-kit-title" data-slot="title">%s</div>', PixfortControls::render_text( $settings, 'title', esc_html( $title ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's element around escaped text.
		echo '</div>';

		if ( '' !== $text || $slot_text ) {
			printf( '<p class="galaxie-kit-text %1$s" data-slot="text">%2$s</p>', esc_attr( PixfortControls::text_classes( $settings, 'body' ) ), esc_html( $text ) );
		}
	}

	/**
	 * A button in one of the three looks.
	 *
	 * @param array<string,mixed> $settings
	 * @param string              $extra    More attributes, already escaped.
	 */
	private function button( array $settings, string $kind, string $action, string $text, string $extra = '' ): string {
		return sprintf(
			'<button type="button" class="galaxie-buybox-btn galaxie-kit-btn galaxie-kit-btn--%1$s" data-kit-action="%2$s" data-kit-variant="%1$s"%3$s>%4$s</button>',
			esc_attr( $kind ),
			esc_attr( $action ),
			$extra,
			PixfortControls::render_button( $settings, 'kit_' . $kind, $text )
		);
	}

	/** @param array<string,mixed> $settings */
	private function nav( array $settings, array $texts, string $next = '' ): void {
		echo '<div class="galaxie-kit-actions galaxie-kit-nav">';
		echo $this->button( $settings, 'secondary', 'back', $texts['back'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo $this->button( $settings, 'primary', 'next', '' !== $next ? $next : $texts['next'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</div>';
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_welcome( array $settings, array $texts, ?array $sample ): void {
		$image = (string) ( $settings['welcome_image']['url'] ?? '' );

		if ( '' !== $image ) {
			printf( '<img class="galaxie-kit-welcome-image" src="%s" alt="" />', esc_url( $image ) );
		}

		$this->head( $settings, 'welcome', $texts['welcome_title'], $texts['welcome_text'] );

		echo '<div class="galaxie-kit-actions">';
		echo $this->button( $settings, 'primary', 'begin', $texts['welcome_button'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</div>';
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_name( array $settings, array $texts, ?array $sample ): void {
		// Live, the hint waits for the script: the default name depends on the visitor's cart.
		$this->head( $settings, 'name', $texts['name_title'], $sample ? GiftKit::fill( $texts['name_text'], array( 'kit' => $sample['name'] ) ) : '', true );

		printf(
			'<label class="galaxie-kit-field"><span class="galaxie-kit-small %1$s">%2$s</span><input type="text" class="galaxie-kit-input" data-kit-name maxlength="%3$d" placeholder="%4$s" autocomplete="off" /></label>',
			esc_attr( PixfortControls::text_classes( $settings, 'small' ) ),
			esc_html( $texts['name_label'] ),
			(int) GiftKit::NAME_MAX,
			esc_attr( $texts['name_placeholder'] )
		);

		$this->nav( $settings, $texts );
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_box( array $settings, array $texts, ?array $sample ): void {
		$this->head( $settings, 'box', $texts['box_title'], $texts['box_text'] );

		echo '<div class="galaxie-kit-choices galaxie-kit-scroll" data-kit-boxes>';

		foreach ( $sample ? $sample['boxes'] : array() as $box ) {
			echo $this->choice( $settings, $box ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in choice().
		}

		echo '</div>';
		printf( '<p class="galaxie-kit-warning galaxie-kit-small %1$s" data-kit-box-none hidden></p>', esc_attr( PixfortControls::text_classes( $settings, 'small' ) ) );

		$this->nav( $settings, $texts );
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_card( array $settings, array $texts, ?array $sample ): void {
		$this->head( $settings, 'card', $texts['card_title'], $texts['card_text'] );

		echo '<div class="galaxie-kit-choices" data-kit-cards>';

		foreach ( $sample ? $sample['cards'] : array() as $card ) {
			echo $this->choice( $settings, $card ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in choice().
		}

		echo '</div>';

		$this->message_field( $settings, $texts, $sample ? $sample['message'] : '', ! $sample );
		$this->nav( $settings, $texts, $texts['card_next'] );
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function message_field( array $settings, array $texts, string $message, bool $hidden, string $slot = 'message' ): void {
		$max = (int) Module::setting( 'card_message_max' );

		printf(
			'<label class="galaxie-kit-field" data-kit-%1$s-wrap%2$s><span class="galaxie-kit-small %3$s">%4$s</span><textarea class="galaxie-kit-input" rows="3" data-kit-%1$s placeholder="%5$s">%6$s</textarea><span class="galaxie-kit-count galaxie-kit-small %3$s" data-kit-%1$s-count>%7$s</span></label>',
			esc_attr( $slot ),
			$hidden ? ' hidden' : '',
			esc_attr( PixfortControls::text_classes( $settings, 'small' ) ),
			esc_html( 'message' === $slot ? $texts['card_label'] : $texts['summary_message'] ),
			esc_attr( $texts['card_placeholder'] ),
			esc_textarea( $message ),
			esc_html( GiftGroups::message_length( $message ) . '/' . $max )
		);
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_continue( array $settings, array $texts, ?array $sample ): void {
		$title = GiftKit::fill( $texts['continue_title'], array( 'kit' => $sample ? $sample['name'] : '' ) );
		$text  = $sample ? GiftKit::fill( $texts['continue_text'], array( 'combos' => $sample['combos'], 'kit' => $sample['name'] ) ) : '';

		$this->head( $settings, 'continue', $title, $text, true );

		echo '<div class="galaxie-kit-actions">';
		echo $this->button( $settings, 'primary', 'continue', $texts['continue_button'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo $this->button( $settings, 'secondary', 'summary', $texts['continue_view'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</div>';
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_summary( array $settings, array $texts, ?array $sample ): void {
		$small = esc_attr( PixfortControls::text_classes( $settings, 'small' ) );
		$meta  = esc_attr( PixfortControls::text_classes( $settings, 'box_meta' ) );
		$name  = esc_attr( PixfortControls::text_classes( $settings, 'box_name' ) );

		$this->head( $settings, 'summary', $texts['summary_title'], '' );

		echo '<div class="galaxie-kit-scroll galaxie-kit-summary">';

		printf(
			'<label class="galaxie-kit-field"><span class="galaxie-kit-small %1$s">%2$s</span><input type="text" class="galaxie-kit-input" data-kit-summary-name maxlength="%3$d" placeholder="%4$s" value="%5$s" autocomplete="off" /></label>',
			$small, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			esc_html( $texts['summary_name'] ),
			(int) GiftKit::NAME_MAX,
			esc_attr( $texts['name_placeholder'] ),
			esc_attr( $sample ? $sample['name'] : '' )
		);

		foreach ( array( 'box' => 'change-box', 'card' => 'change-card' ) as $part => $action ) {
			$row = $sample ? $sample[ 'summary_' . $part ] : array(
				'name'  => '',
				'image' => '',
				'price' => '',
			);

			printf(
				'<div class="galaxie-kit-row galaxie-kit-row--%1$s" data-kit-summary-%1$s><img class="galaxie-kit-thumb" alt="" data-slot="image"%2$s /><span class="galaxie-kit-row-body"><span class="galaxie-kit-small %3$s">%4$s</span><span class="galaxie-kit-choice-name %5$s" data-slot="name">%6$s</span><span class="galaxie-kit-choice-meta %7$s" data-slot="price">%8$s</span></span><a href="#" class="galaxie-kit-link galaxie-kit-small %3$s" data-kit-action="%9$s">%10$s</a></div>',
				esc_attr( $part ),
				'' !== $row['image'] ? ' src="' . esc_url( $row['image'] ) . '"' : ' hidden',
				$small, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( $texts[ 'summary_' . $part ] ),
				$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( $row['name'] ),
				$meta, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( $row['price'] ),
				esc_attr( $action ),
				esc_html( $texts['summary_change'] )
			);
		}

		$this->message_field( $settings, $texts, $sample ? $sample['message'] : '', false, 'summary-message' );
		printf( '<p class="galaxie-kit-warning galaxie-kit-small %1$s" data-kit-warning hidden>%2$s</p>', $small, esc_html( $texts['summary_no_msg'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		printf( '<span class="galaxie-kit-small %1$s">%2$s</span>', $small, esc_html( $texts['summary_candles'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<div class="galaxie-kit-lines" data-kit-candles>';

		foreach ( $sample ? $sample['candles'] : array() as $candle ) {
			echo $this->candle_line( $settings, $texts, $candle ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in candle_line().
		}

		echo '</div>';
		printf( '<p class="galaxie-kit-text %1$s" data-kit-candles-empty hidden>%2$s</p>', esc_attr( PixfortControls::text_classes( $settings, 'body' ) ), esc_html( $texts['summary_empty'] ) );

		echo '</div>';

		$fill = $sample ? (int) $sample['fill'] : 0;
		$full = $sample && $sample['full'];

		printf( '<div class="galaxie-kit-fill%1$s" data-kit-fill>', $full ? ' is-full' : '' );
		printf( '<div class="galaxie-kit-fill-track"><div class="galaxie-kit-fill-bar" data-slot="bar" style="width:%d%%"></div></div>', (int) $fill );
		printf( '<span class="galaxie-kit-small %1$s" data-slot="room">%2$s</span>', $small, esc_html( $sample ? $sample['room'] : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '</div>';

		printf(
			'<div class="galaxie-kit-total"><span class="galaxie-kit-text %1$s">%2$s</span><strong class="galaxie-kit-text %1$s" data-kit-total>%3$s</strong></div>',
			esc_attr( PixfortControls::text_classes( $settings, 'body' ) ),
			esc_html( $texts['summary_total'] ),
			esc_html( $sample ? $sample['total'] : '' )
		);

		echo '<div class="galaxie-kit-actions galaxie-kit-summary-actions">';
		// The add button changes look once the box is full: both are printed.
		printf( '<span data-kit-when="full"%s>', $full ? '' : ' hidden' );
		echo $this->button( $settings, 'primary', 'to-cart', $texts['action_cart'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</span>';
		printf( '<span data-kit-when="room"%s>', $full ? ' hidden' : '' );
		echo $this->button( $settings, 'secondary', 'to-cart', $texts['action_cart'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</span>';
		echo $this->button( $settings, 'secondary', 'continue', $texts['action_continue'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo $this->button( $settings, 'secondary', 'to-cart-new', $texts['action_new'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo $this->button( $settings, 'danger', 'discard', $texts['action_discard'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</div>';
	}

	/**
	 * A box or card to choose.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $data     name, price, image, description, holds, reason, pressed, disabled.
	 */
	private function choice( array $settings, array $data = array() ): string {
		$image = (string) ( $data['image'] ?? '' );
		$meta  = esc_attr( PixfortControls::text_classes( $settings, 'box_meta' ) );
		$slot  = static function ( string $name, string $class = '' ) use ( $data, $meta ): string {
			$value = (string) ( $data[ $name ] ?? '' );

			return sprintf( '<span class="galaxie-kit-choice-meta %1$s %2$s" data-slot="%3$s"%4$s>%5$s</span>', $meta, esc_attr( $class ), esc_attr( $name ), '' === $value ? ' hidden' : '', esc_html( $value ) );
		};

		return sprintf(
			'<button type="button" class="galaxie-kit-choice %1$s" data-kit-choice aria-pressed="%2$s" aria-disabled="%3$s"><img class="galaxie-kit-thumb" alt="" data-slot="image"%4$s /><span class="galaxie-kit-choice-body"><span class="galaxie-kit-choice-name %5$s" data-slot="name">%6$s</span>%7$s%8$s%9$s%10$s</span></button>',
			esc_attr( PixfortControls::surface_classes( $settings, 'box_card' ) ),
			! empty( $data['pressed'] ) ? 'true' : 'false',
			! empty( $data['disabled'] ) ? 'true' : 'false',
			'' !== $image ? ' src="' . esc_url( $image ) . '"' : ' hidden',
			esc_attr( PixfortControls::text_classes( $settings, 'box_name' ) ),
			esc_html( (string) ( $data['name'] ?? '' ) ),
			$slot( 'price' ),
			$slot( 'description' ),
			$slot( 'holds' ),
			$slot( 'reason', 'galaxie-kit-warning' )
		);
	}

	/**
	 * One candle of the kit, with its quantity and "Remover".
	 *
	 * @param array<string,mixed>  $settings
	 * @param array<string,string> $texts
	 * @param array<string,mixed>  $data     name, image, price, qty.
	 */
	private function candle_line( array $settings, array $texts, array $data = array() ): string {
		$image = (string) ( $data['image'] ?? '' );

		return sprintf(
			'<div class="galaxie-kit-row galaxie-kit-line" data-kit-line><img class="galaxie-kit-thumb" alt="" data-slot="image"%1$s /><span class="galaxie-kit-row-body"><span class="galaxie-kit-choice-name %2$s" data-slot="name">%3$s</span><span class="galaxie-kit-choice-meta %4$s" data-slot="price">%5$s</span><a href="#" class="galaxie-kit-link galaxie-kit-small %6$s" data-kit-remove>%7$s</a></span><span class="galaxie-kit-stepper quantity pix-px-10 pix-base-background rounded-lg shadow-sm d-inline-flex justify-content-between"><button type="button" class="galaxie-kit-qty-step text-body-default" data-step="-1" aria-label="%8$s">%9$s</button><span class="galaxie-kit-qty" data-slot="qty">%10$d</span><button type="button" class="galaxie-kit-qty-step text-body-default" data-step="1" aria-label="%11$s">%12$s</button></span></div>',
			'' !== $image ? ' src="' . esc_url( $image ) . '"' : ' hidden',
			esc_attr( PixfortControls::text_classes( $settings, 'box_name' ) ),
			esc_html( (string) ( $data['name'] ?? '' ) ),
			esc_attr( PixfortControls::text_classes( $settings, 'box_meta' ) ),
			esc_html( (string) ( $data['price'] ?? '' ) ),
			esc_attr( PixfortControls::text_classes( $settings, 'small' ) ),
			esc_html( $texts['summary_remove'] ),
			esc_attr__( 'Menos', 'galaxie-woo' ),
			self::icon( 'Line/pixfort-icon-minus-1', '−' ),
			(int) ( $data['qty'] ?? 1 ),
			esc_attr__( 'Mais', 'galaxie-woo' ),
			self::icon( 'Line/pixfort-icon-plus-1', '+' )
		);
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_templates( array $settings, array $texts ): void {
		printf( '<template data-kit-tpl="choice">%s</template>', $this->choice( $settings ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in choice().
		printf( '<template data-kit-tpl="line">%s</template>', $this->candle_line( $settings, $texts ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in candle_line().
	}

	// ----------------------------------------------------------------- sample

	/**
	 * The editor's kit, from the store: the largest size of the first candle
	 * product (Builder::sample_candle()), two of them; the boxes on offer, the
	 * cheapest that holds them chosen; the first card for that box. Anything the
	 * store has none of is drawn as "Exemplo: …".
	 *
	 * @param array<string,string> $words The widget's texts.
	 * @return array<string,mixed>
	 */
	private function sample( array $words ): array {
		$woo      = function_exists( 'wc_get_product' ) && Module::size_attribute() !== '';
		$catalog  = $woo ? new WooCatalog() : null;
		$product  = $woo ? Builder::sample_candle() : null;
		$candle   = $product && $catalog ? $catalog->candle( $product->get_id() ) : null;
		$options  = Module::packing_options();
		$sizes    = $catalog ? $catalog->sizes() : array();
		$labels   = array_column( $sizes, 'label', 'size' );
		$units    = $candle ? array( $candle['candle'], $candle['candle'] ) : array();
		$money    = static fn( float $amount ): string => $catalog ? $catalog->money( $amount ) : number_format( $amount, 2, ',', '.' );
		$name     = GiftKit::default_name( array(), Kits::name_format() );
		$texts    = array(
			'many'  => (string) Module::setting( 'kit_text_room_many' ),
			'one'   => (string) Module::setting( 'kit_text_room_one' ),
			'full'  => (string) Module::setting( 'kit_text_full' ),
			'holds' => (string) Module::setting( 'kit_text_box_holds' ),
		);
		$starting = $candle ? '2 × ' . $candle['name'] : '2 × ' . __( 'Exemplo: vela', 'galaxie-woo' );
		$boxes    = array();
		$chosen   = null;

		foreach ( $catalog ? $catalog->boxes() : array() as $box ) {
			$fits  = ! $units || GiftPacking::fits( $box['shape'], $units, $options );
			$empty = GiftKit::wording( GiftKit::combos( $box['shape'], array(), $sizes, $options ), $labels );

			if ( $fits && 0 !== $box['stock'] && ( ! $chosen || $box['price'] < $chosen['price'] ) ) {
				$chosen = $box;
			}

			$boxes[ $box['id'] ] = array(
				'name'        => $box['name'],
				'image'       => $box['image'],
				'price'       => $money( (float) $box['price'] ),
				'description' => $box['description'],
				'holds'       => 'full' === $empty['state'] ? '' : GiftKit::fill( $texts['holds'], array( 'combos' => $empty['combos'] ) ),
				'reason'      => $fits ? '' : GiftKit::fill( $words['box_reason'], array( 'candles' => $starting ) ),
				'disabled'    => ! $fits,
				'pressed'     => false,
			);
		}

		if ( $chosen ) {
			$boxes[ $chosen['id'] ]['pressed'] = true;
		}

		if ( ! $boxes ) {
			$boxes[] = array(
				'name'    => __( 'Exemplo: caixa', 'galaxie-woo' ),
				'image'   => self::placeholder(),
				'price'   => '',
				'holds'   => GiftKit::fill( $texts['holds'], array( 'combos' => '2 × 190g ou 4 × 50g' ) ),
				'pressed' => true,
			);
		}

		// The card for the chosen box, from each card product.
		$cards     = array();
		$card_row  = null;
		$seen      = array();

		foreach ( $catalog ? $catalog->cards() : array() as $card ) {
			if ( isset( $seen[ $card['parent'] ] ) ) {
				continue;
			}

			$seen[ $card['parent'] ] = true;
			$id                      = $catalog->card_for( (int) $card['parent'], $chosen ? (int) $chosen['id'] : 0 );
			$row                     = $id ? $catalog->cards()[ $id ] : null;

			if ( ! $row ) {
				continue;
			}

			$card_row = $card_row ?? $row;
			$cards[]  = array(
				'name'    => GiftKit::fill( $words['card_add'], array( 'preço' => $money( (float) $row['price'] ), 'card' => $row['title'] ) ),
				'image'   => $row['image'],
				'price'   => '',
				'pressed' => $row === $card_row,
			);
		}

		if ( ! $cards ) {
			$cards[] = array(
				'name'    => __( 'Exemplo: cartão', 'galaxie-woo' ),
				'image'   => self::placeholder(),
				'pressed' => true,
			);
		}

		$cards[] = array( 'name' => $words['card_skip'] );

		// What the kit would say with those two candles in the chosen box.
		$room = array(
			'state'  => 'many',
			'combos' => '1 × 50g',
		);
		$fill = 50;

		if ( $chosen ) {
			$room = GiftKit::wording( GiftKit::combos( $chosen['shape'], $units, $sizes, $options ), $labels );
			$fill = GiftGroups::fill( $chosen['shape'], $units, $sizes, $options );
		}

		$sentence = 'full' === $room['state'] ? $texts['full'] : GiftKit::fill( 'one' === $room['state'] ? $texts['one'] : $texts['many'], array( 'combos' => $room['combos'] ) );
		$total    = ( $candle ? 2 * $candle['price'] : 0 ) + ( $chosen ? $chosen['price'] : 0 ) + ( $card_row ? $card_row['price'] : 0 );
		$message  = __( 'Feliz aniversário! Com carinho.', 'galaxie-woo' );

		return array(
			'name'         => $name,
			'starting'     => $starting,
			'boxes'        => array_values( $boxes ),
			'cards'        => $cards,
			'message'      => $message,
			'combos'       => $room['combos'],
			'room'         => $sentence,
			'fill'         => $fill,
			'full'         => 'full' === $room['state'],
			'total'        => $money( (float) $total ),
			'summary_box'  => array(
				'name'  => $chosen ? $chosen['name'] : __( 'Exemplo: caixa', 'galaxie-woo' ),
				'image' => $chosen ? $chosen['image'] : self::placeholder(),
				'price' => $chosen ? $money( (float) $chosen['price'] ) : '',
			),
			'summary_card' => array(
				'name'  => $card_row ? $card_row['name'] : __( 'Exemplo: cartão', 'galaxie-woo' ),
				'image' => $card_row ? $card_row['image'] : self::placeholder(),
				'price' => $card_row ? $money( (float) $card_row['price'] ) : '',
			),
			'candles'      => array(
				array(
					'name'  => $candle ? $candle['name'] : __( 'Exemplo: vela', 'galaxie-woo' ),
					'image' => $candle ? $candle['image'] : self::placeholder(),
					'price' => $candle ? $money( (float) $candle['price'] ) : '',
					'qty'   => 2,
				),
			),
		);
	}

	private static function placeholder(): string {
		return function_exists( 'wc_placeholder_img_src' ) ? (string) wc_placeholder_img_src( 'woocommerce_thumbnail' ) : '';
	}

	/** pixfort's icon when the theme has it, the character otherwise. */
	private static function icon( string $name, string $fallback = '' ): string {
		if ( PixfortControls::available() && isset( \PixfortCore::instance()->icons ) && method_exists( \PixfortCore::instance()->icons, 'getIcon' ) ) {
			return (string) \PixfortCore::instance()->icons->getIcon( $name, 20, 'align-self-center qty-icon' );
		}

		return esc_html( $fallback );
	}

	/** True inside the Elementor editor's canvas. */
	private static function is_editing(): bool {
		return class_exists( '\\Elementor\\Plugin' )
			&& isset( \Elementor\Plugin::$instance->editor )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}

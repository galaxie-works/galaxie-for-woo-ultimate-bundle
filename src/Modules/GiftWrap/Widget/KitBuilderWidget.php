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
use Galaxie\Woo\Support\Dialog;
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
				'step_more'  => array( __( 'Step 4 label', 'galaxie-woo' ), __( 'Pronto', 'galaxie-woo' ) ),
			),
			'welcome'  => array(
				'welcome_title'  => array( __( 'Title', 'galaxie-woo' ), __( 'Monte um kit de presente', 'galaxie-woo' ) ),
				'welcome_text'   => array( __( 'Text', 'galaxie-woo' ), __( 'Escolha a caixa e um cartão com mensagem. As velas você vai somando pela loja, e nós montamos e enviamos prontinho.', 'galaxie-woo' ) ),
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
				'box_text'    => array( __( 'Text', 'galaxie-woo' ), __( 'Cada caixa leva uma quantidade diferente de velas. Você completa o kit depois, na loja.', 'galaxie-woo' ) ),
				'box_reason'  => array( __( 'A box that cannot hold the candles ({candles})', 'galaxie-woo' ), __( 'Não comporta {candles}', 'galaxie-woo' ) ),
				'box_sold'    => array( __( 'A sold-out box', 'galaxie-woo' ), __( 'Esgotada', 'galaxie-woo' ) ),
				'box_none'    => array( __( 'No box holds the candles ({candles})', 'galaxie-woo' ), __( 'Nenhuma caixa comporta {candles}. Diminua a quantidade na página do produto e tente de novo.', 'galaxie-woo' ) ),
				'box_empty'   => array( __( 'No boxes on offer', 'galaxie-woo' ), __( 'Nenhuma caixa disponível no momento.', 'galaxie-woo' ) ),
				'box_none_any' => array( __( 'No box holds anything the shop sells', 'galaxie-woo' ), __( 'Nenhuma caixa comporta as velas da loja agora. Tente de novo mais tarde.', 'galaxie-woo' ) ),
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
				'continue_text'   => array( __( 'Text ({n} candles so far, {combos}, {room}, {kit})', 'galaxie-woo' ), __( 'Você ainda pode adicionar {combos}. Continue pesquisando nossos produtos e adicionando a este kit.', 'galaxie-woo' ) ),
				'continue_full'   => array( __( 'Text when the box is already full', 'galaxie-woo' ), __( 'A caixa já está completa. Veja o kit e adicione ao carrinho.', 'galaxie-woo' ) ),
				'continue_button' => array( __( 'Keep choosing button (closes and goes to the shop)', 'galaxie-woo' ), __( 'Continuar escolhendo', 'galaxie-woo' ) ),
				'continue_view'   => array( __( 'See the kit button', 'galaxie-woo' ), __( 'Ver kit', 'galaxie-woo' ) ),
				'continue_cart'   => array( __( 'Add to cart button on this step', 'galaxie-woo' ), __( 'Adicionar kit ao carrinho', 'galaxie-woo' ) ),
			),
			'summary'  => array(
				'summary_title'    => array( __( 'Title', 'galaxie-woo' ), __( 'Seu kit', 'galaxie-woo' ) ),
				'summary_text'     => array( __( 'Text under the title (optional)', 'galaxie-woo' ), '' ),
				'summary_name'     => array( __( 'Name label', 'galaxie-woo' ), __( 'Nome', 'galaxie-woo' ) ),
				'summary_box'      => array( __( 'Box label', 'galaxie-woo' ), __( 'Caixa', 'galaxie-woo' ) ),
				'summary_card'     => array( __( 'Card label', 'galaxie-woo' ), __( 'Cartão', 'galaxie-woo' ) ),
				'summary_no_card'  => array( __( 'No card', 'galaxie-woo' ), __( 'Sem cartão', 'galaxie-woo' ) ),
				'summary_change'   => array( __( '"Trocar" link', 'galaxie-woo' ), __( 'trocar', 'galaxie-woo' ) ),
				'summary_message'  => array( __( 'Message label', 'galaxie-woo' ), __( 'Mensagem', 'galaxie-woo' ) ),
				'summary_no_msg'   => array( __( 'Card without a message', 'galaxie-woo' ), __( 'Cartão sem mensagem', 'galaxie-woo' ) ),
				'summary_no_card_fit' => array( __( 'The card no longer exists for this box', 'galaxie-woo' ), __( 'O cartão escolhido não existe para esta caixa. Troque o cartão para continuar.', 'galaxie-woo' ) ),
				'summary_gone'     => array( __( 'A candle that is no longer sold', 'galaxie-woo' ), __( 'Indisponível — remova para continuar', 'galaxie-woo' ) ),
				'summary_candles'  => array( __( 'Candles label', 'galaxie-woo' ), __( 'Velas', 'galaxie-woo' ) ),
				'summary_empty'    => array( __( 'No candles yet', 'galaxie-woo' ), __( 'Nenhuma vela ainda. Feche o kit, escolha uma vela na loja e use "Adicionar ao kit".', 'galaxie-woo' ) ),
				'summary_remove'   => array( __( 'Remove a candle', 'galaxie-woo' ), __( 'Remover', 'galaxie-woo' ) ),
				'summary_total'    => array( __( 'Total label', 'galaxie-woo' ), __( 'Total do kit', 'galaxie-woo' ) ),
				'action_cart'      => array( __( 'Add to cart button', 'galaxie-woo' ), __( 'Adicionar kit ao carrinho', 'galaxie-woo' ) ),
				'action_continue'  => array( __( 'Close button', 'galaxie-woo' ), __( 'Fechar', 'galaxie-woo' ) ),
				'action_new'       => array( __( 'Add and start another button', 'galaxie-woo' ), __( 'Adicionar ao carrinho e começar um novo', 'galaxie-woo' ) ),
				'action_discard'   => array( __( 'Discard button', 'galaxie-woo' ), __( 'Descartar kit', 'galaxie-woo' ) ),
				'action_previous'  => array( __( 'Kit kept at login ({kit})', 'galaxie-woo' ), __( 'Recuperar kit anterior ({kit})', 'galaxie-woo' ) ),
				'need_candle'      => array( __( 'Adding with no candle', 'galaxie-woo' ), __( 'Adicione pelo menos uma vela ao kit.', 'galaxie-woo' ) ),
			),
		);
	}

	/**
	 * The kit's buttons, by what they do. Each one is a section of its own with
	 * its own look and its own icon: "Voltar" and "Descartar kit" are not two
	 * shades of the same secondary button, and a shopper reads the icon before
	 * the label. The defaults reproduce the primary / outline / link looks the
	 * three shared sections used to draw.
	 *
	 * @return array<string, array{0:string, 1:array<string,string>}>
	 */
	private static function buttons(): array {
		return array(
			'start'     => array( __( 'Button · Montar um kit (welcome)', 'galaxie-woo' ), array( 'color' => 'primary', 'icon' => 'Line/pixfort-icon-gift-1' ) ),
			'next'      => array( __( 'Button · Próximo', 'galaxie-woo' ), array( 'color' => 'primary', 'icon' => 'Line/pixfort-icon-arrow-right-2', 'icon_position' => 'after' ) ),
			'back'      => array( __( 'Button · Voltar', 'galaxie-woo' ), array( 'style' => 'outline', 'color' => 'primary', 'icon' => 'Line/pixfort-icon-arrow-left-2' ) ),
			'choose'    => array( __( 'Button · Continuar escolhendo (step 4)', 'galaxie-woo' ), array( 'color' => 'primary', 'icon' => 'Line/pixfort-icon-arrow-right-2', 'icon_position' => 'after' ) ),
			'view'      => array( __( 'Button · Ver kit', 'galaxie-woo' ), array( 'style' => 'outline', 'color' => 'primary', 'icon' => 'Line/pixfort-icon-eye-visibility-1' ) ),
			'cart'      => array( __( 'Button · Adicionar kit ao carrinho', 'galaxie-woo' ), array( 'style' => 'outline', 'color' => 'primary', 'icon' => 'Line/pixfort-icon-bag-1' ) ),
			'cart_full' => array( __( 'Button · Adicionar kit ao carrinho (box full)', 'galaxie-woo' ), array( 'color' => 'primary', 'icon' => 'Line/pixfort-icon-bag-1' ) ),
			'cart_new'  => array( __( 'Button · Adicionar e começar outro', 'galaxie-woo' ), array( 'style' => 'outline', 'color' => 'primary', 'icon' => 'Line/pixfort-icon-plus-circle-1' ) ),
			'close'     => array( __( 'Button · Continuar escolhendo (summary)', 'galaxie-woo' ), array( 'style' => 'outline', 'color' => 'primary' ) ),
			'previous'  => array( __( 'Button · Recuperar kit anterior', 'galaxie-woo' ), array( 'style' => 'outline', 'color' => 'primary', 'icon' => 'Line/pixfort-icon-clock-1' ) ),
			'discard'   => array( __( 'Button · Descartar kit', 'galaxie-woo' ), array( 'style' => 'link', 'color' => 'red', 'icon' => 'Line/pixfort-icon-cross-circle-1' ) ),
		);
	}

	/**
	 * How a flex container spreads what it holds. Empty: whatever the
	 * stylesheet already does.
	 *
	 * @return array<string,string>
	 */
	private static function distribution(): array {
		return array(
			''              => __( 'Default', 'galaxie-woo' ),
			'flex-start'    => __( 'Start', 'galaxie-woo' ),
			'center'        => __( 'Middle', 'galaxie-woo' ),
			'flex-end'      => __( 'End', 'galaxie-woo' ),
			'space-between' => __( 'Space between', 'galaxie-woo' ),
			'space-around'  => __( 'Space around', 'galaxie-woo' ),
			'space-evenly'  => __( 'Space evenly', 'galaxie-woo' ),
		);
	}

	/**
	 * The summary's own actions: a link to swap the box or the card, one to drop
	 * a candle, and the two steps of its quantity box. None of them is a button,
	 * so none is covered by a button section's icon.
	 *
	 * @var array<string, array{0:string, 1:string}>
	 */
	private const ROW_ICONS = array(
		'summary_change' => array( 'Icon on "trocar"', '' ),
		'summary_remove' => array( 'Icon on "Remover"', '' ),
		'summary_minus'  => array( 'Icon on "−"', 'Line/pixfort-icon-minus-1' ),
		'summary_plus'   => array( 'Icon on "+"', 'Line/pixfort-icon-plus-1' ),
	);

	/**
	 * The screens, as the panel names them. One list: the picker above and the
	 * sections below must never drift apart.
	 *
	 * @return array<string,string>
	 */
	private static function screen_labels(): array {
		return array(
			'welcome'  => __( 'Screen · Welcome', 'galaxie-woo' ),
			'name'     => __( 'Screen · 1 · Name', 'galaxie-woo' ),
			'box'      => __( 'Screen · 2 · Box', 'galaxie-woo' ),
			'card'     => __( 'Screen · 3 · Card', 'galaxie-woo' ),
			'continue' => __( 'Screen · 4 · Keep choosing', 'galaxie-woo' ),
			'summary'  => __( 'Screen · Kit summary', 'galaxie-woo' ),
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
				PixfortControls::icon_select( $this, $screen . '_icon', __( 'Title icon', 'galaxie-woo' ), self::ICONS[ $screen ] );
			}

			// The summary's own actions are links and steppers, not buttons, so
			// each one names its icon here.
			if ( 'summary' === $screen ) {
				foreach ( self::ROW_ICONS as $key => $icon ) {
					PixfortControls::icon_select( $this, $key . '_icon', $icon[0], $icon[1] );
				}
			}

			if ( 'continue' === $screen ) {
				$this->add_control(
					'continue_cart_show',
					array(
						'label'        => __( 'Offer "Adicionar kit ao carrinho" here', 'galaxie-woo' ),
						'description'  => __( 'For a kit that is already good to go. It waits for the first candle.', 'galaxie-woo' ),
						'type'         => Controls_Manager::SWITCHER,
						'return_value' => 'yes',
						'default'      => 'yes',
					)
				);
			}

			foreach ( $texts as $key => $text ) {
				$this->add_control(
					$key . '_text',
					array(
						'label'       => $text[0],
						'label_block' => true,
						'type'        => in_array( $key, array( 'welcome_text', 'continue_text', 'box_none', 'name_text' ), true ) ? Controls_Manager::TEXTAREA : Controls_Manager::TEXT,
						'default'     => $text[1],
						'condition'   => 'continue_cart' === $key ? array( 'continue_cart_show' => 'yes' ) : array(),
					)
				);
			}

			$this->end_controls_section();
		}

		// Confirmations belong to the widget that asks them, so they carry its
		// pixfort buttons and its typography instead of the script's plain
		// fallback. The script fills {kit} in whichever sentence wins.
		Dialog::controls(
			$this,
			'kit_discard',
			array(
				'label' => __( 'Discard kit dialog', 'galaxie-woo' ),
				'title' => __( 'Descartar kit', 'galaxie-woo' ),
				'text'  => __( 'Descartar o kit {kit}? Isso não pode ser desfeito.', 'galaxie-woo' ),
				'yes'   => __( 'Sim, descartar', 'galaxie-woo' ),
				'no'    => __( 'Cancelar', 'galaxie-woo' ),
			)
		);

		Dialog::controls(
			$this,
			'kit_restore',
			array(
				'label'        => __( 'Recover previous kit dialog', 'galaxie-woo' ),
				'title'        => __( 'Recuperar kit', 'galaxie-woo' ),
				'text'         => __( 'Isso troca o kit que você está montando pelo kit anterior. Tudo bem?', 'galaxie-woo' ),
				'yes'          => __( 'Sim, recuperar', 'galaxie-woo' ),
				'no'           => __( 'Cancelar', 'galaxie-woo' ),
				'yes_defaults' => array( 'color' => 'primary', 'size' => 'sm' ),
			)
		);

		$this->register_style_controls();
	}

	private function register_style_controls(): void {
		$style = static fn( string $label ): array => array(
			'label' => $label,
			'tab'   => Controls_Manager::TAB_STYLE,
		);

		// Six screens and eleven buttons are seventeen sections a merchant scrolls
		// past to reach the two they came for. Naming the ones worth opening keeps
		// the rest out of the way; every control stays registered, so a screen or a
		// button that is not named still draws exactly as it did.
		$this->start_controls_section( 'kit_sections_style', $style( __( 'Sections', 'galaxie-woo' ) ) );
		$this->add_control(
			'kit_sections_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Pick what you want to style apart. Nothing changes on the page until you change it.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);
		$this->add_control(
			'screens_apart',
			array(
				'label'       => __( 'Screens to style', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => self::screen_labels(),
			)
		);
		$this->add_control(
			'buttons_apart',
			array(
				'label'       => __( 'Buttons to style', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => array_map( static fn( array $button ): string => $button[0], self::buttons() ),
			)
		);
		$this->end_controls_section();

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
		// The pill the Wishlist's badges wear, so a dot follows the site's radius
		// scale instead of a hand-typed 999px. `steps_dot_bg` is the id the
		// background already had, so that choice survives the move.
		PixfortControls::surface( $this, 'steps_dot', '{{WRAPPER}} .galaxie-kit-step-dot', array( 'rounded' => 'badge-pill', 'radius_set' => 'badge' ), $shown );
		PixfortControls::palette_control( $this, 'steps_dot_color', __( 'Dot number', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-step-dot', 'color', $shown );
		PixfortControls::palette_control( $this, 'steps_active_bg', __( 'Current and done dots', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-step.is-current .galaxie-kit-step-dot, {{WRAPPER}} .galaxie-kit-step.is-done .galaxie-kit-step-dot', 'background-color', $shown );
		PixfortControls::palette_control( $this, 'steps_active_color', __( 'Current and done numbers', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-step.is-current .galaxie-kit-step-dot, {{WRAPPER}} .galaxie-kit-step.is-done .galaxie-kit-step-dot', 'color', $shown );
		PixfortControls::text( $this, 'steps_label', array( 'size' => 'text-xs', 'bold' => '' ), $shown, '{{WRAPPER}} .galaxie-kit-step-label', 'text', array( 'inline', 'position' ) );
		$this->add_responsive_control(
			'steps_padding',
			array(
				'label'      => __( 'Padding', 'galaxie-woo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'rem', 'em' ),
				'condition'  => $shown,
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-steps' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'steps_space',
			array(
				'label'       => __( 'Space below the steps', 'galaxie-woo' ),
				'description' => __( 'On top of the "Gap between parts" set under Layout.', 'galaxie-woo' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'rem', 'em' ),
				'range'       => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'condition'   => $shown,
				'selectors'   => array( '{{WRAPPER}} .galaxie-kit-steps' => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		// The panel the wizard's step lives in: the step indicator above it and
		// the step's buttons below it stay outside, so a background and rounded
		// corners here box the content alone and not the whole widget.
		$this->start_controls_section( 'kit_content_style', $style( __( 'Step content', 'galaxie-woo' ) ) );
		PixfortControls::surface( $this, 'kit_content', '{{WRAPPER}} .galaxie-kit-content' );
		$this->end_controls_section();

		$this->start_controls_section( 'kit_text_style', $style( __( 'Titles and text', 'galaxie-woo' ) ) );
		$this->heading( 'title_style_heading', __( 'Titles', 'galaxie-woo' ), false );
		PixfortControls::text( $this, 'title', array( 'size' => 'h5', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0', 'position' => '' ), array(), '{{WRAPPER}} .galaxie-kit-title', 'heading' );
		PixfortControls::icon_color( $this, 'title_icon_color', __( 'Icon color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-icon' );
		$this->icon_size( 'title_icon_size', '{{WRAPPER}}' );
		$this->add_control(
			'title_icon_place',
			array(
				'label'   => __( 'Icon side', 'galaxie-woo' ),
				'type'    => Controls_Manager::CHOOSE,
				'default' => 'left',
				'options' => array(
					'left'   => array( 'title' => __( 'Left of the title', 'galaxie-woo' ), 'icon' => 'eicon-h-align-left' ),
					'top'    => array( 'title' => __( 'Above the title', 'galaxie-woo' ), 'icon' => 'eicon-v-align-top' ),
					'bottom' => array( 'title' => __( 'Below the title', 'galaxie-woo' ), 'icon' => 'eicon-v-align-bottom' ),
					'right'  => array( 'title' => __( 'Right of the title', 'galaxie-woo' ), 'icon' => 'eicon-h-align-right' ),
				),
			)
		);
		$this->margin( 'title_margin', __( 'Space around the title', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-head' );
		$this->heading( 'body_style_heading', __( 'Text', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'body', array( 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-kit-text', 'text', array( 'inline', 'position' ) );
		$this->margin( 'body_margin', __( 'Space around the text', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-text' );
		$this->heading( 'small_style_heading', __( 'Labels, hints and the room-left line', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'small', array( 'size' => 'text-sm', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-kit-small', 'text', array( 'inline', 'position' ) );
		$this->margin( 'starting_margin', __( 'Space around the "started from a product" line', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-starting' );
		$this->end_controls_section();

		// The messages the kit prints on its own line are pixfort Alerts, the
		// same component the Buy Box uses, so a refusal here and a refusal there
		// are one object. The two inside someone else's markup — the reason on a
		// box card, "Indisponível" on a candle line — stay words, because an
		// alert cannot live inside a <button>, and `error_color` is theirs.
		//
		// Section id and control ids share one namespace in Elementor, and
		// alert() registers no `_style`, so `kit_message_style` cannot collide
		// with the `kit_alert_*` set below it.
		$this->start_controls_section( 'kit_message_style', $style( __( 'Warnings and errors', 'galaxie-woo' ) ) );
		$types = PixfortControls::alert_types();
		$this->add_control(
			'kit_alert_error_type',
			array(
				'label'   => __( 'Error style', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $types,
				'default' => 'danger',
			)
		);
		$this->add_control(
			'kit_alert_warning_type',
			array(
				'label'   => __( 'Warning style', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $types,
				'default' => 'warning',
			)
		);
		// No icon and no close button by default: pixfort's own fallback glyph is
		// a question mark, which is the wrong face for a refusal, and a message
		// the script re-shows on every redraw should not offer to be dismissed.
		PixfortControls::alert( $this, 'kit_alert', array( 'hide_close' => 'true', 'media_type' => 'none' ), array(), '{{WRAPPER}} .galaxie-kit-alert' );
		PixfortControls::palette_control( $this, 'error_color', __( 'Warning color inside cards and lines', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-warning', 'color' );
		$this->end_controls_section();

		// The line under the title reads as a hint, not as body copy, so it can
		// move down to the field it explains and wear a badge instead.
		$this->start_controls_section( 'kit_hint_style', $style( __( 'Hint (the line under the title)', 'galaxie-woo' ) ) );
		$this->add_control(
			'hint_place',
			array(
				'label'       => __( 'Where it goes', 'galaxie-woo' ),
				'description' => __( 'With the field: under "Nome do kit" and under "Mensagem do cartão", above the boxes and cards on the other steps.', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'title',
				'options'     => array(
					'title'   => __( 'Under the title', 'galaxie-woo' ),
					'field'   => __( 'With the field or the list', 'galaxie-woo' ),
					'buttons' => __( 'Just above the buttons', 'galaxie-woo' ),
				),
			)
		);
		$this->add_control(
			'hint_badge',
			array(
				'label'        => __( 'Show it as a badge', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);
		$badge = array( 'hint_badge' => 'yes' );
		// The Wishlist's pill: pixfort's own `badge-pill` rather than a typed
		// 999px, so the hint follows the site's badge radius. `hint_bg` and
		// `hint_padding` keep their ids and their meaning; `hint_radius` is now
		// the slider behind "Custom", which is where a typed corner belongs.
		PixfortControls::surface( $this, 'hint', '{{WRAPPER}} .galaxie-kit-text.galaxie-kit-badge', array( 'rounded' => 'badge-pill', 'radius_set' => 'badge' ), $badge );
		$this->add_responsive_control(
			'hint_align',
			array(
				'label'     => __( 'Badge side', 'galaxie-woo' ),
				'type'      => Controls_Manager::CHOOSE,
				'condition' => $badge,
				'options'   => array(
					'flex-start' => array( 'title' => __( 'Left', 'galaxie-woo' ), 'icon' => 'eicon-text-align-left' ),
					'center'     => array( 'title' => __( 'Center', 'galaxie-woo' ), 'icon' => 'eicon-text-align-center' ),
					'flex-end'   => array( 'title' => __( 'Right', 'galaxie-woo' ), 'icon' => 'eicon-text-align-right' ),
					'stretch'    => array( 'title' => __( 'Full width', 'galaxie-woo' ), 'icon' => 'eicon-text-align-justify' ),
				),
				'default'   => 'flex-start',
				'selectors' => array( '{{WRAPPER}} .galaxie-kit-text.galaxie-kit-badge' => 'align-self: {{VALUE}};' ),
			)
		);
		$this->end_controls_section();

		// One section per screen, in the order the shopper sees them. Repetitive
		// on purpose: a cover, a form step and a summary share the markup and
		// nothing else, and no single set of controls can dress all six.
		foreach ( self::screen_labels() as $screen => $label ) {
			$this->start_controls_section( 'kit_screen_' . $screen . '_style', $style( $label ) + array( 'condition' => array( 'screens_apart' => $screen ) ) );
			$this->screen_style( $screen, 'welcome' === $screen ? array( 'size' => 'h4' ) : array() );

			if ( 'welcome' === $screen ) {
				$this->heading( 'welcome_image_heading', __( 'Image', 'galaxie-woo' ) );
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
			}

			$this->end_controls_section();
		}

		// Not "Box cards": the name and the meta set here dress the box step, the
		// card step, the summary's own rows and the candle lines. The section is
		// named after all four rather than after the first one written.
		$this->start_controls_section( 'kit_box_style', $style( __( 'Cards and rows (boxes, cards, summary, candles)', 'galaxie-woo' ) ) );
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
		// The cart's and the wishlist's thumbnail set, scoped to the choice cards
		// alone. The old "Picture size" wrote to every `.galaxie-kit-thumb` on
		// the widget and tied on specificity with the stylesheet's own rule for
		// the candle lines, so which won depended on the order the two
		// stylesheets loaded. The summary's rows have their own set now.
		$this->heading( 'box_image_heading', __( 'Picture', 'galaxie-woo' ) );
		PixfortControls::thumb( $this, 'choice_thumb', '{{WRAPPER}} .galaxie-kit-choice .galaxie-kit-thumb', array( 'rounded' => 'custom', 'size' => 64, 'radius' => 8 ) );
		$this->add_responsive_control(
			'choices_gap',
			array(
				'label'      => __( 'Space between the cards', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 10 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-choices' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->margin( 'choices_margin', __( 'Space around the list', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-choices' );
		$this->end_controls_section();

		// The summary is the editor's default screen and the one most shoppers
		// see, and until now it borrowed every control it had from "Box cards"
		// and from the small-text set. Its rows — the box, the card and each
		// candle — are one shape, so one section dresses them.
		//
		// Ungated on purpose: the gated sections behind the two pickers are the
		// six screens and the eleven buttons, and a merchant should not have to
		// find a picker to reach the screen they came for.
		$this->start_controls_section( 'kit_summary_style', $style( __( 'Summary rows', 'galaxie-woo' ) ) );
		$this->add_responsive_control(
			'rows_gap',
			array(
				'label'      => __( 'Space between the rows', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 10 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-summary, {{WRAPPER}} .galaxie-kit-lines' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->heading( 'line_thumb_heading', __( 'Picture', 'galaxie-woo' ) );
		PixfortControls::thumb( $this, 'line_thumb', '{{WRAPPER}} .galaxie-kit-row .galaxie-kit-thumb', array( 'rounded' => 'custom', 'size' => 48, 'radius' => 8 ) );

		// "trocar" and "Remover" used to wear the small-text set, so restyling a
		// field label silently restyled two actions. They are their own text now.
		$this->heading( 'link_heading', __( '"trocar" and "Remover"', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'link', array( 'size' => 'text-sm', 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-kit-link', 'text', array( 'inline', 'position' ) );
		PixfortControls::icon_color( $this, 'link_icon_color', __( 'Icon color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-link' );
		$this->add_responsive_control(
			'link_icon_size',
			array(
				'label'      => __( 'Icon size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 10, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-link svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);

		// The shop's third spinner, from the set the buy box and the cart share.
		// `style` and `max` are skipped: this number is a <span> the script
		// writes, so there is no input to turn into a dropdown — and with them
		// skipped no `kit_qty_style` control exists to collide with a section id.
		$this->heading( 'qty_heading', __( 'The − / + stepper', 'galaxie-woo' ) );
		PixfortControls::quantity(
			$this,
			'kit_qty',
			'{{WRAPPER}} .galaxie-kit-stepper',
			'{{WRAPPER}} .galaxie-kit-qty',
			array(),
			array(),
			'',
			array( 'style', 'max' )
		);
		PixfortControls::icon_color( $this, 'qty_icon_color', __( 'Icon color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-qty-step' );
		$this->add_responsive_control(
			'qty_icon_size',
			array(
				'label'      => __( 'Icon size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 10, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-qty-step svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section( 'kit_field_style', $style( __( 'Name and message fields', 'galaxie-woo' ) ) );
		// Payment Methods' pattern (`pm_field`): the shared box set, then the
		// typed text, then the states. `field_bg` and `field_padding` keep their
		// ids; `field_radius` becomes the slider behind "Custom", which is why
		// Custom is the default — a corner already typed keeps working.
		PixfortControls::surface( $this, 'field', '{{WRAPPER}} .galaxie-kit-input', array( 'rounded' => 'custom' ) );
		$this->heading( 'field_text_heading', __( 'Typed text', 'galaxie-woo' ) );
		PixfortControls::palette_control( $this, 'field_text', __( 'Text color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-input', 'color' );
		// The two states the field had no way to show: the one the shopper is in,
		// and the one the message counter already marks on the label around it.
		$this->heading( 'field_state_heading', __( 'States', 'galaxie-woo' ) );
		PixfortControls::palette_control( $this, 'field_focus', __( 'Border when focused', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-input:focus', 'border-color' );
		PixfortControls::palette_control( $this, 'field_focus_bg', __( 'Background when focused', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-input:focus', 'background-color' );
		PixfortControls::palette_control( $this, 'field_over', __( 'Border over the message limit', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-field.is-over .galaxie-kit-input', 'border-color' );
		$this->heading( 'field_space_heading', __( 'Spacing', 'galaxie-woo' ) );
		$this->add_responsive_control(
			'field_gap',
			array(
				'label'      => __( 'Space between the label and the field', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 4 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-field' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->margin( 'field_margin', __( 'Space around the field', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-field' );
		$this->end_controls_section();

		$this->start_controls_section( 'kit_fill_style', $style( __( 'Fill bar', 'galaxie-woo' ) ) );
		// The same bar the Free Shipping and the Kit Progress widgets draw, under
		// the Free Shipping ids. This widget's own `fill_track` / `fill_bar` /
		// `fill_bar_full` / `fill_height` are the ones that change, so a fill bar
		// already restyled HERE starts from the defaults again — the only one of
		// the three that loses anything, and the price of one vocabulary.
		PixfortControls::progress(
			$this,
			'progress',
			'{{WRAPPER}} .galaxie-kit-fill-track',
			'{{WRAPPER}} .galaxie-kit-fill-bar',
			'{{WRAPPER}} .galaxie-kit-fill.is-full .galaxie-kit-fill-bar',
			array( 'done_label' => __( 'Fill when full', 'galaxie-woo' ) )
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

		foreach ( self::buttons() as $role => $button ) {
			$this->start_controls_section( 'kit_btn_' . $role . '_style', $style( $button[0] ) + array( 'condition' => array( 'buttons_apart' => $role ) ) );
			PixfortControls::button( $this, 'kit_' . $role, $button[1] + array( 'size' => 'md' ), array(), '{{WRAPPER}}', array( 'text' ) );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'kit_layout_style', $style( __( 'Layout', 'galaxie-woo' ) ) );
		$this->add_responsive_control(
			'kit_gap',
			array(
				'label'      => __( 'Gap between parts', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 16 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-builder, {{WRAPPER}} .galaxie-kit-content' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		// The popup holds three blocks in a column — the steps, the step's content
		// and its buttons. A popup with a height of its own needs to say how the
		// three share it, or one screen sits at the top and the next at the
		// bottom. The height is what makes a distribution mean anything: without
		// it the column is exactly as tall as its content.
		$this->heading( 'kit_distribute_heading', __( 'The three blocks', 'galaxie-woo' ) );
		// A popup that is as tall as its tallest screen jumps from step to step —
		// the summary is twice the name step. Capped, the content scrolls inside
		// and the steps and the buttons stay put.
		$this->add_responsive_control(
			'kit_frame_height',
			array(
				'label'       => __( 'Maximum height', 'galaxie-woo' ),
				'description' => __( 'The content scrolls inside it; the steps and the buttons stay in view. Empty: the popup grows with the screen it shows.', 'galaxie-woo' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'vh' ),
				'range'       => array( 'px' => array( 'min' => 200, 'max' => 1200 ), 'vh' => array( 'min' => 30, 'max' => 95 ) ),
				'default'     => array( 'unit' => 'vh', 'size' => 70 ),
				'selectors'   => array(
					'{{WRAPPER}} .galaxie-kit-builder' => 'max-height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .galaxie-kit-content' => 'flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain;',
				),
			)
		);
		$this->add_responsive_control(
			'kit_min_height',
			array(
				'label'       => __( 'Minimum height', 'galaxie-woo' ),
				'description' => __( 'Keeps the popup the same size from step to step. A short screen (a phone with its keyboard open) ignores it.', 'galaxie-woo' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'vh' ),
				'range'       => array( 'px' => array( 'min' => 100, 'max' => 900 ), 'vh' => array( 'min' => 10, 'max' => 90 ) ),
				'default'     => array( 'unit' => 'vh', 'size' => 55 ),
				'selectors'   => array( '{{WRAPPER}} .galaxie-kit-builder' => 'min-height: {{SIZE}}{{UNIT}};' ),
			)
		);


		$this->heading( 'content_align_heading', __( 'The content block', 'galaxie-woo' ) );
		$this->add_responsive_control(
			'content_align',
			array(
				'label'     => __( 'Align what is inside', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => array(
					''           => __( 'Full width', 'galaxie-woo' ),
					'flex-start' => __( 'Left', 'galaxie-woo' ),
					'center'     => __( 'Center', 'galaxie-woo' ),
					'flex-end'   => __( 'Right', 'galaxie-woo' ),
				),
				'selectors' => array( '{{WRAPPER}} .galaxie-kit-content' => 'align-items: {{VALUE}};' ),
			)
		);
		$this->add_responsive_control(
			'content_text_align',
			array(
				'label'     => __( 'Align the text', 'galaxie-woo' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array( 'title' => __( 'Left', 'galaxie-woo' ), 'icon' => 'eicon-text-align-left' ),
					'center' => array( 'title' => __( 'Center', 'galaxie-woo' ), 'icon' => 'eicon-text-align-center' ),
					'right'  => array( 'title' => __( 'Right', 'galaxie-woo' ), 'icon' => 'eicon-text-align-right' ),
				),
				'selectors' => array( '{{WRAPPER}} .galaxie-kit-content' => 'text-align: {{VALUE}};' ),
			)
		);

		$this->heading( 'motion_heading', __( 'Moving between steps', 'galaxie-woo' ) );
		$this->add_control(
			'step_motion',
			array(
				'label'       => __( 'How a step arrives', 'galaxie-woo' ),
				'description' => __( 'Only the step\'s content moves; the steps and the buttons stay where they are. A visitor who asks for less motion gets none.', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'slide',
				'options'     => array(
					'slide' => __( 'Slide and fade', 'galaxie-woo' ),
					'fade'  => __( 'Fade', 'galaxie-woo' ),
					''      => __( 'No movement', 'galaxie-woo' ),
				),
			)
		);
		$this->add_responsive_control(
			'step_motion_ms',
			array(
				'label'      => __( 'How long', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'ms' ),
				'range'      => array( 'ms' => array( 'min' => 80, 'max' => 600, 'step' => 10 ) ),
				'default'    => array( 'unit' => 'ms', 'size' => 240 ),
				'condition'  => array( 'step_motion!' => '' ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-builder' => '--galaxie-kit-step-ms: {{SIZE}}ms;' ),
			)
		);

		$this->heading( 'actions_heading', __( 'The row of buttons', 'galaxie-woo' ) );
		$this->add_responsive_control(
			'actions_stack',
			array(
				'label'       => __( 'Stack the buttons', 'galaxie-woo' ),
				'description' => __( 'Below 480 px they stack on their own; this stacks them at this size too.', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''     => __( 'Side by side', 'galaxie-woo' ),
					'wrap' => __( 'One per line', 'galaxie-woo' ),
				),
				'selectors'   => array(
					'{{WRAPPER}} .galaxie-kit-actions' => 'flex-direction: column; align-items: stretch;',
					'{{WRAPPER}} .galaxie-kit-actions > *, {{WRAPPER}} .galaxie-kit-actions .galaxie-kit-btn' => 'width: 100%;',
				),
			)
		);
		$this->add_responsive_control(
			'actions_justify',
			array(
				'label'     => __( 'Spread the buttons', 'galaxie-woo' ),
				'description' => __( 'The Back / Next row is spread apart unless you choose otherwise here.', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => self::distribution(),
				'selectors' => array( '{{WRAPPER}} .galaxie-kit-actions' => 'justify-content: {{VALUE}};' ),
			)
		);
		$this->add_responsive_control(
			'actions_gap',
			array(
				'label'      => __( 'Space between the buttons', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 12 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-kit-actions' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->margin( 'actions_margin', __( 'Space around the row', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-kit-actions' );
		$this->end_controls_section();
	}

	/**
	 * One screen's own title, text, panel and spacing.
	 *
	 * The typography and the panel wait behind a switch, because they are
	 * classes this screen's markup has to be printed with. The margins do not:
	 * an empty one writes no rule, and a filled one beats the shared section
	 * because its selector names the screen.
	 *
	 * @param array<string,string> $title_defaults
	 */
	private function screen_style( string $screen, array $title_defaults = array() ): void {
		$this->add_control(
			$screen . '_own',
			array(
				'label'        => __( 'Title and text apart on this screen', 'galaxie-woo' ),
				'description'  => __( 'Its title, its text and its panel only. Labels, counters and the room line keep following "Titles and text".', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$own  = array( $screen . '_own' => 'yes' );
		$at   = '{{WRAPPER}} .galaxie-kit-screen--' . $screen;
		$body = $at . ' .galaxie-kit-content';

		$this->heading( $screen . '_title_heading', __( 'Title', 'galaxie-woo' ) );
		PixfortControls::text( $this, $screen . '_title', $title_defaults + array( 'size' => 'h5', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0', 'position' => '' ), $own, $at . ' .galaxie-kit-title', 'heading' );
		$this->margin( $screen . '_title_margin', __( 'Space around the title', 'galaxie-woo' ), $at . ' .galaxie-kit-head' );

		$this->icon_size( $screen . '_icon_size', $at );
		$this->add_control(
			$screen . '_icon_place',
			array(
				'label'   => __( 'Icon side', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''       => __( 'Follow "Titles and text"', 'galaxie-woo' ),
					'left'   => __( 'Left of the title', 'galaxie-woo' ),
					'top'    => __( 'Above the title', 'galaxie-woo' ),
					'bottom' => __( 'Below the title', 'galaxie-woo' ),
					'right'  => __( 'Right of the title', 'galaxie-woo' ),
				),
			)
		);

		$this->heading( $screen . '_body_heading', __( 'Text', 'galaxie-woo' ) );
		PixfortControls::text( $this, $screen . '_body', array( 'bold' => '' ), $own, $at . ' .galaxie-kit-text', 'text', array( 'inline', 'position' ) );
		$this->margin( $screen . '_body_margin', __( 'Space around the text', 'galaxie-woo' ), $at . ' .galaxie-kit-text' );

		$this->heading( $screen . '_panel_heading', __( 'Panel', 'galaxie-woo' ) );
		PixfortControls::palette_control( $this, $screen . '_panel_bg', __( 'Background', 'galaxie-woo' ), $body, 'background-color', $own );
		$this->add_responsive_control(
			$screen . '_panel_padding',
			array(
				'label'      => __( 'Padding', 'galaxie-woo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'rem', 'em' ),
				'condition'  => $own,
				'selectors'  => array( $body => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			$screen . '_panel_radius',
			array(
				'label'      => __( 'Rounded corners', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'condition'  => $own,
				'selectors'  => array( $body => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			$screen . '_gap',
			array(
				'label'      => __( 'Gap between parts', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( $body => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->heading( $screen . '_space_heading', __( 'Spacing on this screen', 'galaxie-woo' ) );
		$this->margin( $screen . '_field_margin', __( 'Space around the fields', 'galaxie-woo' ), $at . ' .galaxie-kit-field' );
		$this->margin( $screen . '_list_margin', __( 'Space around the lists', 'galaxie-woo' ), $at . ' .galaxie-kit-choices, ' . $at . ' .galaxie-kit-lines' );
		$this->margin( $screen . '_actions_margin', __( 'Space around the buttons', 'galaxie-woo' ), $at . ' .galaxie-kit-actions' );
	}

	/**
	 * The size of the icon beside a title. pixfort draws it at 20 px through the
	 * SVG's own attributes, which a width and a height override.
	 */
	private function icon_size( string $id, string $scope ): void {
		$this->add_responsive_control(
			$id,
			array(
				'label'      => __( 'Icon size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem', 'em' ),
				'range'      => array( 'px' => array( 'min' => 8, 'max' => 120 ) ),
				'selectors'  => array( $scope . ' .galaxie-kit-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);
	}

	/**
	 * How a step arrives: the class the stylesheet keys its animation off.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function motion_class( array $settings ): string {
		$motion = (string) ( $settings['step_motion'] ?? 'slide' );

		return in_array( $motion, array( 'slide', 'fade' ), true ) ? ' galaxie-kit-motion--' . $motion : '';
	}

	/**
	 * Space around one part of a screen. Every block sits in a flex column, so
	 * the gap sets them all apart and this moves one of them on its own.
	 *
	 * @param array<string,string> $condition
	 */
	private function margin( string $id, string $label, string $selector, array $condition = array() ): void {
		$this->add_responsive_control(
			$id,
			array(
				'label'      => $label,
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'rem', 'em' ),
				'condition'  => $condition,
				'selectors'  => array( $selector => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
	}

	/**
	 * A message on a line of its own, drawn by pixfort's own Alert — the same
	 * component the Buy Box refuses with, so a refusal here and a refusal there
	 * are one object rather than two red paragraphs.
	 *
	 * The element the script finds stays on the OUTSIDE: PixAlert draws its own
	 * `<div class="alert">` and puts the words in a `.pix-alert-title` inside
	 * it, so kit-builder.ts writes into that node instead of into the wrapper.
	 * The fallback below carries the same class for the same reason — the
	 * script must find one target whether or not the theme is installed.
	 *
	 * @param array<string,mixed> $settings
	 * @param string              $kind     `error` or `warning`: which type control answers.
	 * @param string              $attr     The wrapper's attributes, already escaped.
	 * @param string              $text     The message; empty on a line the script fills.
	 */
	private function message( array $settings, string $kind, string $attr, string $text = '' ): string {
		$type = (string) ( $settings[ 'kit_alert_' . $kind . '_type' ] ?? ( 'error' === $kind ? 'danger' : 'warning' ) );

		$inner = PixfortControls::available()
			? (string) \PixfortCore::instance()->elementsManager->renderElement( 'Alert', PixfortControls::alert_attr( $settings, 'kit_alert', $text, $type ) )
			: sprintf(
				'<div class="alert alert-%1$s" role="alert"><div class="pix-alert-title">%2$s</div></div>',
				esc_attr( $type ),
				wp_kses_post( $text )
			);

		return sprintf( '<div class="galaxie-kit-alert" %1$s>%2$s</div>', $attr, $inner );
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
			( 'yes' === ( $settings['steps_show'] ?? 'yes' ) ? '' : ' no-steps' ) . $this->motion_class( $settings ), // Nothing to hide then: the indicator is not printed.
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
			$sample ? GiftKit::html( GiftKit::fill( $texts['starting'], array( 'candles' => $sample['starting'] ) ) ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- through wp_kses().
		);

		printf( '<p class="galaxie-kit-small galaxie-kit-loading %1$s" data-kit-loading hidden>%2$s</p>', esc_attr( PixfortControls::text_classes( $settings, 'small' ) ), esc_html( $texts['loading'] ) );

		foreach ( self::SCREENS as $name ) {
			printf( '<section class="galaxie-kit-screen galaxie-kit-screen--%1$s" data-kit-screen="%1$s"%2$s>', esc_attr( $name ), $screen === $name ? '' : ' hidden' );
			$this->in_foot = false;
			printf( '<div class="galaxie-kit-content %s" data-kit-content>', esc_attr( PixfortControls::surface_classes( $settings, 'kit_content' ) ) );
			$this->{'render_' . $name}( $settings, $texts, $screen === $name ? $sample : null );
			echo '</section>';
		}

		echo $this->message( $settings, 'error', 'data-kit-error hidden' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in message().

		if ( ! $editing ) {
			$this->render_templates( $settings, $texts );
		}

		echo Dialog::render( $settings, 'kit_discard' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo Dialog::render( $settings, 'kit_restore' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

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
				'<li class="galaxie-kit-step%1$s" data-step="%2$s"><span class="galaxie-kit-step-dot %3$s">%4$d</span><span class="galaxie-kit-step-label %5$s">%6$s</span></li>',
				esc_attr( $class ),
				esc_attr( $name ),
				esc_attr( PixfortControls::surface_classes( $settings, 'steps_dot' ) ),
				$n + 1,
				esc_attr( PixfortControls::text_classes( $settings, 'steps_label' ) ),
				esc_html( $texts[ $label ] )
			);
			++$n;
		}

		echo '</ol>';
	}

	/**
	 * The screen's hint, waiting for its place: "Where it goes" can hold it back
	 * until the field it explains ({@see hint_anchor()}) or the buttons
	 * ({@see actions()}). Only one of the three prints it, so each screen keeps
	 * the single `data-slot="text"` the script fills.
	 *
	 * @var string|null The hint's text; an empty string still prints the slot
	 *                   the script fills, null means this screen has none.
	 */
	private ?string $hint = null;

	/** The text prefix that hint wears, decided with it. */
	private string $hint_prefix = 'body';

	/**
	 * The control prefix a screen's title and text wear: the welcome screen has
	 * its own pair once "Style this screen apart" is on, every step shares the
	 * ones in "Titles and text".
	 *
	 * @param array<string,mixed> $settings
	 */
	private function text_prefix( array $settings, string $screen, string $part ): string {
		return 'yes' === ( $settings[ $screen . '_own' ] ?? '' ) && in_array( $part, self::OWN_PARTS, true )
			? $screen . '_' . $part
			: $part;
	}

	/**
	 * The text sets a screen can take over. Labels, counters and the room line
	 * stay shared on purpose: six more typography sets for text nobody restyles
	 * per screen would cost more panel than it is worth, and the switch says so.
	 */
	private const OWN_PARTS = array( 'title', 'body' );

	/** Title (with the screen's icon) and text. @param array<string,mixed> $settings */
	private function head( array $settings, string $screen, string $title, string $text, bool $slot_text = false ): void {
		$icon = PixfortControls::icon_value( $settings, $screen . '_icon' );

		printf( '<div class="galaxie-kit-head%s">', esc_attr( $this->head_place( $settings, $screen ) ) );

		if ( '' !== $icon ) {
			echo '<span class="galaxie-kit-icon">' . self::icon( $icon ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own SVG.
		}

		printf( '<div class="galaxie-kit-title" data-slot="title">%s</div>', PixfortControls::render_text( $settings, $this->text_prefix( $settings, $screen, 'title' ), GiftKit::html( $title ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's element around text through wp_kses().
		echo '</div>';

		$this->hint        = '' !== $text || $slot_text ? $text : null;
		$this->hint_prefix = $this->text_prefix( $settings, $screen, 'body' );

		if ( 'title' === ( $settings['hint_place'] ?? 'title' ) ) {
			$this->hint_print( $settings );
		}
	}

	/**
	 * The modifier that puts the title's icon on a side: this screen's choice,
	 * or the one "Titles and text" made for every screen.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function head_place( array $settings, string $screen ): string {
		$place = (string) ( $settings[ $screen . '_icon_place' ] ?? '' );

		if ( '' === $place ) {
			$place = (string) ( $settings['title_icon_place'] ?? 'left' );
		}

		return in_array( $place, array( 'right', 'top', 'bottom' ), true ) ? ' galaxie-kit-head--' . $place : '';
	}

	/**
	 * The field or list the hint explains: it lands here with "With the field or
	 * the list", and on a screen without one it falls through to the buttons.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function hint_anchor( array $settings ): void {
		if ( 'field' === ( $settings['hint_place'] ?? 'title' ) ) {
			$this->hint_print( $settings );
		}
	}

	/** @param array<string,mixed> $settings */
	private function hint_print( array $settings ): void {
		if ( null === $this->hint ) {
			return;
		}

		$text       = $this->hint;
		$this->hint = null;

		$badge = 'yes' === ( $settings['hint_badge'] ?? '' )
			? ' galaxie-kit-badge ' . PixfortControls::surface_classes( $settings, 'hint' )
			: '';

		printf(
			'<p class="galaxie-kit-text %1$s%2$s" data-slot="text">%3$s</p>',
			esc_attr( PixfortControls::text_classes( $settings, $this->hint_prefix ) ),
			esc_attr( rtrim( $badge ) ),
			GiftKit::html( $text ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- through wp_kses().
		);
	}

	/**
	 * A button in one of the three looks.
	 *
	 * @param array<string,mixed> $settings
	 * @param string              $role     A key of {@see buttons()}.
	 * @param string              $extra    More attributes, already escaped.
	 */
	private function button( array $settings, string $role, string $action, string $text, string $extra = '' ): string {
		return sprintf(
			'<button type="button" class="galaxie-buybox-btn galaxie-kit-btn galaxie-kit-btn--%1$s" data-kit-action="%2$s" data-kit-variant="%1$s"%3$s>%4$s</button>',
			esc_attr( $role ),
			esc_attr( $action ),
			$extra,
			PixfortControls::render_button( $settings, 'kit_' . $role, $text )
		);
	}

	/**
	 * Closes the step's content panel and opens its row of buttons.
	 *
	 * Every screen ends in one, so the panel opened in render() is always shut
	 * here: the buttons belong to the wizard, not to the panel they follow.
	 */
	private function actions( array $settings, string $extra = '' ): void {
		$this->hint_print( $settings );
		$this->foot_close();
		printf( '<div class="galaxie-kit-actions%s">', esc_attr( '' !== $extra ? ' ' . $extra : '' ) );
	}

	/**
	 * Closes the step's content panel and, with it, the only part that scrolls.
	 * What comes after — the fill bar, the total, the buttons — stays in view
	 * however long the candle list grows.
	 */
	private function foot_open(): void {
		if ( ! $this->in_foot ) {
			echo '</div>';
			$this->in_foot = true;
		}
	}

	private function foot_close(): void {
		$this->foot_open();
	}

	/** Whether the content panel has already been closed for this screen. */
	private bool $in_foot = false;

	/** @param array<string,mixed> $settings */
	private function nav( array $settings, array $texts, string $next = '' ): void {
		$this->actions( $settings, 'galaxie-kit-nav' );
		echo $this->button( $settings, 'back', 'back', $texts['back'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo $this->button( $settings, 'next', 'next', '' !== $next ? $next : $texts['next'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</div>';
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_welcome( array $settings, array $texts, ?array $sample ): void {
		$image = (string) ( $settings['welcome_image']['url'] ?? '' );

		if ( '' !== $image ) {
			printf( '<img class="galaxie-kit-welcome-image" src="%s" alt="" />', esc_url( $image ) );
		}

		$this->head( $settings, 'welcome', $texts['welcome_title'], $texts['welcome_text'] );

		$this->actions( $settings );
		echo $this->button( $settings, 'start', 'begin', $texts['welcome_button'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		$this->previous_button( $settings, $texts );
		echo '</div>';
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_name( array $settings, array $texts, ?array $sample ): void {
		// Live, the hint waits for the script: the default name depends on the visitor's cart.
		$this->head( $settings, 'name', $texts['name_title'], $sample ? GiftKit::fill( $texts['name_text'], array( 'kit' => $sample['name'] ) ) : '', true );

		printf( '<label class="galaxie-kit-field"><span class="galaxie-kit-small %1$s">%2$s</span>', esc_attr( PixfortControls::text_classes( $settings, 'small' ) ), esc_html( $texts['name_label'] ) );
		$this->hint_anchor( $settings );
		printf(
			'<input type="text" class="galaxie-kit-input %1$s" data-kit-name maxlength="%2$d" placeholder="%3$s" autocomplete="off" /></label>',
			esc_attr( PixfortControls::surface_classes( $settings, 'field' ) ),
			(int) GiftKit::NAME_MAX,
			esc_attr( $texts['name_placeholder'] )
		);

		$this->nav( $settings, $texts );
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_box( array $settings, array $texts, ?array $sample ): void {
		$this->head( $settings, 'box', $texts['box_title'], $texts['box_text'] );
		$this->hint_anchor( $settings );

		echo '<div class="galaxie-kit-choices" data-kit-boxes>';

		foreach ( $sample ? $sample['boxes'] : array() as $box ) {
			echo $this->choice( $settings, $box ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in choice().
		}

		echo '</div>';
		echo $this->message( $settings, 'warning', 'data-kit-box-none hidden' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in message().

		$this->nav( $settings, $texts );
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_card( array $settings, array $texts, ?array $sample ): void {
		$this->head( $settings, 'card', $texts['card_title'], $texts['card_text'] );
		$this->hint_anchor( $settings );

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
			'<label class="galaxie-kit-field" data-kit-%1$s-wrap%2$s><span class="galaxie-kit-small %3$s">%4$s</span><textarea class="galaxie-kit-input %8$s" rows="3" data-kit-%1$s placeholder="%5$s">%6$s</textarea><span class="galaxie-kit-count galaxie-kit-small %3$s" data-kit-%1$s-count>%7$s</span></label>',
			esc_attr( $slot ),
			$hidden ? ' hidden' : '',
			esc_attr( PixfortControls::text_classes( $settings, 'small' ) ),
			esc_html( 'message' === $slot ? $texts['card_label'] : $texts['summary_message'] ),
			esc_attr( $texts['card_placeholder'] ),
			esc_textarea( $message ),
			esc_html( GiftGroups::message_length( $message ) . '/' . $max ),
			esc_attr( PixfortControls::surface_classes( $settings, 'field' ) )
		);
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_continue( array $settings, array $texts, ?array $sample ): void {
		$title = GiftKit::fill( $texts['continue_title'], array( 'kit' => $sample ? $sample['name'] : '' ) );
		$text  = $sample ? GiftKit::fill( $texts['continue_text'], array( 'combos' => $sample['combos'], 'kit' => $sample['name'] ) ) : '';

		$this->head( $settings, 'continue', $title, $text, true );

		$this->actions( $settings );
		echo $this->button( $settings, 'choose', 'continue', $texts['continue_button'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo $this->button( $settings, 'view', 'summary', $texts['continue_view'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().

		// The kit already holds the candle the shopper came with, so it can go
		// to the cart from here; the script keeps it off until it has one.
		if ( 'yes' === ( $settings['continue_cart_show'] ?? 'yes' ) ) {
			echo $this->button( $settings, 'cart', 'to-cart', $texts['continue_cart'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		}

		echo '</div>';
	}

	/** @param array<string,mixed> $settings @param array<string,string> $texts */
	private function render_summary( array $settings, array $texts, ?array $sample ): void {
		$small = esc_attr( PixfortControls::text_classes( $settings, 'small' ) );
		$meta  = esc_attr( PixfortControls::text_classes( $settings, 'box_meta' ) );
		$name  = esc_attr( PixfortControls::text_classes( $settings, 'box_name' ) );
		$link  = esc_attr( PixfortControls::text_classes( $settings, 'link' ) );
		$thumb = esc_attr( PixfortControls::thumb_classes( $settings, 'line_thumb' ) );

		$this->head( $settings, 'summary', $texts['summary_title'], $texts['summary_text'] );

		echo '<div class="galaxie-kit-summary">';

		printf(
			'<label class="galaxie-kit-field"><span class="galaxie-kit-small %1$s">%2$s</span><input type="text" class="galaxie-kit-input %6$s" data-kit-summary-name maxlength="%3$d" placeholder="%4$s" value="%5$s" autocomplete="off" /></label>',
			$small, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			esc_html( $texts['summary_name'] ),
			(int) GiftKit::NAME_MAX,
			esc_attr( $texts['name_placeholder'] ),
			esc_attr( $sample ? $sample['name'] : '' ),
			esc_attr( PixfortControls::surface_classes( $settings, 'field' ) )
		);

		foreach ( array( 'box' => 'change-box', 'card' => 'change-card' ) as $part => $action ) {
			$row = $sample ? $sample[ 'summary_' . $part ] : array(
				'name'  => '',
				'image' => '',
				'price' => '',
			);

			printf(
				'<div class="galaxie-kit-row galaxie-kit-row--%1$s" data-kit-summary-%1$s><img class="galaxie-kit-thumb %12$s" alt="" data-slot="image"%2$s /><span class="galaxie-kit-row-body"><span class="galaxie-kit-small %3$s">%4$s</span><span class="galaxie-kit-choice-name %5$s" data-slot="name">%6$s</span><span class="galaxie-kit-choice-meta %7$s" data-slot="price">%8$s</span></span><a href="#" class="galaxie-kit-link %13$s" data-kit-action="%9$s">%11$s%10$s</a></div>',
				esc_attr( $part ),
				'' !== $row['image'] ? ' src="' . esc_url( $row['image'] ) . '"' : ' hidden',
				$small, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( $texts[ 'summary_' . $part ] ),
				$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( $row['name'] ),
				$meta, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( $row['price'] ),
				esc_attr( $action ),
				esc_html( $texts['summary_change'] ),
				self::row_icon( $settings, 'summary_change' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own SVG.
				$thumb, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			);
		}

		$this->message_field( $settings, $texts, $sample ? $sample['message'] : '', false, 'summary-message' );
		echo $this->message( $settings, 'warning', 'data-kit-warning hidden', $texts['summary_no_msg'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in message().
		echo $this->message( $settings, 'warning', 'data-kit-card-warning hidden', $texts['summary_no_card_fit'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in message().

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

		$this->foot_open();
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

		$this->actions( $settings, 'galaxie-kit-summary-actions' );
		// The add button changes look once the box is full: both are printed.
		printf( '<span data-kit-when="full"%s>', $full ? '' : ' hidden' );
		echo $this->button( $settings, 'cart_full', 'to-cart', $texts['action_cart'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</span>';
		printf( '<span data-kit-when="room"%s>', $full ? ' hidden' : '' );
		echo $this->button( $settings, 'cart', 'to-cart', $texts['action_cart'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</span>';
		// Only closes: the shopper is already where they want to keep choosing.
		echo $this->button( $settings, 'close', 'close', $texts['action_continue'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo $this->button( $settings, 'cart_new', 'to-cart-new', $texts['action_new'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo $this->button( $settings, 'discard', 'discard', $texts['action_discard'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		$this->previous_button( $settings, $texts );
		echo '</div>';
	}

	/**
	 * "Recuperar kit anterior", hidden until the kit endpoint says an account
	 * kit was kept aside at login. Its label is filled by the script ({kit}).
	 *
	 * @param array<string,mixed>  $settings
	 * @param array<string,string> $texts
	 */
	private function previous_button( array $settings, array $texts ): void {
		echo '<span data-kit-previous hidden>';
		echo $this->button( $settings, 'previous', 'restore-previous', GiftKit::fill( $texts['action_previous'], array( 'kit' => '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</span>';
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
			'<button type="button" class="galaxie-kit-choice %1$s" data-kit-choice aria-pressed="%2$s" aria-disabled="%3$s"><img class="galaxie-kit-thumb %11$s" alt="" data-slot="image"%4$s /><span class="galaxie-kit-choice-body"><span class="galaxie-kit-choice-name %5$s" data-slot="name">%6$s</span>%7$s%8$s%9$s%10$s</span></button>',
			esc_attr( PixfortControls::surface_classes( $settings, 'box_card' ) ),
			! empty( $data['pressed'] ) ? 'true' : 'false',
			! empty( $data['disabled'] ) ? 'true' : 'false',
			'' !== $image ? ' src="' . esc_url( $image ) . '"' : ' hidden',
			esc_attr( PixfortControls::text_classes( $settings, 'box_name' ) ),
			esc_html( (string) ( $data['name'] ?? '' ) ),
			$slot( 'price' ),
			$slot( 'description' ),
			$slot( 'holds' ),
			$slot( 'reason', 'galaxie-kit-warning' ),
			esc_attr( PixfortControls::thumb_classes( $settings, 'choice_thumb' ) )
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

		// The stepper's pixfort classes used to be typed in here — `pix-px-10
		// pix-base-background rounded-lg shadow-sm` — where no control could
		// reach them and no other spinner in the shop could match them. They are
		// the stylesheet's defaults now, and the shared quantity set writes over
		// them. `.quantity` stays: it is what the theme's own rules look for.
		return sprintf(
			'<div class="galaxie-kit-row galaxie-kit-line" data-kit-line><img class="galaxie-kit-thumb %15$s" alt="" data-slot="image"%1$s /><span class="galaxie-kit-row-body"><span class="galaxie-kit-choice-name %2$s" data-slot="name">%3$s</span><span class="galaxie-kit-choice-meta %4$s" data-slot="price">%5$s</span><span class="galaxie-kit-small galaxie-kit-warning galaxie-kit-gone %6$s" data-kit-gone hidden>%14$s</span><a href="#" class="galaxie-kit-link %16$s" data-kit-remove>%13$s%7$s</a></span><span class="galaxie-kit-stepper quantity"><button type="button" class="galaxie-kit-qty-step text-body-default" data-step="-1" aria-label="%8$s">%9$s</button><span class="galaxie-kit-qty" data-slot="qty">%10$d</span><button type="button" class="galaxie-kit-qty-step text-body-default" data-step="1" aria-label="%11$s">%12$s</button></span></div>',
			'' !== $image ? ' src="' . esc_url( $image ) . '"' : ' hidden',
			esc_attr( PixfortControls::text_classes( $settings, 'box_name' ) ),
			esc_html( (string) ( $data['name'] ?? '' ) ),
			esc_attr( PixfortControls::text_classes( $settings, 'box_meta' ) ),
			esc_html( (string) ( $data['price'] ?? '' ) ),
			esc_attr( PixfortControls::text_classes( $settings, 'small' ) ),
			esc_html( $texts['summary_remove'] ),
			esc_attr__( 'Menos', 'galaxie-woo' ),
			self::row_icon( $settings, 'summary_minus', '−' ),
			(int) ( $data['qty'] ?? 1 ),
			esc_attr__( 'Mais', 'galaxie-woo' ),
			self::row_icon( $settings, 'summary_plus', '+' ),
			self::row_icon( $settings, 'summary_remove' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own SVG.
			esc_html( $texts['summary_gone'] ),
			esc_attr( PixfortControls::thumb_classes( $settings, 'line_thumb' ) ),
			esc_attr( PixfortControls::text_classes( $settings, 'link' ) )
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
	/**
	 * A summary action's icon, as the merchant chose it. Empty by default for
	 * the links: a word is enough there until someone says otherwise.
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function row_icon( array $settings, string $key, string $fallback = '' ): string {
		$icon = PixfortControls::icon_value( $settings, $key . '_icon' );

		return '' !== $icon ? self::icon( $icon, $fallback ) : ( '' !== $fallback ? esc_html( $fallback ) : '' );
	}

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

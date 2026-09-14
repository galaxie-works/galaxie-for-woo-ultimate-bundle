<?php
/**
 * "Galaxie Account Wishlist": the customer's lists and what is on them.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Wishlist\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Modules\Wishlist\Lists;
use Galaxie\Woo\Support\AccountEndpoints;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\Dialog;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * Tabs for the customer's lists, the chosen list's products as a grid, and
 * what can be done with the list: make it the default, rename it, delete it,
 * share it by link (copy, WhatsApp, e-mail, a new link) and accept gifts.
 *
 * The chosen list travels in `?lista=` so a reload, a shared screen and the
 * back button keep it. Every change goes through AJAX and the widget is drawn
 * again from the server (see frontend globals/wishlist-account.ts).
 *
 * "Buy" adds a simple product straight to the cart and opens any other kind,
 * because a candle with a size to choose cannot be added without choosing it.
 */
final class AccountWishlistWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account-wishlist';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Wishlist', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-heart-o';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'wishlist', 'favoritos', 'lista de desejos', 'listas' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'wl_section', array( 'label' => __( 'Wishlist', 'galaxie-woo' ) ) );

		$this->add_control( 'wl_heading', array( 'label' => __( 'Heading', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Lista de desejos', 'galaxie-woo' ) ) );

		$this->add_responsive_control(
			'wl_columns',
			array(
				'label'          => __( 'Columns', 'galaxie-woo' ),
				'type'           => Controls_Manager::SELECT,
				'options'        => array( '1' => '1', '2' => '2', '3' => '3', '4' => '4' ),
				'default'        => '3',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'selectors'      => array( '{{WRAPPER}} .galaxie-wishlist-grid' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
			)
		);

		$this->add_control( 'wl_show_price', array( 'label' => __( 'Price', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes', 'separator' => 'before' ) );
		$this->add_control( 'wl_show_sale', array( 'label' => __( '"On sale" badge', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'wl_sale_text', array( 'label' => __( '"On sale" text', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Oferta', 'galaxie-woo' ), 'condition' => array( 'wl_show_sale' => 'yes' ) ) );
		$this->add_control( 'wl_show_stock', array( 'label' => __( '"Out of stock" badge', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'wl_stock_text', array( 'label' => __( '"Out of stock" text', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Esgotado', 'galaxie-woo' ), 'condition' => array( 'wl_show_stock' => 'yes' ) ) );

		$this->add_control( 'wl_empty_title', array( 'label' => __( 'Empty list title', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Nada por aqui ainda', 'galaxie-woo' ), 'separator' => 'before' ) );
		$this->add_control( 'wl_empty_label', array( 'label' => __( 'Empty list text', 'galaxie-woo' ), 'type' => Controls_Manager::TEXTAREA, 'rows' => 2, 'default' => __( 'Toque no coração de um produto para guardá-lo aqui.', 'galaxie-woo' ) ) );
		PixfortControls::icon_select( $this, 'wl_empty_icon', __( 'Empty list icon', 'galaxie-woo' ), 'Line/pixfort-icon-heart-1' );

		$this->end_controls_section();

		$this->start_controls_section( 'wl_lists_section', array( 'label' => __( 'Lists and sharing', 'galaxie-woo' ) ) );

		$texts = array(
			'wl_name_placeholder' => array( __( 'List name placeholder', 'galaxie-woo' ), __( 'Nome da lista', 'galaxie-woo' ) ),
			'wl_default_badge'    => array( __( '"Default" badge', 'galaxie-woo' ), __( 'Padrão', 'galaxie-woo' ) ),
			'wl_shared_badge'     => array( __( '"Shared" badge', 'galaxie-woo' ), __( 'Compartilhada', 'galaxie-woo' ) ),
			'wl_share_title'      => array( __( 'Share panel title', 'galaxie-woo' ), __( 'Compartilhar esta lista', 'galaxie-woo' ) ),
			'wl_share_note'       => array( __( 'Share panel note', 'galaxie-woo' ), __( 'Quem tiver o link vê a lista, sem precisar de conta.', 'galaxie-woo' ) ),
			'wl_share_toggle'     => array( __( 'Share switch label', 'galaxie-woo' ), __( 'Compartilhar por link', 'galaxie-woo' ) ),
			'wl_gifts_toggle'     => array( __( 'Gifts switch label', 'galaxie-woo' ), __( 'Aceitar presentes, entregues no meu endereço padrão', 'galaxie-woo' ) ),
			'wl_share_message'    => array( __( 'Share message', 'galaxie-woo' ), __( 'Olha a minha lista "{name}" na Eir Naturals: {url}', 'galaxie-woo' ) ),
			'wl_copied_text'      => array( __( '"Copied" message', 'galaxie-woo' ), __( 'Link copiado.', 'galaxie-woo' ) ),
		);

		foreach ( $texts as $id => $text ) {
			$this->add_control( $id, array( 'label' => $text[0], 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => $text[1], 'description' => 'wl_share_message' === $id ? __( '{name} is the list, {url} its link.', 'galaxie-woo' ) : '' ) );
		}

		// The form only opens on "New list" or "Rename"; styling it needs it open.
		$this->add_control(
			'wl_name_form_preview',
			array(
				'label'        => __( 'Show the list name form in the editor', 'galaxie-woo' ),
				'description'  => __( 'Keeps the new list form open while you style it. Customers only see it after tapping "New list" or "Rename".', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'separator'    => 'before',
			)
		);

		$this->end_controls_section();

		$buttons = array(
			'wl_buy'            => array( __( 'Card: buy button', 'galaxie-woo' ), array( 'text' => __( 'Comprar', 'galaxie-woo' ), 'size' => 'sm' ) ),
			'wl_options'        => array( __( 'Card: choose options button', 'galaxie-woo' ), array( 'text' => __( 'Escolher opções', 'galaxie-woo' ), 'style' => 'outline', 'size' => 'sm' ) ),
			'wl_remove'         => array( __( 'Card: remove button', 'galaxie-woo' ), array( 'text' => __( 'Remover', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm', 'icon' => 'Line/pixfort-icon-cross-circle-1' ) ),
			'wl_list_new'       => array( __( 'Lists: new list button', 'galaxie-woo' ), array( 'text' => __( 'Nova lista', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
			'wl_list_default'   => array( __( 'List: make default button', 'galaxie-woo' ), array( 'text' => __( 'Tornar padrão', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
			'wl_list_rename'    => array( __( 'List: rename button', 'galaxie-woo' ), array( 'text' => __( 'Renomear', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
			'wl_list_delete'    => array( __( 'List: delete button', 'galaxie-woo' ), array( 'text' => __( 'Excluir lista', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
			'wl_name_save'      => array( __( 'List name form: save button', 'galaxie-woo' ), array( 'text' => __( 'Salvar', 'galaxie-woo' ), 'size' => 'sm' ) ),
			'wl_name_cancel'    => array( __( 'List name form: cancel button', 'galaxie-woo' ), array( 'text' => __( 'Cancelar', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
			'wl_share_copy'     => array( __( 'Share: copy link button', 'galaxie-woo' ), array( 'text' => __( 'Copiar link', 'galaxie-woo' ), 'style' => 'outline', 'size' => 'sm' ) ),
			'wl_share_whatsapp' => array( __( 'Share: WhatsApp button', 'galaxie-woo' ), array( 'text' => __( 'WhatsApp', 'galaxie-woo' ), 'style' => 'outline', 'size' => 'sm' ) ),
			'wl_share_email'    => array( __( 'Share: e-mail button', 'galaxie-woo' ), array( 'text' => __( 'E-mail', 'galaxie-woo' ), 'style' => 'outline', 'size' => 'sm' ) ),
			'wl_share_renew'    => array( __( 'Share: new link button', 'galaxie-woo' ), array( 'text' => __( 'Gerar novo link', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
			'wl_shop'           => array( __( 'List: empty list button', 'galaxie-woo' ), array( 'text' => __( 'Ver produtos', 'galaxie-woo' ) ) ),
		);

		foreach ( $buttons as $prefix => $button ) {
			$this->start_controls_section( $prefix . '_section', array( 'label' => $button[0] ) );
			PixfortControls::button( $this, $prefix, $button[1] );
			$this->end_controls_section();
		}

		// Style, in the order the screen reads.
		$this->text_sections(
			array(
				'wl_heading_text' => array( __( 'Header: heading', 'galaxie-woo' ), '.galaxie-wishlist-heading', array( 'size' => 'text-20', 'bold' => 'font-weight-bold' ), true ),
			)
		);

		$this->start_controls_section( 'wl_tabs_style', array( 'label' => __( 'Lists: tabs', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'wl_tab', '{{WRAPPER}} .galaxie-wishlist-tab', array( 'rounded' => 'badge-pill', 'radius_set' => 'badge' ) );
		$this->add_control( 'wl_tab_current_heading', array( 'label' => __( 'Current list', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $this, 'wl_tab_current_bg', __( 'Background', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-wishlist-tab.is-current', 'background-color' );
		PixfortControls::palette_control( $this, 'wl_tab_current_color', __( 'Text color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-wishlist-tab.is-current', 'color' );
		PixfortControls::palette_control( $this, 'wl_tab_current_border', __( 'Border color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-wishlist-tab.is-current', 'border-color', array(), ' border-style: solid; border-width: 1px;' );
		$this->end_controls_section();

		$this->text_sections(
			array(
				'wl_tab_text'   => array( __( 'Lists: tab text', 'galaxie-woo' ), '.galaxie-wishlist-tab', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ) ),
				'wl_list_name'  => array( __( 'List: name', 'galaxie-woo' ), '.galaxie-wishlist-list-name', array( 'size' => 'text-18', 'bold' => 'font-weight-bold' ) ),
				'wl_badge_text' => array( __( 'List: badges text', 'galaxie-woo' ), '.galaxie-wishlist-list-badge', array( 'size' => 'text-xs', 'bold' => 'font-weight-bold' ) ),
			)
		);

		$this->start_controls_section( 'wl_list_badge_style', array( 'label' => __( 'List: badges', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'wl_list_badge', '{{WRAPPER}} .galaxie-wishlist-list-badge', array( 'rounded' => 'badge-pill', 'radius_set' => 'badge' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'wl_share_style', array( 'label' => __( 'Share panel: box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'wl_share_box', '{{WRAPPER}} .galaxie-wishlist-share', array( 'rounded' => 'rounded-lg' ) );
		$this->end_controls_section();

		$this->text_sections(
			array(
				'wl_share_title_text' => array( __( 'Share panel: title', 'galaxie-woo' ), '.galaxie-wishlist-share-title', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ) ),
				'wl_share_body'       => array( __( 'Share panel: note and switches', 'galaxie-woo' ), '.galaxie-wishlist-share-body', array( 'size' => 'text-sm', 'bold' => '' ) ),
			)
		);

		$this->start_controls_section( 'wl_card_style', array( 'label' => __( 'Card: box and layout', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'wl_card', '{{WRAPPER}} .galaxie-wishlist-item', array( 'rounded' => 'rounded-lg' ) );

		$sliders = array(
			'wl_gap'           => array( __( 'Space between products', 'galaxie-woo' ), '.galaxie-wishlist-grid', 'gap', 60 ),
			'wl_actions_space' => array( __( 'Space above the buttons', 'galaxie-woo' ), '.galaxie-wishlist-actions', 'padding-top', 60 ),
			'wl_actions_gap'   => array( __( 'Space between buttons', 'galaxie-woo' ), '.galaxie-wishlist-actions', 'gap', 40 ),
		);

		foreach ( $sliders as $id => $slider ) {
			$this->add_responsive_control( $id, array( 'label' => $slider[0], 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => $slider[3] ) ), 'selectors' => array( '{{WRAPPER}} ' . $slider[1] => $slider[2] . ': {{SIZE}}{{UNIT}};' ) ) );
		}

		$this->add_responsive_control(
			'wl_actions_direction',
			array(
				'label'     => __( 'Buttons', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					''       => __( 'In a row', 'galaxie-woo' ),
					'column' => __( 'Stacked', 'galaxie-woo' ),
				),
				'selectors' => array( '{{WRAPPER}} .galaxie-wishlist-actions' => 'flex-direction: {{VALUE}}; align-items: stretch;' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'wl_thumb_style', array( 'label' => __( 'Card: picture', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::thumb( $this, 'wl_thumb', '{{WRAPPER}} .galaxie-wishlist-thumb img', array( 'size_mode' => 'width' ), array(), '{{WRAPPER}} .galaxie-wishlist-thumb' );
		$this->add_control( 'wl_thumb_zoom', array( 'label' => __( 'Zoom on hover', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'separator' => 'before', 'selectors' => array( '{{WRAPPER}} .galaxie-wishlist-item:hover .galaxie-wishlist-thumb img' => 'transform: scale(1.06);' ) ) );
		$this->end_controls_section();

		$this->text_sections(
			array(
				'wl_name'  => array( __( 'Card: product name', 'galaxie-woo' ), '.galaxie-wishlist-name', array( 'bold' => 'font-weight-bold' ) ),
				'wl_price' => array( __( 'Card: price', 'galaxie-woo' ), '.galaxie-wishlist-price', array( 'bold' => '' ) ),
			)
		);

		foreach ( array( 'sale' => __( 'Card badge: "on sale"', 'galaxie-woo' ), 'stock' => __( 'Card badge: "out of stock"', 'galaxie-woo' ) ) as $type => $label ) {
			$this->start_controls_section( 'wl_badge_' . $type . '_style', array( 'label' => $label, 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'wl_show_' . $type => 'yes' ) ) );
			PixfortControls::surface( $this, 'wl_badge_' . $type, '{{WRAPPER}} .galaxie-wishlist-badge.is-' . $type, array( 'rounded' => 'badge-pill', 'radius_set' => 'badge' ) );
			PixfortControls::text( $this, 'wl_badge_' . $type . '_text', array( 'size' => 'text-xs', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-wishlist-badge.is-' . $type, 'text', array( 'position', 'inline' ) );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'wl_empty_style', array( 'label' => __( 'List: empty state', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'wl_empty_box', '{{WRAPPER}} .galaxie-wishlist-empty-box' );
		$this->add_responsive_control( 'wl_empty_icon_size', array( 'label' => __( 'Icon size', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 16, 'max' => 120 ) ), 'separator' => 'before', 'selectors' => array( '{{WRAPPER}} .galaxie-wishlist-empty-icon .pixfort-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ) ) );
		PixfortControls::icon_color( $this, 'wl_empty_icon_color', __( 'Icon color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-wishlist-empty-icon' );
		$this->end_controls_section();

		$this->text_sections(
			array(
				'wl_empty_title_text' => array( __( 'List: empty title', 'galaxie-woo' ), '.galaxie-wishlist-empty-title', array( 'size' => 'text-18', 'bold' => 'font-weight-bold' ) ),
				'wl_empty'            => array( __( 'List: empty text', 'galaxie-woo' ), '.galaxie-wishlist-empty', array( 'bold' => '' ) ),
				'wl_msg_text'         => array( __( 'Saved and error messages', 'galaxie-woo' ), '.galaxie-account-message', array( 'size' => 'text-sm', 'bold' => '' ) ),
			)
		);

		Dialog::controls(
			$this,
			'wl_remove_confirm',
			array(
				'label' => __( 'Remove product dialog', 'galaxie-woo' ),
				'title' => __( 'Remover produto', 'galaxie-woo' ),
				'text'  => __( 'Remover este produto da lista?', 'galaxie-woo' ),
				'yes'   => __( 'Sim, remover', 'galaxie-woo' ),
				'no'    => __( 'Cancelar', 'galaxie-woo' ),
			)
		);

		Dialog::controls(
			$this,
			'wl_delete_confirm',
			array(
				'label' => __( 'Delete list dialog', 'galaxie-woo' ),
				'title' => __( 'Excluir lista', 'galaxie-woo' ),
				'text'  => __( 'Excluir esta lista e tudo o que está nela? O link compartilhado deixa de funcionar.', 'galaxie-woo' ),
				'yes'   => __( 'Sim, excluir', 'galaxie-woo' ),
				'no'    => __( 'Cancelar', 'galaxie-woo' ),
			)
		);
	}

	/**
	 * Text style sections, in the order given. A fourth `true` marks text drawn
	 * by pixfort's Text element; the rest are classes on this widget's own
	 * markup, which carry fewer controls.
	 *
	 * @param array<string,array<int,mixed>> $texts
	 */
	private function text_sections( array $texts ): void {
		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', empty( $text[3] ) ? array( 'position', 'inline' ) : array( 'position' ) );
			$this->end_controls_section();
		}
	}

	protected function render(): void {
		if ( ! function_exists( 'wc_get_product' ) || ( ! is_user_logged_in() && ! AccountParts::editing() ) ) {
			return;
		}

		Assets::enqueue();

		$s       = $this->get_settings_for_display();
		$editing = AccountParts::editing();
		$user_id = get_current_user_id();
		$lists   = $user_id ? Lists::all( $user_id ) : array();
		$wanted  = isset( $_GET['lista'] ) ? sanitize_key( wp_unslash( $_GET['lista'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which list to show.
		$current = $lists[0] ?? null;

		foreach ( $lists as $list ) {
			if ( $list['id'] === $wanted ) {
				$current = $list;
			}
		}

		$products = $current ? array_filter( array_map( 'wc_get_product', array_reverse( (array) $current['items'] ) ), static fn( $p ) => $p instanceof \WC_Product && $p->is_visible() ) : array();

		// Something to style in the editor, where the list may be empty.
		if ( ! $products && $editing ) {
			$products = wc_get_products( array( 'status' => 'publish', 'limit' => 3, 'orderby' => 'date' ) );
		}

		$text = static fn( string $prefix, string $class, string $html, string $tag = 'div' ): string => sprintf( '<%1$s class="%2$s %3$s">%4$s</%1$s>', $tag, esc_attr( $class ), esc_attr( PixfortControls::text_classes( $s, $prefix ) ), $html );
		$base = AccountEndpoints::url( 'galaxie-wishlist' );

		printf(
			'<div class="galaxie-account-wishlist" data-list-id="%1$s" data-msg-class="%2$s" data-copied="%3$s">',
			esc_attr( (string) ( $current['id'] ?? '' ) ),
			esc_attr( PixfortControls::text_classes( $s, 'wl_msg_text' ) ),
			esc_attr( (string) ( $s['wl_copied_text'] ?? '' ) )
		);

		$heading = trim( (string) ( $s['wl_heading'] ?? '' ) );

		if ( '' !== $heading ) {
			printf( '<div class="galaxie-wishlist-heading">%s</div>', PixfortControls::render_text( $s, 'wl_heading_text', esc_html( $heading ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		echo '<div class="galaxie-account-message" role="status" aria-live="polite" hidden></div>';

		// Tabs: every list, and a new one.
		echo '<nav class="galaxie-wishlist-tabs">';

		foreach ( $lists as $list ) {
			printf(
				'<a class="galaxie-wishlist-tab %1$s%2$s" href="%3$s" data-list-id="%4$s">%5$s <span class="galaxie-wishlist-tab-count">%6$d</span></a>',
				esc_attr( trim( PixfortControls::text_classes( $s, 'wl_tab_text' ) . ' ' . PixfortControls::surface_classes( $s, 'wl_tab' ) ) ),
				$current && $list['id'] === $current['id'] ? ' is-current' : '',
				esc_url( add_query_arg( 'lista', $list['id'], $base ) ),
				esc_attr( (string) $list['id'] ),
				esc_html( (string) $list['name'] ),
				count( (array) $list['items'] )
			);
		}

		printf( '%s</nav>', $this->button( $s, 'wl_list_new', 'galaxie-wishlist-list-new' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		printf(
			'<form class="galaxie-wishlist-name-form"%4$s><input type="text" name="name" class="form-control" maxlength="60" placeholder="%1$s" required /><div class="galaxie-wishlist-name-actions">%2$s%3$s</div></form>',
			esc_attr( (string) ( $s['wl_name_placeholder'] ?? '' ) ),
			$this->button( $s, 'wl_name_save', 'galaxie-wishlist-name-save', 'submit' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$this->button( $s, 'wl_name_cancel', 'galaxie-wishlist-name-cancel' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$editing && 'yes' === ( $s['wl_name_form_preview'] ?? '' ) ? '' : ' hidden'
		);

		if ( $current ) {
			$this->render_toolbar( $s, $current );
			$this->render_share( $s, $current );
		}

		printf(
			'<div class="galaxie-wishlist-empty-box %1$s"%2$s>%3$s%4$s%5$s%6$s</div>',
			esc_attr( PixfortControls::surface_classes( $s, 'wl_empty_box' ) ),
			$products ? ' hidden' : '',
			$this->empty_icon( $s ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own icon markup.
			'' !== trim( (string) ( $s['wl_empty_title'] ?? '' ) ) ? $text( 'wl_empty_title_text', 'galaxie-wishlist-empty-title', esc_html( (string) $s['wl_empty_title'] ) ) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			$text( 'wl_empty', 'galaxie-wishlist-empty', esc_html( (string) ( $s['wl_empty_label'] ?? '' ) ), 'p' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			AccountParts::link_button( $s, 'wl_shop', (string) ( $s['wl_shop_text'] ?? '' ), (string) wc_get_page_permalink( 'shop' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);

		if ( $products ) {
			echo '<div class="galaxie-wishlist-grid">';

			foreach ( $products as $product ) {
				echo $this->card( $s, $product, (string) ( $current['id'] ?? '' ), $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			}

			echo '</div>';
		}

		echo Dialog::render( $s, 'wl_remove_confirm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo Dialog::render( $s, 'wl_delete_confirm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		echo '</div>';
	}

	/** @param array<string,mixed> $s @param array<string,mixed> $list */
	private function render_toolbar( array $s, array $list ): void {
		$badge  = trim( PixfortControls::text_classes( $s, 'wl_badge_text' ) . ' ' . PixfortControls::surface_classes( $s, 'wl_list_badge' ) );
		$badges = '';

		if ( ! empty( $list['default'] ) ) {
			$badges .= sprintf( '<span class="galaxie-wishlist-list-badge is-default %1$s">%2$s</span>', esc_attr( $badge ), esc_html( (string) ( $s['wl_default_badge'] ?? '' ) ) );
		}

		if ( '' !== (string) $list['share'] ) {
			$badges .= sprintf( '<span class="galaxie-wishlist-list-badge is-shared %1$s">%2$s</span>', esc_attr( $badge ), esc_html( (string) ( $s['wl_shared_badge'] ?? '' ) ) );
		}

		printf(
			'<div class="galaxie-wishlist-toolbar"><div class="galaxie-wishlist-title"><span class="galaxie-wishlist-list-name %1$s" data-name="%2$s">%3$s</span>%4$s</div><div class="galaxie-wishlist-toolbar-actions">%5$s%6$s%7$s</div></div>',
			esc_attr( PixfortControls::text_classes( $s, 'wl_list_name' ) ),
			esc_attr( (string) $list['name'] ),
			esc_html( (string) $list['name'] ),
			$badges, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			empty( $list['default'] ) ? $this->button( $s, 'wl_list_default', 'galaxie-wishlist-list-default' ) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$this->button( $s, 'wl_list_rename', 'galaxie-wishlist-list-rename' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$this->button( $s, 'wl_list_delete', 'galaxie-wishlist-list-delete' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
	}

	/** @param array<string,mixed> $s @param array<string,mixed> $list */
	private function render_share( array $s, array $list ): void {
		$shared = '' !== (string) $list['share'];
		$url    = Lists::share_url( $list );
		$body   = PixfortControls::text_classes( $s, 'wl_share_body' );
		$switch = static fn( string $class, bool $on, string $label ): string => sprintf(
			'<label class="galaxie-wishlist-switch %1$s"><input type="checkbox" class="%2$s"%3$s /><span>%4$s</span></label>',
			esc_attr( $body ),
			esc_attr( $class ),
			$on ? ' checked' : '',
			esc_html( $label )
		);

		$link = '';

		if ( $shared ) {
			$message = str_replace( array( '{name}', '{url}' ), array( (string) $list['name'], $url ), (string) ( $s['wl_share_message'] ?? '{url}' ) );
			$out     = static fn( string $prefix, string $href, string $class ) => sprintf(
				'<a class="galaxie-account-button %1$s" href="%2$s" target="_blank" rel="noopener">%3$s</a>',
				esc_attr( $class ),
				esc_url( $href, array( 'https', 'mailto' ) ),
				PixfortControls::render_button( $s, $prefix, (string) ( $s[ $prefix . '_text' ] ?? '' ) )
			);

			$link = sprintf(
				'<div class="galaxie-wishlist-share-link"><input type="text" readonly class="form-control galaxie-wishlist-share-url" value="%1$s" /><div class="galaxie-wishlist-share-actions">%2$s%3$s%4$s%5$s</div></div>%6$s',
				esc_attr( $url ),
				$this->button( $s, 'wl_share_copy', 'galaxie-wishlist-share-copy' ),
				$out( 'wl_share_whatsapp', 'https://wa.me/?text=' . rawurlencode( $message ), 'galaxie-wishlist-share-whatsapp' ),
				$out( 'wl_share_email', 'mailto:?subject=' . rawurlencode( (string) $list['name'] ) . '&body=' . rawurlencode( $message ), 'galaxie-wishlist-share-email' ),
				$this->button( $s, 'wl_share_renew', 'galaxie-wishlist-share-renew' ),
				$switch( 'galaxie-wishlist-gifts-toggle', ! empty( $list['gifts'] ), (string) ( $s['wl_gifts_toggle'] ?? '' ) )
			);
		}

		printf(
			'<div class="galaxie-wishlist-share card %1$s"><div class="galaxie-wishlist-share-title %2$s">%3$s</div><p class="galaxie-wishlist-share-body %4$s">%5$s</p>%6$s%7$s</div>',
			esc_attr( PixfortControls::surface_classes( $s, 'wl_share_box' ) ),
			esc_attr( PixfortControls::text_classes( $s, 'wl_share_title_text' ) ),
			esc_html( (string) ( $s['wl_share_title'] ?? '' ) ),
			esc_attr( $body ),
			esc_html( (string) ( $s['wl_share_note'] ?? '' ) ),
			$switch( 'galaxie-wishlist-share-toggle', $shared, (string) ( $s['wl_share_toggle'] ?? '' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
	}

	/** @param array<string,mixed> $s */
	private function card( array $s, \WC_Product $product, string $list_id, callable $text ): string {
		$simple = $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock();
		$buy    = $simple
			? AccountParts::link_button( $s, 'wl_buy', (string) ( $s['wl_buy_text'] ?? '' ), (string) $product->add_to_cart_url(), 'is-buy' )
			: AccountParts::link_button( $s, 'wl_options', (string) ( $s['wl_options_text'] ?? '' ), (string) $product->get_permalink(), 'is-options' );

		$badges = '';

		if ( 'yes' === ( $s['wl_show_sale'] ?? 'yes' ) && $product->is_on_sale() ) {
			$badges .= $this->badge( $s, 'sale', (string) ( $s['wl_sale_text'] ?? '' ) );
		}

		if ( 'yes' === ( $s['wl_show_stock'] ?? 'yes' ) && ! $product->is_in_stock() ) {
			$badges .= $this->badge( $s, 'stock', (string) ( $s['wl_stock_text'] ?? '' ) );
		}

		return sprintf(
			'<article class="galaxie-wishlist-item card %1$s" data-product-id="%2$d" data-list-id="%3$s"><a class="galaxie-wishlist-thumb %4$s" href="%5$s">%6$s%7$s</a><div class="galaxie-wishlist-body">%8$s%9$s</div><div class="galaxie-wishlist-actions">%10$s<button type="button" class="galaxie-account-submit galaxie-wishlist-remove" data-product-id="%2$d">%11$s</button></div></article>',
			esc_attr( PixfortControls::surface_classes( $s, 'wl_card' ) ),
			(int) $product->get_id(),
			esc_attr( $list_id ),
			esc_attr( PixfortControls::thumb_classes( $s, 'wl_thumb' ) ),
			esc_url( (string) $product->get_permalink() ),
			'' !== $badges ? '<span class="galaxie-wishlist-badges">' . $badges . '</span>' : '',
			$product->get_image( 'woocommerce_thumbnail' ),
			$text( 'wl_name', 'galaxie-wishlist-name', sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) $product->get_permalink() ), esc_html( $product->get_name() ) ) ),
			'yes' === ( $s['wl_show_price'] ?? 'yes' ) ? $text( 'wl_price', 'galaxie-wishlist-price', wp_kses_post( $product->get_price_html() ) ) : '',
			$buy,
			PixfortControls::render_button( $s, 'wl_remove', (string) ( $s['wl_remove_text'] ?? '' ) )
		);
	}

	/** @param array<string,mixed> $s */
	private function badge( array $s, string $type, string $label ): string {
		return '' === trim( $label ) ? '' : sprintf(
			'<span class="galaxie-wishlist-badge is-%1$s %2$s">%3$s</span>',
			esc_attr( $type ),
			esc_attr( trim( PixfortControls::text_classes( $s, 'wl_badge_' . $type . '_text' ) . ' ' . PixfortControls::surface_classes( $s, 'wl_badge_' . $type ) ) ),
			esc_html( $label )
		);
	}

	/** @param array<string,mixed> $s */
	private function empty_icon( array $s ): string {
		$icon = PixfortControls::icon_value( $s, 'wl_empty_icon' );

		if ( '' === $icon || ! class_exists( '\PixfortCore' ) ) {
			return '';
		}

		return '<span class="galaxie-wishlist-empty-icon">' . \PixfortCore::instance()->icons->getIcon( $icon, 48, '' ) . '</span>';
	}

	/** @param array<string,mixed> $s */
	private function button( array $s, string $prefix, string $class, string $type = 'button' ): string {
		return sprintf(
			'<button type="%1$s" class="galaxie-account-submit %2$s">%3$s</button>',
			esc_attr( $type ),
			esc_attr( $class ),
			PixfortControls::render_button( $s, $prefix, (string) ( $s[ $prefix . '_text' ] ?? '' ) )
		);
	}
}

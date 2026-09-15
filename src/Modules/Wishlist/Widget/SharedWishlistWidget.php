<?php
/**
 * "Galaxie Shared Wishlist": the list a link opens.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Wishlist\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Modules\Wishlist\Gifts;
use Galaxie\Woo\Modules\Wishlist\Lists;
use Galaxie\Woo\Modules\Wishlist\SharedPage;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * Whose list it is, what it is called, and its products — each one to buy for
 * yourself or, when the owner accepts gifts, to give. "Save this list" copies
 * it to the visitor's own lists.
 *
 * Drawn on `/lista/{secret}/` (see {@see SharedPage}). In the editor it shows
 * the designer's own default list, or a few products, to have something to
 * style.
 */
final class SharedWishlistWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-shared-wishlist';
	}

	public function get_title(): string {
		return __( 'Galaxie Shared Wishlist', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-heart';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'wishlist', 'shared', 'gift', 'lista', 'presente' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'sw_section', array( 'label' => __( 'Shared list', 'galaxie-woo' ) ) );

		$texts = array(
			'sw_owner'       => array( __( 'Owner line', 'galaxie-woo' ), __( 'Lista de {owner}', 'galaxie-woo' ), __( '{owner} is the first name of who shared it.', 'galaxie-woo' ) ),
			'sw_intro'       => array( __( 'Intro text', 'galaxie-woo' ), __( 'Escolha um produto para comprar para você ou para dar de presente.', 'galaxie-woo' ), '' ),
			'sw_gift_note'   => array( __( 'Gift note', 'galaxie-woo' ), __( 'Presentes são entregues no endereço de {owner}. Você não vê o endereço.', 'galaxie-woo' ), __( 'Shown when the list accepts gifts.', 'galaxie-woo' ) ),
			'sw_saved'       => array( __( '"List saved" message', 'galaxie-woo' ), __( 'Lista salva nas suas listas.', 'galaxie-woo' ), '' ),
			'sw_empty'       => array( __( 'Empty list text', 'galaxie-woo' ), __( 'Esta lista ainda não tem produtos.', 'galaxie-woo' ), '' ),
		);

		foreach ( $texts as $id => $text ) {
			$this->add_control( $id, array( 'label' => $text[0], 'type' => Controls_Manager::TEXTAREA, 'rows' => 2, 'default' => $text[1], 'description' => $text[2] ) );
		}

		$this->add_responsive_control(
			'sw_columns',
			array(
				'label'          => __( 'Columns', 'galaxie-woo' ),
				'type'           => Controls_Manager::SELECT,
				'options'        => array( '1' => '1', '2' => '2', '3' => '3', '4' => '4' ),
				'default'        => '3',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'separator'      => 'before',
				'selectors'      => array( '{{WRAPPER}} .galaxie-wishlist-grid' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
			)
		);

		$this->add_control( 'sw_show_price', array( 'label' => __( 'Price', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );

		$this->end_controls_section();

		$buttons = array(
			'sw_save'    => array( __( 'Header: save this list button', 'galaxie-woo' ), array( 'text' => __( 'Salvar esta lista', 'galaxie-woo' ), 'style' => 'outline', 'size' => 'sm', 'icon' => 'Line/pixfort-icon-heart-1' ) ),
			'sw_buy'     => array( __( 'Card: buy for me button', 'galaxie-woo' ), array( 'text' => __( 'Comprar pra mim', 'galaxie-woo' ), 'size' => 'sm' ) ),
			'sw_options' => array( __( 'Card: choose options button', 'galaxie-woo' ), array( 'text' => __( 'Escolher opções', 'galaxie-woo' ), 'style' => 'outline', 'size' => 'sm' ) ),
			'sw_gift'    => array( __( 'Card: give as a gift button', 'galaxie-woo' ), array( 'text' => __( 'Dar de presente', 'galaxie-woo' ), 'style' => 'outline', 'size' => 'sm' ) ),
		);

		foreach ( $buttons as $prefix => $button ) {
			$this->start_controls_section( $prefix . '_section', array( 'label' => $button[0] ) );
			PixfortControls::button( $this, $prefix, $button[1] );
			$this->end_controls_section();
		}

		$this->text_sections(
			array(
				'sw_owner_text' => array( __( 'Header: owner line', 'galaxie-woo' ), '.galaxie-shared-owner', array( 'size' => 'text-sm', 'bold' => '' ) ),
				'sw_name_text'  => array( __( 'Header: list name', 'galaxie-woo' ), '.galaxie-shared-name', array( 'size' => 'text-24', 'bold' => 'font-weight-bold' ) ),
				'sw_intro_text' => array( __( 'Header: intro and gift note', 'galaxie-woo' ), '.galaxie-shared-intro', array( 'bold' => '' ) ),
			)
		);

		$this->start_controls_section( 'sw_card_style', array( 'label' => __( 'Card: box and layout', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'sw_card', '{{WRAPPER}} .galaxie-wishlist-item', array( 'rounded' => 'rounded-lg' ) );

		foreach ( array( 'sw_gap' => array( __( 'Space between products', 'galaxie-woo' ), '.galaxie-wishlist-grid', 'gap' ), 'sw_actions_gap' => array( __( 'Space between buttons', 'galaxie-woo' ), '.galaxie-wishlist-actions', 'gap' ) ) as $id => $slider ) {
			$this->add_responsive_control( $id, array( 'label' => $slider[0], 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 60 ) ), 'selectors' => array( '{{WRAPPER}} ' . $slider[1] => $slider[2] . ': {{SIZE}}{{UNIT}};' ) ) );
		}

		$this->end_controls_section();

		$this->start_controls_section( 'sw_thumb_style', array( 'label' => __( 'Card: picture', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::thumb( $this, 'sw_thumb', '{{WRAPPER}} .galaxie-wishlist-thumb img', array( 'size_mode' => 'width' ), array(), '{{WRAPPER}} .galaxie-wishlist-thumb' );
		$this->end_controls_section();

		$this->text_sections(
			array(
				'sw_product_name' => array( __( 'Card: product name', 'galaxie-woo' ), '.galaxie-wishlist-name', array( 'bold' => 'font-weight-bold' ) ),
				'sw_price'        => array( __( 'Card: price', 'galaxie-woo' ), '.galaxie-wishlist-price', array( 'bold' => '' ) ),
				'sw_msg_text'     => array( __( 'Saved and error messages', 'galaxie-woo' ), '.galaxie-account-message', array( 'size' => 'text-sm', 'bold' => '' ) ),
			)
		);
	}

	/** @param array<string,array<int,mixed>> $texts */
	private function text_sections( array $texts ): void {
		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', array( 'position', 'inline' ) );
			$this->end_controls_section();
		}
	}

	protected function render(): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$s       = $this->get_settings_for_display();
		$shared  = SharedPage::current();
		$editing = AccountParts::editing();

		if ( ! $shared && $editing && get_current_user_id() ) {
			$shared = array( 'user_id' => get_current_user_id(), 'list' => Lists::default_list( get_current_user_id() ) );
		}

		if ( ! $shared ) {
			return;
		}

		Assets::enqueue();

		$list     = $shared['list'];
		$token    = (string) $list['share'];
		$gift     = '' !== $token ? Gifts::for_token( $token ) : null;
		// Never the display name: for an account made from an e-mail address, that is the e-mail.
		$name     = $gift['name'] ?? Gifts::owner_name( (int) $shared['user_id'] );
		$products = array_filter( array_map( 'wc_get_product', array_reverse( (array) $list['items'] ) ), static fn( $p ) => $p instanceof \WC_Product && $p->is_visible() );

		if ( ! $products && $editing ) {
			$products = wc_get_products( array( 'status' => 'publish', 'limit' => 3 ) );
		}

		$text  = static fn( string $prefix, string $class, string $html, string $tag = 'div' ): string => '' === trim( wp_strip_all_tags( $html ) ) ? '' : sprintf( '<%1$s class="%2$s %3$s">%4$s</%1$s>', $tag, esc_attr( $class ), esc_attr( PixfortControls::text_classes( $s, $prefix ) ), $html );
		$fill  = static fn( string $value ): string => esc_html( str_replace( '{owner}', $name, $value ) );

		printf(
			'<div class="galaxie-shared-wishlist" data-token="%1$s" data-saved="%2$s" data-msg-class="%3$s">',
			esc_attr( $token ),
			esc_attr( (string) ( $s['sw_saved'] ?? '' ) ),
			esc_attr( PixfortControls::text_classes( $s, 'sw_msg_text' ) )
		);

		printf(
			'<header class="galaxie-shared-header"><div class="galaxie-shared-heading">%1$s%2$s%3$s%4$s</div>%5$s</header>',
			$text( 'sw_owner_text', 'galaxie-shared-owner', $fill( (string) ( $s['sw_owner'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			$text( 'sw_name_text', 'galaxie-shared-name', esc_html( (string) $list['name'] ), 'h1' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			$text( 'sw_intro_text', 'galaxie-shared-intro', $fill( (string) ( $s['sw_intro'] ?? '' ) ), 'p' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			$gift ? $text( 'sw_intro_text', 'galaxie-shared-intro is-gift-note', $fill( (string) ( $s['sw_gift_note'] ?? '' ) ), 'p' ) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			get_current_user_id() !== (int) $shared['user_id'] || $editing ? $this->button( $s, 'sw_save', 'galaxie-shared-save' ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);

		echo '<div class="galaxie-account-message" role="status" aria-live="polite" hidden></div>';

		if ( ! $products ) {
			echo $text( 'sw_intro_text', 'galaxie-shared-intro is-empty', esc_html( (string) ( $s['sw_empty'] ?? '' ) ), 'p' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo '</div>';
			return;
		}

		echo '<div class="galaxie-wishlist-grid">';

		foreach ( $products as $product ) {
			echo $this->card( $s, $product, $gift ? $token : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}

		echo '</div></div>';
	}

	/** @param array<string,mixed> $s */
	private function card( array $s, \WC_Product $product, string $gift_token ): string {
		$simple = $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock();
		$link   = (string) $product->get_permalink();

		$buttons = $simple
			? AccountParts::link_button( $s, 'sw_buy', (string) ( $s['sw_buy_text'] ?? '' ), (string) $product->add_to_cart_url(), 'is-buy' )
			: AccountParts::link_button( $s, 'sw_options', (string) ( $s['sw_options_text'] ?? '' ), $link, 'is-options' );

		if ( '' !== $gift_token && $product->is_in_stock() ) {
			$gift_url = $simple
				? add_query_arg( array( 'add-to-cart' => $product->get_id(), Gifts::REQUEST_ARG => $gift_token ), wc_get_cart_url() )
				: add_query_arg( Gifts::REQUEST_ARG, $gift_token, $link );

			$buttons .= AccountParts::link_button( $s, 'sw_gift', (string) ( $s['sw_gift_text'] ?? '' ), $gift_url, 'is-gift' );
		}

		return sprintf(
			'<article class="galaxie-wishlist-item card %1$s"><a class="galaxie-wishlist-thumb %2$s" href="%3$s">%4$s</a><div class="galaxie-wishlist-body"><div class="galaxie-wishlist-name %5$s"><a href="%3$s">%6$s</a></div>%7$s</div><div class="galaxie-wishlist-actions">%8$s</div></article>',
			esc_attr( PixfortControls::surface_classes( $s, 'sw_card' ) ),
			esc_attr( PixfortControls::thumb_classes( $s, 'sw_thumb' ) ),
			esc_url( $link ),
			$product->get_image( 'woocommerce_thumbnail' ),
			esc_attr( PixfortControls::text_classes( $s, 'sw_product_name' ) ),
			esc_html( $product->get_name() ),
			'yes' === ( $s['sw_show_price'] ?? 'yes' ) ? '<div class="galaxie-wishlist-price ' . esc_attr( PixfortControls::text_classes( $s, 'sw_price' ) ) . '">' . wp_kses_post( $product->get_price_html() ) . '</div>' : '',
			$buttons
		);
	}

	/** @param array<string,mixed> $s */
	private function button( array $s, string $prefix, string $class ): string {
		return sprintf(
			'<button type="button" class="galaxie-account-submit %1$s">%2$s</button>',
			esc_attr( $class ),
			PixfortControls::render_button( $s, $prefix, (string) ( $s[ $prefix . '_text' ] ?? '' ) )
		);
	}
}

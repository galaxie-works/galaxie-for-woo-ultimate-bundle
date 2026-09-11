<?php
/**
 * "Galaxie Account Wishlist": the products the customer saved.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Wishlist\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Modules\Wishlist\Module as Wishlist;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * The saved products as a grid: picture, name, price, a way to buy and a way
 * to let go. Removing uses the same toggle as the heart on the product, so the
 * two can never disagree about what is on the list.
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
		return array( 'account', 'wishlist', 'favoritos', 'lista de desejos' );
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

		$this->add_control( 'wl_show_price', array( 'label' => __( 'Price', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'wl_remove_label', array( 'label' => __( 'Remove link', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Remover', 'galaxie-woo' ) ) );
		$this->add_control( 'wl_empty_label', array( 'label' => __( 'When the list is empty', 'galaxie-woo' ), 'type' => Controls_Manager::TEXTAREA, 'rows' => 2, 'default' => __( 'Sua lista está vazia. Toque no coração de um produto para guardá-lo aqui.', 'galaxie-woo' ), 'separator' => 'before' ) );

		$this->end_controls_section();

		$buttons = array(
			'wl_buy'     => array( __( 'Buy button', 'galaxie-woo' ), array( 'text' => __( 'Comprar', 'galaxie-woo' ), 'size' => 'sm' ) ),
			'wl_options' => array( __( 'Choose options button', 'galaxie-woo' ), array( 'text' => __( 'Escolher opções', 'galaxie-woo' ), 'style' => 'outline', 'size' => 'sm' ) ),
			'wl_shop'    => array( __( 'Empty list button', 'galaxie-woo' ), array( 'text' => __( 'Ver produtos', 'galaxie-woo' ) ) ),
		);

		foreach ( $buttons as $prefix => $button ) {
			$this->start_controls_section( $prefix . '_section', array( 'label' => $button[0] ) );
			PixfortControls::button( $this, $prefix, $button[1] );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'wl_card_style', array( 'label' => __( 'Product card', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'wl_card', '{{WRAPPER}} .galaxie-wishlist-item', array( 'rounded' => 'rounded-lg' ) );

		$this->add_responsive_control(
			'wl_gap',
			array(
				'label'      => __( 'Space between products', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-wishlist-grid' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'wl_thumb_style', array( 'label' => __( 'Picture', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::thumb( $this, 'wl_thumb', '{{WRAPPER}} .galaxie-wishlist-thumb img', array( 'size_mode' => 'width' ), array(), '{{WRAPPER}} .galaxie-wishlist-thumb' );
		$this->end_controls_section();

		$texts = array(
			'wl_heading_text' => array( __( 'Heading', 'galaxie-woo' ), '.galaxie-wishlist-heading', array( 'size' => 'text-20', 'bold' => 'font-weight-bold' ) ),
			'wl_name'         => array( __( 'Product name', 'galaxie-woo' ), '.galaxie-wishlist-name', array( 'bold' => 'font-weight-bold' ) ),
			'wl_price'        => array( __( 'Price', 'galaxie-woo' ), '.galaxie-wishlist-price', array( 'bold' => '' ) ),
			'wl_remove'       => array( __( 'Remove link', 'galaxie-woo' ), '.galaxie-wishlist-remove', array( 'size' => 'text-sm', 'bold' => '' ) ),
			'wl_empty'        => array( __( 'Empty list text', 'galaxie-woo' ), '.galaxie-wishlist-empty', array( 'bold' => '' ) ),
		);

		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', array( 'position' ) );
			$this->end_controls_section();
		}
	}

	protected function render(): void {
		if ( ! function_exists( 'wc_get_product' ) || ( ! is_user_logged_in() && ! AccountParts::editing() ) ) {
			return;
		}

		Assets::enqueue();

		$s        = $this->get_settings_for_display();
		$products = array_filter( array_map( 'wc_get_product', array_reverse( Wishlist::items() ) ), static fn( $p ) => $p instanceof \WC_Product && $p->is_visible() );
		$text     = static fn( string $prefix, string $class, string $html, string $tag = 'div' ): string => sprintf( '<%1$s class="%2$s %3$s">%4$s</%1$s>', $tag, esc_attr( $class ), esc_attr( PixfortControls::text_classes( $s, $prefix ) ), $html );

		echo '<div class="galaxie-account-wishlist">';

		$heading = trim( (string) ( $s['wl_heading'] ?? '' ) );

		if ( '' !== $heading ) {
			printf( '<div class="galaxie-wishlist-heading">%s</div>', PixfortControls::render_text( $s, 'wl_heading_text', esc_html( $heading ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		printf(
			'<div class="galaxie-wishlist-empty-box"%1$s>%2$s%3$s</div>',
			$products ? ' hidden' : '',
			$text( 'wl_empty', 'galaxie-wishlist-empty', esc_html( (string) ( $s['wl_empty_label'] ?? '' ) ), 'p' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			AccountParts::link_button( $s, 'wl_shop', (string) ( $s['wl_shop_text'] ?? '' ), (string) wc_get_page_permalink( 'shop' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);

		if ( $products ) {
			echo '<div class="galaxie-wishlist-grid">';

			foreach ( $products as $product ) {
				$simple = $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock();
				$buy    = $simple
					? AccountParts::link_button( $s, 'wl_buy', (string) ( $s['wl_buy_text'] ?? '' ), (string) $product->add_to_cart_url(), 'is-buy' )
					: AccountParts::link_button( $s, 'wl_options', (string) ( $s['wl_options_text'] ?? '' ), (string) $product->get_permalink(), 'is-options' );

				printf(
					'<article class="galaxie-wishlist-item card %1$s" data-product-id="%2$d"><a class="galaxie-wishlist-thumb %3$s" href="%4$s">%5$s</a><div class="galaxie-wishlist-body">%6$s%7$s</div><div class="galaxie-wishlist-actions">%8$s<button type="button" class="galaxie-wishlist-remove %9$s" data-product-id="%2$d">%10$s</button></div></article>',
					esc_attr( PixfortControls::surface_classes( $s, 'wl_card' ) ),
					(int) $product->get_id(),
					esc_attr( PixfortControls::thumb_classes( $s, 'wl_thumb' ) ),
					esc_url( (string) $product->get_permalink() ),
					$product->get_image( 'woocommerce_thumbnail' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own image markup.
					$text( 'wl_name', 'galaxie-wishlist-name', sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) $product->get_permalink() ), esc_html( $product->get_name() ) ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					'yes' === ( $s['wl_show_price'] ?? 'yes' ) ? $text( 'wl_price', 'galaxie-wishlist-price', wp_kses_post( $product->get_price_html() ) ) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					$buy, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
					esc_attr( PixfortControls::text_classes( $s, 'wl_remove' ) ),
					esc_html( (string) ( $s['wl_remove_label'] ?? '' ) )
				);
			}

			echo '</div>';
		}

		echo '</div>';
	}
}

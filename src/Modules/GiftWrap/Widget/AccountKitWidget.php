<?php
/**
 * "Galaxie Account Kit": the kit being built, on My Account's dashboard.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Modules\GiftWrap\Module;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\CartParts;
use Galaxie\Woo\Support\Dialog;
use Galaxie\Woo\Support\GiftKit;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * A card for the account dashboard: the kit the shopper left half-built, with
 * what is already in it, how much room is left and the way back into it.
 *
 * The Kit Progress widget says the same thing in one line, for a header or a
 * sidebar. This one is the Painel's version: the items with their pictures,
 * the total, and the actions that only make sense once — continue, see the
 * kit, add it to the cart, discard it. It also answers for the kit an account
 * kept at login and never took back, which until now only the popup offered.
 *
 * Printed hidden with its texts only (the page is cached), then filled by
 * account-kit.ts from the kit endpoint and kept in step with every change made
 * anywhere on the page. Without a kit it stays hidden, or shows an invitation.
 */
final class AccountKitWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account-kit';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Kit', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-gift';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'account_kit_section', array( 'label' => __( 'Kit being built', 'galaxie-woo' ) ) );

		$this->add_control(
			'account_kit_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Follows the kit the shopper is building, wherever they left it. It opens the popup set in wp-admin → Galaxie → Gift Wrap ("Popup do kit"), and the room-left sentence ({room}) is set there too.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'heading_text',
			array(
				'label'       => __( 'Heading', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Kit em montagem', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'message',
			array(
				'label'       => __( 'Line under the heading', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( '{kit} · {room}', 'galaxie-woo' ),
				'description' => __( '{kit}: the kit\'s name. {room}: "Ainda cabem …" or "Caixa completa! 🎉". {combos}: the bare list. {preço}: the kit\'s total.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'show_items',
			array(
				'label'        => __( 'List what is in the kit', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before',
			)
		);

		$items = array( 'show_items' => 'yes' );

		$this->add_control(
			'items_max',
			array(
				'label'       => __( 'At most', 'galaxie-woo' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 20,
				'default'     => 4,
				'description' => __( 'The rest are counted in a last line ("e mais 2").', 'galaxie-woo' ),
				'condition'   => $items,
			)
		);

		$this->add_control(
			'items_more',
			array(
				'label'       => __( 'That last line', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'e mais {n}', 'galaxie-woo' ),
				'description' => __( '{n}: how many are not shown.', 'galaxie-woo' ),
				'condition'   => $items,
			)
		);

		$this->add_control(
			'box_label',
			array(
				'label'       => __( 'The box line', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Caixa: {box}', 'galaxie-woo' ),
				'description' => __( '{box}: the chosen box. Empty to leave it out.', 'galaxie-woo' ),
				'separator'   => 'before',
			)
		);

		$this->add_control(
			'total_label',
			array(
				'label'       => __( 'Total label', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Total do kit', 'galaxie-woo' ),
				'description' => __( 'Empty to leave the total out.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'show_bar',
			array(
				'label'        => __( 'Show the fill bar', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'buttons_heading',
			array(
				'label'     => __( 'Buttons', 'galaxie-woo' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'continue_text',
			array(
				'label'       => __( 'Continue', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Continuar montando', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'view_text',
			array(
				'label'       => __( 'See the kit', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Ver kit', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'cart_text',
			array(
				'label'       => __( 'Add to cart (shown when the box is full)', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Adicionar kit ao carrinho', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'discard_text',
			array(
				'label'       => __( 'Discard', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Descartar kit', 'galaxie-woo' ),
				'description' => __( 'Empty to leave it out. It always asks first.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'previous_heading',
			array(
				'label'       => __( 'A kit kept at login', 'galaxie-woo' ),
				'type'        => Controls_Manager::HEADING,
				'separator'   => 'before',
			)
		);

		$this->add_control(
			'previous_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'When someone builds a kit signed out and signs in with another kit already open, the account\'s kit is kept aside. Until now only the popup offered it back.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'previous_text',
			array(
				'label'       => __( 'Line', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Você tem um kit guardado: {kit} ({n} itens).', 'galaxie-woo' ),
				'description' => __( '{kit}: its name. {n}: how many items it holds. Empty to leave this out.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'previous_button',
			array(
				'label'       => __( 'Button', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Recuperar kit', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'show_invite',
			array(
				'label'        => __( 'With no kit: show an invitation', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before',
				'description'  => __( 'Off: the card stays hidden until a kit is started.', 'galaxie-woo' ),
			)
		);

		$invite = array( 'show_invite' => 'yes' );

		$this->add_control(
			'invite_text',
			array(
				'label'       => __( 'Invitation', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Monte um kit de presente e nós enviamos pronto.', 'galaxie-woo' ),
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

		PixfortControls::icon_select( $this, 'icon', __( 'Icon beside the heading', 'galaxie-woo' ), 'Line/pixfort-icon-gift-1' );

		$this->add_control(
			'editor_state',
			array(
				'label'     => __( 'Show in the editor', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'kit',
				'options'   => array(
					'kit'      => __( 'A kit with room left', 'galaxie-woo' ),
					'full'     => __( 'A full kit', 'galaxie-woo' ),
					'previous' => __( 'A kit kept at login', 'galaxie-woo' ),
					'invite'   => __( 'No kit (invitation)', 'galaxie-woo' ),
				),
				'separator' => 'before',
			)
		);

		$this->end_controls_section();

		Dialog::controls(
			$this,
			'account_kit_discard',
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
			'account_kit_restore',
			array(
				'label' => __( 'Recover kept kit dialog', 'galaxie-woo' ),
				'title' => __( 'Recuperar kit', 'galaxie-woo' ),
				'text'  => __( 'Isso troca o kit que você está montando pelo kit guardado. Tudo bem?', 'galaxie-woo' ),
				'yes'   => __( 'Sim, recuperar', 'galaxie-woo' ),
				'no'    => __( 'Cancelar', 'galaxie-woo' ),
			)
		);

		// ---------------------------------------------------------- style

		$style = static fn( string $label ): array => array(
			'label' => $label,
			'tab'   => Controls_Manager::TAB_STYLE,
		);

		$this->start_controls_section( 'account_kit_box_style', $style( __( 'Card', 'galaxie-woo' ) ) );
		PixfortControls::surface( $this, 'card_box', '{{WRAPPER}} .galaxie-account-kit' );
		$this->add_responsive_control(
			'card_gap',
			array(
				'label'      => __( 'Space between the parts', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-account-kit' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'icon_size',
			array(
				'label'      => __( 'Icon size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 10, 'max' => 64 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-account-kit-icon' => 'font-size: {{SIZE}}{{UNIT}};' ),
			)
		);
		PixfortControls::icon_color( $this, 'icon_color', __( 'Icon colour', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-account-kit-icon' );
		$this->end_controls_section();

		$this->start_controls_section( 'account_kit_text_style', $style( __( 'Heading and lines', 'galaxie-woo' ) ) );
		$this->heading( 'heading_style', __( 'Heading', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'heading', array( 'size' => 'text-lg', 'bold' => 'bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-account-kit-heading', 'text', array( 'inline', 'position' ) );
		$this->heading( 'line_style', __( 'The line under it, and the invitation', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'line', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-account-kit-line-text', 'text', array( 'inline', 'position' ) );
		$this->heading( 'meta_style', __( 'Box, total and the kept kit', 'galaxie-woo' ) );
		PixfortControls::text( $this, 'meta', array( 'size' => 'text-sm', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-account-kit-meta', 'text', array( 'inline', 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'account_kit_items_style', $style( __( 'What is in the kit', 'galaxie-woo' ) ) );
		PixfortControls::text( $this, 'item', array( 'size' => 'text-sm', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-account-kit-item-name', 'text', array( 'inline', 'position' ) );
		PixfortControls::thumb( $this, 'item_thumb', '{{WRAPPER}} .galaxie-account-kit-thumb' );
		$this->add_responsive_control(
			'items_gap',
			array(
				'label'      => __( 'Space between items', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-account-kit-items' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section( 'account_kit_bar_style', $style( __( 'Fill bar', 'galaxie-woo' ) ) );
		PixfortControls::progress(
			$this,
			'card_bar',
			'{{WRAPPER}} .galaxie-account-kit-track',
			'{{WRAPPER}} .galaxie-account-kit-fill',
			'{{WRAPPER}} .galaxie-account-kit.is-full .galaxie-account-kit-fill'
		);
		$this->end_controls_section();

		$this->start_controls_section( 'account_kit_continue_style', $style( __( 'Button · Continuar', 'galaxie-woo' ) ) );
		PixfortControls::button( $this, 'btn_continue', array( 'color' => 'primary', 'size' => 'sm', 'icon' => 'Line/pixfort-icon-gift-1' ), array(), '{{WRAPPER}}', array( 'text' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'account_kit_view_style', $style( __( 'Button · Ver kit', 'galaxie-woo' ) ) );
		PixfortControls::button( $this, 'btn_view', array( 'style' => 'link', 'color' => 'primary', 'size' => 'sm', 'remove_padding' => 'no-padding', 'icon' => 'Line/pixfort-icon-eye-visibility-1' ), array(), '{{WRAPPER}}', array( 'text' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'account_kit_cart_style', $style( __( 'Button · Adicionar ao carrinho', 'galaxie-woo' ) ) );
		PixfortControls::button( $this, 'btn_cart', array( 'color' => 'primary', 'size' => 'sm', 'icon' => 'Line/pixfort-icon-bag-1' ), array(), '{{WRAPPER}}', array( 'text' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'account_kit_discard_style', $style( __( 'Button · Descartar', 'galaxie-woo' ) ) );
		PixfortControls::button( $this, 'btn_discard', array( 'style' => 'link', 'color' => 'danger', 'size' => 'sm', 'remove_padding' => 'no-padding', 'icon' => 'Line/pixfort-icon-cross-circle-1' ), array(), '{{WRAPPER}}', array( 'text' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'account_kit_restore_style', $style( __( 'Button · Recuperar kit', 'galaxie-woo' ) ) );
		PixfortControls::button( $this, 'btn_restore', array( 'style' => 'outline', 'color' => 'primary', 'size' => 'sm', 'icon' => 'Line/pixfort-icon-clock-1' ), array(), '{{WRAPPER}}', array( 'text' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'account_kit_invite_style', $style( __( 'Button · Montar um kit', 'galaxie-woo' ) ) );
		PixfortControls::button( $this, 'btn_invite', array( 'color' => 'primary', 'size' => 'sm', 'icon' => 'Line/pixfort-icon-gift-1' ), array(), '{{WRAPPER}}', array( 'text' ) );
		$this->end_controls_section();
	}

	/** A heading inside a style section. */
	private function heading( string $id, string $label ): void {
		$this->add_control(
			$id,
			array(
				'label'     => $label,
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
	}

	protected function render(): void {
		Assets::enqueue();
		Assets::enqueue_kit();

		$settings = $this->get_settings_for_display();
		$editing  = class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode();
		$preview  = $editing ? (string) ( $settings['editor_state'] ?? 'kit' ) : '';
		$full     = 'full' === $preview;
		$sample   = in_array( $preview, array( 'kit', 'full' ), true );

		$texts = array(
			'message'  => (string) ( $settings['message'] ?? '' ),
			'more'     => (string) ( $settings['items_more'] ?? '' ),
			'box'      => (string) ( $settings['box_label'] ?? '' ),
			// The label travels here, not in the markup: live it is printed
			// empty (a cached page says nothing of the visitor's kit), and a
			// label read back out of an empty node is no label at all.
			'total'    => (string) ( $settings['total_label'] ?? '' ),
			'previous' => (string) ( $settings['previous_text'] ?? '' ),
		);

		printf(
			'<div class="galaxie-account-kit %1$s%2$s" data-galaxie-account-kit data-texts="%3$s" data-items="%4$s" data-max="%5$d" data-invite="%6$s"%7$s>',
			esc_attr( PixfortControls::surface_classes( $settings, 'card_box' ) ),
			$full ? ' is-full' : '',
			esc_attr( (string) wp_json_encode( $texts ) ),
			'yes' === ( $settings['show_items'] ?? 'yes' ) ? '1' : '0',
			max( 1, (int) ( $settings['items_max'] ?? 4 ) ),
			'yes' === ( $settings['show_invite'] ?? 'yes' ) ? '1' : '0',
			$editing ? ' data-sample="1"' : ' hidden'
		);

		// ------------------------------------------------------- the heading
		echo '<div class="galaxie-account-kit-head">';

		$icon = PixfortControls::icon_value( $settings, 'icon' );

		if ( '' !== $icon && PixfortControls::available() ) {
			printf( '<span class="galaxie-account-kit-icon">%s</span>', CartParts::icon_markup( $icon ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own SVG.
		}

		printf(
			'<div class="galaxie-account-kit-heading">%s</div>',
			PixfortControls::render_text( $settings, 'heading', GiftKit::html( (string) ( $settings['heading_text'] ?? '' ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's element around text through wp_kses().
		);

		echo '</div>';

		// ------------------------------------------------------- with a kit
		printf( '<div class="galaxie-account-kit-state" data-kit-state%s>', $sample ? '' : ' hidden' );

		$line = $full
			? __( 'Kit 1 · Caixa completa! 🎉', 'galaxie-woo' )
			: Module::nouns( __( 'Kit 1 · Ainda cabem 1 × 190g ou 2 × 50g', 'galaxie-woo' ) );

		printf(
			'<div class="galaxie-account-kit-line-text">%s</div>',
			PixfortControls::render_text( $settings, 'line', '<span data-slot="line">' . GiftKit::html( $sample ? $line : '' ) . '</span>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's element around text through wp_kses().
		);

		if ( 'yes' === ( $settings['show_items'] ?? 'yes' ) ) {
			printf( '<div class="galaxie-account-kit-items" data-kit-items>%s</div>', $sample ? $this->item( $settings, Module::nouns( __( 'Exemplo: {noun}', 'galaxie-woo' ) ), 2 ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in item().
			printf( '<template data-kit-tpl="item">%s</template>', $this->item( $settings ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in item().
		}

		if ( 'yes' === ( $settings['show_bar'] ?? 'yes' ) ) {
			printf(
				'<div class="galaxie-account-kit-track"><div class="galaxie-account-kit-fill" data-slot="bar" style="width:%d%%"></div></div>',
				$full ? 100 : ( $sample ? 60 : 0 )
			);
		}

		printf(
			'<div class="galaxie-account-kit-meta"><span data-kit-box%1$s>%2$s</span><span data-kit-total%3$s>%4$s</span></div>',
			'' !== $texts['box'] && $sample ? '' : ' hidden',
			esc_html( $sample ? str_replace( '{box}', __( 'Caixa P', 'galaxie-woo' ), $texts['box'] ) : '' ),
			'' !== (string) ( $settings['total_label'] ?? '' ) && $sample ? '' : ' hidden',
			esc_html( $sample ? (string) ( $settings['total_label'] ?? '' ) . ': R$ 129,00' : '' )
		);

		echo '<div class="galaxie-account-kit-actions">';
		echo $this->button( $settings, 'btn_continue', 'continue', (string) ( $settings['continue_text'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		printf( '<span data-kit-when="full"%s>', $full ? '' : ' hidden' );
		echo $this->button( $settings, 'btn_cart', 'to-cart', (string) ( $settings['cart_text'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</span>';
		echo $this->button( $settings, 'btn_view', 'view', (string) ( $settings['view_text'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().

		if ( '' !== trim( (string) ( $settings['discard_text'] ?? '' ) ) ) {
			echo $this->button( $settings, 'btn_discard', 'discard', (string) $settings['discard_text'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		}

		echo '</div>';
		echo '</div>';

		// ------------------------------------------------ a kit kept at login
		printf( '<div class="galaxie-account-kit-previous" data-kit-previous%s>', 'previous' === $preview ? '' : ' hidden' );
		printf(
			'<div class="galaxie-account-kit-meta" data-slot="previous">%s</div>',
			esc_html( 'previous' === $preview ? str_replace( array( '{kit}', '{n}' ), array( __( 'Kit 2', 'galaxie-woo' ), '3' ), $texts['previous'] ) : '' )
		);
		echo $this->button( $settings, 'btn_restore', 'restore', (string) ( $settings['previous_button'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</div>';

		// ------------------------------------------------------- without one
		printf( '<div class="galaxie-account-kit-invite" data-kit-invite%s>', 'invite' === $preview ? '' : ' hidden' );
		printf(
			'<div class="galaxie-account-kit-line-text">%s</div>',
			PixfortControls::render_text( $settings, 'line', GiftKit::html( (string) ( $settings['invite_text'] ?? '' ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's element around text through wp_kses().
		);
		echo $this->button( $settings, 'btn_invite', 'open', (string) ( $settings['invite_button'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		echo '</div>';

		echo Dialog::render( $settings, 'account_kit_discard' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo Dialog::render( $settings, 'account_kit_restore' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		echo '</div>';
	}

	/**
	 * One line of what is in the kit: the picture, the name and how many.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @param string              $name     Sample name, empty for the template.
	 * @param int                 $qty      Sample quantity.
	 */
	private function item( array $settings, string $name = '', int $qty = 1 ): string {
		return sprintf(
			'<div class="galaxie-account-kit-item" data-kit-item><img class="galaxie-account-kit-thumb %1$s" alt="" data-slot="image" hidden /><span class="galaxie-account-kit-item-name %2$s" data-slot="name">%3$s</span><span class="galaxie-account-kit-item-qty %2$s" data-slot="qty">%4$s</span></div>',
			esc_attr( PixfortControls::thumb_classes( $settings, 'item_thumb' ) ),
			esc_attr( PixfortControls::text_classes( $settings, 'item' ) ),
			esc_html( $name ),
			'' === $name ? '' : esc_html( '× ' . $qty )
		);
	}

	/**
	 * @param array<string,mixed> $settings Widget settings.
	 * @param string              $prefix   Button control prefix.
	 * @param string              $action   What the script does with it.
	 * @param string              $text     Label.
	 */
	private function button( array $settings, string $prefix, string $action, string $text ): string {
		if ( '' === trim( $text ) ) {
			return '';
		}

		return sprintf(
			'<button type="button" class="galaxie-buybox-btn galaxie-account-kit-btn" data-kit-action="%1$s">%2$s</button>',
			esc_attr( $action ),
			PixfortControls::render_button( $settings, $prefix, $text )
		);
	}
}

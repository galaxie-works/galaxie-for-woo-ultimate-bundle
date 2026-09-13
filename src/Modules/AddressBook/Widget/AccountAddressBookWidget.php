<?php
/**
 * "Galaxie Account Address Book": every address the customer keeps.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\AddressBook\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\AddressBook;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * A card per address, with its defaults marked, and one form that opens in
 * place to add or edit — nothing here leaves the page. Every change is an AJAX
 * call answered with the whole list, which the script redraws from the
 * `<template>` card this widget prints, so a redrawn card carries exactly the
 * classes the Style tab chose.
 */
final class AccountAddressBookWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account-address-book';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Address Book', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-map-pin';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'address', 'endereços', 'address book', 'agenda' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'ab_section', array( 'label' => __( 'Address book', 'galaxie-woo' ) ) );

		$this->add_control( 'ab_heading', array( 'label' => __( 'Heading', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Meus endereços', 'galaxie-woo' ) ) );
		$this->add_control( 'ab_intro', array( 'label' => __( 'Intro text', 'galaxie-woo' ), 'type' => Controls_Manager::TEXTAREA, 'rows' => 2, 'default' => __( 'Guarde os endereços que você usa e escolha qual vale para entrega e para cobrança.', 'galaxie-woo' ) ) );
		$this->add_control( 'ab_empty_text', array( 'label' => __( 'When there are no addresses', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Você ainda não cadastrou nenhum endereço.', 'galaxie-woo' ) ) );

		$this->add_control( 'ab_badge_shipping', array( 'label' => __( 'Shipping default badge', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Padrão para entrega', 'galaxie-woo' ), 'separator' => 'before' ) );
		$this->add_control( 'ab_badge_billing', array( 'label' => __( 'Billing default badge', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Padrão para cobrança', 'galaxie-woo' ) ) );

		$this->add_control( 'ab_form_new', array( 'label' => __( 'Form title when adding', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Novo endereço', 'galaxie-woo' ), 'separator' => 'before' ) );
		$this->add_control( 'ab_form_edit', array( 'label' => __( 'Form title when editing', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Editar endereço', 'galaxie-woo' ) ) );
		$this->add_control( 'ab_label_field', array( 'label' => __( 'Nickname field label', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Apelido', 'galaxie-woo' ), 'description' => __( 'WooCommerce adds "(optional)" by itself.', 'galaxie-woo' ) ) );
		$this->add_control( 'ab_label_placeholder', array( 'label' => __( 'Nickname placeholder', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Ex.: Casa, Trabalho', 'galaxie-woo' ) ) );

		$this->add_control( 'ab_saved', array( 'label' => __( 'Saved message', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Endereços atualizados.', 'galaxie-woo' ), 'separator' => 'before' ) );
		$this->add_control( 'ab_confirm', array( 'label' => __( 'Delete confirmation', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Excluir este endereço?', 'galaxie-woo' ) ) );

		$this->add_responsive_control(
			'ab_columns',
			array(
				'label'          => __( 'Columns', 'galaxie-woo' ),
				'type'           => Controls_Manager::SELECT,
				'options'        => array( '1' => '1', '2' => '2', '3' => '3' ),
				'default'        => '2',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'selectors'      => array( '{{WRAPPER}} .galaxie-ab-cards' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
				'separator'      => 'before',
			)
		);

		$this->end_controls_section();

		$buttons = array(
			'ab_add'          => array( __( 'Add button', 'galaxie-woo' ), array( 'text' => __( 'Adicionar endereço', 'galaxie-woo' ), 'size' => 'sm' ) ),
			'ab_edit'         => array( __( 'Edit button', 'galaxie-woo' ), array( 'text' => __( 'Editar', 'galaxie-woo' ), 'style' => 'outline', 'size' => 'sm' ) ),
			'ab_delete'       => array( __( 'Delete button', 'galaxie-woo' ), array( 'text' => __( 'Excluir', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
			'ab_ship_default' => array( __( 'Use for shipping button', 'galaxie-woo' ), array( 'text' => __( 'Usar para entrega', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
			'ab_bill_default' => array( __( 'Use for billing button', 'galaxie-woo' ), array( 'text' => __( 'Usar para cobrança', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
			'ab_save'         => array( __( 'Save button', 'galaxie-woo' ), array( 'text' => __( 'Salvar endereço', 'galaxie-woo' ) ) ),
			'ab_cancel'       => array( __( 'Cancel button', 'galaxie-woo' ), array( 'text' => __( 'Cancelar', 'galaxie-woo' ), 'style' => 'link' ) ),
		);

		foreach ( $buttons as $prefix => $button ) {
			$this->start_controls_section( $prefix . '_section', array( 'label' => $button[0] ) );
			PixfortControls::button( $this, $prefix, $button[1] );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'ab_card_style', array( 'label' => __( 'Cards', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'ab_card', '{{WRAPPER}} .galaxie-ab-card, {{WRAPPER}} .galaxie-ab-form', array( 'rounded' => 'rounded-lg' ) );

		$this->add_responsive_control(
			'ab_gap',
			array(
				'label'      => __( 'Space between cards', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-ab-cards' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'ab_box_style', array( 'label' => __( 'Address box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'ab_box', '{{WRAPPER}} .galaxie-ab-address' );
		$this->end_controls_section();

		$this->start_controls_section( 'ab_badge_style', array( 'label' => __( 'Default badges', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'ab_badge', '{{WRAPPER}} .galaxie-ab-badge', array( 'rounded' => 'rounded-pill' ) );
		$this->end_controls_section();

		$texts = array(
			'ab_heading_text' => array( __( 'Heading', 'galaxie-woo' ), '.galaxie-ab-heading', array( 'size' => 'text-20', 'bold' => 'font-weight-bold' ), true ),
			'ab_intro_text'   => array( __( 'Intro text', 'galaxie-woo' ), '.galaxie-ab-intro', array( 'bold' => '' ), true ),
			'ab_label_text'   => array( __( 'Nicknames', 'galaxie-woo' ), '.galaxie-ab-label', array( 'size' => 'text-18', 'bold' => 'font-weight-bold' ) ),
			'ab_address_text' => array( __( 'Address text', 'galaxie-woo' ), '.galaxie-ab-address', array( 'bold' => '' ) ),
			'ab_badge_text'   => array( __( 'Badge text', 'galaxie-woo' ), '.galaxie-ab-badge', array( 'size' => 'text-xs', 'bold' => 'font-weight-bold' ) ),
			'ab_empty_body'   => array( __( 'Empty list text', 'galaxie-woo' ), '.galaxie-ab-empty', array( 'bold' => '' ) ),
			'ab_form_title'   => array( __( 'Form title', 'galaxie-woo' ), '.galaxie-ab-form-title', array( 'size' => 'text-18', 'bold' => 'font-weight-bold' ) ),
			'ab_field_label'  => array( __( 'Form labels', 'galaxie-woo' ), '.galaxie-ab-form label', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ) ),
			'ab_msg_text'     => array( __( 'Messages', 'galaxie-woo' ), '.galaxie-account-message', array( 'size' => 'text-sm', 'bold' => '' ) ),
		);

		// A fourth `true` marks text drawn by pixfort's Text element; the rest are
		// classes on this widget's own markup, which carry fewer controls.
		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', empty( $text[3] ) ? array( 'position', 'inline' ) : array( 'position' ) );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'ab_field_style', array( 'label' => __( 'Form fields', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		PixfortControls::surface( $this, 'ab_field', '{{WRAPPER}} .galaxie-ab-form .form-control' );

		$this->add_responsive_control(
			'ab_field_gap',
			array(
				'label'      => __( 'Space between fields', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-ab-form .galaxie-address-fields' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		PixfortControls::palette_control( $this, 'ab_required', __( 'Required marker', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-ab-form .required', 'color' );

		$this->end_controls_section();
	}

	protected function render(): void {
		if ( ! function_exists( 'WC' ) || ( ! is_user_logged_in() && ! AccountParts::editing() ) ) {
			return;
		}

		Assets::enqueue();
		wp_enqueue_script( 'wc-country-select' );
		wp_enqueue_script( 'wc-address-i18n' );

		$s       = $this->get_settings_for_display();
		$user_id = get_current_user_id();
		$entries = $user_id ? AddressBook::for_js( $user_id ) : array();

		// Something to style in the editor, where the account may have no addresses.
		if ( ! $entries && AccountParts::editing() ) {
			$entries = array(
				array(
					'id'        => 'sample',
					'label'     => __( 'Casa', 'galaxie-woo' ),
					'formatted' => 'Maria Silva<br/>Rua Exemplo, 123<br/>São Paulo<br/>SP<br/>01000-000',
					'values'    => array(),
					'shipping'  => true,
					'billing'   => true,
				),
			);
		}

		printf(
			'<div class="galaxie-address-book" data-entries="%1$s" data-msg-class="%2$s" data-saved="%3$s" data-confirm="%4$s">',
			esc_attr( (string) wp_json_encode( $entries ) ),
			esc_attr( PixfortControls::text_classes( $s, 'ab_msg_text' ) ),
			esc_attr( (string) ( $s['ab_saved'] ?? '' ) ),
			esc_attr( (string) ( $s['ab_confirm'] ?? '' ) )
		);

		$heading = trim( (string) ( $s['ab_heading'] ?? '' ) );
		$intro   = trim( (string) ( $s['ab_intro'] ?? '' ) );

		if ( '' !== $heading ) {
			printf( '<div class="galaxie-ab-heading">%s</div>', PixfortControls::render_text( $s, 'ab_heading_text', esc_html( $heading ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		if ( '' !== $intro ) {
			printf( '<div class="galaxie-ab-intro">%s</div>', PixfortControls::render_text( $s, 'ab_intro_text', esc_html( $intro ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		echo '<div class="galaxie-account-message" role="status" aria-live="polite" hidden></div>';

		printf(
			'<p class="galaxie-ab-empty %1$s"%2$s>%3$s</p>',
			esc_attr( PixfortControls::text_classes( $s, 'ab_empty_body' ) ),
			$entries ? ' hidden' : '',
			esc_html( (string) ( $s['ab_empty_text'] ?? '' ) )
		);

		printf( '<div class="galaxie-ab-cards"%s>', $entries ? '' : ' hidden' );

		foreach ( $entries as $entry ) {
			echo $this->card( $s, $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}

		echo '</div>';

		printf( '<div class="galaxie-ab-toolbar">%s</div>', $this->button( $s, 'ab_add', 'galaxie-ab-add' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		$this->render_form( $s );

		printf( '<template class="galaxie-ab-card-template">%s</template>', $this->card( $s, null ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		echo '</div>';
	}

	/**
	 * One card. With no entry it is the blank the script fills in, so the
	 * markup a redraw produces can never drift from the markup printed here.
	 *
	 * @param array<string,mixed>      $s
	 * @param array<string,mixed>|null $entry
	 */
	private function card( array $s, ?array $entry ): string {
		$label    = (string) ( $entry['label'] ?? '' );
		$shipping = ! empty( $entry['shipping'] );
		$billing  = ! empty( $entry['billing'] );
		$hide     = static fn( bool $hidden ): string => $hidden ? ' hidden' : '';
		$badge    = trim( PixfortControls::text_classes( $s, 'ab_badge_text' ) . ' ' . PixfortControls::surface_classes( $s, 'ab_badge' ) );

		return sprintf(
			'<article class="galaxie-ab-card card %1$s" data-id="%2$s"><header class="galaxie-ab-card-head"><span class="galaxie-ab-label %3$s"%4$s>%5$s</span><span class="galaxie-ab-badges"><span class="galaxie-ab-badge is-shipping %6$s"%7$s>%8$s</span><span class="galaxie-ab-badge is-billing %6$s"%9$s>%10$s</span></span></header><address class="galaxie-ab-address %11$s">%12$s</address><div class="galaxie-ab-card-actions">%13$s%14$s%15$s%16$s</div></article>',
			esc_attr( PixfortControls::surface_classes( $s, 'ab_card' ) ),
			esc_attr( (string) ( $entry['id'] ?? '' ) ),
			esc_attr( PixfortControls::text_classes( $s, 'ab_label_text' ) ),
			$hide( '' === $label ),
			esc_html( $label ),
			esc_attr( $badge ),
			$hide( ! $shipping ),
			esc_html( (string) ( $s['ab_badge_shipping'] ?? '' ) ),
			$hide( ! $billing ),
			esc_html( (string) ( $s['ab_badge_billing'] ?? '' ) ),
			esc_attr( trim( PixfortControls::text_classes( $s, 'ab_address_text' ) . ' ' . PixfortControls::surface_classes( $s, 'ab_box' ) ) ),
			wp_kses( (string) ( $entry['formatted'] ?? '' ), array( 'br' => array() ) ),
			$this->button( $s, 'ab_edit', 'galaxie-ab-edit' ),
			$this->button( $s, 'ab_ship_default', 'galaxie-ab-default', 'data-type="shipping"' . $hide( null !== $entry && $shipping ) ),
			$this->button( $s, 'ab_bill_default', 'galaxie-ab-default', 'data-type="billing"' . $hide( null !== $entry && $billing ) ),
			$this->button( $s, 'ab_delete', 'galaxie-ab-delete' )
		);
	}

	/** @param array<string,mixed> $s */
	private function button( array $s, string $prefix, string $class, string $attrs = '', string $type = 'button' ): string {
		return sprintf(
			'<button type="%1$s" class="galaxie-account-submit %2$s" %3$s>%4$s</button>',
			esc_attr( $type ),
			esc_attr( $class ),
			$attrs,
			PixfortControls::render_button( $s, $prefix, (string) ( $s[ $prefix . '_text' ] ?? '' ) )
		);
	}

	/**
	 * The fields are WooCommerce's own for the store's country, named shipping_*
	 * so its country script rebuilds the state list when the country changes.
	 *
	 * @param array<string,mixed> $s
	 */
	private function render_form( array $s ): void {
		$country = WC()->countries->get_base_country();
		$fields  = WC()->countries->get_address_fields( $country, 'shipping_' );
		$labels  = array_filter( explode( ' ', PixfortControls::text_classes( $s, 'ab_field_label' ) ) );

		if ( isset( $fields['shipping_state'] ) ) {
			$fields['shipping_state']['country'] = $country;
		}

		if ( ! isset( $fields['shipping_phone'] ) ) {
			$fields['shipping_phone'] = array(
				'label'    => __( 'Telefone', 'galaxie-woo' ),
				'type'     => 'tel',
				'required' => false,
				'class'    => array( 'form-row-wide' ),
				'validate' => array( 'phone' ),
			);
		}

		printf(
			'<form class="galaxie-ab-form card %1$s" data-title-new="%2$s" data-title-edit="%3$s" novalidate hidden>',
			esc_attr( PixfortControls::surface_classes( $s, 'ab_card' ) ),
			esc_attr( (string) ( $s['ab_form_new'] ?? '' ) ),
			esc_attr( (string) ( $s['ab_form_edit'] ?? '' ) )
		);

		printf( '<div class="galaxie-ab-form-title %s"></div>', esc_attr( PixfortControls::text_classes( $s, 'ab_form_title' ) ) );
		echo '<input type="hidden" name="id" value="" />';
		echo '<div class="galaxie-address-fields woocommerce-address-fields__field-wrapper">';

		woocommerce_form_field(
			'galaxie_ab_label',
			array(
				'type'              => 'text',
				'label'             => (string) ( $s['ab_label_field'] ?? '' ),
				'placeholder'       => (string) ( $s['ab_label_placeholder'] ?? '' ),
				'class'             => array( 'form-row-wide' ),
				'input_class'       => array( 'form-control' ),
				'label_class'       => $labels,
				'custom_attributes' => array( 'maxlength' => '40' ),
			),
			''
		);

		foreach ( $fields as $key => $field ) {
			if ( ! in_array( (string) ( $field['type'] ?? 'text' ), array( 'checkbox', 'radio', 'hidden' ), true ) ) {
				$field['input_class'] = array_merge( (array) ( $field['input_class'] ?? array() ), array( 'form-control' ) );
			}

			$field['label_class'] = array_merge( (array) ( $field['label_class'] ?? array() ), $labels );

			woocommerce_form_field( $key, $field, 'shipping_country' === $key ? $country : '' );
		}

		echo '</div>';

		printf(
			'<div class="galaxie-address-form-actions">%1$s%2$s</div>',
			$this->button( $s, 'ab_save', 'galaxie-ab-save', '', 'submit' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$this->button( $s, 'ab_cancel', 'galaxie-ab-cancel' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);

		echo '</form>';
	}
}

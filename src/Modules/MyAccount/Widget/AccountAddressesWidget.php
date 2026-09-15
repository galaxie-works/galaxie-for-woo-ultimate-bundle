<?php
/**
 * "Galaxie Account Addresses": the saved addresses, and the form that edits one.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\AccountEndpoints;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * Two cards on the Addresses screen, and the form once one is opened
 * (`/edit-address/billing/`).
 *
 * The form is WooCommerce's form in everything but looks: the fields come from
 * `WC()->countries->get_address_fields()` through every filter a Brazilian
 * checkout plugin hooks (number, neighbourhood, CPF), each drawn by
 * `woocommerce_form_field()`, and it posts to `WC_Form_Handler::save_address()`
 * with WooCommerce's own nonce. Nothing is saved here. Validation, the
 * "Address changed successfully" notice and the redirect are all WooCommerce's.
 * The fields only gain pixfort's `form-control` class.
 */
final class AccountAddressesWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account-addresses';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Addresses', 'galaxie-woo' );
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
		return array( 'account', 'address', 'endereço', 'woocommerce' );
	}

	/** @return array<int,string> */
	public function get_script_depends(): array {
		return array( 'wc-country-select', 'wc-address-i18n' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'addr_section', array( 'label' => __( 'Addresses', 'galaxie-woo' ) ) );

		$this->add_control(
			'addr_preview',
			array(
				'label'       => __( 'Shown in the editor', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'cards'    => __( 'The address cards', 'galaxie-woo' ),
					'billing'  => __( 'The billing address form', 'galaxie-woo' ),
					'shipping' => __( 'The shipping address form', 'galaxie-woo' ),
				),
				'default'     => 'cards',
				'description' => __( 'On the site, the address decides: the cards on Addresses, the form once one is opened.', 'galaxie-woo' ),
			)
		);

		$this->add_control( 'addr_billing_title', array( 'label' => __( 'Billing address title', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Endereço de cobrança', 'galaxie-woo' ), 'separator' => 'before' ) );
		$this->add_control( 'addr_shipping_title', array( 'label' => __( 'Shipping address title', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Endereço de entrega', 'galaxie-woo' ) ) );
		$this->add_control( 'addr_empty_text', array( 'label' => __( 'When an address is missing', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Você ainda não cadastrou este endereço.', 'galaxie-woo' ) ) );
		$this->add_control( 'addr_intro', array( 'label' => __( 'Text above the cards', 'galaxie-woo' ), 'type' => Controls_Manager::TEXTAREA, 'rows' => 2, 'default' => __( 'Estes endereços serão usados por padrão na finalização da compra.', 'galaxie-woo' ) ) );

		$this->add_responsive_control(
			'addr_columns',
			array(
				'label'          => __( 'Columns', 'galaxie-woo' ),
				'type'           => Controls_Manager::SELECT,
				'options'        => array( '1' => '1', '2' => '2' ),
				'default'        => '2',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'selectors'      => array( '{{WRAPPER}} .galaxie-address-cards' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
				'separator'      => 'before',
			)
		);

		$this->end_controls_section();

		$buttons = array(
			'addr_edit'   => array( __( 'Edit button', 'galaxie-woo' ), array( 'text' => __( 'Editar', 'galaxie-woo' ), 'style' => 'outline', 'size' => 'sm' ) ),
			'addr_add'    => array( __( 'Add button', 'galaxie-woo' ), array( 'text' => __( 'Adicionar endereço', 'galaxie-woo' ), 'size' => 'sm' ) ),
			'addr_save'   => array( __( 'Save button', 'galaxie-woo' ), array( 'text' => __( 'Salvar endereço', 'galaxie-woo' ) ) ),
			'addr_cancel' => array( __( 'Cancel button', 'galaxie-woo' ), array( 'text' => __( 'Cancelar', 'galaxie-woo' ), 'style' => 'link' ) ),
		);

		foreach ( $buttons as $prefix => $button ) {
			$this->start_controls_section( $prefix . '_section', array( 'label' => $button[0] ) );
			PixfortControls::button( $this, $prefix, $button[1] );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'addr_card_style', array( 'label' => __( 'Cards', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'addr_card', '{{WRAPPER}} .galaxie-address-card, {{WRAPPER}} .galaxie-address-form', array( 'rounded' => 'rounded-lg' ) );

		$this->add_responsive_control(
			'addr_gap',
			array(
				'label'      => __( 'Space between cards', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-address-cards' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$texts = array(
			'addr_intro_text' => array( __( 'Text above the cards', 'galaxie-woo' ), '.galaxie-address-intro', array( 'bold' => '' ) ),
			'addr_title'      => array( __( 'Titles', 'galaxie-woo' ), '.galaxie-address-title', array( 'size' => 'text-18', 'bold' => 'font-weight-bold' ) ),
			'addr_body'       => array( __( 'Address text', 'galaxie-woo' ), '.galaxie-address-body:not(.is-empty)', array( 'bold' => '' ) ),
			'addr_label'      => array( __( 'Form labels', 'galaxie-woo' ), '.galaxie-address-form label', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ) ),
		);

		// A fourth `true` marks text drawn by pixfort's Text element; the rest are
		// classes on this widget's own markup, which carry fewer controls.
		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', empty( $text[3] ) ? array( 'position', 'inline' ) : array( 'position' ) );
			$this->end_controls_section();
		}

		// The box around an address is pixfort's `.woocommerce address` rule — a
		// white fill, a hairline border, 5px corners — until these say otherwise.
		// Elementor's scoped selector outranks it, so any value set here wins.
		$this->start_controls_section( 'addr_box_style', array( 'label' => __( 'Address box — all addresses', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'addr_box', '{{WRAPPER}} .galaxie-address-body' );
		$this->end_controls_section();

		// The empty state on its own: its box starts from the one above and only
		// what is set here differs, while its text is styled apart from a real
		// address, since a hint and an address rarely want the same weight.
		$this->start_controls_section( 'addr_empty_style', array( 'label' => __( 'Empty address — overrides', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		$this->add_responsive_control(
			'addr_empty_opacity',
			array(
				'label'     => __( 'Opacity', 'galaxie-woo' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array( 'px' => array( 'min' => 0, 'max' => 100 ) ),
				'default'   => array( 'size' => 70 ),
				'selectors' => array( '{{WRAPPER}} .galaxie-address-body.is-empty' => 'opacity: calc({{SIZE}} / 100);' ),
			)
		);

		$this->add_control( 'addr_empty_box_heading', array( 'label' => __( 'Box', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::surface( $this, 'addr_empty_box', '{{WRAPPER}} .galaxie-address-body.is-empty' );

		$this->add_control( 'addr_empty_body_heading', array( 'label' => __( 'Text', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::text( $this, 'addr_empty_body', array( 'bold' => '' ), array(), '{{WRAPPER}} .galaxie-address-body.is-empty', 'text', array( 'position', 'inline' ) );

		$this->end_controls_section();

		$this->start_controls_section( 'addr_field_style', array( 'label' => __( 'Form fields', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		$field = '{{WRAPPER}} .galaxie-address-form .form-control, {{WRAPPER}} .galaxie-address-form .select2-container .select2-selection';

		PixfortControls::surface( $this, 'addr_field', $field );

		$this->add_responsive_control(
			'addr_field_gap',
			array(
				'label'      => __( 'Space between fields', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-address-fields' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		PixfortControls::palette_control( $this, 'addr_required', __( 'Required marker', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-address-form .required', 'color' );

		$this->end_controls_section();
	}

	/** @param array<string,mixed> $s */
	private function text( array $s, string $prefix, string $class, string $html, string $tag = 'div' ): string {
		return sprintf( '<%1$s class="%2$s %3$s">%4$s</%1$s>', $tag, esc_attr( $class ), esc_attr( PixfortControls::text_classes( $s, $prefix ) ), $html );
	}

	/**
	 * Corners and shadow are classes, so they cannot fall back through CSS the
	 * way a colour does: the empty box takes each of its own when one is chosen
	 * and the address box's otherwise.
	 *
	 * @param array<string,mixed> $s
	 */
	private function empty_box_classes( array $s ): string {
		$merged = $s;

		foreach ( array( 'rounded', 'shadow' ) as $key ) {
			if ( '' !== (string) ( $s[ 'addr_empty_box_' . $key ] ?? '' ) ) {
				$merged[ 'addr_box_' . $key ] = $s[ 'addr_empty_box_' . $key ];
			}
		}

		return PixfortControls::surface_classes( $merged, 'addr_box' );
	}

	/** Which address the form is for, or '' for the cards. */
	private function type( array $s ): string {
		if ( AccountParts::editing() ) {
			$preview = (string) ( $s['addr_preview'] ?? 'cards' );

			return in_array( $preview, array( 'billing', 'shipping' ), true ) ? $preview : '';
		}

		$current = AccountEndpoints::current();

		if ( 'edit-address' !== $current['key'] || '' === $current['value'] ) {
			return '';
		}

		$type = wc_edit_address_i18n( sanitize_title( $current['value'] ), true );

		return in_array( $type, array( 'billing', 'shipping' ), true ) ? $type : '';
	}

	/** @return array<string,string> address type => title */
	private function types( array $s ): array {
		$types = array( 'billing' => (string) ( $s['addr_billing_title'] ?? '' ) );

		if ( ! wc_ship_to_billing_address_only() && wc_shipping_enabled() ) {
			$types['shipping'] = (string) ( $s['addr_shipping_title'] ?? '' );
		}

		return $types;
	}

	protected function render(): void {
		if ( ! function_exists( 'WC' ) || ( ! is_user_logged_in() && ! AccountParts::editing() ) ) {
			return;
		}

		Assets::enqueue();

		$s    = $this->get_settings_for_display();
		$type = $this->type( $s );

		echo '<div class="galaxie-account-addresses">';

		if ( '' === $type ) {
			$this->render_cards( $s );
		} else {
			$this->render_form( $s, $type );
		}

		echo '</div>';
	}

	/** @param array<string,mixed> $s */
	private function render_cards( array $s ): void {
		$intro = trim( (string) ( $s['addr_intro'] ?? '' ) );

		if ( '' !== $intro ) {
			echo $this->text( $s, 'addr_intro_text', 'galaxie-address-intro', esc_html( $intro ), 'p' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}

		echo '<div class="galaxie-address-cards">';

		foreach ( $this->types( $s ) as $type => $title ) {
			// A street, not just any text: saving the account details copies the name
			// into billing so checkout starts filled in, and the formatted address
			// of a name alone is not empty — it would read as an address on file.
			$formatted = wc_get_account_formatted_address( $type );
			$has       = '' !== trim( (string) get_user_meta( get_current_user_id(), $type . '_address_1', true ) )
				&& '' !== trim( wp_strip_all_tags( (string) $formatted ) );

			printf(
				'<section class="galaxie-address-card card %1$s"><header class="galaxie-address-card-head">%2$s</header>%3$s<div class="galaxie-address-card-actions">%4$s</div></section>',
				esc_attr( PixfortControls::surface_classes( $s, 'addr_card' ) ),
				$this->text( $s, 'addr_title', 'galaxie-address-title', esc_html( $title ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				$has // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					? $this->text( $s, 'addr_body', 'galaxie-address-body ' . PixfortControls::surface_classes( $s, 'addr_box' ), wp_kses_post( $formatted ), 'address' )
					: $this->text( $s, 'addr_empty_body', 'galaxie-address-body is-empty ' . $this->empty_box_classes( $s ), esc_html( (string) ( $s['addr_empty_text'] ?? '' ) ), 'address' ),
				$has // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
					? AccountParts::link_button( $s, 'addr_edit', (string) ( $s['addr_edit_text'] ?? '' ), AccountEndpoints::url( 'edit-address', $type ) )
					: AccountParts::link_button( $s, 'addr_add', (string) ( $s['addr_add_text'] ?? '' ), AccountEndpoints::url( 'edit-address', $type ) )
			);
		}

		echo '</div>';
	}

	/**
	 * WooCommerce's `WC_Shortcode_My_Account::edit_address()`, step for step, with
	 * our markup at the end.
	 *
	 * @param array<string,mixed> $s
	 */
	private function render_form( array $s, string $type ): void {
		$user_id = get_current_user_id();
		$country = (string) get_user_meta( $user_id, $type . '_country', true );
		$allowed = 'shipping' === $type ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();

		if ( '' === $country ) {
			$country = WC()->countries->get_base_country();
		}

		if ( ! array_key_exists( $country, $allowed ) ) {
			$country = (string) current( array_keys( $allowed ) );
		}

		$fields = WC()->countries->get_address_fields( $country, $type . '_' );

		wp_enqueue_script( 'wc-country-select' );
		wp_enqueue_script( 'wc-address-i18n' );

		foreach ( $fields as $key => $field ) {
			$value = get_user_meta( $user_id, $key, true );

			if ( ! $value && in_array( $key, array( 'billing_email', 'shipping_email' ), true ) ) {
				$value = wp_get_current_user()->user_email;
			}

			$fields[ $key ]['value'] = apply_filters( 'woocommerce_my_account_edit_address_field_value', $value, $key, $type );
		}

		$fields = apply_filters( 'woocommerce_address_to_edit', $fields, $type );
		$title  = 'billing' === $type ? (string) ( $s['addr_billing_title'] ?? '' ) : (string) ( $s['addr_shipping_title'] ?? '' );

		printf( '<form method="post" class="galaxie-address-form card %s" novalidate>', esc_attr( PixfortControls::surface_classes( $s, 'addr_card' ) ) );

		echo $this->text( $s, 'addr_title', 'galaxie-address-title', esc_html( (string) apply_filters( 'woocommerce_my_account_edit_address_title', $title, $type ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.

		do_action( 'woocommerce_before_edit_account_address_form' );
		do_action( "woocommerce_before_edit_address_form_{$type}" );

		echo '<div class="galaxie-address-fields woocommerce-address-fields__field-wrapper">';

		$labels = PixfortControls::text_classes( $s, 'addr_label' );

		foreach ( $fields as $key => $field ) {
			$field_type = (string) ( $field['type'] ?? 'text' );

			if ( ! in_array( $field_type, array( 'checkbox', 'radio', 'hidden' ), true ) ) {
				$field['input_class'] = array_merge( (array) ( $field['input_class'] ?? array() ), array( 'form-control' ) );
			}

			$field['label_class'] = array_merge( (array) ( $field['label_class'] ?? array() ), array_filter( explode( ' ', $labels ) ) );

			woocommerce_form_field( $key, $field, wc_get_post_data_by_key( $key, $field['value'] ) );
		}

		echo '</div>';

		do_action( "woocommerce_after_edit_address_form_{$type}" );

		printf(
			'<div class="galaxie-address-form-actions"><button type="submit" class="galaxie-account-submit" name="save_address" value="%1$s">%2$s</button>%3$s</div>',
			esc_attr__( 'Save address', 'woocommerce' ),
			PixfortControls::render_button( $s, 'addr_save', (string) ( $s['addr_save_text'] ?? '' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own button.
			AccountParts::link_button( $s, 'addr_cancel', (string) ( $s['addr_cancel_text'] ?? '' ), AccountEndpoints::url( 'edit-address' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);

		wp_nonce_field( 'woocommerce-edit_address', 'woocommerce-edit-address-nonce' );
		echo '<input type="hidden" name="action" value="edit_address" />';
		echo '</form>';

		do_action( 'woocommerce_after_edit_account_address_form' );
	}
}

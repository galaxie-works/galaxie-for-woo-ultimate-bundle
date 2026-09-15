<?php
/**
 * "Galaxie Account Details": the customer's personal details.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\Phone;
use Galaxie\Woo\Support\PixfortControls;
use Galaxie\Woo\Support\ProfileFields;

defined( 'ABSPATH' ) || exit;

/**
 * Name, social name, mobile phone, date of birth, CPF and gender, saved
 * without a reload. The phone is WooCommerce's billing phone — the one checkout
 * fills in and the store calls — so there is only ever one number to keep right.
 *
 * The same fields and the same `galaxie_myaccount_save_details` handler the
 * React tab used, so customer data and the FluentCRM sync behind
 * `galaxie_woo/profile_updated` carry on unchanged. What changed is the markup:
 * server-rendered, pixfort's `form-control` fields and Button, every label and
 * message the merchant's to write.
 *
 * The email is shown, not edited: on a store where people sign in with a code
 * sent to their email, changing it here would lock them out.
 */
final class AccountDetailsWidget extends Widget_Base {

	private const FIELDS = array(
		'first_name'  => array( 'Nome', true ),
		'last_name'   => array( 'Sobrenome', true ),
		'social_name' => array( 'Nome social', false ),
		'email'       => array( 'E-mail', true ),
		'phone'       => array( 'Celular', false ),
		'birthdate'   => array( 'Data de nascimento', false ),
		'cpf'         => array( 'CPF', false ),
		'gender'      => array( 'Gênero', false ),
	);

	public function get_name(): string {
		return 'galaxie-account-details';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Details', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'details', 'profile', 'dados', 'woocommerce' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'details_section', array( 'label' => __( 'Fields', 'galaxie-woo' ) ) );

		$this->add_control( 'details_heading', array( 'label' => __( 'Heading', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Dados pessoais', 'galaxie-woo' ) ) );

		foreach ( self::FIELDS as $key => $field ) {
			$this->add_control( 'details_' . $key . '_label', array( 'label' => sprintf( /* translators: %s: field name. */ __( '"%s" label', 'galaxie-woo' ), $field[0] ), 'type' => Controls_Manager::TEXT, 'default' => $field[0], 'separator' => 'first_name' === $key ? 'before' : '' ) );

			if ( ! $field[1] ) {
				$this->add_control( 'details_' . $key . '_show', array( 'label' => sprintf( /* translators: %s: field name. */ __( 'Show "%s"', 'galaxie-woo' ), $field[0] ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
			}
		}

		$this->add_control( 'details_social_hint', array( 'label' => __( 'Social name hint', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Como você prefere ser chamado, se for diferente do nome civil.', 'galaxie-woo' ), 'separator' => 'before' ) );
		$this->add_control( 'details_email_hint', array( 'label' => __( 'Email hint', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'É o e-mail que você usa para entrar. Para trocar, fale com a gente.', 'galaxie-woo' ) ) );
		$this->add_control( 'details_saved_text', array( 'label' => __( 'Saved message', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Dados salvos.', 'galaxie-woo' ) ) );

		$this->add_responsive_control(
			'details_columns',
			array(
				'label'          => __( 'Columns', 'galaxie-woo' ),
				'type'           => Controls_Manager::SELECT,
				'options'        => array( '1' => '1', '2' => '2' ),
				'default'        => '2',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'selectors'      => array( '{{WRAPPER}} .galaxie-details-fields' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
				'separator'      => 'before',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'details_save_section', array( 'label' => __( 'Save button', 'galaxie-woo' ) ) );
		PixfortControls::button( $this, 'details_save', array( 'text' => __( 'Salvar alterações', 'galaxie-woo' ) ) );
		$this->end_controls_section();

		$this->start_controls_section( 'details_box_style', array( 'label' => __( 'Box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'details_box', '{{WRAPPER}} .galaxie-account-details' );
		$this->end_controls_section();

		$texts = array(
			'details_heading_text' => array( __( 'Heading', 'galaxie-woo' ), '.galaxie-details-heading', array( 'size' => 'text-20', 'bold' => 'font-weight-bold' ), true ),
			'details_label'        => array( __( 'Labels', 'galaxie-woo' ), '.galaxie-details-form label', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ) ),
			'details_hint'         => array( __( 'Hints', 'galaxie-woo' ), '.galaxie-details-hint', array( 'size' => 'text-xs', 'bold' => '' ) ),
			'details_msg_ok'       => array( __( 'Saved message', 'galaxie-woo' ), '.galaxie-account-message.is-success', array( 'size' => 'text-sm', 'bold' => '', 'content_color' => 'green' ) ),
			'details_msg_err'      => array( __( 'Error message', 'galaxie-woo' ), '.galaxie-account-message.is-error', array( 'size' => 'text-sm', 'bold' => '', 'content_color' => 'red' ) ),
		);

		// A fourth `true` marks text drawn by pixfort's Text element; the rest are
		// classes on this widget's own markup, which carry fewer controls.
		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', empty( $text[3] ) ? array( 'position', 'inline' ) : array( 'position' ) );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'details_field_style', array( 'label' => __( 'Fields', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'details_field', '{{WRAPPER}} .galaxie-details-form .form-control' );

		$this->add_responsive_control(
			'details_gap',
			array(
				'label'      => __( 'Space between fields', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-details-fields' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The stored phone as the field should receive it. A number saved before
	 * phones were kept in E.164, `(11) 98040-9005`, is printed as `+5511980409005`
	 * so the flag field reads it as Brazilian; one that cannot be read is printed
	 * as it is, for the customer to correct.
	 */
	private static function phone_value( string $stored ): string {
		$stored = trim( $stored );

		if ( '' === $stored || str_starts_with( $stored, '+' ) ) {
			return $stored;
		}

		return Phone::normalize( $stored ) ?? $stored;
	}

	/** @param array<string,mixed> $s */
	private function shows( array $s, string $key ): bool {
		return self::FIELDS[ $key ][1] || 'yes' === ( $s[ 'details_' . $key . '_show' ] ?? 'yes' );
	}

	protected function render(): void {
		if ( ! is_user_logged_in() && ! AccountParts::editing() ) {
			return;
		}

		Assets::enqueue();

		$s      = $this->get_settings_for_display();
		$user   = wp_get_current_user();
		$labels = PixfortControls::text_classes( $s, 'details_label' );
		$hints  = PixfortControls::text_classes( $s, 'details_hint' );

		$values = array(
			'first_name'  => $user->first_name,
			'last_name'   => $user->last_name,
			'social_name' => (string) get_user_meta( $user->ID, ProfileFields::SOCIAL_NAME, true ),
			'email'       => $user->user_email,
			'phone'       => self::phone_value( (string) get_user_meta( $user->ID, 'billing_phone', true ) ),
			'birthdate'   => (string) get_user_meta( $user->ID, ProfileFields::BIRTHDATE, true ),
			'cpf'         => (string) get_user_meta( $user->ID, ProfileFields::CPF, true ),
			'gender'      => (string) get_user_meta( $user->ID, ProfileFields::GENDER, true ),
		);

		// `data-phone-error`: the handler's own message, for the phone field to
		// show when intl-tel-input already knows the number is invalid.
		printf(
			'<div class="galaxie-account-details %1$s"><form class="galaxie-details-form" novalidate data-error-class="%2$s" data-ok-class="%3$s" data-saved="%4$s" data-phone-error="%5$s">',
			esc_attr( PixfortControls::surface_classes( $s, 'details_box' ) ),
			esc_attr( PixfortControls::text_classes( $s, 'details_msg_err' ) ),
			esc_attr( PixfortControls::text_classes( $s, 'details_msg_ok' ) ),
			esc_attr( (string) ( $s['details_saved_text'] ?? '' ) ),
			esc_attr__( 'Please enter a valid phone number, with area code.', 'galaxie-woo' )
		);

		$heading = trim( (string) ( $s['details_heading'] ?? '' ) );

		if ( '' !== $heading ) {
			printf( '<div class="galaxie-details-heading">%s</div>', PixfortControls::render_text( $s, 'details_heading_text', esc_html( $heading ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		echo '<div class="galaxie-details-fields">';

		foreach ( array_keys( self::FIELDS ) as $key ) {
			if ( ! $this->shows( $s, $key ) ) {
				continue;
			}

			$id    = 'galaxie-details-' . $key . '-' . $this->get_id();
			$label = (string) ( $s[ 'details_' . $key . '_label' ] ?? self::FIELDS[ $key ][0] );
			$wide  = in_array( $key, array( 'social_name', 'email' ), true ) ? ' is-wide' : '';

			printf( '<p class="galaxie-details-field form-row%1$s"><label for="%2$s" class="%3$s">%4$s%5$s</label>', esc_attr( $wide ), esc_attr( $id ), esc_attr( $labels ), esc_html( $label ), in_array( $key, array( 'first_name', 'last_name' ), true ) ? ' <abbr class="required" title="required">*</abbr>' : '' );

			if ( 'gender' === $key ) {
				printf( '<select id="%1$s" name="gender" class="form-control">', esc_attr( $id ) );

				foreach ( ProfileFields::gender_options() as $value => $option ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( $values['gender'], (string) $value, false ), esc_html( $option ) );
				}

				echo '</select>';
			} else {
				$attrs = array(
					'first_name'  => 'type="text" autocomplete="given-name" required',
					'last_name'   => 'type="text" autocomplete="family-name" required',
					'social_name' => 'type="text" autocomplete="nickname"',
					'email'       => 'type="email" readonly',
					// No placeholder or maxlength: intl-tel-input shows an example
					// number for the chosen country and caps the length itself.
					'phone'       => 'type="tel" inputmode="tel" autocomplete="tel"',
					'birthdate'   => 'type="date" autocomplete="bday"',
					'cpf'         => 'type="text" inputmode="numeric" placeholder="000.000.000-00" maxlength="14"',
				);

				printf(
					'<input id="%1$s" name="%2$s" class="form-control" value="%3$s" %4$s />',
					esc_attr( $id ),
					esc_attr( $key ),
					esc_attr( $values[ $key ] ),
					$attrs[ $key ] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute strings.
				);
			}

			$hint = 'social_name' === $key ? (string) ( $s['details_social_hint'] ?? '' ) : ( 'email' === $key ? (string) ( $s['details_email_hint'] ?? '' ) : '' );

			if ( '' !== trim( $hint ) ) {
				printf( '<span class="galaxie-details-hint %1$s">%2$s</span>', esc_attr( $hints ), esc_html( $hint ) );
			}

			echo '</p>';
		}

		echo '</div>';

		printf(
			'<div class="galaxie-details-actions"><button type="submit" class="galaxie-account-submit">%1$s</button><div class="galaxie-account-message" role="status" aria-live="polite" hidden></div></div>',
			PixfortControls::render_button( $s, 'details_save', (string) ( $s['details_save_text'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own button.
		);

		echo '</form></div>';
	}
}

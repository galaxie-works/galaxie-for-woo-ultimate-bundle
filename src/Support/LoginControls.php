<?php
/**
 * The sign-in step's panel, shared by the checkout and the login widget.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * One panel for one component. `islands/login/OtpLogin.tsx` draws the same
 * sign-in in the Galaxie Checkout's first step and in the Galaxie Login widget,
 * and it asks its host for the same things: the texts it prints, the pixfort
 * classes its parts wear, and the buttons already rendered as pixfort markup.
 * Written twice, the two panels would drift the first time either was touched.
 *
 * The control ids are the checkout's own (`co_*`), unchanged, because a
 * merchant's saved checkout carries them.
 */
final class LoginControls {

	/** What the island swaps for a label or a message it only knows in the browser. */
	public const MARKER = '__GALAXIE_LABEL__';

	/**
	 * The strings the sign-in prints, as control id => default. The field
	 * labels are not here: forty text boxes in a panel help nobody, so they go
	 * through translation ({@see self::texts()}).
	 *
	 * @return array<string,string>
	 */
	public static function text_defaults(): array {
		return array(
			'entry_intro'     => '',
			'tab_login'       => __( 'Já sou cliente', 'galaxie-woo' ),
			'tab_register'    => __( 'Primeira compra', 'galaxie-woo' ),
			'send_code'       => __( 'Receber código', 'galaxie-woo' ),
			'register_button' => __( 'Criar conta e receber código', 'galaxie-woo' ),
			'code_hint'       => __( 'Digite o código de 6 dígitos que enviamos para %s.', 'galaxie-woo' ),
			'confirm_code'    => __( 'Confirmar e continuar', 'galaxie-woo' ),
			'resend_code'     => __( 'Reenviar código', 'galaxie-woo' ),
			'change_email'    => __( 'Usar outro e-mail', 'galaxie-woo' ),
			'marketing'       => __( 'Quero receber novidades e ofertas.', 'galaxie-woo' ),
			'terms'           => __( 'Li e aceito os termos de uso e a política de privacidade.', 'galaxie-woo' ),
		);
	}

	/**
	 * The text controls, on the Content tab, in the order the shopper meets
	 * them. The checkout registers its own around these.
	 *
	 * @param object $widget The Elementor widget registering them.
	 */
	public static function register_texts( object $widget ): void {
		$labels = array(
			'entry_intro'     => array( __( 'Line above the sign-in', 'galaxie-woo' ), __( 'Optional.', 'galaxie-woo' ) ),
			'tab_login'       => array( __( 'Tab: returning customer', 'galaxie-woo' ), '' ),
			'tab_register'    => array( __( 'Tab: first time', 'galaxie-woo' ), '' ),
			'send_code'       => array( __( 'Button: send the code', 'galaxie-woo' ), '' ),
			'register_button' => array( __( 'Button: create the account', 'galaxie-woo' ), '' ),
			'code_hint'       => array( __( 'Line above the code field', 'galaxie-woo' ), __( '%s becomes the e-mail address the code went to.', 'galaxie-woo' ) ),
			'confirm_code'    => array( __( 'Button: confirm the code', 'galaxie-woo' ), '' ),
			'resend_code'     => array( __( 'Link: send another code', 'galaxie-woo' ), '' ),
			'change_email'    => array( __( 'Link: use another e-mail', 'galaxie-woo' ), '' ),
			'marketing'       => array( __( 'Consent: news and offers', 'galaxie-woo' ), __( 'The checkbox on the first-time form. What it writes is set under Galaxie → FluentCRM.', 'galaxie-woo' ) ),
			'terms'           => array( __( 'Consent: terms', 'galaxie-woo' ), '' ),
		);

		foreach ( self::text_defaults() as $id => $default ) {
			$widget->add_control(
				$id,
				array(
					'label'       => $labels[ $id ][0],
					'type'        => Controls_Manager::TEXT,
					'label_block' => true,
					'default'     => $default,
					'description' => $labels[ $id ][1],
				)
			);
		}
	}

	/**
	 * Every string the island prints: the merchant's where there is a control,
	 * and the plugin's where there is not.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<string,string>
	 */
	public static function texts( array $settings ): array {
		$out = array();

		foreach ( self::text_defaults() as $id => $default ) {
			$typed = trim( (string) ( $settings[ $id ] ?? '' ) );
			$key   = lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $id ) ) ) );

			$out[ $key ] = '' !== $typed ? (string) $settings[ $id ] : $default;
		}

		// entry_intro → entryIntro, tab_login → tabLogin… and the labels that
		// have no control of their own.
		return $out + array(
			'email'     => __( 'E-mail', 'galaxie-woo' ),
			'firstName' => __( 'Nome', 'galaxie-woo' ),
			'lastName'  => __( 'Sobrenome', 'galaxie-woo' ),
			'birthdate' => __( 'Data de nascimento', 'galaxie-woo' ),
			'cpf'       => __( 'CPF', 'galaxie-woo' ),
			'phone'     => __( 'Celular', 'galaxie-woo' ),
		);
	}

	/**
	 * The text sets the sign-in wears, as prefix => [ label, selector, defaults ].
	 *
	 * @return array<string, array{0:string,1:string,2:array<string,string>}>
	 */
	public static function text_sets(): array {
		return array(
			'co_body'     => array( __( 'Texts: body', 'galaxie-woo' ), '.gx-co-body', array( 'bold' => '' ) ),
			'co_small'    => array( __( 'Texts: small text', 'galaxie-woo' ), '.gx-co-small', array( 'size' => 'text-sm', 'bold' => '' ) ),
			'co_label'    => array( __( 'Texts: field labels', 'galaxie-woo' ), '.gx-co-label', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ) ),
			'co_hint'     => array( __( 'Texts: hints', 'galaxie-woo' ), '.gx-co-hint', array( 'size' => 'text-xs', 'bold' => '' ) ),
			'co_error'    => array( __( 'Texts: error under a field', 'galaxie-woo' ), '.gx-co-error', array( 'size' => 'text-xs', 'bold' => '', 'content_color' => 'red' ) ),
			'co_tab_text' => array( __( 'Texts: sign-in tabs', 'galaxie-woo' ), '.gx-co-tab', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ) ),
		);
	}

	/** The two button roles the sign-in draws: a form's submit, and its links. */
	public static function button_sets(): array {
		return array(
			'co_btn_main' => array( __( 'Button · Main actions', 'galaxie-woo' ), array( 'color' => 'primary', 'full' => 'yes' ) ),
			'co_btn_link' => array( __( 'Button · Links (Alterar, Reenviar…)', 'galaxie-woo' ), array( 'style' => 'link', 'size' => 'sm', 'remove_padding' => 'no-padding', 'title_bold' => '' ) ),
		);
	}

	/**
	 * Every Style section the sign-in needs: its texts, the tabs, the fields,
	 * the switch and checkbox, the warning, and its two buttons.
	 *
	 * @param object $widget The Elementor widget registering them.
	 * @param string $scope  What the selectors hang from.
	 */
	public static function register_style( object $widget, string $scope = '{{WRAPPER}}' ): void {
		$style = static fn( string $label ): array => array( 'label' => $label, 'tab' => Controls_Manager::TAB_STYLE );

		foreach ( self::text_sets() as $prefix => [ $label, $selector, $defaults ] ) {
			$widget->start_controls_section( $prefix . '_style', $style( $label ) );
			PixfortControls::text( $widget, $prefix, $defaults, array(), $scope . ' ' . $selector, 'text', array( 'inline', 'position' ) );
			$widget->end_controls_section();
		}

		// The Wishlist's list tabs: a pill with a current state.
		$widget->start_controls_section( 'co_tab_box_style', $style( __( 'Sign-in tabs', 'galaxie-woo' ) ) );
		PixfortControls::surface( $widget, 'co_tab', $scope . ' .gx-co-tab', array( 'rounded' => 'badge-pill', 'radius_set' => 'badge' ) );
		$widget->add_control( 'co_tab_current_heading', array( 'label' => __( 'Current tab', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $widget, 'co_tab_current_bg', __( 'Background', 'galaxie-woo' ), $scope . ' .gx-co-tab.is-current', 'background-color' );
		PixfortControls::palette_control( $widget, 'co_tab_current_color', __( 'Text color', 'galaxie-woo' ), $scope . ' .gx-co-tab.is-current', 'color' );
		PixfortControls::palette_control( $widget, 'co_tab_current_border', __( 'Border color', 'galaxie-woo' ), $scope . ' .gx-co-tab.is-current', 'border-color', array(), ' border-style: solid; border-width: 1px;' );
		$widget->end_controls_section();

		// Fields: pixfort's .form-control, with the Payment Methods set of states.
		$field = $scope . ' .gx-co .form-control';
		$widget->start_controls_section( 'co_field_style', $style( __( 'Fields', 'galaxie-woo' ) ) );
		PixfortControls::surface( $widget, 'co_field', $field );
		$widget->add_control( 'co_field_text_heading', array( 'label' => __( 'Typed text', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $widget, 'co_field_color', __( 'Text color', 'galaxie-woo' ), $field, 'color' );
		PixfortControls::palette_control( $widget, 'co_field_placeholder', __( 'Placeholder color', 'galaxie-woo' ), $field . '::placeholder', 'color' );
		$widget->add_control( 'co_field_state_heading', array( 'label' => __( 'States', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $widget, 'co_field_focus', __( 'Border when focused', 'galaxie-woo' ), $field . ':focus', 'border-color' );
		PixfortControls::palette_control( $widget, 'co_field_focus_bg', __( 'Background when focused', 'galaxie-woo' ), $field . ':focus', 'background-color' );
		PixfortControls::palette_control( $widget, 'co_field_error', __( 'Border on error', 'galaxie-woo' ), $field . '[aria-invalid="true"]', 'border-color' );
		$widget->add_responsive_control(
			'co_field_gap',
			array(
				'label'      => __( 'Space between fields', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( $scope . ' .gx-co-form' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$widget->end_controls_section();

		// The Communication widget's switch, and the Wishlist popover's checkbox.
		$widget->start_controls_section( 'co_choice_style', $style( __( 'Switch and checkbox', 'galaxie-woo' ) ) );
		PixfortControls::surface( $widget, 'co_option', $scope . ' .gx-co-option', array( 'rounded' => 'rounded-lg' ) );
		PixfortControls::palette_control( $widget, 'co_switch_off', __( 'Switch: off', 'galaxie-woo' ), $scope . ' .galaxie-switch-track', 'background-color' );
		PixfortControls::palette_control( $widget, 'co_switch_on', __( 'Switch: on', 'galaxie-woo' ), $scope . ' .galaxie-switch input:checked + .galaxie-switch-track', 'background-color' );
		PixfortControls::palette_control( $widget, 'co_switch_knob', __( 'Switch: knob', 'galaxie-woo' ), $scope . ' .galaxie-switch-track::after', 'background-color' );
		PixfortControls::palette_control( $widget, 'co_checkbox', __( 'Checkbox', 'galaxie-woo' ), $scope . ' .gx-co input[type="checkbox"]', 'accent-color' );
		$widget->end_controls_section();

		// Warnings: pixfort's Alert, as the Kit Builder and the Buy Box refuse.
		// No icon and no close button: pixfort's fallback glyph is a question
		// mark, and a message re-shown on every attempt should not be dismissed.
		$widget->start_controls_section( 'co_message_style', $style( __( 'Warnings and errors', 'galaxie-woo' ) ) );
		$widget->add_control(
			'co_alert_type',
			array(
				'label'   => __( 'Error style', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => PixfortControls::alert_types(),
				'default' => 'danger',
			)
		);
		PixfortControls::alert( $widget, 'co_alert', array( 'hide_close' => 'true', 'media_type' => 'none' ), array(), $scope . ' .gx-co-alert' );
		$widget->end_controls_section();

		// The labels live on the Content tab, so `text` is skipped. The section
		// id is `_button_style`, never `{prefix}_style`: button() registers that
		// id itself for its Button style select.
		foreach ( self::button_sets() as $prefix => [ $label, $defaults ] ) {
			$widget->start_controls_section( $prefix . '_button_style', $style( $label ) );
			PixfortControls::button( $widget, $prefix, $defaults, array(), $scope, array( 'text' ) );
			$widget->end_controls_section();
		}
	}

	/**
	 * What the island needs to draw pixfort's parts: the classes each text and
	 * surface control stands for, and each button as pixfort's own markup with
	 * its label already in it. Built in PHP because pixfort decides some of
	 * those classes (shadows, hover effects) and all of the button markup.
	 *
	 * @param array<string,mixed>  $settings Widget settings.
	 * @param array<string,string> $text     From {@see self::texts()}.
	 * @return array<string,mixed>
	 */
	public static function ui( array $settings, array $text ): array {
		$tc = static fn( string $prefix ): string => PixfortControls::text_classes( $settings, $prefix );
		$sc = static fn( string $prefix ): string => PixfortControls::surface_classes( $settings, $prefix );

		$button = static fn( string $prefix, string $label ): array => array(
			'html' => PixfortControls::render_button( $settings, $prefix, $label ),
			'full' => 'yes' === ( $settings[ $prefix . '_full' ] ?? '' ),
		);

		return array(
			'cls'     => array(
				'body'    => $tc( 'co_body' ),
				'small'   => $tc( 'co_small' ),
				'label'   => $tc( 'co_label' ),
				'hint'    => $tc( 'co_hint' ),
				'error'   => $tc( 'co_error' ),
				'tabText' => $tc( 'co_tab_text' ),
				'tab'     => $sc( 'co_tab' ),
				'field'   => $sc( 'co_field' ),
				'option'  => $sc( 'co_option' ),
			),
			'buttons' => array(
				'sendCode'       => $button( 'co_btn_main', $text['sendCode'] ),
				'registerButton' => $button( 'co_btn_main', $text['registerButton'] ),
				'confirmCode'    => $button( 'co_btn_main', $text['confirmCode'] ),
				'resendCode'     => $button( 'co_btn_link', $text['resendCode'] ),
				'changeEmail'    => $button( 'co_btn_link', $text['changeEmail'] ),
			),
			'alert'   => self::alert( $settings ),
			'marker'  => self::MARKER,
		);
	}

	/**
	 * pixfort's Alert around a marker the island replaces with the message,
	 * escaped. Drawn here because the messages only exist in the browser.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public static function alert( array $settings ): string {
		$type = (string) ( $settings['co_alert_type'] ?? 'danger' );

		$inner = PixfortControls::available()
			? (string) \PixfortCore::instance()->elementsManager->renderElement( 'Alert', PixfortControls::alert_attr( $settings, 'co_alert', self::MARKER, $type ) )
			: sprintf( '<div class="alert alert-%1$s" role="alert"><div class="pix-alert-title">%2$s</div></div>', esc_attr( $type ), self::MARKER );

		return '<div class="gx-co-alert" role="alert">' . $inner . '</div>';
	}
}

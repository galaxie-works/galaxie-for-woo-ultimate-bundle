<?php
/**
 * "Galaxie Login": the sign-in, anywhere a page wants it.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PasswordlessAuth\Widget;

use Elementor\Controls_Manager;
use Galaxie\Woo\Elementor\AbstractIslandWidget;
use Galaxie\Woo\Support\LoginControls;

defined( 'ABSPATH' ) || exit;

/**
 * The same sign-in the Galaxie Checkout draws in its first step — the code by
 * e-mail, the first-time form, the code field — as a widget of its own, so the
 * signed-out My Account screen (Galaxie Account Content → "Signed-out visitors
 * see" → an Elementor template) and a /login page of the shop's own can show
 * it instead of WooCommerce's form.
 *
 * One component (`islands/login/OtpLogin.tsx`) and one panel
 * ({@see LoginControls}), so the two places cannot drift apart.
 */
final class LoginWidget extends AbstractIslandWidget {

	public function get_name(): string {
		return 'galaxie-login';
	}

	public function get_title(): string {
		return __( 'Galaxie Login', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-lock-user';
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'login', 'entrar', 'conta', 'sign in', 'otp' );
	}

	protected function island_name(): string {
		return 'login';
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'login_section', array( 'label' => __( 'Sign in', 'galaxie-woo' ) ) );

		$this->add_control(
			'login_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'The code-by-e-mail sign-in, the same one the Galaxie Checkout uses. It needs the Passwordless Auth module on; what the "news and offers" checkbox writes is set under Galaxie → FluentCRM.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		LoginControls::register_texts( $this );

		$this->add_control(
			'login_after',
			array(
				'label'       => __( 'Once signed in, go to', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'reload'  => __( 'This same page, reloaded', 'galaxie-woo' ),
					'account' => __( 'My Account', 'galaxie-woo' ),
					'url'     => __( 'Another address', 'galaxie-woo' ),
				),
				'default'     => 'reload',
				'description' => __( 'The page is drawn again either way: everything the server printed — the menu, the account screen, the cart — is still signed out until it is.', 'galaxie-woo' ),
				'separator'   => 'before',
			)
		);

		$this->add_control(
			'login_after_url',
			array(
				'label'       => __( 'Address', 'galaxie-woo' ),
				'type'        => Controls_Manager::URL,
				'options'     => array( 'is_external', 'nofollow' ),
				'placeholder' => 'https://',
				'condition'   => array( 'login_after' => 'url' ),
			)
		);

		$this->add_control(
			'login_signed_in',
			array(
				'label'       => __( 'Shown to someone already signed in', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Você já está conectado.', 'galaxie-woo' ),
				'description' => __( 'Empty draws nothing at all, which is what a page that only exists to sign in wants.', 'galaxie-woo' ),
				'separator'   => 'before',
			)
		);

		$this->end_controls_section();

		LoginControls::register_style( $this );
	}

	/** @return array<string,mixed> */
	protected function island_props(): array {
		$settings = $this->get_settings_for_display();
		$text     = LoginControls::texts( $settings );
		$editing  = class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode();

		return array(
			'text'         => $text,
			'ui'           => LoginControls::ui( $settings, $text ),
			'genericError' => __( 'Algo deu errado. Tente novamente.', 'galaxie-woo' ),
			'redirect'     => $this->redirect( $settings ),
			// Signed in, there is nothing to sign in to: the widget says so, or
			// says nothing. The editor always draws the form, to be styled.
			'signedIn'     => ! $editing && is_user_logged_in() ? (string) ( $settings['login_signed_in'] ?? '' ) : '',
			'preview'      => $editing,
		);
	}

	/**
	 * Where a verified code lands, as an address the browser may use, or ''
	 * for "this page again".
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	private function redirect( array $settings ): string {
		$after = (string) ( $settings['login_after'] ?? 'reload' );

		if ( 'account' === $after ) {
			return function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';
		}

		if ( 'url' === $after ) {
			return esc_url_raw( (string) ( $settings['login_after_url']['url'] ?? '' ) );
		}

		return '';
	}
}

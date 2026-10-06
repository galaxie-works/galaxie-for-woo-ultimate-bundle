<?php
/**
 * "Galaxie Login": the sign-in, anywhere a page wants it.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PasswordlessAuth\Widget;

use Elementor\Controls_Manager;
use Galaxie\Woo\Elementor\AbstractIslandWidget;
use Galaxie\Woo\Support\AccountParts;
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
 *
 * "Use the Galaxie Checkout style" takes the whole look from the Galaxie
 * Checkout on the site instead of this widget's own Style tab: the classes
 * (texts, surfaces, buttons, alert) from its settings, and the rules
 * Elementor wrote for it into that page's CSS — written again here for this
 * element by Elementor's own CSS engine, from the same control ids both
 * widgets register. The look is kept by {@see AccountParts::sync_looks()}
 * whenever the checkout's page is saved, so a change there shows here.
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
			'login_checkout_style',
			array(
				'label'        => __( 'Use the Galaxie Checkout style', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'separator'    => 'before',
				'description'  => __( 'On: texts, tabs, fields, switch, warnings and buttons look exactly like the sign-in step of the Galaxie Checkout, and follow it when it changes. This widget\'s own Style sections are hidden meanwhile. With no styled Galaxie Checkout on the site, the own style is used.', 'galaxie-woo' ),
			)
		);

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

		LoginControls::register_style( $this, '{{WRAPPER}}', array( 'login_checkout_style!' => 'yes' ) );
	}

	/** The checkout's sign-in look, or none: off, or no styled Galaxie Checkout on the site. */
	private function borrowed_look(): array {
		if ( 'yes' !== ( $this->get_settings( 'login_checkout_style' ) ?? '' ) ) {
			return array();
		}

		return AccountParts::look( 'checkout_login' );
	}

	/** The wrapper class the borrowed rules hang from, so they outrank this element's own. */
	public function before_render() {
		if ( $this->borrowed_look() ) {
			$this->add_render_attribute( '_wrapper', 'class', 'gx-login--checkout-style' );
		}

		parent::before_render();
	}

	protected function render(): void {
		$look = $this->borrowed_look();

		if ( $look ) {
			$css = $this->borrowed_css( $look );
			if ( '' !== $css ) {
				echo '<style>' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Elementor's own generated CSS.
			}
		}

		parent::render();
	}

	/**
	 * The rules Elementor wrote for the checkout's sign-in panel, written again
	 * for this element: this widget's own `co_` controls, the checkout's values,
	 * through Elementor's CSS engine — every palette, surface, slider and
	 * responsive value, exactly as it produced them there.
	 *
	 * The selector carries one class more than Elementor's own for this element
	 * (`.elementor-{post} .elementor-element.elementor-element-{id}`), so these
	 * win over the values left in this widget's hidden Style tab.
	 *
	 * @param array<string,mixed> $look
	 */
	private function borrowed_css( array $look ): string {
		if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			return '';
		}

		try {
			$controls = array_filter(
				$this->get_controls(),
				static fn( $control, $key ): bool => 0 === strpos( (string) $key, 'co_' ),
				ARRAY_FILTER_USE_BOTH
			);
			$values   = array_merge( (array) $this->get_settings(), $look );
			$selector = '.elementor .elementor-element.elementor-element-' . $this->get_id() . '.gx-login--checkout-style';

			$css = \Elementor\Core\Files\CSS\Post::create( (int) get_the_ID() );
			$css->add_controls_stack_style_rules( $this, $controls, $values, array( '{{ID}}', '{{WRAPPER}}' ), array( $this->get_id(), $selector ) );

			return (string) $css->get_stylesheet();
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** @return array<string,mixed> */
	protected function island_props(): array {
		$settings = $this->get_settings_for_display();
		$text     = LoginControls::texts( $settings );
		// Classes and button markup from the checkout's settings when borrowing;
		// the texts stay this widget's own.
		$look     = $this->borrowed_look();
		$editing  = class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode();

		$signed_in = ! $editing && is_user_logged_in();

		return array(
			'text'            => $text,
			'ui'              => LoginControls::ui( $look ? array_merge( $settings, $look ) : $settings, $text ),
			'genericError'    => __( 'Algo deu errado. Tente novamente.', 'galaxie-woo' ),
			'redirect'        => $this->redirect( $settings ),
			// Signed in, there is nothing to sign in to: the widget says so, or
			// says nothing. Two fields, because "no message" and "not signed in"
			// are different answers and one string cannot carry both — reading
			// the message alone would draw the form to someone already signed
			// in, offering to replace their session. The editor always draws
			// the form, to be styled.
			'signedIn'        => $signed_in,
			'signedInMessage' => $signed_in ? (string) ( $settings['login_signed_in'] ?? '' ) : '',
			'preview'         => $editing,
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

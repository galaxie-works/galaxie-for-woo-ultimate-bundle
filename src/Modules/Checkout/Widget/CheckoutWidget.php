<?php
/**
 * "Galaxie Checkout" Elementor widget — the self-contained checkout stepper.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Checkout\Widget;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Galaxie\Woo\Elementor\AbstractIslandWidget;
use Galaxie\Woo\Modules\Checkout\Module;
use Galaxie\Woo\Modules\Checkout\OrderSummary;
use Galaxie\Woo\Support\CustomerProfile;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * Self-contained by design: this widget renders WooCommerce's own
 * `[woocommerce_checkout]` output — hidden — alongside the stepper island,
 * and the island's JS moves specific live nodes out of the hidden native form
 * (`#shipping_method`, `#payment`) into its own step mounts, mirroring form
 * values into the native (hidden) fields the rest of WooCommerce still
 * submits against. So it drops onto ANY page/theme, no XStore dependency.
 *
 * Signing in comes first, always. The store does not take guest orders, so a
 * visitor who is not signed in gets the sign-in step and the order summary
 * and nothing else: the native form is not rendered for them at all (with
 * guest checkout off, WooCommerce would only print its "you must be logged
 * in" notice there anyway). Once the code is verified the page reloads and
 * this renders again, signed in, with the stepper past the first step.
 *
 * In the Elementor editor it renders the real island with sample data — the
 * step picked in "Preview step", two catalogue products in the summary — so
 * every control on this panel can be seen working while it is set.
 */
final class CheckoutWidget extends AbstractIslandWidget {

	/** The steps "Preview step" can show. */
	private const STEPS = array( 'entry', 'profile', 'address', 'payment' );

	public function get_name(): string {
		return 'galaxie-checkout';
	}

	public function get_title(): string {
		return __( 'Galaxie Checkout', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-cart-medium';
	}

	protected function island_name(): string {
		return 'checkout';
	}

	protected function register_controls(): void {
		$this->register_layout_controls();
		$this->register_text_controls();
		$this->register_style_controls();
	}

	private function register_layout_controls(): void {
		$this->start_controls_section( 'layout_section', array( 'label' => __( 'Layout', 'galaxie-woo' ) ) );

		$this->add_control(
			'preview_step',
			array(
				'label'       => __( 'Preview step', 'galaxie-woo' ),
				'description' => __( 'Only in the editor: which step to show while you style the widget.', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'entry',
				'options'     => array(
					'entry'   => __( 'Identificação', 'galaxie-woo' ),
					'profile' => __( 'Seus dados', 'galaxie-woo' ),
					'address' => __( 'Entrega', 'galaxie-woo' ),
					'payment' => __( 'Pagamento', 'galaxie-woo' ),
				),
			)
		);

		$this->add_control(
			'summary_position',
			array(
				'label'     => __( 'Order summary', 'galaxie-woo' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'right',
				'toggle'    => false,
				'separator' => 'before',
				'options'   => array(
					'left'  => array( 'title' => __( 'Left', 'galaxie-woo' ), 'icon' => 'eicon-h-align-left' ),
					'right' => array( 'title' => __( 'Right', 'galaxie-woo' ), 'icon' => 'eicon-h-align-right' ),
				),
			)
		);

		$this->add_control(
			'summary_sticky',
			array(
				'label'        => __( 'Keep summary in view while scrolling', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_responsive_control(
			'summary_offset',
			array(
				'label'      => __( 'Distance from the top when sticky', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 200 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 24 ),
				'selectors'  => array( '{{WRAPPER}} .gx-co' => '--gx-co-sticky-top: {{SIZE}}{{UNIT}};' ),
				'condition'  => array( 'summary_sticky' => 'yes' ),
			)
		);

		$this->add_control(
			'summary_open_mobile',
			array(
				'label'        => __( 'Summary open on phones', 'galaxie-woo' ),
				'description'  => __( 'On a narrow screen the summary sits above the steps as a bar with the total. Off: it starts collapsed.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
			)
		);

		$this->add_responsive_control(
			'summary_width',
			array(
				'label'      => __( 'Summary width', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array( 'min' => 260, 'max' => 560 ),
					'%'  => array( 'min' => 25, 'max' => 50 ),
				),
				'default'    => array( 'unit' => 'px', 'size' => 380 ),
				'separator'  => 'before',
				'selectors'  => array( '{{WRAPPER}} .gx-co' => '--gx-co-summary-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'column_gap',
			array(
				'label'      => __( 'Space between columns', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 120 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 48 ),
				'selectors'  => array( '{{WRAPPER}} .gx-co' => '--gx-co-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	private function register_text_controls(): void {
		$this->start_controls_section( 'text_steps_section', array( 'label' => __( 'Texts: steps', 'galaxie-woo' ) ) );

		$this->text_control( 'step_entry', __( 'Step 1 title', 'galaxie-woo' ), __( 'Identificação', 'galaxie-woo' ) );
		$this->text_control( 'step_profile', __( 'Step 2 title', 'galaxie-woo' ), __( 'Seus dados', 'galaxie-woo' ) );
		$this->text_control( 'step_address', __( 'Step 3 title', 'galaxie-woo' ), __( 'Entrega', 'galaxie-woo' ) );
		$this->text_control( 'step_payment', __( 'Step 4 title', 'galaxie-woo' ), __( 'Pagamento', 'galaxie-woo' ) );
		$this->text_control( 'edit_label', __( '"Change" link', 'galaxie-woo' ), __( 'Alterar', 'galaxie-woo' ), true );
		$this->text_control( 'logout_label', __( '"Not you?" link', 'galaxie-woo' ), __( 'Não é você? Sair', 'galaxie-woo' ) );
		$this->text_control( 'profile_button', __( 'Your details button', 'galaxie-woo' ), __( 'Continuar', 'galaxie-woo' ), true );
		$this->text_control( 'address_button', __( 'Save address button', 'galaxie-woo' ), __( 'Salvar endereço', 'galaxie-woo' ) );
		$this->text_control( 'shipping_heading', __( 'Shipping options heading', 'galaxie-woo' ), __( 'Forma de envio', 'galaxie-woo' ) );
		$this->text_control( 'payment_button', __( 'Continue to payment button', 'galaxie-woo' ), __( 'Ir para o pagamento', 'galaxie-woo' ) );
		$this->text_control( 'delivering_to', __( '"Delivering to" label', 'galaxie-woo' ), __( 'Entregar em', 'galaxie-woo' ) );

		$this->end_controls_section();

		$this->start_controls_section( 'text_entry_section', array( 'label' => __( 'Texts: sign in', 'galaxie-woo' ) ) );

		$this->text_control( 'entry_intro', __( 'Intro', 'galaxie-woo' ), __( 'Entre com seu e-mail para continuar. Enviamos um código de acesso, sem senha.', 'galaxie-woo' ), false, true );
		$this->text_control( 'tab_login', __( 'Sign in tab', 'galaxie-woo' ), __( 'Já sou cliente', 'galaxie-woo' ), true );
		$this->text_control( 'tab_register', __( 'Create account tab', 'galaxie-woo' ), __( 'Primeira compra', 'galaxie-woo' ) );
		$this->text_control( 'send_code', __( 'Send code button', 'galaxie-woo' ), __( 'Receber código', 'galaxie-woo' ) );
		$this->text_control( 'register_button', __( 'Create account button', 'galaxie-woo' ), __( 'Criar conta e receber código', 'galaxie-woo' ) );
		$this->text_control( 'code_hint', __( 'Code hint', 'galaxie-woo' ), __( 'Digite o código de 6 dígitos que enviamos para %s.', 'galaxie-woo' ), false, false, __( '%s is replaced by the e-mail address.', 'galaxie-woo' ) );
		$this->text_control( 'confirm_code', __( 'Confirm code button', 'galaxie-woo' ), __( 'Confirmar e continuar', 'galaxie-woo' ) );
		$this->text_control( 'resend_code', __( 'Resend link', 'galaxie-woo' ), __( 'Reenviar código', 'galaxie-woo' ) );
		$this->text_control( 'change_email', __( 'Change e-mail link', 'galaxie-woo' ), __( 'Usar outro e-mail', 'galaxie-woo' ) );
		$this->text_control( 'marketing', __( 'Marketing opt-in', 'galaxie-woo' ), __( 'Quero receber novidades e ofertas da EIR.', 'galaxie-woo' ), true );
		$this->text_control( 'terms', __( 'Terms checkbox', 'galaxie-woo' ), __( 'Li e aceito os termos de uso e a política de privacidade.', 'galaxie-woo' ) );

		$this->end_controls_section();

		$this->start_controls_section( 'text_summary_section', array( 'label' => __( 'Texts: order summary', 'galaxie-woo' ) ) );

		$this->text_control( 'summary_title', __( 'Title', 'galaxie-woo' ), __( 'Resumo do pedido', 'galaxie-woo' ) );
		$this->text_control( 'summary_total', __( 'Total label', 'galaxie-woo' ), __( 'Total', 'galaxie-woo' ) );
		$this->text_control( 'summary_show', __( 'Phone: show summary', 'galaxie-woo' ), __( 'Ver resumo do pedido', 'galaxie-woo' ) );
		$this->text_control( 'summary_hide', __( 'Phone: hide summary', 'galaxie-woo' ), __( 'Ocultar resumo', 'galaxie-woo' ) );

		$this->end_controls_section();
	}

	private function text_control( string $id, string $label, string $default, bool $separator = false, bool $textarea = false, string $description = '' ): void {
		$args = array(
			'label'   => $label,
			'type'    => $textarea ? Controls_Manager::TEXTAREA : Controls_Manager::TEXT,
			'default' => $default,
		);
		if ( $separator ) {
			$args['separator'] = 'before';
		}
		if ( '' !== $description ) {
			$args['description'] = $description;
		}
		$this->add_control( $id, $args );
	}

	private function register_style_controls(): void {
		// Colours are written as the island's design tokens, not onto
		// individual elements: every part of the stepper (tabs, inputs,
		// borders, the step markers) is drawn from those tokens, so one
		// control repaints everything it should and nothing it shouldn't.
		// Palette entries keep following pixfort's light/dark switch.
		$this->start_controls_section(
			'style_colors',
			array( 'label' => __( 'Colors', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);

		$tokens   = '{{WRAPPER}} .galaxie-ui';
		$palettes = array(
			'color_text'       => array( __( 'Text', 'galaxie-woo' ), '--foreground' ),
			'color_muted'      => array( __( 'Secondary text', 'galaxie-woo' ), '--muted-foreground' ),
			'color_surface'    => array( __( 'Cards and summary background', 'galaxie-woo' ), '--card' ),
			'color_border'     => array( __( 'Borders and fields', 'galaxie-woo' ), '--border' ),
			'color_primary'    => array( __( 'Buttons and current step', 'galaxie-woo' ), '--primary' ),
			'color_primary_fg' => array( __( 'Button text', 'galaxie-woo' ), '--primary-foreground' ),
		);
		foreach ( $palettes as $id => [ $label, $property ] ) {
			// Field outlines are their own token, a shade stronger than the
			// dividers; picking a border colour sets both.
			$extra = '--border' === $property ? ' --input: var(--border);' : '';
			PixfortControls::palette_control( $this, $id, $label, $tokens, $property, array(), $extra );
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'style_shape',
			array( 'label' => __( 'Shape', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);

		$this->add_control(
			'radius',
			array(
				'label'      => __( 'Corner radius (fields, buttons, cards)', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-ui' => '--radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'field_height',
			array(
				'label'      => __( 'Field and button height', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 32, 'max' => 64 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 44 ),
				'selectors'  => array( '{{WRAPPER}} .gx-co' => '--gx-co-control-h: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_summary',
			array( 'label' => __( 'Order summary box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::surface( $this, 'summary_box', '{{WRAPPER}} .gx-co-summary-box' );
		$this->end_controls_section();

		$this->start_controls_section(
			'style_steps',
			array( 'label' => __( 'Step boxes', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);
		PixfortControls::surface( $this, 'step_box', '{{WRAPPER}} .gx-co-step' );
		$this->end_controls_section();

		$this->start_controls_section(
			'style_typography',
			array( 'label' => __( 'Typography', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);

		$type = array(
			'type_step_title' => array( __( 'Step titles', 'galaxie-woo' ), '{{WRAPPER}} .gx-co-step-title' ),
			'type_body'       => array( __( 'Body text and fields', 'galaxie-woo' ), '{{WRAPPER}} .gx-co' ),
			'type_label'      => array( __( 'Field labels', 'galaxie-woo' ), '{{WRAPPER}} .gx-co [data-slot="label"]' ),
			'type_button'     => array( __( 'Buttons', 'galaxie-woo' ), '{{WRAPPER}} .gx-co [data-slot="button"], {{WRAPPER}} .gx-co #place_order' ),
			'type_summary'    => array( __( 'Summary title', 'galaxie-woo' ), '{{WRAPPER}} .gx-co-summary-title' ),
			'type_total'      => array( __( 'Summary total', 'galaxie-woo' ), '{{WRAPPER}} .gx-co-total' ),
		);
		foreach ( $type as $id => [ $label, $selector ] ) {
			$this->add_group_control(
				Group_Control_Typography::get_type(),
				array(
					'name'     => $id,
					'label'    => $label,
					'selector' => $selector,
				)
			);
		}

		$this->end_controls_section();
	}

	protected function island_props(): array {
		$settings  = $this->get_settings_for_display();
		$preview   = $this->editing();
		$logged_in = ! $preview && is_user_logged_in();
		$user      = $logged_in ? wp_get_current_user() : null;

		$props = array(
			'loggedIn'  => $logged_in,
			'userEmail' => $user ? $user->user_email : '',
			'logoutUrl' => $logged_in ? wp_logout_url( wc_get_checkout_url() ) : '',
			'profile'   => $logged_in ? CustomerProfile::status( $user->ID ) : array(
				'complete' => false,
				'missing'  => array(),
				'values'   => array(),
			),
			'address'   => $logged_in ? CustomerProfile::saved_address( $user->ID ) : array( 'has_address' => false ),
			'summary'   => $preview ? OrderSummary::sample() : OrderSummary::data(),
			'layout'    => array(
				'summaryPosition'   => 'left' === ( $settings['summary_position'] ?? 'right' ) ? 'left' : 'right',
				'summarySticky'     => 'yes' === ( $settings['summary_sticky'] ?? 'yes' ),
				'summaryOpenMobile' => 'yes' === ( $settings['summary_open_mobile'] ?? '' ),
				'summaryClass'      => PixfortControls::surface_classes( $settings, 'summary_box' ),
				'stepClass'         => PixfortControls::surface_classes( $settings, 'step_box' ),
			),
			'text'      => $this->texts( $settings ),
			'i18n'      => array(
				'genericError' => __( 'Algo deu errado. Tente novamente.', 'galaxie-woo' ),
				'noShipping'   => __( 'Não há opções de envio para este endereço. Confira o endereço e tente novamente.', 'galaxie-woo' ),
			),
			'preview'   => null,
		);

		if ( $preview ) {
			$step             = (string) ( $settings['preview_step'] ?? 'entry' );
			$props['preview'] = array( 'step' => in_array( $step, self::STEPS, true ) ? $step : 'entry' );
			$props['userEmail'] = 'cliente@exemplo.com.br';
			$props['profile']   = array(
				'complete' => true,
				'missing'  => array(),
				'values'   => array(
					'first_name' => 'Ana',
					'last_name'  => 'Souza',
					'phone'      => '+5511987654321',
					'birthdate'  => '1990-05-12',
					'cpf'        => '123.456.789-09',
				),
			);
			$props['address'] = array(
				'has_address' => true,
				'address_1'   => 'Rua Harmonia, 123',
				'address_2'   => 'Apto 42',
				'city'        => 'São Paulo',
				'state'       => 'SP',
				'postcode'    => '05435-000',
				'country'     => 'BR',
			);
		}

		return $props;
	}

	/**
	 * Every string the island prints. The ones with a control come from it;
	 * the field labels do not have one each (a panel with forty text boxes
	 * helps nobody) and go through translation instead.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,string>
	 */
	private function texts( array $settings ): array {
		$from = static fn( string $id, string $fallback ): string => '' !== trim( (string) ( $settings[ $id ] ?? '' ) ) ? (string) $settings[ $id ] : $fallback;

		return array(
			'stepEntry'       => $from( 'step_entry', __( 'Identificação', 'galaxie-woo' ) ),
			'stepProfile'     => $from( 'step_profile', __( 'Seus dados', 'galaxie-woo' ) ),
			'stepAddress'     => $from( 'step_address', __( 'Entrega', 'galaxie-woo' ) ),
			'stepPayment'     => $from( 'step_payment', __( 'Pagamento', 'galaxie-woo' ) ),
			'edit'            => $from( 'edit_label', __( 'Alterar', 'galaxie-woo' ) ),
			'logout'          => $from( 'logout_label', __( 'Não é você? Sair', 'galaxie-woo' ) ),
			'profileButton'   => $from( 'profile_button', __( 'Continuar', 'galaxie-woo' ) ),
			'addressButton'   => $from( 'address_button', __( 'Salvar endereço', 'galaxie-woo' ) ),
			'shippingHeading' => $from( 'shipping_heading', __( 'Forma de envio', 'galaxie-woo' ) ),
			'paymentButton'   => $from( 'payment_button', __( 'Ir para o pagamento', 'galaxie-woo' ) ),
			'deliveringTo'    => $from( 'delivering_to', __( 'Entregar em', 'galaxie-woo' ) ),
			'entryIntro'      => $from( 'entry_intro', '' ),
			'tabLogin'        => $from( 'tab_login', __( 'Já sou cliente', 'galaxie-woo' ) ),
			'tabRegister'     => $from( 'tab_register', __( 'Primeira compra', 'galaxie-woo' ) ),
			'sendCode'        => $from( 'send_code', __( 'Receber código', 'galaxie-woo' ) ),
			'registerButton'  => $from( 'register_button', __( 'Criar conta e receber código', 'galaxie-woo' ) ),
			'codeHint'        => $from( 'code_hint', __( 'Digite o código de 6 dígitos que enviamos para %s.', 'galaxie-woo' ) ),
			'confirmCode'     => $from( 'confirm_code', __( 'Confirmar e continuar', 'galaxie-woo' ) ),
			'resendCode'      => $from( 'resend_code', __( 'Reenviar código', 'galaxie-woo' ) ),
			'changeEmail'     => $from( 'change_email', __( 'Usar outro e-mail', 'galaxie-woo' ) ),
			'marketing'       => $from( 'marketing', __( 'Quero receber novidades e ofertas da EIR.', 'galaxie-woo' ) ),
			'terms'           => $from( 'terms', __( 'Li e aceito os termos de uso e a política de privacidade.', 'galaxie-woo' ) ),
			'summaryTitle'    => $from( 'summary_title', __( 'Resumo do pedido', 'galaxie-woo' ) ),
			'summaryTotal'    => $from( 'summary_total', __( 'Total', 'galaxie-woo' ) ),
			'summaryShow'     => $from( 'summary_show', __( 'Ver resumo do pedido', 'galaxie-woo' ) ),
			'summaryHide'     => $from( 'summary_hide', __( 'Ocultar resumo', 'galaxie-woo' ) ),
			'quantity'        => __( 'Quantidade', 'galaxie-woo' ),
			'email'           => __( 'E-mail', 'galaxie-woo' ),
			'firstName'       => __( 'Nome', 'galaxie-woo' ),
			'lastName'        => __( 'Sobrenome', 'galaxie-woo' ),
			'birthdate'       => __( 'Data de nascimento', 'galaxie-woo' ),
			'cpf'             => __( 'CPF', 'galaxie-woo' ),
			'phone'           => __( 'Celular', 'galaxie-woo' ),
			'address1'        => __( 'Endereço', 'galaxie-woo' ),
			'address2'        => __( 'Complemento', 'galaxie-woo' ),
			'address2Hint'    => __( 'Apartamento, bloco, referência (opcional)', 'galaxie-woo' ),
			'city'            => __( 'Cidade', 'galaxie-woo' ),
			'state'           => __( 'UF', 'galaxie-woo' ),
			'postcode'        => __( 'CEP', 'galaxie-woo' ),
			'previewShipping' => __( 'As opções de frete do WooCommerce aparecem aqui, calculadas para o endereço.', 'galaxie-woo' ),
			'previewPayment'  => __( 'Os métodos de pagamento do WooCommerce e o botão de finalizar aparecem aqui.', 'galaxie-woo' ),
		);
	}

	/** Editor canvas or its preview: sample data, no cart, no native form. */
	private function editing(): bool {
		$elementor = \Elementor\Plugin::$instance;

		return $elementor->editor->is_edit_mode() || ( isset( $elementor->preview ) && $elementor->preview->is_preview_mode() );
	}

	protected function render(): void {
		if ( $this->editing() ) {
			echo '<div class="galaxie-checkout">';
			parent::render();
			echo '</div>';
			return;
		}

		if ( ! function_exists( 'is_checkout' ) || null === WC()->cart || WC()->cart->is_empty() ) {
			echo '<p>' . esc_html__( 'Seu carrinho está vazio.', 'galaxie-woo' ) . '</p>';
			return;
		}

		echo '<div class="galaxie-checkout">';

		// Signed-out visitors only ever see the sign-in step, and with guest
		// checkout off WooCommerce has no form to give them anyway.
		if ( is_user_logged_in() ) {
			echo '<div class="galaxie-checkout-native" data-galaxie-native-checkout hidden>';
			echo do_shortcode( '[woocommerce_checkout]' );
			echo '</div>';
		}

		// The summary's refresh target: WooCommerce replaces this node with a
		// fresh copy on every `updated_checkout` (see Module::summary_fragment).
		echo Module::summary_script( OrderSummary::data() ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON built and hex-escaped by summary_script().

		parent::render(); // Enqueues assets + prints the `data-galaxie-island="checkout"` mount.

		echo '</div>';
	}
}

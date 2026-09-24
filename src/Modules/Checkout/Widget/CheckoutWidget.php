<?php
/**
 * "Galaxie Checkout" Elementor widget — the self-contained checkout stepper.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Checkout\Widget;

use Elementor\Controls_Manager;
use Galaxie\Woo\Elementor\AbstractIslandWidget;
use Galaxie\Woo\Modules\Checkout\Module;
use Galaxie\Woo\Modules\Checkout\OrderSummary;
use Galaxie\Woo\Modules\Checkout\PaymentMarkup;
use Galaxie\Woo\Support\CustomerProfile;
use Galaxie\Woo\Support\LoginControls;
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

	/** Stands in for a label pixfort draws before the island knows it (place order, alert text). */
	/** The sign-in's own marker, so both widgets swap the same string. */
	private const LABEL_MARKER = LoginControls::MARKER;

	/**
	 * The text roles, as prefix => [section label, selector, defaults, size
	 * vocabulary]. Defaults are the plugin's usual ones for each role: a
	 * wizard step title is the Kit Builder's h5; labels text-sm bold as in
	 * the account forms; body and hints not bold; errors red. The key after
	 * `co_` names the class string the island reads (co_step_title → stepTitle).
	 */
	private const TEXTS = array(
		'co_step_title'  => array( 'Texts: step titles', '.gx-co-step-title', array( 'size' => 'h5', 'bold' => 'font-weight-bold' ), 'heading' ),
		'co_rate_name'   => array( 'Texts: shipping option', '.galaxie-shipping-mount #shipping_method label', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ), 'text' ),
		'co_method_name' => array( 'Texts: payment method', '#payment ul.payment_methods li > label', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ), 'text' ),
		'co_token_number'     => array( 'Texts: saved card number', '.gx-co-card-number', array( 'bold' => 'font-weight-bold' ), 'text' ),
		'co_token_expiry'     => array( 'Texts: saved card expiry', '.gx-co-card-expiry', array( 'size' => 'text-xs', 'bold' => '' ), 'text' ),
		'co_token_badge_text' => array( 'Texts: saved card badges', '.gx-co-card-badge', array( 'size' => 'text-xs', 'bold' => 'font-weight-bold' ), 'text' ),
		'co_sum_title'   => array( 'Order summary: title', '.gx-co-summary-title', array( 'size' => 'text-18', 'bold' => 'font-weight-bold' ), 'text' ),
		'co_item_name'   => array( 'Order summary: product name', '.gx-co-item-name', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ), 'text' ),
		'co_item_meta'   => array( 'Order summary: product details', '.gx-co-item-meta', array( 'size' => 'text-xs', 'bold' => '' ), 'text' ),
		'co_item_price'  => array( 'Order summary: product price', '.gx-co-item-price', array( 'size' => 'text-sm', 'bold' => '' ), 'text' ),
		'co_row_label'   => array( 'Order summary: amount labels', '.gx-co-row-label', array( 'size' => 'text-sm', 'bold' => '' ), 'text' ),
		'co_row_value'   => array( 'Order summary: amounts', '.gx-co-row-value', array( 'size' => 'text-sm', 'bold' => '' ), 'text' ),
		'co_total_label' => array( 'Order summary: total label', '.gx-co-total-label', array( 'bold' => 'font-weight-bold' ), 'text' ),
		'co_total_value' => array( 'Order summary: total', '.gx-co-total', array( 'size' => 'text-20', 'bold' => 'font-weight-bold' ), 'text' ),
	);

	/**
	 * The button roles, as prefix => [section label, defaults]. The defaults
	 * are the plugin's: a form's submit is the primary button at full width;
	 * the step forward carries the arrow the account lists use; "Alterar",
	 * "Reenviar código" and the like are link buttons, small and without
	 * padding, as the Address Book's secondary actions are.
	 */
	private const BUTTONS = array(
		'co_btn_next'  => array( 'Button · Continue to payment', array( 'color' => 'primary', 'full' => 'yes', 'icon' => 'Line/pixfort-icon-arrow-right-1', 'icon_position' => 'after' ) ),
		'co_btn_place' => array( 'Button · Place order', array( 'color' => 'primary', 'full' => 'yes', 'size' => 'lg' ) ),
	);

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

	/**
	 * The Style tab, in the order the checkout reads: layout, the step
	 * indicator and its box, the texts, the sign-in tabs, the fields, the
	 * shipping and payment rows WooCommerce prints, the order summary, the
	 * warnings, then one section per button.
	 *
	 * Every part is pixfort's own control set, as in every other widget of the
	 * plugin — text() for words, surface() for boxes and pills, button() for
	 * buttons, alert() for warnings — and reaches the island as the class
	 * strings and the button markup pixfort prints (see ui()). The Kit Builder
	 * is the model: it is the other wizard, and its buttons also sit in
	 * "Button ·" sections with their labels on the Content tab.
	 */
	private function register_style_controls(): void {
		$style = static fn( string $label ): array => array( 'label' => $label, 'tab' => Controls_Manager::TAB_STYLE );

		$this->start_controls_section( 'co_layout_style', $style( __( 'Layout', 'galaxie-woo' ) ) );
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

		$this->add_responsive_control(
			'co_step_gap',
			array(
				'label'      => __( 'Space between steps', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}} .gx-co-main' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		// The Kit Builder's step dot: a pill on the site's radius scale.
		$this->start_controls_section( 'co_steps_style', $style( __( 'Step indicator', 'galaxie-woo' ) ) );
		PixfortControls::surface( $this, 'co_dot', '{{WRAPPER}} .gx-co-step-dot', array( 'rounded' => 'badge-pill', 'radius_set' => 'badge' ), array(), false );
		PixfortControls::palette_control( $this, 'co_dot_color', __( 'Dot number', 'galaxie-woo' ), '{{WRAPPER}} .gx-co-step-dot', 'color' );
		PixfortControls::palette_control( $this, 'co_dot_active_bg', __( 'Current dot', 'galaxie-woo' ), '{{WRAPPER}} .gx-co-step.is-current .gx-co-step-dot', 'background-color' );
		PixfortControls::palette_control( $this, 'co_dot_active_color', __( 'Current number', 'galaxie-woo' ), '{{WRAPPER}} .gx-co-step.is-current .gx-co-step-dot', 'color' );
		PixfortControls::palette_control( $this, 'co_dot_done_bg', __( 'Done dot', 'galaxie-woo' ), '{{WRAPPER}} .gx-co-step.is-done .gx-co-step-dot', 'background-color' );
		PixfortControls::palette_control( $this, 'co_dot_done_color', __( 'Done check', 'galaxie-woo' ), '{{WRAPPER}} .gx-co-step.is-done .gx-co-step-dot', 'color' );
		$this->end_controls_section();

		$this->start_controls_section( 'co_step_box_style', $style( __( 'Step box', 'galaxie-woo' ) ) );
		PixfortControls::surface( $this, 'co_step', '{{WRAPPER}} .gx-co-step', array( 'rounded' => 'rounded-lg' ) );
		$this->add_control( 'co_step_current_heading', array( 'label' => __( 'Current step', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $this, 'co_step_current_bg', __( 'Background', 'galaxie-woo' ), '{{WRAPPER}} .gx-co-step.is-current', 'background-color' );
		PixfortControls::palette_control( $this, 'co_step_current_border', __( 'Border color', 'galaxie-woo' ), '{{WRAPPER}} .gx-co-step.is-current', 'border-color', array(), ' border-style: solid;' );
		$this->end_controls_section();

		$this->text_sections( array( 'co_step_title' ) );

		// The sign-in's own panel — its texts, tabs, fields, switch, warning
		// and its two buttons — belongs to the component both widgets draw.
		LoginControls::register_style( $this );

		// WooCommerce's own shipping list, moved into the delivery step: the
		// Shipping Options widget's rows.
		$rate = '{{WRAPPER}} .galaxie-shipping-mount #shipping_method li';
		$this->start_controls_section( 'co_rate_style', $style( __( 'Delivery: shipping options', 'galaxie-woo' ) ) );
		PixfortControls::surface( $this, 'co_rate', $rate, array( 'rounded' => 'rounded-lg' ) );
		$this->add_control( 'co_rate_selected_heading', array( 'label' => __( 'Chosen option', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $this, 'co_rate_selected_bg', __( 'Background', 'galaxie-woo' ), $rate . ':has(input:checked)', 'background-color' );
		PixfortControls::palette_control( $this, 'co_rate_selected_border', __( 'Border color', 'galaxie-woo' ), $rate . ':has(input:checked)', 'border-color', array(), ' border-style: solid;' );
		PixfortControls::palette_control( $this, 'co_rate_radio', __( 'Radio', 'galaxie-woo' ), $rate . ' input[type="radio"]', 'accent-color' );
		$this->end_controls_section();
		$this->text_sections( array( 'co_rate_name' ) );

		// And WooCommerce's payment block.
		$method = '{{WRAPPER}} .gx-co #payment ul.payment_methods li';
		$this->start_controls_section( 'co_method_style', $style( __( 'Payment: methods', 'galaxie-woo' ) ) );
		PixfortControls::surface( $this, 'co_method', $method, array( 'rounded' => 'rounded-lg' ) );
		$this->add_control( 'co_method_selected_heading', array( 'label' => __( 'Chosen method', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $this, 'co_method_selected_border', __( 'Border color', 'galaxie-woo' ), $method . ':has(> input:checked)', 'border-color', array(), ' border-style: solid;' );
		PixfortControls::palette_control( $this, 'co_method_radio', __( 'Radio', 'galaxie-woo' ), $method . ' input[type="radio"]', 'accent-color' );
		$this->add_control( 'co_method_box_heading', array( 'label' => __( 'Method details box', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::surface( $this, 'co_method_box', '{{WRAPPER}} .gx-co #payment div.payment_box' );
		$this->end_controls_section();
		$this->text_sections( array( 'co_method_name' ) );

		// Saved cards: the Payment Methods widget's parts (brand icon, number,
		// expiry, badges with a colour per state) as selectable rows. The new
		// card's own fields are Stripe's, inside Stripe's frame; they take
		// their look from the "Fields" section above (see stripeAppearance()).
		$token = '{{WRAPPER}} .gx-co #payment .wc-saved-payment-methods li';
		$this->start_controls_section( 'co_token_style', $style( __( 'Payment: saved cards', 'galaxie-woo' ) ) );
		PixfortControls::surface( $this, 'co_token', $token, array( 'rounded' => 'rounded-lg' ) );
		$this->add_control( 'co_token_selected_heading', array( 'label' => __( 'Chosen card', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $this, 'co_token_selected_bg', __( 'Background', 'galaxie-woo' ), $token . ':has(input:checked)', 'background-color' );
		PixfortControls::palette_control( $this, 'co_token_selected_border', __( 'Border color', 'galaxie-woo' ), $token . ':has(input:checked)', 'border-color', array(), ' border-style: solid;' );
		PixfortControls::palette_control( $this, 'co_token_radio', __( 'Radio', 'galaxie-woo' ), $token . ' input[type="radio"]', 'accent-color' );
		$this->add_control( 'co_token_icon_heading', array( 'label' => __( 'Brand icon', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::icon_color( $this, 'co_token_icon', __( 'Color', 'galaxie-woo' ), '{{WRAPPER}} .gx-co-card-brand' );
		$this->add_control( 'co_token_badge_heading', array( 'label' => __( 'Badges', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::surface( $this, 'co_token_badge', '{{WRAPPER}} .gx-co-card-badge', array( 'rounded' => 'badge-pill', 'radius_set' => 'badge' ), array(), false );
		foreach ( array(
			'default'  => __( 'Default card', 'galaxie-woo' ),
			'expiring' => __( 'Expiring soon', 'galaxie-woo' ),
			'expired'  => __( 'Expired', 'galaxie-woo' ),
		) as $state => $label ) {
			$badge = '{{WRAPPER}} .gx-co-card-badge.is-' . $state;
			$this->add_control( 'co_token_badge_' . $state . '_heading', array( 'label' => $label, 'type' => Controls_Manager::HEADING ) );
			PixfortControls::palette_control( $this, 'co_token_badge_' . $state . '_bg', __( 'Background', 'galaxie-woo' ), $badge, 'background-color' );
			PixfortControls::palette_control( $this, 'co_token_badge_' . $state . '_color', __( 'Text color', 'galaxie-woo' ), $badge, 'color' );
		}
		$this->end_controls_section();
		$this->text_sections( array( 'co_token_number', 'co_token_expiry', 'co_token_badge_text' ) );

		$this->start_controls_section( 'summary_box_style', $style( __( 'Order summary: box', 'galaxie-woo' ) ) );
		PixfortControls::surface( $this, 'summary_box', '{{WRAPPER}} .gx-co-summary-box', array( 'rounded' => 'rounded-lg' ) );
		PixfortControls::palette_control( $this, 'co_divider', __( 'Divider color', 'galaxie-woo' ), '{{WRAPPER}} .gx-co-divider', 'border-top-color', array(), ' border-top-style: solid;' );
		$this->end_controls_section();

		$this->start_controls_section( 'co_thumb_style', $style( __( 'Order summary: product photo', 'galaxie-woo' ) ) );
		PixfortControls::thumb( $this, 'co_thumb', '{{WRAPPER}} .gx-co-thumb', array( 'size' => 56 ) );
		$this->add_control( 'co_qty_heading', array( 'label' => __( 'Quantity badge', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::surface( $this, 'co_qty', '{{WRAPPER}} .gx-co-qty', array( 'rounded' => 'badge-pill', 'radius_set' => 'badge' ), array(), false );
		PixfortControls::text( $this, 'co_qty_text', array( 'size' => 'text-xs', 'bold' => 'font-weight-bold' ), array(), '{{WRAPPER}} .gx-co-qty', 'text', array( 'inline', 'position' ) );
		$this->end_controls_section();
		$this->text_sections( array( 'co_sum_title', 'co_item_name', 'co_item_meta', 'co_item_price', 'co_row_label', 'co_row_value', 'co_total_label', 'co_total_value' ) );

		// Buttons: the labels are on the Content tab, so `text` is skipped.
		// The section id is `_button_style`, never `{prefix}_style`: button()
		// registers that id itself (its Button style select).
		foreach ( self::BUTTONS as $prefix => [ $label, $defaults ] ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- labels listed in BUTTONS.
			$this->start_controls_section( $prefix . '_button_style', $style( __( $label, 'galaxie-woo' ) ) );
			PixfortControls::button( $this, $prefix, $defaults, array(), '{{WRAPPER}}', array( 'text' ) );
			$this->end_controls_section();
		}
	}

	/**
	 * One Style section per text role, inline: classes on the island's own
	 * markup, with the defaults listed in TEXTS.
	 *
	 * @param string[] $prefixes
	 */
	private function text_sections( array $prefixes ): void {
		foreach ( $prefixes as $prefix ) {
			[ $label, $selector, $defaults, $sizes ] = self::TEXTS[ $prefix ];
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- labels listed in TEXTS.
			$this->start_controls_section( $prefix . '_style', array( 'label' => __( $label, 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, $defaults, array(), '{{WRAPPER}} ' . $selector, $sizes, array( 'inline', 'position' ) );
			$this->end_controls_section();
		}
	}

	/**
	 * What the island needs to draw pixfort's parts: the class strings the
	 * text and surface controls stand for, and each button as pixfort's own
	 * markup with its label already in it. Built here rather than in
	 * TypeScript because pixfort decides some of those classes (shadows, hover
	 * effects) and all of the button markup, and only PHP can ask it.
	 *
	 * @param array<string,mixed>  $settings
	 * @param array<string,string> $text
	 * @return array<string,mixed>
	 */
	private function ui( array $settings, array $text ): array {
		$tc = static fn( string $prefix ): string => PixfortControls::text_classes( $settings, $prefix );
		$sc = static fn( string $prefix ): string => PixfortControls::surface_classes( $settings, $prefix );

		$shared = LoginControls::ui( $settings, $text );

		// co_step_title → stepTitle, the key the island reads.
		$cls = $shared['cls'];
		foreach ( array_keys( self::TEXTS ) as $prefix ) {
			$cls[ lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', substr( $prefix, 3 ) ) ) ) ) ] = $tc( $prefix );
		}

		$cls += array(
			'stepBox'    => $sc( 'co_step' ),
			'stepDot'    => $sc( 'co_dot' ),
			'rate'       => $sc( 'co_rate' ),
			'method'     => $sc( 'co_method' ),
			'methodBox'  => $sc( 'co_method_box' ),
			'summaryBox' => $sc( 'summary_box' ),
			'thumb'      => PixfortControls::thumb_classes( $settings, 'co_thumb' ),
			'qty'        => trim( $tc( 'co_qty_text' ) . ' ' . $sc( 'co_qty' ) ),
			'token'      => $sc( 'co_token' ),
			'tokenBadge' => trim( $tc( 'co_token_badge_text' ) . ' ' . $sc( 'co_token_badge' ) ),
		);

		$button = static fn( string $prefix, string $label ): array => array(
			'html' => PixfortControls::render_button( $settings, $prefix, $label ),
			'full' => 'yes' === ( $settings[ $prefix . '_full' ] ?? '' ),
		);

		return array(
			'cls'     => $cls,
			'buttons' => $shared['buttons'] + array(
				'profileButton'  => $button( 'co_btn_main', $text['profileButton'] ),
				'addressButton'  => $button( 'co_btn_main', $text['addressButton'] ),
				'paymentButton'  => $button( 'co_btn_next', $text['paymentButton'] ),
				// The place-order label is WooCommerce's (gateways rename it), so
				// this one is drawn around a marker the island swaps for it.
				'placeOrder'     => $button( 'co_btn_place', self::LABEL_MARKER ),
				'edit'           => $button( 'co_btn_link', $text['edit'] ),
				'logout'         => $button( 'co_btn_link', $text['logout'] ),
			),
			'alert'   => $shared['alert'],
			'marker'  => $shared['marker'],
		);
	}

	protected function island_props(): array {
		$settings  = $this->get_settings_for_display();
		$preview   = $this->editing();
		$logged_in = ! $preview && is_user_logged_in();
		$user      = $logged_in ? wp_get_current_user() : null;
		$text      = $this->texts( $settings );

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
			),
			'text'      => $text,
			'ui'        => $this->ui( $settings, $text ),
			'i18n'      => array(
				'genericError' => __( 'Algo deu errado. Tente novamente.', 'galaxie-woo' ),
				'noShipping'   => __( 'Não há opções de envio para este endereço. Confira o endereço e tente novamente.', 'galaxie-woo' ),
			),
			'preview'   => null,
		);

		if ( $preview ) {
			$step             = (string) ( $settings['preview_step'] ?? 'entry' );
			$props['preview'] = array(
				'step'    => in_array( $step, self::STEPS, true ) ? $step : 'entry',
				'payment' => PaymentMarkup::sample(),
			);
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

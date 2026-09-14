<?php
/**
 * "Galaxie Account Payment Methods": the cards the customer saved.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\AccountEndpoints;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\Dialog;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * Each saved payment method drawn as the card it is — brand mark, chip, the
 * last four digits, the expiry — from WooCommerce's own list,
 * `woocommerce_saved_payment_methods_list`, rather than from any one gateway.
 * Stripe, or whatever replaces it, adds its methods to that list, so the widget
 * never needs to know which gateway holds the card.
 *
 * Nothing here handles a card number. Making a card the default and deleting
 * one go through WooCommerce's own nonce-protected links, which is also what
 * lets the gateway hear about the deletion and detach the card on its side.
 * Adding a card opens WooCommerce's add-payment-method screen: that is the one
 * page where a gateway loads its secure card form.
 */
final class AccountPaymentMethodsWidget extends Widget_Base {

	/**
	 * Brand name, lowercased and stripped to letters => pixfort icon. The two
	 * brands pixfort draws come from its Solid set; any other brand — Elo,
	 * Hipercard, Amex — gets its generic card outline rather than a wrong logo.
	 */
	private const BRAND_ICONS = array(
		'visa'       => 'Solid/pixfort-icon-visa-1',
		'mastercard' => 'Solid/pixfort-icon-mastercard-1',
		'master'     => 'Solid/pixfort-icon-mastercard-1',
	);

	private const GENERIC_ICON = 'Line/pixfort-icon-credit-card-1';

	public function get_name(): string {
		return 'galaxie-account-payment-methods';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Payment Methods', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-price-table';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'payment', 'cards', 'cartões', 'formas de pagamento', 'stripe' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'pm_section', array( 'label' => __( 'Payment methods', 'galaxie-woo' ) ) );

		$this->add_control( 'pm_heading', array( 'label' => __( 'Heading', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Formas de pagamento', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_intro', array( 'label' => __( 'Intro text', 'galaxie-woo' ), 'type' => Controls_Manager::TEXTAREA, 'rows' => 2, 'default' => __( 'Os cartões que você salvou para comprar mais rápido. Os dados ficam guardados com o processador de pagamento, nunca na loja.', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_empty_text', array( 'label' => __( 'When there are no cards', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Você ainda não salvou nenhum cartão.', 'galaxie-woo' ) ) );

		$this->add_control( 'pm_expires_label', array( 'label' => __( 'Expiry label', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Validade', 'galaxie-woo' ), 'separator' => 'before' ) );
		$this->add_control( 'pm_badge_default', array( 'label' => __( 'Default badge', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Padrão', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_badge_expiring', array( 'label' => __( 'Expiring soon badge', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Vence em breve', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_badge_expired', array( 'label' => __( 'Expired badge', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Vencido', 'galaxie-woo' ) ) );

		$this->add_control( 'pm_new_heading', array( 'label' => __( 'Adding a card', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		$this->add_control(
			'pm_new_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'With the WooCommerce Stripe plugin active, a form opens below the cards with Stripe\'s own secure fields, and after saving the customer is asked whether the new card becomes the default. Without it, the add button opens WooCommerce\'s screen.', 'galaxie-woo' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);
		$this->add_control( 'pm_form_title', array( 'label' => __( 'Form title', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Novo cartão', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_number_label', array( 'label' => __( 'Card number label', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Número do cartão', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_cvc_label', array( 'label' => __( 'Security code label', 'galaxie-woo' ), 'description' => __( 'The expiry field uses the Expiry label above.', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'CVC', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_saved', array( 'label' => __( 'Card saved message', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Cartão salvo.', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_default_saved', array( 'label' => __( 'Default changed message', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Cartão padrão atualizado.', 'galaxie-woo' ) ) );
		$this->add_control(
			'pm_form_preview',
			array(
				'label'        => __( 'Show the form in the editor', 'galaxie-woo' ),
				'description'  => __( 'Keeps the add card form open, with sample fields, while you style it.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'pm_show_icons',
			array(
				'label'        => __( 'Card brand icon', 'galaxie-woo' ),
				'description'  => __( 'Visa and Mastercard get their own mark; any other brand gets a generic card.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before',
			)
		);

		$this->add_control( 'pm_show_brand_name', array( 'label' => __( 'Brand name', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'pm_show_chip', array( 'label' => __( 'Chip', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );

		$this->add_responsive_control(
			'pm_columns',
			array(
				'label'          => __( 'Columns', 'galaxie-woo' ),
				'type'           => Controls_Manager::SELECT,
				'options'        => array( '1' => '1', '2' => '2', '3' => '3' ),
				'default'        => '2',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'selectors'      => array( '{{WRAPPER}} .galaxie-pm-cards' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
				'separator'      => 'before',
			)
		);

		$this->end_controls_section();

		$buttons = array(
			'pm_add'     => array( __( 'Add card button', 'galaxie-woo' ), array( 'text' => __( 'Adicionar cartão', 'galaxie-woo' ), 'size' => 'sm' ) ),
			'pm_default' => array( __( 'Card: make default button', 'galaxie-woo' ), array( 'text' => __( 'Usar como padrão', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
			'pm_delete'  => array( __( 'Card: delete button', 'galaxie-woo' ), array( 'text' => __( 'Excluir', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
			'pm_save'    => array( __( 'Add card form: save button', 'galaxie-woo' ), array( 'text' => __( 'Salvar cartão', 'galaxie-woo' ), 'size' => 'sm' ) ),
			'pm_cancel'  => array( __( 'Add card form: cancel button', 'galaxie-woo' ), array( 'text' => __( 'Cancelar', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
		);

		foreach ( $buttons as $prefix => $button ) {
			$this->start_controls_section( $prefix . '_section', array( 'label' => $button[0] ) );
			PixfortControls::button( $this, $prefix, $button[1] );
			$this->end_controls_section();
		}

		// The Style tab follows the screen from top to bottom: header, the cards
		// and what is on them, the add button and its form, then the dialogs.
		// Every section names the part it styles.
		$this->text_sections(
			array(
				'pm_heading_text' => array( __( 'Header: heading', 'galaxie-woo' ), '.galaxie-pm-heading', array( 'size' => 'text-20', 'bold' => 'font-weight-bold' ), true ),
				'pm_intro_text'   => array( __( 'Header: intro text', 'galaxie-woo' ), '.galaxie-pm-intro', array( 'bold' => '' ), true ),
			)
		);

		$this->start_controls_section( 'pm_card_style', array( 'label' => __( 'Card: box and layout', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'pm_card', '{{WRAPPER}} .galaxie-pm-card', array( 'rounded' => 'rounded-lg' ) );

		$this->add_control(
			'pm_card_ratio',
			array(
				'label'        => __( 'Credit card proportions', 'galaxie-woo' ),
				'description'  => __( 'The 85.6 × 54 mm shape of a real card. Off, the card is as tall as its content.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before',
				'selectors'    => array( '{{WRAPPER}} .galaxie-pm-card:not(.is-new)' => 'aspect-ratio: 1.586 / 1;' ),
			)
		);

		$this->add_responsive_control(
			'pm_card_width',
			array(
				'label'      => __( 'Card max width', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array( 'px' => array( 'min' => 200, 'max' => 640 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-pm-item' => 'max-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'pm_gap',
			array(
				'label'      => __( 'Space between cards', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-pm-cards' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'pm_actions_space',
			array(
				'label'      => __( 'Space between card and buttons', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-pm-item' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'pm_actions_gap',
			array(
				'label'      => __( 'Space between buttons', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-pm-card-actions' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'pm_chip_style', array( 'label' => __( 'Card: chip', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'pm_show_chip' => 'yes' ) ) );

		$this->add_responsive_control(
			'pm_chip_width',
			array(
				'label'      => __( 'Width', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 16, 'max' => 96 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-pm-chip' => 'width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'pm_chip_height',
			array(
				'label'      => __( 'Height', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 12, 'max' => 72 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-pm-chip' => 'height: {{SIZE}}{{UNIT}};' ),
			)
		);

		// Background — pixfort's gradients included — corners, shadow and border,
		// the same box set every other surface in the widget has.
		PixfortControls::surface( $this, 'pm_chip', '{{WRAPPER}} .galaxie-pm-chip', array(), array(), false );

		$this->end_controls_section();

		$this->start_controls_section( 'pm_icon_style', array( 'label' => __( 'Card: brand icon', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'pm_show_icons' => 'yes' ) ) );

		$this->add_responsive_control(
			'pm_icon_size',
			array(
				'label'      => __( 'Size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 16, 'max' => 96 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-pm-brand .pixfort-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);

		PixfortControls::icon_color( $this, 'pm_icon_color', __( 'Color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-pm-brand' );

		$this->end_controls_section();

		$this->text_sections(
			array(
				'pm_number_text' => array( __( 'Card: number', 'galaxie-woo' ), '.galaxie-pm-number', array( 'size' => 'text-20', 'bold' => '' ) ),
				'pm_expiry_text' => array( __( 'Card: expiry', 'galaxie-woo' ), '.galaxie-pm-expiry', array( 'size' => 'text-sm', 'bold' => '' ) ),
				'pm_brand_text'  => array( __( 'Card: brand name', 'galaxie-woo' ), '.galaxie-pm-brand-name', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ) ),
			)
		);

		$this->start_controls_section( 'pm_badge_style', array( 'label' => __( 'Card badges: shape (all)', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'pm_badge', '{{WRAPPER}} .galaxie-pm-badge', array( 'rounded' => 'badge-pill', 'radius_set' => 'badge' ) );
		$this->end_controls_section();

		$this->text_sections(
			array(
				'pm_badge_text' => array( __( 'Card badges: text (all)', 'galaxie-woo' ), '.galaxie-pm-badge', array( 'size' => 'text-xs', 'bold' => 'font-weight-bold' ) ),
			)
		);

		// Each badge says something different — this is the card used by default,
		// this one is about to expire, this one no longer works — so each gets its
		// own colours on top of the shared shape and text above. Left on Default,
		// a badge keeps the shared look.
		$badges = array(
			'default'  => __( 'Card badge: "default"', 'galaxie-woo' ),
			'expiring' => __( 'Card badge: "expiring soon"', 'galaxie-woo' ),
			'expired'  => __( 'Card badge: "expired"', 'galaxie-woo' ),
		);

		foreach ( $badges as $type => $label ) {
			$selector = '{{WRAPPER}} .galaxie-pm-badge.is-' . $type;

			$this->start_controls_section( 'pm_badge_' . $type . '_style', array( 'label' => $label, 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::palette_control( $this, 'pm_badge_' . $type . '_bg', __( 'Background', 'galaxie-woo' ), $selector, 'background-color' );
			PixfortControls::palette_control( $this, 'pm_badge_' . $type . '_color', __( 'Text color', 'galaxie-woo' ), $selector, 'color' );
			PixfortControls::palette_control( $this, 'pm_badge_' . $type . '_border', __( 'Border color', 'galaxie-woo' ), $selector, 'border-color' );
			$this->end_controls_section();
		}

		$this->text_sections(
			array(
				'pm_empty_body' => array( __( 'List: no cards message', 'galaxie-woo' ), '.galaxie-pm-empty', array( 'bold' => '' ) ),
			)
		);

		AccountParts::add_area_controls( $this, 'pm_add_area', '{{WRAPPER}} .galaxie-pm-toolbar', __( 'Add card button: area and separator', 'galaxie-woo' ) );

		$this->start_controls_section( 'pm_form_style', array( 'label' => __( 'Add card form: box and spacing', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		PixfortControls::surface( $this, 'pm_form', '{{WRAPPER}} .galaxie-pm-form', array( 'rounded' => 'rounded-lg' ) );

		$sliders = array(
			'pm_form_width' => array( __( 'Max width', 'galaxie-woo' ), array( 'px', '%' ), 280, 1200, '.galaxie-pm-form', 'max-width' ),
			'pm_form_space' => array( __( 'Space above the form', 'galaxie-woo' ), array( 'px' ), 0, 80, '.galaxie-pm-form', 'margin-top' ),
			'pm_form_gap'   => array( __( 'Space between title, fields and buttons', 'galaxie-woo' ), array( 'px' ), 0, 60, '.galaxie-pm-form', 'gap' ),
			'pm_fields_gap' => array( __( 'Space between fields', 'galaxie-woo' ), array( 'px' ), 0, 40, '.galaxie-pm-fields', 'gap' ),
			'pm_label_gap'  => array( __( 'Space between label and field', 'galaxie-woo' ), array( 'px' ), 0, 24, '.galaxie-pm-field', 'gap' ),
		);

		foreach ( $sliders as $id => $slider ) {
			$this->add_responsive_control(
				$id,
				array(
					'label'      => $slider[0],
					'type'       => Controls_Manager::SLIDER,
					'size_units' => $slider[1],
					'range'      => array( 'px' => array( 'min' => $slider[2], 'max' => $slider[3] ) ),
					'separator'  => 'pm_form_width' === $id ? 'before' : '',
					'selectors'  => array( '{{WRAPPER}} ' . $slider[4] => $slider[5] . ': {{SIZE}}{{UNIT}};' ),
				)
			);
		}

		$this->end_controls_section();

		// Stripe's fields are iframes: what is typed inside them cannot take a
		// class or a stylesheet. The script reads these boxes' computed colour,
		// font and size and hands them to Stripe, so the controls still work.
		$this->start_controls_section( 'pm_field_style', array( 'label' => __( 'Add card form: fields', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		PixfortControls::surface( $this, 'pm_field', '{{WRAPPER}} .galaxie-pm-stripe' );

		$this->add_control( 'pm_field_text_heading', array( 'label' => __( 'Typed text', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $this, 'pm_field_color', __( 'Text color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-pm-stripe', 'color' );
		PixfortControls::palette_control( $this, 'pm_field_placeholder', __( 'Placeholder color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-pm-placeholder', 'color' );

		$this->add_responsive_control(
			'pm_field_font_size',
			array(
				'label'      => __( 'Text size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 12, 'max' => 28 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-pm-stripe' => 'font-size: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control( 'pm_field_state_heading', array( 'label' => __( 'States', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $this, 'pm_field_focus', __( 'Border when focused', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-pm-stripe.is-focused', 'border-color' );
		PixfortControls::palette_control( $this, 'pm_field_focus_bg', __( 'Background when focused', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-pm-stripe.is-focused', 'background-color' );
		PixfortControls::palette_control( $this, 'pm_field_error', __( 'Border on error', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-pm-stripe.is-invalid', 'border-color' );

		$this->end_controls_section();

		$this->text_sections(
			array(
				'pm_form_title_text' => array( __( 'Add card form: title', 'galaxie-woo' ), '.galaxie-pm-form-title', array( 'size' => 'text-18', 'bold' => 'font-weight-bold' ), true ),
				'pm_field_label'     => array( __( 'Add card form: field labels', 'galaxie-woo' ), '.galaxie-pm-field-label', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ) ),
			)
		);

		Dialog::controls(
			$this,
			'pm_confirm',
			array(
				'label' => __( 'Delete card dialog', 'galaxie-woo' ),
				'title' => __( 'Remover cartão', 'galaxie-woo' ),
				'text'  => __( 'Excluir este cartão?', 'galaxie-woo' ),
				'yes'   => __( 'Sim, remover', 'galaxie-woo' ),
				'no'    => __( 'Cancelar', 'galaxie-woo' ),
			)
		);

		Dialog::controls(
			$this,
			'pm_default_ask',
			array(
				'label'        => __( 'Make default dialog', 'galaxie-woo' ),
				'title'        => __( 'Cartão salvo', 'galaxie-woo' ),
				'text'         => __( 'Usar este cartão como padrão nos próximos pagamentos?', 'galaxie-woo' ),
				'yes'          => __( 'Usar como padrão', 'galaxie-woo' ),
				'no'           => __( 'Agora não', 'galaxie-woo' ),
				'yes_defaults' => array( 'size' => 'sm' ),
			)
		);
	}

	/**
	 * Text style sections, in the order given. A fourth `true` marks text drawn
	 * by pixfort's Text element; the rest are classes on this widget's own
	 * markup, which carry fewer controls.
	 *
	 * @param array<string,array<int,mixed>> $texts
	 */
	private function text_sections( array $texts ): void {
		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', empty( $text[3] ) ? array( 'position', 'inline' ) : array( 'position' ) );
			$this->end_controls_section();
		}
	}

	protected function render(): void {
		if ( ! function_exists( 'wc_get_customer_saved_methods_list' ) || ( ! is_user_logged_in() && ! AccountParts::editing() ) ) {
			return;
		}

		Assets::enqueue();

		$s       = $this->get_settings_for_display();
		$editing = AccountParts::editing();
		$methods = is_user_logged_in() ? self::methods( get_current_user_id() ) : array();

		// Something to style in the editor, where the account may have no cards:
		// one of each icon and each warning.
		if ( ! $methods && $editing ) {
			$methods = array(
				array( 'method' => array( 'brand' => 'Visa', 'last4' => '4242' ), 'expires' => '12/30', 'is_default' => true, 'actions' => array( 'delete' => array( 'url' => '#' ) ) ),
				array( 'method' => array( 'brand' => 'Mastercard', 'last4' => '4444' ), 'expires' => gmdate( 'm/y', strtotime( '+1 month' ) ), 'is_default' => false, 'actions' => array( 'delete' => array( 'url' => '#' ), 'default' => array( 'url' => '#' ) ) ),
				array( 'method' => array( 'brand' => 'Elo', 'last4' => '0001' ), 'expires' => '01/24', 'is_default' => false, 'actions' => array( 'delete' => array( 'url' => '#' ), 'default' => array( 'url' => '#' ) ) ),
			);
		}

		$stripe = $editing ? null : \Galaxie\Woo\Support\StripeCards::client_config();

		printf(
			'<div class="galaxie-payment-methods" data-saved="%1$s" data-default-saved="%2$s"%3$s>',
			esc_attr( (string) ( $s['pm_saved'] ?? '' ) ),
			esc_attr( (string) ( $s['pm_default_saved'] ?? '' ) ),
			$stripe ? ' data-stripe="' . esc_attr( (string) wp_json_encode( $stripe ) ) . '"' : ''
		);

		echo '<div class="galaxie-account-message" role="status" aria-live="polite" hidden></div>';

		$heading = trim( (string) ( $s['pm_heading'] ?? '' ) );
		$intro   = trim( (string) ( $s['pm_intro'] ?? '' ) );

		if ( '' !== $heading ) {
			printf( '<div class="galaxie-pm-heading">%s</div>', PixfortControls::render_text( $s, 'pm_heading_text', esc_html( $heading ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		if ( '' !== $intro ) {
			printf( '<div class="galaxie-pm-intro">%s</div>', PixfortControls::render_text( $s, 'pm_intro_text', esc_html( $intro ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		printf(
			'<p class="galaxie-pm-empty %1$s"%2$s>%3$s</p>',
			esc_attr( PixfortControls::text_classes( $s, 'pm_empty_body' ) ),
			$methods ? ' hidden' : '',
			esc_html( (string) ( $s['pm_empty_text'] ?? '' ) )
		);

		if ( $methods ) {
			echo '<div class="galaxie-pm-cards">';

			foreach ( $methods as $method ) {
				echo $this->card( $s, $method ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			}

			echo '</div>';
		}

		if ( $stripe ) {
			printf(
				'<div class="galaxie-pm-toolbar"><button type="button" class="galaxie-account-submit galaxie-pm-add">%s</button></div>',
				PixfortControls::render_button( $s, 'pm_add', (string) ( $s['pm_add_text'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own button.
			);
		} elseif ( $editing || self::can_add() ) {
			printf(
				'<div class="galaxie-pm-toolbar">%s</div>',
				AccountParts::link_button( $s, 'pm_add', (string) ( $s['pm_add_text'] ?? '' ), $editing ? '#' : AccountEndpoints::url( 'add-payment-method' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			);
		}

		// Below the cards, the way an address is added: typing into a picture of
		// a card made the fields the hardest thing on the screen to hit.
		$preview = $editing && 'yes' === ( $s['pm_form_preview'] ?? '' );

		if ( $stripe || $preview ) {
			echo $this->new_form( $s, $preview ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}

		echo Dialog::render( $s, 'pm_confirm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo Dialog::render( $s, 'pm_default_ask' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		echo '</div>';
	}

	/**
	 * WooCommerce's saved-methods list, flattened across method types, with the
	 * default first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function methods( int $user_id ): array {
		$flat = array();

		foreach ( (array) wc_get_customer_saved_methods_list( $user_id ) as $items ) {
			foreach ( (array) $items as $item ) {
				if ( is_array( $item ) ) {
					$flat[] = $item;
				}
			}
		}

		usort( $flat, static fn( array $a, array $b ): int => (int) ! empty( $b['is_default'] ) <=> (int) ! empty( $a['is_default'] ) );

		return $flat;
	}

	/** Whether any available gateway can save a card outside checkout. */
	private static function can_add(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}

		foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
			if ( $gateway->supports( 'add_payment_method' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 'expired' past the last day of its month, 'expiring' within 60 days of
	 * it, '' otherwise — including when the gateway gave no date at all.
	 */
	private static function expiry_state( string $expires ): string {
		if ( ! preg_match( '#^(\d{1,2})\s*/\s*(\d{2}|\d{4})$#', trim( $expires ), $match ) ) {
			return '';
		}

		$year = (int) $match[2];
		$year = $year < 100 ? 2000 + $year : $year;
		$end  = gmmktime( 23, 59, 59, (int) $match[1] + 1, 0, $year );
		$now  = time();

		if ( $now > $end ) {
			return 'expired';
		}

		return $end - $now < 60 * DAY_IN_SECONDS ? 'expiring' : '';
	}

	/** The brand's pixfort icon, or the generic card when pixfort has none for it. */
	private static function brand_icon( string $brand ): string {
		if ( ! class_exists( '\PixfortCore' ) ) {
			return '';
		}

		$key  = (string) preg_replace( '/[^a-z]/', '', strtolower( $brand ) );
		$name = self::BRAND_ICONS[ $key ] ?? self::GENERIC_ICON;

		return (string) \PixfortCore::instance()->icons->getIcon( $name, 48, 'galaxie-pm-brand-icon' );
	}

	/**
	 * The form a new card is typed into, below the cards. Each field box is
	 * where one of Stripe's secure fields is mounted; in the editor preview they
	 * hold sample text instead, so the boxes have something to be styled around.
	 *
	 * @param array<string,mixed> $s
	 */
	private function new_form( array $s, bool $preview ): string {
		$label = static fn( string $text ): string => sprintf(
			'<span class="galaxie-pm-field-label %1$s">%2$s</span>',
			esc_attr( PixfortControls::text_classes( $s, 'pm_field_label' ) ),
			esc_html( $text )
		);

		$slot = static fn( string $field, string $sample ): string => sprintf(
			'<div class="galaxie-pm-stripe %1$s" data-field="%2$s">%3$s</div>',
			esc_attr( PixfortControls::surface_classes( $s, 'pm_field' ) ),
			esc_attr( $field ),
			$preview ? '<span class="galaxie-pm-stripe-sample">' . esc_html( $sample ) . '</span>' : ''
		);

		$title = trim( (string) ( $s['pm_form_title'] ?? '' ) );

		return sprintf(
			'<form class="galaxie-pm-form card %1$s" novalidate%2$s>%3$s<div class="galaxie-pm-fields"><div class="galaxie-pm-field is-number">%4$s%5$s</div><div class="galaxie-pm-field is-expiry">%6$s%7$s</div><div class="galaxie-pm-field is-cvc">%8$s%9$s</div></div><span class="galaxie-pm-placeholder" hidden></span><div class="galaxie-pm-form-actions"><button type="button" class="galaxie-account-submit galaxie-pm-save">%10$s</button><button type="button" class="galaxie-account-submit galaxie-pm-cancel">%11$s</button></div></form>',
			esc_attr( PixfortControls::surface_classes( $s, 'pm_form' ) ),
			$preview ? '' : ' hidden',
			'' !== $title ? '<div class="galaxie-pm-form-title">' . PixfortControls::render_text( $s, 'pm_form_title_text', esc_html( $title ) ) . '</div>' : '',
			$label( (string) ( $s['pm_number_label'] ?? '' ) ),
			$slot( 'cardNumber', '1234 1234 1234 1234' ),
			$label( (string) ( $s['pm_expires_label'] ?? '' ) ),
			$slot( 'cardExpiry', 'MM / AA' ),
			$label( (string) ( $s['pm_cvc_label'] ?? '' ) ),
			$slot( 'cardCvc', 'CVC' ),
			PixfortControls::render_button( $s, 'pm_save', (string) ( $s['pm_save_text'] ?? '' ) ),
			PixfortControls::render_button( $s, 'pm_cancel', (string) ( $s['pm_cancel_text'] ?? '' ) )
		);
	}

	/**
	 * The saved token's id, read off its WooCommerce action links — the list
	 * WooCommerce hands over carries the links but not the id itself. The
	 * script uses it to find the card it has just saved.
	 *
	 * @param array<string,mixed> $actions
	 */
	private static function token_id( array $actions ): string {
		foreach ( array( 'delete', 'default' ) as $action ) {
			if ( preg_match( '#(?:delete|set-default)-payment-method[/=](\d+)#', (string) ( $actions[ $action ]['url'] ?? '' ), $match ) ) {
				return $match[1];
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $s
	 * @param array<string,mixed> $method
	 */
	private function card( array $s, array $method ): string {
		$brand   = (string) ( $method['method']['brand'] ?? '' );
		$last4   = (string) ( $method['method']['last4'] ?? '' );
		$expires = (string) ( $method['expires'] ?? '' );
		$default = ! empty( $method['is_default'] );
		$actions = (array) ( $method['actions'] ?? array() );
		$state   = self::expiry_state( $expires );
		$key     = (string) preg_replace( '/[^a-z]/', '', strtolower( $brand ) );
		$badge   = trim( PixfortControls::text_classes( $s, 'pm_badge_text' ) . ' ' . PixfortControls::surface_classes( $s, 'pm_badge' ) );
		$icon    = 'yes' === ( $s['pm_show_icons'] ?? 'yes' ) ? self::brand_icon( $brand ) : '';

		$badges = $default ? sprintf( '<span class="galaxie-pm-badge is-default %1$s">%2$s</span>', esc_attr( $badge ), esc_html( (string) ( $s['pm_badge_default'] ?? '' ) ) ) : '';

		if ( '' !== $state ) {
			$badges .= sprintf( '<span class="galaxie-pm-badge is-%1$s %2$s">%3$s</span>', esc_attr( $state ), esc_attr( $badge ), esc_html( (string) ( $s[ 'pm_badge_' . $state ] ?? '' ) ) );
		}

		$buttons = '';

		if ( ! empty( $actions['default']['url'] ) ) {
			$buttons .= AccountParts::link_button( $s, 'pm_default', (string) ( $s['pm_default_text'] ?? '' ), (string) $actions['default']['url'], 'galaxie-pm-default' );
		}

		if ( ! empty( $actions['delete']['url'] ) ) {
			$buttons .= AccountParts::link_button( $s, 'pm_delete', (string) ( $s['pm_delete_text'] ?? '' ), (string) $actions['delete']['url'], 'galaxie-pm-delete' );
		}

		$has_date = '' !== $expires && preg_match( '#\d#', $expires );
		$classes  = array(
			'galaxie-pm-card',
			'card',
			PixfortControls::surface_classes( $s, 'pm_card' ),
			'is-' . ( isset( self::BRAND_ICONS[ $key ] ) ? $key : 'generic' ),
			$default ? 'is-default' : '',
			'' !== $state ? 'is-' . $state : '',
		);

		return sprintf(
			'<article class="galaxie-pm-item" data-token="%9$s"><div class="%1$s"><div class="galaxie-pm-card-top"><span class="galaxie-pm-brand">%2$s</span><span class="galaxie-pm-badges">%3$s</span></div>%4$s%5$s<div class="galaxie-pm-card-bottom">%6$s%7$s</div></div>%8$s</article>',
			esc_attr( trim( implode( ' ', array_filter( $classes ) ) ) ),
			$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own icon markup.
			$badges,
			'yes' === ( $s['pm_show_chip'] ?? 'yes' ) ? '<span class="' . esc_attr( trim( 'galaxie-pm-chip ' . PixfortControls::surface_classes( $s, 'pm_chip' ) ) ) . '" aria-hidden="true"></span>' : '',
			'' !== $last4 ? sprintf( '<div class="galaxie-pm-number %1$s"><span aria-hidden="true">•••• •••• ••••</span> %2$s</div>', esc_attr( PixfortControls::text_classes( $s, 'pm_number_text' ) ), esc_html( $last4 ) ) : '',
			$has_date ? sprintf( '<div class="galaxie-pm-expiry %1$s"><span class="galaxie-pm-expiry-label">%2$s</span> %3$s</div>', esc_attr( PixfortControls::text_classes( $s, 'pm_expiry_text' ) ), esc_html( (string) ( $s['pm_expires_label'] ?? '' ) ), esc_html( $expires ) ) : '<span></span>',
			'yes' === ( $s['pm_show_brand_name'] ?? 'yes' ) && '' !== $brand ? sprintf( '<span class="galaxie-pm-brand-name %1$s">%2$s</span>', esc_attr( PixfortControls::text_classes( $s, 'pm_brand_text' ) ), esc_html( $brand ) ) : '',
			'' !== $buttons ? '<div class="galaxie-pm-card-actions">' . $buttons . '</div>' : '',
			esc_attr( self::token_id( $actions ) )
		);
	}
}

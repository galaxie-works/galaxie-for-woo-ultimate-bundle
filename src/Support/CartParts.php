<?php
/**
 * The cart, in pieces the three cart widgets share.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use Galaxie\Woo\Support\Dialog;

defined( 'ABSPATH' ) || exit;

/**
 * Why three widgets exist, and why their insides live here.
 *
 * "Galaxie Cart" renders the table and the totals together, which is what most
 * carts need and the fastest thing to drop into a template. But welding them
 * together is also what stops an Elementor container from putting the totals in
 * a sticky right-hand column — so "Galaxie Cart Table" and "Galaxie Cart
 * Totals" exist for that, and the layout stays the builder's job rather than
 * becoming a "columns / reverse columns" control inside a widget. XStore ships
 * both shapes for the same reason.
 *
 * Three widgets rendering a cart three slightly different ways is exactly how
 * a cart ends up looking like three carts, so none of them owns any markup:
 * the parts are here, and each widget only decides which ones to call.
 */
final class CartParts {

	/** Every column a line can show. Order comes from the repeater, not here. */
	public const FIELDS = array(
		'thumb'    => 'Image',
		'name'     => 'Product',
		'price'    => 'Price',
		'quantity' => 'Quantity',
		'subtotal' => 'Subtotal',
		'remove'   => 'Remove',
	);

	public static function available(): bool {
		return function_exists( 'WC' ) && null !== WC()->cart;
	}

	/**
	 * Calculate the cart once per request, if nothing has yet.
	 *
	 * The [woocommerce_cart] shortcode recalculates the cart every time it
	 * renders, and that is the only thing that fills
	 * WC()->shipping()->get_packages() on a normal page load. Totals read from
	 * the session carry no rates, so without this the shipping row showed after
	 * the calculator's AJAX (which recalculates) and vanished on the next
	 * reload with the CEP still saved. The shipping options widget needs the
	 * same packages, which is why this is shared.
	 */
	public static function ensure_totals(): void {
		if ( self::available() && ! did_action( 'woocommerce_after_calculate_totals' ) ) {
			wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );
			WC()->cart->calculate_totals();
		}
	}

	/* ---------------------------------------------------------------------
	 * Controls
	 * ------------------------------------------------------------------ */

	/**
	 * The questions and notices a cart line asks: today only "Editar kit", which
	 * takes a kit out of the cart and back into the popup. Registered here so a
	 * cart drawn by either widget carries the same dialog, styled with it.
	 */
	public static function register_dialog_controls( object $widget ): void {
		Dialog::controls(
			$widget,
			'kit_edit',
			array(
				'label' => __( 'Edit kit dialog', 'galaxie-woo' ),
				'title' => __( 'Editar kit', 'galaxie-woo' ),
				'text'  => __( 'Este kit volta para o montador e sai do carrinho. Tudo bem?', 'galaxie-woo' ),
				'yes'   => __( 'Sim, editar', 'galaxie-woo' ),
				'no'    => __( 'Cancelar', 'galaxie-woo' ),
				'yes_defaults' => array( 'color' => 'primary', 'size' => 'sm' ),
			)
		);
	}

	public static function register_line_controls( object $widget ): void {
		$widget->start_controls_section( 'lines_section', array( 'label' => __( 'Line fields', 'galaxie-woo' ) ) );

		$widget->add_control(
			'lines_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Drag to reorder. Remove a row to leave that field out of every line.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$repeater = new Repeater();
		$repeater->add_control(
			'field',
			array(
				'label'   => __( 'Field', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => self::field_options(),
				'default' => 'thumb',
			)
		);
		$repeater->add_control(
			'heading',
			array(
				'label'       => __( 'Column heading', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
			)
		);

		// Per column rather than per table, because a cart is not aligned one
		// way: the product reads from the left, the money from the right, and
		// the stepper sits in the middle of its own column. One control for the
		// whole table would force all three to agree.
		//
		// The heading and the value share this setting on purpose — a heading
		// that does not sit over its own column is the thing a merchant would
		// have to fix twice.
		$repeater->add_control(
			'align',
			array(
				'label'   => __( 'Alignment', 'galaxie-woo' ),
				'type'    => Controls_Manager::CHOOSE,
				'toggle'  => false,
				'default' => 'left',
				'options' => array(
					'left'   => array( 'title' => __( 'Left', 'galaxie-woo' ), 'icon' => 'eicon-text-align-left' ),
					'center' => array( 'title' => __( 'Center', 'galaxie-woo' ), 'icon' => 'eicon-text-align-center' ),
					'right'  => array( 'title' => __( 'Right', 'galaxie-woo' ), 'icon' => 'eicon-text-align-right' ),
				),
			)
		);

		// Vertical, on the same row as its horizontal twin. A cart line is as
		// tall as its tallest cell — usually the thumbnail or the stepper — so
		// every other cell has room to sit somewhere in it, and a wrapped
		// product name reads better against the top than floating in the middle.
		$repeater->add_control(
			'valign',
			array(
				'label'   => __( 'Vertical alignment', 'galaxie-woo' ),
				'type'    => Controls_Manager::CHOOSE,
				'toggle'  => false,
				'default' => 'middle',
				'options' => array(
					'top'    => array( 'title' => __( 'Top', 'galaxie-woo' ), 'icon' => 'eicon-v-align-top' ),
					'middle' => array( 'title' => __( 'Middle', 'galaxie-woo' ), 'icon' => 'eicon-v-align-middle' ),
					'bottom' => array( 'title' => __( 'Bottom', 'galaxie-woo' ), 'icon' => 'eicon-v-align-bottom' ),
				),
			)
		);

		// The column's own width, and the reason the table lines up at all: the
		// header row and every line share ONE template built from these, so a
		// heading always sits over the values beneath it.
		//
		// Auto means an equal share of what is left (`1fr`). Image and Remove
		// default to a fixed width instead, because a column that sizes itself
		// to its content resolves separately in every row — which is exactly
		// how "Imagem" ended up sitting over the product name.
		$repeater->add_control(
			'col_width',
			array(
				'label'       => __( 'Column width', 'galaxie-woo' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', '%', 'fr' ),
				'range'       => array(
					'px' => array( 'min' => 24, 'max' => 600 ),
					'%'  => array( 'min' => 5, 'max' => 100 ),
					'fr' => array( 'min' => 1, 'max' => 6 ),
				),
				'description' => __( 'Leave empty for an equal share of the row.', 'galaxie-woo' ),
			)
		);

		$widget->add_control(
			'lines',
			array(
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				// The row is labelled with the field's LABEL, not the value
				// stored under it: a list reading "thumb / name / price" makes
				// the merchant translate their own table back into our slugs.
				// `title_field` is a JS template, so the options map goes in as
				// a literal and the row looks up its own name.
				'title_field' => '{{{ (' . wp_json_encode( self::field_options() ) . ')[ field ] || field }}}',
				'default'     => array(
					array( 'field' => 'thumb', 'heading' => '', 'align' => 'center' ),
					array( 'field' => 'name', 'heading' => __( 'Produto', 'galaxie-woo' ), 'align' => 'left' ),
					array( 'field' => 'price', 'heading' => __( 'Preço', 'galaxie-woo' ), 'align' => 'center' ),
					array( 'field' => 'quantity', 'heading' => __( 'Quantidade', 'galaxie-woo' ), 'align' => 'center' ),
					array( 'field' => 'subtotal', 'heading' => __( 'Subtotal', 'galaxie-woo' ), 'align' => 'right' ),
					array( 'field' => 'remove', 'heading' => '', 'align' => 'right' ),
				),
			)
		);

		$widget->add_control(
			'show_headings',
			array(
				'label'        => __( 'Show column headings', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		PixfortControls::icon_select( $widget, 'remove_icon', __( 'Remove icon', 'galaxie-woo' ), 'Line/pixfort-icon-trash-can-1' );

		$widget->end_controls_section();
	}

	public static function register_behaviour_controls( object $widget ): void {
		$widget->start_controls_section( 'behaviour_section', array( 'label' => __( 'Behaviour', 'galaxie-woo' ) ) );

		$widget->add_control(
			'auto_update',
			array(
				'label'        => __( 'Update automatically', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Changing a quantity updates the cart straight away. With this off, the shopper presses Update cart and the page reloads — WooCommerce\'s own behaviour.', 'galaxie-woo' ),
			)
		);

		$widget->add_control(
			'update_text',
			array(
				'label'     => __( 'Update button', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Atualizar carrinho', 'galaxie-woo' ),
				'condition' => array( 'auto_update' => '' ),
			)
		);

		$widget->end_controls_section();
	}

	public static function register_totals_controls( object $widget ): void {
		$widget->start_controls_section( 'totals_section', array( 'label' => __( 'Totals', 'galaxie-woo' ) ) );

		$widget->add_control(
			'totals_heading',
			array(
				'label'   => __( 'Heading', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Resumo', 'galaxie-woo' ),
			)
		);

		$widget->add_control(
			'show_shipping',
			array(
				'label'        => __( 'Shipping row', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$widget->add_control(
			'shipping_display',
			array(
				'label'       => __( 'Shipping row shows', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'list'   => __( 'Every option, to choose from', 'galaxie-woo' ),
					'chosen' => __( 'Chosen method only', 'galaxie-woo' ),
				),
				'default'     => 'chosen',
				'description' => __( '"Chosen method only" is one line under the subtotal, with the carrier the shopper picked in the Galaxie Shipping Options widget. "Every option" lists them all here instead, for a page without that widget.', 'galaxie-woo' ),
				'condition'   => array( 'show_shipping' => 'yes' ),
			)
		);

		$widget->add_control(
			'shipping_show_method',
			array(
				'label'        => __( 'Carrier under the label', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'The chosen carrier and its delivery time, e.g. "Loggi Express · 1 a 3 dias úteis".', 'galaxie-woo' ),
				'condition'    => array(
					'show_shipping'    => 'yes',
					'shipping_display' => 'chosen',
				),
			)
		);

		$widget->add_control(
			'shipping_label',
			array(
				'label'     => __( 'Shipping label', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Frete', 'galaxie-woo' ),
				'condition' => array( 'show_shipping' => 'yes' ),
			)
		);

		$widget->add_control(
			'shipping_empty_text',
			array(
				'label'     => __( 'Before a CEP is entered', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Informe o CEP', 'galaxie-woo' ),
				'condition' => array( 'show_shipping' => 'yes' ),
			)
		);

		$widget->add_control(
			'shipping_free_text',
			array(
				'label'     => __( 'When shipping is free', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Grátis', 'galaxie-woo' ),
				'condition' => array( 'show_shipping' => 'yes' ),
			)
		);

		$widget->add_control(
			'show_free_shipping',
			array(
				'label'        => __( 'Free shipping progress', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'separator'    => 'before',
				'description'  => __( 'The Galaxie Free Shipping Progress widget does the same anywhere on the page, with confetti.', 'galaxie-woo' ),
			)
		);

		$widget->add_control(
			'free_shipping_source',
			array(
				'label'       => __( 'Threshold', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'auto',
				'options'     => array(
					'auto'  => __( 'From the Free Shipping method', 'galaxie-woo' ),
					'fixed' => __( 'A number I type', 'galaxie-woo' ),
				),
				'description' => __( 'Read from the shipping zone that matches the customer, so it cannot promise a number the store no longer honours.', 'galaxie-woo' ),
				'condition'   => array( 'show_free_shipping' => 'yes' ),
			)
		);

		$widget->add_control(
			'free_shipping_amount',
			array(
				'label'     => __( 'Free shipping from', 'galaxie-woo' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 0,
				'default'   => 0,
				'condition' => array( 'show_free_shipping' => 'yes', 'free_shipping_source' => 'fixed' ),
			)
		);

		$widget->add_control(
			'free_shipping_message',
			array(
				'label'       => __( 'While there is a way to go', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Faltam {amount} para o frete grátis', 'galaxie-woo' ),
				'description' => __( '{amount} becomes what is missing.', 'galaxie-woo' ),
				'condition'   => array( 'show_free_shipping' => 'yes' ),
			)
		);

		$widget->add_control(
			'free_shipping_done',
			array(
				'label'       => __( 'Once it is reached', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Você ganhou frete grátis!', 'galaxie-woo' ),
				'condition'   => array( 'show_free_shipping' => 'yes' ),
			)
		);

		$widget->add_control(
			'free_shipping_bar',
			array(
				'label'        => __( 'Show the bar', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'show_free_shipping' => 'yes' ),
			)
		);

		$widget->add_control(
			'show_continue',
			array(
				'label'        => __( 'Continue shopping link', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$widget->end_controls_section();

		// Each button gets a section of its own carrying pixfort's whole Button
		// set — text, style, colours, size, radius, shadows, hover animation,
		// icon, full width, container, animation, extra classes and the hover
		// group — exactly the panel the buy box's Add to Cart and Buy Now have.
		// Shaped like the buy box's too: the text lives with the button rather
		// than in a separate content field.
		//
		// `checkout_text` was declared here AND by button(), which is a
		// duplicate control id: Elementor keeps the first and silently drops the
		// second, so the Button text field never appeared in the styling panel.
		// Same id, one declaration now — nothing already typed is lost.
		$widget->start_controls_section( 'checkout_button_section', array( 'label' => __( 'Checkout button', 'galaxie-woo' ) ) );
		PixfortControls::button(
			$widget,
			'checkout',
			array( 'text' => __( 'Finalizar compra', 'galaxie-woo' ), 'color' => 'primary', 'full' => 'w-100', 'size' => 'lg' ),
			array(),
			'{{WRAPPER}} .galaxie-cart-checkout'
		);
		$widget->end_controls_section();

		// A link before, which meant no styling at all. It is a button now, with
		// the same set — an outline or link style is one of its options rather
		// than something baked into the markup.
		$widget->start_controls_section(
			'continue_button_section',
			array( 'label' => __( 'Continue shopping', 'galaxie-woo' ), 'condition' => array( 'show_continue' => 'yes' ) )
		);
		PixfortControls::button(
			$widget,
			'continue',
			array( 'text' => __( 'Continuar comprando', 'galaxie-woo' ), 'style' => 'link', 'color' => 'secondary', 'full' => 'w-100' ),
			array( 'show_continue' => 'yes' ),
			'{{WRAPPER}} .galaxie-cart-continue'
		);
		$widget->end_controls_section();
	}

	public static function register_empty_controls( object $widget ): void {
		$widget->start_controls_section( 'empty_section', array( 'label' => __( 'Empty cart', 'galaxie-woo' ) ) );

		$widget->add_control(
			'empty_mode',
			array(
				'label'   => __( 'Show', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'message',
				'options' => array(
					'message'  => __( 'A message and a button', 'galaxie-woo' ),
					'template' => __( 'A saved Elementor template', 'galaxie-woo' ),
				),
			)
		);

		$widget->add_control(
			'empty_template',
			array(
				'label'       => __( 'Template', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => self::template_options(),
				'label_block' => true,
				'condition'   => array( 'empty_mode' => 'template' ),
			)
		);

		$widget->add_control(
			'empty_text',
			array(
				'label'       => __( 'Message', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => __( 'Seu carrinho está vazio.', 'galaxie-woo' ),
				'label_block' => true,
				'condition'   => array( 'empty_mode' => 'message' ),
			)
		);

		$widget->add_control(
			'empty_button_text',
			array(
				'label'     => __( 'Button', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Ver produtos', 'galaxie-woo' ),
				'condition' => array( 'empty_mode' => 'message' ),
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * The column headings: their own box, and their own text.
	 *
	 * Split from {@see register_table_style()} so the heading row can be turned
	 * into a real header — a filled bar with pixfort's corner radius — without
	 * that being buried under the controls for the lines beneath it.
	 */
	public static function register_head_style( object $widget ): void {
		$widget->start_controls_section(
			'head_style',
			array(
				'label'     => __( 'Table header', 'galaxie-woo' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_headings' => 'yes' ),
			)
		);

		// No padding of its own: see the Layout section's cell padding, which
		// pads the header and the lines alike so their columns keep matching.
		PixfortControls::surface( $widget, 'head', '{{WRAPPER}} .galaxie-cart-head', array(), array(), false );

		$widget->add_control(
			'head_text_heading',
			array( 'label' => __( 'Labels', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' )
		);

		// No Position here. The column's own Alignment, in Line fields, moves
		// the heading and the values under it together — which is the only
		// place that can keep them over each other. A Position control in this
		// section could only aim every heading the same way, and would win over
		// the per-column setting while doing it.
		PixfortControls::text(
			$widget,
			'head',
			array( 'size' => 'text-sm', 'bold' => '', 'remove_pb_padding' => 'm-0' ),
			array(),
			'{{WRAPPER}} .galaxie-cart-head',
			'text',
			array( 'position' )
		);

		// The stylesheet used to hard-code uppercase, letter-spacing and a 60%
		// opacity on this row. They are controls now, defaulted to exactly what
		// they were, so the header looks the same until it is changed.
		$widget->add_control(
			'head_transform',
			array(
				'label'     => __( 'Letter case', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'uppercase',
				'options'   => array(
					'none'       => __( 'As typed', 'galaxie-woo' ),
					'uppercase'  => __( 'UPPERCASE', 'galaxie-woo' ),
					'capitalize' => __( 'Capitalized', 'galaxie-woo' ),
					'lowercase'  => __( 'lowercase', 'galaxie-woo' ),
				),
				'selectors' => array( '{{WRAPPER}} .galaxie-cart-head' => 'text-transform: {{VALUE}};' ),
			)
		);

		$widget->add_responsive_control(
			'head_letter_spacing',
			array(
				'label'      => __( 'Letter spacing', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'em' ),
				'range'      => array( 'em' => array( 'min' => -0.05, 'max' => 0.4, 'step' => 0.01 ) ),
				'default'    => array( 'unit' => 'em', 'size' => 0.04 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-head' => 'letter-spacing: {{SIZE}}{{UNIT}};' ),
			)
		);

		// There was an Opacity slider here, defaulted to the 0.6 the stylesheet
		// used to hard-code. It is gone rather than re-defaulted: it dimmed the
		// whole row, so a Content color picked from the palette arrived washed
		// out and no colour could ever be shown as chosen. A quieter header is
		// a lighter colour from the palette, which is also the one that follows
		// the site into dark mode.
		$widget->end_controls_section();
	}

	public static function register_table_style( object $widget ): void {
		$widget->start_controls_section( 'thumb_style', array( 'label' => __( 'Image', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::thumb( $widget, 'thumb', '{{WRAPPER}} .galaxie-cart-thumb img', array(), array(), '{{WRAPPER}} .galaxie-cart-thumb' );
		$widget->end_controls_section();

		$widget->start_controls_section( 'quantity_style', array( 'label' => __( 'Quantity', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		// The buy box's own set, so a spinner cannot look like two things.
		PixfortControls::quantity( $widget, 'qty', '{{WRAPPER}} .galaxie-cart-qty .quantity', '{{WRAPPER}} .galaxie-cart-qty .qty', array(), array(), '{{WRAPPER}} .galaxie-cart-qty' );
		$widget->end_controls_section();

		$widget->start_controls_section( 'name_style', array( 'label' => __( 'Product name', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $widget, 'name', array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-cart-line:not(.galaxie-cart-head) .galaxie-cart-cell-name', 'text', array( 'position' ) );
		$widget->end_controls_section();

		// Two sections, not one "Prices". A unit price and a line total read
		// differently — the total is usually the heavier of the two — and one
		// set of controls driving both cannot express that.
		//
		// `price` keeps its id, so whatever the old shared section was set to
		// stays on the unit price; Subtotal starts from the defaults.
		$widget->start_controls_section( 'price_style', array( 'label' => __( 'Price', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		// The same `heading` vocabulary the buy box gives the product page's
		// price, so a price is sized the same way in both places.
		PixfortControls::text( $widget, 'price', array( 'size' => 'h6', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-cart-line:not(.galaxie-cart-head) .galaxie-cart-cell-price', 'heading', array( 'position' ) );
		$widget->end_controls_section();

		$widget->start_controls_section( 'subtotal_style', array( 'label' => __( 'Subtotal', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $widget, 'subtotal', array( 'size' => 'h6', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-cart-line:not(.galaxie-cart-head) .galaxie-cart-cell-subtotal', 'heading', array( 'position' ) );
		$widget->end_controls_section();

		$widget->start_controls_section( 'remove_style', array( 'label' => __( 'Remove', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::icon_color( $widget, 'remove_icon_color', __( 'Icon color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-cart-remove' );
		$widget->add_responsive_control(
			'remove_size',
			array(
				'label'      => __( 'Icon size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 10, 'max' => 48 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 20 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-remove svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);
		$widget->end_controls_section();

		$widget->start_controls_section( 'layout_style', array( 'label' => __( 'Layout', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		$widget->add_responsive_control(
			'row_gap',
			array(
				'label'      => __( 'Line padding', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 16 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-line' => 'padding-top: {{SIZE}}{{UNIT}}; padding-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);
		// On the CELLS, not on the rows. Padding a row narrows its content box
		// and with it every flexible column in that row, so a padded header
		// lays out narrower tracks than the lines beneath it — which is exactly
		// how the headings ended up sitting over the wrong columns.
		$widget->add_responsive_control(
			'cell_padding',
			array(
				'label'      => __( 'Cell padding', 'galaxie-woo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'rem', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-cell' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$widget->add_responsive_control(
			'field_gap',
			array(
				'label'      => __( 'Gap between fields', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 16 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-line' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$widget->end_controls_section();
	}

	/**
	 * Type inside the totals box: the heading, the row labels, the amounts, and
	 * the order total on its own.
	 *
	 * Driven by SELECTORS rather than by pixfort's text classes, which is the
	 * one place in this widget where that is the right way round: the AJAX
	 * update re-renders these rows without any widget settings to render them
	 * from, so a class printed at page load would be gone the first time a
	 * quantity changed. A rule in the page's stylesheet applies to whatever
	 * markup is there, including markup that arrived a second ago.
	 */
	private static function register_totals_type( object $widget ): void {
		$widget->start_controls_section(
			'totals_type_style',
			array( 'label' => __( 'Totals type', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE )
		);

		// pixfort's own text set for each, rather than a Size and a Weight
		// invented here. They reach WooCommerce's markup as classes on the
		// spans — see PixfortControls::text_classes() — and the AJAX update
		// carries the widget's id so the rows it re-renders keep them.
		$parts = array(
			'sumh' => array( __( 'Heading', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-cart-totals-heading', array( 'size' => 'text-20', 'bold' => 'font-weight-bold' ) ),
			'suml'   => array( __( 'Row label', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-cart-total-label', array( 'size' => '', 'bold' => '' ) ),
			'sumv'   => array( __( 'Row amount', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-cart-total-value', array( 'size' => '', 'bold' => 'font-weight-bold' ) ),
			'sums'   => array( __( 'Shipping carrier', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-cart-total-method', array( 'size' => 'text-sm', 'bold' => '' ) ),
			'sumt'   => array( __( 'Order total', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-cart-total-order-total', array( 'size' => 'text-18', 'bold' => 'font-weight-bold' ) ),
		);

		$first = true;

		foreach ( $parts as $prefix => $part ) {
			list( $label, $selector, $defaults ) = $part;

			$widget->add_control(
				$prefix . '_heading',
				array(
					'label'     => $label,
					'type'      => Controls_Manager::HEADING,
					'separator' => $first ? '' : 'before',
				)
			);

			$first = false;

			PixfortControls::text(
				$widget,
				$prefix,
				array_merge( $defaults, array( 'remove_pb_padding' => 'm-0' ) ),
				array(),
				$selector,
				'text',
				array( 'position' )
			);
		}

		$widget->add_responsive_control(
			'sum_row_gap',
			array(
				'label'      => __( 'Space between rows', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'separator'  => 'before',
				'selectors'  => array( '{{WRAPPER}} .galaxie-cart-totals-rows' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * The free shipping line: its text, and the bar under it.
	 */
	private static function register_free_shipping_style( object $widget ): void {
		$widget->start_controls_section(
			'free_shipping_style',
			array(
				'label'     => __( 'Free shipping progress', 'galaxie-woo' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_free_shipping' => 'yes' ),
			)
		);

		PixfortControls::palette_control( $widget, 'fs_color', __( 'Text color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-free-shipping-text', 'color' );
		PixfortControls::palette_control( $widget, 'fs_amount_color', __( 'Amount color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-free-shipping-amount', 'color' );

		$widget->add_responsive_control(
			'fs_size',
			array(
				'label'      => __( 'Text size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 10, 'max' => 32 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 14 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-free-shipping-text' => 'font-size: {{SIZE}}{{UNIT}};' ),
			)
		);

		$widget->add_control(
			'fs_bar_heading',
			array( 'label' => __( 'Bar', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before', 'condition' => array( 'free_shipping_bar' => 'yes' ) )
		);

		PixfortControls::palette_control( $widget, 'fs_track', __( 'Track', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-free-shipping-track', 'background-color', array( 'free_shipping_bar' => 'yes' ) );
		PixfortControls::palette_control( $widget, 'fs_fill', __( 'Fill', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-free-shipping-fill', 'background-color', array( 'free_shipping_bar' => 'yes' ) );
		PixfortControls::palette_control( $widget, 'fs_fill_done', __( 'Fill once reached', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-free-shipping.is-achieved .galaxie-free-shipping-fill', 'background-color', array( 'free_shipping_bar' => 'yes' ) );

		$widget->add_responsive_control(
			'fs_bar_height',
			array(
				'label'      => __( 'Height', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 2, 'max' => 32 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 6 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-free-shipping-track' => 'height: {{SIZE}}{{UNIT}};' ),
				'condition'  => array( 'free_shipping_bar' => 'yes' ),
			)
		);

		$widget->add_responsive_control(
			'fs_bar_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 20 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 999 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-free-shipping-track, {{WRAPPER}} .galaxie-free-shipping-fill' => 'border-radius: {{SIZE}}{{UNIT}};' ),
				'condition'  => array( 'free_shipping_bar' => 'yes' ),
			)
		);

		$widget->end_controls_section();
	}

	public static function register_totals_style( object $widget ): void {
		self::register_totals_type( $widget );
		self::register_free_shipping_style( $widget );

		$widget->start_controls_section( 'box_style', array( 'label' => __( 'Totals box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		// Was a hand-rolled background + padding + radius slider. The shared set
		// keeps all three control ids (`box_bg`, `box_padding`, `box_radius`),
		// so nothing already configured is lost — but the radius now sits
		// behind pixfort's own scale, with the slider as the Custom entry.
		PixfortControls::surface( $widget, 'box', '{{WRAPPER}} .galaxie-cart-totals' );

		$widget->end_controls_section();
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * A stored icon identifier turned into pixfort's own SVG.
	 *
	 * The stored value is a path like `Line/pixfort-icon-trash-can-1`, and
	 * echoing it is how the remove column once printed that string on screen to
	 * a shopper. `getIcon()` is what turns it into markup — and it returns an
	 * empty string for a name it cannot resolve, so a bare × stands in rather
	 * than an invisible link.
	 */
	public static function icon_markup( string $identifier ): string {
		if ( '' === $identifier || ! class_exists( '\PixfortCore' ) ) {
			return '&times;';
		}

		$svg = (string) \PixfortCore::instance()->icons->getIcon( $identifier, 20, 'galaxie-cart-remove-icon' );

		return '' !== trim( $svg ) ? $svg : '&times;';
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<int,array<string,mixed>>
	 */
	public static function fields( array $settings ): array {
		$fields = array();

		foreach ( (array) ( $settings['lines'] ?? array() ) as $row ) {
			if ( isset( $row['field'] ) && isset( self::FIELDS[ $row['field'] ] ) ) {
				$fields[] = $row;
			}
		}

		return $fields;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	public static function render_table( array $settings ): void {
		// Anything the request has to say to the shopper, said above the cart it
		// is about. Nothing listens unless something asked to be heard, so a
		// normal load prints nothing and a page with WooCommerce's own notices
		// block does not print them twice.
		do_action( 'galaxie_cart_before_table' );

		$fields = self::fields( $settings );

		// Widgets saved before Position was withdrawn still carry `text-left`
		// under these ids, and pixfort would still print it. Unregistering a
		// control does not unsave it, so the values are cleared here — the
		// column is the only thing that aims a cart cell now.
		foreach ( array( 'head', 'name', 'price', 'subtotal' ) as $prefix ) {
			$settings[ $prefix . '_position' ] = '';
		}

		printf(
			'<form class="woocommerce-cart-form galaxie-cart-form" action="%s" method="post" data-galaxie-auto="%s" style="--galaxie-cart-cols: %s;">',
			esc_url( wc_get_cart_url() ),
			esc_attr( 'yes' === ( $settings['auto_update'] ?? 'yes' ) ? '1' : '0' ),
			esc_attr( self::columns( $fields ) )
		);

		if ( 'yes' === ( $settings['show_headings'] ?? 'yes' ) ) {
			printf(
				'<div class="galaxie-cart-head galaxie-cart-line %s">',
				esc_attr( PixfortControls::surface_classes( $settings, 'head' ) )
			);

			foreach ( $fields as $row ) {
				printf( '<div class="%s">', esc_attr( self::cell_class( $row ) ) );

				$label = (string) ( $row['heading'] ?? '' );

				if ( '' !== $label ) {
					echo PixfortControls::render_text( $settings, 'head', esc_html( $label ), self::align_of( $row ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
				}

				echo '</div>';
			}

			echo '</div>';
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product instanceof \WC_Product || ! apply_filters( 'woocommerce_cart_item_visible', true, $item, $key ) ) {
				continue;
			}

			printf( '<div class="galaxie-cart-line" data-galaxie-key="%s">', esc_attr( $key ) );

			foreach ( $fields as $row ) {
				printf( '<div class="%s">', esc_attr( self::cell_class( $row ) ) );
				self::render_field( (string) $row['field'], $key, $item, $product, $settings, self::align_of( $row ) );
				echo '</div>';
			}

			echo '</div>';
		}

		self::render_actions( $settings );

		wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' );
		echo '</form>';
		echo Dialog::render( $settings, 'kit_edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * The one `grid-template-columns` the header and every line share.
	 *
	 * Each row used to be its own grid with `grid-auto-columns: 1fr` and
	 * `width: max-content` on Image and Remove, so every row measured its own
	 * content and reached its own answer: the header's word "Imagem" is narrow,
	 * an 88px photograph is not, and from the second column on nothing sat over
	 * anything. One explicit template, identical on every row, is what makes
	 * this a table rather than a stack of independent rows.
	 *
	 * Content-sized keywords (`auto`, `max-content`) cannot appear here for the
	 * same reason: they too resolve per grid, which means per row.
	 *
	 * @param array<int,array<string,mixed>> $fields
	 */
	private static function columns( array $fields ): string {
		$parts = array();

		foreach ( $fields as $row ) {
			$width = is_array( $row['col_width'] ?? null ) ? $row['col_width'] : array();
			$size  = $width['size'] ?? '';

			if ( '' === $size || null === $size ) {
				$field   = (string) ( $row['field'] ?? '' );
				$parts[] = 'thumb' === $field ? '100px' : ( 'remove' === $field ? '48px' : '1fr' );
				continue;
			}

			$parts[] = (float) $size . ( (string) ( $width['unit'] ?? 'fr' ) );
		}

		return implode( ' ', $parts );
	}

	/**
	 * A cell's classes: which field it is, and how that column is aligned.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function cell_class( array $row ): string {
		$valign = (string) ( $row['valign'] ?? 'middle' );

		if ( ! in_array( $valign, array( 'top', 'middle', 'bottom' ), true ) ) {
			$valign = 'middle';
		}

		return sprintf(
			'galaxie-cart-cell galaxie-cart-cell-%s galaxie-cart-cell--%s galaxie-cart-cell--v-%s',
			(string) ( $row['field'] ?? '' ),
			self::align_of( $row ),
			$valign
		);
	}

	/**
	 * A column's alignment, which the heading and every value in it follow.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function align_of( array $row ): string {
		$align = (string) ( $row['align'] ?? 'left' );

		return in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'left';
	}

	/**
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $settings
	 */
	private static function render_field( string $field, string $key, array $item, \WC_Product $product, array $settings, string $align = 'left' ): void {
		switch ( $field ) {
			case 'thumb':
				$link = $product->is_visible() ? $product->get_permalink( $item ) : '';
				$img  = $product->get_image( 'woocommerce_thumbnail' );
				printf(
					// The radius is a pixfort class on the wrapper, which clips
					// the image to it — putting it on the <img> would mean
					// rewriting the attributes WooCommerce built.
					'<span class="galaxie-cart-thumb ' . esc_attr( PixfortControls::thumb_classes( $settings, 'thumb' ) ) . '">%s</span>',
					$link
						? sprintf( '<a href="%s">%s</a>', esc_url( $link ), $img ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_image() returns escaped markup.
						: $img // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same.
				);
				break;

			case 'name':
				$name = apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $item, $key );
				// Links a module appends to the name for WooCommerce's own templates
				// ("Editar kit") are printed under the item data here instead.
				$name = (string) preg_replace( '#<span class="galaxie-kit-edit-wrap">.*?</span>#s', '', (string) $name );
				$link = $product->is_visible() ? $product->get_permalink( $item ) : '';
				echo PixfortControls::render_text( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
					$settings,
					'name',
					sprintf(
						'<span class="galaxie-cart-name">%s</span>',
						$link
							? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( wp_strip_all_tags( $name ) ) )
							: esc_html( wp_strip_all_tags( $name ) )
					),
					$align
				);

				$meta = wc_get_formatted_cart_item_data( $item, true );
				if ( $meta ) {
					printf( '<span class="galaxie-cart-meta">%s</span>', esc_html( wp_strip_all_tags( $meta ) ) );
				}

				// Links a module adds under the line (Gift Wrap: "Editar kit").
				do_action( 'galaxie_cart_item_after_meta', $item, $key );
				break;

			case 'price':
				echo PixfortControls::render_text( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped markup.
					$settings,
					'price',
					sprintf(
						'<span class="galaxie-cart-price">%s</span>',
						wp_kses_post( apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $product ), $item, $key ) )
					),
					$align
				);
				break;

			case 'quantity':
				self::render_quantity( $key, $item, $product, $settings );
				break;

			case 'subtotal':
				// The pixfort element wraps the span rather than living inside
				// it: `cart.ts` replaces that span's contents with the amount
				// the server sends back, and any styling kept inside would be
				// discarded the first time a quantity changed.
				echo PixfortControls::render_text( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped markup.
					$settings,
					'subtotal',
					sprintf(
						'<span class="galaxie-cart-subtotal">%s</span>',
						wp_kses_post( apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $product, $item['quantity'] ), $item, $key ) )
					),
					$align
				);
				break;

			case 'remove':
				printf(
					'<a href="%s" class="galaxie-cart-remove" aria-label="%s" data-galaxie-remove="%s">%s</a>',
					esc_url( wc_get_cart_remove_url( $key ) ),
					esc_attr__( 'Remove this item', 'galaxie-woo' ),
					esc_attr( $key ),
					self::icon_markup( PixfortControls::icon_value( $settings, 'remove_icon' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own SVG.
				);
				break;
		}
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private static function render_quantity( string $key, array $item, \WC_Product $product, array $settings ): void {
		echo '<span class="galaxie-cart-qty">';

		// A line whose quantity belongs to something else — a gift's ribbon or
		// card (Gift Wrap) — shows its number the way a sold-individually one does.
		$locked = (bool) apply_filters( 'galaxie_cart_item_quantity_locked', false, $item, $key );

		if ( $locked || $product->is_sold_individually() ) {
			$fixed = $locked ? (int) $item['quantity'] : 1;
			printf( '<input type="hidden" name="cart[%s][qty]" value="%d" />', esc_attr( $key ), (int) $fixed );
			printf( '<span class="galaxie-cart-qty-fixed">%d</span>', (int) $fixed );
		} else {
			QuantityField::render(
				$settings,
				'qty',
				$product,
				array(
					'input_name'   => "cart[{$key}][qty]",
					'input_value'  => $item['quantity'],
					'max_value'    => $product->get_max_purchase_quantity(),
					// Zero is WooCommerce's own "remove this line", which is how
					// the stepper and the trash icon end up on one path.
					'min_value'    => '0',
					'product_name' => $product->get_name(),
				)
			);
		}

		echo '</span>';
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private static function render_actions( array $settings ): void {
		echo '<div class="galaxie-cart-actions">';

		// Kept in the markup and hidden by CSS while the script runs, so a
		// shopper without JavaScript still has a way to commit a change.
		printf(
			'<button type="submit" name="update_cart" value="1" class="galaxie-cart-update">%s</button>',
			esc_html( (string) ( $settings['update_text'] ?? __( 'Atualizar carrinho', 'galaxie-woo' ) ) )
		);

		echo '</div>';
	}

	/**
	 * The totals block.
	 *
	 * The rows are WooCommerce's own helper functions, in WooCommerce's own
	 * order and under WooCommerce's own conditions — every tax and shipping
	 * rule a store has configured still decides what appears. What is NOT used
	 * is `woocommerce_cart_totals()`, which loads the whole cart-totals
	 * template: that template brings its own `<h2>Cart totals</h2>`, its own
	 * subtotal row and its own Proceed to checkout button, so calling it and
	 * then adding a heading and a checkout button of our own printed each of
	 * them twice. That is exactly what it did on the first cut.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function totals_markup( array $settings = array() ): string {
		if ( ! self::available() ) {
			return '';
		}

		$cart     = WC()->cart;
		$heading  = (string) ( $settings['totals_heading'] ?? '' );
		$checkout = (string) ( $settings['checkout_text'] ?? __( 'Finalizar compra', 'galaxie-woo' ) );

		ob_start();
		// `cart_totals` is WooCommerce's own class, and it is here for its
		// cart.js: after the shipping calculator or a shipping method change,
		// that script re-fetches the cart page and swaps `div.cart_totals` for
		// the fresh one. Without the class the new rates arrived and were thrown
		// away — the page kept showing no shipping at all.
		printf(
			'<div class="galaxie-cart-totals cart_totals %s">',
			esc_attr( PixfortControls::surface_classes( $settings, 'box' ) )
		);

		if ( '' !== $heading ) {
			printf( '<h3 class="galaxie-cart-totals-heading %1$s">%2$s</h3>', esc_attr( PixfortControls::text_classes( $settings, 'sumh' ) ), esc_html( $heading ) );
		}

		self::render_free_shipping( $settings );

		echo self::rows_markup( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		printf(
			'<a class="galaxie-cart-checkout" href="%s">%s</a>',
			esc_url( wc_get_checkout_url() ),
			PixfortControls::render_button( $settings, 'checkout', $checkout ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own component markup.
		);

		if ( 'yes' === ( $settings['show_continue'] ?? '' ) ) {
			printf(
				'<a class="galaxie-cart-continue" href="%s">%s</a>',
				esc_url( wc_get_page_permalink( 'shop' ) ),
				PixfortControls::render_button( $settings, 'continue', (string) ( $settings['continue_text'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own component markup.
			);
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * How far the cart is from free shipping.
	 *
	 * Rendered OUTSIDE the rows on purpose. The rows are what the AJAX update
	 * replaces, and that request has no widget settings — so a line rendered in
	 * there would come back stripped of its message and its styling on the
	 * first quantity change. Here it stays put, and the update only feeds it
	 * new numbers: the templates travel in data attributes, so the sentence the
	 * merchant wrote is the one that gets refilled.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function render_free_shipping( array $settings ): void {
		if ( 'yes' !== ( $settings['show_free_shipping'] ?? 'yes' ) ) {
			return;
		}

		// Nothing until the shopper has said where the parcel goes, and nothing
		// where free shipping does not reach. "Faltam R$ 23,30 para o frete
		// grátis" to someone who has not typed a CEP, or who lives outside the
		// zone, is a promise the checkout will not keep. That holds for a typed
		// amount too: the amount can be fixed, the geography cannot be skipped.
		if ( ! FreeShipping::destination_known() ) {
			return;
		}

		$reach     = FreeShipping::threshold();
		$threshold = 'fixed' === ( $settings['free_shipping_source'] ?? 'auto' )
			? ( $reach > 0 ? (float) ( $settings['free_shipping_amount'] ?? 0 ) : 0.0 )
			: $reach;

		// Nothing to aim at — no free shipping rule, or one with no minimum.
		// Saying nothing beats inventing a goal.
		if ( $threshold <= 0 ) {
			return;
		}

		$state    = FreeShipping::state( $threshold );
		$template = (string) ( $settings['free_shipping_message'] ?? '' );
		$done     = (string) ( $settings['free_shipping_done'] ?? '' );

		printf(
			'<div class="galaxie-free-shipping %1$s" data-galaxie-free-shipping="1" data-template="%2$s" data-done="%3$s">',
			esc_attr( $state['achieved'] ? 'is-achieved' : 'is-pending' ),
			esc_attr( $template ),
			esc_attr( $done )
		);

		printf(
			'<div class="galaxie-free-shipping-text">%s</div>',
			$state['achieved'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				? esc_html( $done )
				: FreeShipping::message( $template, $state['remaining'] )
		);

		if ( 'yes' === ( $settings['free_shipping_bar'] ?? 'yes' ) ) {
			printf(
				'<div class="galaxie-free-shipping-track"><div class="galaxie-free-shipping-fill" style="width: %s%%;"></div></div>',
				esc_attr( (string) round( $state['percent'], 2 ) )
			);
		}

		echo '</div>';
	}

	/**
	 * Just the amounts — the block a quantity change actually invalidates.
	 *
	 * Kept separate from {@see totals_markup()} because the AJAX update has no
	 * widget settings to render with: replacing the whole box from there would
	 * hand back a totals block stripped of the background, radius and checkout
	 * button the merchant configured. Replacing only these rows cannot.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function rows_markup( array $settings = array() ): string {
		self::ensure_totals();

		if ( ! self::available() ) {
			return '';
		}

		$cart = WC()->cart;

		ob_start();
		printf(
			'<div class="galaxie-cart-totals-rows" data-label-class="%s" data-value-class="%s" data-total-class="%s">',
			esc_attr( PixfortControls::text_classes( $settings, 'suml' ) ),
			esc_attr( PixfortControls::text_classes( $settings, 'sumv' ) ),
			esc_attr( PixfortControls::text_classes( $settings, 'sumt' ) )
		);

		self::$row_label_class = PixfortControls::text_classes( $settings, 'suml' );
		self::$row_value_class = PixfortControls::text_classes( $settings, 'sumv' );

		self::row( __( 'Subtotal', 'woocommerce' ), self::capture( 'wc_cart_totals_subtotal_html' ), 'subtotal' );

		foreach ( $cart->get_coupons() as $code => $coupon ) {
			self::row(
				wc_cart_totals_coupon_label( $coupon, false ),
				self::capture( 'wc_cart_totals_coupon_html', $coupon ),
				'coupon coupon-' . sanitize_title( $code )
			);
		}

		if ( 'yes' === ( $settings['show_shipping'] ?? 'yes' ) && $cart->needs_shipping() && $cart->show_shipping() ) {
			if ( 'chosen' === ( $settings['shipping_display'] ?? 'chosen' ) ) {
				$chosen = self::chosen_shipping( $settings );
				$method = 'yes' === ( $settings['shipping_show_method'] ?? 'yes' ) ? $chosen['method'] : '';

				printf(
					'<div class="galaxie-cart-total-row galaxie-cart-total-shipping is-chosen"><span class="galaxie-cart-total-label %1$s">%2$s%3$s</span><span class="galaxie-cart-total-value %4$s">%5$s</span></div>',
					esc_attr( self::$row_label_class ),
					esc_html( (string) ( $settings['shipping_label'] ?? __( 'Frete', 'galaxie-woo' ) ) ),
					'' !== $method ? '<span class="galaxie-cart-total-method ' . esc_attr( PixfortControls::text_classes( $settings, 'sums' ) ) . '">' . esc_html( $method ) . '</span>' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					esc_attr( self::$row_value_class ),
					$chosen['value'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in chosen_shipping().
				);
			} else {
				// The same card as the Shipping Options widget, not WooCommerce's
				// template: that one lays the options out as a table row whose
				// labels wrap under the price in a narrow column.
				$card = self::shipping_card(
					$settings,
					'totals',
					array( 'free_text' => (string) ( $settings['shipping_free_text'] ?? __( 'Grátis', 'galaxie-woo' ) ) )
				);

				printf(
					'<div class="galaxie-cart-total-row galaxie-cart-total-shipping is-list"><span class="galaxie-cart-total-label %1$s">%2$s</span>%3$s</div>',
					esc_attr( self::$row_label_class ),
					esc_html( (string) ( $settings['shipping_label'] ?? __( 'Frete', 'galaxie-woo' ) ) ),
					'' !== $card // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
						? $card
						: '<span class="galaxie-cart-total-value ' . esc_attr( self::$row_value_class ) . '">' . esc_html( (string) ( $settings['shipping_empty_text'] ?? __( 'Informe o CEP', 'galaxie-woo' ) ) ) . '</span>'
				);
			}
		}

		foreach ( $cart->get_fees() as $fee ) {
			self::row( $fee->name, self::capture( 'wc_cart_totals_fee_html', $fee ), 'fee' );
		}

		if ( wc_tax_enabled() && ! $cart->display_prices_including_tax() ) {
			if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) {
				foreach ( $cart->get_tax_totals() as $tax ) {
					self::row( $tax->label, wp_kses_post( $tax->formatted_amount ), 'tax' );
				}
			} else {
				self::row( WC()->countries->tax_or_vat(), self::capture( 'wc_cart_totals_taxes_total_html' ), 'tax' );
			}
		}

		// The "Order total" style section, which until now was registered and
		// never printed: the row wore the shared label and amount classes.
		self::row( __( 'Total', 'woocommerce' ), self::capture( 'wc_cart_totals_order_total_html' ), 'order-total', PixfortControls::text_classes( $settings, 'sumt' ) );

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * The carrier the shopper picked and what it costs, for a totals box that
	 * leaves the choosing to the shipping options widget.
	 *
	 * @param array<string,mixed> $settings
	 * @return array{value:string,method:string} Value is escaped HTML, method plain text.
	 */
	private static function chosen_shipping( array $settings ): array {
		$empty = array(
			'value'  => esc_html( (string) ( $settings['shipping_empty_text'] ?? __( 'Informe o CEP', 'galaxie-woo' ) ) ),
			'method' => '',
		);

		// Same rule as the card: a price for the store's own address is not a
		// price for this shopper.
		if ( ! FreeShipping::destination_known() ) {
			return $empty;
		}

		$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		$tax    = WC()->cart->display_prices_including_tax();
		$total   = 0.0;
		$methods = array();

		foreach ( WC()->shipping()->get_packages() as $index => $package ) {
			$id = (string) ( $chosen[ $index ] ?? '' );

			if ( '' === $id || empty( $package['rates'][ $id ] ) ) {
				continue;
			}

			$rate      = $package['rates'][ $id ];
			$cost      = (float) $rate->get_cost();
			$total    += $tax ? $cost + array_sum( array_map( 'floatval', (array) $rate->get_taxes() ) ) : $cost;
			$parts     = ShippingRates::parts( $rate );
			$methods[] = '' !== $parts['days'] ? $parts['name'] . ' · ' . $parts['days'] : $parts['name'];
		}

		if ( ! $methods ) {
			return $empty;
		}

		return array(
			'value'  => $total > 0 ? wp_kses_post( wc_price( $total ) ) : esc_html( (string) ( $settings['shipping_free_text'] ?? __( 'Grátis', 'galaxie-woo' ) ) ),
			'method' => implode( ' + ', array_unique( $methods ) ),
		);
	}

	/**
	 * The shipping options as one card: pixfort's Card around one row per carrier.
	 *
	 * Empty string when there is nothing to choose from, which is how callers
	 * know to show a message or nothing instead.
	 *
	 * @param array<string,mixed>                                          $settings Widget settings; the text sets are read from option_name, option_days, option_price, options_note and the card from card_*.
	 * @param array{free_text?:string,show_days?:bool,show_destination?:bool} $args
	 */
	public static function shipping_card( array $settings, string $instance, array $args = array() ): string {
		if ( ! self::available() || ! WC()->cart->needs_shipping() ) {
			return '';
		}

		// No CEP, no options. Without one WooCommerce quotes for the store's own
		// address, and what it lists is what shipping to the store would cost,
		// not what shipping to the shopper will.
		if ( ! FreeShipping::destination_known() ) {
			return '';
		}

		self::ensure_totals();

		$packages  = WC()->shipping()->get_packages();
		$chosen    = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		$free      = (string) ( $args['free_text'] ?? __( 'Grátis', 'galaxie-woo' ) );
		$show_days = $args['show_days'] ?? true;
		$name      = PixfortControls::text_classes( $settings, 'option_name' );
		$days      = PixfortControls::text_classes( $settings, 'option_days' );
		$price     = PixfortControls::text_classes( $settings, 'option_price' );
		$card      = array_key_exists( 'card_rounded', $settings ) ? PixfortControls::surface_classes( $settings, 'card' ) : 'rounded-xl';
		$listed    = false;

		ob_start();

		foreach ( $packages as $index => $package ) {
			$rates = (array) ( $package['rates'] ?? array() );

			if ( ! $rates ) {
				continue;
			}

			$listed  = true;
			$current = isset( $chosen[ $index ], $rates[ $chosen[ $index ] ] ) ? (string) $chosen[ $index ] : (string) array_key_first( $rates );

			printf( '<div class="card galaxie-shipping-card overflow-hidden %s"><ul class="galaxie-shipping-card-list" role="radiogroup">', esc_attr( $card ) );

			foreach ( $rates as $rate ) {
				$rate_id  = (string) $rate->get_id();
				$input_id = sprintf( 'galaxie_shipping_%d_%s_%s', $index, sanitize_title( $rate_id ), sanitize_key( $instance ) );
				$parts    = ShippingRates::parts( $rate );
				$shown    = ShippingRates::display_cost( $rate );

				printf(
					'<li class="galaxie-shipping-row%1$s"><label for="%2$s"><input type="radio" name="shipping_method[%3$d]" data-index="%3$d" id="%2$s" value="%4$s" class="shipping_method" %5$s /><span class="galaxie-shipping-row-text"><span class="galaxie-shipping-row-name %6$s">%7$s</span>%8$s</span><span class="galaxie-shipping-row-price %9$s">%10$s</span></label>',
					$shown <= 0 ? ' is-free' : '',
					esc_attr( $input_id ),
					(int) $index,
					esc_attr( $rate_id ),
					checked( $rate_id, $current, false ),
					esc_attr( $name ),
					esc_html( $parts['name'] ),
					$show_days && '' !== $parts['days'] ? '<span class="galaxie-shipping-row-days ' . esc_attr( $days ) . '">' . esc_html( $parts['days'] ) . '</span>' : '',
					esc_attr( $price ),
					$shown > 0 ? wp_kses_post( wc_price( $shown ) ) : esc_html( $free )
				);

				do_action( 'woocommerce_after_shipping_rate', $rate, $index );

				echo '</li>';
			}

			echo '</ul></div>';
		}

		if ( $listed && ( $args['show_destination'] ?? true ) ) {
			$package     = reset( $packages );
			$destination = (string) apply_filters( 'galaxie_cart_shipping_destination', WC()->countries->get_formatted_address( (array) ( $package['destination'] ?? array() ), ', ' ), $package );

			if ( '' !== $destination ) {
				printf(
					'<p class="galaxie-shipping-options-note galaxie-shipping-options-destination %1$s">%2$s</p>',
					esc_attr( PixfortControls::text_classes( $settings, 'options_note' ) ),
					/* translators: %s: shipping destination. */
					sprintf( esc_html__( 'Shipping to %s.', 'woocommerce' ), '<strong>' . esc_html( $destination ) . '</strong>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				);
			}
		}

		$html = (string) ob_get_clean();

		return $listed ? $html : '';
	}

	/** One label/value pair in the totals block. */
	/** Set by {@see rows_markup()}, read by {@see row()}, which it calls. */
	private static string $row_label_class = '';
	private static string $row_value_class = '';

	/**
	 * @param string|null $classes The row's own text classes, for both label and
	 *                             amount, instead of the shared row ones — the
	 *                             order total has its own set.
	 */
	private static function row( string $label, string $value, string $modifier, ?string $classes = null ): void {
		printf(
			'<div class="galaxie-cart-total-row galaxie-cart-total-%1$s"><span class="galaxie-cart-total-label %2$s">%3$s</span><span class="galaxie-cart-total-value %4$s">%5$s</span></div>',
			esc_attr( $modifier ),
			esc_attr( $classes ?? self::$row_label_class ),
			esc_html( wp_strip_all_tags( $label ) ),
			esc_attr( $classes ?? self::$row_value_class ),
			$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own formatted amount.
		);
	}

	/**
	 * One widget's saved settings, found by the ids the page already carries.
	 *
	 * The AJAX update re-renders the totals rows, and until now it did so with
	 * no settings at all — which is why those rows were styled by selectors
	 * while everything else in the cart used pixfort's classes. The browser
	 * sends the Elementor post and element ids it can read off the DOM, and the
	 * rows come back looking like the ones they replace.
	 *
	 * The settings as the widget itself sees them, defaults included. This used
	 * to return the raw saved values, which was enough while only the rows'
	 * text classes were read. Elementor does not save a control left at its
	 * default, though. Once the whole totals box was redrawn from these
	 * settings (after a carrier change), "Continuar comprando" vanished and
	 * the checkout button lost its full width: both are defaults. A widget
	 * with nothing changed at all was not even found, because its saved
	 * settings are an empty array.
	 *
	 * @return array<string,mixed>
	 */
	public static function element_settings( int $post_id, string $element_id ): array {
		if ( $post_id <= 0 || '' === $element_id || ! class_exists( '\Elementor\Plugin' ) ) {
			return array();
		}

		$document = \Elementor\Plugin::$instance->documents->get( $post_id );

		if ( ! $document ) {
			return array();
		}

		$data = self::find_element( (array) $document->get_elements_data(), $element_id );

		if ( ! $data ) {
			return array();
		}

		$instance = \Elementor\Plugin::$instance->elements_manager->create_element_instance( $data );

		return $instance ? (array) $instance->get_settings_for_display() : (array) ( $data['settings'] ?? array() );
	}

	/**
	 * The element's whole data array, not just its settings.
	 *
	 * @param array<int,array<string,mixed>> $elements
	 * @return array<string,mixed>
	 */
	private static function find_element( array $elements, string $id ): array {
		foreach ( $elements as $element ) {
			if ( ( $element['id'] ?? '' ) === $id ) {
				return (array) $element;
			}

			$found = self::find_element( (array) ( $element['elements'] ?? array() ), $id );

			if ( $found ) {
				return $found;
			}
		}

		return array();
	}

	/**
	 * WooCommerce's totals helpers echo rather than return.
	 *
	 * @param mixed ...$args
	 */
	private static function capture( string $fn, ...$args ): string {
		if ( ! function_exists( $fn ) ) {
			return '';
		}

		ob_start();
		$fn( ...$args );

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	public static function render_empty( array $settings ): void {
		$template = 'template' === ( $settings['empty_mode'] ?? 'message' )
			? (int) ( $settings['empty_template'] ?? 0 )
			: 0;

		if ( $template ) {
			$content = self::rendered_template( $template );

			// A template that was deleted, or emptied, must not leave a blank
			// page where a cart used to be.
			if ( '' !== trim( $content ) ) {
				echo '<div class="galaxie-cart galaxie-cart-empty galaxie-cart-empty-template">'
					. $content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor renders its own escaped markup.
					. '</div>';
				return;
			}
		}

		printf(
			'<div class="galaxie-cart galaxie-cart-empty"><p>%s</p><a class="galaxie-cart-empty-button" href="%s">%s</a></div>',
			esc_html( (string) ( $settings['empty_text'] ?? '' ) ),
			esc_url( wc_get_page_permalink( 'shop' ) ),
			esc_html( (string) ( $settings['empty_button_text'] ?? '' ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Option lists
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,string>
	 */
	private static function field_options(): array {
		$options = array();

		foreach ( self::FIELDS as $key => $label ) {
			$options[ $key ] = __( $label, 'galaxie-woo' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- a fixed, known list.
		}

		return $options;
	}

	/**
	 * Read from the library post type rather than through an Elementor helper:
	 * a helper that changes empties this dropdown silently, which is the
	 * failure mode this project has paid for most often.
	 *
	 * @return array<int|string,string>
	 */
	private static function template_options(): array {
		$options = array( '' => __( '— Select —', 'galaxie-woo' ) );

		foreach ( get_posts(
			array(
				'post_type'        => 'elementor_library',
				'post_status'      => 'publish',
				'posts_per_page'   => 100,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		) as $template ) {
			$options[ $template->ID ] = '' !== $template->post_title
				? $template->post_title
				/* translators: %d is a template ID. */
				: sprintf( __( 'Template #%d', 'galaxie-woo' ), $template->ID );
		}

		return $options;
	}

	private static function rendered_template( int $id ): string {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! get_post( $id ) ) {
			return '';
		}

		return (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $id, true );
	}
}

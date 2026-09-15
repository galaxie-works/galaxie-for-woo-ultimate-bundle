<?php
/**
 * "Galaxie Wishlist Button" Elementor widget — pixfort-native styled toggle.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Wishlist\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Modules\Wishlist\Lists;
use Galaxie\Woo\Modules\Wishlist\Module;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the current product's wishlist toggle through pixfort's own Button
 * component — same approach (and the same `btn_*` attr mapping) as
 * {@see \Galaxie\Woo\Modules\VariationSwatches\Widget\VariationBadgesWidget},
 * so it inherits pixfort's global color dropdown (Primary, Gray 1-9, Dynamic
 * Colors — which is what makes it follow the theme's light/dark switch) and
 * pixfort's own icon picker, instead of a separate hardcoded palette.
 *
 * BOTH states are fully customizable and BOTH are rendered server-side, one
 * hidden by CSS — exactly the pattern already used for "Badge"/"Badge —
 * selecionado" in the variation widget. That's deliberate: a shopper's
 * "favoritado" heart usually wants a different icon AND a different color from
 * the empty one, and a single rendered button would leave JS rewriting
 * pixfort's markup by hand (fragile, and impossible to restyle from Elementor).
 * Toggling is therefore a one-line class flip in JS — see globals/wishlist.ts.
 */
final class WishlistButtonWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-wishlist-button';
	}

	public function get_title(): string {
		return __( 'Galaxie Wishlist Button', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-heart';
	}

	public function get_categories(): array {
		return array( 'galaxie' );
	}

	private function pixfort_active(): bool {
		return class_exists( '\PixfortCore' );
	}

	protected function register_controls(): void {
		$this->register_button_controls(
			'add',
			'add_section',
			__( 'Button — not saved', 'galaxie-woo' ),
			__( 'Adicionar aos favoritos', 'galaxie-woo' ),
			'primary',
			'outline'
		);
		$this->register_button_controls(
			'saved',
			'saved_section',
			__( 'Button — saved', 'galaxie-woo' ),
			__( 'Remover dos favoritos', 'galaxie-woo' ),
			'primary',
			'flat'
		);

		// The heart saves to the default list; this opens all of them.
		$this->start_controls_section( 'list_section', array( 'label' => __( 'Add to a list button', 'galaxie-woo' ) ) );

		$this->add_control(
			'list_enabled',
			array(
				'label'        => __( 'Show "Add to a list"', 'galaxie-woo' ),
				'description'  => __( 'A second button beside the heart that opens the customer\'s lists: tick the ones to save to, or create a new one.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$shown = array( 'list_enabled' => 'yes' );

		$this->add_control( 'list_title', array( 'label' => __( 'Popover title', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Salvar em', 'galaxie-woo' ), 'condition' => $shown ) );
		$this->add_control( 'list_placeholder', array( 'label' => __( 'New list placeholder', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Nome da nova lista', 'galaxie-woo' ), 'condition' => $shown ) );
		$this->add_control( 'list_create', array( 'label' => __( 'Create button text', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Criar lista', 'galaxie-woo' ), 'condition' => $shown ) );

		PixfortControls::button( $this, 'list', array( 'text' => __( 'Salvar em uma lista', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm', 'icon' => 'Line/pixfort-icon-heart-1' ), $shown );

		// The popover only exists after a tap; styling it needs it open.
		$this->add_control(
			'list_popover_preview',
			array(
				'label'        => __( 'Show the popover in the editor', 'galaxie-woo' ),
				'description'  => __( 'Keeps the "Salvar em" popover open, with sample lists, while you style it. Customers only see it after tapping the button.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'separator'    => 'before',
				'condition'    => $shown,
			)
		);

		$this->end_controls_section();

		// Style, in the order the screen reads.
		$this->register_layout_style();
		$this->register_heart_style();
		$this->register_popover_style( $shown );
	}

	/** The row both buttons sit in. */
	private function register_layout_style(): void {
		$this->start_controls_section( 'layout_style', array( 'label' => __( 'Buttons: layout', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		// The variable says whether the buttons fill the row; the stylesheet turns
		// it into a grow and a minimum width. A value per device resets it, so a
		// row stretched on desktop can stop stretching on mobile.
		$this->add_responsive_control(
			'buttons_align',
			array(
				'label'                => __( 'Alignment', 'galaxie-woo' ),
				'type'                 => Controls_Manager::CHOOSE,
				'toggle'               => true,
				'options'              => array(
					'left'    => array( 'title' => __( 'Left', 'galaxie-woo' ), 'icon' => 'eicon-text-align-left' ),
					'center'  => array( 'title' => __( 'Center', 'galaxie-woo' ), 'icon' => 'eicon-text-align-center' ),
					'right'   => array( 'title' => __( 'Right', 'galaxie-woo' ), 'icon' => 'eicon-text-align-right' ),
					'justify' => array( 'title' => __( 'Justified', 'galaxie-woo' ), 'icon' => 'eicon-text-align-justify' ),
					'stretch' => array( 'title' => __( 'Stretch', 'galaxie-woo' ), 'icon' => 'eicon-h-align-stretch' ),
				),
				'selectors_dictionary' => array(
					'left'    => 'justify-content: flex-start; --galaxie-wl-stretch: 0;',
					'center'  => 'justify-content: center; --galaxie-wl-stretch: 0;',
					'right'   => 'justify-content: flex-end; --galaxie-wl-stretch: 0;',
					'justify' => 'justify-content: space-between; --galaxie-wl-stretch: 0;',
					'stretch' => 'justify-content: flex-start; --galaxie-wl-stretch: 1;',
				),
				'selectors'            => array( '{{WRAPPER}} .galaxie-wishlist-buttons' => '{{VALUE}}' ),
			)
		);

		$this->add_responsive_control( 'buttons_gap', array( 'label' => __( 'Space between buttons', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 60 ) ), 'selectors' => array( '{{WRAPPER}} .galaxie-wishlist-buttons' => 'gap: {{SIZE}}{{UNIT}};' ) ) );

		foreach ( array( 'padding' => __( 'Padding', 'galaxie-woo' ), 'margin' => __( 'Margin', 'galaxie-woo' ) ) as $property => $label ) {
			$this->add_responsive_control(
				'buttons_' . $property,
				array(
					'label'      => $label,
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', 'rem', 'em', '%' ),
					'selectors'  => array( '{{WRAPPER}} .galaxie-wishlist-buttons' => $property . ': {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
				)
			);
		}

		$this->end_controls_section();
	}

	/**
	 * Shape and colours of the heart, over whatever the two Button sections chose.
	 *
	 * Default writes nothing, so a widget saved before this section existed
	 * looks exactly as it did. Circle and Rounded square are for the icon-only
	 * heart: one size for width and height, no padding, icon centred — the
	 * merchant picks colours and nothing else.
	 */
	private function register_heart_style(): void {
		$this->start_controls_section( 'heart_style', array( 'label' => __( 'Heart button: shape and colors', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		$shaped = array( 'heart_shape!' => '' );

		$this->add_control(
			'heart_shape',
			array(
				'label'       => __( 'Shape', 'galaxie-woo' ),
				'description' => __( 'Circle and Rounded square are made for "Icon only" buttons.', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					''       => __( 'Default', 'galaxie-woo' ),
					'circle' => __( 'Circle', 'galaxie-woo' ),
					'square' => __( 'Rounded square', 'galaxie-woo' ),
				),
				'default'     => '',
			)
		);

		// Variables, not the width itself: the stylesheet owns the shape and its
		// fallback size, so a circle picked without touching this is still round.
		$this->add_responsive_control( 'heart_size', array( 'label' => __( 'Button size', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px', 'em', 'rem' ), 'range' => array( 'px' => array( 'min' => 20, 'max' => 120 ) ), 'selectors' => array( '{{WRAPPER}} .galaxie-wishlist-btn' => '--galaxie-wl-size: {{SIZE}}{{UNIT}};' ), 'condition' => $shaped ) );
		$this->add_responsive_control( 'heart_radius', array( 'label' => __( 'Corner radius', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px', '%' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 40 ) ), 'selectors' => array( '{{WRAPPER}} .galaxie-wishlist-btn' => '--galaxie-wl-radius: {{SIZE}}{{UNIT}};' ), 'condition' => array( 'heart_shape' => 'square' ) ) );
		$this->add_responsive_control( 'heart_icon_size', array( 'label' => __( 'Icon size', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px', 'em' ), 'range' => array( 'px' => array( 'min' => 8, 'max' => 80 ) ), 'selectors' => array( '{{WRAPPER}} .galaxie-wishlist-btn .pixfort-icon' => 'width: {{SIZE}}{{UNIT}} !important; height: {{SIZE}}{{UNIT}} !important; font-size: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_control( 'heart_border_width', array( 'label' => __( 'Border width', 'galaxie-woo' ), 'description' => __( 'Used once a border color is picked below.', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 8 ) ), 'selectors' => array( '{{WRAPPER}} .galaxie-wishlist-btn' => '--galaxie-wl-border: {{SIZE}}{{UNIT}};' ) ) );

		// Hover outranks both states by specificity, so it shows over "saved" too.
		$states = array(
			'normal' => array( __( 'Normal', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-wishlist-add' ),
			'hover'  => array( __( 'Hover', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-wishlist-btn:hover, {{WRAPPER}} .galaxie-wishlist-btn:focus-visible' ),
			'saved'  => array( __( 'Saved', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-wishlist-saved' ),
		);

		// pixfort's .btn ships a zero-width border, so a colour brings a width too.
		$border = ' border-style: solid !important; border-width: var(--galaxie-wl-border, 1px) !important;';

		$this->start_controls_tabs( 'heart_state_tabs', array( 'separator' => 'before' ) );

		foreach ( $states as $state => $args ) {
			// Each part of a selector list gets its own descendant.
			$within = static fn( string $tail ): string => implode( ', ', array_map( static fn( string $part ): string => trim( $part ) . ' ' . $tail, explode( ',', $args[1] ) ) );

			$this->start_controls_tab( 'heart_' . $state . '_tab', array( 'label' => $args[0] ) );
			PixfortControls::palette_control( $this, 'heart_' . $state . '_bg', __( 'Background', 'galaxie-woo' ), $within( '.btn' ), 'background-color' );
			PixfortControls::icon_color( $this, 'heart_' . $state . '_icon', __( 'Icon color', 'galaxie-woo' ), $within( '.pixfort-icon' ) );
			PixfortControls::palette_control( $this, 'heart_' . $state . '_border', __( 'Border color', 'galaxie-woo' ), $within( '.btn' ), 'border-color', array(), $border );
			$this->end_controls_tab();
		}

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/** @param array<string,string> $shown */
	private function register_popover_style( array $shown ): void {
		$box = '{{WRAPPER}} .galaxie-wishlist-popover';

		$this->start_controls_section( 'popover_box_style', array( 'label' => __( 'Popover: box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => $shown ) );
		PixfortControls::surface( $this, 'popover_box', $box );

		$this->add_responsive_control( 'popover_width', array( 'label' => __( 'Width', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px', 'vw' ), 'range' => array( 'px' => array( 'min' => 160, 'max' => 600 ) ), 'separator' => 'before', 'selectors' => array( $box => 'width: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'popover_gap', array( 'label' => __( 'Space between parts', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 40 ) ), 'selectors' => array( $box => 'gap: {{SIZE}}{{UNIT}};' ) ) );

		$this->add_control( 'popover_position_heading', array( 'label' => __( 'Position', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );

		$this->add_responsive_control(
			'popover_placement',
			array(
				'label'                => __( 'Opens', 'galaxie-woo' ),
				'type'                 => Controls_Manager::SELECT,
				// An explicit Below as well as the default, so a device can go back to it.
				'options'              => array(
					''      => __( 'Default (below)', 'galaxie-woo' ),
					'below' => __( 'Below the button', 'galaxie-woo' ),
					'above' => __( 'Above the button', 'galaxie-woo' ),
				),
				'selectors_dictionary' => array(
					'below' => 'top: calc(100% + var(--galaxie-wl-pop-y, 8px)); bottom: auto;',
					'above' => 'top: auto; bottom: calc(100% + var(--galaxie-wl-pop-y, 8px));',
				),
				'selectors'            => array( $box => '{{VALUE}}' ),
			)
		);

		// Against the "Salvar em uma lista" button's own box. The shift lives in a
		// variable so the offset and the script's keep-on-screen nudge add to it.
		$this->add_responsive_control(
			'popover_align',
			array(
				'label'                => __( 'Alignment', 'galaxie-woo' ),
				'type'                 => Controls_Manager::CHOOSE,
				'toggle'               => true,
				'options'              => array(
					'left'   => array( 'title' => __( 'Left edges', 'galaxie-woo' ), 'icon' => 'eicon-h-align-left' ),
					'center' => array( 'title' => __( 'Centered', 'galaxie-woo' ), 'icon' => 'eicon-h-align-center' ),
					'right'  => array( 'title' => __( 'Right edges', 'galaxie-woo' ), 'icon' => 'eicon-h-align-right' ),
				),
				'selectors_dictionary' => array(
					'left'   => 'left: 0; right: auto; --galaxie-wl-pop-shift: 0%;',
					'center' => 'left: 50%; right: auto; --galaxie-wl-pop-shift: -50%;',
					'right'  => 'left: auto; right: 0; --galaxie-wl-pop-shift: 0%;',
				),
				'selectors'            => array( $box => '{{VALUE}}' ),
			)
		);

		$this->add_responsive_control( 'popover_offset_y', array( 'label' => __( 'Distance from the button', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 60 ) ), 'selectors' => array( $box => '--galaxie-wl-pop-y: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'popover_offset_x', array( 'label' => __( 'Horizontal offset', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => -200, 'max' => 200 ) ), 'selectors' => array( $box => '--galaxie-wl-pop-x: {{SIZE}}{{UNIT}};' ) ) );

		$this->end_controls_section();

		$this->start_controls_section( 'popover_title_style', array( 'label' => __( 'Popover: title', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => $shown ) );
		PixfortControls::text( $this, 'popover_title', array( 'bold' => 'font-weight-bold' ), array(), '{{WRAPPER}} .galaxie-wishlist-popover-title', 'text', array( 'position', 'inline' ) );
		$this->end_controls_section();

		$rows  = '{{WRAPPER}} .galaxie-wishlist-popover-lists';
		$label = $rows . ' label';

		$this->start_controls_section( 'popover_rows_style', array( 'label' => __( 'Popover: lists', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => $shown ) );
		PixfortControls::text( $this, 'popover_row', array( 'bold' => '' ), array(), $label, 'text', array( 'position', 'inline' ) );

		$this->add_responsive_control( 'popover_row_gap', array( 'label' => __( 'Space between rows', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 30 ) ), 'separator' => 'before', 'selectors' => array( $rows => 'gap: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'popover_row_padding', array( 'label' => __( 'Row padding', 'galaxie-woo' ), 'type' => Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em' ), 'selectors' => array( $label => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'popover_row_radius', array( 'label' => __( 'Row corner radius', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 20 ) ), 'selectors' => array( $label => 'border-radius: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'popover_checkbox_size', array( 'label' => __( 'Checkbox size', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 10, 'max' => 32 ) ), 'selectors' => array( $rows . ' input[type="checkbox"]' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}; flex: 0 0 auto;' ) ) );
		// The browser draws the tick; accent-color is the one colour it takes.
		PixfortControls::palette_control( $this, 'popover_checkbox_color', __( 'Checked color', 'galaxie-woo' ), $rows . ' input[type="checkbox"]', 'accent-color' );

		$this->add_control( 'popover_row_hover_heading', array( 'label' => __( 'Hover', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $this, 'popover_row_hover_color', __( 'Hover text color', 'galaxie-woo' ), $label . ':hover', 'color' );
		PixfortControls::palette_control( $this, 'popover_row_hover_bg', __( 'Hover background', 'galaxie-woo' ), $label . ':hover', 'background-color' );
		$this->end_controls_section();

		$field = '{{WRAPPER}} .galaxie-wishlist-popover-new input';

		$this->start_controls_section( 'popover_field_style', array( 'label' => __( 'Popover: new list field', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => $shown ) );
		PixfortControls::palette_control( $this, 'popover_field_bg', __( 'Background', 'galaxie-woo' ), $field, 'background-color' );
		PixfortControls::palette_control( $this, 'popover_field_color', __( 'Text color', 'galaxie-woo' ), $field, 'color' );
		PixfortControls::palette_control( $this, 'popover_field_placeholder', __( 'Placeholder color', 'galaxie-woo' ), $field . '::placeholder', 'color' );
		PixfortControls::palette_control( $this, 'popover_field_border', __( 'Border color', 'galaxie-woo' ), $field, 'border-color', array(), ' border-style: solid;' );
		$this->add_responsive_control( 'popover_field_border_width', array( 'label' => __( 'Border width', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 8 ) ), 'selectors' => array( $field => 'border-width: {{SIZE}}{{UNIT}}; border-style: solid;' ) ) );
		$this->add_responsive_control( 'popover_field_radius', array( 'label' => __( 'Border radius', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 40 ) ), 'selectors' => array( $field => 'border-radius: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'popover_field_padding', array( 'label' => __( 'Padding', 'galaxie-woo' ), 'type' => Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em' ), 'selectors' => array( $field => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'popover_field_height', array( 'label' => __( 'Height', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 24, 'max' => 80 ) ), 'selectors' => array( $field => 'height: {{SIZE}}{{UNIT}};' ) ) );
		$this->end_controls_section();

		$form = '{{WRAPPER}} .galaxie-wishlist-popover-new';

		$this->start_controls_section( 'popover_create_style', array( 'label' => __( 'Popover: create list button', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => $shown ) );

		$this->add_responsive_control(
			'popover_form_layout',
			array(
				'label'                => __( 'Field and button', 'galaxie-woo' ),
				'type'                 => Controls_Manager::SELECT,
				'options'              => array(
					''        => __( 'Default (side by side)', 'galaxie-woo' ),
					'inline'  => __( 'Side by side', 'galaxie-woo' ),
					'stacked' => __( 'Stacked', 'galaxie-woo' ),
				),
				'selectors_dictionary' => array(
					'inline'  => 'flex-direction: row; align-items: center;',
					'stacked' => 'flex-direction: column; align-items: stretch;',
				),
				'selectors'            => array( $form => '{{VALUE}}' ),
			)
		);

		$this->add_responsive_control( 'popover_form_gap', array( 'label' => __( 'Space between field and button', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 30 ) ), 'selectors' => array( $form => 'gap: {{SIZE}}{{UNIT}};' ) ) );

		$this->add_control( 'popover_create_heading', array( 'label' => __( 'Button', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );

		// The label is "Create button text" in the content section.
		PixfortControls::button( $this, 'popover_create', array( 'size' => 'sm' ), array(), '{{WRAPPER}}', array( 'text' ) );

		$this->end_controls_section();
	}

	/**
	 * Full pixfort Button parity for one state, mirroring
	 * {@see \Galaxie\Woo\Modules\VariationSwatches\Widget\VariationBadgesWidget::register_button_controls()}.
	 *
	 * Control ids are prefixed so the two states can coexist — the same reason
	 * pixfort's own shared `pix_get_elementor_btn()` helper can't be reused here
	 * (it hardcodes unprefixed ids, so it only works once per widget).
	 */
	private function register_button_controls( string $prefix, string $section_id, string $label, string $default_text, string $default_color, string $default_style ): void {
		$this->start_controls_section(
			$section_id,
			array( 'label' => $label )
		);

		$this->add_control(
			$prefix . '_text',
			array(
				'label'   => __( 'Text', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXT,
				'default' => $default_text,
			)
		);

		// A heart alone is the usual wishlist control on a product card. The text
		// stays as the button's accessible name, so a screen reader still says it.
		$this->add_control(
			$prefix . '_icon_only',
			array(
				'label'        => __( 'Icon only', 'galaxie-woo' ),
				'description'  => __( 'Hides the text and keeps it as the label screen readers announce. Pick an icon below.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		if ( $this->pixfort_active() ) {
			$this->add_control(
				$prefix . '_icon',
				array(
					'label'   => __( 'Icon', 'galaxie-woo' ),
					'type'    => \Elementor\CustomControl\PixfortIconSelector_Control::PixfortIconSelector,
					'default' => '',
				)
			);
			$this->add_control(
				$prefix . '_icon_position',
				array(
					'label'     => __( 'Icon position', 'galaxie-woo' ),
					'type'      => Controls_Manager::SELECT,
					'options'   => array(
						''      => __( 'Before text', 'galaxie-woo' ),
						'after' => __( 'After text', 'galaxie-woo' ),
					),
					'default'   => '',
					'condition' => array( $prefix . '_icon!' => '' ),
				)
			);
			$this->add_control(
				$prefix . '_style',
				array(
					'label'   => __( 'Button style', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'options' => array(
						''          => __( 'Default', 'galaxie-woo' ),
						'flat'      => __( 'Flat', 'galaxie-woo' ),
						'line'      => __( 'Line', 'galaxie-woo' ),
						'outline'   => __( 'Outline', 'galaxie-woo' ),
						'underline' => __( 'Underline', 'galaxie-woo' ),
						'link'      => __( 'Link', 'galaxie-woo' ),
						'blink'     => __( 'Blink', 'galaxie-woo' ),
					),
					'default' => $default_style,
				)
			);
			$this->add_control(
				$prefix . '_color',
				array(
					'label'   => __( 'Button color', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'groups'  => \PixfortCore::instance()->coreFunctions->getColorsArray( array( 'defaultColors' => false, 'mainLight' => true, 'custom' => false ) ),
					'default' => $default_color,
				)
			);
			$this->add_control(
				$prefix . '_text_color',
				array(
					'label'   => __( 'Text color', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'groups'  => \PixfortCore::instance()->coreFunctions->getColorsArray( array( 'defaultValue' => array( '' => __( 'Default', 'galaxie-woo' ) ), 'mainLight' => true ) ),
					'default' => '',
				)
			);
		} else {
			$this->add_control(
				$prefix . '_fallback_color',
				array(
					'label'     => __( 'Button color', 'galaxie-woo' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .galaxie-wishlist-' . $prefix . ' .wishlist-btn' => 'background-color: {{VALUE}};' ),
				)
			);
		}

		$this->add_control(
			$prefix . '_size',
			array(
				'label'   => __( 'Button size', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'sm'     => __( 'Small', 'galaxie-woo' ),
					'normal' => __( 'Normal', 'galaxie-woo' ),
					'md'     => __( 'Medium', 'galaxie-woo' ),
					'lg'     => __( 'Large', 'galaxie-woo' ),
					'xl'     => __( 'X-Large', 'galaxie-woo' ),
				),
				'default' => 'md',
			)
		);
		$this->add_control(
			$prefix . '_rounded',
			array(
				'label'        => __( 'Rounded corners', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'btn-rounded',
				'default'      => '',
			)
		);
		$this->add_control(
			$prefix . '_full',
			array(
				'label'        => __( 'Full width', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		$product = $this->current_product();

		if ( ! $product ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div style="padding:2rem;text-align:center;border:1px dashed #ccc;border-radius:8px;">';
				esc_html_e( 'Galaxie Wishlist Button — place inside a single product template, or anywhere a product is in context.', 'galaxie-woo' );
				echo '</div>';
			}
			return;
		}

		$settings    = $this->get_settings_for_display();
		$in_wishlist = Module::is_in_wishlist( $product->get_id() );
		$shape       = (string) ( $settings['heart_shape'] ?? '' );

		// One row for both buttons, so the layout section has something to space.
		echo '<div class="galaxie-wishlist-buttons">';

		printf(
			'<button type="button" class="galaxie-wishlist-btn%1$s%7$s" data-product-id="%2$s" aria-pressed="%3$s" aria-label="%4$s" data-label-add="%5$s" data-label-saved="%6$s">',
			$in_wishlist ? ' is-in-wishlist' : '',
			esc_attr( (string) $product->get_id() ),
			$in_wishlist ? 'true' : 'false',
			esc_attr( (string) ( $settings[ $in_wishlist ? 'saved_text' : 'add_text' ] ?? '' ) ),
			esc_attr( (string) ( $settings['add_text'] ?? '' ) ),
			esc_attr( (string) ( $settings['saved_text'] ?? '' ) ),
			in_array( $shape, array( 'circle', 'square' ), true ) ? ' galaxie-wishlist-shape-' . esc_attr( $shape ) : ''
		);
		// Both states ship in the markup; CSS shows exactly one. See class docblock.
		echo '<span class="galaxie-wishlist-add">' . $this->render_button( 'add', $settings ) . '</span>'; // phpcs:ignore -- pixfort's own component markup.
		echo '<span class="galaxie-wishlist-saved">' . $this->render_button( 'saved', $settings ) . '</span>'; // phpcs:ignore -- pixfort's own component markup.
		echo '</button>';

		if ( 'yes' === ( $settings['list_enabled'] ?? '' ) ) {
			// The popover opens inside this anchor rather than at the end of <body>:
			// that is what lets the Style tab's {{WRAPPER}} rules reach it, and what
			// positions it against the button. The data-* texts stay for a cached
			// page whose markup predates the template.
			printf(
				'<div class="galaxie-wishlist-list-anchor"><button type="button" class="galaxie-account-submit galaxie-wishlist-list-btn" data-product-id="%1$s" data-title="%2$s" data-placeholder="%3$s" data-create="%4$s" aria-haspopup="dialog">%5$s</button><template class="galaxie-wishlist-popover-template">%6$s</template>%7$s</div>',
				esc_attr( (string) $product->get_id() ),
				esc_attr( (string) ( $settings['list_title'] ?? '' ) ),
				esc_attr( (string) ( $settings['list_placeholder'] ?? '' ) ),
				esc_attr( (string) ( $settings['list_create'] ?? '' ) ),
				PixfortControls::render_button( $settings, 'list', (string) ( $settings['list_text'] ?? '' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own component markup.
				$this->popover( $settings, array() ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				AccountParts::editing() && 'yes' === ( $settings['list_popover_preview'] ?? '' ) ? $this->popover( $settings, $this->preview_rows( $product ), true ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			);
		}

		echo '</div>';
	}

	/**
	 * The "Salvar em" popover. Printed empty inside a <template> for the script
	 * to clone and fill, and filled with sample rows for the editor preview.
	 * Classes the Style tab chooses are baked in here, so the script never has
	 * to know pixfort's vocabulary.
	 *
	 * @param array<string,mixed>                     $s
	 * @param array<int,array{name:string,has:bool}> $rows
	 */
	private function popover( array $s, array $rows, bool $preview = false ): string {
		$row_class = PixfortControls::text_classes( $s, 'popover_row' );
		$title     = (string) ( $s['list_title'] ?? '' );
		$items     = '';

		foreach ( $rows as $row ) {
			$items .= sprintf(
				'<li><label class="%1$s"><input type="checkbox"%2$s /><span>%3$s</span></label></li>',
				esc_attr( $row_class ),
				$row['has'] ? ' checked' : '',
				esc_html( $row['name'] )
			);
		}

		return sprintf(
			'<div class="galaxie-wishlist-popover %1$s" role="dialog" aria-label="%2$s"><div class="galaxie-wishlist-popover-title %3$s">%4$s</div><ul class="galaxie-wishlist-popover-lists" data-row-class="%5$s">%6$s</ul><form class="galaxie-wishlist-popover-new"><input type="text" class="form-control" maxlength="60" placeholder="%7$s" /><button type="submit" class="galaxie-account-submit galaxie-wishlist-popover-create">%8$s</button></form></div>',
			esc_attr( trim( PixfortControls::surface_classes( $s, 'popover_box' ) . ( $preview ? ' is-preview' : '' ) ) ),
			esc_attr( $title ),
			esc_attr( PixfortControls::text_classes( $s, 'popover_title' ) ),
			esc_html( $title ),
			esc_attr( $row_class ),
			$items,
			esc_attr( (string) ( $s['list_placeholder'] ?? '' ) ),
			PixfortControls::render_button( $s, 'popover_create', (string) ( $s['list_create'] ?? '' ) )
		);
	}

	/**
	 * Rows for the editor preview: the editing user's own lists when there are
	 * any, two samples otherwise.
	 *
	 * @return array<int,array{name:string,has:bool}>
	 */
	private function preview_rows( \WC_Product $product ): array {
		$rows = array();

		foreach ( Lists::all( get_current_user_id() ) as $list ) {
			$rows[] = array(
				'name' => (string) $list['name'],
				'has'  => in_array( $product->get_id(), (array) $list['items'], true ),
			);
		}

		return $rows ? $rows : array(
			array( 'name' => __( 'Favoritos', 'galaxie-woo' ), 'has' => true ),
			array( 'name' => __( 'Presentes', 'galaxie-woo' ), 'has' => false ),
		);
	}

	/** @param array<string,mixed> $settings */
	private function render_button( string $prefix, array $settings ): string {
		$icon_only = 'yes' === ( $settings[ $prefix . '_icon_only' ] ?? '' ) && '' !== (string) ( $settings[ $prefix . '_icon' ] ?? '' );
		// The label moves to the <button>'s aria-label; pixfort draws no text.
		$text = $icon_only ? '' : (string) ( $settings[ $prefix . '_text' ] ?? '' );

		if ( $this->pixfort_active() ) {
			// PixButton prints the text and these class values unescaped (the text
			// through do_shortcode), so they are escaped here, as
			// PixfortControls::button_attr() does for every other button.
			$attr = array(
				'is_elementor'      => 'true',
				'btn_extra_classes' => $icon_only ? 'galaxie-wishlist-icon-only' : '',
				'btn_text'          => esc_html( $text ),
				'btn_link'          => '', // Empty on purpose: renders a <span>, not an <a> — our own wrapping <button> handles the click.
				'btn_icon'          => $settings[ $prefix . '_icon' ] ?? '',
				'btn_icon_position' => PixfortControls::class_list( $settings[ $prefix . '_icon_position' ] ?? '' ),
				'btn_style'         => PixfortControls::class_list( $settings[ $prefix . '_style' ] ?? '' ),
				'btn_color'         => PixfortControls::class_list( $settings[ $prefix . '_color' ] ?? 'primary' ),
				'btn_text_color'    => PixfortControls::class_list( $settings[ $prefix . '_text_color' ] ?? '' ),
				'btn_size'          => PixfortControls::class_list( $settings[ $prefix . '_size' ] ?? 'md' ),
				'btn_rounded'       => PixfortControls::class_list( $settings[ $prefix . '_rounded' ] ?? '' ),
				'btn_full'          => PixfortControls::class_list( $settings[ $prefix . '_full' ] ?? '' ),
			);
			return \PixfortCore::instance()->elementsManager->renderElement( 'Button', $attr );
		}

		return '<span class="wishlist-btn">' . esc_html( $text ) . '</span>';
	}

	/** Current product on a real single-product page, or Elementor's preview post. */
	private function current_product(): ?\WC_Product {
		global $product;

		if ( $product instanceof \WC_Product ) {
			return $product;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return null;
		}

		$candidate = wc_get_product( $post_id );
		return $candidate instanceof \WC_Product ? $candidate : null;
	}
}

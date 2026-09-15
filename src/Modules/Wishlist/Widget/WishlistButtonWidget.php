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
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * One wishlist button for the current product, drawn by pixfort's own Button
 * component — same approach (and the same `btn_*` attr mapping) as
 * {@see \Galaxie\Woo\Modules\VariationSwatches\Widget\VariationBadgesWidget},
 * so it inherits pixfort's global color dropdown (Primary, Gray 1-9, Dynamic
 * Colors — which is what makes it follow the theme's light/dark switch) and
 * pixfort's own icon picker, instead of a separate hardcoded palette.
 *
 * A tap opens the "Salvar em" popover: every list of the shopper with a
 * checkbox, the default one first and marked, and a field to create another.
 * The button shows its saved state while the product is on any list.
 *
 * BOTH states are fully customizable and BOTH are rendered server-side, one
 * hidden by CSS — exactly the pattern already used for "Badge"/"Badge —
 * selecionado" in the variation widget. A saved button usually wants a
 * different icon AND a different color from the empty one, and a single
 * rendered button would leave JS rewriting pixfort's markup by hand (fragile,
 * and impossible to restyle from Elementor). Switching state is therefore a
 * one-line class flip in JS — see globals/wishlist.ts.
 *
 * One control per property. The button's colours, border and hover live in
 * its two Content sections, next to pixfort's own Button controls; the Style
 * tab only adds what those cannot do — a fixed Circle or Rounded square — and
 * hides the Content controls a shape overrides. Some control ids keep an older
 * `heart_` prefix because saved widgets store them.
 */
final class WishlistButtonWidget extends Widget_Base {

	/** Shapes the Style tab offers besides pixfort's own button. */
	private const SHAPES = array( 'circle', 'square' );

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
			__( 'Wishlist button', 'galaxie-woo' ),
			__( 'Adicionar aos favoritos', 'galaxie-woo' ),
			'primary',
			'outline'
		);
		$this->register_button_controls(
			'saved',
			'saved_section',
			__( 'Wishlist button — saved', 'galaxie-woo' ),
			__( 'Remover dos favoritos', 'galaxie-woo' ),
			'primary',
			'flat'
		);

		// Every word the popover shows, and the switch that keeps it open while
		// they are edited. The ids predate this section; saved values carry over.
		$this->start_controls_section( 'popover_section', array( 'label' => __( 'Wishlist popover', 'galaxie-woo' ) ) );

		$this->add_control(
			'list_popover_preview',
			array(
				'label'        => __( 'Show the popover in the editor', 'galaxie-woo' ),
				'description'  => __( 'Keeps the popover open under the button while you edit its texts and styles, with your own lists or two samples when you have none. Customers only see it after tapping the button.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
			)
		);

		$this->add_control( 'list_title', array( 'label' => __( 'Title', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Salvar em', 'galaxie-woo' ), 'separator' => 'before' ) );
		$this->add_control( 'list_default_badge', array( 'label' => __( 'Default list badge', 'galaxie-woo' ), 'description' => __( 'Beside the list the account page marks as default. Empty hides it.', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Padrão', 'galaxie-woo' ) ) );
		$this->add_control( 'list_placeholder', array( 'label' => __( 'New list placeholder', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Nome da nova lista', 'galaxie-woo' ) ) );
		$this->add_control( 'list_create', array( 'label' => __( 'Create button text', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Criar lista', 'galaxie-woo' ) ) );

		$this->end_controls_section();

		// Style, in the order the screen reads.
		$this->register_layout_style();
		$this->register_shape_style();
		$this->register_popover_style();
	}

	/**
	 * Full pixfort Button parity for one state, mirroring
	 * {@see \Galaxie\Woo\Modules\VariationSwatches\Widget\VariationBadgesWidget::register_button_controls()}.
	 *
	 * Control ids are prefixed so the two states can coexist — the same reason
	 * pixfort's own shared `pix_get_elementor_btn()` helper can't be reused here
	 * (it hardcodes unprefixed ids, so it only works once per widget).
	 *
	 * A Circle or Rounded square picked in the Style tab owns the size, corners,
	 * width and the icon-only look, so the controls for those hide while one is
	 * picked, and render() ignores what they stored.
	 */
	private function register_button_controls( string $prefix, string $section_id, string $label, string $default_text, string $default_color, string $default_style ): void {
		$flat    = array( 'heart_shape' => '' );
		$worded  = array( 'heart_shape' => '', $prefix . '_icon_only' => '' );
		$icon    = '{{WRAPPER}} .galaxie-wishlist-' . $prefix . ' .pixfort-icon';
		$hovered = static fn( string $tail ): string => '{{WRAPPER}} .galaxie-wishlist-btn:hover .galaxie-wishlist-' . $prefix . ' ' . $tail . ', {{WRAPPER}} .galaxie-wishlist-btn:focus-visible .galaxie-wishlist-' . $prefix . ' ' . $tail;

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

		// An icon alone is the usual wishlist control on a product card. The text
		// stays as the button's accessible name, so a screen reader still says it.
		$this->add_control(
			$prefix . '_icon_only',
			array(
				'label'        => __( 'Icon only', 'galaxie-woo' ),
				'description'  => __( 'Hides the text and keeps it as the label screen readers announce. Pick an icon below.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'condition'    => $flat,
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
					'condition' => array_merge( $worded, array( $prefix . '_icon!' => '' ) ),
				)
			);
			$this->add_control(
				$prefix . '_style',
				array(
					'label'       => __( 'Button style', 'galaxie-woo' ),
					'description' => __( 'Outline and Line draw the border, in the button color.', 'galaxie-woo' ),
					'type'        => Controls_Manager::SELECT,
					'options'     => array(
						''          => __( 'Default', 'galaxie-woo' ),
						'flat'      => __( 'Flat', 'galaxie-woo' ),
						'line'      => __( 'Line', 'galaxie-woo' ),
						'outline'   => __( 'Outline', 'galaxie-woo' ),
						'underline' => __( 'Underline', 'galaxie-woo' ),
						'link'      => __( 'Link', 'galaxie-woo' ),
						'blink'     => __( 'Blink', 'galaxie-woo' ),
					),
					'default'     => $default_style,
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
			// pixfort paints its icons in currentColor, so on an icon-only button
			// this would be a second icon colour. Icon color below is the one.
			$this->add_control(
				$prefix . '_text_color',
				array(
					'label'     => __( 'Text color', 'galaxie-woo' ),
					'type'      => Controls_Manager::SELECT,
					'groups'    => \PixfortCore::instance()->coreFunctions->getColorsArray( array( 'defaultValue' => array( '' => __( 'Default', 'galaxie-woo' ) ), 'mainLight' => true ) ),
					'default'   => '',
					'condition' => $worded,
				)
			);
			PixfortControls::icon_color( $this, $prefix . '_icon_color', __( 'Icon color', 'galaxie-woo' ), $icon, array( $prefix . '_icon!' => '' ) );
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
				'label'     => __( 'Button size', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'sm'     => __( 'Small', 'galaxie-woo' ),
					'normal' => __( 'Normal', 'galaxie-woo' ),
					'md'     => __( 'Medium', 'galaxie-woo' ),
					'lg'     => __( 'Large', 'galaxie-woo' ),
					'xl'     => __( 'X-Large', 'galaxie-woo' ),
				),
				'default'   => 'md',
				'condition' => $flat,
			)
		);
		$this->add_control(
			$prefix . '_rounded',
			array(
				'label'        => __( 'Rounded corners', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'btn-rounded',
				'default'      => '',
				'condition'    => $flat,
			)
		);
		$this->add_control(
			$prefix . '_full',
			array(
				'label'        => __( 'Full width', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'condition'    => $flat,
			)
		);

		// pixfort's Button has no hover colours; these are the ones every other
		// Galaxie button carries, written for this state only. The border colour
		// replaces the one Outline and Line already draw — it never adds a border
		// of its own, which is what painted two of them.
		$this->add_control( $prefix . '_hover_heading', array( 'label' => __( 'Hover', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $this, $prefix . '_hover_bg', __( 'Hover background', 'galaxie-woo' ), $hovered( '.btn' ), 'background-color' );
		PixfortControls::palette_control( $this, $prefix . '_hover_color', __( 'Hover text color', 'galaxie-woo' ), $hovered( '.btn' ), 'color', $worded );
		PixfortControls::palette_control( $this, $prefix . '_hover_border', __( 'Hover border color', 'galaxie-woo' ), $hovered( '.btn' ), 'border-color', array( $prefix . '_style' => array( 'outline', 'line' ) ) );
		PixfortControls::icon_color( $this, $prefix . '_hover_icon', __( 'Hover icon color', 'galaxie-woo' ), $hovered( '.pixfort-icon' ), array( $prefix . '_icon!' => '' ) );

		$this->end_controls_section();
	}

	/** Where the button sits in its column. */
	private function register_layout_style(): void {
		$row = '{{WRAPPER}} .galaxie-wishlist-buttons';

		$this->start_controls_section( 'layout_style', array( 'label' => __( 'Wishlist button: layout', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		// Filling the row is the Full width switch in Content, per state.
		$this->add_responsive_control(
			'buttons_align',
			array(
				'label'                => __( 'Alignment', 'galaxie-woo' ),
				'type'                 => Controls_Manager::CHOOSE,
				'toggle'               => true,
				'options'              => array(
					'left'   => array( 'title' => __( 'Left', 'galaxie-woo' ), 'icon' => 'eicon-text-align-left' ),
					'center' => array( 'title' => __( 'Center', 'galaxie-woo' ), 'icon' => 'eicon-text-align-center' ),
					'right'  => array( 'title' => __( 'Right', 'galaxie-woo' ), 'icon' => 'eicon-text-align-right' ),
				),
				'selectors_dictionary' => array(
					'left'   => 'justify-content: flex-start;',
					'center' => 'justify-content: center;',
					'right'  => 'justify-content: flex-end;',
				),
				'selectors'            => array( $row => '{{VALUE}}' ),
			)
		);

		foreach ( array( 'padding' => __( 'Padding', 'galaxie-woo' ), 'margin' => __( 'Margin', 'galaxie-woo' ) ) as $property => $label ) {
			$this->add_responsive_control(
				'buttons_' . $property,
				array(
					'label'      => $label,
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', 'rem', 'em', '%' ),
					'selectors'  => array( $row => $property . ': {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
				)
			);
		}

		$this->end_controls_section();
	}

	/**
	 * What pixfort's Button cannot do: one size for width and height, corners
	 * that make a circle, the icon centred at a chosen size. Default writes
	 * nothing, so a widget saved before this section existed looks as it did.
	 * Colours stay in the two Content sections.
	 */
	private function register_shape_style(): void {
		$this->start_controls_section( 'heart_style', array( 'label' => __( 'Wishlist button: shape', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		$shaped = array( 'heart_shape!' => '' );

		$this->add_control(
			'heart_shape',
			array(
				'label'       => __( 'Shape', 'galaxie-woo' ),
				'description' => __( 'Circle and Rounded square show the icon only, in both states, and take over the button size, corners and full width set in Content. Colors and borders stay in Content.', 'galaxie-woo' ),
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
		// Without a shape pixfort's Button size already scales the icon with the text.
		$this->add_responsive_control( 'heart_icon_size', array( 'label' => __( 'Icon size', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px', 'em' ), 'range' => array( 'px' => array( 'min' => 8, 'max' => 80 ) ), 'selectors' => array( '{{WRAPPER}} .galaxie-wishlist-btn .pixfort-icon' => 'width: {{SIZE}}{{UNIT}} !important; height: {{SIZE}}{{UNIT}} !important;' ), 'condition' => $shaped ) );

		$this->end_controls_section();
	}

	private function register_popover_style(): void {
		$box = '{{WRAPPER}} .galaxie-wishlist-popover';

		$this->start_controls_section( 'popover_box_style', array( 'label' => __( 'Wishlist popover: box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		// Custom with an empty slider is the stylesheet's 12px corner, so None can
		// mean square; the Large shadow is pixfort's nearest to the old one.
		PixfortControls::surface( $this, 'popover_box', $box, array( 'rounded' => 'custom', 'shadow' => '3' ) );

		$this->add_responsive_control( 'popover_width', array( 'label' => __( 'Width', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px', 'vw' ), 'range' => array( 'px' => array( 'min' => 160, 'max' => 600 ) ), 'separator' => 'before', 'selectors' => array( $box => 'width: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'popover_gap', array( 'label' => __( 'Space between parts', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 40 ) ), 'selectors' => array( $box => 'gap: {{SIZE}}{{UNIT}};' ) ) );

		$this->add_control( 'popover_position_heading', array( 'label' => __( 'Position', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );

		// The popover opens at the end of the page, where a product card cannot
		// clip it, so the script places it against the button. These controls
		// only hand it variables to read. A new render in the editor repositions
		// the preview, which reading CSS alone would not do.
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
					'below' => '--galaxie-wl-pop-place: below;',
					'above' => '--galaxie-wl-pop-place: above;',
				),
				'selectors'            => array( $box => '{{VALUE}}' ),
				'render_type'          => 'template',
			)
		);

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
					'left'   => '--galaxie-wl-pop-align: left;',
					'center' => '--galaxie-wl-pop-align: center;',
					'right'  => '--galaxie-wl-pop-align: right;',
				),
				'selectors'            => array( $box => '{{VALUE}}' ),
				'render_type'          => 'template',
			)
		);

		$this->add_responsive_control( 'popover_offset_y', array( 'label' => __( 'Distance from the button', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 60 ) ), 'selectors' => array( $box => '--galaxie-wl-pop-y: {{SIZE}};' ), 'render_type' => 'template' ) );
		$this->add_responsive_control( 'popover_offset_x', array( 'label' => __( 'Horizontal offset', 'galaxie-woo' ), 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => -200, 'max' => 200 ) ), 'selectors' => array( $box => '--galaxie-wl-pop-x: {{SIZE}};' ), 'render_type' => 'template' ) );

		$this->end_controls_section();

		$this->start_controls_section( 'popover_title_style', array( 'label' => __( 'Wishlist popover: title', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $this, 'popover_title', array( 'bold' => 'font-weight-bold' ), array(), '{{WRAPPER}} .galaxie-wishlist-popover-title', 'text', array( 'position', 'inline' ) );
		$this->end_controls_section();

		$rows  = '{{WRAPPER}} .galaxie-wishlist-popover-lists';
		$label = $rows . ' label';
		$badge = '{{WRAPPER}} .galaxie-wishlist-popover-badge';

		$this->start_controls_section( 'popover_rows_style', array( 'label' => __( 'Wishlist popover: lists', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
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

		$this->add_control( 'popover_badge_heading', array( 'label' => __( 'Default list badge', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before', 'condition' => array( 'list_default_badge!' => '' ) ) );
		PixfortControls::text( $this, 'popover_badge_text', array( 'size' => 'text-xs', 'bold' => 'font-weight-bold' ), array( 'list_default_badge!' => '' ), $badge, 'text', array( 'position', 'inline' ) );
		PixfortControls::surface( $this, 'popover_badge', $badge, array( 'rounded' => 'badge-pill', 'radius_set' => 'badge' ), array( 'list_default_badge!' => '' ) );
		$this->end_controls_section();

		$field = '{{WRAPPER}} .galaxie-wishlist-popover-new input';

		$this->start_controls_section( 'popover_field_style', array( 'label' => __( 'Wishlist popover: new list field', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
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

		$this->start_controls_section( 'popover_create_style', array( 'label' => __( 'Wishlist popover: create list button', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

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

		// Its text is "Create button text" in Content → Wishlist popover.
		PixfortControls::button( $this, 'popover_create', array( 'size' => 'sm' ), array(), '{{WRAPPER}}', array( 'text' ) );

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

		$settings = $this->legacy_settings( $this->get_settings_for_display() );
		$user_id  = get_current_user_id();
		// Saved means on any list, not only the default one: the popover is where
		// a product goes, and whichever list took it, the button should say so.
		$saved = $user_id && Lists::contains( $user_id, (int) $product->get_id() );
		$shape = (string) ( $settings['heart_shape'] ?? '' );

		echo $this->legacy_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from sanitized slugs and colours.

		// The row places the button; the anchor holds what the popover is built
		// from. The script opens the popover at the end of the page — see
		// globals/wishlist.ts for why, and for how the Style tab still reaches it.
		printf(
			'<div class="galaxie-wishlist-buttons"><div class="galaxie-wishlist-anchor"><button type="button" class="galaxie-wishlist-btn%1$s%2$s" data-product-id="%3$s" aria-haspopup="dialog" aria-expanded="false" aria-label="%4$s" data-label-add="%5$s" data-label-saved="%6$s">',
			$saved ? ' is-in-wishlist' : '',
			in_array( $shape, self::SHAPES, true ) ? ' galaxie-wishlist-shape-' . esc_attr( $shape ) : '',
			esc_attr( (string) $product->get_id() ),
			esc_attr( (string) ( $settings[ $saved ? 'saved_text' : 'add_text' ] ?? '' ) ),
			esc_attr( (string) ( $settings['add_text'] ?? '' ) ),
			esc_attr( (string) ( $settings['saved_text'] ?? '' ) )
		);
		// Both states ship in the markup; CSS shows exactly one. See class docblock.
		echo '<span class="galaxie-wishlist-add">' . $this->render_button( 'add', $settings ) . '</span>'; // phpcs:ignore -- pixfort's own component markup.
		echo '<span class="galaxie-wishlist-saved">' . $this->render_button( 'saved', $settings ) . '</span>'; // phpcs:ignore -- pixfort's own component markup.
		echo '</button>';

		printf(
			'<template class="galaxie-wishlist-popover-template">%1$s</template>%2$s</div></div>',
			$this->popover( $settings, array() ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			AccountParts::editing() && 'yes' === ( $settings['list_popover_preview'] ?? '' ) ? $this->popover( $settings, $this->preview_rows( $product ), true ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
	}

	/**
	 * The "Salvar em" popover. Printed empty inside a <template> for the script
	 * to clone and fill, and filled with sample rows for the editor preview —
	 * one function for both, so the preview is the popover the site opens. The
	 * script draws rows with the same markup, from the classes and badge text
	 * the list carries in data attributes.
	 *
	 * @param array<string,mixed>                                  $s
	 * @param array<int,array{name:string,has:bool,default:bool}> $rows
	 */
	private function popover( array $s, array $rows, bool $preview = false ): string {
		$row_class   = PixfortControls::text_classes( $s, 'popover_row' );
		$badge_class = trim( PixfortControls::text_classes( $s, 'popover_badge_text' ) . ' ' . PixfortControls::surface_classes( $s, 'popover_badge' ) );
		$badge_text  = trim( (string) ( $s['list_default_badge'] ?? '' ) );
		$title       = (string) ( $s['list_title'] ?? '' );
		$items       = '';

		foreach ( $rows as $row ) {
			$items .= sprintf(
				'<li><label class="%1$s"><input type="checkbox"%2$s /><span class="galaxie-wishlist-popover-name">%3$s</span>%4$s</label></li>',
				esc_attr( $row_class ),
				$row['has'] ? ' checked' : '',
				esc_html( $row['name'] ),
				$row['default'] && '' !== $badge_text ? sprintf( '<span class="galaxie-wishlist-popover-badge %1$s">%2$s</span>', esc_attr( $badge_class ), esc_html( $badge_text ) ) : ''
			);
		}

		$box = PixfortControls::surface_classes( $s, 'popover_box' );

		// surface() calls the empty choice None; the stylesheet's 12px corner is
		// what Custom with no slider shows, so None has to square it off.
		if ( '' === (string) ( $s['popover_box_rounded'] ?? 'custom' ) ) {
			$box .= ' rounded-0';
		}

		return sprintf(
			'<div class="galaxie-wishlist-popover %1$s" role="dialog" aria-label="%2$s"><div class="galaxie-wishlist-popover-title %3$s">%4$s</div><ul class="galaxie-wishlist-popover-lists" data-row-class="%5$s" data-badge-class="%6$s" data-badge-text="%7$s">%8$s</ul><form class="galaxie-wishlist-popover-new"><input type="text" class="form-control" maxlength="60" placeholder="%9$s" aria-label="%9$s" /><button type="submit" class="galaxie-account-submit galaxie-wishlist-popover-create">%10$s</button></form></div>',
			esc_attr( trim( $box . ( $preview ? ' is-preview' : '' ) ) ),
			esc_attr( $title ),
			esc_attr( PixfortControls::text_classes( $s, 'popover_title' ) ),
			esc_html( $title ),
			esc_attr( $row_class ),
			esc_attr( $badge_class ),
			esc_attr( $badge_text ),
			$items,
			esc_attr( (string) ( $s['list_placeholder'] ?? '' ) ),
			PixfortControls::render_button( $s, 'popover_create', (string) ( $s['list_create'] ?? '' ) )
		);
	}

	/**
	 * Rows for the editor preview: the editing user's own lists when there are
	 * any, and otherwise what a first-time shopper sees — the default list,
	 * ready to tick — plus a second sample so the rows can be styled.
	 *
	 * @return array<int,array{name:string,has:bool,default:bool}>
	 */
	private function preview_rows( \WC_Product $product ): array {
		$rows = array();

		foreach ( Lists::peek( get_current_user_id() ) as $list ) {
			$rows[] = array(
				'name'    => (string) $list['name'],
				'has'     => in_array( (int) $product->get_id(), (array) $list['items'], true ),
				'default' => ! empty( $list['default'] ),
			);
		}

		return $rows ? $rows : array(
			array( 'name' => __( 'Favoritos', 'galaxie-woo' ), 'has' => false, 'default' => true ),
			array( 'name' => __( 'Presentes', 'galaxie-woo' ), 'has' => true, 'default' => false ),
		);
	}

	/** @param array<string,mixed> $settings */
	private function render_button( string $prefix, array $settings ): string {
		$shaped = in_array( (string) ( $settings['heart_shape'] ?? '' ), self::SHAPES, true );
		$has    = '' !== (string) ( $settings[ $prefix . '_icon' ] ?? '' );
		// A shape is a round icon button: it shows the icon alone whatever the
		// switch, which the shape hides. Without an icon the text still shows.
		$icon_only = $has && ( $shaped || 'yes' === ( $settings[ $prefix . '_icon_only' ] ?? '' ) );
		// The label moves to the <button>'s aria-label; pixfort draws no text.
		$text = $icon_only ? '' : (string) ( $settings[ $prefix . '_text' ] ?? '' );

		if ( $this->pixfort_active() ) {
			// PixButton prints the text and these class values unescaped (the text
			// through do_shortcode), so they are escaped here, as
			// PixfortControls::button_attr() does for every other button.
			//
			// What a shape owns is left out rather than fought in CSS, and so is
			// the text colour of an icon-only button: pixfort would paint the icon
			// with it, a second source beside Icon color.
			$attr = array(
				'is_elementor'      => 'true',
				'btn_extra_classes' => $icon_only ? 'galaxie-wishlist-icon-only' : '',
				'btn_text'          => esc_html( $text ),
				'btn_link'          => '', // Empty on purpose: renders a <span>, not an <a> — our own wrapping <button> handles the click.
				'btn_icon'          => $settings[ $prefix . '_icon' ] ?? '',
				'btn_icon_position' => PixfortControls::class_list( $settings[ $prefix . '_icon_position' ] ?? '' ),
				'btn_style'         => PixfortControls::class_list( $settings[ $prefix . '_style' ] ?? '' ),
				'btn_color'         => PixfortControls::class_list( $settings[ $prefix . '_color' ] ?? 'primary' ),
				'btn_text_color'    => $icon_only ? '' : PixfortControls::class_list( $settings[ $prefix . '_text_color' ] ?? '' ),
				'btn_size'          => $shaped ? 'md' : PixfortControls::class_list( $settings[ $prefix . '_size' ] ?? 'md' ),
				'btn_rounded'       => $shaped ? '' : PixfortControls::class_list( $settings[ $prefix . '_rounded' ] ?? '' ),
				'btn_full'          => $shaped ? '' : PixfortControls::class_list( $settings[ $prefix . '_full' ] ?? '' ),
			);
			return \PixfortCore::instance()->elementsManager->renderElement( 'Button', $attr );
		}

		return '<span class="wishlist-btn">' . esc_html( $text ) . '</span>';
	}

	// ------------------------------------------------------------ saved widgets

	/**
	 * Settings as the merchant last saved them, before Elementor drops the ids
	 * no control registers any more. `get_settings_for_display()` only returns
	 * registered controls, so the retired ones have to be read here.
	 *
	 * The second "Salvar em uma lista" button is gone, and so is every `list_*`
	 * setting that styled it (`list_enabled`, `list_text`, its pixfort Button
	 * set) and the row's `buttons_gap`: nothing is left for them to style, and
	 * carrying a border or colour meant for that button onto this one would
	 * paint it twice. They are ignored.
	 *
	 * @return array<string,mixed>
	 */
	private function raw_settings(): array {
		$raw = $this->get_data( 'settings' );

		return is_array( $raw ) ? $raw : array();
	}

	/** Whether the merchant never set a control: absent, empty, or its default. */
	private function untouched( string $id ): bool {
		$raw = $this->raw_settings();

		if ( ! isset( $raw[ $id ] ) || '' === $raw[ $id ] ) {
			return true;
		}

		$control = $this->get_controls( $id );

		return is_array( $control ) && array_key_exists( 'default', $control ) && $control['default'] === $raw[ $id ];
	}

	/** A palette slug a class can carry: set, not Custom, not a gradient. */
	private static function class_slug( $value ): string {
		$slug = is_string( $value ) ? sanitize_key( $value ) : '';

		return '' !== $slug && 'custom' !== $slug && 0 !== strpos( $slug, 'gradient' ) ? $slug : '';
	}

	/**
	 * Values saved in controls that were retired, moved into the class-driven
	 * control that replaced each one, when that was never set.
	 *
	 * - The first Style section painted the button's background and border from
	 *   its own Normal and Saved tabs, on top of pixfort's Button color and
	 *   style. A palette background becomes a filled button in that colour; a
	 *   border becomes the colour of an Outline or Line button, which is where
	 *   pixfort draws one. Custom hex colours and the border width have no
	 *   pixfort equivalent and are dropped.
	 * - Alignment's Stretch, which filled the row, becomes Full width.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	private function legacy_settings( array $settings ): array {
		$raw    = $this->raw_settings();
		$shaped = in_array( (string) ( $settings['heart_shape'] ?? '' ), self::SHAPES, true );

		foreach ( array( 'add' => 'normal', 'saved' => 'saved' ) as $prefix => $tab ) {
			if ( ! $shaped && 'stretch' === ( $raw['buttons_align'] ?? '' ) && $this->untouched( $prefix . '_full' ) ) {
				$settings[ $prefix . '_full' ] = 'yes';
			}

			if ( ! $this->untouched( $prefix . '_color' ) ) {
				continue;
			}

			$bg     = self::class_slug( $raw[ 'heart_' . $tab . '_bg' ] ?? '' );
			$border = self::class_slug( $raw[ 'heart_' . $tab . '_border' ] ?? '' );
			$style  = (string) ( $settings[ $prefix . '_style' ] ?? '' );

			if ( '' !== $bg ) {
				$settings[ $prefix . '_color' ] = $bg;

				if ( $this->untouched( $prefix . '_style' ) && ! in_array( $style, array( '', 'flat' ), true ) ) {
					$settings[ $prefix . '_style' ] = '';
				}
			} elseif ( '' !== $border && in_array( $style, array( 'outline', 'line' ), true ) ) {
				$settings[ $prefix . '_color' ] = $border;
			}
		}

		return $settings;
	}

	/**
	 * CSS for colours saved in controls that were retired, written against the
	 * control that replaced each one — and only while that control is untouched,
	 * so Elementor's own rule and this one never both exist. Setting the new
	 * control ends it.
	 *
	 * - The Normal/Saved/Hover icon colours → each state's Icon color and Hover
	 *   icon color.
	 * - Hover background and border → each state's Hover background and Hover
	 *   border color.
	 * - A Text color on an icon-only button, which pixfort used to paint the icon
	 *   with → that state's Icon color.
	 */
	private function legacy_css(): string {
		$raw      = $this->raw_settings();
		$settings = $this->get_settings_for_display();
		$shaped   = in_array( (string) ( $settings['heart_shape'] ?? '' ), self::SHAPES, true );
		$wrapper  = '.elementor-element.elementor-element-' . sanitize_html_class( (string) $this->get_id() );
		$rules    = array();

		$colour = static function ( string $id ) use ( $raw ): string {
			$slug = is_string( $raw[ $id ] ?? null ) ? sanitize_key( $raw[ $id ] ) : '';

			if ( 'custom' === $slug ) {
				return PixfortControls::css_colour( $raw[ $id . '_custom' ] ?? '' );
			}

			return '' !== $slug ? 'var(--pix-' . $slug . ')' : '';
		};

		foreach ( array( 'add' => 'normal', 'saved' => 'saved' ) as $prefix => $tab ) {
			$state   = $wrapper . ' .galaxie-wishlist-' . $prefix;
			$hovered = static fn( string $tail ): string => $wrapper . ' .galaxie-wishlist-btn:hover .galaxie-wishlist-' . $prefix . ' ' . $tail . ', ' . $wrapper . ' .galaxie-wishlist-btn:focus-visible .galaxie-wishlist-' . $prefix . ' ' . $tail;
			$has     = '' !== (string) ( $settings[ $prefix . '_icon' ] ?? '' );

			if ( $has && $this->untouched( $prefix . '_icon_color' ) ) {
				$icon = $colour( 'heart_' . $tab . '_icon' );

				// Read as saved: Elementor hands a hidden control's value to render as
				// null, and Text color is hidden on exactly the buttons this is for.
				if ( '' === $icon && ( $shaped || 'yes' === ( $settings[ $prefix . '_icon_only' ] ?? '' ) ) ) {
					$slug = self::class_slug( $raw[ $prefix . '_text_color' ] ?? '' );
					$icon = '' !== $slug ? 'var(--pix-' . $slug . ')' : '';
				}

				if ( '' !== $icon ) {
					$rules[ $state . ' .pixfort-icon' ] = 'color: ' . $icon . ' !important; --pf-icon-color: ' . $icon . ' !important;';
				}
			}

			if ( $has && $this->untouched( $prefix . '_hover_icon' ) && '' !== $colour( 'heart_hover_icon' ) ) {
				$rules[ $hovered( '.pixfort-icon' ) ] = 'color: ' . $colour( 'heart_hover_icon' ) . ' !important; --pf-icon-color: ' . $colour( 'heart_hover_icon' ) . ' !important;';
			}

			if ( $this->untouched( $prefix . '_hover_bg' ) && '' !== $colour( 'heart_hover_bg' ) ) {
				$slug                         = sanitize_key( (string) ( $raw['heart_hover_bg'] ?? '' ) );
				$rules[ $hovered( '.btn' ) ] = 0 === strpos( $slug, 'gradient-' )
					? 'background-image: var(--pix-' . $slug . ') !important;'
					: 'background-color: ' . $colour( 'heart_hover_bg' ) . ' !important;';
			}

			if ( $this->untouched( $prefix . '_hover_border' ) && '' !== $colour( 'heart_hover_border' ) && in_array( (string) ( $settings[ $prefix . '_style' ] ?? '' ), array( 'outline', 'line' ), true ) ) {
				$rules[ $hovered( '.btn' ) ] = ( $rules[ $hovered( '.btn' ) ] ?? '' ) . ' border-color: ' . $colour( 'heart_hover_border' ) . ' !important;';
			}
		}

		if ( ! $rules ) {
			return '';
		}

		$css = '';

		foreach ( $rules as $selector => $declarations ) {
			$css .= $selector . '{' . trim( $declarations ) . '}';
		}

		return '<style>' . $css . '</style>';
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

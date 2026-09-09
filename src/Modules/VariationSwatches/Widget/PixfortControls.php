<?php
/**
 * Elementor control sets mirroring pixfort's own Button / Text / Badge widgets.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\VariationSwatches\Widget;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Wagner's standing requirement is that every Galaxie widget be customizable
 * exactly like a native pixfort one — the theme has light/dark and Dynamic
 * Colors, and its icons are pixfort icons, so a raw Elementor colour picker or
 * icon control is not an acceptable substitute for the global palette and the
 * `pixfort_icon_selector`.
 *
 * pixfort's own `pix_get_elementor_btn()` can't be reused: it hardcodes
 * unprefixed ids (`btn_text`, `btn_color`, …), so it works exactly once per
 * widget. This registers the same controls under a caller-chosen prefix, which
 * is what lets one widget carry an Add to Cart button AND a Buy Now button —
 * and lets both live inside repeater rows.
 *
 * Every method takes a `$target` that exposes `add_control()`, so the same call
 * works against a `Widget_Base` and against an `\Elementor\Repeater`.
 */
final class PixfortControls {

	/**
	 * Sizes pixfort's own Text widget offers, copied from it verbatim — except
	 * for one thing. Its list carries `'text-sm' => '14px'` twice, and the
	 * second occurrence overwrites the first; the entry clearly meant to be
	 * 16px never made it, and no `.text-16` rule exists in the theme's CSS
	 * either. Offering a 16px that silently does nothing would be worse than
	 * not offering it.
	 */
	private const TEXT_SIZES = array(
		''        => 'Default',
		'text-xs' => '12px',
		'text-sm' => '14px',
		'text-18' => '18px',
		'text-20' => '20px',
		'text-24' => '24px',
	);

	/** pixfort's Heading widget vocabulary, for text that reads as a heading (the price). */
	private const HEADING_SIZES = array(
		'h1'     => 'H1',
		'h2'     => 'H2',
		'h3'     => 'H3',
		'h4'     => 'H4',
		'h5'     => 'H5',
		'h6'     => 'H6',
		'custom' => 'Custom',
	);

	public static function available(): bool {
		return class_exists( '\PixfortCore' );
	}

	/**
	 * pixfort's full Button control set, minus the three link controls
	 * (`btn_link`, `btn_target`, `btn_popup_id`): these buttons drive a form,
	 * and `PixButton::render()` only emits a `<span>` instead of an `<a>` while
	 * `btn_link` stays empty — which is exactly what our wrapping button needs.
	 *
	 * @param object              $target    Anything exposing `add_control()`.
	 * @param string              $prefix    Control-id prefix, e.g. `addcart`.
	 * @param array<string,mixed> $defaults  Unprefixed key => default value.
	 * @param array<string,mixed> $condition Applied to every control (a repeater row's own field, typically).
	 * @param string              $scope     CSS scope for the selector-driven icon colours.
	 */
	public static function button( object $target, string $prefix, array $defaults = array(), array $condition = array(), string $scope = '{{WRAPPER}}' ): void {
		$d = static fn( string $key, $fallback ) => $defaults[ $key ] ?? $fallback;

		self::add( $target, $condition, $prefix . '_text', array(
			'label'       => __( 'Button text', 'galaxie-woo' ),
			'label_block' => true,
			'type'        => Controls_Manager::TEXT,
			'default'     => $d( 'text', '' ),
			'dynamic'     => array( 'active' => true ),
		) );

		self::add( $target, $condition, $prefix . '_title_bold', array(
			'label'        => __( 'Bold', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'font-weight-bold',
			'default'      => $d( 'title_bold', 'font-weight-bold' ),
		) );

		self::add( $target, $condition, $prefix . '_italic', array(
			'label'        => __( 'Italic', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'font-italic',
			'default'      => $d( 'italic', '' ),
		) );

		self::add( $target, $condition, $prefix . '_secondary_font', array(
			'label'        => __( 'Secondary font', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'secondary-font',
			'default'      => $d( 'secondary_font', '' ),
		) );

		self::add( $target, $condition, $prefix . '_style', array(
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
			'default' => $d( 'style', '' ),
		) );

		if ( self::available() ) {
			self::add( $target, $condition, $prefix . '_color', array(
				'label'   => __( 'Button color', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'groups'  => self::colors( array( 'defaultColors' => false, 'mainLight' => true, 'custom' => false ) ),
				'default' => $d( 'color', 'primary' ),
			) );
		} else {
			self::add( $target, $condition, $prefix . '_color_fallback', array(
				'label'     => __( 'Button color', 'galaxie-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $scope . ' .galaxie-buybox-' . $prefix . ' .btn' => 'background-color: {{VALUE}};' ),
			) );
		}

		self::add( $target, $condition, $prefix . '_remove_padding', array(
			'label'        => __( 'Remove padding', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'no-padding',
			'default'      => $d( 'remove_padding', '' ),
			'condition'    => array( $prefix . '_style' => array( 'link', 'underline' ) ),
		) );

		if ( self::available() ) {
			self::add( $target, $condition, $prefix . '_text_color', array(
				'label'   => __( 'Text color', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'groups'  => self::colors( array( 'defaultValue' => array( '' => __( 'Default', 'galaxie-woo' ) ), 'mainLight' => true ) ),
				'default' => $d( 'text_color', '' ),
			) );

			self::add( $target, $condition, $prefix . '_text_custom_color', array(
				'label'     => __( 'Text custom color', 'galaxie-woo' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'condition' => array( $prefix . '_text_color' => 'custom' ),
			) );
		}

		self::add( $target, $condition, $prefix . '_size', array(
			'label'   => __( 'Button size', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => array(
				'sm'     => __( 'Small', 'galaxie-woo' ),
				'normal' => __( 'Normal', 'galaxie-woo' ),
				'md'     => __( 'Medium', 'galaxie-woo' ),
				'lg'     => __( 'Large', 'galaxie-woo' ),
				'xl'     => __( 'XLarge', 'galaxie-woo' ),
			),
			'default' => $d( 'size', 'md' ),
		) );

		self::add( $target, $condition, $prefix . '_rounded', array(
			'label'        => __( 'Rounded corners button', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'btn-rounded',
			'default'      => $d( 'rounded', '' ),
		) );

		self::add( $target, $condition, $prefix . '_effect', array(
			'label'   => __( 'Button shadow', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => self::shadow_options( __( 'Default', 'galaxie-woo' ), __( 'shadow', 'galaxie-woo' ) ),
			'default' => $d( 'effect', '' ),
		) );

		self::add( $target, $condition, $prefix . '_hover_effect', array(
			'label'   => __( 'Button shadow hover style', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => self::shadow_options( __( 'None', 'galaxie-woo' ), __( 'hover shadow', 'galaxie-woo' ) ),
			'default' => $d( 'hover_effect', '' ),
		) );

		self::add( $target, $condition, $prefix . '_add_hover_effect', array(
			'label'   => __( 'Button hover animation', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => self::hover_animations(),
			'default' => $d( 'add_hover_effect', '' ),
		) );

		if ( self::available() ) {
			self::icon_select( $target, $prefix . '_icon', __( 'Button icon', 'galaxie-woo' ), (string) $d( 'icon', '' ), $condition );

			$icon_colors = self::colors( array( 'defaultValue' => array( '' => __( 'Default', 'galaxie-woo' ) ), 'mainLight' => true, 'gradients' => false ) );

			self::add( $target, $condition, $prefix . '_icon_color', array(
				'label'                => __( 'Icon color', 'galaxie-woo' ),
				'type'                 => Controls_Manager::SELECT,
				'groups'               => $icon_colors,
				'default'              => '',
				'selectors_dictionary' => self::icon_color_dictionary( $icon_colors ),
				'selectors'            => array( $scope . ' .btn .pixfort-icon' => '{{VALUE}}' ),
				'condition'            => array( $prefix . '_icon!' => '' ),
			) );

			self::add( $target, $condition, $prefix . '_icon_custom_color', array(
				'label'      => __( 'Icon custom color', 'galaxie-woo' ),
				'type'       => Controls_Manager::COLOR,
				'default'    => '',
				'selectors'  => array( $scope . ' .btn .pixfort-icon' => 'color: {{VALUE}} !important; --pf-icon-color: {{VALUE}} !important;' ),
				'condition'  => array( $prefix . '_icon!' => '', $prefix . '_icon_color' => 'custom' ),
			) );

			self::add( $target, $condition, $prefix . '_icon_position', array(
				'label'      => __( 'Icon position', 'galaxie-woo' ),
				'type'       => Controls_Manager::SELECT,
				'options'    => array(
					''      => __( 'Before text', 'galaxie-woo' ),
					'after' => __( 'After text', 'galaxie-woo' ),
				),
				'default'    => $d( 'icon_position', '' ),
				'condition'  => array( $prefix . '_icon!' => '' ),
			) );

			self::add( $target, $condition, $prefix . '_icon_animation', array(
				'label'        => __( 'Icon animation', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'condition'    => array( $prefix . '_icon!' => '' ),
			) );
		}

		self::add( $target, $condition, $prefix . '_full', array(
			'label'        => __( 'Full width button', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => $d( 'full', '' ),
		) );

		self::add( $target, $condition, $prefix . '_text_align', array(
			'label'      => __( 'Button text align', 'galaxie-woo' ),
			'type'       => Controls_Manager::SELECT,
			'options'    => array(
				'text-center' => __( 'Center', 'galaxie-woo' ),
				'text-left'   => __( 'Left', 'galaxie-woo' ),
				'text-right'  => __( 'Right', 'galaxie-woo' ),
			),
			'default'    => '',
			'condition'  => array( $prefix . '_full!' => '' ),
		) );

		self::add( $target, $condition, $prefix . '_div', array(
			'label'   => __( 'Button inside a container', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => array(
				''            => __( 'Disabled', 'galaxie-woo' ),
				'text-center' => __( 'Center align', 'galaxie-woo' ),
				'text-left'   => __( 'Left align', 'galaxie-woo' ),
				'text-right'  => __( 'Right align', 'galaxie-woo' ),
			),
			'default' => $d( 'div', '' ),
		) );

		self::add( $target, $condition, $prefix . '_animation', array(
			'label'   => __( 'Animation', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'default' => '',
			'options' => self::animations(),
		) );

		self::add( $target, $condition, $prefix . '_anim_delay', array(
			'label'     => __( 'Animation delay (in miliseconds)', 'galaxie-woo' ),
			'type'      => Controls_Manager::TEXT,
			'default'   => '0',
			'condition' => array( $prefix . '_animation!' => '' ),
		) );

		self::add( $target, $condition, $prefix . '_extra_classes', array(
			'label'       => __( 'Extra classes', 'galaxie-woo' ),
			'label_block' => true,
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
		) );

		self::button_hover( $target, $prefix, $condition, $scope );
	}

	/**
	 * Hover styling beyond what pixfort's Button offers.
	 *
	 * pixfort covers only the shadow and the movement (`btn_hover_effect` and
	 * `btn_add_hover_effect`, registered above); it has no hover COLOURS at
	 * all. XStore's add-to-cart widget does, and that combination is the point
	 * of this bundle — so they are added here, still through the theme's own
	 * palette so they follow light/dark and Dynamic Colors like everything
	 * else does.
	 *
	 * The lift is exposed too. The theme applies its own
	 * `translate(0, -3px)` to `.single_add_to_cart_button:hover` regardless of
	 * any widget setting; that is neutralised in our stylesheet so this control
	 * is the only thing that moves the button. A control reporting None while
	 * the button still hops is worse than no control.
	 *
	 * @param array<string,mixed> $condition
	 */
	private static function button_hover( object $target, string $prefix, array $condition, string $scope ): void {
		$hovered = $scope . ':hover .btn';

		self::add( $target, $condition, $prefix . '_hover_heading', array(
			'label'     => __( 'Hover', 'galaxie-woo' ),
			'type'      => Controls_Manager::HEADING,
			'separator' => 'before',
		) );

		self::palette_control( $target, $prefix . '_hover_bg', __( 'Hover background', 'galaxie-woo' ), $hovered, 'background-color', $condition );
		self::palette_control( $target, $prefix . '_hover_color', __( 'Hover text color', 'galaxie-woo' ), $hovered, 'color', $condition );
		self::palette_control(
			$target,
			$prefix . '_hover_border',
			__( 'Hover border color', 'galaxie-woo' ),
			$hovered,
			'border-color',
			$condition,
			' border-style: solid !important;'
		);

		self::add( $target, $condition, $prefix . '_hover_lift', array(
			'label'      => __( 'Hover lift', 'galaxie-woo' ),
			'type'       => Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 12 ) ),
			// A custom property, not the transform itself: pixfort forces its own
			// `translate(0, -3px) !important` on `.single_add_to_cart_button:hover`,
			// so our stylesheet has to answer with `!important` too — and that reset
			// would outrank Elementor's generated rule. Handing the value over as a
			// variable lets both rules coexist: ours owns the transform, this owns
			// the distance.
			'selectors'  => array( $scope => '--galaxie-hover-lift: {{SIZE}}{{UNIT}};' ),
		) );
	}

	/**
	 * Maps a prefixed settings array back onto the exact unprefixed `btn_*`
	 * keys `PixButton::render()` reads.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public static function button_attr( array $settings, string $prefix, string $text ): array {
		$keys = array(
			'title_bold', 'italic', 'secondary_font', 'style', 'color', 'remove_padding',
			'text_color', 'text_custom_color', 'size', 'rounded', 'effect', 'hover_effect',
			'add_hover_effect', 'icon', 'icon_color', 'icon_custom_color', 'icon_position',
			'icon_animation', 'full', 'text_align', 'div', 'animation', 'anim_delay',
			'extra_classes',
		);

		$attr = array(
			'is_elementor' => 'true',
			'btn_text'     => $text,
			// Empty on purpose: PixButton renders a <span> rather than an <a>,
			// which is what belongs inside our own <button>/form.
			'btn_link'     => '',
		);

		foreach ( $keys as $key ) {
			$attr[ 'btn_' . $key ] = $settings[ $prefix . '_' . $key ] ?? '';
		}

		// The icon is a pair of controls, not one: a dropdown plus the raw
		// identifier the Custom entry reveals.
		$attr['btn_icon'] = self::icon_value( $settings, $prefix . '_icon' );

		return $attr;
	}

	/**
	 * pixfort's Text control set. Used for the variation label, the regular and
	 * sale price, and the stock line — each an independent configuration.
	 *
	 * @param array<string,mixed> $defaults
	 * @param array<string,mixed> $condition
	 */
	public static function text( object $target, string $prefix, array $defaults = array(), array $condition = array(), string $scope = '{{WRAPPER}}', string $sizes = 'text' ): void {
		$d = static fn( string $key, $fallback ) => $defaults[ $key ] ?? $fallback;

		self::add( $target, $condition, $prefix . '_size', array(
			'label'   => __( 'Size', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => 'heading' === $sizes ? self::HEADING_SIZES : self::TEXT_SIZES,
			'default' => $d( 'size', '' ),
		) );

		if ( 'heading' === $sizes ) {
			self::add( $target, $condition, $prefix . '_custom_size', array(
				'label'      => __( 'Custom size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 10, 'max' => 96 ) ),
				'selectors'  => array( $scope . ' p' => 'font-size: {{SIZE}}{{UNIT}};' ),
				'condition'  => array( $prefix . '_size' => 'custom' ),
			) );
		}

		self::add( $target, $condition, $prefix . '_bold', array(
			'label'        => __( 'Bold', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'font-weight-bold',
			// pixfort's own Text widget ships bold ON.
			'default'      => $d( 'bold', 'font-weight-bold' ),
		) );

		self::add( $target, $condition, $prefix . '_italic', array(
			'label'        => __( 'Italic', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'font-italic',
			'default'      => $d( 'italic', '' ),
		) );

		self::add( $target, $condition, $prefix . '_secondary_font', array(
			'label'        => __( 'Secondary font', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'secondary-font',
			'default'      => $d( 'secondary_font', '' ),
		) );

		self::add( $target, $condition, $prefix . '_max_width', array(
			'label'       => __( 'Text max width (optional)', 'galaxie-woo' ),
			'label_block' => true,
			'type'        => Controls_Manager::TEXT,
			'placeholder' => __( 'For example 400px', 'galaxie-woo' ),
			'default'     => $d( 'max_width', '' ),
		) );

		if ( self::available() ) {
			self::add( $target, $condition, $prefix . '_content_color', array(
				'label'   => __( 'Content color', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'groups'  => self::colors( array( 'defaultValue' => array( '' => __( 'Default', 'galaxie-woo' ) ), 'mainLight' => true ) ),
				'default' => $d( 'content_color', '' ),
			) );

			self::add( $target, $condition, $prefix . '_content_custom_color', array(
				'label'     => __( 'Custom content color', 'galaxie-woo' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'condition' => array( $prefix . '_content_color' => 'custom' ),
			) );
		} else {
			self::add( $target, $condition, $prefix . '_content_color_fallback', array(
				'label'     => __( 'Content color', 'galaxie-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $scope . ' .galaxie-buybox-' . $prefix => 'color: {{VALUE}};' ),
			) );
		}

		self::add( $target, $condition, $prefix . '_position', array(
			'label'   => __( 'Position', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => array(
				'text-left'   => __( 'Start', 'galaxie-woo' ),
				'text-center' => __( 'Center', 'galaxie-woo' ),
				'text-right'  => __( 'End', 'galaxie-woo' ),
			),
			'default' => $d( 'position', 'text-left' ),
		) );

		self::add( $target, $condition, $prefix . '_animation', array(
			'label'   => __( 'Animation', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'default' => '',
			'options' => self::animations(),
		) );

		self::add( $target, $condition, $prefix . '_delay', array(
			'label'     => __( 'Animation delay (in miliseconds)', 'galaxie-woo' ),
			'type'      => Controls_Manager::TEXT,
			'default'   => '0',
			'condition' => array( $prefix . '_animation!' => '' ),
		) );

		self::add( $target, $condition, $prefix . '_remove_pb_padding', array(
			'label'        => __( 'Remove margin under paragraphs', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'm-0',
			// pixfort defaults this off; the buy box turns it on per slot,
			// because paragraph margins there fight the configured block gap.
			'default'      => $d( 'remove_pb_padding', '' ),
		) );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public static function text_attr( array $settings, string $prefix, string $content ): array {
		return array(
			'content_type'         => 'simple',
			'content'              => $content,
			// `custom` is our own marker for the slider that accompanies it; it
			// is not a pixfort class and must not be emitted as one.
			'size'                 => 'custom' === ( $settings[ $prefix . '_size' ] ?? '' ) ? '' : ( $settings[ $prefix . '_size' ] ?? '' ),
			'bold'                 => $settings[ $prefix . '_bold' ] ?? '',
			'italic'               => $settings[ $prefix . '_italic' ] ?? '',
			'secondary_font'       => $settings[ $prefix . '_secondary_font' ] ?? '',
			'content_color'        => $settings[ $prefix . '_content_color' ] ?? '',
			'content_custom_color' => $settings[ $prefix . '_content_custom_color' ] ?? '',
			'position'             => $settings[ $prefix . '_position' ] ?? '',
			'max_width'            => $settings[ $prefix . '_max_width' ] ?? '',
			'animation'            => $settings[ $prefix . '_animation' ] ?? '',
			'delay'                => $settings[ $prefix . '_delay' ] ?? '',
			'remove_pb_padding'    => $settings[ $prefix . '_remove_pb_padding' ] ?? '',
		);
	}

	/**
	 * pixfort's Badge control set — the swatches use it, and so does the sale
	 * price in its "advanced" display mode.
	 *
	 * @param array<string,mixed> $defaults
	 * @param array<string,mixed> $condition
	 */
	public static function badge( object $target, string $prefix, array $defaults = array(), array $condition = array(), string $scope = '{{WRAPPER}}' ): void {
		$d = static fn( string $key, $fallback ) => $defaults[ $key ] ?? $fallback;

		if ( self::available() ) {
			self::add( $target, $condition, $prefix . '_text_color', array(
				'label'   => __( 'Text color', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'groups'  => self::colors( array( 'defaultColors' => false, 'mainLight' => true ) ),
				'default' => $d( 'text_color', 'primary' ),
			) );

			self::add( $target, $condition, $prefix . '_text_custom_color', array(
				'label'     => __( 'Custom text color', 'galaxie-woo' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'condition' => array( $prefix . '_text_color' => 'custom' ),
			) );

			self::add( $target, $condition, $prefix . '_bg_color', array(
				'label'   => __( 'Background color', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'groups'  => self::colors( array( 'bg' => true, 'transparent' => true ) ),
				'default' => $d( 'bg_color', 'primary-light' ),
			) );

			self::add( $target, $condition, $prefix . '_custom_bg_color', array(
				'label'     => __( 'Custom background color', 'galaxie-woo' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'condition' => array( $prefix . '_bg_color' => 'custom' ),
			) );
		} else {
			self::add( $target, $condition, $prefix . '_text_color_fallback', array(
				'label'     => __( 'Text color', 'galaxie-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $scope . ' .galaxie-badge-' . $prefix => 'color: {{VALUE}};' ),
			) );

			self::add( $target, $condition, $prefix . '_bg_color_fallback', array(
				'label'     => __( 'Background color', 'galaxie-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $scope . ' .galaxie-badge-' . $prefix => 'background-color: {{VALUE}};' ),
			) );
		}

		// H1-H6 + Custom, defaulting to h6 — pixfort's Badge uses the heading
		// vocabulary, not the Text one, and both its control and PixBadge::render()
		// default to `h6`. Offering "Default" here would be an option pixfort has
		// no equivalent for, and passing '' emits a badge with no size class at
		// all, which is why it did not match a Text set to Default.
		self::add( $target, $condition, $prefix . '_text_size', array(
			'label'   => __( 'Text size', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => self::HEADING_SIZES,
			'default' => $d( 'text_size', 'h6' ),
		) );

		self::add( $target, $condition, $prefix . '_text_custom_size', array(
			'label'     => __( 'Custom text size', 'galaxie-woo' ),
			'type'      => Controls_Manager::TEXT,
			'default'   => '',
			'condition' => array( $prefix . '_text_size' => 'custom' ),
		) );

		// pixfort offers a border-radius SELECT here, not a yes/no. Its scale is
		// pulled live from `pixfort_get_border_radius_options()` rather than
		// copied: that list is filterable and each entry maps to a theme option
		// (pix-border-radius-small/normal/large), so reading it is what keeps the
		// widget following the site's configured radii.
		self::add( $target, $condition, $prefix . '_rounded', array(
			'label'   => __( 'Border radius', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => self::radius_options(),
			'default' => $d( 'rounded', '' ),
		) );

		self::add( $target, $condition, $prefix . '_bold', array(
			'label'        => __( 'Bold', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'font-weight-bold',
			// pixfort's own Badge ships bold ON.
			'default'      => $d( 'bold', 'font-weight-bold' ),
		) );

		self::add( $target, $condition, $prefix . '_italic', array(
			'label'        => __( 'Italic', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'font-italic',
			'default'      => $d( 'italic', '' ),
		) );

		self::add( $target, $condition, $prefix . '_secondary_font', array(
			'label'        => __( 'Secondary font', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'secondary-font',
			'default'      => $d( 'secondary_font', '' ),
		) );

		self::add( $target, $condition, $prefix . '_element_div', array(
			'label'   => __( 'Badge inside a container', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => array(
				''            => __( 'Disabled', 'galaxie-woo' ),
				'text-center' => __( 'Center align', 'galaxie-woo' ),
				'text-left'   => __( 'Left align', 'galaxie-woo' ),
				'text-right'  => __( 'Right align', 'galaxie-woo' ),
			),
			'default' => $d( 'element_div', '' ),
		) );

		self::add( $target, $condition, $prefix . '_disable_margin_after_badge', array(
			'label'        => __( 'Remove margin after badge', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => $d( 'disable_margin_after_badge', '' ),
		) );

		self::add( $target, $condition, $prefix . '_style', array(
			'label'   => __( 'Shadow style', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => self::shadow_options( __( 'Default', 'galaxie-woo' ), __( 'shadow', 'galaxie-woo' ) ),
			'default' => $d( 'style', '' ),
		) );

		self::add( $target, $condition, $prefix . '_hover_effect', array(
			'label'   => __( 'Shadow hover style', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => self::shadow_options( __( 'None', 'galaxie-woo' ), __( 'hover shadow', 'galaxie-woo' ) ),
			'default' => $d( 'hover_effect', '' ),
		) );

		self::add( $target, $condition, $prefix . '_add_hover_effect', array(
			'label'   => __( 'Hover animation', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => self::hover_animations(),
			'default' => $d( 'add_hover_effect', '' ),
		) );

		self::add( $target, $condition, $prefix . '_animation', array(
			'label'   => __( 'Animation', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'default' => '',
			'options' => self::animations(),
		) );

		self::add( $target, $condition, $prefix . '_delay', array(
			'label'     => __( 'Animation delay (in miliseconds)', 'galaxie-woo' ),
			'type'      => Controls_Manager::TEXT,
			'default'   => '0',
			'condition' => array( $prefix . '_animation!' => '' ),
		) );

		self::add( $target, $condition, $prefix . '_extra_classes', array(
			'label'       => __( 'Extra classes', 'galaxie-woo' ),
			'label_block' => true,
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
		) );
	}


	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public static function badge_attr( array $settings, string $prefix, string $text ): array {
		$keys = array(
			'text_color', 'text_custom_color', 'text_size', 'text_custom_size',
			'bold', 'italic', 'secondary_font', 'rounded', 'bg_color',
			'custom_bg_color', 'style', 'hover_effect', 'add_hover_effect',
			'animation', 'delay', 'extra_classes', 'element_div',
			'disable_margin_after_badge',
		);

		// text_size seeds pixfort's own default rather than an empty string:
		// PixBadge emits it as a class, and '' produces a badge carrying no
		// size at all — which is not what "default" means anywhere in pixfort.
		$attr = array( 'text' => $text, 'text_size' => 'h6' );

		foreach ( $keys as $key ) {
			if ( isset( $settings[ $prefix . '_' . $key ] ) ) {
				$attr[ $key ] = $settings[ $prefix . '_' . $key ];
			}
		}

		return $attr;
	}

	/**
	 * pixfort's Alert control set, mirrored from `Pix_Eor_Alert`.
	 *
	 * Two deliberate departures from that widget:
	 *
	 * - No Link section and no Image media type. This alert exists to say why an
	 *   add-to-cart did not happen, in the place the shopper is already looking;
	 *   there is nowhere for it to link to and nothing for a photograph to add.
	 *   Icon and Character stay, because they carry severity at a glance.
	 * - No "Alert Type" control. The type is per MESSAGE here, not per widget —
	 *   a missing choice is a warning and a dead combination is an error, and one
	 *   shared colour for both would be worse than pixfort's single alert, not
	 *   better. The widget registers those; everything on this method is the
	 *   shared skin they all wear.
	 *
	 * @param array<string,mixed> $defaults
	 * @param array<string,mixed> $condition
	 */
	public static function alert( object $target, string $prefix, array $defaults = array(), array $condition = array(), string $scope = '{{WRAPPER}}' ): void {
		$d = static fn( string $key, $fallback ) => $defaults[ $key ] ?? $fallback;

		self::add( $target, $condition, $prefix . '_bold', array(
			'label'        => __( 'Bold', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'font-weight-bold',
			// pixfort's Alert ships bold ON, like its Badge.
			'default'      => $d( 'bold', 'font-weight-bold' ),
		) );

		self::add( $target, $condition, $prefix . '_italic', array(
			'label'        => __( 'Italic', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'font-italic',
			'default'      => $d( 'italic', '' ),
		) );

		self::add( $target, $condition, $prefix . '_secondary_font', array(
			'label'        => __( 'Secondary font', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'secondary-font',
			'default'      => $d( 'secondary_font', '' ),
		) );

		// pixfort's own five radius classes, with no empty entry: its Alert has no
		// "Default" and defaults to `rounded-lg`. An alert carrying no radius
		// class is a square-cornered alert, which matches nothing else on the site.
		self::add( $target, $condition, $prefix . '_rounded', array(
			'label'   => __( 'Rounded corners', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => self::container_radius_options(),
			'default' => $d( 'rounded', 'rounded-lg' ),
		) );

		// The Alert numbers its "no shadow" entry "0" where Button and Badge use
		// an empty string. Same six shadows, different key for the first one —
		// so the shared list is re-keyed rather than reused as-is.
		$shadows = self::shadow_options( __( 'Default', 'galaxie-woo' ), __( 'shadow', 'galaxie-woo' ) );
		$shadows = array( '0' => $shadows[''] ) + array_diff_key( $shadows, array( '' => '' ) );

		self::add( $target, $condition, $prefix . '_shadow', array(
			'label'   => __( 'Shadow style', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => $shadows,
			'default' => $d( 'shadow', '2' ),
		) );

		self::add( $target, $condition, $prefix . '_hover_effect', array(
			'label'   => __( 'Shadow hover style', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => self::shadow_options( __( 'None', 'galaxie-woo' ), __( 'hover shadow', 'galaxie-woo' ) ),
			'default' => $d( 'hover_effect', '' ),
		) );

		self::add( $target, $condition, $prefix . '_add_hover_effect', array(
			'label'   => __( 'Hover animation', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => self::hover_animations(),
			'default' => $d( 'add_hover_effect', '' ),
		) );

		self::add( $target, $condition, $prefix . '_hide_close', array(
			'label'        => __( 'Hide close button', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'true',
			'default'      => $d( 'hide_close', '' ),
		) );

		self::add( $target, $condition, $prefix . '_media_type', array(
			'label'   => __( 'Use an icon', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'options' => array(
				'none' => __( 'None', 'galaxie-woo' ),
				'icon' => __( 'Icon', 'galaxie-woo' ),
				'char' => __( 'Character', 'galaxie-woo' ),
			),
			'default' => $d( 'media_type', 'icon' ),
		) );

		self::add( $target, $condition, $prefix . '_char', array(
			'label'     => __( 'Character', 'galaxie-woo' ),
			'type'      => Controls_Manager::TEXT,
			'default'   => $d( 'char', '!' ),
			'condition' => array( $prefix . '_media_type' => 'char' ),
		) );

		// A plain palette SELECT, not a selectors dictionary: PixAlert turns this
		// value into a `text-{slug}` class itself, the same way its own widget does.
		self::add( $target, $condition, $prefix . '_icon_color', array(
			'label'     => __( 'Icon color', 'galaxie-woo' ),
			'type'      => Controls_Manager::SELECT,
			'groups'    => self::colors( array( 'defaultValue' => array( 'alert-default' => __( 'Default', 'galaxie-woo' ) ), 'mainLight' => true, 'gradients' => false ) ),
			'default'   => $d( 'icon_color', 'primary' ),
			'condition' => array( $prefix . '_media_type!' => 'none' ),
		) );

		self::add( $target, $condition, $prefix . '_custom_icon_color', array(
			'label'     => __( 'Custom icon color', 'galaxie-woo' ),
			'type'      => Controls_Manager::COLOR,
			'default'   => '',
			'condition' => array( $prefix . '_icon_color' => 'custom' ),
		) );

		self::add( $target, $condition, $prefix . '_icon_size', array(
			'label'     => __( 'Icon size (without unit)', 'galaxie-woo' ),
			'type'      => Controls_Manager::TEXT,
			'default'   => $d( 'icon_size', '30' ),
			'condition' => array( $prefix . '_media_type!' => 'none' ),
			'selectors' => array( $scope . ' .pix-alert-icon > div' => 'font-size: {{VALUE}}px !important;' ),
		) );

		self::add( $target, $condition, $prefix . '_animation', array(
			'label'   => __( 'Animation', 'galaxie-woo' ),
			'type'    => Controls_Manager::SELECT,
			'default' => '',
			'options' => self::animations(),
		) );

		self::add( $target, $condition, $prefix . '_delay', array(
			'label'     => __( 'Animation delay (in miliseconds)', 'galaxie-woo' ),
			'type'      => Controls_Manager::TEXT,
			'default'   => '0',
			'condition' => array( $prefix . '_animation!' => '' ),
		) );
	}

	/**
	 * Maps a prefixed settings array onto the unprefixed keys `PixAlert::render()`
	 * reads, for one message.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public static function alert_attr( array $settings, string $prefix, string $title, string $type, string $icon = '', array $link = array() ): array {
		$keys = array(
			'bold', 'italic', 'secondary_font', 'shadow', 'hover_effect',
			'add_hover_effect', 'media_type', 'char', 'icon_color',
			'custom_icon_color', 'icon_size', 'hide_close', 'animation', 'delay',
		);

		$attr = array(
			'title'        => $title,
			'alert_type_1' => '' !== $type ? $type : 'warning',
			// PixAlert calls the radius attribute `rounded_img` even when there is
			// no image; it is simply appended to the alert's class list.
			'rounded_img'  => (string) ( $settings[ $prefix . '_rounded' ] ?? 'rounded-lg' ),
			// PixAlert keys its whole link branch off `link_text`: with it empty the
			// alert renders as a plain block, and with it set it grows a slot at
			// `order-2`, immediately left of the close button at `order-3`. That
			// is the slot the cart link belongs in — no markup of ours required.
			'link'         => $link['link'] ?? '',
			'link_text'    => $link['link_text'] ?? '',
			'link_color'   => $link['link_color'] ?? 'alert-default',
		);

		foreach ( $keys as $key ) {
			if ( isset( $settings[ $prefix . '_' . $key ] ) ) {
				$attr[ $key ] = $settings[ $prefix . '_' . $key ];
			}
		}

		// The icon travels with the message rather than with the skin: a warning
		// triangle over "Added to cart" is worse than no icon at all, and one
		// shared glyph cannot be right for four different things being said.
		if ( '' !== $icon ) {
			$attr['icon'] = $icon;
		}

		// Asking for an icon without picking one would render an empty box.
		// Dropping the key lets PixAlert fall back to its own default glyph.
		if ( 'icon' === ( $attr['media_type'] ?? '' ) && empty( $attr['icon'] ) ) {
			unset( $attr['icon'] );
		}

		return $attr;
	}

	/**
	 * A dropdown of icons instead of pixfort's own picker.
	 *
	 * The picker's `content_template()` prints the ENTIRE icon library inline,
	 * once per control. Six of them in one panel — two buttons and four alert
	 * messages — meant roughly a minute of frozen editor every time it opened.
	 * This is a plain SELECT: same identifiers, same `getIcon()`, same palette
	 * around it, without two thousand icons of markup per field.
	 *
	 * The value is the full `Style/pixfort-icon-name`, never a bare name — a bare
	 * name is not a filename to pixfort, it is a legacy alias resolved through
	 * `mapping-duotone.php`, and some of those point somewhere else entirely
	 * (a bare `cart-2` renders `cart-7`).
	 *
	 * Custom keeps the door open: the list is curated, not exhaustive, and no one
	 * should be boxed in by our idea of which icons matter.
	 *
	 * @param array<string,mixed> $condition
	 */
	public static function icon_select( object $target, string $id, string $label, string $default = '', array $condition = array() ): void {
		self::add( $target, $condition, $id, array(
			'label'   => $label,
			// The literal, not the constant: naming the class would autoload it
			// wherever controls are registered, including the front end.
			'type'    => 'galaxie_icon',
			'default' => $default,
		) );
	}

	/**
	 * The identifier a pair of {@see icon_select()} controls resolves to.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function icon_value( array $settings, string $id ): string {
		return trim( (string) ( $settings[ $id ] ?? '' ) );
	}

	/**
	 * Palette colour for a pixfort SVG icon.
	 *
	 * An icon is not coloured by a `text-{slug}` class like a label is — pixfort
	 * paints it through the `--pf-icon-color` variable, so the palette entry has
	 * to arrive as a declaration rather than as a class name. That is what the
	 * dictionary is for, and it is the same mapping the Button's own icon colour
	 * uses, lifted here now that a second control needs it.
	 *
	 * @param array<string,mixed> $condition
	 */
	public static function icon_color( object $target, string $id, string $label, string $selector, array $condition = array() ): void {
		if ( ! self::available() ) {
			return;
		}

		$colors = self::colors( array( 'defaultValue' => array( '' => __( 'Default', 'galaxie-woo' ) ), 'mainLight' => true, 'gradients' => false ) );

		self::add( $target, $condition, $id, array(
			'label'                => $label,
			'type'                 => Controls_Manager::SELECT,
			'groups'               => $colors,
			'default'              => '',
			'selectors_dictionary' => self::icon_color_dictionary( $colors ),
			'selectors'            => array( $selector => '{{VALUE}}' ),
		) );

		self::add( $target, $condition, $id . '_custom', array(
			'label'     => sprintf( /* translators: %s: the colour control's own label. */ __( 'Custom %s', 'galaxie-woo' ), strtolower( $label ) ),
			'type'      => Controls_Manager::COLOR,
			'default'   => '',
			'selectors' => array( $selector => 'color: {{VALUE}} !important; --pf-icon-color: {{VALUE}} !important;' ),
			'condition' => array( $id => 'custom' ),
		) );
	}

	/**
	 * A plain palette dropdown whose value is handed to a pixfort component as an
	 * attribute — the component turns it into its own `text-{slug}` class.
	 *
	 * Distinct from {@see palette_control()}, which drives CSS selectors for
	 * markup pixfort does not render. Use this one whenever the component itself
	 * understands the palette, so the class it emits stays the class it would
	 * have emitted in pixfort's own widget.
	 *
	 * @param array<string,mixed> $condition
	 */
	public static function palette_select( object $target, string $id, string $label, string $default = '', array $condition = array() ): void {
		if ( ! self::available() ) {
			return;
		}

		self::add( $target, $condition, $id, array(
			'label'   => $label,
			'type'    => Controls_Manager::SELECT,
			'groups'  => self::colors( array( 'defaultValue' => array( $default => __( 'Default', 'galaxie-woo' ) ), 'mainLight' => true, 'gradients' => false ) ),
			'default' => $default,
		) );
	}

	/**
	 * A pixfort-palette colour picker for markup pixfort's own components don't
	 * render — the native quantity field, chiefly. Those can't take a
	 * `text-{slug}` utility class, so the chosen palette entry is applied as the
	 * matching `--pix-*` variable through a selectors dictionary, which is the
	 * same trick pixfort itself uses for its button icon colour. That keeps the
	 * control theme-aware (light/dark, Dynamic Colors) instead of freezing a
	 * literal hex the way a raw Elementor colour picker would.
	 *
	 * @param array<string,mixed> $condition
	 */
	public static function palette_control( object $target, string $id, string $label, string $selector, string $property, array $condition = array(), string $extra = '' ): void {
		if ( ! self::available() ) {
			self::add( $target, $condition, $id . '_fallback', array(
				'label'     => $label,
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $selector => $property . ': {{VALUE}};' . $extra ),
			) );
			return;
		}

		$colors     = self::colors( array( 'defaultValue' => array( '' => __( 'Default', 'galaxie-woo' ) ), 'mainLight' => true, 'gradients' => false ) );
		$dictionary = array( '' => '' );

		foreach ( $colors as $group ) {
			if ( empty( $group['options'] ) || ! is_array( $group['options'] ) ) {
				continue;
			}
			foreach ( array_keys( $group['options'] ) as $value ) {
				if ( '' === $value || 'custom' === $value ) {
					continue;
				}
				$dictionary[ $value ] = $property . ': var(--pix-' . $value . ') !important;' . $extra;
			}
		}

		self::add( $target, $condition, $id, array(
			'label'                => $label,
			'type'                 => Controls_Manager::SELECT,
			'groups'               => $colors,
			'default'              => '',
			'selectors_dictionary' => $dictionary,
			'selectors'            => array( $selector => '{{VALUE}}' ),
		) );
	}

	/**
	 * Registers one control, folding the caller's blanket condition in.
	 *
	 * Deliberately only Elementor's simple `condition` map, never the richer
	 * `conditions` terms form: the terms evaluator is a far less travelled path
	 * inside a repeater row, and the map expresses everything needed here
	 * anyway — `'x!' => ''` for "not empty", an array value for "one of".
	 *
	 * @param array<string,mixed> $condition
	 * @param array<string,mixed> $args
	 */
	private static function add( object $target, array $condition, string $id, array $args ): void {
		if ( $condition ) {
			$args['condition'] = array_merge( $condition, $args['condition'] ?? array() );
		}

		$target->add_control( $id, $args );
	}

	/**
	 * Default + Pill + pixfort's own radius scale.
	 *
	 * The Pill class is `badge-pill`, not `rounded-pill` — the latter is
	 * Bootstrap 5's name and appears nowhere in this theme's CSS, so emitting
	 * it produced a control that silently did nothing.
	 *
	 * @return array<string,string>
	 */
	private static function radius_options(): array {
		$scale = function_exists( 'pixfort_get_border_radius_options' )
			? pixfort_get_border_radius_options()
			: array();

		return array_merge(
			array(
				''           => __( 'Default', 'galaxie-woo' ),
				'badge-pill' => __( 'Pill', 'galaxie-woo' ),
			),
			is_array( $scale ) ? $scale : array()
		);
	}

	/**
	 * pixfort's radius scale with nothing prepended — what its container-shaped
	 * elements (Alert, Img Box) offer. `radius_options()` above is the badge
	 * flavour of the same list, with Default and Pill in front.
	 *
	 * @return array<string,string>
	 */
	private static function container_radius_options(): array {
		$scale = function_exists( 'pixfort_get_border_radius_options' )
			? pixfort_get_border_radius_options()
			: array();

		return is_array( $scale ) && $scale
			? $scale
			: array( 'rounded-lg' => __( 'Normal', 'galaxie-woo' ) );
	}

	/**
	 * Shared by Button and Badge: pixfort offers the same hover animations on
	 * both, under the same numeric values.
	 *
	 * @return array<string,string>
	 */
	private static function hover_animations(): array {
		return array(
			''  => __( 'None', 'galaxie-woo' ),
			'1' => __( 'Fly Small', 'galaxie-woo' ),
			'2' => __( 'Fly Medium', 'galaxie-woo' ),
			'3' => __( 'Fly Large', 'galaxie-woo' ),
			'4' => __( 'Scale Small', 'galaxie-woo' ),
			'5' => __( 'Scale Medium', 'galaxie-woo' ),
			'6' => __( 'Scale Large', 'galaxie-woo' ),
			'7' => __( 'Scale Inverse Small', 'galaxie-woo' ),
			'8' => __( 'Scale Inverse Medium', 'galaxie-woo' ),
			'9' => __( 'Scale Inverse Large', 'galaxie-woo' ),
		);
	}

	/** @return array<string,string> */
	private static function shadow_options( string $none, string $noun ): array {
		return array(
			''  => $none,
			'1' => sprintf( /* translators: %s: "shadow" or "hover shadow". */ __( 'Small %s', 'galaxie-woo' ), $noun ),
			'2' => sprintf( /* translators: %s: "shadow" or "hover shadow". */ __( 'Medium %s', 'galaxie-woo' ), $noun ),
			'3' => sprintf( /* translators: %s: "shadow" or "hover shadow". */ __( 'Large %s', 'galaxie-woo' ), $noun ),
			'4' => sprintf( /* translators: %s: "shadow" or "hover shadow". */ __( 'Inverse Small %s', 'galaxie-woo' ), $noun ),
			'5' => sprintf( /* translators: %s: "shadow" or "hover shadow". */ __( 'Inverse Medium %s', 'galaxie-woo' ), $noun ),
			'6' => sprintf( /* translators: %s: "shadow" or "hover shadow". */ __( 'Inverse Large %s', 'galaxie-woo' ), $noun ),
		);
	}



	/**
	 * pixfort colours the icon through a CSS variable rather than a class, so
	 * each palette entry needs its own declaration in the dictionary.
	 *
	 * @param array<int,array<string,mixed>> $groups
	 * @return array<string,string>
	 */
	private static function icon_color_dictionary( array $groups ): array {
		$dictionary = array( '' => '', 'custom' => '' );

		foreach ( $groups as $group ) {
			if ( empty( $group['options'] ) || ! is_array( $group['options'] ) ) {
				continue;
			}
			foreach ( array_keys( $group['options'] ) as $value ) {
				if ( '' === $value || 'custom' === $value ) {
					continue;
				}
				$dictionary[ $value ] = 'color: var(--pix-' . $value . ', currentColor) !important; --pf-icon-color: var(--pix-' . $value . ', currentColor) !important;';
			}
		}

		return $dictionary;
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<int,array<string,mixed>>
	 */
	private static function colors( array $args ): array {
		return self::available() ? \PixfortCore::instance()->coreFunctions->getColorsArray( $args ) : array();
	}

	/** @return array<string,string> */
	private static function animations(): array {
		return function_exists( 'pix_get_animations' )
			? pix_get_animations( true )
			: array( '' => __( 'None', 'galaxie-woo' ) );
	}
}

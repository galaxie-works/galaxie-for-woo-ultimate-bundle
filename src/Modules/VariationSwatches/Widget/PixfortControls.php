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
			self::add( $target, $condition, $prefix . '_icon', array(
				'label'   => __( 'Button icon', 'galaxie-woo' ),
				'type'    => \Elementor\CustomControl\PixfortIconSelector_Control::PixfortIconSelector,
				'default' => $d( 'icon', '' ),
			) );

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
			'default'      => $d( 'bold', '' ),
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
			'default'      => $d( 'remove_pb_padding', 'm-0' ),
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

		self::add( $target, $condition, $prefix . '_rounded', array(
			'label'        => __( 'Rounded', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'rounded-pill',
			'default'      => $d( 'rounded', 'rounded-pill' ),
		) );

		self::add( $target, $condition, $prefix . '_bold', array(
			'label'        => __( 'Bold', 'galaxie-woo' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'font-weight-bold',
			'default'      => $d( 'bold', '' ),
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
			'animation', 'delay', 'extra_classes',
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
	public static function palette_control( object $target, string $id, string $label, string $selector, string $property, array $condition = array() ): void {
		if ( ! self::available() ) {
			self::add( $target, $condition, $id . '_fallback', array(
				'label'     => $label,
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $selector => $property . ': {{VALUE}};' ),
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
				$dictionary[ $value ] = $property . ': var(--pix-' . $value . ') !important;';
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

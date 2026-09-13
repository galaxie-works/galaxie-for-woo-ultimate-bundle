<?php
/**
 * The in-page dialog every widget asks and tells through.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * A confirmation ("Excluir este cartão?") or a notice ("Selecione uma
 * variação") drawn by the widget itself, instead of the browser's `confirm()`
 * and `alert()` — which carry the site's address as a title, look like nothing
 * else on the page and cannot be styled at all.
 *
 * Each widget prints its own `<dialog data-dialog="{prefix}">`, so its
 * Elementor controls style it like any other part of the widget: pixfort
 * buttons, pixfort text, the usual surface set. The script
 * (`frontend/src/lib/dialog.ts`) opens it as a modal, and falls back to a plain
 * dialog of its own when a widget has none, so no path ever reaches the
 * browser's.
 *
 * The message setting id is the prefix itself, which keeps the wording
 * merchants already saved under the old single-text confirmation controls.
 */
final class Dialog {

	/**
	 * @param array{
	 *   label:string,
	 *   text:string,
	 *   title?:string,
	 *   yes:string,
	 *   no?:string|null,
	 *   yes_defaults?:array<string,string>,
	 *   extra?:array<string,array{0:string,1:string}>
	 * } $args `no` null makes it a notice with a single button; `extra` adds
	 *         more messages the script picks by key. A question's confirm
	 *         button is red unless `yes_defaults` says otherwise.
	 */
	public static function controls( object $widget, string $prefix, array $args ): void {
		$widget->start_controls_section( $prefix . '_content_section', array( 'label' => $args['label'] ) );

		$widget->add_control( $prefix . '_title', array( 'label' => __( 'Title', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => $args['title'] ?? '' ) );
		$widget->add_control( $prefix, array( 'label' => __( 'Message', 'galaxie-woo' ), 'type' => Controls_Manager::TEXTAREA, 'rows' => 2, 'default' => $args['text'] ) );

		foreach ( $args['extra'] ?? array() as $key => $extra ) {
			$widget->add_control( $prefix . '_' . $key, array( 'label' => $extra[0], 'type' => Controls_Manager::TEXTAREA, 'rows' => 2, 'default' => $extra[1] ) );
		}

		$widget->add_control(
			$prefix . '_preview',
			array(
				'label'        => __( 'Show in the editor', 'galaxie-woo' ),
				'description'  => __( 'Keeps the dialog open inside the widget while you style it. Visitors only see it when it is needed.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'separator'    => 'before',
			)
		);

		$widget->end_controls_section();

		$buttons = array( 'yes' => array( __( 'Dialog: confirm button', 'galaxie-woo' ), $args['yes'], array( 'size' => 'sm' ) ) );

		if ( null !== ( $args['no'] ?? null ) ) {
			$buttons['yes'][2] = $args['yes_defaults'] ?? array( 'color' => 'red', 'text_color' => 'white', 'size' => 'sm' );
			$buttons['no']     = array( __( 'Dialog: cancel button', 'galaxie-woo' ), $args['no'], array( 'style' => 'link', 'size' => 'sm' ) );
		} else {
			$buttons['yes'][0] = __( 'Dialog: button', 'galaxie-woo' );
		}

		foreach ( $buttons as $key => $button ) {
			$widget->start_controls_section( $prefix . '_' . $key . '_section', array( 'label' => $button[0] ) );
			PixfortControls::button( $widget, $prefix . '_' . $key, array_merge( array( 'text' => $button[1] ), $button[2] ) );
			$widget->end_controls_section();
		}

		$box = '{{WRAPPER}} .galaxie-dialog[data-dialog="' . $prefix . '"]';

		$widget->start_controls_section( $prefix . '_box_style', array( 'label' => __( 'Dialog', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		PixfortControls::surface( $widget, $prefix . '_box', $box, array( 'rounded' => 'rounded-xl', 'shadow' => '4' ) );

		$widget->add_responsive_control(
			$prefix . '_width',
			array(
				'label'      => __( 'Width', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'vw' ),
				'range'      => array( 'px' => array( 'min' => 240, 'max' => 800 ) ),
				'separator'  => 'before',
				'selectors'  => array( $box => 'width: min({{SIZE}}{{UNIT}}, calc(100vw - 32px));' ),
			)
		);

		$widget->add_responsive_control(
			$prefix . '_align',
			array(
				'label'     => __( 'Alignment', 'galaxie-woo' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array( 'title' => __( 'Left', 'galaxie-woo' ), 'icon' => 'eicon-text-align-left' ),
					'center' => array( 'title' => __( 'Center', 'galaxie-woo' ), 'icon' => 'eicon-text-align-center' ),
					'right'  => array( 'title' => __( 'Right', 'galaxie-woo' ), 'icon' => 'eicon-text-align-right' ),
				),
				'selectors' => array(
					$box                                 => 'text-align: {{VALUE}};',
					$box . ' .galaxie-dialog-actions' => 'justify-content: {{VALUE}};',
				),
				'selectors_dictionary' => array( 'left' => 'left', 'center' => 'center', 'right' => 'right' ),
			)
		);

		$widget->add_responsive_control(
			$prefix . '_gap',
			array(
				'label'      => __( 'Space between parts', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'selectors'  => array( $box => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$widget->add_control(
			$prefix . '_backdrop',
			array(
				'label'     => __( 'Page overlay', 'galaxie-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $box . '::backdrop' => 'background-color: {{VALUE}};' ),
			)
		);

		$widget->end_controls_section();

		$texts = array(
			'title_text' => array( __( 'Dialog: title', 'galaxie-woo' ), '.galaxie-dialog-title', array( 'size' => 'text-18', 'bold' => 'font-weight-bold' ) ),
			'body_text'  => array( __( 'Dialog: message', 'galaxie-woo' ), '.galaxie-dialog-text', array( 'bold' => '' ) ),
		);

		foreach ( $texts as $key => $text ) {
			$widget->start_controls_section( $prefix . '_' . $key . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $widget, $prefix . '_' . $key, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), $box . ' ' . $text[1], 'text', array( 'position' ) );
			$widget->end_controls_section();
		}
	}

	/**
	 * The dialog, closed — or open in place while the merchant styles it.
	 *
	 * @param array<string,mixed> $s
	 * @param array<int,string>   $extra  The `extra` keys given to {@see controls()}.
	 */
	public static function render( array $s, string $prefix, bool $cancel = true, array $extra = array() ): string {
		$title   = trim( (string) ( $s[ $prefix . '_title' ] ?? '' ) );
		$preview = 'yes' === ( $s[ $prefix . '_preview' ] ?? '' ) && class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor->is_edit_mode();
		$texts   = array();

		foreach ( $extra as $key ) {
			$texts[ $key ] = (string) ( $s[ $prefix . '_' . $key ] ?? '' );
		}

		$button = static fn( string $key, string $class ): string => sprintf(
			'<button type="button" class="galaxie-dialog-button %1$s">%2$s</button>',
			esc_attr( $class ),
			PixfortControls::render_button( $s, $prefix . '_' . $key, (string) ( $s[ $prefix . '_' . $key . '_text' ] ?? '' ) )
		);

		return sprintf(
			'<dialog class="galaxie-dialog %1$s" data-dialog="%2$s" data-texts="%3$s"%4$s>%5$s<div class="galaxie-dialog-text">%6$s</div><div class="galaxie-dialog-actions">%7$s%8$s</div></dialog>',
			esc_attr( trim( PixfortControls::surface_classes( $s, $prefix . '_box' ) . ( $preview ? ' is-preview' : '' ) ) ),
			esc_attr( $prefix ),
			esc_attr( (string) wp_json_encode( $texts ) ),
			$preview ? ' open' : '',
			'' !== $title ? '<div class="galaxie-dialog-title">' . PixfortControls::render_text( $s, $prefix . '_title_text', esc_html( $title ) ) . '</div>' : '',
			PixfortControls::render_text( $s, $prefix . '_body_text', '<span class="galaxie-dialog-message">' . esc_html( (string) ( $s[ $prefix ] ?? '' ) ) . '</span>' ),
			$cancel ? $button( 'no', 'galaxie-dialog-cancel' ) : '',
			$button( 'yes', 'galaxie-dialog-confirm' )
		);
	}
}

<?php
/**
 * "Galaxie Account Interests": what the customer is into.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Integrations\FluentCRM as FluentCRMApi;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * The interests the store lists under Galaxie → FluentCRM, as pills that switch
 * on and off with one tap. Each is a FluentCRM tag, attached or removed at once
 * by `galaxie_myaccount_toggle_interest`, so a segment built on it is current
 * the moment the customer chooses.
 */
final class AccountInterestsWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account-interests';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Interests', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-tags';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'interests', 'interesses', 'fluentcrm', 'tags' );
	}

	/** @return array<int,array{tagId:int,label:string,icon:string,iconUrl:string}> */
	public static function options(): array {
		if ( ! Plugin::instance()->modules()->is_enabled_by_id( 'fluentcrm' ) ) {
			return array();
		}

		$settings = Plugin::instance()->settings()->module_settings( 'fluentcrm' );

		if ( empty( $settings['interests_enabled'] ) ) {
			return array();
		}

		$options = array();

		foreach ( (array) ( $settings['interest_options'] ?? array() ) as $row ) {
			$row = (array) $row;

			if ( empty( $row['tag_id'] ) || '' === (string) ( $row['label'] ?? '' ) ) {
				continue;
			}

			$options[] = array(
				'tagId'   => (int) $row['tag_id'],
				'label'   => (string) $row['label'],
				'icon'    => (string) ( $row['icon'] ?? '' ),
				'iconUrl' => (string) ( $row['icon_url'] ?? '' ),
			);
		}

		usort( $options, static fn( $a, $b ) => strcoll( $a['label'], $b['label'] ) );

		return $options;
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'interests_section', array( 'label' => __( 'Interests', 'galaxie-woo' ) ) );

		$this->add_control(
			'interests_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'The list itself is edited under Galaxie → FluentCRM → Interests.', 'galaxie-woo' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->add_control( 'interests_heading', array( 'label' => __( 'Heading', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Seus interesses', 'galaxie-woo' ) ) );
		$this->add_control( 'interests_intro', array( 'label' => __( 'Intro text', 'galaxie-woo' ), 'type' => Controls_Manager::TEXTAREA, 'rows' => 2, 'default' => __( 'Escolha o que combina com você. Usamos isso para mandar só novidades que interessam.', 'galaxie-woo' ) ) );
		$this->add_control( 'interests_sparkles', array( 'label' => __( 'Sparkles when choosing', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );

		$this->add_control(
			'interests_align',
			array(
				'label'     => __( 'Alignment', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'flex-start' => __( 'Start', 'galaxie-woo' ),
					'center'     => __( 'Center', 'galaxie-woo' ),
					'flex-end'   => __( 'End', 'galaxie-woo' ),
				),
				'default'   => 'flex-start',
				'selectors' => array( '{{WRAPPER}} .galaxie-interests-list' => 'justify-content: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'interests_box_style', array( 'label' => __( 'Box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'interests_box', '{{WRAPPER}} .galaxie-account-interests' );
		$this->end_controls_section();

		$texts = array(
			'interests_heading_text' => array( __( 'Heading', 'galaxie-woo' ), '.galaxie-interests-heading', array( 'size' => 'text-20', 'bold' => 'font-weight-bold' ), true ),
			'interests_intro_text'   => array( __( 'Intro text', 'galaxie-woo' ), '.galaxie-interests-intro', array( 'bold' => '' ), true ),
			'interests_pill_text'    => array( __( 'Pill text', 'galaxie-woo' ), '.galaxie-interest', array( 'size' => 'text-sm', 'bold' => '' ) ),
			'interests_msg_text'     => array( __( 'Error message', 'galaxie-woo' ), '.galaxie-account-message', array( 'size' => 'text-sm', 'bold' => '', 'content_color' => 'red' ) ),
		);

		// A fourth `true` marks text drawn by pixfort's Text element; the rest are
		// classes on this widget's own markup, which carry fewer controls.
		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', empty( $text[3] ) ? array( 'position', 'inline' ) : array( 'position' ) );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'interests_pill_style', array( 'label' => __( 'Pills', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		PixfortControls::surface( $this, 'interests_pill', '{{WRAPPER}} .galaxie-interest', array( 'rounded' => 'rounded-pill' ) );

		$this->add_responsive_control(
			'interests_gap',
			array(
				'label'      => __( 'Space between pills', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-interests-list' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control( 'interests_selected_heading', array( 'label' => __( 'Chosen', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $this, 'interests_selected_color', __( 'Text color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-interest.is-selected', 'color' );
		PixfortControls::palette_control( $this, 'interests_selected_bg', __( 'Background', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-interest.is-selected', 'background-color' );
		PixfortControls::palette_control( $this, 'interests_selected_border', __( 'Border color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-interest.is-selected', 'border-color', array(), ' border-style: solid; border-width: 1px;' );

		$this->end_controls_section();
	}

	protected function render(): void {
		if ( ! is_user_logged_in() && ! AccountParts::editing() ) {
			return;
		}

		$options = self::options();

		if ( ! $options ) {
			echo AccountParts::editor_note( __( 'No interests to show. Turn them on and list them under Galaxie → FluentCRM.', 'galaxie-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			return;
		}

		Assets::enqueue();

		$s        = $this->get_settings_for_display();
		$selected = array_map( 'intval', FluentCRMApi::contact_tag_ids( wp_get_current_user()->user_email ) );
		$pill     = trim( PixfortControls::text_classes( $s, 'interests_pill_text' ) . ' ' . PixfortControls::surface_classes( $s, 'interests_pill' ) );

		// data-msg-class: the script paints the message line with these when a
		// toggle fails, as it does for the details, communication and delete widgets.
		printf(
			'<div class="galaxie-account-interests %1$s" data-sparkles="%2$s" data-msg-class="%3$s">',
			esc_attr( PixfortControls::surface_classes( $s, 'interests_box' ) ),
			'yes' === ( $s['interests_sparkles'] ?? 'yes' ) ? '1' : '',
			esc_attr( PixfortControls::text_classes( $s, 'interests_msg_text' ) )
		);

		$heading = trim( (string) ( $s['interests_heading'] ?? '' ) );
		$intro   = trim( (string) ( $s['interests_intro'] ?? '' ) );

		if ( '' !== $heading ) {
			printf( '<div class="galaxie-interests-heading">%s</div>', PixfortControls::render_text( $s, 'interests_heading_text', esc_html( $heading ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		if ( '' !== $intro ) {
			printf( '<div class="galaxie-interests-intro">%s</div>', PixfortControls::render_text( $s, 'interests_intro_text', esc_html( $intro ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		echo '<div class="galaxie-account-message" role="status" aria-live="polite" hidden></div><div class="galaxie-interests-list">';

		foreach ( $options as $option ) {
			$on   = in_array( $option['tagId'], $selected, true );
			$icon = '' !== $option['iconUrl']
				? sprintf( '<img class="galaxie-interest-image" src="%s" alt="" />', esc_url( $option['iconUrl'] ) )
				: ( '' !== $option['icon'] ? sprintf( '<span class="galaxie-interest-emoji" aria-hidden="true">%s</span>', esc_html( $option['icon'] ) ) : '' );

			printf(
				'<button type="button" class="galaxie-interest %1$s%2$s" data-tag="%3$d" aria-pressed="%4$s">%5$s<span>%6$s</span></button>',
				esc_attr( $pill ),
				$on ? ' is-selected' : '',
				(int) $option['tagId'],
				$on ? 'true' : 'false',
				$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				esc_html( $option['label'] )
			);
		}

		echo '</div></div>';
	}
}

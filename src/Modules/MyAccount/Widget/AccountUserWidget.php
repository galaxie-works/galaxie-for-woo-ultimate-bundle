<?php
/**
 * "Galaxie Account User": who is signed in.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\PixfortControls;
use Galaxie\Woo\Support\ProfileFields;

defined( 'ABSPATH' ) || exit;

/**
 * The avatar and a greeting, for the top of the account menu or of a dashboard.
 *
 * The greeting and the line under it are sentences with placeholders rather
 * than switches for each detail, so "Olá, Maria" and "Cliente desde março de
 * 2025" are both written by the merchant, in the store's voice.
 */
final class AccountUserWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account-user';
	}

	public function get_title(): string {
		return __( 'Galaxie Account User', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-person';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'user', 'avatar', 'greeting', 'conta', 'woocommerce' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'user_section', array( 'label' => __( 'User', 'galaxie-woo' ) ) );

		$this->add_control(
			'user_placeholders',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'Placeholders: {first_name}, {name}, {email}, {since}. {name} is the social name when the customer has one.', 'galaxie-woo' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->add_control(
			'user_show_avatar',
			array(
				'label'        => __( 'Avatar', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'user_greeting',
			array(
				'label'       => __( 'Greeting', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Olá, {first_name}!', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'user_subtitle',
			array(
				'label'       => __( 'Line below', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( '{email}', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'user_layout',
			array(
				'label'     => __( 'Layout', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'inline'  => __( 'Avatar beside the text', 'galaxie-woo' ),
					'stacked' => __( 'Avatar above the text', 'galaxie-woo' ),
				),
				'default'   => 'inline',
				'separator' => 'before',
			)
		);

		$this->add_control(
			'user_align',
			array(
				'label'   => __( 'Alignment', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'start'  => __( 'Start', 'galaxie-woo' ),
					'center' => __( 'Center', 'galaxie-woo' ),
					'end'    => __( 'End', 'galaxie-woo' ),
				),
				'default' => 'start',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'user_box_style', array( 'label' => __( 'Box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'user_box', '{{WRAPPER}} .galaxie-account-user' );

		$this->add_responsive_control(
			'user_gap',
			array(
				'label'      => __( 'Space between avatar and text', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-account-user' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'user_avatar_style',
			array( 'label' => __( 'Avatar', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'user_show_avatar' => 'yes' ) )
		);
		PixfortControls::thumb( $this, 'user_avatar', '{{WRAPPER}} .galaxie-account-user-avatar img', array( 'size' => 56, 'rounded' => 'custom', 'radius' => 80 ), array(), '{{WRAPPER}} .galaxie-account-user-avatar' );
		$this->end_controls_section();

		$this->start_controls_section( 'user_greeting_style', array( 'label' => __( 'Greeting', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $this, 'user_greeting', array( 'size' => 'text-20', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-account-user-greeting', 'text', array( 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'user_subtitle_style', array( 'label' => __( 'Line below', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::text( $this, 'user_subtitle', array( 'size' => 'text-sm', 'bold' => '', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-account-user-subtitle', 'text', array( 'position' ) );
		$this->end_controls_section();
	}

	private static function fill( string $text, \WP_User $user ): string {
		$social = (string) get_user_meta( $user->ID, ProfileFields::SOCIAL_NAME, true );
		$first  = '' !== $user->first_name ? $user->first_name : $user->display_name;

		return strtr(
			$text,
			array(
				'{first_name}' => $first,
				'{name}'       => '' !== $social ? $social : trim( $user->first_name . ' ' . $user->last_name ),
				'{email}'      => $user->user_email,
				'{since}'      => date_i18n( 'F \d\e Y', strtotime( $user->user_registered ) ),
			)
		);
	}

	protected function render(): void {
		if ( ! is_user_logged_in() && ! AccountParts::editing() ) {
			return;
		}

		Assets::enqueue();

		$settings = $this->get_settings_for_display();
		$user     = wp_get_current_user();
		$greeting = trim( self::fill( (string) ( $settings['user_greeting'] ?? '' ), $user ) );
		$subtitle = trim( self::fill( (string) ( $settings['user_subtitle'] ?? '' ), $user ) );
		$align    = in_array( $settings['user_align'] ?? 'start', array( 'start', 'center', 'end' ), true ) ? (string) $settings['user_align'] : 'start';

		printf(
			'<div class="galaxie-account-user is-%1$s is-align-%2$s %3$s">',
			'stacked' === ( $settings['user_layout'] ?? 'inline' ) ? 'stacked' : 'inline',
			esc_attr( $align ),
			esc_attr( PixfortControls::surface_classes( $settings, 'user_box' ) )
		);

		if ( 'yes' === ( $settings['user_show_avatar'] ?? 'yes' ) ) {
			printf(
				'<div class="galaxie-account-user-avatar %1$s">%2$s</div>',
				esc_attr( PixfortControls::thumb_classes( $settings, 'user_avatar' ) ),
				get_avatar( $user->ID, 160, '', $greeting ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress's own avatar markup.
			);
		}

		echo '<div class="galaxie-account-user-text">';

		if ( '' !== $greeting ) {
			printf( '<div class="galaxie-account-user-greeting">%s</div>', PixfortControls::render_text( $settings, 'user_greeting', esc_html( $greeting ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		if ( '' !== $subtitle ) {
			printf( '<div class="galaxie-account-user-subtitle">%s</div>', PixfortControls::render_text( $settings, 'user_subtitle', esc_html( $subtitle ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		echo '</div></div>';
	}
}

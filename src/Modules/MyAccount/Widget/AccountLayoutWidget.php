<?php
/**
 * "Galaxie Account": the My Account menu and screen, laid out as pixfort's tabs.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * The composition of pixfort's Vertical Tabs, a `row` with the menu in a
 * `col-md-4` and the content beside it, or of its Horizontal Tabs, with the
 * menu above. The difference from those widgets is where the content comes
 * from: not panes already on the page, but the screen the address names, drawn
 * by the server. See {@see AccountParts} for why.
 */
final class AccountLayoutWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account';
	}

	public function get_title(): string {
		return __( 'Galaxie Account', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-tabs';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'my account', 'tabs', 'vertical tabs', 'conta', 'woocommerce' );
	}

	protected function register_controls(): void {
		AccountParts::register_menu_controls( $this );

		$this->start_controls_section( 'layout_section', array( 'label' => __( 'Menu and content', 'galaxie-woo' ) ) );

		$this->add_control(
			'layout_menu_width',
			array(
				'label'     => __( 'Menu width', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'3' => __( 'A quarter', 'galaxie-woo' ),
					'4' => __( 'A third', 'galaxie-woo' ),
					'5' => __( 'Five twelfths', 'galaxie-woo' ),
					'6' => __( 'Half', 'galaxie-woo' ),
				),
				'default'   => '4',
				'condition' => array( 'menu_layout' => 'vertical' ),
			)
		);

		$this->add_control(
			'layout_menu_side',
			array(
				'label'     => __( 'Menu position', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'start' => __( 'Start', 'galaxie-woo' ),
					'end'   => __( 'End', 'galaxie-woo' ),
				),
				'default'   => 'start',
				'condition' => array( 'menu_layout' => 'vertical' ),
			)
		);

		$this->add_responsive_control(
			'layout_gap',
			array(
				'label'      => __( 'Space between menu and content', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 120 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 30 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-account-layout' => '--galaxie-account-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		AccountParts::register_content_controls( $this );
		AccountParts::register_menu_style_controls( $this );
		AccountParts::register_content_style_controls( $this );
	}

	protected function render(): void {
		Assets::enqueue();

		$settings = $this->get_settings_for_display();
		$content  = AccountParts::render_content( $settings );

		// Signed out there is no menu to show, only the sign-in form.
		if ( ! is_user_logged_in() && ! AccountParts::editing() ) {
			echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's and Elementor's own output.
			return;
		}

		$menu     = AccountParts::render_menu( $settings );
		$vertical = 'horizontal' !== ( $settings['menu_layout'] ?? 'vertical' );
		$width    = in_array( (string) ( $settings['layout_menu_width'] ?? '4' ), array( '3', '4', '5', '6' ), true ) ? (int) $settings['layout_menu_width'] : 4;

		if ( ! $vertical ) {
			printf(
				'<div class="galaxie-account-layout is-horizontal"><div class="galaxie-account-layout-menu">%1$s</div><div class="galaxie-account-layout-content">%2$s</div></div>',
				$menu, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_menu().
				$content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's and Elementor's own output.
			);
			return;
		}

		printf(
			'<div class="galaxie-account-layout is-vertical row%1$s"><div class="galaxie-account-layout-menu col-12 col-md-%2$d">%3$s</div><div class="galaxie-account-layout-content col-12 col-md-%4$d">%5$s</div></div>',
			'end' === ( $settings['layout_menu_side'] ?? 'start' ) ? ' is-menu-end' : '',
			$width,
			$menu, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_menu().
			12 - $width,
			$content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's and Elementor's own output.
		);
	}
}

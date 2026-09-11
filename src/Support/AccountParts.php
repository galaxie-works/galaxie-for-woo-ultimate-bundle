<?php
/**
 * The account menu and the account content, shared by the widgets that show them.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

use Elementor\Controls_Manager;
use Elementor\Repeater;

defined( 'ABSPATH' ) || exit;

/**
 * Controls and markup for the two halves of My Account.
 *
 * They live here, like the cart's in {@see CartParts}, because they are shown
 * two ways: as separate widgets placed anywhere, and together in the Account
 * widget, which lays them out the way pixfort's Vertical and Horizontal Tabs
 * do. One set of controls and one renderer means the two can never drift.
 *
 * The menu is pixfort's tabs composition: `nav nav-pills pix_tabs_btns` with
 * one of its `pix-pills-*` styles, `nav-item` wrappers and `nav-link` buttons
 * that take `active`. That is what gives it the theme's own tab looks, palette
 * and dark mode included. Two things are deliberately left out: the
 * `pix-tabs-btn` class and `data-toggle="pill"`. pixfort's tabs script claims
 * every click on those to swap panes that are already on the page, and each
 * account screen is its own address with its own server-rendered content:
 * orders paginate, an order opens by number, the address forms post and reload.
 */
final class AccountParts {

	private const PILL_STYLES = array(
		'pix-pills-1'       => 'Default (Gradient)',
		'pix-pills-solid'   => 'Solid',
		'pix-pills-light'   => 'Light',
		'pix-pills-outline' => 'Outline',
		'pix-pills-line'    => 'Line',
		'pix-pills-round'   => 'Round',
		'pix-pills-lines'   => 'Lines',
	);

	public static function available(): bool {
		return function_exists( 'WC' ) && function_exists( 'wc_get_account_menu_items' );
	}

	/** In the Elementor editor or its preview, where the designer needs to see something. */
	public static function editing(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$elementor = \Elementor\Plugin::$instance;

		return ( $elementor->editor && $elementor->editor->is_edit_mode() )
			|| ( $elementor->preview && $elementor->preview->is_preview_mode() );
	}

	// =================================================================== menu

	public static function register_menu_controls( object $widget ): void {
		$widget->start_controls_section( 'menu_section', array( 'label' => __( 'Menu', 'galaxie-woo' ) ) );

		$screens = array();

		foreach ( AccountEndpoints::all() as $key => $screen ) {
			if ( 'view-order' === $key ) {
				continue;
			}

			$screens[ $key ] = $screen['enabled']
				? $screen['label']
				: sprintf( /* translators: %s: screen name. */ __( '%s (hidden)', 'galaxie-woo' ), $screen['label'] );
		}

		$repeater = new Repeater();

		$repeater->add_control(
			'item_type',
			array(
				'label'   => __( 'Item', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'endpoint' => __( 'Screen', 'galaxie-woo' ),
					'link'     => __( 'Link', 'galaxie-woo' ),
					'heading'  => __( 'Group title', 'galaxie-woo' ),
					'divider'  => __( 'Divider', 'galaxie-woo' ),
				),
				'default' => 'endpoint',
			)
		);

		$repeater->add_control(
			'endpoint',
			array(
				'label'       => __( 'Screen', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $screens,
				'default'     => 'dashboard',
				'description' => __( 'Screens are created, renamed and hidden under Galaxie → My Account.', 'galaxie-woo' ),
				'condition'   => array( 'item_type' => 'endpoint' ),
			)
		);

		$repeater->add_control(
			'label',
			array(
				'label'       => __( 'Text', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => __( 'The screen\'s name', 'galaxie-woo' ),
				'dynamic'     => array( 'active' => true ),
				'condition'   => array( 'item_type!' => 'divider' ),
			)
		);

		$repeater->add_control(
			'link',
			array(
				'label'     => __( 'Link', 'galaxie-woo' ),
				'type'      => Controls_Manager::URL,
				'dynamic'   => array( 'active' => true ),
				'condition' => array( 'item_type' => 'link' ),
			)
		);

		PixfortControls::icon_select( $repeater, 'icon', __( 'pixfort Icon', 'galaxie-woo' ), '', array( 'item_type' => array( 'endpoint', 'link' ) ) );

		$defaults = array();

		foreach ( AccountEndpoints::all() as $key => $screen ) {
			if ( 'view-order' === $key || ! $screen['enabled'] ) {
				continue;
			}

			$defaults[] = array(
				'item_type' => 'endpoint',
				'endpoint'  => $key,
				'icon'      => AccountEndpoints::ICONS[ $key ] ?? 'Line/pixfort-icon-bookmark-1',
			);
		}

		$widget->add_control(
			'menu_items',
			array(
				'label'       => __( 'Items', 'galaxie-woo' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => $defaults,
				'title_field' => '{{{ label || ( item_type === "endpoint" ? endpoint : item_type ) }}}',
			)
		);

		$widget->add_control(
			'menu_layout',
			array(
				'label'     => __( 'Layout', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'vertical'   => __( 'Vertical tabs', 'galaxie-woo' ),
					'horizontal' => __( 'Horizontal tabs', 'galaxie-woo' ),
				),
				'default'   => 'vertical',
				'separator' => 'before',
			)
		);

		$widget->add_control(
			'menu_style',
			array(
				'label'   => __( 'Style', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => self::PILL_STYLES,
				'default' => 'pix-pills-light',
			)
		);

		$widget->add_control(
			'menu_fill',
			array(
				'label'        => __( 'Full width buttons', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'nav-fill',
				'default'      => '',
				'condition'    => array( 'menu_layout' => 'horizontal' ),
			)
		);

		$widget->add_control(
			'menu_position',
			array(
				'label'     => __( 'Position', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'justify-content-start'  => __( 'Start', 'galaxie-woo' ),
					'justify-content-center' => __( 'Center', 'galaxie-woo' ),
					'justify-content-end'    => __( 'End', 'galaxie-woo' ),
				),
				'default'   => 'justify-content-start',
				'condition' => array( 'menu_layout' => 'horizontal' ),
			)
		);

		$widget->add_control(
			'menu_show_icons',
			array(
				'label'        => __( 'Icons', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$widget->add_control(
			'menu_icon_position',
			array(
				'label'     => __( 'Icon position', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					''    => __( 'Beside the text', 'galaxie-woo' ),
					'top' => __( 'Above the text', 'galaxie-woo' ),
				),
				'default'   => '',
				'condition' => array( 'menu_show_icons' => 'yes' ),
			)
		);

		$widget->add_control(
			'menu_sticky',
			array(
				'label'        => __( 'Sticky menu', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'condition'    => array( 'menu_layout' => 'vertical' ),
			)
		);

		$widget->add_responsive_control(
			'menu_sticky_top',
			array(
				'label'      => __( 'Distance from the top', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 300 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 110 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-account-menu.is-sticky' => 'top: {{SIZE}}{{UNIT}};' ),
				'condition'  => array(
					'menu_layout' => 'vertical',
					'menu_sticky' => 'yes',
				),
			)
		);

		$widget->add_control(
			'menu_mobile',
			array(
				'label'       => __( 'On phones', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'scroll' => __( 'One row that scrolls sideways', 'galaxie-woo' ),
					'select' => __( 'A dropdown', 'galaxie-woo' ),
					'stack'  => __( 'As on larger screens', 'galaxie-woo' ),
				),
				'default'     => 'scroll',
				'separator'   => 'before',
			)
		);

		$widget->end_controls_section();
	}

	public static function register_menu_style_controls( object $widget ): void {
		$menu = '{{WRAPPER}} .galaxie-account-menu';
		$link = $menu . ' .nav.nav-pills .nav-link';

		$widget->start_controls_section( 'menu_box_style', array( 'label' => __( 'Menu box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $widget, 'menu_box', $menu );
		$widget->end_controls_section();

		$widget->start_controls_section( 'menu_items_style', array( 'label' => __( 'Menu items', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		PixfortControls::text( $widget, 'menu_item', array( 'bold' => '', 'remove_pb_padding' => 'm-0' ), array(), $link, 'text', array( 'position' ) );

		$widget->add_responsive_control(
			'menu_item_gap',
			array(
				'label'      => __( 'Space between items', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( $menu . ' .nav.nav-pills' => 'gap: {{SIZE}}{{UNIT}};' ),
				'separator'  => 'before',
			)
		);

		$widget->add_control( 'menu_item_surface_heading', array( 'label' => __( 'Button', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::surface( $widget, 'menu_item', $link );

		$widget->add_control(
			'menu_item_opacity',
			array(
				'label'     => __( 'Inactive opacity', 'galaxie-woo' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array( 'px' => array( 'min' => 0.2, 'max' => 1, 'step' => 0.05 ) ),
				'selectors' => array( $link . ':not(.active)' => 'opacity: {{SIZE}};' ),
			)
		);

		$widget->add_control( 'menu_hover_heading', array( 'label' => __( 'Hover', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $widget, 'menu_hover_color', __( 'Text color', 'galaxie-woo' ), $link . ':not(.active):hover', 'color' );
		PixfortControls::palette_control( $widget, 'menu_hover_bg', __( 'Background', 'galaxie-woo' ), $link . ':not(.active):hover', 'background' );

		$widget->add_control( 'menu_active_heading', array( 'label' => __( 'Current screen', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $widget, 'menu_active_color', __( 'Text color', 'galaxie-woo' ), $link . '.active', 'color' );
		PixfortControls::palette_control( $widget, 'menu_active_bg', __( 'Background', 'galaxie-woo' ), $link . '.active', 'background' );
		PixfortControls::palette_control( $widget, 'menu_active_border', __( 'Border color', 'galaxie-woo' ), $link . '.active', 'border-color', array(), ' border-style: solid; border-width: 1px;' );

		$widget->add_control(
			'menu_active_bold',
			array(
				'label'        => __( 'Bold', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'selectors'    => array( $link . '.active' => 'font-weight: 700;' ),
			)
		);

		$widget->end_controls_section();

		$widget->start_controls_section(
			'menu_icons_style',
			array( 'label' => __( 'Menu icons', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'menu_show_icons' => 'yes' ) )
		);

		$widget->add_responsive_control(
			'menu_icon_size',
			array(
				'label'      => __( 'Size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 12, 'max' => 48 ) ),
				'selectors'  => array( $link . ' .pixfort-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$widget->add_responsive_control(
			'menu_icon_gap',
			array(
				'label'      => __( 'Space to the text', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array( $link => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		PixfortControls::icon_color( $widget, 'menu_icon_color', __( 'Color', 'galaxie-woo' ), $link . ' .pixfort-icon' );
		PixfortControls::icon_color( $widget, 'menu_icon_active_color', __( 'Current screen color', 'galaxie-woo' ), $link . '.active .pixfort-icon' );

		$widget->end_controls_section();

		$widget->start_controls_section( 'menu_groups_style', array( 'label' => __( 'Group titles and dividers', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		PixfortControls::text( $widget, 'menu_heading', array( 'size' => 'text-xs', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), $menu . ' .galaxie-account-menu-heading', 'text', array( 'position' ) );

		$widget->add_control( 'menu_divider_heading', array( 'label' => __( 'Divider', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $widget, 'menu_divider_color', __( 'Color', 'galaxie-woo' ), $menu . ' .galaxie-account-menu-divider', 'background-color' );

		$widget->add_responsive_control(
			'menu_divider_space',
			array(
				'label'      => __( 'Space around', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array(
					$menu . '.is-vertical .galaxie-account-menu-divider'   => 'margin: {{SIZE}}{{UNIT}} 0;',
					$menu . '.is-horizontal .galaxie-account-menu-divider' => 'margin: 0 {{SIZE}}{{UNIT}};',
				),
			)
		);

		$widget->end_controls_section();

		$widget->start_controls_section(
			'menu_select_style',
			array( 'label' => __( 'Phone dropdown', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'menu_mobile' => 'select' ) )
		);
		PixfortControls::surface( $widget, 'menu_select', $menu . ' .galaxie-account-menu-select' );
		$widget->end_controls_section();
	}

	/**
	 * The screen the menu marks as current. An order's own page belongs to Orders.
	 */
	private static function active_key(): string {
		$key = AccountEndpoints::current()['key'];

		$parents = array(
			'view-order'         => 'orders',
			'add-payment-method' => 'payment-methods',
		);

		return $parents[ $key ] ?? $key;
	}

	/** @param array<string,mixed> $settings */
	public static function render_menu( array $settings ): string {
		if ( ! self::available() || ( ! is_user_logged_in() && ! self::editing() ) ) {
			return '';
		}

		$items = array_values( array_filter( (array) ( $settings['menu_items'] ?? array() ), 'is_array' ) );

		if ( ! $items ) {
			foreach ( array_keys( AccountEndpoints::all() ) as $key ) {
				if ( 'view-order' !== $key ) {
					$items[] = array( 'item_type' => 'endpoint', 'endpoint' => $key, 'icon' => AccountEndpoints::ICONS[ $key ] ?? '' );
				}
			}
		}

		$vertical  = 'horizontal' !== ( $settings['menu_layout'] ?? 'vertical' );
		$style     = array_key_exists( (string) ( $settings['menu_style'] ?? '' ), self::PILL_STYLES ) ? (string) $settings['menu_style'] : 'pix-pills-light';
		$icons     = 'yes' === ( $settings['menu_show_icons'] ?? 'yes' );
		$top       = $icons && 'top' === ( $settings['menu_icon_position'] ?? '' );
		$mobile    = in_array( $settings['menu_mobile'] ?? 'scroll', array( 'scroll', 'select', 'stack' ), true ) ? (string) $settings['menu_mobile'] : 'scroll';
		$sticky    = $vertical && 'yes' === ( $settings['menu_sticky'] ?? '' );
		$active    = self::active_key();
		$link_base = trim(
			implode(
				' ',
				array(
					'nav-link',
					$top ? 'is-icon-top' : '',
					PixfortControls::text_classes( $settings, 'menu_item' ),
					PixfortControls::surface_classes( $settings, 'menu_item' ),
				)
			)
		);

		$nav_classes = array( 'nav', 'nav-pills', 'pix_tabs_btns', $style );

		if ( $vertical ) {
			$nav_classes[] = 'flex-column';
		} else {
			$nav_classes[] = (string) ( $settings['menu_position'] ?? 'justify-content-start' );
			$nav_classes[] = (string) ( $settings['menu_fill'] ?? '' );
		}

		$links   = '';
		$options = '';

		foreach ( $items as $item ) {
			$type  = (string) ( $item['item_type'] ?? 'endpoint' );
			$label = trim( (string) ( $item['label'] ?? '' ) );

			if ( 'divider' === $type ) {
				$links .= '<div class="nav-item galaxie-account-menu-divider" role="separator"></div>';
				continue;
			}

			if ( 'heading' === $type ) {
				if ( '' !== $label ) {
					$links .= sprintf(
						'<div class="nav-item galaxie-account-menu-heading %1$s">%2$s</div>',
						esc_attr( PixfortControls::text_classes( $settings, 'menu_heading' ) ),
						esc_html( $label )
					);
				}
				continue;
			}

			$attrs     = '';
			$is_active = false;

			if ( 'link' === $type ) {
				$link = (array) ( $item['link'] ?? array() );
				$url  = (string) ( $link['url'] ?? '' );

				if ( '' === $url || '' === $label ) {
					continue;
				}

				if ( ! empty( $link['is_external'] ) ) {
					$attrs .= ' target="_blank"';
				}

				if ( ! empty( $link['nofollow'] ) || ! empty( $link['is_external'] ) ) {
					$attrs .= ' rel="' . ( ! empty( $link['nofollow'] ) ? 'nofollow ' : '' ) . 'noopener"';
				}
			} else {
				$key = (string) ( $item['endpoint'] ?? '' );

				if ( ! AccountEndpoints::get( $key ) || ! AccountEndpoints::is_enabled( $key ) ) {
					continue;
				}

				$url       = AccountEndpoints::url( $key );
				$label     = '' !== $label ? $label : AccountEndpoints::label( $key );
				$is_active = $key === $active;
			}

			$icon = '';

			if ( $icons && '' !== (string) ( $item['icon'] ?? '' ) && class_exists( '\PixfortCore' ) ) {
				$icon = (string) \PixfortCore::instance()->icons->getIcon( (string) $item['icon'], 24, 'galaxie-account-menu-icon' );
			}

			$links .= sprintf(
				'<div class="nav-item"><a class="%1$s" href="%2$s"%3$s%4$s>%5$s<span class="galaxie-account-menu-label">%6$s</span></a></div>',
				esc_attr( $link_base . ( $is_active ? ' active' : '' ) ),
				esc_url( $url ),
				$is_active ? ' aria-current="page"' : '',
				$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute strings.
				$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own icon markup.
				esc_html( $label )
			);

			$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_url( $url ), selected( $is_active, true, false ), esc_html( $label ) );
		}

		// The Lines style draws a rule either side of a horizontal row, as
		// pixfort's own Horizontal Tabs does.
		$line = ! $vertical && 'pix-pills-lines' === $style
			? '<div class="nav-item galaxie-account-menu-line d-none d-sm-block flex-fill align-self-center"><span class="w-100 pix-tabs-line"></span></div>'
			: '';

		$html = sprintf(
			'<nav class="%1$s" aria-label="%2$s"><div class="%3$s">%4$s%5$s%4$s</div>',
			esc_attr(
				trim(
					implode(
						' ',
						array(
							'galaxie-account-menu',
							$vertical ? 'is-vertical' : 'is-horizontal',
							'is-mobile-' . $mobile,
							$sticky ? 'is-sticky sticky-top' : '',
							PixfortControls::surface_classes( $settings, 'menu_box' ),
						)
					)
				)
			),
			esc_attr__( 'Minha conta', 'galaxie-woo' ),
			esc_attr( trim( implode( ' ', array_filter( $nav_classes ) ) ) ),
			$line, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			$links // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);

		if ( 'select' === $mobile && '' !== $options ) {
			$html .= sprintf(
				'<select class="galaxie-account-menu-select form-control %1$s" aria-label="%2$s">%3$s</select>',
				esc_attr( PixfortControls::surface_classes( $settings, 'menu_select' ) ),
				esc_attr__( 'Minha conta', 'galaxie-woo' ),
				$options // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			);
		}

		return $html . '</nav>';
	}

	// ================================================================ content

	public static function register_content_controls( object $widget ): void {
		$widget->start_controls_section( 'content_section', array( 'label' => __( 'Screen content', 'galaxie-woo' ) ) );

		$widget->add_control(
			'content_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'Shows the screen the customer is on. Pick a template for a screen under Galaxie → My Account; without one, WooCommerce\'s own screen is shown.', 'galaxie-woo' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$screens = array();

		foreach ( AccountEndpoints::all() as $key => $screen ) {
			if ( 'customer-logout' !== $key ) {
				$screens[ $key ] = $screen['label'];
			}
		}

		$widget->add_control(
			'content_preview',
			array(
				'label'       => __( 'Screen shown in the editor', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $screens,
				'default'     => 'dashboard',
				'description' => __( 'Only for designing here. On the site, the screen follows the address.', 'galaxie-woo' ),
			)
		);

		$widget->add_control(
			'content_show_title',
			array(
				'label'        => __( 'Screen title', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'separator'    => 'before',
			)
		);

		$widget->add_control(
			'content_notices',
			array(
				'label'        => __( 'WooCommerce messages', 'galaxie-woo' ),
				'description'  => __( '"Address changed successfully" and the like, printed above the screen.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$widget->add_control(
			'content_logged_out',
			array(
				'label'     => __( 'Signed-out visitors see', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'native'   => __( 'WooCommerce\'s sign-in form', 'galaxie-woo' ),
					'template' => __( 'An Elementor template', 'galaxie-woo' ),
					'nothing'  => __( 'Nothing', 'galaxie-woo' ),
				),
				'default'   => 'native',
				'separator' => 'before',
			)
		);

		$widget->add_control(
			'content_logged_out_template',
			array(
				'label'     => __( 'Template', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array( '' => __( 'Choose…', 'galaxie-woo' ) ) + AccountEndpoints::templates(),
				'default'   => '',
				'condition' => array( 'content_logged_out' => 'template' ),
			)
		);

		$widget->end_controls_section();
	}

	public static function register_content_style_controls( object $widget ): void {
		$widget->start_controls_section( 'content_box_style', array( 'label' => __( 'Content box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $widget, 'content_box', '{{WRAPPER}} .galaxie-account-content' );
		$widget->end_controls_section();

		$widget->start_controls_section(
			'content_title_style',
			array( 'label' => __( 'Screen title', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'content_show_title' => 'yes' ) )
		);

		PixfortControls::text( $widget, 'content_title', array( 'size' => 'text-24', 'bold' => 'font-weight-bold' ), array(), '{{WRAPPER}} .galaxie-account-content-title' );

		$widget->add_responsive_control(
			'content_title_space',
			array(
				'label'      => __( 'Space below', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-account-content-title' => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * The screen to show: the one in the address, or in the editor the one
	 * chosen for designing.
	 *
	 * @param array<string,mixed> $settings
	 * @return array{key:string,value:string}
	 */
	private static function screen( array $settings ): array {
		if ( ! self::editing() ) {
			return AccountEndpoints::current();
		}

		$key = (string) ( $settings['content_preview'] ?? 'dashboard' );

		if ( ! AccountEndpoints::get( $key ) ) {
			return array( 'key' => 'dashboard', 'value' => '' );
		}

		$value = '';

		// An order's page needs an order: the most recent one of whoever is designing.
		if ( 'view-order' === $key ) {
			$orders = wc_get_orders( array( 'customer_id' => get_current_user_id(), 'limit' => 1, 'return' => 'ids' ) );
			$value  = $orders ? (string) $orders[0] : '';
		}

		return array( 'key' => $key, 'value' => $value );
	}

	/** @param array<string,mixed> $settings */
	public static function render_content( array $settings ): string {
		if ( ! self::available() ) {
			return '';
		}

		$box = PixfortControls::surface_classes( $settings, 'content_box' );

		if ( ! is_user_logged_in() ) {
			$mode = (string) ( $settings['content_logged_out'] ?? 'native' );
			$html = '';

			if ( 'native' === $mode ) {
				$html = do_shortcode( '[woocommerce_my_account]' );
			} elseif ( 'template' === $mode && absint( $settings['content_logged_out_template'] ?? 0 ) && class_exists( '\Elementor\Plugin' ) ) {
				$html = (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( absint( $settings['content_logged_out_template'] ), true );
			}

			return '' === $html ? '' : sprintf( '<div class="galaxie-account-content is-signed-out %1$s">%2$s</div>', esc_attr( $box ), $html );
		}

		$screen = self::screen( $settings );
		$html   = '';

		if ( 'yes' === ( $settings['content_show_title'] ?? '' ) ) {
			$title = 'dashboard' === $screen['key'] || ! WC()->query
				? AccountEndpoints::label( $screen['key'] )
				: wp_strip_all_tags( (string) WC()->query->get_endpoint_title( $screen['key'] ) );

			if ( '' === $title ) {
				$title = AccountEndpoints::label( $screen['key'] );
			}

			$html .= sprintf(
				'<div class="galaxie-account-content-title">%s</div>',
				PixfortControls::render_text( $settings, 'content_title', esc_html( $title ) )
			);
		}

		if ( 'yes' === ( $settings['content_notices'] ?? 'yes' ) && function_exists( 'wc_print_notices' ) && ! self::editing() ) {
			$html .= (string) wc_print_notices( true );
		}

		$html .= AccountEndpoints::render( $screen['key'], $screen['value'] );

		return sprintf(
			'<div class="galaxie-account-content woocommerce %1$s" data-account-screen="%2$s">%3$s</div>',
			esc_attr( $box ),
			esc_attr( $screen['key'] ),
			$html
		);
	}
}

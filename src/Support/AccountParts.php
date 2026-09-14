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

		$repeater->add_control(
			'template',
			array(
				'label'       => __( 'Template', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array( '' => __( 'As set under Galaxie → My Account', 'galaxie-woo' ) ) + AccountEndpoints::templates(),
				'default'     => '',
				'description' => __( 'Draws this screen from a template of your own, wherever this menu is. Leave it as it is to follow Galaxie → My Account.', 'galaxie-woo' ),
				'condition'   => array( 'item_type' => 'endpoint' ),
			)
		);

		// An opened order has no menu item of its own — it belongs to Orders — so
		// the item that lists the orders is where its template is chosen too.
		$repeater->add_control(
			'order_template',
			array(
				'label'       => __( 'Order details template', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array( '' => __( 'As set under Galaxie → My Account', 'galaxie-woo' ) ) + AccountEndpoints::templates(),
				'default'     => '',
				'description' => __( 'The screen that opens from "View order". Put the Galaxie Account Order widget in it.', 'galaxie-woo' ),
				'condition'   => array( 'item_type' => 'endpoint', 'endpoint' => 'orders' ),
			)
		);

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

		// Inline: the items are our own links carrying these as classes.
		PixfortControls::text( $widget, 'menu_item', array( 'bold' => '', 'remove_pb_padding' => 'm-0' ), array(), $link, 'text', array( 'position', 'inline' ) );

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

		// pixfort gives the current pill its large shadow with `!important`, so
		// the Button's Shadow style above — a class — can never reach it. The
		// values are pixfort's own shadow-sm / shadow / shadow-lg, written on a
		// selector scoped by Elementor, which outranks the theme's rule.
		$widget->add_control(
			'menu_active_shadow',
			array(
				'label'                => __( 'Shadow', 'galaxie-woo' ),
				'type'                 => Controls_Manager::SELECT,
				'options'              => array(
					''       => __( 'As the menu style draws it', 'galaxie-woo' ),
					'none'   => __( 'None', 'galaxie-woo' ),
					'small'  => __( 'Small shadow', 'galaxie-woo' ),
					'medium' => __( 'Medium shadow', 'galaxie-woo' ),
					'large'  => __( 'Large shadow', 'galaxie-woo' ),
				),
				'default'              => '',
				'selectors_dictionary' => array(
					'none'   => 'none',
					'small'  => '0 1px 5px 0 rgba(0, 0, 0, .15)',
					'medium' => '0 .125rem .375rem rgba(0, 0, 0, .05), 0 .5rem 1.2rem rgba(0, 0, 0, .1)',
					'large'  => '0 .25rem .5rem rgba(0, 0, 0, .05), 0 1.5rem 2.2rem rgba(0, 0, 0, .1)',
				),
				'selectors'            => array( $link . '.active' => 'box-shadow: {{VALUE}} !important;' ),
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

		PixfortControls::text( $widget, 'menu_heading', array( 'size' => 'text-xs', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), $menu . ' .galaxie-account-menu-heading', 'text', array( 'position', 'inline' ) );

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
	/**
	 * Screen key => template id, as the menu beside the content was told to draw them.
	 *
	 * The menu and the screen are two widgets as often as they are one. When they
	 * are one — the Galaxie Account widget — the content reads the items straight
	 * out of its own settings, which it must, because that widget draws the
	 * content before the menu. When they are two, the menu leaves its choices
	 * here on the way past and the content picks them up.
	 *
	 * @var array<string,int>
	 */
	private static array $menu_templates = array();

	/**
	 * The template this screen should be drawn from here, or 0 to follow the admin.
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function template_for( string $key, array $settings ): int {
		if ( '' === $key ) {
			return 0;
		}

		if ( isset( $settings['menu_items'] ) && is_array( $settings['menu_items'] ) ) {
			return self::templates_from( $settings['menu_items'] )[ $key ] ?? 0;
		}

		return self::$menu_templates[ $key ] ?? 0;
	}

	/**
	 * @param array<int,mixed> $items
	 * @return array<string,int>
	 */
	private static function templates_from( array $items ): array {
		$out = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || 'endpoint' !== ( $item['item_type'] ?? 'endpoint' ) ) {
				continue;
			}

			$key      = (string) ( $item['endpoint'] ?? '' );
			$template = absint( $item['template'] ?? 0 );

			if ( '' !== $key && $template ) {
				$out[ $key ] = $template;
			}

			$order_template = absint( $item['order_template'] ?? 0 );

			if ( 'orders' === $key && $order_template ) {
				$out['view-order'] = $order_template;
			}
		}

		return $out;
	}

	public static function render_menu( array $settings ): string {
		if ( ! self::available() || ( ! is_user_logged_in() && ! self::editing() ) ) {
			return '';
		}

		$items = array_values( array_filter( (array) ( $settings['menu_items'] ?? array() ), 'is_array' ) );

		// Left for a content widget rendered after this one, further down the page.
		self::$menu_templates = self::templates_from( $items );

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

		// The size class goes on the label, not the link: pixfort's pill styles
		// and ours both set a font size on `.nav-pills .nav-link` with more
		// specificity than a lone `.text-20`, so on the link the Size control
		// never showed. Nothing sizes the label, so there the class wins, and
		// with no size chosen the label simply inherits the link's. A custom
		// size is untouched — its rule already reaches the link through
		// Elementor's scoped selector.
		$item_size  = (string) ( $settings['menu_item_size'] ?? '' );
		$size_class = ( '' !== $item_size && 'custom' !== $item_size ) ? $item_size : '';
		$item_text  = PixfortControls::text_classes( $settings, 'menu_item' );

		if ( '' !== $size_class ) {
			$item_text = trim( (string) preg_replace( '/(^|\s)' . preg_quote( $size_class, '/' ) . '(?=\s|$)/', ' ', $item_text ) );
		}

		$link_base = trim(
			implode(
				' ',
				array(
					'nav-link',
					$top ? 'is-icon-top' : '',
					$item_text,
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

			$attrs       = '';
			$screen_attr = '';
			$is_active   = false;

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

				// What the script needs to swap this screen in without a reload:
				// which screen, and the template chosen for it right here. Kept
				// apart from $attrs, which carries target and rel — neither of
				// which belongs on the phone menu's <option>.
				$chosen      = absint( $item['template'] ?? 0 );
				$screen_attr = ' data-screen="' . esc_attr( $key ) . '"' . ( $chosen ? ' data-template="' . $chosen . '"' : '' );
			}

			$icon = '';

			if ( $icons && '' !== (string) ( $item['icon'] ?? '' ) && class_exists( '\PixfortCore' ) ) {
				$icon = (string) \PixfortCore::instance()->icons->getIcon( (string) $item['icon'], 24, 'galaxie-account-menu-icon' );
			}

			$links .= sprintf(
				'<div class="nav-item"><a class="%1$s" href="%2$s"%3$s%4$s%7$s>%5$s%6$s</a></div>',
				esc_attr( $link_base . ( $is_active ? ' active' : '' ) ),
				esc_url( $url ),
				$is_active ? ' aria-current="page"' : '',
				$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute strings.
				$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own icon markup.
				sprintf( '<span class="%1$s">%2$s</span>', esc_attr( trim( 'galaxie-account-menu-label ' . $size_class ) ), esc_html( $label ) ),
				$screen_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an escaped key and an integer.
			);

			$options .= sprintf(
				'<option value="%1$s"%2$s%4$s>%3$s</option>',
				esc_url( $url ),
				selected( $is_active, true, false ),
				esc_html( $label ),
				$screen_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an escaped key and an integer.
			);
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

		// The screen sits in a box of its own so the script can replace exactly
		// this much: the wrapper stays, and with it the pixfort surface classes
		// and every selector Elementor generated against {{WRAPPER}}.
		$html .= sprintf(
			'<div class="galaxie-account-screen-body">%s</div>',
			AccountEndpoints::render( $screen['key'], $screen['value'], self::template_for( $screen['key'], $settings ) )
		);

		return sprintf(
			'<div class="galaxie-account-content woocommerce %1$s" data-account-screen="%2$s"%3$s>%4$s</div>',
			esc_attr( $box ),
			esc_attr( $screen['key'] ),
			'yes' === ( $settings['content_show_title'] ?? '' ) ? ' data-account-title="yes"' : '',
			$html
		);
	}

	// ================================================================ helpers

	/** WooCommerce's order statuses, with the colours each starts with. */
	private const STATUS_COLOURS = array(
		'pending'    => array( 'orange', 'orange-light' ),
		'on-hold'    => array( 'orange', 'yellow-light' ),
		'processing' => array( 'blue', 'blue-light' ),
		'completed'  => array( 'green', 'green-light' ),
		'cancelled'  => array( 'red', 'red-light' ),
		'refunded'   => array( 'purple', 'purple-light' ),
		'failed'     => array( 'red', 'red-light' ),
	);

	/**
	 * One of the screen widgets, drawn with its own defaults: what a screen shows
	 * when no template was picked for it. Going through Elementor rather than
	 * calling the markup directly is what makes "defaults" mean the widget's
	 * control defaults, the same ones a merchant sees when dropping it in.
	 *
	 * @param array<string,mixed> $settings Overrides, for the dashboard's short order list.
	 */
	public static function render_widget( string $name, array $settings = array() ): string {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return '';
		}

		$element = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
			array(
				'id'         => substr( md5( 'galaxie-default-' . $name ), 0, 7 ),
				'elType'     => 'widget',
				'widgetType' => $name,
				'settings'   => $settings,
			)
		);

		if ( ! $element ) {
			return '';
		}

		ob_start();
		$element->print_element();

		return (string) ob_get_clean();
	}

	/**
	 * Status names and colours, shared by the order list and the order page.
	 *
	 * The base badge is pixfort's Badge set; each status then brings its own text
	 * and background from the palette, because "cancelled" and "completed" in
	 * one colour is a list nobody can scan.
	 */
	public static function register_status_controls( object $widget, bool $inherit = false ): void {
		$own = array();

		// The order page follows the orders list by default: the same status, in
		// the same colours, is styled once — on Galaxie Account Orders.
		if ( $inherit ) {
			$widget->start_controls_section( 'status_inherit_section', array( 'label' => __( 'Status badges', 'galaxie-woo' ) ) );

			$widget->add_control(
				'status_inherit',
				array(
					'label'        => __( 'Same as Galaxie Account Orders', 'galaxie-woo' ),
					'description'  => __( 'The status badges, the Pay, Cancel and other action buttons, and cancelling an order (its dialog, alert and return screen) work and look exactly as set on the Galaxie Account Orders widget. Turn off to set them here on their own.', 'galaxie-woo' ),
					'type'         => Controls_Manager::SWITCHER,
					'return_value' => 'yes',
					'default'      => 'yes',
				)
			);

			$widget->end_controls_section();

			$own = array( 'condition' => array( 'status_inherit!' => 'yes' ) );
		}

		$widget->start_controls_section( 'status_names_section', array( 'label' => __( 'Status names', 'galaxie-woo' ) ) + $own );

		foreach ( array_keys( self::STATUS_COLOURS ) as $status ) {
			$widget->add_control(
				'status_' . str_replace( '-', '_', $status ) . '_label',
				array(
					'label'       => wc_get_order_status_name( $status ),
					'type'        => Controls_Manager::TEXT,
					'placeholder' => wc_get_order_status_name( $status ),
					'default'     => self::status_default_label( $status ),
				)
			);
		}

		$widget->end_controls_section();

		$widget->start_controls_section( 'status_badge_style', array( 'label' => __( 'Status badge', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) + $own );

		foreach ( self::STATUS_COLOURS as $status => $colours ) {
			$id = 'status_' . str_replace( '-', '_', $status );

			$widget->add_control( $id . '_heading', array( 'label' => self::status_default_label( $status ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
			PixfortControls::palette_select( $widget, $id . '_text', __( 'Text color', 'galaxie-woo' ), $colours[0] );
			PixfortControls::palette_select( $widget, $id . '_bg', __( 'Background', 'galaxie-woo' ), $colours[1] );
		}

		$widget->add_control( 'status_base_heading', array( 'label' => __( 'Badge (other statuses and shared look)', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::badge( $widget, 'status', array( 'text_size' => 'h6', 'rounded' => 'rounded-lg', 'bold' => 'font-weight-bold', 'disable_margin_after_badge' => 'yes' ) );

		$widget->end_controls_section();
	}

	private static function status_default_label( string $status ): string {
		$labels = array(
			'pending'    => __( 'Aguardando pagamento', 'galaxie-woo' ),
			'on-hold'    => __( 'Em espera', 'galaxie-woo' ),
			'processing' => __( 'Em preparação', 'galaxie-woo' ),
			'completed'  => __( 'Concluído', 'galaxie-woo' ),
			'cancelled'  => __( 'Cancelado', 'galaxie-woo' ),
			'refunded'   => __( 'Reembolsado', 'galaxie-woo' ),
			'failed'     => __( 'Falhou', 'galaxie-woo' ),
		);

		return $labels[ $status ] ?? wc_get_order_status_name( $status );
	}

	/**
	 * Looks the order page follows instead of being styled again: widget it is
	 * taken from, option it is kept in, setting prefixes it is made of.
	 *
	 * - orders: the status badges, the Pay, Cancel and plugin action buttons, and
	 *   cancelling (dialog, alert, return screen) of Galaxie Account Orders —
	 *   the parts both screens show.
	 * - addresses: the card, address box and texts of Galaxie Account Address
	 *   Book, for the order's billing and shipping cards.
	 */
	private const LOOKS = array(
		'orders'    => array( 'galaxie-account-orders', 'galaxie_woo_orders_look_v3', array( 'status_', 'orders_pay_', 'orders_cancel_', 'orders_action_', 'cancel_' ) ),
		'addresses' => array( 'galaxie-account-address-book', 'galaxie_woo_addresses_look', array( 'ab_card_', 'ab_box_', 'ab_address_text_', 'ab_label_text_' ) ),
	);

	/** @param array<int,string> $prefixes */
	private static function is_look_key( string $key, array $prefixes ): bool {
		if ( 'status_inherit' === $key ) {
			return false;
		}

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An order's Pay, Cancel and plugin action buttons, as button sections:
	 * label, defaults, controls skipped, condition. Shared by the orders list and
	 * the order page, whose ids are the same so one can follow the other.
	 *
	 * Pay and Cancel start without text of their own and read WooCommerce's
	 * words; plugin actions ("Order again"…) always do.
	 *
	 * @param array<string,mixed> $condition
	 * @return array<string,array{0:string,1:array<string,string>,2:array<int,string>,3:array<string,mixed>}>
	 */
	public static function order_action_buttons( array $condition = array() ): array {
		return array(
			'orders_pay'    => array( __( 'Order: pay button', 'galaxie-woo' ), array( 'style' => 'link', 'size' => 'sm', 'icon' => 'Line/pixfort-icon-credit-card-1' ), array(), $condition ),
			'orders_cancel' => array( __( 'Order: cancel button', 'galaxie-woo' ), array( 'style' => 'link', 'size' => 'sm', 'icon' => 'Line/pixfort-icon-cross-circle-1' ), array(), $condition ),
			'orders_action' => array( __( 'Order: other actions (added by plugins)', 'galaxie-woo' ), array( 'style' => 'link', 'size' => 'sm' ), array( 'text' ), $condition ),
		);
	}

	/**
	 * An element's settings with its widget's defaults filled in, from its
	 * saved data. Falls back to what was saved when Elementor cannot build it.
	 *
	 * @param array<string,mixed> $element
	 * @return array<string,mixed>
	 */
	private static function full_settings( array $element ): array {
		$saved = (array) ( $element['settings'] ?? array() );

		if ( ! class_exists( '\Elementor\Plugin' ) || empty( \Elementor\Plugin::$instance->elements_manager ) ) {
			return $saved;
		}

		try {
			$instance = \Elementor\Plugin::$instance->elements_manager->create_element_instance( $element );

			return $instance ? array_merge( (array) $instance->get_settings(), $saved ) : $saved;
		} catch ( \Throwable $e ) {
			return $saved;
		}
	}

	/**
	 * WooCommerce's actions for an order, View left out, each on the button
	 * configured for it.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function order_actions( array $settings, \WC_Order $order ): string {
		$out = '';

		foreach ( wc_get_account_orders_actions( $order ) as $key => $action ) {
			if ( 'view' === $key ) {
				continue;
			}

			$prefix = array( 'pay' => 'orders_pay', 'cancel' => 'orders_cancel' )[ $key ] ?? 'orders_action';
			$label  = 'orders_action' === $prefix ? '' : trim( (string) ( $settings[ $prefix . '_text' ] ?? '' ) );
			$url    = (string) $action['url'];

			// WooCommerce's cancel link sends the customer to the dashboard. Ours
			// returns to the screen the widget chose, marked so it can say so.
			if ( 'cancel' === $key ) {
				$target = 'orders' === ( $settings['cancel_redirect'] ?? 'order' ) ? AccountEndpoints::url( 'orders' ) : $order->get_view_order_url();
				$url    = $order->get_cancel_order_url( add_query_arg( self::CANCELLED_ARG, $order->get_id(), $target ) );
			}

			$out .= self::link_button( $settings, $prefix, '' !== $label ? $label : (string) $action['name'], $url, 'is-' . sanitize_html_class( (string) $key ) );
		}

		return $out;
	}

	/** The query argument a cancel link returns with: the cancelled order's id. */
	public const CANCELLED_ARG = 'galaxie_cancelled';

	/**
	 * Cancelling an order, set on the widget that shows the Cancel button: where
	 * the customer lands, the alert that tells them it worked, and the dialog
	 * that asks first — WooCommerce itself cancels on the first click.
	 *
	 * @param array<string,mixed> $condition
	 */
	public static function cancel_controls( object $widget, string $default_redirect, array $condition ): void {
		$widget->start_controls_section( 'cancel_section', array( 'label' => __( 'Cancelling an order', 'galaxie-woo' ), 'condition' => $condition ) );

		$widget->add_control(
			'cancel_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Customers can cancel orders that are awaiting payment or failed. The dialog below asks first; the alert confirms it on the screen they return to.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$widget->add_control(
			'cancel_redirect',
			array(
				'label'   => __( 'After cancelling, go to', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'order'  => __( 'The order details', 'galaxie-woo' ),
					'orders' => __( 'The orders list', 'galaxie-woo' ),
				),
				'default' => $default_redirect,
			)
		);

		$widget->add_control( 'cancel_alert_heading', array( 'label' => __( 'Alert after cancelling', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );

		$widget->add_control(
			'cancel_alert_text',
			array(
				'label'       => __( 'Message', 'galaxie-woo' ),
				'description' => __( '{number} is the order number. Leave empty for no alert.', 'galaxie-woo' ),
				'label_block' => true,
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => __( 'Pedido #{number} cancelado.', 'galaxie-woo' ),
			)
		);

		$shown = array( 'cancel_alert_text!' => '' );

		$widget->add_control(
			'cancel_alert_type',
			array(
				'label'     => __( 'Alert type', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => PixfortControls::alert_types(),
				'default'   => 'success',
				'condition' => $shown,
			)
		);

		PixfortControls::icon_select( $widget, 'cancel_alert_icon', __( 'Icon', 'galaxie-woo' ), 'Line/pixfort-icon-check-circle-1', $shown + array( 'cancel_alert_media_type' => 'icon' ) );

		$widget->add_control(
			'cancel_alert_preview',
			array(
				'label'        => __( 'Show in the editor', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'condition'    => $shown,
			)
		);

		$widget->add_control( 'cancel_alert_style_heading', array( 'label' => __( 'Alert appearance', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before', 'condition' => $shown ) );
		PixfortControls::alert( $widget, 'cancel_alert', array(), $shown, '{{WRAPPER}} .galaxie-order-cancelled' );

		$widget->end_controls_section();

		Dialog::controls(
			$widget,
			'cancel_confirm',
			array(
				'label' => __( 'Cancel order dialog', 'galaxie-woo' ),
				'title' => __( 'Cancelar pedido', 'galaxie-woo' ),
				'text'  => __( 'Cancelar este pedido? Isso não pode ser desfeito.', 'galaxie-woo' ),
				'yes'       => __( 'Sim, cancelar', 'galaxie-woo' ),
				'no'        => __( 'Voltar', 'galaxie-woo' ),
				'condition' => $condition,
			)
		);
	}

	/**
	 * The one selector-driven rule of the cancelled alert, its icon size, for an
	 * alert drawn with settings saved on another widget.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function cancelled_alert_css( array $settings, string $scope ): string {
		$size = $settings['cancel_alert_icon_size'] ?? '';

		return is_numeric( $size ) ? $scope . ' .galaxie-order-cancelled .pix-alert-icon > div{font-size: ' . (float) $size . 'px !important;}' : '';
	}

	/**
	 * The alert for an order the customer has just cancelled, on the screen
	 * the cancel link returned to — or a sample in the editor when asked for.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function cancelled_alert( array $settings ): string {
		$text = trim( (string) ( $settings['cancel_alert_text'] ?? '' ) );

		if ( '' === $text ) {
			return '';
		}

		$id      = isset( $_GET[ self::CANCELLED_ARG ] ) ? absint( wp_unslash( $_GET[ self::CANCELLED_ARG ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only; the cancelling itself was WooCommerce's, nonce and all.
		$order   = $id ? wc_get_order( $id ) : null;
		$preview = self::editing() && 'yes' === ( $settings['cancel_alert_preview'] ?? '' );

		// Only for an order of this customer that really is cancelled now: the
		// argument is in the address bar, and anyone can type it.
		$real = $order instanceof \WC_Order && (int) $order->get_customer_id() === get_current_user_id() && $order->has_status( 'cancelled' );

		if ( ! $real && ! $preview ) {
			return '';
		}

		$number = $real ? $order->get_order_number() : '1234';
		$text   = wp_kses_post( str_replace( '{number}', esc_html( (string) $number ), $text ) );
		$type   = (string) ( $settings['cancel_alert_type'] ?? 'success' );

		if ( ! PixfortControls::available() ) {
			return sprintf( '<div class="galaxie-order-cancelled" role="status"><div class="alert alert-%1$s">%2$s</div></div>', esc_attr( $type ), $text );
		}

		if ( defined( 'PIX_CORE_PLUGIN_URI' ) && defined( 'PIXFORT_PLUGIN_VERSION' ) ) {
			wp_enqueue_style( 'pixfort-alert-style', PIX_CORE_PLUGIN_URI . 'includes/assets/css/elements/alert.min.css', array(), PIXFORT_PLUGIN_VERSION );
		}

		return sprintf(
			'<div class="galaxie-order-cancelled" role="status">%s</div>',
			(string) \PixfortCore::instance()->elementsManager->renderElement( 'Alert', PixfortControls::alert_attr( $settings, 'cancel_alert', $text, $type, PixfortControls::icon_value( $settings, 'cancel_alert_icon' ) ) )
		);
	}

	/**
	 * WooCommerce queues "Your order was cancelled." for the next page; ours says
	 * it in the widget's alert, so the loose notice is dropped when the cancel
	 * link came back to one of our screens.
	 */
	public static function drop_cancelled_notice(): void {
		if ( ! isset( $_GET[ self::CANCELLED_ARG ] ) || ! function_exists( 'wc_get_notices' ) || ! function_exists( 'WC' ) || ! WC()->session ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only removes a message.
			return;
		}

		$message = (string) apply_filters( 'woocommerce_order_cancelled_notice', __( 'Your order was cancelled.', 'woocommerce' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own string, to match it.
		$notices = wc_get_notices();

		foreach ( $notices as $type => $list ) {
			$notices[ $type ] = array_values(
				array_filter(
					(array) $list,
					static fn( $notice ): bool => ( is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice ) !== $message
				)
			);
		}

		wc_set_notices( $notices );
	}

	/**
	 * Keeps the looks of the widgets the order page follows (see LOOKS) when a
	 * document holding them is saved, so that page is not styled again.
	 *
	 * Taken on save, not on display: the dashboard can draw its own unstyled
	 * Orders widget, and a display would overwrite the merchant's colours with
	 * its defaults.
	 *
	 * @param mixed $document An Elementor document.
	 */
	public static function sync_looks( $document ): void {
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_elements_data' ) ) {
			return;
		}

		$elements = (array) $document->get_elements_data();

		foreach ( self::LOOKS as $look_def ) {
			$look = self::look_in( $elements, $look_def[0], $look_def[2] );

			if ( null !== $look ) {
				update_option( $look_def[1], $look, false );
			}
		}
	}

	/**
	 * The look of the first styled widget of this type among these elements; an
	 * empty array when every such widget is left at its defaults; null when
	 * there is none.
	 *
	 * @param array<int,mixed>  $elements
	 * @param array<int,string> $prefixes
	 * @return array<string,mixed>|null
	 */
	private static function look_in( array $elements, string $widget, array $prefixes ): ?array {
		$found = null;

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( $widget === ( $element['widgetType'] ?? '' ) ) {
				$only_look = static fn( array $settings ): array => array_filter(
					$settings,
					static fn( $value, $key ): bool => self::is_look_key( (string) $key, $prefixes ),
					ARRAY_FILTER_USE_BOTH
				);

				// Saved settings hold only what was changed. The rest — a badge left
				// bold, an icon left as it came — must travel too, or the order page's
				// own values would show through, so the widget's full settings are
				// taken, defaults included.
				if ( $only_look( (array) ( $element['settings'] ?? array() ) ) ) {
					return $only_look( self::full_settings( $element ) );
				}

				$found = array();
			}

			$inner = self::look_in( (array) ( $element['elements'] ?? array() ), $widget, $prefixes );

			if ( $inner ) {
				return $inner;
			}

			$found = $found ?? $inner;
		}

		return $found;
	}

	/**
	 * A look kept by {@see sync_looks()}: 'orders' or 'addresses'. The first
	 * time it is asked for — before its widget was saved with this in place — it
	 * is read from the widgets already on the site, newest first.
	 *
	 * @return array<string,mixed>
	 */
	public static function look( string $name ): array {
		if ( ! isset( self::LOOKS[ $name ] ) ) {
			return array();
		}

		[ $widget, $option, $prefixes ] = self::LOOKS[ $name ];

		$look = get_option( $option, null );

		if ( is_array( $look ) ) {
			return $look;
		}

		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off lookup, cached in the option below.
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE m.meta_key = '_elementor_data' AND m.meta_value LIKE %s
				AND p.post_status IN ('publish','private') ORDER BY p.post_modified DESC LIMIT 20",
				'%' . $wpdb->esc_like( '"widgetType":"' . $widget . '"' ) . '%'
			)
		);

		$look = array();

		foreach ( (array) $ids as $id ) {
			$data  = json_decode( (string) get_post_meta( (int) $id, '_elementor_data', true ), true );
			$found = is_array( $data ) ? self::look_in( $data, $widget, $prefixes ) : null;

			if ( $found ) {
				$look = $found;
				break;
			}
		}

		update_option( $option, $look, false );

		return $look;
	}

	/** @return array<string,mixed> The orders list's look, see LOOKS. */
	public static function orders_look(): array {
		return self::look( 'orders' );
	}

	/** @param array<string,mixed> $settings */
	public static function status_badge( array $settings, \WC_Order $order ): string {
		if ( 'yes' === ( $settings['status_inherit'] ?? '' ) ) {
			$settings = array_merge( $settings, self::orders_look() );
		}

		$status = $order->get_status();
		$id     = 'status_' . str_replace( '-', '_', $status );
		$label  = trim( (string) ( $settings[ $id . '_label' ] ?? '' ) );
		$label  = '' !== $label ? $label : ( isset( self::STATUS_COLOURS[ $status ] ) ? self::status_default_label( $status ) : wc_get_order_status_name( $status ) );

		if ( ! PixfortControls::available() ) {
			return sprintf( '<span class="badge galaxie-order-status is-%1$s">%2$s</span>', esc_attr( $status ), esc_html( $label ) );
		}

		$attr = PixfortControls::badge_attr( $settings, 'status', $label );

		if ( isset( self::STATUS_COLOURS[ $status ] ) ) {
			$attr['text_color'] = (string) ( $settings[ $id . '_text' ] ?? self::STATUS_COLOURS[ $status ][0] );
			$attr['bg_color']   = (string) ( $settings[ $id . '_bg' ] ?? self::STATUS_COLOURS[ $status ][1] );
		}

		return sprintf(
			'<span class="galaxie-order-status is-%1$s">%2$s</span>',
			esc_attr( $status ),
			(string) \PixfortCore::instance()->elementsManager->renderElement( 'Badge', $attr )
		);
	}

	/**
	 * A pixfort button that is a link: our `<a>` around pixfort's `<span class="btn">`.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function link_button( array $settings, string $prefix, string $text, string $url, string $class = '' ): string {
		return sprintf(
			'<a class="galaxie-account-button %1$s" href="%2$s">%3$s</a>',
			esc_attr( $class ),
			esc_url( $url ),
			PixfortControls::render_button( $settings, $prefix, $text )
		);
	}

	/**
	 * The strip holding a list's add button, set apart from the cards above it:
	 * an optional line, the space on either side of it and where the buttons sit.
	 * Shared so the address book and the payment methods separate the same way.
	 *
	 * The line is drawn with three custom properties and the switcher writes the
	 * border that reads them, so style, width and colour each work whichever is
	 * set first.
	 */
	public static function add_area_controls( object $widget, string $prefix, string $selector, string $label ): void {
		$line = array( $prefix . '_separator' => 'yes' );

		$widget->start_controls_section( $prefix . '_style', array( 'label' => $label, 'tab' => Controls_Manager::TAB_STYLE ) );

		$widget->add_control(
			$prefix . '_separator',
			array(
				'label'        => __( 'Separator line above', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => array( $selector => 'border-top: var(--galaxie-separator-width, 1px) var(--galaxie-separator-style, solid) var(--galaxie-separator-color, rgb(0 0 0 / 12%));' ),
			)
		);

		$widget->add_control(
			$prefix . '_separator_style',
			array(
				'label'     => __( 'Line style', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					''       => __( 'Default', 'galaxie-woo' ),
					'solid'  => __( 'Solid', 'galaxie-woo' ),
					'dashed' => __( 'Dashed', 'galaxie-woo' ),
					'dotted' => __( 'Dotted', 'galaxie-woo' ),
					'double' => __( 'Double', 'galaxie-woo' ),
				),
				'selectors' => array( $selector => '--galaxie-separator-style: {{VALUE}};' ),
				'condition' => $line,
			)
		);

		$widget->add_responsive_control(
			$prefix . '_separator_width',
			array(
				'label'      => __( 'Line width', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 1, 'max' => 10 ) ),
				'selectors'  => array( $selector => '--galaxie-separator-width: {{SIZE}}{{UNIT}};' ),
				'condition'  => $line,
			)
		);

		PixfortControls::palette_control( $widget, $prefix . '_separator_color', __( 'Line color', 'galaxie-woo' ), $selector, '--galaxie-separator-color', $line );

		$widget->add_responsive_control(
			$prefix . '_space_above',
			array(
				'label'       => __( 'Space above', 'galaxie-woo' ),
				'description' => __( 'Between the cards and the line, or the buttons when there is no line. Added to the widget\'s usual spacing.', 'galaxie-woo' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array( 'px' => array( 'min' => 0, 'max' => 120 ) ),
				'separator'   => 'before',
				'selectors'   => array( $selector => 'margin-top: {{SIZE}}{{UNIT}};' ),
			)
		);

		$widget->add_responsive_control(
			$prefix . '_space_below',
			array(
				'label'      => __( 'Space between line and buttons', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 120 ) ),
				'selectors'  => array( $selector => 'padding-top: {{SIZE}}{{UNIT}};' ),
			)
		);

		$widget->add_responsive_control(
			$prefix . '_align',
			array(
				'label'     => __( 'Buttons alignment', 'galaxie-woo' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array( 'title' => __( 'Left', 'galaxie-woo' ), 'icon' => 'eicon-h-align-left' ),
					'center'     => array( 'title' => __( 'Center', 'galaxie-woo' ), 'icon' => 'eicon-h-align-center' ),
					'flex-end'   => array( 'title' => __( 'Right', 'galaxie-woo' ), 'icon' => 'eicon-h-align-right' ),
				),
				'selectors' => array( $selector => 'justify-content: {{VALUE}};' ),
			)
		);

		$widget->end_controls_section();
	}

	/** The order a screen is about, if this customer may see it. */
	public static function order_for( string $value ): ?\WC_Order {
		$order = wc_get_order( absint( $value ) );

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		return current_user_can( 'view_order', $order->get_id() ) ? $order : null;
	}

	/**
	 * A message for the editor when a screen widget has nothing to show there,
	 * and nothing at all on the site.
	 */
	public static function editor_note( string $text ): string {
		return self::editing() ? sprintf( '<p class="galaxie-account-empty">%s</p>', esc_html( $text ) ) : '';
	}
}

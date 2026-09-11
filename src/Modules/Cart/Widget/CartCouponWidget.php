<?php
/**
 * "Galaxie Cart Coupon": the coupon field, on its own.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Cart\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\CartParts;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon entry, applied coupons and every message a coupon can produce, as one
 * widget the merchant can put anywhere on the page.
 *
 * It used to be a strip at the foot of the cart table. That tied it to the
 * table's column and left nothing to configure but a placeholder: no label, no
 * list of what was applied, no way to remove one except WooCommerce's
 * "[Remove]" link in the totals, and WooCommerce's English messages.
 *
 * Applying and removing still go through WooCommerce's own `apply_coupon` and
 * `remove_coupon` endpoints, so every coupon rule and hook runs. The messages
 * are WooCommerce's own too unless the merchant writes one. The request carries
 * this widget's ids, and {@see \Galaxie\Woo\Modules\Cart\Module::coupon_text()}
 * swaps the text in at the filter WooCommerce already provides, per error code.
 */
final class CartCouponWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-cart-coupon';
	}

	public function get_title(): string {
		return __( 'Galaxie Cart Coupon', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-price-list';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/**
	 * Control id => label, one per thing a shopper can be told.
	 *
	 * WooCommerce has nineteen codes. Several say the same thing to a shopper
	 * (three flavours of "not valid for these products", three of "used up"),
	 * so they share a message here. {@see \Galaxie\Woo\Modules\Cart\Module}
	 * holds the code-to-message map.
	 *
	 * @return array<string,string>
	 */
	public static function messages(): array {
		return array(
			'msg_applied'        => __( 'Coupon applied', 'galaxie-woo' ),
			'msg_removed'        => __( 'Coupon removed', 'galaxie-woo' ),
			'msg_empty'          => __( 'No code typed', 'galaxie-woo' ),
			'msg_not_exist'      => __( 'Code does not exist', 'galaxie-woo' ),
			'msg_expired'        => __( 'Coupon expired', 'galaxie-woo' ),
			'msg_usage_limit'    => __( 'Usage limit reached', 'galaxie-woo' ),
			'msg_already'        => __( 'Already applied', 'galaxie-woo' ),
			'msg_individual'     => __( 'Cannot be combined with other coupons', 'galaxie-woo' ),
			'msg_min'            => __( 'Minimum spend not reached', 'galaxie-woo' ),
			'msg_max'            => __( 'Maximum spend exceeded', 'galaxie-woo' ),
			'msg_not_applicable' => __( 'Not valid for these products', 'galaxie-woo' ),
			'msg_invalid'        => __( 'Invalid (any other reason)', 'galaxie-woo' ),
		);
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'coupon_section', array( 'label' => __( 'Coupon', 'galaxie-woo' ) ) );

		$this->add_control(
			'show_label',
			array(
				'label'        => __( 'Label', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'label_text',
			array(
				'label'     => __( 'Label text', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Cupom de desconto', 'galaxie-woo' ),
				'condition' => array( 'show_label' => 'yes' ),
			)
		);

		$this->add_control(
			'placeholder',
			array(
				'label'   => __( 'Placeholder', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Código do cupom', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Field and button', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'inline'  => __( 'Side by side', 'galaxie-woo' ),
					'stacked' => __( 'Stacked', 'galaxie-woo' ),
				),
				'default' => 'inline',
			)
		);

		$this->add_control(
			'show_applied',
			array(
				'label'        => __( 'List applied coupons', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'separator'    => 'before',
			)
		);

		PixfortControls::icon_select( $this, 'remove_icon', __( 'Remove icon', 'galaxie-woo' ), '', array( 'show_applied' => 'yes' ) );

		$this->add_control(
			'remove_text',
			array(
				'label'       => __( 'Remove text', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Remover', 'galaxie-woo' ),
				'description' => __( 'Shown when no icon is chosen.', 'galaxie-woo' ),
				'condition'   => array( 'show_applied' => 'yes' ),
			)
		);

		$this->add_control(
			'messages_display',
			array(
				'label'       => __( 'Show messages', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'inline' => __( 'Under the field', 'galaxie-woo' ),
					'notice' => __( 'As a store notice', 'galaxie-woo' ),
				),
				'default'     => 'inline',
				'separator'   => 'before',
				'description' => __( 'A store notice becomes a toast when the Toast Notices module is on.', 'galaxie-woo' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'coupon_button_section', array( 'label' => __( 'Apply button', 'galaxie-woo' ) ) );
		PixfortControls::button(
			$this,
			'couponbtn',
			array( 'text' => __( 'Aplicar', 'galaxie-woo' ), 'color' => 'primary', 'size' => 'md' ),
			array(),
			'{{WRAPPER}} .galaxie-coupon-apply'
		);
		$this->end_controls_section();

		$this->start_controls_section( 'coupon_messages_section', array( 'label' => __( 'Messages', 'galaxie-woo' ) ) );

		$this->add_control(
			'messages_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'Leave a message empty to keep WooCommerce\'s own. {code} is the coupon code; {amount} is the minimum or maximum spend.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		foreach ( self::messages() as $id => $label ) {
			$this->add_control(
				$id,
				array(
					'label'       => $label,
					'type'        => Controls_Manager::TEXTAREA,
					'rows'        => 2,
					'default'     => '',
					'label_block' => true,
				)
			);
		}

		$this->end_controls_section();

		$this->start_controls_section( 'coupon_box_style', array( 'label' => __( 'Box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'coupon_box', '{{WRAPPER}} .galaxie-coupon' );
		$this->end_controls_section();

		$this->start_controls_section(
			'coupon_label_style',
			array( 'label' => __( 'Label', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'show_label' => 'yes' ) )
		);
		PixfortControls::text( $this, 'coupon_label', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-coupon-label', 'text', array( 'position' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'coupon_field_style', array( 'label' => __( 'Field', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		$field = '{{WRAPPER}} .galaxie-coupon .form-control';

		PixfortControls::palette_control( $this, 'coupon_field_bg', __( 'Background', 'galaxie-woo' ), $field, 'background-color' );
		PixfortControls::palette_control( $this, 'coupon_field_color', __( 'Text color', 'galaxie-woo' ), $field, 'color' );
		PixfortControls::palette_control( $this, 'coupon_field_border', __( 'Border color', 'galaxie-woo' ), $field, 'border-color', array(), ' border-style: solid; border-width: 1px;' );

		$this->add_responsive_control(
			'coupon_field_radius',
			array(
				'label'      => __( 'Border radius', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( $field => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'coupon_field_gap',
			array(
				'label'      => __( 'Space between field and button', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 8 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-coupon-form' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'coupon_applied_style',
			array( 'label' => __( 'Applied coupons', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'show_applied' => 'yes' ) )
		);

		$this->add_control( 'coupon_code_heading', array( 'label' => __( 'Code', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING ) );
		PixfortControls::text( $this, 'coupon_code', array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-coupon-code', 'text', array( 'position' ) );

		$this->add_control( 'coupon_amount_heading', array( 'label' => __( 'Discount', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::text( $this, 'coupon_amount', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-coupon-amount', 'text', array( 'position' ) );

		$this->add_control( 'coupon_remove_heading', array( 'label' => __( 'Remove', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::icon_color( $this, 'remove_icon_color', __( 'Icon color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-coupon-remove', array( 'remove_icon!' => '' ) );
		PixfortControls::palette_control( $this, 'remove_text_color', __( 'Text color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-coupon-remove', 'color', array( 'remove_icon' => '' ) );

		$this->add_responsive_control(
			'remove_icon_size',
			array(
				'label'      => __( 'Icon size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 8, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 16 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-coupon-remove svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
				'condition'  => array( 'remove_icon!' => '' ),
			)
		);

		$this->add_responsive_control(
			'coupon_applied_gap',
			array(
				'label'      => __( 'Space between coupons', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'separator'  => 'before',
				'selectors'  => array( '{{WRAPPER}} .galaxie-coupon-applied' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'coupon_message_style',
			array( 'label' => __( 'Messages', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'messages_display' => 'inline' ) )
		);

		$this->add_control( 'coupon_msg_ok_heading', array( 'label' => __( 'Success', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING ) );
		PixfortControls::text( $this, 'coupon_msg_ok', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-coupon-notice.is-success', 'text', array( 'position' ) );

		$this->add_control( 'coupon_msg_err_heading', array( 'label' => __( 'Error', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::text( $this, 'coupon_msg_err', array( 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-coupon-notice.is-error', 'text', array( 'position' ) );

		$this->end_controls_section();
	}

	protected function render(): void {
		if ( ! CartParts::available() || ! wc_coupons_enabled() || WC()->cart->is_empty() ) {
			return;
		}

		$settings = $this->get_settings_for_display();
		$id       = $this->get_id();
		$input_id = 'galaxie-coupon-' . $id;

		// The ids travel on the fragment key so a fresh copy of this widget,
		// read out of a re-fetched cart page, replaces this one and no other.
		printf(
			'<div class="galaxie-coupon %1$s %2$s" data-galaxie-fragment="coupon-%3$s" data-messages="%4$s" data-ok-class="%5$s" data-error-class="%6$s">',
			esc_attr( PixfortControls::surface_classes( $settings, 'coupon_box' ) ),
			esc_attr( 'stacked' === ( $settings['layout'] ?? 'inline' ) ? 'is-stacked' : 'is-inline' ),
			esc_attr( $id ),
			esc_attr( 'notice' === ( $settings['messages_display'] ?? 'inline' ) ? 'notice' : 'inline' ),
			esc_attr( PixfortControls::text_classes( $settings, 'coupon_msg_ok' ) ),
			esc_attr( PixfortControls::text_classes( $settings, 'coupon_msg_err' ) )
		);

		if ( 'yes' === ( $settings['show_label'] ?? 'yes' ) ) {
			printf(
				'<label class="galaxie-coupon-label %1$s" for="%2$s">%3$s</label>',
				esc_attr( PixfortControls::text_classes( $settings, 'coupon_label' ) ),
				esc_attr( $input_id ),
				esc_html( (string) ( $settings['label_text'] ?? '' ) )
			);
		}

		// A real form posting WooCommerce's own fields and nonce, so a shopper
		// without JavaScript still applies a coupon through the cart handler.
		printf( '<form class="galaxie-coupon-form" method="post" action="%s">', esc_url( wc_get_cart_url() ) );
		printf(
			'<input type="text" name="coupon_code" id="%1$s" class="input-text form-control" placeholder="%2$s" autocomplete="off" />',
			esc_attr( $input_id ),
			esc_attr( (string) ( $settings['placeholder'] ?? '' ) )
		);
		printf(
			'<button type="submit" name="apply_coupon" value="1" class="galaxie-coupon-apply">%s</button>',
			PixfortControls::render_button( $settings, 'couponbtn', (string) ( $settings['couponbtn_text'] ?? __( 'Aplicar', 'galaxie-woo' ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own component markup.
		);
		// Printed by hand rather than with wp_nonce_field(): the cart table
		// prints the same field, and two elements with id="woocommerce-cart-nonce"
		// on one page is invalid markup.
		printf( '<input type="hidden" name="woocommerce-cart-nonce" value="%s" />', esc_attr( wp_create_nonce( 'woocommerce-cart' ) ) );
		echo '</form>';

		// Preserved across a refresh, so the message just shown survives the
		// new copy of this widget that the refresh brings in.
		echo '<div class="galaxie-coupon-notice" data-galaxie-preserve="notice" role="status" aria-live="polite" hidden></div>';

		$coupons = WC()->cart->get_coupons();

		if ( $coupons && 'yes' === ( $settings['show_applied'] ?? 'yes' ) ) {
			$icon   = PixfortControls::icon_value( $settings, 'remove_icon' );
			$text   = (string) ( $settings['remove_text'] ?? '' );
			$remove = '' !== $icon ? CartParts::icon_markup( $icon ) : esc_html( '' !== $text ? $text : __( 'Remover', 'galaxie-woo' ) );

			echo '<ul class="galaxie-coupon-applied">';

			foreach ( $coupons as $coupon ) {
				$code   = $coupon->get_code();
				$amount = WC()->cart->get_coupon_discount_amount( $code, WC()->cart->display_cart_ex_tax );
				$value  = $amount > 0 ? '-' . wc_price( $amount ) : ( $coupon->get_free_shipping() ? esc_html__( 'Free shipping coupon', 'woocommerce' ) : '' );

				printf(
					'<li class="galaxie-coupon-item"><span class="galaxie-coupon-code %1$s">%2$s</span><span class="galaxie-coupon-amount %3$s">%4$s</span><a href="%5$s" class="galaxie-coupon-remove" data-coupon="%6$s" role="button" aria-label="%7$s">%8$s</a></li>',
					esc_attr( PixfortControls::text_classes( $settings, 'coupon_code' ) ),
					esc_html( $code ),
					esc_attr( PixfortControls::text_classes( $settings, 'coupon_amount' ) ),
					wp_kses_post( $value ),
					esc_url( add_query_arg( 'remove_coupon', rawurlencode( $code ), wc_get_cart_url() ) ),
					esc_attr( $code ),
					/* translators: %s: coupon code. */
					esc_attr( sprintf( __( 'Remove %s coupon', 'woocommerce' ), $code ) ),
					$remove // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own SVG, or escaped text.
				);
			}

			echo '</ul>';
		}

		echo '</div>';
	}
}

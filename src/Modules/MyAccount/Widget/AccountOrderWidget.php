<?php
/**
 * "Galaxie Account Order": one order, opened from the orders list.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\AccountEndpoints;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\Dialog;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * The order page, in cards: a header with its number, date and status, what
 * was bought, what it cost, where it goes, and anything the store wrote back.
 *
 * Every figure comes from WooCommerce's own order API, the totals from
 * `get_order_item_totals()` in particular, so discounts, shipping lines, fees
 * and taxes read exactly as they do in the order email. The
 * `woocommerce_order_details_after_order_table` hook still fires where
 * WooCommerce fires it, because that is where plugins put tracking codes,
 * invoices and pickup details; leaving it out would hide them.
 */
final class AccountOrderWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account-order';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Order', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-document-file';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'order', 'pedido', 'woocommerce' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'order_section', array( 'label' => __( 'Order', 'galaxie-woo' ) ) );

		$this->add_control( 'order_title_text', array( 'label' => __( 'Title', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Pedido #{number}', 'galaxie-woo' ), 'description' => __( '{number} is the order number.', 'galaxie-woo' ) ) );

		$switches = array(
			'order_show_items'     => __( 'Items', 'galaxie-woo' ),
			'order_show_totals'    => __( 'Totals', 'galaxie-woo' ),
			'order_show_addresses' => __( 'Addresses', 'galaxie-woo' ),
			'order_show_note'      => __( 'Customer note', 'galaxie-woo' ),
			'order_show_updates'   => __( 'Updates from the store', 'galaxie-woo' ),
			'order_show_actions'   => __( 'Pay, cancel and other actions', 'galaxie-woo' ),
			'order_show_extras'    => __( 'What other plugins add (tracking, invoices)', 'galaxie-woo' ),
		);

		$first = true;

		foreach ( $switches as $id => $label ) {
			$this->add_control(
				$id,
				array(
					'label'        => $label,
					'type'         => Controls_Manager::SWITCHER,
					'return_value' => 'yes',
					'default'      => 'yes',
					'separator'    => $first ? 'before' : '',
				)
			);
			$first = false;
		}

		$this->add_control(
			'order_addresses_inherit',
			array(
				'label'        => __( 'Addresses look like Galaxie Account Address Book', 'galaxie-woo' ),
				'description'  => __( 'The billing and shipping cards take the card, address box and text styles saved on the address book widget. Turn off to style them with this widget\'s Cards and text sections.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'order_show_addresses' => 'yes' ),
			)
		);

		$labels = array(
			'order_items_heading'    => array( __( 'Items heading', 'galaxie-woo' ), __( 'Itens', 'galaxie-woo' ) ),
			'order_totals_heading'   => array( __( 'Totals heading', 'galaxie-woo' ), __( 'Resumo', 'galaxie-woo' ) ),
			'order_billing_heading'  => array( __( 'Billing address heading', 'galaxie-woo' ), __( 'Endereço de cobrança', 'galaxie-woo' ) ),
			'order_shipping_heading' => array( __( 'Shipping address heading', 'galaxie-woo' ), __( 'Endereço de entrega', 'galaxie-woo' ) ),
			'order_note_heading'     => array( __( 'Customer note heading', 'galaxie-woo' ), __( 'Sua observação', 'galaxie-woo' ) ),
			'order_updates_heading'  => array( __( 'Updates heading', 'galaxie-woo' ), __( 'Atualizações do pedido', 'galaxie-woo' ) ),
		);

		$first = true;

		foreach ( $labels as $id => $label ) {
			$this->add_control(
				$id,
				array(
					'label'     => $label[0],
					'type'      => Controls_Manager::TEXT,
					'default'   => $label[1],
					'separator' => $first ? 'before' : '',
				)
			);
			$first = false;
		}

		$this->add_control( 'order_missing_text', array( 'label' => __( 'When the order cannot be found', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Pedido não encontrado.', 'galaxie-woo' ), 'separator' => 'before' ) );

		$this->end_controls_section();

		$this->start_controls_section( 'order_back_button_section', array( 'label' => __( 'Back button', 'galaxie-woo' ) ) );
		PixfortControls::button( $this, 'order_back', array( 'text' => __( 'Voltar para pedidos', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm', 'icon' => 'Line/pixfort-icon-arrow-left-1' ) );

		$this->add_responsive_control(
			'order_back_space',
			array(
				'label'       => __( 'Space below', 'galaxie-woo' ),
				'description' => __( 'Between this button and the order title. Added to the header\'s usual spacing.', 'galaxie-woo' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'separator'   => 'before',
				'selectors'   => array( '{{WRAPPER}} .galaxie-order-back' => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		AccountParts::register_status_controls( $this, true );

		// The same buttons, under the same ids, as Galaxie Account Orders — which
		// is what lets this page follow that widget's look. Shown only to style
		// them here instead.
		foreach ( AccountParts::order_action_buttons( array( 'order_show_actions' => 'yes', 'status_inherit!' => 'yes' ) ) as $prefix => $button ) {
			$this->start_controls_section( $prefix . '_button_section', array( 'label' => $button[0], 'condition' => $button[3] ) );
			PixfortControls::button( $this, $prefix, $button[1], array(), '{{WRAPPER}}', $button[2] );
			$this->end_controls_section();
		}

		AccountParts::cancel_controls( $this, 'order', array( 'order_show_actions' => 'yes', 'status_inherit!' => 'yes' ) );

		// Following the orders list hides the cancelling sections, and with them
		// the way to see the alert here. Only that stays: its look is set there.
		$this->start_controls_section( 'order_cancel_preview_section', array( 'label' => __( 'Cancelled order alert', 'galaxie-woo' ), 'condition' => array( 'order_show_actions' => 'yes', 'status_inherit' => 'yes' ) ) );

		$this->add_control(
			'order_cancel_preview',
			array(
				'label'        => __( 'Show the cancelled alert in the editor', 'galaxie-woo' ),
				'description'  => __( 'Its message and look are set on Galaxie Account Orders.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'order_cards_style', array( 'label' => __( 'Cards', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		PixfortControls::surface( $this, 'order_card', '{{WRAPPER}} .galaxie-order-section', array( 'rounded' => 'rounded-lg' ) );

		$this->add_responsive_control(
			'order_gap',
			array(
				'label'      => __( 'Space between cards', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-account-order' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		PixfortControls::palette_control( $this, 'order_divider', __( 'Divider color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-order-line + .galaxie-order-line, {{WRAPPER}} .galaxie-order-total-row + .galaxie-order-total-row', 'border-top-color' );

		$this->end_controls_section();

		$texts = array(
			'order_title'     => array( __( 'Title', 'galaxie-woo' ), '.galaxie-order-title', array( 'size' => 'text-24', 'bold' => 'font-weight-bold' ) ),
			'order_date'      => array( __( 'Date', 'galaxie-woo' ), '.galaxie-order-date', array( 'size' => 'text-sm', 'bold' => '' ) ),
			'order_heading'   => array( __( 'Card headings', 'galaxie-woo' ), '.galaxie-order-section-heading', array( 'size' => 'text-18', 'bold' => 'font-weight-bold' ), true ),
			'order_item_name' => array( __( 'Item name', 'galaxie-woo' ), '.galaxie-order-line-name', array( 'bold' => 'font-weight-bold' ) ),
			'order_item_meta' => array( __( 'Item details and quantity', 'galaxie-woo' ), '.galaxie-order-line-meta', array( 'size' => 'text-sm', 'bold' => '' ) ),
			'order_item_total' => array( __( 'Item total', 'galaxie-woo' ), '.galaxie-order-line-total', array( 'bold' => 'font-weight-bold' ) ),
			'order_total_label' => array( __( 'Totals labels', 'galaxie-woo' ), '.galaxie-order-total-label', array( 'bold' => '' ) ),
			'order_total_value' => array( __( 'Totals values', 'galaxie-woo' ), '.galaxie-order-total-value', array( 'bold' => 'font-weight-bold' ) ),
			'order_body'      => array( __( 'Addresses, note and updates', 'galaxie-woo' ), '.galaxie-order-body', array( 'bold' => '' ) ),
		);

		// A fourth `true` marks text drawn by pixfort's Text element; the rest are
		// classes on this widget's own markup, which carry fewer controls.
		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', empty( $text[3] ) ? array( 'position', 'inline' ) : array( 'position' ) );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'order_thumbs_style', array( 'label' => __( 'Product pictures', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'order_show_items' => 'yes' ) ) );
		PixfortControls::thumb( $this, 'order_thumb', '{{WRAPPER}} .galaxie-order-line-thumb img', array( 'size' => 64 ), array(), '{{WRAPPER}} .galaxie-order-line-thumb' );
		$this->end_controls_section();
	}

	private function order(): ?\WC_Order {
		$current = AccountEndpoints::current();

		if ( 'view-order' === $current['key'] ) {
			return AccountParts::order_for( $current['value'] );
		}

		// Anywhere else (a template being designed, a dashboard) the latest order stands in.
		if ( is_user_logged_in() ) {
			$ids = wc_get_orders( array( 'customer_id' => get_current_user_id(), 'limit' => 1, 'return' => 'ids' ) );

			return $ids ? AccountParts::order_for( (string) $ids[0] ) : null;
		}

		return null;
	}

	/** @param array<string,mixed> $s */
	private function section( array $s, string $heading_id, string $body, string $class ): string {
		$heading = trim( (string) ( $s[ $heading_id ] ?? '' ) );

		return sprintf(
			'<section class="galaxie-order-section card %1$s %2$s">%3$s%4$s</section>',
			esc_attr( $class ),
			esc_attr( PixfortControls::surface_classes( $s, 'order_card' ) ),
			'' !== $heading ? '<div class="galaxie-order-section-heading">' . PixfortControls::render_text( $s, 'order_heading', esc_html( $heading ) ) . '</div>' : '',
			$body
		);
	}

	/**
	 * An address card in the address book's markup and saved look: its card,
	 * its nickname style for the heading, its address box and text.
	 *
	 * @param array<string,mixed> $s
	 * @param array<string,mixed> $book The address book widget's settings.
	 */
	private function address_card( array $s, array $book, string $heading_id, string $html, string $class ): string {
		$heading = trim( (string) ( $s[ $heading_id ] ?? '' ) );

		return sprintf(
			'<section class="galaxie-order-section galaxie-ab-card card %1$s %2$s">%3$s<address class="galaxie-ab-address %4$s">%5$s</address></section>',
			esc_attr( $class ),
			esc_attr( PixfortControls::surface_classes( $book, 'ab_card' ) ),
			'' !== $heading ? '<header class="galaxie-ab-card-head"><span class="galaxie-ab-label ' . esc_attr( PixfortControls::text_classes( $book, 'ab_label_text' ) ) . '">' . esc_html( $heading ) . '</span></header>' : '',
			esc_attr( trim( PixfortControls::text_classes( $book, 'ab_address_text' ) . ' ' . PixfortControls::surface_classes( $book, 'ab_box' ) ) ),
			$html
		);
	}

	/** @param array<string,mixed> $s */
	private function text( array $s, string $prefix, string $class, string $html ): string {
		return sprintf( '<span class="%1$s %2$s">%3$s</span>', esc_attr( $class ), esc_attr( PixfortControls::text_classes( $s, $prefix ) ), $html );
	}

	protected function render(): void {
		if ( ! function_exists( 'wc_get_order' ) || ( ! is_user_logged_in() && ! AccountParts::editing() ) ) {
			return;
		}

		Assets::enqueue();

		$s     = $this->get_settings_for_display();
		$order = $this->order();

		if ( ! $order ) {
			printf( '<div class="galaxie-account-order is-missing"><p class="%1$s">%2$s</p></div>', esc_attr( PixfortControls::text_classes( $s, 'order_body' ) ), esc_html( (string) ( $s['order_missing_text'] ?? '' ) ) );
			return;
		}

		$date = $order->get_date_created();
		// Cancelling follows Galaxie Account Orders too: its dialog, its alert and
		// its return screen, with the rules its CSS file holds written here.
		$inherit = 'yes' === ( $s['status_inherit'] ?? '' );
		$cancel  = $inherit ? array_merge( $s, AccountParts::orders_look(), array( 'cancel_alert_preview' => (string) ( $s['order_cancel_preview'] ?? '' ) ) ) : $s;
		$scope   = '.elementor-element-' . $this->get_id();
		$css     = $inherit ? Dialog::css( $cancel, 'cancel_confirm', $scope ) . AccountParts::cancelled_alert_css( $cancel, $scope ) : '';
		$out     = ( '' !== $css ? '<style>' . $css . '</style>' : '' ) . AccountParts::cancelled_alert( $cancel ) . Dialog::render( $cancel, 'cancel_confirm' );

		// Header: back link, title, date, status.
		$back = trim( (string) ( $s['order_back_text'] ?? '' ) );
		$out .= '<header class="galaxie-order-header">';

		if ( '' !== $back ) {
			$out .= '<div class="galaxie-order-back">' . AccountParts::link_button( $s, 'order_back', $back, AccountEndpoints::url( 'orders' ) ) . '</div>';
		}

		$out .= sprintf(
			'<div class="galaxie-order-header-main"><div class="galaxie-order-header-title">%1$s%2$s</div>%3$s</div>',
			$this->text( $s, 'order_title', 'galaxie-order-title', esc_html( str_replace( '{number}', $order->get_order_number(), (string) ( $s['order_title_text'] ?? '#{number}' ) ) ) ),
			$date ? $this->text( $s, 'order_date', 'galaxie-order-date', sprintf( '<time datetime="%1$s">%2$s</time>', esc_attr( $date->date( 'c' ) ), esc_html( wc_format_datetime( $date ) ) ) ) : '',
			AccountParts::status_badge( $s, $order )
		);

		if ( 'yes' === ( $s['order_show_actions'] ?? 'yes' ) ) {
			$look    = 'yes' === ( $s['status_inherit'] ?? '' ) ? AccountParts::orders_look() : array();
			$actions = AccountParts::order_actions( array_merge( $s, $look ), $order );

			if ( '' !== $actions ) {
				$css = '';

				// The colours and hover those buttons have on Galaxie Account Orders
				// live in that page's CSS, not here: written again for this element.
				if ( $look ) {
					foreach ( array( 'orders_pay', 'orders_cancel', 'orders_action' ) as $prefix ) {
						$css .= PixfortControls::button_css( array_merge( $s, $look ), $prefix, '.elementor-element-' . $this->get_id() );
					}
				}

				$out .= ( '' !== $css ? '<style>' . $css . '</style>' : '' ) . '<div class="galaxie-order-card-actions">' . $actions . '</div>';
			}
		}

		$out .= '</header>';

		// Items.
		if ( 'yes' === ( $s['order_show_items'] ?? 'yes' ) ) {
			$lines  = '';
			$radius = PixfortControls::thumb_classes( $s, 'order_thumb' );

			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$product = $item->get_product();
				$link    = $product && $product->is_visible() ? $product->get_permalink( $item ) : '';
				$name    = '' !== $link ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html( $item->get_name() ) ) : esc_html( $item->get_name() );
				$meta    = wc_display_item_meta( $item, array( 'echo' => false, 'before' => '', 'after' => '', 'separator' => ' · ', 'label_before' => '', 'label_after' => ': ' ) );
				$qty     = sprintf( /* translators: %s: quantity. */ __( 'Qtd.: %s', 'galaxie-woo' ), $item->get_quantity() );

				$lines .= sprintf(
					'<div class="galaxie-order-line">%1$s<div class="galaxie-order-line-text">%2$s%3$s</div>%4$s</div>',
					$product ? sprintf( '<span class="galaxie-order-line-thumb %1$s">%2$s</span>', esc_attr( $radius ), $product->get_image( 'thumbnail' ) ) : '',
					$this->text( $s, 'order_item_name', 'galaxie-order-line-name', $name ),
					$this->text( $s, 'order_item_meta', 'galaxie-order-line-meta', wp_kses_post( trim( ( '' !== wp_strip_all_tags( (string) $meta ) ? $meta . ' · ' : '' ) . esc_html( $qty ) ) ) ),
					$this->text( $s, 'order_item_total', 'galaxie-order-line-total', wp_kses_post( $order->get_formatted_line_subtotal( $item ) ) )
				);

				// Plugins add download links and personalisation here.
				ob_start();
				do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, false );
				$extra = (string) ob_get_clean();

				if ( '' !== trim( $extra ) ) {
					$lines .= '<div class="galaxie-order-line-extra galaxie-order-body">' . $extra . '</div>';
				}
			}

			$out .= $this->section( $s, 'order_items_heading', '<div class="galaxie-order-lines">' . $lines . '</div>', 'is-items' );
		}

		// Totals.
		if ( 'yes' === ( $s['order_show_totals'] ?? 'yes' ) ) {
			$rows = '';

			foreach ( $order->get_order_item_totals() as $key => $total ) {
				$rows .= sprintf(
					'<div class="galaxie-order-total-row is-%1$s">%2$s%3$s</div>',
					esc_attr( sanitize_html_class( (string) $key ) ),
					$this->text( $s, 'order_total_label', 'galaxie-order-total-label', esc_html( rtrim( wp_strip_all_tags( (string) $total['label'] ), ':' ) ) ),
					$this->text( $s, 'order_total_value', 'galaxie-order-total-value', wp_kses_post( (string) $total['value'] ) )
				);
			}

			$out .= $this->section( $s, 'order_totals_heading', '<div class="galaxie-order-totals">' . $rows . '</div>', 'is-totals' );
		}

		// Addresses.
		if ( 'yes' === ( $s['order_show_addresses'] ?? 'yes' ) ) {
			$addresses = array();
			$billing   = $order->get_formatted_billing_address();
			$shipping  = $order->needs_shipping_address() ? $order->get_formatted_shipping_address() : '';

			if ( $billing ) {
				$extra       = array_filter( array( esc_html( $order->get_billing_phone() ), esc_html( $order->get_billing_email() ) ) );
				$addresses[] = array( 'order_billing_heading', wp_kses_post( $billing ) . ( $extra ? '<br />' . implode( '<br />', $extra ) : '' ), 'is-billing' );
			}

			if ( $shipping ) {
				$addresses[] = array( 'order_shipping_heading', wp_kses_post( $shipping ), 'is-shipping' );
			}

			// The address book's look when it has one saved, so an address reads
			// the same on the order as on the customer's own address cards.
			$book = 'yes' === ( $s['order_addresses_inherit'] ?? '' ) ? AccountParts::look( 'addresses' ) : array();
			$grid = '';

			foreach ( $addresses as $address ) {
				$grid .= $book ? $this->address_card( $s, $book, $address[0], $address[1], $address[2] ) : $this->section( $s, $address[0], '<address class="galaxie-order-body ' . esc_attr( PixfortControls::text_classes( $s, 'order_body' ) ) . '">' . $address[1] . '</address>', $address[2] );
			}

			if ( '' !== $grid ) {
				$css = '';

				if ( $book ) {
					$scope = '.elementor-element.elementor-element-' . $this->get_id() . ' .galaxie-order-addresses';
					$css   = PixfortControls::surface_css( $book, 'ab_card', $scope . ' .galaxie-ab-card' ) . PixfortControls::surface_css( $book, 'ab_box', $scope . ' address.galaxie-ab-address' );
				}

				$out .= ( '' !== $css ? '<style>' . $css . '</style>' : '' ) . '<div class="galaxie-order-addresses">' . $grid . '</div>';
			}
		}

		// The customer's own note.
		$note = $order->get_customer_note();

		if ( 'yes' === ( $s['order_show_note'] ?? 'yes' ) && '' !== trim( $note ) ) {
			$out .= $this->section( $s, 'order_note_heading', '<div class="galaxie-order-body ' . esc_attr( PixfortControls::text_classes( $s, 'order_body' ) ) . '">' . wp_kses_post( wpautop( wptexturize( $note ) ) ) . '</div>', 'is-note' );
		}

		// What the store wrote to the customer.
		$updates = $order->get_customer_order_notes();

		if ( 'yes' === ( $s['order_show_updates'] ?? 'yes' ) && $updates ) {
			$list = '';

			foreach ( $updates as $update ) {
				$list .= sprintf(
					'<li class="galaxie-order-update"><time>%1$s</time><div>%2$s</div></li>',
					esc_html( date_i18n( wc_date_format() . ' ' . wc_time_format(), strtotime( $update->comment_date ) ) ),
					wp_kses_post( wpautop( wptexturize( $update->comment_content ) ) )
				);
			}

			$out .= $this->section( $s, 'order_updates_heading', '<ol class="galaxie-order-updates galaxie-order-body ' . esc_attr( PixfortControls::text_classes( $s, 'order_body' ) ) . '">' . $list . '</ol>', 'is-updates' );
		}

		// Tracking codes, invoices, pickup details.
		if ( 'yes' === ( $s['order_show_extras'] ?? 'yes' ) ) {
			ob_start();
			do_action( 'woocommerce_order_details_after_order_table', $order );
			$extras = (string) ob_get_clean();

			if ( '' !== trim( $extras ) ) {
				$out .= '<div class="galaxie-order-extras galaxie-order-body">' . $extras . '</div>';
			}
		}

		echo '<div class="galaxie-account-order">' . $out . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts and WooCommerce's own output.
	}
}

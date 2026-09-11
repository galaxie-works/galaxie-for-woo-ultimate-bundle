<?php
/**
 * "Galaxie Account Orders": the customer's orders.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\AccountEndpoints;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * Every order as a card, or as a table row, with what a customer looks for
 * first: its number, when, where it stands, how much, and what was in it.
 *
 * The query is WooCommerce's own, `woocommerce_my_account_my_orders_query`
 * included, so a plugin that narrows or widens the list still does. The
 * actions are WooCommerce's too (`wc_get_account_orders_actions()`): pay, view,
 * cancel, and whatever a plugin adds, such as "Order again".
 *
 * "Most recent" is the dashboard's short list: the latest few, no pages, and a
 * link to the rest.
 */
final class AccountOrdersWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account-orders';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Orders', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-product-meta';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'orders', 'pedidos', 'woocommerce' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'orders_section', array( 'label' => __( 'Orders', 'galaxie-woo' ) ) );

		$this->add_control(
			'orders_source',
			array(
				'label'   => __( 'Show', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'all'    => __( 'Every order, in pages', 'galaxie-woo' ),
					'recent' => __( 'The most recent only', 'galaxie-woo' ),
				),
				'default' => 'all',
			)
		);

		$this->add_control(
			'orders_per_page',
			array(
				'label'   => __( 'Orders per page', 'galaxie-woo' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 50,
				'default' => 10,
			)
		);

		$this->add_control(
			'orders_layout',
			array(
				'label'   => __( 'Layout', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'cards' => __( 'Cards', 'galaxie-woo' ),
					'table' => __( 'Table', 'galaxie-woo' ),
				),
				'default' => 'cards',
			)
		);

		$this->add_responsive_control(
			'orders_columns',
			array(
				'label'          => __( 'Columns', 'galaxie-woo' ),
				'type'           => Controls_Manager::SELECT,
				'options'        => array( '1' => '1', '2' => '2', '3' => '3' ),
				'default'        => '1',
				'tablet_default' => '1',
				'mobile_default' => '1',
				'selectors'      => array( '{{WRAPPER}} .galaxie-account-orders.is-cards .galaxie-orders-list' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
				'condition'      => array( 'orders_layout' => 'cards' ),
			)
		);

		$this->add_control(
			'orders_heading',
			array(
				'label'       => __( 'Heading', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => '',
				'placeholder' => __( 'Seus pedidos', 'galaxie-woo' ),
				'separator'   => 'before',
			)
		);

		$this->add_control(
			'orders_number_text',
			array(
				'label'       => __( 'Order number', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Pedido #{number}', 'galaxie-woo' ),
				'description' => __( '{number} is the order number.', 'galaxie-woo' ),
			)
		);

		$this->add_control( 'orders_show_date', array( 'label' => __( 'Date', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'orders_show_status', array( 'label' => __( 'Status', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'orders_show_thumbs', array( 'label' => __( 'Product pictures', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );

		$this->add_control(
			'orders_thumbs_max',
			array(
				'label'     => __( 'Pictures before "+n"', 'galaxie-woo' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 8,
				'default'   => 4,
				'condition' => array( 'orders_show_thumbs' => 'yes' ),
			)
		);

		$this->add_control( 'orders_show_items', array( 'label' => __( 'Item count', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'orders_show_total', array( 'label' => __( 'Total', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'orders_show_actions', array( 'label' => __( 'Pay, cancel and other actions', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );

		$this->add_control(
			'orders_all_text',
			array(
				'label'     => __( '"See every order" link', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Ver todos os pedidos', 'galaxie-woo' ),
				'condition' => array( 'orders_source' => 'recent' ),
				'separator' => 'before',
			)
		);

		$this->add_control( 'orders_prev_text', array( 'label' => __( 'Previous page', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Anteriores', 'galaxie-woo' ), 'condition' => array( 'orders_source' => 'all' ), 'separator' => 'before' ) );
		$this->add_control( 'orders_next_text', array( 'label' => __( 'Next page', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Mais antigos', 'galaxie-woo' ), 'condition' => array( 'orders_source' => 'all' ) ) );

		$this->add_control(
			'orders_empty_text',
			array(
				'label'     => __( 'When there are no orders', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXTAREA,
				'rows'      => 2,
				'default'   => __( 'Você ainda não fez nenhum pedido.', 'galaxie-woo' ),
				'separator' => 'before',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'orders_view_button_section', array( 'label' => __( 'View button', 'galaxie-woo' ) ) );
		PixfortControls::button( $this, 'orders_view', array( 'text' => __( 'Ver pedido', 'galaxie-woo' ), 'style' => 'outline', 'size' => 'sm' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'orders_action_button_section', array( 'label' => __( 'Other action buttons', 'galaxie-woo' ), 'condition' => array( 'orders_show_actions' => 'yes' ) ) );
		PixfortControls::button( $this, 'orders_action', array( 'style' => 'link', 'size' => 'sm' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'orders_empty_button_section', array( 'label' => __( 'Empty list button', 'galaxie-woo' ) ) );
		PixfortControls::button( $this, 'orders_shop', array( 'text' => __( 'Ver produtos', 'galaxie-woo' ) ) );
		$this->end_controls_section();

		AccountParts::register_status_controls( $this );

		$this->start_controls_section( 'orders_card_style', array( 'label' => __( 'Card or row', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );

		PixfortControls::surface( $this, 'orders_card', '{{WRAPPER}} .galaxie-order-card, {{WRAPPER}} .galaxie-orders-table', array( 'rounded' => 'rounded-lg' ) );

		$this->add_responsive_control(
			'orders_gap',
			array(
				'label'      => __( 'Space between orders', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-orders-list' => 'gap: {{SIZE}}{{UNIT}};' ),
				'condition'  => array( 'orders_layout' => 'cards' ),
			)
		);

		PixfortControls::palette_control( $this, 'orders_divider', __( 'Divider color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-order-card-foot, {{WRAPPER}} .galaxie-orders-table td, {{WRAPPER}} .galaxie-orders-table th', 'border-color' );

		$this->end_controls_section();

		$texts = array(
			'orders_heading_text' => array( __( 'Heading', 'galaxie-woo' ), '.galaxie-account-orders-heading', array( 'size' => 'text-20', 'bold' => 'font-weight-bold' ) ),
			'orders_number'       => array( __( 'Order number', 'galaxie-woo' ), '.galaxie-order-number', array( 'bold' => 'font-weight-bold' ) ),
			'orders_date'         => array( __( 'Date', 'galaxie-woo' ), '.galaxie-order-date', array( 'size' => 'text-sm', 'bold' => '' ) ),
			'orders_items'        => array( __( 'Item count', 'galaxie-woo' ), '.galaxie-order-items', array( 'size' => 'text-sm', 'bold' => '' ) ),
			'orders_total'        => array( __( 'Total', 'galaxie-woo' ), '.galaxie-order-total', array( 'bold' => 'font-weight-bold' ) ),
			'orders_empty'        => array( __( 'Empty list text', 'galaxie-woo' ), '.galaxie-account-orders-empty', array( 'bold' => '' ) ),
		);

		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', array( 'position' ) );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'orders_thumbs_style', array( 'label' => __( 'Product pictures', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'orders_show_thumbs' => 'yes' ) ) );
		PixfortControls::thumb( $this, 'orders_thumb', '{{WRAPPER}} .galaxie-order-thumb img', array( 'size' => 48 ), array(), '{{WRAPPER}} .galaxie-order-thumb' );
		$this->end_controls_section();
	}

	/** @return array{orders:\WC_Order[],pages:int,page:int} */
	private function query( array $settings ): array {
		$recent   = 'recent' === ( $settings['orders_source'] ?? 'all' );
		$per_page = max( 1, min( 50, (int) ( $settings['orders_per_page'] ?? 10 ) ) );
		$current  = AccountEndpoints::current();
		$page     = ! $recent && 'orders' === $current['key'] ? max( 1, absint( $current['value'] ) ) : 1;

		$result = wc_get_orders(
			apply_filters(
				'woocommerce_my_account_my_orders_query',
				array(
					'customer' => get_current_user_id(),
					'page'     => $page,
					'paginate' => true,
					'limit'    => $per_page,
				)
			)
		);

		return array(
			'orders' => array_filter( (array) ( $result->orders ?? array() ), static fn( $o ) => $o instanceof \WC_Order ),
			'pages'  => $recent ? 1 : (int) ( $result->max_num_pages ?? 1 ),
			'page'   => $page,
		);
	}

	protected function render(): void {
		if ( ! function_exists( 'wc_get_orders' ) || ( ! is_user_logged_in() && ! AccountParts::editing() ) ) {
			return;
		}

		Assets::enqueue();

		$s      = $this->get_settings_for_display();
		$data   = $this->query( $s );
		$layout = 'table' === ( $s['orders_layout'] ?? 'cards' ) ? 'table' : 'cards';

		printf( '<div class="galaxie-account-orders is-%s">', esc_attr( $layout ) );

		$heading = trim( (string) ( $s['orders_heading'] ?? '' ) );

		if ( '' !== $heading ) {
			printf( '<div class="galaxie-account-orders-heading">%s</div>', PixfortControls::render_text( $s, 'orders_heading_text', esc_html( $heading ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		if ( ! $data['orders'] ) {
			printf(
				'<div class="galaxie-account-orders-empty-box"><div class="galaxie-account-orders-empty">%1$s</div>%2$s</div></div>',
				PixfortControls::render_text( $s, 'orders_empty', esc_html( (string) ( $s['orders_empty_text'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
				AccountParts::link_button( $s, 'orders_shop', (string) ( $s['orders_shop_text'] ?? __( 'Ver produtos', 'galaxie-woo' ) ), (string) apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			);
			return;
		}

		if ( 'table' === $layout ) {
			$this->render_table( $s, $data['orders'] );
		} else {
			echo '<div class="galaxie-orders-list">';

			foreach ( $data['orders'] as $order ) {
				$this->render_card( $s, $order );
			}

			echo '</div>';
		}

		if ( 'recent' === ( $s['orders_source'] ?? 'all' ) ) {
			$all = trim( (string) ( $s['orders_all_text'] ?? '' ) );

			if ( '' !== $all ) {
				printf( '<div class="galaxie-account-orders-more">%s</div>', AccountParts::link_button( $s, 'orders_action', $all, AccountEndpoints::url( 'orders' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			}
		} elseif ( $data['pages'] > 1 ) {
			echo '<nav class="galaxie-account-orders-pages">';

			if ( $data['page'] > 1 ) {
				echo AccountParts::link_button( $s, 'orders_view', (string) ( $s['orders_prev_text'] ?? '' ), (string) wc_get_endpoint_url( 'orders', (string) ( $data['page'] - 1 ) ), 'is-prev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			}

			if ( $data['page'] < $data['pages'] ) {
				echo AccountParts::link_button( $s, 'orders_view', (string) ( $s['orders_next_text'] ?? '' ), (string) wc_get_endpoint_url( 'orders', (string) ( $data['page'] + 1 ) ), 'is-next' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			}

			echo '</nav>';
		}

		echo '</div>';
	}

	/** @return array<string,string> The pieces a card and a row share. */
	private function parts( array $s, \WC_Order $order ): array {
		$text  = static fn( string $prefix, string $content ): string => sprintf( '<span class="%1$s %2$s">%3$s</span>', 'galaxie-' . str_replace( 'orders_', 'order-', $prefix ), esc_attr( PixfortControls::text_classes( $s, $prefix ) ), $content );
		$count = $order->get_item_count();

		$actions = '';

		if ( 'yes' === ( $s['orders_show_actions'] ?? 'yes' ) ) {
			foreach ( wc_get_account_orders_actions( $order ) as $key => $action ) {
				if ( 'view' === $key ) {
					continue;
				}

				$actions .= AccountParts::link_button( $s, 'orders_action', (string) $action['name'], (string) $action['url'], 'is-' . sanitize_html_class( (string) $key ) );
			}
		}

		$thumbs = '';

		if ( 'yes' === ( $s['orders_show_thumbs'] ?? 'yes' ) ) {
			$max    = max( 1, (int) ( $s['orders_thumbs_max'] ?? 4 ) );
			$items  = array_values( $order->get_items() );
			$shown  = 0;
			$radius = PixfortControls::thumb_classes( $s, 'orders_thumb' );

			foreach ( $items as $item ) {
				if ( $shown >= $max ) {
					break;
				}

				$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;

				if ( ! $product ) {
					continue;
				}

				$thumbs .= sprintf( '<span class="galaxie-order-thumb %1$s" title="%2$s">%3$s</span>', esc_attr( $radius ), esc_attr( $item->get_name() ), $product->get_image( 'thumbnail' ) );
				++$shown;
			}

			if ( count( $items ) > $shown && $shown > 0 ) {
				$thumbs .= sprintf( '<span class="galaxie-order-thumb is-more %1$s">+%2$d</span>', esc_attr( $radius ), count( $items ) - $shown );
			}
		}

		$number = str_replace( '{number}', $order->get_order_number(), (string) ( $s['orders_number_text'] ?? '#{number}' ) );
		$date   = $order->get_date_created();

		return array(
			'number'  => $text( 'orders_number', esc_html( $number ) ),
			'date'    => 'yes' === ( $s['orders_show_date'] ?? 'yes' ) && $date ? $text( 'orders_date', sprintf( '<time datetime="%1$s">%2$s</time>', esc_attr( $date->date( 'c' ) ), esc_html( wc_format_datetime( $date ) ) ) ) : '',
			'status'  => 'yes' === ( $s['orders_show_status'] ?? 'yes' ) ? AccountParts::status_badge( $s, $order ) : '',
			'items'   => 'yes' === ( $s['orders_show_items'] ?? 'yes' ) ? $text( 'orders_items', esc_html( sprintf( /* translators: %d: number of items. */ _n( '%d item', '%d itens', $count, 'galaxie-woo' ), $count ) ) ) : '',
			'total'   => 'yes' === ( $s['orders_show_total'] ?? 'yes' ) ? $text( 'orders_total', wp_kses_post( $order->get_formatted_order_total() ) ) : '',
			'thumbs'  => $thumbs,
			'view'    => AccountParts::link_button( $s, 'orders_view', (string) ( $s['orders_view_text'] ?? __( 'Ver pedido', 'galaxie-woo' ) ), $order->get_view_order_url(), 'is-view' ),
			'actions' => $actions,
		);
	}

	private function render_card( array $s, \WC_Order $order ): void {
		$p = $this->parts( $s, $order );

		printf(
			'<article class="galaxie-order-card card %1$s">
				<div class="galaxie-order-card-head"><div class="galaxie-order-card-title">%2$s%3$s</div>%4$s</div>
				%5$s
				<div class="galaxie-order-card-foot"><div class="galaxie-order-card-summary">%6$s%7$s</div><div class="galaxie-order-card-actions">%8$s%9$s</div></div>
			</article>',
			esc_attr( PixfortControls::surface_classes( $s, 'orders_card' ) ),
			$p['number'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$p['date'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$p['status'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own badge.
			'' !== $p['thumbs'] ? '<div class="galaxie-order-thumbs">' . $p['thumbs'] . '</div>' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$p['items'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$p['total'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$p['actions'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$p['view'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
	}

	/** @param \WC_Order[] $orders */
	private function render_table( array $s, array $orders ): void {
		printf( '<div class="galaxie-orders-table-wrap"><table class="galaxie-orders-table %s"><tbody>', esc_attr( PixfortControls::surface_classes( $s, 'orders_card' ) ) );

		foreach ( $orders as $order ) {
			$p = $this->parts( $s, $order );

			printf(
				'<tr><td class="is-order"><div class="galaxie-order-card-title">%1$s%2$s</div></td><td class="is-status">%3$s</td><td class="is-thumbs">%4$s</td><td class="is-total">%5$s%6$s</td><td class="is-actions"><div class="galaxie-order-card-actions">%7$s%8$s</div></td></tr>',
				$p['number'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				$p['date'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				$p['status'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own badge.
				'' !== $p['thumbs'] ? '<div class="galaxie-order-thumbs">' . $p['thumbs'] . '</div>' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				$p['total'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				$p['items'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				$p['actions'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				$p['view'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			);
		}

		echo '</tbody></table></div>';
	}
}

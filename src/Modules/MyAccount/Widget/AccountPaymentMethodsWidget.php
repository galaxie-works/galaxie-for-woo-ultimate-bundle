<?php
/**
 * "Galaxie Account Payment Methods": the cards the customer saved.
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
 * Each saved payment method drawn as the card it is — brand mark, chip, the
 * last four digits, the expiry — from WooCommerce's own list,
 * `woocommerce_saved_payment_methods_list`, rather than from any one gateway.
 * Stripe, or whatever replaces it, adds its methods to that list, so the widget
 * never needs to know which gateway holds the card.
 *
 * Nothing here handles a card number. Making a card the default and deleting
 * one go through WooCommerce's own nonce-protected links, which is also what
 * lets the gateway hear about the deletion and detach the card on its side.
 * Adding a card opens WooCommerce's add-payment-method screen: that is the one
 * page where a gateway loads its secure card form.
 */
final class AccountPaymentMethodsWidget extends Widget_Base {

	/**
	 * Brand name, lowercased and stripped to letters => pixfort icon. The two
	 * brands pixfort draws come from its Solid set; any other brand — Elo,
	 * Hipercard, Amex — gets its generic card outline rather than a wrong logo.
	 */
	private const BRAND_ICONS = array(
		'visa'       => 'Solid/pixfort-icon-visa-1',
		'mastercard' => 'Solid/pixfort-icon-mastercard-1',
		'master'     => 'Solid/pixfort-icon-mastercard-1',
	);

	private const GENERIC_ICON = 'Line/pixfort-icon-credit-card-1';

	public function get_name(): string {
		return 'galaxie-account-payment-methods';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Payment Methods', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-price-table';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'payment', 'cards', 'cartões', 'formas de pagamento', 'stripe' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'pm_section', array( 'label' => __( 'Payment methods', 'galaxie-woo' ) ) );

		$this->add_control( 'pm_heading', array( 'label' => __( 'Heading', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Formas de pagamento', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_intro', array( 'label' => __( 'Intro text', 'galaxie-woo' ), 'type' => Controls_Manager::TEXTAREA, 'rows' => 2, 'default' => __( 'Os cartões que você salvou para comprar mais rápido. Os dados ficam guardados com o processador de pagamento, nunca na loja.', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_empty_text', array( 'label' => __( 'When there are no cards', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Você ainda não salvou nenhum cartão.', 'galaxie-woo' ) ) );

		$this->add_control( 'pm_expires_label', array( 'label' => __( 'Expiry label', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Validade', 'galaxie-woo' ), 'separator' => 'before' ) );
		$this->add_control( 'pm_badge_default', array( 'label' => __( 'Default badge', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Padrão', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_badge_expiring', array( 'label' => __( 'Expiring soon badge', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Vence em breve', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_badge_expired', array( 'label' => __( 'Expired badge', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Vencido', 'galaxie-woo' ) ) );
		$this->add_control( 'pm_confirm', array( 'label' => __( 'Delete confirmation', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Excluir este cartão?', 'galaxie-woo' ) ) );

		$this->add_control(
			'pm_show_icons',
			array(
				'label'        => __( 'Card brand icon', 'galaxie-woo' ),
				'description'  => __( 'Visa and Mastercard get their own mark; any other brand gets a generic card.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before',
			)
		);

		$this->add_control( 'pm_show_brand_name', array( 'label' => __( 'Brand name', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'pm_show_chip', array( 'label' => __( 'Chip', 'galaxie-woo' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );

		$this->add_responsive_control(
			'pm_columns',
			array(
				'label'          => __( 'Columns', 'galaxie-woo' ),
				'type'           => Controls_Manager::SELECT,
				'options'        => array( '1' => '1', '2' => '2', '3' => '3' ),
				'default'        => '2',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'selectors'      => array( '{{WRAPPER}} .galaxie-pm-cards' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
				'separator'      => 'before',
			)
		);

		$this->end_controls_section();

		$buttons = array(
			'pm_add'     => array( __( 'Add card button', 'galaxie-woo' ), array( 'text' => __( 'Adicionar cartão', 'galaxie-woo' ), 'size' => 'sm' ) ),
			'pm_default' => array( __( 'Make default button', 'galaxie-woo' ), array( 'text' => __( 'Usar como padrão', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
			'pm_delete'  => array( __( 'Delete button', 'galaxie-woo' ), array( 'text' => __( 'Excluir', 'galaxie-woo' ), 'style' => 'link', 'size' => 'sm' ) ),
		);

		foreach ( $buttons as $prefix => $button ) {
			$this->start_controls_section( $prefix . '_section', array( 'label' => $button[0] ) );
			PixfortControls::button( $this, $prefix, $button[1] );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'pm_card_style', array( 'label' => __( 'Cards', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'pm_card', '{{WRAPPER}} .galaxie-pm-card', array( 'rounded' => 'rounded-lg' ) );

		$this->add_control(
			'pm_card_ratio',
			array(
				'label'        => __( 'Credit card proportions', 'galaxie-woo' ),
				'description'  => __( 'The 85.6 × 54 mm shape of a real card. Off, the card is as tall as its content.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before',
				'selectors'    => array( '{{WRAPPER}} .galaxie-pm-card' => 'aspect-ratio: 1.586 / 1;' ),
			)
		);

		$this->add_responsive_control(
			'pm_card_width',
			array(
				'label'      => __( 'Card max width', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array( 'px' => array( 'min' => 200, 'max' => 640 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-pm-item' => 'max-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'pm_gap',
			array(
				'label'      => __( 'Space between cards', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-pm-cards' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'pm_icon_style', array( 'label' => __( 'Brand icon', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'pm_show_icons' => 'yes' ) ) );

		$this->add_responsive_control(
			'pm_icon_size',
			array(
				'label'      => __( 'Size', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 16, 'max' => 96 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-pm-brand .pixfort-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);

		PixfortControls::icon_color( $this, 'pm_icon_color', __( 'Color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-pm-brand' );

		$this->end_controls_section();

		$this->start_controls_section( 'pm_badge_style', array( 'label' => __( 'Badges', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'pm_badge', '{{WRAPPER}} .galaxie-pm-badge', array( 'rounded' => 'rounded-pill' ) );

		$this->add_control( 'pm_warning_heading', array( 'label' => __( 'Expiring and expired', 'galaxie-woo' ), 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		PixfortControls::palette_control( $this, 'pm_warning_color', __( 'Text color', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-pm-badge.is-expiring, {{WRAPPER}} .galaxie-pm-badge.is-expired', 'color' );
		PixfortControls::palette_control( $this, 'pm_warning_bg', __( 'Background', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-pm-badge.is-expiring, {{WRAPPER}} .galaxie-pm-badge.is-expired', 'background-color' );

		$this->end_controls_section();

		$texts = array(
			'pm_heading_text' => array( __( 'Heading', 'galaxie-woo' ), '.galaxie-pm-heading', array( 'size' => 'text-20', 'bold' => 'font-weight-bold' ), true ),
			'pm_intro_text'   => array( __( 'Intro text', 'galaxie-woo' ), '.galaxie-pm-intro', array( 'bold' => '' ), true ),
			'pm_number_text'  => array( __( 'Card number', 'galaxie-woo' ), '.galaxie-pm-number', array( 'size' => 'text-20', 'bold' => '' ) ),
			'pm_expiry_text'  => array( __( 'Expiry', 'galaxie-woo' ), '.galaxie-pm-expiry', array( 'size' => 'text-sm', 'bold' => '' ) ),
			'pm_brand_text'   => array( __( 'Brand name', 'galaxie-woo' ), '.galaxie-pm-brand-name', array( 'size' => 'text-sm', 'bold' => 'font-weight-bold' ) ),
			'pm_badge_text'   => array( __( 'Badge text', 'galaxie-woo' ), '.galaxie-pm-badge', array( 'size' => 'text-xs', 'bold' => 'font-weight-bold' ) ),
			'pm_empty_body'   => array( __( 'Empty list text', 'galaxie-woo' ), '.galaxie-pm-empty', array( 'bold' => '' ) ),
		);

		// A fourth `true` marks text drawn by pixfort's Text element; the rest are
		// classes on this widget's own markup, which carry fewer controls.
		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', empty( $text[3] ) ? array( 'position', 'inline' ) : array( 'position' ) );
			$this->end_controls_section();
		}
	}

	protected function render(): void {
		if ( ! function_exists( 'wc_get_customer_saved_methods_list' ) || ( ! is_user_logged_in() && ! AccountParts::editing() ) ) {
			return;
		}

		Assets::enqueue();

		$s       = $this->get_settings_for_display();
		$editing = AccountParts::editing();
		$methods = is_user_logged_in() ? self::methods( get_current_user_id() ) : array();

		// Something to style in the editor, where the account may have no cards:
		// one of each icon and each warning.
		if ( ! $methods && $editing ) {
			$methods = array(
				array( 'method' => array( 'brand' => 'Visa', 'last4' => '4242' ), 'expires' => '12/30', 'is_default' => true, 'actions' => array( 'delete' => array( 'url' => '#' ) ) ),
				array( 'method' => array( 'brand' => 'Mastercard', 'last4' => '4444' ), 'expires' => gmdate( 'm/y', strtotime( '+1 month' ) ), 'is_default' => false, 'actions' => array( 'delete' => array( 'url' => '#' ), 'default' => array( 'url' => '#' ) ) ),
				array( 'method' => array( 'brand' => 'Elo', 'last4' => '0001' ), 'expires' => '01/24', 'is_default' => false, 'actions' => array( 'delete' => array( 'url' => '#' ), 'default' => array( 'url' => '#' ) ) ),
			);
		}

		printf( '<div class="galaxie-payment-methods" data-confirm="%s">', esc_attr( (string) ( $s['pm_confirm'] ?? '' ) ) );

		$heading = trim( (string) ( $s['pm_heading'] ?? '' ) );
		$intro   = trim( (string) ( $s['pm_intro'] ?? '' ) );

		if ( '' !== $heading ) {
			printf( '<div class="galaxie-pm-heading">%s</div>', PixfortControls::render_text( $s, 'pm_heading_text', esc_html( $heading ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		if ( '' !== $intro ) {
			printf( '<div class="galaxie-pm-intro">%s</div>', PixfortControls::render_text( $s, 'pm_intro_text', esc_html( $intro ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		}

		if ( $methods ) {
			echo '<div class="galaxie-pm-cards">';

			foreach ( $methods as $method ) {
				echo $this->card( $s, $method ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			}

			echo '</div>';
		} else {
			printf( '<p class="galaxie-pm-empty %1$s">%2$s</p>', esc_attr( PixfortControls::text_classes( $s, 'pm_empty_body' ) ), esc_html( (string) ( $s['pm_empty_text'] ?? '' ) ) );
		}

		if ( $editing || self::can_add() ) {
			printf(
				'<div class="galaxie-pm-toolbar">%s</div>',
				AccountParts::link_button( $s, 'pm_add', (string) ( $s['pm_add_text'] ?? '' ), $editing ? '#' : AccountEndpoints::url( 'add-payment-method' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			);
		}

		echo '</div>';
	}

	/**
	 * WooCommerce's saved-methods list, flattened across method types, with the
	 * default first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function methods( int $user_id ): array {
		$flat = array();

		foreach ( (array) wc_get_customer_saved_methods_list( $user_id ) as $items ) {
			foreach ( (array) $items as $item ) {
				if ( is_array( $item ) ) {
					$flat[] = $item;
				}
			}
		}

		usort( $flat, static fn( array $a, array $b ): int => (int) ! empty( $b['is_default'] ) <=> (int) ! empty( $a['is_default'] ) );

		return $flat;
	}

	/** Whether any available gateway can save a card outside checkout. */
	private static function can_add(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}

		foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
			if ( $gateway->supports( 'add_payment_method' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 'expired' past the last day of its month, 'expiring' within 60 days of
	 * it, '' otherwise — including when the gateway gave no date at all.
	 */
	private static function expiry_state( string $expires ): string {
		if ( ! preg_match( '#^(\d{1,2})\s*/\s*(\d{2}|\d{4})$#', trim( $expires ), $match ) ) {
			return '';
		}

		$year = (int) $match[2];
		$year = $year < 100 ? 2000 + $year : $year;
		$end  = gmmktime( 23, 59, 59, (int) $match[1] + 1, 0, $year );
		$now  = time();

		if ( $now > $end ) {
			return 'expired';
		}

		return $end - $now < 60 * DAY_IN_SECONDS ? 'expiring' : '';
	}

	/** The brand's pixfort icon, or the generic card when pixfort has none for it. */
	private static function brand_icon( string $brand ): string {
		if ( ! class_exists( '\PixfortCore' ) ) {
			return '';
		}

		$key  = (string) preg_replace( '/[^a-z]/', '', strtolower( $brand ) );
		$name = self::BRAND_ICONS[ $key ] ?? self::GENERIC_ICON;

		return (string) \PixfortCore::instance()->icons->getIcon( $name, 48, 'galaxie-pm-brand-icon' );
	}

	/**
	 * @param array<string,mixed> $s
	 * @param array<string,mixed> $method
	 */
	private function card( array $s, array $method ): string {
		$brand   = (string) ( $method['method']['brand'] ?? '' );
		$last4   = (string) ( $method['method']['last4'] ?? '' );
		$expires = (string) ( $method['expires'] ?? '' );
		$default = ! empty( $method['is_default'] );
		$actions = (array) ( $method['actions'] ?? array() );
		$state   = self::expiry_state( $expires );
		$key     = (string) preg_replace( '/[^a-z]/', '', strtolower( $brand ) );
		$badge   = trim( PixfortControls::text_classes( $s, 'pm_badge_text' ) . ' ' . PixfortControls::surface_classes( $s, 'pm_badge' ) );
		$icon    = 'yes' === ( $s['pm_show_icons'] ?? 'yes' ) ? self::brand_icon( $brand ) : '';

		$badges = $default ? sprintf( '<span class="galaxie-pm-badge is-default %1$s">%2$s</span>', esc_attr( $badge ), esc_html( (string) ( $s['pm_badge_default'] ?? '' ) ) ) : '';

		if ( '' !== $state ) {
			$badges .= sprintf( '<span class="galaxie-pm-badge is-%1$s %2$s">%3$s</span>', esc_attr( $state ), esc_attr( $badge ), esc_html( (string) ( $s[ 'pm_badge_' . $state ] ?? '' ) ) );
		}

		$buttons = '';

		if ( ! empty( $actions['default']['url'] ) ) {
			$buttons .= AccountParts::link_button( $s, 'pm_default', (string) ( $s['pm_default_text'] ?? '' ), (string) $actions['default']['url'], 'galaxie-pm-default' );
		}

		if ( ! empty( $actions['delete']['url'] ) ) {
			$buttons .= AccountParts::link_button( $s, 'pm_delete', (string) ( $s['pm_delete_text'] ?? '' ), (string) $actions['delete']['url'], 'galaxie-pm-delete' );
		}

		$has_date = '' !== $expires && preg_match( '#\d#', $expires );
		$classes  = array(
			'galaxie-pm-card',
			'card',
			PixfortControls::surface_classes( $s, 'pm_card' ),
			'is-' . ( isset( self::BRAND_ICONS[ $key ] ) ? $key : 'generic' ),
			$default ? 'is-default' : '',
			'' !== $state ? 'is-' . $state : '',
		);

		return sprintf(
			'<article class="galaxie-pm-item"><div class="%1$s"><div class="galaxie-pm-card-top"><span class="galaxie-pm-brand">%2$s</span><span class="galaxie-pm-badges">%3$s</span></div>%4$s%5$s<div class="galaxie-pm-card-bottom">%6$s%7$s</div></div>%8$s</article>',
			esc_attr( trim( implode( ' ', array_filter( $classes ) ) ) ),
			$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own icon markup.
			$badges,
			'yes' === ( $s['pm_show_chip'] ?? 'yes' ) ? '<span class="galaxie-pm-chip" aria-hidden="true"></span>' : '',
			'' !== $last4 ? sprintf( '<div class="galaxie-pm-number %1$s"><span aria-hidden="true">•••• •••• ••••</span> %2$s</div>', esc_attr( PixfortControls::text_classes( $s, 'pm_number_text' ) ), esc_html( $last4 ) ) : '',
			$has_date ? sprintf( '<div class="galaxie-pm-expiry %1$s"><span class="galaxie-pm-expiry-label">%2$s</span> %3$s</div>', esc_attr( PixfortControls::text_classes( $s, 'pm_expiry_text' ) ), esc_html( (string) ( $s['pm_expires_label'] ?? '' ) ), esc_html( $expires ) ) : '<span></span>',
			'yes' === ( $s['pm_show_brand_name'] ?? 'yes' ) && '' !== $brand ? sprintf( '<span class="galaxie-pm-brand-name %1$s">%2$s</span>', esc_attr( PixfortControls::text_classes( $s, 'pm_brand_text' ) ), esc_html( $brand ) ) : '',
			'' !== $buttons ? '<div class="galaxie-pm-card-actions">' . $buttons . '</div>' : ''
		);
	}
}

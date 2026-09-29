<?php
/**
 * Legal pages: which pages are the terms, the privacy policy and the returns
 * policy, the links to them, and the record of their acceptance.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\LegalPages;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesSettings;

defined( 'ABSPATH' ) || exit;

/**
 * The three pages are chosen on this module's tab (wp-admin → Galaxie →
 * Páginas legais). An empty choice falls back to what WordPress and
 * WooCommerce already point at — Settings → Privacy's policy page and
 * WooCommerce → Settings → Advanced's terms page — and a choice made here is
 * written back to both, so WooCommerce's own checkout checkbox and privacy
 * text link to the same pages.
 *
 * The consent checkbox of the sign-up form ("Li e aceito os termos de uso e
 * a política de privacidade") turns those two phrases into links from the
 * boot data; a page that is not set leaves its phrase as plain text.
 *
 * Every acceptance is recorded, on the account and on the order when it
 * happens at checkout: when (UTC), which version of each document (page id
 * and its last modification, UTC), and where (cadastro, checkout, google).
 * Only the latest acceptance is kept on the account; each order keeps its own.
 */
final class Module implements ModuleContract, ProvidesSettings, ProvidesBootData {

	public const META_ACCEPTED_AT     = '_galaxie_terms_accepted_at';
	public const META_TERMS_VERSION   = '_galaxie_terms_version';
	public const META_PRIVACY_VERSION = '_galaxie_privacy_version';
	public const META_SOURCE          = '_galaxie_terms_source';

	/** Documents, as setting key => label. */
	private const DOCUMENTS = array(
		'terms'   => 'Termos de uso',
		'privacy' => 'Política de privacidade',
		'returns' => 'Trocas e devoluções',
	);

	public function id(): string {
		return 'legal-pages';
	}

	public function title(): string {
		return __( 'Páginas legais', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Termos de uso, política de privacidade e trocas: links no aceite do cadastro e registro de cada aceite (data, versão, origem).', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_action( 'galaxie_woo/customer_registered', array( $this, 'on_customer_registered' ), 5, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_checkout' ), 10, 3 );
	}

	public function boot_data(): array {
		$legal = array();

		foreach ( array_keys( self::DOCUMENTS ) as $doc ) {
			$id = self::page_id( $doc );
			if ( $id > 0 ) {
				$legal[ $doc ] = array(
					'url'   => (string) get_permalink( $id ),
					'title' => get_the_title( $id ),
				);
			}
		}

		return array( 'legal' => $legal );
	}

	/**
	 * The published page chosen for `$doc` ('terms', 'privacy', 'returns'),
	 * falling back to WordPress's and WooCommerce's own; 0 when none.
	 */
	public static function page_id( string $doc ): int {
		$settings = \Galaxie\Woo\Core\Plugin::instance()->settings()->module_settings( 'legal-pages' );
		$id       = (int) ( $settings[ $doc . '_page' ] ?? 0 );

		if ( $id <= 0 ) {
			if ( 'terms' === $doc ) {
				$id = (int) get_option( 'woocommerce_terms_page_id', 0 );
			} elseif ( 'privacy' === $doc ) {
				$id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
			}
		}

		return $id > 0 && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	/**
	 * The version of a document as it stands now: page id and its last
	 * modification in UTC, e.g. "123@2026-09-28 14:03:11". Empty when the
	 * document has no page.
	 */
	public static function version( string $doc ): string {
		$id = self::page_id( $doc );

		return $id > 0 ? $id . '@' . (string) get_post_field( 'post_modified_gmt', $id ) : '';
	}

	/**
	 * Records that the terms and the privacy policy were accepted, on the
	 * account and, when given, on the order.
	 *
	 * @param string $source 'cadastro', 'checkout' or 'google'.
	 */
	public static function record_acceptance( int $user_id, string $source, ?\WC_Order $order = null ): void {
		$record = array(
			self::META_ACCEPTED_AT     => gmdate( 'Y-m-d H:i:s' ),
			self::META_TERMS_VERSION   => self::version( 'terms' ),
			self::META_PRIVACY_VERSION => self::version( 'privacy' ),
			self::META_SOURCE          => sanitize_key( $source ),
		);

		if ( $user_id > 0 ) {
			foreach ( $record as $key => $value ) {
				update_user_meta( $user_id, $key, $value );
			}
		}

		if ( $order ) {
			foreach ( $record as $key => $value ) {
				$order->update_meta_data( $key, $value );
			}
			$order->save();
		}
	}

	/**
	 * A new account: the sign-up form will not submit without the terms box,
	 * and PasswordlessAuth refuses it server side too, so reaching here means
	 * it was ticked.
	 */
	public function on_customer_registered( int $user_id, string $source ): void {
		self::record_acceptance( $user_id, 'google' === $source ? 'google' : 'cadastro' );
	}

	/**
	 * WooCommerce's own terms checkbox, which it shows at checkout once a terms
	 * page is set and will not let the order through without.
	 *
	 * @param int                 $order_id
	 * @param array<string,mixed> $posted
	 * @param \WC_Order|null      $order
	 */
	public function on_checkout( $order_id, $posted, $order = null ): void {
		if ( empty( $posted['terms'] ) ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		self::record_acceptance( (int) $order->get_customer_id(), 'checkout', $order );
	}

	public function settings_tab_label(): string {
		return __( 'Páginas legais', 'galaxie-woo' );
	}

	/** @return Field[] */
	public function settings_fields(): array {
		$pages = array();
		foreach ( get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title' ) ) as $page ) {
			$pages[ (string) $page->ID ] = $page->post_title . ' (/' . $page->post_name . '/)';
		}

		$wp_privacy = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		$wc_terms   = (int) get_option( 'woocommerce_terms_page_id', 0 );

		return array(
			new Field(
				key: 'terms_page',
				label: __( 'Termos de uso', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'Vira link em "termos de uso" no aceite do cadastro. Também é gravada como a página de termos do WooCommerce, que passa a pedir o aceite no checkout.', 'galaxie-woo' )
					. ( $wc_terms > 0 ? ' ' . sprintf( /* translators: %s: page title */ __( 'Vazio: usa a do WooCommerce (%s).', 'galaxie-woo' ), get_the_title( $wc_terms ) ) : '' ),
				default: '',
				options: array( '' => __( '— Usar a do WooCommerce —', 'galaxie-woo' ) ) + $pages
			),
			new Field(
				key: 'privacy_page',
				label: __( 'Política de privacidade', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'Vira link em "política de privacidade" no aceite do cadastro. Também é gravada como a página de privacidade do WordPress (Configurações → Privacidade).', 'galaxie-woo' )
					. ( $wp_privacy > 0 ? ' ' . sprintf( /* translators: %s: page title */ __( 'Vazio: usa a do WordPress (%s).', 'galaxie-woo' ), get_the_title( $wp_privacy ) ) : '' ),
				default: '',
				options: array( '' => __( '— Usar a do WordPress —', 'galaxie-woo' ) ) + $pages
			),
			new Field(
				key: 'returns_page',
				label: __( 'Trocas e devoluções', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'Vira link em "trocas e devoluções" quando a frase aparece num texto de aceite.', 'galaxie-woo' ),
				default: '',
				options: array( '' => __( '— Nenhuma —', 'galaxie-woo' ) ) + $pages
			),
		);
	}

	public function render_extra_settings( array $values ): void {
		echo '<h3>' . esc_html__( 'Registro do aceite', 'galaxie-woo' ) . '</h3>';
		echo '<p>' . esc_html__( 'A cada aceite (cadastro ou checkout) gravamos na conta do cliente, e no pedido quando é no checkout: a data e hora (UTC), a versão de cada documento (ID da página @ última modificação) e a origem.', 'galaxie-woo' ) . '</p>';
		printf(
			'<p><code>%s</code>, <code>%s</code>, <code>%s</code>, <code>%s</code></p>',
			esc_html( self::META_ACCEPTED_AT ),
			esc_html( self::META_TERMS_VERSION ),
			esc_html( self::META_PRIVACY_VERSION ),
			esc_html( self::META_SOURCE )
		);
	}

	public function sanitize_settings( array $submitted, array $current ): array {
		$values = Field::sanitize_all( $this->settings_fields(), $submitted );

		// Written back so WooCommerce's terms checkbox and WordPress's privacy
		// links point at the same pages as ours.
		$terms = (int) ( $values['terms_page'] ?? 0 );
		if ( $terms > 0 ) {
			update_option( 'woocommerce_terms_page_id', $terms );
		}
		$privacy = (int) ( $values['privacy_page'] ?? 0 );
		if ( $privacy > 0 ) {
			update_option( 'wp_page_for_privacy_policy', $privacy );
		}

		return $values;
	}
}

<?php
/**
 * Keeps other plugins' unused front-end files off the storefront.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\AssetTrim;

use Galaxie\Woo\Core\Module as ModuleContract;

defined( 'ABSPATH' ) || exit;

/**
 * pixfort hides its page-transition overlay on jQuery's DOM-ready, so every
 * script the browser must fetch and run first delays the first paint. Measured
 * on a throttled phone (2026-10-08) the product page reached it at ~8.7 s.
 * Part of that was files nothing on the page uses:
 *
 * - Elementor's Google Fonts for its default kit, Roboto and Roboto Slab with
 *   18 weights each. No visible text on the store renders in either (the brand
 *   fonts come from pixfort), checked with computed styles on every template.
 * - On product pages, Melhor Envio's product-page quote scripts and the
 *   Shipping Simulator's form scripts and styles. Neither form is printed on
 *   the product template; the store quotes shipping in the Galaxie cart.
 *
 * Elementor enqueues its fonts while rendering, after `wp_enqueue_scripts`,
 * so those are filtered at print time (`style_loader_tag`) rather than
 * dequeued. Nothing is touched in wp-admin, in the Elementor editor or its
 * preview, or for a handle not listed here.
 */
final class Module implements ModuleContract {

	/** Styles dropped on every storefront page. */
	private const FONT_STYLES = array( 'elementor-gf-roboto', 'elementor-gf-robotoslab' );

	/** Scripts dropped on product pages. */
	private const PRODUCT_SCRIPTS = array( 'produto', 'produto-variacao', 'calculator', 'wc_shipping_simulator_form', 'wc-shipping-simulator-custom-product-postcode' );

	/** Styles dropped on product pages. */
	private const PRODUCT_STYLES = array( 'wc_shipping_simulator_form', 'wc-shipping-simulator-custom-postcode' );

	public function id(): string {
		return 'asset-trim';
	}

	public function title(): string {
		return __( 'Enxugar arquivos sem uso', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Não carrega as fontes padrão do Elementor (Roboto e Roboto Slab) nem os scripts de cotação do Melhor Envio e do Simulador de Frete na página de produto, que a loja não usa.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'dequeue' ), 100 );
		add_filter( 'style_loader_tag', array( self::class, 'style_tag' ), 10, 2 );
	}

	public static function dequeue(): void {
		if ( ! self::storefront() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		foreach ( self::PRODUCT_SCRIPTS as $handle ) {
			wp_dequeue_script( $handle );
		}
		foreach ( self::PRODUCT_STYLES as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * @param string|mixed $tag
	 * @param string|mixed $handle
	 * @return string|mixed
	 */
	public static function style_tag( $tag, $handle ) {
		if ( ! is_string( $handle ) || ! self::storefront() ) {
			return $tag;
		}
		if ( in_array( $handle, self::FONT_STYLES, true ) ) {
			return '';
		}
		if ( in_array( $handle, self::PRODUCT_STYLES, true ) && function_exists( 'is_product' ) && is_product() ) {
			return '';
		}
		return $tag;
	}

	/** The public front end, not wp-admin and not the Elementor editor or its preview. */
	private static function storefront(): bool {
		if ( is_admin() || wp_doing_ajax() ) {
			return false;
		}
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->preview ) && \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
			return false;
		}
		return true;
	}
}

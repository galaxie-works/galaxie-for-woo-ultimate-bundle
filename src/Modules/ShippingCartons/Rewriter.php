<?php
/**
 * Melhor Envio quote requests, sent with the store's cartons.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ShippingCartons;

use Galaxie\Woo\Support\CartonQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Every quote the Melhor Envio plugin asks for — the cart and checkout, order
 * creation, its order list and the label flow — is a POST to
 * `/shipment/calculate` through `wp_remote_post()`. `http_request_args` is the
 * one place all of them pass, so the body is rewritten there
 * ({@see CartonQuote::rewrite_body()}); nothing else about the request changes.
 *
 * Fails open: whatever goes wrong, the plugin's own request goes out, and the
 * reason is logged. Headers — the plugin's API token among them — are never
 * read or logged.
 */
final class Rewriter {

	/** Why a quote was left as the plugin built it, for the log. */
	private const REASONS = array(
		'shape'       => 'the request body is not the shape this module knows',
		'already'     => 'the request was already packed',
		'too_many'    => 'more units than the packer takes',
		'no_cartons'  => 'no active carton is registered',
		'lines'       => 'a product in the request is not a WooCommerce product',
		'dimensions'  => 'a product has no WooCommerce shipping dimensions or weight',
		'no_fit'      => 'an item fits no registered carton',
		'needs_split' => 'no single carton holds the order and the fallback is "send the original request"',
		'error'       => 'an error while packing',
	);

	public static function hooks(): void {
		add_filter( 'http_request_args', array( self::class, 'filter' ), 20, 2 );
	}

	/**
	 * @param mixed $args Request arguments.
	 * @param mixed $url  Request URL.
	 * @return mixed
	 */
	public static function filter( $args, $url = '' ) {
		if ( ! is_string( $url ) || ! CartonQuote::is_calculate( $url, $args ) ) {
			return $args;
		}

		try {
			$result = CartonQuote::rewrite_body( $args['body'], array( self::class, 'lines_for' ), Module::cartons(), Module::packing_options() );
		} catch ( \Throwable $e ) {
			self::log( 'error', sprintf( 'Kept the Melhor Envio quote as the plugin built it: %s (%s).', self::REASONS['error'], get_class( $e ) ) );
			return $args;
		}

		if ( ! $result['changed'] ) {
			self::log( 'notice', sprintf( 'Kept the Melhor Envio quote as the plugin built it: %s.', self::REASONS[ $result['reason'] ] ?? $result['reason'] ) );
			return $args;
		}

		$args['body'] = $result['body'];

		self::log(
			'info',
			sprintf(
				'Melhor Envio quote packed into %1$d carton(s), gift groups from %2$s: %3$s.',
				count( $result['packed'] ),
				'' !== Context::source() ? Context::source() : 'no cart or order (every line its own item)',
				implode( '; ', CartonQuote::describe( $result['packed'] ) )
			)
		);

		return $args;
	}

	/**
	 * What WooCommerce knows about each request product.
	 *
	 * @param array $products Request products.
	 * @return array<int, array>|null Null when a product is not a WooCommerce product.
	 */
	public static function lines_for( array $products ): ?array {
		$roles = Context::roles_for( $products );
		$lines = array();

		foreach ( $products as $k => $p ) {
			$id      = $p['id'] ?? null;
			$product = is_numeric( $id ) ? wc_get_product( (int) $id ) : null;

			if ( ! $product instanceof \WC_Product ) {
				return null;
			}

			$lines[ $k ] = self::measure( $product ) + ( $roles[ $k ] ?? array(
				'group' => '',
				'role'  => '',
			) );
		}

		return $lines;
	}

	/**
	 * A product's shipping size in cm and weight in grams (0 where missing),
	 * with its name. Variations fall back to their parent's, as WooCommerce does.
	 *
	 * @return array{length:float, width:float, height:float, weight:float, label:string}
	 */
	public static function measure( \WC_Product $product ): array {
		return array(
			'length' => (float) wc_get_dimension( (float) $product->get_length(), 'cm' ),
			'width'  => (float) wc_get_dimension( (float) $product->get_width(), 'cm' ),
			'height' => (float) wc_get_dimension( (float) $product->get_height(), 'cm' ),
			'weight' => (float) wc_get_weight( (float) $product->get_weight(), 'g' ),
			'label'  => wp_strip_all_tags( $product->get_name() ),
		);
	}

	private static function log( string $level, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => Module::LOG_SOURCE ) );
		}
	}
}

<?php
/**
 * Gift box and candle packing sizes over the REST API.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

use Galaxie\Woo\Support\GiftPacking;

defined( 'ABSPATH' ) || exit;

/**
 * The `_galaxie_box_*` ({@see BoxFields}) and `_galaxie_gift_*`
 * ({@see CandleFields}) variation meta, writable without the variation panel:
 *
 * - Registered for `product` and `product_variation` with a REST schema, so
 *   `wp/v2` shows them to editors and WordPress sanitises every write, whatever
 *   sends it, with the fields' own `clean()`.
 * - WooCommerce's `wc/v3/products/{id}/variations/{vid}` already accepts them
 *   in `meta_data` (it filters protected meta only for customers, WooCommerce
 *   10.9); its writes go through `add_metadata()`, which runs that same
 *   sanitiser. What it does not check is the per-post capability, so a request
 *   touching these keys is refused unless the user can `edit_product` that one.
 * - Any change to them clears the cached store sizes, as saving the panel does.
 *
 * Booted by the Gift Wrap module, like the panel fields.
 */
final class ProductMeta {

	private const POST_TYPES = array( 'product', 'product_variation' );

	public static function hooks(): void {
		foreach ( self::schema() as $key => $schema ) {
			foreach ( self::POST_TYPES as $post_type ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'              => $schema['type'],
						'description'       => $schema['description'],
						'single'            => true,
						'show_in_rest'      => array( 'schema' => $schema ),
						'sanitize_callback' => static fn( $value ) => self::clean( $key, $value ),
						'auth_callback'     => array( self::class, 'can_edit' ),
					)
				);
			}
		}

		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'changed' ), 10, 3 );
		}

		foreach ( self::POST_TYPES as $post_type ) {
			add_filter( 'woocommerce_rest_pre_insert_' . $post_type . '_object', array( self::class, 'guard_wc_rest' ), 10, 2 );
		}

		GiftPacking::watch_sizes();
	}

	/** @return string[] Every meta key handled here. */
	public static function keys(): array {
		return array_merge( array_values( BoxFields::META ), array_values( CandleFields::META ) );
	}

	/**
	 * A value as the variation panel would save it.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Raw value.
	 * @return int|string
	 */
	public static function clean( string $key, $value ) {
		$box = array_search( $key, BoxFields::META, true );

		if ( false !== $box ) {
			return BoxFields::clean( (string) $box, $value );
		}

		return CandleFields::clean( $value );
	}

	/**
	 * `auth_callback` for the registered meta: the user may edit that product.
	 *
	 * @param bool   $allowed  Unused.
	 * @param string $meta_key Unused.
	 * @param int    $post_id  Product or variation.
	 * @param int    $user_id  User.
	 */
	public static function can_edit( $allowed, $meta_key, $post_id, $user_id ): bool {
		return (int) $post_id > 0 && user_can( (int) $user_id, 'edit_product', (int) $post_id );
	}

	/**
	 * Clears the store sizes when one of these keys changes, however it changed.
	 *
	 * @param int|int[] $meta_id   Unused.
	 * @param int       $object_id Unused.
	 * @param string    $meta_key  Meta key.
	 */
	public static function changed( $meta_id, $object_id, $meta_key ): void {
		if ( in_array( (string) $meta_key, self::keys(), true ) ) {
			GiftPacking::flush_sizes();
		}
	}

	/**
	 * Refuses a WooCommerce REST write that sets these keys on a product the
	 * user cannot edit. Every other key, and every other request, passes as is.
	 *
	 * @param \WC_Product|\WP_Error $product Object about to be saved.
	 * @param \WP_REST_Request      $request Request.
	 * @return \WC_Product|\WP_Error
	 */
	public static function guard_wc_rest( $product, $request ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $product;
		}

		$meta    = $request['meta_data'] ?? null;
		$touches = false;

		foreach ( is_array( $meta ) ? $meta : array() as $entry ) {
			if ( is_array( $entry ) && in_array( (string) ( $entry['key'] ?? '' ), self::keys(), true ) ) {
				$touches = true;
				break;
			}
		}

		if ( ! $touches ) {
			return $product;
		}

		$id      = (int) $product->get_id();
		$allowed = $id > 0 ? current_user_can( 'edit_product', $id ) : current_user_can( 'edit_products' );

		if ( $allowed ) {
			return $product;
		}

		return new \WP_Error( 'galaxie_meta_forbidden', __( 'Sorry, you are not allowed to change this product\'s gift packing sizes.', 'galaxie-woo' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/** @return array<string,array{type:string,description:string,minimum:int|float,maximum?:float}> */
	private static function schema(): array {
		$number = static fn( string $description, array $extra = array() ): array => array(
			'type'        => 'number',
			'description' => $description,
			'minimum'     => 0,
		) + $extra;

		return array(
			BoxFields::META['length']    => $number( 'Gift box internal (usable) length, cm. Empty or 0: not a box.' ),
			BoxFields::META['width']     => $number( 'Gift box internal (usable) width, cm.' ),
			BoxFields::META['height']    => $number( 'Gift box internal (usable) height, cm.' ),
			BoxFields::META['max']       => array(
				'type'        => 'integer',
				'description' => 'Most candles the gift box takes; 0 for no limit beyond what fits.',
				'minimum'     => 0,
			),
			BoxFields::META['overflow']  => $number( 'Extra height, cm, candles may stand above the base with the lid still closing (0–2).', array( 'maximum' => BoxFields::OVERFLOW_MAX ) ),
			CandleFields::META['length'] => $number( 'Candle jar alone, lid on, length in cm, for gift packing. Optional override: empty uses the size term\'s gift dimensions, then the shipping dimensions.' ),
			CandleFields::META['width']  => $number( 'Candle jar alone, lid on, width in cm, for gift packing.' ),
			CandleFields::META['height'] => $number( 'Candle jar alone, lid on, height in cm, for gift packing.' ),
		);
	}
}

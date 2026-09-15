<?php
/**
 * Just enough WooCommerce for the plain runner to call
 * `GiftPacking::candle_from_product()` and `box_from_product()`.
 *
 * Not WooCommerce: meta, dimensions and prices come back as the strings the
 * stub is given, the way WC_Product returns stored values.
 *
 * @package Galaxie\Woo
 */

if ( ! function_exists( 'absint' ) ) {
	/**
	 * WordPress's absint().
	 *
	 * @param mixed $value Value.
	 */
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	/**
	 * A product built from an array: id, type, attributes, length, width,
	 * height, price, meta.
	 */
	class WC_Product {

		/**
		 * @param array $data Product data.
		 */
		public function __construct( private array $data = array() ) {}

		public function get_id(): int {
			return (int) ( $this->data['id'] ?? 0 );
		}

		/**
		 * @param string $type Product type.
		 */
		public function is_type( $type ): bool {
			return ( $this->data['type'] ?? 'simple' ) === $type;
		}

		public function get_attributes(): array {
			return $this->data['attributes'] ?? array();
		}

		/**
		 * Simple products keep attributes as text; the stub has none.
		 *
		 * @param string $attribute Attribute.
		 */
		public function get_attribute( $attribute ): string {
			return (string) ( $this->data['text_attributes'][ $attribute ] ?? '' );
		}

		/**
		 * @param string $key Meta key.
		 */
		public function get_meta( $key ) {
			return $this->data['meta'][ $key ] ?? '';
		}

		public function get_length() {
			return $this->data['length'] ?? '';
		}

		public function get_width() {
			return $this->data['width'] ?? '';
		}

		public function get_height() {
			return $this->data['height'] ?? '';
		}

		public function get_price() {
			return $this->data['price'] ?? '';
		}
	}
}

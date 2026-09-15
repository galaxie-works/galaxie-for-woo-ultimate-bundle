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

if ( ! function_exists( 'get_term_by' ) ) {
	/**
	 * A term from `$GLOBALS['gx_terms'][ $taxonomy ]` (objects with term_id,
	 * slug, name) whose `$field` matches, or false.
	 *
	 * @param string $field    slug or name.
	 * @param mixed  $value    Value.
	 * @param string $taxonomy Taxonomy.
	 */
	function get_term_by( $field, $value, $taxonomy ) {
		foreach ( $GLOBALS['gx_terms'][ $taxonomy ] ?? array() as $term ) {
			if ( (string) ( $term->$field ?? '' ) === (string) $value ) {
				return $term;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	/**
	 * Term meta from `$GLOBALS['gx_term_meta'][ $term_id ]`, '' when missing.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Unused.
	 */
	function get_term_meta( $term_id, $key, $single = false ) {
		return $GLOBALS['gx_term_meta'][ (int) $term_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * `GiftPacking::flush_sizes()` deletes its transient; there is none here.
	 *
	 * @param string $name Transient.
	 */
	function delete_transient( $name ): bool {
		return true;
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

<?php
/**
 * A customer's saved addresses.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce keeps exactly one billing and one shipping address per customer.
 * The book sits beside those two rather than replacing them: every address the
 * customer keeps lives in user meta, and "default for shipping" simply means
 * the WooCommerce shipping address currently matches it. Nothing therefore has
 * to be kept in step by hand — checkout, emails and the native account screen
 * go on reading the same two addresses they always did, and whichever address
 * is made the default is written into them.
 *
 * Matching is by place (country, postcode, street, complement, city, state),
 * not by recipient: the same door with a different name on the parcel is the
 * same address.
 */
final class AddressBook {

	public const META_KEY = '_galaxie_address_book';

	/** The WooCommerce address keys an entry holds, without the billing_/shipping_ prefix. */
	public const FIELDS = array( 'first_name', 'last_name', 'company', 'country', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone' );

	private const LIMIT = 20;

	/**
	 * The saved addresses, id => entry. On first read the book starts from the
	 * billing and shipping addresses the customer already has, so turning the
	 * module on never presents an established customer with an empty page.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function entries( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $raw ) ) {
			$raw = self::seed( $user_id );
		}

		$entries = array();

		foreach ( $raw as $id => $entry ) {
			if ( is_array( $entry ) && '' !== trim( (string) ( $entry['address_1'] ?? '' ) ) ) {
				$entries[ (string) $id ] = self::clean( $entry );
			}
		}

		return $entries;
	}

	/**
	 * What the account widget and the checkout picker read.
	 *
	 * @return array<int,array{id:string,label:string,formatted:string,values:array<string,string>,shipping:bool,billing:bool}>
	 */
	public static function for_js( int $user_id ): array {
		$shipping = self::fingerprint( self::wc_address( $user_id, 'shipping' ) );
		$billing  = self::fingerprint( self::wc_address( $user_id, 'billing' ) );
		$list     = array();

		foreach ( self::entries( $user_id ) as $id => $entry ) {
			$place  = self::fingerprint( $entry );
			$list[] = array(
				'id'        => $id,
				'label'     => $entry['label'],
				'formatted' => self::format( $entry ),
				'values'    => array_intersect_key( $entry, array_flip( self::FIELDS ) ),
				'shipping'  => $place === $shipping,
				'billing'   => $place === $billing,
			);
		}

		return $list;
	}

	/**
	 * Adds or updates an entry. An address that is already a default stays the
	 * default through the edit, and the very first address becomes the default
	 * for both, since a book with one address and no default helps nobody.
	 *
	 * @param array<string,string> $data Unprefixed address keys plus `label`.
	 * @return string|\WP_Error The entry id.
	 */
	public static function save( int $user_id, string $id, array $data ) {
		$countries = WC()->countries;
		$country   = strtoupper( trim( (string) ( $data['country'] ?? '' ) ) );

		if ( ! array_key_exists( $country, $countries->get_shipping_countries() ) ) {
			return new \WP_Error( 'country', __( 'Escolha um país para onde entregamos.', 'galaxie-woo' ) );
		}

		$data['country'] = $country;

		foreach ( $countries->get_address_fields( $country, 'shipping_' ) as $key => $field ) {
			$name = substr( (string) $key, strlen( 'shipping_' ) );

			if ( ! empty( $field['required'] ) && '' === trim( (string) ( $data[ $name ] ?? '' ) ) ) {
				/* translators: %s: field label, e.g. "CEP". */
				return new \WP_Error( 'required', sprintf( __( 'Preencha o campo %s.', 'galaxie-woo' ), wp_strip_all_tags( (string) ( $field['label'] ?? $name ) ) ) );
			}
		}

		if ( '' !== trim( (string) ( $data['postcode'] ?? '' ) ) ) {
			$data['postcode'] = wc_format_postcode( (string) $data['postcode'], $country );

			if ( ! \WC_Validation::is_postcode( $data['postcode'], $country ) ) {
				return new \WP_Error( 'postcode', __( 'Confira o CEP.', 'galaxie-woo' ) );
			}
		}

		$states = $countries->get_states( $country );

		if ( is_array( $states ) && $states && ! array_key_exists( (string) ( $data['state'] ?? '' ), $states ) ) {
			return new \WP_Error( 'state', __( 'Escolha o estado.', 'galaxie-woo' ) );
		}

		$entries = self::entries( $user_id );
		$entry   = self::clean( $data );

		if ( '' !== $id && ! isset( $entries[ $id ] ) ) {
			return new \WP_Error( 'missing', __( 'Este endereço não existe mais. Recarregue a página.', 'galaxie-woo' ) );
		}

		// Saving an address that is already in the book updates it instead of
		// filing a second copy.
		if ( '' === $id ) {
			foreach ( $entries as $existing_id => $existing ) {
				if ( self::fingerprint( $existing ) === self::fingerprint( $entry ) ) {
					$id = $existing_id;
					break;
				}
			}
		}

		if ( '' === $id && count( $entries ) >= self::LIMIT ) {
			/* translators: %d: how many addresses a customer may keep. */
			return new \WP_Error( 'limit', sprintf( __( 'Você pode guardar até %d endereços.', 'galaxie-woo' ), self::LIMIT ) );
		}

		$defaults = '' !== $id ? self::defaults_of( $user_id, $entries[ $id ] ) : array();

		if ( ! $entries ) {
			$defaults = array( 'billing', 'shipping' );
		}

		if ( '' === $id ) {
			$id = self::new_id( $entries );
		}

		$entries[ $id ] = $entry;
		update_user_meta( $user_id, self::META_KEY, $entries );

		foreach ( $defaults as $type ) {
			self::write_wc( $user_id, $type, $entry );
		}

		return $id;
	}

	/** @return true|\WP_Error */
	public static function delete( int $user_id, string $id ) {
		$entries = self::entries( $user_id );

		if ( ! isset( $entries[ $id ] ) ) {
			return new \WP_Error( 'missing', __( 'Este endereço não existe mais. Recarregue a página.', 'galaxie-woo' ) );
		}

		$defaults = self::defaults_of( $user_id, $entries[ $id ] );

		unset( $entries[ $id ] );
		update_user_meta( $user_id, self::META_KEY, $entries );

		// A default is what checkout fills in, so it cannot just vanish from the
		// book and stay behind in WooCommerce: the next address takes its place,
		// and with none left the WooCommerce address is emptied too.
		foreach ( $defaults as $type ) {
			$next = reset( $entries );

			if ( false !== $next ) {
				self::write_wc( $user_id, $type, $next );
			} else {
				self::clear_wc( $user_id, $type );
			}
		}

		return true;
	}

	/**
	 * Empties where a WooCommerce address points, keeping the name and phone:
	 * those belong to the customer, not to the place.
	 */
	private static function clear_wc( int $user_id, string $type ): void {
		foreach ( array( 'company', 'address_1', 'address_2', 'city', 'state', 'postcode' ) as $field ) {
			update_user_meta( $user_id, $type . '_' . $field, '' );
		}
	}

	/** @return true|\WP_Error */
	public static function set_default( int $user_id, string $id, string $type ) {
		$entries = self::entries( $user_id );

		if ( ! isset( $entries[ $id ] ) ) {
			return new \WP_Error( 'missing', __( 'Este endereço não existe mais. Recarregue a página.', 'galaxie-woo' ) );
		}

		if ( ! in_array( $type, array( 'billing', 'shipping' ), true ) ) {
			return new \WP_Error( 'type', __( 'Algo deu errado. Tente de novo.', 'galaxie-woo' ) );
		}

		self::write_wc( $user_id, $type, $entries[ $id ] );

		return true;
	}

	/**
	 * Files an address the customer used somewhere else — checkout, or the
	 * native edit-address screen — unless the book already has it.
	 *
	 * @param array<string,string> $address Unprefixed address keys.
	 */
	public static function add_if_new( int $user_id, array $address ): void {
		if ( ! $user_id || '' === trim( (string) ( $address['address_1'] ?? '' ) ) ) {
			return;
		}

		$entries = self::entries( $user_id );
		$entry   = self::clean( $address );

		foreach ( $entries as $existing ) {
			if ( self::fingerprint( $existing ) === self::fingerprint( $entry ) ) {
				return;
			}
		}

		if ( count( $entries ) >= self::LIMIT ) {
			return;
		}

		$entries[ self::new_id( $entries ) ] = $entry;
		update_user_meta( $user_id, self::META_KEY, $entries );
	}

	/** @return array<string,string> The customer's WooCommerce billing or shipping address, unprefixed. */
	public static function wc_address( int $user_id, string $type ): array {
		$address = array();

		foreach ( self::FIELDS as $field ) {
			$address[ $field ] = (string) get_user_meta( $user_id, $type . '_' . $field, true );
		}

		return $address;
	}

	/**
	 * WooCommerce's own formatting for the store's country, fed escaped values:
	 * `get_formatted_address()` assembles strings, it does not escape them.
	 *
	 * @param array<string,string> $entry
	 */
	public static function format( array $entry ): string {
		$values = array_map( 'esc_html', array_intersect_key( $entry, array_flip( array_diff( self::FIELDS, array( 'phone' ) ) ) ) );

		return (string) WC()->countries->get_formatted_address( $values );
	}

	/** @return array<string,string> */
	private static function seed( int $user_id ): array {
		$entries = array();

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$address = self::clean( self::wc_address( $user_id, $type ) );

			if ( '' === $address['address_1'] ) {
				continue;
			}

			foreach ( $entries as $existing ) {
				if ( self::fingerprint( $existing ) === self::fingerprint( $address ) ) {
					continue 2;
				}
			}

			$entries[ self::new_id( $entries ) ] = $address;
		}

		update_user_meta( $user_id, self::META_KEY, $entries );

		return $entries;
	}

	/**
	 * @param array<string,string> $entry
	 * @return string[] 'billing' and/or 'shipping'
	 */
	private static function defaults_of( int $user_id, array $entry ): array {
		$place = self::fingerprint( $entry );

		return array_values(
			array_filter(
				array( 'billing', 'shipping' ),
				static fn( string $type ): bool => self::fingerprint( self::wc_address( $user_id, $type ) ) === $place
			)
		);
	}

	/**
	 * A blank phone is not written: the billing phone is how the store reaches
	 * the customer, and an address saved without one must not erase it.
	 *
	 * @param array<string,string> $entry
	 */
	private static function write_wc( int $user_id, string $type, array $entry ): void {
		foreach ( self::FIELDS as $field ) {
			if ( 'phone' === $field && '' === $entry['phone'] ) {
				continue;
			}

			update_user_meta( $user_id, $type . '_' . $field, $entry[ $field ] );
		}
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,string>
	 */
	private static function clean( array $data ): array {
		$entry = array();

		foreach ( self::FIELDS as $field ) {
			$entry[ $field ] = sanitize_text_field( (string) ( $data[ $field ] ?? '' ) );
		}

		$entry['label'] = mb_substr( sanitize_text_field( (string) ( $data['label'] ?? '' ) ), 0, 40 );

		return $entry;
	}

	/** @param array<string,string> $address */
	private static function fingerprint( array $address ): string {
		$parts = array(
			$address['country'] ?? '',
			preg_replace( '/\W+/', '', (string) ( $address['postcode'] ?? '' ) ),
			$address['address_1'] ?? '',
			$address['address_2'] ?? '',
			$address['city'] ?? '',
			$address['state'] ?? '',
		);

		return implode( '|', array_map( static fn( $part ): string => (string) preg_replace( '/\s+/', ' ', mb_strtolower( trim( (string) $part ) ) ), $parts ) );
	}

	/** @param array<string,mixed> $entries */
	private static function new_id( array $entries ): string {
		do {
			$id = strtolower( wp_generate_password( 10, false ) );
		} while ( isset( $entries[ $id ] ) );

		return $id;
	}
}

<?php
/**
 * Reads a customer's profile completeness and saved address.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Shared between Checkout (decides whether to skip the profile/address steps)
 * and My Account (shows the same data in the edit forms) — one implementation
 * so "is this profile complete" can't drift between the two.
 */
final class CustomerProfile {

	private const ADDRESS_FIELDS = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	/** @return array{complete:bool,missing:string[],values:array<string,string>} */
	public static function status( int $user_id ): array {
		$user = get_userdata( $user_id );

		$values = array(
			'first_name' => $user ? $user->first_name : '',
			'last_name'  => $user ? $user->last_name : '',
			'phone'      => get_user_meta( $user_id, 'billing_phone', true ),
			'cpf'        => get_user_meta( $user_id, ProfileFields::CPF, true ),
			'birthdate'  => get_user_meta( $user_id, ProfileFields::BIRTHDATE, true ),
		);

		$missing = array();
		foreach ( $values as $key => $value ) {
			if ( '' === $value ) {
				$missing[] = $key;
			}
		}

		return array(
			'complete' => empty( $missing ),
			'missing'  => $missing,
			'values'   => $values,
		);
	}

	/** @return array{has_address:bool,address_1:string,address_2:string,city:string,state:string,postcode:string,country:string} */
	public static function saved_address( int $user_id ): array {
		$address = array();
		foreach ( self::ADDRESS_FIELDS as $field ) {
			$address[ $field ] = get_user_meta( $user_id, 'billing_' . $field, true );
		}
		$address['has_address'] = '' !== $address['address_1'] && '' !== $address['postcode'];

		return $address;
	}

	/**
	 * Writes billing_* (and, when requested, mirrored shipping_*) address meta.
	 *
	 * @param array<string,string> $address
	 */
	public static function save_address( int $user_id, array $address, bool $mirror_to_shipping = true ): void {
		foreach ( self::ADDRESS_FIELDS as $field ) {
			if ( ! isset( $address[ $field ] ) ) {
				continue;
			}
			update_user_meta( $user_id, 'billing_' . $field, $address[ $field ] );
			if ( $mirror_to_shipping ) {
				update_user_meta( $user_id, 'shipping_' . $field, $address[ $field ] );
			}
		}
	}
}

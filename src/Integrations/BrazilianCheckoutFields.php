<?php
/**
 * The Brazilian checkout fields (CPF/CNPJ, person type) fed from the profile CPF.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Integrations;

use Galaxie\Woo\Support\Cpf;
use Galaxie\Woo\Support\ProfileFields;

defined( 'ABSPATH' ) || exit;

/**
 * The bundle keeps the customer's CPF in one place, the `eir_cpf` user meta
 * (ProfileFields::CPF). The store's Brazilian checkout plugin — "Campos
 * Checkout Brasileiro para WooCommerce" by Link Nacional, 5.0.4, folder
 * woo-better-shipping-calculator-for-brazil — never reads it: with its person
 * type option on (`woo_better_calc_person_type_select` != 'none') it adds to
 * the classic checkout a visible, REQUIRED `billing_document` (CPF/CNPJ in one
 * box) and three hidden companions, `billing_persontype` ('1' individual,
 * '2' company), `billing_cpf` and `billing_cnpj`, and prefills them from the
 * user meta of the same names. The keys and the 1/2 coding are the ones
 * "Brazilian Market on WooCommerce" uses too, so this also serves that plugin.
 *
 * Nothing filled them, and the checkout widget keeps WooCommerce's form
 * hidden, so the shopper could not either — three ways to lose the order:
 *  - its `woocommerce_checkout_process` check reads `$_POST` directly and adds
 *    "Por favor, insira seu CPF." when all three are empty;
 *  - WooCommerce rejects the empty required `billing_document`;
 *  - and before any of that, its own script cancels the click on
 *    `#place_order` (preventDefault + stopPropagation) when `billing_document`
 *    does not hold 11 or 14 characters, marking a field nobody can see: the
 *    button simply does nothing.
 * The island now mirrors the CPF into those fields (native-checkout.ts,
 * `fillNativeBilling`); this class is the server side of the same promise —
 * the user meta the plugin prefills from, the default values WooCommerce
 * prints, and the posted request itself — so an order never depends on the
 * script having run.
 *
 * Only ever fills what is empty: a CNPJ (or any document) already there wins,
 * since the plugin's own account fields can hold a company's.
 *
 * The same holds for the house number and the bairro. With its options
 * `woo_better_calc_number_required` / `woo_better_calc_enable_neighborhood_field`
 * on, the plugin adds REQUIRED `billing_number` / `shipping_number` and
 * `billing_neighborhood` / `shipping_neighborhood` to the hidden form, and
 * Melhor Envio reads the order meta they become (`_shipping_number`,
 * `_shipping_neighborhood`) for the label, beside `address_1` as the street.
 * The delivery step now has fields for both and the island mirrors them in;
 * with the plugin's options off this class registers the four fields itself
 * (optional), so the order carries the same meta either way, and prints the
 * number and bairro in formatted addresses where the plugin would have.
 */
final class BrazilianCheckoutFields {

	/** The address parts the Brazilian plugins keep beside WooCommerce's, with Link Nacional's form priorities. */
	private const ADDRESS_PARTS = array(
		'number'       => 55,
		'neighborhood' => 69,
	);

	/** `billing_persontype` value for an individual (CPF); '2' is a company (CNPJ). */
	public const INDIVIDUAL = '1';

	/** The fields the CPF answers, in the order the plugin's own script writes them. */
	public const KEYS = array( 'billing_persontype', 'billing_cpf', 'billing_cnpj', 'billing_document' );

	public static function hooks(): void {
		// Every writer of the profile CPF (checkout profile step, My Account,
		// sign-up) goes through update_user_meta, so mirroring on the meta
		// hooks covers them all without each one having to remember.
		add_action( 'added_user_meta', array( self::class, 'mirror_profile_cpf' ), 10, 4 );
		add_action( 'updated_user_meta', array( self::class, 'mirror_profile_cpf' ), 10, 4 );

		// Priority 1: ahead of the plugin's own document check (10), which
		// reads $_POST, not the posted-data array — so $_POST is what to fill.
		add_action( 'woocommerce_checkout_process', array( self::class, 'fill_request' ), 1 );
		add_filter( 'woocommerce_checkout_posted_data', array( self::class, 'fill_posted_data' ) );

		// What WooCommerce prints into the (hidden) fields for customers whose
		// CPF predates the mirroring above: WC_Checkout::get_value() ends in
		// `default_checkout_{$input}`, with null when it found nothing.
		foreach ( array( 'billing_document', 'billing_cpf', 'billing_persontype' ) as $key ) {
			add_filter( 'default_checkout_' . $key, array( self::class, 'default_value' ), 10, 2 );
		}

		// After Link Nacional (100 and 999): only what it did not add.
		add_filter( 'woocommerce_checkout_fields', array( self::class, 'address_fields' ), 1000 );
		add_filter( 'woocommerce_checkout_posted_data', array( self::class, 'fill_address_parts' ), 20 );
		add_filter( 'woocommerce_order_formatted_billing_address', array( self::class, 'formatted_order_address' ), 20, 2 );
		add_filter( 'woocommerce_order_formatted_shipping_address', array( self::class, 'formatted_order_address' ), 20, 2 );
	}

	/** Does Link Nacional add (and print) this part itself? */
	public static function plugin_handles( string $part ): bool {
		$option = 'number' === $part ? 'woo_better_calc_number_required' : 'woo_better_calc_enable_neighborhood_field';

		return 'yes' === get_option( $option, 'no' );
	}

	/**
	 * Number and bairro on the checkout form when no plugin put them there:
	 * optional for WooCommerce (the delivery step asks for them), and saved by
	 * WooCommerce itself as `_billing_number` & co. order meta and customer meta.
	 *
	 * @param array<string,array<string,mixed>> $fields
	 * @return array<string,array<string,mixed>>
	 */
	public static function address_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( ! isset( $fields[ $type ] ) || ! is_array( $fields[ $type ] ) ) {
				continue;
			}

			foreach ( self::ADDRESS_PARTS as $part => $priority ) {
				$key = $type . '_' . $part;

				if ( isset( $fields[ $type ][ $key ] ) ) {
					continue;
				}

				$fields[ $type ][ $key ] = array(
					'label'    => 'number' === $part ? __( 'Número', 'galaxie-woo' ) : __( 'Bairro', 'galaxie-woo' ),
					'required' => false,
					'class'    => array( 'form-row-wide' ),
					'priority' => $priority,
				);
			}
		}

		return $fields;
	}

	/**
	 * Number and bairro for an order posted without them (the island not having
	 * run), from the customer's saved address — but only when the posted
	 * address IS that saved address (same street and CEP): another address's
	 * number on this one would put the parcel at the wrong door.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function fill_address_parts( $data ) {
		$user_id = get_current_user_id();

		if ( ! is_array( $data ) || $user_id <= 0 ) {
			return $data;
		}

		$saved = array(
			'address_1' => (string) get_user_meta( $user_id, 'billing_address_1', true ),
			'postcode'  => (string) get_user_meta( $user_id, 'billing_postcode', true ),
		);

		foreach ( self::ADDRESS_PARTS as $part => $unused ) {
			$saved[ $part ] = (string) get_user_meta( $user_id, 'billing_' . $part, true );
		}

		return self::fill_parts( $data, $saved );
	}

	/**
	 * Pure, for the tests: `$data` with empty number/bairro keys filled from
	 * `$saved` when the posted billing street and CEP are the saved ones.
	 *
	 * @param array<string,mixed>  $data
	 * @param array<string,string> $saved address_1, postcode, number, neighborhood.
	 * @return array<string,mixed>
	 */
	public static function fill_parts( array $data, array $saved ): array {
		$same = static fn( $a, $b ): bool => '' !== trim( (string) $b )
			&& mb_strtolower( trim( (string) $a ) ) === mb_strtolower( trim( (string) $b ) );
		$cep  = static fn( $value ): string => (string) preg_replace( '/\D+/', '', (string) $value );

		if ( ! $same( $data['billing_address_1'] ?? '', $saved['address_1'] ?? '' )
			|| '' === $cep( $saved['postcode'] ?? '' )
			|| $cep( $data['billing_postcode'] ?? '' ) !== $cep( $saved['postcode'] ?? '' ) ) {
			return $data;
		}

		foreach ( self::ADDRESS_PARTS as $part => $unused ) {
			$value = (string) ( $saved[ $part ] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			foreach ( array( 'billing', 'shipping' ) as $type ) {
				$key = $type . '_' . $part;

				if ( array_key_exists( $key, $data ) && '' === trim( (string) $data[ $key ] ) ) {
					// Shipping only when it is the billing address (no
					// separate street posted, or the same one).
					if ( 'shipping' === $type && '' !== trim( (string) ( $data['shipping_address_1'] ?? '' ) ) && ! $same( $data['shipping_address_1'], $saved['address_1'] ) ) {
						continue;
					}
					$data[ $key ] = $value;
				}
			}
		}

		return $data;
	}

	/**
	 * Number and bairro in the order's printed address (e-mails, My Account,
	 * admin) when Link Nacional, whose format has places for them, is not the
	 * one printing them: "Rua das Flores, 123" and "Apto 4 - Centro".
	 *
	 * @param array<string,string>|mixed $address
	 * @param \WC_Order|mixed            $order
	 * @return array<string,string>|mixed
	 */
	public static function formatted_order_address( $address, $order ) {
		if ( ! is_array( $address ) || ! $order instanceof \WC_Order ) {
			return $address;
		}

		$type = 'woocommerce_order_formatted_shipping_address' === current_filter() ? 'shipping' : 'billing';

		return self::with_parts(
			$address,
			self::plugin_handles( 'number' ) ? '' : (string) $order->get_meta( '_' . $type . '_number' ),
			self::plugin_handles( 'neighborhood' ) ? '' : (string) $order->get_meta( '_' . $type . '_neighborhood' )
		);
	}

	/**
	 * Pure: the number appended to the street, the bairro to the complement.
	 * Nothing added twice: a street already ending in that number keeps it.
	 *
	 * @param array<string,string> $address
	 * @return array<string,string>
	 */
	public static function with_parts( array $address, string $number, string $neighborhood ): array {
		$street = (string) ( $address['address_1'] ?? '' );
		$number = trim( $number );

		if ( '' !== $number && '' !== $street && ! preg_match( '/,\s*' . preg_quote( $number, '/' ) . '$/u', $street ) ) {
			$address['address_1'] = $street . ', ' . $number;
		}

		$neighborhood = trim( $neighborhood );

		if ( '' !== $neighborhood ) {
			$address['address_2'] = implode( ' - ', array_filter( array( (string) ( $address['address_2'] ?? '' ), $neighborhood ) ) );
		}

		return $address;
	}

	/**
	 * Copies a saved profile CPF into the plugin's billing meta.
	 *
	 * Writes keys other than the one it listens for, so it cannot re-enter
	 * itself; the FluentCRM watcher's list has none of them either.
	 *
	 * @param int    $meta_id
	 * @param int    $user_id
	 * @param string $meta_key
	 * @param mixed  $meta_value
	 */
	public static function mirror_profile_cpf( $meta_id, $user_id, $meta_key, $meta_value ): void {
		if ( ProfileFields::CPF !== $meta_key || ! is_scalar( $meta_value ) ) {
			return;
		}

		$user_id = (int) $user_id;
		$cpf     = Cpf::is_valid( (string) $meta_value ) ? Cpf::format( (string) $meta_value ) : '';
		$company = '2' === (string) get_user_meta( $user_id, 'billing_persontype', true );

		update_user_meta( $user_id, 'billing_cpf', $cpf );

		// A customer billing as a company keeps their CNPJ as the document.
		if ( $company ) {
			return;
		}

		update_user_meta( $user_id, 'billing_document', $cpf );
		update_user_meta( $user_id, 'billing_persontype', '' === $cpf ? '' : self::INDIVIDUAL );
	}

	/** Fills the posted request before the plugin validates it. WooCommerce has checked the nonce by now. */
	public static function fill_request(): void {
		$cpf = self::customer_cpf( get_current_user_id() );

		if ( '' === $cpf ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in WC_Checkout::process_checkout().
		$_POST = self::fill_document( $_POST, $cpf, false );
	}

	/**
	 * The same for the array WooCommerce validates and saves, which the
	 * plugin's order-meta writer (`_billing_cpf`, `_billing_persontype`) reads.
	 * Only keys already there: the array holds the registered fields, and one
	 * the store does not have is not ours to invent onto the order.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function fill_posted_data( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$cpf = self::customer_cpf( get_current_user_id() );

		return '' === $cpf ? $data : self::fill_document( $data, $cpf, true );
	}

	/**
	 * @param mixed  $value What WooCommerce found (null for nothing).
	 * @param string $input The field key.
	 * @return mixed
	 */
	public static function default_value( $value, $input ) {
		if ( null !== $value && '' !== $value ) {
			return $value;
		}

		$user_id = get_current_user_id();
		$cpf     = self::customer_cpf( $user_id );

		if ( '' === $cpf || '' !== (string) get_user_meta( $user_id, 'billing_cnpj', true ) ) {
			return $value;
		}

		return 'billing_persontype' === $input ? self::INDIVIDUAL : $cpf;
	}

	/**
	 * The fields with the CPF filled in, when the request carries no document
	 * of any kind and is for Brazil (or names no country). Pure, for the tests.
	 *
	 * @param array<string,mixed> $data
	 * @param bool                $only_present Fill only keys $data already has.
	 * @return array<string,mixed>
	 */
	public static function fill_document( array $data, string $cpf, bool $only_present ): array {
		$country = isset( $data['billing_country'] ) && is_scalar( $data['billing_country'] ) ? strtoupper( trim( (string) $data['billing_country'] ) ) : '';

		if ( '' === $cpf || ( '' !== $country && 'BR' !== $country ) ) {
			return $data;
		}

		foreach ( array( 'billing_document', 'billing_cpf', 'billing_cnpj' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ) {
				return $data;
			}
		}

		$fill = array(
			'billing_persontype' => self::INDIVIDUAL,
			'billing_cpf'        => $cpf,
			'billing_document'   => $cpf,
		);

		foreach ( $fill as $key => $value ) {
			if ( ! $only_present || array_key_exists( $key, $data ) ) {
				$data[ $key ] = $value;
			}
		}

		return $data;
	}

	/**
	 * The signed-in customer's valid CPF, formatted: the profile's, else the
	 * one the Brazilian plugin saved. '' for a guest or for none.
	 */
	public static function customer_cpf( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		foreach ( array( ProfileFields::CPF, 'billing_cpf' ) as $key ) {
			$cpf = (string) get_user_meta( $user_id, $key, true );

			if ( Cpf::is_valid( $cpf ) ) {
				return Cpf::format( $cpf );
			}
		}

		return '';
	}
}

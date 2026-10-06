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
 */
final class BrazilianCheckoutFields {

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

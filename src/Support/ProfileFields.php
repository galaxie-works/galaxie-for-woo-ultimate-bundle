<?php
/**
 * Shared extra-profile-field schema (user-meta keys + options).
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for the custom customer fields the bundle stores.
 * The meta keys deliberately match eir-my-account-ux's (`eir_*`) so that when
 * this bundle supersedes v1 on the same site, existing customer data carries
 * over with no migration. Consumed by the auth, checkout and My Account modules.
 */
final class ProfileFields {

	public const CPF                   = 'eir_cpf';
	public const BIRTHDATE             = 'eir_birthdate';
	public const GENDER                = 'eir_gender';
	public const SOCIAL_NAME           = 'eir_social_name';
	public const MARKETING_OPT_IN      = 'eir_marketing_opt_in';
	public const SIGNUP_SOURCE         = 'eir_signup_source';
	public const GOOGLE_AVATAR         = 'eir_google_avatar';
	public const DELETION_REQUESTED_AT = 'eir_account_deletion_requested_at';

	/**
	 * Gender value => label. Values match v1 for data continuity.
	 *
	 * @return array<string,string>
	 */
	public static function gender_options(): array {
		return array(
			''                  => __( 'Prefiro não informar', 'galaxie-woo' ),
			'male'              => __( 'Masculino', 'galaxie-woo' ),
			'female'            => __( 'Feminino', 'galaxie-woo' ),
			'non_binary'        => __( 'Não-binário', 'galaxie-woo' ),
			'prefer_not_to_say' => __( 'Prefiro não dizer', 'galaxie-woo' ),
		);
	}

	/** Human label for a stored gender value (falls back to the raw value). */
	public static function gender_label( string $value ): string {
		$options = self::gender_options();
		return $options[ $value ] ?? $value;
	}
}

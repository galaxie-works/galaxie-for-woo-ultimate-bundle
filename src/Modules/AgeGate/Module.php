<?php
/**
 * Minimum age: the store sells to adults only.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\AgeGate;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Support\ProfileFields;

defined( 'ABSPATH' ) || exit;

/**
 * The terms of use say the store is for people aged 18 or over (a contract
 * with a minor can be annulled), and this is what holds the site to it.
 *
 * While on (wp-admin → Galaxie → Idade mínima), the date of birth is required
 * and checked on the server wherever it is written — the sign-up form
 * (PasswordlessAuth), the checkout's profile step and My Account — through
 * {@see check()}: under the minimum age, a date in the future or one more than
 * 120 years back is refused, and nothing is created. An account that has no
 * date yet (older accounts) cannot place an order until it gives one: the
 * checkout's profile step asks for it, and the order itself is refused
 * server side as the last word.
 *
 * Off, the date stays optional and only its format is checked.
 */
final class Module implements ModuleContract, ProvidesSettings, ProvidesBootData {

	private const MAX_AGE = 120;

	public function id(): string {
		return 'age-gate';
	}

	public function title(): string {
		return __( 'Idade mínima', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Data de nascimento obrigatória e conferida no servidor: menores da idade mínima não criam conta nem fecham pedido.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'check_order' ), 10, 2 );
	}

	public function boot_data(): array {
		return array(
			'ageGate' => array(
				'minAge'  => self::min_age(),
				// For a date input's `max`: the latest birth date still old enough.
				'maxDate' => self::latest_birthdate(),
			),
		);
	}

	/** Whether the gate is on. */
	public static function enabled(): bool {
		return Plugin::instance()->modules()->is_enabled_by_id( 'age-gate' );
	}

	/** The minimum age while the gate is on; 0 while it is off. */
	public static function min_age(): int {
		if ( ! self::enabled() ) {
			return 0;
		}
		$age = (int) ( Plugin::instance()->settings()->module_settings( 'age-gate' )['min_age'] ?? 18 );

		return $age > 0 ? $age : 18;
	}

	/** The latest date of birth that meets the minimum age, Y-m-d in the site's time zone. */
	public static function latest_birthdate(): string {
		return self::today()->modify( '-' . self::min_age() . ' years' )->format( 'Y-m-d' );
	}

	/**
	 * What is wrong with a date of birth, or null when it may be saved.
	 *
	 * Required while the gate is on; a blank date is fine while it is off.
	 */
	public static function check( string $date ): ?string {
		$date = trim( $date );
		$min  = self::min_age();

		if ( '' === $date ) {
			return $min > 0 ? __( 'Informe sua data de nascimento.', 'galaxie-woo' ) : null;
		}

		$invalid = __( 'Informe uma data de nascimento válida.', 'galaxie-woo' );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return $invalid;
		}

		$born  = new \DateTimeImmutable( $date, self::timezone() );
		$today = self::today();

		if ( $born > $today || $born < $today->modify( '-' . self::MAX_AGE . ' years' ) ) {
			return $invalid;
		}

		if ( $min > 0 && $born->diff( $today )->y < $min ) {
			return sprintf(
				/* translators: %d: minimum age */
				__( 'Sentimos muito, mas nossa loja é só para maiores de %d anos. Esperamos você quando chegar a hora.', 'galaxie-woo' ),
				$min
			);
		}

		return null;
	}

	/**
	 * The last word on an order: a signed-in shopper whose saved date of birth
	 * is missing or under the minimum age cannot place it. The checkout's
	 * profile step asks for the date before payment; this catches anything
	 * that reaches WooCommerce without it.
	 *
	 * @param array<string,mixed> $data
	 * @param \WP_Error           $errors
	 */
	public function check_order( $data, $errors ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! $errors instanceof \WP_Error ) {
			return;
		}

		$problem = self::check( (string) get_user_meta( $user_id, ProfileFields::BIRTHDATE, true ) );
		if ( null !== $problem ) {
			$errors->add( 'galaxie_age_gate', $problem );
		}
	}

	private static function timezone(): \DateTimeZone {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
	}

	private static function today(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'today', self::timezone() );
	}

	public function settings_tab_label(): string {
		return __( 'Idade mínima', 'galaxie-woo' );
	}

	/** @return Field[] */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'min_age',
				label: __( 'Idade mínima', 'galaxie-woo' ),
				type: Field::TYPE_NUMBER,
				description: __( 'Em anos. Abaixo dela o cadastro é recusado, a data não pode ser salva em Minha conta e o pedido não fecha. Para desligar a verificação, desligue o módulo na aba Módulos.', 'galaxie-woo' ),
				default: 18,
				min: '1',
				max: '120'
			),
		);
	}

	public function render_extra_settings( array $values ): void {}

	public function sanitize_settings( array $submitted, array $current ): array {
		return Field::sanitize_all( $this->settings_fields(), $submitted );
	}
}

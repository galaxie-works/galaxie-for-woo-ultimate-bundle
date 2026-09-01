<?php
/**
 * Module enable/disable state.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the single `galaxie_woo_modules` option: a map of
 * module id => bool. A missing key means "never set" and falls back to the
 * module's own {@see Module::default_enabled()}.
 */
final class Settings {

	private const MODULES_OPTION  = 'galaxie_woo_modules';
	private const SETTINGS_OPTION = 'galaxie_woo_settings';

	/** @return array<string,bool> */
	public function enabled_map(): array {
		return (array) get_option( self::MODULES_OPTION, array() );
	}

	public function is_enabled( string $id, bool $default ): bool {
		$map = $this->enabled_map();
		return array_key_exists( $id, $map ) ? (bool) $map[ $id ] : $default;
	}

	/** @param array<string,bool> $map */
	public function set_enabled_map( array $map ): void {
		update_option( self::MODULES_OPTION, $map );
	}

	/**
	 * A module's saved settings values, keyed by field key. Empty array if the
	 * module has never been configured.
	 *
	 * @return array<string,mixed>
	 */
	public function module_settings( string $module_id ): array {
		$all = (array) get_option( self::SETTINGS_OPTION, array() );
		return (array) ( $all[ $module_id ] ?? array() );
	}

	/** @param array<string,mixed> $values */
	public function set_module_settings( string $module_id, array $values ): void {
		$all               = (array) get_option( self::SETTINGS_OPTION, array() );
		$all[ $module_id ] = $values;
		update_option( self::SETTINGS_OPTION, $all );
	}
}

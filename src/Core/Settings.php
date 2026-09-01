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

	private const OPTION = 'galaxie_woo_modules';

	/** @return array<string,bool> */
	public function enabled_map(): array {
		return (array) get_option( self::OPTION, array() );
	}

	public function is_enabled( string $id, bool $default ): bool {
		$map = $this->enabled_map();
		return array_key_exists( $id, $map ) ? (bool) $map[ $id ] : $default;
	}

	/** @param array<string,bool> $map */
	public function set_enabled_map( array $map ): void {
		update_option( self::OPTION, $map );
	}
}

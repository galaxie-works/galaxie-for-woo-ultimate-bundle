<?php
/**
 * Registry of all modules + resolution of which are enabled.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

defined( 'ABSPATH' ) || exit;

final class ModuleRegistry {

	/** @var array<string,Module> */
	private array $modules = array();

	public function __construct( private Settings $settings ) {}

	public function register( Module $module ): void {
		$this->modules[ $module->id() ] = $module;
	}

	/** @return array<string,Module> */
	public function all(): array {
		return $this->modules;
	}

	public function is_enabled( Module $module ): bool {
		return $this->settings->is_enabled( $module->id(), $module->default_enabled() );
	}

	/** Convenience for callers that only have a module id (e.g. a widget checking a sibling module). */
	public function is_enabled_by_id( string $id ): bool {
		$module = $this->modules[ $id ] ?? null;
		return null !== $module && $this->is_enabled( $module );
	}

	/** @return array<string,Module> */
	public function enabled(): array {
		return array_filter( $this->modules, array( $this, 'is_enabled' ) );
	}

	/** Boot every enabled module exactly once. */
	public function boot_enabled(): void {
		foreach ( $this->enabled() as $module ) {
			$module->boot();
		}
	}
}

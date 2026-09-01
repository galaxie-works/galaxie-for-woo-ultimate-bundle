<?php
/**
 * Interface for modules that need front-end boot config.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A {@see Module} that needs a flag/value available to the JS bundle before it
 * runs (e.g. "toast-notices is enabled") implements this. {@see Plugin} merges
 * the boot data from every enabled module and prints it once as
 * `window.__GALAXIE_WOO__` in the head, ahead of the deferred module bundle.
 */
interface ProvidesBootData {

	/**
	 * Key/value pairs merged into `window.__GALAXIE_WOO__`.
	 *
	 * @return array<string,mixed>
	 */
	public function boot_data(): array;
}

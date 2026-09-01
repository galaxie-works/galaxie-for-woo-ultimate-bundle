<?php
/**
 * Module contract.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Every toggleable capability in the bundle implements this interface and is
 * registered in {@see Plugin::register_modules()}. Enabled modules have their
 * {@see Module::boot()} called once, on `plugins_loaded`, after WooCommerce is
 * confirmed active.
 */
interface Module {

	/** Stable, unique, kebab-case id (also the key in the `galaxie_woo_modules` option). */
	public function id(): string;

	/** Human label shown on the admin Modules page. */
	public function title(): string;

	/** One-line description shown under the label. */
	public function description(): string;

	/** Whether the module is on when the user has never touched its toggle. */
	public function default_enabled(): bool;

	/** Register hooks / do the work. Called only when the module is enabled. */
	public function boot(): void;
}

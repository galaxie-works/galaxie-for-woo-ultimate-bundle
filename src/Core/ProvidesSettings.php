<?php
/**
 * Interface for modules with their own settings tab.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A {@see Module} that has configuration (API credentials, mappings, toggles)
 * implements this to get its own tab on the Galaxie settings page — but only
 * while the module is enabled (see {@see \Galaxie\Woo\Core\Admin\SettingsPage}).
 *
 * The common case is fully declarative via {@see settings_fields()}. Modules
 * that need more than a plain field list (FluentCRM's tag/list dropdowns,
 * discovered live from the FluentCRM API) use {@see render_extra_settings()}
 * as an escape hatch and read their own `$_POST` keys in
 * {@see sanitize_settings()}.
 */
interface ProvidesSettings {

	/** Label shown on the tab. */
	public function settings_tab_label(): string;

	/**
	 * Declarative fields, rendered as a standard settings table. Return an
	 * empty array if the module's tab is entirely custom-rendered.
	 *
	 * @return Field[]
	 */
	public function settings_fields(): array;

	/**
	 * Escape hatch: render additional markup on the tab, after the declarative
	 * fields table. Receives the module's current saved values. No-op if unused.
	 *
	 * @param array<string,mixed> $values
	 */
	public function render_extra_settings( array $values ): void;

	/**
	 * Produce the final value array to persist for this module. Start from
	 * {@see Field::sanitize_all()} against {@see settings_fields()} and merge in
	 * anything read from `$_POST` for fields rendered by
	 * {@see render_extra_settings()}.
	 *
	 * @param array<string,mixed> $submitted Raw `$_POST` data (already `wp_unslash`ed).
	 * @param array<string,mixed> $current   Currently saved values, for fields that keep their old value when absent from POST (e.g. a password left blank to mean "unchanged").
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $submitted, array $current ): array;
}

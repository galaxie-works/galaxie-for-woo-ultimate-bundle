<?php
/**
 * Interface for settings the declarative fields cannot describe.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A {@see ProvidesSettings} module whose tab also saves rows its
 * {@see ProvidesSettings::render_extra_settings()} draws (a carton table, a
 * tier repeater) implements this so the REST API and WP-CLI can write them too
 * ({@see SettingsService::update_settings()}).
 *
 * Without it only the {@see Field} keys are reachable from outside wp-admin,
 * and a write that would change anything else the tab saves is refused rather
 * than wiping it.
 */
interface ProvidesRestSettings {

	/**
	 * JSON schema per extra key, stored under that same key. Each is
	 * `type: array` of `type: object` rows; `items.properties` documents a row.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function rest_settings_schema(): array;

	/**
	 * The values as the settings form would post them, so
	 * {@see ProvidesSettings::sanitize_settings()} reads the rows exactly as it
	 * reads a wp-admin save. Field keys pass through; the schema keys arrive as
	 * the rows saved or sent.
	 *
	 * @param array<string,mixed> $values
	 * @return array<string,mixed>
	 */
	public function rest_settings_submitted( array $values ): array;
}

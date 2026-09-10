<?php
/**
 * ACF field groups as code.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Points ACF at the bundle's own `acf-json/` directory so the field groups it
 * ships load on every environment the plugin is deployed to.
 *
 * The reason this exists: the product field groups lived only in the
 * production database. Nothing in the codebase declared them, nothing exported
 * them, and the staging site had none — a field group is only as durable as
 * the last person who remembered to import it. Reading them from the plugin
 * makes the definition versioned, reviewable in a diff, and identical
 * everywhere the plugin is installed.
 */
final class Acf {

	/**
	 * Only `load_json` is registered, never `save_json`.
	 *
	 * Pointing `save_json` here would write a merchant's edits from the WP admin
	 * into the plugin folder on the server — where the next deploy overwrites
	 * the directory wholesale and the edit disappears with no error. The repo is
	 * the source of truth in one direction only: edit the JSON here, deploy, and
	 * ACF syncs it in. To change a group through the ACF UI instead, edit it,
	 * export it from ACF → Tools, and commit the file.
	 */
	public static function hooks(): void {
		add_filter( 'acf/settings/load_json', array( self::class, 'load_paths' ) );
	}

	/**
	 * @param array<int,string> $paths
	 * @return array<int,string>
	 */
	public static function load_paths( $paths ): array {
		$paths   = is_array( $paths ) ? $paths : array();
		$paths[] = untrailingslashit( GALAXIE_WOO_DIR ) . '/acf-json';

		return $paths;
	}
}

<?php
/**
 * `wp galaxie module`.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core\Cli;

use Galaxie\Woo\Core\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, enables and disables Galaxie modules, through the same
 * {@see SettingsService} the settings page saves with.
 */
final class ModuleCommand {

	public function __construct( private SettingsService $service ) {}

	/**
	 * Lists every module and whether it is on.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$rows   = $this->service->module_rows();

		if ( 'table' === $format ) {
			foreach ( $rows as &$row ) {
				$row['enabled']      = $row['enabled'] ? 'yes' : 'no';
				$row['configurable'] = $row['configurable'] ? 'yes' : 'no';
			}
			unset( $row );
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'label', 'enabled', 'configurable' ) );
	}

	/**
	 * Turns a module on.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Module id, e.g. shipping-cartons.
	 *
	 * @param array $args Module id.
	 */
	public function enable( $args ): void {
		$this->set( (string) $args[0], true );
	}

	/**
	 * Turns a module off.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Module id, e.g. shipping-cartons.
	 *
	 * @param array $args Module id.
	 */
	public function disable( $args ): void {
		$this->set( (string) $args[0], false );
	}

	private function set( string $id, bool $enabled ): void {
		$state = $this->service->set_enabled( $id, $enabled );

		if ( $state instanceof \WP_Error ) {
			\WP_CLI::error( $state->get_error_message() );
		}

		\WP_CLI::success( sprintf( '%s %s.', $id, $state ? 'enabled' : 'disabled' ) );
	}
}

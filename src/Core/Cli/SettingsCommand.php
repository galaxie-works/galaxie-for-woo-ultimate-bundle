<?php
/**
 * `wp galaxie settings`.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core\Cli;

use Galaxie\Woo\Core\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and changes one module's settings, through the same
 * {@see SettingsService::update_settings()} the REST API uses.
 */
final class SettingsCommand {

	public function __construct( private SettingsService $service ) {}

	/**
	 * Prints a module's settings as JSON: all of them, or one.
	 *
	 * ## OPTIONS
	 *
	 * <module>
	 * : Module id, e.g. shipping-cartons.
	 *
	 * [<key>]
	 * : One setting.
	 *
	 * [--schema]
	 * : Print what each setting accepts instead.
	 *
	 * @param array $args       Module id, key.
	 * @param array $assoc_args Options.
	 */
	public function get( $args, $assoc_args ): void {
		$module = $this->module( (string) $args[0] );
		$data   = empty( $assoc_args['schema'] ) ? $this->service->values( $module ) : $this->service->schema( $module );

		if ( isset( $args[1] ) ) {
			if ( ! array_key_exists( $args[1], $data ) ) {
				\WP_CLI::error( sprintf( 'No setting %s in %s.', $args[1], $module->id() ) );
			}
			$data = $data[ $args[1] ];
		}

		\WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Changes one setting, sanitised as the settings page would.
	 *
	 * ## OPTIONS
	 *
	 * <module>
	 * : Module id, e.g. shipping-cartons.
	 *
	 * <key>
	 * : Setting key.
	 *
	 * <value>
	 * : Read as JSON when it is JSON (true, 1.5, ["a"], [{"code":"N12"}]); as text otherwise.
	 *
	 * [--string]
	 * : Always read the value as text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp galaxie settings set shipping-cartons margin 1.5
	 *     wp galaxie settings set shipping-cartons cartons '[{"code":"N12","length":12,"width":12,"height":12,"active":true}]'
	 *
	 * @param array $args       Module id, key, value.
	 * @param array $assoc_args Options.
	 */
	public function set( $args, $assoc_args ): void {
		$module = $this->module( (string) $args[0] );
		$key    = (string) $args[1];
		$raw    = (string) $args[2];
		$value  = $raw;

		if ( empty( $assoc_args['string'] ) ) {
			$decoded = json_decode( $raw, true );
			$value   = JSON_ERROR_NONE === json_last_error() ? $decoded : $raw;
		}

		$saved = $this->service->update_settings( $module, array( $key => $value ) );

		if ( $saved instanceof \WP_Error ) {
			\WP_CLI::error( $saved->get_error_message() );
		}

		\WP_CLI::success( sprintf( '%s.%s = %s', $module->id(), $key, (string) wp_json_encode( $saved[ $key ] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
	}

	/** @return \Galaxie\Woo\Core\Module&\Galaxie\Woo\Core\ProvidesSettings */
	private function module( string $id ) {
		$module = $this->service->configurable( $id );

		if ( null === $module ) {
			\WP_CLI::error( sprintf( 'No module %s with settings.', $id ) );
		}

		return $module;
	}
}

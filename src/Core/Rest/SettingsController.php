<?php
/**
 * REST routes for module toggles and module settings.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core\Rest;

use Galaxie\Woo\Core\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * `galaxie-woo/v1`: the Galaxie settings page without wp-admin.
 *
 *   GET        /modules            every module, on or off
 *   PUT|PATCH  /modules/{id}       { "enabled": true }
 *   GET        /settings/{module}  values + schema
 *   PUT|PATCH  /settings/{module}  { "key": value, ... } — only the keys sent change
 *
 * Standard REST authentication (a logged-in cookie with the `wp_rest` nonce,
 * or an Application Password) and `manage_woocommerce`, the same capability as
 * the settings page. Every write goes through {@see SettingsService}, which the
 * settings page saves through too. See docs/rest-api.md.
 */
final class SettingsController {

	public const NAMESPACE = 'galaxie-woo/v1';

	public function __construct( private SettingsService $service ) {}

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/modules',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_modules' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/modules/(?P<id>[a-z0-9-]+)',
			array(
				'methods'             => 'PUT, PATCH',
				'callback'            => array( $this, 'update_module' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'enabled' => array(
						'description'       => __( 'Whether the module is on. Takes effect from the next request.', 'galaxie-woo' ),
						'type'              => 'boolean',
						'required'          => true,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/(?P<module>[a-z0-9-]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	/** @return true|\WP_Error */
	public function can_manage() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to change Galaxie settings.', 'galaxie-woo' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/** @param \WP_REST_Request $request */
	public function get_modules( $request ) {
		return rest_ensure_response( $this->service->module_rows() );
	}

	/** @param \WP_REST_Request $request */
	public function update_module( $request ) {
		$id    = (string) $request['id'];
		$state = $this->service->set_enabled( $id, (bool) $request['enabled'] );

		if ( $state instanceof \WP_Error ) {
			return $state;
		}

		foreach ( $this->service->module_rows() as $row ) {
			if ( $row['id'] === $id ) {
				return rest_ensure_response( $row );
			}
		}

		return rest_ensure_response( array( 'id' => $id, 'enabled' => $state ) );
	}

	/** @param \WP_REST_Request $request */
	public function get_settings( $request ) {
		$module = $this->module( (string) $request['module'] );

		return $module instanceof \WP_Error ? $module : rest_ensure_response( $this->payload( $module ) );
	}

	/** @param \WP_REST_Request $request */
	public function update_settings( $request ) {
		$module = $this->module( (string) $request['module'] );

		if ( $module instanceof \WP_Error ) {
			return $module;
		}

		// The JSON body, or form fields when the client sent those; never the
		// URL's own `module` parameter.
		$patch = $request->get_json_params();
		$patch = is_array( $patch ) ? $patch : (array) $request->get_body_params();

		if ( ! $patch ) {
			return new \WP_Error( 'galaxie_empty_settings', __( 'Send at least one setting, as a JSON object.', 'galaxie-woo' ), array( 'status' => 400 ) );
		}

		$saved = $this->service->update_settings( $module, $patch );

		return $saved instanceof \WP_Error ? $saved : rest_ensure_response( $this->payload( $module ) );
	}

	/** @return \Galaxie\Woo\Core\Module|\WP_Error A module with settings. */
	private function module( string $id ) {
		$module = $this->service->configurable( $id );

		if ( null === $module ) {
			/* translators: %s: module id. */
			return new \WP_Error( 'galaxie_unknown_module', sprintf( __( 'No module %s with settings.', 'galaxie-woo' ), $id ), array( 'status' => 404 ) );
		}

		return $module;
	}

	/**
	 * @param \Galaxie\Woo\Core\Module&\Galaxie\Woo\Core\ProvidesSettings $module
	 * @return array<string,mixed>
	 */
	private function payload( $module ): array {
		return array(
			'module'  => $module->id(),
			'label'   => $module->settings_tab_label(),
			'enabled' => $this->service->is_enabled( $module ),
			'values'  => $this->service->values( $module ),
			'schema'  => $this->service->schema( $module ),
		);
	}
}

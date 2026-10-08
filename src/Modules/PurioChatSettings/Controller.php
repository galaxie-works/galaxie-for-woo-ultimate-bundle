<?php
/**
 * REST route for PurioChat's settings.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PurioChatSettings;

use Galaxie\Woo\Core\Rest\SettingsController;

defined( 'ABSPATH' ) || exit;

/**
 * `galaxie-woo/v1/puriochat`:
 *
 *   GET        /puriochat   { plugin, settings, secrets, schema }
 *                           ?keys=a,b   only those settings
 *                           ?schema=0   leave the schema out
 *   PUT|PATCH  /puriochat   { "listeo_ai_chat_name": "Runa", ... } — only the keys sent change
 *
 * `manage_options`, the capability PurioChat's own settings screen asks for.
 * A write is all or nothing: every key is checked and cleaned first, and one
 * bad key leaves every option untouched. See docs/rest-api.md.
 */
final class Controller {

	public const ROUTE = '/puriochat';

	public function __construct( private PurioChat $purio ) {}

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			SettingsController::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'keys'   => array(
							'description' => __( 'Comma-separated option names; only those are returned.', 'galaxie-woo' ),
							'type'        => 'string',
						),
						'schema' => array(
							'description' => __( 'Include the schema (default true).', 'galaxie-woo' ),
							'type'        => 'boolean',
							'default'     => true,
						),
					),
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
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to change PurioChat settings.', 'galaxie-woo' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/** @param \WP_REST_Request $request */
	public function get_settings( $request ) {
		$schema = $this->purio->schema();

		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}

		$keys = array_filter( array_map( 'trim', explode( ',', (string) $request['keys'] ) ) );
		if ( $keys ) {
			$unknown = array_diff( $keys, array_keys( $schema ) );
			if ( $unknown ) {
				return $this->unknown( $unknown );
			}
			$schema = array_intersect_key( $schema, array_flip( $keys ) );
		}

		$body = array(
			'plugin'   => array(
				'version' => $this->purio->version(),
				'pro'     => $this->purio->pro(),
			),
			'settings' => $this->values( $schema ),
			'secrets'  => $this->purio->secrets_set(),
		);

		if ( false !== $request['schema'] && 'false' !== $request['schema'] && '0' !== (string) $request['schema'] ) {
			$body['schema'] = $schema;
		}

		return rest_ensure_response( $body );
	}

	/** @param \WP_REST_Request $request */
	public function update_settings( $request ) {
		$schema = $this->purio->schema();

		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}

		$patch = $request->get_json_params();
		$patch = is_array( $patch ) ? $patch : (array) $request->get_body_params();

		// WordPress's own request parameters (`_fields`, `_embed`, `_locale`,
		// `_method`) can arrive in the body; PurioChat has no option named so.
		$patch = array_filter( $patch, static fn( $key ) => '_' !== substr( (string) $key, 0, 1 ), ARRAY_FILTER_USE_KEY );

		if ( ! $patch ) {
			return new \WP_Error( 'galaxie_empty_settings', __( 'Send at least one setting, as a JSON object.', 'galaxie-woo' ), array( 'status' => 400 ) );
		}

		$secrets = array_values( array_filter( array_keys( $patch ), fn( $key ) => $this->purio->is_secret( (string) $key ) ) );
		if ( $secrets ) {
			return new \WP_Error(
				'galaxie_puriochat_secret',
				/* translators: %s: option names */
				sprintf( __( 'API keys and tokens are set on PurioChat\'s own settings screen, not here: %s', 'galaxie-woo' ), implode( ', ', $secrets ) ),
				array( 'status' => 400, 'keys' => $secrets )
			);
		}

		$unknown = array_diff( array_map( 'strval', array_keys( $patch ) ), array_keys( $schema ) );
		if ( $unknown ) {
			return $this->unknown( $unknown );
		}

		// PurioChat's own save swaps the chat model when the provider changes, to
		// a default it picks. Make that choice explicit instead.
		if ( array_key_exists( 'listeo_ai_search_provider', $patch )
			&& (string) $patch['listeo_ai_search_provider'] !== (string) get_option( 'listeo_ai_search_provider', '' )
			&& ! array_key_exists( 'listeo_ai_chat_model', $patch ) ) {
			return new \WP_Error( 'galaxie_puriochat_model_required', __( 'Changing listeo_ai_search_provider needs listeo_ai_chat_model in the same request, so the chat keeps a model that provider offers.', 'galaxie-woo' ), array( 'status' => 400 ) );
		}

		$clean = array();
		foreach ( $patch as $key => $value ) {
			$value = $this->purio->sanitize( (string) $key, $value, $schema[ $key ] );
			if ( $value instanceof \WP_Error ) {
				return $value;
			}
			$clean[ (string) $key ] = $value;
		}

		foreach ( $clean as $key => $value ) {
			update_option( $key, $value );
		}

		$this->purio->after_save( $clean );

		return rest_ensure_response(
			array(
				'updated'  => array_keys( $clean ),
				'settings' => $this->values( array_intersect_key( $schema, $clean ) ),
			)
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $schema
	 * @return array<string,mixed>
	 */
	private function values( array $schema ): array {
		$values = array();
		foreach ( $schema as $key => $entry ) {
			$values[ $key ] = $this->purio->value( $key, $entry );
		}
		return $values;
	}

	/** @param string[] $keys */
	private function unknown( array $keys ): \WP_Error {
		return new \WP_Error(
			'galaxie_puriochat_unknown',
			/* translators: %s: option names */
			sprintf( __( 'Not a PurioChat setting: %s. GET the route for the list.', 'galaxie-woo' ), implode( ', ', $keys ) ),
			array( 'status' => 400, 'keys' => array_values( $keys ) )
		);
	}
}

<?php
/**
 * Reading and saving module toggles and settings, whatever sends them.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The one place module toggles and module settings are written. The admin page
 * ({@see Admin\SettingsPage}), the REST routes ({@see Rest\SettingsController})
 * and WP-CLI ({@see Cli\SettingsCommand}) all save through here, so a value is
 * sanitised by the module's own {@see ProvidesSettings::sanitize_settings()},
 * and its side effects run (Shipping Cartons clearing cached rates, say),
 * whichever of them sent it.
 *
 * The admin form posts every field of a tab at once. The API sends only what
 * changes: {@see self::update_settings()} fills in the rest from what is saved,
 * or the field default, and then sanitises the same way.
 */
final class SettingsService {

	public function __construct( private ModuleRegistry $modules, private Settings $settings ) {}

	// ---------------------------------------------------------------- modules

	/** @return array<int,array{id:string,label:string,description:string,enabled:bool,configurable:bool}> */
	public function module_rows(): array {
		$rows = array();

		foreach ( $this->modules->all() as $module ) {
			$rows[] = array(
				'id'           => $module->id(),
				'label'        => $module->title(),
				'description'  => $module->description(),
				'enabled'      => $this->modules->is_enabled( $module ),
				'configurable' => $module instanceof ProvidesSettings,
			);
		}

		return $rows;
	}

	/**
	 * Saves the whole on/off board. A module missing from `$submitted` is off,
	 * the way an unticked checkbox is.
	 *
	 * @param array<string,mixed> $submitted Module id => truthy when on.
	 * @return array<string,bool> The map saved.
	 */
	public function save_modules( array $submitted ): array {
		$map = array();

		foreach ( $this->modules->all() as $module ) {
			$map[ $module->id() ] = ! empty( $submitted[ $module->id() ] );
		}

		$this->settings->set_enabled_map( $map );

		return $map;
	}

	/**
	 * Turns one module on or off and leaves every other as it is now. Takes
	 * effect from the next request: modules boot on `plugins_loaded`.
	 *
	 * @return bool|\WP_Error The module's new state.
	 */
	public function set_enabled( string $id, bool $enabled ) {
		if ( ! isset( $this->modules->all()[ $id ] ) ) {
			/* translators: %s: module id. */
			return self::error( 'galaxie_unknown_module', sprintf( __( 'No module %s.', 'galaxie-woo' ), $id ), 404 );
		}

		$submitted = array();

		foreach ( $this->modules->all() as $module ) {
			$submitted[ $module->id() ] = $module->id() === $id ? $enabled : $this->modules->is_enabled( $module );
		}

		return $this->save_modules( $submitted )[ $id ];
	}

	/**
	 * A module with a settings tab, enabled or not.
	 *
	 * @return (Module&ProvidesSettings)|null
	 */
	public function configurable( string $id ) {
		$module = $this->modules->all()[ $id ] ?? null;

		return $module instanceof ProvidesSettings ? $module : null;
	}

	public function is_enabled( Module $module ): bool {
		return $this->modules->is_enabled( $module );
	}

	// --------------------------------------------------------------- settings

	/**
	 * Saves a settings tab as the form posts it: every field present (an
	 * unticked toggle absent) plus whatever the module's extra markup drew.
	 *
	 * @param Module&ProvidesSettings $module
	 * @param array<string,mixed>     $submitted Already unslashed.
	 * @return array<string,mixed> What was saved.
	 */
	public function save_settings( $module, array $submitted ): array {
		return $this->apply( $module, $submitted, false );
	}

	/**
	 * Changes some of a module's settings: the keys sent, and nothing else.
	 *
	 * Refused, with nothing saved, when the module is off (the settings page has
	 * no tab for it either), when a key is not one of its settings, when a value
	 * has the wrong shape (a list where one value goes, rows that are not
	 * objects), or when saving would change something only its settings page
	 * can edit. Values of the right shape are sanitised exactly as a wp-admin
	 * save sanitises them, so an out-of-range number is clamped, not refused:
	 * the saved values come back to show what was kept.
	 *
	 * @param Module&ProvidesSettings $module
	 * @param array<string,mixed>     $patch Key => value.
	 * @return array<string,mixed>|\WP_Error The module's values after saving.
	 */
	public function update_settings( $module, array $patch ) {
		if ( ! $this->modules->is_enabled( $module ) ) {
			/* translators: %s: module id. */
			return self::error( 'galaxie_module_disabled', sprintf( __( 'Module %s is disabled: enable it before changing its settings.', 'galaxie-woo' ), $module->id() ), 409 );
		}

		$fields  = $this->fields( $module );
		$rows    = $this->row_schema( $module );
		$allowed = array_merge( array_keys( $fields ), array_keys( $rows ) );
		$unknown = array_values( array_diff( array_map( 'strval', array_keys( $patch ) ), $allowed ) );

		if ( $unknown ) {
			return self::error(
				'galaxie_unknown_settings',
				/* translators: 1: module id, 2: setting keys. */
				sprintf( __( 'Unknown settings for %1$s: %2$s.', 'galaxie-woo' ), $module->id(), implode( ', ', $unknown ) ),
				400,
				array(
					'unknown_keys' => $unknown,
					'allowed_keys' => $allowed,
				)
			);
		}

		$invalid = self::shape_errors( $fields, $rows, $patch );

		if ( $invalid ) {
			return self::error(
				'galaxie_invalid_settings',
				/* translators: %s: setting keys. */
				sprintf( __( 'Wrong kind of value for: %s.', 'galaxie-woo' ), implode( ', ', array_keys( $invalid ) ) ),
				400,
				array( 'invalid_keys' => $invalid )
			);
		}

		$current   = $this->settings->module_settings( $module->id() );
		$submitted = array();

		// What the form would post: the value sent, else the one saved, else the
		// default. Starting from saved values is what keeps a partial update from
		// switching off every toggle it did not mention.
		foreach ( $fields as $key => $field ) {
			if ( array_key_exists( $key, $patch ) ) {
				$submitted[ $key ] = $patch[ $key ];
			} elseif ( array_key_exists( $key, $current ) ) {
				$submitted[ $key ] = $current[ $key ];
			} else {
				$submitted[ $key ] = $field->default;
			}
		}

		foreach ( array_keys( $rows ) as $key ) {
			if ( array_key_exists( $key, $patch ) ) {
				$submitted[ $key ] = array_values( $patch[ $key ] );
			} elseif ( is_array( $current[ $key ] ?? null ) ) {
				$submitted[ $key ] = array_values( $current[ $key ] );
			}
		}

		if ( $module instanceof ProvidesRestSettings ) {
			$submitted = $module->rest_settings_submitted( $submitted );
		}

		$saved = $this->apply( $module, $submitted, true );

		return $saved instanceof \WP_Error ? $saved : $this->values( $module );
	}

	/**
	 * A module's settings as the API shows them: every field (its default when
	 * never saved) and every row list. Secrets come back empty, as the settings
	 * page never shows them either; {@see self::schema()} says whether one is set.
	 *
	 * @param Module&ProvidesSettings $module
	 * @return array<string,mixed>
	 */
	public function values( $module ): array {
		$stored = $this->settings->module_settings( $module->id() );
		$out    = array();

		foreach ( $this->fields( $module ) as $key => $field ) {
			$value       = array_key_exists( $key, $stored ) ? $stored[ $key ] : $field->default;
			$out[ $key ] = Field::TYPE_PASSWORD === $field->type ? '' : $value;
		}

		foreach ( $this->row_schema( $module ) as $key => $schema ) {
			$out[ $key ] = is_array( $stored[ $key ] ?? null ) ? array_values( $stored[ $key ] ) : ( $schema['default'] ?? array() );
		}

		return $out;
	}

	/**
	 * What each setting accepts, from the module's fields and row schema.
	 *
	 * @param Module&ProvidesSettings $module
	 * @return array<string,array<string,mixed>>
	 */
	public function schema( $module ): array {
		$stored = $this->settings->module_settings( $module->id() );
		$out    = array();

		foreach ( $this->fields( $module ) as $key => $field ) {
			$entry = array(
				'type'        => $field->type,
				'json_type'   => self::json_type( $field->type ),
				'label'       => $field->label,
				'description' => $field->description,
				'default'     => Field::TYPE_PASSWORD === $field->type ? '' : $field->default,
			);

			if ( $field->options ) {
				// A list, not a map: numeric ids would otherwise turn it into a JSON array or reorder it.
				$entry['options'] = array();
				foreach ( $field->options as $value => $label ) {
					$entry['options'][] = array(
						'value' => (string) $value,
						'label' => $label,
					);
				}
			}

			foreach ( array( 'min', 'max', 'step' ) as $bound ) {
				if ( '' !== $field->$bound ) {
					$entry[ $bound ] = (float) $field->$bound;
				}
			}

			if ( Field::TYPE_PASSWORD === $field->type ) {
				$entry['write_only'] = true;
				$entry['has_value']  = '' !== (string) ( $stored[ $key ] ?? '' );
			}

			$out[ $key ] = $entry;
		}

		foreach ( $this->row_schema( $module ) as $key => $schema ) {
			$out[ $key ] = array( 'type' => 'rows' ) + $schema + array( 'json_type' => 'array' );
		}

		return $out;
	}

	/**
	 * Sanitises through the module and saves. With `$guard`, refuses a save that
	 * would change a saved key the caller could not address.
	 *
	 * The guard runs after sanitising, so a refused request has still run the
	 * module's sanitiser. Those side effects are the harmless ones: Shipping
	 * Cartons clearing cached rates. The ones that change other data (FluentCRM
	 * pushing interests, Free Shipping switching methods off) only run for form
	 * keys the API cannot send.
	 *
	 * @param Module&ProvidesSettings $module
	 * @param array<string,mixed>     $submitted
	 * @return array<string,mixed>|\WP_Error
	 */
	private function apply( $module, array $submitted, bool $guard ) {
		$current   = $this->settings->module_settings( $module->id() );
		$sanitized = $module->sanitize_settings( $submitted, $current );

		if ( $guard ) {
			$lost = $this->lost_keys( $module, $current, $sanitized );

			if ( $lost ) {
				return self::error(
					'galaxie_settings_form_only',
					/* translators: 1: module id, 2: setting keys. */
					sprintf( __( 'Saving %1$s from here would change settings only its wp-admin tab can edit: %2$s. Nothing was saved.', 'galaxie-woo' ), $module->id(), implode( ', ', $lost ) ),
					409,
					array( 'form_only_keys' => $lost )
				);
			}
		}

		$this->settings->set_module_settings( $module->id(), $sanitized );

		return $sanitized;
	}

	/**
	 * Saved keys outside the fields and row schema whose value a save would
	 * change or drop. Empty saved values do not count: there is nothing to lose.
	 *
	 * @param Module&ProvidesSettings $module
	 * @param array<string,mixed>     $current
	 * @param array<string,mixed>     $sanitized
	 * @return string[]
	 */
	private function lost_keys( $module, array $current, array $sanitized ): array {
		$addressable = array_merge( array_keys( $this->fields( $module ) ), array_keys( $this->row_schema( $module ) ) );
		$lost        = array();

		foreach ( $current as $key => $value ) {
			if ( in_array( (string) $key, $addressable, true ) || in_array( $value, array( '', null, false, array() ), true ) ) {
				continue;
			}

			if ( ! array_key_exists( $key, $sanitized ) || $sanitized[ $key ] !== $value ) {
				$lost[] = (string) $key;
			}
		}

		return $lost;
	}

	/**
	 * Values whose shape no form could post: those are refused, not coerced.
	 *
	 * @param array<string,Field>                $fields
	 * @param array<string,array<string,mixed>>  $rows
	 * @param array<string,mixed>                $patch
	 * @return array<string,string> key => what it must be.
	 */
	private static function shape_errors( array $fields, array $rows, array $patch ): array {
		$errors = array();

		foreach ( $patch as $key => $value ) {
			$key = (string) $key;

			if ( isset( $rows[ $key ] ) ) {
				if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
					$errors[ $key ] = 'array of objects';
					continue;
				}

				foreach ( $value as $row ) {
					if ( ! is_array( $row ) || ( $row && array_is_list( $row ) ) ) {
						$errors[ $key ] = 'array of objects';
						break;
					}
				}
				continue;
			}

			$field = $fields[ $key ] ?? null;

			if ( null === $field ) {
				continue;
			}

			$ok = match ( $field->type ) {
				Field::TYPE_TOGGLE => is_bool( $value ) || 0 === $value || 1 === $value,
				Field::TYPE_MULTI  => is_array( $value ) && array_is_list( $value ) && ! array_filter( $value, static fn( $item ): bool => ! is_scalar( $item ) ),
				default            => is_scalar( $value ) || null === $value,
			};

			if ( ! $ok ) {
				$errors[ $key ] = self::json_type( $field->type );
			}
		}

		return $errors;
	}

	/**
	 * @param Module&ProvidesSettings $module
	 * @return array<string,Field> By key.
	 */
	private function fields( $module ): array {
		$out = array();

		foreach ( $module->settings_fields() as $field ) {
			$out[ $field->key ] = $field;
		}

		return $out;
	}

	/**
	 * @param Module&ProvidesSettings $module
	 * @return array<string,array<string,mixed>>
	 */
	private function row_schema( $module ): array {
		return $module instanceof ProvidesRestSettings ? $module->rest_settings_schema() : array();
	}

	private static function json_type( string $type ): string {
		return match ( $type ) {
			Field::TYPE_NUMBER => 'number',
			Field::TYPE_TOGGLE => 'boolean',
			Field::TYPE_MULTI  => 'array',
			default            => 'string',
		};
	}

	/** @param array<string,mixed> $data */
	private static function error( string $code, string $message, int $status, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) + $data );
	}
}

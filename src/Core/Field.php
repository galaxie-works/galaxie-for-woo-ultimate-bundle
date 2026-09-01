<?php
/**
 * Declarative settings field value object.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

defined( 'ABSPATH' ) || exit;

/**
 * One row on a module's settings tab. Covers the common cases (text, password,
 * number, a toggle, a select) so most modules never hand-render a form — see
 * {@see ProvidesSettings} for the escape hatch when a module needs more
 * (FluentCRM's auto-discovered tag/list dropdowns, for instance).
 */
final class Field {

	public const TYPE_TEXT     = 'text';
	public const TYPE_PASSWORD = 'password';
	public const TYPE_NUMBER   = 'number';
	public const TYPE_TOGGLE   = 'toggle';
	public const TYPE_SELECT   = 'select';

	/**
	 * @param string               $key         Storage key within the module's settings array.
	 * @param string               $label       Field label.
	 * @param string               $type        One of the TYPE_* constants.
	 * @param string               $description Help text shown under the field.
	 * @param mixed                $default     Default value when never saved.
	 * @param array<string,string> $options     value => label, required for TYPE_SELECT.
	 * @param string               $placeholder Input placeholder (text/password/number only).
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $type = self::TYPE_TEXT,
		public readonly string $description = '',
		public readonly mixed $default = '',
		public readonly array $options = array(),
		public readonly string $placeholder = ''
	) {}

	/** Sanitize one submitted raw value according to this field's type. */
	public function sanitize( mixed $raw ): mixed {
		return match ( $this->type ) {
			self::TYPE_TOGGLE => ! empty( $raw ),
			self::TYPE_NUMBER => is_numeric( $raw ) ? (float) $raw : $this->default,
			self::TYPE_SELECT => array_key_exists( (string) $raw, $this->options ) ? (string) $raw : $this->default,
			default => sanitize_text_field( (string) $raw ),
		};
	}

	/**
	 * Sanitize a full submitted array against a field list, keyed by `key`.
	 * Toggles are treated as absent-when-unchecked, same as checkboxes.
	 *
	 * @param Field[]              $fields
	 * @param array<string,mixed>  $submitted
	 * @return array<string,mixed>
	 */
	public static function sanitize_all( array $fields, array $submitted ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$out[ $field->key ] = $field->sanitize( $submitted[ $field->key ] ?? ( self::TYPE_TOGGLE === $field->type ? false : '' ) );
		}
		return $out;
	}
}

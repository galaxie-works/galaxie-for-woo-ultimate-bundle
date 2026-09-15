<?php
/**
 * Settings API tests: `php tests/settings-api/run.php`.
 *
 * Covers `Core\SettingsService` (the save path the settings page, the REST API
 * and WP-CLI share), `Core\Rest\SettingsController` and the gift packing meta
 * (`Modules\GiftWrap\ProductMeta`). No PHPUnit and no WordPress: a plain runner
 * like tests/gift-packing, on the stubs in wp-stubs.php. Exits 1 on any failure.
 *
 * @package Galaxie\Woo
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/wp-stubs.php';
require dirname( __DIR__ ) . '/gift-packing/wc-stubs.php';

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Galaxie\\Woo\\';
		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}
		$path = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module;
use Galaxie\Woo\Core\ModuleRegistry;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Core\Rest\SettingsController;
use Galaxie\Woo\Core\Settings;
use Galaxie\Woo\Core\SettingsService;
use Galaxie\Woo\Modules\GiftWrap\BoxFields;
use Galaxie\Woo\Modules\GiftWrap\CandleFields;
use Galaxie\Woo\Modules\GiftWrap\ProductMeta;
use Galaxie\Woo\Modules\QuantityDiscounts\Module as QuantityDiscounts;
use Galaxie\Woo\Modules\ShippingCartons\Module as ShippingCartons;
use Galaxie\Woo\Support\GiftPacking;

$failed = 0;
$passed = 0;

$check = static function ( string $group, string $name, $actual, $expect ) use ( &$failed, &$passed ): void {
	if ( $actual === $expect ) {
		++$passed;
		echo "  ok    {$group}: {$name}\n";
		return;
	}

	++$failed;
	echo "  FAIL  {$group}: {$name}\n        expected " . json_encode( $expect ) . "\n        got      " . json_encode( $actual ) . "\n";
};

/** [ code, status, extra data key ] of an error, or 'not an error'. */
$error = static function ( $result, string $data_key = '' ) {
	if ( ! $result instanceof WP_Error ) {
		return 'not an error: ' . json_encode( $result );
	}
	$data = (array) $result->get_error_data();
	$out  = array( $result->get_error_code(), $data['status'] ?? null );
	if ( '' !== $data_key ) {
		$out[] = $data[ $data_key ] ?? null;
	}
	return $out;
};

// A module with a secret, as GoogleLogin and AddressAutocomplete: blank keeps the saved one.
$secret = new class() implements Module, ProvidesSettings {
	public function id(): string {
		return 'secret';
	}
	public function title(): string {
		return 'Secret';
	}
	public function description(): string {
		return '';
	}
	public function default_enabled(): bool {
		return true;
	}
	public function boot(): void {}
	public function settings_tab_label(): string {
		return 'Secret';
	}
	public function settings_fields(): array {
		return array(
			new Field( key: 'api_key', label: 'API key', type: Field::TYPE_PASSWORD ),
			new Field( key: 'country', label: 'Country', default: 'BR' ),
			new Field( key: 'tags', label: 'Tags', type: Field::TYPE_MULTI, default: array(), options: array( '12' => 'A', '15' => 'B' ) ),
		);
	}
	public function render_extra_settings( array $values ): void {}
	public function sanitize_settings( array $submitted, array $current ): array {
		$out = Field::sanitize_all( $this->settings_fields(), $submitted );
		if ( '' === $out['api_key'] ) {
			$out['api_key'] = $current['api_key'] ?? '';
		}
		return $out;
	}
};

// A tab whose repeater posts rows the API has no schema for.
$form_only = new class() implements Module, ProvidesSettings {
	public function id(): string {
		return 'form-only';
	}
	public function title(): string {
		return 'Form only';
	}
	public function description(): string {
		return '';
	}
	public function default_enabled(): bool {
		return true;
	}
	public function boot(): void {}
	public function settings_tab_label(): string {
		return 'Form only';
	}
	public function settings_fields(): array {
		return array( new Field( key: 'label', label: 'Label' ) );
	}
	public function render_extra_settings( array $values ): void {}
	public function sanitize_settings( array $submitted, array $current ): array {
		return Field::sanitize_all( $this->settings_fields(), $submitted ) + array( 'rows' => array_values( (array) ( $submitted['rows'] ?? array() ) ) );
	}
};

/** A fresh store: no options, every module registered. */
$boot = static function () use ( $secret, $form_only ): array {
	$GLOBALS['gx_options']     = array();
	$GLOBALS['gx_cache_bumps'] = 0;

	$settings = new Settings();
	$registry = new ModuleRegistry( $settings );
	$cartons  = new ShippingCartons();
	$qd       = new QuantityDiscounts();

	foreach ( array( $cartons, $qd, $secret, $form_only ) as $module ) {
		$registry->register( $module );
	}

	return array( new SettingsService( $registry, $settings ), $cartons, $qd );
};

$saved = static fn( string $id ): array => (array) ( get_option( 'galaxie_woo_settings', array() )[ $id ] ?? array() );

// ------------------------------------------------------------------ modules

[ $service, $cartons, $qd ] = $boot();

$enabled = static function ( SettingsService $service, string $id ): ?bool {
	foreach ( $service->module_rows() as $row ) {
		if ( $row['id'] === $id ) {
			return $row['enabled'];
		}
	}
	return null;
};

$check( 'modules', 'shipping-cartons is off until enabled', $enabled( $service, 'shipping-cartons' ), false );
$check( 'modules', 'enable returns the new state', $service->set_enabled( 'shipping-cartons', true ), true );
$check(
	'modules',
	'the whole board is saved, the others as they were',
	get_option( 'galaxie_woo_modules' ),
	array(
		'shipping-cartons'   => true,
		'quantity-discounts' => $qd->default_enabled(),
		'secret'             => true,
		'form-only'          => true,
	)
);
$check( 'modules', 'disable', array( $service->set_enabled( 'shipping-cartons', false ), $enabled( $service, 'shipping-cartons' ) ), array( false, false ) );
$check( 'modules', 'unknown module: 404', $error( $service->set_enabled( 'nope', true ) ), array( 'galaxie_unknown_module', 404 ) );
$check( 'modules', 'settings page board: a module absent from the form is off', $service->save_modules( array( 'secret' => '1' ) )['form-only'], false );

// ----------------------------------------------------------- settings: refused

[ $service, $cartons ] = $boot();

$check( 'settings', 'disabled module: 409', $error( $service->update_settings( $cartons, array( 'margin' => 2 ) ) ), array( 'galaxie_module_disabled', 409 ) );
$check( 'settings', 'disabled module: nothing saved', get_option( 'galaxie_woo_settings', 'none' ), 'none' );

$service->set_enabled( 'shipping-cartons', true );

$check(
	'settings',
	'unknown keys: 400 listing them',
	$error( $service->update_settings( $cartons, array( 'margin' => 2, 'bogus' => 1, 'margins' => 3 ) ), 'unknown_keys' ),
	array( 'galaxie_unknown_settings', 400, array( 'bogus', 'margins' ) )
);
$check( 'settings', 'unknown keys: nothing saved, not even the known one', get_option( 'galaxie_woo_settings', 'none' ), 'none' );
$check( 'settings', 'cartons as text: 400', $error( $service->update_settings( $cartons, array( 'cartons' => 'N12' ) ), 'invalid_keys' ), array( 'galaxie_invalid_settings', 400, array( 'cartons' => 'array of objects' ) ) );
$check( 'settings', 'carton rows that are lists: 400', $error( $service->update_settings( $cartons, array( 'cartons' => array( array( 'N12', 12 ) ) ) ) ), array( 'galaxie_invalid_settings', 400 ) );
$check( 'settings', 'cartons keyed by code instead of a list: 400', $error( $service->update_settings( $cartons, array( 'cartons' => array( 'N12' => array( 'code' => 'N12' ) ) ) ) ), array( 'galaxie_invalid_settings', 400 ) );
$check( 'settings', 'a list for a number: 400', $error( $service->update_settings( $cartons, array( 'margin' => array( 1 ) ) ), 'invalid_keys' ), array( 'galaxie_invalid_settings', 400, array( 'margin' => 'number' ) ) );
$check( 'settings', '"yes" for a toggle: 400', $error( $service->update_settings( $cartons, array( 'stacking' => 'yes' ) ), 'invalid_keys' ), array( 'galaxie_invalid_settings', 400, array( 'stacking' => 'boolean' ) ) );
$check( 'settings', 'wrong shapes: nothing saved', get_option( 'galaxie_woo_settings', 'none' ), 'none' );
$check( 'settings', 'nothing refused ran the sanitiser', $GLOBALS['gx_cache_bumps'], 0 );

// ------------------------------------------- settings: same as the settings page

$rows = array(
	array( 'code' => 'N12', 'name' => '<b>Caixa</b> 12', 'length' => '12', 'width' => 12, 'height' => 12.004, 'outer_length' => '11', 'active' => true ),
	array( 'code' => 'N12', 'length' => 250, 'width' => 10, 'height' => 10, 'max_load' => 0, 'empty_weight' => -5 ),
	array( 'code' => '', 'length' => 10 ),
	array( 'code' => 'Ü*20', 'length' => 20, 'width' => 20, 'height' => 20, 'outer_width' => 21.5, 'empty_weight' => '180.6', 'active' => false ),
);

// The settings page posts strings, and no `stacking` at all when unticked.
[ $service, $cartons ] = $boot();
$service->set_enabled( 'shipping-cartons', true );
$admin       = $service->save_settings( $cartons, array( 'margin' => '9', 'gap' => '-1', 'density' => 'abc', 'fallback' => 'nope', 'cartons' => $rows ) );
$admin_bumps = $GLOBALS['gx_cache_bumps'];

[ $service, $cartons ] = $boot();
$service->set_enabled( 'shipping-cartons', true );
$rest = $service->update_settings( $cartons, array( 'margin' => 9, 'gap' => -1, 'density' => 'abc', 'fallback' => 'nope', 'stacking' => false, 'cartons' => $rows ) );

$check( 'settings', 'REST saves exactly what the settings page saves', $saved( 'shipping-cartons' ), $admin );
$check( 'settings', 'out of range clamped, junk to the default, as on the page', array( $admin['margin'], $admin['gap'], $admin['density'], $admin['fallback'], $admin['stacking'] ), array( 5.0, 0.0, 29.0, 'split', false ) );
$check( 'settings', 'the page\'s side effect runs from REST too (shipping cache bumped)', array( $admin_bumps, $GLOBALS['gx_cache_bumps'] ), array( 1, 1 ) );
$check( 'settings', 'the response shows what was kept', $rest['cartons'] ?? null, $admin['cartons'] );

$check(
	'cartons',
	'rows validated as on the page',
	$admin['cartons'],
	array(
		array( 'code' => 'N12', 'name' => 'Caixa 12', 'length' => 12.0, 'width' => 12.0, 'height' => 12.0, 'outer_length' => '', 'outer_width' => '', 'outer_height' => '', 'empty_weight' => 0, 'max_load' => 30000, 'active' => true ),
		array( 'code' => 'N12-2', 'name' => '', 'length' => 0.0, 'width' => 10.0, 'height' => 10.0, 'outer_length' => '', 'outer_width' => '', 'outer_height' => '', 'empty_weight' => 0, 'max_load' => 30000, 'active' => false ),
		array( 'code' => '20', 'name' => '', 'length' => 20.0, 'width' => 20.0, 'height' => 20.0, 'outer_length' => '', 'outer_width' => 21.5, 'outer_height' => '', 'empty_weight' => 181, 'max_load' => 30000, 'active' => false ),
	)
);

$thirteen = array_fill( 0, 13, array( 'code' => 'X', 'length' => 10, 'width' => 10, 'height' => 10, 'active' => true ) );
$service->update_settings( $cartons, array( 'cartons' => $thirteen ) );
$check( 'cartons', 'at most 12 rows kept, repeated codes numbered', array( count( $saved( 'shipping-cartons' )['cartons'] ), $saved( 'shipping-cartons' )['cartons'][11]['code'] ), array( 12, 'X-12' ) );
$check( 'cartons', 'an empty list clears the table', ( $service->update_settings( $cartons, array( 'cartons' => array() ) ) )['cartons'], array() );

// ------------------------------------------------------- settings: partial

[ $service, $cartons ] = $boot();
$service->set_enabled( 'shipping-cartons', true );
$service->update_settings( $cartons, array( 'cartons' => array( $rows[0] ) ) );
$before = $saved( 'shipping-cartons' )['cartons'];
$after  = $service->update_settings( $cartons, array( 'margin' => 2 ) );

$check( 'partial', 'margin alone: margin changed', $after['margin'], 2.0 );
$check( 'partial', 'margin alone: carton table kept', $saved( 'shipping-cartons' )['cartons'], $before );
$check( 'partial', 'margin alone: never-saved toggle keeps its default (not off like an absent checkbox)', $saved( 'shipping-cartons' )['stacking'], true );
$check( 'partial', 'margin alone: other fields keep defaults', array( $after['gap'], $after['density'], $after['fallback'] ), array( 0.0, 29.0, 'split' ) );

$schema = $service->schema( $cartons );
$check( 'schema', 'margin bounds from the field', array( $schema['margin']['json_type'], $schema['margin']['min'], $schema['margin']['max'], $schema['margin']['step'], $schema['margin']['default'] ), array( 'number', 0.0, 5.0, 0.1, 1.5 ) );
$check( 'schema', 'fallback options as a list', array_column( $schema['fallback']['options'], 'value' ), array( 'split', 'original' ) );
$check( 'schema', 'cartons are rows', array( $schema['cartons']['type'], $schema['cartons']['json_type'], $schema['cartons']['items']['type'] ), array( 'rows', 'array', 'object' ) );

// ------------------------------------------------------------- secrets, multi

[ $service ] = $boot();
$service->update_settings( $secret, array( 'api_key' => 'k-123', 'tags' => array( '15', '99' ) ) );

$check( 'secret', 'saved', $saved( 'secret' )['api_key'], 'k-123' );
$check( 'secret', 'never shown', $service->values( $secret )['api_key'], '' );
$check( 'secret', 'schema says it is set', array( $service->schema( $secret )['api_key']['write_only'], $service->schema( $secret )['api_key']['has_value'] ), array( true, true ) );
$service->update_settings( $secret, array( 'country' => 'PT' ) );
$check( 'secret', 'another key changed: kept', $saved( 'secret' )['api_key'], 'k-123' );
$service->update_settings( $secret, array( 'api_key' => '' ) );
$check( 'secret', 'sent blank: kept, as on the page', $saved( 'secret' )['api_key'], 'k-123' );
$check( 'multi', 'unknown choice dropped, as on the page', $saved( 'secret' )['tags'], array( '15' ) );
$check( 'multi', 'text for a multiselect: 400', $error( $service->update_settings( $secret, array( 'tags' => '15' ) ) ), array( 'galaxie_invalid_settings', 400 ) );

// ------------------------------------------------------ quantity discount rows

[ $service, , $qd ] = $boot();
$service->set_enabled( 'quantity-discounts', true );

$tiers = array(
	array( 'min' => 3, 'max' => 5, 'value' => 5 ),
	array( 'min' => 6, 'max' => 2, 'value' => 10 ),
	array( 'min' => 0, 'value' => 20 ),
	array( 'min' => 2, 'value' => 0 ),
);
$service->update_settings( $qd, array( 'intervals' => $tiers, 'steps' => array( array( 'every' => 12, 'value' => 15 ) ) ) );
$rest_qd = $saved( 'quantity-discounts' );

$check(
	'tiers',
	'rows cleaned by the repeater\'s own parser',
	array( $rest_qd['intervals'], $rest_qd['steps'] ),
	array(
		array( array( 'min' => 3, 'max' => 5, 'value' => 5.0 ), array( 'min' => 6, 'max' => null, 'value' => 10.0 ) ),
		array( array( 'every' => 12, 'value' => 15.0 ) ),
	)
);

$service->update_settings( $qd, array( 'type' => 'fixed' ) );
$check( 'tiers', 'type alone: both tables kept', array( $saved( 'quantity-discounts' )['intervals'], $saved( 'quantity-discounts' )['steps'] ), array( $rest_qd['intervals'], $rest_qd['steps'] ) );
$check( 'tiers', 'type alone: type changed, toggle default kept', array( $saved( 'quantity-discounts' )['type'], $saved( 'quantity-discounts' )['apply_to_variations'] ), array( 'fixed', true ) );

[ $service, , $qd ] = $boot();
$service->set_enabled( 'quantity-discounts', true );
$admin_qd = $service->save_settings(
	$qd,
	array(
		'type'                => 'percentage',
		'rules'               => 'intervals',
		'apply_to_variations' => '1',
		'intervals_min'       => array( '3', '6', '0', '2' ),
		'intervals_max'       => array( '5', '2', '', '' ),
		'intervals_value'     => array( '5', '10', '20', '0' ),
		'steps_every'         => array( '12' ),
		'steps_value'         => array( '15' ),
	)
);
$check( 'tiers', 'REST rows save what the page\'s repeater saves', $rest_qd, $admin_qd );

// ------------------------------------------------------------- form-only guard

[ $service ] = $boot();
update_option( 'galaxie_woo_settings', array( 'form-only' => array( 'label' => 'x', 'rows' => array( array( 'a' => 1 ) ) ) ) );

$check( 'guard', 'would wipe rows only the page edits: 409', $error( $service->update_settings( $form_only, array( 'label' => 'y' ) ), 'form_only_keys' ), array( 'galaxie_settings_form_only', 409, array( 'rows' ) ) );
$check( 'guard', 'nothing saved', $saved( 'form-only' ), array( 'label' => 'x', 'rows' => array( array( 'a' => 1 ) ) ) );
update_option( 'galaxie_woo_settings', array( 'form-only' => array( 'label' => 'x', 'rows' => array() ) ) );
$check( 'guard', 'no rows to lose: saved', $service->update_settings( $form_only, array( 'label' => 'y' ) ), array( 'label' => 'y' ) );

// ---------------------------------------------------------------- controller

[ $service ] = $boot();
$service->set_enabled( 'shipping-cartons', true );
$controller = new SettingsController( $service );

$GLOBALS['gx_caps'] = array( 'edit_products' );
$check( 'rest', 'without manage_woocommerce: forbidden', $error( $controller->can_manage() ), array( 'rest_forbidden', 403 ) );
$GLOBALS['gx_caps'] = array( 'manage_woocommerce' );
$check( 'rest', 'with manage_woocommerce: allowed', $controller->can_manage(), true );

$check( 'rest', 'settings of an unknown module: 404', $error( $controller->get_settings( new WP_REST_Request( array( 'module' => 'nope' ) ) ) ), array( 'galaxie_unknown_module', 404 ) );
$check( 'rest', 'settings of a module without settings tab: 404', $error( $controller->update_settings( new WP_REST_Request( array( 'module' => 'nope' ), array( 'x' => 1 ) ) ) ), array( 'galaxie_unknown_module', 404 ) );
$check( 'rest', 'empty body: 400', $error( $controller->update_settings( new WP_REST_Request( array( 'module' => 'shipping-cartons' ), array() ) ) ), array( 'galaxie_empty_settings', 400 ) );

$response = $controller->update_settings( new WP_REST_Request( array( 'module' => 'shipping-cartons' ), array( 'margin' => '2.5', 'module' => 'x' ) ) );
$check( 'rest', 'a body key named like the URL parameter is still a setting: 400', $error( $response, 'unknown_keys' ), array( 'galaxie_unknown_settings', 400, array( 'module' ) ) );

$response = $controller->update_settings( new WP_REST_Request( array( 'module' => 'shipping-cartons' ), null, array( 'margin' => '2.5' ) ) );
$check( 'rest', 'form-encoded body accepted', array( $response['module'] ?? null, $response['values']['margin'] ?? null ), array( 'shipping-cartons', 2.5 ) );

$response = $controller->get_settings( new WP_REST_Request( array( 'module' => 'shipping-cartons' ) ) );
$check( 'rest', 'GET: values and schema', array( $response['enabled'], $response['values']['margin'], isset( $response['schema']['cartons'] ) ), array( true, 2.5, true ) );
$check( 'rest', 'GET /modules lists all', count( $controller->get_modules( new WP_REST_Request() ) ), 4 );
$check( 'rest', 'PUT /modules/{id}', $controller->update_module( new WP_REST_Request( array( 'id' => 'shipping-cartons', 'enabled' => false ) ) )['enabled'], false );

// ------------------------------------------------------------ product meta

ProductMeta::hooks();

$check( 'meta', 'registered on products and variations', array( count( $GLOBALS['gx_meta']['product'] ), count( $GLOBALS['gx_meta']['product_variation'] ) ), array( 8, 8 ) );

$overflow = $GLOBALS['gx_meta']['product_variation']['_galaxie_box_overflow'];
$check( 'meta', 'overflow schema: number 0–2, single', array( $overflow['type'], $overflow['single'], $overflow['show_in_rest']['schema']['minimum'], $overflow['show_in_rest']['schema']['maximum'] ), array( 'number', true, 0, 2.0 ) );
$check( 'meta', 'max schema: integer', $GLOBALS['gx_meta']['product_variation']['_galaxie_box_max']['type'], 'integer' );

$auth                      = $overflow['auth_callback'];
$GLOBALS['gx_user_caps'][7] = array( 'edit_products' );
$check( 'meta', 'auth: no edit_product on that variation: denied', $auth( true, '_galaxie_box_overflow', 501, 7 ), false );
$GLOBALS['gx_user_caps'][7] = array( 'edit_product:501' );
$check( 'meta', 'auth: edit_product on that variation: allowed', $auth( false, '_galaxie_box_overflow', 501, 7 ), true );
$check( 'meta', 'auth: another variation: denied', $auth( true, '_galaxie_box_overflow', 502, 7 ), false );

$sanitize = static fn( string $key, $value ) => $GLOBALS['gx_meta']['product_variation'][ $key ]['sanitize_callback']( $value );

foreach ( array(
	array( '_galaxie_box_overflow', '3', '2' ),
	array( '_galaxie_box_overflow', 3.5, '2' ),
	array( '_galaxie_box_overflow', '-1', '' ),
	array( '_galaxie_box_overflow', 'abc', '' ),
	array( '_galaxie_box_overflow', 0.5, '0.5' ),
	array( '_galaxie_box_max', '4.7', 4 ),
	array( '_galaxie_box_max', '', 0 ),
	array( '_galaxie_box_length', 13.6, '13.6' ),
	array( '_galaxie_box_length', '0', '' ),
	array( '_galaxie_box_length', array( 1 ), '' ),
	array( '_galaxie_gift_height', 6.7, '6.7' ),
	array( '_galaxie_gift_width', 'x', '' ),
) as $case ) {
	$check( 'meta', 'sanitised ' . $case[0] . ' ' . json_encode( $case[1] ), $sanitize( $case[0], $case[1] ), $case[2] );
}

// The variation panel saves with the same clean(): same input, same stored value.
$GLOBALS['gx_caps'] = array( 'edit_product:501' );
$_POST              = array(
	'galaxie_gift_box_nonce' => 'ok',
	'_galaxie_box_length'    => array( '13.6' ),
	'_galaxie_box_width'     => array( 'abc' ),
	'_galaxie_box_height'    => array( '-2' ),
	'_galaxie_box_max'       => array( '4.7' ),
	'_galaxie_box_overflow'  => array( '3' ),
);
( new BoxFields() )->save( 501, 0 );

$check( 'meta', 'panel save: set values stored', $GLOBALS['gx_post_meta'][501] ?? array(), array( '_galaxie_box_length' => '13.6', '_galaxie_box_max' => 4, '_galaxie_box_overflow' => '2' ) );

$same = true;
foreach ( BoxFields::META as $key ) {
	$stored = $GLOBALS['gx_post_meta'][501][ $key ] ?? null;
	$clean  = $sanitize( $key, $_POST[ $key ][0] );
	$same   = $same && ( null === $stored ? in_array( $clean, array( '', 0 ), true ) : $stored === $clean );
}
$check( 'meta', 'panel save and REST sanitiser agree on every box key', $same, true );

$_POST = array(
	'galaxie_gift_candle_nonce' => 'ok',
	'_galaxie_gift_length'      => array( '5.3' ),
	'_galaxie_gift_width'       => array( '' ),
	'_galaxie_gift_height'      => array( '6.70' ),
);
( new CandleFields() )->save( 601, 0 );
$GLOBALS['gx_caps'][] = 'edit_product:601';
( new CandleFields() )->save( 601, 0 );
$check( 'meta', 'candle panel save: stored as the REST sanitiser gives', $GLOBALS['gx_post_meta'][601] ?? array(), array( '_galaxie_gift_length' => $sanitize( '_galaxie_gift_length', '5.3' ), '_galaxie_gift_height' => $sanitize( '_galaxie_gift_height', '6.70' ) ) );

$GLOBALS['gx_deleted'] = array();
ProductMeta::changed( 1, 501, '_price' );
$check( 'meta', 'another key changed: sizes cache kept', $GLOBALS['gx_deleted'], array() );
ProductMeta::changed( 1, 501, '_galaxie_gift_height' );
$check( 'meta', 'a size changed (any way, REST included): sizes cache cleared', $GLOBALS['gx_deleted'], array( GiftPacking::SIZES_TRANSIENT ) );

$variation = new WC_Product( array( 'id' => 501 ) );
$touching  = new WP_REST_Request( array( 'meta_data' => array( array( 'key' => '_galaxie_box_overflow', 'value' => 1 ) ) ) );
$other     = new WP_REST_Request( array( 'meta_data' => array( array( 'key' => '_something_else', 'value' => 1 ) ) ) );

$GLOBALS['gx_caps'] = array( 'edit_products' );
$check( 'wc rest', 'sizes in meta_data without edit_product on it: refused', $error( ProductMeta::guard_wc_rest( $variation, $touching ) ), array( 'galaxie_meta_forbidden', 403 ) );
$check( 'wc rest', 'other meta: left to WooCommerce', ProductMeta::guard_wc_rest( $variation, $other ), $variation );
$GLOBALS['gx_caps'] = array( 'edit_product:501' );
$check( 'wc rest', 'sizes in meta_data with edit_product on it: passes', ProductMeta::guard_wc_rest( $variation, $touching ), $variation );
$earlier = new WP_Error( 'earlier', 'x' );
$check( 'wc rest', 'an earlier error passes through', ProductMeta::guard_wc_rest( $earlier, $touching ), $earlier );

echo "\n  {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );

<?php
/**
 * Boot smoke test: `php tests/boot/run.php` (exits 1 on any failure).
 *
 * The plain runners in tests/gift-packing call pure code and never load the
 * plugin, and `php -l` only parses — so a module that breaks the site on boot
 * (a method calling itself, an undefined function on a boot path) passed both
 * and took every page down on test. This runner loads the real plugin file on
 * stubbed WordPress (wp-stubs.php) and boots it.
 *
 * 1. Static check: no method whose first statement calls itself (`self::name(`,
 *    `static::name(`, `$this->name(` inside `name`).
 * 2. Boot scenarios, each in its own PHP process (the plugin is a singleton,
 *    and a runaway recursion must kill a child, not the runner), with
 *    memory_limit 128M and a 2 s time limit; PHP's call-stack guard turns deep
 *    recursion into an Error. Every module on, front end and admin, with the
 *    Gift Wrap size attribute 'pa_peso' and 'peso', plus the modules' own
 *    defaults — then the static helpers read on boot. Two more boot as a REST
 *    request and under WP-CLI: `rest_api_init` fired, the settings routes and
 *    the `wp galaxie` commands registered, and the settings service they and the
 *    settings page share answering. With Gift Wrap on, the kit flow's hooks,
 *    boot data and launcher are checked, and the Kit Builder and Kit Progress
 *    widgets (and the Buy Box's controls) register and render on stubbed
 *    Elementor, live and on every editor screen.
 *
 * `--root=<dir>` points at another copy of the plugin (to prove the runner
 * catches a bug, run it on a copy that has one).
 *
 * @package Galaxie\Woo
 */

// phpcs:disable

$args  = getopt( '', array( 'root:', 'child:' ) );
$root  = rtrim( str_replace( '\\', '/', (string) ( $args['root'] ?? dirname( __DIR__, 2 ) ) ), '/' );
$child = $args['child'] ?? null;

/** The scenarios, by name: which modules are on and what settings they hold. */
function galaxie_boot_scenarios(): array {
	$gift = static fn( string $attribute ): array => array(
		'gift-wrap' => array(
			'size_attribute'     => $attribute,
			'packing_gap'        => 0,
			'allow_stacking'     => false,
			'candle_orientation' => 'lying',
			'box_categories'     => array( 125 ),
			'ribbon_categories'  => array(),
			'card_categories'    => array( 125 ),
			'card_message_max'   => 200,
			'kit_popup'          => '#pix_popup_4549',
		),
		'shipping-cartons' => array(
			'cartons'  => array(
				array( 'code' => 'N12', 'name' => 'N12', 'length' => 19, 'width' => 12, 'height' => 12, 'outer_length' => '', 'outer_width' => '', 'outer_height' => '', 'empty_weight' => 60, 'max_load' => 30000, 'active' => true ),
				// Missing an inside measure: kept in settings, never used.
				array( 'code' => 'X', 'name' => 'X', 'length' => 20, 'width' => 0, 'height' => 10, 'outer_length' => '', 'outer_width' => '', 'outer_height' => '', 'empty_weight' => 0, 'max_load' => 30000, 'active' => true ),
			),
			'margin'   => 1.5,
			'gap'      => 0,
			'density'  => 29,
			'stacking' => true,
			'fallback' => 'split',
		),
	);

	return array(
		'module defaults, front end'           => array( 'all' => false, 'admin' => false, 'settings' => array() ),
		'every module on, front end, pa_peso'  => array( 'all' => true, 'admin' => false, 'settings' => $gift( 'pa_peso' ) ),
		'every module on, front end, peso'     => array( 'all' => true, 'admin' => false, 'settings' => $gift( 'peso' ) ),
		'every module on, wp-admin, pa_peso'   => array( 'all' => true, 'admin' => true, 'settings' => $gift( 'pa_peso' ) ),
		'every module on, wp-admin, peso'      => array( 'all' => true, 'admin' => true, 'settings' => $gift( 'peso' ) ),
		'module defaults, REST + WP-CLI'       => array( 'all' => false, 'admin' => false, 'settings' => array(), 'rest' => true, 'cli' => true ),
		'every module on, REST + WP-CLI, peso' => array( 'all' => true, 'admin' => false, 'settings' => $gift( 'peso' ), 'rest' => true, 'cli' => true ),
	);
}

/**
 * The kit flow's checks for one booted scenario; '' when Gift Wrap is off.
 *
 * @param string[] $booted
 */
function galaxie_boot_kit( array $booted, array $scenario, callable $hooked ): string {
	if ( ! in_array( 'gift-wrap', $booted, true ) ) {
		return '';
	}

	$ajax = \Galaxie\Woo\Modules\GiftWrap\Kit\Ajax::class;

	foreach ( $ajax::ACTIONS as $action ) {
		foreach ( array( 'wp_ajax_', 'wp_ajax_nopriv_' ) as $prefix ) {
			if ( ! $hooked( $prefix . $ajax::PREFIX . $action, $ajax, 'dispatch' ) ) {
				throw new RuntimeException( "Kit: {$prefix}galaxie_kit_{$action} not hooked" );
			}
		}
	}

	$groups   = \Galaxie\Woo\Modules\GiftWrap\Groups::class;
	$launcher = \Galaxie\Woo\Modules\GiftWrap\Kit\Launcher::class;
	$expected = array(
		array( 'wp_loaded', $ajax, 'merge_on_load', true ),
		array( 'woocommerce_cart_item_name', $groups, 'name_with_edit', true ),
		array( 'galaxie_cart_item_after_meta', $groups, 'print_edit', true ),
		array( 'wp_footer', $launcher, 'footer', ! $scenario['admin'] ),
		array( 'wp_enqueue_scripts', $launcher, 'enqueue', ! $scenario['admin'] ),
	);

	foreach ( $expected as list( $hook, $class, $method, $wanted ) ) {
		if ( $hooked( $hook, $class, $method ) !== $wanted ) {
			throw new RuntimeException( "Kit: {$class}::{$method} on {$hook} should " . ( $wanted ? '' : 'not ' ) . 'be hooked here' );
		}
	}

	// The old builder's requests are gone.
	foreach ( array( 'galaxie_gift_builder_nonce', 'galaxie_gift_builder_data', 'galaxie_gift_builder_add' ) as $old ) {
		if ( ! empty( $GLOBALS['galaxie_boot']['hooks'][ 'wp_ajax_nopriv_' . $old ] ) ) {
			throw new RuntimeException( "Kit: the old {$old} request is still hooked" );
		}
	}

	$module = new \Galaxie\Woo\Modules\GiftWrap\Module();
	$data   = $module->boot_data()['giftWrap']['kit'] ?? null;

	if ( ! is_array( $data ) || 4549 !== $data['popup'] || array( 'room_many', 'room_one', 'full', 'box_holds', 'added' ) !== array_keys( $data['texts'] ) ) {
		throw new RuntimeException( 'Kit: boot data ' . json_encode( $data ) );
	}

	if ( 0 !== $module::parse_popup_link( 'https://shop.test/x/#pix_popup_abc' ) || 4549 !== $module::parse_popup_link( 'https://shop.test/x/#pix_popup_4549' ) ) {
		throw new RuntimeException( 'Kit: popup link parser' );
	}

	$parts = array();

	if ( ! $scenario['admin'] ) {
		ob_start();
		$launcher::footer();
		$footer = (string) ob_get_clean();

		if ( false === strpos( $footer, 'id="galaxie-kit-launcher-icon"' ) || false === strpos( $footer, '--galaxie-kit-badge-bg:var(--pix-primary)' ) || false === strpos( $footer, '.galaxie-kit-launcher[data-count]::after' ) ) {
			throw new RuntimeException( 'Kit: launcher footer ' . substr( $footer, 0, 200 ) );
		}

		$parts[] = 'launcher';
	}

	// Widgets: controls, a live render and every editor screen.
	$widgets = array(
		\Galaxie\Woo\Modules\GiftWrap\Widget\KitBuilderWidget::class  => array( 'data-galaxie-kit-builder', array( 'welcome', 'name', 'box', 'card', 'continue', 'summary' ), 'editor_screen' ),
		\Galaxie\Woo\Modules\GiftWrap\Widget\KitProgressWidget::class => array( 'data-galaxie-kit-progress', array( 'kit', 'full', 'invite' ), 'editor_state' ),
	);

	foreach ( $widgets as $class => list( $marker, $states, $key ) ) {
		$widget = new $class();
		$widget->register_for_test();

		$GLOBALS['galaxie_boot']['editing'] = false;
		$html = $widget->render_for_test();

		if ( false === strpos( $html, $marker ) ) {
			throw new RuntimeException( "Kit: {$class} rendered no {$marker}" );
		}

		// Cached pages: nothing of the visitor's kit, and no nonce.
		if ( preg_match( '/nonce|Kit 1|data-sample/i', $html ) ) {
			throw new RuntimeException( "Kit: {$class} printed visitor state or a sample on the live page" );
		}

		$GLOBALS['galaxie_boot']['editing'] = true;

		foreach ( $states as $state ) {
			$widget->settings = array( $key => $state );
			$editor           = $widget->render_for_test();

			if ( false === strpos( $editor, 'data-sample' ) ) {
				throw new RuntimeException( "Kit: {$class} editor '{$state}' is not a sample" );
			}
		}

		// The step indicator in the editor: visible on every screen with the
		// switch on (hidden attribute never), absent with it off.
		if ( 'editor_screen' === $key ) {
			foreach ( $states as $state ) {
				$widget->settings = array( $key => $state, 'steps_show' => 'yes' );
				$on               = $widget->render_for_test();
				$widget->settings = array( $key => $state, 'steps_show' => '' );
				$off              = $widget->render_for_test();

				if ( ! preg_match( '/<ol class="galaxie-kit-steps" data-kit-steps>/', $on ) || false !== strpos( $off, 'data-kit-steps' ) ) {
					throw new RuntimeException( "Kit: step indicator in the editor, screen '{$state}': shown " . ( preg_match( '/data-kit-steps>/', $on ) ? 'yes' : 'no' ) . ' with the switch on, printed ' . ( false !== strpos( $off, 'data-kit-steps' ) ? 'yes' : 'no' ) . ' with it off' );
				}
			}

			// "Continuar escolhendo": step 4 goes to the shop, the summary only closes.
			$widget->settings = array( $key => 'summary' );
			$summary          = $widget->render_for_test();

			if ( ! preg_match( '/data-kit-action="close"/', $summary ) || ! preg_match( '/galaxie-kit-screen--continue[^>]*>.*?data-kit-action="continue"/s', $summary ) || preg_match( '/galaxie-kit-screen--summary[^>]*>((?!<\/section>).)*data-kit-action="continue"/s', $summary ) ) {
				throw new RuntimeException( 'Kit: "Continuar escolhendo" should close in the summary and navigate only on step 4' );
			}

			// Live: printed, hidden until the script shows it on a step.
			$GLOBALS['galaxie_boot']['editing'] = false;
			$widget->settings                   = array();

			if ( ! preg_match( '/<ol class="galaxie-kit-steps" data-kit-steps hidden>/', $widget->render_for_test() ) ) {
				throw new RuntimeException( 'Kit: live step indicator should start hidden' );
			}
		}

		$GLOBALS['galaxie_boot']['editing'] = false;
		$widget->settings                   = array();
		$parts[]                            = ( new ReflectionClass( $class ) )->getShortName() . ' ' . count( $widget->controls ) . ' controls';
	}

	if ( in_array( 'variation-swatches', $booted, true ) ) {
		$buybox = new \Galaxie\Woo\Modules\VariationSwatches\Widget\BuyBoxWidget();
		$buybox->register_for_test();

		foreach ( array( 'giftwrap_enable', 'giftkit_start_text', 'giftkit_add_text', 'giftkit_full_text', 'giftkit_cap_text', 'giftwrap_popup_link', 'giftkit_btn_style' ) as $control ) {
			if ( ! isset( $buybox->controls[ $control ] ) ) {
				throw new RuntimeException( "Kit: Buy Box has no {$control} control" );
			}
		}

		foreach ( array( 'giftwrap_label_text', 'giftwrap_summary_confirm', 'giftwrap_btn_text', 'giftwrap_preview' ) as $control ) {
			if ( isset( $buybox->controls[ $control ] ) ) {
				throw new RuntimeException( "Kit: Buy Box still has the checkbox's {$control}" );
			}
		}

		$parts[] = 'BuyBoxWidget ' . count( $buybox->controls ) . ' controls';
	}

	return implode( ', ', $parts );
}

// ------------------------------------------------------------------ child

if ( null !== $child ) {
	set_time_limit( 2 );

	$scenario = galaxie_boot_scenarios()[ $child ] ?? null;

	if ( ! $scenario ) {
		fwrite( STDERR, "unknown scenario {$child}\n" );
		exit( 2 );
	}

	register_shutdown_function(
		static function () {
			$error = error_get_last();

			if ( $error && in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE ), true ) ) {
				fwrite( STDERR, "fatal: {$error['message']} in {$error['file']}:{$error['line']}\n" );
			}
		}
	);

	require __DIR__ . '/wp-stubs.php';

	$GLOBALS['galaxie_boot']['admin'] = $scenario['admin'];

	// Under WP-CLI the plugin adds its commands while booting.
	if ( ! empty( $scenario['cli'] ) && ! defined( 'WP_CLI' ) ) {
		define( 'WP_CLI', true );
	}

	try {
		require $root . '/galaxie-bundle.php';

		// Every module on: the registry reads the enabled map, so it needs the
		// module ids, which it only knows once the plugin has registered them.
		// A throwaway registry gives the ids without booting anything.
		if ( $scenario['all'] ) {
			$registry = new \Galaxie\Woo\Core\ModuleRegistry( new \Galaxie\Woo\Core\Settings() );
			$plugin   = new ReflectionClass( \Galaxie\Woo\Core\Plugin::class );
			$method   = $plugin->getMethod( 'register_modules' );
			$property = $plugin->getProperty( 'modules' );
			$probe    = $plugin->newInstanceWithoutConstructor();

			$property->setValue( $probe, $registry );
			$method->invoke( $probe );

			update_option( 'galaxie_woo_modules', array_fill_keys( array_keys( $registry->all() ), true ) );
		}

		update_option( 'galaxie_woo_settings', $scenario['settings'] );

		// The plugin boots on plugins_loaded.
		galaxie_boot_fire( 'plugins_loaded' );

		$modules = \Galaxie\Woo\Core\Plugin::instance()->modules();
		$booted  = array_keys( $modules->enabled() );

		// The static helpers modules read while booting and on every request.
		if ( class_exists( \Galaxie\Woo\Modules\GiftWrap\Module::class ) ) {
			$gift = \Galaxie\Woo\Modules\GiftWrap\Module::class;
			$gift::size_attribute();
			$gift::packing_options();

			foreach ( array( 'box', 'card', 'ribbon' ) as $kind ) {
				$gift::categories( $kind );
			}
		}

		// The attribute the variation and size term fields were built with, at
		// boot — before init, when WooCommerce's pa_* taxonomies do not exist yet.
		$field_classes = array(
			\Galaxie\Woo\Modules\GiftWrap\BoxFields::class,
			\Galaxie\Woo\Modules\GiftWrap\CandleFields::class,
			\Galaxie\Woo\Modules\GiftWrap\SizeTermFields::class,
		);
		$fields        = array();

		foreach ( $GLOBALS['galaxie_boot']['hooks'] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback ) && is_object( $callback[0] ) && in_array( get_class( $callback[0] ), $field_classes, true ) ) {
					$property = new ReflectionProperty( $callback[0], 'attribute' );
					$fields[ ( new ReflectionClass( $callback[0] ) )->getShortName() ] = $property->getValue( $callback[0] );
				}
			}
		}

		$attribute = isset( $gift ) ? $gift::size_attribute() : null;

		// A store whose global attribute is Peso: whatever was typed, the
		// helper and the fields must name the taxonomy.
		if ( in_array( 'gift-wrap', $booted, true ) ) {
			$wanted = 'pa_peso';

			if ( $attribute !== $wanted ) {
				throw new RuntimeException( "Module::size_attribute() is '{$attribute}', expected '{$wanted}'" );
			}

			// Not a check that passes by finding nothing: every field class hooked in.
			foreach ( array( 'BoxFields', 'CandleFields', 'SizeTermFields' ) as $class ) {
				if ( ! isset( $fields[ $class ] ) ) {
					throw new RuntimeException( "{$class} was not registered on boot" );
				}
			}

			foreach ( $fields as $class => $value ) {
				if ( $value !== $wanted ) {
					throw new RuntimeException( "{$class} was built with '{$value}', expected '{$wanted}'" );
				}
			}

			// The size term form hooks on the taxonomy itself, not the typed name.
			if ( empty( $GLOBALS['galaxie_boot']['hooks']['pa_peso_edit_form_fields'] ) || ! empty( $GLOBALS['galaxie_boot']['hooks']['peso_edit_form_fields'] ) ) {
				throw new RuntimeException( 'SizeTermFields: term form not hooked on pa_peso' );
			}
		}

		$hooked = static function ( string $hook, string $class, string $method ): bool {
			foreach ( $GLOBALS['galaxie_boot']['hooks'][ $hook ] ?? array() as $callback ) {
				if ( is_array( $callback ) && $class === ( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0] ) && $method === $callback[1] ) {
					return true;
				}
			}
			return false;
		};

		// The kit flow (PR #21): its requests and hooks, the store config it
		// prints, the launcher, and both kit widgets registering their controls
		// and rendering, live (no kit state, no nonce in the HTML) and in the
		// editor on every screen; plus the Buy Box's kit button controls.
		$kit = galaxie_boot_kit( $booted, $scenario, $hooked );

		// Shipping Cartons: booted whenever every module is on, its hooks where
		// they belong, and what it reads on every HTTP request safe on boot.
		$shipping = '';

		if ( $scenario['all'] && ! in_array( 'shipping-cartons', $booted, true ) ) {
			throw new RuntimeException( 'Shipping Cartons did not boot with every module on' );
		}

		if ( in_array( 'shipping-cartons', $booted, true ) ) {
			$expected = array(
				array( 'http_request_args', \Galaxie\Woo\Modules\ShippingCartons\Rewriter::class, 'filter', true ),
				array( 'woocommerce_order_get_items', \Galaxie\Woo\Modules\ShippingCartons\Context::class, 'record', true ),
				array( 'woocommerce_checkout_order_processed', \Galaxie\Woo\Modules\ShippingCartons\Context::class, 'enter_checkout', true ),
				array( 'add_meta_boxes', \Galaxie\Woo\Modules\ShippingCartons\OrderBox::class, 'meta_box', $scenario['admin'] ),
				array( 'admin_notices', \Galaxie\Woo\Modules\ShippingCartons\Module::class, 'notices', $scenario['admin'] ),
			);

			foreach ( $expected as list( $hook, $class, $method, $wanted ) ) {
				if ( $hooked( $hook, $class, $method ) !== $wanted ) {
					throw new RuntimeException( "Shipping Cartons: {$class}::{$method} on {$hook} should " . ( $wanted ? '' : 'not ' ) . 'be hooked here' );
				}
			}

			$module  = \Galaxie\Woo\Modules\ShippingCartons\Module::class;
			$cartons = $module::cartons();
			$module::packing_options();

			if ( array( 'N12' ) !== array_column( $cartons, 'code' ) ) {
				throw new RuntimeException( 'Shipping Cartons: cartons() read ' . json_encode( array_column( $cartons, 'code' ) ) . ', expected ["N12"]' );
			}

			// Not a quote: untouched. A quote whose products WooCommerce does not
			// know (the stubs know none): fails open, body unchanged.
			$rewriter = \Galaxie\Woo\Modules\ShippingCartons\Rewriter::class;
			$other    = array( 'method' => 'POST', 'body' => '{"products":[{"id":1,"quantity":1}]}' );
			$quote    = $other + array();

			if ( $rewriter::filter( $other, 'https://example.test/wp-json/' ) !== $other ) {
				throw new RuntimeException( 'Shipping Cartons: a non-quote request was changed' );
			}

			if ( $rewriter::filter( $quote, 'https://api.melhorenvio.com/v2/me/shipment/calculate' ) !== $quote ) {
				throw new RuntimeException( 'Shipping Cartons: an unknown-product quote was changed instead of kept' );
			}

			$shipping = count( $cartons ) . ' carton, rewrite fails open';
		}

		// The settings API: routes on rest_api_init, the WP-CLI commands, and the
		// service the settings page, the routes and the commands all save through.
		$api = '';

		if ( ! empty( $scenario['rest'] ) ) {
			galaxie_boot_fire( 'rest_api_init' );

			$routes  = $GLOBALS['galaxie_boot']['routes'] ?? array();
			$missing = array_diff(
				array( 'galaxie-woo/v1/modules', 'galaxie-woo/v1/modules/(?P<id>[a-z0-9-]+)', 'galaxie-woo/v1/settings/(?P<module>[a-z0-9-]+)' ),
				$routes
			);

			if ( $missing ) {
				throw new RuntimeException( 'REST: not registered on rest_api_init: ' . implode( ', ', $missing ) );
			}

			$service = \Galaxie\Woo\Core\Plugin::instance()->settings_service();

			if ( count( $service->module_rows() ) !== count( $modules->all() ) ) {
				throw new RuntimeException( 'REST: GET /modules does not list every module' );
			}

			// GET /settings/{module} on a booted plugin, for the modules whose rows it writes.
			foreach ( array( 'shipping-cartons', 'gift-wrap', 'quantity-discounts' ) as $id ) {
				$configurable = $service->configurable( $id );

				if ( null === $configurable ) {
					throw new RuntimeException( "REST: {$id} has no settings" );
				}

				$service->values( $configurable );
				$service->schema( $configurable );
			}

			$api = count( $routes ) . ' REST routes';
		}

		if ( ! empty( $scenario['cli'] ) ) {
			$commands = $GLOBALS['galaxie_boot']['cli'] ?? array();

			foreach ( array( 'galaxie module' => \Galaxie\Woo\Core\Cli\ModuleCommand::class, 'galaxie settings' => \Galaxie\Woo\Core\Cli\SettingsCommand::class ) as $name => $class ) {
				if ( ! isset( $commands[ $name ] ) || ! $commands[ $name ] instanceof $class ) {
					throw new RuntimeException( "WP-CLI: '{$name}' not added as {$class}" );
				}
			}

			$api .= ( '' !== $api ? ', ' : '' ) . count( $commands ) . ' WP-CLI commands';
		}

		echo json_encode( array( 'booted' => $booted, 'attribute' => $attribute, 'fields' => $fields, 'shipping' => $shipping, 'api' => $api, 'kit' => $kit ) );
		exit( 0 );
	} catch ( \Throwable $e ) {
		fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" );
		exit( 1 );
	}
}

// ------------------------------------------------------------ orchestrator

$failed = 0;
$passed = 0;

$report = static function ( bool $ok, string $name, string $detail = '' ) use ( &$failed, &$passed ): void {
	if ( $ok ) {
		++$passed;
		echo "  ok    {$name}" . ( '' !== $detail ? "  ({$detail})" : '' ) . "\n";
		return;
	}

	++$failed;
	echo "  FAIL  {$name}\n" . ( '' !== $detail ? '        ' . str_replace( "\n", "\n        ", trim( $detail ) ) . "\n" : '' );
};

// 1. Methods whose first statement calls themselves.
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
$self  = array();

foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}

	$tokens = array_values( array_filter( token_get_all( (string) file_get_contents( $file->getPathname() ) ), static fn( $t ): bool => ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] || ! isset( $tokens[ $i + 1 ] ) || ! is_array( $tokens[ $i + 1 ] ) || T_STRING !== $tokens[ $i + 1 ][0] ) {
			continue;
		}

		$name = $tokens[ $i + 1 ][1];
		$line = $tokens[ $i + 1 ][2];

		// Find the body's opening brace (an abstract or interface method has none).
		for ( $j = $i + 2; $j < $count && '{' !== $tokens[ $j ] && ';' !== $tokens[ $j ]; $j++ );

		if ( $j >= $count || ';' === $tokens[ $j ] ) {
			continue;
		}

		// The first statement: up to its ';' (or a nested block's opening brace).
		$statement = array();
		for ( $k = $j + 1; $k < $count && ';' !== $tokens[ $k ] && '{' !== $tokens[ $k ] && '}' !== $tokens[ $k ]; $k++ ) {
			$statement[] = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
		}

		$code = implode( '', $statement );

		if ( preg_match( '/(?:\bself::|\bstatic::|\$this->)' . preg_quote( $name, '/' ) . '\(/', $code ) ) {
			$self[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ":{$line} {$name}() starts with {$code}";
		}
	}
}

$report( ! $self, 'no method calls itself as its first statement', $self ? implode( "\n", $self ) : '' );

// 2. Boot every scenario in its own process.
$php = PHP_BINARY;

foreach ( array_keys( galaxie_boot_scenarios() ) as $name ) {
	$command = sprintf(
		'%s -d memory_limit=128M -d zend.max_allowed_stack_size=0 -d display_errors=stderr %s --child=%s --root=%s',
		escapeshellarg( $php ),
		escapeshellarg( __FILE__ ),
		escapeshellarg( $name ),
		escapeshellarg( $root )
	);

	$pipes   = array();
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$out     = stream_get_contents( $pipes[1] );
	$err     = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $process );

	$result = json_decode( (string) $out, true );
	$ok     = 0 === $code && is_array( $result );
	$detail = $ok
		? count( $result['booted'] ) . ' modules booted'
			. ( null !== $result['attribute'] ? ", size attribute {$result['attribute']}" : '' )
			. ( ! empty( $result['fields'] ) ? ', fields ' . implode( ' ', array_map( static fn( $class, $value ): string => "{$class}={$value}", array_keys( $result['fields'] ), $result['fields'] ) ) : '' )
			. ( ! empty( $result['shipping'] ) ? ", shipping cartons: {$result['shipping']}" : '' )
			. ( ! empty( $result['api'] ) ? ", {$result['api']}" : '' )
			. ( ! empty( $result['kit'] ) ? ", kit: {$result['kit']}" : '' )
		: "exit {$code}\n" . trim( $err . "\n" . substr( (string) $out, 0, 500 ) );

	$report( $ok, "boot: {$name}", $detail );
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );

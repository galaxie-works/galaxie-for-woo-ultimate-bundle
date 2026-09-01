<?php
/**
 * Plugin Name:       Galaxie for WooCommerce — Ultimate Bundle
 * Description:       Modular WooCommerce storefront UX bundle (checkout, my account, cart, wishlist and more) shipped as toggleable modules and Elementor widgets. Theme-independent.
 * Version:           0.1.0
 * Author:            Galaxie Works
 * Text Domain:       galaxie-woo
 * Requires at least: 6.4
 * Requires PHP:      8.1
 *
 * @package Galaxie\Woo
 */

defined( 'ABSPATH' ) || exit;

define( 'GALAXIE_WOO_VERSION', '0.1.0' );
define( 'GALAXIE_WOO_FILE', __FILE__ );
define( 'GALAXIE_WOO_DIR', plugin_dir_path( __FILE__ ) );
define( 'GALAXIE_WOO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal PSR-4 autoloader for the `Galaxie\Woo\` namespace mapped to `src/`.
 *
 * Deliberately hand-rolled (no Composer autoloader) so the plugin can be
 * deployed as a plain folder — `hosting_deployWordpressPlugin` uploads the
 * directory as-is, with no `composer install` step on the server.
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Galaxie\\Woo\\';
		$len    = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}
		$relative = substr( $class, $len );
		$path     = GALAXIE_WOO_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Galaxie for WooCommerce — Ultimate Bundle requires WooCommerce to be installed and active.', 'galaxie-woo' );
					echo '</p></div>';
				}
			);
			return;
		}

		\Galaxie\Woo\Core\Plugin::instance()->boot();
	}
);

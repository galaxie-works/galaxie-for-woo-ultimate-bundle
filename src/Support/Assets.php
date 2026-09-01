<?php
/**
 * Enqueues the built React island bundle.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One shared bundle (`assets/dist/galaxie.js` + `.css`, built from `frontend/`)
 * powers every island. Enqueued on demand — a widget calls {@see Assets::enqueue()}
 * from its render() so the bundle only loads on pages that actually use a Galaxie
 * island. Cache-busted by file mtime.
 */
final class Assets {

	public const HANDLE = 'galaxie-woo';

	private static bool $module_filter_added = false;

	public static function enqueue(): void {
		$dir = GALAXIE_WOO_DIR . 'assets/dist/';
		$url = GALAXIE_WOO_URL . 'assets/dist/';

		if ( is_readable( $dir . 'galaxie.css' ) && ! wp_style_is( self::HANDLE, 'enqueued' ) ) {
			wp_enqueue_style( self::HANDLE, $url . 'galaxie.css', array(), (string) filemtime( $dir . 'galaxie.css' ) );
		}

		if ( is_readable( $dir . 'galaxie.js' ) && ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			wp_enqueue_script( self::HANDLE, $url . 'galaxie.js', array(), (string) filemtime( $dir . 'galaxie.js' ), true );

			// The bundle is an ES module — tag it so browsers load it as one.
			if ( ! self::$module_filter_added ) {
				add_filter( 'script_loader_tag', array( self::class, 'as_module_tag' ), 10, 3 );
				self::$module_filter_added = true;
			}
		}
	}

	public static function as_module_tag( string $tag, string $handle, string $src ): string {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		return sprintf( '<script type="module" src="%s"></script>' . "\n", esc_url( $src ) );
	}
}

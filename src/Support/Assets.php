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
 * powers every island and global script. Cache-busted by file mtime.
 *
 * Where it loads: widgets call {@see Assets::enqueue()} from render(), but
 * several modules also enqueue it on `wp_enqueue_scripts` for every page
 * (Cart, ProductData, ToastNotices, VariationSpotlight, Wishlist, and the kit
 * through Kit\Launcher), so in practice galaxie.css and galaxie.js are on every
 * storefront page. That is why the entry is kept small: galaxie.js is boot code
 * plus a ~33 KB core chunk; each React island (and React itself), the account
 * screens' scripts and the phone field load as chunks only when their markup
 * is on the page (frontend/src/main.tsx, runtime.ts).
 */
final class Assets {

	public const HANDLE = 'galaxie-woo';

	/**
	 * The kit flow's own entry (`galaxie-kit.js`): the kit store, launcher badge,
	 * progress widget, Buy Box kit button and cart links, with the builder as a
	 * chunk it loads when the kit popup opens. Loaded on every storefront page
	 * once the kit popup is set (Modules\GiftWrap\Kit\Launcher); `galaxie.js`
	 * holds none of it. No CSS of its own.
	 */
	public const KIT_HANDLE = 'galaxie-woo-kit';

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

	public static function enqueue_kit(): void {
		$dir = GALAXIE_WOO_DIR . 'assets/dist/';
		$url = GALAXIE_WOO_URL . 'assets/dist/';

		if ( ! is_readable( $dir . 'galaxie-kit.js' ) || wp_script_is( self::KIT_HANDLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script( self::KIT_HANDLE, $url . 'galaxie-kit.js', array(), (string) filemtime( $dir . 'galaxie-kit.js' ), true );

		if ( ! self::$module_filter_added ) {
			add_filter( 'script_loader_tag', array( self::class, 'as_module_tag' ), 10, 3 );
			self::$module_filter_added = true;
		}
	}

	/**
	 * Marks the bundle's own <script> tag as an ES module.
	 *
	 * Only the `type` of that one tag changes: its id, its attributes and any
	 * inline script WordPress printed before or after it (wp_add_inline_script,
	 * translations) are kept as they were.
	 */
	public static function as_module_tag( string $tag, string $handle, string $src ): string {
		if ( self::HANDLE !== $handle && self::KIT_HANDLE !== $handle ) {
			return $tag;
		}

		$to_module = static function ( array $match ): string {
			$open = (string) preg_replace( '/\stype\s*=\s*(["\'])[^"\']*\1/i', '', $match[0] );

			return (string) preg_replace( '/^<script\b/i', '<script type="module"', $open, 1 );
		};

		// The tag WordPress gives an id of "{handle}-js"; inline ones get "-js-before"/"-js-after".
		$id    = preg_quote( $handle . '-js', '/' );
		$count = 0;
		$out   = preg_replace_callback( '/<script\b(?=[^>]*\sid\s*=\s*(["\'])' . $id . '\1)[^>]*>/i', $to_module, $tag, 1, $count );

		if ( ! $count ) {
			// No id (an older WordPress, or a filter removed it): find the tag by its src.
			$url = preg_quote( esc_url( $src ), '/' );
			$out = preg_replace_callback( '/<script\b(?=[^>]*\ssrc\s*=\s*(["\'])' . $url . '\1)[^>]*>/i', $to_module, $tag, 1, $count );
		}

		return $count && is_string( $out ) ? $out : $tag;
	}
}

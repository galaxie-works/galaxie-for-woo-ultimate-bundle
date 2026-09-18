<?php
/**
 * The kit popup's pixfort launcher: a gift icon and a candle count.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Kit;

use Galaxie\Woo\Modules\GiftWrap\Module;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * pixfort prints the launcher itself, in the footer, for a popup with
 * "Launcher" on (`includes/post-types/popup.php`):
 *
 *     <a id="pix_launcher_{id}" class="pix-popup-launcher … overflow-hidden rounded-circle"
 *        data-id="{id}"><span class="pix-launcher-main">{svg}</span>
 *        <span class="pix-launcher-close d-none">{svg}</span></a>
 *
 * The icon is a file of pixfort's own small set, chosen in the popup, and the
 * launcher stays `d-none` until pixfort's dialog script styles it.
 *
 * What this adds, on every storefront page, with nothing about the draft in the
 * HTML (pages are cached): the gift icon as a `<template>` and the badge
 * colours as CSS variables. kit-launcher.ts swaps the icon into
 * `.pix-launcher-main` and keeps `data-count` on the launcher from the kit
 * endpoint, whenever the launcher shows up — before or after the script runs.
 * The badge itself is CSS (`::after`), so the popup's own Custom CSS can still
 * restyle it.
 */
final class Launcher {

	public static function hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
		add_action( 'wp_footer', array( self::class, 'footer' ), 5 );
	}

	/**
	 * The badge, printed here rather than in `galaxie.css`: the pages that only
	 * have the launcher load the kit entry alone, with no stylesheet. The popup's
	 * own Custom CSS may still restyle `.galaxie-kit-launcher[data-count]::after`.
	 */
	private const BADGE_CSS = '.pix-popup-launcher.galaxie-kit-launcher{overflow:visible!important}.pix-popup-launcher.galaxie-kit-launcher .pix-launcher-main svg{--pf-icon-color:currentColor;--pf-icon-stroke-width:1.75px}.pix-popup-launcher.galaxie-kit-launcher[data-count]::after{content:attr(data-count);position:absolute;top:-4px;right:-4px;display:flex;align-items:center;justify-content:center;min-width:var(--galaxie-kit-badge-size,20px);height:var(--galaxie-kit-badge-size,20px);padding:0 5px;box-sizing:border-box;border-radius:999px;background:var(--galaxie-kit-badge-bg,var(--pix-primary,#e11d48));color:var(--galaxie-kit-badge-color,#fff);font-size:calc(var(--galaxie-kit-badge-size,20px) * .6);font-weight:700;line-height:1;box-shadow:0 0 0 2px var(--galaxie-kit-badge-ring,#fff);pointer-events:none}.pix-popup-launcher.galaxie-kit-launcher.has-kit:not([data-count])::after{content:"";position:absolute;top:-2px;right:-2px;width:10px;height:10px;border-radius:999px;background:var(--galaxie-kit-badge-bg,var(--pix-primary,#e11d48));box-shadow:0 0 0 2px var(--galaxie-kit-badge-ring,#fff);pointer-events:none}';

	/**
	 * The launcher is on every page, so the kit entry that drives it is too
	 * (small; the builder is a chunk it loads when the popup opens).
	 */
	public static function enqueue(): void {
		if ( Module::kit_popup_id() ) {
			Assets::enqueue_kit();
		}
	}

	public static function footer(): void {
		if ( ! Module::kit_popup_id() ) {
			return;
		}

		$vars = array(
			'--galaxie-kit-badge-bg'    => self::colour( (string) Module::setting( 'kit_badge_bg' ) ),
			'--galaxie-kit-badge-color' => self::colour( (string) Module::setting( 'kit_badge_color' ) ),
			'--galaxie-kit-badge-size'  => max( 10, min( 48, (int) Module::setting( 'kit_badge_size' ) ) ) . 'px',
		);

		$css = '';

		foreach ( $vars as $name => $value ) {
			if ( '' !== $value ) {
				$css .= $name . ':' . $value . ';';
			}
		}

		echo '<style id="galaxie-kit-launcher">' . ( '' !== $css ? ':root{' . esc_html( $css ) . '}' : '' ) . self::BADGE_CSS . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a constant; the variables are escaped.

		if ( Module::setting( 'kit_launcher_icon' ) ) {
			echo '<template id="galaxie-kit-launcher-icon">' . self::icon() . '</template>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's SVG or our own constant.
		}
	}

	/**
	 * A CSS colour from the setting: a pixfort palette name (`primary`,
	 * `dark-opacity-5`) becomes its variable; `#hex`, `rgb()`, `hsl()` pass
	 * through the same whitelist the widgets use; anything else is ''.
	 */
	public static function colour( string $value ): string {
		$value = trim( $value );

		if ( preg_match( '/^[a-z][a-z0-9-]*$/', $value ) ) {
			return 'var(--pix-' . $value . ')';
		}

		if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) || preg_match( '/^(?:rgb|rgba|hsl|hsla)\([0-9.,%\s]+\)$/', $value ) ) {
			return PixfortControls::css_colour( $value );
		}

		return '';
	}

	/** The chosen pixfort icon at launcher size, or a plain gift outline. */
	private static function icon(): string {
		$name = trim( (string) Module::setting( 'kit_launcher_icon_name' ) );

		if ( '' !== $name && class_exists( '\PixfortCore' ) && isset( \PixfortCore::instance()->icons ) && method_exists( \PixfortCore::instance()->icons, 'getIcon' ) ) {
			$svg = (string) \PixfortCore::instance()->icons->getIcon( $name, 30, 'galaxie-kit-launcher-icon' );

			if ( '' !== trim( $svg ) ) {
				return $svg;
			}
		}

		return '<svg class="galaxie-kit-launcher-icon" xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C9.5 3 12 8 12 8s2.5-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>';
	}
}

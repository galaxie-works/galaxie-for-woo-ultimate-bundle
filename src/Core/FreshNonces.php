<?php
/**
 * Fresh nonces for pages served from a cache.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

defined( 'ABSPATH' ) || exit;

/**
 * `window.__GALAXIE_WOO__` is printed into every storefront page, and the
 * product and home pages are kept by LiteSpeed and the CDN for days. Its
 * nonces (Buy Box, wish list, spotlight, cart, checkout, account…) last 12 to
 * 24 hours, so a page older than that sends requests WordPress refuses: the
 * shopper's "Adicionar ao carrinho" silently does nothing.
 *
 * This admin-ajax action — public, uncached — answers with the same boot data
 * the page would carry if it were rendered now, reduced to its nonces (every
 * string under a key ending in "nonce", with the keys above it), plus when it
 * was made. The script (frontend/src/lib/wp.ts) writes them into the page's
 * config in place and sends a refused request once more.
 *
 * Same context as the check: the nonces are made inside admin-ajax with the
 * visitor's own cookies, exactly where `check_ajax_referer()` /
 * `wp_verify_nonce()` will verify them — the user (0 for a visitor) and the
 * session token match by construction.
 *
 * Nothing secret is handed out: a nonce only proves the request came from a
 * page of this site for this visitor, and a cross-site page cannot read this
 * answer (admin-ajax sends no CORS headers).
 */
final class FreshNonces {

	public const ACTION = 'galaxie_fresh_nonces';

	public static function hooks(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( self::class, 'ajax' ) );
	}

	public static function ajax(): void {
		self::no_cache();

		wp_send_json_success(
			array(
				'nonces'      => self::nonces_only( Plugin::instance()->boot_data() ),
				'generatedAt' => time(),
			)
		);
	}

	/** Neither the browser, LiteSpeed nor the CDN may keep this answer. */
	public static function no_cache(): void {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		// LiteSpeed Cache's own switch for this response.
		do_action( 'litespeed_control_set_nocache', 'galaxie fresh nonces' );

		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			header( 'CDN-Cache-Control: no-store' );
		}
	}

	/**
	 * The nonces of a boot data tree: string leaves under a key ending in
	 * "nonce" (any case: `nonce`, `intentNonce`), with the keys that lead to
	 * them. Everything else is left out — it was rendered for this request
	 * (admin-ajax, no product, no page), not for the page asking.
	 *
	 * @param array<mixed> $data
	 * @return array<string,mixed>
	 */
	public static function nonces_only( array $data ): array {
		$out = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$inner = self::nonces_only( $value );
				if ( array() !== $inner ) {
					$out[ (string) $key ] = $inner;
				}
			} elseif ( is_string( $value ) && is_string( $key ) && 1 === preg_match( '/nonce$/i', $key ) ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}
}

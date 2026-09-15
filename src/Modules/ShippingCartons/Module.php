<?php
/**
 * Shipping Cartons module — Melhor Envio quotes with the store's real cartons.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ShippingCartons;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Support\CartonQuote;

defined( 'ABSPATH' ) || exit;

/**
 * The Melhor Envio plugin quotes every cart and order line as its own product
 * and has no filter to change that. This module packs those lines into the
 * cartons the merchant really ships in — with filler around them and the
 * carton's own weight — and sends Melhor Envio one product per carton instead
 * ({@see Rewriter}). Gift boxes count as one rigid item holding their candles
 * and cards. The order screen shows the cartons computed for the order
 * ({@see OrderBox}), so the parcel packed is the parcel quoted.
 *
 * Settings: the carton list, the filler margin and density, stacking, and what
 * to do when nothing holds the whole order.
 */
final class Module implements ModuleContract, ProvidesSettings {

	public const ID = 'shipping-cartons';

	/** WooCommerce → Status → Logs source. */
	public const LOG_SOURCE = 'galaxie-shipping-cartons';

	/** Most cartons the settings keep: every carton is another packing search. */
	private const MAX_CARTONS = 12;

	/** Default wall-clock limit for one packing, in ms (filter `galaxie_shipping_cartons_time_limit_ms`). */
	private const TIME_LIMIT_MS = 300;

	/** Empty rows offered under the saved cartons. */
	private const BLANK_ROWS = 3;

	/** Largest carton side accepted, in cm. */
	private const MAX_SIDE = 200;

	/** Defaults for every setting, read through {@see self::setting()}. */
	private const DEFAULTS = array(
		'cartons'  => array(),
		'margin'   => 1.5,
		'gap'      => 0,
		'density'  => 29,
		'stacking' => true,
		'fallback' => 'split',
	);

	private const FALLBACKS = array( 'split', 'original' );

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Shipping Cartons (Caixas de envio)', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Melhor Envio quotes and labels use your real shipping cartons: the order is packed into the smallest carton that holds it, with filler, and quoted at the carton\'s outside size and total weight. Shows the cartons on each order.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function boot(): void {
		Context::hooks();
		Rewriter::hooks();

		if ( is_admin() ) {
			OrderBox::hooks();
		}
	}

	/**
	 * One saved value, or its default.
	 *
	 * @return mixed
	 */
	public static function setting( string $key ) {
		$values = Plugin::instance()->settings()->module_settings( self::ID );

		return $values[ $key ] ?? ( self::DEFAULTS[ $key ] ?? null );
	}

	/**
	 * Active cartons with a usable inside size, as the packer reads them.
	 *
	 * @return array<int, array{code:string, name:string, length:float, width:float, height:float, outer_length:float, outer_width:float, outer_height:float, empty_weight:int, max_load:int}>
	 */
	public static function cartons(): array {
		$out = array();

		foreach ( (array) self::setting( 'cartons' ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['active'] ) || '' === (string) ( $row['code'] ?? '' ) ) {
				continue;
			}

			$carton = array(
				'code'         => (string) $row['code'],
				'name'         => '' !== (string) ( $row['name'] ?? '' ) ? (string) $row['name'] : (string) $row['code'],
				'length'       => (float) ( $row['length'] ?? 0 ),
				'width'        => (float) ( $row['width'] ?? 0 ),
				'height'       => (float) ( $row['height'] ?? 0 ),
				'empty_weight' => max( 0, (int) ( $row['empty_weight'] ?? 0 ) ),
				'max_load'     => max( 0, (int) ( $row['max_load'] ?? 0 ) ),
			);

			if ( $carton['length'] <= 0 || $carton['width'] <= 0 || $carton['height'] <= 0 ) {
				continue;
			}

			foreach ( array( 'length', 'width', 'height' ) as $axis ) {
				$carton[ 'outer_' . $axis ] = CartonQuote::outer( $row, $axis );
			}

			$out[] = $carton;

			if ( count( $out ) >= self::MAX_CARTONS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The options every packing question is asked with.
	 *
	 * @return array{margin:float, gap:float, density:float, stacking:bool, fallback:string, time_limit:float}
	 */
	public static function packing_options(): array {
		$fallback = (string) self::setting( 'fallback' );

		return array(
			'margin'     => (float) self::setting( 'margin' ),
			'gap'        => (float) self::setting( 'gap' ),
			'density'    => (float) self::setting( 'density' ),
			'stacking'   => (bool) self::setting( 'stacking' ),
			'fallback'   => in_array( $fallback, self::FALLBACKS, true ) ? $fallback : self::DEFAULTS['fallback'],
			/**
			 * Wall-clock milliseconds one packing may take before the original
			 * quote is kept. Each quote packs once per PHP request (shared by the
			 * plugin's insured and uninsured calls).
			 *
			 * @param int $ms Default 300.
			 */
			'time_limit' => max( 1.0, (float) apply_filters( 'galaxie_shipping_cartons_time_limit_ms', self::TIME_LIMIT_MS ) ),
		);
	}

	// -------------------------------------------------------------- settings

	public function settings_tab_label(): string {
		return __( 'Caixas de envio', 'galaxie-woo' );
	}

	public function settings_fields(): array {
		return array(
			new Field(
				key: 'margin',
				label: __( 'Filler margin (cm)', 'galaxie-woo' ),
				type: Field::TYPE_NUMBER,
				description: __( 'Room kept between the contents and every carton wall for loose fill. 1.5 cm takes 3 cm off each inside measure. From 0 to 5.', 'galaxie-woo' ),
				default: self::DEFAULTS['margin'],
				step: '0.1',
				min: '0',
				max: '5'
			),
			new Field(
				key: 'gap',
				label: __( 'Gap between items (cm)', 'galaxie-woo' ),
				type: Field::TYPE_NUMBER,
				description: __( 'Extra room between items inside the carton. 0: they touch, the filler margin surrounds the group. From 0 to 5.', 'galaxie-woo' ),
				default: self::DEFAULTS['gap'],
				step: '0.1',
				min: '0',
				max: '5'
			),
			new Field(
				key: 'density',
				label: __( 'Filler density (g/L)', 'galaxie-woo' ),
				type: Field::TYPE_NUMBER,
				description: __( 'Weight of a litre of loose fill, added for the space the items leave empty. Biodegradable loose fill: 83 L weigh 2.4 kg ≈ 29 g/L.', 'galaxie-woo' ),
				default: self::DEFAULTS['density'],
				step: '1',
				min: '0',
				max: '500'
			),
			new Field(
				key: 'stacking',
				label: __( 'Stacking allowed', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Items may be stacked in layers inside a carton (candles and gift boxes on top of each other). Off: one layer.', 'galaxie-woo' ),
				default: self::DEFAULTS['stacking']
			),
			new Field(
				key: 'fallback',
				label: __( 'When no carton holds the whole order', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'Split: several cartons, each quoted as its own volume (Melhor Envio hides Correios rates for 2 or more volumes). Original: quote the way the Melhor Envio plugin does without this module.', 'galaxie-woo' ),
				default: self::DEFAULTS['fallback'],
				options: array(
					'split'    => __( 'Split across cartons', 'galaxie-woo' ),
					'original' => __( 'Send the original request', 'galaxie-woo' ),
				)
			),
		);
	}

	public function render_extra_settings( array $values ): void {
		$rows = array_values( array_filter( (array) ( $values['cartons'] ?? array() ), 'is_array' ) );

		for ( $i = 0; $i < self::BLANK_ROWS && count( $rows ) < self::MAX_CARTONS; $i++ ) {
			$rows[] = array( 'active' => true );
		}

		echo '<h2>' . esc_html__( 'Cartons', 'galaxie-woo' ) . '</h2>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Every carton you ship in. Inside measures are what the items must fit in; outside measures are what Melhor Envio is quoted (left empty: inside + 0.6 cm). Leave the code empty to remove a row. Save to get more empty rows.', 'galaxie-woo' )
		);

		$number = static function ( string $name, $value, string $step, string $placeholder = '' ): string {
			return sprintf(
				'<input type="number" name="%1$s" value="%2$s" step="%3$s" min="0" placeholder="%4$s" style="width:5.5em" />',
				esc_attr( $name ),
				esc_attr( is_numeric( $value ) && (float) $value > 0 ? (string) $value : '' ),
				esc_attr( $step ),
				esc_attr( $placeholder )
			);
		};

		echo '<div style="overflow-x:auto"><table class="widefat striped" style="max-width:none"><thead><tr>';

		foreach (
			array(
				__( 'Code', 'galaxie-woo' ),
				__( 'Name', 'galaxie-woo' ),
				__( 'Inside L × W × H (cm)', 'galaxie-woo' ),
				__( 'Outside L × W × H (cm)', 'galaxie-woo' ),
				__( 'Empty weight (g)', 'galaxie-woo' ),
				__( 'Max load (g)', 'galaxie-woo' ),
				__( 'Active', 'galaxie-woo' ),
			) as $heading
		) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $i => $row ) {
			$base = 'fields[cartons][' . (int) $i . ']';

			echo '<tr>';
			printf( '<td><input type="text" name="%1$s" value="%2$s" maxlength="20" style="width:6em" placeholder="N12" /></td>', esc_attr( $base . '[code]' ), esc_attr( (string) ( $row['code'] ?? '' ) ) );
			printf( '<td><input type="text" name="%1$s" value="%2$s" maxlength="60" style="width:10em" /></td>', esc_attr( $base . '[name]' ), esc_attr( (string) ( $row['name'] ?? '' ) ) );

			echo '<td style="white-space:nowrap">';
			foreach ( array( 'length', 'width', 'height' ) as $axis ) {
				echo $number( $base . '[' . $axis . ']', $row[ $axis ] ?? '', '0.01' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $number.
			}
			echo '</td><td style="white-space:nowrap">';
			foreach ( array( 'length', 'width', 'height' ) as $axis ) {
				echo $number( $base . '[outer_' . $axis . ']', $row[ 'outer_' . $axis ] ?? '', '0.01', __( 'auto', 'galaxie-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $number.
			}
			echo '</td>';

			echo '<td>' . $number( $base . '[empty_weight]', $row['empty_weight'] ?? '', '1' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $number.
			echo '<td>' . $number( $base . '[max_load]', $row['max_load'] ?? '', '1', '30000' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $number.
			printf( '<td><input type="checkbox" name="%1$s" value="1" %2$s /></td>', esc_attr( $base . '[active]' ), checked( ! empty( $row['active'] ), true, false ) );
			echo '</tr>';
		}

		echo '</tbody></table></div>';

		echo '<h2>' . esc_html__( 'Carrier limits', 'galaxie-woo' ) . '</h2>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Minimum outside sizes per carrier (Melhor Envio services, September 2026): Correios PAC/SEDEX 13 × 8 × 0.4 cm, at most 100 cm per side, 200 cm summed and 30 kg; Correios Mini Envios at most 24 × 16 × 4 cm and 0.3 kg; Loggi Coleta/Ponto 15 × 10 cm; Loggi Express 0.3 kg; LATAM Cargo 10 × 10 × 10 cm; Azul Cargo 15 × 15 × 15 cm; Jadlog, J&T, Buslog and Total Express 1 cm. A carton smaller than a carrier\'s minimum hides that carrier\'s rate for the orders packed in it.', 'galaxie-woo' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Products are measured by their WooCommerce shipping dimensions and weight (Products → edit → Shipping, or each variation). A product without them keeps the Melhor Envio plugin\'s own quote. What happened to each quote is logged in WooCommerce → Status → Logs, source "galaxie-shipping-cartons".', 'galaxie-woo' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'After saving: WooCommerce\'s cached rates are cleared, but the Melhor Envio plugin keeps each shopper\'s quotes in their session for up to 15 minutes, keyed by the cart\'s products and not by these cartons. A shopper who already quoted the same cart may see the old price until then; change the cart (or wait) to test a new setting.', 'galaxie-woo' )
		);
	}

	public function sanitize_settings( array $submitted, array $current ): array {
		$values = array_merge( $current, Field::sanitize_all( $this->settings_fields(), $submitted ) );

		$values['margin']   = round( min( 5.0, max( 0.0, (float) $values['margin'] ) ), 2 );
		$values['gap']      = round( min( 5.0, max( 0.0, (float) $values['gap'] ) ), 2 );
		$values['density']  = round( min( 500.0, max( 0.0, (float) $values['density'] ) ), 1 );
		$values['fallback'] = in_array( $values['fallback'] ?? '', self::FALLBACKS, true ) ? $values['fallback'] : self::DEFAULTS['fallback'];
		$values['cartons']  = self::sanitize_cartons( $submitted['cartons'] ?? array() );

		// Cached rates were worked out with the old cartons: WooCommerce keys its
		// per-package rate cache on this version, so bumping it re-quotes.
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}

		// The Melhor Envio plugin also keeps quotes in the PHP session for 900 s,
		// keyed by its own products (not the cartons). Only the saving user's
		// session can be reached from here; shoppers' expire on their own.
		if ( PHP_SESSION_ACTIVE === session_status() && isset( $_SESSION['quotation-melhor-envio'] ) ) {
			unset( $_SESSION['quotation-melhor-envio'] );
		}

		return $values;
	}

	/**
	 * The carton rows as saved: codes unique, measures in range, empty rows dropped.
	 *
	 * An outside measure left empty (or smaller than the inside) is stored empty
	 * and worked out when read, so it follows later changes to the inside.
	 *
	 * @param mixed $raw Submitted rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitize_cartons( $raw ): array {
		$out  = array();
		$seen = array();

		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( ! is_array( $row ) || count( $out ) >= self::MAX_CARTONS ) {
				continue;
			}

			$code = substr( trim( (string) preg_replace( '/[^A-Za-z0-9 _.-]+/', '', sanitize_text_field( (string) ( $row['code'] ?? '' ) ) ) ), 0, 20 );

			if ( '' === $code ) {
				continue;
			}

			$unique = $code;
			for ( $n = 2; isset( $seen[ strtolower( $unique ) ] ); $n++ ) {
				$unique = $code . '-' . $n;
			}
			$seen[ strtolower( $unique ) ] = true;

			$carton = array(
				'code' => $unique,
				'name' => mb_substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 60 ),
			);

			foreach ( array( 'length', 'width', 'height' ) as $axis ) {
				$carton[ $axis ] = self::measure( $row[ $axis ] ?? '' );
			}

			foreach ( array( 'length', 'width', 'height' ) as $axis ) {
				$outer = self::measure( $row[ 'outer_' . $axis ] ?? '' );

				$carton[ 'outer_' . $axis ] = $outer > 0 && $outer >= $carton[ $axis ] ? $outer : '';
			}

			$carton['empty_weight'] = is_numeric( $row['empty_weight'] ?? null ) ? (int) min( 10000, max( 0, round( (float) $row['empty_weight'] ) ) ) : 0;
			$carton['max_load']     = is_numeric( $row['max_load'] ?? null ) && (float) $row['max_load'] > 0 ? (int) min( 100000, round( (float) $row['max_load'] ) ) : 30000;

			// A carton missing an inside measure is kept so the merchant sees it, but never used.
			$carton['active'] = ! empty( $row['active'] ) && $carton['length'] > 0 && $carton['width'] > 0 && $carton['height'] > 0;

			$out[] = $carton;
		}

		return $out;
	}

	/** @param mixed $value A measure in cm: 0 when empty or out of range. */
	private static function measure( $value ): float {
		$value = is_numeric( $value ) ? (float) $value : 0.0;

		return $value > 0 && $value <= self::MAX_SIDE ? round( $value, 2 ) : 0.0;
	}
}

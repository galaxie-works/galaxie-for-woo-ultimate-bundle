<?php
/**
 * A pixfort icon picker that draws the library once.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Elementor;

use Elementor\Base_Data_Control;

defined( 'ABSPATH' ) || exit;

/**
 * Why this exists rather than pixfort's own `pixfort_icon_selector`.
 *
 * That control's `content_template()` prints the ENTIRE icon library inline,
 * once per control. Six of them in one panel — two buttons and four alert
 * messages — froze the editor for about a minute every time it opened. Trading
 * it for a plain dropdown fixed the freeze and broke something else: picking an
 * icon from a list of 260 names, with nothing to look at, is picking blind.
 *
 * So the markup is printed exactly once for the whole editor session, as a
 * shared map, and every instance of this control renders a grid that reads from
 * it. The cost stops multiplying by the number of fields, which was the actual
 * defect — the picker was never too big, it was too many.
 */
final class IconPicker extends Base_Data_Control {

	public const TYPE = 'galaxie_icon';

	/** The three style folders pixfort ships. Nothing else resolves. */
	private const STYLES = array( 'Line', 'Duotone', 'Solid' );

	/**
	 * Ceiling on how many icons go into the shared map.
	 *
	 * Three styles across pixfort's full list is over six thousand file reads
	 * and several megabytes inlined into the editor. Fast enough once and
	 * cached, but an unbounded build is its own kind of outage, and the search
	 * box makes a bounded list perfectly usable.
	 */
	private const LIMIT = 700;

	/**
	 * Bump to discard a cached map.
	 *
	 * Separate from the plugin version because the map can be wrong without the
	 * plugin version changing — an empty one got cached exactly that way.
	 */
	private const TRANSIENT = 'galaxie_icon_picker_map_v2';

	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function get_default_settings(): array {
		return array(
			'label_block' => true,
		);
	}

	public static function register( $controls_manager ): void {
		$controls_manager->register( new self() );
	}

	/**
	 * Elementor's own hook for a control to load what it needs.
	 *
	 * This is an INSTANCE method because `Base_Control::enqueue()` is one, and
	 * redeclaring an inherited non-static method as static is a fatal at
	 * class-load time — which took the whole site down, not just the editor,
	 * because the class loads during `plugins_loaded`. The method I collided
	 * with turned out to be exactly the one I wanted: Elementor calls it once
	 * per registered control type, in the editor, which is precisely when the
	 * map and the script are needed and never otherwise.
	 */
	public function enqueue(): void {
		// Enqueue first, THEN attach the map. wp_add_inline_script() on a handle
		// that is not registered yet returns false and drops the script without
		// a word — which is precisely how the picker came up with no icons and
		// no error to explain it.
		wp_enqueue_script(
			'galaxie-icon-picker',
			GALAXIE_WOO_URL . 'assets/editor/icon-picker.js',
			array( 'jquery', 'elementor-editor' ),
			GALAXIE_WOO_VERSION,
			true
		);

		wp_enqueue_style(
			'galaxie-icon-picker',
			GALAXIE_WOO_URL . 'assets/editor/icon-picker.css',
			array(),
			GALAXIE_WOO_VERSION
		);

		self::print_map();
	}

	/**
	 * The shared map, printed once into the editor.
	 *
	 * Cached because building it reads several hundred files. Bump TRANSIENT to
	 * discard a cached map.
	 */
	public static function print_map(): void {
		$map = get_transient( self::TRANSIENT );

		if ( ! is_array( $map ) || ! $map ) {
			$map = self::build_map();

			// An empty map is never cached. Caching one froze the picker empty
			// for a week over a bug that took a minute to fix, and a picker that
			// silently shows nothing is worse than one that rebuilds each load.
			if ( $map ) {
				set_transient( self::TRANSIENT, $map, WEEK_IN_SECONDS );
			}
		}

		// Inline on our own handle rather than Elementor's: an inline script
		// attached to a handle that is not enqueued at that moment is dropped
		// without a word, and our own handle is one we know is there.
		wp_add_inline_script(
			'galaxie-icon-picker',
			'window.galaxieIcons = ' . wp_json_encode( $map ) . ';',
			'before'
		);
	}

	/**
	 * @return array<string,array<string,string>> style => identifier => svg
	 */
	private static function build_map(): array {
		$map = array();

		if ( ! class_exists( '\PixfortCore' ) ) {
			return $map;
		}

		$names = array_slice( self::names(), 0, self::LIMIT );

		foreach ( self::STYLES as $style ) {
			foreach ( $names as $name ) {
				$id  = $style . '/pixfort-icon-' . $name;
				$svg = \PixfortCore::instance()->icons->getIcon( $id, 22, 'galaxie-icon-preview' );

				// An identifier with no SVG behind it renders nothing at all, so
				// a blank result means the name is not real in this style and it
				// simply does not go in the map — a picker must never offer a
				// choice that draws an empty box.
				if ( '' !== trim( (string) $svg ) && str_contains( (string) $svg, '<svg' ) ) {
					$map[ $style ][ $id ] = $svg;
				}
			}
		}

		return $map;
	}

	/**
	 * Every name pixfort lists, read from its own file.
	 *
	 * Read rather than curated: the whole point of drawing the library once is
	 * that we no longer have to decide for the merchant which icons matter.
	 *
	 * @return array<int,string>
	 */
	private static function names(): array {
		$path = defined( 'PIX_CORE_PLUGIN_DIR' )
			? rtrim( PIX_CORE_PLUGIN_DIR, '/' ) . '/includes/icons/pixfort-icons-list.php'
			: '';

		if ( ! $path || ! is_readable( $path ) ) {
			return array();
		}

		/*
		 * Read as text, not `include`.
		 *
		 * The file assigns `$pixfortIconsList` instead of returning it, so an
		 * include only helps if it actually runs — and pixfort includes the same
		 * file itself. Anything reaching it first with `require_once` makes our
		 * include a no-op that defines nothing, and the picker comes up empty
		 * with no error to explain it. Reading the source has no such ordering
		 * to lose: the names are quoted string literals and nothing else in the
		 * file looks like one.
		 */
		$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file on disk, not a remote request.

		// Both quote styles. pixfort's list is written with DOUBLE quotes, and
		// a pattern that only knew single ones matched nothing at all — an
		// empty picker with no error, which is the hardest kind of wrong to see.
		preg_match_all( '/[\'"]([a-z0-9][a-z0-9-]*)[\'"]/', $source, $matches );


		return array_values( array_unique( $matches[1] ?? array() ) );
	}

	/**
	 * The panel UI: style tabs, a search box, and a grid of real icons.
	 *
	 * Everything is drawn from `window.galaxieIcons`, so this template carries
	 * no icon markup of its own no matter how many times it is instantiated.
	 */
	public function content_template(): void {
		?>
		<div class="elementor-control-field elementor-control-galaxie-icon">
			<label class="elementor-control-title">{{{ data.label }}}</label>
			<div class="elementor-control-input-wrapper">
				<div class="galaxie-icon-picker">
					<div class="galaxie-icon-picker__bar">
						<# _.each( [ 'Line', 'Duotone', 'Solid' ], function( style ) { #>
							<button type="button" class="galaxie-icon-picker__style" data-style="{{ style }}">{{{ style }}}</button>
						<# }); #>
						<input type="search" class="galaxie-icon-picker__search" placeholder="<?php echo esc_attr__( 'Search…', 'galaxie-woo' ); ?>" />
					</div>

					<div class="galaxie-icon-picker__current">
						<span class="galaxie-icon-picker__preview"></span>
						<code class="galaxie-icon-picker__value">{{{ data.controlValue || '<?php echo esc_js( __( 'None', 'galaxie-woo' ) ); ?>' }}}</code>
						<button type="button" class="galaxie-icon-picker__clear"><?php echo esc_html__( 'Clear', 'galaxie-woo' ); ?></button>
					</div>

					<div class="galaxie-icon-picker__grid"></div>

					<input type="hidden" data-setting="{{ data.name }}" />
				</div>
			</div>
		</div>
		<?php
	}
}

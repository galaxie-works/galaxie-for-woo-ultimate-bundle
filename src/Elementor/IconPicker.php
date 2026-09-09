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

	private const TRANSIENT = 'galaxie_icon_picker_map';

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

	public static function hooks(): void {
		add_action( 'elementor/controls/register', array( self::class, 'register' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'elementor/editor/after_enqueue_styles', array( self::class, 'enqueue_styles' ) );
	}

	public static function enqueue(): void {
		self::print_map();

		wp_enqueue_script(
			'galaxie-icon-picker',
			GALAXIE_WOO_URL . 'assets/editor/icon-picker.js',
			array( 'jquery', 'elementor-editor' ),
			GALAXIE_WOO_VERSION,
			true
		);
	}

	public static function enqueue_styles(): void {
		wp_enqueue_style(
			'galaxie-icon-picker',
			GALAXIE_WOO_URL . 'assets/editor/icon-picker.css',
			array(),
			GALAXIE_WOO_VERSION
		);
	}

	public static function register( $controls_manager ): void {
		$controls_manager->register( new self() );
	}

	/**
	 * The shared map, printed once into the editor.
	 *
	 * Cached because building it reads a couple of hundred files. The cache is
	 * keyed on the plugin version so a release that changes the list is not
	 * served a stale map.
	 */
	public static function print_map(): void {
		$map = get_transient( self::TRANSIENT . '_' . GALAXIE_WOO_VERSION );

		if ( ! is_array( $map ) ) {
			$map = self::build_map();
			set_transient( self::TRANSIENT . '_' . GALAXIE_WOO_VERSION, $map, WEEK_IN_SECONDS );
		}

		wp_add_inline_script(
			'elementor-editor',
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

		foreach ( self::STYLES as $style ) {
			foreach ( self::names() as $name ) {
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
			? PIX_CORE_PLUGIN_DIR . '/includes/icons/pixfort-icons-list.php'
			: '';

		if ( ! $path || ! is_readable( $path ) ) {
			return array();
		}

		$list = include $path;

		// The file assigns `$pixfortIconsList` rather than returning it, so a
		// plain include gives back `1`. Fall back to reading the variable the
		// include just defined in this scope.
		if ( ! is_array( $list ) ) {
			$list = isset( $pixfortIconsList ) && is_array( $pixfortIconsList ) ? $pixfortIconsList : array();
		}

		return array_values( array_filter( $list, 'is_string' ) );
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

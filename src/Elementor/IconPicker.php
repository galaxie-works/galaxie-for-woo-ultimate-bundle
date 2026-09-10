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
 * So the markup is never inlined at all. pixfort already publishes the whole
 * library over `wp_ajax_pix_icons_data`, gzipped, and the panel fetches it once
 * per editor session; every instance of this control renders a grid that reads
 * from that one response. The cost stops multiplying by the number of fields,
 * which was the actual defect — the picker was never too big, it was too many.
 */
final class IconPicker extends Base_Data_Control {

	public const TYPE = 'galaxie_icon';

	/** The three style folders pixfort ships. Nothing else resolves. */
	private const STYLES = array( 'Line', 'Duotone', 'Solid' );

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
	 * picker is needed and never otherwise.
	 */
	public function enqueue(): void {
		self::enqueue_editor_assets();
	}

	/**
	 * The editor script, style and config — loadable without this control.
	 *
	 * Split out because the same script also replaces pixfort's own icon
	 * selector ({@see \Galaxie\Woo\Modules\PixfortIconPicker\Module}), which
	 * has to happen whether or not a widget of ours is on the page. Guarded so
	 * that both callers can ask for it and only the first does the work.
	 */
	public static function enqueue_editor_assets(): void {
		static $done = false;

		if ( $done ) {
			return;
		}

		$done = true;

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

		/*
		 * The address of pixfort's own library endpoint, and nothing else.
		 *
		 * An earlier version built its own map here: read pixfort's list file,
		 * render every name in every style, cache the lot in a transient and
		 * inline it. It worked and it was still wrong. The list is grouped by
		 * category rather than sorted, so the ceiling that kept the build from
		 * being unbounded cut it mid-category and silently took the whole
		 * commerce block with it — every cart, bag, price tag and credit card,
		 * in a picker for a shop. A bound that decides which icons exist by
		 * where they happen to sit in a file is not a bound, it is a bug with a
		 * constant in front of it.
		 *
		 * `wp_ajax_pix_icons_data` has no such ceiling, it is already gzipped,
		 * and it is the same data pixfort's own UI reads — so the picker now
		 * offers exactly the library the theme has, and this class no longer
		 * has an opinion about which icons a merchant is allowed to want.
		 */
		wp_add_inline_script(
			'galaxie-icon-picker',
			'window.galaxieIconPicker = ' . wp_json_encode(
				array(
					'url'    => admin_url( 'admin-ajax.php' ),
					'action' => 'pix_icons_data',
					'nonce'  => wp_create_nonce( 'pix_icons_data' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The panel UI: style tabs, a search box, and a grid of real icons.
	 *
	 * Everything is drawn from the fetched library, so this template carries no
	 * icon markup of its own no matter how many times it is instantiated.
	 */
	public function content_template(): void {
		?>
		<div class="elementor-control-field elementor-control-galaxie-icon">
			<label class="elementor-control-title">{{{ data.label }}}</label>
			<div class="elementor-control-input-wrapper">
				<div class="galaxie-icon-picker">
					<div class="galaxie-icon-picker__bar">
						<# _.each( <?php echo wp_json_encode( self::STYLES ); ?>, function( style ) { #>
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

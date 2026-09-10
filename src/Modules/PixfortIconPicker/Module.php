<?php
/**
 * Replaces pixfort's Elementor icon selector with one that does not build the
 * whole library on hover.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PixfortIconPicker;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Elementor\IconPicker;

defined( 'ABSPATH' ) || exit;

/**
 * Editing one pixfort widget row can take seconds per keystroke, and the cause
 * is measurable rather than vague.
 *
 * pixfort's `pixfort_icon_selector` renders an empty grid and fills it from JS.
 * Inside a repeater it is careful not to fill on render — but it binds this
 * (dist/main/elementor/main-icons-selector.js):
 *
 *     t.$el.closest( '.elementor-repeater-row-controls' ).mouseenter( function () {
 *         $( this ).unbind( 'mouseenter' );
 *         i( t.$el, t, true );          // force-populate
 *     } );
 *
 * So the grid is built when the pointer enters the ROW — which it must, to
 * reach any field in it. The Comparison Table has three icon selectors per row,
 * one per column, so hovering a row to type its title builds three grids.
 *
 * The library is 6,327 icons (2,135 Line + 2,107 Duotone + 2,085 Solid),
 * averaging 724 bytes of SVG each. One grid is roughly 6,327 nodes and 4-5 MB
 * of markup; one row is about 19,000 nodes, injected synchronously, and then
 * walked again by `.find( '.icon-item' ).addClass( 'type-hidden' )`.
 *
 * Nineteen pixfort widgets use that control, so this is not a Comparison Table
 * problem.
 *
 * The replacement keeps pixfort's own control, markup and stored values, and
 * changes only the editor view: our script registers a view for the same
 * control type, and Elementor keeps the LAST view registered per type
 * (`addControlView()` is a plain map assignment, so the later registration
 * wins and pixfort's `onReady` — where that mouseenter is bound — never runs).
 *
 * pixfort's script is deliberately NOT dequeued. Its stylesheet is injected by
 * that same webpack bundle: the grid, the tabs, the `.icon-item` sizing and
 * `--pf-icon-color` all come from it. Removing the script would take the CSS
 * with it and leave the icons unpainted, which is the exact failure this
 * project already spent a day on.
 *
 * So the only job here is to make sure our script is in the editor. If it
 * fails to load, pixfort's own view stays registered and the picker behaves
 * exactly as it does today — slow, but not broken.
 */
final class Module implements ModuleContract {

	/** pixfort's own handle, enqueued from its control's `enqueue()`. */
	private const PIXFORT_HANDLE = 'pixfort-icons-elementor-selector';

	public function id(): string {
		return 'pixfort-icon-picker';
	}

	public function title(): string {
		return __( 'Fast pixfort Icon Picker', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Replaces pixfort\'s icon selector in the Elementor panel, which builds all 6,327 icons whenever the pointer enters a repeater row.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	/**
	 * Priority 100 on the hook that fires AFTER Elementor has walked every
	 * registered control calling `enqueue()` — which is where pixfort adds its
	 * script. Dequeuing before that would dequeue nothing.
	 *
	 * Elementor's order, from core/editor/editor.php:
	 *
	 *     $controls_manager->enqueue_control_scripts();            // pixfort enqueues here
	 *     do_action( 'elementor/editor/after_enqueue_scripts' );   // we run here
	 */
	public function boot(): void {
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'replace_selector' ), 100 );
	}

	public function replace_selector(): void {
		if ( ! wp_script_is( self::PIXFORT_HANDLE, 'enqueued' ) ) {
			// pixfort is absent, or a version that names its handle something
			// else. Leaving its picker alone is the right outcome either way —
			// ours would have nothing to replace.
			return;
		}

		// pixfort's script stays enqueued on purpose — its webpack bundle is
		// what injects the selector's stylesheet, and our view fills the grid
		// that stylesheet lays out. Only the view is replaced, in JS.
		IconPicker::enqueue_editor_assets();
	}
}

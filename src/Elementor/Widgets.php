<?php
/**
 * Elementor widget category + registration, collected from enabled modules.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Elementor;

use Galaxie\Woo\Core\ModuleRegistry;
use Galaxie\Woo\Core\ProvidesElementorWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a "Galaxie" widget category and every widget declared by an enabled
 * module that implements {@see ProvidesElementorWidgets}. Both callbacks are
 * Elementor hooks, so they simply never fire when Elementor is absent.
 */
final class Widgets {

	public function __construct( private ModuleRegistry $modules ) {}

	public function hooks(): void {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );

		// Registered through a closure on purpose. Naming the class here would
		// autoload it during `plugins_loaded`, and a class that extends an
		// Elementor base has no business being loaded before Elementor is up —
		// any problem in it becomes a fatal for the whole site rather than a
		// broken control. Inside the callback, Elementor is guaranteed present.
		add_action(
			'elementor/controls/register',
			static function ( $controls_manager ) {
				IconPicker::register( $controls_manager );
			}
		);
	}

	public function register_category( $categories_manager ): void {
		$categories_manager->add_category(
			'galaxie',
			array(
				'title' => __( 'Galaxie', 'galaxie-woo' ),
				'icon'  => 'eicon-woocommerce',
			)
		);
	}

	public function register_widgets( $widgets_manager ): void {
		foreach ( $this->modules->enabled() as $module ) {
			if ( ! $module instanceof ProvidesElementorWidgets ) {
				continue;
			}
			foreach ( $module->elementor_widgets() as $widget_class ) {
				if ( class_exists( $widget_class ) ) {
					$widgets_manager->register( new $widget_class() );
				}
			}
		}
	}
}

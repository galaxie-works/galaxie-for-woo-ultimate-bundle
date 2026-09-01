<?php
/**
 * Marker interface for modules that ship Elementor widgets.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A {@see Module} that also renders storefront UI as Elementor widgets implements
 * this so {@see \Galaxie\Woo\Elementor\Widgets} can collect and register them —
 * but only while the module is enabled and Elementor is loaded.
 */
interface ProvidesElementorWidgets {

	/**
	 * Fully-qualified class names of the Elementor widgets this module provides.
	 * Each must extend `\Elementor\Widget_Base`.
	 *
	 * @return string[]
	 */
	public function elementor_widgets(): array;
}

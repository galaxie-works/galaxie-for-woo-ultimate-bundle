/**
 * Rebuilds the Galaxie Loop Carousel in the Elementor editor.
 *
 * pixfort binds its slider handler to its own widget names only
 * (functions/elementor/js/template-carousel.js), so ours would never rebuild
 * after a change. That handler does real work — in the editor it rewrites the
 * Swiper options, turns loop into rewind, drops the slides a looped Swiper
 * duplicated and rewires navigation and pagination — so this runs theirs on our
 * element instead of carrying a copy that would drift from it.
 *
 * Their script is a declared dependency of this widget, so the action exists by
 * the time an element is ready.
 */
jQuery( window ).on( 'elementor/frontend/init', function () {
	elementorFrontend.hooks.addAction(
		'frontend/element_ready/galaxie-loop-carousel.default',
		function ( $element ) {
			elementorFrontend.hooks.doAction( 'frontend/element_ready/pix-loop-carousel.default', $element );
		}
	);
} );

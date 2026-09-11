/**
 * Rebuilds the Galaxie Products Carousel in the Elementor editor.
 *
 * The same steps pixfort's own handler (functions/elementor/js/products-carousel.js)
 * takes for its carousel: destroy the Swiper instance the re-render orphaned,
 * then let pixfort's loader build it again. pixfort binds that handler to its
 * widget name, so our widget needs its own binding or the canvas never updates.
 */
jQuery( window ).on( 'elementor/frontend/init', function () {
	function rebuild( $element ) {
		var $swipers = $element.find( '.pixfort-swiper .swiper' );

		if ( ! $swipers.length ) {
			return;
		}

		$swipers.each( function ( i, elem ) {
			jQuery( elem ).removeClass( 'swiper-initialized' );

			if ( elem.swiper && typeof elem.swiper.destroy === 'function' ) {
				elem.swiper.destroy( true, true );
			}
		} );

		var tries = 0;
		var timer = setInterval( function () {
			tries++;

			if ( typeof window.pixLoadSwiper === 'function' ) {
				clearInterval( timer );
				window.pixLoadSwiper( $element );
			} else if ( tries >= 20 ) {
				clearInterval( timer );
			}
		}, 100 );
	}

	elementorFrontend.hooks.addAction( 'frontend/element_ready/galaxie-products-carousel.default', rebuild );
} );

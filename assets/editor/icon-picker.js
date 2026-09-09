/**
 * Editor behaviour for the Galaxie icon picker control.
 *
 * The icon markup itself lives in `window.galaxieIcons`, printed once per
 * editor session by IconPicker::print_map(). Nothing here builds an SVG; it
 * only shows the ones already in memory.
 *
 * The grid stays EMPTY until the merchant asks for it. Elementor renders every
 * control in an open section, so a grid that filled itself on render would put
 * two thousand icons on screen four times over in the alert section alone —
 * which is the exact shape of the problem this control was written to solve.
 * Opening one grid is a deliberate act, and only one is ever open.
 */
( function ( $ ) {
	'use strict';

	var LIMIT = 180;

	function styleMap( style ) {
		return ( window.galaxieIcons && window.galaxieIcons[ style ] ) || {};
	}

	function styleOf( value ) {
		var slash = String( value || '' ).indexOf( '/' );

		return slash === -1 ? 'Line' : String( value ).slice( 0, slash );
	}

	function labelOf( identifier ) {
		return String( identifier )
			.replace( /^[^/]+\/pixfort-icon-/, '' )
			.replace( /-/g, ' ' );
	}

	if ( ! window.elementor || ! elementor.modules || ! elementor.modules.controls ) {
		return;
	}

	var View = elementor.modules.controls.BaseData.extend( {

		onReady: function () {
			this.style = styleOf( this.getControlValue() );
			this.open = false;

			this.$grid = this.$el.find( '.galaxie-icon-picker__grid' );
			this.$search = this.$el.find( '.galaxie-icon-picker__search' );

			this.paintPreview();
			this.paintStyles();

			this.$el.on( 'click', '.galaxie-icon-picker__style', this.onStyle.bind( this ) );
			this.$el.on( 'input', '.galaxie-icon-picker__search', this.onSearch.bind( this ) );
			this.$el.on( 'focus', '.galaxie-icon-picker__search', this.onSearch.bind( this ) );
			this.$el.on( 'click', '.galaxie-icon-picker__item', this.onPick.bind( this ) );
			this.$el.on( 'click', '.galaxie-icon-picker__clear', this.onClear.bind( this ) );
			this.$el.on( 'click', '.galaxie-icon-picker__preview', this.onSearch.bind( this ) );
		},

		paintStyles: function () {
			var current = this.style;

			this.$el.find( '.galaxie-icon-picker__style' ).each( function () {
				$( this ).toggleClass( 'is-current', $( this ).data( 'style' ) === current );
			} );
		},

		paintPreview: function () {
			var value = this.getControlValue();
			var svg = value ? styleMap( styleOf( value ) )[ value ] : '';

			this.$el.find( '.galaxie-icon-picker__preview' ).html( svg || '' );
			this.$el.find( '.galaxie-icon-picker__value' ).text( value || '—' );
		},

		paintGrid: function () {
			var term = String( this.$search.val() || '' ).trim().toLowerCase();
			var map = styleMap( this.style );
			var current = this.getControlValue();
			var html = '';
			var shown = 0;
			var total = 0;

			Object.keys( map ).forEach( function ( identifier ) {
				if ( term && labelOf( identifier ).indexOf( term ) === -1 ) {
					return;
				}

				total++;

				if ( shown >= LIMIT ) {
					return;
				}

				shown++;
				html +=
					'<button type="button" class="galaxie-icon-picker__item' +
					( identifier === current ? ' is-current' : '' ) +
					'" data-icon="' + identifier + '" title="' + labelOf( identifier ) + '">' +
					map[ identifier ] +
					'</button>';
			} );

			if ( ! total ) {
				html = '<p class="galaxie-icon-picker__empty">Nada encontrado.</p>';
			} else if ( total > shown ) {
				html += '<p class="galaxie-icon-picker__more">+' + ( total - shown ) + ' — refine a busca.</p>';
			}

			this.$grid.html( html );
		},

		onSearch: function () {
			this.open = true;
			this.$el.addClass( 'is-open' );
			this.paintGrid();
		},

		onStyle: function ( event ) {
			this.style = $( event.currentTarget ).data( 'style' );
			this.paintStyles();
			this.onSearch();
		},

		onPick: function ( event ) {
			this.setValue( $( event.currentTarget ).data( 'icon' ) );
			this.paintPreview();
			this.paintGrid();
		},

		onClear: function () {
			this.setValue( '' );
			this.paintPreview();
			this.paintGrid();
		},
	} );

	elementor.addControlView( 'galaxie_icon', View );
}( jQuery ) );

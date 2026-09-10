/**
 * Editor behaviour for the Galaxie icon picker control.
 *
 * The icon markup comes from pixfort's own `pix_icons_data` endpoint, fetched
 * ONCE for the whole editor session and shared by every instance of this
 * control. Nothing here builds an SVG and nothing is inlined into the page.
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

	/*
	 * pixfort keys its response by set, and the keys are not the folder names.
	 * The folder is what an icon identifier is built from, so translate here
	 * and let the rest of the file speak only in folders.
	 */
	var SETS = {
		LINE_ICONS: 'Line',
		DUO_ICONS: 'Duotone',
		SOLID_ICONS: 'Solid',
	};

	// One request per editor session, however many controls ask for it.
	var loading = null;

	function load() {
		if ( loading ) {
			return loading;
		}

		var config = window.galaxieIconPicker;

		if ( ! config || ! config.url ) {
			loading = $.Deferred().resolve( {} ).promise();

			return loading;
		}

		loading = $.post( config.url, {
			action: config.action,
			nonce: config.nonce,
		} ).then( function ( response ) {
			var library = {};

			Object.keys( SETS ).forEach( function ( key ) {
				var style = SETS[ key ];
				var icons = ( response && response[ key ] ) || [];

				library[ style ] = {};

				icons.forEach( function ( icon ) {
					if ( icon && icon.name && icon.icon ) {
						library[ style ][ style + '/' + icon.name ] = icon.icon;
					}
				} );
			} );

			// Kept on window so a second control finds it without a round trip
			// even in the window between this resolving and the next render.
			window.galaxieIcons = library;

			return library;
		} );

		return loading;
	}

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

			this.$grid = this.$el.find( '.galaxie-icon-picker__grid' );
			this.$search = this.$el.find( '.galaxie-icon-picker__search' );

			this.paintPreview();
			this.paintStyles();

			// The preview needs the library too, but only to draw the one icon
			// already chosen — so it waits rather than forcing the fetch early.
			if ( this.getControlValue() ) {
				load().then( this.paintPreview.bind( this ) );
			}

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
			var term = String( this.$search.val() || '' ).trim().toLowerCase().replace( /-/g, ' ' );
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
			this.$el.addClass( 'is-open' );

			if ( ! this.$grid.html() ) {
				this.$grid.html( '<p class="galaxie-icon-picker__empty">Carregando…</p>' );
			}

			load().then( this.paintGrid.bind( this ) );
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

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

	/*
	 * pixfort's .svg files are NOT SVG documents — each one holds the shapes
	 * alone, a bare `<path>` with no `<svg>` around it. Injected as-is the
	 * browser draws nothing at all, which is exactly what the grid did: cells
	 * you could hover, name, and click, with nothing inside them.
	 *
	 * `PixfortIcons::loadSvgContentWithCache()` is what supplies the element,
	 * and the wrapper it writes is fixed — same class, same `viewBox`, only the
	 * size varying. This reproduces it so the panel shows what the storefront
	 * shows.
	 */
	function wrap( identifier, shapes ) {
		return (
			'<svg class="pixfort-icon" width="22" height="22" data-name="' +
			identifier +
			'" viewBox="2 2 20 20">' +
			shapes +
			'</svg>'
		);
	}

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
						var identifier = style + '/' + icon.name;

						library[ style ][ identifier ] = wrap( identifier, icon.icon );
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

	/*
	 * Registration is deferred rather than done at parse time, for two reasons.
	 *
	 * `elementor.modules.controls.BaseData` has to exist before a view can
	 * extend it, and at the moment this file runs it may not — pixfort's own
	 * selector guards the same way, which is the hint that it can happen.
	 *
	 * And ordering decides the outcome: `addControlView()` is a plain map
	 * assignment, so the LAST registration for a type wins. Overriding
	 * pixfort's selector means registering after theirs. Ours is enqueued on
	 * `elementor/editor/after_enqueue_scripts`, after Elementor has walked the
	 * controls calling `enqueue()`, so this file prints and runs later; adding
	 * the listener later then puts our handler after theirs. The delayed second
	 * pass covers pixfort's own 200ms fallback path, which registers outside
	 * the event entirely.
	 */
	function register() {
		if ( ! window.elementor || ! elementor.modules || ! elementor.modules.controls ) {
			return false;
		}

		build();

		return true;
	}

	if ( ! register() ) {
		window.addEventListener( 'elementor/init', register );
	}

	window.setTimeout( register, 400 );

	function build() {

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

	/*
	 * The same library, driving pixfort's own control.
	 *
	 * pixfort renders the markup below and fills the grid from its own script,
	 * which builds all 6,327 icons the moment the pointer enters a repeater row
	 * — three times over in a Comparison Table row. Its script is dequeued
	 * server side, so this view is the only one registered for the type, and it
	 * fills the same grid with at most LIMIT icons at a time.
	 *
	 * Nothing about the control changes on the server: same type, same markup,
	 * same `.elementor-control-icon-value` input, same stored `Style/name`
	 * value. Only the filling is different.
	 */
	var PIXFORT_STYLES = { line: 'Line', duotone: 'Duotone', solid: 'Solid' }

	var PixfortView = elementor.modules.controls.BaseData.extend( {

		onReady: function () {
			this.$value = this.$el.find( '.elementor-control-icon-value:first' )
			this.$grid = this.$el.find( '.pixfort-icons-selector-icons' )
			this.$search = this.$el.find( '.pixfort-selector-search' )

			// pixfort's markup is the contract here. If a future version
			// changes it, do nothing rather than half-render: the text input
			// alone still holds and saves a value.
			if ( ! this.$value.length || ! this.$grid.length ) {
				return;
			}

			this.style = styleOf( this.$value.val() );
			this.paintTabs();

			this.$el.on( 'click', '.pixfort-icons-filter-tab', this.onTab.bind( this ) );
			this.$el.on( 'input focus', '.pixfort-selector-search', this.onSearch.bind( this ) );
			this.$el.on( 'click', '.icon-item', this.onPick.bind( this ) );

			// Deliberately NOT on mouseenter — that is the behaviour being
			// replaced. The grid fills once, lazily, when the row is opened.
			load().then( this.paint.bind( this ) );
		},

		paintTabs: function () {
			var current = this.style;

			this.$el.find( '.pixfort-icons-filter-tab' ).each( function () {
				var style = PIXFORT_STYLES[ $( this ).data( 'type' ) ];

				$( this ).toggleClass( 'is-selected', style === current );
			} );
		},

		paint: function () {
			var term = String( this.$search.val() || '' ).trim().toLowerCase().replace( /-/g, ' ' );
			var map = styleMap( this.style );
			var current = String( this.$value.val() || '' );
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
					'<span class="icon-item' + ( identifier === current ? ' icon-selected' : '' ) +
					'" data-id="' + identifier + '" data-name="' + identifier +
					'" title="' + labelOf( identifier ) + '">' + map[ identifier ] + '</span>';
			} );

			if ( ! total ) {
				html = '<p class="galaxie-icon-picker__empty">Nada encontrado.</p>';
			} else if ( total > shown ) {
				html += '<p class="galaxie-icon-picker__more">+' + ( total - shown ) + ' — refine a busca.</p>';
			}

			this.$grid.html( html );
		},

		onTab: function ( event ) {
			event.preventDefault();
			this.style = PIXFORT_STYLES[ $( event.currentTarget ).data( 'type' ) ] || 'Line';
			this.paintTabs();
			load().then( this.paint.bind( this ) );
		},

		onSearch: function () {
			load().then( this.paint.bind( this ) );
		},

		onPick: function ( event ) {
			this.$value.val( $( event.currentTarget ).data( 'id' ) );
			this.saveValue();
			this.paint();
		},

		// pixfort's own saveValue, kept verbatim: the input is what the control
		// stores, and other pixfort code reads it.
		saveValue: function () {
			this.setValue( this.$el.find( '.elementor-control-icon-value:first' ).val() );
		},
	} );

	elementor.addControlView( 'pixfort_icon_selector', PixfortView );

	}
}( jQuery ) );

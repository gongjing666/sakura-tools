/**
 * Sakura Admin core: namespace, page context, DOM helpers, event bus, format.
 *
 * This is the only global the admin UI adds. Page controllers read their
 * bootstrap data from window.sakuraPage and never reach for jQuery.
 *
 * @package Sakura_Image_Optimizer
 */
( function ( window, document ) {
	'use strict';

	var page = window.sakuraPage || {};

	function ready( callback ) {
		if ( typeof callback !== 'function' ) {
			return;
		}
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', callback, { once: true } );
			return;
		}
		callback();
	}

	function qs( selector, context ) {
		return ( context || document ).querySelector( selector );
	}

	function qsa( selector, context ) {
		return Array.prototype.slice.call( ( context || document ).querySelectorAll( selector ) );
	}

	function on( target, type, handler, options ) {
		if ( ! target ) {
			return function () {};
		}
		target.addEventListener( type, handler, options || false );
		return function () {
			target.removeEventListener( type, handler, options || false );
		};
	}

	function delegate( container, type, selector, handler ) {
		if ( ! container ) {
			return function () {};
		}
		return on( container, type, function ( event ) {
			var target = event.target.closest ? event.target.closest( selector ) : null;
			if ( target && container.contains( target ) ) {
				handler.call( target, event, target );
			}
		} );
	}

	function closest( element, selector ) {
		return element && element.closest ? element.closest( selector ) : null;
	}

	function parseJSON( value, fallback ) {
		if ( value && typeof value === 'object' ) {
			return value;
		}
		if ( typeof value !== 'string' || value === '' ) {
			return fallback;
		}
		try {
			return JSON.parse( value );
		} catch ( error ) {
			return fallback;
		}
	}

	/**
	 * Reads a server-rendered JSON block:
	 * <script type="application/json" data-sakura-data="scan">{...}</script>
	 */
	function dataBlock( name, fallback ) {
		var node = qs( 'script[type="application/json"][data-sakura-data="' + name + '"]' );
		return node ? parseJSON( node.textContent, fallback ) : fallback;
	}

	function debounce( callback, wait ) {
		var timer = 0;
		return function () {
			var args = arguments;
			var context = this;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				callback.apply( context, args );
			}, wait || 120 );
		};
	}

	function raf( callback ) {
		return window.requestAnimationFrame ? window.requestAnimationFrame( callback ) : window.setTimeout( callback, 16 );
	}

	var formatter = {
		number: function ( value, digits ) {
			var number = Number( value );
			if ( ! isFinite( number ) ) {
				return '0';
			}
			return number.toLocaleString( 'zh-CN', {
				minimumFractionDigits: digits || 0,
				maximumFractionDigits: digits || 0
			} );
		},
		percent: function ( value, digits ) {
			var number = Number( value );
			return isFinite( number ) ? number.toFixed( digits === undefined ? 1 : digits ) + '%' : '0%';
		},
		fileSize: function ( bytes ) {
			var size = Number( bytes );
			if ( ! isFinite( size ) || size <= 0 ) {
				return '0 B';
			}
			var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
			var index = Math.min( Math.floor( Math.log( size ) / Math.log( 1024 ) ), units.length - 1 );
			var value = size / Math.pow( 1024, index );
			return ( index === 0 ? value.toFixed( 0 ) : value.toFixed( value >= 10 ? 1 : 2 ) ) + ' ' + units[ index ];
		},
		date: function ( value ) {
			if ( ! value ) {
				return '—';
			}
			var date = value instanceof Date ? value : new Date( value );
			if ( isNaN( date.getTime() ) ) {
				return String( value );
			}
			return date.toLocaleString( 'zh-CN', {
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit'
			} );
		}
	};

	var listeners = {};

	var events = {
		on: function ( name, handler ) {
			( listeners[ name ] = listeners[ name ] || [] ).push( handler );
			return function () {
				events.off( name, handler );
			};
		},
		off: function ( name, handler ) {
			var list = listeners[ name ] || [];
			listeners[ name ] = list.filter( function ( item ) {
				return item !== handler;
			} );
		},
		emit: function ( name, detail ) {
			( listeners[ name ] || [] ).slice().forEach( function ( handler ) {
				try {
					handler( detail );
				} catch ( error ) {
					window.console && window.console.error( error );
				}
			} );
			if ( typeof window.CustomEvent === 'function' ) {
				document.dispatchEvent( new window.CustomEvent( 'sakura:' + name, { detail: detail } ) );
			}
		}
	};

	function root() {
		return qs( '.sakura-admin' );
	}

	window.SakuraAdmin = {
		page: page,
		root: root,
		ready: ready,
		qs: qs,
		qsa: qsa,
		on: on,
		delegate: delegate,
		closest: closest,
		parseJSON: parseJSON,
		dataBlock: dataBlock,
		debounce: debounce,
		raf: raf,
		format: formatter,
		events: events,
		data: page.data || {},
		strings: page.strings || {}
	};
}( window, document ) );

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

	/*
	 * The page root, not merely the first .sakura-admin on the screen.
	 *
	 * Admin notices render through admin_notices, i.e. BEFORE the page markup,
	 * and their wrapper carries .sakura-admin so the media library and thumbnail
	 * screens (which have no page root) still get the design tokens. A plain
	 * qs( '.sakura-admin' ) therefore returned a one-line notice, and every page
	 * controller that checks data-sakura-page on it bailed out before binding a
	 * single handler — the sync center's buttons and chips went dead while the
	 * notice's page-level canvas rules painted a 688px band of empty space.
	 * Prefer a root that is actually a page root; fall back to any root so
	 * token-only screens keep working.
	 */
	function root() {
		return qs( '.sakura-admin[data-sakura-page]' ) || qs( '.sakura-admin' );
	}

	/*
	 * Page-change feedback.
	 *
	 * A module or tab click is a real navigation, so the browser keeps the
	 * current page on screen until WordPress has booted the next one. That
	 * dead air is the part of the wait the user notices, and nothing on the
	 * new page can fix it: the feedback has to be painted before the
	 * request goes out.
	 *
	 * base.css opts these screens out of WordPress' cross-document View
	 * Transitions (full-page cross-fade reads as mush on dense screens) and
	 * animates the new main column in instead. This is the other half: a 2px
	 * bar across the top edge, shown in the same frame as the click.
	 */
	var navBar = null;

	function navBarNode() {
		if ( ! navBar ) {
			navBar = document.createElement( 'div' );
			navBar.className = 'sakura-nav-bar';
			navBar.setAttribute( 'aria-hidden', 'true' );
			navBar.appendChild( document.createElement( 'i' ) );
		}
		if ( document.body && ! navBar.parentNode ) {
			document.body.appendChild( navBar );
		}
		return navBar;
	}

	/*
	 * Capture phase, so the bar is up before the browser starts unloading.
	 * Only plain left clicks on our own chrome count: a modified click opens
	 * a new tab and leaves this page exactly where it is.
	 */
	document.addEventListener( 'click', function ( event ) {
		if ( event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}
		var link = closest( event.target, 'a.sakura-mod, a.sakura-tab' );
		if ( ! link ) {
			return;
		}
		var href = link.getAttribute( 'href' );
		if ( ! href || href.charAt( 0 ) === '#' || link.classList.contains( 'is-disabled' ) ) {
			return;
		}
		navBarNode().classList.add( 'is-on' );
	}, true );

	// Coming back through the bfcache leaves the bar up over a page that is
	// already there; start those visits clean.
	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted && navBar ) {
			navBar.classList.remove( 'is-on' );
		}
	} );

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

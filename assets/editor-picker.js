/**
 * Editor file panel — placement, entry card, insert.
 *
 * The file manager itself is assets/userfiles.js (the user-centre script): it
 * owns loading, upload, new folder, rename, move, delete-request, search and
 * paging. This file only
 *
 *   - sizes and places the panel next to the article column,
 *   - opens / closes it from the sidebar card,
 *   - turns a picked file into Zibll's native editor markup.
 *
 * Clicking a file name opens it in the user centre. Here that would throw the
 * writer out of the page, so clicks on `[data-sufm-open-file]` are captured
 * before the manager sees them and turn into a selection instead. Double click
 * inserts.
 */
( function () {
	'use strict';

	var cfg = window.SUFM_EDITOR;

	if ( ! cfg ) {
		return;
	}

	var panel = document.getElementById( 'sufm-picker' );

	if ( ! panel ) {
		return;
	}

	var doc = document;
	var body = doc.body;
	var root = doc.documentElement;
	var t = cfg.strings || {};

	var MIN_WIDTH = Math.max( 960, Number( cfg.minWidth ) || 1360 );
	var GAP = 16;
	var EDGE = 16;
	// Width is a share of the writing column, not a fixed pixel value: wide
	// screens give the panel the whole free gutter, narrow ones keep a readable
	// ~30% and move the article right by the difference.
	var PANEL_MIN = 360;
	// On a screen wide enough to keep the article at its full width, the panel
	// owns every pixel the row leaves over. The share cap stops an ultra-wide
	// monitor from turning the picker into a full-screen file manager.
	var PANEL_SHARE = 0.55;
	// Upper bound is the user center's content width (1030px): any wider and the
	// panel stops reading as a column and starts reading as a page of its own.
	var PANEL_MAX = 1030;

	var state = {
		mode: 'gutter',
		shift: 0,
		push: 0,
		editor: null,
		picked: null,
		checked: [],
		loading: 0
	};

	var el = {
		files: panel.querySelector( '#sufm-files' ),
		picked: panel.querySelector( '[data-sufm-picked]' ),
		insert: panel.querySelector( '[data-sufm-insert]' ),
		close: panel.querySelector( '[data-sufm-close]' )
	};

	function esc( value ) {
		return String( value == null ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function human( bytes ) {
		var n = Number( bytes ) || 0;

		if ( n >= 1073741824 ) {
			var gb = n / 1073741824;

			return ( gb >= 10 ? Math.round( gb ) : Math.round( gb * 10 ) / 10 ) + ' GB';
		}

		if ( n >= 1048576 ) {
			var mb = n / 1048576;

			return ( mb >= 10 ? Math.round( mb ) : Math.round( mb * 10 ) / 10 ) + ' MB';
		}

		if ( n > 0 ) {
			return Math.max( 1, Math.round( n / 1024 ) ) + ' KB';
		}

		return '';
	}

	function api( query ) {
		return fetch( cfg.restUrl + 'picker/item?' + query, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( function ( res ) {
			return res.json().then( function ( payload ) {
				if ( ! res.ok ) {
					throw new Error( ( payload && payload.message ) || 'error' );
				}

				return payload;
			} );
		} );
	}

	/* ---------- placement ---------- */

	var wrap = null;
	var row = null;
	var layout = null;

	function findHosts() {
		if ( wrap && layout && doc.contains( wrap ) && doc.contains( layout ) ) {
			return true;
		}

		wrap = doc.querySelector( '.content-wrap' );
		layout = doc.querySelector( '.content-layout' );

		if ( ! wrap || ! layout ) {
			wrap = null;
			layout = null;

			return false;
		}

		return true;
	}

	function mount() {
		if ( ! findHosts() ) {
			return false;
		}

		// Host on <main>, never on .content-wrap: making the wrap `position:
		// relative` lifted it above the floated sidebar, so a real click landed on
		// the article wrapper instead of the trigger card living in that sidebar.
		var host = findRow() || wrap;

		if ( panel.parentNode !== host ) {
			host.appendChild( panel );
		}

		if ( 'static' === window.getComputedStyle( host ).position ) {
			host.style.position = 'relative';
		}

		return true;
	}

	/*
	 * Where the panel may stick. The theme slides the header out of view while
	 * scrolling down (`.scroll-down.nav-fixed .header.scroll-down-hide`), so this
	 * reads the live position instead of the nominal height -- a static 84px
	 * offset was leaving a blank band above the panel as soon as it stuck.
	 */
	function headerOffset() {
		var header = doc.querySelector( '.header' );

		if ( ! header ) {
			return 16;
		}

		var rect = header.getBoundingClientRect();

		if ( rect.bottom <= 0 || rect.height <= 0 ) {
			return 16;
		}

		return Math.max( 16, Math.round( rect.bottom ) + 16 );
	}

	function findRow() {
		if ( row && doc.contains( row ) ) {
			return row;
		}

		row = layout ? layout.closest( 'main' ) : null;
		row = row || ( wrap ? wrap.parentElement : null );

		return row;
	}

	function measure() {
		if ( ! findHosts() ) {
			return { width: PANEL_MIN, fit: false, shift: 0, push: 0, fallback: true };
		}

		// Read the row as if we had not moved it yet: `state` holds last round's
		// translate/margin, so subtracting them gives the theme's own geometry.
		var lrect = layout.getBoundingClientRect();
		var side = doc.querySelector( '.sidebar.show-sidebar' ) || doc.querySelector( '.sidebar' );
		var srect = side ? side.getBoundingClientRect() : null;
		var wrect = wrap.getBoundingClientRect();
		var left0 = Math.round( lrect.left ) - ( state.shift || 0 ) - ( state.push || 0 );
		var right0 = Math.round( srect ? srect.right : ( wrect.left + wrect.width ) ) - ( state.shift || 0 );
		var column = Math.round( lrect.width ) + ( state.push || 0 );

		// clientWidth, not innerWidth: once the page is scrollable the
		// scrollbar eats ~15px and a translateX based on innerWidth overflows.
		var viewport = doc.documentElement.clientWidth || window.innerWidth;

		var row = Math.max( 1, right0 - left0 );
		var rightSlack = Math.max( 0, viewport - EDGE - right0 );

		// Everything the panel may own while the row still ends on the right
		// margin: viewport minus both edges, the gap, and the row itself.
		var free = viewport - 2 * EDGE - GAP - row;

		if ( free >= PANEL_MIN ) {
			// Wide screens: the row keeps its exact widths and moves right until it
			// touches the right margin; the panel takes the rest, capped by a share
			// of the viewport.
			var cap = Math.min( PANEL_MAX, Math.max( PANEL_MIN, Math.round( viewport * PANEL_SHARE ) ) );
			var want = Math.min( free, cap );
			var shift = EDGE + want + GAP - left0;
			var slack = free - want;

			// A capped panel cannot reach the row, so centre the pair instead of
			// dropping all the leftover space on one side.
			if ( slack > 0 ) {
				shift += Math.round( slack / 2 );
			}

			return {
				width: want,
				shift: Math.max( 0, shift ),
				push: 0,
				left: Math.max( EDGE, EDGE + Math.round( Math.max( 0, slack ) / 2 ) ),
				fit: shift <= 0,
				wide: true,
				fallback: false
			};
		}

		// Narrow screens: the panel keeps its minimum width, the row slides as far
		// as it can and only the remainder is taken out of the article.
		var need = EDGE + PANEL_MIN + GAP;
		var shift = Math.max( 0, Math.min( rightSlack, need - left0 ) );
		var push = Math.max( 0, need - left0 - shift );

		return {
			width: PANEL_MIN,
			shift: shift,
			push: push,
			left: Math.max( EDGE, left0 + shift - GAP - PANEL_MIN ),
			fit: 0 === shift && 0 === push,
			wide: false,
			fallback: false
		};
	}

	function anchor() {
		if ( ! findHosts() ) {
			return 16;
		}

		var rect = wrap.getBoundingClientRect();
		var style = window.getComputedStyle( wrap );

		return rect.left + ( parseFloat( style.paddingLeft ) || 0 );
	}

	function place() {
		var mode = measure();

		if ( ! mode.fallback && layout ) {
			var rowEl = findRow();

			if ( rowEl ) {
				if ( mode.shift > 0 ) {
					rowEl.style.transform = 'translateX(' + mode.shift + 'px)';
				} else if ( rowEl.style.transform ) {
					rowEl.style.transform = '';
				}
			}

			if ( mode.push > 0 ) {
				layout.style.marginLeft = mode.push + 'px';
			} else if ( layout.style.marginLeft ) {
				layout.style.marginLeft = '';
			}

			state.shift = mode.shift;
			state.push = mode.push;
		}

		var card = doc.querySelector( '.editor-main-box' ) || layout;
		var hostRect = ( findRow() || wrap ).getBoundingClientRect();
		var lrect = layout.getBoundingClientRect();
		var crect = card ? card.getBoundingClientRect() : lrect;

		// Anchored to the article wrapper: scrolls with the page, keeps the
		// article's top edge and its full height, like the right-hand column.
		panel.style.top = Math.max( 0, Math.round( crect.top - hostRect.top ) ) + 'px';
		panel.style.left = Math.round( lrect.left - hostRect.left - GAP - mode.width ) + 'px';
		panel.style.width = mode.width + 'px';

		// Card grid: 5 columns at the 1030 cap, never fewer than 2, so the count
		// follows the panel instead of the viewport.
		var cols = Math.max( 2, Math.min( 5, Math.floor( ( mode.width - 50 ) / 190 ) ) );
		panel.style.setProperty( '--sufm-cols', cols );
		panel.classList.toggle( 'is-wide', mode.width >= 860 );

		// Docked under the header the panel cannot be taller than what the
		// viewport shows, otherwise its footer (the insert button) is unreachable.
		var room = ( doc.documentElement.clientHeight || window.innerHeight ) - headerOffset() - 16;
		state.height = Math.max( 420, Math.min( Math.round( crect.height ), room ) );
		panel.style.height = state.height + 'px';

		dock();

		return mode;
	}

	/*
	 * Sticky, emulated: the panel is absolutely positioned inside the article
	 * wrapper, so it would scroll away with the card. Pin its top under the fixed
	 * header while the article is on screen, and let it stop at the wrapper's
	 * bottom so it never floats over the footer.
	 */
	function dock() {
		if ( panel.hidden || ! findHosts() ) {
			return;
		}

		var card = doc.querySelector( '.editor-main-box' ) || layout;
		var wrect = wrap.getBoundingClientRect();
		var hostRect = ( findRow() || wrap ).getBoundingClientRect();
		var crect = card.getBoundingClientRect();
		var stick = headerOffset();
		var top = Math.max( crect.top, stick );
		var base = state.height || panel.offsetHeight || 0;

		// Bottom stop: the article wrapper ends where the footer begins, so the
		// panel first gives back height (the list scrolls) and only slides up once
		// it is down to its minimum -- either way it never covers the footer.
		var room = wrect.bottom - top - 8;
		var floor = Math.min( 300, base );

		if ( room < base ) {
			if ( room >= floor ) {
				panel.style.height = Math.round( room ) + 'px';
			} else {
				panel.style.height = Math.round( floor ) + 'px';
				top = Math.max( wrect.top + 8, wrect.bottom - floor - 8 );
			}
		} else if ( panel.style.height !== base + 'px' ) {
			panel.style.height = base + 'px';
		}

		panel.style.top = Math.round( top - hostRect.top ) + 'px';
	}

	function release() {
		body.classList.remove( 'sufm-picker--gutter', 'sufm-picker--shift' );
		panel.classList.remove( 'is-away' );

		var rowEl = findRow();

		if ( rowEl && rowEl.style.transform ) {
			rowEl.style.transform = '';
		}

		if ( layout && layout.style.marginLeft ) {
			layout.style.marginLeft = '';
		}

		state.shift = 0;
		state.push = 0;

		panel.style.top = '';
		panel.style.left = '';
		panel.style.width = '';
		panel.style.height = '';
	}

	/* ---------- open / close ---------- */

	function open( editor ) {
		if ( window.innerWidth < MIN_WIDTH ) {
			return;
		}

		state.editor = editor || state.editor;

		mount();
		panel.hidden = false;
		place();
		setExpanded( true );

		// The list may have grown while the panel was closed (the theme uploads
		// pasted images on its own), so the manager refreshes on open.
		document.dispatchEvent( new CustomEvent( 'sufm:panel-open' ) );
	}

	function close() {
		panel.hidden = true;
		state.picked = null;
		markPicked( null );
		updatePicked();
		release();
		setExpanded( false );
	}

	function toggle( editor ) {
		if ( panel.hidden ) {
			open( editor );
		} else {
			close();
		}
	}

	/* ---------- entry card ---------- */

	var trigger = null;
	var triggerState = null;

	/*
	 * Resolved lazily: the card is printed in wp_footer, and a cached node goes
	 * stale the moment anything re-renders the sidebar.
	 */
	function getTrigger() {
		if ( trigger && doc.contains( trigger ) ) {
			return trigger;
		}

		trigger = doc.getElementById( 'sufm-picker-trigger' );
		triggerState = trigger ? trigger.querySelector( '[data-sufm-trigger-state]' ) : null;

		return trigger;
	}

	function mountTrigger() {
		var node = getTrigger();

		if ( ! node ) {
			return false;
		}

		if ( window.innerWidth < MIN_WIDTH ) {
			node.hidden = true;

			return false;
		}

		var side = doc.querySelector( '.sidebar.show-sidebar' ) || doc.querySelector( '.sidebar' );

		if ( side ) {
			var first = side.firstElementChild;

			node.classList.remove( 'is-floating' );

			if ( first && first.classList.contains( 'but-average' ) ) {
				if ( node.parentNode !== side || node.previousElementSibling !== first ) {
					side.insertBefore( node, first.nextSibling );
				}
			} else if ( node.parentNode !== side || side.firstElementChild !== node ) {
				side.insertBefore( node, first || null );
			}
		} else if ( node.parentNode !== body ) {
			body.appendChild( node );
			node.classList.add( 'is-floating' );
		}

		node.hidden = false;
		setExpanded( ! panel.hidden );

		return true;
	}

	function setExpanded( on ) {
		var node = getTrigger();

		if ( ! node ) {
			return;
		}

		node.setAttribute( 'aria-expanded', on ? 'true' : 'false' );

		if ( triggerState ) {
			triggerState.textContent = on ? ( t.collapse || '收起' ) : ( t.expand || '展开' );
		}
	}

	/*
	 * Wide screens have a free gutter, so the panel can open without costing the
	 * article any width. Closing it manually there opts out until it is opened
	 * by hand again; narrow screens never auto-open.
	 */
	var AUTO_KEY = 'sufm-picker-auto';

	function autoAllowed() {
		try {
			return '0' !== window.localStorage.getItem( AUTO_KEY );
		} catch ( e ) {
			return true;
		}
	}

	function setAuto( on ) {
		try {
			window.localStorage.setItem( AUTO_KEY, on ? '1' : '0' );
		} catch ( e ) {
			// Private mode: fall back to "auto on".
		}
	}

	function autoOpen() {
		if ( ! autoAllowed() || window.innerWidth < MIN_WIDTH ) {
			return false;
		}

		if ( ! measure().fit ) {
			return false;
		}

		open( null );

		return true;
	}

	function userClose() {
		if ( 'gutter' === state.mode ) {
			setAuto( false );
		}

		close();
	}

	/* ---------- pick ---------- */

	function markPicked( node ) {
		Array.prototype.forEach.call( panel.querySelectorAll( '.is-picked' ), function ( other ) {
			other.classList.remove( 'is-picked' );
		} );

		if ( node ) {
			node.classList.add( 'is-picked' );
		}
	}

	function nodeInfo( node ) {
		if ( ! node ) {
			return null;
		}

		var name = node.querySelector( '.sufm-row-name, .sufm-grid-name' );
		var meta = node.querySelector( '.sufm-row-meta, .sufm-grid-sub' );

		return {
			id: Number( node.getAttribute( 'data-sufm-file' ) || 0 ),
			name: name ? name.textContent.trim() : '',
			meta: meta ? meta.textContent.replace( /\s+/g, ' ' ).trim() : ''
		};
	}

	function pickNode( node ) {
		var info = nodeInfo( node );

		if ( ! info || ! info.id ) {
			return;
		}

		state.picked = info;
		markPicked( node );
		updatePicked();
	}

	/*
	 * The manager owns the checkboxes (`state.selection`), but not the order the
	 * user ticked them, and `Object.keys()` on numeric ids would come back in id
	 * order anyway. Keep our own click order and reconcile it against the DOM,
	 * because every re-render rebuilds the boxes from the manager's map.
	 */
	function checkedIds() {
		var seen = {};
		var dom = [];

		Array.prototype.forEach.call( panel.querySelectorAll( '.sufm-check[data-sufm-select]' ), function ( box ) {
			var id = Number( box.getAttribute( 'data-sufm-select' ) || 0 );

			if ( ! id || ! box.checked || box.disabled || seen[ id ] ) {
				return;
			}

			seen[ id ] = true;
			dom.push( id );
		} );

		var order = [];

		state.checked.forEach( function ( id ) {
			if ( seen[ id ] && order.indexOf( id ) === -1 ) {
				order.push( id );
			}
		} );
		dom.forEach( function ( id ) {
			if ( order.indexOf( id ) === -1 ) {
				order.push( id );
			}
		} );

		return order;
	}

	function clearChecked() {
		state.checked = [];

		Array.prototype.forEach.call( panel.querySelectorAll( '.sufm-check[data-sufm-select]' ), function ( box ) {
			if ( ! box.checked ) {
				return;
			}

			box.checked = false;
			box.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	}

	function updatePicked() {
		if ( ! el.insert || ! el.picked ) {
			return;
		}

		var ids = checkedIds();

		state.checked = ids;

		if ( ids.length > 1 ) {
			el.insert.disabled = false;
			el.insert.textContent = ( t.insertMany || '插入 %d 个' ).replace( '%d', ids.length );
			el.picked.innerHTML = '<b>' + esc( ( t.picked || '已选 %d 项' ).replace( '%d', ids.length ) ) + '</b>' +
				'<small>' + esc( t.orderHint || '图片优先，按勾选顺序插入' ) + '</small>';

			return;
		}

		el.insert.textContent = t.insert || '插入';

		if ( 1 === ids.length ) {
			var node = panel.querySelector( '[data-sufm-file="' + ids[ 0 ] + '"]' );
			var one = nodeInfo( node );

			if ( one && one.id ) {
				state.picked = one;
				markPicked( node );
			}
		}

		if ( ! state.picked ) {
			el.insert.disabled = true;
			el.picked.textContent = t.pickHint || '单击选中，双击插入，也可拖到正文';

			return;
		}

		el.insert.disabled = false;
		el.picked.innerHTML = '<b>' + esc( state.picked.name ) + '</b>' +
			'<small>' + esc( state.picked.meta ) + '</small>';
	}

	/* ---------- insert ---------- */

	function toast( message ) {
		var box = doc.getElementById( 'sufm-toast' );

		if ( ! box ) {
			return;
		}

		box.textContent = message;
		box.hidden = false;

		window.setTimeout( function () {
			box.hidden = true;
		}, 2400 );
	}

	function activeEditor() {
		if ( state.editor && state.editor.insertContent ) {
			return state.editor;
		}

		if ( window.tinymce ) {
			return window.tinymce.get( cfg.editorId );
		}

		return null;
	}

	function buildHtml( item ) {
		var name = esc( item.name );
		var mime = item.mime || '';

		if ( 0 === mime.indexOf( 'image/' ) ) {
			return '<p><img src="' + esc( item.large || item.url ) + '" data-edit-file-id="' + Number( item.id ) +
				'" alt="' + name + '" data-full-url="' + esc( item.url ) + '"></p><p></p>';
		}

		if ( 0 === mime.indexOf( 'video/' ) ) {
			return '<div contenteditable="false" data-video-url="' + esc( item.url ) + '" data-video-name="' + name +
				'" data-edit-file-id="' + Number( item.id ) + '" class="new-dplayer post-dplayer dplayer"></div><p></p>';
		}

		return '<div class="mb20 muted-box file-download-box" contenteditable="false">' +
			'<div class="flex ac jsb"><div class="flex ac">' +
			'<div class="mr10"><div class="file-download-icon"><i class="fa fa-file-o"></i></div></div>' +
			'<div class="text-ellipsis-2 file-download-name" contenteditable="true">' + name + '</div>' +
			'</div><div class="flex0 ml20">' +
			'<a href="' + esc( item.url ) + '" data-mce-href="' + esc( item.url ) + '" data-download-file="' + Number( item.id ) +
			'" class="but c-blue file-download-btn"><i class="fa fa-download"></i>下载</a>' +
			'</div></div></div><p></p>';
	}

	function insert( forced, point ) {
		var ids = Array.isArray( forced ) ? forced.slice() : checkedIds();

		if ( ! ids.length && state.picked && state.picked.id ) {
			ids = [ state.picked.id ];
		}

		ids = ids.filter( function ( id ) {
			return Number( id ) > 0;
		} );

		if ( ! ids.length ) {
			return;
		}

		var editor = activeEditor();

		if ( ! editor ) {
			toast( t.insertFailed || '找不到编辑器，请点一下正文再试' );

			return;
		}

		if ( el.insert ) {
			el.insert.disabled = true;
		}

		var request = ids.length > 1 ? 'ids=' + ids.join( ',' ) : 'id=' + ids[ 0 ];

		api( request ).then( function ( payload ) {
			var list = payload && payload.items ? payload.items : [ payload ];
			var images = [];
			var others = [];

			// Images first, then everything else, each group in tick order: a
			// wall of pictures followed by the attachments reads better than a
			// mixed stream, and matches how the editor renders them.
			list.forEach( function ( item ) {
				if ( 0 === String( item.mime || '' ).indexOf( 'image/' ) ) {
					images.push( item );
				} else {
					others.push( item );
				}
			} );

			var html = images.concat( others ).map( buildHtml ).join( '' );

			/*
			 * Put the caret back inside the frame first. Without focus the command
			 * runs against a stale selection and the markup lands at the top of the
			 * document (or nowhere at all).
			 */
			var range = point ? rangeAtPoint( editor, point ) : null;

			editor.focus();

			if ( range ) {
				editor.selection.setRng( range );
			} else {
				editor.selection.collapse();
			}

			editor.execCommand( 'mceInsertContent', false, html );

			editor.selection.collapse( false );
			editor.nodeChanged();

			clearChecked();

			// A landed batch leaves no "current" file: drop the stale single
			// pick so the footer falls back to the hint instead of showing one
			// of the files we just inserted.
			state.picked = null;
			markPicked( null );

			var label = t.insert || '插入';

			if ( el.insert ) {
				el.insert.textContent = t.inserted || '已插入';

				window.setTimeout( function () {
					el.insert.textContent = label;
					el.insert.disabled = false;
					updatePicked();
				}, 1200 );
			} else {
				updatePicked();
			}

			if ( point ) {
				toast( t.inserted || '已插入' );
			}
		} ).catch( function () {
			if ( el.insert ) {
				el.insert.disabled = false;
			}

			updatePicked();
			toast( t.insertFailed || '插入失败，请重试' );
		} );
	}

	/* ---------- drag a file into the editor ---------- */

	var dragIds = [];
	var dropzone = null;

	/* TinyMCE exposes `iframe` in most builds, but some versions return null;
	 * the content-area container is the stable fallback. */
	function editorFrame( editor ) {
		if ( editor && editor.iframe ) {
			return editor.iframe;
		}

		var area = editor && editor.getContentAreaContainer ? editor.getContentAreaContainer() : null;

		if ( area ) {
			var frame = area.querySelector( 'iframe' );

			if ( frame ) {
				return frame;
			}
		}

		return doc.getElementById( cfg.editorId + '_ifr' );
	}

	function rangeAtPoint( editor, point ) {
		var frame = editorFrame( editor );

		if ( ! frame || ! frame.contentDocument || ! point ) {
			return null;
		}

		var rect = frame.getBoundingClientRect();
		var x = point.clientX - rect.left - ( frame.clientLeft || 0 );
		var y = point.clientY - rect.top - ( frame.clientTop || 0 );

		if ( x < 0 || y < 0 || x > frame.clientWidth || y > frame.clientHeight ) {
			return null;
		}

		var frameDoc = frame.contentDocument;
		var range = null;

		if ( frameDoc.caretRangeFromPoint ) {
			range = frameDoc.caretRangeFromPoint( x, y );
		} else if ( frameDoc.caretPositionFromPoint ) {
			var position = frameDoc.caretPositionFromPoint( x, y );

			if ( position && position.offsetNode ) {
				range = frameDoc.createRange();
				range.setStart( position.offsetNode, position.offset );
				range.collapse( true );
			}
		}

		return range;
	}

	function placeDropzone() {
		if ( ! dropzone || dropzone.hidden ) {
			return;
		}

		var frame = editorFrame( activeEditor() );

		if ( ! frame ) {
			hideDropzone();

			return;
		}

		var rect = frame.getBoundingClientRect();

		if ( rect.width < 1 || rect.height < 1 ) {
			hideDropzone();

			return;
		}

		dropzone.style.left = Math.round( rect.left ) + 'px';
		dropzone.style.top = Math.round( rect.top ) + 'px';
		dropzone.style.width = Math.round( rect.width ) + 'px';
		dropzone.style.height = Math.round( rect.height ) + 'px';
	}

	function hideDropzone() {
		dragIds = [];

		if ( ! dropzone ) {
			return;
		}

		dropzone.classList.remove( 'is-visible', 'is-over' );
		dropzone.hidden = true;
	}

	function buildDropzone() {
		var node = doc.createElement( 'div' );

		node.className = 'sufm-editor-drop';
		node.hidden = true;
		node.setAttribute( 'aria-hidden', 'true' );
		node.innerHTML = '<div class="sufm-editor-drop__inner">' +
			'<span class="sufm-editor-drop__icon" aria-hidden="true">' +
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/></svg>' +
			'</span>' +
			'<strong>' + esc( t.dropInsert || '松手插入到正文' ) + '</strong>' +
			'<small>' + esc( t.dropInsertHint || '图片优先，按拖入顺序插入' ) + '</small>' +
		'</div>';

		node.addEventListener( 'dragover', function ( event ) {
			if ( ! dragIds.length ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			if ( event.dataTransfer ) {
				event.dataTransfer.dropEffect = 'copy';
			}

			node.classList.add( 'is-over' );
		} );

		node.addEventListener( 'dragleave', function ( event ) {
			if ( ! node.contains( event.relatedTarget ) ) {
				node.classList.remove( 'is-over' );
			}
		} );

		node.addEventListener( 'drop', function ( event ) {
			if ( ! dragIds.length ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			var ids = dragIds.slice();
			var point = { clientX: event.clientX, clientY: event.clientY };

			hideDropzone();
			insert( ids, point );
		} );

		body.appendChild( node );

		return node;
	}

	function showDropzone( ids ) {
		dragIds = ids.filter( function ( id ) {
			return Number( id ) > 0;
		} );

		if ( ! dragIds.length ) {
			return;
		}

		var editor = activeEditor();

		if ( ! editor || ! editorFrame( editor ) ) {
			dragIds = [];

			return;
		}

		if ( ! dropzone ) {
			dropzone = buildDropzone();
		}

		dropzone.hidden = false;
		dropzone.classList.remove( 'is-visible', 'is-over' );
		placeDropzone();

		// Force a frame so the fade/scale transition can run after `hidden` is removed.
		void dropzone.offsetWidth;

		window.requestAnimationFrame( function () {
			if ( dropzone ) {
				dropzone.classList.add( 'is-visible' );
			}
		} );
	}

	document.addEventListener( 'sufm:drag-files', function ( event ) {
		var targets = event.detail && event.detail.targets ? event.detail.targets : [];

		showDropzone( targets.map( function ( target ) {
			return Number( target.id );
		} ) );
	} );

	document.addEventListener( 'sufm:drag-end', hideDropzone );

	window.addEventListener( 'scroll', placeDropzone, { passive: true } );
	window.addEventListener( 'resize', placeDropzone );

	/* ---------- events ---------- */

	/*
	 * Delegated from the document, not from `#sufm-files`: the manager replaces
	 * that node's innerHTML on every render, and binding to a cached reference
	 * made picks silently stop working. Capture phase stays necessary because
	 * the manager opens `[data-sufm-open-file]` in a new tab.
	 */
	doc.addEventListener( 'click', function ( event ) {
		// The manager's "cancel" only clears its own selection; the ticks the
		// insert bar counts live here, so clear both or the counts disagree.
		if ( event.target.closest( '#sufm-picker [data-sufm-batch="cancel"]' ) ) {
			clearChecked();
			state.picked = null;
			markPicked( null );
			updatePicked();

			return;
		}

		var row = event.target.closest( '#sufm-picker [data-sufm-file]' );

		if ( ! row || event.target.closest( '.sufm-check, .sufm-more, .sufm-menu' ) ) {
			return;
		}

		if ( event.target.closest( '[data-sufm-open-file]' ) ) {
			event.preventDefault();
			event.stopPropagation();
		}

		pickNode( row );
	}, true );

	/*
	 * Right-click fallback only. The manager already opens its own menu at the
	 * cursor for both files and folders and marks the event handled; clicking
	 * the ⋯ button here as well toggled that fresh menu straight back shut,
	 * which is why right-clicking a file appeared to do nothing.
	 */
	doc.addEventListener( 'contextmenu', function ( event ) {
		if ( event.defaultPrevented ) {
			return;
		}

		var row = event.target.closest( '#sufm-picker [data-sufm-file]' );

		if ( ! row ) {
			return;
		}

		var more = row.querySelector( '.sufm-more' );

		if ( ! more ) {
			return;
		}

		event.preventDefault();
		more.click();
	} );

	// Capture phase: the manager listens on the app root and may swallow later.
	doc.addEventListener( 'change', function ( event ) {
		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		if ( 'sufm-check-all' === target.id ) {
			state.checked = [];
			updatePicked();

			return;
		}

		var box = target.closest( '#sufm-picker [data-sufm-select]' );

		if ( ! box ) {
			return;
		}

		var id = Number( box.getAttribute( 'data-sufm-select' ) || 0 );

		if ( ! id ) {
			return;
		}

		var at = state.checked.indexOf( id );

		if ( box.checked && -1 === at ) {
			state.checked.push( id );
		} else if ( ! box.checked && -1 !== at ) {
			state.checked.splice( at, 1 );
		}

		updatePicked();
	}, true );

	doc.addEventListener( 'dblclick', function ( event ) {
		var row = event.target.closest( '#sufm-picker [data-sufm-file]' );

		if ( ! row ) {
			return;
		}

		event.preventDefault();
		pickNode( row );

		insert( [ Number( row.getAttribute( 'data-sufm-file' ) || 0 ) ] );
	} );

	if ( el.insert ) {
		el.insert.addEventListener( 'click', insert );
	}

	if ( el.close ) {
		el.close.addEventListener( 'click', userClose );
	}

	// Delegated: the card may be moved into the sidebar after this script runs.
	doc.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest( '#sufm-picker-trigger' ) ) {
			return;
		}

		event.preventDefault();

		if ( panel.hidden ) {
			setAuto( true );
			open( activeEditor() );

			return;
		}

		userClose();
	} );

	doc.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key || panel.hidden ) {
			return;
		}

		userClose();
	} );

	var mountTimer = 0;
	var scrollTick = false;
	var frameTick = false;

	window.addEventListener( 'scroll', function () {
		if ( scrollTick ) {
			return;
		}

		scrollTick = true;

		window.requestAnimationFrame( function () {
			scrollTick = false;
			dock();
		} );
	}, { passive: true } );

	/*
	 * Follow the viewport frame by frame. A 150ms debounce read as the panel
	 * lagging behind the article while the window was being dragged; the only
	 * work per frame is a few getBoundingClientRect calls.
	 */
	function follow() {
		if ( frameTick ) {
			return;
		}

		frameTick = true;

		window.requestAnimationFrame( function () {
			frameTick = false;

			if ( panel.hidden ) {
				return;
			}

			if ( window.innerWidth < MIN_WIDTH ) {
				close();

				return;
			}

			place();
		} );
	}

	window.addEventListener( 'resize', follow );
	window.addEventListener( 'orientationchange', follow );

	// Re-homing the sidebar card moves DOM, so it stays debounced.
	window.addEventListener( 'resize', function () {
		clearTimeout( mountTimer );
		mountTimer = window.setTimeout( mountTrigger, 200 );
	} );

	mountTrigger();
	updatePicked();
	autoOpen();

	window.addEventListener( 'load', function () {
		if ( ! panel.hidden ) {
			place();
		}
	} );

	/*
	 * The manager replaces the list whenever the scope changes (tab, folder,
	 * search). A file picked in the old scope is no longer on screen, so the
	 * footer selection is dropped together with it.
	 */
	document.addEventListener( 'sufm:list-reset', function () {
		clearChecked();

		if ( ! state.picked ) { return; }

		state.picked = null;
		markPicked( null );
		updatePicked();
	} );

	window.SUFMPicker = {
		toggle: toggle,
		open: open,
		close: close,
		place: place,
		autoOpen: autoOpen,
		insert: insert
	};
}() );

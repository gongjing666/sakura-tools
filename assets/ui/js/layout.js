/**
 * Admin card layout: per-user order, width and minimum height.
 *
 * Progressive enhancement: without this file the markup order and the original
 * CSS grid are used unchanged. Cards never leave their form owner; the script
 * only reorders elements inside an existing group container and remembers
 * each control's form so drag-and-drop cannot break a settings submission.
 */
( function () {
	'use strict';

	var admin = window.SakuraAdmin || null;
	var cfg = admin && admin.page && admin.page.layout ? admin.page.layout : null;
	if ( ! admin || ! cfg || ! cfg.ajaxUrl ) {
		return;
	}

	var root = admin.root() || document.body;

	var MIN_SPAN = 2;
	var MAX_SPAN = 12;
	var MIN_H = 120;
	var MAX_H = 2000;
	var desktops = window.matchMedia( '(min-width: 1101px)' );
	var state = cfg.pages && typeof cfg.pages === 'object' ? cfg.pages : {};
	var editing = false;
	var lineTimers = new WeakMap();
	var toolbar = null;
	var panel = null;
	var DEFAULT_SPANS = {
		image: { thumbs: 12, rename: 6, compress: 6, bulk: 6 },
		thumb: { switches: 6, scopes: 6, sizes: 12, advanced: 12, probe: 6, stats: 6, rollback: 6, cache: 6 },
		backup: { usage: 4, policy: 4, access: 4 },
		s3: { upload: 6, behaviour: 6, connection: 12, test: 6, migrate: 6 },
		sync: { ignored: 12, tasks: 12, health: 6, storage: 6, reconcile: 12, logs: 8, notes: 4 },
		'files-space': { space_diag: 12, space_entry: 12, space_gate: 5, space_level: 7, space_vip: 5, space_plans: 12, space_grants: 7, space_reconcile: 5 },
		'system-settings': { runtime: 6, engine: 6, checks: 12, extensions: 6, layout: 6 }
	};
	var saveTimers = {};

	/* ---------------------------------------------------------------- helpers */

	function clamp( value, min, max ) {
		return Math.min( max, Math.max( min, value ) );
	}

	function cssPx( value, fallback ) {
		var number = parseFloat( value );
		return isNaN( number ) ? fallback : number;
	}

	/**
	 * One measurement basis for every coordinate calculation.
	 *
	 * The drag preview used to measure with getBoundingClientRect().width
	 * (border + padding included) while the actual placement used
	 * clientWidth (border excluded), so a dropped card landed a few pixels
	 * away from the slot it was previewed in. Both now read this object.
	 *
	 * @param {Element} group Layout group element.
	 * @return {{gapX: number, gapY: number, width: number, track: number, left: number}}
	 */
	function metrics( group ) {
		var styles = window.getComputedStyle( group );
		var gapX = cssPx( styles.columnGap, 20 );
		var gapY = cssPx( styles.rowGap, 18 );
		var width = group.clientWidth;

		return {
			gapX: gapX,
			gapY: gapY,
			width: width,
			track: ( width - gapX * 11 ) / 12,
			left: group.getBoundingClientRect().left + ( group.clientLeft || 0 )
		};
	}

	function pageOf( group ) {
		return group.getAttribute( 'data-sakura-layout' );
	}

	function cardsOf( group ) {
		var selector = group.hasAttribute( 'data-sakura-collect' )
			? '[data-sakura-card]'
			: ':scope > [data-sakura-card]';
		return Array.prototype.slice.call( group.querySelectorAll( selector ) ).filter( function ( card ) {
			return card.closest( '[data-sakura-layout]' ) === group;
		} );
	}

	function pageState( page ) {
		if ( ! state[ page ] || typeof state[ page ] !== 'object' ) {
			state[ page ] = { groups: {} };
		}
		if ( ! state[ page ].groups || typeof state[ page ].groups !== 'object' ) {
			state[ page ].groups = {};
		}
		if ( 'undefined' === typeof state[ page ].grid ) {
			state[ page ].grid = 1;
		}
		return state[ page ];
	}

	function groupState( page, name ) {
		var pageData = pageState( page );
		if ( ! pageData.groups[ name ] ) {
			pageData.groups[ name ] = { order: [], cards: {} };
		}
		if ( ! pageData.groups[ name ].order ) {
			pageData.groups[ name ].order = [];
		}
		if ( ! pageData.groups[ name ].cards ) {
			pageData.groups[ name ].cards = {};
		}
		return pageData.groups[ name ];
	}

	/* --------------------------------------------------------------- set-up */

	root.classList.add( 'sakura-layout-enabled' );

	labelTables();

	Array.prototype.slice.call( document.querySelectorAll( '[data-sakura-layout]' ) ).forEach( setupGroup );

	function labelTables() {
		Array.prototype.forEach.call( root.querySelectorAll( 'table' ), function ( table ) {
			var heads = Array.prototype.map.call( table.querySelectorAll( 'thead th' ), function ( th ) {
				return ( th.textContent || '' ).trim();
			} );
			if ( heads.length < 2 ) {
				return;
			}
			Array.prototype.forEach.call( table.querySelectorAll( 'tbody tr' ), function ( row ) {
				Array.prototype.forEach.call( row.children, function ( cell, index ) {
					if ( heads[ index ] && ! cell.hasAttribute( 'data-label' ) ) {
						cell.setAttribute( 'data-label', heads[ index ] );
					}
				} );
			} );
		} );
	}

	function setupGroup( group ) {
		var page = pageOf( group );
		var groupName = group.getAttribute( 'data-sakura-group' ) || 'main';
		var cards = cardsOf( group );
		/* A single card still gets the toolbar: the two file tabs that only hold
		 * one table (users, log) should offer the same control as the pages with
		 * several cards, even if the only thing to change is its width. */
		if ( ! page || cards.length < 1 ) {
			return;
		}

		group.classList.add( 'sakura-layout-grid' );
		group.setAttribute( 'data-sakura-ready', '1' );

		if ( ! group.querySelector( ':scope > .sakura-layout-lines' ) ) {
			var lines = document.createElement( 'div' );
			lines.className = 'sakura-layout-lines';
			lines.setAttribute( 'aria-hidden', 'true' );
			group.appendChild( lines );

			var guide = document.createElement( 'div' );
			guide.className = 'sakura-layout-guide';
			guide.setAttribute( 'aria-hidden', 'true' );
			group.appendChild( guide );
		}

		var data = groupState( page, groupName );

		var byId = {};
		cards.forEach( function ( card ) {
			var id = card.getAttribute( 'data-sakura-card' );
			if ( ! id ) {
				return;
			}
			byId[ id ] = card;
			rememberForm( card );
		} );

		if ( group.hasAttribute( 'data-sakura-collect' ) ) {
			cards.forEach( function ( card ) {
				group.appendChild( card );
			} );
			Array.prototype.forEach.call( group.querySelectorAll( '[data-sakura-source]' ), function ( source ) {
				source.setAttribute( 'data-sakura-collected', '1' );
			} );
		}

		if ( ! group.hasAttribute( 'data-sakura-default-order' ) ) {
			var declared = cards.map( function ( card ) {
				return card.getAttribute( 'data-sakura-card' );
			} ).filter( Boolean );
			group.setAttribute( 'data-sakura-default-order', declared.join( ',' ) );
		}

		var order = ( data.order || [] ).filter( function ( id ) {
			return byId[ id ];
		} );
		// A card added after the layout was saved is missing from the stored
		// order. Appending it would bury it at the bottom of the page, so it is
		// placed right after its nearest predecessor in the declared order.
		var declared = ( group.getAttribute( 'data-sakura-default-order' ) || '' ).split( ',' );

		cards.forEach( function ( card ) {
			var id = card.getAttribute( 'data-sakura-card' );
			if ( ! id || order.indexOf( id ) !== -1 ) {
				return;
			}

			var anchor = -1;

			for ( var i = declared.indexOf( id ) - 1; i >= 0; i-- ) {
				var pos = order.indexOf( declared[ i ] );
				if ( pos !== -1 ) {
					anchor = pos;
					break;
				}
			}

			if ( anchor === -1 ) {
				order.push( id );
			} else {
				order.splice( anchor + 1, 0, id );
			}
		} );

		order.forEach( function ( id ) {
			var card = byId[ id ];
			if ( ! card ) {
				return;
			}
			group.appendChild( card );
			applyCard( card, data.cards[ id ], page, id );
			addResizeHandles( card, groupName );
			enableDrag( card, group, page, groupName );
		} );

		freeLayout( group );

		buildToolbar();
	}

	/**
	 * Record the owning form of every control before the card can be moved, so
	 * the control keeps submitting to the same form after reordering.
	 */
	function reflow() {
		Array.prototype.slice.call( document.querySelectorAll( '[data-sakura-layout]' ) ).forEach( function ( group ) {
			var page = pageOf( group );
			var groupName = group.getAttribute( 'data-sakura-group' ) || 'main';
			if ( ! page ) {
				return;
			}
			var data = groupState( page, groupName );
			cardsOf( group ).forEach( function ( card ) {
				var id = card.getAttribute( 'data-sakura-card' );
				if ( id ) {
					applyCard( card, data.cards[ id ], page, id );
				}
			} );
			freeLayout( group );
		} );
	}

	if ( admin && admin.events && admin.events.on ) {
		admin.events.on( 'layout-refresh', reflow );
	}

	if ( desktops.addEventListener ) {
		desktops.addEventListener( 'change', reflow );
	} else if ( desktops.addListener ) {
		desktops.addListener( reflow );
	}

	/* The canvas is pixel based: after any window resize the column width
	 * changes, so every card has to be measured and placed again. Without this
	 * the cards keep their old pixel width and look tiny in a wider window. */
	var resizeTimer = null;
	window.addEventListener( 'resize', function () {
		window.clearTimeout( resizeTimer );
		resizeTimer = window.setTimeout( function () {
			Array.prototype.slice.call( document.querySelectorAll( '[data-sakura-layout]' ) ).forEach( freeLayout );
		}, 120 );
	} );

	function rememberForm( card ) {
		var controls = card.querySelectorAll( 'input, select, textarea, button' );
		Array.prototype.forEach.call( controls, function ( control ) {
			var owner = control.closest( 'form' );
			if ( ! owner || card.contains( owner ) ) {
				return;
			}
			if ( ! owner.id ) {
				owner.id = 'sakura-layout-form-' + Math.random().toString( 36 ).slice( 2, 8 );
			}
			control.setAttribute( 'form', owner.id );
		} );
	}

	function applyCard( card, cardData, page, id ) {
		/* Below 1100px the board collapses to one column: drop saved widths and
		 * heights so a desktop layout cannot leave tall empty cards on a phone. */
		if ( ! desktops.matches ) {
			card.style.removeProperty( 'min-height' );
			card.style.removeProperty( '--card-span' );
			card.style.removeProperty( 'grid-column' );
			card.style.removeProperty( 'width' );
			card.style.removeProperty( 'left' );
			card.style.removeProperty( 'top' );
			card.style.removeProperty( 'height' );
			card.removeAttribute( 'data-sakura-h' );
			card.removeAttribute( 'data-sakura-resized' );
			card.parentNode.classList.remove( 'is-free' );
			return;
		}
		var fallback = ( DEFAULT_SPANS[ page ] && DEFAULT_SPANS[ page ][ id ] ) || 12;
		var span = clamp( parseInt( cardData && cardData.span, 10 ) || fallback, MIN_SPAN, MAX_SPAN );
		/* Legacy layouts stored the current card height as `minH`. Applying it
		 * here made every card a one-way ratchet: it could grow, but never shrink
		 * back to its content. Heights the user actually resized are stored in
		 * the separate `h` field; `minH` stays retired. */
		card.style.removeProperty( 'min-height' );
		var savedH = parseInt( cardData && cardData.h, 10 ) || 0;
		if ( savedH > 0 ) {
			card.setAttribute( 'data-sakura-h', String( savedH ) );
			card.setAttribute( 'data-sakura-resized', '1' );
		} else {
			card.removeAttribute( 'data-sakura-h' );
			card.removeAttribute( 'data-sakura-resized' );
		}
		/* Positions are no longer stored: the board is packed from the top, so a
		 * saved `top` could only punch a hole in it. */
		card.removeAttribute( 'data-sakura-top' );
		var col = clamp( parseInt( cardData && cardData.col, 10 ) || parseInt( card.getAttribute( 'data-sakura-col' ), 10 ) || 0, 0, 12 );
		if ( col > 0 ) {
			col = Math.min( col, 13 - span );
			card.setAttribute( 'data-sakura-col', String( col ) );
			card.style.gridColumn = col + ' / span ' + span;
		} else {
			card.removeAttribute( 'data-sakura-col' );
			card.style.gridColumn = 'span ' + span;
		}
		card.setAttribute( 'data-sakura-span', String( span ) );
	}

	/**
	 * Which 1-based column the pointer is over, clamped so the card fits.
	 */
	function columnAt( group, clientX, span ) {
		var box = metrics( group );
		if ( box.track <= 0 ) {
			return 0;
		}
		var raw = Math.floor( ( clientX - box.left ) / ( box.track + box.gapX ) ) + 1;
		return clamp( raw, 1, 13 - span );
	}

	/**
	 * Free canvas layout: every card is absolutely placed on a 12-column grid,
	 * so a card can occupy the empty space next to a shorter card without
	 * forcing its neighbours into the same row height.
	 */
	function freeLayout( group ) {
		if ( ! group || ! desktops.matches ) {
			if ( group ) {
				group.classList.remove( 'is-free', 'is-initializing', 'is-laid' );
				group.style.removeProperty( 'height' );
			}
			return;
		}

		var cards = cardsOf( group );
		if ( ! cards.length ) {
			return;
		}

		/* The first placement is not a motion: the cards are still in normal
		 * document flow until this call, so the 0.18s left/top transition would
		 * animate them from the canvas origin and read as a pile unfolding. */
		var initial = ! group.classList.contains( 'is-free' );
		if ( initial ) {
			group.classList.add( 'is-initializing' );
			window.requestAnimationFrame( function () {
				group.classList.remove( 'is-initializing' );
			} );
		}

		group.classList.add( 'is-free' );
		var box = metrics( group );
		var gapX = box.gapX;
		var gapY = box.gapY;
		var track = box.track;
		if ( track <= 0 ) {
			return;
		}

		var heights = [];
		for ( var i = 0; i < 12; i++ ) {
			heights.push( 0 );
		}
		var bottom = 0;

		cards.forEach( function ( card ) {
			var span = clamp( parseInt( card.getAttribute( 'data-sakura-span' ), 10 ) || 12, MIN_SPAN, MAX_SPAN );
			var col = parseInt( card.getAttribute( 'data-sakura-col' ), 10 ) || 0;
			if ( col < 1 ) {
				col = bestColumn( heights, span );
				card.setAttribute( 'data-sakura-col', String( col ) );
			}
			col = clamp( col, 1, 13 - span );

			var cardWidth = span * track + ( span - 1 ) * gapX;
			card.style.width = cardWidth + 'px';
			card.style.left = ( ( col - 1 ) * ( track + gapX ) ) + 'px';
			card.style.right = 'auto';
			card.style.removeProperty( 'min-height' );
			card.style.height = 'auto';
			card.style.top = '0px';

			var natural = card.offsetHeight;
			var savedH = parseFloat( card.getAttribute( 'data-sakura-h' ) ) || 0;
			/* A user-chosen height is a floor, not a box: the card may still grow
			 * when its content needs the room, so text and tables are never cut in
			 * half. The height used for placement is the one the card will render.
			 * Stale heights are removed once on the server (Layout schema), never
			 * here, or a deliberate resize would be mistaken for old data. */
			var height = Math.max( MIN_H, natural, savedH );

			var floor = 0;
			for ( var c = col - 1; c < col - 1 + span; c++ ) {
				floor = Math.max( floor, heights[ c ] || 0 );
			}
			/* Pure flow: a card packs at the tallest floor of its columns. No
			 * absolute position is stored anywhere, so the board can never keep
			 * the frozen holes an old `top` pin left behind whenever a neighbour
			 * changed height — that is what forced a reset to defaults. Order and
			 * column are the only user intent. */
			var top = floor;
			card.style.top = top + 'px';
			/* min-height instead of height: dragging the bottom grip sets how
			 * tall the card may be, without ever hiding its content. */
			card.style.height = 'auto';
			card.style.minHeight = height + 'px';
			/* No write-back of top/height on purpose: those attributes are the
			 * user's intent, not a cache of the computed position. */

			for ( c = col - 1; c < col - 1 + span; c++ ) {
				heights[ c ] = Math.max( heights[ c ] || 0, top + height + gapY );
			}
			bottom = Math.max( bottom, top + height );
		} );

		group.style.height = ( bottom + gapY ) + 'px';
		group.classList.add( 'is-laid' );
	}

	function bestColumn( heights, span ) {
		var best = 1;
		var bestTop = Infinity;
		for ( var col = 1; col <= 13 - span; col++ ) {
			var top = 0;
			for ( var c = col - 1; c < col - 1 + span; c++ ) {
				top = Math.max( top, heights[ c ] || 0 );
			}
			if ( top < bestTop ) {
				bestTop = top;
				best = col;
			}
		}
		return best;
	}

	/* ------------------------------------------------------------------ drag */

	function enableDrag( card, group, page, groupName ) {
		var head = card.querySelector( ':scope > .card__head' );
		if ( ! head ) {
			return;
		}
		head.classList.add( 'sakura-layout-drag' );
		head.setAttribute( 'title', ( cfg.strings && cfg.strings.drag ) || '拖动以调整位置' );

		head.addEventListener( 'pointerdown', function ( event ) {
			if ( ! editing || ! desktops.matches || event.button !== 0 ) {
				return;
			}
			if ( event.target.closest( 'button, a, input, select, textarea, label, summary' ) ) {
				return;
			}
			startDrag( event, card, group, page, groupName );
		} );

		head.addEventListener( 'keydown', function ( event ) {
			if ( ! editing || ! event.altKey || ( event.key !== 'ArrowUp' && event.key !== 'ArrowDown' ) ) {
				return;
			}
			event.preventDefault();
			var sibling = event.key === 'ArrowUp' ? card.previousElementSibling : card.nextElementSibling;
			if ( sibling && sibling.hasAttribute( 'data-sakura-card' ) ) {
				if ( event.key === 'ArrowUp' ) {
					group.insertBefore( card, sibling );
				} else {
					group.insertBefore( sibling, card );
				}
				persist( page );
			}
		} );
	}

	/**
	 * Where the dragged card would be inserted.
	 *
	 * The rule is the one every sortable list uses: the card goes in front of
	 * the first card in the same columns whose vertical middle sits below the
	 * pointer. Positions are read from the resting geometry (style.top), never
	 * from getBoundingClientRect, because the neighbours are being translated
	 * by the preview at this very moment.
	 *
	 * @param {Element} group Layout group.
	 * @param {Element} card  Dragged card.
	 * @param {number}  col   1-based target column.
	 * @param {number}  span  Target span.
	 * @param {number}  top   Pointer position in group coordinates.
	 * @return {number} Index in the card list with the dragged card removed.
	 */
	function insertionIndex( group, card, col, span, top ) {
		var box = metrics( group );
		var list = cardsOf( group ).filter( function ( other ) {
			return other !== card;
		} );
		var index = list.length;

		if ( box.track <= 0 ) {
			return index;
		}

		var left = ( col - 1 ) * ( box.track + box.gapX );
		var right = left + span * box.track + ( span - 1 ) * box.gapX;
		var nearest = Infinity;

		list.forEach( function ( other, i ) {
			var oLeft = parseFloat( other.style.left ) || 0;

			if ( oLeft + other.offsetWidth <= left + 0.5 || oLeft >= right - 0.5 ) {
				return;
			}

			var middle = ( parseFloat( other.style.top ) || 0 ) + other.offsetHeight / 2;

			if ( middle > top && middle < nearest ) {
				nearest = middle;
				index = i;
			}
		} );

		return index;
	}

	/**
	 * Move the dropped card to its slot in the DOM.
	 *
	 * Order is the only thing that has to be remembered: freeLayout packs every
	 * card from the top, so the neighbours below the slot simply flow down.
	 *
	 * @param {Element} group Layout group.
	 * @param {Element} card  Dropped card.
	 * @param {number}  index Slot index.
	 */
	function settle( group, card, index ) {
		var list = cardsOf( group ).filter( function ( other ) {
			return other !== card;
		} );
		var next = index >= 0 && index < list.length ? list[ index ] : null;

		if ( next ) {
			if ( next.previousElementSibling !== card ) {
				group.insertBefore( card, next );
			}
			return;
		}

		if ( group.lastElementChild !== card ) {
			group.appendChild( card );
		}
	}

	/**
	 * Run freeLayout's placement rules against a hypothetical drop.
	 *
	 * The drag preview used to be a lone dashed box drawn on top of the real
	 * cards: nothing moved until the pointer was released, so the box sat on
	 * top of the cards below it and the page looked like it had doubled. This
	 * projects the real result instead, so the neighbours can step aside while
	 * the card is still in the air and the drop never surprises anyone.
	 *
	 * @param {Element} group  Layout group.
	 * @param {Element} card   Dragged card.
	 * @param {number}  col    1-based target column.
	 * @param {number}  span   Target span.
	 * @param {number}  index  Slot index.
	 * @param {number}  height Card height while dragging.
	 * @return {{entries: Array, bottom: number}} Projected tops in flow order.
	 */
	function projectLayout( group, card, col, span, index, height ) {
		var box = metrics( group );
		var entries = [];
		var bottom = 0;

		if ( box.track <= 0 ) {
			return { entries: entries, bottom: bottom };
		}

		var gapY = box.gapY;
		var order = cardsOf( group ).filter( function ( other ) {
			return other !== card;
		} );

		order.splice( clamp( index, 0, order.length ), 0, card );

		var heights = [];
		var i;

		for ( i = 0; i < 12; i++ ) {
			heights.push( 0 );
		}

		order.forEach( function ( other ) {
			var self = other === card;
			var otherSpan = self
				? span
				: clamp( parseInt( other.getAttribute( 'data-sakura-span' ), 10 ) || 12, MIN_SPAN, MAX_SPAN );
			var otherCol = self ? col : ( parseInt( other.getAttribute( 'data-sakura-col' ), 10 ) || 0 );

			if ( otherCol < 1 ) {
				otherCol = bestColumn( heights, otherSpan );
			}

			otherCol = clamp( otherCol, 1, 13 - otherSpan );

			var savedH = parseFloat( other.getAttribute( 'data-sakura-h' ) ) || 0;
			var otherHeight = self
				? Math.max( MIN_H, height, savedH )
				: Math.max( MIN_H, other.offsetHeight, savedH );
			var floor = 0;
			var c;

			for ( c = otherCol - 1; c < otherCol - 1 + otherSpan; c++ ) {
				floor = Math.max( floor, heights[ c ] || 0 );
			}

			var place = floor;

			for ( c = otherCol - 1; c < otherCol - 1 + otherSpan; c++ ) {
				heights[ c ] = Math.max( heights[ c ] || 0, place + otherHeight + gapY );
			}

			entries.push( { card: other, top: place } );
			bottom = Math.max( bottom, place + otherHeight );
		} );

		return { entries: entries, bottom: bottom };
	}

	/**
	 * Push the neighbours aside so the preview slot is empty.
	 *
	 * Transforms move the cards without touching the style.top values the
	 * placement maths reads, so the projection cannot feed back on itself.
	 *
	 * @return {number} Projected top of the dragged card.
	 */
	function shiftCards( group, card, col, span, index, height ) {
		var box = metrics( group );
		var projected = projectLayout( group, card, col, span, index, height );
		var self = 0;

		projected.entries.forEach( function ( entry ) {
			if ( entry.card === card ) {
				self = entry.top;
				return;
			}

			var current = parseFloat( entry.card.style.top ) || 0;
			var delta = Math.round( entry.top - current );

			entry.card.style.transform = delta ? 'translateY(' + delta + 'px)' : '';
		} );

		if ( box.track > 0 ) {
			group.style.height = ( projected.bottom + box.gapY ) + 'px';
		}

		return self;
	}

	/**
	 * Undo every transform painted during a drag.
	 */
	function clearShifts( group ) {
		group.classList.remove( 'is-reflowing' );

		cardsOf( group ).forEach( function ( card ) {
			if ( card.style.transform ) {
				card.style.removeProperty( 'transform' );
			}
		} );
	}

	function startDrag( event, card, group, page, groupName ) {
		event.preventDefault();

		var startX = event.clientX;
		var startY = event.clientY;
		var lastX = startX;
		var lastY = startY;
		var moved = false;
		var ghost = null;
		var placeholder = null;
		var frame = 0;
		var pointerId = event.pointerId;
		var span = clamp( parseInt( card.getAttribute( 'data-sakura-span' ), 10 ) || 12, MIN_SPAN, MAX_SPAN );
		var cardRect = card.getBoundingClientRect();
		var offsetX = startX - cardRect.left;
		var offsetY = startY - cardRect.top;
		var dragHeight = cardRect.height;
		var dropCol = clamp( parseInt( card.getAttribute( 'data-sakura-col' ), 10 ) || 1, 1, 13 - span );
		/* Slot index = the card's own position, so a press without movement
		 * previews exactly the layout that is already on screen. */
		var dropIndex = Math.max( 0, cardsOf( group ).indexOf( card ) );
		var landTop = 0;
		var baseHeight = group.style.height;

		/* Keep receiving pointer events once the cursor leaves the window.
		 * Without capture a fast drag simply stops tracking. */
		if ( card.setPointerCapture && 'number' === typeof pointerId ) {
			try {
				card.setPointerCapture( pointerId );
			} catch ( captureError ) {}
		}

		function preview() {
			if ( ! placeholder ) {
				return;
			}

			var box = metrics( group );
			if ( box.track <= 0 ) {
				return;
			}

			placeholder.style.left = ( ( dropCol - 1 ) * ( box.track + box.gapX ) ) + 'px';
			placeholder.style.width = ( span * box.track + ( span - 1 ) * box.gapX ) + 'px';
			placeholder.style.height = dragHeight + 'px';
			markColumns( group, dropCol, span, box );

			/* Let the cards behind the drop line step aside while the card is
			 * still held, otherwise the dashed outline is painted on top of them
			 * and nothing appears to happen until the pointer is released. */
			landTop = shiftCards( group, card, dropCol, span, dropIndex, dragHeight );
			placeholder.style.top = landTop + 'px';
		}

		/* Edge auto-scroll on animation frames, proportional to how far the
		 * pointer is past the edge. The old fixed 18px every 16ms worked out to
		 * roughly 1100px per second and always overshot the target. */
		function tick() {
			frame = window.requestAnimationFrame( tick );

			var edge = 96;
			var speed = 0;

			if ( lastY < edge ) {
				speed = -Math.ceil( ( edge - lastY ) / 6 );
			} else if ( lastY > window.innerHeight - edge ) {
				speed = Math.ceil( ( lastY - ( window.innerHeight - edge ) ) / 6 );
			}

			if ( speed !== 0 ) {
				window.scrollBy( 0, speed );
			}
		}

		function move( moveEvent ) {
			lastX = moveEvent.clientX;
			lastY = moveEvent.clientY;

			/* 6px sat inside the noise of an ordinary click on the card head. */
			if ( ! moved && Math.abs( lastX - startX ) + Math.abs( lastY - startY ) < 8 ) {
				return;
			}

			if ( ! moved ) {
				moved = true;
				card.classList.add( 'is-dragging' );
				bumpLines( group, true );
				group.classList.add( 'is-reflowing' );

				placeholder = document.createElement( 'div' );
				placeholder.className = 'sakura-layout-drop';
				group.appendChild( placeholder );

				ghost = card.cloneNode( true );
				/* The clone inherits is-dragging, whose rule (0,2,0) beats
				 * .sakura-layout-ghost (0,1,0) — the card that follows the pointer
				 * was therefore painted at 30% opacity. */
				ghost.classList.remove( 'is-dragging' );
				ghost.classList.add( 'sakura-layout-ghost' );
				ghost.style.width = cardRect.width + 'px';
				root.appendChild( ghost );

				frame = window.requestAnimationFrame( tick );
				preview();
			}

			ghost.style.left = ( lastX - offsetX + 10 ) + 'px';
			ghost.style.top = ( lastY - offsetY + 10 ) + 'px';

			var col = columnAt( group, lastX, span );

			if ( col > 0 ) {
				dropCol = col;
			}

			var groupTop = group.getBoundingClientRect().top;
			var cursorTop = Math.max( 0, Math.round( lastY - offsetY - groupTop ) );

			dropIndex = insertionIndex( group, card, dropCol, span, cursorTop );

			preview();
		}

		function stop() {
			document.removeEventListener( 'pointermove', move );
			document.removeEventListener( 'pointerup', stop );
			document.removeEventListener( 'pointercancel', stop );
			document.removeEventListener( 'lostpointercapture', stop );

			if ( frame ) {
				window.cancelAnimationFrame( frame );
				frame = 0;
			}

			if ( card.releasePointerCapture && 'number' === typeof pointerId ) {
				try {
					if ( card.hasPointerCapture && card.hasPointerCapture( pointerId ) ) {
						card.releasePointerCapture( pointerId );
					}
				} catch ( releaseError ) {}
			}

			/* Drop every transform before the real layout runs, and turn the
			 * transition off first so the reset is not animated. */
			clearShifts( group );
			card.classList.remove( 'is-dragging' );
			bumpLines( group, false );
			markColumns( group, 0, 0, null );

			if ( placeholder ) {
				placeholder.remove();
				placeholder = null;
			}

			if ( ghost ) {
				ghost.remove();
				ghost = null;
			}

			if ( ! moved ) {
				/* A press without movement must leave the group exactly as it was:
				 * only the height could have been touched. */
				if ( baseHeight ) {
					group.style.height = baseHeight;
				} else {
					group.style.removeProperty( 'height' );
				}

				return;
			}

			/* The preview is the contract: the slot it showed is where the card
			 * goes. The old 48px horizontal heuristic classified a purely
			 * vertical drag as "auto arrange" and threw the slot away, so the
			 * card jumped elsewhere the instant the pointer was released. */
			card.setAttribute( 'data-sakura-span', String( span ) );
			card.setAttribute( 'data-sakura-col', String( dropCol ) );
			/* Order and column are the whole state now: no absolute top is stored,
			 * so no drop can leave a hole behind. */
			card.removeAttribute( 'data-sakura-top' );

			settle( group, card, dropIndex );

			freeLayout( group );
			persist( page );
		}

		document.addEventListener( 'pointermove', move );
		document.addEventListener( 'pointerup', stop, { once: true } );
		document.addEventListener( 'pointercancel', stop, { once: true } );
		document.addEventListener( 'lostpointercapture', stop, { once: true } );
	}

	/* ---------------------------------------------------------------- guides */

	function showGuide( card, y ) {
		var group = card.parentNode;
		var guide = group && group.querySelector( ':scope > .sakura-layout-guide' );
		if ( ! guide ) {
			return;
		}
		if ( ! y ) {
			guide.style.display = 'none';
			return;
		}
		guide.style.display = 'block';
		guide.style.top = y + 'px';
	}

	/**
	 * Snap the card's bottom edge to a sibling card's bottom edge so cards that
	 * share a row end up with the same height. Returns the height to apply.
	 */
	function snapHeight( card, height ) {
		var group = card.parentNode;
		if ( ! group ) {
			return height;
		}
		var groupRect = group.getBoundingClientRect();
		var top = card.getBoundingClientRect().top - groupRect.top;
		var bottom = top + height;
		var best = null;
		var bestDelta = 8;

		cardsOf( group ).forEach( function ( sibling ) {
			if ( sibling === card ) {
				return;
			}
			var siblingBottom = sibling.getBoundingClientRect().bottom - groupRect.top;
			var delta = Math.abs( siblingBottom - bottom );
			if ( delta <= bestDelta ) {
				bestDelta = delta;
				best = siblingBottom;
			}
		} );

		if ( null === best ) {
			showGuide( card, 0 );
			return height;
		}

		showGuide( card, best );
		return clamp( best - top, MIN_H, MAX_H );
	}

	/**
	 * Column band painted under the cards while one is being dragged.
	 *
	 * bumpLines() used to be an empty stub called from startDrag / move / stop /
	 * startResize — four call sites, no effect. That is why dragging showed no
	 * grid feedback at all.
	 *
	 * @param {Element} group Layout group.
	 * @param {number}  col   1-based target column, 0 to clear.
	 * @param {number}  span  Card span.
	 * @param {Object}  box   Result of metrics( group ).
	 */
	function markColumns( group, col, span, box ) {
		if ( ! group ) {
			return;
		}

		if ( ! col || ! box || box.track <= 0 ) {
			group.classList.remove( 'has-drop' );
			group.style.removeProperty( '--drop-left' );
			group.style.removeProperty( '--drop-width' );
			return;
		}

		group.classList.add( 'has-drop' );
		group.style.setProperty( '--drop-left', ( ( col - 1 ) * ( box.track + box.gapX ) ) + 'px' );
		group.style.setProperty( '--drop-width', ( span * box.track + ( span - 1 ) * box.gapX ) + 'px' );
	}

	function bumpLines( group, on ) {
		if ( group ) {
			group.classList.toggle( 'is-dragging-card', !! on );
		}
	}

	var cardTimers = new WeakMap();

	function pulseCard( card ) {
		card.classList.add( 'is-resizing' );
		window.clearTimeout( cardTimers.get( card ) );
		cardTimers.set( card, window.setTimeout( function () {
			card.classList.remove( 'is-resizing' );
		}, 5000 ) );
	}

	/* ---------------------------------------------------------------- resize */

	function addResizeHandles( card, groupName ) {
		if ( card.querySelector( ':scope > .sakura-layout-grip' ) ) {
			return;
		}
		[ 'x', 'y', 'xy' ].forEach( function ( axis ) {
			var grip = document.createElement( 'span' );
			grip.className = 'sakura-layout-grip sakura-layout-grip--' + axis;
			grip.setAttribute( 'role', 'separator' );
			grip.setAttribute( 'aria-label', axis === 'x' ? ( cfg.strings && cfg.strings.wider ) || '调整宽度' : ( cfg.strings && cfg.strings.taller ) || '调整高度' );
			grip.setAttribute( 'tabindex', '-1' );
			grip.addEventListener( 'pointerdown', function ( event ) {
				if ( ! editing || ! desktops.matches || event.button !== 0 ) {
					return;
				}
				startResize( event, card, axis );
			} );
			card.appendChild( grip );
		} );
	}

	function startResize( event, card, axis ) {
		event.preventDefault();
		event.stopPropagation();
		var editingGroup = card.parentNode;
		bumpLines( editingGroup, true );
		var startX = event.clientX;
		var startY = event.clientY;
		var startSpan = parseInt( card.getAttribute( 'data-sakura-span' ), 10 ) || 12;
		var startHeight = card.getBoundingClientRect().height;
		var grid = card.parentNode;
		var column = Math.max( 1, grid.getBoundingClientRect().width / 12 );
		var page = pageOf( grid );

		function move( moveEvent ) {
			pulseCard( card );
			if ( axis === 'x' || axis === 'xy' ) {
				var span = clamp( Math.round( startSpan + ( moveEvent.clientX - startX ) / column ), MIN_SPAN, MAX_SPAN );
				if ( grid.classList.contains( 'is-free' ) ) {
					var gridStyles = window.getComputedStyle( grid );
					var gapX = cssPx( gridStyles.columnGap, 20 );
					var track = ( grid.clientWidth - gapX * 11 ) / 12;
					var col = parseInt( card.getAttribute( 'data-sakura-col' ), 10 ) || 1;
					span = clamp( span, MIN_SPAN, Math.max( MIN_SPAN, 13 - col ) );
					if ( track > 0 ) {
						card.style.width = ( span * track + ( span - 1 ) * gapX ) + 'px';
					}
				} else {
					card.style.gridColumn = 'span ' + span;
				}
				card.setAttribute( 'data-sakura-span', String( span ) );
			}
			if ( axis === 'y' || axis === 'xy' ) {
				var height = clamp( startHeight + ( moveEvent.clientY - startY ), MIN_H, MAX_H );
				height = snapHeight( card, height );
				card.setAttribute( 'data-sakura-h', String( Math.round( height ) ) );
				card.setAttribute( 'data-sakura-resized', '1' );
				card.style.height = 'auto';
				card.style.minHeight = height + 'px';
			}
		}

		function stop() {
			document.removeEventListener( 'pointermove', move );
			document.removeEventListener( 'pointerup', stop );
			showGuide( card, 0 );
			card.classList.remove( 'is-resizing' );
			bumpLines( card.parentNode, false );
			markColumns( card.parentNode, 0, 0, null );
			void card.offsetHeight;
			freeLayout( card.parentNode );
			persist( page );
		}

		pulseCard( card );
		document.addEventListener( 'pointermove', move );
		document.addEventListener( 'pointerup', stop, { once: true } );
	}

	/* --------------------------------------------------------------- toolbar */

	function setEditing( on ) {
		editing = on;
		root.classList.toggle( 'sakura-layout-edit', on );
		document.querySelectorAll( '[data-sakura-layout]' ).forEach( function ( group ) {
			group.classList.toggle( 'sakura-layout-no-lines', ! pageState( pageOf( group ) ).grid );
		} );
		if ( ! toolbar ) {
			return;
		}
		toolbar.querySelector( '.sakura-layout-tools-label' ).textContent = on
			? ( ( cfg.strings && cfg.strings.save ) || '布局保存' )
			: ( ( cfg.strings && cfg.strings.edit ) || '布局修改' );
		panel.hidden = ! on;
		var resetButton = toolbar.querySelector( '.sakura-layout-reset' );
		if ( resetButton ) {
			resetButton.hidden = ! on;
		}
		toolbar.querySelector( '.sakura-layout-tools-toggle' ).setAttribute( 'aria-expanded', on ? 'true' : 'false' );
		if ( ! on ) {
			document.querySelectorAll( '[data-sakura-layout]' ).forEach( function ( group ) {
				group.classList.remove( 'is-editing' );
				group.classList.remove( 'is-dragging-card' );
				markColumns( group, 0, 0, null );
				window.clearTimeout( lineTimers.get( group ) );
			} );
			document.querySelectorAll( '[data-sakura-card]' ).forEach( function ( card ) {
				card.classList.remove( 'is-resizing', 'is-dragging' );
			} );
			persist( firstPage() );
		}
	}

	function buildToolbar() {
		if ( toolbar || ! document.querySelector( '[data-sakura-ready]' ) ) {
			return;
		}
		toolbar = document.createElement( 'div' );
		toolbar.className = 'sakura-layout-tools';
		toolbar.innerHTML = '<button type="button" class="sakura-layout-reset" hidden>' + ( ( cfg.strings && cfg.strings.reset ) || '恢复默认' ) + '</button>' +
			'<div class="sakura-layout-panel" hidden>' +
			'<label class="sakura-layout-check"><input type="checkbox" data-grid-toggle /> ' + ( ( cfg.strings && cfg.strings.grid ) || '显示网格' ) + '</label>' +
			'<p class="sakura-layout-hint">' + ( ( cfg.strings && cfg.strings.hint ) || '拖标题移动，拖边缘调整大小。' ) + '</p>' +
			'</div>' +
			'<button type="button" class="sakura-layout-tools-toggle" aria-expanded="false">' +
			'<span class="sakura-layout-tools-label">' + ( ( cfg.strings && cfg.strings.edit ) || '布局修改' ) + '</span></button>';
		root.appendChild( toolbar );

		var gridToggle = toolbar.querySelector( '[data-grid-toggle]' );
		gridToggle.checked = !! pageState( firstPage() ).grid;

		var toggle = toolbar.querySelector( '.sakura-layout-tools-toggle' );
		panel = toolbar.querySelector( '.sakura-layout-panel' );
		toggle.addEventListener( 'click', function () {
			setEditing( ! editing );
		} );

		toolbar.querySelector( '[data-grid-toggle]' ).addEventListener( 'change', function () {
			var page = firstPage();
			var data = pageState( page );
			data.grid = this.checked ? 1 : 0;
			document.querySelectorAll( '[data-sakura-layout]' ).forEach( function ( group ) {
				if ( pageOf( group ) === page ) {
					group.classList.toggle( 'sakura-layout-no-lines', ! data.grid );
				}
			} );
			persist( page );
		} );

		toolbar.querySelector( '.sakura-layout-reset' ).addEventListener( 'click', function () {
			var page = firstPage();
			state[ page ] = { grid: 1, groups: {} };
			gridToggle.checked = true;
			document.querySelectorAll( '[data-sakura-layout="' + page + '"]' ).forEach( function ( group ) {
				group.classList.remove( 'sakura-layout-no-lines' );
				var byId = {};
				cardsOf( group ).forEach( function ( card ) {
					var id = card.getAttribute( 'data-sakura-card' );
					var fallback = ( DEFAULT_SPANS[ page ] && DEFAULT_SPANS[ page ][ id ] ) || 12;
					byId[ id ] = card;
					card.style.removeProperty( 'min-height' );
					card.style.removeProperty( 'height' );
					card.removeAttribute( 'data-sakura-col' );
					card.removeAttribute( 'data-sakura-top' );
					card.removeAttribute( 'data-sakura-h' );
					card.removeAttribute( 'data-sakura-resized' );
					card.style.gridColumn = 'span ' + fallback;
					card.setAttribute( 'data-sakura-span', String( fallback ) );
				} );
				arrayAppendDefaults( group, byId, ( group.getAttribute( 'data-sakura-default-order' ) || '' ).split( ',' ) );
			} );
			document.querySelectorAll( '[data-sakura-layout="' + page + '"]' ).forEach( freeLayout );
			persist( page );
		} );
	}

	function arrayAppendDefaults( group, byId, ids ) {
		var seen = {};
		ids.forEach( function ( id ) {
			if ( id && byId[ id ] && ! seen[ id ] ) {
				seen[ id ] = true;
				group.appendChild( byId[ id ] );
			}
		} );
		Object.keys( byId ).forEach( function ( id ) {
			if ( ! seen[ id ] ) {
				group.appendChild( byId[ id ] );
			}
		} );
	}

	function firstPage() {
		var node = document.querySelector( '[data-sakura-ready]' );
		return node ? pageOf( node ) : 'main';
	}

	/* ------------------------------------------------------------------ save */

	function persist( page ) {
		window.clearTimeout( saveTimers[ page ] );
		saveTimers[ page ] = window.setTimeout( function () {
			savePage( page );
		}, 350 );
	}

	function savePage( page ) {
		var payload = { grid: 0, groups: {} };
		var data = pageState( page );
		payload.grid = data.grid ? 1 : 0;

		document.querySelectorAll( '[data-sakura-layout="' + page + '"]' ).forEach( function ( group ) {
			var name = group.getAttribute( 'data-sakura-group' ) || 'main';
			var groupData = { order: [], cards: {} };
			cardsOf( group ).forEach( function ( card ) {
				var id = card.getAttribute( 'data-sakura-card' );
				if ( ! id ) {
					return;
				}
				groupData.order.push( id );
				groupData.cards[ id ] = {
					span: parseInt( card.getAttribute( 'data-sakura-span' ), 10 ) || 12,
					/* Retired: kept at 0 so old clients/schema still parse. */
					minH: 0,
					/* Only an explicit height resize is persisted; natural
					 * heights stay fluid when the viewport width changes. */
					h: card.getAttribute( 'data-sakura-resized' ) === '1' ? ( parseInt( card.getAttribute( 'data-sakura-h' ), 10 ) || 0 ) : 0,
					col: parseInt( card.getAttribute( 'data-sakura-col' ), 10 ) || 0,
					/* Retired: the board is flow-based and stores no position. */
					top: 0
				};
			} );
			payload.groups[ name ] = groupData;
		} );

		admin.api.post( cfg.ajaxUrl, {
			action: 'sakura_save_layout',
			nonce: cfg.nonce,
			page: page,
			layout: JSON.stringify( payload )
		}, { timeout: 15000 } ).catch( function () {} );
	}
} )();

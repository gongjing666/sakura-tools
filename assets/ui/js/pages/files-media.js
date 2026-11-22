/**
 * Media manager: owner tree on the left, file browser on the right.
 *
 * Two things this file deliberately does not do, because both are why the media
 * library integration keeps needing repairs:
 *
 * - It never reads or replaces WordPress's own DOM. The list table's bulk-action
 *   dropdowns are bound by core's `marryControls()` once, at load; replacing
 *   `.tablenav` silently drops those bindings, which is how a takedown once
 *   submitted without its reason.
 * - It never follows the grid's Backbone selection model. One selection set
 *   (`state.selection`) serves both views, so switching between list and cards
 *   cannot lose or duplicate a choice.
 *
 * The server payload is the only source of truth: owners, files, reasons and
 * every string arrive in `sakuraPage.data`, so the markup here stays generic.
 *
 * @package Sakura_Image_Optimizer
 */
( function ( window, document ) {
	'use strict';

	var admin = window.SakuraAdmin;

	if ( ! admin ) {
		return;
	}

	admin.ready( function () {
		var root = document.getElementById( 'sufm-mm' );

		if ( ! root ) {
			return;
		}

		var config = admin.data || {};
		var strings = config.strings || {};
		var base = ( admin.page.restUrl || '' ) + 'sufm/v1/';
		var nonce = admin.page.nonce || '';
		var canTakedown = !! config.canTakedown;
		var reasons = Array.isArray( config.reasons ) ? config.reasons : [];

		var treeNode = document.getElementById( 'sufm-mm-tree' );
		var filesNode = document.getElementById( 'sufm-mm-files' );
		var crumbsNode = document.getElementById( 'sufm-mm-crumbs' );
		var ownerSearch = document.getElementById( 'sufm-mm-owner-search' );
		var fileSearch = document.getElementById( 'sufm-mm-file-search' );
		var moreOwners = document.getElementById( 'sufm-mm-more-owners' );
		var moreFiles = document.getElementById( 'sufm-mm-more-files' );
		var selectionBar = document.getElementById( 'sufm-mm-selection' );
		var countNode = document.getElementById( 'sufm-mm-count' );
		var takedownButton = document.getElementById( 'sufm-mm-takedown' );
		var clearButton = document.getElementById( 'sufm-mm-clear' );
		var refreshButton = document.getElementById( 'sufm-mm-refresh' );
		var viewList = document.getElementById( 'sufm-mm-view-list' );
		var viewGrid = document.getElementById( 'sufm-mm-view-grid' );

		var state = {
			owner: 0,
			folder: 0,
			scope: 'folder',
			search: '',
			ownerSearch: '',
			cursor: 0,
			next: 0,
			view: window.localStorage && window.localStorage.getItem( 'sufm_mm_view' ) === 'grid' ? 'grid' : 'list',
			owners: [],
			ownerPage: 1,
			hasMoreOwners: false,
			files: [],
			folders: [],
			breadcrumb: [],
			selection: {},
			anchor: 0,
			loading: false
		};

		/* ---------------------------------------------------------------
		 * Small helpers
		 * --------------------------------------------------------------- */

		function el( tag, className, text ) {
			var node = document.createElement( tag );

			if ( className ) {
				node.className = className;
			}

			if ( text !== undefined && text !== null ) {
				node.textContent = String( text );
			}

			return node;
		}

		function request( path, options ) {
			var settings = Object.assign( {}, options || {} );
			settings.headers = Object.assign( { 'X-WP-Nonce': nonce }, settings.headers || {} );

			return admin.api.request( base + path, settings );
		}

		function get( path, params ) {
			return admin.api.get( base + path, params || {}, { headers: { 'X-WP-Nonce': nonce } } );
		}

		function errorText( error ) {
			if ( ! error ) {
				return strings.loadFailed;
			}

			if ( typeof error.payload === 'string' && error.payload ) {
				return error.payload;
			}

			if ( error.payload && error.payload.message ) {
				return error.payload.message;
			}

			return error.message || strings.loadFailed;
		}

		function toast( message, tone ) {
			if ( admin.ui && admin.ui.toast ) {
				admin.ui.toast( message, tone );
				return;
			}

			if ( tone === 'bad' ) {
				window.console.warn( message );
			}
		}

		function selectedIds() {
			return Object.keys( state.selection ).map( Number ).filter( function ( id ) {
				return id > 0;
			} );
		}

		function selectedAttachments() {
			var ids = selectedIds();

			return state.files.filter( function ( file ) {
				return ids.indexOf( Number( file.id ) ) >= 0 && Number( file.attachment ) > 0;
			} ).map( function ( file ) {
				return Number( file.attachment );
			} );
		}

		function formatBytes( bytes ) {
			var value = Number( bytes ) || 0;

			if ( value < 1024 ) {
				return value + ' B';
			}

			if ( value < 1048576 ) {
				return ( value / 1024 ).toFixed( 1 ) + ' KB';
			}

			return ( value / 1048576 ).toFixed( 1 ) + ' MB';
		}

		function isImage( mime ) {
			return /^image\//.test( String( mime || '' ) );
		}

		/* ---------------------------------------------------------------
		 * Selection — one set for both views
		 * --------------------------------------------------------------- */

		/* The rows currently on screen, in reading order. Both views render one
		 * [data-file] element per file, so a Shift range means the same thing in
		 * either of them. */
		function selectableIds() {
			return Array.prototype.map.call(
				filesNode.querySelectorAll( '[data-file]' ),
				function ( node ) {
					return Number( node.getAttribute( 'data-file' ) );
				}
			).filter( function ( id ) {
				return id > 0;
			} );
		}

		function selectRange( id ) {
			var ids = selectableIds();
			var target = Number( id );

			if ( ids.indexOf( target ) < 0 ) {
				return;
			}

			var anchor = Number( state.anchor ) || target;
			var from = ids.indexOf( anchor );
			var to = ids.indexOf( target );

			if ( from < 0 ) {
				from = to;
			}

			var start = Math.min( from, to );
			var end = Math.max( from, to );

			for ( var i = start; i <= end; i++ ) {
				state.selection[ String( ids[ i ] ) ] = true;
			}

			state.anchor = anchor;
			afterSelection();
		}

		function toggleFile( id ) {
			id = Number( id );

			if ( ! id ) {
				return;
			}

			if ( state.selection[ String( id ) ] ) {
				delete state.selection[ String( id ) ];
			} else {
				state.selection[ String( id ) ] = true;
			}

			state.anchor = id;
			afterSelection();
		}

		function clearSelection() {
			state.selection = {};
			state.anchor = 0;
			afterSelection();
		}

		/* Only the bits that changed are rewritten: repainting the whole file area
		 * on every click would drop focus and scroll position, which is the thing
		 * that makes a file browser feel slow. */
		function afterSelection() {
			var ids = selectedIds();

			Array.prototype.forEach.call( filesNode.querySelectorAll( '[data-file]' ), function ( node ) {
				var id = String( node.getAttribute( 'data-file' ) );
				var on = !! state.selection[ id ];

				node.classList.toggle( 'is-selected', on );

				var box = node.querySelector( '[data-select]' );

				if ( box ) {
					box.checked = on;
				}
			} );

			if ( ! ids.length ) {
				selectionBar.hidden = true;
				return;
			}

			selectionBar.hidden = false;
			countNode.textContent = 1 === ids.length
				? strings.selectedOne
				: String( strings.selectedMany ).replace( '%d', ids.length );

			if ( takedownButton ) {
				takedownButton.disabled = ! selectedAttachments().length;
			}
		}

		/* ---------------------------------------------------------------
		 * Owner tree
		 * --------------------------------------------------------------- */

		function sanctionBadge( sanction ) {
			if ( ! sanction ) {
				return null;
			}

			var badge = el( 'span', 'sufm-mm__badge', sanction.badge || sanction.label );
			badge.classList.add( 'sufm-mm__badge--' + ( sanction.type === 'warn' ? 'warn' : 'bad' ) );

			return badge;
		}

		function renderOwners() {
			var query = state.ownerSearch.toLowerCase();

			treeNode.replaceChildren();

			var all = el( 'button', 'sufm-mm__node' + ( 0 === state.owner ? ' is-active' : '' ) );
			all.type = 'button';
			all.setAttribute( 'role', 'treeitem' );
			all.appendChild( el( 'span', 'sufm-mm__node-name', strings.allOwners ) );

			/* The sum of the loaded owner counts, not a separate request: the tree
			 * pages in blocks, so this is "what the list currently knows", which is
			 * exactly what the operator is looking at. */
			var sum = state.owners.reduce( function ( total, owner ) {
				return total + ( Number( owner.files ) || 0 );
			}, 0 );

			if ( sum > 0 ) {
				all.appendChild( el( 'span', 'sufm-mm__node-count', sum ) );
			}

			all.addEventListener( 'click', function () {
				openOwner( 0 );
			} );
			treeNode.appendChild( all );

			state.owners.forEach( function ( owner ) {
				if ( query && String( owner.name || '' ).toLowerCase().indexOf( query ) < 0 && String( owner.login || '' ).toLowerCase().indexOf( query ) < 0 && String( owner.id ).indexOf( query ) < 0 ) {
					return;
				}

				var row = el( 'button', 'sufm-mm__node' + ( Number( owner.id ) === Number( state.owner ) ? ' is-active' : '' ) );
				row.type = 'button';
				row.setAttribute( 'role', 'treeitem' );

				var name = el( 'span', 'sufm-mm__node-name', owner.name + ' #' + owner.id );
				name.title = owner.name + ' #' + owner.id;
				row.appendChild( name );

				if ( owner.deleted ) {
					row.classList.add( 'is-orphan' );
				}

				/* Count before the badge: a trailing number after a pill reads as
				 * part of the pill ("剩 7 天0"), which is how a status turns into
				 * noise. The badge stays pinned to the right edge. */
				row.appendChild( el( 'span', 'sufm-mm__node-count', Number( owner.files ) || 0 ) );

				var badge = sanctionBadge( owner.sanction );

				if ( badge ) {
					row.appendChild( badge );
					row.classList.add( 'is-sanctioned' );
				}

				row.addEventListener( 'click', function () {
					openOwner( Number( owner.id ) );
				} );

				treeNode.appendChild( row );
			} );

			moreOwners.hidden = ! state.hasMoreOwners;
		}

		function openOwner( id ) {
			state.owner = Number( id ) || 0;
			state.folder = 0;
			state.cursor = 0;
			state.selection = {};
			state.anchor = 0;
			renderOwners();
			loadFiles( true );
		}

		function loadOwners( append ) {
			var params = { per_page: 50 };
			params.page = append ? state.ownerPage + 1 : 1;

			if ( state.ownerSearch ) {
				params.search = state.ownerSearch;
			}

			return get( 'admin/folders', params ).then( function ( payload ) {
				var owners = payload && Array.isArray( payload.owners ) ? payload.owners : [];

				if ( append ) {
					state.owners = state.owners.concat( owners );
					state.ownerPage += 1;
				} else {
					state.owners = owners;
					state.ownerPage = 1;
				}

				state.hasMoreOwners = !!( payload && payload.has_more );
				renderOwners();
			} ).catch( function ( error ) {
				treeNode.replaceChildren( el( 'div', 'sufm-mm__tree-empty', errorText( error ) ) );
			} );
		}

		/* ---------------------------------------------------------------
		 * File area
		 * --------------------------------------------------------------- */

		function fileIcon( file ) {
			/* `thumb` is resolved on the server from the attachment itself, so it
			 * follows bucket rewrites and stays on the current host. Falling back
			 * to `url` only covers attachments that predate the decoration. */
			var src = String( file.thumb || file.url || '' );

			if ( isImage( file.mime ) && src ) {
				var img = el( 'img', 'sufm-mm__thumb' );
				img.src = src;
				img.alt = '';
				img.loading = 'lazy';
				img.decoding = 'async';
				img.addEventListener( 'error', function () {
					var ext = String( file.name || '' ).split( '.' ).pop();
					var fallback = el( 'span', 'sufm-mm__thumb sufm-mm__thumb--fallback', ( ext && ext.length <= 5 ? ext : '?' ).toUpperCase() );

					if ( img.parentNode ) {
						img.parentNode.replaceChild( fallback, img );
					}
				} );

				return img;
			}

			var ext = String( file.name || '' ).split( '.' ).pop();

			return el( 'span', 'sufm-mm__thumb sufm-mm__thumb--fallback', ( ext && ext.length <= 5 ? ext : '?' ).toUpperCase() );
		}

		function selectBox( file ) {
			var box = document.createElement( 'input' );
			box.type = 'checkbox';
			box.className = 'sufm-mm__check';
			box.setAttribute( 'data-select', String( file.id ) );
			box.checked = !! state.selection[ String( file.id ) ];
			box.disabled = Number( file.attachment ) <= 0;
			box.setAttribute( 'aria-label', String( strings.select ) + ' ' + String( file.name || '' ) );

			return box;
		}

		function rowMeta( file ) {
			var parts = [ formatBytes( file.bytes ) ];

			if ( file.folder ) {
				parts.push( file.folder );
			}

			return parts.join( ' · ' );
		}

		function buildListRow( file ) {
			var row = el( 'div', 'sufm-mm__row' + ( state.selection[ String( file.id ) ] ? ' is-selected' : '' ) );
			row.setAttribute( 'data-file', String( file.id ) );

			if ( file.locked ) {
				row.classList.add( 'is-locked' );
				row.title = strings.lockedTip;
			}

			row.appendChild( selectBox( file ) );

			var name = el( 'div', 'sufm-mm__name' );
			name.appendChild( fileIcon( file ) );
			name.appendChild( el( 'span', 'sufm-mm__name-text', file.name ) );
			row.appendChild( name );

			row.appendChild( el( 'div', 'sufm-mm__cell', rowMeta( file ) ) );
			row.appendChild( el( 'div', 'sufm-mm__cell sufm-mm__cell--owner', ownerLabel( file.owner_id ) ) );

			return row;
		}

		function buildCard( file ) {
			var card = el( 'div', 'sufm-mm__card' + ( state.selection[ String( file.id ) ] ? ' is-selected' : '' ) );
			card.setAttribute( 'data-file', String( file.id ) );

			if ( file.locked ) {
				card.classList.add( 'is-locked' );
				card.title = strings.lockedTip;
			}

			card.appendChild( selectBox( file ) );

			var art = el( 'div', 'sufm-mm__card-art' );
			art.appendChild( fileIcon( file ) );
			card.appendChild( art );

			card.appendChild( el( 'div', 'sufm-mm__card-name', file.name ) );
			card.appendChild( el( 'div', 'sufm-mm__card-meta', rowMeta( file ) ) );

			return card;
		}

		function ownerLabel( id ) {
			var owner = state.owners.filter( function ( candidate ) {
				return Number( candidate.id ) === Number( id );
			} )[0];

			if ( ! owner ) {
				return String( id );
			}

			return owner.name + ' #' + owner.id;
		}

		function renderFiles() {
			filesNode.replaceChildren();
			filesNode.setAttribute( 'data-view', state.view );

			if ( state.breadcrumb.length ) {
				crumbsNode.replaceChildren();

				/* The chain starts at the account, not at the cabinet: a folder path
				 * is a path inside one account, and the tree already carries the
				 * "which account" answer. */
				var ownerCrumb = el( 'button', 'sufm-mm__crumb', ownerLabel( state.owner ) );
				ownerCrumb.type = 'button';
				ownerCrumb.addEventListener( 'click', function () {
					openFolder( 0 );
				} );
				crumbsNode.appendChild( ownerCrumb );

				state.breadcrumb.forEach( function ( crumb ) {
					crumbsNode.appendChild( el( 'span', 'sufm-mm__crumb-sep', '/' ) );

					var link = el( 'button', 'sufm-mm__crumb', crumb.name );
					link.type = 'button';
					link.addEventListener( 'click', function () {
						openFolder( Number( crumb.id ) );
					} );
					crumbsNode.appendChild( link );
				} );
			} else if ( state.owner ) {
				/* Root of one owner: the tree already highlights the row, but the
				 * crumb is what says "these are the files inside this account", and
				 * it is the way back to the whole cabinet. */
				crumbsNode.replaceChildren();

				var back = el( 'button', 'sufm-mm__crumb', strings.allOwners );
				back.type = 'button';
				back.addEventListener( 'click', function () {
					openOwner( 0 );
				} );
				crumbsNode.appendChild( back );
				crumbsNode.appendChild( el( 'span', 'sufm-mm__crumb-sep', '/' ) );
				crumbsNode.appendChild( el( 'span', 'sufm-mm__crumb is-current', ownerLabel( state.owner ) ) );
			} else {
				crumbsNode.replaceChildren( el( 'span', 'sufm-mm__crumb is-current', strings.allOwners ) );
			}

			if ( ! state.files.length && ! state.folders.length ) {
				var empty = el( 'div', 'sufm-mm__placeholder', state.loading ? strings.loading : ( state.owner ? strings.emptyOwner : strings.empty ) );
				filesNode.appendChild( empty );
				moreFiles.hidden = true;
				afterSelection();

				return;
			}

			if ( state.folders.length ) {
				var folderStrip = el( 'div', 'sufm-mm__folders' );

				state.folders.forEach( function ( folder ) {
					var chip = el( 'button', 'sufm-mm__folder' );
					chip.type = 'button';
					chip.appendChild( el( 'span', 'sufm-mm__folder-name', folder.name ) );
					chip.appendChild( el( 'span', 'sufm-mm__folder-count', Number( folder.count ) || 0 ) );
					chip.addEventListener( 'click', function () {
						openFolder( Number( folder.id ) );
					} );
					folderStrip.appendChild( chip );
				} );

				filesNode.appendChild( folderStrip );
			}

			if ( state.files.length ) {
				if ( 'grid' === state.view ) {
					var grid = el( 'div', 'sufm-mm__grid' );
					state.files.forEach( function ( file ) {
						grid.appendChild( buildCard( file ) );
					} );
					filesNode.appendChild( grid );
				} else {
					var head = el( 'div', 'sufm-mm__row sufm-mm__row--head' );
					head.appendChild( el( 'div', 'sufm-mm__cell', '' ) );
					head.appendChild( el( 'div', 'sufm-mm__cell', strings.file ) );
					head.appendChild( el( 'div', 'sufm-mm__cell', strings.size ) );
					head.appendChild( el( 'div', 'sufm-mm__cell', strings.owner ) );
					filesNode.appendChild( head );

					var list = el( 'div', 'sufm-mm__list' );
					state.files.forEach( function ( file ) {
						list.appendChild( buildListRow( file ) );
					} );
					filesNode.appendChild( list );
				}
			}

			moreFiles.hidden = ! state.next;
			afterSelection();
		}

		function openFolder( id ) {
			state.folder = Number( id ) || 0;
			state.cursor = 0;
			state.selection = {};
			state.anchor = 0;
			loadFiles( true );
		}

		function loadFiles( reset ) {
			state.loading = true;

			if ( reset ) {
				filesNode.replaceChildren( el( 'div', 'sufm-mm__placeholder', strings.loading ) );
			}

			var params = {
				per_page: 60,
				owner_id: state.owner,
				folder_id: state.folder
			};

			if ( state.search ) {
				params.search = state.search;
			}

			if ( ! reset && state.cursor ) {
				params.cursor = state.cursor;
			}

			return get( 'admin/media', params ).then( function ( payload ) {
				state.loading = false;

				var files = payload && Array.isArray( payload.files ) ? payload.files : [];
				var folders = payload && Array.isArray( payload.folders ) ? payload.folders : [];

				if ( reset ) {
					state.files = files;
					state.folders = folders;
					state.breadcrumb = payload && Array.isArray( payload.breadcrumb ) ? payload.breadcrumb : [];
					state.next = Number( payload && payload.next ) || 0;
					state.cursor = state.next;
				} else {
					state.files = state.files.concat( files );
					state.next = Number( payload && payload.next ) || 0;
					state.cursor = state.next;
				}

				renderFiles();
			} ).catch( function ( error ) {
				state.loading = false;
				filesNode.replaceChildren( el( 'div', 'sufm-mm__placeholder', errorText( error ) ) );
			} );
		}

		/* ---------------------------------------------------------------
		 * Takedown dialog
		 * --------------------------------------------------------------- */

		function openTakedown() {
			var ids = selectedAttachments();

			if ( ! ids.length ) {
				toast( strings.noSelection, 'bad' );
				return;
			}

			var dialog = document.getElementById( 'sufm-mm-takedown-dialog' );

			if ( ! dialog ) {
				return;
			}

			var lead = document.getElementById( 'sufm-mm-takedown-lead' );
			var reasonSelect = document.getElementById( 'sufm-mm-reason' );
			var noteInput = document.getElementById( 'sufm-mm-note' );
			var punish = document.getElementById( 'sufm-mm-punish' );
			var sanctionFields = document.getElementById( 'sufm-mm-sanction-fields' );
			var typeSelect = document.getElementById( 'sufm-mm-sanction-type' );
			var daysSelect = document.getElementById( 'sufm-mm-sanction-days' );
			var confirmButton = document.getElementById( 'sufm-mm-takedown-confirm' );
			var cancelButton = document.getElementById( 'sufm-mm-takedown-cancel' );

			lead.textContent = String( strings.takedownMessage ).replace( '%d', ids.length );

			reasonSelect.replaceChildren();

			reasons.forEach( function ( reason ) {
				var option = document.createElement( 'option' );
				option.value = String( reason.value );
				option.textContent = String( reason.label );
				reasonSelect.appendChild( option );
			} );

			noteInput.value = '';
			punish.checked = false;
			sanctionFields.hidden = true;
			typeSelect.value = 'warn';
			daysSelect.value = '7';
			daysSelect.disabled = true;

			function syncSanctionFields() {
				sanctionFields.hidden = ! punish.checked;
				daysSelect.disabled = 'warn' === typeSelect.value;
			}

			punish.onchange = syncSanctionFields;
			typeSelect.onchange = syncSanctionFields;

			function close() {
				if ( typeof dialog.close === 'function' ) {
					dialog.close();
				} else {
					dialog.removeAttribute( 'open' );
				}
			}

			cancelButton.onclick = close;

			confirmButton.onclick = function () {
				if ( ! reasonSelect.value ) {
					toast( strings.takedownNeedReason, 'bad' );
					return;
				}

				var body = {
					ids: ids,
					reason: reasonSelect.value,
					note: noteInput.value
				};

				if ( punish.checked ) {
					body.punish = true;
					body.sanction_type = typeSelect.value;
					body.sanction_days = daysSelect.disabled ? 0 : Number( daysSelect.value );
				}

				confirmButton.disabled = true;

				request( 'admin/media/takedown', {
					method: 'POST',
					body: body
				} ).then( function ( payload ) {
					confirmButton.disabled = false;
					close();

					var done = Number( payload && payload.done ) || 0;
					var partial = Number( payload && payload.partial ) || 0;
					var skipped = Number( payload && payload.skipped ) || 0;
					var sanctioned = Number( payload && payload.sanctioned ) || 0;

					toast(
						String( strings.takedownResult )
							.replace( '%1$d', done )
							.replace( '%2$d', partial )
							.replace( '%3$d', skipped ),
						'success'
					);

					if ( sanctioned > 0 ) {
						toast( String( strings.takedownSanctioned ).replace( '%d', sanctioned ), 'success' );
					}

					state.selection = {};
					state.anchor = 0;
					loadFiles( true );
					loadOwners( false );
				} ).catch( function ( error ) {
					confirmButton.disabled = false;
					toast( errorText( error ) || strings.takedownFailed, 'bad' );
				} );
			};

			if ( typeof dialog.showModal === 'function' ) {
				dialog.showModal();
			} else {
				dialog.setAttribute( 'open', 'open' );
			}

			reasonSelect.focus();
		}

		/* ---------------------------------------------------------------
		 * Wiring
		 * --------------------------------------------------------------- */

		if ( takedownButton ) {
			takedownButton.addEventListener( 'click', openTakedown );
		}

		clearButton.addEventListener( 'click', clearSelection );

		refreshButton.addEventListener( 'click', function () {
			loadOwners( false );
			loadFiles( true );
		} );

		function setView( view ) {
			state.view = view;

			if ( window.localStorage ) {
				window.localStorage.setItem( 'sufm_mm_view', view );
			}

			viewList.setAttribute( 'aria-pressed', 'list' === view ? 'true' : 'false' );
			viewGrid.setAttribute( 'aria-pressed', 'grid' === view ? 'true' : 'false' );
			renderFiles();
		}

		viewList.addEventListener( 'click', function () {
			setView( 'list' );
		} );

		viewGrid.addEventListener( 'click', function () {
			setView( 'grid' );
		} );

		/* Selection clicks: the checkbox toggles one, the row body follows the
		 * desktop file-browser convention (plain click replaces, Ctrl/Shift
		 * extend) so a keyboard-shy operator gets the same result from either. */
		filesNode.addEventListener( 'click', function ( event ) {
			var box = event.target.closest( '[data-select]' );

			if ( box ) {
				if ( event.shiftKey ) {
					selectRange( box.getAttribute( 'data-select' ) );
				} else {
					toggleFile( box.getAttribute( 'data-select' ) );
				}

				event.stopPropagation();

				return;
			}

			var row = event.target.closest( '[data-file]' );

			if ( ! row ) {
				return;
			}

			var id = row.getAttribute( 'data-file' );

			if ( event.shiftKey ) {
				selectRange( id );
				return;
			}

			if ( event.ctrlKey || event.metaKey ) {
				toggleFile( id );
				return;
			}

			state.selection = {};
			state.selection[ String( id ) ] = true;
			state.anchor = Number( id );
			afterSelection();
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && selectedIds().length ) {
				clearSelection();
			}
		} );

		ownerSearch.addEventListener( 'input', admin.debounce( function () {
			state.ownerSearch = ownerSearch.value.trim();
			loadOwners( false );
		}, 250 ) );

		fileSearch.addEventListener( 'input', admin.debounce( function () {
			state.search = fileSearch.value.trim();
			state.cursor = 0;
			loadFiles( true );
		}, 250 ) );

		moreOwners.addEventListener( 'click', function () {
			loadOwners( true );
		} );

		moreFiles.addEventListener( 'click', function () {
			loadFiles( false );
		} );

		viewList.setAttribute( 'aria-pressed', 'list' === state.view ? 'true' : 'false' );
		viewGrid.setAttribute( 'aria-pressed', 'grid' === state.view ? 'true' : 'false' );

		loadOwners( false );
		loadFiles( true );
	} );
}( window, document ) );

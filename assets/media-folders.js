( function () {
	'use strict';
	var data = window.sufmFolders, panel = document.getElementById( 'sufm-tree' );
	if ( ! data || ! panel ) { return; }
	var strings = data.strings || {};
	function t( key ) { return strings[ key ] || key; }
	function text( tag, cls, value ) { var e = document.createElement( tag ); if ( cls ) { e.className = cls; } if ( value !== undefined ) { e.textContent = value; } return e; }
	function button( label, run, cls ) { var e = text( 'button', cls || 'button', label ); e.type = 'button'; if ( run ) { e.addEventListener( 'click', run ); } return e; }
	function ids( values ) { return Array.from( new Set( values.map( Number ).filter( function ( n ) { return Number.isSafeInteger( n ) && n > 0; } ) ) ); }
	var list = document.getElementById( 'sufm-tree-list' ), search = document.getElementById( 'sufm-tree-search' );
	var owners = [], page = 1, more = false, loading = false, generation = 0, open = new Set(), loaded = new Set(), ownerRequests = {}, dragNodes = new WeakMap(), menu, menuReturn, drag = null, hoverTimer = 0, busy = false, selectionBound, toolbar, drawerReturn, activeDialog;
	try { open = new Set( JSON.parse( localStorage.getItem( 'sufmTreeOpen' ) || '[]' ) ); } catch ( e ) {}
	function saveOpen() { try { localStorage.setItem( 'sufmTreeOpen', JSON.stringify( Array.from( open ) ) ); } catch ( e ) {} }
	function apiUrl( path ) {
		var split = path.indexOf( '?' ), route = split < 0 ? path : path.slice( 0, split ), query = split < 0 ? '' : path.slice( split + 1 );
		var url = new URL( data.restUrl + route, location.href ); new URLSearchParams( query ).forEach( function ( value, key ) { url.searchParams.set( key, value ); } ); return url.href;
	}
	function api( path, options ) {
		options = options || {};
		return fetch( apiUrl( path ), { method: options.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': data.nonce, 'Content-Type': 'application/json' }, body: options.body ? JSON.stringify( options.body ) : undefined } ).then( function ( res ) {
			return res.json().then( function ( body ) { if ( ! res.ok ) { throw new Error( body.message || t( 'requestFailed' ) ); } return body; } );
		} );
	}
	var toast = text( 'div', 'sufm-feedback' ); toast.setAttribute( 'role', 'status' ); toast.setAttribute( 'aria-live', 'polite' ); document.body.appendChild( toast );
	var toastTimer;
	function notify( message, error ) { toast.textContent = message; toast.classList.toggle( 'is-error', !! error ); toast.hidden = false; clearTimeout( toastTimer ); toastTimer = setTimeout( function () { toast.hidden = true; }, 6500 ); }
	toast.hidden = true;
	function ownerId() { return Number( panel.dataset.owner ) || 0; }
	function folderId() { return panel.dataset.folder || ''; }
	function ownerById( id ) { return owners.find( function ( o ) { return Number( o.id ) === Number( id ); } ); }
	function findFolder( owner, id ) { var found; function walk( nodes ) { ( nodes || [] ).some( function ( f ) { if ( Number( f.id ) === Number( id ) ) { found = f; return true; } walk( f.children ); return !! found; } ); } walk( ( ownerById( owner ) || {} ).tree ); return found; }
	function targetLocked( owner, folder ) {
		var o = ownerById( owner ); if ( ! o || o.deleted || o.locked || o.can_drop === false ) { return true; }
		if ( ! Number( folder ) ) { return false; } var f = findFolder( owner, folder ), visited = new Set(); if ( ! f ) { return true; }
		while ( f ) { if ( visited.has( Number( f.id ) ) || f.locked || f.can_drop === false ) { return true; } visited.add( Number( f.id ) ); if ( ! Number( f.parent_id ) ) { return false; } f = findFolder( owner, f.parent_id ); if ( ! f ) { return true; } }
		return false;
	}
	function pathName( owner, folder ) {
		var o = ownerById( owner ), parts = [], visited = new Set(), cur = findFolder( owner, folder );
		while ( cur && ! visited.has( cur.id ) ) { visited.add( cur.id ); parts.unshift( cur.name ); cur = findFolder( owner, cur.parent_id ); }
		return ( o ? o.name : '#' + owner ) + ' #' + owner + ( folder === '' ? '' : ' / ' + ( parts.join( ' / ' ) || t( 'uncategorized' ) ) );
	}
	function mediaFrame() { return window.wp && wp.media && ( ( wp.media.frames && wp.media.frames.browse ) || wp.media.frame ); }
	function gridSelection() { try { var f = mediaFrame(); return f && f.state && f.state() && f.state().get( 'selection' ); } catch ( e ) { return null; } }
	function selected() {
		if ( document.getElementById( 'the-list' ) ) { return ids( Array.from( document.querySelectorAll( '#the-list input[name="media[]"]:checked' ) ).map( function ( cb ) { return cb.value; } ) ); }
		var s = gridSelection(); return s && s.pluck ? ids( s.pluck( 'id' ) ) : [];
	}
	function clearSelection() { var s = gridSelection(); if ( s && s.reset ) { s.reset(); } document.querySelectorAll( '#posts-filter input[type="checkbox"]' ).forEach( function ( cb ) { cb.checked = false; } ); updateSelection(); }
	function bindSelection() {
		var s = gridSelection(); if ( s && s !== selectionBound ) { if ( selectionBound ) { selectionBound.off( 'add remove reset', updateSelection ); } selectionBound = s; s.on( 'add remove reset', updateSelection ); }
	}
	function unassignedView() { return panel.dataset.unassigned === '1'; }
	// The assign entry must be findable without already knowing the trick, so in
	// the unassigned view the bar stays put and explains itself when empty.
	function updateSelection() { if ( ! toolbar ) { return; } var n = selected().length, idle = unassignedView(); toolbar.hidden = n === 0 && ! idle; toolbar.classList.toggle( 'is-idle', n === 0 ); var count = toolbar.querySelector( '.sufm-selection-count' ), label = n ? t( 'selected' ).replace( '%d', n ) : ( idle ? t( 'assignHint' ) : '' ); if ( count.textContent !== label ) { count.textContent = label; } toolbar.querySelector( '.sufm-move-selected' ).disabled = busy || n === 0; var cancel = toolbar.querySelector( '.sufm-cancel-selection' ); if ( cancel ) { cancel.hidden = n === 0; } }
	function makeToolbar() {
		toolbar = text( 'div', 'sufm-selection-toolbar' ); toolbar.hidden = true;
		toolbar.appendChild( text( 'span', 'sufm-selection-count' ) );
		// In the unassigned view the only sensible destination is a walled account,
		// so name the action after what the person is actually deciding.
		var assigning = unassignedView();
		toolbar.appendChild( button( assigning ? t( 'assignOwner' ) : t( 'moveTo' ), function () { chooseTarget( selected() ); }, 'button button-primary sufm-move-selected' ) );
		toolbar.appendChild( button( t( 'cancelSelection' ), clearSelection, 'button sufm-cancel-selection' ) );
		var host = document.querySelector( '#wpbody-content > .wrap' ) || document.querySelector( '#wpbody-content .wrap' ); if ( host ) { var anchor = host.querySelector( '.wp-filter' ) || host.querySelector( '#posts-filter' ); while ( anchor && anchor.parentNode !== host ) { anchor = anchor.parentNode; } host.insertBefore( toolbar, anchor || null ); }
	}
	function library() { try { var f = mediaFrame(); return f && ( f.library || f.state().get( 'library' ) ); } catch ( e ) { return null; } }
	function queryValues() { var u = new URL( location.href ), out = {}; [ 'sufm_owner', 'sufm_folder', 'sufm_unassigned', 'sufm_folder_mode', 'sufm_source', 'sufm_request', 'sufm_request_status' ].forEach( function ( k ) { out[ k ] = u.searchParams.has( k ) ? u.searchParams.get( k ) : ''; } ); return out; }
	function refreshMedia( navigate ) {
		var lib = library(), scroll = window.scrollY;
		if ( lib && ! document.getElementById( 'the-list' ) ) {
			var props = navigate ? queryValues() : {}; props.sufm_refresh = Date.now(); lib.props.set( props );
			if ( typeof lib.more === 'function' ) { return Promise.resolve( lib.more( { reset: true } ) ); }
			return Promise.resolve();
		}
		return fetch( location.href, { credentials: 'same-origin' } ).then( function ( r ) { if ( ! r.ok ) { throw new Error( t( 'refreshFailed' ) ); } return r.text(); } ).then( function ( html ) {
			var doc = new DOMParser().parseFromString( html, 'text/html' );
			[ '#posts-filter .wp-list-table', '#posts-filter .tablenav.top', '#posts-filter .tablenav.bottom' ].forEach( function ( selector ) { var old = document.querySelector( selector ), next = doc.querySelector( selector ); if ( old && next ) { old.replaceWith( next ); } } );
			decorateRows(); window.scrollTo( 0, scroll );
		} );
	}
	function go( params ) {
		var u = new URL( location.href ); Object.keys( params ).forEach( function ( k ) { if ( params[ k ] === '' || params[ k ] === null ) { u.searchParams.delete( k ); } else { u.searchParams.set( k, params[ k ] ); } } ); u.searchParams.delete( 'paged' );
		clearSelection(); history.pushState( {}, '', u ); panel.dataset.owner = u.searchParams.get( 'sufm_owner' ) || '0'; panel.dataset.folder = u.searchParams.get( 'sufm_folder' ) || ''; panel.dataset.unassigned = u.searchParams.get( 'sufm_unassigned' ) || '';
		// List filter submits must retain the current virtual scope, too.
		var form = document.getElementById( 'posts-filter' ); if ( form ) { Object.entries( queryValues() ).forEach( function ( pair ) { var input = form.querySelector( '[name="' + pair[ 0 ] + '"]' ); if ( ! input ) { input = document.createElement( 'input' ); input.type = 'hidden'; input.name = pair[ 0 ]; form.appendChild( input ); } input.value = pair[ 1 ]; } ); }
		render(); closeDrawer(); refreshMedia( true ).catch( function ( e ) { notify( e.message, true ); } );
	}
	function navigateOwner( id, folder ) { go( { sufm_owner: id, sufm_folder: folder, sufm_unassigned: '', sufm_folder_mode: folder === '' ? 'all' : '' } ); }
	window.addEventListener( 'popstate', function () { var u = new URL( location.href ); panel.dataset.owner = u.searchParams.get( 'sufm_owner' ) || '0'; panel.dataset.folder = u.searchParams.get( 'sufm_folder' ) || ''; panel.dataset.unassigned = u.searchParams.get( 'sufm_unassigned' ) || ''; clearSelection(); render(); refreshMedia( true ); } );
	function icon( kind ) { var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' ); svg.setAttribute( 'viewBox', '0 0 24 24' ); svg.setAttribute( 'fill', 'none' ); svg.setAttribute( 'stroke', 'currentColor' ); svg.setAttribute( 'stroke-width', '1.6' ); svg.setAttribute( 'aria-hidden', 'true' ); var p = document.createElementNS( svg.namespaceURI, 'path' ); p.setAttribute( 'd', kind === 'owner' ? 'M20 21v-2a7 7 0 0 0-14 0v2M13 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8' : 'M3 7V5a2 2 0 0 1 2-2h5l3 4h6a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z' ); svg.appendChild( p ); return svg; }
	function closeMenu() { if ( menu ) { menu.remove(); menu = null; } if ( menuReturn ) { menuReturn.classList.remove( 'is-menu-open' ); menuReturn = null; } }
	function placeMenu( x, y ) { var box = menu.getBoundingClientRect(), left = Math.max( 8, Math.min( x, innerWidth - box.width - 8 ) ), top = y;
		// Flip above the cursor when the list would run off the bottom edge.
		if ( top + box.height > innerHeight - 8 ) { top = Math.max( 8, y - box.height ); }
		menu.style.left = left + 'px'; menu.style.top = Math.max( 8, top ) + 'px'; }
	function openMenuAt( x, y, actions ) { menu = text( 'div', 'sufm-tree-menu' ); menu.setAttribute( 'role', 'menu' );
		// Not a WordPress .button: those styles make each row 56px tall, which
		// turns a five-item list into a 274px slab of padding.
		actions.forEach( function ( a ) { var b = button( a[ 0 ], function () { closeMenu(); a[ 1 ](); }, a[ 2 ] ? 'sufm-menu-item is-danger' : 'sufm-menu-item' ); b.setAttribute( 'role', 'menuitem' ); menu.appendChild( b ); } );
		document.body.appendChild( menu ); placeMenu( x, y ); var first = menu.querySelector( 'button' ); if ( first ) { first.focus(); } }
	function menuFromRow( node, x, y, actions ) { if ( ! actions ) { return; } closeMenu(); node.classList.add( 'is-menu-open' ); menuReturn = node; openMenuAt( x, y, actions ); }
	function toggle( k, owner ) { if ( open.has( k ) ) { open.delete( k ); } else { open.add( k ); if ( owner ) { loadOwner( owner ).catch( function ( e ) { notify( e.message, true ); } ); } } saveOpen(); render(); }
	function row( opts ) {
		var e = text( 'div', 'sufm-node' + ( opts.kind === 'owner' ? ' is-owner' : '' ) ); e.tabIndex = 0; e.setAttribute( 'role', 'treeitem' );
		if ( opts.active ) { e.classList.add( 'is-active' ); e.setAttribute( 'aria-current', 'true' ); }
		if ( opts.owner ) { opts.locked = opts.locked || targetLocked( opts.owner, opts.folder || 0 ); e.dataset.owner = opts.owner; e.dataset.folder = opts.folder || 0; e.classList.toggle( 'is-locked', !! opts.locked ); }
		if ( opts.expand ) { e.setAttribute( 'aria-expanded', !! opts.expanded ); }
		var caret = button( opts.expanded ? '▾' : '▸', function ( event ) { event.stopPropagation(); opts.expand(); }, 'sufm-node-caret' ); caret.setAttribute( 'aria-label', opts.expanded ? t( 'collapse' ) : t( 'expand' ) ); caret.hidden = ! opts.expand; e.appendChild( caret );
		var ico = text( 'span', 'sufm-node-ico' ); ico.appendChild( icon( opts.kind ) ); e.appendChild( ico );
		var name = text( 'span', 'sufm-node-name', opts.label ); name.title = opts.label; e.appendChild( name ); if ( opts.count != null ) { e.appendChild( text( 'span', 'sufm-node-count', opts.count ) ); }
		if ( opts.actions ) { var b = button( '⋯', function ( event ) { event.stopPropagation(); menuFromRow( e, b.getBoundingClientRect().left, b.getBoundingClientRect().bottom + 4, opts.actions ); }, 'sufm-node-more' ); b.setAttribute( 'aria-label', t( 'moreActions' ) ); b.setAttribute( 'aria-haspopup', 'menu' ); e.appendChild( b );
			// Same gesture as the front-end file manager: right-click a folder.
			e.addEventListener( 'contextmenu', function ( event ) { event.preventDefault(); menuFromRow( e, event.clientX, event.clientY, opts.actions ); } );
		}
		e.addEventListener( 'click', function ( event ) { if ( ! event.target.closest( 'button' ) ) { opts.click(); } } );
		e.addEventListener( 'keydown', function ( event ) { if ( event.target !== e ) { return; } if ( event.key === 'Enter' || event.key === ' ' ) { event.preventDefault(); opts.click(); } else if ( event.key === 'ArrowRight' && opts.expand && ! opts.expanded ) { opts.expand(); } else if ( event.key === 'ArrowLeft' && opts.expand && opts.expanded ) { opts.expand(); } else if ( opts.actions && ( event.key === 'ContextMenu' || ( event.shiftKey && event.key === 'F10' ) ) ) { event.preventDefault(); var r = e.getBoundingClientRect(); menuFromRow( e, r.left + 14, r.top + 14, opts.actions ); } } );
		if ( opts.owner && ! opts.locked ) {
			dragNodes.set( e, opts );
			e.addEventListener( 'dragleave', function ( event ) { if ( ! e.contains( event.relatedTarget ) ) { clearTimeout( hoverTimer ); hoverTimer = 0; e.classList.remove( 'is-drop', 'is-drop-before', 'is-drop-after' ); } } );
		}
		if ( opts.sortable && ! opts.locked ) { e.draggable = true; e.addEventListener( 'dragstart', function ( event ) { drag = { owner: opts.owner, folder: opts.folder }; event.dataTransfer.setData( 'application/x-sufm-folder', opts.owner + ':' + opts.folder ); event.dataTransfer.effectAllowed = 'move'; e.classList.add( 'is-dragging' ); } ); e.addEventListener( 'dragend', endDrag ); }
		return e;
	}
	function appendFolder( host, folder, owner ) {
		var k = owner.id + ':' + folder.id, li = text( 'li', 'sufm-tree-item' );
		var expanded = open.has( k ); li.appendChild( row( { kind: 'folder', label: folder.name, count: folder.count, owner: owner.id, folder: folder.id, locked: folder.locked || folder.can_drop === false, active: ownerId() === Number( owner.id ) && folderId() === String( folder.id ), sortable: true, expanded: expanded, expand: ( folder.children || [] ).length ? function () { toggle( k ); } : null, click: function () { navigateOwner( owner.id, folder.id ); }, actions: folderActions( owner, folder ) } ) );
		if ( expanded ) { var ul = text( 'ul' ); ( folder.children || [] ).forEach( function ( f ) { appendFolder( ul, f, owner ); } ); li.appendChild( ul ); } host.appendChild( li );
	}
	function render() {
		var scroll = list.scrollTop; list.replaceChildren();
		var all = text( 'li' ); all.appendChild( row( { label: t( 'allMedia' ), count: panel.dataset.total, active: ! ownerId() && ! folderId() && ! panel.dataset.unassigned, click: function () { go( { sufm_owner: '', sufm_folder: '', sufm_unassigned: '', sufm_folder_mode: '' } ); } } ) ); list.appendChild( all );
		var un = text( 'li' ); un.appendChild( row( { label: t( 'unassignedMedia' ), count: panel.dataset.unassignedCount, active: panel.dataset.unassigned === '1', click: function () { go( { sufm_owner: '', sufm_folder: '', sufm_unassigned: '1', sufm_folder_mode: '' } ); } } ) ); list.appendChild( un );
		owners.forEach( function ( owner ) {
			var li = text( 'li' ), k = owner.id + ':0', expanded = open.has( k );
			li.appendChild( row( { kind: 'owner', label: owner.name + ' #' + owner.id, count: owner.files, owner: owner.id, folder: 0, locked: owner.locked || owner.can_drop === false, active: ownerId() === Number( owner.id ) && folderId() === '', expanded: expanded, expand: function () { toggle( k, owner.id ); }, click: function () { navigateOwner( owner.id, '' ); }, actions: [ [ t( 'newFolder' ), function () { createFolder( owner, 0 ); } ], [ t( 'onlyUncategorized' ), function () { navigateOwner( owner.id, '0' ); } ] ] } ) );
			if ( expanded ) { var ul = text( 'ul' ), unfiled = text( 'li' ); unfiled.appendChild( row( { label: t( 'uncategorized' ), count: owner.unassigned, owner: owner.id, folder: 0, active: ownerId() === Number( owner.id ) && folderId() === '0', click: function () { navigateOwner( owner.id, '0' ); } } ) ); ul.appendChild( unfiled ); if ( ownerRequests[ owner.id ] ) { ul.appendChild( text( 'li', 'sufm-tree-empty', t( 'loading' ) ) ); } ( owner.tree || [] ).forEach( function ( f ) { appendFolder( ul, f, owner ); } ); li.appendChild( ul ); } list.appendChild( li );
		} );
		if ( ! owners.length ) { list.appendChild( text( 'li', 'sufm-tree-empty', loading ? t( 'loading' ) : t( 'noUsers' ) ) ); }
		if ( more ) { var next = text( 'li' ); var b = button( loading ? t( 'loading' ) : t( 'loadMore' ), function () { load( true ); }, 'button sufm-load-more' ); b.disabled = loading; next.appendChild( b ); list.appendChild( next ); }
		list.scrollTop = scroll;
		syncTreeActions();
		var path = document.getElementById( 'sufm-tree-path' ); if ( path ) { var hasPath = !! ownerId(); path.hidden = ! hasPath; if ( hasPath ) { path.textContent = pathName( ownerId(), folderId() ) + ( folderId() === '' ? ' / ' + t( 'includingChildren' ) : '' ); path.title = path.textContent; } }
	}
	function mergeOwners( incoming ) { incoming.forEach( function ( o ) { var old = ownerById( o.id ); if ( old ) { if ( loaded.has( Number( o.id ) ) && ( ! o.tree || ! o.tree.length ) && o.tree_loaded === false ) { o.tree = old.tree; } Object.assign( old, o ); } else { owners.push( o ); } } ); }
	function loadOwner( id, force ) {
		id = Number( id ); if ( ! force && loaded.has( id ) ) { return Promise.resolve( ownerById( id ) ); } if ( ownerRequests[ id ] ) { return ownerRequests[ id ]; }
		var request = api( 'admin/folders?owner_id=' + id ).then( function ( res ) { mergeOwners( res.owners || ( res.owner ? [ res.owner ] : [] ) ); loaded.add( id ); return ownerById( id ); } ).finally( function () { delete ownerRequests[ id ]; render(); } ); ownerRequests[ id ] = request; return request;
	}
	function load( append ) {
		if ( append && loading ) { return Promise.resolve(); } var token = ++generation; if ( ! append ) { page = 1; } loading = true; render();
		return api( 'admin/folders?per_page=50&page=' + ( append ? page + 1 : 1 ) + '&search=' + encodeURIComponent( search.value.trim() ) ).then( function ( res ) {
			if ( token !== generation ) { return; } if ( ! append ) { owners = []; loaded.clear(); } mergeOwners( res.owners || [] ); page = append ? page + 1 : 1; more = !! ( res.has_more || ( res.pagination && ( res.pagination.more || res.pagination.page < res.pagination.total_pages ) ) ); panel.dataset.total = res.total || 0; panel.dataset.unassignedCount = res.unassigned_count == null ? '' : res.unassigned_count;
			var expandIds = owners.filter( function ( o ) { return open.has( o.id + ':0' ) || Number( o.id ) === ownerId(); } ).map( function ( o ) { return Number( o.id ); } ); if ( ownerId() && ! expandIds.includes( ownerId() ) ) { expandIds.push( ownerId() ); } return Promise.all( expandIds.map( function ( id ) { return loadOwner( id ); } ) );
		} ).catch( function ( e ) { notify( e.message, true ); } ).finally( function () { if ( token === generation ) { loading = false; render(); } } );
	}
	function folderActions( owner, folder ) { return [ [ t( 'open' ), function () { navigateOwner( owner.id, folder.id ); } ], [ t( 'newSubfolder' ), function () { createFolder( owner, folder.id ); } ], [ t( 'rename' ), function () { var name = prompt( t( 'rename' ), folder.name ); if ( name && name !== folder.name ) { mutateFolder( 'admin/folders/' + folder.id, 'PATCH', { owner_id: owner.id, name: name }, owner.id ); } } ], [ t( 'moveRoot' ), function () { reorder( owner.id, folder.id, 0, 'inside' ); } ], [ t( 'deleteFolder' ), function () { if ( confirm( t( 'deleteFolderConfirm' ).replace( '%s', folder.name ) ) ) { mutateFolder( 'admin/folders/' + folder.id + '?owner_id=' + owner.id, 'DELETE', null, owner.id ); } }, true ] ]; }
	function mutateFolder( path, method, body, owner ) { return api( path, { method: method, body: body } ).then( function () { return loadOwner( owner, true ); } ).then( function () { render(); notify( t( 'folderUpdated' ) ); } ).catch( function ( e ) { notify( e.message, true ); } ); }
	function createFolder( owner, parent ) { var name = prompt( t( 'newFolderName' ) ); if ( name ) { open.add( owner.id + ':0' ); if ( parent ) { open.add( owner.id + ':' + parent ); } saveOpen(); mutateFolder( 'admin/folders', 'POST', { owner_id: owner.id, parent_id: parent || 0, name: name }, owner.id ); } }
	function reorder( owner, folder, target, zone ) {
		var parent = target, position = -1, f = findFolder( owner, target ); if ( zone !== 'inside' && f ) { parent = Number( f.parent_id ) || 0; var p = findFolder( owner, parent ), siblings = ( p ? p.children : ( ownerById( owner ) || {} ).tree || [] ).filter( function ( n ) { return Number( n.id ) !== Number( folder ); } ); position = siblings.findIndex( function ( n ) { return Number( n.id ) === Number( target ); } ) + ( zone === 'after' ? 1 : 0 ); }
		if ( Number( folder ) === Number( parent ) ) { return; } mutateFolder( 'admin/folders/reorder', 'POST', { owner_id: owner, folder_id: folder, parent_id: parent, position: position }, owner );
	}
	function dialog( title ) {
		if ( activeDialog ) { activeDialog.close(); } var d = document.createElement( 'dialog' ); d.className = 'sufm-dialog'; var returnFocus = document.activeElement;
		var h = text( 'h2', '', title ); h.id = 'sufm-dialog-title'; d.setAttribute( 'aria-labelledby', h.id ); d.appendChild( h ); var body = text( 'div', 'sufm-dialog-body' ), footer = text( 'div', 'sufm-dialog-footer' ); d.appendChild( body ); d.appendChild( footer ); footer.appendChild( button( t( 'cancel' ), function () { d.close(); } ) );
		d.addEventListener( 'close', function () { activeDialog = null; d.remove(); if ( returnFocus && returnFocus.isConnected ) { returnFocus.focus(); } } ); document.body.appendChild( d ); activeDialog = d; d.showModal(); return { el: d, body: body, footer: footer };
	}
	function chooseTarget( attachmentIds ) {
		if ( ! attachmentIds.length || busy ) { return; } var d = dialog( t( 'moveTo' ) ), target = null, results = text( 'div', 'sufm-targets' ), input = document.createElement( 'input' ); input.type = 'search'; input.placeholder = t( 'searchUsers' ); input.setAttribute( 'aria-label', t( 'searchUsers' ) ); d.body.appendChild( input ); d.body.appendChild( results ); var confirmButton = button( t( 'continue' ), function () { if ( target ) { d.el.close(); moveInto( attachmentIds, target.folder, target.owner ); } }, 'button button-primary' ); confirmButton.disabled = true; d.footer.appendChild( confirmButton ); var timer, request = 0, targetPage = 1;
		function choose( owner, folder, label ) { target = { owner: owner, folder: folder }; results.querySelectorAll( '.is-chosen' ).forEach( function ( e ) { e.classList.remove( 'is-chosen' ); } ); label.classList.add( 'is-chosen' ); confirmButton.disabled = false; }
		function addOwner( owner ) {
			mergeOwners( [ owner ] ); var wrap = text( 'div', 'sufm-target-owner' ), root = button( owner.name + ' #' + owner.id + ' / ' + t( 'uncategorized' ), function () { choose( owner.id, 0, root ); }, 'sufm-target' ); root.disabled = targetLocked( owner.id, 0 ); wrap.appendChild( root ); var foldersButton = button( t( 'expandFolders' ), function () { foldersButton.disabled = true; loadOwner( owner.id ).then( function ( full ) { foldersButton.remove(); function children( nodes, prefix ) { ( nodes || [] ).forEach( function ( f ) { var label = prefix + f.name, b = button( label, function () { choose( owner.id, f.id, b ); }, 'sufm-target' ); b.disabled = targetLocked( owner.id, f.id ); wrap.appendChild( b ); children( f.children, label + ' / ' ); } ); } children( full.tree, '' ); } ).catch( function ( e ) { foldersButton.disabled = false; notify( e.message, true ); } ); }, 'sufm-target-expand' ); wrap.appendChild( foldersButton ); results.appendChild( wrap );
		}
		function query( append ) { var token = ++request; if ( ! append ) { results.replaceChildren(); targetPage = 1; target = null; confirmButton.disabled = true; } return api( 'admin/folders?per_page=50&page=' + targetPage + '&search=' + encodeURIComponent( input.value.trim() ) ).then( function ( res ) { if ( token !== request || ! d.el.open ) { return; } ( res.owners || [] ).forEach( addOwner ); if ( res.has_more || ( res.pagination && res.pagination.page < res.pagination.total_pages ) ) { var next = button( t( 'loadMore' ), function () { next.remove(); targetPage++; query( true ); }, 'button' ); results.appendChild( next ); } if ( ! results.children.length ) { results.appendChild( text( 'p', '', t( 'noUsers' ) ) ); } } ).catch( function ( e ) { notify( e.message, true ); } ); }
		input.addEventListener( 'input', function () { clearTimeout( timer ); timer = setTimeout( function () { query( false ); }, 250 ); } ); query( false ); input.focus();
	}
	function bytes( n ) { n = Number( n ) || 0; return ( n / 1048576 ).toLocaleString( undefined, { maximumFractionDigits: 2 } ) + ' MiB'; }
	function moveInto( attachmentIds, folder, owner ) {
		if ( busy ) { return; } attachmentIds = ids( attachmentIds ); if ( ! attachmentIds.length ) { return; } busy = true; updateSelection();
		var body = { attachment_ids: attachmentIds, folder_id: folder, owner_id: owner };
		api( 'admin/transfers/preview', { method: 'POST', body: body } ).then( function ( preview ) {
			if ( ! preview.confirmation_required ) { return commitMove( 'admin/folders/move', body ); }
			busy = false; updateSelection(); body.idempotency_key = ( window.crypto && crypto.randomUUID ? crypto.randomUUID() : 'media-' + Date.now() + '-' + Math.random().toString( 36 ).slice( 2 ) ); var d = dialog( t( 'transferTitle' ) );
			d.body.appendChild( text( 'p', '', t( 'transferDescription' ).replace( '%d', attachmentIds.length ) ) ); d.body.appendChild( text( 'p', 'sufm-target-path', pathName( owner, folder ) ) );
			var dl = text( 'dl', 'sufm-transfer-summary' ); [ [ t( 'incomingBytes' ), bytes( preview.incoming_bytes ) ], [ t( 'usedBytes' ), bytes( preview.target_used_bytes ) ], [ t( 'reservedBytes' ), bytes( preview.target_reserved_bytes ) ], [ t( 'quotaBytes' ), bytes( preview.target_quota_bytes ) ], [ t( 'afterTransfer' ), bytes( Number( preview.target_used_bytes ) + Number( preview.incoming_bytes ) ) ] ].forEach( function ( pair ) { dl.appendChild( text( 'dt', '', pair[ 0 ] ) ); dl.appendChild( text( 'dd', '', pair[ 1 ] ) ); } ); d.body.appendChild( dl );
			var outgoing = text( 'dl', 'sufm-transfer-summary' ); ( preview.outgoing || [] ).forEach( function ( source ) { outgoing.appendChild( text( 'dt', '', t( 'sourceRelease' ).replace( '%d', Number( source.owner_id ) ) ) ); outgoing.appendChild( text( 'dd', '', bytes( source.bytes ) ) ); } ); if ( outgoing.children.length ) { d.body.appendChild( outgoing ); }
			d.body.appendChild( text( 'p', 'sufm-transfer-note', t( 'transferNote' ) ) ); var submit = button( t( 'confirmTransfer' ), function () { if ( busy ) { return; } busy = true; submit.disabled = true; updateSelection(); body.snapshot_hash = preview.snapshot_hash; body.confirmed = true; commitMove( 'admin/transfers', body ).then( function () { d.el.close(); } ).catch( function ( e ) { submit.disabled = false; notify( e.message, true ); } ); }, 'button button-primary' ); d.footer.appendChild( submit );
		} ).catch( function ( e ) { notify( e.message, true ); } ).finally( function () { busy = false; updateSelection(); } );
	}
	function commitMove( endpoint, body ) {
		return api( endpoint, { method: 'POST', body: body } ).then( function ( res ) {
			var moved = Array.isArray( res.moved ) ? res.moved.length : Number( res.moved || 0 ), unchanged = Array.isArray( res.unchanged ) ? res.unchanged.length : Number( res.unchanged || 0 ), skipped = Array.isArray( res.skipped ) ? res.skipped.length : Number( res.skipped || 0 );
			notify( t( 'moveResult' ).replace( '%m', moved ).replace( '%u', unchanged ).replace( '%s', skipped ), skipped > 0 );
			if ( ! skipped ) { clearSelection(); } return Promise.all( [ refreshMedia( false ), load( false ) ] );
		} ).finally( function () { busy = false; updateSelection(); } );
	}
	function internalDrag( event ) {
		var dt = event.dataTransfer;
		return !! drag && ! ( dt && ( Array.from( dt.types || [] ).includes( 'Files' ) || ( dt.files && dt.files.length ) ) );
	}
	function resetUploaderDropzone() {
		var frame = mediaFrame(), uploader = frame && frame.uploader && frame.uploader.uploader;
		// Core resets its private `active` flag, emits dropzone:leave and hides its
		// own view. Only invoke its namespaced handler; never synthesize a file drop.
		if ( uploader && uploader.dropzone && uploader.dropzone.triggerHandler ) { uploader.dropzone.triggerHandler( 'drop.wp-uploader' ); }
	}
	function internalDragEvent( event ) {
		if ( ! internalDrag( event ) ) { return; }
		// Core's body dropzone treats every dragover as an upload. Route our own
		// drag in capture phase so neither that handler nor plupload sees it.
		event.preventDefault(); event.stopImmediatePropagation();
		var node = event.target.closest && event.target.closest( '.sufm-node' ), opts = node && dragNodes.get( node );
		var valid = opts && ! ( drag.folder && ( Number( drag.owner ) !== Number( opts.owner ) || Number( drag.folder ) === Number( opts.folder ) ) );
		if ( event.dataTransfer ) { event.dataTransfer.dropEffect = valid ? 'move' : 'none'; }
		if ( event.type === 'drop' ) {
			try {
				if ( ! valid ) { return; }
				var zone = node.classList.contains( 'is-drop-before' ) ? 'before' : node.classList.contains( 'is-drop-after' ) ? 'after' : 'inside';
				if ( drag.folder ) { reorder( drag.owner, drag.folder, opts.folder || 0, zone ); }
				else { moveInto( drag.ids, Number( opts.folder ) || 0, Number( opts.owner ) ); }
			} finally { endDrag(); }
			return;
		}
		if ( ! valid || event.type !== 'dragover' ) { return; }
		node.classList.add( 'is-drop' );
		if ( drag.folder && opts.folder ) { var r = node.getBoundingClientRect(), y = ( event.clientY - r.top ) / r.height; node.classList.toggle( 'is-drop-before', y < .25 ); node.classList.toggle( 'is-drop-after', y > .75 ); }
		if ( opts.expand && ! opts.expanded && ! hoverTimer ) { hoverTimer = setTimeout( function () { hoverTimer = 0; opts.expand(); }, 600 ); }
	}
	[ 'dragenter', 'dragover', 'drop' ].forEach( function ( type ) { document.addEventListener( type, internalDragEvent, true ); } );
	function endDrag() { if ( drag ) { resetUploaderDropzone(); } drag = null; clearTimeout( hoverTimer ); hoverTimer = 0; document.querySelectorAll( '.is-drop,.is-drop-before,.is-drop-after,.is-dragging' ).forEach( function ( e ) { e.classList.remove( 'is-drop', 'is-drop-before', 'is-drop-after', 'is-dragging' ); } ); }
	function unassignedNotice() {
		// The unassigned view is often empty (new uploads are attributed to
		// their author), so say what the view is for instead of leaving a bare
		// "no media" panel behind.
		var empty = document.querySelector( '#wp-media-grid .no-media' );
		if ( ! unassignedView() || ! empty ) { return; }
		if ( empty.querySelector( '.sufm-unassigned-note' ) ) { return; }
		empty.appendChild( text( 'p', 'sufm-unassigned-note', t( 'unassignedNote' ) ) );
	}
	function decorateRows() {
		unassignedNotice();
		bindSelection(); document.querySelectorAll( '#the-list tr[id^="post-"], #wp-media-grid .attachments-browser .attachment[data-id]' ).forEach( function ( el ) {
			if ( el.dataset.sufmDrag ) { return; } el.dataset.sufmDrag = '1'; el.draggable = true;
			el.addEventListener( 'dragstart', function ( event ) { var id = Number( el.dataset.id || el.id.slice( 5 ) ), current = selected(), payload = current.includes( id ) ? current : [ id ]; drag = { ids: payload }; event.dataTransfer.setData( 'application/x-sufm-attachment', payload.join( ',' ) ); event.dataTransfer.effectAllowed = 'move'; var ghost = text( 'div', 'sufm-drag-ghost', t( 'dragCount' ).replace( '%d', payload.length ) ); document.body.appendChild( ghost ); event.dataTransfer.setDragImage( ghost, 12, 12 ); setTimeout( function () { ghost.remove(); }, 0 ); } ); el.addEventListener( 'dragend', endDrag );
		} ); updateSelection(); unassignedNotice();
	}
	// The native collection remains responsible for card clicks, ranges and details.
	document.addEventListener( 'change', function ( e ) { if ( e.target.matches( '#posts-filter input[type="checkbox"]' ) ) { updateSelection(); } } );
	document.addEventListener( 'click', function ( e ) { if ( menu && ! menu.contains( e.target ) && ! e.target.closest( '.sufm-node-more' ) ) { closeMenu(); } setTimeout( updateSelection, 0 ); } );
	function focusTrap( e, host ) { if ( e.key !== 'Tab' ) { return; } var nodes = Array.from( host.querySelectorAll( 'button:not([disabled]),input:not([disabled]),[tabindex="0"]' ) ).filter( function ( n ) { return n.offsetParent !== null; } ); if ( ! nodes.length ) { return; } if ( e.shiftKey && document.activeElement === nodes[ 0 ] ) { e.preventDefault(); nodes[ nodes.length - 1 ].focus(); } else if ( ! e.shiftKey && document.activeElement === nodes[ nodes.length - 1 ] ) { e.preventDefault(); nodes[ 0 ].focus(); } }
	var handle = document.getElementById( 'sufm-tree-handle' );

	function setCollapsed( collapsed ) {
		document.body.classList.toggle( 'sufm-tree-collapsed', !! collapsed );
		if ( handle ) {
			handle.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
			handle.classList.toggle( 'is-collapsed', !! collapsed );
		}
		try {
			window.localStorage.setItem( 'sufmTreeCollapsed', collapsed ? '1' : '0' );
		} catch ( e ) {}
	}

	if ( handle ) {
		try {
			if ( '1' === window.localStorage.getItem( 'sufmTreeCollapsed' ) ) {
				setCollapsed( true );
			}
		} catch ( e ) {}
		handle.addEventListener( 'click', function () {
			setCollapsed( ! document.body.classList.contains( 'sufm-tree-collapsed' ) );
			if ( ! document.body.classList.contains( 'sufm-tree-collapsed' ) ) {
				var search = document.getElementById( 'sufm-tree-search' );
				if ( search ) { search.focus(); }
			}
		} );
	}

	var drawerButton = document.getElementById( 'sufm-tree-toggle' ), shade = document.getElementById( 'sufm-tree-shade' );
	function closeDrawer() { document.body.classList.remove( 'sufm-drawer-open' ); drawerButton.setAttribute( 'aria-expanded', 'false' ); if ( drawerReturn && drawerReturn.isConnected ) { drawerReturn.focus(); drawerReturn = null; } }
	drawerButton.addEventListener( 'click', function () { drawerReturn = document.activeElement; document.body.classList.add( 'sufm-drawer-open' ); drawerButton.setAttribute( 'aria-expanded', 'true' ); search.focus(); } ); shade.addEventListener( 'click', closeDrawer ); document.getElementById( 'sufm-tree-close' ).addEventListener( 'click', closeDrawer );
	document.addEventListener( 'scroll', function () { if ( menu ) { closeMenu(); } }, true ); window.addEventListener( 'resize', function () { if ( menu ) { closeMenu(); } } );
	document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { closeMenu(); closeDrawer(); } if ( document.body.classList.contains( 'sufm-drawer-open' ) && ! activeDialog ) { focusTrap( e, panel ); } } );
	var renameBtn = document.getElementById( 'sufm-tree-rename' );
	var deleteBtn = document.getElementById( 'sufm-tree-delete' );
	var subBtn = document.getElementById( 'sufm-tree-subfolder' );

	function currentFolderNode() {
		// findFolder() takes an owner id, not the owner object: passing the
		// object made the lookup fail and left the toolbar disabled.
		if ( ! ownerById( ownerId() ) || ! folderId() ) {
			return null;
		}
		return findFolder( ownerId(), Number( folderId() ) );
	}

	function syncTreeActions() {
		var node = currentFolderNode();
		[ renameBtn, deleteBtn, subBtn ].forEach( function ( btn ) {
			if ( btn ) {
				btn.disabled = ! node;
			}
		} );
	}

	if ( renameBtn ) {
		renameBtn.addEventListener( 'click', function () {
			var node = currentFolderNode();
			if ( ! node ) { return; }
			var name = prompt( t( 'rename' ), node.name );
			if ( name && name !== node.name ) {
				mutateFolder( 'admin/folders/' + node.id, 'PATCH', { owner_id: ownerId(), name: name }, ownerId() );
			}
		} );
	}
	if ( deleteBtn ) {
		deleteBtn.addEventListener( 'click', function () {
			var node = currentFolderNode();
			if ( ! node ) { return; }
			if ( confirm( t( 'deleteFolderConfirm' ).replace( '%s', node.name ) ) ) {
				mutateFolder( 'admin/folders/' + node.id + '?owner_id=' + ownerId(), 'DELETE', null, ownerId() );
			}
		} );
	}
	if ( subBtn ) {
		subBtn.addEventListener( 'click', function () {
			var node = currentFolderNode();
			var owner = ownerById( ownerId() );
			if ( node && owner ) {
				createFolder( owner, node.id );
			}
		} );
	}

	document.getElementById( 'sufm-tree-new' ).addEventListener( 'click', function () { var o = ownerById( ownerId() ); if ( o ) { createFolder( o, Number( folderId() ) || 0 ); } else { notify( t( 'chooseUserFirst' ), true ); } } );
	var searchTimer; search.addEventListener( 'input', function () { clearTimeout( searchTimer ); searchTimer = setTimeout( function () { load( false ); }, 250 ); } );
	// Keep the existing own-user upload destination; new uploads carry the folder
	// through core's signed multipart request, rather than a second transfer.
	var uploadFolder = 0, uploadDone = 0, uploadFailed = 0;
	var uploadPanel = null, uploadList = null, uploadCount = null, uploadRows = {}, uploadTotal = 0, uploadHideTimer = 0;
	function uploadTitle( key, fallback ) { return strings[ key ] || fallback; }
	// A server-side rejection still arrives as HTTP 200: the REST/async-upload
	// response says `success:false`, or core's HTML error block. Both are
	// failures, so the panel must not count them as completed files.
	function uploadAccepted( response ) {
		var raw = response && typeof response === 'object' && 'response' in response ? response.response : response;
		if ( raw && typeof raw === 'object' ) { return raw.success !== false; }
		if ( typeof raw !== 'string' ) { return true; }
		var body = raw.replace( /^\s+/, '' );
		if ( body.charAt( 0 ) === '{' ) {
			try { var parsed = JSON.parse( body ); return parsed.success !== false; } catch ( e ) { return true; }
		}
		return ! /media-upload-error|error-div/.test( body );
	}
	function ensureUploadPanel() {
		if ( uploadPanel ) { return uploadPanel; }
		uploadPanel = text( 'div', 'sufm-upload-progress' );
		uploadPanel.setAttribute( 'role', 'status' );
		uploadPanel.setAttribute( 'aria-live', 'polite' );
		uploadPanel.hidden = true;
		var head = text( 'div', 'sufm-upload-progress-head' );
		var title = text( 'strong', 'sufm-upload-progress-title', uploadTitle( 'uploadingTitle', '正在上传' ) );
		uploadCount = text( 'span', 'sufm-upload-progress-count', '0 / 0' );
		var close = button( '\u00d7', function () { uploadPanel.hidden = true; }, 'sufm-upload-progress-close' );
		close.setAttribute( 'aria-label', uploadTitle( 'collapse', '收起' ) );
		head.appendChild( title ); head.appendChild( uploadCount ); head.appendChild( close );
		uploadList = text( 'div', 'sufm-upload-progress-list' );
		uploadPanel.appendChild( head ); uploadPanel.appendChild( uploadList );
		document.body.appendChild( uploadPanel );
		return uploadPanel;
	}
	function setUploadTitle( textValue, failed ) {
		if ( ! uploadPanel ) { return; }
		var title = uploadPanel.querySelector( '.sufm-upload-progress-title' );
		if ( title ) { title.textContent = textValue; }
		uploadPanel.classList.toggle( 'is-error', !! failed );
	}
	function updateUploadCount() { if ( uploadCount ) { uploadCount.textContent = uploadDone + ' / ' + uploadTotal; } }
	function uploadItem( file ) {
		ensureUploadPanel();
		var key = String( file.id || file.name ), hit = uploadRows[ key ];
		if ( hit ) { return hit; }
		var row = text( 'div', 'sufm-upload-item' );
		var name = text( 'span', 'sufm-upload-item-name', file.name );
		name.title = file.name;
		var pct = text( 'span', 'sufm-upload-item-pct', '0%' );
		var bar = text( 'span', 'sufm-upload-item-bar' ), fill = text( 'i' );
		bar.appendChild( fill );
		row.appendChild( name ); row.appendChild( pct ); row.appendChild( bar );
		uploadList.appendChild( row );
		hit = uploadRows[ key ] = { row: row, pct: pct, fill: fill };
		return hit;
	}
	function markUpload( file, percent, state ) {
		var item = uploadItem( file );
		if ( ! item ) { return; }
		var value = Math.max( 0, Math.min( 100, Math.round( percent ) ) );
		item.fill.style.width = value + '%';
		item.pct.textContent = value + '%';
		item.row.classList.toggle( 'is-done', state === 'done' );
		item.row.classList.toggle( 'is-error', state === 'error' );
	}
	function showUploadPanel() {
		ensureUploadPanel();
		uploadPanel.hidden = false;
		clearTimeout( uploadHideTimer );
	}
	function finishUploadPanel( failed ) {
		updateUploadCount();
		setUploadTitle( failed ? uploadTitle( 'uploadFailedTitle', '上传失败' ) : uploadTitle( 'uploadedTitle', '上传完成' ), failed );
		clearTimeout( uploadHideTimer );
		uploadHideTimer = setTimeout( function () { if ( uploadPanel ) { uploadPanel.hidden = true; } }, failed ? 9000 : 4000 );
	}
	function bindUpload( uploader ) {
		if ( ! uploader || ! uploader.param || uploader.sufmTargetBound ) { return; }
		uploader.sufmTargetBound = true;
		function setTarget() { uploader.param( { sufm_folder: uploadFolder, sufm_owner: Number( data.userId ) } ); }
		setTarget();
		if ( uploader.uploader && uploader.uploader.bind ) {
			var pl = uploader.uploader;
			pl.bind( 'BeforeUpload', setTarget );
			pl.bind( 'FilesAdded', function ( up, files ) {
				ensureUploadPanel();
				if ( up.files.length === ( files || [] ).length ) { uploadRows = {}; uploadList.innerHTML = ''; }
				uploadTotal = up.files.length;
				uploadDone = 0;
				uploadFailed = 0;
				( files || [] ).forEach( function ( file ) { uploadItem( file ); } );
				showUploadPanel(); setUploadTitle( uploadTitle( 'uploadingTitle', '正在上传' ), false ); updateUploadCount();
			} );
			pl.bind( 'UploadProgress', function ( up, file ) { markUpload( file, file.percent || 0 ); } );
			pl.bind( 'FileUploaded', function ( up, file, response ) {
				if ( ! uploadAccepted( response ) ) {
					uploadFailed++;
					markUpload( file, 100, 'error' );
					setUploadTitle( uploadTitle( 'uploadFailedTitle', '上传失败' ), true );
					updateUploadCount();
					return;
				}
				uploadDone++;
				markUpload( file, 100, 'done' );
				updateUploadCount();
			} );
			pl.bind( 'UploadComplete', function () {
				uploadTotal = Math.max( uploadTotal, uploadDone + uploadFailed );
				// Judge every visible row, not only this batch: a rejection from
				// an earlier file still means the list is not all-success.
				var failedRows = uploadPanel ? uploadPanel.querySelectorAll( '.sufm-upload-item.is-error' ).length : 0;
				finishUploadPanel( failedRows > 0 );
				uploadDone = 0;
				uploadFailed = 0;
				setTimeout( function () {
					Promise.resolve( refreshMedia( false ) ).catch( function () {} ).then( function () { load( false ); } );
				}, 350 );
			} );
			pl.bind( 'Error', function ( up, error ) {
				var file = error && error.file ? error.file : null;
				uploadFailed++;
				if ( file ) { markUpload( file, 100, 'error' ); }
				setUploadTitle( uploadTitle( 'uploadFailedTitle', '上传失败' ), true );
				notify( ( strings.uploadFailed || '上传失败：%s' ).replace( '%s', ( error && error.message ) || '' ), true );
			} );
		}
	}
	if ( window.wp && wp.Uploader ) { var originalInit = wp.Uploader.prototype.init; wp.Uploader.prototype.init = function () { if ( originalInit ) { originalInit.apply( this, arguments ); } bindUpload( this ); }; }
	function fillTargets( select, owner ) {
		function add( nodes, prefix ) { ( nodes || [] ).forEach( function ( f ) { var opt = text( 'option', '', prefix + f.name ); opt.value = f.id; opt.disabled = !! f.locked; select.appendChild( opt ); add( f.children, prefix + f.name + ' / ' ); } ); }
		add( owner && owner.tree, '' );
		select.value = String( uploadFolder );
	}
	function mountPicker( host, select, frame ) {
		if ( ! host || host.querySelector( '.sufm-modal-folder' ) ) { return; }
		var label = text( 'label', 'sufm-modal-folder', t( 'uploadTarget' ) ); label.appendChild( select ); host.appendChild( label );
		loadOwner( Number( data.userId ) ).then( function ( owner ) { fillTargets( select, owner ); } ).catch( function ( e ) { notify( e.message, true ); } );
		select.addEventListener( 'change', function () { uploadFolder = Number( select.value ) || 0; if ( frame && frame.uploader ) { var up = frame.uploader.uploader; bindUpload( up ); if ( up && up.param ) { up.param( { sufm_folder: uploadFolder, sufm_owner: Number( data.userId ) } ); } } } );
	}
	function uploadPicker() {
		var frame = mediaFrame(); if ( frame && frame.uploader ) { bindUpload( frame.uploader.uploader ); }
		// Only a real uploader owns .upload-ui; attachment details reuse
		// .media-frame-content, so anchoring on that put an upload control in
		// the details rail (1.18.44).
		var modal = document.querySelector( '.media-modal' );
		var modalHost = modal && modal.querySelector( '.upload-ui' );
		if ( modalHost ) {
			var modalSelect = document.createElement( 'select' ); modalSelect.setAttribute( 'aria-label', t( 'uploadTarget' ) ); var modalRoot = text( 'option', '', t( 'uploadRoot' ) ); modalRoot.value = '0'; modalSelect.appendChild( modalRoot );
			mountPicker( modalHost, modalSelect, frame );
		}
		var inline = document.querySelector( '.uploader-inline .upload-ui' );
		if ( inline ) {
			if ( ! inline.querySelector( '.sufm-inline-folder' ) ) {
				var inlineSelect = document.createElement( 'select' ); inlineSelect.setAttribute( 'aria-label', t( 'uploadTarget' ) ); var inlineRoot = text( 'option', '', t( 'uploadRoot' ) ); inlineRoot.value = '0'; inlineSelect.appendChild( inlineRoot );
				var pill = text( 'div', 'sufm-modal-folder sufm-inline-folder' ); pill.appendChild( text( 'span', 'sufm-inline-folder-label', t( 'uploadTarget' ) ) ); pill.appendChild( inlineSelect );
				var wrap = text( 'div', 'sufm-inline-folder-bar' ); wrap.appendChild( pill );
				inline.insertBefore( wrap, inline.firstChild );
				loadOwner( Number( data.userId ) ).then( function ( owner ) { fillTargets( inlineSelect, owner ); } ).catch( function ( e ) { notify( e.message, true ); } );
				inlineSelect.addEventListener( 'change', function () { uploadFolder = Number( inlineSelect.value ) || 0; var f = mediaFrame(); if ( f && f.uploader ) { var up = f.uploader.uploader; bindUpload( up ); if ( up && up.param ) { up.param( { sufm_folder: uploadFolder, sufm_owner: Number( data.userId ) } ); } } } );
			}
		}
	}
	/* Delete feedback: WordPress removes rows silently, so report the outcome (1.18.39). */
	var delPending = { done: 0, failed: 0, timer: 0 };
	function flushDeletes() {
		var done = delPending.done, failed = delPending.failed;
		delPending.done = 0; delPending.failed = 0; delPending.timer = 0;
		if ( ! done && ! failed ) { return; }
		if ( failed ) {
			notify( t( 'deletePartial' ).replace( '%d', done ).replace( '%s', failed ), true );
		} else {
			notify( t( 'deleteDone' ).replace( '%d', done ) );
		}
		load( false );
	}
	function trackDelete( ok ) {
		if ( ok ) { delPending.done += 1; } else { delPending.failed += 1; }
		clearTimeout( delPending.timer );
		delPending.timer = setTimeout( flushDeletes, 420 );
	}
	if ( window.jQuery ) {
		jQuery( document ).ajaxSuccess( function ( event, xhr, settings ) {
			if ( ! settings || String( settings.data || '' ).indexOf( 'action=delete-post' ) < 0 ) { return; }
			var body = String( xhr && xhr.responseText ? xhr.responseText : '' ).trim();
			trackDelete( body === '1' );
		} );
	}
	document.body.classList.add( 'sufm-tree-active', 'sufm-tree-open' ); makeToolbar(); load( false ); decorateRows();
	var queued = false; new MutationObserver( function () { if ( queued ) { return; } queued = true; requestAnimationFrame( function () { queued = false; decorateRows(); uploadPicker(); } ); } ).observe( document.body, { subtree: true, childList: true } );
	if ( window.jQuery ) { jQuery( document ).ajaxComplete( function ( event, xhr, settings ) { if ( settings && String( settings.data || '' ).includes( 'action=upload-attachment' ) ) { load( false ); } } ); }
} )();

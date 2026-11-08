( function () {
	'use strict';

	var data = window.sufmFolders;
	if ( ! data ) {
		return;
	}

	var strings = data.strings || {};
	var target = 0;
	var folders = null;
	var toast = null;
	var toastTimer = 0;
	var uploaded = 0;
	var panel = null, panelList = null, panelCount = null, panelTitle = null, panelRows = {}, panelTotal = 0, panelDone = 0, panelFailed = 0, panelHide = 0;

	function api( path ) {
		return fetch( data.restUrl + path, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': data.nonce }
		} ).then( function ( res ) {
			return res.json().then( function ( body ) {
				if ( ! res.ok ) {
					throw new Error( ( body && body.message ) || strings.requestFailed || '请求失败，请重试。' );
				}
				return body;
			} );
		} );
	}

	function flatten( nodes, depth, out ) {
		( nodes || [] ).forEach( function ( node ) {
			out.push( { id: node.id, name: new Array( depth + 1 ).join( '　' ) + node.name, locked: !! node.locked } );
			flatten( node.children, depth + 1, out );
		} );
		return out;
	}

	function ownFolders() {
		if ( folders ) {
			return Promise.resolve( folders );
		}
		return api( 'folders' ).then( function ( res ) {
			folders = flatten( res && res.tree, 0, [] );
			return folders;
		} ).catch( function () {
			return null;
		} );
	}

	function ensurePanel() {
		if ( panel ) { return panel; }
		panel = document.createElement( 'div' );
		panel.className = 'sufm-upload-progress';
		panel.setAttribute( 'role', 'status' );
		panel.setAttribute( 'aria-live', 'polite' );
		panel.hidden = true;
		var head = document.createElement( 'div' );
		head.className = 'sufm-upload-progress-head';
		panelTitle = document.createElement( 'strong' );
		panelTitle.className = 'sufm-upload-progress-title';
		panelTitle.textContent = strings.uploadingTitle || '正在上传';
		panelCount = document.createElement( 'span' );
		panelCount.className = 'sufm-upload-progress-count';
		panelCount.textContent = '0 / 0';
		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'sufm-upload-progress-close';
		close.setAttribute( 'aria-label', strings.collapse || '收起' );
		close.textContent = '\u00d7';
		close.addEventListener( 'click', function () { panel.hidden = true; } );
		head.appendChild( panelTitle );
		head.appendChild( panelCount );
		head.appendChild( close );
		panelList = document.createElement( 'div' );
		panelList.className = 'sufm-upload-progress-list';
		panel.appendChild( head );
		panel.appendChild( panelList );
		document.body.appendChild( panel );
		return panel;
	}
	function panelItem( file ) {
		ensurePanel();
		var key = String( file.id || file.name );
		if ( panelRows[ key ] ) { return panelRows[ key ]; }
		var row = document.createElement( 'div' );
		row.className = 'sufm-upload-item';
		var name = document.createElement( 'span' );
		name.className = 'sufm-upload-item-name';
		name.textContent = file.name;
		name.title = file.name;
		var pct = document.createElement( 'span' );
		pct.className = 'sufm-upload-item-pct';
		pct.textContent = '0%';
		var bar = document.createElement( 'span' );
		bar.className = 'sufm-upload-item-bar';
		var fill = document.createElement( 'i' );
		bar.appendChild( fill );
		row.appendChild( name );
		row.appendChild( pct );
		row.appendChild( bar );
		panelList.appendChild( row );
		panelRows[ key ] = { row: row, pct: pct, fill: fill };
		return panelRows[ key ];
	}
	function panelProgress( file, percent ) {
		var item = panelItem( file );
		var value = Math.max( 0, Math.min( 100, Math.round( percent || 0 ) ) );
		item.fill.style.width = value + '%';
		item.pct.textContent = value + '%';
	}
	function panelMark( file, state ) {
		var item = panelItem( file );
		item.fill.style.width = '100%';
		item.pct.textContent = '100%';
		item.row.classList.toggle( 'is-done', state === 'done' );
		item.row.classList.toggle( 'is-error', state === 'error' );
	}
	function panelUpdate() { if ( panelCount ) { panelCount.textContent = panelDone + ' / ' + panelTotal; } }
	// Rejections come back as HTTP 200 with a `<pre>`-wrapped error or an
	// `error-div` block; core's handler treats those as failures, so do we.
	function uploadAccepted( response ) {
		var raw = response && typeof response === 'object' && 'response' in response ? response.response : response;
		if ( raw && typeof raw === 'object' ) { return raw.success !== false; }
		if ( typeof raw !== 'string' ) { return true; }
		var body = raw.replace( /^<pre>/, '' ).replace( /<\/pre>$/, '' );
		if ( /media-upload-error|error-div/.test( body ) ) { return false; }
		try { return JSON.parse( body ).success !== false; } catch ( e ) { return true; }
	}
	function notify( message, error ) {
		if ( ! toast ) {
			toast = document.createElement( 'div' );
			toast.className = 'sufm-upload-toast';
			toast.setAttribute( 'role', 'status' );
			toast.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( toast );
		}
		toast.textContent = message;
		toast.classList.toggle( 'is-error', !! error );
		toast.hidden = false;
		window.clearTimeout( toastTimer );
		toastTimer = window.setTimeout( function () {
			toast.hidden = true;
		}, 6000 );
	}

	function inject() {
		var host = document.querySelector( '.wrap' );
		if ( ! host || host.querySelector( '#sufm-new-folder' ) ) {
			return;
		}
		if ( ! document.querySelector( '#plupload-upload-ui, #drag-drop-area' ) ) {
			return;
		}

		var box = document.createElement( 'div' );
		box.className = 'sufm-upload-folder';
		var label = document.createElement( 'label' );
		label.setAttribute( 'for', 'sufm-new-folder' );
		label.textContent = strings.uploadTo || '上传到文件夹';
		var select = document.createElement( 'select' );
		select.id = 'sufm-new-folder';
		var root = document.createElement( 'option' );
		root.value = '0';
		root.textContent = strings.uncategorized || '我的文件（根目录）';
		select.appendChild( root );
		label.appendChild( select );
		box.appendChild( label );
		var hint = document.createElement( 'span' );
		hint.className = 'sufm-upload-folder-hint';
		hint.hidden = true;
		box.appendChild( hint );

		var heading = host.querySelector( 'h1' );
		if ( heading && heading.parentNode === host && heading.nextSibling ) {
			host.insertBefore( box, heading.nextSibling );
		} else if ( heading && heading.parentNode === host ) {
			host.appendChild( box );
		} else {
			host.insertBefore( box, host.firstChild );
		}

		ownFolders().then( function ( list ) {
			if ( ! list ) {
				box.hidden = true;
				return;
			}
			list.forEach( function ( folder ) {
				var option = document.createElement( 'option' );
				option.value = folder.id;
				option.textContent = folder.name;
				option.disabled = !! folder.locked;
				select.appendChild( option );
			} );
			if ( ! list.length ) {
				hint.textContent = strings.noFolders || '';
				hint.hidden = false;
			}
		} );

		select.addEventListener( 'change', function () {
			target = Number( select.value ) || 0;
		} );
	}

	function bindUploader() {
		var uploader = window.uploader;
		if ( ! uploader || ! uploader.bind || uploader.sufmBound ) {
			return false;
		}
		uploader.sufmBound = true;
		uploader.bind( 'BeforeUpload', function ( up ) {
			up.settings.multipart_params = up.settings.multipart_params || {};
			up.settings.multipart_params.sufm_folder = String( target );
			up.settings.multipart_params.sufm_owner = String( data.userId || 0 );
		} );
		uploader.bind( 'FilesAdded', function ( up, files ) {
			ensurePanel();
			if ( up.files.length === ( files || [] ).length ) {
				panelRows = {};
				panelList.textContent = '';
				panelDone = 0;
				panelFailed = 0;
			}
			panelTotal = up.files.length;
			( files || [] ).forEach( function ( file ) { panelItem( file ); } );
			panel.hidden = false;
			panel.classList.remove( 'is-error' );
			panelTitle.textContent = strings.uploadingTitle || '正在上传';
			panelUpdate();
		} );
		uploader.bind( 'UploadProgress', function ( up, file ) {
			panelProgress( file, file.percent );
		} );
		uploader.bind( 'FileUploaded', function ( up, file, response ) {
			if ( ! uploadAccepted( response ) ) {
				panelFailed++;
				panelMark( file, 'error' );
				panel.hidden = false;
				panel.classList.add( 'is-error' );
				panelTitle.textContent = strings.uploadFailedTitle || '上传失败';
				panelUpdate();
				return;
			}
			panelDone++;
			panelMark( file, 'done' );
			panelUpdate();
			uploaded++;
		} );
		uploader.bind( 'UploadComplete', function () {
			// A rejected file only shows up here, never as `FileUploaded`, so a
			// batch with any rejection must not be labelled as completed.
			// Judge the whole visible list, not just this batch: an earlier
			// rejected row still deserves the failure wording.
			var failedRows = panel ? panel.querySelectorAll( '.sufm-upload-item.is-error' ).length : 0;
			if ( uploaded > 0 || failedRows > 0 ) {
				panelTotal = Math.max( panelTotal, panelDone + panelFailed );
				panelTitle.textContent = failedRows > 0
					? ( strings.uploadFailedTitle || '上传失败' )
					: ( strings.uploadedTitle || '上传完成' );
				panel.classList.toggle( 'is-error', failedRows > 0 );
				panelUpdate();
				clearTimeout( panelHide );
				panelHide = setTimeout( function () { if ( panel ) { panel.hidden = true; } }, panelFailed > 0 ? 9000 : 4000 );
			}
			uploaded = 0;
			panelFailed = 0;
		} );
		uploader.bind( 'Error', function ( up, error ) {
			var file = error && error.file ? error.file : null;
			panelFailed++;
			if ( file ) { panelMark( file, 'error' ); }
			panel.hidden = false;
			panel.classList.add( 'is-error' );
			panelTitle.textContent = strings.uploadFailedTitle || '上传失败';
			panelUpdate();
			notify( ( strings.uploadFailed || '上传失败：%s' ).replace( '%s', ( error && error.message ) || '' ), true );
		} );
		return true;
	}

	if ( ! bindUploader() ) {
		var tries = 0;
		var poll = window.setInterval( function () {
			if ( bindUploader() || ++tries > 40 ) {
				window.clearInterval( poll );
			}
		}, 150 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', inject );
	} else {
		inject();
	}
} )();

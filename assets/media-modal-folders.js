( function () {
	'use strict';

	var data = window.sufmFolders;
	if ( ! data || ! window.wp || ! wp.media ) {
		return;
	}

	var strings = data.strings || {};
	var target = 0;
	var folders = null;
	var uploader = null;
	// The same modal can be rebuilt several times per page: keep label/select
	// pairs addressable with a unique id.
	var seq = 0;

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

	function own() {
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

	function bind( instance ) {
		if ( ! instance || ! instance.bind || instance.sufmBound ) {
			return;
		}
		instance.sufmBound = true;
		instance.bind( 'BeforeUpload', function ( up ) {
			up.settings.multipart_params = up.settings.multipart_params || {};
			up.settings.multipart_params.sufm_folder = String( target );
			up.settings.multipart_params.sufm_owner = String( data.userId || 0 );
		} );
	}

	function inject() {
		var root = document.querySelector( '.media-modal .upload-ui' );
		if ( ! root || root.querySelector( '.sufm-modal-folder' ) ) {
			return;
		}

		seq += 1;

		var box = document.createElement( 'div' );
		box.className = 'sufm-modal-folder';

		// A folder glyph turns a bare label + select into a single destination chip.
		var icon = document.createElement( 'span' );
		icon.className = 'sufm-modal-folder-ico';
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.2l2 2.2h7.8A2.5 2.5 0 0 1 21 9.7v7.8A2.5 2.5 0 0 1 18.5 20h-13A2.5 2.5 0 0 1 3 17.5z"/></svg>';

		var select = document.createElement( 'select' );
		select.id = 'sufm-modal-folder-' + seq;
		select.className = 'sufm-modal-folder-select';

		var label = document.createElement( 'label' );
		label.className = 'sufm-modal-folder-label';
		label.setAttribute( 'for', select.id );
		label.textContent = strings.uploadTo || '上传到文件夹';

		var rootOption = document.createElement( 'option' );
		rootOption.value = '0';
		rootOption.textContent = strings.uncategorized || '我的文件（根目录）';
		select.appendChild( rootOption );
		box.appendChild( icon );
		box.appendChild( label );
		box.appendChild( select );
		root.insertBefore( box, root.firstChild );

		own().then( function ( list ) {
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
			select.value = String( target );
		} );

		select.addEventListener( 'change', function () {
			target = Number( select.value ) || 0;
			if ( uploader ) {
				uploader.param( { sufm_folder: target, sufm_owner: Number( data.userId || 0 ) } );
			}
		} );
	}

	if ( ! wp.Uploader.prototype.sufmModalPatched ) {
		wp.Uploader.prototype.sufmModalPatched = true;
		var original = wp.Uploader.prototype.init;
		wp.Uploader.prototype.init = function () {
			var result = original.apply( this, arguments );
			uploader = this;
			if ( this.uploader ) {
				bind( this.uploader );
			}
			return result;
		};
	}

	var observer = new MutationObserver( function () {
		if ( document.querySelector( '.media-modal .upload-ui' ) ) {
			window.setTimeout( inject, 60 );
		}
	} );

	observer.observe( document.body, { childList: true, subtree: true } );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			window.setTimeout( inject, 200 );
		} );
	} else {
		window.setTimeout( inject, 200 );
	}
} )();

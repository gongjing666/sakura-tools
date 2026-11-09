/**
 * User file space — user center tab.
 *
 * Talks to the sufm/v1 REST namespace only. Every piece of server copy that
 * matters (quota, notice text, strings) comes from window.sufmData.
 */
( function () {
	'use strict';

	function start( root ) {
	root.dataset.sufmStarted = '1';

	var app = root;
	var data = window.sufmData;
	var strings = data.strings || {};
	var notice = data.notice || { version: '', text: '' };
	var policy = null;

	// A narrow host (the editor panel) keeps the compact rendering even when
	// the viewport itself is wide: the card is 400px, not the 1200px the desk
	// layout was written for.
	var compact = root.hasAttribute( 'data-sufm-compact' );
	var deskMedia = ( ! compact && window.matchMedia ) ? window.matchMedia( '(min-width: 1200px)' ) : null;

	var state = {
		folder: 0,
		filter: 'all',
		search: '',
		breadcrumb: [],
		scope: 'folder',
		cursor: 0,
		files: [],
		folders: [],
		next: 0,
		desk: !!( deskMedia && deskMedia.matches ),
		view: localStorage.getItem( 'sufm_view' ) === 'grid' ? 'grid' : 'list',
		folded: localStorage.getItem( 'sufm_folders' ) === 'folded',
		overQuota: false,
		selection: {},
		selectedCount: 0,
		anchor: 0,
		pointerInside: false,
		loading: false,
		requestsMode: false,
		requestPage: 1,
		pending: 0,
		uploading: 0,
		queue: []
	};

	var el = {
		used: document.getElementById( 'sufm-used' ),
		quota: document.getElementById( 'sufm-quota' ),
		free: document.getElementById( 'sufm-free' ),
		track: document.getElementById( 'sufm-track' ),
		progress: document.getElementById( 'sufm-progress' ),
		counts: document.getElementById( 'sufm-counts' ),
		usedMini: document.getElementById( 'sufm-used-mini' ),
		quotaMini: document.getElementById( 'sufm-quota-mini' ),
		trackMini: document.getElementById( 'sufm-track-mini' ),
		progressMini: document.getElementById( 'sufm-progress-mini' ),
		crumbs: document.getElementById( 'sufm-crumbs' ),
		pathCount: document.getElementById( 'sufm-path-count' ),
		folders: document.getElementById( 'sufm-folders' ),
		files: document.getElementById( 'sufm-files' ),
		drop: document.getElementById( 'sufm-drop' ),
		dropSelect: document.getElementById( 'sufm-drop-select' ),
		input: document.getElementById( 'sufm-file-input' ),
		search: document.getElementById( 'sufm-search' ),
		searchClear: document.querySelector( '[data-sufm-search-clear]' ),
		batch: document.getElementById( 'sufm-batch' ),
		batchLabel: document.getElementById( 'sufm-batch-label' ),
		alert: document.getElementById( 'sufm-alert' ),
		alertText: document.getElementById( 'sufm-alert-text' ),
		alertRetry: document.getElementById( 'sufm-alert-retry' ),
		footer: document.getElementById( 'sufm-footer-count' ),
		toast: document.getElementById( 'sufm-toast' ),
		queue: document.getElementById( 'sufm-queue' ),
		live: document.getElementById( 'sufm-live' ),
		requestCount: document.getElementById( 'sufm-request-count' ),
		maxFile: document.getElementById( 'sufm-max-file' ),
		modal: document.getElementById( 'sufm-modal' ),
		modalForm: document.getElementById( 'sufm-modal-form' ),
		modalTitle: document.getElementById( 'sufm-modal-title' ),
		modalBody: document.getElementById( 'sufm-modal-body' ),
		modalConfirm: document.getElementById( 'sufm-modal-confirm' )
	};

	var modalConfirm = null;
	var moveClickHandler = null;
	var moveDblHandler = null;
	var retryAction = null;
	var toastTimer = 0;
	var searchTimer = 0;

	function syncSearchClear() {
		if ( el.searchClear ) {
			el.searchClear.hidden = '' === el.search.value;
		}
	}
	var dragMove = null;

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	function api( path, options ) {
		var opts = options || {};
		var headers = { 'X-WP-Nonce': data.nonce };

		if ( opts.body && ! ( opts.body instanceof FormData ) ) {
			headers['Content-Type'] = 'application/json';
		}

		return fetch( data.restUrl + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: opts.body instanceof FormData ? opts.body : ( opts.body ? JSON.stringify( opts.body ) : undefined )
		} ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				if ( ! response.ok ) {
					var message = payload && payload.message ? payload.message : '请求失败，请稍后重试。';
					var error = new Error( message );
					error.payload = payload;
					throw error;
				}
				return payload;
			} );
		} );
	}

	function bytes( value ) {
		var n = Number( value ) || 0;
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;

		while ( n >= 1024 && i < units.length - 1 ) {
			n = n / 1024;
			i++;
		}

		return ( i === 0 ? n : n.toFixed( n >= 10 ? 0 : 1 ) ) + ' ' + units[ i ];
	}

	function dateLabel( value ) {
		return value ? String( value ).slice( 0, 10 ) : '—';
	}

	function escapeHtml( value ) {
		return String( value == null ? '' : value ).replace( /[&<>"']/g, function ( ch ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ ch ];
		} );
	}

	function groupFor( mime ) {
		mime = String( mime || '' );
		if ( mime.indexOf( 'image/' ) === 0 ) { return 'image'; }
		if ( mime.indexOf( 'video/' ) === 0 ) { return 'video'; }
		if ( mime.indexOf( 'audio/' ) === 0 ) { return 'audio'; }
		if ( /zip|rar|7z|tar|gzip/.test( mime ) ) { return 'archive'; }
		return 'document';
	}

	function typeLabel( mime, name ) {
		var ext = String( name || '' ).split( '.' ).pop().toUpperCase();
		if ( ext && ext.length <= 4 ) { return ext; }
		return { image: 'IMG', video: 'VID', audio: 'AUD', archive: 'ZIP', document: 'DOC' }[ groupFor( mime ) ];
	}

	function folderArt() {
		// Same path as the media library tree (media-folders.js), so a folder
		// looks identical on both surfaces and only the palette differs.
		return '<span class="sufm-folder-art" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M3 7V5a2 2 0 0 1 2-2h5l3 4h6a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg></span>';
	}
	function icon( name ) {
		return '<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-' + name + '"></use></svg>';
	}

	function toast( message, isError ) {
		if ( ! el.toast ) { return; }
		el.toast.textContent = message;
		el.toast.hidden = false;
		el.toast.classList.toggle( 'is-error', !! isError );
		window.clearTimeout( toastTimer );
		toastTimer = window.setTimeout( function () {
			el.toast.hidden = true;
		}, isError ? 6000 : 3200 );
	}

	function announce( message ) {
		if ( el.live ) {
			el.live.textContent = message;
		}
	}

	function showError( message, retry ) {
		if ( ! el.alert ) { return; }
		el.alert.hidden = ! message;
		el.alertText.textContent = message || '';
		retryAction = retry || null;
	}

	function setBusy( busy ) {
		state.loading = busy;
		if ( el.files ) {
			el.files.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		}
	}

	function empty( title, hint, actionLabel, action ) {
		return '<div class="sufm-empty">' + icon( 'folder' ) +
			'<p><b>' + escapeHtml( title ) + '</b></p>' +
			'<p>' + escapeHtml( hint ) + '</p>' +
			( actionLabel ? '<button type="button" class="sufm-btn sufm-btn-sm" data-sufm-empty-action="' + escapeHtml( action || 'upload' ) + '">' + escapeHtml( actionLabel ) + '</button>' : '' ) +
			'</div>';
	}

	/* ---------------------------------------------------------------------
	 * Modal
	 * ------------------------------------------------------------------ */

	function openModal( options ) {
		if ( moveClickHandler ) {
			el.modalBody.removeEventListener( 'click', moveClickHandler );
			moveClickHandler = null;
		}
		if ( moveDblHandler ) {
			el.modalBody.removeEventListener( 'dblclick', moveDblHandler );
			moveDblHandler = null;
		}
		modalConfirm = options.onConfirm || null;
		el.modalTitle.textContent = options.title || '';
		el.modalBody.innerHTML = options.body || '';
		el.modalConfirm.textContent = options.confirmLabel || '确定';
		el.modalConfirm.hidden = ! options.onConfirm;
		el.modalConfirm.disabled = false;

		if ( typeof el.modal.showModal === 'function' ) {
			el.modal.showModal();
		} else {
			el.modal.setAttribute( 'open', 'open' );
		}

		var focusable = el.modalBody.querySelector( 'input, textarea, button' );
		if ( focusable ) {
			focusable.focus();
		}
	}

	function closeModal() {
		modalConfirm = null;
		if ( typeof el.modal.close === 'function' ) {
			el.modal.close();
		} else {
			el.modal.removeAttribute( 'open' );
		}
	}

	function modalError( message ) {
		var box = el.modalBody.querySelector( '.sufm-dialog-error' );
		if ( ! box ) {
			box = document.createElement( 'p' );
			box.className = 'sufm-dialog-error';
			el.modalBody.appendChild( box );
		}
		box.textContent = message || '';
		box.hidden = ! message;
	}

	el.modalForm.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		if ( ! modalConfirm ) {
			closeModal();
			return;
		}
		modalConfirm();
	} );

	root.addEventListener( 'click', function ( event ) {
		var close = event.target.closest( '[data-sufm-modal-close]' );
		if ( close ) {
			event.preventDefault();
			closeModal();
		}
	} );

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	function renderQuota( quota ) {
		if ( ! quota ) { return; }

		var total = Number( quota.total ) || 0;
		var used = Number( quota.used ) || 0;
		var free = Math.max( 0, total - used );
		var percent = total > 0 ? Math.min( 100, Math.round( used / total * 100 ) ) : 0;

		el.used.textContent = bytes( used );
		el.quota.textContent = bytes( total );
		el.free.textContent = bytes( free );
		el.progress.style.width = percent + '%';
		el.track.setAttribute( 'aria-valuenow', String( percent ) );
		el.track.classList.toggle( 'is-warning', percent >= 80 );
		el.counts.textContent = ( ( data.strings && data.strings.filesCount ) || '%s 个文件' ).replace( '%s', String( quota.files || 0 ) );

		if ( el.usedMini ) { el.usedMini.textContent = bytes( used ); }
		if ( el.quotaMini ) { el.quotaMini.textContent = bytes( total ); }
		if ( el.progressMini ) { el.progressMini.style.width = percent + '%'; }
		if ( el.trackMini ) {
			el.trackMini.setAttribute( 'aria-valuenow', String( percent ) );
			el.trackMini.classList.toggle( 'is-warning', percent >= 80 );
		}
	}

	function quotaText() {
		return ( data.strings && data.strings.quotaExceeded ) || '已使用容量超过上限，请联系网站管理员扩容或删除多余文件！';
	}

	function applyQuotaLock() {
		var upload = document.getElementById( 'sufm-upload' );
		var drop = document.getElementById( 'sufm-drop' );
		var banner = document.getElementById( 'sufm-quota-warn' );
		var bannerText = document.getElementById( 'sufm-quota-warn-text' );

		if ( upload ) {
			upload.disabled = state.overQuota;
			upload.classList.toggle( 'is-disabled', state.overQuota );
			upload.setAttribute( 'title', state.overQuota ? quotaText() : '' );
		}
		if ( drop ) {
			drop.classList.toggle( 'is-disabled', state.overQuota );
		}
		if ( banner && bannerText ) {
			banner.hidden = ! state.overQuota;
			bannerText.textContent = state.overQuota ? quotaText() : '';
		}
	}

	function renderSummary( payload ) {
		renderQuota( { total: payload.quota.total, used: payload.quota.used, files: payload.counts.files } );
		state.overQuota = !! payload.quota.over || ( Number( payload.quota.total ) > 0 && Number( payload.quota.used ) >= Number( payload.quota.total ) );
		applyQuotaLock();
		state.pending = Number( payload.counts.pending ) || 0;

		if ( el.requestCount ) {
			el.requestCount.textContent = state.pending > 0 ? String( state.pending ) : '';
			el.requestCount.hidden = state.pending === 0;
		}

		policy = payload.policy || null;

		if ( policy && el.maxFile ) {
			el.maxFile.textContent = bytes( policy.max_file_bytes );
		}
	}

	function scopeLabel() {
		if ( state.search ) {
			return ( strings.searchScope || '搜索' ) + '「' + state.search + '」';
		}

		var tab = app.querySelector( '.sufm-tab.is-active' );

		return tab ? String( tab.textContent || '' ).trim() : ( strings.allFiles || '全部文件' );
	}

	function renderCrumbs( breadcrumb, folder ) {
		var parts = '<button type="button" class="sufm-crumb" data-sufm-folder="0">' + escapeHtml( strings.allFiles || '全部文件' ) + '</button>';

		( breadcrumb || [] ).forEach( function ( item ) {
			parts += '<span class="sufm-crumb-sep">/</span>' +
				'<button type="button" class="sufm-crumb" data-sufm-folder="' + Number( item.id ) + '">' + escapeHtml( item.name ) + '</button>';
		} );

		// At the root the chip would just repeat "全部文件", so it only marks a
		// filter or a search that narrowed the cabinet.
		if ( 'all' === state.scope && ( state.search || 'all' !== state.filter ) ) {
			parts += '<span class="sufm-crumb-sep">/</span><span class="sufm-crumb is-scope">' + escapeHtml( scopeLabel() ) + '</span>';
		}

		if ( ! breadcrumb || ! breadcrumb.length ) {
			// Keep the current folder highlighted when it is the root.
			el.crumbs.innerHTML = parts;
			return;
		}

		el.crumbs.innerHTML = parts;
		announce( '当前文件夹：' + breadcrumb[ breadcrumb.length - 1 ].name );
	}

	function foldBar( count ) {
		var label = strings.foldersToggle || '\u5b50\u6587\u4ef6\u5939';
		var hint = state.folded
			? ( strings.foldersShow || '\u5c55\u5f00\u5b50\u6587\u4ef6\u5939' )
			: ( strings.foldersHide || '\u6536\u8d77\u5b50\u6587\u4ef6\u5939' );

		return '<button type="button" class="sufm-fold" data-sufm-fold="1" aria-expanded="' + ( state.folded ? 'false' : 'true' ) + '" title="' + escapeHtml( hint ) + '">' +
			'<svg class="sufm-fold-chev" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.8 6.2 8 10.4l4.2-4.2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>' +
			'<span class="sufm-fold-label">' + escapeHtml( label ) + '</span>' +
			'<span class="sufm-fold-count">' + Number( count || 0 ) + '</span>' +
		'</button>';
	}

	function setFolded( folded ) {
		state.folded = !! folded;
		localStorage.setItem( 'sufm_folders', state.folded ? 'folded' : 'open' );
		renderFolders( state.folders );

		// Desktop merges folders into the file table, so the table must re-render too.
		if ( state.desk ) {
			renderFiles();
		}
	}

	function renderFolders( folders ) {
		state.folders = folders || [];

		if ( state.desk ) {
			// Desktop merges folders into the file table so both share one grid.
			el.folders.hidden = true;
			el.folders.innerHTML = '';
			return;
		}

		if ( ! state.folders.length ) {
			el.folders.hidden = true;
			el.folders.innerHTML = '';
			return;
		}

		el.folders.hidden = false;
		el.folders.innerHTML = foldBar( state.folders.length ) + ( state.folded ? '' : state.folders.map( function ( folder ) {
			return '<div class="sufm-folder" role="button" tabindex="0" data-sufm-open-folder="' + Number( folder.id ) + '"' + ( folder.locked ? ' data-sufm-drop-locked="1"' : ( state.desk ? ' draggable="true" data-sufm-drag="folder"' : '' ) ) + '>' +
				folderArt() +
				'<span class="sufm-folder-meta">' +
					'<span class="sufm-folder-name">' + escapeHtml( folder.name ) + '</span>' +
					'<span class="sufm-folder-sub">' + ( folder.locked ? '待审核锁定' : escapeHtml( dateLabel( folder.updated_at ) ) ) + '</span>' +
				'</span>' +
				'<span class="sufm-folder-side">' +
					'<button type="button" class="sufm-more" data-sufm-menu="folder" data-id="' + Number( folder.id ) + '" aria-label="文件夹操作" aria-haspopup="menu">' + icon( 'dots' ) + '</button>' +
					folderMenu( folder.id ) +
				'</span>' +
			'</div>';
		} ).join( '' ) );
	}

	function folderMenu( id ) {
		return '<div class="sufm-menu" role="menu" hidden data-sufm-menu-panel="folder-' + Number( id ) + '">' +
			'<button type="button" role="menuitem" data-sufm-act="fopen" data-id="' + Number( id ) + '">' + icon( 'folder' ) + '打开</button>' +
			'<button type="button" role="menuitem" data-sufm-act="frename" data-id="' + Number( id ) + '">' + icon( 'file-text' ) + '重命名</button>' +
			'<button type="button" role="menuitem" data-sufm-act="fmove" data-id="' + Number( id ) + '">' + icon( 'move' ) + '移动到</button>' +
			( state.folder > 0 ? '<button type="button" role="menuitem" data-sufm-act="up" data-kind="folder" data-id="' + Number( id ) + '">' + icon( 'move' ) + '移到上一级</button>' : '' ) +
			'<button type="button" role="menuitem" class="is-danger" data-sufm-act="fdelete" data-id="' + Number( id ) + '">' + icon( 'trash' ) + '申请删除</button>' +
		'</div>';
	}

	function folderSummary( folder ) {
		var count = Number( folder.count || 0 );
		if ( folder.locked ) { return '待审核锁定'; }
		return count > 0 ? count + ' 个文件' : '空文件夹';
	}

	function folderRow( folder ) {
		return '<div class="sufm-folder sufm-folder-row" role="button" tabindex="0" data-sufm-open-folder="' + Number( folder.id ) + '"' + ( folder.locked ? ' data-sufm-drop-locked="1"' : ( state.desk ? ' draggable="true" data-sufm-drag="folder"' : '' ) ) + '>' +
			'<span class="sufm-row-check"></span>' +
			'<span class="sufm-cell-name">' +
				folderArt() +
				'<span class="sufm-cell-text">' +
					'<span class="sufm-folder-name">' + escapeHtml( folder.name ) + '</span>' +
					'<span class="sufm-cell-sub">' + escapeHtml( folderSummary( folder ) ) + '</span>' +
				'</span>' +
			'</span>' +
			'<span class="sufm-cell-size">—</span>' +
			'<span class="sufm-cell-date">' + escapeHtml( dateLabel( folder.updated_at ) ) + '</span>' +
			'<span class="sufm-row-side"><button type="button" class="sufm-more" data-sufm-menu="folder" data-id="' + Number( folder.id ) + '" aria-label="文件夹操作" aria-haspopup="true">' + icon( 'dots' ) + '</button>' + folderMenu( folder.id ) + '</span>' +
		'</div>';
	}

	function fileMenu( id ) {
		return '<div class="sufm-menu" role="menu" hidden data-sufm-menu-panel="' + id + '">' +
			'<button type="button" role="menuitem" data-sufm-act="open" data-id="' + id + '">' + icon( 'external' ) + '打开</button>' +
			'<button type="button" role="menuitem" data-sufm-act="rename" data-id="' + id + '">' + icon( 'file-text' ) + '重命名</button>' +
			'<button type="button" role="menuitem" data-sufm-act="move" data-id="' + id + '">' + icon( 'move' ) + '移动到</button>' +
			( state.folder > 0 ? '<button type="button" role="menuitem" data-sufm-act="up" data-kind="file" data-id="' + id + '">' + icon( 'move' ) + '移到上一级</button>' : '' ) +
			'<button type="button" role="menuitem" data-sufm-act="copy" data-id="' + id + '">' + icon( 'external' ) + '复制链接</button>' +
			'<button type="button" role="menuitem" data-sufm-act="download" data-id="' + id + '">' + icon( 'download' ) + '下载</button>' +
			'<button type="button" role="menuitem" class="is-danger" data-sufm-act="delete" data-id="' + id + '">' + icon( 'trash' ) + '申请删除</button>' +
		'</div>';
	}

	function renderFiles() {
		closeAllMenus();
		el.files.classList.toggle( 'is-desk-scroll', !! state.desk );

		var barHtml = ( state.desk && state.folders.length ) ? foldBar( state.folders.length ) : '';

		var isEmpty = ! state.files.length && ! ( state.desk && state.folders.length && ! state.folded );

		// Counts describe the current scope, so they are refreshed even when the
		// scope turns out to be empty; otherwise the previous tab's figure stays
		// on screen next to "这里还没有文件".
		updateCounts();

		if ( isEmpty ) {
			el.files.innerHTML = barHtml + empty(
				'这里还没有文件',
				'拖拽文件到下方区域，或点击上传按钮。',
				'上传文件',
				'upload'
			);
			return;
		}

		if ( state.view === 'grid' ) {
			var folderTiles = state.desk && ! state.folded ? state.folders.map( function ( folder ) {
				return '<article class="sufm-file-card sufm-folder-tile" role="button" tabindex="0" data-sufm-open-folder="' + Number( folder.id ) + '"' + ( folder.locked ? ' data-sufm-drop-locked="1"' : ( state.desk ? ' draggable="true" data-sufm-drag="folder"' : '' ) ) + '>' +
					'<span class="sufm-folder-tile-art" aria-hidden="true">' + folderArt() + '</span>' +
					'<span class="sufm-folder-name sufm-folder-tile-name">' + escapeHtml( folder.name ) + '</span>' +
					'<span class="sufm-grid-sub">' + escapeHtml( folderSummary( folder ) ) + '</span>' +
					'<button type="button" class="sufm-more sufm-grid-more" data-sufm-menu="folder" data-id="' + Number( folder.id ) + '" aria-label="文件夹操作" aria-haspopup="true">' + icon( 'dots' ) + '</button>' +
					folderMenu( folder.id ) +
				'</article>';
			} ).join( '' ) : '';

			el.files.innerHTML = barHtml + '<div class="sufm-cards">' + folderTiles + state.files.map( function ( file ) {
				var group = groupFor( file.mime );
				var preview = group === 'image'
					? '<img src="' + escapeHtml( file.url ) + '" alt="" loading="lazy" decoding="async">'
					: '<span class="sufm-type" data-group="' + group + '">' + escapeHtml( typeLabel( file.mime, file.name ) ) + '</span>';

				return '<article class="sufm-file-card' + ( state.selection[ file.id ] ? ' is-selected' : '' ) + '" data-sufm-file="' + Number( file.id ) + '"' + ( file.locked ? '' : ( state.desk ? ' draggable="true" data-sufm-drag="file"' : '' ) ) + '>' +
					'<label class="sufm-grid-check"><input type="checkbox" class="sufm-check" data-sufm-select="' + Number( file.id ) + '"' + ( state.selection[ file.id ] ? ' checked' : '' ) + ( file.locked ? ' disabled' : '' ) + ' aria-label="选择 ' + escapeHtml( file.name ) + '"></label>' +
					'<div class="sufm-grid-preview">' + preview + '</div>' +
					'<button type="button" class="sufm-grid-name" data-sufm-open-file="' + Number( file.id ) + '" title="' + escapeHtml( file.name ) + '">' + escapeHtml( file.name ) + '</button>' +
					'<span class="sufm-grid-sub">' + ( file.locked ? '待审核' : bytes( file.bytes ) ) + ( file.folder ? ' · ' + escapeHtml( file.folder ) : '' ) + '</span>' +
					'<button type="button" class="sufm-more sufm-grid-more" data-sufm-menu="file" data-id="' + Number( file.id ) + '" aria-label="文件操作" aria-haspopup="menu">' + icon( 'dots' ) + '</button>' +
					fileMenu( file.id ) +
				'</article>';
			} ).join( '' ) + '</div>';
		} else if ( state.desk ) {
			el.files.innerHTML = barHtml + '<div class="sufm-list sufm-list-desk">' +
				'<div class="sufm-list-head">' +
					'<span class="sufm-head-check"><input type="checkbox" class="sufm-check" id="sufm-check-all" aria-label="全选文件"></span>' +
					'<span class="sufm-head-name">名称</span>' +
					'<span class="sufm-head-size">大小</span>' +
					'<span class="sufm-head-date">修改时间</span>' +
					'<span class="sufm-head-side">操作</span>' +
				'</div>' + ( state.folded ? '' : state.folders.map( folderRow ).join( '' ) ) + state.files.map( function ( file ) {
					var locked = file.locked;
					var group = groupFor( file.mime );
					var thumb = group === 'image'
						? '<img class="sufm-cell-thumb" src="' + escapeHtml( file.url ) + '" alt="" loading="lazy" decoding="async">'
						: '<span class="sufm-type" data-group="' + group + '">' + escapeHtml( typeLabel( file.mime, file.name ) ) + '</span>';

					return '<div class="sufm-row' + ( state.selection[ file.id ] ? ' is-selected' : '' ) + '" data-sufm-file="' + Number( file.id ) + '"' + ( locked ? '' : ( state.desk ? ' draggable="true" data-sufm-drag="file"' : '' ) ) + '>' +
						'<span class="sufm-row-check"><input type="checkbox" class="sufm-check" data-sufm-select="' + Number( file.id ) + '"' + ( state.selection[ file.id ] ? ' checked' : '' ) + ( locked ? ' disabled' : '' ) + ' aria-label="选择 ' + escapeHtml( file.name ) + '"></span>' +
						'<span class="sufm-cell-name">' + thumb +
							'<span class="sufm-cell-text">' +
								'<button type="button" class="sufm-row-name" data-sufm-open-file="' + Number( file.id ) + '" title="' + escapeHtml( file.name ) + '">' + escapeHtml( file.name ) + '</button>' +
								( locked ? '<span class="sufm-cell-sub is-warn">待审核，暂不可操作</span>' : ( file.folder ? '<span class="sufm-cell-sub sufm-in-folder">' + escapeHtml( file.folder ) + '</span>' : '' ) ) +
							'</span>' +
						'</span>' +
						'<span class="sufm-cell-size">' + ( locked ? '—' : escapeHtml( bytes( file.bytes ) ) ) + '</span>' +
						'<span class="sufm-cell-date">' + escapeHtml( dateLabel( file.created_at ) ) + '</span>' +
						'<span class="sufm-row-side"><button type="button" class="sufm-more" data-sufm-menu="file" data-id="' + Number( file.id ) + '" aria-label="文件操作" aria-haspopup="menu">' + icon( 'dots' ) + '</button>' + fileMenu( file.id ) + '</span>' +
					'</div>';
				} ).join( '' ) + '</div>';
		} else {
			el.files.innerHTML = '<div class="sufm-list">' +
				'<div class="sufm-list-head">' +
					'<span class="sufm-head-check"><input type="checkbox" class="sufm-check" id="sufm-check-all" aria-label="全选文件"></span>' +
					'<span class="sufm-head-name">名称</span>' +
					'<span class="sufm-head-side">操作</span>' +
				'</div>' + state.files.map( function ( file ) {
					var locked = file.locked;
					var meta = bytes( file.bytes ) + '<i>·</i>' + escapeHtml( dateLabel( file.created_at ) ) + '<i>·</i>' + ( locked ? '待审核' : escapeHtml( shortType( file.mime ) ) ) +
						( file.folder ? '<i>·</i><span class="sufm-in-folder">' + escapeHtml( file.folder ) + '</span>' : '' );

					return '<div class="sufm-row' + ( state.selection[ file.id ] ? ' is-selected' : '' ) + '" data-sufm-file="' + Number( file.id ) + '"' + ( locked ? '' : ( state.desk ? ' draggable="true" data-sufm-drag="file"' : '' ) ) + '>' +
						'<span class="sufm-row-check"><input type="checkbox" class="sufm-check" data-sufm-select="' + Number( file.id ) + '"' + ( state.selection[ file.id ] ? ' checked' : '' ) + ( locked ? ' disabled' : '' ) + ' aria-label="选择 ' + escapeHtml( file.name ) + '"></span>' +
						'<span class="sufm-type" data-group="' + groupFor( file.mime ) + '">' + escapeHtml( typeLabel( file.mime, file.name ) ) + '</span>' +
						'<span class="sufm-row-text">' +
							'<button type="button" class="sufm-row-name" data-sufm-open-file="' + Number( file.id ) + '" title="' + escapeHtml( file.name ) + '">' + escapeHtml( file.name ) + '</button>' +
							'<span class="sufm-row-meta">' + meta + '</span>' +
						'</span>' +
						'<span class="sufm-row-side"><button type="button" class="sufm-more" data-sufm-menu="file" data-id="' + Number( file.id ) + '" aria-label="文件操作" aria-haspopup="menu">' + icon( 'dots' ) + '</button>' + fileMenu( file.id ) + '</span>' +
					'</div>';
				} ).join( '' ) + '</div>';
		}

		if ( state.next ) {
			el.files.insertAdjacentHTML( 'beforeend', '<div class="sufm-more-wrap"><button type="button" class="sufm-btn sufm-btn-sm" data-sufm-more="1">加载更多</button></div>' );
		}

		syncSelectAll();
	}

	function updateCounts() {
		var visibleFiles = state.files.length;
		var visibleFolders = state.desk ? state.folders.length : 0;
		// Only the root "all files" view is about the whole cabinet, so only it
		// may quote the cabinet total (the same number the quota summary shows).
		// The root view now pages through one directory (its own files only), so
		// the count it shows is that directory's total, not the whole cabinet.
		var cabinetTotal = ( ! state.search && 'all' === state.filter && 0 === state.folder )
			? Number( state.totalFiles || 0 )
			: 0;
		var countLabel;

		if ( cabinetTotal > 0 ) {
			// This view is about every file in the cabinet, so quoting only the
			// rows that happen to be loaded would understate it.
			countLabel = ( strings.filesCount || '%s 个文件' ).replace( '%s', String( cabinetTotal ) );
		} else if ( visibleFolders > 0 ) {
			countLabel = ( strings.itemsCount || '共 %s 项' ).replace( '%s', String( visibleFiles + visibleFolders ) );
		} else {
			countLabel = ( strings.filesCount || '%s 个文件' ).replace( '%s', String( visibleFiles ) );
		}

		el.footer.textContent = countLabel;
		el.pathCount.textContent = countLabel;
	}

	function shortType( mime ) {
		var value = String( mime || '' ).split( '/' )[1] || '';
		return value ? value.toUpperCase() : '文件';
	}

	function statusLabel( status ) {
		return {
			pending_review: '待审核',
			approved: '已批准',
			rejected: '已驳回',
			withdrawn: '已撤回',
			revoked: '已撤销',
			needs_review: '需核实',
			queued: '排队中',
			executing: '执行中',
			completed: '已完成',
			partial_failed: '部分失败',
			failed: '失败',
			stopped: '已停止',
			done: '已清理',
			purged: '已清理',
			deleting: '删除中',
			skipped: '已跳过',
			active: '正常'
		}[ status ] || status;
	}

	function renderRequests( payload ) {
		state.requestsMode = true;
		el.files.classList.remove( 'is-desk-scroll' );
		el.folders.hidden = true;
		el.drop.hidden = true;

		if ( ! payload.rows.length ) {
			el.pathCount.textContent = '';
			el.files.innerHTML = empty( '还没有删除申请', '在文件列表里选择文件即可提交申请，管理员审核后才会执行删除。', '回到全部文件', 'back' );
			el.footer.textContent = '';
			return;
		}

		el.files.innerHTML = '<div class="sufm-requests">' + payload.rows.map( function ( row ) {
			var quiet = [ 'withdrawn', 'rejected', 'revoked', 'stopped' ].indexOf( row.status ) !== -1;
			var items = ( row.items || [] ).map( function ( item ) {
				var isFolder = item.type === 'folder';
				var name = item.name || ( isFolder ? '文件夹 #' + item.object_id : '文件 #' + item.object_id );
				return '<li class="sufm-request-item">' +
					'<span class="sufm-request-item-icon' + ( isFolder ? ' is-folder' : '' ) + '">' +
						'<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-' + ( isFolder ? 'folder' : 'file-text' ) + '"></use></svg>' +
					'</span>' +
					'<span class="sufm-request-item-name" title="' + escapeHtml( name ) + '">' + escapeHtml( name ) + '</span>' +
					'<span class="sufm-request-item-state" data-state="' + ( quiet ? 'cancelled' : escapeHtml( item.state ) ) + '">' + ( quiet ? '未删除' : escapeHtml( statusLabel( item.state ) ) ) + '</span>' +
				'</li>';
			} ).join( '' );

			var canWithdraw = row.status === 'pending_review';
			var count = Number( row.item_count || 0 );

			return '<article class="sufm-request" data-status="' + escapeHtml( row.status ) + '">' +
				'<div class="sufm-request-main">' +
					'<header class="sufm-request-head">' +
						'<span class="sufm-request-id">申请 #' + Number( row.id ) + '</span>' +
						'<span class="sufm-request-status" data-status="' + escapeHtml( row.status ) + '">' + escapeHtml( statusLabel( row.status ) ) + '</span>' +
					'</header>' +
					( row.reason ? '<p class="sufm-request-reason"><b>理由</b>' + escapeHtml( row.reason ) + '</p>' : '' ) +
					( items ? '<ul class="sufm-request-items">' + items + '</ul>' : '' ) +
				'</div>' +
				'<aside class="sufm-request-aside">' +
					'<time class="sufm-request-date">' + escapeHtml( dateLabel( row.created_at ) ) + '</time>' +
					'<span class="sufm-request-count">' + count + ' 个对象 · ' + bytes( row.bytes ) + '</span>' +
					'<span class="sufm-request-meta">' + ( row.approval_revision ? '授权修订 ' + Number( row.approval_revision ) : '尚未批准' ) + '</span>' +
					( canWithdraw ? '<div class="sufm-request-actions"><button type="button" class="sufm-btn sufm-btn-sm sufm-btn-ghost" data-sufm-withdraw="' + Number( row.id ) + '">撤回申请</button></div>' : '' ) +
				'</aside>' +
			'</article>';
		} ).join( '' ) + '</div>';

		if ( payload.total > payload.rows.length ) {
			el.files.insertAdjacentHTML( 'beforeend', '<div class="sufm-more-wrap"><button type="button" class="sufm-btn sufm-btn-sm" data-sufm-requests-more="1">加载更多</button></div>' );
		}

		el.footer.textContent = '共 ' + payload.total + ' 条申请';
		el.pathCount.textContent = '';
	}

	/* ---------------------------------------------------------------------
	 * Loading
	 * ------------------------------------------------------------------ */

	function loadSummary() {
		return api( 'summary' ).then( renderSummary ).catch( function ( error ) {
			showError( error.message, loadSummary );
		} );
	}

	function loadItems( reset ) {
		syncSearchClear();

		if ( state.requestsMode ) {
			return loadRequests( reset );
		}

		if ( reset ) {
			state.cursor = 0;
			state.files = [];
			state.selection = {};
			state.selectedCount = 0;
			state.anchor = 0;
			updateBatch();
		}

		setBusy( true );
		showError( '' );

		var params = 'folder_id=' + state.folder +
			'&type=' + encodeURIComponent( state.filter === 'all' ? '' : state.filter ) +
			'&search=' + encodeURIComponent( state.search ) +
			'&per_page=40' +
			( reset ? '' : '&cursor=' + state.cursor );

		return api( 'items?' + params ).then( function ( payload ) {
			state.files = reset ? payload.files : state.files.concat( payload.files );
			state.next = Number( payload.next ) || 0;
			state.cursor = state.next;
			renderQuota( { total: payload.quota.quota, used: payload.quota.used, files: state.files.length } );
			state.scope = payload.scope === 'all' ? 'all' : 'folder';
			state.totalFiles = Number( payload.total ) || ( reset ? 0 : Number( state.totalFiles ) || 0 );
			state.breadcrumb = payload.breadcrumb || [];
			renderCrumbs( payload.breadcrumb );
			renderFolders( payload.folders );
			renderFiles();
			setBusy( false );
		} ).catch( function ( error ) {
			setBusy( false );
			el.files.innerHTML = empty( '加载失败', error.message, '重试', 'retry' );
			showError( error.message, function () { loadItems( true ); } );
		} );
	}

	function loadRequests( reset, append ) {
		setBusy( true );
		showError( '' );

		if ( reset ) {
			state.requestPage = 1;
		}

		if ( append ) {
			state.requestPage++;
		}

		return api( 'delete-requests?page=' + state.requestPage + '&per_page=20' ).then( function ( payload ) {
			renderRequests( payload );
			setBusy( false );
		} ).catch( function ( error ) {
			setBusy( false );
			showError( error.message, function () { loadRequests( true ); } );
		} );
	}

	function enterRequests() {
		state.requestsMode = true;
		app.classList.add( 'is-requests' );
		state.selection = {};
		state.selectedCount = 0;
		state.anchor = 0;
		updateBatch();
		loadRequests( true );
	}

	function refresh() {
		// The overview and the listing read different tables and neither needs
		// the other's answer, so waiting for one before starting the other only
		// doubles the perceived latency.
		var listing = state.requestsMode || state.filter === 'requests' ? loadRequests( true ) : loadItems( true );

		return Promise.all( [ loadSummary(), listing ] );
	}

	/* ---------------------------------------------------------------------
	 * Selection
	 * ------------------------------------------------------------------ */

	function checkboxOf( target ) {
		var box = target.closest( '[data-sufm-select]' );
		if ( box ) { return box; }
		var wrap = target.closest( '.sufm-grid-check, .sufm-row-check' );
		return wrap ? wrap.querySelector( '[data-sufm-select]' ) : null;
	}

	function isTypingTarget( target ) {
		if ( ! target || ! target.closest ) { return false; }
		if ( target.isContentEditable ) { return true; }
		var field = target.closest( 'input, textarea, select, [contenteditable="true"], [contenteditable=""], .mce-edit-area, .wp-editor-area' );
		if ( ! field ) { return false; }
		if ( 'INPUT' === field.tagName ) {
			// Checkbox and button inputs are selection targets, not text fields:
			// Ctrl+A with a checkbox focused must still select every file.
			return ! /^(checkbox|radio|button|submit|reset)$/i.test( field.type || '' );
		}
		return true;
	}

	function hasOpenMenus() {
		return !! document.querySelector( '[data-sufm-menu-panel]:not([hidden])' );
	}

	// The checkboxes currently on screen, in reading order. Both layouts render
	// one box per file, so this is also the order a Shift range follows.
	function selectableIds() {
		var seen = {};
		var ids = [];

		root.querySelectorAll( '[data-sufm-select]' ).forEach( function ( box ) {
			if ( box.disabled ) { return; }
			var row = box.closest( '[data-sufm-file]' );
			var id = row ? Number( row.getAttribute( 'data-sufm-file' ) ) : 0;
			if ( ! id || seen[ id ] ) { return; }
			seen[ id ] = true;
			ids.push( id );
		} );

		return ids;
	}

	function isSelectable( id ) {
		return selectableIds().indexOf( Number( id ) ) >= 0;
	}

	function selectRange( id ) {
		var target = Number( id );
		var ids = selectableIds();
		if ( ids.indexOf( target ) < 0 ) { return; }

		var anchor = Number( state.anchor ) || target;
		var from = ids.indexOf( anchor );
		var to = ids.indexOf( target );
		if ( from < 0 ) { from = to; }

		var start = Math.min( from, to );
		var end = Math.max( from, to );

		for ( var i = start; i <= end; i++ ) {
			state.selection[ String( ids[ i ] ) ] = true;
		}

		// Ranges only add. The anchor stays where it was so the next Shift
		// click can extend the range in the other direction.
		state.anchor = anchor;
		state.selectedCount = Object.keys( state.selection ).length;
		updateBatch();
	}

	function toggleFile( id ) {
		if ( ! isSelectable( id ) ) { return; }
		id = Number( id );
		if ( state.selection[ String( id ) ] ) {
			delete state.selection[ String( id ) ];
		} else {
			state.selection[ String( id ) ] = true;
		}
		state.anchor = id;
		state.selectedCount = Object.keys( state.selection ).length;
		if ( 0 === state.selectedCount ) { state.anchor = 0; }
		updateBatch();
	}

	function clearSelection() {
		state.selection = {};
		state.selectedCount = 0;
		state.anchor = 0;
		updateBatch();
	}

	function allSelected() {
		var ids = selectableIds();
		if ( ! ids.length ) { return false; }
		return ids.every( function ( id ) { return !! state.selection[ String( id ) ]; } );
	}

	function syncSelectAll() {
		var ids = selectableIds();
		var picked = ids.filter( function ( id ) { return !! state.selection[ String( id ) ]; } ).length;
		var checked = ids.length > 0 && picked === ids.length;
		var partial = picked > 0 && picked < ids.length;
		var toggle = root.querySelector( '[data-sufm-select-all]' );

		if ( toggle ) {
			// The list and desk layouts already have the header checkbox; this
			// button is the grid's only way to select everything at once.
			toggle.hidden = state.view !== 'grid' || ids.length === 0;
			toggle.textContent = checked
				? ( strings.selectNoneShort || '取消全选' )
				: ( strings.selectAllShort || '全选' );
			toggle.setAttribute( 'aria-pressed', checked ? 'true' : 'false' );
		}

		var all = root.querySelector( '#sufm-check-all' );
		if ( all ) {
			all.checked = checked;
			all.indeterminate = partial;
		}
	}

	function updateBatch() {
		if ( ! el.batch ) { return; }
		var count = state.selectedCount;
		el.batch.hidden = count === 0;
		var upBtn = el.batch.querySelector( '[data-sufm-batch="up"]' );
		if ( upBtn ) { upBtn.hidden = state.folder <= 0; }
		if ( count > 0 ) {
			el.batchLabel.textContent = '已选择 ' + count + ' 个文件';
		}

		root.querySelectorAll( '[data-sufm-select]' ).forEach( function ( box ) {
			var id = box.getAttribute( 'data-sufm-select' );
			var on = !! state.selection[ id ];
			box.checked = on;
			var row = box.closest( '[data-sufm-file]' );
			if ( row ) { row.classList.toggle( 'is-selected', on ); }
		} );

		syncSelectAll();
	}

	function selectAll( checked ) {
		state.selection = {};
		if ( checked ) {
			selectableIds().forEach( function ( id ) { state.selection[ String( id ) ] = true; } );
		}
		state.selectedCount = Object.keys( state.selection ).length;
		state.anchor = 0;
		updateBatch();
	}

	/* ---------------------------------------------------------------------
	 * Uploads
	 * ------------------------------------------------------------------ */

	function queueRow( id, name ) {
		var row = document.createElement( 'div' );
		row.className = 'sufm-queue-row';
		row.setAttribute( 'data-queue', id );
		row.innerHTML = '<div class="sufm-queue-name"><strong>' + escapeHtml( name ) + '</strong><small>等待上传</small></div>' +
			'<div class="sufm-queue-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i style="width:0%"></i></div>';
		el.queue.hidden = false;
		el.queue.appendChild( row );
		return row;
	}

	function uploadOne( file, row ) {
		return new Promise( function ( resolve ) {
			var xhr = new XMLHttpRequest();
			var body = new FormData();
			var nameEl = row.querySelector( 'small' );
			var bar = row.querySelector( '.sufm-queue-progress' );

			body.append( 'file', file );
			body.append( 'folder_id', String( state.folder ) );
			body.append( 'idempotency_key', ( window.crypto && crypto.randomUUID ) ? crypto.randomUUID() : 'u-' + Date.now() + '-' + Math.random().toString( 16 ).slice( 2 ) );

			xhr.open( 'POST', data.restUrl + 'uploads' );
			xhr.setRequestHeader( 'X-WP-Nonce', data.nonce );
			xhr.withCredentials = true;

			xhr.upload.addEventListener( 'progress', function ( event ) {
				if ( ! event.lengthComputable ) { return; }
				var percent = Math.round( event.loaded / event.total * 100 );
				bar.setAttribute( 'aria-valuenow', String( percent ) );
				bar.querySelector( 'i' ).style.width = percent + '%';
				nameEl.textContent = '上传中 ' + percent + '%';
			} );

			xhr.addEventListener( 'load', function () {
				var payload = {};
				try { payload = JSON.parse( xhr.responseText || '{}' ); } catch ( e ) { payload = {}; }

				if ( xhr.status >= 200 && xhr.status < 300 ) {
					nameEl.textContent = '已上传';
					row.classList.add( 'is-done' );
					bar.querySelector( 'i' ).style.width = '100%';
					resolve( true );
				} else {
					row.classList.add( 'is-error' );
					nameEl.textContent = payload.message || '上传失败';
					if ( payload && payload.code === 'sufm_quota_exceeded' ) {
						state.overQuota = true;
						applyQuotaLock();
					}
					resolve( false );
				}
			} );

			xhr.addEventListener( 'error', function () {
				row.classList.add( 'is-error' );
				nameEl.textContent = '网络错误';
				resolve( false );
			} );

			xhr.send( body );
		} );
	}

	function drainQueue() {
		if ( state.uploading >= 2 ) { return; }

		var next = state.queue.shift();
		if ( ! next ) {
			if ( state.uploading === 0 ) {
				window.setTimeout( function () {
					if ( el.queue && state.uploading === 0 && ! el.queue.querySelector( '.sufm-queue-row:not(.is-done):not(.is-error)' ) ) {
						el.queue.hidden = true;
						el.queue.innerHTML = '';
					}
				}, 2400 );
			}
			return;
		}

		state.uploading++;
		next.row.querySelector( 'small' ).textContent = '上传中 0%';
		uploadOne( next.file, next.row ).then( function ( ok ) {
			state.uploading--;
			if ( ok ) {
				state.queueDone = true;
			}
			drainQueue();
			if ( state.uploading === 0 && ! state.queue.length ) {
				refresh();
				if ( ! ok ) { toast( '有文件上传失败，请重试。', true ); }
			}
		} );
	}

	function enqueueFiles( fileList ) {
		var files = Array.prototype.slice.call( fileList || [] );
		if ( ! files.length ) { return; }

		if ( state.overQuota ) {
			applyQuotaLock();
			return;
		}

		var max = policy && policy.max_file_bytes ? Number( policy.max_file_bytes ) : 0;
		var accepted = 0;

		files.forEach( function ( file ) {
			if ( max > 0 && file.size > max ) {
				toast( '「' + file.name + '」超过单文件上限 ' + bytes( max ) + '。', true );
				return;
			}
			accepted++;
			var row = queueRow( 'q' + Date.now() + '-' + accepted, file.name );
			state.queue.push( { file: file, row: row } );
		} );

		if ( accepted ) {
			toast( '已加入上传队列：' + accepted + ' 个文件' );
			drainQueue();
		}

		el.input.value = '';
	}

	/* ---------------------------------------------------------------------
	 * Dialogs
	 * ------------------------------------------------------------------ */

	function renameFile( id ) {
		var file = state.files.filter( function ( item ) { return Number( item.id ) === Number( id ); } )[0];
		if ( ! file ) { return; }

		openModal( {
			title: '重命名文件',
			body: '<label class="sufm-field-label" for="sufm-rename-input">文件名</label>' +
				'<input class="sufm-field" id="sufm-rename-input" value="' + escapeHtml( file.name ) + '" maxlength="200">' +
				'<p class="sufm-hint sufm-muted">扩展名会被保留为 ' + escapeHtml( typeLabel( file.mime, file.name ) ) + ' 类型。</p>',
			confirmLabel: '保存',
			onConfirm: function () {
				var input = document.getElementById( 'sufm-rename-input' );
				var value = input ? input.value.trim() : '';
				if ( ! value ) { modalError( '文件名不能为空。' ); return; }
				api( 'files/' + id, { method: 'PATCH', body: { name: value } } ).then( function () {
					closeModal();
					toast( '已重命名' );
					loadItems( true );
				} ).catch( function ( error ) { modalError( error.message ); } );
			}
		} );
	}

	function renameFolder( id ) {
		var folder = null;
		document.querySelectorAll( '#sufm-folders [data-sufm-open-folder], #sufm-files [data-sufm-open-folder]' ).forEach( function ( node ) {
			if ( Number( node.getAttribute( 'data-sufm-open-folder' ) ) === Number( id ) ) {
				folder = node.querySelector( '.sufm-folder-name' );
			}
		} );

		openModal( {
			title: '重命名文件夹',
			body: '<label class="sufm-field-label" for="sufm-rename-input">文件夹名</label>' +
				'<input class="sufm-field" id="sufm-rename-input" value="' + escapeHtml( folder ? folder.textContent : '' ) + '" maxlength="100">',
			confirmLabel: '保存',
			onConfirm: function () {
				var input = document.getElementById( 'sufm-rename-input' );
				var value = input ? input.value.trim() : '';
				if ( ! value ) { modalError( '文件夹名不能为空。' ); return; }
				api( 'folders/' + id, { method: 'PATCH', body: { name: value } } ).then( function () {
					closeModal();
					toast( '已重命名' );
					loadItems( true );
				} ).catch( function ( error ) { modalError( error.message ); } );
			}
		} );
	}

	function newFolder() {
		openModal( {
			title: '新建文件夹',
			body: '<label class="sufm-field-label" for="sufm-folder-input">文件夹名</label>' +
				'<input class="sufm-field" id="sufm-folder-input" maxlength="100" placeholder="例如：设计稿">',
			confirmLabel: '创建',
			onConfirm: function () {
				var input = document.getElementById( 'sufm-folder-input' );
				var value = input ? input.value.trim() : '';
				if ( ! value ) { modalError( '请输入文件夹名。' ); return; }
				api( 'folders', { method: 'POST', body: { name: value, parent_id: state.folder } } ).then( function () {
					closeModal();
					toast( '文件夹已创建' );
					loadItems( true );
				} ).catch( function ( error ) { modalError( error.message ); } );
			}
		} );
	}

	function parentFolder() {
		if ( state.folder <= 0 ) { return null; }
		var crumb = state.breadcrumb || [];
		if ( crumb.length >= 2 ) {
			var up = crumb[ crumb.length - 2 ];
			return { id: Number( up.id ), name: String( up.name ) };
		}
		return { id: 0, name: strings.allFiles || '全部文件' };
	}

	/* The one-click way out: files selected inside a folder go to its parent,
	   which is the move people repeat most and the one a dialog makes tedious. */
	function moveUp( targets ) {
		var parent = parentFolder();
		if ( ! parent ) {
			toast( '已经在最外层了。' );
			return;
		}
		moveTargets( targets, parent.id, parent.name );
	}

	function movePicker( targets ) {
		var movingFolder = '';
		var names = [];

		( targets || [] ).forEach( function ( target ) {
			if ( 'folder' === target.type ) {
				movingFolder = String( target.id );
				var folderNode = findFolderNode( target.id );
				if ( folderNode ) { names.push( '「' + folderNode.textContent.trim() + '」' ); }
			} else {
				var file = state.files.filter( function ( item ) { return Number( item.id ) === Number( target.id ); } )[0];
				if ( file ) { names.push( '「' + file.name + '」' ); }
			}
		} );

		var describe = names.length === 1 ? names[0] : names.length + ' 个对象';

		/* One flat, indented list instead of a drill-down. Nesting is the case
		   that hurts: a file six folders deep would otherwise need six clicks
		   before it could be pulled back out, and pushing one into a nested
		   folder would need six more. Searching the flat list makes both sides
		   of that trip one click. */
		var picker = {
			folder: state.folder > 0 ? 0 : null,
			query: '',
			flat: [],
			blocked: {}
		};

		var body = '<p class="sufm-dialog-copy">把 ' + escapeHtml( describe ) + ' 移动到：</p>' +
			'<label class="sufm-move-search"><svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-search"></use></svg>' +
			'<input type="search" id="sufm-move-search" placeholder="' + escapeHtml( strings.moveSearch || '搜索文件夹' ) + '" autocomplete="off"></label>' +
			'<div class="sufm-move-list" id="sufm-move-list"><p class="sufm-muted sufm-move-empty">加载中…</p></div>' +
			'<p class="sufm-hint sufm-muted" id="sufm-move-hint"></p>';

		function flatten( nodes, depth, out ) {
			( nodes || [] ).forEach( function ( node ) {
				out.push( { id: Number( node.id ), name: String( node.name ), depth: depth } );
				if ( node.children && node.children.length ) { flatten( node.children, depth + 1, out ); }
			} );
			return out;
		}

		function blockedFor( nodes ) {
			/* A folder cannot be moved inside itself or one of its children. */
			var found = null;
			( function walk( list ) {
				( list || [] ).forEach( function ( node ) {
					if ( ! found && String( node.id ) === movingFolder ) { found = node; }
					if ( ! found ) { walk( node.children ); }
				} );
			} )( nodes );
			var blocked = {};
			( function mark( node ) {
				if ( ! node ) { return; }
				blocked[ String( node.id ) ] = true;
				( node.children || [] ).forEach( mark );
			} )( found );
			return blocked;
		}

		function renderList() {
			var list = document.getElementById( 'sufm-move-list' );
			var hint = document.getElementById( 'sufm-move-hint' );
			if ( ! list ) { return; }

			var query = picker.query.trim().toLowerCase();
			var rows = [ { id: 0, name: strings.allFiles || '全部文件', depth: 0 } ].concat( picker.flat ).filter( function ( row ) {
				if ( picker.blocked[ String( row.id ) ] ) { return false; }
				if ( query && row.name.toLowerCase().indexOf( query ) === -1 ) { return false; }
				return true;
			} );

			list.innerHTML = rows.length
				? rows.map( function ( row ) {
					var picked = null !== picker.folder && Number( picker.folder ) === Number( row.id );
					var current = Number( state.folder ) === Number( row.id );
					return '<button type="button" class="sufm-move-row' + ( picked ? ' is-picked' : '' ) +
						( current ? ' is-current' : '' ) + '" data-move-pick="' + Number( row.id ) + '"' +
						( row.depth ? ' style="padding-left:' + ( 12 + row.depth * 16 ) + 'px"' : '' ) + '>' +
						icon( row.id ? 'folder' : 'folder-solid' ) + '<span>' + escapeHtml( row.name ) + '</span>' +
						( current ? '<em>' + escapeHtml( strings.currentFolder || '当前位置' ) + '</em>' : '' ) +
						'</button>';
				} ).join( '' )
				: '<p class="sufm-muted sufm-move-empty">' + escapeHtml( strings.noMatch || '没有匹配的文件夹。' ) + '</p>';

			if ( hint ) {
				var target = null;
				if ( null !== picker.folder ) {
					target = Number( picker.folder ) === 0
						? ( strings.allFiles || '全部文件' )
						: ( picker.flat.filter( function ( row ) { return Number( row.id ) === Number( picker.folder ); } )[0] || {} ).name;
				}
				hint.textContent = target ? '将移动到「' + target + '」' : '先选一个目标文件夹。';
			}
		}

		openModal( {
			title: '移动到',
			body: body,
			confirmLabel: '移动到此处',
			onConfirm: function () {
				if ( null === picker.folder ) {
					modalError( '先选一个目标文件夹。' );
					return;
				}
				var folder = Number( picker.folder );
				var requests = targets.map( function ( target ) {
					if ( 'folder' === target.type ) {
						return api( 'folders/' + target.id, { method: 'PATCH', body: { parent_id: folder } } );
					}
					return api( 'files/' + target.id, { method: 'PATCH', body: { folder_id: folder } } );
				} );

				Promise.all( requests ).then( function () {
					closeModal();
					toast( '已移动 ' + targets.length + ' 个对象' );
					loadSummary();
					loadItems( true );
				} ).catch( function ( error ) { modalError( error.message ); } );
			}
		} );

		renderList();

		api( 'folders' ).then( function ( payload ) {
			picker.flat = flatten( payload.tree || [], 0, [] );
			picker.blocked = blockedFor( payload.tree || [] );
			renderList();
		} ).catch( function ( error ) {
			var list = document.getElementById( 'sufm-move-list' );
			if ( list ) { list.innerHTML = '<p class="sufm-dialog-error">' + escapeHtml( error.message ) + '</p>'; }
		} );

		moveClickHandler = function ( event ) {
			var row = event.target.closest( '[data-move-pick]' );
			if ( row ) {
				picker.folder = Number( row.getAttribute( 'data-move-pick' ) );
				renderList();
			}
		};

		el.modalBody.addEventListener( 'click', moveClickHandler );
		moveDblHandler = function ( event ) {
			var row = event.target.closest( '[data-move-pick]' );
			if ( row ) {
				picker.folder = Number( row.getAttribute( 'data-move-pick' ) );
				renderList();
				el.modalConfirm.click();
			}
		};
		el.modalBody.addEventListener( 'dblclick', moveDblHandler );

		var search = document.getElementById( 'sufm-move-search' );
		if ( search ) {
			search.addEventListener( 'input', function () {
				picker.query = search.value || '';
				renderList();
			} );
		}
	}

	function requestDelete( targets ) {
		var items = targets.map( function ( target ) {
			return '<li>' + escapeHtml( target.name ) + '</li>';
		} ).join( '' );

		openModal( {
			title: '申请删除',
			body: '<p class="sufm-dialog-copy">将把这些内容提交给管理员审核：</p>' +
				'<ul class="sufm-detail-list">' + items + '</ul>' +
				'<div class="sufm-notice-box">' + escapeHtml( notice.text || '删除后文章、商品、头像等位置的链接可能失效，请自行确认后再提交。' ) + '</div>' +
				'<label class="sufm-field-label" style="margin-top:14px">申请理由（可选）</label>' +
				'<textarea class="sufm-field" id="sufm-reason" rows="2" maxlength="300"></textarea>' +
				'<label class="sufm-check-line" style="margin-top:12px"><input type="checkbox" class="sufm-check" id="sufm-confirm-ref">' +
				'<span>我已自行确认引用影响，申请删除所选内容</span></label>' +
				'<p class="sufm-hint sufm-muted">通过审核不代表立即删除：管理员仍需在后台再次确认执行。</p>',
			confirmLabel: '提交申请',
			onConfirm: function () {
				var box = document.getElementById( 'sufm-confirm-ref' );
				if ( ! box || ! box.checked ) {
					modalError( '请先勾选确认引用影响。' );
					return;
				}

				var key = ( window.crypto && crypto.randomUUID ) ? crypto.randomUUID() : 'r-' + Date.now();

				api( 'delete-requests', {
					method: 'POST',
					body: {
						items: targets.map( function ( target ) { return { type: target.type, id: target.id }; } ),
						reason: ( document.getElementById( 'sufm-reason' ) || {} ).value || '',
						idempotency_key: key,
						notice_version: notice.version || '',
						confirmed: true
					}
				} ).then( function () {
					closeModal();
					toast( '删除申请已提交，等待管理员审核' );
					state.filter = 'requests';
					syncTabs();
					enterRequests();
					loadSummary();
				} ).catch( function ( error ) { modalError( error.message ); } );
			}
		} );
	}

	function withdrawRequest( id ) {
		openModal( {
			title: '撤回申请',
			body: '<p class="sufm-dialog-copy">撤回后该申请不再进入审核队列，文件会恢复为可用状态。</p>',
			confirmLabel: '撤回',
			onConfirm: function () {
				api( 'delete-requests/' + id + '/withdraw', { method: 'POST' } ).then( function () {
					closeModal();
					toast( '申请已撤回' );
					loadRequests( true );
				} ).catch( function ( error ) { modalError( error.message ); } );
			}
		} );
	}

	/* ---------------------------------------------------------------------
	 * Menus and actions
	 * ------------------------------------------------------------------ */

	function targetFor( id ) {
		var file = state.files.filter( function ( item ) { return Number( item.id ) === Number( id ); } )[0];
		return file ? { type: 'file', id: Number( id ), name: file.name } : null;
	}

	function findFolderNode( id ) {
		return document.querySelector( '#sufm-folders [data-sufm-open-folder="' + Number( id ) + '"] .sufm-folder-name, #sufm-files [data-sufm-open-folder="' + Number( id ) + '"] .sufm-folder-name' );
	}

	function folderTarget( id ) {
		var node = findFolderNode( id );
		return { type: 'folder', id: Number( id ), name: node ? node.textContent : '文件夹' };
	}

	function enterFolder( id ) {
		state.folder = Number( id );
		state.search = '';
		el.search.value = '';
		loadItems( true );
	}

	function openFileById( id ) {
		var file = state.files.filter( function ( item ) { return Number( item.id ) === Number( id ); } )[0];
		if ( file && file.url ) { window.open( file.url, '_blank', 'noopener' ); }
	}

	function downloadFileById( id ) {
		var file = state.files.filter( function ( item ) { return Number( item.id ) === Number( id ); } )[0];
		if ( ! file || ! file.url ) { return; }
		var link = document.createElement( 'a' );
		link.href = file.url;
		link.download = file.name || '';
		link.rel = 'noopener';
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
	}

	/* The desktop list scrolls inside its card, so row menus are pinned to the
	   viewport instead of the row: otherwise the panel would be clipped by the
	   scroll container. */
	function resetMenuPosition( panel ) {
		panel.style.position = '';
		panel.style.left = '';
		panel.style.top = '';
		panel.style.right = '';
	}

	/* The theme wraps this widget in a card that creates a containing block for
	 * fixed elements (transform / filter), so viewport coordinates no longer
	 * match the rendered position and the panel lands off screen. Probe where
	 * the origin lands first, then offset every placement by that error. */
	function menuOriginOffset( panel ) {
		var previous = { left: panel.style.left, top: panel.style.top, right: panel.style.right, position: panel.style.position };
		panel.style.position = 'fixed';
		panel.style.right = 'auto';
		panel.style.left = '0px';
		panel.style.top = '0px';
		var probe = panel.getBoundingClientRect();
		panel.style.position = previous.position;
		panel.style.left = previous.left;
		panel.style.top = previous.top;
		panel.style.right = previous.right;
		return { x: probe.left, y: probe.top, width: probe.width, height: probe.height };
	}

	function positionMenu( panel, x, y, alignRight ) {
		var probe = menuOriginOffset( panel );
		var width = probe.width || 152;
		var height = probe.height || 0;
		var left = alignRight ? x - width : x;
		var top = y;

		if ( left + width > window.innerWidth - 12 ) { left = window.innerWidth - width - 12; }
		if ( left < 12 ) { left = 12; }
		if ( height && top + height > window.innerHeight - 12 ) { top = y - height - 12; }
		if ( top < 12 ) { top = 12; }

		panel.style.position = 'fixed';
		panel.style.right = 'auto';
		panel.style.left = ( left - probe.x ) + 'px';
		panel.style.top = ( top - probe.y ) + 'px';
	}

	/* Cards clip their own children, so an open panel is moved to a body-level
	 * layer while it is visible and put back into the card once it closes. */
	var menuLayer = null;
	var menuOrigin = new WeakMap();

	function menuLayerNode() {
		if ( menuLayer ) { return menuLayer; }

		menuLayer = document.createElement( 'div' );
		menuLayer.className = 'sufm-menu-layer';
		menuLayer.addEventListener( 'click', function ( event ) {
			var clicked = event.target.closest( '[data-sufm-act]' );
			if ( clicked ) { runMenuAction( clicked ); }
		} );
		document.body.appendChild( menuLayer );

		return menuLayer;
	}

	function closePanel( panel ) {
		if ( ! panel ) { return; }
		panel.hidden = true;
		resetMenuPosition( panel );
		var origin = menuOrigin.get( panel );
		if ( origin && origin.isConnected && panel.parentNode !== origin ) {
			origin.appendChild( panel );
		}
	}

	function closeAllMenus() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-sufm-menu-panel]' ), closePanel );
		Array.prototype.forEach.call( document.querySelectorAll( '.is-menu-open' ), function ( node ) {
			node.classList.remove( 'is-menu-open' );
		} );
	}

	function openMenuAt( panel, x, y, alignRight ) {
		if ( ! menuOrigin.has( panel ) ) { menuOrigin.set( panel, panel.parentNode ); }
		menuLayerNode().appendChild( panel );
		panel.hidden = false;
		positionMenu( panel, x, y, alignRight );
	}

	function runMenuAction( act ) {
		var id = Number( act.getAttribute( 'data-id' ) );
		var name = act.getAttribute( 'data-sufm-act' );

		closeAllMenus();

		if ( 'open' === name ) { openFileById( id ); }
		if ( 'download' === name ) { downloadFileById( id ); }
		if ( 'rename' === name ) { renameFile( id ); }
		if ( 'move' === name ) { movePicker( [ { type: 'file', id: id } ] ); }
		if ( 'up' === name ) { moveUp( [ { type: act.getAttribute( 'data-kind' ) === 'folder' ? 'folder' : 'file', id: id } ] ); }
		if ( 'copy' === name ) {
			var file = state.files.filter( function ( item ) { return Number( item.id ) === Number( id ); } )[0];
			if ( file ) {
				if ( navigator.clipboard ) {
					navigator.clipboard.writeText( file.url ).then( function () { toast( '链接已复制' ); } );
				} else {
					toast( file.url );
				}
			}
		}
		if ( 'delete' === name ) {
			var target = targetFor( id );
			if ( target ) { requestDelete( [ target ] ); }
		}
		if ( 'fopen' === name ) { enterFolder( id ); }
		if ( 'frename' === name ) { renameFolder( id ); }
		if ( 'fmove' === name ) { movePicker( [ { type: 'folder', id: id } ] ); }
		if ( 'fdelete' === name ) {
			var folderName = '';
			var folderNode = document.querySelector( '#sufm-folders [data-sufm-open-folder="' + id + '"] .sufm-folder-name, #sufm-files [data-sufm-open-folder="' + id + '"] .sufm-folder-name' );
			if ( folderNode ) { folderName = folderNode.textContent; }
			requestDelete( [ { type: 'folder', id: id, name: folderName || ( '文件夹 #' + id ) } ] );
		}
	}

	root.addEventListener( 'click', function ( event ) {
		var foldBtn = event.target.closest( '[data-sufm-fold]' );
		if ( foldBtn ) {
			event.preventDefault();
			setFolded( ! state.folded );
			return;
		}

		var allBtn = event.target.closest( '[data-sufm-select-all]' );
		if ( allBtn ) {
			event.preventDefault();
			selectAll( ! allSelected() );
			return;
		}

		var menuBtn = event.target.closest( '[data-sufm-menu]' );

		if ( menuBtn ) {
			var panel = menuBtn.__sufmPanel || menuBtn.parentNode.querySelector( '[data-sufm-menu-panel]' );
			var open = !!( panel && ! panel.hidden );

			closeAllMenus();

			if ( panel && ! open ) {
				var host = menuBtn.closest( '.sufm-row, .sufm-file-card, .sufm-folder' );
				if ( host ) { host.classList.add( 'is-menu-open' ); }

				var rect = menuBtn.getBoundingClientRect();
				openMenuAt( panel, rect.right, rect.bottom + 6, true );
				menuBtn.__sufmPanel = panel;
			}
			return;
		}

		var act = event.target.closest( '[data-sufm-act]' );
		if ( act ) {
			runMenuAction( act );
			return;
		}

		var modify = event.shiftKey || event.ctrlKey || event.metaKey;
		var fileRow = event.target.closest( '[data-sufm-file]' );
		if ( modify && fileRow && ! event.target.closest( '.sufm-grid-check, .sufm-row-check, .sufm-check, [data-sufm-menu], [data-sufm-act]' ) ) {
			closeAllMenus();
			event.preventDefault();
			event.stopPropagation();
			var fileId = fileRow.getAttribute( 'data-sufm-file' );
			if ( event.shiftKey ) { selectRange( fileId ); } else { toggleFile( fileId ); }
			return;
		}

		var folderOpen = event.target.closest( '[data-sufm-open-folder]' );
		if ( folderOpen && ! event.target.closest( '.sufm-more' ) ) {
			state.folder = Number( folderOpen.getAttribute( 'data-sufm-open-folder' ) );
			state.search = '';
			el.search.value = '';
			loadItems( true );
			return;
		}

		var crumb = event.target.closest( '.sufm-crumb' );
		if ( crumb ) {
			state.folder = Number( crumb.getAttribute( 'data-sufm-folder' ) );

			// The root crumb is the way out of a search or a category view:
			// leaving it on would keep the listing cross-folder.
			if ( 0 === state.folder && 'all' === state.scope ) {
				state.filter = 'all';
				state.search = '';
				el.search.value = '';
				if ( 'function' === typeof syncTabs ) { syncTabs(); }
			}

			loadItems( true );
			return;
		}

		var fileOpen = event.target.closest( '[data-sufm-open-file]' );
		if ( fileOpen ) {
			var selected = state.files.filter( function ( item ) { return Number( item.id ) === Number( fileOpen.getAttribute( 'data-sufm-open-file' ) ); } )[0];
			if ( selected ) { window.open( selected.url, '_blank', 'noopener' ); }
			return;
		}

		var emptyAction = event.target.closest( '[data-sufm-empty-action]' );
		if ( emptyAction ) {
			var emptyKind = emptyAction.getAttribute( 'data-sufm-empty-action' );
			if ( 'retry' === emptyKind ) {
				loadItems( true );
			} else if ( 'back' === emptyKind ) {
				state.filter = 'all';
				state.requestsMode = false;
				app.classList.remove( 'is-requests' );
				el.drop.hidden = false;
				syncTabs();
				loadItems( true );
			} else {
				el.input.click();
			}
			return;
		}

		var more = event.target.closest( '[data-sufm-more]' );
		if ( more ) { loadItems( false ); return; }

		var withdraw = event.target.closest( '[data-sufm-withdraw]' );
		if ( withdraw ) { withdrawRequest( Number( withdraw.getAttribute( 'data-sufm-withdraw' ) ) ); return; }

		var reqMore = event.target.closest( '[data-sufm-requests-more]' );
		if ( reqMore ) { loadRequests( false, true ); return; }

		var batch = event.target.closest( '[data-sufm-batch]' );
		if ( batch ) {
			var action = batch.getAttribute( 'data-sufm-batch' );
			var ids = Object.keys( state.selection ).map( Number );

			if ( 'cancel' === action ) {
				clearSelection();
			} else if ( 'move' === action && ids.length ) {
				movePicker( ids.map( function ( id ) { return { type: 'file', id: id }; } ) );
			} else if ( 'up' === action && ids.length ) {
				moveUp( ids.map( function ( id ) { return { type: 'file', id: id }; } ) );
			} else if ( 'delete' === action && ids.length ) {
				requestDelete( ids.map( function ( id ) { return { type: 'file', id: id, name: '文件 #' + id }; } ) );
			}
			return;
		}
	} );

	root.addEventListener( 'change', function ( event ) {
		if ( event.target.id === 'sufm-check-all' ) {
			selectAll( event.target.checked );
			return;
		}

		var box = event.target.closest( '[data-sufm-select]' );
		if ( box ) {
			var id = box.getAttribute( 'data-sufm-select' );
			if ( box.checked ) { state.selection[ id ] = true; } else { delete state.selection[ id ]; }
			state.anchor = Number( id );
			state.selectedCount = Object.keys( state.selection ).length;
			if ( 0 === state.selectedCount ) { state.anchor = 0; }
			updateBatch();
		}
	} );

	root.addEventListener( 'keydown', function ( event ) {
		var folder = event.target.closest( '[data-sufm-open-folder]' );
		if ( folder && ( event.key === 'Enter' || event.key === ' ' ) ) {
			event.preventDefault();
			state.folder = Number( folder.getAttribute( 'data-sufm-open-folder' ) );
			loadItems( true );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest( '[data-sufm-menu], [data-sufm-menu-panel]' ) ) {
			closeAllMenus();
		}
	} );

	root.addEventListener( 'click', function ( event ) {
		var box = checkboxOf( event.target );
		if ( ! box || box.disabled ) { return; }
		if ( ! ( event.shiftKey || event.ctrlKey || event.metaKey ) ) { return; }

		closeAllMenus();
		event.preventDefault();
		event.stopPropagation();

		var id = box.getAttribute( 'data-sufm-select' );
		if ( event.shiftKey ) { selectRange( id ); } else { toggleFile( id ); }

		// Canceling a checkbox click restores its pre-click checked state after
		// dispatch, which can overwrite the boxes we just set. Re-apply once the
		// browser has finished the canceled activation.
		window.setTimeout( updateBatch, 0 );
	}, true );

	document.addEventListener( 'pointerdown', function ( event ) {
		// Ctrl/Command+A belongs to the file panel only while the pointer or the
		// focus is in it, so the theme's own select-all keeps working elsewhere.
		state.pointerInside = app.contains( event.target );
	}, true );

	document.addEventListener( 'keydown', function ( event ) {
		if ( el.modal && el.modal.open ) { return; }

		if ( 'Escape' === event.key ) {
			var hadMenu = hasOpenMenus();
			closeAllMenus();
			if ( hadMenu ) { return; }
			if ( state.selectedCount > 0 ) {
				event.preventDefault();
				clearSelection();
			}
			return;
		}

		if ( ! ( event.ctrlKey || event.metaKey ) || event.altKey || event.shiftKey ) { return; }
		if ( 'a' !== String( event.key || '' ).toLowerCase() ) { return; }
		if ( ! state.pointerInside && ! app.contains( event.target ) ) { return; }
		if ( ! root.getClientRects().length || state.requestsMode || hasOpenMenus() ) { return; }
		if ( isTypingTarget( event.target ) || ! selectableIds().length ) { return; }

		event.preventDefault();
		selectAll( true );
	} );

	window.addEventListener( 'blur', function () {
		closeAllMenus();
	} );

	/* ---------------------------------------------------------------------
	 * Toolbar, tabs, drop zone
	 * ------------------------------------------------------------------ */

	function syncTabs() {
		document.querySelectorAll( '.sufm-tab[data-sufm-filter]' ).forEach( function ( tab ) {
			var on = tab.getAttribute( 'data-sufm-filter' ) === state.filter;
			tab.classList.toggle( 'is-active', on );
			tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			tab.tabIndex = on ? 0 : -1;
		} );
	}

	document.querySelectorAll( '.sufm-tab[data-sufm-filter]' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			state.filter = tab.getAttribute( 'data-sufm-filter' );
			state.search = '';
			el.search.value = '';
			syncTabs();

			if ( 'requests' === state.filter ) {
				enterRequests();
			} else {
				state.requestsMode = false;
				app.classList.remove( 'is-requests' );
				state.folder = 0;
				el.drop.hidden = false;
				loadItems( true );
			}
		} );
	} );

	document.querySelectorAll( '[data-sufm-view]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			state.view = button.getAttribute( 'data-sufm-view' );
			localStorage.setItem( 'sufm_view', state.view );
			document.querySelectorAll( '[data-sufm-view]' ).forEach( function ( node ) {
				var on = node === button;
				node.classList.toggle( 'is-active', on );
				node.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			if ( state.requestsMode ) { loadRequests( true ); } else { renderFiles(); }
		} );

		var on = button.getAttribute( 'data-sufm-view' ) === state.view;
		button.classList.toggle( 'is-active', on );
		button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
	} );

	el.search.addEventListener( 'input', function () {
		syncSearchClear();
		window.clearTimeout( searchTimer );
		searchTimer = window.setTimeout( function () {
			state.search = el.search.value.trim();

			// A search spans every folder and every kind, so it takes over the
			// category tab while it is running: leaving 图片 selected would
			// silently hide the PDF the user is looking for.
			if ( state.search && ( 'all' !== state.filter || state.requestsMode ) ) {
				state.filter = 'all';
				state.requestsMode = false;
				syncTabs();
			}

			loadItems( true );
		}, 320 );
	} );

	if ( el.searchClear ) {
		el.searchClear.addEventListener( 'click', function ( event ) {
			// The button sits inside the <label>; without both guards the label
			// would re-focus the input and the click would look like a no-op.
			event.preventDefault();
			event.stopPropagation();

			if ( '' === el.search.value ) {
				return;
			}

			el.search.value = '';
			window.clearTimeout( searchTimer );
			state.search = '';
			syncSearchClear();
			loadItems( true );
			el.search.focus();
		} );
	}

	el.search.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && '' !== el.search.value ) {
			event.stopPropagation();
			el.search.value = '';
			window.clearTimeout( searchTimer );
			state.search = '';
			syncSearchClear();
			loadItems( true );
		}
	} );

	el.input.addEventListener( 'change', function () { enqueueFiles( el.input.files ); } );
	el.dropSelect.addEventListener( 'click', function () { el.input.click(); } );
	el.alertRetry.addEventListener( 'click', function () { if ( retryAction ) { retryAction(); } } );

	document.getElementById( 'sufm-upload' ).addEventListener( 'click', function () {
		if ( state.overQuota ) {
			applyQuotaLock();
			return;
		}
		el.input.click();
	} );
	document.getElementById( 'sufm-new-folder' ).addEventListener( 'click', newFolder );

	[ 'dragenter', 'dragover' ].forEach( function ( type ) {
		app.addEventListener( type, function ( event ) {
			if ( dragMove ) { return; }
			event.preventDefault();
			el.drop.classList.add( 'is-over' );
		} );
	} );

	[ 'dragleave', 'dragend' ].forEach( function ( type ) {
		app.addEventListener( type, function ( event ) {
			if ( dragMove ) { return; }
			if ( event.target === el.drop || ! app.contains( event.relatedTarget ) ) {
				el.drop.classList.remove( 'is-over' );
			}
		} );
	} );

	/* A fixed-position row menu would drift away from its row when the list
	   scrolls; close it instead. */
	el.files.addEventListener( 'scroll', function () {
		if ( ! state.desk ) { return; }
		document.querySelectorAll( '[data-sufm-menu-panel]:not([hidden])' ).forEach( function ( node ) {
			node.hidden = true;
			resetMenuPosition( node );
		} );
		document.querySelectorAll( '.is-menu-open' ).forEach( function ( node ) { node.classList.remove( 'is-menu-open' ); } );
	}, { passive: true } );

	app.addEventListener( 'drop', function ( event ) {
		if ( dragMove ) { return; }
		event.preventDefault();
		el.drop.classList.remove( 'is-over' );
		if ( event.dataTransfer && event.dataTransfer.files ) {
			enqueueFiles( event.dataTransfer.files );
		}
	} );

	/* ---------------------------------------------------------------------
	 * Drag files/folders into another folder
	 * ------------------------------------------------------------------ */

	var dragBadge = null;
	var dragBlocked = false;

	app.addEventListener( 'mousedown', function ( event ) {
		dragBlocked = !! event.target.closest( 'input, label, .sufm-more, .sufm-menu' );
	} );

	document.addEventListener( 'mouseup', function () { dragBlocked = false; } );

	function dragBadgeNode() {
		if ( dragBadge ) { return dragBadge; }
		dragBadge = document.createElement( 'div' );
		dragBadge.className = 'sufm-drag-badge';
		dragBadge.setAttribute( 'aria-hidden', 'true' );
		document.body.appendChild( dragBadge );
		return dragBadge;
	}

	function clearDropTargets() {
		document.querySelectorAll( '.is-drop-target' ).forEach( function ( node ) {
			node.classList.remove( 'is-drop-target' );
		} );
	}

	function dragTargetFor( node ) {
		if ( ! node ) { return null; }
		var folderNode = node.closest( '[data-sufm-drop-folder], [data-sufm-open-folder], .sufm-crumb[data-sufm-folder]' );
		if ( ! folderNode ) { return null; }
		var raw = folderNode.getAttribute( 'data-sufm-drop-folder' );
		if ( raw === null ) { raw = folderNode.getAttribute( 'data-sufm-open-folder' ); }
		if ( raw === null ) { raw = folderNode.getAttribute( 'data-sufm-folder' ); }
		var id = Number( raw );
		return isNaN( id ) ? null : id;
	}

	function draggedFolderIds() {
		if ( ! dragMove ) { return []; }
		return dragMove.targets.filter( function ( target ) { return 'folder' === target.type; } )
			.map( function ( target ) { return Number( target.id ); } );
	}

	function moveTargets( targets, folderId, folderName ) {
		var pending = [];
		var skipped = 0;

		targets.forEach( function ( target ) {
			if ( 'folder' === target.type ) {
				/* Folders in the current view already live here. */
				if ( Number( target.id ) === Number( folderId ) || Number( folderId ) === Number( state.folder ) ) {
					skipped++;
					return;
				}
				pending.push( api( 'folders/' + target.id, { method: 'PATCH', body: { parent_id: folderId } } ) );
				return;
			}
			var file = state.files.filter( function ( item ) { return Number( item.id ) === Number( target.id ); } )[0];
			if ( file && Number( file.folder_id ) === Number( folderId ) ) {
				skipped++;
				return;
			}
			pending.push( api( 'files/' + target.id, { method: 'PATCH', body: { folder_id: folderId } } ) );
		} );

		if ( ! pending.length ) {
			toast( skipped ? '已经在这个位置了。' : '没有可移动的对象。' );
			return;
		}

		Promise.all( pending ).then( function () {
			toast( '已移动 ' + pending.length + ' 个对象到「' + folderName + '」' );
			state.selection = {};
			state.selectedCount = 0;
			updateBatch();
			loadSummary();
			loadItems( true );
		} ).catch( function ( error ) {
			toast( error.message || '移动失败，请重试。', true );
			loadItems( true );
		} );
	}

	app.addEventListener( 'dragstart', function ( event ) {
		if ( dragBlocked ) { event.preventDefault(); return; }
		var handle = event.target.closest( '[data-sufm-drag]' );
		if ( ! handle ) { return; }

		var type = handle.getAttribute( 'data-sufm-drag' );
		var id = Number( 'folder' === type
			? handle.getAttribute( 'data-sufm-open-folder' )
			: handle.getAttribute( 'data-sufm-file' ) );
		var targets = [];

		if ( 'file' === type ) {
			var selected = Object.keys( state.selection ).map( Number );
			if ( selected.length > 1 && state.selection[ id ] ) {
				targets = selected.map( function ( fileId ) { return { type: 'file', id: fileId }; } );
			} else {
				targets = [ { type: 'file', id: id } ];
			}
		} else {
			targets = [ { type: 'folder', id: id } ];
		}

		dragMove = { targets: targets, sourceType: type, sourceId: id };

		if ( event.dataTransfer ) {
			event.dataTransfer.effectAllowed = 'move';
			try {
				event.dataTransfer.setData( 'text/plain', targets.length > 1 ? targets.length + ' 个文件' : handle.textContent.trim() );
			} catch ( e ) {}
			var badge = dragBadgeNode();
			badge.textContent = targets.length > 1 ? '移动 ' + targets.length + ' 个文件' : '移动到文件夹';
			badge.style.left = '-200px';
			badge.style.top = '-200px';
			try { event.dataTransfer.setDragImage( badge, 18, 18 ); } catch ( e ) {}
		}

		handle.classList.add( 'is-dragging' );
		app.classList.add( 'is-drag-moving' );
	} );

	function endDrag() {
		document.querySelectorAll( '.is-dragging' ).forEach( function ( node ) { node.classList.remove( 'is-dragging' ); } );
		app.classList.remove( 'is-drag-moving' );
		clearDropTargets();
		if ( dragBadge && dragBadge.parentNode ) { dragBadge.parentNode.removeChild( dragBadge ); }
		dragBadge = null;
		dragMove = null;
	}

	app.addEventListener( 'dragend', function () {
		if ( dragMove ) { endDrag(); }
	} );

	app.addEventListener( 'dragover', function ( event ) {
		if ( ! dragMove ) { return; }
		var folderId = dragTargetFor( event.target );
		if ( folderId === null ) { return; }

		var dragged = draggedFolderIds();
		if ( dragged.indexOf( folderId ) > -1 ) { return; }
		if ( event.target.closest( '[data-sufm-drop-locked]' ) ) { return; }

		event.preventDefault();
		if ( event.dataTransfer ) { event.dataTransfer.dropEffect = 'move'; }

		var node = event.target.closest( '[data-sufm-drop-folder], [data-sufm-open-folder], .sufm-crumb[data-sufm-folder]' );
		if ( node && ! node.classList.contains( 'is-drop-target' ) ) {
			clearDropTargets();
			node.classList.add( 'is-drop-target' );
		}
	} );

	app.addEventListener( 'dragleave', function ( event ) {
		if ( ! dragMove ) { return; }
		var node = event.target.closest( '.is-drop-target' );
		if ( node && ! node.contains( event.relatedTarget ) ) {
			node.classList.remove( 'is-drop-target' );
		}
	} );

	app.addEventListener( 'drop', function ( event ) {
		if ( ! dragMove ) { return; }
		var folderId = dragTargetFor( event.target );
		if ( folderId === null ) { return; }

		if ( event.target.closest( '[data-sufm-drop-locked]' ) ) { return; }

		event.preventDefault();
		event.stopPropagation();
		clearDropTargets();

		var targets = dragMove.targets.slice();
		var dragged = draggedFolderIds();
		if ( dragged.indexOf( folderId ) > -1 ) { return; }

		var targetNode = findFolderNode( folderId );
		var folderName = folderId > 0 && targetNode ? targetNode.textContent.trim() : ( strings.allFiles || '全部文件' );

		endDrag();
		moveTargets( targets, folderId, folderName );
	} );

	/* ---------------------------------------------------------------------
	 * Right-click context menu
	 * ------------------------------------------------------------------ */

	/* Panels live in a body-level layer while open, so keyboard events inside
	 * them no longer bubble through the widget root. */
	document.addEventListener( 'keydown', function ( event ) {
		var menuItem = event.target.closest( '[data-sufm-menu-panel] [role="menuitem"]' );
		if ( menuItem && ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) ) {
			var items = Array.prototype.slice.call( menuItem.parentNode.querySelectorAll( '[role="menuitem"]' ) );
			var next = items.indexOf( menuItem ) + ( 'ArrowDown' === event.key ? 1 : -1 );
			if ( next < 0 ) { next = items.length - 1; }
			if ( next >= items.length ) { next = 0; }
			event.preventDefault();
			items[ next ].focus();
			return;
		}

		var isMenuKey = 'ContextMenu' === event.key || ( event.shiftKey && 'F10' === event.key );
		if ( ! isMenuKey || state.requestsMode ) { return; }

		var row = event.target.closest( '[data-sufm-file], [data-sufm-open-folder]' );
		if ( ! row ) { return; }
		var panel = row.__sufmPanel || row.querySelector( '[data-sufm-menu-panel]' );
		if ( ! panel ) { return; }

		event.preventDefault();
		closeAllMenus();
		row.classList.add( 'is-menu-open' );
		row.__sufmPanel = panel;
		var rect = row.getBoundingClientRect();
		openMenuAt( panel, rect.right - 12, rect.top + 12, false );
		var first = panel.querySelector( 'button' );
		if ( first ) { first.focus(); }
	} );

	app.addEventListener( 'contextmenu', function ( event ) {
		if ( state.requestsMode ) { return; }

		var row = event.target.closest( '[data-sufm-file], [data-sufm-open-folder]' );
		if ( ! row || ! app.contains( row ) ) { return; }

		var panel = row.__sufmPanel || row.querySelector( '[data-sufm-menu-panel]' );
		if ( ! panel ) { return; }

		event.preventDefault();

		closeAllMenus();

		row.classList.add( 'is-menu-open' );
		row.__sufmPanel = panel;
		openMenuAt( panel, event.clientX + 2, event.clientY + 2, false );
	} );

	/* Keep the desktop row/table branch in sync when the viewport crosses the
	 * breakpoint (window resize, tablet rotation, devtools). The CSS switches
	 * at 1200px; the JS render branch must follow it. */
	if ( deskMedia ) {
		var onDeskChange = function ( event ) {
			var next = !!( event && event.matches );
			if ( next === state.desk ) { return; }
			state.desk = next;
			if ( state.requestsMode ) { return; }
			renderFolders( state.folders );
			renderFiles();
		};

		if ( typeof deskMedia.addEventListener === 'function' ) {
			deskMedia.addEventListener( 'change', onDeskChange );
		} else if ( typeof deskMedia.addListener === 'function' ) {
			deskMedia.addListener( onDeskChange );
		}
	}

	/* ---------------------------------------------------------------------
	 * Capacity detail dialog
	 * ------------------------------------------------------------------ */

	var capacityCache = null;
	var capacityBusy = false;
	var capacityFocus = null;
	var capacityExpandNext = false;

	function capacityBox() {
		return document.getElementById( 'sufm-capacity-dialog' );
	}

	function capacityNode( selector ) {
		return document.querySelector( selector );
	}

	function capacityScene( name ) {
		var loading = capacityNode( '[data-sufm-capacity-loading]' );
		var error = capacityNode( '[data-sufm-capacity-error]' );
		var content = capacityNode( '[data-sufm-capacity-content]' );
		var body = capacityNode( '[data-sufm-capacity-body]' );

		if ( loading ) { loading.hidden = name !== 'loading'; }
		if ( error ) { error.hidden = name !== 'error'; }
		if ( content ) { content.hidden = name !== 'content'; }
		if ( body ) { body.setAttribute( 'aria-busy', name === 'loading' ? 'true' : 'false' ); }
	}

	function capacitySplit( human ) {
		var value = String( human || '' );
		var at = value.lastIndexOf( ' ' );

		if ( at < 1 ) {
			return { value: value, unit: '' };
		}

		return { value: value.slice( 0, at ), unit: value.slice( at + 1 ) };
	}

	function capacityText( selector, value ) {
		var node = capacityNode( selector );

		if ( node ) {
			node.textContent = value === undefined || value === null ? '' : String( value );
		}
	}

	function capacityMeta( row ) {
		var meta = document.createElement( 'span' );
		var parts = [];

		meta.className = 'sufm-capacity-row-meta';

		if ( row.tag ) {
			var tag = document.createElement( 'span' );

			tag.className = 'sufm-capacity-tag';
			tag.textContent = row.tag;
			parts.push( tag );
		}

		if ( row.note ) { parts.push( document.createTextNode( row.note ) ); }
		if ( row.expiry ) { parts.push( document.createTextNode( row.expiry ) ); }

		if ( row.days_label ) {
			var days = document.createElement( 'span' );

			days.className = 'sufm-capacity-days' + ( row.soon ? ' is-soon' : '' );
			days.textContent = row.days_label;
			parts.push( days );
		}

		parts.forEach( function ( part, index ) {
			if ( index > 0 ) { meta.appendChild( document.createTextNode( ' \u00b7 ' ) ); }
			meta.appendChild( part );
		} );

		return meta;
	}

	function capacityRow( row ) {
		var li = document.createElement( 'li' );
		var dot = document.createElement( 'span' );
		var main = document.createElement( 'div' );
		var name = document.createElement( 'span' );
		var size = document.createElement( 'span' );

		li.className = 'sufm-capacity-row';
		li.setAttribute( 'data-kind', row.kind || 'base' );

		dot.className = 'sufm-capacity-dot';
		dot.setAttribute( 'aria-hidden', 'true' );

		main.className = 'sufm-capacity-row-main';
		name.className = 'sufm-capacity-row-name';
		name.textContent = row.label || '';
		main.appendChild( name );
		main.appendChild( capacityMeta( row ) );

		size.className = 'sufm-capacity-row-size';
		size.textContent = row.human || '';

		if ( row.share > 0 ) {
			var share = document.createElement( 'small' );

			share.textContent = row.share + '%';
			size.appendChild( share );
		}

		li.appendChild( dot );
		li.appendChild( main );
		li.appendChild( size );

		return li;
	}

	function renderCapacity( payload ) {
		var total = capacitySplit( payload.total && payload.total.human );
		var percent = payload.used && payload.used.percent ? Number( payload.used.percent ) : 0;
		var fill = capacityNode( '[data-sufm-capacity-fill]' );
		var track = capacityNode( '[data-sufm-capacity-track]' );
		var list = capacityNode( '[data-sufm-capacity-list]' );
		var sources = payload.sources || [];
		var note = capacityNode( '[data-sufm-capacity-note]' );
		var noteText = capacityNode( '[data-sufm-capacity-note-text]' );
		var expiring = payload.expiring || {};
		var expand = capacityNode( '[data-sufm-capacity-expand]' );

		capacityText( '[data-sufm-capacity-value]', total.value );
		capacityText( '[data-sufm-capacity-unit]', total.unit );
		capacityText( '[data-sufm-capacity-used]', payload.used && payload.used.human );
		capacityText( '[data-sufm-capacity-free]', payload.free && payload.free.human );
		capacityText( '[data-sufm-capacity-percent]', payload.used && payload.used.percent_label );
		capacityText( '[data-sufm-capacity-files]', payload.files_label );
		capacityText( '[data-sufm-capacity-count]', sources.length ? '共 ' + sources.length + ' 项' : '' );

		if ( fill ) {
			// A used-but-tiny quota would render as an invisible sliver: keep a
			// minimum 1% stub so the bar still reads as "some usage".
			var width = Math.max( 0, Math.min( 100, percent ) );

			fill.style.width = ( width > 0 ? Math.max( 1, width ) : 0 ) + '%';
		}

		if ( track ) {
			track.setAttribute( 'aria-valuenow', String( Math.round( percent ) ) );
			track.classList.toggle( 'is-warning', percent >= 80 );
		}

		if ( list ) {
			list.textContent = '';
			sources.forEach( function ( row ) { list.appendChild( capacityRow( row ) ); } );
		}

		if ( note && noteText ) {
			note.hidden = ! ( expiring.count > 0 && expiring.text );
			noteText.textContent = expiring.text || '';
		}

		if ( expand ) {
			expand.hidden = ! payload.expand || ! document.getElementById( 'sufm-space-open' );
		}
	}

	function loadCapacity() {
		if ( capacityBusy ) { return; }

		capacityBusy = true;

		api( 'capacity' ).then( function ( payload ) {
			capacityBusy = false;
			capacityCache = payload;
			renderCapacity( payload );
			capacityScene( 'content' );
		} ).catch( function () {
			capacityBusy = false;

			// Keep the numbers already on screen when only the refresh failed.
			if ( capacityCache ) { return; }

			capacityScene( 'error' );
		} );
	}

	function openCapacity() {
		var box = capacityBox();

		if ( ! box ) { return; }

		capacityFocus = document.activeElement;
		box.classList.remove( 'is-closing' );

		if ( capacityCache ) {
			renderCapacity( capacityCache );
			capacityScene( 'content' );
		} else {
			capacityScene( 'loading' );
		}

		if ( typeof box.showModal === 'function' ) {
			box.showModal();
		} else {
			box.setAttribute( 'open', 'open' );
		}

		var close = box.querySelector( '[data-sufm-capacity-close]' );

		if ( close && close.focus ) { close.focus(); }

		loadCapacity();
	}

	function closeCapacity( thenExpand ) {
		var box = capacityBox();

		if ( ! box || box.classList.contains( 'is-closing' ) ) { return; }
		if ( ! box.hasAttribute( 'open' ) && box.open !== true ) { return; }

		capacityExpandNext = !! thenExpand;
		box.classList.add( 'is-closing' );

		var finished = false;

		var finish = function () {
			if ( finished ) { return; }

			finished = true;
			box.removeEventListener( 'animationend', onEnd );
			box.classList.remove( 'is-closing' );

			if ( typeof box.close === 'function' ) {
				box.close();
			} else {
				box.removeAttribute( 'open' );
			}

			if ( capacityFocus && typeof capacityFocus.focus === 'function' ) {
				capacityFocus.focus();
			}

			if ( capacityExpandNext ) {
				capacityExpandNext = false;

				var trigger = document.getElementById( 'sufm-space-open' );

				if ( trigger ) { trigger.click(); }
			}
		};

		var onEnd = function ( event ) {
			if ( event.target === box ) { finish(); }
		};

		box.addEventListener( 'animationend', onEnd );
		window.setTimeout( finish, 170 );
	}

	/* Delegated so the dialog keeps working after an AJAX tab swap. The document
	 * listener is installed once: a swapped tab calls start() again and a second
	 * listener would open the modal twice. */
	( function bindCapacityClicks() {
		if ( document.documentElement.hasAttribute( 'data-sufm-capacity-bound' ) ) { return; }

		document.documentElement.setAttribute( 'data-sufm-capacity-bound', '1' );

		document.addEventListener( 'click', function ( event ) {
			var target = event.target;

			if ( ! target || ! target.closest ) { return; }

			if ( target.closest( '#sufm-capacity-open' ) ) {
				event.preventDefault();
				openCapacity();
				return;
			}

			if ( target.closest( '[data-sufm-capacity-close]' ) ) {
				event.preventDefault();
				closeCapacity();
				return;
			}

			if ( target.closest( '[data-sufm-capacity-retry]' ) ) {
				event.preventDefault();
				capacityScene( 'loading' );
				loadCapacity();
				return;
			}

			if ( target.closest( '[data-sufm-capacity-expand]' ) ) {
				event.preventDefault();
				closeCapacity( true );
			}
		} );
	}() );

	( function bindCapacityShell() {
		var box = capacityBox();

		if ( ! box ) { return; }

		box.addEventListener( 'cancel', function ( event ) {
			event.preventDefault();
			closeCapacity();
		} );

		box.addEventListener( 'click', function ( event ) {
			if ( event.target === box ) { closeCapacity(); }
		} );
	}() );

	/* ---------------------------------------------------------------------
	 * Boot
	 * ------------------------------------------------------------------ */

	var bulkParam = '';
	try {
		bulkParam = new URLSearchParams( window.location.search ).get( 'sufm_request_ids' ) || '';
	} catch ( e ) {
		bulkParam = '';
	}

	Promise.all( [ loadSummary(), loadItems( true ) ] ).then( function () {
		if ( ! bulkParam ) { return; }
		var ids = bulkParam.split( ',' ).map( Number ).filter( function ( id ) { return id > 0; } );
		if ( ! ids.length ) { return; }
		requestDelete( ids.map( function ( id ) { return { type: 'file', id: id, name: '文件 #' + id }; } ) );
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( {}, '', window.location.pathname );
		}
	} );
	}

	function boot() {
		if ( ! window.sufmData ) {
			return;
		}

		var root = document.getElementById( 'sufm-app' );

		if ( root ) {
			if ( ! root.dataset.sufmStarted ) {
				start( root );
			}
			return;
		}

		// Zibll injects the tab body over AJAX after the click, so #sufm-app can
		// appear long after this script ran. Watch the DOM instead of guessing.
		var observer = new MutationObserver( function () {
			var late = document.getElementById( 'sufm-app' );

			if ( ! late ) {
				return;
			}

			observer.disconnect();

			if ( ! late.dataset.sufmStarted ) {
				start( late );
			}
		} );

		observer.observe( document.documentElement, { childList: true, subtree: true } );
	}

	boot();
}() );

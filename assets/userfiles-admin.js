/**
 * Admin review / execution screen for the user file space.
 */
( function () {
	'use strict';

	var cfg = window.sufmAdmin || {};
	var root = document.querySelector( '.sakura-sync-app' );

	if ( ! root || ! cfg.restUrl ) {
		return;
	}

	var state = {
		queuePage: 1,
		queuePer: 25,
		usersPage: 1,
		usersPer: 25,
		eventsPage: 1,
		eventsPer: 25,
		queueTotal: 0,
		selected: [],
		hash: '',
		batchId: 0,
		poll: 0,
	};

	var $ = function ( id ) {
		return document.getElementById( id );
	};

	function api( path, options ) {
		options = options || {};
		var headers = options.headers || {};
		headers[ 'X-WP-Nonce' ] = cfg.nonce;
		if ( options.body && ! headers[ 'Content-Type' ] && ! ( options.body instanceof FormData ) ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		return fetch( cfg.restUrl + path, Object.assign( {}, options, { headers: headers, credentials: 'same-origin' } ) ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data && data.message ? data.message : cfg.strings.failed );
				}
				return data;
			} );
		} );
	}

	function bytes( value ) {
		value = Number( value ) || 0;
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( value >= 1024 && i < units.length - 1 ) {
			value /= 1024;
			i += 1;
		}
		return ( i === 0 ? value : value.toFixed( value >= 10 ? 0 : 1 ) ) + ' ' + units[ i ];
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function statusLabel( status ) {
		var map = {
			pending_review: '待审核',
			approved: '已批准',
			rejected: '已驳回',
			revoked: '已撤销',
			withdrawn: '已撤回',
			needs_review: '需核实',
			queued: '待执行',
			executing: '执行中',
			completed: '已完成',
			partial_failed: '部分失败',
			failed: '失败',
			stopped: '已停止',
		};
		return map[ status ] || status;
	}

	function setPill( node, status ) {
		node.className = 'sufm-status sufm-status--' + status;
		node.textContent = statusLabel( status );
	}

	function toast( message, isError ) {
		var live = $ ( 'sufm-link-state' );
		if ( live ) {
			live.querySelector( 'span:last-child' ).textContent = message;
			live.classList.toggle( 'is-error', !! isError );
		}
	}

	/* Queue ------------------------------------------------------------- */

	function renderQueue( data ) {
		var body = $( 'sufm-queue-body' );
		if ( ! body ) {
			return;
		}

		body.textContent = '';
		var rows = data.rows || [];

		if ( ! rows.length ) {
			var tr = document.createElement( 'tr' );
			var td = el( 'td', 'sufm-empty', cfg.strings.empty );
			td.colSpan = 6;
			tr.appendChild( td );
			body.appendChild( tr );
		}

		rows.forEach( function ( row ) {
			var tr = document.createElement( 'tr' );

			tr.appendChild( el( 'td', 'sufm-mono', '#' + row.id ) );

			var user = el( 'td' );
			user.appendChild( el( 'strong', '', row.owner_name || ( '#' + row.owner_id ) ) );
			user.appendChild( el( 'span', 'sufm-muted', 'ID ' + row.owner_id ) );
			tr.appendChild( user );

			var objects = el( 'td' );
			objects.appendChild( el( 'span', '', ( row.item_count || 0 ) + ' ' + ( cfg.strings.items || '项' ) ) );
			objects.appendChild( el( 'span', 'sufm-muted', bytes( row.bytes || 0 ) ) );
			tr.appendChild( objects );

			var status = el( 'td' );
			var pill = el( 'span' );
			setPill( pill, row.status );
			status.appendChild( pill );
			tr.appendChild( status );

			tr.appendChild( el( 'td', 'sufm-muted', row.created_at || '' ) );

			var actions = el( 'td', 'sufm-col-actions' );
			actions.appendChild( buildActions( row ) );
			tr.appendChild( actions );

			body.appendChild( tr );
		} );

		var counts = data.counts || {};
		if ( $ ( 'sufm-hero-pending' ) ) {
			$ ( 'sufm-hero-pending' ).textContent = String( counts.pending_review || 0 );
		}
		if ( $ ( 'sufm-hero-approved' ) ) {
			$ ( 'sufm-hero-approved' ).textContent = String( counts.approved || 0 );
		}
		if ( $ ( 'sufm-hero-queued' ) ) {
			$ ( 'sufm-hero-queued' ).textContent = String( counts.queued || 0 );
		}

		state.queueTotal = data.total || 0;
		renderPager( $( 'sufm-queue-pager' ), state.queueTotal, state.queuePage, state.queuePer,
			function ( page ) { state.queuePage = page; loadQueue(); },
			function ( per ) { state.queuePer = per; state.queuePage = 1; loadQueue(); } );
		renderApproved( counts );
	}

	function buildActions( row ) {
		var wrap = el( 'div', 'sufm-row-actions' );

		if ( [ 'pending_review', 'needs_review' ].indexOf( row.status ) !== -1 ) {
			wrap.appendChild( actionButton( row, 'approve', cfg.strings.approve, 'btn btn--small btn--primary' ) );
			wrap.appendChild( actionButton( row, 'reject', cfg.strings.reject, 'btn btn--small' ) );
		}

		if ( row.status === 'pending_review' ) {
			wrap.appendChild( actionButton( row, 'needs_review', '需核实', 'btn btn--small' ) );
		}

		if ( row.status === 'approved' ) {
			wrap.appendChild( actionButton( row, 'revoke', cfg.strings.revoke, 'btn btn--small btn--danger' ) );
		}

		if ( row.status === 'queued' || row.status === 'executing' ) {
			wrap.appendChild( actionButton( row, 'stop', cfg.strings.stopped, 'btn btn--small btn--danger' ) );
		}

		if ( ! wrap.children.length ) {
			wrap.appendChild( el( 'span', 'sufm-muted', '—' ) );
		}

		return wrap;
	}

	function actionButton( row, decision, label, className ) {
		var button = el( 'button', className, label );
		button.type = 'button';
		button.addEventListener( 'click', function () {
			button.disabled = true;

			var path;
			var body = {};

			if ( decision === 'stop' ) {
				path = 'admin/delete-batches/' + row.batch_id + '/stop';
				body = {};
			} else if ( decision === 'revoke' ) {
				path = 'admin/delete-requests/' + row.id + '/revoke';
				body = { reason: '' };
			} else {
				path = 'admin/delete-requests/' + row.id + '/review';
				body = { decision: decision === 'needs_review' ? 'needs_review' : decision };
			}

			api( path, { method: 'POST', body: JSON.stringify( body ) } )
				.then( function () {
					toast( '已更新 #' + row.id );
					loadQueue();
					loadApproved();
				} )
				.catch( function ( error ) {
					button.disabled = false;
					toast( error.message, true );
				} );
		} );
		return button;
	}

	function renderPager( node, total, page, per, onPage, onPer ) {
		if ( ! node ) {
			return;
		}

		node.textContent = '';
		var pages = Math.max( 1, Math.ceil( ( total || 0 ) / per ) );
		if ( page > pages ) {
			page = pages;
		}

		var sizes = el( 'div', 'sufm-page-size' );
		sizes.appendChild( el( 'span', '', '每页' ) );
		[ 25, 50, 100 ].forEach( function ( size ) {
			var sizeBtn = el( 'button', 'sufm-size-btn' + ( size === per ? ' is-active' : '' ), String( size ) );
			sizeBtn.type = 'button';
			sizeBtn.addEventListener( 'click', function () {
				onPer( size );
			} );
			sizes.appendChild( sizeBtn );
		} );
		node.appendChild( sizes );

		var from = total ? ( page - 1 ) * per + 1 : 0;
		var to = Math.min( total || 0, page * per );
		node.appendChild( el( 'span', 'sufm-page-summary', '显示 ' + from + '–' + to + ' 条 · 共 ' + ( total || 0 ) + ' 条' ) );

		var nav = el( 'div', 'sufm-page-nav' );
		var prev = el( 'button', 'sufm-page-btn', '‹' );
		prev.type = 'button';
		prev.setAttribute( 'aria-label', '上一页' );
		prev.disabled = page <= 1;
		prev.addEventListener( 'click', function () {
			onPage( page - 1 );
		} );

		var next = el( 'button', 'sufm-page-btn', '›' );
		next.type = 'button';
		next.setAttribute( 'aria-label', '下一页' );
		next.disabled = page >= pages;
		next.addEventListener( 'click', function () {
			onPage( page + 1 );
		} );

		nav.appendChild( prev );
		nav.appendChild( el( 'span', 'sufm-page-now', page + ' / ' + pages ) );
		nav.appendChild( next );
		node.appendChild( nav );
	}

	function loadQueue() {
		var status = $ ( 'sufm-status' ).value;
		var search = $ ( 'sufm-search' ).value.trim();
		var query = 'admin/delete-requests?page=' + state.queuePage + '&per_page=' + state.queuePer + '&status=' + encodeURIComponent( status ) + '&search=' + encodeURIComponent( search );

		return api( query )
			.then( renderQueue )
			.catch( function ( error ) {
				toast( error.message, true );
			} );
	}

	/* Approved selection ------------------------------------------------ */

	function renderApproved( counts ) {
		var box = $( 'sufm-approved-list' );
		if ( ! box ) {
			return;
		}

		if ( ! counts || ! counts.approved ) {
			box.textContent = '';
			box.appendChild( el( 'p', 'sufm-empty', '暂无已批准、待执行的申请。' ) );
			updateConfirmState();
			return;
		}

		if ( ! box.dataset.loaded ) {
			box.dataset.loaded = '1';
			loadApproved();
		}
	}

	function loadApproved() {
		var box = $( 'sufm-approved-list' );
		if ( ! box ) {
			return;
		}

		api( 'admin/delete-requests?status=approved&per_page=50' )
			.then( function ( data ) {
				box.textContent = '';
				var rows = ( data.rows || [] ).filter( function ( row ) {
					return ! row.batch_id;
				} );

				if ( ! rows.length ) {
					box.appendChild( el( 'p', 'sufm-empty', '暂无已批准、待执行的申请。' ) );
					updateConfirmState();
					return;
				}

				rows.forEach( function ( row ) {
					var label = el( 'label', 'sufm-check' );
					var input = document.createElement( 'input' );
					input.type = 'checkbox';
					input.value = String( row.id );
					input.addEventListener( 'change', function () {
						state.hash = '';
						$ ( 'sufm-preview-box' ).hidden = true;
						updateConfirmState();
					} );

					label.appendChild( input );
					label.appendChild( el( 'strong', '', '#' + row.id + ' · ' + ( row.owner_name || row.owner_id ) ) );
					label.appendChild( el( 'span', 'sufm-muted', ( row.item_count || 0 ) + ' 项 · ' + bytes( row.bytes || 0 ) ) );
					box.appendChild( label );
				} );

				updateConfirmState();
			} )
			.catch( function ( error ) {
				toast( error.message, true );
			} );
	}

	function selected() {
		return Array.prototype.slice
			.call( document.querySelectorAll( '#sufm-approved-list input[type="checkbox"]:checked' ) )
			.map( function ( input ) {
				return Number( input.value );
			} );
	}

	function updateConfirmState() {
		var button = $( 'sufm-confirm' );
		if ( ! button ) {
			return;
		}

		var ids = selected();
		button.disabled = ! state.hash || ! ids.length;

		var count = $( 'sufm-pick-count' );
		if ( count ) {
			count.textContent = ids.length ? '已选 ' + ids.length + ' 项' : '未选择';
		}

		var step = $( 'sufm-step-confirm' );
		if ( step ) {
			step.classList.toggle( 'is-locked', button.disabled );
		}

		var previewButton = $( 'sufm-preview' );
		var previewStep   = $( 'sufm-step-preview' );
		var previewHint   = $( 'sufm-preview-hint' );
		var hasPicked     = ids.length > 0;

		if ( previewButton ) {
			previewButton.disabled = ! hasPicked;
			previewButton.title = hasPicked ? '' : '先在上一步勾选已批准的申请。';
		}

		if ( previewStep ) {
			previewStep.classList.toggle( 'is-locked', ! hasPicked );
		}

		if ( previewHint ) {
			previewHint.textContent = hasPicked ? '' : '先在上一步勾选已批准的申请，再核对范围。';
			previewHint.hidden = hasPicked;
		}
	}

	/* Preview + batch --------------------------------------------------- */

	function preview() {
		var ids = selected();
		if ( ! ids.length ) {
			var hint = $( 'sufm-preview-hint' );
			if ( hint ) {
				hint.hidden = false;
			}
			toast( '请先勾选至少一条已批准的申请。', true );
			return;
		}

		api( 'admin/delete-batches/preview', { method: 'POST', body: JSON.stringify( { requests: ids } ) } )
			.then( function ( data ) {
				state.hash = data.hash;
				var box = $( 'sufm-preview-box' );
				box.hidden = false;
				box.textContent = '';

				var head = el( 'p', 'sufm-preview-head' );
				head.appendChild( el( 'strong', '', ( data.items || 0 ) + ' 项' ) );
				head.appendChild( el( 'span', 'sufm-muted', ' 共 ' + bytes( data.bytes || 0 ) ) );
				box.appendChild( head );

				( data.requests || [] ).forEach( function ( item ) {
					box.appendChild( el( 'p', 'sufm-muted', '申请 #' + item.id + '：' + item.count + ' 项，' + bytes( item.bytes ) ) );
				} );

				box.appendChild( el( 'p', 'sufm-hint', '范围哈希 ' + state.hash.slice( 0, 12 ) + '…，确认时会再次比对。' ) );
				$ ( 'sufm-preview-hash' ).textContent = '#' + ids.join( ', #' );
				updateConfirmState();
			} )
			.catch( function ( error ) {
				toast( error.message, true );
			} );
	}

	function confirm() {
		var phrase = $ ( 'sufm-phrase' ).value;
		var ids = selected();

		api( 'admin/delete-batches', {
			method: 'POST',
			body: JSON.stringify( { requests: ids, phrase: phrase, hash: state.hash } ),
		} )
			.then( function ( data ) {
				state.batchId = data.batch_id || 0;
				$ ( 'sufm-phrase' ).value = '';
				state.hash = '';
				$ ( 'sufm-preview-box' ).hidden = true;
				loadApproved();
				loadQueue();
				pollBatch();
				toast( '批次 #' + state.batchId + ' 已建立，等待后台执行' );
			} )
			.catch( function ( error ) {
				toast( error.message, true );
			} );
	}

	function pollBatch() {
		if ( ! state.batchId ) {
			return;
		}

		clearTimeout( state.poll );

		api( 'admin/delete-batches/' + state.batchId )
			.then( function ( data ) {
				var box = $( 'sufm-batch-state' );
				var batch = data.batch || {};
				box.hidden = false;
				box.textContent = '';

				var line = el( 'p' );
				line.appendChild( el( 'strong', '', '批次 #' + batch.id + ' · ' ) );
				line.appendChild( el( 'span', '', statusLabel( batch.state ) ) );
				box.appendChild( line );
				box.appendChild( el( 'p', 'sufm-muted', '完成 ' + ( batch.done_items || 0 ) + ' / ' + ( batch.total_items || 0 ) + '，失败 ' + ( batch.failed_items || 0 ) ) );

				if ( [ 'queued', 'executing' ].indexOf( batch.state ) !== -1 ) {
					var stop = el( 'button', 'btn btn--small btn--danger', '停止批次' );
					stop.type = 'button';
					stop.addEventListener( 'click', function () {
						api( 'admin/delete-batches/' + batch.id + '/stop', { method: 'POST', body: '{}' } )
							.then( pollBatch )
							.catch( function ( error ) {
								toast( error.message, true );
							} );
					} );
					box.appendChild( stop );
					state.poll = setTimeout( pollBatch, 4000 );
				} else {
					toast( '批次 #' + batch.id + ' ' + statusLabel( batch.state ) );
					loadQueue();
				}
			} )
			.catch( function ( error ) {
				toast( error.message, true );
			} );
	}

	/* Users ------------------------------------------------------------- */

	function usageBar( used, quota ) {
		var pct = quota > 0 ? Math.min( 100, Math.round( ( used / quota ) * 100 ) ) : 0;
		var wrap = el( 'div', 'sufm-usage' );
		var line = el( 'div', 'sufm-usage-line' );
		line.appendChild( el( 'strong', '', bytes( used ) ) );
		line.appendChild( el( 'span', 'sufm-muted', ' / ' + bytes( quota ) ) );
		wrap.appendChild( line );
		var track = el( 'div', 'sufm-usage-track' );
		var fill = el( 'span', 'sufm-usage-fill' );
		fill.style.width = pct + '%';
		if ( pct >= 90 ) {
			fill.classList.add( 'is-high' );
		}
		track.appendChild( fill );
		wrap.appendChild( track );
		return wrap;
	}

	function renderUsers( data ) {
		var body = $( 'sufm-users-body' );
		if ( ! body ) {
			return;
		}

		body.textContent = '';
		var rows = data.rows || [];

		rows.forEach( function ( row ) {
			var tr = document.createElement( 'tr' );

			var user = el( 'td' );
			user.appendChild( el( 'strong', '', row.name || row.login ) );
			user.appendChild( el( 'span', 'sufm-muted', row.login ) );
			tr.appendChild( user );

			var usage = el( 'td' );
			usage.appendChild( usageBar( row.used || 0, row.quota || 0 ) );
			tr.appendChild( usage );

			tr.appendChild( el( 'td', 'sufm-col-files', String( row.files || 0 ) ) );

			var quotaCell = el( 'td', 'sufm-quota-cell' );
			var input = document.createElement( 'input' );
			input.type = 'number';
			input.min = '0';
			input.step = '1';
			input.value = String( Math.round( ( row.quota || 0 ) / 1048576 ) );
			input.title = bytes( row.quota || 0 );
			input.className = 'sufm-quota-input';
			quotaCell.appendChild( input );
			var save = el( 'button', 'btn btn--small', '保存' );
			save.type = 'button';
			save.addEventListener( 'click', function () {
				api( 'admin/users/' + row.id + '/quota', { method: 'PATCH', body: JSON.stringify( { quota_bytes: Math.round( ( Number( input.value ) || 0 ) * 1048576 ) } ) } )
					.then( function () {
						toast( '已更新 ' + ( row.name || row.login ) );
						loadUsers( state.usersPage );
					} )
					.catch( function ( error ) {
						toast( error.message, true );
					} );
			} );
			quotaCell.appendChild( save );
			tr.appendChild( quotaCell );

			body.appendChild( tr );
		} );

		if ( ! rows.length ) {
			var empty = document.createElement( 'tr' );
			var cell = el( 'td', 'sufm-empty', '没有匹配的用户。' );
			cell.colSpan = 4;
			empty.appendChild( cell );
			body.appendChild( empty );
		}

		renderPager( $( 'sufm-users-pager' ), data.total || 0, state.usersPage, state.usersPer,
			function ( page ) { state.usersPage = page; loadUsers( page ); },
			function ( per ) { state.usersPer = per; state.usersPage = 1; loadUsers( 1 ); } );
	}

	function loadUsers( page ) {
		if ( page ) {
			state.usersPage = page;
		}
		var search = $ ( 'sufm-user-search' ).value.trim();
		api( 'admin/users?search=' + encodeURIComponent( search ) + '&page=' + state.usersPage + '&per_page=' + state.usersPer )
			.then( renderUsers )
			.catch( function ( error ) {
				toast( error.message, true );
			} );
	}

	function renderEvents( data ) {
		var list = $( 'sufm-events' );
		if ( ! list ) {
			return;
		}

		list.textContent = '';
		var rows = data.rows || [];

		if ( ! rows.length ) {
			var empty = el( 'li', 'sufm-empty', '暂无操作记录。' );
			list.appendChild( empty );
		}

		rows.forEach( function ( row ) {
			var li = document.createElement( 'li' );
			li.appendChild( el( 'span', 'sufm-event-action', row.action || '—' ) );
			li.appendChild( el( 'span', 'sufm-event-result', row.result || '—' ) );
			li.appendChild( el( 'span', 'sufm-event-detail', row.safe_detail || '' ) );
			li.appendChild( el( 'span', 'sufm-event-time', row.created_at || '' ) );
			list.appendChild( li );
		} );

		renderPager( $( 'sufm-events-pager' ), data.total || 0, state.eventsPage, state.eventsPer,
			function ( page ) { state.eventsPage = page; loadEvents( page ); },
			function ( per ) { state.eventsPer = per; state.eventsPage = 1; loadEvents( 1 ); } );
	}

	function loadEvents( page ) {
		if ( page ) {
			state.eventsPage = page;
		}
		api( 'admin/events?page=' + state.eventsPage + '&per_page=' + state.eventsPer )
			.then( renderEvents )
			.catch( function ( error ) {
				toast( error.message, true );
			} );
	}

	/* Wiring ------------------------------------------------------------ */

	function bind( id, event, handler ) {
		var node = $( id );
		if ( node ) {
			node.addEventListener( event, handler );
		}
	}

	bind( 'sufm-refresh', 'click', function () {
		state.queuePage = 1;
		loadQueue();
	} );
	bind( 'sufm-status', 'change', function () {
		state.queuePage = 1;
		loadQueue();
	} );
	bind( 'sufm-search', 'keydown', function ( event ) {
		if ( event.key === 'Enter' ) {
			state.queuePage = 1;
			loadQueue();
		}
	} );
	bind( 'sufm-preview', 'click', preview );
	bind( 'sufm-confirm', 'click', confirm );
	bind( 'sufm-phrase', 'keydown', function ( event ) {
		if ( 'Enter' === event.key && ! $( 'sufm-confirm' ).disabled ) {
			event.preventDefault();
			confirm();
		}
	} );
	bind( 'sufm-users-refresh', 'click', function () {
		state.usersPage = 1;
		loadUsers( 1 );
	} );
	bind( 'sufm-user-search', 'keydown', function ( event ) {
		if ( event.key === 'Enter' ) {
			state.usersPage = 1;
			loadUsers( 1 );
		}
	} );
	function syncProgress( state ) {
		var out = $ ( 'sufm-reconcile-out' );
		var button = $ ( 'sufm-sync-quota' );
		var scanned = Number( state.scanned ) || 0;
		var total = Number( state.total ) || 0;
		var percent = total > 0 ? Math.min( 100, Math.round( scanned / total * 100 ) ) : 0;

		if ( 'running' === state.state ) {
			out.textContent = '同步中 ' + scanned + ' / ' + total + '（' + percent + '%），更新 ' + ( state.updated || 0 ) + ' 个文件';
			if ( button ) {
				button.disabled = true;
			}
			window.setTimeout( pollSync, 1200 );
			return;
		}

		if ( button ) {
			button.disabled = false;
		}

		if ( 'done' === state.state ) {
			out.textContent = state.last || '同步完成';
			toast( state.last || '同步完成' );
			loadUsers( state.usersPage || 1 );
		}
	}

	function pollSync() {
		api( 'admin/sync?t=' + Date.now() )
			.then( function ( state ) {
				syncProgress( state );
			} )
			.catch( function ( error ) {
				var button = $ ( 'sufm-sync-quota' );
				if ( button ) {
					button.disabled = false;
				}
				toast( error.message, true );
			} );
	}

	bind( 'sufm-sync-quota', 'click', function () {
		api( 'admin/sync', { method: 'POST', body: '{}' } )
			.then( function ( state ) {
				syncProgress( state );
			} )
			.catch( function ( error ) {
				toast( error.message, true );
			} );
	} );
	bind( 'sufm-reset-quota', 'click', function () {
		if ( ! window.confirm( '重置会清除【所有用户】单独设置的容量（例如 sakura 的 1 GB），全部回到默认容量。确定继续？' ) ) {
			return;
		}
		api( 'admin/reset-quotas', { method: 'POST', body: '{}' } )
			.then( function ( data ) {
				$ ( 'sufm-reconcile-out' ).textContent = '已重置 ' + ( data.reset || 0 ) + ' 个用户的单独容量';
				toast( '已重置 ' + ( data.reset || 0 ) + ' 个用户的单独容量' );
				loadUsers( state.usersPage );
			} )
			.catch( function ( error ) {
				toast( error.message, true );
			} );
	} );

	/* The layout engine measures each card once, before this script has filled
	 * its tables. Every list render changes a card's natural height, so ask the
	 * engine to measure again (it listens for resize) whenever a body changes.
	 * Only childList is observed: the engine's own style writes on the card are
	 * attributes, so this cannot loop. */
	var relayoutTimer = 0;
	function relayout() {
		window.clearTimeout( relayoutTimer );
		relayoutTimer = window.setTimeout( function () {
			window.dispatchEvent( new Event( 'resize' ) );
		}, 120 );
	}

	if ( window.MutationObserver ) {
		var observer = new MutationObserver( relayout );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-sakura-layout] .card-body' ), function ( body ) {
			observer.observe( body, { childList: true, subtree: true, characterData: true } );
		} );
	}

	/* Each tab only renders its own cards, so only fetch what is on screen. */
	if ( $( 'sufm-queue-body' ) ) {
		loadQueue();
		loadApproved();
	}

	if ( $( 'sufm-users-body' ) ) {
		loadUsers( 1 );
	}

	if ( $( 'sufm-events' ) ) {
		loadEvents( 1 );
	}
}() );

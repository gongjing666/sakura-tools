/**
 * File space user quota controller.
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
		var root = admin.root();
		var body = document.getElementById( 'sufm-users-body' );

		if ( ! root || ! body ) {
			return;
		}

		var config = admin.data || {};
		var strings = config.strings || {};
		var base = ( admin.page.restUrl || '' ) + ( config.restPath || 'sufm/v1/' );
		var nonce = admin.page.nonce || '';

		var searchInput = document.getElementById( 'sufm-user-search' );
		var refreshButton = document.getElementById( 'sufm-users-refresh' );
		var syncButton = document.getElementById( 'sufm-sync-quota' );
		var resetButton = document.getElementById( 'sufm-reset-quota' );
		var out = document.getElementById( 'sufm-reconcile-out' );
		var empty = root.querySelector( '[data-empty="users"]' );
		var pagerNode = document.getElementById( 'sufm-users-pager' );
		var tableScroll = body.closest( '.table-scroll' );

		var syncTimer = 0;
		var syncing = false;
		var syncDone = false;

		function fill( template, values ) {
			return String( template || '' ).replace( /\{(\w+)\}/g, function ( match, key ) {
				return Object.prototype.hasOwnProperty.call( values, key ) ? String( values[ key ] ) : match;
			} );
		}

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

		function errorText( error ) {
			if ( ! error ) {
				return strings.failed || '操作失败，请重试。';
			}
			if ( typeof error.payload === 'string' && error.payload ) {
				return error.payload;
			}
			if ( error.payload && error.payload.message ) {
				return error.payload.message;
			}
			if ( error.payload && typeof error.payload.data === 'string' && error.payload.data ) {
				return error.payload.data;
			}
			if ( error.payload && error.payload.data && error.payload.data.message ) {
				return error.payload.data.message;
			}
			return error.message || strings.failed || '操作失败，请重试。';
		}

		function get( path, params ) {
			return admin.api.get( base + path, params || {}, {
				headers: { 'X-WP-Nonce': nonce }
			} );
		}

		function request( path, options ) {
			var settings = Object.assign( {}, options || {} );
			settings.headers = Object.assign( { 'X-WP-Nonce': nonce }, settings.headers || {} );
			return admin.api.request( base + path, settings );
		}

		function say( message, tone ) {
			if ( ! out ) {
				return;
			}
			out.textContent = message || '';
			out.classList.toggle( 'is-error', tone === 'bad' );
		}

		function makePager( node, sizes, onChange ) {
			var page = 1;
			var per = sizes[0];
			var total = 0;
			var sizeButtons = admin.qsa( '[data-size]', node );
			var summary = node.querySelector( '[data-summary]' );
			var now = node.querySelector( '[data-now]' );
			var prev = node.querySelector( '[data-page-prev]' );
			var next = node.querySelector( '[data-page-next]' );

			function pages() {
				return Math.max( 1, Math.ceil( total / per ) );
			}

			function render() {
				var last = pages();
				if ( page > last ) {
					page = last;
				}
				var from = total ? ( page - 1 ) * per + 1 : 0;
				var to = Math.min( total, page * per );

				node.hidden = total <= 0;
				if ( summary ) {
					summary.textContent = total
						? fill( strings.pagerSummary || '', { from: from, to: to, total: total } )
						: ( strings.pagerEmpty || '' );
				}
				if ( now ) {
					now.textContent = fill( strings.pagerNow || '', { page: page, pages: last } );
				}
				if ( prev ) {
					prev.disabled = page <= 1;
				}
				if ( next ) {
					next.disabled = page >= last;
				}
				sizeButtons.forEach( function ( button ) {
					var active = Number( button.getAttribute( 'data-size' ) ) === per;
					button.classList.toggle( 'is-current', active );
					button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
				} );
			}

			sizeButtons.forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var size = Number( button.getAttribute( 'data-size' ) );
					if ( ! size || size === per ) {
						return;
					}
					per = size;
					page = 1;
					render();
					onChange();
				} );
			} );

			if ( prev ) {
				prev.addEventListener( 'click', function () {
					if ( page > 1 ) {
						page -= 1;
						render();
						onChange();
					}
				} );
			}

			if ( next ) {
				next.addEventListener( 'click', function () {
					if ( page < pages() ) {
						page += 1;
						render();
						onChange();
					}
				} );
			}

			return {
				set: function ( data ) {
					total = Number( data.total ) || 0;
					page = Number( data.page ) || 1;
					if ( Number( data.per_page ) > 0 ) {
						per = Number( data.per_page );
					}
					render();
				},
				page: function () {
					return page;
				},
				per: function () {
					return per;
				}
			};
		}

		var pager = makePager( pagerNode, [ 25, 50, 100 ], function () {
			loadUsers( pager.page() );
		} );

		function showEmpty( state ) {
			if ( empty ) {
				empty.hidden = ! state;
			}
			if ( tableScroll ) {
				tableScroll.hidden = state;
			}
		}

		function displayName( row ) {
			return row.name || row.login || ( '#' + row.id );
		}

		function quotaInput( row ) {
			var input = document.createElement( 'input' );
			input.type = 'number';
			input.min = '0';
			input.step = '1';
			input.value = String( Math.round( ( Number( row.quota ) || 0 ) / 1048576 ) );
			input.placeholder = strings.quotaPlaceholder || '默认';
			input.title = fill( strings.quotaTitle || '', { size: admin.format.fileSize( row.quota || 0 ) } );
			input.setAttribute( 'aria-label', fill( strings.quotaTitle || '', { size: admin.format.fileSize( row.quota || 0 ) } ) );
			return input;
		}

		function saveQuota( row, input, button ) {
			var raw = input.value.trim();
			var bytes;

			if ( raw === '' ) {
				bytes = -1;
			} else {
				if ( ! /^\d+$/.test( raw ) ) {
					admin.ui.toast( strings.quotaInvalid || '', 'warn' );
					input.focus();
					return;
				}
				bytes = Math.round( Number( raw ) * 1048576 );
				if ( ! isFinite( bytes ) ) {
					admin.ui.toast( strings.quotaInvalid || '', 'warn' );
					input.focus();
					return;
				}
			}

			admin.ui.busy( button, true );
			request( 'admin/users/' + encodeURIComponent( row.id ) + '/quota', {
				method: 'PATCH',
				body: { quota_bytes: bytes }
			} ).then( function ( data ) {
				var name = displayName( row );
				admin.ui.toast( fill( strings.saved || '', { name: name } ), 'ok' );
				if ( raw === '' ) {
					input.value = '';
					input.title = strings.quotaClear || '';
				} else if ( data && Number( data.quota ) >= 0 ) {
					input.value = String( Math.round( Number( data.quota ) / 1048576 ) );
				}
				loadUsers( pager.page() );
			} ).catch( function ( error ) {
				admin.ui.toast( errorText( error ), 'bad' );
			} ).finally( function () {
				admin.ui.busy( button, false );
			} );
		}

		function renderUsers( rows ) {
			body.textContent = '';

			if ( ! rows.length ) {
				showEmpty( true );
				return;
			}

			showEmpty( false );

			rows.forEach( function ( row ) {
				var tr = document.createElement( 'tr' );

				var userCell = document.createElement( 'td' );
				var user = el( 'div', 'user-cell' );
				user.appendChild( el( 'strong', '', displayName( row ) ) );
				user.appendChild( el( 'span', '', row.login || row.email || ( '#' + row.id ) ) );
				userCell.appendChild( user );
				if ( row.email ) {
					userCell.title = row.email;
				}
				tr.appendChild( userCell );

				var quota = Number( row.quota ) || 0;
				var used = Number( row.used ) || 0;
				var percent = quota > 0 ? Math.min( 100, Math.round( used / quota * 100 ) ) : 0;

				var usageCell = document.createElement( 'td' );
				var usage = el( 'div', 'usage' );
				var line = el( 'div', 'usage__line' );
				line.appendChild( el( 'strong', '', admin.format.fileSize( used ) ) );
				line.appendChild( document.createTextNode( ' / ' + admin.format.fileSize( quota ) ) );
				usage.appendChild( line );
				var track = el( 'div', 'usage__track' );
				var fillBar = el( 'span', 'usage__fill' + ( percent >= 90 ? ' is-high' : '' ) );
				fillBar.style.width = percent + '%';
				track.appendChild( fillBar );
				usage.appendChild( track );
				usageCell.appendChild( usage );
				tr.appendChild( usageCell );

				var filesCell = document.createElement( 'td' );
				var files = el( 'div', 'files-cell' );
				files.appendChild( el( 'strong', '', String( Number( row.files ) || 0 ) ) );
				files.appendChild( el( 'span', '', strings.filesUnit || '' ) );
				files.appendChild( el( 'span', '', ( Number( row.folders ) || 0 ) + ' ' + ( strings.foldersUnit || '' ) ) );
				filesCell.appendChild( files );
				tr.appendChild( filesCell );

				var quotaCell = document.createElement( 'td' );
				var quotaWrap = el( 'div', 'quota-cell' );
				var input = quotaInput( row );
				var save = el( 'button', 'btn btn--small btn--ghost', strings.save || '保存' );
				save.type = 'button';
				save.addEventListener( 'click', function () {
					saveQuota( row, input, save );
				} );
				quotaWrap.appendChild( input );
				quotaWrap.appendChild( save );
				quotaCell.appendChild( quotaWrap );
				tr.appendChild( quotaCell );

				body.appendChild( tr );
			} );

			admin.ui.labelTables( body.closest( 'table' ) || document );
		}

		function loadUsers( page ) {
			showEmpty( false );
			body.textContent = '';
			var loading = document.createElement( 'tr' );
			loading.className = 'is-loading';
			var cell = document.createElement( 'td' );
			cell.colSpan = 4;
			cell.setAttribute( 'data-label', '' );
			cell.textContent = strings.loading || '正在读取…';
			loading.appendChild( cell );
			body.appendChild( loading );

			return get( 'admin/users', {
				search: searchInput ? searchInput.value.trim() : '',
				page: page || pager.page(),
				per_page: pager.per()
			} ).then( function ( data ) {
				renderUsers( data.rows || [] );
				pager.set( data );
			} ).catch( function ( error ) {
				body.textContent = '';
				showEmpty( true );
				if ( empty ) {
					var message = empty.querySelector( '.empty-state__message' );
					if ( message ) {
						message.textContent = errorText( error );
					}
				}
				admin.ui.toast( errorText( error ), 'bad' );
			} );
		}

		function restoreSyncButton() {
			syncing = false;
			if ( ! syncButton ) {
				return;
			}
			syncButton.disabled = false;
			syncButton.classList.remove( 'is-busy' );
			syncButton.setAttribute( 'aria-busy', 'false' );
			syncButton.textContent = strings.sync || '同步容量';
		}

		function pollSync() {
			syncTimer = 0;
			if ( ! syncing ) {
				return;
			}
			get( 'admin/sync', { t: Date.now() } ).then( function ( state ) {
				if ( state && state.state === 'running' ) {
					var total = Number( state.total ) || 0;
					var scanned = Number( state.scanned ) || 0;
					say( fill( strings.syncProgress || '', {
						scanned: scanned,
						total: total,
						percent: total ? Math.round( scanned / total * 100 ) : 0,
						updated: Number( state.updated ) || 0
					} ), 'ok' );
					syncTimer = window.setTimeout( pollSync, 1200 );
					return;
				}
				finishSync( state || {} );
			} ).catch( function ( error ) {
				restoreSyncButton();
				say( errorText( error ), 'bad' );
				admin.ui.toast( strings.syncFailed || errorText( error ), 'bad' );
			} );
		}

		function finishSync( state ) {
			restoreSyncButton();
			say( fill( strings.syncDone || '', { message: state.last || '' } ), 'ok' );
			if ( ! syncDone ) {
				syncDone = true;
				admin.ui.toast( strings.syncDone || '', 'ok' );
			}
			loadUsers( pager.page() );
		}

		function startSync() {
			if ( syncing ) {
				return;
			}
			syncing = true;
			syncDone = false;
			if ( syncButton ) {
				syncButton.disabled = true;
				syncButton.classList.add( 'is-busy' );
				syncButton.setAttribute( 'aria-busy', 'true' );
				syncButton.textContent = strings.syncing || '同步中…';
			}
			say( strings.syncing || '', 'ok' );

			request( 'admin/sync', { method: 'POST', body: {} } ).then( function ( state ) {
				if ( state && state.state === 'running' ) {
					pollSync();
					return;
				}
				finishSync( state || {} );
			} ).catch( function ( error ) {
				restoreSyncButton();
				say( errorText( error ), 'bad' );
				admin.ui.toast( strings.syncStartFailed || errorText( error ), 'bad' );
			} );
		}

		function resetQuotas() {
			admin.ui.confirm( strings.resetMessage || '', {
				title: strings.resetTitle || '',
				confirmText: strings.resetConfirm || strings.confirm,
				cancelText: strings.cancel || '取消',
				danger: true,
				tone: 'danger'
			} ).then( function ( confirmed ) {
				if ( ! confirmed ) {
					return;
				}
				admin.ui.busy( resetButton, true );
				request( 'admin/reset-quotas', { method: 'POST', body: {} } ).then( function ( data ) {
					var count = Number( data && data.reset ) || 0;
					say( fill( strings.resetDone || '', { count: count } ), 'ok' );
					admin.ui.toast( fill( strings.resetDone || '', { count: count } ), 'ok' );
					loadUsers( 1 );
				} ).catch( function ( error ) {
					say( errorText( error ), 'bad' );
					admin.ui.toast( strings.resetFailed || errorText( error ), 'bad' );
				} ).finally( function () {
					admin.ui.busy( resetButton, false );
				} );
			} );
		}

		if ( refreshButton ) {
			refreshButton.addEventListener( 'click', function () {
				loadUsers( 1 );
			} );
		}

		if ( searchInput ) {
			searchInput.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					loadUsers( 1 );
				}
			} );
		}

		if ( syncButton ) {
			syncButton.addEventListener( 'click', startSync );
		}

		if ( resetButton ) {
			resetButton.addEventListener( 'click', resetQuotas );
		}

		window.addEventListener( 'beforeunload', function () {
			if ( syncTimer ) {
				window.clearTimeout( syncTimer );
			}
		} );

		loadUsers( 1 );
	} );
}( window, document ) );

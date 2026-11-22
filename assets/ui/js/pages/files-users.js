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
				var filesLine = el( 'div', 'files-cell__line' );
				filesLine.appendChild( el( 'strong', '', String( Number( row.files ) || 0 ) ) );
				filesLine.appendChild( el( 'span', '', strings.filesUnit || '' ) );
				files.appendChild( filesLine );
				var foldersLine = el( 'div', 'files-cell__line' );
				foldersLine.appendChild( el( 'span', '', ( Number( row.folders ) || 0 ) + ' ' + ( strings.foldersUnit || '' ) ) );
				files.appendChild( foldersLine );
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

				tr.appendChild( sanctionCell( row ) );

				body.appendChild( tr );
			} );

			admin.ui.labelTables( body.closest( 'table' ) || document );
		}

		/*
		 * The sanction column. A badge when something is in force, a button
		 * either way — the button opens the same dialog in both cases and the
		 * dialog itself decides whether to offer "apply" or "revoke".
		 *
		 * `can_sanction` is decided by the server (self, administrators and the
		 * capability are all checked there), so a disabled button here is only
		 * the visible half of a refusal that would happen anyway.
		 */
		function sanctionCell( row ) {
			var cell = document.createElement( 'td' );
			var wrap = el( 'div', 'sanction-cell' );

			if ( row.sanction && row.sanction.badge ) {
				var badge = el( 'span', 'badge badge--warn', row.sanction.badge );
				badge.title = row.sanction.ends ? row.sanction.ends : ( strings.sanctionForever || '' );
				wrap.appendChild( badge );
			}

			var open = el( 'button', 'btn btn--small btn--ghost', strings.sanction || '处罚' );
			open.type = 'button';

			if ( false === row.can_sanction ) {
				open.disabled = true;
				open.title = Number( row.id ) === 0 ? ( strings.sanctionSelf || '' ) : ( strings.sanctionAdmin || '' );
			} else {
				open.addEventListener( 'click', function () {
					openSanction( row, open );
				} );
			}

			wrap.appendChild( open );
			cell.appendChild( wrap );
			return cell;
		}

		/*
		 * The closed reason list is the same one the takedown dialog uses. It is
		 * sent by the server so the two paths can never drift apart.
		 */
		function reasonOptions( data ) {
			if ( data && Array.isArray( data.reasons ) && data.reasons.length ) {
				return data.reasons.map( function ( entry ) {
					return [ String( entry.value ), String( entry.label ) ];
				} );
			}
			return [ [ 'other', strings.sanctionReason || '' ] ];
		}

		function field( labelText, control, wide ) {
			var wrap = el( 'label', 'field' + ( wide ? ' field--full' : '' ) );
			var main = el( 'span', 'field__main' );
			main.appendChild( el( 'span', 'field__label', labelText ) );
			wrap.appendChild( main );
			var box = el( 'span', 'field__control' );
			box.appendChild( control );
			wrap.appendChild( box );
			return wrap;
		}

		/**
		 * Sanction dialog: current state, ladder suggestion, apply or revoke.
		 *
		 * Everything factual (the ladder, whether the target may be sanctioned)
		 * comes from GET /admin/users/{id}/sanction so this file never encodes
		 * policy. The one decision that needs the login name — a permanent ban —
		 * is gated here and re-checked by the server.
		 */
		function openSanction( row, trigger ) {
			get( 'admin/users/' + encodeURIComponent( row.id ) + '/sanction' ).then( function ( data ) {
				buildSanctionDialog( row, data || {}, trigger );
			} ).catch( function ( error ) {
				admin.ui.toast( strings.sanctionLoadFailed || errorText( error ), 'bad' );
			} );
		}

		function buildSanctionDialog( row, data, trigger ) {
			if ( ! data.can_sanction ) {
				admin.ui.toast( strings.sanctionAdmin || '', 'bad' );
				return;
			}

			var dialog = document.createElement( 'dialog' );
			dialog.className = 'sakura-dialog';

			var form = document.createElement( 'form' );
			form.className = 'sakura-dialog__form';
			form.method = 'dialog';

			/* 头部与插件里其它确认框同一套：左上角小鲸鱼 + 标题 + 说明。
			 * data-mascot 把 .dialog__body 变成两列；鲸鱼由 ui.js 统一画，
			 * 这里只取节点，插件里因此只有一种弹窗长相。取不到就只渲染文字，
			 * 不让旧缓存里的 ui.js 把整个弹窗带崩。 */
			var head = el( 'div', 'dialog__body' );
			dialog.setAttribute( 'data-mascot', '1' );
			if ( admin.ui.mascot ) {
				head.appendChild( admin.ui.mascot() );
			}

			var headText = el( 'div', 'dialog__text' );
			headText.appendChild( el( 'h2', 'dialog__title', fill( strings.sanctionTitle || '', { name: displayName( row ) } ) ) );

			if ( data.active ) {
				headText.appendChild( el( 'p', 'dialog__message', fill( strings.sanctionActive || '', { label: data.active.label || '' } ) ) );
			} else {
				headText.appendChild( el( 'p', 'dialog__message', strings.sanctionNone || '' ) );
			}

			head.appendChild( headText );
			form.appendChild( head );

			var grid = el( 'div', 'field-list sanction-form' );
			var types = data.types || [];
			var durations = data.durations || [];

			var typeSelect = document.createElement( 'select' );
			types.forEach( function ( option ) {
				var node = document.createElement( 'option' );
				node.value = String( option.value );
				node.textContent = String( option.label );
				typeSelect.appendChild( node );
			} );

			var daysSelect = document.createElement( 'select' );
			durations.forEach( function ( option ) {
				var node = document.createElement( 'option' );
				node.value = String( option.value );
				node.textContent = String( option.label );
				daysSelect.appendChild( node );
			} );
			/* Default to the shortest finite window rather than to whatever the
			 * server lists first: a decision made by reflex should expire. */
			var firstFinite = durations.filter( function ( option ) {
				return Number( option.value ) > 0;
			} )[ 0 ];
			if ( firstFinite ) {
				daysSelect.value = String( firstFinite.value );
			}

			var reasonSelect = document.createElement( 'select' );
			reasonOptions( data ).forEach( function ( pair ) {
				var node = document.createElement( 'option' );
				node.value = pair[ 0 ];
				node.textContent = pair[ 1 ];
				reasonSelect.appendChild( node );
			} );

			var noteInput = document.createElement( 'input' );
			noteInput.type = 'text';
			noteInput.maxLength = 120;
			noteInput.placeholder = strings.sanctionNote || '';

			/* The ladder suggestion only ever pre-fills; the operator can override
			 * every field. Nothing here decides anything on its own. */
			var suggestion = data.suggestion || null;
			if ( suggestion && suggestion.type ) {
				typeSelect.value = String( suggestion.type );
			}
			/* A warning has no duration by definition, so its suggested 0 must
			 * never reach the select — 0 reads as "permanent" there. */
			if ( suggestion && suggestion.days !== undefined && suggestion.days !== null && 'warn' !== String( typeSelect.value ) ) {
				daysSelect.value = String( suggestion.days );
			}

			var hint = el( 'p', 'field__hint', suggestion && suggestion.reason ? fill( strings.sanctionSuggest || '', { reason: suggestion.reason } ) : '' );

			grid.appendChild( field( strings.sanctionType || '', typeSelect ) );
			grid.appendChild( field( strings.sanctionDuration || '', daysSelect ) );
			grid.appendChild( field( strings.sanctionReason || '', reasonSelect ) );
			grid.appendChild( field( strings.sanctionNote || '', noteInput ) );
			grid.appendChild( hint );
			form.appendChild( grid );

			/* A zero-day upload ban means "permanent", so it needs the login name
			 * typed back. Warn has no end date by definition and skips the gate. */
			var gateWrap = el( 'div', 'dialog__gate' );
			var gateLabel = el( 'label', 'dialog__gate-label', fill( strings.sanctionForeverLabel || '', { login: data.user ? data.user.login : '' } ) );
			var gateInput = document.createElement( 'input' );
			gateInput.type = 'text';
			gateInput.className = 'dialog__gate-input';
			gateInput.autocomplete = 'off';
			gateInput.spellcheck = false;
			gateLabel.appendChild( gateInput );
			gateWrap.appendChild( gateLabel );
			gateWrap.hidden = true;
			form.appendChild( gateWrap );

			var actions = el( 'div', 'dialog__actions' );

			var cancel = el( 'button', 'btn btn--ghost', strings.cancel || '取消' );
			cancel.type = 'button';
			/* settle() 而不是点 <form method="dialog"> 的默认提交：它会关窗、摘节点、
			 * 还原焦点，并且幂等（close 事件里还会再调一次）。1.28.7-beta-25 这里
			 * 调的是不存在的 closeSanctionDialog，点取消只会在控制台抛
			 * ReferenceError、窗口留在原地，只有 ESC 能关。 */
			cancel.addEventListener( 'click', function () {
				settle( null );
			} );
			actions.appendChild( cancel );

			var apply = el( 'button', 'btn btn--primary', strings.sanctionApply || '确认处罚' );
			apply.type = 'button';
			actions.appendChild( apply );

			var revoke = null;
			if ( data.active ) {
				revoke = el( 'button', 'btn btn--danger', strings.sanctionRevoke || '撤销处罚' );
				revoke.type = 'button';
				actions.appendChild( revoke );
			}

			form.appendChild( actions );
			dialog.appendChild( form );

			function syncGate() {
				var warn = 'warn' === String( typeSelect.value );
				/* A warning carries no window, so its duration control is noise —
				 * leaving it live invites "7 天警告" which the row never stores. */
				daysSelect.disabled = warn;
				var permanent = '0' === String( daysSelect.value ) && ! warn;
				gateWrap.hidden = ! permanent;
				if ( ! permanent ) {
					apply.disabled = false;
					return;
				}
				var typed = gateInput.value.trim();
				apply.disabled = typed !== String( data.user ? data.user.login : '' );
			}

			typeSelect.addEventListener( 'change', syncGate );
			daysSelect.addEventListener( 'change', syncGate );
			gateInput.addEventListener( 'input', syncGate );
			syncGate();

			dialog.addEventListener( 'cancel', function ( event ) {
				event.preventDefault();
				settle( null );
			} );
			/* Escape inside a method="dialog" form, or a stray native close, must
			 * not leave the node behind — settle() is idempotent. */
			dialog.addEventListener( 'close', function () {
				settle( null );
			} );

			var settled = false;

			function settle( outcome ) {
				if ( settled ) {
					return;
				}
				settled = true;

				if ( typeof dialog.close === 'function' ) {
					dialog.close();
				}
				if ( dialog.parentNode ) {
					dialog.parentNode.removeChild( dialog );
				}
				if ( trigger && trigger.isConnected ) {
					trigger.focus();
				}
				if ( ! outcome ) {
					return;
				}
				var name = displayName( row );
				if ( 'applied' === outcome ) {
					admin.ui.toast( fill( strings.sanctionApplied || '', { name: name } ), 'ok' );
				} else {
					admin.ui.toast( fill( strings.sanctionRevoked || '', { name: name } ), 'ok' );
				}
				loadUsers( pager.page() );
			}

			apply.addEventListener( 'click', function () {
				var body = {
					type: typeSelect.value,
					/* warn stores no window at all; sending its stale 7 would be a
					 * lie the row cannot express anyway. */
					days: 'warn' === typeSelect.value ? 0 : Number( daysSelect.value ),
					reason_code: reasonSelect.value,
					note: noteInput.value.trim()
				};

				if ( body.days === 0 && body.type !== 'warn' ) {
					body.confirm = gateInput.value.trim();
				}

				admin.ui.busy( apply, true );
				request( 'admin/users/' + encodeURIComponent( row.id ) + '/sanction', {
					method: 'POST',
					body: body
				} ).then( function () {
					settle( 'applied' );
				} ).catch( function ( error ) {
					admin.ui.toast( errorText( error ), 'bad' );
				} ).finally( function () {
					admin.ui.busy( apply, false );
				} );
			} );

			if ( revoke ) {
				revoke.addEventListener( 'click', function () {
					admin.ui.confirm( strings.sanctionRevokeMessage || '', {
						title: fill( strings.sanctionRevokeTitle || '', { name: displayName( row ) } ),
						note: strings.sanctionRevokeNote || '',
						tone: 'danger',
						danger: true,
						confirmText: strings.sanctionRevoke || '撤销处罚',
						cancelText: strings.cancel || '取消'
					} ).then( function ( confirmed ) {
						if ( ! confirmed ) {
							return;
						}
						request( 'admin/sanctions/' + encodeURIComponent( data.active.id ) + '/revoke', {
							method: 'POST',
							body: {}
						} ).then( function () {
							settle( 'revoked' );
						} ).catch( function ( error ) {
							admin.ui.toast( strings.sanctionRevokeFailed || errorText( error ), 'bad' );
						} );
					} );
				} );
			}

			( admin.root() || document.body ).appendChild( dialog );
			if ( typeof dialog.showModal === 'function' ) {
				dialog.showModal();
			} else {
				dialog.setAttribute( 'open', '' );
			}
			typeSelect.focus();
		}

		function loadUsers( page ) {
			showEmpty( false );
			body.textContent = '';
			var loading = document.createElement( 'tr' );
			loading.className = 'is-loading';
			var cell = document.createElement( 'td' );
			cell.colSpan = 5;
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
				if ( admin.events ) {
					admin.events.emit( 'layout-refresh' );
				}
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
				if ( admin.events ) {
					admin.events.emit( 'layout-refresh' );
				}
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

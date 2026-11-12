/**
 * File space review queue and delete execution controller.
 *
 * Talks to sufm/v1 through SakuraAdmin.api, never through fetch or jQuery.
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
		var body = document.getElementById( 'sufm-queue-body' );

		if ( ! root || ! body ) {
			return;
		}

		var config = admin.data || {};
		var strings = config.strings || {};
		var base = ( admin.page.restUrl || '' ) + ( config.restPath || 'sufm/v1/' );
		var nonce = admin.page.nonce || '';

		var statusSelect = document.getElementById( 'sufm-status' );
		var searchInput = document.getElementById( 'sufm-search' );
		var refreshButton = document.getElementById( 'sufm-refresh' );
		var emptyQueue = root.querySelector( '[data-empty="queue"]' );
		var queueScroll = body.closest( '.table-scroll' );
		var approvedList = document.getElementById( 'sufm-approved-list' );
		var pickCount = document.getElementById( 'sufm-pick-count' );
		var previewButton = document.getElementById( 'sufm-preview' );
		var previewHint = document.getElementById( 'sufm-preview-hint' );
		var previewBox = document.getElementById( 'sufm-preview-box' );
		var previewCount = document.getElementById( 'sufm-preview-count' );
		var previewTotal = document.getElementById( 'sufm-preview-total' );
		var previewRequests = document.getElementById( 'sufm-preview-requests' );
		var previewHash = document.getElementById( 'sufm-preview-hash' );
		var stepPreview = document.getElementById( 'sufm-step-preview' );
		var stepConfirm = document.getElementById( 'sufm-step-confirm' );
		var phraseInput = document.getElementById( 'sufm-phrase' );
		var confirmButton = document.getElementById( 'sufm-confirm' );
		var batchState = document.getElementById( 'sufm-batch-state' );
		var heroPending = document.getElementById( 'sufm-hero-pending' );
		var heroSub = document.getElementById( 'sufm-hero-sub' );
		var pagerNode = document.getElementById( 'sufm-queue-pager' );

		var state = {
			hash: '',
			previewItems: 0,
			batchId: 0,
			batchTimer: 0,
			busy: false
		};

		var layoutTimer = 0;

		/* Desktop cards are absolutely placed. Anything that changes this card's
		 * height after first paint has to ask the layout engine to repack. */
		function notifyLayout() {
			window.clearTimeout( layoutTimer );
			layoutTimer = window.setTimeout( function () {
				window.dispatchEvent( new Event( 'resize' ) );
			}, 80 );
		}

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

		function cell( label, className ) {
			var node = el( 'td', className );
			node.setAttribute( 'data-label', label );
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

		function request( path, options ) {
			var settings = Object.assign( {}, options || {} );
			settings.headers = Object.assign( { 'X-WP-Nonce': nonce }, settings.headers || {} );
			return admin.api.request( base + path, settings );
		}

		function get( path, params ) {
			return admin.api.get( base + path, params || {}, {
				headers: { 'X-WP-Nonce': nonce }
			} );
		}

		function statusTone( status ) {
			if ( status === 'pending_review' || status === 'needs_review' ) {
				return 'warn';
			}
			if ( status === 'approved' || status === 'queued' || status === 'executing' ) {
				return 'info';
			}
			if ( status === 'completed' ) {
				return 'ok';
			}
			if ( status === 'rejected' || status === 'failed' || status === 'partial_failed' ) {
				return 'bad';
			}
			return 'neutral';
		}

		function statusLabel( status ) {
			return ( strings.statusLabels && strings.statusLabels[ status ] ) || status || '—';
		}

		function ownerName( row ) {
			return row.owner_name || fill( strings.ownerId || 'ID {id}', { id: row.owner_id } );
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
				goto: function ( next ) {
					page = Math.max( 1, Number( next ) || 1 );
				},
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

		var pager = makePager( pagerNode, [ 20, 30, 50 ], function () {
			loadQueue( pager.page() );
		} );

		function showQueueEmpty( empty ) {
			if ( emptyQueue ) {
				emptyQueue.hidden = ! empty;
			}
			if ( queueScroll ) {
				queueScroll.hidden = empty;
			}
		}

		function updateCounts( counts ) {
			var data = counts || {};
			if ( heroPending ) {
				heroPending.textContent = String( data.pending_review || 0 );
			}
			if ( heroSub ) {
				heroSub.textContent = fill( strings.countSub || '', {
					approved: data.approved || 0,
					queued: data.queued || 0
				} );
			}
		}

		function actionButton( label, variant, handler ) {
			var button = el( 'button', 'btn btn--small btn--' + variant, label );
			button.type = 'button';
			button.addEventListener( 'click', function () {
				handler( button );
			} );
			return button;
		}

		function review( row, decision, button ) {
			var titles = {
				approve: strings.approveTitle,
				reject: strings.rejectTitle
			};
			var messages = {
				approve: strings.approveMessage,
				reject: strings.rejectMessage
			};
			var values = { id: row.id };

			admin.ui.confirm( fill( messages[ decision ] || '', values ), {
				title: fill( titles[ decision ] || '', values ),
				confirmText: decision === 'approve' ? ( strings.approve || strings.confirm ) : ( strings.confirm || '确认' ),
				cancelText: strings.cancel || '取消',
				danger: decision === 'reject',
				tone: decision === 'reject' ? 'danger' : 'neutral'
			} ).then( function ( confirmed ) {
				if ( ! confirmed ) {
					return;
				}
				admin.ui.busy( button, true );
				return request( 'admin/delete-requests/' + encodeURIComponent( row.id ) + '/review', {
					method: 'POST',
					body: { decision: decision }
				} ).then( function () {
					admin.ui.toast( fill( strings.reviewDone || '', values ), 'ok' );
					loadQueue( pager.page() );
					loadApproved();
				} ).catch( function ( error ) {
					admin.ui.toast( errorText( error ), 'bad' );
				} ).finally( function () {
					admin.ui.busy( button, false );
				} );
			} );
		}

		function revoke( row, button ) {
			var values = { id: row.id };
			admin.ui.confirm( fill( strings.revokeMessage || '', values ), {
				title: fill( strings.revokeTitle || '', values ),
				confirmText: strings.revoke || strings.confirm,
				cancelText: strings.cancel || '取消',
				danger: true,
				tone: 'danger'
			} ).then( function ( confirmed ) {
				if ( ! confirmed ) {
					return;
				}
				admin.ui.busy( button, true );
				return request( 'admin/delete-requests/' + encodeURIComponent( row.id ) + '/revoke', {
					method: 'POST',
					body: { reason: '' }
				} ).then( function () {
					admin.ui.toast( fill( strings.revokeDone || '', values ), 'ok' );
					loadQueue( pager.page() );
					loadApproved();
				} ).catch( function ( error ) {
					admin.ui.toast( errorText( error ), 'bad' );
				} ).finally( function () {
					admin.ui.busy( button, false );
				} );
			} );
		}

		function stopBatch( batchId, button ) {
			var values = { id: batchId };
			admin.ui.confirm( fill( strings.stopMessage || '', values ), {
				title: fill( strings.stopTitle || '', values ),
				confirmText: strings.stopBatch || strings.confirm,
				cancelText: strings.cancel || '取消',
				danger: true,
				tone: 'danger'
			} ).then( function ( confirmed ) {
				if ( ! confirmed ) {
					return;
				}
				admin.ui.busy( button, true );
				return request( 'admin/delete-batches/' + encodeURIComponent( batchId ) + '/stop', {
					method: 'POST',
					body: {}
				} ).then( function () {
					admin.ui.toast( fill( strings.stopDone || '', values ), 'warn' );
					pollBatch( batchId );
				} ).catch( function ( error ) {
					admin.ui.toast( errorText( error ), 'bad' );
				} ).finally( function () {
					admin.ui.busy( button, false );
				} );
			} );
		}

		function actionCell( row ) {
			var node = cell( strings.actionsLabel || '操作', 'queue-cell' );
			var wrap = el( 'div', 'queue-actions' );
			var status = row.status;
			var batchId = Number( row.batch_id ) || 0;

			if ( status === 'pending_review' || status === 'needs_review' ) {
				wrap.appendChild( actionButton( strings.approve || '批准', 'primary', function ( button ) {
					review( row, 'approve', button );
				} ) );
				wrap.appendChild( actionButton( strings.reject || '驳回', 'ghost', function ( button ) {
					review( row, 'reject', button );
				} ) );

				if ( Number( row.item_count ) > 0 ) {
					var viewFiles = actionButton( strings.viewFiles || '查看文件', 'ghost', function ( button ) {
						openFiles( row, button );
					} );
					viewFiles.setAttribute( 'aria-label', fill( strings.viewFilesLabel || '查看申请 #{id} 的 {count} 个文件', {
						id: row.id,
						count: row.item_count
					} ) );
					wrap.appendChild( viewFiles );
				}

				node.appendChild( wrap );
				return node;
			}

			if ( status === 'approved' ) {
				wrap.appendChild( actionButton( strings.revoke || '撤销批准', 'danger', function ( button ) {
					revoke( row, button );
				} ) );
				node.appendChild( wrap );
				return node;
			}

			if ( ( status === 'queued' || status === 'executing' ) && batchId ) {
				wrap.appendChild( actionButton( strings.stopBatch || '停止批次', 'danger', function ( button ) {
					stopBatch( batchId, button );
				} ) );
				node.appendChild( wrap );
				return node;
			}

			node.classList.add( 'is-empty' );
			node.appendChild( el( 'span', 'text-muted', strings.noActions || '—' ) );
			return node;
		}

		function openFiles( row, button ) {
			if ( ! admin.ui || typeof admin.ui.mediaPreview !== 'function' ) {
				if ( admin.ui && typeof admin.ui.toast === 'function' ) {
					admin.ui.toast( strings.failed || '操作失败，请重试。', 'bad' );
				}
				return;
			}

			admin.ui.busy( button, true );
			get( 'admin/delete-requests/' + encodeURIComponent( row.id ) + '/items', {} ).then( function ( data ) {
				admin.ui.busy( button, false );
				var items = data && Array.isArray( data.items ) ? data.items : [];

				if ( ! items.length ) {
					admin.ui.toast( strings.filesEmpty || '这条申请没有可查看的文件。', 'warn' );
					return;
				}

				admin.ui.mediaPreview( items[0], {
					items: items,
					trigger: button,
					labels: {
						prev: strings.galleryPrev || '上一个文件',
						next: strings.galleryNext || '下一个文件',
						counter: strings.galleryCounter || '{current} / {total}',
						gallery: strings.galleryLabel || '第 {current} 个，共 {total} 个文件'
					}
				} );
			} ).catch( function ( error ) {
				admin.ui.busy( button, false );
				var message = errorText( error );
				if ( ! message || message === strings.failed ) {
					message = strings.filesLoadFailed || message || '读取申请文件失败，请重试。';
				}
				admin.ui.toast( message, 'bad' );
			} );
		}

		function renderQueue( rows ) {
			body.textContent = '';

			if ( ! rows.length ) {
				showQueueEmpty( true );
				notifyLayout();
				return;
			}

			showQueueEmpty( false );

			rows.forEach( function ( row ) {
				var tr = document.createElement( 'tr' );

				var idCell = cell( strings.idLabel || '编号', 'queue-id' );
				idCell.textContent = '#' + row.id;
				tr.appendChild( idCell );

				var userCell = cell( strings.userLabel || '用户' );
				var user = el( 'div', 'queue-user' );
				user.appendChild( el( 'strong', '', ownerName( row ) ) );
				user.appendChild( el( 'span', 'queue-user__id', fill( strings.ownerId || 'ID {id}', { id: row.owner_id } ) ) );
				userCell.appendChild( user );
				tr.appendChild( userCell );

				var objectCell = cell( strings.objectsLabel || '对象' );
				var object = el( 'div', 'queue-object' );
				object.appendChild( el( 'span', '', fill( strings.items || '{count} 项', { count: row.item_count || 0 } ) ) );
				object.appendChild( el( 'span', '', admin.format.fileSize( row.bytes || 0 ) ) );
				objectCell.appendChild( object );

				tr.appendChild( objectCell );

				var statusCell = cell( strings.statusLabel || '状态' );
				var badge = el( 'span', 'badge badge--' + statusTone( row.status ), statusLabel( row.status ) );
				statusCell.appendChild( badge );
				tr.appendChild( statusCell );

				var timeCell = cell( strings.timeLabel || '提交时间', 'text-muted' );
				timeCell.textContent = row.created_at || '';
				tr.appendChild( timeCell );

				tr.appendChild( actionCell( row ) );
				body.appendChild( tr );
			} );

			admin.ui.labelTables( body.closest( 'table' ) || document );
			notifyLayout();
		}

		function loadQueue( page ) {
			if ( page ) {
				pager.goto( page );
			}
			showQueueEmpty( false );
			body.textContent = '';
			var loading = document.createElement( 'tr' );
			loading.className = 'is-loading';
			var loadingCell = document.createElement( 'td' );
			loadingCell.colSpan = 6;
			loadingCell.setAttribute( 'data-label', '' );
			loadingCell.textContent = strings.loading || '正在读取…';
			loading.appendChild( loadingCell );
			body.appendChild( loading );

			return get( 'admin/delete-requests', {
				page: pager.page(),
				per_page: pager.per(),
				status: statusSelect ? statusSelect.value : '',
				search: searchInput ? searchInput.value.trim() : ''
			} ).then( function ( data ) {
				renderQueue( data.rows || [] );
				updateCounts( data.counts || {} );
				pager.set( data );
			} ).catch( function ( error ) {
				body.textContent = '';
				showQueueEmpty( true );
				if ( emptyQueue ) {
					var message = emptyQueue.querySelector( '.empty-state__message' );
					if ( message ) {
						message.textContent = errorText( error );
					}
				}
				admin.ui.toast( errorText( error ), 'bad' );
			} );
		}

		function selectedIds() {
			if ( ! approvedList ) {
				return [];
			}
			return admin.qsa( 'input[type="checkbox"]', approvedList ).filter( function ( input ) {
				return input.checked;
			} ).map( function ( input ) {
				return Number( input.value );
			} ).filter( function ( id ) {
				return id > 0;
			} );
		}

		function updateConfirmState() {
			var ids = selectedIds();
			var hasSelection = ids.length > 0;
			var ready = hasSelection && !! state.hash;

			if ( pickCount ) {
				pickCount.textContent = hasSelection
					? fill( strings.pickCount || '', { count: ids.length } )
					: ( strings.pickNone || '未选择' );
			}
			if ( previewButton ) {
				previewButton.disabled = ! hasSelection;
				previewButton.title = hasSelection ? '' : ( strings.previewHint || '' );
			}
			if ( previewHint ) {
				previewHint.hidden = hasSelection;
				if ( ! hasSelection ) {
					previewHint.textContent = strings.previewHint || '';
				}
			}
			if ( stepPreview ) {
				stepPreview.classList.toggle( 'is-locked', ! hasSelection );
			}
			if ( confirmButton ) {
				confirmButton.disabled = ! ready;
			}
			if ( stepConfirm ) {
				stepConfirm.classList.toggle( 'is-locked', ! ready );
			}
			notifyLayout();
		}

		function renderApproved( rows ) {
			if ( ! approvedList ) {
				return;
			}
			approvedList.textContent = '';

			if ( ! rows.length ) {
				approvedList.appendChild( el( 'p', 'text-muted', strings.approvedEmpty || '暂无已批准、待执行的申请。' ) );
				updateConfirmState();
				return;
			}

			rows.forEach( function ( row ) {
				var label = el( 'label', 'approved-item' );
				var input = document.createElement( 'input' );
				input.type = 'checkbox';
				input.value = String( row.id );
				input.addEventListener( 'change', function () {
					state.hash = '';
					if ( previewBox ) {
						previewBox.hidden = true;
					}
					updateConfirmState();
				} );
				label.appendChild( input );
				label.appendChild( el( 'span', 'approved-item__label', '#' + row.id + ' · ' + ownerName( row ) ) );
				label.appendChild( el( 'span', 'approved-item__meta',
					fill( strings.items || '{count} 项', { count: row.item_count || 0 } ) + ' · ' + admin.format.fileSize( row.bytes || 0 )
				) );
				approvedList.appendChild( label );
			} );

			updateConfirmState();
		}

		function loadApproved() {
			if ( ! approvedList ) {
				return Promise.resolve();
			}
			return get( 'admin/delete-requests', {
				status: 'approved',
				page: 1,
				per_page: 50
			} ).then( function ( data ) {
				var rows = ( data.rows || [] ).filter( function ( row ) {
					return ! row.batch_id;
				} );
				renderApproved( rows );
			} ).catch( function ( error ) {
				approvedList.textContent = '';
				approvedList.appendChild( el( 'p', 'text-muted', errorText( error ) ) );
				admin.ui.toast( errorText( error ), 'bad' );
			} );
		}

		function preview() {
			var ids = selectedIds();
			if ( ! ids.length ) {
				admin.ui.toast( strings.previewNeed || '', 'warn' );
				return;
			}

			admin.ui.busy( previewButton, true );
			request( 'admin/delete-batches/preview', {
				method: 'POST',
				body: { requests: ids }
			} ).then( function ( data ) {
				state.hash = data.hash || '';
				state.previewItems = Number( data.items ) || 0;

				if ( previewCount ) {
					previewCount.textContent = fill( strings.previewTitle || '', { count: data.items || 0 } );
				}
				if ( previewTotal ) {
					previewTotal.textContent = fill( strings.previewTotal || '', { size: admin.format.fileSize( data.bytes || 0 ) } );
				}
				if ( previewRequests ) {
					previewRequests.textContent = '';
					( data.requests || [] ).forEach( function ( item ) {
						previewRequests.appendChild( el( 'p', 'text-muted', fill( strings.previewRequest || '', {
							id: item.id,
							count: item.count || 0,
							size: admin.format.fileSize( item.bytes || 0 )
						} ) ) );
					} );
				}
				if ( previewHash ) {
					previewHash.textContent = fill( strings.previewHash || '', {
						hash: String( data.hash || '' ).slice( 0, 12 )
					} );
				}
				if ( previewBox ) {
					previewBox.hidden = false;
				}
				updateConfirmState();
			} ).catch( function ( error ) {
				admin.ui.toast( errorText( error ), 'bad' );
			} ).finally( function () {
				admin.ui.busy( previewButton, false );
			} );
		}

		function execute() {
			var ids = selectedIds();
			var phrase = phraseInput ? phraseInput.value.trim() : '';
			var expected = strings.phraseValue || '删除';

			if ( phrase !== expected ) {
				admin.ui.toast( strings.phraseWrong || '', 'warn' );
				if ( phraseInput ) {
					phraseInput.focus();
				}
				return;
			}

			if ( ! ids.length || ! state.hash ) {
				admin.ui.toast( strings.previewNeed || '', 'warn' );
				return;
			}

			admin.ui.confirm( fill( strings.executeMessage || '', { count: state.previewItems } ), {
				title: strings.executeTitle || '',
				confirmText: strings.executeConfirm || strings.confirm,
				cancelText: strings.cancel || '取消',
				danger: true,
				tone: 'danger'
			} ).then( function ( confirmed ) {
				if ( ! confirmed ) {
					return;
				}
				admin.ui.busy( confirmButton, true );
				return request( 'admin/delete-batches', {
					method: 'POST',
					body: { requests: ids, phrase: phrase, hash: state.hash }
				} ).then( function ( data ) {
					var batchId = Number( data.batch_id ) || 0;
					if ( phraseInput ) {
						phraseInput.value = '';
					}
					state.hash = '';
					state.previewItems = 0;
					if ( previewBox ) {
						previewBox.hidden = true;
					}
					updateConfirmState();
					admin.ui.toast( fill( strings.batchCreated || '', { id: batchId } ), 'ok' );
					loadApproved();
					loadQueue( pager.page() );
					if ( batchId ) {
						pollBatch( batchId );
					}
				} ).catch( function ( error ) {
					admin.ui.toast( errorText( error ), 'bad' );
				} ).finally( function () {
					admin.ui.busy( confirmButton, false );
				} );
			} );
		}

		function stopTimer() {
			if ( state.batchTimer ) {
				window.clearTimeout( state.batchTimer );
				state.batchTimer = 0;
			}
		}

		function pollBatch( batchId ) {
			stopTimer();
			state.batchId = batchId;

			get( 'admin/delete-batches/' + encodeURIComponent( batchId ) ).then( function ( data ) {
				var batch = data.batch || {};
				var running = batch.state === 'queued' || batch.state === 'executing';

				if ( batchState ) {
					batchState.hidden = false;
					batchState.textContent = '';
					batchState.appendChild( el( 'p', '', fill( strings.batchState || '', {
						id: batch.id || batchId,
						status: statusLabel( batch.state )
					} ) ) );
					batchState.appendChild( el( 'p', 'text-muted', fill( strings.batchProgress || '', {
						done: batch.done_items || 0,
						total: batch.total_items || 0,
						failed: batch.failed_items || 0
					} ) ) );

					if ( running ) {
						var stop = el( 'button', 'btn btn--small btn--danger', strings.stopBatch || '停止批次' );
						stop.type = 'button';
						stop.addEventListener( 'click', function () {
							stopBatch( batchId, stop );
						} );
						batchState.appendChild( stop );
					}
				}

				notifyLayout();

				if ( running ) {
					state.batchTimer = window.setTimeout( function () {
						pollBatch( batchId );
					}, 4000 );
					return;
				}

				state.batchId = 0;
				admin.ui.toast( fill( strings.batchFinished || '', { id: batch.id || batchId } ), 'ok' );
				loadQueue( pager.page() );
			} ).catch( function ( error ) {
				admin.ui.toast( errorText( error ), 'bad' );
				state.batchTimer = window.setTimeout( function () {
					pollBatch( batchId );
				}, 6000 );
			} );
		}

		if ( refreshButton ) {
			refreshButton.addEventListener( 'click', function () {
				loadQueue( 1 );
			} );
		}

		if ( statusSelect ) {
			statusSelect.addEventListener( 'change', function () {
				loadQueue( 1 );
			} );
		}

		if ( searchInput ) {
			searchInput.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					loadQueue( 1 );
				}
			} );
		}

		if ( previewButton ) {
			previewButton.addEventListener( 'click', preview );
		}

		if ( confirmButton ) {
			confirmButton.addEventListener( 'click', execute );
		}

		if ( phraseInput ) {
			phraseInput.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' && confirmButton && ! confirmButton.disabled ) {
					event.preventDefault();
					execute();
				}
			} );
		}

		window.addEventListener( 'beforeunload', stopTimer );

		loadQueue( 1 );
		loadApproved();
	} );
}( window, document ) );

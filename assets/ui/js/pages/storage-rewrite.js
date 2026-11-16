/**
 * Attachment URL rewrite page controller.
 *
 * The server owns the cursor: every request runs a time-boxed slice of the
 * scan, the rewrite or the rollback and returns the full public summary. This
 * controller renders that summary, keeps the selection in sync, and asks for
 * the next slice while the status still says the work is moving.
 *
 * @package Sakura_Image_Optimizer
 */
(function () {
	'use strict';

	var admin = window.SakuraAdmin;
	if ( ! admin || ! admin.ready ) {
		return;
	}

	admin.ready( function () {
		var root = admin.root();
		if ( ! root || 'storage-rewrite' !== root.getAttribute( 'data-sakura-page' ) ) {
			return;
		}

		var config = admin.page && admin.page.data ? admin.page.data : {};
		var strings = config.strings || {};
		var nonce = config.nonce || '';
		var batch = Number( config.batch ) || 50;
		var groupLabels = strings.groupLabels || {};
		var current = config.state || {};
		var MAX_STEPS = 400;

		var el = {
			from: admin.qs( '#rewrite-from', root ),
			to: admin.qs( '#rewrite-to', root ),
			scan: admin.qs( '#rewrite-scan', root ),
			reset: admin.qs( '#rewrite-reset', root ),
			scanStatus: admin.qs( '#rewrite-scan-status', root ),
			scanProgress: admin.qs( '#rewrite-scan-progress', root ),
			scanBar: admin.qs( '[data-rewrite-scan-bar]', root ),
			selectAll: admin.qs( '#rewrite-select-all', root ),
			presetDefault: admin.qs( '#rewrite-preset-default', root ),
			presetAll: admin.qs( '#rewrite-preset-all', root ),
			selection: admin.qs( '#rewrite-selection', root ),
			tasks: admin.qs( '#rewrite-tasks', root ),
			preview: admin.qs( '#rewrite-preview', root ),
			previewTitle: admin.qs( '#rewrite-preview-title', root ),
			previewBody: admin.qs( '#rewrite-preview-body', root ),
			previewClose: admin.qs( '#rewrite-preview-close', root ),
			run: admin.qs( '#rewrite-run', root ),
			runProgress: admin.qs( '#rewrite-run-progress', root ),
			runBar: admin.qs( '[data-rewrite-run-bar]', root ),
			note: admin.qs( '#rewrite-run-note', root ),
			runs: admin.qs( '#rewrite-runs', root ),
			stats: {}
		};

		admin.qsa( '[data-rewrite-stat]', root ).forEach( function ( node ) {
			el.stats[ node.getAttribute( 'data-rewrite-stat' ) ] = node;
		} );

		var busy = false;
		var locked = false;

		var STATUS = {
			idle: { label: strings.statusIdle || '未扫描', tone: 'neutral', preview: false, canRun: false },
			scanning: { label: strings.statusScanning || '扫描中', tone: 'info', preview: false, canRun: false },
			ready: { label: strings.statusReady || '待执行', tone: 'warn', preview: true, canRun: true },
			running: { label: strings.statusRunning || '改写中', tone: 'info', preview: true, canRun: true },
			done: { label: strings.statusDone || '已完成', tone: 'ok', preview: true, canRun: false }
		};

		function statusInfo( status ) {
			return STATUS[ status ] || STATUS.idle;
		}

		function toast( message, tone ) {
			if ( ! message ) {
				return;
			}
			admin.ui.toast( message, tone || 'info', { duration: 'bad' === tone ? 6000 : 4200 } );
		}

		function errorText( error, fallback ) {
			if ( error && error.payload ) {
				if ( error.payload.data && error.payload.data.message ) {
					return String( error.payload.data.message );
				}
				if ( error.payload.message ) {
					return String( error.payload.message );
				}
			}
			if ( error && error.message ) {
				return String( error.message );
			}
			return fallback || strings.unknown || '';
		}

		function num( value ) {
			var parsed = Number( value );
			if ( ! isFinite( parsed ) ) {
				parsed = 0;
			}
			return admin.format.number( parsed, 0 );
		}

		function make( tag, className, text ) {
			var node = document.createElement( tag );
			if ( className ) {
				node.className = className;
			}
			if ( text !== undefined && text !== null ) {
				node.textContent = String( text );
			}
			return node;
		}

		function cell( label, content ) {
			var td = document.createElement( 'td' );
			td.setAttribute( 'data-label', label );
			if ( content instanceof window.Node ) {
				td.appendChild( content );
			} else {
				td.textContent = String( content );
			}
			return td;
		}

		function clear( node ) {
			while ( node.firstChild ) {
				node.removeChild( node.firstChild );
			}
		}

		function emptyRow( colspan, title, message ) {
			var tr = document.createElement( 'tr' );
			tr.className = 'rewrite-empty-row';
			var td = document.createElement( 'td' );
			td.colSpan = colspan;
			var box = make( 'div', 'empty-state' );
			box.appendChild( make( 'strong', 'empty-state__title', title ) );
			box.appendChild( make( 'p', 'empty-state__message', message ) );
			td.appendChild( box );
			tr.appendChild( td );
			return tr;
		}

		function renderStatus( status ) {
			var info = statusInfo( status );
			if ( ! el.scanStatus ) {
				return;
			}
			var badge = admin.qs( '.badge', el.scanStatus );
			if ( badge ) {
				badge.className = 'badge badge--' + info.tone;
				badge.textContent = info.label;
			}
		}

		function taskRow( task, canPreview ) {
			var tr = document.createElement( 'tr' );
			tr.setAttribute( 'data-index', String( task.index ) );

			var pick = document.createElement( 'td' );
			pick.className = 'rewrite-tasks__pick';
			pick.setAttribute( 'data-label', '选择' );
			var check = document.createElement( 'input' );
			check.type = 'checkbox';
			check.className = 'rewrite-task__check';
			check.value = String( task.index );
			check.checked = !! task.enabled;
			check.setAttribute( 'aria-label', '选择 ' + String( task.label || '' ) );
			pick.appendChild( check );
			tr.appendChild( pick );

			var label = document.createElement( 'td' );
			label.setAttribute( 'data-label', '位置' );
			label.appendChild( make( 'code', 'rewrite-task__label', task.label || '' ) );
			label.appendChild( make( 'span', 'rewrite-task__meta', String( task.table || '' ) + '.' + String( task.column || '' ) ) );
			tr.appendChild( label );

			var groupKey = String( task.group || 'other' );
			var group = document.createElement( 'td' );
			group.setAttribute( 'data-label', '分组' );
			group.appendChild( make( 'span', 'badge badge--' + ( 'content' === groupKey ? 'ok' : 'neutral' ), groupLabels[ groupKey ] || groupKey ) );
			tr.appendChild( group );

			tr.appendChild( cell( '命中行数', num( task.rows ) ) );

			var sample = document.createElement( 'td' );
			sample.setAttribute( 'data-label', '样例' );
			var button = make( 'button', 'btn btn--quiet btn--small', '查看' );
			button.type = 'button';
			button.setAttribute( 'data-rewrite-preview', String( task.index ) );
			button.disabled = ! canPreview || ! ( Number( task.rows ) > 0 );
			sample.appendChild( button );
			tr.appendChild( sample );

			return tr;
		}

		function renderTasks( tasks, status ) {
			if ( ! el.tasks ) {
				return;
			}
			var canPreview = statusInfo( status ).preview;
			clear( el.tasks );
			if ( ! tasks.length ) {
				el.tasks.appendChild( emptyRow( 5, '还没有扫描结果', '填写新旧域名后点“扫描数据库”。扫描只读，不会改动任何数据；发现位置后再勾选并执行。' ) );
				return;
			}
			tasks.forEach( function ( task ) {
				el.tasks.appendChild( taskRow( task, canPreview ) );
			} );
		}

		function runRow( run ) {
			var tr = document.createElement( 'tr' );
			tr.setAttribute( 'data-run', String( run.runId ) );
			tr.appendChild( cell( '时间', String( run.createdAt || '' ) ) );

			var pair = document.createElement( 'td' );
			pair.setAttribute( 'data-label', '改写' );
			pair.appendChild( make( 'code', '', String( run.from || '' ) + ' → ' + String( run.to || '' ) ) );
			tr.appendChild( pair );

			tr.appendChild( cell( '账本行数', num( run.total ) ) );
			tr.appendChild( cell( '待回滚', num( run.pending ) ) );
			tr.appendChild( cell( '冲突', num( run.conflicts ) ) );

			var op = document.createElement( 'td' );
			op.setAttribute( 'data-label', '操作' );
			var button = make( 'button', 'btn btn--ghost btn--small', '回滚' );
			button.type = 'button';
			button.setAttribute( 'data-rewrite-revert', String( run.runId ) );
			button.disabled = locked || ! ( Number( run.pending ) > 0 );
			op.appendChild( button );
			tr.appendChild( op );

			return tr;
		}

		function renderRuns( runs ) {
			if ( ! el.runs ) {
				return;
			}
			clear( el.runs );
			if ( ! runs.length ) {
				el.runs.appendChild( emptyRow( 6, '还没有改写记录', '执行一次改写后，这里按批次列出账本；回滚会按记录把值改回原样。' ) );
				return;
			}
			runs.forEach( function ( run ) {
				el.runs.appendChild( runRow( run ) );
			} );
		}

		function checkboxes() {
			return el.tasks ? admin.qsa( '.rewrite-task__check', el.tasks ) : [];
		}

		function selectedIndexes() {
			var out = [];
			checkboxes().forEach( function ( box ) {
				if ( box.checked ) {
					out.push( Number( box.value ) );
				}
			} );
			return out;
		}

		function updateSelection() {
			var boxes = checkboxes();
			var selected = 0;
			boxes.forEach( function ( box ) {
				if ( box.checked ) {
					selected += 1;
				}
			} );

			if ( el.selection ) {
				el.selection.textContent = String( strings.selected || '已选 %d 项' ).replace( '%d', String( selected ) );
			}
			if ( el.selectAll ) {
				el.selectAll.checked = boxes.length > 0 && selected === boxes.length;
				el.selectAll.indeterminate = selected > 0 && selected < boxes.length;
			}
			if ( el.run ) {
				var info = statusInfo( String( current.status || 'idle' ) );
				el.run.disabled = locked || ! info.canRun || ! boxes.length || ( 'ready' === current.status && 0 === selected );
			}
		}

		function setControls( status ) {
			var info = statusInfo( status );
			if ( el.scan ) {
				el.scan.disabled = locked || 'scanning' === status || 'running' === status;
			}
			if ( el.reset ) {
				el.reset.disabled = locked || 'idle' === status || 'scanning' === status;
			}
			if ( el.presetDefault ) {
				el.presetDefault.disabled = locked || ! info.preview || ! ( current.tasks || [] ).length;
			}
			if ( el.presetAll ) {
				el.presetAll.disabled = locked || ! info.preview || ! ( current.tasks || [] ).length;
			}
			admin.qsa( '[data-rewrite-preview]', root ).forEach( function ( button ) {
				button.disabled = locked || ! info.preview;
			} );
			admin.qsa( '[data-rewrite-revert]', root ).forEach( function ( button ) {
				var runId = button.getAttribute( 'data-rewrite-revert' );
				var run = ( current.runs || [] ).filter( function ( item ) {
					return String( item.runId ) === String( runId );
				} )[0];
				button.disabled = locked || ! run || ! ( Number( run.pending ) > 0 );
			} );
			if ( el.run ) {
				updateSelection();
			}
		}

		function renderNote( status, data ) {
			if ( ! el.note ) {
				return;
			}
			var total = data ? Number( data.selected ? data.selected : data.total ) || 0 : 0;
			var text;
			if ( 'scanning' === status ) {
				text = strings.scanning || '';
			} else if ( 'ready' === status ) {
				text = '已扫描到 ' + num( total ) + ' 行待处理；勾选位置后执行。';
			} else if ( 'running' === status ) {
				text = '改写进行中：关闭页面后可以点“继续改写”接着跑。';
			} else if ( 'done' === status ) {
				text = '改写已完成。需要撤销时用右侧回滚账本，按记录逐行改回原值。';
			} else {
				text = '勾选上方的位置后执行；每行会先写入回滚账本，再更新原值。';
			}
			el.note.textContent = text;
		}

		function applySummary( data ) {
			if ( ! data ) {
				return;
			}
			current = data;
			var status = String( data.status || 'idle' );

			renderStatus( status );

			Object.keys( el.stats ).forEach( function ( key ) {
				var value = 'total' === key && data.selected ? data.selected : data[ key ];

				if ( value !== undefined ) {
					el.stats[ key ].textContent = num( value );
				}
			} );

			var scanning = 'scanning' === status;
			if ( el.scanProgress ) {
				el.scanProgress.hidden = ! scanning;
				el.scanProgress.classList.toggle( 'progress--indeterminate', scanning );
			}
			if ( el.scanBar ) {
				el.scanBar.style.width = scanning ? '100%' : '0%';
			}

			var progress = Math.min( 100, Math.max( 0, Number( data.progress ) || 0 ) );
			if ( el.runProgress ) {
				el.runProgress.hidden = ! ( 'running' === status || ( 'done' === status && progress > 0 ) );
			}
			if ( el.runBar ) {
				el.runBar.style.width = String( progress ) + '%';
			}

			renderTasks( data.tasks || [], status );
			renderRuns( data.runs || [] );
			renderNote( status, data );
			setControls( status );
		}

		function post( action, payload, timeout ) {
			return admin.api.ajax( action, Object.assign( { nonce: nonce }, payload || {} ), { timeout: timeout || 60000 } );
		}

		function hosts() {
			var from = el.from ? String( el.from.value || '' ).trim() : '';
			var to = el.to ? String( el.to.value || '' ).trim() : '';
			if ( ! from || ! to ) {
				toast( strings.badHost || '', 'bad' );
				return null;
			}
			if ( from === to ) {
				toast( strings.hostsSame || '', 'bad' );
				return null;
			}
			return { from: from, to: to };
		}

		function finish() {
			busy = false;
			locked = false;
			applySummary( current );
		}

		function scanStep( steps ) {
			return post( 'sakura_url_rewrite_scan_step', {}, 60000 ).then( function ( data ) {
				applySummary( data );
				if ( data && 'scanning' === data.status ) {
					if ( steps >= MAX_STEPS ) {
						throw new Error( strings.unknown || '' );
					}
					return scanStep( steps + 1 );
				}
				return data;
			} );
		}

		function startScan() {
			if ( busy ) {
				return;
			}
			var pair = hosts();
			if ( ! pair ) {
				return;
			}
			busy = true;
			locked = true;
			setControls( current.status );

			post( 'sakura_url_rewrite_scan', pair, 60000 ).then( function ( data ) {
				applySummary( data );
				if ( data && 'scanning' === data.status ) {
					return scanStep( 0 );
				}
				return data;
			} ).then( function ( data ) {
				var count = data && data.tasks ? data.tasks.length : 0;
				toast( count ? ( strings.scanDone || '' ) : ( strings.scanEmpty || '' ), count ? 'ok' : 'warn' );
			} ).catch( function ( error ) {
				toast( errorText( error ), 'bad' );
			} ).then( finish );
		}

		function runStep( steps ) {
			return post( 'sakura_url_rewrite_run', { tasks: [], batch: batch }, 90000 ).then( function ( data ) {
				applySummary( data );
				if ( data && 'running' === data.status ) {
					if ( steps >= MAX_STEPS ) {
						throw new Error( strings.unknown || '' );
					}
					return runStep( steps + 1 );
				}
				return data;
			} );
		}

		function startRun() {
			if ( busy ) {
				return;
			}
			if ( ! ( current.tasks || [] ).length ) {
				toast( strings.noTasks || '', 'warn' );
				return;
			}
			var selection = selectedIndexes();
			if ( ! selection.length ) {
				toast( strings.noSelection || '', 'warn' );
				return;
			}

			admin.ui.confirm( strings.confirmRun || '', {
				title: strings.confirmTitle || '',
				confirmText: strings.confirmButton || '',
				cancelText: '取消',
				tone: 'warn'
			} ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				busy = true;
				locked = true;
				setControls( current.status );

				return post( 'sakura_url_rewrite_run', { tasks: selection, batch: batch }, 90000 ).then( function ( data ) {
					applySummary( data );
					if ( data && 'running' === data.status ) {
						return runStep( 0 );
					}
					return data;
				} ).then( function ( data ) {
					applySummary( data );
					toast( strings.runDone || '', 'ok' );
				} ).catch( function ( error ) {
					toast( errorText( error ), 'bad' );
				} ).then( finish );
			} );
		}

		function revertStep( runId, after, steps ) {
			return post( 'sakura_url_rewrite_revert', { runId: runId, after: after, batch: batch }, 90000 ).then( function ( data ) {
				if ( data && data.runs ) {
					current.runs = data.runs;
					renderRuns( data.runs );
				}
				if ( data && ! data.done ) {
					if ( steps >= MAX_STEPS ) {
						throw new Error( strings.unknown || '' );
					}
					return revertStep( runId, Number( data.lastId ) || 0, steps + 1 );
				}
				return data;
			} );
		}

		function startRevert( runId ) {
			if ( busy || ! runId ) {
				return;
			}

			admin.ui.confirm( strings.revertDesc || '', {
				title: strings.revertTitle || '',
				confirmText: strings.revertButton || '',
				cancelText: '取消',
				tone: 'warn',
				danger: true
			} ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				busy = true;
				locked = true;
				setControls( current.status );

				revertStep( runId, 0, 0 ).then( function () {
					toast( strings.revertDone || '', 'ok' );
				} ).catch( function ( error ) {
					toast( errorText( error ), 'bad' );
				} ).then( function () {
					busy = false;
					locked = false;
					setControls( current.status );
				} );
			} );
		}

		function startReset() {
			if ( busy ) {
				return;
			}
			admin.ui.confirm( strings.resetDesc || '', {
				title: strings.resetTitle || '',
				confirmText: strings.resetButton || '',
				cancelText: '取消',
				tone: 'warn'
			} ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				busy = true;
				locked = true;
				setControls( current.status );

				post( 'sakura_url_rewrite_reset', {}, 30000 ).then( function ( data ) {
					applySummary( data );
					toast( strings.resetDone || '', 'ok' );
				} ).catch( function ( error ) {
					toast( errorText( error ), 'bad' );
				} ).then( finish );
			} );
		}

		function openPreview( index ) {
			if ( ! el.preview || busy ) {
				return;
			}
			el.preview.hidden = false;
			if ( el.previewTitle ) {
				el.previewTitle.textContent = '样例';
			}
			if ( el.previewBody ) {
				clear( el.previewBody );
				el.previewBody.appendChild( make( 'p', 'rewrite-preview__hint', strings.previewing || '' ) );
			}

			post( 'sakura_url_rewrite_preview', { index: index }, 30000 ).then( function ( data ) {
				if ( el.previewTitle ) {
					el.previewTitle.textContent = data && data.label ? String( data.label ) : '样例';
				}
				if ( ! el.previewBody ) {
					return;
				}
				clear( el.previewBody );
				var samples = data && data.samples ? data.samples : [];
				if ( ! samples.length ) {
					el.previewBody.appendChild( make( 'p', 'rewrite-preview__hint', strings.previewEmpty || '' ) );
					return;
				}
				samples.forEach( function ( sample ) {
					var row = make( 'div', 'rewrite-preview__row' );
					row.appendChild( make( 'span', 'rewrite-preview__pk', '#' + String( sample.pk || '' ) ) );
					row.appendChild( make( 'code', 'rewrite-preview__old', sample.old || '' ) );
					row.appendChild( make( 'span', 'rewrite-preview__arrow', '→' ) );
					row.appendChild( make( 'code', 'rewrite-preview__new', sample.new || '' ) );
					el.previewBody.appendChild( row );
				} );
			} ).catch( function ( error ) {
				toast( errorText( error ), 'bad' );
			} );
		}

		function closePreview() {
			if ( el.preview ) {
				el.preview.hidden = true;
			}
		}

		function preset( groups ) {
			var tasks = current.tasks || [];
			checkboxes().forEach( function ( box ) {
				var index = Number( box.value );
				var task = tasks.filter( function ( item ) {
					return Number( item.index ) === index;
				} )[0];
				var group = task ? String( task.group || '' ) : '';
				box.checked = ! groups || groups.indexOf( group ) !== -1;
			} );
			updateSelection();
		}

		if ( el.scan ) {
			el.scan.addEventListener( 'click', startScan );
		}
		if ( el.reset ) {
			el.reset.addEventListener( 'click', startReset );
		}
		if ( el.run ) {
			el.run.addEventListener( 'click', startRun );
		}
		if ( el.previewClose ) {
			el.previewClose.addEventListener( 'click', closePreview );
		}
		if ( el.selectAll ) {
			el.selectAll.addEventListener( 'change', function () {
				var on = !! el.selectAll.checked;
				checkboxes().forEach( function ( box ) {
					box.checked = on;
				} );
				updateSelection();
			} );
		}
		if ( el.presetDefault ) {
			el.presetDefault.addEventListener( 'click', function () {
				preset( [ 'content', 'theme' ] );
			} );
		}
		if ( el.presetAll ) {
			el.presetAll.addEventListener( 'click', function () {
				preset( null );
			} );
		}

		root.addEventListener( 'change', function ( event ) {
			var target = event.target;
			if ( target && target.classList && target.classList.contains( 'rewrite-task__check' ) ) {
				updateSelection();
			}
		} );

		root.addEventListener( 'click', function ( event ) {
			var target = event.target;
			if ( ! target || typeof target.closest !== 'function' ) {
				return;
			}
			var previewButton = target.closest( '[data-rewrite-preview]' );
			if ( previewButton && root.contains( previewButton ) ) {
				openPreview( previewButton.getAttribute( 'data-rewrite-preview' ) );
				return;
			}
			var revertButton = target.closest( '[data-rewrite-revert]' );
			if ( revertButton && root.contains( revertButton ) ) {
				startRevert( revertButton.getAttribute( 'data-rewrite-revert' ) );
			}
		} );

		applySummary( current );

		if ( 'scanning' === String( current.status || '' ) ) {
			busy = true;
			locked = true;
			setControls( 'scanning' );
			scanStep( 0 ).then( function ( data ) {
				applySummary( data );
				var count = data && data.tasks ? data.tasks.length : 0;
				toast( count ? ( strings.scanDone || '' ) : ( strings.scanEmpty || '' ), count ? 'ok' : 'warn' );
			} ).catch( function ( error ) {
				toast( errorText( error ), 'bad' );
			} ).then( finish );
		}
	} );
}( window ) );

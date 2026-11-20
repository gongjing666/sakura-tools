/**
 * Sync center page controller.
 *
 * Handles scan polling, reconciliation table paging, batch actions, logs and
 * exports. Only talks to the site through SakuraAdmin.api / SakuraAdmin.ui.
 */
(function () {
	'use strict';

	var admin = window.SakuraAdmin;
	if ( ! admin || ! admin.ready ) {
		return;
	}

	function pad2( value ) {
		return value < 10 ? '0' + value : String( value );
	}

	function formatDate( timestamp ) {
		var value = Number( timestamp || 0 );
		if ( ! value ) {
			return '—';
		}
		var date = new Date( value * 1000 );
		return pad2( date.getMonth() + 1 ) + '/' + pad2( date.getDate() ) + ' ' + pad2( date.getHours() ) + ':' + pad2( date.getMinutes() );
	}

	function formatSize( bytes ) {
		var value = Number( bytes || 0 );
		if ( ! value ) {
			return '—';
		}
		return admin.format.fileSize( value );
	}

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var text = ( template === undefined || template === null ) ? '' : String( template );
		var out = '';
		var nextIndex = 0;
		for ( var i = 0; i < text.length; i++ ) {
			var ch = text.charAt( i );
			if ( '%' !== ch ) {
				out += ch;
				continue;
			}
			var next = text.charAt( i + 1 );
			if ( next >= '1' && next <= '9' && '$' === text.charAt( i + 2 ) ) {
				var type = text.charAt( i + 3 );
				if ( 's' === type || 'd' === type ) {
					var positional = args[ Number( next ) - 1 ];
					out += ( positional === undefined || positional === null ) ? '' : String( positional );
					i += 3;
					continue;
				}
			}
			if ( 's' === next || 'd' === next ) {
				var sequential = args[ nextIndex ];
				nextIndex += 1;
				out += ( sequential === undefined || sequential === null ) ? '' : String( sequential );
				i += 1;
				continue;
			}
			out += ch;
		}
		return out;
	}

	admin.ready( function () {
		var root = admin.root();
		if ( ! root || 'storage-sync' !== root.getAttribute( 'data-sakura-page' ) ) {
			return;
		}

		var config      = admin.page && admin.page.data ? admin.page.data : {};
		var strings     = config.strings || {};
		var labels      = config.labels || {};
		var statusLabels = config.statusLabels || {};
		var stateEl     = admin.qs( '#sakura-sync-state', root );

		function toast( message, tone ) {
			if ( ! message ) {
				return;
			}
			admin.ui.toast( message, tone || 'info', { duration: 'bad' === tone ? 6000 : 4200 } );
		}

		function errorText( error, fallback ) {
			if ( error && error.payload ) {
				var payload = error.payload;
				if ( payload.data && payload.data.message ) {
					return String( payload.data.message );
				}
				if ( payload.message ) {
					return String( payload.message );
				}
			}
			if ( error && error.message ) {
				return String( error.message );
			}
			return fallback || strings.unknownError || '';
		}

		function reloadWithToast( message, tone ) {
			try {
				window.sessionStorage.setItem( 'sakuraSyncToast', JSON.stringify( { message: message || '', tone: tone || 'ok' } ) );
			} catch ( e ) {
				// Ignore storage failures.
			}
			window.location.reload();
		}

		function restoreToast() {
			var raw = null;
			try {
				raw = window.sessionStorage.getItem( 'sakuraSyncToast' );
				if ( raw ) {
					window.sessionStorage.removeItem( 'sakuraSyncToast' );
				}
			} catch ( e ) {
				return;
			}
			if ( ! raw ) {
				return;
			}
			var payload = admin.parseJSON( raw, null );
			if ( payload && payload.message ) {
				toast( payload.message, payload.tone || 'ok' );
			}
		}

		restoreToast();
		initTools();
		initTable();
		initLogs();
		initDialogs();

		/* -----------------------------------------------------------------
		 * Setup and first-reconciliation dialogs.
		 *
		 * Config_Notice renders at most one modal into the page body. They share the
		 * dismiss endpoint with the connection screen, so a "not now" here also
		 * quiets the setup prompt; the choice is stored per user by the server.
		 * The markup contract is #sakura-s3-*-modal + [data-config-close] and
		 * optional [data-config-dont-remind]; everything else is behaviour.
		 * ----------------------------------------------------------------- */

		function initDialogs() {
			var modals = admin.qsa( '#sakura-s3-config-modal, #sakura-s3-paused-modal, #sakura-s3-reconcile-modal', document );
			if ( ! modals.length ) {
				return;
			}

			var skipKey   = 'sakuraS3ConfigSkipOnce';
			var maybeSkip = false;

			try {
				maybeSkip = '1' === window.sessionStorage.getItem( skipKey );
				if ( maybeSkip ) {
					window.sessionStorage.removeItem( skipKey );
				}
			} catch ( error ) {
				maybeSkip = false;
			}


			function focusables( card ) {
				return admin.qsa(
					'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
					card
				).filter( function ( node ) {
					return ! node.hasAttribute( 'hidden' ) && ( node.offsetWidth > 0 || node.offsetHeight > 0 );
				} );
			}

			function build( modal ) {
				var card       = admin.qs( '.sakura-modal__card', modal );
				var focusFirst = admin.qs( '[data-config-focus]', modal );
				var dontRemind = admin.qs( '[data-config-dont-remind]', modal );
				var lastFocus  = null;
				var sent       = false;
				var open       = false;

				/* Only "do not remind me again" is worth a round trip. A plain
				 * close (X, Escape, the ghost button, or walking away through the
				 * primary link) writes nothing, so the dialog comes back on the next
				 * load while the problem is still there. */
				function persist() {
					if ( sent ) {
						return;
					}
					sent = true;
					if ( ! ( dontRemind && dontRemind.checked ) ) {
						return;
					}
					admin.api.ajax(
						'sakura_s3_config_notice',
						{
							nonce: modal.getAttribute( 'data-nonce' ) || '',
							dismiss: '1'
						}
					).catch( function () {} );
				}

				function close() {
					if ( ! open ) {
						return;
					}
					open = false;
					persist();
					// Same exit as every other prompt: ui.js owns the fade, so an autoshow
					// prompt and a clicked one do not close in two different ways.
					admin.ui.hideModal( modal );
					// The page never moved while the card was up, so handing focus back is the
					// whole restore; preventScroll keeps that focus call from dragging the page.
					if ( lastFocus && lastFocus.focus ) {
						lastFocus.focus( { preventScroll: true } );
					}
				}

				function show() {
					if ( open ) {
						return;
					}
					lastFocus = document.activeElement;
					open = true;
					// No scroll lock and no scroll bookkeeping: the page behind the card never
					// moves, so there is no offset to put back on close.
					modal.hidden = false;
					modal.setAttribute( 'aria-hidden', 'false' );
					modal.setAttribute( 'data-sakura-modal-instant', '1' );
					window.setTimeout( function () {
						if ( focusFirst ) {
							focusFirst.focus( { preventScroll: true } );
						}
					}, 40 );
				}

				admin.qsa( '[data-config-close]', modal ).forEach( function ( node ) {
					admin.on( node, 'click', function ( event ) {
						event.preventDefault();
						close();
					} );
				} );

				/* The scan button is handled by the page-level delegate, so this
				 * handler only closes the dialog; the request itself must go
				 * through the same scan flow that shows progress in the header. */
				var primary = admin.qs( 'a[data-config-primary]', modal );
				if ( primary ) {
					admin.on( primary, 'click', function () {
						var href = primary.getAttribute( 'href' ) || '';
						close();
						if ( 0 !== href.indexOf( '#' ) ) {
							/* The connection page would otherwise show its own setup
							 * dialog on top of the form the operator just asked for. */
							try {
								window.sessionStorage.setItem( skipKey, '1' );
							} catch ( error ) {}
						}
					} );
				}

				document.addEventListener( 'keydown', function ( event ) {
					if ( ! open ) {
						return;
					}
					if ( 'Escape' === event.key ) {
						event.preventDefault();
						close();
						return;
					}
					if ( 'Tab' !== event.key || ! card ) {
						return;
					}
					var list = focusables( card );
					if ( ! list.length ) {
						return;
					}
					var first = list[ 0 ];
					var last  = list[ list.length - 1 ];
					if ( event.shiftKey && document.activeElement === first ) {
						event.preventDefault();
						last.focus();
					} else if ( ! event.shiftKey && document.activeElement === last ) {
						event.preventDefault();
						first.focus();
					}
				} );

				return {
					show: show,
					close: close,
					isOpen: function () {
						return open;
					}
				};
			}

			var controllers = modals.map( build );

			/* A scan from the leftmost dialog should also hide a second dialog that
			 * describes the same missing work. */
			admin.qsa( '[data-config-primary][data-sakura-action="scan"]', document ).forEach( function ( button ) {
				admin.on( button, 'click', function () {
					controllers.forEach( function ( controller ) {
						controller.close();
					} );
				} );
			} );

			if ( maybeSkip ) {
				return;
			}

			/* Opened synchronously, before the first paint, so the card is already there
			 * when the page appears instead of fading in over a screen the operator has
			 * been reading for a fifth of a second. Only the first prompt opens: they all
			 * describe the same missing setup, and two stacked cards are worse than one. */
			controllers[ 0 ].show();
		}

		/* -----------------------------------------------------------------
		 * Scan, refresh, cancel, resume, retry, index toggle and connection test.
		 * ----------------------------------------------------------------- */

	/* Cards are laid out by CSS Grid, so a row-count change needs no remeasure.
	 * The event is still emitted because other listeners may want to know that
	 * the table contents changed. */
	function notifyLayout() {
		if ( admin.events ) {
			admin.events.emit( 'layout-refresh' );
		}
	}

	// Storage switched off freezes every action that would read from or write
	// to the bucket. The server renders those buttons disabled and flags the
	// page state; these helpers keep rows and batches rebuilt by AJAX in the
	// same state, and stop a stray click from reaching the REST layer.
	function isFrozen() {
		var node = admin.qs( '#sakura-sync-state' );
		return !! node && '1' === node.getAttribute( 'data-frozen' );
	}

	// R18: the bucket changed and nobody has reconciled against the new one
	// yet, so the rows below belong to the previous target. It behaves like a
	// freeze for everything except the reconcile that clears it.
	function isReconcileGated() {
		var node = admin.qs( '#sakura-sync-state' );
		return !! node && '1' === node.getAttribute( 'data-reconcile-gated' );
	}

	function isLocked() {
		return isFrozen() || isReconcileGated();
	}

	function applyFrozen( button ) {
		if ( button && isLocked() ) {
			button.disabled = true;
		}
		return button;
	}

	// The gate only stops what would act on the previous bucket's numbers:
	// refreshing differences, resuming or retrying, and every upload, download
	// or delete. A scan is the way out of the gate; the sync-index toggle,
	// the connection test and stopping a running scan touch no indexed row.
	var GATE_ALLOWED = { scan: 1, cancel: 1, adopt: 1, 'toggle-index': 1, 'test-connection': 1 };

	function isBlocked( action ) {
		if ( isFrozen() ) {
			return true;
		}
		return isReconcileGated() && ! Object.prototype.hasOwnProperty.call( GATE_ALLOWED, action );
	}

	function initTools() {
			var scanBusy      = false;
			var scanPollToken = 0;

			function currentScanId() {
				return stateEl ? String( stateEl.getAttribute( 'data-scan-id' ) || '0' ) : '0';
			}

			function updateScanMeta( data ) {
				var meta = admin.qs( '#sakura-sync-scan-meta', root );
				if ( ! meta || ! data ) {
					return;
				}
				if ( data.state ) {
					meta.setAttribute( 'data-state', data.state );
				}
				if ( data.progress !== undefined && data.progress !== null ) {
					var percent = Math.max( 0, Math.min( 100, Math.round( Number( data.progress ) || 0 ) ) );
					meta.textContent = ( strings.scanning || '' ) + ' ' + percent + '%';
				}
			}

			function setScanControls( data ) {
				if ( ! data ) {
					return;
				}
				var running = 'running' === data.state || 'queued' === data.state;
				var cancel  = admin.qs( '#sakura-sync-cancel', root );
				var resume  = admin.qs( '#sakura-sync-resume', root );
				if ( cancel ) {
					cancel.hidden = ! running;
				}
				if ( resume ) {
					resume.hidden = running || 'done' === data.state || ( ! data.resumable && 'cancelled' !== data.state );
					if ( 'cancelled' === data.state && data.resumable ) {
						resume.hidden = false;
					}
				}
				if ( stateEl ) {
					stateEl.setAttribute( 'data-scan-state', data.state || '' );
					if ( data.scan_id ) {
						stateEl.setAttribute( 'data-scan-id', String( data.scan_id ) );
					}
				}
			}

			function follow( scanId, onProgress ) {
				var token   = ++scanPollToken;
				var attempt = 0;
				var delays  = [ 400, 1000, 2000, 5000 ];

				return new Promise( function ( resolve, reject ) {
					function step() {
						if ( token !== scanPollToken ) {
							resolve( { state: 'detached' } );
							return;
						}
						admin.api.ajax( 'sakura_s3_sync_scan_step', {
							scan_id: scanId,
							nonce: config.scanNonce
						}, { timeout: 30000 } ).then( function ( data ) {
							if ( token !== scanPollToken ) {
								resolve( { state: 'detached' } );
								return;
							}
							if ( onProgress ) {
								onProgress( data );
							}
							if ( data && ( 'running' === data.state || 'queued' === data.state ) ) {
								var delay = delays[ Math.min( attempt, delays.length - 1 ) ];
								attempt += 1;
								window.setTimeout( step, delay );
								return;
							}
							resolve( data || {} );
						} ).catch( reject );
					}
					step();
				} );
			}

			function startScan( button ) {
				if ( scanBusy ) {
					return;
				}
				scanBusy = true;
				var restore = admin.ui.busy( button, true );
				var nonce   = button.getAttribute( 'data-sakura-nonce' ) || config.scanNonce;
				admin.api.ajax( 'sakura_s3_sync_scan', { nonce: nonce }, { timeout: 60000 } ).then( function ( data ) {
					if ( ! data || ! data.scan_id ) {
						throw new Error( strings.unknownError || '' );
					}
					if ( 'done' === data.state ) {
						reloadWithToast( data.message || strings.done, 'ok' );
						return;
					}
					if ( 'running' === data.state || 'queued' === data.state ) {
						updateScanMeta( data );
						setScanControls( data );
						return follow( data.scan_id, function ( step ) {
							updateScanMeta( step );
							setScanControls( step );
						} ).then( function ( final ) {
							if ( final.state === 'cancelled' ) {
								reloadWithToast( strings.cancelled, 'warn' );
								return;
							}
							reloadWithToast( final.message || strings.done, 'ok' );
						} );
					}
					reloadWithToast( data.message || strings.done, 'ok' );
				} ).catch( function ( error ) {
					scanBusy = false;
					restore();
					toast( errorText( error, strings.unknownError ), 'bad' );
				} );
			}

			function refreshDiff( button ) {
				var restore = admin.ui.busy( button, true );
				var nonce   = button.getAttribute( 'data-sakura-nonce' ) || config.refreshNonce;
				admin.api.ajax( 'sakura_s3_sync_refresh', { nonce: nonce }, { timeout: 60000 } ).then( function ( data ) {
					reloadWithToast( ( data && data.message ) || strings.refreshed, 'ok' );
				} ).catch( function ( error ) {
					restore();
					toast( errorText( error, strings.unknownError ), 'bad' );
				} );
			}

			function cancelScan( button ) {
				var restore = admin.ui.busy( button, true );
				var nonce   = button.getAttribute( 'data-sakura-nonce' ) || config.cancelNonce;
				admin.api.ajax( 'sakura_s3_sync_cancel', {
					scan_id: currentScanId(),
					nonce: nonce
				}, { timeout: 30000 } ).then( function () {
					scanPollToken += 1;
					reloadWithToast( strings.cancelled, 'warn' );
				} ).catch( function ( error ) {
					restore();
					toast( errorText( error, strings.cancelFailed ), 'bad' );
				} );
			}

			function resumeScan( button ) {
				var restore = admin.ui.busy( button, true );
				var nonce   = button.getAttribute( 'data-sakura-nonce' ) || config.resumeNonce;
				admin.api.ajax( 'sakura_s3_sync_resume', { nonce: nonce }, { timeout: 60000 } ).then( function ( data ) {
					if ( ! data || ! data.scan_id ) {
						throw new Error( strings.resumeMissing || strings.unknownError || '' );
					}
					if ( 'running' === data.state || 'queued' === data.state ) {
						updateScanMeta( data );
						setScanControls( data );
						return follow( data.scan_id, function ( step ) {
							updateScanMeta( step );
							setScanControls( step );
						} ).then( function ( final ) {
							if ( final.state === 'cancelled' ) {
								reloadWithToast( strings.cancelled, 'warn' );
								return;
							}
							reloadWithToast( final.message || strings.done, 'ok' );
						} );
					}
					reloadWithToast( ( data && data.message ) || strings.done, 'ok' );
				} ).catch( function ( error ) {
					restore();
					toast( errorText( error, strings.unknownError ), 'bad' );
				} );
			}

			function retryFailed( button ) {
				var restore = admin.ui.busy( button, true );
				var nonce   = button.getAttribute( 'data-sakura-nonce' ) || config.retryNonce;
				admin.api.ajax( 'sakura_s3_retry', {
					_ajax_nonce: nonce,
					attachment_id: button.getAttribute( 'data-sakura-attachment' ) || '0'
				}, { timeout: 120000 } ).then( function ( data ) {
					reloadWithToast( ( data && data.message ) || strings.retryDone, 'ok' );
				} ).catch( function ( error ) {
					restore();
					toast( errorText( error, strings.unknownError ), 'bad' );
				} );
			}

			function toggleIndex( button ) {
				var restore = admin.ui.busy( button, true );
				var nonce   = button.getAttribute( 'data-sakura-nonce' ) || config.toggleNonce;
				var enabled = '1' === button.getAttribute( 'data-sakura-enabled' ) ? '1' : '0';
				admin.api.ajax( 'sakura_s3_sync_toggle_index', {
					enabled: enabled,
					nonce: nonce
				}, { timeout: 30000 } ).then( function ( data ) {
					reloadWithToast( ( data && data.message ) || strings.actionDone, 'ok' );
				} ).catch( function ( error ) {
					restore();
					toast( errorText( error, strings.unknownError ), 'bad' );
				} );
			}

			function testConnection( button ) {
				var restore = admin.ui.busy( button, true );
				var nonce   = button.getAttribute( 'data-sakura-nonce' ) || config.testNonce;
				admin.api.ajax( 'sakura_s3_sync_test', { nonce: nonce }, { timeout: 30000 } ).then( function ( data ) {
					var latency = admin.qs( '#sakura-sync-latency', root );
					if ( latency ) {
						latency.textContent = 'headBucket · ' + ( Number( data && data.latency ) || 0 ) + ' ms';
					}
					toast( ( data && data.message ) || strings.testDone, 'ok' );
					restore();
				} ).catch( function ( error ) {
					restore();
					toast( errorText( error, strings.unknownError ), 'bad' );
				} );
			}

			admin.delegate( root, 'click', '[data-sakura-action]', function ( event, button ) {
				var action = button.getAttribute( 'data-sakura-action' ) || '';
				if ( ! action || 0 === action.indexOf( 'row-' ) ) {
					return;
				}
				// Disabled buttons do not fire clicks, but the scan buttons are also
				// reachable through the dialogs and the batch bar is rebuilt on every
				// page load, so refuse here as well instead of trusting the markup.
				// A scan stays allowed while the reconcile gate is up: it is the way
				// out of that state.
				if ( isBlocked( action ) || button.disabled ) {
					event.preventDefault();
					return;
				}
				event.preventDefault();
				if ( 'scan' === action ) {
					startScan( button );
					return;
				}
				if ( 'refresh' === action ) {
					refreshDiff( button );
					return;
				}
				if ( 'cancel' === action ) {
					cancelScan( button );
					return;
				}
				if ( 'resume' === action ) {
					resumeScan( button );
					return;
				}
				if ( 'retry-all' === action ) {
					retryFailed( button );
					return;
				}
				if ( 'toggle-index' === action ) {
					toggleIndex( button );
					return;
				}
				if ( 'test-connection' === action ) {
					testConnection( button );
					return;
				}
			} );

			// Resume following a scan that was already running when the page was opened.
			if ( stateEl ) {
				var initialState = stateEl.getAttribute( 'data-scan-state' ) || '';
				var initialId    = parseInt( stateEl.getAttribute( 'data-scan-id' ) || '0', 10 );
				if ( initialId && ( 'running' === initialState || 'queued' === initialState ) ) {
					follow( initialId, function ( step ) {
						updateScanMeta( step );
						setScanControls( step );
					} ).then( function ( final ) {
						if ( ! final || 'detached' === final.state ) {
							return;
						}
						if ( 'cancelled' === final.state ) {
							reloadWithToast( strings.cancelled, 'warn' );
							return;
						}
						reloadWithToast( final.message || strings.done, 'ok' );
					} ).catch( function () {
						// Background polling failures stay silent in the header.
					} );
				}
			}
		}

		/* -----------------------------------------------------------------
		 * Reconciliation table, filters, row actions, batches and pagination.
		 * ----------------------------------------------------------------- */

		function initTable() {
			var tbody = admin.qs( '#recon-body', root );
			if ( ! tbody ) {
				return;
			}

			var table          = tbody.closest( 'table' );
			var selectAll      = admin.qs( '#select-all', root );
			var batchBar       = admin.qs( '#recon-batch', root );
			var batchCount     = admin.qs( '#batch-count', root );
			var batchUpload    = admin.qs( '#batch-upload', root );
			var batchDownload  = admin.qs( '#batch-download', root );
			var batchDeleteBtn = admin.qs( '#batch-delete', root );
			var batchClear     = admin.qs( '#batch-clear', root );
			var batchProgress  = admin.qs( '#batch-progress', root );
			var progressText   = admin.qs( '#batch-progress-text', root );
			var progressPct    = admin.qs( '#batch-progress-pct', root );
			var progressTrack  = batchProgress ? admin.qs( '.progress', batchProgress ) : null;
			var progressFill   = batchProgress ? admin.qs( '.progress__bar', batchProgress ) : null;
			var summaryEl      = admin.qs( '#recon-summary', root );
			var pager          = admin.qs( '#recon-pager', root );
			var emptyRow       = admin.qs( '#recon-empty', root );
			var searchInput    = admin.qs( '#file-search', root );
			var typeSelect     = admin.qs( '#type-filter', root );
			var dateSelect     = admin.qs( '#date-filter', root );
			var filterChips    = admin.qsa( 'button[data-sakura-filter-value]', root );
			var sizeButtons    = admin.qsa( '[data-recon-size]', root );
			var adoptButton    = admin.qs( '#sakura-sync-adopt', root );
			var adoptText      = admin.qs( '#adopt-progress-text', root );
			var MAX_SELECT     = 100;

			var filter      = 'all';
			var pageNo      = 1;
			var pageSize    = Number( config.pageSize ) || 25;
			var cursor      = tbody.getAttribute( 'data-cursor' ) || '';
			var hasMore     = '1' === tbody.getAttribute( 'data-more' );
			var totalRows   = parseInt( tbody.getAttribute( 'data-total' ) || '0', 10 ) || 0;
			// Rows matching the active filters. The server reports it with the
			// first page of a filtered list; until then it mirrors the total.
			var matchedRows = totalRows;
			var counts      = {};
			var history     = [ '' ];
			var selection   = {};
			var requestSeq  = 0;
			var actionBusy  = false;
			var batchBusy   = false;
			var adoptBusy   = false;

			filterChips.forEach( function ( chip ) {
				var value = chip.getAttribute( 'data-sakura-filter-value' );
				var countEl = admin.qs( '.filter-chip__count', chip );
				if ( value && countEl ) {
					counts[ value ] = parseInt( String( countEl.textContent ).replace( /[^0-9]/g, '' ), 10 ) || 0;
				}
			} );

			function badgeTone( status ) {
				if ( 'synced' === status ) {
					return 'ok';
				}
				if ( 'failed' === status ) {
					return 'bad';
				}
				if ( 'pending' === status || 'mismatch' === status ) {
					return 'warn';
				}
				return 'neutral';
			}

			function selectedCount() {
				return Object.keys( selection ).length;
			}

			function selectedKeys( type ) {
				return Object.keys( selection ).filter( function ( key ) {
					var meta = selection[ key ];
					if ( ! meta ) {
						return false;
					}
					if ( 'upload' === type ) {
						return meta.local && 'synced' !== meta.status;
					}
					if ( 'download' === type ) {
						return meta.cloud && ! meta.local;
					}
					if ( 'delete' === type ) {
						return meta.cloud;
					}
					return false;
				} );
			}

			function setBatchButtonsDisabled( disabled ) {
				// Only the three actions that touch the bucket follow the busy flag
				// and the frozen state. "清除选择" is local bookkeeping, so it stays
				// clickable even while a screen is frozen.
				var frozen = isLocked();
				[ batchUpload, batchDownload, batchDeleteBtn ].forEach( function ( button ) {
					if ( button ) {
						button.disabled = disabled || frozen;
					}
				} );
				if ( batchClear ) {
					batchClear.disabled = false;
				}
			}

			function updateBatchBar() {
				if ( ! batchBar ) {
					return;
				}
				var count = selectedCount();
				batchBar.hidden = 0 === count;
				if ( batchCount ) {
					batchCount.textContent = String( count );
				}
				admin.qsa( 'input.row-select', tbody ).forEach( function ( box ) {
					var row = box.closest( 'tr' );
					if ( row ) {
						row.classList.toggle( 'is-selected', !! selection[ box.value ] );
					}
				} );
				if ( selectAll && ! batchBusy ) {
					var boxes = admin.qsa( 'input.row-select', tbody );
					selectAll.checked = boxes.length > 0 && count >= boxes.length;
					selectAll.indeterminate = count > 0 && count < boxes.length;
				}
			}

			function clearSelection() {
				selection = {};
				admin.qsa( 'input.row-select', tbody ).forEach( function ( box ) {
					box.checked = false;
				} );
				if ( selectAll ) {
					selectAll.checked = false;
					selectAll.indeterminate = false;
				}
				updateBatchBar();
			}

			function addSelection( box ) {
				if ( selectedCount() >= MAX_SELECT && ! selection[ box.value ] ) {
					box.checked = false;
					toast( strings.batchLimit, 'warn' );
					return false;
				}
				selection[ box.value ] = {
					status: box.getAttribute( 'data-status' ) || '',
					local: '1' === box.getAttribute( 'data-local' ),
					cloud: '1' === box.getAttribute( 'data-cloud' )
				};
				return true;
			}

			function syncRowSelection( box ) {
				if ( box.checked ) {
					addSelection( box );
				} else {
					delete selection[ box.value ];
				}
				updateBatchBar();
			}

			admin.qsa( 'input.row-select', tbody ).forEach( function ( box ) {
				box.checked = false;
			} );

			admin.delegate( tbody, 'change', 'input.row-select', function ( event, box ) {
				syncRowSelection( box );
			} );

			if ( selectAll ) {
				admin.on( selectAll, 'change', function () {
					var boxes = admin.qsa( 'input.row-select', tbody );
					if ( selectAll.checked ) {
						var overflow = false;
						boxes.forEach( function ( box ) {
							if ( ! box.checked ) {
								box.checked = true;
								if ( ! addSelection( box ) ) {
									overflow = true;
								}
							}
						} );
						if ( overflow ) {
							toast( strings.batchLimit, 'warn' );
						}
					} else {
						boxes.forEach( function ( box ) {
							box.checked = false;
							delete selection[ box.value ];
						} );
					}
					updateBatchBar();
				} );
			}

			if ( batchClear ) {
				admin.on( batchClear, 'click', clearSelection );
			}

			/* Progress helpers. */

			function setBatchProgress( done, total, text ) {
				if ( ! batchProgress ) {
					return;
				}
				batchProgress.hidden = false;
				var percent = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
				if ( progressText ) {
					progressText.textContent = ( text || '' ) + ' ' + admin.format.number( done ) + ' / ' + admin.format.number( total );
				}
				if ( progressPct ) {
					progressPct.textContent = percent + '%';
				}
				if ( progressFill ) {
					progressFill.style.width = percent + '%';
				}
				if ( progressTrack ) {
					progressTrack.setAttribute( 'aria-valuenow', String( percent ) );
				}
			}

			function startBatch() {
				batchBusy = true;
				setBatchButtonsDisabled( true );
				if ( batchProgress ) {
					batchProgress.hidden = false;
					setBatchProgress( 0, 1, strings.batchWorking );
				}
			}

			function endBatch() {
				batchBusy = false;
				setBatchButtonsDisabled( false );
			}

			function finishBatch( message, tone ) {
				batchBusy = false;
				setBatchButtonsDisabled( false );
				if ( batchProgress ) {
					var now = Number( progressTrack && progressTrack.getAttribute( 'aria-valuenow' ) ) || 100;
					if ( progressFill ) {
						progressFill.style.width = '100%';
					}
					if ( progressPct ) {
						progressPct.textContent = '100%';
					}
					if ( progressText ) {
						progressText.textContent = message || strings.actionDone;
					}
					progressTrack && progressTrack.setAttribute( 'aria-valuenow', String( Math.max( 100, now ) ) );
				}
				window.setTimeout( function () {
					reloadWithToast( message || strings.actionDone, tone || 'ok' );
				}, 500 );
			}

			admin.on( window, 'beforeunload', function ( event ) {
				if ( ! batchBusy ) {
					return undefined;
				}
				var warning = strings.batchLeaveWarning || '';
				event.preventDefault();
				event.returnValue = warning;
				return warning;
			} );

			/* Row actions. */

			function runRowAction( type, key, confirmValue, button ) {
				actionBusy = true;
				var restore = admin.ui.busy( button, true );
				var payload = { sync_action: type, object_key: key, confirm: confirmValue || '', nonce: config.actionNonce };
				admin.api.ajax( 'sakura_s3_sync_action', payload, { timeout: 120000 } ).then( function ( data ) {
					actionBusy = false;
					if ( data && data.queued ) {
						toast( data.message || strings.queued, 'info' );
						window.setTimeout( function () {
							window.location.reload();
						}, 1600 );
						return;
					}
					reloadWithToast( ( data && data.message ) || strings.actionDone, 'ok' );
				} ).catch( function ( error ) {
					actionBusy = false;
					restore();
					toast( errorText( error, strings.unknownError ), 'bad' );
				} );
			}

			admin.delegate( tbody, 'click', 'button[data-sakura-action]', function ( event, button ) {
				var action = button.getAttribute( 'data-sakura-action' ) || '';
				if ( 0 !== action.indexOf( 'row-' ) || actionBusy ) {
					return;
				}
				if ( isLocked() || button.disabled ) {
					event.preventDefault();
					return;
				}
				event.preventDefault();
				var type = action.slice( 4 );
				var key  = button.getAttribute( 'data-sakura-key' ) || '';
				if ( ! key ) {
					return;
				}
				if ( 'delete' === type ) {
					var name = button.getAttribute( 'data-sakura-name' ) || '';
					var size = button.getAttribute( 'data-sakura-size' ) || '';
					var detail = name ? name + ( size ? ' · ' + size : '' ) : '';
					/* 与批量删除同构：一句结论 + 对象清单 + 粉底说明。 */
					admin.ui.confirm( name && strings.deleteHead ? format( strings.deleteHead, name ) : strings.deleteDesc, {
						title: name && strings.deleteTitleShort ? strings.deleteTitleShort : strings.deleteTitle,
						note: strings.deleteNote || '',
						items: detail ? [ detail ] : [],
						confirmText: strings.deleteConfirm,
						danger: true
					} ).then( function ( confirmed ) {
						if ( confirmed ) {
							runRowAction( 'delete', key, 'DELETE', button );
						}
					} );
					return;
				}
				if ( 'REPLACE' === button.getAttribute( 'data-sakura-confirm' ) ) {
					admin.ui.confirm( strings.replaceDesc, {
						title: strings.replaceTitle,
						confirmText: strings.replaceConfirm,
						danger: true
					} ).then( function ( confirmed ) {
						if ( confirmed ) {
							runRowAction( type, key, 'REPLACE', button );
						}
					} );
					return;
				}
				runRowAction( type, key, '', button );
			} );

			admin.delegate( tbody, 'click', '[data-sakura-preview]', function ( event, button ) {
				event.preventDefault();
				var preview = {};
				try {
					preview = JSON.parse( button.getAttribute( 'data-sakura-preview' ) || '{}' );
				} catch ( error ) {
					preview = {};
				}
				if ( ! preview.name ) {
					preview.name = button.getAttribute( 'data-sakura-name' ) || '';
				}

				/*
				 * Rows that come from the fast list endpoint carry no preview
				 * URL: that endpoint runs under SHORTINIT, where the URL and
				 * nonce helpers do not exist, and signing every row would make
				 * the list slower than the dialog. Ask for the one row the
				 * visitor actually opened instead.
				 */
				if ( ! preview.download && ! preview.url ) {
					var row = button.closest( 'tr' );
					var rowKey = row ? row.getAttribute( 'data-key' ) : '';
					if ( ! rowKey ) {
						rowKey = button.getAttribute( 'data-sakura-key' ) || '';
					}
					if ( rowKey ) {
						loadPreview( rowKey, button, preview );
						return;
					}
				}

				openPreview( preview, button );
			} );

			var previewCache = {};

			function openPreview( preview, button ) {
				if ( admin.ui && 'function' === typeof admin.ui.mediaPreview ) {
					admin.ui.mediaPreview( preview, { trigger: button } );
					return;
				}
				toast( strings.unknownError, 'bad' );
			}

			function loadPreview( key, button, fallback ) {
				if ( previewCache[ key ] ) {
					openPreview( previewCache[ key ], button );
					return;
				}

				var restore = admin.ui && admin.ui.busy ? admin.ui.busy( button, true ) : function () {};

				admin.api.ajax(
					'sakura_s3_sync_preview',
					{ object_key: key, nonce: config.previewNonce },
					{ timeout: 25000 }
				).then( function ( data ) {
					restore();
					var payload = data && data.preview ? data.preview : null;
					if ( ! payload || ! payload.name ) {
						openPreview( fallback || {}, button );
						return;
					}
					previewCache[ key ] = payload;
					openPreview( payload, button );
				} ).catch( function ( error ) {
					restore();
					toast( errorText( error, strings.previewError ), 'bad' );
				} );
			}

			/* Batches. */

			function batchTransfer( type, keys, confirmValue ) {
				if ( ! keys.length ) {
					toast( strings.batchEmpty, 'warn' );
					return;
				}
				startBatch();
				var total    = keys.length;
				var offset   = 0;
				var rounds   = 0;
				var message  = '';
				var failures = false;

				function step() {
					rounds += 1;
					var payload = {
						sync_action: type,
						keys: keys,
						offset: offset,
						seconds: 20,
						nonce: config.actionNonce
					};
					if ( confirmValue ) {
						payload.confirm = confirmValue;
					}
					admin.api.ajax( 'sakura_s3_sync_action', payload, { timeout: 180000 } ).then( function ( data ) {
						var processed = Number( data.offset || 0 );
						if ( ! processed ) {
							processed = Number( data.uploaded || data.downloaded || 0 ) + Number( data.skipped || 0 ) + Number( data.failed || 0 );
						}
						if ( processed > offset ) {
							offset = processed;
						}
						message = data.message || message;
						failures = failures || Number( data.failed || 0 ) > 0;
						setBatchProgress( Math.min( offset || total, total ), total, strings.batchWorking );
						if ( data.done || offset >= total || rounds > 200 ) {
							finishBatch( message || strings.actionDone, failures ? 'warn' : 'ok' );
							return;
						}
						window.setTimeout( step, 100 );
					} ).catch( function ( error ) {
						endBatch();
						toast( errorText( error, strings.unknownError ), 'bad' );
					} );
				}

				step();
			}

			function batchDelete( keys ) {
				if ( ! keys.length ) {
					toast( strings.batchEmpty, 'warn' );
					return;
				}
				startBatch();
				setBatchProgress( 0, keys.length, strings.batchWorking );
				admin.api.ajax( 'sakura_s3_sync_action', {
					sync_action: 'delete',
					keys: keys,
					confirm: 'DELETE',
					nonce: config.actionNonce
				}, { timeout: 60000 } ).then( function ( data ) {
					setBatchProgress( keys.length, keys.length, strings.batchWorking );
					finishBatch( ( data && data.message ) || strings.actionDone, 'ok' );
				} ).catch( function ( error ) {
					endBatch();
					toast( errorText( error, strings.unknownError ), 'bad' );
				} );
			}

			if ( batchUpload ) {
				admin.on( batchUpload, 'click', function () {
					var keys = selectedKeys( 'upload' );
					if ( ! keys.length ) {
						toast( strings.batchEmpty, 'warn' );
						return;
					}
					var replace = keys.filter( function ( key ) {
						return selection[ key ] && selection[ key ].cloud;
					} ).length;
					if ( replace > 0 ) {
						admin.ui.confirm( format( strings.batchReplace, replace ), {
							title: strings.replaceTitle,
							confirmText: strings.replaceConfirm,
							danger: true
						} ).then( function ( confirmed ) {
							if ( confirmed ) {
								batchTransfer( 'upload', keys, 'REPLACE' );
							}
						} );
						return;
					}
					batchTransfer( 'upload', keys, '' );
				} );
			}

			if ( batchDownload ) {
				admin.on( batchDownload, 'click', function () {
					batchTransfer( 'download', selectedKeys( 'download' ), '' );
				} );
			}

			if ( batchDeleteBtn ) {
				admin.on( batchDeleteBtn, 'click', function () {
					var keys = selectedKeys( 'delete' );
					if ( ! keys.length ) {
						toast( strings.batchEmpty, 'warn' );
						return;
					}
					/* 按视觉稿：一句话结论 + 文件清单 + 底部粉底说明，三段分开读。 */
					var sizeByKey = {};
					Array.prototype.forEach.call( document.querySelectorAll( '.row-select' ), function ( box ) {
						sizeByKey[ box.value ] = box.getAttribute( 'data-size' ) || '';
					} );
					var listed = keys.slice( 0, 5 ).map( function ( key ) {
						var size = sizeByKey[ key ] || '';
						return String( key ).split( '/' ).pop() + ( size ? ' · ' + size : '' );
					} );
					if ( keys.length > listed.length && strings.batchDeleteMore ) {
						listed.push( format( strings.batchDeleteMore, keys.length - listed.length ) );
					}
					admin.ui.confirm( format( strings.batchDeleteHead || strings.batchDeleteDesc, keys.length ), {
						title: strings.batchDeleteTitle,
						note: strings.batchDeleteNote || '',
						items: listed,
						confirmText: format( strings.batchDeleteConfirm, keys.length ),
						danger: true
					} ).then( function ( confirmed ) {
						if ( confirmed ) {
							batchDelete( keys );
						}
					} );
				} );
			}

			if ( adoptButton ) {
				admin.on( adoptButton, 'click', function () {
					if ( adoptBusy || adoptButton.disabled ) {
						return;
					}
					adoptBusy = true;
					var restore = admin.ui.busy( adoptButton, true );
					var total   = 0;
					var adopted = 0;
					if ( adoptText ) {
						adoptText.hidden = false;
						adoptText.textContent = strings.adoptRunning || '';
					}

					function stepAdopt() {
						admin.api.ajax( 'sakura_s3_sync_action', {
							sync_action: 'adopt',
							seconds: 20,
							nonce: config.actionNonce
						}, { timeout: 180000 } ).then( function ( data ) {
							total = Number( data.total || total ) || total;
							adopted = Number( data.adopted || 0 ) || adopted;
							if ( adoptText ) {
								adoptText.textContent = ( strings.adoptRunning || '' ) + ' ' + admin.format.number( adopted ) + ' / ' + admin.format.number( total );
							}
							if ( data.done || Number( data.remaining || 0 ) <= 0 ) {
								reloadWithToast( data.message || strings.adoptDone, 'ok' );
								return;
							}
							window.setTimeout( stepAdopt, 100 );
						} ).catch( function ( error ) {
							adoptBusy = false;
							restore();
							if ( adoptText ) {
								adoptText.hidden = true;
							}
							toast( errorText( error, strings.unknownError ), 'bad' );
						} );
					}

					stepAdopt();
				} );
			}

			/* Filters, search and paging. */

			function isFiltered() {
				return 'all' !== filter ||
					!! ( searchInput && String( searchInput.value || '' ).trim() ) ||
					!! ( typeSelect && 'all' !== typeSelect.value ) ||
					!! ( dateSelect && 'all' !== dateSelect.value );
			}

			function filteredTotal() {
				return isFiltered() ? matchedRows : totalRows;
			}

			function updateSummary( visible ) {
				if ( ! summaryEl ) {
					return;
				}
				var total = filteredTotal();
				var start = visible === 0 ? 0 : ( pageNo - 1 ) * pageSize + 1;
				var end   = Math.min( ( pageNo - 1 ) * pageSize + visible, total );
				// The filtered template's last number is the size of the whole
				// index ("筛选自 130 项"), not the number of matches.
				var template = isFiltered() ? ( strings.summaryFiltered || strings.summary ) : strings.summary;
				var base     = isFiltered() ? totalRows : total;
				summaryEl.textContent = format( template, admin.format.number( start ), admin.format.number( end ), admin.format.number( base ) );
			}

			function renderPager() {
				if ( ! pager ) {
					return;
				}
				while ( pager.firstChild ) {
					pager.removeChild( pager.firstChild );
				}
				var total = filteredTotal();
				var pages = Math.max( 1, Math.ceil( total / pageSize ) );

				var prev = document.createElement( 'button' );
				prev.type = 'button';
				prev.className = 'pagination__link';
				prev.textContent = '‹';
				prev.disabled = pageNo <= 1;
				admin.on( prev, 'click', function () {
					if ( pageNo <= 1 ) {
						return;
					}
					var back = history.length > 1 ? history.pop() : '';
					loadPage( back || '', pageNo - 1 );
				} );
				pager.appendChild( prev );

				var now = document.createElement( 'span' );
				now.className = 'pagination__link is-current';
				now.textContent = pageNo + ' / ' + pages;
				pager.appendChild( now );

				var next = document.createElement( 'button' );
				next.type = 'button';
				next.className = 'pagination__link';
				next.textContent = '›';
				next.disabled = ! hasMore;
				admin.on( next, 'click', function () {
					if ( ! hasMore ) {
						return;
					}
					if ( history[ history.length - 1 ] !== cursor ) {
						history.push( cursor );
					}
					loadPage( cursor, pageNo + 1 );
				} );
				pager.appendChild( next );
			}

			function updateCounts( next ) {
				Object.keys( next ).forEach( function ( key ) {
					counts[ key ] = parseInt( next[ key ], 10 ) || 0;
				} );
				filterChips.forEach( function ( chip ) {
					var value = chip.getAttribute( 'data-sakura-filter-value' );
					var countEl = admin.qs( '.filter-chip__count', chip );
					if ( value && countEl && counts[ value ] !== undefined ) {
						countEl.textContent = admin.format.number( counts[ value ] );
					}
				} );
			}

			function queryData( cursorValue ) {
				return {
					status: filter,
					search: searchInput ? String( searchInput.value || '' ).trim() : '',
					type: typeSelect ? typeSelect.value : 'all',
					days: dateSelect ? dateSelect.value : 'all',
					cursor: cursorValue || '',
					limit: pageSize
				};
			}

			function fastPage( data ) {
				var token  = config.searchToken || {};
				var params = { u: token.u || '', e: token.e || '', p: token.p || '', s: token.s || '' };
				Object.keys( data ).forEach( function ( key ) {
					params[ key ] = data[ key ] === undefined || data[ key ] === null ? '' : String( data[ key ] );
				} );
				return admin.api.get( config.searchUrl, params, { timeout: 25000 } ).then( function ( payload ) {
					if ( ! payload || ! payload.success || ! payload.data ) {
						throw new Error( ( payload && payload.data && payload.data.message ) || strings.pageError || '' );
					}
					return payload.data;
				} );
			}

			function needsFallback( payload, data ) {
				if ( ! payload || '[object Array]' !== Object.prototype.toString.call( payload.rows ) ) {
					return true;
				}
				return 0 === payload.rows.length && ! data.cursor;
			}

			function ajaxPage( data ) {
				return admin.api.ajax( 'sakura_s3_sync_page', {
					status: data.status,
					search: data.search,
					type: data.type,
					days: data.days,
					cursor: data.cursor,
					limit: data.limit,
					nonce: config.pageNonce
				}, { timeout: 30000 } );
			}

			function loadPage( nextCursor, nextPage ) {
				var seq  = ++requestSeq;
				var data = queryData( nextCursor );
				tbody.classList.add( 'is-loading' );
				var loader;
				if ( config.searchUrl && config.searchToken ) {
					loader = fastPage( data ).then( function ( payload ) {
						if ( needsFallback( payload, data ) ) {
							return ajaxPage( data );
						}
						return payload;
					} ).catch( function () {
						return ajaxPage( data );
					} );
				} else {
					loader = ajaxPage( data );
				}
				loader.then( function ( payload ) {
					if ( seq !== requestSeq ) {
						return;
					}
					tbody.classList.remove( 'is-loading' );
					pageNo  = nextPage;
					cursor  = payload.cursor || '';
					hasMore = !! payload.has_more;
					if ( payload.total !== undefined ) {
						totalRows = parseInt( payload.total, 10 ) || 0;
					}
					// Only the first page of a filtered list carries a count; later
					// pages keep the value that was measured when the list started.
					if ( payload.filtered_total !== undefined && payload.filtered_total !== null ) {
						matchedRows = parseInt( payload.filtered_total, 10 ) || 0;
					} else if ( ! isFiltered() ) {
						matchedRows = totalRows;
					}
					if ( payload.counts && 'object' === typeof payload.counts ) {
						updateCounts( payload.counts );
					}
					applyRows( payload.rows || [] );
				} ).catch( function ( error ) {
					if ( seq !== requestSeq ) {
						return;
					}
					tbody.classList.remove( 'is-loading' );
					toast( errorText( error, strings.pageError ), 'bad' );
				} );
			}

			function extensionOf( name ) {
				var text = String( name || '' );
				var dot = text.lastIndexOf( '.' );
				return dot >= 0 ? text.slice( dot + 1 ) : '';
			}

			function textCell( value ) {
				var cell = document.createElement( 'td' );
				cell.textContent = value;
				return cell;
			}

			function locSpan( present, warn ) {
				var span = document.createElement( 'span' );
				span.className = 'loc ' + ( present ? ( warn ? 'loc--warn' : 'loc--yes' ) : 'loc--no' );
				var dot = document.createElement( 'span' );
				dot.className = 'loc__dot';
				dot.setAttribute( 'aria-hidden', 'true' );
				span.appendChild( dot );
				span.appendChild( document.createTextNode( present ? ( warn ? ( labels.diffsize || '' ) : ( labels.exists || '' ) ) : ( labels.missing || '' ) ) );
				return span;
			}

			function actionButton( action, key, confirmValue, text ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'link-btn';
				button.setAttribute( 'data-sakura-action', action );
				button.setAttribute( 'data-sakura-key', key );
				if ( confirmValue ) {
					button.setAttribute( 'data-sakura-confirm', confirmValue );
				}
				button.textContent = text || '';
				// Rows are rebuilt on every page of results, so the markup the server
				// rendered no longer applies here; re-apply the frozen state.
				return applyFrozen( button );
			}

			function makeRow( row ) {
				var status    = row.status || 'synced';
				var name      = row.name || '';
				var key       = row.key || '';
				var path      = row.path || key;
				var hasLocal  = !! row.local;
				var hasCloud  = !! row.cloud;
				var cloudSize = Number( row.cloud_size || 0 );
				var mtime     = Number( row.mtime || 0 );

				var tr = document.createElement( 'tr' );
				tr.setAttribute( 'data-group', status );
				tr.setAttribute( 'data-type', row.type || 'other' );
				tr.setAttribute( 'data-key', key );
				tr.setAttribute( 'data-mtime', String( mtime ) );

				var selectCell = document.createElement( 'td' );
				selectCell.className = 'cell-select';
				var box = document.createElement( 'input' );
				box.type = 'checkbox';
				box.className = 'row-select';
				box.value = key;
				box.setAttribute( 'data-status', status );
				box.setAttribute( 'data-local', hasLocal ? '1' : '0' );
				box.setAttribute( 'data-cloud', hasCloud ? '1' : '0' );
				box.setAttribute( 'aria-label', name );
				selectCell.appendChild( box );
				tr.appendChild( selectCell );

				var fileCell = document.createElement( 'td' );
				var file = document.createElement( 'div' );
				file.className = 'file-cell';
				var nameEl = document.createElement( 'span' );
				nameEl.className = 'file-cell__name';
				nameEl.textContent = name;
				var pathEl = document.createElement( 'span' );
				pathEl.className = 'file-cell__path';
				pathEl.textContent = path;
				file.appendChild( nameEl );
				file.appendChild( pathEl );
				fileCell.appendChild( file );
				tr.appendChild( fileCell );

				var typeCell = document.createElement( 'td' );
				var pill = document.createElement( 'span' );
				pill.className = 'type-pill';
				pill.textContent = extensionOf( name ).toUpperCase() || '—';
				typeCell.appendChild( pill );
				tr.appendChild( typeCell );

				tr.appendChild( textCell( hasLocal ? formatSize( row.size || 0 ) : '—' ) );

				var localCell = document.createElement( 'td' );
				localCell.appendChild( locSpan( hasLocal, false ) );
				tr.appendChild( localCell );

				var cloudCell = document.createElement( 'td' );
				cloudCell.appendChild( locSpan( hasCloud, 'mismatch' === status ) );
				tr.appendChild( cloudCell );

				var statusCell = document.createElement( 'td' );
				var badge = document.createElement( 'span' );
				badge.className = 'badge badge--' + badgeTone( status );
				badge.textContent = statusLabels[ status ] || status;
				statusCell.appendChild( badge );
				tr.appendChild( statusCell );

				tr.appendChild( textCell( formatDate( mtime ) ) );

				var actions = document.createElement( 'div' );
				actions.className = 'action-cell';
				if ( 'failed' === status ) {
					if ( hasLocal ) {
						actions.appendChild( actionButton( 'row-upload', key, hasCloud ? 'REPLACE' : '', labels.reupload || labels.upload ) );
					} else {
						actions.appendChild( actionButton( 'row-download', key, '', labels.download ) );
					}
				} else if ( 'pending' === status ) {
					actions.appendChild( actionButton( 'row-upload', key, '', labels.upload ) );
				} else if ( 'remote' === status ) {
					actions.appendChild( actionButton( 'row-download', key, '', labels.download ) );
				} else if ( 'mismatch' === status ) {
					actions.appendChild( actionButton( 'row-upload', key, 'REPLACE', labels.reupload || labels.upload ) );
				}
				if ( hasCloud ) {
					var remove = actionButton( 'row-delete', key, '', labels.delete );
					remove.classList.add( 'link-btn--danger' );
					remove.setAttribute( 'data-sakura-name', name );
					remove.setAttribute( 'data-sakura-size', formatSize( cloudSize ) );
					actions.appendChild( remove );
				}
				var preview = row.preview || {};
				if ( ! preview.name ) {
					preview = {
						name: name,
						path: path,
						kind: row.type || 'other',
						status: status,
						size: hasLocal ? Number( row.size || 0 ) : cloudSize,
						url: '',
						download: ''
					};
				}
				var detail = document.createElement( 'button' );
				detail.type = 'button';
				detail.className = 'link-btn';
				detail.setAttribute( 'data-sakura-preview', JSON.stringify( preview ) );
				detail.setAttribute( 'data-sakura-name', name );
				detail.textContent = labels.detail || '';
				actions.appendChild( detail );

				var actionCell = document.createElement( 'td' );
				actionCell.appendChild( actions );
				tr.appendChild( actionCell );

				return tr;
			}

			function applyRows( rows ) {
				admin.qsa( 'tr', tbody ).forEach( function ( tr ) {
					if ( tr !== emptyRow ) {
						tbody.removeChild( tr );
					}
				} );
				rows.forEach( function ( row ) {
					if ( emptyRow ) {
						tbody.insertBefore( makeRow( row ), emptyRow );
					} else {
						tbody.appendChild( makeRow( row ) );
					}
				} );
				if ( emptyRow ) {
					emptyRow.hidden = rows.length > 0;
				}
				clearSelection();
				updateSummary( rows.length );
				renderPager();
				if ( table ) {
					admin.ui.labelTables( table );
				}
				/* The table can drop from seven rows to one (filters, search,
				 * page size, paging). The cards sit on an absolute pixel canvas, so
				 * their measured height has to be taken again or the card keeps the
				 * old height and the next card either floats far below or is
				 * covered. */
				notifyLayout();
			}

			function setFilter( value ) {
				filter = value;
				filterChips.forEach( function ( chip ) {
					var active = chip.getAttribute( 'data-sakura-filter-value' ) === value;
					chip.classList.toggle( 'is-active', active );
					chip.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				} );
				history = [ '' ];
				loadPage( '', 1 );
			}

			filterChips.forEach( function ( chip ) {
				admin.on( chip, 'click', function () {
					setFilter( chip.getAttribute( 'data-sakura-filter-value' ) || 'all' );
				} );
			} );

			if ( searchInput ) {
				admin.on( searchInput, 'input', admin.debounce( function () {
					history = [ '' ];
					loadPage( '', 1 );
				}, 280 ) );
			}

			if ( typeSelect ) {
				admin.on( typeSelect, 'change', function () {
					history = [ '' ];
					loadPage( '', 1 );
				} );
			}

			if ( dateSelect ) {
				admin.on( dateSelect, 'change', function () {
					history = [ '' ];
					loadPage( '', 1 );
				} );
			}

			sizeButtons.forEach( function ( button ) {
				admin.on( button, 'click', function () {
					var value = parseInt( button.getAttribute( 'data-recon-size' ) || '', 10 );
					if ( ! value ) {
						return;
					}
					pageSize = value;
					sizeButtons.forEach( function ( other ) {
						other.classList.toggle( 'is-current', other === button );
					} );
					history = [ '' ];
					loadPage( '', 1 );
				} );
			} );

			/* Export links keep the active filters. */

			function exportQuery() {
				return {
					status: filter,
					search: searchInput ? String( searchInput.value || '' ).trim() : '',
					type: typeSelect ? typeSelect.value : 'all',
					days: dateSelect ? dateSelect.value : 'all'
				};
			}

			admin.qsa( '[data-sakura-role="export"]', root ).forEach( function ( link ) {
				admin.on( link, 'click', function () {
					var url;
					try {
						url = new URL( link.getAttribute( 'href' ), window.location.href );
					} catch ( e ) {
						return;
					}
					var query = exportQuery();
					url.searchParams.set( 'status', query.status );
					url.searchParams.set( 'search', query.search );
					url.searchParams.set( 'type', query.type );
					url.searchParams.set( 'days', query.days );
					link.href = url.toString();
				} );
			} );

			updateSummary( admin.qsa( 'input.row-select', tbody ).length );
			renderPager();
			updateBatchBar();
		}

		/* -----------------------------------------------------------------
		 * Sync log list: client-side level filter and progressive loading.
		 * ----------------------------------------------------------------- */

		function initLogs() {
			var data = config.logs || [];
			if ( ! data || ! data.length ) {
				return;
			}
			var list = admin.qs( '#log-list', root );
			if ( ! list ) {
				return;
			}
			var moreButton = admin.qs( '#sakura-logs-more', root );
			var moreWrap   = moreButton ? admin.closest( moreButton, '.log-more' ) : null;
			var chips      = admin.qsa( '[data-log-level]', root );
			var level      = 'all';
			var visible    = 6;

			function filtered() {
				return data.filter( function ( item ) {
					return 'all' === level || item.level === level;
				} );
			}

			function render() {
				var rows = filtered();
				while ( list.firstChild ) {
					list.removeChild( list.firstChild );
				}
				if ( ! rows.length ) {
					var empty = document.createElement( 'div' );
					empty.className = 'empty-state';
					var title = document.createElement( 'strong' );
					title.className = 'empty-state__title';
					title.textContent = strings.logsEmpty || '';
					empty.appendChild( title );
					list.appendChild( empty );
					if ( moreWrap ) {
						moreWrap.hidden = true;
					}
					return;
				}

				var limit = 'all' === level ? visible : rows.length;
				rows.slice( 0, limit ).forEach( function ( item ) {
					var row = document.createElement( 'div' );
					row.className = 'log-item';
					row.setAttribute( 'data-level', item.level || 'info' );

					var time = document.createElement( 'span' );
					time.className = 'log-item__time';
					time.textContent = item.time || '';

					var body = document.createElement( 'div' );
					body.className = 'log-item__body';

					var title = document.createElement( 'div' );
					title.className = 'log-item__title';
					title.appendChild( document.createTextNode( ( item.message || '' ) + ' ' ) );
					var badge = document.createElement( 'span' );
					badge.className = 'badge badge--' + ( item.badge || 'info' );
					badge.textContent = item.label || '';
					title.appendChild( badge );

					var meta = document.createElement( 'div' );
					meta.className = 'log-item__meta';
					meta.textContent = item.scan || '';

					body.appendChild( title );
					body.appendChild( meta );
					row.appendChild( time );
					row.appendChild( body );
					list.appendChild( row );
				} );

				if ( ! moreWrap ) {
					return;
				}
				var remaining = rows.length - Math.min( rows.length, limit );
				if ( 'all' !== level || remaining <= 0 ) {
					moreWrap.hidden = true;
					return;
				}
				moreWrap.hidden = false;
				if ( moreButton ) {
					moreButton.textContent = format( strings.logsMore || strings.logsNoMore, remaining );
				}
			}

			chips.forEach( function ( chip ) {
				admin.on( chip, 'click', function () {
					level = chip.getAttribute( 'data-log-level' ) || 'all';
					visible = 6;
					chips.forEach( function ( other ) {
						other.classList.toggle( 'is-active', other === chip );
					} );
					render();
					/* Showing every entry even when the level filter is off grows
					 * the card by a row or two: re-measure or the card ends below
					 * the canvas the footer is positioned against. */
					notifyLayout();
				} );
			} );

			if ( moreButton ) {
				admin.on( moreButton, 'click', function () {
					visible += 6;
					render();
					notifyLayout();
				} );
			}

			render();
		}
	} );

	/*
	 * Bucket latency pill.
	 *
	 * One signed LIST per probe, so a few requests a minute are cheap. The three
	 * bands are a product decision: under 200ms is healthy, 200–500ms still
	 * usable but worth knowing, over 500ms means every page load is waiting on
	 * the object store. Polling stops while the tab is hidden — a backgrounded
	 * dashboard should not spend requests on nobody.
	 */
	admin.ready( function () {
		var pill = document.querySelector( '[data-sakura-ping]' );
		if ( ! pill ) {
			return;
		}

		var config   = admin.page && admin.page.data ? admin.page.data : {};
		var strings  = config.strings || {};
		var interval = Number( config.latencyMs ) || 30000;
		var timer    = 0;
		var busy     = false;
		var samples  = [];

		/* One slow handshake should not repaint the dot red: judge on the median
		 * of the last three probes, show the number that produced it. */
		function median( list ) {
			var sorted = list.slice().sort( function ( a, b ) {
				return a - b;
			} );
			return sorted[ Math.floor( sorted.length / 2 ) ];
		}

		function paint( state, ms, text ) {
			pill.setAttribute( 'data-state', state );
			var word = pill.querySelector( '.status-card__ping-text' );
			var value = pill.querySelector( '[data-sakura-ping-ms]' );
			if ( word ) {
				word.textContent = text || '';
			}
			if ( value ) {
				value.textContent = ( typeof ms === 'number' && ms >= 0 ) ? ms + ' ms' : '';
			}
			pill.setAttribute( 'title', ( strings.latencyHint || '' ) + ( typeof ms === 'number' ? ' · ' + ms + ' ms' : '' ) );
		}

		function stateFor( ms ) {
			if ( ms < 200 ) {
				return 'good';
			}
			return ms <= 500 ? 'slow' : 'bad';
		}

		function words( state ) {
			if ( state === 'good' ) {
				return strings.latencyOk || '';
			}
			if ( state === 'slow' ) {
				return strings.latencySlow || '';
			}
			return strings.latencyBad || '';
		}

		function probe() {
			if ( busy ) {
				return;
			}
			busy = true;
			admin.api.ajax( 'sakura_s3_latency', { nonce: config.actionNonce || '' }, { timeout: 12000 } ).then( function ( data ) {
				busy = false;
				var ms = data && typeof data.ms === 'number' ? data.ms : null;
				if ( ms === null ) {
					paint( 'unknown', null, strings.latencyFail || '' );
					return;
				}
				samples.push( ms );
				if ( samples.length > 3 ) {
					samples.shift();
				}
				var shown = median( samples );
				paint( stateFor( shown ), shown, words( stateFor( shown ) ) );
			} ).catch( function () {
				busy = false;
				paint( 'unknown', null, strings.latencyFail || '' );
			} );
		}

		function start() {
			stop();
			probe();
			timer = window.setInterval( probe, interval );
		}

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = 0;
			}
		}

		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				stop();
				return;
			}
			start();
		} );

		start();
	} );
	/*
	 * 汽水气泡：每颗都是真实元素，起点、大小、时长、延迟各不相同。
	 * 负延迟让它们一上来就处在各自的相位，不会一起从零开始——
	 * 平铺渐变的版本正是死在「所有气泡同时归零」这一点上。
	 */
	admin.ready( function () {
		var bars = document.querySelectorAll( '.progress--soda' );

		if ( ! bars.length || window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return;
		}

		function sprinkle( host, count, sizeMin, sizeMax, durMin, durMax ) {
			if ( ! host || host.getAttribute( 'data-sakura-fizz' ) ) {
				return;
			}

			host.setAttribute( 'data-sakura-fizz', '1' );

			for ( var i = 0; i < count; i++ ) {
				var bubble = document.createElement( 'i' );
				var size = sizeMin + Math.random() * ( sizeMax - sizeMin );
				var duration = durMin + Math.random() * ( durMax - durMin );

				bubble.style.width = size.toFixed( 1 ) + 'px';
				bubble.style.height = size.toFixed( 1 ) + 'px';
				bubble.style.left = ( 3 + Math.random() * 92 ).toFixed( 1 ) + '%';
				bubble.style.animationDuration = duration.toFixed( 2 ) + 's';
				bubble.style.animationDelay = '-' + ( Math.random() * duration ).toFixed( 2 ) + 's';
				host.appendChild( bubble );
			}
		}

		Array.prototype.forEach.call( bars, function ( bar ) {
			sprinkle( bar.querySelector( '.progress__fizz' ), 22, 3, 8, 1.8, 3.6 );
			sprinkle( bar.querySelector( '.progress__pop' ), 7, 4, 6, 2.2, 3.8 );
		} );
	} );
}() );

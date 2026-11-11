/**
 * Batch progress controller: drives the per-batch AJAX loop, updates the
 * progress bar and counters, and exposes a retry path after a network error.
 *
 * @package Sakura_Image_Optimizer
 */
( function ( window, document ) {
	'use strict';

	var admin = window.SakuraAdmin;

	if ( ! admin ) {
		return;
	}

	function fill( template, values ) {
		return String( template || '' ).replace( /\{(\w+)\}/g, function ( match, key ) {
			return Object.prototype.hasOwnProperty.call( values, key ) ? String( values[ key ] ) : match;
		} );
	}

	function count( value ) {
		var number = parseInt( value, 10 );
		if ( isNaN( number ) || number < 0 ) {
			return 0;
		}
		try {
			return number.toLocaleString( document.documentElement.lang || undefined );
		} catch ( error ) {
			return String( number );
		}
	}

	function errorText( error, fallback ) {
		if ( ! error ) {
			return fallback;
		}
		var payload = error.payload;
		if ( typeof payload === 'string' && payload ) {
			return payload;
		}
		if ( payload && typeof payload.data === 'string' && payload.data ) {
			return payload.data;
		}
		if ( payload && payload.data && payload.data.message ) {
			return payload.data.message;
		}
		if ( payload && payload.message ) {
			return payload.message;
		}
		return error.message || fallback;
	}

	admin.ready( function () {
		var panel = document.getElementById( 'sakura-progress-wrap' );

		if ( ! panel ) {
			return;
		}

		var config = admin.data || {};
		var strings = config.strings || {};
		var total = Math.max( 0, parseInt( panel.getAttribute( 'data-total' ), 10 ) || 0 );

		if ( total < 1 ) {
			return;
		}

		var processed = Math.max( 0, parseInt( panel.getAttribute( 'data-processed' ), 10 ) || 0 );
		var success = Math.max( 0, parseInt( panel.getAttribute( 'data-success' ), 10 ) || 0 );
		var fail = Math.max( 0, parseInt( panel.getAttribute( 'data-fail' ), 10 ) || 0 );
		var skipped = Math.max( 0, parseInt( panel.getAttribute( 'data-skipped' ), 10 ) || 0 );
		var mode = panel.getAttribute( 'data-mode' ) === 'restore' ? 'restore' : 'optimize';
		var verb = mode === 'restore' ? ( strings.restore || '还原' ) : ( strings.optimize || '优化' );
		var nonce = config.nonce || '';
		var delay = Math.max( 100, parseInt( config.delay, 10 ) || 800 );

		var statusNode = document.getElementById( 'sakura-status-text' );
		var percentNode = document.getElementById( 'sakura-progress-percent' );
		var bar = document.getElementById( 'sakura-progress-bar' );
		var track = panel.querySelector( '.progress' );
		var detailNode = document.getElementById( 'sakura-progress-detail' );
		var successNode = document.getElementById( 'sakura-count-success' );
		var failNode = document.getElementById( 'sakura-count-fail' );
		var skippedNode = document.getElementById( 'sakura-count-skipped' );
		var errorBox = document.getElementById( 'sakura-progress-error' );
		var errorTextNode = document.getElementById( 'sakura-progress-error-text' );
		var retryButton = document.getElementById( 'sakura-progress-retry' );
		var doneBox = document.getElementById( 'sakura-progress-done' );
		var summaryNode = document.getElementById( 'sakura-progress-summary' );
		var doneTotal = document.getElementById( 'sakura-done-total' );
		var doneSuccess = document.getElementById( 'sakura-done-success' );
		var doneFail = document.getElementById( 'sakura-done-fail' );
		var doneSkipRow = document.getElementById( 'sakura-done-skip-row' );
		var doneSkipped = document.getElementById( 'sakura-done-skipped' );
		var doneNote = document.getElementById( 'sakura-done-note' );

		var running = false;
		var finished = false;
		var timer = 0;

		function updateDetail() {
			var parts = [ fill( strings.successCount || '成功 {count} 张', { count: count( success ) } ) ];
			parts.push( fill( strings.failCount || '失败 {count} 张', { count: count( fail ) } ) );
			if ( skipped > 0 ) {
				parts.push( fill( strings.skippedCount || '跳过 {count} 张', { count: count( skipped ) } ) );
			}
			detailNode.textContent = parts.join( ' · ' );
		}

		function paint( state ) {
			var ratio = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : 0;
			bar.style.width = ratio + '%';
			if ( track ) {
				track.setAttribute( 'aria-valuenow', String( Math.min( processed, total ) ) );
			}
			percentNode.textContent = ratio + '%';
			if ( successNode ) {
				successNode.textContent = count( success );
			}
			if ( failNode ) {
				failNode.textContent = count( fail );
			}
			if ( skippedNode ) {
				skippedNode.textContent = count( skipped );
			}
			updateDetail();
			if ( state ) {
				statusNode.textContent = state;
			}
		}

		function apply( data ) {
			processed = Math.max( processed, parseInt( data.processed, 10 ) || 0 );
			success = Math.max( 0, parseInt( data.success, 10 ) || 0 );
			fail = Math.max( 0, parseInt( data.fail, 10 ) || 0 );
			skipped = Math.max( 0, parseInt( data.skipped, 10 ) || 0 );
			paint( fill( strings.processing || '正在处理 {processed} / {total}…', {
				processed: count( processed ),
				total: count( total )
			} ) );
		}

		function finish( data ) {
			if ( finished ) {
				return;
			}
			finished = true;
			running = false;
			window.clearTimeout( timer );

			processed = Math.max( processed, parseInt( data.processed, 10 ) || total );
			success = Math.max( 0, parseInt( data.success, 10 ) || 0 );
			fail = Math.max( 0, parseInt( data.fail, 10 ) || 0 );
			skipped = Math.max( 0, parseInt( data.skipped, 10 ) || 0 );

			paint( fill( strings.doneTitle || '{verb}完成', { verb: verb } ) );
			bar.style.width = '100%';
			if ( track ) {
				track.setAttribute( 'aria-valuenow', String( total ) );
			}
			percentNode.textContent = '100%';

			if ( summaryNode ) {
				summaryNode.textContent = fill( strings.doneTitle || '{verb}完成', { verb: verb } );
			}
			if ( doneTotal ) {
				doneTotal.textContent = count( total );
			}
			if ( doneSuccess ) {
				doneSuccess.textContent = count( success );
			}
			if ( doneFail ) {
				doneFail.textContent = count( fail );
			}
			if ( doneSkipped ) {
				doneSkipped.textContent = count( skipped );
			}
			if ( doneSkipRow ) {
				doneSkipRow.hidden = skipped < 1;
			}
			if ( doneNote ) {
				if ( skipped > 0 ) {
					doneNote.textContent = fill(
						mode === 'restore'
							? ( strings.skipRestore || '其中 {count} 张位于云存储或 CDN，未还原。' )
							: ( strings.skipOptimize || '其中 {count} 张因动图、超过上限、格式不支持或体积没有变小而跳过。' ),
						{ count: count( skipped ) }
					);
					doneNote.hidden = false;
				} else {
					doneNote.hidden = true;
					doneNote.textContent = '';
				}
			}
			if ( errorBox ) {
				errorBox.hidden = true;
			}
			if ( doneBox ) {
				doneBox.hidden = false;
			}
		}

		function showError( message ) {
			running = false;
			window.clearTimeout( timer );
			statusNode.textContent = strings.errorStop || '发生错误，已停止。';
			if ( errorTextNode ) {
				errorTextNode.textContent = message;
			}
			if ( errorBox ) {
				errorBox.hidden = false;
			}
		}

		function poll() {
			if ( ! running || finished ) {
				return;
			}

			admin.api.ajax( 'sakura_process_batch', { _ajax_nonce: nonce }, { timeout: 360000 } )
				.then( function ( data ) {
					if ( ! running || finished ) {
						return;
					}
					var payload = data || {};
					apply( payload );
					if ( processed >= total ) {
						finish( payload );
						return;
					}
					timer = window.setTimeout( poll, delay );
				} )
				.catch( function ( error ) {
					if ( ! running || finished ) {
						return;
					}
					showError( errorText( error, strings.requestFailed || '请求失败，已停止。请重试。' ) );
				} )
				.finally( function () {
					if ( ! running && retryButton ) {
						admin.ui.busy( retryButton, false );
					}
				} );
		}

		function start() {
			if ( running || finished ) {
				return;
			}
			running = true;
			if ( errorBox ) {
				errorBox.hidden = true;
			}
			if ( retryButton ) {
				admin.ui.busy( retryButton, true );
			}
			poll();
		}

		if ( retryButton ) {
			retryButton.addEventListener( 'click', function () {
				if ( running || finished ) {
					return;
				}
				start();
			} );
		}

		paint( processed > 0
			? fill( strings.resume || '继续处理，已完成 {processed} / {total}…', {
				processed: count( processed ),
				total: count( total )
			} )
			: fill( strings.preparing || '准备处理 {total} 张图片…', { total: count( total ) } )
		);

		start();
	} );
}( window, document ) );

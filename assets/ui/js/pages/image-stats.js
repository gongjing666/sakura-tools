/**
 * Stats page: server-side pagination for the recent processing history.
 *
 * @package Sakura_Image_Optimizer
 */
( function ( window, document ) {
	'use strict';

	var admin = window.SakuraAdmin;

	function text( value ) {
		return String( value === undefined || value === null ? '' : value );
	}

	admin.ready( function () {
		var table = document.getElementById( 'sakura-stats-history' );
		if ( ! table ) {
			return;
		}

		var config = ( admin.page && admin.page.data && admin.page.data.stats ) || {};
		var foot = document.getElementById( 'stats-history-foot' );
		var tbody = table.querySelector( 'tbody' );
		var sizeButtons = admin.qsa( '.pagination__sizes [data-size]', foot || document );
		var prev = document.getElementById( 'stats-page-prev' );
		var next = document.getElementById( 'stats-page-next' );
		var summary = document.getElementById( 'stats-history-summary' );
		var now = document.getElementById( 'stats-page-now' );

		var total = parseInt( table.getAttribute( 'data-total' ), 10 ) || 0;
		var per = parseInt( table.getAttribute( 'data-per-page' ), 10 ) || 25;
		var page = 1;
		var pages = Math.max( 1, Math.ceil( total / per ) );
		var busy = false;

		function setActive() {
			sizeButtons.forEach( function ( button ) {
				button.classList.toggle( 'is-current', Number( button.getAttribute( 'data-size' ) ) === per );
			} );
		}

		function updateMeta() {
			pages = Math.max( 1, Math.ceil( total / per ) );
			if ( page > pages ) {
				page = pages;
			}
			if ( page < 1 ) {
				page = 1;
			}

			var start = total ? ( page - 1 ) * per + 1 : 0;
			var end = Math.min( page * per, total );
			if ( summary ) {
				summary.textContent = total
					? '显示 ' + start + '–' + end + ' 条，共 ' + total + ' 条'
					: '暂无记录';
			}
			if ( now ) {
				now.textContent = page + ' / ' + pages;
			}
			if ( prev ) {
				prev.disabled = page <= 1 || busy;
			}
			if ( next ) {
				next.disabled = page >= pages || busy;
			}
			setActive();
		}

		function emptyRow() {
			tbody.innerHTML = '<tr class="stats-empty"><td colspan="7">暂无记录。</td></tr>';
		}

		function load( nextPage, nextPer ) {
			if ( busy || ! config.action || ! config.nonce ) {
				return;
			}
			busy = true;
			table.classList.add( 'is-loading' );
			table.setAttribute( 'aria-busy', 'true' );
			if ( summary ) {
				summary.textContent = '加载中…';
			}

			admin.api.ajax( config.action, {
				nonce: config.nonce,
				page: nextPage,
				per_page: nextPer
			} ).then( function ( data ) {
				var payload = data || {};
				page = Number( payload.page ) || nextPage;
				per = Number( payload.per_page ) || nextPer;
				total = Number( payload.total ) || 0;
				pages = Number( payload.pages ) || 1;

				if ( payload.rows ) {
					tbody.innerHTML = payload.rows;
				} else {
					emptyRow();
				}
				admin.ui.labelTables( tbody );
			} ).catch( function () {
				if ( summary ) {
					summary.textContent = '加载失败，请稍后重试。';
				}
			} ).finally( function () {
				busy = false;
				table.classList.remove( 'is-loading' );
				table.removeAttribute( 'aria-busy' );
				updateMeta();
			} );
		}

		sizeButtons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var size = Number( button.getAttribute( 'data-size' ) ) || 25;
				if ( size === per && page === 1 ) {
					return;
				}
				per = size;
				page = 1;
				setActive();
				load( 1, size );
			} );
		} );

		if ( prev ) {
			prev.addEventListener( 'click', function () {
				if ( page > 1 ) {
					load( page - 1, per );
				}
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function () {
				if ( page < pages ) {
					load( page + 1, per );
				}
			} );
		}

		if ( ! total ) {
			if ( foot ) {
				foot.hidden = true;
			}
			return;
		}

		updateMeta();
	} );
}( window, document ) );

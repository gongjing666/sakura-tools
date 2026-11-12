/**
 * File space audit log controller.
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
		var list = document.getElementById( 'sufm-events' );

		if ( ! root || ! list ) {
			return;
		}

		var config = admin.data || {};
		var strings = config.strings || {};
		var base = ( admin.page.restUrl || '' ) + ( config.restPath || 'sufm/v1/' );
		var nonce = admin.page.nonce || '';

		var searchInput = document.getElementById( 'sufm-log-search' );
		var resultSelect = document.getElementById( 'sufm-log-result' );
		var refreshButton = document.getElementById( 'sufm-log-refresh' );
		var countNode = document.getElementById( 'sufm-log-count' );
		var filterEmpty = document.getElementById( 'sufm-log-filter-empty' );
		var empty = root.querySelector( '[data-empty="log"]' );
		var pagerNode = document.getElementById( 'sufm-events-pager' );

		var rows = [];

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

		function getEvents( page, per ) {
			return admin.api.get( base + 'admin/events', { page: page, per_page: per }, {
				headers: { 'X-WP-Nonce': nonce }
			} );
		}

		function resultTone( value ) {
			var text = String( value || '' ).toLowerCase();
			if ( text === 'ok' || text === 'success' || text === 'succeeded' || text === 'done' ) {
				return 'ok';
			}
			if ( text === 'failed' || text === 'error' || text === 'partial_failed' ) {
				return 'bad';
			}
			return 'neutral';
		}

		function applyFilter() {
			if ( ! rows.length ) {
				if ( countNode ) {
					countNode.textContent = '';
				}
				if ( filterEmpty ) {
					filterEmpty.hidden = true;
				}
				return;
			}

			var query = searchInput ? searchInput.value.trim().toLowerCase() : '';
			var wanted = resultSelect ? resultSelect.value : '';
			var shown = 0;
			var items = admin.qsa( '.event-list__item', list );

			items.forEach( function ( item ) {
				var haystack = ( item.getAttribute( 'data-search' ) || '' ).toLowerCase();
				var result = item.getAttribute( 'data-result' ) || '';
				var visible = ( ! query || haystack.indexOf( query ) !== -1 ) && ( ! wanted || result === wanted );
				item.hidden = ! visible;
				if ( visible ) {
					shown += 1;
				}
			} );

			if ( filterEmpty ) {
				filterEmpty.hidden = shown > 0;
			}

			if ( countNode ) {
				countNode.textContent = ( query || wanted )
					? fill( strings.filterSummary || '', { shown: shown, total: rows.length } )
					: shown + ' / ' + rows.length;
			}
		}

		function renderRows() {
			list.textContent = '';

			if ( ! rows.length ) {
				list.hidden = true;
				if ( empty ) {
					empty.hidden = false;
				}
				applyFilter();
				return;
			}

			list.hidden = false;
			if ( empty ) {
				empty.hidden = true;
			}

			rows.forEach( function ( row ) {
				var item = document.createElement( 'li' );
				item.className = 'event-list__item';
				item.setAttribute( 'data-result', row.result || '' );
				item.setAttribute( 'data-search', [ row.action || '', row.result || '', row.safe_detail || '', row.created_at || '' ].join( ' ' ) );

				item.appendChild( el( 'span', 'event-list__action', row.action || '—' ) );

				var result = el( 'span', 'event-list__result' );
				var badge = el( 'span', 'badge badge--' + resultTone( row.result ), row.result || '—' );
				result.appendChild( badge );
				item.appendChild( result );

				var detail = el( 'span', 'event-list__detail', row.safe_detail || '—' );
				if ( row.safe_detail ) {
					detail.title = row.safe_detail;
				}
				item.appendChild( detail );
				item.appendChild( el( 'span', 'event-list__time', row.created_at || '—' ) );

				list.appendChild( item );
			} );

			applyFilter();
		}

		function fillResultOptions() {
			if ( ! resultSelect ) {
				return;
			}

			var current = resultSelect.value;
			var values = [];

			rows.forEach( function ( row ) {
				var value = row.result || '';
				if ( value && values.indexOf( value ) === -1 ) {
					values.push( value );
				}
			} );

			resultSelect.textContent = '';
			var all = document.createElement( 'option' );
			all.value = '';
			all.textContent = strings.allResults || '全部结果';
			resultSelect.appendChild( all );

			values.forEach( function ( value ) {
				var option = document.createElement( 'option' );
				option.value = value;
				option.textContent = value;
				resultSelect.appendChild( option );
			} );

			resultSelect.value = values.indexOf( current ) !== -1 ? current : '';
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
			load( pager.page() );
		} );

		/* The event list grows after the AJAX response; the absolute card canvas
		 * has to be measured again or the WordPress footer sits on top of it. */
		function notifyLayout() {
			if ( admin.events ) {
				admin.events.emit( 'layout-refresh' );
			}
		}

		function load( page ) {
			list.hidden = false;
			list.textContent = '';
			var loading = el( 'li', 'event-list__empty', strings.loading || '正在读取…' );
			list.appendChild( loading );
			if ( empty ) {
				empty.hidden = true;
			}
			if ( filterEmpty ) {
				filterEmpty.hidden = true;
			}
			if ( countNode ) {
				countNode.textContent = '';
			}

			return getEvents( page || pager.page(), pager.per() ).then( function ( data ) {
				rows = Array.isArray( data.rows ) ? data.rows : [];
				fillResultOptions();
				renderRows();
				pager.set( data );
				notifyLayout();
			} ).catch( function ( error ) {
				rows = [];
				list.textContent = '';
				list.hidden = true;
				if ( empty ) {
					empty.hidden = false;
					var message = empty.querySelector( '.empty-state__message' );
					if ( message ) {
						message.textContent = errorText( error );
					}
				}
				admin.ui.toast( errorText( error ), 'bad' );
				notifyLayout();
			} );
		}

		if ( searchInput ) {
			searchInput.addEventListener( 'input', admin.debounce( applyFilter, 120 ) );
		}

		if ( resultSelect ) {
			resultSelect.addEventListener( 'change', applyFilter );
		}

		if ( refreshButton ) {
			refreshButton.addEventListener( 'click', function () {
				load( pager.page() );
			} );
		}

		load( 1 );
	} );
}( window, document ) );

/**
 * Cloud storage cost estimator controller.
 *
 * The server renders the first estimate and owns the price sheet; this script
 * only re-submits the demand form and redraws the comparison table.
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
		if ( ! root || 'storage-cost' !== root.getAttribute( 'data-sakura-page' ) ) {
			return;
		}

		var config = admin.page && admin.page.data ? admin.page.data : {};
		var strings = config.strings || {};

		var el = {
			storage: admin.qs( '#cost-storage', root ),
			traffic: admin.qs( '#cost-traffic', root ),
			read: admin.qs( '#cost-read', root ),
			write: admin.qs( '#cost-write', root ),
			image: admin.qs( '#cost-image', root ),
			hit: admin.qs( '#cost-hit', root ),
			busy: admin.qs( '#cost-busy', root ),
			https: admin.qs( '#cost-https', root ),
			mode: admin.qs( '#cost-mode', root ),
			fx: admin.qs( '#cost-fx', root ),
			run: admin.qs( '#cost-estimate', root ),
			status: admin.qs( '#cost-status', root ),
			rows: admin.qs( '#cost-rows', root ),
			meta: admin.qs( '#cost-meta', root )
		};

		if ( ! el.rows ) {
			return;
		}

		var busy = false;

		function node( tag, className, text ) {
			var item = document.createElement( tag );
			if ( className ) {
				item.className = className;
			}
			if ( undefined !== text ) {
				item.textContent = text;
			}
			return item;
		}

		function badge( label, tone ) {
			return node( 'span', 'badge badge--' + ( tone || 'neutral' ), label );
		}

		function money( value ) {
			return '¥' + admin.format.number( value, 2 );
		}

		function number( input ) {
			var value = input ? Number( input.value ) : 0;
			return isFinite( value ) ? value : 0;
		}

		function cell( label, value ) {
			var td = node( 'td' );
			td.setAttribute( 'data-label', label );
			if ( 'string' === typeof value ) {
				td.textContent = value;
			} else {
				td.appendChild( value );
			}
			return td;
		}

		function rowHtml( row ) {
			var tr = node( 'tr', 'cost-row' );
			tr.setAttribute( 'data-cost-row', row.key );

			var name = node( 'td' );
			name.setAttribute( 'data-label', '存储商' );
			var nameWrap = node( 'span', 'cost-name', row.name );
			if ( row.cheapest ) {
				nameWrap.appendChild( document.createTextNode( ' ' ) );
				nameWrap.appendChild( badge( strings.cheapest || '最低价', 'ok' ) );
			}
			if ( row.usd ) {
				nameWrap.appendChild( document.createTextNode( ' ' ) );
				nameWrap.appendChild( badge( strings.usd || '美元计价', 'neutral' ) );
			}
			name.appendChild( nameWrap );
			name.appendChild( node( 'span', 'cost-tag', row.tag || '' ) );
			tr.appendChild( name );

			tr.appendChild( cell( '存储费', money( row.storage ) ) );
			tr.appendChild( cell( '流量费', money( Number( row.egress ) + Number( row.origin ) ) ) );
			tr.appendChild( cell( '请求费', money( row.requests ) ) );
			tr.appendChild( cell( '处理费', money( row.image ) ) );
			tr.appendChild( cell( '合计 / 月', node( 'strong', 'cost-total', money( row.total ) ) ) );

			var actions = node( 'td' );
			var toggle = node( 'button', 'btn btn--quiet btn--small', '口径' );
			toggle.type = 'button';
			toggle.setAttribute( 'data-cost-toggle', row.key );
			toggle.setAttribute( 'aria-expanded', 'false' );
			toggle.setAttribute( 'aria-controls', 'cost-detail-' + row.key );
			actions.appendChild( toggle );
			tr.appendChild( actions );

			var detail = node( 'tr', 'cost-detail' );
			detail.id = 'cost-detail-' + row.key;
			detail.setAttribute( 'data-cost-detail', row.key );
			detail.hidden = true;

			var holder = node( 'td' );
			holder.colSpan = 7;
			holder.setAttribute( 'data-label', '' );
			var inner = node( 'div', 'cost-detail__inner' );

			var lines = node( 'ul', 'cost-detail__lines' );
			( row.billing || [] ).forEach( function ( line ) {
				var li = node( 'li' );
				li.appendChild( node( 'span', '', line.label ) );
				li.appendChild( document.createTextNode( ' ' + line.value ) );
				lines.appendChild( li );
			} );
			inner.appendChild( lines );

			if ( row.note ) {
				inner.appendChild( node( 'p', 'cost-detail__note', row.note ) );
			}

			var sources = node( 'p', 'cost-detail__sources' );
			if ( row.source ) {
				sources.appendChild( link( row.source, '存储定价' ) );
			}
			if ( row.cdn_source && row.cdn_source !== row.source ) {
				sources.appendChild( document.createTextNode( ' ' ) );
				sources.appendChild( link( row.cdn_source, 'CDN / 流量定价' ) );
			}
			if ( sources.childNodes.length ) {
				inner.appendChild( sources );
			}

			holder.appendChild( inner );
			detail.appendChild( holder );

			return { row: tr, detail: detail };
		}

		function link( url, label ) {
			var anchor = node( 'a', '', label );
			anchor.href = url;
			anchor.target = '_blank';
			anchor.rel = 'noopener noreferrer';
			return anchor;
		}

		function render( data ) {
			var rows = data && data.rows ? data.rows : [];
			var fragment = document.createDocumentFragment();

			rows.forEach( function ( row ) {
				var built = rowHtml( row );
				fragment.appendChild( built.row );
				fragment.appendChild( built.detail );
			} );

			el.rows.textContent = '';
			el.rows.appendChild( fragment );

			if ( el.meta ) {
				var modeLabel = 'direct' === ( data.mode || '' ) ? ( strings.modeDirect || '源站直出' ) : ( strings.modeCdn || 'CDN 组合' );
				el.meta.textContent = '当前口径：' + modeLabel + '；共比较 ' + admin.format.number( rows.length, 0 ) + ' 家。按合计从低到高排列。';
			}

			if ( ! rows.length ) {
				var empty = node( 'tr' );
				var td = node( 'td', '', strings.empty || '没有估算结果。' );
				td.colSpan = 7;
				empty.appendChild( td );
				el.rows.appendChild( empty );
			}
		}

		function payload() {
			return {
				nonce: config.nonce || '',
				storage_gb: number( el.storage ),
				traffic_gb: number( el.traffic ),
				read_wan: number( el.read ),
				write_wan: number( el.write ),
				image_gb: number( el.image ),
				hit_rate: number( el.hit ),
				busy_rate: number( el.busy ),
				https_rate: number( el.https ),
				mode: el.mode ? el.mode.value : 'cdn',
				fx: number( el.fx )
			};
		}

		function estimate() {
			if ( busy ) {
				return;
			}

			busy = true;
			admin.ui.busy( el.run, true );
			if ( el.status ) {
				el.status.textContent = strings.estimating || '正在估算…';
			}

			admin.api.ajax( 'sakura_storage_cost_estimate', payload(), { timeout: 30000 } )
				.then( function ( data ) {
					render( data );
					if ( el.status ) {
						el.status.textContent = strings.done || '已按当前需求重新估算。';
					}
				} )
				.catch( function ( error ) {
					var message = strings.unknown || '估算失败，请稍后重试。';
					if ( error && error.payload && error.payload.data && error.payload.data.message ) {
						message = error.payload.data.message;
					}
					if ( el.status ) {
						el.status.textContent = message;
					}
					admin.ui.toast( message, 'bad', { duration: 6000 } );
				} )
				.then( function () {
					busy = false;
					admin.ui.busy( el.run, false );
				} );
		}

		if ( el.run ) {
			el.run.addEventListener( 'click', estimate );
		}

		root.addEventListener( 'click', function ( event ) {
			var toggle = event.target.closest ? event.target.closest( '[data-cost-toggle]' ) : null;

			if ( ! toggle || ! root.contains( toggle ) ) {
				return;
			}

			var key = toggle.getAttribute( 'data-cost-toggle' );
			var detail = admin.qs( '[data-cost-detail="' + key + '"]', root );

			if ( ! detail ) {
				return;
			}

			var open = detail.hidden;
			detail.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
	} );
}());

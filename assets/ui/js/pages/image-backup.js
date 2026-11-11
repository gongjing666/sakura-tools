/**
 * Backup page: refresh usage, clean expired snapshots, purge everything and
 * probe whether the backup directory is publicly reachable.
 *
 * @package Sakura_Image_Optimizer
 */
( function ( window, document ) {
	'use strict';

	var admin = window.SakuraAdmin;

	function errorText( error ) {
		if ( ! error ) {
			return '请求失败，请重试';
		}
		var payload = error.payload;
		if ( typeof payload === 'string' && payload ) {
			return payload;
		}
		if ( payload && payload.data ) {
			if ( typeof payload.data === 'string' ) {
				return payload.data;
			}
			if ( payload.data.message ) {
				return payload.data.message;
			}
		}
		return error.message || '请求失败，请重试';
	}

	function setMessage( element, text, tone ) {
		if ( ! element ) {
			return;
		}
		element.textContent = text || '';
		element.classList.remove( 'is-ok', 'is-bad', 'is-muted' );
		element.classList.add( 'is-' + ( tone || 'muted' ) );
	}

	admin.ready( function () {
		var root = admin.root();
		if ( ! root ) {
			return;
		}

		var purgeMessage = admin.qs( '#sakura-purge-msg', root );
		var probeMessage = admin.qs( '#sakura-probe-msg', root );
		var probeState = admin.qs( '#sakura-probe-state', root );
		var size = admin.qs( '#sakura-backup-size', root );
		var count = admin.qs( '#sakura-backup-count', root );
		var heroCount = admin.qs( '#sakura-backup-hero-count', root );
		var heroSize = admin.qs( '#sakura-backup-hero-size', root );

		function applyUsage( data ) {
			if ( size && data.size !== undefined ) {
				size.textContent = data.size;
			}
			if ( count && data.count !== undefined ) {
				count.textContent = data.count;
			}
			if ( heroCount && data.count !== undefined ) {
				heroCount.textContent = data.count;
			}
			if ( heroSize && data.size !== undefined ) {
				heroSize.textContent = data.size;
			}
		}

		function applyProbe( data ) {
			var state = String( data.state || '' );
			var tone = state === 'exposed' ? 'bad' : ( state === 'protected' ? 'ok' : 'neutral' );
			if ( probeState ) {
				probeState.textContent = data.message || '';
				probeState.classList.remove( 'is-ok', 'is-bad', 'is-neutral', 'is-muted' );
				probeState.classList.add( 'is-' + tone );
			}
			setMessage( probeMessage, '', 'muted' );
		}

		function run( button ) {
			var action = button.getAttribute( 'data-sakura-backup-action' );
			var nonce = button.getAttribute( 'data-sakura-nonce' ) || '';
			var probe = action === 'sakura_probe_backup';
			var target = probe ? probeMessage : purgeMessage;

			admin.ui.busy( button, true );
			setMessage( target, probe ? '检测中…' : '处理中…', 'muted' );

			admin.api.ajax( action, { _ajax_nonce: nonce } ).then( function ( data ) {
				var payload = data || {};
				if ( probe ) {
					applyProbe( payload );
					return;
				}
				applyUsage( payload );
				setMessage( target, payload.message || '', 'ok' );
			} ).catch( function ( error ) {
				setMessage( target, errorText( error ), 'bad' );
			} ).finally( function () {
				admin.ui.busy( button, false );
			} );
		}

		admin.qsa( '[data-sakura-backup-action]', root ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( button.getAttribute( 'data-sakura-backup-action' ) === 'sakura_purge_backup' ) {
					admin.ui.confirm( '确定要清空全部原图备份吗？此操作不可恢复。', {
						title: '清空全部备份',
						confirmText: '清空',
						cancelText: '取消',
						danger: true,
						tone: 'danger'
					} ).then( function ( confirmed ) {
						if ( confirmed ) {
							run( button );
						}
					} );
					return;
				}
				run( button );
			} );
		} );
	} );
}( window, document ) );

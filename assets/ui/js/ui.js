/**
 * Sakura Admin UI: toasts, native-dialog confirmations, busy states, copy and
 * responsive table labels. Pages call these instead of window.alert/confirm.
 *
 * @package Sakura_Image_Optimizer
 */
( function ( window, document ) {
	'use strict';

	var admin = window.SakuraAdmin;

	function host() {
		return admin.root() || document.body;
	}

	function toast( message, tone, options ) {
		var settings = options || {};
		var stack = admin.qs( '.toast-stack', host() );
		if ( ! stack ) {
			stack = document.createElement( 'div' );
			stack.className = 'toast-stack';
			stack.setAttribute( 'role', 'status' );
			stack.setAttribute( 'aria-live', 'polite' );
			host().appendChild( stack );
		}

		var item = document.createElement( 'div' );
		item.className = 'toast toast--' + ( tone || 'info' );
		item.setAttribute( 'role', 'status' );

		var text = document.createElement( 'span' );
		text.className = 'toast__text';
		text.textContent = String( message || '' );
		item.appendChild( text );

		if ( settings.dismissible !== false ) {
			var close = document.createElement( 'button' );
			close.type = 'button';
			close.className = 'toast__close';
			close.setAttribute( 'aria-label', '关闭' );
			close.textContent = '×';
			close.addEventListener( 'click', function () {
				remove();
			} );
			item.appendChild( close );
		}

		stack.appendChild( item );

		var timer = 0;
		function remove() {
			window.clearTimeout( timer );
			if ( ! item.parentNode ) {
				return;
			}
			item.classList.add( 'is-leaving' );
			window.setTimeout( function () {
				if ( item.parentNode ) {
					item.parentNode.removeChild( item );
				}
			}, 180 );
		}

		if ( settings.duration !== 0 && settings.duration !== false ) {
			timer = window.setTimeout( remove, Number( settings.duration ) || 4200 );
		}

		return { close: remove };
	}

	/**
	 * 视觉稿里弹窗左上角那只小鲸鱼（docs/设计稿/s3-sync-center-sakura.html 的
	 * .modal__mascot）。2026-11-20 起所有确认框都用它，插件里的弹窗因此只有
	 * 一种样子；调用方传 mascot:false 可以要求纯文字。
	 */
	function mascotSvg() {
		var wrap = document.createElement( 'div' );
		wrap.className = 'dialog__mascot';
		wrap.setAttribute( 'aria-hidden', 'true' );
		wrap.innerHTML = '<svg width="46" height="46" viewBox="0 0 46 46" aria-hidden="true" focusable="false">'
			+ '<circle cx="23" cy="24" r="17" fill="#ffd9e6"/>'
			+ '<path d="M9 15c2 2 4 3.2 6.5 3.4C18 11 28 11 30.5 18.4 33 18.2 35 17 37 15c1.4 2.2 1.8 4.6 1.4 7-2.6-.6-4.8-.2-6.6 1.2.7 6.7-4.4 12.4-11.3 12.4S8.9 30.1 9.6 23.4c-1.8-1.4-4-1.8-6.6-1.2-.4-2.4 0-4.8 1.4-7z" fill="#ffb3d1"/>'
			+ '<circle cx="18.4" cy="24" r="1.9" fill="#3a2333"/><circle cx="27.6" cy="24" r="1.9" fill="#3a2333"/>'
			+ '<path d="M20.4 28.6c1.7 1.5 3.5 1.5 5.2 0" stroke="#3a2333" stroke-width="1.6" fill="none" stroke-linecap="round"/>'
			+ '<circle cx="15.2" cy="27.4" r="2.4" fill="#ff8fb8" opacity=".55"/><circle cx="30.8" cy="27.4" r="2.4" fill="#ff8fb8" opacity=".55"/>'
			+ '</svg>';
		return wrap;
	}

	/**
	 * 清单行首的小纸片图标：图片粉、视频蓝、压缩包琥珀、其它暖灰。
	 */
	function fileGlyph( name ) {
		var ext = ( String( name ).match( /\.([a-z0-9]{1,6})$/i ) || [ '', '' ] )[ 1 ].toLowerCase();
		var pink = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'heic', 'svg' ];
		var sky  = [ 'mp4', 'mov', 'webm', 'mkv', 'avi', 'm4v' ];
		var sun  = [ 'zip', 'rar', '7z', 'gz', 'tar', 'tgz' ];
		var fill = '#c9b6c2';
		if ( pink.indexOf( ext ) >= 0 ) { fill = '#ff8fb8'; } else if ( sky.indexOf( ext ) >= 0 ) { fill = '#9dccff'; } else if ( sun.indexOf( ext ) >= 0 ) { fill = '#ffd08a'; }
		var wrap = document.createElement( 'span' );
		wrap.className = 'dialog__glyph';
		wrap.setAttribute( 'aria-hidden', 'true' );
		wrap.innerHTML = '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">'
			+ '<path fill="' + fill + '" d="M3 1h6l4 4v10H3z"/><path fill="#fff" d="M9 1v4h4z" opacity=".65"/></svg>';
		return wrap;
	}

	/**
	 * Promise-based confirmation using a native <dialog>. Falls back to
	 * window.confirm only when the dialog element is unavailable.
	 */
	function confirm( message, options ) {
		var settings = options || {};
		if ( typeof document.createElement( 'dialog' ).showModal !== 'function' ) {
			return Promise.resolve( window.confirm( message ) );
		}

		return new Promise( function ( resolve ) {
			// Remember where the operator was: showModal() moves focus into the
			// dialog, and removing the node on close would otherwise drop focus to
			// <body> and leave a keyboard user with nothing to continue from.
			var opener    = document.activeElement;
			var openScroll = Math.round( window.pageYOffset || 0 );
			var dialog = document.createElement( 'dialog' );
			dialog.className = 'sakura-dialog';
			if ( settings.tone ) {
				dialog.classList.add( 'sakura-dialog--' + settings.tone );
			}

			var form = document.createElement( 'form' );
			form.method = 'dialog';
			form.className = 'sakura-dialog__form';

			var body = document.createElement( 'div' );
			body.className = 'dialog__body';

			/* 要删东西的对话框带头像；纯提示（已保存、连接成功）不加。 */
			// 2026-11-20：所有确认框都带这只小鲸鱼——开发者要求「插件的所有弹窗
			// 提示统一成文件核对的删除弹窗」。传 mascot:false 可以只剩文字。
			if ( settings.mascot !== false ) {
				dialog.setAttribute( 'data-mascot', '1' );
				body.appendChild( mascotSvg() );
			}

			var textWrap = document.createElement( 'div' );
			textWrap.className = 'dialog__text';

			var title = document.createElement( 'h2' );
			title.className = 'dialog__title';
			title.textContent = settings.title || '请确认';
			textWrap.appendChild( title );

			var text = document.createElement( 'p' );
			text.className = 'dialog__message';
			text.textContent = String( message || '' );
			textWrap.appendChild( text );
			body.appendChild( textWrap );
			form.appendChild( body );

			var list = null;
			if ( Array.isArray( settings.items ) && settings.items.length ) {
				list = document.createElement( 'ul' );
				list.className = 'dialog__list';
				settings.items.forEach( function ( entry ) {
					var row  = document.createElement( 'li' );
					var parts = String( entry || '' ).split( ' · ' );
					/* 「…另有 N 个对象」这类汇总行不带纸片图标。 */
					if ( parts[ 0 ] && parts[ 0 ].indexOf( '…' ) !== 0 ) {
						row.appendChild( fileGlyph( parts[ 0 ] ) );
					}
					var name = document.createElement( 'span' );
					name.className = 'dialog__list-name';
					name.textContent = parts[ 0 ] || '';
					row.appendChild( name );
					if ( parts.length > 1 ) {
						var size = document.createElement( 'em' );
						size.textContent = parts.slice( 1 ).join( ' · ' );
						row.appendChild( size );
					}
					list.appendChild( row );
				} );
				form.appendChild( list );
			}

			/*
			 * R7 (07-S3存储生命周期): deleting the bound profile after the local
			 * copies were already removed cannot be undone, so the operator has to
			 * type the profile name. Client-side only here — the server re-checks
			 * the same string, because a disabled control or a skipped script must
			 * never be the thing that protects the attachments.
			 */
			var typed = null;
			if ( settings.requireText ) {
				var gate = document.createElement( 'div' );
				gate.className = 'dialog__gate';
				var gateLabel = document.createElement( 'label' );
				gateLabel.className = 'dialog__gate-label';
				gateLabel.textContent = settings.requireLabel || ( '请输入「' + settings.requireText + '」确认' );
				typed = document.createElement( 'input' );
				typed.type = 'text';
				typed.className = 'dialog__gate-input';
				typed.autocomplete = 'off';
				typed.spellcheck = false;
				gateLabel.appendChild( typed );
				gate.appendChild( gateLabel );
				form.appendChild( gate );
			}

			var actions = document.createElement( 'div' );
			actions.className = 'dialog__actions';

			var cancel = document.createElement( 'button' );
			cancel.type = 'button';
			cancel.className = 'btn btn--ghost';
			cancel.textContent = settings.cancelText || '取消';
			cancel.addEventListener( 'click', function () {
				closeDialog( dialog, 'cancel' );
			} );

			var accept = document.createElement( 'button' );
			accept.type = 'button';
			accept.className = settings.danger ? 'btn btn--danger' : 'btn btn--primary';
			accept.textContent = settings.confirmText || '确认';
			accept.addEventListener( 'click', function () {
				closeDialog( dialog, 'confirm' );
			} );

			if ( typed ) {
				accept.disabled = true;
				accept.setAttribute( 'aria-disabled', 'true' );
				var syncGate = function () {
					var ok = typed.value.trim() === String( settings.requireText ).trim();
					accept.disabled = ! ok;
					accept.setAttribute( 'aria-disabled', ok ? 'false' : 'true' );
				};
				typed.addEventListener( 'input', syncGate );
				// Enter inside a method=dialog form would close it as a confirmation,
				// which would let the gate be skipped with one keystroke.
				form.addEventListener( 'submit', function ( event ) {
					event.preventDefault();
					if ( ! accept.disabled ) {
						closeDialog( dialog, 'confirm' );
					}
				} );
			}

			/* 一段「后果说明」，视觉稿里是正文下面那块淡粉便签。正文只留一句
			 * 结论，细节放这里，读起来不会连成一大段。 */
			if ( settings.note ) {
				var note = document.createElement( 'p' );
				note.className = 'dialog__note';
				note.textContent = String( settings.note );
				form.appendChild( note );
			}

			actions.appendChild( cancel );
			actions.appendChild( accept );
			form.appendChild( actions );
			dialog.appendChild( form );
			host().appendChild( dialog );

			// Esc 也要走同一条出场动画：原生 cancel 会立刻关掉，卡片会「啪」地消失。
			dialog.addEventListener( 'cancel', function ( event ) {
				event.preventDefault();
				closeDialog( dialog, 'cancel' );
			} );

			dialog.addEventListener( 'close', function () {
				var confirmed = dialog.returnValue === 'confirm';
				if ( dialog.parentNode ) {
					dialog.parentNode.removeChild( dialog );
				}

				// Put the reader back exactly where they were before the prompt: focus on
				// the control that asked, viewport on the same scroll offset. preventScroll
				// keeps the focus call from jumping; the explicit scrollTo covers the
				// cases where the browser moves anyway.
				if ( opener && opener !== document.body && document.contains( opener ) && opener.focus ) {
					opener.focus( { preventScroll: true } );
				}
				if ( Math.round( window.pageYOffset || 0 ) !== openScroll ) {
					window.scrollTo( 0, openScroll );
				}

				resolve( confirmed );
			}, { once: true } );

			dialog.showModal();
			if ( typed ) {
				typed.focus();
			} else {
				accept.focus();
			}
		} );
	}

	function busy( element, state ) {
		if ( ! element ) {
			return function () {};
		}
		var active = state !== false;
		element.classList.toggle( 'is-busy', active );
		element.setAttribute( 'aria-busy', active ? 'true' : 'false' );
		admin.qsa( 'button, input[type="submit"]', element ).forEach( function ( control ) {
			if ( active ) {
				if ( ! control.hasAttribute( 'data-sakura-was-disabled' ) ) {
					control.setAttribute( 'data-sakura-was-disabled', control.disabled ? '1' : '0' );
				}
				control.disabled = true;
				return;
			}
			if ( control.getAttribute( 'data-sakura-was-disabled' ) === '0' ) {
				control.disabled = false;
			}
			control.removeAttribute( 'data-sakura-was-disabled' );
		} );
		return function () {
			busy( element, false );
		};
	}

	function copy( text ) {
		if ( window.navigator.clipboard && window.isSecureContext ) {
			return window.navigator.clipboard.writeText( String( text ) );
		}
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = String( text );
			area.setAttribute( 'readonly', 'readonly' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			try {
				if ( document.execCommand( 'copy' ) ) {
					resolve();
				} else {
					reject( new Error( '复制失败' ) );
				}
			} catch ( error ) {
				reject( error );
			} finally {
				document.body.removeChild( area );
			}
		} );
	}

	/**
	 * Mirrors table headers onto cells so .table--responsive can stack rows
	 * below 782px without losing the column name.
	 */
	function labelTables( context ) {
		var scope  = context || document;
		var owner  = scope.closest ? scope.closest( 'table.table--responsive' ) : null;
		var tables = owner ? [ owner ] : admin.qsa( '.table--responsive', scope );

		tables.forEach( function ( table ) {
			var headers = admin.qsa( 'thead th', table ).map( function ( cell ) {
				return cell.textContent.trim();
			} );
			if ( ! headers.length ) {
				return;
			}
			admin.qsa( 'tbody tr', table ).forEach( function ( row ) {
				admin.qsa( 'td', row ).forEach( function ( cell, index ) {
					if ( headers[ index ] && ! cell.hasAttribute( 'data-label' ) ) {
						cell.setAttribute( 'data-label', headers[ index ] );
					}
				} );
			} );
		} );
	}

	var mediaSeq = 0;

	var MEDIA_ICONS = {
		close: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
		play: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg>',
		pause: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 5h3v14H8zM13 5h3v14h-3z" fill="currentColor"/></svg>',
		volume: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 9v6h4l5 4V5L8 9H4z" fill="currentColor"/><path d="M16 8.5a5 5 0 010 7M18.5 6a8 8 0 010 12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
		muted: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 9v6h4l5 4V5L8 9H4z" fill="currentColor"/><path d="M16 9l5 6M21 9l-5 6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
		fullscreen: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		download: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 20h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		file: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 3h7l4 4v14H7z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M14 3v5h5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>',
		chevronLeft: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		chevronRight: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>'
	};

	function mediaIcon( name ) {
		return MEDIA_ICONS[ name ] || '';
	}

	function mediaFill( template, values ) {
		return String( template || '' ).replace( /\{(\w+)\}/g, function ( match, key ) {
			return Object.prototype.hasOwnProperty.call( values, key ) ? String( values[ key ] ) : match;
		} );
	}

	function mediaTime( value ) {
		var total = Number( value );
		if ( ! isFinite( total ) || total < 0 ) {
			total = 0;
		}
		total = Math.floor( total );
		var hours = Math.floor( total / 3600 );
		var minutes = Math.floor( ( total % 3600 ) / 60 );
		var seconds = total % 60;
		if ( hours > 0 ) {
			return hours + ':' + ( minutes < 10 ? '0' : '' ) + minutes + ':' + ( seconds < 10 ? '0' : '' ) + seconds;
		}
		return minutes + ':' + ( seconds < 10 ? '0' : '' ) + seconds;
	}

	function mediaButton( className, iconName, label ) {
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = className;
		button.setAttribute( 'aria-label', label );
		button.innerHTML = mediaIcon( iconName );
		return button;
	}

	function mediaPlayer( payload, labels, onError ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'sakura-media-player';

		var video = document.createElement( 'video' );
		video.className = 'sakura-media-player__video';
		video.preload = 'metadata';
		video.playsInline = true;
		video.setAttribute( 'playsinline', '' );
		video.setAttribute( 'aria-label', payload.name || '' );
		video.src = payload.url;
		wrap.appendChild( video );

		var overlay = mediaButton( 'sakura-media-player__overlay', 'play', labels.play );
		wrap.appendChild( overlay );

		var controls = document.createElement( 'div' );
		controls.className = 'sakura-media-player__controls';

		var toggle = mediaButton( 'sakura-media-player__control sakura-media-player__toggle', 'play', labels.play );
		controls.appendChild( toggle );

		var time = document.createElement( 'span' );
		time.className = 'sakura-media-player__time';
		time.textContent = '0:00 / 0:00';
		controls.appendChild( time );

		var seek = document.createElement( 'input' );
		seek.type = 'range';
		seek.className = 'sakura-media-player__seek';
		seek.min = '0';
		seek.max = '1000';
		seek.step = '1';
		seek.value = '0';
		seek.disabled = true;
		seek.setAttribute( 'aria-label', labels.seek || labels.play );
		controls.appendChild( seek );

		var mute = mediaButton( 'sakura-media-player__control', 'volume', labels.mute );
		controls.appendChild( mute );

		var volume = document.createElement( 'input' );
		volume.type = 'range';
		volume.className = 'sakura-media-player__volume';
		volume.min = '0';
		volume.max = '100';
		volume.step = '1';
		volume.value = '100';
		volume.setAttribute( 'aria-label', labels.volume || labels.mute );
		controls.appendChild( volume );

		var full = mediaButton( 'sakura-media-player__control', 'fullscreen', labels.fullscreen );
		controls.appendChild( full );
		wrap.appendChild( controls );

		var duration = 0;
		var lastVolume = 1;

		function setIcon( button, name, label ) {
			button.innerHTML = mediaIcon( name );
			button.setAttribute( 'aria-label', label );
		}

		function updatePlay() {
			var paused = video.paused || video.ended;
			wrap.classList.toggle( 'is-playing', ! paused );
			setIcon( toggle, paused ? 'play' : 'pause', paused ? labels.play : labels.pause );
			setIcon( overlay, paused ? 'play' : 'pause', paused ? labels.play : labels.pause );
		}

		function updateVolume() {
			var muted = video.muted || 0 === video.volume;
			setIcon( mute, muted ? 'muted' : 'volume', muted ? labels.unmute : labels.mute );
			volume.value = String( Math.round( ( muted ? 0 : video.volume ) * 100 ) );
		}

		function togglePlay() {
			if ( video.paused || video.ended ) {
				var result = video.play();
				if ( result && typeof result.catch === 'function' ) {
					result.catch( function () { /* The error event explains the failure. */ } );
				}
				return;
			}
			video.pause();
		}

		function seekTo( ratio ) {
			if ( ! duration || ! isFinite( duration ) ) {
				return;
			}
			video.currentTime = Math.max( 0, Math.min( duration, duration * ratio ) );
		}

		toggle.addEventListener( 'click', togglePlay );
		overlay.addEventListener( 'click', togglePlay );
		video.addEventListener( 'click', togglePlay );

		seek.addEventListener( 'input', function () {
			seekTo( Number( seek.value ) / 1000 );
		} );

		mute.addEventListener( 'click', function () {
			if ( video.muted || 0 === video.volume ) {
				video.muted = false;
				video.volume = lastVolume > 0 ? lastVolume : 1;
			} else {
				lastVolume = video.volume;
				video.muted = true;
			}
			updateVolume();
		} );

		volume.addEventListener( 'input', function () {
			var next = Number( volume.value ) / 100;
			video.volume = next;
			video.muted = 0 === next;
			if ( next > 0 ) {
				lastVolume = next;
			}
			updateVolume();
		} );

		full.addEventListener( 'click', function () {
			if ( document.fullscreenElement ) {
				if ( document.exitFullscreen ) {
					document.exitFullscreen();
				}
				return;
			}
			if ( wrap.requestFullscreen ) {
				wrap.requestFullscreen().catch( function () {} );
			}
		} );

		video.addEventListener( 'loadedmetadata', function () {
			duration = Number( video.duration ) || 0;
			seek.disabled = ! duration || ! isFinite( duration );
			time.textContent = '0:00 / ' + mediaTime( duration );
		} );

		video.addEventListener( 'timeupdate', function () {
			if ( duration && isFinite( duration ) && document.activeElement !== seek ) {
				seek.value = String( Math.round( ( video.currentTime / duration ) * 1000 ) );
			}
			time.textContent = mediaTime( video.currentTime ) + ' / ' + mediaTime( duration );
		} );

		video.addEventListener( 'play', updatePlay );
		video.addEventListener( 'pause', updatePlay );
		video.addEventListener( 'ended', updatePlay );
		video.addEventListener( 'volumechange', updateVolume );
		video.addEventListener( 'error', function () {
			onError( labels.videoFailed );
		} );

		updatePlay();
		updateVolume();
		return wrap;
	}

	/**
	 * Modern media preview dialog. Supports images, a custom video player and a
	 * download-only fallback for every other file type.
	 *
	 * @param {Object} data    Preview payload from the server.
	 * @param {Object} options Optional { trigger, labels }.
	 * @return {Object|null}   { close } when opened.
	 */
	function mediaPreview( data, options ) {
		var payload = data || {};
		var settings = options || {};
		var labels = settings.labels || {};
		var trigger = settings.trigger || document.activeElement;
		var root = host();
		var items = Array.isArray( settings.items ) && settings.items.length ? settings.items.slice() : [ payload ];
		var index = Number( settings.index );

		items = items.filter( function ( item ) {
			return item && item.name;
		} );

		if ( ! items.length ) {
			return null;
		}

		if ( ! isFinite( index ) || index < 0 ) {
			index = 0;
		}
		index = Math.min( items.length - 1, Math.floor( index ) );
		payload = items[ index ];

		var text = {
			close: labels.close || '关闭',
			download: labels.download || '下载文件',
			play: labels.play || '播放',
			pause: labels.pause || '暂停',
			mute: labels.mute || '静音',
			unmute: labels.unmute || '取消静音',
			volume: labels.volume || '音量',
			seek: labels.seek || '播放进度',
			fullscreen: labels.fullscreen || '全屏',
			videoFailed: labels.videoFailed || '视频无法播放，可能格式不受浏览器支持；可直接下载查看。',
			imageFailed: labels.imageFailed || '图片暂时无法显示，可能已被移动或云端权限不允许读取；可直接下载查看。',
			unavailable: labels.unavailable || '该文件类型暂不支持在线预览，下载后可用本地应用打开。',
			noUrl: labels.noUrl || '暂时没有可用的预览地址。',
			imageErrorTitle: labels.imageErrorTitle || '图片暂时无法显示',
			videoErrorTitle: labels.videoErrorTitle || '视频暂时无法播放',
			unavailableTitle: labels.unavailableTitle || '暂不支持在线预览',
			noUrlTitle: labels.noUrlTitle || '暂无可用预览',
			local: labels.local || '本地文件',
			cloud: labels.cloud || '云端对象',
			prev: labels.prev || '上一个文件',
			next: labels.next || '下一个文件',
			counter: labels.counter || '{current} / {total}',
			gallery: labels.gallery || '第 {current} 个，共 {total} 个文件',
			folderTitle: labels.folderTitle || '文件夹',
			folderMessage: labels.folderMessage || '这个文件夹申请里没有可预览的文件。'
		};

		var existing = admin.qs( '.sakura-media-dialog[open]', root );
		if ( existing ) {
			existing.close();
		}

		var titleId = 'sakura-media-title-' + ( ++mediaSeq );
		var dialog = document.createElement( 'dialog' );
		dialog.className = 'sakura-media-dialog';
		dialog.setAttribute( 'aria-labelledby', titleId );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.tabIndex = -1;

		var panel = document.createElement( 'div' );
		panel.className = 'sakura-media-dialog__panel';
		panel.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
		} );

		var header = document.createElement( 'header' );
		header.className = 'sakura-media-dialog__head';

		var copy = document.createElement( 'div' );
		copy.className = 'sakura-media-dialog__copy';

		var eyebrow = document.createElement( 'p' );
		eyebrow.className = 'sakura-media-dialog__eyebrow';
		copy.appendChild( eyebrow );

		var title = document.createElement( 'h2' );
		title.className = 'sakura-media-dialog__title';
		title.id = titleId;
		copy.appendChild( title );

		var meta = document.createElement( 'div' );
		meta.className = 'sakura-media-dialog__meta';
		copy.appendChild( meta );

		function setHeader( item ) {
			var parts = [];
			if ( item.kind_label ) {
				parts.push( item.kind_label );
			}
			if ( item.ext ) {
				parts.push( item.ext );
			}
			eyebrow.textContent = parts.join( ' · ' );
			title.textContent = item.name || '';
			meta.textContent = '';

			[ item.size_label, item.status_label, item.mtime_label ].forEach( function ( value ) {
				if ( ! value ) {
					return;
				}
				var chip = document.createElement( 'span' );
				chip.className = 'sakura-media-dialog__chip';
				chip.textContent = value;
				meta.appendChild( chip );
			} );

			var placeLabel = item.place_label || '';
			if ( ! placeLabel ) {
				if ( item.local && item.cloud ) {
					placeLabel = text.local + ' + ' + text.cloud;
				} else if ( item.local ) {
					placeLabel = text.local;
				} else if ( item.cloud ) {
					placeLabel = text.cloud;
				}
			}

			if ( placeLabel ) {
				var place = document.createElement( 'span' );
				place.className = 'sakura-media-dialog__chip sakura-media-dialog__chip--place';
				place.textContent = placeLabel;
				meta.appendChild( place );
			}
		}

		setHeader( payload );
		header.appendChild( copy );

		var closeButton = mediaButton( 'sakura-media-dialog__close', 'close', text.close );
		header.appendChild( closeButton );
		panel.appendChild( header );

		var stage = document.createElement( 'div' );
		stage.className = 'sakura-media-dialog__stage';

		var stageBody = document.createElement( 'div' );
		stageBody.className = 'sakura-media-dialog__stage-body';
		stage.appendChild( stageBody );

		var gallery = document.createElement( 'div' );
		gallery.className = 'sakura-media-dialog__gallery';

		var prevButton = mediaButton( 'sakura-media-dialog__gallery-btn sakura-media-dialog__gallery-prev', 'chevronLeft', text.prev );
		var counter = document.createElement( 'span' );
		counter.className = 'sakura-media-dialog__gallery-count';
		var nextButton = mediaButton( 'sakura-media-dialog__gallery-btn sakura-media-dialog__gallery-next', 'chevronRight', text.next );
		gallery.appendChild( prevButton );
		gallery.appendChild( counter );
		gallery.appendChild( nextButton );
		stage.appendChild( gallery );
		panel.appendChild( stage );

		var videoNode = null;
		var renderSeq = 0;
		var swipeStart = null;

		function stageMessage( heading, message ) {
			stageBody.innerHTML = '';
			stage.classList.remove( 'is-loading', 'is-video' );
			var note = document.createElement( 'div' );
			note.className = 'sakura-media-dialog__note';
			var icon = document.createElement( 'span' );
			icon.className = 'sakura-media-dialog__note-icon';
			icon.innerHTML = mediaIcon( 'file' );
			note.appendChild( icon );
			var strong = document.createElement( 'strong' );
			strong.textContent = heading;
			note.appendChild( strong );
			var detail = document.createElement( 'p' );
			detail.textContent = message || '';
			note.appendChild( detail );
			stageBody.appendChild( note );
		}

		function stopVideo() {
			if ( ! videoNode ) {
				return;
			}
			videoNode.pause();
			videoNode.removeAttribute( 'src' );
			videoNode.load();
			videoNode = null;
		}

		function updateGallery() {
			gallery.hidden = items.length < 2;
			counter.textContent = mediaFill( text.counter, { current: index + 1, total: items.length } );
			gallery.setAttribute( 'aria-label', mediaFill( text.gallery, { current: index + 1, total: items.length } ) );
			prevButton.setAttribute( 'aria-disabled', index <= 0 ? 'true' : 'false' );
			nextButton.setAttribute( 'aria-disabled', index >= items.length - 1 ? 'true' : 'false' );
		}

		function go( delta ) {
			var next = index + delta;
			if ( next < 0 || next >= items.length ) {
				return;
			}
			renderItem( next );
		}

		function renderItem( next ) {
			next = Number( next );
			if ( ! isFinite( next ) || next < 0 || next >= items.length ) {
				return;
			}
			stopVideo();
			index = next;
			payload = items[ index ];
			var token = ++renderSeq;

			stage.classList.remove( 'is-loading', 'is-video' );
			stageBody.innerHTML = '';
			setHeader( payload );
			setPath( payload );
			setActions( payload );
			updateGallery();

			if ( 'image' === payload.kind ) {
				if ( ! payload.url ) {
					stageMessage( text.noUrlTitle, text.noUrl );
					return;
				}
				stage.classList.add( 'is-loading' );
				var image = document.createElement( 'img' );
				image.className = 'sakura-media-dialog__image';
				image.alt = payload.name;
				image.decoding = 'async';
				image.loading = 'eager';
				image.src = payload.url;
				image.addEventListener( 'load', function () {
					if ( token === renderSeq ) {
						stage.classList.remove( 'is-loading' );
					}
				} );
				image.addEventListener( 'error', function () {
					if ( token === renderSeq ) {
						stageMessage( text.imageErrorTitle, text.imageFailed );
					}
				} );
				stageBody.appendChild( image );
				return;
			}

			if ( 'video' === payload.kind ) {
				if ( ! payload.url ) {
					stageMessage( text.noUrlTitle, text.noUrl );
					return;
				}
				stage.classList.add( 'is-video' );
				var player = mediaPlayer( payload, text, function ( message ) {
					if ( token === renderSeq ) {
						stageMessage( text.videoErrorTitle, message );
					}
				} );
				videoNode = player.querySelector( 'video' );
				stageBody.appendChild( player );
				return;
			}

			if ( 'folder' === payload.kind ) {
				stageMessage( text.folderTitle, payload.message || text.folderMessage );
				return;
			}

			stageMessage( text.unavailableTitle, payload.message || text.unavailable );
		}

		prevButton.addEventListener( 'click', function () {
			go( -1 );
		} );
		nextButton.addEventListener( 'click', function () {
			go( 1 );
		} );

		stage.addEventListener( 'touchstart', function ( event ) {
			if ( event.touches.length !== 1 ) {
				return;
			}
			if ( event.target.closest && event.target.closest( 'input, button, a' ) ) {
				return;
			}
			swipeStart = { x: event.touches[0].clientX, y: event.touches[0].clientY };
		}, { passive: true } );
		stage.addEventListener( 'touchend', function ( event ) {
			if ( ! swipeStart || ! event.changedTouches.length ) {
				swipeStart = null;
				return;
			}
			var dx = event.changedTouches[0].clientX - swipeStart.x;
			var dy = event.changedTouches[0].clientY - swipeStart.y;
			swipeStart = null;
			if ( Math.abs( dx ) >= 48 && Math.abs( dx ) > Math.abs( dy ) * 1.2 ) {
				go( dx < 0 ? 1 : -1 );
			}
		}, { passive: true } );
		stage.addEventListener( 'touchcancel', function () {
			swipeStart = null;
		}, { passive: true } );

		var footer = document.createElement( 'footer' );
		footer.className = 'sakura-media-dialog__foot';

		var info = document.createElement( 'div' );
		info.className = 'sakura-media-dialog__info';
		var path = document.createElement( 'span' );
		path.className = 'sakura-media-dialog__path';
		info.appendChild( path );
		footer.appendChild( info );

		var actions = document.createElement( 'div' );
		actions.className = 'sakura-media-dialog__actions';
		footer.appendChild( actions );
		panel.appendChild( footer );
		dialog.appendChild( panel );

		function setPath( item ) {
			path.textContent = item.path || item.key || '';
			path.title = path.textContent;
		}

		function setActions( item ) {
			actions.textContent = '';
			if ( ! item.download ) {
				return;
			}
			var download = document.createElement( 'a' );
			download.className = 'btn btn--primary sakura-media-dialog__download';
			download.href = item.download;
			download.setAttribute( 'download', '' );
			var downloadIcon = document.createElement( 'span' );
			downloadIcon.className = 'sakura-media-dialog__download-icon';
			downloadIcon.innerHTML = mediaIcon( 'download' );
			download.appendChild( downloadIcon );
			var downloadLabel = document.createElement( 'span' );
			downloadLabel.textContent = text.download;
			download.appendChild( downloadLabel );
			actions.appendChild( download );
		}

		var closed = false;
		var pageScrollY = 0;
		var pageBodyStyle = null;

		function lockPage() {
			pageScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
			var body = document.body;
			var bodyTop = body.getBoundingClientRect().top;
			pageBodyStyle = {
				position: body.style.position,
				top: body.style.top,
				left: body.style.left,
				right: body.style.right,
				width: body.style.width
			};
			body.style.position = 'fixed';
			body.style.top = bodyTop + 'px';
			body.style.left = '0';
			body.style.right = '0';
			body.style.width = '100%';
			document.documentElement.classList.add( 'sakura-media-open' );
		}

		function unlockPage() {
			if ( ! pageBodyStyle ) {
				return;
			}
			var body = document.body;
			body.style.position = pageBodyStyle.position;
			body.style.top = pageBodyStyle.top;
			body.style.left = pageBodyStyle.left;
			body.style.right = pageBodyStyle.right;
			body.style.width = pageBodyStyle.width;
			pageBodyStyle = null;
			document.documentElement.classList.remove( 'sakura-media-open' );
			window.scrollTo( 0, pageScrollY );
		}

		function close() {
			if ( closed ) {
				return;
			}
			closed = true;
			if ( dialog.open ) {
				closeDialog( dialog, 'close' );
			} else {
				unlockPage();
				if ( dialog.parentNode ) {
					dialog.parentNode.removeChild( dialog );
				}
			}
		}

		closeButton.addEventListener( 'click', close );
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				close();
			}
		} );
		dialog.addEventListener( 'cancel', function ( event ) {
			event.preventDefault();
			close();
		} );
		dialog.addEventListener( 'close', function () {
			unlockPage();
			stopVideo();
			if ( dialog.parentNode ) {
				dialog.parentNode.removeChild( dialog );
			}
			if ( trigger && typeof trigger.focus === 'function' ) {
				trigger.focus( { preventScroll: true } );
			}
		} );

		dialog.addEventListener( 'keydown', function ( event ) {
			var tag = ( event.target && event.target.tagName ) || '';
			if ( /^(INPUT|TEXTAREA|SELECT)$/.test( tag ) ) {
				return;
			}
			if ( items.length > 1 && ( 'ArrowLeft' === event.key || 'ArrowRight' === event.key ) && ! event.shiftKey ) {
				event.preventDefault();
				go( 'ArrowLeft' === event.key ? -1 : 1 );
				return;
			}
			if ( ! videoNode ) {
				return;
			}
			if ( /^(BUTTON|A)$/.test( tag ) ) {
				return;
			}
			if ( ' ' === event.key ) {
				event.preventDefault();
				if ( videoNode.paused || videoNode.ended ) {
					var result = videoNode.play();
					if ( result && typeof result.catch === 'function' ) {
						result.catch( function () {} );
					}
				} else {
					videoNode.pause();
				}
				return;
			}
			if ( 'ArrowLeft' === event.key || 'ArrowRight' === event.key ) {
				event.preventDefault();
				videoNode.currentTime = Math.max( 0, Math.min( videoNode.duration || 0, videoNode.currentTime + ( 'ArrowLeft' === event.key ? -5 : 5 ) ) );
			}
		} );

		renderItem( index );
		root.appendChild( dialog );
		lockPage();

		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}
		dialog.focus();

		return { close: close };
	}


	/*
	 * Keep the reader where they were across a save that reloads the page.
	 *
	 * Cards that opted into initInPlaceSaves() never get here: they post through
	 * fetch and the document is never replaced. For the rest, a form posts to
	 * options.php, WordPress redirects back, and the browser paints a fresh
	 * document at scroll 0 — saving a card near the bottom throws the user back to
	 * the top even though the dialog itself is careful about position. Remember the
	 * offset before the POST and restore it on the next load of the same screen.
	 *
	 * Scoped per URL (path + page) so a redirect to a different screen does not get
	 * the previous screen's offset, and cleared on read so a later manual refresh
	 * starts clean at the top.
	 */
	var SAVE_SCROLL_KEY = 'sakuraAdminSaveScroll';
	// The offset this document restored after a save; the prompt mounted on the
	// same load inherits it so its close target matches the visible position.
	var restoredOffset = null;

	/*
	 * Identity of the screen, used to decide whether a stored offset belongs here.
	 *
	 * Only path + page: WordPress redirects some savers back to a URL WITHOUT the
	 * tab argument (the object-storage saver returns page=sakura-tools-storage
	 * with no t=), so including 't' made the key mismatch and the offset was
	 * silently dropped. The tab is part of the page id anyway on these screens,
	 * and a tab switch is a link, not a submit, so nothing is stored.
	 */
	function screenKey() {
		var params = new URLSearchParams( window.location.search );
		return [
			window.location.pathname,
			params.get( 'page' ) || ''
		].join( '|' );
	}

	function rememberScrollOnSave() {
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			if ( ! form || 'FORM' !== form.tagName ) {
				return;
			}
			// Only a full navigation loses the scroll; an AJAX form keeps its page.
			var action = form.getAttribute( 'action' ) || '';
			if ( action && -1 === action.indexOf( 'options.php' ) && -1 === action.indexOf( 'admin-post.php' ) ) {
				return;
			}
			try {
				window.sessionStorage.setItem( SAVE_SCROLL_KEY, JSON.stringify( {
					key: screenKey(),
					y: Math.round( window.pageYOffset || 0 )
				} ) );
			} catch ( error ) {}
		}, true );
	}

	/**
	 * Pending offset from a save round-trip that belongs to THIS screen, or null.
	 */
	function readPendingScroll() {
		var saved = null;
		try {
			var raw = window.sessionStorage.getItem( SAVE_SCROLL_KEY );
			if ( ! raw ) {
				return null;
			}
			saved = JSON.parse( raw );
		} catch ( error ) {
			return null;
		}
		if ( ! saved || saved.key !== screenKey() || ! ( saved.y > 0 ) ) {
			return null;
		}
		return saved.y;
	}

	/**
	 * Put the viewport back where the save round-trip started.
	 *
	 * Fallback only: cards that opted into in-place saving never reload. Kept for
	 * the forms that still post, where the new document starts at scroll 0 and this
	 * is the earliest moment a footer script can act — after the first paint, not
	 * before it. It still beats not restoring at all, but it is a visible jump, and
	 * that jump is why the in-place path exists.
	 *
	 * @return {number|null} The applied offset, or null when there was nothing to do.
	 */
	function restoreScrollAfterSave() {
		var target = readPendingScroll();
		if ( null === target ) {
			return null;
		}

		/*
		 * Apply immediately so the first paint is already correct, then once more
		 * after layout settles: images and web fonts arrive after DOMContentLoaded
		 * and can grow the document above the saved offset, which silently clamps a
		 * scroll applied too early. The second pass is cheap and idempotent.
		 */
		function apply() {
			window.scrollTo( 0, target );
		}

		apply();
		if ( document.readyState === 'complete' ) {
			apply();
		} else {
			window.addEventListener( 'load', function () {
			if ( Math.round( window.pageYOffset || 0 ) !== target ) {
				apply();
			}
		}, { once: true } );
		}

		// Forget the offset: a later manual refresh must start at the top.
		try {
			window.sessionStorage.removeItem( SAVE_SCROLL_KEY );
		} catch ( error ) {}

		restoredOffset = target;
		return target;
	}

	/*
	 * Save an opted-in card form without leaving the screen.
	 *
	 * 2026-11-23，实测之后的结论：这条路上的抖动修不掉，只能绕开。
	 *
	 * A card form posts to options.php, WordPress redirects back, and the browser
	 * paints a completely new document at scroll 0. restoreScrollAfterSave() cannot
	 * run before that frame — footer scripts do not exist until DOMContentLoaded —
	 * so the first frame is always the top of the page. Measured on the demo site:
	 * first-contentful-paint at y=0, the offset came back ~70ms later at
	 * readyState=complete, and the result prompt opened in the same tick on a page
	 * that had just moved. Every repair on that path is a second jump.
	 *
	 * Sending the same POST through fetch keeps the live document: nothing is
	 * reparsed, nothing scrolls, no entrance animation replays. The HTML WordPress
	 * would have navigated to is parsed instead, and the parts the operator can see
	 * are copied over — the prompt it rendered, the page-level variables and inline
	 * styles it enqueued (page width, card radius, grid gaps), and the control values
	 * the server actually kept, because it clamps them.
	 *
	 * A real navigation still wins whenever fetch cannot do the job: the redirect
	 * lands on another screen, the answer is not one of our pages, the request fails,
	 * or the browser lacks FormData / DOMParser. Cards opt in through form.inplace
	 * (prints data-sakura-inplace); a card whose neighbours print values derived from
	 * it must stay opted out, because nothing here re-renders them.
	 */
	var RESULT_MODAL_ID = 'sakura-settings-result-modal';
	// The result prompt this document mounted last: a second save replaces it
	// instead of stacking two dialogs that both answer to Escape.
	var savePrompt = null;
	// One save at a time; a second submit while it runs is dropped, not queued.
	var savingInPlace = false;

	function canSaveInPlace() {
		return !! admin.api
			&& typeof admin.api.post === 'function'
			&& typeof window.FormData === 'function'
			&& typeof window.DOMParser === 'function';
	}

	/**
	 * Action URL when this form opted into in-place saving, otherwise null.
	 *
	 * @param {Element} form
	 * @return {string|null}
	 */
	function inPlaceAction( form ) {
		if ( ! form || 'FORM' !== form.tagName || ! form.hasAttribute( 'data-sakura-inplace' ) ) {
			return null;
		}
		if ( 'post' !== ( form.getAttribute( 'method' ) || '' ).toLowerCase() ) {
			return null;
		}
		if ( ! form.closest || ! form.closest( '.sakura-admin' ) ) {
			return null;
		}
		/*
		 * 不要读 form.action / form.method：HTMLFormElement 是 OverrideBuiltIns，
		 * 卡片表单里那个 <input type="hidden" name="action" value="update"> 会把它
		 * 盖成 input 元素，后续 indexOf 直接抛 "action.indexOf is not a function"，
		 * 而异常发生在 preventDefault 之前，于是表单照旧整页提交。
		 */
		var action = form.getAttribute( 'action' ) || '';
		// Options API only: admin-post.php savers download files or finish on a
		// screen of their own, so they keep navigating.
		if ( -1 === action.indexOf( 'options.php' ) ) {
			return null;
		}
		try {
			return new URL( action, window.location.href ).href;
		} catch ( error ) {
			return null;
		}
	}

	/**
	 * Is this URL the screen the operator is already looking at?
	 *
	 * @param {string} url
	 * @return {boolean}
	 */
	function sameScreen( url ) {
		try {
			var target = new URL( url, window.location.href );
			var here = new URLSearchParams( window.location.search );
			return target.pathname === window.location.pathname
				&& ( target.searchParams.get( 'page' ) || '' ) === ( here.get( 'page' ) || '' );
		} catch ( error ) {
			return false;
		}
	}

	function clearPendingScroll() {
		try {
			window.sessionStorage.removeItem( SAVE_SCROLL_KEY );
		} catch ( error ) {}
	}

	function initInPlaceSaves() {
		document.addEventListener( 'submit', function ( event ) {
			if ( event.defaultPrevented ) {
				return;
			}
			var form = event.target;
			var action = inPlaceAction( form );
			if ( null === action || ! canSaveInPlace() ) {
				return;
			}

			// A save that is already running must not stack a second request.
			if ( savingInPlace ) {
				event.preventDefault();
				return;
			}

			event.preventDefault();
			savingInPlace = true;

			var body = new window.FormData( form );
			var submitter = event.submitter;
			if ( submitter && submitter.name ) {
				body.append( submitter.name, submitter.value );
			}

			var released = busy( form, true );

			function fallback() {
				// The old path, warts and all — better than a button stuck on busy.
				savingInPlace = false;
				released();
				form.submit();
			}

			admin.api.post( action, body, {
				type: 'text',
				timeout: 30000,
				headers: { Accept: 'text/html' }
			} ).then( function ( result ) {
				if ( ! applySavedResult( form, result ) ) {
					fallback();
					return;
				}
				// Nothing navigated, so the offset stored for the reload has no
				// owner; leaving it would move the page on the NEXT load.
				clearPendingScroll();
				savingInPlace = false;
				released();
			}, function () {
				fallback();
			} );
		}, true );
	}

	/**
	 * Apply the document options.php would have navigated to.
	 *
	 * @param {Element} form
	 * @param {Object}  result { text, url } from admin.api with type "text".
	 * @return {boolean} False when the caller must let the browser navigate.
	 */
	function applySavedResult( form, result ) {
		var html = result && result.text ? result.text : '';
		if ( ! html || -1 === html.indexOf( 'sakura-admin' ) ) {
			return false;
		}
		// A redirect to another screen (page id changed) is a real move.
		if ( result.url && ! sameScreen( result.url ) ) {
			return false;
		}

		var doc = new window.DOMParser().parseFromString( html, 'text/html' );
		var fresh = doc.querySelector( '.sakura-admin[data-sakura-page]' ) || doc.querySelector( '.sakura-admin' );
		var root = document.querySelector( '.sakura-admin[data-sakura-page]' ) || document.querySelector( '.sakura-admin' );
		if ( ! fresh || ! root ) {
			return false;
		}

		// Page-level variables: page width, card radius / border / shadow.
		if ( fresh.hasAttribute( 'style' ) ) {
			root.setAttribute( 'style', fresh.getAttribute( 'style' ) );
		} else {
			root.removeAttribute( 'style' );
		}

		// Inline style blocks (grid gaps arrive through wp_add_inline_style).
		admin.qsa( 'style[id$="-inline-css"]', doc.head ).forEach( function ( block ) {
			var live = document.getElementById( block.id );
			if ( live && live.textContent !== block.textContent ) {
				live.textContent = block.textContent;
			}
		} );

		syncFormValues( form, doc );
		showSavedPrompt( doc, root );

		admin.events.emit( 'saved', { form: form, card: form.closest( '.card' ) } );

		return true;
	}

	/**
	 * Copy the stored control values back into the live form.
	 *
	 * The server answers with what it kept, not with what was typed: a page width
	 * of 3000 comes back as 1920, and a field left showing 3000 would describe a
	 * site that does not exist. Password and file inputs are skipped — a masked
	 * value would be saved back as the mask next time — and hidden inputs carry
	 * nonces, which stay valid.
	 *
	 * @param {Element} form Live form.
	 * @param {Document} doc  Parsed response.
	 */
	function syncFormValues( form, doc ) {
		var id = form.getAttribute( 'id' );
		var fresh = id ? doc.getElementById( id ) : null;
		if ( ! fresh || 'FORM' !== fresh.tagName ) {
			return;
		}

		var byName = {};
		admin.qsa( 'input[name], select[name], textarea[name]', fresh ).forEach( function ( control ) {
			var name = control.getAttribute( 'name' );
			byName[ name ] = byName[ name ] || [];
			byName[ name ].push( control );
		} );

		admin.qsa( 'input[name], select[name], textarea[name]', form ).forEach( function ( control ) {
			var type = ( control.type || '' ).toLowerCase();
			if ( 'password' === type || 'file' === type || 'hidden' === type ) {
				return;
			}
			var candidates = byName[ control.getAttribute( 'name' ) ] || [];
			var source = null;
			candidates.forEach( function ( candidate ) {
				if ( source ) {
					return;
				}
				if ( ( 'radio' === type || 'checkbox' === type ) && candidate.value !== control.value ) {
					return;
				}
				source = candidate;
			} );
			if ( ! source ) {
				return;
			}
			if ( 'checkbox' === type || 'radio' === type ) {
				control.checked = source.checked;
				return;
			}
			if ( 'select-one' === type || 'select-multiple' === type ) {
				control.value = source.value;
				return;
			}
			if ( control.value !== source.value ) {
				control.value = source.value;
			}
		} );
	}

	/**
	 * Mount the prompt the server printed for this save.
	 *
	 * The markup is the real one — tone, copy, focus target — so the in-place path
	 * cannot drift from what a reload would have shown.
	 *
	 * @param {Document} doc  Parsed response.
	 * @param {Element}  root Live page root.
	 */
	function showSavedPrompt( doc, root ) {
		closeSavedPrompt();

		var fresh = doc.getElementById( RESULT_MODAL_ID );
		if ( ! fresh ) {
			// Saved, but the saver wrote no settings error to report.
			toast( '已保存', 'ok' );
			return;
		}

		// A prompt rendered on the previous load may still sit in the DOM closed.
		var stale = document.getElementById( RESULT_MODAL_ID );
		if ( stale && stale.parentNode ) {
			stale.parentNode.removeChild( stale );
		}

		var node = document.importNode( fresh, true );
		// Autoshow means "open on page load"; this prompt is already opening, and
		// keeping the flag would open it a second time on the next initModals().
		node.removeAttribute( 'data-sakura-modal-autoshow' );
		root.appendChild( node );

		savePrompt = { node: node, handle: openModal( node, null ) };
	}

	function closeSavedPrompt() {
		if ( ! savePrompt ) {
			return;
		}
		if ( savePrompt.handle ) {
			savePrompt.handle.close();
		}
		if ( savePrompt.node && savePrompt.node.parentNode ) {
			savePrompt.node.parentNode.removeChild( savePrompt.node );
		}
		savePrompt = null;
	}

	/**
	 * Confirm links and forms marked with data-sakura-confirm before acting.
	 */
	function initConfirmations() {
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( '[data-sakura-confirm]' ) : null;
			if ( ! link ) {
				return;
			}

			event.preventDefault();
			var submitter  = event.target.closest ? event.target.closest( 'button, input[type="submit"], input[type="image"]' ) : null;
			var message    = link.getAttribute( 'data-sakura-confirm' ) || '';

			/*
			 * A marked element may carry more than one sentence: a list of
			 * consequences (R7/R10) and, for the irreversible case, the profile name
			 * the operator has to type. Both arrive as attributes so the server keeps
			 * owning the copy. A malformed list degrades to no list rather than
			 * throwing, because the prompt is the last thing before a destructive
			 * request and must still open.
			 */
			var items = null;
			var rawItems = link.getAttribute( 'data-sakura-confirm-items' ) || '';
			if ( rawItems ) {
				try {
					var parsed = JSON.parse( rawItems );
					if ( Array.isArray( parsed ) && parsed.length ) {
						items = parsed;
					}
				} catch ( error ) {
					items = null;
				}
			}

			confirm( message, {
				title: link.getAttribute( 'data-sakura-confirm-title' ) || '',
				items: items,
				requireText: link.getAttribute( 'data-sakura-require-text' ) || '',
				tone: 'danger',
				danger: true
			} ).then( function ( confirmed ) {
				if ( ! confirmed ) {
					return;
				}

				if ( 'FORM' === link.tagName ) {
					if ( link.requestSubmit ) {
						link.requestSubmit( submitter && submitter.form === link ? submitter : undefined );
					} else {
						link.submit();
					}
					return;
				}

				if ( link.href ) {
					window.location.href = link.href;
				}
			} );
		} );
	}

	/*
	 * There is deliberately no scroll lock for these prompts.
	 *
	 * The old implementation pinned <body> with position:fixed and a negative
	 * offset so the page could not scroll behind the dialog. It worked, and it
	 * still cost a full-page reflow: body leaves the flow, the document collapses
	 * to viewport height, the scrollbar vanishes and every centred column
	 * re-measures — one frame of a visibly jumping page. Without a scrim there is
	 * nothing to lock: the card floats over a page the operator can keep
	 * reading, scrolling and clicking, and the viewport never has to move.
	 */

	/**
	 * Play the exit and then hide a prompt.
	 *
	 * The exit is a short opacity fade, so "hidden" lands on animationend; a timer
	 * covers the cases where no animation runs at all (reduced motion, a card that
	 * was never painted). The page controllers that keep their own open state
	 * (storage-sync.js, storage-connection.js) close through this as well.
	 *
	 * @param {Element} modal Root .sakura-modal node.
	 * @return {void}
	 */
	function hideModal( modal ) {
		if ( ! modal || modal.hasAttribute( 'hidden' ) ) {
			return;
		}

		var hide = function () {
			modal.setAttribute( 'hidden', '' );
			modal.setAttribute( 'aria-hidden', 'true' );
			modal.classList.remove( 'is-closing' );
		};

		modal.setAttribute( 'aria-hidden', 'true' );

		/*
		 * The instant flag only suppresses the entrance animation. Dropping it here
		 * lets the exit fade play, so a prompt that arrived without motion still leaves
		 * without popping out of existence.
		 */
		modal.removeAttribute( 'data-sakura-modal-instant' );

		if ( reducedMotion() ) {
			hide();
			return;
		}

		modal.classList.add( 'is-closing' );

		// Guaranteed once-only: animationend fires per animated element.
		var finished = false;
		var finish = function () {
			if ( finished ) {
				return;
			}
			finished = true;
			modal.removeEventListener( 'animationend', finish );
			hide();
		};
		modal.addEventListener( 'animationend', finish );
		window.setTimeout( finish, 200 );
	}

	/** Whether the operator asked for reduced motion. */
	function reducedMotion() {
		return !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	/**
	 * Prompt dialog controller (.sakura-modal).
	 *
	 * Three ways out — the X, the acknowledge button, Escape — and none of them
	 * write anything. The dialog only reports. Focus goes to the heading on open
	 * and back to whatever had it before on close, so a keyboard operator ends up
	 * where they were, not at the top of the document.
	 */
	function openModal( modal, trigger ) {
		if ( ! modal || modal.getAttribute( 'data-sakura-modal-open' ) === '1' ) {
			return null;
		}

		var card    = admin.qs( '.sakura-modal__card', modal );
		var focusOn = admin.qs( '[data-sakura-modal-focus]', modal ) || card;
		var opener  = trigger || document.activeElement;

		/*
		 * Lift the prompt out of <main> before opening it.
		 *
		 * .sakura-admin__main plays a 180ms entrance that sets a transform, and a
		 * transformed ancestor becomes the containing block for position: fixed.
		 * A prompt echoed inside main (every save-result modal is) therefore parked
		 * itself on main's box instead of the viewport for those 180ms — the scrim
		 * stopped at the content gutters and the page looked like it had white bands
		 * on both sides. Moving the node to the page root once, on open, keeps the
		 * fixed positioning honest and costs nothing after the first time.
		 */
		var root = document.querySelector( '.sakura-admin' );
		if ( root && modal.parentNode !== root && modal.closest( '.sakura-admin__main' ) ) {
			root.appendChild( modal );
		}

		modal.removeAttribute( 'hidden' );
		modal.setAttribute( 'aria-hidden', 'false' );
		modal.setAttribute( 'data-sakura-modal-open', '1' );

		/*
		 * 2026-11-20：加了遮罩之后，点遮罩就该关——否则那层模糊只是挡住视线。
		 * 只认「点在容器本身上」的点击，卡片内部的点击照旧不触发。
		 */
		function onBackdrop( event ) {
			if ( event.target === modal ) {
				close();
			}
		}

		modal.addEventListener( 'click', onBackdrop );
		/*
		 * Nothing is locked and nothing is scrolled on open: without a scrim the page
		 * stays exactly where the operator left it, and a prompt that follows a click
		 * has no business moving the viewport at all.
		 *
		 * The one exception is a prompt that is part of a save round-trip, mounted by
		 * PHP and opened here while the document is still parsing. That document
		 * started at scroll 0, restoreScrollAfterSave() has already moved it back, but
		 * a late layout pass can still clamp the offset — so the pending value is
		 * re-applied here, before the card is painted, to keep the first frame honest.
		 * restoredOffset is cleared once this load is past its first frame (see
		 * admin.ready), so no later prompt inherits a stale position.
		 */
		var pending = readPendingScroll();
		if ( null === pending ) {
			pending = restoredOffset;
		}
		if ( null !== pending && pending > 0 && Math.round( window.pageYOffset || 0 ) !== pending ) {
			window.scrollTo( 0, pending );
		}

		if ( focusOn && typeof focusOn.focus === 'function' ) {
			window.setTimeout( function () {
				focusOn.focus( { preventScroll: true } );
			}, 40 );
		}

		function close() {
			if ( modal.getAttribute( 'data-sakura-modal-open' ) !== '1' ) {
				return;
			}
			modal.removeAttribute( 'data-sakura-modal-open' );
			modal.removeEventListener( 'click', onBackdrop );
			document.removeEventListener( 'keydown', onKeydown );

			/*
			 * Play the exit before hiding, so the card does not blink out of existence.
			 */
			hideModal( modal );

			// Only pull focus back when it is still inside the dialog; the operator may
			// have moved it to a control in the page behind it.
			if ( modal.contains( document.activeElement ) && document.activeElement !== document.body ) {
				document.activeElement.blur();
			}

			/*
			 * Autoshow prompts survive a post-and-redirect: the node that was the
			 * trigger belongs to the previous document, so focus would fall to <body>
			 * and a keyboard operator would restart at the top of the page. A
			 * data-sakura-modal-return selector names the control to come back to
			 * instead (the save button of the card that was submitted).
			 *
			 * The viewport never moved while the prompt was open, so handing focus back
			 * is the whole restore: preventScroll keeps the focus call itself from
			 * dragging the page to that control.
			 */
			var fallback = modal.getAttribute( 'data-sakura-modal-return' );
			// <body> is the "nothing was focused" placeholder, not an opener: on a
			// post-and-redirect prompt it is always what document.activeElement
			// reports, so treat it as absent and let the selector win.
			var useful = opener && opener !== document.body && document.contains( opener ) ? opener : null;
			var target = useful || ( fallback ? admin.qs( fallback ) : null );

			if ( target && typeof target.focus === 'function' ) {
				target.focus( { preventScroll: true } );
			}
		}

		function onKeydown( event ) {
			if ( 'Escape' !== event.key ) {
				return;
			}
			event.preventDefault();
			close();
		}

		modal.addEventListener( 'click', function ( event ) {
			var closer = event.target.closest ? event.target.closest( '[data-sakura-modal-close]' ) : null;
			if ( closer && modal.contains( closer ) ) {
				event.preventDefault();
				close();
			}
		} );

		document.addEventListener( 'keydown', onKeydown );

		return { close: close };
	}

	/**
	 * Close a <dialog> with its exit animation.
	 *
	 * `dialog.close()` is instant, so the card used to vanish between two frames —
	 * the abruptness people read as "old". Adding `.is-closing` lets CSS play a
	 * 140ms shrink-and-fade, then the attribute goes away. A timer backs up
	 * `animationend` because a dialog that is closed from a hidden tab never
	 * receives one and would stay on screen.
	 */
	function closeDialog( dialog, value ) {
		if ( ! dialog.open ) {
			return;
		}
		if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			dialog.close( value );
			return;
		}
		var done = false;
		var finish = function () {
			if ( done ) {
				return;
			}
			done = true;
			dialog.classList.remove( 'is-closing' );
			if ( dialog.open ) {
				dialog.close( value );
			}
		};
		dialog.classList.add( 'is-closing' );
		dialog.addEventListener( 'animationend', finish, { once: true } );
		window.setTimeout( finish, 220 );
	}

	/**
	 * Mount prompts that opt in with data-sakura-modal-autoshow.
	 *
	 * Opened synchronously, while the document is still parsing, so the card is part
	 * of the first painted frame. The old version waited 180ms + 120ms per extra
	 * prompt: the browser had long since painted the page without it, and the card
	 * then faded in over a screen that had already settled — which reads as a
	 * flash. The instant flag also drops the entrance animation: an autoshow prompt
	 * did not follow a click, it should simply be there.
	 */
	function initModals() {
		admin.qsa( '.sakura-modal[data-sakura-modal-autoshow="1"]' ).forEach( function ( modal ) {
			modal.setAttribute( 'data-sakura-modal-instant', '1' );
			var handle = openModal( modal, null );
			if ( RESULT_MODAL_ID === modal.id ) {
				// Printed by the server after a real navigation; the next in-place
				// save has to replace this one instead of stacking a second dialog.
				savePrompt = { node: modal, handle: handle };
			}
		} );
	}

	admin.ready( function () {
		/*
		 * Restore the post-save offset before anything else can move the viewport.
		 *
		 * This is the FALLBACK path. A card that opted into initInPlaceSaves() never
		 * navigates, so there is nothing to restore; the offset is only here for a
		 * form that still posts, and it cannot beat the first paint — footer scripts
		 * run at DOMContentLoaded, after the browser has already drawn the document
		 * at scroll 0. Measured on the demo site: fcp at y=0, offset applied at
		 * readyState=complete, ~70ms later. Do not describe this as "before the
		 * first frame"; it is not, and that is exactly why cards opt into in-place
		 * saving instead.
		 */
		restoreScrollAfterSave();

		labelTables( document );
		initConfirmations();
		// Writes the offset first: the in-place path clears it on success and needs
		// it intact when it falls back to a real submit.
		rememberScrollOnSave();
		initInPlaceSaves();
		initModals();

		/*
		 * The saved offset is spent: it was applied here and, if a prompt is part of
		 * this load, re-applied by openModal() before that card was painted. Keeping
		 * it any longer meant every later prompt — a save result, a connection test,
		 * a setup nudge — re-scrolled the page to the offset the operator happened to
		 * be at after their last save, yanking the content out from under the card.
		 * A prompt after the first frame must leave the viewport alone.
		 */
		restoredOffset = null;
	} );

	admin.ui = {
		toast: toast,
		confirm: confirm,
		busy: busy,
		copy: copy,
		labelTables: labelTables,
		mediaPreview: mediaPreview,
		openModal: openModal,
		hideModal: hideModal,
		/* 弹窗左上角那只小鲸鱼。页面自己拼 <dialog> 时用它，插件里因此只有
		 * 一种弹窗长相；返回的是已经带 .dialog__mascot 的节点。 */
		mascot: mascotSvg
	};
}( window, document ) );

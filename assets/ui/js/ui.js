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

			var title = document.createElement( 'h2' );
			title.className = 'dialog__title';
			title.textContent = settings.title || '请确认';
			body.appendChild( title );

			var text = document.createElement( 'p' );
			text.className = 'dialog__message';
			text.textContent = String( message || '' );
			body.appendChild( text );
			form.appendChild( body );

			var actions = document.createElement( 'div' );
			actions.className = 'dialog__actions';

			var cancel = document.createElement( 'button' );
			cancel.type = 'button';
			cancel.className = 'btn btn--ghost';
			cancel.textContent = settings.cancelText || '取消';
			cancel.addEventListener( 'click', function () {
				dialog.close( 'cancel' );
			} );

			var accept = document.createElement( 'button' );
			accept.type = 'button';
			accept.className = settings.danger ? 'btn btn--danger' : 'btn btn--primary';
			accept.textContent = settings.confirmText || '确认';
			accept.addEventListener( 'click', function () {
				dialog.close( 'confirm' );
			} );

			actions.appendChild( cancel );
			actions.appendChild( accept );
			form.appendChild( actions );
			dialog.appendChild( form );
			host().appendChild( dialog );

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
			accept.focus();
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
				dialog.close( 'close' );
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
	 * Keep the reader where they were across a save.
	 *
	 * Card forms post to options.php, WordPress redirects back, and the browser
	 * paints a fresh document at scroll 0 — so saving a card near the bottom throws
	 * the user back to the top even though the dialog itself is careful about
	 * position. Remember the offset before the POST and restore it on the next
	 * load of the same screen.
	 *
	 * Scoped per URL (path + page + tab) so a redirect to a different screen does
	 * not get the previous screen's offset, and cleared on read so a later manual
	 * refresh starts clean at the top.
	 */
	var SAVE_SCROLL_KEY = 'sakuraAdminSaveScroll';

	function screenKey() {
		var params = new URLSearchParams( window.location.search );
		return [
			window.location.pathname,
			params.get( 'page' ) || '',
			params.get( 't' ) || ''
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

	function restoreScrollAfterSave() {
		var target = readPendingScroll();
		if ( null === target ) {
			return;
		}

		function apply() {
			window.scrollTo( 0, target );
			try {
				window.sessionStorage.removeItem( SAVE_SCROLL_KEY );
			} catch ( error ) {}
		}

		/*
		 * When a prompt is about to open, it owns the viewport: it locks the page
		 * and later restores the offset it was given. Handing it this target (see
		 * openModal's pendingRestore) means the dialog closes onto the saved
		 * position, so restore here would race it. Otherwise, restore now.
		 */
		var prompt = admin.qs( '.sakura-modal[data-sakura-modal-autoshow="1"]' );
		if ( prompt ) {
			return;
		}

		apply();
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
			confirm( message, {
				title: link.getAttribute( 'data-sakura-confirm-title' ) || '',
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
	 * One scroll lock for every dialog in the plugin.
	 *
	 * `body { overflow: hidden }` is the tempting one-liner and it is wrong here:
	 * it removes the scrollbar, the viewport widens by that width, and every
	 * centred column on the page jumps a few pixels sideways the moment a dialog
	 * opens. Pinning the body with position:fixed and a negative offset keeps the
	 * layout exactly as it was; closing restores the inline styles and scrolls
	 * back to the pixel the user was on.
	 */
	var scrollLock = ( function () {
		var saved = null;

		function lock() {
			if ( saved || ! document.body ) {
				return;
			}
			var body = document.body;
			var y = window.pageYOffset || document.documentElement.scrollTop || 0;
			saved = {
				y: y,
				position: body.style.position,
				top: body.style.top,
				left: body.style.left,
				right: body.style.right,
				width: body.style.width
			};
			body.style.position = 'fixed';
			body.style.top = ( -y ) + 'px';
			body.style.left = '0';
			body.style.right = '0';
			body.style.width = '100%';
			document.documentElement.classList.add( 'sakura-scroll-locked' );
		}

		function unlock() {
			if ( ! saved || ! document.body ) {
				return;
			}
			var body = document.body;
			var y = saved.y;
			body.style.position = saved.position;
			body.style.top = saved.top;
			body.style.left = saved.left;
			body.style.right = saved.right;
			body.style.width = saved.width;
			saved = null;
			document.documentElement.classList.remove( 'sakura-scroll-locked' );
			// Chrome clamps scroll on a fixed body; putting it back explicitly is
			// the difference between "returned to the page" and "tossed to the top".
			window.scrollTo( 0, y );
		}

		return { lock: lock, unlock: unlock };
	}() );
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

		modal.removeAttribute( 'hidden' );
		modal.setAttribute( 'aria-hidden', 'false' );
		modal.setAttribute( 'data-sakura-modal-open', '1' );
		/*
		 * Recorded before the lock so the restore target is the user's own position.
		 *
		 * An autoshow prompt appears on a freshly loaded document, so the live offset
		 * is 0 even when the operator had scrolled far down the previous screen (the
		 * save round-trip). In that case the pending restore target is the honest
		 * answer, and it is also what the dialog must hand back on close.
		 */
		var pendingRestore = readPendingScroll();
		var currentY = Math.round( window.pageYOffset || 0 );
		modal.setAttribute( 'data-sakura-modal-scroll', String( pendingRestore !== null ? pendingRestore : currentY ) );
		scrollLock.lock();

		if ( focusOn && typeof focusOn.focus === 'function' ) {
			window.setTimeout( function () {
				focusOn.focus( { preventScroll: true } );
			}, 40 );
		}

		function close() {
			if ( modal.getAttribute( 'data-sakura-modal-open' ) !== '1' ) {
				return;
			}
			modal.setAttribute( 'hidden', '' );
			modal.setAttribute( 'aria-hidden', 'true' );
			modal.removeAttribute( 'data-sakura-modal-open' );
			document.removeEventListener( 'keydown', onKeydown );

			// Only pull focus back when it is still inside the dialog; the operator
			// may have tabbed to another control in the page behind it.
			if ( modal.contains( document.activeElement ) && document.activeElement !== document.body ) {
				document.activeElement.blur();
			}

			/*
			 * Autoshow prompts survive a post-and-redirect: the node that was the
			 * trigger belongs to the previous document, so focus would fall to
			 * <body> and a keyboard operator would restart at the top of the page.
			 * A data-sakura-modal-return selector names the control to come back to
			 * instead (the save button of the card that was submitted).
			 */
			var fallback = modal.getAttribute( 'data-sakura-modal-return' );
			// <body> is the "nothing was focused" placeholder, not an opener: on a
			// post-and-redirect prompt it is always what document.activeElement
			// reports, so treat it as absent and let the selector win.
			var useful = opener && opener !== document.body && document.contains( opener ) ? opener : null;
			var target = useful || ( fallback ? admin.qs( fallback ) : null );
			var restoreY = Number( modal.getAttribute( 'data-sakura-modal-scroll' ) );

			// Unlock first: the body is pinned while a dialog is open, so restoring
			// focus before this point would measure against the pinned layout.
			scrollLock.unlock();
			modal.removeAttribute( 'data-sakura-modal-scroll' );

			/*
			 * Focus, then put the scroll position back exactly.
			 *
			 * preventScroll keeps the focus call itself from jumping, but browsers
			 * still scroll when the focused element is off-screen in some paths, and
			 * the fixed-body unlock can shift things by a frame. Re-asserting the
			 * recorded offset after focusing is what makes "close the dialog" a
			 * no-op for the viewport: the user stays where they were reading.
			 */
			if ( target && typeof target.focus === 'function' ) {
				target.focus( { preventScroll: true } );
			}

			if ( isFinite( restoreY ) && Math.round( window.pageYOffset || 0 ) !== restoreY ) {
				window.scrollTo( 0, restoreY );
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
	 * Mount servers that opt in with data-sakura-modal-autoshow.
	 */
	function initModals() {
		admin.qsa( '.sakura-modal[data-sakura-modal-autoshow="1"]' ).forEach( function ( modal, index ) {
			// Stagger, so two prompts never fight for focus in the same frame.
			window.setTimeout( function () {
				openModal( modal, null );
			}, 180 + index * 120 );
		} );
	}

	admin.ready( function () {
		labelTables( document );
		initConfirmations();
		rememberScrollOnSave();
		initModals();
		restoreScrollAfterSave();
	} );

	admin.ui = {
		toast: toast,
		confirm: confirm,
		busy: busy,
		copy: copy,
		labelTables: labelTables,
		mediaPreview: mediaPreview,
		openModal: openModal,
		scrollLock: scrollLock
	};
}( window, document ) );

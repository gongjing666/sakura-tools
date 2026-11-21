/**
 * Image rules controller: compression-mode linkage and the crop scan refresh.
 * Every value it writes already exists in the server-rendered table; without
 * this file the page is still a complete settings form.
 *
 * @package Sakura_Image_Optimizer
 */
( function ( window, document ) {
	'use strict';

	var admin = window.SakuraAdmin;
	if ( ! admin ) {
		return;
	}

	var scan = admin.data.scan || {};
	var strings = scan.strings || {};
	var qs = admin.qs;
	var qsa = admin.qsa;

	function initModeLinkage() {
		var modes = qsa( 'input[name="sakura_imgopt_compression_mode"]' );
		var format = qs( '#sakura_imgopt_convert_format' );
		var quality = qs( '#sakura_imgopt_quality' );

		if ( ! modes.length || ! format || ! quality ) {
			return;
		}

		function fieldOf( control ) {
			return control.closest( '.field' ) || control.closest( 'tr' ) || control.parentNode;
		}

		function setDisabled( control, disabled ) {
			if ( ! control ) {
				return;
			}
			control.disabled = !! disabled;
			var field = fieldOf( control );
			if ( field ) {
				field.classList.toggle( 'is-disabled', !! disabled );
			}
		}

		function sync() {
			var checked = modes.filter( function ( input ) {
				return input.checked;
			} )[ 0 ];
			var mode = checked ? checked.value : '';
			setDisabled( format, mode === 'compress_only' );
			setDisabled( quality, mode === 'convert_only' );
		}

		modes.forEach( function ( input ) {
			admin.on( input, 'change', sync );
		} );
		sync();

		var form = format.closest( 'form' );
		if ( form ) {
			admin.on( form, 'submit', function () {
				format.disabled = false;
				quality.disabled = false;
			} );
		}
	}

	function initScan() {
		var button = qs( '#sakura-imgopt-scan-crop' );
		if ( ! button || ! scan.action || ! scan.ajaxUrl ) {
			return;
		}

		var result = qs( '#sakura-imgopt-scan-result' );
		var wpConfig = qs( '#sakura-imgopt-scan-wpconfig' );
		var warningsBox = qs( '#sakura-imgopt-scan-warnings' );
		var originalLabel = button.textContent;

		admin.on( button, 'click', function ( event ) {
			event.preventDefault();
			if ( button.disabled ) {
				return;
			}

			button.disabled = true;
			button.textContent = strings.scanning || '扫描中…';

			admin.api.ajax( scan.action, { nonce: scan.nonce }, { timeout: 45000 } ).then( function ( data ) {
				if ( ! data ) {
					throw new Error( 'empty' );
				}
				applyScan( data );
			} ).catch( function () {
				if ( result ) {
					result.textContent = strings.failed || '扫描失败，请重试。';
				}
			} ).finally( function () {
				button.disabled = false;
				button.textContent = originalLabel || strings.button || '扫描裁剪配置';
			} );
		} );

		function applyScan( data ) {
			var sizes = data.sizes || {};
			Object.keys( sizes ).forEach( function ( name ) {
				if ( name === '_big_threshold' ) {
					return;
				}
				updateRow( name, sizes[ name ] || {} );
			} );

			if ( data.big_threshold ) {
				updateBig( data.big_threshold );
			}
			if ( result ) {
				result.textContent = data.summary_text || '';
			}
			if ( wpConfig && data.wp_config_text ) {
				wpConfig.textContent = data.wp_config_text;
			}
			renderWarnings( data.warnings || [] );
		}

		function updateRow( name, row ) {
			var table = qs( 'table.sakura-imgopt-size-table' );
			var tr = table ? Array.prototype.slice.call( table.tBodies[ 0 ] ? table.tBodies[ 0 ].rows : [] ).filter( function ( item ) {
				return item.getAttribute( 'data-size' ) === String( name );
			} )[ 0 ] : null;

			if ( ! tr && table ) {
				tr = buildRow( name, row );
				( table.tBodies[ 0 ] || table ).appendChild( tr );
			}
			if ( ! tr ) {
				return;
			}

			var hasSize = ( row.width || 0 ) > 0 || ( row.height || 0 ) > 0;
			var dims = hasSize
				? ( ( row.width || 0 ) > 0 ? row.width : ( strings.auto || '' ) ) + ' × ' + ( ( row.height || 0 ) > 0 ? row.height : ( strings.auto || '' ) )
				: '—';
			write( tr.querySelector( "[data-field='dims']" ), dims );

			var status = tr.querySelector( "[data-field='status'] .sakura-imgopt-status" );
			if ( status ) {
				status.classList.remove( 'ok', 'warn', 'bad', 'conflict' );
				statusClass( row ).split( ' ' ).forEach( function ( token ) {
					if ( token ) {
						status.classList.add( token );
					}
				} );
				status.textContent = row.status_label || '';
				status.setAttribute( 'title', row.reason || '' );
			}

			write( tr.querySelector( "[data-field='crop']" ), hasSize ? ( row.crop ? ( strings.hardCrop || '' ) : ( strings.softCrop || '' ) ) : '—' );

			var sources = tr.querySelector( "[data-field='sources']" );
			if ( sources ) {
				sources.textContent = '';
				var labels = [];
				( row.sources || [] ).forEach( function ( source ) {
					if ( source && source.label && labels.indexOf( source.label ) === -1 ) {
						labels.push( source.label );
					}
				} );
				if ( labels.length ) {
					labels.forEach( function ( label ) {
						var tag = document.createElement( 'span' );
						tag.className = 'sakura-imgopt-source-tag';
						tag.textContent = label;
						sources.appendChild( tag );
					} );
				} else if ( row.registered ) {
					sources.textContent = strings.registeredNoSource || '';
				} else if ( row.status === 'disabled_by_plugin' || row.status === 'removed' ) {
					sources.textContent = '—';
				} else {
					sources.textContent = strings.unknownSource || '';
				}
			}
		}

		function buildRow( name, row ) {
			var tr = document.createElement( 'tr' );
			tr.setAttribute( 'data-size', name );

			var checkCell = document.createElement( 'td' );
			checkCell.className = 'sakura-imgopt-col-check';
			var checkbox = document.createElement( 'input' );
			checkbox.type = 'checkbox';
			checkbox.name = 'sakura_imgopt_disabled_sizes[]';
			checkbox.value = name;
			checkbox.checked = row.status === 'disabled_by_plugin';
			checkCell.appendChild( checkbox );
			tr.appendChild( checkCell );

			var nameCell = document.createElement( 'td' );
			var code = document.createElement( 'code' );
			code.textContent = name;
			nameCell.appendChild( code );
			tr.appendChild( nameCell );

			[ 'dims', 'status', 'crop', 'sources' ].forEach( function ( field ) {
				var cell = document.createElement( 'td' );
				cell.setAttribute( 'data-field', field );
				if ( field === 'status' ) {
					var span = document.createElement( 'span' );
					span.className = 'sakura-imgopt-status';
					cell.appendChild( span );
				}
				tr.appendChild( cell );
			} );

			return tr;
		}

		function updateBig( big ) {
			var table = qs( 'table.sakura-imgopt-size-table' );
			var tr = table ? Array.prototype.slice.call( table.querySelectorAll( 'tr[data-size]' ) ).filter( function ( item ) {
				return item.getAttribute( 'data-size' ) === '_big_threshold';
			} )[ 0 ] : null;
			if ( ! tr ) {
				return;
			}
			var status = tr.querySelector( '.sakura-imgopt-status' );
			if ( status ) {
				status.classList.remove( 'ok', 'warn' );
				status.classList.add( big.disabled ? 'warn' : 'ok' );
				status.setAttribute( 'title', big.reason || '' );
				status.textContent = big.status_label || ( big.disabled ? ( strings.disabled || '' ) : ( strings.enabled || '' ) );
			}
			write( tr.querySelector( "[data-field='sources']" ), big.source || '' );
		}

		function renderWarnings( warnings ) {
			if ( ! warningsBox ) {
				return;
			}
			var list = warningsBox.querySelector( 'ul' );
			if ( list ) {
				list.textContent = '';
				warnings.forEach( function ( warning ) {
					if ( warning && warning.message ) {
						var item = document.createElement( 'li' );
						item.textContent = warning.message;
						list.appendChild( item );
					}
				} );
			}
			warningsBox.hidden = warnings.length === 0;
		}
	}

	function statusClass( row ) {
		var name = 'ok';
		if ( [ 'disabled_by_plugin', 'removed', 'filtered' ].indexOf( row.status ) >= 0 ) {
			name = 'warn';
		} else if ( [ 'unregistered', 'unknown' ].indexOf( row.status ) >= 0 ) {
			name = 'bad';
		}
		if ( row.conflict ) {
			name += ' conflict';
		}
		return name;
	}

	function write( node, value ) {
		if ( node ) {
			node.textContent = value;
		}
	}

	/**
	 * 重命名规则编辑器：点击占位符卡片，按 `-` 拼到输入框末尾并刷新预览。
	 * 没有这段脚本，输入框仍然是完整的设置字段（服务端已经渲染好预览）。
	 */
	function initRenameComposer() {
		var box = qs( '[data-rule-composer]' );
		if ( ! box ) {
			return;
		}

		var input = qs( '.rule-composer__input', box );
		var preview = qs( '[data-rule-preview]', box );
		var reset = qs( '[data-rule-reset]', box );
		var buttons = qsa( '[data-rule-token]', box );
		var moreToggle = qs( '[data-rule-more-toggle]', box );
		var morePanel = qs( '[data-rule-more-panel]', box );

		if ( ! input ) {
			return;
		}

		var samples = {};
		buttons.forEach( function ( button ) {
			samples[ button.getAttribute( 'data-rule-token' ) ] = button.getAttribute( 'data-rule-sample' ) || '';
		} );

		function syncPreview() {
			if ( ! preview ) {
				return;
			}
			var value = input.value || '';
			// 与 PHP 的 prune_empty_tokens() 一致：值取不到的占位符（未填的固定文本、
			// 访客上传时的 {user}…）只吃掉它前面那一个连接符，再把留下的双分隔符
			// 收拢成一个——两侧都吃会把邻居粘成 `{hash}{filename}`。
			var pruned = false;
			Object.keys( samples ).forEach( function ( token ) {
				if ( '' !== samples[ token ] || -1 === value.indexOf( token ) ) {
					return;
				}
				var pattern = new RegExp( '[-_.]?' + token.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ), 'g' );
				value = value.replace( pattern, '' );
				pruned = true;
			} );
			if ( pruned ) {
				value = value.replace( /([-_.])[-_.]+/g, '$1' ).replace( /^[-_.]+|[-_.]+$/g, '' );
			}
			var name = value.replace( /\{[a-zA-Z0-9_]+\}/g, function ( match ) {
				return Object.prototype.hasOwnProperty.call( samples, match ) ? samples[ match ] : '';
			} );
			name = name.replace( /[\\/:*?"<>|]+/g, '-' ).replace( /^[\s\-_.]+|[\s\-_.]+$/g, '' );
			if ( ! name ) {
				name = 'sakura';
			}
			// 与 PHP 侧 pathinfo() 一致：没有点号时扩展名为空。
			var dot = name.lastIndexOf( '.' );
			var ext = dot >= 0 ? name.slice( dot + 1 ).toLowerCase() : '';
			if ( 'webp' !== ext ) {
				name += '.webp';
			}
			preview.textContent = name;
		}

		var titleAdd = box.getAttribute( 'data-rule-title-add' ) || '';
		var titleRemove = box.getAttribute( 'data-rule-title-remove' ) || '';

		/**
		 * 同步卡片的按下态：规则里已经出现的占位符按下去，再点一次移除。
		 * 手输的占位符也走这里，所以卡片状态永远跟输入框一致。
		 */
		function syncPressed() {
			var value = input.value || '';
			buttons.forEach( function ( button ) {
				var token = button.getAttribute( 'data-rule-token' ) || '';
				var used = '' !== token && -1 !== value.indexOf( token );
				button.setAttribute( 'aria-pressed', used ? 'true' : 'false' );

				// 自定义占位符的标题带当前值（或「还没填」），用服务端给的两条，
				// 别被通用的「点击插入/移除 {token}」盖掉。
				if ( button.hasAttribute( 'data-rule-fixed' ) ) {
					var fixedOn = button.getAttribute( 'data-rule-title-when-on' );
					var fixedOff = button.getAttribute( 'data-rule-title-when-off' );
					var fixedTitle = used ? fixedOn : fixedOff;
					if ( fixedTitle ) {
						button.title = fixedTitle;
					}
					return;
				}

				if ( titleAdd || titleRemove ) {
					button.title = ( used ? titleRemove : titleAdd ) + ' ' + token;
				}
			} );
		}

		function settle() {
			if ( typeof input.setSelectionRange === 'function' ) {
				input.setSelectionRange( input.value.length, input.value.length );
			}
			syncPreview();
			syncPressed();
		}

		function append( token ) {
			var value = input.value.trim();
			// 每个占位符只用一次：已经在规则里就不重复插入。
			if ( -1 !== value.indexOf( token ) ) {
				settle();
				return;
			}
			// {ext} 前面永远是点号，其余占位符之间用 `-`。
			var separator = '{ext}' === token ? '.' : '-';
			if ( ! value ) {
				input.value = token;
			} else if ( /[-_.]$/.test( value ) ) {
				input.value = value + token;
			} else {
				input.value = value + separator + token;
			}
			input.focus();
			settle();
		}

		/** 移除占位符：连同它一侧的连接符一起去掉，留下的分隔符收拢成一个。 */
		function remove( token ) {
			var next = ( input.value || '' ).split( token ).join( '' );
			next = next.replace( /([-_.])[-_.]+/g, '$1' ).replace( /^[-_.]+|[-_.]+$/g, '' );
			input.value = next;
			input.focus();
			settle();
		}

		admin.on( box, 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '[data-rule-token]' ) : null;
			if ( button && box.contains( button ) ) {
				event.preventDefault();
				var token = button.getAttribute( 'data-rule-token' ) || '';
				if ( ! token ) {
					return;
				}
				if ( 'true' === button.getAttribute( 'aria-pressed' ) ) {
					remove( token );
				} else {
					append( token );
				}
				return;
			}
			if ( moreToggle && event.target.closest( '[data-rule-more-toggle]' ) ) {
				event.preventDefault();
				var open = 'true' !== moreToggle.getAttribute( 'aria-expanded' );
				moreToggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				if ( morePanel ) {
					morePanel.hidden = ! open;
				}
				var labelOpen = moreToggle.getAttribute( 'data-rule-more-label-open' ) || '';
				var labelClosed = moreToggle.getAttribute( 'data-rule-more-label' ) || '';
				if ( labelOpen && labelClosed ) {
					moreToggle.textContent = open ? labelOpen : labelClosed;
				}
				return;
			}
			if ( reset && event.target.closest && event.target.closest( '[data-rule-reset]' ) ) {
				event.preventDefault();
				input.value = input.getAttribute( 'data-rule-default' ) || '{date}-{time}-{rand}';
				input.focus();
				syncPreview();
				syncPressed();
			}
		} );

		admin.on( input, 'input', function () {
			syncPreview();
			syncPressed();
		} );
		syncPreview();
		syncPressed();
	}

	admin.ready( function () {
		initModeLinkage();
		initScan();
		initRenameComposer();
	} );
}( window, document ) );

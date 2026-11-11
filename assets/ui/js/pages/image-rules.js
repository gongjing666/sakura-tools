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

	admin.ready( function () {
		initModeLinkage();
		initScan();
	} );
}( window, document ) );

/**
 * S3 connection screen: saved-profile shortcut, upload metrics refresh and the
 * setup reminder dialog.
 *
 * @package Sakura_Image_Optimizer
 */
( function ( window, document ) {
	'use strict';

	var admin = window.SakuraAdmin;
	if ( ! admin ) {
		return;
	}

	var dataNode = admin.qs( '[data-sakura-connection-strings]' );
	var config   = dataNode ? admin.parseJSON( dataNode.getAttribute( 'data-sakura-connection-strings' ), {} ) : {};
	var strings  = config.strings || {};

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var cursor = 0;

		return String( template === undefined || template === null ? '' : template ).replace( /%(\d+\$)?[sd]/g, function ( token, position ) {
			var value = position ? args[ parseInt( position, 10 ) - 1 ] : args[ cursor++ ];
			return value === undefined || value === null ? token : String( value );
		} );
	}

	function errorText( error, fallback ) {
		var payload = error && error.payload ? error.payload : null;
		if ( payload && payload.data && payload.data.message ) {
			return String( payload.data.message );
		}
		if ( payload && payload.message ) {
			return String( payload.message );
		}
		return error && error.message ? String( error.message ) : ( fallback || '' );
	}

	/**
	 * "Add profile" scrolls to the connection card and pre-ticks the
	 * save-as-new flag without touching the form values already typed.
	 */
	function initAddProfile( root ) {
		var button = admin.qs( '[data-sakura-action="add-profile"]', root );
		if ( ! button ) {
			return;
		}

		admin.on( button, 'click', function () {
			var form = admin.qs( '#sakura-s3-connection-form', root );
			var flag = form ? admin.qs( 'input[name="save_as_new"]', form ) : null;
			var name = form ? admin.qs( '#sakura-s3-profile-name', form ) : null;

			if ( flag ) {
				flag.checked = true;
			}
			if ( name ) {
				name.value = '';
			}

			var card = form ? admin.closest( form, '.card' ) : null;
			if ( card && card.scrollIntoView ) {
				card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
			window.setTimeout( function () {
				if ( name ) {
					name.focus( { preventScroll: true } );
				}
			}, 320 );

			if ( strings.profileCreated ) {
				admin.ui.toast( strings.profileCreated, 'info' );
			}
		} );
	}

	/**
	 * Upload throughput panel: manual refresh plus a quiet 30 second poll.
	 */
	function initMetrics( root ) {
		var panel = admin.qs( '#sakura-s3-metrics', root );
		if ( ! panel ) {
			return;
		}

		var button  = admin.qs( '#sakura-s3-metrics-refresh', panel );
		var spinner = admin.qs( '#sakura-s3-metrics-spinner', panel );
		var updated = admin.qs( '#sakura-s3-metrics-updated', panel );
		var nonce   = panel.getAttribute( 'data-nonce' ) || '';
		var pending = false;

		function field( id ) {
			return admin.qs( '#' + id, panel );
		}

		function setText( id, value ) {
			var node = field( id );
			if ( node ) {
				node.textContent = value;
			}
		}

		function apply( data ) {
			var today = data.today || {};
			var week  = data.week || {};
			var last  = data.last_error || {};

			setText( 'sakura-s3-metric-ok', admin.format.number( today.ok || 0 ) );
			setText( 'sakura-s3-metric-rate', format( strings.metricsRate, today.fail || 0, today.rate_text || '—' ) );
			setText( 'sakura-s3-metric-avg', today.avg_text || '—' );
			setText( 'sakura-s3-metric-max', format( strings.metricsMax, today.max_text || '—' ) );
			setText( 'sakura-s3-metric-bytes', today.bytes_text || '—' );
			setText( 'sakura-s3-metric-week', format( strings.metricsWeek, week.rate_text || '—' ) );

			var error = field( 'sakura-s3-metric-error' );
			if ( error ) {
				error.textContent = last.has ? String( last.message || '' ) : ( strings.metricsNoError || '' );
				error.title = last.has ? String( last.message || '' ) : '';
				var kpi = admin.closest( error, '.kpi' );
				if ( kpi ) {
					kpi.classList.toggle( 'kpi--bad', !! last.has );
				}
			}
			setText( 'sakura-s3-metric-error-ago', last.has ? String( last.ago || '' ) : '' );

			if ( updated ) {
				updated.textContent = data.updated && data.updated.ago
					? format( strings.metricsUpdated, data.updated.ago )
					: ( strings.metricsNever || '' );
			}
		}

		function refresh( manual ) {
			if ( pending ) {
				return;
			}
			pending = true;
			if ( spinner ) {
				spinner.hidden = false;
			}
			if ( button ) {
				button.disabled = true;
			}

			admin.api.ajax( 'sakura_s3_upload_metrics', { _ajax_nonce: nonce }, { timeout: 15000 } )
				.then( function ( data ) {
					apply( data || {} );
				} )
				.catch( function ( error ) {
					if ( manual ) {
						admin.ui.toast( errorText( error, strings.metricsFailed ), 'bad' );
					}
				} )
				.then( function () {
					pending = false;
					if ( spinner ) {
						spinner.hidden = true;
					}
					if ( button ) {
						button.disabled = false;
					}
				} );
		}

		if ( button ) {
			admin.on( button, 'click', function () {
				refresh( true );
			} );
		}

		window.setInterval( function () {
			if ( ! document.hidden ) {
				refresh( false );
			}
		}, 30000 );
	}

	/**
	 * Setup reminder dialog rendered by Config_Notice. The markup is shared
	 * with the legacy screen, so the controller only owns the behaviour.
	 */
	function initConfigDialog( root ) {
		var modal = admin.qs( '#sakura-s3-config-modal', root ) || admin.qs( '#sakura-s3-config-modal' );
		if ( ! modal ) {
			return;
		}

		var card       = admin.qs( '.sakura-modal__card', modal );
		var focusFirst = admin.qs( '[data-config-focus]', modal );
		var primary    = admin.qs( '[data-config-primary]', modal );
		var dontRemind = admin.qs( '[data-config-dont-remind]', modal );
		var skipKey    = 'sakuraS3ConfigSkipOnce';
		var lastFocus  = null;
		var sent       = false;
		var open       = false;

		/* Only "do not remind me again" is remembered; every other close is
		 * throwaway, so an unhandled setup problem is asked about again. */
		function persistChoice() {
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
			persistChoice();
			modal.hidden = true;
			modal.setAttribute( 'aria-hidden', 'true' );
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
			modal.hidden = false;
			modal.setAttribute( 'aria-hidden', 'false' );
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

		if ( primary ) {
			admin.on( primary, 'click', function ( event ) {
				var href = primary.getAttribute( 'href' ) || '';
				if ( 0 !== href.indexOf( '#' ) ) {
					close();
					try {
						window.sessionStorage.setItem( skipKey, '1' );
					} catch ( error ) {}
					return;
				}

				event.preventDefault();
				close();

				var target = document.getElementById( href.slice( 1 ) );
				if ( ! target ) {
					return;
				}
				if ( target.scrollIntoView ) {
					target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}

				window.setTimeout( function () {
					var inputs = admin.qsa( 'input, select, textarea', target ).filter( function ( input ) {
						if ( input.disabled || input.readOnly ) {
							return false;
						}
						if ( 'hidden' === input.type || 'checkbox' === input.type || 'radio' === input.type ) {
							return false;
						}
						return 'SELECT' === input.tagName || 'TEXTAREA' === input.tagName || ! input.value;
					} );
					var first = inputs.filter( function ( input ) {
						return ! input.value;
					} )[ 0 ] || inputs[ 0 ] || admin.qs( 'input, select, textarea', target );
					if ( first ) {
						first.focus( { preventScroll: true } );
					}
				}, 360 );
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

			var focusable = admin.qsa( 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', card );
			if ( ! focusable.length ) {
				return;
			}
			var first = focusable[ 0 ];
			var last  = focusable[ focusable.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		} );

		var skip = false;
		try {
			skip = '1' === window.sessionStorage.getItem( skipKey );
			if ( skip ) {
				window.sessionStorage.removeItem( skipKey );
			}
		} catch ( error ) {
			skip = false;
		}

		if ( ! skip ) {
			window.setTimeout( show, 220 );
		}
	}

	admin.ready( function () {
		var root = admin.root();
		if ( ! root ) {
			return;
		}
		initAddProfile( root );
		initMetrics( root );
		initConfigDialog( root );
	} );
}( window, document ) );

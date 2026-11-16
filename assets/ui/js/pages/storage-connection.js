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

	/*
	 * Vendors append a tail that is identical for every bucket on the account, so
	 * it says nothing about which bucket this is. Tencent COS names read
	 * "<custom-name>-<10-digit APPID>"; keeping the APPID turns sakura-clover into
	 * "sakura-c…4331" and drops the only informative part. Mirrors
	 * Sakura\ImageOptimizer\S3\Bucket_Label::AUTO_SUFFIX so the chip and the
	 * saved card agree.
	 */
	var BUCKET_AUTO_SUFFIX = {
		tencent: /-\d{10}$/
	};

	function stripBucketSuffix( value, provider ) {
		var pattern = BUCKET_AUTO_SUFFIX[ String( provider || '' ) ];
		if ( ! pattern ) {
			return value;
		}

		var stripped = value.replace( pattern, '' );
		return stripped || value;
	}

	/**
	 * Shorten a long bucket id the way the PHP side does, so the chip, the card
	 * and the status line all read the same. Full value stays in title.
	 */
	function shortBucket( bucket, provider ) {
		var value = stripBucketSuffix( String( bucket === undefined || bucket === null ? '' : bucket ), provider );
		if ( value.length <= 22 ) {
			return value;
		}

		return value.slice( 0, 8 ) + '\u2026' + value.slice( -4 );
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
			// Shared exit from ui.js, so both storage pages close the same way.
			admin.ui.hideModal( modal );
			// The page behind the card never moved, so focus is the only thing to restore.
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
			// Mounted and opened in the same frame, so there is no entrance animation.
			modal.setAttribute( 'data-sakura-modal-instant', '1' );
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

		/* Opened synchronously, before the first paint: waiting 220ms let the browser
		 * paint the page without the card and then drop it on top, which reads as a
		 * flash. */
		if ( ! skip ) {
			show();
		}
	}

	/**
	 * Saved configs for the provider currently picked, as one-click chips.
	 *
	 * Loading a chip is deliberately inert: it only fills the fields already on
	 * screen (and the hidden profile_id, so the save writes to that entry) and
	 * never posts, never switches the active profile and never touches the
	 * stored option. The operator still has to press 保存连接配置, which is what
	 * keeps this from becoming a second, silent switcher next to the
	 * 已保存的配置 card.
	 */
	function initProviderProfiles( root ) {
		var select = admin.qs( '[data-sakura-provider-select]', root );
		var wrap   = admin.qs( '[data-sakura-provider-profiles]', root );
		var list   = admin.qs( '[data-sakura-provider-profile-list]', root );
		var form   = admin.qs( '#sakura-s3-connection-form', root );
		var profiles = config.profiles || [];
		if ( ! select || ! wrap || ! list || ! form ) {
			return;
		}

		var idField = admin.qs( 'input[name="profile_id"]', form );
		var loaded  = '';

		function field( id ) {
			return document.getElementById( id );
		}

		function setValue( id, value ) {
			var node = field( id );
			if ( node ) {
				node.value = value;
			}
		}

		/*
		 * Remember which bucket the space name belongs to, so that editing the
		 * bucket by hand clears a name that no longer applies instead of
		 * relabelling the new target with the old space.
		 */
		function setSpace( value, bucket ) {
			var node = field( 'sakura-s3-space' );
			if ( ! node ) {
				return;
			}
			node.value = value || '';
			if ( node.value && bucket ) {
				node.dataset.bucket = String( bucket );
			} else {
				delete node.dataset.bucket;
			}
		}

		/*
		 * Credentials are never sent to the browser, so a loaded config keeps the
		 * secret fields blank — save() reads that as "keep what this profile
		 * already has" and leaves the stored ciphertext alone.
		 */
		function load( profile ) {
			var provider = field( 'sakura-s3-provider' );
			if ( provider && profile.provider && provider.value !== profile.provider ) {
				provider.value = profile.provider;
				// Re-run the per-provider hints through their own change handler.
				provider.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
			}
			setValue( 'sakura-s3-bucket', profile.bucket || '' );
			setSpace( profile.space || '', profile.bucket || '' );
			setValue( 'sakura-s3-region', profile.region || '' );
			setValue( 'sakura-s3-endpoint', profile.endpoint || '' );
			setValue( 'sakura-s3-profile-name', profile.name || '' );
			setValue( 'sakura-s3-path', profile.upload_path || '' );
			setValue( 'sakura-s3-url', profile.upload_url_path || '' );

			var key    = field( 'sakura-s3-key' );
			var secret = field( 'sakura-s3-secret' );
			if ( key ) {
				key.value = '';
			}
			if ( secret ) {
				secret.value = '';
			}

			if ( idField ) {
				idField.value = profile.id || '';
			}

			var saveAsNew = admin.qs( 'input[name="save_as_new"]', form );
			if ( saveAsNew ) {
				saveAsNew.checked = false;
			}

			loaded = profile.id || '';
			render();
			// The provider-switch guard re-reads the profile id this line wrote.
			admin.events.emit( 'profile-load', profile );
			admin.ui.toast( strings.profileLoadedKey || '', 'info' );
		}

		function render() {
			var provider = select.value;
			var matches  = profiles.filter( function ( profile ) {
				return profile.saved && profile.provider === provider;
			} );

			list.textContent = '';
			if ( ! matches.length ) {
				wrap.hidden = true;
				return;
			}

			matches.forEach( function ( profile ) {
				var chip = document.createElement( 'button' );
				chip.type = 'button';
				chip.className = 'profile-chip' + ( profile.id === loaded ? ' is-loaded' : '' );
				chip.setAttribute( 'role', 'listitem' );
				chip.title = profile.bucket || '';

				var name = document.createElement( 'span' );
				name.className = 'profile-chip__name';
				name.textContent = profile.name || profile.id;
				chip.appendChild( name );

				/*
				 * Space name when the provider reports one (DogeCloud), otherwise the
				 * shortened bucket: a 40-character id is unreadable at chip size.
				 * Auto-generated names already end with the space, so skip the
				 * second line instead of printing the same word twice.
				 */
				var metaText = profile.space || shortBucket( profile.bucket, profile.provider ) || profile.endpoint || '';
				if ( metaText && name.textContent.indexOf( metaText ) === -1 ) {
					var meta = document.createElement( 'span' );
					meta.className = 'profile-chip__meta';
					meta.textContent = metaText;
					chip.appendChild( meta );
				}

				chip.addEventListener( 'click', function () {
					load( profile );
				} );

				list.appendChild( chip );
			} );

			wrap.hidden = false;
		}

		admin.on( select, 'change', render );
		render();
	}

	/**
	 * A save under another provider is a new configuration, not an edit.
	 *
	 * The form posts the profile id it is showing, so switching the picker and
	 * pressing save used to rewrite that profile under a provider it was never
	 * configured for — the old connection vanished. The server now mints a new
	 * profile in that case, and this keeps the form honest about it: the
	 * save-as-new flag is ticked and disabled (it cannot be turned off, because
	 * the save would ignore it) together with a line explaining why.
	 *
	 * A form that is not editing a saved profile has nothing to overwrite, so
	 * the guard stays out of the way entirely.
	 */
	function initProviderSwitchGuard( root ) {
		var form   = admin.qs( '#sakura-s3-connection-form', root );
		var select = admin.qs( '[data-sakura-provider-select]', root );
		var flag   = form ? admin.qs( 'input[name="save_as_new"]', form ) : null;
		var idField = form ? admin.qs( 'input[name="profile_id"]', form ) : null;

		if ( ! form || ! select || ! flag || ! idField ) {
			return;
		}

		var note = admin.qs( '[data-sakura-auto-new-text]', form );
		var box  = admin.qs( '[data-sakura-auto-new-note]', form );
		var profiles = config.profiles || [];
		// Null means "this save is not a provider switch": either the form edits
		// no saved profile, or it edits one whose provider is the selected one.
		var restore = null;

		function savedProvider() {
			var id = idField.value;
			if ( ! id ) {
				return '';
			}

			for ( var index = 0; index < profiles.length; index++ ) {
				if ( profiles[ index ] && profiles[ index ].id === id ) {
					return String( profiles[ index ].provider || '' );
				}
			}

			return '';
		}

		function setGuarded( on ) {
			if ( on ) {
				if ( null === restore ) {
					restore = flag.checked;
				}
				flag.checked = true;
				flag.disabled = true;
			} else if ( null !== restore ) {
				flag.disabled = false;
				flag.checked = restore;
				restore = null;
			}

			if ( box ) {
				box.hidden = ! on;
			}
			if ( on && note ) {
				note.textContent = strings.providerSwitchNote || '';
			}
		}

		function sync() {
			var saved = savedProvider();
			setGuarded( '' !== saved && saved !== select.value );
		}

		admin.on( select, 'change', sync );
		// Loading a saved config rewrites the profile id and the flag first, so the
		// decision from the previous profile no longer applies.
		admin.events.on( 'profile-load', function () {
			restore = null;
			flag.disabled = false;
			sync();
		} );
		/*
		 * "Add profile" pre-ticks the flag without loading anything, so a later
		 * switch back must not undo it: the previous state is "another profile,
		 * please", not whatever was in the box beforehand.
		 */
		admin.delegate( root, 'click', '[data-sakura-action="add-profile"]', function () {
			restore = true;
			sync();
		} );

		sync();
	}

	/**
	 * Drop the remembered space name as soon as the bucket stops being the one
	 * it described.
	 *
	 * The name belongs to exactly one s3Bucket: keeping it while the operator
	 * pastes a different bucket would label the new target with the old space,
	 * and the status card would lie about where uploads go. Typing the bucket
	 * by hand therefore clears it, while loading a saved config or pressing
	 * 获取 SDK 参数 sets both together.
	 */
	function initSpaceTracking( root ) {
		var bucket = admin.qs( '#sakura-s3-bucket', root );
		var space  = admin.qs( '#sakura-s3-space', root );
		if ( ! bucket || ! space ) {
			return;
		}

		// The server renders the stored pair together; record it so the first
		// keystroke in the bucket field can tell it apart from a fresh value.
		if ( space.value && bucket.value ) {
			space.dataset.bucket = bucket.value;
		}

		admin.on( bucket, 'input', function () {
			// A chip load or a resolver fill records which bucket the name was
			// captured for; once the field no longer holds that bucket the name
			// describes nothing, so it goes away.
			if ( space.value && space.dataset.bucket !== bucket.value ) {
				space.value = '';
				delete space.dataset.bucket;
			}
		} );
	}

	/**
	 * Per-provider hints for bucket / region / endpoint.
	 *
	 * A provider's console names things differently from what the S3 endpoint
	 * actually wants (多吉云 shows a storage-space name but the client needs
	 * s3Bucket; its API host answers 404 because it is not an S3 endpoint), so
	 * the copy follows the selection instead of only the saved value.
	 */
	function initProviderHints( root ) {
		var select = admin.qs( '[data-sakura-provider-select]', root );
		var hints  = config.hints || {};
		if ( ! select ) {
			return;
		}

		var fields = [
			{ key: 'bucket', id: 'sakura-s3-bucket' },
			{ key: 'region', id: 'sakura-s3-region' },
			{ key: 'endpoint', id: 'sakura-s3-endpoint' }
		];

		function apply() {
			var set = hints[ select.value ] || hints.default || {};
			fields.forEach( function ( field ) {
				var hint = document.getElementById( field.id + '-hint' );
				if ( hint && set[ field.key ] ) {
					hint.textContent = String( set[ field.key ] );
				}
			} );

			/*
			 * The bucket field is a custom control, so #id lands on the <input>
			 * and the label is still emitted with for="#id" — relabel it here so
			 * 多吉云 reads 存储空间名称 without a second hard-coded string.
			 */
			var label = admin.qs( 'label[for="sakura-s3-bucket"]', root );
			if ( label && set.bucket_label ) {
				label.textContent = String( set.bucket_label );
			}

			var endpoint = document.getElementById( 'sakura-s3-endpoint' );
			if ( endpoint && set.placeholder ) {
				endpoint.setAttribute( 'placeholder', String( set.placeholder ) );
			}

			/*
			 * The resolver button only exists for 多吉云: everywhere else the row
			 * stays a plain text field, and with no JavaScript driving it the
			 * button stays hidden rather than appearing dead.
			 */
			var resolve = admin.qs( '[data-sakura-doge-resolve]', root );
			if ( resolve ) {
				resolve.hidden = 'dogecloud' !== select.value;
			}
		}

		admin.on( select, 'change', apply );
		apply();
	}

	/**
	 * 多吉云 hands out an opaque s3Bucket and s3Endpoint that do not appear as
	 * the storage-space name shown in its console, so copying them by hand is
	 * both tedious and easy to get wrong. The resolver calls the account's
	 * temporary-credential endpoint through admin-ajax and writes the pair back
	 * into the form; the operator still has to press 保存连接配置, and nothing
	 * here touches a stored profile.
	 */
	function initDogeResolve( root ) {
		var button = admin.qs( '[data-sakura-doge-resolve]', root );
		var modal  = admin.qs( '#sakura-s3-doge-modal', root );
		if ( ! button || ! modal ) {
			return;
		}

		var list = admin.qs( '[data-sakura-doge-spaces]', modal );
		var dialog = null;
		var pending = false;

		function field( id ) {
			return document.getElementById( id );
		}

		function fill( match ) {
			if ( ! match ) {
				return;
			}
			var bucket   = field( 'sakura-s3-bucket' );
			var endpoint = field( 'sakura-s3-endpoint' );
			var region   = field( 'sakura-s3-region' );
			var space    = field( 'sakura-s3-space' );

			if ( bucket && match.bucket ) {
				bucket.value = String( match.bucket );
			}
			/*
			 * The console name travels with the bucket: the 40-character s3Bucket is
			 * what the SDK needs, but "nboke" is what the operator recognises, so it
			 * is saved alongside and shown by the status and profile cards.
			 */
			if ( space ) {
				space.value = match.space ? String( match.space ) : '';
				if ( space.value && match.bucket ) {
					space.dataset.bucket = String( match.bucket );
				} else {
					delete space.dataset.bucket;
				}
			}
			if ( endpoint && match.endpoint ) {
				endpoint.value = String( match.endpoint );
			}
			if ( region && match.region ) {
				region.value = String( match.region );
			}
		}

		function choice( entry ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'doge-space';

			var name = document.createElement( 'span' );
			name.className = 'doge-space__name';
			name.textContent = String( entry.space );

			var hint = document.createElement( 'span' );
			hint.className = 'doge-space__hint';
			hint.textContent = String( entry.bucket );

			button.appendChild( name );
			button.appendChild( hint );
			admin.on( button, 'click', function () {
				fill( entry );
				if ( dialog ) {
					dialog.close();
					dialog = null;
				}
				admin.ui.toast( strings.dogeFilled || '', 'ok' );
			} );

			return button;
		}

		/*
		 * A space is identified by the name the operator types, so the picker
		 * only exists for the awkward case: several spaces and no name to
		 * disambiguate them. One match is written straight back.
		 */
		function show( result ) {
			if ( 'choose' !== result.code || ! list ) {
				if ( result.match ) {
					fill( result.match );
					admin.ui.toast( strings.dogeFilled || result.message || '', 'ok' );
				} else if ( result.message ) {
					admin.ui.toast( String( result.message ), 'warn', { duration: 0 } );
				}
				return;
			}

			while ( list.firstChild ) {
				list.removeChild( list.firstChild );
			}
			( result.choices || [] ).forEach( function ( entry ) {
				list.appendChild( choice( entry ) );
			} );

			dialog = admin.ui.openModal( modal, button );
		}

		function request() {
			var key    = field( 'sakura-s3-key' );
			var secret = field( 'sakura-s3-secret' );
			var space  = field( 'sakura-s3-bucket' );

			pending = true;
			admin.ui.busy( button, true );

			return admin.api.ajax(
				'sakura_s3_doge_buckets',
				{
					_ajax_nonce: strings.dogeNonce || '',
					space: space ? space.value : '',
					key: key ? key.value : '',
					secret: secret ? secret.value : ''
				},
				{ timeout: 20000 }
			).then( show ).catch( function ( error ) {
				admin.ui.toast( errorText( error, strings.dogeFailed || '' ), 'bad', { duration: 0 } );
			} ).finally( function () {
				pending = false;
				admin.ui.busy( button, false );
			} );
		}

		/*
		 * The button stays usable: a 多吉云 account can add a space between two
		 * attempts, and busy() already blocks a double click while the request
		 * is in flight.
		 */
		admin.on( button, 'click', function () {
			if ( pending ) {
				return;
			}
			request();
		} );
	}

	/*
	 * Pre-save probe. The card can only report a verdict after a profile
	 * exists, which costs a save, a failed upload and a cleanup when the
	 * endpoint is mistyped, so this button tests the values currently in the
	 * form: admin-ajax runs the same probe the save path uses and hands back
	 * the provider's own error code. Blank credentials fall back to the saved
	 * pair on the server, matching the "留空保持不变" placeholders.
	 */
	function initProbe( root ) {
		var button = admin.qs( '#sakura-s3-test-connection', root );
		var modal  = admin.qs( '#sakura-s3-probe-modal', root );
		if ( ! button || ! modal ) {
			return;
		}

		var icon      = admin.qs( '.sakura-modal__icon', modal );
		var titleNode = admin.qs( '[data-sakura-modal-focus]', modal );
		var descNode  = admin.qs( '.sakura-modal__desc, [id$="-desc"]', modal );
		var pending   = false;

		/* Same shapes Field_View::select() draws, so the chip keeps its tone. */
		var ICONS = {
			ok: '<path d="M5 12.5 9.5 17 19 7"/>',
			bad: '<path d="M7 7l10 10M17 7 7 17"/>',
			warn: '<path d="M12 4.5 21 19.5H3L12 4.5Z"/><path d="M12 10v4.2"/><path d="M12 17.1v.1"/>'
		};

		function field( id ) {
			return document.getElementById( id );
		}

		function value( id ) {
			var node = field( id );
			return node ? node.value : '';
		}

		function paint( tone, heading, message ) {
			if ( icon ) {
				icon.className = 'sakura-modal__icon sakura-modal__icon--' + tone;
				icon.innerHTML = '<svg viewBox="0 0 24 24" width="25" height="25" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ( ICONS[ tone ] || ICONS.warn ) + '</svg>';
			}
			if ( titleNode ) {
				titleNode.textContent = String( heading );
			}
			if ( descNode ) {
				descNode.textContent = String( message );
				descNode.hidden = '' === String( message );
			}
		}

		function show( result ) {
			var ok = result && result.ok;
			paint(
				ok ? 'ok' : 'bad',
				ok ? ( strings.probeTitleOk || '' ) : ( strings.probeTitleBad || '' ),
				( result && result.message ) || ( ok ? ( strings.probeOk || '' ) : ( strings.probeFailed || '' ) )
			);
			admin.ui.openModal( modal, button );
		}

		function request() {
			var provider = admin.qs( '[data-sakura-provider-select]', root );

			pending = true;
			admin.ui.busy( button, true );

			return admin.api.ajax(
				'sakura_s3_probe_connection',
				{
					_ajax_nonce: strings.probeNonce || '',
					provider: provider ? provider.value : '',
					bucket: value( 'sakura-s3-bucket' ),
					region: value( 'sakura-s3-region' ),
					endpoint: value( 'sakura-s3-endpoint' ),
					key: value( 'sakura-s3-key' ),
					secret: value( 'sakura-s3-secret' )
				},
				{ timeout: 30000 }
			).then( show ).catch( function ( error ) {
				paint( 'bad', strings.probeTitleBad || '', errorText( error, strings.probeFailed || '' ) );
				admin.ui.openModal( modal, button );
			} ).finally( function () {
				pending = false;
				admin.ui.busy( button, false );
			} );
		}

		admin.on( button, 'click', function () {
			if ( pending ) {
				return;
			}
			request();
		} );
	}
	admin.ready( function () {
		var root = admin.root();
		if ( ! root ) {
			return;
		}
		initAddProfile( root );
		initMetrics( root );
		initConfigDialog( root );
		initDogeResolve( root );
		initProbe( root );
		initProviderHints( root );
		initProviderProfiles( root );
		initProviderSwitchGuard( root );
		initSpaceTracking( root );
	} );
}( window, document ) );

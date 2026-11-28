/**
 * The S3 storage page.
 *
 * The top bar and both tab rows are printed by PHP and never re-drawn. This
 * component owns the page head and the five cards, and keeps them in step with
 * the server: every mutation answers with the recalculated status, alerts and
 * the card sections it touched, and the answer is merged in place rather than
 * triggering a reload.
 */
import { Fragment, useCallback, useRef, useState } from 'react';
import { Dialog } from '../shared/Dialog.jsx';
import { post } from '../shared/transport.js';
import { PageHead, Prompt } from '../shared/ui.jsx';
import ErrorBoundary from '../shared/error-boundary.jsx';
import BehaviourCard from './behaviour.jsx';
import ConnectionCard from './connection.jsx';
import MetricsCard from './metrics.jsx';
import MonitoringCard from './monitoring.jsx';
import ProfilesCard from './profiles.jsx';

/** A probe is "fast" below this many milliseconds, same threshold as the old page. */
const SLOW_MS = 800;

/**
 * One card from the ordered list, by id.
 *
 * @param {Array}  cards Card list from the payload.
 * @param {string} id    Card id.
 * @return {Object} Card data, or an empty object when the payload lacks it.
 */
function cardById( cards, id ) {
	return ( Array.isArray( cards ) ? cards : [] ).find( ( card ) => card.id === id ) || {};
}

/**
 * @param {Object} props      Boot payload.
 * @param {Object} props.boot App_Manifest::payload( 'storage-connection' ).
 * @return {JSX.Element} Page.
 */
export default function App( { boot } ) {
	const [ payload, setPayload ] = useState( boot );
	const [ head, setHead ] = useState( boot.head );
	const [ notice, setNotice ] = useState( null );
	const [ dialog, setDialog ] = useState( null );
	const [ dialogBusy, setDialogBusy ] = useState( false );
	const [ busyProfile, setBusyProfile ] = useState( '' );
	const [ savingCard, setSavingCard ] = useState( '' );
	const [ resetToken, setResetToken ] = useState( 0 );
	const promptFocus = useRef( null );

	const strings = payload.strings || {};
	const kickers = strings.kickers || {};
	const rest = payload.rest || {};
	const cards = Array.isArray( payload.cards ) ? payload.cards : [];
	const profiles = cardById( cards, 'profiles' );
	const connection = cardById( cards, 'connection' );
	const behaviour = cardById( cards, 'behaviour' );
	const metrics = cardById( cards, 'upload-metrics' );
	const monitoring = cardById( cards, 'monitoring' );

	/**
	 * Fold the server's recalculated pieces back into the page.
	 *
	 * Writes answer with sections keyed by card id, so the card list is replaced
	 * item by item instead of growing a second copy of the data at page level.
	 *
	 * @param {Object} next Response from any storage route.
	 * @return {void}
	 */
	const merge = useCallback( ( next ) => {
		if ( ! next ) {
			return;
		}

		const sections = next.sections || {};

		setPayload( ( current ) => ( {
			...current,
			cards: ( current.cards || [] ).map( ( card ) => ( sections[ card.id ] ? sections[ card.id ] : card ) ),
			alerts: Array.isArray( next.alerts ) ? next.alerts : current.alerts,
			// The redirect notice belongs to the request that printed the page; a
			// write from this screen supersedes it.
			notice: null,
		} ) );

		if ( next.status ) {
			setHead( ( current ) => ( { ...current, status: { ...current.status, ...next.status } } ) );
		}
	}, [] );

	const showNotice = ( result ) => {
		setNotice( result || null );
	};

	/**
	 * Save one card.
	 *
	 * @param {string} card Card id (connection | behaviour).
	 * @param {Object} body Form values.
	 * @return {Promise<void>}
	 */
	const saveCard = async ( card, body ) => {
		promptFocus.current = document.querySelector( '[data-sakura-card="' + card + '"] button[type="submit"]' );
		setSavingCard( card );

		try {
			const answer = await post( rest, '/cards/' + card, body, strings );

			merge( answer );

			if ( answer.notice ) {
				showNotice( answer.notice );
			}
		} catch ( failure ) {
			showNotice( {
				tone: 'bad',
				kicker: kickers.storage,
				title: strings.saveFailed,
				message: failure.message,
			} );
		} finally {
			setSavingCard( '' );
		}
	};

	/**
	 * Turn storage on, off, or move it to the clicked profile.
	 *
	 * @param {Object} item Profile row.
	 * @return {Promise<void>}
	 */
	const toggle = async ( item ) => {
		const off = profiles.enabled && ( item.live || item.current );

		setBusyProfile( item.id );

		try {
			const answer = await post( rest, '/profile', {
				profile_id: item.id,
				intent: off ? 'off' : 'on',
			}, strings );

			merge( answer );
			showNotice( {
				tone: 'ok',
				kicker: kickers.storage,
				title: off ? strings.toggledOff : strings.toggledOn,
				message: '',
			} );

			if ( answer.switch ) {
				setDialog( { kind: 'switch', data: answer.switch } );
			}
		} catch ( failure ) {
			showNotice( {
				tone: 'bad',
				kicker: kickers.storage,
				title: strings.toggleFailed,
				message: failure.message,
			} );
		} finally {
			setBusyProfile( '' );
		}
	};

	/**
	 * Ask for confirmation, then delete one profile.
	 *
	 * @param {Object} item Profile row.
	 * @return {void}
	 */
	const remove = ( item ) => {
		const confirm = item.delete.confirm;

		setDialog( {
			kind: 'delete',
			item,
			title: confirm.title,
			message: confirm.text,
			items: confirm.items,
			requireText: confirm.require,
		} );
	};

	const deleteProfile = async ( item ) => {
		setDialogBusy( true );

		try {
			const answer = await post( rest, '/profile/delete', { profile_id: item.id }, strings );

			merge( answer );
			setDialog( null );
			showNotice( {
				tone: 'ok',
				kicker: kickers.storage,
				title: answer.message,
				message: '',
			} );
		} catch ( failure ) {
			setDialog( null );
			showNotice( {
				tone: 'bad',
				kicker: kickers.storage,
				title: strings.saveFailed,
				message: failure.message,
			} );
		} finally {
			setDialogBusy( false );
		}
	};

	/**
	 * Read one probe verdict into the prompt.
	 *
	 * @param {Object} verdict { ok, message, latency }.
	 * @param {string} title   Heading for a successful probe.
	 * @return {void}
	 */
	const showProbe = ( verdict, title ) => {
		const latency = Number( verdict.latency || 0 );
		let message = verdict.message || '';

		if ( verdict.ok ) {
			const template = latency < SLOW_MS ? strings.probeFast : strings.probeSlow;

			message = template.replace( '%s', String( latency ) );
		} else if ( ! message ) {
			message = strings.probeFailed;
		}

		showNotice( {
			tone: verdict.ok ? 'ok' : 'bad',
			kicker: kickers.storage,
			title: verdict.ok ? title : strings.probeTitleBad,
			message,
		} );
	};

	const probeForm = async ( body ) => {
		try {
			showProbe( await post( rest, '/probe', body, strings ), strings.probeTitleOk );
		} catch ( failure ) {
			showProbe( { ok: false, message: failure.message, latency: 0 }, '' );
		}
	};

	const probeProfile = async ( item ) => {
		setBusyProfile( item.id );

		try {
			showProbe(
				await post( rest, '/probe/profile', { profile_id: item.id }, strings ),
				strings.probeProfileTitle
			);
		} catch ( failure ) {
			showProbe( { ok: false, message: failure.message, latency: 0 }, '' );
		} finally {
			setBusyProfile( '' );
		}
	};

	/**
	 * Resolve 多吉云 SDK parameters and hand the chosen entry back to the form.
	 *
	 * @param {Object}   values { space, key, secret }.
	 * @param {Function} apply  Receives the resolved entry.
	 * @return {Promise<void>}
	 */
	const resolveDoge = async ( values, apply ) => {
		try {
			const answer = await post( rest, '/doge-buckets', values, strings );
			const data = answer.data || {};

			if ( data.ok && data.match && data.match.bucket ) {
				apply( data.match );
				showNotice( {
					tone: 'ok',
					kicker: kickers.doge,
					title: strings.dogeFilled,
					message: data.message || '',
				} );

				return;
			}

			if ( data.choices && data.choices.length ) {
				setDialog( { kind: 'doge', message: data.message || '', choices: data.choices, apply } );

				return;
			}

			showNotice( {
				tone: 'bad',
				kicker: kickers.doge,
				title: strings.dogeFailed,
				message: data.message || '',
			} );
		} catch ( failure ) {
			showNotice( {
				tone: 'bad',
				kicker: kickers.doge,
				title: strings.dogeFailed,
				message: failure.message,
			} );
		}
	};

	const askRisk = ( accept, cancel ) => {
		setDialog( {
			kind: 'risk',
			items: strings.riskAckItems || [],
			accept,
			cancel,
		} );
	};

	const addProfile = () => {
		setResetToken( ( current ) => current + 1 );

		const card = document.querySelector( '[data-sakura-card="connection"]' );

		if ( card ) {
			card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	};

	/**
	 * The open dialog, if any. Results go through Prompt; this is only for the
	 * decisions and choices the cards cannot make on their own.
	 *
	 * @return {JSX.Element|null} Dialog.
	 */
	const renderDialog = () => {
		if ( ! dialog ) {
			return null;
		}

		if ( 'switch' === dialog.kind ) {
			const data = dialog.data;

			return (
				<Dialog
					tone={ data.tone }
					kicker={ data.kicker }
					title={ data.title }
					message={ data.message }
					items={ data.items }
					confirmLabel={ data.button || strings.closeDialog }
					onConfirm={ () => setDialog( null ) }
					onClose={ () => setDialog( null ) }
				/>
			);
		}

		if ( 'delete' === dialog.kind ) {
			return (
				<Dialog
					tone="bad"
					kicker={ kickers.storage }
					title={ dialog.title }
					message={ dialog.message }
					items={ dialog.items }
					requireText={ dialog.requireText }
					confirmLabel={ strings.confirmDelete }
					confirmVariant="primary"
					cancelLabel={ strings.cancel }
					busy={ dialogBusy }
					onConfirm={ () => deleteProfile( dialog.item ) }
					onClose={ () => setDialog( null ) }
				/>
			);
		}

		if ( 'risk' === dialog.kind ) {
			return (
				<Dialog
					tone="warn"
					kicker={ kickers.behaviourRisk }
					title={ strings.riskAckTitle }
					message={ strings.riskAckText }
					items={ dialog.items }
					confirmLabel={ strings.riskAckAccept }
					cancelLabel={ strings.cancel }
					onConfirm={ () => {
						const accept = dialog.accept;

						setDialog( null );
						accept();
					} }
					onClose={ () => {
						const cancel = dialog.cancel;

						setDialog( null );
						if ( cancel ) {
							cancel();
						}
					} }
				/>
			);
		}

		if ( 'doge' === dialog.kind ) {
			return (
				<Dialog
					tone="info"
					kicker={ kickers.doge }
					title={ strings.dogePick }
					message={ dialog.message }
					confirmLabel={ strings.cancel }
					cancelLabel=""
					onConfirm={ () => setDialog( null ) }
					onClose={ () => setDialog( null ) }
				>
					<div className="doge-spaces">
						{ dialog.choices.map( ( entry ) => (
							<button
								type="button"
								className="doge-space"
								key={ entry.bucket }
								onClick={ () => {
									dialog.apply( entry );
									setDialog( null );
									showNotice( {
										tone: 'ok',
										kicker: kickers.doge,
										title: strings.dogeFilled,
										message: '',
									} );
								} }
							>
								<span className="doge-space__name">{ entry.space }</span>
								<span className="doge-space__hint">{ entry.bucket }</span>
							</button>
						) ) }
					</div>
				</Dialog>
			);
		}

		return null;
	};

	return (
		<Fragment>
			<PageHead head={ head } />

			<div className="page-body">
				{ payload.notice && payload.notice.message ? (
					<div
						className={ 'notice-inline notice-inline--' + ( payload.notice.tone || 'info' ) }
						role="status"
					>
						<p>{ payload.notice.message }</p>
					</div>
				) : null }

				{ payload.alerts && payload.alerts.length ? (
					<div className="system-note" data-tone="info">
						{ payload.alerts.map( ( alert, index ) => (
							<p key={ index } data-tone={ alert.tone }>
								<strong>{ alert.title }：</strong>
								{ alert.message }
							</p>
						) ) }
					</div>
				) : null }

				<div className="card-grid" data-sakura-layout={ payload.page.id }>
					<ErrorBoundary>
						<ProfilesCard
							card={ profiles }
							busy={ busyProfile }
							onAdd={ addProfile }
							onToggle={ toggle }
							onDelete={ remove }
							onProbe={ probeProfile }
						/>
					</ErrorBoundary>
					<ErrorBoundary>
						<ConnectionCard
							card={ connection }
							strings={ strings }
							saving={ 'connection' === savingCard }
							resetToken={ resetToken }
							onSave={ ( body ) => saveCard( 'connection', body ) }
							onProbe={ probeForm }
							onDoge={ resolveDoge }
						/>
					</ErrorBoundary>
					<ErrorBoundary>
						<BehaviourCard
							card={ behaviour }
							strings={ strings }
							saving={ 'behaviour' === savingCard }
							onSave={ ( body ) => saveCard( 'behaviour', body ) }
							askRisk={ askRisk }
						/>
					</ErrorBoundary>
					<ErrorBoundary>
						<MetricsCard section={ metrics } rest={ rest } nonce={ rest.nonce } />
					</ErrorBoundary>
					<ErrorBoundary>
						<MonitoringCard
							section={ monitoring }
							rest={ rest }
							nonce={ rest.nonce }
							onSaved={ ( next ) => merge( next ) }
						/>
					</ErrorBoundary>
				</div>
			</div>

			{ notice ? (
				<Prompt
					notice={ {
						...notice,
						closeLabel: strings.closeLabel,
						closeIcon: strings.closeIcon,
						mascot: strings.mascot,
						button: strings.closeDialog,
					} }
					onClose={ () => setNotice( null ) }
					returnFocus={ promptFocus }
				/>
			) : null }

			{ renderDialog() }
		</Fragment>
	);
}

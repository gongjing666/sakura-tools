/**
 * The S3 storage page.
 *
 * The top bar and both tab rows are printed by PHP and never re-drawn. This
 * component owns the page head and the five cards, and keeps them in step with
 * the server: every mutation answers with the recalculated status, alerts and
 * section it touched, and the answer is merged in place rather than triggering
 * a reload.
 */
import { Fragment, useCallback, useState } from 'react';
import { PageHead } from '../shared/ui.jsx';
import BehaviourCard from './behaviour.jsx';
import ConnectionCard from './connection.jsx';
import Dialog from './dialog.jsx';
import MetricsCard from './metrics.jsx';
import MonitoringCard from './monitoring.jsx';
import ProfilesCard from './profiles.jsx';
import { call } from './transport.js';

/** A probe is "fast" below this many milliseconds, same threshold as the old page. */
const SLOW_MS = 800;

/**
 * @param {Object} props      Boot payload.
 * @param {Object} props.boot Storage_Payload::build() output.
 * @return {JSX.Element} Page.
 */
export default function App( { boot } ) {
	const [ payload, setPayload ] = useState( boot );
	const [ head, setHead ] = useState( boot.head );
	const [ notice, setNotice ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ dialog, setDialog ] = useState( null );
	const [ dialogBusy, setDialogBusy ] = useState( false );
	const [ busyProfile, setBusyProfile ] = useState( '' );
	const [ savingCard, setSavingCard ] = useState( '' );
	const [ resetToken, setResetToken ] = useState( 0 );

	const strings = payload.strings;
	const rest = payload.rest;

	/**
	 * Fold the server's recalculated pieces back into the page.
	 *
	 * @param {Object} next Response from any storage route.
	 * @return {void}
	 */
	const merge = useCallback( ( next ) => {
		setPayload( ( current ) => ( {
			...current,
			status: next.status || current.status,
			alerts: Array.isArray( next.alerts ) ? next.alerts : current.alerts,
			profiles: next.profiles || current.profiles,
			connection: next.connection || current.connection,
			behaviour: next.behaviour || current.behaviour,
			notice: next.notice || null,
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
		setSavingCard( card );
		setError( '' );

		try {
			const answer = await call( rest, strings, '/cards/' + card, body );

			merge( answer );

			if ( answer.result ) {
				showNotice( answer.result );
			}
		} catch ( failure ) {
			showNotice( {
				tone: 'bad',
				kicker: 'OBJECT STORAGE',
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
		const off = payload.profiles.enabled && ( item.live || item.current );

		setBusyProfile( item.id );

		try {
			const answer = await call( rest, strings, '/profile', {
				profile_id: item.id,
				intent: off ? 'off' : 'on',
			} );

			merge( answer );
			showNotice( {
				tone: 'ok',
				kicker: 'OBJECT STORAGE',
				title: off ? strings.toggledOff : strings.toggledOn,
				message: '',
			} );

			if ( answer.switch ) {
				setDialog( { kind: 'switch', data: answer.switch } );
			}
		} catch ( failure ) {
			showNotice( {
				tone: 'bad',
				kicker: 'OBJECT STORAGE',
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
			const answer = await call( rest, strings, '/profile/delete', { profile_id: item.id } );

			merge( answer );
			setDialog( null );
			showNotice( {
				tone: 'ok',
				kicker: 'OBJECT STORAGE',
				title: answer.message,
				message: '',
			} );
		} catch ( failure ) {
			setDialog( null );
			showNotice( {
				tone: 'bad',
				kicker: 'OBJECT STORAGE',
				title: strings.saveFailed,
				message: failure.message,
			} );
		} finally {
			setDialogBusy( false );
		}
	};

	/**
	 * Read one probe verdict into the dialog.
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

		setDialog( {
			kind: 'result',
			tone: verdict.ok ? 'ok' : 'bad',
			title: verdict.ok ? title : strings.probeTitleBad,
			message,
		} );
	};

	const probeForm = async ( body ) => {
		try {
			showProbe( await call( rest, strings, '/probe', body ), strings.probeTitleOk );
		} catch ( failure ) {
			showProbe( { ok: false, message: failure.message, latency: 0 }, '' );
		}
	};

	const probeProfile = async ( item ) => {
		setBusyProfile( item.id );

		try {
			showProbe(
				await call( rest, strings, '/probe/profile', { profile_id: item.id } ),
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
			const answer = await call( rest, strings, '/doge-buckets', values );
			const data = answer.data || {};

			if ( data.ok && data.match && data.match.bucket ) {
				apply( data.match );
				showNotice( {
					tone: 'ok',
					kicker: 'DOGECLOUD',
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
				kicker: 'DOGECLOUD',
				title: strings.dogeFailed,
				message: data.message || '',
			} );
		} catch ( failure ) {
			showNotice( {
				tone: 'bad',
				kicker: 'DOGECLOUD',
				title: strings.dogeFailed,
				message: failure.message,
			} );
		}
	};

	const askRisk = ( accept ) => {
		setDialog( {
			kind: 'risk',
			items: strings.riskAckItems || [],
			accept,
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
	 * The open dialog, if any.
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
					kicker="OBJECT STORAGE"
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
					kicker="BEHAVIOUR / RISK"
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
					onClose={ () => setDialog( null ) }
				/>
			);
		}

		if ( 'doge' === dialog.kind ) {
			return (
				<Dialog
					tone="info"
					kicker="DOGECLOUD"
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
										kicker: 'DOGECLOUD',
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

		return (
			<Dialog
				tone={ dialog.tone || 'info' }
				kicker="OBJECT STORAGE"
				title={ dialog.title }
				message={ dialog.message }
				confirmLabel={ strings.closeDialog }
				onConfirm={ () => setDialog( null ) }
				onClose={ () => setDialog( null ) }
			/>
		);
	};

	return (
		<Fragment>
			<PageHead head={ head } />

			<div className="page-body">
				{ error ? (
					<div className="system-note" role="alert">
						<p>{ error }</p>
					</div>
				) : null }

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
					<ProfilesCard
						card={ payload.profiles }
						busy={ busyProfile }
						onAdd={ addProfile }
						onToggle={ toggle }
						onDelete={ remove }
						onProbe={ probeProfile }
					/>
					<ConnectionCard
						card={ payload.connection }
						strings={ strings }
						saving={ 'connection' === savingCard }
						resetToken={ resetToken }
						onSave={ ( body ) => saveCard( 'connection', body ) }
						onProbe={ probeForm }
						onDoge={ resolveDoge }
					/>
					<BehaviourCard
						card={ payload.behaviour }
						strings={ strings }
						saving={ 'behaviour' === savingCard }
						onSave={ ( body ) => saveCard( 'behaviour', body ) }
						askRisk={ askRisk }
					/>
					<MetricsCard section={ payload.metrics } rest={ rest } nonce={ rest.nonce } />
					<MonitoringCard
						section={ payload.monitoring }
						rest={ rest }
						nonce={ rest.nonce }
						onSaved={ ( next ) => merge( next ) }
					/>
				</div>
			</div>

			{ notice ? (
				<Dialog
					tone={ notice.tone }
					kicker={ notice.kicker }
					title={ notice.title }
					message={ notice.message }
					confirmLabel={ strings.closeDialog }
					onConfirm={ () => setNotice( null ) }
					onClose={ () => setNotice( null ) }
				/>
			) : null }

			{ renderDialog() }
		</Fragment>
	);
}

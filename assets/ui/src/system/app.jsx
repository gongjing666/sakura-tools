/**
 * System settings page.
 *
 * Owns the page head, the readouts and the one writable card; the top bar and
 * the tab strip are printed by PHP and are deliberately not re-drawn here —
 * they are the same on every page of the module, and replacing them on mount
 * is what readers saw as the bar flickering. Saving goes through the REST
 * route instead of options.php, so the document is never rebuilt.
 */
import { Fragment, useCallback, useEffect, useRef, useState } from 'react';

import {
	BusyLabel,
	Card,
	ChecksTable,
	ExtensionGrid,
	Field,
	KpiBoard,
	PageHead,
	Prompt,
	RuntimeTable,
} from './components.jsx';
import { applyCanvas, draftFromFields, normalizeLayout, saveLayout } from './boot.js';

function sameValues( left, right ) {
	return Object.keys( left ).every( ( key ) => String( left[ key ] ) === String( right[ key ] ) );
}

export default function App( { boot } ) {
	const cards = Object.fromEntries( ( boot.cards || [] ).map( ( card ) => [ card.id, card ] ) );
	const layoutCard = cards.layout;
	const [ draft, setDraft ] = useState( () => draftFromFields( layoutCard.fields ) );
	const [ saved, setSaved ] = useState( () => draftFromFields( layoutCard.fields ) );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const saveRef = useRef( null );
	const dirty = ! sameValues( draft, saved );

	// Paint the canvas with whatever the server just confirmed. The first run
	// re-applies the same values the inline style already carries, which is
	// harmless and keeps one code path for "before" and "after" a save.
	useEffect( () => {
		applyCanvas( saved );
	}, [ saved ] );

	const changeField = useCallback( ( key, value ) => {
		setDraft( ( current ) => ( { ...current, [ key ]: value } ) );
	}, [] );

	const save = useCallback( async () => {
		if ( saving || ! dirty ) {
			return;
		}

		setSaving( true );

		try {
			const result = await saveLayout( boot.rest, draft );
			const next = normalizeLayout( result.layout );

			setSaved( next );
			setDraft( next );
			// ui.js records the offset on every options.php submit in case the
			// browser navigates. This page never navigates, so the record would
			// only linger and be replayed by some later reload of this screen.
			sessionStorage.removeItem( 'sakuraAdminSaveScroll' );
			setNotice( {
				...boot.strings.savedNotice,
				...result.notice,
				closeLabel: boot.strings.close,
				closeIcon: boot.strings.closeIcon,
				mascot: boot.strings.mascot,
				button: boot.strings.dismiss,
			} );
		} catch ( error ) {
			setNotice( {
				...boot.strings.failedNotice,
				message: error && error.message ? String( error.message ) : boot.strings.failedNotice.message,
				closeLabel: boot.strings.close,
				closeIcon: boot.strings.closeIcon,
				mascot: boot.strings.mascot,
				button: boot.strings.dismiss,
			} );
		} finally {
			setSaving( false );
		}
	}, [ boot, dirty, draft, saving ] );

	const submit = ( event ) => {
		event.preventDefault();
		save();
	};

	return (
		<Fragment>
			<PageHead head={ boot.head } />

			<div className="page-body">
					<KpiBoard score={ boot.data.score } kpis={ boot.data.kpis } />

					<div className="card-grid" data-sakura-layout="system-settings" data-sakura-group="main">
						<Card card={ cards.runtime }>
							<RuntimeTable card={ cards.runtime } />
						</Card>

						<Card card={ cards.engine }>
							<RuntimeTable card={ cards.engine } />
						</Card>

						<Card card={ cards.checks }>
							<ChecksTable card={ cards.checks } />
						</Card>

						<Card card={ cards.extensions }>
							<ExtensionGrid card={ cards.extensions } />
						</Card>

						<Card card={ layoutCard } titleProps={ { tabIndex: -1, 'data-sakura-return-target': '' } }>
							<form className="card__form" id="sakura-card-form-layout" onSubmit={ submit }>
								<div className="field-list">
									{ layoutCard.fields.map( ( field ) => (
										<Fragment key={ field.id }>
											{ field.group ? (
												<p className="system-field-group">{ field.group }</p>
											) : null }
											<Field
												field={ field }
												value={ draft[ field.key ] }
												onChange={ changeField }
												disabled={ saving }
											/>
										</Fragment>
									) ) }
								</div>
								<p className="system-note">{ layoutCard.note }</p>
								<div className="card__savebar">
									<button
										type="submit"
										className="btn btn--primary"
										ref={ saveRef }
										disabled={ ! dirty }
										aria-busy={ saving ? 'true' : undefined }
										aria-disabled={ saving ? 'true' : undefined }
									>
										<BusyLabel
											idle={ layoutCard.save }
											active={ boot.strings.saving }
											busy={ saving }
										/>
									</button>
								</div>
							</form>
						</Card>
					</div>
				</div>

			{ notice ? (
				<Prompt
					notice={ notice }
					onClose={ () => setNotice( null ) }
					returnFocus={ saveRef }
				/>
			) : null }
		</Fragment>
	);
}

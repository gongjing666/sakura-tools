/**
 * The image optimisation page — rules tab.
 *
 * Same shape as the system page: the top bar, the tab strip and the page head come
 * from PHP (`Shell::header_data()` / `Page::head_data()`), so the first paint and
 * the island cannot disagree. Each card saves with one POST and the document never
 * navigates.
 */

import { useEffect, useState } from 'react';
import { BusyLabel, Card, PageHead, Prompt, Tabs, Topbar } from '../shared/ui.jsx';
import { ImageField, RuleComposer, SizeTable } from './fields.jsx';

/**
 * Compare plain JSON option values (strings, numbers, booleans, string arrays).
 *
 * @param {*} a Left.
 * @param {*} b Right.
 * @return {boolean} Equal.
 */
function same( a, b ) {
	return JSON.stringify( a ?? null ) === JSON.stringify( b ?? null );
}

/**
 * One JSON call; every failure surfaces as one Chinese sentence.
 *
 * @param {Object} rest    {url, nonce}.
 * @param {Object} strings UI strings.
 * @param {string} path    Relative path below /image/rules.
 * @param {Object} [body]  JSON body; omitted for GET.
 * @return {Promise<Object>} Parsed body.
 */
async function call( rest, strings, path, body ) {
	let response;

	try {
		response = await fetch( rest.url + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': rest.nonce,
				...( body ? { 'Content-Type': 'application/json' } : {} ),
			},
			body: body ? JSON.stringify( body ) : undefined,
		} );
	} catch ( error ) {
		throw new Error( strings.network );
	}

	let payload = null;

	try {
		payload = await response.json();
	} catch ( error ) {
		payload = null;
	}

	if ( ! response.ok ) {
		throw new Error( ( payload && payload.message ) || strings.failed );
	}

	return payload || {};
}

/**
 * One card: own draft, own dirty flag, own save button.
 */
function SettingsCard( { card, rest, strings, sections, scanned, scan, scanning, onError, onNotice } ) {
	const [ draft, setDraft ] = useState( card.values );
	const [ saved, setSaved ] = useState( card.values );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		setSaved( card.values );
		setDraft( card.values );
	}, [ card.values ] );

	const dirty = ! same( draft, saved );
	const change = ( key, value ) => setDraft( ( current ) => ( { ...current, [ key ]: value } ) );

	const submit = async ( event ) => {
		event.preventDefault();

		if ( saving || ! dirty ) {
			return;
		}

		setSaving( true );
		onError( '' );

		try {
			const payload = await call( rest, strings, `/cards/${ card.id }`, { fields: draft } );
			const next = payload.values || draft;

			setSaved( next );
			setDraft( next );

			if ( payload.notice ) {
				onNotice( payload.notice );
			}
		} catch ( failure ) {
			onError( failure.message );
		} finally {
			setSaving( false );
		}
	};

	return (
		<Card card={ card }>
			<form className="card__form" id={ card.form.id } onSubmit={ submit }>
				<div className="field-list">
					{ card.fields.map( ( field ) => {
						if ( 'size-table' === field.type ) {
							return (
								<SizeTable
									key={ field.name }
									field={ field }
									section={ sections.thumbs }
									disabled={ draft.disabled_sizes }
									onChange={ change }
									scanned={ scanned }
									onScan={ scan }
									scanning={ scanning }
									scanLabel={ strings.scan }
									scanningLabel={ strings.scanning }
								/>
							);
						}

						if ( 'rule-composer' === field.type ) {
							return (
								<RuleComposer
									key={ field.name }
									field={ field }
									section={ sections.rename }
									rule={ draft.rename_rule || '' }
									fixed={ draft.rename_fixed || '' }
									onRule={ ( value ) => change( 'rename_rule', value ) }
								/>
							);
						}

						return (
							<ImageField
								key={ field.name }
								field={ field }
								value={ draft[ field.name ] }
								values={ draft }
								onChange={ change }
							/>
						);
					} ) }
				</div>
				{ card.note && card.note.length ? (
					<div className="system-note">
						{ card.note.map( ( line, index ) => (
							<p key={ index }>{ line }</p>
						) ) }
					</div>
				) : null }
				<div className="card__savebar">
					<button
						type="submit"
						className="btn btn--primary"
						disabled={ ! dirty }
						aria-busy={ saving ? 'true' : undefined }
						aria-disabled={ saving ? 'true' : undefined }
					>
						<BusyLabel idle={ card.form.save } active={ strings.saving } busy={ saving } />
					</button>
				</div>
			</form>
		</Card>
	);
}

/**
 * Page root.
 */
export default function App( { boot } ) {
	const { chrome, head, strings, rest } = boot;
	const sections = boot.sections || {};
	const cards = boot.cards || [];
	const [ notice, setNotice ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ scanned, setScanned ] = useState( null );
	const [ scanning, setScanning ] = useState( false );

	const scan = async () => {
		setScanning( true );
		setError( '' );

		try {
			setScanned( await call( rest, strings, '/scan', {} ) );
		} catch ( failure ) {
			setError( failure.message );
		} finally {
			setScanning( false );
		}
	};

	return (
		<>
			<Topbar chrome={ chrome } />
			<Tabs chrome={ chrome } />
			<main className="sakura-admin__main">
				<PageHead head={ head } />
				<div className="page-body">
					{ error ? (
						<div className="system-note" role="alert">
							<p>{ error }</p>
						</div>
					) : null }
					<div className="card-grid" data-sakura-layout="image-rules">
						{ cards.map( ( card ) => (
							<SettingsCard
								key={ card.id }
								card={ card }
								rest={ rest }
								strings={ strings }
								sections={ sections }
								scanned={ scanned }
								scan={ scan }
								scanning={ scanning }
								onError={ setError }
								onNotice={ setNotice }
							/>
						) ) }
					</div>
				</div>
			</main>
			{ notice ? <Prompt notice={ notice } onClose={ () => setNotice( null ) } /> : null }
		</>
	);
}

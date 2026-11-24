/**
 * The "add a configuration" card.
 *
 * The form never edits the live profile in place: it builds one, and a save
 * either appends a new profile or rewrites the one the operator loaded from the
 * chips. That is why the card carries profileId and saveAsNew at all.
 */
import { useEffect, useState } from 'react';
import { BusyLabel, Card } from '../shared/ui.jsx';

/**
 * One labelled text input, with the hint line the payload sent.
 *
 * @param {Object}   props          Field props.
 * @param {Object}   props.field    { label, hint, placeholder }.
 * @param {string}   props.name     Draft key.
 * @param {string}   props.value    Current value.
 * @param {string}   props.type     HTML input type.
 * @param {boolean}  props.disabled Read-only when the site pins the path.
 * @param {Function} props.onChange Change handler.
 * @return {JSX.Element} Field.
 */
function Text( { field, name, value, type = 'text', disabled = false, onChange } ) {
	return (
		<div className={ 'field field--' + type }>
			<div className="field__main">
				<label className="field__label" htmlFor={ 'sakura-s3-' + name }>
					{ field.label }
				</label>
				{ field.hint ? <p className="field__hint">{ field.hint }</p> : null }
			</div>
			<div className="field__control">
				<input
					id={ 'sakura-s3-' + name }
					name={ name }
					type={ type }
					autoComplete={ 'password' === type ? 'new-password' : 'off' }
					placeholder={ field.placeholder || undefined }
					value={ value }
					disabled={ disabled }
					onChange={ ( event ) => onChange( name, event.target.value ) }
				/>
			</div>
		</div>
	);
}

/**
 * @param {Object}   props            Card payload and callbacks.
 * @param {Object}   props.card       connection section.
 * @param {Object}   props.strings    Payload strings.
 * @param {boolean}  props.saving     A save is in flight.
 * @param {number}   props.resetToken Bumped by "新增账户配置".
 * @param {Function} props.onSave     Persist the draft.
 * @param {Function} props.onProbe    Probe the draft without saving.
 * @param {Function} props.onDoge     Resolve 多吉云 SDK parameters.
 * @return {JSX.Element} Card.
 */
export default function ConnectionCard( { card, strings, saving, resetToken, onSave, onProbe, onDoge } ) {
	const empty = ( ) => ( {
		provider: card.provider,
		profileName: '',
		profileId: '',
		saveAsNew: false,
		bucket: '',
		space: '',
		region: '',
		endpoint: '',
		key: '',
		secret: '',
		uploadPath: card.values.uploadPath,
		uploadUrlPath: '',
	} );

	const [ draft, setDraft ] = useState( empty );
	const [ saved, setSaved ] = useState( empty );
	const [ probing, setProbing ] = useState( false );
	const [ dodging, setDodging ] = useState( false );

	// Every mutation answers with a fresh connection section, including the
	// ones made on another card. Keying the reset on the values themselves (not
	// on the object) keeps an unsaved draft in this form when a neighbour card
	// saves, and still snaps back to the stored values after this one does.
	const valuesKey = JSON.stringify( card.values ) + '|' + card.provider;

	useEffect( () => {
		const base = { ...empty(), ...card.values, provider: card.provider };

		setDraft( base );
		setSaved( base );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ valuesKey ] );

	// "新增账户配置" drops the loaded profile and starts clean.
	useEffect( () => {
		if ( resetToken > 0 ) {
			setDraft( empty() );
			setSaved( empty() );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ resetToken ] );

	const load = ( profile ) => {
		setDraft( {
			provider: profile.provider || card.provider,
			profileName: profile.name,
			profileId: profile.id,
			saveAsNew: false,
			bucket: profile.bucket,
			space: profile.space,
			region: profile.region,
			endpoint: profile.endpoint,
			key: '',
			secret: '',
			uploadPath: profile.uploadPath,
			uploadUrlPath: profile.uploadUrlPath,
		} );
	};

	const change = ( key, value ) => setDraft( ( current ) => ( { ...current, [ key ]: value } ) );

	const hints = card.hints[ draft.provider ] || card.hints.default;
	const editing = card.savedProfiles.find( ( profile ) => profile.id === draft.profileId ) || null;
	const providerChanged = Boolean( editing ) && editing.provider !== draft.provider;
	const saveAsNew = providerChanged || draft.saveAsNew;
	const chips = card.savedProfiles.filter( ( profile ) => profile.provider === draft.provider );

	const body = ( ) => ( {
		provider: draft.provider,
		profile_id: draft.profileId,
		profile_name: draft.profileName,
		save_as_new: saveAsNew ? '1' : '',
		bucket: draft.bucket,
		space: draft.space,
		region: draft.region,
		endpoint: draft.endpoint,
		key: draft.key,
		secret: draft.secret,
		upload_path: draft.uploadPath,
		upload_url_path: draft.uploadUrlPath,
	} );

	const dirty = JSON.stringify( body() ) !== JSON.stringify( savedBody() );

	/**
	 * The values a save would write, for the dirty comparison only.
	 *
	 * @return {Object} Body built from the last saved draft.
	 */
	function savedBody() {
		return {
			provider: saved.provider,
			profile_id: saved.profileId,
			profile_name: saved.profileName,
			save_as_new: saved.saveAsNew ? '1' : '',
			bucket: saved.bucket,
			space: saved.space,
			region: saved.region,
			endpoint: saved.endpoint,
			key: saved.key,
			secret: saved.secret,
			upload_path: saved.uploadPath,
			upload_url_path: saved.uploadUrlPath,
		};
	}

	const submit = ( event ) => {
		event.preventDefault();

		if ( saving ) {
			return;
		}

		onSave( body() );
	};

	const probe = async () => {
		setProbing( true );

		try {
			await onProbe( {
				provider: draft.provider,
				bucket: draft.bucket,
				region: draft.region,
				endpoint: draft.endpoint,
				key: draft.key,
				secret: draft.secret,
			} );
		} finally {
			setProbing( false );
		}
	};

	const resolveDoge = async () => {
		setDodging( true );

		try {
			await onDoge(
				{ space: draft.space, key: draft.key, secret: draft.secret },
				( entry ) => {
					setDraft( ( current ) => ( {
						...current,
						bucket: entry.bucket || current.bucket,
						space: entry.space || current.space,
						endpoint: entry.endpoint || current.endpoint,
						region: entry.region || current.region,
					} ) );
				}
			);
		} finally {
			setDodging( false );
		}
	};

	return (
		<Card card={ card }>
			<form className="card__form" id="sakura-s3-connection-form" onSubmit={ submit }>
				<div className="field-list">
					<div className="field field--custom field--full">
						<div className="field__main">
							<span className="field__label">{ card.fields.provider.label }</span>
						</div>
						<div className="field__control">
							<div className={ 's3-provider-row' + ( chips.length || editing ? '' : ' is-empty' ) }>
								<div className="s3-provider-row__pick">
									<div className="field__row">
										<select
											className="field__grow"
											id="sakura-s3-provider"
											name="provider"
											value={ draft.provider }
											onChange={ ( event ) => {
												change( 'provider', event.target.value );
												change( 'profileId', '' );
												change( 'saveAsNew', false );
											} }
										>
											{ card.providers.map( ( option ) => (
												<option key={ option.value } value={ option.value }>
													{ option.label }
												</option>
											) ) }
										</select>
										<button
											type="button"
											className="btn btn--ghost btn--small"
											id="sakura-s3-test-connection"
											title={ card.fields.provider.testTitle }
											disabled={ probing }
											onClick={ probe }
										>
											<BusyLabel
												idle={ card.fields.provider.testLabel }
												active={ strings.probeRunning }
												busy={ probing }
											/>
										</button>
									</div>
								</div>

								<div className="s3-provider-row__saved" hidden={ ! chips.length }>
									<span className="field__label">{ card.fields.provider.savedLabel }</span>
									<div className="profile-chips__list" role="list">
										{ chips.map( ( profile ) => (
											<button
												type="button"
												key={ profile.id }
												role="listitem"
												className={ 'profile-chip' + ( profile.id === draft.profileId ? ' is-loaded' : '' ) }
												onClick={ () => load( profile ) }
											>
												<span className="profile-chip__name">{ profile.name }</span>
												<span className="profile-chip__meta">{ profile.space || profile.bucket }</span>
											</button>
										) ) }
									</div>
								</div>
							</div>
						</div>
					</div>

					<Text field={ card.fields.profileName } name="profile_name" value={ draft.profileName } onChange={ ( _key, value ) => change( 'profileName', value ) } />

					<div className="field field--check field--full">
						<div className="field__main">
							<span className="field__hint">{ card.fields.saveAsNew.hint }</span>
						</div>
						<div className="field__control">
							<label className="check">
								<input
									type="checkbox"
									name="save_as_new"
									checked={ saveAsNew }
									disabled={ providerChanged }
									onChange={ ( event ) => change( 'saveAsNew', event.target.checked ) }
								/>
								<span className="check__text">{ card.fields.saveAsNew.label }</span>
							</label>
							{ providerChanged ? <p className="field__hint">{ strings.providerSwitchNote }</p> : null }
						</div>
					</div>

					<div className="field field--custom field--full">
						<div className="field__main">
							<label className="field__label" htmlFor="sakura-s3-bucket">
								{ hints.bucket_label }
							</label>
							<p className="field__hint">{ hints.bucket }</p>
						</div>
						<div className="field__control">
							<input type="hidden" name="space" value={ draft.space } readOnly />
							<div className="field__row field__row--column">
								<input
									className="field__grow"
									id="sakura-s3-bucket"
									name="bucket"
									type="text"
									autoComplete="off"
									value={ draft.bucket }
									onChange={ ( event ) => change( 'bucket', event.target.value ) }
								/>
								{ 'dogecloud' === draft.provider ? (
									<button
										type="button"
										className="btn btn--ghost btn--small"
										disabled={ dodging }
										onClick={ resolveDoge }
									>
										<BusyLabel idle={ card.fields.bucket.doge } active={ strings.dogeBusy } busy={ dodging } />
									</button>
								) : null }
							</div>
						</div>
					</div>

					<Text field={ card.fields.region } name="region" value={ draft.region } onChange={ ( _key, value ) => change( 'region', value ) } />
					<Text field={ card.fields.endpoint } name="endpoint" type="url" value={ draft.endpoint } onChange={ ( _key, value ) => change( 'endpoint', value ) } />
					<Text field={ card.fields.key } name="key" type="password" value={ draft.key } onChange={ ( _key, value ) => change( 'key', value ) } />
					<Text field={ card.fields.secret } name="secret" type="password" value={ draft.secret } onChange={ ( _key, value ) => change( 'secret', value ) } />
					<Text
						field={ card.fields.uploadPath }
						name="upload_path"
						value={ draft.uploadPath }
						disabled={ card.fields.uploadPath.locked }
						onChange={ ( _key, value ) => change( 'uploadPath', value ) }
					/>
					<Text
						field={ card.fields.uploadUrlPath }
						name="upload_url_path"
						type="url"
						value={ draft.uploadUrlPath }
						onChange={ ( _key, value ) => change( 'uploadUrlPath', value ) }
					/>
				</div>

				{ card.lockedNote ? (
					<div className="notice-inline notice-inline--warn" role="status">
						<p>{ card.lockedNote }</p>
					</div>
				) : null }

				<div className="card__savebar">
					<button
						type="submit"
						className="btn btn--primary"
						disabled={ ! dirty || saving }
						aria-busy={ saving ? 'true' : undefined }
					>
						<BusyLabel idle={ card.save } active={ strings.saving } busy={ saving } />
					</button>
				</div>
			</form>
		</Card>
	);
}

import { Field } from '../shared/ui.jsx';

export default function Target( { fields, values, onChange, hostOptions, scope, strings, disabled } ) {
	const sourceLabel = 'upload_url_path' === scope.source ? strings.scopeSourceUrl : strings.scopeSourcePath;

	return (
		<>
			<div className="alert alert--info rewrite-scope" role="status">
				<div className="alert__body">
					<strong>{ strings.scopeLabel }</strong>{ ' ' }
					<code>{ scope.displayPath || '/' }</code>{ ' · ' }
					<span className="rewrite-scope__source">{ sourceLabel }</span>{ ' · ' }
					<span className="rewrite-scope__hint">{ strings.scopeHint }</span>
				</div>
			</div>

			<div className="rewrite-target">
				{ fields[0] ? (
					<Field
						field={ fields[0] }
						value={ values[ fields[0].key ] || '' }
						onChange={ onChange }
						disabled={ disabled }
					/>
				) : null }
				<span className="rewrite-target__arrow" aria-hidden="true">→</span>
				{ fields[1] ? (
					<Field
						field={ fields[1] }
						value={ values[ fields[1].key ] || '' }
						onChange={ onChange }
						disabled={ disabled }
					/>
				) : null }
			</div>

			<datalist id="rewrite-host-options">
				{ hostOptions.map( ( host ) => <option key={ host } value={ host } /> ) }
			</datalist>

			<div className="alert alert--info" role="status">
				<div className="alert__body">{ strings.behaviorAlert }</div>
			</div>
			<div className="alert alert--warn" role="status">
				<strong className="alert__title">{ strings.backupTitle }</strong>
				<div className="alert__body">{ strings.backupAlert }</div>
			</div>
		</>
	);
}

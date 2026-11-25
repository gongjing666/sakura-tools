import { useEffect, useRef } from 'react';
import { statusInfo, numberLabel } from './status.js';
import Preview from './preview.jsx';

function SelectAll( { checked, indeterminate, onChange, label, disabled } ) {
	const ref = useRef( null );

	useEffect( () => {
		if ( ref.current ) {
			ref.current.indeterminate = !! indeterminate;
		}
	}, [ indeterminate ] );

	return (
		<label className="check">
			<input
				ref={ ref }
				type="checkbox"
				checked={ checked }
				disabled={ disabled }
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
			<span className="check__box" aria-hidden="true" />
			<span className="check__text">{ label }</span>
		</label>
	);
}

export default function Scan( {
	state,
	selected,
	strings,
	busy,
	scope,
	onSelect,
	onSelectAll,
	onPreset,
	onScan,
	onReset,
	onPreview,
	preview,
	onClosePreview,
} ) {
	const tasks = state.tasks || [];
	const info = statusInfo( state.status, strings );
	const canPreview = info.preview;
	const selectedCount = selected.length;
	const all = tasks.length > 0 && selectedCount === tasks.length;
	const indeterminate = selectedCount > 0 && selectedCount < tasks.length;
	const scanning = 'scanning' === state.status;
	const disabled = busy || scanning || 'running' === state.status;
	const scanText = ( strings.scanStatus || '' )
		.replace( '%1$s', info.label )
		.replace( '%2$s', numberLabel( tasks.length ) )
		.replace( '%3$s', scope.displayPath || '/' );

	return (
		<>
			<p className="rewrite-scan-note">{ scanText }</p>

			<div className="rewrite-actions">
				<button type="button" className="btn btn--primary" onClick={ onScan } disabled={ disabled }>
					{ strings.scanButton }
				</button>
				<button type="button" className="btn btn--ghost" onClick={ onReset } disabled={ busy || 'idle' === state.status || scanning }>
					{ strings.resetScan }
				</button>
				<span className="rewrite-scan-status" role="status" aria-live="polite">
					<span className={ `badge badge--${ info.tone }` }>{ info.label }</span>
					<span className="rewrite-scan-status__text">{ scanning ? strings.scanResume : '' }</span>
				</span>
			</div>

			<div className={ scanning ? 'progress rewrite-progress progress--indeterminate' : 'progress rewrite-progress' } hidden={ ! scanning }>
				<span className="progress__bar" style={ { width: scanning ? '100%' : '0%' } } />
			</div>

			<div className="rewrite-toolbar">
				<SelectAll
					checked={ all }
					indeterminate={ indeterminate }
					onChange={ onSelectAll }
					label={ strings.selectAll }
					disabled={ busy || ! tasks.length }
				/>
				<button type="button" className="btn btn--quiet btn--small" onClick={ () => onPreset( [ 'content', 'theme' ] ) } disabled={ busy || ! canPreview || ! tasks.length }>
					{ strings.presetDefault }
				</button>
				<button type="button" className="btn btn--quiet btn--small" onClick={ () => onPreset( null ) } disabled={ busy || ! canPreview || ! tasks.length }>
					{ strings.presetAll }
				</button>
				<span className="rewrite-selection">{ ( strings.selected || '' ).replace( '%d', String( selectedCount ) ) }</span>
			</div>

			<div className="table-scroll">
				<table className="table table--compact table--responsive rewrite-tasks">
					<thead>
						<tr>
							<th scope="col" className="rewrite-tasks__pick"><span className="screen-reader-text">{ strings.selectColumn }</span></th>
							<th scope="col">{ strings.columns.position }</th>
							<th scope="col">{ strings.columns.group }</th>
							<th scope="col">{ strings.columns.rows }</th>
							<th scope="col">{ strings.columns.sample }</th>
						</tr>
					</thead>
					<tbody>
						{ tasks.length ? (
							tasks.map( ( task ) => {
								const group = String( task.group || 'other' );
								const checked = selected.indexOf( task.index ) >= 0;

								return (
									<tr key={ task.index } data-index={ task.index }>
										<td data-label={ strings.selectColumn } className="rewrite-tasks__pick">
											<input
												type="checkbox"
												className="rewrite-task__check"
												value={ task.index }
												checked={ checked }
												disabled={ busy }
												aria-label={ task.label }
												onChange={ ( event ) => onSelect( task.index, event.target.checked ) }
											/>
										</td>
										<td data-label={ strings.columns.position }>
											<code className="rewrite-task__label">{ task.label }</code>
											<span className="rewrite-task__meta">{ `${ task.table }.${ task.column }` }</span>
										</td>
										<td data-label={ strings.columns.group }>
											<span className={ `badge badge--${ 'content' === group ? 'ok' : 'neutral' }` }>
												{ ( strings.groupLabels || {} )[ group ] || group }
											</span>
										</td>
										<td data-label={ strings.columns.rows }>{ numberLabel( task.rows ) }</td>
										<td data-label={ strings.columns.sample }>
											<button
												type="button"
												className="btn btn--quiet btn--small"
												onClick={ () => onPreview( task.index ) }
												disabled={ busy || ! canPreview || ! ( Number( task.rows ) > 0 ) }
											>
												{ strings.previewButton }
											</button>
										</td>
									</tr>
								);
							} )
						) : (
							<tr className="rewrite-tasks__empty">
								<td colSpan="5">
									<div className="empty-state">
										<strong className="empty-state__title">{ strings.emptyTasksTitle }</strong>
										<p className="empty-state__message">{ strings.emptyTasksBody }</p>
									</div>
								</td>
							</tr>
						) }
					</tbody>
				</table>
			</div>

			<Preview preview={ preview } onClose={ onClosePreview } strings={ strings } />
		</>
	);
}

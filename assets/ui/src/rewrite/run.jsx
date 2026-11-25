import { numberLabel, statusInfo } from './status.js';

export default function Run( { state, strings, busy, selectedCount, onRun } ) {
	const info = statusInfo( state.status, strings );
	const total = state.selected ? state.selected : state.total;
	const progress = Math.min( 100, Math.max( 0, Number( state.progress ) || 0 ) );
	const running = 'running' === state.status;
	const showProgress = running || ( 'done' === state.status && progress > 0 );
	const canRun = info.canRun && ( 'running' === state.status ? selectedCount > 0 : selectedCount > 0 );
	const totalLabel = numberLabel( state.selected ? state.selected : total );
	const note = 'scanning' === state.status
		? strings.scanResume
		: 'ready' === state.status
			? ( strings.noteReady || '' ).replace( '%d', totalLabel )
			: 'running' === state.status
				? strings.noteRunning
				: 'done' === state.status
					? strings.noteDone
					: strings.noteIdle;

	return (
		<>
			<div className="rewrite-run__stats">
				<div className="rewrite-stat"><span className="rewrite-stat__label">{ strings.statsLabels.total }</span><strong className="rewrite-stat__value">{ numberLabel( state.selected ? state.selected : state.total ) }</strong></div>
				<div className="rewrite-stat"><span className="rewrite-stat__label">{ strings.statsLabels.done }</span><strong className="rewrite-stat__value">{ numberLabel( state.done ) }</strong></div>
				<div className="rewrite-stat"><span className="rewrite-stat__label">{ strings.statsLabels.changed }</span><strong className="rewrite-stat__value rewrite-stat__value--ok">{ numberLabel( state.changed ) }</strong></div>
				<div className="rewrite-stat"><span className="rewrite-stat__label">{ strings.statsLabels.skipped }</span><strong className="rewrite-stat__value">{ numberLabel( state.skipped ) }</strong></div>
				<div className="rewrite-stat"><span className="rewrite-stat__label">{ strings.statsLabels.failed }</span><strong className="rewrite-stat__value rewrite-stat__value--bad">{ numberLabel( state.failed ) }</strong></div>
			</div>

			<div className="progress rewrite-progress" hidden={ ! showProgress }>
				<span className="progress__bar" style={ { width: `${ progress }%` } } />
			</div>

			<p className="rewrite-run__note" role="status" aria-live="polite">{ note }</p>

			<div className="rewrite-actions">
				<button type="button" className="btn btn--primary" onClick={ onRun } disabled={ busy || ! canRun }>
					{ running ? strings.runContinue : strings.runButton }
				</button>
			</div>

			<ul className="notes-list">
				{ ( strings.runNotes || [] ).map( ( line, index ) => <li key={ index }>{ line }</li> ) }
			</ul>
		</>
	);
}

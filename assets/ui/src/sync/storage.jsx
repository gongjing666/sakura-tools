/**
 * Cloud size mix: a conic-gradient donut plus the legend, or the empty state
 * that explains why there is nothing to draw.
 */
import { Card } from '../shared/ui.jsx';

export default function StorageCard( { card, view, scan, strings, locked, frozen, onScan, onResume } ) {
	const s = strings.storage || {};
	const state = view.mix_state || 'unscanned';
	const empty = 'unscanned' === state || 'scanning' === state || 'interrupted' === state;
	const title = view.mix_title || '';
	const desc = view.mix_desc || '';

	return (
		<Card card={ card }>
			<div className={ 'storage-mix storage-mix--' + state }>
				{ empty ? (
					<div className="storage-mix__empty">
						<strong className="storage-mix__empty-title">{ title }</strong>
						<p className="storage-mix__empty-text">{ desc }</p>
						<div className="button-row">
							{ 'unscanned' === state ? (
								<>
									<button type="button" className="btn btn--primary btn--small" disabled={ frozen } onClick={ onScan }>
										{ s.emptyScan }
									</button>
									<a className="btn btn--ghost btn--small" href="#tasks">
										{ s.viewTasks }
									</a>
								</>
							) : null }
							{ 'interrupted' === state ? (
								<>
									<button type="button" className="btn btn--primary btn--small" disabled={ locked } onClick={ onResume }>
										{ s.resume }
									</button>
									<a className="btn btn--ghost btn--small" href="#tasks">
										{ s.viewTasks }
									</a>
								</>
							) : null }
						</div>
					</div>
				) : (
					<>
						<div className="storage-mix__donut-wrap">
							<div
								className="storage-mix__donut"
								role="img"
								aria-label="云端文件体积分布"
								style={ { background: view.mix_gradient } }
							>
								<div className="storage-mix__center">
									<strong>{ view.cloud_value_label }</strong>
									<span>
										{ view.cloud_unit } · { view.cloud_objects } 个文件
									</span>
								</div>
							</div>
							<div className="storage-mix__legend">
								{ ( view.mix_items || [] ).map( ( item ) => (
									<span className="storage-mix__legend-row" key={ item.type }>
										<span className="storage-mix__swatch" style={ { background: item.color } } aria-hidden="true" />
										{ item.label }
										<strong>{ item.pct_label }%</strong>
									</span>
								) ) }
							</div>
						</div>
						<p className="storage-mix__hint">{ desc }</p>
					</>
				) }
				<div className="storage-mix__foot">
					<span>{ ( s.local || '本地 %s' ).replace( '%s', view.local_bytes_label ) }</span>
					<strong>{ ( s.cloud || '云端 %s' ).replace( '%s', view.cloud_bytes_label ) }</strong>
					<span>{ ( s.diff || '差 %s' ).replace( '%s', view.diff_bytes_label || '' ) }</span>
				</div>
			</div>
		</Card>
	);
}

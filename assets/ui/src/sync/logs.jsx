/** Sync log: level chips, the six-row default, load more. */
import { useEffect, useState } from 'react';
import { Card, Badge } from '../shared/ui.jsx';

const DEFAULT_VISIBLE = 6;

export default function LogsCard( { card, logs, data, strings } ) {
	const copy = strings.logs || {};
	const ui = strings.ui || {};
	const [ level, setLevel ] = useState( 'all' );
	const [ visible, setVisible ] = useState( DEFAULT_VISIBLE );
	const list = Array.isArray( logs ) ? logs : [];

	useEffect( () => {
		setVisible( DEFAULT_VISIBLE );
	}, [ logs ] );

	const filtered = 'all' === level ? list : list.filter( ( entry ) => entry.level === level );
	const shown = 'all' === level ? filtered.slice( 0, visible ) : filtered;
	const remaining = filtered.length - shown.length;

	const exportHref = () => {
		const url = new URL( data.exportUrl || window.location.href, window.location.href );

		return url.toString();
	};

	return (
		<Card
			card={ card }
			actions={
				<a className="btn btn--ghost btn--small" href={ exportHref() }>
					{ ui.exportCsv || '导出差异表格（CSV）' }
				</a>
			}
		>
			<div className="log-toolbar">
				<div className="filter-chips" aria-label={ ui.logLevelAria || '日志级别' }>
					{ [ 'all', 'error', 'warning', 'info' ].map( ( value ) => (
						<button
							key={ value }
							type="button"
							className={ 'filter-chip' + ( value === level ? ' is-active' : '' ) }
							onClick={ () => setLevel( value ) }
						>
							{ ( copy.levels || {} )[ value ] }
						</button>
					) ) }
				</div>
			</div>
			{ shown.length ? (
				<div className="log-list">
					{ shown.map( ( entry, index ) => (
						<div className="log-item" data-level={ entry.level } key={ index }>
							<span className="log-item__time">{ entry.time_label }</span>
							<div className="log-item__body">
								<div className="log-item__title">
									{ entry.message }
									<Badge tone={ entry.badge }>{ entry.label }</Badge>
								</div>
								<div className="log-item__meta">{ entry.scan }</div>
							</div>
						</div>
					) ) }
				</div>
			) : (
				<div className="empty-state">
					<strong className="empty-state__title">{ copy.empty || '暂无同步事件。' }</strong>
				</div>
			) }
			{ 'all' === level && remaining > 0 ? (
				<div className="log-more">
					<button type="button" className="btn btn--ghost btn--small" onClick={ () => setVisible( visible + DEFAULT_VISIBLE ) }>
						{ ( copy.more || '查询更多日志（还有 %d 条）' ).replace( '%d', String( remaining ) ) }
					</button>
				</div>
			) : null }
		</Card>
	);
}

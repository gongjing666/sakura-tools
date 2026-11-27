/**
 * The Sakura Frontline rail and the five-ticket KPI strip above the sync grid.
 *
 * The server sends every number and label (Sync_Payload::numbers()); this file
 * only recreates the markup the classic page printed, so the shared component
 * styles (components.css .frontline / .kpis--ticket) apply unchanged.
 */
import { Fragment } from 'react';

/** Station anchors as percentages of the 900x150 view box (86/900, 112/150). */
const ANCHORS = [
	{ x: '9.556%', y: '74.667%' },
	{ x: '50.222%', y: '37.333%' },
	{ x: '90.889%', y: '64.000%' },
];

const TONES = [ 'sky', 'petal', 'sun' ];

/** One arc, a soft glow at its end, two petals, three dots. */
function Arc() {
	return (
		<svg className="frontline__arc" viewBox="0 0 900 150" aria-hidden="true" focusable="false">
			<defs>
				<linearGradient id="sakura-front-arc" x1="0" y1="0" x2="1" y2="0">
					<stop offset="0" stopColor="#7eb6f0" />
					<stop offset=".42" stopColor="#c99cd8" />
					<stop offset=".66" stopColor="#ff9cc0" />
					<stop offset="1" stopColor="#ffc85c" />
				</linearGradient>
				<radialGradient id="sakura-front-glow" cx=".5" cy=".5" r=".5">
					<stop offset="0" stopColor="#fff4d6" stopOpacity=".95" />
					<stop offset="1" stopColor="#fff4d6" stopOpacity="0" />
				</radialGradient>
			</defs>
			<circle cx="820" cy="74" r="64" fill="url(#sakura-front-glow)" />
			<path
				d="M86 112C210 22 330 20 452 56c120 36 244 40 366 40"
				fill="none"
				stroke="url(#sakura-front-arc)"
				strokeWidth="3.5"
				strokeLinecap="round"
				opacity=".55"
			/>
			<path
				d="M86 112C210 22 330 20 452 56c120 36 244 40 366 40"
				fill="none"
				stroke="#ffb0d0"
				strokeWidth="10"
				strokeLinecap="round"
				strokeDasharray="2 46"
				opacity=".9"
			/>
			<g className="frontline__petals">
				<path
					transform="translate(268 44) rotate(18)"
					d="M0 0c3.4 2 6 5.4 6 9.1C6 12.7 3.4 17 0 18.3-3.4 17-6 12.7-6 9.1-6 5.4-3.4 2 0 0z"
					fill="#ffb3d1"
				/>
				<path
					transform="translate(636 84) rotate(-24)"
					d="M0 0c2.6 1.5 4.5 4.1 4.5 7 0 2.7-1.9 6-4.5 7-2.6-1-4.5-4.3-4.5-7 0-2.9 1.9-5.5 4.5-7z"
					fill="#ffc6db"
					opacity=".9"
				/>
			</g>
			<circle className="frontline__dot frontline__dot--sky" cx="86" cy="112" r="9" />
			<circle className="frontline__dot frontline__dot--petal" cx="452" cy="56" r="9" />
			<circle className="frontline__dot frontline__dot--sun" cx="818" cy="96" r="9" />
		</svg>
	);
}

/** The three stations: local media, cloud objects, verified in the index. */
export function Frontline( { frontline } ) {
	if ( ! frontline || ! Array.isArray( frontline.stations ) || ! frontline.stations.length ) {
		return null;
	}

	return (
		<div className="frontline">
			<div className="frontline__plot">
				<Arc />
				<ul className="frontline__legend">
					{ frontline.stations.map( ( station, index ) => {
						const tone = station.tone || TONES[ index ] || 'sky';
						const anchor = ANCHORS[ index ] || ANCHORS[ 0 ];
						const style = { '--frontline-x': anchor.x, '--frontline-y': anchor.y };

						return (
							<li
								key={ station.label || index }
								className={ 'frontline__station frontline__station--' + tone }
								style={ style }
							>
								<span className="frontline__value">{ station.value }</span>
								<span className="frontline__meta">
									<span className="frontline__label">{ station.label }</span>
									{ station.sub ? <span className="frontline__sub">{ station.sub }</span> : null }
								</span>
							</li>
						);
					} ) }
				</ul>
			</div>
			{ frontline.caption || frontline.note ? (
				<p className="frontline__caption">
					{ frontline.caption ? <span>{ frontline.caption }</span> : null }
					{ frontline.note ? <span>{ frontline.note }</span> : null }
				</p>
			) : null }
		</div>
	);
}

function Kpi( { kpi } ) {
	const progress = typeof kpi.progress === 'number' ? Math.min( 100, Math.max( 0, kpi.progress ) ) : null;
	const subs = Array.isArray( kpi.subs ) ? kpi.subs : [];

	return (
		<article className={ 'kpi' + ( kpi.synced ? ' kpi--synced' : '' ) }>
			<span className="kpi__label">{ kpi.label }</span>
			<span className="kpi__value">
				{ kpi.value }
				<small>{ kpi.unit }</small>
			</span>
			{ subs.map( ( sub, index ) => (
				<span
					className="kpi__sub"
					key={ index }
					title={ 0 === index && kpi.title ? kpi.title : undefined }
				>
					{ sub }
				</span>
			) ) }
			{ null === progress ? null : (
				<div className="kpi__progress">
					<div
						className="progress"
						role="progressbar"
						aria-valuemin="0"
						aria-valuemax="100"
						aria-valuenow={ Math.round( progress ) }
					>
						<span className="progress__bar" style={ { width: progress + '%' } } />
					</div>
				</div>
			) }
		</article>
	);
}

/** Five ticket-stub tiles: the same five the classic page printed. */
export function KpiStrip( { kpis, label } ) {
	if ( ! Array.isArray( kpis ) || ! kpis.length ) {
		return null;
	}

	return (
		<section className="kpis kpis--ticket" id="summary" aria-label={ label || '同步摘要' }>
			{ kpis.map( ( kpi, index ) => (
				<Fragment key={ kpi.label || index }>
					<Kpi kpi={ kpi } />
				</Fragment>
			) ) }
		</section>
	);
}

import { Badge, Card } from '../shared/ui.jsx';

export default function Diagnostics( { card, strings } ) {
	const data = card.data || {};
	const theme = data.theme || {};
	const breakdown = data.breakdown || {};
	const methods = data.methods || [];
	const caps = data.caps || [];
	const themeValue = ( value, fallback ) => ( value ? value : fallback );

	return (
		<Card card={ card }>
			<div className="zibll-diag-grid">
				<section className="zibll-block" aria-labelledby="zibll-theme-title">
					<h3 className="zibll-block__title" id="zibll-theme-title">{ strings.themeTitle }</h3>
					<dl className="zibll-meta">
						<div><dt>{ strings.name }</dt><dd>{ themeValue( theme.name, strings.unknown ) }</dd></div>
						<div><dt>{ strings.version }</dt><dd>{ themeValue( theme.version, strings.unknown ) }</dd></div>
						<div><dt>{ strings.stylesheet }</dt><dd><code className="zibll-code">{ themeValue( theme.stylesheet, '—' ) }</code></dd></div>
						<div><dt>{ strings.template }</dt><dd><code className="zibll-code">{ themeValue( theme.template, '—' ) }</code></dd></div>
						<div><dt>{ strings.child }</dt><dd>{ theme.is_child ? strings.yes : strings.no }</dd></div>
					</dl>
				</section>

				<section className="zibll-block" aria-labelledby="zibll-caps-title">
					<h3 className="zibll-block__title" id="zibll-caps-title">{ strings.capsTitle }</h3>
					<ul className="zibll-cap-list">
						{ caps.map( ( cap ) => (
							<li className="zibll-cap" key={ cap.key }>
								<Badge tone={ cap.ok ? 'ok' : 'warn' }>{ cap.ok ? strings.capOk : strings.capMissing }</Badge>
								<span className="zibll-cap__label">{ cap.label }</span>
								{ ! cap.ok ? <span className="zibll-cap__note">{ strings.capClosed }</span> : null }
							</li>
						) ) }
					</ul>
				</section>
			</div>

			<section className="zibll-block" aria-labelledby="zibll-methods-title">
				<h3 className="zibll-block__title" id="zibll-methods-title">{ strings.methodsTitle }</h3>
				{ ! methods.length && ! data.points_on ? (
					<p className="zibll-muted">{ strings.noMethods }</p>
				) : (
					<>
						<ul className="zibll-method-list">
							{ methods.map( ( method ) => (
								<li key={ method.id }>
									<code className="zibll-code">{ method.id }</code>
									<span>{ method.name }</span>
								</li>
							) ) }
							{ data.points_on ? (
								<li><code className="zibll-code">points</code><span>{ strings.points }</span></li>
							) : null }
						</ul>
						<p className="zibll-muted">{ strings.methodsNote }</p>
					</>
				) }
			</section>

			<section className="zibll-block" aria-labelledby="zibll-breakdown-title">
				<h3 className="zibll-block__title" id="zibll-breakdown-title">{ strings.breakdownTitle }</h3>
				<dl className="zibll-meta zibll-meta--wide">
					<div><dt>{ strings.base }</dt><dd>{ breakdown.base }</dd></div>
					<div><dt>{ strings.levelBonus }</dt><dd>{ breakdown.level }</dd></div>
					<div><dt>{ strings.vipBonus }</dt><dd>{ breakdown.vip }</dd></div>
					<div><dt>{ strings.granted }</dt><dd>{ breakdown.granted }</dd></div>
					<div><dt>{ strings.computed }</dt><dd>{ breakdown.computed }</dd></div>
					<div><dt>{ strings.override }</dt><dd>{ breakdown.override || strings.unset }</dd></div>
					<div><dt>{ strings.final }</dt><dd><strong>{ breakdown.final }</strong></dd></div>
				</dl>
				<p className="zibll-muted">{ strings.mode }：{ 'replace' === breakdown.mode ? strings.modeReplace : strings.modeCap }</p>
			</section>
		</Card>
	);
}

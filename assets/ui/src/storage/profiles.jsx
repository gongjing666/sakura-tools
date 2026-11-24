/**
 * The saved profiles card: one click enables or disables, the actions row can
 * probe a profile or delete it.
 */
import { Badge, Card } from '../shared/ui.jsx';

/**
 * @param {Object}   props          Card payload and callbacks.
 * @param {Object}   props.card     profiles section.
 * @param {string}   props.busy     Profile id currently talking to the server.
 * @param {Function} props.onAdd    Start a new configuration.
 * @param {Function} props.onToggle Enable / disable / switch.
 * @param {Function} props.onDelete Ask for the confirmation dialog.
 * @param {Function} props.onProbe  Probe this profile without switching.
 * @return {JSX.Element} Card.
 */
export default function ProfilesCard( { card, busy, onAdd, onToggle, onDelete, onProbe } ) {
	return (
		<Card
			card={ card }
			actions={
				<button type="button" className="btn btn--ghost btn--small" onClick={ onAdd }>
					{ card.addLabel }
				</button>
			}
		>
			{ card.items.length ? (
				<div className="profile-grid">
					{ card.items.map( ( item ) => {
						const classes = [ 'profile-card' ];

						if ( item.live ) {
							classes.push( 'is-active' );
						}

						if ( item.current ) {
							classes.push( 'is-current' );
						}

						if ( busy === item.id ) {
							classes.push( 'is-pending' );
						}

						return (
							<div className="profile-item" key={ item.id }>
								<button
									type="button"
									className={ classes.join( ' ' ) }
									aria-pressed={ item.live ? 'true' : 'false' }
									disabled={ busy === item.id || ! item.complete }
									onClick={ () => onToggle( item ) }
								>
									<span className="profile-card__top">
										<span className="profile-card__name">{ item.name }</span>
										<Badge tone={ item.badgeTone }>{ item.badge }</Badge>
									</span>
									<span className="profile-card__meta">{ item.label }</span>
									<span className="profile-card__meta" title={ item.bucket }>
										{ item.bucketDisplay }
									</span>
									<span className="profile-card__meta">{ item.endpoint }</span>
									{ item.warning ? (
										<span className="profile-card__warning">{ item.warning }</span>
									) : null }
								</button>

								<div className="profile-item__actions">
									<button
										type="button"
										className="btn btn--quiet btn--small profile-probe"
										title={ item.probe.title }
										disabled={ busy === item.id }
										onClick={ () => onProbe( item ) }
									>
										{ item.probe.label }
									</button>
									<button
										type="button"
										className="btn btn--quiet btn--small profile-delete"
										title={ item.delete.title }
										disabled={ item.delete.disabled || busy === item.id }
										onClick={ () => onDelete( item ) }
									>
										{ item.delete.label }
									</button>
								</div>
							</div>
						);
					} ) }
				</div>
			) : (
				<div className="empty-state">
					<span className="empty-state__title">{ card.empty.title }</span>
					<p className="empty-state__message">{ card.empty.text }</p>
				</div>
			) }
		</Card>
	);
}

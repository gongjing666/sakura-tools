/**
 * The same empty-state nesting UI::empty_state() prints.
 */
export default function EmptyState( { title, message } ) {
	return (
		<div className="empty-state">
			<strong className="empty-state__title">{ title }</strong>
			<p className="empty-state__message">{ message }</p>
		</div>
	);
}

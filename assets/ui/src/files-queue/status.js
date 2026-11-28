/**
 * Status presentation shared by the review queue and its detail drawer.
 *
 * The queue list and the drawer must agree on the tone of the same row, so the
 * mapping lives here instead of being copied into both components.
 */

export function statusTone( status ) {
	if ( status === 'pending_review' || status === 'needs_review' ) {
		return 'warn';
	}

	if ( status === 'approved' || status === 'queued' || status === 'executing' ) {
		return 'info';
	}

	if ( status === 'completed' ) {
		return 'ok';
	}

	if ( status === 'rejected' || status === 'failed' || status === 'partial_failed' ) {
		return 'bad';
	}

	return 'neutral';
}

export function statusText( strings, status ) {
	const labels = strings && strings.statusLabels;

	return ( labels && labels[ status ] ) || status || '—';
}

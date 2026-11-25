/**
 * Shared pager, mirroring the PHP $sufm_pager markup and {placeholders}.
 */
export default function Pager( { page, per, total, sizes, strings, onChange } ) {
	const last = Math.max( 1, Math.ceil( total / per ) );
	const from = total ? ( page - 1 ) * per + 1 : 0;
	const to = Math.min( total, page * per );
	const summary = total
		? String( strings.pagerSummary || '' ).replace( '{from}', from ).replace( '{to}', to ).replace( '{total}', total )
		: String( strings.pagerEmpty || '' );
	const now = String( strings.pagerNow || '' ).replace( '{page}', page ).replace( '{pages}', last );

	return (
		<div className="pagination" hidden={ total <= 0 }>
			<div className="pagination__sizes" role="group" aria-label={ strings.perPage || '每页' }>
				<span>{ strings.perPage || '每页' }</span>
				{ sizes.map( ( size ) => (
					<button
						key={ size }
						type="button"
						className={ size === per ? 'pagination__link is-current' : 'pagination__link' }
						aria-pressed={ size === per }
						onClick={ () => {
							if ( size !== per ) {
								onChange( 1, size );
							}
						} }
					>
						{ size }
					</button>
				) ) }
			</div>
			<span className="pagination__summary">{ summary }</span>
			<div className="pagination__pages">
				<button
					type="button"
					className="pagination__link"
					aria-label={ strings.prevPage || '上一页' }
					disabled={ page <= 1 }
					onClick={ () => onChange( page - 1, per ) }
				>
					‹
				</button>
				<span className="pagination__now">{ now }</span>
				<button
					type="button"
					className="pagination__link"
					aria-label={ strings.nextPage || '下一页' }
					disabled={ page >= last }
					onClick={ () => onChange( page + 1, per ) }
				>
					›
				</button>
			</div>
		</div>
	);
}

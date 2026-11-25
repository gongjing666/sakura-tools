export default function Preview( { preview, onClose, strings } ) {
	if ( ! preview || ! preview.open ) {
		return null;
	}

	return (
		<div className="rewrite-preview" id="rewrite-preview">
			<div className="rewrite-preview__head">
				<strong>{ preview.label || strings.previewTitle }</strong>
				<button type="button" className="btn btn--quiet btn--small" onClick={ onClose }>
					{ strings.previewClose }
				</button>
			</div>
			<div className="rewrite-preview__body">
				{ preview.loading ? <p className="rewrite-preview__hint">{ strings.previewing }</p> : null }
				{ ! preview.loading && ! preview.rows.length ? (
					<p className="rewrite-preview__hint">{ strings.previewEmpty }</p>
				) : null }
				{ ! preview.loading ? preview.rows.map( ( sample, index ) => (
					<div className="rewrite-preview__row" key={ `${ sample.pk }-${ index }` }>
						<span className="rewrite-preview__pk">{ `#${ sample.pk }` }</span>
						<code className="rewrite-preview__old">{ sample.old }</code>
						<span className="rewrite-preview__arrow">→</span>
						<code className="rewrite-preview__new">{ sample.new }</code>
					</div>
				) ) : null }
			</div>
		</div>
	);
}

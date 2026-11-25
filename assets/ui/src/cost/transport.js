/**
 * admin-ajax transport for the cost estimator.
 *
 * The endpoint is intentionally not REST: it is a CPU-only calculator and the
 * legacy page already uses this action, so a fallback and the island cannot
 * diverge in validation.
 */
export async function estimate( config, fields ) {
	const admin = window.SakuraAdmin;

	if ( ! admin || ! admin.api || ! admin.api.ajax ) {
		throw new Error( ( config.strings || {} ).unknown || '估算失败，请稍后重试。' );
	}

	return admin.api.ajax(
		'sakura_storage_cost_estimate',
		{
			nonce: ( config || {} ).nonce || '',
			...fields,
		},
		{ timeout: 30000 }
	);
}

/**
 * Batch dispatch settings for the delete queue.
 *
 * Pure presentation: the policy and the live queue counters arrive in the
 * snapshot, saving goes through the REST route. No server-rendered form.
 */
import { useState } from 'react';
import { Card } from '../shared/ui.jsx';
import { postAction } from './transport.js';

const NUMBERS = [
	{ key: 'batch_size', label: '每车件数', hint: '凑满这么多就立即执行', min: 1, max: 200, unit: '件' },
	{ key: 'max_wait', label: '最长等待', hint: '等这么久还没凑够也执行；0 = 立即', min: 0, max: 86400, unit: '秒' },
	{ key: 'batch_bytes', label: '每车最大体积', hint: '达到这个体积就执行；0 = 不限', min: 0, max: 10240, unit: 'MB' },
	{ key: 'batch_time', label: '单次运行上限', hint: '一次最多跑多久，防止请求超时', min: 10, max: 60, unit: '秒' },
];

export default function DispatchCard( { dispatch, strings, rest, onSaved } ) {
	const labels = ( strings && strings.dispatch ) || {};
	const [ form, setForm ] = useState( dispatch && dispatch.policy ? dispatch.policy : {} );
	const [ busy, setBusy ] = useState( false );
	const [ note, setNote ] = useState( '' );
	const queued = ( dispatch && dispatch.queued ) || {};

	const change = ( key, value ) => {
		setForm( ( current ) => ( { ...current, [ key ]: value } ) );
	};

	const save = () => {
		if ( busy ) {
			return;
		}

		setBusy( true );
		setNote( '' );

		postAction( rest, 'dispatch', form )
			.then( ( data ) => {
				setNote( labels.saved || '已保存，下一批生效。' );

				if ( onSaved ) {
					onSaved( data );
				}
			} )
			.catch( ( error ) => {
				setNote( String( ( error && error.message ) || '保存失败，请稍后重试。' ) );
			} )
			.finally( () => setBusy( false ) );
	};

	const oldest = Number( queued.oldest || 0 );
	const wait = Number( form.max_wait || 0 );
	const waited = oldest > 0 ? Math.max( 0, Math.floor( Date.now() / 1000 ) - oldest ) : 0;
	const remain = oldest > 0 && wait > 0 ? Math.max( 0, wait - waited ) : 0;
	const count = Number( queued.count || 0 );
	const bytes = Number( queued.bytes || 0 );
	const status = count > 0
		? ( form.enabled
			? '候车 ' + count + ' 件 / ' + Math.round( bytes / 1048576 ) + ' MB；最早一件已等 ' + waited + ' 秒'
				+ ( wait > 0 ? '，最迟 ' + remain + ' 秒后执行。' : '，下个检查点执行。' )
			: '攒批已关闭：任务进入队列后立即执行。' )
		: '队列空闲，没有等待中的任务。';

	return (
		<Card card={ { id: 'delete-dispatch', kicker: labels.kicker || 'DISPATCH', title: labels.title || '批量调度', description: labels.desc || '任务先进队列，凑够一批或等满时间再执行；关闭后立即执行。' } }>
			<div className="field-list">
				<div className="field field--check field--full">
					<div className="field__control">
						<label className="check check--stacked">
							<input
								type="checkbox"
								className="is-switch"
								checked={ Boolean( form.enabled ) }
								onChange={ ( event ) => change( 'enabled', event.target.checked ? 1 : 0 ) }
							/>
							<span className="check__text">
								<strong className="check__title">{ labels.enabled || '启用批量调度' }</strong>
								<span className="check__hint">{ labels.enabledHint || '关闭后每条删除立即执行，等同原有行为。' }</span>
							</span>
						</label>
					</div>
				</div>

				{ NUMBERS.map( ( item ) => (
					<div className="field field--limits" key={ item.key }>
						<div className="field__main">
							<label className="field__label" htmlFor={ 'dispatch-' + item.key }>{ item.label }</label>
							<p className="field__hint">{ item.hint }</p>
						</div>
						<div className="field__control">
							<span className="field__inline">
								<span className="field__inline-part">
									<input
										id={ 'dispatch-' + item.key }
										type="number"
										inputMode="numeric"
										min={ item.min }
										max={ item.max }
										value={ form[ item.key ] == null ? '' : form[ item.key ] }
										onChange={ ( event ) => change( item.key, event.target.value ) }
									/>
									<span className="field__inline-label">{ item.unit }</span>
								</span>
							</span>
						</div>
					</div>
				) ) }
			</div>

			<p className="small-text">{ status }</p>

			<div className="card__savebar">
				{ note ? <span className="card-savebar__note">{ note }</span> : null }
				<button type="button" className="btn btn--primary" disabled={ busy } onClick={ save }>
					{ busy ? ( labels.saving || '保存中…' ) : ( labels.save || '保存设置' ) }
				</button>
			</div>
		</Card>
	);
}

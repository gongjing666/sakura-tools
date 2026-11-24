/**
 * The sync center island.
 *
 * The server decides every number; this component arranges them and owns the
 * interactions the classic controller used to bind by hand. Requests still go
 * to the same admin-ajax handlers, so scan/refresh/retry/adopt behave exactly
 * as they did before the move.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { PageHead } from '../shared/ui.jsx';
import TasksCard from './tasks.jsx';
import HealthCard from './health.jsx';
import StorageCard from './storage.jsx';
import ReconcileCard from './reconcile.jsx';
import LogsCard from './logs.jsx';
import IgnoredCard from './ignored.jsx';
import NotesCard from './notes.jsx';
import Dialogs from './dialogs.jsx';
import { ajax, errorText, toast, restoreToast, reloadWithToast } from './transport.js';
import { Frontline, KpiStrip } from './overview.jsx';

export default function App( { boot } ) {
	const config = boot.config || {};
	const strings = boot.strings || {};
	const view = boot.view || {};
	const [ live, setLive ] = useState( () => ( {
		scan: view.scan || {},
		rows: boot.rows || [],
		logs: boot.logs || [],
	} ) );
	const [ notice, setNotice ] = useState( null );
	const [ error, setError ] = useState( '' );
	const tokenRef = useRef( 0 );
	const busyRef = useRef( false );

	const locked = Boolean( view.locked );
	const frozen = Boolean( view.frozen );
	const gated = Boolean( view.reconcile_gated );
	const indexEnabled = Boolean( view.index_enabled );

	const stringsAll = useMemo( () => ( { ...strings, ...( config.strings || {} ) } ), [ strings, config.strings ] );

	useEffect( () => {
		restoreToast();
	}, [] );

	/** Non-stale guard for polling: a new scan replaces the old follower. */
	const follow = ( scanId ) => {
		const token = ++tokenRef.current;
		const delays = [ 400, 1000, 2000, 5000 ];
		let attempt = 0;

		return new Promise( ( resolve, reject ) => {
			const step = () => {
				if ( token !== tokenRef.current ) {
					resolve( { state: 'detached' } );
					return;
				}

				ajax( 'sakura_s3_sync_scan_step', { scan_id: scanId, nonce: config.scanNonce }, { timeout: 30000 } )
					.then( ( data ) => {
						if ( token !== tokenRef.current ) {
							resolve( { state: 'detached' } );
							return;
						}

						setLive( ( current ) => ( {
							...current,
							scan: {
								...current.scan,
								id: Number( data.scan_id || scanId ),
								state: String( data.state || '' ),
								phase: String( data.phase || '' ),
								running: 'queued' === data.state || 'running' === data.state,
								processed: Number( data.processed || 0 ),
								busy: Boolean( data.busy ),
							},
						} ) );

						if ( 'queued' === data.state || 'running' === data.state ) {
							attempt = Math.min( attempt + 1, delays.length - 1 );
							window.setTimeout( step, delays[ attempt ] );
							return;
						}

						resolve( data );
					} )
					.catch( reject );
			};

			step();
		} );
	};

	/** One scan-start / resume path, shared by the buttons and the dialog. */
	const startScan = ( mode ) => {
		if ( busyRef.current ) {
			return;
		}

		busyRef.current = true;
		const action = 'resume' === mode ? 'sakura_s3_sync_resume' : 'sakura_s3_sync_scan';
		const nonce = 'resume' === mode ? config.resumeNonce : config.scanNonce;

		ajax( action, { nonce }, { timeout: 60000 } )
			.then( ( data ) => {
				const state = String( data.state || '' );

				if ( ! data.scan_id ) {
					throw new Error( stringsAll.unknownError );
				}

				if ( 'done' === state ) {
					reloadWithToast( data.message || stringsAll.done );
					return null;
				}

				if ( 'queued' !== state && 'running' !== state ) {
					reloadWithToast( data.message || stringsAll.done );
					return null;
				}

				setLive( ( current ) => ( {
					...current,
					scan: {
						...current.scan,
						id: Number( data.scan_id ),
						state,
						phase: String( data.phase || '' ),
						running: true,
						processed: Number( ( data.progress && data.progress.processed ) || 0 ),
					},
				} ) );

				return follow( data.scan_id ).then( ( final ) => {
					reloadWithToast(
						( final && final.message ) || stringsAll.done,
						'cancelled' === ( final && final.state ) ? 'warn' : 'ok'
					);

					return null;
				} );
			} )
			.catch( ( err ) => {
				toast( errorText( err, stringsAll.unknownError ), 'bad' );
			} )
			.finally( () => {
				busyRef.current = false;
			} );
	};

	const refreshDiff = ( button ) => {
		if ( busyRef.current ) {
			return;
		}

		busyRef.current = true;
		const restore = button ? window.SakuraAdmin.ui.busy( button, true ) : null;

		ajax( 'sakura_s3_sync_refresh', { nonce: config.refreshNonce }, { timeout: 60000 } )
			.then( ( data ) => {
				reloadWithToast( data.message || stringsAll.refreshed );
			} )
			.catch( ( err ) => {
				toast( errorText( err, stringsAll.unknownError ), 'bad' );
			} )
			.finally( () => {
				if ( restore ) {
					restore();
				}

				busyRef.current = false;
			} );
	};

	const cancelScan = () => {
		ajax( 'sakura_s3_sync_cancel', { scan_id: Number( live.scan.id || 0 ), nonce: config.cancelNonce }, { timeout: 30000 } )
			.then( () => {
				++tokenRef.current;
				reloadWithToast( stringsAll.cancelled, 'warn' );
			} )
			.catch( ( err ) => {
				toast( errorText( err, stringsAll.cancelFailed ), 'bad' );
			} );
	};

	const retryAll = () => {
		ajax( 'sakura_s3_retry', { _ajax_nonce: config.retryNonce, attachment_id: '0' }, { timeout: 120000 } )
			.then( ( data ) => {
				reloadWithToast( data.message || stringsAll.retryDone );
			} )
			.catch( ( err ) => {
				toast( errorText( err, stringsAll.unknownError ), 'bad' );
			} );
	};

	const toggleIndex = () => {
		ajax(
			'sakura_s3_sync_toggle_index',
			{ enabled: indexEnabled ? '0' : '1', nonce: config.toggleNonce },
			{ timeout: 30000 }
		)
			.then( ( data ) => {
				reloadWithToast( data.message || stringsAll.actionDone );
			} )
			.catch( ( err ) => {
				toast( errorText( err, stringsAll.unknownError ), 'bad' );
			} );
	};

	const testConnection = ( button ) => {
		const restore = button ? window.SakuraAdmin.ui.busy( button, true ) : null;

		ajax( 'sakura_s3_sync_test', { nonce: config.testNonce }, { timeout: 30000 } )
			.then( ( data ) => {
				toast( data.message || stringsAll.testDone );
				const pill = document.querySelector( '.status-card__ping' );

				if ( pill && data.latency != null ) {
					const text = pill.querySelector( '.status-card__ping-text' );

					if ( text ) {
						text.textContent = 'headBucket';

						const ms = pill.querySelector( '[data-sakura-ping-ms]' );

						if ( ms ) {
							ms.textContent = Number( data.latency ) + ' ms';
						}
					}
				}
			} )
			.catch( ( err ) => {
				toast( errorText( err, stringsAll.unknownError ), 'bad' );
			} )
			.finally( () => {
				if ( restore ) {
					restore();
				}
			} );
	};

	const actions = {
		scan: () => startScan( 'full' ),
		refresh: ( button ) => refreshDiff( button ),
		cancel: cancelScan,
		resume: () => startScan( 'resume' ),
		'retry-all': retryAll,
		'toggle-index': toggleIndex,
		'test-connection': testConnection,
	};

	const setRows = ( rows ) => setLive( ( current ) => ( { ...current, rows } ) );

	return (
		<>
			<PageHead head={ boot.head } />
			<div className="page-body">
				{ error ? (
					<div className="system-note" role="alert">
						<p>{ error }</p>
					</div>
				) : null }
				<Notices view={ view } strings={ stringsAll } onScan={ actions.scan } onToggle={ toggleIndex } />
				<Frontline frontline={ view.frontline } />
				<KpiStrip kpis={ view.kpis } />
				<div className="card-grid" data-sakura-layout={ boot.page.id }>
					<IgnoredCard
						card={ findCard( boot.cards, 'ignored' ) }
						view={ view }
						strings={ strings }
						frozen={ frozen }
					/>
					<TasksCard
						card={ findCard( boot.cards, 'tasks' ) }
						view={ view }
						rows={ boot.rows }
						scan={ live.scan }
						strings={ stringsAll }
						locked={ locked }
						frozen={ frozen }
						indexEnabled={ indexEnabled }
						onAction={ actions }
					/>
					<HealthCard
						card={ findCard( boot.cards, 'health' ) }
						view={ view }
						strings={ stringsAll }
						config={ config }
						frozen={ frozen }
						indexEnabled={ indexEnabled }
						onToggle={ toggleIndex }
						onTest={ actions[ 'test-connection' ] }
					/>
					<StorageCard
						card={ findCard( boot.cards, 'storage' ) }
						view={ view }
						scan={ live.scan }
						strings={ stringsAll }
						locked={ locked }
						frozen={ frozen }
						onScan={ actions.scan }
						onResume={ actions.resume }
					/>
					<NotesCard card={ findCard( boot.cards, 'notes' ) } notes={ strings.notes } />
					<ReconcileCard
						card={ findCard( boot.cards, 'reconcile' ) }
						view={ view }
						rows={ live.rows }
						config={ config }
						strings={ stringsAll }
						locked={ locked }
						frozen={ frozen }
						gated={ gated }
						onScan={ actions.scan }
						onRows={ setRows }
						onError={ setError }
						onNotice={ setNotice }
					/>
					<LogsCard
						card={ findCard( boot.cards, 'logs' ) }
						logs={ live.logs }
						config={ config }
						strings={ stringsAll }
					/>
				</div>
			</div>
			<Dialogs
				notice={ notice }
				onClose={ () => setNotice( null ) }
				config={ config }
				onScan={ () => {
					setNotice( null );
					actions.scan();
				} }
			/>
			<LatencyPill config={ config } strings={ stringsAll } />
		</>
	);
}

function findCard( cards, id ) {
	return ( cards || [] ).find( ( card ) => card.id === id ) || { id: id, title: '' };
}

/** The state strips above the grid, in the same order the classic page used. */
function Notices( { view, strings, onScan, onToggle } ) {
	const items = [];

	if ( view.frozen ) {
		items.push(
			<div className="notice-inline notice-inline--warn" role="status" key="frozen">
				<p>对象存储已停用：核对、上传、下载与删除都暂时停下，下方的数字停留在停用之前。重新启用后可以继续。</p>
			</div>
		);
	}

	if ( view.reconcile_gated ) {
		const from = view.reconcile_gate && view.reconcile_gate.from ? view.reconcile_gate.from : '';
		const bucket = view.reconcile_gate && view.reconcile_gate.bucket ? view.reconcile_gate.bucket : '';
		const text = from
			? '存储目标已从「' + from + '」切换到当前配置（桶 ' + bucket + '），下方的数字还是上一个桶的。下载、上传、删除与刷新差异暂时停下，点「开始完整核对」重建索引后自动恢复。'
			: '存储目标已切换，下方的数字还是上一个桶的。下载、上传、删除与刷新差异暂时停下，点「开始完整核对」重建索引后自动恢复。';

		items.push(
			<div className="notice-inline notice-inline--warn" role="status" key="gated">
				<p>{ text }</p>
			</div>
		);
	}

	if ( ! view.index_enabled ) {
		items.push(
			<div className="alert alert--warn" role="status" key="compat">
				<strong className="alert__title">兼容模式</strong>
				<div className="alert__body" dangerouslySetInnerHTML={ { __html: view.legacy_message || '' } } />
				<div className="button-row">
					<button
						type="button"
						className="btn btn--small"
						data-sakura-action="toggle-index"
						disabled={ view.frozen }
						onClick={ onToggle }
					>
						启用同步记录
					</button>
				</div>
			</div>
		);
	}

	if ( view.scan.running || view.scan.resumable ) {
		const text = view.scan.running
			? '核对进行中（' + ( view.scan.phase_label || '准备中' ) + '，已处理 ' + view.scan.processed + ' 个），可关闭页面由后台继续；也可随时停止。'
			: '上一次核对中断，同步记录仍可用；点击“继续核对”会从断点续跑，不会产生重复行。';

		items.push(
			<div className="notice-inline notice-inline--info" role="status" key="scan">
				<p>{ text }</p>
			</div>
		);
	}

	if ( view.calibrate_stale && ! view.locked ) {
		items.push(
			<div className="notice-inline notice-inline--warn" role="status" key="calibrate">
				<p>云端数据已超过 30 天未校准，期间的对象增删不会体现在下方统计里。校准会自动排队，也可以用下面的按钮现在开始。</p>
				<div className="button-row">
					<button type="button" className="btn btn--small" disabled={ view.frozen } onClick={ onScan }>
						立即校准
					</button>
				</div>
			</div>
		);
	}

	if ( view.difference > 0 || view.failed_total > 0 ) {
		items.push(
			<div className="alert alert--warn" role="status" key="diff">
				<div
					className="alert__body"
					dangerouslySetInnerHTML={ {
						__html:
							'最近一次全量核对发现 <strong>' +
							view.difference +
							' 项差异</strong>：' +
							view.pending +
							' 个只在本地上传、' +
							view.failed_count +
							' 个上传失败、' +
							view.remote +
							' 个只在云端。建议先处理失败项，再执行批量上传（按附件补齐全部分辨率并逐个校验）。',
					} }
				/>
			</div>
		);
	}

	if ( ! items.length ) {
		return null;
	}

	return <div className="sync-notices">{ items }</div>;
}

/** Hero pill: re-measures the bucket every 30s while the tab is visible. */
function LatencyPill( { config, strings } ) {
	useEffect( () => {
		const pill = document.querySelector( '[data-sakura-ping]' );

		if ( ! pill ) {
			return undefined;
		}

		const recent = [];
		let timer = null;
		let stopped = false;

		const render = ( state, text, ms ) => {
			pill.setAttribute( 'data-state', state );
			const label = pill.querySelector( '.status-card__ping-text' );

			if ( label ) {
				label.textContent = text;
			}

			const value = pill.querySelector( '[data-sakura-ping-ms]' );

			if ( value ) {
				value.textContent = ms == null ? '' : ms + ' ms';
			}

			pill.setAttribute( 'title', ( strings.latencyHint || '' ) + ( ms == null ? '' : ' · ' + ms + ' ms' ) );
		};

		const measure = () => {
			if ( stopped ) {
				return;
			}

			ajax( 'sakura_s3_latency', { nonce: config.actionNonce }, { timeout: 12000 } )
				.then( ( data ) => {
					const ms = Number( data.ms || 0 );
					recent.push( ms );

					while ( recent.length > 3 ) {
						recent.shift();
					}

					const sorted = recent.slice().sort( ( a, b ) => a - b );
					const median = sorted[ Math.floor( sorted.length / 2 ) ];
					const state = median < 200 ? 'good' : median <= 500 ? 'slow' : 'bad';
					const label = 'good' === state ? strings.latencyOk : 'slow' === state ? strings.latencySlow : strings.latencyBad;

					render( state, label, median );
				} )
				.catch( () => {
					render( 'unknown', strings.latencyFail, null );
				} );
		};

		const start = () => {
			stopped = false;
			measure();
			timer = window.setInterval( measure, Number( config.latencyMs || 30000 ) );
		};

		const stop = () => {
			stopped = true;

			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		};

		const onVisibility = () => {
			if ( document.hidden ) {
				stop();
			} else {
				start();
			}
		};

		start();
		document.addEventListener( 'visibilitychange', onVisibility );

		return () => {
			stop();
			document.removeEventListener( 'visibilitychange', onVisibility );
		};
	}, [ config, strings ] );

	return null;
}
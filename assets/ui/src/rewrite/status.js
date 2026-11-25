export const STATUS = {
	idle: { label: '未扫描', tone: 'neutral', preview: false, canRun: false },
	scanning: { label: '扫描中', tone: 'warn', preview: false, canRun: false },
	ready: { label: '待执行', tone: 'warn', preview: true, canRun: true },
	running: { label: '改写中', tone: 'warn', preview: true, canRun: true },
	done: { label: '已完成', tone: 'ok', preview: true, canRun: false },
};

export function statusInfo( status, strings = {} ) {
	const info = STATUS[ status ] || STATUS.idle;
	const labels = {
		idle: strings.statusIdle,
		scanning: strings.statusScanning,
		ready: strings.statusReady,
		running: strings.statusRunning,
		done: strings.statusDone,
	};

	return { ...info, label: labels[ status ] || info.label };
}

export function numberLabel( value ) {
	const parsed = Number( value );

	return Number.isFinite( parsed ) ? parsed.toLocaleString( 'zh-CN' ) : '0';
}

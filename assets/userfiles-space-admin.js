/**
 * Sakura Tools - admin behaviour for the "Zibll only" tab (P0).
 *
 * Two features only:
 *  1. live running total of the MB inputs inside each bonus form;
 *  2. a confirm() gate in front of the destructive switches.
 *
 * No jQuery, no globals except the localised window.sufmSpaceCfg.
 */
(function () {
	'use strict';

	var cfg = window.sufmSpaceCfg || {};
	var strings = cfg.strings || {};

	/**
	 * Ask the admin layout engine to place the cards again after async content
	 * changed a card height (grants ledger, reconcile result). The engine
	 * already listens for `resize` and re-runs its layout on a 120ms debounce,
	 * so this reuses that path instead of measuring heights by hand.
	 *
	 * @return {void}
	 */
	function relayout() {
		window.setTimeout(function () {
			window.dispatchEvent(new Event('resize'));
		}, 0);
	}

	/**
	 * Formats a number of megabytes the way an admin reads it.
	 *
	 * @param {number} megabytes Raw megabyte total.
	 * @return {string} Human readable size.
	 */
	function formatMegabytes(megabytes) {
		var value = Number(megabytes);

		if (!isFinite(value) || value <= 0) {
			return '0 ' + (strings.unitMb || 'MB');
		}

		if (value >= 1024) {
			return (Math.round((value / 1024) * 10) / 10) + ' ' + (strings.unitGb || 'GB');
		}

		return (Math.round(value * 10) / 10) + ' ' + (strings.unitMb || 'MB');
	}

	/**
	 * Recomputes the running total of one form.
	 *
	 * @param {HTMLFormElement} form Form element.
	 * @return {void}
	 */
	function updateTotal(form) {
		if (!form) {
			return;
		}

		var output = form.querySelector('[data-sufm-space-total] strong');

		// The total may sit outside the form (footer slot); fall back to the document.
		if (!output) {
			output = document.querySelector('[data-sufm-space-total] strong');
		}

		if (!output) {
			return;
		}

		var inputs = form.querySelectorAll('[data-sufm-space-mb]');
		var total = 0;

		for (var i = 0; i < inputs.length; i++) {
			var value = inputs[i].value;

			// An empty number input must count as 0, not NaN.
			var raw = ('' === value || null === value) ? 0 : parseFloat(value);

			if (isFinite(raw) && raw > 0) {
				total += raw;
			}
		}

		output.textContent = formatMegabytes(total);
	}

	/**
	 * Wires every bonus form on the page.
	 *
	 * @return {void}
	 */
	function bindTotals() {
		var outputs = document.querySelectorAll('[data-sufm-space-total]');

		for (var i = 0; i < outputs.length; i++) {
			var form = outputs[i].closest('form');

			if (!form) {
				continue;
			}

			updateTotal(form);

			form.addEventListener('input', function (event) {
				var target = event.target;

				if (!target || !target.hasAttribute || !target.hasAttribute('data-sufm-space-mb')) {
					return;
				}

				updateTotal(target.form || form);
			});
		}
	}

	/**
	 * Asks for confirmation before a destructive switch changes state.
	 *
	 * @param {Event} event Change event.
	 * @return {void}
	 */
	function guardSwitch(event) {
		var node = event.target;

		if (!node || !node.hasAttribute || !node.hasAttribute('data-sufm-space-confirm')) {
			return;
		}

		var kind = node.getAttribute('data-sufm-space-confirm');
		var message = '';

		if (kind === 'enabled') {
			message = node.checked ? strings.enableWarn : strings.disableWarn;
		} else if (kind === 'apply_bonuses') {
			message = node.checked ? '' : strings.flashWarn;
		}

		if (!message) {
			return;
		}

		if (!window.confirm(message)) {
			node.checked = !node.checked;
		}
	}

	/**
	 * Boots the page behaviour.
	 *
	 * @return {void}
	 */
	function boot() {
		bindTotals();
		bindGrants();
		bindPackages();
		bindIconPicker();
		bindPoints();
		bindReconcile();

		// Run once more after the first paint: guards against footer script / DOM order races.
		if (window.requestAnimationFrame) {
			window.requestAnimationFrame(bindTotals);
			window.requestAnimationFrame(bindGrants);
		}

		document.addEventListener('click', grantAction);
		document.addEventListener('change', guardSwitch, true);
	}

	/**
	 * Renders one ledger row. Uses textContent only: the payload is user data.
	 *
	 * @param {Object} row Grant row from the REST payload.
	 * @return {HTMLElement} Row element.
	 */
	function grantsRow(row) {
		var element = document.createElement('div');
		var state = String(row.state || '');

		element.className = 'sufm-space-row' + (state === 'pending' ? ' is-pending' : '');

		var cells = [
			String(row.created_at || ''),
			String(row.owner_id || '') + ' · ' + String(row.label || ''),
			String(row.bytes_human || ''),
			row.permanent ? (strings.permanent || '永久') : String(row.expires_at || ''),
			String(row.state_label || state)
		];

		for (var i = 0; i < cells.length; i++) {
			var cell = document.createElement('span');
			cell.textContent = cells[i];

			if (i === 4) {
				cell.className = state === 'active' ? 'is-state-active' : 'is-state-muted';
			}

			element.appendChild(cell);
		}

		var actions = document.createElement('span');
		actions.className = 'sufm-space-grant-actions';

		if (state === 'active' && row.id) {
			var extend = document.createElement('button');
			extend.type = 'button';
			extend.className = 'btn btn--sm';
			extend.setAttribute('data-sufm-grant-extend', String(row.id));
			extend.textContent = (strings.grantExtend || '延长');
			actions.appendChild(extend);

			var revoke = document.createElement('button');
			revoke.type = 'button';
			revoke.className = 'btn btn--sm';
			revoke.setAttribute('data-sufm-grant-revoke', String(row.id));
			revoke.textContent = (strings.grantRevoke || '撤销');
			actions.appendChild(revoke);
		}

		element.appendChild(actions);

		return element;
	}

	/**
	 * Loads the grants ledger into #sufm-space-grants when the card exists.
	 *
	 * @return {void}
	 */
	function bindGrants() {
		var host = document.getElementById('sufm-space-grants');

		if (!host || host.getAttribute('data-sufm-space-bound') === '1') {
			return;
		}

		host.setAttribute('data-sufm-space-bound', '1');

		var cfg = window.sufmSpaceCfg || {};

		function render() {
			window.fetch(cfg.restUrl + 'admin/space/grants', {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.nonce || '' }
			}).then(function (response) {
				return response.json().then(function (payload) {
					if (!response.ok) {
						throw new Error(payload && payload.message ? payload.message : '');
					}

					return payload;
				});
			}).then(function (payload) {
				var items = payload && payload.items ? payload.items : [];

				host.textContent = '';

				if (!items.length) {
					var empty = document.createElement('p');
					empty.className = 'sufm-space-muted';
					empty.textContent = (cfg.strings && cfg.strings.grantsEmpty) ? cfg.strings.grantsEmpty : '暂无授权记录';
					host.appendChild(empty);
					relayout();
					return;
				}

				for (var i = 0; i < items.length; i++) {
					host.appendChild(grantsRow(items[i]));
				}

				relayout();
			}).catch(function (error) {
				host.textContent = '';

				var node = document.createElement('p');
				node.className = 'sufm-space-grants-error';
				node.textContent = (error && error.message) ? error.message : ((cfg.strings && cfg.strings.grantsError) ? cfg.strings.grantsError : '加载失败，请刷新重试');
				host.appendChild(node);
			});
		}

		render();

		var button = document.getElementById('sufm-space-grants-refresh');

		if (button) {
			button.addEventListener('click', function (event) {
				event.preventDefault();
				render();
			});
		}
	}

	/**
	 * Plan table: one card, two product lines (A = cash, P = points).
	 *
	 * Rows can be added or marked for deletion freely; the option keeps whatever
	 * is left after the hidden remove flag is submitted.
	 *
	 * @return {void}
	 */
	function bindPackages() {
		var form = document.querySelector('[data-sufm-space-plans]');

		if (!form || form.getAttribute('data-sufm-space-bound') === '1') {
			return;
		}

		form.setAttribute('data-sufm-space-bound', '1');

		var error = form.querySelector('[data-sufm-space-pkg-error]');

		function rowsOf(kind) {
			var host = form.querySelector(kind === 'cash' ? '.sufm-space-packages' : '.sufm-space-ppkgs');

			if (!host) {
				return [];
			}

			return Array.prototype.slice.call(host.querySelectorAll('[data-sufm-pkg-row]')).filter(function (row) {
				return row.getAttribute('class') ? row.getAttribute('class').indexOf('is-head') === -1 : true;
			});
		}

		function nextId(kind) {
			var prefix = kind === 'cash' ? 'A' : 'P';
			var max = 0;

			rowsOf(kind).forEach(function (row) {
				var field = row.querySelector('[data-sufm-pkg-id]');
				var match = /^[AP](\d+)$/.exec(field ? String(field.value || '').trim().toUpperCase() : '');

				if (match && Number(match[1]) > max) {
					max = Number(match[1]);
				}
			});

			return prefix + (max + 1);
		}

		function addRow(kind) {
			var tpl = document.querySelector('[data-sufm-plan-template="' + kind + '"]');
			var host = form.querySelector(kind === 'cash' ? '.sufm-space-packages' : '.sufm-space-ppkgs');

			if (!tpl || !host || !tpl.content || !tpl.content.firstElementChild) {
				return;
			}

			var row = tpl.content.firstElementChild.cloneNode(true);
			var key = 'new' + Date.now() + String(Math.floor(Math.random() * 1000));

			Array.prototype.slice.call(row.querySelectorAll('[name]')).forEach(function (field) {
				field.name = field.name.replace('__KEY__', key);
			});

			var idField = row.querySelector('[data-sufm-pkg-id]');

			if (idField) {
				idField.value = nextId(kind);
			}

			host.appendChild(row);

			var focus = row.querySelector('[data-sufm-pkg-label]') || row.querySelector('input[type="number"]');

			if (focus) {
				focus.focus();
			}

			summaryOf(row);
		}

		function summaryOf(row) {
			var out = row.querySelector('[data-sufm-plan-summary]');

			if (!out) {
				return;
			}

			var gbField = row.querySelector('[data-sufm-plan-field="gb"]');
			var dayField = row.querySelector('[data-sufm-plan-field="days"]');
			var gb = gbField ? parseFloat(gbField.value) : NaN;

			if (!(gb > 0)) {
				out.textContent = '\u2014';
				return;
			}

			var days = dayField ? parseInt(dayField.value, 10) : 0;

			out.textContent = String(Math.round(gb * 100) / 100) + ' GB \u00b7 ' + (days > 0 ? days + ' \u5929' : '\u6c38\u4e45');
		}

		form.addEventListener('change', function (event) {
			var box = event.target;

			if (!box || !box.hasAttribute || !box.hasAttribute('data-sufm-pkg-remove')) {
				return;
			}

			var row = box.closest('[data-sufm-pkg-row]');

			if (!row) {
				return;
			}

			var flag = row.querySelector('[data-sufm-pkg-remove-value]');

			if (box.checked) {
				// An unsaved row carries no history, so it can go right away.
				if (row.getAttribute('class') && row.getAttribute('class').indexOf('is-new') !== -1) {
					row.parentNode.removeChild(row);
					return;
				}

				row.classList.add('is-removed');

				if (flag) {
					flag.value = '1';
				}

				return;
			}

			row.classList.remove('is-removed');

			if (flag) {
				flag.value = '0';
			}
		});

		form.addEventListener('input', function (event) {
			var field = event.target;

			if (!field || !field.getAttribute) {
				return;
			}

			var kind = field.getAttribute('data-sufm-plan-field');

			if (kind !== 'gb' && kind !== 'days') {
				return;
			}

			var row = field.closest('[data-sufm-pkg-row]');

			if (row) {
				summaryOf(row);
			}
		});

		form.addEventListener('click', function (event) {
			var button = event.target;

			if (!button || !button.hasAttribute || !button.hasAttribute('data-sufm-plan-add')) {
				return;
			}

			event.preventDefault();
			addRow(button.getAttribute('data-sufm-plan-add') === 'points' ? 'points' : 'cash');
		});

		form.addEventListener('submit', function () {
			if (error) {
				error.textContent = '';
			}
		});
	}

	/**
	 * Points card: keep「单笔上限」never above「每日上限」on the client too.
	 *
	 * @return {void}
	 */
	function bindPoints() {
		var rows = document.querySelectorAll('.sufm-space-points');

		for (var i = 0; i < rows.length; i++) {
			var host = rows[i];

			if (host.getAttribute('data-sufm-space-bound') === '1') {
				continue;
			}

			host.setAttribute('data-sufm-space-bound', '1');
		}
	}

	/**
	 * Reconcile card: dry run first, then an explicit second click to heal.
	 *
	 * @return {void}
	 */
	function bindReconcile() {
		var check = document.getElementById('sufm-space-reconcile-check');
		var apply = document.getElementById('sufm-space-reconcile-apply');

		if (!check || check.getAttribute('data-sufm-space-bound') === '1') {
			return;
		}

		check.setAttribute('data-sufm-space-bound', '1');

		var cfg = window.sufmSpaceCfg || {};
		var summary = document.querySelector('[data-sufm-space-reconcile-summary]');
		var list = document.querySelector('[data-sufm-space-reconcile-list]');

		function setSummary(message, isError) {
			if (!summary) {
				return;
			}

			summary.textContent = message;
			summary.className = 'sufm-space-reconcile-summary' + (isError ? ' is-error' : '');
		}

		function clearList() {
			if (list) {
				list.textContent = '';
			}
		}

		function renderItems(payload) {
			clearList();

			if (!list || !payload || !payload.items) {
				return;
			}

			for (var i = 0; i < payload.items.length; i++) {
				var item = payload.items[i];
				var row = document.createElement('div');
				row.className = 'sufm-space-row';

				var cells = [
					String(item.order_num || ''),
					String(item.user_id || ''),
					String(item.price || ''),
					String(item.pay_time || '')
				];

				for (var c = 0; c < cells.length; c++) {
					var cell = document.createElement('span');
					cell.textContent = cells[c];
					row.appendChild(cell);
				}

				list.appendChild(row);
			}

			relayout();
		}

		function post(dryRun) {
			return window.fetch(cfg.restUrl + 'admin/space/reconcile', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce || ''
				},
				body: JSON.stringify({ dry_run: dryRun ? 1 : 0 })
			}).then(function (response) {
				return response.json().then(function (payload) {
					if (!response.ok) {
						throw new Error(payload && payload.message ? payload.message : '');
					}

					return payload;
				});
			});
		}

		function handle(payload) {
			if (!payload || payload.ready === false) {
				setSummary((payload && payload.error) ? payload.error : '对账查询失败。', true);
				clearList();
				return;
			}

			if (apply) {
				apply.disabled = !payload.missing;
			}

			if (payload.dry_run === false) {
				setSummary('已补发 ' + String(payload.healed || 0) + ' 笔授权。', false);

				var refresh = document.getElementById('sufm-space-grants-refresh');

				if (refresh) {
					refresh.click();
				}

				return;
			}

			setSummary('发现 ' + String(payload.missing || 0) + ' 笔「已付款但无授权」的订单。', false);
			renderItems(payload);
		}

		check.addEventListener('click', function (event) {
			event.preventDefault();
			setSummary('检查中…', false);

			post(true).then(handle).catch(function (error) {
				setSummary(error && error.message ? error.message : '检查失败，请重试。', true);
			});
		});

		if (apply) {
			apply.addEventListener('click', function (event) {
				event.preventDefault();

				var count = 0;

				if (list && list.querySelectorAll('.sufm-space-row').length) {
					count = list.querySelectorAll('.sufm-space-row').length;
				}

				if (!window.confirm('将按订单快照补发 ' + String(count) + ' 笔授权，确认？')) {
					return;
				}

				setSummary('修复中…', false);

				post(false).then(handle).catch(function (error) {
					setSummary(error && error.message ? error.message : '修复失败，请重试。', true);
				});
			});
		}
	}

	/**
	 * Delegated handler for the ledger row actions.
	 *
	 * @param {Event} event Click event.
	 * @return {void}
	 */
	function grantAction(event) {
		var target = event.target;

		if (!target || !target.closest) {
			return;
		}

		var cfg = window.sufmSpaceCfg || {};

		var extend = target.closest('[data-sufm-grant-extend]');

		if (extend) {
			event.preventDefault();

			var days = window.prompt('延长天数（1-3650）', '30');

			if (days === null) {
				return;
			}

			days = parseInt(days, 10);

			if (!isFinite(days) || days < 1 || days > 3650) {
				window.alert('天数必须在 1 到 3650 之间。');
				return;
			}

			window.fetch(cfg.restUrl + 'admin/space/grants/' + encodeURIComponent(extend.getAttribute('data-sufm-grant-extend')) + '/extend', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce || ''
				},
				body: JSON.stringify({ days: days })
			}).then(function (response) {
				return response.json().then(function (payload) {
					if (!response.ok) {
						throw new Error(payload && payload.message ? payload.message : '');
					}

					return payload;
				});
			}).then(function () {
				var refresh = document.getElementById('sufm-space-grants-refresh');

				if (refresh) {
					refresh.click();
				}
			}).catch(function (error) {
				window.alert(error && error.message ? error.message : '延长失败，请重试。');
			});

			return;
		}

		var revoke = target.closest('[data-sufm-grant-revoke]');

		if (!revoke) {
			return;
		}

		event.preventDefault();

		var typed = window.prompt('撤销是不可逆的资金动作：请输入「撤销」两个字确认。', '');

		if (typed === null) {
			return;
		}

		if (typed.trim() !== '撤销') {
			window.alert('输入不匹配，已中止。');
			return;
		}

		window.fetch(cfg.restUrl + 'admin/space/grants/' + encodeURIComponent(revoke.getAttribute('data-sufm-grant-revoke')) + '/revoke', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: JSON.stringify({ note: typed.trim() })
		}).then(function (response) {
			return response.json().then(function (payload) {
				if (!response.ok) {
					throw new Error(payload && payload.message ? payload.message : '');
				}

				return payload;
			});
		}).then(function () {
			var refresh = document.getElementById('sufm-space-grants-refresh');

			if (refresh) {
				refresh.click();
			}
		}).catch(function (error) {
			window.alert(error && error.message ? error.message : '撤销失败，请重试。');
		});
	}

	/**
	 * Lets every package row pick its order icon from the media library.
	 *
	 * The URL stays in a hidden-ish text field so admins can also paste a CDN
	 * address; the thumbnail is only a preview of that field.
	 *
	 * @return {void}
	 */
	function bindIconPicker() {
		var form = document.querySelector('[data-sufm-space-plans]');

		if (!form || form.getAttribute('data-sufm-icon-bound') === '1') {
			return;
		}

		form.setAttribute('data-sufm-icon-bound', '1');

		var frame = null;

		function cellOf(node) {
			return node && node.closest ? node.closest('.sufm-space-icon-cell') : null;
		}

		function repaint(cell) {
			if (!cell) {
				return;
			}

			var input = cell.querySelector('[data-sufm-icon-input]');
			var img = cell.querySelector('[data-sufm-icon-preview]');
			var clear = cell.querySelector('[data-sufm-icon-clear]');
			var value = input ? String(input.value || '').trim() : '';

			if (img) {
				img.src = value || img.getAttribute('data-default') || img.src;
			}

			if (clear) {
				clear.hidden = !value;
			}
		}

		form.addEventListener('click', function (event) {
			var cell = cellOf(event.target);

			if (!cell) {
				return;
			}

			if (event.target.closest('[data-sufm-icon-clear]')) {
				event.preventDefault();

				var input = cell.querySelector('[data-sufm-icon-input]');

				if (input) {
					input.value = '';
					input.dispatchEvent(new Event('change', { bubbles: true }));
				}

				repaint(cell);
				return;
			}

			if (!event.target.closest('[data-sufm-icon-pick]')) {
				return;
			}

			event.preventDefault();

			if (!window.wp || !window.wp.media) {
				return;
			}

			if (!frame) {
				frame = window.wp.media({
					title: strings.chooseIcon || '选择订单图标',
					library: { type: 'image' },
					button: { text: strings.chooseIcon || '使用这张图片' },
					multiple: false
				});

				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					var target = cellOf(frame.__sufmCell);
					var input = target ? target.querySelector('[data-sufm-icon-input]') : null;
					var sizes = attachment.sizes || {};
					var url = (sizes.thumbnail && sizes.thumbnail.url) || (sizes.medium && sizes.medium.url) || attachment.url || '';

					if (input) {
						input.value = attachment.url || url;
						input.dispatchEvent(new Event('change', { bubbles: true }));
					}

					repaint(target);
					frame.__sufmCell = null;
				});
			}

			frame.__sufmCell = cell;
			frame.open();
		});

		form.addEventListener('input', function (event) {
			if (event.target.matches && event.target.matches('[data-sufm-icon-input]')) {
				repaint(cellOf(event.target));
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();

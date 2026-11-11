/**
 * Controller for the Zibll-only file space tab.
 *
 * Uses the shared SakuraAdmin API only: no global state, no direct fetch and no
 * native prompts. All user-facing copy is injected through window.sakuraPage.
 */
(function (window, document) {
	'use strict';

	var admin = window.SakuraAdmin;

	if (!admin || typeof admin.ready !== 'function') {
		return;
	}

	var page = window.sakuraPage || {};
	var data = page.data || {};
	var strings = data.strings || {};
	var restUrl = data.restUrl || '';
	var nonce = data.nonce || '';

	function text(key, fallback) {
		return strings[key] || fallback || '';
	}

	function format(template, value) {
		return String(template || '').replace(/%s/g, String(value));
	}

	function number(value) {
		var parsed = parseFloat(value);
		return isFinite(parsed) ? parsed : 0;
	}

	function closest(node, selector) {
		if (!node || node.nodeType !== 1) {
			return null;
		}
		if (node.closest) {
			return node.closest(selector);
		}
		while (node && node.nodeType === 1) {
			if (node.matches && node.matches(selector)) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	function all(selector, context) {
		return Array.prototype.slice.call((context || document).querySelectorAll(selector));
	}

	function make(tag, className, content) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (undefined !== content && null !== content) {
			node.textContent = String(content);
		}
		return node;
	}

	function cell(label, value) {
		var td = document.createElement('td');
		td.setAttribute('data-label', label);
		if (value instanceof window.Node) {
			td.appendChild(value);
		} else {
			td.textContent = String(value === undefined || value === null ? '' : value);
		}
		return td;
	}

	function apiError(error, fallback) {
		if (error && error.payload) {
			if (error.payload.data && error.payload.data.message) {
				return String(error.payload.data.message);
			}
			if (error.payload.message) {
				return String(error.payload.message);
			}
		}
		return (error && error.message) ? String(error.message) : fallback;
	}

	function formatTotal(mb) {
		var value = number(mb);
		if (value <= 0) {
			return '0 ' + text('unitMb', 'MB');
		}
		if (value >= 1024) {
			return (Math.round(value / 1024 * 10) / 10) + ' ' + text('unitGb', 'GB');
		}
		return (Math.round(value * 10) / 10) + ' ' + text('unitMb', 'MB');
	}

	function ask(options) {
		return new Promise(function (resolve) {
			var root = admin.root() || document.body;
			var dialog = document.createElement('dialog');
			dialog.className = 'sakura-dialog';

			var form = document.createElement('form');
			form.className = 'sakura-dialog__form';
			form.method = 'dialog';

			var body = make('div', 'dialog__body');
			body.appendChild(make('h2', 'dialog__title', options.title || ''));
			if (options.message) {
				body.appendChild(make('p', 'dialog__message', options.message));
			}

			var input = document.createElement('input');
			input.type = options.type || 'text';
			input.value = options.value || '';
			input.setAttribute('aria-label', options.label || options.title || '');
			if (options.min) {
				input.min = String(options.min);
			}
			if (options.max) {
				input.max = String(options.max);
			}
			if (options.placeholder) {
				input.placeholder = options.placeholder;
			}
			body.appendChild(input);

			var actions = make('div', 'dialog__actions');
			var cancel = make('button', 'btn btn--ghost', options.cancelText || text('cancel', 'Cancel'));
			cancel.type = 'button';
			var confirm = make('button', options.danger ? 'btn btn--danger' : 'btn btn--primary', options.confirmText || text('confirm', 'OK'));
			confirm.type = 'button';
			actions.appendChild(cancel);
			actions.appendChild(confirm);
			body.appendChild(actions);

			form.appendChild(body);
			dialog.appendChild(form);
			root.appendChild(dialog);

			function close(value) {
				if (typeof dialog.close === 'function') {
					dialog.close(value);
				} else {
					dialog.setAttribute('open', '');
					resolve(value === 'ok' ? input.value : null);
					dialog.remove();
				}
			}

			cancel.addEventListener('click', function () {
				close('cancel');
			});
			confirm.addEventListener('click', function () {
				close('ok');
			});
			form.addEventListener('submit', function (event) {
				event.preventDefault();
			});

			dialog.addEventListener('close', function () {
				var value = dialog.returnValue === 'ok' ? input.value.trim() : null;
				dialog.remove();
				resolve(value);
			});

			if (typeof dialog.showModal === 'function') {
				dialog.showModal();
			} else {
				dialog.setAttribute('open', '');
			}
			input.focus();
			if (input.select) {
				input.select();
			}
		});
	}

	admin.ready(function () {
		var root = admin.root();
		if (!root) {
			return;
		}

		function updateTotals() {
			all('[data-zibll-total]', root).forEach(function (total) {
				var scope = closest(total, '.card') || root;
				var sum = 0;
				all('[data-zibll-mb]', scope).forEach(function (input) {
					sum += number(input.value);
				});
				var strong = total.querySelector('strong');
				if (strong) {
					strong.textContent = formatTotal(sum);
				}
			});
		}

		root.addEventListener('input', function (event) {
			if (event.target && event.target.matches && event.target.matches('[data-zibll-mb]')) {
				updateTotals();
			}
		});
		root.addEventListener('change', function (event) {
			if (event.target && event.target.matches && event.target.matches('[data-zibll-mb]')) {
				updateTotals();
			}
		});
		updateTotals();

		/* ---------------------------------------------------------------- switches */
		all('[data-zibll-switch]', root).forEach(function (input) {
			var previous = input.checked;

			function apply(value) {
				input.checked = value;
				previous = value;
			}

			input.addEventListener('change', function () {
				var next = input.checked;
				var warnKey = next ? input.getAttribute('data-zibll-warn-on') : input.getAttribute('data-zibll-warn-off');

				if (!warnKey) {
					previous = next;
					return;
				}

				var message = text(warnKey, '');
				input.checked = previous;

				if (!message) {
					apply(next);
					return;
				}

				admin.ui.confirm(message, {
					title: text('confirmTitle', ''),
					confirmText: text('confirm', ''),
					cancelText: text('cancel', ''),
					danger: true
				}).then(function (ok) {
					if (ok) {
						apply(next);
					}
				});
			});
		});

		/* -------------------------------------------------------------- level rule */
		var ruleToggle = root.querySelector('input[name="sufm_space_settings[level_rule][enabled]"]');
		if (ruleToggle) {
			ruleToggle.addEventListener('change', function () {
				all('[data-zibll-mb="level"]', root).forEach(function (input) {
					input.disabled = ruleToggle.checked;
				});
				updateTotals();
			});
		}

		/* ------------------------------------------------------------------- icon */
		var plans = root.querySelector('[data-zibll-plans]');
		if (plans) {
			var iconInput = plans.querySelector('[data-zibll-icon-input]');
			var iconPreview = plans.querySelector('[data-zibll-icon-preview]');
			var iconClear = plans.querySelector('[data-zibll-icon-clear]');
			var iconPick = plans.querySelector('[data-zibll-icon-pick]');

			function repaintIcon() {
				if (!iconInput || !iconPreview) {
					return;
				}
				var value = iconInput.value.trim();
				iconPreview.src = value || iconPreview.getAttribute('data-default') || '';
				if (iconClear) {
					iconClear.hidden = !value;
				}
			}

			if (iconInput) {
				iconInput.addEventListener('input', repaintIcon);
				iconInput.addEventListener('change', repaintIcon);
			}
			if (iconClear) {
				iconClear.addEventListener('click', function () {
					if (iconInput) {
						iconInput.value = '';
						iconInput.dispatchEvent(new window.Event('change', { bubbles: true }));
					}
					repaintIcon();
				});
			}
			if (iconPick) {
				iconPick.addEventListener('click', function () {
					if (!window.wp || typeof window.wp.media !== 'function') {
						admin.ui.toast(text('mediaUnavailable', text('grantActionFailed', '')), 'bad');
						return;
					}
					var frame = window.wp.media({
						title: text('chooseIcon', ''),
						library: { type: 'image' },
						button: { text: text('chooseIcon', '') },
						multiple: false
					});
					frame.on('select', function () {
						var attachment = frame.state().get('selection').first().toJSON();
						if (attachment && attachment.url && iconInput) {
							iconInput.value = attachment.url;
							iconInput.dispatchEvent(new window.Event('change', { bubbles: true }));
							repaintIcon();
						}
					});
					frame.open();
				});
			}
		}

		/* ------------------------------------------------------------------ plans */
		if (plans) {
			var plansForm = closest(plans, 'form') || root;
			var planError = plans.querySelector('[data-zibll-plan-error]');

			function nextPlanId(kind) {
				var tbody = plans.querySelector(kind === 'cash' ? '[data-zibll-cash-rows]' : '[data-zibll-points-rows]');
				var prefix = kind === 'cash' ? 'A' : 'P';
				var max = 0;
				all('[data-zibll-plan-id]', tbody || plans).forEach(function (input) {
					var match = /^([AP])(\d+)$/i.exec(input.value.trim());
					if (match && match[1].toUpperCase() === prefix) {
						max = Math.max(max, parseInt(match[2], 10));
					}
				});
				return prefix + (max + 1);
			}

			function addPlan(kind) {
				var template = plans.querySelector('[data-zibll-plan-template="' + kind + '"]');
				var tbody = plans.querySelector(kind === 'cash' ? '[data-zibll-cash-rows]' : '[data-zibll-points-rows]');
				if (!template || !template.content || !tbody) {
					return;
				}
				var row = template.content.cloneNode(true).querySelector('tr');
				if (!row) {
					return;
				}
				var key = 'new' + Date.now() + Math.floor(Math.random() * 1000);
				all('[name]', row).forEach(function (field) {
					var name = field.getAttribute('name');
					if (name && name.indexOf('__KEY__') !== -1) {
						field.setAttribute('name', name.replace(/__KEY__/g, key));
					}
				});
				var idInput = row.querySelector('[data-zibll-plan-id]');
				if (idInput) {
					idInput.value = nextPlanId(kind);
				}
				tbody.appendChild(row);
				updateTotals();
				var label = row.querySelector('input[name$="[label]"]');
				if (label) {
					label.focus();
				}
			}

			all('[data-zibll-plan-add]', plans).forEach(function (button) {
				button.addEventListener('click', function () {
					addPlan(button.getAttribute('data-zibll-plan-add'));
				});
			});

			plansForm.addEventListener('change', function (event) {
				var checkbox = event.target;
				if (!checkbox || !checkbox.matches || !checkbox.matches('[data-zibll-plan-remove]')) {
					return;
				}
				var row = closest(checkbox, '[data-zibll-plan-row]');
				if (!row) {
					return;
				}
				var hidden = row.querySelector('[data-zibll-plan-remove-value]');
				var isNew = row.getAttribute('data-zibll-plan-new') === '1';

				if (isNew && checkbox.checked) {
					row.remove();
					updateTotals();
					return;
				}
				if (hidden) {
					hidden.value = checkbox.checked ? '1' : '0';
				}
				row.classList.toggle('is-removed', checkbox.checked);
			});

			plansForm.addEventListener('submit', function () {
				if (planError) {
					planError.textContent = '';
				}
			});
		}

		/* ----------------------------------------------------------------- grants */
		var grantsBox = root.querySelector('[data-zibll-grants]');
		var grantsRefresh = root.querySelector('[data-zibll-grants-refresh]');
		var grantsLoading = false;

		function emptyState(container, title, message) {
			container.innerHTML = '';
			var box = make('div', 'empty-state');
			box.appendChild(make('p', 'empty-state__title', title));
			if (message) {
				box.appendChild(make('p', 'empty-state__message', message));
			}
			container.appendChild(box);
		}

		function renderGrants(items) {
			if (!grantsBox) {
				return;
			}
			var state = grantsBox.querySelector('[data-zibll-grants-state]');
			var wrap = grantsBox.querySelector('[data-zibll-grants-wrap]');
			var tbody = grantsBox.querySelector('[data-zibll-grants-rows]');

			if (!items.length) {
				if (state) {
					state.hidden = false;
					emptyState(state, text('grantsEmpty', ''), '');
				}
				if (wrap) {
					wrap.hidden = true;
				}
				return;
			}

			if (state) {
				state.hidden = true;
			}
			if (wrap) {
				wrap.hidden = false;
			}
			if (!tbody) {
				return;
			}
			tbody.innerHTML = '';

			items.forEach(function (item) {
				var tr = document.createElement('tr');

				var time = document.createElement('div');
				time.textContent = item.created_at || '';
				var user = make('div', 'zibll-grant-user', '#' + (item.owner_id || ''));
				var first = document.createElement('div');
				first.appendChild(time);
				first.appendChild(user);
				tr.appendChild(cell(text('grantTimeUser', ''), first));

				var planName = make('div', 'zibll-grant-package', item.label || '');
				var size = make('div', 'zibll-grant-size', item.bytes_human || '');
				var second = document.createElement('div');
				second.appendChild(planName);
				second.appendChild(size);
				tr.appendChild(cell(text('grantPackageSize', ''), second));

				var expiry = item.permanent ? text('permanent', '') : (item.expires_at || '');
				tr.appendChild(cell(text('grantExpiry', ''), expiry));

				var tone = item.state === 'active' ? 'ok' : (item.state === 'pending' ? 'warn' : 'info');
				var badge = make('span', 'badge badge--' + tone, item.state_label || item.state || '');
				tr.appendChild(cell(text('grantState', ''), badge));

				var actions = make('div', 'button-row');
				if (item.state === 'active') {
					var extend = make('button', 'btn btn--ghost btn--small', text('grantExtend', ''));
					extend.type = 'button';
					extend.setAttribute('data-zibll-grant-extend', String(item.id));
					var revoke = make('button', 'btn btn--danger btn--small', text('grantRevoke', ''));
					revoke.type = 'button';
					revoke.setAttribute('data-zibll-grant-revoke', String(item.id));
					actions.appendChild(extend);
					actions.appendChild(revoke);
				}
				tr.appendChild(cell(text('grantActions', ''), actions));
				tbody.appendChild(tr);
			});
		}

		function loadGrants() {
			if (!grantsBox || grantsLoading) {
				return;
			}
			var state = grantsBox.querySelector('[data-zibll-grants-state]');
			var wrap = grantsBox.querySelector('[data-zibll-grants-wrap]');
			if (state) {
				state.hidden = false;
				emptyState(state, text('grantsLoading', ''));
			}
			if (wrap) {
				wrap.hidden = true;
			}
			grantsLoading = true;
			if (grantsRefresh) {
				admin.ui.busy(grantsRefresh, true);
			}

			admin.api.get(restUrl + 'admin/space/grants', {}, {
				headers: { 'X-WP-Nonce': nonce }
			}).then(function (payload) {
				var items = payload && Array.isArray(payload.items) ? payload.items : [];
				renderGrants(items);
			}).catch(function (error) {
				if (state) {
					state.hidden = false;
					emptyState(state, text('grantsError', ''), apiError(error, text('grantsError', '')));
				}
				admin.ui.toast(apiError(error, text('grantsError', '')), 'bad');
			}).finally(function () {
				grantsLoading = false;
				if (grantsRefresh) {
					admin.ui.busy(grantsRefresh, false);
				}
			});
		}

		if (grantsRefresh) {
			grantsRefresh.addEventListener('click', function (event) {
				event.preventDefault();
				loadGrants();
			});
		}

		function extendGrant(id, button) {
			ask({
				title: text('grantExtendTitle', ''),
				message: text('grantExtendPrompt', ''),
				type: 'number',
				min: 1,
				max: 3650,
				value: '30',
				confirmText: text('grantExtend', ''),
				cancelText: text('cancel', '')
			}).then(function (value) {
				if (null === value) {
					return;
				}
				var days = parseInt(value, 10);
				if (!isFinite(days) || days < 1 || days > 3650) {
					admin.ui.toast(text('invalidDays', ''), 'bad');
					return;
				}
				admin.ui.busy(button, true);
				admin.api.post(restUrl + 'admin/space/grants/' + encodeURIComponent(id) + '/extend', { days: days }, {
					headers: { 'X-WP-Nonce': nonce }
				}).then(function () {
					admin.ui.toast(text('grantExtendDone', ''), 'ok');
					loadGrants();
				}).catch(function (error) {
					admin.ui.toast(apiError(error, text('grantActionFailed', '')), 'bad');
				}).finally(function () {
					admin.ui.busy(button, false);
				});
			});
		}

		function revokeGrant(id, button) {
			ask({
				title: text('grantRevokeTitle', ''),
				message: text('grantRevokePrompt', ''),
				type: 'text',
				confirmText: text('grantRevoke', ''),
				cancelText: text('cancel', ''),
				danger: true
			}).then(function (value) {
				if (null === value) {
					return;
				}
				if (value !== text('revokeWord', '撤销')) {
					admin.ui.toast(text('invalidWord', ''), 'bad');
					return;
				}
				admin.ui.busy(button, true);
				admin.api.post(restUrl + 'admin/space/grants/' + encodeURIComponent(id) + '/revoke', { note: text('revokeWord', '撤销') }, {
					headers: { 'X-WP-Nonce': nonce }
				}).then(function () {
					admin.ui.toast(text('grantRevokeDone', ''), 'ok');
					loadGrants();
				}).catch(function (error) {
					admin.ui.toast(apiError(error, text('grantActionFailed', '')), 'bad');
				}).finally(function () {
					admin.ui.busy(button, false);
				});
			});
		}

		if (grantsBox) {
			var grantsRows = grantsBox.querySelector('[data-zibll-grants-rows]');
			if (grantsRows) {
				grantsRows.addEventListener('click', function (event) {
					var extend = closest(event.target, '[data-zibll-grant-extend]');
					var revoke = closest(event.target, '[data-zibll-grant-revoke]');
					if (extend) {
						extendGrant(extend.getAttribute('data-zibll-grant-extend'), extend);
					} else if (revoke) {
						revokeGrant(revoke.getAttribute('data-zibll-grant-revoke'), revoke);
					}
				});
			}
			loadGrants();
		}

		/* -------------------------------------------------------------- reconcile */
		var reconcileCheck = root.querySelector('[data-zibll-reconcile-check]');
		var reconcileApply = root.querySelector('[data-zibll-reconcile-apply]');
		var reconcileSummary = root.querySelector('[data-zibll-reconcile-summary]');
		var reconcileList = root.querySelector('[data-zibll-reconcile-list]');
		var lastMissing = 0;
		var reconcileBusy = false;

		function renderReconcileItems(items) {
			if (!reconcileList) {
				return;
			}
			reconcileList.innerHTML = '';
			if (!items || !items.length) {
				reconcileList.appendChild(make('p', 'zibll-muted', text('reconcileNoItems', '')));
				return;
			}
			var list = make('ul', 'list zibll-reconcile-items');
			items.forEach(function (item) {
				var li = document.createElement('li');
				var title = make('strong', '', text('orderNum', '') + ' ' + (item.order_num || ''));
				li.appendChild(title);
				li.appendChild(make('span', '', text('user', '') + ' #' + (item.user_id || '')));
				li.appendChild(make('span', '', text('price', '') + ' ' + (item.price || '')));
				li.appendChild(make('span', '', text('payTime', '') + ' ' + (item.pay_time || '')));
				list.appendChild(li);
			});
			reconcileList.appendChild(list);
		}

		function reconcileRequest(dryRun) {
			if (reconcileBusy) {
				return;
			}
			reconcileBusy = true;
			if (reconcileSummary) {
				reconcileSummary.textContent = text(dryRun ? 'reconcileChecking' : 'reconcileHealing', '');
			}
			admin.ui.busy(reconcileCheck, true);
			admin.ui.busy(reconcileApply, true);

			admin.api.post(restUrl + 'admin/space/reconcile', { dry_run: dryRun ? 1 : 0 }, {
				headers: { 'X-WP-Nonce': nonce }
			}).then(function (payload) {
				if (!payload || false === payload.ready) {
					var message = (payload && payload.error) ? String(payload.error) : text('reconcileFailed', '');
					if (reconcileSummary) {
						reconcileSummary.textContent = message;
					}
					admin.ui.toast(message, 'bad');
					return;
				}

				var missing = parseInt(payload.missing, 10) || 0;
				var healed = parseInt(payload.healed, 10) || 0;

				if (dryRun) {
					lastMissing = missing;
					if (reconcileSummary) {
						reconcileSummary.textContent = missing ? format(text('reconcileMissing', ''), missing) : text('reconcileClean', '');
					}
					if (reconcileApply) {
						reconcileApply.disabled = !missing;
					}
					renderReconcileItems(payload.items || []);
				} else {
					lastMissing = 0;
					if (reconcileSummary) {
						reconcileSummary.textContent = format(text('reconcileHealed', ''), healed);
					}
					renderReconcileItems([]);
					if (grantsBox) {
						loadGrants();
					}
				}
			}).catch(function (error) {
				var message = apiError(error, text('reconcileFailed', ''));
				if (reconcileSummary) {
					reconcileSummary.textContent = message;
				}
				admin.ui.toast(message, 'bad');
			}).finally(function () {
				reconcileBusy = false;
				admin.ui.busy(reconcileCheck, false);
				admin.ui.busy(reconcileApply, false);
				if (reconcileApply && lastMissing <= 0) {
					reconcileApply.disabled = true;
				}
			});
		}

		if (reconcileCheck) {
			reconcileCheck.addEventListener('click', function () {
				reconcileRequest(true);
			});
		}

		if (reconcileApply) {
			reconcileApply.addEventListener('click', function () {
				if (!lastMissing) {
					return;
				}
				admin.ui.confirm(format(text('reconcileConfirm', ''), lastMissing), {
					title: text('confirmTitle', ''),
					confirmText: text('confirm', ''),
					cancelText: text('cancel', ''),
					danger: true
				}).then(function (ok) {
					if (ok) {
						reconcileRequest(false);
					}
				});
			});
		}

		/* Keep the active file tab visible inside the horizontally scrolling strip. */
		all('.sakura-tabs', root).forEach(function (tabs) {
			var activeTab = tabs.querySelector('[aria-current="page"], .is-active');
			if (!activeTab) {
				return;
			}
			var box = tabs.getBoundingClientRect();
			var tab = activeTab.getBoundingClientRect();
			if (tab.left < box.left || tab.right > box.right) {
				tabs.scrollLeft += (tab.left - box.left) - (box.width - tab.width) / 2;
			}
		});
	});
})(window, document);

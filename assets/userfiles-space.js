/**
 * Sakura Tools - front end space expansion panel (P1).
 *
 * Native JS, IIFE, no jQuery.
 *
 * The panel can arrive two ways: server rendered with the page, or injected as
 * HTML by the theme's AJAX tab loader. innerHTML injected <script> tags do NOT
 * execute, therefore:
 *   - the payload is read lazily from window.sufmSpacePanel OR from the inline
 *     <script type="application/json" data-sufm-space-json> tag;
 *   - every interaction is bound through document level delegation.
 */
(function () {
	'use strict';

	var cached = null;

	// Set once the purchase succeeded: closing the dialog then reloads the page.
	var state = {
		purchased: false,
		lastFocus: null
	};

	// QR polling session. Reset on every open / close.
	var poll = {
		timer: null,
		orderNum: '',
		attempts: 0,
		limit: 100,
		failures: 0
	};

	function readJsonTag() {
		var node = document.querySelector('script[data-sufm-space-json]');

		if (!node) {
			return null;
		}

		try {
			return JSON.parse(node.textContent || '');
		} catch (error) {
			return null;
		}
	}

	function payload() {
		if (cached) {
			return cached;
		}

		cached = window.sufmSpacePanel || readJsonTag() || {};

		return cached;
	}

	function cfg() {
		return payload().config || {};
	}

	function strings() {
		return payload().strings || {};
	}

	function text(key, fallback) {
		var table = strings();

		return table[key] || fallback || '';
	}

	function dialog() {
		return document.getElementById('sufm-space-dialog');
	}

	function node(selector) {
		return document.querySelector(selector);
	}

	function checkedPlan() {
		return node('input[name="sufm-space-plan"]:checked');
	}

	function checkedMethod() {
		return node('input[name="sufm-space-method"]:checked');
	}

	function checkedPointsPlan() {
		return node('input[name="sufm-space-points-plan"]:checked');
	}

	function methodLabel(input) {
		if (!input) {
			return '';
		}

		var label = input.closest('label');

		return label ? label.textContent.trim() : input.value;
	}

	function describe() {
		var plan = checkedPlan();
		var method = checkedMethod();

		// Points channel keeps its own packages: the summary follows them.
		if (pointsMode()) {
			var picked = checkedPointsPlan();

			if (!picked) {
				return '';
			}

			var ppDays = parseInt(picked.getAttribute('data-days') || '0', 10) || 0;

			return [
				(picked.getAttribute('data-gb') || '') + ' GB',
				(picked.getAttribute('data-points') || '') + ' ' + text('pointsUnit', '积分'),
				ppDays > 0 ? (String(ppDays) + ' ' + text('daysUnit', '天')) : text('permanent', '永久')
			].filter(function (part) {
				return part !== '';
			}).join(' \u00b7 ');
		}

		if (!plan) {
			return '';
		}

		return [
			plan.getAttribute('data-bytes-human') || '',
			plan.getAttribute('data-price-human') || '',
			plan.getAttribute('data-days') === '0' ? text('permanent', '永久') : '',
			methodLabel(method)
		].filter(function (part) {
			return part !== '';
		}).join(' · ');
	}

	function clearMessages() {
		var error = node('[data-sufm-space-error]');
		var result = node('[data-sufm-space-result]');

		if (error) {
			error.textContent = '';
		}

		if (result) {
			result.textContent = '';
		}
	}

	function showError(message) {
		var target = node('[data-sufm-space-error]');

		if (target) {
			target.textContent = message;
		}
	}

	function showResult(message) {
		var target = node('[data-sufm-space-result]');

		if (target) {
			target.textContent = message;
		}
	}

	function openDialog() {
		var summary = node('[data-sufm-space-summary]');
		var box = dialog();

		// Fresh session: no pending reload, no stale QR, usable confirm button.
		state.purchased = false;
		stopPolling();
		hideQr();
		clearMessages();
		resetConfirm();
		syncPointsMode();

		if (summary) {
			summary.textContent = describe();
		}

		if (!box) {
			return;
		}

		ensureBindings(box);
		box.classList.remove('is-closing');

		// Remember the trigger so focus can return to it after closing.
		state.lastFocus = document.activeElement;

		if (typeof box.showModal === 'function') {
			box.showModal();
		} else {
			box.setAttribute('open', 'open');
		}

		// Move focus into the dialog: the first usable control wins when a
		// channel is missing (points-only sites have no cash plan cards).
		var first = node('input[name="sufm-space-plan"]');

		if (!first) {
			first = node('input[name="sufm-space-points-plan"]');
		}

		if (!first) {
			first = node('input[name="sufm-space-method"]');
		}

		if (!first) {
			first = node('[data-sufm-space-redeem-gb]');
		}

		if (first && typeof first.focus === 'function') {
			first.focus();
		}
	}

	function closeDialog() {
		var box = dialog();

		if (!box || box.classList.contains('is-closing')) {
			return;
		}

		if (!box.hasAttribute('open') && box.open !== true) {
			return;
		}

		stopPolling();

		box.classList.add('is-closing');

		var finished = false;

		var finish = function () {
			if (finished) {
				return;
			}

			finished = true;
			box.removeEventListener('animationend', onAnimationEnd);
			box.classList.remove('is-closing');

			if (typeof box.close === 'function') {
				box.close();
			} else {
				box.removeAttribute('open');
			}

			// Return focus to the toolbar button (or whatever opened the dialog).
			if (state.lastFocus && typeof state.lastFocus.focus === 'function') {
				state.lastFocus.focus();
				state.lastFocus = null;
			}
		};

		var onAnimationEnd = function (event) {
			if (event && event.target !== box) {
				return;
			}

			finish();
		};

		box.addEventListener('animationend', onAnimationEnd);

		// Fallback timer: animationend does not fire with reduced motion, in a
		// background tab, or when the browser skips the ::backdrop animation.
		window.setTimeout(finish, 160);
	}

	function setBusy(busy) {
		var button = document.getElementById('sufm-space-confirm');

		if (!button) {
			return;
		}

		button.disabled = busy;
		button.textContent = busy ? text('busy', '处理中…') : text('confirm', '确认支付');
	}

	function resetConfirm() {
		var button = document.getElementById('sufm-space-confirm');

		if (!button) {
			return;
		}

		button.disabled = false;
		button.textContent = text('confirm', '确认支付');
		button.removeAttribute('data-sufm-space-done');
	}

	function setDone() {
		var button = document.getElementById('sufm-space-confirm');

		if (!button) {
			return;
		}

		// The dialog already finished its work: 完成 is an exit button, not a
		// submit button, so it must stay clickable (click closes and reloads).
		button.disabled = false;
		button.textContent = text('done', '完成');
		button.setAttribute('data-sufm-space-done', '1');
	}

	function qrBox() {
		return node('[data-sufm-space-qr]');
	}

	/**
	 * Switches the dialog to the scanning state.
	 *
	 * @param {Object} body Order response from POST /space/orders.
	 * @return {void}
	 */
	function showQr(body) {
		var box = qrBox();
		var image = node('[data-sufm-space-qr-img]');
		var link = node('[data-sufm-space-qr-open]');
		var button = document.getElementById('sufm-space-confirm');

		if (image && body.qr_image) {
			image.src = body.qr_image;
		}

		if (link) {
			var code = String(body.qr_code || '');

			if (code.indexOf('http') === 0) {
				link.href = code;
				link.removeAttribute('hidden');
			} else {
				link.setAttribute('hidden', 'hidden');
			}
		}

		if (box) {
			box.removeAttribute('hidden');
		}

		// The button stays disabled while the QR is on screen: no double order.
		if (button) {
			button.disabled = true;
			button.textContent = text('waiting', '等待支付…');
		}

		showResult(text('scanHint', '订单已创建，请在 5 分钟内完成扫码支付。'));
	}

	function hideQr() {
		var box = qrBox();
		var image = node('[data-sufm-space-qr-img]');
		var link = node('[data-sufm-space-qr-open]');

		if (box) {
			box.setAttribute('hidden', 'hidden');
		}

		if (image) {
			image.removeAttribute('src');
		}

		if (link) {
			link.setAttribute('hidden', 'hidden');
			link.removeAttribute('href');
		}
	}

	function stopPolling() {
		if (poll.timer) {
			window.clearInterval(poll.timer);
		}

		poll.timer = null;
		poll.orderNum = '';
		poll.attempts = 0;
		poll.failures = 0;
	}

	/**
	 * Starts polling the order status every 3 seconds.
	 *
	 * @param {Object} body Order response carrying order_num / expires_in.
	 * @return {void}
	 */
	function startPolling(body) {
		stopPolling();

		poll.orderNum = String(body.order_num || '');

		var seconds = parseInt(String(body.expires_in || 300), 10);

		if (!isFinite(seconds) || seconds <= 0) {
			seconds = 300;
		}

		poll.limit = Math.max(1, Math.floor(seconds / 3));

		if (!poll.orderNum || !cfg().restUrl) {
			return;
		}

		poll.timer = window.setInterval(pollTick, 3000);
	}

	function pollTick() {
		var config = cfg();

		poll.attempts++;

		if (poll.attempts > poll.limit) {
			stopPolling();
			hideQr();
			resetConfirm();
			showError(text('qrExpired', '二维码已过期，请重新发起。'));
			return;
		}

		window.fetch(config.restUrl + 'space/orders/' + encodeURIComponent(poll.orderNum) + '/status', {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce || '' }
		}).then(function (response) {
			return response.json().then(function (body) {
				if (!response.ok) {
					throw new Error(body && body.message ? body.message : '');
				}

				return body;
			});
		}).then(function (body) {
			poll.failures = 0;

			if (body && body.paid) {
				stopPolling();
				hideQr();
				state.purchased = true;
				showResult(text('success', '已到账：%s').replace('%s', body.bytes_human || ''));
				setDone();
				return;
			}

			if (body && (body.state === 'closed' || body.state === 'refunded')) {
				stopPolling();
				hideQr();
				resetConfirm();
				showError(text('orderClosed', '订单已关闭，请重新发起。'));
			}
		}).catch(function () {
			// Transient failures are tolerated: give up only after 3 in a row.
			poll.failures++;

			if (poll.failures >= 3) {
				stopPolling();
				hideQr();
				resetConfirm();
				showError(text('network', '网络异常，请稍后重试。'));
			}
		});
	}

	/**
	 * Dialog level bindings, attached once per element.
	 *
	 * ESC is intercepted on purpose: the native cancel closes the top-layer
	 * element instantly, which would skip both the closing animation and the
	 * post-purchase reload.
	 *
	 * @param {HTMLDialogElement} box Dialog element.
	 * @return {void}
	 */
	function ensureBindings(box) {
		if (!box || box.getAttribute('data-sufm-space-bound') === '1') {
			return;
		}

		box.setAttribute('data-sufm-space-bound', '1');

		box.addEventListener('close', function () {
			// Quota bar and the theme rendered balance are server side: only a
			// reload shows the new numbers, because the balance markup lives
			// outside everything this script can patch.
			if (state.purchased) {
				window.location.reload();
			}
		});

		box.addEventListener('cancel', function (event) {
			event.preventDefault();
			closeDialog();
		});
	}

	function parseJson(response) {
		return response.json().then(function (body) {
			if (!response.ok) {
				throw new Error(body && body.message ? body.message : text('network', '网络异常，请稍后重试。'));
			}

			return body;
		});
	}

	function confirmPurchase() {
		var config = cfg();
		var plan = checkedPlan();
		var method = checkedMethod();

		clearMessages();

		// The points channel has its own submit path (redeemGo); never create a
		// cash order from it, even if the button is somehow visible.
		if (pointsMode()) {
			return;
		}

		if (!plan) {
			showError(text('needPlan', '请先选择套餐。'));
			return;
		}

		if (!config.restUrl) {
			showError(text('network', '网络异常，请稍后重试。'));
			return;
		}

		setBusy(true);

		window.fetch(config.restUrl + 'space/orders', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || ''
			},
			body: JSON.stringify({
				plan_id: plan.value,
				method: method ? method.value : ''
			})
		}).then(parseJson).then(function (body) {
			setBusy(false);

			if (body && body.payok) {
				state.purchased = true;
				showResult(text('success', '已到账：%s').replace('%s', body.bytes_human || ''));
				setDone();
				return;
			}

			// Alipay: the theme returns a base64 QR image plus the qr.alipay.com
			// payload. Show the QR and poll for the async notify.
			if (body && body.qr_image) {
				showQr(body);
				startPolling(body);
				return;
			}

			showResult(text('pending', '订单已创建，但尚未完成支付，请稍后到「授权流水」核对。'));
		}).catch(function (error) {
			setBusy(false);
			showError(error && error.message ? error.message : text('network', '网络异常，请稍后重试。'));
		});
	}

	function handleClick(event) {
		var target = event.target;

		if (!target || !target.closest) {
			return;
		}

		// Toolbar entry (rendered by the file-space toolbar, outside the dialog).
		if (target.closest('#sufm-space-open')) {
			event.preventDefault();
			clearMessages();
			openDialog();
			return;
		}

		if (target.closest('#sufm-space-confirm')) {
			event.preventDefault();

			// After a successful purchase the same button turns into 完成:
			// close the dialog (its close handler reloads the panel).
			if (state.purchased) {
				closeDialog();
				return;
			}

			confirmPurchase();
			return;
		}

		if (target.closest('[data-sufm-space-close]')) {
			event.preventDefault();
			closeDialog();
			return;
		}

		var box = dialog();

		if (box && target === box) {
			closeDialog();
		}
	}

	function redeemBox() {
		return node('[data-sufm-space-redeem]');
	}

	/**
	 * Selected plan as GB + days: the points panel always follows the plan card.
	 *
	 * @return {Object} { gb: number, days: number }
	 */
	/**
	 * Selected points package. The server owns the price, the card only shows it.
	 *
	 * @return {Object} { id, gb, cost, days }
	 */
	function redeemPlan() {
		var picked = checkedPointsPlan();
		var out = { id: '', gb: 0, cost: 0, days: 0 };

		if (!picked) {
			return out;
		}

		out.id = picked.value;
		out.gb = Math.round((parseFloat(picked.getAttribute('data-gb') || '0') || 0) * 100) / 100;
		out.cost = parseInt(picked.getAttribute('data-points') || '0', 10) || 0;
		out.days = parseInt(picked.getAttribute('data-days') || '0', 10) || 0;

		return out;
	}

	function redeemGb() {
		var plan = redeemPlan();
		var input = node('[data-sufm-space-redeem-gb]');

		if (plan.gb > 0) {
			if (input) {
				input.value = String(plan.gb);
			}

			return plan.gb;
		}

		var fallback = input ? parseFloat(String(input.value || '')) : 0;

		return isFinite(fallback) && fallback > 0 ? Math.round(fallback * 100) / 100 : 0;
	}

	/**
	 * Is the points channel the selected payment method?
	 *
	 * @return {boolean} True when points is selected.
	 */
	function pointsMode() {
		var method = checkedMethod();

		return !!method && method.value === 'points';
	}

	/**
	 * Shows / hides the points panel and swaps the footer CTA.
	 *
	 * @return {void}
	 */
	function syncPointsMode() {
		var box = redeemBox();
		var confirm = document.getElementById('sufm-space-confirm');
		var go = node('[data-sufm-space-redeem-go]');

		if (!box || !go) {
			return;
		}

		var cash = node('[data-sufm-space-plans-cash]');
		var pointsPlans = node('[data-sufm-space-plans-points]');

		if (pointsMode()) {
			if (cash) {
				cash.setAttribute('hidden', 'hidden');
			}

			if (pointsPlans) {
				pointsPlans.removeAttribute('hidden');
			}

			box.removeAttribute('hidden');
			go.removeAttribute('hidden');

			if (confirm) {
				confirm.hidden = true;
			}

			redeemUpdate();
			return;
		}

		if (cash) {
			cash.removeAttribute('hidden');
		}

		if (pointsPlans) {
			pointsPlans.setAttribute('hidden', 'hidden');
		}

		box.setAttribute('hidden', 'hidden');
		go.setAttribute('hidden', 'hidden');

		if (confirm) {
			confirm.hidden = false;
		}

		updateSummary();
	}

	function redeemState() {
		var box = redeemBox();

		if (!box) {
			return null;
		}

		return {
			perGb: parseInt(box.getAttribute('data-per-gb') || '0', 10) || 0,
			balance: parseInt(box.getAttribute('data-balance') || '0', 10) || 0,
			maxGb: parseFloat(box.getAttribute('data-max-gb') || '0') || 0,
			capGb: parseFloat(box.getAttribute('data-cap-gb') || '0') || 0,
			usedGb: parseFloat(box.getAttribute('data-used-gb') || '0') || 0
		};
	}

	function redeemUpdate() {
		var info = redeemState();
		var button = node('[data-sufm-space-redeem-go]');
		var preview = node('[data-sufm-space-redeem-preview]');
		var gbView = node('[data-sufm-space-redeem-gb-view]');
		var costView = node('[data-sufm-space-redeem-cost-view]');
		var daysView = node('[data-sufm-space-redeem-days-view]');

		if (!info || !button) {
			return info;
		}

		var plan = redeemPlan();
		var gb = redeemGb();
		var cost = plan.cost;

		if (gbView) {
			gbView.textContent = gb > 0 ? (String(gb) + ' GB') : '—';
		}

		if (costView) {
			costView.textContent = gb > 0 ? String(cost) : '—';
		}

		if (daysView) {
			daysView.textContent = plan.days > 0
				? (String(plan.days) + ' ' + text('daysUnit', '天'))
				: text('permanent', '永久');
		}

		if (preview) {
			preview.textContent = text('pointsPreview', '余额 %s1 · 今日已兑 %s2/%s3 GB')
				.replace('%s1', String(info.balance))
				.replace('%s2', String(info.usedGb))
				.replace('%s3', String(info.capGb));
		}

		var short = info.balance < cost;
		var overCap = (info.usedGb + gb) > info.capGb;
		var outOfRange = gb < 0.5 || (info.maxGb > 0 && gb > info.maxGb);

		button.disabled = gb <= 0 || cost <= 0 || short || overCap || outOfRange;

		if (short) {
			button.textContent = text('pointsShort', '还差 %s 积分').replace('%s', String(cost - info.balance));
		} else if (overCap) {
			button.textContent = text('pointsCap', '今日额度不足');
		} else if (gb <= 0 || cost <= 0) {
			button.textContent = text('pointsPick', '请先选择积分套餐');
		} else {
			button.textContent = text('pointsRedeem', '用 %s1 积分兑换 %s2 GB')
				.replace('%s1', String(cost))
				.replace('%s2', String(gb));
		}

		updateSummary();

		return info;
	}

	/**
	 * Keeps the order summary line in sync with the active channel.
	 *
	 * @return {void}
	 */
	function updateSummary() {
		var summary = node('[data-sufm-space-summary]');

		if (summary) {
			summary.textContent = describe();
		}
	}

	// Chips removed: the amount now follows the selected plan card.

	function redeemGo(event) {
		var go = event.target.closest('[data-sufm-space-redeem-go]');

		if (!go) {
			return false;
		}

		event.preventDefault();

		var config = cfg();
		var info = redeemUpdate();
		var gb = redeemGb();

		if (!info || gb <= 0) {
			return true;
		}

		clearMessages();
		go.disabled = true;

		window.fetch(config.restUrl + 'space/redeem', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || ''
			},
			body: JSON.stringify({
				package_id: redeemPlan().id,
				request_id: (window.crypto && window.crypto.randomUUID)
					? window.crypto.randomUUID()
					: String(Date.now()) + String(Math.random()).slice(2, 8)
			})
		}).then(function (response) {
			return response.json().then(function (body) {
				if (!response.ok) {
					var error = new Error(body && body.message ? body.message : '');

					error.data = body && body.data ? body.data : {};

					throw error;
				}

				return body;
			});
		}).then(function (body) {
			var box = redeemBox();
			var go = node('[data-sufm-space-redeem-go]');
			var confirm = document.getElementById('sufm-space-confirm');

			if (box) {
				box.setAttribute('hidden', 'hidden');
			}

			if (go) {
				go.setAttribute('hidden', 'hidden');
			}

			// The points flow hides 确认支付; bring it back as the 完成 button.
			if (confirm) {
				confirm.hidden = false;
			}

			state.purchased = true;
			stateShow(body);
		}).catch(function (error) {
			go.disabled = false;
			redeemUpdate();

			var data = error && error.data ? error.data : {};

			if (typeof data.balance === 'number' || typeof data.cost === 'number') {
				var box = redeemBox();

				if (box) {
					if (typeof data.balance === 'number') {
						box.setAttribute('data-balance', String(data.balance));
					}

					redeemUpdate();
				}
			}

			showError(error && error.message ? error.message : text('network', '网络异常，请稍后重试。'));
		});

		return true;
	}

	function stateShow(body) {
		showResult(text('success', '已到账：%s').replace('%s', (body && body.bytes_human) || ''));
		setDone();
	}

	function handleChange(event) {
		var target = event.target;

		if (!target || !target.name) {
			return;
		}

		if (!target.hasAttribute('data-sufm-space-redeem-gb')
			&& target.name !== 'sufm-space-plan'
			&& target.name !== 'sufm-space-points-plan'
			&& target.name !== 'sufm-space-method') {
			return;
		}

		if (target.name === 'sufm-space-method') {
			syncPointsMode();
			return;
		}

		if (pointsMode()) {
			redeemUpdate();
			return;
		}

		updateSummary();
	}

	// Delegated: works for server rendered and AJAX injected panels alike.
	document.addEventListener('click', function (event) {
		if (redeemGo(event)) {
			return;
		}

		handleClick(event);
	});

	document.addEventListener('change', handleChange);
	document.addEventListener('input', function (event) {
		if (event.target && event.target.hasAttribute && event.target.hasAttribute('data-sufm-space-redeem-gb')) {
			redeemUpdate();
		}
	});
})();

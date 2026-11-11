(function () {
    'use strict';

    var root = document.querySelector('.sakura-sync-app');
    if (!root) {
        return;
    }

    var config = window.sakuraSyncCenter || {};
    var indexEnabled = root.getAttribute('data-index-enabled') !== '0';
    var toastTimer = 0;

    function toast(message, isError) {
        var node = document.getElementById('sakura-sync-toast');
        if (!node) {
            node = document.createElement('div');
            node.id = 'sakura-sync-toast';
            node.className = 'toast';
            node.setAttribute('role', 'status');
            document.body.appendChild(node);
        }
        node.textContent = message;
        node.classList.toggle('is-error', !!isError);
        node.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            node.classList.remove('is-visible');
        }, 3200);
    }

    function rememberToast(message, isError) {
        try {
            sessionStorage.setItem('sakuraSyncToast', JSON.stringify({ message: message, error: !!isError }));
        } catch (e) {}
    }

    function reloadWithToast(message, isError) {
        rememberToast(message, isError);
        window.location.reload();
    }

    function request(action, data, nonce) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', nonce);
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) { body.append(key + '[]', item); });
            } else {
                body.set(key, value);
            }
        });
        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () { throw new Error(config.unknownError); });
        }).then(function (payload) {
            if (!payload || !payload.success) {
                throw new Error(payload && payload.data && payload.data.message ? payload.data.message : config.unknownError);
            }
            return payload.data;
        });
    }

    function fastPage(data) {
        var token = config.searchToken || {};
        var params = new URLSearchParams();
        params.set('u', token.u);
        params.set('e', token.e);
        params.set('p', token.p);
        params.set('s', token.s);
        Object.keys(data || {}).forEach(function (key) {
            params.set(key, data[key] == null ? '' : data[key]);
        });
        return fetch(config.searchUrl + '?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            if (!response.ok) { throw new Error(config.unknownError); }
            return response.json().catch(function () { throw new Error(config.unknownError); });
        }).then(function (payload) {
            if (!payload || !payload.success) {
                throw new Error(payload && payload.data && payload.data.message ? payload.data.message : config.unknownError);
            }
            return payload.data;
        });
    }

    /**
     * The fast endpoint shares the sync tables with the AJAX endpoint but
     * builds its own SQL. An empty first page there means "no rows" or "the
     * fast query broke" — retry over admin-ajax before telling the user the
     * library is empty. Later pages may legitimately be empty (end of list),
     * and a cursor is present then, so they are not retried.
     */
    function needsAjaxFallback(payload, data) {
        if (!payload || !Array.isArray(payload.rows)) {
            return true;
        }
        return payload.rows.length === 0 && !data.cursor;
    }

    var pollToken = 0;

    function follow(scanId, onProgress) {
        var token = pollToken;
        return request('sakura_s3_sync_scan_step', { scan_id: scanId }, config.scanNonce)
            .then(function (data) {
                if (token !== pollToken) { return { state: 'detached' }; }
                if (typeof onProgress === 'function') { onProgress(data); }
                if (data.state === 'running' || data.state === 'queued') {
                    return new Promise(function (resolve) {
                        window.setTimeout(resolve, 400);
                    }).then(function () { return follow(scanId, onProgress); });
                }
                return data;
            });
    }

    function scan(button) {
        if (!button || button.disabled) { return; }
        button.disabled = true;
        pollToken++;
        var original = button.innerHTML;
        button.textContent = config.scanning || '...';
        var nonce = button.getAttribute('data-nonce') || config.scanNonce;

        function fail(error) {
            button.disabled = false;
            button.innerHTML = original;
            toast(error.message || config.unknownError, true);
        }

        request('sakura_s3_sync_scan', {}, nonce)
            .then(function (data) {
                if (data.state === 'done') {
                    reloadWithToast(data.message || config.done || '', false);
                    return null;
                }
                return follow(data.scan_id, function (step) {
                    button.textContent = (config.scanning || '...') + ' ' + (step.progress || 0) + '%';
                });
            })
            .then(function (final) {
                if (!final || final.state === 'detached') { return; }
                if (final.state === 'cancelled') {
                    reloadWithToast(config.cancelled || '', false);
                    return;
                }
                reloadWithToast(final.message || config.done || '', false);
            })
            .catch(fail);
    }

    function retry(button) {
        if (!button || button.disabled) { return; }
        button.disabled = true;
        var original = button.innerHTML;
        button.textContent = config.retrying || '...';
        request('sakura_s3_retry', { attachment_id: button.getAttribute('data-id') || '0' }, button.getAttribute('data-nonce') || config.retryNonce)
            .then(function (data) { reloadWithToast(data.message || config.retryDone || '', false); })
            .catch(function (error) {
                button.disabled = false;
                button.innerHTML = original;
                toast(error.message, true);
            });
    }

    function refresh(button) {
        if (!button || button.disabled) { return; }
        button.disabled = true;
        var original = button.innerHTML;
        button.textContent = config.refreshing || '...';
        request('sakura_s3_sync_refresh', {}, button.getAttribute('data-nonce') || config.refreshNonce)
            .then(function (data) { reloadWithToast(data.message || config.refreshed || '', false); })
            .catch(function (error) {
                button.disabled = false;
                button.innerHTML = original;
                toast(error.message, true);
            });
    }

    Array.prototype.forEach.call(document.querySelectorAll('#sakura-sync-scan, #sakura-sync-scan-3, #sakura-sync-scan-7, #sakura-sync-scan-empty'), function (button) {
        button.addEventListener('click', function () { scan(button); });
    });
    var refreshButton = document.getElementById('sakura-sync-scan-2');
    if (refreshButton) {
        refreshButton.addEventListener('click', function () { refresh(refreshButton); });
    }
    Array.prototype.forEach.call(document.querySelectorAll('[id^="sakura-sync-toggle-index"]'), function (button) {
        button.addEventListener('click', function () {
            if (button.disabled) { return; }
            button.disabled = true;
            request('sakura_s3_sync_toggle_index', {
                enabled: button.getAttribute('data-enabled') === '1' ? '1' : '0'
            }, button.getAttribute('data-nonce') || config.toggleNonce)
                .then(function (data) { reloadWithToast(data.message || config.refreshed || '', false); })
                .catch(function (error) {
                    button.disabled = false;
                    toast(error.message, true);
                });
        });
    });
    Array.prototype.forEach.call(document.querySelectorAll('#sakura-sync-retry-all, .sakura-sync-retry'), function (button) {
        button.addEventListener('click', function () { retry(button); });
    });

    var activeScanId = parseInt(root.getAttribute('data-scan-id'), 10) || 0;
    var cancelButton = document.getElementById('sakura-sync-cancel');
    var resumeButton = document.getElementById('sakura-sync-resume');

    function setScanButtons(state) {
        var running = state === 'running' || state === 'queued';
        if (cancelButton) { cancelButton.hidden = !running; }
        if (resumeButton) { resumeButton.hidden = running || state === 'done'; }
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', function () {
            if (cancelButton.disabled || !activeScanId) { return; }
            cancelButton.disabled = true;
            cancelButton.textContent = config.cancelling || '...';
            request('sakura_s3_sync_cancel', { scan_id: activeScanId }, cancelButton.getAttribute('data-nonce') || config.cancelNonce)
                .then(function () {
                    pollToken++;
                    reloadWithToast(config.cancelled || '', false);
                })
                .catch(function (error) {
                    cancelButton.disabled = false;
                    toast(error.message || config.cancelFailed || '', true);
                });
        });
    }

    if (resumeButton) {
        resumeButton.addEventListener('click', function () {
            if (resumeButton.disabled) { return; }
            resumeButton.disabled = true;
            pollToken++;
            resumeButton.textContent = config.resuming || '...';
            request('sakura_s3_sync_resume', {}, resumeButton.getAttribute('data-nonce') || config.resumeNonce)
                .then(function (data) {
                    activeScanId = data.scan_id || activeScanId;
                    setScanButtons(data.state || 'running');
                    return follow(activeScanId, function (step) {
                        resumeButton.textContent = (config.scanning || '...') + ' ' + (step.progress || 0) + '%';
                    });
                })
                .then(function (final) {
                    if (!final || final.state === 'detached') { return; }
                    if (final.state === 'cancelled') {
                        reloadWithToast(config.cancelled || '', false);
                        return;
                    }
                    reloadWithToast(final.message || config.done || '', false);
                })
                .catch(function (error) {
                    resumeButton.disabled = false;
                    resumeButton.textContent = config.resume || '...';
                    toast(error.message || config.unknownError, true);
                });
        });
    }

    // If the page opens while a scan is running, keep polling it so progress
    // and the final counts appear without clicking anything.
    if (activeScanId && ['running', 'queued'].indexOf(root.getAttribute('data-scan-state')) !== -1) {
        var meta = document.getElementById('sakura-sync-scan-meta');
        follow(activeScanId, function (step) {
            if (meta) {
                meta.textContent = (config.scanning || '...') + ' ' + (step.progress || 0) + '%';
            }
        }).then(function (final) {
            if (!final || final.state === 'detached') { return; }
            if (final.state === 'done') {
                reloadWithToast(final.message || config.done || '', false);
            } else if (final.state === 'cancelled') {
                reloadWithToast(config.cancelled || '', false);
            }
        }).catch(function () {});
    }

    var actionBusy = false;

    function act(button) {
        if (!button || actionBusy) { return; }
        var type = button.getAttribute('data-sync-action') || '';
        var key = button.getAttribute('data-key') || '';
        if (!type || !key) { return; }

        actionBusy = true;
        button.disabled = true;
        var original = button.innerHTML;
        button.textContent = type === 'upload' ? (config.uploading || '...') : (config.downloading || '...');

        request(
            'sakura_s3_sync_action',
            {
                sync_action: type,
                object_key: key,
                confirm: button.getAttribute('data-confirm') || ''
            },
            button.getAttribute('data-nonce') || config.actionNonce
        ).then(function (data) {
            if (data && data.queued) {
                toast(data.message || config.actionDone || '', false);
                window.setTimeout(function () { window.location.reload(); }, 1600);
                return;
            }
            reloadWithToast(data.message || config.actionDone || '', false);
        }).catch(function (error) {
            actionBusy = false;
            button.disabled = false;
            button.innerHTML = original;
            toast(error.message, true);
        });
    }

    var modal = document.getElementById('sakura-sync-delete-modal');
    var modalTitle = document.getElementById('sakura-sync-delete-title');
    var modalDesc = document.getElementById('sakura-sync-delete-desc');
    var confirmText = document.getElementById('sakura-sync-delete-confirm-text');
    var confirmButton = document.getElementById('sakura-sync-delete-confirm');
    var modalError = document.getElementById('sakura-sync-delete-error');
    var deleteTargets = [];
    var defaultTitle = modalTitle ? modalTitle.textContent : '';
    var defaultDesc = modalDesc ? modalDesc.textContent : '';
    var defaultConfirmLabel = confirmButton ? confirmButton.textContent : '';

    function closeModal() {
        if (!modal) { return; }
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('sakura-modal-open');
        if (confirmButton) { confirmButton.disabled = true; }
        if (confirmText) { confirmText.value = ''; }
        if (modalError) { modalError.hidden = true; modalError.textContent = ''; }
        deleteTargets = [];
    }

    function syncConfirmState() {
        if (confirmButton) {
            confirmButton.disabled = !confirmText || 'DELETE' !== confirmText.value;
        }
    }

    function showModal() {
        if (!modal) { return; }
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('sakura-modal-open');
        if (confirmText) { confirmText.value = ''; }
        syncConfirmState();
        if (modalError) { modalError.hidden = true; modalError.textContent = ''; }
        window.setTimeout(function () { if (confirmText) { confirmText.focus(); } }, 30);
    }

    function openModal(button) {
        if (!modal || !button) { return; }
        deleteTargets = [{
            key: button.getAttribute('data-key') || '',
            name: button.getAttribute('data-name') || '',
            size: button.getAttribute('data-size') || ''
        }];
        if (modalTitle) { modalTitle.textContent = defaultTitle; }
        if (modalDesc) { modalDesc.textContent = defaultDesc; }
        if (confirmButton) { confirmButton.textContent = defaultConfirmLabel; }
        document.getElementById('sakura-sync-delete-key').textContent = deleteTargets[0].key;
        document.getElementById('sakura-sync-delete-name').textContent = deleteTargets[0].name;
        document.getElementById('sakura-sync-delete-size').textContent = deleteTargets[0].size;
        showModal();
    }

    function openBatchModal(keys) {
        if (!modal || !keys.length) { return; }
        deleteTargets = keys.map(function (key) { return { key: key, name: '', size: '' }; });
        if (modalTitle) {
            modalTitle.textContent = (config.batchDeleteTitle || '从云端永久删除这 %d 个对象？').replace('%d', String(keys.length));
        }
        if (modalDesc) {
            modalDesc.textContent = config.batchDeleteDesc || '';
        }
        if (confirmButton) {
            confirmButton.textContent = (config.batchDeleteConfirm || '永久删除 %d 个对象').replace('%d', String(keys.length));
        }
        document.getElementById('sakura-sync-delete-key').textContent = String(keys.length) + ' 个对象';
        document.getElementById('sakura-sync-delete-name').textContent = config.batchDelete || '';
        document.getElementById('sakura-sync-delete-size').textContent = '';
        showModal();
    }

    if (confirmText) {
        confirmText.addEventListener('input', syncConfirmState);
    }

    document.addEventListener('click', function (event) {
        var actionButton = event.target.closest('.sakura-sync-act');
        if (actionButton) {
            act(actionButton);
            return;
        }
        var removeButton = event.target.closest('.sakura-sync-delete');
        if (removeButton) {
            openModal(removeButton);
        }
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-modal-close]'), function (button) {
        button.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (event) {
        if ('Escape' === event.key && modal && !modal.hidden) { closeModal(); }
    });
    if (confirmButton) {
        confirmButton.addEventListener('click', function () {
            if (!deleteTargets.length || actionBusy || !confirmText || 'DELETE' !== confirmText.value) { return; }
            actionBusy = true;
            confirmButton.disabled = true;
            var original = confirmButton.textContent;
            confirmButton.textContent = config.deleting || '...';
            var payload = { sync_action: 'delete', confirm: 'DELETE' };
            if (deleteTargets.length === 1) {
                payload.object_key = deleteTargets[0].key;
            } else {
                payload.keys = deleteTargets.map(function (target) { return target.key; });
            }
            request('sakura_s3_sync_action', payload, config.actionNonce)
                .then(function (data) {
                    reloadWithToast(data.message || config.actionDone || '', false);
                }).catch(function (error) {
                    actionBusy = false;
                    confirmButton.disabled = false;
                    confirmButton.textContent = original;
                    if (modalError) {
                        modalError.textContent = error.message;
                        modalError.hidden = false;
                    }
                });
        });
    }

    // ---- Batch selection -------------------------------------------------
    var batchBar = document.getElementById('recon-batch');
    var batchCount = document.getElementById('batch-count');
    var batchUpload = document.getElementById('batch-upload');
    var batchDownload = document.getElementById('batch-download');
    var batchDelete = document.getElementById('batch-delete');
    var batchClear = document.getElementById('batch-clear');
    var adoptButton = document.getElementById('sakura-sync-adopt');
    var selectAll = document.getElementById('select-all');
    var selectedRows = new Map();
    var MAX_SELECT = 100;

    var batchProgress = document.getElementById('batch-progress');
    var batchProgressText = document.getElementById('batch-progress-text');
    var batchProgressPct = document.getElementById('batch-progress-pct');
    var batchProgressBar = batchProgress ? batchProgress.querySelector('.progress') : null;
    var batchProgressFill = batchProgressBar ? batchProgressBar.querySelector('span') : null;
    var batchRunning = false;
    var batchFadeTimer = 0;
    var batchReloadTimer = 0;
    /* How long a finished bar stays readable before it fades out. */
    var BATCH_LINGER = 5000;
    var BATCH_FADE = 500;

    function clearBatchTimers() {
        window.clearTimeout(batchFadeTimer);
        window.clearTimeout(batchReloadTimer);
        batchFadeTimer = 0;
        batchReloadTimer = 0;
    }

    // Reflect how far a resumable batch has gone. The panel sits right under
    // the selection bar so it is visible without scrolling back to the top.
    function setBatchProgress(done, total, label) {
        if (!batchProgress) { return; }
        clearBatchTimers();
        batchProgress.classList.remove('is-leaving', 'is-done');
        var percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
        batchProgress.hidden = false;
        if (batchProgressFill) { batchProgressFill.style.width = percent + '%'; }
        if (batchProgressBar) { batchProgressBar.setAttribute('aria-valuenow', String(percent)); }
        if (batchProgressText) {
            batchProgressText.textContent = (label ? label + ' ' : '') + done + '/' + total;
        }
        if (batchProgressPct) { batchProgressPct.textContent = percent + '%'; }
    }

    function hideBatchProgress() {
        clearBatchTimers();
        if (batchProgress) {
            batchProgress.hidden = true;
            batchProgress.classList.remove('is-leaving', 'is-done');
        }
        if (batchProgressFill) { batchProgressFill.style.width = '0%'; }
        if (batchProgressText) { batchProgressText.textContent = ''; }
        if (batchProgressPct) { batchProgressPct.textContent = '0%'; }
    }

    // A finished batch used to disappear the instant the last request came
    // back, because the page reloaded immediately. The bar now parks at 100 %
    // with the server summary for five seconds, fades out, and only then
    // refreshes the table (which is also what brings the toast in).
    function finishBatchProgress(message, isError) {
        if (!batchProgress) {
            reloadWithToast(message, isError);
            return;
        }

        clearBatchTimers();
        batchProgress.hidden = false;
        batchProgress.classList.remove('is-leaving');
        batchProgress.classList.add('is-done');
        if (batchProgressFill) { batchProgressFill.style.width = '100%'; }
        if (batchProgressBar) { batchProgressBar.setAttribute('aria-valuenow', '100'); }
        if (batchProgressText) { batchProgressText.textContent = message || config.actionDone || ''; }
        if (batchProgressPct) { batchProgressPct.textContent = '100%'; }

        // Keep the controls locked while the result is on screen, but drop the
        // unload guard: the batch itself is already over.
        setBatchRunning(true);
        batchRunning = false;

        batchFadeTimer = window.setTimeout(function () {
            batchProgress.classList.add('is-leaving');
            batchReloadTimer = window.setTimeout(function () {
                batchProgress.hidden = true;
                reloadWithToast(message, isError);
            }, BATCH_FADE);
        }, BATCH_LINGER);
    }

    // Lock every batch button while a loop runs, so two loops cannot overlap.
    function setBatchRunning(state) {
        batchRunning = !!state;
        [batchUpload, batchDownload, batchDelete, batchClear, adoptButton].forEach(function (button) {
            if (button) { button.disabled = batchRunning; }
        });
    }

    // Browsers only honour this while a batch is actually running.
    window.addEventListener('beforeunload', function (event) {
        if (!batchRunning) { return ''; }
        event.preventDefault();
        event.returnValue = config.batchLeaveWarning || '';
        return config.batchLeaveWarning || '';
    });

    function selectionMeta(node) {
        return {
            status: node.getAttribute('data-status') || '',
            local: node.getAttribute('data-local') === '1',
            cloud: node.getAttribute('data-cloud') === '1'
        };
    }

    function selectedKeys(type) {
        var keys = [];
        selectedRows.forEach(function (meta, key) {
            if ('upload' === type && (!meta.local || 'synced' === meta.status)) { return; }
            if ('download' === type && (!meta.cloud || meta.local)) { return; }
            if ('delete' === type && !meta.cloud) { return; }
            keys.push(key);
        });
        return keys;
    }

    function updateBatchBar() {
        var total = selectedRows.size;
        if (batchBar) { batchBar.hidden = 0 === total; }
        if (batchCount) { batchCount.textContent = String(total); }
        if (batchUpload) { batchUpload.disabled = 0 === selectedKeys('upload').length; }
        if (batchDownload) { batchDownload.disabled = 0 === selectedKeys('download').length; }
        if (batchDelete) { batchDelete.disabled = 0 === selectedKeys('delete').length; }
        Array.prototype.forEach.call(document.querySelectorAll('.row-select'), function (box) {
            var row = box.closest('tr');
            if (row) { row.classList.toggle('is-selected', selectedRows.has(box.value)); }
        });
    }

    function clearSelection() {
        selectedRows.clear();
        Array.prototype.forEach.call(document.querySelectorAll('.row-select'), function (box) { box.checked = false; });
        if (selectAll) { selectAll.checked = false; }
        updateBatchBar();
    }

    document.addEventListener('change', function (event) {
        var box = event.target.closest('.row-select');
        if (box) {
            if (box.checked) {
                if (selectedRows.size >= MAX_SELECT) {
                    box.checked = false;
                    toast(config.batchLimit || '最多选择 100 项', true);
                    return;
                }
                selectedRows.set(box.value, selectionMeta(box));
            } else {
                selectedRows.delete(box.value);
            }
            updateBatchBar();
            return;
        }

        if (selectAll && event.target === selectAll) {
            var boxes = Array.prototype.slice.call(document.querySelectorAll('.row-select'));
            if (!selectAll.checked) {
                boxes.forEach(function (node) { node.checked = false; selectedRows.delete(node.value); });
                updateBatchBar();
                return;
            }
            var skipped = 0;
            boxes.forEach(function (node) {
                if (node.checked) { return; }
                if (selectedRows.size >= MAX_SELECT) { skipped++; return; }
                node.checked = true;
                selectedRows.set(node.value, selectionMeta(node));
            });
            if (skipped > 0) { toast(config.batchLimit || '最多选择 100 项', true); }
            updateBatchBar();
        }
    });

    function submitBatch(type, keys, confirm) {
        if (actionBusy || !keys.length) { return; }
        actionBusy = true;
        request('sakura_s3_sync_action', { sync_action: type, keys: keys, confirm: confirm || '' }, config.actionNonce)
            .then(function (data) {
                reloadWithToast(data.message || config.actionDone || '', false);
            }).catch(function (error) {
                actionBusy = false;
                toast(error.message, true);
            });
    }

    function batchAction(type) {
        var keys = selectedKeys(type);
        if (!keys.length) {
            toast(config.batchEmpty || '', true);
            return;
        }
        if ('delete' === type) {
            openBatchModal(keys);
            return;
        }
        var confirm = '';
        if ('upload' === type) {
            var replace = keys.filter(function (key) {
                var meta = selectedRows.get(key);
                return meta && meta.cloud;
            }).length;
            if (replace > 0) {
                var message = (config.batchReplace || '').replace('%d', String(replace));
                if (!window.confirm(message)) { return; }
                confirm = 'REPLACE';
            }
        }
        if ('upload' === type) {
            uploadBatch(keys);
            return;
        }
        if ('download' === type) {
            downloadBatch(keys);
            return;
        }
        submitBatch(type, keys, confirm);
    }

    // Upload one attachment at a time: the server walks every registered size
    // of an attachment, uploads and verifies them, then writes the sync status
    // back. The loop keeps the browser alive for large selections because each
    // request stops at a time budget.
    function uploadBatch(keys) {
        if (actionBusy || !keys.length) { return; }
        actionBusy = true;
        var offset = 0;
        var total = keys.length;
        var failed = 0;

        setBatchRunning(true);
        setBatchProgress(0, total, config.batchWorking || '');

        function step() {
            request(
                'sakura_s3_sync_action',
                { sync_action: 'upload', keys: keys, offset: offset, seconds: 20, confirm: 'REPLACE' },
                config.actionNonce
            ).then(function (data) {
                offset = parseInt(data.offset, 10) || 0;
                total = parseInt(data.total, 10) || total;
                failed += parseInt(data.failed, 10) || 0;
                setBatchProgress(offset, total, config.batchWorking || '');
                if (!data.done && offset < total) { step(); return; }
                actionBusy = false;
                // Selection is intentionally kept until the delayed reload: clearing it
                // would collapse the bar and make the finished panel jump upwards.
                finishBatchProgress(data.message || config.actionDone || '', failed > 0);
            }).catch(function (error) {
                actionBusy = false;
                setBatchRunning(false);
                hideBatchProgress();
                toast(error.message, true);
            });
        }

        step();
    }

    // Download one cloud-only object at a time. Each request copies as many
    // objects as fit the time budget, then the browser resumes from the
    // returned offset. Existing local files are kept (the server skips them).
    function downloadBatch(keys) {
        if (actionBusy || !keys.length) { return; }
        actionBusy = true;
        var offset = 0;
        var total = keys.length;
        var failed = 0;

        setBatchRunning(true);
        setBatchProgress(0, total, config.batchWorking || '');

        function step() {
            request(
                'sakura_s3_sync_action',
                { sync_action: 'download', keys: keys, offset: offset, seconds: 20 },
                config.actionNonce
            ).then(function (data) {
                offset = parseInt(data.offset, 10) || 0;
                total = parseInt(data.total, 10) || total;
                failed += parseInt(data.failed, 10) || 0;
                setBatchProgress(offset, total, config.batchWorking || '');
                if (!data.done && offset < total) { step(); return; }
                actionBusy = false;
                // Selection is intentionally kept until the delayed reload: clearing it
                // would collapse the bar and make the finished panel jump upwards.
                finishBatchProgress(data.message || config.actionDone || '', failed > 0);
            }).catch(function (error) {
                actionBusy = false;
                setBatchRunning(false);
                hideBatchProgress();
                toast(error.message, true);
            });
        }

        step();
    }

    // Restore media-library rows for indexed local files that have none. The
    // server adopts the first batch of unattached files per request, so the
    // browser keeps calling until nothing adoptable is left.
    function adoptBatch() {
        if (actionBusy) { return; }
        actionBusy = true;
        var failed = 0;

        var adoptText = document.getElementById('adopt-progress-text');
        if (adoptText) { adoptText.hidden = false; adoptText.textContent = config.batchWorking || ''; }

        setBatchRunning(true);

        function step() {
            request(
                'sakura_s3_sync_action',
                { sync_action: 'adopt', seconds: 20 },
                config.actionNonce
            ).then(function (data) {
                var total = parseInt(data.total, 10) || 0;
                var remaining = parseInt(data.remaining, 10) || 0;
                failed += parseInt(data.failed, 10) || 0;
                if (adoptText) {
                    adoptText.textContent = (config.batchWorking || '') + ' ' + Math.max(0, total - remaining) + '/' + total;
                }
                if (!data.done && remaining > 0) { step(); return; }
                actionBusy = false;
                setBatchRunning(false);
                reloadWithToast(data.message || config.actionDone || '', failed > 0);
            }).catch(function (error) {
                actionBusy = false;
                setBatchRunning(false);
                if (adoptText) { adoptText.hidden = true; }
                toast(error.message, true);
            });
        }

        step();
    }

    if (adoptButton) { adoptButton.addEventListener('click', function () { adoptBatch(); }); }
    if (batchClear) { batchClear.addEventListener('click', clearSelection); }
    if (batchUpload) { batchUpload.addEventListener('click', function () { batchAction('upload'); }); }
    if (batchDownload) { batchDownload.addEventListener('click', function () { batchAction('download'); }); }
    if (batchDelete) { batchDelete.addEventListener('click', function () { batchAction('delete'); }); }

    var testButton = document.getElementById('sakura-sync-test');
    if (testButton) {
        testButton.addEventListener('click', function () {
            if (testButton.disabled) { return; }
            testButton.disabled = true;
            var original = testButton.innerHTML;
            testButton.textContent = config.testing || '...';
            request('sakura_s3_sync_test', {}, testButton.getAttribute('data-nonce') || config.testNonce)
                .then(function (data) {
                    testButton.disabled = false;
                    testButton.innerHTML = original;
                    var latency = document.getElementById('sakura-sync-latency');
                    if (latency) { latency.textContent = 'headBucket · ' + data.latency + ' ms'; }
                    toast(data.message || config.testDone || '', false);
                })
                .catch(function (error) {
                    testButton.disabled = false;
                    testButton.innerHTML = original;
                    toast(error.message, true);
                });
        });
    }

    document.addEventListener('click', function (event) {
        var toastButton = event.target.closest('[data-toast]');
        if (toastButton) {
            toast(toastButton.getAttribute('data-toast'));
        }
    });

    try {
        var saved = sessionStorage.getItem('sakuraSyncToast');
        if (saved) {
            sessionStorage.removeItem('sakuraSyncToast');
            saved = JSON.parse(saved);
            if (saved && saved.message) {
                toast(saved.message, !!saved.error);
            }
        }
    } catch (e) {}

    var table = document.querySelector('.sakura-sync-table');
    var tbody = document.getElementById('recon-body');
    if (!tbody) { return; }

    var statusLabels = config.statusLabels || {};
    var typeLabels = config.typeLabels || {};
    var labels = config.labels || {};
    var filter = 'all';
    var page = 1;
    var pageSize = 25;
    var pageSizeButtons = Array.prototype.slice.call(document.querySelectorAll('[data-recon-size]'));
    var totalRows = parseInt(tbody.getAttribute('data-total'), 10) || 0;
    var cursor = tbody.getAttribute('data-cursor') || '';
    var hasMore = tbody.getAttribute('data-more') === '1';
    var history = [''];
    var search = document.getElementById('file-search');
    var typeFilter = document.getElementById('type-filter');
    var dateFilter = document.getElementById('date-filter');
    var empty = document.getElementById('recon-empty');
    var summary = document.getElementById('recon-summary');
    var pager = document.getElementById('recon-pager');
    var requestSeq = 0;

    // Browsers restore checkbox state on reload; selections live in JS only.
    clearSelection();

    if (!indexEnabled) {
        [search, typeFilter, dateFilter].forEach(function (node) {
            if (node) { node.disabled = true; }
        });
        if (pager) { pager.hidden = true; }
        Array.prototype.forEach.call(document.querySelectorAll('.sakura-sync-act, .sakura-sync-delete, #batch-upload, #batch-download, #batch-delete, #select-all'), function (node) {
            node.disabled = true;
        });
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatSize(bytes) {
        var value = parseInt(bytes, 10) || 0;
        if (value <= 0) { return '—'; }
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var index = 0;
        var number = value;
        while (number >= 1024 && index < units.length - 1) {
            number /= 1024;
            index++;
        }
        return (index === 0 ? String(Math.round(number)) : number.toFixed(number >= 10 ? 1 : 2).replace(/\.0+$/, '')) + ' ' + units[index];
    }

    function formatDate(timestamp) {
        var ts = parseInt(timestamp, 10) || 0;
        if (!ts) { return '—'; }
        var date = new Date(ts * 1000);
        var pad = function (number) { return (number < 10 ? '0' : '') + number; };
        return pad(date.getMonth() + 1) + '/' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    function badgeClass(status) {
        return status === 'synced' ? 'badge--ok' : status === 'failed' ? 'badge--bad' : (status === 'pending' || status === 'mismatch') ? 'badge--warn' : 'badge--muted';
    }

    function actionButton(type, row, label, confirm, extraClass) {
        return '<button class="link-btn ' + (extraClass || '') + ' sakura-sync-act" type="button" data-sync-action="' + esc(type) + '" data-key="' + esc(row.key) + '"' +
            (confirm ? ' data-confirm="' + esc(confirm) + '"' : '') +
            ' data-nonce="' + esc(config.actionNonce || '') + '">' + esc(label) + '</button>';
    }

    function rowHtml(row) {
        var status = row.status || 'synced';
        var local = !!row.local;
        var cloud = !!row.cloud;
        var name = row.name || '';
        var path = row.path || '';
        var statusLabel = statusLabels[status] || status;
        var typeLabel = typeLabels[row.type] || row.type || '—';
        var localHtml = local ? '<span class="loc yes"><span class="dot"></span>' + esc(labels.exists || '') + '</span>' : '<span class="loc no"><span class="dot"></span>' + esc(labels.missing || '') + '</span>';
        var cloudHtml = cloud ? '<span class="loc ' + (status === 'mismatch' ? 'warn' : 'yes') + '"><span class="dot"></span>' + esc(status === 'mismatch' ? (labels.diffsize || '') : (labels.exists || '')) + '</span>' : '<span class="loc no"><span class="dot"></span>' + esc(labels.missing || '') + '</span>';
        var actions = '';
        if (status === 'pending') {
            actions += actionButton('upload', row, labels.upload || '');
        } else if (status === 'remote') {
            actions += actionButton('download', row, labels.download || '');
            actions += '<button class="link-btn danger sakura-sync-delete" type="button" data-key="' + esc(row.key) + '" data-name="' + esc(name) + '" data-size="' + esc(formatSize(row.cloud_size)) + '" data-nonce="' + esc(config.actionNonce || '') + '">' + esc(labels.delete || '') + '</button>';
        } else if (status === 'mismatch') {
            actions += actionButton('upload', row, labels.upload || '', 'REPLACE');
        } else if (status === 'failed') {
            actions += local
                ? actionButton('upload', row, labels.upload || '', cloud ? 'REPLACE' : '')
                : actionButton('download', row, labels.download || '');
        }
        actions += '<a class="link-btn" href="' + esc(config.mediaUrl + '?s=' + encodeURIComponent(name)) + '">' + esc(labels.detail || '') + '</a>';

        return '<tr data-group="' + esc(status) + '" data-type="' + esc(row.type || 'other') + '" data-key="' + esc(row.key) + '">' +
            '<td class="col-select"><input type="checkbox" class="row-select" value="' + esc(row.key) + '" data-local="' + (local ? '1' : '0') + '" data-cloud="' + (cloud ? '1' : '0') + '" aria-label="' + esc(name) + '"></td>' +
            '<td><div class="file-cell"><span class="file-thumb"><svg class="icon" aria-hidden="true"><use href="#i-file"></use></svg></span><span><span class="file-name">' + esc(name) + '</span><span class="file-path">' + esc(path) + '</span></span></div></td>' +
            '<td><span class="type-pill">' + esc(String(name.split('.').pop() || '—').toUpperCase()) + '</span></td>' +
            '<td>' + esc(formatSize(row.size || row.cloud_size)) + '</td>' +
            '<td>' + localHtml + '</td><td>' + cloudHtml + '</td>' +
            '<td><span class="badge ' + badgeClass(status) + '"><span class="dot"></span>' + esc(statusLabel) + '</span></td>' +
            '<td>' + esc(formatDate(row.mtime)) + '</td>' +
            '<td><div class="action-cell">' + actions + '</div></td></tr>';
    }

    function totalForFilter() {
        if (filter === 'all') { return totalRows; }
        return parseInt((config.counts && config.counts[filter]) || 0, 10) || 0;
    }

    function updateSummary(visible) {
        if (!summary) { return; }
        var filtered = filter !== 'all' ||
            (search && search.value.trim() !== '') ||
            (typeFilter && typeFilter.value !== 'all') ||
            (dateFilter && dateFilter.value !== 'all');
        var total = filtered ? totalRows : totalForFilter();
        var start = visible === 0 ? 0 : (page - 1) * pageSize + 1;
        var end = Math.min((page - 1) * pageSize + visible, total);
        var template = filtered && config.summaryFiltered ? config.summaryFiltered : config.summary;
        if (template) {
            summary.textContent = template
                .replace('%1$s', String(start))
                .replace('%2$s', String(end))
                .replace('%3$s', String(total))
                .replace('%4$s', '0');
            if (dateFilter && dateFilter.value !== 'all' && config.sortedByRecent) {
                summary.textContent += config.sortedByRecent;
            }
        }
    }

    function renderPager() {
        if (!pager) { return; }
        pager.innerHTML = '';
        var previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'stats-page-btn';
        previous.textContent = '‹';
        previous.disabled = page <= 1;
        previous.addEventListener('click', function () {
            if (page <= 1) { return; }
            history.pop();
            loadPage(history[history.length - 1] || '', page - 1);
        });
        pager.appendChild(previous);

        var current = document.createElement('span');
        current.className = 'stats-page-now';
        current.textContent = page + ' / ' + Math.max(1, Math.ceil(totalForFilter() / pageSize));
        pager.appendChild(current);

        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'stats-page-btn';
        next.textContent = '›';
        next.disabled = !hasMore;
        next.addEventListener('click', function () {
            if (!hasMore) { return; }
            history.push(cursor);
            loadPage(cursor, page + 1);
        });
        pager.appendChild(next);
    }

    function applyRows(rows) {
        tbody.innerHTML = rows.map(rowHtml).join('');
        clearSelection();
        if (empty) { empty.classList.toggle('is-visible', rows.length === 0); }
        updateSummary(rows.length);
        renderPager();
    }

    function loadPage(nextCursor, nextPage) {
        var seq = ++requestSeq;
        var data = {
            status: filter,
            search: search ? search.value : '',
            type: typeFilter ? typeFilter.value : 'all',
            days: dateFilter ? dateFilter.value : 'all',
            cursor: nextCursor || '',
            limit: pageSize
        };
        tbody.classList.add('is-loading');
        // The second argument handles only fastPage() failures: a rejected
        // AJAX fallback must reach loadPage()'s catch instead of retrying twice.
        var loader = (config.searchUrl && config.searchToken)
            ? fastPage(data).then(function (payload) {
                if (!needsAjaxFallback(payload, data)) {
                    return payload;
                }
                return request('sakura_s3_sync_page', data, config.pageNonce);
            }, function () {
                return request('sakura_s3_sync_page', data, config.pageNonce);
            })
            : request('sakura_s3_sync_page', data, config.pageNonce);
        loader.then(function (payload) {
            if (seq !== requestSeq) { return; }
            page = nextPage;
            cursor = payload.cursor || '';
            hasMore = !!payload.has_more;
            if (typeof payload.total === 'number') { totalRows = payload.total; }
            tbody.classList.remove('is-loading');
            applyRows(payload.rows || []);
        }).catch(function (error) {
            if (seq !== requestSeq) { return; }
            tbody.classList.remove('is-loading');
            toast(error.message || config.pageError || '', true);
        });
    }

    function setFilter(value) {
        filter = value || 'all';
        page = 1;
        history = [''];
        loadPage('', 1);
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-filter]'), function (button) {
        button.addEventListener('click', function (event) {
            if (button.tagName === 'A') { return; }
            event.preventDefault();
            setFilter(button.getAttribute('data-filter'));
            var group = button.classList.contains('task-tab') ? '.task-tab' : '.seg-btn';
            Array.prototype.forEach.call(document.querySelectorAll(group), function (item) {
                var active = item === button;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            var reconcile = document.getElementById('reconcile');
            if (reconcile && button.classList.contains('task-tab')) {
                reconcile.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    var searchTimer = 0;
    if (search) {
        search.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                page = 1;
                history = [''];
                loadPage('', 1);
            }, 280);
        });
    }
    if (typeFilter) { typeFilter.addEventListener('change', function () { page = 1; history = ['']; loadPage('', 1); }); }
    if (dateFilter) { dateFilter.addEventListener('change', function () { page = 1; history = ['']; loadPage('', 1); }); }
    pageSizeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            pageSize = parseInt(button.getAttribute('data-recon-size'), 10) || 25;
            page = 1;
            history = [''];
            pageSizeButtons.forEach(function (item) { item.classList.toggle('is-active', item === button); });
            loadPage('', 1);
        });
    });

    var logChips = Array.prototype.slice.call(document.querySelectorAll('.log-filters [data-level]'));
    var logList = document.getElementById('log-list');
    var logMore = document.getElementById('sakura-logs-more');
    var logDataEl = document.getElementById('sakura-logs-data');
    var logData = [];
    try {
        logData = logDataEl ? JSON.parse(logDataEl.textContent || '[]') : [];
    } catch (e) {
        logData = [];
    }
    var logLevel = 'all';
    var logVisible = 6;

    function buildLogItem(item) {
        var row = document.createElement('div');
        row.className = 'log-item';
        row.setAttribute('data-level', item.level || 'info');

        var time = document.createElement('span');
        time.className = 'log-time';
        time.textContent = item.time || '';

        var body = document.createElement('div');
        body.className = 'log-body';
        var title = document.createElement('div');
        title.className = 'log-title';
        title.appendChild(document.createTextNode((item.message || '') + ' '));
        var badge = document.createElement('span');
        badge.className = 'badge ' + (item.badge || 'badge--info');
        badge.textContent = item.label || '信息';
        title.appendChild(badge);

        var object = document.createElement('div');
        object.className = 'log-object';
        object.textContent = item.scan || '';

        body.appendChild(title);
        body.appendChild(object);
        row.appendChild(time);
        row.appendChild(body);
        return row;
    }

    function renderLogs() {
        if (!logList) { return; }
        var filtered = logLevel === 'all'
            ? logData
            : logData.filter(function (item) { return item.level === logLevel; });
        var shown = logLevel === 'all' ? filtered.slice(0, logVisible) : filtered;
        logList.innerHTML = '';
        if (!shown.length) {
            var empty = document.createElement('p');
            empty.className = 'log-empty';
            empty.textContent = '暂无匹配日志。';
            logList.appendChild(empty);
        } else {
            shown.forEach(function (item) { logList.appendChild(buildLogItem(item)); });
        }
        if (!logMore) { return; }
        if (logLevel !== 'all') {
            logMore.hidden = true;
            return;
        }
        var remaining = Math.max(0, filtered.length - shown.length);
        logMore.hidden = remaining === 0;
        logMore.textContent = remaining ? '查询更多日志（还有 ' + remaining + ' 条）' : '没有更多日志';
    }

    logChips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            logChips.forEach(function (item) { item.classList.remove('is-active'); });
            chip.classList.add('is-active');
            logLevel = chip.getAttribute('data-level') || 'all';
            renderLogs();
        });
    });
    if (logMore) {
        logMore.addEventListener('click', function () {
            logVisible += 6;
            renderLogs();
        });
    }
    if (logData.length) { renderLogs(); } else if (logMore) { logMore.hidden = true; }

    Array.prototype.forEach.call(document.querySelectorAll('a[href*="sakura_s3_sync_export"]'), function (link) {
        link.addEventListener('click', function () {
            var url;
            try {
                url = new URL(link.getAttribute('href'), window.location.origin);
            } catch (e) {
                return;
            }
            url.searchParams.set('status', filter);
            if (search && search.value.trim() !== '') { url.searchParams.set('search', search.value.trim()); }
            if (typeFilter && typeFilter.value !== 'all') { url.searchParams.set('type', typeFilter.value); }
            if (dateFilter && dateFilter.value !== 'all') { url.searchParams.set('days', dateFilter.value); }
            link.setAttribute('href', url.toString());
        });
    });

    updateSummary(tbody.querySelectorAll('tr').length);
    hasMore = tbody.getAttribute('data-more') === '1';
    renderPager();
})();
/**
 * 樱花工具箱 admin behaviours.
 */
(function ($) {
    'use strict';

    $(function () {
        /* ── 压缩模式联动 ───────────────────────────── */
        var $modes = $("input[name='sakura_imgopt_compression_mode']");
        var $format = $('#sakura_imgopt_convert_format');
        var $quality = $('#sakura_imgopt_quality');

        function rowOf($el) {
            return $el.closest('tr').length ? $el.closest('tr') : $el.parent();
        }

        function setDisabled($el, disabled) {
            if (!$el.length) { return; }
            $el.prop('disabled', disabled);
            rowOf($el).toggleClass('sakura-imgopt-field-disabled', disabled);
        }

        function sync() {
            var mode = $modes.filter(':checked').val();
            setDisabled($format, mode === 'compress_only');
            setDisabled($quality, mode === 'convert_only');
        }

        $modes.on('change', sync);
        sync();

        $modes.closest('form').on('submit', function () {
            $format.prop('disabled', false);
            $quality.prop('disabled', false);
        });

        /* ── 裁剪配置扫描 ───────────────────────────── */
        var $btn = $('#sakura-imgopt-scan-crop');
        if (!$btn.length) { return; }

        var cfg = window.sakuraImgoptScan || {};
        var strings = cfg.strings || {};

        $btn.on('click', function (event) {
            event.preventDefault();
            if ($btn.prop('disabled')) { return; }
            $btn.prop('disabled', true).text(strings.scanning || '…');
            $.ajax({
                url: cfg.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: { action: cfg.action, nonce: cfg.nonce }
            }).done(function (response) {
                if (!response || !response.success || !response.data) { fail(); return; }
                applyScan(response.data);
            }).fail(fail).always(function () {
                $btn.prop('disabled', false).text(strings.button || '');
            });
        });

        function fail() {
            $('#sakura-imgopt-scan-result').text(strings.failed || '');
        }

        function applyScan(data) {
            var sizes = data.sizes || {};
            Object.keys(sizes).forEach(function (name) {
                if (name === '_big_threshold') { return; }
                updateRow(name, sizes[name]);
            });
            if (data.big_threshold) { updateBig(data.big_threshold); }
            $('#sakura-imgopt-scan-result').text(data.summary_text || '');
            if (data.wp_config_text) {
                $('#sakura-imgopt-scan-wpconfig').text(data.wp_config_text);
            }
            renderWarnings(data.warnings || []);
        }

        function updateRow(name, row) {
            var selector = "tr[data-size='" + String(name).replace(/'/g, "\\'") + "']";
            var $row = $(selector);
            if (!$row.length) {
                $row = buildRow(name, row);
                $('table.sakura-imgopt-size-table tbody').append($row);
            }

            var hasSize = (row.width || 0) > 0 || (row.height || 0) > 0;
            var dims = hasSize
                ? ((row.width || 0) > 0 ? row.width : (strings.auto || '')) + ' × ' + ((row.height || 0) > 0 ? row.height : (strings.auto || ''))
                : '—';
            $row.find("[data-field='dims']").text(dims);

            var $status = $row.find("[data-field='status'] .sakura-imgopt-status");
            $status.removeClass('ok warn bad conflict').addClass(statusClass(row)).text(row.status_label || '');
            $status.attr('title', row.reason || '');

            $row.find("[data-field='crop']").text(
                hasSize ? (row.crop ? (strings.hardCrop || '') : (strings.softCrop || '')) : '—'
            );

            var $sources = $row.find("[data-field='sources']").empty();
            var labels = [];
            (row.sources || []).forEach(function (source) {
                if (source && source.label && labels.indexOf(source.label) === -1) {
                    labels.push(source.label);
                }
            });
            if (labels.length) {
                labels.forEach(function (label) {
                    $("<span class='sakura-imgopt-source-tag'></span>").text(label).appendTo($sources);
                });
            } else if (row.registered) {
                $sources.text(strings.registeredNoSource || '');
            } else if (row.status === 'disabled_by_plugin' || row.status === 'removed') {
                $sources.text('—');
            } else {
                $sources.text(strings.unknownSource || '');
            }
        }

        function statusClass(row) {
            var cls = 'ok';
            if (['disabled_by_plugin', 'removed', 'filtered'].indexOf(row.status) >= 0) {
                cls = 'warn';
            } else if (['unregistered', 'unknown'].indexOf(row.status) >= 0) {
                cls = 'bad';
            }
            if (row.conflict) { cls += ' conflict'; }
            return cls;
        }

        function buildRow(name, row) {
            var $row = $('<tr>').attr('data-size', name);
            var $check = $("<td class='sakura-imgopt-col-check'></td>");
            $("<input type='checkbox' name='sakura_imgopt_disabled_sizes[]'>")
                .val(name)
                .prop('checked', row.status === 'disabled_by_plugin')
                .appendTo($check);
            $row.append($check);
            $row.append($('<td></td>').append($('<code></code>').text(name)));
            $row.append($("<td data-field='dims'></td>"));
            $row.append($("<td data-field='status'></td>").append($("<span class='sakura-imgopt-status'></span>")));
            $row.append($("<td data-field='crop'></td>"));
            $row.append($("<td data-field='sources'></td>"));
            return $row;
        }

        function updateBig(big) {
            var $row = $("tr[data-size='_big_threshold']");
            if (!$row.length || !big) { return; }
            var $status = $row.find('.sakura-imgopt-status');
            $status.removeClass('ok warn').addClass(big.disabled ? 'warn' : 'ok')
                .attr('title', big.reason || '')
                .text(big.status_label || (big.disabled ? (strings.disabled || '') : (strings.enabled || '')));
            $row.find("[data-field='sources']").text(big.source || '');
        }

        function renderWarnings(warnings) {
            var $box = $('#sakura-imgopt-scan-warnings');
            if (!$box.length) { return; }
            var $list = $box.find('ul').empty();
            warnings.forEach(function (warning) {
                if (warning && warning.message) {
                    $('<li></li>').text(warning.message).appendTo($list);
                }
            });
            $box.prop('hidden', warnings.length === 0);
        }
    });
})(jQuery);

/* Waterfall (masonry) for settings cards: place each card in the shorter column. */
(function () {
    'use strict';

    function layoutCompactCards(grid) {
        // The settings screen uses an explicit CSS grid so DOM order, visual
        // order and keyboard order stay aligned.
        if (grid.classList.contains('compact-cards--settings')) {
            return;
        }

        // Layout groups manage their own 12-column grid; masonry would wrap
        // cards in fixed columns and fight the drag/resize engine.
        if (grid.hasAttribute('data-sakura-layout')) {
            return;
        }

        var wrap = grid.querySelector(':scope > .masonry');
        var flow = [];

        if (wrap) {
            flow = Array.prototype.slice.call(wrap.querySelectorAll(':scope > .masonry-col > .card'));
        } else {
            Array.prototype.slice.call(grid.children).forEach(function (node, index) {
                if (node.classList && node.classList.contains('card') && !node.classList.contains('card--wide')) {
                    node.setAttribute('data-masonry-order', String(index));
                    flow.push(node);
                }
            });
        }

        if (flow.length < 2) {
            return;
        }

        flow.sort(function (a, b) {
            return parseInt(a.getAttribute('data-masonry-order') || '0', 10) - parseInt(b.getAttribute('data-masonry-order') || '0', 10);
        });

        if (window.innerWidth <= 1100) {
            if (wrap) {
                flow.forEach(function (card) {
                    grid.appendChild(card);
                });
                wrap.parentNode.removeChild(wrap);
            }
            return;
        }

        if (!wrap) {
            flow.forEach(function (card) {
                card.parentNode.removeChild(card);
            });
            wrap = document.createElement('div');
            wrap.className = 'masonry';
            var left = document.createElement('div');
            var right = document.createElement('div');
            left.className = 'masonry-col';
            right.className = 'masonry-col';
            wrap.appendChild(left);
            wrap.appendChild(right);
            grid.appendChild(wrap);
        }

        var cols = Array.prototype.slice.call(wrap.querySelectorAll(':scope > .masonry-col'));
        if (cols.length < 2) {
            return;
        }
        cols.forEach(function (col) {
            while (col.firstChild) {
                col.removeChild(col.firstChild);
            }
        });
        flow.forEach(function (card) {
            var target = cols[0].offsetHeight <= cols[1].offsetHeight ? cols[0] : cols[1];
            target.appendChild(card);
        });
    }

    function layoutAll() {
        Array.prototype.forEach.call(document.querySelectorAll('.compact-cards'), layoutCompactCards);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', layoutAll);
    } else {
        layoutAll();
    }
    window.addEventListener('load', layoutAll);
    window.addEventListener('resize', function () {
        window.clearTimeout(window.__sakuraMasonryTimer);
        window.__sakuraMasonryTimer = window.setTimeout(layoutAll, 180);
    });
})();

(function () {
    var floats = document.querySelectorAll('[data-sakura-save-anchor]');
    if (!floats.length) {
        return;
    }
    Array.prototype.forEach.call(floats, function (button) {
        var anchor = document.querySelector(button.getAttribute('data-sakura-save-anchor'));
        if (!anchor) {
            button.hidden = false;
            return;
        }
        var hero = document.querySelector('.sakura-sync-app .hero');
        var queued = false;
        var update = function () {
            queued = false;
            var heroPassed = hero ? hero.getBoundingClientRect().bottom < 0 : window.scrollY > 280;
            var anchorVisible = anchor.getBoundingClientRect().top < window.innerHeight - 24;
            button.hidden = !heroPassed || anchorVisible;
        };
        var queue = function () {
            if (queued) {
                return;
            }
            queued = true;
            window.requestAnimationFrame(update);
        };
        window.addEventListener('scroll', queue, { passive: true });
        window.addEventListener('resize', queue);
        update();
    });
})();
/* ── 优化统计：服务端分页 ───────────── */
(function () {
    'use strict';
    function initStatsHistory() {
        var table = document.getElementById('sakura-stats-history');
        if (!table) { return; }
        var foot = document.getElementById('stats-history-foot');
        var cfg = window.sakuraImgoptScan || {};
        var tbody = table.querySelector('tbody');
        var sizeButtons = Array.prototype.slice.call(document.querySelectorAll('.stats-size-btn'));
        var prev = document.getElementById('stats-page-prev');
        var next = document.getElementById('stats-page-next');
        var summary = document.getElementById('stats-history-summary');
        var now = document.getElementById('stats-page-now');
        var total = parseInt(table.getAttribute('data-total'), 10) || 0;
        var page = 1;
        var per = 25;
        var pages = Math.max(1, Math.ceil(total / per));
        var busy = false;

        function setActive() {
            sizeButtons.forEach(function (button) {
                button.classList.toggle('is-active', Number(button.getAttribute('data-size')) === per);
            });
        }
        function updateMeta() {
            pages = Math.max(1, Math.ceil(total / per));
            if (page > pages) { page = pages; }
            if (page < 1) { page = 1; }
            var start = total ? (page - 1) * per + 1 : 0;
            var end = Math.min(page * per, total);
            if (summary) {
                summary.textContent = total
                    ? '显示 ' + start + '–' + end + ' 条，共 ' + total + ' 条'
                    : '暂无记录';
            }
            if (now) { now.textContent = page + ' / ' + pages; }
            if (prev) { prev.disabled = page <= 1 || busy; }
            if (next) { next.disabled = page >= pages || busy; }
            setActive();
        }
        function emptyRow() {
            tbody.innerHTML = '<tr class="stats-empty"><td colspan="7">暂无记录。</td></tr>';
        }
        function load(nextPage, nextPer) {
            if (busy || !cfg.ajaxUrl || !cfg.statsAction || !cfg.statsNonce) { return; }
            busy = true;
            table.classList.add('is-loading');
            table.setAttribute('aria-busy', 'true');
            if (summary) { summary.textContent = '加载中…'; }
            var body = new URLSearchParams();
            body.set('action', cfg.statsAction);
            body.set('nonce', cfg.statsNonce);
            body.set('page', String(nextPage));
            body.set('per_page', String(nextPer));
            fetch(cfg.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (response) {
                return response.json();
            }).then(function (result) {
                if (!result || !result.success || !result.data) {
                    throw new Error('stats page failed');
                }
                var data = result.data;
                page = Number(data.page) || nextPage;
                per = Number(data.per_page) || nextPer;
                total = Number(data.total) || 0;
                pages = Number(data.pages) || 1;
                if (data.rows) {
                    tbody.innerHTML = data.rows;
                } else {
                    emptyRow();
                }
                busy = false;
                table.classList.remove('is-loading');
                table.removeAttribute('aria-busy');
                updateMeta();
            }).catch(function () {
                busy = false;
                table.classList.remove('is-loading');
                table.removeAttribute('aria-busy');
                if (summary) { summary.textContent = '加载失败，请稍后重试。'; }
                updateMeta();
            });
        }

        sizeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var size = Number(button.getAttribute('data-size')) || 25;
                if (size === per && page === 1) { return; }
                per = size;
                page = 1;
                setActive();
                load(1, size);
            });
        });
        if (prev) {
            prev.addEventListener('click', function () {
                if (page > 1) { load(page - 1, per); }
            });
        }
        if (next) {
            next.addEventListener('click', function () {
                if (page < pages) { load(page + 1, per); }
            });
        }
        if (!total) {
            if (foot) { foot.hidden = true; }
            return;
        }
        updateMeta();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initStatsHistory);
    } else {
        initStatsHistory();
    }
})();


/* S3 setup dialog: centered, dismissible per user, and skipped once after
 * the operator follows its primary action to the connection screen. */
(function () {
    var modal = document.getElementById('sakura-s3-config-modal');
    if (!modal) { return; }

    var card = modal.querySelector('.sakura-modal__card');
    var focusTarget = modal.querySelector('[data-config-focus]');
    var primary = modal.querySelector('[data-config-primary]');
    var dontRemind = modal.querySelector('[data-config-dont-remind]');
    var lastFocus = null;
    var sent = false;
    var skipKey = 'sakuraS3ConfigSkipOnce';
    var skip = false;

    try {
        skip = window.sessionStorage.getItem(skipKey) === '1';
        if (skip) { window.sessionStorage.removeItem(skipKey); }
    } catch (e) {}

    function persistChoice(permanent) {
        if (sent) { return; }
        sent = true;

        var params = new URLSearchParams();
        params.set('action', 'sakura_s3_config_notice');
        params.set('nonce', modal.getAttribute('data-nonce') || '');
        params.set(permanent ? 'dismiss' : 'snooze', '1');

        var url = modal.getAttribute('data-ajax-url') || '';
        var body = params.toString();
        var queued = false;

        if (window.navigator && typeof window.navigator.sendBeacon === 'function') {
            try {
                queued = window.navigator.sendBeacon(url, new Blob([body], { type: 'application/x-www-form-urlencoded;charset=UTF-8' }));
            } catch (e) {}
        }

        if (!queued && window.fetch) {
            window.fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body,
                keepalive: true
            }).catch(function () {});
        }
    }

    function close() {
        if (modal.hidden) { return; }
        persistChoice(!!(dontRemind && dontRemind.checked));
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('sakura-modal-open');
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
    }

    function open() {
        if (!modal.hidden) { return; }
        lastFocus = document.activeElement;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('sakura-modal-open');
        window.setTimeout(function () {
            if (focusTarget) { focusTarget.focus(); }
        }, 40);
    }

    function focusFirstEmptyField() {
        var target;
        try { target = document.querySelector(primary.getAttribute('href')); } catch (e) { return; }
        if (!target) { return; }

        window.setTimeout(function () {
            var fields = target.querySelectorAll('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
            var field = null;
            for (var i = 0; i < fields.length; i++) {
                if (fields[i].type !== 'checkbox' && fields[i].type !== 'radio' && !fields[i].value) {
                    field = fields[i];
                    break;
                }
            }
            if (!field) { field = fields[0]; }
            if (field) { field.focus({ preventScroll: true }); }
        }, 360);
    }

    modal.addEventListener('click', function (event) {
        var node = event.target && event.target.nodeType === 1 ? event.target : event.target.parentElement;
        if (!node) { return; }

        if (node.closest('[data-config-close]')) {
            event.preventDefault();
            close();
            return;
        }

        if (node.closest('[data-config-primary]')) {
            var href = primary.getAttribute('href') || '';
            if (href.charAt(0) === '#') {
                event.preventDefault();
                close();
                var section = document.querySelector(href);
                if (section) {
                    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    focusFirstEmptyField();
                }
                return;
            }

            close();
            try { window.sessionStorage.setItem(skipKey, '1'); } catch (e) {}
        }
    });

    document.addEventListener('keydown', function (event) {
        if (modal.hidden) { return; }

        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }

        if (event.key !== 'Tab') { return; }
        var focusable = card.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
        if (!focusable.length) { return; }

        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    if (!skip) {
        window.setTimeout(open, 220);
    }
})();

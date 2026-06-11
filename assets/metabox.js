// AI translate metabox: prepare → batch loop → finalize.
// Self-contained vanilla JS (safe with the WP 7 iframed editor).
(function () {
	'use strict';

	var cfg = window.aiptMetabox;
	if (!cfg || !cfg.postId) {
		return;
	}

	function post(action, extra) {
		var data = new FormData();
		data.append('action', action);
		data.append('post_id', cfg.postId);
		data.append('_ajax_nonce', cfg.nonce);
		Object.keys(extra || {}).forEach(function (key) {
			data.append(key, extra[key]);
		});
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (response) { return response.json(); });
	}

	function sprintf(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return template.replace(/%\d\$d|%d|%s/g, function () {
			return args[i++];
		});
	}

	function setBusy(busy) {
		document.querySelectorAll('#aipt_metabox .aipt-translate').forEach(function (button) {
			button.disabled = busy;
		});
	}

	function status(row, html) {
		row.querySelector('.aipt-status').innerHTML = html;
	}

	function showError(row, message, retryFn) {
		var box = row.querySelector('.aipt-status');
		box.innerHTML = '';

		var text = document.createElement('span');
		text.className = 'aipt-error';
		text.textContent = cfg.i18n.error + ' ' + (message || 'unknown');
		box.appendChild(text);

		if (retryFn) {
			var retry = document.createElement('button');
			retry.type = 'button';
			retry.className = 'button button-small';
			retry.textContent = cfg.i18n.retry;
			retry.addEventListener('click', retryFn);
			box.appendChild(document.createTextNode(' '));
			box.appendChild(retry);
		}
		setBusy(false);
	}

	function showDone(row, editLink) {
		var box = row.querySelector('.aipt-status');
		box.innerHTML = '';
		box.appendChild(document.createTextNode(cfg.i18n.done + ' '));

		var link = document.createElement('a');
		link.href = editLink;
		link.textContent = cfg.i18n.openDraft;
		box.appendChild(link);
		setBusy(false);
	}

	function runBatches(row, jobId, startIndex, total) {
		var index = startIndex;

		function next() {
			if (index >= total) {
				status(row, cfg.i18n.finalizing);
				post('aipt_finalize', { job_id: jobId }).then(function (response) {
					if (!response.success) {
						showError(row, response.data && response.data.message, function () {
							runBatches(row, jobId, total, total); // retry finalize only
						});
						return;
					}
					showDone(row, response.data.edit_link);
				}).catch(function (error) {
					showError(row, String(error), next);
				});
				return;
			}

			status(row, sprintf(cfg.i18n.batch, index + 1, total));
			post('aipt_translate_batch', { job_id: jobId, batch: index }).then(function (response) {
				if (!response.success) {
					var failedIndex = index;
					showError(row, response.data && response.data.message, function () {
						setBusy(true);
						runBatches(row, jobId, failedIndex, total);
					});
					return;
				}
				index++;
				next();
			}).catch(function (error) {
				var failedIndex = index;
				showError(row, String(error), function () {
					setBusy(true);
					runBatches(row, jobId, failedIndex, total);
				});
			});
		}

		next();
	}

	function start(row, button) {
		var existing = row.dataset.existing;
		var mode = button.dataset.mode || 'overwrite';
		if (existing && mode === 'overwrite' && !window.confirm(cfg.i18n.confirmOverwrite)) {
			return;
		}

		setBusy(true);
		status(row, cfg.i18n.preparing);

		post('aipt_prepare', {
			target: row.dataset.lang,
			mode: mode,
			confirm: existing && mode === 'overwrite' ? 1 : 0
		}).then(function (response) {
			if (!response.success) {
				showError(row, response.data && response.data.message, null);
				return;
			}
			runBatches(row, response.data.job_id, 0, response.data.total);
		}).catch(function (error) {
			showError(row, String(error), null);
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('#aipt_metabox .aipt-translate');
		if (!button || button.disabled) {
			return;
		}
		start(button.closest('.aipt-row'), button);
	});
})();

// Strings section of the bulk translation page (WPML String Translation): the domain table
// (aipt_bulk_string_domains, reloaded when the languages change), Estimate (dry run) and
// Translate through aipt_bulk_strings, one domain × language at a time, in parts of at
// most two gateway batches (next → after) until the server says the domain is done. Requests, the
// small DOM helpers, the error policy and the log rows come from window.aiptBulkRun
// (assets/bulk-ui.js, bulk-run.js); this section keeps its own state. The selected domains
// and the update box are remembered in localStorage.
(function () {
	'use strict';

	var cfg = window.aiptBulk;
	var R = window.aiptBulkRun;
	var form = document.getElementById('aipt-strings-form');
	if (!cfg || !R || !form) {
		return;
	}

	var t = cfg.i18n.strings;
	var store = R.store('aiptBulkStrings');
	var el = {
		from: document.getElementById('aipt-strings-from'),
		domains: document.getElementById('aipt-strings-domains'),
		estimate: document.getElementById('aipt-strings-estimate'),
		start: document.getElementById('aipt-strings-start'),
		stop: document.getElementById('aipt-strings-stop'),
		message: document.getElementById('aipt-strings-message'),
		progress: document.getElementById('aipt-strings-progress'),
		progressText: document.getElementById('aipt-strings-progress-text'),
		summary: document.getElementById('aipt-strings-summary'),
		log: document.getElementById('aipt-strings-log')
	};
	var job = null;
	var loadToken = 0;
	// Ticked domains, kept while the table is reloaded.
	var selected = [];

	// ---- Form -----------------------------------------------------------------

	function readForm() {
		var data = new FormData(form);
		return {
			from: data.get('from') || '',
			targets: data.getAll('targets[]'),
			domains: data.getAll('domains[]'),
			update: data.get('update') ? 1 : 0
		};
	}

	function save() {
		store.set({ domains: selected, update: readForm().update });
	}

	// The ticked domains of the table on screen.
	function remember() {
		if (el.domains.querySelector('table')) {
			selected = readForm().domains;
		}
		save();
	}

	function message(text) {
		el.message.textContent = text || '';
	}

	// Domain table for the current languages; ticked domains stay ticked. A reply for
	// languages that are no longer selected is ignored.
	async function loadDomains() {
		var opts = readForm();
		var token = ++loadToken;
		el.domains.textContent = '';
		if (!opts.targets.length) {
			el.domains.appendChild(R.node('p', t.selectTarget));
			return;
		}
		el.domains.appendChild(R.node('p', t.loading));
		var result = await R.call('string_domains', { from: opts.from, targets: opts.targets });
		if (token !== loadToken) {
			return;
		}
		el.domains.textContent = '';
		if (!result.ok) {
			el.domains.appendChild(R.node('p', R.errorText(result), 'aipt-error'));
			return;
		}
		var list = result.data.domains || [];
		if (!list.length) {
			el.domains.appendChild(R.node('p', t.noDomains));
			return;
		}
		el.domains.appendChild(domainTable(list, opts.targets));
	}

	function domainTable(list, targets) {
		var table = R.node('table', null, 'widefat striped');
		var head = R.node('tr');
		var all = R.node('input');
		all.type = 'checkbox';
		all.addEventListener('change', function () {
			table.querySelectorAll('input[name="domains[]"]').forEach(function (input) {
				input.checked = all.checked;
			});
			remember();
		});
		var cell = R.node('td', null, 'check-column');
		cell.appendChild(all);
		head.appendChild(cell);
		head.appendChild(R.node('th', t.domain));
		head.appendChild(R.node('th', t.total));
		head.appendChild(R.node('th', t.untranslated));
		var thead = R.node('thead');
		thead.appendChild(head);
		table.appendChild(thead);
		var body = R.node('tbody');
		list.forEach(function (entry, index) {
			var row = R.node('tr');
			var box = R.node('input');
			box.type = 'checkbox';
			box.name = 'domains[]';
			box.value = entry.domain;
			box.id = 'aipt-strings-domain-' + index;
			box.checked = selected.indexOf(entry.domain) !== -1;
			var check = R.node('td', null, 'check-column');
			check.appendChild(box);
			row.appendChild(check);
			var name = R.node('td');
			var label = R.node('label', entry.domain);
			label.htmlFor = box.id;
			name.appendChild(label);
			row.appendChild(name);
			row.appendChild(R.node('td', String(entry.total)));
			row.appendChild(R.node('td', targets.map(function (lang) {
				return lang + ': ' + (entry.untranslated[lang] || 0);
			}).join(', ')));
			body.appendChild(row);
		});
		table.appendChild(body);
		return table;
	}

	// ---- Run ------------------------------------------------------------------

	function begin(dry) {
		job = { dry: dry, stopping: false, reason: '', cost: 0, done: 0, total: 0, consecutive: 0 };
		el.summary.textContent = '';
		el.log.textContent = '';
		el.estimate.disabled = true;
		el.start.disabled = true;
		el.stop.disabled = false;
	}

	function end(text) {
		var cost = job.cost.toFixed(4);
		message(text || job.reason || R.sprintf(job.stopping ? t.stopped : job.dry ? t.estimateDone : t.finished, cost));
		el.estimate.disabled = false;
		el.start.disabled = false;
		el.stop.disabled = true;
		var dry = job.dry;
		job = null;
		if (!dry) {
			loadDomains();
		}
	}

	function stop(reason) {
		if (job && !job.stopping) {
			job.stopping = true;
			job.reason = reason || '';
			message(reason || t.stopping);
		}
	}

	function progress(text) {
		el.progress.max = Math.max(1, job.total);
		el.progress.value = job.done;
		el.progressText.textContent = job.done + ' / ' + job.total + (text ? ' — ' + text : '');
	}

	// One summary row per domain × language, updated after every part.
	function summaryRow(domain, lang) {
		var row = R.node('tr');
		var result = R.node('td');
		var cost = R.node('td', '$0.0000');
		row.appendChild(R.node('td', domain));
		row.appendChild(R.node('td', lang));
		row.appendChild(result);
		row.appendChild(cost);
		el.summary.appendChild(row);
		var totals = { counts: {}, chars: 0, cost: 0 };
		return function (data, error) {
			if (data) {
				Object.keys(data.counts || {}).forEach(function (status) {
					totals.counts[status] = (totals.counts[status] || 0) + data.counts[status];
				});
				totals.chars += data.chars || 0;
				totals.cost += Number(data.cost) || 0;
			}
			var parts = Object.keys(totals.counts).map(function (status) {
				return R.label(status) + ': ' + totals.counts[status];
			});
			if (totals.chars) {
				parts.push(cfg.i18n.chars + ': ' + totals.chars.toLocaleString());
			}
			if (error) {
				parts.push(error);
			}
			result.textContent = parts.join(', ');
			cost.textContent = '$' + totals.cost.toFixed(4);
		};
	}

	function failed(update, who, lang, result) {
		job.consecutive++;
		update(null, R.errorText(result));
		R.logRow(el.log, who, lang, 'error', R.errorText(result));
		var reason = R.stopReason(result, job.consecutive);
		if (reason) {
			stop(reason);
		}
	}

	async function runPair(opts, domain, lang) {
		var who = R.sprintf(t.pair, domain, lang);
		var update = summaryRow(domain, lang);
		var after = 0;
		while (after !== null && !job.stopping) {
			progress(who);
			var result = await R.call('strings', {
				domain: domain,
				from: opts.from,
				target: lang,
				update: opts.update,
				dry_run: job.dry ? 1 : 0,
				after: after
			}, function () { progress(who + ': ' + cfg.i18n.retrying); });
			if (!result.ok) {
				failed(update, who, lang, result);
				return;
			}
			var data = result.data;
			var errors = false;
			job.cost += Number(data.cost) || 0;
			update(data);
			(data.rows || []).forEach(function (row) {
				errors = errors || row.status === 'error';
				R.logRow(el.log, domain + ' "' + row.name + '" #' + row.id, lang, row.status, row.message);
			});
			// A reply with failed rows counts as a failure in a row, like a terms slice.
			job.consecutive = errors ? job.consecutive + 1 : 0;
			var reason = R.stopReason({ code: '' }, job.consecutive);
			if (R.isAuthError(data.error_code)) {
				stop(cfg.i18n.authError);
			} else if (reason) {
				stop(reason);
			}
			after = data.next ?? null;
			if (after !== null) {
				progress(who + ': ' + R.sprintf(t.left, data.remaining));
			}
		}
	}

	async function run(dry) {
		var opts = readForm();
		if (job) {
			return;
		}
		if (R.current()) {
			message(t.busy);
			return;
		}
		if (!opts.targets.length) {
			message(t.selectTarget);
			return;
		}
		if (!opts.domains.length) {
			message(t.selectDomain);
			return;
		}
		begin(dry);
		job.total = opts.domains.length * opts.targets.length;
		message(dry ? cfg.i18n.estimateDone + '…' : cfg.i18n.running);
		for (var i = 0; i < opts.domains.length && !job.stopping; i++) {
			for (var j = 0; j < opts.targets.length && !job.stopping; j++) {
				await runPair(opts, opts.domains[i], opts.targets[j]);
				job.done++;
				progress('');
			}
		}
		end();
	}

	// ---- Wiring ---------------------------------------------------------------

	var saved = store.get() || {};
	selected = saved.domains || [];
	if (saved.update) {
		form.querySelector('input[name="update"]').checked = true;
	}
	R.syncTargets(form, el.from);
	loadDomains();
	el.from.addEventListener('change', function () {
		R.syncTargets(form, el.from);
		loadDomains();
	});
	form.addEventListener('change', function (event) {
		if (event.target.name === 'targets[]') {
			loadDomains();
		} else if (event.target.name === 'domains[]') {
			remember();
		} else if (event.target.name === 'update') {
			save();
		}
	});
	el.estimate.addEventListener('click', function () { run(true); });
	el.start.addEventListener('click', function () { run(false); });
	el.stop.addEventListener('click', function () { stop(''); });
	R.guardUnload(function () { return !!(job && !job.dry); });
})();

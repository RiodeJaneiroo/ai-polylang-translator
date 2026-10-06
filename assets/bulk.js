// Bulk translation page: the form (values remembered in localStorage, a convenience),
// Estimate and Start through the run engine (assets/bulk-run.js), the run token and the
// "needs manual check" panel. The run token (aiptBulkSince in localStorage, the server
// time of the run's first Start) is kept through Stop and cleared when the run finishes
// by itself or with "New run"; records the server finished since then are skipped.
(function () {
	'use strict';

	var cfg = window.aiptBulk;
	var R = window.aiptBulkRun;
	var form = document.getElementById('aipt-bulk-form');
	if (!cfg || !R || !form) {
		return;
	}

	var t = cfg.i18n;
	var formStore = R.store('aiptBulkForm');
	var sinceStore = R.store('aiptBulkSince');
	var el = {
		newRun: document.getElementById('aipt-bulk-new-run'),
		from: document.getElementById('aipt-bulk-from'),
		start: document.getElementById('aipt-bulk-start'),
		estimate: document.getElementById('aipt-bulk-estimate'),
		stop: document.getElementById('aipt-bulk-stop'),
		box: document.getElementById('aipt-bulk-estimate-box'),
		pending: document.getElementById('aipt-bulk-pending')
	};

	// ---- Form -----------------------------------------------------------------

	function readForm() {
		var data = new FormData(form);
		return {
			post_types: data.getAll('post_types[]'),
			taxonomies: data.getAll('taxonomies[]'),
			from: data.get('from') || '',
			targets: data.getAll('targets[]'),
			mode: data.get('mode') || 'skip',
			confirm: data.get('confirm') ? 1 : 0,
			publish: data.get('publish') ? 1 : 0,
			keep_date: data.get('keep_date') ? 1 : 0,
			limit: parseFloat(data.get('limit')) || 0,
			streams: Math.min(3, Math.max(1, parseInt(data.get('streams'), 10) || 2))
		};
	}

	function saveForm(opts) {
		formStore.set(opts);
	}

	// The overwrite confirmation is never restored: it is ticked for each run.
	function restoreForm() {
		var saved = formStore.get();
		if (!saved) {
			return;
		}
		form.querySelectorAll('input, select').forEach(function (input) {
			var name = input.name.replace('[]', '');
			if (name === 'confirm' || !(name in saved)) {
				return;
			}
			var value = saved[name];
			if (input.type === 'checkbox') {
				input.checked = Array.isArray(value) ? value.indexOf(input.value) !== -1 : !!value;
			} else if (input.type === 'radio') {
				input.checked = input.value === value;
			} else if (input.tagName === 'SELECT') {
				if (input.querySelector('option[value="' + String(value).replace(/"/g, '') + '"]')) {
					input.value = value;
				}
			} else {
				input.value = value || '';
			}
		});
	}

	// Term counts of the source language: "Label (n)"; a taxonomy without terms in it is
	// disabled and unticked. A reply for a source that is no longer selected is ignored.
	async function loadTaxonomies() {
		var from = el.from.value;
		var result = await R.call('taxonomies', { from: from });
		if (!result.ok || el.from.value !== from) {
			return;
		}
		form.querySelectorAll('input[name="taxonomies[]"]').forEach(function (input) {
			var count = result.data.counts[input.value];
			if (count === undefined) {
				return;
			}
			input.parentNode.querySelector('.aipt-bulk-tax-label').textContent = input.getAttribute('data-label') + ' (' + count + ')';
			input.disabled = !count;
			if (!count) {
				input.checked = false;
			}
		});
	}

	// ---- Run token ------------------------------------------------------------

	function getSince() {
		return parseInt(sinceStore.get(), 10) || 0;
	}

	function setSince(since) {
		sinceStore.set(since || null);
		el.newRun.hidden = !since;
	}

	// Tombstones removed on the server; the token becomes the new run's ($start) or is
	// cleared (the run is forgotten).
	async function resetRun(start) {
		var result = await R.call('reset', {});
		if (result.ok) {
			setSince(start ? result.data.since : 0);
		}
		return result;
	}

	// ---- Actions --------------------------------------------------------------

	function validate(opts) {
		if (opts.mode === 'overwrite' && !opts.confirm) {
			R.message(t.confirmNeeded);
			return false;
		}
		return true;
	}

	async function plan(opts) {
		R.message(t.planning);
		var result = await R.call('plan', Object.assign({
			post_types: opts.post_types,
			taxonomies: opts.taxonomies,
			targets: opts.targets
		}, R.current().common));
		if (!result.ok) {
			R.end(R.errorText(result));
			return null;
		}
		var records = [];
		result.data.posts.forEach(function (post) {
			opts.targets.forEach(function (lang) {
				records.push({ id: post.id, parent: post.parent, lang: lang });
			});
		});
		R.current().total = result.data.terms.length + records.length;
		if (!R.current().total) {
			R.end(t.nothing);
			return null;
		}
		return { terms: result.data.terms, records: records };
	}

	async function start() {
		var opts = readForm();
		if (R.current() || !validate(opts)) {
			return;
		}
		saveForm(opts);
		el.box.hidden = true;
		R.begin(opts);
		var work = await plan(opts);
		if (!work) {
			return;
		}
		var since = getSince();
		if (!since) {
			var reset = await resetRun(true);
			if (!reset.ok) {
				R.end(R.errorText(reset));
				return;
			}
			since = reset.data.since;
			R.message(t.running);
		} else {
			R.message(t.resumed);
		}
		R.current().since = since;
		await R.runTerms(work.terms);
		await R.runRecords(work.records);
		// Finished by itself (not stopped by the user, an error or the cost limit).
		if (!R.current().stopping) {
			await resetRun();
		}
		R.end();
	}

	async function estimateChunks(kind, items, size, context, totals) {
		for (var index = 0; index < items.length && !R.current().stopping;) {
			var params = Object.assign({
				context: JSON.stringify(context),
				items: JSON.stringify(items.slice(index, index + size))
			}, R.current().common);
			if (kind === 'terms') {
				params.terms = 1;
			}
			var result = await R.call('estimate', params);
			if (!result.ok) {
				R.current().reason = R.errorText(result);
				return null;
			}
			context = result.data.context;
			(result.data.rows || []).forEach(function (row) {
				logEstimate(kind, row);
				if (row.counts) {
					Object.keys(row.counts).forEach(function (status) {
						totals.counts[status] = (totals.counts[status] || 0) + row.counts[status];
					});
				} else {
					totals.counts[row.status] = (totals.counts[row.status] || 0) + 1;
				}
				totals.chars += row.chars || 0;
				totals.cost += Number(row.cost) || 0;
			});
			var next = Math.max(1, result.data.next || 0);
			index += next;
			R.current().done += next;
			R.message(R.sprintf(t.estimating, R.current().done, R.current().total));
			R.render();
		}
		return context;
	}

	// Errors and skips of an estimate get a log row with their reason (CLI wording).
	function logEstimate(kind, row) {
		if (kind === 'terms') {
			if (row.message) {
				R.log({ text: R.sprintf(t.termsPair, row.taxonomy, row.target) }, row.target, 'error', row.message);
			}
		} else if (row.status === 'error' || (row.status.indexOf('skipped_') === 0 && row.status !== 'skipped_existing')) {
			R.log({ text: '#' + row.id, href: cfg.editUrl + row.id }, row.target, row.status, row.message);
		}
	}

	async function estimate() {
		var opts = readForm();
		if (R.current() || !validate(opts)) {
			return;
		}
		saveForm(opts);
		R.begin(opts);
		var work = await plan(opts);
		if (!work) {
			return;
		}
		var terms = { counts: {}, chars: 0, cost: 0 };
		var posts = { counts: {}, chars: 0, cost: 0 };
		var context = await estimateChunks('terms', work.terms, 20, {}, terms);
		if (context) {
			var records = work.records.map(function (rec) { return { id: rec.id, lang: rec.lang }; });
			context = await estimateChunks('records', records, 200, context, posts);
		}
		if (context && !R.current().stopping) {
			showEstimate(terms, posts);
			R.end(t.estimateDone + ': $' + (terms.cost + posts.cost).toFixed(4));
		} else {
			R.end();
		}
	}

	function showEstimate(terms, posts) {
		el.box.textContent = '';
		el.box.hidden = false;
		el.box.appendChild(R.node('h2', t.estimateDone, 'aipt-panel-title'));
		var table = R.node('table', null, 'widefat striped aipt-bulk-estimate');
		[[t.terms, terms], [t.posts, posts]].forEach(function (part) {
			var statuses = Object.keys(part[1].counts);
			if (!statuses.length) {
				return;
			}
			var head = R.node('tr');
			head.appendChild(R.node('th', part[0]));
			head.appendChild(R.node('th', t.chars + ': ' + part[1].chars.toLocaleString()));
			head.appendChild(R.node('th', t.cost + ': $' + part[1].cost.toFixed(4)));
			table.appendChild(head);
			statuses.forEach(function (status) {
				var row = R.node('tr');
				row.appendChild(R.node('td', R.label(status)));
				row.appendChild(R.node('td', String(part[1].counts[status])));
				row.appendChild(R.node('td', ''));
				table.appendChild(row);
			});
		});
		var total = R.node('tr');
		total.appendChild(R.node('th', t.total));
		total.appendChild(R.node('th', ''));
		total.appendChild(R.node('th', t.cost + ': $' + (terms.cost + posts.cost).toFixed(4)));
		table.appendChild(total);
		el.box.appendChild(table);
	}

	// ---- Needs manual check ---------------------------------------------------

	async function loadPending() {
		var result = await R.call('pending', {});
		el.pending.textContent = '';
		if (!result.ok) {
			el.pending.appendChild(R.node('p', result.message, 'aipt-error'));
			return;
		}
		var list = result.data.pending || [];
		if (!list.length) {
			el.pending.appendChild(R.node('p', t.noPending));
			return;
		}
		var table = R.node('table', null, 'widefat striped');
		list.forEach(function (entry) {
			var row = R.node('tr');
			var source = R.node('td');
			source.appendChild(R.link(entry.source_link || cfg.editUrl + entry.post_id, (entry.title || '') + ' #' + entry.post_id));
			row.appendChild(source);
			row.appendChild(R.node('td', entry.target));
			var state = entry.stage === 'finalizing' ? t.stageFinalizing
				: entry.stage === 'complete' ? R.sprintf(t.stageComplete, R.label(entry.outcome))
				: entry.target_changed ? t.targetChanged
				: R.sprintf(t.stageNone, entry.done, entry.total);
			if (entry.review && entry.stage !== 'complete') {
				state = R.label('review') + ': ' + entry.review;
			}
			if (!entry.mine && entry.owner) {
				state += ' (' + R.sprintf(t.otherUser, entry.owner) + ')';
			}
			row.appendChild(R.node('td', state));
			var target = R.node('td');
			if (entry.target_link) {
				target.appendChild(R.link(entry.target_link, t.openTarget + ' #' + entry.target_id));
			}
			row.appendChild(target);
			// Discard: any administrator (stale entries can always be cleared). Translate
			// anyway: only the user who started the job.
			var actions = R.node('td');
			if (entry.stage !== 'complete') {
				if (entry.mine) {
					actions.appendChild(button(t.translateAnyway, function () { translateAnyway(entry); }));
				}
				actions.appendChild(button(t.discard, function () {
					if (window.confirm(t.confirmDiscard)) {
						discard(entry).then(function (result) {
							if (!result.ok) {
								R.message(result.message);
							}
							loadPending();
						});
					}
				}));
			}
			row.appendChild(actions);
			table.appendChild(row);
		});
		el.pending.appendChild(table);
	}

	function button(text, onClick) {
		var b = R.node('button', text, 'button button-small');
		b.type = 'button';
		b.addEventListener('click', onClick);
		return b;
	}

	function discard(entry) {
		return R.call('discard', { job_id: entry.job_id, post_id: entry.post_id, target: entry.target });
	}

	// Discard the job, then translate this one record again in overwrite mode (after an
	// explicit confirmation), logging its result whatever it is.
	async function translateAnyway(entry) {
		if (R.current()) {
			R.message(t.busy);
			return;
		}
		if (!window.confirm(t.confirmAnyway)) {
			return;
		}
		var opts = readForm();
		// The record runs with the job's source language, not the form's From.
		opts.from = entry.from || opts.from;
		opts.mode = 'overwrite';
		opts.confirm = 1;
		R.begin(opts);
		var run = R.current();
		run.total = 1;
		run.logAll = true;
		var discarded = await discard(entry);
		if (!discarded.ok) {
			R.end(R.errorText(discarded));
			return;
		}
		await R.runRecords([{ id: entry.post_id, parent: 0, lang: entry.target }]);
		R.end();
	}

	// ---- Wiring ---------------------------------------------------------------

	restoreForm();
	R.syncTargets(form, el.from);
	setSince(getSince());
	loadTaxonomies();
	el.from.addEventListener('change', function () {
		R.syncTargets(form, el.from);
		loadTaxonomies();
	});
	el.newRun.addEventListener('click', function () {
		if (!R.current() && window.confirm(t.confirmNewRun)) {
			resetRun().then(function (result) {
				R.message(result.ok ? '' : result.message);
			});
		}
	});
	el.start.addEventListener('click', start);
	el.estimate.addEventListener('click', estimate);
	el.stop.addEventListener('click', function () { R.stop(''); });
	form.addEventListener('change', function () { saveForm(readForm()); });
	R.guardUnload(function () { return !!R.current(); });
	R.onEnd = loadPending;
	loadPending();
})();

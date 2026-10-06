// Bulk translation run engine (the page itself is assets/bulk.js): AJAX requests with
// the retry policy, the error policy shared with bulk-strings.js, the run state with its
// counters, progress and log, terms pairs one at a time, then records (prepare → batches
// → finalize) through a small worker pool. The server keeps the state (durable jobs and
// their locators); this tab only drives the requests. DOM and storage helpers:
// assets/bulk-ui.js (loaded first, starts window.aiptBulkRun).
(function () {
	'use strict';

	var cfg = window.aiptBulk;
	var api = window.aiptBulkRun;
	if (!cfg || !api) {
		return;
	}

	var t = cfg.i18n;
	var sprintf = api.sprintf, label = api.label, node = api.node;
	var MAX_CONSECUTIVE_ERRORS = 5;
	var BUSY_DELAY = 5000;
	// Busy retries (every BUSY_DELAY ms) cover twice the server's batch lock TTL: a batch
	// lock, or a terms or strings slice lock of two gateway batches.
	var BUSY_RETRIES = Math.ceil(2 * (Number(cfg.batchLockTtl) || 240) / 5) + 2;
	var NET_RETRIES = 5;
	var NET_DELAY = 3000;
	var AUTH_CODES = ['aipt_http_401', 'aipt_http_403'];
	// Errors no other record, terms pair or string domain can get past: the run stops at
	// once (aipt_forbidden is the guard's; a source the user may not edit is
	// aipt_source_forbidden, that record only).
	var FATAL_CODES = ['aipt_disabled', 'aipt_no_key', 'aipt_bad_nonce', 'aipt_forbidden', 'aipt_job_backend', 'aipt_sync_missing', 'aipt_strings_unsupported', 'aipt_wpml_api'];

	var el = {
		estimate: document.getElementById('aipt-bulk-estimate'),
		start: document.getElementById('aipt-bulk-start'),
		stop: document.getElementById('aipt-bulk-stop'),
		message: document.getElementById('aipt-bulk-message'),
		progress: document.getElementById('aipt-bulk-progress'),
		progressText: document.getElementById('aipt-bulk-progress-text'),
		skipped: document.getElementById('aipt-bulk-skipped'),
		current: document.getElementById('aipt-bulk-current'),
		log: document.getElementById('aipt-bulk-log')
	};
	var run = null;

	function sleep(ms) {
		return new Promise(function (resolve) { setTimeout(resolve, ms); });
	}

	function common(opts) {
		return { from: opts.from, mode: opts.mode, confirm: opts.confirm, publish: opts.publish, keep_date: opts.keep_date };
	}

	// ---- Requests -------------------------------------------------------------

	function send(action, params) {
		var data = new FormData();
		data.append('action', 'aipt_bulk_' + action);
		data.append('_ajax_nonce', cfg.nonce);
		Object.keys(params || {}).forEach(function (key) {
			var value = params[key];
			if (Array.isArray(value)) {
				value.forEach(function (item) { data.append(key + '[]', item); });
			} else {
				data.append(key, value);
			}
		});
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (response) { return response.json(); });
	}

	// One request with the retry policy: a network failure or a non-JSON reply (proxy
	// timeout, fatal error) is sent again after 3 s (5 times); a reply flagged retry
	// (block or record busy) after 5 s, retried while the server lock can still be held
	// (BUSY_RETRIES; also after Stop: Stop only prevents new records, the requests of
	// records in progress finish).
	// Resolves {ok, data} or {ok: false, code, message, usage, data}.
	async function call(action, params, onRetry) {
		var busy = 0, net = 0;
		for (;;) {
			var response;
			try {
				response = await send(action, params);
				if (!response || typeof response !== 'object') {
					throw new Error(String(response));
				}
			} catch (error) {
				if (net++ < NET_RETRIES) {
					if (onRetry) { onRetry(); }
					await sleep(NET_DELAY);
					continue;
				}
				return { ok: false, code: 'aipt_network', message: t.networkError + ': ' + error.message, usage: null, data: {} };
			}
			if (response.success) {
				return { ok: true, data: response.data || {} };
			}
			var data = response.data || {};
			if (data.retry && busy++ < BUSY_RETRIES) {
				if (onRetry) { onRetry(); }
				await sleep(BUSY_DELAY);
				continue;
			}
			return { ok: false, code: data.code || '', message: data.message || '', usage: data.aipt_usage || null, data: data };
		}
	}

	// "[code] message" of a failed call().
	function errorText(result) {
		return (result.code ? '[' + result.code + '] ' : '') + result.message;
	}

	function isAuthError(code) {
		return AUTH_CODES.indexOf(code) !== -1;
	}

	// What a run stops with after a failed call() (consecutive: failures in a row, this
	// one included), or '' to go on.
	function stopReason(result, consecutive) {
		if (isAuthError(result.code)) {
			return t.authError;
		}
		if (FATAL_CODES.indexOf(result.code) !== -1) {
			return sprintf(t.fatal, result.message);
		}
		return consecutive >= MAX_CONSECUTIVE_ERRORS ? t.tooManyErrors : '';
	}

	// ---- Run state ------------------------------------------------------------

	function beginRun(opts) {
		run = {
			opts: opts,
			common: common(opts),
			stopping: false,
			reason: '',
			total: 0,
			done: 0,
			started: Date.now(),
			consecutive: 0,
			c: { created: 0, updated: 0, terms: 0, warnings: 0, skipped: 0, check: 0, errors: 0, cost: 0, api: 0 },
			skippedBy: {},
			queue: [],
			// key(id, lang) of every record queued or in flight in this run.
			unfinished: {},
			inflightIds: {},
			waiters: []
		};
		el.log.textContent = '';
		el.current.textContent = '';
		el.estimate.disabled = true;
		el.start.disabled = true;
		el.stop.disabled = false;
		run.timer = setInterval(render, 1000);
		render();
	}

	function endRun(text) {
		if (!run) {
			return;
		}
		clearInterval(run.timer);
		render();
		message(text || run.reason || (run.stopping ? t.stopped : t.finished));
		el.current.textContent = '';
		el.estimate.disabled = false;
		el.start.disabled = false;
		el.stop.disabled = true;
		run = null;
		if (api.onEnd) {
			api.onEnd();
		}
	}

	function stop(reason) {
		if (!run) {
			return;
		}
		if (reason && !run.reason) {
			run.reason = reason;
		}
		if (!run.stopping) {
			run.stopping = true;
			message(reason || t.stopping);
		}
	}

	function message(text) {
		el.message.textContent = text || '';
	}

	function elapsed(ms) {
		var s = Math.floor(ms / 1000), h = Math.floor(s / 3600), m = Math.floor(s / 60) % 60;
		s %= 60;
		return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s;
	}

	function render() {
		if (!run) {
			return;
		}
		Object.keys(run.c).forEach(function (key) {
			var target = document.querySelector('[data-counter="' + key + '"]');
			if (target) {
				target.textContent = key === 'cost' ? '$' + run.c.cost.toFixed(4) : String(run.c[key]);
			}
		});
		document.querySelector('[data-counter="elapsed"]').textContent = elapsed(Date.now() - run.started);
		el.progress.max = Math.max(1, run.total);
		el.progress.value = run.done;
		el.progressText.textContent = run.total ? run.done + ' / ' + run.total : '';
		el.skipped.textContent = Object.keys(run.skippedBy).map(function (status) {
			return label(status) + ': ' + run.skippedBy[status];
		}).join(', ');
	}

	function addCost(cost, apiCalls) {
		run.c.cost += Number(cost) || 0;
		run.c.api += apiCalls || 0;
		if (capReached()) {
			stop(t.costLimit);
		}
	}

	function capReached() {
		return !!(run.opts.limit && run.c.cost >= run.opts.limit);
	}

	function skip(status, count) {
		count = count === undefined ? 1 : count;
		run.c.skipped += count;
		run.skippedBy[status] = (run.skippedBy[status] || 0) + count;
	}

	function current(text) {
		var item = node('li', text);
		el.current.appendChild(item);
		return {
			set: function (state) { item.textContent = text + ': ' + state; },
			remove: function () { item.remove(); }
		};
	}

	// Row of the run's log (api.logRow).
	function log(who, lang, status, text, links) {
		api.logRow(el.log, who, lang, status, text, links);
	}

	function recordWho(rec) {
		return { text: (rec.title || '') + ' #' + rec.id, href: cfg.editUrl + rec.id };
	}

	function fail(who, lang, result) {
		run.c.errors++;
		run.consecutive++;
		if (result.usage) {
			addCost(result.usage.cost, result.usage.api_calls);
		}
		if (result.data && result.data.stage === 'finalizing') {
			run.c.check++;
		}
		log(who, lang, 'error', errorText(result));
		var reason = stopReason(result, run.consecutive);
		if (reason) {
			stop(reason);
		}
	}

	// ---- Terms ----------------------------------------------------------------

	// Each pair runs in short slices (offset → next) until the server says it is done.
	async function runTerms(pairs) {
		for (var i = 0; i < pairs.length; i++) {
			var taxonomy = pairs[i][0], lang = pairs[i][1];
			var who = { text: sprintf(t.termsPair, taxonomy, lang) };
			var item = current(who.text);
			var offset = 0;
			while (offset !== null && !run.stopping && !capReached()) {
				var params = { taxonomy: taxonomy, target: lang, from: run.common.from, offset: offset };
				var result = await call('terms', params, function () { item.set(t.retrying); });
				if (!result.ok) {
					fail(who, lang, result);
					break;
				}
				offset = result.data.next ?? null;
				termRows(taxonomy, lang, result.data);
				if (offset !== null) {
					item.set(sprintf(t.termsLeft, result.data.remaining));
				}
			}
			item.remove();
			run.done++;
			if (run.stopping || capReached()) {
				return;
			}
		}
	}

	function termRows(taxonomy, lang, data) {
		addCost(data.cost, 0);
		if (data.existing) {
			skip('skipped_existing', data.existing);
		}
		var errors = 0;
		(data.rows || []).forEach(function (row) {
			var termWho = { text: taxonomy + ': ' + row.name + ' #' + row.term_id };
			if (row.status === 'created' || row.status === 'linked') {
				run.c.terms++;
			} else if (row.status === 'error') {
				errors++;
				run.c.errors++;
				log(termWho, lang, 'error', row.message);
			} else {
				skip(row.status);
				if (row.status !== 'skipped_existing') {
					log(termWho, lang, row.status, row.message);
				}
			}
		});
		run.consecutive = errors ? run.consecutive + 1 : 0;
		if (isAuthError(data.error_code)) {
			stop(t.authError);
		} else if (run.consecutive >= MAX_CONSECUTIVE_ERRORS) {
			stop(t.tooManyErrors);
		}
	}

	// ---- Records --------------------------------------------------------------

	function key(id, lang) {
		return id + '|' + lang;
	}

	// The next record that may start: never two languages of one source at once (the
	// multilingual plugin saves the translation group of both), never a child (any type
	// with a post_parent) while its parent is queued or in flight in this run (parent is 0
	// for a post without one).
	function nextRecord() {
		for (var i = 0; i < run.queue.length; i++) {
			var rec = run.queue[i];
			var parentOpen = rec.parent && run.unfinished[key(rec.parent, rec.lang)];
			if (!run.inflightIds[rec.id] && !parentOpen) {
				return run.queue.splice(i, 1)[0];
			}
		}
		return null;
	}

	function changed() {
		var waiters = run.waiters;
		run.waiters = [];
		waiters.forEach(function (resolve) { resolve(); });
	}

	async function worker() {
		while (run.queue.length && !run.stopping && !capReached()) {
			var rec = nextRecord();
			if (!rec) {
				if (!Object.keys(run.inflightIds).length) {
					rec = run.queue.shift();
				} else {
					await new Promise(function (resolve) { run.waiters.push(resolve); });
					continue;
				}
			}
			run.inflightIds[rec.id] = true;
			try {
				await processRecord(rec);
			} catch (error) {
				fail(recordWho(rec), rec.lang, { code: '', message: String(error), usage: null, data: {} });
			} finally {
				delete run.unfinished[key(rec.id, rec.lang)];
				delete run.inflightIds[rec.id];
				run.done++;
				changed();
			}
		}
	}

	async function runRecords(records) {
		run.queue = records.slice();
		records.forEach(function (rec) { run.unfinished[key(rec.id, rec.lang)] = true; });
		var workers = [];
		for (var i = 0; i < run.opts.streams; i++) {
			workers.push(worker());
		}
		await Promise.all(workers);
	}

	async function processRecord(rec) {
		var item = current('#' + rec.id + ' → ' + rec.lang);
		var retry = function () { item.set(t.retrying); };
		try {
			item.set(t.preparing);
			var ids = { post_id: rec.id, target: rec.lang };
			var params = Object.assign({ since: run.since || 0 }, ids, run.common);
			var prepared = await call('prepare', params, retry);
			if (!prepared.ok) {
				fail(recordWho(rec), rec.lang, prepared);
				return;
			}
			var job = prepared.data;
			rec.title = job.title || '';
			if (job.status !== 'prepared' && job.status !== 'reuse') {
				outcome(rec, job);
				return;
			}
			// Batches after next_index that are already saved come back as reused (free).
			for (var i = job.next_index; i < job.total; i++) {
				// Stop and the cost limit leave the job for the next Start; a record whose
				// blocks are all translated is still saved (free).
				if (run.stopping || capReached()) {
					log(recordWho(rec), rec.lang, 'interrupted', t.interrupted);
					return;
				}
				item.set(sprintf(t.block, i + 1, job.total));
				var batch = await call('batch', Object.assign({ job_id: job.job_id, index: i }, ids), retry);
				if (!batch.ok) {
					fail(recordWho(rec), rec.lang, batch);
					return;
				}
				// The job was finished or half-written elsewhere: its outcome or needs_check.
				if (batch.data.status) {
					outcome(rec, batch.data);
					return;
				}
				if (!batch.data.reused) {
					addCost(batch.data.usage ? batch.data.usage.cost : 0, 1);
				}
			}
			item.set(t.saving);
			var finalized = await call('finalize', Object.assign({ job_id: job.job_id }, ids), retry);
			if (!finalized.ok) {
				fail(recordWho(rec), rec.lang, finalized);
				return;
			}
			outcome(rec, finalized.data);
		} finally {
			item.remove();
		}
	}

	// Final statuses of a record: the counter, the log text and link fields, and whether
	// it is logged only in a single-record run (logAll). Any other status is a skip.
	var OUTCOMES = {
		created: { counter: 'created', quiet: true }, updated: { counter: 'updated', quiet: true },
		created_with_warning: { counter: 'created', warning: true }, updated_with_warning: { counter: 'updated', warning: true },
		needs_check: { counter: 'check', link: 'target_link' }, review: { counter: 'check' },
		skipped_existing: { quiet: true }, skipped_done: { quiet: true }
	};

	function outcome(rec, data) {
		var status = data.status || '';
		var kind = OUTCOMES[status] || {};
		run.consecutive = 0;
		if (kind.counter) {
			run.c[kind.counter]++;
		} else {
			skip(status);
		}
		if (kind.warning) {
			run.c.warnings++;
		}
		if (!kind.quiet || run.logAll) {
			log(recordWho(rec), rec.lang, status, kind.warning ? data.warning : data.message, [{ href: data[kind.link || 'edit_link'], text: t.openTarget }]);
		}
	}

	Object.assign(api, {
		call: call,
		errorText: errorText,
		isAuthError: isAuthError,
		stopReason: stopReason,
		begin: beginRun,
		end: endRun,
		stop: stop,
		message: message,
		render: render,
		log: log,
		runTerms: runTerms,
		runRecords: runRecords,
		// The run in progress (mutable), or null.
		current: function () { return run; },
		// Called after every run, estimate included.
		onEnd: null
	});
})();

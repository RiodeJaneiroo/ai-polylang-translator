// Small DOM and storage helpers of the bulk translation page, loaded first: they start
// window.aiptBulkRun, which the run engine (assets/bulk-run.js) completes and bulk.js
// and bulk-strings.js use.
(function () {
	'use strict';

	var cfg = window.aiptBulk;
	if (!cfg) {
		return;
	}

	var t = cfg.i18n;

	function sprintf(format) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(format).replace(/%(\d+)\$[ds]|%[ds]/g, function (m, pos) {
			return String(pos ? args[pos - 1] : args[i++]);
		});
	}

	function label(status) {
		return t.status[status] || status;
	}

	function node(tag, text, className) {
		var element = document.createElement(tag);
		if (text !== undefined && text !== null) {
			element.textContent = text;
		}
		if (className) {
			element.className = className;
		}
		return element;
	}

	function link(href, text) {
		var a = node('a', text);
		a.href = href;
		a.target = '_blank';
		a.rel = 'noopener';
		return a;
	}

	// Log row (newest first) for anything that is not a plain success. who: a string, or
	// {text, href} (href: shown as a link); links: [{href, text}] after the message.
	function logRow(tbody, who, lang, status, text, links) {
		var row = node('tr');
		row.appendChild(node('td', new Date().toLocaleTimeString()));
		var cell = node('td');
		if (typeof who === 'string') {
			cell.textContent = who;
		} else {
			cell.appendChild(who.href ? link(who.href, who.text) : node('span', who.text));
		}
		row.appendChild(cell);
		row.appendChild(node('td', lang));
		row.appendChild(node('td', label(status), status === 'error' ? 'aipt-error' : ''));
		var msg = node('td', text || '');
		(links || []).forEach(function (item) {
			if (item.href) {
				msg.appendChild(document.createTextNode(' '));
				msg.appendChild(link(item.href, item.text));
			}
		});
		row.appendChild(msg);
		tbody.insertBefore(row, tbody.firstChild);
	}

	// The source language cannot be a target.
	function syncTargets(form, fromSelect) {
		form.querySelectorAll('input[name="targets[]"]').forEach(function (input) {
			var same = input.value === fromSelect.value;
			input.disabled = same;
			if (same) {
				input.checked = false;
			}
		});
	}

	// A JSON value in localStorage (a convenience: unavailable storage reads as null and
	// ignores writes). set(null) removes the key.
	function store(key) {
		return {
			get: function () {
				try {
					return JSON.parse(localStorage.getItem(key) || 'null');
				} catch (e) {
					return null;
				}
			},
			set: function (value) {
				try {
					if (value === null || value === undefined) {
						localStorage.removeItem(key);
					} else {
						localStorage.setItem(key, JSON.stringify(value));
					}
				} catch (e) {}
			}
		};
	}

	// Ask before leaving the page while isBusy() is true.
	function guardUnload(isBusy) {
		window.addEventListener('beforeunload', function (event) {
			if (isBusy()) {
				event.preventDefault();
				event.returnValue = '';
			}
		});
	}

	window.aiptBulkRun = {
		sprintf: sprintf,
		label: label,
		node: node,
		link: link,
		logRow: logRow,
		syncTargets: syncTargets,
		store: store,
		guardUnload: guardUnload
	};
})();

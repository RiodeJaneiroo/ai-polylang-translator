// Settings page: "Test key" button.
(function () {
	'use strict';

	var cfg = window.aiptSettings;
	var button = document.getElementById('aipt-test-key');
	if (!cfg || !button) {
		return;
	}

	button.addEventListener('click', function () {
		var result = document.getElementById('aipt-test-result');
		var keyInput = document.getElementById('aipt-api-key');
		var savedIcon = document.getElementById('aipt-key-saved');

		result.textContent = cfg.i18n.testing;
		result.className = '';
		button.disabled = true;

		var data = new FormData();
		data.append('action', 'aipt_test_key');
		data.append('_ajax_nonce', cfg.nonce);
		data.append('key', keyInput ? keyInput.value : '');

		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (response) { return response.json(); })
			.then(function (response) {
				if (response.success) {
					result.textContent = cfg.i18n.ok;
					result.className = 'aipt-ok';
					if (savedIcon) {
						savedIcon.hidden = false;
					}
				} else {
					result.textContent = (response.data && response.data.message) || 'Error';
					result.className = 'aipt-error';
				}
			})
			.catch(function (error) {
				result.textContent = String(error);
				result.className = 'aipt-error';
			})
			.finally(function () {
				button.disabled = false;
			});
	});
})();

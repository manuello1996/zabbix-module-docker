<?php declare(strict_types = 0);
?>
<script>
window.monzphere_docker_list = new class {
	init({refresh_interval}) {
		this._interval = refresh_interval;
		this._timer = null;
		this._last_refresh = Date.now();
		this._failures = 0;
		this._status = document.getElementById('mnz-docker-list-refresh-status');

		if (!this._interval) {
			return;
		}

		this._schedule();
		this._updateStatus();

		setInterval(() => this._updateStatus(), 1000);
	}

	_updateStatus() {
		if (this._status === null) {
			return;
		}

		if (this._failures >= 3) {
			this._status.textContent = <?= json_encode(_('Update failed. Retrying...')) ?>;

			return;
		}

		const seconds = Math.max(0, Math.round((Date.now() - this._last_refresh) / 1000));
		const age = seconds < 60 ? seconds + 's' : Math.floor(seconds / 60) + 'm';

		this._status.textContent = <?= json_encode(_('Updated %1$s ago')) ?>.replace('%1$s', age);
	}

	_schedule() {
		clearTimeout(this._timer);

		this._timer = setTimeout(() => this._reload(), this._interval * 1000);
	}

	_reload() {
		const url = new Curl('zabbix.php');

		url.setArgument('action', 'monzphere.docker.list');

		const page = new URLSearchParams(location.search).get('page');

		if (page !== null) {
			url.setArgument('page', page);
		}

		fetch(url.getUrl(), {cache: 'no-store'})
			.then((response) => {
				if (!response.ok) {
					throw new Error();
				}

				return response.text();
			})
			.then((html) => {
				const doc = new DOMParser().parseFromString(html, 'text/html');
				const cards = doc.getElementById('mnz-docker-list-cards');
				const tbody = doc.querySelector('#mnz-docker-nodes-table tbody');

				if (cards === null || tbody === null) {
					throw new Error();
				}

				document.getElementById('mnz-docker-list-cards').replaceWith(cards);
				document.querySelector('#mnz-docker-nodes-table tbody').replaceWith(tbody);

				this._failures = 0;
				this._last_refresh = Date.now();
				this._updateStatus();
			})
			.catch(() => {
				this._failures++;
				this._updateStatus();
			})
			.finally(() => this._schedule());
	}
};

document.addEventListener('DOMContentLoaded', () => {
	monzphere_docker_list.init({refresh_interval: <?= json_encode($data['refresh_interval'] ?? 0) ?>});
});
</script>

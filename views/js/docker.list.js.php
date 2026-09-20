<?php declare(strict_types = 0);
?>
<script type="text/x-jquery-tmpl" id="filter-tag-row-tmpl">
	<?= CTagFilterFieldHelper::getTemplate(); ?>
</script>
<script>
window.monitor_docker_list = new class {
	init({refresh_interval}) {
		this._interval = refresh_interval;
		this._timer = null;
		this._last_refresh = Date.now();
		this._failures = 0;
		this._status = document.getElementById('docker-list-refresh-status');
		this._problem_request = null;

		this._loadProblems();
		this._initTagFilter();

		if (!this._interval) {
			return;
		}

		this._schedule();
		this._updateStatus();

		setInterval(() => this._updateStatus(), 1000);
	}

	_initTagFilter() {
		if (typeof CTagFilterItem === 'undefined' || typeof $ === 'undefined'
				|| typeof $.fn.dynamicRows === 'undefined') {
			return;
		}

		$('#filter-tags')
			.dynamicRows({template: '#filter-tag-row-tmpl'})
			.on('afteradd.dynamicRows', function () {
				const rows = this.querySelectorAll('.form_row');

				new CTagFilterItem(rows[rows.length - 1]);
			});

		document.querySelectorAll('#filter-tags .form_row').forEach((row) => new CTagFilterItem(row));
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

	_loadProblems() {
		const targets = [...document.querySelectorAll('[data-problem-hostid]')];
		const hostids = [...new Set(targets.map((target) => target.dataset.problemHostid))];

		if (hostids.length === 0) {
			return;
		}

		const url = new Curl('zabbix.php');

		url.setArgument('action', 'docker.problems');
		url.setArgument('hostids', hostids);

		const request = fetch(url.getUrl(), {cache: 'no-store'})
			.then((response) => response.json())
			.then((response) => {
				if ('error' in response || request !== this._problem_request) {
					throw new Error();
				}

				for (const target of targets) {
					const badges = response.hosts?.[target.dataset.problemHostid] ?? [];

					if (badges.length === 0) {
						const empty = document.createElement('span');

						empty.className = 'docker-muted';
						empty.textContent = '-';
						target.replaceWith(empty);

						continue;
					}

					target.replaceChildren();
					target.removeAttribute('aria-label');

					for (const badge of badges) {
						const span = document.createElement('span');

						span.className = 'problem-icon-list-item ' + badge.style;
						span.title = badge.title;
						span.textContent = badge.count;
						target.append(span);
					}
				}
			})
			.catch(() => {
				if (request !== this._problem_request) {
					return;
				}

				for (const target of targets) {
					const empty = document.createElement('span');

					empty.className = 'docker-muted';
					empty.textContent = '-';
					target.replaceWith(empty);
				}
			});

		this._problem_request = request;
	}

	_reload() {
		const url = new Curl('zabbix.php');

		url.setArgument('action', 'docker.list');

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
				const cards = doc.getElementById('docker-list-cards');
				const tbody = doc.querySelector('#docker-nodes-table tbody');
				const paging = doc.getElementById('docker-list-paging');

				if (cards === null || tbody === null || paging === null) {
					throw new Error();
				}

				document.getElementById('docker-list-cards').replaceWith(cards);
				document.querySelector('#docker-nodes-table tbody').replaceWith(tbody);
				document.getElementById('docker-list-paging').replaceWith(paging);
				this._loadProblems();

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
	monitor_docker_list.init({refresh_interval: <?= json_encode($data['refresh_interval'] ?? 0) ?>});
});
</script>

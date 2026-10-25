<?php declare(strict_types = 0);
?>
<script type="text/x-jquery-tmpl" id="filter-tag-row-tmpl">
	<?= CTagFilterFieldHelper::getTemplate(); ?>
</script>
<script>
window.monitor_docker_list = new class {
	init({refresh_interval}) {
		this._reloadRequest = null;
		this._problem_request = null;
		this._autoRefresh = new window.DockerCommon.AutoRefresh({
			control: document.getElementById('docker-list-auto-refresh'), interval: refresh_interval,
			status: document.getElementById('docker-list-refresh-status'),
			onRefresh: () => this._reload(), isBusy: () => this._reloadRequest !== null
		});
		this._autoRefresh.updated();
		this._loadProblems();
		this._initTagFilter();
		const refresh = document.getElementById('docker-list-refresh');
		refresh?.addEventListener('click', () => {
			if (this._reloadRequest !== null) return;
			refresh.disabled = true;
			this._reload().finally(() => { refresh.disabled = false; });
		});
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

					window.DockerCommon.ProblemBadges.render(target, badges, {
						emptyText: <?= json_encode(_('None')) ?>
					});
				}
			})
			.catch(() => {
				if (request !== this._problem_request) {
					return;
				}

				for (const target of targets) window.DockerCommon.ProblemBadges.render(target, [], {
					available: false, unavailableText: <?= json_encode(_('Unavailable')) ?>
				});
			});

		this._problem_request = request;
	}

	_reload() {
		if (this._reloadRequest !== null) return this._reloadRequest;
		this._autoRefresh.pause();
		this._autoRefresh.setStatus(<?= json_encode(_('Loading Docker data...')) ?>);

		const url = new Curl(location.href);

		url.setArgument('action', 'docker.list');

		const page = new URLSearchParams(location.search).get('page');

		if (page !== null) {
			url.setArgument('page', page);
		}

		const request = fetch(url.getUrl(), {cache: 'no-store'})
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

				this._autoRefresh.updated();
			})
			.catch(() => {
				this._autoRefresh.setStatus(<?= json_encode(_('Unable to refresh Docker data. Showing the last loaded values.')) ?>);
			})
			.finally(() => {
				this._reloadRequest = null;
				this._autoRefresh.resume();
			});
		this._reloadRequest = request;
		return request;
	}
};

document.addEventListener('DOMContentLoaded', () => {
	monitor_docker_list.init({refresh_interval: <?= json_encode($data['refresh_interval'] ?? 0) ?>});
});
</script>

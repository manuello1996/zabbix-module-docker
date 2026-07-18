<?php declare(strict_types = 0);
?>
<script>
window.monzphere_docker = new class {
	init({hostid, refresh_interval, active_tab}) {
		this._hostid = hostid;
		this._refresh_interval = refresh_interval;
		this._active_tab = active_tab ?? '';
		this._timer = null;
		this._panel = null;
		this._content = null;
		this._tab_cache = new Map();
		this._tab_pages = new Map();
		this._last_refresh = null;
		this._refresh_failures = 0;
		this._refresh_status = null;
		this._table_state = {search: '', chip: 'all', sort: null, dir: 1};
		this._search_debounce = null;
		this._modal = null;
		this._modal_trigger = null;
		this._modal_keydown = null;
		this._modal_seq = 0;

		this._initTabs();
		this._initTableControls();
		this._initContainerModal();

		jQuery.subscribe('timeselector.rangeupdate', () => {
			this._tab_cache.delete('graphs');

			const active = document.querySelector('.mnz-docker-tab-active');

			if (this._panel !== null && active !== null && active.dataset.mnzTab === 'graphs') {
				this._loadTab('graphs');
			}
		});

		this._initRefreshStatus();

		this._applyTableState();

		if (this._active_tab !== '') {
			document.querySelector(`.mnz-docker-tab[data-mnz-tab="${CSS.escape(this._active_tab)}"]`)?.click();
		}

		this._scheduleRefresh();
	}

	_initTabs() {
		const content = document.getElementById('mnz-docker-content');
		const panel = document.getElementById('mnz-docker-panel');

		if (content === null || panel === null) {
			return;
		}

		this._content = content;
		this._panel = panel;

		panel.addEventListener('click', (e) => {
			const page_link = e.target.closest('.<?= defined('ZBX_STYLE_PAGER_CONTAINER') ? ZBX_STYLE_PAGER_CONTAINER : ZBX_STYLE_TABLE_PAGING ?> a[href]');

			if (page_link === null || !panel.contains(page_link)) {
				return;
			}

			e.preventDefault();

			const active = document.querySelector('.mnz-docker-tab-active');

			if (active === null || active.dataset.mnzTab === '') {
				return;
			}

			const key = active.dataset.mnzTab;
			const page = new URL(page_link.href, location.origin).searchParams.get('page') ?? '1';

			this._tab_pages.set(key, page);
			this._tab_cache.delete(key);
			this._loadTab(key);
		});

		for (const tab of document.querySelectorAll('.mnz-docker-tab[data-mnz-tab]')) {
			tab.addEventListener('click', () => {
				document.querySelectorAll('.mnz-docker-tab').forEach((node) => {
					node.classList.remove('mnz-docker-tab-active');
					node.setAttribute('aria-selected', 'false');
				});

				tab.classList.add('mnz-docker-tab-active');
				tab.setAttribute('aria-selected', 'true');

				const key = tab.dataset.mnzTab;

				if (key === '') {
					panel.hidden = true;
					content.hidden = false;

					const url = new Curl('zabbix.php');

					url.setArgument('action', 'monzphere.docker.tab');
					url.setArgument('hostid', this._hostid);
					url.setArgument('tab', 'docker');

					fetch(url.getUrl()).catch(() => {});

					return;
				}

				content.hidden = true;
				panel.hidden = false;

				this._loadTab(key);
			});
		}
	}

	_isActiveTab(key) {
		const active = document.querySelector('.mnz-docker-tab-active');

		return active !== null && active.dataset.mnzTab === key;
	}

	_loadTab(key) {
		const panel = this._panel;

		if (!this._tab_cache.has(key)) {
			panel.innerHTML = '<div class="mnz-docker-loading">'
				+ <?= json_encode(_('Loading...')) ?> + '</div>';

			const url = new Curl('zabbix.php');

			url.setArgument('action', 'monzphere.docker.tab');
			url.setArgument('hostid', this._hostid);
			url.setArgument('tab', key);
			url.setArgument('page', this._tab_pages.get(key) ?? '1');

			this._tab_cache.set(key,
				fetch(url.getUrl())
					.then((response) => response.json())
					.then((response) => {
						if ('error' in response) {
							throw new Error(response.error.title ?? '');
						}

						return response.html;
					})
			);
		}

		Promise.resolve(this._tab_cache.get(key))
			.then((html) => {
				if (key === 'graphs' || key === 'problems') {
					this._tab_cache.delete(key);
				}

				if (this._isActiveTab(key)) {
					panel.innerHTML = html;
				}
			})
			.catch(() => {
				this._tab_cache.delete(key);

				if (this._isActiveTab(key)) {
					this._showError(panel, () => this._loadTab(key));
				}
			});
	}

	_showError(target, retry_fn) {
		const message = document.createElement('div');

		message.className = 'mnz-docker-loading';
		message.append(<?= json_encode(_('Failed to load data.')) ?> + ' ');

		const retry = document.createElement('a');

		retry.href = 'javascript:void(0)';
		retry.classList.add('link-action');
		retry.textContent = <?= json_encode(_('Retry')) ?>;
		retry.addEventListener('click', () => retry_fn());

		message.append(retry);

		target.innerHTML = '';
		target.append(message);
	}

	_initTableControls() {
		const search = document.getElementById('mnz-docker-search');

		if (search !== null) {
			search.addEventListener('input', () => {
				clearTimeout(this._search_debounce);

				this._search_debounce = setTimeout(() => {
					this._table_state.search = search.value.trim().toLowerCase();
					this._applyTableState();
				}, 250);
			});
		}

		for (const chip of document.querySelectorAll('.mnz-docker-chip')) {
			chip.addEventListener('click', () => {
				this._table_state.chip = chip.dataset.mnzChip;

				document.querySelectorAll('.mnz-docker-chip').forEach((node) => {
					const active = node === chip;

					node.classList.toggle('mnz-docker-chip-active', active);
					node.setAttribute('aria-pressed', active ? 'true' : 'false');
				});

				this._applyTableState();
			});
		}

		for (const header of document.querySelectorAll('.mnz-docker-sort')) {
			const toggle = () => {
				const key = header.dataset.mnzSort;

				if (this._table_state.sort === key) {
					this._table_state.dir = -this._table_state.dir;
				}
				else {
					this._table_state.sort = key;
					this._table_state.dir = 1;
				}

				this._applyTableState();
			};

			header.addEventListener('click', toggle);
			header.addEventListener('keydown', (e) => {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					toggle();
				}
			});
		}
	}

	_applyTableState() {
		const table = document.getElementById('mnz-docker-table');

		if (table === null) {
			return;
		}

		const tbody = table.querySelector('tbody');

		const rows = [...tbody.querySelectorAll('tr')].filter((row) => row.dataset.mnzName !== undefined);

		const counts = {all: rows.length, running: 0, stopped: 0};

		for (const row of rows) {
			counts[row.dataset.mnzStatus] = (counts[row.dataset.mnzStatus] ?? 0) + 1;
		}

		for (const chip of document.querySelectorAll('.mnz-docker-chip')) {
			const count_node = chip.querySelector('.mnz-docker-chip-count');

			if (count_node !== null) {
				count_node.textContent = '(' + (counts[chip.dataset.mnzChip] ?? 0) + ')';
			}
		}

		const {search, chip, sort, dir} = this._table_state;
		let visible = 0;

		for (const row of rows) {
			const match_search = search === '' || row.dataset.mnzName.includes(search);
			const match_chip = chip === 'all' || row.dataset.mnzStatus === chip;

			row.hidden = !(match_search && match_chip);
			visible += row.hidden ? 0 : 1;
		}

		if (sort !== null) {
			const dataset_key = 'mnz' + sort.charAt(0).toUpperCase() + sort.slice(1);

			const sorted = [...rows].sort((a, b) => sort === 'name'
				? dir * a.dataset.mnzName.localeCompare(b.dataset.mnzName)
				: dir * ((parseFloat(a.dataset[dataset_key]) || 0) - (parseFloat(b.dataset[dataset_key]) || 0))
			);

			tbody.append(...sorted);
		}

		for (const header of document.querySelectorAll('.mnz-docker-sort')) {
			const arrow = header.querySelector('.mnz-docker-sort-arrow');
			const th = header.closest('th');

			if (arrow !== null) {
				arrow.innerHTML = '';
			}

			if (sort !== null && header.dataset.mnzSort === sort) {
				if (arrow !== null) {
					const icon = document.createElement('span');

					icon.className = dir === 1 ? 'arrow-up' : 'arrow-down';
					arrow.append(icon);
				}

				th?.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
			}
			else {
				th?.removeAttribute('aria-sort');
			}
		}

		const stats = document.getElementById('mnz-docker-table-stats');

		if (stats !== null) {
			stats.textContent = <?= json_encode(_('Displaying %1$s of %2$s found')) ?>
				.replace('%1$s', visible)
				.replace('%2$s', rows.length);
		}
	}

	_initContainerModal() {
		const table = document.getElementById('mnz-docker-table');

		if (table === null) {
			return;
		}

		table.addEventListener('click', (e) => {
			const link = e.target.closest('[data-mnz-container]');

			if (link !== null) {
				e.preventDefault();
				this._openContainerModal(link.dataset.mnzContainer, link);
			}
		});
	}

	_ensureModal() {
		if (this._modal !== null) {
			return this._modal;
		}

		const backdrop = document.getElementById('mnz-docker-modal');

		if (backdrop === null) {
			return null;
		}

		this._modal = {
			backdrop,
			dialog: backdrop.querySelector('.mnz-docker-modal'),
			title: document.getElementById('mnz-docker-modal-title'),
			body: backdrop.querySelector('.mnz-docker-modal-body')
		};

		backdrop.querySelector('.mnz-docker-modal-close')
			.addEventListener('click', () => this._closeModal());

		backdrop.addEventListener('click', (e) => {
			if (e.target === backdrop) {
				this._closeModal();
			}
		});

		return this._modal;
	}

	_openContainerModal(name, trigger) {
		const modal = this._ensureModal();

		if (modal === null) {
			return;
		}

		this._modal_trigger = trigger;
		modal.title.textContent = name;
		modal.backdrop.hidden = false;
		modal.dialog.focus();

		if (this._modal_keydown === null) {
			this._modal_keydown = (e) => {
				if (e.key === 'Escape') {
					this._closeModal();
				}
			};

			document.addEventListener('keydown', this._modal_keydown);
		}

		this._loadContainer(name);
	}

	_loadContainer(name) {
		const modal = this._modal;
		const seq = ++this._modal_seq;

		modal.body.innerHTML = '<div class="mnz-docker-loading">'
			+ <?= json_encode(_('Loading...')) ?> + '</div>';

		const url = new Curl('zabbix.php');

		url.setArgument('action', 'monzphere.docker.container');
		url.setArgument('hostid', this._hostid);
		url.setArgument('name', name);

		fetch(url.getUrl())
			.then((response) => response.json())
			.then((response) => {
				if ('error' in response) {
					throw new Error();
				}

				if (seq === this._modal_seq && !modal.backdrop.hidden) {
					modal.body.innerHTML = response.html;
				}
			})
			.catch(() => {
				if (seq === this._modal_seq && !modal.backdrop.hidden) {
					this._showError(modal.body, () => this._loadContainer(name));
				}
			});
	}

	_closeModal() {
		if (this._modal === null || this._modal.backdrop.hidden) {
			return;
		}

		this._modal.backdrop.hidden = true;

		if (this._modal_keydown !== null) {
			document.removeEventListener('keydown', this._modal_keydown);
			this._modal_keydown = null;
		}

		this._modal_trigger?.focus();
		this._modal_trigger = null;
	}

	_initRefreshStatus() {
		if (!this._refresh_interval || this._hostid === '') {
			return;
		}

		const table = document.getElementById('mnz-docker-table');
		const title = table !== null
			? table.closest('.mnz-docker-section')?.querySelector('.mnz-docker-section-title')
			: null;

		if (title == null) {
			return;
		}

		this._refresh_status = document.createElement('span');
		this._refresh_status.className = 'mnz-docker-refresh-status';
		this._refresh_status.style.marginLeft = '8px';
		this._refresh_status.style.fontSize = '0.85em';
		this._refresh_status.style.fontWeight = 'normal';
		this._refresh_status.style.opacity = '0.7';

		title.append(this._refresh_status);

		setInterval(() => this._updateRefreshStatus(), 1000);
	}

	_updateRefreshStatus() {
		if (this._refresh_status === null) {
			return;
		}

		if (this._refresh_failures >= 3) {
			this._refresh_status.textContent = <?= json_encode(_('Update failed. Retrying...')) ?>;

			return;
		}

		if (this._last_refresh === null) {
			return;
		}

		const seconds = Math.max(0, Math.round((Date.now() - this._last_refresh) / 1000));
		const age = seconds < 60 ? seconds + 's' : Math.floor(seconds / 60) + 'm';

		this._refresh_status.textContent = <?= json_encode(_('Updated %1$s ago')) ?>.replace('%1$s', age);
	}

	_registerRefreshFailure() {
		this._refresh_failures++;
		this._updateRefreshStatus();
	}

	_scheduleRefresh() {
		if (!this._refresh_interval || this._hostid === '') {
			return;
		}

		clearTimeout(this._timer);

		this._timer = setTimeout(() => this._refresh(), this._refresh_interval * 1000);
	}

	_refresh() {
		const url = new Curl('zabbix.php');

		url.setArgument('action', 'monzphere.docker.refresh');

		fetch(url.getUrl(), {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify({
				hostid: this._hostid,
				[CSRF_TOKEN_NAME]: <?= json_encode(CCsrfTokenHelper::get('monzphere.docker.refresh')) ?>
			})
		})
			.then((response) => response.json())
			.then((response) => {
				if ('error' in response) {
					this._registerRefreshFailure();

					return;
				}

				this._refresh_failures = 0;
				this._last_refresh = Date.now();
				this._updateRefreshStatus();

				this._updateOverview(response.overview);
				this._updateTable(response.containers);
			})
			.catch(() => {
				this._registerRefreshFailure();
			})
			.finally(() => this._scheduleRefresh());
	}

	_updateOverview(overview) {
		const [memory_value, memory_unit] = overview.memory_total.split(' ');

		const cards = {
			total: {value: overview.total},
			running: {value: overview.running},
			stopped: {value: overview.stopped},
			cpu: {value: overview.cpu_total},
			memory: {value: memory_value, unit: memory_unit ?? ''}
		};

		for (const [modifier, {value, unit}] of Object.entries(cards)) {
			const card = document.querySelector(`.mnz-docker-card-${modifier}`);

			if (card === null) {
				continue;
			}

			card.querySelector('.mnz-docker-card-value').textContent = value;

			if (unit !== undefined) {
				card.querySelector('.mnz-docker-card-unit').textContent = unit;
			}
		}
	}

	_updateTable(containers) {
		const table = document.getElementById('mnz-docker-table');

		if (table === null) {
			return;
		}

		const by_name = new Map(containers.map((container) => [container.name, container]));

		const row_names = [...table.querySelectorAll('tbody tr .mnz-docker-name')]
			.map((node) => node.textContent);

		const known_total = Number(table.dataset.mnzTotal ?? row_names.length);

		if (by_name.size !== known_total || row_names.some((name) => !by_name.has(name))) {
			location.reload();

			return;
		}

		for (const row of table.querySelectorAll('tbody tr')) {
			const name_node = row.querySelector('.mnz-docker-name');

			if (name_node === null || !by_name.has(name_node.textContent)) {
				continue;
			}

			const container = by_name.get(name_node.textContent);
			const status_node = row.querySelector('.mnz-docker-status');
			const status_class = container.is_running
				? 'mnz-docker-status-running'
				: 'mnz-docker-status-stopped';

			if (status_node !== null) {
				status_node.textContent = container.status;
				status_node.classList.remove('mnz-docker-status-running', 'mnz-docker-status-stopped');
				status_node.classList.add(status_class);
			}

			const dot = row.querySelector('.mnz-docker-dot');

			if (dot !== null) {
				dot.classList.remove('mnz-docker-status-running', 'mnz-docker-status-stopped');
				dot.classList.add(status_class);
			}

			const values = row.querySelectorAll('.mnz-docker-metric-value');

			if (values.length === 2) {
				values[0].textContent = container.cpu;
				values[1].textContent = container.memory;
			}

			const cells = row.querySelectorAll('td');

			if (cells.length === 8) {
				cells[4].textContent = container.memory_limit;
				cells[5].textContent = container.net_in;
				cells[6].textContent = container.net_out;
				cells[7].textContent = container.uptime;
			}

			row.dataset.mnzStatus = container.is_running ? 'running' : 'stopped';
			row.dataset.mnzCpu = String(container.cpu_raw);
			row.dataset.mnzMemory = String(container.memory_raw);
			row.dataset.mnzUptime = String(container.uptime_raw);
		}

		this._applyTableState();
	}
};
</script>

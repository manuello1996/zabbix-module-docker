<?php declare(strict_types = 0);
?>
<script>
window.monzphere_docker = new class {
	init({hostid, refresh_interval, active_tab, ssh_command}) {
		this._hostid = hostid;
		this._refresh_interval = refresh_interval;
		this._active_tab = active_tab ?? '';
		this._ssh_command = ssh_command ?? '';
		this._timer = null;
		this._panel = null;
		this._content = null;
		this._tab_cache = new Map();
		this._tab_pages = new Map();
		this._last_refresh = null;
		this._refresh_failures = 0;
		this._refresh_status = null;
		this._table_state = {search: '', status: 'all', sort: null, dir: 1, page: 1};
		this._page_size = 25;
		this._search_debounce = null;
		this._modal = null;
		this._modal_trigger = null;
		this._modal_keydown = null;
		this._modal_seq = 0;
		this._resize_debounce = null;

		document.querySelector('header.header-title')?.remove();

		this._initTabs();
		this._initTableControls();
		this._initContainerModal();
		this._initEditShim();
		this._initTopbar();

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

		window.addEventListener('resize', () => {
			clearTimeout(this._resize_debounce);

			this._resize_debounce = setTimeout(() => {
				if (this._modal !== null && !this._modal.backdrop.hidden) {
					this._hydrateCharts(this._modal.body, true);
				}
			}, 300);
		});

		this._scheduleRefresh();
	}

	_initEditShim() {
		if (window.view !== undefined && typeof window.view.editTrigger === 'function') {
			return;
		}

		const on_submit = (e) => {
			const data = e.detail;

			if ('success' in data) {
				postMessageOk(data.success.title);

				if ('messages' in data.success) {
					postMessageDetails('success', data.success.messages);
				}
			}
			else if ('error' in data) {
				postMessageError(data.error.title);

				if ('messages' in data.error) {
					postMessageDetails('error', data.error.messages);
				}
			}

			location.href = location.href;
		};

		window.view = {
			editTrigger(trigger_data) {
				const overlay = PopUp('trigger.edit', trigger_data, {
					dialogueid: 'trigger-edit',
					dialogue_class: 'modal-popup-large',
					prevent_navigation: true
				});

				overlay.$dialogue[0].addEventListener('dialogue.submit', on_submit, {once: true});
			},

			editItem(target, data) {
				const overlay = PopUp('item.edit', data, {
					dialogueid: 'item-edit',
					dialogue_class: 'modal-popup-large',
					prevent_navigation: true,
					trigger_element: target
				});

				overlay.$dialogue[0].addEventListener('dialogue.submit', on_submit, {once: true});
			},

			editHost(hostid) {
				const original_url = location.href;

				const overlay = PopUp('popup.host.edit', {hostid}, {
					dialogueid: 'host_edit',
					dialogue_class: 'modal-popup-large',
					prevent_navigation: true
				});

				overlay.$dialogue[0].addEventListener('dialogue.submit', on_submit, {once: true});
				overlay.$dialogue[0].addEventListener('dialogue.close', () => {
					history.replaceState({}, '', original_url);
				}, {once: true});
			}
		};
	}

	_initTopbar() {
		document.getElementById('mnz-docker-btn-filter')?.addEventListener('click', (e) => {
			const filters = document.getElementById('mnz-docker-filters');

			if (filters !== null) {
				filters.hidden = !filters.hidden;
				e.currentTarget.classList.toggle('mnz-docker-iconbtn-active', !filters.hidden);
			}
		});

		document.getElementById('mnz-docker-btn-refresh')?.addEventListener('click', () => {
			const btn = document.getElementById('mnz-docker-btn-refresh');

			btn?.classList.add('mnz-docker-iconbtn-busy');
			setTimeout(() => btn?.classList.remove('mnz-docker-iconbtn-busy'), 1200);

			this._tab_cache.clear();

			const active = document.querySelector('.mnz-docker-tab-active');

			if (active !== null && active.dataset.mnzTab !== '') {
				this._loadTab(active.dataset.mnzTab);
			}

			clearTimeout(this._timer);
			this._refresh();
		});

		const kebab_btn = document.getElementById('mnz-docker-btn-kebab');
		const kebab_menu = document.getElementById('mnz-docker-kebab-menu');

		if (kebab_btn !== null && kebab_menu !== null) {
			kebab_btn.addEventListener('click', (e) => {
				e.stopPropagation();
				kebab_menu.hidden = !kebab_menu.hidden;
			});

			document.addEventListener('click', (e) => {
				if (!kebab_menu.hidden && !kebab_menu.contains(e.target)) {
					kebab_menu.hidden = true;
				}
			});
		}

		document.getElementById('mnz-docker-menu-edit')?.addEventListener('click', () => {
			if (kebab_menu !== null) {
				kebab_menu.hidden = true;
			}

			view.editHost(this._hostid);
		});

		document.getElementById('mnz-docker-btn-edit')?.addEventListener('click', () => {
			view.editHost(this._hostid);
		});

		const ssh_btn = document.getElementById('mnz-docker-btn-ssh');

		if (ssh_btn !== null && this._ssh_command !== '') {
			ssh_btn.addEventListener('click', () => {
				const done = () => {
					const label = ssh_btn.querySelector('span:last-child');
					const original = label.textContent;

					label.textContent = <?= json_encode(_('Copied!')) ?>;
					setTimeout(() => { label.textContent = original; }, 1500);
				};

				if (navigator.clipboard?.writeText) {
					navigator.clipboard.writeText(this._ssh_command).then(done).catch(() => {});
				}
				else {
					const helper = document.createElement('textarea');

					helper.value = this._ssh_command;
					document.body.append(helper);
					helper.select();
					document.execCommand('copy');
					helper.remove();
					done();
				}
			});
		}
	}

	_hydrateCharts(root, force = false) {
		for (const img of root.querySelectorAll('img[data-mnz-chart]')) {
			if (img.closest('[hidden]') !== null) {
				continue;
			}

			const holder = img.closest('.mnz-docker-cell') ?? img.parentElement;
			const style = getComputedStyle(holder);
			const shift = parseInt(img.dataset.mnzShift ?? '0', 10);
			const width = Math.max(400, Math.floor(holder.clientWidth
				- parseFloat(style.paddingLeft) - parseFloat(style.paddingRight)) - shift);

			if (!force && img.dataset.mnzChartWidth === String(width)) {
				continue;
			}

			img.dataset.mnzChartWidth = String(width);
			img.src = img.dataset.mnzChart
				+ (img.dataset.mnzChart.includes('?') ? '&' : '?')
				+ 'width=' + width;
		}
	}

	_filterGraphs(query) {
		for (const group of this._panel.querySelectorAll('.mnz-docker-graphgroup')) {
			const group_match = query !== '' && (group.dataset.mnzGraphgroup ?? '').includes(query);
			let visible = 0;

			for (const item of group.querySelectorAll('.mnz-docker-graph')) {
				const show = query === '' || group_match || (item.dataset.mnzGraph ?? '').includes(query);

				item.hidden = !show;
				visible += show ? 1 : 0;
			}

			group.hidden = visible === 0;

			if (query !== '' && visible > 0) {
				this._expandGraphGroup(group, true);
			}
		}
	}

	_expandGraphGroup(group, expand) {
		const head = group.querySelector('.mnz-docker-graphgroup-head');
		const body = group.querySelector('.mnz-docker-graphgroup-body');

		if (head === null || body === null || body.hidden === !expand) {
			return;
		}

		body.hidden = !expand;
		head.classList.toggle('mnz-docker-graphgroup-open', expand);
		head.setAttribute('aria-expanded', expand ? 'true' : 'false');

		if (expand) {
			this._hydrateCharts(body);
		}
	}

	_initTabs() {
		const content = document.getElementById('mnz-docker-content');
		const panel = document.getElementById('mnz-docker-panel');

		if (content === null || panel === null) {
			return;
		}

		this._content = content;
		this._panel = panel;

		panel.addEventListener('input', (e) => {
			if (e.target.id === 'mnz-docker-graphs-search') {
				clearTimeout(this._graphs_search_debounce);

				this._graphs_search_debounce = setTimeout(() => {
					this._filterGraphs(e.target.value.trim().toLowerCase());
				}, 250);
			}
		});

		panel.addEventListener('click', (e) => {
			const head = e.target.closest('.mnz-docker-graphgroup-head');

			if (head !== null && panel.contains(head)) {
				const group = head.closest('.mnz-docker-graphgroup');
				const body = group.querySelector('.mnz-docker-graphgroup-body');

				this._expandGraphGroup(group, body.hidden);

				return;
			}

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

				const timefilter = document.getElementById('mnz-docker-timefilter');

				if (timefilter !== null) {
					timefilter.hidden = key !== 'graphs';
				}

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
					this._hydrateCharts(panel);
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
					this._table_state.page = 1;
					this._applyTableState();
				}, 250);
			});
		}

		document.getElementById('mnz-docker-status')?.addEventListener('change', (e) => {
			this._table_state.status = e.target.value;
			this._table_state.page = 1;
			this._applyTableState();
		});

		document.getElementById('mnz-docker-pager-prev')?.addEventListener('click', () => {
			this._table_state.page = Math.max(1, this._table_state.page - 1);
			this._applyTableState();
		});

		document.getElementById('mnz-docker-pager-next')?.addEventListener('click', () => {
			this._table_state.page++;
			this._applyTableState();
		});

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

				this._table_state.page = 1;
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

		const status_select = document.getElementById('mnz-docker-status');

		if (status_select !== null) {
			for (const option of status_select.options) {
				const labels = {
					all: <?= json_encode(_('All')) ?>,
					running: <?= json_encode(_('Running')) ?>,
					stopped: <?= json_encode(_('Stopped')) ?>
				};

				option.textContent = `${labels[option.value]} (${counts[option.value] ?? 0})`;
			}
		}

		const {search, status, sort, dir} = this._table_state;

		if (sort !== null) {
			const dataset_key = 'mnz' + sort.charAt(0).toUpperCase() + sort.slice(1);

			const sorted = [...rows].sort((a, b) => sort === 'name'
				? dir * a.dataset.mnzName.localeCompare(b.dataset.mnzName)
				: dir * ((parseFloat(a.dataset[dataset_key]) || 0) - (parseFloat(b.dataset[dataset_key]) || 0))
			);

			tbody.append(...sorted);
			rows.length = 0;
			rows.push(...sorted);
		}

		const filtered = rows.filter((row) =>
			(search === '' || row.dataset.mnzName.includes(search))
			&& (status === 'all' || row.dataset.mnzStatus === status)
		);

		const pages = Math.max(1, Math.ceil(filtered.length / this._page_size));

		this._table_state.page = Math.min(this._table_state.page, pages);

		const start = (this._table_state.page - 1) * this._page_size;
		const page_rows = new Set(filtered.slice(start, start + this._page_size));

		for (const row of rows) {
			row.hidden = !page_rows.has(row);
		}

		const info = document.getElementById('mnz-docker-pager-info');

		if (info !== null) {
			info.textContent = filtered.length > 0
				? `${start + 1}-${start + page_rows.size} ` + <?= json_encode(_('of')) ?> + ` ${filtered.length}`
				: '0 ' + <?= json_encode(_('of')) ?> + ' 0';
		}

		const prev = document.getElementById('mnz-docker-pager-prev');
		const next = document.getElementById('mnz-docker-pager-next');

		if (prev !== null) {
			prev.disabled = this._table_state.page <= 1;
		}

		if (next !== null) {
			next.disabled = this._table_state.page >= pages;
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
					this._hydrateCharts(modal.body);
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

				this._updateOverview(response);
				this._updateTable(response.containers);
			})
			.catch(() => {
				this._registerRefreshFailure();
			})
			.finally(() => this._scheduleRefresh());
	}

	_updateOverview(response) {
		const overview = response.overview;
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
				const unit_node = card.querySelector('.mnz-docker-card-unit');

				if (unit_node !== null) {
					unit_node.textContent = unit;
				}
			}
		}

		if (response.memory_pct !== null && response.memory_pct !== undefined) {
			const fill = document.getElementById('mnz-docker-memory-fill');

			if (fill !== null) {
				fill.style.width = response.memory_pct + '%';
			}
		}

		const badge = document.getElementById('mnz-docker-problems-badge');

		if (badge !== null && Array.isArray(response.problem_badges)) {
			badge.innerHTML = '';

			for (const item of response.problem_badges) {
				const span = document.createElement('span');

				span.className = 'problem-icon-list-item ' + item.style;
				span.title = item.title;
				span.textContent = item.count;
				badge.append(span);
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

			const status_node = row.querySelector('.js-status');

			if (status_node !== null) {
				status_node.textContent = container.status_text;
				status_node.className = 'mnz-docker-state mnz-docker-state-' + container.status_kind + ' js-status';
			}

			row.classList.toggle('mnz-docker-row-restarting', container.status_kind === 'restarting');
			row.classList.toggle('mnz-docker-row-off', ['down', 'off'].includes(container.status_kind));

			const set = (selector, value) => {
				const node = row.querySelector(selector);

				if (node !== null) {
					node.textContent = value;
				}
			};

			set('.js-cpu', container.cpu);
			set('.js-mem-val', container.memory);
			set('.js-limit', container.memory_limit);
			set('.js-rx', container.net_in);
			set('.js-tx', container.net_out);
			set('.js-uptime', container.uptime);

			row.dataset.mnzStatus = container.is_running ? 'running' : 'stopped';
			row.dataset.mnzCpu = String(container.cpu_raw);
			row.dataset.mnzMemory = String(container.memory_raw);
			row.dataset.mnzUptime = String(container.uptime_raw);
		}

		this._applyTableState();
	}
};
</script>

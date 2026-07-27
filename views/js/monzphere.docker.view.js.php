<?php declare(strict_types = 0);
?>
<script>
window.monzphere_docker = new class {
	init({hostid}) {
		this._hostid = hostid;
		this._panel = null;
		this._content = null;
		this._tab_cache = new Map();
		this._tab_pages = new Map();
		this._table_state = {search: '', status: 'all', sort: null, dir: 1, page: 1};
		this._page_size = 25;
		this._search_debounce = null;
		this._image_filter_debounce = null;
		this._modal = null;
		this._modal_trigger = null;
		this._modal_keydown = null;
		this._modal_seq = 0;
		this._resize_debounce = null;
		this._sparkline_observer = null;
		this._sparkline_queue = new Set();
		this._sparkline_batch_timer = null;
		this._sparkline_history = new Map();

		document.querySelector('header.header-title')?.remove();

		this._initTabs();
		this._initTableControls();
		this._initContainerModal();
		this._initEditShim();
		this._initTopbar();
		this._initHostFilter();

		const requested_container = new URLSearchParams(location.search).get('container');

		if (this._hostid !== '' && requested_container !== null && requested_container.trim() !== '') {
			this._openContainerModal(requested_container, null);
		}

		jQuery.subscribe('timeselector.rangeupdate', () => {
			this._tab_cache.delete('graphs');

			const active = document.querySelector('.mnz-docker-tab-active');

			if (this._panel !== null && active !== null && active.dataset.mnzTab === 'graphs') {
				this._loadTab('graphs');
			}
		});

		jQuery.subscribe('acknowledge.create', () => {
			this._tab_cache.delete('problems');

			if (this._isActiveTab('problems')) {
				this._loadTab('problems', true);
			}
		});

		this._applyTableState();
		this._initSparklines();

		window.addEventListener('resize', () => {
			clearTimeout(this._resize_debounce);

			this._resize_debounce = setTimeout(() => {
				if (this._modal !== null && !this._modal.backdrop.hidden) {
					this._hydrateCharts(this._modal.body, true);
				}
			}, 300);
		});

	}

	_initSparklines() {
		const holders = document.querySelectorAll('.mnz-docker-sparkline[data-mnz-spark-itemids]');

		if (holders.length === 0) {
			return;
		}

		if (!('IntersectionObserver' in window)) {
			holders.forEach((holder) => this._queueSparkline(holder));

			return;
		}

		this._sparkline_observer = new IntersectionObserver((entries) => {
			for (const entry of entries) {
				if (entry.isIntersecting && entry.target.closest('[hidden]') === null) {
					this._queueSparkline(entry.target);
				}
			}
		}, {rootMargin: '80px 0px'});

		holders.forEach((holder) => this._sparkline_observer.observe(holder));
	}

	_queueSparkline(holder) {
		if (holder.dataset.mnzSparkLoaded === '1') {
			return;
		}

		this._sparkline_observer?.unobserve(holder);
		holder.dataset.mnzSparkLoaded = '1';

		const itemids = this._sparklineItemids(holder);
		const kind = holder.dataset.mnzSparkKind ?? 'up';

		if (itemids.length === 0 || kind === 'down' || kind === 'off') {
			this._renderSparkline(holder, [], kind);

			return;
		}

		this._sparkline_queue.add(holder);
		clearTimeout(this._sparkline_batch_timer);
		this._sparkline_batch_timer = setTimeout(() => this._loadSparklineBatch(), 20);
	}

	_sparklineItemids(holder) {
		return (holder.dataset.mnzSparkItemids ?? '')
			.split(',')
			.filter((itemid) => /^\d+$/.test(itemid));
	}

	_loadSparklineBatch() {
		const holders = [...this._sparkline_queue];

		this._sparkline_queue.clear();

		if (holders.length === 0) {
			return;
		}

		const itemids = [...new Set(holders.flatMap((holder) => this._sparklineItemids(holder)))]
			.filter((itemid) => !this._sparkline_history.has(itemid));

		if (itemids.length === 0) {
			this._renderSparklineHolders(holders);

			return;
		}

		const url = new Curl('zabbix.php');

		url.setArgument('action', 'monzphere.docker.sparkline');
		url.setArgument('hostid', this._hostid);
		url.setArgument('itemids', itemids);

		fetch(url.getUrl(), {cache: 'no-store'})
			.then((response) => response.json())
			.then((response) => {
				if ('error' in response) {
					throw new Error();
				}

				const history = response.history ?? {};

				for (const itemid of itemids) {
					this._sparkline_history.set(itemid, history[itemid] ?? []);
				}

				this._renderSparklineHolders(holders);
			})
			.catch(() => {
				for (const holder of holders) {
					this._renderSparkline(holder, [], holder.dataset.mnzSparkKind ?? 'up');
				}
			});
	}

	_renderSparklineHolders(holders) {
		for (const holder of holders) {
			const itemids = this._sparklineItemids(holder);
			const series = holder.dataset.mnzSparkMode === 'sum'
				? this._sumSparklineSeries(
					itemids.map((itemid) => this._sparkline_history.get(itemid) ?? [])
				)
				: this._sparkline_history.get(itemids[0]) ?? [];

			this._renderSparkline(holder, series, holder.dataset.mnzSparkKind ?? 'up');
		}
	}

	_sumSparklineSeries(series_list) {
		const buckets = new Map();

		for (const series of series_list) {
			for (const point of series) {
				const bucket = Math.floor(Number(point[0]) / 1440);

				buckets.set(bucket, (buckets.get(bucket) ?? 0) + Number(point[1]));
			}
		}

		return [...buckets.entries()]
			.sort(([a], [b]) => a - b)
			.map(([bucket, value]) => [bucket * 1440, value]);
	}

	_renderSparkline(holder, history, kind) {
		const width = 100;
		const height = 24;
		const pad = 3;
		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');

		svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
		svg.setAttribute('preserveAspectRatio', 'none');
		svg.classList.add('mnz-docker-spark-svg');

		if (history.length < 2 || kind === 'down' || kind === 'off') {
			const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
			const y = height - pad - 2;

			line.setAttribute('x1', '0');
			line.setAttribute('y1', String(y));
			line.setAttribute('x2', String(width));
			line.setAttribute('y2', String(y));
			line.classList.add('mnz-docker-spark-flat');
			svg.append(line);
		}
		else {
			if (history.length > 60) {
				const step = Math.ceil(history.length / 60);

				history = history.filter((point, index) => index % step === 0);
			}

			const values = history.map((point) => Number(point[1]));
			const min = Math.min(...values);
			const max = Math.max(...values);
			const range = max - min;
			const points = values.map((value, index) => {
				const x = values.length > 1 ? index / (values.length - 1) * width : 0;
				const y = range > 0
					? height - pad - ((value - min) / range) * (height - 2 * pad)
					: height / 2;

				return `${x.toFixed(1)},${y.toFixed(1)}`;
			});

			const polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');

			polygon.setAttribute('points', `0,${height - 1} ${points.join(' ')} ${width},${height - 1}`);
			polygon.classList.add('mnz-docker-spark-fill');
			svg.append(polygon);

			const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');

			polyline.setAttribute('points', points.join(' '));
			polyline.setAttribute('fill', 'none');
			polyline.classList.add('mnz-docker-spark-line');
			svg.append(polyline);
		}

		holder.replaceChildren(svg);
		holder.removeAttribute('aria-label');
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
	}

	_initHostFilter() {
		const form = document.forms.mnz_docker_filterbar;
		const submit = form?.querySelector('button[name="filter_set"]');

		if (form === undefined || form === null || submit === null) {
			return;
		}

		const sync = () => {
			const selected = [...form.querySelectorAll('input[name="filter_hostid[]"]')]
				.some((input) => input.value !== '');

			submit.disabled = !selected;
		};

		form.addEventListener('input', sync);
		form.addEventListener('change', sync);
		form.addEventListener('submit', (e) => {
			sync();

			if (submit.disabled) {
				e.preventDefault();
				form.querySelector('#filter_hostid__ms input')?.focus();
			}
		});

		new MutationObserver(sync).observe(form, {
			subtree: true,
			childList: true,
			attributes: true,
			attributeFilter: ['value']
		});

		sync();
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

	_filterImages(root) {
		const queries = {};

		for (const input of root.querySelectorAll('[data-mnz-image-filter]')) {
			queries[input.dataset.mnzImageFilter] = input.value.trim().toLowerCase();
		}

		const rows = [...root.querySelectorAll('#mnz-docker-images-table tbody tr[data-mnz-image-name]')];
		let visible = 0;

		for (const row of rows) {
			const show = Object.entries(queries).every(([field, query]) => {
				if (query === '') {
					return true;
				}

				const property = 'mnzImage' + field.charAt(0).toUpperCase() + field.slice(1);

				return (row.dataset[property] ?? '').includes(query);
			});

			row.hidden = !show;
			visible += show ? 1 : 0;
		}

		const count = root.querySelector('#mnz-docker-images-filter-count');

		if (count !== null) {
			const images_label = <?= json_encode(_('images')) ?>;
			const of_label = <?= json_encode(_('of')) ?>;

			count.textContent = visible === rows.length
				? `${rows.length} ${images_label}`
				: `${visible} ${of_label} ${rows.length} ${images_label}`;
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
			else if (e.target.matches('[data-mnz-image-filter]')) {
				clearTimeout(this._image_filter_debounce);

				this._image_filter_debounce = setTimeout(() => {
					this._filterImages(panel);
				}, 150);
			}
		});

		panel.addEventListener('click', (e) => {
			if (e.target.closest('.mnz-docker-topo-dns-link') !== null) {
				return;
			}

			const topo_node = e.target.closest('[data-mnz-container]');

			if (topo_node !== null && panel.contains(topo_node)) {
				this._openContainerModal(topo_node.dataset.mnzContainer, topo_node);

				return;
			}

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

	_loadTab(key, silent = false) {
		const panel = this._panel;

		if (!this._tab_cache.has(key)) {
			if (!silent) {
				panel.innerHTML = '<div class="mnz-docker-loading">'
					+ <?= json_encode(_('Loading...')) ?> + '</div>';
			}

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
			(search === ''
				|| row.dataset.mnzName.includes(search)
				|| (row.dataset.mnzNote ?? '').includes(search))
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
		this._setModalTitle(name);
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
					this._setModalTitle(name, response.description ?? '');
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

	_setModalTitle(name, description = '') {
		const title = this._modal?.title;

		if (title === null || title === undefined) {
			return;
		}

		title.textContent = name;

		description = description.trim();

		if (description !== '') {
			const note = document.createElement('span');

			note.className = 'mnz-docker-modal-title-description';
			note.textContent = '- ' + description;
			title.append(note);
		}
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

};
</script>

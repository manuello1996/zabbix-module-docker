<?php declare(strict_types = 0);
?>
<script>
window.monitor_docker = new class {
	init({hostid}) {
		this._hostid = hostid;
		this._panel = null;
		this._content = null;
		this._tab_cache = new Map();
		this._tab_pages = new Map();
		this._problem_pages = new Map([['docker', '1'], ['other', '1']]);
		this._table_state = {search: '', status: 'all', sort: null, dir: 1, page: 1};
		this._page_size = 25;
		this._search_debounce = null;
		this._image_filter_debounce = null;
		this._image_sort = {field: null, dir: 1};
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

			const active = document.querySelector('.docker-tab-active');

			if (this._panel !== null && active !== null && active.dataset.tab === 'graphs') {
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
		const holders = document.querySelectorAll('.docker-sparkline[data-spark-itemids]');

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
		if (holder.dataset.sparkLoaded === '1') {
			return;
		}

		this._sparkline_observer?.unobserve(holder);
		holder.dataset.sparkLoaded = '1';

		const itemids = this._sparklineItemids(holder);
		const kind = holder.dataset.sparkKind ?? 'up';

		if (itemids.length === 0 || kind === 'down' || kind === 'off') {
			this._renderSparkline(holder, [], kind);

			return;
		}

		this._sparkline_queue.add(holder);
		clearTimeout(this._sparkline_batch_timer);
		this._sparkline_batch_timer = setTimeout(() => this._loadSparklineBatch(), 20);
	}

	_sparklineItemids(holder) {
		return (holder.dataset.sparkItemids ?? '')
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

		url.setArgument('action', 'docker.sparkline');
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
					this._renderSparkline(holder, [], holder.dataset.sparkKind ?? 'up');
				}
			});
	}

	_renderSparklineHolders(holders) {
		for (const holder of holders) {
			const itemids = this._sparklineItemids(holder);
			const series = holder.dataset.sparkMode === 'sum'
				? this._sumSparklineSeries(
					itemids.map((itemid) => this._sparkline_history.get(itemid) ?? [])
				)
				: this._sparkline_history.get(itemids[0]) ?? [];

			this._renderSparkline(holder, series, holder.dataset.sparkKind ?? 'up');
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
		svg.classList.add('docker-spark-svg');

		if (history.length < 2 || kind === 'down' || kind === 'off') {
			const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
			const y = height - pad - 2;

			line.setAttribute('x1', '0');
			line.setAttribute('y1', String(y));
			line.setAttribute('x2', String(width));
			line.setAttribute('y2', String(y));
			line.classList.add('docker-spark-flat');
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
			polygon.classList.add('docker-spark-fill');
			svg.append(polygon);

			const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');

			polyline.setAttribute('points', points.join(' '));
			polyline.setAttribute('fill', 'none');
			polyline.classList.add('docker-spark-line');
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
		document.getElementById('docker-btn-filter')?.addEventListener('click', (e) => {
			const filters = document.getElementById('docker-filters');

			if (filters !== null) {
				filters.hidden = !filters.hidden;
				e.currentTarget.classList.toggle('docker-iconbtn-active', !filters.hidden);
			}
		});
	}

	_initHostFilter() {
		const form = document.forms.docker_filterbar;
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
		for (const img of root.querySelectorAll('img[data-chart]')) {
			if (img.closest('[hidden]') !== null) {
				continue;
			}

			const holder = img.closest('.docker-cell') ?? img.parentElement;
			const style = getComputedStyle(holder);
			const shift = parseInt(img.dataset.shift ?? '0', 10);
			const width = Math.max(400, Math.floor(holder.clientWidth
				- parseFloat(style.paddingLeft) - parseFloat(style.paddingRight)) - shift);

			if (!force && img.dataset.chartWidth === String(width)) {
				continue;
			}

			img.dataset.chartWidth = String(width);
			img.src = img.dataset.chart
				+ (img.dataset.chart.includes('?') ? '&' : '?')
				+ 'width=' + width;
		}
	}

	_filterGraphs(query) {
		for (const group of this._panel.querySelectorAll('.docker-graphgroup')) {
			const group_match = query !== '' && (group.dataset.graphgroup ?? '').includes(query);
			let visible = 0;

			for (const item of group.querySelectorAll('.docker-graph')) {
				const show = query === '' || group_match || (item.dataset.graph ?? '').includes(query);

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

		for (const input of root.querySelectorAll('[data-image-filter]')) {
			queries[input.dataset.imageFilter] = input.value.trim().toLowerCase();
		}

		const rows = [...root.querySelectorAll('#docker-images-table tbody tr[data-image-name]')];
		let visible = 0;

		for (const row of rows) {
			const show = Object.entries(queries).every(([field, query]) => {
				if (query === '') {
					return true;
				}

				const property = 'image' + field.charAt(0).toUpperCase() + field.slice(1);

				return (row.dataset[property] ?? '').includes(query);
			});

			row.hidden = !show;
			visible += show ? 1 : 0;
		}

		const count = root.querySelector('#docker-images-filter-count');

		if (count !== null) {
			const images_label = <?= json_encode(_('images')) ?>;
			const of_label = <?= json_encode(_('of')) ?>;

			count.textContent = visible === rows.length
				? `${rows.length} ${images_label}`
				: `${visible} ${of_label} ${rows.length} ${images_label}`;
		}
	}

	_sortImages(root, field) {
		const tbody = root.querySelector('#docker-images-table tbody');

		if (tbody === null) {
			return;
		}

		if (this._image_sort.field === field) {
			this._image_sort.dir = -this._image_sort.dir;
		}
		else {
			this._image_sort = {field, dir: 1};
		}

		const {dir} = this._image_sort;
		const property = 'imageSort' + field.charAt(0).toUpperCase() + field.slice(1);
		const rows = [...tbody.querySelectorAll('tr[data-image-name]')];

		rows.sort((a, b) => {
			const a_value = Number(a.dataset[property]);
			const b_value = Number(b.dataset[property]);
			const difference = (Number.isFinite(a_value) ? a_value : -1)
				- (Number.isFinite(b_value) ? b_value : -1);

			return difference !== 0
				? dir * difference
				: (a.dataset.imageName ?? '').localeCompare(b.dataset.imageName ?? '');
		});
		tbody.append(...rows);

		for (const header of root.querySelectorAll('[data-image-sort]')) {
			const active = header.dataset.imageSort === field;
			const arrow = header.querySelector('.docker-sort-arrow');
			const th = header.closest('th');

			arrow?.replaceChildren();

			if (active) {
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

	_expandGraphGroup(group, expand) {
		const head = group.querySelector('.docker-graphgroup-head');
		const body = group.querySelector('.docker-graphgroup-body');

		if (head === null || body === null || body.hidden === !expand) {
			return;
		}

		body.hidden = !expand;
		head.classList.toggle('docker-graphgroup-open', expand);
		head.setAttribute('aria-expanded', expand ? 'true' : 'false');

		if (expand) {
			this._hydrateCharts(body);
		}
	}

	_initTabs() {
		const content = document.getElementById('docker-content');
		const panel = document.getElementById('docker-panel');

		if (content === null || panel === null) {
			return;
		}

		this._content = content;
		this._panel = panel;

		panel.addEventListener('input', (e) => {
			if (e.target.id === 'docker-graphs-search') {
				clearTimeout(this._graphs_search_debounce);

				this._graphs_search_debounce = setTimeout(() => {
					this._filterGraphs(e.target.value.trim().toLowerCase());
				}, 250);
			}
			else if (e.target.matches('[data-image-filter]')) {
				clearTimeout(this._image_filter_debounce);

				this._image_filter_debounce = setTimeout(() => {
					this._filterImages(panel);
				}, 150);
			}
		});

		panel.addEventListener('click', (e) => {
			if (e.target.closest('.docker-topo-dns-link') !== null) {
				return;
			}

			const image_sort = e.target.closest('[data-image-sort]');

			if (image_sort !== null && panel.contains(image_sort)) {
				this._sortImages(panel, image_sort.dataset.imageSort);

				return;
			}

			const topo_node = e.target.closest('[data-container]');

			if (topo_node !== null && panel.contains(topo_node)) {
				this._openContainerModal(topo_node.dataset.container, topo_node);

				return;
			}

			const head = e.target.closest('.docker-graphgroup-head');

			if (head !== null && panel.contains(head)) {
				const group = head.closest('.docker-graphgroup');
				const body = group.querySelector('.docker-graphgroup-body');

				this._expandGraphGroup(group, body.hidden);

				return;
			}

			const page_link = e.target.closest('.<?= defined('ZBX_STYLE_PAGER_CONTAINER') ? ZBX_STYLE_PAGER_CONTAINER : ZBX_STYLE_TABLE_PAGING ?> a[href]');

			if (page_link === null || !panel.contains(page_link)) {
				return;
			}

			e.preventDefault();

			const active = document.querySelector('.docker-tab-active');

			if (active === null || active.dataset.tab === '') {
				return;
			}

			const key = active.dataset.tab;
			const page_url = new URL(page_link.href, location.origin);
			const page = page_url.searchParams.get('page') ?? '1';

			if (key === 'problems') {
				const section = page_url.searchParams.get('problem_section');

				if (section === 'docker' || section === 'other') {
					this._problem_pages.set(section, page);
				}
			}
			else {
				this._tab_pages.set(key, page);
			}

			this._tab_cache.delete(key);
			this._loadTab(key);
		});

		panel.addEventListener('keydown', (e) => {
			const image_sort = e.target.closest('[data-image-sort]');

			if (image_sort !== null && panel.contains(image_sort) && (e.key === 'Enter' || e.key === ' ')) {
				e.preventDefault();
				this._sortImages(panel, image_sort.dataset.imageSort);
			}
		});

		for (const tab of document.querySelectorAll('.docker-tab[data-tab]')) {
			tab.addEventListener('click', () => {
				document.querySelectorAll('.docker-tab').forEach((node) => {
					node.classList.remove('docker-tab-active');
					node.setAttribute('aria-selected', 'false');
				});

				tab.classList.add('docker-tab-active');
				tab.setAttribute('aria-selected', 'true');

				const key = tab.dataset.tab;

				const timefilter = document.getElementById('docker-timefilter');

				if (timefilter !== null) {
					timefilter.hidden = key !== 'graphs';
				}

				if (key === '') {
					panel.hidden = true;
					content.hidden = false;

					const url = new Curl('zabbix.php');

					url.setArgument('action', 'docker.tab');
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
		const active = document.querySelector('.docker-tab-active');

		return active !== null && active.dataset.tab === key;
	}

	_loadTab(key, silent = false) {
		const panel = this._panel;

		if (!this._tab_cache.has(key)) {
			if (!silent) {
				panel.innerHTML = '<div class="docker-loading">'
					+ <?= json_encode(_('Loading...')) ?> + '</div>';
			}

			const url = new Curl('zabbix.php');

			url.setArgument('action', 'docker.tab');
			url.setArgument('hostid', this._hostid);
			url.setArgument('tab', key);

			if (key === 'problems') {
				url.setArgument('docker_page', this._problem_pages.get('docker') ?? '1');
				url.setArgument('other_page', this._problem_pages.get('other') ?? '1');
			}
			else {
				url.setArgument('page', this._tab_pages.get(key) ?? '1');
			}

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

		message.className = 'docker-loading';
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
		const search = document.getElementById('docker-search');

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

		document.getElementById('docker-status')?.addEventListener('change', (e) => {
			this._table_state.status = e.target.value;
			this._table_state.page = 1;
			this._applyTableState();
		});

		document.getElementById('docker-pager-prev')?.addEventListener('click', () => {
			this._table_state.page = Math.max(1, this._table_state.page - 1);
			this._applyTableState();
		});

		document.getElementById('docker-pager-next')?.addEventListener('click', () => {
			this._table_state.page++;
			this._applyTableState();
		});

		for (const header of document.querySelectorAll('.docker-sort')) {
			const toggle = () => {
				const key = header.dataset.sort;

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
		const table = document.getElementById('docker-table');

		if (table === null) {
			return;
		}

		const tbody = table.querySelector('tbody');

		const rows = [...tbody.querySelectorAll('tr')].filter((row) => row.dataset.name !== undefined);

		const counts = {all: rows.length, running: 0, stopped: 0};

		for (const row of rows) {
			counts[row.dataset.status] = (counts[row.dataset.status] ?? 0) + 1;
		}

		const status_select = document.getElementById('docker-status');

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
			const dataset_key = sort;

			const sorted = [...rows].sort((a, b) => sort === 'name'
				? dir * a.dataset.name.localeCompare(b.dataset.name)
				: dir * ((parseFloat(a.dataset[dataset_key]) || 0) - (parseFloat(b.dataset[dataset_key]) || 0))
			);

			tbody.append(...sorted);
			rows.length = 0;
			rows.push(...sorted);
		}

		const filtered = rows.filter((row) =>
			(search === ''
				|| row.dataset.name.includes(search)
				|| (row.dataset.note ?? '').includes(search))
			&& (status === 'all' || row.dataset.status === status)
		);

		const pages = Math.max(1, Math.ceil(filtered.length / this._page_size));

		this._table_state.page = Math.min(this._table_state.page, pages);

		const start = (this._table_state.page - 1) * this._page_size;
		const page_rows = new Set(filtered.slice(start, start + this._page_size));

		for (const row of rows) {
			row.hidden = !page_rows.has(row);
		}

		const info = document.getElementById('docker-pager-info');

		if (info !== null) {
			info.textContent = filtered.length > 0
				? `${start + 1}-${start + page_rows.size} ` + <?= json_encode(_('of')) ?> + ` ${filtered.length}`
				: '0 ' + <?= json_encode(_('of')) ?> + ' 0';
		}

		const prev = document.getElementById('docker-pager-prev');
		const next = document.getElementById('docker-pager-next');

		if (prev !== null) {
			prev.disabled = this._table_state.page <= 1;
		}

		if (next !== null) {
			next.disabled = this._table_state.page >= pages;
		}

		for (const header of document.querySelectorAll('.docker-sort')) {
			const arrow = header.querySelector('.docker-sort-arrow');
			const th = header.closest('th');

			if (arrow !== null) {
				arrow.innerHTML = '';
			}

			if (sort !== null && header.dataset.sort === sort) {
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
		const table = document.getElementById('docker-table');

		if (table === null) {
			return;
		}

		table.addEventListener('click', (e) => {
			const link = e.target.closest('[data-container]');

			if (link !== null) {
				e.preventDefault();
				this._openContainerModal(link.dataset.container, link);
			}
		});
	}

	_ensureModal() {
		if (this._modal !== null) {
			return this._modal;
		}

		const backdrop = document.getElementById('docker-modal');

		if (backdrop === null) {
			return null;
		}

		this._modal = {
			backdrop,
			dialog: backdrop.querySelector('.docker-modal'),
			title: document.getElementById('docker-modal-title'),
			body: backdrop.querySelector('.docker-modal-body')
		};

		backdrop.querySelector('.docker-modal-close')
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

		modal.body.innerHTML = '<div class="docker-loading">'
			+ <?= json_encode(_('Loading...')) ?> + '</div>';

		const url = new Curl('zabbix.php');

		url.setArgument('action', 'docker.container');
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

			note.className = 'docker-modal-title-description';
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

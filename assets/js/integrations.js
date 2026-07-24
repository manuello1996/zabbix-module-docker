(function() {
	'use strict';

	const ACTION = new URL(window.location.href).searchParams.get('action') || '';
	const DOCKER_ACTION = 'monzphere.docker.view';
	const docker_hosts = new Set();
	const checked_hosts = new Set();
	let qualification_request = Promise.resolve();
	let scan_timer = null;

	function dockerUrl(hostid, container = '') {
		const url = new Curl('zabbix.php');

		url.setArgument('action', DOCKER_ACTION);
		url.setArgument('filter_set', '1');
		url.setArgument('filter_hostid[]', hostid);

		if (container !== '') {
			url.setArgument('container', container);
		}

		return url.getUrl();
	}

	function integrationUrl(mode, options = {}) {
		const url = new Curl('zabbix.php');

		url.setArgument('action', 'monzphere.docker.integration');
		url.setArgument('mode', mode);

		for (const [name, value] of Object.entries(options)) {
			if (Array.isArray(value)) {
				url.setArgument(name, value);
			}
			else {
				url.setArgument(name, value);
			}
		}

		return url.getUrl();
	}

	function qualifyHosts(hostids) {
		const unknown = [...new Set(hostids.map(String))]
			.filter((hostid) => /^\d+$/.test(hostid) && !checked_hosts.has(hostid));

		if (unknown.length === 0) {
			return qualification_request;
		}

		unknown.forEach((hostid) => checked_hosts.add(hostid));

		qualification_request = qualification_request
			.then(() => fetch(integrationUrl('hosts', {hostids: unknown})))
			.then((response) => response.json())
			.then((response) => {
				if ('error' in response) {
					throw new Error();
				}

				(response.hostids || []).forEach((hostid) => docker_hosts.add(String(hostid)));
			})
			.catch(() => {
				unknown.forEach((hostid) => checked_hosts.delete(hostid));
			});

		return qualification_request;
	}

	function hostidFromPopup(element) {
		try {
			const popup = JSON.parse(element.getAttribute('data-menu-popup'));

			return String(popup?.data?.hostid || popup?.hostid || '');
		}
		catch (error) {
			return '';
		}
	}

	function replaceCell(cell, hostid) {
		if (cell === undefined || cell === null || !docker_hosts.has(String(hostid))) {
			return;
		}

		if (cell.querySelector('a[data-mnz-docker-host-link]') !== null) {
			return;
		}

		const link = document.createElement('a');

		link.dataset.mnzDockerHostLink = '1';
		link.href = dockerUrl(hostid);
		link.textContent = 'Docker';
		cell.replaceChildren(link);
	}

	function getWebCell(row, table) {
		const web_link = row.querySelector(
			'a[href*="httpconf.php"], a[href*="action=web.view"], a[href*="action%3Dweb.view"]'
		);

		if (web_link !== null) {
			return web_link.closest('td');
		}

		const headers = [...table.querySelectorAll('thead tr:last-child th')];
		const web_column = headers.findIndex((header) => header.textContent.trim() === t('Web'));

		return web_column === -1 ? null : row.cells[web_column];
	}

	function enhanceConfigurationHosts() {
		const form = document.querySelector('form[name="hosts"]');

		if (form === null) {
			return;
		}

		const table = form.querySelector('table');

		if (table === null) {
			return;
		}

		const rows = [...form.querySelectorAll('tbody tr')];
		const entries = rows.map((row) => {
			const checkbox = row.querySelector('input[name^="hostids["]');

			return checkbox === null ? null : {row, hostid: String(checkbox.value)};
		}).filter(Boolean);

		qualifyHosts(entries.map((entry) => entry.hostid)).then(() => {
			entries.forEach(({row, hostid}) => replaceCell(getWebCell(row, table), hostid));
		});
	}

	function enhanceMonitoringHosts() {
		const form = document.querySelector('form[name="host_view"]');

		if (form === null) {
			return;
		}

		const table = form.querySelector('table');

		if (table === null) {
			return;
		}

		const entries = [...form.querySelectorAll('tbody tr')].map((row) => {
			const trigger = row.querySelector('[data-menu-popup]');
			const hostid = trigger === null ? '' : hostidFromPopup(trigger);

			return hostid === '' ? null : {row, hostid};
		}).filter(Boolean);

		qualifyHosts(entries.map((entry) => entry.hostid)).then(() => {
			entries.forEach(({row, hostid}) => replaceCell(getWebCell(row, table), hostid));
		});
	}

	function installHostMenuIntegration() {
		if (typeof window.getMenuPopupHost !== 'function'
				|| window.getMenuPopupHost.mnzDockerIntegration === true) {
			return;
		}

		const original = window.getMenuPopupHost;
		const replacement = function(options, trigger_element) {
			const sections = original(options, trigger_element);

			if (!docker_hosts.has(String(options.hostid))) {
				return sections;
			}

			for (const section of sections) {
				if (section.label !== t('View') && section.label !== t('Configuration')) {
					continue;
				}

				for (const item of section.items || []) {
					if (item.label === t('Web')) {
						item.label = 'Docker';
						item.url = dockerUrl(options.hostid);
						item.disabled = false;
						delete item.clickCallback;
					}
				}
			}

			return sections;
		};

		replacement.mnzDockerIntegration = true;
		window.getMenuPopupHost = replacement;
	}

	function makeCell(value) {
		const cell = document.createElement('td');

		cell.textContent = value || '';

		return cell;
	}

	function buildContainerSearchSection(response) {
		const section = document.createElement('section');
		const head = document.createElement('div');
		const title = document.createElement('h4');
		const body = document.createElement('div');
		const table = document.createElement('table');
		const thead = document.createElement('thead');
		const header = document.createElement('tr');
		const tbody = document.createElement('tbody');

		section.id = 'mnz-docker-search-containers';
		head.className = 'section-head';
		body.className = 'section-body';
		title.textContent = 'Docker Containers';
		table.className = document.querySelector('#search_hosts table')?.className || 'list-table';

		['Container', 'Notes', 'Host', 'IP', 'DNS', 'Host notes'].forEach((label) => {
			const cell = document.createElement('th');

			cell.textContent = label;
			header.append(cell);
		});

		if ((response.containers || []).length === 0) {
			const row = document.createElement('tr');
			const cell = document.createElement('td');

			cell.colSpan = 6;
			cell.textContent = t('No data found');
			row.append(cell);
			tbody.append(row);
		}
		else {
			for (const container of response.containers) {
				const row = document.createElement('tr');
				const name_cell = document.createElement('td');
				const link = document.createElement('a');

				link.href = dockerUrl(container.hostid, container.name);
				link.textContent = container.name;
				name_cell.append(link);

				row.append(
					name_cell,
					makeCell(container.note),
					makeCell(container.host),
					makeCell(container.ip),
					makeCell(container.dns),
					makeCell(container.host_notes)
				);
				tbody.append(row);
			}
		}

		thead.append(header);
		table.append(thead, tbody);
		head.append(title);
		body.append(table);
		section.append(head, body);

		if (response.total > 0) {
			const footer = document.createElement('div');

			footer.className = 'section-foot';
			footer.textContent = `Displaying ${response.containers.length} of ${response.total} found`;
			section.append(footer);
		}

		return section;
	}

	function enhanceSearch() {
		const hosts_section = document.getElementById('search_hosts');
		const search = new URL(window.location.href).searchParams.get('search')?.trim() || '';

		if (hosts_section === null || search === ''
				|| document.getElementById('mnz-docker-search-containers') !== null) {
			return;
		}

		fetch(integrationUrl('search', {search}))
			.then((response) => response.json())
			.then((response) => {
				if ('error' in response || document.getElementById('mnz-docker-search-containers') !== null) {
					return;
				}

				hosts_section.insertAdjacentElement('afterend', buildContainerSearchSection(response));
			})
			.catch(() => {});
	}

	function scan() {
		clearTimeout(scan_timer);
		scan_timer = setTimeout(() => {
			installHostMenuIntegration();

			if (ACTION === 'host.list') {
				enhanceConfigurationHosts();
			}
			else if (ACTION === 'host.view') {
				enhanceMonitoringHosts();
			}
			else if (ACTION === 'search') {
				enhanceSearch();
			}
		}, 0);
	}

	document.addEventListener('DOMContentLoaded', () => {
		scan();

		if (ACTION === 'host.view') {
			new MutationObserver(scan).observe(document.body, {childList: true, subtree: true});
		}
	});
})();

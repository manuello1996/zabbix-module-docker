<?php declare(strict_types = 0);

namespace Modules\MonzphereDocker\Actions;

use API;
use CCol;
use CController;
use CControllerResponseData;
use CDiv;
use CLink;
use CLinkAction;
use CMenuPopupHelper;
use CPagerHelper;
use CProfile;
use CRoleHelper;
use CSettingsHelper;
use CUrl;
use CSeverityHelper;
use Manager;
use CSpan;
use CTableInfo;
use CTag;
use CWebUser;
use Modules\MonzphereDocker\Includes\DockerCollector;
use Modules\MonzphereDocker\Includes\DockerFormatter;

class DockerTab extends CController {
	public const TIME_PROFILE_IDX = 'web.monzphere.docker.filter';

	public const PROFILE_ACTIVE_TAB = 'web.monzphere.docker.active_tab';

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' =>	'required|db hosts.hostid',
			'tab' =>	'required|in latest,problems,graphs,web,inventory,node,images,volumes,networks,docker',
			'page' =>	'ge 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => _('Invalid request.')
			])]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		$tab_rules = [
			'latest' => CRoleHelper::UI_MONITORING_LATEST_DATA,
			'problems' => CRoleHelper::UI_MONITORING_PROBLEMS,
			'graphs' => CRoleHelper::UI_MONITORING_HOSTS,
			'web' => CRoleHelper::UI_MONITORING_HOSTS,
			'inventory' => CRoleHelper::UI_INVENTORY_HOSTS,
			'node' => CRoleHelper::UI_MONITORING_LATEST_DATA,
			'images' => CRoleHelper::UI_MONITORING_LATEST_DATA,
			'volumes' => CRoleHelper::UI_MONITORING_LATEST_DATA,
			'networks' => CRoleHelper::UI_MONITORING_LATEST_DATA,
			'docker' => CRoleHelper::UI_MONITORING_LATEST_DATA
		];

		if (!CWebUser::checkAccess($tab_rules[$this->getInput('tab')])) {
			return false;
		}

		return (bool) API::Host()->get([
			'output' => [],
			'hostids' => $this->getInput('hostid')
		]);
	}

	protected function doAction(): void {
		$hostid = $this->getInput('hostid');
		$tab = $this->getInput('tab');
		$page = (int) $this->getInput('page', 1);

		CProfile::update(self::PROFILE_ACTIVE_TAB, $tab === 'docker' ? '' : $tab, PROFILE_TYPE_STR);

		if ($tab === 'docker') {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['html' => ''])]));

			return;
		}

		switch ($tab) {
			case 'latest':
				$panel = $this->makeLatestPanel($hostid, $page);
				break;

			case 'problems':
				$panel = $this->makeProblemsPanel($hostid, $page);
				break;

			case 'graphs':
				$panel = $this->makeGraphsPanel($hostid);
				break;

			case 'node':
				$panel = $this->makeNodePanel($hostid);
				break;

			case 'images':
				$panel = $this->makeImagesPanel($hostid, $page);
				break;

			case 'volumes':
				$panel = $this->makeVolumesPanel($hostid, $page);
				break;

			case 'networks':
				$panel = $this->makeNetworksPanel($hostid);
				break;

			case 'web':
				$panel = $this->makeWebPanel($hostid, $page);
				break;

			default:
				$panel = $this->makeInventoryPanel($hostid);
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'html' => $panel->toString()
		])]));
	}

	private function makeLatestPanel(string $hostid, int $page): CDiv {
		$search_limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'lastvalue', 'lastclock', 'units', 'value_type'],
			'selectValueMap' => ['mappings'],
			'hostids' => $hostid,
			'monitored' => true,
			'webitems' => true,
			'sortfield' => 'name',
			'limit' => $search_limit + 1
		]);

		$paging = CPagerHelper::paginate($page, $items, ZBX_SORT_UP, $this->getTabUrl('latest'));

		$table = (new CTableInfo())
			->setHeader([_('Name'), _('Last check'), _('Last value')])
			->setNoDataMessage(_('No data found.'));

		foreach ($items as $item) {
			$has_value = $item['lastclock'] > 0;

			$table->addRow([
				$item['name'],
				$has_value
					? (new CSpan(zbx_date2age($item['lastclock']).' '._('ago')))
						->setTitle(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $item['lastclock']))
					: '-',
				$has_value
					? (new CSpan(formatHistoryValue($item['lastvalue'], $item)))
						->addClass('mnz-docker-latest-value')
					: '-'
			]);
		}

		return $this->wrapPanel(_('Latest data'), new CDiv([$table, $paging]));
	}

	private function getTabUrl(string $tab): CUrl {
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'monzphere.docker.tab')
			->setArgument('hostid', $this->getInput('hostid'))
			->setArgument('tab', $tab);
	}

	private function makeNodePanel(string $hostid): CDiv {
		$highlight_keys = [
			'docker.ping' => [_('Docker engine'), 'ping'],
			'docker.ncpu' => [_('CPUs'), 'raw'],
			'docker.mem.total' => [_('Memory'), 'bytes'],
			'docker.images.total' => [_('Images'), 'raw'],
			'docker.images_size' => [_('Images size'), 'bytes'],
			'docker.containers_size' => [_('Containers size'), 'bytes'],
			'docker.volumes_size' => [_('Volumes size'), 'bytes']
		];

		$property_keys = [
			'docker.operating_system' => _('Operating system'),
			'docker.os_type' => _('OS type'),
			'docker.architecture' => _('Architecture'),
			'docker.kernel_version' => _('Kernel version'),
			'docker.server_version' => _('Docker version'),
			'docker.driver' => _('Storage driver'),
			'docker.logging_driver' => _('Logging driver'),
			'docker.cgroup_driver' => _('Cgroup driver'),
			'docker.default_runtime' => _('Default runtime'),
			'docker.root_dir' => _('Root directory'),
			'docker.goroutines' => _('Goroutines'),
			'docker.nfd' => _('File descriptors')
		];

		$items = API::Item()->get([
			'output' => ['itemid', 'key_', 'lastvalue', 'lastclock'],
			'hostids' => $hostid,
			'filter' => ['key_' => array_merge(array_keys($highlight_keys), array_keys($property_keys))],
			'monitored' => true
		]);

		$values = [];

		foreach ($items as $item) {
			if ($item['lastclock'] > 0) {
				$values[$item['key_']] = $item['lastvalue'];
			}
		}

		$pills = [];

		foreach ($highlight_keys as $key => [$label, $format]) {
			$value = $values[$key] ?? null;

			if ($format === 'ping') {
				$pill_value = $value !== null && (int) $value === 1
					? (new CSpan(_('Up')))->addClass('mnz-docker-status-running')
					: (new CSpan(_('Down')))->addClass('mnz-docker-status-stopped');
			}
			elseif ($value === null) {
				$pill_value = new CSpan('-');
			}
			else {
				$pill_value = (new CSpan($format === 'bytes' ? DockerFormatter::bytes($value) : $value))
					->addClass('mnz-docker-card-value');
			}

			$pills[] = (new CDiv([
				(new CSpan($label))->addClass('mnz-docker-card-unit'),
				$pill_value
			]))->addClass('mnz-docker-stat');
		}

		$table = (new CTableInfo())
			->setHeader([_('Parameter'), _('Value')])
			->setNoDataMessage(_('No node information collected yet.'));

		foreach ($property_keys as $key => $label) {
			if (array_key_exists($key, $values)) {
				$table->addRow([$label, $values[$key]]);
			}
		}

		return $this->wrapPanel(_('Node info'), new CDiv([
			(new CDiv($pills))->addClass('mnz-docker-hostbar-stats')->addClass('mnz-docker-node-stats'),
			$table
		]));
	}

	private function makeImagesPanel(string $hostid, int $page): CDiv {
		$search_limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'key_', 'units', 'value_type', 'lastvalue', 'lastclock'],
			'selectValueMap' => ['mappings'],
			'hostids' => $hostid,
			'search' => ['key_' => ['docker.image.created[', 'docker.image.size[']],
			'searchByAny' => true,
			'startSearch' => true,
			'monitored' => true,
			'limit' => $search_limit
		]);

		$images = [];

		foreach ($items as $item) {
			if (preg_match('/^docker\.image\.(created|size)\["?([^"\]]+)"?\]$/', $item['key_'], $matches) != 1
					|| $item['lastclock'] == 0) {
				continue;
			}

			[, $field, $image_id] = $matches;

			if (!array_key_exists($image_id, $images)) {
				$name = preg_replace('/^Image\s+|:\s+[^:]+$/u', '', $item['name']);

				$images[$image_id] = [
					'id' => $image_id,
					'name' => $name,
					'created' => null,
					'size' => null,
					'size_formatted' => null
				];
			}

			if ($field === 'created') {
				$images[$image_id]['created'] = (int) $item['lastvalue'];
			}
			else {
				$images[$image_id]['size'] = (float) $item['lastvalue'];
				$images[$image_id]['size_formatted'] = formatHistoryValue($item['lastvalue'], $item);
			}
		}

		usort($images, static fn (array $a, array $b): int => ($b['size'] ?? -1) <=> ($a['size'] ?? -1));

		$node = DockerCollector::nodeInfo($hostid);

		$pills = [];

		$pill_defs = [
			[_('Images'), $node['images_total'] !== null ? $node['images_total'] : count($images)],
			[_('Total size'), $node['images_size'] !== null ? DockerFormatter::bytes($node['images_size']) : '-']
		];

		foreach ($pill_defs as [$label, $value]) {
			$pills[] = (new CDiv([
				(new CSpan($label))->addClass('mnz-docker-card-unit'),
				(new CSpan($value))->addClass('mnz-docker-card-value')
			]))->addClass('mnz-docker-stat');
		}

		$paging = CPagerHelper::paginate($page, $images, ZBX_SORT_UP, $this->getTabUrl('images'));

		$table = (new CTableInfo())
			->setHeader([_('Image'), _('ID'), _('Size'), _('Created')])
			->setNoDataMessage(_('No image data collected yet.'));

		foreach ($images as $image) {
			$is_dangling = strpos($image['name'], '<none>') !== false;

			$short_id = preg_replace('/^sha256:/', '', $image['id']);
			$short_id = substr($short_id, 0, 12);

			$table->addRow([
				(new CSpan($is_dangling ? _('<untagged>') : $image['name']))
					->addClass('mnz-docker-image-name')
					->addClass($is_dangling ? 'mnz-docker-muted' : null)
					->setTitle($image['name']),
				(new CSpan($short_id))->addClass('mnz-docker-image-id')->setTitle($image['id']),
				$image['size_formatted'] ?? '-',
				$image['created'] !== null ? zbx_date2str(DATE_TIME_FORMAT, $image['created']) : '-'
			]);
		}

		return $this->wrapPanel(_('Images'), new CDiv([
			(new CDiv($pills))->addClass('mnz-docker-hostbar-stats')->addClass('mnz-docker-node-stats'),
			$table,
			$paging
		]));
	}

	private function textItemValue(string $hostid, string $key): ?string {
		$items = API::Item()->get([
			'output' => ['itemid', 'value_type'],
			'hostids' => $hostid,
			'filter' => ['key_' => $key],
			'monitored' => true,
			'preservekeys' => true
		]);

		if (!$items) {
			return null;
		}

		$last_values = Manager::History()->getLastValues($items, 1, timeUnitToSeconds(
			CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD)
		));

		$itemid = array_key_first($items);

		return array_key_exists($itemid, $last_values) ? $last_values[$itemid][0]['value'] : null;
	}

	private function makeVolumesPanel(string $hostid, int $page): CDiv {
		$raw = $this->textItemValue($hostid, 'docker.volumes.raw');

		if ($raw === null) {
			return $this->wrapPanel(_('Volumes'),
				(new CTableInfo())->setNoDataMessage(
					_('No volume data collected yet. Data appears after the next "Docker: Volumes" item update.')
				)
			);
		}

		$volumes = json_decode($raw, true) ?: [];

		$total_size = 0;
		$in_use = 0;

		foreach ($volumes as $volume) {
			$total_size += (float) (($volume['UsageData'] ?? [])['Size'] ?? 0);
			$in_use += (int) ((($volume['UsageData'] ?? [])['RefCount'] ?? 0) > 0);
		}

		usort($volumes, static fn (array $a, array $b): int =>
			(($b['UsageData'] ?? [])['Size'] ?? 0) <=> (($a['UsageData'] ?? [])['Size'] ?? 0)
		);

		$pills = [];

		$pill_defs = [
			[_('Volumes'), (string) count($volumes)],
			[_('Total size'), DockerFormatter::bytes($total_size)],
			[_('In use'), (string) $in_use],
			[_('Unused'), (string) (count($volumes) - $in_use)]
		];

		foreach ($pill_defs as [$label, $value]) {
			$pills[] = (new CDiv([
				(new CSpan($label))->addClass('mnz-docker-card-unit'),
				(new CSpan($value))->addClass('mnz-docker-card-value')
			]))->addClass('mnz-docker-stat');
		}

		$paging = CPagerHelper::paginate($page, $volumes, ZBX_SORT_UP, $this->getTabUrl('volumes'));

		$table = (new CTableInfo())
			->setHeader([_('Volume'), _('Mountpoint'), _('Size'), _('Ref count')])
			->setNoDataMessage(_('No volumes found.'));

		foreach ($volumes as $volume) {
			$name = (string) ($volume['Name'] ?? '');
			$is_anonymous = preg_match('/^[0-9a-f]{64}$/', $name) == 1;
			$usage = $volume['UsageData'] ?? [];
			$refcount = (int) ($usage['RefCount'] ?? 0);

			$table->addRow([
				(new CSpan($is_anonymous ? substr($name, 0, 12) : $name))
					->addClass('mnz-docker-image-name')
					->addClass($is_anonymous ? 'mnz-docker-muted' : null)
					->setTitle($name),
				(new CSpan((string) ($volume['Mountpoint'] ?? '-')))
					->addClass('mnz-docker-image-id')
					->setTitle((string) ($volume['Mountpoint'] ?? '')),
				DockerFormatter::bytes(($usage['Size'] ?? 0)),
				(new CSpan((string) $refcount))
					->addClass($refcount > 0 ? 'mnz-docker-status-running' : 'mnz-docker-muted')
			]);
		}

		return $this->wrapPanel(_('Volumes'), new CDiv([
			(new CDiv($pills))->addClass('mnz-docker-hostbar-stats')->addClass('mnz-docker-node-stats'),
			$table,
			$paging
		]));
	}

	private function makeNetworksPanel(string $hostid): CDiv {
		$items = API::Item()->get([
			'output' => ['itemid', 'key_', 'value_type'],
			'hostids' => $hostid,
			'search' => ['key_' => 'docker.container_info.networks['],
			'startSearch' => true,
			'monitored' => true,
			'preservekeys' => true
		]);

		if (!$items) {
			return $this->wrapPanel(_('Networks'),
				(new CTableInfo())->setNoDataMessage(
					_('No network data collected yet. Data appears after the next containers discovery cycle.')
				)
			);
		}

		$last_values = Manager::History()->getLastValues($items, 1, timeUnitToSeconds(
			CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD)
		));

		$container_state = [];

		foreach (DockerCollector::collect($hostid)['containers'] as $container) {
			$row = DockerFormatter::formatContainer($container);
			$container_state[$container['name']] = $row['status_kind'];
		}

		$networks = [];
		$memberships = [];

		foreach ($items as $itemid => $item) {
			if (preg_match('/^docker\.container_info\.networks\["?\/?([^"\]]+)"?\]$/', $item['key_'], $matches) != 1
					|| !array_key_exists($itemid, $last_values)) {
				continue;
			}

			$name = $matches[1];
			$nets = json_decode($last_values[$itemid][0]['value'], true);

			if (!is_array($nets)) {
				continue;
			}

			foreach ($nets as $net_name => $net) {
				$networks[$net_name][$name] = (string) ($net['IPAddress'] ?? '');
				$memberships[$name][] = $net_name;
			}
		}

		if (!$networks) {
			return $this->wrapPanel(_('Networks'),
				(new CTableInfo())->setNoDataMessage(_('No container network data available.'))
			);
		}

		uasort($networks, static fn (array $a, array $b): int => count($b) <=> count($a));

		$legend = (new CDiv([
			(new CSpan([(new CSpan())->addClass('mnz-docker-dot')->addClass('mnz-docker-status-running'),
				' '._('Running')]))->addClass('mnz-docker-topo-legend-item'),
			(new CSpan([(new CSpan())->addClass('mnz-docker-dot')->addClass('mnz-docker-status-stopped'),
				' '._('Problem')]))->addClass('mnz-docker-topo-legend-item'),
			(new CSpan([(new CSpan('⇄'))->addClass('mnz-docker-topo-multi'), ' '._('Multiple networks')]))
				->addClass('mnz-docker-topo-legend-item'),
			(new CSpan(count($networks).' '._('networks').' · '.count($memberships).' '._('containers')))
				->addClass('mnz-docker-graphs-count')
		]))->addClass('mnz-docker-topo-legend');

		$zones = new CDiv();
		$zones->addClass('mnz-docker-topo');

		foreach ($networks as $net_name => $members) {
			$nodes = new CDiv();
			$nodes->addClass('mnz-docker-topo-nodes');

			ksort($members);

			foreach ($members as $container => $ip) {
				$kind = $container_state[$container] ?? 'up';
				$extra_nets = array_values(array_diff($memberships[$container] ?? [], [$net_name]));

				$nodes->addItem(
					(new CDiv([
						(new CSpan())->addClass('mnz-docker-dot')
							->addClass($kind === 'up' ? 'mnz-docker-status-running' : 'mnz-docker-status-stopped'),
						(new CDiv([
							(new CSpan($container))->addClass('mnz-docker-topo-name'),
							(new CSpan($ip !== '' ? $ip : '-'))->addClass('mnz-docker-topo-ip')
						]))->addClass('mnz-docker-topo-text'),
						$extra_nets
							? (new CSpan('⇄'))
								->addClass('mnz-docker-topo-multi')
								->setTitle(_('Also in').': '.implode(', ', $extra_nets))
							: null
					]))
						->addClass('mnz-docker-topo-node')
						->addClass($kind === 'restarting' ? 'mnz-docker-topo-node-bad' : null)
						->setAttribute('data-mnz-container', $container)
						->setAttribute('role', 'button')
						->setAttribute('tabindex', '0')
				);
			}

			$zones->addItem(
				(new CDiv([
					(new CDiv([
						(new CSpan($net_name))->addClass('mnz-docker-topo-zone-name'),
						(new CSpan((string) count($members)))->addClass('mnz-docker-graphgroup-count')
					]))->addClass('mnz-docker-topo-zone-head'),
					$nodes
				]))->addClass('mnz-docker-topo-zone')
			);
		}

		return $this->wrapPanel(_('Networks'), new CDiv([$legend, $zones]));
	}

	private function makeProblemsPanel(string $hostid, int $page): CDiv {
		$search_limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$problems = API::Problem()->get([
			'output' => ['eventid', 'objectid', 'name', 'clock', 'severity', 'acknowledged'],
			'hostids' => $hostid,
			'recent' => true,
			'sortfield' => 'eventid',
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => $search_limit + 1
		]);

		$paging = CPagerHelper::paginate($page, $problems, ZBX_SORT_DOWN, $this->getTabUrl('problems'));

		$table = (new CTableInfo())
			->setHeader([_('Time'), _('Severity'), _('Problem'), _('Duration'), _('Ack')])
			->setNoDataMessage(_('No problems found.'));

		$backurl = (new CUrl('zabbix.php'))
			->setArgument('action', 'monzphere.docker.view')
			->setArgument('filter_hostid', [$hostid])
			->getUrl();

		foreach ($problems as $problem) {
			$severity = (int) $problem['severity'];

			$name_link = (new CLinkAction($problem['name']))
				->setMenuPopup(CMenuPopupHelper::getTrigger([
					'triggerid' => $problem['objectid'],
					'backurl' => $backurl,
					'eventid' => $problem['eventid'],
					'show_update_problem' => true
				]));

			$clock_link = new CLink(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['clock']),
				(new CUrl('tr_events.php'))
					->setArgument('triggerid', $problem['objectid'])
					->setArgument('eventid', $problem['eventid'])
			);

			$table->addRow([
				$clock_link,
				(new CCol(CSeverityHelper::getName($severity)))
					->addClass(CSeverityHelper::getStyle($severity)),
				$name_link,
				zbx_date2age($problem['clock']),
				$problem['acknowledged'] == EVENT_ACKNOWLEDGED
					? (new CSpan(_('Yes')))->addClass('mnz-docker-status-running')
					: (new CSpan(_('No')))->addClass('mnz-docker-status-stopped')
			]);
		}

		return $this->wrapPanel(_('Problems'), new CDiv([$table, $paging]));
	}

	private function makeGraphsPanel(string $hostid): CDiv {
		$search_limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$graphs = API::Graph()->get([
			'output' => ['graphid', 'name', 'graphtype'],
			'hostids' => $hostid,
			'sortfield' => 'name',
			'limit' => $search_limit
		]);

		if (!$graphs) {
			return $this->wrapPanel(_('Graphs'),
				(new CTableInfo())->setNoDataMessage(_('No graphs found.'))
			);
		}

		$timeline = getTimeSelectorPeriod([
			'profileIdx' => self::TIME_PROFILE_IDX,
			'profileIdx2' => 0
		]);

		$groups = [];

		foreach ($graphs as $graph) {
			if (preg_match('/^Container\s+\/?([^:]+):\s*(.+)$/', $graph['name'], $matches) == 1) {
				$groups[$matches[1]][] = ['graph' => $graph, 'title' => $matches[2]];
			}
			else {
				$groups[''][] = ['graph' => $graph, 'title' => $graph['name']];
			}
		}

		uksort($groups, static function (string $a, string $b): int {
			if ($a === '' || $b === '') {
				return $a === '' ? -1 : 1;
			}

			return strnatcasecmp($a, $b);
		});

		$total = count($graphs);
		$expand_all = $total <= 6;

		$panel = new CDiv();

		$panel->addItem(
			(new CDiv([
				(new CTag('input', false))
					->setId('mnz-docker-graphs-search')
					->setAttribute('type', 'search')
					->setAttribute('placeholder', _('Filter graphs...'))
					->setAttribute('aria-label', _('Filter graphs'))
					->setAttribute('autocomplete', 'off')
					->addClass('mnz-docker-search'),
				(new CSpan($total.' '._('graphs').' · '.count($groups).' '._('groups')
					.($total == $search_limit ? ' ('._('limited').')' : '')
				))->addClass('mnz-docker-graphs-count')
			]))->addClass('mnz-docker-toolbar')
		);

		foreach ($groups as $group_name => $items) {
			$body = (new CDiv())->addClass('mnz-docker-graphgroup-body');

			foreach ($items as ['graph' => $graph, 'title' => $title]) {
				$dims = getGraphDims($graph['graphid']);

				$is_pie = in_array((int) $dims['graphtype'], [GRAPH_TYPE_PIE, GRAPH_TYPE_EXPLODED], true);

				$body->addItem(
					(new CDiv([
						(new CTag('h5', true, $title))->addClass('mnz-docker-graph-title'),
						(new CTag('img', false))
							->setAttribute('alt', $graph['name'])
							->setAttribute('loading', 'lazy')
							->addClass('mnz-docker-chart-img')
							->setAttribute('data-mnz-shift',
								(string) ($is_pie ? 0 : $dims['shiftXleft'] + $dims['shiftXright'] + 1)
							)
							->setAttribute('data-mnz-chart', ($is_pie ? 'chart6.php' : 'chart2.php').'?'
								.http_build_query([
									'graphid' => $graph['graphid'],
									'from' => $timeline['from'],
									'to' => $timeline['to'],
									'height' => $dims['graphHeight'],
									'profileIdx' => self::TIME_PROFILE_IDX
								]))
					]))
						->addClass('mnz-docker-graph')
						->setAttribute('data-mnz-graph', mb_strtolower($title))
				);
			}

			if (!$expand_all) {
				$body->setAttribute('hidden', 'hidden');
			}

			$head = (new CTag('button', true, [
				(new CSpan())->addClass('mnz-docker-graphgroup-caret'),
				(new CSpan($group_name === '' ? _('Node') : $group_name))->addClass('mnz-docker-graphgroup-name'),
				(new CSpan((string) count($items)))->addClass('mnz-docker-graphgroup-count')
			]))
				->setAttribute('type', 'button')
				->addClass('mnz-docker-graphgroup-head')
				->addClass($expand_all ? 'mnz-docker-graphgroup-open' : null)
				->setAttribute('aria-expanded', $expand_all ? 'true' : 'false');

			$panel->addItem(
				(new CDiv([$head, $body]))
					->addClass('mnz-docker-graphgroup')
					->setAttribute('data-mnz-graphgroup', mb_strtolower((string) $group_name))
			);
		}

		return $this->wrapPanel(_('Graphs'), $panel);
	}

	private function makeWebPanel(string $hostid, int $page): CDiv {
		$search_limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$httptests = API::HttpTest()->get([
			'output' => ['httptestid', 'name', 'status', 'delay', 'nextcheck'],
			'hostids' => $hostid,
			'sortfield' => 'name',
			'limit' => $search_limit + 1
		]);

		$paging = CPagerHelper::paginate($page, $httptests, ZBX_SORT_UP, $this->getTabUrl('web'));

		$table = (new CTableInfo())
			->setHeader([_('Name'), _('Interval'), _('Status')])
			->setNoDataMessage(_('No web scenarios found.'));

		foreach ($httptests as $httptest) {
			$enabled = (int) $httptest['status'] === HTTPTEST_STATUS_ACTIVE;

			$table->addRow([
				$httptest['name'],
				$httptest['delay'],
				$enabled
					? (new CSpan(_('Enabled')))->addClass('mnz-docker-status-running')
					: (new CSpan(_('Disabled')))->addClass('mnz-docker-status-stopped')
			]);
		}

		return $this->wrapPanel(_('Web scenarios'), new CDiv([$table, $paging]));
	}

	private function makeInventoryPanel(string $hostid): CDiv {
		$hosts = API::Host()->get([
			'output' => ['hostid'],
			'selectInventory' => true,
			'hostids' => $hostid
		]);

		$inventory = $hosts ? array_filter((array) $hosts[0]['inventory'], 'strlen') : [];
		unset($inventory['hostid'], $inventory['inventory_mode']);

		$table = (new CTableInfo())
			->setHeader([_('Field'), _('Value')])
			->setNoDataMessage(_('No inventory data found.'));

		$titles = array_column(getHostInventories(), 'title', 'db_field');

		foreach ($inventory as $field => $value) {
			$table->addRow([$titles[$field] ?? $field, $value]);
		}

		return $this->wrapPanel(_('Inventory'), $table);
	}

	private function wrapPanel(string $title, CTag $body): CDiv {
		return (new CDiv([
			(new CTag('h4', true, $title))->addClass('mnz-docker-section-title'),
			$body
		]))->addClass('mnz-docker-section');
	}
}

<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Actions;

use API;
use CCol;
use CController;
use CControllerResponseData;
use CDiv;
use CLink;
use CLinkAction;
use CMenuPopupHelper;
use CPagerHelper;
use CRoleHelper;
use CRow;
use CSettingsHelper;
use CUrl;
use CSeverityHelper;
use CSpan;
use CTableInfo;
use CTag;
use CWebUser;
use Modules\MonitorDocker\Includes\DockerCollector;
use Modules\MonitorDocker\Includes\DockerFormatter;

class CControllerDockerTab extends CController {
	public const TIME_PROFILE_IDX = 'web.docker.filter';

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' =>	'required|db hosts.hostid',
			'tab' =>	'required|in problems,graphs,node,images,volumes,mounts,networks,compose,docker',
			'page' =>	'ge 1',
			'docker_page' => 'ge 1',
			'other_page' => 'ge 1',
			'problem_section' => 'in docker,other'
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
			'problems' => CRoleHelper::UI_MONITORING_PROBLEMS,
			'graphs' => CRoleHelper::UI_MONITORING_HOSTS,
			'node' => CRoleHelper::UI_MONITORING_LATEST_DATA,
			'images' => CRoleHelper::UI_MONITORING_LATEST_DATA,
			'volumes' => CRoleHelper::UI_MONITORING_LATEST_DATA,
			'mounts' => CRoleHelper::UI_MONITORING_LATEST_DATA,
			'networks' => CRoleHelper::UI_MONITORING_LATEST_DATA,
			'compose' => CRoleHelper::UI_MONITORING_LATEST_DATA,
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

		if ($tab === 'docker') {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['html' => ''])]));

			return;
		}

		switch ($tab) {
			case 'problems':
				$panel = $this->makeProblemsPanel($hostid,
					(int) $this->getInput('docker_page', $page),
					(int) $this->getInput('other_page', $page)
				);
				break;

			case 'graphs':
				$panel = $this->makeGraphsPanel($hostid);
				break;

			case 'node':
				$panel = $this->makeNodePanel($hostid);
				break;

			case 'images':
				$panel = $this->makeImagesPanel($hostid);
				break;

			case 'volumes':
				$panel = $this->makeVolumesPanel($hostid, $page);
				break;

			case 'mounts':
				$panel = $this->makeMountsPanel($hostid, $page);
				break;

			case 'networks':
				$panel = $this->makeNetworksPanel($hostid);
				break;

			case 'compose':
				$panel = $this->makeComposePanel($hostid, $page);
				break;

			default:
				$panel = $this->makeNodePanel($hostid);
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'html' => $panel->toString()
		])]));
	}

	private function getTabUrl(string $tab): CUrl {
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'docker.tab')
			->setArgument('hostid', $this->getInput('hostid'))
			->setArgument('tab', $tab);
	}

	private function tabDataSources(string $tab): array {
		return match ($tab) {
			'node' => [
				['docker.ping', 'docker.ping'],
				['docker.images_size', 'docker.data_usage']
			],
			'images' => [
				['docker.image.created[', 'docker.images']
			],
			'volumes' => [['docker.volumes.raw', 'docker.data_usage']],
			'mounts' => [['docker.containers.mounts', 'docker.containers']],
			'networks' => [
				['docker.container_info.networks[', 'docker.container_info[']
			],
			'compose' => [
				['docker.containers.labels', 'docker.containers.labels.raw']
			],
			'graphs' => [
				['docker.container_stats.cpu_pct_usage[', 'docker.container_stats[']
			],
			'problems' => [[null, null]],
			default => []
		};
	}

	private function makeDataSourceStrip(string $hostid, string $tab): ?CDiv {
		$sources = $this->tabDataSources($tab);

		if (!$sources) {
			return null;
		}

		$clocks = [];
		$delays = [];
		$is_live = false;

		foreach ($sources as $source) {
			[$stored_key, $master_key] = $source;

			if ($stored_key === null) {
				$is_live = true;
				continue;
			}

			foreach ($this->dataSourceItems($hostid, $stored_key, ['lastclock']) as $item) {
				if ((int) $item['lastclock'] > 0) {
					$clocks[] = (int) $item['lastclock'];
				}
			}

			foreach ($this->dataSourceItems($hostid, $master_key, ['delay']) as $item) {
				if ($item['delay'] !== '' && $item['delay'] !== '0') {
					$delays[] = (string) $item['delay'];
				}
			}
		}

		if ($is_live) {
			$updated = _('Live');
			$interval = _('On page load');
		}
		else {
			$updated = $clocks
				? zbx_date2str(DATE_TIME_FORMAT_SECONDS, min($clocks)).' ('.zbx_date2age(min($clocks)).')'
				: _('No value received');
			$delays = array_values(array_unique($delays));
			$interval = $delays ? implode(', ', $delays) : '-';
		}

		return (new CDiv([
			(new CSpan([_('Updated').': ', $updated])),
			(new CSpan([_('Interval').': ', $interval]))
		]))->addClass('docker-data-freshness');
	}

	private function dataSourceItems(string $hostid, string $key, array $output): array {
		$query = [
			'output' => $output,
			'hostids' => $hostid,
			'monitored' => true
		];

		if (str_ends_with($key, '[')) {
			$query['search'] = ['key_' => $key];
			$query['startSearch'] = true;
		}
		else {
			$query['filter'] = ['key_' => $key];
		}

		return API::Item()->get($query);
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
			'lxp.docker.compose_version' => _('Docker compose version'),
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
					? (new CSpan(_('Up')))->addClass('docker-status-running')
					: (new CSpan(_('Down')))->addClass('docker-status-stopped');
			}
			elseif ($value === null) {
				$pill_value = new CSpan('-');
			}
			else {
				$pill_value = (new CSpan($format === 'bytes' ? DockerFormatter::bytes($value) : $value))
					->addClass('docker-card-value');
			}

			$pills[] = (new CDiv([
				(new CSpan($label))->addClass('docker-card-unit'),
				$pill_value
			]))->addClass('docker-stat');
		}

		$table = (new CTableInfo())
			->setHeader([_('Parameter'), _('Value')])
			->setNoDataMessage(_('No node information collected yet.'));

		foreach ($property_keys as $key => $label) {
			if (array_key_exists($key, $values)) {
				$table->addRow([$label, $values[$key]]);
			}
		}

		$body = [
			(new CDiv($pills))->addClass('docker-hostbar-stats')->addClass('docker-node-stats'),
			$table
		];

		if (CWebUser::checkAccess(CRoleHelper::UI_INVENTORY_HOSTS)) {
			$body[] = (new CTag('h5', true, _('Inventory')))->addClass('docker-node-inventory-title');
			$body[] = $this->makeInventoryTable($hostid);
		}

		return $this->wrapPanel(_('Node info'), new CDiv($body));
	}

	private function makeImagesPanel(string $hostid): CDiv {
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
					'size_formatted' => null,
					'containers' => []
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

		$snapshot = $this->getContainerDataset($hostid, 'docker.containers.image_usage');
		$usage_available = $snapshot['status'] === 'ok';
		$usage_complete = false;
		$unmatched_containers = 0;

		if ($usage_available) {
			$image_ids = [];
			$image_names = [];
			$container_count = 0;
			$matched_containers = 0;

			foreach ($images as $image_key => $image) {
				$normalized_id = preg_replace('/^sha256:/', '', $image['id']);
				$image_ids[$normalized_id] = $image_key;
				$image_names[$image['name']] = $image_key;
			}

			$container_image_ids = $this->getContainerImageIds($hostid);

			foreach ($snapshot['containers'] as $container) {
				if (!is_array($container)) {
					continue;
				}

				$names = array_values(array_filter(
					array_map(
						static fn ($name): string => ltrim((string) $name, '/'),
						(array) ($container['Names'] ?? [])
					),
					'strlen'
				));

				if (!$names) {
					continue;
				}

				$container_count++;
				$container_name = $names[0];
				$image_id = (string) (
					$container['ImageID'] ?? $container_image_ids[$container_name] ?? ''
				);
				$normalized_id = preg_replace('/^sha256:/', '', $image_id);
				$image_ref = (string) ($container['Image'] ?? '');
				$image_key = $normalized_id !== '' && array_key_exists($normalized_id, $image_ids)
					? $image_ids[$normalized_id]
					: ($image_names[$image_ref] ?? null);

				if ($image_key === null) {
					$unmatched_containers++;
					continue;
				}

				$matched_containers++;
				$images[$image_key]['containers'][] = [
					'name' => $container_name,
					'id' => (string) ($container['Id'] ?? ''),
					'state' => strtolower((string) ($container['State'] ?? 'unknown'))
				];
			}

			foreach ($images as &$image) {
				usort($image['containers'], static fn (array $a, array $b): int =>
					($a['state'] === 'running' ? 0 : 1) <=> ($b['state'] === 'running' ? 0 : 1)
						?: strnatcasecmp($a['name'], $b['name'])
				);
			}
			unset($image);

			$usage_complete = $container_count === $matched_containers;
		}

		usort($images, static fn (array $a, array $b): int => ($b['size'] ?? -1) <=> ($a['size'] ?? -1));

		$node = DockerCollector::nodeInfo($hostid);
		$used_images = count(array_filter($images, static fn (array $image): bool => (bool) $image['containers']));

		$pills = [];

		$pill_defs = [
			[_('Images'), $node['images_total'] !== null ? $node['images_total'] : count($images)],
			[_('Total size'), $node['images_size'] !== null ? DockerFormatter::bytes($node['images_size']) : '-']
		];

		if ($usage_available) {
			$pill_defs[] = [_('Used'), $used_images];
			$pill_defs[] = $usage_complete
				? [_('Unused'), count($images) - $used_images]
				: [_('Unmatched containers'), $unmatched_containers];
		}

		foreach ($pill_defs as [$label, $value]) {
			$pills[] = (new CDiv([
				(new CSpan($label))->addClass('docker-card-unit'),
				(new CSpan($value))->addClass('docker-card-value')
			]))->addClass('docker-stat');
		}

		$make_sort_header = static function (string $label, string $field): CSpan {
			return (new CSpan([$label, (new CSpan())->addClass('docker-sort-arrow')]))
				->addClass('docker-image-sort')
				->setAttribute('data-image-sort', $field)
				->setAttribute('role', 'button')
				->setAttribute('tabindex', '0');
		};

		$table = (new CTableInfo())
			->setId('docker-images-table')
			->setHeader([
				_('Image'),
				_('ID'),
				$make_sort_header(_('Size'), 'size'),
				$make_sort_header(_('Created'), 'created'),
				_('Usage')
			])
			->setNoDataMessage(_('No image data collected yet.'));

		foreach ($images as $image) {
			$is_dangling = strpos($image['name'], '<none>') !== false;

			$short_id = preg_replace('/^sha256:/', '', $image['id']);
			$short_id = substr($short_id, 0, 12);
			$usage = (new CSpan(_('No data')))->addClass('docker-muted');

			if ($usage_available) {
				$running = count(array_filter(
					$image['containers'],
					static fn (array $container): bool => $container['state'] === 'running'
				));
				$stopped = count($image['containers']) - $running;

				if ($image['containers']) {
					$container_nodes = [];

					foreach ($image['containers'] as $container) {
						$container_nodes[] = (new CDiv([
							(new CSpan())->addClass('docker-dot')
								->addClass($container['state'] === 'running'
									? 'docker-status-running'
									: 'docker-status-stopped'
								),
							(new CLinkAction($container['name']))
								->setAttribute('data-container', $container['name'])
								->setTitle($container['id']),
							(new CSpan(ucfirst($container['state'])))
								->addClass('docker-image-usage-state')
						]))->addClass('docker-image-usage-container');
					}

					$usage = (new CTag('details', true, [
						(new CTag('summary', true, [
							(new CSpan(count($image['containers']).' '._('containers')))
								->addClass('docker-image-usage-total'),
							(new CSpan($running.' '._('running')))
								->addClass('docker-status-running'),
							(new CSpan($stopped.' '._('stopped')))
								->addClass($stopped > 0 ? 'docker-status-stopped' : 'docker-muted')
						]))->addClass('docker-image-usage-summary'),
						(new CDiv($container_nodes))->addClass('docker-image-usage-list')
					]))->addClass('docker-image-usage');
				}
				else {
					$usage = (new CSpan($usage_complete ? _('Unused') : _('No matched containers')))
						->addClass('docker-muted');
				}
			}

			$created_text = $image['created'] !== null
				? zbx_date2str(DATE_TIME_FORMAT, $image['created'])
				: '-';
			$created = new CSpan($created_text);

			if ($image['created'] !== null && $image['created'] > 0
					&& $image['created'] < time() - 365 * SEC_PER_DAY) {
				$created
					->addClass('docker-image-created-old')
					->setTitle(_('Image is older than 365 days.'));
			}

			$usage_filter = $usage_available
				? implode(' ', array_merge(
					[
						count($image['containers']).' '._('containers'),
						$running.' '._('running'),
						$stopped.' '._('stopped'),
						$image['containers'] ? '' : ($usage_complete ? _('Unused') : _('No matched containers'))
					],
					array_column($image['containers'], 'name')
				))
				: _('No data');

			$row = (new CRow([
				(new CSpan($is_dangling ? _('<untagged>') : $image['name']))
					->addClass('docker-image-name')
					->addClass($is_dangling ? 'docker-muted' : null)
					->setTitle($image['name']),
				(new CSpan($short_id))->addClass('docker-image-id')->setTitle($image['id']),
				$image['size_formatted'] ?? '-',
				$created,
				$usage
			]))
				->setAttribute('data-image-name', strtolower($image['name']))
				->setAttribute('data-image-sort-size', (string) ($image['size'] ?? -1))
				->setAttribute('data-image-sort-created', (string) ($image['created'] ?? -1))
				->setAttribute('data-image-usage', strtolower($usage_filter));

			$table->addRow($row);
		}

		$filters = [];

		foreach ([
			'name' => _('Name'),
			'usage' => _('Usage')
		] as $field => $label) {
			$filters[] = (new CTag('label', true, [
				(new CSpan($label))->addClass('docker-card-unit'),
				(new CTag('input', false))
					->setAttribute('type', 'search')
					->setAttribute('data-image-filter', $field)
					->setAttribute('placeholder', _('Filter').' '.$label)
					->setAttribute('autocomplete', 'off')
					->addClass('docker-search')
			]))->addClass('docker-image-filter');
		}

		return $this->wrapPanel(_('Images'), new CDiv([
			(new CDiv($pills))->addClass('docker-hostbar-stats')->addClass('docker-node-stats'),
			(new CDiv([
				(new CDiv($filters))->addClass('docker-image-filters'),
				(new CSpan(count($images).' '._('images')))
					->setId('docker-images-filter-count')
					->addClass('docker-graphs-count')
			]))->addClass('docker-toolbar')->addClass('docker-image-toolbar'),
			$table,
			(new CDiv([
				(new CSpan())->addClass('docker-image-age-legend-marker'),
				_('A red Created date indicates that the image is older than 365 days and should be updated or removed.')
			]))->addClass('docker-image-age-legend')
		]));
	}

	private function textItemValue(string $hostid, string $key): ?string {
		$items = API::Item()->get([
			'output' => ['lastvalue', 'lastclock'],
			'hostids' => $hostid,
			'filter' => ['key_' => $key],
			'monitored' => true,
			'limit' => 1
		]);

		if (!$items || !DockerCollector::hasRecentValue($items[0])) {
			return null;
		}

		return $items[0]['lastvalue'];
	}

	private function getContainerDataset(string $hostid, string $key): array {
		$items = API::Item()->get([
			'output' => ['lastvalue', 'lastclock'],
			'hostids' => $hostid,
			'filter' => ['key_' => $key],
			'monitored' => true,
			'limit' => 1
		]);

		if (!$items) {
			return ['status' => 'missing', 'containers' => [], 'lastclock' => 0];
		}

		$item = $items[0];

		if (!DockerCollector::hasRecentValue($item)) {
			return ['status' => 'missing', 'containers' => [], 'lastclock' => 0];
		}

		$containers = json_decode($item['lastvalue'], true);

		return is_array($containers)
			? [
				'status' => 'ok',
				'containers' => $containers,
				'lastclock' => (int) $item['lastclock']
			]
			: [
				'status' => 'invalid',
				'containers' => [],
				'lastclock' => (int) $item['lastclock']
			];
	}

	private function getContainerImageIds(string $hostid): array {
		$items = API::Item()->get([
			'output' => ['key_', 'lastvalue', 'lastclock'],
			'hostids' => $hostid,
			'search' => ['key_' => 'docker.container_info.image_id['],
			'startSearch' => true,
			'monitored' => true
		]);
		$image_ids = [];

		foreach ($items as $item) {
			if ($item['lastclock'] == 0
					|| preg_match(
						'/^docker\.container_info\.image_id\["?\/?([^"\]]+)"?\]$/',
						$item['key_'],
						$matches
					) != 1) {
				continue;
			}

			$image_ids[$matches[1]] = (string) $item['lastvalue'];
		}

		return $image_ids;
	}

	private function getContainerStates(string $hostid): array {
		$items = API::Item()->get([
			'output' => ['key_', 'lastvalue', 'lastclock'],
			'hostids' => $hostid,
			'search' => ['key_' => 'docker.container_info.state.status['],
			'startSearch' => true,
			'monitored' => true
		]);

		if (!$items) {
			return [];
		}

		$states = [];

		foreach ($items as $item) {
			if (!DockerCollector::hasRecentValue($item)
					|| preg_match(
						'/^docker\.container_info\.state\.status\["?\/?([^"\]]+)"?\]$/',
						$item['key_'],
						$matches
					) != 1) {
				continue;
			}

			switch ($item['lastvalue']) {
				case 'running':
					$states[$matches[1]] = 'up';
					break;

				case 'restarting':
					$states[$matches[1]] = 'restarting';
					break;

				case 'exited':
					$states[$matches[1]] = 'down';
					break;

				default:
					$states[$matches[1]] = 'off';
			}
		}

		return $states;
	}

	private function getContainerHealth(string $hostid): array {
		$items = API::Item()->get([
			'output' => ['key_', 'lastvalue', 'lastclock'],
			'hostids' => $hostid,
			'search' => ['key_' => 'docker.container_info.state.health['],
			'startSearch' => true,
			'monitored' => true
		]);
		$health = [];

		foreach ($items as $item) {
			if (!DockerCollector::hasRecentValue($item)
					|| preg_match(
						'/^docker\.container_info\.state\.health\["?\/?([^"\]]+)"?\]$/',
						$item['key_'],
						$matches
					) != 1) {
				continue;
			}

			$health[$matches[1]] = DockerFormatter::healthState($item['lastvalue']);
		}

		return $health;
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
				(new CSpan($label))->addClass('docker-card-unit'),
				(new CSpan($value))->addClass('docker-card-value')
			]))->addClass('docker-stat');
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
					->addClass('docker-image-name')
					->addClass($is_anonymous ? 'docker-muted' : null)
					->setTitle($name),
				(new CSpan((string) ($volume['Mountpoint'] ?? '-')))
					->addClass('docker-image-id')
					->setTitle((string) ($volume['Mountpoint'] ?? '')),
				DockerFormatter::bytes(($usage['Size'] ?? 0)),
				(new CSpan((string) $refcount))
					->addClass($refcount > 0 ? 'docker-status-running' : 'docker-muted')
			]);
		}

		return $this->wrapPanel(_('Volumes'), new CDiv([
			(new CDiv($pills))->addClass('docker-hostbar-stats')->addClass('docker-node-stats'),
			$table,
			$paging
		]));
	}

	private function makeMountsPanel(string $hostid, int $page): CDiv {
		$snapshot = $this->getContainerDataset($hostid, 'docker.containers.mounts');

		if ($snapshot['status'] === 'missing') {
			return $this->wrapPanel(_('Mounts'),
				(new CTableInfo())->setNoDataMessage(
					_('No container mount data collected yet. Data appears after the next "Get containers" item update.')
				)
			);
		}

		if ($snapshot['status'] === 'invalid') {
			return $this->wrapPanel(_('Mounts'),
				(new CTableInfo())->setNoDataMessage(_('The collected container mount data is invalid.'))
			);
		}

		$containers = $snapshot['containers'];
		$mount_groups = [];
		$mount_count = 0;
		$read_write = 0;

		foreach ($containers as $container) {
			if (!is_array($container)) {
				continue;
			}

			$names = array_values(array_filter(
				array_map(
					static fn ($name): string => ltrim((string) $name, '/'),
					(array) ($container['Names'] ?? [])
				),
				'strlen'
			));
			$container_name = $names ? implode(', ', $names) : substr((string) ($container['Id'] ?? ''), 0, 12);
			$container_id = (string) ($container['Id'] ?? '');
			$group_key = $container_id !== '' ? $container_id : $container_name;

			foreach ((array) ($container['Mounts'] ?? []) as $mount) {
				if (!is_array($mount)) {
					continue;
				}

				$is_read_write = (bool) ($mount['RW'] ?? false);
				$source = (string) ($mount['Source'] ?? '');

				if (!array_key_exists($group_key, $mount_groups)) {
					$mount_groups[$group_key] = [
						'container' => $container_name,
						'container_id' => $container_id,
						'has_sensitive_mount' => false,
						'mounts' => []
					];
				}

				$mount_groups[$group_key]['has_sensitive_mount'] =
					$mount_groups[$group_key]['has_sensitive_mount'] || $this->isSensitiveMountSource($source);

				$mount_groups[$group_key]['mounts'][] = [
					'type' => (string) ($mount['Type'] ?? ''),
					'name' => (string) ($mount['Name'] ?? ''),
					'source' => $source,
					'destination' => (string) ($mount['Destination'] ?? ''),
					'driver' => (string) ($mount['Driver'] ?? ''),
					'mode' => (string) ($mount['Mode'] ?? ''),
					'read_write' => $is_read_write,
					'propagation' => (string) ($mount['Propagation'] ?? '')
				];

				$mount_count++;
				$read_write += (int) $is_read_write;
			}
		}

		$mount_groups = array_values($mount_groups);

		foreach ($mount_groups as &$mount_group) {
			usort($mount_group['mounts'], static fn (array $a, array $b): int =>
				strnatcasecmp($a['destination'], $b['destination'])
			);
		}
		unset($mount_group);

		usort($mount_groups, static fn (array $a, array $b): int =>
			strnatcasecmp($a['container'], $b['container'])
		);

		$pills = [];

		foreach ([
			[_('Mounts'), $mount_count],
			[_('Containers'), count($mount_groups)],
			[_('Read-write'), $read_write],
			[_('Read-only'), $mount_count - $read_write]
		] as [$label, $value]) {
			$pills[] = (new CDiv([
				(new CSpan($label))->addClass('docker-card-unit'),
				(new CSpan((string) $value))->addClass('docker-card-value')
			]))->addClass('docker-stat');
		}

		$paging = CPagerHelper::paginate($page, $mount_groups, ZBX_SORT_UP, $this->getTabUrl('mounts'));
		$group_nodes = [];
		$expand_all = $mount_count <= 6;

		foreach ($mount_groups as $mount_group) {
			$container_label_parts = [
				(new CSpan($mount_group['container'] !== '' ? $mount_group['container'] : '-'))
					->addClass('docker-mount-container-name')
			];

			if ($mount_group['has_sensitive_mount']) {
				$container_label_parts[] = (new CSpan())
					->addClass('docker-mount-security-flag')
					->setAttribute('role', 'img')
					->setAttribute('aria-label', _('Security warning: host root or /etc is mounted.'))
					->setTitle(_('Security warning: host root or /etc is mounted.'));
			}

			$container_label = (new CSpan($container_label_parts))
				->addClass('docker-graphgroup-name')
				->addClass('docker-mount-container-label');

			if ($mount_group['container_id'] !== '') {
				$container_label->setTitle($mount_group['container_id']);
			}

			$table = (new CTableInfo())
				->setHeader([
					_('Type'),
					_('Name'),
					_('Source'),
					_('Destination'),
					_('Driver'),
					_('Mode'),
					_('Access'),
					_('Propagation')
				]);

			foreach ($mount_group['mounts'] as $mount) {
				$table->addRow([
					$mount['type'] !== '' ? $mount['type'] : '-',
					$mount['name'] !== '' ? $mount['name'] : '-',
					(new CSpan($mount['source'] !== '' ? $mount['source'] : '-'))
						->addClass('docker-image-id')
						->addClass('docker-mount-path')
						->setTitle($mount['source']),
					(new CSpan($mount['destination'] !== '' ? $mount['destination'] : '-'))
						->addClass('docker-image-id')
						->addClass('docker-mount-path')
						->setTitle($mount['destination']),
					$mount['driver'] !== '' ? $mount['driver'] : '-',
					$mount['mode'] !== '' ? $mount['mode'] : '-',
					(new CSpan($mount['read_write'] ? _('Read-write') : _('Read-only')))
						->addClass($mount['read_write'] ? 'docker-status-running' : 'docker-muted'),
					$mount['propagation'] !== '' ? $mount['propagation'] : '-'
				]);
			}

			$body = (new CDiv([$table]))->addClass('docker-graphgroup-body');

			if (!$expand_all) {
				$body->setAttribute('hidden', 'hidden');
			}

			$head = (new CTag('button', true, [
				(new CSpan())->addClass('docker-graphgroup-caret'),
				$container_label->addClass('docker-graphgroup-name'),
				(new CSpan((string) count($mount_group['mounts'])))
					->addClass('docker-graphgroup-count')
			]))
				->setAttribute('type', 'button')
				->addClass('docker-graphgroup-head')
				->addClass($expand_all ? 'docker-graphgroup-open' : null)
				->setAttribute('aria-expanded', $expand_all ? 'true' : 'false');

			$group_nodes[] = (new CDiv([
				$head,
				$body
			]))->addClass('docker-graphgroup');
		}

		if (!$group_nodes) {
			$group_nodes[] = (new CTableInfo())->setNoDataMessage(_('No container mounts found.'));
		}

		return $this->wrapPanel(_('Mounts'), new CDiv([
			(new CDiv($pills))->addClass('docker-hostbar-stats')->addClass('docker-node-stats'),
			(new CDiv($group_nodes))->addClass('docker-mount-groups'),
			$paging
		]));
	}

	private function isSensitiveMountSource(string $source): bool {
		return $source === '/' || rtrim($source, '/') === '/etc';
	}

	private function makeComposePanel(string $hostid, int $page): CDiv {
		$snapshot = $this->getContainerDataset($hostid, 'docker.containers.labels');

		if ($snapshot['status'] === 'missing') {
			return $this->wrapPanel(_('Compose'),
				(new CTableInfo())->setNoDataMessage(
					_('No container label data collected yet. Install the docker.containers.labels.raw UserParameter and import the bundled template.')
				)
			);
		}

		if ($snapshot['status'] === 'invalid') {
			return $this->wrapPanel(_('Compose'),
				(new CTableInfo())->setNoDataMessage(_('The collected container label data is invalid.'))
			);
		}

		$projects = [];
		$running = 0;
		$container_health = $this->getContainerHealth($hostid);
		$health_counts = ['healthy' => 0, 'unhealthy' => 0, 'starting' => 0, 'none' => 0];

		foreach ($snapshot['containers'] as $container) {
			if (!is_array($container) || !is_array($container['Labels'] ?? null)) {
				continue;
			}

			$labels = $container['Labels'];
			$project = trim((string) ($labels['com.docker.compose.project'] ?? ''));

			if ($project === '') {
				continue;
			}

			$names = array_values(array_filter(array_map(
				static fn ($name): string => ltrim((string) $name, '/'),
				(array) ($container['Names'] ?? [])
			), 'strlen'));
			$name = $names
				? $names[0]
				: substr((string) ($container['Id'] ?? ''), 0, 12);
			$compose_labels = [];
			$container_labels = [];

			foreach ($labels as $key => $value) {
				$key = (string) $key;

				if (!str_starts_with($key, 'com.docker.compose.')) {
					continue;
				}

				$formatted_value = is_scalar($value) || $value === null
					? (string) $value
					: json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				$compose_labels[$key] = $formatted_value;

				if ($key !== 'com.docker.compose.project'
						&& !str_starts_with($key, 'com.docker.compose.project.')
						&& $key !== 'com.docker.compose.version') {
					$container_labels[$key] = $formatted_value;
				}
			}

			uksort($compose_labels, 'strnatcasecmp');
			uksort($container_labels, 'strnatcasecmp');

			$state = strtolower((string) ($container['State'] ?? ''));
			$is_running = $state === 'running';
			$health = $container_health[$name] ?? null;
			$running += (int) $is_running;

			if ($health !== null) {
				$health_counts[$health[1]] = ($health_counts[$health[1]] ?? 0) + 1;
			}

			$projects[$project]['containers'][] = [
				'name' => $name,
				'id' => (string) ($container['Id'] ?? ''),
				'image' => (string) ($container['Image'] ?? ''),
				'state' => $state,
				'is_running' => $is_running,
				'health' => $health,
				'service' => (string) ($labels['com.docker.compose.service'] ?? ''),
				'number' => (string) ($labels['com.docker.compose.container-number'] ?? ''),
				'labels' => $container_labels
			];

			foreach ($compose_labels as $key => $value) {
				if (str_starts_with($key, 'com.docker.compose.project.')
						|| $key === 'com.docker.compose.version') {
					$projects[$project]['metadata'][$key][$value] = true;
				}
			}
		}

		uksort($projects, 'strnatcasecmp');
		$project_count = count($projects);
		$container_count = array_sum(array_map(
			static fn (array $project): int => count($project['containers']),
			$projects
		));

		$projects = array_map(static function (array $project): array {
			usort($project['containers'], static fn (array $a, array $b): int =>
				strnatcasecmp($a['service'], $b['service'])
					?: strnatcasecmp($a['name'], $b['name'])
			);

			return $project;
		}, $projects);

		$projects = array_values(array_map(
			static fn (string $name, array $project): array => $project + ['name' => $name],
			array_keys($projects),
			array_values($projects)
		));

		$paging = CPagerHelper::paginate($page, $projects, ZBX_SORT_UP, $this->getTabUrl('compose'));
		$pills = [];

		foreach ([
			[_('Projects'), $project_count],
			[_('Containers'), $container_count],
			[_('Running'), $running],
			[_('Stopped'), $container_count - $running],
			[_('Healthy'), $health_counts['healthy']],
			[_('Unhealthy'), $health_counts['unhealthy']]
		] as [$label, $value]) {
			$pills[] = (new CDiv([
				(new CSpan($label))->addClass('docker-card-unit'),
				(new CSpan((string) $value))->addClass('docker-card-value')
			]))->addClass('docker-stat');
		}

		$project_nodes = [];
		$expand_all = $project_count <= 4;

		foreach ($projects as $project) {
			$metadata_table = null;

			if (!empty($project['metadata'])) {
				$metadata_table = (new CTableInfo())
					->setHeader([_('Deployment label'), _('Value')]);

				foreach ($project['metadata'] as $key => $values) {
					$value = implode(', ', array_keys($values));
					$metadata_table->addRow([
						(new CSpan($key))
							->addClass('docker-label-key')
							->setTitle($key),
						(new CSpan($value !== '' ? $value : '-'))
							->addClass('docker-label-value')
							->setTitle($value)
					]);
				}
			}

			$project_running = 0;
			$project_health_counts = ['healthy' => 0, 'unhealthy' => 0, 'starting' => 0, 'none' => 0];
			$services = [];
			$container_nodes = [];
			$expand_containers = count($project['containers']) <= 3;

			foreach ($project['containers'] as $container) {
				$project_running += (int) $container['is_running'];

				if ($container['health'] !== null) {
					$project_health_counts[$container['health'][1]] =
						($project_health_counts[$container['health'][1]] ?? 0) + 1;
				}

				if ($container['service'] !== '') {
					$services[$container['service']] = true;
				}

				$labels_table = null;

				if ($container['labels']) {
					$labels_table = (new CTableInfo())
						->setHeader([_('Compose label'), _('Value')]);

					foreach ($container['labels'] as $key => $value) {
						$labels_table->addRow([
							(new CSpan($key))
								->addClass('docker-label-key')
								->setTitle($key),
							(new CSpan($value !== '' ? $value : '-'))
								->addClass('docker-label-value')
								->setTitle($value)
						]);
					}
				}

				$details_link = null;

				if ($container['name'] !== '') {
					$details_link = (new CLinkAction(_('Open container details')))
						->setAttribute('data-container', $container['name']);
				}

				$container_body = (new CDiv([
					$details_link !== null
						? (new CDiv($details_link))->addClass('docker-compose-container-actions')
						: null,
					$labels_table
				]))->addClass('docker-graphgroup-body');

				if (!$expand_containers) {
					$container_body->setAttribute('hidden', 'hidden');
				}

				$container_name = (new CSpan($container['name'] !== '' ? $container['name'] : '-'))
					->addClass('docker-graphgroup-name')
					->setTitle($container['id']);

				$service = $container['service'] !== ''
					? $container['service'].($container['number'] !== '' ? ' #'.$container['number'] : '')
					: '-';
				$container_head = (new CTag('button', true, [
					(new CSpan())->addClass('docker-graphgroup-caret'),
					$container_name,
					(new CSpan($service))->addClass('docker-compose-container-service'),
					(new CSpan($container['image'] !== '' ? $container['image'] : '-'))
						->addClass('docker-compose-container-image')
						->setTitle($container['image']),
					(new CSpan($container['state'] !== '' ? ucfirst($container['state']) : '-'))
						->addClass($container['is_running']
							? 'docker-status-running'
							: 'docker-status-stopped'
						),
					$container['health'] !== null
						? (new CSpan($container['health'][0]))
							->addClass('docker-compose-health')
							->addClass('docker-compose-health-'.$container['health'][1])
						: null,
					(new CSpan((string) count($container['labels'])))
						->addClass('docker-graphgroup-count')
				]))
					->setAttribute('type', 'button')
					->addClass('docker-graphgroup-head')
					->addClass($expand_containers ? 'docker-graphgroup-open' : null)
					->setAttribute('aria-expanded', $expand_containers ? 'true' : 'false');

				$container_nodes[] = (new CDiv([$container_head, $container_body]))
					->addClass('docker-graphgroup')
					->addClass('docker-compose-container');
			}

			$body = (new CDiv([
				$metadata_table !== null
					? (new CDiv([
						(new CTag('h5', true, _('Deployment labels')))
							->addClass('docker-node-inventory-title'),
						$metadata_table
					]))->addClass('docker-compose-deployment')
					: null,
				(new CDiv($container_nodes))->addClass('docker-compose-containers')
			]))->addClass('docker-graphgroup-body');

			if (!$expand_all) {
				$body->setAttribute('hidden', 'hidden');
			}

			$health_rollup = [];
			$health_labels = [
				'healthy' => _('Healthy'),
				'unhealthy' => _('Unhealthy'),
				'starting' => _('Starting')
			];

			foreach ($health_labels as $kind => $label) {
				if ($project_health_counts[$kind] > 0) {
					$health_rollup[] = (new CSpan(
						$project_health_counts[$kind].' '.$label
					))->addClass('docker-compose-health-'.$kind);
				}
			}

			if (!$health_rollup) {
				$health_rollup[] = (new CSpan(_('No health checks')))->addClass('docker-compose-health-none');
			}

			$head = (new CTag('button', true, [
				(new CSpan())->addClass('docker-graphgroup-caret'),
				(new CSpan($project['name']))->addClass('docker-graphgroup-name'),
				(new CSpan(count($services).' '._('services')))
					->addClass('docker-compose-project-summary'),
				(new CSpan($health_rollup))->addClass('docker-compose-health-rollup'),
				(new CSpan($project_running.'/'.count($project['containers']).' '._('running')))
					->addClass($project_running === count($project['containers'])
						? 'docker-status-running'
						: 'docker-status-stopped'
					),
				(new CSpan((string) count($project['containers'])))
					->addClass('docker-graphgroup-count')
			]))
				->setAttribute('type', 'button')
				->addClass('docker-graphgroup-head')
				->addClass($expand_all ? 'docker-graphgroup-open' : null)
				->setAttribute('aria-expanded', $expand_all ? 'true' : 'false');

			$project_nodes[] = (new CDiv([$head, $body]))
				->addClass('docker-graphgroup')
				->addClass('docker-compose-project');
		}

		if (!$project_nodes) {
			$project_nodes[] = (new CTableInfo())->setNoDataMessage(
				_('No Docker Compose projects found in the collected container labels.')
			);
		}

		return $this->wrapPanel(_('Compose'), new CDiv([
			(new CDiv($pills))->addClass('docker-hostbar-stats')->addClass('docker-node-stats'),
			(new CDiv($project_nodes))->addClass('docker-compose-projects'),
			$paging
		]));
	}

	public static function makeNetworkZone(string $network_name, array $members, array $container_state,
			array $memberships, array $container_ports, bool $ports_available, bool $interactive = true): CDiv {
		$nodes = (new CDiv())->addClass('docker-topo-nodes');

		ksort($members);

		foreach ($members as $container => $network_info) {
			$kind = $container_state[$container] ?? 'up';
			$extra_nets = array_values(array_diff($memberships[$container] ?? [], [$network_name]));
			$network_details = [
				(new CSpan($network_info['ip'] !== '' ? $network_info['ip'] : '-'))
					->addClass('docker-topo-ip')
			];

			foreach ($network_info['dns_names'] as $dns_name) {
				$is_ch_fqdn = preg_match(
					'/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+ch$/i',
					$dns_name
				) == 1;

				$dns = $is_ch_fqdn
					? (new CLink($dns_name, 'https://'.$dns_name))
						->setTarget('_blank')
						->setAttribute('rel', 'noopener noreferrer')
						->addClass('docker-topo-dns-link')
					: new CSpan($dns_name);

				$network_details[] = $dns->addClass('docker-topo-dns');
			}

			$port_nodes = [
				(new CSpan(_('Ports')))->addClass('docker-topo-ports-label')
			];

			if (!empty($container_ports[$container])) {
				foreach ($container_ports[$container] as $port) {
					$port_nodes[] = (new CSpan($port))->addClass('docker-topo-port');
				}
			}
			else {
				$port_nodes[] = (new CSpan($ports_available ? '-' : _('No data')))
					->addClass('docker-topo-port');
			}

			$network_details[] = (new CDiv($port_nodes))->addClass('docker-topo-ports');
			$node = (new CDiv([
				(new CSpan())->addClass('docker-dot')
					->addClass($kind === 'up' ? 'docker-status-running' : 'docker-status-stopped'),
				(new CDiv([
					(new CSpan($container))->addClass('docker-topo-name'),
					(new CDiv($network_details))->addClass('docker-topo-details')
				]))->addClass('docker-topo-text'),
				$extra_nets
					? (new CSpan('⇄'))
						->addClass('docker-topo-multi')
						->setTitle(_('Also in').': '.implode(', ', $extra_nets))
					: null
			]))
				->addClass('docker-topo-node')
				->addClass($kind === 'restarting' ? 'docker-topo-node-bad' : null);

			if ($interactive) {
				$node
					->setAttribute('data-container', $container)
					->setAttribute('role', 'button')
					->setAttribute('tabindex', '0');
			}
			else {
				$node->addClass('docker-topo-node-static');
			}

			$nodes->addItem($node);
		}

		$zone = (new CDiv([
			(new CDiv([
				(new CSpan($network_name))->addClass('docker-topo-zone-name'),
				(new CSpan((string) count($members)))->addClass('docker-graphgroup-count')
			]))->addClass('docker-topo-zone-head'),
			$nodes
		]))->addClass('docker-topo-zone');

		if (count($members) > 2) {
			$zone->addClass('docker-topo-zone-wide');
		}
		elseif (count($members) === 1) {
			$zone->addClass('docker-topo-zone-single');
		}
		else {
			$zone->addClass('docker-topo-zone-compact');
		}

		return $zone;
	}

	private function makeNetworksPanel(string $hostid): CDiv {
		$items = API::Item()->get([
			'output' => ['key_', 'lastvalue', 'lastclock'],
			'hostids' => $hostid,
			'search' => ['key_' => 'docker.container_info.networks['],
			'startSearch' => true,
			'monitored' => true
		]);

		if (!$items) {
			return $this->wrapPanel(_('Networks'),
				(new CTableInfo())->setNoDataMessage(
					_('No network data collected yet. Data appears after the next containers discovery cycle.')
				)
			);
		}

		$container_state = $this->getContainerStates($hostid);
		$container_ports = [];
		$snapshot = $this->getContainerDataset($hostid, 'docker.containers.ports');
		$ports_available = $snapshot['status'] === 'ok';

		if ($ports_available) {
			foreach ($snapshot['containers'] as $raw_container) {
				if (!is_array($raw_container)) {
					continue;
				}

				$ports = DockerFormatter::containerPorts((array) ($raw_container['Ports'] ?? []));

				foreach ((array) ($raw_container['Names'] ?? []) as $raw_name) {
					$name = ltrim((string) $raw_name, '/');

					if ($name !== '') {
						$container_ports[$name] = $ports;
					}
				}
			}
		}

		$networks = [];
		$memberships = [];

		foreach ($items as $item) {
			if (preg_match('/^docker\.container_info\.networks\["?\/?([^"\]]+)"?\]$/', $item['key_'], $matches) != 1
					|| !DockerCollector::hasRecentValue($item)) {
				continue;
			}

			$name = $matches[1];
			$nets = json_decode($item['lastvalue'], true);

			if (!is_array($nets)) {
				continue;
			}

			foreach ($nets as $net_name => $net) {
				$dns_names = array_values(array_filter(
					array_map('strval', (array) ($net['DNSNames'] ?? [])),
					'strlen'
				));

				$networks[$net_name][$name] = [
					'ip' => (string) ($net['IPAddress'] ?? ''),
					'dns_names' => $dns_names
				];
				$memberships[$name][] = $net_name;
			}
		}

		if (!$networks) {
			return $this->wrapPanel(_('Networks'),
				(new CTableInfo())->setNoDataMessage(_('No container network data available.'))
			);
		}

		uasort($networks, static fn (array $a, array $b): int => count($b) <=> count($a));

		$summary = (new CDiv(
			count($networks).' '._('networks').' · '.count($memberships).' '._('containers')
		))->addClass('docker-topo-summary');

		$zones = new CDiv();
		$zones->addClass('docker-topo');

		foreach ($networks as $net_name => $members) {
			$zones->addItem(self::makeNetworkZone(
				$net_name,
				$members,
				$container_state,
				$memberships,
				$container_ports,
				$ports_available
			));
		}

		return $this->wrapPanel(_('Networks'), new CDiv([$summary, $zones]));
	}

	private function makeProblemsPanel(string $hostid, int $docker_page, int $other_page): CDiv {
		$search_limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$problems = API::Problem()->get([
			'output' => ['eventid', 'objectid', 'name', 'clock', 'severity', 'acknowledged'],
			'hostids' => $hostid,
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'suppressed' => false,
			'symptom' => false,
			'sortfield' => 'eventid',
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => ($search_limit * 2) + 2
		]);

		$docker_triggerids = array_flip(DockerCollector::dockerTemplateTriggerIds(
			array_values(array_unique(array_column($problems, 'objectid')))
		));
		$docker_problems = [];
		$other_problems = [];

		foreach ($problems as $problem) {
			if (array_key_exists($problem['objectid'], $docker_triggerids)) {
				$docker_problems[] = $problem;
			}
			else {
				$other_problems[] = $problem;
			}
		}

		$docker_paging = CPagerHelper::paginate($docker_page, $docker_problems, ZBX_SORT_DOWN,
			$this->getTabUrl('problems')->setArgument('problem_section', 'docker')
		);
		$other_paging = CPagerHelper::paginate($other_page, $other_problems, ZBX_SORT_DOWN,
			$this->getTabUrl('problems')->setArgument('problem_section', 'other')
		);

		$backurl = (new CUrl('zabbix.php'))
			->setArgument('action', 'docker.view')
			->setArgument('filter_hostid', [$hostid])
			->getUrl();

		$docker_table = $this->makeProblemTable($docker_problems, $backurl,
			_('No Docker-related problems found.')
		);
		$other_table = $this->makeProblemTable($other_problems, $backurl,
			_('No other host problems found.')
		);

		return $this->wrapPanel(_('Problems'), new CDiv([
			(new CTag('h5', true, _('Docker-related problems')))->addClass('docker-subsection-title'),
			$docker_table,
			$docker_paging,
			(new CTag('h5', true, _('Other host problems')))->addClass('docker-subsection-title'),
			$other_table,
			$other_paging
		]));
	}

	private function makeProblemTable(array $problems, string $backurl, string $no_data_message): CTableInfo {
		$table = (new CTableInfo())
			->setHeader([_('Time'), _('Severity'), _('Problem'), _('Duration'), _('Ack')])
			->setNoDataMessage($no_data_message);

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
					? (new CSpan(_('Yes')))->addClass('docker-status-running')
					: (new CSpan(_('No')))->addClass('docker-status-stopped')
			]);
		}

		return $table;
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
					->setId('docker-graphs-search')
					->setAttribute('type', 'search')
					->setAttribute('placeholder', _('Filter graphs...'))
					->setAttribute('aria-label', _('Filter graphs'))
					->setAttribute('autocomplete', 'off')
					->addClass('docker-search'),
				(new CSpan($total.' '._('graphs').' · '.count($groups).' '._('groups')
					.($total == $search_limit ? ' ('._('limited').')' : '')
				))->addClass('docker-graphs-count')
			]))->addClass('docker-toolbar')
		);

		foreach ($groups as $group_name => $items) {
			$body = (new CDiv())->addClass('docker-graphgroup-body');

			foreach ($items as ['graph' => $graph, 'title' => $title]) {
				$dims = getGraphDims($graph['graphid']);

				$is_pie = in_array((int) $dims['graphtype'], [GRAPH_TYPE_PIE, GRAPH_TYPE_EXPLODED], true);

				$body->addItem(
					(new CDiv([
						(new CTag('h5', true, $title))->addClass('docker-graph-title'),
						(new CTag('img', false))
							->setAttribute('alt', $graph['name'])
							->setAttribute('loading', 'lazy')
							->addClass('docker-chart-img')
							->setAttribute('data-shift',
								(string) ($is_pie ? 0 : $dims['shiftXleft'] + $dims['shiftXright'] + 1)
							)
							->setAttribute('data-chart', ($is_pie ? 'chart6.php' : 'chart2.php').'?'
								.http_build_query([
									'graphid' => $graph['graphid'],
									'from' => $timeline['from'],
									'to' => $timeline['to'],
									'height' => $dims['graphHeight'],
									'profileIdx' => self::TIME_PROFILE_IDX
								]))
					]))
						->addClass('docker-graph')
						->setAttribute('data-graph', mb_strtolower($title))
				);
			}

			if (!$expand_all) {
				$body->setAttribute('hidden', 'hidden');
			}

			$head = (new CTag('button', true, [
				(new CSpan())->addClass('docker-graphgroup-caret'),
				(new CSpan($group_name === '' ? _('Node') : $group_name))->addClass('docker-graphgroup-name'),
				(new CSpan((string) count($items)))->addClass('docker-graphgroup-count')
			]))
				->setAttribute('type', 'button')
				->addClass('docker-graphgroup-head')
				->addClass($expand_all ? 'docker-graphgroup-open' : null)
				->setAttribute('aria-expanded', $expand_all ? 'true' : 'false');

			$panel->addItem(
				(new CDiv([$head, $body]))
					->addClass('docker-graphgroup')
					->setAttribute('data-graphgroup', mb_strtolower((string) $group_name))
			);
		}

		return $this->wrapPanel(_('Graphs'), $panel);
	}

	private function makeInventoryTable(string $hostid): CTableInfo {
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

		return $table;
	}

	private function wrapPanel(string $title, CTag $body): CDiv {
		return (new CDiv([
			(new CTag('h4', true, $title))->addClass('docker-section-title'),
			$this->makeDataSourceStrip($this->getInput('hostid'), $this->getInput('tab')),
			$body
		]))->addClass('docker-section');
	}
}

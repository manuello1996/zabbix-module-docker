<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Actions;

use API;
use CController;
use CControllerResponseData;
use CDiv;
use CRoleHelper;
use CSpan;
use CTableInfo;
use CTag;
use CUrl;
use CWebUser;
use Modules\MonitorDocker\Includes\DockerCollector;
use Modules\MonitorDocker\Includes\DockerFormatter;

class CControllerDockerContainer extends CController {
	private const FIELDS = [
		'docker.container.description' => 'description',
		'docker.container_info.image' => 'image',
		'docker.container_info.networks' => 'networks',
		'docker.container_info.restart_count' => 'restart_count',
		'docker.container_info.state.exitcode' => 'exitcode',
		'docker.container_info.state.health' => 'health',
		'docker.container_info.state.status' => 'status',
		'docker.container_stats.pids_stats.current' => 'pids',
		'docker.container_stats.cpu_usage.throttled_periods' => 'throttled_periods',
		'docker.container_stats.cpu_usage.throttled_time' => 'throttled_time',
		'docker.networks.rx_errors' => 'rx_errors',
		'docker.networks.rx_dropped' => 'rx_dropped',
		'docker.networks.tx_errors' => 'tx_errors',
		'docker.networks.tx_dropped' => 'tx_dropped',
		'docker.container_stats.memory.max_usage' => 'memory_max'
	];

	private const CHART_KEYS = [
		'docker.container_stats.cpu_pct_usage',
		'docker.container_stats.memory.usage_total',
		'docker.container_stats.memory.max_usage',
		'docker.networks.rx_bytes',
		'docker.networks.tx_bytes'
	];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' =>	'required|db hosts.hostid',
			'name' =>	'required|string|not_empty'
		];

		$ret = $this->validateInput($fields)
			&& preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,254}$/', $this->getInput('name', '')) == 1;

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => _('Invalid request.')
			])]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		if (!CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA)) {
			return false;
		}

		return (bool) API::Host()->get([
			'output' => [],
			'hostids' => $this->getInput('hostid')
		]);
	}

	protected function doAction(): void {
		$hostid = $this->getInput('hostid');
		$name = $this->getInput('name');
		$prefixes = array_values(array_unique(array_merge(array_keys(self::FIELDS), self::CHART_KEYS)));
		$keys = [];

		foreach ($prefixes as $prefix) {
			foreach ([$name, '/'.$name] as $key_name) {
				$keys[] = $prefix.'["'.$key_name.'"]';
				$keys[] = $prefix.'['.$key_name.']';
			}
		}

		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'key_', 'units', 'value_type', 'lastvalue', 'lastclock'],
			'selectValueMap' => ['mappings'],
			'hostids' => $hostid,
			'filter' => ['key_' => $keys],
			'monitored' => true,
			'preservekeys' => true
		]);

		if (!$items) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => _('No data found for this container.')
			])]));

			return;
		}

		$by_prefix = [];

		foreach ($items as $itemid => $item) {
			if (preg_match('/^([a-z0-9._]+)\["?([^"\]]+)"?\]$/i', $item['key_'], $matches) != 1
					|| ltrim($matches[2], '/') !== $name) {
				continue;
			}

			$prefix = $matches[1];

			if (!array_key_exists($prefix, $by_prefix)) {
				if (!DockerCollector::hasRecentValue($item)) {
					$item['lastvalue'] = '';
					$item['lastclock'] = 0;
				}

				$by_prefix[$prefix] = $item;
			}
		}

		if (!$by_prefix) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => _('No data found for this container.')
			])]));

			return;
		}

		$datasets = $this->storedContainerDatasets($hostid, [
			'docker.containers.ports',
			'docker.containers.mounts',
			'docker.containers.labels'
		]);
		$charts = $this->makeChartCells($by_prefix);
		$content = (new CDiv([
			$this->makeIdentityStrip($by_prefix),
			$this->makeNetworkSection($by_prefix, $name, $datasets['docker.containers.ports']),
			$charts['network'],
			$charts['cpu'],
			$charts['memory'],
			$this->makeMountsSection($name, $datasets['docker.containers.mounts']),
			$this->makeLabelsSection($name, $datasets['docker.containers.labels'])
		]))->addClass('docker-modal-content');

		$description = trim((string) ($this->rawValue($by_prefix, 'docker.container.description') ?? ''));

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'html' => $content->toString(),
			'description' => $description
		])]));
	}

	private function formattedValue(array $by_prefix, string $prefix, bool $trim = true): string {
		$item = $by_prefix[$prefix] ?? null;

		return ($item !== null && $item['lastclock'] > 0)
			? formatHistoryValue($item['lastvalue'], $item, $trim)
			: '-';
	}

	private function rawValue(array $by_prefix, string $prefix): ?string {
		$item = $by_prefix[$prefix] ?? null;

		return ($item !== null && $item['lastclock'] > 0) ? $item['lastvalue'] : null;
	}

	private function countValue(array $by_prefix, string $prefix): CSpan {
		$value = (new CSpan($this->formattedValue($by_prefix, $prefix)))->addClass('docker-stat-value');
		$raw = $this->rawValue($by_prefix, $prefix);

		if ($raw !== null && (float) $raw != 0) {
			$value->addClass('docker-value-bad');
		}

		return $value;
	}

	private function makeIdentityStrip(array $by_prefix): CDiv {
		$health_raw = $this->rawValue($by_prefix, 'docker.container_info.state.health');

		$health = (new CSpan($this->formattedValue($by_prefix, 'docker.container_info.state.health')))
			->addClass('docker-stat-value');

		if ($health_raw !== null && (int) $health_raw == 3) {
			$health->addClass('docker-chip-ok');
		}
		elseif ($health_raw !== null && (int) $health_raw == 2) {
			$health->addClass('docker-value-bad');
		}

		$image = $this->formattedValue($by_prefix, 'docker.container_info.image', false);

		$chip = static function (string $label, $value): CDiv {
			return (new CDiv([
				(new CDiv($label))->addClass('docker-chip-label'),
				(new CDiv($value))->addClass('docker-chip-body')
			]))->addClass('docker-idchip');
		};

		return (new CDiv([
			$chip(_('Health'), $health),
			$chip(_('Exit code'), $this->countValue($by_prefix, 'docker.container_info.state.exitcode')),
			$chip(_('Restarts'), $this->formattedValue($by_prefix, 'docker.container_info.restart_count')),
			$chip(_('PIDs'), $this->formattedValue($by_prefix, 'docker.container_stats.pids_stats.current')),
			$chip(_('Image'), $image)
				->addClass('docker-idchip-image')
				->setTitle($image)
		]))->addClass('docker-idbar');
	}

	private function storedContainerDatasets(string $hostid, array $keys): array {
		$datasets = array_fill_keys($keys, null);
		$items = API::Item()->get([
			'output' => ['key_', 'lastvalue', 'lastclock'],
			'hostids' => $hostid,
			'filter' => ['key_' => $keys],
			'monitored' => true,
			'limit' => count($keys)
		]);

		foreach ($items as $item) {
			if (!array_key_exists($item['key_'], $datasets) || !DockerCollector::hasRecentValue($item)) {
				continue;
			}

			$value = json_decode($item['lastvalue'], true);

			if (is_array($value)) {
				$datasets[$item['key_']] = $value;
			}
		}

		return $datasets;
	}

	private function findContainerDatasetEntry(?array $dataset, string $name): ?array {
		foreach ($dataset ?? [] as $container) {
			if (!is_array($container)) {
				continue;
			}

			foreach ((array) ($container['Names'] ?? []) as $container_name) {
				if (ltrim((string) $container_name, '/') === $name) {
					return $container;
				}
			}
		}

		return null;
	}

	private function makeNetworkSection(array $by_prefix, string $name, ?array $ports_dataset): CDiv {
		$raw = $this->rawValue($by_prefix, 'docker.container_info.networks');
		$networks = $raw !== null ? json_decode($raw, true) : null;
		$body = (new CDiv())->addClass('docker-topo')->addClass('docker-modal-topo');

		if (!is_array($networks) || !$networks) {
			$body->addItem(
				(new CDiv(_('No network information collected for this container.')))
					->addClass('docker-muted')
			);
		}
		else {
			ksort($networks);
			$port_entry = $this->findContainerDatasetEntry($ports_dataset, $name);
			$ports = DockerFormatter::containerPorts((array) ($port_entry['Ports'] ?? []));
			$status = $this->rawValue($by_prefix, 'docker.container_info.state.status');
			$kind = $status === 'running' ? 'up' : ($status === 'restarting' ? 'restarting' : 'off');
			$memberships = [$name => array_keys($networks)];

			foreach ($networks as $network_name => $network) {
				if (!is_array($network)) {
					continue;
				}

				$dns_names = array_values(array_filter(
					array_map('strval', (array) ($network['DNSNames'] ?? [])),
					'strlen'
				));
				$body->addItem(CControllerDockerTab::makeNetworkZone(
					(string) $network_name,
					[
						$name => [
							'ip' => (string) ($network['IPAddress'] ?? ''),
							'dns_names' => $dns_names
						]
					],
					[$name => $kind],
					$memberships,
					[$name => $ports],
					$ports_dataset !== null,
					false
				));
			}
		}

		return (new CDiv([
			(new CTag('h5', true, _('Network information')))->addClass('docker-cell-title'),
			$body
		]))
			->addClass('docker-cell')
			->addClass('docker-modal-network-section');
	}

	private function makeMountsSection(string $name, ?array $mounts_dataset): CDiv {
		$entry = $this->findContainerDatasetEntry($mounts_dataset, $name);
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
			])
			->setNoDataMessage($mounts_dataset === null
				? _('No mount data collected yet.')
				: _('No mounts configured for this container.')
			);

		foreach ((array) ($entry['Mounts'] ?? []) as $mount) {
			if (!is_array($mount)) {
				continue;
			}

			$read_write = (bool) ($mount['RW'] ?? false);

			$table->addRow([
				(string) ($mount['Type'] ?? '') !== '' ? (string) $mount['Type'] : '-',
				(string) ($mount['Name'] ?? '') !== '' ? (string) $mount['Name'] : '-',
				(new CSpan((string) ($mount['Source'] ?? '') !== '' ? (string) $mount['Source'] : '-'))
					->addClass('docker-image-id')
					->addClass('docker-mount-path')
					->setTitle((string) ($mount['Source'] ?? '')),
				(new CSpan((string) ($mount['Destination'] ?? '') !== ''
					? (string) $mount['Destination']
					: '-'
				))
					->addClass('docker-image-id')
					->addClass('docker-mount-path')
					->setTitle((string) ($mount['Destination'] ?? '')),
				(string) ($mount['Driver'] ?? '') !== '' ? (string) $mount['Driver'] : '-',
				(string) ($mount['Mode'] ?? '') !== '' ? (string) $mount['Mode'] : '-',
				(new CSpan($read_write ? _('Read-write') : _('Read-only')))
					->addClass($read_write ? 'docker-status-running' : 'docker-muted'),
				(string) ($mount['Propagation'] ?? '') !== '' ? (string) $mount['Propagation'] : '-'
			]);
		}

		return (new CDiv([
			(new CTag('h5', true, _('Mounts')))->addClass('docker-cell-title'),
			$table
		]))
			->addClass('docker-cell')
			->addClass('docker-cell-wide')
			->addClass('docker-modal-mounts');
	}

	private function makeLabelsSection(string $name, ?array $labels_dataset): CDiv {
		$entry = $this->findContainerDatasetEntry($labels_dataset, $name);
		$labels = is_array($entry['Labels'] ?? null) ? $entry['Labels'] : [];

		uksort($labels, 'strnatcasecmp');

		$table = (new CTableInfo())
			->setHeader([_('Label'), _('Value')])
			->setNoDataMessage($labels_dataset === null
				? _('No label data collected yet.')
				: _('No labels configured for this container.')
			);

		foreach ($labels as $key => $value) {
			$value = is_scalar($value) || $value === null
				? (string) $value
				: json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

			$table->addRow([
				(new CSpan((string) $key))
					->addClass('docker-label-key')
					->setTitle((string) $key),
				(new CSpan($value !== '' ? $value : '-'))
					->addClass('docker-label-value')
					->setTitle($value)
			]);
		}

		return (new CDiv([
			(new CTag('h5', true, _('Labels')))->addClass('docker-cell-title'),
			$table
		]))
			->addClass('docker-cell')
			->addClass('docker-cell-wide')
			->addClass('docker-modal-labels');
	}

	private function makeChartCells(array $by_prefix): array {
		$timeline = getTimeSelectorPeriod([
			'profileIdx' => CControllerDockerTab::TIME_PROFILE_IDX,
			'profileIdx2' => 0
		]);

		$itemid = static fn (string $prefix): ?string => $by_prefix[$prefix]['itemid'] ?? null;

		$charts = [
			['cpu', _('CPU usage'), [
				$itemid('docker.container_stats.cpu_pct_usage')
			], [
				(new CSpan(_('Throttled').': '))->addClass('docker-stat-label'),
				$this->countValue($by_prefix, 'docker.container_stats.cpu_usage.throttled_periods'),
				' / ',
				(new CSpan($this->formattedValue($by_prefix, 'docker.container_stats.cpu_usage.throttled_time')))
					->addClass('docker-stat-value')
			], 0, 220],
			['memory', _('Memory usage'), [
				$itemid('docker.container_stats.memory.usage_total')
			], [
				(new CSpan())->addClass('docker-series-dot')->addClass('docker-series-dot-1'),
				(new CSpan(_('Used').' '))->addClass('docker-stat-label'),
				(new CSpan($this->formattedValue($by_prefix, 'docker.container_stats.memory.usage_total')))
					->addClass('docker-stat-value'),
				' — ',
				(new CSpan(_('Max').' '))->addClass('docker-stat-label'),
				(new CSpan($this->formattedValue($by_prefix, 'docker.container_stats.memory.max_usage')))
					->addClass('docker-stat-value')
			], 0, 220],
			['network', _('Network traffic'), [
				$itemid('docker.networks.rx_bytes'),
				$itemid('docker.networks.tx_bytes')
			], [
				(new CSpan())->addClass('docker-series-dot')->addClass('docker-series-dot-1'),
				(new CSpan('RX'))->addClass('docker-stat-label'),
				' ',
				(new CSpan())->addClass('docker-series-dot')->addClass('docker-series-dot-2'),
				(new CSpan('TX'))->addClass('docker-stat-label'),
				' — ',
				(new CSpan(_('Errors').' '))->addClass('docker-stat-label'),
				$this->countValue($by_prefix, 'docker.networks.rx_errors'),
				'/',
				$this->countValue($by_prefix, 'docker.networks.tx_errors'),
				' — ',
				(new CSpan(_('Dropped').' '))->addClass('docker-stat-label'),
				$this->countValue($by_prefix, 'docker.networks.rx_dropped'),
				'/',
				$this->countValue($by_prefix, 'docker.networks.tx_dropped')
			], 0, 220]
		];

		$result = [];

		foreach ($charts as [$key, $title, $itemids, $stats, $legend, $height]) {
			$itemids = array_values(array_filter($itemids, static fn ($id) => $id !== null));

			if ($itemids) {
				$url = (new CUrl('chart.php'))
					->setArgument('from', $timeline['from'])
					->setArgument('to', $timeline['to'])
					->setArgument('itemids', $itemids)
					->setArgument('type', GRAPH_TYPE_NORMAL)
					->setArgument('batch', 1)
					->setArgument('legend', $legend)
					->setArgument('resolve_macros', 1)
					->setArgument('widget_view', 1)
					->setArgument('outer', 1)
					->setArgument('profileIdx', CControllerDockerTab::TIME_PROFILE_IDX)
					->setArgument('height', $height);

				$body = (new CTag('img', false))
					->setAttribute('alt', $title)
					->setAttribute('loading', 'lazy')
					->addClass('docker-chart-img')
					->setAttribute('data-chart', $url->getUrl());
			}
			else {
				$body = (new CDiv(_('No history data for this metric')))->addClass('docker-chart-empty');
			}

			$cell = (new CDiv([
				(new CDiv([
					(new CTag('h5', true, $title))->addClass('docker-cell-title'),
					(new CDiv($stats))->addClass('docker-cell-stats')
				]))->addClass('docker-cell-head'),
				$body
			]))
				->addClass('docker-cell')
				->addClass('docker-cell-chart');

			$result[$key] = $cell;
		}

		return $result;
	}
}

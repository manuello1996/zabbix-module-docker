<?php declare(strict_types = 0);

namespace Modules\MonzphereDocker\Actions;

use API;
use CController;
use CControllerResponseData;
use CDiv;
use CRoleHelper;
use CTag;
use CUrl;
use CWebUser;

class DockerContainer extends CController {
	private const FIELDS = [
		'docker.container_info.image' => 'image',
		'docker.container_info.restart_count' => 'restart_count',
		'docker.container_info.state.exitcode' => 'exitcode',
		'docker.container_info.state.health' => 'health',
		'docker.container_stats.pids_stats.current' => 'pids',
		'docker.container_stats.cpu_usage.throttled_periods' => 'throttled_periods',
		'docker.container_stats.cpu_usage.throttled_time' => 'throttled_time',
		'docker.networks.rx_errors' => 'rx_errors',
		'docker.networks.rx_dropped' => 'rx_dropped',
		'docker.networks.tx_errors' => 'tx_errors',
		'docker.networks.tx_dropped' => 'tx_dropped',
		'docker.container_stats.memory.limit' => 'memory_limit',
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

		$keys = [];

		foreach (array_merge(array_keys(self::FIELDS), self::CHART_KEYS) as $prefix) {
			$keys[] = $prefix.'["/'.$name.'"]';
			$keys[] = $prefix.'["'.$name.'"]';
		}

		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'key_', 'units', 'value_type', 'lastvalue', 'lastclock'],
			'selectValueMap' => ['mappings'],
			'hostids' => $hostid,
			'filter' => ['key_' => $keys],
			'monitored' => true
		]);

		if (!$items) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => _('No data found for this container.')
			])]));

			return;
		}

		$by_prefix = [];

		foreach ($items as $item) {
			$prefix = strstr($item['key_'], '[', true);

			if ($prefix !== false && !array_key_exists($prefix, $by_prefix)) {
				$by_prefix[$prefix] = $item;
			}
		}

		$content = (new CDiv())->addClass('mnz-docker-modal-content');
		$content->addItem($this->makeInfoGrid($by_prefix));

		foreach ($this->makeCharts($by_prefix) as $chart) {
			$content->addItem($chart);
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'html' => $content->toString()
		])]));
	}

	private function makeInfoGrid(array $by_prefix): CDiv {
		$labels = [
			'image' => _('Image'),
			'restart_count' => _('Restart count'),
			'exitcode' => _('Exit code'),
			'health' => _('Health'),
			'pids' => _('PIDs'),
			'throttled_periods' => _('CPU throttled periods'),
			'throttled_time' => _('CPU throttled time'),
			'rx_errors' => _('RX errors'),
			'rx_dropped' => _('RX dropped'),
			'tx_errors' => _('TX errors'),
			'tx_dropped' => _('TX dropped'),
			'memory_limit' => _('Memory limit'),
			'memory_max' => _('Memory max usage')
		];

		$grid = (new CDiv())->addClass('mnz-docker-modal-grid');

		foreach (self::FIELDS as $prefix => $field) {
			$item = $by_prefix[$prefix] ?? null;

			$value = ($item !== null && $item['lastclock'] > 0)
				? formatHistoryValue($item['lastvalue'], $item)
				: '-';

			$grid->addItem([
				(new CDiv($labels[$field]))->addClass('mnz-docker-modal-label'),
				(new CDiv($value))->addClass('mnz-docker-modal-value')
			]);
		}

		return $grid;
	}

	private function makeCharts(array $by_prefix): array {
		$timeline = getTimeSelectorPeriod([
			'profileIdx' => DockerTab::TIME_PROFILE_IDX,
			'profileIdx2' => 0
		]);

		$itemid = static fn (string $prefix): ?string => $by_prefix[$prefix]['itemid'] ?? null;

		$charts = [
			[_('CPU usage'), [
				$itemid('docker.container_stats.cpu_pct_usage')
			]],
			[_('Memory usage'), [
				$itemid('docker.container_stats.memory.usage_total'),
				$itemid('docker.container_stats.memory.max_usage')
			]],
			[_('Network traffic'), [
				$itemid('docker.networks.rx_bytes'),
				$itemid('docker.networks.tx_bytes')
			]]
		];

		$result = [];

		foreach ($charts as [$title, $itemids]) {
			$itemids = array_values(array_filter($itemids, static fn ($id) => $id !== null));

			if (!$itemids) {
				continue;
			}

			$url = (new CUrl('chart.php'))
				->setArgument('from', $timeline['from'])
				->setArgument('to', $timeline['to'])
				->setArgument('itemids', $itemids)
				->setArgument('type', GRAPH_TYPE_NORMAL)
				->setArgument('batch', 1)
				->setArgument('legend', 1)
				->setArgument('resolve_macros', 1)
				->setArgument('profileIdx', DockerTab::TIME_PROFILE_IDX)
				->setArgument('width', 936)
				->setArgument('height', 170);

			$result[] = (new CDiv([
				(new CTag('h5', true, $title))->addClass('mnz-docker-graph-title'),
				(new CTag('img', false))
					->setAttribute('alt', $title)
					->setAttribute('loading', 'lazy')
					->setAttribute('src', $url->getUrl())
			]))->addClass('mnz-docker-graph');
		}

		return $result;
	}
}

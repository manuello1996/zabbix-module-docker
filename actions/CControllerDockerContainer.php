<?php declare(strict_types = 0);

namespace Modules\MonzphereDocker\Actions;

use API;
use CLink;
use CController;
use CControllerResponseData;
use CDiv;
use CRoleHelper;
use CSpan;
use CTag;
use CUrl;
use CWebUser;

class CControllerDockerContainer extends CController {
	private const FIELDS = [
		'docker.container.description' => 'description',
		'docker.container_info.image' => 'image',
		'docker.container_info.networks' => 'networks',
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
		$content->addItem($this->makeIdentityStrip($by_prefix));
		$content->addItem($this->makeNetworkSection($by_prefix));

		foreach ($this->makeChartCells($by_prefix) as $cell) {
			$content->addItem($cell);
		}

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
		$value = (new CSpan($this->formattedValue($by_prefix, $prefix)))->addClass('mnz-docker-stat-value');
		$raw = $this->rawValue($by_prefix, $prefix);

		if ($raw !== null && (float) $raw != 0) {
			$value->addClass('mnz-docker-value-bad');
		}

		return $value;
	}

	private function makeIdentityStrip(array $by_prefix): CDiv {
		$health_raw = $this->rawValue($by_prefix, 'docker.container_info.state.health');

		$health = (new CSpan($this->formattedValue($by_prefix, 'docker.container_info.state.health')))
			->addClass('mnz-docker-stat-value');

		if ($health_raw !== null && (int) $health_raw == 3) {
			$health->addClass('mnz-docker-chip-ok');
		}
		elseif ($health_raw !== null && (int) $health_raw == 2) {
			$health->addClass('mnz-docker-value-bad');
		}

		$image = $this->formattedValue($by_prefix, 'docker.container_info.image', false);

		$chip = static function (string $label, $value): CDiv {
			return (new CDiv([
				(new CDiv($label))->addClass('mnz-docker-chip-label'),
				(new CDiv($value))->addClass('mnz-docker-chip-body')
			]))->addClass('mnz-docker-idchip');
		};

		return (new CDiv([
			$chip(_('Health'), $health),
			$chip(_('Exit code'), $this->countValue($by_prefix, 'docker.container_info.state.exitcode')),
			$chip(_('Restarts'), $this->formattedValue($by_prefix, 'docker.container_info.restart_count')),
			$chip(_('PIDs'), $this->formattedValue($by_prefix, 'docker.container_stats.pids_stats.current')),
			$chip(_('Image'), $image)
				->addClass('mnz-docker-idchip-image')
				->setTitle($image)
		]))->addClass('mnz-docker-idbar');
	}

	private function makeNetworkSection(array $by_prefix): CDiv {
		$raw = $this->rawValue($by_prefix, 'docker.container_info.networks');
		$networks = $raw !== null ? json_decode($raw, true) : null;
		$body = (new CDiv())->addClass('mnz-docker-modal-network-grid');

		if (!is_array($networks) || !$networks) {
			$body->addItem(
				(new CDiv(_('No network information collected for this container.')))
					->addClass('mnz-docker-muted')
			);
		}
		else {
			ksort($networks);

			foreach ($networks as $network_name => $network) {
				if (!is_array($network)) {
					continue;
				}

				$facts = [];
				$ip = (string) ($network['IPAddress'] ?? '');

				$facts[] = $this->makeNetworkFact(_('IP address'), $ip !== '' ? $ip : '-');

				foreach ([
					[_('Network ID'), $network['NetworkID'] ?? '']
				] as [$label, $value]) {
					if ((string) $value !== '') {
						$facts[] = $this->makeNetworkFact($label, (string) $value);
					}
				}

				$dns_names = array_values(array_filter(
					array_map('strval', (array) ($network['DNSNames'] ?? [])),
					'strlen'
				));

				if ($dns_names) {
					$dns_nodes = [];

					foreach ($dns_names as $dns_name) {
						$dns_nodes[] = $this->makeDnsName($dns_name);
					}

					$facts[] = $this->makeNetworkFact(_('DNS names'),
						(new CDiv($dns_nodes))->addClass('mnz-docker-modal-network-list')
					);
				}

				foreach ([
					[_('Links'), $network['Links'] ?? null]
				] as [$label, $value]) {
					if ($value !== null && $value !== [] && $value !== '') {
						$facts[] = $this->makeNetworkFact($label,
							is_array($value)
								? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
								: (string) $value
						);
					}
				}

				$body->addItem(
					(new CDiv([
						(new CTag('h6', true, (string) $network_name))
							->addClass('mnz-docker-modal-network-name'),
						new CDiv($facts)
					]))->addClass('mnz-docker-modal-network-card')
				);
			}
		}

		return (new CDiv([
			(new CTag('h5', true, _('Network information')))->addClass('mnz-docker-cell-title'),
			$body
		]))
			->addClass('mnz-docker-cell')
			->addClass('mnz-docker-cell-wide')
			->addClass('mnz-docker-modal-network-section');
	}

	private function makeNetworkFact(string $label, $value): CDiv {
		return (new CDiv([
			(new CSpan($label))->addClass('mnz-docker-modal-network-label'),
			(new CDiv($value))->addClass('mnz-docker-modal-network-value')
		]))->addClass('mnz-docker-modal-network-fact');
	}

	private function makeDnsName(string $dns_name) {
		$is_ch_fqdn = preg_match(
			'/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+ch$/i',
			$dns_name
		) == 1;

		return ($is_ch_fqdn
			? (new CLink($dns_name, 'https://'.$dns_name))
				->setTarget('_blank')
				->setAttribute('rel', 'noopener noreferrer')
			: new CSpan($dns_name)
		)->addClass('mnz-docker-modal-network-list-item');
	}

	private function makeChartCells(array $by_prefix): array {
		$timeline = getTimeSelectorPeriod([
			'profileIdx' => CControllerDockerTab::TIME_PROFILE_IDX,
			'profileIdx2' => 0
		]);

		$itemid = static fn (string $prefix): ?string => $by_prefix[$prefix]['itemid'] ?? null;

		$charts = [
			[_('CPU usage'), [
				$itemid('docker.container_stats.cpu_pct_usage')
			], [
				(new CSpan(_('Throttled').': '))->addClass('mnz-docker-stat-label'),
				$this->countValue($by_prefix, 'docker.container_stats.cpu_usage.throttled_periods'),
				' / ',
				(new CSpan($this->formattedValue($by_prefix, 'docker.container_stats.cpu_usage.throttled_time')))
					->addClass('mnz-docker-stat-value')
			], 0, 200, true],
			[_('Memory usage'), [
				$itemid('docker.container_stats.memory.usage_total')
			], [
				(new CSpan())->addClass('mnz-docker-series-dot')->addClass('mnz-docker-series-dot-1'),
				(new CSpan(_('Used').' '))->addClass('mnz-docker-stat-label'),
				(new CSpan($this->formattedValue($by_prefix, 'docker.container_stats.memory.usage_total')))
					->addClass('mnz-docker-stat-value'),
				' — ',
				(new CSpan(_('Max').' '))->addClass('mnz-docker-stat-label'),
				(new CSpan($this->formattedValue($by_prefix, 'docker.container_stats.memory.max_usage')))
					->addClass('mnz-docker-stat-value')
			], 0, 220, false],
			[_('Network traffic'), [
				$itemid('docker.networks.rx_bytes'),
				$itemid('docker.networks.tx_bytes')
			], [
				(new CSpan())->addClass('mnz-docker-series-dot')->addClass('mnz-docker-series-dot-1'),
				(new CSpan('RX'))->addClass('mnz-docker-stat-label'),
				' ',
				(new CSpan())->addClass('mnz-docker-series-dot')->addClass('mnz-docker-series-dot-2'),
				(new CSpan('TX'))->addClass('mnz-docker-stat-label'),
				' — ',
				(new CSpan(_('Errors').' '))->addClass('mnz-docker-stat-label'),
				$this->countValue($by_prefix, 'docker.networks.rx_errors'),
				'/',
				$this->countValue($by_prefix, 'docker.networks.tx_errors'),
				' — ',
				(new CSpan(_('Dropped').' '))->addClass('mnz-docker-stat-label'),
				$this->countValue($by_prefix, 'docker.networks.rx_dropped'),
				'/',
				$this->countValue($by_prefix, 'docker.networks.tx_dropped')
			], 0, 220, false]
		];

		$result = [];

		foreach ($charts as [$title, $itemids, $stats, $legend, $height, $wide]) {
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
					->addClass('mnz-docker-chart-img')
					->setAttribute('data-mnz-chart', $url->getUrl());
			}
			else {
				$body = (new CDiv(_('No history data for this metric')))->addClass('mnz-docker-chart-empty');
			}

			$cell = (new CDiv([
				(new CDiv([
					(new CTag('h5', true, $title))->addClass('mnz-docker-cell-title'),
					(new CDiv($stats))->addClass('mnz-docker-cell-stats')
				]))->addClass('mnz-docker-cell-head'),
				$body
			]))
				->addClass('mnz-docker-cell')
				->addClass('mnz-docker-cell-chart');

			if ($wide) {
				$cell->addClass('mnz-docker-cell-wide');
			}

			$result[] = $cell;
		}

		return $result;
	}
}

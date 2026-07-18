<?php declare(strict_types = 0);

namespace Modules\MonzphereDocker\Includes;

use API;
use CSettingsHelper;
use Manager;

class DockerCollector {
	public const HOST_KEYS = [
		'docker.containers.total' => 'total',
		'docker.containers.running' => 'running',
		'docker.containers.stopped' => 'stopped',
		'docker.containers.paused' => 'paused'
	];

	public const CONTAINER_KEYS = [
		'docker.container_info.state.status' => 'status',
		'docker.container_stats.cpu_pct_usage' => 'cpu',
		'docker.container_stats.memory.usage_total' => 'memory',
		'docker.container_stats.memory.limit' => 'memory_limit',
		'docker.networks.rx_bytes' => 'net_in',
		'docker.networks.tx_bytes' => 'net_out',
		'docker.container_info.started' => 'started'
	];

	public const SPARKLINE_PERIOD = 3600;

	private const SPARKLINE_FIELDS = ['cpu', 'memory'];

	private const SPARKLINE_POINTS = 60;

	public static function collect(string $hostid): array {
		$items = API::Item()->get([
			'output' => ['itemid', 'key_', 'value_type', 'units', 'lastvalue', 'lastclock'],
			'hostids' => $hostid,
			'search' => ['key_' => array_merge(array_keys(self::HOST_KEYS), array_keys(self::CONTAINER_KEYS))],
			'searchByAny' => true,
			'startSearch' => true,
			'monitored' => true,
			'webitems' => false,
			'preservekeys' => true
		]);

		if (!$items) {
			return ['overview' => self::emptyOverview(), 'containers' => []];
		}

		$last_values = Manager::History()->getLastValues($items, 1, timeUnitToSeconds(
			CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD)
		));

		$overview = self::emptyOverview();
		$containers = [];
		$sparkline_itemids = [];

		foreach ($items as $itemid => $item) {
			$value = array_key_exists($itemid, $last_values)
				? $last_values[$itemid][0]['value']
				: null;

			if (array_key_exists($item['key_'], self::HOST_KEYS)) {
				$overview[self::HOST_KEYS[$item['key_']]] = $value !== null ? (int) $value : null;
				continue;
			}

			[$prefix, $container] = self::parseKey($item['key_']);

			if ($prefix === null || !array_key_exists($prefix, self::CONTAINER_KEYS)) {
				continue;
			}

			$field = self::CONTAINER_KEYS[$prefix];
			$name = ltrim($container, '/');

			if (!array_key_exists($name, $containers)) {
				$containers[$name] = self::emptyContainer($name);
			}

			$containers[$name][$field] = $value;

			if (in_array($field, self::SPARKLINE_FIELDS, true)) {
				$containers[$name][$field.'_itemid'] = $itemid;
				$sparkline_itemids[$itemid] = $item['value_type'];
			}
		}

		$history = self::getSparklineHistory($sparkline_itemids);

		foreach ($containers as &$container) {
			foreach (self::SPARKLINE_FIELDS as $field) {
				$itemid = $container[$field.'_itemid'];
				$container[$field.'_history'] = ($itemid !== null && array_key_exists($itemid, $history))
					? $history[$itemid]
					: [];
			}

			$container['is_running'] = $container['status'] === 'running';
			$container['uptime'] = ($container['is_running'] && $container['started'] !== null)
				? max(0, time() - (int) $container['started'])
				: null;
		}
		unset($container);

		$overview['cpu_total'] = 0;
		$overview['memory_total'] = 0;

		foreach ($containers as $container) {
			if ($container['is_running']) {
				$overview['cpu_total'] += (float) $container['cpu'];
				$overview['memory_total'] += (float) $container['memory'];
			}
		}

		ksort($containers);

		return ['overview' => $overview, 'containers' => array_values($containers)];
	}

	private static function parseKey(string $key): array {
		if (preg_match('/^([a-z0-9._]+)\["?([^"\]]+)"?\]$/i', $key, $matches) != 1) {
			return [null, ''];
		}

		return [$matches[1], $matches[2]];
	}

	private static function getSparklineHistory(array $itemids): array {
		if (!$itemids) {
			return [];
		}

		$items = [];

		foreach ($itemids as $itemid => $value_type) {
			$items[] = [
				'itemid' => $itemid,
				'value_type' => $value_type,
				'source' => 'history'
			];
		}

		$aggregation = Manager::History()->getGraphAggregationByWidth($items, time() - self::SPARKLINE_PERIOD,
			time(), self::SPARKLINE_POINTS - 1
		);

		$result = [];

		foreach ($aggregation as $itemid => $item_data) {
			foreach ($item_data['data'] as $point) {
				$result[$itemid][] = [(int) $point['clock'], $point['avg']];
			}
		}

		return $result;
	}

	private static function emptyOverview(): array {
		return [
			'total' => null,
			'running' => null,
			'stopped' => null,
			'paused' => null,
			'cpu_total' => 0,
			'memory_total' => 0
		];
	}

	private static function emptyContainer(string $name): array {
		return [
			'name' => $name,
			'status' => null,
			'cpu' => null,
			'cpu_itemid' => null,
			'cpu_history' => [],
			'memory' => null,
			'memory_itemid' => null,
			'memory_history' => [],
			'memory_limit' => null,
			'net_in' => null,
			'net_out' => null,
			'started' => null,
			'uptime' => null,
			'is_running' => false
		];
	}
}

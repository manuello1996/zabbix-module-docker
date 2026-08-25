<?php declare(strict_types = 0);

namespace Modules\MonzphereDocker\Includes;

use API;
use CSettingsHelper;
use Manager;

class DockerCollector {
	private const DOCKER_TEMPLATE_NAME_FRAGMENT = 'Docker by Zabbix agent 2';

	public const HOST_KEYS = [
		'docker.containers.total' => 'total',
		'docker.containers.running' => 'running',
		'docker.containers.stopped' => 'stopped',
		'docker.containers.paused' => 'paused',
		'lxp.docker.sum_healthy' => 'healthy',
		'lxp.docker.sum_unhealthy' => 'unhealthy'
	];

	public const CONTAINER_KEYS = [
		'docker.container.description' => 'note',
		'docker.container_info.state.status' => 'status',
		'docker.container_info.state.exitcode' => 'exitcode',
		'docker.container_info.restart_count' => 'restarts',
		'docker.container_stats.cpu_pct_usage' => 'cpu',
		'docker.container_stats.memory.usage_total' => 'memory',
		'docker.networks.rx_bytes' => 'net_in',
		'docker.networks.tx_bytes' => 'net_out',
		'docker.container_info.started' => 'started'
	];

	public const NODE_KEYS = [
		'system.uptime' => 'uptime',
		'docker.mem.total' => 'mem_total',
		'docker.images.total' => 'images_total',
		'docker.images_size' => 'images_size'
	];

	public const SPARKLINE_PERIOD = 86400;

	private const SPARKLINE_FIELDS = ['cpu', 'memory'];

	private const SPARKLINE_POINTS = 60;

	public static function hasRecentValue(array $item): bool {
		static $minimum_clock = null;

		if ($minimum_clock === null) {
			$minimum_clock = time() - timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD));
		}

		return (int) ($item['lastclock'] ?? 0) >= $minimum_clock;
	}

	public static function collect(string $hostid): array {
		$items = API::Item()->get([
			'output' => ['itemid', 'key_', 'lastvalue', 'lastclock'],
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

		$overview = self::emptyOverview();
		$containers = [];

		foreach ($items as $itemid => $item) {
			$value = self::hasRecentValue($item) ? $item['lastvalue'] : null;

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
			}
		}

		foreach ($containers as &$container) {
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

	public static function nodeInfo(string $hostid): array {
		$info = array_fill_keys(array_values(self::NODE_KEYS), null);

		$items = API::Item()->get([
			'output' => ['key_', 'lastvalue', 'lastclock'],
			'hostids' => $hostid,
			'filter' => ['key_' => array_keys(self::NODE_KEYS)],
			'monitored' => true
		]);

		if (!$items) {
			return $info;
		}

		foreach ($items as $item) {
			if (self::hasRecentValue($item)) {
				$info[self::NODE_KEYS[$item['key_']]] = $item['lastvalue'];
			}
		}

		return $info;
	}

	public static function problemsBySeverity(string $hostid): array {
		$problems = API::Problem()->get([
			'output' => ['severity'],
			'hostids' => $hostid,
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'suppressed' => false,
			'symptom' => false
		]);

		$by_severity = [];

		foreach ($problems as $problem) {
			$severity = (int) $problem['severity'];
			$by_severity[$severity] = ($by_severity[$severity] ?? 0) + 1;
		}

		krsort($by_severity);

		return $by_severity;
	}

	public static function problemsByHosts(array $hostids): array {
		return self::collectProblemsByHosts($hostids, false);
	}

	public static function dockerTemplateProblemsByHosts(array $hostids): array {
		return self::collectProblemsByHosts($hostids, true);
	}

	public static function dockerTemplateTriggerIds(array $triggerids): array {
		if (!$triggerids) {
			return [];
		}

		$triggers = API::Trigger()->get([
			'output' => ['templateid'],
			'triggerids' => $triggerids,
			'selectTriggerDiscovery' => ['parent_triggerid'],
			'preservekeys' => true
		]);

		return array_keys(self::getDockerTemplateTriggerDescendants($triggerids, $triggers));
	}

	private static function collectProblemsByHosts(array $hostids, bool $docker_template_only): array {
		if (!$hostids) {
			return [];
		}

		$trigger_options = [
			'output' => $docker_template_only ? ['templateid'] : [],
			'selectHosts' => ['hostid'],
			'hostids' => $hostids,
			'skipDependent' => true,
			'monitored' => true,
			'preservekeys' => true
		];

		if ($docker_template_only) {
			$trigger_options['selectTriggerDiscovery'] = ['parent_triggerid'];
		}

		$triggers = API::Trigger()->get($trigger_options);

		if (!$triggers) {
			return [];
		}

		$problems = API::Problem()->get([
			'output' => ['eventid', 'objectid', 'severity'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'objectids' => array_keys($triggers),
			'suppressed' => false,
			'symptom' => false
		]);

		if ($docker_template_only) {
			$docker_triggerids = self::getDockerTemplateTriggerDescendants(
				array_values(array_unique(array_column($problems, 'objectid'))), $triggers
			);
			$problems = array_filter($problems,
				static fn (array $problem): bool => array_key_exists($problem['objectid'], $docker_triggerids)
			);
		}

		$wanted = array_flip($hostids);
		$result = [];

		foreach ($problems as $problem) {
			foreach ($triggers[$problem['objectid']]['hosts'] as $host) {
				if (array_key_exists($host['hostid'], $wanted)) {
					$result[$host['hostid']]['events'][$problem['eventid']] = (int) $problem['severity'];
				}
			}
		}

		foreach ($result as &$row) {
			$by_severity = [];

			foreach ($row['events'] as $severity) {
				$by_severity[$severity] = ($by_severity[$severity] ?? 0) + 1;
			}

			krsort($by_severity);
			$row = ['by_severity' => $by_severity];
		}
		unset($row);

		return $result;
	}

	private static function getDockerTemplateTriggerDescendants(array $triggerids, array $known_triggers): array {
		if (!$triggerids) {
			return [];
		}

		$templates = API::Template()->get([
			'output' => [],
			'search' => ['name' => self::DOCKER_TEMPLATE_NAME_FRAGMENT],
			'preservekeys' => true
		]);

		if (!$templates) {
			return [];
		}

		$template_triggers = API::Trigger()->get([
			'output' => [],
			'templateids' => array_keys($templates),
			'preservekeys' => true
		]);
		$template_trigger_prototypes = API::TriggerPrototype()->get([
			'output' => [],
			'templateids' => array_keys($templates),
			'preservekeys' => true
		]);

		if (!$template_triggers && !$template_trigger_prototypes) {
			return [];
		}

		$docker_triggerids = array_fill_keys(
			array_merge(array_keys($template_triggers), array_keys($template_trigger_prototypes)), true
		);
		$parents = [];

		foreach ($known_triggers as $triggerid => $trigger) {
			$discovery_parentid = (string) ($trigger['triggerDiscovery']['parent_triggerid'] ?? '0');
			$parents[$triggerid] = $discovery_parentid !== '0'
				? $discovery_parentid
				: (string) ($trigger['templateid'] ?? '0');
		}

		$pending = [];

		foreach ($triggerids as $triggerid) {
			$parentid = $parents[$triggerid] ?? '0';

			if ($parentid !== '0' && !array_key_exists($parentid, $parents)
					&& !array_key_exists($parentid, $docker_triggerids)) {
				$pending[$parentid] = true;
			}
		}

		while ($pending) {
			$ancestors = API::Trigger()->get([
				'output' => ['templateid'],
				'triggerids' => array_keys($pending),
				'selectTriggerDiscovery' => ['parent_triggerid'],
				'preservekeys' => true
			]);
			$ancestor_prototypes = API::TriggerPrototype()->get([
				'output' => ['templateid'],
				'triggerids' => array_keys($pending),
				'preservekeys' => true
			]);
			$pending = [];

			foreach ($ancestors + $ancestor_prototypes as $triggerid => $trigger) {
				$discovery_parentid = (string) ($trigger['triggerDiscovery']['parent_triggerid'] ?? '0');
				$parentid = $discovery_parentid !== '0'
					? $discovery_parentid
					: (string) ($trigger['templateid'] ?? '0');
				$parents[$triggerid] = $parentid;

				if ($parentid !== '0' && !array_key_exists($parentid, $parents)
						&& !array_key_exists($parentid, $docker_triggerids)) {
					$pending[$parentid] = true;
				}
			}
		}

		$result = [];

		foreach ($triggerids as $triggerid) {
			$currentid = (string) $triggerid;
			$visited = [];

			while ($currentid !== '0' && !array_key_exists($currentid, $visited)) {
				if (array_key_exists($currentid, $docker_triggerids)) {
					$result[$triggerid] = true;
					break;
				}

				$visited[$currentid] = true;
				$currentid = $parents[$currentid] ?? '0';
			}
		}

		return $result;
	}

	public static function sparklineHistory(array $itemids): array {
		return self::getSparklineHistory($itemids);
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
			'healthy' => null,
			'unhealthy' => null,
			'cpu_total' => 0,
			'memory_total' => 0
		];
	}

	private static function emptyContainer(string $name): array {
		return [
			'name' => $name,
			'note' => null,
			'status' => null,
			'exitcode' => null,
			'restarts' => null,
			'cpu' => null,
			'cpu_itemid' => null,
			'memory' => null,
			'memory_itemid' => null,
			'net_in' => null,
			'net_out' => null,
			'started' => null,
			'uptime' => null,
			'is_running' => false
		];
	}
}

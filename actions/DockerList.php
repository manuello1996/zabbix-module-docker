<?php declare(strict_types = 0);

namespace Modules\MonzphereDocker\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CRoleHelper;
use CSettingsHelper;
use CUrl;
use CWebUser;
use Manager;

class DockerList extends CController {
	public const PROFILE_GROUPIDS = 'web.monzphere.docker.list.filter.groupids';
	public const PROFILE_NAME = 'web.monzphere.docker.list.filter.name';

	private const NODE_KEYS = [
		'docker.containers.total' => 'total',
		'docker.containers.running' => 'running',
		'docker.containers.stopped' => 'stopped',
		'docker.containers.paused' => 'paused',
		'docker.server_version' => 'version',
		'docker.mem.total' => 'memory'
	];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_groupids' =>	'array_db hstgrp.groupid',
			'filter_name' =>		'string',
			'filter_set' =>			'in 1',
			'filter_rst' =>			'in 1',
			'page' =>				'ge 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);
	}

	protected function doAction(): void {
		if ($this->hasInput('filter_rst')) {
			CProfile::deleteIdx(self::PROFILE_GROUPIDS);
			CProfile::delete(self::PROFILE_NAME);
		}
		elseif ($this->hasInput('filter_set')) {
			CProfile::updateArray(self::PROFILE_GROUPIDS, $this->getInput('filter_groupids', []),
				PROFILE_TYPE_ID
			);
			CProfile::update(self::PROFILE_NAME, trim($this->getInput('filter_name', '')), PROFILE_TYPE_STR);
		}

		$groupids = CProfile::getArray(self::PROFILE_GROUPIDS, []);
		$name = CProfile::get(self::PROFILE_NAME, '');

		$groups = $groupids
			? API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $groupids,
				'preservekeys' => true
			])
			: [];

		$docker_items = API::Item()->get([
			'output' => ['hostid'],
			'filter' => ['key_' => 'docker.containers.total'],
			'monitored' => true
		]);

		$docker_hostids = array_unique(array_column($docker_items, 'hostid'));

		$search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$hosts = $docker_hostids
			? API::Host()->get([
				'output' => ['hostid', 'name', 'status'],
				'selectInterfaces' => ['interfaceid', 'type', 'available', 'useip', 'ip', 'dns', 'port', 'error',
					'details'
				],
				'hostids' => $docker_hostids,
				'groupids' => $groups ? array_keys($groups) : null,
				'search' => $name !== '' ? ['name' => $name] : null,
				'preservekeys' => true,
				'sortfield' => 'name',
				'limit' => $search_limit
			])
			: [];

		foreach ($hosts as &$host) {
			foreach ($host['interfaces'] as &$interface) {
				$interface['interface'] = getHostInterface($interface);
				$interface['description'] = '';
				$interface['has_enabled_items'] = true;
			}
			unset($interface);
		}
		unset($host);

		$nodes = $this->collectNodeMetrics($hosts);

		$problems = $this->collectNodeProblems(array_keys($hosts));

		foreach ($nodes as $hostid => &$node) {
			$node['problems'] = $problems[$hostid] ?? ['by_severity' => []];
		}
		unset($node);

		$totals = [
			'nodes' => count($nodes),
			'total' => 0,
			'running' => 0,
			'stopped' => 0
		];

		foreach ($nodes as $node) {
			$totals['total'] += (int) $node['metrics']['total'];
			$totals['running'] += (int) $node['metrics']['running'];
			$totals['stopped'] += (int) $node['metrics']['stopped'];
		}

		$nodes = array_values($nodes);
		$paging = CPagerHelper::paginate((int) $this->getInput('page', 1), $nodes, ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', 'monzphere.docker.list')
		);

		$response = new CControllerResponseData([
			'filter' => [
				'groupids' => $groups ? array_keys($groups) : [],
				'groups' => array_values($groups),
				'name' => $name
			],
			'paging' => $paging,
			'nodes' => $nodes,
			'totals' => $totals,
			'refresh_interval' => timeUnitToSeconds(CWebUser::getRefresh())
		]);
		$response->setTitle(_('Docker nodes'));

		$this->setResponse($response);
	}

	private function collectNodeMetrics(array $hosts): array {
		if (!$hosts) {
			return [];
		}

		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_', 'value_type'],
			'hostids' => array_keys($hosts),
			'filter' => ['key_' => array_keys(self::NODE_KEYS)],
			'monitored' => true,
			'preservekeys' => true
		]);

		$last_values = $items
			? Manager::History()->getLastValues($items, 1, timeUnitToSeconds(
				CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD)
			))
			: [];

		$empty_metrics = array_fill_keys(array_values(self::NODE_KEYS), null);
		$nodes = [];

		foreach ($hosts as $hostid => $host) {
			$nodes[$hostid] = $host + ['metrics' => $empty_metrics];
		}

		foreach ($items as $itemid => $item) {
			if (!array_key_exists($itemid, $last_values)) {
				continue;
			}

			$field = self::NODE_KEYS[$item['key_']];
			$nodes[$item['hostid']]['metrics'][$field] = $last_values[$itemid][0]['value'];
		}

		return $nodes;
	}

	private function collectNodeProblems(array $hostids): array {
		if (!$hostids) {
			return [];
		}

		$triggers = API::Trigger()->get([
			'output' => [],
			'selectHosts' => ['hostid'],
			'hostids' => $hostids,
			'skipDependent' => true,
			'monitored' => true,
			'preservekeys' => true
		]);

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

		$wanted = array_flip($hostids);
		$result = [];

		foreach ($problems as $problem) {
			foreach ($triggers[$problem['objectid']]['hosts'] as $host) {
				if (!array_key_exists($host['hostid'], $wanted)) {
					continue;
				}

				$result[$host['hostid']]['events'][$problem['eventid']] = (int) $problem['severity'];
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
}

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
use Modules\MonzphereDocker\Includes\DockerCollector;

class CControllerDockerView extends CController {
	public const PROFILE_GROUPIDS = 'web.monzphere.docker.filter.groupids';
	public const PROFILE_HOSTID = 'web.monzphere.docker.filter.hostid';

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_groupids' =>	'array_db hstgrp.groupid',
			'filter_hostid' =>		'array_db hosts.hostid',
			'filter_set' =>			'in 1',
			'filter_rst' =>			'in 1',
			'page' =>				'ge 1',
			'from' =>				'range_time',
			'to' =>					'range_time'
		];

		$ret = $this->validateInput($fields) && $this->validateTimeSelectorPeriod();

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);
	}

	protected function doAction(): void {
		$was_reset = $this->hasInput('filter_rst');

		if ($was_reset) {
			CProfile::deleteIdx(self::PROFILE_GROUPIDS);
			CProfile::deleteIdx(self::PROFILE_HOSTID);
		}
		elseif ($this->hasInput('filter_set')) {
			CProfile::updateArray(self::PROFILE_GROUPIDS, $this->getInput('filter_groupids', []),
				PROFILE_TYPE_ID
			);
			$filter_hostids = $this->getInput('filter_hostid', []);
			CProfile::update(self::PROFILE_HOSTID, $filter_hostids ? (string) reset($filter_hostids) : '',
				PROFILE_TYPE_STR
			);
		}

		$groupids = CProfile::getArray(self::PROFILE_GROUPIDS, []);
		$hostid = CProfile::get(self::PROFILE_HOSTID, '');

		$groups = $groupids
			? API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $groupids,
				'preservekeys' => true
			])
			: [];

		$docker_items = API::Item()->get([
			'output' => ['hostid'],
			'groupids' => $groups ? array_keys($groups) : null,
			'filter' => ['key_' => 'docker.containers.total'],
			'monitored' => true
		]);

		$docker_hostids = array_keys(array_flip(array_column($docker_items, 'hostid')));

		$search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$hosts = $docker_hostids
			? API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => $docker_hostids,
				'preservekeys' => true,
				'sortfield' => 'name',
				'limit' => $search_limit
			])
			: [];

		if ($hostid !== '' && !array_key_exists($hostid, $hosts)) {
			$hostid = '';
		}

		$host = null;

		if ($hostid !== '') {
			$db_hosts = API::Host()->get([
				'output' => ['hostid', 'name', 'status'],
				'selectInterfaces' => ['interfaceid', 'type', 'available', 'useip', 'ip', 'dns', 'port', 'error',
					'details'
				],
				'selectInventory' => ['notes'],
				'hostids' => [$hostid]
			]);

			$host = $db_hosts ? reset($db_hosts) : null;

			if ($host === null) {
				$hostid = '';
			}
			else {
				foreach ($host['interfaces'] as &$interface) {
					$interface['interface'] = getHostInterface($interface);
					$interface['description'] = '';

					$interface['has_enabled_items'] = true;
				}
				unset($interface);
			}
		}

		$timeselector_options = [
			'profileIdx' => 'web.monzphere.docker.filter',
			'profileIdx2' => 0,
			'from' => $this->hasInput('from')
				? $this->getInput('from')
				: CProfile::get('web.monzphere.docker.filter.from',
					'now-'.CSettingsHelper::get(CSettingsHelper::PERIOD_DEFAULT)
				),
			'to' => $this->hasInput('to')
				? $this->getInput('to')
				: CProfile::get('web.monzphere.docker.filter.to', 'now')
		];

		updateTimeSelectorPeriod($timeselector_options);

		$data = [
			'filter' => [
				'groupids' => $groups ? array_keys($groups) : [],
				'groups' => array_values($groups),
				'hostid' => $hostid
			],
			'timeline' => getTimeSelectorPeriod($timeselector_options),

			'active_tab' => CProfile::get('web.monzphere.docker.filter.active', 0),

			'hosts' => array_values($hosts),
			'host' => $host,
			'overview' => null,
			'containers' => [],
			'node' => array_fill_keys(array_values(DockerCollector::NODE_KEYS), null),
			'problems_by_severity' => [],
			'agent_address' => ''
		];

		if ($hostid !== '') {
			$data = array_replace($data, DockerCollector::collect($hostid));

			$data['node'] = DockerCollector::nodeInfo($hostid);
			$data['problems_by_severity'] = DockerCollector::problemsBySeverity($hostid);

			foreach ($host['interfaces'] as $interface) {
				if ((int) $interface['type'] == INTERFACE_TYPE_AGENT) {
					$data['agent_address'] = (int) $interface['useip'] == INTERFACE_USE_IP
						? $interface['ip']
						: $interface['dns'];
					break;
				}
			}

			$data['containers_total'] = count($data['containers']);

			$data['paging'] = CPagerHelper::paginate((int) $this->getInput('page', 1), $data['containers'],
				ZBX_SORT_UP, (new CUrl('zabbix.php'))->setArgument('action', 'monzphere.docker.view')
			);
		}

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Docker'));

		$this->setResponse($response);
	}

	public static function filterDockerHosts(array $hosts): array {
		if (!$hosts) {
			return [];
		}

		$items = API::Item()->get([
			'output' => ['hostid'],
			'hostids' => array_keys($hosts),
			'filter' => ['key_' => 'docker.containers.total'],
			'monitored' => true
		]);

		$docker_hostids = array_flip(array_column($items, 'hostid'));

		return array_intersect_key($hosts, $docker_hostids);
	}
}

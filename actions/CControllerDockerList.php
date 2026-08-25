<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Actions;

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
use Modules\MonitorDocker\Includes\DockerCollector;

class CControllerDockerList extends CController {
	public const PROFILE_GROUPIDS = 'web.docker.list.filter.groupids';
	public const PROFILE_NAME = 'web.docker.list.filter.name';
	public const PROFILE_PROBLEMS = 'web.docker.list.filter.problems';
	public const PROFILE_DOCKER_PROBLEMS = 'web.docker.list.filter.docker_problems';
	public const PROFILE_CONTAINER_STATES = 'web.docker.list.filter.container_states';
	public const PROFILE_TAG_EVALTYPE = 'web.docker.list.filter.tag_evaltype';
	public const PROFILE_TAGS_TAG = 'web.docker.list.filter.tags.tag';
	public const PROFILE_TAGS_VALUE = 'web.docker.list.filter.tags.value';
	public const PROFILE_TAGS_OPERATOR = 'web.docker.list.filter.tags.operator';

	private const CONTAINER_STATES = ['stopped', 'paused', 'unhealthy', 'no_running'];

	private const NODE_KEYS = [
		'docker.containers.total' => 'total',
		'docker.containers.running' => 'running',
		'docker.containers.stopped' => 'stopped',
		'docker.containers.paused' => 'paused',
		'lxp.docker.sum_unhealthy' => 'unhealthy'
	];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_name' =>		'string',
			'filter_groupids' =>	'array_id',
			'filter_problems' =>	'in 0,1',
			'filter_docker_problems' => 'in 0,1',
			'filter_container_states' => 'array',
			'filter_tag_evaltype' => 'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'filter_tags' =>		'array',
			'filter_set' =>			'in 1',
			'filter_rst' =>			'in 1',
			'page' =>				'ge 1'
		];

		$ret = $this->validateInput($fields);

		if ($ret && $this->hasInput('filter_container_states')) {
			foreach ($this->getInput('filter_container_states') as $state) {
				if (!is_string($state) || !in_array($state, self::CONTAINER_STATES, true)) {
					$ret = false;
					break;
				}
			}
		}

		if ($ret && $this->hasInput('filter_tags')) {
			foreach ($this->getInput('filter_tags') as $tag) {
				if (!is_array($tag) || !array_key_exists('tag', $tag) || !is_string($tag['tag'])
						|| !array_key_exists('value', $tag) || !is_string($tag['value'])
						|| !array_key_exists('operator', $tag) || !is_string($tag['operator'])
						|| !ctype_digit($tag['operator'])
						|| !in_array((int) $tag['operator'], [TAG_OPERATOR_EXISTS, TAG_OPERATOR_EQUAL,
							TAG_OPERATOR_LIKE, TAG_OPERATOR_NOT_EXISTS, TAG_OPERATOR_NOT_EQUAL,
							TAG_OPERATOR_NOT_LIKE
						], true)) {
					$ret = false;
					break;
				}
			}
		}

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
			CProfile::deleteIdx('web.docker.list.filter.hostids');
			CProfile::delete(self::PROFILE_PROBLEMS);
			CProfile::delete(self::PROFILE_DOCKER_PROBLEMS);
			CProfile::deleteIdx(self::PROFILE_CONTAINER_STATES);
			CProfile::delete(self::PROFILE_TAG_EVALTYPE);
			CProfile::deleteIdx(self::PROFILE_TAGS_TAG);
			CProfile::deleteIdx(self::PROFILE_TAGS_VALUE);
			CProfile::deleteIdx(self::PROFILE_TAGS_OPERATOR);
		}
		elseif ($this->hasInput('filter_set')) {
			CProfile::update(self::PROFILE_NAME, $this->getInput('filter_name', ''), PROFILE_TYPE_STR);
			CProfile::updateArray(self::PROFILE_GROUPIDS, $this->getInput('filter_groupids', []),
				PROFILE_TYPE_ID
			);
			CProfile::deleteIdx('web.docker.list.filter.hostids');
			CProfile::update(self::PROFILE_PROBLEMS, (int) $this->getInput('filter_problems', 0),
				PROFILE_TYPE_INT
			);
			CProfile::update(self::PROFILE_DOCKER_PROBLEMS,
				(int) $this->getInput('filter_docker_problems', 0), PROFILE_TYPE_INT
			);
			CProfile::updateArray(self::PROFILE_CONTAINER_STATES, $this->getInput('filter_container_states', []),
				PROFILE_TYPE_STR
			);
			CProfile::update(self::PROFILE_TAG_EVALTYPE,
				(int) $this->getInput('filter_tag_evaltype', TAG_EVAL_TYPE_AND_OR), PROFILE_TYPE_INT
			);

			$filter_tags = ['tag' => [], 'value' => [], 'operator' => []];

			foreach ($this->getInput('filter_tags', []) as $tag) {
				if ($tag['tag'] === '' && $tag['value'] === '') {
					continue;
				}

				$filter_tags['tag'][] = $tag['tag'];
				$filter_tags['value'][] = $tag['value'];
				$filter_tags['operator'][] = (int) $tag['operator'];
			}

			CProfile::updateArray(self::PROFILE_TAGS_TAG, $filter_tags['tag'], PROFILE_TYPE_STR);
			CProfile::updateArray(self::PROFILE_TAGS_VALUE, $filter_tags['value'], PROFILE_TYPE_STR);
			CProfile::updateArray(self::PROFILE_TAGS_OPERATOR, $filter_tags['operator'], PROFILE_TYPE_INT);
		}

		$filter_name = (string) CProfile::get(self::PROFILE_NAME, '');
		$groupids = CProfile::getArray(self::PROFILE_GROUPIDS, []);
		$filter_problems = (int) CProfile::get(self::PROFILE_PROBLEMS, 0);
		$filter_docker_problems = (int) CProfile::get(self::PROFILE_DOCKER_PROBLEMS, 0);
		$filter_container_states = CProfile::getArray(self::PROFILE_CONTAINER_STATES, []);
		$filter_tag_evaltype = (int) CProfile::get(self::PROFILE_TAG_EVALTYPE, TAG_EVAL_TYPE_AND_OR);
		$filter_tags = [];

		foreach (CProfile::getArray(self::PROFILE_TAGS_TAG, []) as $index => $tag) {
			$filter_tags[] = [
				'tag' => $tag,
				'value' => (string) CProfile::get(self::PROFILE_TAGS_VALUE, '', $index),
				'operator' => (int) CProfile::get(self::PROFILE_TAGS_OPERATOR, TAG_OPERATOR_LIKE, $index)
			];
		}

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

		if (($filter_name !== '' || $groupids || $filter_tags) && $docker_hostids) {
			$filter_candidates = API::Host()->get([
				'output' => ['hostid', 'name'],
				'selectInventory' => ['notes'],
				'hostids' => $docker_hostids,
				'groupids' => $groupids ?: null,
				'evaltype' => $filter_tag_evaltype,
				'tags' => $filter_tags ?: null,
				'inheritedTags' => true,
				'preservekeys' => true
			]);

			if ($filter_name !== '') {
				$filter_candidates = array_filter($filter_candidates,
					static fn (array $host): bool => mb_stripos($host['name'], $filter_name) !== false
						|| mb_stripos((string) ($host['inventory']['notes'] ?? ''), $filter_name) !== false
				);
			}

			$docker_hostids = array_keys($filter_candidates);
		}

		if ($filter_container_states && $docker_hostids) {
			$docker_hostids = $this->filterHostidsByContainerStates($docker_hostids, $filter_container_states);
		}

		if (($filter_problems || $filter_docker_problems) && $docker_hostids) {
			$problem_hosts = $filter_docker_problems
				? DockerCollector::dockerTemplateProblemsByHosts($docker_hostids)
				: DockerCollector::problemsByHosts($docker_hostids);

			$docker_hostids = array_values(array_intersect($docker_hostids, array_keys($problem_hosts)));
		}

		$search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$hosts = $docker_hostids
			? API::Host()->get([
				'output' => ['hostid', 'name', 'status'],
				'selectInterfaces' => ['interfaceid', 'type', 'available', 'useip', 'ip', 'dns', 'port', 'error',
					'details'
				],
				'selectInventory' => ['notes'],
				'selectTags' => ['tag', 'value'],
				'selectInheritedTags' => ['tag', 'value'],
				'hostids' => $docker_hostids,
				'groupids' => $groupids ?: null,
				'preservekeys' => true,
				'sortfield' => 'name',
				'limit' => $search_limit + 1
			])
			: [];

		foreach ($hosts as &$host) {
			foreach ($host['inheritedTags'] as $inherited_tag) {
				foreach ($host['tags'] as $host_tag) {
					if ($host_tag['tag'] === $inherited_tag['tag'] && $host_tag['value'] === $inherited_tag['value']) {
						continue 2;
					}
				}

				$host['tags'][] = $inherited_tag;
			}
		}
		unset($host);

		$formatted_tags = makeTags($hosts, true, 'hostid', ZBX_TAG_COUNT_DEFAULT, $filter_tags);

		foreach ($hosts as &$host) {
			$host['formatted_tags'] = $formatted_tags[$host['hostid']] ?? [];

			foreach ($host['interfaces'] as &$interface) {
				$interface['interface'] = getHostInterface($interface);
				$interface['description'] = '';
				$interface['has_enabled_items'] = true;
			}
			unset($interface);
		}
		unset($host);

		$nodes = $this->collectNodeMetrics($hosts);

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
			(new CUrl('zabbix.php'))->setArgument('action', 'docker.list')
		);

		$response = new CControllerResponseData([
			'filter' => [
				'name' => $filter_name,
				'groupids' => $groups ? array_keys($groups) : [],
				'groups' => array_map(
					static fn (array $group): array => ['id' => $group['groupid'], 'name' => $group['name']],
					array_values($groups)
				),
				'problems' => $filter_problems,
				'docker_problems' => $filter_docker_problems,
				'container_states' => $filter_container_states,
				'tag_evaltype' => $filter_tag_evaltype,
				'tags' => $filter_tags
			],
			'paging' => $paging,
			'nodes' => $nodes,
			'totals' => $totals,
			'active_tab' => CProfile::get('web.docker.list.filter.active', 1),
			'refresh_interval' => timeUnitToSeconds(CWebUser::getRefresh())
		]);
		$response->setTitle(_('Docker nodes'));

		$this->setResponse($response);
	}

	private function filterHostidsByContainerStates(array $hostids, array $states): array {
		$keys = [
			'docker.containers.running' => 'running',
			'docker.containers.stopped' => 'stopped',
			'docker.containers.paused' => 'paused',
			'lxp.docker.sum_unhealthy' => 'unhealthy'
		];
		$metrics = array_fill_keys($hostids, array_fill_keys(array_values($keys), null));
		$items = API::Item()->get([
			'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'],
			'hostids' => $hostids,
			'filter' => ['key_' => array_keys($keys)],
			'monitored' => true
		]);

		foreach ($items as $item) {
			if (DockerCollector::hasRecentValue($item)) {
				$metrics[$item['hostid']][$keys[$item['key_']]] = (int) $item['lastvalue'];
			}
		}

		return array_keys(array_filter($metrics, static function (array $values) use ($states): bool {
			foreach ($states as $state) {
				$matches = match ($state) {
					'stopped' => $values['stopped'] !== null && $values['stopped'] > 0,
					'paused' => $values['paused'] !== null && $values['paused'] > 0,
					'unhealthy' => $values['unhealthy'] !== null && $values['unhealthy'] > 0,
					'no_running' => $values['running'] !== null && $values['running'] === 0,
					default => false
				};

				if ($matches) {
					return true;
				}
			}

			return false;
		}));
	}

	private function collectNodeMetrics(array $hosts): array {
		if (!$hosts) {
			return [];
		}

		$items = API::Item()->get([
			'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'],
			'hostids' => array_keys($hosts),
			'filter' => ['key_' => array_keys(self::NODE_KEYS)],
			'monitored' => true
		]);

		$empty_metrics = array_fill_keys(array_values(self::NODE_KEYS), null);
		$nodes = [];

		foreach ($hosts as $hostid => $host) {
			$nodes[$hostid] = $host + ['metrics' => $empty_metrics];
		}

		foreach ($items as $item) {
			if (!DockerCollector::hasRecentValue($item)) {
				continue;
			}

			$field = self::NODE_KEYS[$item['key_']];
			$nodes[$item['hostid']]['metrics'][$field] = $item['lastvalue'];
		}

		return $nodes;
	}

}

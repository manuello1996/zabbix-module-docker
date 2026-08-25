<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use CSettingsHelper;
use CWebUser;
use Modules\MonitorDocker\Includes\DockerCollector;

/**
 * Read-only bridge used by the module's global UI integration.
 *
 * Keeping this in a dedicated action avoids replacing core/third-party host and
 * search controllers. In particular, it can coexist with Better Search.
 */
class CControllerDockerIntegration extends CController {
	private const TEMPLATE_NAME = 'Docker by Zabbix agent 2';

	private const CONTAINER_KEY_PREFIXES = [
		'docker.container.description[',
		'docker.container_info.state.status['
	];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'mode' =>		'required|in hosts,search',
			'hostids' =>	'array_db hosts.hostid',
			'search' =>		'string'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);
	}

	protected function doAction(): void {
		$mode = $this->getInput('mode');
		$hostids = $this->getDockerHostids($mode === 'hosts' ? $this->getInput('hostids', []) : []);

		$data = $mode === 'hosts'
			? ['hostids' => $hostids]
			: $this->searchContainers($hostids, trim($this->getInput('search', '')));

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($data)
		]));
	}

	/**
	 * Return only hosts visible to the current user and linked to a template
	 * whose visible name contains the requested Docker template name.
	 */
	private function getDockerHostids(array $hostids = []): array {
		$item_options = [
			'output' => ['hostid'],
			'filter' => ['key_' => 'docker.containers.total']
		];

		if ($hostids) {
			$item_options['hostids'] = $hostids;
		}

		$candidate_hostids = array_values(array_unique(array_column(API::Item()->get($item_options), 'hostid')));

		if (!$candidate_hostids) {
			return [];
		}

		$templates = API::Template()->get([
			'output' => ['templateid', 'name'],
			'search' => ['name' => self::TEMPLATE_NAME]
		]);

		$templateids = [];

		foreach ($templates as $template) {
			if (stripos($template['name'], self::TEMPLATE_NAME) !== false) {
				$templateids[] = $template['templateid'];
			}
		}

		if (!$templateids) {
			return [];
		}

		$options = [
			'output' => ['hostid'],
			'templateids' => $templateids,
			'hostids' => $candidate_hostids,
			'preservekeys' => true
		];

		return array_keys(API::Host()->get($options));
	}

	private function searchContainers(array $hostids, string $search): array {
		if ($search === '' || !$hostids) {
			return ['containers' => [], 'total' => 0];
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'],
			'hostids' => $hostids,
			'selectInterfaces' => ['interfaceid', 'type', 'main', 'useip', 'ip', 'dns'],
			'selectInventory' => ['notes'],
			'preservekeys' => true
		]);

		if (!$hosts) {
			return ['containers' => [], 'total' => 0];
		}

		$items = API::Item()->get([
			'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'],
			'hostids' => array_keys($hosts),
			'search' => ['key_' => self::CONTAINER_KEY_PREFIXES],
			'searchByAny' => true,
			'startSearch' => true,
			'monitored' => true,
			'webitems' => false
		]);

		$containers = [];

		foreach ($items as $item) {
			[$prefix, $name] = $this->parseContainerKey($item['key_']);

			if ($prefix === null) {
				continue;
			}

			$name = ltrim($name, '/');
			$key = $item['hostid']."\0".$name;

			if (!array_key_exists($key, $containers)) {
				$containers[$key] = [
					'hostid' => $item['hostid'],
					'name' => $name,
					'note' => ''
				];
			}

			if ($prefix === 'docker.container.description') {
				$containers[$key]['note'] = DockerCollector::hasRecentValue($item)
					? (string) $item['lastvalue']
					: '';
			}
		}

		foreach ($containers as $key => &$container) {
			if (stripos($container['name'], $search) === false
					&& stripos($container['note'], $search) === false) {
				unset($containers[$key]);
				continue;
			}

			$host = $hosts[$container['hostid']];
			$interface = $this->getPreferredInterface($host['interfaces']);

			$container['host'] = $host['name'];
			$container['ip'] = $interface['ip'] ?? '';
			$container['dns'] = $interface['dns'] ?? '';
			$container['host_notes'] = is_array($host['inventory'])
				? (string) ($host['inventory']['notes'] ?? '')
				: '';
		}
		unset($container);

		$containers = array_values($containers);

		usort($containers, static function(array $a, array $b): int {
			return strcasecmp($a['name'], $b['name']) ?: strcasecmp($a['host'], $b['host']);
		});

		$total = count($containers);
		$limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		if ($limit > 0) {
			$containers = array_slice($containers, 0, $limit);
		}

		return ['containers' => $containers, 'total' => $total];
	}

	private function parseContainerKey(string $key): array {
		if (preg_match('/^([a-z0-9._]+)\["?([^"\]]+)"?\]$/i', $key, $matches) != 1) {
			return [null, ''];
		}

		return [$matches[1], $matches[2]];
	}

	private function getPreferredInterface(array $interfaces): ?array {
		foreach ($interfaces as $interface) {
			if ((int) $interface['type'] === INTERFACE_TYPE_AGENT && (int) $interface['main'] === INTERFACE_PRIMARY) {
				return $interface;
			}
		}

		foreach ($interfaces as $interface) {
			if ((int) $interface['main'] === INTERFACE_PRIMARY) {
				return $interface;
			}
		}

		return $interfaces ? reset($interfaces) : null;
	}
}

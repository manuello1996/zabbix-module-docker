<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Actions;

use API;
use CController;
use CControllerResponseData;
use CRoleHelper;
use CWebUser;
use Modules\MonitorDocker\Includes\DockerCollector;
use Modules\MonitorDocker\Includes\DockerImageAge;

class CControllerDockerImagesCsv extends CController {
	private const IMAGE_CREATED_KEY_PREFIX = 'docker.image.created[';

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput([]);
	}

	protected function checkPermissions(): bool {
		return CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);
	}

	protected function doAction(): void {
		$image_age = DockerImageAge::get();
		$cutoff = time() - $image_age['seconds'];
		$old_items = [];
		$rows = [];

		// Fetch all image creation items in one API request. This avoids an API request per Docker host.
		$items = API::Item()->get([
			'output' => ['hostid', 'name', 'key_', 'lastvalue', 'lastclock'],
			'search' => ['key_' => self::IMAGE_CREATED_KEY_PREFIX],
			'startSearch' => true,
			'monitored' => true
		]);

		foreach ($items as $item) {
			if ($item['lastclock'] == 0
					|| preg_match('/^docker\.image\.created\["?([^"\]]+)"?\]$/', $item['key_'], $matches) !== 1) {
				continue;
			}

			$created = (int) $item['lastvalue'];

			if ($created <= 0 || $created >= $cutoff) {
				continue;
			}

			$item['created'] = $created;
			$item['image_id'] = $this->imageId($matches[1]);
			$old_items[] = $item;
		}

		$hostids = array_values(array_unique(array_column($old_items, 'hostid')));
		$hosts = $hostids
			? API::Host()->get([
				'output' => ['hostid', 'name'],
				'selectInventory' => ['notes'],
				'hostids' => $hostids,
				'preservekeys' => true
			])
			: [];
		$usage = $this->imageUsage(array_keys($hosts));

		foreach ($old_items as $item) {
			if (!array_key_exists($item['hostid'], $hosts)) {
				continue;
			}

			$host = $hosts[$item['hostid']];
			$image = preg_replace('/^Image\s+|:\s+[^:]+$/u', '', $item['name']);
			$host_usage = $usage[$item['hostid']] ?? null;
			$containers = ($host_usage['ids'][$item['image_id']] ?? [])
				+ ($host_usage['references'][$image] ?? []);
			natcasesort($containers);

			$rows[] = [
				'team' => '',
				'host' => $host['name'],
				'notes' => (string) ($host['inventory']['notes'] ?? ''),
				'image' => $image,
				'date' => zbx_date2str(DATE_TIME_FORMAT, $item['created']),
				'usage' => $containers ? implode(',', $containers) : ($host_usage === null ? 'UNKNOWN' : 'NOT IN USE'),
				'created' => $item['created']
			];
		}

		usort($rows, static fn (array $a, array $b): int =>
			strnatcasecmp($a['host'], $b['host'])
				?: strnatcasecmp($a['image'], $b['image'])
				?: $a['created'] <=> $b['created']
		);

		$response = new CControllerResponseData(['rows' => $rows]);
		$response->setFileName('docker_images_older_than_'.$image_age['filename'].'_'.date('Y-m-d').'.csv');
		$this->setResponse($response);
	}

	private function imageUsage(array $hostids): array {
		if (!$hostids) {
			return [];
		}

		$items = API::Item()->get([
			'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'],
			'hostids' => $hostids,
			'search' => ['key_' => ['docker.containers.image_usage', 'docker.container_info.image_id[']],
			'searchByAny' => true,
			'startSearch' => true,
			'monitored' => true
		]);
		$snapshots = [];
		$image_ids = [];

		foreach ($items as $item) {
			if (!DockerCollector::hasRecentValue($item)) {
				continue;
			}

			if ($item['key_'] === 'docker.containers.image_usage') {
				$containers = json_decode($item['lastvalue'], true);
				if (is_array($containers) && substr(ltrim($item['lastvalue']), 0, 1) === '[') {
					$snapshots[$item['hostid']] = $containers;
				}
			}
			elseif (preg_match('/^docker\.container_info\.image_id\["?\/?([^"\]]+)"?\]$/',
					$item['key_'], $matches) === 1) {
				$image_ids[$item['hostid']][$matches[1]] = $this->imageId($item['lastvalue']);
			}
		}

		$usage = [];
		foreach ($snapshots as $hostid => $containers) {
			$usage[$hostid] = ['ids' => [], 'references' => []];
			foreach ($containers as $container) {
				if (!is_array($container)) {
					unset($usage[$hostid]);
					break;
				}
				$names = array_values(array_filter(array_map(
					static fn ($name): string => ltrim((string) $name, '/'),
					(array) ($container['Names'] ?? [])
				), 'strlen'));
				if (!$names) {
					unset($usage[$hostid]);
					break;
				}
				$name = $names[0];
				$id = $this->imageId($container['ImageID'] ?? '');
				$id = $id !== '' ? $id : ($image_ids[$hostid][$name] ?? '');
				$image = (string) ($container['Image'] ?? '');
				if ($id === '' && $image === '') {
					unset($usage[$hostid]);
					break;
				}
				// Match by reference only when no exact ID is available; tags can be reassigned.
				$bucket = $id !== '' ? 'ids' : 'references';
				$usage[$hostid][$bucket][$id !== '' ? $id : $image][$name] = $name;
			}
		}

		return $usage;
	}

	private function imageId(string $id): string {
		return strtolower((string) preg_replace('/^sha256:/i', '', $id));
	}
}

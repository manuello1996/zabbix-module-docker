<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CRoleHelper;
use CUrl;
use CWebUser;
use Modules\MonitorDocker\Includes\DockerCollector;

class CControllerDockerCleanup extends CController {
	private const DATASET_KEYS = [
		'docker.images',
		'docker.data_usage'
	];

	private const REMOVABLE_CONTAINER_STATES = ['created', 'dead', 'exited'];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput(['page' => 'ge 1']);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);
	}

	protected function doAction(): void {
		$docker_items = API::Item()->get([
			'output' => ['hostid'],
			'filter' => ['key_' => 'docker.containers.total'],
			'monitored' => true
		]);
		$hostids = array_values(array_unique(array_column($docker_items, 'hostid')));
		$hosts = $hostids
			? API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => $hostids,
				'preservekeys' => true
			])
			: [];
		$items = $hosts
			? API::Item()->get([
				'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'],
				'hostids' => array_keys($hosts),
				'filter' => ['key_' => self::DATASET_KEYS],
				'monitored' => true
			])
			: [];
		$datasets = [];

		foreach ($items as $item) {
			if (DockerCollector::hasRecentValue($item)) {
				$datasets[$item['hostid']][$item['key_']] = $item;
			}
		}

		$candidates = [];
		$totals = [
			'hosts' => 0,
			'images' => 0,
			'containers' => 0,
			'volumes' => 0,
			'bytes' => 0.0
		];

		foreach ($hosts as $hostid => $host) {
			$summary = $this->summarizeHost($datasets[$hostid] ?? []);

			if ($summary['total'] === 0) {
				continue;
			}

			$candidates[] = $host + $summary;
			$totals['hosts']++;
			$totals['images'] += $summary['images'];
			$totals['containers'] += $summary['containers'];
			$totals['volumes'] += $summary['volumes'];
			$totals['bytes'] += $summary['bytes'];
		}

		usort($candidates, static fn (array $a, array $b): int =>
			$b['bytes'] <=> $a['bytes'] ?: strnatcasecmp($a['name'], $b['name'])
		);

		$paging = CPagerHelper::paginate((int) $this->getInput('page', 1), $candidates, ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', 'docker.cleanup')
		);

		$response = new CControllerResponseData([
			'hosts' => $candidates,
			'totals' => $totals,
			'hosts_scanned' => count($hosts),
			'paging' => $paging
		]);
		$response->setTitle(_('Docker Cleanup'));

		$this->setResponse($response);
	}

	private function summarizeHost(array $items): array {
		$images = $this->dataset($items['docker.images'] ?? null);
		$data_usage = $this->dataset($items['docker.data_usage'] ?? null);
		$containers = is_array($data_usage['Containers'] ?? null) ? $data_usage['Containers'] : [];
		$volumes = is_array($data_usage['Volumes'] ?? null) ? $data_usage['Volumes'] : [];
		$used_image_ids = [];

		foreach ($containers as $container) {
			if (is_array($container) && ($image_id = $this->imageId($container['ImageID'] ?? '')) !== '') {
				$used_image_ids[$image_id] = true;
			}
		}

		$image_count = 0;
		$container_count = 0;
		$volume_count = 0;
		$bytes = 0.0;

		foreach ($images as $image) {
			if (!is_array($image) || !$this->isDanglingImage($image)
					|| array_key_exists($this->imageId($image['Id'] ?? ''), $used_image_ids)) {
				continue;
			}

			$image_count++;
			$bytes += max(0.0, (float) ($image['Size'] ?? 0));
		}

		foreach ($containers as $container) {
			if (!is_array($container)
					|| !in_array(strtolower((string) ($container['State'] ?? '')), self::REMOVABLE_CONTAINER_STATES, true)) {
				continue;
			}

			$container_count++;
			$bytes += max(0.0, (float) ($container['SizeRw'] ?? 0));
		}

		foreach ($volumes as $volume) {
			$usage = is_array($volume) ? (array) ($volume['UsageData'] ?? []) : [];

			if (!is_array($volume) || (int) ($usage['RefCount'] ?? 0) > 0) {
				continue;
			}

			$volume_count++;
			$bytes += max(0.0, (float) ($usage['Size'] ?? 0));
		}

		$lastclocks = array_filter(array_map(
			static fn (array $item): int => (int) $item['lastclock'],
			$items
		));

		return [
			'images' => $image_count,
			'containers' => $container_count,
			'volumes' => $volume_count,
			'bytes' => $bytes,
			'total' => $image_count + $container_count + $volume_count,
			'lastclock' => $lastclocks ? min($lastclocks) : 0
		];
	}

	private function dataset(?array $item): array {
		if ($item === null) {
			return [];
		}

		$dataset = json_decode($item['lastvalue'], true);

		return is_array($dataset) ? $dataset : [];
	}

	private function imageId($image_id): string {
		return strtolower((string) preg_replace('/^sha256:/', '', (string) $image_id));
	}

	private function isDanglingImage(array $image): bool {
		$tags = $image['RepoTags'] ?? [];

		if (!is_array($tags) || $tags === []) {
			return true;
		}

		return array_reduce($tags, static fn (bool $dangling, $tag): bool => $dangling
			&& in_array($tag, ['<none>', '<none>:<none>'], true), true);
	}
}

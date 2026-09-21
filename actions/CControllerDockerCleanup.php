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
	/*
	 * docker.images and docker.data_usage are master items with history=0 in the
	 * stock template. Their lastvalue is consequently unavailable through the
	 * frontend API. The raw items are still queried for installations that do
	 * retain them; the remaining sources are retained fallbacks.
	 */
	private const RAW_DATASET_KEYS = [
		'docker.images',
		'docker.data_usage'
	];

	private const STORED_ITEM_PREFIXES = [
		'docker.container_info.state.status[',
		'docker.volumes.raw'
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
		$raw_items = $hosts
			? API::Item()->get([
				'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'],
				'hostids' => array_keys($hosts),
				'filter' => ['key_' => self::RAW_DATASET_KEYS],
				'monitored' => true
			])
			: [];
		$stored_items = $hosts
			? API::Item()->get([
				'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'],
				'hostids' => array_keys($hosts),
				'search' => ['key_' => self::STORED_ITEM_PREFIXES],
				'searchByAny' => true,
				'startSearch' => true,
				'monitored' => true
			])
			: [];
		$datasets = [];

		foreach ($raw_items as $item) {
			if (!DockerCollector::hasRecentValue($item)) {
				continue;
			}

			$datasets[$item['hostid']][$item['key_']] = $item;
		}

		foreach ($stored_items as $item) {
			if (!DockerCollector::hasRecentValue($item)) {
				continue;
			}

			if (preg_match('/^docker\\.container_info\\.state\\.status\\[/', $item['key_']) === 1) {
				$datasets[$item['hostid']]['containers'][] = $item;
				continue;
			}

			if ($item['key_'] === 'docker.volumes.raw') {
				$datasets[$item['hostid']]['volumes'] = $item;
			}
		}

		$candidates = [];
		$totals = [
			'hosts' => 0,
			'hosts_with_data' => 0,
			'hosts_with_image_data' => 0,
			'images' => 0,
			'containers' => 0,
			'volumes' => 0,
			'bytes' => 0.0
		];

		foreach ($hosts as $hostid => $host) {
			$summary = $this->summarizeHost($datasets[$hostid] ?? []);
			$totals['hosts_with_data'] += $summary['has_data'] ? 1 : 0;
			$totals['hosts_with_image_data'] += $summary['image_data_available'] ? 1 : 0;

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
		$images_item = $items['docker.images'] ?? null;
		$data_usage_item = $items['docker.data_usage'] ?? null;
		$images = $this->dataset($images_item);
		$data_usage = $this->dataset($data_usage_item);
		$raw_containers = is_array($data_usage['Containers'] ?? null) ? $data_usage['Containers'] : [];
		$raw_volumes = is_array($data_usage['Volumes'] ?? null) ? $data_usage['Volumes'] : [];
		$containers = $data_usage_item !== null ? $raw_containers : ($items['containers'] ?? []);
		$volumes = $data_usage_item !== null ? $raw_volumes : $this->dataset($items['volumes'] ?? null);
		$used_image_ids = [];

		foreach ($raw_containers as $container) {
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
			if (!is_array($container)) {
				continue;
			}

			$state = $data_usage_item !== null ? ($container['State'] ?? '') : ($container['lastvalue'] ?? '');

			if (!in_array(strtolower((string) $state), self::REMOVABLE_CONTAINER_STATES, true)) {
				continue;
			}

			$container_count++;
			$bytes += $data_usage_item !== null ? max(0.0, (float) ($container['SizeRw'] ?? 0)) : 0.0;
		}

		foreach ($volumes as $volume) {
			$usage = is_array($volume) ? (array) ($volume['UsageData'] ?? []) : [];

			if (!is_array($volume) || (int) ($usage['RefCount'] ?? 0) > 0) {
				continue;
			}

			$volume_count++;
			$bytes += max(0.0, (float) ($usage['Size'] ?? 0));
		}

		$lastclocks = $this->lastclocks($items);

		return [
			'images' => $image_count,
			'containers' => $container_count,
			'volumes' => $volume_count,
			'bytes' => $bytes,
			'total' => $image_count + $container_count + $volume_count,
			'lastclock' => $lastclocks ? min($lastclocks) : 0,
			'has_data' => (bool) ($images_item || $data_usage_item || $containers || $volumes),
			'image_data_available' => $images_item !== null
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

	private function lastclocks(array $items): array {
		$clocks = [];

		array_walk_recursive($items, static function ($value, $key) use (&$clocks): void {
			if ($key === 'lastclock') {
				$clocks[] = (int) $value;
			}
		});

		return array_filter($clocks);
	}
}

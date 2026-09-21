<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CRoleHelper;
use CUrl;
use CWebUser;
use Modules\MonitorDocker\Includes\DockerCollector;

class CControllerDockerCleanup extends CController {
	private const PROFILE_GROUPIDS = 'web.docker.cleanup.filter.groupids';
	private const PROFILE_NAME = 'web.docker.cleanup.filter.name';
	private const PROFILE_TAG_EVALTYPE = 'web.docker.cleanup.filter.tag_evaltype';
	private const PROFILE_TAGS_TAG = 'web.docker.cleanup.filter.tags.tag';
	private const PROFILE_TAGS_VALUE = 'web.docker.cleanup.filter.tags.value';
	private const PROFILE_TAGS_OPERATOR = 'web.docker.cleanup.filter.tags.operator';
	private const PROFILE_SORT = 'web.docker.cleanup.sort';
	private const PROFILE_SORTORDER = 'web.docker.cleanup.sortorder';

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
		'docker.containers.stopped',
		'docker.volumes.raw'
	];

	private const IMAGE_USAGE_ITEM_PREFIXES = [
		'docker.containers.image_usage',
		'docker.image.size[',
		'docker.container_info.image_id['
	];

	private const REMOVABLE_CONTAINER_STATES = ['created', 'dead', 'exited'];

	private const SORT_FIELDS = ['name', 'notes', 'images', 'containers', 'volumes', 'bytes', 'lastclock'];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'filter_name' => 'string',
			'filter_groupids' => 'array_id',
			'filter_tag_evaltype' => 'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'filter_tags' => 'array',
			'filter_set' => 'in 1',
			'filter_rst' => 'in 1',
			'sort' => 'in '.implode(',', self::SORT_FIELDS),
			'sortorder' => 'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN,
			'page' => 'ge 1'
		]);

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
			CProfile::delete(self::PROFILE_TAG_EVALTYPE);
			CProfile::deleteIdx(self::PROFILE_TAGS_TAG);
			CProfile::deleteIdx(self::PROFILE_TAGS_VALUE);
			CProfile::deleteIdx(self::PROFILE_TAGS_OPERATOR);
		}
		elseif ($this->hasInput('filter_set')) {
			CProfile::update(self::PROFILE_NAME, $this->getInput('filter_name', ''), PROFILE_TYPE_STR);
			CProfile::updateArray(self::PROFILE_GROUPIDS, $this->getInput('filter_groupids', []), PROFILE_TYPE_ID);
			CProfile::update(self::PROFILE_TAG_EVALTYPE,
				(int) $this->getInput('filter_tag_evaltype', TAG_EVAL_TYPE_AND_OR), PROFILE_TYPE_INT
			);

			$profile_tags = ['tag' => [], 'value' => [], 'operator' => []];

			foreach ($this->getInput('filter_tags', []) as $tag) {
				if ($tag['tag'] === '' && $tag['value'] === '') {
					continue;
				}

				$profile_tags['tag'][] = $tag['tag'];
				$profile_tags['value'][] = $tag['value'];
				$profile_tags['operator'][] = (int) $tag['operator'];
			}

			CProfile::updateArray(self::PROFILE_TAGS_TAG, $profile_tags['tag'], PROFILE_TYPE_STR);
			CProfile::updateArray(self::PROFILE_TAGS_VALUE, $profile_tags['value'], PROFILE_TYPE_STR);
			CProfile::updateArray(self::PROFILE_TAGS_OPERATOR, $profile_tags['operator'], PROFILE_TYPE_INT);
		}

		if ($this->hasInput('sort')) {
			CProfile::update(self::PROFILE_SORT, $this->getInput('sort'), PROFILE_TYPE_STR);
		}
		if ($this->hasInput('sortorder')) {
			CProfile::update(self::PROFILE_SORTORDER, $this->getInput('sortorder'), PROFILE_TYPE_STR);
		}

		$filter_name = (string) CProfile::get(self::PROFILE_NAME, '');
		$groupids = CProfile::getArray(self::PROFILE_GROUPIDS, []);
		$filter_tag_evaltype = (int) CProfile::get(self::PROFILE_TAG_EVALTYPE, TAG_EVAL_TYPE_AND_OR);
		$filter_tags = [];

		foreach (CProfile::getArray(self::PROFILE_TAGS_TAG, []) as $index => $tag) {
			$filter_tags[] = [
				'tag' => $tag,
				'value' => (string) CProfile::get(self::PROFILE_TAGS_VALUE, '', $index),
				'operator' => (int) CProfile::get(self::PROFILE_TAGS_OPERATOR, TAG_OPERATOR_LIKE, $index)
			];
		}

		$sort = (string) CProfile::get(self::PROFILE_SORT, 'bytes');
		$sortorder = (string) CProfile::get(self::PROFILE_SORTORDER, ZBX_SORT_DOWN);
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
		$hostids = array_values(array_unique(array_column($docker_items, 'hostid')));

		if (($filter_name !== '' || $groupids || $filter_tags) && $hostids) {
			$filter_candidates = API::Host()->get([
				'output' => ['hostid', 'name'],
				'selectInventory' => ['notes'],
				'hostids' => $hostids,
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

			$hostids = array_keys($filter_candidates);
		}

		$hosts = $hostids
			? API::Host()->get([
				'output' => ['hostid', 'name'],
				'selectInventory' => ['notes'],
				'hostids' => $hostids,
				'groupids' => $groupids ?: null,
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

			if ($item['key_'] === 'docker.containers.stopped') {
				$datasets[$item['hostid']]['stopped'] = $item;
				continue;
			}

			if ($item['key_'] === 'docker.volumes.raw') {
				$datasets[$item['hostid']]['volumes'] = $item;
			}
		}

		/* Same persisted-data join used by the Images tab; no live Docker calls. */
		$image_usage_hostids = array_keys(array_filter($hosts,
			static fn (array $host): bool => !isset($datasets[$host['hostid']]['docker.images'])
		));
		$image_usage_items = $image_usage_hostids
			? API::Item()->get([
				'output' => ['hostid', 'name', 'key_', 'lastvalue', 'lastclock'],
				'hostids' => $image_usage_hostids,
				'search' => ['key_' => self::IMAGE_USAGE_ITEM_PREFIXES],
				'searchByAny' => true,
				'startSearch' => true,
				'monitored' => true
			])
			: [];

		foreach ($image_usage_items as $item) {
			if (!DockerCollector::hasRecentValue($item)) {
				continue;
			}

			if ($item['key_'] === 'docker.containers.image_usage') {
				$datasets[$item['hostid']]['image_usage'] = $item;
				continue;
			}

			if (preg_match('/^docker\\.image\\.size\\["?([^"\\]]+)"?\\]$/', $item['key_'], $matches) === 1) {
				$image_id = $this->imageId($matches[1]);
				$datasets[$item['hostid']]['discovered_images'][$image_id] = [
					'id' => $image_id,
					'name' => preg_replace('/^Image\\s+|:\\s+[^:]+$/u', '', $item['name']),
					'size' => max(0.0, (float) $item['lastvalue'])
				];
				continue;
			}

			if (preg_match('/^docker\\.container_info\\.image_id\\["?\\/?([^"\\]]+)"?\\]$/',
					$item['key_'], $matches) === 1) {
				$datasets[$item['hostid']]['container_image_ids'][$matches[1]] = $item['lastvalue'];
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

		$this->sortCandidates($candidates, $sort, $sortorder);

		$paging = CPagerHelper::paginate((int) $this->getInput('page', 1), $candidates, ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', 'docker.cleanup')
		);

		$response = new CControllerResponseData([
			'hosts' => $candidates,
			'totals' => $totals,
			'hosts_scanned' => count($hosts),
			'paging' => $paging,
			'filter' => [
				'name' => $filter_name,
				'groupids' => $groups ? array_keys($groups) : [],
				'groups' => array_map(
					static fn (array $group): array => ['id' => $group['groupid'], 'name' => $group['name']],
					array_values($groups)
				),
				'tag_evaltype' => $filter_tag_evaltype,
				'tags' => $filter_tags
			],
			'sort' => $sort,
			'sortorder' => $sortorder,
			'active_tab' => CProfile::get('web.docker.cleanup.filter.active', 1)
		]);
		$response->setTitle(_('Docker Cleanup'));

		$this->setResponse($response);
	}

	private function sortCandidates(array &$candidates, string $sort, string $sortorder): void {
		usort($candidates, static function (array $a, array $b) use ($sort, $sortorder): int {
			$left = $sort === 'notes' ? (string) ($a['inventory']['notes'] ?? '') : $a[$sort];
			$right = $sort === 'notes' ? (string) ($b['inventory']['notes'] ?? '') : $b[$sort];
			$comparison = in_array($sort, ['name', 'notes'], true)
				? strnatcasecmp((string) $left, (string) $right)
				: $left <=> $right;

			if ($comparison === 0) {
				$comparison = strnatcasecmp($a['name'], $b['name']);
			}

			return $sortorder === ZBX_SORT_DOWN ? -$comparison : $comparison;
		});
	}

	private function summarizeHost(array $items): array {
		$images_item = $items['docker.images'] ?? null;
		$data_usage_item = $items['docker.data_usage'] ?? null;
		$image_usage_item = $items['image_usage'] ?? null;
		$images = $this->dataset($images_item);
		$data_usage = $this->dataset($data_usage_item);
		$raw_containers = is_array($data_usage['Containers'] ?? null) ? $data_usage['Containers'] : [];
		$raw_volumes = is_array($data_usage['Volumes'] ?? null) ? $data_usage['Volumes'] : [];
		$containers = $data_usage_item !== null ? $raw_containers : [];
		$volumes = $data_usage_item !== null ? $raw_volumes : $this->dataset($items['volumes'] ?? null);
		$stopped_item = $items['stopped'] ?? null;
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
		$image_data_available = $images_item !== null;

		if ($images_item !== null) {
			foreach ($images as $image) {
				if (!is_array($image) || !$this->isDanglingImage($image)
						|| array_key_exists($this->imageId($image['Id'] ?? ''), $used_image_ids)) {
					continue;
				}

				$image_count++;
				$bytes += max(0.0, (float) ($image['Size'] ?? 0));
			}
		}
		elseif ($image_usage_item !== null) {
			[$image_count, $image_bytes, $image_data_available] = $this->summarizeDiscoveredImages(
				$items['discovered_images'] ?? [],
				$this->dataset($image_usage_item),
				$items['container_image_ids'] ?? []
			);
			$bytes += $image_bytes;
		}

		if ($data_usage_item === null && $stopped_item !== null) {
			$container_count = max(0, (int) $stopped_item['lastvalue']);
		}
		else {
			foreach ($containers as $container) {
				if (!is_array($container)
						|| !in_array(strtolower((string) ($container['State'] ?? '')),
							self::REMOVABLE_CONTAINER_STATES, true)) {
					continue;
				}

				$container_count++;
				$bytes += max(0.0, (float) ($container['SizeRw'] ?? 0));
			}
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
			'has_data' => (bool) ($images_item || $image_usage_item || $data_usage_item || $stopped_item || $volumes),
			'image_data_available' => $image_data_available
		];
	}

	private function summarizeDiscoveredImages(array $images, array $containers, array $container_image_ids): array {
		$image_ids = [];
		$image_names = [];
		$used_images = [];
		$container_count = 0;
		$matched_containers = 0;

		foreach ($images as $image_key => $image) {
			$image_ids[$this->imageId($image['id'] ?? $image_key)] = $image_key;
			$image_names[(string) ($image['name'] ?? '')] = $image_key;
		}

		foreach ($containers as $container) {
			if (!is_array($container)) {
				continue;
			}

			$names = array_values(array_filter(
				array_map(static fn ($name): string => ltrim((string) $name, '/'), (array) ($container['Names'] ?? [])),
				'strlen'
			));

			if (!$names) {
				continue;
			}

			$container_count++;
			$container_name = $names[0];
			$image_id = (string) ($container['ImageID'] ?? $container_image_ids[$container_name] ?? '');
			$normalized_id = $this->imageId($image_id);
			$image_ref = (string) ($container['Image'] ?? '');
			$image_key = $normalized_id !== '' && array_key_exists($normalized_id, $image_ids)
				? $image_ids[$normalized_id]
				: ($image_names[$image_ref] ?? null);

			if ($image_key === null) {
				continue;
			}

			$matched_containers++;
			$used_images[$image_key] = true;
		}

		if ($container_count !== $matched_containers) {
			return [0, 0.0, false];
		}

		$count = 0;
		$bytes = 0.0;

		foreach ($images as $image_key => $image) {
			if ($this->isDanglingImageName((string) ($image['name'] ?? ''))
					&& !array_key_exists($image_key, $used_images)) {
				$count++;
				$bytes += max(0.0, (float) ($image['size'] ?? 0));
			}
		}

		return [$count, $bytes, true];
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

	private function isDanglingImageName(string $name): bool {
		return strpos($name, '<none>') !== false;
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

<?php declare(strict_types = 0);

namespace Modules\MonzphereDocker\Actions;

use API;
use CController;
use CControllerResponseData;
use CRoleHelper;
use CWebUser;

class CControllerDockerImagesCsv extends CController {
	private const IMAGE_CREATED_KEY_PREFIX = 'docker.image.created[';
	private const IMAGE_MAX_AGE_DAYS = 365;

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
		$cutoff = time() - self::IMAGE_MAX_AGE_DAYS * SEC_PER_DAY;
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
					|| preg_match('/^docker\.image\.created\["?([^"\]]+)"?\]$/', $item['key_']) !== 1) {
				continue;
			}

			$created = (int) $item['lastvalue'];

			if ($created <= 0 || $created >= $cutoff) {
				continue;
			}

			$item['created'] = $created;
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

		foreach ($old_items as $item) {
			if (!array_key_exists($item['hostid'], $hosts)) {
				continue;
			}

			$host = $hosts[$item['hostid']];

			$rows[] = [
				'team' => '',
				'host' => $host['name'],
				'notes' => (string) ($host['inventory']['notes'] ?? ''),
				'image' => preg_replace('/^Image\s+|:\s+[^:]+$/u', '', $item['name']),
				'date' => zbx_date2str(DATE_TIME_FORMAT, $item['created']),
				'created' => $item['created']
			];
		}

		usort($rows, static fn (array $a, array $b): int =>
			strnatcasecmp($a['host'], $b['host'])
				?: strnatcasecmp($a['image'], $b['image'])
				?: $a['created'] <=> $b['created']
		);

		$response = new CControllerResponseData(['rows' => $rows]);
		$response->setFileName('docker_images_older_than_365_days_'.date('Y-m-d').'.csv');
		$this->setResponse($response);
	}
}

<?php declare(strict_types = 0);

namespace Modules\MonzphereDocker\Actions;

use API;
use CController;
use CControllerResponseData;
use CRoleHelper;
use CWebUser;
use Modules\MonzphereDocker\Includes\DockerCollector;
use Modules\MonzphereDocker\Includes\DockerFormatter;

class CControllerDockerRefresh extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'required|db hosts.hostid'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => _('Invalid request.')
			])]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		if (!CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA)) {
			return false;
		}

		return (bool) API::Host()->get([
			'output' => [],
			'hostids' => $this->getInput('hostid')
		]);
	}

	protected function doAction(): void {
		$hostid = $this->getInput('hostid');
		$data = DockerCollector::collect($hostid);
		$node = DockerCollector::nodeInfo($hostid);

		$memory_pct = ($node['mem_total'] !== null && (float) $node['mem_total'] > 0)
			? (int) round(min(100, (float) $data['overview']['memory_total'] / (float) $node['mem_total'] * 100))
			: null;

		$problem_badges = [];

		foreach (DockerCollector::problemsBySeverity($hostid) as $severity => $count) {
			$problem_badges[] = [
				'count' => $count,
				'style' => \CSeverityHelper::getStatusStyle($severity),
				'title' => \CSeverityHelper::getName($severity)
			];
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'overview' => DockerFormatter::formatOverview($data['overview']),
			'containers' => array_map(
				static fn (array $container): array => DockerFormatter::formatContainer($container, $node['mem_total']),
				$data['containers']
			),
			'problem_badges' => $problem_badges,
			'memory_pct' => $memory_pct
		])]));
	}
}

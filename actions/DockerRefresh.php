<?php declare(strict_types = 0);

namespace Modules\MonzphereDocker\Actions;

use API;
use CController;
use CControllerResponseData;
use CRoleHelper;
use CWebUser;
use Modules\MonzphereDocker\Includes\DockerCollector;
use Modules\MonzphereDocker\Includes\DockerFormatter;

class DockerRefresh extends CController {
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
		$data = DockerCollector::collect($this->getInput('hostid'));

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'overview' => DockerFormatter::formatOverview($data['overview']),
			'containers' => array_map([DockerFormatter::class, 'formatContainer'], $data['containers'])
		])]));
	}
}

<?php declare(strict_types = 0);

namespace Modules\MonzphereDocker\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use CSeverityHelper;
use CWebUser;
use Modules\MonzphereDocker\Includes\DockerCollector;

class CControllerDockerProblems extends CController {
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'hostids' => 'required|array_db hosts.hostid'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		if (!CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA)) {
			return false;
		}

		$hostids = array_values(array_unique($this->getInput('hostids')));
		$hosts = API::Host()->get([
			'output' => ['hostid'],
			'hostids' => $hostids
		]);

		return count($hosts) === count($hostids);
	}

	protected function doAction(): void {
		$hostids = array_values(array_unique($this->getInput('hostids')));
		$result = array_fill_keys($hostids, []);

		foreach (DockerCollector::problemsByHosts($hostids) as $hostid => $problems) {
			foreach ($problems['by_severity'] as $severity => $count) {
				$result[$hostid][] = [
					'count' => $count,
					'style' => CSeverityHelper::getStatusStyle((int) $severity),
					'title' => CSeverityHelper::getName((int) $severity)
				];
			}
		}

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode(['hosts' => $result])
		]));
	}
}

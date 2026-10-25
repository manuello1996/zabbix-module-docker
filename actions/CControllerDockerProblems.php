<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Actions;
use Modules\MonitorDocker\Includes\Common\ProblemBadges;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use CWebUser;
use Modules\MonitorDocker\Includes\DockerCollector;

require_once dirname(__DIR__).'/includes/common/bootstrap.php';

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
			$result[$hostid] = ProblemBadges::metadata($problems['by_severity']);
		}

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode(['hosts' => $result])
		]));
	}
}

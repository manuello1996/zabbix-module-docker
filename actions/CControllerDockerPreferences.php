<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Actions;

use CController;
use CControllerResponseData;
use CRoleHelper;
use CWebUser;
use Modules\MonitorDocker\Includes\DockerTablePreferences;

class CControllerDockerPreferences extends CController {
	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'page_size' => 'required|in 10,25,50,100',
			'sort' => 'required|in name,cpu,memory',
			'dir' => 'required|in -1,1',
			'columns' => 'required|array'
		]);

		if ($ret) {
			$columns = $this->getInput('columns');
			$ret = count($columns) <= count(DockerTablePreferences::COLUMNS)
				&& count(array_filter($columns, 'is_string')) === count($columns)
				&& DockerTablePreferences::isValid($this->preferences());
		}

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => _('Invalid table preferences.')
			])]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);
	}

	protected function doAction(): void {
		DockerTablePreferences::save($this->preferences());
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
	}

	private function preferences(): array {
		return [
			'page_size' => (int) $this->getInput('page_size'),
			'sort' => $this->getInput('sort'),
			'dir' => (int) $this->getInput('dir'),
			'columns' => array_values($this->getInput('columns'))
		];
	}
}

<?php declare(strict_types = 0);

namespace Modules\MonitorDocker;

use APP;
use CControllerTimeSelectorUpdate;
use CMenuItem;
use Zabbix\Core\CModule;

class Module extends CModule {
	public function init(): void {
		CControllerTimeSelectorUpdate::$profiles[] = 'web.docker.filter';

		APP::Component()->get('menu.main')
			->findOrAdd(_('Monitoring'))
			->getSubmenu()
			->insertAfter(_('Latest data'),
				(new CMenuItem(_('Docker')))->setAction('docker.list')
			);
	}
}

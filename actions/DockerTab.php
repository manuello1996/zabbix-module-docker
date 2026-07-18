<?php declare(strict_types = 0);

namespace Modules\MonzphereDocker\Actions;

use API;
use CCol;
use CController;
use CControllerResponseData;
use CDiv;
use CLink;
use CLinkAction;
use CMenuPopupHelper;
use CPagerHelper;
use CProfile;
use CRoleHelper;
use CSettingsHelper;
use CUrl;
use CSeverityHelper;
use CSpan;
use CTableInfo;
use CTag;
use CWebUser;
use Modules\MonzphereDocker\Includes\DockerFormatter;

class DockerTab extends CController {
	public const TIME_PROFILE_IDX = 'web.monzphere.docker.filter';

	public const PROFILE_ACTIVE_TAB = 'web.monzphere.docker.active_tab';

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' =>	'required|db hosts.hostid',
			'tab' =>	'required|in latest,problems,graphs,web,inventory,node,docker',
			'page' =>	'ge 1'
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
		$tab_rules = [
			'latest' => CRoleHelper::UI_MONITORING_LATEST_DATA,
			'problems' => CRoleHelper::UI_MONITORING_PROBLEMS,
			'graphs' => CRoleHelper::UI_MONITORING_HOSTS,
			'web' => CRoleHelper::UI_MONITORING_HOSTS,
			'inventory' => CRoleHelper::UI_INVENTORY_HOSTS,
			'node' => CRoleHelper::UI_MONITORING_LATEST_DATA,
			'docker' => CRoleHelper::UI_MONITORING_LATEST_DATA
		];

		if (!CWebUser::checkAccess($tab_rules[$this->getInput('tab')])) {
			return false;
		}

		return (bool) API::Host()->get([
			'output' => [],
			'hostids' => $this->getInput('hostid')
		]);
	}

	protected function doAction(): void {
		$hostid = $this->getInput('hostid');
		$tab = $this->getInput('tab');
		$page = (int) $this->getInput('page', 1);

		CProfile::update(self::PROFILE_ACTIVE_TAB, $tab === 'docker' ? '' : $tab, PROFILE_TYPE_STR);

		if ($tab === 'docker') {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['html' => ''])]));

			return;
		}

		switch ($tab) {
			case 'latest':
				$panel = $this->makeLatestPanel($hostid, $page);
				break;

			case 'problems':
				$panel = $this->makeProblemsPanel($hostid, $page);
				break;

			case 'graphs':
				$panel = $this->makeGraphsPanel($hostid);
				break;

			case 'node':
				$panel = $this->makeNodePanel($hostid);
				break;

			case 'web':
				$panel = $this->makeWebPanel($hostid, $page);
				break;

			default:
				$panel = $this->makeInventoryPanel($hostid);
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'html' => $panel->toString()
		])]));
	}

	private function makeLatestPanel(string $hostid, int $page): CDiv {
		$search_limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'lastvalue', 'lastclock', 'units', 'value_type'],
			'selectValueMap' => ['mappings'],
			'hostids' => $hostid,
			'monitored' => true,
			'webitems' => true,
			'sortfield' => 'name',
			'limit' => $search_limit + 1
		]);

		$paging = CPagerHelper::paginate($page, $items, ZBX_SORT_UP, $this->getTabUrl('latest'));

		$table = (new CTableInfo())
			->setHeader([_('Name'), _('Last check'), _('Last value')])
			->setNoDataMessage(_('No data found.'));

		foreach ($items as $item) {
			$has_value = $item['lastclock'] > 0;

			$table->addRow([
				$item['name'],
				$has_value
					? (new CSpan(zbx_date2age($item['lastclock']).' '._('ago')))
						->setTitle(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $item['lastclock']))
					: '-',
				$has_value
					? (new CSpan(formatHistoryValue($item['lastvalue'], $item)))
						->addClass('mnz-docker-latest-value')
					: '-'
			]);
		}

		return $this->wrapPanel(_('Latest data'), new CDiv([$table, $paging]));
	}

	private function getTabUrl(string $tab): CUrl {
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'monzphere.docker.tab')
			->setArgument('hostid', $this->getInput('hostid'))
			->setArgument('tab', $tab);
	}

	private function makeNodePanel(string $hostid): CDiv {
		$highlight_keys = [
			'docker.ping' => [_('Docker engine'), 'ping'],
			'docker.ncpu' => [_('CPUs'), 'raw'],
			'docker.mem.total' => [_('Memory'), 'bytes'],
			'docker.images.total' => [_('Images'), 'raw'],
			'docker.images_size' => [_('Images size'), 'bytes'],
			'docker.containers_size' => [_('Containers size'), 'bytes'],
			'docker.volumes_size' => [_('Volumes size'), 'bytes']
		];

		$property_keys = [
			'docker.operating_system' => _('Operating system'),
			'docker.os_type' => _('OS type'),
			'docker.architecture' => _('Architecture'),
			'docker.kernel_version' => _('Kernel version'),
			'docker.server_version' => _('Docker version'),
			'docker.driver' => _('Storage driver'),
			'docker.logging_driver' => _('Logging driver'),
			'docker.cgroup_driver' => _('Cgroup driver'),
			'docker.default_runtime' => _('Default runtime'),
			'docker.root_dir' => _('Root directory'),
			'docker.goroutines' => _('Goroutines'),
			'docker.nfd' => _('File descriptors')
		];

		$items = API::Item()->get([
			'output' => ['itemid', 'key_', 'lastvalue', 'lastclock'],
			'hostids' => $hostid,
			'filter' => ['key_' => array_merge(array_keys($highlight_keys), array_keys($property_keys))],
			'monitored' => true
		]);

		$values = [];

		foreach ($items as $item) {
			if ($item['lastclock'] > 0) {
				$values[$item['key_']] = $item['lastvalue'];
			}
		}

		$pills = [];

		foreach ($highlight_keys as $key => [$label, $format]) {
			$value = $values[$key] ?? null;

			if ($format === 'ping') {
				$pill_value = $value !== null && (int) $value === 1
					? (new CSpan(_('Up')))->addClass('mnz-docker-status-running')
					: (new CSpan(_('Down')))->addClass('mnz-docker-status-stopped');
			}
			elseif ($value === null) {
				$pill_value = new CSpan('-');
			}
			else {
				$pill_value = (new CSpan($format === 'bytes' ? DockerFormatter::bytes($value) : $value))
					->addClass('mnz-docker-card-value');
			}

			$pills[] = (new CDiv([
				(new CSpan($label))->addClass('mnz-docker-card-unit'),
				$pill_value
			]))->addClass('mnz-docker-stat');
		}

		$table = (new CTableInfo())
			->setHeader([_('Parameter'), _('Value')])
			->setNoDataMessage(_('No node information collected yet.'));

		foreach ($property_keys as $key => $label) {
			if (array_key_exists($key, $values)) {
				$table->addRow([$label, $values[$key]]);
			}
		}

		return $this->wrapPanel(_('Node info'), new CDiv([
			(new CDiv($pills))->addClass('mnz-docker-hostbar-stats')->addClass('mnz-docker-node-stats'),
			$table
		]));
	}

	private function makeProblemsPanel(string $hostid, int $page): CDiv {
		$search_limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$problems = API::Problem()->get([
			'output' => ['eventid', 'objectid', 'name', 'clock', 'severity', 'acknowledged'],
			'hostids' => $hostid,
			'recent' => true,
			'sortfield' => 'eventid',
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => $search_limit + 1
		]);

		$paging = CPagerHelper::paginate($page, $problems, ZBX_SORT_DOWN, $this->getTabUrl('problems'));

		$table = (new CTableInfo())
			->setHeader([_('Time'), _('Severity'), _('Problem'), _('Duration'), _('Ack')])
			->setNoDataMessage(_('No problems found.'));

		$backurl = (new CUrl('zabbix.php'))
			->setArgument('action', 'monzphere.docker.view')
			->getUrl();

		foreach ($problems as $problem) {
			$severity = (int) $problem['severity'];

			$name_link = (new CLinkAction($problem['name']))
				->setMenuPopup(CMenuPopupHelper::getTrigger([
					'triggerid' => $problem['objectid'],
					'backurl' => $backurl,
					'eventid' => $problem['eventid'],
					'show_update_problem' => true
				]));

			$clock_link = new CLink(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['clock']),
				(new CUrl('tr_events.php'))
					->setArgument('triggerid', $problem['objectid'])
					->setArgument('eventid', $problem['eventid'])
			);

			$table->addRow([
				$clock_link,
				(new CCol(CSeverityHelper::getName($severity)))
					->addClass(CSeverityHelper::getStyle($severity)),
				$name_link,
				zbx_date2age($problem['clock']),
				(int) $problem['acknowledged'] === EVENT_ACKNOWLEDGED
					? (new CSpan(_('Yes')))->addClass('mnz-docker-status-running')
					: (new CSpan(_('No')))->addClass('mnz-docker-status-stopped')
			]);
		}

		return $this->wrapPanel(_('Problems'), new CDiv([$table, $paging]));
	}

	private function makeGraphsPanel(string $hostid): CDiv {
		$graphs = API::Graph()->get([
			'output' => ['graphid', 'name'],
			'hostids' => $hostid,
			'sortfield' => 'name',
			'limit' => 50
		]);

		if (!$graphs) {
			return $this->wrapPanel(_('Graphs'),
				(new CTableInfo())->setNoDataMessage(_('No graphs found.'))
			);
		}

		$timeline = getTimeSelectorPeriod([
			'profileIdx' => self::TIME_PROFILE_IDX,
			'profileIdx2' => 0
		]);

		$panel = new CDiv();

		foreach ($graphs as $graph) {
			$panel->addItem(
				(new CDiv([
					(new CTag('h5', true, $graph['name']))->addClass('mnz-docker-graph-title'),
					(new CTag('img', false))
						->setAttribute('alt', $graph['name'])
						->setAttribute('loading', 'lazy')
						->setAttribute('src', 'chart2.php?'.http_build_query([
							'graphid' => $graph['graphid'],
							'from' => $timeline['from'],
							'to' => $timeline['to'],
							'height' => 201,
							'width' => 1436,
							'profileIdx' => self::TIME_PROFILE_IDX
						]))
				]))->addClass('mnz-docker-graph')
			);
		}

		return $this->wrapPanel(_('Graphs'), $panel);
	}

	private function makeWebPanel(string $hostid, int $page): CDiv {
		$search_limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$httptests = API::HttpTest()->get([
			'output' => ['httptestid', 'name', 'status', 'delay', 'nextcheck'],
			'hostids' => $hostid,
			'sortfield' => 'name',
			'limit' => $search_limit + 1
		]);

		$paging = CPagerHelper::paginate($page, $httptests, ZBX_SORT_UP, $this->getTabUrl('web'));

		$table = (new CTableInfo())
			->setHeader([_('Name'), _('Interval'), _('Status')])
			->setNoDataMessage(_('No web scenarios found.'));

		foreach ($httptests as $httptest) {
			$enabled = (int) $httptest['status'] === HTTPTEST_STATUS_ACTIVE;

			$table->addRow([
				$httptest['name'],
				$httptest['delay'],
				$enabled
					? (new CSpan(_('Enabled')))->addClass('mnz-docker-status-running')
					: (new CSpan(_('Disabled')))->addClass('mnz-docker-status-stopped')
			]);
		}

		return $this->wrapPanel(_('Web scenarios'), new CDiv([$table, $paging]));
	}

	private function makeInventoryPanel(string $hostid): CDiv {
		$hosts = API::Host()->get([
			'output' => ['hostid'],
			'selectInventory' => true,
			'hostids' => $hostid
		]);

		$inventory = $hosts ? array_filter((array) $hosts[0]['inventory'], 'strlen') : [];
		unset($inventory['hostid'], $inventory['inventory_mode']);

		$table = (new CTableInfo())
			->setHeader([_('Field'), _('Value')])
			->setNoDataMessage(_('No inventory data found.'));

		$titles = array_column(getHostInventories(), 'title', 'db_field');

		foreach ($inventory as $field => $value) {
			$table->addRow([$titles[$field] ?? $field, $value]);
		}

		return $this->wrapPanel(_('Inventory'), $table);
	}

	private function wrapPanel(string $title, CTag $body): CDiv {
		return (new CDiv([
			(new CTag('h4', true, $title))->addClass('mnz-docker-section-title'),
			$body
		]))->addClass('mnz-docker-section');
	}
}

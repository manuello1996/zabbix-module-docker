<?php declare(strict_types = 0);

use Modules\MonitorDocker\Includes\DockerFormatter;

$this->addJsFile('class.calendar.js');
$this->addJsFile('gtlc.js');
$this->addJsFile('multilineinput.js');
$this->addJsFile('items.js');

$this->includeJsFile('docker.view.js.php');



$makeSparkline = static function (array $itemids, string $kind, string $mode = 'single'): CDiv {
	return (new CDiv())
		->addClass('docker-sparkline')
		->addClass('docker-spark-'.$kind)
		->setAttribute('data-spark-itemids', implode(',', $itemids))
		->setAttribute('data-spark-kind', $kind)
		->setAttribute('data-spark-mode', $mode)
		->setAttribute('aria-label', _('Loading history'));
};

$html_page = (new CHtmlPage())
	->setTitle(_('Docker'))
	->setWebLayoutMode(CViewHelper::loadLayoutMode());

$filter = (new CFilter())
	->setResetUrl(new CUrl('zabbix.php?action=docker.view'))
	->setProfile($data['timeline']['profileIdx'], $data['timeline']['profileIdx2'])
	->setActiveTab($data['active_tab'])
	->addTimeSelector($data['timeline']['from'], $data['timeline']['to'], true,
		'web.docker.filter'
	)
	->addVar('action', 'docker.view');

$filter_button = new CSubmitButton(_('Filter'), 'filter_set', 1);

if ($data['host'] === null) {
	$filter_button->setAttribute('disabled', 'disabled');
}

$filterbar = (new CForm('get'))
	->cleanItems()
	->setName('docker_filterbar')
	->addVar('action', 'docker.view')
	->addItem(
		(new CDiv([
			new CLabel(_('Host groups'), 'filter_groupids__ms'),
			(new CMultiSelect([
				'name' => 'filter_groupids[]',
				'object_name' => 'hostGroup',
				'data' => $data['filter']['groups'],
				'popup' => [
					'parameters' => [
						'srctbl' => 'host_groups',
						'srcfld1' => 'groupid',
						'dstfrm' => 'docker_filterbar',
						'dstfld1' => 'filter_groupids_',
						'with_monitored_items' => true
					]
				]
			]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH),
			new CLabel(_('Host'), 'filter_hostid__ms'),
			(new CMultiSelect([
				'name' => 'filter_hostid[]',
				'object_name' => 'hosts',
				'multiple' => false,
				'data' => $data['host'] !== null
					? [['id' => $data['host']['hostid'], 'name' => $data['host']['name']]]
					: [],
				'popup' => [
					'parameters' => [
						'srctbl' => 'hosts',
						'srcfld1' => 'hostid',
						'dstfrm' => 'docker_filterbar',
						'dstfld1' => 'filter_hostid_',
						'with_monitored_items' => true
					]
				]
			]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH),
			$filter_button,
			(new CRedirectButton(_('Reset'),
				(new CUrl('zabbix.php'))
					->setArgument('action', 'docker.view')
					->setArgument('filter_rst', 1)
			))->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('docker-filterbar')
	);

if ($data['host'] === null) {
	$html_page
		->addItem($filter)
		->addItem($filterbar)
		->addItem(
			(new CTableInfo())->setNoDataMessage(
				$data['hosts']
					? _('Select a Docker node to view its containers.')
					: _('No hosts with Docker metrics found. Link the "Docker by Zabbix agent 2" template to a host.')
			)
		)
		->show();

	(new CScriptTag('monitor_docker.init('.json_encode([
		'hostid' => ''
	]).');'))
		->setOnDocumentReady()
		->show();

	return;
}

$host_enabled = (int) $data['host']['status'] === HOST_STATUS_MONITORED;

$overview = DockerFormatter::formatOverview($data['overview']);

$memory_parts = explode(' ', $overview['memory_total'], 2);

$makeIconButton = static function (string $modifier, string $label): CTag {
	return (new CTag('button', true))
		->setAttribute('type', 'button')
		->addClass('docker-iconbtn')
		->addClass('docker-iconbtn-'.$modifier)
		->setId('docker-btn-'.$modifier)
		->setAttribute('aria-label', $label)
		->setTitle($label);
};

$meta_parts = [];

if ($data['agent_address'] !== '') {
	$meta_parts[] = (new CSpan([_('host').': ', $data['agent_address']]));
}

if ($data['node']['uptime'] !== null) {
	$meta_parts[] = (new CSpan([_('Uptime').': ', DockerFormatter::shortDuration($data['node']['uptime'])]));
}

$host_note = trim((string) ($data['host']['inventory']['notes'] ?? ''));
$meta_parts[] = (new CSpan([_('Note').': ', $host_note !== '' ? $host_note : '-']));

$html_page->addItem(
	(new CDiv([
		(new CDiv([
			(new CDiv([
				new CLink(_('Nodes'), (new CUrl('zabbix.php'))->setArgument('action', 'docker.list')),
				(new CSpan('›'))->addClass('docker-breadcrumb-sep'),
				(new CSpan($data['host']['name']))->addClass('docker-breadcrumb-current')
			]))
				->addClass('docker-breadcrumb')
				->setAttribute('aria-label', _('Breadcrumb')),
			(new CDiv([
				(new CSpan($host_enabled ? _('Enabled') : _('Disabled')))
					->addClass($host_enabled ? ZBX_STYLE_GREEN : ZBX_STYLE_RED),
				(new CHostAvailability())->setInterfaces($data['host']['interfaces'])
			]))->addClass('docker-topbar-chips'),
			$meta_parts ? (new CDiv($meta_parts))->addClass('docker-topbar-meta') : null
		]))->addClass('docker-topbar-info'),
		(new CDiv([
			$makeIconButton('filter', _('Toggle filters'))
		]))->addClass('docker-topbar-actions')
	]))->addClass('docker-topbar')
);

$html_page->addItem(
	(new CDiv($filterbar))
		->setId('docker-filters')
		->setAttribute('hidden', 'hidden')
);

$cpu_itemids = [];

foreach ($data['containers'] as $container) {
	if ($container['is_running'] && $container['cpu_itemid'] !== null) {
		$cpu_itemids[] = $container['cpu_itemid'];
	}
}

$memory_pct = ($data['node']['mem_total'] !== null && (float) $data['node']['mem_total'] > 0)
	? (int) round(min(100, (float) $data['overview']['memory_total'] / (float) $data['node']['mem_total'] * 100))
	: 0;

$makeStatSegment = static function (string $modifier, string $label, $value, $unit, $aside): CDiv {
	return (new CDiv([
		(new CDiv($label))->addClass('docker-statseg-label'),
		(new CDiv([
			(new CSpan($value))->addClass('docker-card-value'),
			$unit !== '' ? (new CSpan($unit))->addClass('docker-card-unit') : null,
			$aside !== null ? (new CDiv($aside))->addClass('docker-statseg-aside') : null
		]))->addClass('docker-statseg-figure')
	]))
		->addClass('docker-statseg')
		->addClass('docker-card-'.$modifier);
};

$html_page->addItem(
	(new CDiv([
		$makeStatSegment('total', _('Containers'), $overview['total'], '', null),
		$makeStatSegment('running', _('Running'), $overview['running'], '', null),
		$makeStatSegment('healthy', _('Healthy'), $overview['healthy'], '', null),
		$makeStatSegment('unhealthy', _('Unhealthy'), $overview['unhealthy'], '', null),
		$makeStatSegment('stopped', _('Stopped / Err'), $overview['stopped'], '', null),
		$makeStatSegment('cpu', _('CPU usage'), $overview['cpu_total'], '%',
			$makeSparkline($cpu_itemids, 'up', 'sum')
		),
		$makeStatSegment('memory', _('Memory (used)'), $memory_parts[0], $memory_parts[1] ?? '',
			(new CDiv(
				(new CDiv())
					->addClass('docker-progress-fill')
					->setId('docker-memory-fill')
					->setAttribute('style', 'width: '.$memory_pct.'%;')
			))->addClass('docker-progress')
		)
	]))
		->addClass('docker-statstrip')
		->setId('docker-cards')
);

$problems_badge = (new CDiv())
	->setId('docker-problems-badge')
	->addClass(ZBX_STYLE_PROBLEM_ICON_LIST)
	->addClass('docker-tab-problems');

foreach ($data['problems_by_severity'] as $severity => $count) {
	$problems_badge->addItem(
		(new CSpan($count))
			->addClass(ZBX_STYLE_PROBLEM_ICON_LIST_ITEM)
			->addClass(CSeverityHelper::getStatusStyle($severity))
			->setTitle(CSeverityHelper::getName($severity))
	);
}

$tab_items = [
	(new CSpan(_('Containers')))
		->addClass('docker-tab')
		->addClass('docker-tab-active')
		->setAttribute('data-tab', '')
		->setAttribute('role', 'tab')
		->setAttribute('tabindex', '0')
		->setAttribute('aria-selected', 'true')
];

foreach ([
	['compose', _('Compose'), null],
	['problems', _('Problems'), $problems_badge],
	['graphs', _('Graphs'), null],
	['images', _('Images'), null],
	['volumes', _('Volumes'), null],
	['mounts', _('Mounts'), null],
	['networks', _('Networks'), null],
	['node', _('Node info'), null]
] as [$key, $label, $badge]) {
	$tab_items[] = (new CSpan([$label, $badge]))
		->addClass('docker-tab')
		->setAttribute('data-tab', $key)
		->setAttribute('role', 'tab')
		->setAttribute('tabindex', '0')
		->setAttribute('aria-selected', 'false');
}

$html_page->addItem(
	(new CDiv($tab_items))
		->addClass('docker-tabs')
		->setAttribute('role', 'tablist')
);

$status_select = (new CTag('select', true, [
	(new CTag('option', true, _('All')))->setAttribute('value', 'all'),
	(new CTag('option', true, _('Running')))->setAttribute('value', 'running'),
	(new CTag('option', true, _('Restarting')))->setAttribute('value', 'restarting'),
	(new CTag('option', true, _('Paused')))->setAttribute('value', 'paused'),
	(new CTag('option', true, _('Stopped')))->setAttribute('value', 'exited')
]))
	->setId('docker-status')
	->addClass('docker-status-select')
	->setAttribute('aria-label', _('Filter by runtime state'));

$health_select = (new CTag('select', true, [
	(new CTag('option', true, _('All')))->setAttribute('value', 'all'),
	(new CTag('option', true, _('Healthy')))->setAttribute('value', 'healthy'),
	(new CTag('option', true, _('Unhealthy')))->setAttribute('value', 'unhealthy'),
	(new CTag('option', true, _('Starting')))->setAttribute('value', 'starting'),
	(new CTag('option', true, _('No health check')))->setAttribute('value', 'none')
]))
	->setId('docker-health')
	->addClass('docker-status-select')
	->setAttribute('aria-label', _('Filter by health'));

$toolbar = (new CDiv([
	(new CTag('input', false))
		->setId('docker-search')
		->setAttribute('type', 'search')
		->setAttribute('placeholder', _('Filter containers...'))
		->setAttribute('aria-label', _('Filter containers by name or note'))
		->setAttribute('autocomplete', 'off')
		->addClass('docker-search'),
	(new CDiv([
		(new CSpan(_('State').':'))->addClass('docker-status-label'),
		$status_select
	]))->addClass('docker-status-wrap'),
	(new CDiv([
		(new CSpan(_('Health').':'))->addClass('docker-status-label'),
		$health_select
	]))->addClass('docker-status-wrap'),
	(new CDiv([
		(new CTag('button', true, '‹'))
			->setAttribute('type', 'button')
			->setId('docker-pager-prev')
			->addClass('docker-pager-btn')
			->setAttribute('aria-label', _('Previous page')),
		(new CSpan(''))->setId('docker-pager-info')->addClass('docker-pager-info'),
		(new CTag('button', true, '›'))
			->setAttribute('type', 'button')
			->setId('docker-pager-next')
			->addClass('docker-pager-btn')
			->setAttribute('aria-label', _('Next page'))
	]))->addClass('docker-pager')
]))->addClass('docker-toolbar');

$makeSortHeader = static function (string $label, string $key): CSpan {
	return (new CSpan([$label, (new CSpan())->addClass('docker-sort-arrow')]))
		->addClass('docker-sort')
		->setAttribute('data-sort', $key)
		->setAttribute('role', 'button')
		->setAttribute('tabindex', '0');
};

$table = (new CTableInfo())
	->setId('docker-table')
	->setAttribute('data-total', (string) ($data['containers_total'] ?? count($data['containers'])))
	->setHeader([
		$makeSortHeader(_('Container name'), 'name'),
		_('Note'),
		_('Uptime'),
		_('Health'),
		$makeSortHeader(_('CPU % (24h)'), 'cpu'),
		$makeSortHeader(_('Memory usage'), 'memory'),
		_('Net I/O (rx/tx)')
	])
	->setNoDataMessage(_('No container data collected yet.'));

foreach ($data['containers'] as $container) {
	$row = DockerFormatter::formatContainer($container, $data['node']['mem_total']);

	$table->addRow((new CRow([
		(new CLinkAction($row['name']))
			->addClass('docker-name')
			->setAttribute('data-container', $row['name']),

		(new CSpan($row['note']))->addClass('js-note'),

		(new CSpan($row['status_text']))
			->addClass('docker-state')
			->addClass('docker-state-'.$row['status_kind'])
			->addClass('js-status'),

		(new CSpan($row['health_text']))
			->addClass('docker-health')
			->addClass('docker-health-'.$row['health_kind']),

		(new CDiv([
			(new CSpan($row['cpu']))->addClass('docker-metric-value')->addClass('js-cpu'),
			$makeSparkline(
				$container['cpu_itemid'] !== null ? [$container['cpu_itemid']] : [],
				$row['status_kind']
			)
		]))->addClass('docker-metric-cell'),

		(new CDiv([
			(new CSpan($row['memory']))->addClass('docker-metric-value')->addClass('js-mem-val'),
			$makeSparkline(
				$container['memory_itemid'] !== null ? [$container['memory_itemid']] : [],
				$row['status_kind']
			)
		]))->addClass('docker-metric-cell'),

		(new CDiv([
			(new CSpan($row['net_in']))->addClass('docker-net-rx')->addClass('js-rx'),
			(new CSpan('/'))->addClass('docker-net-sep'),
			(new CSpan($row['net_out']))->addClass('docker-net-tx')->addClass('js-tx')
		]))->addClass('docker-netcell')
	]))
		->addClass($row['status_kind'] === 'restarting' ? 'docker-row-restarting' : null)
		->addClass(in_array($row['status_kind'], ['down', 'off'], true) ? 'docker-row-off' : null)
		->setAttribute('data-name', mb_strtolower($row['name']))
		->setAttribute('data-note', mb_strtolower($row['note']))
		->setAttribute('data-status', strtolower((string) ($container['status'] ?? 'unknown')))
		->setAttribute('data-health', $row['health_kind'])
		->setAttribute('data-cpu', (string) $row['cpu_raw'])
		->setAttribute('data-memory', (string) $row['memory_raw'])
	);
}

$table_section = (new CDiv([
	(new CTag('h4', true, _('Container metrics (latest)')))->addClass('docker-section-title'),
	$toolbar,
	$table,
	$data['paging'] ?? null
]))->addClass('docker-section');

$modal = (new CDiv(
	(new CDiv([
		(new CDiv([
			(new CTag('h4', true))->setId('docker-modal-title'),
			(new CTag('button', true, '×'))
				->setAttribute('type', 'button')
				->addClass('docker-modal-close')
				->setAttribute('aria-label', _('Close'))
		]))->addClass('docker-modal-header'),
		(new CDiv())->addClass('docker-modal-body')
	]))
		->addClass('docker-modal')
		->setAttribute('role', 'dialog')
		->setAttribute('aria-modal', 'true')
		->setAttribute('aria-labelledby', 'docker-modal-title')
		->setAttribute('tabindex', '-1')
))
	->setId('docker-modal')
	->addClass('docker-modal-backdrop')
	->setAttribute('hidden', 'hidden');

$html_page
	->addItem(
		(new CDiv($table_section))->setId('docker-content')
	)
	->addItem(
		(new CDiv($filter))
			->setId('docker-timefilter')
			->setAttribute('hidden', 'hidden')
	)
	->addItem(
		(new CDiv())
			->setId('docker-panel')
			->addClass('docker-panel')
			->setAttribute('hidden', 'hidden')
	)
	->addItem($modal)
	->show();

(new CScriptTag('monitor_docker.init('.json_encode([
	'hostid' => $data['filter']['hostid']
]).');'))
	->setOnDocumentReady()
	->show();

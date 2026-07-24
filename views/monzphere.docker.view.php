<?php declare(strict_types = 0);

use Modules\MonzphereDocker\Includes\DockerFormatter;

$this->addJsFile('class.calendar.js');
$this->addJsFile('gtlc.js');
$this->addJsFile('multilineinput.js');
$this->addJsFile('items.js');

$this->includeJsFile('monzphere.docker.view.js.php');



$makeSparkline = static function (array $itemids, string $kind, string $mode = 'single'): CDiv {
	return (new CDiv())
		->addClass('mnz-docker-sparkline')
		->addClass('mnz-docker-spark-'.$kind)
		->setAttribute('data-mnz-spark-itemids', implode(',', $itemids))
		->setAttribute('data-mnz-spark-kind', $kind)
		->setAttribute('data-mnz-spark-mode', $mode)
		->setAttribute('aria-label', _('Loading history'));
};

$html_page = (new CHtmlPage())
	->setTitle(_('Docker'))
	->setWebLayoutMode(CViewHelper::loadLayoutMode());

$filter = (new CFilter())
	->setResetUrl(new CUrl('zabbix.php?action=monzphere.docker.view'))
	->setProfile($data['timeline']['profileIdx'], $data['timeline']['profileIdx2'])
	->setActiveTab($data['active_tab'])
	->addTimeSelector($data['timeline']['from'], $data['timeline']['to'], true,
		'web.monzphere.docker.filter'
	)
	->addVar('action', 'monzphere.docker.view');

$filterbar = (new CForm('get'))
	->cleanItems()
	->setName('mnz_docker_filterbar')
	->addVar('action', 'monzphere.docker.view')
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
						'dstfrm' => 'mnz_docker_filterbar',
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
						'dstfrm' => 'mnz_docker_filterbar',
						'dstfld1' => 'filter_hostid_',
						'with_monitored_items' => true
					]
				]
			]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH),
			new CSubmitButton(_('Filter'), 'filter_set', 1),
			(new CRedirectButton(_('Reset'),
				(new CUrl('zabbix.php'))
					->setArgument('action', 'monzphere.docker.view')
					->setArgument('filter_rst', 1)
			))->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('mnz-docker-filterbar')
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

	return;
}

$host_enabled = (int) $data['host']['status'] === HOST_STATUS_MONITORED;

$overview = DockerFormatter::formatOverview($data['overview']);

$memory_parts = explode(' ', $overview['memory_total'], 2);

$makeIconButton = static function (string $modifier, string $label): CTag {
	return (new CTag('button', true))
		->setAttribute('type', 'button')
		->addClass('mnz-docker-iconbtn')
		->addClass('mnz-docker-iconbtn-'.$modifier)
		->setId('mnz-docker-btn-'.$modifier)
		->setAttribute('aria-label', $label)
		->setTitle($label);
};

$kebab_menu = (new CDiv([
	(new CLink(_('Docker nodes'), (new CUrl('zabbix.php'))->setArgument('action', 'monzphere.docker.list')))
		->addClass('mnz-docker-menu-item')
]))
	->setId('mnz-docker-kebab-menu')
	->addClass('mnz-docker-menu')
	->setAttribute('hidden', 'hidden');

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
				new CLink(_('Nodes'), (new CUrl('zabbix.php'))->setArgument('action', 'monzphere.docker.list')),
				(new CSpan('›'))->addClass('mnz-docker-breadcrumb-sep'),
				(new CSpan($data['host']['name']))->addClass('mnz-docker-breadcrumb-current')
			]))
				->addClass('mnz-docker-breadcrumb')
				->setAttribute('aria-label', _('Breadcrumb')),
			(new CDiv([
				(new CSpan($host_enabled ? _('Enabled') : _('Disabled')))
					->addClass($host_enabled ? ZBX_STYLE_GREEN : ZBX_STYLE_RED),
				(new CHostAvailability())->setInterfaces($data['host']['interfaces'])
			]))->addClass('mnz-docker-topbar-chips'),
			$meta_parts ? (new CDiv($meta_parts))->addClass('mnz-docker-topbar-meta') : null
		]))->addClass('mnz-docker-topbar-info'),
		(new CDiv([
			$makeIconButton('filter', _('Toggle filters')),
			$makeIconButton('refresh', _('Refresh now')),
			(new CDiv([
				$makeIconButton('kebab', _('More actions')),
				$kebab_menu
			]))->addClass('mnz-docker-kebab-wrap')
		]))->addClass('mnz-docker-topbar-actions')
	]))->addClass('mnz-docker-topbar')
);

$html_page->addItem(
	(new CDiv($filterbar))
		->setId('mnz-docker-filters')
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
		(new CDiv($label))->addClass('mnz-docker-statseg-label'),
		(new CDiv([
			(new CSpan($value))->addClass('mnz-docker-card-value'),
			$unit !== '' ? (new CSpan($unit))->addClass('mnz-docker-card-unit') : null,
			$aside !== null ? (new CDiv($aside))->addClass('mnz-docker-statseg-aside') : null
		]))->addClass('mnz-docker-statseg-figure')
	]))
		->addClass('mnz-docker-statseg')
		->addClass('mnz-docker-card-'.$modifier);
};

$html_page->addItem(
	(new CDiv([
		$makeStatSegment('total', _('Containers'), $overview['total'], '', null),
		$makeStatSegment('running', _('Running'), $overview['running'], '', null),
		$makeStatSegment('stopped', _('Stopped / Err'), $overview['stopped'], '', null),
		$makeStatSegment('cpu', _('CPU usage'), $overview['cpu_total'], '%',
			$makeSparkline($cpu_itemids, 'up', 'sum')
		),
		$makeStatSegment('memory', _('Memory (used)'), $memory_parts[0], $memory_parts[1] ?? '',
			(new CDiv(
				(new CDiv())
					->addClass('mnz-docker-progress-fill')
					->setId('mnz-docker-memory-fill')
					->setAttribute('style', 'width: '.$memory_pct.'%;')
			))->addClass('mnz-docker-progress')
		)
	]))
		->addClass('mnz-docker-statstrip')
		->setId('mnz-docker-cards')
);

$problems_badge = (new CDiv())
	->setId('mnz-docker-problems-badge')
	->addClass(ZBX_STYLE_PROBLEM_ICON_LIST)
	->addClass('mnz-docker-tab-problems');

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
		->addClass('mnz-docker-tab')
		->addClass('mnz-docker-tab-active')
		->setAttribute('data-mnz-tab', '')
		->setAttribute('role', 'tab')
		->setAttribute('tabindex', '0')
		->setAttribute('aria-selected', 'true')
];

foreach ([
	['problems', _('Problems'), $problems_badge],
	['graphs', _('Graphs'), null],
	['images', _('Images'), null],
	['volumes', _('Volumes'), null],
	['mounts', _('Mounts'), null],
	['networks', _('Networks'), null],
	['node', _('Node info'), null]
] as [$key, $label, $badge]) {
	$tab_items[] = (new CSpan([$label, $badge]))
		->addClass('mnz-docker-tab')
		->setAttribute('data-mnz-tab', $key)
		->setAttribute('role', 'tab')
		->setAttribute('tabindex', '0')
		->setAttribute('aria-selected', 'false');
}

$html_page->addItem(
	(new CDiv($tab_items))
		->addClass('mnz-docker-tabs')
		->setAttribute('role', 'tablist')
);

$status_select = (new CTag('select', true, [
	(new CTag('option', true, _('All')))->setAttribute('value', 'all'),
	(new CTag('option', true, _('Running')))->setAttribute('value', 'running'),
	(new CTag('option', true, _('Stopped')))->setAttribute('value', 'stopped')
]))
	->setId('mnz-docker-status')
	->addClass('mnz-docker-status-select')
	->setAttribute('aria-label', _('Filter by status'));

$toolbar = (new CDiv([
	(new CTag('input', false))
		->setId('mnz-docker-search')
		->setAttribute('type', 'search')
		->setAttribute('placeholder', _('Filter containers...'))
		->setAttribute('aria-label', _('Filter containers by name or note'))
		->setAttribute('autocomplete', 'off')
		->addClass('mnz-docker-search'),
	(new CDiv([
		(new CSpan(_('Status').':'))->addClass('mnz-docker-status-label'),
		$status_select
	]))->addClass('mnz-docker-status-wrap'),
	(new CDiv([
		(new CTag('button', true, '‹'))
			->setAttribute('type', 'button')
			->setId('mnz-docker-pager-prev')
			->addClass('mnz-docker-pager-btn')
			->setAttribute('aria-label', _('Previous page')),
		(new CSpan(''))->setId('mnz-docker-pager-info')->addClass('mnz-docker-pager-info'),
		(new CTag('button', true, '›'))
			->setAttribute('type', 'button')
			->setId('mnz-docker-pager-next')
			->addClass('mnz-docker-pager-btn')
			->setAttribute('aria-label', _('Next page'))
	]))->addClass('mnz-docker-pager')
]))->addClass('mnz-docker-toolbar');

$makeSortHeader = static function (string $label, string $key): CSpan {
	return (new CSpan([$label, (new CSpan())->addClass('mnz-docker-sort-arrow')]))
		->addClass('mnz-docker-sort')
		->setAttribute('data-mnz-sort', $key)
		->setAttribute('role', 'button')
		->setAttribute('tabindex', '0');
};

$table = (new CTableInfo())
	->setId('mnz-docker-table')
	->setAttribute('data-mnz-total', (string) ($data['containers_total'] ?? count($data['containers'])))
	->setHeader([
		$makeSortHeader(_('Container name'), 'name'),
		_('Note'),
		_('Status'),
		$makeSortHeader(_('CPU % (24h)'), 'cpu'),
		$makeSortHeader(_('Memory usage'), 'memory'),
		_('Net I/O (rx/tx)')
	])
	->setNoDataMessage(_('No container data collected yet.'));

foreach ($data['containers'] as $container) {
	$row = DockerFormatter::formatContainer($container, $data['node']['mem_total']);

	$table->addRow((new CRow([
		(new CLinkAction($row['name']))
			->addClass('mnz-docker-name')
			->setAttribute('data-mnz-container', $row['name']),

		(new CSpan($row['note']))->addClass('js-note'),

		(new CSpan($row['status_text']))
			->addClass('mnz-docker-state')
			->addClass('mnz-docker-state-'.$row['status_kind'])
			->addClass('js-status'),

		(new CDiv([
			(new CSpan($row['cpu']))->addClass('mnz-docker-metric-value')->addClass('js-cpu'),
			$makeSparkline(
				$container['cpu_itemid'] !== null ? [$container['cpu_itemid']] : [],
				$row['status_kind']
			)
		]))->addClass('mnz-docker-metric-cell'),

		(new CDiv([
			(new CSpan($row['memory']))->addClass('mnz-docker-metric-value')->addClass('js-mem-val'),
			$makeSparkline(
				$container['memory_itemid'] !== null ? [$container['memory_itemid']] : [],
				$row['status_kind']
			)
		]))->addClass('mnz-docker-metric-cell'),

		(new CDiv([
			(new CSpan($row['net_in']))->addClass('mnz-docker-net-rx')->addClass('js-rx'),
			(new CSpan('/'))->addClass('mnz-docker-net-sep'),
			(new CSpan($row['net_out']))->addClass('mnz-docker-net-tx')->addClass('js-tx')
		]))->addClass('mnz-docker-netcell')
	]))
		->addClass($row['status_kind'] === 'restarting' ? 'mnz-docker-row-restarting' : null)
		->addClass(in_array($row['status_kind'], ['down', 'off'], true) ? 'mnz-docker-row-off' : null)
		->setAttribute('data-mnz-name', mb_strtolower($row['name']))
		->setAttribute('data-mnz-note', mb_strtolower($row['note']))
		->setAttribute('data-mnz-status', $row['is_running'] ? 'running' : 'stopped')
		->setAttribute('data-mnz-cpu', (string) $row['cpu_raw'])
		->setAttribute('data-mnz-memory', (string) $row['memory_raw'])
	);
}

$table_section = (new CDiv([
	(new CTag('h4', true, _('Container metrics (latest)')))->addClass('mnz-docker-section-title'),
	$toolbar,
	$table,
	$data['paging'] ?? null
]))->addClass('mnz-docker-section');

$modal = (new CDiv(
	(new CDiv([
		(new CDiv([
			(new CTag('h4', true))->setId('mnz-docker-modal-title'),
			(new CTag('button', true, '×'))
				->setAttribute('type', 'button')
				->addClass('mnz-docker-modal-close')
				->setAttribute('aria-label', _('Close'))
		]))->addClass('mnz-docker-modal-header'),
		(new CDiv())->addClass('mnz-docker-modal-body')
	]))
		->addClass('mnz-docker-modal')
		->setAttribute('role', 'dialog')
		->setAttribute('aria-modal', 'true')
		->setAttribute('aria-labelledby', 'mnz-docker-modal-title')
		->setAttribute('tabindex', '-1')
))
	->setId('mnz-docker-modal')
	->addClass('mnz-docker-modal-backdrop')
	->setAttribute('hidden', 'hidden');

$html_page
	->addItem(
		(new CDiv($table_section))->setId('mnz-docker-content')
	)
	->addItem(
		(new CDiv($filter))
			->setId('mnz-docker-timefilter')
			->setAttribute('hidden', 'hidden')
	)
	->addItem(
		(new CDiv())
			->setId('mnz-docker-panel')
			->addClass('mnz-docker-panel')
			->setAttribute('hidden', 'hidden')
	)
	->addItem($modal)
	->show();

(new CScriptTag('monzphere_docker.init('.json_encode([
	'hostid' => $data['filter']['hostid'],
	'refresh_interval' => $data['refresh_interval']
]).');'))
	->setOnDocumentReady()
	->show();

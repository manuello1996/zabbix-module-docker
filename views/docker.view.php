<?php declare(strict_types = 0);
use Modules\MonitorDocker\Includes\Common\Summary;
use Modules\MonitorDocker\Includes\Common\Breadcrumbs;
use Modules\MonitorDocker\Includes\Common\ProblemBadges;
use Modules\MonitorDocker\Includes\Common\CapacityBar;
use Modules\MonitorDocker\Includes\Common\SortableHeader;
use Modules\MonitorDocker\Includes\Common\Sparkline;
use Modules\MonitorDocker\Includes\Common\Pager;

use Modules\MonitorDocker\Includes\Common\SearchControl;

use Modules\MonitorDocker\Includes\DockerFormatter;
use Modules\MonitorDocker\Includes\DockerTablePreferences;

require_once dirname(__DIR__).'/includes/common/bootstrap.php';

$this->addJsFile('class.calendar.js');
$this->addJsFile('gtlc.js');
$this->addJsFile('multilineinput.js');
$this->addJsFile('items.js');

$this->includeJsFile('docker.view.js.php');

$makeSparkline = static function (array $itemids, string $kind, string $mode = 'single', ?float $total = null): CDiv {
	$attributes = [
		'data-spark-itemids' => implode(',', $itemids), 'data-spark-kind' => $kind, 'data-spark-mode' => $mode
	];
	if ($total !== null) {
		$attributes['data-spark-total'] = $total;
		$attributes['title'] = _('Memory usage as a percentage of Docker node memory');
	}
	return Sparkline::holder($attributes, $kind);
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
			Breadcrumbs::make([
				new CLink(_('Nodes'), (new CUrl('zabbix.php'))->setArgument('action', 'docker.list')),
				(new CSpan($data['host']['name']))
			])
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
	return Summary::card($label, $value, ['modifier' => $modifier, 'unit' => $unit, 'aside' => $aside]);
};

$html_page->addItem(
	Summary::strip([
		$makeStatSegment('total', _('Containers'), $overview['total'], '', null),
		$makeStatSegment('running', _('Running'), $overview['running'], '', null),
		$makeStatSegment('healthy', _('Healthy'), $overview['healthy'], '', null),
		$makeStatSegment('unhealthy', _('Unhealthy'), $overview['unhealthy'], '', null),
		$makeStatSegment('stopped', _('Stopped / Err'), $overview['stopped'], '', null),
		$makeStatSegment('cpu', _('CPU usage'), $overview['cpu_total'], '%',
			$makeSparkline($cpu_itemids, 'up', 'sum')
		),
		$makeStatSegment('memory', _('Memory (used)'), $memory_parts[0], $memory_parts[1] ?? '',
			CapacityBar::render($memory_pct, ['tone' => 'warning', 'threshold' => 101, 'fill_id' => 'docker-memory-fill'])
		)
	])
		->setId('docker-cards')
);

$problems_badge = ProblemBadges::counts($data['problems_by_severity'])
	->setId('docker-problems-badge')->addClass('docker-tab-problems');

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

$table_controls = (new CDiv([
	(new CDiv([
		(new CSpan(_('State').':'))->addClass('docker-status-label'),
		$status_select
	]))->addClass('docker-status-wrap'),
	(new CDiv([
		(new CSpan(_('Health').':'))->addClass('docker-status-label'),
		$health_select
	]))->addClass('docker-status-wrap'),
	(new CTag('label', true, [
		_('Rows').': ',
		(new CTag('select', true, array_map(static fn (int $size): CTag =>
			(new CTag('option', true, (string) $size))->setAttribute('value', (string) $size),
			DockerTablePreferences::PAGE_SIZES
		)))->setId('docker-page-size')->addClass('docker-status-select')
	])),
	(new CTag('details', true, [
		new CTag('summary', true, _('Columns')),
		(new CDiv(array_map(static fn (string $key, string $label): CTag =>
			(new CTag('label', true, [
				(new CTag('input', false))->setAttribute('type', 'checkbox')
					->setAttribute('data-docker-column', $key)
					->setAttribute('disabled', $key === 'name' ? 'disabled' : null),
				$label
			])),
			DockerTablePreferences::COLUMNS,
			[_('Container name'), _('Note'), _('Uptime'), _('Health'), _('CPU'), _('Memory'), _('Network')]
		)))->addClass('docker-column-options')
	]))->addClass('docker-column-settings'),
	(new CSpan())->setId('docker-preferences-status')->setAttribute('aria-live', 'polite'),
	(new CTag('button', true, _('Retry save')))->setAttribute('type', 'button')
		->setId('docker-preferences-retry')->setAttribute('hidden', 'hidden'),
	Pager::controls('docker-pager-prev', 'docker-pager-info', 'docker-pager-next')
]))->addClass('docker-toolbar');

$toolbar = new CDiv([
	SearchControl::bar(_('Filter containers...'), '', ['id' => 'docker-search',
		'aria-label' => _('Filter containers by name or note')]),
	$table_controls
]);

$makeSortHeader = static function (string $label, string $key): CSpan {
	return SortableHeader::render($label, $key, '', ZBX_SORT_UP, ['attribute' => 'data-sort']);
};

$container_clocks = [];
$container_delays = [];

foreach ($data['container_data_sources'] as $source) {
	$container_clocks = array_merge($container_clocks, $source['clocks']);
	$container_delays = array_merge($container_delays, $source['delays']);
}

$container_data_sources = (new CDiv([
	(new CSpan([
		_('Updated').': ',
		$container_clocks
			? zbx_date2str(DATE_TIME_FORMAT_SECONDS, min($container_clocks))
				.' ('.zbx_date2age(min($container_clocks)).')'
			: _('No value received')
	])),
	(new CSpan([_('Interval').': ', $container_delays
		? implode(', ', array_values(array_unique($container_delays)))
		: '-']))
]))->addClass('docker-data-freshness');

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
				$row['status_kind'], 'single', (float) ($data['node']['mem_total'] ?? 0)
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
	$container_data_sources,
	$toolbar,
	$table
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
	'hostid' => $data['filter']['hostid'],
	'groupids' => $data['filter']['groupids'],
	'timeline' => $data['timeline'],
	'preferences' => $data['table_preferences']
]).');'))
	->setOnDocumentReady()
	->show();

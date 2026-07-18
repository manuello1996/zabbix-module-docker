<?php declare(strict_types = 0);
/**
 * Docker Monitoring — Developed by MonZphere.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\MonzphereDocker\Includes\DockerFormatter;

$this->addJsFile('class.calendar.js');
$this->addJsFile('gtlc.js');

$this->includeJsFile('monzphere.docker.view.js.php');

$makeFooter = static function (): CDiv {
	return (new CDiv(_('Developed by MonZphere')))->addClass('mnz-docker-footer');
};

$makeStat = static function (string $modifier, string $label, string $value, string $unit): CDiv {
	return (new CDiv([
		(new CSpan())->addClass('mnz-docker-card-icon')->addClass('mnz-docker-icon-'.$modifier),
		(new CSpan($value))->addClass('mnz-docker-card-value'),
		(new CSpan($unit))->addClass('mnz-docker-card-unit')
	]))
		->addClass('mnz-docker-stat')
		->addClass('mnz-docker-card-'.$modifier)
		->setTitle($label);
};

$makeSparkline = static function (array $history, bool $is_running): CDiv {
	$width = 100;
	$height = 24;
	$pad = 3;

	$svg = (new CTag('svg', true))
		->setAttribute('viewBox', '0 0 '.$width.' '.$height)
		->setAttribute('preserveAspectRatio', 'none')
		->addClass('mnz-docker-spark-svg');

	if (!$is_running || count($history) < 2) {
		$y = $height - $pad - 2;

		$svg->addItem(
			(new CTag('line', true))
				->setAttribute('x1', 0)
				->setAttribute('y1', $y)
				->setAttribute('x2', $width)
				->setAttribute('y2', $y)
				->setAttribute('stroke', '#8b8b8b')
				->setAttribute('stroke-width', 1.5)
		);
	}
	else {
		if (count($history) > 60) {
			$step = (int) ceil(count($history) / 60);
			$history = array_values(array_filter($history,
				static fn ($i) => $i % $step === 0, ARRAY_FILTER_USE_KEY
			));
		}

		$values = array_map(static fn ($point) => (float) $point[1], $history);
		$min = min($values);
		$max = max($values);
		$range = $max - $min;
		$count = count($values);

		$points = [];

		foreach ($values as $index => $value) {
			$x = $count > 1 ? $index / ($count - 1) * $width : 0;
			$y = $range > 0
				? $height - $pad - (($value - $min) / $range) * ($height - 2 * $pad)
				: $height / 2;

			$points[] = round($x, 1).','.round($y, 1);
		}

		$svg
			->addItem(
				(new CTag('polygon', true))
					->setAttribute('points',
						'0,'.($height - 1).' '.implode(' ', $points).' '.$width.','.($height - 1)
					)
					->setAttribute('fill', '#59db8f')
					->setAttribute('fill-opacity', '0.12')
			)
			->addItem(
				(new CTag('polyline', true))
					->setAttribute('points', implode(' ', $points))
					->setAttribute('fill', 'none')
					->setAttribute('stroke', '#59db8f')
					->setAttribute('stroke-width', 1.5)
			);
	}

	return (new CDiv($svg))->addClass('mnz-docker-sparkline');
};

$html_page = (new CHtmlPage())
	->setTitle(_('Docker'))
	->setWebLayoutMode(CViewHelper::loadLayoutMode());

$html_page->addItem(
	(new CFilter())
		->setResetUrl(new CUrl('zabbix.php?action=monzphere.docker.view'))
		->setProfile($data['timeline']['profileIdx'], $data['timeline']['profileIdx2'])
		->setActiveTab($data['active_tab'])
		->addTimeSelector($data['timeline']['from'], $data['timeline']['to'], true,
			'web.monzphere.docker.filter'
		)
		->addVar('action', 'monzphere.docker.view')
);

$html_page->addItem(
	(new CForm('get'))
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
		)
);

if ($data['host'] === null) {
	$html_page
		->addItem(
			(new CTableInfo())->setNoDataMessage(
				$data['hosts']
					? _('Select a Docker node to view its containers.')
					: _('No hosts with Docker metrics found. Link the "Docker by Zabbix agent 2" template to a host.')
			)
		)
		->addItem($makeFooter())
		->show();

	return;
}

$html_page->addItem(
	(new CDiv([
		new CLink(_('Docker nodes'), (new CUrl('zabbix.php'))->setArgument('action', 'monzphere.docker.list')),
		(new CSpan('/'))->addClass('mnz-docker-breadcrumb-sep'),
		(new CSpan($data['host']['name']))->addClass('mnz-docker-breadcrumb-current')
	]))
		->addClass('mnz-docker-breadcrumb')
		->setAttribute('aria-label', _('Breadcrumb'))
);

$host_enabled = (int) $data['host']['status'] === HOST_STATUS_MONITORED;

$overview = DockerFormatter::formatOverview($data['overview']);

$memory_parts = explode(' ', $overview['memory_total'], 2);

$hostbar_stats = (new CDiv([
	$makeStat('total', _('Total containers'), $overview['total'], _('total')),
	$makeStat('running', _('Running'), $overview['running'], _('running')),
	$makeStat('stopped', _('Stopped'), $overview['stopped'], _('stopped')),
	$makeStat('cpu', _('Total CPU usage'), $overview['cpu_total'], '%'),
	$makeStat('memory', _('Total memory usage'), $memory_parts[0], $memory_parts[1] ?? '')
]))
	->addClass('mnz-docker-hostbar-stats')
	->setId('mnz-docker-cards');

$html_page->addItem(
	(new CDiv([
		(new CLink('←', (new CUrl('zabbix.php'))->setArgument('action', 'monzphere.docker.list')))
			->addClass('mnz-docker-hostbar-back')
			->setTitle(_('Back to Docker nodes')),
		(new CSpan($data['host']['name']))->addClass('mnz-docker-hostbar-name'),
		(new CSpan($host_enabled ? _('Enabled') : _('Disabled')))
			->addClass($host_enabled ? 'mnz-docker-hostbar-enabled' : 'mnz-docker-hostbar-disabled'),
		(new CHostAvailability())->setInterfaces($data['host']['interfaces']),
		$hostbar_stats
	]))->addClass('mnz-docker-hostbar')
);

$tabs = [
	['latest', _('Latest data')],
	['problems', _('Problems')],
	['graphs', _('Graphs')],
	['web', _('Web')],
	['inventory', _('Inventory')],
	['node', _('Node info')]
];

$tab_items = [];

foreach ($tabs as [$key, $label]) {
	$tab_items[] = (new CSpan($label))
		->addClass('mnz-docker-tab')
		->setAttribute('data-mnz-tab', $key)
		->setAttribute('role', 'tab')
		->setAttribute('tabindex', '0')
		->setAttribute('aria-selected', 'false');
}

$tab_items[] = (new CSpan(_('Containers')))
	->addClass('mnz-docker-tab')
	->addClass('mnz-docker-tab-active')
	->setAttribute('data-mnz-tab', '')
	->setAttribute('role', 'tab')
	->setAttribute('tabindex', '0')
	->setAttribute('aria-selected', 'true');

$html_page->addItem(
	(new CDiv($tab_items))
		->addClass('mnz-docker-tabs')
		->setAttribute('role', 'tablist')
);

$makeChip = static function (string $key, string $label, bool $active): CTag {
	return (new CTag('button', true, [
		new CSpan($label),
		(new CSpan())->addClass('mnz-docker-chip-count')
	]))
		->setAttribute('type', 'button')
		->addClass('mnz-docker-chip')
		->addClass($active ? 'mnz-docker-chip-active' : null)
		->setAttribute('data-mnz-chip', $key)
		->setAttribute('aria-pressed', $active ? 'true' : 'false');
};

$toolbar = (new CDiv([
	(new CTag('input', false))
		->setId('mnz-docker-search')
		->setAttribute('type', 'search')
		->setAttribute('placeholder', _('Filter by name...'))
		->setAttribute('aria-label', _('Filter containers by name'))
		->setAttribute('autocomplete', 'off')
		->addClass('mnz-docker-search'),
	(new CDiv([
		$makeChip('all', _('All'), true),
		$makeChip('running', _('Running'), false),
		$makeChip('stopped', _('Stopped'), false)
	]))
		->addClass('mnz-docker-chips')
		->setAttribute('role', 'group')
		->setAttribute('aria-label', _('Filter by status'))
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
		_('Status'),
		$makeSortHeader(_('CPU usage'), 'cpu'),
		$makeSortHeader(_('Memory usage'), 'memory'),
		_('Memory limit'),
		_('Net I/O (in)'),
		_('Net I/O (out)'),
		$makeSortHeader(_('Uptime'), 'uptime')
	])
	->setNoDataMessage(_('No container data collected yet.'));

foreach ($data['containers'] as $container) {
	$row = DockerFormatter::formatContainer($container);

	$status_class = $row['is_running'] ? 'mnz-docker-status-running' : 'mnz-docker-status-stopped';

	$table->addRow((new CRow([
		(new CDiv([
			(new CSpan())->addClass('mnz-docker-dot')->addClass($status_class),
			(new CSpan())->addClass('mnz-docker-container-icon'),
			(new CLinkAction($row['name']))
				->addClass('mnz-docker-name')
				->setAttribute('data-mnz-container', $row['name'])
		]))->addClass('mnz-docker-name-cell'),

		(new CDiv([
			(new CSpan())->addClass('mnz-docker-status-icon')->addClass($status_class),
			(new CSpan($row['status']))->addClass('mnz-docker-status')->addClass($status_class)
		]))->addClass('mnz-docker-status-cell'),

		(new CDiv([
			(new CSpan($row['cpu']))->addClass('mnz-docker-metric-value'),
			$makeSparkline($container['cpu_history'], $row['is_running'])
		]))->addClass('mnz-docker-metric-cell'),

		(new CDiv([
			(new CSpan($row['memory']))->addClass('mnz-docker-metric-value'),
			$makeSparkline($container['memory_history'], $row['is_running'])
		]))->addClass('mnz-docker-metric-cell'),

		$row['memory_limit'],
		$row['net_in'],
		$row['net_out'],
		$row['uptime']
	]))
		->setAttribute('data-mnz-name', mb_strtolower($row['name']))
		->setAttribute('data-mnz-status', $row['is_running'] ? 'running' : 'stopped')
		->setAttribute('data-mnz-cpu', (string) $row['cpu_raw'])
		->setAttribute('data-mnz-memory', (string) $row['memory_raw'])
		->setAttribute('data-mnz-uptime', (string) $row['uptime_raw'])
	);
}

$table_section = (new CDiv([
	(new CTag('h4', true, _('Container metrics (latest)')))->addClass('mnz-docker-section-title'),
	$toolbar,
	$table,
	$data['paging'] ?? null,

	(new CDiv())
		->setId('mnz-docker-table-stats')
		->addClass('mnz-docker-table-stats')
		->setAttribute('aria-live', 'polite')
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
		(new CDiv([$table_section, $makeFooter()]))->setId('mnz-docker-content')
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
	'refresh_interval' => $data['refresh_interval'],
	'active_tab' => $data['active_docker_tab'] ?? ''
]).');'))
	->setOnDocumentReady()
	->show();

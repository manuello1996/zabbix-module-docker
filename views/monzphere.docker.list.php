<?php declare(strict_types = 0);

use Modules\MonzphereDocker\Includes\DockerFormatter;

$this->includeJsFile('monzphere.docker.list.js.php', ['refresh_interval' => $data['refresh_interval']]);

$makeFooter = static function (): CDiv {
	return (new CDiv([
		(new CSpan(_('Developed by MonZphere'))),
		(new CSpan('|'))->addClass('mnz-docker-footer-sep'),
		(new CLink(_('Documentation'), 'https://github.com/Monzphere/zabbix-module-docker#readme'))
			->setTarget('_blank'),
		(new CLink(_('Changelog'), 'https://github.com/Monzphere/zabbix-module-docker/releases'))
			->setTarget('_blank'),
		(new CLink(_('Community'), 'https://monzphere.com'))
			->setTarget('_blank')
	]))->addClass('mnz-docker-footer');
};

$makeStatSegment = static function (string $modifier, string $label, string $value, string $unit): CDiv {
	return (new CDiv([
		(new CDiv($label))->addClass('mnz-docker-statseg-label'),
		(new CDiv([
			(new CSpan($value))->addClass('mnz-docker-card-value'),
			$unit !== '' ? (new CSpan($unit))->addClass('mnz-docker-card-unit') : null
		]))->addClass('mnz-docker-statseg-figure')
	]))
		->addClass('mnz-docker-statseg')
		->addClass('mnz-docker-card-'.$modifier);
};

$html_page = (new CHtmlPage())
	->setTitle(_('Docker nodes'))
	->setWebLayoutMode(CViewHelper::loadLayoutMode());

$html_page->addItem(
	(new CDiv(
		(new CDiv([
			(new CSpan(_('Nodes')))->addClass('mnz-docker-breadcrumb-current')
		]))
			->addClass('mnz-docker-breadcrumb')
			->setAttribute('aria-label', _('Breadcrumb'))
	))->addClass('mnz-docker-topbar')
);

$filter_form = (new CFormGrid())
	->addClass('mnz-docker-filter-row')
	->addItem([
		new CLabel(_('Host groups'), 'filter_groupids__ms'),
		new CFormField(
			(new CMultiSelect([
				'name' => 'filter_groupids[]',
				'object_name' => 'hostGroup',
				'data' => $data['filter']['groups'],
				'popup' => [
					'parameters' => [
						'srctbl' => 'host_groups',
						'srcfld1' => 'groupid',
						'dstfrm' => 'zbx_filter',
						'dstfld1' => 'filter_groupids_',
						'with_monitored_items' => true
					]
				]
			]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
		)
	])
	->addItem([
		new CLabel(_('Name'), 'filter_name'),
		new CFormField(
			(new CTextBox('filter_name', $data['filter']['name']))
				->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
				->setAttribute('placeholder', _('Node name'))
		)
	]);

$html_page->addItem(
	(new CFilter())
		->setResetUrl(new CUrl('zabbix.php?action=monzphere.docker.list'))
		->setProfile('web.monzphere.docker.list.filter')
		->addVar('action', 'monzphere.docker.list')
		->addFilterTab(_('Filter'), [$filter_form])
);

$html_page->addItem(
	(new CDiv([
		(new CTag('h4', true, _('Docker environment overview')))->addClass('mnz-docker-section-title'),
		(new CDiv([
			$makeStatSegment('nodes', _('Nodes'), (string) $data['totals']['nodes'], ''),
			$makeStatSegment('total', _('Containers'), (string) $data['totals']['total'], ''),
			$makeStatSegment('running', _('Running'), (string) $data['totals']['running'], ''),
			$makeStatSegment('stopped', _('Stopped / Err'), (string) $data['totals']['stopped'], '')
		]))
			->addClass('mnz-docker-statstrip')
			->setId('mnz-docker-list-cards')
	]))->addClass('mnz-docker-section')
);

$table = (new CTableInfo())
	->setId('mnz-docker-nodes-table')
	->setHeader([
		_('Node'),
		_('Availability'),
		_('Problems'),
		_('Docker version'),
		_('Containers'),
		_('Running'),
		_('Stopped'),
		_('Paused'),
		_('Host memory')
	])
	->setNoDataMessage(_('No Docker nodes found. Link the "Docker by Zabbix agent 2" template to your hosts.'));

foreach ($data['nodes'] as $node) {
	$metrics = $node['metrics'];
	$problems = $node['problems'] ?? ['by_severity' => []];

	$problem_badges = [];

	foreach ($problems['by_severity'] as $severity => $count) {
		$problem_badges[] = (new CSpan($count))
			->addClass(ZBX_STYLE_PROBLEM_ICON_LIST_ITEM)
			->addClass(CSeverityHelper::getStatusStyle((int) $severity))
			->setTitle(CSeverityHelper::getName((int) $severity));
	}

	$detail_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'monzphere.docker.view')
		->setArgument('filter_hostid', [$node['hostid']])
		->setArgument('filter_set', '1');

	$table->addRow([
		(new CDiv([
			(new CSpan())->addClass('mnz-docker-container-icon'),
			(new CLink($node['name'], $detail_url))->addClass('mnz-docker-name')
		]))->addClass('mnz-docker-name-cell'),

		(new CHostAvailability())->setInterfaces($node['interfaces']),

		$problem_badges
			? (new CLink($problem_badges, $detail_url))->addClass(ZBX_STYLE_PROBLEM_ICON_LINK)
			: (new CSpan('-'))->addClass('mnz-docker-muted'),

		$metrics['version'] !== null ? $metrics['version'] : '-',

		$metrics['total'] !== null ? (string) (int) $metrics['total'] : '-',

		(new CSpan($metrics['running'] !== null ? (string) (int) $metrics['running'] : '-'))
			->addClass((int) $metrics['running'] > 0 ? 'mnz-docker-status-running' : null),

		(new CSpan($metrics['stopped'] !== null ? (string) (int) $metrics['stopped'] : '-'))
			->addClass((int) $metrics['stopped'] > 0 ? 'mnz-docker-status-stopped' : null),

		$metrics['paused'] !== null ? (string) (int) $metrics['paused'] : '-',

		$metrics['memory'] !== null ? DockerFormatter::bytes($metrics['memory']) : '-'
	]);
}

$html_page
	->addItem(
		(new CDiv([

			(new CTag('h4', true, [
				_('Docker nodes'),
				(new CSpan())
					->setId('mnz-docker-list-refresh-status')
					->addClass('mnz-docker-refresh-status')
					->setAttribute('aria-live', 'polite')
			]))->addClass('mnz-docker-section-title'),
			$table,

			$data['paging']
		]))->addClass('mnz-docker-section')
	)
	->addItem($makeFooter())
	->show();

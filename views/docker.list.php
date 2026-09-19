<?php declare(strict_types = 0);

$this->addJsFile('class.tagfilteritem.js');
$this->includeJsFile('docker.list.js.php', ['refresh_interval' => $data['refresh_interval']]);

$makeStatSegment = static function (string $modifier, string $label, string $value, string $unit): CDiv {
	return (new CDiv([
		(new CDiv($label))->addClass('docker-statseg-label'),
		(new CDiv([
			(new CSpan($value))->addClass('docker-card-value'),
			$unit !== '' ? (new CSpan($unit))->addClass('docker-card-unit') : null
		]))->addClass('docker-statseg-figure')
	]))
		->addClass('docker-statseg')
		->addClass('docker-card-'.$modifier);
};

$html_page = (new CHtmlPage())
	->setTitle(_('Docker nodes'))
	->setWebLayoutMode(CViewHelper::loadLayoutMode());

$html_page->addItem(
	(new CDiv([
		(new CDiv([
			(new CSpan(_('Nodes')))->addClass('docker-breadcrumb-current')
		]))
			->addClass('docker-breadcrumb')
			->setAttribute('aria-label', _('Breadcrumb')),
		(new CDiv([
			(new CRedirectButton(
				_('Export images older than 365 days (CSV)'),
				(new CUrl('zabbix.php'))->setArgument('action', 'docker.images.csv')
			))->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('docker-topbar-actions')
	]))->addClass('docker-topbar')
);

$filter_left = (new CFormGrid())
	->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
	->addItem([
		new CLabel(_('Name or notes'), 'filter_name'),
		new CFormField(
			(new CTextBox('filter_name', $data['filter']['name']))
				->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
		)
	])
	->addItem([
		new CLabel(_('Host groups'), 'filter_groupids__ms'),
		new CFormField(
			(new CMultiSelect([
				'multiple' => true,
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
		new CLabel(_('Container state')),
		new CFormField(
			(new CCheckBoxList('filter_container_states'))
				->setOptions([
					['label' => _('Has stopped containers'), 'value' => 'stopped'],
					['label' => _('Has paused containers'), 'value' => 'paused'],
					['label' => _('Has unhealthy containers'), 'value' => 'unhealthy'],
					['label' => _('No running containers'), 'value' => 'no_running']
				])
				->setChecked($data['filter']['container_states'])
				->setColumns(2)
				->setVertical()
		)
	]);

$filter_right = (new CFormGrid())
	->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
	->addItem([
		new CLabel(_('Host tags')),
		new CFormField(
			CTagFilterFieldHelper::getTagFilterField([
				'evaltype' => $data['filter']['tag_evaltype'],
				'tags' => $data['filter']['tags'] ?: [
					['tag' => '', 'operator' => TAG_OPERATOR_LIKE, 'value' => '']
				]
			], [
				'evaltype_field_name' => 'filter_tag_evaltype'
			])
		)
	])
	->addItem([
		new CLabel(_('Problems'), 'filter_problems'),
		new CFormField(
			(new CCheckBox('filter_problems', 1))
				->setLabel(_('Show only hosts with problems'))
				->setChecked((int) $data['filter']['problems'] === 1)
				->setUncheckedValue(0)
		)
	])
	->addItem([
		new CLabel(_('Docker template'), 'filter_docker_problems'),
		new CFormField(
			(new CCheckBox('filter_docker_problems', 1))
				->setLabel(_('Show only hosts with Docker template problems'))
				->setChecked((int) $data['filter']['docker_problems'] === 1)
				->setUncheckedValue(0)
		)
	]);

$html_page->addItem(
	(new CFilter())
		->setResetUrl(new CUrl('zabbix.php?action=docker.list'))
		->setProfile('web.docker.list.filter')
		->setActiveTab($data['active_tab'])
		->addVar('action', 'docker.list')
		->addFilterTab(_('Filter'), [$filter_left, $filter_right])
);

$html_page->addItem(
	(new CDiv([
		(new CDiv([
			$makeStatSegment('nodes', _('Nodes'), (string) $data['totals']['nodes'], ''),
			$makeStatSegment('total', _('Containers'), (string) $data['totals']['total'], ''),
			$makeStatSegment('running', _('Running'), (string) $data['totals']['running'], ''),
			$makeStatSegment('stopped', _('Stopped / Err'), (string) $data['totals']['stopped'], '')
		]))
			->addClass('docker-statstrip')
			->setId('docker-list-cards')
	]))->addClass('docker-section')
);

$no_data_message = _('No Docker nodes found. Link the "Docker by Zabbix agent 2" template to your hosts.');

if ($data['filter']['docker_problems']) {
	$no_data_message = _('No Docker hosts with Docker template problems found.');
}
elseif ($data['filter']['problems']) {
	$no_data_message = _('No Docker hosts with problems found.');
}
elseif ($data['filter']['name'] !== '' || $data['filter']['groupids']
		|| $data['filter']['container_states'] || $data['filter']['tags']) {
	$no_data_message = _('No Docker hosts match the selected filters.');
}

$table = (new CTableInfo())
	->setId('docker-nodes-table')
	->setHeader([
		_('Node'),
		_('Notes'),
		_('Availability'),
		_('Problems'),
		_('Containers'),
		_('Running'),
		_('Stopped'),
		_('Paused'),
		_('Tags')
	])
	->setNoDataMessage($no_data_message);

foreach ($data['nodes'] as $node) {
	$metrics = $node['metrics'];

	$detail_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'docker.view')
		->setArgument('filter_hostid', [$node['hostid']])
		->setArgument('filter_set', '1');

	$table->addRow([
		(new CDiv([
			(new CSpan())->addClass('docker-container-icon'),
			(new CLink($node['name'], $detail_url))->addClass('docker-name')
		]))->addClass('docker-name-cell'),

		$node['inventory']['notes'] ?? '',

		(new CHostAvailability())->setInterfaces($node['interfaces']),

		(new CLink(
			(new CSpan('…'))->addClass('docker-muted'),
			$detail_url
		))
			->addClass(ZBX_STYLE_PROBLEM_ICON_LINK)
			->setAttribute('data-problem-hostid', $node['hostid'])
			->setAttribute('aria-label', _('Loading problems')),

		$metrics['total'] !== null ? (string) (int) $metrics['total'] : '-',

		(new CSpan($metrics['running'] !== null ? (string) (int) $metrics['running'] : '-'))
			->addClass((int) $metrics['running'] > 0 ? 'docker-status-running' : null),

		(new CSpan($metrics['stopped'] !== null ? (string) (int) $metrics['stopped'] : '-'))
			->addClass((int) $metrics['stopped'] > 0 ? 'docker-status-stopped' : null),

		$metrics['paused'] !== null ? (string) (int) $metrics['paused'] : '-',

		$node['formatted_tags'] ?: '-'
	]);
}

$html_page
	->addItem(
		(new CDiv([

			(new CTag('h4', true, [
				_('Docker nodes'),
				(new CSpan())
					->setId('docker-list-refresh-status')
					->addClass('docker-refresh-status')
					->setAttribute('aria-live', 'polite')
			]))->addClass('docker-section-title'),
			$table,

			$data['paging']->setId('docker-list-paging')
		]))->addClass('docker-section')
	)
	->show();

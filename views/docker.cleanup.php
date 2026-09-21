<?php declare(strict_types = 0);

use Modules\MonitorDocker\Includes\DockerFormatter;

$this->addJsFile('class.tagfilteritem.js');

$make_stat = static function (string $label, $value): CDiv {
	return (new CDiv([
		(new CSpan($label))->addClass('docker-card-unit'),
		(new CSpan((string) $value))->addClass('docker-card-value')
	]))->addClass('docker-stat');
};

$html_page = (new CHtmlPage())
	->setTitle(_('Docker Cleanup'))
	->setControls(
		(new CTag('nav', true,
			(new CList())->addItem(
				(new CRedirectButton(
					_('Docker nodes'),
					(new CUrl('zabbix.php'))->setArgument('action', 'docker.list')
				))->addClass(ZBX_STYLE_BTN_ALT)
			)
		))->setAttribute('aria-label', _('Content controls'))
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
	]);

$html_page->addItem(
	(new CFilter())
		->setResetUrl(new CUrl('zabbix.php?action=docker.cleanup'))
		->setProfile('web.docker.cleanup.filter')
		->setActiveTab($data['active_tab'])
		->addVar('action', 'docker.cleanup')
		->addFilterTab(_('Filter'), [$filter_left, $filter_right])
);

$html_page->addItem(
	(new CDiv([
		$make_stat(_('Hosts with candidates'), $data['totals']['hosts']),
		$make_stat(_('Unused images'), $data['totals']['hosts_with_image_data'] > 0
			? $data['totals']['images']
			: '—'),
		$make_stat(_('Stopped containers'), $data['totals']['containers']),
		$make_stat(_('Unused volumes'), $data['totals']['volumes']),
		$make_stat(_('Potentially reclaimable'), DockerFormatter::bytes($data['totals']['bytes']))
	]))->addClass('docker-hostbar-stats')->addClass('docker-node-stats')
);

$html_page->addItem(
	(new CDiv(sprintf(
		_('Read-only candidates from retained Docker items. Data is available for %1$s of %2$s Docker hosts. Stopped-container counts use the retained host metric. Unused-image detection follows the Images tab for %3$s hosts with complete container-to-image matching.'),
		$data['totals']['hosts_with_data'],
		$data['hosts_scanned'],
		$data['totals']['hosts_with_image_data']
	)))->addClass('docker-cleanup-note')
);

$sort_url = (new CUrl('zabbix.php'))->setArgument('action', 'docker.cleanup');

$table = (new CTableInfo())
	->setHeader([
		make_sorting_header(_('Docker host'), 'name', $data['sort'], $data['sortorder'], $sort_url->getUrl()),
		make_sorting_header(_('Notes'), 'notes', $data['sort'], $data['sortorder'], $sort_url->getUrl()),
		make_sorting_header(_('Unused images'), 'images', $data['sort'], $data['sortorder'], $sort_url->getUrl()),
		make_sorting_header(_('Stopped containers'), 'containers', $data['sort'], $data['sortorder'], $sort_url->getUrl()),
		make_sorting_header(_('Unused volumes'), 'volumes', $data['sort'], $data['sortorder'], $sort_url->getUrl()),
		make_sorting_header(_('Potentially reclaimable'), 'bytes', $data['sort'], $data['sortorder'], $sort_url->getUrl()),
		make_sorting_header(_('Updated'), 'lastclock', $data['sort'], $data['sortorder'], $sort_url->getUrl())
	])
	->setNoDataMessage(_('No cleanup candidates found in the retained Docker data. A host without retained image, container-state or volume data is not treated as clean.'));

foreach ($data['hosts'] as $host) {
	$detail_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'docker.view')
		->setArgument('filter_hostid', [$host['hostid']])
		->setArgument('filter_set', '1');

	$table->addRow([
		(new CLink($host['name'], $detail_url))->addClass('docker-name'),
		$host['inventory']['notes'] ?? '',
		$host['image_data_available'] ? $host['images'] : '—',
		$host['containers'],
		$host['volumes'],
		DockerFormatter::bytes($host['bytes']),
		$host['lastclock'] > 0
			? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $host['lastclock']).' ('.zbx_date2age($host['lastclock']).')'
			: '-'
	]);
}

$html_page
	->addItem((new CDiv([
		(new CTag('h4', true, _('Docker Cleanup')))->addClass('docker-section-title'),
		$table,
		$data['paging']
	]))->addClass('docker-section'))
	->show();

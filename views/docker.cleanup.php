<?php declare(strict_types = 0);

use Modules\MonitorDocker\Includes\DockerFormatter;

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

$html_page->addItem(
	(new CDiv([
		$make_stat(_('Hosts with candidates'), $data['totals']['hosts']),
		$make_stat(_('Dangling images'), $data['totals']['hosts_with_image_data'] > 0
			? $data['totals']['images']
			: '—'),
		$make_stat(_('Stopped containers'), $data['totals']['containers']),
		$make_stat(_('Unused volumes'), $data['totals']['volumes']),
		$make_stat(_('Potentially reclaimable'), DockerFormatter::bytes($data['totals']['bytes']))
	]))->addClass('docker-hostbar-stats')->addClass('docker-node-stats')
);

$html_page->addItem(
	(new CDiv(sprintf(
		_('Read-only candidates from retained Docker items. Data is available for %1$s of %2$s Docker hosts. Exact dangling-image detection is available for %3$s hosts because the stock template does not retain the raw image inventory.'),
		$data['totals']['hosts_with_data'],
		$data['hosts_scanned'],
		$data['totals']['hosts_with_image_data']
	)))->addClass('docker-cleanup-note')
);

$table = (new CTableInfo())
	->setHeader([
		_('Docker host'),
		_('Dangling images'),
		_('Stopped containers'),
		_('Unused volumes'),
		_('Potentially reclaimable'),
		_('Updated')
	])
	->setNoDataMessage(_('No cleanup candidates found in the retained Docker data. A host without retained image, container-state or volume data is not treated as clean.'));

foreach ($data['hosts'] as $host) {
	$detail_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'docker.view')
		->setArgument('filter_hostid', [$host['hostid']])
		->setArgument('filter_set', '1');

	$table->addRow([
		(new CLink($host['name'], $detail_url))->addClass('docker-name'),
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

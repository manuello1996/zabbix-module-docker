<?php declare(strict_types = 0);

// Generated from zabbix-module-common. Columns and statistics belong to the module.
namespace Modules\MonitorDocker\Includes\Common;

class LocationRows {
	public static function header(array $group, string $count, array $columns, int $label_span = 1,
			array $groups = []): \CRow {
		$button = (new \CButton(null, [
			(new \CSpan())->addClass('docker-location-chevron')->setAttribute('aria-hidden', 'true'),
			(new \CSpan($group['label']))->addClass('docker-location-name')
		]))->addClass('docker-location-toggle')
			->setAttribute('data-docker-location-toggle', $group['key'])
			->setAttribute('aria-expanded', ($group['collapsed'] ?? false) ? 'false' : 'true');
		$label = (new \CDiv([$button, (new \CSpan($count))->addClass('docker-location-count')]))
			->addClass('docker-location-group')
			->setAttribute('style', '--docker-location-depth: '.(int) $group['depth']);
		$row = (new \CRow(array_merge([(new \CCol($label))->setColSpan($label_span)], $columns)))
			->addClass('docker-location-row')
			->setAttribute('data-docker-location-key', $group['key']);
		return self::withAncestors($row, $group['ancestors'], $groups);
	}

	public static function withAncestors(\CRow $row, array $ancestors, array $groups = []): \CRow {
		$row->setAttribute('data-docker-location-ancestors', json_encode($ancestors));
		foreach ($ancestors as $key) {
			if ($groups[$key]['collapsed'] ?? false) {
				$row->setAttribute('hidden', 'hidden');
				break;
			}
		}
		return $row;
	}
}

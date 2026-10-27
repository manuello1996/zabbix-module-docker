<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Includes;

use Modules\MonitorDocker\Includes\Common\ItemActions;

require_once __DIR__.'/common/bootstrap.php';

/** Docker only owns the return route; Zabbix owns the menu and permissions. */
class DockerItemMenu {
	public static function value($label, array $metrics, string $field) {
		return self::link($label, $metrics[$field.'_itemid'] ?? null);
	}

	public static function link($label, ?string $itemid) {
		$url = (new \CUrl('zabbix.php'))->formatGetArguments();
		parse_str(parse_url($url->getUrl(), PHP_URL_QUERY) ?? '', $args);
		if (in_array($args['action'] ?? '', ['docker.tab', 'docker.container'], true)) {
			$url = (new \CUrl('zabbix.php'))->setArgument('action', 'docker.view')
				->setArgument('filter_hostid', [(string) ($args['hostid'] ?? '')])->setArgument('filter_set', 1);
			if (isset($args['tab'])) { $url->setArgument('tab', $args['tab']); }
			if (isset($args['name'])) { $url->setArgument('container', $args['name']); }
		}
		return ItemActions::link($label, $itemid, $url->getUrl());
	}
}

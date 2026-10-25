<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Includes\Common;

class Sparkline {
	public static function holder(array $attributes = [], string $kind = ''): \CDiv {
		$holder = (new \CDiv())->addClass('docker-sparkline')->setAttribute('aria-label', _('Loading history'));
		if ($kind !== '') { $holder->addClass('docker-spark-'.$kind); }
		foreach ($attributes as $name => $value) { $holder->setAttribute($name, $value); }
		return $holder;
	}
}

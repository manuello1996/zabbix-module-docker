<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Includes;

use API;

class DockerImageAge {
	public const MACRO = '{$DOKCER.IMAGE.OLD}';

	public static function get(): array {
		$macros = API::UserMacro()->get([
			'output' => ['value', 'type'],
			'globalmacro' => true,
			'filter' => ['macro' => self::MACRO],
			'limit' => 1
		]);
		$macro = $macros ? reset($macros) : null;
		$value = $macro !== null && (int) $macro['type'] === ZBX_MACRO_TYPE_TEXT
			? trim((string) $macro['value']) : '';
		$seconds = $value !== '' ? timeUnitToSeconds($value, true) : null;

		if ($seconds === null || $seconds <= 0 || $seconds >= PHP_INT_MAX) {
			$seconds = 365 * SEC_PER_DAY;
			$value = '365d';
		}

		$seconds = (int) $seconds;
		$whole_days = $seconds % SEC_PER_DAY === 0;

		return [
			'seconds' => $seconds,
			'label' => $whole_days ? sprintf(_('%1$s days'), (string) ($seconds / SEC_PER_DAY)) : $value,
			'filename' => $whole_days ? (string) ($seconds / SEC_PER_DAY).'_days' : $value
		];
	}
}

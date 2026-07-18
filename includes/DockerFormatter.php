<?php declare(strict_types = 0);

namespace Modules\MonzphereDocker\Includes;

class DockerFormatter {
	public static function formatOverview(array $overview): array {
		return [
			'total' => $overview['total'] !== null ? (string) $overview['total'] : self::noData(),
			'running' => $overview['running'] !== null ? (string) $overview['running'] : self::noData(),
			'stopped' => $overview['stopped'] !== null ? (string) $overview['stopped'] : self::noData(),
			'cpu_total' => sprintf('%.2f', $overview['cpu_total']),
			'memory_total' => self::bytes($overview['memory_total'])
		];
	}

	public static function formatContainer(array $container): array {
		return [
			'name' => $container['name'],
			'status' => $container['status'] !== null ? $container['status'] : self::noData(),
			'is_running' => $container['is_running'],
			'cpu' => $container['is_running'] && $container['cpu'] !== null
				? sprintf('%.2f %%', (float) $container['cpu'])
				: '0 %',
			'memory' => $container['is_running'] && $container['memory'] !== null
				? self::bytes((float) $container['memory'])
				: '0 B',
			'memory_limit' => $container['memory_limit'] !== null && (float) $container['memory_limit'] > 0
				? self::bytes((float) $container['memory_limit'])
				: self::noData(),
			'net_in' => self::rate($container['is_running'] ? $container['net_in'] : 0),
			'net_out' => self::rate($container['is_running'] ? $container['net_out'] : 0),
			'uptime' => $container['uptime'] !== null
				? convertUnitsS($container['uptime'], ['ignore_milliseconds' => true])
				: self::noData(),

			'cpu_raw' => $container['is_running'] ? (float) $container['cpu'] : 0.0,
			'memory_raw' => $container['is_running'] ? (float) $container['memory'] : 0.0,
			'uptime_raw' => $container['uptime'] ?? -1
		];
	}

	public static function bytes($value): string {
		return convertUnits(['value' => (float) $value, 'units' => 'B']);
	}

	public static function rate($value): string {
		return self::bytes($value).'/s';
	}

	public static function noData(): string {
		return '-';
	}
}

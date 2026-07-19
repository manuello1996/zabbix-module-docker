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

	public static function formatContainer(array $container, $node_mem_total = null): array {
		[$status_text, $status_kind] = self::containerState($container);

		$memory_pct = null;

		$memory_base = ($container['memory_limit'] !== null && (float) $container['memory_limit'] > 0)
			? (float) $container['memory_limit']
			: (float) ($node_mem_total ?? 0);

		if ($container['is_running'] && $container['memory'] !== null && $memory_base > 0) {
			$memory_pct = (int) round(min(100, (float) $container['memory'] / $memory_base * 100));
		}

		return [
			'name' => $container['name'],
			'status' => $container['status'] !== null ? $container['status'] : self::noData(),
			'status_text' => $status_text,
			'status_kind' => $status_kind,
			'memory_pct' => $memory_pct,
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
				? self::shortDuration($container['uptime'])
				: self::noData(),

			'cpu_raw' => $container['is_running'] ? (float) $container['cpu'] : 0.0,
			'memory_raw' => $container['is_running'] ? (float) $container['memory'] : 0.0,
			'uptime_raw' => $container['uptime'] ?? -1
		];
	}

	private static function containerState(array $container): array {
		switch ($container['status']) {
			case 'running':
				return [_('Up').' '.self::shortDuration($container['uptime'] ?? 0), 'up'];

			case 'restarting':
				return [_('Restarting').' ('.(int) ($container['restarts'] ?? 0).')', 'restarting'];

			case 'exited':
				return [_('Exited').' ('.(int) ($container['exitcode'] ?? 0).')', 'down'];

			case 'paused':
				return [_('Paused'), 'off'];

			case null:
				return [self::noData(), 'off'];

			default:
				return [ucfirst($container['status']), 'off'];
		}
	}

	public static function shortDuration($seconds): string {
		$parts = explode(' ', convertUnitsS(max(0, (int) $seconds), ['ignore_milliseconds' => true]));

		return implode(' ', array_slice($parts, 0, 2));
	}

	public static function bytes($value): string {
		return convertUnits(['value' => (float) $value, 'units' => 'B']);
	}

	public static function rate($value): string {
		$value = max(0.0, (float) $value);

		foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
			if ($value < 1024 || $unit === 'GB') {
				return ($unit === 'B' ? (string) round($value) : sprintf('%.1f', $value)).' '.$unit.'/s';
			}

			$value /= 1024;
		}

		return '-';
	}

	public static function noData(): string {
		return '-';
	}
}

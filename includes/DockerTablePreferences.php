<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Includes;

use CProfile;

class DockerTablePreferences {
	public const PROFILE = 'web.docker.table.preferences';
	public const PAGE_SIZES = [10, 25, 50, 100];
	public const COLUMNS = ['name', 'note', 'uptime', 'health', 'cpu', 'memory', 'network'];

	public static function defaults(): array {
		return ['page_size' => 25, 'sort' => 'name', 'dir' => 1, 'columns' => self::COLUMNS];
	}

	public static function isValid(array $preferences): bool {
		return in_array($preferences['page_size'] ?? null, self::PAGE_SIZES, true)
			&& in_array($preferences['sort'] ?? null, ['name', 'cpu', 'memory'], true)
			&& in_array($preferences['dir'] ?? null, [-1, 1], true)
			&& is_array($preferences['columns'] ?? null)
			&& count(array_filter($preferences['columns'], 'is_string')) === count($preferences['columns'])
			&& in_array('name', $preferences['columns'], true)
			&& count(array_unique($preferences['columns'], SORT_REGULAR)) === count($preferences['columns'])
			&& !array_diff($preferences['columns'], self::COLUMNS);
	}

	public static function get(): array {
		$preferences = json_decode((string) CProfile::get(self::PROFILE, ''), true);

		return is_array($preferences) && self::isValid($preferences) ? $preferences : self::defaults();
	}

	public static function save(array $preferences): void {
		CProfile::update(self::PROFILE, json_encode($preferences), PROFILE_TYPE_STR);
	}
}

<?php declare(strict_types = 0);

namespace Modules\MonitorDocker\Includes\Common;

/** Shared presentation; labels, values and source-item links belong to the caller. */
class Summary {
	public static function card(string $label, $value, array $options = []): \CDiv {
		$figure = (new \CDiv($value))->addClass('docker-card-value');
		if (isset($options['value_id'])) { $figure->setId($options['value_id']); }
		$content = [$figure];
		if (($options['unit'] ?? '') !== '') {
			$content[] = (new \CSpan($options['unit']))->addClass('docker-card-unit');
		}
		if (isset($options['aside'])) {
			$content[] = (new \CDiv($options['aside']))->addClass('docker-statseg-aside');
		}
		$card = (new \CDiv([
			(new \CDiv($label))->addClass('docker-statseg-label'),
			(new \CDiv($content))->addClass('docker-statseg-figure')
		]))->addClass('docker-statseg')->addClass('docker-card-'.($options['modifier'] ?? 'total'));
		foreach ($options['attributes'] ?? [] as $name => $value) { $card->setAttribute($name, $value); }
		return $card;
	}

	public static function strip(array $cards, array $attributes = []): \CDiv {
		$strip = (new \CDiv($cards))->addClass('docker-statstrip');
		foreach ($attributes as $name => $value) { $strip->setAttribute($name, $value); }
		return $strip;
	}
}

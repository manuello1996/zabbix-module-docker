<?php declare(strict_types = 0);

// Generated from zabbix-module-common. Routing and filtering stay with the caller.
namespace Modules\MonitorDocker\Includes\Common;

class SearchControl {
	public static function input(string $placeholder, string $value = '', array $attributes = []): \CTag {
		$input = new \CTag('input', false);
		foreach ($attributes + ['class' => 'docker-search', 'type' => 'search', 'value' => $value, 'placeholder' => $placeholder,
			'aria-label' => $placeholder, 'autocomplete' => 'off'] as $name => $content) {
			$input->setAttribute($name, $content);
		}
		return $input;
	}

	/** The NetApp tab-search layout is the standard UI for every module. */
	public static function bar(string $hint, string $value = '', array $attributes = [], array $options = []): \CDiv {
		$id = $attributes['id'] ?? 'docker-tab-search';
		$attributes = ['class' => 'docker-tab-search-input', 'type' => 'text',
			'aria-describedby' => $id.'-hint'] + $attributes;
		$input = self::input($hint, $value, $attributes + [
			'id' => $id, 'maxlength' => 255
		]);
		$clear = (new \CTag('button', true, _('Clear')))->setAttribute('type', 'button')
			->setAttribute('data-module-search-clear', '1')->setAttribute('title', _('Clear search (Escape)'))
			->setAttribute('aria-controls', $id)->addClass(ZBX_STYLE_BTN_ALT);
		foreach ($options['clear_attributes'] ?? [] as $name => $content) { $clear->setAttribute($name, $content); }
		$status = (new \CSpan($options['message'] ?? ''))->setAttribute('data-module-search-status', '1')
			->setAttribute('role', 'status')->setAttribute('data-searching', _('Searching…'))
			->setAttribute('data-error', _('Search could not be updated. Results still show the previous search. Please try again.'));
		foreach ($options['status_attributes'] ?? [] as $name => $content) { $status->setAttribute($name, $content); }
		return (new \CDiv(array_merge([
			new \CLabel($options['label'] ?? _('Search this tab'), $id), $input, $clear,
			(new \CSpan($hint))->setId($id.'-hint')->addClass('docker-tab-search-hint'), $status
		], $options['extra'] ?? [])))->addClass('docker-tab-search')->setAttribute('data-module-search', '1');
	}
}

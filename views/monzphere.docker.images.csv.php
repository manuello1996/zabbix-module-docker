<?php declare(strict_types = 0);

/**
 * @var array $data
 */

$csv = [[_('Team'), _('Host'), _('Notes'), _('Image'), _('Date')]];

foreach ($data['rows'] as $row) {
	$csv[] = [$row['team'], $row['host'], $row['notes'], $row['image'], $row['date']];
}

echo zbx_toCSV($csv);

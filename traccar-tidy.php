#!/usr/bin/php
<?php
require __DIR__ . '/vendor/autoload.php';
require 'config.php';

/**
 * Calculates the great-circle distance between two points, with
 * the Haversine formula.
 * @param float $latitudeFrom Latitude of start point in [deg decimal]
 * @param float $longitudeFrom Longitude of start point in [deg decimal]
 * @param float $latitudeTo Latitude of target point in [deg decimal]
 * @param float $longitudeTo Longitude of target point in [deg decimal]
 * @param float $earthRadius Mean earth radius in [m]
 * @return float Distance between points in [m] (same as earthRadius)
 */
function latLongDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000)
{
  // convert from degrees to radians
  $latFrom = deg2rad($latitudeFrom);
  $lonFrom = deg2rad($longitudeFrom);
  $latTo = deg2rad($latitudeTo);
  $lonTo = deg2rad($longitudeTo);

  $latDelta = $latTo - $latFrom;
  $lonDelta = $lonTo - $lonFrom;

  $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
    cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
  return $angle * $earthRadius;
}

function comparePositions($a, $b) {
	$fields = ['speed'];

	foreach ($fields as $field) {
		if ($a[$field] !== $b[$field]) {
			return false; /* different */
		}
	}

	if (latLongDistance($a['latitude'], $b['longitude'], $a['latitude'], $b['longitude']) > 10 /* meters */) {
		return false;
	}

	//var_dump($a);
	//var_dump($b);
	return true; /* same */
}


dibi::connect($db);
echo "DB connected\n";


$positions = dibi::query('SELECT * FROM positions WHERE servertime > NOW() - INTERVAL 1 DAY ORDER BY deviceId, id');

$deleteId = [];
$lastPosition = NULL;
$lastDeviceId = NULL;
foreach ($positions as $position) {
	if ($lastDeviceId !== $position['deviceid']) {
		$lastDeviceId = $position['deviceid'];
		$lastPosition = $position;
		continue;
	}

	if (comparePositions($lastPosition, $position)) {
		/* same - delete */
		$deleteId[] = $position['id'];
	}
	$lastPosition = $position;
}

echo "Delete " . count($deleteId) . " entries.\n";
//var_dump($deleteId);

if (count($deleteId) == 0) {
    return;
}

//$count = dibi::select('count(*)')->from('positions')->where('id in %l', $deleteId)->fetchAll();
//var_dump($count);

dibi::delete('positions')->where('id in %l', $deleteId)->execute();


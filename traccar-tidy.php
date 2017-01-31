#!/usr/bin/php
<?php
require __DIR__ . '/vendor/autoload.php';
require 'config.php';

define('FILTER_SPEED', 5);
define('FILTER_METERS', 10);

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

	$latDelta = abs($latTo - $latFrom);
	$lonDelta = abs($lonTo - $lonFrom);

	$angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
		cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
	return $angle * $earthRadius;
}

function comparePositions($a, $b) {
	if ($a['speed'] !== $b['speed']) {
		return false; /* keep */
	}

	$diff = latLongDistance(floatval($a['latitude']), floatval($a['longitude']), floatval($b['latitude']), floatval($b['longitude']));
	if ($diff > FILTER_METERS) {
		return false; /* keep */
	}

	return true; /* delete */
}


dibi::connect($db);
echo "DB connected\n";

$positions = dibi::query('SELECT * FROM positions WHERE devicetime > NOW() - INTERVAL 1 DAY ORDER BY deviceId, id');

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
	} else {
		$lastPosition = $position;
	}
}

echo "Delete " . count($deleteId) . " entries.\n";
//var_dump($deleteId); die;

if (count($deleteId)) {
	dibi::delete('positions')->where('id in %l', $deleteId)->execute();
}


$curl     = new \Ivory\HttpAdapter\CurlHttpAdapter();
$geocoder = new \Geocoder\Provider\GoogleMaps($curl, 'cs', NULL, true, $googleApiKey);

$positions = dibi::query('SELECT * FROM positions WHERE address IS NULL and speed < 20');
foreach ($positions as $position) {
	$ret = $geocoder->reverse($position['latitude'], $position['longitude']);
	$f = $ret->first();

	$adr = [];
	if ($f->getStreetName() && preg_match('/.*[a-zA-Z]+.*$/',$f->getStreetName())) {
		if ($f->getStreetNumber()) {
			$adr[] = $f->getStreetName() .' ' . $f->getStreetNumber();
		} else {
			$adr[] = $f->getStreetName();
		}
	}

	if ($f->getPostalCode()) {
		if ($f->getLocality()) {
			$adr[] = $f->getPostalCode() . ' ' .$f->getLocality();
		} else {
			$adr[] = $f->getPostalCode();
		}
	} else {
		$adr[] = $f->getLocality();
	}

	if ($f->getCountryCode()) {
		$adr[] = $f->getCountryCode();
	}

	$str = implode(', ', $adr);

	dibi::query('UPDATE positions set address=%s where id=%i', $str, $position['id']);
	echo $position['id'] . ": adresa: $str\n";
}



#!/usr/bin/php
<?php
require __DIR__ . '/vendor/autoload.php';
require 'config.php';

define('FILTER_SPEED', 5);
define('FILTER_METERS', 20 /* meters */ * 1.94); /* knots */
define('LAST_TIMESTAMP', '/tmp/traccar-tidy.last');

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
function latLongDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000) {
	// convert from degrees to radians
	$latFrom = deg2rad($latitudeFrom);
	$lonFrom = deg2rad($longitudeFrom);
	$latTo = deg2rad($latitudeTo);
	$lonTo = deg2rad($longitudeTo);

	$latDelta = abs($latTo - $latFrom);
	$lonDelta = abs($lonTo - $lonFrom);

	$angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
	return $angle * $earthRadius;
}

function comparePositions($a, $b) {
	$diff = latLongDistance(floatval($a['latitude']), floatval($a['longitude']), floatval($b['latitude']), floatval($b['longitude']));
	if ($diff > FILTER_METERS) {
		return false; /* keep */
	}

	return true; /* delete */
}

dibi::connect($db);
echo "DB connected\n";

$toDelete = [];
$toZero = [];
$toGeo = [];
$positions = [];
$counterChecked = 0;
$allPositions = dibi::query('SELECT deviceid, id, latitude, longitude, speed, address FROM positions WHERE servertime > NOW() - INTERVAL 1 DAY ORDER BY deviceid, id');
while (($posNext = $allPositions->fetch())) {
	$counterChecked++;
	if (count($positions) && ($posNext['deviceid'] !== end($positions)['deviceid'])) {
		//echo "Processing device=" . $posNext['deviceid'] . "\n";
		$positions=[];
	}

	$positions[] = $posNext;
	if (count($positions) < 7) {
		continue;
	}

	if(
		comparePositions($positions[3], $positions[1]) &&
		comparePositions($positions[3], $positions[2]) &&
		comparePositions($positions[3], $positions[4]) &&
		comparePositions($positions[3], $positions[5])
	) {
		if ($positions[2]['speed'] != 0) {
			$toZero[$positions[2]['id']] = true;
		}

		$toDelete[$positions[3]['id']] = true;

		for ($i = 0; $i <= 6; $i++) {
			if (strlen($positions[$i]['address']) <= 0) {
				$toGeo[$positions[$i]['id']] = true;
			}
		}
	}

	array_shift($positions);
}
$positions = null;
$allPositions = null;


foreach($toDelete as $pos => $dummy) {
    unset($toZero[$pos]);
    unset($toGeo[$pos]);
}

$toDelete = array_keys($toDelete);
$toZero = array_keys($toZero);
$toGeo = array_keys($toGeo);

echo "Checked $counterChecked entries.\n";
echo "Delete " . count($toDelete) . " entries.\n";
echo "Zero " . count($toZero) . " entries.\n";
echo "Geo " . count($toGeo) . " entries.\n";
//var_dump($toDelete);
//var_dump($toZero);
//var_dump($toGeo);


if (count($toDelete)) {
	dibi::delete('positions')->where('id in %l', $toDelete)->execute();
	dibi::delete('events')->where('positionId in %l', $toDelete)->execute();
}
if (count($toZero)) {
	$res = dibi::query('UPDATE positions set speed=%i where id in %l', 0, $toZero);
}

$curl = new \Ivory\HttpAdapter\CurlHttpAdapter();
$geocoder = new \Geocoder\Provider\GoogleMaps($curl, 'cs', NULL, true, $googleApiKey);

if (count($toGeo)) {
	//$positions = dibi::query('SELECT * FROM positions WHERE address IS NULL and speed < 20');
	$positions = dibi::query('SELECT * FROM positions WHERE id in %l', $toGeo);
	while (($position = $positions->fetch())) {
		$cache = dibi::query('select address from geocache where latitude=%f and longitude=%f', round($position['latitude'], 4), round($position['longitude'], 4))->fetch();
		if ($cache) {
			$str = $cache['address'];
			echo "Using cache...\n";
		}
		else {
			echo "Resolving google..." . $position['latitude'] . " " . $position['longitude'] . "\n";
			$ret = $geocoder->reverse($position['latitude'], $position['longitude']);
			$f = $ret->first();

			$adr = [];
			if ($f->getStreetName() && preg_match('/.*[a-zA-Z]+.*$/', $f->getStreetName())) {
				if ($f->getStreetNumber()) {
					$adr[] = $f->getStreetName() . ' ' . $f->getStreetNumber();
				}
				else {
					$adr[] = $f->getStreetName();
				}
			}


			if ($f->getPostalCode()) {
				if ($f->getLocality()) {
					$adr[] = $f->getPostalCode() . ' ' . $f->getLocality();
				}
				else {
					$adr[] = $f->getPostalCode();
				}
			}
			else {
				$adr[] = $f->getLocality();
			}

			if ($f->getCountryCode()) {
				$adr[] = $f->getCountryCode();
			}

			$str = implode(', ', $adr);

			dibi::query('INSERT into [geocache]', [
				'latitude' => round($position['latitude'], 4),
				'longitude' => round($position['longitude'], 4),
				'address' => $str,
			]);
		}

		dibi::query('UPDATE positions set address=%s where id=%i', $str, $position['id']);
		echo $position['id'] . ": adresa: $str\n";
	}
}

/* erase cache */
dibi::query('delete from geocache where time < NOW() - INTERVAL 60 DAY');

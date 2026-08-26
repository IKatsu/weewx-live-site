<?php

declare(strict_types=1);

function celestial_catalog_bool(array $config, string $key, bool $default): bool
{
    return (bool) (($config['celestial']['catalog'][$key] ?? $default));
}

function celestial_catalog_float(array $config, string $key, float $default, float $min, float $max): float
{
    $value = (float) ($config['celestial']['catalog'][$key] ?? $default);
    return max($min, min($max, $value));
}

function celestial_julian_date(int $unixTime): float
{
    return ($unixTime / 86400.0) + 2440587.5;
}

function celestial_local_sidereal_degrees(int $unixTime, float $longitude): float
{
    $jd = celestial_julian_date($unixTime);
    $days = $jd - 2451545.0;
    $gmst = 280.46061837 + 360.98564736629 * $days;
    return fmod(($gmst + $longitude + 720.0), 360.0);
}

function celestial_project_ra_dec(float $raDegrees, float $decDegrees, float $latDegrees, float $localSiderealDegrees): array
{
    $hourAngle = deg2rad(fmod(($localSiderealDegrees - $raDegrees + 540.0), 360.0) - 180.0);
    $lat = deg2rad($latDegrees);
    $dec = deg2rad($decDegrees);

    $sinAlt = sin($dec) * sin($lat) + cos($dec) * cos($lat) * cos($hourAngle);
    $sinAlt = max(-1.0, min(1.0, $sinAlt));
    $alt = asin($sinAlt);

    $az = atan2(
        -sin($hourAngle),
        tan($dec) * cos($lat) - sin($lat) * cos($hourAngle)
    );
    $azDegrees = fmod(rad2deg($az) + 360.0, 360.0);

    return [
        'altitude' => round(rad2deg($alt), 3),
        'azimuth' => round($azDegrees, 3),
    ];
}

function celestial_catalog_rows(PDO $pdo, array $config, int $unixTime): array
{
    $location = (array) ($config['location'] ?? []);
    $lat = (float) ($location['latitude'] ?? 0.0);
    $lon = (float) ($location['longitude'] ?? 0.0);
    $lst = celestial_local_sidereal_degrees($unixTime, $lon);
    $magLimit = celestial_catalog_float($config, 'star_magnitude_limit', 6.0, -2.0, 12.0);
    $labelMagLimit = celestial_catalog_float($config, 'star_label_magnitude_limit', 1.6, -2.0, 6.5);
    $includeLines = celestial_catalog_bool($config, 'constellation_lines', true);
    $includeLabels = celestial_catalog_bool($config, 'constellation_labels', true);

    $starsTable = is_safe_identifier((string) ($config['celestial']['catalog']['stars_table'] ?? 'pws_celestial_stars'))
        ? (string) ($config['celestial']['catalog']['stars_table'] ?? 'pws_celestial_stars')
        : 'pws_celestial_stars';
    $linesTable = is_safe_identifier((string) ($config['celestial']['catalog']['lines_table'] ?? 'pws_celestial_constellation_lines'))
        ? (string) ($config['celestial']['catalog']['lines_table'] ?? 'pws_celestial_constellation_lines')
        : 'pws_celestial_constellation_lines';
    $namesTable = is_safe_identifier((string) ($config['celestial']['catalog']['names_table'] ?? 'pws_celestial_constellation_names'))
        ? (string) ($config['celestial']['catalog']['names_table'] ?? 'pws_celestial_constellation_names')
        : 'pws_celestial_constellation_names';

    $starStmt = $pdo->prepare("SELECT hip_id, right_ascension_degrees, declination_degrees, magnitude, proper_name
        FROM {$starsTable}
        WHERE catalog_key = 'hipparcos' AND magnitude IS NOT NULL AND magnitude <= :mag
        ORDER BY magnitude ASC");
    $starStmt->execute([':mag' => $magLimit]);

    $stars = [];
    $brightStars = [];
    while ($row = $starStmt->fetch()) {
        $projected = celestial_project_ra_dec(
            (float) $row['right_ascension_degrees'],
            (float) $row['declination_degrees'],
            $lat,
            $lst
        );
        if ($projected['altitude'] < -1.0) {
            continue;
        }
        $star = [
            'hip' => (int) $row['hip_id'],
            'azimuth' => $projected['azimuth'],
            'altitude' => $projected['altitude'],
            'magnitude' => round((float) $row['magnitude'], 2),
        ];
        $name = trim((string) ($row['proper_name'] ?? ''));
        if ($name !== '') {
            $star['name'] = $name;
        }
        $stars[] = $star;
        if ((float) $row['magnitude'] <= $labelMagLimit) {
            $brightStars[] = $star;
        }
    }

    $linePayload = [];
    $labelPayload = [];
    if ($includeLines) {
        $lineSql = "SELECT l.constellation_abbr, l.polyline_id, l.point_order, s.hip_id,
                s.right_ascension_degrees, s.declination_degrees
            FROM {$linesTable} l
            JOIN {$starsTable} s ON s.catalog_key = 'hipparcos' AND s.hip_id = l.hip_id
            WHERE l.catalog_key = 'stellarium-modern'
            ORDER BY l.constellation_abbr, l.polyline_id, l.point_order";
        $rows = $pdo->query($lineSql)->fetchAll();
        $grouped = [];
        $visibleByConstellation = [];
        $totalByConstellation = [];
        foreach ($rows as $row) {
            $abbr = (string) $row['constellation_abbr'];
            $key = $abbr . ':' . (string) $row['polyline_id'];
            $projected = celestial_project_ra_dec(
                (float) $row['right_ascension_degrees'],
                (float) $row['declination_degrees'],
                $lat,
                $lst
            );
            $point = [
                'hip' => (int) $row['hip_id'],
                'azimuth' => $projected['azimuth'],
                'altitude' => $projected['altitude'],
            ];
            $grouped[$key]['abbr'] = $abbr;
            $grouped[$key]['points'][] = $point;
            $totalByConstellation[$abbr][$point['hip']] = true;
            if ($projected['altitude'] > 0.0) {
                $visibleByConstellation[$abbr][$point['hip']] = $point;
            }
        }
        foreach ($grouped as $line) {
            $segments = [];
            $run = [];
            foreach ($line['points'] as $point) {
                if ($point['altitude'] >= -5.0) {
                    $run[] = $point;
                } else {
                    if (count($run) > 1) {
                        $segments[] = $run;
                    }
                    $run = [];
                }
            }
            if (count($run) > 1) {
                $segments[] = $run;
            }
            foreach ($segments as $segment) {
                $linePayload[] = [
                    'abbr' => $line['abbr'],
                    'points' => array_map(static fn($p) => ['azimuth' => $p['azimuth'], 'altitude' => $p['altitude']], $segment),
                ];
            }
        }

        if ($includeLabels && $visibleByConstellation !== []) {
            $nameRows = $pdo->query("SELECT constellation_abbr, display_name FROM {$namesTable} WHERE locale = 'en'")->fetchAll();
            $names = [];
            foreach ($nameRows as $row) {
                $names[(string) $row['constellation_abbr']] = (string) $row['display_name'];
            }
            foreach ($visibleByConstellation as $abbr => $points) {
                $visibleCount = count($points);
                $totalCount = count($totalByConstellation[$abbr] ?? []);
                if ($visibleCount < 2 || ($totalCount > 0 && $visibleCount * 2 < $totalCount)) {
                    continue;
                }
                $az = 0.0;
                $alt = 0.0;
                foreach ($points as $point) {
                    $az += $point['azimuth'];
                    $alt += $point['altitude'];
                }
                $labelPayload[] = [
                    'abbr' => $abbr,
                    'name' => $names[$abbr] ?? $abbr,
                    'azimuth' => round($az / $visibleCount, 3),
                    'altitude' => round($alt / $visibleCount, 3),
                ];
            }
        }
    }

    return [
        'generatedAt' => gmdate('c', $unixTime),
        'location' => [
            'latitude' => $lat,
            'longitude' => $lon,
            'timezone' => (string) (($location['timezone'] ?? 'UTC') ?: 'UTC'),
        ],
        'limits' => [
            'starMagnitude' => $magLimit,
            'starLabelMagnitude' => $labelMagLimit,
        ],
        'counts' => [
            'stars' => count($stars),
            'brightStars' => count($brightStars),
            'constellationLines' => count($linePayload),
            'constellationLabels' => count($labelPayload),
        ],
        'stars' => $stars,
        'brightStars' => $brightStars,
        'constellationLines' => $linePayload,
        'constellationLabels' => $labelPayload,
        'source' => [
            'engine' => 'pws-live-site spherical projection',
            'note' => 'Catalog data is imported locally; no catalog dataset files are served by this API.',
        ],
    ];
}

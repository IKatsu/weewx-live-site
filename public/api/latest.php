<?php

declare(strict_types=1);

// API entrypoints can run from local dev or mounted deploy paths.
putenv('PWS_BASE_DIR=' . dirname(__DIR__));

$srcCandidates = [
    dirname(__DIR__, 2) . '/src',
    dirname(__DIR__, 3) . '/src',
];

$bootstrapPath = null;
foreach ($srcCandidates as $candidate) {
    if (is_file($candidate . '/bootstrap.php')) {
        $bootstrapPath = $candidate . '/bootstrap.php';
        break;
    }
}

if ($bootstrapPath === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unable to locate src/bootstrap.php']);
    exit;
}

require_once $bootstrapPath;

$config = app_config();

// Logical metric definitions map to DB fields via src/config*.php field_map.
$metricSpec = [
    'outTemp' => ['label' => 'Outside Temp', 'unit' => 'temperature'],
    'inTemp' => ['label' => 'Inside Temp', 'unit' => 'temperature'],
    'outHumidity' => ['label' => 'Outside Humidity', 'unit' => 'humidity'],
    'inHumidity' => ['label' => 'Inside Humidity', 'unit' => 'humidity'],
    'barometer' => ['label' => 'Sea-Level Pressure', 'unit' => 'pressure'],
    'pressure' => ['label' => 'Station Pressure', 'unit' => 'pressure'],
    'windSpeed' => ['label' => 'Wind Speed', 'unit' => 'wind'],
    'windGust' => ['label' => 'Wind Gust', 'unit' => 'wind'],
    'windDir' => ['label' => 'Wind Direction', 'unit' => 'degree'],
    'windrun' => ['label' => 'Wind Run', 'unit' => 'wind'],
    'rainRate' => ['label' => 'Rain Rate', 'unit' => 'rain_rate'],
    'rain' => ['label' => 'Rain Today', 'unit' => 'rain'],
    'radiation' => ['label' => 'Solar Radiation', 'unit' => 'radiation'],
    'UV' => ['label' => 'UV Index', 'unit' => 'uv'],
    'dewpoint' => ['label' => 'Dewpoint', 'unit' => 'temperature'],
    'inDewpoint' => ['label' => 'Inside Dewpoint', 'unit' => 'temperature'],
    'heatindex' => ['label' => 'Heat Index', 'unit' => 'temperature'],
    'windchill' => ['label' => 'Wind Chill', 'unit' => 'temperature'],
    'appTemp' => ['label' => 'Apparent Temp', 'unit' => 'temperature'],
    'humidex' => ['label' => 'Humidex', 'unit' => 'temperature'],
    'cloudbase' => ['label' => 'Cloudbase', 'unit' => 'meters'],
    'ET' => ['label' => 'Evapotranspiration', 'unit' => 'rain'],
    'solarAltitude' => ['label' => 'Solar Altitude', 'unit' => 'degree'],
    'solarAzimuth' => ['label' => 'Solar Azimuth', 'unit' => 'degree'],
    'solarTime' => ['label' => 'Solar Time', 'unit' => 'hours'],
    'lunarAltitude' => ['label' => 'Lunar Altitude', 'unit' => 'degree'],
    'lunarAzimuth' => ['label' => 'Lunar Azimuth', 'unit' => 'degree'],
    'lunarTime' => ['label' => 'Lunar Time', 'unit' => 'hours'],
    'pm2_5' => ['label' => 'PM2.5', 'unit' => 'ugm3'],
    'lightning_strike_count' => ['label' => 'Lightning Strikes', 'unit' => 'count'],
    'lightning_strikes_5m' => ['label' => 'Strikes 5m', 'unit' => 'count'],
    'lightning_strikes_24h' => ['label' => 'Strikes 24h', 'unit' => 'count'],
    'lightning_closest_5m' => ['label' => 'Closest Strike 5m', 'unit' => 'km'],
    'lightning_furthest_5m' => ['label' => 'Furthest Strike 5m', 'unit' => 'km'],
    'lightning_average_5m' => ['label' => 'Average Strike 5m', 'unit' => 'km'],
    'lightning_last_distance' => ['label' => 'Last Strike Distance', 'unit' => 'km'],
    'lightning_last_age' => ['label' => 'Last Strike Age', 'unit' => ''],
    'windBatteryStatus' => ['label' => 'Wind Battery', 'unit' => 'voltage'],
    'rainBatteryStatus' => ['label' => 'Rain Battery', 'unit' => 'voltage'],
    'lightning_Batt' => ['label' => 'Lightning Battery', 'unit' => 'voltage'],
    'pm25_Batt1' => ['label' => 'PM2.5 Battery', 'unit' => 'voltage'],
    'inTempBatteryStatus' => ['label' => 'Indoor Temp Battery', 'unit' => 'voltage'],
];

foreach ((array) ($config['optional_metric_groups'] ?? []) as $groupCfg) {
    if (($groupCfg['enabled'] ?? false) !== true) {
        continue;
    }
    foreach ((array) ($groupCfg['metrics'] ?? []) as $field => $spec) {
        if (!is_string($field) || $field === '') {
            continue;
        }
        $metricSpec[$field] = [
            'label' => (string) ($spec['label'] ?? $field),
            'unit' => (string) ($spec['unit'] ?? ''),
        ];
    }
}

    // Local installs can suppress duplicate logical metrics (for example when
    // pm25_1 mirrors the primary pm2_5 sensor on a specific station setup).
    foreach ((array) ($config['ui']['suppress_latest_metrics'] ?? []) as $metricKey) {
        if (is_string($metricKey) && $metricKey !== '') {
            unset($metricSpec[$metricKey]);
        }
    }

$unitOverride = [
    'degree' => '°',
    'seconds' => 's',
    'meters' => 'm',
    'ugm3' => 'µg/m³',
    'count' => 'count',
    'voltage' => 'V',
    'hours' => 'h',
    'ppm' => 'ppm',
    'status' => '',
    'state' => '',
    'index' => 'index',
    'percent' => '%',
    'mm' => 'mm',
    'km' => 'km',
    'usiecm' => 'µS/cm',
];

$dynamicDerivedMetricKeys = [
    'rain24h',
    'lightning_strikes_5m',
    'lightning_strikes_24h',
    'lightning_closest_5m',
    'lightning_furthest_5m',
    'lightning_average_5m',
    'lightning_last_distance',
    'lightning_last_age',
];

try {
    $pdo = pdo_from_config($config);
    $columns = archive_columns($pdo);

    $dateTimeCol = mapped_archive_column($config, $columns, 'dateTime');
    $usUnitsCol = mapped_archive_column($config, $columns, 'usUnits');
    if ($dateTimeCol === null || $usUnitsCol === null) {
        json_response(['error' => 'Missing mapped dateTime/usUnits columns.'], 500);
    }

    // Start with timestamp + unit system; metrics are added dynamically below.
    $select = [
        sprintf('%s AS dateTime', $dateTimeCol),
        sprintf('%s AS usUnits', $usUnitsCol),
    ];

    $included = [];
    $missing = [];
    // Select only metrics that are both configured and physically present in archive.
    foreach (array_keys($metricSpec) as $field) {
        if (in_array($field, $dynamicDerivedMetricKeys, true)) {
            continue;
        }
        $col = mapped_archive_column($config, $columns, $field);
        if ($col === null) {
            $missing[] = $field;
            continue;
        }
        $select[] = sprintf('%s AS %s', $col, $field);
        $included[] = $field;
    }

    $sql = sprintf(
        'SELECT %s FROM archive ORDER BY %s DESC LIMIT 1',
        implode(', ', $select),
        $dateTimeCol
    );

    $row = $pdo->query($sql)->fetch();
    if (!$row) {
        json_response(['error' => 'No weather records found in archive table.'], 404);
    }

    $derivedValues = [];
    $windSummary = null;
    $timezone = (string) (($config['location']['timezone'] ?? 'UTC') ?: 'UTC');
    $latestTs = (int) $row['dateTime'];
    $windSpeedColumn = mapped_archive_column($config, $columns, 'windSpeed');
    $windGustColumn = mapped_archive_column($config, $columns, 'windGust');
    if ($windSpeedColumn !== null && $windGustColumn !== null) {
        $windSummarySql = sprintf(
            'SELECT
                AVG(CASE WHEN %1$s >= :avg_one_hour_start THEN %2$s END) AS wind_avg_1h,
                AVG(CASE WHEN %1$s >= :gust_avg_one_hour_start THEN %3$s END) AS gust_avg_1h,
                AVG(CASE WHEN %1$s >= :avg_three_hour_start THEN %2$s END) AS wind_avg_3h,
                AVG(CASE WHEN %1$s >= :gust_avg_three_hour_start THEN %3$s END) AS gust_avg_3h,
                MAX(CASE WHEN %1$s >= :top_one_hour_start THEN %2$s END) AS wind_top_1h,
                MAX(CASE WHEN %1$s >= :top_three_hour_start THEN %2$s END) AS wind_top_3h,
                MAX(CASE WHEN %1$s >= :gust_one_hour_start THEN %3$s END) AS gust_top_1h,
                MAX(CASE WHEN %1$s >= :gust_three_hour_start THEN %3$s END) AS gust_top_3h
             FROM archive
             WHERE %1$s <= :latest_ts AND %1$s >= :where_three_hour_start',
            $dateTimeCol,
            $windSpeedColumn,
            $windGustColumn
        );
        $windSummaryStmt = $pdo->prepare($windSummarySql);
        $windSummaryStmt->execute([
            ':avg_one_hour_start' => $latestTs - 3600,
            ':avg_three_hour_start' => $latestTs - 10800,
            ':gust_avg_one_hour_start' => $latestTs - 3600,
            ':gust_avg_three_hour_start' => $latestTs - 10800,
            ':top_one_hour_start' => $latestTs - 3600,
            ':top_three_hour_start' => $latestTs - 10800,
            ':gust_one_hour_start' => $latestTs - 3600,
            ':gust_three_hour_start' => $latestTs - 10800,
            ':where_three_hour_start' => $latestTs - 10800,
            ':latest_ts' => $latestTs,
        ]);
        $windSummaryRow = $windSummaryStmt->fetch() ?: [];
        $windSummary = [
            'avg1h' => isset($windSummaryRow['wind_avg_1h']) ? (float) $windSummaryRow['wind_avg_1h'] : null,
            'avg3h' => isset($windSummaryRow['wind_avg_3h']) ? (float) $windSummaryRow['wind_avg_3h'] : null,
            'gustAvg1h' => isset($windSummaryRow['gust_avg_1h']) ? (float) $windSummaryRow['gust_avg_1h'] : null,
            'gustAvg3h' => isset($windSummaryRow['gust_avg_3h']) ? (float) $windSummaryRow['gust_avg_3h'] : null,
            'top1h' => isset($windSummaryRow['wind_top_1h']) ? (float) $windSummaryRow['wind_top_1h'] : null,
            'top3h' => isset($windSummaryRow['wind_top_3h']) ? (float) $windSummaryRow['wind_top_3h'] : null,
            'gustTop1h' => isset($windSummaryRow['gust_top_1h']) ? (float) $windSummaryRow['gust_top_1h'] : null,
            'gustTop3h' => isset($windSummaryRow['gust_top_3h']) ? (float) $windSummaryRow['gust_top_3h'] : null,
        ];
    }

    $rainColumn = mapped_archive_column($config, $columns, 'rain');
    if ($rainColumn !== null) {
        // WeeWX archive.rain is the amount for that archive interval, not a
        // sticky total. Expose both local-midnight and trailing-24h totals so
        // the dashboard can show an MQTT-aligned "Rain Today" card while still
        // making a true 24-hour accumulation available separately.
        $latestLocal = (new DateTimeImmutable('@' . $latestTs))->setTimezone(new DateTimeZone($timezone));
        $dayStartTs = $latestLocal->setTime(0, 0, 0)->getTimestamp();
        $window24hTs = $latestTs - 86400;
        $rainSumSql = sprintf(
            'SELECT
                COALESCE(SUM(CASE WHEN %2$s >= :day_start_case THEN %1$s ELSE 0 END), 0) AS rain_today,
                COALESCE(SUM(CASE WHEN %2$s >= :window_24h_case THEN %1$s ELSE 0 END), 0) AS rain_24h
             FROM archive
             WHERE %2$s <= :latest_ts AND %2$s >= :window_24h_where',
            $rainColumn,
            $dateTimeCol
        );
        $rainSumStmt = $pdo->prepare($rainSumSql);
        $rainSumStmt->execute([
            ':day_start_case' => $dayStartTs,
            ':window_24h_case' => $window24hTs,
            ':window_24h_where' => $window24hTs,
            ':latest_ts' => $latestTs,
        ]);
        $rainSums = $rainSumStmt->fetch() ?: [];
        $derivedValues['rain'] = (float) (($rainSums['rain_today'] ?? 0.0));
        $derivedValues['rain24h'] = (float) (($rainSums['rain_24h'] ?? 0.0));
    }

    $lightningDistanceColumn = mapped_archive_column($config, $columns, 'lightning_distance');
    $lightningStrikeCountColumn = mapped_archive_column($config, $columns, 'lightning_strike_count');
    if ($lightningDistanceColumn !== null || $lightningStrikeCountColumn !== null) {
        // Ecowitt lightning_disturber_count can stay latched for hours, so the
        // dashboard summary should be based on actual strike rows and recent
        // distances instead of that raw flag.
        $window5mTs = $latestTs - 300;
        $window24hTs = $latestTs - 86400;
        $strikeExpr = $lightningStrikeCountColumn !== null ? $lightningStrikeCountColumn : '0';
        $distanceExpr = $lightningDistanceColumn !== null ? $lightningDistanceColumn : 'NULL';
        $lightningSummarySql = sprintf(
            'SELECT
                COALESCE(SUM(CASE WHEN %1$s >= :five_min_start_case THEN %2$s ELSE 0 END), 0) AS strikes_5m,
                COALESCE(SUM(CASE WHEN %1$s >= :day_start_case THEN %2$s ELSE 0 END), 0) AS strikes_24h,
                MIN(CASE WHEN %1$s >= :distance_five_min_start AND %3$s IS NOT NULL THEN %3$s END) AS closest_5m,
                MAX(CASE WHEN %1$s >= :distance_five_min_start_max AND %3$s IS NOT NULL THEN %3$s END) AS furthest_5m,
                AVG(CASE WHEN %1$s >= :distance_five_min_start_avg AND %3$s IS NOT NULL THEN %3$s END) AS average_5m
             FROM archive
             WHERE %1$s <= :latest_ts AND %1$s >= :where_start_24h',
            $dateTimeCol,
            $strikeExpr,
            $distanceExpr
        );
        $lightningSummaryStmt = $pdo->prepare($lightningSummarySql);
        $lightningSummaryStmt->execute([
            ':five_min_start_case' => $window5mTs,
            ':day_start_case' => $window24hTs,
            ':distance_five_min_start' => $window5mTs,
            ':distance_five_min_start_max' => $window5mTs,
            ':distance_five_min_start_avg' => $window5mTs,
            ':where_start_24h' => $window24hTs,
            ':latest_ts' => $latestTs,
        ]);
        $lightningSummary = $lightningSummaryStmt->fetch() ?: [];
        $strikes24h = (int) round((float) ($lightningSummary['strikes_24h'] ?? 0));
        if ($strikes24h > 0) {
            $derivedValues['lightning_strikes_5m'] = (string) ((int) round((float) ($lightningSummary['strikes_5m'] ?? 0)));
            $derivedValues['lightning_strikes_24h'] = (string) $strikes24h;
            if (isset($lightningSummary['closest_5m']) && $lightningSummary['closest_5m'] !== null) {
                $derivedValues['lightning_closest_5m'] = (float) $lightningSummary['closest_5m'];
            }
            if (isset($lightningSummary['furthest_5m']) && $lightningSummary['furthest_5m'] !== null) {
                $derivedValues['lightning_furthest_5m'] = (float) $lightningSummary['furthest_5m'];
            }
            if (isset($lightningSummary['average_5m']) && $lightningSummary['average_5m'] !== null) {
                $derivedValues['lightning_average_5m'] = (float) $lightningSummary['average_5m'];
            }
        } else {
            $lastStrikeSql = sprintf(
                'SELECT %1$s AS strike_ts, %2$s AS strike_distance
                 FROM archive
                 WHERE (%3$s > 0 OR %2$s IS NOT NULL)
                 ORDER BY %1$s DESC
                 LIMIT 1',
                $dateTimeCol,
                $distanceExpr,
                $strikeExpr
            );
            $lastStrikeRow = $pdo->query($lastStrikeSql)->fetch() ?: null;
            if ($lastStrikeRow) {
                if (isset($lastStrikeRow['strike_distance']) && $lastStrikeRow['strike_distance'] !== null) {
                    $derivedValues['lightning_last_distance'] = (float) $lastStrikeRow['strike_distance'];
                }
                $lastStrikeTs = (int) ($lastStrikeRow['strike_ts'] ?? 0);
                if ($lastStrikeTs > 0) {
                    $derivedValues['lightning_last_age'] = lightning_age_label($latestTs - $lastStrikeTs);
                }
            }
        }
    }

    $units = unit_map((int) $row['usUnits']);
    $metrics = [];
    foreach ($included as $field) {
        $spec = $metricSpec[$field];
        $unit = $unitOverride[$spec['unit']] ?? ($units[$spec['unit']] ?? '');
        $value = $derivedValues[$field] ?? ($row[$field] ?? null);
        $metrics[$field] = [
            'label' => $spec['label'],
            'value' => $value,
            'unit' => $unit,
            'missingColumn' => false,
        ];
    }

    if (array_key_exists('rain24h', $derivedValues)) {
        $metrics['rain24h'] = [
            'label' => 'Rain 24h',
            'value' => $derivedValues['rain24h'],
            'unit' => $unitOverride['mm'] ?? ($units['rain'] ?? ''),
            'missingColumn' => false,
        ];
    }

    foreach ([
        'lightning_strikes_5m',
        'lightning_strikes_24h',
        'lightning_closest_5m',
        'lightning_furthest_5m',
        'lightning_average_5m',
        'lightning_last_distance',
        'lightning_last_age',
    ] as $field) {
        if (!array_key_exists($field, $derivedValues) || !isset($metricSpec[$field])) {
            continue;
        }
        $spec = $metricSpec[$field];
        $unit = $unitOverride[$spec['unit']] ?? ($units[$spec['unit']] ?? '');
        $metrics[$field] = [
            'label' => $spec['label'],
            'value' => $derivedValues[$field],
            'unit' => $unit,
            'missingColumn' => false,
        ];
    }

    json_response([
        'timestamp' => (int) $row['dateTime'],
        'updatedAtIso' => gmdate('c', (int) $row['dateTime']),
        'usUnits' => (int) $row['usUnits'],
        'metrics' => $metrics,
        'windSummary' => $windSummary,
        'availableFields' => $included,
        // Keep missing configured fields visible to debug/admin consumers without
        // exposing them as fake values in the normal latest metric payload.
        'missingFields' => $missing,
    ]);
} catch (Throwable $exception) {
    json_response([
        'error' => 'Failed to load latest conditions.',
        'details' => $exception->getMessage(),
    ], 500);
}

function lightning_age_label(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return sprintf('%ds ago', $seconds);
    }

    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
        return sprintf('%dm ago', $minutes);
    }

    $hours = intdiv($minutes, 60);
    $minsRemainder = $minutes % 60;
    if ($hours < 24) {
        return $minsRemainder > 0
            ? sprintf('%dh %dm ago', $hours, $minsRemainder)
            : sprintf('%dh ago', $hours);
    }

    $days = intdiv($hours, 24);
    $hoursRemainder = $hours % 24;
    return $hoursRemainder > 0
        ? sprintf('%dd %dh ago', $days, $hoursRemainder)
        : sprintf('%dd ago', $days);
}

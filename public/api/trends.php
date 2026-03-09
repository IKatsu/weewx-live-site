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

/**
 * @param list<array{t:float,v:float}> $points
 * @return array{slope_per_hour:float,r2:float,samples:int}
 */
function regression_slope(array $points): array
{
    $n = count($points);
    if ($n < 2) {
        return ['slope_per_hour' => 0.0, 'r2' => 0.0, 'samples' => $n];
    }

    $xMean = 0.0;
    $yMean = 0.0;
    foreach ($points as $p) {
        $xMean += $p['t'];
        $yMean += $p['v'];
    }
    $xMean /= $n;
    $yMean /= $n;

    $ssXX = 0.0;
    $ssXY = 0.0;
    $ssYY = 0.0;
    foreach ($points as $p) {
        $dx = $p['t'] - $xMean;
        $dy = $p['v'] - $yMean;
        $ssXX += ($dx * $dx);
        $ssXY += ($dx * $dy);
        $ssYY += ($dy * $dy);
    }

    if ($ssXX <= 0.0) {
        return ['slope_per_hour' => 0.0, 'r2' => 0.0, 'samples' => $n];
    }

    $slopePerSecond = $ssXY / $ssXX;
    $slopePerHour = $slopePerSecond * 3600.0;

    $r2 = 0.0;
    if ($ssYY > 0.0) {
        $r = $ssXY / sqrt($ssXX * $ssYY);
        $r2 = max(0.0, min(1.0, $r * $r));
    }

    return ['slope_per_hour' => $slopePerHour, 'r2' => $r2, 'samples' => $n];
}

function trend_direction(float $slopePerHour, float $deadband): string
{
    if ($slopePerHour > $deadband) {
        return 'rising';
    }
    if ($slopePerHour < -$deadband) {
        return 'falling';
    }
    return 'steady';
}

function estimate_rain_likelihood(array $latestMetrics, array $metricTrends, array $units): array
{
    $score = 0;
    $reasons = [];

    $humidity = isset($latestMetrics['outHumidity']) ? (float) $latestMetrics['outHumidity'] : null;
    if ($humidity !== null && $humidity >= 90.0) {
        $score += 25;
        $reasons[] = 'High outside humidity';
    } elseif ($humidity !== null && $humidity >= 80.0) {
        $score += 15;
        $reasons[] = 'Elevated outside humidity';
    }

    $pressureUnit = (string) ($units['pressure'] ?? 'hPa');
    $pressureTrend = isset($metricTrends['barometer']) ? (float) ($metricTrends['barometer']['slope_per_hour'] ?? 0.0) : 0.0;
    $fallFast = str_contains($pressureUnit, 'inHg') ? -0.03 : -1.0;
    $fallMild = str_contains($pressureUnit, 'inHg') ? -0.015 : -0.5;
    if ($pressureTrend <= $fallFast) {
        $score += 30;
        $reasons[] = 'Pressure dropping quickly';
    } elseif ($pressureTrend <= $fallMild) {
        $score += 18;
        $reasons[] = 'Pressure falling';
    }

    $rainRate = isset($latestMetrics['rainRate']) ? (float) $latestMetrics['rainRate'] : null;
    if ($rainRate !== null && $rainRate > 0.0) {
        $score += 35;
        $reasons[] = 'Rain currently detected';
    }

    $windTrend = isset($metricTrends['windSpeed']) ? (float) ($metricTrends['windSpeed']['slope_per_hour'] ?? 0.0) : 0.0;
    if ($windTrend > 0.4) {
        $score += 10;
        $reasons[] = 'Wind speed trending up';
    }

    $score = max(0, min(100, $score));
    $level = 'low';
    if ($score >= 70) {
        $level = 'high';
    } elseif ($score >= 40) {
        $level = 'moderate';
    }

    return [
        'score' => $score,
        'level' => $level,
        'reasons' => $reasons,
    ];
}

$config = app_config();

$trendFields = [
    'outTemp' => ['label' => 'Outside Temperature', 'unitType' => 'temperature', 'deadband' => 0.2, 'predictHours' => 3],
    'barometer' => ['label' => 'Barometer', 'unitType' => 'pressure', 'deadband' => 0.2, 'predictHours' => 3],
    'windSpeed' => ['label' => 'Wind Speed', 'unitType' => 'wind', 'deadband' => 0.15, 'predictHours' => 3],
    'outHumidity' => ['label' => 'Outside Humidity', 'unitType' => 'humidity', 'deadband' => 1.0, 'predictHours' => 3],
    'rainRate' => ['label' => 'Rain Rate', 'unitType' => 'rain_rate', 'deadband' => 0.1, 'predictHours' => 1],
];

try {
    $pdo = pdo_from_config($config);
    $archiveCols = archive_columns($pdo);

    $dateTimeCol = mapped_archive_column($config, $archiveCols, 'dateTime');
    $usUnitsCol = mapped_archive_column($config, $archiveCols, 'usUnits');
    if ($dateTimeCol === null || $usUnitsCol === null) {
        json_response(['error' => 'Missing mapped dateTime/usUnits columns.'], 500);
    }

    $mapped = [];
    foreach ($trendFields as $field => $_spec) {
        $col = mapped_archive_column($config, $archiveCols, $field);
        if ($col !== null) {
            $mapped[$field] = $col;
        }
    }

    if ($mapped === []) {
        json_response(['error' => 'No trend fields are mapped in archive.'], 500);
    }

    $latestSelect = [
        sprintf('%s AS dateTime', $dateTimeCol),
        sprintf('%s AS usUnits', $usUnitsCol),
    ];
    foreach ($mapped as $field => $col) {
        $latestSelect[] = sprintf('%s AS %s', $col, $field);
    }

    $latestSql = sprintf(
        'SELECT %s FROM archive ORDER BY %s DESC LIMIT 1',
        implode(', ', $latestSelect),
        $dateTimeCol
    );
    $latest = $pdo->query($latestSql)->fetch();
    if (!$latest) {
        json_response(['error' => 'No weather records found in archive table.'], 404);
    }

    $historySelect = [sprintf('%s AS dateTime', $dateTimeCol)];
    foreach ($mapped as $field => $col) {
        $historySelect[] = sprintf('%s AS %s', $col, $field);
    }

    $historySql = sprintf(
        'SELECT %s
         FROM archive
         WHERE %s >= UNIX_TIMESTAMP(DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 HOUR))
         ORDER BY %s ASC',
        implode(', ', $historySelect),
        $dateTimeCol,
        $dateTimeCol
    );
    $rows = $pdo->query($historySql)->fetchAll();

    $units = unit_map((int) $latest['usUnits']);
    $metrics = [];
    $trendLookup = [];

    foreach ($mapped as $field => $_col) {
        $spec = $trendFields[$field];

        $points = [];
        foreach ($rows as $row) {
            $v = $row[$field] ?? null;
            if ($v === null || !is_numeric($v)) {
                continue;
            }
            $points[] = [
                't' => (float) $row['dateTime'],
                'v' => (float) $v,
            ];
        }

        $trend = regression_slope($points);
        $direction = trend_direction((float) $trend['slope_per_hour'], (float) $spec['deadband']);
        $current = isset($latest[$field]) && is_numeric($latest[$field]) ? (float) $latest[$field] : null;
        $predictHours = (int) $spec['predictHours'];
        $predicted = $current !== null ? $current + ((float) $trend['slope_per_hour'] * $predictHours) : null;
        if ($predicted !== null && $field === 'outHumidity') {
            $predicted = max(0.0, min(100.0, $predicted));
        }
        if ($predicted !== null && $field === 'rainRate') {
            $predicted = max(0.0, $predicted);
        }

        $unit = $units[$spec['unitType']] ?? '';

        $metric = [
            'field' => $field,
            'label' => $spec['label'],
            'unit' => $unit,
            'current' => $current,
            'slope_per_hour' => (float) $trend['slope_per_hour'],
            'direction' => $direction,
            'prediction_hours' => $predictHours,
            'predicted_value' => $predicted,
            'confidence' => (float) $trend['r2'],
            'sample_count' => (int) $trend['samples'],
        ];

        $metrics[] = $metric;
        $trendLookup[$field] = $metric;
    }

    $latestMetrics = [];
    foreach (array_keys($mapped) as $field) {
        if (isset($latest[$field]) && is_numeric($latest[$field])) {
            $latestMetrics[$field] = (float) $latest[$field];
        }
    }

    $rainNowcast = estimate_rain_likelihood($latestMetrics, $trendLookup, $units);

    $summary = [];
    foreach ($metrics as $metric) {
        $summary[] = sprintf(
            '%s is %s (%.2f %s/hour, confidence %.0f%%).',
            $metric['label'],
            $metric['direction'],
            $metric['slope_per_hour'],
            $metric['unit'],
            $metric['confidence'] * 100.0
        );
    }

    json_response([
        'generatedAtIso' => gmdate('c'),
        'latestTimestamp' => (int) $latest['dateTime'],
        'latestTimestampIso' => gmdate('c', (int) $latest['dateTime']),
        'windowHours' => 12,
        'metrics' => $metrics,
        'rainNowcast' => $rainNowcast,
        'summary' => $summary,
    ]);
} catch (Throwable $exception) {
    json_response([
        'error' => 'Failed to calculate trends.',
        'details' => $exception->getMessage(),
    ], 500);
}

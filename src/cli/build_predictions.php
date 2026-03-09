<?php

declare(strict_types=1);

$srcDir = dirname(__DIR__);
require_once $srcDir . '/bootstrap.php';
require_once $srcDir . '/forecast_cache.php';
require_once $srcDir . '/prediction_cache.php';

/**
 * @param list<array{t:int,v:float}> $points
 * @return array{slope_per_hour:float,r2:float,samples:int}
 */
function regression_stats(array $points): array
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

    $slopePerSec = $ssXY / $ssXX;
    $slopePerHour = $slopePerSec * 3600.0;
    $r2 = 0.0;
    if ($ssYY > 0.0) {
        $r = $ssXY / sqrt($ssXX * $ssYY);
        $r2 = max(0.0, min(1.0, $r * $r));
    }

    return ['slope_per_hour' => $slopePerHour, 'r2' => $r2, 'samples' => $n];
}

/**
 * @param list<array{ts:int,val:float}> $history
 */
function seasonal_mean_for_hour(array $history, int $targetHour): ?float
{
    $sum = 0.0;
    $count = 0;
    foreach ($history as $row) {
        $h = (int) gmdate('G', (int) $row['ts']);
        if ($h !== $targetHour) {
            continue;
        }
        $sum += (float) $row['val'];
        $count++;
    }
    if ($count === 0) {
        return null;
    }
    return $sum / $count;
}

function clamp_metric(string $field, float $value): float
{
    return match ($field) {
        'outHumidity' => max(0.0, min(100.0, $value)),
        'rainRate', 'windSpeed' => max(0.0, $value),
        default => $value,
    };
}

/**
 * @return array{min:?float,max:?float}
 */
function wu_tomorrow_temp_range(PDO $pdo, array $config): array
{
    $daily = forecast_read_dataset($pdo, $config, 'daily');
    if (!is_array($daily)) {
        return ['min' => null, 'max' => null];
    }
    $payload = (array) ($daily['payload'] ?? []);

    $mins = (array) ($payload['temperatureMin'] ?? []);
    $maxs = (array) ($payload['temperatureMax'] ?? []);
    $days = (array) ($payload['dayOfWeek'] ?? []);

    if (count($mins) < 2 || count($maxs) < 2) {
        return ['min' => null, 'max' => null];
    }

    // Index 0 is usually "today" in daily API payloads.
    $idx = 1;
    $min = arr_idx($mins, $idx);
    $max = arr_idx($maxs, $idx);
    if (!is_numeric($min) || !is_numeric($max)) {
        return ['min' => null, 'max' => null];
    }

    return [
        'min' => (float) $min,
        'max' => (float) $max,
    ];
}

$config = app_config();
$force = in_array('--force', $argv, true);

$metricDefs = [
    'outTemp' => ['label' => 'Outside Temperature', 'unitType' => 'temperature'],
    'outHumidity' => ['label' => 'Outside Humidity', 'unitType' => 'humidity'],
    'barometer' => ['label' => 'Barometer', 'unitType' => 'pressure'],
    'windSpeed' => ['label' => 'Wind Speed', 'unitType' => 'wind'],
    'rainRate' => ['label' => 'Rain Rate', 'unitType' => 'rain_rate'],
];

$horizons = (array) ($config['prediction']['horizons_hours'] ?? [1, 3, 6, 12, 24]);
if ($horizons === []) {
    $horizons = [1, 3, 6, 12, 24];
}

try {
    $readPdo = pdo_from_config($config);

    $writerDb = (array) ($config['forecast_writer_db'] ?? []);
    $writerUser = (string) ($writerDb['username'] ?? '');
    if ($writerUser !== '') {
        $writerConfig = $config;
        $writerConfig['db'] = [
            'host' => (string) ($writerDb['host'] ?? $config['db']['host']),
            'port' => (int) ($writerDb['port'] ?? $config['db']['port']),
            'database' => (string) ($writerDb['database'] ?? $config['db']['database']),
            'username' => $writerUser,
            'password' => (string) ($writerDb['password'] ?? ''),
        ];
        $writePdo = pdo_from_config($writerConfig);
    } else {
        $writePdo = $readPdo;
    }

    if (!$force && !prediction_cache_should_refresh($writePdo, $config)) {
        fwrite(STDOUT, "Prediction refresh skipped (interval not reached).\n");
        exit(0);
    }

    $archiveCols = archive_columns($readPdo);

    $dateCol = mapped_archive_column($config, $archiveCols, 'dateTime');
    $unitsCol = mapped_archive_column($config, $archiveCols, 'usUnits');
    if ($dateCol === null || $unitsCol === null) {
        throw new RuntimeException('Missing mapped dateTime/usUnits fields.');
    }

    $mapped = [];
    foreach ($metricDefs as $field => $_def) {
        $col = mapped_archive_column($config, $archiveCols, $field);
        if ($col !== null) {
            $mapped[$field] = $col;
        }
    }
    if ($mapped === []) {
        throw new RuntimeException('No prediction metrics are mapped in archive.');
    }

    $latestSelect = [
        sprintf('%s AS dateTime', $dateCol),
        sprintf('%s AS usUnits', $unitsCol),
    ];
    foreach ($mapped as $field => $col) {
        $latestSelect[] = sprintf('%s AS %s', $col, $field);
    }

    $latestSql = sprintf(
        'SELECT %s FROM archive ORDER BY %s DESC LIMIT 1',
        implode(', ', $latestSelect),
        $dateCol
    );
    $latest = $readPdo->query($latestSql)->fetch();
    if (!is_array($latest)) {
        throw new RuntimeException('No archive rows found.');
    }

    $historySelect = [sprintf('%s AS dateTime', $dateCol)];
    foreach ($mapped as $field => $col) {
        $historySelect[] = sprintf('%s AS %s', $col, $field);
    }

    $historySql = sprintf(
        'SELECT %s
         FROM archive
         WHERE %s >= UNIX_TIMESTAMP(DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY))
         ORDER BY %s ASC',
        implode(', ', $historySelect),
        $dateCol,
        $dateCol
    );
    $rows = $readPdo->query($historySql)->fetchAll();

    $nowTs = (int) $latest['dateTime'];
    $units = unit_map((int) $latest['usUnits']);
    $wuTomorrowRange = wu_tomorrow_temp_range($readPdo, $config);

    $predictionRows = [];
    $generatedAt = gmdate('Y-m-d H:i:s');
    $runId = bin2hex(random_bytes(16));

    foreach ($mapped as $field => $_col) {
        $def = $metricDefs[$field];
        $unit = (string) ($units[$def['unitType']] ?? '');
        $current = isset($latest[$field]) && is_numeric($latest[$field]) ? (float) $latest[$field] : null;
        if ($current === null) {
            continue;
        }

        $recentPoints = [];
        $seasonalHistory = [];
        foreach ($rows as $row) {
            $v = $row[$field] ?? null;
            if (!is_numeric($v)) {
                continue;
            }
            $ts = (int) $row['dateTime'];
            $val = (float) $v;
            $seasonalHistory[] = ['ts' => $ts, 'val' => $val];
            if ($ts >= ($nowTs - (3 * 3600))) {
                $recentPoints[] = ['t' => $ts, 'v' => $val];
            }
        }

        $trend = regression_stats($recentPoints);
        $slope = (float) $trend['slope_per_hour'];
        $r2 = (float) $trend['r2'];
        $samples = (int) $trend['samples'];

        foreach ($horizons as $horizon) {
            $h = (int) $horizon;
            if ($h <= 0 || $h > 72) {
                continue;
            }

            $targetTs = $nowTs + ($h * 3600);
            $targetHour = (int) gmdate('G', $targetTs);
            $seasonal = seasonal_mean_for_hour($seasonalHistory, $targetHour);

            $localProjection = $current + ($slope * $h);
            $predicted = $localProjection;
            $method = 'local_trend_v1';

            if ($seasonal !== null) {
                $predicted = (0.45 * $localProjection) + (0.55 * $seasonal);
                $method = 'local_seasonal_blend_v1';
            }

            // Optional WU guardrails for longer temperature horizons when daily data exists.
            if (
                $field === 'outTemp'
                && $h >= 12
                && $wuTomorrowRange['min'] !== null
                && $wuTomorrowRange['max'] !== null
            ) {
                $predicted = max((float) $wuTomorrowRange['min'], min((float) $wuTomorrowRange['max'], $predicted));
                $method = 'hybrid_local_wu_daily_v1';
            }

            $predicted = clamp_metric($field, $predicted);
            $confidence = min(0.95, max(0.1, 0.25 + (0.5 * $r2) + min(0.2, $samples / 240.0)));

            $predictionRows[] = [
                'generated_at' => $generatedAt,
                'target_time' => gmdate('Y-m-d H:i:s', $targetTs),
                'metric' => $field,
                'unit' => $unit,
                'value_num' => $predicted,
                'confidence' => $confidence,
                'method' => $method,
                'details' => [
                    'label' => $def['label'],
                    'horizon_hours' => $h,
                    'current' => $current,
                    'slope_per_hour' => $slope,
                    'r2' => $r2,
                    'samples' => $samples,
                    'seasonal_mean' => $seasonal,
                ],
            ];
        }
    }

    if ($predictionRows === []) {
        throw new RuntimeException('No prediction rows generated.');
    }

    $written = prediction_cache_write($writePdo, $config, $runId, $predictionRows);
    fwrite(STDOUT, sprintf("Prediction cache refresh completed: run_id=%s rows=%d\n", $runId, $written));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Prediction refresh failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

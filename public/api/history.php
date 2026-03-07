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

$allowedFields = array_values(array_unique(array_merge(
    array_keys($config['field_map'] ?? []),
    ['rainHourly']
)));

$defaultFields = [
    'outTemp', 'dewpoint', 'appTemp', 'inTemp', 'inDewpoint',
    'outHumidity', 'inHumidity', 'windSpeed', 'windGust', 'windDir',
    'barometer', 'pressure', 'rainRate', 'rainHourly',
    'rain', 'rainDur', 'heatindex', 'windchill', 'humidex',
    'UV', 'radiation', 'solarAltitude', 'cloudbase', 'ET',
    'sunshineDur', 'windrun', 'pm2_5', 'lightning_strike_count',
    'windBatteryStatus', 'rainBatteryStatus', 'lightning_Batt', 'pm25_Batt1', 'inTempBatteryStatus',
];

$hours = isset($_GET['hours']) ? (int) $_GET['hours'] : (int) $config['history_default_hours'];
// Guardrails prevent unbounded queries from expensive client requests.
$hours = max(1, min($hours, (int) $config['history_max_hours']));
$bucketMinutes = isset($_GET['bucketMinutes']) ? (int) $_GET['bucketMinutes'] : 0;
$bucketMinutes = max(0, min($bucketMinutes, 24 * 60));
$endOffsetHours = isset($_GET['endOffsetHours']) ? (int) $_GET['endOffsetHours'] : 0;
$endOffsetHours = max(0, min($endOffsetHours, (int) $config['history_max_hours']));

$fields = $defaultFields;
if (isset($_GET['fields'])) {
    $requested = array_filter(array_map('trim', explode(',', (string) $_GET['fields'])));
    $requested = array_values(array_unique(array_intersect($requested, $allowedFields)));
    if ($requested !== []) {
        $fields = $requested;
    }
}

$includeRainHourly = in_array('rainHourly', $fields, true);
// `rainHourly` is derived from `rain` and does not directly map to a DB column.
$dbFields = array_values(array_filter($fields, static fn (string $field): bool => $field !== 'rainHourly' && $field !== 'dateTime' && $field !== 'usUnits'));

$aggregateMap = [
    // Totals/count-like fields should be summed per bucket; others use AVG.
    'rain' => 'SUM',
    'ET' => 'SUM',
    'windrun' => 'SUM',
    'rainDur' => 'SUM',
    'sunshineDur' => 'SUM',
    'lightning_strike_count' => 'SUM',
];

$nowTs = time();
$endTs = $nowTs - ($endOffsetHours * 3600);
$startTs = $endTs - ($hours * 3600);

try {
    $pdo = pdo_from_config($config);
    $columns = archive_columns($pdo);

    $dateTimeCol = mapped_archive_column($config, $columns, 'dateTime');
    $usUnitsCol = mapped_archive_column($config, $columns, 'usUnits');
    if ($dateTimeCol === null || $usUnitsCol === null) {
        json_response(['error' => 'Missing mapped dateTime/usUnits columns.'], 500);
    }

    $mapped = [];
    // Keep only mapped archive columns that physically exist in this database.
    foreach ($dbFields as $field) {
        $col = mapped_archive_column($config, $columns, $field);
        if ($col !== null) {
            $mapped[$field] = $col;
        }
    }

    $series = [];
    foreach ($fields as $field) {
        $series[$field] = [];
    }

    $rows = [];
    if ($mapped !== []) {
        if ($bucketMinutes > 0) {
            // For long windows we aggregate server-side so response size stays manageable.
            $expressions = [];
            foreach ($mapped as $field => $column) {
                $fn = $aggregateMap[$field] ?? 'AVG';
                $expressions[] = sprintf('%s(%s) AS %s', $fn, $column, $field);
            }
            $sql = sprintf(
                'SELECT MIN(%s) AS dateTime, MIN(%s) AS usUnits, %s
                 FROM archive
                 WHERE %s >= ? AND %s < ?
                 GROUP BY FLOOR(%s / (? * 60))
                 ORDER BY dateTime ASC',
                $dateTimeCol,
                $usUnitsCol,
                implode(', ', $expressions),
                $dateTimeCol,
                $dateTimeCol,
                $dateTimeCol
            );
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$startTs, $endTs, $bucketMinutes]);
        } else {
            $expressions = [];
            foreach ($mapped as $field => $column) {
                $expressions[] = sprintf('%s AS %s', $column, $field);
            }
            $sql = sprintf(
                'SELECT %s AS dateTime, %s AS usUnits, %s
                 FROM archive
                 WHERE %s >= ? AND %s < ?
                 ORDER BY dateTime ASC',
                $dateTimeCol,
                $usUnitsCol,
                implode(', ', $expressions),
                $dateTimeCol,
                $dateTimeCol
            );
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$startTs, $endTs]);
        }

        $rows = $stmt->fetchAll();
    }

    $usUnits = null;
    if ($rows !== []) {
        $usUnits = (int) $rows[0]['usUnits'];
        foreach ($rows as $row) {
            $x = (int) $row['dateTime'] * 1000;
            foreach (array_keys($mapped) as $field) {
                $value = $row[$field] ?? null;
                if ($value === null) {
                    continue;
                }
                $series[$field][] = ['x' => $x, 'y' => (float) $value];
            }
        }
    }

    if ($includeRainHourly) {
        $rainColumn = mapped_archive_column($config, $columns, 'rain');
        if ($rainColumn !== null) {
            // Derive per-hour rainfall totals regardless of display bucket size.
            $rainHourlySql = sprintf(
                'SELECT FLOOR(%s / 3600) * 3600 AS hour_ts, SUM(%s) AS rain_sum, MIN(%s) AS usUnits
                 FROM archive
                 WHERE %s >= ? AND %s < ?
                 GROUP BY hour_ts
                 ORDER BY hour_ts ASC',
                $dateTimeCol,
                $rainColumn,
                $usUnitsCol,
                $dateTimeCol,
                $dateTimeCol
            );
            $rainStmt = $pdo->prepare($rainHourlySql);
            $rainStmt->execute([$startTs, $endTs]);
            $hourRows = $rainStmt->fetchAll();

            foreach ($hourRows as $hourRow) {
                $series['rainHourly'][] = [
                    'x' => (int) $hourRow['hour_ts'] * 1000,
                    'y' => (float) ($hourRow['rain_sum'] ?? 0),
                ];
                if ($usUnits === null && $hourRow['usUnits'] !== null) {
                    $usUnits = (int) $hourRow['usUnits'];
                }
            }
        }
    }

    json_response([
        'hours' => $hours,
        'endOffsetHours' => $endOffsetHours,
        'fields' => $fields,
        'availableFields' => array_keys($mapped),
        'series' => $series,
        'usUnits' => $usUnits,
        'units' => $usUnits === null ? null : unit_map($usUnits),
        'bucketMinutes' => $bucketMinutes,
        'startTs' => $startTs,
        'endTs' => $endTs,
    ]);
} catch (Throwable $exception) {
    json_response([
        'error' => 'Failed to load history series.',
        'details' => $exception->getMessage(),
    ], 500);
}

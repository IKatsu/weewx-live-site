<?php

declare(strict_types=1);

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

$metricSpec = [
    'outTemp' => ['label' => 'Outside Temperature', 'unit' => 'temperature'],
    'inTemp' => ['label' => 'Inside Temperature', 'unit' => 'temperature'],
    'outHumidity' => ['label' => 'Outside Humidity', 'unit' => 'humidity'],
    'inHumidity' => ['label' => 'Inside Humidity', 'unit' => 'humidity'],
    'barometer' => ['label' => 'Barometer', 'unit' => 'pressure'],
    'pressure' => ['label' => 'Pressure', 'unit' => 'pressure'],
    'windSpeed' => ['label' => 'Wind Speed', 'unit' => 'wind'],
    'windGust' => ['label' => 'Wind Gust', 'unit' => 'wind'],
    'windDir' => ['label' => 'Wind Direction', 'unit' => 'degree'],
    'windrun' => ['label' => 'Wind Run', 'unit' => 'wind'],
    'rainRate' => ['label' => 'Rain Rate', 'unit' => 'rain_rate'],
    'rain' => ['label' => 'Rain Total', 'unit' => 'rain'],
    'rainDur' => ['label' => 'Rain Duration', 'unit' => 'seconds'],
    'radiation' => ['label' => 'Solar Radiation', 'unit' => 'radiation'],
    'UV' => ['label' => 'UV Index', 'unit' => 'uv'],
    'dewpoint' => ['label' => 'Dewpoint', 'unit' => 'temperature'],
    'inDewpoint' => ['label' => 'Inside Dewpoint', 'unit' => 'temperature'],
    'heatindex' => ['label' => 'Heat Index', 'unit' => 'temperature'],
    'windchill' => ['label' => 'Wind Chill', 'unit' => 'temperature'],
    'appTemp' => ['label' => 'Apparent Temperature', 'unit' => 'temperature'],
    'humidex' => ['label' => 'Humidex', 'unit' => 'temperature'],
    'cloudbase' => ['label' => 'Cloudbase', 'unit' => 'meters'],
    'ET' => ['label' => 'Evapotranspiration', 'unit' => 'rain'],
    'solarAltitude' => ['label' => 'Solar Altitude', 'unit' => 'degree'],
    'sunshineDur' => ['label' => 'Sunshine Duration', 'unit' => 'seconds'],
    'pm2_5' => ['label' => 'PM2.5', 'unit' => 'ugm3'],
    'lightning_strike_count' => ['label' => 'Lightning Strikes', 'unit' => 'count'],
    'windBatteryStatus' => ['label' => 'Wind Battery', 'unit' => 'voltage'],
    'rainBatteryStatus' => ['label' => 'Rain Battery', 'unit' => 'voltage'],
    'lightning_Batt' => ['label' => 'Lightning Battery', 'unit' => 'voltage'],
    'pm25_Batt1' => ['label' => 'PM2.5 Battery', 'unit' => 'voltage'],
    'inTempBatteryStatus' => ['label' => 'Indoor Temp Battery', 'unit' => 'voltage'],
];

$unitOverride = [
    'degree' => '°',
    'seconds' => 's',
    'meters' => 'm',
    'ugm3' => 'µg/m³',
    'count' => 'count',
    'voltage' => 'V',
];

try {
    $pdo = pdo_from_config($config);
    $columns = archive_columns($pdo);

    $dateTimeCol = mapped_archive_column($config, $columns, 'dateTime');
    $usUnitsCol = mapped_archive_column($config, $columns, 'usUnits');
    if ($dateTimeCol === null || $usUnitsCol === null) {
        json_response(['error' => 'Missing mapped dateTime/usUnits columns.'], 500);
    }

    $select = [
        sprintf('%s AS dateTime', $dateTimeCol),
        sprintf('%s AS usUnits', $usUnitsCol),
    ];

    $included = [];
    foreach (array_keys($metricSpec) as $field) {
        $col = mapped_archive_column($config, $columns, $field);
        if ($col === null) {
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

    $units = unit_map((int) $row['usUnits']);
    $metrics = [];
    foreach ($included as $field) {
        $spec = $metricSpec[$field];
        $unit = $unitOverride[$spec['unit']] ?? ($units[$spec['unit']] ?? '');
        $metrics[$field] = [
            'label' => $spec['label'],
            'value' => $row[$field] ?? null,
            'unit' => $unit,
        ];
    }

    json_response([
        'timestamp' => (int) $row['dateTime'],
        'updatedAtIso' => gmdate('c', (int) $row['dateTime']),
        'usUnits' => (int) $row['usUnits'],
        'metrics' => $metrics,
        'availableFields' => $included,
    ]);
} catch (Throwable $exception) {
    json_response([
        'error' => 'Failed to load latest conditions.',
        'details' => $exception->getMessage(),
    ], 500);
}

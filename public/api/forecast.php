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
require_once dirname($bootstrapPath) . '/forecast_cache.php';

$config = app_config();
$activeProviders = forecast_active_providers($config);

// Forecast API is cache-backed only; live HTTP calls happen via cron script.
if ($activeProviders === []) {
    json_response([
        'provider' => 'none',
        'dashboard' => [
            'next_hours' => [],
            'tomorrow' => null,
        ],
        'daily' => [],
        'message' => 'Forecast provider disabled',
    ]);
}

/**
 * @param array<string, array{hourly:?array, daily:?array}> $rowsByProvider
 */
function pick_provider(array $rowsByProvider, array $order): ?string
{
    foreach ($order as $p) {
        if (isset($rowsByProvider[$p])) {
            return $p;
        }
    }
    return null;
}

try {
    $pdo = pdo_from_config($config);
    $rowsByProvider = [];
    foreach ($activeProviders as $provider) {
        $rowsByProvider[$provider] = forecast_read_all($pdo, $config, $provider);
    }

    $f = (array) ($config['forecast'] ?? []);
    $preferredHourly = strtolower(trim((string) ($f['preferred_hourly_provider'] ?? '')));
    $preferredDaily = strtolower(trim((string) ($f['preferred_daily_provider'] ?? '')));
    $alertsProvider = strtolower(trim((string) ($f['alerts_provider'] ?? 'openweather')));

    $hourlyProvider = pick_provider($rowsByProvider, array_values(array_filter(array_merge([$preferredHourly], $activeProviders))));
    $dailyProvider = pick_provider($rowsByProvider, array_values(array_filter(array_merge([$preferredDaily], $activeProviders))));

    $mergedRows = [
        'hourly' => $hourlyProvider !== null ? ($rowsByProvider[$hourlyProvider]['hourly'] ?? null) : null,
        'daily' => $dailyProvider !== null ? ($rowsByProvider[$dailyProvider]['daily'] ?? null) : null,
    ];
    $payload = forecast_build_api_payload($config, $mergedRows);

    $alertsRow = forecast_read_dataset($pdo, $config, 'alerts', $alertsProvider);
    $alerts = [];
    if (is_array($alertsRow) && is_array($alertsRow['payload'] ?? null)) {
        $alerts = $alertsRow['payload'];
    }
    $payload['alerts'] = $alerts;
    $payload['sources'] = [
        'active' => $activeProviders,
        'selected' => [
            'hourly' => $hourlyProvider,
            'daily' => $dailyProvider,
            'alerts' => $alertsProvider,
        ],
        'available' => array_map(static function ($rowSet): array {
            $hourly = $rowSet['hourly'] ?? null;
            $daily = $rowSet['daily'] ?? null;
            return [
                'hourly' => is_array($hourly) ? [
                    'fetched_at' => $hourly['fetched_at'] ?? null,
                    'status' => $hourly['source_status'] ?? null,
                    'error' => $hourly['source_error'] ?? null,
                ] : null,
                'daily' => is_array($daily) ? [
                    'fetched_at' => $daily['fetched_at'] ?? null,
                    'status' => $daily['source_status'] ?? null,
                    'error' => $daily['source_error'] ?? null,
                ] : null,
            ];
        }, $rowsByProvider),
    ];

    if (count($activeProviders) > 1) {
        $payload['provider'] = 'combined';
    }
    json_response($payload);
} catch (Throwable $exception) {
    json_response([
        'error' => 'Failed to load cached forecast.',
        'details' => $exception->getMessage(),
    ], 500);
}

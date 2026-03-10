<?php

declare(strict_types=1);

// API entrypoints can run from local dev or mounted deploy paths.
putenv('PWS_BASE_DIR=' . dirname(__DIR__));

$srcCandidates = [
    dirname(__DIR__, 2) . '/src',
    dirname(__DIR__, 3) . '/src',
];

$bootstrapPath = null;
$predictionPath = null;
$forecastPath = null;
foreach ($srcCandidates as $candidate) {
    if ($bootstrapPath === null && is_file($candidate . '/bootstrap.php')) {
        $bootstrapPath = $candidate . '/bootstrap.php';
    }
    if ($predictionPath === null && is_file($candidate . '/prediction_cache.php')) {
        $predictionPath = $candidate . '/prediction_cache.php';
    }
    if ($forecastPath === null && is_file($candidate . '/forecast_cache.php')) {
        $forecastPath = $candidate . '/forecast_cache.php';
    }
}

if ($bootstrapPath === null || $predictionPath === null || $forecastPath === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unable to locate required src files']);
    exit;
}

require_once $bootstrapPath;
require_once $predictionPath;
require_once $forecastPath;

/**
 * @param array<string, array{hourly:?array, daily:?array}> $rowsByProvider
 */
function pick_provider_for_prediction(array $rowsByProvider, array $order): ?string
{
    foreach ($order as $provider) {
        if (isset($rowsByProvider[$provider]) && is_array($rowsByProvider[$provider]['hourly'] ?? null)) {
            return $provider;
        }
    }
    return null;
}

function nearest_hourly_row(array $hourlyRows, string $targetTime): ?array
{
    $targetTs = strtotime($targetTime . ' UTC');
    if ($targetTs === false) {
        return null;
    }

    $best = null;
    $bestDelta = PHP_INT_MAX;
    foreach ($hourlyRows as $row) {
        $rowTs = strtotime((string) ($row['time_local'] ?? ''));
        if ($rowTs === false) {
            continue;
        }
        $delta = abs($rowTs - $targetTs);
        if ($delta < $bestDelta) {
            $bestDelta = $delta;
            $best = $row;
        }
    }

    // Ignore rows that are too far away from the prediction target.
    return ($bestDelta <= 7200) ? $best : null;
}

function clamp_confidence(float $value): float
{
    return max(0.1, min(0.98, $value));
}

function unit_tolerance(string $metric, string $unit): float
{
    return match ($metric) {
        'outTemp' => str_contains($unit, 'F') ? 2.5 : 1.5,
        'windSpeed' => match (true) {
            str_contains($unit, 'km/h') => 4.0,
            str_contains($unit, 'mph') => 2.5,
            default => 1.2,
        },
        default => 0.0,
    };
}

function augment_prediction_confidence(array $row, ?array $forecastRow): array
{
    $base = (float) ($row['confidence'] ?? 0.0);
    $metric = (string) ($row['metric'] ?? '');
    $unit = (string) ($row['unit'] ?? '');
    $predicted = isset($row['value_num']) ? (float) $row['value_num'] : null;
    $display = $base;
    $support = ['source' => null, 'score' => null, 'note' => 'No hourly forecast comparison available'];

    if ($forecastRow === null || $predicted === null) {
        $row['display_confidence'] = clamp_confidence($display);
        $row['forecast_support'] = $support;
        return $row;
    }

    if ($metric === 'outTemp') {
        $forecastValue = isset($forecastRow['temperature']) ? (float) $forecastRow['temperature'] : null;
        if ($forecastValue !== null) {
            $tol = unit_tolerance($metric, $unit);
            $delta = abs($predicted - $forecastValue);
            $agreement = max(0.0, min(1.0, 1.0 - ($delta / max(0.001, $tol))));
            $display = clamp_confidence($base + (($agreement - 0.5) * 0.34));
            $support = [
                'source' => 'hourly_temperature',
                'score' => $agreement,
                'note' => sprintf('Forecast temp %.2f %s', $forecastValue, $unit),
            ];
        }
    } elseif ($metric === 'windSpeed') {
        $forecastValue = isset($forecastRow['wind_speed']) ? (float) $forecastRow['wind_speed'] : null;
        if ($forecastValue !== null) {
            $tol = unit_tolerance($metric, $unit);
            $delta = abs($predicted - $forecastValue);
            $agreement = max(0.0, min(1.0, 1.0 - ($delta / max(0.001, $tol))));
            $display = clamp_confidence($base + (($agreement - 0.5) * 0.28));
            $support = [
                'source' => 'hourly_wind_speed',
                'score' => $agreement,
                'note' => sprintf('Forecast wind %.2f %s', $forecastValue, $unit),
            ];
        }
    } elseif ($metric === 'rainRate') {
        $precipChance = isset($forecastRow['precip_chance']) ? (float) $forecastRow['precip_chance'] : null;
        if ($precipChance !== null) {
            $agreement = ($predicted > 0.0)
                ? max(0.0, min(1.0, $precipChance / 100.0))
                : max(0.0, min(1.0, 1.0 - ($precipChance / 100.0)));
            $display = clamp_confidence($base + (($agreement - 0.5) * 0.22));
            $support = [
                'source' => 'hourly_precip_chance',
                'score' => $agreement,
                'note' => sprintf('Forecast rain chance %.0f%%', $precipChance),
            ];
        }
    }

    $row['display_confidence'] = $display;
    $row['forecast_support'] = $support;
    return $row;
}

try {
    $config = app_config();
    $pdo = pdo_from_config($config);
    $rows = prediction_cache_read_latest($pdo, $config);

    if ($rows === []) {
        json_response([
            'error' => 'No predictions cached yet. Run src/cli/build_predictions.php first.',
            'items' => [],
        ], 404);
    }

    $runId = (string) ($rows[0]['run_id'] ?? '');
    $generatedAt = (string) ($rows[0]['generated_at'] ?? '');
    $activeProviders = forecast_active_providers($config);
    $rowsByProvider = [];
    foreach ($activeProviders as $provider) {
        $rowsByProvider[$provider] = forecast_read_all($pdo, $config, $provider);
    }
    $forecastCfg = (array) ($config['forecast'] ?? []);
    $preferredHourly = strtolower(trim((string) ($forecastCfg['preferred_hourly_provider'] ?? '')));
    $hourlyProvider = pick_provider_for_prediction(
        $rowsByProvider,
        array_values(array_filter(array_merge([$preferredHourly], $activeProviders)))
    );
    $hourlyForecastRows = [];
    if ($hourlyProvider !== null) {
        $hourlyPayload = (array) (($rowsByProvider[$hourlyProvider]['hourly']['payload'] ?? null) ?: []);
        $hourlyForecastRows = forecast_parse_hourly_for_dashboard($hourlyPayload, 48);
    }

    $augmentedRows = [];
    foreach ($rows as $row) {
        $forecastRow = nearest_hourly_row($hourlyForecastRows, (string) ($row['target_time'] ?? ''));
        $augmentedRows[] = augment_prediction_confidence($row, $forecastRow);
    }

    json_response([
        'run_id' => $runId,
        'generated_at' => $generatedAt,
        'generated_at_iso' => $generatedAt !== '' ? gmdate('c', strtotime($generatedAt . ' UTC')) : null,
        'forecast_confidence_source' => $hourlyProvider,
        'items' => $augmentedRows,
    ]);
} catch (Throwable $exception) {
    json_response([
        'error' => 'Failed to read prediction cache.',
        'details' => $exception->getMessage(),
    ], 500);
}

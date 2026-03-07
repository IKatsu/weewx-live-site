<?php

declare(strict_types=1);

/**
 * Cron entrypoint: pull WU/TWC forecast data and store it in local DB cache.
 *
 * Usage:
 *   php src/cli/fetch_wu_forecast.php
 *   php src/cli/fetch_wu_forecast.php --force
 */

putenv('PWS_BASE_DIR=' . dirname(__DIR__, 2) . '/public');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../forecast_cache.php';

$config = app_config();

if (($config['forecast']['provider'] ?? 'none') !== 'wu') {
    fwrite(STDERR, "Forecast provider is not set to 'wu'; skipping.\n");
    exit(0);
}

$force = in_array('--force', $argv, true);

try {
    $pdo = pdo_from_config($config);

    if (!$force && !forecast_should_refresh($pdo, $config)) {
        fwrite(STDOUT, "WU cache refresh skipped (interval not reached).\n");
        exit(0);
    }

    $hourly = wu_fetch_hourly($config);
    forecast_write_dataset($pdo, $config, 'hourly', $hourly, 200, '');

    $daily = wu_fetch_daily($config);
    forecast_write_dataset($pdo, $config, 'daily', $daily, 200, '');

    fwrite(STDOUT, "WU forecast cache refresh completed.\n");
    exit(0);
} catch (Throwable $exception) {
    // Keep last good cache intact; write failure to stderr for cron logs.
    fwrite(STDERR, 'WU refresh failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

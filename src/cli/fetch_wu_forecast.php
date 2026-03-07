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
    $writerDb = (array) ($config['forecast_writer_db'] ?? []);
    $writerUser = (string) ($writerDb['username'] ?? '');
    if ($writerUser !== '') {
        // Forecast refresh can run with a dedicated DB writer account.
        $writerConfig = $config;
        $writerConfig['db'] = [
            'host' => (string) ($writerDb['host'] ?? $config['db']['host']),
            'port' => (int) ($writerDb['port'] ?? $config['db']['port']),
            'database' => (string) ($writerDb['database'] ?? $config['db']['database']),
            'username' => $writerUser,
            'password' => (string) ($writerDb['password'] ?? ''),
        ];
        $pdo = pdo_from_config($writerConfig);
    } else {
        $pdo = pdo_from_config($config);
    }

    if (!$force && !forecast_should_refresh($pdo, $config)) {
        fwrite(STDOUT, "WU cache refresh skipped (interval not reached).\n");
        exit(0);
    }

    $hourlyEnabled = (bool) ($config['forecast']['wu_hourly_enabled'] ?? true);
    $hourlyWarnings = [];
    if ($hourlyEnabled) {
        try {
            $hourly = wu_fetch_hourly($config);
            forecast_write_dataset($pdo, $config, 'hourly', $hourly, 200, '');
        } catch (Throwable $hourlyError) {
            // Daily forecast remains usable even when hourly endpoint is not entitled.
            $hourlyWarnings[] = $hourlyError->getMessage();
            forecast_write_dataset($pdo, $config, 'hourly', [], 401, $hourlyError->getMessage());
        }
    }

    $daily = wu_fetch_daily($config);
    forecast_write_dataset($pdo, $config, 'daily', $daily, 200, '');

    if ($hourlyWarnings !== []) {
        fwrite(STDOUT, "WU forecast cache refresh completed (daily only; hourly unavailable).\n");
        foreach ($hourlyWarnings as $warning) {
            fwrite(STDOUT, "Hourly warning: {$warning}\n");
        }
    } else {
        fwrite(STDOUT, "WU forecast cache refresh completed.\n");
    }
    exit(0);
} catch (Throwable $exception) {
    // Keep last good cache intact; write failure to stderr for cron logs.
    fwrite(STDERR, 'WU refresh failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

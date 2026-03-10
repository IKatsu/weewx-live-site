<?php

declare(strict_types=1);

/**
 * Cron entrypoint: pull forecast data from configured provider and store in DB cache.
 *
 * Usage:
 *   php src/cli/fetch_forecast.php
 *   php src/cli/fetch_forecast.php --force
 */

putenv('PWS_BASE_DIR=' . dirname(__DIR__, 2) . '/public');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../forecast_cache.php';

function forecast_writer_pdo(array $config): PDO
{
    $writerDb = (array) ($config['forecast_writer_db'] ?? []);
    $writerUser = (string) ($writerDb['username'] ?? '');
    if ($writerUser === '') {
        return pdo_from_config($config);
    }

    $writerConfig = $config;
    $writerConfig['db'] = [
        'host' => (string) ($writerDb['host'] ?? $config['db']['host']),
        'port' => (int) ($writerDb['port'] ?? $config['db']['port']),
        'database' => (string) ($writerDb['database'] ?? $config['db']['database']),
        'username' => $writerUser,
        'password' => (string) ($writerDb['password'] ?? ''),
    ];
    return pdo_from_config($writerConfig);
}

function cardinal_from_degrees(?float $deg): string
{
    if ($deg === null) {
        return '';
    }
    $dirs = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
    $idx = (int) round((fmod(($deg + 360.0), 360.0) / 22.5)) % 16;
    return $dirs[$idx];
}

function local_iso(int $ts, string $tz): string
{
    $dt = new DateTimeImmutable('@' . $ts);
    return $dt->setTimezone(new DateTimeZone($tz))->format('Y-m-d\TH:i:sP');
}

function day_name_local(int $ts, string $tz): string
{
    $dt = new DateTimeImmutable('@' . $ts);
    return $dt->setTimezone(new DateTimeZone($tz))->format('l');
}

function owm_coords(array $config): array
{
    $f = (array) ($config['forecast'] ?? []);
    $cfgLat = (float) ($f['owm_latitude'] ?? 0.0);
    $cfgLon = (float) ($f['owm_longitude'] ?? 0.0);
    $lat = (abs($cfgLat) < 0.000001) ? (float) ($config['location']['latitude'] ?? 0.0) : $cfgLat;
    $lon = (abs($cfgLon) < 0.000001) ? (float) ($config['location']['longitude'] ?? 0.0) : $cfgLon;
    return [$lat, $lon];
}

function owm_api_key(array $config): string
{
    $key = (string) ($config['forecast']['owm_api_key'] ?? '');
    if ($key === '' || strtoupper($key) === 'CHANGE_ME') {
        throw new RuntimeException('Missing forecast.owm_api_key');
    }
    return $key;
}

function owm_endpoint_url(array $config, string $path, array $extra = []): string
{
    [$lat, $lon] = owm_coords($config);
    $f = (array) ($config['forecast'] ?? []);
    $base = rtrim((string) ($f['owm_base_url'] ?? 'https://api.openweathermap.org'), '/');
    $units = (string) ($f['owm_units'] ?? 'metric');
    $lang = (string) ($f['owm_language'] ?? 'en');

    $query = array_merge([
        'lat' => sprintf('%.6f', $lat),
        'lon' => sprintf('%.6f', $lon),
        'appid' => owm_api_key($config),
        'units' => $units,
        'lang' => $lang,
    ], $extra);

    return $base . $path . '?' . http_build_query($query);
}

function owm_fetch_onecall(array $config): array
{
    // One Call 3.0 (paid plan): single call includes hourly + daily.
    $url = owm_endpoint_url($config, '/data/3.0/onecall', [
        'exclude' => 'minutely,alerts',
    ]);
    return forecast_http_get_json($url);
}

function owm_fetch_free_5d(array $config): array
{
    // Free plan endpoint: 5 day / 3 hour forecast.
    $url = owm_endpoint_url($config, '/data/2.5/forecast');
    return forecast_http_get_json($url);
}

function owm_normalize_from_onecall(array $config, array $payload): array
{
    $tz = (string) ($config['location']['timezone'] ?? 'UTC');
    $hourlyRows = (array) ($payload['hourly'] ?? []);
    $dailyRows = (array) ($payload['daily'] ?? []);

    $hourly = [
        'validTimeLocal' => [],
        'temperature' => [],
        'wxPhraseLong' => [],
        'iconCode' => [],
        'precipChance' => [],
        'windSpeed' => [],
        'windDirectionCardinal' => [],
    ];

    foreach ($hourlyRows as $row) {
        if (!is_array($row) || !isset($row['dt'])) {
            continue;
        }
        $ts = (int) $row['dt'];
        $weather0 = is_array($row['weather'][0] ?? null) ? $row['weather'][0] : [];
        $hourly['validTimeLocal'][] = local_iso($ts, $tz);
        $hourly['temperature'][] = isset($row['temp']) ? (float) $row['temp'] : null;
        $hourly['wxPhraseLong'][] = (string) ($weather0['description'] ?? '');
        $hourly['iconCode'][] = (string) ($weather0['icon'] ?? '');
        $hourly['precipChance'][] = isset($row['pop']) ? (float) $row['pop'] * 100.0 : null;
        $hourly['windSpeed'][] = isset($row['wind_speed']) ? (float) $row['wind_speed'] : null;
        $hourly['windDirectionCardinal'][] = isset($row['wind_deg']) ? cardinal_from_degrees((float) $row['wind_deg']) : '';
    }

    $daily = [
        'validTimeLocal' => [],
        'dayOfWeek' => [],
        'temperatureMax' => [],
        'temperatureMin' => [],
        'narrative' => [],
        'sunriseTimeLocal' => [],
        'sunsetTimeLocal' => [],
        'moonriseTimeLocal' => [],
        'moonsetTimeLocal' => [],
        'moonPhase' => [],
        'moonPhaseCode' => [],
    ];

    foreach ($dailyRows as $row) {
        if (!is_array($row) || !isset($row['dt'])) {
            continue;
        }
        $ts = (int) $row['dt'];
        $weather0 = is_array($row['weather'][0] ?? null) ? $row['weather'][0] : [];
        $daily['validTimeLocal'][] = local_iso($ts, $tz);
        $daily['dayOfWeek'][] = day_name_local($ts, $tz);
        $daily['temperatureMax'][] = isset($row['temp']['max']) ? (float) $row['temp']['max'] : null;
        $daily['temperatureMin'][] = isset($row['temp']['min']) ? (float) $row['temp']['min'] : null;
        $daily['narrative'][] = (string) ($weather0['description'] ?? '');
        $daily['sunriseTimeLocal'][] = isset($row['sunrise']) ? local_iso((int) $row['sunrise'], $tz) : '';
        $daily['sunsetTimeLocal'][] = isset($row['sunset']) ? local_iso((int) $row['sunset'], $tz) : '';
        $daily['moonriseTimeLocal'][] = isset($row['moonrise']) ? local_iso((int) $row['moonrise'], $tz) : '';
        $daily['moonsetTimeLocal'][] = isset($row['moonset']) ? local_iso((int) $row['moonset'], $tz) : '';
        $daily['moonPhase'][] = isset($row['moon_phase']) ? (string) $row['moon_phase'] : '';
        $daily['moonPhaseCode'][] = null;
    }

    return ['hourly' => $hourly, 'daily' => $daily];
}

function owm_normalize_from_free_5d(array $config, array $payload): array
{
    $tz = (string) ($config['location']['timezone'] ?? 'UTC');
    $list = (array) ($payload['list'] ?? []);

    $hourly = [
        'validTimeLocal' => [],
        'temperature' => [],
        'wxPhraseLong' => [],
        'iconCode' => [],
        'precipChance' => [],
        'windSpeed' => [],
        'windDirectionCardinal' => [],
    ];

    $byDay = [];

    foreach ($list as $row) {
        if (!is_array($row) || !isset($row['dt'])) {
            continue;
        }
        $ts = (int) $row['dt'];
        $weather0 = is_array($row['weather'][0] ?? null) ? $row['weather'][0] : [];
        $local = local_iso($ts, $tz);
        $hourly['validTimeLocal'][] = $local;
        $hourly['temperature'][] = isset($row['main']['temp']) ? (float) $row['main']['temp'] : null;
        $hourly['wxPhraseLong'][] = (string) ($weather0['description'] ?? '');
        $hourly['iconCode'][] = (string) ($weather0['icon'] ?? '');
        $hourly['precipChance'][] = isset($row['pop']) ? (float) $row['pop'] * 100.0 : null;
        $hourly['windSpeed'][] = isset($row['wind']['speed']) ? (float) $row['wind']['speed'] : null;
        $hourly['windDirectionCardinal'][] = isset($row['wind']['deg']) ? cardinal_from_degrees((float) $row['wind']['deg']) : '';

        $dayKey = substr($local, 0, 10);
        if (!isset($byDay[$dayKey])) {
            $byDay[$dayKey] = [];
        }
        $byDay[$dayKey][] = [
            'ts' => $ts,
            'temp' => isset($row['main']['temp']) ? (float) $row['main']['temp'] : null,
            'phrase' => (string) ($weather0['description'] ?? ''),
        ];
    }

    ksort($byDay);
    $daily = [
        'validTimeLocal' => [],
        'dayOfWeek' => [],
        'temperatureMax' => [],
        'temperatureMin' => [],
        'narrative' => [],
        'sunriseTimeLocal' => [],
        'sunsetTimeLocal' => [],
        'moonriseTimeLocal' => [],
        'moonsetTimeLocal' => [],
        'moonPhase' => [],
        'moonPhaseCode' => [],
    ];

    foreach ($byDay as $dayKey => $rows) {
        $temps = array_values(array_filter(array_map(static fn($r) => $r['temp'], $rows), static fn($v) => is_float($v) || is_int($v)));
        $mid = $rows[0];
        $targetNoonDistance = PHP_INT_MAX;
        foreach ($rows as $r) {
            $hour = (int) gmdate('G', (int) $r['ts']);
            $d = abs(12 - $hour);
            if ($d < $targetNoonDistance) {
                $targetNoonDistance = $d;
                $mid = $r;
            }
        }

        $dayTs = isset($rows[0]['ts']) ? (int) $rows[0]['ts'] : time();
        $daily['validTimeLocal'][] = $dayKey . 'T12:00:00';
        $daily['dayOfWeek'][] = day_name_local($dayTs, $tz);
        $daily['temperatureMax'][] = $temps !== [] ? max($temps) : null;
        $daily['temperatureMin'][] = $temps !== [] ? min($temps) : null;
        $daily['narrative'][] = (string) ($mid['phrase'] ?? '');
        $daily['sunriseTimeLocal'][] = '';
        $daily['sunsetTimeLocal'][] = '';
        $daily['moonriseTimeLocal'][] = '';
        $daily['moonsetTimeLocal'][] = '';
        $daily['moonPhase'][] = '';
        $daily['moonPhaseCode'][] = null;
    }

    return ['hourly' => $hourly, 'daily' => $daily];
}

$config = app_config();
$force = in_array('--force', $argv, true);
$provider = forecast_provider($config);

if ($provider === 'none') {
    fwrite(STDOUT, "Forecast provider is set to 'none'; skipping.\n");
    exit(0);
}

try {
    $pdo = forecast_writer_pdo($config);

    if (!$force && !forecast_should_refresh($pdo, $config, $provider)) {
        fwrite(STDOUT, "Forecast cache refresh skipped (interval not reached).\n");
        exit(0);
    }

    if ($provider === 'wu') {
        $hourlyEnabled = (bool) ($config['forecast']['wu_hourly_enabled'] ?? true);
        $hourlyWarnings = [];
        if ($hourlyEnabled) {
            try {
                $hourly = wu_fetch_hourly($config);
                forecast_write_dataset($pdo, $config, 'hourly', $hourly, 200, '', $provider);
            } catch (Throwable $hourlyError) {
                $hourlyWarnings[] = $hourlyError->getMessage();
                forecast_write_dataset($pdo, $config, 'hourly', [], 401, $hourlyError->getMessage(), $provider);
            }
        }

        $daily = wu_fetch_daily($config);
        forecast_write_dataset($pdo, $config, 'daily', $daily, 200, '', $provider);

        if ($hourlyWarnings !== []) {
            fwrite(STDOUT, "WU forecast cache refresh completed (daily only; hourly unavailable).\n");
            foreach ($hourlyWarnings as $warning) {
                fwrite(STDOUT, "Hourly warning: {$warning}\n");
            }
        } else {
            fwrite(STDOUT, "WU forecast cache refresh completed.\n");
        }
        exit(0);
    }

    if ($provider === 'openweather') {
        $mode = strtolower(trim((string) ($config['forecast']['owm_mode'] ?? 'onecall_3')));
        if ($mode === 'free_5d') {
            $raw = owm_fetch_free_5d($config);
            $normalized = owm_normalize_from_free_5d($config, $raw);
        } else {
            $raw = owm_fetch_onecall($config);
            $normalized = owm_normalize_from_onecall($config, $raw);
        }

        forecast_write_dataset($pdo, $config, 'hourly', (array) ($normalized['hourly'] ?? []), 200, '', $provider);
        forecast_write_dataset($pdo, $config, 'daily', (array) ($normalized['daily'] ?? []), 200, '', $provider);

        fwrite(STDOUT, "OpenWeather forecast cache refresh completed (mode={$mode}).\n");
        exit(0);
    }

    throw new RuntimeException('Unsupported forecast provider: ' . $provider);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Forecast refresh failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

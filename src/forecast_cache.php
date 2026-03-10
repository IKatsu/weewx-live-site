<?php

declare(strict_types=1);

/**
 * Weather.com/WU forecast endpoint returns arrays by field; this helper safely reads an index.
 */
function arr_idx(array $array, int $idx, mixed $default = null): mixed
{
    return array_key_exists($idx, $array) ? $array[$idx] : $default;
}

function forecast_provider(array $config): string
{
    $provider = strtolower(trim((string) ($config['forecast']['provider'] ?? 'none')));
    return $provider === '' ? 'none' : $provider;
}

/**
 * @return list<string>
 */
function forecast_active_providers(array $config): array
{
    $f = (array) ($config['forecast'] ?? []);
    $rawList = $f['providers'] ?? [];
    $allowed = ['wu', 'openweather'];
    $out = [];

    if (is_string($rawList) && $rawList !== '') {
        $rawList = array_map('trim', explode(',', $rawList));
    }

    if (is_array($rawList) && $rawList !== []) {
        foreach ($rawList as $item) {
            $p = strtolower(trim((string) $item));
            if ($p !== '' && in_array($p, $allowed, true) && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
    }

    if ($out !== []) {
        return $out;
    }

    $single = forecast_provider($config);
    if (in_array($single, $allowed, true)) {
        return [$single];
    }
    return [];
}

function forecast_cache_table(array $config): string
{
    // Table name is configurable but must stay a plain SQL identifier.
    $table = (string) ($config['forecast']['cache_table'] ?? 'pws_wu_forecast_cache');
    return is_safe_identifier($table) ? $table : 'pws_wu_forecast_cache';
}

function forecast_now_utc(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function forecast_http_get_json(string $url, int $timeoutSeconds = 10): array
{
    // Prefer cURL when available to expose HTTP status and richer error details.
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'pws-live-site/1.0',
        ]);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            throw new RuntimeException('Forecast request failed: ' . ($error !== '' ? $error : 'empty response'));
        }
        if ($code >= 400) {
            $decodedError = json_decode((string) $raw, true);
            $errorSummary = '';
            if (is_array($decodedError)) {
                $parts = [];
                if (isset($decodedError['error']['code'])) {
                    $parts[] = 'code=' . (string) $decodedError['error']['code'];
                }
                if (isset($decodedError['error']['message'])) {
                    $parts[] = 'message=' . (string) $decodedError['error']['message'];
                }
                if ($parts !== []) {
                    $errorSummary = ' (' . implode(', ', $parts) . ')';
                }
            } else {
                $trimmed = trim((string) $raw);
                if ($trimmed !== '') {
                    $snippet = preg_replace('/\s+/', ' ', $trimmed);
                    if (is_string($snippet) && $snippet !== '') {
                        $errorSummary = ' (body=' . substr($snippet, 0, 220) . ')';
                    }
                }
            }
            throw new RuntimeException('Forecast request failed with HTTP ' . $code . $errorSummary);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('WU response is not valid JSON');
        }

        return $decoded;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "Accept: application/json\r\nUser-Agent: pws-live-site/1.0\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('Forecast request failed via file_get_contents');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('WU response is not valid JSON');
    }

    return $decoded;
}

function wu_endpoint_url(array $config, string $path, array $extraParams = []): string
{
    $forecast = (array) ($config['forecast'] ?? []);
    $base = rtrim((string) ($forecast['wu_base_url'] ?? 'https://api.weather.com'), '/');
    $apiKey = (string) ($forecast['wu_api_key'] ?? '');
    $units = (string) ($forecast['wu_units'] ?? 'm');
    $lang = (string) ($forecast['wu_language'] ?? 'en-US');

    if ($apiKey === '' || strtoupper($apiKey) === 'CHANGE_ME') {
        throw new RuntimeException('Missing forecast.wu_api_key');
    }

    $cfgLat = (float) ($forecast['wu_latitude'] ?? 0.0);
    $cfgLon = (float) ($forecast['wu_longitude'] ?? 0.0);
    // 0.0 is treated as "inherit from location.*" for easier local config.
    $lat = (abs($cfgLat) < 0.000001) ? (float) ($config['location']['latitude'] ?? 0.0) : $cfgLat;
    $lon = (abs($cfgLon) < 0.000001) ? (float) ($config['location']['longitude'] ?? 0.0) : $cfgLon;
    $geocode = sprintf('%.6f,%.6f', $lat, $lon);

    $query = array_merge([
        'geocode' => $geocode,
        'format' => 'json',
        'units' => $units,
        'language' => $lang,
        'apiKey' => $apiKey,
    ], $extraParams);

    return $base . $path . '?' . http_build_query($query);
}

function wu_fetch_hourly(array $config): array
{
    $forecast = (array) ($config['forecast'] ?? []);
    $duration = trim((string) ($forecast['wu_hourly_duration'] ?? '2day'));
    if ($duration === '') {
        $duration = '2day';
    }
    $path = '/v3/wx/forecast/hourly/' . $duration;
    return forecast_http_get_json(wu_endpoint_url($config, $path));
}

function wu_fetch_daily(array $config): array
{
    $forecast = (array) ($config['forecast'] ?? []);
    $duration = max(3, (int) ($forecast['wu_daily_duration_days'] ?? 10));
    $path = '/v3/wx/forecast/daily/' . $duration . 'day';
    return forecast_http_get_json(wu_endpoint_url($config, $path));
}

function forecast_read_dataset(PDO $pdo, array $config, string $dataset, ?string $provider = null): ?array
{
    // Each dataset (hourly/daily) is cached in one row keyed by provider+dataset.
    $provider = $provider !== null ? strtolower($provider) : forecast_provider($config);
    $table = forecast_cache_table($config);
    $sql = "SELECT dataset, payload_json, fetched_at, expires_at, source_status, source_error
            FROM {$table}
            WHERE provider = :provider AND dataset = :dataset
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':provider' => $provider,
        ':dataset' => $dataset,
    ]);

    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    $payload = json_decode((string) $row['payload_json'], true);
    if (!is_array($payload)) {
        return null;
    }

    return [
        'dataset' => (string) $row['dataset'],
        'payload' => $payload,
        'fetched_at' => (string) $row['fetched_at'],
        'expires_at' => (string) $row['expires_at'],
        'source_status' => (int) $row['source_status'],
        'source_error' => (string) $row['source_error'],
    ];
}

function forecast_read_all(PDO $pdo, array $config, ?string $provider = null): array
{
    $provider = $provider !== null ? strtolower($provider) : forecast_provider($config);
    return [
        'hourly' => forecast_read_dataset($pdo, $config, 'hourly', $provider),
        'daily' => forecast_read_dataset($pdo, $config, 'daily', $provider),
    ];
}

function forecast_last_fetch_time(PDO $pdo, array $config, ?string $provider = null): ?DateTimeImmutable
{
    $provider = $provider !== null ? strtolower($provider) : forecast_provider($config);
    $table = forecast_cache_table($config);
    $sql = "SELECT MAX(fetched_at) AS fetched_at FROM {$table} WHERE provider = :provider";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':provider' => $provider]);

    $value = (string) ($stmt->fetchColumn() ?: '');
    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return null;
    }
}

function forecast_should_refresh(PDO $pdo, array $config, ?string $provider = null): bool
{
    // Refresh policy is time-based so dashboard/API reads never trigger vendor calls.
    $forecast = (array) ($config['forecast'] ?? []);
    $interval = max(60, (int) ($forecast['refresh_interval_seconds'] ?? 900));
    $last = forecast_last_fetch_time($pdo, $config, $provider);
    if ($last === null) {
        return true;
    }

    $age = forecast_now_utc()->getTimestamp() - $last->getTimestamp();
    return $age >= $interval;
}

function forecast_write_dataset(PDO $pdo, array $config, string $dataset, array $payload, int $sourceStatus = 200, string $sourceError = '', ?string $provider = null): void
{
    $provider = $provider !== null ? strtolower($provider) : forecast_provider($config);
    $forecast = (array) ($config['forecast'] ?? []);
    $ttl = max(60, (int) ($forecast['cache_ttl_seconds'] ?? 900));
    $now = forecast_now_utc();
    $expires = $now->modify('+' . $ttl . ' seconds');
    $table = forecast_cache_table($config);

    $sql = "INSERT INTO {$table}
            (provider, dataset, location_key, payload_json, fetched_at, expires_at, source_status, source_error)
            VALUES (:provider, :dataset, :location_key, :payload_json, :fetched_at, :expires_at, :source_status, :source_error)
            ON DUPLICATE KEY UPDATE
                payload_json = VALUES(payload_json),
                fetched_at = VALUES(fetched_at),
                expires_at = VALUES(expires_at),
                source_status = VALUES(source_status),
                source_error = VALUES(source_error),
                updated_at = CURRENT_TIMESTAMP";

    if ($provider === 'openweather') {
        $cfgLat = (float) ($forecast['owm_latitude'] ?? 0.0);
        $cfgLon = (float) ($forecast['owm_longitude'] ?? 0.0);
    } else {
        $cfgLat = (float) ($forecast['wu_latitude'] ?? 0.0);
        $cfgLon = (float) ($forecast['wu_longitude'] ?? 0.0);
    }
    $lat = (abs($cfgLat) < 0.000001) ? (float) ($config['location']['latitude'] ?? 0.0) : $cfgLat;
    $lon = (abs($cfgLon) < 0.000001) ? (float) ($config['location']['longitude'] ?? 0.0) : $cfgLon;
    $locationKey = sprintf('%.4f,%.4f', $lat, $lon);

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':provider' => $provider,
        ':dataset' => $dataset,
        ':location_key' => $locationKey,
        ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':fetched_at' => $now->format('Y-m-d H:i:s'),
        ':expires_at' => $expires->format('Y-m-d H:i:s'),
        ':source_status' => $sourceStatus,
        ':source_error' => $sourceError,
    ]);
}

function forecast_parse_hourly_for_dashboard(array $hourlyPayload, int $takeHours = 5): array
{
    $times = (array) ($hourlyPayload['validTimeLocal'] ?? []);
    $temps = (array) ($hourlyPayload['temperature'] ?? []);
    $phrases = (array) ($hourlyPayload['wxPhraseLong'] ?? []);
    $icons = (array) ($hourlyPayload['iconCode'] ?? []);
    $precip = (array) ($hourlyPayload['precipChance'] ?? []);
    $windSpeed = (array) ($hourlyPayload['windSpeed'] ?? []);
    $windDirCard = (array) ($hourlyPayload['windDirectionCardinal'] ?? []);

    $rows = [];
    $nowTs = time();
    for ($i = 0, $n = count($times); $i < $n; $i++) {
        $local = (string) arr_idx($times, $i, '');
        if ($local === '') {
            continue;
        }

        $ts = strtotime($local);
        // Skip clearly stale slots to keep "next hours" focused on near-future values.
        if ($ts !== false && $ts + 1800 < $nowTs) {
            continue;
        }

        $rows[] = [
            'time_local' => $local,
            'temperature' => arr_idx($temps, $i),
            'phrase' => arr_idx($phrases, $i, ''),
            'icon_code' => arr_idx($icons, $i),
            'precip_chance' => arr_idx($precip, $i),
            'wind_speed' => arr_idx($windSpeed, $i),
            'wind_direction_cardinal' => arr_idx($windDirCard, $i, ''),
        ];

        if (count($rows) >= max(1, $takeHours)) {
            break;
        }
    }

    return $rows;
}

function forecast_parse_tomorrow(array $dailyPayload, string $timezone = 'UTC'): ?array
{
    $valid = (array) ($dailyPayload['validTimeLocal'] ?? []);
    $dayOfWeek = (array) ($dailyPayload['dayOfWeek'] ?? []);
    $tempMax = (array) ($dailyPayload['temperatureMax'] ?? []);
    $tempMin = (array) ($dailyPayload['temperatureMin'] ?? []);
    $narrative = (array) ($dailyPayload['narrative'] ?? []);
    $sunrise = (array) ($dailyPayload['sunriseTimeLocal'] ?? []);
    $sunset = (array) ($dailyPayload['sunsetTimeLocal'] ?? []);
    $moonrise = (array) ($dailyPayload['moonriseTimeLocal'] ?? []);
    $moonset = (array) ($dailyPayload['moonsetTimeLocal'] ?? []);
    $moonPhase = (array) ($dailyPayload['moonPhase'] ?? []);
    $moonPhaseCode = (array) ($dailyPayload['moonPhaseCode'] ?? []);

    $tz = new DateTimeZone($timezone);
    $tomorrow = (new DateTimeImmutable('now', $tz))->modify('+1 day')->format('Y-m-d');
    $idx = null;

    for ($i = 0, $n = count($valid); $i < $n; $i++) {
        $local = (string) arr_idx($valid, $i, '');
        if ($local !== '') {
            try {
                $localDate = (new DateTimeImmutable($local))->setTimezone($tz)->format('Y-m-d');
                if ($localDate === $tomorrow) {
                    $idx = $i;
                    break;
                }
            } catch (Throwable) {
                // Ignore parse failures and continue scanning.
            }
        }
    }

    // Fallback to second daily row when exact local-date match is not available.
    if ($idx === null && count($valid) > 1) {
        $idx = 1;
    }

    if ($idx === null) {
        return null;
    }

    return [
        'date_local' => (string) arr_idx($valid, $idx, ''),
        'day_of_week' => (string) arr_idx($dayOfWeek, $idx, ''),
        'temp_max' => arr_idx($tempMax, $idx),
        'temp_min' => arr_idx($tempMin, $idx),
        'narrative' => (string) arr_idx($narrative, $idx, ''),
        'sunrise_local' => (string) arr_idx($sunrise, $idx, ''),
        'sunset_local' => (string) arr_idx($sunset, $idx, ''),
        'moonrise_local' => (string) arr_idx($moonrise, $idx, ''),
        'moonset_local' => (string) arr_idx($moonset, $idx, ''),
        'moon_phase' => (string) arr_idx($moonPhase, $idx, ''),
        'moon_phase_code' => arr_idx($moonPhaseCode, $idx),
    ];
}

function forecast_parse_daily_rows(array $dailyPayload): array
{
    $valid = (array) ($dailyPayload['validTimeLocal'] ?? []);
    $dayOfWeek = (array) ($dailyPayload['dayOfWeek'] ?? []);
    $tempMax = (array) ($dailyPayload['temperatureMax'] ?? []);
    $tempMin = (array) ($dailyPayload['temperatureMin'] ?? []);
    $narrative = (array) ($dailyPayload['narrative'] ?? []);

    $rows = [];
    for ($i = 0, $n = count($valid); $i < $n; $i++) {
        $rows[] = [
            'date_local' => (string) arr_idx($valid, $i, ''),
            'day_of_week' => (string) arr_idx($dayOfWeek, $i, ''),
            'temp_max' => arr_idx($tempMax, $i),
            'temp_min' => arr_idx($tempMin, $i),
            'narrative' => (string) arr_idx($narrative, $i, ''),
        ];
    }

    return $rows;
}

function forecast_build_api_payload(array $config, array $cachedRows): array
{
    $hourlyRow = $cachedRows['hourly'] ?? null;
    $dailyRow = $cachedRows['daily'] ?? null;

    $hourlyPayload = is_array($hourlyRow['payload'] ?? null) ? $hourlyRow['payload'] : [];
    $dailyPayload = is_array($dailyRow['payload'] ?? null) ? $dailyRow['payload'] : [];

    $dashboardHours = max(1, (int) ($config['forecast']['dashboard_hours'] ?? 5));

    $hourly = forecast_parse_hourly_for_dashboard($hourlyPayload, $dashboardHours);
    $tz = (string) ($config['location']['timezone'] ?? 'UTC');
    $tomorrow = forecast_parse_tomorrow($dailyPayload, $tz);

    return [
        'provider' => (string) ($config['forecast']['provider'] ?? 'none'),
        'cache' => [
            'hourly' => is_array($hourlyRow) ? [
                'fetched_at' => $hourlyRow['fetched_at'],
                'expires_at' => $hourlyRow['expires_at'],
                'status' => $hourlyRow['source_status'],
                'error' => $hourlyRow['source_error'],
            ] : null,
            'daily' => is_array($dailyRow) ? [
                'fetched_at' => $dailyRow['fetched_at'],
                'expires_at' => $dailyRow['expires_at'],
                'status' => $dailyRow['source_status'],
                'error' => $dailyRow['source_error'],
            ] : null,
        ],
        'dashboard' => [
            'next_hours' => $hourly,
            'tomorrow' => $tomorrow,
        ],
        // Dedicated forecast page uses this for longer-range display.
        'daily' => forecast_parse_daily_rows($dailyPayload),
    ];
}

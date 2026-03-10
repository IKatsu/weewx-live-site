<?php

declare(strict_types=1);

function env_value(string $key, string $default): string
{
    // Empty env vars should not erase required defaults.
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function env_bool(string $key, bool $default): bool
{
    $raw = env_value($key, $default ? '1' : '0');
    return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
}

function array_merge_deep(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
            $base[$key] = array_merge_deep($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }

    return $base;
}

function load_local_config(): array
{
    // Defaults are always present; local config selectively overrides and holds secrets.
    $defaultsPath = __DIR__ . '/config.defaults.php';
    if (!is_file($defaultsPath)) {
        throw new RuntimeException('Missing src/config.defaults.php');
    }

    $base = require $defaultsPath;
    if (!is_array($base)) {
        throw new RuntimeException('src/config.defaults.php must return an array');
    }

    $localPath = __DIR__ . '/config.local.php';
    if (!is_file($localPath)) {
        return $base;
    }

    $local = require $localPath;
    if (!is_array($local)) {
        throw new RuntimeException('src/config.local.php must return an array');
    }

    return array_merge_deep($base, $local);
}

function resolve_base_dir(string $baseValue): string
{
    // Entry points set PWS_BASE_DIR to their own __DIR__, so '__DIR__'
    // in config always maps to the served web root for that entry point.
    $entryBaseDir = env_value('PWS_BASE_DIR', dirname(__DIR__));
    if ($baseValue === '__DIR__') {
        return $entryBaseDir;
    }

    if ($baseValue !== '' && $baseValue[0] === '/') {
        return $baseValue;
    }

    return rtrim($entryBaseDir, '/') . '/' . $baseValue;
}

function resolve_relative_path(string $path, string $baseDir): string
{
    if ($path === '') {
        return $baseDir;
    }

    if ($path[0] === '/') {
        return $path;
    }

    return rtrim($baseDir, '/') . '/' . $path;
}

function resolve_plotly_js_asset(array $uiCfg, string $baseDir): string
{
    $configured = trim((string) ($uiCfg['plotly_js'] ?? 'auto'));

    // Allow explicit pinning (or disabling with an empty string) from local config.
    if ($configured !== '' && strtolower($configured) !== 'auto') {
        return $configured;
    }

    // Vendor path is resolved from configured public base_dir.
    $vendorDir = rtrim($baseDir, '/') . '/assets/vendor';
    if (!is_dir($vendorDir)) {
        return '';
    }

    // Auto mode: pick the highest versioned "plotly-X.Y.Z.min.js" dropped in vendor.
    $candidates = glob($vendorDir . '/plotly-*.min.js') ?: [];
    if ($candidates !== []) {
        usort($candidates, static function (string $a, string $b): int {
            $ma = [];
            $mb = [];
            preg_match('/plotly-([0-9A-Za-z.\-+_]+)\.min\.js$/', basename($a), $ma);
            preg_match('/plotly-([0-9A-Za-z.\-+_]+)\.min\.js$/', basename($b), $mb);
            $va = $ma[1] ?? '0';
            $vb = $mb[1] ?? '0';
            return version_compare($va, $vb);
        });
        return 'assets/vendor/' . basename((string) end($candidates));
    }

    // Compatibility fallback for manual vendor file naming.
    if (is_file($vendorDir . '/plotly.min.js')) {
        return 'assets/vendor/plotly.min.js';
    }

    return '';
}

function app_config(): array
{
    $local = load_local_config();

    $pathsCfg = (array) ($local['paths'] ?? []);
    $uiCfg = (array) ($local['ui'] ?? []);
    $dbCfg = (array) ($local['db'] ?? []);
    $mqttCfg = (array) ($local['mqtt'] ?? []);
    $historyCfg = (array) ($local['history'] ?? []);
    $apiCfg = (array) ($local['api'] ?? []);
    $locationCfg = (array) ($local['location'] ?? []);
    $forecastCfg = (array) ($local['forecast'] ?? []);
    $predictionCfg = (array) ($local['prediction'] ?? []);
    $forecastWriterDbCfg = (array) ($local['forecast_writer_db'] ?? []);
    $historyWriterDbCfg = (array) ($local['history_writer_db'] ?? []);
    $securityCfg = (array) ($local['security'] ?? []);

    $baseDir = resolve_base_dir((string) ($pathsCfg['base_dir'] ?? '__DIR__'));
    $srcDir = resolve_relative_path((string) ($pathsCfg['src_dir'] ?? '../src'), $baseDir);

    return [
        'paths' => [
            'base_dir' => $baseDir,
            'src_dir' => $srcDir,
        ],
        'db' => [
            'host' => env_value('PWS_DB_HOST', (string) ($dbCfg['host'] ?? '127.0.0.1')),
            'port' => (int) env_value('PWS_DB_PORT', (string) ($dbCfg['port'] ?? '3306')),
            'database' => env_value('PWS_DB_NAME', (string) ($dbCfg['database'] ?? 'weather')),
            'username' => env_value('PWS_DB_USER', (string) ($dbCfg['username'] ?? 'weather')),
            'password' => env_value('PWS_DB_PASS', (string) ($dbCfg['password'] ?? '')),
        ],
        'forecast_writer_db' => [
            'host' => env_value('PWS_FORECAST_DB_HOST', (string) ($forecastWriterDbCfg['host'] ?? ($dbCfg['host'] ?? '127.0.0.1'))),
            'port' => (int) env_value('PWS_FORECAST_DB_PORT', (string) ($forecastWriterDbCfg['port'] ?? ($dbCfg['port'] ?? '3306'))),
            'database' => env_value('PWS_FORECAST_DB_NAME', (string) ($forecastWriterDbCfg['database'] ?? ($dbCfg['database'] ?? 'weather'))),
            'username' => env_value('PWS_FORECAST_DB_USER', (string) ($forecastWriterDbCfg['username'] ?? '')),
            'password' => env_value('PWS_FORECAST_DB_PASS', (string) ($forecastWriterDbCfg['password'] ?? '')),
        ],
        'history_writer_db' => [
            'host' => env_value('PWS_HISTORY_DB_HOST', (string) ($historyWriterDbCfg['host'] ?? ($dbCfg['host'] ?? '127.0.0.1'))),
            'port' => (int) env_value('PWS_HISTORY_DB_PORT', (string) ($historyWriterDbCfg['port'] ?? ($dbCfg['port'] ?? '3306'))),
            'database' => env_value('PWS_HISTORY_DB_NAME', (string) ($historyWriterDbCfg['database'] ?? ($dbCfg['database'] ?? 'weather'))),
            'username' => env_value('PWS_HISTORY_DB_USER', (string) ($historyWriterDbCfg['username'] ?? '')),
            'password' => env_value('PWS_HISTORY_DB_PASS', (string) ($historyWriterDbCfg['password'] ?? '')),
        ],
        'mqtt' => [
            'enabled' => env_bool('PWS_MQTT_ENABLED', (bool) ($mqttCfg['enabled'] ?? true)),
            'expose_password' => env_bool('PWS_MQTT_EXPOSE_PASSWORD', (bool) ($mqttCfg['expose_password'] ?? false)),
            'url' => env_value('PWS_MQTT_URL', (string) ($mqttCfg['url'] ?? 'ws://127.0.0.1:9001/mqtt')),
            'username' => env_value('PWS_MQTT_USER', (string) ($mqttCfg['username'] ?? '')),
            'password' => env_value('PWS_MQTT_PASS', (string) ($mqttCfg['password'] ?? '')),
            'topic' => env_value('PWS_MQTT_TOPIC', (string) ($mqttCfg['topic'] ?? 'weewx/#')),
        ],
        'api' => [
            'dump_enabled' => env_bool('PWS_API_DUMP_ENABLED', (bool) ($apiCfg['dump_enabled'] ?? true)),
            'dump_default_rows' => max(1, (int) env_value('PWS_API_DUMP_DEFAULT_ROWS', (string) ($apiCfg['dump_default_rows'] ?? 1000))),
            'dump_max_rows' => max(1, (int) env_value('PWS_API_DUMP_MAX_ROWS', (string) ($apiCfg['dump_max_rows'] ?? 10000))),
            'dump_token' => env_value('PWS_API_DUMP_TOKEN', (string) ($apiCfg['dump_token'] ?? '')),
        ],
        'ui' => [
            'css' => [
                'base' => (string) ($uiCfg['css_base'] ?? 'assets/css/base.css'),
                'themes' => (array) ($uiCfg['css_themes'] ?? ['bright' => 'assets/css/theme-bright.css']),
                'default_theme' => (string) ($uiCfg['default_theme'] ?? 'bright'),
                'custom' => (string) ($uiCfg['css_custom'] ?? ''),
            ],
            'time' => [
                'format' => in_array((string) ($uiCfg['time_format'] ?? '24h'), ['12h', '24h'], true)
                    ? (string) ($uiCfg['time_format'] ?? '24h')
                    : '24h',
            ],
            'poll_interval_seconds' => max(5, (int) env_value('PWS_UI_POLL_INTERVAL_SECONDS', (string) ($uiCfg['poll_interval_seconds'] ?? 15))),
            'mqtt_reconnect_delay_ms' => max(1000, (int) ($uiCfg['mqtt_reconnect_delay_ms'] ?? 10000)),
            'plotly' => [
                'js' => resolve_plotly_js_asset($uiCfg, $baseDir),
                'wind_rose' => (bool) ($uiCfg['plotly_wind_rose'] ?? false),
            ],
            'layout' => (array) ($uiCfg['layout'] ?? []),
            'graphs' => (array) ($uiCfg['graphs'] ?? []),
            'battery_status_labels' => (array) ($uiCfg['battery_status_labels'] ?? []),
            'sensor_thresholds' => (array) ($uiCfg['sensor_thresholds'] ?? []),
        ],
        'field_map' => (array) ($local['field_map'] ?? []),
        'location' => [
            'latitude' => (float) ($locationCfg['latitude'] ?? 0.0),
            'longitude' => (float) ($locationCfg['longitude'] ?? 0.0),
            'timezone' => (string) ($locationCfg['timezone'] ?? 'UTC'),
        ],
        'forecast' => [
            'provider' => env_value('PWS_FORECAST_PROVIDER', (string) ($forecastCfg['provider'] ?? 'none')),
            'providers' => array_values(array_filter(array_map(
                static fn($p) => strtolower(trim((string) $p)),
                (array) ($forecastCfg['providers'] ?? [])
            ), static fn($p) => in_array($p, ['wu', 'openweather'], true))),
            'preferred_hourly_provider' => (string) ($forecastCfg['preferred_hourly_provider'] ?? ''),
            'preferred_daily_provider' => (string) ($forecastCfg['preferred_daily_provider'] ?? ''),
            'alerts_provider' => (string) ($forecastCfg['alerts_provider'] ?? 'openweather'),
            'cache_ttl_seconds' => (int) ($forecastCfg['cache_ttl_seconds'] ?? 900),
            'hourly_cache_ttl_seconds' => (int) ($forecastCfg['hourly_cache_ttl_seconds'] ?? 14400),
            'alerts_cache_ttl_seconds' => (int) ($forecastCfg['alerts_cache_ttl_seconds'] ?? 1800),
            'refresh_interval_seconds' => (int) ($forecastCfg['refresh_interval_seconds'] ?? 1800),
            'cache_table' => (string) ($forecastCfg['cache_table'] ?? 'pws_wu_forecast_cache'),
            'dashboard_hours' => (int) ($forecastCfg['dashboard_hours'] ?? 5),
            'wu_base_url' => (string) ($forecastCfg['wu_base_url'] ?? 'https://api.weather.com'),
            'wu_api_key' => env_value('PWS_WU_API_KEY', (string) ($forecastCfg['wu_api_key'] ?? '')),
            'wu_hourly_enabled' => (bool) ($forecastCfg['wu_hourly_enabled'] ?? true),
            'wu_units' => (string) ($forecastCfg['wu_units'] ?? 'm'),
            'wu_language' => (string) ($forecastCfg['wu_language'] ?? 'en-US'),
            'wu_latitude' => (float) ($forecastCfg['wu_latitude'] ?? 0.0),
            'wu_longitude' => (float) ($forecastCfg['wu_longitude'] ?? 0.0),
            'wu_hourly_duration' => (string) ($forecastCfg['wu_hourly_duration'] ?? '2day'),
            'wu_daily_duration_days' => (int) ($forecastCfg['wu_daily_duration_days'] ?? 10),
            'owm_mode' => (string) ($forecastCfg['owm_mode'] ?? 'onecall_3'),
            'owm_base_url' => (string) ($forecastCfg['owm_base_url'] ?? 'https://api.openweathermap.org'),
            'owm_api_key' => env_value('PWS_OWM_API_KEY', (string) ($forecastCfg['owm_api_key'] ?? '')),
            'owm_units' => (string) ($forecastCfg['owm_units'] ?? 'metric'),
            'owm_language' => (string) ($forecastCfg['owm_language'] ?? 'en'),
            'owm_latitude' => (float) ($forecastCfg['owm_latitude'] ?? 0.0),
            'owm_longitude' => (float) ($forecastCfg['owm_longitude'] ?? 0.0),
        ],
        'prediction' => [
            'cache_table' => (string) ($predictionCfg['cache_table'] ?? 'pws_prediction_cache'),
            'refresh_interval_seconds' => max(300, (int) ($predictionCfg['refresh_interval_seconds'] ?? 1800)),
            'horizons_hours' => array_values(array_filter(array_map(
                static fn($h) => (int) $h,
                (array) ($predictionCfg['horizons_hours'] ?? [1, 3, 6, 12, 24])
            ), static fn($h) => $h > 0 && $h <= 72)),
        ],
        'history' => [
            'default_hours' => max(1, (int) env_value('PWS_HISTORY_DEFAULT_HOURS', (string) ($historyCfg['default_hours'] ?? '24'))),
            'max_hours' => max(1, (int) env_value('PWS_HISTORY_MAX_HOURS', (string) ($historyCfg['max_hours'] ?? (24 * 366)))),
            'summary_table' => (string) ($historyCfg['summary_table'] ?? 'pws_history_monthly_summary'),
            'lookback_years' => max(1, (int) ($historyCfg['lookback_years'] ?? 3)),
        ],
        'optional_metric_groups' => (array) ($local['optional_metric_groups'] ?? []),
        'security' => [
            'enable_headers' => env_bool('PWS_SECURITY_ENABLE_HEADERS', (bool) ($securityCfg['enable_headers'] ?? true)),
            'content_security_policy' => (string) ($securityCfg['content_security_policy'] ?? ''),
            'referrer_policy' => (string) ($securityCfg['referrer_policy'] ?? 'strict-origin-when-cross-origin'),
            'frame_options' => (string) ($securityCfg['frame_options'] ?? 'SAMEORIGIN'),
            'permissions_policy' => (string) ($securityCfg['permissions_policy'] ?? 'geolocation=(), microphone=(), camera=()'),
        ],
        'history_default_hours' => max(1, (int) env_value('PWS_HISTORY_DEFAULT_HOURS', (string) ($historyCfg['default_hours'] ?? '24'))),
        'history_max_hours' => max(1, (int) env_value('PWS_HISTORY_MAX_HOURS', (string) ($historyCfg['max_hours'] ?? (24 * 366)))),
    ];
}

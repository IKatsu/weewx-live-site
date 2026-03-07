<?php

declare(strict_types=1);

function env_value(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
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

function app_config(): array
{
    $local = load_local_config();

    $pathsCfg = (array) ($local['paths'] ?? []);
    $uiCfg = (array) ($local['ui'] ?? []);
    $dbCfg = (array) ($local['db'] ?? []);
    $mqttCfg = (array) ($local['mqtt'] ?? []);
    $historyCfg = (array) ($local['history'] ?? []);
    $locationCfg = (array) ($local['location'] ?? []);
    $forecastCfg = (array) ($local['forecast'] ?? []);
    $forecastWriterDbCfg = (array) ($local['forecast_writer_db'] ?? []);

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
        'mqtt' => [
            'url' => env_value('PWS_MQTT_URL', (string) ($mqttCfg['url'] ?? 'ws://127.0.0.1:9001/mqtt')),
            'username' => env_value('PWS_MQTT_USER', (string) ($mqttCfg['username'] ?? '')),
            'password' => env_value('PWS_MQTT_PASS', (string) ($mqttCfg['password'] ?? '')),
            'topic' => env_value('PWS_MQTT_TOPIC', (string) ($mqttCfg['topic'] ?? 'weewx/#')),
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
            'plotly' => [
                'js' => (string) ($uiCfg['plotly_js'] ?? ''),
                'wind_rose' => (bool) ($uiCfg['plotly_wind_rose'] ?? false),
            ],
            'layout' => (array) ($uiCfg['layout'] ?? []),
            'graphs' => (array) ($uiCfg['graphs'] ?? []),
        ],
        'field_map' => (array) ($local['field_map'] ?? []),
        'location' => [
            'latitude' => (float) ($locationCfg['latitude'] ?? 0.0),
            'longitude' => (float) ($locationCfg['longitude'] ?? 0.0),
            'timezone' => (string) ($locationCfg['timezone'] ?? 'UTC'),
        ],
        'forecast' => [
            'provider' => (string) ($forecastCfg['provider'] ?? 'none'),
            'cache_ttl_seconds' => (int) ($forecastCfg['cache_ttl_seconds'] ?? 900),
            'refresh_interval_seconds' => (int) ($forecastCfg['refresh_interval_seconds'] ?? 900),
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
        ],
        'history_default_hours' => (int) env_value('PWS_HISTORY_DEFAULT_HOURS', (string) ($historyCfg['default_hours'] ?? '24')),
        'history_max_hours' => (int) env_value('PWS_HISTORY_MAX_HOURS', (string) ($historyCfg['max_hours'] ?? (24 * 366))),
    ];
}

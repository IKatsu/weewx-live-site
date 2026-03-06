<?php

declare(strict_types=1);

function env_value(string $key, string $default): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function resolve_path(string $path, string $baseDir): string
{
    if ($path === '') {
        return $baseDir;
    }

    if ($path[0] === '/') {
        return $path;
    }

    return rtrim($baseDir, '/') . '/' . $path;
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
    $examplePath = __DIR__ . '/config.example.php';
    if (!is_file($examplePath)) {
        throw new RuntimeException('Missing src/config.example.php');
    }

    $base = require $examplePath;
    if (!is_array($base)) {
        throw new RuntimeException('src/config.example.php must return an array');
    }

    $localPath = __DIR__ . '/config.local.php';
    if (is_file($localPath)) {
        $local = require $localPath;
        if (!is_array($local)) {
            throw new RuntimeException('src/config.local.php must return an array');
        }
        return array_merge_deep($base, $local);
    }

    return $base;
}

function app_config(): array
{
    $local = load_local_config();

    $baseDir = env_value('PWS_BASE_DIR', dirname(__DIR__));
    $pathsCfg = $local['paths'] ?? [];
    $uiCfg = $local['ui'] ?? [];
    $dbCfg = $local['db'] ?? [];
    $mqttCfg = $local['mqtt'] ?? [];
    $historyCfg = $local['history'] ?? [];

    $paths = [
        'base_dir' => resolve_path((string) ($pathsCfg['base_dir'] ?? '..'), $baseDir),
    ];
    $paths['src_dir'] = resolve_path((string) ($pathsCfg['src_dir'] ?? 'src'), $paths['base_dir']);
    $paths['public_dir'] = resolve_path((string) ($pathsCfg['public_dir'] ?? 'public'), $paths['base_dir']);
    $paths['docs_dir'] = resolve_path((string) ($pathsCfg['docs_dir'] ?? 'docs'), $paths['base_dir']);
    $paths['cache_dir'] = resolve_path((string) ($pathsCfg['cache_dir'] ?? 'var/cache/pws-live-site'), $paths['base_dir']);

    return [
        'paths' => $paths,
        'db' => [
            'host' => env_value('PWS_DB_HOST', (string) ($dbCfg['host'] ?? '127.0.0.1')),
            'port' => (int) env_value('PWS_DB_PORT', (string) ($dbCfg['port'] ?? '3306')),
            'database' => env_value('PWS_DB_NAME', (string) ($dbCfg['database'] ?? 'weather')),
            'username' => env_value('PWS_DB_USER', (string) ($dbCfg['username'] ?? 'weather')),
            'password' => env_value('PWS_DB_PASS', (string) ($dbCfg['password'] ?? '')),
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
            'plotly' => [
                'js' => (string) ($uiCfg['plotly_js'] ?? ''),
                'wind_rose' => (bool) ($uiCfg['plotly_wind_rose'] ?? false),
            ],
            'graphs' => (array) ($uiCfg['graphs'] ?? []),
        ],
        'field_map' => (array) ($local['field_map'] ?? []),
        'history_default_hours' => (int) env_value('PWS_HISTORY_DEFAULT_HOURS', (string) ($historyCfg['default_hours'] ?? '24')),
        'history_max_hours' => (int) env_value('PWS_HISTORY_MAX_HOURS', (string) ($historyCfg['max_hours'] ?? (24 * 366))),
    ];
}

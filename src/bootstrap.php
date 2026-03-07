<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function json_response(array $payload, int $statusCode = 200): void
{
    // Central JSON response helper so all API endpoints behave consistently.
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pdo_from_config(array $config): PDO
{
    // Read-only API traffic and cron scripts both use this DSN builder.
    $db = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'],
        $db['port'],
        $db['database']
    );

    return new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function unit_map(int $usUnits): array
{
    // Keep units in one place so dashboard/history API responses stay aligned.
    if ($usUnits === 1) {
        return [
            'temperature' => '°F',
            'pressure' => 'inHg',
            'wind' => 'mph',
            'rain_rate' => 'in/hr',
            'rain' => 'in',
            'humidity' => '%',
            'uv' => 'index',
            'radiation' => 'W/m²',
        ];
    }

    if ($usUnits === 17) {
        return [
            'temperature' => '°C',
            'pressure' => 'hPa',
            'wind' => 'm/s',
            'rain_rate' => 'mm/hr',
            'rain' => 'mm',
            'humidity' => '%',
            'uv' => 'index',
            'radiation' => 'W/m²',
        ];
    }

    return [
        'temperature' => '°C',
        'pressure' => 'hPa',
        'wind' => 'km/h',
        'rain_rate' => 'mm/hr',
        'rain' => 'mm',
        'humidity' => '%',
        'uv' => 'index',
        'radiation' => 'W/m²',
    ];
}

function is_safe_identifier(string $identifier): bool
{
    // Restrict SQL identifiers to plain column/table names only.
    return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
}

function archive_columns(PDO $pdo): array
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    // Cache archive column metadata per-connection to avoid repeated DESCRIBE/SHOW calls.
    $rows = $pdo->query('SHOW COLUMNS FROM archive')->fetchAll();
    $columns = [];
    foreach ($rows as $row) {
        $columns[(string) $row['Field']] = true;
    }

    $cache[$key] = $columns;
    return $columns;
}

function mapped_archive_column(array $config, array $archiveColumns, string $field): ?string
{
    // Field names are configurable; we still validate identifiers to prevent SQL injection.
    $mapped = $config['field_map'][$field] ?? null;
    if (!is_string($mapped) || $mapped === '') {
        return null;
    }
    if (!is_safe_identifier($mapped)) {
        return null;
    }
    if (!isset($archiveColumns[$mapped])) {
        return null;
    }
    return $mapped;
}

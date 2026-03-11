<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function send_security_headers(?array $config = null): void
{
    $cfg = $config ?? app_config();
    $security = (array) ($cfg['security'] ?? []);
    if (($security['enable_headers'] ?? true) !== true) {
        return;
    }

    header('X-Content-Type-Options: nosniff');

    $referrerPolicy = trim((string) ($security['referrer_policy'] ?? ''));
    if ($referrerPolicy !== '') {
        header('Referrer-Policy: ' . $referrerPolicy);
    }

    $frameOptions = trim((string) ($security['frame_options'] ?? ''));
    if ($frameOptions !== '') {
        header('X-Frame-Options: ' . $frameOptions);
    }

    $permissionsPolicy = trim((string) ($security['permissions_policy'] ?? ''));
    if ($permissionsPolicy !== '') {
        header('Permissions-Policy: ' . $permissionsPolicy);
    }

    $csp = trim((string) ($security['content_security_policy'] ?? ''));
    if ($csp !== '') {
        header('Content-Security-Policy: ' . $csp);
    }
}

function json_response(array $payload, int $statusCode = 200): void
{
    // Central JSON response helper so all API endpoints behave consistently.
    send_security_headers();
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
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

    if ($usUnits === 16 || $usUnits === 17) {
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

function ip_in_cidr(string $ip, string $cidr): bool
{
    $parts = explode('/', $cidr, 2);
    $network = trim($parts[0] ?? '');
    $prefix = isset($parts[1]) ? (int) $parts[1] : null;
    if ($network === '' || !filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($network, FILTER_VALIDATE_IP)) {
        return false;
    }

    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($network);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }

    $maxBits = strlen($ipBin) * 8;
    if ($prefix === null) {
        $prefix = $maxBits;
    }
    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }

    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;

    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
}

function client_ip_allowed(array $allowedCidrs, ?string $remoteAddr): bool
{
    $ip = trim((string) $remoteAddr);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    foreach ($allowedCidrs as $cidr) {
        if (!is_string($cidr) || trim($cidr) === '') {
            continue;
        }
        if (ip_in_cidr($ip, trim($cidr))) {
            return true;
        }
    }

    return false;
}

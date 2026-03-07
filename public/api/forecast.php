<?php

declare(strict_types=1);

// API entrypoints can run from local dev or mounted deploy paths.
putenv('PWS_BASE_DIR=' . dirname(__DIR__));

$srcCandidates = [
    dirname(__DIR__, 2) . '/src',
    dirname(__DIR__, 3) . '/src',
];

$bootstrapPath = null;
foreach ($srcCandidates as $candidate) {
    if (is_file($candidate . '/bootstrap.php')) {
        $bootstrapPath = $candidate . '/bootstrap.php';
        break;
    }
}

if ($bootstrapPath === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unable to locate src/bootstrap.php']);
    exit;
}

require_once $bootstrapPath;
require_once dirname($bootstrapPath) . '/forecast_cache.php';

$config = app_config();

// Forecast API is cache-backed only; live HTTP calls happen via cron script.
if (($config['forecast']['provider'] ?? 'none') !== 'wu') {
    json_response([
        'provider' => $config['forecast']['provider'] ?? 'none',
        'dashboard' => [
            'next_hours' => [],
            'tomorrow' => null,
        ],
        'daily' => [],
        'message' => 'Forecast provider disabled',
    ]);
}

try {
    $pdo = pdo_from_config($config);
    $rows = forecast_read_all($pdo, $config);
    $payload = forecast_build_api_payload($config, $rows);
    json_response($payload);
} catch (Throwable $exception) {
    json_response([
        'error' => 'Failed to load cached forecast.',
        'details' => $exception->getMessage(),
    ], 500);
}

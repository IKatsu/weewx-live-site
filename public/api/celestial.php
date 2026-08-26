<?php

declare(strict_types=1);

// API entrypoints can run from local dev or mounted deploy paths.
putenv('PWS_BASE_DIR=' . dirname(__DIR__));

$srcCandidates = [
    dirname(__DIR__, 2) . '/src',
    dirname(__DIR__, 3) . '/src',
];

$bootstrapPath = null;
$cachePath = null;
foreach ($srcCandidates as $candidate) {
    if ($bootstrapPath === null && is_file($candidate . '/bootstrap.php')) {
        $bootstrapPath = $candidate . '/bootstrap.php';
    }
    if ($cachePath === null && is_file($candidate . '/celestial_cache.php')) {
        $cachePath = $candidate . '/celestial_cache.php';
    }
}

if ($bootstrapPath === null || $cachePath === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unable to locate required src files']);
    exit;
}

require_once $bootstrapPath;
require_once $cachePath;

$dataset = strtolower(trim((string) ($_GET['dataset'] ?? 'daily')));
if (!in_array($dataset, ['daily', 'monthly', 'yearly'], true)) {
    $dataset = 'daily';
}
$periodKey = trim((string) ($_GET['periodKey'] ?? ''));

try {
    $config = app_config();
    $pdo = pdo_from_config($config);
    $row = celestial_cache_read($pdo, $config, $dataset, $periodKey !== '' ? $periodKey : null);
    if ($row === null) {
        json_response([
            'error' => 'No celestial cache found. Run src/cli/build_celestial_cache.php first.',
            'dataset' => $dataset,
            'periodKey' => $periodKey,
        ], 404);
    }

    json_response($row);
} catch (Throwable $exception) {
    $payload = [
        'error' => 'Failed to load celestial cache.',
    ];
    if (isset($config) && (($config['debug']['enabled'] ?? false) === true)) {
        $payload['details'] = $exception->getMessage();
    }
    json_response($payload, 500);
}

<?php

declare(strict_types=1);

putenv('PWS_BASE_DIR=' . dirname(__DIR__));

$srcCandidates = [
    dirname(__DIR__, 2) . '/src',
    dirname(__DIR__, 3) . '/src',
];

$bootstrapPath = null;
$catalogPath = null;
foreach ($srcCandidates as $candidate) {
    if ($bootstrapPath === null && is_file($candidate . '/bootstrap.php')) {
        $bootstrapPath = $candidate . '/bootstrap.php';
    }
    if ($catalogPath === null && is_file($candidate . '/celestial_catalog.php')) {
        $catalogPath = $candidate . '/celestial_catalog.php';
    }
}

if ($bootstrapPath === null || $catalogPath === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unable to locate required src files']);
    exit;
}

require_once $bootstrapPath;
require_once $catalogPath;

try {
    $config = app_config();
    if (($config['celestial']['catalog']['enabled'] ?? true) !== true) {
        json_response(['error' => 'Celestial catalog is disabled.'], 404);
    }

    $time = isset($_GET['time']) ? (int) $_GET['time'] : time();
    $now = time();
    if ($time < $now - 86400 || $time > $now + 86400) {
        $time = $now;
    }

    $pdo = pdo_from_config($config);
    json_response(celestial_catalog_rows($pdo, $config, $time));
} catch (Throwable $exception) {
    $payload = ['error' => 'Failed to load celestial catalog projection.'];
    if (isset($config) && (($config['debug']['enabled'] ?? false) === true)) {
        $payload['details'] = $exception->getMessage();
    }
    json_response($payload, 500);
}

<?php

declare(strict_types=1);

// API entrypoints can run from local dev or mounted deploy paths.
putenv('PWS_BASE_DIR=' . dirname(__DIR__));

$srcCandidates = [
    dirname(__DIR__, 2) . '/src',
    dirname(__DIR__, 3) . '/src',
];

$bootstrapPath = null;
$predictionPath = null;
foreach ($srcCandidates as $candidate) {
    if ($bootstrapPath === null && is_file($candidate . '/bootstrap.php')) {
        $bootstrapPath = $candidate . '/bootstrap.php';
    }
    if ($predictionPath === null && is_file($candidate . '/prediction_cache.php')) {
        $predictionPath = $candidate . '/prediction_cache.php';
    }
}

if ($bootstrapPath === null || $predictionPath === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unable to locate required src files']);
    exit;
}

require_once $bootstrapPath;
require_once $predictionPath;

try {
    $config = app_config();
    $pdo = pdo_from_config($config);
    $rows = prediction_cache_read_latest($pdo, $config);

    if ($rows === []) {
        json_response([
            'error' => 'No predictions cached yet. Run src/cli/build_predictions.php first.',
            'items' => [],
        ], 404);
    }

    $runId = (string) ($rows[0]['run_id'] ?? '');
    $generatedAt = (string) ($rows[0]['generated_at'] ?? '');

    json_response([
        'run_id' => $runId,
        'generated_at' => $generatedAt,
        'generated_at_iso' => $generatedAt !== '' ? gmdate('c', strtotime($generatedAt . ' UTC')) : null,
        'items' => $rows,
    ]);
} catch (Throwable $exception) {
    json_response([
        'error' => 'Failed to read prediction cache.',
        'details' => $exception->getMessage(),
    ], 500);
}

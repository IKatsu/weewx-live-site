<?php

declare(strict_types=1);

$cachePath = __DIR__ . '/api/forecast-cache.json';
$payload = null;
if (is_file($cachePath)) {
    $raw = file_get_contents($cachePath);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forecast</title>
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/theme-bright.css">
    <style>
        .forecast-wrap { max-width: 1200px; margin: 1rem auto; width: calc(100% - 2rem); }
        .card { margin-bottom: 1rem; }
        pre { white-space: pre-wrap; word-break: break-word; color: var(--muted); }
    </style>
</head>
<body>
<div class="forecast-wrap">
    <h1 class="title">Forecast</h1>
    <article class="card">
        <div class="label">Status</div>
        <?php if ($payload === null): ?>
            <p>No cached forecast found yet. This page will show long-range forecast after WU fetch integration is enabled.</p>
        <?php else: ?>
            <p>Cached forecast loaded.</p>
        <?php endif; ?>
    </article>

    <article class="card">
        <div class="label">Raw Forecast Cache</div>
        <pre><?= htmlspecialchars($payload === null ? 'No data' : json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
    </article>
</div>
</body>
</html>

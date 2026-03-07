<?php

declare(strict_types=1);

putenv('PWS_BASE_DIR=' . __DIR__);

$srcCandidates = [
    dirname(__DIR__) . '/src',
    dirname(__DIR__, 2) . '/src',
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
    echo 'Unable to locate src/bootstrap.php';
    exit;
}

require_once $bootstrapPath;

$config = app_config();

try {
    $pdo = pdo_from_config($config);
    $columns = archive_columns($pdo);

    $outTempColumn = mapped_archive_column($config, $columns, 'outTemp') ?? 'outTemp';
    if (!is_safe_identifier($outTempColumn)) {
        throw new RuntimeException('Invalid outTemp field mapping.');
    }

    $dayTable = 'archive_day_' . $outTempColumn;
    if (!is_safe_identifier($dayTable)) {
        throw new RuntimeException('Invalid archive_day table name.');
    }

    // Daily summaries already contain min/max and weighted sums,
    // so monthly min/avg/max can be computed cheaply from archive_day tables.
    $sql = sprintf(
        "SELECT
            DATE_FORMAT(FROM_UNIXTIME(dateTime), '%%Y-%%m') AS month_key,
            MIN(min) AS month_min,
            MAX(max) AS month_max,
            SUM(sum) / NULLIF(SUM(count), 0) AS month_avg,
            SUM(count) AS sample_count
         FROM %s
         WHERE dateTime >= UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 24 MONTH))
         GROUP BY month_key
         ORDER BY month_key DESC",
        $dayTable
    );

    $rows = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to load history: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

$months = [];
$minVals = [];
$avgVals = [];
$maxVals = [];
foreach ($rows as $row) {
    $months[] = $row['month_key'];
    $minVals[] = $row['month_min'] !== null ? round((float) $row['month_min'], 2) : null;
    $avgVals[] = $row['month_avg'] !== null ? round((float) $row['month_avg'], 2) : null;
    $maxVals[] = $row['month_max'] !== null ? round((float) $row['month_max'], 2) : null;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monthly Temperature History</title>
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/theme-bright.css">
    <style>
        .history-wrap { max-width: 1200px; margin: 1rem auto; width: calc(100% - 2rem); }
        .table-card { background: var(--card); border: 1px solid var(--border); border-radius: .8rem; padding: 1rem; margin-top: 1rem; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: .55rem .5rem; border-bottom: 1px solid var(--border); text-align: right; }
        th:first-child, td:first-child { text-align: left; }
    </style>
</head>
<body>
<div class="history-wrap">
    <div class="status-row" style="margin-bottom: .6rem;">
        <a class="status-pill" href="./">Dashboard</a>
    </div>
    <h1 class="title">Monthly Outside Temperature Min/Avg/Max</h1>
    <div class="chart-card">
        <h3 class="chart-title">Last 24 Months</h3>
        <div class="chart-wrap"><canvas id="history-chart"></canvas></div>
    </div>
    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Min</th>
                    <th>Avg</th>
                    <th>Max</th>
                    <th>Samples</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $row['month_key'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $row['month_min'] !== null ? number_format((float) $row['month_min'], 2) : 'n/a' ?></td>
                    <td><?= $row['month_avg'] !== null ? number_format((float) $row['month_avg'], 2) : 'n/a' ?></td>
                    <td><?= $row['month_max'] !== null ? number_format((float) $row['month_max'], 2) : 'n/a' ?></td>
                    <td><?= (int) ($row['sample_count'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('history-chart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_reverse($months)) ?>,
        datasets: [
            { label: 'Min', data: <?= json_encode(array_reverse($minVals)) ?>, borderColor: '#2c77c0', backgroundColor: '#2c77c0' },
            { label: 'Avg', data: <?= json_encode(array_reverse($avgVals)) ?>, borderColor: '#2f7f40', backgroundColor: '#2f7f40' },
            { label: 'Max', data: <?= json_encode(array_reverse($maxVals)) ?>, borderColor: '#cf3f2f', backgroundColor: '#cf3f2f' },
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        elements: { point: { radius: 2 }, line: { tension: 0.25, borderWidth: 2 } },
    },
});
</script>
</body>
</html>

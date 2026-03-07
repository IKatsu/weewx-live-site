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

function years_last_n(int $years): array
{
    $now = new DateTimeImmutable('now');
    $list = [];
    for ($i = $years - 1; $i >= 0; $i--) {
        $list[] = (int) $now->modify("-{$i} years")->format('Y');
    }
    return $list;
}

function month_labels_short(): array
{
    return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
}

function fetch_monthly_hilo(PDO $pdo, string $tableName): array
{
    $sql = sprintf(
        "SELECT
            DATE_FORMAT(FROM_UNIXTIME(dateTime), '%%Y-%%m') AS month_key,
            MIN(min) AS low_val,
            SUM(sum) / NULLIF(SUM(count), 0) AS avg_val,
            MAX(max) AS high_val
         FROM `%s`
         WHERE dateTime >= UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 12 MONTH))
         GROUP BY month_key",
        $tableName
    );

    $rows = $pdo->query($sql)->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $key = (string) ($row['month_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $out[$key] = [
            'low' => $row['low_val'] !== null ? (float) $row['low_val'] : null,
            'avg' => $row['avg_val'] !== null ? (float) $row['avg_val'] : null,
            'high' => $row['high_val'] !== null ? (float) $row['high_val'] : null,
        ];
    }
    return $out;
}

function row_min_max(array $values): array
{
    $filtered = array_values(array_filter($values, static fn($v) => $v !== null));
    if ($filtered === []) {
        return [null, null];
    }
    return [min($filtered), max($filtered)];
}

function cell_style(?float $value, ?float $min, ?float $max): string
{
    if ($value === null || $min === null || $max === null || abs($max - $min) < 0.00001) {
        return '';
    }
    $ratio = ($value - $min) / ($max - $min);
    $ratio = max(0.0, min(1.0, $ratio));
    $alpha = 0.10 + (0.35 * $ratio);
    return sprintf(' style="background: rgba(15,110,207,%.3f);"', $alpha);
}

function fmt_val(?float $value, int $decimals): string
{
    return $value === null ? '-' : number_format($value, $decimals);
}

$config = app_config();
$cssConfig = $config['ui']['css'] ?? [];
$cssBase = (string) ($cssConfig['base'] ?? 'assets/css/base.css');
$cssThemes = $cssConfig['themes'] ?? ['bright' => 'assets/css/theme-bright.css', 'dark' => 'assets/css/theme-dark.css'];
$defaultTheme = (string) ($cssConfig['default_theme'] ?? 'bright');
$cssCustom = (string) ($cssConfig['custom'] ?? '');

$metricDefs = [
    ['field' => 'outTemp', 'label' => 'Outside Temperature', 'unit_key' => 'temperature', 'decimals' => 1],
    ['field' => 'inTemp', 'label' => 'Inside Temperature', 'unit_key' => 'temperature', 'decimals' => 1],
    ['field' => 'dewpoint', 'label' => 'Outside Dew Point', 'unit_key' => 'temperature', 'decimals' => 1],
    ['field' => 'inDewpoint', 'label' => 'Inside Dew Point', 'unit_key' => 'temperature', 'decimals' => 1],
    ['field' => 'outHumidity', 'label' => 'Outside Humidity', 'unit' => '%', 'decimals' => 1],
    ['field' => 'inHumidity', 'label' => 'Inside Humidity', 'unit' => '%', 'decimals' => 1],
    ['field' => 'windSpeed', 'label' => 'Wind Speed', 'unit_key' => 'wind', 'decimals' => 1],
    ['field' => 'windGust', 'label' => 'Wind Gust', 'unit_key' => 'wind', 'decimals' => 1],
    ['field' => 'barometer', 'label' => 'Barometer', 'unit_key' => 'pressure', 'decimals' => 1],
    ['field' => 'rainRate', 'label' => 'Rain Rate', 'unit_key' => 'rain_rate', 'decimals' => 2],
    ['field' => 'rain', 'label' => 'Rain Total', 'unit_key' => 'rain', 'decimals' => 2],
    ['field' => 'radiation', 'label' => 'Solar Radiation', 'unit' => 'W/m²', 'decimals' => 0],
    ['field' => 'UV', 'label' => 'UV Index', 'unit' => 'index', 'decimals' => 1],
    ['field' => 'ET', 'label' => 'Evapotranspiration', 'unit_key' => 'rain', 'decimals' => 2],
    ['field' => 'pm2_5', 'label' => 'PM2.5', 'unit' => 'µg/m³', 'decimals' => 1],
    ['field' => 'lightning_strike_count', 'label' => 'Lightning Count', 'unit' => 'count', 'decimals' => 0],
    ['field' => 'windBatteryStatus', 'label' => 'Wind Battery', 'unit' => 'V', 'decimals' => 2],
    ['field' => 'rainBatteryStatus', 'label' => 'Rain Battery', 'unit' => 'V', 'decimals' => 2],
    ['field' => 'lightning_Batt', 'label' => 'Lightning Battery', 'unit' => 'V', 'decimals' => 2],
    ['field' => 'pm25_Batt1', 'label' => 'PM2.5 Battery', 'unit' => 'V', 'decimals' => 2],
    ['field' => 'inTempBatteryStatus', 'label' => 'Indoor Temp Battery', 'unit' => 'V', 'decimals' => 2],
];

    $sections = [];
    $error = null;

try {
    $pdo = pdo_from_config($config);
    $columns = archive_columns($pdo);
    $years = years_last_n(3);

    $latestUnits = 17;
    $dateCol = mapped_archive_column($config, $columns, 'dateTime');
    $unitsCol = mapped_archive_column($config, $columns, 'usUnits');
    if ($dateCol !== null && $unitsCol !== null) {
        $sqlLatest = sprintf('SELECT %s AS usUnits FROM archive ORDER BY %s DESC LIMIT 1', $unitsCol, $dateCol);
        $row = $pdo->query($sqlLatest)->fetch();
        if (is_array($row) && isset($row['usUnits'])) {
            $latestUnits = (int) $row['usUnits'];
        }
    }
    $unitMap = unit_map($latestUnits);

    $tableExistsStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name'
    );

    foreach ($metricDefs as $def) {
        $field = (string) $def['field'];
        $mapped = mapped_archive_column($config, $columns, $field);
        if ($mapped === null) {
            continue;
        }

        $tableName = 'archive_day_' . $mapped;
        if (!is_safe_identifier($tableName)) {
            continue;
        }

        $tableExistsStmt->execute([':table_name' => $tableName]);
        if ((int) $tableExistsStmt->fetchColumn() === 0) {
            continue;
        }

        $monthlyData = fetch_monthly_hilo($pdo, $tableName); // keyed by YYYY-MM
        $rowsByYear = [];
        foreach ($years as $year) {
            $y = (string) $year;
            $rowsByYear[$y] = ['high' => [], 'avg' => [], 'low' => []];
            for ($month = 1; $month <= 12; $month++) {
                $mk = sprintf('%04d-%02d', $year, $month);
                $entry = $monthlyData[$mk] ?? ['low' => null, 'avg' => null, 'high' => null];
                $rowsByYear[$y]['high'][] = $entry['high'];
                $rowsByYear[$y]['avg'][] = $entry['avg'];
                $rowsByYear[$y]['low'][] = $entry['low'];
            }
        }

        $unit = $def['unit'] ?? ($unitMap[$def['unit_key'] ?? ''] ?? '');
        $sections[] = [
            'label' => $def['label'],
            'unit' => (string) $unit,
            'decimals' => (int) $def['decimals'],
            'years' => $years,
            'rows_by_year' => $rowsByYear,
        ];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $sections = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monthly History</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($cssBase, ENT_QUOTES, 'UTF-8') ?>">
<?php foreach ($cssThemes as $themePath): ?>
<?php if (is_string($themePath) && $themePath !== ''): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($themePath, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<?php endforeach; ?>
<?php if ($cssCustom !== ''): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($cssCustom, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
    <style>
        .history-wrap { max-width: 1400px; margin: 1rem auto; width: calc(100% - 2rem); }
        .history-grid { display: grid; gap: .9rem; }
        .history-card { background: var(--card); border: 1px solid var(--border); border-radius: .8rem; padding: .85rem; overflow-x: auto; }
        .history-head { display: flex; justify-content: space-between; gap: .5rem; align-items: baseline; margin-bottom: .5rem; }
        .history-title { margin: 0; font-size: 1rem; }
        .history-unit { color: var(--muted); font-size: .86rem; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th, td { padding: .45rem .4rem; border-bottom: 1px solid var(--border); text-align: center; white-space: nowrap; }
        th:first-child, td:first-child { text-align: left; font-weight: 700; }
        .muted { color: var(--muted); }
    </style>
</head>
<body>
<div class="history-wrap">
    <div class="status-row" style="margin-bottom: .7rem;">
        <a class="status-pill" href="./">Dashboard</a>
        <label class="status-pill" for="theme-select">
            <span>Theme:</span>
            <select id="theme-select"></select>
        </label>
    </div>
    <h1 class="title">Monthly High / Average / Low History</h1>
    <p class="muted">Monthly high/average/low by metric (Jan-Dec columns, last 3 years) from available <code>archive_day_*</code> tables.</p>

    <?php if ($error !== null): ?>
        <div class="history-card">Failed to load history: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif ($sections === []): ?>
        <div class="history-card">No monthly history sections available for current field mappings.</div>
    <?php else: ?>
    <section class="history-grid">
        <?php foreach ($sections as $section): ?>
            <?php
            $allHigh = [];
            $allAvg = [];
            $allLow = [];
            foreach ($section['rows_by_year'] as $bucket) {
                $allHigh = array_merge($allHigh, $bucket['high']);
                $allAvg = array_merge($allAvg, $bucket['avg']);
                $allLow = array_merge($allLow, $bucket['low']);
            }
            [$highMin, $highMax] = row_min_max($allHigh);
            [$avgMin, $avgMax] = row_min_max($allAvg);
            [$lowMin, $lowMax] = row_min_max($allLow);
            ?>
            <article class="history-card">
                <div class="history-head">
                    <h2 class="history-title"><?= htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <span class="history-unit"><?= htmlspecialchars($section['unit'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Year / Type</th>
                            <?php foreach (month_labels_short() as $monthLabel): ?>
                                <th><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($section['years'] as $year): ?>
                            <?php $bucket = $section['rows_by_year'][(string) $year]; ?>
                            <tr>
                                <td><?= (int) $year ?> High</td>
                                <?php foreach ($bucket['high'] as $v): ?>
                                    <td<?= cell_style($v, $highMin, $highMax) ?>><?= fmt_val($v, $section['decimals']) ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <td><?= (int) $year ?> Average</td>
                                <?php foreach ($bucket['avg'] as $v): ?>
                                    <td<?= cell_style($v, $avgMin, $avgMax) ?>><?= fmt_val($v, $section['decimals']) ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <td><?= (int) $year ?> Low</td>
                                <?php foreach ($bucket['low'] as $v): ?>
                                    <td<?= cell_style($v, $lowMin, $lowMax) ?>><?= fmt_val($v, $section['decimals']) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</div>
<script>
const HISTORY_APP = {
    defaultTheme: <?= json_encode($defaultTheme) ?>,
    themes: <?= json_encode(array_keys($cssThemes)) ?>,
};

function setTheme(themeName) {
    const allowed = Array.isArray(HISTORY_APP.themes) ? HISTORY_APP.themes : [];
    const selected = allowed.includes(themeName) ? themeName : HISTORY_APP.defaultTheme;
    document.body.dataset.theme = selected;
    try { localStorage.setItem('pws-theme', selected); } catch {}
}

function initThemeSelector() {
    const select = document.getElementById('theme-select');
    if (!select) return;

    const themes = Array.isArray(HISTORY_APP.themes) ? HISTORY_APP.themes : ['bright', 'dark'];
    for (const theme of themes) {
        const opt = document.createElement('option');
        opt.value = theme;
        opt.textContent = theme;
        select.appendChild(opt);
    }

    let initial = HISTORY_APP.defaultTheme;
    try {
        const saved = localStorage.getItem('pws-theme');
        if (saved && themes.includes(saved)) initial = saved;
    } catch {}

    setTheme(initial);
    select.value = initial;
    select.addEventListener('change', () => setTheme(select.value));
}

initThemeSelector();
</script>
</body>
</html>

<?php

declare(strict_types=1);

// Page entrypoints can run from local dev or mounted deploy paths.
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
    // archive_day_* already stores daily rollups; this query aggregates those into months.
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

function lerp(float $a, float $b, float $t): float
{
    return $a + (($b - $a) * $t);
}

function color_stops(string $palette): array
{
    return match ($palette) {
        // Unified temperature scale requested for all pages.
        // -15C and below frosty blue, 0 blue, 10 yellow, 20 green, 25+ red.
        'temperature' => [
            [198, 168, 235], // light purple
            [140, 28, 255],  // violet
            [96, 24, 228],   // blue-violet
            [54, 74, 214],   // indigo
            [22, 164, 140],  // blue-green edge
            [32, 186, 84],   // green edge
            [182, 230, 54],  // yellow-green
            [248, 224, 64],  // yellow
            [255, 176, 44],  // orange
            [255, 102, 34],  // red-orange edge
            [255, 44, 22],   // red
            [224, 12, 126],  // red-violet
        ],
        // Rainfall: very light to deep blue
        'rain' => [[236, 244, 252], [189, 220, 246], [120, 179, 231], [61, 131, 200], [30, 78, 156]],
        // Standard wind severity gradient.
        'wind' => [[52, 120, 204], [54, 172, 114], [235, 208, 78], [219, 111, 60], [138, 74, 171]],
        // Neutral fallback palette.
        default => [[226, 236, 247], [154, 188, 224], [84, 132, 186]],
    };
}

function color_for_ratio(string $palette, float $ratio): array
{
    $stops = color_stops($palette);
    if (count($stops) === 1) {
        return $stops[0];
    }

    $ratio = max(0.0, min(1.0, $ratio));
    $segments = count($stops) - 1;
    $pos = $ratio * $segments;
    $idx = (int) floor($pos);
    if ($idx >= $segments) {
        return $stops[$segments];
    }
    $t = $pos - $idx;
    $a = $stops[$idx];
    $b = $stops[$idx + 1];

    return [
        (int) round(lerp($a[0], $b[0], $t)),
        (int) round(lerp($a[1], $b[1], $t)),
        (int) round(lerp($a[2], $b[2], $t)),
    ];
}

function temp_to_celsius(?float $value, string $unit): ?float
{
    if ($value === null) {
        return null;
    }
    if (str_contains($unit, '°F')) {
        return (($value - 32.0) * 5.0) / 9.0;
    }
    return $value;
}

function temperature_ratio_from_value(?float $value, string $unit): ?float
{
    $tempC = temp_to_celsius($value, $unit);
    if ($tempC === null) {
        return null;
    }
    $stops = [-25.0, -15.0, -8.0, -3.0, 0.0, 4.0, 10.0, 15.0, 20.0, 25.0, 30.0, 35.0];
    $x = max($stops[0], min($stops[count($stops) - 1], $tempC));
    for ($i = 0, $n = count($stops) - 1; $i < $n; $i++) {
        if ($x >= $stops[$i] && $x <= $stops[$i + 1]) {
            $local = ($x - $stops[$i]) / max(0.0001, ($stops[$i + 1] - $stops[$i]));
            return (($i + $local) / $n);
        }
    }
    return 1.0;
}

function cell_style(
    ?float $value,
    ?float $min,
    ?float $max,
    string $palette = 'default',
    string $unit = ''
): string
{
    if ($value === null) {
        return '';
    }
    if ($palette === 'temperature') {
        return '';
    } else {
        if ($min === null || $max === null || abs($max - $min) < 0.00001) {
            return '';
        }
        $ratio = ($value - $min) / ($max - $min);
        $ratio = max(0.0, min(1.0, $ratio));
    }
    [$r, $g, $b] = color_for_ratio($palette, $ratio);
    $luma = (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
    $fg = $luma < 145 ? '#ffffff' : '#102137';
    return sprintf(
        ' class="metric-tone-cell" style="--metric-rgb:%1$d,%2$d,%3$d;--metric-fg:%4$s;"',
        $r,
        $g,
        $b,
        $fg
    );
}

function temp_text_html(?float $value, int $decimals, string $unit): string
{
    if ($value === null) {
        return '-';
    }
    $ratio = temperature_ratio_from_value($value, $unit);
    if ($ratio === null) {
        return number_format($value, $decimals);
    }
    [$r, $g, $b] = color_for_ratio('temperature', $ratio);
    $hi = [
        (int) min(255, round($r + (255 - $r) * 0.22)),
        (int) min(255, round($g + (255 - $g) * 0.22)),
        (int) min(255, round($b + (255 - $b) * 0.22)),
    ];
    $label = number_format($value, $decimals);
    return sprintf(
        '<span class="temp-gradient-chip temp-gradient-text" style="--temp-hi-rgb:%d,%d,%d;--temp-base-rgb:%d,%d,%d;">%s</span>',
        $hi[0],
        $hi[1],
        $hi[2],
        $r,
        $g,
        $b,
        $label
    );
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
    ['field' => 'outTemp', 'label' => 'Outside Temperature', 'unit_key' => 'temperature', 'decimals' => 1, 'palette' => 'temperature'],
    ['field' => 'inTemp', 'label' => 'Inside Temperature', 'unit_key' => 'temperature', 'decimals' => 1, 'palette' => 'temperature'],
    ['field' => 'dewpoint', 'label' => 'Outside Dew Point', 'unit_key' => 'temperature', 'decimals' => 1, 'palette' => 'temperature'],
    ['field' => 'inDewpoint', 'label' => 'Inside Dew Point', 'unit_key' => 'temperature', 'decimals' => 1, 'palette' => 'temperature'],
    ['field' => 'outHumidity', 'label' => 'Outside Humidity', 'unit' => '%', 'decimals' => 1, 'palette' => 'default'],
    ['field' => 'inHumidity', 'label' => 'Inside Humidity', 'unit' => '%', 'decimals' => 1, 'palette' => 'default'],
    ['field' => 'windSpeed', 'label' => 'Wind Speed', 'unit_key' => 'wind', 'decimals' => 1, 'palette' => 'wind'],
    ['field' => 'windGust', 'label' => 'Wind Gust', 'unit_key' => 'wind', 'decimals' => 1, 'palette' => 'wind'],
    ['field' => 'barometer', 'label' => 'Barometer', 'unit_key' => 'pressure', 'decimals' => 1, 'palette' => 'default'],
    ['field' => 'rainRate', 'label' => 'Rain Rate', 'unit_key' => 'rain_rate', 'decimals' => 2, 'palette' => 'rain'],
    ['field' => 'rain', 'label' => 'Rain Total', 'unit_key' => 'rain', 'decimals' => 2, 'palette' => 'rain'],
    ['field' => 'radiation', 'label' => 'Solar Radiation', 'unit' => 'W/m²', 'decimals' => 0, 'palette' => 'default'],
    ['field' => 'UV', 'label' => 'UV Index', 'unit' => 'index', 'decimals' => 1, 'palette' => 'default'],
    ['field' => 'ET', 'label' => 'Evapotranspiration', 'unit_key' => 'rain', 'decimals' => 2, 'palette' => 'rain'],
    ['field' => 'pm2_5', 'label' => 'PM2.5', 'unit' => 'µg/m³', 'decimals' => 1, 'palette' => 'default'],
    ['field' => 'lightning_strike_count', 'label' => 'Lightning Count', 'unit' => 'count', 'decimals' => 0, 'palette' => 'default'],
    ['field' => 'windBatteryStatus', 'label' => 'Wind Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
    ['field' => 'rainBatteryStatus', 'label' => 'Rain Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
    ['field' => 'lightning_Batt', 'label' => 'Lightning Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
    ['field' => 'pm25_Batt1', 'label' => 'PM2.5 Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
    ['field' => 'inTempBatteryStatus', 'label' => 'Indoor Temp Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
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
            'palette' => (string) ($def['palette'] ?? 'default'),
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
</head>
<body>
<div class="history-wrap">
    <div class="status-row status-row-spaced">
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
                <table class="history-table">
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
                                    <td<?= cell_style($v, $highMin, $highMax, $section['palette'], $section['unit']) ?>><?= $section['palette'] === 'temperature' ? temp_text_html($v, $section['decimals'], $section['unit']) : fmt_val($v, $section['decimals']) ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <td><?= (int) $year ?> Average</td>
                                <?php foreach ($bucket['avg'] as $v): ?>
                                    <td<?= cell_style($v, $avgMin, $avgMax, $section['palette'], $section['unit']) ?>><?= $section['palette'] === 'temperature' ? temp_text_html($v, $section['decimals'], $section['unit']) : fmt_val($v, $section['decimals']) ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <td><?= (int) $year ?> Low</td>
                                <?php foreach ($bucket['low'] as $v): ?>
                                    <td<?= cell_style($v, $lowMin, $lowMax, $section['palette'], $section['unit']) ?>><?= $section['palette'] === 'temperature' ? temp_text_html($v, $section['decimals'], $section['unit']) : fmt_val($v, $section['decimals']) ?></td>
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
    document.documentElement.setAttribute('data-theme', selected);
    try { localStorage.setItem('pws_theme', selected); } catch {}
}

function initThemeSelector() {
    const select = document.getElementById('theme-select');
    if (!select) return;

    const themes = Array.isArray(HISTORY_APP.themes) ? HISTORY_APP.themes : ['bright', 'dark'];
    for (const theme of themes) {
        const opt = document.createElement('option');
        opt.value = theme;
        opt.textContent = theme.charAt(0).toUpperCase() + theme.slice(1);
        select.appendChild(opt);
    }

    let initial = HISTORY_APP.defaultTheme;
    try {
        const saved = localStorage.getItem('pws_theme');
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

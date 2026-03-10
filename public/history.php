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
require_once dirname($bootstrapPath) . '/view_helpers.php';
require_once dirname($bootstrapPath) . '/history_metrics.php';

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

function fetch_monthly_hilo(PDO $pdo, string $tableName, string $fromMonthKey): array
{
    // archive_day_* already stores daily rollups; this query aggregates those into months.
    $sql = sprintf(
        "SELECT
            DATE_FORMAT(FROM_UNIXTIME(dateTime), '%%Y-%%m') AS month_key,
            MIN(min) AS low_val,
            SUM(sum) / NULLIF(SUM(count), 0) AS avg_val,
            MAX(max) AS high_val
         FROM `%s`
         WHERE DATE_FORMAT(FROM_UNIXTIME(dateTime), '%%Y-%%m') >= :from_month_key
         GROUP BY month_key",
        $tableName
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':from_month_key' => $fromMonthKey]);
    $rows = $stmt->fetchAll();
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

function fetch_monthly_summary(PDO $pdo, string $tableName, string $fromMonthKey): array
{
    $sql = sprintf(
        'SELECT field_key, month_key, low_value, avg_value, high_value
         FROM `%s`
         WHERE month_key >= :from_month_key',
        $tableName
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':from_month_key' => $fromMonthKey]);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $fieldKey = (string) ($row['field_key'] ?? '');
        $monthKey = (string) ($row['month_key'] ?? '');
        if ($fieldKey === '' || $monthKey === '') {
            continue;
        }
        $out[$fieldKey][$monthKey] = [
            'low' => $row['low_value'] !== null ? (float) $row['low_value'] : null,
            'avg' => $row['avg_value'] !== null ? (float) $row['avg_value'] : null,
            'high' => $row['high_value'] !== null ? (float) $row['high_value'] : null,
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
send_security_headers($config);
$view = page_view_context($config);
$defaultTheme = (string) $view['default_theme'];

    $sections = [];
    $error = null;

try {
    $pdo = pdo_from_config($config);
    $columns = archive_columns($pdo);
    $lookbackYears = max(1, (int) ($config['history']['lookback_years'] ?? 3));
    $years = years_last_n($lookbackYears);
    $fromMonthKey = sprintf('%04d-01', min($years));
    $currentMonthKey = (new DateTimeImmutable('now'))->format('Y-m');
    $summaryTable = (string) (($config['history']['summary_table'] ?? '') ?: 'pws_history_monthly_summary');

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
    $summaryByField = [];
    if (is_safe_identifier($summaryTable)) {
        $tableExistsStmt->execute([':table_name' => $summaryTable]);
        if ((int) $tableExistsStmt->fetchColumn() > 0) {
            $summaryByField = fetch_monthly_summary($pdo, $summaryTable, $fromMonthKey);
        }
    }

    foreach (history_metric_definitions() as $def) {
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

        $monthlyData = fetch_monthly_hilo($pdo, $tableName, $fromMonthKey); // keyed by YYYY-MM
        foreach (($summaryByField[$field] ?? []) as $monthKey => $entry) {
            if ($monthKey === $currentMonthKey) {
                continue;
            }
            $monthlyData[$monthKey] = $entry;
        }
        $rowsByYear = [];
        $yearsWithData = [];
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

            $flatYearValues = array_merge($rowsByYear[$y]['high'], $rowsByYear[$y]['avg'], $rowsByYear[$y]['low']);
            $hasData = array_filter($flatYearValues, static fn($value) => $value !== null) !== [];
            if ($hasData) {
                $yearsWithData[] = $year;
            }
        }

        if ($yearsWithData === []) {
            continue;
        }

        $unit = $def['unit'] ?? ($unitMap[$def['unit_key'] ?? ''] ?? '');
        $sections[] = [
            'label' => tr((string) ($def['label_key'] ?? ''), (string) $def['label']),
            'unit' => (string) $unit,
            'decimals' => (int) $def['decimals'],
            'palette' => (string) ($def['palette'] ?? 'default'),
            'years' => $yearsWithData,
            'rows_by_year' => $rowsByYear,
        ];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $sections = [];
}
?>
<?php render_page_head(tr('history.page_title', 'Monthly History'), $view); ?>
<body>
<div class="history-wrap">
<?php render_site_header(tr('history.page_title', 'Monthly History'), default_nav_links()); ?>
    <h1 class="title"><?= htmlspecialchars(tr('history.title', 'Monthly High / Average / Low History'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="muted"><?= htmlspecialchars(tr('history.note', 'Monthly high/average/low by metric (Jan-Dec columns, last {years} years) using cached closed months plus live archive_day_* data for the current month.', ['years' => (int) ($config['history']['lookback_years'] ?? 3)]), ENT_QUOTES, 'UTF-8') ?></p>

    <?php if ($error !== null): ?>
        <div class="history-card"><?= htmlspecialchars(tr('history.failed_load', 'Failed to load history: {error}', ['error' => $error]), ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif ($sections === []): ?>
        <div class="history-card"><?= htmlspecialchars(tr('history.no_sections', 'No monthly history sections available for current field mappings.'), ENT_QUOTES, 'UTF-8') ?></div>
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
                            <th><?= htmlspecialchars(tr('history.year_type', 'Year / Type'), ENT_QUOTES, 'UTF-8') ?></th>
                            <?php foreach (month_labels_short() as $monthLabel): ?>
                                <th><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($section['years'] as $year): ?>
                            <?php $bucket = $section['rows_by_year'][(string) $year]; ?>
                            <tr class="history-year-group history-year-group-start">
                                <td><span class="history-year-label"><?= (int) $year ?></span> <span class="history-year-type"><?= htmlspecialchars(tr('history.high', 'High'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <?php foreach ($bucket['high'] as $v): ?>
                                    <td<?= cell_style($v, $highMin, $highMax, $section['palette'], $section['unit']) ?>><?= $section['palette'] === 'temperature' ? temp_text_html($v, $section['decimals'], $section['unit']) : fmt_val($v, $section['decimals']) ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <tr class="history-year-group">
                                <td><span class="history-year-label"><?= (int) $year ?></span> <span class="history-year-type"><?= htmlspecialchars(tr('history.average', 'Average'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <?php foreach ($bucket['avg'] as $v): ?>
                                    <td<?= cell_style($v, $avgMin, $avgMax, $section['palette'], $section['unit']) ?>><?= $section['palette'] === 'temperature' ? temp_text_html($v, $section['decimals'], $section['unit']) : fmt_val($v, $section['decimals']) ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <tr class="history-year-group history-year-group-end">
                                <td><span class="history-year-label"><?= (int) $year ?></span> <span class="history-year-type"><?= htmlspecialchars(tr('history.low', 'Low'), ENT_QUOTES, 'UTF-8') ?></span></td>
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
    themes: <?= json_encode(array_keys((array) $view['css_themes'])) ?>,
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

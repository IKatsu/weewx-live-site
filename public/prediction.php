<?php

declare(strict_types=1);

// Page entrypoints can run from local dev or mounted deploy paths.
putenv('PWS_BASE_DIR=' . __DIR__);

$srcCandidates = [
    dirname(__DIR__) . '/src',
    dirname(__DIR__, 2) . '/src',
];

$configPath = null;
foreach ($srcCandidates as $candidate) {
    if (is_file($candidate . '/config.php')) {
        $configPath = $candidate . '/config.php';
        break;
    }
}

if ($configPath === null) {
    http_response_code(500);
    echo 'Unable to locate src/config.php';
    exit;
}

require_once $configPath;

$config = app_config();
$cssConfig = $config['ui']['css'] ?? [];
$cssBase = (string) ($cssConfig['base'] ?? 'assets/css/base.css');
$cssThemes = $cssConfig['themes'] ?? ['bright' => 'assets/css/theme-bright.css', 'dark' => 'assets/css/theme-dark.css'];
$defaultTheme = (string) ($cssConfig['default_theme'] ?? 'bright');
$timeConfig = $config['ui']['time'] ?? ['format' => '24h'];
$timeFormat = (string) ($timeConfig['format'] ?? '24h');
$cssCustom = (string) ($cssConfig['custom'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PWS Prediction</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($cssBase, ENT_QUOTES, 'UTF-8') ?>">
<?php foreach ($cssThemes as $themeName => $themePath): ?>
<?php if (is_string($themePath) && $themePath !== ''): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($themePath, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<?php endforeach; ?>
<?php if ($cssCustom !== ''): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($cssCustom, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
</head>
<body>
<div class="forecast-wrap">
    <header class="header">
        <h1 class="title">Prediction</h1>
        <div class="status-row">
            <a class="status-pill" href="index.php">Dashboard</a>
            <a class="status-pill" href="trends.php">Trends</a>
            <a class="status-pill" href="history.php">History</a>
            <label class="status-pill" for="theme-select">
                <span>Theme:</span>
                <select id="theme-select"></select>
            </label>
            <div class="status-pill"><span>Run:</span> <strong id="pred-run">-</strong></div>
            <div class="status-pill"><span>Generated:</span> <strong id="pred-generated">-</strong></div>
        </div>
    </header>

    <article class="card">
        <h2 class="chart-title">Prediction By Hour</h2>
        <section id="prediction-hours" class="prediction-hours"></section>
    </article>
</div>

<script>
const PRED_APP = {
    defaultTheme: <?= json_encode($defaultTheme) ?>,
    themes: <?= json_encode(array_keys($cssThemes)) ?>,
    timeFormat: <?= json_encode($timeFormat) ?>,
};

function setTheme(theme) {
    if (!PRED_APP.themes.includes(theme)) return;
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('pws_theme', theme); } catch {}
}

function initThemeSelector() {
    const select = document.getElementById('theme-select');
    if (!select) return;
    for (const theme of PRED_APP.themes) {
        const opt = document.createElement('option');
        opt.value = theme;
        opt.textContent = theme.charAt(0).toUpperCase() + theme.slice(1);
        select.appendChild(opt);
    }

    let initial = PRED_APP.defaultTheme;
    try {
        const saved = localStorage.getItem('pws_theme');
        if (saved && PRED_APP.themes.includes(saved)) initial = saved;
    } catch {}

    select.value = initial;
    setTheme(initial);
    select.addEventListener('change', () => setTheme(select.value));
}

function fmtNumber(value, decimals = 2) {
    const n = Number(value);
    return Number.isFinite(n) ? n.toFixed(decimals) : '-';
}

function fmtTs(value) {
    const dt = new Date(`${String(value || '')}Z`);
    if (Number.isNaN(dt.getTime())) return String(value || '-');
    return dt.toLocaleString([], {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: PRED_APP.timeFormat !== '24h',
    });
}

function fmtHour(value) {
    const dt = new Date(`${String(value || '')}Z`);
    if (Number.isNaN(dt.getTime())) return String(value || '-');
    return dt.toLocaleString([], {
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: PRED_APP.timeFormat !== '24h',
    });
}

function arrowForSlope(slope) {
    const n = Number(slope);
    if (!Number.isFinite(n)) return '→';
    if (n > 0.01) return '↑';
    if (n < -0.01) return '↓';
    return '→';
}

function confidenceBand(conf) {
    const pct = Math.max(0, Math.min(100, Math.round((Number(conf) || 0) * 100)));
    if (pct < 25) return 'confidence-low';
    if (pct >= 90) return 'confidence-high';
    return 'confidence-mid';
}

function groupByHour(items) {
    const map = new Map();
    for (const item of items) {
        const key = String(item?.target_time || '');
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(item);
    }
    return [...map.entries()].sort((a, b) => String(a[0]).localeCompare(String(b[0])));
}

function renderHourWidgets(items) {
    const host = document.getElementById('prediction-hours');
    if (!host) return;
    host.innerHTML = '';
    if (!Array.isArray(items) || items.length === 0) {
        host.innerHTML = '<div class="muted">No prediction rows available.</div>';
        return;
    }

    const groups = groupByHour(items);
    for (const [hourKey, rows] of groups) {
        const card = document.createElement('article');
        card.className = 'prediction-hour-card';

        const title = document.createElement('h3');
        title.className = 'prediction-hour-title';
        title.textContent = fmtHour(hourKey);
        card.appendChild(title);

        const grid = document.createElement('div');
        grid.className = 'prediction-metric-grid';

        for (const item of rows) {
            const band = confidenceBand(item?.confidence);
            const metric = document.createElement('article');
            metric.className = `prediction-mini ${band}`;
            const metricLabel = String(item?.details?.label || item?.metric || 'metric');
            const horizon = Number(item?.details?.horizon_hours);
            const slope = Number(item?.details?.slope_per_hour);
            const arrow = arrowForSlope(slope);
            const trendLine = Number.isFinite(horizon) ? `${horizon}h, ${arrow} ${fmtNumber(Math.abs(slope), 2)}` : `${arrow} ${fmtNumber(Math.abs(slope), 2)}`;
            metric.innerHTML = `
                <div class="prediction-mini-label">${metricLabel}</div>
                <div class="prediction-mini-value">${fmtNumber(item?.value_num, 2)} ${item?.unit || ''}</div>
                <div class="prediction-mini-meta">${trendLine}</div>
                <div class="prediction-mini-confidence">${fmtNumber((Number(item?.confidence) || 0) * 100, 0)}% confidence</div>
            `;
            grid.appendChild(metric);
        }

        card.appendChild(grid);
        host.appendChild(card);
    }
}

async function loadPrediction() {
    const response = await fetch('api/prediction.php', { cache: 'no-store' });
    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        throw new Error(payload.error || `prediction ${response.status}`);
    }
    const payload = await response.json();
    document.getElementById('pred-run').textContent = payload.run_id || '-';
    document.getElementById('pred-generated').textContent = fmtTs(payload.generated_at);
    renderHourWidgets(payload.items || []);
}

(async function init() {
    initThemeSelector();
    try {
        await loadPrediction();
    } catch (err) {
        const host = document.getElementById('prediction-hours');
        if (host) {
            host.innerHTML = `<div class="muted">${String(err.message || err)}</div>`;
        }
    }
})();
</script>
</body>
</html>

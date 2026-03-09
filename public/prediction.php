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
        <h2 class="chart-title">Next 24h Prediction Grid</h2>
        <div class="history-table-wrap">
            <table class="history-table" id="prediction-table">
                <thead>
                <tr>
                    <th>Target Time</th>
                    <th>Metric</th>
                    <th>Prediction</th>
                    <th>Confidence</th>
                    <th>Method</th>
                    <th>Notes</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
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

function renderTable(items) {
    const body = document.querySelector('#prediction-table tbody');
    if (!body) return;
    body.innerHTML = '';

    if (!Array.isArray(items) || items.length === 0) {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="6">No prediction rows available.</td>';
        body.appendChild(row);
        return;
    }

    for (const item of items) {
        const notes = [];
        if (item?.details?.horizon_hours !== undefined) notes.push(`${item.details.horizon_hours}h horizon`);
        if (item?.details?.slope_per_hour !== undefined) notes.push(`slope ${fmtNumber(item.details.slope_per_hour, 3)}`);
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${fmtTs(item.target_time)}</td>
            <td>${item.metric}</td>
            <td>${fmtNumber(item.value_num, 2)} ${item.unit || ''}</td>
            <td>${fmtNumber((Number(item.confidence) || 0) * 100, 0)}%</td>
            <td>${item.method || '-'}</td>
            <td>${notes.join(', ') || '-'}</td>
        `;
        body.appendChild(tr);
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
    renderTable(payload.items || []);
}

(async function init() {
    initThemeSelector();
    try {
        await loadPrediction();
    } catch (err) {
        const body = document.querySelector('#prediction-table tbody');
        if (body) {
            body.innerHTML = `<tr><td colspan="6">${String(err.message || err)}</td></tr>`;
        }
    }
})();
</script>
</body>
</html>

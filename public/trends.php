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

$config = app_config();
send_security_headers($config);
$view = page_view_context($config);
$defaultTheme = (string) $view['default_theme'];
$timeConfig = $config['ui']['time'] ?? ['format' => '24h'];
$timeFormat = (string) ($timeConfig['format'] ?? '24h');
?>
<?php render_page_head('PWS Trends', $view); ?>
<body>
<div class="forecast-wrap">
<?php
render_site_header('Trend Nowcast', default_nav_links(), [
    '<div class="status-pill"><span>Window:</span> <strong id="window-hours">-</strong></div>',
    '<div class="status-pill"><span>Updated:</span> <strong id="trend-updated">-</strong></div>',
]);
?>

    <section class="cards" id="trend-cards"></section>

    <article class="card">
        <h2 class="chart-title">Rain Likelihood</h2>
        <div id="rain-nowcast" class="forecast-row muted">Loading...</div>
        <ul id="rain-reasons" class="trend-reasons"></ul>
    </article>

    <article class="history-card">
        <h2 class="history-title">Trend Details</h2>
        <div class="history-table-wrap">
            <table class="history-table" id="trend-table">
                <thead>
                    <tr>
                        <th>Metric</th>
                        <th>Current</th>
                        <th>Direction</th>
                        <th>Slope per hour</th>
                        <th>Prediction</th>
                        <th>Confidence</th>
                        <th>Samples</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </article>

    <article class="card">
        <h2 class="chart-title">Summary</h2>
        <ul id="trend-summary" class="trend-reasons"></ul>
    </article>
</div>

<script>
const TREND_APP = {
    defaultTheme: <?= json_encode($defaultTheme) ?>,
    themes: <?= json_encode(array_keys($cssThemes)) ?>,
    timeFormat: <?= json_encode($timeFormat) ?>,
};

function setTheme(theme) {
    if (!TREND_APP.themes.includes(theme)) return;
    document.documentElement.setAttribute('data-theme', theme);
    try {
        localStorage.setItem('pws_theme', theme);
    } catch {
        // ignore
    }
}

function initThemeSelector() {
    const select = document.getElementById('theme-select');
    if (!select) return;

    for (const theme of TREND_APP.themes) {
        const opt = document.createElement('option');
        opt.value = theme;
        opt.textContent = theme.charAt(0).toUpperCase() + theme.slice(1);
        select.appendChild(opt);
    }

    let initial = TREND_APP.defaultTheme;
    try {
        const saved = localStorage.getItem('pws_theme');
        if (saved && TREND_APP.themes.includes(saved)) initial = saved;
    } catch {
        // ignore
    }
    select.value = initial;
    setTheme(initial);
    select.addEventListener('change', () => setTheme(select.value));
}

function fmt(value, decimals = 2) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '-';
    return n.toFixed(decimals);
}

function fmtTime(isoText) {
    const dt = new Date(String(isoText || ''));
    if (Number.isNaN(dt.getTime())) return '-';
    return dt.toLocaleString([], {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: TREND_APP.timeFormat !== '24h',
    });
}

function directionClass(direction) {
    if (direction === 'rising') return 'trend-up';
    if (direction === 'falling') return 'trend-down';
    return 'trend-steady';
}

function renderMetricCards(metrics) {
    const host = document.getElementById('trend-cards');
    if (!host) return;
    host.innerHTML = '';
    for (const metric of metrics) {
        const card = document.createElement('article');
        card.className = 'card';
        card.innerHTML = `
            <div class="label">${metric.label}</div>
            <div class="value">${fmt(metric.current, 2)} ${metric.unit || ''}</div>
            <div class="muted ${directionClass(metric.direction)}">${metric.direction}</div>
            <div class="muted">Slope ${fmt(metric.slope_per_hour, 3)} ${metric.unit || ''}/h</div>
            <div class="muted">${metric.prediction_hours}h: ${fmt(metric.predicted_value, 2)} ${metric.unit || ''}</div>
        `;
        host.appendChild(card);
    }
}

function renderRainNowcast(rainNowcast) {
    const box = document.getElementById('rain-nowcast');
    const reasons = document.getElementById('rain-reasons');
    if (!box || !reasons) return;
    if (!rainNowcast) {
        box.textContent = 'Unavailable';
        reasons.innerHTML = '';
        return;
    }
    box.innerHTML = `<strong>${rainNowcast.score}%</strong> (${rainNowcast.level})`;
    reasons.innerHTML = '';
    const items = Array.isArray(rainNowcast.reasons) ? rainNowcast.reasons : [];
    for (const reason of items) {
        const li = document.createElement('li');
        li.textContent = reason;
        reasons.appendChild(li);
    }
    if (items.length === 0) {
        const li = document.createElement('li');
        li.textContent = 'No strong rain indicators at this time.';
        reasons.appendChild(li);
    }
}

function renderTrendTable(metrics) {
    const body = document.querySelector('#trend-table tbody');
    if (!body) return;
    body.innerHTML = '';
    for (const metric of metrics) {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${metric.label}</td>
            <td>${fmt(metric.current, 2)} ${metric.unit || ''}</td>
            <td class="${directionClass(metric.direction)}">${metric.direction}</td>
            <td>${fmt(metric.slope_per_hour, 3)} ${metric.unit || ''}/h</td>
            <td>${metric.prediction_hours}h: ${fmt(metric.predicted_value, 2)} ${metric.unit || ''}</td>
            <td>${fmt(Number(metric.confidence) * 100, 0)}%</td>
            <td>${fmt(metric.sample_count, 0)}</td>
        `;
        body.appendChild(row);
    }
}

function renderSummary(items) {
    const host = document.getElementById('trend-summary');
    if (!host) return;
    host.innerHTML = '';
    const list = Array.isArray(items) ? items : [];
    for (const item of list) {
        const li = document.createElement('li');
        li.textContent = String(item);
        host.appendChild(li);
    }
}

async function loadTrends() {
    const response = await fetch('api/trends.php', { cache: 'no-store' });
    if (!response.ok) throw new Error(`trends ${response.status}`);
    const payload = await response.json();

    document.getElementById('window-hours').textContent = `${payload.windowHours || '-'}h`;
    document.getElementById('trend-updated').textContent = fmtTime(payload.generatedAtIso);

    const metrics = Array.isArray(payload.metrics) ? payload.metrics : [];
    renderMetricCards(metrics);
    renderRainNowcast(payload.rainNowcast || null);
    renderTrendTable(metrics);
    renderSummary(payload.summary || []);
}

(async function init() {
    initThemeSelector();
    try {
        await loadTrends();
    } catch (error) {
        console.error(error);
        const box = document.getElementById('rain-nowcast');
        if (box) box.textContent = 'Failed to load trends.';
    }
})();
</script>
</body>
</html>

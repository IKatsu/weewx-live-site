<?php

declare(strict_types=1);

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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PWS Forecast</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($cssBase, ENT_QUOTES, 'UTF-8') ?>">
<?php foreach ($cssThemes as $themeName => $themePath): ?>
<?php if (is_string($themePath) && $themePath !== ''): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($themePath, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<?php endforeach; ?>
    <style>
        .forecast-wrap { max-width: 1200px; margin: 1rem auto; width: calc(100% - 2rem); }
        .forecast-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .muted { color: var(--muted); }
        .row { margin-bottom: .4rem; }
    </style>
</head>
<body>
<div class="forecast-wrap">
    <header class="header">
        <h1 class="title">Forecast</h1>
        <div class="status-row">
            <label class="status-pill" for="theme-select">
                <span>Theme:</span>
                <select id="theme-select"></select>
            </label>
            <div class="status-pill"><span>Provider:</span> <strong id="provider">-</strong></div>
            <div class="status-pill"><span>Hourly cache:</span> <strong id="cache-hourly">-</strong></div>
            <div class="status-pill"><span>Daily cache:</span> <strong id="cache-daily">-</strong></div>
        </div>
    </header>

    <article class="card">
        <h2 class="chart-title">Next Hours</h2>
        <div id="next-hours" class="forecast-grid"></div>
    </article>

    <article class="card">
        <h2 class="chart-title">Daily Forecast</h2>
        <div id="daily-forecast" class="forecast-grid"></div>
    </article>
</div>

<script>
const FORECAST_APP = {
    defaultTheme: <?= json_encode($defaultTheme) ?>,
    themes: <?= json_encode(array_keys($cssThemes)) ?>,
};

function setTheme(theme) {
    if (!FORECAST_APP.themes.includes(theme)) return;
    document.body.dataset.theme = theme;
    localStorage.setItem('pws-theme', theme);
}

function initThemeSelector() {
    const select = document.getElementById('theme-select');
    if (!select) return;

    for (const theme of FORECAST_APP.themes) {
        const opt = document.createElement('option');
        opt.value = theme;
        opt.textContent = theme;
        select.appendChild(opt);
    }

    const saved = localStorage.getItem('pws-theme');
    const theme = FORECAST_APP.themes.includes(saved) ? saved : FORECAST_APP.defaultTheme;
    select.value = theme;
    setTheme(theme);

    select.addEventListener('change', () => setTheme(select.value));
}

function cacheLabel(cacheRow) {
    if (!cacheRow?.fetched_at) return 'missing';
    return `${cacheRow.fetched_at} UTC`;
}

function tempToCelsius(value, unit) {
    const n = Number(value);
    if (!Number.isFinite(n)) return NaN;
    if (String(unit || '').includes('°F')) return (n - 32) * (5 / 9);
    return n;
}

function tempScaleColor(tempC) {
    const stops = [
        { t: -15, rgb: [82, 162, 255] },
        { t: 0, rgb: [54, 120, 232] },
        { t: 10, rgb: [244, 206, 72] },
        { t: 20, rgb: [84, 220, 90] },
        { t: 25, rgb: [222, 76, 68] },
    ];
    const x = Math.max(stops[0].t, Math.min(stops[stops.length - 1].t, tempC));
    for (let i = 0; i < stops.length - 1; i++) {
        const a = stops[i];
        const b = stops[i + 1];
        if (x >= a.t && x <= b.t) {
            const p = (x - a.t) / Math.max(0.0001, (b.t - a.t));
            return [
                Math.round(a.rgb[0] + (b.rgb[0] - a.rgb[0]) * p),
                Math.round(a.rgb[1] + (b.rgb[1] - a.rgb[1]) * p),
                Math.round(a.rgb[2] + (b.rgb[2] - a.rgb[2]) * p),
            ];
        }
    }
    return stops[stops.length - 1].rgb;
}

function tempChip(value, unit = '°C', decimals = 0) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '--';
    const tempC = tempToCelsius(n, unit);
    if (!Number.isFinite(tempC)) return `${n.toFixed(decimals)}°`;
    const [r, g, b] = tempScaleColor(tempC);
    const luma = (0.2126 * r) + (0.7152 * g) + (0.0722 * b);
    const fg = luma < 145 ? '#ffffff' : '#102137';
    const label = `${n.toFixed(decimals)}°`;
    return `<span class=\"temp-gradient-chip\" style=\"background:linear-gradient(180deg, rgba(${r}, ${g}, ${b}, 0.36), rgba(${r}, ${g}, ${b}, 0.18)); border:1px solid rgba(${r}, ${g}, ${b}, 0.78); color:${fg};\">${label}</span>`;
}

function renderHourly(rows) {
    const host = document.getElementById('next-hours');
    if (!host) return;
    if (!Array.isArray(rows) || rows.length === 0) {
        host.innerHTML = '<div class="muted">No hourly forecast in cache.</div>';
        return;
    }

    host.innerHTML = rows.map((r) => {
        const t = r.time_local ? new Date(r.time_local) : null;
        const timeText = t && !Number.isNaN(t.getTime())
            ? t.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            : '--:--';
        const temp = r.temperature !== null && r.temperature !== undefined ? tempChip(r.temperature, '°C', 0) : '--';
        const precip = r.precip_chance !== null && r.precip_chance !== undefined ? `${Number(r.precip_chance).toFixed(0)}%` : '-';
        return `<article class="card"><div class="row"><strong>${timeText}</strong></div><div class="row">${temp} ${r.phrase || ''}</div><div class="row muted">Rain chance ${precip}</div></article>`;
    }).join('');
}

function renderDaily(rows) {
    const host = document.getElementById('daily-forecast');
    if (!host) return;
    if (!Array.isArray(rows) || rows.length === 0) {
        host.innerHTML = '<div class="muted">No daily forecast in cache.</div>';
        return;
    }

    host.innerHTML = rows.map((r) => {
        const high = r.temp_max !== null && r.temp_max !== undefined ? tempChip(r.temp_max, '°C', 0) : '--';
        const low = r.temp_min !== null && r.temp_min !== undefined ? tempChip(r.temp_min, '°C', 0) : '--';
        return `<article class="card"><div class="row"><strong>${r.day_of_week || 'Day'}</strong></div><div class="row">High ${high} / Low ${low}</div><div class="row muted">${r.narrative || ''}</div></article>`;
    }).join('');
}

async function loadForecast() {
    const response = await fetch('api/forecast.php', { cache: 'no-store' });
    if (!response.ok) throw new Error(`forecast ${response.status}`);
    const payload = await response.json();

    document.getElementById('provider').textContent = payload.provider || 'n/a';
    document.getElementById('cache-hourly').textContent = cacheLabel(payload.cache?.hourly);
    document.getElementById('cache-daily').textContent = cacheLabel(payload.cache?.daily);

    renderHourly(payload.dashboard?.next_hours || []);
    renderDaily(payload.daily || []);
}

(async function init() {
    initThemeSelector();
    try {
        await loadForecast();
    } catch (error) {
        document.getElementById('daily-forecast').innerHTML = '<div class="muted">Failed to load forecast cache.</div>';
        console.error(error);
    }
})();
</script>
</body>
</html>

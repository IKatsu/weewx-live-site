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
// Theme/time settings are read server-side and forwarded to JS config below.
$cssConfig = $config['ui']['css'] ?? [];
$cssBase = (string) ($cssConfig['base'] ?? 'assets/css/base.css');
$cssThemes = $cssConfig['themes'] ?? ['bright' => 'assets/css/theme-bright.css', 'dark' => 'assets/css/theme-dark.css'];
$defaultTheme = (string) ($cssConfig['default_theme'] ?? 'bright');
$timeConfig = $config['ui']['time'] ?? ['format' => '24h'];
$timeFormat = (string) ($timeConfig['format'] ?? '24h');
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
</head>
<body>
<div class="forecast-wrap">
    <header class="header">
        <h1 class="title">Forecast</h1>
        <div class="status-row">
            <a class="status-pill" href="index.php">Dashboard</a>
            <a class="status-pill" href="trends.php">Trends</a>
            <a class="status-pill" href="history.php">History</a>
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
    timeFormat: <?= json_encode($timeFormat) ?>,
};

function setTheme(theme) {
    if (!FORECAST_APP.themes.includes(theme)) return;
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('pws_theme', theme);
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

    const saved = localStorage.getItem('pws_theme');
    const theme = FORECAST_APP.themes.includes(saved) ? saved : FORECAST_APP.defaultTheme;
    select.value = theme;
    setTheme(theme);

    select.addEventListener('change', () => setTheme(select.value));
}

function cacheLabel(cacheRow) {
    if (!cacheRow?.fetched_at) return 'missing';
    const dt = new Date(`${cacheRow.fetched_at}Z`);
    if (Number.isNaN(dt.getTime())) return `${cacheRow.fetched_at} UTC`;
    return `${dt.toLocaleString([], {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: FORECAST_APP.timeFormat !== '24h',
        timeZone: 'UTC',
    })} UTC`;
}

function formatClock(dateObj) {
    if (!(dateObj instanceof Date) || Number.isNaN(dateObj.getTime())) return '--:--';
    return dateObj.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hour12: FORECAST_APP.timeFormat !== '24h',
    });
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function tempToCelsius(value, unit) {
    const n = Number(value);
    if (!Number.isFinite(n)) return NaN;
    if (String(unit || '').includes('°F')) return (n - 32) * (5 / 9);
    return n;
}

function tempScaleColor(tempC) {
    const stops = [
        { t: -25, rgb: [198, 168, 235] },
        { t: -15, rgb: [140, 28, 255] },
        { t: -8, rgb: [96, 24, 228] },
        { t: -3, rgb: [54, 74, 214] },
        { t: 0, rgb: [22, 164, 140] },
        { t: 4, rgb: [32, 186, 84] },
        { t: 10, rgb: [182, 230, 54] },
        { t: 15, rgb: [248, 224, 64] },
        { t: 20, rgb: [255, 176, 44] },
        { t: 25, rgb: [255, 102, 34] },
        { t: 30, rgb: [255, 44, 22] },
        { t: 35, rgb: [224, 12, 126] },
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
    const hi = [
        Math.min(255, Math.round(r + (255 - r) * 0.22)),
        Math.min(255, Math.round(g + (255 - g) * 0.22)),
        Math.min(255, Math.round(b + (255 - b) * 0.22)),
    ];
    const label = `${n.toFixed(decimals)}°`;
    return `<span class=\"temp-gradient-chip temp-gradient-text\" style=\"--temp-hi-rgb:${hi[0]},${hi[1]},${hi[2]};--temp-base-rgb:${r},${g},${b};\">${label}</span>`;
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
            ? formatClock(t)
            : '--:--';
        const temp = r.temperature !== null && r.temperature !== undefined ? tempChip(r.temperature, '°C', 0) : '--';
        const precip = r.precip_chance !== null && r.precip_chance !== undefined ? `${Number(r.precip_chance).toFixed(0)}%` : '-';
        return `<article class="card"><div class="forecast-row"><strong>${timeText}</strong></div><div class="forecast-row">${temp} ${escapeHtml(r.phrase || '')}</div><div class="forecast-row muted">Rain chance ${precip}</div></article>`;
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
        return `<article class="card"><div class="forecast-row"><strong>${escapeHtml(r.day_of_week || 'Day')}</strong></div><div class="forecast-row">High ${high} / Low ${low}</div><div class="forecast-row muted">${escapeHtml(r.narrative || '')}</div></article>`;
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

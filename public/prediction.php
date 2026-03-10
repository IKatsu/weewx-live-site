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
<?php render_page_head('PWS Prediction', $view); ?>
<body>
<div class="forecast-wrap">
<?php
render_site_header('Prediction', default_nav_links(), [
    '<div class="status-pill"><span>Run:</span> <strong id="pred-run">-</strong></div>',
    '<div class="status-pill"><span>Generated:</span> <strong id="pred-generated">-</strong></div>',
]);
?>

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
const PRED_STATE = { items: [] };

function setTheme(theme) {
    if (!PRED_APP.themes.includes(theme)) return;
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('pws_theme', theme); } catch {}
    if (PRED_STATE.items.length > 0) {
        renderHourWidgets(PRED_STATE.items);
    }
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

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function iconForMetric(metric) {
    const key = String(metric || '').toLowerCase();
    const base = 'assets/weathericons/';
    if (key === 'outtemp') return `${base}clear-day.svg`;
    if (key === 'outhumidity') return `${base}raindrop.svg`;
    if (key === 'barometer') return `${base}cloudy.svg`;
    if (key === 'windspeed') return `${base}wind.svg`;
    if (key === 'rainrate') return `${base}rain.svg`;
    return `${base}unknown.svg`;
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

function parseHexColor(raw) {
    const c = String(raw || '').trim();
    if (!/^#([0-9a-fA-F]{6})$/.test(c)) {
        return [160, 160, 160];
    }
    return [
        parseInt(c.slice(1, 3), 16),
        parseInt(c.slice(3, 5), 16),
        parseInt(c.slice(5, 7), 16),
    ];
}

function rgbToHex(rgb) {
    const toHex = (x) => Math.max(0, Math.min(255, Math.round(x))).toString(16).padStart(2, '0');
    return `#${toHex(rgb[0])}${toHex(rgb[1])}${toHex(rgb[2])}`;
}

function mixColor(a, b, t) {
    return [
        a[0] + ((b[0] - a[0]) * t),
        a[1] + ((b[1] - a[1]) * t),
        a[2] + ((b[2] - a[2]) * t),
    ];
}

function confidenceBaseColor(conf) {
    const cs = getComputedStyle(document.documentElement);
    const low = parseHexColor(cs.getPropertyValue('--confidence-low'));
    const mid = parseHexColor(cs.getPropertyValue('--confidence-mid'));
    const high = parseHexColor(cs.getPropertyValue('--confidence-high'));
    const c = Math.max(0, Math.min(1, Number(conf) || 0));
    if (c <= 0.5) {
        return mixColor(low, mid, c / 0.5);
    }
    return mixColor(mid, high, (c - 0.5) / 0.5);
}

function confidenceSvgDataUrl(conf) {
    const base = confidenceBaseColor(conf);
    const hi = mixColor(base, [255, 255, 255], 0.28);
    const lo = mixColor(base, [0, 0, 0], 0.18);
    const accent = mixColor(base, [255, 255, 255], 0.45);
    const svg = `
<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 60' preserveAspectRatio='none'>
  <defs>
    <linearGradient id='bg' x1='0' y1='0' x2='1' y2='1'>
      <stop offset='0%' stop-color='${rgbToHex(hi)}'/>
      <stop offset='58%' stop-color='${rgbToHex(base)}'/>
      <stop offset='100%' stop-color='${rgbToHex(lo)}'/>
    </linearGradient>
    <radialGradient id='glow' cx='0.85' cy='0.15' r='0.6'>
      <stop offset='0%' stop-color='${rgbToHex(accent)}' stop-opacity='0.85'/>
      <stop offset='100%' stop-color='${rgbToHex(accent)}' stop-opacity='0'/>
    </radialGradient>
  </defs>
  <rect width='100' height='60' fill='url(#bg)'/>
  <circle cx='86' cy='8' r='26' fill='url(#glow)'/>
  <path d='M0,58 C20,40 40,64 60,46 C76,34 88,54 100,45 L100,60 L0,60 Z' fill='rgba(255,255,255,0.16)'/>
</svg>`;
    return `url("data:image/svg+xml,${encodeURIComponent(svg)}")`;
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

        const title = document.createElement('h4');
        title.className = 'prediction-hour-title';
        title.textContent = fmtHour(hourKey);
        card.appendChild(title);

        const grid = document.createElement('div');
        grid.className = 'prediction-metric-grid';

        for (const item of rows) {
            const band = confidenceBand(item?.confidence);
            const metric = document.createElement('article');
            metric.className = `prediction-mini ${band}`;
            metric.style.setProperty('--prediction-svg', confidenceSvgDataUrl(item?.confidence));
            const metricLabel = String(item?.details?.label || item?.metric || 'metric');
            const metricIcon = iconForMetric(item?.metric);
            const horizon = Number(item?.details?.horizon_hours);
            const slope = Number(item?.details?.slope_per_hour);
            const arrow = arrowForSlope(slope);
            const trendLine = Number.isFinite(horizon) ? `${horizon}h, ${arrow} ${fmtNumber(Math.abs(slope), 2)}` : `${arrow} ${fmtNumber(Math.abs(slope), 2)}`;
            const displayConfidence = Number(item?.display_confidence ?? item?.confidence ?? 0);
            metric.innerHTML = `
                <div class="prediction-mini-bg" aria-hidden="true"></div>
                <div class="prediction-mini-content">
                    <div class="prediction-mini-label"><img class="prediction-mini-icon" src="${escapeHtml(metricIcon)}" alt="${escapeHtml(metricLabel)}" title="${escapeHtml(metricLabel)}"></div>
                    <div class="prediction-mini-value">${fmtNumber(item?.value_num, 2)} ${escapeHtml(item?.unit || '')}</div>
                    <div class="prediction-mini-meta">${escapeHtml(trendLine)}</div>
                    <div class="prediction-mini-confidence">${fmtNumber(displayConfidence * 100, 0)}% confidence</div>
                </div>
            `;
            metric.className = `prediction-mini ${confidenceBand(displayConfidence)}`;
            metric.style.setProperty('--prediction-svg', confidenceSvgDataUrl(displayConfidence));
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
    PRED_STATE.items = Array.isArray(payload.items) ? payload.items : [];
    renderHourWidgets(PRED_STATE.items);
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

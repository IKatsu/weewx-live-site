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
$location = (array) ($config['location'] ?? []);
$lat = (float) ($location['latitude'] ?? 0.0);
$lon = (float) ($location['longitude'] ?? 0.0);
$timezone = (string) (($location['timezone'] ?? 'UTC') ?: 'UTC');
$defaultTheme = (string) $view['default_theme'];
$timeFormat = (string) (($config['ui']['time']['format'] ?? '24h') ?: '24h');
?>
<?php render_page_head('Celestial Almanac', $view); ?>
<body>
<div class="container celestial-page">
<?php
render_site_header('Celestial Almanac', default_nav_links($config), [
    '<div class="status-pill"><span>Location:</span> <strong id="celestial-location">-</strong></div>',
    '<div class="status-pill"><span>Now:</span> <strong id="celestial-now">-</strong></div>',
]);
?>

    <section class="charts celestial-charts celestial-full">
        <article class="chart-card celestial-sky-card">
            <h2 class="chart-title">Sky Map</h2>
            <canvas id="celestial-sky" width="900" height="900"></canvas>
        </article>
    </section>

    <section class="charts celestial-charts">
        <article class="card">
            <h2 class="chart-title">Sun</h2>
            <div id="sun-details" class="celestial-detail-grid"></div>
        </article>
        <article class="card">
            <h2 class="chart-title">Moon</h2>
            <div id="moon-details" class="celestial-detail-grid"></div>
        </article>
    </section>

    <section class="charts celestial-charts celestial-full">
        <article class="chart-card">
            <h2 class="chart-title">Lunar Month</h2>
            <canvas id="celestial-lunation" width="760" height="240"></canvas>
        </article>
    </section>

    <section class="charts celestial-charts celestial-full">
        <article class="chart-card">
            <h2 class="chart-title">Rise &amp; Set - Today</h2>
            <canvas id="celestial-visibility" width="1200" height="320"></canvas>
        </article>
    </section>

    <section class="charts celestial-charts">
        <article class="chart-card">
            <h2 class="chart-title">Moon Phase</h2>
            <canvas id="celestial-moon" width="520" height="320"></canvas>
            <div id="phase-details" class="celestial-detail-grid celestial-phase-grid"></div>
        </article>
        <article class="chart-card">
            <h2 class="chart-title">Solar System - Today</h2>
            <canvas id="celestial-solar-system" width="760" height="620"></canvas>
        </article>
    </section>

    <section class="charts celestial-charts celestial-full">
        <article class="chart-card">
            <h2 class="chart-title">The Sun's Path - Today</h2>
            <canvas id="celestial-sunpath" width="900" height="640"></canvas>
        </article>
    </section>

    <section class="charts celestial-charts celestial-full">
        <article class="chart-card">
            <h2 class="chart-title">Solar Year - Daylight Week By Week</h2>
            <canvas id="celestial-daylight-year" width="1200" height="420"></canvas>
        </article>
    </section>

    <section class="charts celestial-charts celestial-full">
        <article class="card">
            <h2 class="chart-title">Almanac</h2>
            <div id="celestial-almanac-table" class="celestial-tablewrap"></div>
        </article>
    </section>

    <section class="charts celestial-charts">
        <article class="card">
            <h2 class="chart-title">Twilight</h2>
            <div id="twilight-details" class="celestial-detail-grid"></div>
        </article>
        <article class="card">
            <h2 class="chart-title">Time</h2>
            <div id="time-details" class="celestial-detail-grid"></div>
        </article>
    </section>

    <section class="charts celestial-charts">
        <article class="card">
            <h2 class="chart-title">Planets</h2>
            <div id="planet-details" class="celestial-chip-grid"></div>
        </article>
        <article class="card">
            <h2 class="chart-title">Skyfield Cache</h2>
            <div id="cache-details" class="celestial-detail-grid"></div>
        </article>
    </section>
</div>

<!-- SunCalc reference: https://github.com/mourner/suncalc -->
<script src="https://cdn.jsdelivr.net/npm/suncalc@1.9.0/suncalc.js"></script>
<script>
const CELESTIAL = {
    defaultTheme: <?= json_encode($defaultTheme) ?>,
    themes: <?= json_encode(array_keys((array) $view['css_themes'])) ?>,
    timeFormat: <?= json_encode($timeFormat) ?>,
    location: {
        latitude: <?= json_encode($lat) ?>,
        longitude: <?= json_encode($lon) ?>,
        timezone: <?= json_encode($timezone) ?>,
    },
};

const SYNODIC_MONTH_DAYS = 29.530588853;
const CELESTIAL_CACHE = {
    daily: null,
    monthly: null,
    yearly: null,
    catalog: null,
};
const CELESTIAL_BODY_ORDER = ['sun', 'moon', 'mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune'];
const CELESTIAL_BODY_LABELS = {
    sun: 'Sun',
    moon: 'Moon',
    earth: 'Earth',
    mercury: 'Mercury',
    venus: 'Venus',
    mars: 'Mars',
    jupiter: 'Jupiter',
    saturn: 'Saturn',
    uranus: 'Uranus',
    neptune: 'Neptune',
};
const CELESTIAL_BODY_COLORS = {
    sun: '#f6c44f',
    moon: '#cdd7f2',
    earth: '#4f9df6',
    mercury: '#a9b0c0',
    venus: '#f0d38c',
    mars: '#e46f55',
    jupiter: '#d99a58',
    saturn: '#d6b766',
    uranus: '#55b6ca',
    neptune: '#6a8ee8',
};

function setTheme(theme) {
    if (!CELESTIAL.themes.includes(theme)) return;
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('pws_theme', theme);
    requestAnimationFrame(renderCelestial);
}

function initThemeSelector() {
    const select = document.getElementById('theme-select');
    if (!select) return;
    for (const theme of CELESTIAL.themes) {
        const opt = document.createElement('option');
        opt.value = theme;
        opt.textContent = theme;
        select.appendChild(opt);
    }
    const saved = localStorage.getItem('pws_theme');
    const theme = CELESTIAL.themes.includes(saved) ? saved : CELESTIAL.defaultTheme;
    select.value = theme;
    setTheme(theme);
    select.addEventListener('change', () => setTheme(select.value));
}

function cssVar(name, fallback) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
}

function colorMix(hexA, hexB, ratio = 0.5) {
    const parse = (hex) => {
        const clean = String(hex || '').trim().replace('#', '');
        if (!/^[0-9a-f]{6}$/i.test(clean)) return null;
        return [0, 2, 4].map((idx) => parseInt(clean.slice(idx, idx + 2), 16));
    };
    const a = parse(hexA);
    const b = parse(hexB);
    if (!a || !b) return hexA || hexB || '#ffffff';
    const mix = a.map((part, idx) => Math.round(part * (1 - ratio) + b[idx] * ratio));
    return `rgb(${mix[0]}, ${mix[1]}, ${mix[2]})`;
}

function formatClock(dateObj) {
    if (!(dateObj instanceof Date) || Number.isNaN(dateObj.getTime())) return 'n/a';
    return dateObj.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: CELESTIAL.location.timezone || 'UTC',
        hour12: CELESTIAL.timeFormat !== '24h',
    });
}

function formatDateTime(dateObj) {
    if (!(dateObj instanceof Date) || Number.isNaN(dateObj.getTime())) return 'n/a';
    return dateObj.toLocaleString([], {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: CELESTIAL.location.timezone || 'UTC',
        hour12: CELESTIAL.timeFormat !== '24h',
    });
}

function formatDuration(ms) {
    if (!Number.isFinite(ms) || ms < 0) return 'n/a';
    const totalMinutes = Math.round(ms / 60000);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return `${hours}h ${String(minutes).padStart(2, '0')}m`;
}

function degrees(rad) {
    return rad * 180 / Math.PI;
}

function normalizeDegrees(value) {
    return ((value % 360) + 360) % 360;
}

function compassLabel(deg) {
    if (!Number.isFinite(deg)) return 'n/a';
    const dirs = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
    return dirs[Math.round(normalizeDegrees(deg) / 22.5) % dirs.length];
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[char]);
}

function detailRows(targetId, rows) {
    const host = document.getElementById(targetId);
    if (!host) return;
    host.innerHTML = rows.map(([label, value]) => `
        <div class="celestial-detail">
            <span>${label}</span>
            <strong>${value}</strong>
        </div>
    `).join('');
}

function chipRows(targetId, rows) {
    const host = document.getElementById(targetId);
    if (!host) return;
    host.innerHTML = rows.map((row) => `
        <div class="celestial-chip">
            <strong>${row.title}</strong>
            <span>${row.line1}</span>
            <span>${row.line2}</span>
            <span>${row.line3}</span>
        </div>
    `).join('');
}

function localDayBounds(now) {
    const tz = CELESTIAL.location.timezone || 'UTC';
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: tz,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(now);
    const get = (type) => parts.find((p) => p.type === type)?.value;
    const y = Number(get('year'));
    const m = Number(get('month'));
    const d = Number(get('day'));
    const approxUtc = Date.UTC(y, m - 1, d, 0, 0, 0);
    const localMidnightApprox = new Date(approxUtc);
    const offsetMinutes = timezoneOffsetMinutes(localMidnightApprox, tz);
    const start = new Date(approxUtc - offsetMinutes * 60000);
    return { start, end: new Date(start.getTime() + 86400000) };
}

function timezoneOffsetMinutes(date, timeZone) {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);
    const map = Object.fromEntries(parts.map((p) => [p.type, p.value]));
    const asUtc = Date.UTC(Number(map.year), Number(map.month) - 1, Number(map.day), Number(map.hour), Number(map.minute), Number(map.second));
    return (asUtc - date.getTime()) / 60000;
}

function sampleBody(kind, start, end, stepMinutes = 5) {
    const cached = cachedPath(kind);
    if (cached.length > 0) return cached;

    const lat = CELESTIAL.location.latitude;
    const lon = CELESTIAL.location.longitude;
    const rows = [];
    for (let t = start.getTime(); t <= end.getTime(); t += stepMinutes * 60000) {
        const date = new Date(t);
        const pos = kind === 'sun'
            ? SunCalc.getPosition(date, lat, lon)
            : SunCalc.getMoonPosition(date, lat, lon);
        rows.push({
            date,
            alt: degrees(pos.altitude),
            az: normalizeDegrees(degrees(pos.azimuth) + 180),
        });
    }
    return rows;
}

function cachedPath(kind) {
    const path = CELESTIAL_CACHE.daily?.payload?.paths?.[kind];
    if (!Array.isArray(path)) return [];
    return path.map((row) => ({
        date: new Date(String(row.time || '')),
        alt: Number(row.altitude),
        az: Number(row.azimuth),
        distanceAu: Number(row.distanceAu),
    })).filter((row) => !Number.isNaN(row.date.getTime()) && Number.isFinite(row.alt) && Number.isFinite(row.az));
}

function availableBodies() {
    const paths = CELESTIAL_CACHE.daily?.payload?.paths || {};
    const bodies = CELESTIAL_CACHE.daily?.payload?.bodies || {};
    return CELESTIAL_BODY_ORDER.filter((name) => Array.isArray(paths[name]) || bodies[name]);
}

function bodyColor(name) {
    return CELESTIAL_BODY_COLORS[name] || cssVar('--accent', '#0f6ecf');
}

function bodyLabel(name) {
    return CELESTIAL_BODY_LABELS[name] || name.charAt(0).toUpperCase() + name.slice(1);
}

function currentBodyPosition(kind, now) {
    const rows = cachedPath(kind);
    if (rows.length >= 2) {
        const target = now.getTime();
        for (let i = 1; i < rows.length; i++) {
            const prev = rows[i - 1];
            const next = rows[i];
            const pTime = prev.date.getTime();
            const nTime = next.date.getTime();
            if (target < pTime || target > nTime) continue;
            const f = nTime === pTime ? 0 : (target - pTime) / (nTime - pTime);
            let azDelta = next.az - prev.az;
            if (azDelta > 180) azDelta -= 360;
            if (azDelta < -180) azDelta += 360;
            return {
                date: now,
                alt: prev.alt + (next.alt - prev.alt) * f,
                az: normalizeDegrees(prev.az + azDelta * f),
                distanceAu: Number.isFinite(prev.distanceAu) && Number.isFinite(next.distanceAu)
                    ? prev.distanceAu + (next.distanceAu - prev.distanceAu) * f
                    : null,
            };
        }
        return target < rows[0].date.getTime() ? rows[0] : rows[rows.length - 1];
    }

    if (!window.SunCalc || !['sun', 'moon'].includes(kind)) return null;
    const raw = kind === 'sun'
        ? SunCalc.getPosition(now, CELESTIAL.location.latitude, CELESTIAL.location.longitude)
        : SunCalc.getMoonPosition(now, CELESTIAL.location.latitude, CELESTIAL.location.longitude);
    return {
        date: now,
        alt: degrees(raw.altitude),
        az: normalizeDegrees(degrees(raw.azimuth) + 180),
        distanceAu: null,
    };
}

function pathVisibilityIntervals(rows, start, end) {
    const intervals = [];
    let active = null;
    for (const row of rows) {
        if (row.date < start || row.date > end) continue;
        if (row.alt >= 0 && active === null) {
            active = { start: row.date, end: row.date };
        } else if (row.alt >= 0 && active !== null) {
            active.end = row.date;
        } else if (row.alt < 0 && active !== null) {
            intervals.push(active);
            active = null;
        }
    }
    if (active !== null) intervals.push(active);
    return intervals;
}

function catalogPayload() {
    return CELESTIAL_CACHE.catalog || {};
}

function drawCatalogLayer(ctx, cx, cy, radius, text, muted) {
    const catalog = catalogPayload();
    const stars = Array.isArray(catalog.stars) ? catalog.stars : [];
    const lines = Array.isArray(catalog.constellationLines) ? catalog.constellationLines : [];
    const labels = Array.isArray(catalog.constellationLabels) ? catalog.constellationLabels : [];
    if (stars.length === 0 && lines.length === 0) return;

    ctx.save();
    ctx.beginPath();
    ctx.arc(cx, cy, radius, 0, Math.PI * 2);
    ctx.clip();

    ctx.strokeStyle = colorMix(muted, '#000000', 0.12);
    ctx.lineWidth = 0.7;
    ctx.globalAlpha = 0.42;
    for (const line of lines) {
        const points = Array.isArray(line.points) ? line.points : [];
        if (points.length < 2) continue;
        ctx.beginPath();
        let started = false;
        for (const point of points) {
            const alt = Number(point.altitude);
            const az = Number(point.azimuth);
            if (!Number.isFinite(alt) || !Number.isFinite(az) || alt < -5) {
                started = false;
                continue;
            }
            const pt = projectSky(az, alt, cx, cy, radius);
            if (!started) {
                ctx.moveTo(pt.x, pt.y);
                started = true;
            } else {
                ctx.lineTo(pt.x, pt.y);
            }
        }
        ctx.stroke();
    }
    ctx.globalAlpha = 1;

    for (const star of stars) {
        const alt = Number(star.altitude);
        const az = Number(star.azimuth);
        const mag = Number(star.magnitude);
        if (!Number.isFinite(alt) || !Number.isFinite(az) || alt < 0) continue;
        const pt = projectSky(az, alt, cx, cy, radius);
        const brightness = Math.max(0.18, Math.min(1, (6.5 - (Number.isFinite(mag) ? mag : 6)) / 6.5));
        const dot = Math.max(0.75, Math.min(2.8, 2.9 - (Number.isFinite(mag) ? mag : 6) * 0.32));
        ctx.fillStyle = `rgba(238, 232, 206, ${0.38 + brightness * 0.55})`;
        ctx.beginPath();
        ctx.arc(pt.x, pt.y, dot, 0, Math.PI * 2);
        ctx.fill();
    }

    ctx.fillStyle = colorMix(text, muted, 0.34);
    ctx.font = '10px Source Sans 3, Segoe UI, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    for (const label of labels) {
        const alt = Number(label.altitude);
        const az = Number(label.azimuth);
        if (!Number.isFinite(alt) || !Number.isFinite(az) || alt < 4) continue;
        const pt = projectSky(az, alt, cx, cy, radius);
        ctx.fillText(String(label.name || label.abbr || ''), pt.x, pt.y);
    }
    ctx.restore();
}

function projectSky(az, alt, cx, cy, radius) {
    const horizonAlt = -6;
    const clampedAlt = Math.max(horizonAlt, Math.min(90, alt));
    const r = radius * (1 - ((clampedAlt - horizonAlt) / (90 - horizonAlt)));
    const angle = (az - 90) * Math.PI / 180;
    return {
        x: cx + Math.cos(angle) * r,
        y: cy + Math.sin(angle) * r,
    };
}

function setupCanvas(canvas) {
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.max(1, Math.round(rect.width * dpr));
    canvas.height = Math.max(1, Math.round(rect.height * dpr));
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx, width: rect.width, height: rect.height };
}

function canvasNotice(canvasId, message) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const { ctx, width, height } = setupCanvas(canvas);
    ctx.clearRect(0, 0, width, height);
    ctx.fillStyle = cssVar('--muted', '#5b6f86');
    ctx.font = '600 14px Source Sans 3, Segoe UI, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(message, width / 2, height / 2);
}

function drawSkyMap(now) {
    const canvas = document.getElementById('celestial-sky');
    if (!canvas || !window.SunCalc) return;
    const { ctx, width, height } = setupCanvas(canvas);
    const text = cssVar('--text', '#102137');
    const muted = cssVar('--muted', '#5b6f86');
    const border = cssVar('--border', '#d7e1ec');
    const accent = cssVar('--accent', '#0f6ecf');
    const { start, end } = localDayBounds(now);
    const sunPath = sampleBody('sun', start, end, 5);
    const moonPath = sampleBody('moon', start, end, 5);
    const cx = width / 2;
    const cy = height / 2;
    const radius = Math.min(width, height) * 0.43;

    ctx.clearRect(0, 0, width, height);
    const sky = ctx.createRadialGradient(cx, cy, radius * 0.1, cx, cy, radius);
    sky.addColorStop(0, 'rgba(70, 126, 190, 0.18)');
    sky.addColorStop(1, 'rgba(20, 38, 68, 0.1)');
    ctx.fillStyle = sky;
    ctx.beginPath();
    ctx.arc(cx, cy, radius, 0, Math.PI * 2);
    ctx.fill();

    ctx.strokeStyle = border;
    ctx.lineWidth = 1;
    for (const pct of [0.25, 0.5, 0.75, 1]) {
        ctx.beginPath();
        ctx.arc(cx, cy, radius * pct, 0, Math.PI * 2);
        ctx.stroke();
    }
    for (const az of [0, 45, 90, 135, 180, 225, 270, 315]) {
        const outer = projectSky(az, -6, cx, cy, radius);
        const inner = projectSky(az, 90, cx, cy, radius);
        ctx.beginPath();
        ctx.moveTo(inner.x, inner.y);
        ctx.lineTo(outer.x, outer.y);
        ctx.stroke();
    }

    ctx.fillStyle = text;
    ctx.font = '600 13px Source Sans 3, Segoe UI, sans-serif';
    for (const [label, az] of [['N', 0], ['E', 90], ['S', 180], ['W', 270]]) {
        const pt = projectSky(az, -9, cx, cy, radius);
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(label, pt.x, pt.y);
    }

    drawCatalogLayer(ctx, cx, cy, radius, text, muted);

    function drawPath(rows, color, widthPx) {
        ctx.strokeStyle = color;
        ctx.lineWidth = widthPx;
        ctx.beginPath();
        let started = false;
        for (const row of rows) {
            if (row.alt < -6) {
                started = false;
                continue;
            }
            const pt = projectSky(row.az, row.alt, cx, cy, radius);
            if (!started) {
                ctx.moveTo(pt.x, pt.y);
                started = true;
            } else {
                ctx.lineTo(pt.x, pt.y);
            }
        }
        ctx.stroke();
    }

    drawPath(sunPath, 'rgba(245, 176, 44, 0.95)', 3);
    drawPath(moonPath, 'rgba(170, 184, 222, 0.9)', 2);

    for (const name of availableBodies()) {
        const pos = currentBodyPosition(name, now);
        if (!pos || pos.alt < 0) continue;
        const pt = projectSky(pos.az, pos.alt, cx, cy, radius);
        const isMajor = name === 'sun' || name === 'moon';
        const dotRadius = name === 'sun' ? 8 : (name === 'moon' ? 7 : 4.5);
        ctx.fillStyle = bodyColor(name);
        ctx.beginPath();
        ctx.arc(pt.x, pt.y, dotRadius, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = name === 'sun' ? '#fff4bf' : colorMix(bodyColor(name), text, 0.35);
        ctx.lineWidth = isMajor ? 2 : 1.4;
        ctx.stroke();
        if (isMajor || pos.alt > 6) {
            ctx.fillStyle = text;
            ctx.font = `${isMajor ? '600 ' : ''}11px Source Sans 3, Segoe UI, sans-serif`;
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.fillText(bodyLabel(name), pt.x + dotRadius + 5, pt.y);
        }
    }

    ctx.fillStyle = muted;
    ctx.font = '12px Source Sans 3, Segoe UI, sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText('Sun path', 14, height - 34);
    ctx.fillStyle = accent;
    ctx.fillRect(74, height - 42, 22, 3);
    ctx.fillStyle = muted;
    ctx.fillText('Moon path', 14, height - 14);
    ctx.fillStyle = '#aab8de';
    ctx.fillRect(86, height - 22, 22, 3);
}

function drawVisibility(now) {
    const canvas = document.getElementById('celestial-visibility');
    if (!canvas) return;
    const { ctx, width, height } = setupCanvas(canvas);
    const text = cssVar('--text', '#102137');
    const muted = cssVar('--muted', '#5b6f86');
    const border = cssVar('--border', '#d7e1ec');
    const { start, end } = localDayBounds(now);
    const bodies = availableBodies().filter((name) => cachedPath(name).length > 0 || ['sun', 'moon'].includes(name));
    const left = 92;
    const right = width - 72;
    const top = 26;
    const rowGap = 25;
    const rowHeight = 8;
    const bottom = Math.min(height - 26, top + Math.max(1, bodies.length) * rowGap + 16);

    ctx.clearRect(0, 0, width, height);
    function xFor(date) {
        return left + ((date.getTime() - start.getTime()) / (end.getTime() - start.getTime())) * (right - left);
    }

    ctx.fillStyle = colorMix(cssVar('--card', '#102137'), cssVar('--accent-soft', '#2d6cdf'), 0.24);
    ctx.fillRect(left, top - 8, right - left, bottom - top + 18);
    ctx.strokeStyle = border;
    ctx.lineWidth = 1;
    ctx.strokeRect(left, top - 8, right - left, bottom - top + 18);

    ctx.font = '12px Source Sans 3, Segoe UI, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillStyle = muted;
    for (let h = 0; h <= 24; h += 3) {
        const x = left + (h / 24) * (right - left);
        ctx.strokeStyle = h % 6 === 0 ? border : colorMix(border, cssVar('--card', '#102137'), 0.6);
        ctx.beginPath();
        ctx.moveTo(x, top - 8);
        ctx.lineTo(x, bottom + 10);
        ctx.stroke();
        ctx.fillText(`${String(h).padStart(2, '0')}`, x, height - 9);
    }

    ctx.textAlign = 'left';
    bodies.forEach((name, idx) => {
        const y = top + idx * rowGap;
        const rows = sampleBody(name, start, end, 10);
        const intervals = pathVisibilityIntervals(rows, start, end);
        ctx.fillStyle = bodyColor(name);
        ctx.beginPath();
        ctx.arc(18, y, 4, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = text;
        ctx.font = '600 12px Source Sans 3, Segoe UI, sans-serif';
        ctx.fillText(bodyLabel(name), 28, y + 4);

        ctx.strokeStyle = colorMix(border, bodyColor(name), 0.16);
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(left, y);
        ctx.lineTo(right, y);
        ctx.stroke();

        ctx.fillStyle = bodyColor(name);
        for (const interval of intervals) {
            const x0 = Math.max(left, xFor(interval.start));
            const x1 = Math.min(right, xFor(interval.end));
            ctx.fillRect(x0, y - rowHeight / 2, Math.max(2, x1 - x0), rowHeight);
        }

        const transit = CELESTIAL_CACHE.daily?.payload?.events?.[name]?.transit;
        if (transit) {
            const tx = xFor(new Date(transit));
            if (tx >= left && tx <= right) {
                ctx.strokeStyle = colorMix(bodyColor(name), text, 0.22);
                ctx.lineWidth = 1.5;
                ctx.beginPath();
                ctx.moveTo(tx, y - 8);
                ctx.lineTo(tx, y + 8);
                ctx.stroke();
            }
        }
    });

    const nowX = xFor(now);
    ctx.strokeStyle = cssVar('--accent', '#0f6ecf');
    ctx.lineWidth = 2;
    ctx.setLineDash([4, 4]);
    ctx.beginPath();
    ctx.moveTo(nowX, top - 13);
    ctx.lineTo(nowX, bottom + 15);
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.fillStyle = cssVar('--accent', '#0f6ecf');
    ctx.textAlign = 'center';
    ctx.font = '600 11px Source Sans 3, Segoe UI, sans-serif';
    ctx.fillText(`now ${formatClock(now)}`, Math.max(left + 34, Math.min(right - 34, nowX)), top - 16);
}

function drawSunPath(now) {
    const canvas = document.getElementById('celestial-sunpath');
    if (!canvas) return;
    const { ctx, width, height } = setupCanvas(canvas);
    const text = cssVar('--text', '#102137');
    const muted = cssVar('--muted', '#5b6f86');
    const border = cssVar('--border', '#d7e1ec');
    const card = cssVar('--card', '#101828');
    const { start, end } = localDayBounds(now);
    const sun = sampleBody('sun', start, end, 10);
    const moon = sampleBody('moon', start, end, 10);
    const floor = -24;
    const topAlt = Math.min(94, Math.max(48, ...sun.concat(moon).map((row) => row.alt).filter(Number.isFinite)) + 8);
    const left = 54;
    const right = width - 22;
    const top = 18;
    const bottom = height - 42;

    function xForAz(az) {
        const wrapped = ((az % 360) + 360) % 360;
        const chartAz = wrapped === 0 && az > 0 ? 360 : wrapped;
        return left + (chartAz / 360) * (right - left);
    }
    function yForAlt(alt) {
        const clamped = Math.max(floor, Math.min(topAlt, alt));
        return top + ((topAlt - clamped) / (topAlt - floor)) * (bottom - top);
    }
    function band(hi, lo, color) {
        const y0 = yForAlt(Math.min(hi, topAlt));
        const y1 = yForAlt(Math.max(lo, floor));
        if (y1 <= y0) return;
        ctx.fillStyle = color;
        ctx.fillRect(left, y0, right - left, y1 - y0);
    }
    function drawAzPath(rows, color, lineWidth, dash = []) {
        ctx.strokeStyle = color;
        ctx.lineWidth = lineWidth;
        ctx.setLineDash(dash);
        ctx.beginPath();
        let started = false;
        let prev = null;
        for (const row of rows) {
            if (row.alt < floor) {
                if (started) ctx.stroke();
                ctx.beginPath();
                started = false;
                prev = null;
                continue;
            }
            const x = xForAz(row.az);
            const y = yForAlt(row.alt);
            if (prev !== null && Math.abs(row.az - prev.az) > 180) {
                const wrappedAz = row.az < prev.az ? row.az + 360 : row.az - 360;
                const seamAz = row.az < prev.az ? 360 : 0;
                const ratio = (seamAz - prev.az) / (wrappedAz - prev.az);
                const seamAlt = prev.alt + (row.alt - prev.alt) * ratio;
                const seamY = yForAlt(seamAlt);

                if (started) {
                    ctx.lineTo(xForAz(seamAz), seamY);
                    ctx.stroke();
                }

                ctx.beginPath();
                ctx.moveTo(xForAz(seamAz === 360 ? 0 : 360), seamY);
                ctx.lineTo(x, y);
                started = true;
                prev = row;
                continue;
            }
            if (!started) {
                ctx.moveTo(x, y);
                started = true;
            } else {
                ctx.lineTo(x, y);
            }
            prev = row;
        }
        if (started) ctx.stroke();
        ctx.setLineDash([]);
    }
    function marker(name, radius) {
        const pos = currentBodyPosition(name, now);
        if (!pos || pos.alt < floor) return;
        const x = xForAz(pos.az);
        const y = yForAlt(pos.alt);
        ctx.fillStyle = bodyColor(name);
        ctx.beginPath();
        ctx.arc(x, y, radius, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = colorMix(bodyColor(name), text, 0.28);
        ctx.lineWidth = 1.5;
        ctx.stroke();
        ctx.fillStyle = text;
        ctx.font = '600 11px Source Sans 3, Segoe UI, sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(bodyLabel(name), x + radius + 5, y + 4);
    }

    ctx.clearRect(0, 0, width, height);
    band(topAlt, 0, colorMix(card, '#f6c44f', 0.18));
    band(0, -6, colorMix(card, '#6f95c8', 0.28));
    band(-6, -12, colorMix(card, '#4b638c', 0.28));
    band(-12, -18, colorMix(card, '#2d3b62', 0.28));
    band(-18, floor, colorMix(card, '#101828', 0.25));

    ctx.strokeStyle = border;
    ctx.lineWidth = 1;
    ctx.strokeRect(left, top, right - left, bottom - top);
    ctx.font = '12px Source Sans 3, Segoe UI, sans-serif';
    for (const alt of [0, 30, 60, 90]) {
        if (alt > topAlt) continue;
        const y = yForAlt(alt);
        ctx.strokeStyle = alt === 0 ? text : border;
        ctx.beginPath();
        ctx.moveTo(left, y);
        ctx.lineTo(right, y);
        ctx.stroke();
        ctx.fillStyle = muted;
        ctx.textAlign = 'right';
        ctx.fillText(`${alt}°`, left - 8, y + 4);
    }
    for (const az of [0, 45, 90, 135, 180, 225, 270, 315, 360]) {
        const x = xForAz(az);
        ctx.strokeStyle = az % 90 === 0 ? border : colorMix(border, card, 0.55);
        ctx.beginPath();
        ctx.moveTo(x, top);
        ctx.lineTo(x, bottom);
        ctx.stroke();
        ctx.fillStyle = muted;
        ctx.textAlign = 'center';
        const label = {0: 'N', 90: 'E', 180: 'S', 270: 'W', 360: 'N'}[az] || String(az);
        ctx.fillText(label, x, bottom + 20);
    }

    drawAzPath(moon, bodyColor('moon'), 2, [5, 5]);
    drawAzPath(sun, bodyColor('sun'), 3);
    for (const row of sun) {
        const localHour = Number(new Intl.DateTimeFormat('en-GB', {
            timeZone: CELESTIAL.location.timezone || 'UTC',
            hour: '2-digit',
            hourCycle: 'h23',
        }).format(row.date));
        const minute = Number(new Intl.DateTimeFormat('en-GB', {
            timeZone: CELESTIAL.location.timezone || 'UTC',
            minute: '2-digit',
        }).format(row.date));
        if (minute !== 0 || localHour % 3 !== 0 || row.alt < floor) continue;
        const x = xForAz(row.az);
        const y = yForAlt(row.alt);
        ctx.fillStyle = text;
        ctx.beginPath();
        ctx.arc(x, y, 2, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = muted;
        ctx.font = '10px Source Sans 3, Segoe UI, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(String(localHour).padStart(2, '0'), x, y - 7);
    }
    marker('moon', 6);
    marker('sun', 7);

    ctx.fillStyle = muted;
    ctx.font = '12px Source Sans 3, Segoe UI, sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText('Altitude against azimuth from midnight to midnight; dashed line is the Moon.', left, height - 10);
}

function drawMoonSymbol(now) {
    const canvas = document.getElementById('celestial-moon');
    if (!canvas || !window.SunCalc) return;
    const { ctx, width, height } = setupCanvas(canvas);
    const moon = SunCalc.getMoonIllumination(now);
    const cx = width / 2;
    const cy = height / 2;
    const r = Math.min(width, height) * 0.32;
    const phase = moon.phase;
    const illum = moon.fraction;
    const waxing = phase < 0.5;

    ctx.clearRect(0, 0, width, height);
    ctx.fillStyle = '#30394c';
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fill();
    ctx.save();
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.clip();
    ctx.fillStyle = '#f3ead8';
    const litWidth = Math.max(2, r * 2 * illum);
    const x = waxing ? cx + r - litWidth : cx - r;
    ctx.fillRect(x, cy - r, litWidth, r * 2);
    ctx.restore();
    ctx.strokeStyle = cssVar('--border', '#d7e1ec');
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.stroke();
}

function drawDaylightYear(now) {
    const canvas = document.getElementById('celestial-daylight-year');
    const weeks = CELESTIAL_CACHE.yearly?.payload?.daylightWeeks || [];
    if (!canvas) return;
    if (!Array.isArray(weeks) || weeks.length === 0) {
        canvasNotice('celestial-daylight-year', 'Rebuild the yearly celestial cache to show daylight week by week.');
        return;
    }
    const { ctx, width, height } = setupCanvas(canvas);
    const text = cssVar('--text', '#102137');
    const muted = cssVar('--muted', '#5b6f86');
    const border = cssVar('--border', '#d7e1ec');
    const accent = cssVar('--accent', '#0f6ecf');
    const card = cssVar('--card', '#101828');
    const left = 58;
    const right = width - 24;
    const top = 20;
    const bottom = height - 58;
    const plotW = right - left;
    const colW = plotW / weeks.length;
    const shade = {
        day: colorMix(card, '#f6c44f', 0.24),
        civil: colorMix(card, '#6f95c8', 0.30),
        nautical: colorMix(card, '#4b638c', 0.30),
        astronomical: colorMix(card, '#2d3b62', 0.32),
        night: colorMix(card, '#101828', 0.38),
    };
    const yForHour = (hour) => top + ((24 - hour) / 24) * (bottom - top);
    const xForWeek = (idx) => left + idx * colW + colW / 2;

    function fillBand(idx, fromHour, toHour, color) {
        if (!Number.isFinite(fromHour) || !Number.isFinite(toHour) || toHour <= fromHour) return;
        const x = left + idx * colW;
        ctx.fillStyle = color;
        ctx.fillRect(x, yForHour(toHour), colW + 0.5, yForHour(fromHour) - yForHour(toHour));
    }

    function fillColumn(idx, hours) {
        const events = [
            [0, 'night'],
            [Number(hours.astronomicalDawn), 'astronomical'],
            [Number(hours.nauticalDawn), 'nautical'],
            [Number(hours.civilDawn), 'civil'],
            [Number(hours.sunrise), 'day'],
            [Number(hours.sunset), 'civil'],
            [Number(hours.civilDusk), 'nautical'],
            [Number(hours.nauticalDusk), 'astronomical'],
            [Number(hours.astronomicalDusk), 'night'],
            [24, null],
        ].filter(([hour]) => Number.isFinite(hour) && hour >= 0 && hour <= 24)
            .sort((a, b) => a[0] - b[0]);

        let state = 'night';
        for (let i = 0; i < events.length - 1; i += 1) {
            const [hour, nextState] = events[i];
            const nextHour = events[i + 1][0];
            if (nextState) state = nextState;
            if (nextHour > hour) fillBand(idx, hour, nextHour, shade[state]);
        }
    }

    function drawCurve(key, color, dash = []) {
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.setLineDash(dash);
        ctx.beginPath();
        let started = false;
        weeks.forEach((week, idx) => {
            const hour = Number(week.hours?.[key]);
            if (!Number.isFinite(hour)) {
                if (started) ctx.stroke();
                ctx.beginPath();
                started = false;
                return;
            }
            const x = xForWeek(idx);
            const y = yForHour(hour);
            if (!started) {
                ctx.moveTo(x, y);
                started = true;
            } else {
                ctx.lineTo(x, y);
            }
        });
        if (started) ctx.stroke();
        ctx.setLineDash([]);
    }

    ctx.clearRect(0, 0, width, height);
    weeks.forEach((week, idx) => {
        fillColumn(idx, week.hours || {});
    });

    ctx.strokeStyle = border;
    ctx.lineWidth = 1;
    ctx.strokeRect(left, top, plotW, bottom - top);
    ctx.font = '11px Source Sans 3, Segoe UI, sans-serif';
    for (const hour of [0, 6, 12, 18, 24]) {
        const y = yForHour(hour);
        ctx.strokeStyle = hour === 12 ? colorMix(border, text, 0.35) : border;
        ctx.beginPath();
        ctx.moveTo(left, y);
        ctx.lineTo(right, y);
        ctx.stroke();
        ctx.fillStyle = muted;
        ctx.textAlign = 'right';
        ctx.fillText(`${String(hour).padStart(2, '0')}:00`, left - 8, y + 4);
    }
    const monthTicks = [];
    let lastMonth = '';
    weeks.forEach((week, idx) => {
        const date = new Date(`${week.date}T12:00:00`);
        if (Number.isNaN(date.getTime())) return;
        const month = new Intl.DateTimeFormat([], {
            timeZone: CELESTIAL.location.timezone || 'UTC',
            month: 'short',
        }).format(date);
        if (month !== lastMonth) {
            monthTicks.push({ idx, label: month });
            lastMonth = month;
        }
    });
    for (const tick of monthTicks) {
        const x = left + tick.idx * colW;
        ctx.strokeStyle = colorMix(border, card, 0.45);
        ctx.beginPath();
        ctx.moveTo(x, top);
        ctx.lineTo(x, bottom + 6);
        ctx.stroke();
        if (tick.idx % 2 === 0 || width > 900) {
            ctx.fillStyle = muted;
            ctx.textAlign = 'center';
            ctx.fillText(tick.label, Math.min(right - 10, Math.max(left + 10, x)), bottom + 22);
        }
    }
    drawCurve('sunrise', bodyColor('sun'));
    drawCurve('sunset', bodyColor('sun'));
    drawCurve('solarNoon', text, [4, 5]);
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: CELESTIAL.location.timezone || 'UTC' }).format(now);
    const todayIndex = weeks.findIndex((week) => String(week.date) >= today);
    const nowX = left + Math.max(0, todayIndex >= 0 ? todayIndex : weeks.length - 1) * colW;
    ctx.strokeStyle = accent;
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(nowX, top - 4);
    ctx.lineTo(nowX, bottom + 4);
    ctx.stroke();
    ctx.fillStyle = muted;
    ctx.textAlign = 'left';
    ctx.fillText('Sunrise / sunset curves with dashed solar noon; vertical line is today.', left, height - 10);
}

function drawSolarSystem() {
    const canvas = document.getElementById('celestial-solar-system');
    const bodies = CELESTIAL_CACHE.daily?.payload?.solarSystem || [];
    if (!canvas) return;
    if (!Array.isArray(bodies) || bodies.length === 0) {
        canvasNotice('celestial-solar-system', 'Rebuild the daily celestial cache to show the solar system map.');
        return;
    }
    const { ctx, width, height } = setupCanvas(canvas);
    const text = cssVar('--text', '#102137');
    const muted = cssVar('--muted', '#5b6f86');
    const border = cssVar('--border', '#d7e1ec');
    const cx = width / 2;
    const cy = height / 2;
    const maxR = Math.min(width, height) * 0.42;
    const lo = Math.log(0.38);
    const hi = Math.log(30.2);
    const rForAu = (au) => 38 + (maxR - 38) * (Math.log(Math.max(0.38, au)) - lo) / (hi - lo);

    ctx.clearRect(0, 0, width, height);
    ctx.strokeStyle = border;
    ctx.lineWidth = 1;
    for (const au of [0.39, 0.72, 1, 1.52, 5.2, 9.58, 19.2, 30.1]) {
        ctx.beginPath();
        ctx.arc(cx, cy, rForAu(au), 0, Math.PI * 2);
        ctx.stroke();
    }
    ctx.strokeStyle = colorMix(border, muted, 0.35);
    ctx.setLineDash([3, 5]);
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(cx + maxR + 18, cy);
    ctx.stroke();
    ctx.setLineDash([]);

    ctx.fillStyle = bodyColor('sun');
    ctx.beginPath();
    ctx.arc(cx, cy, 9, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = text;
    ctx.font = '600 11px Source Sans 3, Segoe UI, sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText('Sun', cx + 13, cy + 4);

    for (const body of bodies) {
        const name = String(body.body || '');
        if (name === 'sun') continue;
        const xAu = Number(body.xAu);
        const yAu = Number(body.yAu);
        const radiusAu = Number(body.radiusAu);
        if (!Number.isFinite(xAu) || !Number.isFinite(yAu) || !Number.isFinite(radiusAu)) continue;
        const angle = Math.atan2(yAu, xAu);
        const r = rForAu(radiusAu);
        const x = cx + Math.cos(angle) * r;
        const y = cy - Math.sin(angle) * r;
        const dot = name === 'earth' ? 5.5 : 4.5;
        ctx.fillStyle = bodyColor(name);
        ctx.beginPath();
        ctx.arc(x, y, dot, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = text;
        ctx.font = name === 'earth' ? '700 11px Source Sans 3, Segoe UI, sans-serif' : '600 10px Source Sans 3, Segoe UI, sans-serif';
        ctx.textAlign = x >= cx ? 'left' : 'right';
        ctx.fillText(bodyLabel(name), x + (x >= cx ? 8 : -8), y + 4);
    }
    ctx.fillStyle = muted;
    ctx.textAlign = 'left';
    ctx.font = '12px Source Sans 3, Segoe UI, sans-serif';
    ctx.fillText('Log-radius heliocentric plan view; directions are ecliptic longitude.', 18, height - 16);
}

function drawLunationStrip(now) {
    const canvas = document.getElementById('celestial-lunation');
    const lunation = CELESTIAL_CACHE.daily?.payload?.lunation || {};
    const days = Array.isArray(lunation.days) ? lunation.days : [];
    if (!canvas) return;
    if (days.length === 0) {
        canvasNotice('celestial-lunation', 'Rebuild the daily celestial cache to show the lunar month.');
        return;
    }
    const { ctx, width, height } = setupCanvas(canvas);
    const text = cssVar('--text', '#102137');
    const muted = cssVar('--muted', '#5b6f86');
    const border = cssVar('--border', '#d7e1ec');
    const accent = cssVar('--accent', '#0f6ecf');
    const left = 34;
    const right = width - 34;
    const y = 82;
    const r = Math.min(13, (right - left) / days.length * 0.42);
    const previous = new Date(String(lunation.previousNew || ''));
    const next = new Date(String(lunation.nextNew || ''));
    const span = next - previous;

    function drawDisc(x, illum, waxing) {
        ctx.fillStyle = '#30394c';
        ctx.beginPath();
        ctx.arc(x, y, r, 0, Math.PI * 2);
        ctx.fill();
        ctx.save();
        ctx.beginPath();
        ctx.arc(x, y, r, 0, Math.PI * 2);
        ctx.clip();
        ctx.fillStyle = '#f3ead8';
        const litWidth = Math.max(1, r * 2 * Math.max(0, Math.min(1, illum)));
        const startX = waxing ? x + r - litWidth : x - r;
        ctx.fillRect(startX, y - r, litWidth, r * 2);
        ctx.restore();
        ctx.strokeStyle = border;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.arc(x, y, r, 0, Math.PI * 2);
        ctx.stroke();
    }

    ctx.clearRect(0, 0, width, height);
    days.forEach((day, idx) => {
        const x = left + ((right - left) * idx / Math.max(1, days.length - 1));
        const angle = Number(day.phaseAngle);
        const illum = Number(day.illumination) / 100;
        drawDisc(x, illum, angle < 180);
    });

    if (!Number.isNaN(previous.getTime()) && !Number.isNaN(next.getTime()) && span > 0) {
        const tx = left + Math.max(0, Math.min(1, (now - previous) / span)) * (right - left);
        ctx.strokeStyle = accent;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(tx, y, r + 5, 0, Math.PI * 2);
        ctx.stroke();
        ctx.fillStyle = text;
        ctx.textAlign = 'center';
        ctx.font = '600 11px Source Sans 3, Segoe UI, sans-serif';
        ctx.fillText('today', tx, y - r - 12);
    }

    const phases = Array.isArray(lunation.phases) ? lunation.phases : [];
    phases.forEach((phase) => {
        const date = new Date(String(phase.time || ''));
        if (Number.isNaN(date.getTime()) || Number.isNaN(previous.getTime()) || Number.isNaN(next.getTime()) || date < previous || date > next) return;
        const x = left + ((date - previous) / span) * (right - left);
        ctx.strokeStyle = muted;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, y + r + 8);
        ctx.lineTo(x, y + r + 18);
        ctx.stroke();
        ctx.fillStyle = muted;
        ctx.textAlign = 'center';
        ctx.font = '10px Source Sans 3, Segoe UI, sans-serif';
        ctx.fillText(String(phase.label || '').replace(' Moon', ''), x, y + r + 33);
        ctx.fillText(formatClock(date), x, y + r + 47);
    });

    ctx.fillStyle = muted;
    ctx.textAlign = 'left';
    ctx.font = '12px Source Sans 3, Segoe UI, sans-serif';
    ctx.fillText(`${formatDateTime(previous)} to ${formatDateTime(next)}`, 18, height - 16);
}

function moonPhaseName(phase) {
    const p = ((phase % 1) + 1) % 1;
    if (p < 0.03 || p > 0.97) return 'New Moon';
    if (p < 0.22) return 'Waxing Crescent';
    if (p < 0.28) return 'First Quarter';
    if (p < 0.47) return 'Waxing Gibbous';
    if (p < 0.53) return 'Full Moon';
    if (p < 0.72) return 'Waning Gibbous';
    if (p < 0.78) return 'Last Quarter';
    return 'Waning Crescent';
}

function nextPhaseDate(now, currentPhase, targetPhase) {
    const delta = ((targetPhase - currentPhase + 1) % 1) || 1;
    return new Date(now.getTime() + delta * SYNODIC_MONTH_DAYS * 86400000);
}

function equationOfTimeMinutes(now) {
    const start = new Date(Date.UTC(now.getUTCFullYear(), 0, 0));
    const day = Math.floor((now - start) / 86400000);
    const b = (2 * Math.PI * (day - 81)) / 364;
    return 9.87 * Math.sin(2 * b) - 7.53 * Math.cos(b) - 1.5 * Math.sin(b);
}

function siderealTime(now) {
    const jd = now.getTime() / 86400000 + 2440587.5;
    const d = jd - 2451545.0;
    const gmst = 280.46061837 + 360.98564736629 * d;
    const lst = normalizeDegrees(gmst + CELESTIAL.location.longitude) / 15;
    const h = Math.floor(lst);
    const m = Math.floor((lst - h) * 60);
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function approximateMoonTransit(now) {
    const { start, end } = localDayBounds(now);
    const rows = sampleBody('moon', start, end, 10);
    return rows.reduce((best, row) => row.alt > best.alt ? row : best, rows[0]);
}

function renderDetails(now) {
    const lat = CELESTIAL.location.latitude;
    const lon = CELESTIAL.location.longitude;
    const sunPos = SunCalc.getPosition(now, lat, lon);
    const moonPos = SunCalc.getMoonPosition(now, lat, lon);
    const sunTimes = SunCalc.getTimes(now, lat, lon);
    const moonTimes = SunCalc.getMoonTimes(now, lat, lon);
    const moonIll = SunCalc.getMoonIllumination(now);
    const moonTransit = approximateMoonTransit(now);
    const dayLength = sunTimes.sunset - sunTimes.sunrise;
    const solarTime = ((12 + ((now - sunTimes.solarNoon) / 3600000)) % 24 + 24) % 24;
    const solarHours = Math.floor(solarTime);
    const solarMinutes = Math.floor((solarTime - solarHours) * 60);
    const cached = CELESTIAL_CACHE.daily?.payload || null;
    const cachedSun = cached?.bodies?.sun || null;
    const cachedMoon = cached?.bodies?.moon || null;
    const cachedMoonInfo = cached?.moon || null;
    const cachedEvents = cached?.events || {};
    const cachedTwilight = cached?.twilight || {};

    document.getElementById('celestial-location').textContent = `${lat.toFixed(4)}, ${lon.toFixed(4)}`;
    document.getElementById('celestial-now').textContent = formatClock(now);

    detailRows('sun-details', [
        ['Altitude', cachedSun ? `${Number(cachedSun.altitude).toFixed(1)}°` : `${degrees(sunPos.altitude).toFixed(1)}°`],
        ['Azimuth', cachedSun ? `${Number(cachedSun.azimuth).toFixed(1)}° ${compassLabel(Number(cachedSun.azimuth))}` : `${normalizeDegrees(degrees(sunPos.azimuth) + 180).toFixed(1)}° ${compassLabel(degrees(sunPos.azimuth) + 180)}`],
        ['Sunrise', cachedEvents.sun?.rise ? formatDateTime(new Date(cachedEvents.sun.rise)) : formatClock(sunTimes.sunrise)],
        ['Solar noon', cachedEvents.sun?.transit ? formatDateTime(new Date(cachedEvents.sun.transit)) : formatClock(sunTimes.solarNoon)],
        ['Sunset', cachedEvents.sun?.set ? formatDateTime(new Date(cachedEvents.sun.set)) : formatClock(sunTimes.sunset)],
        ['Day length', formatDuration(dayLength)],
    ]);

    detailRows('moon-details', [
        ['Altitude', cachedMoon ? `${Number(cachedMoon.altitude).toFixed(1)}°` : `${degrees(moonPos.altitude).toFixed(1)}°`],
        ['Azimuth', cachedMoon ? `${Number(cachedMoon.azimuth).toFixed(1)}° ${compassLabel(Number(cachedMoon.azimuth))}` : `${normalizeDegrees(degrees(moonPos.azimuth) + 180).toFixed(1)}° ${compassLabel(degrees(moonPos.azimuth) + 180)}`],
        ['Moonrise', cachedEvents.moon?.rise ? formatDateTime(new Date(cachedEvents.moon.rise)) : (moonTimes.alwaysUp ? 'Always up' : formatClock(moonTimes.rise))],
        ['Transit', cachedEvents.moon?.transit ? formatDateTime(new Date(cachedEvents.moon.transit)) : (moonTransit ? formatClock(moonTransit.date) : 'n/a')],
        ['Moonset', cachedEvents.moon?.set ? formatDateTime(new Date(cachedEvents.moon.set)) : (moonTimes.alwaysDown ? 'Always down' : formatClock(moonTimes.set))],
        ['Illumination', cachedMoonInfo ? `${Number(cachedMoonInfo.illumination).toFixed(1)}%` : `${(moonIll.fraction * 100).toFixed(1)}%`],
    ]);

    detailRows('phase-details', [
        ['Phase', cachedMoonInfo?.phaseName || moonPhaseName(moonIll.phase)],
        ['Angle', cachedMoonInfo ? `${Number(cachedMoonInfo.phaseAngle).toFixed(1)}°` : `${degrees(moonIll.angle).toFixed(1)}°`],
        ['Next new', formatDateTime(nextPhaseDate(now, moonIll.phase, 0))],
        ['First quarter', formatDateTime(nextPhaseDate(now, moonIll.phase, 0.25))],
        ['Next full', formatDateTime(nextPhaseDate(now, moonIll.phase, 0.5))],
        ['Last quarter', formatDateTime(nextPhaseDate(now, moonIll.phase, 0.75))],
    ]);

    detailRows('twilight-details', [
        ['Civil dawn', cachedTwilight.civil?.dawn ? formatDateTime(new Date(cachedTwilight.civil.dawn)) : formatClock(sunTimes.dawn)],
        ['Civil dusk', cachedTwilight.civil?.dusk ? formatDateTime(new Date(cachedTwilight.civil.dusk)) : formatClock(sunTimes.dusk)],
        ['Nautical dawn', cachedTwilight.nautical?.dawn ? formatDateTime(new Date(cachedTwilight.nautical.dawn)) : formatClock(sunTimes.nauticalDawn)],
        ['Nautical dusk', cachedTwilight.nautical?.dusk ? formatDateTime(new Date(cachedTwilight.nautical.dusk)) : formatClock(sunTimes.nauticalDusk)],
        ['Astronomical dawn', cachedTwilight.astronomical?.dawn ? formatDateTime(new Date(cachedTwilight.astronomical.dawn)) : formatClock(sunTimes.nightEnd)],
        ['Astronomical dusk', cachedTwilight.astronomical?.dusk ? formatDateTime(new Date(cachedTwilight.astronomical.dusk)) : formatClock(sunTimes.night)],
    ]);

    detailRows('time-details', [
        ['Civil time', formatDateTime(now)],
        ['Solar time', `${String(solarHours).padStart(2, '0')}:${String(solarMinutes).padStart(2, '0')}`],
        ['Sidereal time', siderealTime(now)],
        ['Equation of time', cached?.time?.equationOfTimeMinutes !== undefined ? `${Number(cached.time.equationOfTimeMinutes).toFixed(1)} min` : `${equationOfTimeMinutes(now).toFixed(1)} min`],
        ['Timezone', CELESTIAL.location.timezone || 'UTC'],
        ['Coordinates', `${lat.toFixed(5)}, ${lon.toFixed(5)}`],
    ]);

    renderPlanetDetails();
    renderAlmanacTable();
    renderCacheDetails();
}

function renderPlanetDetails() {
    const bodies = CELESTIAL_CACHE.daily?.payload?.bodies || {};
    const events = CELESTIAL_CACHE.daily?.payload?.events || {};
    const planetNames = ['mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune'];
    const rows = planetNames.filter((name) => bodies[name]).map((name) => ({
        title: name.charAt(0).toUpperCase() + name.slice(1),
        line1: `${Number(bodies[name].altitude).toFixed(1)}° alt · ${compassLabel(Number(bodies[name].azimuth))}`,
        line2: `Rise ${events[name]?.rise ? formatDateTime(new Date(events[name].rise)) : 'n/a'}`,
        line3: `Set ${events[name]?.set ? formatDateTime(new Date(events[name].set)) : 'n/a'}`,
    }));
    chipRows('planet-details', rows.length > 0 ? rows : [{
        title: 'No cached planets',
        line1: 'Run the celestial cache builder',
        line2: 'src/cli/build_celestial_cache.php',
        line3: '',
    }]);
}

function renderAlmanacTable() {
    const host = document.getElementById('celestial-almanac-table');
    if (!host) return;
    const bodies = CELESTIAL_CACHE.daily?.payload?.bodies || {};
    const events = CELESTIAL_CACHE.daily?.payload?.events || {};
    const { start, end } = localDayBounds(new Date());
    const names = CELESTIAL_BODY_ORDER.filter((name) => bodies[name] || name === 'sun' || name === 'moon');
    const rows = names.map((name) => {
        const body = bodies[name] || {};
        const typeLabel = name === 'sun' ? 'Star' : (name === 'moon' ? 'Moon' : 'Planet');
        const intervals = pathVisibilityIntervals(sampleBody(name, start, end, 10), start, end);
        const visibleMs = intervals.reduce((total, interval) => total + (interval.end - interval.start), 0);
        const distanceAu = Number(body.distanceAu);
        const distance = name === 'moon' && Number.isFinite(distanceAu)
            ? `${(distanceAu * 149597870.7).toLocaleString(undefined, { maximumFractionDigits: 0 })} km`
            : (Number.isFinite(distanceAu) ? `${distanceAu.toFixed(3)} au` : 'n/a');
        return `
            <tr>
                <td class="celestial-table-name">
                    <span class="celestial-body-orb" style="background:${escapeHtml(bodyColor(name))}; color:${escapeHtml(bodyColor(name))}"></span>
                    <span>${escapeHtml(bodyLabel(name))}<br><em>${escapeHtml(typeLabel)}</em></span>
                </td>
                <td>${escapeHtml(events[name]?.rise ? formatDateTime(new Date(events[name].rise)) : 'n/a')}</td>
                <td>${escapeHtml(events[name]?.transit ? formatDateTime(new Date(events[name].transit)) : 'n/a')}</td>
                <td>${escapeHtml(events[name]?.set ? formatDateTime(new Date(events[name].set)) : 'n/a')}</td>
                <td>${escapeHtml(formatDuration(visibleMs))}</td>
                <td>${Number.isFinite(Number(body.altitude)) ? `${Number(body.altitude).toFixed(1)}°` : 'n/a'}</td>
                <td>${Number.isFinite(Number(body.azimuth)) ? `${Number(body.azimuth).toFixed(1)}° ${escapeHtml(compassLabel(Number(body.azimuth)))}` : 'n/a'}</td>
                <td>n/a</td>
                <td>${escapeHtml(distance)}</td>
            </tr>
        `;
    });

    host.innerHTML = `
        <table class="celestial-almanac">
            <thead>
                <tr>
                    <th>Body</th>
                    <th>Rise</th>
                    <th>Transit</th>
                    <th>Set</th>
                    <th>Up for</th>
                    <th>Altitude</th>
                    <th>Azimuth</th>
                    <th>Mag</th>
                    <th>Distance</th>
                </tr>
            </thead>
            <tbody>${rows.join('')}</tbody>
        </table>
    `;
}

function renderCacheDetails() {
    const daily = CELESTIAL_CACHE.daily;
    const monthly = CELESTIAL_CACHE.monthly;
    const yearly = CELESTIAL_CACHE.yearly;
    detailRows('cache-details', [
        ['Daily', daily ? `${daily.periodKey} · cached` : 'not cached'],
        ['Monthly', monthly ? `${monthly.periodKey} · cached` : 'not cached'],
        ['Yearly', yearly ? `${yearly.periodKey} · cached` : 'not cached'],
        ['Source', daily?.payload?.source?.engine || 'SunCalc fallback'],
        ['Reference', daily?.payload?.source?.reference ? 'weewx-skyfield' : 'n/a'],
        ['Dataset note', 'No external datasets stored in git'],
    ]);
}

function renderCelestial() {
    if (!window.SunCalc) return;
    const now = new Date();
    drawSkyMap(now);
    drawVisibility(now);
    drawSunPath(now);
    drawDaylightYear(now);
    drawSolarSystem();
    drawLunationStrip(now);
    drawMoonSymbol(now);
    renderDetails(now);
}

async function loadCelestialCache() {
    await Promise.all(['daily', 'monthly', 'yearly'].map(async (dataset) => {
        try {
            const response = await fetch(`api/celestial.php?dataset=${dataset}&_=${Date.now()}`, { cache: 'no-store' });
            if (!response.ok) return;
            CELESTIAL_CACHE[dataset] = await response.json();
        } catch {
            // Keep the client-side SunCalc fallback if the cache is unavailable.
        }
    }));
}

async function loadCelestialCatalog() {
    try {
        const bucket = Math.floor(Date.now() / 900000) * 900;
        const response = await fetch(`api/celestial_catalog.php?time=${bucket}&_=${bucket}`, { cache: 'no-store' });
        if (!response.ok) return;
        CELESTIAL_CACHE.catalog = await response.json();
    } catch {
        // Catalog stars are optional; the dome still renders sun/moon/planets.
    }
}

initThemeSelector();
(async function initCelestial() {
    await loadCelestialCache();
    await loadCelestialCatalog();
    renderCelestial();
})();
window.addEventListener('resize', renderCelestial);
window.setInterval(renderCelestial, 60000);
window.setInterval(async () => {
    await loadCelestialCatalog();
    renderCelestial();
}, 900000);
</script>
</body>
</html>

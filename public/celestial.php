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

    <section class="celestial-layout">
        <article class="chart-card celestial-sky-card">
            <h2 class="chart-title">Sky Map</h2>
            <canvas id="celestial-sky" width="900" height="900"></canvas>
        </article>

        <aside class="celestial-side">
            <article class="card">
                <h2 class="chart-title">Sun</h2>
                <div id="sun-details" class="celestial-detail-grid"></div>
            </article>
            <article class="card">
                <h2 class="chart-title">Moon</h2>
                <div id="moon-details" class="celestial-detail-grid"></div>
            </article>
        </aside>
    </section>

    <section class="charts celestial-charts">
        <article class="chart-card">
            <h2 class="chart-title">Visibility Timeline</h2>
            <canvas id="celestial-visibility" width="1200" height="320"></canvas>
        </article>
        <article class="chart-card">
            <h2 class="chart-title">Moon Phase</h2>
            <canvas id="celestial-moon" width="520" height="320"></canvas>
            <div id="phase-details" class="celestial-detail-grid celestial-phase-grid"></div>
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

    const sunNow = SunCalc.getPosition(now, CELESTIAL.location.latitude, CELESTIAL.location.longitude);
    const moonNow = SunCalc.getMoonPosition(now, CELESTIAL.location.latitude, CELESTIAL.location.longitude);
    const sunPt = projectSky(normalizeDegrees(degrees(sunNow.azimuth) + 180), degrees(sunNow.altitude), cx, cy, radius);
    const moonPt = projectSky(normalizeDegrees(degrees(moonNow.azimuth) + 180), degrees(moonNow.altitude), cx, cy, radius);

    ctx.fillStyle = '#f5b02c';
    ctx.beginPath();
    ctx.arc(sunPt.x, sunPt.y, 8, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = '#fff4bf';
    ctx.lineWidth = 2;
    ctx.stroke();

    ctx.fillStyle = '#d8def0';
    ctx.beginPath();
    ctx.arc(moonPt.x, moonPt.y, 7, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = '#7987a7';
    ctx.lineWidth = 2;
    ctx.stroke();

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
    if (!canvas || !window.SunCalc) return;
    const { ctx, width, height } = setupCanvas(canvas);
    const text = cssVar('--text', '#102137');
    const muted = cssVar('--muted', '#5b6f86');
    const border = cssVar('--border', '#d7e1ec');
    const { start, end } = localDayBounds(now);
    const sun = sampleBody('sun', start, end, 10);
    const moon = sampleBody('moon', start, end, 10);
    const left = 48;
    const right = width - 16;
    const top = 18;
    const bottom = height - 34;

    ctx.clearRect(0, 0, width, height);
    ctx.strokeStyle = border;
    ctx.fillStyle = muted;
    ctx.font = '12px Source Sans 3, Segoe UI, sans-serif';
    for (const alt of [-18, -12, -6, 0, 30, 60]) {
        const y = bottom - ((alt + 20) / 90) * (bottom - top);
        ctx.beginPath();
        ctx.moveTo(left, y);
        ctx.lineTo(right, y);
        ctx.stroke();
        ctx.fillText(`${alt}°`, 8, y + 4);
    }

    function xFor(date) {
        return left + ((date.getTime() - start.getTime()) / (end.getTime() - start.getTime())) * (right - left);
    }
    function yFor(alt) {
        return bottom - ((Math.max(-20, Math.min(70, alt)) + 20) / 90) * (bottom - top);
    }
    function path(rows, color) {
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.beginPath();
        rows.forEach((row, idx) => {
            const x = xFor(row.date);
            const y = yFor(row.alt);
            if (idx === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();
    }

    path(sun, '#f5b02c');
    path(moon, '#aab8de');
    const nowX = xFor(now);
    ctx.strokeStyle = text;
    ctx.setLineDash([4, 4]);
    ctx.beginPath();
    ctx.moveTo(nowX, top);
    ctx.lineTo(nowX, bottom);
    ctx.stroke();
    ctx.setLineDash([]);

    ctx.fillStyle = muted;
    ctx.textAlign = 'center';
    for (let h = 0; h <= 24; h += 4) {
        const x = left + (h / 24) * (right - left);
        ctx.fillText(`${String(h).padStart(2, '0')}:00`, x, height - 10);
    }
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

    document.getElementById('celestial-location').textContent = `${lat.toFixed(4)}, ${lon.toFixed(4)}`;
    document.getElementById('celestial-now').textContent = formatClock(now);

    detailRows('sun-details', [
        ['Altitude', `${degrees(sunPos.altitude).toFixed(1)}°`],
        ['Azimuth', `${normalizeDegrees(degrees(sunPos.azimuth) + 180).toFixed(1)}° ${compassLabel(degrees(sunPos.azimuth) + 180)}`],
        ['Sunrise', formatClock(sunTimes.sunrise)],
        ['Solar noon', formatClock(sunTimes.solarNoon)],
        ['Sunset', formatClock(sunTimes.sunset)],
        ['Day length', formatDuration(dayLength)],
    ]);

    detailRows('moon-details', [
        ['Altitude', `${degrees(moonPos.altitude).toFixed(1)}°`],
        ['Azimuth', `${normalizeDegrees(degrees(moonPos.azimuth) + 180).toFixed(1)}° ${compassLabel(degrees(moonPos.azimuth) + 180)}`],
        ['Moonrise', moonTimes.alwaysUp ? 'Always up' : formatClock(moonTimes.rise)],
        ['Transit', moonTransit ? formatClock(moonTransit.date) : 'n/a'],
        ['Moonset', moonTimes.alwaysDown ? 'Always down' : formatClock(moonTimes.set)],
        ['Illumination', `${(moonIll.fraction * 100).toFixed(1)}%`],
    ]);

    detailRows('phase-details', [
        ['Phase', moonPhaseName(moonIll.phase)],
        ['Angle', `${degrees(moonIll.angle).toFixed(1)}°`],
        ['Next new', formatDateTime(nextPhaseDate(now, moonIll.phase, 0))],
        ['First quarter', formatDateTime(nextPhaseDate(now, moonIll.phase, 0.25))],
        ['Next full', formatDateTime(nextPhaseDate(now, moonIll.phase, 0.5))],
        ['Last quarter', formatDateTime(nextPhaseDate(now, moonIll.phase, 0.75))],
    ]);

    detailRows('twilight-details', [
        ['Dawn', formatClock(sunTimes.dawn)],
        ['Dusk', formatClock(sunTimes.dusk)],
        ['Nautical dawn', formatClock(sunTimes.nauticalDawn)],
        ['Nautical dusk', formatClock(sunTimes.nauticalDusk)],
        ['Night end', formatClock(sunTimes.nightEnd)],
        ['Night', formatClock(sunTimes.night)],
    ]);

    detailRows('time-details', [
        ['Civil time', formatDateTime(now)],
        ['Solar time', `${String(solarHours).padStart(2, '0')}:${String(solarMinutes).padStart(2, '0')}`],
        ['Sidereal time', siderealTime(now)],
        ['Equation of time', `${equationOfTimeMinutes(now).toFixed(1)} min`],
        ['Timezone', CELESTIAL.location.timezone || 'UTC'],
        ['Coordinates', `${lat.toFixed(5)}, ${lon.toFixed(5)}`],
    ]);
}

function renderCelestial() {
    if (!window.SunCalc) return;
    const now = new Date();
    drawSkyMap(now);
    drawVisibility(now);
    drawMoonSymbol(now);
    renderDetails(now);
}

initThemeSelector();
renderCelestial();
window.addEventListener('resize', renderCelestial);
window.setInterval(renderCelestial, 60000);
</script>
</body>
</html>

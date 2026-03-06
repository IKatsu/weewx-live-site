<?php

declare(strict_types=1);

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
$cssCustom = (string) ($cssConfig['custom'] ?? '');
$plotlyConfig = $config['ui']['plotly'] ?? [];
$plotlyJs = (string) ($plotlyConfig['js'] ?? '');
$plotlyWindRose = (bool) ($plotlyConfig['wind_rose'] ?? false);
$graphToggles = $config['ui']['graphs'] ?? [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PWS Live Dashboard</title>
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
<div class="container">
    <header class="header">
        <h1 class="title">PWS Live Dashboard</h1>
        <div class="status-row">
            <label class="status-pill" for="theme-select">
                <span>Theme:</span>
                <select id="theme-select"></select>
            </label>
            <div class="status-pill"><span>Last update:</span> <strong id="db-updated">-</strong></div>
            <div class="status-pill"><span>Range:</span> <strong id="range-label">Today</strong></div>
            <div class="status-pill"><span class="dot" id="mqtt-dot"></span><span id="mqtt-status">MQTT: idle</span></div>
        </div>
    </header>

    <section class="cards" id="cards"></section>

    <nav class="range-toolbar" id="range-toolbar">
        <button class="range-btn active" data-range="today">Today</button>
        <button class="range-btn" data-range="yesterday">Yesterday</button>
        <button class="range-btn" data-range="week">Last Week</button>
        <button class="range-btn" data-range="month">Last Month</button>
        <button class="range-btn" data-range="year">Last Year</button>
    </nav>

    <h2 class="section-title">Weather Graphs</h2>
    <section class="charts">
        <article class="chart-card" data-graph="temp_outside">
            <h3 class="chart-title">Outside Temperature / Dewpoint / Apparent</h3>
            <div class="chart-wrap"><canvas id="chart-temp"></canvas></div>
        </article>
        <article class="chart-card" data-graph="temp_inside">
            <h3 class="chart-title">Inside Temperature / Dewpoint</h3>
            <div class="chart-wrap"><canvas id="chart-temp-in"></canvas></div>
        </article>
        <article class="chart-card" data-graph="humidity_outside">
            <h3 class="chart-title">Outside Humidity</h3>
            <div class="chart-wrap"><canvas id="chart-humidity"></canvas></div>
        </article>
        <article class="chart-card" data-graph="humidity_inside">
            <h3 class="chart-title">Inside Humidity</h3>
            <div class="chart-wrap"><canvas id="chart-humidity-in"></canvas></div>
        </article>
        <article class="chart-card" data-graph="wind_speed">
            <h3 class="chart-title">Wind Speed / Gust</h3>
            <div class="chart-wrap"><canvas id="chart-wind"></canvas></div>
        </article>
        <article class="chart-card" data-graph="wind_direction">
            <h3 class="chart-title">Wind Direction (Points)</h3>
            <div class="chart-wrap"><canvas id="chart-wind-dir"></canvas></div>
        </article>
        <article class="chart-card" data-graph="pressure">
            <h3 class="chart-title">Pressure</h3>
            <div class="chart-wrap"><canvas id="chart-pressure"></canvas></div>
        </article>
        <article class="chart-card" data-graph="rain_rate_hourly">
            <h3 class="chart-title">Rain Rate + Hourly Rain Sum</h3>
            <div class="chart-wrap"><canvas id="chart-rain"></canvas></div>
        </article>
        <article class="chart-card" data-graph="rain_total_duration">
            <h3 class="chart-title">Rain Total / Rain Duration</h3>
            <div class="chart-wrap"><canvas id="chart-rain-total"></canvas></div>
        </article>
        <article class="chart-card" data-graph="feels_like">
            <h3 class="chart-title">Feels Like Indicators</h3>
            <div class="chart-wrap"><canvas id="chart-feels"></canvas></div>
        </article>
        <article class="chart-card" data-graph="solar">
            <h3 class="chart-title">Solar Radiation / UV / Solar Altitude</h3>
            <div class="chart-wrap"><canvas id="chart-solar"></canvas></div>
        </article>
        <article class="chart-card" data-graph="cloudbase">
            <h3 class="chart-title">Cloudbase</h3>
            <div class="chart-wrap"><canvas id="chart-cloudbase"></canvas></div>
        </article>
        <article class="chart-card" data-graph="et">
            <h3 class="chart-title">Evapotranspiration (ET)</h3>
            <div class="chart-wrap"><canvas id="chart-et"></canvas></div>
        </article>
        <article class="chart-card" data-graph="sunshine">
            <h3 class="chart-title">Sunshine Duration</h3>
            <div class="chart-wrap"><canvas id="chart-sunshine"></canvas></div>
        </article>
        <article class="chart-card" data-graph="windrun">
            <h3 class="chart-title">Wind Run</h3>
            <div class="chart-wrap"><canvas id="chart-windrun"></canvas></div>
        </article>
        <article class="chart-card" data-graph="pm25">
            <h3 class="chart-title">PM2.5</h3>
            <div class="chart-wrap"><canvas id="chart-pm25"></canvas></div>
        </article>
        <article class="chart-card" data-graph="lightning">
            <h3 class="chart-title">Lightning Strike Count</h3>
            <div class="chart-wrap"><canvas id="chart-lightning"></canvas></div>
        </article>
        <article class="chart-card" data-graph="wind_rose">
            <h3 class="chart-title">Wind Rose (Direction x Speed Class)</h3>
            <div class="chart-wrap wind-rose">
                <div id="chart-wind-rose-plotly" style="width:100%;height:100%;display:none;"></div>
                <canvas id="chart-wind-rose"></canvas>
            </div>
        </article>
    </section>

    <h2 class="section-title">Battery Graphs</h2>
    <section class="charts">
        <article class="chart-card" data-graph="battery_wind">
            <h3 class="chart-title">Wind Battery</h3>
            <div class="chart-wrap"><canvas id="chart-batt-wind"></canvas></div>
        </article>
        <article class="chart-card" data-graph="battery_rain">
            <h3 class="chart-title">Rain Battery</h3>
            <div class="chart-wrap"><canvas id="chart-batt-rain"></canvas></div>
        </article>
        <article class="chart-card" data-graph="battery_lightning">
            <h3 class="chart-title">Lightning Battery</h3>
            <div class="chart-wrap"><canvas id="chart-batt-lightning"></canvas></div>
        </article>
        <article class="chart-card" data-graph="battery_pm25">
            <h3 class="chart-title">PM2.5 Battery</h3>
            <div class="chart-wrap"><canvas id="chart-batt-pm25"></canvas></div>
        </article>
        <article class="chart-card" data-graph="battery_indoor">
            <h3 class="chart-title">Indoor Temp Battery</h3>
            <div class="chart-wrap"><canvas id="chart-batt-indoor"></canvas></div>
        </article>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/date-fns@3.6.0/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mqtt@5.10.4/dist/mqtt.min.js"></script>
<?php if ($plotlyJs !== ''): ?>
<script src="<?= htmlspecialchars($plotlyJs, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
<script>
const APP = {
    mqtt: {
        url: <?= json_encode($config['mqtt']['url']) ?>,
        username: <?= json_encode($config['mqtt']['username']) ?>,
        password: <?= json_encode($config['mqtt']['password']) ?>,
        topic: <?= json_encode($config['mqtt']['topic']) ?>,
    },
    historyRange: 'today',
    usePlotlyWindRose: <?= $plotlyWindRose ? 'true' : 'false' ?>,
    defaultTheme: <?= json_encode($defaultTheme) ?>,
    themes: <?= json_encode(array_keys($cssThemes)) ?>,
    graphToggles: <?= json_encode($graphToggles) ?>,
};

const historyRanges = {
    today: { label: 'Today', hours: 24, endOffsetHours: 0, bucketMinutes: 5 },
    yesterday: { label: 'Yesterday', hours: 24, endOffsetHours: 24, bucketMinutes: 5 },
    week: { label: 'Last Week', hours: 24 * 7, endOffsetHours: 0, bucketMinutes: 15 },
    month: { label: 'Last Month', hours: 24 * 30, endOffsetHours: 0, bucketMinutes: 60 },
    year: { label: 'Last Year', hours: 24 * 365, endOffsetHours: 0, bucketMinutes: 6 * 60 },
};

const metricOrder = [
    'outTemp', 'inTemp', 'dewpoint', 'inDewpoint', 'appTemp', 'heatindex', 'windchill', 'humidex',
    'outHumidity', 'inHumidity', 'barometer', 'pressure', 'windSpeed', 'windGust', 'windDir', 'windrun',
    'rainRate', 'rain', 'rainDur', 'UV', 'radiation', 'cloudbase', 'ET', 'solarAltitude', 'sunshineDur',
    'pm2_5', 'lightning_strike_count', 'windBatteryStatus', 'rainBatteryStatus', 'lightning_Batt',
    'pm25_Batt1', 'inTempBatteryStatus'
];

const state = {
    latest: null,
    charts: {},
    windRosePlotly: false,
};

const graphFieldRequirements = {
    temp_outside: ['outTemp', 'dewpoint', 'appTemp'],
    temp_inside: ['inTemp', 'inDewpoint'],
    humidity_outside: ['outHumidity'],
    humidity_inside: ['inHumidity'],
    wind_speed: ['windSpeed', 'windGust'],
    wind_direction: ['windDir'],
    pressure: ['barometer', 'pressure'],
    rain_rate_hourly: ['rainRate', 'rainHourly'],
    rain_total_duration: ['rain', 'rainDur'],
    feels_like: ['heatindex', 'windchill', 'humidex'],
    solar: ['radiation', 'UV', 'solarAltitude'],
    cloudbase: ['cloudbase'],
    et: ['ET'],
    sunshine: ['sunshineDur'],
    windrun: ['windrun'],
    pm25: ['pm2_5'],
    lightning: ['lightning_strike_count'],
    wind_rose: ['windDir', 'windSpeed'],
    battery_wind: ['windBatteryStatus'],
    battery_rain: ['rainBatteryStatus'],
    battery_lightning: ['lightning_Batt'],
    battery_pm25: ['pm25_Batt1'],
    battery_indoor: ['inTempBatteryStatus'],
};

function graphEnabled(key) {
    return APP.graphToggles[key] !== false;
}

function requiredHistoryFields() {
    const fields = new Set();
    for (const [key, req] of Object.entries(graphFieldRequirements)) {
        if (!graphEnabled(key)) continue;
        for (const field of req) fields.add(field);
    }
    return Array.from(fields);
}

function formatValue(value, unit) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return 'n/a';
    const n = Number(value);
    const fixed = Math.abs(n) >= 100 ? 0 : (Math.abs(n) >= 10 ? 1 : 2);
    return `${n.toFixed(fixed)} ${unit || ''}`.trim();
}

function formatTimestamp(epochSeconds) {
    if (!epochSeconds) return '-';
    return new Date(Number(epochSeconds) * 1000).toLocaleString();
}

function setMqttStatus(text, mode) {
    const status = document.getElementById('mqtt-status');
    const dot = document.getElementById('mqtt-dot');
    status.textContent = text;
    dot.classList.remove('connected', 'error');
    if (mode) dot.classList.add(mode);
}

function applyGraphVisibility() {
    const cards = document.querySelectorAll('[data-graph]');
    for (const node of cards) {
        const key = node.getAttribute('data-graph') || '';
        node.style.display = graphEnabled(key) ? '' : 'none';
    }
}

function applyTheme(themeName) {
    const allowed = Array.isArray(APP.themes) ? APP.themes : [];
    const selected = allowed.includes(themeName) ? themeName : APP.defaultTheme;
    document.documentElement.setAttribute('data-theme', selected);
    try {
        localStorage.setItem('pws_theme', selected);
    } catch (e) {
        // ignore
    }
}

function initThemeSelector() {
    const select = document.getElementById('theme-select');
    if (!select) return;
    const themes = Array.isArray(APP.themes) ? APP.themes : ['bright', 'dark'];
    select.innerHTML = '';
    for (const theme of themes) {
        const option = document.createElement('option');
        option.value = theme;
        option.textContent = theme.charAt(0).toUpperCase() + theme.slice(1);
        select.appendChild(option);
    }

    let initial = APP.defaultTheme;
    try {
        const saved = localStorage.getItem('pws_theme');
        if (saved && themes.includes(saved)) initial = saved;
    } catch (e) {
        // ignore
    }
    select.value = initial;
    applyTheme(initial);

    select.addEventListener('change', () => {
        applyTheme(select.value);
    });
}

function renderCards() {
    if (!state.latest) return;
    const cards = document.getElementById('cards');
    cards.innerHTML = '';
    const metrics = state.latest.metrics || {};

    const rendered = new Set();
    for (const key of metricOrder) {
        const metric = metrics[key];
        if (!metric) continue;
        rendered.add(key);
        const card = document.createElement('article');
        card.className = 'card';
        card.innerHTML = `<div class="label">${metric.label || key}</div><div class="value" id="metric-${key}">${formatValue(metric.value, metric.unit)}</div>`;
        cards.appendChild(card);
    }

    for (const [key, metric] of Object.entries(metrics)) {
        if (rendered.has(key)) continue;
        const card = document.createElement('article');
        card.className = 'card';
        card.innerHTML = `<div class="label">${metric.label || key}</div><div class="value" id="metric-${key}">${formatValue(metric.value, metric.unit)}</div>`;
        cards.appendChild(card);
    }
}

function updateMetricValue(key, value, unit) {
    const node = document.getElementById(`metric-${key}`);
    if (node) node.textContent = formatValue(value, unit);
}

function choose(payload, keys) {
    for (const key of keys) {
        if (Object.prototype.hasOwnProperty.call(payload, key) && payload[key] !== null && payload[key] !== '') {
            return Number(payload[key]);
        }
    }
    return null;
}

function mergeMqttUpdate(payload) {
    if (!state.latest || !state.latest.metrics) return;

    const map = {
        outTemp: ['outTemp', 'outTemp_C', 'outTemp_F'],
        inTemp: ['inTemp', 'inTemp_C', 'inTemp_F'],
        outHumidity: ['outHumidity'],
        inHumidity: ['inHumidity'],
        windSpeed: ['windSpeed', 'windSpeed_kph', 'windSpeed_mph', 'windSpeed_mps'],
        windGust: ['windGust', 'windGust_kph', 'windGust_mph', 'windGust_mps'],
        windDir: ['windDir'],
        barometer: ['barometer', 'barometer_mbar', 'barometer_inHg'],
        pressure: ['pressure', 'pressure_mbar', 'pressure_inHg'],
        rainRate: ['rainRate', 'rainRate_mm_per_hr', 'rainRate_in_per_hr'],
        rain: ['rain', 'rain_mm', 'rain_in'],
        UV: ['UV'],
        radiation: ['radiation'],
        dewpoint: ['dewpoint', 'dewpoint_C', 'dewpoint_F'],
        heatindex: ['heatindex', 'heatindex_C', 'heatindex_F'],
        windchill: ['windchill', 'windchill_C', 'windchill_F'],
        appTemp: ['appTemp', 'appTemp_C', 'appTemp_F'],
        pm2_5: ['pm2_5'],
        lightning_strike_count: ['lightning_strike_count'],
        windBatteryStatus: ['windBatteryStatus'],
        rainBatteryStatus: ['rainBatteryStatus'],
        lightning_Batt: ['lightning_Batt'],
        pm25_Batt1: ['pm25_Batt1'],
    };

    for (const [metricKey, sourceKeys] of Object.entries(map)) {
        if (!state.latest.metrics[metricKey]) continue;
        const value = choose(payload, sourceKeys);
        if (value === null) continue;
        state.latest.metrics[metricKey].value = value;
        updateMetricValue(metricKey, value, state.latest.metrics[metricKey].unit);
    }

    const ts = choose(payload, ['dateTime']);
    if (ts !== null) {
        document.getElementById('db-updated').textContent = formatTimestamp(ts);
    }
}

function lineOptions(xMin, xMax) {
    return {
        type: 'line',
        options: {
            responsive: true,
            maintainAspectRatio: false,
            normalized: true,
            interaction: { mode: 'nearest', intersect: false },
            scales: {
                x: {
                    type: 'time',
                    time: { tooltipFormat: 'PPpp' },
                    ticks: { maxRotation: 0 },
                    min: xMin,
                    max: xMax,
                },
            },
            elements: {
                point: { radius: 0 },
                line: { tension: 0.25, borderWidth: 2 },
            },
            plugins: { legend: { position: 'bottom' } },
        },
    };
}

function windSpeedClasses() {
    return [
        { min: 0, max: 2, label: '0-2 m/s (Bft 0-2)' },
        { min: 2, max: 5, label: '2-5 m/s (Bft 2-3)' },
        { min: 5, max: 10, label: '5-10 m/s (Bft 4-5)' },
        { min: 10, max: 9999, label: '10+ m/s (Bft 6+)' },
    ];
}

function toMetersPerSecond(speed, windUnit) {
    if (Number.isNaN(speed)) return speed;
    if (windUnit === 'km/h') return speed / 3.6;
    if (windUnit === 'mph') return speed * 0.44704;
    return speed;
}

function buildWindRose(dirSeries, speedSeries, windUnit) {
    const sectors = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
    const classes = windSpeedClasses();
    const counts = classes.map(() => new Array(sectors.length).fill(0));

    const speedByTs = new Map();
    for (const p of speedSeries || []) {
        speedByTs.set(Number(p.x), Number(p.y));
    }

    let sampleCount = 0;
    for (const p of dirSeries || []) {
        const ts = Number(p.x);
        const deg = Number(p.y);
        const speedRaw = speedByTs.get(ts);
        if (Number.isNaN(deg) || speedRaw === undefined || Number.isNaN(speedRaw) || speedRaw < 0) continue;
        const speed = toMetersPerSecond(speedRaw, windUnit || 'm/s');

        const normalized = ((deg % 360) + 360) % 360;
        const sectorIndex = Math.floor((normalized + 11.25) / 22.5) % sectors.length;

        let classIndex = classes.length - 1;
        for (let i = 0; i < classes.length; i++) {
            if (speed >= classes[i].min && speed < classes[i].max) {
                classIndex = i;
                break;
            }
        }

        counts[classIndex][sectorIndex] += 1;
        sampleCount += 1;
    }

    const datasets = [];
    const colors = ['#b7d9f8', '#5da8e8', '#2b77c2', '#0b3f6e'];

    for (let i = 0; i < classes.length; i++) {
        const r = counts[i].map((v) => sampleCount > 0 ? (v / sampleCount) * 100 : 0);
        datasets.push({
            label: classes[i].label,
            data: r,
            backgroundColor: colors[i % colors.length],
            borderColor: '#ffffff',
            borderWidth: 1,
        });
    }

    return { sectors, datasets, sampleCount };
}

function destroyCharts() {
    for (const chart of Object.values(state.charts)) {
        if (chart) chart.destroy();
    }
    state.charts = {};
    if (state.windRosePlotly && window.Plotly) {
        Plotly.purge('chart-wind-rose-plotly');
    }
    state.windRosePlotly = false;
}

function buildCharts(history) {
    const units = history.units || {};
    const s = history.series || {};
    const xMin = Number(history.startTs || 0) * 1000;
    const xMax = Number(history.endTs || 0) * 1000;

    destroyCharts();

    const temp = lineOptions(xMin, xMax);
    temp.data = {
        datasets: [
            { label: `Outside Temp (${units.temperature || ''})`, data: s.outTemp || [], borderColor: '#cf3f2f', backgroundColor: '#cf3f2f' },
            { label: `Dewpoint (${units.temperature || ''})`, data: s.dewpoint || [], borderColor: '#007d73', backgroundColor: '#007d73' },
            { label: `Apparent Temp (${units.temperature || ''})`, data: s.appTemp || [], borderColor: '#1177cc', backgroundColor: '#1177cc' },
        ],
    };
    state.charts.temp = new Chart(document.getElementById('chart-temp'), temp);

    const tempIn = lineOptions(xMin, xMax);
    tempIn.data = {
        datasets: [
            { label: `Inside Temp (${units.temperature || ''})`, data: s.inTemp || [], borderColor: '#6f4a1f', backgroundColor: '#6f4a1f' },
            { label: `Inside Dewpoint (${units.temperature || ''})`, data: s.inDewpoint || [], borderColor: '#125a7a', backgroundColor: '#125a7a' },
        ],
    };
    state.charts.tempIn = new Chart(document.getElementById('chart-temp-in'), tempIn);

    const humidity = lineOptions(xMin, xMax);
    humidity.data = {
        datasets: [
            { label: `Outside Humidity (${units.humidity || '%'})`, data: s.outHumidity || [], borderColor: '#1177cc', backgroundColor: '#1177cc' },
        ],
    };
    humidity.options.scales.y = { suggestedMin: 0, suggestedMax: 100 };
    state.charts.humidity = new Chart(document.getElementById('chart-humidity'), humidity);

    const humidityIn = lineOptions(xMin, xMax);
    humidityIn.data = {
        datasets: [
            { label: `Inside Humidity (${units.humidity || '%'})`, data: s.inHumidity || [], borderColor: '#338799', backgroundColor: '#338799' },
        ],
    };
    humidityIn.options.scales.y = { suggestedMin: 0, suggestedMax: 100 };
    state.charts.humidityIn = new Chart(document.getElementById('chart-humidity-in'), humidityIn);

    const wind = lineOptions(xMin, xMax);
    wind.data = {
        datasets: [
            { label: `Wind Speed (${units.wind || ''})`, data: s.windSpeed || [], borderColor: '#7c5cff', backgroundColor: '#7c5cff' },
            { label: `Wind Gust (${units.wind || ''})`, data: s.windGust || [], borderColor: '#9e7f09', backgroundColor: '#9e7f09' },
        ],
    };
    state.charts.wind = new Chart(document.getElementById('chart-wind'), wind);

    const windDir = lineOptions(xMin, xMax);
    windDir.data = {
        datasets: [{
            label: 'Wind Direction (deg)',
            data: s.windDir || [],
            borderColor: '#0f6ecf',
            backgroundColor: '#0f6ecf',
            showLine: false,
            pointRadius: 2,
        }],
    };
    windDir.options.scales.y = { min: 0, max: 360 };
    state.charts.windDir = new Chart(document.getElementById('chart-wind-dir'), windDir);

    const pressure = lineOptions(xMin, xMax);
    pressure.data = {
        datasets: [
            { label: `Barometer (${units.pressure || ''})`, data: s.barometer || [], borderColor: '#2f7f40', backgroundColor: '#2f7f40' },
            { label: `Pressure (${units.pressure || ''})`, data: s.pressure || [], borderColor: '#309088', backgroundColor: '#309088' },
        ],
    };
    state.charts.pressure = new Chart(document.getElementById('chart-pressure'), pressure);

    const rain = lineOptions(xMin, xMax);
    rain.data = {
        datasets: [
            { label: `Rain Rate (${units.rain_rate || ''})`, data: s.rainRate || [], borderColor: '#2f3fcf', backgroundColor: '#2f3fcf', yAxisID: 'y' },
            { label: `Rain per Hour (${units.rain || ''})`, data: s.rainHourly || [], borderColor: '#1f6aa5', backgroundColor: 'rgba(31,106,165,0.28)', type: 'bar', yAxisID: 'y1' },
        ],
    };
    rain.options.scales.y = { position: 'left' };
    rain.options.scales.y1 = { position: 'right', grid: { drawOnChartArea: false } };
    state.charts.rain = new Chart(document.getElementById('chart-rain'), rain);

    const rainTotal = lineOptions(xMin, xMax);
    rainTotal.data = {
        datasets: [
            { label: `Rain Total (${units.rain || ''})`, data: s.rain || [], borderColor: '#0f5fa8', backgroundColor: '#0f5fa8', yAxisID: 'y' },
            { label: 'Rain Duration (s)', data: s.rainDur || [], borderColor: '#4d7db8', backgroundColor: 'rgba(77,125,184,0.25)', type: 'bar', yAxisID: 'y1' },
        ],
    };
    rainTotal.options.scales.y = { position: 'left' };
    rainTotal.options.scales.y1 = { position: 'right', grid: { drawOnChartArea: false } };
    state.charts.rainTotal = new Chart(document.getElementById('chart-rain-total'), rainTotal);

    const feels = lineOptions(xMin, xMax);
    feels.data = {
        datasets: [
            { label: `Heat Index (${units.temperature || ''})`, data: s.heatindex || [], borderColor: '#cf5a30', backgroundColor: '#cf5a30' },
            { label: `Wind Chill (${units.temperature || ''})`, data: s.windchill || [], borderColor: '#3c8ac5', backgroundColor: '#3c8ac5' },
            { label: `Humidex (${units.temperature || ''})`, data: s.humidex || [], borderColor: '#967000', backgroundColor: '#967000' },
        ],
    };
    state.charts.feels = new Chart(document.getElementById('chart-feels'), feels);

    const solar = lineOptions(xMin, xMax);
    solar.data = {
        datasets: [
            { label: `Solar Radiation (${units.radiation || 'W/m²'})`, data: s.radiation || [], type: 'bar', borderColor: '#f2c500', backgroundColor: 'rgba(255,225,0,0.78)', yAxisID: 'y' },
            { label: `UV (${units.uv || 'index'})`, data: s.UV || [], borderColor: '#bf7d1c', backgroundColor: '#bf7d1c', yAxisID: 'y1' },
            { label: 'Solar Altitude (°)', data: s.solarAltitude || [], borderColor: '#e58e00', backgroundColor: '#e58e00', yAxisID: 'y2' },
        ],
    };
    solar.options.scales.y = { position: 'left' };
    solar.options.scales.y1 = { position: 'right', grid: { drawOnChartArea: false } };
    solar.options.scales.y2 = { position: 'right', grid: { drawOnChartArea: false }, offset: true };
    state.charts.solar = new Chart(document.getElementById('chart-solar'), solar);

    const cloudbase = lineOptions(xMin, xMax);
    cloudbase.data = {
        datasets: [
            { label: 'Cloudbase (m)', data: s.cloudbase || [], borderColor: '#6a6fb0', backgroundColor: '#6a6fb0' },
        ],
    };
    state.charts.cloudbase = new Chart(document.getElementById('chart-cloudbase'), cloudbase);

    const et = lineOptions(xMin, xMax);
    et.data = {
        datasets: [
            { label: `ET (${units.rain || ''})`, data: s.ET || [], borderColor: '#3e9c5f', backgroundColor: '#3e9c5f' },
        ],
    };
    state.charts.et = new Chart(document.getElementById('chart-et'), et);

    const sunshine = lineOptions(xMin, xMax);
    sunshine.data = {
        datasets: [
            { label: 'Sunshine Duration (s)', data: s.sunshineDur || [], borderColor: '#c18a00', backgroundColor: '#c18a00' },
        ],
    };
    state.charts.sunshine = new Chart(document.getElementById('chart-sunshine'), sunshine);

    const windrun = lineOptions(xMin, xMax);
    windrun.data = {
        datasets: [
            { label: `Wind Run (${units.wind || ''})`, data: s.windrun || [], borderColor: '#6e5de7', backgroundColor: '#6e5de7' },
        ],
    };
    state.charts.windrun = new Chart(document.getElementById('chart-windrun'), windrun);

    const pm25 = lineOptions(xMin, xMax);
    pm25.data = {
        datasets: [
            { label: 'PM2.5 (µg/m³)', data: s.pm2_5 || [], borderColor: '#8a3f7c', backgroundColor: '#8a3f7c' },
        ],
    };
    state.charts.pm25 = new Chart(document.getElementById('chart-pm25'), pm25);

    const lightning = lineOptions(xMin, xMax);
    lightning.data = {
        datasets: [
            { label: 'Lightning Strikes (count)', data: s.lightning_strike_count || [], borderColor: '#7a4b1f', backgroundColor: 'rgba(122,75,31,0.3)', type: 'bar' },
        ],
    };
    state.charts.lightning = new Chart(document.getElementById('chart-lightning'), lightning);

    const rose = buildWindRose(s.windDir || [], s.windSpeed || [], units.wind || 'km/h');
    const roseCanvas = document.getElementById('chart-wind-rose');
    const rosePlotly = document.getElementById('chart-wind-rose-plotly');
    if (APP.usePlotlyWindRose && window.Plotly) {
        roseCanvas.style.display = 'none';
        rosePlotly.style.display = 'block';

        const traces = rose.datasets.map((d) => ({
            type: 'barpolar',
            name: d.label,
            theta: rose.sectors,
            r: d.data,
            marker: { color: d.backgroundColor, line: { color: '#ffffff', width: 0.6 } },
            hovertemplate: '%{theta}<br>' + d.label + ': %{r:.1f}%<extra></extra>',
        }));

        Plotly.newPlot('chart-wind-rose-plotly', traces, {
            margin: { l: 24, r: 24, t: 8, b: 8 },
            barmode: 'stack',
            showlegend: true,
            legend: { orientation: 'h' },
            polar: {
                angularaxis: { direction: 'clockwise', rotation: 90 },
                radialaxis: { ticksuffix: '%', angle: 90 },
            },
        }, { responsive: true, displayModeBar: false });
        state.windRosePlotly = true;
    } else {
        rosePlotly.style.display = 'none';
        roseCanvas.style.display = 'block';
        state.charts.windRose = new Chart(roseCanvas, {
            type: 'bar',
            data: {
                labels: rose.sectors,
                datasets: rose.datasets,
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: { callback(value) { return `${value}%`; } },
                        title: { display: true, text: 'Frequency' },
                    },
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                const val = Number(context.raw || 0).toFixed(1);
                                return `${context.dataset.label}: ${val}%`;
                            },
                        },
                    },
                    title: rose.sampleCount > 0 ? undefined : {
                        display: true,
                        text: 'No wind rose samples in selected range',
                    },
                },
            },
        });
    }

    function batteryChart(canvasId, label, data, color) {
        const cfg = lineOptions(xMin, xMax);
        cfg.data = {
            datasets: [{ label, data: data || [], borderColor: color, backgroundColor: color }],
        };
        state.charts[canvasId] = new Chart(document.getElementById(canvasId), cfg);
    }

    batteryChart('chart-batt-wind', 'Wind Battery (V)', s.windBatteryStatus, '#8c3f2b');
    batteryChart('chart-batt-rain', 'Rain Battery (V)', s.rainBatteryStatus, '#2f7a5f');
    batteryChart('chart-batt-lightning', 'Lightning Battery (V)', s.lightning_Batt, '#9f7a19');
    batteryChart('chart-batt-pm25', 'PM2.5 Battery (V)', s.pm25_Batt1, '#6b4ea5');
    batteryChart('chart-batt-indoor', 'Indoor Temp Battery (V)', s.inTempBatteryStatus, '#40658f');
}

async function loadLatest() {
    const response = await fetch('api/latest.php', { cache: 'no-store' });
    if (!response.ok) throw new Error(`latest ${response.status}`);
    state.latest = await response.json();
    document.getElementById('db-updated').textContent = formatTimestamp(state.latest.timestamp);
    renderCards();
}

async function loadHistory(rangeKey = APP.historyRange) {
    const selected = historyRanges[rangeKey] || historyRanges.today;
    const fields = requiredHistoryFields();
    const query = new URLSearchParams({
        hours: String(selected.hours),
        endOffsetHours: String(selected.endOffsetHours),
        bucketMinutes: String(selected.bucketMinutes),
        fields: fields.join(','),
    });

    const response = await fetch(`api/history.php?${query.toString()}`, { cache: 'no-store' });
    if (!response.ok) throw new Error(`history ${response.status}`);
    const history = await response.json();
    buildCharts(history);
    document.getElementById('range-label').textContent = selected.label;
}

function setActiveRange(rangeKey) {
    APP.historyRange = rangeKey;
    for (const node of document.querySelectorAll('.range-btn')) {
        node.classList.toggle('active', node.dataset.range === rangeKey);
    }
}

function initRangeButtons() {
    for (const node of document.querySelectorAll('.range-btn')) {
        node.addEventListener('click', async () => {
            const rangeKey = node.dataset.range || 'today';
            setActiveRange(rangeKey);
            try {
                await loadHistory(rangeKey);
            } catch (err) {
                console.error(err);
            }
        });
    }
}

function connectMqtt() {
    setMqttStatus('MQTT: connecting', null);

    const client = mqtt.connect(APP.mqtt.url, {
        username: APP.mqtt.username,
        password: APP.mqtt.password,
        clean: true,
        reconnectPeriod: 5000,
        connectTimeout: 8000,
    });

    client.on('connect', () => {
        setMqttStatus('MQTT: connected', 'connected');
        client.subscribe(APP.mqtt.topic, (err) => {
            if (err) setMqttStatus('MQTT: subscribe error', 'error');
        });
    });

    client.on('reconnect', () => setMqttStatus('MQTT: reconnecting', null));
    client.on('error', () => setMqttStatus('MQTT: error', 'error'));
    client.on('offline', () => setMqttStatus('MQTT: offline', 'error'));

    client.on('message', (topic, payload) => {
        if (!topic.startsWith('weewx/')) return;
        try {
            mergeMqttUpdate(JSON.parse(payload.toString()));
        } catch {
            // Ignore malformed payloads.
        }
    });
}

(async function init() {
    applyGraphVisibility();
    initThemeSelector();
    try {
        await loadLatest();
        await loadHistory(APP.historyRange);
    } catch (err) {
        console.error(err);
        setMqttStatus('API load failed', 'error');
    }

    initRangeButtons();
    connectMqtt();

    setInterval(() => {
        loadLatest().catch(() => {});
    }, 60000);
})();
</script>
</body>
</html>

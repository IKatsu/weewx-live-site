<?php

declare(strict_types=1);

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
require_once dirname($bootstrapPath) . '/forecast_cache.php';
require_once dirname($bootstrapPath) . '/view_helpers.php';

function debug_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function debug_bool(bool $value): string
{
    return $value ? 'yes' : 'no';
}

function debug_fmt_ts(?int $ts, string $timezone, string $timeFormat): string
{
    if ($ts === null || $ts <= 0) {
        return '-';
    }
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($timezone));
    return $dt->format($timeFormat === '12h' ? 'Y-m-d h:i:s A' : 'Y-m-d H:i:s');
}

$config = app_config();
send_security_headers($config);
$debugCfg = (array) ($config['debug'] ?? []);
if (($debugCfg['enabled'] ?? true) !== true) {
    http_response_code(403);
    echo 'Debug page disabled';
    exit;
}
$allowedCidrs = (array) ($debugCfg['allowed_cidrs'] ?? []);
if (!client_ip_allowed($allowedCidrs, (string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
$view = page_view_context($config);
$defaultTheme = (string) $view['default_theme'];
$timeFormat = (string) $view['time_format'];
$timezone = (string) (($config['location']['timezone'] ?? 'UTC') ?: 'UTC');

$runtime = [
    'php_version' => PHP_VERSION,
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'curl' => extension_loaded('curl'),
    'json' => extension_loaded('json'),
];
$archiveLatest = null;
$archiveFields = [];
$forecastRows = [];
$predictionSummary = null;
$error = null;

try {
    $pdo = pdo_from_config($config);
    $columns = archive_columns($pdo);
    $archiveFields = array_keys($columns);

    $selectFields = ['dateTime', 'usUnits', 'outTemp', 'windSpeed', 'solarAltitude', 'solarAzimuth', 'lunarAltitude', 'lunarAzimuth'];
    $select = [];
    foreach ($selectFields as $field) {
        $mapped = mapped_archive_column($config, $columns, $field);
        if ($mapped !== null) {
            $select[] = sprintf('%s AS %s', $mapped, $field);
        }
    }

    if ($select !== []) {
        $dateCol = mapped_archive_column($config, $columns, 'dateTime') ?? 'dateTime';
        $archiveLatest = $pdo->query(sprintf(
            'SELECT %s FROM archive ORDER BY %s DESC LIMIT 1',
            implode(', ', $select),
            $dateCol
        ))->fetch() ?: null;
    }

    $forecastTable = (string) ($config['forecast']['cache_table'] ?? 'pws_wu_forecast_cache');
    if (is_safe_identifier($forecastTable)) {
        $forecastSql = sprintf(
            'SELECT fc.provider, fc.dataset, fc.fetched_at, fc.source_status, fc.source_error
             FROM %1$s fc
             INNER JOIN (
                SELECT provider, dataset, MAX(fetched_at) AS latest_fetched
                FROM %1$s
                GROUP BY provider, dataset
             ) picked
             ON picked.provider = fc.provider
             AND picked.dataset = fc.dataset
             AND picked.latest_fetched = fc.fetched_at
             ORDER BY fc.provider, fc.dataset',
            $forecastTable
        );
        $forecastRows = $pdo->query($forecastSql)->fetchAll();
    }

    $predictionTable = (string) ($config['prediction']['cache_table'] ?? 'pws_prediction_cache');
    if (is_safe_identifier($predictionTable)) {
        $predictionSql = sprintf(
            'SELECT run_id, generated_at, COUNT(*) AS row_count
             FROM %1$s
             WHERE run_id = (SELECT run_id FROM %1$s ORDER BY generated_at DESC LIMIT 1)
             GROUP BY run_id, generated_at
             LIMIT 1',
            $predictionTable
        );
        $predictionSummary = $pdo->query($predictionSql)->fetch() ?: null;
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<?php render_page_head('PWS Debug', $view); ?>
<body>
<div class="forecast-wrap">
<?php
render_site_header('Debug / Admin', default_nav_links($config), [
    '<div class="status-pill"><span>PHP:</span> <strong>' . debug_h($runtime['php_version']) . '</strong></div>',
    '<div class="status-pill"><span>Headers:</span> <strong>' . debug_h(debug_bool((bool) ($config['security']['enable_headers'] ?? false))) . '</strong></div>',
]);
?>

<?php if ($error !== null): ?>
    <article class="card">
        <h2 class="chart-title">Database Error</h2>
        <div class="muted"><?= debug_h($error) ?></div>
    </article>
<?php endif; ?>

    <section class="cards">
        <article class="card">
            <div class="label">Archive latest</div>
            <div class="value"><?= debug_h(debug_fmt_ts(isset($archiveLatest['dateTime']) ? (int) $archiveLatest['dateTime'] : null, $timezone, $timeFormat)) ?></div>
            <div class="muted">Timezone <?= debug_h($timezone) ?></div>
        </article>
        <article class="card">
            <div class="label">Forecast providers</div>
            <div class="value"><?= debug_h(implode(', ', forecast_active_providers($config))) ?></div>
            <div class="muted">Preferred hourly <?= debug_h((string) ($config['forecast']['preferred_hourly_provider'] ?? '-')) ?></div>
        </article>
        <article class="card">
            <div class="label">Prediction rows</div>
            <div class="value"><?= debug_h((string) ($predictionSummary['row_count'] ?? '-')) ?></div>
            <div class="muted">Latest run <?= debug_h((string) ($predictionSummary['run_id'] ?? '-')) ?></div>
        </article>
        <article class="card">
            <div class="label">Archive columns</div>
            <div class="value"><?= debug_h((string) count($archiveFields)) ?></div>
            <div class="muted">Mapped solarAzimuth <?= debug_h((string) ($config['field_map']['solarAzimuth'] ?? '-')) ?></div>
        </article>
    </section>

    <section class="debug-grid">
        <article class="history-card">
            <h2 class="history-title">Runtime Summary</h2>
            <div class="history-table-wrap">
                <table class="history-table debug-table">
                    <tbody>
                        <tr><th>PHP version</th><td><?= debug_h($runtime['php_version']) ?></td></tr>
                        <tr><th>pdo_mysql</th><td><?= debug_h(debug_bool((bool) $runtime['pdo_mysql'])) ?></td></tr>
                        <tr><th>curl</th><td><?= debug_h(debug_bool((bool) $runtime['curl'])) ?></td></tr>
                        <tr><th>json</th><td><?= debug_h(debug_bool((bool) $runtime['json'])) ?></td></tr>
                        <tr><th>Default theme</th><td><?= debug_h($defaultTheme) ?></td></tr>
                        <tr><th>Time format</th><td><?= debug_h($timeFormat) ?></td></tr>
                        <tr><th>UI poll interval</th><td><?= debug_h((string) ($config['ui']['poll_interval_seconds'] ?? '-')) ?> s</td></tr>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="history-card">
            <h2 class="history-title">Paths</h2>
            <div class="history-table-wrap">
                <table class="history-table debug-table">
                    <tbody>
                        <tr><th>base_dir</th><td><?= debug_h((string) ($config['paths']['base_dir'] ?? '')) ?></td></tr>
                        <tr><th>src_dir</th><td><?= debug_h((string) ($config['paths']['src_dir'] ?? '')) ?></td></tr>
                        <tr><th>Forecast cache table</th><td><?= debug_h((string) ($config['forecast']['cache_table'] ?? '')) ?></td></tr>
                        <tr><th>Prediction cache table</th><td><?= debug_h((string) ($config['prediction']['cache_table'] ?? '')) ?></td></tr>
                        <tr><th>MQTT topic</th><td><?= debug_h((string) ($config['mqtt']['topic'] ?? '')) ?></td></tr>
                        <tr><th>MQTT auth exposed</th><td><?= debug_h(debug_bool((bool) ($config['mqtt']['expose_password'] ?? false))) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="history-card">
            <h2 class="history-title">Latest Sky Fields</h2>
            <div class="history-table-wrap">
                <table class="history-table debug-table">
                    <tbody>
                        <tr><th>Archive time</th><td><?= debug_h(debug_fmt_ts(isset($archiveLatest['dateTime']) ? (int) $archiveLatest['dateTime'] : null, $timezone, $timeFormat)) ?></td></tr>
                        <tr><th>Solar altitude</th><td><?= debug_h((string) ($archiveLatest['solarAltitude'] ?? '-')) ?></td></tr>
                        <tr><th>Solar azimuth</th><td><?= debug_h((string) ($archiveLatest['solarAzimuth'] ?? '-')) ?></td></tr>
                        <tr><th>Lunar altitude</th><td><?= debug_h((string) ($archiveLatest['lunarAltitude'] ?? '-')) ?></td></tr>
                        <tr><th>Lunar azimuth</th><td><?= debug_h((string) ($archiveLatest['lunarAzimuth'] ?? '-')) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="history-card">
            <h2 class="history-title">Security Headers</h2>
            <div class="history-table-wrap">
                <table class="history-table debug-table">
                    <tbody>
                        <tr><th>Enabled</th><td><?= debug_h(debug_bool((bool) ($config['security']['enable_headers'] ?? false))) ?></td></tr>
                        <tr><th>Referrer-Policy</th><td><?= debug_h((string) ($config['security']['referrer_policy'] ?? '')) ?></td></tr>
                        <tr><th>X-Frame-Options</th><td><?= debug_h((string) ($config['security']['frame_options'] ?? '')) ?></td></tr>
                        <tr><th>Permissions-Policy</th><td><?= debug_h((string) ($config['security']['permissions_policy'] ?? '')) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <article class="history-card">
        <h2 class="history-title">Forecast Cache Status</h2>
        <div class="history-table-wrap">
            <table class="history-table debug-table">
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Dataset</th>
                        <th>Fetched</th>
                        <th>Status</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
<?php if ($forecastRows === []): ?>
                    <tr><td colspan="5">No forecast cache rows found.</td></tr>
<?php else: ?>
<?php foreach ($forecastRows as $row): ?>
                    <tr>
                        <td><?= debug_h((string) ($row['provider'] ?? '')) ?></td>
                        <td><?= debug_h((string) ($row['dataset'] ?? '')) ?></td>
                        <td><?= debug_h((string) ($row['fetched_at'] ?? '')) ?></td>
                        <td><?= debug_h((string) ($row['source_status'] ?? '')) ?></td>
                        <td><?= debug_h((string) ($row['source_error'] ?? '')) ?></td>
                    </tr>
<?php endforeach; ?>
<?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="history-card">
        <h2 class="history-title">Archive Field Map</h2>
        <pre class="debug-pre"><?= debug_h(json_encode($config['field_map'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </article>
</div>

<script>
const DEBUG_APP = {
    defaultTheme: <?= json_encode($defaultTheme) ?>,
    themes: <?= json_encode(array_keys((array) $view['css_themes'])) ?>,
};

function setTheme(theme) {
    if (!DEBUG_APP.themes.includes(theme)) return;
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('pws_theme', theme); } catch {}
}

function initThemeSelector() {
    const select = document.getElementById('theme-select');
    if (!select) return;
    for (const theme of DEBUG_APP.themes) {
        const option = document.createElement('option');
        option.value = theme;
        option.textContent = theme.charAt(0).toUpperCase() + theme.slice(1);
        select.appendChild(option);
    }
    let initial = DEBUG_APP.defaultTheme;
    try {
        const saved = localStorage.getItem('pws_theme');
        if (saved && DEBUG_APP.themes.includes(saved)) initial = saved;
    } catch {}
    select.value = initial;
    setTheme(initial);
    select.addEventListener('change', () => setTheme(select.value));
}

initThemeSelector();
</script>
</body>
</html>

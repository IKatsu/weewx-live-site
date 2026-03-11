# Install Guide (Fedora 43 tested, Ubuntu notes included)

> Command/path note: shell commands in this document were written in a development environment. Verify and adjust paths for your own host before running them.

This deployment was tested on Fedora 43.
It should also work on other Linux distributions; Ubuntu examples are included below.
The WeeWX database integration has been tested with WeeWX 5.x archives.
The reference station path used during development was an Ecowitt HP2553 console sending to a custom server endpoint, with WeeWX using the `ecowittcustom` driver from `Ecowitt-or-DAVIS-stations-and-Season-skin`.

## 1. Required software

Install Apache, PHP 8+, MySQL driver, and cURL extension for WU fetch:

```bash
sudo dnf install -y httpd php php-mysqlnd php-json php-mbstring php-curl
```

Notes:
- Fedora 43 ships a PHP 8.x release, which satisfies the minimum requirement.
- Frontend libraries (Chart.js + MQTT.js) are loaded from CDN by default.
- Plotly is loaded from `public/assets/vendor` (auto-detected version by default).

Optional WeeWX-side requirement (if installing the included WeeWX extension package):

- [WeeWX 5.x](https://weewx.com/) with the default `weectl` tooling available.
- [`weewx-skyfield-almanac`](https://github.com/roe-dl/weewx-skyfield-almanac) if you want the solar/lunar archive fields used by the dashboard sky widget

Reference projects used during development:

- [WeeWX](https://github.com/weewx/weewx)
- [`Ecowitt-or-DAVIS-stations-and-Season-skin`](https://github.com/WernerKr/Ecowitt-or-DAVIS-stations-and-Season-skin) (Ecowitt driver field naming/mapping reference)
- [`weewx-skyfield-almanac`](https://github.com/roe-dl/weewx-skyfield-almanac) (solar/lunar field source used by the sky widget)
- [`weathericons`](https://github.com/roe-dl/weathericons) (dashboard icon reference)

## 2. Recommended installation order

1. Install the required OS packages.
2. Deploy the PHP project so `public/` is the document root and `src/` remains outside the served path.
3. Create `src/config.local.php` and configure the read-only DB account, location, and optional MQTT settings.
4. Verify that the WeeWX archive already contains the fields you need.
5. If you need solar/lunar custom observations, install `weewx-skyfield-almanac`, then the included `custom_obs` extension, add archive columns with `weectl database add-column`, then restart WeeWX.
6. If you want live browser updates, install/configure Mosquitto and the WeeWX MQTT publisher extension.
7. If you want forecast and prediction pages, create the cache tables and schedule the CLI cron jobs.
8. If you want monthly history to stop re-aggregating closed months, create the summary table and schedule the first-of-month rollup.
9. Optionally mirror the recommended security headers in Apache.
10. Verify the dashboard, charts, MQTT, forecast cache, and history page.

## 3. Deploy the project

Project path:

- `/path/to/pws-live-site`

Recommended Apache document root:

- `/path/to/pws-live-site/public`

Example VirtualHost:

```apache
<VirtualHost *:80>
    ServerName weather.local
    DocumentRoot /path/to/pws-live-site/public

    <Directory /path/to/pws-live-site/public>
        AllowOverride None
        Require all granted
    </Directory>

    ErrorLog /var/log/httpd/pws-live-error.log
    CustomLog /var/log/httpd/pws-live-access.log combined
</VirtualHost>
```

Reference file in this repository:

- `docs/reference/apache-pws-live-site.conf`

## 4. Apache + SELinux notes

If SELinux blocks Apache from remote DB access, allow outbound DB connections:

```bash
sudo setsebool -P httpd_can_network_connect_db 1
```

Then restart Apache:

```bash
sudo systemctl enable --now httpd
sudo systemctl restart httpd
```

Recommended Apache modules:

- `mod_headers` for the hardening headers in `docs/reference/apache-pws-live-site.conf`
- `mod_php` or PHP-FPM, depending on your host layout

The application already sends matching headers from PHP, but mirroring them in Apache is still useful:

- consistent behavior for static assets
- visible vhost-level policy
- one place to tighten or relax headers during deployment

## 5. Runtime configuration

Create a local config file from template:

```bash
cp src/config.defaults.php src/config.local.php
```

Edit `src/config.local.php` with your DB/MQTT settings and optional field mappings/graph toggles/themes.

Optional metric group note:
- Enabling a group only makes its mapped metrics eligible for output.
- If the underlying archive column does not exist, that metric will not render.
- If the column exists but the latest value is `NULL`, the current-value card renders as `n/a`.
- History charts for optional metrics only appear when there are non-`NULL` samples in the selected range.
Set `ui.time_format` to `24h` (default) or `12h` for all displayed times.
Set `ui.mqtt_reconnect_delay_ms` if you want slower MQTT reconnect attempts during outages (`10000` default).
Set `ui.plotly_js` to:
- `auto` (default): highest `plotly-*.min.js` in `public/assets/vendor`
- explicit file path to pin a specific Plotly build
Set `mqtt.enabled` to `false` if you want to run without live MQTT push updates.
Set `mqtt.expose_password` to `true` only if browser-side MQTT auth is unavoidable.
Use `ui.battery_status_labels` to label integer battery status codes (for example `5 = OK`).
Use `ui.sensor_thresholds.air_quality.alert_level` to control PM2.5 warning highlighting (`75` default).
Use `ui.sensor_thresholds.soil_moisture.low` / `high` to control soil moisture out-of-range highlighting.

Environment variable overrides:

- `PWS_DB_HOST`, `PWS_DB_PORT`, `PWS_DB_NAME`, `PWS_DB_USER`, `PWS_DB_PASS`
- `PWS_MQTT_URL`, `PWS_MQTT_USER`, `PWS_MQTT_PASS`, `PWS_MQTT_TOPIC`
- `PWS_MQTT_ENABLED`
- `PWS_MQTT_EXPOSE_PASSWORD`
- `PWS_API_DUMP_ENABLED`, `PWS_API_DUMP_DEFAULT_ROWS`, `PWS_API_DUMP_MAX_ROWS`, `PWS_API_DUMP_TOKEN`
- `PWS_HISTORY_DEFAULT_HOURS`, `PWS_HISTORY_MAX_HOURS`

If you run the site over HTTPS, use secure MQTT WebSocket (`wss://...`) to avoid browser mixed-content blocking.

## 6. Mosquitto broker setup (MQTT/WebSocket)

Reference config files in this repository:

- `docs/reference/mosquitto.conf`
- `docs/reference/acl.conf`

### Fedora

Install package:

```bash
sudo dnf install -y mosquitto
```

### Ubuntu

Install packages:

```bash
sudo apt update
sudo apt install -y mosquitto mosquitto-clients
```

### Common configuration steps

1. Place reference config files:

```bash
sudo cp /path/to/pws-live-site/docs/reference/mosquitto.conf /etc/mosquitto/mosquitto.conf
sudo cp /path/to/pws-live-site/docs/reference/acl.conf /etc/mosquitto/acl.conf
```

2. Create password file and users required by the provided ACL:

```bash
sudo mosquitto_passwd -c /etc/mosquitto/pwfile weewx
sudo mosquitto_passwd /etc/mosquitto/pwfile weewx-readonly
```

3. Ensure file permissions allow mosquitto service user to read:

```bash
sudo chown root:mosquitto /etc/mosquitto/pwfile /etc/mosquitto/acl.conf
sudo chmod 640 /etc/mosquitto/pwfile /etc/mosquitto/acl.conf
```

4. Enable and restart broker:

```bash
sudo systemctl enable --now mosquitto
sudo systemctl restart mosquitto
sudo systemctl status mosquitto
```

5. Verify ACL behavior quickly:

```bash
mosquitto_sub -h 127.0.0.1 -p 1883 -u weewx-readonly -P 'YOUR_PASSWORD' -t 'weewx/#' -v
```

The provided ACL grants:

- `weewx`: read/write on `weewx/#`
- `weewx-readonly`: read-only on `weewx/#`

## 7. WeeWX MQTT publisher extension (for live updates)

The dashboard's browser live updates require WeeWX to publish LOOP/archive data to MQTT.

Recommended extension:

- [`matthewwall/weewx-mqtt`](https://github.com/matthewwall/weewx-mqtt)

Install on WeeWX 5+:

```bash
weectl extension install https://github.com/matthewwall/weewx-mqtt/archive/master.zip
```

Then configure in `weewx.conf`:

```ini
[StdRESTful]
    [[MQTT]]
        server_url = mqtt://weewx:YOUR_PASSWORD@localhost:1883
        topic = weewx
        unit_system = METRICWX
        retain = true
        binding = archive, loop
        aggregation = aggregate
```

If you skip this, set `mqtt.enabled = false` in this project so the UI does not try to connect.

## 8. Plotly upgrades

To upgrade Plotly, copy a new build into:

- `public/assets/vendor`

Example:

```bash
cp plotly-3.1.0.min.js /path/to/pws-live-site/public/assets/vendor/
```

With `ui.plotly_js = 'auto'`, the site will automatically use the newest `plotly-*.min.js` file.

## 9. Verification checklist

1. Open the dashboard page.
2. Confirm weather cards load values from MySQL.
3. Confirm charts render and resize on mobile/desktop.
4. Confirm MQTT status changes to `connected` and values update live.
5. Confirm the range buttons (`Today`, `Yesterday`, `Last Week`, `Last Month`, `Last Year`) reload history successfully.
6. Confirm rain chart shows both rain rate and hourly rain sum.
7. Confirm battery charts render for wind/rain/lightning/pm25 battery fields.
8. Confirm the top-of-page metric rows are grouped logically and PM2.5 shows air-quality coloring.
9. Confirm `php src/cli/fetch_forecast.php --force` succeeds and dashboard forecast panels fill.

API format check:

10. Confirm archive export formats:
   - `/api/dump.php` (CSV default, limited rows)
   - `/api/dump.php?type=json&limit=500`
   - `/api/dump.php?type=xml&limit=500&offset=0`
   - If `api.dump_token` is configured, include `token=...` or `X-Api-Token` header

## 10. Forecast DB cache (WU or OpenWeather)

Apply the SQL schema (run with a user that has CREATE TABLE rights):

```bash
mysql -u DB_USER -p DB_NAME < docs/sql/create_pws_wu_forecast_cache.sql
```

Then set in `src/config.local.php`:

- `forecast.provider = 'wu'` or `forecast.provider = 'openweather'`
- Optional combined mode:
  - `forecast.providers = ['wu', 'openweather']`
  - `forecast.preferred_hourly_provider` / `forecast.preferred_daily_provider`
- WU:
  - `forecast.wu_api_key = '...'`
  - optional `forecast.wu_hourly_enabled = false` for daily-only WU plans
- OpenWeather:
  - `forecast.owm_api_key = '...'`
  - `forecast.owm_mode = 'onecall_3'` (paid One Call API 3.0) or `forecast.owm_mode = 'free_5d'` (free plan)
  - `onecall_3` provides weather alerts; these are shown on the dashboard alert banner
- station geocode via `location.latitude` + `location.longitude` (or provider-specific `wu_*` / `owm_*` overrides)
- `forecast.refresh_interval_seconds = 1800` to keep single-call providers around 48 calls/day (<50/day)
- `forecast_writer_db.*` to use a cron-only DB account for forecast cache writes

Cron example (30 min):

```cron
*/30 * * * * cd /path/to/pws-live-site && php src/cli/fetch_forecast.php >> /var/log/pws-forecast-cron.log 2>&1
```

## 11. Monthly history rollup cache

Apply the SQL schema (run with a user that has CREATE TABLE and GRANT rights):

```bash
mysql -u DB_USER -p DB_NAME < docs/sql/create_pws_history_monthly_summary.sql
```

This creates:

- `pws_history_monthly_summary`

and grants the localhost cron writer user access:

- `pws_forecast_writer@localhost`

If you prefer a different writer account, adjust the SQL and `src/config.local.php` accordingly.

Configure writer credentials in `src/config.local.php`:

- `history_writer_db.*` for a dedicated monthly-history writer
- if left empty, the CLI falls back to `forecast_writer_db.*`
- if that is also empty, it falls back to the main `db` account

Manual build:

```bash
php src/cli/build_monthly_history.php
```

Expected success output:

- `Monthly history refresh completed: month=2026-02 inserted=21 existing=0 empty=0 missing=0`

Useful flags:

```bash
php src/cli/build_monthly_history.php --force
php src/cli/build_monthly_history.php --month=2026-02
```

Cron example (first day of each month):

```cron
5 0 1 * * cd /path/to/pws-live-site && php src/cli/build_monthly_history.php >> /var/log/pws-history-rollup.log 2>&1
```

Behavior:

- closed months are served from `pws_history_monthly_summary`
- the current month still uses live `archive_day_*` data
- the history page uses `history.lookback_years` from config
- nothing is inserted if a month has no data
- without `--force`, an existing month is left untouched
## 12. WeeWX custom_obs extension (optional but recommended for skyfield live fields)

This repo includes an extension package at:

- `weewx/custom_obs`

Install from your WeeWX host:

```bash
cd /path/to/pws-live-site/weewx/custom_obs
weectl extension install .
```

Or install from the packaged zip in this repo:

```bash
weectl extension install /path/to/pws-live-site/weewx/custom_obs/custom_obs-extension.zip
```

Then ensure archive columns exist for these observations:

- `solarAzimuth`, `solarAltitude`, `solarTime`
- `lunarAzimuth`, `lunarAltitude`, `lunarTime`

Add them with `weectl` on the WeeWX host:

```bash
weectl database add-column solarAzimuth
weectl database add-column solarAltitude
weectl database add-column solarTime
weectl database add-column lunarAzimuth
weectl database add-column lunarAltitude
weectl database add-column lunarTime
```

Restart WeeWX after database column changes so services and accumulators pick up the updated schema.

## 13. Prediction cache (hybrid local + WU)

Apply the SQL schema (run with a user that has CREATE TABLE rights):

```bash
mysql -u DB_USER -p DB_NAME < docs/sql/create_pws_prediction_cache.sql
```

Required archive fields:

- `dateTime`
- `usUnits`
- `outTemp`
- `outHumidity`
- `barometer`
- `windSpeed`
- `rainRate`

Prediction builder command:

```bash
php src/cli/build_predictions.php --force
```

Expected success output:

- `Prediction cache refresh completed: run_id=... rows=25`

Cron example (30 min):

```cron
*/30 * * * * cd /path/to/pws-live-site && php src/cli/build_predictions.php >> /var/log/pws-prediction-cron.log 2>&1
```

Full details:

- `docs/WEEWX_CUSTOM_OBS_EXTENSION.md`

---
Author: Codex (GPT-5)

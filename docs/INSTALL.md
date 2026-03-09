# Install Guide (Fedora 43 tested, Ubuntu notes included)

> Command/path note: shell commands in this document were written in a development environment. Verify and adjust paths for your own host before running them.

This deployment was tested on Fedora 43.
It should also work on other Linux distributions; Ubuntu examples are included below.
The WeeWX database integration has been tested with WeeWX 5.x archives.

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

- WeeWX 5+ with `weectl` command available.

## 2. Deploy the project

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

## 3. Apache + SELinux notes

If SELinux blocks Apache from remote DB access, allow outbound DB connections:

```bash
sudo setsebool -P httpd_can_network_connect_db 1
```

Then restart Apache:

```bash
sudo systemctl enable --now httpd
sudo systemctl restart httpd
```

## 4. Runtime configuration (optional)

Create a local config file from template:

```bash
cp src/config.defaults.php src/config.local.php
```

Edit `src/config.local.php` with your DB/MQTT settings and optional field mappings/graph toggles/themes.
Set `ui.time_format` to `24h` (default) or `12h` for all displayed times.
Set `ui.plotly_js` to:
- `auto` (default): highest `plotly-*.min.js` in `public/assets/vendor`
- explicit file path to pin a specific Plotly build
Set `mqtt.enabled` to `false` if you want to run without live MQTT push updates.
Set `mqtt.expose_password` to `true` only if browser-side MQTT auth is unavoidable.
Use `ui.battery_status_labels` to label integer battery status codes (for example `5 = OK`).

Environment variable overrides:

- `PWS_DB_HOST`, `PWS_DB_PORT`, `PWS_DB_NAME`, `PWS_DB_USER`, `PWS_DB_PASS`
- `PWS_MQTT_URL`, `PWS_MQTT_USER`, `PWS_MQTT_PASS`, `PWS_MQTT_TOPIC`
- `PWS_MQTT_ENABLED`
- `PWS_MQTT_EXPOSE_PASSWORD`
- `PWS_API_DUMP_ENABLED`, `PWS_API_DUMP_DEFAULT_ROWS`, `PWS_API_DUMP_MAX_ROWS`, `PWS_API_DUMP_TOKEN`
- `PWS_HISTORY_DEFAULT_HOURS`, `PWS_HISTORY_MAX_HOURS`

If you run the site over HTTPS, use secure MQTT WebSocket (`wss://...`) to avoid browser mixed-content blocking.

## 5. Mosquitto broker setup (MQTT/WebSocket)

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

## 6. WeeWX MQTT publisher extension (for live updates)

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
        unit_system = METRIC
        retain = true
        binding = archive, loop
        aggregation = aggregate
```

If you skip this, set `mqtt.enabled = false` in this project so the UI does not try to connect.

## 7. Plotly upgrades

To upgrade Plotly, copy a new build into:

- `public/assets/vendor`

Example:

```bash
cp plotly-3.1.0.min.js /path/to/pws-live-site/public/assets/vendor/
```

With `ui.plotly_js = 'auto'`, the site will automatically use the newest `plotly-*.min.js` file.

## 8. Verification checklist

1. Open the dashboard page.
2. Confirm weather cards load values from MySQL.
3. Confirm charts render and resize on mobile/desktop.
4. Confirm MQTT status changes to `connected` and values update live.
5. Confirm the range buttons (`Today`, `Yesterday`, `Last Week`, `Last Month`, `Last Year`) reload history successfully.
6. Confirm rain chart shows both rain rate and hourly rain sum.
7. Confirm battery charts render for wind/rain/lightning/pm25 battery fields.
8. Confirm `php src/cli/fetch_wu_forecast.php --force` succeeds and dashboard forecast panels fill.

API format check:

9. Confirm archive export formats:
   - `/api/dump.php` (CSV default, limited rows)
   - `/api/dump.php?type=json&limit=500`
   - `/api/dump.php?type=xml&limit=500&offset=0`
   - If `api.dump_token` is configured, include `token=...` or `X-Api-Token` header

## 9. WU forecast DB cache (option 1)

Apply the SQL schema (run with a user that has CREATE TABLE rights):

```bash
mysql -u DB_USER -p DB_NAME < docs/sql/create_pws_wu_forecast_cache.sql
```

Then set in `src/config.local.php`:

- `forecast.provider = 'wu'`
- `forecast.wu_api_key = '...'`
- station geocode via `location.latitude` + `location.longitude` (or `forecast.wu_latitude` / `forecast.wu_longitude`)
- `forecast_writer_db.*` to use a cron-only DB account for forecast cache writes

Cron example (15 min):

```cron
*/15 * * * * cd /path/to/pws-live-site && php src/cli/fetch_wu_forecast.php >> /var/log/pws-forecast-cron.log 2>&1
```

## 10. WeeWX custom_obs extension (optional but recommended for skyfield live fields)

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
weectl database add-column solarAzimuth=REAL
weectl database add-column solarAltitude=REAL
weectl database add-column solarTime=REAL
weectl database add-column lunarAzimuth=REAL
weectl database add-column lunarAltitude=REAL
weectl database add-column lunarTime=REAL
```

Restart WeeWX after database column changes so services and accumulators pick up the updated schema.

## 11. Prediction cache (hybrid local + WU)

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

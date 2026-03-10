# PWS Live Site (PHP)

> Command/path note: shell commands in this document were written in a development environment. Verify and adjust paths for your own host before running them.

# Promptwriter's note

This was made for weewx 5.2+ that is using Mysql and the ecowittcustom driver.
I was struggling to get all the data correctly displayed with existing skins and the tiny fan of my Intel NuC that is hosting my LAN only weewx installation was REALLY driving me nuts every 5 minutes as weewx started to do it's html/image generation cycle. Being a sysadmin means that, unlike most developers, you know that just because you HAVE free cpu cycles, doesn't mean you HAVE to use them :P
Knowing SOME php / mysql I was kinda surprised there weren't any weewx sites that just pulled the data directly from the database and generate most things client side.
So I decided to try and make my own little PWS site, but I was lazy as well as curious so I had Codex make it.
I tried to have it write code in a way to keep the site customizable to other peoples possible needs.

None of the used accounts have write access other than the one for the cli program so it should be (relatively) safe code. 
That said, I have no idea how safe mqtt is to have open to the internet, use at your own risk, but it reduces the load on the server greatly. Just 1 sql query and live updats through mqtt.

So this whole project took about 4 days of 2-3 hours a day of telling codex what to do, figuring out issues with missing weewx values, basic troubleshooting and on the fly design changes because I am indecisive and come up with new things on the go.
Overall it turned out pretty nice, better than I had expected for sure.

# Info

Live weather dashboard for weewx data with:
- latest conditions from MySQL (`weather.archive`)
- responsive history charts (Chart.js)
- live value updates via MQTT WebSocket (`weewx/#`)
- history presets: `Today`, `Yesterday`, `Last Week`, `Last Month`, `Last Year`
- wind rose + wind direction point chart
- separate rain chart with rain rate and hourly rain totals
- dedicated battery charts (`windBatteryStatus`, `rainBatteryStatus`, `lightning_Batt`, `pm25_Batt1`)
- cached WU/TWC forecast integration (dashboard + dedicated forecast page)
- archive-based trend page (`trends.php`)
- hybrid prediction cache and page (`prediction.php`)
- optional WeeWX `custom_obs` extension package for solar/lunar custom field registration

Compatibility note:
- This project has been tested against a WeeWX 5.x archive database layout.
- Runtime stack and MQTT setup have been tested on Fedora 43; Ubuntu instructions are in `docs/INSTALL.md`.

## Run locally

```bash
cd pws-live-site
cp src/config.defaults.php src/config.local.php
# edit src/config.local.php with your hostnames/credentials
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080`.

## Software requirements

- PHP 8.0+ (`php`, `php-mysqlnd`, `php-json`, `php-mbstring`, `php-curl`)
- MySQL/MariaDB server containing WeeWX archive data
- [WeeWX 5.x](https://weewx.com/) with a MySQL/MariaDB-backed archive
- Apache or another PHP-capable web server
- Optional (for live browser updates): WeeWX MQTT extension [`matthewwall/weewx-mqtt`](https://github.com/matthewwall/weewx-mqtt)

## Reference projects used during development

- [weewx](https://weewx.com/) for the archive layout, extension model, and MQTT/custom observation integration points
- `Ecowitt-or-DAVIS-stations-and-Season-skin` from the workspace for the Ecowitt driver field names and custom observation mapping
- `weathericons` from the workspace for the dashboard icon set

## Recommended install order

1. Install system packages (`php`, Apache, DB driver, optional Mosquitto).
2. Deploy the project files so `public/` is the web root and `src/` stays outside the served path.
3. Create `src/config.local.php` and configure:
   - read-only website DB access
   - optional forecast-writer DB access
   - location
   - MQTT broker settings
4. Make sure the WeeWX archive already contains the fields you want to show.
5. If needed, install the included WeeWX `custom_obs` extension and add missing archive columns with `weectl database add-column`.
6. Restart WeeWX after any archive schema changes.
7. If using live updates, install/configure Mosquitto and the WeeWX MQTT publisher extension.
8. If using forecast/prediction pages, create the cache tables and schedule the CLI cron jobs.
9. Drop Plotly into `public/assets/vendor` if you want the Plotly-based wind rose.
10. Open the dashboard and verify cards, charts, MQTT, and forecast cache behavior.

## Configuration model

- Safe defaults: `src/config.defaults.php` (tracked in git)
- Local secrets: `src/config.local.php` (gitignored)
- Runtime override: environment variables
- Security checklist: `docs/SECURITY_NOTES.md`

Environment variables (optional overrides):

- `PWS_DB_HOST` (default from local config)
- `PWS_DB_PORT` (default `3306`)
- `PWS_DB_NAME` (default from local config)
- `PWS_DB_USER` (default from local config)
- `PWS_DB_PASS` (default from local config)
- `PWS_MQTT_URL` (default from local config)
- `PWS_MQTT_ENABLED` (default `true`)
- `PWS_MQTT_EXPOSE_PASSWORD` (default `false`)
- `PWS_MQTT_USER` (default from local config)
- `PWS_MQTT_PASS` (default from local config)
- `PWS_MQTT_TOPIC` (default `weewx/#`)
- `PWS_API_DUMP_ENABLED` (default `true`)
- `PWS_API_DUMP_DEFAULT_ROWS` (default `1000`)
- `PWS_API_DUMP_MAX_ROWS` (default `10000`)
- `PWS_API_DUMP_TOKEN` (optional)
- `PWS_HISTORY_DEFAULT_HOURS` (default `24`)
- `PWS_HISTORY_MAX_HOURS` (default `8784`)
- `PWS_WU_API_KEY` (overrides `forecast.wu_api_key`)
- `PWS_OWM_API_KEY` (overrides `forecast.owm_api_key`)
- `PWS_FORECAST_PROVIDER` (`wu`, `openweather`, `none`)
- `PWS_FORECAST_DB_HOST`, `PWS_FORECAST_DB_PORT`, `PWS_FORECAST_DB_NAME`, `PWS_FORECAST_DB_USER`, `PWS_FORECAST_DB_PASS`

## Forecast cache setup (WU or OpenWeather)

1. Apply SQL schema:

```bash
mysql -u DB_USER -p DB_NAME < docs/sql/create_pws_wu_forecast_cache.sql
```

2. Configure forecast values in `src/config.local.php`:
- `forecast.provider = 'wu'` or `forecast.provider = 'openweather'`
- Optional combined mode:
  - `forecast.providers = ['wu', 'openweather']`
  - `forecast.preferred_hourly_provider = 'openweather'` (example)
  - `forecast.preferred_daily_provider = 'wu'` (example)
- For WU:
  - `forecast.wu_api_key`
  - optional `forecast.wu_hourly_enabled = false` if your subscription only includes daily APIs
- For OpenWeather:
  - `forecast.owm_api_key`
  - `forecast.owm_mode = 'onecall_3'` (paid) or `'free_5d'` (free 3-hour endpoint)
  - With `onecall_3`, weather alerts are also cached and shown on the main page.
- `location.latitude` / `location.longitude` (default geocode source for both providers)
- `forecast.refresh_interval_seconds = 1800` keeps single-call providers at ~48 calls/day (<50/day target)
- `forecast_writer_db.*` for the cron writer account (optional but recommended)

3. Refresh cache manually:

```bash
php src/cli/fetch_forecast.php --force
```

4. Add cron (example every 30 minutes, ~48 calls/day):

```cron
*/30 * * * * cd /path/to/pws-live-site && php src/cli/fetch_forecast.php >> /var/log/pws-forecast-cron.log 2>&1
```

## Prediction setup (hybrid local + WU guardrails)

1. Apply SQL schema:

```bash
mysql -u DB_USER -p DB_NAME < docs/sql/create_pws_prediction_cache.sql
```

2. Ensure `forecast_writer_db.*` in `src/config.local.php` can write to `pws_prediction_cache`.

3. Build predictions manually:

```bash
php src/cli/build_predictions.php --force
```

Expected success output:
- `Prediction cache refresh completed: run_id=... rows=25`

4. Add cron (example every 30 minutes):

```cron
*/30 * * * * cd /path/to/pws-live-site && php src/cli/build_predictions.php >> /var/log/pws-prediction-cron.log 2>&1
```

Required archive fields for prediction:
- `dateTime`
- `usUnits`
- `outTemp`
- `outHumidity`
- `barometer`
- `windSpeed`
- `rainRate`

Optional for better hybrid behavior:
- Daily forecast cache table (`pws_wu_forecast_cache`) populated by `fetch_forecast.php`

## Path and theme configuration

Edit `src/config.local.php` to control filesystem/UI settings and field mappings:

- `paths.*` for filesystem locations (relative paths supported)
- `ui.css_*` and `ui.css_themes` for theme files
- `ui.time_format` for clock style (`24h` default, or `12h`)
- `ui.plotly_js` for plotly loading (`auto` default)
- `ui.mqtt_reconnect_delay_ms` to slow MQTT reconnect attempts during broker/network outages (`10000` default)
- `mqtt.enabled` to enable/disable live MQTT updates (`true` default)
- `mqtt.expose_password` to explicitly expose MQTT password to browser JS (`false` default)
- `ui.battery_status_labels` to map integer battery status codes (for example `5`) to text labels
- `ui.sensor_thresholds.air_quality.alert_level` for PM2.5 warning highlighting (`75` default)
- `ui.sensor_thresholds.soil_moisture.low` / `high` for soil moisture out-of-range highlighting
- `ui.graphs.*` to enable/disable specific graphs
- `field_map.*` to map logical fields to database column names

If `mqtt.enabled = false`, the site still works with MySQL polling/history/forecast, but live browser push updates are disabled.
Keep `mqtt.expose_password = false` unless browser auth is unavoidable for your broker.

Battery note:
- Battery series are auto-detected as status-style values when they are integer codes (for example `0`, `1`, `5`, `9`).
- In that case, cards/charts show status labels instead of assuming volts.

Air-quality note:
- The dashboard colors PM2.5 values using the Dutch `Luchtmeetnet` PM2.5 index-style bands.
- The current-weather area also shows a PM2.5 air-quality pill.
- `pm25_1` is suppressed in this installation because it duplicates the primary `pm2_5` reading.

### WeeWX MQTT extension setup (for live updates)

Install WeeWX MQTT publisher extension on your WeeWX host (WeeWX 5+):

```bash
weectl extension install https://github.com/matthewwall/weewx-mqtt/archive/master.zip
```

Then add/configure `[StdRESTful][[MQTT]]` in `weewx.conf` (server URL, topic, binding `archive, loop`) so data is published to your broker topic used by this dashboard.

Relative paths are resolved against `paths.base_dir`.

### Plotly auto-discovery

With `ui.plotly_js = 'auto'` (default), the app scans `public/assets/vendor` and automatically uses the highest `plotly-*.min.js` version it finds.

That means you can update Plotly by dropping a newer file, for example:

- `public/assets/vendor/plotly-3.1.0.min.js`

No code changes are needed.

If you want to pin a specific file, set `ui.plotly_js` to an explicit path (for example `assets/vendor/plotly-2.35.2.min.js`).

## WeeWX custom_obs extension

The repository includes a WeeWX extension package that registers custom skyfield live-data observation names (`solarAzimuth`, `solarAltitude`, `solarTime`, `lunarAzimuth`, `lunarAltitude`, `lunarTime`) with WeeWX unit groups.

See:

- `docs/WEEWX_CUSTOM_OBS_EXTENSION.md`

## API endpoints

- `GET /api/latest.php`
- `GET /api/history.php?hours=24&endOffsetHours=0&bucketMinutes=5&fields=outTemp,dewpoint,outHumidity,windSpeed,windGust,windDir,barometer,pressure,rainRate,rainHourly`
- `GET /api/forecast.php` (reads cached WU forecast from DB)
- `GET /api/trends.php` (archive-based local trend nowcast)
- `GET /api/prediction.php` (latest prediction cache run)
- `GET /api/dump.php` (default output: CSV, row-limited)
  - `GET /api/dump.php?type=csv` -> `text/csv`
  - `GET /api/dump.php?type=json` -> `application/json`
  - `GET /api/dump.php?type=xml` -> `application/xml`
  - Optional paging/limits:
    - `limit` (capped by `api.dump_max_rows`)
    - `offset`
  - Optional token protection:
    - `token=...` query or `X-Api-Token` header when `api.dump_token` is configured

There is currently no separate `docs/API.md`; the API summary lives here in the main README.

---
Author: Codex (GPT-5)

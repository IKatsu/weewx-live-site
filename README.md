# PWS Live Site (PHP)

Live weather dashboard for weewx data with:
- latest conditions from MySQL (`weather.archive`)
- responsive history charts (Chart.js)
- live value updates via MQTT WebSocket (`weewx/#`)
- history presets: `Today`, `Yesterday`, `Last Week`, `Last Month`, `Last Year`
- wind rose + wind direction point chart
- separate rain chart with rain rate and hourly rain totals
- dedicated battery charts (`windBatteryStatus`, `rainBatteryStatus`, `lightning_Batt`, `pm25_Batt1`)
- cached WU/TWC forecast integration (dashboard + dedicated forecast page)
- optional WeeWX `custom_obs` extension package for solar/lunar custom field registration

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
- Apache or another PHP-capable web server
- Optional (for WeeWX extension install): `wee_extension` command on the WeeWX host

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
- `PWS_MQTT_USER` (default from local config)
- `PWS_MQTT_PASS` (default from local config)
- `PWS_MQTT_TOPIC` (default `weewx/#`)
- `PWS_HISTORY_DEFAULT_HOURS` (default `24`)
- `PWS_HISTORY_MAX_HOURS` (default `8784`)
- `PWS_WU_API_KEY` (overrides `forecast.wu_api_key`)
- `PWS_FORECAST_DB_HOST`, `PWS_FORECAST_DB_PORT`, `PWS_FORECAST_DB_NAME`, `PWS_FORECAST_DB_USER`, `PWS_FORECAST_DB_PASS`

## Forecast cache setup (WU option 1)

1. Apply SQL schema:

```bash
mysql -u weather -p weather < docs/sql/create_pws_wu_forecast_cache.sql
```

2. Configure WU values in `src/config.local.php`:
- `forecast.provider = 'wu'`
- `forecast.wu_api_key`
- Set `forecast.wu_hourly_enabled = false` if your plan only includes daily forecast APIs.
- `location.latitude` / `location.longitude` (used by both astro widgets and, by default, WU geocode)
- `forecast_writer_db.*` for the cron writer account (optional but recommended)

3. Refresh cache manually:

```bash
php src/cli/fetch_wu_forecast.php --force
```

4. Add cron (example every 15 minutes):

```cron
*/15 * * * * cd /path/to/pws-live-site && php src/cli/fetch_wu_forecast.php >> /var/log/pws-forecast-cron.log 2>&1
```

## Path and theme configuration

Edit `src/config.local.php` to control filesystem/UI settings and field mappings:

- `paths.*` for filesystem locations (relative paths supported)
- `ui.css_*` and `ui.css_themes` for theme files
- `ui.time_format` for clock style (`24h` default, or `12h`)
- `ui.plotly_js` for plotly loading (`auto` default)
- `ui.graphs.*` to enable/disable specific graphs
- `field_map.*` to map logical fields to database column names

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

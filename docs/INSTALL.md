# Install Guide (Fedora 43)

## 1. Required software

Install Apache, PHP 8+, MySQL driver, and cURL extension for WU fetch:

```bash
sudo dnf install -y httpd php php-mysqlnd php-json php-mbstring php-curl
```

Notes:
- Fedora 43 ships a PHP 8.x release, which satisfies the minimum requirement.
- Frontend libraries (Chart.js + MQTT.js) are loaded from CDN by default.

## 2. Deploy the project

Project path:

- `/path/to/home/Documents/Dev/pws-live-site`

Recommended Apache document root:

- `/path/to/home/Documents/Dev/pws-live-site/public`

Example VirtualHost:

```apache
<VirtualHost *:80>
    ServerName weather.local
    DocumentRoot /path/to/home/Documents/Dev/pws-live-site/public

    <Directory /path/to/home/Documents/Dev/pws-live-site/public>
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

Environment variable overrides:

- `PWS_DB_HOST`, `PWS_DB_PORT`, `PWS_DB_NAME`, `PWS_DB_USER`, `PWS_DB_PASS`
- `PWS_MQTT_URL`, `PWS_MQTT_USER`, `PWS_MQTT_PASS`, `PWS_MQTT_TOPIC`
- `PWS_HISTORY_DEFAULT_HOURS`, `PWS_HISTORY_MAX_HOURS`

If you run the site over HTTPS, use secure MQTT WebSocket (`wss://...`) to avoid browser mixed-content blocking.

## 5. Verification checklist

1. Open the dashboard page.
2. Confirm weather cards load values from MySQL.
3. Confirm charts render and resize on mobile/desktop.
4. Confirm MQTT status changes to `connected` and values update live.
5. Confirm the range buttons (`Today`, `Yesterday`, `Last Week`, `Last Month`, `Last Year`) reload history successfully.
6. Confirm rain chart shows both rain rate and hourly rain sum.
7. Confirm battery charts render for wind/rain/lightning/pm25 battery fields.
8. Confirm `php src/cli/fetch_wu_forecast.php --force` succeeds and dashboard forecast panels fill.

## 6. WU forecast DB cache (option 1)

Apply the SQL schema (run with a user that has CREATE TABLE rights):

```bash
mysql -u weather -p weather < docs/sql/create_pws_wu_forecast_cache.sql
```

Then set in `src/config.local.php`:

- `forecast.provider = 'wu'`
- `forecast.wu_api_key = '...'`
- station geocode via `location.latitude` + `location.longitude` (or `forecast.wu_latitude` / `forecast.wu_longitude`)

Cron example (15 min):

```cron
*/15 * * * * cd /path/to/pws-live-site && php src/cli/fetch_wu_forecast.php >> /var/log/pws-forecast-cron.log 2>&1
```

## Optional database optimization suggestions

If `archive` grows large, add indexes to speed latest/history queries:

```sql
ALTER TABLE archive ADD INDEX idx_archive_dateTime (dateTime);
```

For pre-aggregated chart periods, consider creating summary views/tables (hourly or daily) and querying those for long time ranges.

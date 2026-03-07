# Install Guide (Fedora 43)

## 1. Required software

Install Apache, PHP 8+, and MySQL driver:

```bash
sudo dnf install -y httpd php php-mysqlnd php-json php-mbstring
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

## Optional database optimization suggestions

If `archive` grows large, add indexes to speed latest/history queries:

```sql
ALTER TABLE archive ADD INDEX idx_archive_dateTime (dateTime);
```

For pre-aggregated chart periods, consider creating summary views/tables (hourly or daily) and querying those for long time ranges.

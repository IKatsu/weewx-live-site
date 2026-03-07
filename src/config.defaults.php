<?php

declare(strict_types=1);

return [
    'paths' => [
        // `__DIR__` means the public web root (set by entrypoint via PWS_BASE_DIR).
        'base_dir' => '__DIR__',
        // Keep config/src outside of the served directory.
        'src_dir' => '../../src',
    ],
    'ui' => [
        'css_base' => 'assets/css/base.css',
        'css_themes' => [
            'bright' => 'assets/css/theme-bright.css',
            'dark' => 'assets/css/theme-dark.css',
        ],
        'default_theme' => 'bright',
        // Supported: "24h" (default) or "12h".
        'time_format' => '24h',
        // Browser polling interval for latest values when API polling is used.
        'poll_interval_seconds' => 15,
        'css_custom' => '',
        // "auto" selects the highest plotly-*.min.js found in public/assets/vendor.
        'plotly_js' => 'auto',
        'plotly_wind_rose' => true,
        // Optional mapping for battery status-like integer values (0,1,5,9,...).
        'battery_status_labels' => [
            '0' => 'Normal',
            '1' => 'Low',
            '2' => 'Low',
            '3' => 'Critical',
            '4' => 'Critical',
            '5' => 'OK',
            '9' => 'Low',
        ],
        // Layout controls for all standard charts.
        'layout' => [
            'graph_max_columns' => 3,
            'graph_min_width_px' => 320,
            'graph_height_px' => 260,
            // Wind rose keeps its own row/size for readability.
            'wind_rose_height_px' => 380,
        ],
        'graphs' => [
            'temp_outside' => true,
            'temp_inside' => true,
            'humidity_outside' => true,
            'humidity_inside' => true,
            'wind_speed' => true,
            'wind_direction' => true,
            'pressure' => true,
            'rain_rate_hourly' => true,
            'rain_total_duration' => true,
            'feels_like' => true,
            'solar' => true,
            'cloudbase' => true,
            'et' => true,
            'sunshine' => true,
            'windrun' => true,
            'pm25' => true,
            'lightning' => true,
            'wind_rose' => true,
            'battery_wind' => true,
            'battery_rain' => true,
            'battery_lightning' => true,
            'battery_pm25' => true,
            'battery_indoor' => true,
        ],
    ],
    'location' => [
        // Required for sunrise/sunset and moonrise/moonset calculations.
        'latitude' => 0.0,
        'longitude' => 0.0,
        'timezone' => 'UTC',
    ],
    'forecast' => [
        // Provider should be "wu" when WU/TWC forecast integration is enabled.
        'provider' => 'wu',
        'cache_ttl_seconds' => 900,
        'refresh_interval_seconds' => 900,
        'cache_table' => 'pws_wu_forecast_cache',
        'dashboard_hours' => 5,
        'wu_base_url' => 'https://api.weather.com',
        'wu_api_key' => 'CHANGE_ME',
        // Disable hourly fetch when subscription only includes daily forecast.
        'wu_hourly_enabled' => true,
        'wu_units' => 'm',
        'wu_language' => 'en-US',
        // Leave at 0.0 to inherit from location.latitude/location.longitude.
        'wu_latitude' => 0.0,
        'wu_longitude' => 0.0,
        // Valid values are API-defined durations such as "2day" or "15day".
        'wu_hourly_duration' => '2day',
        'wu_daily_duration_days' => 10,
    ],
    'field_map' => [
        'dateTime' => 'dateTime',
        'usUnits' => 'usUnits',
        'outTemp' => 'outTemp',
        'inTemp' => 'inTemp',
        'dewpoint' => 'dewpoint',
        'inDewpoint' => 'inDewpoint',
        'appTemp' => 'appTemp',
        'heatindex' => 'heatindex',
        'windchill' => 'windchill',
        'humidex' => 'humidex',
        'outHumidity' => 'outHumidity',
        'inHumidity' => 'inHumidity',
        'barometer' => 'barometer',
        'pressure' => 'pressure',
        'windSpeed' => 'windSpeed',
        'windGust' => 'windGust',
        'windDir' => 'windDir',
        'windrun' => 'windrun',
        'rainRate' => 'rainRate',
        'rain' => 'rain',
        'rainDur' => 'rainDur',
        'UV' => 'UV',
        'radiation' => 'radiation',
        'cloudbase' => 'cloudbase',
        'ET' => 'ET',
        'solarAltitude' => 'solarAltitude',
        'solarAzimuth' => 'solarAzimuth',
        'solarTime' => 'solarTime',
        'lunarAltitude' => 'lunarAltitude',
        'lunarAzimuth' => 'lunarAzimuth',
        'lunarTime' => 'lunarTime',
        'sunshineDur' => 'sunshineDur',
        'pm2_5' => 'pm2_5',
        'lightning_strike_count' => 'lightning_strike_count',
        'windBatteryStatus' => 'windBatteryStatus',
        'rainBatteryStatus' => 'rainBatteryStatus',
        'lightning_Batt' => 'lightning_Batt',
        'pm25_Batt1' => 'pm25_Batt1',
        'inTempBatteryStatus' => 'inTempBatteryStatus',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'weather',
        'username' => 'weather',
        'password' => 'CHANGE_ME',
    ],
    // Dedicated writer credentials for cron forecast cache refreshes.
    // Leave empty to fall back to the read-only `db` credentials.
    'forecast_writer_db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'weather',
        'username' => '',
        'password' => '',
    ],
    'mqtt' => [
        'enabled' => true,
        // Keep false unless browser-side MQTT authentication is strictly required.
        'expose_password' => false,
        'url' => 'ws://127.0.0.1:9001/mqtt',
        'username' => 'CHANGE_ME',
        'password' => 'CHANGE_ME',
        'topic' => 'weewx/#',
    ],
    'api' => [
        // Harden dump endpoint by default while keeping it available.
        'dump_enabled' => true,
        'dump_default_rows' => 1000,
        'dump_max_rows' => 10000,
        // If set, /api/dump.php requires this token (GET token= or X-Api-Token header).
        'dump_token' => '',
    ],
    'history' => [
        'default_hours' => '24',
        'max_hours' => (string) (24 * 366),
    ],
];

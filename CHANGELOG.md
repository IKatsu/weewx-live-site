# Changelog

All notable changes to this project should be documented in this file.

The format is intentionally simple:

- version header
- release date
- short list of user-visible changes

## Unreleased

- Added wind summary cards in the `Wind` row for 1-hour and 3-hour wind/gust averages
- Added a separate `Wind Averages / Gust Averages` chart and kept the main wind chart focused on raw speed/gust values
- Added backend wind summary derivation and hourly average series support in `latest.php` and `history.php`
- Split rain metrics into `Rain Today` and `Rain 24h` so live MQTT values and derived archive totals no longer conflict
- Updated the dashboard to prefer live MQTT loop payload values and reduced background API refresh to a slow fallback interval
- Fixed `latest.php` placeholder binding issues that caused dashboard bootstrap failures
- Disabled the archive dump API by default and kept token-based access as an optional local override
- Disabled the debug page by default and added a separate config toggle for showing the debug nav link
- Moved duplicate-sensor suppression such as `pm25_1` into local config instead of hard-coding it in shared code
- Fixed bucketed wind-direction history by using vector averaging instead of plain scalar averages
- Reworked the sky widget to draw sampled SunCalc sun and moon paths instead of placeholder arcs
- Updated README and install documentation to reflect the wind summary and rain metric changes

## v1.0.0 - 2026-03-10

- Initial stable release of the PHP live dashboard
- Direct MySQL-backed latest conditions and history charts
- MQTT live updates over WebSocket
- Wind rose, wind compass, and solar/lunar sky widget
- Forecast integration with Weather Underground and OpenWeather
- Trend page and hybrid prediction page
- Monthly high/average/low history page
- Monthly history rollup CLI job and summary-table support
- Optional sensor-group framework with configurable field mapping
- Theme support, configurable graph layout, and responsive frontend
- API export endpoints for CSV, JSON, and XML
- WeeWX custom observation extension package and installation docs
- Apache hardening guidance and deployment documentation

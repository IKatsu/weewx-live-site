# Celestial Cache

> Note: Double-check paths in commands before running them. Some examples may differ slightly from your webserver or WeeWX environment.

The celestial page can use a precomputed Skyfield cache instead of asking WeeWX skin/report generation to render almanac panels every few minutes.

This project does **not** use the WeeWX skin output from `weewx-skyfield`. Instead, `src/cli/build_celestial_cache.py` uses Skyfield directly, emits derived JSON, and `src/cli/build_celestial_cache.php` stores that JSON in MySQL for `public/celestial.php`.

The current daily cache includes sampled paths for the configured sun, moon, and planet bodies. The browser uses those paths for live marker interpolation, rise/set ribbons, the sun-path panel, the solar-system map, and the lunar-month strip.

The yearly cache includes weekly daylight/twilight samples for the "daylight week by week" graph. Rebuild the yearly cache after changing station location or timezone.

## Reference

The implementation is inspired by:

- `weewx-skyfield`: https://github.com/chaunceygardiner/weewx-skyfield
- `weewx-skyfield` panel documentation: https://chaunceygardiner.github.io/weewx-skyfield/panels.html

## Dataset And License Notes

`weewx-skyfield` is GPL-3.0 licensed. This project is AGPL-3.0, so code ideas and compatible GPL code can be used with attribution.

Do not copy bundled data files from `weewx-skyfield` into this repository:

- `wxskyfield_de421.bsp`
- `wxskyfield_stars.dat.gz`
- `wxskyfield_lines.dat`
- downloaded TLE/comet files

Reason:

- Ephemeris, star catalog, constellation, and TLE data have their own upstream licensing and freshness rules.
- The Hipparcos/Tycho star data referenced by `weewx-skyfield` is documented as ESA data under CC BY-NC 3.0 IGO.
- Local/non-commercial use is fine for your own station, but these datasets should stay outside git and outside release archives.

Use a local data directory such as:

```bash
mkdir -p /var/lib/pws-live-site/skyfield
```

Then configure either:

```php
'celestial' => [
    'data_dir' => '/var/lib/pws-live-site/skyfield',
    'ephemeris_path' => '',
]
```

or point directly at a local ephemeris file:

```php
'celestial' => [
    'ephemeris_path' => '/home/YOUR_USER/Documents/Dev/reference/weewx-skyfield/bin/user/wxskyfield_de421.bsp',
]
```

The second option is convenient for local testing only. Do not copy that `.bsp` file into this project.

## Requirements

Install Python dependencies in the same environment used by the cron job:

```bash
python3 -m pip install 'skyfield>=1.47' numpy
```

## Database

Create the cache table:

```bash
mysql -u DB_ADMIN_USER -p DB_NAME < docs/sql/create_pws_celestial_cache.sql
```

If your writer account is not `pws_forecast_writer`, adjust the `GRANT` line first.

Optional full-dome catalog schema:

```bash
mysql -u DB_ADMIN_USER -p DB_NAME < docs/sql/create_pws_celestial_catalog.sql
```

That schema provides empty tables for locally imported stars and constellation lines. It does not include catalog data and is not required for the current sun/moon/planet panels.

Validate local catalog files without writing rows:

```bash
php src/cli/import_celestial_catalog.php \
  --stars=/path/to/wxskyfield_stars.dat.gz \
  --lines=/path/to/wxskyfield_lines.dat \
  --dry-run
```

Import local catalog files:

```bash
php src/cli/import_celestial_catalog.php \
  --stars=/path/to/wxskyfield_stars.dat.gz \
  --lines=/path/to/wxskyfield_lines.dat \
  --force
```

With the `weewx-skyfield` reference files, the dry run should report approximately:

- `stars=118218`
- `constellation_polylines=219`
- `constellation_points=914`
- `names=88`

The web page then reads projected visible stars/constellation lines through:

```text
GET /api/celestial_catalog.php
```

This endpoint returns derived alt/az JSON only. It does not serve the source catalog files.

Future Download Location

If later ISS/TLE, comet, or other stale orbital-data downloads are added, cron scripts should download those files to `/tmp` or a subdirectory such as `/tmp/pws-live-site`, not into this repository. Static catalog files can be staged locally for import, but should still stay outside git and release archives.

## Build

Build all cache datasets:

```bash
php src/cli/build_celestial_cache.php --force
```

Build one dataset:

```bash
php src/cli/build_celestial_cache.php --dataset=daily --force
```

After upgrading from an older cache shape, rebuild at least:

```bash
php src/cli/build_celestial_cache.php --dataset=daily --force
php src/cli/build_celestial_cache.php --dataset=yearly --force
```

The daily rebuild populates the solar-system and lunation panels. The yearly rebuild populates the daylight week-by-week panel.

Suggested cron:

```cron
10 0 * * * cd /var/www/pws-live-site && php src/cli/build_celestial_cache.php --dataset=daily --force
20 0 * * * cd /var/www/pws-live-site && php src/cli/build_celestial_cache.php --dataset=monthly --force
30 0 1 1 * cd /var/www/pws-live-site && php src/cli/build_celestial_cache.php --dataset=yearly --force
```

## ISS

ISS support is intentionally disabled by default. It needs fresh TLE data, which is not predictable in the same way as sun/moon/planet positions.

If added later, keep the TLE file outside git and refresh it separately from CelesTrak or another trusted source, preferably under `/tmp/pws-live-site` from cron.

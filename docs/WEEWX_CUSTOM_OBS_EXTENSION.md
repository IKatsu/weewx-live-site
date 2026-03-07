# WeeWX Custom Obs Extension (`custom_obs`)

## Why this extension exists

When `weewx-skyfield-almanac` is configured with live data enabled, custom solar/lunar fields can appear in LOOP/MQTT output:

- `solarAzimuth`
- `solarAltitude`
- `solarTime`
- `lunarAzimuth`
- `lunarAltitude`
- `lunarTime`

These values can be visible in MQTT without being consistently archived unless WeeWX knows their unit group. This extension registers those observation names in `weewx.units.obs_group_dict`.

## Scope (what it does and does not do)

It **does**:
- register custom observation names to `group_direction`

It **does not**:
- rewrite LOOP packets
- copy/rename fields
- calculate new values
- patch MQTT output formatting

## Files in this repo

- `weewx/custom_obs/install.py`
- `weewx/custom_obs/bin/user/custom_obs.py`

## Install

From your WeeWX host:

```bash
cd /path/to/pws-live-site/weewx/custom_obs
wee_extension --install .
```

## Service wiring

The installer adds:

- `user.custom_obs.CustomObsService` to `Engine/Services/prep_services`

The installer also reorders `prep_services` so `CustomObsService` stays near the front (immediately after `weewx.engine.StdTimeSynch` when present), ensuring unit-group registration is available early.

## Accumulator entries

The installer adds `Accumulator` entries so archive records use the last LOOP value:

- `solarAzimuth`, `solarAltitude`, `solarTime`
- `lunarAzimuth`, `lunarAltitude`, `lunarTime`

all with:

- `extractor = last`

## Archive table columns

Your `archive` table should include matching columns:

- `solarAzimuth`
- `solarAltitude`
- `solarTime`
- `lunarAzimuth`
- `lunarAltitude`
- `lunarTime`

A helper SQL script is provided at:

- `docs/sql/add_weewx_custom_obs_columns.sql`

## MQTT naming caveat

Do not use MQTT-formatted field names (for example `solarAltitude_degree_angle`) as archive observation names. Register/use the canonical observation names listed above.

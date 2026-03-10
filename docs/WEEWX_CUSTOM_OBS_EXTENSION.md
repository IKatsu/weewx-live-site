# WeeWX Custom Obs Extension (`custom_obs`)

> Command/path note: shell commands in this document were written in a development environment. Verify and adjust paths for your own host before running them.

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
weectl extension install custom_obs-extension.zip
```

Or install from the packaged zip in this repository:

```bash
weectl extension install /path/to/pws-live-site/weewx/custom_obs/custom_obs-extension.zip
```

Compatibility note:

- This extension workflow has been tested with WeeWX 5.x.

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

Add them with `weectl database add-column` on the WeeWX host (WeeWX 5+):

```bash
weectl database add-column solarAzimuth
weectl database add-column solarAltitude
weectl database add-column solarTime
weectl database add-column lunarAzimuth
weectl database add-column lunarAltitude
weectl database add-column lunarTime
```

Restart WeeWX after adding/changing archive columns.

## MQTT naming caveat

Do not use MQTT-formatted field names (for example `solarAltitude_degree_angle`) as archive observation names. Register/use the canonical observation names listed above.

## Adding your own custom values

If you want to archive additional custom LOOP fields, follow this pattern:

1. Add the observation name to `OBS_GROUP_MAP` in:
   - `weewx/custom_obs/bin/user/custom_obs.py`
2. Choose the correct WeeWX unit group for that field.
3. Add matching accumulator settings (`extractor = last` is typical for live values).
4. Add a matching column in the `archive` table with `weectl database add-column`.
5. Restart WeeWX and verify data appears in new archive rows.

Example (`soilTemp2` as temperature):

```python
OBS_GROUP_MAP = {
    # existing entries ...
    "soilTemp2": "group_temperature",
}
```

Accumulator example:

```ini
[Accumulator]
    [[soilTemp2]]
        extractor = last
```

Archive schema example:

```bash
weectl database add-column soilTemp2
```

### Common unit groups

- `group_temperature` for temperature values
- `group_pressure` for pressure values
- `group_speed` for speed values
- `group_rain` for rain amount values
- `group_direction` for angular values (degrees/azimuth/altitude)
- `group_count` for counters

Pick the unit group that matches how WeeWX should treat and convert the value.

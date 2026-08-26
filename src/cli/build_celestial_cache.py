#!/usr/bin/env python3
"""Generate derived celestial cache JSON for pws-live-site.

This script uses Skyfield directly and intentionally does not depend on WeeWX
skin/report generation. It can use a local ephemeris file, but it does not
bundle or copy ephemeris/star/constellation datasets into this repository.
"""

from __future__ import annotations

import argparse
import json
import math
import os
from datetime import date, datetime, time, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

from skyfield import almanac
from skyfield.api import load, wgs84


BODY_MAP = {
    "sun": "sun",
    "moon": "moon",
    "mercury": "mercury",
    "venus": "venus",
    "mars": "mars",
    "jupiter": "jupiter barycenter",
    "saturn": "saturn barycenter",
    "uranus": "uranus barycenter",
    "neptune": "neptune barycenter",
}

PHASE_NAMES = {
    0: "New Moon",
    1: "First Quarter",
    2: "Full Moon",
    3: "Last Quarter",
}

SEASON_NAMES = {
    0: "March Equinox",
    1: "June Solstice",
    2: "September Equinox",
    3: "December Solstice",
}


def iso(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def local_midnight(day: date, tz: ZoneInfo) -> datetime:
    return datetime.combine(day, time.min, tzinfo=tz)


def fmt_time(t, tz: ZoneInfo) -> str:
    return iso(t.utc_datetime().replace(tzinfo=timezone.utc).astimezone(tz))


def safe_float(value, decimals: int = 3):
    try:
        n = float(value)
    except (TypeError, ValueError):
        return None
    if not math.isfinite(n):
        return None
    return round(n, decimals)


def load_ephemeris(ephemeris_path: str, data_dir: str):
    if ephemeris_path:
        return load(str(Path(ephemeris_path).expanduser()))
    if data_dir:
        loader = load.Loader(str(Path(data_dir).expanduser()))
        return loader("de421.bsp")
    return load("de421.bsp")


def body_altaz(eph, observer, body_name: str, ts, when):
    body = eph[BODY_MAP[body_name]]
    apparent = observer.at(when).observe(body).apparent()
    alt, az, distance = apparent.altaz()
    return {
        "body": body_name,
        "altitude": safe_float(alt.degrees, 3),
        "azimuth": safe_float(az.degrees, 3),
        "distanceAu": safe_float(distance.au, 6),
    }


def event_list(find_func, observer, body, t0, t1, tz: ZoneInfo):
    times, flags = find_func(observer, body, t0, t1)
    out = []
    for t, flag in zip(times, flags):
        if bool(flag):
            out.append(fmt_time(t, tz))
    return out


def transit_list(observer, body, t0, t1, tz: ZoneInfo):
    try:
        times = almanac.find_transits(observer, body, t0, t1)
    except Exception:
        return []
    return [fmt_time(t, tz) for t in times]


def first_or_none(values):
    return values[0] if values else None


def generate_daily(args) -> dict:
    tz = ZoneInfo(args.timezone)
    day = date.fromisoformat(args.date)
    start_local = local_midnight(day, tz)
    end_local = start_local + timedelta(days=1)
    now = datetime.now(timezone.utc)

    eph = load_ephemeris(args.ephemeris, args.data_dir)
    ts = load.timescale()
    site = wgs84.latlon(args.latitude, args.longitude, elevation_m=args.altitude)
    observer = eph["earth"] + site
    t0 = ts.from_datetime(start_local.astimezone(timezone.utc))
    t1 = ts.from_datetime(end_local.astimezone(timezone.utc))
    t_now = ts.from_datetime(now)

    enabled = [b for b in args.bodies if b in BODY_MAP]
    events = {}
    bodies = {}
    for name in enabled:
        body = eph[BODY_MAP[name]]
        body_events = {
            "rise": first_or_none(event_list(almanac.find_risings, observer, body, t0, t1, tz)),
            "set": first_or_none(event_list(almanac.find_settings, observer, body, t0, t1, tz)),
            "transit": first_or_none(transit_list(observer, body, t0, t1, tz)),
        }
        events[name] = body_events
        bodies[name] = body_altaz(eph, observer, name, ts, t_now)

    paths = {}
    sample_step = max(1, args.sample_minutes)
    sample_count = int((24 * 60) / sample_step) + 1
    for name in enabled:
        rows = []
        for idx in range(sample_count):
            dt = start_local + timedelta(minutes=idx * sample_step)
            pos = body_altaz(eph, observer, name, ts, ts.from_datetime(dt.astimezone(timezone.utc)))
            pos["time"] = iso(dt)
            rows.append(pos)
        paths[name] = rows

    twilight = {}
    for label, horizon in [("civil", -6.0), ("nautical", -12.0), ("astronomical", -18.0)]:
        sun = eph["sun"]
        twilight[label] = {
            "dawn": first_or_none(event_list(lambda o, b, a, c: almanac.find_risings(o, b, a, c, horizon_degrees=horizon), observer, sun, t0, t1, tz)),
            "dusk": first_or_none(event_list(lambda o, b, a, c: almanac.find_settings(o, b, a, c, horizon_degrees=horizon), observer, sun, t0, t1, tz)),
        }

    moon_phase_angle = almanac.moon_phase(eph, t_now).degrees
    moon_illum = almanac.fraction_illuminated(eph, "moon", t_now)
    eot = equation_of_time_minutes(now, args.longitude)

    return {
        "dataset": "daily",
        "periodKey": day.isoformat(),
        "generatedAt": iso(now),
        "validFrom": iso(start_local),
        "validUntil": iso(end_local),
        "location": {
            "latitude": args.latitude,
            "longitude": args.longitude,
            "altitude": args.altitude,
            "timezone": args.timezone,
        },
        "source": {
            "engine": "skyfield",
            "reference": "https://github.com/chaunceygardiner/weewx-skyfield",
            "note": "Derived with Skyfield directly; no WeeWX skin output or bundled catalog data is stored in this cache.",
        },
        "events": events,
        "twilight": twilight,
        "bodies": bodies,
        "paths": paths,
        "moon": {
            "phaseAngle": safe_float(moon_phase_angle, 3),
            "illumination": safe_float(moon_illum * 100.0, 2),
            "phaseName": moon_phase_name(moon_phase_angle),
        },
        "time": {
            "equationOfTimeMinutes": safe_float(eot, 2),
        },
    }


def generate_monthly(args) -> dict:
    tz = ZoneInfo(args.timezone)
    day = date.fromisoformat(args.date).replace(day=1)
    if day.month == 12:
        next_month = day.replace(year=day.year + 1, month=1)
    else:
        next_month = day.replace(month=day.month + 1)
    start_local = local_midnight(day, tz)
    end_local = local_midnight(next_month, tz)
    now = datetime.now(timezone.utc)

    eph = load_ephemeris(args.ephemeris, args.data_dir)
    ts = load.timescale()
    t0 = ts.from_datetime(start_local.astimezone(timezone.utc))
    t1 = ts.from_datetime(end_local.astimezone(timezone.utc))
    times, phases = almanac.find_discrete(t0, t1, almanac.moon_phases(eph))
    phase_rows = [
        {"time": fmt_time(t, tz), "phase": int(p), "label": PHASE_NAMES.get(int(p), str(int(p)))}
        for t, p in zip(times, phases)
    ]

    discs = []
    cursor = start_local
    while cursor < end_local:
        t = ts.from_datetime(cursor.astimezone(timezone.utc))
        angle = almanac.moon_phase(eph, t).degrees
        discs.append({
            "date": cursor.date().isoformat(),
            "phaseAngle": safe_float(angle, 3),
            "illumination": safe_float(almanac.fraction_illuminated(eph, "moon", t) * 100.0, 2),
            "phaseName": moon_phase_name(angle),
        })
        cursor += timedelta(days=1)

    return {
        "dataset": "monthly",
        "periodKey": day.strftime("%Y-%m"),
        "generatedAt": iso(now),
        "validFrom": iso(start_local),
        "validUntil": iso(end_local),
        "moonPhases": phase_rows,
        "lunationDays": discs,
        "source": {"engine": "skyfield", "reference": "https://github.com/chaunceygardiner/weewx-skyfield"},
    }


def generate_yearly(args) -> dict:
    tz = ZoneInfo(args.timezone)
    year = date.fromisoformat(args.date).year
    start_local = datetime(year, 1, 1, tzinfo=tz)
    end_local = datetime(year + 1, 1, 1, tzinfo=tz)
    now = datetime.now(timezone.utc)

    eph = load_ephemeris(args.ephemeris, args.data_dir)
    ts = load.timescale()
    t0 = ts.from_datetime(start_local.astimezone(timezone.utc))
    t1 = ts.from_datetime(end_local.astimezone(timezone.utc))
    times, seasons = almanac.find_discrete(t0, t1, almanac.seasons(eph))

    return {
        "dataset": "yearly",
        "periodKey": str(year),
        "generatedAt": iso(now),
        "validFrom": iso(start_local),
        "validUntil": iso(end_local),
        "seasons": [
            {"time": fmt_time(t, tz), "season": int(s), "label": SEASON_NAMES.get(int(s), str(int(s)))}
            for t, s in zip(times, seasons)
        ],
        "equationOfTime": [
            {
                "date": (start_local + timedelta(days=i)).date().isoformat(),
                "minutes": safe_float(equation_of_time_minutes(start_local + timedelta(days=i), args.longitude), 2),
            }
            for i in range(0, 366 if is_leap_year(year) else 365, 7)
        ],
        "source": {"engine": "skyfield", "reference": "https://github.com/chaunceygardiner/weewx-skyfield"},
    }


def moon_phase_name(angle_degrees: float) -> str:
    phase = (angle_degrees % 360.0) / 360.0
    if phase < 0.03 or phase > 0.97:
        return "New Moon"
    if phase < 0.22:
        return "Waxing Crescent"
    if phase < 0.28:
        return "First Quarter"
    if phase < 0.47:
        return "Waxing Gibbous"
    if phase < 0.53:
        return "Full Moon"
    if phase < 0.72:
        return "Waning Gibbous"
    if phase < 0.78:
        return "Last Quarter"
    return "Waning Crescent"


def equation_of_time_minutes(dt: datetime, longitude: float) -> float:
    del longitude
    day = int(dt.strftime("%j"))
    b = (2.0 * math.pi * (day - 81)) / 364.0
    return 9.87 * math.sin(2.0 * b) - 7.53 * math.cos(b) - 1.5 * math.sin(b)


def is_leap_year(year: int) -> bool:
    return year % 4 == 0 and (year % 100 != 0 or year % 400 == 0)


def parse_args():
    parser = argparse.ArgumentParser(description="Build pws-live-site celestial cache JSON")
    parser.add_argument("--dataset", choices=["daily", "monthly", "yearly"], default="daily")
    parser.add_argument("--date", default=date.today().isoformat())
    parser.add_argument("--latitude", type=float, required=True)
    parser.add_argument("--longitude", type=float, required=True)
    parser.add_argument("--altitude", type=float, default=0.0)
    parser.add_argument("--timezone", default="UTC")
    parser.add_argument("--sample-minutes", type=int, default=10)
    parser.add_argument("--bodies", default="sun,moon,mercury,venus,mars,jupiter,saturn")
    parser.add_argument("--data-dir", default=os.environ.get("PWS_SKYFIELD_DATA_DIR", ""))
    parser.add_argument("--ephemeris", default=os.environ.get("PWS_SKYFIELD_EPHEMERIS", ""))
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    args.bodies = [item.strip().lower() for item in str(args.bodies).split(",") if item.strip()]
    payload = {
        "daily": generate_daily,
        "monthly": generate_monthly,
        "yearly": generate_yearly,
    }[args.dataset](args)
    print(json.dumps(payload, separators=(",", ":"), ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

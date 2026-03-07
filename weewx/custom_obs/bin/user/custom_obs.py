"""Register custom solar/lunar observation types for WeeWX unit handling.

This service only updates ``weewx.units.obs_group_dict`` so custom observation
names injected by other extensions (such as skyfield-almanac live data) can be
archived consistently.
"""

import logging

import weewx.engine
import weewx.units

log = logging.getLogger(__name__)


class CustomObsService(weewx.engine.StdService):
    """Register custom observation names in WeeWX's unit-group registry."""

    OBS_GROUP_MAP = {
        "solarAzimuth": "group_direction",
        "solarAltitude": "group_direction",
        "solarTime": "group_direction",
        "lunarAzimuth": "group_direction",
        "lunarAltitude": "group_direction",
        "lunarTime": "group_direction",
    }

    def __init__(self, engine, config_dict):
        super().__init__(engine, config_dict)

        # Register each custom field once at startup; no packet rewriting happens here.
        for obs_name, group_name in self.OBS_GROUP_MAP.items():
            weewx.units.obs_group_dict[obs_name] = group_name

        log.info("CustomObsService registered %d custom observation types", len(self.OBS_GROUP_MAP))

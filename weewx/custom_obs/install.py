"""Installer for the pws-live-site custom_obs WeeWX extension."""

from weecfg.extension import ExtensionInstaller
from weeutil.weeutil import option_as_list


def loader():
    return CustomObsInstaller()


class CustomObsInstaller(ExtensionInstaller):
    def __init__(self):
        super(CustomObsInstaller, self).__init__(
            version="1.0.0",
            name="custom_obs",
            description="Register custom solar/lunar observation types for archiving.",
            author="pws-live-site",
            author_email="noreply@example.invalid",
            prep_services="user.custom_obs.CustomObsService",
            config={
                "Accumulator": {
                    "solarAzimuth": {"extractor": "last"},
                    "solarAltitude": {"extractor": "last"},
                    "solarTime": {"extractor": "last"},
                    "lunarAzimuth": {"extractor": "last"},
                    "lunarAltitude": {"extractor": "last"},
                    "lunarTime": {"extractor": "last"},
                },
            },
            files=[("bin/user", ["bin/user/custom_obs.py"])],
        )

    def configure(self, engine):
        """Keep service near the front of prep_services for early registration."""
        target = "user.custom_obs.CustomObsService"
        services_cfg = engine.config_dict["Engine"]["Services"]
        current = option_as_list(services_cfg.get("prep_services", []))

        # Ensure exactly one entry, then place it after StdTimeSynch if present.
        current = [svc for svc in current if svc != target]
        insert_at = 1 if current and current[0] == "weewx.engine.StdTimeSynch" else 0
        current.insert(insert_at, target)
        services_cfg["prep_services"] = current
        return True

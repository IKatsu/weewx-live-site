-- Add custom skyfield-derived observation columns expected by custom_obs extension.
-- Run against the same database used by WeeWX archive.

ALTER TABLE archive
    ADD COLUMN IF NOT EXISTS solarAzimuth DOUBLE NULL,
    ADD COLUMN IF NOT EXISTS solarAltitude DOUBLE NULL,
    ADD COLUMN IF NOT EXISTS solarTime DOUBLE NULL,
    ADD COLUMN IF NOT EXISTS lunarAzimuth DOUBLE NULL,
    ADD COLUMN IF NOT EXISTS lunarAltitude DOUBLE NULL,
    ADD COLUMN IF NOT EXISTS lunarTime DOUBLE NULL;

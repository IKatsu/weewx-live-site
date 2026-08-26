-- Optional sky catalog tables for a full celestial dome.
-- This schema is tracked, but catalog source files and imported data are not.
--
-- Supported local sources can include:
-- - Hipparcos/Tycho-derived star data, such as wxskyfield_stars.dat.gz
-- - Stellarium-derived constellation line data, such as wxskyfield_lines.dat
--
-- Check upstream licenses before importing and publishing data.

CREATE TABLE IF NOT EXISTS pws_celestial_catalog_meta (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    catalog_key VARCHAR(64) NOT NULL,
    source_name VARCHAR(128) NOT NULL,
    source_license VARCHAR(128) NOT NULL DEFAULT '',
    source_url VARCHAR(255) NOT NULL DEFAULT '',
    imported_at DATETIME NOT NULL,
    notes TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_celestial_catalog_key (catalog_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pws_celestial_stars (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    catalog_key VARCHAR(64) NOT NULL DEFAULT 'hipparcos',
    hip_id INT UNSIGNED NOT NULL,
    right_ascension_degrees DOUBLE NOT NULL,
    declination_degrees DOUBLE NOT NULL,
    magnitude DOUBLE NULL,
    proper_name VARCHAR(96) NOT NULL DEFAULT '',
    spectral_type VARCHAR(64) NOT NULL DEFAULT '',
    raw_json JSON NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_celestial_star_catalog_hip (catalog_key, hip_id),
    KEY idx_celestial_star_mag (catalog_key, magnitude),
    KEY idx_celestial_star_ra_dec (catalog_key, right_ascension_degrees, declination_degrees)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pws_celestial_constellation_names (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    constellation_abbr VARCHAR(8) NOT NULL,
    locale VARCHAR(16) NOT NULL DEFAULT 'en',
    display_name VARCHAR(96) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_celestial_constellation_locale (constellation_abbr, locale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pws_celestial_constellation_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    catalog_key VARCHAR(64) NOT NULL DEFAULT 'stellarium-modern',
    constellation_abbr VARCHAR(8) NOT NULL,
    polyline_id INT UNSIGNED NOT NULL,
    point_order INT UNSIGNED NOT NULL,
    hip_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_celestial_constellation_point (catalog_key, constellation_abbr, polyline_id, point_order),
    KEY idx_celestial_constellation_hip (catalog_key, hip_id),
    KEY idx_celestial_constellation_abbr (catalog_key, constellation_abbr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reuse the localhost-only cron/import writer if desired.
GRANT SELECT, INSERT, UPDATE, DELETE ON weather.pws_celestial_catalog_meta TO 'pws_forecast_writer'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON weather.pws_celestial_stars TO 'pws_forecast_writer'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON weather.pws_celestial_constellation_names TO 'pws_forecast_writer'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON weather.pws_celestial_constellation_lines TO 'pws_forecast_writer'@'localhost';
FLUSH PRIVILEGES;

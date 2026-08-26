-- Celestial cache used by src/cli/build_celestial_cache.php and public/api/celestial.php.
-- The payload is generated from local Skyfield data and intentionally stores
-- only derived JSON, not bundled ephemeris/star/catalog datasets.

CREATE TABLE IF NOT EXISTS pws_celestial_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dataset VARCHAR(32) NOT NULL,
    period_key VARCHAR(32) NOT NULL,
    generated_at DATETIME NOT NULL,
    valid_from DATETIME NOT NULL,
    valid_until DATETIME NOT NULL,
    payload_json LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_celestial_dataset_period (dataset, period_key),
    KEY idx_celestial_valid (dataset, valid_from, valid_until),
    KEY idx_celestial_generated (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reuse the existing cron writer account if desired.
GRANT SELECT, INSERT, UPDATE ON weather.pws_celestial_cache TO 'pws_forecast_writer'@'localhost';
FLUSH PRIVILEGES;

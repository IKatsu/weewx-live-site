-- Cache table for external WU/TWC forecast data used by pws-live-site.
-- Descriptive name intentionally avoids overlap with weewx archive_* tables.
CREATE TABLE IF NOT EXISTS pws_wu_forecast_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(32) NOT NULL,
    dataset VARCHAR(32) NOT NULL,
    location_key VARCHAR(64) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    fetched_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    source_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    source_error VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_provider_dataset (provider, dataset),
    KEY idx_expires_at (expires_at),
    KEY idx_fetched_at (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dedicated cron writer account (change password before running).
CREATE USER IF NOT EXISTS 'pws_forecast_writer'@'localhost' IDENTIFIED BY 'CHANGE_ME_FORECAST_WRITER_PASSWORD';

-- Minimum privileges for the cron forecast refresh script.
GRANT SELECT, INSERT, UPDATE ON weather.pws_wu_forecast_cache TO 'pws_forecast_writer'@'localhost';

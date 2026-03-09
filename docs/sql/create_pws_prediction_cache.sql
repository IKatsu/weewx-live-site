-- Prediction cache used by src/cli/build_predictions.php and public/api/prediction.php

CREATE TABLE IF NOT EXISTS pws_prediction_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id CHAR(32) NOT NULL,
    generated_at DATETIME NOT NULL,
    target_time DATETIME NOT NULL,
    metric VARCHAR(64) NOT NULL,
    unit VARCHAR(24) NOT NULL DEFAULT '',
    value_num DOUBLE NULL,
    confidence DOUBLE NULL,
    method VARCHAR(64) NOT NULL DEFAULT 'local_blend_v1',
    details_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_prediction_run (run_id),
    KEY idx_prediction_target (target_time, metric),
    KEY idx_prediction_generated (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grant only the permissions needed by the prediction writer cron user.
GRANT SELECT, INSERT, UPDATE ON weather.pws_prediction_cache TO 'pws_forecast_writer'@'localhost';
FLUSH PRIVILEGES;

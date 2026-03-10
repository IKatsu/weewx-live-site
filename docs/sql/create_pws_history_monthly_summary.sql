CREATE TABLE IF NOT EXISTS `pws_history_monthly_summary` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `field_key` VARCHAR(64) NOT NULL,
  `source_column` VARCHAR(64) NOT NULL,
  `month_key` CHAR(7) NOT NULL,
  `month_start` DATE NOT NULL,
  `sample_days` INT UNSIGNED NOT NULL DEFAULT 0,
  `low_value` DOUBLE NULL,
  `avg_value` DOUBLE NULL,
  `high_value` DOUBLE NULL,
  `generated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pws_history_field_month` (`field_key`, `month_key`),
  KEY `idx_pws_history_month_start` (`month_start`),
  KEY `idx_pws_history_source_column` (`source_column`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

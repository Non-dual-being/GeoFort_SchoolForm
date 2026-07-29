-- Run after disabled_dates and admin_users exist. MySQL/MariaDB DDL implicitly commits.
CREATE TABLE IF NOT EXISTS calendar_date_change_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  action ENUM('calendar_date_blocked', 'calendar_date_released') NOT NULL,
  scope ENUM('single', 'period') NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  reason VARCHAR(255) NULL,
  affected_count SMALLINT UNSIGNED NOT NULL,
  summary_json JSON NOT NULL,
  changed_by_admin_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_calendar_date_history_range_created (start_date, end_date, created_at),
  KEY idx_calendar_date_history_admin (changed_by_admin_id),
  CONSTRAINT fk_calendar_date_history_admin FOREIGN KEY (changed_by_admin_id) REFERENCES admin_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE IF NOT EXISTS calendar_date_change_history_dates (
  history_id BIGINT UNSIGNED NOT NULL,
  calendar_date DATE NOT NULL,
  manually_blocked_before TINYINT(1) NOT NULL,
  manually_blocked_after TINYINT(1) NOT NULL,
  reason_before VARCHAR(255) NULL,
  reason_after VARCHAR(255) NULL,
  PRIMARY KEY (history_id, calendar_date),
  KEY idx_calendar_date_history_dates_date (calendar_date, history_id),
  CONSTRAINT fk_calendar_date_history_dates_history FOREIGN KEY (history_id) REFERENCES calendar_date_change_history (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

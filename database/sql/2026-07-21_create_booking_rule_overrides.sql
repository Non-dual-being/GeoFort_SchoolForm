-- Run after booking_status_history exists. MariaDB/MySQL DDL implicitly commits.
-- aanvragen.id is signed INT; admin_users.id is INT UNSIGNED.
CREATE TABLE IF NOT EXISTS booking_rule_overrides (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT NOT NULL,
  status_history_id BIGINT UNSIGNED NULL,
  rule_code VARCHAR(100) NOT NULL,
  scope ENUM('single_operation') NOT NULL DEFAULT 'single_operation',
  reason VARCHAR(500) NOT NULL,
  context_fingerprint CHAR(64) NOT NULL,
  metadata_json JSON NULL,
  approved_by_admin_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_booking_rule_overrides_booking (booking_id, created_at),
  KEY idx_booking_rule_overrides_rule (rule_code, created_at),
  CONSTRAINT fk_booking_rule_overrides_booking FOREIGN KEY (booking_id) REFERENCES aanvragen (id) ON DELETE RESTRICT,
  CONSTRAINT fk_booking_rule_overrides_status_history FOREIGN KEY (status_history_id) REFERENCES booking_status_history (id) ON DELETE RESTRICT,
  CONSTRAINT fk_booking_rule_overrides_admin FOREIGN KEY (approved_by_admin_id) REFERENCES admin_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

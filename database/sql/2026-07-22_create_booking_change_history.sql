CREATE TABLE IF NOT EXISTS booking_change_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT NOT NULL,
  change_type VARCHAR(80) NOT NULL,
  changed_fields_json JSON NOT NULL,
  changed_by_admin_id INT UNSIGNED NOT NULL,
  reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_booking_change_history_booking_created (booking_id, created_at),
  KEY idx_booking_change_history_change_type (change_type, created_at),
  CONSTRAINT fk_booking_change_history_booking FOREIGN KEY (booking_id) REFERENCES aanvragen(id) ON DELETE RESTRICT,
  CONSTRAINT fk_booking_change_history_admin FOREIGN KEY (changed_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

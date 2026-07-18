-- Run after the aanvragen and admin_users tables exist. MySQL/MariaDB DDL implicitly commits.
-- aanvragen.id is signed INT; admin_users.id is INT UNSIGNED.
CREATE TABLE IF NOT EXISTS booking_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT NOT NULL,
  previous_status ENUM('In optie', 'Definitief', 'Afgewezen') NOT NULL,
  new_status ENUM('In optie', 'Definitief', 'Afgewezen') NOT NULL,
  mail_mode ENUM('none', 'send') NOT NULL DEFAULT 'none',
  mail_sent TINYINT(1) NOT NULL DEFAULT 0,
  admin_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_booking_status_history_booking_created (booking_id, created_at),
  KEY idx_booking_status_history_admin (admin_user_id),
  CONSTRAINT fk_booking_status_history_booking FOREIGN KEY (booking_id) REFERENCES aanvragen (id) ON DELETE RESTRICT,
  CONSTRAINT fk_booking_status_history_admin FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

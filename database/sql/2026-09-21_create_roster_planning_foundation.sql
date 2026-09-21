-- GeoFort webroosteren Run 1.
-- Run only after aanvragen and admin_users exist. MySQL/MariaDB DDL implicitly commits.
-- aanvragen.id is signed INT; admin_users.id is INT UNSIGNED.

CREATE TABLE IF NOT EXISTS roster_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT NULL,
  visit_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'concept',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  source_booking_fingerprint CHAR(64) NULL,
  created_by_admin_id INT UNSIGNED NOT NULL,
  updated_by_admin_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roster_plans_booking (booking_id),
  KEY idx_roster_plans_visit_date (visit_date),
  KEY idx_roster_plans_status_visit_date (status, visit_date),
  CONSTRAINT fk_roster_plans_booking
    FOREIGN KEY (booking_id) REFERENCES aanvragen(id) ON DELETE RESTRICT,
  CONSTRAINT fk_roster_plans_created_by
    FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_roster_plans_updated_by
    FOREIGN KEY (updated_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roster_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  roster_plan_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL,
  position SMALLINT UNSIGNED NOT NULL,
  student_count INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roster_groups_plan_position (roster_plan_id, position),
  CONSTRAINT fk_roster_groups_plan
    FOREIGN KEY (roster_plan_id) REFERENCES roster_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
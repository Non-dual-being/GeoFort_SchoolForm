-- Persistent coordination row shared by future status, disabled-date and override mutations.
-- Run after 2026-07-11_create_admin_auth_tables.sql. MySQL/MariaDB DDL implicitly commits.
-- Overridewaarden moeten in de applicatielaag strikt groter dan nul worden
-- gevalideerd wanneer zij niet NULL zijn. Er staat bewust geen CHECK-constraint:
-- afdwinging daarvan verschilt tussen de ondersteunde MariaDB/MySQL-versies.
CREATE TABLE IF NOT EXISTS booking_day_settings (
  visit_date DATE NOT NULL,
  max_schools_override INT UNSIGNED NULL,
  max_students_override INT UNSIGNED NULL,
  override_reason VARCHAR(255) NULL,
  created_by_admin_id INT UNSIGNED NULL,
  updated_by_admin_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (visit_date),
  CONSTRAINT fk_booking_day_settings_created_admin FOREIGN KEY (created_by_admin_id) REFERENCES admin_users (id) ON DELETE SET NULL,
  CONSTRAINT fk_booking_day_settings_updated_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

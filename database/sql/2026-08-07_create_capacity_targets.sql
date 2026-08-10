-- Effectief gedateerde organisatietargets voor capaciteitsanalytics.
-- Een target wijzigt de technische capaciteit niet.
-- Selecteer vooraf de applicatiedatabase en controleer dit met SELECT DATABASE().
-- Dit bestand bevat bewust geen USE-statement of hardgecodeerde databasenaam.
-- Vereist een versie die CHECK-constraints afdwingt: MySQL >= 8.0.16 of MariaDB >= 10.2.1.
-- Gebruik scripts/apply-capacity-targets-migration.php om een bestaande tabel expliciet te valideren.
CREATE TABLE IF NOT EXISTS capacity_targets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  effective_date DATE NOT NULL,
  students_per_available_day SMALLINT NOT NULL,
  bookings_per_available_day DECIMAL(2,1) NOT NULL,
  created_by_admin_id INT UNSIGNED NOT NULL,
  updated_by_admin_id INT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uniq_capacity_targets_effective_date (effective_date),
  KEY idx_capacity_targets_effective_date (effective_date),
  KEY idx_capacity_targets_created_admin (created_by_admin_id),
  KEY idx_capacity_targets_updated_admin (updated_by_admin_id),
  CONSTRAINT chk_capacity_targets_students CHECK (students_per_available_day BETWEEN 0 AND 160),
  CONSTRAINT chk_capacity_targets_bookings CHECK (bookings_per_available_day BETWEEN 0.0 AND 2.0),
  CONSTRAINT fk_capacity_targets_created_admin FOREIGN KEY (created_by_admin_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_capacity_targets_updated_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admin_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

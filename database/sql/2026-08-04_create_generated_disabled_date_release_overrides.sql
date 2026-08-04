-- Persistente uitzondering voor expliciet vrijgegeven, opnieuw genereerbare schoolvakanties.
CREATE TABLE generated_disabled_date_release_overrides (
  datum DATE NOT NULL,
  type ENUM('school_vacation') NOT NULL,
  released_by_admin_id INT UNSIGNED NOT NULL,
  released_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (datum, type),
  KEY idx_generated_release_override_admin (released_by_admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

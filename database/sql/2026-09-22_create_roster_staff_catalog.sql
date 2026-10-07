-- GeoFort roosterplanner - centrale personeelsconfiguratie.
-- Salarissen/kostprijzen zijn bewust historisch modelleerbaar, maar nog niet ingevuld.

CREATE TABLE IF NOT EXISTS roster_staff_members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  display_name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roster_staff_members_name (display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roster_staff_module_preferences (
  staff_id BIGINT UNSIGNED NOT NULL,
  module_key VARCHAR(120) NOT NULL,
  preference_rank TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (staff_id, module_key),
  KEY idx_roster_staff_module_rank (module_key, preference_rank, staff_id),
  CONSTRAINT fk_roster_staff_module_preferences_staff
    FOREIGN KEY (staff_id) REFERENCES roster_staff_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roster_staff_cost_rates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_id BIGINT UNSIGNED NOT NULL,
  valid_from DATE NOT NULL,
  valid_to DATE NULL,
  hourly_cost_cents INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_roster_staff_cost_rates_staff_period (staff_id, valid_from, valid_to),
  CONSTRAINT fk_roster_staff_cost_rates_staff
    FOREIGN KEY (staff_id) REFERENCES roster_staff_members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personnel data is initialized once by RosterStaffBootstrapService after the Ronde C schema.
-- Existing installations: adopt the current catalog; never replay historical seed DML.

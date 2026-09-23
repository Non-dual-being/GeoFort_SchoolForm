-- GeoFort roosterplanner - selectie van personeel per rooster en toewijzing per sessie.
-- Vereist de personeelscatalogus uit 2026-09-22_create_roster_staff_catalog.sql.

CREATE TABLE IF NOT EXISTS roster_plan_staff (
  roster_plan_id BIGINT UNSIGNED NOT NULL,
  staff_id BIGINT UNSIGNED NOT NULL,
  availability_start TIME NULL,
  availability_end TIME NULL,
  PRIMARY KEY (roster_plan_id, staff_id),
  KEY idx_roster_plan_staff_staff (staff_id, roster_plan_id),
  CONSTRAINT fk_roster_plan_staff_plan
    FOREIGN KEY (roster_plan_id) REFERENCES roster_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_roster_plan_staff_staff
    FOREIGN KEY (staff_id) REFERENCES roster_staff_members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roster_session_staff (
  roster_session_id BIGINT UNSIGNED NOT NULL,
  staff_id BIGINT UNSIGNED NOT NULL,
  assignment_source VARCHAR(20) NOT NULL DEFAULT 'auto',
  PRIMARY KEY (roster_session_id, staff_id),
  KEY idx_roster_session_staff_staff (staff_id, roster_session_id),
  CONSTRAINT fk_roster_session_staff_session
    FOREIGN KEY (roster_session_id) REFERENCES roster_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_roster_session_staff_staff
    FOREIGN KEY (staff_id) REFERENCES roster_staff_members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
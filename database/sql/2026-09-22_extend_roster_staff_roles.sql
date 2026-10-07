-- GeoFort roosterplanner - personeelsrollen en roosterinstellingen.
-- Vereist:
--   2026-09-22_create_roster_staff_catalog.sql
--   2026-09-22_create_roster_staff_assignments.sql

ALTER TABLE roster_staff_members
  ADD COLUMN employment_type VARCHAR(20) NOT NULL DEFAULT 'paid' AFTER is_active,
  ADD COLUMN can_guide TINYINT(1) NOT NULL DEFAULT 1 AFTER employment_type,
  ADD COLUMN can_cook TINYINT(1) NOT NULL DEFAULT 0 AFTER can_guide;

CREATE TABLE roster_plan_staffing_settings (
  roster_plan_id BIGINT UNSIGNED NOT NULL,
  staffing_mode VARCHAR(20) NOT NULL DEFAULT 'with_staff',
  prefer_geofort_ke TINYINT(1) NOT NULL DEFAULT 1,
  cook_staff_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (roster_plan_id),
  KEY idx_roster_plan_staffing_cook (cook_staff_id),
  CONSTRAINT fk_roster_plan_staffing_plan
    FOREIGN KEY (roster_plan_id) REFERENCES roster_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_roster_plan_staffing_cook
    FOREIGN KEY (cook_staff_id) REFERENCES roster_staff_members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personnel data is initialized once by RosterStaffBootstrapService after the Ronde C schema.
-- Existing installations: adopt the current catalog; never replay historical seed DML.

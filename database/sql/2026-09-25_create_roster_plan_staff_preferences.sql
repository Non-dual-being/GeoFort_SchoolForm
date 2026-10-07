-- GeoFort roosterplanner - dagvoorkeuren van de planner per rooster.
-- Vereist:
--   2026-09-22_create_roster_staff_catalog.sql
--   2026-09-22_create_roster_staff_assignments.sql

CREATE TABLE IF NOT EXISTS roster_plan_staff_module_preferences (
  roster_plan_id BIGINT UNSIGNED NOT NULL,
  staff_id BIGINT UNSIGNED NOT NULL,
  module_key VARCHAR(120) NOT NULL,
  preference_rank TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (roster_plan_id, staff_id, module_key),
  UNIQUE KEY uniq_roster_plan_staff_preference_rank (
    roster_plan_id,
    staff_id,
    preference_rank
  ),
  KEY idx_roster_plan_staff_preference_staff (staff_id, roster_plan_id),
  CONSTRAINT fk_roster_plan_staff_preference_plan
    FOREIGN KEY (roster_plan_id) REFERENCES roster_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_roster_plan_staff_preference_staff
    FOREIGN KEY (staff_id) REFERENCES roster_staff_members(id) ON DELETE RESTRICT,
  CONSTRAINT chk_roster_plan_staff_preference_rank
    CHECK (preference_rank BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

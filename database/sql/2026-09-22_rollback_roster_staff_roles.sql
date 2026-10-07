DROP TABLE IF EXISTS roster_plan_staffing_settings;

ALTER TABLE roster_staff_members
  DROP COLUMN can_cook,
  DROP COLUMN can_guide,
  DROP COLUMN employment_type;

-- Schema rollback only. Personnel is operational data and must never be deleted by name.
-- This historical rollback is not applicable after Ronde C; no Ronde C rollback is provided.
